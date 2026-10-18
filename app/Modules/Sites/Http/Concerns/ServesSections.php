<?php

declare(strict_types=1);

namespace App\Modules\Sites\Http\Concerns;

use App\Core\Exceptions\ApiException;
use App\Core\Http\ApiResponse;
use App\Modules\Sites\Actions\SiteContent;
use App\Modules\Sites\Models\Site;
use App\Modules\Sites\Support\SiteSections;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The section endpoints both builders share. A route names its section in
 * route defaults ('section' for a list section, 'setting' for a single-row
 * one); the controller says which site the request works on.
 *
 * Route parameters reach these methods by position (Laravel), so route
 * defaults are read from the request, never taken as arguments.
 */
trait ServesSections
{
    /** The site this request works on; with $create, set it up when missing. */
    abstract protected function site(Request $request, bool $create): ?Site;

    /** Sections whose delete answers no data (v1's hand-written admin controllers). */
    protected const QUIET_DELETES = [
        'features', 'pricingPlans', 'pricingFeatures', 'steps', 'faqCategories', 'faqs', 'templateCategories', 'templates',
        'tutorialCategories', 'tutorialSubcategories', 'tutorialDifficulties', 'tutorialTypes', 'tutorials',
    ];

    public function index(Request $request, SiteContent $content): JsonResponse
    {
        $key = $this->sectionKey($request);
        $site = $this->site($request, false);

        return ApiResponse::success($site === null ? [] : $content->rows($site, $key, $request), $this->message($key, 'listed', 'Items retrieved'));
    }

    public function show(Request $request, SiteContent $content, int $id): JsonResponse
    {
        $key = $this->sectionKey($request);

        return ApiResponse::success($content->show($this->requiredSite($request), $key, $id), $this->message($key, 'shown', 'Item retrieved'));
    }

    public function store(Request $request, SiteContent $content): JsonResponse
    {
        $key = $this->sectionKey($request);

        return ApiResponse::created($content->create($this->requiredSite($request, true), $key, $request->all()), $this->message($key, 'created', 'Item created'));
    }

    public function update(Request $request, SiteContent $content, int $id): JsonResponse
    {
        $key = $this->sectionKey($request);

        return ApiResponse::success($content->update($this->requiredSite($request), $key, $id, $request->all()), $this->message($key, 'updated', 'Item updated'));
    }

    public function status(Request $request, SiteContent $content, int $id): JsonResponse
    {
        $key = $this->sectionKey($request);

        return ApiResponse::success($content->setActive($this->requiredSite($request), $key, $id, $request->all()), $this->message($key, 'status', 'Status updated successfully'));
    }

    public function destroy(Request $request, SiteContent $content, int $id): JsonResponse
    {
        $key = $this->sectionKey($request);
        $content->delete($this->requiredSite($request), $key, $id);

        return ApiResponse::success(in_array($key, self::QUIET_DELETES, true) ? null : ['id' => $id, 'deleted' => true], $this->message($key, 'deleted', 'Item deleted'));
    }

    /** v1 bulk PUT: the body is the list, or {items: [...]}. */
    public function replace(Request $request, SiteContent $content): JsonResponse
    {
        $key = $this->sectionKey($request);

        return ApiResponse::success($content->replace($this->requiredSite($request, true), $key, self::items($request)), $this->message($key, 'saved', 'Items saved'));
    }

    public function setting(Request $request, SiteContent $content): JsonResponse
    {
        [$section, $label] = $this->settingSection($request);
        $site = $this->site($request, false);

        return ApiResponse::success($site === null ? null : $content->setting($site, $section), "{$label} retrieved");
    }

    public function saveSetting(Request $request, SiteContent $content): JsonResponse
    {
        [$section, $label] = $this->settingSection($request);

        return ApiResponse::success($content->saveSetting($this->requiredSite($request, true), $section, $request->all()), "{$label} saved");
    }

    /** Which blocks a page shows (?page_slug=; none = the site-wide list). */
    public function blocks(Request $request, SiteContent $content): JsonResponse
    {
        $site = $this->site($request, false);

        return ApiResponse::success($site === null ? [] : $content->blocks($site, self::page($request)), 'UI blocks retrieved');
    }

    public function saveBlocks(Request $request, SiteContent $content): JsonResponse
    {
        return ApiResponse::success($content->saveBlocks($this->requiredSite($request, true), self::items($request), self::page($request)), 'UI blocks saved');
    }

    // ---------------------------------------------------------------------

    protected static function page(Request $request): string
    {
        $page = $request->input('page_slug', $request->query('page_slug', ''));

        return is_string($page) ? mb_substr($page, 0, 100) : '';
    }

    protected function requiredSite(Request $request, bool $create = false): Site
    {
        return $this->site($request, $create) ?? throw ApiException::notFound('Record not found');
    }

    protected function sectionKey(Request $request): string
    {
        return (string) $request->route('section');
    }

    /** @return array{0: string, 1: string} section, v1 message label ("Footer settings") */
    protected function settingSection(Request $request): array
    {
        return [(string) $request->route('setting'), (string) $request->route('label')];
    }

    protected function message(string $key, string $action, string $fallback): string
    {
        return app(SiteSections::class)->list($key)->message($action, $fallback);
    }

    /** @return mixed the list a bulk save sends: the body itself, or its items */
    protected static function items(Request $request): mixed
    {
        $body = $request->all();

        return array_is_list($body) ? $body : ($body['items'] ?? []);
    }
}
