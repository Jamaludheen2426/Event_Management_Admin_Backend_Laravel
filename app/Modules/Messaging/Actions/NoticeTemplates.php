<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Actions;

use App\Core\Exceptions\ApiException;
use App\Core\Support\Dates;
use App\Core\Tenancy\CompanyScope;
use App\Modules\Catalog\Models\NotificationTemplate;
use App\Modules\Clients\Events\ClientNotice;
use App\Modules\Clients\Models\Client;
use App\Modules\Events\Models\Event;
use App\Modules\Events\Models\Participant;
use App\Modules\Events\Support\EventAccess;
use App\Modules\Messaging\Models\EventTemplatePref;
use App\Modules\Messaging\Support\AppPush;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Throwable;

/**
 * The platform's automatic notices per event (v1 clientEventNotificationTemplate
 * + notificationTrigger): which admin templates apply to an event, the
 * owner's on/off switch for each, and sending the Welcome Invitation when
 * someone joins.
 */
final class NoticeTemplates
{
    public const WELCOME = 'welcome_invitation';

    public function __construct(
        private readonly EventAccess $access,
        private readonly AppPush $push,
        private readonly ClientNotifications $feed,
    ) {}

    /** @return array<string, mixed> */
    public function summary(Client $client): array
    {
        $events = Event::query()->whereIn('id', $this->access->managedEventIds($client))
            ->with('category:id,name')->orderByDesc('updated_at')->get();
        $first = $events->first();
        $templates = $first === null ? new Collection : NotificationTemplate::query()->withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $this->push->companyOf($first))->where('is_active', 1)->get(['id', 'event_category_id']);
        $off = EventTemplatePref::query()->whereIn('event_id', $events->modelKeys())->where('enabled', false)
            ->get(['event_id', 'notification_template_id'])->groupBy('event_id');

        $rows = $events->map(static function (Event $event) use ($templates, $off): array {
            $applicable = $templates->filter(static fn (NotificationTemplate $t): bool => $t->getAttribute('event_category_id') === null || $t->getAttribute('event_category_id') === $event->event_category_id);
            $disabled = $off->get($event->id, collect())->pluck('notification_template_id')->intersect($applicable->modelKeys())->count();

            return [
                'id' => $event->id, 'name' => $event->name, 'status' => $event->status,
                'category' => $event->category === null ? null : ['id' => $event->category->id, 'name' => $event->category->name],
                'templates_count' => $applicable->count(), 'active_count' => $applicable->count() - $disabled,
                'inactive_count' => $disabled, 'updated_at' => Dates::at($event->updated_at),
            ];
        });

