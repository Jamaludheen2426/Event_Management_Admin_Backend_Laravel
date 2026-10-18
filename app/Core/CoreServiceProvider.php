<?php

declare(strict_types=1);

namespace App\Core;

use App\Core\Activity\ActivityLogger;
use App\Core\Approvals\ApprovalRegistry;
use App\Core\Approvals\Approvals;
use App\Core\Auth\PersonalAccessToken;
use App\Core\Auth\SessionTokens;
use App\Core\Contracts\MachineTranslator;
use App\Core\Contracts\OtpSender;
use App\Core\Http\ApiResponse;
use App\Core\Infrastructure\Msg91OtpSender;
use App\Core\Infrastructure\MyMemoryTranslator;
use App\Core\Support\EventQr;
use App\Core\Tenancy\CurrentCompany;
use App\Core\Tenancy\CurrentVendor;
use App\Core\Tenancy\Tenancy;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\ColumnDefinition;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Sanctum;

/**
 * Wires the shared kernel. Business modules have their own providers
 * (app/Modules/<Name>/<Name>ServiceProvider.php).
 */
final class CoreServiceProvider extends ServiceProvider
{
    /** Route parameters that are always numeric ids, so "stats" never matches {id}. */
    private const NUMERIC_PARAMETERS = [
        'id', 'eventId', 'itemId', 'noteId', 'tagId', 'reminderId', 'memberId', 'wishId',
        'notificationId', 'templateId', 'categoryId', 'sliderId', 'vendorId', 'userId',
        'countryId', 'stateId', 'districtId', 'roleId', 'clientId', 'staffId',
    ];

    public function register(): void
    {
        // Fresh per HTTP request and per queued job.
        $this->app->scoped(CurrentVendor::class);
        $this->app->scoped(CurrentCompany::class);
        $this->app->scoped(Tenancy::class);

        // Filled once at boot by module providers.
        $this->app->singleton(ApprovalRegistry::class);
        $this->app->scoped(Approvals::class);
        $this->app->scoped(ActivityLogger::class);
        $this->app->singleton(SessionTokens::class);

        $this->app->singleton(EventQr::class, static fn (): EventQr => EventQr::fromConfig());

        $this->app->singleton(OtpSender::class, Msg91OtpSender::class);

        $this->app->singleton(MachineTranslator::class, static fn (): MachineTranslator => new MyMemoryTranslator(
            array_values(array_filter(array_map('trim', explode(',', (string) config('services.mymemory.emails'))))),
        ));
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Database/Migrations');

        Sanctum::usePersonalAccessTokenModel(PersonalAccessToken::class);

        // Only access tokens open the API. A refresh token presented as a
        // Bearer token is refused; it can only mint a new access token.
        Sanctum::authenticateAccessTokensUsing(
            static fn (PersonalAccessToken $token, bool $isValid): bool => $isValid && $token->can('access'),
        );

        JsonResource::withoutWrapping();

        // Lazy loading, silently dropped attributes and missing attributes throw
        // outside production: production's database is ~374 ms per query, so an
        // N+1 found late is expensive.
        Model::shouldBeStrict(! $this->app->isProduction());

        foreach (self::NUMERIC_PARAMETERS as $parameter) {
            Route::pattern($parameter, '[0-9]+');
        }

        $this->registerSchemaMacros();
        $this->registerRateLimiters();
    }

    /**
     * Soft delete + unique without renaming deleted rows.
     *
     * `alive` is 1 for a live row and NULL for a deleted one. A unique index on
     * (vendor_id, email, alive) therefore only binds live rows: MySQL lets any
     * number of NULLs coexist. This replaces the Node API's habit of rewriting a
     * deleted row's email or slug so the unique index would let a new row in.
     */
    private function registerSchemaMacros(): void
    {
        Blueprint::macro('aliveColumn', function (): ColumnDefinition {
            /** @var Blueprint $this */
            return $this->unsignedTinyInteger('alive')->nullable()->storedAs('if(`deleted_at` is null, 1, null)');
        });
    }

    /** Same budgets and words as the Node API's middleware/rateLimit.js. */
    private function registerRateLimiters(): void
    {
        $answer = static fn (string $message) => static fn () => ApiResponse::error($message, 429);

        RateLimiter::for('auth', static fn (Request $request) => Limit::perMinutes(15, 30)
            ->by($request->ip())
            ->response($answer('Too many attempts. Please wait a few minutes and try again.')));

        RateLimiter::for('code', static fn (Request $request) => Limit::perMinutes(15, 10)
            ->by($request->ip())
            ->response($answer('Too many incorrect codes. Please wait a few minutes and try again.')));

        RateLimiter::for('refresh', static fn (Request $request) => Limit::perMinutes(15, 60)
            ->by($request->ip())
            ->response($answer('Too many requests. Please wait a moment and try again.')));
    }
}
