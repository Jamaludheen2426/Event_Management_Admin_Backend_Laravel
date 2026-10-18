<?php

declare(strict_types=1);

namespace App\Modules\Clients\Http\Controllers;

use App\Core\Http\ApiResponse;
use App\Core\Queries\CallbackListQuery;
use App\Modules\Clients\Actions\NewsletterSubscribers;
use App\Modules\Clients\Models\NewsletterSubscriber;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * /api/v1/vendors/subscribers — the vendor's e-mail newsletter list.
 */
final class SubscriberController
{
    public function __construct(private readonly NewsletterSubscribers $subscribers) {}

    public function index(Request $request): JsonResponse
    {
        $page = (new CallbackListQuery(NewsletterSubscriber::query(), ['email'], ['created_at', 'email', 'is_active']))->paginate($request);

        return ApiResponse::nestedPage($page, 'Subscribers retrieved successfully');
    }

    public function store(Request $request): JsonResponse
    {
        return ApiResponse::success($this->subscribers->create($request->all()), 'Subscriber added successfully', 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        return ApiResponse::success($this->subscribers->update($this->subscribers->find($id), $request->all()), 'Subscriber updated successfully');
    }

    public function destroy(int $id): JsonResponse
    {
        $this->subscribers->find($id)->delete();

        return ApiResponse::success(null, 'Subscriber deleted successfully');
    }
}
