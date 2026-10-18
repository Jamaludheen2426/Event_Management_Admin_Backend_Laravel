<?php

declare(strict_types=1);

namespace App\Modules\Sites\Actions;

use App\Core\Exceptions\ApiException;
use App\Core\Support\Flag;
use App\Core\Tenancy\Tenancy;
use App\Modules\Sites\Models\Site;
use App\Modules\Sites\Models\SitePage;
use App\Modules\Sites\Models\SiteTemplateCategory;
use App\Modules\Vendors\Models\Vendor;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Which site a request works on, creating it the first time it is needed,
 * and the site row itself (address, status, theme, publishing).
 *
 *  - The admin panel's Website Builder edits the marketing site of the house
 *    vendor (the current vendor of every admin request).
 *  - The builder app edits the signed-in vendor's own vendor site.
 */
final class Sites
{
    /** The fixed pages every vendor site starts with (v1 SYSTEM_PAGE_SEEDS). */
    private const SYSTEM_PAGES = [
        ['about-us', 'About Us', '<h2>About Us</h2><p>Tell visitors about your team, story, and event planning experience.</p>'],
        ['service', 'Service', '<h2>Our Service</h2><p>Describe the event service, packages, and experiences you offer.</p>'],
        ['events', 'Events', '<h2>Events</h2><p>Showcase weddings, corporate events, celebrations, and other event categories.</p>'],
        ['terms-conditions', 'Terms & Conditions', '<h2>Terms &amp; Conditions</h2><p>Add the terms and conditions visitors agree to when signing up or using your website.</p>'],
        ['privacy-policy', 'Privacy Policy', "<h2>Privacy Policy</h2><p>Explain how you collect, use, and protect your visitors' personal information.</p>"],
        ['maintenance', 'Maintenance', "<p>We're currently performing some upgrades to improve your experience.</p><p>Please check back soon.</p>"],
    ];

    /** The template showcase's first categories (v1 seeded them on the first read). */
    private const TEMPLATE_CATEGORIES = [
        ['Wedding', 'wedding', 'Royal wedding & marriage invitation templates.', '#6A38F5'],
        ['Engagement', 'engagement', 'Roka & engagement party invitations.', '#EC4899'],
        ['Birthday', 'birthday', 'Birthday party & milestone celebration templates.', '#3B82F6'],
        ['Anniversary', 'anniversary', 'Wedding anniversary & vow renewal templates.', '#10B981'],
        ['Baby Shower', 'baby-shower', 'Welcome baby & gender reveal invitations.', '#F59E0B'],
        ['Corporate', 'corporate', 'Business conference, gala & summit invites.', '#6366F1'],
        ['Festival', 'festival', 'Diwali, Eid, Christmas & seasonal greetings.', '#EF4444'],
    ];

    private const STATUSES = ['draft', 'published', 'maintenance'];

    /**
     * The house vendor's marketing site (admin Website Builder), created on
     * first use. Always the house vendor's, whoever asks (open reads have no
     * signed-in tenant).
     */
    public function marketing(): Site
    {
        $house = Vendor::houseId();
        $site = Tenancy::bypass(static fn (): ?Site => Site::query()->where('vendor_id', $house)->where('kind', Site::MARKETING)->first());
        if ($site !== null) {
            return $site;
        }

        return DB::transaction(function () use ($house): Site {
            $site = Site::query()->create([
                'vendor_id' => $house,
                'kind' => Site::MARKETING,
                'slug' => $this->freeSlug('company-'.(int) config('tenancy.house_company_id')),
                'status' => 'published',
            ]);
            foreach (self::TEMPLATE_CATEGORIES as $i => [$name, $slug, $description, $color]) {
                SiteTemplateCategory::query()->create([
                    'site_id' => $site->id, 'name' => $name, 'slug' => $slug, 'description' => $description, 'color' => $color, 'sort_order' => $i + 1,
                ]);
            }

            return $site;
        });
    }

    /** The current vendor's own site, null until it is set up. */
    public function vendorSite(): ?Site
    {
        return Site::query()->where('kind', Site::VENDOR)->first();
    }

    /**
     * The current vendor's site, set up when missing: "<company-name>-<vendor id>",
     * a draft, with the fixed pages (v1 ensureWebsite).
     *
     * @param  array<string, mixed>  $input
     */
    public function ensureVendorSite(Vendor $vendor, array $input = []): Site
    {
        $site = $this->vendorSite();
        if ($site !== null) {
            return $site;
        }

        return DB::transaction(function () use ($vendor, $input): Site {
            $deleted = Site::onlyTrashed()->where('kind', Site::VENDOR)->latest('id')->first();
            if ($deleted !== null) {
                $deleted->restore();
                $deleted->forceFill(['is_active' => 1])->save();
                $this->seedPages($deleted);

                return $deleted;
            }

            $base = Str::slug((string) $vendor->company_name) ?: 'vendor';
            $site = new Site(['kind' => Site::VENDOR, 'status' => 'draft', 'slug' => $this->freeSlug("{$base}-{$vendor->id}")]);
            $site->fill($this->siteValues($input, null));
            $site->save();
            $this->seedPages($site);

            return $site;
        });
    }

