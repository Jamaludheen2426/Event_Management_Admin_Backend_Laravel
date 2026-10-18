<?php

declare(strict_types=1);

namespace App\Modules\Platform\Http\Controllers;

use App\Core\Activity\ActivityLogger;
use App\Core\Exceptions\ApiException;
use App\Core\Http\ApiResponse;
use App\Core\Tenancy\Tenancy;
use App\Modules\Platform\Http\Resources\V1\PluginResource;
use App\Modules\Platform\Models\Plugin;
use App\Modules\Platform\Models\Setting;
use App\Modules\Platform\Support\PluginCatalogue;
use App\Modules\Platform\Support\PluginUsage;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;

/** /api/v1/plugins */
final class PluginController
{
    public function __construct(
        private readonly PluginUsage $usage,
        private readonly ActivityLogger $activity,
    ) {}

    /** { plugins: [...], grouped: { category: [...] } } in v1's category order. */
    public function index(): JsonResponse
    {
        $plugins = $this->plugins();
        $rows = $plugins->map(fn (Plugin $p): PluginResource => $this->present($p))->values();

        $grouped = [];
        foreach (array_unique([...PluginCatalogue::CATEGORY_ORDER, ...$plugins->pluck('category')->all()]) as $category) {
            $inCategory = $rows->filter(static fn (PluginResource $r): bool => $r->resource->category === $category)->values();
            if ($inCategory->isNotEmpty()) {
                $grouped[$category] = $inCategory;
            }
        }

        return ApiResponse::success(['plugins' => $rows, 'grouped' => $grouped]);
    }

    public function show(string $slug): JsonResponse
    {
        $plugin = $this->find($slug);
        $config = $plugin->config_group === null ? [] : Setting::query()
            ->where('group', $plugin->config_group)
            ->get()
            ->map(static fn (Setting $s): array => ['key' => $s->key, 'value' => $s->value, 'type' => $s->type, 'description' => $s->getAttribute('description')])
            ->values();

        return ApiResponse::success(['plugin' => $this->present($plugin), 'config' => $config]);
    }

    public function toggle(string $slug): JsonResponse
    {
        $plugin = $this->find($slug);
        $was = $plugin->is_active;
        $plugin->forceFill(['is_active' => ! $was])->save();

        $state = $plugin->is_active ? 'enabled' : 'disabled';
        $this->activity->log('plugins', 'toggle', "Plugin \"{$plugin->name}\" {$state}", $plugin, ['is_active' => (int) $was], ['is_active' => (int) $plugin->is_active]);

        return ApiResponse::success(['plugin' => $this->present($plugin)], "Plugin {$state} successfully");
    }

    /** @return Collection<int, Plugin> the company's plugin rows, created on first use */
    private function plugins(): Collection
    {
        $companyId = Tenancy::companyId();
        $now = now();
        Plugin::query()->insertOrIgnore(array_map(static fn (array $p): array => [
            ...$p, 'company_id' => $companyId, 'is_active' => false, 'created_at' => $now, 'updated_at' => $now,
        ], PluginCatalogue::PLUGINS));

        return Plugin::query()->whereIn('slug', PluginCatalogue::slugs())->orderBy('category')->orderBy('name')->get();
    }

    private function find(string $slug): Plugin
    {
        if (! in_array($slug, PluginCatalogue::slugs(), true)) {
            throw ApiException::notFound('Plugin not found');
        }

        return $this->plugins()->firstWhere('slug', $slug) ?? throw ApiException::notFound('Plugin not found');
    }

    private function present(Plugin $plugin): PluginResource
    {
        return new PluginResource($plugin, $this->usage->count($plugin->slug, $plugin->company_id));
    }
}
