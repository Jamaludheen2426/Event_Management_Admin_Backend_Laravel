<?php

declare(strict_types=1);

use App\Modules\Clients\Http\Controllers\ClientAccountController;
use App\Modules\Clients\Http\Controllers\ClientSecurityController;
use Illuminate\Support\Facades\Route;

/*
| Client portal and app (portal.client): /api/v1/client — the client's own
| account. Events, guests and billing routes arrive with their modules.
*/
Route::controller(ClientAccountController::class)->group(static function (): void {
    Route::get('me', 'me');
    Route::put('me', 'update');
    Route::put('me/password', 'changePassword');
    Route::delete('me', 'destroy');
    Route::put('me/avatar', 'updateAvatar');
    Route::delete('me/avatar', 'removeAvatar');
    Route::put('favourite-templates', 'favouriteTemplates');
    Route::get('media/proxy', 'mediaProxy');

    Route::get('settings', 'settings');
    Route::put('settings/preferences', 'updatePreferences');
    Route::put('settings/notifications', 'updateNotifications');

    Route::get('devices', 'devices');
    Route::post('devices', 'registerDevice');
    Route::delete('devices', 'releaseDevice');
});

Route::prefix('security')->controller(ClientSecurityController::class)->group(static function (): void {
    Route::get('sessions', 'sessions');
    Route::delete('sessions/{id}', 'revokeSession');
    Route::post('sessions/revoke-all', 'revokeOthers');
    Route::get('devices', 'devices');
    Route::delete('devices/{id}', 'removeDevice');

    Route::get('2fa', 'twoFactor');
    Route::post('2fa/setup', 'setupTwoFactor')->middleware('throttle:code');
    Route::post('2fa/confirm', 'confirmTwoFactor')->middleware('throttle:code');
    Route::post('2fa/disable', 'disableTwoFactor')->middleware('throttle:code');
    Route::post('2fa/backup-codes', 'backupCodes')->middleware('throttle:code');
});
