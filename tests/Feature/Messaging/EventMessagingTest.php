<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\EventCategory;
use App\Modules\Catalog\Models\EventMenu;
use App\Modules\Catalog\Models\NotificationCategory;
use App\Modules\Catalog\Models\NotificationTemplate;
use App\Modules\Catalog\Models\Plan;
use App\Modules\Catalog\Models\PlanMenu;
use App\Modules\Clients\Models\ClientDevice;
use App\Modules\Events\Models\Participant;
use App\Modules\Messaging\Models\Broadcast;
use App\Modules\Messaging\Models\BroadcastRecipient;
use App\Modules\Messaging\Models\ClientNotification;
use App\Modules\Messaging\Models\EmailConfig;
use App\Modules\Messaging\Models\PushConfig;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;

/*
| What an organizer sends to their guests: the Messages screen
| (/client/messages), the app's Send Notification (/client/events/{id}/
| notifications), the automatic notices per event and the Welcome Invitation.
| v1 clientMessage, clientEventNotification, clientEventNotificationTemplate,
| notificationTrigger and the two schedulers.
*/

beforeEach(function () {
    seedHouse();
    $this->travelTo(now()->setDate(2026, 10, 20)->setTime(10, 0));
    $category = EventCategory::query()->create(['company_id' => 1, 'name' => 'Wedding', 'is_active' => 1]);
    $plan = Plan::query()->create(['company_id' => 1, 'name' => 'Gold', 'plan_code' => 'GOLD', 'is_active' => 1]);
    $menu = EventMenu::query()->create(['company_id' => 1, 'name' => 'RSVP', 'slug' => 'rsvp', 'is_active' => 1]);
    PlanMenu::query()->create(['plan_id' => $plan->id, 'event_menu_id' => $menu->id, 'sort_order' => 0]);
    $this->category = $category;
    $this->host = client(['plan_id' => $plan->id, 'mobile' => '9800000001', 'name' => 'Rohan', 'email' => 'host@ra.test']);
    $this->token = accessToken($this->host);
    $this->eventId = $this->withToken($this->token)->postJson('/api/v1/client/events', [
        'event_category_id' => $category->id, 'name' => 'Big Day', 'start_date' => '2026-12-01', 'end_date' => '2026-12-02',
        'start_time' => '18:30', 'end_time' => '22:00', 'menu_ids' => [$menu->id],
    ])->assertCreated()->json('data.event.id');

    $add = fn (array $form): int => $this->withToken($this->token)->postJson('/api/v1/client/participants', ['event_id' => $this->eventId, ...$form])
        ->assertCreated()->json('data.participant.id');
    $this->asha = $add(['first_name' => 'Asha', 'last_name' => 'K', 'mobile' => '9811111111', 'email' => 'asha@ra.test', 'rsvp_side' => 'bride', 'relationship' => 'Friend']);
    $this->vikram = $add(['first_name' => 'Vikram', 'mobile' => '9822222222', 'rsvp_side' => 'groom']);
    // Meera joined through the app: she has an account and a phone that takes push.
    $this->meera = $add(['first_name' => 'Meera', 'mobile' => '9833333333', 'rsvp_side' => 'bride', 'relationship' => 'Cousin']);
    $this->meeraAccount = client(['mobile' => '9833333333', 'email' => 'meera@ra.test', 'name' => 'Meera']);
    Participant::query()->whereKey($this->meera)->update(['client_id' => $this->meeraAccount->id]);
    ClientDevice::query()->create(['client_id' => $this->meeraAccount->id, 'token' => 'meera-phone', 'platform' => 'android']);
});

