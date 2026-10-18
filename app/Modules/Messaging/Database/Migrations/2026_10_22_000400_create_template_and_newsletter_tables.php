<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Messaging, part 4: the platform's e-mail templates (Settings → Email), a
| vendor's own e-mail categories and templates, and the vendor's newsletter.
|
| email_templates.is_predefined is dropped (never set). v1's
| vendor_newsletter_sends + vendor_newsletter_sent_logs become
| vendor_newsletters + vendor_newsletter_recipients; a send keeps the in-app
| mail it created (mail_id).
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_templates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->string('slug', 100);
            // header / footer are parts other templates put around their body.
            $table->enum('type', ['header', 'footer', 'template'])->default('template');
            $table->string('subject')->nullable();
            $table->longText('body')->nullable();
            $table->json('variables')->nullable();
            $table->string('description', 500)->nullable();
            $table->foreignId('header_id')->nullable()->constrained('email_templates')->nullOnDelete();
            $table->foreignId('footer_id')->nullable()->constrained('email_templates')->nullOnDelete();
            $table->foreignId('email_config_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedTinyInteger('is_active')->default(1);
            $table->enum('approval_status', ['approved', 'pending'])->default('approved');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->aliveColumn();
            $table->unique(['company_id', 'slug', 'alive']);
        });

        Schema::create('vendor_email_categories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('vendor_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->text('description')->nullable();
            $table->integer('sort_order')->default(0);
            $table->unsignedTinyInteger('is_active')->default(1);
            $table->timestamps();
            $table->softDeletes();
            $table->aliveColumn();
            $table->unique(['vendor_id', 'name', 'alive']);
        });

        Schema::create('vendor_email_templates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('vendor_id')->constrained()->cascadeOnDelete();
            $table->foreignId('category_id')->nullable()->constrained('vendor_email_categories')->nullOnDelete();
            $table->string('name');
            // The template's content (rich text); v1 called it description.
            $table->longText('description')->nullable();
            $table->unsignedTinyInteger('is_active')->default(1);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('vendor_newsletters', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('vendor_id')->constrained()->cascadeOnDelete();
            $table->foreignId('template_id')->nullable()->constrained('vendor_email_templates')->nullOnDelete();
            $table->string('template_name');
            $table->foreignId('category_id')->nullable()->constrained('vendor_email_categories')->nullOnDelete();
            $table->enum('user_type', ['Guest', 'Registered'])->default('Registered');
            // Tier names the send was narrowed to; NULL = every tier.
            $table->json('plans')->nullable();
            $table->unsignedInteger('total_sent')->default(0);
            $table->foreignId('mail_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('vendor_newsletter_recipients', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('newsletter_id')->constrained('vendor_newsletters')->cascadeOnDelete();
            $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete();
            $table->string('email');
            $table->string('name');
            $table->string('membership', 100)->nullable();
            $table->string('template');
            $table->enum('status', ['pending', 'sent', 'failed', 'bounced'])->default('pending');
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('clicked_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['vendor_newsletter_recipients', 'vendor_newsletters', 'vendor_email_templates', 'vendor_email_categories', 'email_templates'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
