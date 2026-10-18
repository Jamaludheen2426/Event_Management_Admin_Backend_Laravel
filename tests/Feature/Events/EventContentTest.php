<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\EventCategory;
use App\Modules\Catalog\Models\EventMenu;
use App\Modules\Catalog\Models\Plan;
use App\Modules\Catalog\Models\PlanMenu;
use App\Modules\Events\Models\Event;
use App\Modules\Events\Models\Participant;
use App\Modules\Events\Models\Wish;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;

/*
| Agenda, family, gallery, wishes and splash screens — v1 clientAgenda,
| clientFamily, clientGallery, clientWish, clientSplashScreen.
*/

beforeEach(function () {
    seedHouse();
    $this->travelTo(now()->setDate(2026, 10, 20)->setTime(10, 0));
    $this->uploads = storage_path('framework/testing/uploads-'.uniqid());
    config(['media.local_root' => $this->uploads, 'media.local_url' => 'http://api.test/uploads', 'media.ffmpeg' => 'no-such-ffmpeg']);

    $category = EventCategory::query()->create(['company_id' => 1, 'name' => 'Wedding', 'is_active' => 1]);
    $plan = Plan::query()->create(['company_id' => 1, 'name' => 'Gold', 'plan_code' => 'GOLD', 'is_active' => 1, 'max_photos' => 2, 'max_videos' => 1]);
    $agenda = EventMenu::query()->create(['company_id' => 1, 'name' => 'Agenda', 'slug' => 'agenda', 'is_active' => 1, 'is_default' => 1]);
    PlanMenu::query()->create(['plan_id' => $plan->id, 'event_menu_id' => $agenda->id]);
    $this->host = client(['plan_id' => $plan->id, 'mobile' => '9800000001', 'name' => 'Host']);
    $this->token = accessToken($this->host);
    $this->event = Event::query()->find($this->withToken($this->token)->postJson('/api/v1/client/events', [
        'event_category_id' => $category->id, 'name' => 'Big Day', 'start_date' => '2026-12-01', 'end_date' => '2026-12-02',
        'start_time' => '10:00', 'end_time' => '20:00', 'menu_ids' => [],
    ])->assertCreated()->json('data.event.id'));
    $this->guest = client(['mobile' => '9811111111', 'name' => 'Guest']);
    Participant::query()->create(['event_id' => $this->event->id, 'name' => 'Meena', 'mobile' => '9811111111', 'client_id' => $this->guest->id]);
    $this->guestToken = accessToken($this->guest);
});

afterEach(function () {
    File::deleteDirectory($this->uploads);
});

it('keeps the agenda inside the event dates; guests read it', function () {
    $id = $this->event->id;
    $this->withToken($this->token)->postJson("/api/v1/client/events/{$id}/agenda", ['title' => 'Mehndi', 'agenda_date' => '2026-11-30', 'start_time' => '10:00', 'end_time' => '12:00'])
        ->assertStatus(400)->assertJsonPath('message', 'The agenda date must be within the event dates.');
    $this->withToken($this->token)->postJson("/api/v1/client/events/{$id}/agenda", ['title' => 'Mehndi', 'agenda_date' => '2026-12-01', 'start_time' => '10:00', 'end_time' => '10:00'])
        ->assertStatus(400)->assertJsonPath('message', 'The end time must be different from the start time.');
    $first = $this->withToken($this->token)->postJson("/api/v1/client/events/{$id}/agenda", ['title' => 'Mehndi', 'agenda_date' => '2026-12-01', 'start_time' => '10:00', 'end_time' => '12:00'])
        ->assertCreated()->assertJsonPath('data.item.start_time', '10:00')->json('data.item.id');
    $second = $this->withToken($this->token)->postJson("/api/v1/client/agenda/{$first}/duplicate")->assertCreated()->assertJsonPath('data.item.title', 'Mehndi (Copy)')->json('data.item.id');

    $this->withToken($this->token)->putJson("/api/v1/client/events/{$id}/agenda/reorder", ['ids' => [$second, $first]])
        ->assertOk()->assertJsonPath('data.items.0.id', $second);

    $this->withToken($this->guestToken)->getJson("/api/v1/client/events/{$id}/agenda")
        ->assertOk()->assertJsonPath('data.can_edit', false)->assertJsonPath('data.show_in_app', true)
        ->assertJsonPath('data.days', ['2026-12-01', '2026-12-02'])->assertJsonCount(2, 'data.items');
    $this->withToken($this->guestToken)->putJson("/api/v1/client/agenda/{$first}", ['title' => 'x'])->assertNotFound();
});

