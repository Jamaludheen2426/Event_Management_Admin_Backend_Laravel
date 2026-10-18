<?php

declare(strict_types=1);

use App\Modules\Billing\Http\Controllers\AdminPaymentController;
use Illuminate\Support\Facades\Route;

/*
| Admin panel (portal.admin): /api/v1/payments — the clients' payments.
*/
Route::prefix('payments')->controller(AdminPaymentController::class)->group(static function (): void {
    Route::get('stats', 'stats')->middleware('perm:payments.view');
    Route::get('/', 'index')->middleware('perm:payments.view');
    Route::get('{id}', 'show')->middleware('perm:payments.view');
    Route::post('/', 'store')->middleware('perm:payments.create');
    Route::patch('{id}/status', 'status')->middleware('perm:payments.manage');
});
