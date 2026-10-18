<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Events (vendor layer). `events` is the aggregate root and carries vendor_id;
| everything else carries only its parent id (event, client or guest), so
| tenancy is derived and never repeated. v1 repeated website_client_id and
| company_id on every child row.
|
| Changes from v1:
|  - events.menu_ids / menu_order (JSON) → event_menu (selected + position).
|    disabled_app_menu_ids was always reset to [] and is dropped.
|  - events.rsvp/participant/family/wish settings (JSON on the event row) →
|    event_settings, one row per event.
|  - events.theme_id stays a template CODE (string): the premium themes live in
|    the frontends, not in event_templates, so it cannot be a foreign key.
|  - Phone book vs attendance kept as v1 decided (§581): `guests` are the
|    client's contacts, `participants` the people attending one event (from a
|    QR scan or added by hand), linked by guest_id.
|  - guests.migrated_from_guest_id (one-off migration column) dropped.
|  - Notes, tags and reminders hang off the guest and/or the participant.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('vendor_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('organizer_client_id')->nullable()->constrained('clients')->nullOnDelete();
            $table->foreignId('plan_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('event_category_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name', 200);
            $table->string('host_one', 120)->nullable();
            $table->string('host_two', 120)->nullable();
            $table->string('tagline', 150)->nullable();
            $table->text('description')->nullable();
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->time('start_time')->nullable();
            $table->time('end_time')->nullable();
            $table->string('timezone', 80)->nullable();
            $table->string('venue_name')->nullable();
            $table->string('venue_address', 500)->nullable();
            $table->string('venue_landmark')->nullable();
            $table->string('venue_map_link', 500)->nullable();
            $table->string('venue_image', 500)->nullable();
            $table->decimal('venue_lat', 10, 7)->nullable();
            $table->decimal('venue_lng', 10, 7)->nullable();
            $table->string('ceremony_title', 150)->nullable();
            $table->string('ceremony_venue')->nullable();
            $table->text('ceremony_description')->nullable();
            $table->string('organizer', 200)->nullable();
            $table->string('contact_phone', 30)->nullable();
            $table->string('contact_email', 150)->nullable();
            $table->string('footer_note', 300)->nullable();
            $table->enum('privacy', ['private', 'public', 'unlisted'])->default('private');
            $table->enum('status', ['draft', 'upcoming', 'cancelled'])->default('upcoming');
            $table->string('theme_id', 64)->nullable();
            $table->string('primary_color', 9)->nullable();
            $table->string('secondary_color', 9)->nullable();
            $table->string('cover_image', 500)->nullable();
            $table->string('organizer_image', 500)->nullable();
            $table->string('custom_image', 500)->nullable();
            $table->string('invitation_image', 500)->nullable();
            // The invitation layout: which blocks show, in which order (a document).
            $table->json('components')->nullable();
            $table->json('component_order')->nullable();
            $table->text('qr_token')->nullable();
            $table->unsignedTinyInteger('qr_version')->default(1);
            $table->timestamp('qr_issued_at')->nullable();
            $table->unsignedTinyInteger('qr_style')->default(0);
            $table->timestamps();
            $table->softDeletes();
            $table->index(['vendor_id', 'client_id']);
            $table->index('organizer_client_id');
        });

        Schema::create('event_menu', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->foreignId('event_menu_id')->constrained()->cascadeOnDelete();
            // Switched on by the host (v1 menu_ids). Default menus are always on.
            $table->boolean('selected')->default(false);
            // The host's tile order (v1 menu_order); NULL = catalogue order.
            $table->unsignedSmallInteger('position')->nullable();
            $table->unique(['event_id', 'event_menu_id']);
        });

        Schema::create('event_settings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('event_id')->unique()->constrained()->cascadeOnDelete();
            $table->json('rsvp')->nullable();
            $table->json('participant')->nullable();
            $table->json('family')->nullable();
            $table->json('wish')->nullable();
            $table->json('wish_filter')->nullable();
            $table->timestamps();
        });

        Schema::create('guest_groups', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->string('description', 255)->nullable();
            $table->string('color', 20)->nullable()->default('#EC4899');
            $table->enum('visibility', ['private', 'public'])->default('private');
            $table->boolean('is_default')->default(false);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('guests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('group_id')->nullable()->constrained('guest_groups')->nullOnDelete();
            $this->person($table);
            $table->enum('source', ['manual', 'import'])->default('manual');
            $table->timestamps();
            $table->softDeletes();
            $table->index(['client_id', 'mobile']);
        });

        Schema::create('participants', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->foreignId('guest_id')->nullable()->constrained()->nullOnDelete();
            // The account this participant signs in with (v1 participant_client_id).
            $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('added_by_client_id')->nullable()->constrained('clients')->nullOnDelete();
            $this->person($table);
            $table->string('table_number', 50)->nullable();
            $table->unsignedTinyInteger('party_size')->default(1);
            $table->enum('rsvp_status', ['not_responded', 'invited', 'pending', 'accepted', 'declined'])->default('not_responded');
            $table->enum('response_type', ['none', 'yes', 'no', 'maybe'])->default('none');
            $table->enum('invite_source', ['whatsapp', 'email', 'sms', 'manual', 'import', 'qr'])->default('manual');
            $table->timestamp('invited_at')->nullable();
            $table->timestamp('responded_at')->nullable();
            $table->boolean('plus_one')->default(false);
            $table->unsignedTinyInteger('plus_one_count')->default(0);
            $table->json('custom_answers')->nullable();
            $table->enum('accommodation', ['unknown', 'required', 'not_required'])->default('unknown');
            $table->enum('rsvp_side', ['groom', 'bride'])->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['event_id', 'response_type']);
            $table->index(['event_id', 'mobile']);
            $table->index('client_id');
        });

        Schema::create('participant_response_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('participant_id')->constrained()->cascadeOnDelete();
            $table->enum('from_response_type', ['none', 'yes', 'no', 'maybe'])->nullable();
            $table->enum('to_response_type', ['none', 'yes', 'no', 'maybe']);
            $table->unsignedTinyInteger('party_size')->default(1);
            $table->string('dietary_preference', 100)->nullable();
            $table->enum('accommodation', ['unknown', 'required', 'not_required'])->default('unknown');
            $table->string('notes', 500)->nullable();
            $table->enum('source', ['client', 'guest', 'import', 'system'])->default('client');
            $table->foreignId('changed_by_client_id')->nullable()->constrained('clients')->nullOnDelete();
            $table->timestamp('changed_at');
            $table->timestamps();
        });

        Schema::create('guest_notes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('guest_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('participant_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('title', 150);
            $table->mediumText('body')->nullable();
            $table->enum('category', ['general', 'personal', 'dietary', 'communication', 'reminder', 'logistics'])->default('general');
            $table->enum('visibility', ['internal', 'shared'])->default('internal');
            $table->boolean('is_pinned')->default(false);
            $table->foreignId('created_by_client_id')->nullable()->constrained('clients')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('guest_tags', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('guest_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('participant_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('label', 50);
            $table->string('color', 20)->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('guest_reminders', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('guest_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('participant_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('note_id')->nullable()->constrained('guest_notes')->nullOnDelete();
            $table->string('title', 200);
            $table->timestamp('due_at');
            $table->enum('status', ['pending', 'done', 'dismissed'])->default('pending');
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('created_by_client_id')->nullable()->constrained('clients')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('agenda_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->string('title', 200);
            $table->string('description', 1000)->nullable();
            $table->date('agenda_date');
            $table->date('end_date')->nullable();
            $table->time('start_time');
            $table->time('end_time');
            $table->string('location')->nullable();
            $table->json('images')->nullable();
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('family_members', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->string('title', 20)->nullable();
            $table->string('name', 150);
            $table->string('photo', 500)->nullable();
            $table->date('date_of_birth')->nullable();
            $table->enum('side', ['groom', 'bride']);
            $table->string('relationship', 100)->nullable();
            $table->foreignId('relationship_option_id')->nullable()->constrained('guest_options')->nullOnDelete();
            $table->string('dial_code', 8)->nullable();
            $table->string('mobile', 20)->nullable();
            $table->string('email', 150)->nullable();
            $table->string('notes', 500)->nullable();
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('gallery_categories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->string('description', 255)->nullable();
            $table->string('cover_image', 500)->nullable();
            $table->string('icon', 100)->nullable();
            $table->integer('sort_order')->default(0);
            $table->boolean('is_default')->default(false);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('gallery_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->foreignId('category_id')->nullable()->constrained('gallery_categories')->nullOnDelete();
            $table->enum('type', ['image', 'video'])->default('image');
            $table->string('url', 500);
            $table->string('thumbnail_url', 500)->nullable();
            $table->string('file_name')->nullable();
            $table->string('mime_type', 100)->nullable();
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->string('caption', 500)->nullable();
            $table->boolean('show_in_app')->default(true);
            $table->integer('sort_order')->default(0);
            $table->foreignId('uploaded_by')->nullable()->constrained('clients')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('wishes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->foreignId('author_client_id')->nullable()->constrained('clients')->nullOnDelete();
            $table->enum('post_type', ['text', 'image', 'video'])->default('text');
            $table->enum('audience', ['groom', 'bride', 'both'])->default('both');
            $table->string('guest_name', 150);
            $table->string('mobile', 20)->nullable();
            $table->string('message', 1000);
            $table->string('photo', 500)->nullable();
            $table->string('video_url', 500)->nullable();
            $table->string('thumbnail_url', 500)->nullable();
            $table->unsignedSmallInteger('duration_seconds')->nullable();
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->boolean('is_hidden')->default(false);
            $table->boolean('is_flagged')->default(false);
            $table->timestamps();
            $table->index(['event_id', 'created_at']);
        });

        Schema::create('wish_likes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('wish_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->timestamp('created_at')->nullable();
            $table->unique(['wish_id', 'client_id']);
        });

        Schema::create('wish_blocks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->foreignId('author_client_id')->constrained('clients')->cascadeOnDelete();
            $table->boolean('hide_menu')->default(true);
            $table->timestamp('created_at')->nullable();
            $table->unique(['event_id', 'author_client_id']);
        });

        Schema::create('splash_screens', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('event_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name', 150);
            $table->string('main_title', 150);
            $table->string('sub_title', 150)->nullable();
            $table->string('event_name', 200);
            $table->string('theme_id', 64)->nullable();
            $table->string('tagline', 200)->nullable();
            $table->enum('background_type', ['image', 'video', 'solid_color', 'gradient', 'logo', 'couple_photo'])->default('image');
            $table->string('background_url', 500)->nullable();
            $table->json('background_config')->nullable();
            $table->boolean('loader_enabled')->default(true);
            $table->json('loader_config')->nullable();
            $table->string('primary_color', 9)->nullable();
            $table->string('secondary_color', 9)->nullable();
            $table->string('bg_color', 9)->nullable();
            $table->string('decoration_url', 500)->nullable();
            $table->boolean('show_decoration')->default(true);
            $table->json('components')->nullable();
            $table->json('component_order')->nullable();
            $table->enum('status', ['draft', 'active'])->default('draft');
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        foreach (['splash_screens', 'wish_blocks', 'wish_likes', 'wishes', 'gallery_items', 'gallery_categories', 'family_members',
            'agenda_items', 'guest_reminders', 'guest_tags', 'guest_notes', 'participant_response_logs', 'participants', 'guests',
            'guest_groups', 'event_settings', 'event_menu', 'events'] as $table) {
            Schema::dropIfExists($table);
        }
    }

    /** The person fields guests and participants share (v1 guestFields). */
    private function person(Blueprint $table): void
    {
        $table->string('title', 20)->nullable();
        $table->string('first_name', 100)->nullable();
        $table->string('last_name', 100)->nullable();
        $table->string('name', 200);
        $table->date('date_of_birth')->nullable();
        $table->enum('gender', ['male', 'female', 'other'])->nullable();
        $table->string('email', 150)->nullable();
        $table->string('dial_code', 8)->nullable()->default('+91');
        $table->string('mobile', 20)->nullable();
        $table->string('whatsapp', 20)->nullable();
        $table->string('company', 150)->nullable();
        $table->string('relationship', 100)->nullable();
        $table->foreignId('relationship_option_id')->nullable()->constrained('guest_options')->nullOnDelete();
        $table->string('address_line1')->nullable();
        $table->string('address_line2')->nullable();
        $table->string('city', 100)->nullable();
        $table->string('state', 100)->nullable();
        $table->string('postal_code', 20)->nullable();
        $table->string('country', 100)->nullable()->default('India');
        $table->string('dietary_preference', 100)->nullable();
        $table->foreignId('food_preference_option_id')->nullable()->constrained('guest_options')->nullOnDelete();
        $table->string('special_requirements', 500)->nullable();
        $table->string('notes', 500)->nullable();
        $table->string('photo', 500)->nullable();
    }
};
