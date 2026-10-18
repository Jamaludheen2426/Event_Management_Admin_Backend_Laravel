<?php

declare(strict_types=1);

namespace App\Modules\Platform\Http\Controllers;

use App\Core\Approvals\Approvals;
use App\Core\Approvals\PendingApprovals;
use App\Core\Http\ApiResponse;
use App\Modules\Platform\Actions\Reference\LanguageWriter;
use App\Modules\Platform\Http\Resources\V1\LanguageResource;
use App\Modules\Platform\Models\Language;
use App\Modules\Platform\Queries\ReferenceListQueries;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** /api/v1/languages */
final class LanguageController
{
    public function __construct(
        private readonly LanguageWriter $writer,
        private readonly Approvals $approvals,
    ) {}

    /** GET /languages/active — no login (language pickers). */
    public function active(): JsonResponse
    {
        $languages = Language::query()->where('is_active', true)->where('approval_status', 'approved')
            ->orderByDesc('is_default')->orderBy('name')
            ->get(['id', 'name', 'code', 'native_name', 'direction', 'is_default']);

        return ApiResponse::success(['languages' => $languages]);
    }

    public function index(Request $request): JsonResponse
    {
        return ApiResponse::paginated(PendingApprovals::annotate(ReferenceListQueries::languages()->paginate($request), LanguageResource::class, 'languages', 'language'));
    }

    public function show(int $id): JsonResponse
    {
        return ApiResponse::success(['language' => new LanguageResource($this->writer->find($id))]);
    }

    public function store(Request $request): JsonResponse
    {
        $input = $request->validate($this->rules(true));

        return $this->approvals->guard($this->actor($request), 'languages', 'create', 'language', null, $input,
            fn (): JsonResponse => ApiResponse::created(['language' => new LanguageResource($this->writer->create($input, $this->actor($request)))], 'Language created successfully'));
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $language = $this->writer->find($id);
        $input = $request->validate($this->rules(false));

        return $this->approvals->guard($this->actor($request), 'languages', 'edit', 'language', $language->id, $input,
            fn (): JsonResponse => ApiResponse::success(['language' => new LanguageResource($this->writer->update($language, $input, $this->actor($request)))], 'Language updated successfully'));
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $language = $this->writer->find($id);

        return $this->approvals->guard($this->actor($request), 'languages', 'delete', 'language', $language->id, [],
            function () use ($language, $request): JsonResponse {
                $this->writer->delete($language, $this->actor($request));

                return ApiResponse::success(null, 'Language deleted successfully');
            });
    }

    public function setDefault(Request $request, int $id): JsonResponse
    {
        $language = $this->writer->setDefault($this->writer->find($id), $this->actor($request));

        return ApiResponse::success(['language' => new LanguageResource($language)], 'Default language updated successfully');
    }

    /** @return array<string, list<string>> */
    private function rules(bool $creating): array
    {
        $required = $creating ? 'required' : 'sometimes';

        return [
            'name' => [$required, 'string', 'max:100'],
            'code' => [$required, 'string', 'max:10'],
            'native_name' => ['sometimes', 'nullable', 'string', 'max:100'],
            'direction' => ['sometimes', 'in:ltr,rtl'],
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
