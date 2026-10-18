<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Clients (vendor layer) — one table for the two v1 client systems.
|
| v1 had website_clients (the client portal and app, mostly vendor 1) and
| vendor_clients (a vendor's own customer list, with a separate login nobody
| could use for events). Both are `clients` now: every client belongs to one
| vendor, signs in through one guard, and can use every client feature.
|
|  - email and mobile are unique per vendor among live rows (v1: website
|    clients per vendor, vendor clients' email globally);
|  - source says where the client came from (website, google, facebook, admin,
|    vendor); code is v1 vendor_clients.client_id ("CLI-…");
|  - plan_id is the platform plan (v1 website_clients.subscription_plan_id),
|    plan_type_id the vendor's tier for this client (v1 vendor_clients
|    subscription_id + plan text);
|  - newsletter_opt_in is v1 client_type subscribed/unsubscribed;
|  - favourites move from JSON columns to client_favourites.
|
| Sign-ins: client_sessions holds the device of each sign-in; its tokens live in
| personal_access_tokens with session_id, so "sign out that device" revokes
| exactly them. (v1: client_sessions + client_refresh_tokens.)
|
| newsletter_subscribers is v1 vendor_subscribers: e-mail-only newsletter list
| of a vendor.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clients', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('vendor_id')->constrained()->cascadeOnDelete();
            $table->string('code', 50)->nullable()->unique();
            $table->enum('source', ['website', 'google', 'facebook', 'admin', 'vendor'])->default('website');
            $table->string('provider_id', 64)->nullable();
            $table->string('name', 200);
            $table->string('company_name', 150)->nullable();
            $table->text('bio')->nullable();
            $table->string('email')->nullable();
            $table->string('dial_code', 8)->nullable()->default('+91');
            $table->string('mobile', 20)->nullable();
            $table->string('password')->nullable();
            $table->string('avatar_url', 500)->nullable();
            $table->text('address')->nullable();
            $table->string('country', 100)->nullable();
            $table->string('state', 100)->nullable();
            $table->string('district', 100)->nullable();
            $table->string('city', 100)->nullable();
            $table->string('locality', 100)->nullable();
            $table->string('pincode', 20)->nullable();
            $table->foreignId('plan_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('plan_type_id')->nullable()->constrained()->nullOnDelete();
            $table->enum('registration_type', ['guest', 'client'])->default('client');
            $table->boolean('newsletter_opt_in')->default(false);
            $table->boolean('login_access')->default(true);
            $table->boolean('send_credentials_to_email')->default(false);
            $table->boolean('email_verified')->default(false);
            $table->boolean('mobile_verified')->default(false);
            $table->string('otp_hash')->nullable();
            $table->timestamp('otp_expires_at')->nullable();
            $table->unsignedTinyInteger('otp_attempts')->default(0);
            // 1 active, 0 inactive, 2 blocked (v1 values; the admin stats count all three).
            $table->unsignedTinyInteger('is_active')->default(1);
            $table->timestamp('last_login_at')->nullable();
            $table->timestamp('password_changed_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->aliveColumn();
            $table->unique(['vendor_id', 'email', 'alive']);
            $table->unique(['vendor_id', 'mobile', 'alive']);
            $table->index(['vendor_id', 'source', 'provider_id']);
        });

        Schema::create('client_favourites', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->enum('kind', ['template', 'event']);
            $table->string('ref', 64);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
            $table->unique(['client_id', 'kind', 'ref']);
        });

        Schema::create('client_sessions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->enum('transport', ['web', 'app'])->default('web');
            $table->string('device_name', 120)->nullable();
            $table->string('device_type', 20)->nullable();
            $table->string('browser', 60)->nullable();
            $table->string('os', 60)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->string('location', 120)->nullable();
            $table->timestamp('last_active_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->string('revoked_reason', 40)->nullable();
            $table->timestamp('trusted_until')->nullable();
            $table->timestamps();
            $table->index(['client_id', 'revoked_at']);
        });

        Schema::create('client_two_factor', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('client_id')->unique()->constrained()->cascadeOnDelete();
            $table->text('secret');
            $table->boolean('is_enabled')->default(false);
            $table->timestamp('confirmed_at', 3)->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->unsignedBigInteger('last_used_counter')->nullable();
            $table->timestamps();
        });

        Schema::create('client_backup_codes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->string('code_hash');
            $table->timestamp('used_at')->nullable();
            $table->timestamps();
        });

        Schema::create('client_preferences', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('client_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('language_code', 10)->default('en');
            $table->string('date_format', 20)->default('DD/MM/YYYY');
            $table->string('time_zone', 64)->default('Asia/Kolkata');
            $table->enum('theme', ['light', 'dark', 'system'])->default('light');
            $table->string('default_landing', 40)->default('dashboard');
            $table->unsignedSmallInteger('items_per_page')->default(20);
            $table->boolean('compact_mode')->default(false);
            $table->boolean('auto_save')->default(true);
            $table->boolean('show_tips')->default(true);
            $table->boolean('emails_disabled')->default(false);
            $table->boolean('in_app_disabled')->default(false);
            $table->timestamp('dnd_starts_at')->nullable();
            $table->timestamp('dnd_ends_at')->nullable();
            $table->timestamps();
        });

        Schema::create('client_notification_prefs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->enum('channel', ['email', 'in_app']);
            $table->string('type', 60);
            $table->boolean('enabled')->default(true);
            $table->string('frequency', 30)->nullable();
            $table->boolean('sound')->nullable();
            $table->timestamps();
            $table->unique(['client_id', 'channel', 'type']);
        });

        Schema::create('client_devices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->string('token')->unique();
            $table->enum('platform', ['android', 'ios', 'web'])->default('android');
            $table->string('device_name', 120)->nullable();
            $table->string('app_version', 40)->nullable();
            $table->boolean('is_active')->default(true);
            $table->string('disabled_reason', 120)->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();
        });

        Schema::create('newsletter_subscribers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('vendor_id')->constrained()->cascadeOnDelete();
            $table->string('email', 190);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
            $table->aliveColumn();
            $table->unique(['vendor_id', 'email', 'alive']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('newsletter_subscribers');
        Schema::dropIfExists('client_devices');
        Schema::dropIfExists('client_notification_prefs');
        Schema::dropIfExists('client_preferences');
        Schema::dropIfExists('client_backup_codes');
        Schema::dropIfExists('client_two_factor');
        Schema::dropIfExists('client_sessions');
        Schema::dropIfExists('client_favourites');
        Schema::dropIfExists('clients');
    }
};
