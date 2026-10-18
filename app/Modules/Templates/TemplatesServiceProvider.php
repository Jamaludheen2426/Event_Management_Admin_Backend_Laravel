<?php

declare(strict_types=1);

namespace App\Modules\Templates;

use App\Core\Approvals\ApprovalRegistry;
use App\Core\Support\ModuleServiceProvider;
use App\Modules\Platform\Media\KnownUploads;
use App\Modules\Templates\Actions\DecorationWriter;
use App\Modules\Templates\Actions\EventTemplateWriter;
use App\Modules\Templates\Actions\FrameStyleWriter;
use App\Modules\Templates\Actions\TemplateCategoryWriter;
use App\Modules\Templates\Models\Decoration;
use App\Modules\Templates\Models\EventTemplate;
use App\Modules\Templates\Models\FrameStyle;

/**
 * Templates: invitation designs — template styles, frame artwork,
 * decorations, fonts and the event templates built from them.
 */
final class TemplatesServiceProvider extends ModuleServiceProvider
{
    protected function bootModule(): void
    {
        $approvals = $this->app->make(ApprovalRegistry::class);
        $approvals->register('template_categories', 'template_category', TemplateCategoryWriter::class);
        $approvals->register('frame_styles', 'frame_style', FrameStyleWriter::class);
        $approvals->register('decorations', 'decoration', DecorationWriter::class);
        $approvals->register('event_templates', 'event_template', EventTemplateWriter::class);

        // The media proxy may fetch artwork already stored on our rows, even on
        // a CDN the current storage settings do not know (v1 isKnownUpload).
        $this->app->make(KnownUploads::class)->register(static fn (string $url): bool => Decoration::withTrashed()->where('file_url', $url)->exists()
            || FrameStyle::withTrashed()->where('file_url', $url)->exists()
            || EventTemplate::withTrashed()->where('background_image', $url)->exists());
    }
}
