<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Http\Controllers;

use App\Core\Http\ApiResponse;
use App\Modules\Clients\Models\Client;
use App\Modules\Messaging\Actions\EventNotices;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;

/**
 * /api/v1/client/events/{id}/notifications and /client/event-notifications/{id}
 * — the app's Send Notification (v1 clientEventNotification.controller).
 */
final class EventNoticeController
{
    public function __construct(private readonly EventNotices $notices) {}

    public function index(Request $request, int $id): JsonResponse
    {
        return ApiResponse::success($this->notices->list($this->client($request), $id), 'Notifications loaded.');
    }

    public function audience(Request $request, int $id): JsonResponse
    {
        return ApiResponse::success($this->notices->audienceOptions($this->client($request), $id), 'Audience loaded.');
    }

    public function show(Request $request, int $notificationId): JsonResponse
    {
        return ApiResponse::success(['notification' => $this->notices->find($this->client($request), $notificationId)], 'Notification loaded.');
    }

    public function store(Request $request, int $id): JsonResponse
    {
        $result = $this->notices->create($this->client($request), $id, $request->all());

        return ApiResponse::created($result, $this->outcome($result));
    }

    public function update(Request $request, int $notificationId): JsonResponse
    {
        $result = $this->notices->update($this->client($request), $notificationId, $request->all());

        return ApiResponse::success($result, $this->outcome($result));
    }

    public function reschedule(Request $request, int $notificationId): JsonResponse
    {
        return ApiResponse::success(['notification' => $this->notices->reschedule($this->client($request), $notificationId, $request->all())], 'Notification rescheduled.');
    }

    public function destroy(Request $request, int $notificationId): JsonResponse
    {
        return ApiResponse::success($this->notices->delete($this->client($request), $notificationId), 'Notification deleted.');
    }

    public function uploadImage(Request $request, int $id): JsonResponse
    {
        $file = $request->file('file');

        return ApiResponse::success($this->notices->uploadImage($this->client($request), $id, $file instanceof UploadedFile ? $file : null), 'Image uploaded.');
    }

    /** @param array{notification: array<string, mixed>, outcome: array<string, mixed>|null} $result */
    private function outcome(array $result): string
    {
        $status = $result['notification']['status'];
        $outcome = $result['outcome'];
        if ($status === 'draft') {
            return 'Notification saved as draft.';
        }
        if ($status === 'scheduled') {
            return 'Notification scheduled.';
        }
        if ($outcome === null) {
            return 'Notification saved.';
        }
        $count = (int) $outcome['recipients'];

        return "Notification sent to {$count} participant".($count === 1 ? '' : 's')
            .(($outcome['push'] ?? false) ? " — push delivered to {$outcome['delivered']}." : " — saved in the app for {$outcome['delivered']}.");
    }

    private function client(Request $request): Client
    {
        /** @var Client */
        return $request->user();
    }
}
