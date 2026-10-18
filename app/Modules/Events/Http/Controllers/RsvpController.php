<?php

declare(strict_types=1);

namespace App\Modules\Events\Http\Controllers;

use App\Core\Http\ApiResponse;
use App\Modules\Clients\Models\Client;
use App\Modules\Events\Actions\EventSettings;
use App\Modules\Events\Actions\Rsvps;
use App\Modules\Events\Support\EventAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * /api/v1/client/rsvps/* and /client/events/{id}/rsvp-settings (v1
 * clientRsvp.controller).
 */
final class RsvpController
{
    private const FILTERS = ['event_id', 'side', 'relationship', 'status', 'search', 'from', 'to', 'page', 'limit'];

    public function __construct(private readonly Rsvps $rsvps) {}

    public function index(Request $request): JsonResponse
    {
        return ApiResponse::success($this->rsvps->list($this->client($request), $request->only(self::FILTERS)), 'RSVPs retrieved');
    }

    public function stats(Request $request): JsonResponse
    {
        return ApiResponse::success($this->rsvps->stats($this->client($request), $request->only(self::FILTERS)), 'RSVP stats retrieved');
    }

    public function export(Request $request): JsonResponse
    {
        return ApiResponse::success($this->rsvps->export($this->client($request), $request->only(self::FILTERS)), 'Export ready');
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $client = $this->client($request);

        return ApiResponse::success($this->rsvps->detail($client, $this->rsvps->find($client, $id)), 'RSVP retrieved');
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $client = $this->client($request);
        $participant = $this->rsvps->find($client, $id);
        $this->rsvps->update($client, $participant, $request->all());

        return ApiResponse::success($this->rsvps->detail($client, $participant->refresh()), 'RSVP updated');
    }

    public function reset(Request $request, int $id): JsonResponse
    {
        $client = $this->client($request);
        $participant = $this->rsvps->find($client, $id);
        $this->rsvps->reset($client, $participant, $request->input('reason'));

        return ApiResponse::success($this->rsvps->detail($client, $participant->refresh()), 'Response cleared — the guest can respond again');
    }

    public function settings(Request $request, EventSettings $settings, EventAccess $access, int $id): JsonResponse
    {
        return ApiResponse::success($settings->rsvpView($access->manageable($this->client($request), $id)), 'RSVP settings retrieved');
    }

    public function updateSettings(Request $request, EventSettings $settings, EventAccess $access, int $id): JsonResponse
    {
        $event = $access->manageable($this->client($request), $id);
        $input = $request->only(['enabled', 'response_options', 'deadline', 'allow_guest_count', 'allow_food_preference', 'allow_special_requests', 'allow_relationship']);

        return ApiResponse::success($settings->updateRsvp($event, $input), 'RSVP settings saved');
    }

    private function client(Request $request): Client
    {
        /** @var Client */
        return $request->user();
    }
}
