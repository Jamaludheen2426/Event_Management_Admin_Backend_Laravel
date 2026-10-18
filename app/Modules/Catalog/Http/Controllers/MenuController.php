<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Controllers;

use App\Core\Approvals\Approvals;
use App\Core\Crud\RecordWriter;
use App\Core\Http\ApiResponse;
use App\Core\Http\Controllers\RecordController;
use App\Core\Http\Resources\RowResource;
use App\Core\Http\Resources\V1Resource;
use App\Core\Queries\CallbackListQuery;
use App\Core\Queries\ListQuery;
use App\Modules\Catalog\Actions\MenuWriter;
use App\Modules\Catalog\Models\Menu;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * /api/v1/menus — vendor-portal menus that plan types unlock.
 *
 * @extends RecordController<Menu>
 */
final class MenuController extends RecordController
{
    protected string $key = 'menu';

    protected string $module = 'menus';

    protected string $type = 'menus';

    protected array $messages = [
        'created' => 'Menu created successfully',
        'updated' => 'Menu updated successfully',
        'status' => 'Menu status updated successfully',
        'deleted' => 'Menu deleted successfully',
    ];

    public function __construct(Approvals $approvals, private readonly MenuWriter $writer)
    {
        parent::__construct($approvals);
    }

    public function displayStatus(Request $request, int $id): JsonResponse
    {
        $menu = $this->writer->update($this->writer->find($id), ['display_status' => $request->input('display_status')], $this->actor($request));

        return ApiResponse::success(['menu' => new RowResource($menu)], 'Menu display status updated successfully');
    }

    protected function writer(): RecordWriter
    {
        return $this->writer;
    }

    protected function listQuery(Request $request): ListQuery
    {
        return new CallbackListQuery(Menu::query(), ['name'], ['created_at', 'name'], true);
    }

    protected function present(Model $model): V1Resource
    {
        return new RowResource($model);
    }
}
