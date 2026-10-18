<?php

declare(strict_types=1);

use App\Core\Exceptions\ApiException;
use App\Core\Tenancy\Tenancy;
use App\Modules\Vendors\Models\Vendor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    Route::middleware('api')->group(function () {
        Route::get('/_test/rule', static fn () => throw ApiException::badRequest('That event category is not included in your subscription plan.'));
        Route::get('/_test/missing', static fn () => Tenancy::bypass(fn () => Vendor::query()->findOrFail(999)));
        Route::post('/_test/validate', static fn (Request $request) => $request->validate(['email' => ['required', 'email']]));
        Route::get('/_test/crash', static fn () => throw new RuntimeException('boom'));
        Route::get('/_test/forbidden', static fn () => abort(403));
    });
});

it('shows a business rule message with its own status', function () {
    $this->getJson('/_test/rule')
        ->assertStatus(400)
        ->assertJson(['success' => false, 'message' => 'That event category is not included in your subscription plan.'])
        ->assertJsonStructure(['success', 'message', 'timestamp']);
});

it('answers 404 Resource not found for a missing model', function () {
    $this->getJson('/_test/missing')->assertNotFound()->assertJson(['success' => false, 'message' => 'Resource not found']);
});

it('answers 404 for an unknown route', function () {
    $this->getJson('/api/v1/does-not-exist')->assertNotFound()->assertJson(['success' => false]);
});

it('answers validation failures with 400, the first message and the field errors', function () {
    $this->postJson('/_test/validate', ['email' => 'nope'])
        ->assertStatus(400)
        ->assertJsonPath('success', false)
        ->assertJsonPath('message', 'The email field must be a valid email address.')
        ->assertJsonStructure(['errors' => ['email']]);
});

it('answers 403 in the v1 words', function () {
    $this->getJson('/_test/forbidden')->assertForbidden()->assertJson(['message' => 'Access denied. Insufficient permissions.']);
});

it('answers unexpected errors with 500 in the v1 envelope', function () {
    $this->getJson('/_test/crash')->assertStatus(500)->assertJson(['success' => false, 'message' => 'boom']);
});
