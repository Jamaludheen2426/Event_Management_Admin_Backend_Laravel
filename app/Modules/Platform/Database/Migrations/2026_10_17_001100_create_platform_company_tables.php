<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Company-scoped platform tables: settings and plugins, plus the admin UI text
| translations (global: one set of interface strings for the admin panel).
|
| settings: `key` was globally UNIQUE although the table had company_id, so a
| second company could never have its own value. Now unique per company.
| Values of secret keys (config/settings.php) are encrypted at rest.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('key', 100);
            $table->text('value')->nullable();
            $table->string('group', 50)->default('general');
            $table->enum('type', ['text', 'textarea', 'number', 'boolean', 'json', 'file'])->default('text');
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'key']);
            $table->index(['company_id', 'group']);
        });

        Schema::create('plugins', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('slug', 100);
            $table->string('name', 100);
            $table->text('description')->nullable();
            $table->string('category', 50);
            $table->string('icon', 100)->nullable();
            $table->boolean('is_active')->default(false);
            $table->string('config_group', 100)->nullable();
            $table->string('config_route')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'slug']);
        });

        Schema::create('translation_keys', function (Blueprint $table): void {
            $table->id();
            $table->string('key');
            $table->text('default_value')->nullable();
            $table->string('description', 500)->nullable();
            $table->string('group', 50)->default('common')->index();
            $table->enum('approval_status', ['approved', 'pending'])->default('approved');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
            $table->unique('key');
        });

        Schema::create('translations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('translation_key_id')->constrained()->cascadeOnDelete();
            $table->foreignId('language_id')->constrained()->cascadeOnDelete();
            $table->text('value')->nullable();
            $table->enum('status', ['auto', 'reviewed'])->default('auto');
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
            $table->unique(['translation_key_id', 'language_id']);
        });

        // Keys the frontends asked for that do not exist yet (reported automatically).
        Schema::create('missing_translation_keys', function (Blueprint $table): void {
            $table->id();
            $table->string('key');
            $table->text('default_value')->nullable();
            $table->string('page_url', 500)->nullable();
            $table->unsignedInteger('report_count')->default(1);
            $table->timestamp('first_reported_at')->nullable();
            $table->timestamp('last_reported_at')->nullable();
            $table->timestamp('resolved_at')->nullable()->index();
            $table->timestamps();
            $table->unique('key');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('missing_translation_keys');
        Schema::dropIfExists('translations');
        Schema::dropIfExists('translation_keys');
        Schema::dropIfExists('plugins');
        Schema::dropIfExists('settings');
    }
};
