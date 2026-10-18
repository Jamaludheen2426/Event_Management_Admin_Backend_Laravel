<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\EventCategory;
use App\Modules\Catalog\Models\EventMenu;
use App\Modules\Catalog\Models\GuestOption;
use App\Modules\Catalog\Models\Plan;
use App\Modules\Catalog\Models\PlanMenu;
use App\Modules\Events\Models\Event;
use App\Modules\Events\Models\Guest;
use App\Modules\Events\Models\Participant;
use App\Modules\Events\Models\ParticipantResponseLog;

/*
| The phone book, participants, RSVPs and joining by QR — v1 clientGuest,
| clientGuestGroup, clientGuestImport/Export, clientParticipant, clientRsvp,
| clientRsvpSettings, guestRegistration, clientGuestProfile.
*/

beforeEach(function () {
    seedHouse();
    $this->travelTo(now()->setDate(2026, 10, 20)->setTime(10, 0));
    $this->category = EventCategory::query()->create(['company_id' => 1, 'name' => 'Wedding', 'is_active' => 1]);
    $this->plan = Plan::query()->create(['company_id' => 1, 'name' => 'Gold', 'plan_code' => 'GOLD', 'is_active' => 1, 'max_guests_per_event' => 3, 'max_rsvp_per_event' => 2]);
    $this->rsvpMenu = EventMenu::query()->create(['company_id' => 1, 'name' => 'RSVP', 'slug' => 'rsvp', 'is_active' => 1]);
    PlanMenu::query()->create(['plan_id' => $this->plan->id, 'event_menu_id' => $this->rsvpMenu->id, 'sort_order' => 0]);
    $this->veg = GuestOption::query()->create(['company_id' => 1, 'kind' => 'food_preference', 'name' => 'No Preference', 'is_active' => 1]);
    $this->host = client(['plan_id' => $this->plan->id, 'mobile' => '9800000001', 'name' => 'Host']);
    $this->token = accessToken($this->host);
    $this->event = Event::query()->find($this->withToken($this->token)->postJson('/api/v1/client/events', [
        'event_category_id' => $this->category->id, 'name' => 'Big Day', 'start_date' => '2026-12-01', 'end_date' => '2026-12-02',
        'start_time' => '10:00', 'end_time' => '20:00', 'menu_ids' => [$this->rsvpMenu->id],
    ])->assertCreated()->json('data.event.id'));
});

it('keeps one phone-book guest per number, within the plan', function () {
    $this->withToken($this->token)->postJson('/api/v1/client/guests', ['first_name' => ''])
        ->assertStatus(400)->assertJsonPath('message', 'Please enter the first name.');

    $guest = $this->withToken($this->token)->postJson('/api/v1/client/guests', ['first_name' => 'Asha', 'last_name' => 'K', 'mobile' => '+91 98111 11111', 'email' => 'Asha@ra.test'])
        ->assertCreated()->assertJsonPath('data.guest.name', 'Asha K')->assertJsonPath('data.guest.email', 'asha@ra.test')
        ->assertJsonPath('data.guest.has_joined', false)->json('data.guest');

    $this->withToken($this->token)->postJson('/api/v1/client/guests', ['first_name' => 'Dup', 'mobile' => '9811111111'])
        ->assertStatus(409)->assertJsonPath('message', 'Asha K is already in your guest list with this mobile number.');
    $this->withToken($this->token)->postJson('/api/v1/client/guests', ['first_name' => 'B', 'mobile' => '9822222222'])->assertCreated();
    $this->withToken($this->token)->postJson('/api/v1/client/guests', ['first_name' => 'C', 'mobile' => '9833333333'])->assertCreated();
    $this->withToken($this->token)->postJson('/api/v1/client/guests', ['first_name' => 'D', 'mobile' => '9844444444'])
        ->assertStatus(400)->assertJsonPath('message', 'Your plan allows 3 guests in total and you have reached that limit. Please upgrade your plan to add more guests.');

    $this->withToken($this->token)->getJson('/api/v1/client/guests/capacity')->assertJsonPath('data.full', true)->assertJsonPath('data.remaining', 0);
    $this->withToken($this->token)->getJson('/api/v1/client/guests?search=Asha')->assertOk()->assertJsonPath('pagination.totalItems', 1);
    $this->withToken($this->token)->putJson("/api/v1/client/guests/{$guest['id']}", ['last_name' => 'Kumar'])->assertJsonPath('data.guest.name', 'Asha Kumar');

    // Another client's guest is not ours.
    $this->withToken(accessToken(client()))->getJson("/api/v1/client/guests/{$guest['id']}")->assertNotFound()->assertJsonPath('message', 'Guest not found.');
});

