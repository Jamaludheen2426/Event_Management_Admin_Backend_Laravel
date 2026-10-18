<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Invitation design catalogue (company layer): template styles, frame artwork,
| decorations, fonts and the event templates built from them.
|
| Changes from v1:
|  - event_templates.decoration_ids (JSON) -> event_template_decoration
|    (ordered pivot), event_templates.plan_ids (JSON) -> event_template_plan;
|  - template codes and category slugs are unique per company among live rows
|    (v1 enforced this in code only);
|  - components / component_order / permissions / tags / available_for stay
|    JSON: they are one design document, not relations.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('template_categories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name', 150);
            $table->string('slug', 150);
            $this->listColumns($table);
            $table->unique(['company_id', 'slug', 'alive']);
        });

        Schema::create('frame_styles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name', 150);
            $table->foreignId('template_category_id')->nullable()->constrained()->nullOnDelete();
            $table->string('file_url', 500)->nullable();
            $table->string('file_name')->nullable();
            $table->json('supported_layouts')->nullable();
            $table->enum('status', ['draft', 'published'])->default('published');
            $this->listColumns($table);
        });

        Schema::create('decorations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name', 150);
            $table->enum('type', ['corner', 'divider', 'ornament', 'top', 'bottom', 'motif'])->default('corner');
            $table->string('file_url', 500)->nullable();
            $table->string('file_name')->nullable();
            $table->string('file_format', 10)->nullable();
            $table->unsignedInteger('file_size')->nullable();
            $this->listColumns($table);
        });

        Schema::create('template_fonts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100)->comment('the CSS family name templates store');
            $table->enum('source', ['upload', 'link'])->default('upload');
            $table->string('file_url', 500)->nullable();
            $table->string('file_name')->nullable();
            $table->string('file_format', 10)->nullable();
            $table->unsignedInteger('file_size')->nullable();
            $table->string('link_url', 1000)->nullable();
            $table->unsignedTinyInteger('is_active')->default(1);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->aliveColumn();
            $table->unique(['company_id', 'name', 'alive']);
        });

        Schema::create('event_templates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name', 200);
            $table->string('code', 120)->comment('slug, e.g. fwe-001; events point at it');
            $table->foreignId('event_category_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('template_category_id')->nullable()->constrained()->nullOnDelete();
            $table->string('style', 40)->default('classic');
            $table->json('tags')->nullable();
            $table->string('description', 500)->nullable();
            $table->string('layout_style', 40)->default('classic');
            $table->enum('background_type', ['color', 'image', 'gradient', 'custom'])->default('color');
            $table->string('background_color', 9)->nullable();
            $table->string('secondary_color', 9)->nullable();
            $table->string('background_image', 500)->nullable();
            $table->string('gradient_from', 9)->nullable();
            $table->string('gradient_to', 9)->nullable();
            $table->string('gradient_via', 9)->nullable();
            $table->string('image_position', 20)->default('center');
            $table->string('image_scale', 20)->default('cover');
            $table->string('background_position', 20)->default('center');
            $table->integer('image_size')->default(100);
            $table->unsignedTinyInteger('overlay_enabled')->default(0);
            $table->string('overlay_color', 9)->nullable();
            $table->string('artwork_style', 20)->nullable();
            $table->enum('gradient_type', ['linear', 'radial'])->default('linear');
            $table->string('gradient_direction', 20)->default('bottom');
            $table->enum('image_shape', ['rectangle', 'square', 'circle', 'heart', 'arch'])->default('rectangle');
            $table->integer('corner_radius')->default(0);
            $table->unsignedTinyInteger('overlay_opacity')->default(0);
            $table->enum('orientation', ['portrait', 'landscape'])->default('portrait');
            $table->string('dimension', 60)->nullable();
            $table->string('primary_font', 80)->nullable();
            $table->string('secondary_font', 80)->nullable();
            $table->unsignedSmallInteger('primary_font_size')->default(100);
            $table->unsignedSmallInteger('secondary_font_size')->default(100);
            $table->string('border_style', 40)->nullable();
            $table->foreignId('frame_style_id')->nullable()->constrained()->nullOnDelete();
            $table->string('frame_color', 9)->nullable();
            $table->string('decoration_color', 9)->nullable();
            $table->json('components')->nullable();
            $table->json('component_order')->nullable();
            $table->json('permissions')->nullable();
            $table->enum('status', ['draft', 'published'])->default('draft');
            $table->unsignedTinyInteger('is_featured')->default(0);
            $table->json('available_for')->nullable();
            $table->enum('plan_availability', ['all', 'selected', 'trial'])->default('all');
            $table->unsignedTinyInteger('show_on_homepage')->default(0);
            $table->string('thumbnail', 500)->nullable();
            $this->listColumns($table);
            $table->unique(['company_id', 'code', 'alive']);
        });

        Schema::create('event_template_decoration', function (Blueprint $table): void {
            $table->foreignId('event_template_id')->constrained()->cascadeOnDelete();
            $table->foreignId('decoration_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('position')->default(0);
            $table->primary(['event_template_id', 'decoration_id']);
        });

        Schema::create('event_template_plan', function (Blueprint $table): void {
            $table->foreignId('event_template_id')->constrained()->cascadeOnDelete();
            $table->foreignId('plan_id')->constrained()->cascadeOnDelete();
            $table->primary(['event_template_id', 'plan_id']);
        });
    }

    public function down(): void
    {
        foreach (['event_template_plan', 'event_template_decoration', 'event_templates', 'template_fonts',
            'decorations', 'frame_styles', 'template_categories'] as $table) {
            Schema::dropIfExists($table);
        }
    }

    private function listColumns(Blueprint $table): void
    {
        $table->integer('sort_order')->default(0);
        $table->unsignedTinyInteger('is_active')->default(1);
        $table->enum('approval_status', ['approved', 'pending'])->default('approved');
        $table->unsignedBigInteger('created_by')->nullable();
        $table->unsignedBigInteger('updated_by')->nullable();
        $table->timestamps();
        $table->softDeletes();
        $table->aliveColumn();
    }
};
