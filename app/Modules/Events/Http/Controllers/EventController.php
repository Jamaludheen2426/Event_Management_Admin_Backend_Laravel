<?php

declare(strict_types=1);

namespace App\Modules\Events\Http\Controllers;

use App\Core\Http\ApiResponse;
use App\Modules\Clients\Models\Client;
use App\Modules\Events\Actions\EventReader;
use App\Modules\Events\Actions\EventWriter;
use App\Modules\Events\Actions\GuestAnalytics;
use App\Modules\Events\Support\EventAccess;
use App\Modules\Events\Support\PlanEntitlements;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * /api/v1/client/events/* and /client/event-options — a client's events
 * (v1 clientEvent.controller). The organizer of an event manages it through
 * the same routes; only the owner may delete it.
 */
final class EventController
{
    public function __construct(
        private readonly EventReader $reader,
        private readonly EventWriter $writer,
        private readonly EventAccess $access,
    ) {}

    public function options(Request $request, PlanEntitlements $plans): JsonResponse
    {
        return ApiResponse::success($plans->options($this->access->planHolder($this->client($request))), 'Event options retrieved');
    }

    public function index(Request $request): JsonResponse
    {
        [$rows, $pagination] = $this->reader->list($this->client($request), $request);

        return ApiResponse::paginated(new LengthAwarePaginator($rows, $pagination['totalItems'], $pagination['limit'], $pagination['page']), message: 'Events retrieved');
    }

    public function stats(Request $request): JsonResponse
    {
        return ApiResponse::success($this->reader->stats($this->client($request)), 'Dashboard stats retrieved');
    }

    /** The guest/message side and the event side together (v1 analytics). */
    public function analytics(Request $request, GuestAnalytics $guests): JsonResponse
    {
        $client = $this->client($request);
        $events = $this->reader->analytics($client);

        return ApiResponse::success([
            ...$guests->report($client, (int) $request->query('days', '31')),
            'event_totals' => $events['totals'],
            'by_status' => $events['by_status'],
            'by_category' => $events['by_category'],
            'by_theme' => $events['by_theme'],
            'top_menus' => $events['top_menus'],
            'timeline' => $events['timeline'],
            'recent_events' => $events['recent_events'],
            'guests_available' => true,
            'messaging_available' => true,
        ], 'Analytics retrieved');
    }

    public function show(Request $request, int $id): JsonResponse
    {
        return ApiResponse::success(['event' => $this->reader->forViewer($this->client($request), $id)], 'Event retrieved');
    }

    public function store(Request $request): JsonResponse
    {
        $event = $this->writer->create($this->client($request), $request->all());

        return ApiResponse::created(['event' => $this->reader->forManager($event)], 'Event created successfully');
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $event = $this->access->manageable($this->client($request), $id);
        $event = $this->writer->update($event->client, $event, $request->all());

        return ApiResponse::success(['event' => $this->reader->forManager($event->refresh())], 'Event updated successfully');
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $this->writer->delete($this->access->owned($this->client($request), $id));

        return ApiResponse::success(null, 'Event deleted successfully');
    }

    public function decodeQr(Request $request): JsonResponse
    {
        return ApiResponse::success($this->reader->decodeQr($request->input('token') ?? $request->input('qr_token')), 'QR code decoded');
    }

    public function uploadCover(Request $request): JsonResponse
    {
        return ApiResponse::success($this->writer->uploadCover($this->file($request)), 'Image uploaded');
    }

    public function uploadInvitation(Request $request, int $id): JsonResponse
    {
        $event = $this->access->manageable($this->client($request), $id);

        return ApiResponse::success($this->writer->uploadInvitation($event, $this->file($request)), 'Invitation image saved');
    }

    public function wishlist(Request $request): JsonResponse
    {
        return ApiResponse::success(['events' => $this->reader->wishlist($this->client($request))], 'Wishlist retrieved');
    }

    public function addToWishlist(Request $request, int $id): JsonResponse
    {
        return ApiResponse::success($this->reader->setWishlisted($this->client($request), $id, true), 'Added to wishlist');
    }

    public function removeFromWishlist(Request $request, int $id): JsonResponse
    {
        return ApiResponse::success($this->reader->setWishlisted($this->client($request), $id, false), 'Removed from wishlist');
    }

    private function file(Request $request): ?UploadedFile
    {
        $file = $request->file('file');

        return $file instanceof UploadedFile ? $file : null;
    }

    private function client(Request $request): Client
    {
        /** @var Client */
        return $request->user();
    }
}