it('groups guests, with one default group', function () {
    $family = $this->withToken($this->token)->postJson('/api/v1/client/guests/groups', ['name' => 'Family', 'is_default' => true])->assertCreated()->json('data.group.id');
    $this->withToken($this->token)->postJson('/api/v1/client/guests/groups', ['name' => 'family'])
        ->assertStatus(409)->assertJsonPath('message', 'You already have a group called "Family".');
    $friends = $this->withToken($this->token)->postJson('/api/v1/client/guests/groups', ['name' => 'Friends', 'color' => '#22AA44', 'is_default' => true])->json('data.group.id');

    $guest = $this->withToken($this->token)->postJson('/api/v1/client/guests', ['first_name' => 'Auto', 'mobile' => '9811111112'])->json('data.guest');
    expect($guest['group_id'])->toBe($friends);

    $this->withToken($this->token)->getJson('/api/v1/client/guests/groups')
        ->assertOk()->assertJsonPath('data.0.name', 'Friends')->assertJsonPath('data.0.members_count', 1)->assertJsonPath('data.1.is_default', false);
    $this->withToken($this->token)->deleteJson("/api/v1/client/guests/groups/{$friends}")
        ->assertOk()->assertJsonPath('message', 'Group deleted. 1 guest(s) are now ungrouped.');
    $this->withToken($this->token)->getJson('/api/v1/client/guests/groups/stats')->assertJsonPath('data.total_groups', 1);
    expect($family)->toBeInt();
});

it('imports a messy CSV and exports a safe one', function () {
    $this->withToken($this->token)->postJson('/api/v1/client/guests/groups', ['name' => 'Family']);
    $csv = "\u{FEFF}First Name*;Phone Number*;Email;Guest Group\nAmit;+919876543210;amit@ra.test;Family\nBad;9.19877E+11;;\nAmit again;9876543210;;\n\nPriya;9812345678;;Work";

    $preview = $this->withToken($this->token)->postJson('/api/v1/client/guests/import/preview', ['content' => $csv])
        ->assertOk()->assertJsonPath('data.delimiter', ';')->assertJsonPath('data.valid_count', 2)
        ->assertJsonPath('data.skipped_count', 1)->assertJsonPath('data.error_count', 1)->assertJsonPath('data.new_groups', ['Work'])->json('data');
    expect($preview['errors'][0]['errors'][0])->toContain('converted to a number by a spreadsheet');

    $this->withToken($this->token)->postJson('/api/v1/client/guests/import', ['content' => $csv, 'create_groups' => true])
        ->assertOk()->assertJsonPath('data.imported', 2)->assertJsonPath('data.created_groups.0.name', 'Work')->assertJsonPath('message', '2 guest(s) imported');
    expect(Guest::query()->where('source', 'import')->count())->toBe(2);

    $this->withToken($this->token)->postJson('/api/v1/client/guests', ['first_name' => '=HYPERLINK("x")', 'mobile' => '9800009999']);
    $export = $this->withToken($this->token)->get('/api/v1/client/guests/export')->assertOk()->assertHeader('Content-Type', 'text/csv; charset=utf-8');
    expect($export->getContent())->toStartWith("\u{FEFF}\"First Name*\"")->toContain("\"\t=HYPERLINK(\"\"x\"\")\"");

    $this->withToken($this->token)->get('/api/v1/client/guests/import/sample')->assertOk()->assertHeader('Content-Disposition', 'attachment; filename="guest-import-sample.csv"');
});

