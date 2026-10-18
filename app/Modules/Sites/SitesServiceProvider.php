<?php

declare(strict_types=1);

namespace App\Modules\Sites;

use App\Core\Approvals\ApprovalRegistry;
use App\Core\Support\ModuleServiceProvider;
use App\Core\Tenancy\Tenancy;
use App\Modules\Clients\Actions\SocialSignIn;
use App\Modules\Sites\Actions\ColorPaletteWriter;
use App\Modules\Sites\Actions\FaqCategoryWriter;
use App\Modules\Sites\Actions\FaqWriter;
use App\Modules\Sites\Models\Site;
use App\Modules\Sites\Support\SiteSections;
use App\Modules\Vendors\Models\Vendor;

/**
 * Sites: the website builder (the admin panel's marketing site and every
 * vendor's own site, one set of tables), the public site, content
 * translation, the admin FAQ screen and colour palettes.
 */
final class SitesServiceProvider extends ModuleServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(SiteSections::class);
    }

    protected function bootModule(): void
    {
        $approvals = $this->app->make(ApprovalRegistry::class);
        $approvals->register('faqs', 'faqs', FaqWriter::class);
        $approvals->register('faq_categories', 'faq_categories', FaqCategoryWriter::class);
        $approvals->register('color_palettes', 'color_palettes', ColorPaletteWriter::class);

        // A vendor's public forms work on its site's address as well as its name.
        Vendor::resolveSlugsUsing(static fn (string $slug): ?int => Tenancy::bypass(static function () use ($slug): ?int {
            $id = Site::query()->where('kind', Site::VENDOR)->where('is_active', 1)
                ->where(static fn ($q) => $q->where('slug', $slug)->orWhere('custom_domain', $slug))->value('vendor_id');

            return $id === null ? null : (int) $id;
        }));

        // "Continue with Google" may return to any site's own domain.
        $this->app->make(SocialSignIn::class)->allowHosts(static fn (string $host): bool => Tenancy::bypass(
            static fn (): bool => Site::query()->where('custom_domain', $host)->where('is_active', 1)->exists(),
        ));
    }
}
