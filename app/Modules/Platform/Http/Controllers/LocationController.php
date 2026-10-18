<?php

declare(strict_types=1);

namespace App\Modules\Platform\Http\Controllers;

use App\Core\Approvals\Approvals;
use App\Core\Approvals\PendingApprovals;
use App\Core\Crud\RecordWriter;
use App\Core\Exceptions\ApiException;
use App\Core\Http\ApiResponse;
use App\Core\Http\Resources\V1Resource;
use App\Modules\Platform\Actions\Reference\CityWriter;
use App\Modules\Platform\Actions\Reference\CountryWriter;
use App\Modules\Platform\Actions\Reference\DistrictWriter;
use App\Modules\Platform\Actions\Reference\LocationImport;
use App\Modules\Platform\Actions\Reference\StateWriter;
use App\Modules\Platform\Http\Resources\V1\CityResource;
use App\Modules\Platform\Http\Resources\V1\CountryResource;
use App\Modules\Platform\Http\Resources\V1\DistrictResource;
use App\Modules\Platform\Http\Resources\V1\StateResource;
use App\Modules\Platform\Queries\ReferenceListQueries;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * /api/v1/locations — countries > states > districts > cities.
 * Lists need no login; writes need locations.* and go through approval.
 * Cities take and return their district as `city_id` (v1 name).
 */
final class LocationController
{
    /** level => [writer, resource, response key, label, validation rules] */
    private const LEVELS = [
        'country' => [CountryWriter::class, CountryResource::class, 'Country'],
        'state' => [StateWriter::class, StateResource::class, 'State'],
        'district' => [DistrictWriter::class, DistrictResource::class, 'District'],
        'city' => [CityWriter::class, CityResource::class, 'City'],
    ];

    public function __construct(private readonly Approvals $approvals) {}

    // ---- public lists -----------------------------------------------------

    public function countries(Request $request): JsonResponse
    {
        return $this->page(ReferenceListQueries::countries()->paginate($request), CountryResource::class, 'country');
    }

    public function states(Request $request, ?int $countryId = null): JsonResponse
    {
        $countryId ??= is_numeric($request->query('country_id')) ? (int) $request->query('country_id') : null;

        return $this->page(ReferenceListQueries::states($countryId)->paginate($request), StateResource::class, 'state');
    }

    public function districts(Request $request, ?int $stateId = null): JsonResponse
    {
        $stateId ??= is_numeric($request->query('state_id')) ? (int) $request->query('state_id') : null;

        return $this->page(ReferenceListQueries::districts($stateId)->paginate($request), DistrictResource::class, 'district');
    }

    public function cities(Request $request, ?int $districtId = null): JsonResponse
    {
        $districtId ??= is_numeric($request->query('city_id')) ? (int) $request->query('city_id') : null;

        return $this->page(ReferenceListQueries::cities($districtId)->paginate($request), CityResource::class, 'city');
    }

    // ---- writes -----------------------------------------------------------

    public function store(Request $request): JsonResponse
    {
        $level = $this->levelOf($request);
        [$writer, $resource, $label] = $this->level($level);
        $input = $request->validate($this->rules($level, true));
        $actor = $this->actor($request);

        return $this->approvals->guard($actor, 'locations', 'create', $level, null, $input,
            fn (): JsonResponse => ApiResponse::created([$level => new $resource($this->fresh($level, $writer->create($input, $actor)))], "{$label} created successfully"));
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $level = $this->levelOf($request);
        [$writer, $resource, $label] = $this->level($level);
        $model = $writer->find($id);
        $input = $request->validate($this->rules($level, false));
        $actor = $this->actor($request);

        return $this->approvals->guard($actor, 'locations', 'edit', $level, $model->getKey(), $input,
            fn (): JsonResponse => ApiResponse::success([$level => new $resource($this->fresh($level, $writer->update($model, $input, $actor)))], "{$label} updated successfully"));
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $level = $this->levelOf($request);
        [$writer, , $label] = $this->level($level);
        $model = $writer->find($id);
        $actor = $this->actor($request);

        return $this->approvals->guard($actor, 'locations', 'delete', $level, $model->getKey(), [],
            function () use ($writer, $model, $actor, $label): JsonResponse {
                $writer->delete($model, $actor);

                return ApiResponse::success(null, "{$label} deleted successfully");
            });
    }

