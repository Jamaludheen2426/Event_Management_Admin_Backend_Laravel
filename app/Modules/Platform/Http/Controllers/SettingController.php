<?php

declare(strict_types=1);

namespace App\Modules\Platform\Http\Controllers;

use App\Core\Approvals\Approvals;
use App\Core\Exceptions\ApiException;
use App\Core\Http\ApiResponse;
use App\Core\Tenancy\Tenancy;
use App\Modules\Platform\Actions\SettingWriter;
use App\Modules\Platform\Http\Resources\V1\SettingResource;
use App\Modules\Platform\Models\Company;
use App\Modules\Platform\Models\Setting;
use App\Modules\Platform\Queries\SettingListQuery;
use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/** /api/v1/settings */
final class SettingController
{
    public function __construct(
        private readonly SettingWriter $writer,
        private readonly Approvals $approvals,
    ) {}

    /**
     * GET /settings/public — no login. Branding and the public groups of the
     * house company; secret and internal keys are never included.
     */
    public function public(): JsonResponse
    {
        $companyId = (int) config('tenancy.house_company_id');

        [$rows, $company] = Tenancy::bypass(static fn (): array => [
            Setting::query()
                ->where('company_id', $companyId)
                ->whereIn('group', (array) config('settings.public_groups'))
                ->where('is_active', true)
                ->get(),
            Company::query()->find($companyId),
        ]);

        $settings = [];
        foreach ($rows as $row) {
            if (Setting::isPrivate($row->key)) {
                continue;
            }
            $settings[$row->key] = $row->type === 'json' && $row->value !== null
                ? json_decode($row->value, true)
                : $row->value;
        }

        return ApiResponse::success([
            'settings' => $settings,
            'company' => $company === null ? null : [
                'name' => $company->name,
                'logo' => $company->getAttribute('logo'),
                'favicon' => $company->getAttribute('favicon'),
            ],
        ]);
    }

    public function index(Request $request, SettingListQuery $list): JsonResponse
    {
        return ApiResponse::paginated($list->paginate($request), SettingResource::class);
    }

    public function group(string $group): JsonResponse
    {
        $settings = Setting::query()->where('group', $group)->where('is_active', true)->orderBy('key')->get();

        return ApiResponse::success(['settings' => SettingResource::collection($settings)]);
    }

    public function show(string $key): JsonResponse
    {
        $setting = Setting::query()->where('key', $key)->first() ?? throw ApiException::notFound('Setting not found');

        return ApiResponse::success(['setting' => new SettingResource($setting)]);
    }

    public function update(Request $request, string $key): JsonResponse
    {
        $actor = $this->actor($request);
        $payload = ['group' => 'general', $key => $request->input('value')];

        return $this->approvals->guard($actor, 'settings', 'edit', 'setting', null, $payload,
            fn (): JsonResponse => ApiResponse::success(
                ['setting' => new SettingResource($this->writer->update($key, $request->input('value'), (int) $actor->getKey()))],
                'Setting updated successfully',
            ));
    }

    /** POST /settings/bulk { group, key: value, ... } */
    public function bulk(Request $request): JsonResponse
    {
        $actor = $this->actor($request);
        /** @var array<string, mixed> $values */
        $values = $request->except(['group']);
        $group = is_string($request->input('group')) && $request->input('group') !== '' ? (string) $request->input('group') : 'general';

        if ($values === []) {
            throw ApiException::badRequest('No settings to save');
        }
        foreach (array_keys($values) as $key) {
            if (! preg_match('/^[A-Za-z0-9_.\-]{1,100}$/', (string) $key)) {
                throw ApiException::badRequest("Invalid setting key: {$key}");
            }
        }

        // v1: besides settings.edit, a settings permission naming the group
        // ("general_settings.edit"). Developer and super admin pass.
        if (! $this->mayEditGroup($actor, $group)) {
            throw ApiException::forbidden('Access denied. Insufficient permissions.');
        }

        return $this->approvals->guard($actor, 'settings', 'edit', 'setting', null, ['group' => $group, ...$values],
            fn (): JsonResponse => ApiResponse::success(
                ['settings' => SettingResource::collection($this->writer->bulk($values, $group, (int) $actor->getKey()))],
                'Settings updated successfully',
            ));
    }

    private function mayEditGroup(Model $actor, string $group): bool
    {
        if (! $actor instanceof Authorizable) {
            return false;
        }

        return Gate::forUser($actor)->allows("{$group}_settings.edit")
            || Gate::forUser($actor)->allows("{$group}.edit");
    }

    private function actor(Request $request): Model
    {
        /** @var Model */
        return $request->user();
    }
}
