<?php

declare(strict_types=1);

namespace App\Modules\Platform\Http\Controllers;

use App\Core\Approvals\Approvals;
use App\Core\Approvals\PendingApprovals;
use App\Core\Exceptions\ApiException;
use App\Core\Http\ApiResponse;
use App\Modules\Platform\Actions\Translations\UiTranslations;
use App\Modules\Platform\Http\Resources\V1\TranslationKeyResource;
use App\Modules\Platform\Models\Language;
use App\Modules\Platform\Models\Translation;
use App\Modules\Platform\Models\TranslationKey;
use App\Modules\Platform\Queries\TranslationKeyListQuery;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** /api/v1/translation-keys */
final class TranslationKeyController
{
    public function __construct(
        private readonly UiTranslations $translations,
        private readonly Approvals $approvals,
    ) {}

    public function index(Request $request, TranslationKeyListQuery $list): JsonResponse
    {
        return ApiResponse::paginated(PendingApprovals::annotate($list->paginate($request), TranslationKeyResource::class, 'translations', 'translation_key'));
    }

    public function show(int $id): JsonResponse
    {
        return ApiResponse::success(['key' => new TranslationKeyResource($this->find($id)->load('translations.language'))]);
    }

    public function store(Request $request): JsonResponse
    {
        /** @var array{key: string, default_value?: string|null, description?: string|null, group?: string|null} $input */
        $input = $request->validate($this->rules(true));
        $actor = $this->actor($request);
        $auto = $request->input('auto_translate') !== false;

        return $this->approvals->guard($actor, 'translations', 'create', 'translation_key', null, $input,
            fn (): JsonResponse => ApiResponse::created(
                ['key' => new TranslationKeyResource($this->translations->createKey($input, (int) $actor->getKey(), $auto)->load('translations.language'))],
                'Translation key created successfully',
            ));
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $key = $this->find($id);
        $input = $request->validate($this->rules(false));
        $actor = $this->actor($request);

        return $this->approvals->guard($actor, 'translations', 'edit', 'translation_key', $key->id, $input,
            fn (): JsonResponse => ApiResponse::success(
                ['key' => new TranslationKeyResource($this->translations->updateKey($key, $input, (int) $actor->getKey()))],
                'Translation key updated successfully',
            ));
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $key = $this->find($id);

        return $this->approvals->guard($this->actor($request), 'translations', 'delete', 'translation_key', $key->id, [],
            function () use ($key): JsonResponse {
                $this->translations->deleteKey($key);

                return ApiResponse::success(null, 'Translation key deleted successfully');
            });
    }

    public function values(int $id): JsonResponse
    {
        $key = $this->find($id)->load('translations.language');

        return ApiResponse::success(['translations' => (new TranslationKeyResource($key))->toArray(request())['translations']]);
    }

    public function updateValues(Request $request, int $id): JsonResponse
    {
        $key = $this->find($id);
        /** @var array{translations: list<array{language_id: int, value: string|null}>} $input */
        $input = $request->validate([
            'translations' => ['required', 'array'],
            'translations.*.language_id' => ['required', 'integer', 'exists:languages,id'],
            'translations.*.value' => ['nullable', 'string'],
        ]);
        $actor = $this->actor($request);

        return $this->approvals->guard($actor, 'translations', 'edit', 'translation_key', $key->id, $input,
            fn (): JsonResponse => ApiResponse::success(
                ['translations' => array_map(
                    static fn (Translation $t): array => TranslationKeyResource::translation($t),
                    $this->translations->setValues($key, $input['translations'], (int) $actor->getKey()),
                )],
                'Translations updated successfully',
            ));
    }

    public function retranslate(Request $request, int $id): JsonResponse
    {
        $language = Language::query()->find((int) $request->input('language_id')) ?? throw ApiException::notFound('Language not found');
        $translation = $this->translations->retranslate($this->find($id), $language, (int) $this->actor($request)->getKey());

        return ApiResponse::success(['translation' => TranslationKeyResource::translation($translation)], 'Translation updated successfully');
    }

    public function retranslateAll(Request $request, int $id): JsonResponse
    {
        $rows = $this->translations->translateToAll($this->find($id), (int) $this->actor($request)->getKey());

        return ApiResponse::success(['translations' => array_map(
            static fn (Translation $t): array => TranslationKeyResource::translation($t), $rows,
        )], 'Translations updated successfully');
    }

    public function bulkImport(Request $request): JsonResponse
    {
        $keys = $request->input('keys');
        if (! is_array($keys)) {
            throw ApiException::badRequest('keys array is required');
        }
        $actor = $this->actor($request);
        $payload = ['keys' => $keys];

        // A bulk import needing approval is parked as one request with no row (v1 shape).
        return $this->approvals->guard($actor, 'translations', 'create', 'translation_key', null, $payload,
            fn (): JsonResponse => ApiResponse::success(
                $this->translations->bulkImport(array_values(array_filter($keys, 'is_array')), (int) $actor->getKey(), $request->input('auto_translate', true) !== false),
                'Import completed',
            ));
    }

    /** @return array<string, list<string>> */
    private function rules(bool $creating): array
    {
        return [
            'key' => [$creating ? 'required' : 'sometimes', 'string', 'max:255', 'regex:/^[A-Za-z0-9_.\-]+$/'],
            'default_value' => ['sometimes', 'nullable', 'string'],
            'description' => ['sometimes', 'nullable', 'string', 'max:500'],
            'group' => ['sometimes', 'nullable', 'string', 'max:50'],
        ];
    }

    private function find(int $id): TranslationKey
    {
        return TranslationKey::query()->find($id) ?? throw ApiException::notFound('Translation key not found');
    }

    private function actor(Request $request): Model
    {
        /** @var Model */
        return $request->user();
    }
}
