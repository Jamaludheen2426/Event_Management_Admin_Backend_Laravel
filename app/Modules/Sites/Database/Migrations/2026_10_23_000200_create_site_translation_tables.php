<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Sites, part 2: content translations (v1 company_website_builder_languages,
| company_website_translation_keys, company_website_content_translations).
|
| A translation slot is (site, section, page_slug, record_id, field_key) —
| record_id is the id of the content row (or site_settings row) it belongs to.
| Keys are rebuilt from the content by a scan; a translation outlives its key
| on purpose (a slot whose address changed is re-adopted when it reappears).
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_languages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->string('code', 10);
            $table->string('name', 100);
            $table->string('native_name', 100)->nullable();
            $table->enum('direction', ['ltr', 'rtl'])->default('ltr');
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
            $table->unique(['site_id', 'code']);
        });

        Schema::create('site_translation_keys', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->string('section', 100);
            $table->string('page_slug', 100)->default('');
            $table->unsignedBigInteger('record_id')->default(0);
            $table->string('field_key', 100);
            $table->string('field_label', 150)->default('');
            // The English text the translations are made from.
            $table->longText('default_value')->nullable();
            $table->integer('sort_order')->default(0);
            $table->timestamps();
            $table->unique(['site_id', 'section', 'page_slug', 'record_id', 'field_key'], 'site_translation_keys_slot_unique');
        });

        Schema::create('site_translations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->string('section', 100);
            $table->string('page_slug', 100)->default('');
            $table->unsignedBigInteger('record_id')->default(0);
            $table->string('field_key', 100);
            $table->foreignId('language_id')->constrained('site_languages')->cascadeOnDelete();
            $table->longText('value')->nullable();
            // auto = machine translated, reviewed = typed or corrected by a person.
            $table->enum('status', ['auto', 'reviewed'])->default('reviewed');
            $table->timestamps();
            $table->unique(['site_id', 'section', 'page_slug', 'record_id', 'field_key', 'language_id'], 'site_translations_slot_unique');
            $table->index(['site_id', 'language_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_translations');
        Schema::dropIfExists('site_translation_keys');
        Schema::dropIfExists('site_languages');
    }
};
