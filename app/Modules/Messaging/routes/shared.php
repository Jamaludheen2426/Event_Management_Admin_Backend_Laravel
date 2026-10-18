<?php

declare(strict_types=1);

use App\Modules\Messaging\Http\Controllers\ChatController;
use App\Modules\Messaging\Http\Controllers\MailController;
use Illuminate\Support\Facades\Route;

/*
| /api/v1/mail and /api/v1/chat — shared by the admin, vendor and client
| portals; PortalFromHeader signs the caller in through their own portal.
| {id} is digits only, so the fixed paths never reach it.
*/
Route::prefix('mail')->controller(MailController::class)->group(static function (): void {
    Route::get('contacts', 'contacts');
    Route::get('notifications', 'notifications');
    Route::patch('notifications/read', 'readNotifications');
    Route::post('drafts', 'saveDraft');
    Route::put('drafts/{id}', 'updateDraft');
    Route::post('drafts/{id}/send', 'sendDraft');
    Route::post('send', 'send');
    Route::get('sent', 'sent');
    Route::get('drafts', 'drafts');
    Route::get('trash', 'trash');
    Route::patch('trash/{id}/restore', 'restore');
    Route::delete('trash/{id}', 'purge');
    Route::post('bulk-delete', 'bulkDelete');
    Route::post('bulk-read', 'bulkRead');
    Route::post('bulk-label', 'bulkLabel');
    Route::post('bulk-folder', 'bulkFolder');
    Route::get('folders', 'folders');
    Route::post('folders', 'createFolder');
    Route::put('folders/{id}', 'updateFolder');
    Route::patch('folders/{id}/status', 'updateFolder');
    Route::delete('folders/{id}', 'deleteFolder');
    Route::get('/', 'inbox');
    Route::get('{id}', 'show');
    Route::delete('{id}', 'destroy');
    Route::patch('{id}/read', 'toggleRead');
    Route::patch('{id}/label', 'label');
    Route::patch('{id}/folder', 'folder');
});

Route::prefix('chat')->controller(ChatController::class)->group(static function (): void {
    Route::get('token', 'token');
    Route::get('contacts', 'contacts');
    Route::get('conversations', 'conversations');
    Route::post('conversations/direct', 'direct');
    Route::get('conversations/{id}/messages', 'messages');
    Route::patch('conversations/{id}/read', 'read');
    Route::post('messages', 'send');
});
