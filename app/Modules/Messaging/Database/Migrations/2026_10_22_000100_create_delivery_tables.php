<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Messaging, part 1: how messages leave the platform (e-mail provider, Firebase
| project) and the client's notification feed.
|
| email_configs.imap_* are dropped: the admin form sends them, but v1's model
| never declared them, so they were never saved or returned.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_configs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->string('from_email');
            $table->string('from_name', 100)->nullable();
            $table->enum('driver', ['smtp', 'brevo', 'elasticemail', 'sendmail'])->default('smtp');
            $table->string('host')->nullable();
            $table->unsignedInteger('port')->nullable();
            $table->string('username')->nullable();
            // Encrypted at rest (v1 stored both in plain text).
            $table->text('password')->nullable();
            $table->text('api_key')->nullable();
            $table->enum('encryption', ['tls', 'ssl', 'none'])->nullable()->default('tls');
            $table->string('domain')->nullable();
            $table->string('region', 100)->nullable();
            $table->boolean('is_default')->default(false);
            $table->unsignedTinyInteger('is_active')->default(1);
            $table->enum('approval_status', ['approved', 'pending'])->default('approved');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->aliveColumn();
            $table->unique(['company_id', 'name', 'alive']);
        });

        Schema::create('push_notification_configs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            // One active Firebase project per company; the app's tokens belong to it.
            $table->boolean('is_active')->default(false);
            // Encrypted at rest (v1 stored the service account in plain text).
            $table->longText('service_account_json')->nullable();
            $table->text('private_key')->nullable();
            $table->string('project_id')->nullable();
            $table->string('client_email')->nullable();
            $table->string('web_api_key')->nullable();
            $table->string('app_id')->nullable();
            $table->string('messaging_sender_id')->nullable();
            $table->string('auth_domain')->nullable();
            $table->string('storage_bucket')->nullable();
            $table->string('measurement_id')->nullable();
            $table->string('vapid_key')->nullable();
            $table->enum('connection_status', ['connected', 'disconnected', 'pending', 'error'])->default('pending');
            $table->timestamp('last_verified_at')->nullable();
            $table->text('validation_error')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
            $table->index(['company_id', 'is_active']);
        });

        Schema::create('client_notifications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->enum('category', ['rsvp', 'reminder', 'message', 'system', 'guest'])->default('system');
            $table->string('type', 60);
            $table->string('title', 200);
            $table->string('body', 500)->nullable();
            $table->foreignId('event_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('participant_id')->nullable()->constrained()->nullOnDelete();
            // In-app path the row opens; NULL = nothing to open.
            $table->string('link')->nullable();
            // Extra detail for the side panel; never load-bearing.
            $table->json('meta')->nullable();
            $table->boolean('is_read')->default(false);
            $table->timestamp('read_at')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
            $table->index(['client_id', 'archived_at', 'created_at']);
            $table->index(['client_id', 'is_read', 'archived_at']);
            $table->index(['client_id', 'category', 'archived_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_notifications');
        Schema::dropIfExists('push_notification_configs');
        Schema::dropIfExists('email_configs');
    }
};
