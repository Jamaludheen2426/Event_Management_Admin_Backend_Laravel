<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Http\Controllers;

use App\Core\Http\ApiResponse;
use App\Modules\Clients\Models\Client;
use App\Modules\Messaging\Actions\NoticeTemplates;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * /api/v1/client/events/.../notification-templates — the automatic notices
 * per event (v1 clientEventNotificationTemplate.controller).
 */
final class NoticeTemplateController
{
    public function __construct(private readonly NoticeTemplates $templates) {}

    public function summary(Request $request): JsonResponse
    {
        return ApiResponse::success($this->templates->summary($this->client($request)), 'Notification template summary retrieved');
    }

    public function index(Request $request, int $id): JsonResponse
    {
        return ApiResponse::success($this->templates->forEvent($this->client($request), $id), 'Applicable notification templates retrieved');
    }

    public function toggle(Request $request, int $id, int $templateId): JsonResponse
    {
        return ApiResponse::success($this->templates->toggle($this->client($request), $id, $templateId, $request->input('enabled')), 'Notification template preference updated');
    }

    /** Sends the Welcome Invitation to the owner as a sample. */
    public function test(Request $request, int $id): JsonResponse
    {
        $client = $this->client($request);
        $event = $this->templates->event($client, $id);
        $guest = $request->input('guest');
        $result = $this->templates->welcome($event, null, $client, is_array($guest) && is_string($guest['name'] ?? null) ? $guest['name'] : null);

        return ApiResponse::success($result, match (true) {
            $result['triggered'] => 'Welcome Invitation triggered successfully',
            ($result['reason'] ?? null) === 'disabled_by_client_portal' => 'Welcome Invitation is disabled for this event in client portal',
            default => 'Welcome Invitation was not triggered',
        });
    }

    private function client(Request $request): Client
    {
        /** @var Client */
        return $request->user();
    }
}
