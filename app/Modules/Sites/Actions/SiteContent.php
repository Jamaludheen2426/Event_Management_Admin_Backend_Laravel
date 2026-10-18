<?php

declare(strict_types=1);

namespace App\Modules\Sites\Actions;

use App\Core\Contracts\ObjectStorage;
use App\Core\Exceptions\ApiException;
use App\Core\Support\Flag;
use App\Modules\Sites\Models\Site;
use App\Modules\Sites\Models\SiteBlock;
use App\Modules\Sites\Models\SiteContactCategory;
use App\Modules\Sites\Models\SiteFeature;
use App\Modules\Sites\Models\SiteHighlight;
use App\Modules\Sites\Models\SiteRecord;
use App\Modules\Sites\Models\SiteSetting;
use App\Modules\Sites\Support\Section;
use App\Modules\Sites\Support\SiteSections;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Reads and writes a site's sections — the same steps for both builders and
 * every section, which is what v1's two generic services did over 56 tables.
 *
 * Input arrives in v1 keys (SnakeCaseInput has already snake_cased them);
 * the alternative names v1 accepted, ENUM coercion on vendor sites and links
 * to rows of other sites are all handled here, so a section's table only ever
 * holds values that make sense for that site.
 */
final class SiteContent
{
    /** Columns a caller never sets through a section. */
    private const PROTECTED = ['id', 'site_id', 'approval_status', 'created_by', 'updated_by', 'created_at', 'updated_at'];

    /** Number columns: a blank value from a cleared form field means 0. */
    private const NUMBERS = [
        'sort_order', 'menu_order', 'step_number', 'autoplay_speed', 'duration_seconds', 'price_monthly', 'price_yearly', 'rating',
    ];

    /** Folders for pasted images in the single-row sections (v1 TABLE_S3_FOLDERS). */
    private const SETTING_FOLDERS = [
        'basic-information' => 'website-builder/logos', 'hero' => 'website-builder/hero', 'footer' => 'website-builder/footer',
        'seo' => 'website-builder/seo', 'login' => 'website-builder/login', 'contact' => 'website-builder/contact',
    ];

    /** @var array<int, int|null> site id => its contact settings row id */
    private array $contactSettingIds = [];

    public function __construct(
        private readonly SiteSections $sections,
        private readonly Sites $sites,
        private readonly ObjectStorage $storage,
    ) {}

    // ---- single-row sections ----------------------------------------------

    /** @return array<string, mixed>|null null until the section is first saved (v1) */
    public function setting(Site $site, string $section): ?array
    {
        $row = SiteSetting::query()->where('site_id', $site->id)->where('section', $section)->first();

        return $row === null ? null : $this->presentSetting($site, $row);
    }

