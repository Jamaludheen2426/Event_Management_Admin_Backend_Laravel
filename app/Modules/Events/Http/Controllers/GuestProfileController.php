<?php

declare(strict_types=1);

namespace App\Modules\Events\Http\Controllers;

use App\Core\Http\ApiResponse;
use App\Modules\Clients\Models\Client;
use App\Modules\Events\Actions\GuestProfiles;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * /api/v1/client/guests/{id}/profile, notes, tags, reminders (v1
 * clientGuestProfile.controller).
 */
final class GuestProfileController
{
    public function __construct(private readonly GuestProfiles $profiles) {}

    public function show(Request $request, int $id): JsonResponse
    {
        return ApiResponse::success($this->profiles->show($this->client($request), $id), 'Guest profile retrieved');
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $client = $this->client($request);
        $this->profiles->update($client, $id, $request->all());

        return ApiResponse::success($this->profiles->show($client, $id), 'Guest profile updated');
    }

    public function addNote(Request $request, int $id): JsonResponse
    {
        return ApiResponse::success($this->profiles->addNote($this->client($request), $id, $request->all()), 'Note added', 201);
    }

    public function updateNote(Request $request, int $id, int $noteId): JsonResponse
    {
        return ApiResponse::success($this->profiles->updateNote($this->client($request), $id, $noteId, $request->all()), 'Note updated');
    }

    public function deleteNote(Request $request, int $id, int $noteId): JsonResponse
    {
        return ApiResponse::success($this->profiles->deleteNote($this->client($request), $id, $noteId), 'Note deleted');
    }

    public function addTag(Request $request, int $id): JsonResponse
    {
        return ApiResponse::success($this->profiles->addTag($this->client($request), $id, $request->all()), 'Tag added', 201);
    }

    public function removeTag(Request $request, int $id, int $tagId): JsonResponse
    {
        return ApiResponse::success($this->profiles->removeTag($this->client($request), $id, $tagId), 'Tag removed');
    }

    public function addReminder(Request $request, int $id): JsonResponse
    {
        return ApiResponse::success($this->profiles->addReminder($this->client($request), $id, $request->all()), 'Reminder added', 201);
    }

    public function updateReminder(Request $request, int $id, int $reminderId): JsonResponse
    {
        return ApiResponse::success($this->profiles->updateReminder($this->client($request), $id, $reminderId, $request->all()), 'Reminder updated');
    }

    public function deleteReminder(Request $request, int $id, int $reminderId): JsonResponse
    {
        return ApiResponse::success($this->profiles->deleteReminder($this->client($request), $id, $reminderId), 'Reminder deleted');
    }

    private function client(Request $request): Client
    {
        /** @var Client */
        return $request->user();
    }
}
