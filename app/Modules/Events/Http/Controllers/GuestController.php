<?php

declare(strict_types=1);

namespace App\Modules\Events\Http\Controllers;

use App\Core\Exceptions\ApiException;
use App\Core\Http\ApiResponse;
use App\Core\Queries\CallbackListQuery;
use App\Modules\Clients\Models\Client;
use App\Modules\Events\Actions\GuestBook;
use App\Modules\Events\Actions\GuestGroups;
use App\Modules\Events\Actions\GuestTransfer;
use App\Modules\Events\Models\Guest;
use App\Modules\Events\Models\GuestGroup;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * /api/v1/client/guests/* — the client's phone book, its groups, CSV import
 * and export (v1 clientGuest.controller).
 */
final class GuestController
{
    public function __construct(
        private readonly GuestBook $book,
        private readonly GuestGroups $groups,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $book = $this->book;
        $page = (new CallbackListQuery(
            $this->book->query($this->client($request)),
            [],
            ['created_at'],
            false,
            static fn (Builder $q) => $book->filter($q, $request),
        ))->paginate($this->listRequest($request, 8, 200))->through(fn (Guest $g): array => $this->book->present($g));

        return ApiResponse::paginated($page, message: 'Guests retrieved');
    }

    public function stats(Request $request): JsonResponse
    {
        return ApiResponse::success($this->book->stats($this->client($request)), 'Guest stats retrieved');
    }

    public function capacity(Request $request): JsonResponse
    {
        return ApiResponse::success($this->book->capacity($this->client($request)), 'Guest capacity retrieved');
    }

    public function formOptions(Request $request): JsonResponse
    {
        return ApiResponse::success($this->book->formOptions($this->client($request), $request->query('event_id')), 'Guest form options retrieved');
    }

    public function show(Request $request, int $id): JsonResponse
    {
        return ApiResponse::success(['guest' => $this->book->present($this->book->find($this->client($request), $id))], 'Guest retrieved');
    }

    public function store(Request $request): JsonResponse
    {
        return ApiResponse::created(['guest' => $this->book->present($this->book->create($this->client($request), $request->all()))], 'Guest added successfully');
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $client = $this->client($request);
        $guest = $this->book->update($client, $this->book->find($client, $id), $request->all());

        return ApiResponse::success(['guest' => $this->book->present($guest)], 'Guest updated successfully');
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $this->book->find($this->client($request), $id)->delete();

        return ApiResponse::success(null, 'Guest removed');
    }

    public function bulk(Request $request): JsonResponse
    {
        $result = $this->book->bulk($this->client($request), $request->input('guest_ids'), $request->input('action'), $request->input('value'));

        return ApiResponse::success($result, "{$result['affected']} guest(s) updated");
    }

    public function photo(Request $request): JsonResponse
    {
        $file = $request->file('file');

        return ApiResponse::success($this->book->uploadPhoto($this->client($request), $file instanceof UploadedFile ? $file : null), 'Photo uploaded.');
    }

    // ---- import / export --------------------------------------------------------

    public function previewImport(Request $request, GuestTransfer $transfer): JsonResponse
    {
        return ApiResponse::success($transfer->preview($this->client($request), $this->content($request)), 'File analysed');
    }

    public function import(Request $request, GuestTransfer $transfer): JsonResponse
    {
        $result = $transfer->import($this->client($request), $this->content($request), $request->boolean('create_groups'));

        return ApiResponse::success($result, "{$result['imported']} guest(s) imported");
    }

    public function export(Request $request, GuestTransfer $transfer): Response
    {
        return $this->csv($transfer->export($this->client($request), $request));
    }

    public function sample(Request $request, GuestTransfer $transfer): Response
    {
        return $this->csv($transfer->sample($this->client($request)));
    }

    // ---- groups -----------------------------------------------------------------

    public function groups(Request $request): JsonResponse
    {
        $search = trim((string) $request->query('search', ''));
        $visibility = mb_strtolower((string) $request->query('visibility', ''));
        $query = $this->groups->query($this->client($request))
            ->when(in_array($visibility, ['private', 'public'], true), static fn ($q) => $q->where('visibility', $visibility))
            ->when($search !== '', static fn ($q) => $q->where(static fn ($w) => $w->where('name', 'like', "%{$search}%")->orWhere('description', 'like', "%{$search}%")));
        $limit = min(100, max(1, (int) $request->query('limit', '8')));
        $page = $query->paginate($limit, ['*'], 'page', max(1, (int) $request->query('page', '1')));
        $rows = $this->groups->withCounts($page->items());

        return ApiResponse::paginated(new LengthAwarePaginator($rows, $page->total(), $limit, $page->currentPage()), message: 'Groups retrieved');
    }

    public function allGroups(Request $request): JsonResponse
    {
        return ApiResponse::success(['groups' => $this->groups->withCounts($this->groups->all($this->client($request)))], 'Groups retrieved');
    }

    public function groupStats(Request $request): JsonResponse
    {
        return ApiResponse::success($this->groups->stats($this->client($request)), 'Group stats retrieved');
    }

    public function showGroup(Request $request, int $id): JsonResponse
    {
        return ApiResponse::success(['group' => $this->presentGroup($this->groups->find($this->client($request), $id))], 'Group retrieved');
    }

    public function storeGroup(Request $request): JsonResponse
    {
        return ApiResponse::created(['group' => $this->presentGroup($this->groups->create($this->client($request), $request->all()))], 'Group created successfully');
    }

    public function updateGroup(Request $request, int $id): JsonResponse
    {
        $client = $this->client($request);
        $group = $this->groups->update($client, $this->groups->find($client, $id), $request->all());

        return ApiResponse::success(['group' => $this->presentGroup($group)], 'Group updated successfully');
    }

    public function destroyGroup(Request $request, int $id): JsonResponse
    {
        $client = $this->client($request);
        $result = $this->groups->delete($client, $this->groups->find($client, $id));

        return ApiResponse::success($result, $result['ungrouped_guests'] > 0 ? "Group deleted. {$result['ungrouped_guests']} guest(s) are now ungrouped." : 'Group deleted.');
    }

    /** @return array<string, mixed> */
    private function presentGroup(GuestGroup $group): array
    {
        return $this->groups->withCounts([$group])[0];
    }

    /** @param array{filename: string, content: string} $file */
    private function csv(array $file): Response
    {
        return response($file['content'], 200, [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="'.$file['filename'].'"',
        ]);
    }

    private function content(Request $request): string
    {
        $content = $request->input('content');

        return is_string($content) && $content !== '' ? $content : throw ApiException::badRequest('No file contents received.');
    }

    /** A copy of the request with v1's page size defaults (the list query reads page/limit). */
    private function listRequest(Request $request, int $default, int $max): Request
    {
        $limit = min($max, max(1, (int) $request->query('limit', (string) $default)));

        return $request->duplicate([...$request->query(), 'limit' => $limit]);
    }

    private function client(Request $request): Client
    {
        /** @var Client */
        return $request->user();
    }
}
