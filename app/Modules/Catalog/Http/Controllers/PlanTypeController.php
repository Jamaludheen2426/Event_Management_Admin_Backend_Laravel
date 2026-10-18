<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Controllers;

use App\Core\Approvals\Approvals;
use App\Core\Crud\RecordWriter;
use App\Core\Http\Controllers\RecordController;
use App\Core\Http\Resources\V1Resource;
use App\Core\Queries\CallbackListQuery;
use App\Core\Queries\ListQuery;
use App\Modules\Catalog\Actions\PlanTypeWriter;
use App\Modules\Catalog\Http\Resources\V1\PlanTypeResource;
use App\Modules\Catalog\Models\PlanType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * /api/v1/plan-types — vendor subscription tiers.
 *
 * @extends RecordController<PlanType>
 */
final class PlanTypeController extends RecordController
{
    protected string $key = 'planType';

    protected string $module = 'plan_types';

    protected string $type = 'plan_type';

    /** @var list<string> */
    protected array $detail = ['menus'];

    protected array $messages = [
        'created' => 'Plan type created successfully',
        'updated' => 'Plan type updated successfully',
        'status' => 'Plan type status updated successfully',
        'deleted' => 'Plan type deleted successfully',
    ];

    public function __construct(Approvals $approvals, private readonly PlanTypeWriter $writer)
    {
        parent::__construct($approvals);
    }

    protected function writer(): RecordWriter
    {
        return $this->writer;
    }

    protected function listQuery(Request $request): ListQuery
    {
        return new CallbackListQuery(PlanType::query()->with('menus'), ['name'], ['created_at', 'name', 'price'], true);
    }

    protected function present(Model $model): V1Resource
    {
        return new PlanTypeResource($model);
    }
}
