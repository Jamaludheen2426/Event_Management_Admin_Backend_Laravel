<?php

declare(strict_types=1);

use App\Core\Http\ApiResponse;
use App\Core\Tenancy\Tenancy;
use App\Modules\Platform\Models\Company;
use App\Modules\Vendors\Models\Vendor;
use Illuminate\Support\Facades\Route;

/*
| The admin portal group, end to end: cookie or bearer token -> Sanctum ->
| ResolveTenant -> route model binding. Proves the middleware order pinned in
| bootstrap/app.php: if binding ran before the tenant was resolved, the scoped
| Vendor lookup below would throw instead of answering.
*/

beforeEach(function () {
    seedHouse();

    Route::middleware(['api', 'portal.admin'])->group(function () {
        Route::get('/_test/me', static fn () => ApiResponse::success([
            'user_id' => auth()->id(),
            'company_id' => Tenancy::companyId(),
            'vendor_id' => Tenancy::vendorId(),
        ]));
        Route::get('/_test/vendors/{vendor}', static fn (Vendor $vendor) => ApiResponse::success(['vendor' => ['id' => $vendor->id]]));
    });
});

it('rejects a request without a token in the v1 shape', function () {
    $this->getJson('/_test/me')
        ->assertStatus(401)
        ->assertJson(['success' => false, 'message' => 'Authentication required. Please login.']);
});

it('accepts a bearer token and resolves the house vendor for admins', function () {
    $user = adminUser();
    $token = $user->createToken('access')->plainTextToken;

    $this->withToken($token)->getJson('/_test/me')
        ->assertOk()
        ->assertJsonPath('data.user_id', $user->id)
        ->assertJsonPath('data.company_id', $user->company_id)
        ->assertJsonPath('data.vendor_id', Vendor::houseId());
});

it('accepts the legacy access_token cookie', function () {
    $user = adminUser();
    $token = $user->createToken('access')->plainTextToken;

    // withCredentials(): Laravel's test client only sends cookies on JSON calls when asked.
    $this->withCredentials()->withUnencryptedCookie('access_token', $token)->getJson('/_test/me')
        ->assertOk()
        ->assertJsonPath('data.user_id', $user->id);
});

it('prefers an explicit Authorization header over a cookie', function () {
    $cookieUser = adminUser();
    $headerUser = adminUser();

    $this->withCredentials()->withUnencryptedCookie('access_token', $cookieUser->createToken('a')->plainTextToken)
        ->withToken($headerUser->createToken('b')->plainTextToken)
        ->getJson('/_test/me')
        ->assertJsonPath('data.user_id', $headerUser->id);
});

it('rejects a revoked token', function () {
    $user = adminUser();
    $token = $user->createToken('access');
    $token->accessToken->delete();

    $this->withToken($token->plainTextToken)->getJson('/_test/me')->assertStatus(401);
});

it('binds route models only inside the caller\'s tenant', function () {
    $user = adminUser();
    $token = $user->createToken('access')->plainTextToken;

    $other = Tenancy::bypass(fn () => Vendor::factory()->for(Company::factory())->create());

    $this->withToken($token)->getJson('/_test/vendors/'.Vendor::houseId())
        ->assertOk()
        ->assertJsonPath('data.vendor.id', Vendor::houseId());

    $this->withToken($token)->getJson('/_test/vendors/'.$other->id)
        ->assertNotFound()
        ->assertJson(['success' => false, 'message' => 'Resource not found']);
});

it('answers 401 JSON, not a login redirect, when a browser (no JSON Accept header) calls a signed-in route', function () {
    // A browser or curl does not ask for JSON; there is no login page to send it to.
    $this->get('/api/v1/auth/me')->assertUnauthorized()
        ->assertJson(['success' => false, 'message' => 'Authentication required. Please login.']);
});