    /**
     * Save the fields given; the others keep their value (v1 upsertSingleton).
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function saveSetting(Site $site, string $section, array $input): array
    {
        $spec = SiteSections::SETTINGS[$section] ?? throw ApiException::notFound('Section not found');
        $input = $this->settingInput($section, $input);

        $values = [];
        foreach (array_keys($spec['fields']) as $field) {
            if (array_key_exists($field, $input)) {
                $values[$field] = $input[$field];
            }
        }
        foreach ($spec['flags'] ?? [] as $flag) {
            if (array_key_exists($flag, $values)) {
                $values[$flag] = Flag::bit($values[$flag], 1);
            }
        }
        foreach ($spec['json'] ?? [] as $field) {
            if (array_key_exists($field, $values)) {
                $values[$field] = self::decoded($values[$field]);
            }
        }
        if (! $site->isMarketing()) {
            foreach ($spec['enums'] ?? [] as $field => $allowed) {
                if (array_key_exists($field, $values)) {
                    $coerced = self::enum($values[$field], $allowed);
                    if ($coerced === null) {
                        unset($values[$field]);
                    } else {
                        $values[$field] = $coerced;
                    }
                }
            }
        }
        $values = $this->storeImages($values, self::SETTING_FOLDERS[$section] ?? 'website-builder');

        $row = SiteSetting::query()->firstOrNew(['site_id' => $site->id, 'section' => $section]);
        $row->data = [...($row->data ?? []), ...$values];
        if (array_key_exists('is_active', $input)) {
            $row->is_active = Flag::bit($input['is_active'], 1);
        }
        $row->save();
        $this->sites->changed($site);

        return $this->presentSetting($site, $row->refresh());
    }

    /** @return array<string, mixed> */
    public function presentSetting(Site $site, SiteSetting $row): array
    {
        $spec = SiteSections::SETTINGS[$row->section];
        $data = array_intersect_key($row->data ?? [], $spec['fields']);
        $fields = [...SiteSections::settingDefaults($row->section, $site), ...$data];
        foreach ($spec['flags'] ?? [] as $flag) {
            $fields[$flag] = Flag::bit($fields[$flag], 1);
        }

        $attributes = $row->attributesToArray();

        return [
            'id' => $row->id,
            ...$fields,
            'is_active' => (int) $row->is_active,
            'created_at' => $attributes['created_at'] ?? null,
            'updated_at' => $attributes['updated_at'] ?? null,
        ];
    }

    // ---- list sections ----------------------------------------------------

    /**
     * The section's rows, in its order, narrowed by the request's filters.
     *
     * @param  array<string, mixed>  $where
     * @return list<array<string, mixed>>
     */
    public function rows(Site $site, string $key, ?Request $request = null, array $where = []): array
    {
        $section = $this->sections->list($key);
        $query = $this->query($site, $section)->where($where)->with($section->with);
        if ($request !== null && $section->filter !== null) {
            ($section->filter)($query, $request);
        }
        foreach ($section->order as [$column, $direction]) {
            $query->orderBy($column, $direction);
        }

        return $this->presentAll($site, $key, $query->get());
    }

