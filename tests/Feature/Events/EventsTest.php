<?php

declare(strict_types=1);

use App\Core\Support\EventQr;
use App\Modules\Catalog\Models\EventCategory;
use App\Modules\Catalog\Models\EventMenu;
use App\Modules\Catalog\Models\Plan;
use App\Modules\Catalog\Models\PlanMenu;
use App\Modules\Clients\Models\Client;
use App\Modules\Events\Models\Event;
use App\Modules\Events\Models\Participant;

/*
| Events core (/api/v1/client/events, /client/event-options) — v1
| clientEvent.service, clientPortal.getEventOptions, eventOrganizer.
*/

beforeEach(function () {
    seedHouse();
    $this->travelTo(now()->setDate(2026, 10, 20)->setTime(10, 0));
    $this->wedding = EventCategory::query()->create(['company_id' => 1, 'name' => 'Wedding', 'is_active' => 1]);
    $this->party = EventCategory::query()->create(['company_id' => 1, 'name' => 'Party', 'is_active' => 1]);
    $this->plan = Plan::query()->create(['company_id' => 1, 'name' => 'Gold', 'plan_code' => 'GOLD', 'is_active' => 1, 'max_events' => 2]);
    $menu = fn (string $name, int $default = 0, ?int $category = null) => EventMenu::query()->create([
        'company_id' => 1, 'name' => $name, 'slug' => strtolower($name), 'is_default' => $default, 'is_active' => 1, 'event_category_id' => $category,
    ]);
    $this->home = $menu('Home', 1);
    $this->gallery = $menu('Gallery');
    $this->guestsMenu = $menu('Guests');
    $this->bridal = $menu('Bridal', 0, $this->party->id);
    $this->notGranted = $menu('Extra');
    foreach ([$this->home, $this->gallery, $this->guestsMenu, $this->bridal] as $i => $m) {
        PlanMenu::query()->create(['plan_id' => $this->plan->id, 'event_menu_id' => $m->id, 'sort_order' => $i]);
    }
    $this->host = client(['plan_id' => $this->plan->id, 'mobile' => '9800000001']);
});

function eventForm(array $overrides = []): array
{
    return [
        'event_category_id' => test()->wedding->id, 'name' => 'Asha weds Ravi', 'start_date' => '2026-12-01', 'end_date' => '2026-12-02',
        'start_time' => '10:00', 'end_time' => '22:30', 'menu_ids' => [test()->gallery->id], ...$overrides,
    ];
}

it('offers the wizard only what the plan grants', function () {
    $this->withToken(accessToken(client()))->getJson('/api/v1/client/event-options')
        ->assertOk()->assertJsonPath('data.plan', null)
        ->assertJsonPath('data.reason', 'No subscription plan is assigned to your account yet. Please contact us.');

    $options = $this->withToken(accessToken($this->host))->getJson('/api/v1/client/event-options')
        ->assertOk()->assertJsonPath('data.plan.plan_code', 'GOLD')->assertJsonPath('data.events_used', 0)
        ->assertJsonCount(2, 'data.categories')->assertJsonCount(33, 'data.timezones')->json('data');
    expect(array_column($options['menus'], 'slug'))->toBe(['home', 'gallery', 'guests', 'bridal'])
        ->and($options['portal_sections'])->toBe(['home', 'gallery', 'guests', 'bridal']);
});

it('creates an event inside the plan, with its QR code and default menus', function () {
    $token = accessToken($this->host);

    $this->withToken($token)->postJson('/api/v1/client/events', eventForm(['menu_ids' => [$this->notGranted->id]]))
        ->assertStatus(400)->assertJsonPath('message', 'One or more selected menus are not included in your subscription plan.');
    $this->withToken($token)->postJson('/api/v1/client/events', eventForm(['menu_ids' => [$this->bridal->id]]))
        ->assertStatus(400)->assertJsonPath('message', 'One or more selected menus do not match the event category.');
    $this->withToken($token)->postJson('/api/v1/client/events', eventForm(['end_date' => '2026-11-01']))
        ->assertStatus(400)->assertJsonPath('message', 'The end date cannot be before the start date.');
    $this->withToken($token)->postJson('/api/v1/client/events', eventForm(['primary_color' => 'red']))
        ->assertStatus(400)->assertJsonPath('message', 'Primary colour must be a hex value like #2457D6.');

    $event = $this->withToken($token)->postJson('/api/v1/client/events', eventForm(['components' => ['venue' => 0], 'component_order' => ['venue', 'nope']]))
        ->assertCreated()->assertJsonPath('message', 'Event created successfully')
        ->assertJsonPath('data.event.derived_status', 'upcoming')->assertJsonPath('data.event.start_time', '10:00:00')
        ->assertJsonPath('data.event.plan.plan_code', 'GOLD')->assertJsonPath('data.event.website_client_id', $this->host->id)
        ->assertJsonPath('data.event.components.venue', 0)->assertJsonPath('data.event.components.event_title', 1)
        ->assertJsonPath('data.event.component_order.0', 'venue')
        ->json('data.event');

    expect($event['menu_ids'])->toEqualCanonicalizing([$this->gallery->id, $this->home->id])
        ->and(array_column($event['menus'], 'slug'))->toBe(['home', 'gallery'])
        ->and(app(EventQr::class)->decrypt($event['qr_token'])['eid'])->toBe($event['id']);

    // The host's tile order wins.
    $this->withToken($token)->putJson("/api/v1/client/events/{$event['id']}", ['menu_order' => [$this->gallery->id, $this->home->id]])
        ->assertOk()->assertJsonPath('data.event.menus.0.slug', 'gallery');
});

