<?php

declare(strict_types=1);

use App\Modules\Messaging\Http\Controllers\NewsletterController;
use App\Modules\Messaging\Http\Controllers\VendorEmailController;
use Illuminate\Support\Facades\Route;

/*
| Vendor portal (portal.vendor): /api/v1/vendors/... — the vendor's e-mail
| categories and templates, and its newsletter.
*/
Route::prefix('newsletter')->controller(NewsletterController::class)->group(static function (): void {
    Route::get('subscribers', 'subscribers');
    Route::get('unsubscribers', 'unsubscribers');
    Route::get('sends', 'sends');
    Route::get('sent-logs', 'log');
    Route::patch('bulk', 'bulk');
    Route::patch('bulk-ids', 'bulkIds');
    Route::patch('{id}/client-type', 'toggle');
    Route::post('send', 'send');
});

Route::controller(VendorEmailController::class)->group(static function (): void {
    Route::get('email-categories', 'categories');
    Route::get('email-categories/{id}', 'category');
    Route::post('email-categories', 'storeCategory');
    Route::put('email-categories/{id}', 'updateCategory');
    Route::delete('email-categories/{id}', 'destroyCategory');
    Route::get('email-templates', 'templates');
    Route::get('email-templates/{id}', 'template');
    Route::post('email-templates', 'storeTemplate');
    Route::put('email-templates/{id}', 'updateTemplate');
    Route::patch('email-templates/{id}/status', 'toggleTemplate');
    Route::delete('email-templates/{id}', 'destroyTemplate');
});
