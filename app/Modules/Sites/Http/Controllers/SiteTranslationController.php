<?php

declare(strict_types=1);

namespace App\Modules\Sites\Http\Controllers;

use App\Core\Exceptions\ApiException;
use App\Core\Http\ApiResponse;
use App\Core\Support\Flag;
use App\Modules\Sites\Actions\Sites;
use App\Modules\Sites\Actions\SiteTranslations;
use App\Modules\Sites\Models\Site;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use stdClass;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * /api/v1/website-builder/translations/*, content-translations,
 * translation-keys — the marketing site's languages and content
 * translations (v1 websiteBuilderTranslation.controller).
 */
final class SiteTranslationController
{
    public function __construct(private readonly Sites $sites, private readonly SiteTranslations $translations) {}

    public function languages(): JsonResponse
    {
        return ApiResponse::success($this->translations->languages($this->site()), 'Languages retrieved');
    }

    public function publicLanguages(): JsonResponse
    {
        return ApiResponse::success($this->translations->publicLanguages($this->site()), 'Public languages retrieved');
    }

    public function createLanguage(Request $request): JsonResponse
    {
        return ApiResponse::created($this->translations->createLanguage($this->site(), $request->all()), 'Language created');
    }

    public function updateLanguage(Request $request, int $id): JsonResponse
    {
        return ApiResponse::success($this->translations->updateLanguage($this->site(), $id, $request->all()), 'Language updated');
    }

    public function setDefaultLanguage(int $id): JsonResponse
    {
        return ApiResponse::success($this->translations->setDefaultLanguage($this->site(), $id), 'Default language updated');
    }

    public function deleteLanguage(int $id): JsonResponse
    {
        return ApiResponse::success($this->translations->deleteLanguage($this->site(), $id), 'Language deleted');
    }

    /** `overwrite: true` re-translates fields that already have text. */
    public function translateAll(Request $request, int $id): JsonResponse
    {
        return ApiResponse::success($this->translations->translateAll($this->site(), $id, $request->input('overwrite') === true), 'Translation completed');
    }

    /** The whole site's overlay for one language (?language_id= or ?code= / ?lang=). */
    public function bundle(Request $request): JsonResponse
    {
        $code = $request->query('code', $request->query('lang'));
        if (! Flag::id($request->query('language_id')) && ! is_string($code)) {
            throw ApiException::badRequest('language_id or code is required');
        }
        $bundle = $this->translations->bundle($this->site(), $request->query('language_id'), $code);

        // An unknown or switched-off language is not an error: the site shows its own text.
        return $bundle === null
            ? ApiResponse::success(['language' => null, 'translations' => new stdClass], 'Language not available')
            : ApiResponse::success([...$bundle, 'translations' => $bundle['translations'] === [] ? new stdClass : $bundle['translations']], 'Translation bundle retrieved');
    }

    public function slot(Request $request): JsonResponse
    {
        $section = $request->query('section');
        if (! is_string($section) || $section === '') {
            throw ApiException::badRequest('section is required');
        }
        $data = $this->translations->slot($this->site(), $section, self::page($request->query('page_slug')), Flag::id($request->query('record_id')) ?? 0);

        return ApiResponse::success(self::map($data), 'Content translations retrieved');
    }

    public function saveSlot(Request $request): JsonResponse
    {
        $section = $request->input('section');
        $languageId = Flag::id($request->input('language_id'));
        if (! is_string($section) || $section === '' || $languageId === null) {
            throw ApiException::badRequest('section and language_id are required');
        }
        $values = $request->input('values');
        $data = $this->translations->saveSlot($this->site(), $section, self::page($request->input('page_slug')), Flag::id($request->input('record_id')) ?? 0, $languageId, is_array($values) ? $values : []);

        return ApiResponse::success(self::map($data), 'Content translations saved');
    }

    public function register(Request $request): JsonResponse
    {
        $section = $request->input('section');
        $fields = $request->input('fields');
        if (! is_string($section) || $section === '' || ! is_array($fields)) {
            throw ApiException::badRequest('section and fields[] are required');
        }

        return ApiResponse::success($this->translations->register($this->site(), $section, self::page($request->input('page_slug')), Flag::id($request->input('record_id')) ?? 0, $fields), 'Translation keys registered');
    }

