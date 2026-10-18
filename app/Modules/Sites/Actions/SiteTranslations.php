<?php

declare(strict_types=1);

namespace App\Modules\Sites\Actions;

use App\Core\Contracts\MachineTranslator;
use App\Core\Exceptions\ApiException;
use App\Core\Exceptions\TranslationQuotaExceeded;
use App\Core\Support\Flag;
use App\Modules\Sites\Models\Site;
use App\Modules\Sites\Models\SiteContactCategory;
use App\Modules\Sites\Models\SiteFaq;
use App\Modules\Sites\Models\SiteFaqCategory;
use App\Modules\Sites\Models\SiteFeature;
use App\Modules\Sites\Models\SiteGalleryCategory;
use App\Modules\Sites\Models\SiteGalleryItem;
use App\Modules\Sites\Models\SiteHighlight;
use App\Modules\Sites\Models\SiteLanguage;
use App\Modules\Sites\Models\SiteMenuItem;
use App\Modules\Sites\Models\SitePage;
use App\Modules\Sites\Models\SitePricingFeature;
use App\Modules\Sites\Models\SitePricingPlan;
use App\Modules\Sites\Models\SiteRecord;
use App\Modules\Sites\Models\SiteSetting;
use App\Modules\Sites\Models\SiteSlide;
use App\Modules\Sites\Models\SiteSlider;
use App\Modules\Sites\Models\SiteStep;
use App\Modules\Sites\Models\SiteTemplate;
use App\Modules\Sites\Models\SiteTemplateCategory;
use App\Modules\Sites\Models\SiteTestimonial;
use App\Modules\Sites\Models\SiteTranslation;
use App\Modules\Sites\Models\SiteTranslationKey;
use App\Modules\Sites\Models\SiteTutorial;
use App\Modules\Sites\Models\SiteTutorialTaxonomy;
use App\Modules\Sites\Support\UiChrome;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Content translation (v1 websiteBuilderTranslation.service).
 *
 * A translation slot is (section, page_slug, record_id, field_key). The key
 * registry is rebuilt from the site's content by a scan, so text already saved
 * is translatable without re-saving each section. Rules kept from v1:
 *
 *  - auto-translate fills only EMPTY slots, in every active language at once;
 *    an existing translation is never re-translated (changing the English is
 *    not a reason to replace someone's wording);
 *  - removing a key never removes its translations (a slot whose address
 *    changed is re-adopted when it reappears);
 *  - every write is batched (production MySQL costs ~374 ms a query).
 *
 * Brand names are no longer offered for translation: client and sponsor
 * names, social link labels, the company name and the site name.
 */
final class SiteTranslations
{
    private const CHUNK = 200;

    private const SYNC_SECONDS = 60;

    /** Sections read from one table: model, fixed where, name column, field => label. */
    private const TABLES = [
        'testimonials' => [SiteTestimonial::class, [], 'customer_name', ['customer_name' => 'Customer Name', 'event_name' => 'Event Name', 'feedback' => 'Feedback']],
        'how-it-works' => [SiteStep::class, [], 'title', ['title' => 'Title', 'description' => 'Description', 'highlight_title' => 'Highlight Title', 'highlight_subtext' => 'Highlight Subtext']],
        'faqs' => [SiteFaq::class, [], 'question', ['question' => 'Question', 'answer' => 'Answer']],
        'templates' => [SiteTemplate::class, [], 'template_name', ['template_name' => 'Template Name', 'description' => 'Description']],
        'nav-menu' => [SiteMenuItem::class, [], 'label', ['label' => 'Menu Label']],
        'sliders' => [SiteSlide::class, [], 'title', ['title' => 'Title', 'description' => 'Description', 'button_label' => 'Button Label']],
        'gallery' => [SiteGalleryItem::class, [], 'event_name', ['event_name' => 'Event Name', 'alt_text' => 'Alt Text']],
        'video-tutorials' => [SiteTutorial::class, [], 'title', ['title' => 'Title', 'short_description' => 'Short Description', 'key_takeaways' => 'Key Takeaways']],
        'pages' => [SitePage::class, [], 'title', ['title' => 'Title', 'excerpt' => 'Excerpt', 'seo_title' => 'SEO Title', 'seo_description' => 'SEO Description']],
        'template-categories' => [SiteTemplateCategory::class, [], 'name', ['name' => 'Category Name', 'description' => 'Description']],
        'faq-categories' => [SiteFaqCategory::class, [], 'name', ['name' => 'Category Name', 'description' => 'Description']],
        'gallery-categories' => [SiteGalleryCategory::class, [], 'name', ['name' => 'Category Name', 'description' => 'Description']],
        'contact-categories' => [SiteContactCategory::class, [], 'name', ['name' => 'Category Name', 'description' => 'Description']],
        'video-tutorial-categories' => [SiteTutorialTaxonomy::class, ['kind' => 'category'], 'name', ['name' => 'Category Name', 'description' => 'Description']],
        'video-tutorial-subcategories' => [SiteTutorialTaxonomy::class, ['kind' => 'subcategory'], 'name', ['name' => 'Sub Category Name', 'description' => 'Description']],
        'video-tutorial-difficulty-levels' => [SiteTutorialTaxonomy::class, ['kind' => 'difficulty'], 'name', ['name' => 'Level Name', 'description' => 'Description']],
        'video-tutorial-types' => [SiteTutorialTaxonomy::class, ['kind' => 'type'], 'name', ['name' => 'Type Name', 'description' => 'Description']],
    ];

    /** Single-row sections read from site_settings: translation section => [setting, field => label]. */
    private const SETTINGS = [
        'basic-information' => ['basic-information', ['address' => 'Address']],
        'seo' => ['seo', ['default_title' => 'Default Title', 'default_description' => 'Default Description', 'og_title' => 'OG Title', 'og_description' => 'OG Description']],
        'login-page' => ['login', ['title' => 'Title', 'subtitle' => 'Subtitle']],
        'contact-settings' => ['contact', ['address' => 'Address']],
        'pricing-settings' => ['pricing', [
            'section_title' => 'Section Title', 'section_subtitle' => 'Section Subtitle', 'badge_text' => 'Badge Text',
            'individual_heading' => 'Individual Heading', 'individual_subheading' => 'Individual Subheading',
            'company_heading' => 'Company Heading', 'company_subheading' => 'Company Subheading',
        ]],
    ];

    /** Sections whose keys a scan owns (others, e.g. ui-chrome, are registered by hand). */
    private const SCANNED_EXTRA = ['hero-section', 'footer', 'features', 'pricing-plans', 'highlights', 'pricing-features', 'slider-settings'];

    public function __construct(
        private readonly MachineTranslator $translator,
        private readonly Sites $sites,
    ) {}

    // ---- languages --------------------------------------------------------

    /** @return list<array<string, mixed>> */
    public function languages(Site $site): array
    {
        return $this->languageQuery($site)->orderBy('sort_order')->orderBy('id')->get()->map(self::language(...))->values()->all();
    }

    /** @return list<array<string, mixed>> the public switcher's list: active, default first */
    public function publicLanguages(Site $site): array
    {
        return $this->languageQuery($site)->where('is_active', 1)->orderByDesc('is_default')->orderBy('sort_order')->orderBy('id')->get()
            ->map(static fn (SiteLanguage $l): array => [
                'id' => $l->id, 'code' => $l->code, 'name' => $l->name, 'native_name' => $l->native_name,
                'direction' => $l->direction, 'is_default' => (bool) $l->is_default,
            ])->values()->all();
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function createLanguage(Site $site, array $input): array
    {
        $code = mb_strtolower(trim(is_scalar($input['code'] ?? null) ? (string) $input['code'] : ''));
        $name = trim(is_scalar($input['name'] ?? null) ? (string) $input['name'] : '');
        if ($code === '' || $name === '') {
            throw ApiException::badRequest('code and name are required');
        }
        if ($this->languageQuery($site)->where('code', $code)->exists()) {
            throw ApiException::badRequest('A language with this code already exists');
        }

        $language = SiteLanguage::query()->create([
            'site_id' => $site->id, 'code' => mb_substr($code, 0, 10), 'name' => $name,
            'native_name' => is_scalar($input['native_name'] ?? null) ? (string) $input['native_name'] : null,
            'direction' => Flag::oneOf($input['direction'] ?? null, ['ltr', 'rtl'], 'ltr'),
            'sort_order' => (int) ($input['sort_order'] ?? 0),
        ]);
        $this->sites->changed($site);

        return self::language($language->refresh());
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function updateLanguage(Site $site, int $id, array $input): array
    {
        $language = $this->findLanguage($site, $id);
        if ($language->is_default && array_key_exists('is_active', $input) && Flag::bit($input['is_active'], 1) === 0) {
            throw ApiException::badRequest('The default language cannot be deactivated');
        }

        $values = [];
        foreach (['name', 'native_name'] as $field) {
            if (array_key_exists($field, $input)) {
                $values[$field] = is_scalar($input[$field]) ? (string) $input[$field] : null;
            }
        }
        if (array_key_exists('code', $input) && is_scalar($input['code']) && trim((string) $input['code']) !== '') {
            $code = mb_strtolower(trim((string) $input['code']));
            if ($this->languageQuery($site)->where('code', $code)->whereKeyNot($id)->exists()) {
                throw ApiException::badRequest('A language with this code already exists');
            }
            $values['code'] = mb_substr($code, 0, 10);
        }
        if (array_key_exists('direction', $input)) {
            $values['direction'] = Flag::oneOf($input['direction'], ['ltr', 'rtl'], 'ltr');
        }
        if (array_key_exists('sort_order', $input)) {
            $values['sort_order'] = (int) $input['sort_order'];
        }
        if (array_key_exists('is_active', $input)) {
            $values['is_active'] = Flag::bit($input['is_active'], 1);
        }
        $language->fill($values)->save();
        $this->sites->changed($site);

        return self::language($language);
    }

    /** @return array<string, mixed> */
    public function setDefaultLanguage(Site $site, int $id): array
    {
        $language = $this->findLanguage($site, $id);
        $this->languageQuery($site)->whereKeyNot($id)->update(['is_default' => 0]);
        $language->forceFill(['is_default' => 1, 'is_active' => 1])->save();
        $this->sites->changed($site);

        return self::language($language);
    }

    /**
     * Removes the language and its translations.
     *
     * @return array{id: int, deleted: true}
     */
    public function deleteLanguage(Site $site, int $id): array
    {
        $language = $this->languageQuery($site)->find($id);
        if ($language?->is_default) {
            throw ApiException::badRequest('The default language cannot be deleted');
        }
        $language?->delete();
        $this->sites->changed($site);

        return ['id' => $id, 'deleted' => true];
    }

    // ---- translations of one slot -----------------------------------------

    /** @return array<int, array<string, string|null>> language id => field => text */
    public function slot(Site $site, string $section, string $pageSlug, int $recordId): array
    {
        $byLanguage = [];
        foreach ($this->slotQuery($site, $section, $pageSlug, $recordId)->get(['language_id', 'field_key', 'value']) as $row) {
            $byLanguage[$row->language_id][$row->field_key] = $row->value;
        }

        return $byLanguage;
    }

    /**
     * Upsert the given fields of one slot in one language.
     *
     * @param  array<string, mixed>  $values  field => text
     * @return array<int, array<string, string|null>>
     */
    public function saveSlot(Site $site, string $section, string $pageSlug, int $recordId, int $languageId, array $values, string $status = 'reviewed'): array
    {
        $this->findLanguage($site, $languageId);
        $now = now();
        $rows = [];
        foreach ($values as $field => $value) {
            $rows[] = [
                'site_id' => $site->id, 'section' => $section, 'page_slug' => $pageSlug, 'record_id' => $recordId,
                'field_key' => (string) $field, 'language_id' => $languageId, 'value' => self::clean($value), 'status' => $status,
                'created_at' => $now, 'updated_at' => $now,
            ];
        }
        foreach (array_chunk($rows, self::CHUNK) as $chunk) {
            SiteTranslation::query()->upsert($chunk, ['site_id', 'section', 'page_slug', 'record_id', 'field_key', 'language_id'], ['value', 'status', 'updated_at']);
        }
        if ($rows !== []) {
            $this->sites->changed($site);
        }

        return $this->slot($site, $section, $pageSlug, $recordId);
    }

    /**
     * Every translation of one language, keyed "section|page_slug|record_id"
     * (what the rendered site overlays). Null for an unknown or inactive
     * language; the default language needs no overlay.
     *
     * @return array{language: array<string, mixed>, translations: array<string, array<string, string>>}|null
     */
    public function bundle(Site $site, mixed $languageId, mixed $code): ?array
    {
        $id = Flag::id($languageId);
        $language = $this->languageQuery($site)->where('is_active', 1)
            ->when($id !== null, static fn (Builder $q) => $q->whereKey($id), static fn (Builder $q) => $q->where('code', mb_strtolower(is_scalar($code) ? (string) $code : '')))
            ->first();
        if ($language === null) {
            return null;
        }
        if ($language->is_default) {
            return ['language' => self::language($language), 'translations' => []];
        }

        $translations = [];
        SiteTranslation::query()->where('site_id', $site->id)->where('language_id', $language->id)->where('value', '<>', '')
            ->get(['section', 'page_slug', 'record_id', 'field_key', 'value'])
            ->each(static function (SiteTranslation $t) use (&$translations): void {
                $translations["{$t->section}|{$t->page_slug}|{$t->record_id}"][$t->field_key] = (string) $t->value;
            });

        return ['language' => self::language($language), 'translations' => $translations];
    }

    // ---- the key registry -------------------------------------------------

    /**
     * Register one slot's fields (a section was saved in English). A field
     * with no English is not translatable: its key is removed.
     *
     * @param  mixed  $fields  [{key, label, value}]
     * @return list<array<string, mixed>>
     */
    public function register(Site $site, string $section, string $pageSlug, int $recordId, mixed $fields): array
    {
        $entries = [];
        foreach (array_values(array_filter(is_array($fields) ? $fields : [], 'is_array')) as $field) {
            $entries[] = [
                'key' => is_scalar($field['key'] ?? null) ? (string) $field['key'] : '',
                'label' => is_scalar($field['label'] ?? null) ? (string) $field['label'] : '',
                'value' => is_scalar($field['value'] ?? null) ? (string) $field['value'] : '',
            ];
        }
        $this->writeKeys($site, [['section' => $section, 'page_slug' => $pageSlug, 'record_id' => $recordId, 'fields' => $entries]], removeEmpty: true);

        return $this->keys($site, ['section' => $section, 'page_slug' => $pageSlug]);
    }

    /**
     * The registered keys with every language's text: [{..., translations:
     * [{language_id, value, status}]}].
     *
     * @param  array<string, mixed>  $filters  section, page_slug, record_id, search
     * @return list<array<string, mixed>>
     */
    public function keys(Site $site, array $filters = [], bool $sync = false, bool $fresh = false): array
    {
        if ($sync) {
            $this->syncIfStale($site, $fresh);
        }

        $query = SiteTranslationKey::query()->where('site_id', $site->id);
        foreach (['section', 'page_slug'] as $column) {
            if (is_string($filters[$column] ?? null) && $filters[$column] !== '') {
                $query->where($column, $filters[$column]);
            }
        }
        // 0 is a real record id: only absence means "every row".
        if (isset($filters['record_id']) && $filters['record_id'] !== '' && is_numeric($filters['record_id'])) {
            $query->where('record_id', (int) $filters['record_id']);
        }
        if (is_string($filters['search'] ?? null) && trim($filters['search']) !== '') {
            $term = '%'.trim($filters['search']).'%';
            $query->where(static fn (Builder $q) => $q->where('field_label', 'like', $term)->orWhere('default_value', 'like', $term)->orWhere('field_key', 'like', $term));
        }
        $keys = $query->orderBy('section')->orderBy('page_slug')->orderBy('sort_order')->orderBy('id')->get();
        if ($keys->isEmpty()) {
            return [];
        }

        $bySlot = [];
        SiteTranslation::query()->where('site_id', $site->id)
            ->whereIn('section', $keys->pluck('section')->unique()->all())
            ->get(['section', 'page_slug', 'record_id', 'field_key', 'language_id', 'value', 'status'])
            ->each(static function (SiteTranslation $t) use (&$bySlot): void {
                $bySlot["{$t->section}|{$t->page_slug}|{$t->record_id}|{$t->field_key}"][] = ['language_id' => $t->language_id, 'value' => $t->value, 'status' => $t->status];
            });

        return $keys->map(static fn (SiteTranslationKey $k): array => [
            ...array_diff_key($k->attributesToArray(), ['site_id' => true]),
            'translations' => $bySlot["{$k->section}|{$k->page_slug}|{$k->record_id}|{$k->field_key}"] ?? [],
        ])->values()->all();
    }

    /** @return list<string> */
    public function sections(Site $site): array
    {
        $this->syncIfStale($site);

        return SiteTranslationKey::query()->where('site_id', $site->id)->distinct()->orderBy('section')->pluck('section')->all();
    }

    /** @return array{total_keys: int, languages: list<array<string, mixed>>} completion per language */
    public function stats(Site $site): array
    {
        $this->syncIfStale($site);
        $total = SiteTranslationKey::query()->where('site_id', $site->id)->count();
        $counts = SiteTranslation::query()->where('site_id', $site->id)->where('value', '<>', '')
            ->groupBy('language_id')
            ->selectRaw("language_id, SUM(status = 'reviewed') as reviewed, SUM(status = 'auto') as auto, COUNT(*) as total")
            ->get()->keyBy('language_id');

        return [
            'total_keys' => $total,
            'languages' => $this->languageQuery($site)->where('is_default', 0)->orderBy('sort_order')->orderBy('id')->get()
                ->map(static function (SiteLanguage $l) use ($counts, $total): array {
                    $c = $counts->get($l->id);
                    $done = (int) ($c?->getAttribute('total') ?? 0);

                    return [
                        'id' => $l->id, 'name' => $l->name, 'native_name' => $l->native_name, 'total' => $done,
                        'reviewed' => (int) ($c?->getAttribute('reviewed') ?? 0), 'auto' => (int) ($c?->getAttribute('auto') ?? 0),
                        'missing' => max($total - $done, 0), 'completion' => $total > 0 ? (int) round($done / $total * 100) : 0,
                    ];
                })->values()->all(),
        ];
    }

    /** Remove a key and its translations. */
    public function deleteKey(Site $site, int $id): void
    {
        $key = $this->findKey($site, $id);
        $this->slotQuery($site, $key->section, $key->page_slug, $key->record_id)->where('field_key', $key->field_key)->delete();
        $key->delete();
        $this->sites->changed($site);
    }

    /**
     * One key's text in several languages at once (the "Edit Translations" dialog).
     *
     * @param  mixed  $translations  [{language_id, value}]
     * @return array<string, mixed>
     */
    public function saveKey(Site $site, int $id, mixed $translations): array
    {
        $key = $this->findKey($site, $id);
        foreach (array_filter(is_array($translations) ? $translations : [], 'is_array') as $entry) {
            $languageId = Flag::id($entry['language_id'] ?? null);
            if ($languageId !== null && $this->languageQuery($site)->whereKey($languageId)->exists()) {
                $this->saveSlot($site, $key->section, $key->page_slug, $key->record_id, $languageId, [$key->field_key => $entry['value'] ?? ''], 'reviewed');
            }
        }

        return collect($this->keys($site, ['section' => $key->section, 'page_slug' => $key->page_slug, 'record_id' => $key->record_id]))
            ->firstWhere('id', $key->id) ?? ['id' => $key->id];
    }

    /**
     * Machine-translate one key into every active language again (the explicit "redo").
     *
     * @return array{id: int, retranslated: true}
     */
    public function retranslateKey(Site $site, int $id): array
    {
        $key = $this->findKey($site, $id);
        $source = trim((string) $key->default_value);
        if ($source !== '') {
            foreach ($this->targetLanguages($site) as $language) {
                try {
                    $text = $this->translator->translate($source, 'en', $language->code);
                } catch (Throwable) {
                    continue;
                }
                $this->saveSlot($site, $key->section, $key->page_slug, $key->record_id, $language->id, [$key->field_key => $text], 'auto');
            }
        }

        return ['id' => $id, 'retranslated' => true];
    }

    // ---- machine translation ----------------------------------------------

    /**
     * Fill one slot's empty fields in every active language (or one, with
     * $languageId and $all false). Progress goes to $onProgress so the
     * streaming route can show a real percentage.
     *
     * @param  (Closure(array<string, mixed>): void)|null  $onProgress
     * @return array<int, array<string, string|null>>|null null when there is no language to translate into
     */
    public function autoTranslate(Site $site, string $section, string $pageSlug, mixed $recordId, mixed $languageId, bool $all, ?Closure $onProgress = null): ?array
    {
        $onProgress ??= static function (array $progress): void {};
        $requested = Flag::id($languageId);
        $languages = ! $all && $requested !== null
            ? $this->languageQuery($site)->whereKey($requested)->get()
            : $this->targetLanguages($site);
        if ($languages->isEmpty()) {
            return null;
        }

        // A fresh scan: a field the admin has just filled in must have its key (v1 §96).
        $keys = $this->keys($site, ['section' => $section, 'page_slug' => $pageSlug, 'record_id' => $recordId], sync: true, fresh: true);
        $plan = [];
        $preserved = 0;
        foreach ($languages as $language) {
            foreach ($keys as $key) {
                $source = trim((string) $key['default_value']);
                if ($source === '') {
                    continue;
                }
                if (self::translated($key['translations'], $language->id)) {
                    $preserved++;

                    continue;
                }
                $plan[] = [$language, $key, $source];
            }
        }

        $total = count($plan);
        $done = 0;
        $onProgress(['phase' => 'start', 'done' => 0, 'total' => $total, 'preserved' => $preserved]);
        $pending = [];
        foreach ($plan as [$language, $key, $source]) {
            try {
                $pending[$language->id][(int) $key['record_id']][$key['field_key']] = $this->translator->translate($source, 'en', $language->code);
            } catch (Throwable) {
                // Left empty: the next run picks it up again.
            }
            $done++;
            $onProgress(['phase' => 'progress', 'done' => $done, 'total' => $total, 'preserved' => $preserved, 'field' => $key['field_label'] ?: $key['field_key'], 'language' => $language->name]);
        }
        foreach ($pending as $language => $records) {
            foreach ($records as $record => $values) {
                $this->saveSlot($site, $section, $pageSlug, $record, $language, $values, 'auto');
            }
        }

        // No record named: a single-row section's one record (else record 0, as v1).
        $records = array_values(array_unique(array_map(static fn (array $k): int => (int) $k['record_id'], $keys)));
        $saved = $this->slot($site, $section, $pageSlug, Flag::id($recordId) ?? (count($records) === 1 ? $records[0] : 0));
        $onProgress(['phase' => 'done', 'done' => $done, 'total' => $total, 'preserved' => $preserved]);

        return $saved;
    }

    /**
     * Translate every key into one language (the Languages page's "Translate").
     * Existing translations are kept unless $overwrite. Paced, and stops asking
     * once the provider's quota is spent.
     *
     * @return array{created: int, failed: int, skipped: int, quotaExceeded: bool}
     */
    public function translateAll(Site $site, int $languageId, bool $overwrite): array
    {
        $language = $this->findLanguage($site, $languageId);
        if ($language->is_default) {
            throw ApiException::badRequest('The default language is the translation source');
        }

        $created = $failed = $skipped = 0;
        $quotaExceeded = false;
        $delay = (int) config('services.mymemory.delay_ms', 350);
        $pending = [];
        foreach ($this->keys($site, [], sync: true, fresh: true) as $key) {
            $source = trim((string) $key['default_value']);
            if ($source === '' || (! $overwrite && self::translated($key['translations'], $language->id))) {
                $skipped++;

                continue;
            }
            if ($quotaExceeded) {
                $failed++;

                continue;
            }
            try {
                $pending["{$key['section']}|{$key['page_slug']}|{$key['record_id']}"][$key['field_key']] = $this->translator->translate($source, 'en', $language->code);
                $created++;
            } catch (TranslationQuotaExceeded) {
                $quotaExceeded = true;
                $failed++;
            } catch (Throwable) {
                $failed++;
            }
            if ($delay > 0) {
                usleep($delay * 1000);
            }
        }
        foreach ($pending as $slot => $values) {
            [$section, $pageSlug, $recordId] = explode('|', $slot);
            $this->saveSlot($site, $section, $pageSlug, (int) $recordId, $language->id, $values, 'auto');
        }

        return ['created' => $created, 'failed' => $failed, 'skipped' => $skipped, 'quotaExceeded' => $quotaExceeded];
    }

    // ---- the content scan -------------------------------------------------

    /** The scan, at most once a minute unless $force (anything about to translate forces it). */
    public function syncIfStale(Site $site, bool $force = false): void
    {
        $lock = "sites:translation-sync:{$site->id}";
        if ($force) {
            Cache::put($lock, true, self::SYNC_SECONDS);
        } elseif (! Cache::add($lock, true, self::SYNC_SECONDS)) {
            return;
        }
        $this->sync($site);
    }

    /**
     * Register a key for every non-empty translatable value in the site's
     * content, and drop the keys whose content is gone (their translations stay).
     *
     * @return array{discovered: int, pruned: int}
     */
    public function sync(Site $site): array
    {
        $slots = [['section' => UiChrome::SECTION, 'page_slug' => '', 'record_id' => 0, 'fields' => array_map(
            static fn (string $key, string $value): array => ['key' => $key, 'label' => UiChrome::label($key), 'value' => $value],
            array_keys(UiChrome::KEYS), UiChrome::KEYS,
        )]];
        foreach ($this->scan($site) as $slot) {
            $slots[] = $slot;
        }
        $seen = $this->writeKeys($site, $slots, removeEmpty: false);

        $scanned = [...array_keys(self::TABLES), ...array_keys(self::SETTINGS), ...self::SCANNED_EXTRA];
        $doomed = SiteTranslationKey::query()->where('site_id', $site->id)->whereIn('section', $scanned)
            ->get(['id', 'section', 'page_slug', 'record_id', 'field_key'])
            ->reject(static fn (SiteTranslationKey $k): bool => isset($seen["{$k->section}|{$k->page_slug}|{$k->record_id}|{$k->field_key}"]))
            ->pluck('id')->all();
        foreach (array_chunk($doomed, self::CHUNK) as $chunk) {
            SiteTranslationKey::query()->whereKey($chunk)->delete();
        }

        return ['discovered' => count($seen), 'pruned' => count($doomed)];
    }

    /** @return list<array{section: string, page_slug: string, record_id: int, fields: list<array{key: string, label: string, value: string}>}> */
    private function scan(Site $site): array
    {
        $slots = [];

        foreach (self::TABLES as $section => [$model, $where, $nameColumn, $fields]) {
            $rows = $model::query()->where('site_id', $site->id)->where($where)->orderBy('id')->get();
            foreach ($rows as $row) {
                $slots[] = $this->plain($section, $row, $fields, $rows->count() > 1 ? $row->getAttribute($nameColumn) : null);
            }
        }

        $settings = SiteSetting::query()->where('site_id', $site->id)->get()->keyBy('section');
        foreach (self::SETTINGS as $section => [$setting, $fields]) {
            $row = $settings->get($setting);
            if ($row !== null) {
                $data = $row->data ?? [];
                $slots[] = ['section' => $section, 'page_slug' => '', 'record_id' => $row->id, 'fields' => self::fields($fields, $data)];
            }
        }
        if (($hero = $settings->get('hero')) !== null) {
            foreach ($this->heroSlots($hero) as $slot) {
                $slots[] = $slot;
            }
        }
        if (($footer = $settings->get('footer')) !== null) {
            $slots[] = $this->footerSlot($footer);
        }

        $features = SiteFeature::query()->where('site_id', $site->id)->orderBy('id')->get();
        foreach ($features as $feature) {
            $slot = $this->plain('features', $feature, ['title' => 'Title', 'short_description' => 'Short Description', 'detailed_description' => 'Detailed Description'], $features->count() > 1 ? $feature->getAttribute('title') : null);
            foreach (self::listTexts($feature->getAttribute('bullet_points_json')) as $i => $text) {
                $slot['fields'][] = ['key' => 'bullet_'.($i + 1), 'label' => self::named('Bullet '.($i + 1), $features->count() > 1 ? $feature->getAttribute('title') : null), 'value' => $text];
            }
            $slots[] = $slot;
        }

        $plans = SitePricingPlan::query()->where('site_id', $site->id)->orderBy('id')->get();
        foreach ($plans as $plan) {
            $name = $plans->count() > 1 ? $plan->getAttribute('plan_name') : null;
            $slot = $this->plain('pricing-plans', $plan, ['plan_name' => 'Plan Name', 'subtitle' => 'Subtitle', 'period_label' => 'Period Label', 'badge_text' => 'Badge Text'], $name);
            foreach (self::listTexts($plan->getAttribute('features_json')) as $i => $text) {
                $slot['fields'][] = ['key' => 'feature_'.($i + 1), 'label' => self::named('Feature '.($i + 1), $name), 'value' => $text];
            }
            $slots[] = $slot;
        }

        $matrix = SitePricingFeature::query()->where('site_id', $site->id)->orderBy('id')->get();
        foreach ($matrix as $row) {
            $name = $matrix->count() > 1 ? $row->getAttribute('feature_name') : null;
            $slot = $this->plain('pricing-features', $row, ['feature_name' => 'Feature Name', 'description' => 'Description'], $name);
            // The comparison cells ("Up to 50"), keyed by tier name so reordering tiers moves nothing.
            $tiers = $row->getAttribute('plan_values_json');
            $tiers = is_array($tiers) ? $tiers : [];
            ksort($tiers);
            foreach ($tiers as $tier => $cell) {
                $limit = trim(is_string($cell) ? $cell : (is_array($cell) && is_scalar($cell['limit'] ?? null) ? (string) $cell['limit'] : ''));
                if ($limit !== '') {
                    $slot['fields'][] = ['key' => "limit_{$tier}", 'label' => self::named("Limit — {$tier}", $name), 'value' => $limit];
                }
            }
            $slots[] = $slot;
        }

        foreach (SiteHighlight::query()->where('site_id', $site->id)->orderBy('id')->get() as $row) {
            $settings = $row->getAttribute('settings_json');
            $fields = [];
            foreach (array_values(is_array($settings['items'] ?? null) ? $settings['items'] : []) as $i => $item) {
                // Keyed by the card's own id, so dragging cards moves their translations with them.
                $slotKey = is_array($item) && isset($item['id']) && $item['id'] !== '' ? 'i'.$item['id'] : 'p'.($i + 1);
                $fields[] = ['key' => "item_{$slotKey}_title", 'label' => 'Card '.($i + 1).' Title', 'value' => is_array($item) ? (string) ($item['title'] ?? '') : ''];
                $fields[] = ['key' => "item_{$slotKey}_description", 'label' => 'Card '.($i + 1).' Description', 'value' => is_array($item) ? (string) ($item['description'] ?? '') : ''];
            }
            $slots[] = ['section' => 'highlights', 'page_slug' => (string) $row->getAttribute('page_slug'), 'record_id' => $row->id, 'fields' => $fields];
        }

        $slider = SiteSlider::query()->where('site_id', $site->id)->orderBy('id')->first();
        if ($slider !== null) {
            $slots[] = $this->plain('slider-settings', $slider, ['title' => 'Slider Title'], null);
        }

        return $slots;
    }

    /**
     * Hero copy per page: the page overrides in design_json over the base fields.
     *
     * @return list<array<string, mixed>>
     */
    private function heroSlots(SiteSetting $hero): array
    {
        $data = $hero->data ?? [];
        $pages = is_array($data['design_json'] ?? null) && $data['design_json'] !== [] ? $data['design_json'] : ['home' => []];
        $slots = [];
        foreach ($pages as $page => $override) {
            $override = is_array($override) ? $override : [];
            $pick = static fn (string $key): mixed => $override[$key] ?? $data[$key] ?? '';
            $button = static function (string $key) use ($override, $data): string {
                $value = $override[$key] ?? $data[$key] ?? null;
                $value = is_string($value) ? json_decode($value, true) : $value;

                return is_array($value) && is_scalar($value['label'] ?? null) ? (string) $value['label'] : '';
            };
            $slots[] = ['section' => 'hero-section', 'page_slug' => (string) $page, 'record_id' => $hero->id, 'fields' => [
                ['key' => 'badge_text', 'label' => 'Badge Text', 'value' => self::text($pick('badge_text'))],
                ['key' => 'title', 'label' => 'Title', 'value' => self::text($pick('title'))],
                ['key' => 'description', 'label' => 'Description', 'value' => self::text($pick('description'))],
                ['key' => 'button_1_label', 'label' => 'Button 1 Label', 'value' => $button('button_1_json')],
                ['key' => 'button_2_label', 'label' => 'Button 2 Label', 'value' => $button('button_2_json')],
            ]];
        }

        return $slots;
    }

    /**
     * The footer's text, plus one key per quick link: links to pages show the
     * page's title, the rest a label made from the slug — text no table holds.
     *
     * @return array<string, mixed>
     */
    private function footerSlot(SiteSetting $footer): array
    {
        $data = $footer->data ?? [];
        $fields = self::fields([
            'description' => 'Description', 'top_list_heading' => 'Links Heading 1', 'top_list_heading_2' => 'Links Heading 2',
            'copyright_text' => 'Copyright Text', 'powered_by_text' => 'Powered By Text', 'address' => 'Address',
        ], [...['top_list_heading_2' => 'Company'], ...$data]);

        $first = is_array($data['quick_links_json'] ?? null) && $data['quick_links_json'] !== [] ? $data['quick_links_json']
            : (is_array($data['add_pages_json'] ?? null) ? $data['add_pages_json'] : []);
        $links = [...($first !== [] ? $first : ['home', 'features', 'templates', 'gallery', 'contact']), ...(is_array($data['quick_links_2_json'] ?? null) ? $data['quick_links_2_json'] : [])];
        $seen = [];
        foreach ($links as $link) {
            $slug = trim(is_scalar($link) ? (string) $link : '');
            if ($slug === '' || isset($seen[$slug])) {
                continue;
            }
            $seen[$slug] = true;
            $label = implode(' ', array_map('ucfirst', array_filter(preg_split('#[-_/]#', ltrim($slug, '/')) ?: [])));
            $fields[] = ['key' => "quick_link.{$slug}", 'label' => "Footer Link — {$label}", 'value' => $label];
        }

        return ['section' => 'footer', 'page_slug' => '', 'record_id' => $footer->id, 'fields' => $fields];
    }

    /**
     * @param  array<string, string>  $fields  column => label
     * @return array<string, mixed>
     */
    private function plain(string $section, SiteRecord $row, array $fields, mixed $name): array
    {
        $out = [];
        foreach ($fields as $column => $label) {
            $out[] = ['key' => $column, 'label' => self::named($label, $name), 'value' => self::text($row->getAttribute($column))];
        }

        return ['section' => $section, 'page_slug' => '', 'record_id' => (int) $row->getKey(), 'fields' => $out];
    }

    /**
     * Upsert key rows for these slots, batched; with $removeEmpty, fields with
     * no English lose their key.
     *
     * @param  list<array<string, mixed>>  $slots
     * @return array<string, true> the slots written, "section|page|record|field"
     */
    private function writeKeys(Site $site, array $slots, bool $removeEmpty): array
    {
        $now = now();
        $rows = [];
        $seen = [];
        $empty = [];
        foreach ($slots as $slot) {
            $position = 0;
            foreach ($slot['fields'] as $field) {
                if ($field['key'] === '') {
                    continue;
                }
                $address = "{$slot['section']}|{$slot['page_slug']}|{$slot['record_id']}|{$field['key']}";
                if (trim($field['value']) === '') {
                    $empty[] = [$slot['section'], $slot['page_slug'], $slot['record_id'], $field['key']];

                    continue;
                }
                $seen[$address] = true;
                $rows[] = [
                    'site_id' => $site->id, 'section' => mb_substr($slot['section'], 0, 100), 'page_slug' => mb_substr($slot['page_slug'], 0, 100),
                    'record_id' => $slot['record_id'], 'field_key' => mb_substr($field['key'], 0, 100), 'field_label' => mb_substr($field['label'] ?: $field['key'], 0, 150),
                    'default_value' => $field['value'], 'sort_order' => $position++, 'created_at' => $now, 'updated_at' => $now,
                ];
            }
        }
        foreach (array_chunk($rows, self::CHUNK) as $chunk) {
            SiteTranslationKey::query()->upsert($chunk, ['site_id', 'section', 'page_slug', 'record_id', 'field_key'], ['field_label', 'default_value', 'sort_order', 'updated_at']);
        }
        if ($removeEmpty) {
            foreach ($empty as [$section, $page, $record, $field]) {
                SiteTranslationKey::query()->where('site_id', $site->id)->where('section', $section)->where('page_slug', $page)
                    ->where('record_id', $record)->where('field_key', $field)->delete();
            }
        }

        return $seen;
    }

    /**
     * @param  array<string, string>  $labels
     * @param  array<string, mixed>  $data
     * @return list<array{key: string, label: string, value: string}>
     */
    private static function fields(array $labels, array $data): array
    {
        $out = [];
        foreach ($labels as $field => $label) {
            $out[] = ['key' => $field, 'label' => $label, 'value' => self::text($data[$field] ?? '')];
        }

        return $out;
    }

    /** "Title — Floral Love" when the section has several rows. */
    private static function named(string $label, mixed $name): string
    {
        if ($name === null) {
            return $label;
        }
        $plain = trim(strip_tags(is_scalar($name) ? (string) $name : ''));
        if ($plain === '') {
            return $label;
        }

        return $label.' — '.(mb_strlen($plain) > 30 ? mb_substr($plain, 0, 30).'…' : $plain);
    }

    /**
     * The texts of a JSON list: plain strings or {label|text|title|name|value}
     * objects (production's plan features are {label, included}). Position is
     * kept — a blank entry still occupies its number.
     *
     * @return list<string>
     */
    private static function listTexts(mixed $list): array
    {
        $list = is_string($list) ? json_decode($list, true) : $list;
        if (! is_array($list)) {
            return [];
        }

        return array_map(static function (mixed $entry): string {
            if (is_string($entry)) {
                return trim($entry);
            }
            if (is_array($entry)) {
                foreach (['label', 'text', 'title', 'name', 'value'] as $prop) {
                    if (is_string($entry[$prop] ?? null) && trim($entry[$prop]) !== '') {
                        return trim($entry[$prop]);
                    }
                }
            }

            return '';
        }, array_values($list));
    }

    /**
     * Whether the language already has text for this key.
     *
     * @param  list<array{language_id: int, value: string|null, status: string}>  $translations
     */
    private static function translated(array $translations, int $languageId): bool
    {
        foreach ($translations as $translation) {
            if ($translation['language_id'] === $languageId && trim((string) $translation['value']) !== '') {
                return true;
            }
        }

        return false;
    }

    private static function text(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    /** The translation service sometimes pads its answer ("seemantha\n\n\n"). */
    private static function clean(mixed $value): string
    {
        $text = str_replace("\r", '', is_scalar($value) ? (string) $value : '');
        $text = (string) preg_replace('/[ \t]+\n/', "\n", $text);

        return trim((string) preg_replace('/\n{3,}/', "\n\n", $text));
    }

    /** @return array<string, mixed> a language row as v1 sent it */
    private static function language(SiteLanguage $language): array
    {
        return array_diff_key($language->attributesToArray(), ['site_id' => true]);
    }

    /** @return Builder<SiteLanguage> */
    private function languageQuery(Site $site): Builder
    {
        return SiteLanguage::query()->where('site_id', $site->id);
    }

    /** @return Collection<int, SiteLanguage> */
    private function targetLanguages(Site $site): Collection
    {
        return $this->languageQuery($site)->where('is_default', 0)->where('is_active', 1)->orderBy('sort_order')->orderBy('id')->get();
    }

    private function findLanguage(Site $site, int $id): SiteLanguage
    {
        return $this->languageQuery($site)->find($id) ?? throw ApiException::notFound('Language not found');
    }

    private function findKey(Site $site, int $id): SiteTranslationKey
    {
        return SiteTranslationKey::query()->where('site_id', $site->id)->find($id) ?? throw ApiException::notFound('Translation key not found');
    }

    /** @return Builder<SiteTranslation> */
    private function slotQuery(Site $site, string $section, string $pageSlug, int $recordId): Builder
    {
        return SiteTranslation::query()->where('site_id', $site->id)->where('section', $section)->where('page_slug', $pageSlug)->where('record_id', $recordId);
    }
}