function activatePush(): void
{
    PushConfig::query()->create(['company_id' => 1, 'name' => 'App', 'is_active' => true, 'project_id' => 'event-invit',
        'client_email' => 'push@event-invit.iam.gserviceaccount.com', 'private_key' => file_get_contents(base_path('tests/Fixtures/firebase-test-key.pem'))]);
    Http::fake([
        'oauth2.googleapis.com/*' => Http::response(['access_token' => 'ya29.test', 'expires_in' => 3600]),
        'fcm.googleapis.com/*' => Http::response(['name' => 'projects/event-invit/messages/1']),
    ]);
}

it('opens the composer on the next event with its groups, merge fields and channel states', function () {
    $this->withToken($this->token)->getJson('/api/v1/client/messages/composer')
        ->assertOk()->assertJsonPath('message', 'Composer loaded')
        ->assertJsonPath('data.selected_event.id', $this->eventId)->assertJsonPath('data.events.0.guest_count', 3)
        ->assertJsonPath('data.guest_count', 3)->assertJsonPath('data.merge_fields.0.token', 'first_name')
        ->assertJsonPath('data.channels.0.channel', 'whatsapp')->assertJsonPath('data.channels.0.enabled', false)
        ->assertJsonPath('data.channels.1.enabled', false)->assertJsonPath('data.channels.2.enabled', false);

    $this->withToken($this->token)->postJson('/api/v1/client/messages/preview', [
        'event_id' => $this->eventId, 'channel' => 'email', 'subject' => 'Hi {{first_name}}', 'body' => 'See you on {{event_date}} at {{event_time}}, {{host_name}} {{unknown}}',
    ])->assertOk()->assertJsonPath('data.total_recipients', 1)
        ->assertJsonPath('data.counts.selected_guests', 3)->assertJsonPath('data.counts.unreachable', 2)
        ->assertJsonPath('data.unreachable.reason', '2 guests have no email address on file and will be skipped.')
        ->assertJsonPath('data.preview.subject', 'Hi Asha')
        ->assertJsonPath('data.preview.body', 'See you on 1 Dec 2026 at 06:30 PM, Rohan {{unknown}}')
        ->assertJsonPath('data.breakdown.0.name', "Bride's side");

    // Push reaches only guests who joined through the app.
    $this->withToken($this->token)->postJson('/api/v1/client/messages/preview', ['event_id' => $this->eventId, 'channel' => 'push'])
        ->assertOk()->assertJsonPath('data.total_recipients', 1)->assertJsonPath('data.unreachable.count', 2);
});

it('records a message when the channel has no provider, and says so', function () {
    $this->withToken($this->token)->postJson('/api/v1/client/messages/send', ['event_id' => $this->eventId, 'channel' => 'email', 'body' => 'Hi'])
        ->assertStatus(400)->assertJsonPath('message', 'Please fill all mandatory fields.');

    $sent = $this->withToken($this->token)->postJson('/api/v1/client/messages/send', [
        'event_id' => $this->eventId, 'channel' => 'whatsapp', 'kind' => 'invite', 'body' => 'Dear {{first_name}}, you are invited',
    ])->assertOk()->assertJsonPath('message', 'Message recorded for 3 guests — delivery is not enabled yet')
        ->assertJsonPath('data.campaign.status', 'sending')->assertJsonPath('data.campaign.subject', 'WhatsApp — Big Day')
        ->assertJsonPath('data.delivery.attempted', false)->json('data.campaign');

    expect(Participant::query()->find($this->vikram)?->rsvp_status)->toBe('invited')
        ->and(BroadcastRecipient::query()->where('broadcast_id', $sent['id'])->where('status', 'queued')->count())->toBe(3);

    $this->withToken($this->token)->getJson('/api/v1/client/messages')
        ->assertOk()->assertJsonPath('data.campaigns.0.id', $sent['id'])->assertJsonPath('data.campaigns.0.delivery.total', 3)
        ->assertJsonPath('data.stats.total', 3)->assertJsonPath('data.stats.by_channel.whatsapp.queued', 3)
        ->assertJsonPath('data.stats.by_channel.email.total', 0);
    $this->withToken($this->token)->getJson("/api/v1/client/messages/{$sent['id']}")
        ->assertOk()->assertJsonPath('data.delivery.queued', null)->assertJsonPath('data.campaign.delivery.queued', 3)
        ->assertJsonCount(3, 'data.recipients')->assertJsonPath('data.channel_state.enabled', false);

    // The host's own feed records it.
    expect(ClientNotification::query()->where('client_id', $this->host->id)->where('type', 'campaign_sent')->exists())->toBeTrue();

    // Another client cannot read it.
    $this->withToken(accessToken(client(['email' => 'x@ra.test', 'mobile' => '9899999999'])))
        ->getJson("/api/v1/client/messages/{$sent['id']}")->assertNotFound();
});

