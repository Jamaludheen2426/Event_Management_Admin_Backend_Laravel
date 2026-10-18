<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

beforeEach(function () {
    config()->set('cors.frontend_origins', ['https://admin.example.com']);

    Route::middleware('api')->group(function () {
        Route::get('/api/v1/public/site/bundle', static fn () => response()->json(['ok' => true]));
        Route::post('/api/v1/public/website-clients/login', static fn () => response()->json(['ok' => true]));
        Route::get('/api/v1/private-thing', static fn () => response()->json(['ok' => true]));
    });
});

it('lets any origin read the public site, without credentials', function () {
    $this->getJson('/api/v1/public/site/bundle', ['Origin' => 'https://some-tenant-domain.com'])
        ->assertOk()
        ->assertHeader('Access-Control-Allow-Origin', '*')
        ->assertHeaderMissing('Access-Control-Allow-Credentials');
});

it('keeps the cookie-setting public login on the credentialed list', function () {
    $this->postJson('/api/v1/public/website-clients/login', [], ['Origin' => 'https://some-tenant-domain.com'])
        ->assertStatus(403);

    $this->postJson('/api/v1/public/website-clients/login', [], ['Origin' => 'https://admin.example.com'])
        ->assertOk()
        ->assertHeader('Access-Control-Allow-Credentials', 'true');
});

it('reflects a listed origin with credentials on private routes', function () {
    $this->getJson('/api/v1/private-thing', ['Origin' => 'https://admin.example.com'])
        ->assertOk()
        ->assertHeader('Access-Control-Allow-Origin', 'https://admin.example.com')
        ->assertHeader('Access-Control-Allow-Credentials', 'true');
});

it('blocks an unlisted origin on private routes', function () {
    $this->getJson('/api/v1/private-thing', ['Origin' => 'https://evil.example.com'])
        ->assertStatus(403)
        ->assertJson(['success' => false, 'message' => 'CORS blocked: https://evil.example.com']);
});

it('answers a preflight for a listed origin', function () {
    $this->call('OPTIONS', '/api/v1/private-thing', [], [], [], [
        'HTTP_ORIGIN' => 'https://admin.example.com',
        'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
    ])->assertNoContent()->assertHeader('Access-Control-Allow-Origin', 'https://admin.example.com');
});

it('lets requests without an Origin header through (server-to-server, the app)', function () {
    $this->getJson('/api/v1/private-thing')->assertOk()->assertHeaderMissing('Access-Control-Allow-Origin');
});
