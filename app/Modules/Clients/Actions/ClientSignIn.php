<?php

declare(strict_types=1);

namespace App\Modules\Clients\Actions;

use App\Core\Auth\ResetCodes;
use App\Core\Contracts\OtpSender;
use App\Core\Exceptions\ApiException;
use App\Core\Support\Flag;
use App\Core\Support\Phone;
use App\Core\Tenancy\VendorScope;
use App\Modules\Clients\Enums\ClientSource;
use App\Modules\Clients\Models\Client;
use App\Modules\Clients\Support\ClientPassword;
use App\Modules\Clients\Support\MobileSignInHooks;
use App\Modules\Identity\Mail\PasswordResetCodeMail;
use App\Modules\Vendors\Enums\VendorStatus;
use App\Modules\Vendors\Models\Vendor;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;

/**
 * Every way a client gets in (v1 websiteClient.service, vendorClientAuth and
 * the public vendor sign-up): email + password, a code sent to the phone, the
 * 2FA second step, the one-minute handoff from a vendor's public site, and the
 * vendor-client password reset. Messages are v1's.
 *
 * Sign-ins run before any tenant is known, so lookups name the vendor
 * explicitly instead of relying on the vendor scope.
 */
final class ClientSignIn
{
    private const CHALLENGE_MINUTES = 10;

    private const HANDOFF_SECONDS = 60;

    private const MOBILE_LINK_MINUTES = 15;

    public function __construct(
        private readonly OtpSender $otp,
        private readonly ResetCodes $codes,
        private readonly MobileSignInHooks $hooks,
        private readonly ClientSessions $sessions,
    ) {}

    /** The vendor a public request signs in to: vendor_id, else the house vendor. */
    public function vendorId(mixed $requested): int
    {
        $id = Flag::id($requested) ?? Vendor::houseId();
        $exists = Vendor::query()->withoutGlobalScopes()->whereKey($id)->whereNull('deleted_at')->exists();

        return $exists ? $id : throw ApiException::notFound('Vendor not found');
    }

    /** @param array<string, mixed> $input */
    public function register(array $input, int $vendorId): Client
    {
        $name = trim($this->text($input, 'name'));
        if ($name === '') {
            throw ApiException::badRequest('Full name is required.');
        }
        $email = mb_strtolower(trim($this->text($input, 'email')));
        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw ApiException::badRequest('A valid email address is required.');
        }
        $password = ClientPassword::website($input['password'] ?? null);
        $mobile = Phone::digits($input['mobile'] ?? null);

        $this->assertEmailFree($vendorId, $email);
        $this->assertMobileFree($vendorId, $mobile);

