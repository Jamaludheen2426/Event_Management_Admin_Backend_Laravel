<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Http\Controllers;

use App\Core\Http\ApiResponse;
use App\Modules\Messaging\Actions\VendorNewsletters;
use App\Modules\Vendors\Models\VendorUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * /api/v1/vendors/newsletter/* (v1 vendorNewsletter.controller).
 */
final class NewsletterController
{
    public function __construct(private readonly VendorNewsletters $newsletters) {}

    public function subscribers(): JsonResponse
    {
        return ApiResponse::success($this->newsletters->clients(true), 'Subscribers fetched');
    }

    public function unsubscribers(): JsonResponse
    {
        return ApiResponse::success($this->newsletters->clients(false), 'Unsubscribers fetched');
    }

    public function toggle(int $id): JsonResponse
    {
        return ApiResponse::success($this->newsletters->toggle($id), 'Subscription toggled');
    }

    public function bulk(Request $request): JsonResponse
    {
        return ApiResponse::success($this->newsletters->moveAll($request->input('from'), $request->input('to')), 'Bulk update successful');
    }

    public function bulkIds(Request $request): JsonResponse
    {
        $ids = $request->input('ids');
        $ids = array_values(array_map('intval', array_filter(is_array($ids) ? $ids : [], 'is_numeric')));

        return ApiResponse::success($this->newsletters->setMany($ids, $request->input('client_type')), 'Bulk update successful');
    }

    public function sends(): JsonResponse
    {
        return ApiResponse::success($this->newsletters->sends(), 'Newsletter sends fetched');
    }

    public function log(Request $request): JsonResponse
    {
        return ApiResponse::success($this->newsletters->log($request->query('newsletter_id')), 'Sent logs fetched');
    }

    public function send(Request $request): JsonResponse
    {
        /** @var VendorUser $sender */
        $sender = $request->user();
        $result = $this->newsletters->send($sender, $request->all());

        return ApiResponse::success($result, "Newsletter sent to {$result['count']} recipients");
    }
}
