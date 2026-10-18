<?php

declare(strict_types=1);

namespace App\Modules\Clients\Actions;

use App\Core\Contracts\ObjectStorage;
use App\Core\Exceptions\ApiException;
use App\Core\Support\Flag;
use App\Core\Support\Phone;
use App\Core\Tenancy\CurrentCompany;
use App\Core\Tenancy\CurrentVendor;
use App\Core\Tenancy\VendorScope;
use App\Modules\Catalog\Models\Plan;
use App\Modules\Catalog\Models\PlanType;
use App\Modules\Clients\Enums\ClientSource;
use App\Modules\Clients\Models\Client;
use App\Modules\Clients\Models\ClientFavourite;
use App\Modules\Clients\Support\ClientPassword;
use App\Modules\Vendors\Models\Vendor;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Creating and editing clients, from each place v1 allowed it, each with its
 * own field whitelist (CLAUDE.md: CLIENT_CREATABLE_FIELDS / CLIENT_EDITABLE_FIELDS):
 *
 *  - admin  /website-clients          (v1 website_clients WRITABLE_FIELDS)
 *  - vendor /vendors/clients, staff /vendors/staff/portal/clients
 *                                     (v1 vendor_clients, the vendor-side checks)
 *  - the client themself              (client portal /client/me, vendor-client portal profile)
 *
 * v1 field names are mapped here: subscription_plan_id → plan_id,
 * subscription_id / plan → plan_type_id, profile_pic → avatar_url,
 * client_type → newsletter_opt_in, client_id → code.
 */
final class ClientWriter
{
    /** v1 vendor_clients CLIENT_CREATABLE_FIELDS = CLIENT_EDITABLE_FIELDS. */
    private const VENDOR_FIELDS = [
        'name', 'email', 'mobile', 'profile_pic', 'address', 'country', 'state', 'district', 'city', 'locality', 'pincode',
        'subscription_id', 'plan', 'registration_type', 'client_type', 'login_access', 'send_credentials_to_email', 'password',
    ];

    private const VENDOR_REQUIRED = [
        'name' => 'Full Name', 'mobile' => 'Mobile Number', 'email' => 'Email Address', 'address' => 'Street Address',
        'country' => 'Country', 'state' => 'State', 'district' => 'District', 'city' => 'City', 'locality' => 'Locality',
        'pincode' => 'Pincode',
    ];

    private const SELF_LIMITS = ['name' => 200, 'company_name' => 150, 'bio' => 2000, 'dial_code' => 8, 'avatar_url' => 500];

    private const VENDOR_PORTAL_SELF = ['name', 'mobile', 'profile_pic', 'address', 'country', 'state', 'district', 'city', 'locality', 'pincode'];

    public function __construct(
        private readonly ObjectStorage $storage,
        private readonly ClientSignIn $signIn,
        private readonly ClientSessions $sessions,
        private readonly CurrentCompany $company,
        private readonly CurrentVendor $vendor,
    ) {}

    // ---- admin: /website-clients ------------------------------------------

    /**
     * Clients of every vendor of the admin's company.
     *
     * @return Builder<Client>
     */
    public function adminQuery(): Builder
    {
        $companyId = $this->company->id();

        return Client::query()->withoutGlobalScope(VendorScope::class)
            ->whereHas('vendor', static fn ($q) => $q->where('company_id', $companyId));
    }

    public function adminFind(int $id): Client
    {
        return $this->adminQuery()->with('vendor')->find($id) ?? throw ApiException::notFound('Client not found');
    }

    /** @param array<string, mixed> $input */
    public function adminCreate(array $input, int $actorId): Client
    {
        $name = trim($this->text($input['name'] ?? null));
        if ($name === '') {
            throw ApiException::badRequest('Full name is required.');
        }
        $email = mb_strtolower(trim($this->text($input['email'] ?? null)));
        if ($email === '') {
            throw ApiException::badRequest('Email is required.');
        }
        $vendorId = $this->companyVendor($input['vendor_id'] ?? null);
        $mobile = Phone::digits($input['mobile'] ?? null) ?: null;
        $this->signIn->assertEmailFree($vendorId, $email);
        $this->signIn->assertMobileFree($vendorId, $mobile);
        if (($input['password'] ?? null) === null || $input['password'] === '') {
            throw ApiException::badRequest('A password is required so the client can sign in to the portal.');
        }

        $client = Client::query()->create([
            'vendor_id' => $vendorId,
            'source' => ClientSource::Admin,
            'name' => $name,
            'email' => $email,
            'mobile' => $mobile,
            'dial_code' => trim($this->text($input['dial_code'] ?? null)) ?: '+91',
            'password' => $this->adminPassword($input['password']),
            'plan_id' => $this->platformPlan($input['subscription_plan_id'] ?? null),
            'is_active' => array_key_exists('is_active', $input) ? Flag::clamp($input['is_active'], 0, 2, 1) : 1,
            'created_by' => $actorId,
        ]);

        return $client->load('vendor');
    }

