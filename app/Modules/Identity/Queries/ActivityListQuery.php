<?php

declare(strict_types=1);

namespace App\Modules\Identity\Queries;

use App\Core\Activity\Activity;
use App\Core\Queries\ListQuery;
use App\Core\Tenancy\Tenancy;
use App\Modules\Identity\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * The current company's activity log. v1 filters: action, module, user_id,
 * start_date + end_date. v1 column names map: module = log_name,
 * action = event, user = causer.
 *
 * @extends ListQuery<Activity>
 */
final class ActivityListQuery extends ListQuery
{
    protected array $searchable = ['description'];

    protected array $sortable = ['created_at', 'id', 'event', 'log_name', 'causer_id'];

    protected array $sortAliases = ['action' => 'event', 'module' => 'log_name', 'user_id' => 'causer_id'];

    protected bool $filtersActive = false;

    private ?int $userId = null;

    private ?string $module = null;

    public function forUser(int $userId): self
    {
        $this->userId = $userId;

        return $this;
    }

    public function forModule(string $module): self
    {
        $this->module = $module;

        return $this;
    }

    protected function query(Request $request): Builder
    {
        return Activity::query()
            ->where('company_id', Tenancy::companyId())
            ->with('causer');
    }

    protected function filter(Builder $query, Request $request): void
    {
        $userId = $this->userId ?? (is_numeric($request->query('user_id')) ? (int) $request->query('user_id') : null);
        if ($userId !== null) {
            $query->where('causer_type', (new User)->getMorphClass())->where('causer_id', $userId);
        }

        $module = $this->module ?? $request->query('module');
        if (is_string($module) && $module !== '') {
            $query->where('log_name', $module);
        }

        $action = $request->query('action');
        if (is_string($action) && $action !== '') {
            $query->where('event', $action);
        }

        $from = $request->query('start_date');
        $to = $request->query('end_date');
        if (is_string($from) && is_string($to) && strtotime($from) !== false && strtotime($to) !== false) {
            $query->whereBetween('created_at', [date('Y-m-d H:i:s', (int) strtotime($from)), date('Y-m-d H:i:s', (int) strtotime($to))]);
        }
    }
}