it('e-mails each guest when an e-mail provider is set up', function () {
    EmailConfig::query()->create(['company_id' => 1, 'name' => 'SMTP', 'from_email' => 'hello@ra.test', 'driver' => 'smtp', 'is_default' => true]);

    $sent = $this->withToken($this->token)->postJson('/api/v1/client/messages/send', [
        'event_id' => $this->eventId, 'channel' => 'email', 'subject' => 'Hello {{first_name}}', 'body' => 'Come!',
    ])->assertOk()->assertJsonPath('message', 'Message sent to 1 guests')->assertJsonPath('data.delivery.attempted', true)->json('data.campaign');

    expect(BroadcastRecipient::query()->where('broadcast_id', $sent['id'])->value('status'))->toBe('sent')
        ->and(Broadcast::query()->find($sent['id'])?->delivered_count)->toBe(1);

    $this->withToken($this->token)->postJson('/api/v1/client/messages/test', ['event_id' => $this->eventId, 'channel' => 'email', 'body' => 'Hi'])
        ->assertOk()->assertJsonPath('message', 'Test sent')->assertJsonPath('data.destination', 'host@ra.test');
});

it('pushes a message to guests with the app and puts it in their bell', function () {
    activatePush();

    $this->withToken($this->token)->postJson('/api/v1/client/messages/send', [
        'event_id' => $this->eventId, 'channel' => 'push', 'kind' => 'update', 'subject' => 'Venue changed', 'body' => 'Now at the Grand',
        'click_action' => 'deep_link', 'deep_link' => '/events/1', 'data_payload' => [['key' => 'screen', 'value' => 'venue'], ['key' => 'from', 'value' => 'x']],
        'push_options' => ['priority' => 'normal', 'ttl_seconds' => 'abc'],
    ])->assertOk()->assertJsonPath('data.delivery.delivered', 1)->assertJsonPath('data.delivery.failed', 0)
        ->assertJsonPath('data.campaign.status', 'sent');

    Http::assertSent(static fn (HttpRequest $r): bool => str_contains($r->url(), 'messages:send')
        && $r['message']['data']['screen'] === 'venue' && ! isset($r['message']['data']['from'])
        && $r['message']['android']['ttl'] === '86400s' && $r['message']['android']['priority'] === 'NORMAL');
    expect(ClientNotification::query()->where('client_id', $this->meeraAccount->id)->where('type', 'push_notification')->value('title'))->toBe('Venue changed');
});

it('sends a scheduled message when its time comes, and refuses a time already past', function () {
    $this->withToken($this->token)->postJson('/api/v1/client/messages/send', [
        'event_id' => $this->eventId, 'channel' => 'whatsapp', 'body' => 'Hi', 'scheduled_at' => '2026-10-20T08:00:00Z',
    ])->assertStatus(400)->assertJsonPath('message', 'That time has already passed. Choose a later time.');

    $id = $this->withToken($this->token)->postJson('/api/v1/client/messages/send', [
        'event_id' => $this->eventId, 'channel' => 'whatsapp', 'kind' => 'reminder', 'body' => 'Tomorrow!', 'scheduled_at' => now()->addHour()->toIso8601String(),
    ])->assertOk()->assertJsonPath('data.campaign.status', 'scheduled')->json('data.campaign.id');

    $this->artisan('messaging:send-due')->assertSuccessful();
    expect(Broadcast::query()->find($id)?->status)->toBe('scheduled');

    $this->travel(61)->minutes();
    $this->artisan('messaging:send-due')->expectsOutputToContain('Sent 1 scheduled message(s)')->assertSuccessful();
    expect(Broadcast::query()->find($id)?->status)->toBe('sent');
});

