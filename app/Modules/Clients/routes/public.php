<?php

declare(strict_types=1);

use App\Modules\Clients\Http\Controllers\ClientAuthController;
use App\Modules\Clients\Http\Controllers\VendorSiteClientController;
use Illuminate\Support\Facades\Route;

/*
| /api/v1/public — no login. Client sign-in for the portal, the public site
| and the app, and sign-up / sign-in / newsletter on a vendor's public site.
*/
Route::prefix('website-clients')->controller(ClientAuthController::class)->group(static function (): void {
    Route::post('register', 'register')->middleware('throttle:auth');
    Route::post('login', 'login')->middleware('throttle:auth');
    Route::post('login/2fa/verify', 'verifyLogin2fa')->middleware('throttle:code');
    Route::post('logout', 'logout');
    Route::post('login/otp/request', 'requestLoginOtp')->middleware('throttle:auth');
    Route::post('login/otp/verify', 'verifyLoginOtp')->middleware('throttle:code');
    Route::post('token/refresh', 'refresh')->middleware('throttle:refresh');

    Route::get('oauth/providers', 'oauthProviders');
    Route::get('oauth/{provider}/start', 'oauthStart');
    Route::get('oauth/{provider}/callback', 'oauthCallback');

    Route::post('mobile/send-otp', 'sendMobileOtp')->middleware('throttle:auth');
    Route::post('mobile/verify', 'verifyMobileOtp')->middleware('throttle:code');
});

Route::prefix('vendors/{slug}')->controller(VendorSiteClientController::class)->group(static function (): void {
    Route::post('register-client', 'register')->middleware('throttle:auth');
    Route::post('login-client', 'login')->middleware('throttle:auth');
    Route::post('newsletter-subscribe', 'subscribe')->middleware('throttle:auth');
});
