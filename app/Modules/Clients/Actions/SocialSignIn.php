<?php

declare(strict_types=1);

namespace App\Modules\Clients\Actions;

use App\Core\Exceptions\ApiException;
use App\Core\Tenancy\VendorScope;
use App\Modules\Clients\Enums\ClientSource;
use App\Modules\Clients\Models\Client;
use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\AbstractProvider;
use Laravel\Socialite\Two\User as SocialiteUser;

/**
 * "Continue with Google / Facebook" for clients (v1 websiteClientOAuth.service),
 * through laravel/socialite.
 *
 * The sign-in returns to the site it started from; only our frontends, the
 * public-site root domains and hosts a module vouches for (Sites: tenant
 * domains) are allowed, so the redirect cannot be used to send people to
 * another site. The state is a single-use server-side token (10 minutes).
 */
final class SocialSignIn
{
    public const PROVIDERS = [
        'google' => ['label' => 'Google', 'params' => ['access_type' => 'online', 'prompt' => 'select_account'], 'scopes' => ['openid', 'email', 'profile']],
        'facebook' => ['label' => 'Facebook', 'params' => ['auth_type' => 'rerequest'], 'scopes' => ['email', 'public_profile']],
    ];

    private const STATE_MINUTES = 10;

    /** @var list<Closure(string): bool> */
    private array $hostChecks = [];

    /** @param Closure(string): bool $check host (lower case, no www.) → allowed */
    public function allowHosts(Closure $check): void
    {
        $this->hostChecks[] = $check;
    }

    /** @return list<string> */
    public function configuredProviders(): array
    {
        return array_values(array_filter(array_keys(self::PROVIDERS), fn (string $p): bool => $this->isConfigured($p)));
    }

    public function authorizeUrl(string $provider, mixed $returnTo, int $vendorId): string
    {
        $provider = $this->provider($provider);
        if (! $this->isConfigured($provider)) {
            throw ApiException::badRequest(self::PROVIDERS[$provider]['label'].' sign-in is not configured on this server.');
        }
        if (! is_string($returnTo) || ! $this->isAllowedReturnTo($returnTo)) {
            throw ApiException::badRequest('That return address is not an allowed site.');
        }

        $state = Str::random(48);
        Cache::put('client-oauth:'.$state, ['p' => $provider, 'r' => $returnTo, 'v' => $vendorId], now()->addMinutes(self::STATE_MINUTES));

        return $this->driver($provider)->with([...self::PROVIDERS[$provider]['params'], 'state' => $state])->redirect()->getTargetUrl();
    }

    /** @return array{p: string, r: string, v: int}|null the pending sign-in a callback belongs to (single use) */
    public function pullState(mixed $state): ?array
    {
        $value = is_string($state) && $state !== '' ? Cache::pull('client-oauth:'.$state) : null;

        return is_array($value) ? $value : null;
    }

    /**
     * The provider's profile → our client: by provider id, else by a verified
     * email of the same vendor (linking the accounts), else a new account.
     *
     * @return array{client: Client, created: bool}
     */
    public function completeSignIn(string $provider, int $vendorId): array
    {
        $user = $this->driver($provider)->user();
        if (! $user instanceof SocialiteUser) {
            throw ApiException::badRequest('The sign-in provider did not identify the account.');
        }
        $raw = $user->getRaw();
        $email = mb_strtolower(trim((string) $user->getEmail()));
        $verified = $provider === 'google'
            ? in_array($raw['email_verified'] ?? null, [true, 'true'], true)
            : $email !== '';
        $providerId = (string) $user->getId();
        if ($providerId === '') {
            throw ApiException::badRequest('The sign-in provider did not identify the account.');
        }

        $clients = static fn () => Client::query()->withoutGlobalScope(VendorScope::class)->with('vendor')->where('vendor_id', $vendorId);
        $created = false;

        $client = $clients()->where('source', $provider)->where('provider_id', $providerId)->first();
        if ($client === null && $email !== '' && $verified) {
            $client = $clients()->where('email', $email)->first();
            $client?->forceFill(['provider_id' => $providerId, 'avatar_url' => $client->avatar_url ?? $user->getAvatar(), 'email_verified' => true])->save();
        }
        if ($client === null) {
            if ($email === '') {
                throw ApiException::badRequest("We need your email address to create an account. Please try again and leave the email permission ticked on the provider's consent screen.");
            }
            if ($clients()->where('email', $email)->exists()) {
                throw ApiException::badRequest('An account with this email already exists. Please sign in with your password.');
            }
            $client = Client::query()->create([
                'vendor_id' => $vendorId,
                'source' => ClientSource::from($provider),
                'provider_id' => $providerId,
                'name' => trim((string) $user->getName()) ?: Str::before($email, '@'),
                'email' => $email,
                'avatar_url' => $user->getAvatar(),
                'email_verified' => $verified,
            ])->load('vendor');
            $created = true;
        }

        if (! $client->isActive()) {
            throw ApiException::forbidden('Your account is not active. Please contact us.');
        }
        $client->forceFill(['last_login_at' => now()])->save();

        return ['client' => $client, 'created' => $created];
    }

    public function isAllowedReturnTo(string $url): bool
    {
        $parts = parse_url($url);
        if (! is_array($parts) || ! in_array($parts['scheme'] ?? null, ['http', 'https'], true) || ! isset($parts['host'])) {
            return false;
        }

        $origin = $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');
        if (in_array($origin, (array) config('cors.frontend_origins'), true)) {
            return true;
        }

        $host = (string) preg_replace('/^www\./', '', mb_strtolower($parts['host']));
        foreach ((array) config('clients.public_site_root_domains') as $root) {
            $root = (string) preg_replace('/^www\./', '', mb_strtolower((string) $root));
            if ($root !== '' && ($host === $root || str_ends_with($host, '.'.$root))) {
                return true;
            }
        }

        foreach ($this->hostChecks as $check) {
            if ($check($host)) {
                return true;
            }
        }

        return false;
    }

    public function provider(string $name): string
    {
        $name = mb_strtolower($name);

        return array_key_exists($name, self::PROVIDERS) ? $name : throw ApiException::badRequest('Unknown sign-in provider.');
    }

    private function isConfigured(string $provider): bool
    {
        return config("services.{$provider}.client_id") && config("services.{$provider}.client_secret");
    }

    private function driver(string $provider): AbstractProvider
    {
        $base = rtrim((string) config('clients.oauth_callback_base_url'), '/');
        if ($base === '') {
            throw ApiException::badRequest('OAUTH_CALLBACK_BASE_URL is not set, so the sign-in redirect cannot be built.');
        }

        /** @var AbstractProvider $driver */
        $driver = Socialite::driver($provider);

        return $driver->stateless()
            ->redirectUrl("{$base}/api/v1/public/website-clients/oauth/{$provider}/callback")
            ->scopes(self::PROVIDERS[$provider]['scopes']);
    }
}