    /** @param array<string, mixed> $input */
    public function adminUpdate(Client $client, array $input): Client
    {
        $values = [];
        if (array_key_exists('name', $input)) {
            $values['name'] = trim($this->text($input['name'])) ?: throw ApiException::badRequest('Full name is required.');
        }
        $vendorId = array_key_exists('vendor_id', $input) ? $this->companyVendor($input['vendor_id']) : $client->vendor_id;
        $values['vendor_id'] = $vendorId;
        if (array_key_exists('email', $input)) {
            $values['email'] = mb_strtolower(trim($this->text($input['email']))) ?: throw ApiException::badRequest('Email is required.');
            $this->signIn->assertEmailFree($vendorId, $values['email'], $client->id);
        }
        if (array_key_exists('mobile', $input)) {
            $values['mobile'] = Phone::digits($input['mobile']) ?: null;
            $this->signIn->assertMobileFree($vendorId, $values['mobile'], $client->id);
        }
        if (array_key_exists('dial_code', $input)) {
            $values['dial_code'] = trim($this->text($input['dial_code'])) ?: '+91';
        }
        if (array_key_exists('subscription_plan_id', $input)) {
            $values['plan_id'] = $this->platformPlan($input['subscription_plan_id']);
        }
        if (array_key_exists('is_active', $input)) {
            $values['is_active'] = Flag::clamp($input['is_active'], 0, 2, $client->is_active);
        }
        if (($input['password'] ?? null) !== null && $input['password'] !== '') {
            $values['password'] = $this->adminPassword($input['password']);
            $values['password_changed_at'] = now();
        }

        return $this->save($client, $values)->load('vendor');
    }

    // ---- vendor and staff portals: /vendors/clients ----------------------

    /** @param array<string, mixed> $input */
    public function vendorCreate(array $input): Client
    {
        $input = $this->normaliseVendorInput($input, update: false);
        $values = $this->vendorValues($input);
        // Clients a vendor adds may share a mobile number (v1 vendor clients did).
        $this->assertVendorEmailFree($values['email'] ?? null);

        return Client::query()->create([
            ...$values,
            'source' => ClientSource::Vendor,
            'code' => $this->newCode(),
            'login_access' => $values['login_access'] ?? false,
            'is_active' => 1,
        ])->load('planType');
    }

    /** @param array<string, mixed> $input */
    public function vendorUpdate(Client $client, array $input): Client
    {
        $input = $this->normaliseVendorInput($input, update: true);
        $values = $this->vendorValues($input);
        if (array_key_exists('email', $values)) {
            $this->assertVendorEmailFree($values['email'], $client->id);
        }

        return $this->save($client, $values)->load('planType');
    }

    public function find(int $id): Client
    {
        return Client::query()->with('planType')->find($id) ?? throw ApiException::notFound('Client not found');
    }

    public function setActive(Client $client, mixed $value): Client
    {
        return $this->save($client, ['is_active' => Flag::clamp($value, 0, 2, $client->is_active)]);
    }

    public function delete(Client $client): void
    {
        $this->sessions->endAll($client, 'deleted');
        $client->delete();
    }

    // ---- the client themself -----------------------------------------------

    /**
     * PUT /client/me (v1 SELF_EDITABLE_FIELDS + email).
     *
     * @param  array<string, mixed>  $input
     */
    public function updateOwnProfile(Client $client, array $input): Client
    {
        $values = [];
        foreach (self::SELF_LIMITS as $field => $max) {
            if (array_key_exists($field, $input)) {
                $value = $input[$field] === null ? null : trim($this->text($input[$field]));
                $values[$field] = $value === null || $value === '' ? null : mb_substr($value, 0, $max);
            }
        }
        if (array_key_exists('mobile', $input)) {
            $values['mobile'] = substr(Phone::digits($input['mobile']), 0, 15) ?: null;
            if ($values['mobile'] !== null && Phone::whereMatches($this->signIn->clients($client->vendor_id), 'mobile', $values['mobile'])->whereKeyNot($client->id)->exists()) {
                throw ApiException::badRequest('That mobile number is already in use.');
            }
        }
        if (array_key_exists('name', $values) && $values['name'] === null) {
            throw ApiException::badRequest('Your name cannot be empty.');
        }
        if (array_key_exists('email', $input)) {
            $email = mb_strtolower(trim($this->text($input['email'])));
            if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw ApiException::badRequest('Please enter a valid email address.');
            }
            if ($email !== $client->email) {
                if ($this->signIn->clients($client->vendor_id)->where('email', $email)->whereKeyNot($client->id)->exists()) {
                    throw ApiException::badRequest('That email address is already in use.');
                }
                $values['email'] = $email;
                $values['email_verified'] = false;
            }
        }