    /** The registry, after a (throttled) scan of the content. */
    public function keys(Request $request): JsonResponse
    {
        $filters = $request->only(['section', 'page_slug', 'search']);

        return ApiResponse::success($this->translations->keys($this->site(), $filters, sync: true), 'Translation keys retrieved');
    }

    public function sections(): JsonResponse
    {
        return ApiResponse::success($this->translations->sections($this->site()), 'Sections retrieved');
    }

    public function stats(): JsonResponse
    {
        return ApiResponse::success($this->translations->stats($this->site()), 'Translation stats retrieved');
    }

    public function saveKey(Request $request, int $id): JsonResponse
    {
        $translations = $request->input('translations');
        if (! is_array($translations)) {
            throw ApiException::badRequest('translations[] is required');
        }

        return ApiResponse::success($this->translations->saveKey($this->site(), $id, $translations), 'Translations saved');
    }

    public function retranslateKey(int $id): JsonResponse
    {
        return ApiResponse::success($this->translations->retranslateKey($this->site(), $id), 'Key re-translated');
    }

    public function deleteKey(int $id): JsonResponse
    {
        $this->translations->deleteKey($this->site(), $id);

        return ApiResponse::success(['id' => $id, 'deleted' => true], 'Translation key deleted');
    }

    /** One section's empty fields, in every active language (or one: all_languages=false + language_id). */
    public function autoTranslate(Request $request): JsonResponse
    {
        $section = $request->input('section');
        if (! is_string($section) || $section === '') {
            throw ApiException::badRequest('section is required');
        }
        $all = ! in_array($request->input('all_languages'), [false, 'false', 0, '0'], true);
        $data = $this->translations->autoTranslate($this->site(), $section, self::page($request->input('page_slug')), $request->input('record_id'), $request->input('language_id'), $all)
            ?? throw ApiException::notFound('No active language to translate into');

        return ApiResponse::success(self::map($data), 'Content auto-translated');
    }

    /**
     * The same, as Server-Sent Events (progress, then complete or error), so
     * the screen can show a real percentage. EventSource sends no custom
     * headers: the sign-in arrives as the cookie.
     */
    public function autoTranslateStream(Request $request): StreamedResponse
    {
        $site = $this->site();
        $section = $request->query('section');
        $all = ! in_array($request->query('all_languages'), ['false', '0'], true);

        return response()->stream(function () use ($site, $section, $request, $all): void {
            $send = static function (string $event, mixed $payload): void {
                echo "event: {$event}\ndata: ".json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n\n";
                if (ob_get_level() > 0) {
                    ob_flush();
                }
                flush();
            };
            if (! is_string($section) || $section === '') {
                $send('error', ['message' => 'section is required']);

                return;
            }
            try {
                $result = $this->translations->autoTranslate($site, $section, self::page($request->query('page_slug')), $request->query('record_id'),
                    $request->query('language_id'), $all, static fn (array $progress) => $send('progress', $progress));
                $result === null
                    ? $send('error', ['message' => 'No active language to translate into'])
                    : $send('complete', ['translations' => self::map($result)]);
            } catch (Throwable $e) {
                $send('error', ['message' => $e->getMessage() !== '' ? $e->getMessage() : 'Failed to auto-translate']);
            }
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache, no-transform',
            // Without this, nginx buffers the stream and every event arrives at once.
            'X-Accel-Buffering' => 'no',
        ]);
    }

    private function site(): Site
    {
        return $this->sites->marketing();
    }

    private static function page(mixed $value): string
    {
        return is_string($value) ? mb_substr($value, 0, 100) : '';
    }

    /**
     * {language id: {...}} — an object even when empty (v1).
     *
     * @param  array<int, mixed>  $data
     * @return array<int, mixed>|stdClass
     */
    private static function map(array $data): array|stdClass
    {
        return $data === [] ? new stdClass : $data;
    }
}