    /**
     * Address, status, theme and the builder's settings document.
     *
     * @param  array<string, mixed>  $input
     */
    public function update(Site $site, array $input): Site
    {
        $site->fill($this->siteValues($input, $site))->save();
        $this->changed($site);

        return $site;
    }

    /** v1 publish: the next version number, published now. */
    public function publish(Site $site): Site
    {
        $site->forceFill(['status' => 'published', 'publish_version' => $site->publish_version + 1, 'published_at' => now()])->save();
        $this->changed($site);

        return $site;
    }

    /**
     * The site row as v1 sent it.
     *
     * @return array<string, mixed>
     */
    public function present(Site $site): array
    {
        return [...array_diff_key($site->attributesToArray(), array_flip(['alive', 'deleted_at'])), 'is_active' => (int) $site->is_active];
    }

    /** Something on the site changed: cached public copies are stale. */
    public function changed(Site $site): void
    {
        Cache::forever(self::versionKey($site->id), (string) Str::uuid());
    }

    /** Changes every time the site's content does (the public bundle's cache key). */
    public function version(int $siteId): string
    {
        return (string) Cache::rememberForever(self::versionKey($siteId), static fn (): string => (string) Str::uuid());
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function siteValues(array $input, ?Site $site): array
    {
        $values = [];
        if (array_key_exists('slug', $input)) {
            $slug = Str::slug((string) $input['slug']);
            if ($slug === '') {
                throw ApiException::badRequest('A web address is required.');
            }
            $this->assertFree('slug', $slug, $site);
            $values['slug'] = $slug;
        }
        if (array_key_exists('custom_domain', $input)) {
            $domain = self::host((string) $input['custom_domain']);
            if ($domain !== '') {
                $this->assertFree('custom_domain', $domain, $site);
            }
            $values['custom_domain'] = $domain === '' ? null : $domain;
        }
        if (array_key_exists('status', $input) && ($status = Flag::oneOf($input['status'], self::STATUSES, null)) !== null) {
            $values['status'] = $status;
        }
        if (array_key_exists('theme_id', $input)) {
            $values['theme_id'] = is_scalar($input['theme_id']) && (string) $input['theme_id'] !== '' ? (string) $input['theme_id'] : null;
        }
        if (array_key_exists('palette_id', $input)) {
            $values['palette_id'] = Flag::id($input['palette_id']);
        }
        if (array_key_exists('settings_json', $input)) {
            $settings = is_string($input['settings_json']) ? json_decode($input['settings_json'], true) : $input['settings_json'];
            $values['settings_json'] = is_array($settings) ? $settings : null;
        }
        if (array_key_exists('is_active', $input)) {
            $values['is_active'] = Flag::bit($input['is_active'], 1);
        }

        return $values;
    }

    /** Host form of a domain: lower case, no scheme, path, port or "www.". */
    public static function host(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $value = (string) preg_replace('#^[a-z]+://#', '', $value);
        $value = explode('/', $value)[0];
        $value = explode(':', $value)[0];

        return (string) preg_replace('/^www\./', '', $value);
    }

    private function assertFree(string $column, string $value, ?Site $site): void
    {
        $taken = Tenancy::bypass(static fn (): bool => Site::query()->where($column, $value)
            ->when($site !== null, static fn (Builder $q) => $q->whereKeyNot($site?->id))->exists());
        if ($taken) {
            throw ApiException::conflict($column === 'slug' ? 'This web address is already taken.' : 'This domain is already used by another site.');
        }
    }

    private function freeSlug(string $slug): string
    {
        $taken = Tenancy::bypass(static fn (): array => Site::withTrashed()->where('slug', 'like', $slug.'%')->pluck('slug')->all());
        $candidate = $slug;
        for ($i = 2; in_array($candidate, $taken, true); $i++) {
            $candidate = "{$slug}-{$i}";
        }

        return $candidate;
    }

    private function seedPages(Site $site): void
    {
        $existing = SitePage::query()->where('site_id', $site->id)->pluck('slug')->all();
        foreach (self::SYSTEM_PAGES as $i => [$slug, $title, $content]) {
            if (! in_array($slug, $existing, true)) {
                SitePage::query()->create([
                    'site_id' => $site->id, 'page_type' => 'system', 'title' => $title, 'slug' => $slug, 'content' => $content,
                    'status' => 'published', 'sort_order' => $i + 1, 'is_system' => 1,
                ]);
            }
        }
    }

    private static function versionKey(int $siteId): string
    {
        return "sites:version:{$siteId}";
    }
}