        return $this->save($client, $values);
    }

    /**
     * PUT /vendors/client/auth/profile.
     *
     * @param  array<string, mixed>  $input
     */
    public function updateVendorPortalProfile(Client $client, array $input): Client
    {
        $values = [];
        foreach (self::VENDOR_PORTAL_SELF as $field) {
            if (array_key_exists($field, $input)) {
                $values[$field === 'profile_pic' ? 'avatar_url' : $field] = $input[$field];
            }
        }
        if (isset($values['avatar_url']) && is_string($values['avatar_url'])) {
            $values['avatar_url'] = $this->storage->storeDataUri($values['avatar_url'], 'clients', 'profile_pic');
        }

        return $this->save($client, $values);
    }

    public function updateAvatar(Client $client, ?UploadedFile $file): Client
    {
        if ($file === null) {
            throw ApiException::badRequest('Please choose an image to upload.');
        }
        if (! in_array($file->getMimeType(), ['image/jpeg', 'image/png', 'image/webp', 'image/gif'], true)) {
            throw ApiException::badRequest('Please choose a JPG, PNG, WEBP or GIF image.');
        }
        if ($file->getSize() > 4 * 1024 * 1024) {
            throw ApiException::badRequest('That image is larger than 4MB.');
        }
        $stored = $this->storage->upload($file, 'client-avatars');

        return $this->save($client, ['avatar_url' => $stored['url']]);
    }

    public function removeAvatar(Client $client): Client
    {
        return $this->save($client, ['avatar_url' => null]);
    }

    /** Closing one's own account: the password, or the e-mail typed back when there is none. */
    public function closeAccount(Client $client, mixed $password, mixed $confirmEmail): void
    {
        if ($client->hasPassword()) {
            if (! is_string($password) || $password === '') {
                throw ApiException::badRequest('Please enter your password to confirm.');
            }
            if (! Hash::check($password, (string) $client->password)) {
                throw ApiException::unauthorized('That password is not correct.');
            }
        } else {
            if ($client->email === null) {
                throw ApiException::badRequest('This account has no password or email address to confirm with. Please set one in your profile first, or contact us.');
            }
            $typed = mb_strtolower(trim(is_string($confirmEmail) ? $confirmEmail : ''));
            if ($typed === '') {
                throw ApiException::badRequest('Please type your email address to confirm.');
            }
            if ($typed !== $client->email) {
                throw ApiException::badRequest('That does not match the email address on this account.');
            }
        }

        DB::transaction(function () use ($client): void {
            $client->forceFill(['is_active' => 0])->save();
            $this->delete($client);
        });
    }

    /**
     * Replace the favourite template codes (bounded to 100, slugs only).
     *
     * @return list<string>
     */
    public function setFavouriteTemplates(Client $client, mixed $codes): array
    {
        $clean = collect(is_array($codes) ? $codes : [])
            ->filter(static fn ($c): bool => is_string($c) && preg_match('/^[a-z0-9][a-z0-9-]{0,63}$/i', $c) === 1)
            ->unique()->take(100)->values()->all();

        DB::transaction(static function () use ($client, $clean): void {
            ClientFavourite::query()->where('client_id', $client->id)->where('kind', 'template')->delete();
            ClientFavourite::query()->insert(array_map(static fn (string $code, int $i): array => [
                'client_id' => $client->id, 'kind' => 'template', 'ref' => $code, 'sort_order' => $i,
                'created_at' => now(), 'updated_at' => now(),
            ], $clean, array_keys($clean)));
        });

        return $clean;
    }

    // ---- helpers ------------------------------------------------------------

    /** @param array<string, mixed> $values */
    private function save(Client $client, array $values): Client
    {
        $deactivated = (array_key_exists('is_active', $values) && (int) $values['is_active'] !== 1 && $client->isActive())
            || (array_key_exists('login_access', $values) && ! $values['login_access'] && $client->login_access);
        $client->fill($values)->save();
        if ($deactivated) {
            // v1 kept signed-in sessions alive until their tokens expired.
            $this->sessions->endAll($client, 'deactivated');
        }

        return $client;
    }

    /**
     * v1 normalizeClientInput + validateClientInput.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function normaliseVendorInput(array $input, bool $update): array
    {
        foreach (['name', 'mobile', 'email', 'address', 'country', 'state', 'district', 'city', 'locality', 'pincode', 'plan', 'registration_type'] as $field) {
            if (isset($input[$field]) && is_string($input[$field])) {
                $input[$field] = trim($input[$field]);
            }
        }
        if (isset($input['email']) && is_string($input['email'])) {
            $input['email'] = mb_strtolower($input['email']);
        }
        if (isset($input['registration_type']) && is_string($input['registration_type'])) {
            $input['registration_type'] = mb_strtolower($input['registration_type']);
        }

        foreach (self::VENDOR_REQUIRED as $field => $label) {
            if ((! $update || array_key_exists($field, $input)) && trim($this->text($input[$field] ?? null)) === '') {
                throw ApiException::badRequest("{$label} is required");
            }
        }
        foreach (['name' => 'Full Name', 'address' => 'Street Address', 'locality' => 'Locality', 'pincode' => 'Pincode'] as $field => $label) {
            $value = trim($this->text($input[$field] ?? null));
            if ($value !== '' && preg_match('/[A-Za-z0-9]/', $value) !== 1) {
                throw ApiException::badRequest("{$label} cannot contain only special characters or underscores");
            }
        }
        if (array_key_exists('mobile', $input)) {
            $mobile = trim($this->text($input['mobile']));
            if (! Phone::isPlausible(Phone::digits($mobile)) || preg_match('/^\+?[0-9 ]{7,20}$/', $mobile) !== 1) {
                throw ApiException::badRequest('Enter a valid mobile number');
            }
        }
        if (($input['password'] ?? null) !== null && $input['password'] !== '') {
            ClientPassword::vendor($input['password']);
        }

        return $input;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function vendorValues(array $input): array
    {
        $values = [];
        foreach (self::VENDOR_FIELDS as $field) {
            if (! array_key_exists($field, $input)) {
                continue;
            }
            $value = $input[$field];
            match ($field) {
                'profile_pic' => $values['avatar_url'] = is_string($value) ? $this->storage->storeDataUri($value, 'clients', 'profile_pic') : null,
                'subscription_id' => $values['plan_type_id'] = $this->vendorTier($value),
                'plan' => $values['plan_type_id'] ??= $this->vendorTierByName($value),
                'registration_type' => $values['registration_type'] = Flag::oneOf($value, ['guest', 'client'], 'client'),
                'client_type' => $values['newsletter_opt_in'] = $value === 'subscribed',
                'login_access', 'send_credentials_to_email' => $values[$field] = Flag::bit($value) === 1,
                'password' => ($value !== null && $value !== '') ? $values['password'] = $value : null,
                'mobile' => $values['mobile'] = Phone::digits($value) ?: null,
                default => $values[$field] = $value === '' ? null : $value,
            };
        }
        if (isset($values['password'])) {
            $values['password_changed_at'] = now();
        }

        return $values;
    }

    private function assertVendorEmailFree(mixed $email, ?int $except = null): void
    {
        if (is_string($email) && $email !== '' && Client::query()->where('email', $email)->when($except !== null, static fn ($q) => $q->whereKeyNot($except))->exists()) {
            throw ApiException::conflict('A client with this email is already registered.');
        }
    }

    /** A vendor tier this vendor may give (its own custom tiers or the common ones). */
    private function vendorTier(mixed $id): ?int
    {
        $id = Flag::id($id);
        if ($id === null) {
            return null;
        }

        return PlanType::query()->withoutGlobalScopes()->whereKey($id)
            ->where(fn ($q) => $q->where('is_custom', 0)->orWhere('vendor_id', $this->vendorId()))
            ->value('id') ?? throw ApiException::badRequest('Invalid subscription plan');
    }

    private function vendorTierByName(mixed $name): ?int
    {
        $name = trim($this->text($name));

        return $name === '' ? null : PlanType::query()->withoutGlobalScopes()->where('name', $name)
            ->where(fn ($q) => $q->where('is_custom', 0)->orWhere('vendor_id', $this->vendorId()))
            ->value('id');
    }

    private function platformPlan(mixed $id): ?int
    {
        $id = Flag::id($id);

        return $id === null ? null : (Plan::query()->whereKey($id)->value('id') ?? throw ApiException::badRequest('Invalid subscription plan'));
    }

    /** vendor_id from the admin form: a vendor of the admin's company, the house vendor by default. */
    private function companyVendor(mixed $id): int
    {
        $id = Flag::id($id) ?? Vendor::houseId();
        $ok = Vendor::query()->withoutGlobalScopes()->whereKey($id)->where('company_id', $this->company->id())->whereNull('deleted_at')->exists();

        return $ok ? $id : throw ApiException::badRequest('Invalid vendor');
    }

    private function vendorId(): int
    {
        return $this->vendor->id();
    }

    /** An admin-set password: checked, unless the controller already checked and hashed it (approval payloads). */
    private function adminPassword(mixed $value): string
    {
        return is_string($value) && Hash::isHashed($value) ? $value : ClientPassword::website($value);
    }

    private function newCode(): string
    {
        return 'CLI-'.mb_strtoupper(Str::random(10));
    }

    private function text(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }
}