it('adds participants up to the plan limit, one per number', function () {
    $form = fn (array $extra = []) => ['event_id' => $this->event->id, 'first_name' => 'P', 'mobile' => '9811111111', ...$extra];

    $this->withToken($this->token)->postJson('/api/v1/client/participants', $form(['event_id' => 999]))
        ->assertStatus(400)->assertJsonPath('message', 'That event is not on your account.');
    $id = $this->withToken($this->token)->postJson('/api/v1/client/participants', $form(['response_type' => 'yes', 'party_size' => 3]))
        ->assertCreated()->assertJsonPath('data.participant.rsvp_status', 'accepted')->assertJsonPath('data.guest.party_size', 3)->json('data.participant.id');
    $this->withToken($this->token)->postJson('/api/v1/client/participants', $form())
        ->assertStatus(409)->assertJsonPath('message', 'P is already a participant of this event.');
    $this->withToken($this->token)->postJson('/api/v1/client/participants', $form(['mobile' => '9822222222']))->assertCreated();
    $this->withToken($this->token)->postJson('/api/v1/client/participants', $form(['mobile' => '9833333333']))
        ->assertStatus(400)->assertJsonPath('message', 'This event has reached its limit of 2 participants. Please upgrade your plan to allow more.');

    $this->withToken($this->token)->getJson('/api/v1/client/participants/stats?event_id='.$this->event->id)
        ->assertOk()->assertJsonPath('data.total_rows', 2)->assertJsonPath('data.total_guests', 4)->assertJsonPath('data.accepted', 1);
    $this->withToken($this->token)->getJson('/api/v1/client/participants?status=accepted')->assertJsonPath('pagination.totalItems', 1);
    $this->withToken(accessToken(client()))->getJson("/api/v1/client/participants/{$id}")->assertNotFound();
});

it('edits and clears RSVPs with a history of every answer', function () {
    $p = Participant::query()->create(['event_id' => $this->event->id, 'name' => 'Ravi', 'mobile' => '9811111111']);

    $this->withToken($this->token)->putJson("/api/v1/client/rsvps/{$p->id}", ['response_type' => 'yes', 'party_size' => 2])
        ->assertOk()->assertJsonPath('data.rsvp.bucket', 'accepted')->assertJsonPath('data.response_history.0.is_first', true);
    $this->withToken($this->token)->putJson("/api/v1/client/rsvps/{$p->id}/reset", ['reason' => 'Asked by phone'])
        ->assertOk()->assertJsonPath('message', 'Response cleared — the guest can respond again')
        ->assertJsonPath('data.rsvp.response_type', 'none')->assertJsonPath('data.response_history.0.from_response_type', 'yes');
    expect(ParticipantResponseLog::query()->count())->toBe(2);

    $this->withToken($this->token)->getJson('/api/v1/client/rsvps?status=no_response')
        ->assertOk()->assertJsonPath('data.pagination.totalItems', 1)->assertJsonPath('data.stats.no_response', 1);
    $this->withToken($this->token)->putJson("/api/v1/client/rsvps/{$p->id}", [])->assertStatus(400)->assertJsonPath('message', 'Nothing to update.');

    $this->withToken($this->token)->putJson("/api/v1/client/events/{$this->event->id}/rsvp-settings", ['response_options' => []])
        ->assertStatus(400)->assertJsonPath('message', 'Keep at least one response option.');
    $this->withToken($this->token)->putJson("/api/v1/client/events/{$this->event->id}/rsvp-settings", ['deadline' => '2027-01-01'])
        ->assertStatus(400)->assertJsonPath('message', 'The response deadline cannot be after the event ends.');
    $this->withToken($this->token)->putJson("/api/v1/client/events/{$this->event->id}/rsvp-settings", ['response_options' => ['yes', 'no'], 'allow_food_preference' => true])
        ->assertOk()->assertJsonPath('data.settings.response_options', ['yes', 'no'])->assertJsonPath('data.settings.effective_deadline', '2026-12-02');
});

