<?php

declare(strict_types=1);

namespace App\Core\Auth;

use App\Core\Tenancy\CompanyScope;
use App\Core\Tenancy\VendorScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Laravel\Sanctum\PersonalAccessToken as SanctumPersonalAccessToken;

/**
 * Sanctum's token, with one change: loading the token's owner ignores tenant
 * scopes.
 *
 * The owner is read during authentication, BEFORE ResolveTenant has set the
 * tenant (it needs the owner to know the tenant). The token row names its owner
 * exactly, so lifting the scopes for this single lookup cannot leak another
 * tenant's data; everything after authentication runs scoped.
 *
 * session_id groups the access and refresh tokens of one sign-in (clients).
 *
 * @property int|null $session_id
 */
final class PersonalAccessToken extends SanctumPersonalAccessToken
{
    protected $table = 'personal_access_tokens';

    protected $fillable = ['name', 'token', 'abilities', 'expires_at', 'session_id'];

    /** @return MorphTo<Model, $this> */
    public function tokenable(): MorphTo
    {
        return $this->morphTo('tokenable')->withoutGlobalScopes([VendorScope::class, CompanyScope::class]);
    }
}
