<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Admin-side departments (company layer) and the foreign keys that had to wait
| for the location and department tables.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('departments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name', 200);
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
            $table->aliveColumn();
            $table->unique(['company_id', 'name', 'alive']);
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->foreign('department_id')->references('id')->on('departments')->nullOnDelete();
            $table->foreign('country_id')->references('id')->on('countries')->nullOnDelete();
            $table->foreign('state_id')->references('id')->on('states')->nullOnDelete();
            $table->foreign('district_id')->references('id')->on('districts')->nullOnDelete();
            $table->foreign('city_id')->references('id')->on('cities')->nullOnDelete();
        });

        Schema::table('vendors', function (Blueprint $table): void {
            $table->foreign('country_id')->references('id')->on('countries')->nullOnDelete();
            $table->foreign('state_id')->references('id')->on('states')->nullOnDelete();
            $table->foreign('district_id')->references('id')->on('districts')->nullOnDelete();
            $table->foreign('city_id')->references('id')->on('cities')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('vendors', function (Blueprint $table): void {
            $table->dropForeign(['country_id']);
            $table->dropForeign(['state_id']);
            $table->dropForeign(['district_id']);
            $table->dropForeign(['city_id']);
        });
        Schema::table('users', function (Blueprint $table): void {
            $table->dropForeign(['department_id']);
            $table->dropForeign(['country_id']);
            $table->dropForeign(['state_id']);
            $table->dropForeign(['district_id']);
            $table->dropForeign(['city_id']);
        });
        Schema::dropIfExists('departments');
    }
};
