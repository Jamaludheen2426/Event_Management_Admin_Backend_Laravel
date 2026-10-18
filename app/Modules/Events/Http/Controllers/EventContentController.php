<?php

declare(strict_types=1);

namespace App\Modules\Events\Http\Controllers;

use App\Core\Http\ApiResponse;
use App\Modules\Clients\Models\Client;
use App\Modules\Events\Actions\Agenda;
use App\Modules\Events\Actions\Family;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;

/**
 * An event's agenda and families (v1 clientAgenda / clientFamily
 * controllers): the host and organizer edit, participants read.
 */
final class EventContentController
{
    public function __construct(
        private readonly Agenda $agenda,
        private readonly Family $family,
    ) {}

    // ---- agenda -------------------------------------------------------------------

    public function agenda(Request $request, int $id): JsonResponse
    {
        return ApiResponse::success($this->agenda->list($this->client($request), $id), 'Agenda loaded.');
    }

    public function agendaItem(Request $request, int $itemId): JsonResponse
    {
        return ApiResponse::success(['item' => $this->agenda->show($this->client($request), $itemId)], 'Agenda item loaded.');
    }

    public function addAgenda(Request $request, int $id): JsonResponse
    {
        return ApiResponse::created(['item' => $this->agenda->create($this->client($request), $id, $request->all())], 'Agenda added.');
    }

    public function updateAgenda(Request $request, int $itemId): JsonResponse
    {
        return ApiResponse::success(['item' => $this->agenda->update($this->client($request), $itemId, $request->all())], 'Agenda updated.');
    }

    public function duplicateAgenda(Request $request, int $itemId): JsonResponse
    {
        return ApiResponse::created(['item' => $this->agenda->duplicate($this->client($request), $itemId)], 'Agenda duplicated.');
    }

    public function deleteAgenda(Request $request, int $itemId): JsonResponse
    {
        return ApiResponse::success($this->agenda->delete($this->client($request), $itemId), 'Agenda deleted.');
    }

    public function reorderAgenda(Request $request, int $id): JsonResponse
    {
        return ApiResponse::success($this->agenda->reorder($this->client($request), $id, $request->input('ids')), 'Agenda order saved.');
    }

    public function agendaImage(Request $request, int $id): JsonResponse
    {
        $file = $request->file('file');

        return ApiResponse::success($this->agenda->upload($this->client($request), $id, $file instanceof UploadedFile ? $file : null), 'Image uploaded.');
    }

    // ---- family -------------------------------------------------------------------

    public function family(Request $request, int $id): JsonResponse
    {
        return ApiResponse::success($this->family->list($this->client($request), $id), 'Family loaded.');
    }

    public function familyMember(Request $request, int $memberId): JsonResponse
    {
        return ApiResponse::success(['member' => $this->family->show($this->client($request), $memberId)], 'Family member loaded.');
    }

    public function addFamily(Request $request, int $id): JsonResponse
    {
        return ApiResponse::created(['member' => $this->family->create($this->client($request), $id, $request->all())], 'Family member added.');
    }

    public function updateFamily(Request $request, int $memberId): JsonResponse
    {
        return ApiResponse::success(['member' => $this->family->update($this->client($request), $memberId, $request->all())], 'Family member updated.');
    }

    public function deleteFamily(Request $request, int $memberId): JsonResponse
    {
        return ApiResponse::success($this->family->delete($this->client($request), $memberId), 'Family member deleted.');
    }

    public function reorderFamily(Request $request, int $id): JsonResponse
    {
        return ApiResponse::success($this->family->reorder($this->client($request), $id, $request->input('ids')), 'Family order saved.');
    }

    public function familySettings(Request $request, int $id): JsonResponse
    {
        return ApiResponse::success($this->family->viewSettings($this->client($request), $id), 'Family settings loaded.');
    }

    public function updateFamilySettings(Request $request, int $id): JsonResponse
    {
        return ApiResponse::success($this->family->updateSettings($this->client($request), $id, $request->all()), 'Family settings saved.');
    }

    private function client(Request $request): Client
    {
        /** @var Client */
        return $request->user();
    }
}
