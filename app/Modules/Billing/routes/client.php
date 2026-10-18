<?php

declare(strict_types=1);

use App\Modules\Billing\Http\Controllers\ClientBillingController;
use Illuminate\Support\Facades\Route;

/*
| Client portal (portal.client): /api/v1/client/billing/*. Every route acts on
| the signed-in client's own subscription — none takes an account id.
*/
Route::prefix('billing')->controller(ClientBillingController::class)->group(static function (): void {
    Route::get('overview', 'overview');
    Route::get('plans', 'plans');
    Route::get('history', 'history');
    Route::post('change-plan', 'changePlan');
    Route::post('cancel', 'cancel');
    Route::post('resume', 'resume');

    Route::get('payment-methods', 'paymentMethods');
    Route::post('payment-methods', 'addPaymentMethod');
    Route::put('payment-methods/{id}/default', 'defaultPaymentMethod');
    Route::delete('payment-methods/{id}', 'removePaymentMethod');

    Route::get('invoices', 'invoices');
    Route::get('invoices/{id}', 'invoice');

    Route::post('contact-sales', 'contactSales')->middleware('throttle:auth');
});
