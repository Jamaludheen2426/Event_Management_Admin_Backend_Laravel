<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Messaging, part 2: what an organizer sends to their event's participants.
|
| v1 had two table pairs for one idea: event_message_campaigns + event_messages
| (the Messages screen: WhatsApp / e-mail / push, by guest group) and
| event_notifications + event_notification_recipients (the app's "Send
| Notification": in-app / push, by side and relation). Both are a broadcast
| with recipients; `source` keeps each screen's rows apart.
|
| event_message_campaigns.window_start / window_end are dropped (never used).
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_broadcasts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            // The host or organizer who wrote it.
            $table->foreignId('sent_by_client_id')->nullable()->constrained('clients')->nullOnDelete();
            // message = the Messages screen (v1 campaigns); notice = the app's notifications.
            $table->enum('source', ['message', 'notice']);
            $table->string('title');
            $table->mediumText('body')->nullable();
            // message: one delivery channel; notice: in_app and/or push.
            $table->enum('channel', ['email', 'sms', 'whatsapp', 'push'])->nullable();
            $table->json('channels')->nullable();
            $table->enum('kind', ['invite', 'reminder', 'update', 'thank_you', 'custom'])->nullable();
            // message: all | groups | guests; notice: all | specific | groups.
            $table->enum('audience', ['all', 'groups', 'guests', 'specific'])->default('all');
            $table->json('group_ids')->nullable();
            $table->json('participant_ids')->nullable();
            $table->json('sides')->nullable();
            $table->json('relationships')->nullable();
            $table->string('image_url', 500)->nullable();
            // Push only: open_app | deep_link | custom, extra data, FCM options.
            $table->string('click_action', 30)->nullable();
            $table->string('deep_link', 500)->nullable();
            $table->json('data_payload')->nullable();
            $table->json('push_options')->nullable();
            $table->enum('status', ['draft', 'scheduled', 'sending', 'sent', 'failed'])->default('draft');
            $table->timestamp('scheduled_at')->nullable();
            // The label the sender picked; scheduled_at is the instant.
            $table->string('timezone', 80)->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->unsignedInteger('recipients_count')->default(0);
            $table->unsignedInteger('delivered_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            $table->string('failed_reason')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['event_id', 'source']);
            $table->index(['status', 'scheduled_at']);
        });

        Schema::create('event_broadcast_recipients', function (Blueprint $table): void {
            $table->id();
            // NULL: a message recorded on its own (an invitation sent outside a broadcast).
            $table->foreignId('broadcast_id')->nullable()->constrained('event_broadcasts')->cascadeOnDelete();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->foreignId('participant_id')->nullable()->constrained()->nullOnDelete();
            // The participant's app account, when they have one.
            $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete();
            $table->enum('channel', ['whatsapp', 'email', 'sms', 'push', 'in_app']);
            $table->enum('kind', ['invite', 'reminder', 'update', 'thank_you'])->nullable();
            // queued = recorded, nothing left the platform yet.
            $table->enum('status', ['queued', 'sent', 'delivered', 'failed'])->default('queued');
            $table->enum('sender', ['client', 'system'])->default('system');
            $table->foreignId('sender_client_id')->nullable()->constrained('clients')->nullOnDelete();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('clicked_at')->nullable();
            $table->boolean('is_read')->default(false);
            $table->timestamp('read_at')->nullable();
            $table->string('failed_reason')->nullable();
            $table->timestamps();
            $table->unique(['broadcast_id', 'participant_id']);
            $table->index(['event_id', 'kind', 'status']);
        });

        // An event owner switching one of the platform's automatic notices off.
        Schema::create('event_notification_template_prefs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->foreignId('notification_template_id')->constrained(indexName: 'event_template_pref_template_fk')->cascadeOnDelete();
            $table->boolean('enabled')->default(true);
            $table->timestamps();
            $table->unique(['event_id', 'notification_template_id'], 'event_template_pref_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_notification_template_prefs');
        Schema::dropIfExists('event_broadcast_recipients');
        Schema::dropIfExists('event_broadcasts');
    }
};