it('stops at the plan event limit, deleted events included', function () {
    $token = accessToken($this->host);
    $first = $this->withToken($token)->postJson('/api/v1/client/events', eventForm())->assertCreated()->json('data.event.id');
    $this->withToken($token)->postJson('/api/v1/client/events', eventForm())->assertCreated();
    $this->withToken($token)->deleteJson("/api/v1/client/events/{$first}")->assertOk();

    $this->withToken($token)->postJson('/api/v1/client/events', eventForm())
        ->assertStatus(400)->assertJsonPath('message', 'Your plan allows a maximum of 2 events. Please upgrade your plan to create more.');
});

it('lists, filters and counts the host events', function () {
    $token = accessToken($this->host);
    $this->withToken($token)->postJson('/api/v1/client/events', eventForm(['name' => 'Past', 'start_date' => '2026-01-01', 'end_date' => '2026-01-02']))->assertCreated();
    $live = $this->withToken($token)->postJson('/api/v1/client/events', eventForm(['name' => 'Today', 'start_date' => '2026-10-20', 'end_date' => '2026-10-21']))->assertCreated()->json('data.event.id');
    Participant::query()->create(['event_id' => $live, 'name' => 'G1', 'response_type' => 'yes', 'party_size' => 3]);
    Participant::query()->create(['event_id' => $live, 'name' => 'G2']);

    $this->withToken($token)->getJson('/api/v1/client/events?status=live')
        ->assertOk()->assertJsonPath('pagination.totalItems', 1)->assertJsonPath('data.0.name', 'Today')
        ->assertJsonPath('data.0.guest_count', 2)->assertJsonPath('data.0.confirmed_count', 1)->assertJsonPath('data.0.pending_count', 1);
    $this->withToken($token)->getJson('/api/v1/client/events?search=Pas')->assertJsonPath('data.0.derived_status', 'past');
    $this->withToken($token)->getJson('/api/v1/client/events/stats')
        ->assertOk()->assertJsonPath('data.total_events', 2)->assertJsonPath('data.live_events', 1)->assertJsonPath('data.past_events', 1);
    $this->withToken($token)->getJson("/api/v1/client/events/{$live}")
        ->assertOk()->assertJsonPath('data.event.stats.invited_guests', 2)->assertJsonPath('data.event.is_owner', true);
});

it('lets a participant view the event without host fields or host menus', function () {
    $token = accessToken($this->host);
    $id = $this->withToken($token)->postJson('/api/v1/client/events', eventForm(['menu_ids' => [$this->guestsMenu->id, $this->gallery->id]]))->json('data.event.id');

    $guest = client(['mobile' => '9811111111']);
    $stranger = client(['mobile' => '9822222222']);
    Participant::query()->create(['event_id' => $id, 'name' => 'Guest', 'mobile' => '9811111111']);

    $seen = $this->withToken(accessToken($guest))->getJson("/api/v1/client/events/{$id}")
        ->assertOk()->assertJsonPath('data.event.is_owner', false)->assertJsonMissingPath('data.event.qr_token')
        ->assertJsonMissingPath('data.event.website_client_id')->json('data.event');
    expect(array_column($seen['menus'], 'slug'))->not->toContain('guests');

    $this->withToken(accessToken($stranger))->getJson("/api/v1/client/events/{$id}")->assertNotFound();
    $this->withToken(accessToken($guest))->putJson("/api/v1/client/events/{$id}", ['name' => 'Hijack'])->assertNotFound();
});

