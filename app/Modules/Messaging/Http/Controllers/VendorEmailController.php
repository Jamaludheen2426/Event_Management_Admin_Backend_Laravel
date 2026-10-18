<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Http\Controllers;

use App\Core\Http\ApiResponse;
use App\Modules\Messaging\Actions\VendorEmails;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * /api/v1/vendors/email-categories and /vendors/email-templates
 * (v1 vendorEmailCategory + vendorEmailTemplate controllers).
 */
final class VendorEmailController
{
    public function __construct(private readonly VendorEmails $emails) {}

    public function categories(Request $request): JsonResponse
    {
        return ApiResponse::paginated($this->emails->categories($request->query()));
    }

    public function category(int $id): JsonResponse
    {
        return ApiResponse::success(['category' => $this->emails->category($this->emails->findCategory($id))]);
    }

    public function storeCategory(Request $request): JsonResponse
    {
        $category = $this->emails->saveCategory(null, $request->only(['name', 'description', 'sort_order', 'is_active']));

        return ApiResponse::created(['category' => $this->emails->category($category)], 'Email category created successfully');
    }

    public function updateCategory(Request $request, int $id): JsonResponse
    {
        $category = $this->emails->saveCategory($this->emails->findCategory($id), $request->only(['name', 'description', 'sort_order', 'is_active']));

        return ApiResponse::success(['category' => $this->emails->category($category)], 'Email category updated successfully');
    }

    public function destroyCategory(int $id): JsonResponse
    {
        $this->emails->findCategory($id)->delete();

        return ApiResponse::success(null, 'Email category deleted successfully');
    }

    public function templates(Request $request): JsonResponse
    {
        return ApiResponse::paginated($this->emails->templates($request->query()));
    }

    public function template(int $id): JsonResponse
    {
        return ApiResponse::success(['template' => $this->emails->template($this->emails->findTemplate($id))]);
    }

    public function storeTemplate(Request $request): JsonResponse
    {
        $template = $this->emails->saveTemplate(null, $request->only(['name', 'category_id', 'description', 'is_active']));

        return ApiResponse::created(['template' => $this->emails->template($template)], 'Email template created successfully');
    }

    public function updateTemplate(Request $request, int $id): JsonResponse
    {
        $template = $this->emails->saveTemplate($this->emails->findTemplate($id), $request->only(['name', 'category_id', 'description', 'is_active']));

        return ApiResponse::success(['template' => $this->emails->template($template)], 'Email template updated successfully');
    }

    public function toggleTemplate(int $id): JsonResponse
    {
        return ApiResponse::success(['template' => $this->emails->template($this->emails->toggleTemplate($this->emails->findTemplate($id)))], 'Status updated successfully');
    }

    public function destroyTemplate(int $id): JsonResponse
    {
        $this->emails->findTemplate($id)->delete();

        return ApiResponse::success(null, 'Email template deleted successfully');
    }
}
