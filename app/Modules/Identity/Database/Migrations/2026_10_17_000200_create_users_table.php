<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Admin users. Was `users` (33 columns). Changes:
|  - dropped: `department` text (department_id is the link; the API still
|    answers `department` with the department's name), `pincode_id`, `password_reset_token/expires` (reset codes
|    live hashed in password_reset_tokens), `role_id` (spatie roles).
|  - `city_id` used to point at districts; locations are now named honestly:
|    district_id -> districts, city_id -> cities.
|  - `is_active` is on/off only; "pending approval" is approval_status.
|  - email is unique among LIVE rows, so a deleted user no longer blocks the
|    address (the Node API rewrote the email on delete instead).
| Location and department foreign keys are added in Phase 2 with those tables.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->unsignedBigInteger('department_id')->nullable()->index();
            $table->string('full_name', 200);
            $table->string('email');
            $table->string('username', 100)->nullable();
            $table->string('phone', 20)->nullable();
            $table->string('avatar', 500)->nullable();
            $table->string('password')->nullable();
            $table->date('dob')->nullable();
            $table->enum('gender', ['male', 'female', 'other'])->nullable();
            // Edited by the admin employee form (kept although no backend code reads it).
            $table->enum('marital_status', ['married', 'unmarried'])->nullable();
            $table->string('designation', 200)->nullable();
            $table->date('doj')->nullable();
            $table->date('dor')->nullable();
            $table->text('address')->nullable();
            $table->unsignedBigInteger('country_id')->nullable();
            $table->unsignedBigInteger('state_id')->nullable();
            $table->unsignedBigInteger('district_id')->nullable();
            $table->unsignedBigInteger('city_id')->nullable();
            $table->string('timezone', 100)->nullable();
            $table->boolean('login_access')->default(true);
            $table->boolean('is_active')->default(false);
            $table->enum('approval_status', ['approved', 'pending'])->default('approved');
            $table->timestamp('email_verified_at')->nullable();
            $table->timestamp('last_login_at')->nullable();
            $table->timestamp('password_changed_at')->nullable();
            $table->rememberToken();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->aliveColumn();

            $table->unique(['email', 'alive']);
        });

        // Reset codes are stored hashed here, never on the user row.
        Schema::create('password_reset_tokens', function (Blueprint $table): void {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('users');
    }
};
