<?php

declare(strict_types=1);

namespace App\Modules\Events\Http\Controllers;

use App\Core\Exceptions\ApiException;
use App\Core\Http\ApiResponse;
use App\Core\Queries\CallbackListQuery;
use App\Modules\Clients\Models\Client;
use App\Modules\Events\Actions\EventSettings;
use App\Modules\Events\Actions\Participants;
use App\Modules\Events\Models\Event;
use App\Modules\Events\Models\Participant;
use App\Modules\Events\Support\EventAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * /api/v1/client/participants/* and the participant-directory settings of an
 * event (v1 clientParticipant / clientParticipantSettings controllers).
 */
final class ParticipantController
{
    public function __construct(private readonly Participants $participants) {}

    public function index(Request $request): JsonResponse
    {
        $participants = $this->participants;
        $limit = min(200, max(1, (int) $request->query('limit', '20')));
        $page = (new CallbackListQuery(
            $this->participants->query($this->client($request)),
            [],
            ['created_at'],
            false,
            static fn (Builder $q) => $participants->filter($q, $request),
        ))->paginate($request->duplicate([...$request->query(), 'limit' => $limit]))
            ->through(fn (Participant $p): array => $this->participants->present($p));

        return ApiResponse::paginated($page, message: 'Participants retrieved');
    }

    public function stats(Request $request): JsonResponse
    {
        return ApiResponse::success($this->participants->stats($this->client($request), $request->query('event_id')), 'Participant stats retrieved');
    }

    public function capacity(Request $request): JsonResponse
    {
        return ApiResponse::success($this->participants->capacity($this->client($request), $request->query('event_id')), 'Participant capacity retrieved');
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $p = $this->participants->present($this->participants->find($this->client($request), $id));

        return ApiResponse::success(['participant' => $p, 'guest' => $p], 'Participant retrieved');
    }

    public function store(Request $request): JsonResponse
    {
        $p = $this->participants->present($this->participants->create($this->client($request), $request->all()));

        return ApiResponse::created(['participant' => $p, 'guest' => $p], 'Participant added successfully');
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $client = $this->client($request);
        $p = $this->participants->present($this->participants->update($client, $this->participants->find($client, $id), $request->all()));

        return ApiResponse::success(['participant' => $p, 'guest' => $p], 'Participant updated successfully');
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $this->participants->find($this->client($request), $id)->delete();

        return ApiResponse::success(null, 'Participant removed');
    }

    /** Readable by the host and by the event's participants (the app's directory screen). */
    public function settings(Request $request, EventSettings $settings, EventAccess $access, int $id): JsonResponse
    {
        $client = $this->client($request);
        $event = Event::query()->with(['client', 'settings'])->find($id);
        if ($event === null) {
            throw ApiException::notFound('Event not found.');
        }
        if (! $access->canManage($client, $event) && ! $access->isParticipant($client, $id)) {
            throw ApiException::notFound('You are not a participant of this event.');
        }

        return ApiResponse::success(['event_id' => $event->id, 'settings' => $settings->participant($event)], 'Participant settings retrieved');
    }

    public function updateSettings(Request $request, EventSettings $settings, EventAccess $access, int $id): JsonResponse
    {
        $event = $access->manageable($this->client($request), $id);

        return ApiResponse::success(['event_id' => $event->id, 'settings' => $settings->updateParticipant($event, $request->all())], 'Participant settings saved');
    }

    private function client(Request $request): Client
    {
        /** @var Client */
        return $request->user();
    }
}
