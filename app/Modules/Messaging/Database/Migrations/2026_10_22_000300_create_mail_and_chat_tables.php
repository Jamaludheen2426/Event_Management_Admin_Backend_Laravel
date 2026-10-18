<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Messaging, part 3: in-app mail and chat between admins, vendors and
| clients. A person is (type, id): admin = users.id, vendor = vendors.id (the
| business, not a staff login), client = clients.id.
|
| chat_read_states is folded into chat_participants, which already carried
| the same last-read columns. Dropped as never used: chat_conversations
| .context_id, chat_participants.is_muted, chat_messages.edited_at.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mail_folders', function (Blueprint $table): void {
            $table->id();
            $table->enum('owner_type', ['admin', 'vendor', 'client']);
            $table->unsignedBigInteger('owner_id');
            $table->string('name', 100);
            $table->unsignedTinyInteger('is_active')->default(1);
            $table->timestamps();
            $table->softDeletes();
            $table->index(['owner_type', 'owner_id', 'is_active']);
        });

        Schema::create('mails', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->enum('sender_type', ['admin', 'vendor', 'client']);
            $table->unsignedBigInteger('sender_id');
            $table->string('subject', 500);
            $table->longText('body');
            $table->enum('status', ['draft', 'sent', 'failed'])->default('draft');
            $table->timestamp('sent_at')->nullable();
            $table->text('error_message')->nullable();
            // The sender's copy: 1 = in Sent/Drafts, 0 = in Trash, 2 = deleted for good.
            $table->unsignedTinyInteger('sender_is_active')->default(1);
            $table->string('sender_label', 50)->nullable();
            $table->foreignId('sender_custom_folder_id')->nullable()->constrained('mail_folders')->nullOnDelete();
            $table->timestamps();
            $table->index(['sender_type', 'sender_id', 'status', 'sender_is_active']);
        });

        Schema::create('mail_recipients', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('mail_id')->constrained()->cascadeOnDelete();
            $table->enum('recipient_type', ['admin', 'vendor', 'client']);
            $table->unsignedBigInteger('recipient_id');
            $table->enum('role', ['to', 'cc', 'bcc'])->default('to');
            $table->boolean('is_read')->default(false);
            // The recipient's copy: 1 = in Inbox, 0 = in Trash, 2 = deleted for good.
            $table->unsignedTinyInteger('is_active')->default(1);
            $table->string('label', 50)->nullable();
            $table->foreignId('custom_folder_id')->nullable()->constrained('mail_folders')->nullOnDelete();
            $table->timestamps();
            $table->index(['recipient_type', 'recipient_id', 'is_active']);
        });

        Schema::create('mail_notifications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('mail_id')->constrained()->cascadeOnDelete();
            $table->enum('recipient_type', ['admin', 'vendor', 'client']);
            $table->unsignedBigInteger('recipient_id');
            $table->boolean('is_read')->default(false);
            $table->timestamp('created_at')->nullable();
            $table->index(['recipient_type', 'recipient_id', 'is_read']);
        });

        Schema::create('chat_conversations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            // Every chat involves a vendor: admin ↔ vendor, admin ↔ client (the client's vendor), vendor ↔ client.
            $table->foreignId('vendor_id')->nullable()->constrained()->nullOnDelete();
            $table->enum('conversation_type', ['direct', 'group'])->default('direct');
            $table->string('context_type', 80)->default('general');
            $table->string('title')->nullable();
            $table->unsignedBigInteger('last_message_id')->nullable();
            $table->timestamp('last_message_at')->nullable();
            $table->unsignedTinyInteger('is_active')->default(1);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('chat_participants', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('conversation_id')->constrained('chat_conversations')->cascadeOnDelete();
            $table->enum('actor_type', ['admin', 'vendor', 'client']);
            $table->unsignedBigInteger('actor_id');
            $table->string('display_name')->nullable();
            $table->string('avatar', 500)->nullable();
            $table->unsignedBigInteger('last_read_message_id')->nullable();
            $table->timestamp('last_read_at')->nullable();
            $table->unsignedTinyInteger('is_active')->default(1);
            $table->timestamps();
            $table->unique(['conversation_id', 'actor_type', 'actor_id']);
            $table->index(['actor_type', 'actor_id', 'is_active']);
        });

        Schema::create('chat_messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('conversation_id')->constrained('chat_conversations')->cascadeOnDelete();
            $table->enum('sender_type', ['admin', 'vendor', 'client']);
            $table->unsignedBigInteger('sender_id');
            $table->text('message');
            $table->enum('message_type', ['text', 'system'])->default('text');
            // {attachment: {url, name, type, size}}
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['conversation_id', 'id']);
        });
    }

    public function down(): void
    {
        foreach (['chat_messages', 'chat_participants', 'chat_conversations', 'mail_notifications', 'mail_recipients', 'mails', 'mail_folders'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