it('shows family contact details only to the host', function () {
    $id = $this->event->id;
    $this->withToken($this->token)->postJson("/api/v1/client/events/{$id}/family-members", ['name' => 'Raj'])
        ->assertStatus(400)->assertJsonPath('message', 'Please fill all mandatory fields.');
    $member = $this->withToken($this->token)->postJson("/api/v1/client/events/{$id}/family-members", ['name' => 'Raj', 'side' => 'Groom', 'relationship' => 'Father', 'mobile' => '+91 98765 43210', 'photo' => 'https://cdn.test/raj.jpg'])
        ->assertCreated()->assertJsonPath('data.member.mobile', '919876543210')->json('data.member.id');

    $this->withToken($this->token)->putJson("/api/v1/client/events/{$id}/family-settings", ['profile_photo' => false])->assertOk();
    $this->withToken($this->guestToken)->getJson("/api/v1/client/events/{$id}/family-members")
        ->assertOk()->assertJsonPath('data.items.0.full_name', 'Raj')->assertJsonPath('data.items.0.photo', null)
        ->assertJsonMissingPath('data.items.0.mobile');
    $this->withToken($this->token)->putJson("/api/v1/client/events/{$id}/family-settings", ['profile_screen' => false])->assertOk();
    $this->withToken($this->guestToken)->getJson("/api/v1/client/family-members/{$member}")
        ->assertStatus(403)->assertJsonPath('message', 'The organizer has turned off family profiles.');
});

it('uploads photos within the plan, into the default album', function () {
    $id = $this->event->id;
    $photo = fn () => UploadedFile::fake()->image('p.jpg', 1200, 800);

    $item = $this->withToken($this->token)->post("/api/v1/client/events/{$id}/gallery", ['file' => $photo(), 'caption' => 'Hi'], ['Accept' => 'application/json'])
        ->assertOk()->assertJsonPath('data.usage.photos.used', 1)->json('data.item');
    expect($item['thumbnail_url'])->not->toBe($item['url']);
    $this->withToken($this->token)->post("/api/v1/client/events/{$id}/gallery", ['file' => $photo()], ['Accept' => 'application/json'])->assertOk();
    $this->withToken($this->token)->post("/api/v1/client/events/{$id}/gallery", ['file' => $photo()], ['Accept' => 'application/json'])
        ->assertStatus(400)->assertJsonPath('message', 'This event has reached its limit of 2 images. Please upgrade your plan to add more.');

    // Without ffmpeg a requested mute cannot be honoured.
    $this->withToken($this->token)->post("/api/v1/client/events/{$id}/gallery", ['file' => UploadedFile::fake()->create('v.mp4', 100, 'video/mp4'), 'mute' => 'true'], ['Accept' => 'application/json'])
        ->assertStatus(400)->assertJsonPath('message', 'The sound could not be removed from this video. Please try again.');

    $album = $this->withToken($this->token)->postJson("/api/v1/client/events/{$id}/gallery/categories", ['name' => 'Haldi', 'position' => 1])
        ->assertCreated()->json('data.category.id');
    $this->withToken($this->token)->putJson("/api/v1/client/gallery/{$item['id']}", ['category_id' => $album, 'show_in_app' => false])->assertOk();
    $this->withToken($this->guestToken)->getJson("/api/v1/client/events/{$id}/gallery")
        ->assertOk()->assertJsonCount(1, 'data.items')->assertJsonPath('data.can_upload', false);

    $default = collect($this->withToken($this->token)->getJson("/api/v1/client/events/{$id}/gallery/categories")->json('data.categories'))->firstWhere('is_default', true);
    $this->withToken($this->token)->putJson("/api/v1/client/gallery/categories/{$default['id']}", ['name' => 'Renamed'])
        ->assertStatus(400)->assertJsonPath('message', 'The Gallery category cannot be renamed.');
    $this->withToken($this->token)->deleteJson("/api/v1/client/gallery/categories/{$album}")->assertOk();
    $this->withToken($this->token)->getJson("/api/v1/client/events/{$id}/gallery?category_id=0")->assertJsonCount(2, 'data.items');
});

