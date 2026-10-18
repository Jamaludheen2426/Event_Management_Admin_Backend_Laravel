<?php

declare(strict_types=1);

use App\Modules\Events\Http\Controllers\EventContentController;
use App\Modules\Events\Http\Controllers\EventController;
use App\Modules\Events\Http\Controllers\GalleryController;
use App\Modules\Events\Http\Controllers\GuestController;
use App\Modules\Events\Http\Controllers\GuestJoinController;
use App\Modules\Events\Http\Controllers\GuestProfileController;
use App\Modules\Events\Http\Controllers\ParticipantController;
use App\Modules\Events\Http\Controllers\RsvpController;
use App\Modules\Events\Http\Controllers\SplashScreenController;
use App\Modules\Events\Http\Controllers\WishController;
use Illuminate\Support\Facades\Route;

/*
| Client portal and app (portal.client): /api/v1/client/... — events, the
| phone book, participants and RSVPs. {id} and the other ids are digits only,
| so fixed paths (stats, wishlist, groups, import...) never reach them.
*/
Route::get('event-options', [EventController::class, 'options']);

Route::prefix('events')->group(static function (): void {
    Route::controller(EventController::class)->group(static function (): void {
        Route::get('stats', 'stats');
        Route::get('analytics', 'analytics');
        Route::post('qr/decode', 'decodeQr');
        Route::post('cover-image', 'uploadCover');
        Route::get('wishlist', 'wishlist');

        Route::get('/', 'index');
        Route::post('/', 'store');
        Route::get('{id}', 'show');
        Route::put('{id}', 'update');
        Route::delete('{id}', 'destroy');
        Route::post('{id}/invitation-image', 'uploadInvitation');
        Route::post('{id}/wishlist', 'addToWishlist');
        Route::delete('{id}/wishlist', 'removeFromWishlist');
    });

    Route::post('join', [GuestJoinController::class, 'join']);
    Route::get('joined', [GuestJoinController::class, 'joined']);
    Route::get('{id}/my-rsvp', [GuestJoinController::class, 'myRsvp']);
    Route::post('{id}/my-rsvp', [GuestJoinController::class, 'submitMyRsvp']);
    Route::get('{id}/participants', [GuestJoinController::class, 'directory']);

    Route::get('{id}/rsvp-settings', [RsvpController::class, 'settings']);
    Route::put('{id}/rsvp-settings', [RsvpController::class, 'updateSettings']);
    Route::get('{id}/participant-settings', [ParticipantController::class, 'settings']);
    Route::put('{id}/participant-settings', [ParticipantController::class, 'updateSettings']);
});

Route::prefix('guests')->group(static function (): void {
    Route::controller(GuestController::class)->group(static function (): void {
        Route::get('stats', 'stats');
        Route::get('capacity', 'capacity');
        Route::get('form-options', 'formOptions');
        Route::get('export', 'export');
        Route::post('bulk', 'bulk');
        Route::post('photo', 'photo');
        Route::get('import/sample', 'sample');
        Route::post('import/preview', 'previewImport');
        Route::post('import', 'import');

        Route::get('groups/all', 'allGroups');
        Route::get('groups/stats', 'groupStats');
        Route::get('groups', 'groups');
        Route::post('groups', 'storeGroup');
        Route::get('groups/{id}', 'showGroup');
        Route::put('groups/{id}', 'updateGroup');
        Route::delete('groups/{id}', 'destroyGroup');

        Route::get('/', 'index');
        Route::post('/', 'store');
        Route::get('{id}', 'show');
        Route::put('{id}', 'update');
        Route::delete('{id}', 'destroy');
    });

    Route::controller(GuestProfileController::class)->group(static function (): void {
        Route::get('{id}/profile', 'show');
        Route::put('{id}/profile', 'update');
        Route::post('{id}/notes', 'addNote');
        Route::put('{id}/notes/{noteId}', 'updateNote');
        Route::delete('{id}/notes/{noteId}', 'deleteNote');
        Route::post('{id}/tags', 'addTag');
        Route::delete('{id}/tags/{tagId}', 'removeTag');
        Route::post('{id}/reminders', 'addReminder');
        Route::put('{id}/reminders/{reminderId}', 'updateReminder');
        Route::delete('{id}/reminders/{reminderId}', 'deleteReminder');
    });
});