        return [
            'events' => $rows->all(),
            'totals' => [
                'total_events' => $rows->count(), 'total_templates' => $rows->sum('templates_count'),
                'active_templates' => $rows->sum('active_count'), 'inactive_templates' => $rows->sum('inactive_count'),
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function forEvent(Client $client, int $eventId): array
    {
        $event = $this->event($client, $eventId);
        $templates = $this->templates($event)->with(['notificationCategory:id,name,icon,color', 'category:id,name'])->get();
        $prefs = EventTemplatePref::query()->where('event_id', $event->id)->pluck('enabled', 'notification_template_id');

        return [
            'event' => ['id' => $event->id, 'name' => $event->name, 'status' => $event->status],
            'templates' => $templates->map(static fn (NotificationTemplate $t): array => [
                'id' => $t->id, 'name' => $t->name, 'title' => $t->title, 'content' => $t->content,
                'image_url' => $t->getAttribute('image_url'),
                'notificationCategory' => $t->getRelation('notificationCategory')?->only(['id', 'name', 'icon', 'color']),
                'category' => $t->getRelation('category')?->only(['id', 'name']),
                'enabled' => $prefs->has($t->id) ? (bool) $prefs[$t->id] : true,
                'is_set' => $prefs->has($t->id),
            ])->all(),
        ];
    }

    /** @return array{template_id: int, enabled: bool} */
    public function toggle(Client $client, int $eventId, int $templateId, mixed $enabled): array
    {
        $event = $this->event($client, $eventId, 'Event or notification template not found.');
        if (! $this->templates($event)->whereKey($templateId)->exists()) {
            throw ApiException::notFound('Event or notification template not found.');
        }
        $pref = EventTemplatePref::query()->updateOrCreate(
            ['event_id' => $event->id, 'notification_template_id' => $templateId],
            ['enabled' => filter_var($enabled, FILTER_VALIDATE_BOOL)],
        );

        return ['template_id' => $templateId, 'enabled' => $pref->enabled];
    }

    /**
     * The Welcome Invitation (v1 triggerWelcomeInvitation). The owner keeps a
     * copy in their bell; the push goes only to the person welcomed — a
     * greeting meant for a guest must not buzz the host's phone.
     *
     * @return array<string, mixed>
     */
    public function welcome(Event $event, ?Participant $participant, ?Client $welcomed = null, ?string $guestName = null): array
    {
        try {
            $template = $this->templates($event)->where('trigger_key', self::WELCOME)->first();
            if ($template === null) {
                return ['triggered' => false, 'reason' => 'template_not_found'];
            }
            $off = EventTemplatePref::query()->where('event_id', $event->id)->where('notification_template_id', $template->id)->where('enabled', false)->exists();
            if ($off) {
                return ['triggered' => false, 'reason' => 'disabled_by_client_portal'];
            }

            $vars = [
                'guest_name' => $participant?->name ?: ($guestName ?: ($welcomed->name ?? 'Guest')),
                'event_name' => $event->name ?: 'Our Event',
                'event_date' => $event->start_date?->format('D, M j, Y') ?? '',
                'event_time' => (string) $event->start_time,
                'venue_name' => $event->getAttribute('venue_name') ?: ($event->getAttribute('venue_address') ?: 'Event Venue'),
                'event_link' => "eventinvit.app/e/{$event->id}",
            ];
            $render = static fn (string $text): string => (string) preg_replace_callback('/\{\{\s*(\w+)\s*\}\}/', static fn (array $m): string => $vars[$m[1]] ?? $m[0], $text);
            $title = $render($template->title);
            $body = $render($template->content);
            $channels = $template->channels !== [] ? $template->channels : ['in_app', 'push'];

            $people = array_values(array_unique(array_filter([$participant?->client_id, $welcomed?->id])));
            $recipients = array_values(array_unique([$event->client_id, ...$people]));
            if (in_array('in_app', $channels, true)) {
                foreach ($recipients as $clientId) {
                    $this->feed->record(new ClientNotice(
                        $clientId, self::WELCOME, $title, $body,
                        $participant !== null ? "/dashboard/guests/{$participant->id}" : "/dashboard/events/{$event->id}",
                        $event->id, $participant?->id,
                        ['template_id' => $template->id, 'template_name' => $template->name, 'guest_name' => $vars['guest_name'], 'event_name' => $vars['event_name'], 'channels' => $channels],
                    ));
                }
            }
            if (in_array('push', $channels, true) && $people !== []) {
                $this->push->send($event, $people, [
                    'title' => $title,
                    'body' => trim((string) preg_replace(['/\R\s*\R/', '/\R/', '/\s{2,}/'], [' · ', ' ', ' '], $body)),
                    'image_url' => $template->getAttribute('image_url') ?: null,
                    'deep_link' => "/dashboard/events/{$event->id}",
                    'data' => ['event_id' => (string) $event->id, 'template_id' => (string) $template->id, 'type' => self::WELCOME],
                ]);
            }

            return ['triggered' => true, 'template' => ['id' => $template->id, 'name' => $template->name], 'title' => $title, 'recipients' => $recipients];
        } catch (Throwable $e) {
            report($e);

            return ['triggered' => false, 'reason' => 'error', 'error' => $e->getMessage()];
        }
    }

    public function event(Client $client, int $eventId, string $missing = 'Event not found.'): Event
    {
        if (! in_array($eventId, $this->access->managedEventIds($client), true)) {
            throw ApiException::notFound($missing);
        }

        return Event::query()->findOrFail($eventId);
    }

    /**
     * Active templates for the event's category or for every category, in
     * the event's company (the guest flows run outside a company context).
     *
     * @return Builder<NotificationTemplate>
     */
    private function templates(Event $event): Builder
    {
        return NotificationTemplate::query()->withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $this->push->companyOf($event))->where('is_active', 1)
            ->where(static fn (Builder $q) => $q->whereNull('event_category_id')->orWhere('event_category_id', $event->event_category_id))
            ->orderBy('sort_order')->orderBy('name')->orderBy('id');
    }
}
