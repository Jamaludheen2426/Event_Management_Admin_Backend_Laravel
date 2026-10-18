<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Vendor business profile. Was `vendors` (48 columns) which mixed the business,
| the owner's login, bank details and website footer text. Changes:
|  - the owner's login and person (email, password, name, contact, profile
|    photo, address, reset token) -> vendor_users (Phase 4)
|  - membership (a plan type NAME) -> plan_type_id (added in Phase 4, after
|    plan_types exists); plan_changed_at dropped (changing the plan now
|    signs the owner out by revoking tokens)
|  - acc_no -> account_number, encrypted at rest
|  - locations named honestly: district_id -> districts, city_id -> cities
|    (the old city_id pointed at districts and pincode_id at cities)
|  - company_id NOT NULL (9 of 12 production vendors had NULL; import sets 1)
| Location foreign keys are added in Phase 2 with the location tables.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vendors', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->string('company_name');
            $table->string('logo', 500)->nullable();
            $table->text('short_description')->nullable();
            $table->text('about_us')->nullable();
            $table->text('company_information')->nullable();
            $table->string('email')->nullable();
            $table->string('phone', 50)->nullable();
            $table->string('website', 500)->nullable();
            $table->text('address')->nullable();
            $table->enum('contact_mode', ['default', 'alternative'])->default('default');
            $table->string('alt_email')->nullable();
            $table->string('alt_phone', 50)->nullable();
            $table->text('alt_address')->nullable();
            $table->unsignedBigInteger('country_id')->nullable();
            $table->unsignedBigInteger('state_id')->nullable();
            $table->unsignedBigInteger('district_id')->nullable();
            $table->unsignedBigInteger('city_id')->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            // Edited on the admin vendor form (Phase 1 wrongly listed them as unused).
            $table->string('reg_no', 100)->nullable();
            $table->string('gst_no', 100)->nullable();
            $table->string('landline', 50)->nullable();
            // Footer lines of the vendor's site; kept on the vendor (the vendor screens edit them here).
            $table->string('copywrite')->nullable();
            $table->string('poweredby')->nullable();
            $table->boolean('website_enabled')->default(false);
            $table->enum('status', ['active', 'inactive'])->default('active')->index();
            $table->string('bank_name')->nullable();
            $table->text('account_number')->nullable();
            $table->string('ifsc_code', 50)->nullable();
            $table->enum('account_type', ['savings', 'current', 'overdraft'])->nullable();
            $table->string('branch')->nullable();
            $table->string('bank_logo', 500)->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vendors');
    }
};
