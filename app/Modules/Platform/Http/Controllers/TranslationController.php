<?php

declare(strict_types=1);

namespace App\Modules\Platform\Http\Controllers;

use App\Core\Exceptions\ApiException;
use App\Core\Http\ApiResponse;
use App\Modules\Platform\Actions\Translations\UiTranslations;
use App\Modules\Platform\Http\Resources\V1\TranslationKeyResource;
use App\Modules\Platform\Models\Language;
use App\Modules\Platform\Models\MissingTranslationKey;
use App\Modules\Platform\Models\TranslationKey;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * /api/v1/translations — reading UI strings (no login), plus the
 * missing-key inbox and bulk machine translation (signed in).
 *
 * v1 left the missing-key list, create, ignore and delete without a permission
 * check (any signed-in admin); they now need translations.view / .manage.
 */
final class TranslationController
{
    public function __construct(private readonly UiTranslations $translations) {}

    // ---- public -------------------------------------------------------------

    public function forLanguage(string $langCode): JsonResponse
    {
        return ApiResponse::success(['translations' => (object) $this->translations->forLanguage($langCode)]);
    }

    public function forGroup(string $langCode, string $group): JsonResponse
    {
        return ApiResponse::success(['translations' => (object) $this->translations->forGroup($langCode, $group)]);
    }

    public function stats(): JsonResponse
    {
        return ApiResponse::success(['stats' => $this->translations->stats()]);
    }

    public function groups(): JsonResponse
    {
        return ApiResponse::success(['groups' => TranslationKey::query()->distinct()->orderBy('group')->pluck('group')->values()]);
    }

    public function export(): JsonResponse
    {
        return ApiResponse::success(['data' => $this->translations->export()]);
    }

    /** POST /translations/report-missing — frontends report keys they could not find. */
    public function reportMissing(Request $request): JsonResponse
    {
        $key = $request->input('key');
        if (! is_string($key) || trim($key) === '' || strlen($key) > 255) {
            throw ApiException::badRequest('key is required');
        }

        return ApiResponse::success($this->translations->reportMissing(
            trim($key),
            is_string($request->input('default_value')) ? mb_substr($request->input('default_value'), 0, 5000) : null,
            is_string($request->input('page_url')) ? mb_substr($request->input('page_url'), 0, 500) : null,
        ));
    }

    public function missingCount(): JsonResponse
    {
        return ApiResponse::success(['count' => MissingTranslationKey::query()->whereNull('resolved_at')->count()]);
    }

    // ---- signed in ----------------------------------------------------------

    /** ?resolved=false (default) | true | all, most reported first, 50 per page. */
    public function missing(Request $request): JsonResponse
    {
        $page = max(1, (int) $request->query('page', '1'));
        $limit = min(1000, max(1, (int) $request->query('limit', '50') ?: 50));
        $resolved = (string) $request->query('resolved', 'false');

        $rows = MissingTranslationKey::query()
            ->when($resolved === 'true', static fn ($q) => $q->whereNotNull('resolved_at'))
            ->when($resolved === 'false', static fn ($q) => $q->whereNull('resolved_at'))
            ->orderByDesc('report_count')->orderByDesc('last_reported_at')
            ->paginate($limit, ['*'], 'page', $page)
            ->through(fn (MissingTranslationKey $m): array => $this->missingRow($m));

        return ApiResponse::paginated($rows);
    }

    public function createFromMissing(Request $request, int $id): JsonResponse
    {
        $missing = $this->findMissing($id);
        $key = $this->translations->createFromMissing($missing, [
            'default_value' => is_string($request->input('default_value')) ? $request->input('default_value') : null,
            'description' => is_string($request->input('description')) ? $request->input('description') : null,
            'group' => is_string($request->input('group')) ? $request->input('group') : null,
        ], $this->userId($request));

        return ApiResponse::created(['key' => new TranslationKeyResource($key->load('translations.language'))], 'Translation key created and translated');
    }

    public function createAllMissing(Request $request): JsonResponse
    {
        $result = $this->translations->createAllMissing($this->userId($request));

        return ApiResponse::success($result, "Created {$result['created']} keys");
    }

    public function ignoreMissing(int $id): JsonResponse
    {
        $this->findMissing($id)->forceFill(['resolved_at' => now()])->save();

        return ApiResponse::success(null, 'Missing key ignored');
    }

    public function deleteMissing(int $id): JsonResponse
    {
        $this->findMissing($id)->delete();

        return ApiResponse::success(null, 'Missing key deleted');
    }

    public function translateAll(Request $request): JsonResponse
    {
        $language = Language::query()->find((int) $request->input('language_id')) ?? throw ApiException::notFound('Language not found');
        set_time_limit(0);
        $result = $this->translations->translateAllTo($language, $this->userId($request));

        $message = $result['failed'] > 0
            ? "{$result['created']} translations created, {$result['failed']} failed"
            : 'Translations created successfully';

        return ApiResponse::success(['count' => $result['created'], 'failed' => $result['failed'], 'reactivated' => $result['reactivated']], $message);
    }

    /** @return array<string, mixed> */
    private function missingRow(MissingTranslationKey $m): array
    {
        return [
            'id' => $m->id,
            'key' => $m->key,
            'default_value' => $m->getAttribute('default_value'),
            'page_url' => $m->getAttribute('page_url'),
            'report_count' => $m->report_count,
            'is_active' => $m->resolved_at === null ? 1 : 0,
            'first_reported_at' => $m->getAttribute('first_reported_at')?->utc()->format('Y-m-d\TH:i:s.v\Z'),
            'last_reported_at' => $m->getAttribute('last_reported_at')?->utc()->format('Y-m-d\TH:i:s.v\Z'),
            'created_at' => $m->created_at?->utc()->format('Y-m-d\TH:i:s.v\Z'),
            'updated_at' => $m->updated_at?->utc()->format('Y-m-d\TH:i:s.v\Z'),
        ];
    }

    private function findMissing(int $id): MissingTranslationKey
    {
        return MissingTranslationKey::query()->find($id) ?? throw ApiException::notFound('Missing key entry not found');
    }

    private function userId(Request $request): ?int
    {
        $id = $request->user()?->getAuthIdentifier();

        return is_numeric($id) ? (int) $id : null;
    }
}