Route::prefix('participants')->controller(ParticipantController::class)->group(static function (): void {
    Route::get('stats', 'stats');
    Route::get('capacity', 'capacity');
    Route::get('/', 'index');
    Route::post('/', 'store');
    Route::get('{id}', 'show');
    Route::put('{id}', 'update');
    Route::delete('{id}', 'destroy');
});

Route::prefix('rsvps')->controller(RsvpController::class)->group(static function (): void {
    Route::get('stats', 'stats');
    Route::get('export', 'export');
    Route::get('/', 'index');
    Route::get('{id}', 'show');
    Route::put('{id}', 'update');
    Route::put('{id}/reset', 'reset');
});

// ---- agenda, family, gallery, wishes (event content) ----------------------------
Route::controller(EventContentController::class)->group(static function (): void {
    Route::get('events/{id}/agenda', 'agenda');
    Route::post('events/{id}/agenda', 'addAgenda');
    Route::put('events/{id}/agenda/reorder', 'reorderAgenda');
    Route::post('events/{id}/agenda/media', 'agendaImage');
    Route::get('agenda/{itemId}', 'agendaItem');
    Route::put('agenda/{itemId}', 'updateAgenda');
    Route::post('agenda/{itemId}/duplicate', 'duplicateAgenda');
    Route::delete('agenda/{itemId}', 'deleteAgenda');

    Route::get('events/{id}/family-members', 'family');
    Route::post('events/{id}/family-members', 'addFamily');
    Route::put('events/{id}/family-members/reorder', 'reorderFamily');
    Route::get('events/{id}/family-settings', 'familySettings');
    Route::put('events/{id}/family-settings', 'updateFamilySettings');
    Route::get('family-members/{memberId}', 'familyMember');
    Route::put('family-members/{memberId}', 'updateFamily');
    Route::delete('family-members/{memberId}', 'deleteFamily');
});

Route::controller(GalleryController::class)->group(static function (): void {
    Route::get('events/{id}/gallery', 'index');
    Route::get('events/{id}/gallery/usage', 'usage');
    Route::post('events/{id}/gallery', 'upload');
    Route::put('events/{id}/gallery/reorder', 'reorder');
    Route::get('events/{id}/gallery/categories', 'categories');
    Route::post('events/{id}/gallery/categories', 'addCategory');
    Route::post('events/{id}/gallery/categories/cover', 'categoryCover');
    Route::put('gallery/categories/{categoryId}', 'updateCategory');
    Route::delete('gallery/categories/{categoryId}', 'deleteCategory');
    Route::put('gallery/{itemId}', 'update');
    Route::delete('gallery/{itemId}', 'destroy');
});

Route::controller(WishController::class)->group(static function (): void {
    Route::get('events/{id}/wishes', 'index');
    Route::post('events/{id}/wishes', 'store');
    Route::get('events/{id}/wish-settings', 'settings');
    Route::put('events/{id}/wish-settings', 'updateSettings');
    Route::get('events/{id}/wish-filter', 'filter');
    Route::put('events/{id}/wish-filter', 'updateFilter');
    Route::get('wishes/{wishId}', 'show');
    Route::put('wishes/{wishId}', 'update');
    Route::post('wishes/{wishId}/media', 'media');
    Route::delete('wishes/{wishId}', 'destroy');
    Route::post('wishes/{wishId}/like', 'like');
    Route::delete('wishes/{wishId}/like', 'unlike');
    Route::post('wishes/{wishId}/block', 'block');
    Route::delete('wishes/{wishId}/block', 'unblock');
});

Route::prefix('splash-screens')->controller(SplashScreenController::class)->group(static function (): void {
    Route::get('for-event/{eventId}', 'forEvent');
    Route::get('decorations', 'decorations');
    Route::post('media', 'media');
    Route::get('/', 'index');
    Route::post('/', 'store');
    Route::get('{id}', 'show');
    Route::put('{id}', 'update');
    Route::delete('{id}', 'destroy');
});
