<?php

declare(strict_types=1);

namespace App\Modules\Platform;

use App\Core\Approvals\ApprovalRegistry;
use App\Core\Contracts\ObjectStorage;
use App\Core\Support\ModuleServiceProvider;
use App\Modules\Platform\Actions\Reference\CityWriter;
use App\Modules\Platform\Actions\Reference\CountryWriter;
use App\Modules\Platform\Actions\Reference\CurrencyWriter;
use App\Modules\Platform\Actions\Reference\DistrictWriter;
use App\Modules\Platform\Actions\Reference\LanguageWriter;
use App\Modules\Platform\Actions\Reference\StateWriter;
use App\Modules\Platform\Actions\SettingWriter;
use App\Modules\Platform\Actions\Translations\UiTranslations;
use App\Modules\Platform\Media\KnownUploads;
use App\Modules\Platform\Media\MediaSettings;
use App\Modules\Platform\Media\MediaStorage;
use App\Modules\Platform\Support\PluginUsage;

/**
 * Platform: companies, settings, plugins, languages, currencies, locations,
 * media storage, admin UI translations.
 *
 * Exposes to other modules: ObjectStorage (uploads), KnownUploads and
 * PluginUsage (registries other modules fill).
 */
final class PlatformServiceProvider extends ModuleServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(MediaSettings::class);
        $this->app->scoped(MediaStorage::class);
        $this->app->scoped(ObjectStorage::class, MediaStorage::class);
        $this->app->singleton(KnownUploads::class);
        $this->app->singleton(PluginUsage::class);
    }

    protected function bootModule(): void
    {
        $approvals = $this->app->make(ApprovalRegistry::class);
        $approvals->register('settings', 'setting', SettingWriter::class);
        $approvals->register('languages', 'language', LanguageWriter::class);
        $approvals->register('currencies', 'currency', CurrencyWriter::class);
        $approvals->register('locations', 'country', CountryWriter::class);
        $approvals->register('locations', 'state', StateWriter::class);
        $approvals->register('locations', 'district', DistrictWriter::class);
        $approvals->register('locations', 'city', CityWriter::class);
        $approvals->register('translations', 'translation_key', UiTranslations::class);
    }
}
