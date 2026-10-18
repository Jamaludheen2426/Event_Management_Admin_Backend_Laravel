<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Actions;

use App\Core\Activity\ActivityLogger;
use App\Core\Contracts\ObjectStorage;
use App\Core\Crud\RecordWriter;
use App\Core\Exceptions\ApiException;
use App\Modules\Messaging\Models\EmailConfig;
use App\Modules\Messaging\Models\EmailTemplate;
use App\Modules\Messaging\Support\EmailRenderer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Settings → Email → Templates (v1 emailTemplate.service): the slug comes
 * from the name when left blank, pasted images are stored, and the
 * {{variables}} list is read from the subject and body.
 *
 * @extends RecordWriter<EmailTemplate>
 */
final class EmailTemplateWriter extends RecordWriter
{
    public function __construct(ActivityLogger $activity, private readonly ObjectStorage $storage)
    {
        parent::__construct($activity);
    }

    protected function model(): string
    {
        return EmailTemplate::class;
    }

    protected function module(): string
    {
        return 'email_templates';
    }

    protected function label(): string
    {
        return 'Email template';
    }

    protected function columns(): array
    {
        return ['name', 'slug', 'type', 'subject', 'body', 'variables', 'description', 'header_id', 'footer_id', 'email_config_id', 'is_active'];
    }

    protected function prepare(array $values, ?Model $existing): array
    {
        $name = trim((string) ($values['name'] ?? $existing->name ?? ''));
        if ($name === '') {
            throw ApiException::badRequest('Name is required');
        }
        if ($existing === null || array_key_exists('slug', $values)) {
            $values['slug'] = Str::slug(trim((string) ($values['slug'] ?? '')) ?: $name);
        }
        if (isset($values['type']) && ! in_array($values['type'], ['header', 'footer', 'template'], true)) {
            throw ApiException::badRequest('Type must be header, footer or template');
        }
        foreach (['header_id', 'footer_id', 'email_config_id'] as $key) {
            if (array_key_exists($key, $values)) {
                $values[$key] = is_numeric($values[$key]) ? (int) $values[$key] : null;
            }
        }
        if (isset($values['header_id']) || isset($values['footer_id'])) {
            $parts = array_filter([$values['header_id'] ?? null, $values['footer_id'] ?? null]);
            if ($this->query()->whereKey($parts)->count() !== count(array_unique($parts))) {
                throw ApiException::badRequest('Header or footer template not found');
            }
        }
        if (isset($values['email_config_id']) && ! EmailConfig::query()->whereKey($values['email_config_id'])->exists()) {
            throw ApiException::badRequest('Email configuration not found');
        }
        if (isset($values['body'])) {
            $values['body'] = (string) preg_replace_callback('#data:image/[a-z0-9.+-]+;base64,[A-Za-z0-9+/=]+#i',
                fn (array $m): string => $this->storage->storeDataUri($m[0], 'email', 'email-image') ?? $m[0], (string) $values['body']);
        }
        if (array_key_exists('body', $values) || array_key_exists('subject', $values) || $existing === null) {
            $values['variables'] = EmailRenderer::variablesIn(
                (string) ($values['subject'] ?? $existing?->subject),
                (string) ($values['body'] ?? $existing?->body),
            );
        }

        return $values;
    }

    protected function assertUnique(array $values, ?int $exceptId): void
    {
        if ($this->taken($this->query(), ['slug' => $values['slug']], $exceptId)) {
            throw ApiException::conflict('Email template with this slug already exists');
        }
    }

    /** v1 PATCH /:id/toggle-active. */
    public function toggle(EmailTemplate $template, ?Model $actor): EmailTemplate
    {
        return $this->update($template, ['is_active' => (int) $template->is_active === 1 ? 0 : 1], $actor);
    }
}