it('lets a guest join from the QR, answer once, and see who else came', function () {
    $this->withToken($this->token)->putJson("/api/v1/client/events/{$this->event->id}/rsvp-settings", ['allow_food_preference' => true])->assertOk();
    $qr = $this->event->qr_token;
    $this->withToken($this->token)->postJson('/api/v1/client/guests', ['first_name' => 'Meena', 'mobile' => '9855555555']);

    $this->postJson('/api/v1/public/events/qr/resolve', ['token' => 'nope'])->assertStatus(400)->assertJsonPath('message', 'This QR code is not valid.');
    $this->postJson('/api/v1/public/events/qr/resolve', ['token' => $qr])
        ->assertOk()->assertJsonPath('data.event.name', 'Big Day')->assertJsonPath('data.rsvp_enabled', true)
        ->assertJsonPath('data.participants_full', false)->assertJsonMissingPath('data.event.qr_token');

    $code = $this->postJson('/api/v1/public/events/join/otp/request', ['token' => $qr, 'mobile' => '9855555555', 'name' => 'Meena'])
        ->assertOk()->assertJsonPath('data.account_created', true)->json('data.dev_code');
    $session = $this->postJson('/api/v1/public/events/join/otp/verify', ['mobile' => '9855555555', 'otp' => $code])
        ->assertOk()->assertJsonPath('message', 'Mobile number verified')->assertCookie('website_client_access_token')->json('data');
    $guestToken = $session['access_token'];

    $joined = $this->withToken($guestToken)->postJson('/api/v1/client/events/join', ['token' => $qr, 'name' => 'Meena R'])
        ->assertCreated()->assertJsonPath('message', 'You have joined the event')->assertJsonPath('data.created', true)->json('data.guest');
    expect($joined['invite_source'])->toBe('qr')->and($joined['guest_id'])->toBeInt();
    $this->withToken($guestToken)->postJson('/api/v1/client/events/join', ['token' => $qr])->assertOk()->assertJsonPath('data.created', false);

    $this->withToken($guestToken)->getJson("/api/v1/client/events/{$this->event->id}/my-rsvp")
        ->assertOk()->assertJsonPath('data.can_respond', true)->assertJsonPath('data.default_food_preference_option_id', $this->veg->id);
    $this->withToken($guestToken)->postJson("/api/v1/client/events/{$this->event->id}/my-rsvp", ['response_type' => 'yes', 'party_size' => 2, 'food_preference_option_id' => $this->veg->id])
        ->assertOk()->assertJsonPath('message', 'Your RSVP has been submitted')->assertJsonPath('data.rsvp.response_type', 'yes')
        ->assertJsonPath('data.rsvp.dietary_preference', 'No Preference')->assertJsonPath('data.can_respond', false);
    $this->withToken($guestToken)->postJson("/api/v1/client/events/{$this->event->id}/my-rsvp", ['response_type' => 'no'])
        ->assertStatus(400)->assertJsonPath('message', 'You have already responded to this event. To change your answer, please contact the host.');

    $this->withToken($guestToken)->getJson("/api/v1/client/events/{$this->event->id}/participants")
        ->assertOk()->assertJsonPath('data.0.full_name', 'Meena R')->assertJsonMissingPath('data.0.mobile');
    $this->withToken($guestToken)->getJson('/api/v1/client/events/joined')->assertJsonPath('data.events.0.response_type', 'yes');
    $this->withToken($this->token)->postJson("/api/v1/client/events/{$this->event->id}/my-rsvp", ['response_type' => 'yes'])
        ->assertStatus(400)->assertJsonPath('message', 'You are hosting this event — RSVPs are for your guests.');

    // The second place is taken, so the event is full for a new number.
    Participant::query()->create(['event_id' => $this->event->id, 'name' => 'X', 'mobile' => '9866666666']);
    $this->postJson('/api/v1/public/events/join/otp/request', ['token' => $qr, 'mobile' => '9877777777'])
        ->assertStatus(400)->assertJsonPath('message', 'Sorry, this event is full and cannot take more participants. Please contact the host.');
});

