<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Actions;

use App\Core\Crud\RecordWriter;
use App\Core\Exceptions\ApiException;
use App\Core\Support\Flag;
use App\Modules\Catalog\Models\NotificationCategory;
use App\Modules\Catalog\Models\NotificationTemplate;
use Illuminate\Database\Eloquent\Model;

/**
 * Notification templates (v1 /notification-templates). variables_used is
 * worked out from {{...}} in title and content; one template per system trigger.
 *
 * @extends RecordWriter<NotificationTemplate>
 */
final class NotificationTemplateWriter extends RecordWriter
{
    public const VARIABLES = ['guest_name', 'event_name', 'event_date', 'event_time', 'venue_name', 'event_link'];

    public const TRIGGERS = [
        ['key' => 'welcome_invitation', 'label' => 'Welcome Invitation (guest joins an event)'],
    ];

    protected function model(): string
    {
        return NotificationTemplate::class;
    }

    protected function module(): string
    {
        return 'notification_templates';
    }

    protected function label(): string
    {
        return 'Notification template';
    }

    protected function notFoundMessage(): string
    {
        return 'That notification template was not found.';
    }

    protected function columns(): array
    {
        return [
            'name', 'trigger_key', 'notification_category_id', 'event_category_id', 'title', 'content',
            'image_url', 'channels', 'is_active', 'sort_order',
        ];
    }

    protected function prepare(array $values, ?Model $existing): array
    {
        /** @var NotificationTemplate|null $existing */
        $creating = $existing === null;
        foreach (['name' => 'A template name is required.', 'title' => 'A title is required.'] as $field => $message) {
            if ($creating || array_key_exists($field, $values)) {
                $values[$field] = trim((string) ($values[$field] ?? ''));
                if ($values[$field] === '') {
                    throw ApiException::badRequest($message);
                }
            }
        }
        if ($creating && ! array_key_exists('notification_category_id', $values)) {
            throw ApiException::badRequest('A notification category is required.');
        }
        if (array_key_exists('notification_category_id', $values)) {
            $values['notification_category_id'] = Flag::id($values['notification_category_id']);
            if ($values['notification_category_id'] === null) {
                throw ApiException::badRequest('A notification category is required.');
            }
            if (! NotificationCategory::query()->whereKey($values['notification_category_id'])->exists()) {
                throw ApiException::badRequest('That notification category does not exist.');
            }
        }
        if ($creating || array_key_exists('content', $values)) {
            if (trim((string) ($values['content'] ?? '')) === '') {
                throw ApiException::badRequest('Message content is required.');
            }
        }
        if (array_key_exists('event_category_id', $values) || $creating) {
            $values['event_category_id'] = Flag::id($values['event_category_id'] ?? null);
        }
        if (array_key_exists('channels', $values) || $creating) {
            $values['channels'] = self::channels($values['channels'] ?? null);
        }
        if (array_key_exists('trigger_key', $values) || $creating) {
            $values['trigger_key'] = self::trigger($values['trigger_key'] ?? null);
        }
        if ($creating || array_key_exists('title', $values) || array_key_exists('content', $values)) {
            $values['variables_used'] = self::variablesIn(
                (string) ($values['title'] ?? $existing->title ?? ''),
                (string) ($values['content'] ?? $existing->content ?? ''),
            );
        }

        return $values;
    }

    protected function assertUnique(array $values, ?int $exceptId): void
    {
        if (empty($values['trigger_key'])) {
            return;
        }
        $clash = $this->query()->where('trigger_key', $values['trigger_key'])
            ->when($exceptId !== null, static fn ($q) => $q->whereKeyNot($exceptId))
            ->first();
        if ($clash !== null) {
            throw ApiException::badRequest("\"{$clash->name}\" already handles this system trigger. Clear it there first.");
        }
    }

    /** "(Copy)", switched off, no trigger. */
    public function duplicate(NotificationTemplate $source, ?Model $actor): NotificationTemplate
    {
        $copy = $source->only($this->columns());
        $copy['name'] = $source->name.' (Copy)';
        $copy['is_active'] = 0;
        $copy['trigger_key'] = null;

        return $this->create($copy, $actor);
    }

    /** @return list<string> */
    public static function variablesIn(string $title, string $content): array
    {
        preg_match_all('/\{\{\s*(\w+)\s*\}\}/', $title.' '.$content, $matches);

        return array_values(array_unique(array_intersect($matches[1], self::VARIABLES)));
    }

    /** @return list<string> */
    private static function channels(mixed $channels): array
    {
        $clean = is_array($channels) ? array_values(array_unique(array_intersect($channels, ['in_app', 'push']))) : [];

        return $clean === [] ? ['in_app'] : $clean;
    }

    private static function trigger(mixed $value): ?string
    {
        if ($value === null || $value === '' || $value === 'null') {
            return null;
        }
        if (! in_array($value, array_column(self::TRIGGERS, 'key'), true)) {
            throw ApiException::badRequest('That system trigger is not recognised.');
        }

        return (string) $value;
    }
}
