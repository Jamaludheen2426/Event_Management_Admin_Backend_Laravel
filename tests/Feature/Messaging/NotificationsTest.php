<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\EventCategory;
use App\Modules\Catalog\Models\EventMenu;
use App\Modules\Catalog\Models\Plan;
use App\Modules\Catalog\Models\PlanMenu;
use App\Modules\Messaging\Models\ClientNotification;

/*
| The client's notification feed (/api/v1/client/notifications/*), filled
| from the other modules' ClientNotice events. v1 clientNotification.service.
*/

beforeEach(function () {
    seedHouse();
    $this->travelTo(now()->setDate(2026, 10, 20)->setTime(10, 0));
    $category = EventCategory::query()->create(['company_id' => 1, 'name' => 'Wedding', 'is_active' => 1]);
    $plan = Plan::query()->create(['company_id' => 1, 'name' => 'Gold', 'plan_code' => 'GOLD', 'is_active' => 1]);
    $menu = EventMenu::query()->create(['company_id' => 1, 'name' => 'RSVP', 'slug' => 'rsvp', 'is_active' => 1]);
    PlanMenu::query()->create(['plan_id' => $plan->id, 'event_menu_id' => $menu->id, 'sort_order' => 0]);
    $this->host = client(['plan_id' => $plan->id, 'mobile' => '9800000001', 'name' => 'Host']);
    $this->token = accessToken($this->host);
    $this->eventId = $this->withToken($this->token)->postJson('/api/v1/client/events', [
        'event_category_id' => $category->id, 'name' => 'Big Day', 'start_date' => '2026-12-01', 'end_date' => '2026-12-02',
        'start_time' => '10:00', 'end_time' => '20:00', 'menu_ids' => [$menu->id],
    ])->assertCreated()->json('data.event.id');
});

it('fills the feed from guest and RSVP activity, with tabs, stats and the event filter', function () {
    $participant = $this->withToken($this->token)->postJson('/api/v1/client/participants', ['event_id' => $this->eventId, 'first_name' => 'Asha', 'mobile' => '9811111111'])
        ->assertCreated()->json('data.participant.id');
    $this->travel(1)->minutes();
    $this->withToken($this->token)->putJson("/api/v1/client/participants/{$participant}", ['response_type' => 'yes'])->assertOk();

    $feed = $this->withToken($this->token)->getJson('/api/v1/client/notifications')
        ->assertOk()->assertJsonPath('message', 'Notifications retrieved')
        ->assertJsonPath('data.notifications.0.type', 'rsvp_accepted')->assertJsonPath('data.notifications.0.category', 'rsvp')
        ->assertJsonPath('data.notifications.0.event.name', 'Big Day')->assertJsonPath('data.notifications.0.guest.name', 'Asha')
        ->assertJsonPath('data.notifications.0.guest_id', $participant)->assertJsonPath('data.notifications.0.meta.response', 'yes')
        ->assertJsonPath('data.notifications.1.type', 'guest_added')->assertJsonPath('data.notifications.1.category', 'guest')
        ->assertJsonPath('data.stats.total', 2)->assertJsonPath('data.stats.unread', 2)->assertJsonPath('data.stats.guest_activity', 2)
        ->assertJsonPath('data.stats.by_category.reminder.total', 0)
        ->assertJsonPath('data.events.0.id', $this->eventId)->assertJsonPath('data.events.0.total', 2)
        ->assertJsonPath('data.pagination.totalItems', 2)
        ->json('data.notifications');

    $this->withToken($this->token)->getJson('/api/v1/client/notifications?category=rsvp')->assertJsonCount(1, 'data.notifications');
    $this->withToken($this->token)->getJson('/api/v1/client/notifications?search=added')->assertJsonCount(1, 'data.notifications');

    // Read, unread again, then "mark all" on the RSVP tab leaves the Guest tab alone.
    $this->withToken($this->token)->putJson("/api/v1/client/notifications/{$feed[0]['id']}/read")
        ->assertOk()->assertJsonPath('message', 'Marked as read')->assertJsonPath('data.notification.is_read', true);
    $this->withToken($this->token)->putJson("/api/v1/client/notifications/{$feed[0]['id']}/read", ['read' => false])
        ->assertOk()->assertJsonPath('message', 'Marked as unread');
    $this->withToken($this->token)->putJson('/api/v1/client/notifications/read-all', ['category' => 'rsvp'])
        ->assertOk()->assertJsonPath('message', '1 marked as read')->assertJsonPath('data.stats.unread', 1);
    $this->withToken($this->token)->getJson('/api/v1/client/notifications/count')->assertJsonPath('data.unread', 1);

    // Archiving hides it and counts it as read.
    $this->withToken($this->token)->putJson("/api/v1/client/notifications/{$feed[1]['id']}/archive")
        ->assertOk()->assertJsonPath('data.stats.total', 1)->assertJsonPath('data.stats.unread', 0);
});

it('never shows or changes another client\'s notifications', function () {
    $other = client(['email' => 'other@ra.test', 'mobile' => '9899999999']);
    $row = ClientNotification::query()->create(['client_id' => $other->id, 'type' => 'guest_added', 'category' => 'guest', 'title' => 'Hidden']);

    $this->withToken($this->token)->getJson('/api/v1/client/notifications')->assertJsonCount(0, 'data.notifications');
    $this->withToken($this->token)->putJson("/api/v1/client/notifications/{$row->id}/read")
        ->assertNotFound()->assertJsonPath('message', 'Notification not found.');
    $this->withToken($this->token)->putJson("/api/v1/client/notifications/{$row->id}/archive")->assertNotFound();
});