    /** @return array<string, mixed> */
    public function show(Site $site, string $key, int $id): array
    {
        $section = $this->sections->list($key);

        return $this->present($site, $key, $this->find($site, $section, $id)->load($section->with));
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>  $extra  values the route fixes (a slide's slider)
     * @return array<string, mixed>
     */
    public function create(Site $site, string $key, array $input, array $extra = []): array
    {
        $section = $this->sections->list($key);
        $model = DB::transaction(fn (): SiteRecord => $this->insert($site, $section, $input, $extra));
        $this->sites->changed($site);

        return $this->present($site, $key, $model->refresh()->load($section->with));
    }

    /**
     * Change the fields given; the others keep their value.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function update(Site $site, string $key, int $id, array $input): array
    {
        $section = $this->sections->list($key);
        $model = $this->find($site, $section, $id);
        $values = $this->values($site, $section, $input, $model);
        $this->assertUnique($site, $section, $values, $model);
        $model->fill($values)->save();
        $this->sites->changed($site);

        return $this->present($site, $key, $model->refresh()->load($section->with));
    }

    /**
     * The list's on/off switch (v1 PATCH /:id/status).
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function setActive(Site $site, string $key, int $id, array $input): array
    {
        $section = $this->sections->list($key);
        $model = $this->find($site, $section, $id);
        $answer = ['id' => $id];

        if ($model instanceof SiteFeature) {
            // Features carry a status word too (Active / Inactive / Draft).
            $active = array_key_exists('is_active', $input) ? Flag::bit($input['is_active']) === 1 : ($input['status'] ?? null) === 'Active';
            $status = is_string($input['status'] ?? null) && $input['status'] !== '' ? $input['status'] : ($active ? 'Active' : 'Inactive');
            $model->forceFill(['is_active' => $active ? 1 : 0, 'status' => $status])->save();
            $answer += ['is_active' => $active, 'status' => $status];
        } else {
            $active = Flag::bit($input['is_active'] ?? null, 1) === 1;
            $model->forceFill(['is_active' => $active ? 1 : 0])->save();
            $answer['is_active'] = $active;
        }
        $this->sites->changed($site);

        return $answer;
    }

    public function delete(Site $site, string $key, int $id): void
    {
        $model = $this->find($site, $this->sections->list($key), $id);
        // The contact form's fallback subject (v1 kept it on the vendor builder).
        if ($model instanceof SiteContactCategory && in_array(mb_strtolower(trim((string) $model->getAttribute('name'))), ['other', 'others'], true)) {
            throw ApiException::badRequest('The "Other" category cannot be deleted.');
        }
        $model->delete();
        $this->sites->changed($site);
    }

    /**
     * Save the whole list at once (v1 bulk PUT). Rows sent with their id are
     * updated in place, new ones added, the rest removed — v1 deleted and
     * re-inserted every row, which gave each a new id and so detached its
     * translations and the contact page's links to it.
     *
     * Menu items may refer to a parent sent in the same list by client_id /
     * parent_client_id (the builder's unsaved ids).
     *
     * @param  mixed  $items  the body's items
     * @param  array<string, mixed>  $scope  narrows which rows the list replaces (a slider's slides)
     * @return list<array<string, mixed>>
     */
    public function replace(Site $site, string $key, mixed $items, array $scope = []): array
    {
        $section = $this->sections->list($key);
        $items = array_values(array_filter(is_array($items) ? $items : [], 'is_array'));

        DB::transaction(function () use ($site, $section, $key, $items, $scope): void {
            $existing = $this->query($site, $section)->where($scope)->get()->keyBy('id');
            $kept = [];
            $clientIds = [];

            // Parents first, so children sent in the same list can find them.
            $ordered = $items;
            usort($ordered, static fn (array $a, array $b): int => (isset($a['parent_client_id']) ? 1 : 0) <=> (isset($b['parent_client_id']) ? 1 : 0));

            foreach ($ordered as $item) {
                $position = array_search($item, $items, true);
                $item['sort_order'] ??= (is_int($position) ? $position : 0) + 1;
                if (isset($item['parent_client_id']) && isset($clientIds[(string) $item['parent_client_id']])) {
                    $item['parent_id'] = $clientIds[(string) $item['parent_client_id']];
                }
                if ($key === 'contactSocialLinks' && $this->referenced($site, $section, 'social_link_id', $item['social_link_id'] ?? null) === null) {
                    continue;
                }

                $id = Flag::id($item['id'] ?? null);
                $model = $id !== null ? $existing->get($id) : null;
                if ($model !== null) {
                    $model->fill($this->values($site, $section, $item, $model))->save();
                } else {
                    $model = $this->insert($site, $section, $item, $scope);
                }
                $kept[] = (int) $model->getKey();
                if (isset($item['client_id']) && is_scalar($item['client_id'])) {
                    $clientIds[(string) $item['client_id']] = (int) $model->getKey();
                }
            }

            $this->query($site, $section)->where($scope)->whereKeyNot($kept)->delete();
        });
        $this->sites->changed($site);

        return $this->rows($site, $key, null, $scope);
    }

    // ---- page blocks and highlights -----------------------------------------

    /** @return list<array<string, mixed>> */
    public function blocks(Site $site, string $pageSlug = ''): array
    {
        $rows = SiteBlock::query()->where('site_id', $site->id)->where('page_slug', $pageSlug)->orderBy('sort_order')->orderBy('id')->get();

        return array_values($rows->map(fn (SiteBlock $b): array => $this->strip($b->attributesToArray(), []))->all());
    }

    /** @return list<array<string, mixed>> every page's blocks (the public bundle) */
    public function allBlocks(Site $site): array
    {
        $rows = SiteBlock::query()->where('site_id', $site->id)->orderBy('page_slug')->orderBy('sort_order')->orderBy('id')->get();

        return array_values($rows->map(fn (SiteBlock $b): array => $this->strip($b->attributesToArray(), []))->all());
    }

    /**
     * Which blocks a page shows, in order: every block sent is kept (matched by
     * its key), the others are removed. The admin screen sends {id: key,
     * visible}, the builder app {block_key, is_visible, ...}.
     *
     * @return list<array<string, mixed>>
     */
    public function saveBlocks(Site $site, mixed $items, string $pageSlug = ''): array
    {
        $byKey = [];
        foreach (array_values(array_filter(is_array($items) ? $items : [], 'is_array')) as $i => $item) {
            $key = trim(is_scalar($item['block_key'] ?? $item['id'] ?? null) ? (string) ($item['block_key'] ?? $item['id']) : '');
            if ($key === '') {
                $key = "block_{$i}";
            }
            $visible = array_key_exists('visible', $item) ? $item['visible'] : ($item['is_visible'] ?? 1);
            $row = ['is_visible' => Flag::bit($visible, 1), 'sort_order' => (int) ($item['sort_order'] ?? $i + 1)];
            foreach (['label', 'variant_key'] as $column) {
                if (array_key_exists($column, $item)) {
                    $row[$column] = is_scalar($item[$column]) ? (string) $item[$column] : null;
                }
            }
            foreach (['config_json', 'design_json', 'mobile_json'] as $column) {
                if (array_key_exists($column, $item)) {
                    $row[$column] = self::decoded($item[$column]);
                }
            }
            if (array_key_exists('is_active', $item)) {
                $row['is_active'] = Flag::bit($item['is_active'], 1);
            }
            $byKey[mb_substr($key, 0, 100)] = $row;
        }

        DB::transaction(static function () use ($site, $pageSlug, $byKey): void {
            SiteBlock::query()->where('site_id', $site->id)->where('page_slug', $pageSlug)->whereNotIn('block_key', array_keys($byKey))->delete();
            foreach ($byKey as $key => $row) {
                SiteBlock::query()->updateOrCreate(['site_id' => $site->id, 'page_slug' => $pageSlug, 'block_key' => $key], $row);
            }
        });
        $this->sites->changed($site);

        return $this->blocks($site, $pageSlug);
    }

    /**
     * One page's highlight cards: the editor's document with the row's id,
     * page and position laid back over it (translations hang off the id).
     *
     * @return array<string, mixed>|null
     */
    public function highlights(Site $site, string $pageSlug, int $instance): ?array
    {
        $row = SiteHighlight::query()->where('site_id', $site->id)->where('page_slug', $pageSlug)->where('instance', $instance)->first();

        return $row === null ? null : self::highlight($row);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function saveHighlights(Site $site, array $input): array
    {
        $pageSlug = is_string($input['page_slug'] ?? null) && $input['page_slug'] !== '' ? $input['page_slug'] : 'home';
        $instance = Flag::clamp($input['instance'] ?? null, 1, 1000, 1);
        $input = $this->storeImages($input, 'website-builder/highlights');

        $row = SiteHighlight::query()->updateOrCreate(
            ['site_id' => $site->id, 'page_slug' => mb_substr($pageSlug, 0, 100), 'instance' => $instance],
            ['settings_json' => $input],
        );
        $this->sites->changed($site);

        return [...$input, 'id' => $row->id, 'page_slug' => $row->getAttribute('page_slug'), 'instance' => $instance];
    }

    /** @return array<string, mixed> */
    public static function highlight(SiteHighlight $row): array
    {
        $settings = $row->getAttribute('settings_json');

        return [...(is_array($settings) ? $settings : []), 'id' => $row->id, 'page_slug' => $row->getAttribute('page_slug'), 'instance' => (int) $row->getAttribute('instance')];
    }

    // ---------------------------------------------------------------------

    /** The id, when it is a row of this site in that section (else 404). */
    public function owned(Site $site, string $key, int $id): int
    {
        return (int) $this->find($site, $this->sections->list($key), $id)->getKey();
    }

    /** @return SiteRecord the row, when it is this site's and in this section */
    public function find(Site $site, Section $section, int $id): SiteRecord
    {
        return $this->query($site, $section)->whereKey($id)->first()
            ?? throw ApiException::notFound($section->message('missing', 'Record not found'));
    }

    /** @return Builder<SiteRecord> */
    private function query(Site $site, Section $section): Builder
    {
        /** @var Builder<SiteRecord> $query */
        $query = $section->model::query()->where('site_id', $site->id);

        return $query->where($section->fixed);
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>  $extra
     */
    private function insert(Site $site, Section $section, array $input, array $extra): SiteRecord
    {
        $values = $this->values($site, $section, [...$input, ...$extra], null);
        $defaults = $section->defaults !== null ? ($section->defaults)($site, $input) : [];
        $values = [...$defaults, ...array_filter($values, static fn (mixed $v): bool => $v !== null), ...array_intersect_key($values, $extra)];
        foreach (['slider_id' => 'Slider not found', 'social_link_id' => 'Social link not found'] as $required => $message) {
            if (in_array($required, (new $section->model)->getFillable(), true) && ($values[$required] ?? null) === null) {
                throw ApiException::badRequest($message);
            }
        }
        $this->assertUnique($site, $section, $values, null);

        /** @var SiteRecord $model */
        $model = new $section->model;
        $model->fill([...$values, ...$section->fixed, 'site_id' => $site->id])->save();

        return $model;
    }

    /**
     * The caller's input as this section's column values.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function values(Site $site, Section $section, array $input, ?SiteRecord $existing): array
    {
        foreach ($section->aliases as $alias => $column) {
            if (array_key_exists($alias, $input) && ! array_key_exists($column, $input)) {
                $input[$column] = $input[$alias];
            }
        }
        if ($section->normalize !== null) {
            $input = ($section->normalize)($input, $site);
        }

        $model = $existing ?? new $section->model;
        $columns = array_diff($model->getFillable(), self::PROTECTED, array_keys($section->fixed));
        $values = array_intersect_key($input, array_flip($columns));

        foreach ($values as $column => $value) {
            if (isset($section->references[$column])) {
                $values[$column] = $this->referenced($site, $section, $column, $value);
            } elseif (! $site->isMarketing() && isset($section->vendorEnums[$column])) {
                $coerced = self::enum($value, $section->vendorEnums[$column]);
                if ($coerced === null) {
                    unset($values[$column]);

                    continue;
                }
                $values[$column] = $coerced;
            } elseif ($model->hasCast($column, ['array'])) {
                $values[$column] = self::decoded($value);
            } elseif ($value === '' && in_array($column, self::NUMBERS, true)) {
                // v1: `p.price_monthly || 0` — a cleared number field is 0, not an SQL error.
                $values[$column] = 0;
            } elseif ($value === '' && $column === 'publish_date') {
                $values[$column] = null;
            } elseif (is_bool($value) || preg_match('/^(is|show|allow)_|^autoplay$/', $column) === 1 && $value !== null) {
                $values[$column] = Flag::bit($value);
            } elseif (is_array($value)) {
                unset($values[$column]);
            }
        }

        return $this->storeImages($values, $section->folder);
    }

    /** An id of a row of this site in the referenced table, else null (v1 coerceNullableInt, plus ownership). */
    private function referenced(Site $site, Section $section, string $column, mixed $value): ?int
    {
        $id = Flag::id($value);
        $model = $section->references[$column];

        return $id !== null && $model::query()->where('site_id', $site->id)->whereKey($id)->exists() ? $id : null;
    }

    /** @param array<string, mixed> $values */
    private function assertUnique(Site $site, Section $section, array $values, ?SiteRecord $existing): void
    {
        $message = $section->messages['unique'] ?? null;
        if ($message === null || ! isset($values['slug'])) {
            return;
        }
        $taken = $section->model::query()->where('site_id', $site->id)->where('slug', $values['slug'])
            ->when($existing !== null, static fn (Builder $q) => $q->whereKeyNot($existing?->getKey()))->exists();
        if ($taken) {
            throw ApiException::badRequest($message);
        }
    }

    /**
     * @param  Collection<int, SiteRecord>  $models
     * @return list<array<string, mixed>>
     */
    private function presentAll(Site $site, string $key, Collection $models): array
    {
        return array_values($models->map(fn (SiteRecord $m): array => $this->present($site, $key, $m))->all());
    }

    /** @return array<string, mixed> */
    private function present(Site $site, string $key, SiteRecord $model): array
    {
        $section = $this->sections->list($key);
        $row = $this->strip($model->attributesToArray(), $section->hidden);
        if ($section->present !== null) {
            $row = ($section->present)($row, $model);
        }
        if ($key === 'contactSocialLinks') {
            $row['contact_setting_id'] = $this->contactSettingIds[$site->id]
                ??= SiteSetting::query()->where('site_id', $site->id)->where('section', 'contact')->value('id');
        }

        return $row;
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  list<string>  $hidden
     * @return array<string, mixed>
     */
    private function strip(array $row, array $hidden): array
    {
        return array_diff_key($row, array_flip(['site_id', ...$hidden]));
    }

    /**
     * v1 normalizeInput for the single-row sections: the alternative names,
     * plus the vendor builder's robots and mobile hero switches.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function settingInput(string $section, array $input): array
    {
        foreach (SiteSections::SETTINGS[$section]['aliases'] ?? [] as $alias => $field) {
            if (array_key_exists($alias, $input) && ! array_key_exists($field, $input)) {
                $input[$field] = $input[$alias];
            }
        }

        if ($section === 'seo' && isset($input['robots_meta'])) {
            $meta = (string) $input['robots_meta'];
            $input['robots_index'] ??= $meta === 'noindex-nofollow' ? 0 : 1;
            $input['robots_follow'] ??= in_array($meta, ['noindex-nofollow', 'index-nofollow'], true) ? 0 : 1;
        }

        if ($section === 'hero' && ! array_key_exists('mobile_settings_json', $input)) {
            $mobile = array_filter([
                'hideBtn2Mobile' => $input['hide_btn2_mobile'] ?? null,
                'centerMobile' => $input['center_mobile'] ?? null,
                'mobileHeroHeight' => $input['mobile_hero_height'] ?? null,
            ], static fn (mixed $v): bool => $v !== null);
            if ($mobile !== []) {
                $input['mobile_settings_json'] = $mobile;
            }
        }

        return $input;
    }

    /**
     * Pasted images (data:image/... text) become stored files (v1 converted
     * them to S3 URLs on save), however deep they sit in a JSON document.
     *
     * @param  array<array-key, mixed>  $values
     * @return array<array-key, mixed>
     */
    private function storeImages(array $values, string $folder): array
    {
        foreach ($values as $key => $value) {
            if (is_string($value) && str_starts_with($value, 'data:image/')) {
                $values[$key] = $this->storage->storeDataUri($value, $folder, basename($folder));
            } elseif (is_array($value)) {
                $values[$key] = $this->storeImages($value, $folder);
            }
        }

        return $values;
    }

    /** A JSON column's value: text holding JSON is decoded (v1 stored such text as is). */
    private static function decoded(mixed $value): mixed
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);

            return json_last_error() === JSON_ERROR_NONE ? $decoded : $value;
        }

        return $value;
    }

    /** @param list<string> $allowed v1 coerceEnum: "_self" → "self"; anything else not allowed → null */
    private static function enum(mixed $value, array $allowed): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }
        $value = mb_strtolower(trim((string) $value));
        if (in_array($value, $allowed, true)) {
            return $value;
        }
        $stripped = ltrim($value, '_-');

        return in_array($stripped, $allowed, true) ? $stripped : null;
    }
}
