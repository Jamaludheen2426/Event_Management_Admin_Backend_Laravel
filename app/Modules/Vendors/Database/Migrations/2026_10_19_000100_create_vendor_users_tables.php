<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| People who sign in for a vendor (vendor layer).
|
| v1 kept the vendor OWNER's login on the vendors row and STAFF in
| vendor_staff — two login systems with stateless, unrevocable tokens. Both are
| vendor_users now (is_owner), signed in through one guard with revocable
| tokens. The owner passes every vendor permission; staff get a vendor role.
|
| Emails are unique among live vendor users (one login per address).
| Locations of staff stay free text, as v1 stored them.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vendor_departments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('vendor_id')->constrained()->cascadeOnDelete();
            $table->string('name', 200);
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
            $table->aliveColumn();
            $table->unique(['vendor_id', 'name', 'alive']);
        });

        Schema::create('vendor_users', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('vendor_id')->constrained()->cascadeOnDelete();
            $table->boolean('is_owner')->default(false);
            $table->foreignId('department_id')->nullable()->constrained('vendor_departments')->nullOnDelete();
            $table->string('emp_id', 50)->nullable();
            $table->string('name', 200);
            $table->string('email');
            $table->string('mobile', 20)->nullable();
            $table->string('password')->nullable();
            $table->string('profile_pic', 500)->nullable();
            $table->string('designation', 200)->nullable();
            $table->date('dob')->nullable();
            $table->date('doj')->nullable();
            $table->date('dor')->nullable();
            $table->text('address')->nullable();
            $table->string('country', 100)->nullable();
            $table->string('state', 100)->nullable();
            $table->string('district', 100)->nullable();
            $table->string('city', 100)->nullable();
            $table->string('locality', 100)->nullable();
            $table->string('pincode', 20)->nullable();
            $table->boolean('login_access')->default(true);
            $table->boolean('is_active')->default(true);
            $table->enum('work_status', ['active', 'inactive', 'resigned', 'relieved'])->default('active');
            $table->timestamp('last_login_at')->nullable();
            $table->timestamp('password_changed_at')->nullable();
            $table->rememberToken();
            $table->timestamps();
            $table->softDeletes();
            $table->aliveColumn();
            $table->unique(['email', 'alive']);
            $table->index(['vendor_id', 'is_owner']);
        });

        Schema::table('vendors', function (Blueprint $table): void {
            $table->foreignId('plan_type_id')->nullable()->after('city_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('vendors', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('plan_type_id');
        });
        Schema::dropIfExists('vendor_users');
        Schema::dropIfExists('vendor_departments');
    }
};