it('moderates wishes: filter, hide, block', function () {
    $id = $this->event->id;
    $this->withToken($this->token)->putJson("/api/v1/client/events/{$id}/wish-filter", ['enabled' => true, 'words' => [['word' => 'bad'], ['word' => 'BAD']]])
        ->assertOk()->assertJsonCount(1, 'data.filter.words')->assertJsonPath('data.filter.words.0.replace', '***');

    $clean = $this->withToken($this->guestToken)->postJson("/api/v1/client/events/{$id}/wishes", ['message' => 'Happy days!'])
        ->assertCreated()->assertJsonPath('data.wish.guest_name', 'Meena')->assertJsonPath('data.wish.is_mine', true)->json('data.wish.id');
    $this->withToken($this->guestToken)->postJson("/api/v1/client/events/{$id}/wishes", ['message' => 'Not bad, badly'])
        ->assertCreated()->assertJsonPath('data.wish.message', 'Not ***, badly')->assertJsonPath('data.wish.is_hidden', true);

    $other = client(['mobile' => '9822222222']);
    Participant::query()->create(['event_id' => $id, 'name' => 'Other', 'mobile' => '9822222222', 'client_id' => $other->id]);
    $this->withToken(accessToken($other))->getJson("/api/v1/client/events/{$id}/wishes")->assertJsonCount(1, 'data.wishes');
    $this->withToken(accessToken($other))->postJson("/api/v1/client/wishes/{$clean}/like")->assertOk()->assertJsonPath('data.wish.like_count', 1);

    $this->withToken($this->token)->getJson("/api/v1/client/events/{$id}/wishes")
        ->assertOk()->assertJsonPath('data.counts.all', 2)->assertJsonPath('data.counts.flagged', 1)->assertJsonPath('data.counts.hidden', 1);
    $this->withToken($this->token)->putJson("/api/v1/client/wishes/{$clean}", ['message' => 'Edited'])
        ->assertStatus(403)->assertJsonPath('message', "The organizer cannot edit a participant's wish.");

    $this->withToken($this->token)->postJson("/api/v1/client/wishes/{$clean}/block")->assertOk()->assertJsonPath('data.wish.status', 'blocked');
    $this->withToken($this->guestToken)->getJson("/api/v1/client/events/{$id}/wishes")
        ->assertStatus(403)->assertJsonPath('message', 'Wed Wishes is not available for you on this event.');
    $menus = $this->withToken($this->guestToken)->getJson("/api/v1/client/events/{$id}")->json('data.event.menus');
    expect(array_column($menus, 'slug'))->not->toContain('wishes');

    $this->withToken($this->token)->postJson("/api/v1/client/wishes/{$clean}/block", ['remove_wishes' => true])->assertOk()->assertJsonPath('data.removed', 2);
    expect(Wish::query()->count())->toBe(0);
});

it('plays one splash per event, without organizer details', function () {
    $id = $this->event->id;
    $form = ['event_id' => $id, 'name' => 'Intro', 'main_title' => 'Welcome', 'background_type' => 'color', 'components' => ['organizer' => true, 'title' => true], 'status' => 'active'];

    $splash = $this->withToken($this->token)->postJson('/api/v1/client/splash-screens', $form)
        ->assertCreated()->assertJsonPath('data.splash_screen.background_type', 'solid_color')
        ->assertJsonPath('data.splash_screen.components.organizer', false)->json('data.splash_screen.id');
    $this->withToken($this->token)->postJson('/api/v1/client/splash-screens', $form)
        ->assertStatus(400)->assertJsonPath('message', 'That event already has a splash screen ("Intro"). Edit that one, or pick another event.');

    $this->withToken($this->guestToken)->getJson("/api/v1/client/splash-screens/for-event/{$id}")
        ->assertOk()->assertJsonPath('data.splash_screen.main_title', 'Welcome')->assertJsonMissingPath('data.splash_screen.name');
    $this->withToken(accessToken(client()))->getJson("/api/v1/client/splash-screens/for-event/{$id}")->assertJsonPath('data.splash_screen', null);
    $this->withToken($this->token)->getJson('/api/v1/client/splash-screens')->assertOk()->assertJsonPath('pagination.totalItems', 1);
    $this->withToken($this->token)->deleteJson("/api/v1/client/splash-screens/{$splash}")->assertOk();
});
