<?php

declare(strict_types=1);

use App\Modules\Platform\Http\Controllers\CurrencyController;
use App\Modules\Platform\Http\Controllers\LanguageController;
use App\Modules\Platform\Http\Controllers\LocationController;
use App\Modules\Platform\Http\Controllers\SettingController;
use App\Modules\Platform\Http\Controllers\SystemController;
use App\Modules\Platform\Http\Controllers\TranslationController;
use Illuminate\Support\Facades\Route;

/*
| No login: what the frontends need before (or without) signing in. v1 paths.
*/

Route::get('setup/status', [SystemController::class, 'setupStatus']);
Route::get('timezones', [SystemController::class, 'timezones']);
Route::get('settings/public', [SettingController::class, 'public']);
Route::get('languages/active', [LanguageController::class, 'active']);
Route::get('currencies/active', [CurrencyController::class, 'active']);

Route::prefix('locations')->controller(LocationController::class)->group(static function (): void {
    Route::get('countries', 'countries');
    Route::get('states/{countryId?}', 'states')->whereNumber('countryId');
    Route::get('districts/{stateId?}', 'districts')->whereNumber('stateId');
    Route::get('cities/{districtId?}', 'cities')->whereNumber('districtId');
});

Route::prefix('translations')->controller(TranslationController::class)->group(static function (): void {
    Route::get('stats', 'stats');
    Route::get('groups', 'groups');
    Route::get('export', 'export');
    Route::post('report-missing', 'reportMissing')->middleware('throttle:60,1');
    Route::get('missing/count', 'missingCount');

    // Language codes only ("en", "ta", "zh-CN"), so these never swallow the named routes above.
    $code = '[a-zA-Z]{2,3}(?:[-_][a-zA-Z0-9]{2,4})?';
    Route::get('{langCode}/{group}', 'forGroup')->where('langCode', $code);
    Route::get('{langCode}', 'forLanguage')->where('langCode', $code);
});
