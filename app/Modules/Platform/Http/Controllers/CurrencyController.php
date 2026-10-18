<?php

declare(strict_types=1);

namespace App\Modules\Platform\Http\Controllers;

use App\Core\Approvals\Approvals;
use App\Core\Approvals\PendingApprovals;
use App\Core\Http\ApiResponse;
use App\Modules\Platform\Actions\Reference\CurrencyWriter;
use App\Modules\Platform\Http\Resources\V1\CurrencyResource;
use App\Modules\Platform\Models\Currency;
use App\Modules\Platform\Queries\ReferenceListQueries;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** /api/v1/currencies */
final class CurrencyController
{
    public function __construct(
        private readonly CurrencyWriter $writer,
        private readonly Approvals $approvals,
    ) {}

    /** GET /currencies/active — no login. */
    public function active(Request $request): JsonResponse
    {
        $currencies = Currency::query()->where('is_active', true)->where('approval_status', 'approved')
            ->orderByDesc('is_default')->orderBy('name')->get();

        return ApiResponse::success(['currencies' => $currencies->map(static fn (Currency $c): array => [
            'id' => $c->id,
            'name' => $c->name,
            'code' => $c->code,
            'symbol' => $c->symbol,
            'exchange_rate' => number_format((float) $c->getAttribute('exchange_rate'), 4, '.', ''),
            'decimal_places' => $c->getAttribute('decimal_places'),
            'is_default' => $c->is_default,
        ])->values()]);
    }

    public function index(Request $request): JsonResponse
    {
        return ApiResponse::paginated(PendingApprovals::annotate(ReferenceListQueries::currencies()->paginate($request), CurrencyResource::class, 'currencies', 'currency'));
    }

    public function show(int $id): JsonResponse
    {
        return ApiResponse::success(['currency' => new CurrencyResource($this->writer->find($id))]);
    }

    public function store(Request $request): JsonResponse
    {
        $input = $request->validate($this->rules(true));

        return $this->approvals->guard($this->actor($request), 'currencies', 'create', 'currency', null, $input,
            fn (): JsonResponse => ApiResponse::created(['currency' => new CurrencyResource($this->writer->create($input, $this->actor($request)))], 'Currency created successfully'));
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $currency = $this->writer->find($id);
        $input = $request->validate($this->rules(false));

        return $this->approvals->guard($this->actor($request), 'currencies', 'edit', 'currency', $currency->id, $input,
            fn (): JsonResponse => ApiResponse::success(['currency' => new CurrencyResource($this->writer->update($currency, $input, $this->actor($request)))], 'Currency updated successfully'));
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $currency = $this->writer->find($id);

        return $this->approvals->guard($this->actor($request), 'currencies', 'delete', 'currency', $currency->id, [],
            function () use ($currency, $request): JsonResponse {
                $this->writer->delete($currency, $this->actor($request));

                return ApiResponse::success(null, 'Currency deleted successfully');
            });
    }

    public function setDefault(Request $request, int $id): JsonResponse
    {
        $currency = $this->writer->setDefault($this->writer->find($id), $this->actor($request));

        return ApiResponse::success(['currency' => new CurrencyResource($currency)], 'Default currency updated successfully');
    }

    /** @return array<string, list<string>> */
    private function rules(bool $creating): array
    {
        $required = $creating ? 'required' : 'sometimes';

        return [
            'name' => [$required, 'string', 'max:100'],
            'code' => [$required, 'string', 'size:3'],
            'symbol' => ['sometimes', 'nullable', 'string', 'max:10'],
            'exchange_rate' => ['sometimes', 'numeric', 'min:0'],
            'decimal_places' => ['sometimes', 'integer', 'min:0', 'max:8'],
            'decimal_separator' => ['sometimes', 'string', 'max:5'],
            'thousand_separator' => ['sometimes', 'nullable', 'string', 'max:5'],
            'symbol_position' => ['sometimes', 'in:before,after'],
            'space_between' => ['sometimes', 'boolean'],
            'is_default' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'in:0,1,2,true,false'],
        ];
    }

    private function actor(Request $request): Model
    {
        /** @var Model */
        return $request->user();
    }
}