it('lets the organizer manage the event but not delete it', function () {
    $organizer = client(['mobile' => '9833333333']);
    $token = accessToken($this->host);
    $id = $this->withToken($token)->postJson('/api/v1/client/events', eventForm(['contact_phone' => '+91 98333 33333']))->json('data.event.id');
    expect(Event::query()->withoutGlobalScopes()->find($id)->organizer_client_id)->toBe($organizer->id);

    $orgToken = accessToken($organizer);
    $this->withToken($orgToken)->putJson("/api/v1/client/events/{$id}", ['tagline' => 'Edited by organizer'])
        ->assertOk()->assertJsonPath('data.event.tagline', 'Edited by organizer');
    $this->withToken($orgToken)->getJson("/api/v1/client/events/{$id}")->assertJsonPath('data.event.is_organizer', true);
    $this->withToken($orgToken)->getJson('/api/v1/client/events')->assertJsonPath('data.0.is_organizer', true);
    $this->withToken($orgToken)->deleteJson("/api/v1/client/events/{$id}")
        ->assertStatus(403)->assertJsonPath('message', 'Only the client who owns this event can delete it.');
    $this->withToken($orgToken)->postJson('/api/v1/client/events', eventForm())
        ->assertStatus(403)->assertJsonPath('message', 'Only the client can create events.');
    // Without a plan of their own, the wizard shows the owner's catalogue.
    $this->withToken($orgToken)->getJson('/api/v1/client/event-options')->assertJsonPath('data.plan.plan_code', 'GOLD');
});

it('signs in an organizer by phone the first time and links them', function () {
    $id = $this->withToken(accessToken($this->host))->postJson('/api/v1/client/events', eventForm(['contact_phone' => '9844444444', 'organizer' => 'Uncle Raj']))->json('data.event.id');

    $code = $this->postJson('/api/v1/public/website-clients/login/otp/request', ['mobile' => '9844444444', 'login_as' => 'organizer'])
        ->assertOk()->json('data.dev_code');
    $tokens = $this->postJson('/api/v1/public/website-clients/login/otp/verify', ['mobile' => '9844444444', 'otp' => $code])
        ->assertOk()->assertJsonPath('data.client.name', 'Uncle Raj')->json('data');

    $organizer = Client::query()->withoutGlobalScopes()->where('mobile', '9844444444')->firstOrFail();
    expect(Event::query()->withoutGlobalScopes()->find($id)->organizer_client_id)->toBe($organizer->id);
    $this->withToken($tokens['access_token'])->getJson("/api/v1/client/events/{$id}")->assertOk();
});

it('gives a guest an account from their participation and decodes the QR', function () {
    $id = $this->withToken(accessToken($this->host))->postJson('/api/v1/client/events', eventForm())->json('data.event.id');
    Participant::query()->create(['event_id' => $id, 'name' => 'Meena', 'mobile' => '9855555555', 'photo' => 'https://cdn.test/m.jpg']);

    $code = $this->postJson('/api/v1/public/website-clients/login/otp/request', ['mobile' => '9855555555'])->json('data.dev_code');
    $token = $this->postJson('/api/v1/public/website-clients/login/otp/verify', ['mobile' => '9855555555', 'otp' => $code])
        ->assertOk()->assertJsonPath('data.client.name', 'Meena')->json('data.access_token');

    $qr = Event::query()->withoutGlobalScopes()->find($id)->qr_token;
    $this->withToken($token)->postJson('/api/v1/client/events/qr/decode', ['token' => $qr])
        ->assertOk()->assertJsonPath('data.payload.event_id', $id)->assertJsonPath('data.event.name', 'Asha weds Ravi');
    $this->withToken($token)->postJson('/api/v1/client/events/qr/decode', ['token' => 'EVQ1.bad'])
        ->assertStatus(400)->assertJsonPath('message', 'This QR code is not valid.');

    $this->withToken($token)->postJson("/api/v1/client/events/{$id}/wishlist")->assertOk()->assertJsonPath('data.count', 1);
    $this->withToken($token)->getJson('/api/v1/client/events/wishlist')->assertOk()->assertJsonPath('data.events.0.wishlisted', true);
});

it('counts events and heads in the billing usage', function () {
    $token = accessToken($this->host);
    $id = $this->withToken($token)->postJson('/api/v1/client/events', eventForm())->json('data.event.id');
    Participant::query()->create(['event_id' => $id, 'name' => 'G', 'party_size' => 4]);

    $this->withToken($token)->getJson('/api/v1/client/billing/overview')
        ->assertOk()->assertJsonPath('data.usage.events.used', 1)->assertJsonPath('data.usage.events.limit', 2)->assertJsonPath('data.usage.guests.heads', 4);
});