it('keeps host notes, tags and reminders on the guest profile', function () {
    $guest = $this->withToken($this->token)->postJson('/api/v1/client/guests', ['first_name' => 'Vip', 'mobile' => '9811111111'])->json('data.guest.id');

    $this->withToken($this->token)->postJson("/api/v1/client/guests/{$guest}/notes", ['title' => ['x' => 1]])
        ->assertStatus(400)->assertJsonPath('message', 'A note needs a title.');
    $note = $this->withToken($this->token)->postJson("/api/v1/client/guests/{$guest}/notes", ['title' => 'Allergies', 'body' => '<p>Nuts</p>', 'is_pinned' => true])
        ->assertCreated()->json('data.id');
    $tag = $this->withToken($this->token)->postJson("/api/v1/client/guests/{$guest}/tags", ['label' => 'VIP'])->assertCreated()->json('data.id');
    $this->withToken($this->token)->postJson("/api/v1/client/guests/{$guest}/tags", ['label' => 'VIP'])->assertStatus(400)->assertJsonPath('message', 'That tag is already on this guest.');
    $this->withToken($this->token)->postJson("/api/v1/client/guests/{$guest}/reminders", ['title' => 'Call', 'due_at' => '2026-01-01T10:00:00Z'])
        ->assertStatus(400)->assertJsonPath('message', 'That reminder date has already passed.');
    $this->withToken($this->token)->postJson("/api/v1/client/guests/{$guest}/reminders", ['title' => 'Call', 'due_at' => '2026-11-01T10:00:00Z', 'note_id' => $note])->assertCreated();

    $this->withToken($this->token)->deleteJson("/api/v1/client/guests/{$guest}/tags/{$tag}")->assertOk();
    $this->withToken($this->token)->postJson("/api/v1/client/guests/{$guest}/tags", ['label' => 'VIP'])->assertCreated()->assertJsonPath('data.id', $tag);

    $this->withToken($this->token)->getJson("/api/v1/client/guests/{$guest}/profile")
        ->assertOk()->assertJsonPath('data.notes.0.title', 'Allergies')->assertJsonPath('data.notes.0.author', 'Host')
        ->assertJsonPath('data.tags.0.label', 'VIP')->assertJsonPath('data.reminders.0.state', 'upcoming')
        ->assertJsonPath('data.summary.reminders_open', 1)->assertJsonPath('data.identity.events_invited', 0);
});

it('reports guest analytics', function () {
    Participant::query()->create(['event_id' => $this->event->id, 'name' => 'A', 'response_type' => 'yes', 'rsvp_status' => 'accepted', 'responded_at' => now(), 'party_size' => 2]);
    Participant::query()->create(['event_id' => $this->event->id, 'name' => 'B', 'rsvp_status' => 'invited', 'invited_at' => now()]);

    $this->withToken($this->token)->getJson('/api/v1/client/events/analytics')
        ->assertOk()->assertJsonPath('data.totals.total_guests', 3)->assertJsonPath('data.totals.total_rsvps', 2)
        ->assertJsonPath('data.rsvp_breakdown.0.count', 1)->assertJsonPath('data.event_totals.total_events', 1)
        ->assertJsonPath('data.top_events.0.name', 'Big Day')->assertJsonPath('data.totals.messages_sent', 0);
});
