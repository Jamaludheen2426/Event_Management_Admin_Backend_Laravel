<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Http\Controllers;

use App\Core\Http\ApiResponse;
use App\Modules\Clients\Models\Client;
use App\Modules\Messaging\Actions\MessageCampaigns;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * /api/v1/client/messages/* — the Messages screen (v1 clientMessage.controller).
 */
final class MessageController
{
    public function __construct(private readonly MessageCampaigns $campaigns) {}

    public function composer(Request $request): JsonResponse
    {
        return ApiResponse::success($this->campaigns->composer($this->client($request), $request->query('event_id')), 'Composer loaded');
    }

    public function preview(Request $request): JsonResponse
    {
        return ApiResponse::success($this->campaigns->preview($this->client($request), $request->all()), 'Audience resolved');
    }

    /** The message names what really happened: "sent" only when a provider delivered. */
    public function send(Request $request): JsonResponse
    {
        $data = $this->campaigns->send($this->client($request), $request->all());

        return ApiResponse::success($data, $data['delivery']['attempted']
            ? "Message sent to {$data['recipients']} guests"
            : "Message recorded for {$data['recipients']} guests — delivery is not enabled yet");
    }

    public function test(Request $request): JsonResponse
    {
        $data = $this->campaigns->test($this->client($request), $request->all());

        return ApiResponse::success($data, $data['sent'] ? 'Test sent' : 'Test could not be delivered');
    }

    public function index(Request $request): JsonResponse
    {
        $filters = $request->only(['channel', 'status', 'event_id', 'search', 'from', 'to', 'page', 'limit']);

        return ApiResponse::success($this->campaigns->list($this->client($request), $filters), 'Messages retrieved');
    }

    public function show(Request $request, int $id): JsonResponse
    {
        return ApiResponse::success($this->campaigns->show($this->client($request), $id), 'Message retrieved');
    }

    private function client(Request $request): Client
    {
        /** @var Client */
        return $request->user();
    }
}
