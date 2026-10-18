<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Http\Controllers;

use App\Core\Http\ApiResponse;
use App\Modules\Clients\Models\Client;
use App\Modules\Messaging\Actions\ClientNotifications;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * /api/v1/client/notifications/* — the signed-in client's own feed
 * (v1 clientMessage.controller, notification half).
 */
final class NotificationController
{
    public function __construct(private readonly ClientNotifications $notifications) {}

    public function index(Request $request): JsonResponse
    {
        $data = $this->notifications->list($this->client($request), $request->only(['category', 'unread', 'search', 'event_id', 'page', 'limit']));

        return ApiResponse::success($data, 'Notifications retrieved');
    }

    /** The badge on its own, called on every page load. */
    public function count(Request $request): JsonResponse
    {
        return ApiResponse::success(['unread' => $this->notifications->unreadCount($this->client($request))], 'Unread count retrieved');
    }

    /** `read: false` marks it unread again. */
    public function read(Request $request, int $id): JsonResponse
    {
        $read = $request->input('read') !== false;
        $row = $this->notifications->markRead($this->client($request), $id, $read);

        return ApiResponse::success(['notification' => $row], $read ? 'Marked as read' : 'Marked as unread');
    }

    public function readAll(Request $request): JsonResponse
    {
        $data = $this->notifications->markAllRead(
            $this->client($request),
            $request->input('category') ?: $request->query('category'),
            $request->input('event_id') ?: $request->query('event_id'),
        );

        return ApiResponse::success($data, "{$data['marked']} marked as read");
    }

    public function archive(Request $request, int $id): JsonResponse
    {
        return ApiResponse::success($this->notifications->archive($this->client($request), $id), 'Notification archived');
    }

    private function client(Request $request): Client
    {
        /** @var Client */
        return $request->user();
    }
}
