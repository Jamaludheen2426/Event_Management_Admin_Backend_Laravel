<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| The platform catalogue clients buy and events are built from (company layer).
|
| Column names are v1's so the admin screens read them unchanged. Changes:
|  - company_id is NOT NULL with a foreign key (v1: nullable, unenforced);
|  - "pending approval" is approval_status (v1: is_active = 2);
|  - subscription_plans -> plans, subscription_plan_menus -> plan_menu;
|  - plan_types.menu_ids (JSON) -> plan_type_menu (pivot);
|  - guest_relationship_options + guest_food_preference_options -> guest_options
|    with a `kind` column (two identical tables);
|  - names and codes are unique per company among LIVE rows (alive column),
|    not by renaming deleted rows.
| Flags stay tinyint (0/1): the screens compare them as numbers.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_categories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->text('description')->nullable();
            $table->string('icon', 100)->nullable()->default('');
            $table->string('color', 20)->nullable();
            $this->listColumns($table);
            $table->unique(['company_id', 'name', 'alive']);
        });

        Schema::create('event_menus', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name', 150);
            $table->string('slug', 180);
            $table->string('remarks', 300)->nullable();
            $table->text('description')->nullable();
            $table->enum('menu_group', ['core', 'addon'])->default('core');
            $table->foreignId('event_category_id')->nullable()->constrained()->nullOnDelete();
            // 1 = granted by every plan and locked on ("Default"); 0 = add-on.
            $table->unsignedTinyInteger('is_default')->default(0);
            $table->string('icon', 100)->nullable()->default('');
            $table->string('color', 20)->nullable();
            $this->listColumns($table);
            $table->unique(['company_id', 'slug', 'alive']);
        });

        Schema::create('plan_badges', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('text', 25);
            $table->enum('style', ['default', 'rounded', 'pill', 'outline', 'soft', 'dashed'])->default('default');
            $table->string('color', 20)->default('#6E22FE');
            $table->enum('apply_to', ['all', 'selected'])->default('all');
            $this->listColumns($table);
        });

        // Vendor subscription tiers (the vendor's own plan with the platform).
        Schema::create('plan_types', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name', 200);
            $table->text('description')->nullable();
            $table->decimal('price', 10, 2)->default(0);
            $table->decimal('discounted_price', 10, 2)->nullable();
            $table->integer('validity')->nullable()->comment('days; NULL = no expiry');
            $table->longText('features')->nullable()->comment('rich HTML');
            $table->unsignedTinyInteger('is_custom')->default(0)->comment('1 = made for one vendor');
            $table->foreignId('vendor_id')->nullable()->constrained()->nullOnDelete();
            $table->string('label_color', 20)->nullable();
            $this->listColumns($table);
        });

        // The vendor-portal menus a vendor plan type unlocks (v1 table `menus`).
        Schema::create('menus', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('icon', 100)->nullable()->default('');
            $table->string('icon_fill_color_light', 20)->nullable();
            $table->string('icon_fill_color_dark', 20)->nullable();
            $table->unsignedTinyInteger('display_status')->default(1);
            $this->listColumns($table);
        });

        Schema::create('plan_type_menu', function (Blueprint $table): void {
            $table->foreignId('plan_type_id')->constrained()->cascadeOnDelete();
            $table->foreignId('menu_id')->constrained()->cascadeOnDelete();
            $table->integer('sort_order')->default(0);
            $table->primary(['plan_type_id', 'menu_id']);
        });

        // What a client buys (v1 subscription_plans).
        Schema::create('plans', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name', 150);
            $table->string('plan_code', 50);
            $table->foreignId('plan_type_id')->nullable()->constrained()->nullOnDelete();
            $table->enum('billing_cycle', ['monthly', 'quarterly', 'yearly', 'lifetime'])->default('monthly');
            $table->string('short_description', 200)->nullable();
            $table->foreignId('event_category_id')->nullable()->constrained()->nullOnDelete();
            // Limits: NULL = unlimited.
            $table->unsignedInteger('max_events')->nullable();
            $table->unsignedInteger('max_guests_per_event')->nullable();
            $table->unsignedInteger('max_rsvp_per_event')->nullable();
            $table->unsignedInteger('max_photos')->nullable();
            $table->unsignedInteger('max_videos')->nullable();
            $table->unsignedInteger('storage_limit')->nullable();
            $table->enum('storage_unit', ['MB', 'GB'])->nullable();
            $table->string('currency_code', 10)->default('INR');
            $table->decimal('price', 10, 2)->default(0);
            $table->integer('trial_days')->default(0);
            $table->unsignedTinyInteger('is_visible')->default(1);
            $table->foreignId('plan_badge_id')->nullable()->constrained()->nullOnDelete();
            $table->string('deactivation_reason', 100)->nullable();
            $table->string('deactivation_comments', 300)->nullable();
            $table->timestamp('deactivated_at')->nullable();
            $table->unsignedBigInteger('deactivated_by')->nullable();
            $table->string('deletion_reason', 100)->nullable();
            $table->string('deletion_comments', 300)->nullable();
            $table->unsignedBigInteger('deleted_by')->nullable();
            $this->listColumns($table);
            $table->unique(['company_id', 'plan_code', 'alive']);
        });

        // Which event menus a plan grants, in display order (v1 subscription_plan_menus).
        Schema::create('plan_menu', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('plan_id')->constrained()->cascadeOnDelete();
            $table->foreignId('event_menu_id')->constrained()->cascadeOnDelete();
            $table->integer('sort_order')->default(0);
            $table->timestamps();
            $table->unique(['plan_id', 'event_menu_id']);
        });

        // Badge shown on selected plans only (apply_to = selected).
        Schema::create('plan_badge_plan', function (Blueprint $table): void {
            $table->foreignId('plan_badge_id')->constrained()->cascadeOnDelete();
            $table->foreignId('plan_id')->constrained()->cascadeOnDelete();
            $table->primary(['plan_badge_id', 'plan_id']);
        });

        Schema::create('notification_categories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->text('description')->nullable();
            $table->string('icon', 100)->nullable()->default('');
            $table->string('color', 20)->nullable();
            $this->listColumns($table);
            $table->unique(['company_id', 'name', 'alive']);
        });

        Schema::create('notification_templates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name', 150);
            // The one template a system event sends (e.g. welcome_invitation).
            $table->string('trigger_key', 50)->nullable();
            $table->foreignId('notification_category_id')->constrained()->restrictOnDelete();
            $table->foreignId('event_category_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title', 100);
            $table->text('content');
            $table->json('variables_used')->nullable();
            $table->string('image_url', 500)->nullable();
            $table->json('channels');
            $this->listColumns($table);
            $table->unique(['company_id', 'trigger_key', 'alive']);
        });

        // Guest registration dropdowns: "Relationship with invitor" and "Food preference".
        // event_category_id NULL = the fallback list used when a category has none.
        Schema::create('guest_options', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->enum('kind', ['relationship', 'food_preference']);
            $table->foreignId('event_category_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name', 100);
            $table->text('description')->nullable();
            $table->string('icon', 100)->nullable()->default('');
            $table->string('color', 20)->nullable();
            $this->listColumns($table);
            $table->index(['company_id', 'kind', 'event_category_id']);
        });
    }

    public function down(): void
    {
        foreach (['guest_options', 'notification_templates', 'notification_categories', 'plan_badge_plan', 'plan_menu',
            'plans', 'plan_type_menu', 'menus', 'plan_types', 'plan_badges', 'event_menus', 'event_categories'] as $table) {
            Schema::dropIfExists($table);
        }
    }

    /** sort_order, is_active, approval, audit, timestamps, soft delete. */
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
