<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Controllers;

use App\Core\Approvals\Approvals;
use App\Core\Crud\RecordWriter;
use App\Core\Http\ApiResponse;
use App\Core\Http\Controllers\RecordController;
use App\Core\Http\Resources\V1Resource;
use App\Core\Queries\CallbackListQuery;
use App\Core\Queries\ListQuery;
use App\Modules\Catalog\Actions\PlanBadgeWriter;
use App\Modules\Catalog\Http\Resources\V1\PlanBadgeResource;
use App\Modules\Catalog\Models\PlanBadge;
use App\Modules\Platform\Actions\SettingWriter;
use App\Modules\Platform\Models\Setting;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * /api/v1/plan-badges, plus the module switches stored as settings
 * (group plan_badges: plan_badges_enabled, plan_badge_position).
 *
 * @extends RecordController<PlanBadge>
 */
final class PlanBadgeController extends RecordController
{
    protected string $key = 'planBadge';

    protected string $module = 'plan_badges';

    protected string $type = 'plan_badge';

    /** @var list<string> */
    protected array $detail = ['plans'];

    protected array $messages = [
        'created' => 'Badge created successfully',
        'updated' => 'Badge updated successfully',
        'status' => 'Badge status updated successfully',
        'deleted' => 'Badge deleted successfully',
    ];

    public function __construct(
        Approvals $approvals,
        private readonly PlanBadgeWriter $writer,
        private readonly SettingWriter $settings,
    ) {
        parent::__construct($approvals);
    }

    public function settings(): JsonResponse
    {
        return ApiResponse::success(['settings' => $this->currentSettings()]);
    }

    public function updateSettings(Request $request): JsonResponse
    {
        $values = [];
        if ($request->has('enabled')) {
            $values['plan_badges_enabled'] = $request->boolean('enabled') ? '1' : '0';
        }
        if ($request->has('position')) {
            $values['plan_badge_position'] = (string) $request->input('position');
        }
        if ($values !== []) {
            $this->settings->bulk($values, 'plan_badges', (int) $this->actor($request)->getKey());
        }

        return ApiResponse::success(['settings' => $this->currentSettings()], 'Badge settings updated successfully');
    }

    public function summary(): JsonResponse
    {
        $total = PlanBadge::query()->count();
        $active = PlanBadge::query()->where('is_active', 1)->count();

        return ApiResponse::success(['summary' => ['total' => $total, 'active' => $active, 'inactive' => $total - $active]]);
    }

    public function recommended(): JsonResponse
    {
        return ApiResponse::success(['recommended' => PlanBadgeWriter::RECOMMENDED]);
    }

    protected function writer(): RecordWriter
    {
        return $this->writer;
    }

    protected function listQuery(Request $request): ListQuery
    {
        return (new CallbackListQuery(PlanBadge::query()->with('plans'), ['text'], ['sort_order', 'text', 'created_at'], true))
            ->defaultOrder('sort_order');
    }

    protected function present(Model $model): V1Resource
    {
        return new PlanBadgeResource($model);
    }

    /** @return array{enabled: bool, position: string} */
    private function currentSettings(): array
    {
        $values = Setting::query()->where('group', 'plan_badges')->pluck('value', 'key');

        return [
            'enabled' => (string) ($values['plan_badges_enabled'] ?? '1') === '1',
            'position' => (string) ($values['plan_badge_position'] ?? 'top_right'),
        ];
    }
}