        return Client::query()->create([
            'vendor_id' => $vendorId,
            'source' => ClientSource::Website,
            'name' => $name,
            'email' => $email,
            'mobile' => $mobile === '' ? null : $mobile,
            'dial_code' => trim($this->text($input, 'dial_code')) ?: '+91',
            'password' => $password,
        ])->refresh();
    }

    /**
     * POST /public/vendors/{slug}/register-client — sign-up on a vendor's site
     * (v1: a guest vendor_client with a login).
     *
     * @param  array<string, mixed>  $input
     */
    public function registerAtVendorSite(Vendor $vendor, array $input): Client
    {
        $name = trim($this->text($input, 'name'));
        $email = mb_strtolower(trim($this->text($input, 'email')));
        $mobile = Phone::digits($input['mobile'] ?? null);
        if ($name === '' || $email === '' || $mobile === '' || $this->text($input, 'password') === '') {
            throw ApiException::badRequest('Name, email, mobile, and password are required');
        }
        $password = ClientPassword::vendor($input['password']);
        if ($this->clients($vendor->id)->where('email', $email)->exists()) {
            throw ApiException::conflict('This email is already registered.');
        }
        if (Phone::whereMatches($this->clients($vendor->id), 'mobile', $mobile)->exists()) {
            throw ApiException::conflict('This mobile number is already registered.');
        }

        $address = [];
        foreach (['address', 'country', 'state', 'district', 'city', 'locality', 'pincode'] as $field) {
            $address[$field] = trim($this->text($input, $field)) ?: null;
        }

        return Client::query()->create([
            'vendor_id' => $vendor->id,
            'source' => ClientSource::Vendor,
            'code' => 'CLI-'.mb_strtoupper(Str::random(10)),
            'name' => $name,
            'email' => $email,
            'mobile' => $mobile,
            'password' => $password,
            ...$address,
            'registration_type' => 'guest',
            'newsletter_opt_in' => in_array($input['subscribe_newsletter'] ?? null, [true, 1, '1', 'true'], true),
            'login_access' => true,
        ]);
    }

    /** @param array<string, mixed> $input */
    public function passwordLogin(array $input, int $vendorId): Client
    {
        $email = mb_strtolower(trim($this->text($input, 'email')));
        $password = $this->text($input, 'password');
        if ($email === '' || $password === '') {
            throw ApiException::badRequest('Email and password are required.');
        }

        $client = $this->clients($vendorId)->where('email', $email)->first();
        if ($client === null || ! $client->hasPassword() || ! Hash::check($password, (string) $client->password)) {
            throw ApiException::unauthorized('Invalid email or password.');
        }

        // The app may also send the mobile number: it must be this account's.
        $supplied = Phone::digits($input['mobile'] ?? null);
        if ($supplied !== '') {
            $stored = substr(Phone::digits($client->mobile), -10);
            if ($stored === '') {
                throw ApiException::unauthorized('This account has no mobile number on file.');
            }
            if ($stored !== substr($supplied, -10)) {
                throw ApiException::unauthorized('That mobile number does not match this account.');
            }
        }

        $this->assertCanSignIn($client);
        $this->signedIn($client, $password);

        return $client;
    }

    /**
     * May this account sign in? Checked at every sign-in and on every request
     * (EnsureClientUsable). Website wording by default, vendor-client portal
     * wording with $vendorPortal.
     */
    public function assertCanSignIn(Client $client, bool $vendorPortal = false, bool $atLogin = true): void
    {
        $client->loadMissing('vendor');
        if (! $client->isActive()) {
            throw ApiException::forbidden($vendorPortal ? 'Your account is inactive. Please contact the vendor.' : 'Your account is not active. Please contact us.');
        }
        if (! $client->login_access) {
            throw ApiException::forbidden($atLogin ? 'Login access is not enabled. Please contact the vendor.' : 'Your login access has been revoked.');
        }
        if ($client->vendor->status !== VendorStatus::Active) {
            throw ApiException::forbidden('Vendor account is suspended. Please contact support.');
        }
    }

    // ---- sign-in with a code sent to the phone ---------------------------

    /**
     * @param  array<string, mixed>  $input  mobile, login_as ("organizer")
     * @return array<string, mixed>
     */
    public function requestLoginOtp(array $input, int $vendorId): array
    {
        $asOrganizer = ($input['login_as'] ?? null) === 'organizer';
        $client = $this->findByMobile($input['mobile'] ?? null, $vendorId, $asOrganizer);

        if ($asOrganizer && $client->plan_id === null && ! $this->hooks->organizes($client)) {
            throw ApiException::forbidden('This mobile number is not registered as an event organizer.');
        }

        $code = $this->storeCode($client);

        return $this->delivery((string) $client->dial_code, Phone::digits($input['mobile'] ?? null), $code, 'login');
    }

    /** @param array<string, mixed> $input mobile, otp */
    public function verifyLoginOtp(array $input, int $vendorId): Client
    {
        $client = $this->findByMobile($input['mobile'] ?? null, $vendorId, false);
        $this->checkCode($client, $input['otp'] ?? null);

        $client->forceFill(['last_login_at' => now()])->save();
        $this->hooks->afterSignIn($client);

        return $client->refresh();
    }

    // ---- 2FA second step ---------------------------------------------------

    public function issueChallenge(Client $client): string
    {
        $token = Str::random(64);
        Cache::put('client-2fa:'.hash('sha256', $token), $client->id, now()->addMinutes(self::CHALLENGE_MINUTES));

        return $token;
    }

    public function challengeClient(mixed $token): Client
    {
        $id = is_string($token) && $token !== '' ? Cache::get('client-2fa:'.hash('sha256', $token)) : null;
        if (! is_numeric($id)) {
            throw ApiException::unauthorized('That sign-in attempt has expired. Please sign in again.');
        }
        $client = $this->unscoped()->find((int) $id) ?? throw ApiException::unauthorized('Your account no longer exists.');
        if (! $client->isActive()) {
            throw ApiException::forbidden('Your account is not active. Please contact us.');
        }

        return $client;
    }

    public function forgetChallenge(mixed $token): void
    {
        if (is_string($token)) {
            Cache::forget('client-2fa:'.hash('sha256', $token));
        }
    }

    // ---- the vendor's public site → vendor-client portal handoff ----------

    /** POST /public/vendors/{slug}/login-client: credentials in, one-minute handoff token out. */
    public function vendorSiteLogin(Vendor $vendor, mixed $email, mixed $password): string
    {
        $email = mb_strtolower(trim(is_string($email) ? $email : ''));
        $password = is_string($password) ? $password : '';
        if ($email === '' || $password === '') {
            throw ApiException::badRequest('Email and password are required');
        }

        $client = $this->clients($vendor->id)->where('email', $email)->first();
        if ($client === null || ! $client->hasPassword() || ! Hash::check($password, (string) $client->password)) {
            throw ApiException::unauthorized('Invalid email or password');
        }
        $this->assertCanSignIn($client, vendorPortal: true);
        $this->signedIn($client, $password);

        $token = Str::random(64);
        Cache::put('client-handoff:'.hash('sha256', $token), $client->id, self::HANDOFF_SECONDS);

        return $token;
    }

    public function redeemHandoff(mixed $token): Client
    {
        $id = is_string($token) && $token !== '' ? Cache::pull('client-handoff:'.hash('sha256', $token)) : null;
        $client = is_numeric($id) ? $this->unscoped()->find((int) $id) : null;
        if ($client === null) {
            throw ApiException::unauthorized('Invalid or expired client login handoff.');
        }
        $this->assertCanSignIn($client, vendorPortal: true);

        return $client;
    }

    // ---- attach a mobile number after social sign-up ----------------------

    public function issueMobileLinkToken(Client $client): string
    {
        $token = Str::random(64);
        Cache::put('client-mobile-link:'.hash('sha256', $token), $client->id, now()->addMinutes(self::MOBILE_LINK_MINUTES));

        return $token;
    }

    /** @return array<string, mixed> */
    public function sendMobileLinkCode(mixed $token, mixed $dialCode, mixed $mobile): array
    {
        $client = $this->mobileLinkClient($token);
        $digits = Phone::digits($mobile);
        if (! Phone::isPlausible($digits)) {
            throw ApiException::badRequest('Please enter a valid mobile number.');
        }
        if (Phone::whereMatches($this->clients($client->vendor_id), 'mobile', $digits)->whereKeyNot($client->id)->exists()) {
            throw ApiException::badRequest('That mobile number is already used by another account.');
        }

        $dial = trim(is_string($dialCode) ? $dialCode : '') ?: ($client->dial_code ?? '+91');
        $client->forceFill(['dial_code' => $dial])->save();

        return $this->delivery($dial, $digits, $this->storeCode($client), 'verify_mobile');
    }

    public function verifyMobileLinkCode(mixed $token, mixed $dialCode, mixed $mobile, mixed $otp): Client
    {
        $client = $this->mobileLinkClient($token);
        $this->checkCode($client, $otp);

        $client->forceFill([
            'dial_code' => trim(is_string($dialCode) ? $dialCode : '') ?: ($client->dial_code ?? '+91'),
            'mobile' => Phone::digits($mobile),
            'mobile_verified' => ! $this->acceptsAnyCode(),
        ])->save();

        return $client->refresh();
    }

    // ---- passwords ----------------------------------------------------------

    /**
     * Vendor-client portal "forgot password". The code is e-mailed. v1 returned
     * it in the response, so anyone who knew an email could reset that
     * client's password; it is never returned now.
     */
    public function sendResetCode(string $email): void
    {
        $client = $this->unscoped()->where('email', mb_strtolower(trim($email)))->first();
        if ($client === null) {
            return;
        }
        $this->assertCanSignIn($client, vendorPortal: true);

        $code = $this->codes->issue('client', (string) $client->email);
        try {
            Mail::to((string) $client->email)->send(new PasswordResetCodeMail($client->name, $code, ResetCodes::MINUTES));
        } catch (Throwable $e) {
            report($e);
        }
    }

    public function resetPassword(string $email, string $code, mixed $password): void
    {
        $password = ClientPassword::vendor($password);
        $client = $this->unscoped()->where('email', mb_strtolower(trim($email)))->first()
            ?? throw ApiException::badRequest('Invalid or expired reset code.');
        $this->codes->check('client', (string) $client->email, $code);
        $this->codes->forget('client', (string) $client->email);

        $client->forceFill(['password' => $password, 'password_changed_at' => now()])->save();
        $this->sessions->endAll($client, 'password_reset');
    }

    /**
     * @param  'website'|'vendor'  $rule  which v1 password rule the calling screen uses
     */
    public function changePassword(Client $client, mixed $current, mixed $new, string $rule): void
    {
        if (! is_string($current) || $current === '' || ! is_string($new) || $new === '') {
            throw ApiException::badRequest($rule === 'website' ? 'Both your current and new password are required.' : 'Current and new password are required');
        }
        if (! $client->hasPassword()) {
            throw ApiException::badRequest('This account signs in with Google or Facebook and has no password to change.');
        }
        if (! Hash::check($current, (string) $client->password)) {
            throw $rule === 'website'
                ? ApiException::unauthorized('Your current password is not correct.')
                : ApiException::badRequest('Current password is incorrect');
        }
        if ($rule === 'website') {
            if (mb_strlen($new) < 8) {
                throw ApiException::badRequest('Your new password must be at least 8 characters.');
            }
            if (Hash::check($new, (string) $client->password)) {
                throw ApiException::badRequest('Your new password must be different from the current one.');
            }
        } else {
            ClientPassword::vendor($new);
        }

        $client->forceFill(['password' => $new, 'password_changed_at' => now()])->save();
    }

    // ---- helpers ------------------------------------------------------------

    /** @return Builder<Client> clients of one vendor, without the tenant scope (no tenant yet) */
    public function clients(int $vendorId): Builder
    {
        return $this->unscoped()->where('vendor_id', $vendorId);
    }

    /** @return Builder<Client> */
    private function unscoped(): Builder
    {
        return Client::query()->withoutGlobalScope(VendorScope::class)->with('vendor');
    }

    public function assertEmailFree(int $vendorId, ?string $email, ?int $except = null): void
    {
        if ($email !== null && $email !== '' && $this->clients($vendorId)->where('email', $email)->when($except !== null, static fn ($q) => $q->whereKeyNot($except))->exists()) {
            throw ApiException::badRequest('An account with this email already exists.');
        }
    }

    public function assertMobileFree(int $vendorId, ?string $mobile, ?int $except = null): void
    {
        $forms = Phone::candidates($mobile);
        if ($forms !== [] && Phone::whereMatches($this->clients($vendorId), 'mobile', $mobile)->when($except !== null, static fn ($q) => $q->whereKeyNot($except))->exists()) {
            throw ApiException::badRequest('An account with this mobile number already exists.');
        }
    }

    /** last_login_at, and v1 bcryptjs hashes upgraded to PHP's on the way in. */
    private function signedIn(Client $client, string $password): void
    {
        $changes = ['last_login_at' => now()];
        if (Hash::needsRehash((string) $client->password)) {
            $changes['password'] = $password;
        }
        $client->forceFill($changes)->save();
    }

    private function findByMobile(mixed $mobile, int $vendorId, bool $asOrganizer): Client
    {
        $digits = Phone::digits($mobile);
        if (! Phone::isPlausible($digits)) {
            throw ApiException::badRequest('Please enter a valid mobile number.');
        }

        // Clients a vendor added may share a number: the account that can sign in,
        // a self-made one before a vendor-added one, then the oldest.
        $client = Phone::whereMatches($this->clients($vendorId), 'mobile', $digits)
            ->orderByDesc('login_access')->orderByDesc('is_active')->orderByRaw("source = 'vendor'")->orderBy('id')->first()
            ?? $this->hooks->resolveAccount(Phone::candidates($digits), $vendorId, $asOrganizer)
            ?? throw ApiException::unauthorized('No account is registered with that mobile number.');
        $client->loadMissing('vendor');
        $this->assertCanSignIn($client);

        return $client;
    }

    /**
     * Send this client a one-time code (used by other sign-in doors, such as
     * joining an event from its QR).
     *
     * @return array<string, mixed> the delivery answer
     */
    public function sendCode(Client $client, string $mobile, string $purpose): array
    {
        return $this->delivery((string) $client->dial_code, Phone::digits($mobile), $this->storeCode($client), $purpose);
    }

    /** Check and spend the client's one-time code (v1 messages). */
    public function spendCode(Client $client, mixed $otp): void
    {
        $this->checkCode($client, $otp);
    }

    private function storeCode(Client $client): string
    {
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $client->forceFill([
            'otp_hash' => Hash::make($code),
            'otp_expires_at' => now()->addMinutes((int) config('clients.otp_minutes')),
            'otp_attempts' => 0,
        ])->save();

        return $code;
    }

    private function checkCode(Client $client, mixed $otp): void
    {
        $code = Phone::digits($otp);
        if ($client->otp_hash === null || $client->otp_expires_at === null) {
            throw ApiException::badRequest('Please request a code first.');
        }
        if ($client->otp_expires_at->isPast()) {
            throw ApiException::badRequest('That code has expired. Please request a new one.');
        }
        if ($client->otp_attempts >= (int) config('clients.otp_max_attempts')) {
            throw ApiException::badRequest('Too many incorrect attempts. Please request a new code.');
        }
        if (! $this->acceptsAnyCode() && (strlen($code) !== 6 || ! Hash::check($code, $client->otp_hash))) {
            $client->forceFill(['otp_attempts' => $client->otp_attempts + 1])->save();
            throw ApiException::badRequest('That code is not correct.');
        }

        $client->forceFill(['otp_hash' => null, 'otp_expires_at' => null, 'otp_attempts' => 0])->save();
    }

    /**
     * Send the code. Outside production the code comes back in the response
     * when it could not be delivered for a reason we chose (no provider,
     * switched off) or OTP_DEV_ECHO asks for it — otherwise turning delivery
     * off locally would lock every account out.
     *
     * @return array<string, mixed>
     */
    private function delivery(string $dialCode, string $mobile, string $code, string $purpose): array
    {
        $result = $this->otp->send($dialCode, $mobile, $code, $purpose);
        $production = app()->isProduction();
        $echo = ! $production && (config('clients.otp_dev_echo') || in_array($result['reason'], ['disabled', 'not_configured'], true));

        return [
            'expires_in' => (int) config('clients.otp_minutes') * 60,
            'delivered' => $result['delivered'],
            ...($result['delivered'] || $production ? [] : ['delivery_error' => $result['reason']]),
            ...($echo ? ['dev_code' => $code] : []),
        ];
    }

    private function acceptsAnyCode(): bool
    {
        return ! app()->isProduction() && (bool) config('clients.otp_accept_any');
    }

    private function mobileLinkClient(mixed $token): Client
    {
        $id = is_string($token) && $token !== '' ? Cache::get('client-mobile-link:'.hash('sha256', $token)) : null;
        if (! is_numeric($id)) {
            throw ApiException::unauthorized('This verification link has expired. Please sign in again.');
        }
        $client = $this->unscoped()->find((int) $id) ?? throw ApiException::notFound('Account not found.');
        if (! $client->isActive()) {
            throw ApiException::forbidden('Your account is not active.');
        }

        return $client;
    }

    /** @param array<string, mixed> $input */
    private function text(array $input, string $key): string
    {
        $value = $input[$key] ?? null;

        return is_scalar($value) ? (string) $value : '';
    }
}