    public function bulk(Request $request, LocationImport $import): JsonResponse
    {
        $level = $this->levelOf($request);
        $rows = $request->input('rows');
        if (! is_array($rows) || $rows === []) {
            throw ApiException::badRequest('rows array is required');
        }
        $rows = array_values(array_filter($rows, 'is_array'));
        $userId = (int) $this->actor($request)->getKey();

        $result = match ($level) {
            'country' => $import->countries($rows, $userId),
            'state' => $import->states($rows, $userId),
            'district' => $import->districts($rows, $userId),
            'city' => $import->cities($rows, $userId),
            default => throw ApiException::notFound('Resource not found'),
        };

        $plural = ['country' => 'countries', 'state' => 'states', 'district' => 'districts', 'city' => 'cities'][$level];
        $message = "Imported {$result['imported']} {$plural}".(isset($result['skipped']) ? " ({$result['skipped']} skipped)" : '');

        return ApiResponse::success($result, $message);
    }

    // ---------------------------------------------------------------------

    /**
     * The level comes from the route's `level` default. It is read here, not
     * taken as a method argument: Laravel passes route parameters to controller
     * methods by position, with defaults last, so a `string $level` argument
     * would receive the {id} instead.
     */
    private function levelOf(Request $request): string
    {
        return (string) $request->route('level');
    }

    /**
     * @template TModel of Model
     *
     * @param  LengthAwarePaginator<int, TModel>  $page
     * @param  class-string<V1Resource>  $resource
     */
    private function page(LengthAwarePaginator $page, string $resource, string $type): JsonResponse
    {
        return ApiResponse::paginated(PendingApprovals::annotate($page, $resource, 'locations', $type));
    }

    /** @return array{0: RecordWriter<Model>, 1: class-string<V1Resource>, 2: string} */
    private function level(string $level): array
    {
        [$writer, $resource, $label] = self::LEVELS[$level] ?? throw ApiException::notFound('Resource not found');

        /** @var RecordWriter<Model> $instance */
        $instance = app($writer);

        return [$instance, $resource, $label];
    }

    private function fresh(string $level, Model $model): Model
    {
        return match ($level) {
            'state' => $model->load('country'),
            'district' => $model->load('state'),
            'city' => $model->load('district.state.country'),
            default => $model,
        };
    }

    /** @return array<string, list<string>> */
    private function rules(string $level, bool $creating): array
    {
        $required = $creating ? 'required' : 'sometimes';
        $common = [
            'name' => [$required, 'string', 'max:200'],
            'is_default' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'in:0,1,2,true,false'],
        ];

        return $common + match ($level) {
            'country' => [
                'code' => [$required, 'string', 'max:3'],
                'flag' => ['sometimes', 'nullable', 'string', 'max:500'],
                'nationality' => ['sometimes', 'nullable', 'string', 'max:100'],
                'sort_order' => ['sometimes', 'nullable', 'integer'],
            ],
            'state' => [
                'country_id' => [$required, 'integer'],
                'code' => ['sometimes', 'nullable', 'string', 'max:10'],
                'slug' => ['sometimes', 'nullable', 'string', 'max:100'],
                'sort_order' => ['sometimes', 'nullable', 'integer'],
            ],
            'district' => [
                'state_id' => [$required, 'integer'],
                'slug' => ['sometimes', 'nullable', 'string', 'max:100'],
                'sort_order' => ['sometimes', 'nullable', 'integer'],
            ],
            default => [
                'city_id' => [$required, 'integer'],
                'pincode' => ['sometimes', 'nullable', 'string', 'max:20'],
            ],
        };
    }

    private function actor(Request $request): Model
    {
        /** @var Model */
        return $request->user();
    }
}