it('lets the organizer draft, send, schedule and reschedule app notifications', function () {
    $base = "/api/v1/client/events/{$this->eventId}/notifications";

    $this->withToken($this->token)->getJson("{$base}/audience")
        ->assertOk()->assertJsonPath('data.total', 3)->assertJsonPath('data.sides.0.count', 2)
        ->assertJsonPath('data.relationships.0.name', 'Cousin')->assertJsonPath('data.participants.1.has_app', true);

    $draft = $this->withToken($this->token)->postJson($base, ['title' => 'Draft one'])
        ->assertCreated()->assertJsonPath('message', 'Notification saved as draft.')->json('data.notification.id');
    $this->withToken($this->token)->putJson("/api/v1/client/event-notifications/{$draft}", ['action' => 'send', 'title' => 'Welcome'])
        ->assertStatus(400)->assertJsonPath('message', 'Please write a message.');

    // In-app only, to the bride's side: Asha (no app) and Meera (app).
    $this->withToken($this->token)->putJson("/api/v1/client/event-notifications/{$draft}", [
        'action' => 'send', 'title' => 'Mehendi tonight', 'message' => 'At 7', 'audience' => 'groups', 'sides' => ['bride'], 'channels' => ['in_app'],
    ])->assertOk()->assertJsonPath('message', 'Notification sent to 2 participants — saved in the app for 1.')
        ->assertJsonPath('data.notification.status', 'sent')->assertJsonPath('data.notification.delivered_count', 1);
    expect(ClientNotification::query()->where('client_id', $this->meeraAccount->id)->where('type', 'event_notification')->exists())->toBeTrue();
    $this->withToken($this->token)->putJson("/api/v1/client/event-notifications/{$draft}", ['action' => 'send', 'title' => 'x', 'message' => 'y'])
        ->assertStatus(400)->assertJsonPath('message', 'A notification that has been sent cannot be edited.');

    // Scheduled on the organizer's wall clock in their zone.
    $scheduled = $this->withToken($this->token)->postJson($base, [
        'action' => 'schedule', 'title' => 'Reminder', 'message' => 'Tomorrow', 'scheduled_date' => '2026-10-21', 'scheduled_time' => '09:00', 'timezone' => '(GMT+05:30) Chennai',
    ])->assertCreated()->assertJsonPath('message', 'Notification scheduled.')
        ->assertJsonPath('data.notification.scheduled_at', '2026-10-21T03:30:00.000Z')
        ->assertJsonPath('data.notification.scheduled_date', '2026-10-21')->assertJsonPath('data.notification.scheduled_time', '09:00')
        ->json('data.notification.id');
    $this->withToken($this->token)->putJson("/api/v1/client/event-notifications/{$scheduled}/reschedule", [
        'scheduled_date' => '2026-10-21', 'scheduled_time' => '10:15', 'timezone' => '(GMT+05:30) Chennai',
    ])->assertOk()->assertJsonPath('data.notification.scheduled_time', '10:15');

    $this->travelTo(now()->setDate(2026, 10, 21)->setTime(5, 0));
    $this->artisan('messaging:send-due')->expectsOutputToContain('1 event notification(s)')->assertSuccessful();
    $this->token = accessToken($this->host); // the old one expired overnight
    $this->withToken($this->token)->getJson("/api/v1/client/event-notifications/{$scheduled}")
        ->assertOk()->assertJsonPath('data.notification.status', 'sent')->assertJsonPath('data.notification.recipients_count', 3);

    $this->withToken($this->token)->getJson($base)->assertOk()->assertJsonCount(2, 'data.notifications');
    $this->withToken($this->token)->deleteJson("/api/v1/client/event-notifications/{$draft}")->assertOk()->assertJsonPath('data.id', $draft);

    // Notifications never show up on the Messages screen.
    $this->withToken($this->token)->getJson('/api/v1/client/messages')->assertJsonCount(0, 'data.campaigns');
});

