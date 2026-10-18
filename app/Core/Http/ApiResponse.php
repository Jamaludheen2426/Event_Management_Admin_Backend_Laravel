<?php

declare(strict_types=1);

namespace App\Core\Http;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The v1 response envelope. Every endpoint answers through this class, so the
 * shape the six web apps and the Flutter app read stays identical to the Node API:
 *
 *   { success: true,  message, data, timestamp }                        one answer
 *   { success: true,  message, data: [...], pagination, timestamp }     a page of a list
 *   { success: false, message, errors?, timestamp }                     a failure
 *
 * Single records are wrapped by name, as v1 does: success(['eventCategory' => $resource]).
 */
final class ApiResponse
{
    private const JSON_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;

    public static function success(mixed $data = null, string $message = 'Success', int $status = 200): JsonResponse
    {
        return self::send(['success' => true, 'message' => $message, 'data' => $data], $status);
    }

    public static function created(mixed $data = null, string $message = 'Created successfully'): JsonResponse
    {
        return self::success($data, $message, 201);
    }

    public static function error(string $message = 'Error occurred', int $status = 500, mixed $errors = null): JsonResponse
    {
        $body = ['success' => false, 'message' => $message];
        if ($errors !== null) {
            $body['errors'] = $errors;
        }

        return self::send($body, $status);
    }

    /**
     * A page of a list, with the v1 pagination block.
     *
     * totalPages is ceil(total / limit), so an empty list reports 0 pages exactly
     * like the Node API did (Laravel's own lastPage() would say 1).
     *
     * @param  LengthAwarePaginator<array-key, mixed>  $page
     * @param  class-string<JsonResource>|null  $resource
     */
    public static function paginated(LengthAwarePaginator $page, ?string $resource = null, string $message = 'Success'): JsonResponse
    {
        $items = $resource !== null ? $resource::collection($page->items()) : $page->items();
        $limit = $page->perPage();
        $total = $page->total();
        $current = $page->currentPage();
        $totalPages = $limit > 0 ? (int) ceil($total / $limit) : 0;

        return self::send([
            'success' => true,
            'message' => $message,
            'data' => $items,
            'pagination' => [
                'page' => $current,
                'limit' => $limit,
                'totalItems' => $total,
                'totalPages' => $totalPages,
                'hasNextPage' => $current < $totalPages,
                'hasPrevPage' => $current > 1,
            ],
        ], 200);
    }

    /**
     * A page wrapped INSIDE data — { data: { data: [...], pagination } } — the
     * shape several v1 vendor endpoints answered with (they passed the whole
     * {data, pagination} result to success()). Kept where the screens read it.
     *
     * @param  LengthAwarePaginator<array-key, mixed>  $page
     */
    public static function nestedPage(LengthAwarePaginator $page, string $message = 'Success'): JsonResponse
    {
        $limit = $page->perPage();
        $total = $page->total();
        $current = $page->currentPage();
        $totalPages = $limit > 0 ? (int) ceil($total / $limit) : 0;

        return self::success([
            'data' => $page->items(),
            'pagination' => [
                'page' => $current,
                'limit' => $limit,
                'total' => $total,
                'totalItems' => $total,
                'totalPages' => $totalPages,
                'hasNextPage' => $current < $totalPages,
                'hasPrevPage' => $current > 1,
            ],
        ], $message);
    }

    /**
     * The approval workflow's "sent for approval" answer. v1 sends this one with
     * status 202 and without data or timestamp, so it is kept exactly that way.
     */
    public static function pendingApproval(int $approvalId, string $action): JsonResponse
    {
        return new JsonResponse([
            'success' => true,
            'status' => 'pending_approval',
            'message' => 'Your request has been sent for approval',
            'approval_id' => $approvalId,
            'action' => $action,
        ], 202, [], self::JSON_FLAGS);
    }

    /** The same text JavaScript's Date#toISOString() produces: millisecond precision, UTC, "Z". */
    public static function timestamp(): string
    {
        return now('UTC')->format('Y-m-d\TH:i:s.v\Z');
    }

    /** @param array<string, mixed> $body */
    private static function send(array $body, int $status): JsonResponse
    {
        $body['timestamp'] = self::timestamp();

        return new JsonResponse($body, $status, [], self::JSON_FLAGS);
    }
}
