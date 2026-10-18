<?php

declare(strict_types=1);

use App\Modules\Messaging\Http\Controllers\EventNoticeController;
use App\Modules\Messaging\Http\Controllers\MessageController;
use App\Modules\Messaging\Http\Controllers\NoticeTemplateController;
use App\Modules\Messaging\Http\Controllers\NotificationController;
use Illuminate\Support\Facades\Route;

/*
| Client portal and app (portal.client): /api/v1/client/... — the signed-in
| client's notification feed, messages to guests and event notifications.
*/
Route::get('notifications/count', [NotificationController::class, 'count']);
Route::put('notifications/read-all', [NotificationController::class, 'readAll']);
Route::get('notifications', [NotificationController::class, 'index']);
Route::put('notifications/{id}/read', [NotificationController::class, 'read']);
Route::put('notifications/{id}/archive', [NotificationController::class, 'archive']);

// The Messages screen.
Route::prefix('messages')->group(static function (): void {
    Route::get('composer', [MessageController::class, 'composer']);
    Route::post('preview', [MessageController::class, 'preview']);
    Route::post('test', [MessageController::class, 'test']);
    Route::post('send', [MessageController::class, 'send']);
    Route::get('/', [MessageController::class, 'index']);
    Route::get('{id}', [MessageController::class, 'show']);
});

// The app's Send Notification.
Route::get('events/{id}/notifications', [EventNoticeController::class, 'index']);
Route::post('events/{id}/notifications', [EventNoticeController::class, 'store']);
Route::get('events/{id}/notifications/audience', [EventNoticeController::class, 'audience']);
Route::post('events/{id}/notifications/image', [EventNoticeController::class, 'uploadImage']);
Route::get('event-notifications/{notificationId}', [EventNoticeController::class, 'show']);
Route::put('event-notifications/{notificationId}', [EventNoticeController::class, 'update']);
Route::put('event-notifications/{notificationId}/reschedule', [EventNoticeController::class, 'reschedule']);
Route::delete('event-notifications/{notificationId}', [EventNoticeController::class, 'destroy']);

// The platform's automatic notices, per event.
Route::get('events/notification-templates/summary', [NoticeTemplateController::class, 'summary']);
Route::get('events/{id}/notification-templates', [NoticeTemplateController::class, 'index']);
Route::patch('events/{id}/notification-templates/{templateId}', [NoticeTemplateController::class, 'toggle']);
Route::post('events/{id}/notification-templates/{templateId}/test', [NoticeTemplateController::class, 'test']);