it('sends the Welcome Invitation on join unless the owner switched it off', function () {
    $notices = NotificationCategory::query()->create(['company_id' => 1, 'name' => 'Guests', 'is_active' => 1]);
    $welcome = NotificationTemplate::query()->create([
        'company_id' => 1, 'name' => 'Welcome', 'trigger_key' => 'welcome_invitation', 'notification_category_id' => $notices->id,
        'event_category_id' => $this->category->id, 'title' => 'Welcome {{guest_name}}', 'content' => "Join us for {{event_name}}\non {{event_date}}",
        'channels' => ['in_app', 'push'], 'is_active' => 1,
    ]);
    activatePush();

    $joined = $this->withToken($this->token)->postJson('/api/v1/client/participants', ['event_id' => $this->eventId, 'first_name' => 'Kiran', 'mobile' => '9844444444'])
        ->assertCreated()->json('data.participant.id');
    expect(ClientNotification::query()->where('client_id', $this->host->id)->where('type', 'welcome_invitation')->where('participant_id', $joined)->value('title'))
        ->toBe('Welcome Kiran');

    $this->withToken($this->token)->getJson('/api/v1/client/events/notification-templates/summary')
        ->assertOk()->assertJsonPath('data.events.0.templates_count', 1)->assertJsonPath('data.totals.active_templates', 1);
    $this->withToken($this->token)->getJson("/api/v1/client/events/{$this->eventId}/notification-templates")
        ->assertOk()->assertJsonPath('data.templates.0.enabled', true)->assertJsonPath('data.templates.0.is_set', false)
        ->assertJsonPath('data.templates.0.notificationCategory.name', 'Guests');

    $this->withToken($this->token)->patchJson("/api/v1/client/events/{$this->eventId}/notification-templates/{$welcome->id}", ['enabled' => false])
        ->assertOk()->assertJsonPath('data.enabled', false);
    $this->withToken($this->token)->postJson("/api/v1/client/events/{$this->eventId}/notification-templates/{$welcome->id}/test")
        ->assertOk()->assertJsonPath('message', 'Welcome Invitation is disabled for this event in client portal');

    $this->withToken($this->token)->patchJson("/api/v1/client/events/{$this->eventId}/notification-templates/{$welcome->id}", ['enabled' => true])->assertOk();
    $this->withToken($this->token)->postJson("/api/v1/client/events/{$this->eventId}/notification-templates/{$welcome->id}/test", ['guest' => ['name' => 'Sample']])
        ->assertOk()->assertJsonPath('message', 'Welcome Invitation triggered successfully')->assertJsonPath('data.title', 'Welcome Sample');

    $this->withToken($this->token)->patchJson("/api/v1/client/events/{$this->eventId}/notification-templates/99999", ['enabled' => false])
        ->assertNotFound()->assertJsonPath('message', 'Event or notification template not found.');
});

it('shows sent invitations in the guest\'s RSVP history', function () {
    $this->withToken($this->token)->postJson('/api/v1/client/messages/send', ['event_id' => $this->eventId, 'channel' => 'whatsapp', 'kind' => 'reminder', 'body' => 'Hi'])->assertOk();

    $timeline = $this->withToken($this->token)->getJson("/api/v1/client/rsvps/{$this->asha}")->assertOk()->json('data');
    expect(json_encode($timeline))->toContain('Reminder sent');
});
