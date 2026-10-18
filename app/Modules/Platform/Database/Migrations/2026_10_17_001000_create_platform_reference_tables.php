<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Global reference data (no tenant column): languages, currencies and the
| location tree. Names are now honest:
|   countries -> states -> districts -> cities
| (v1 called districts "cities" in places and stored the district id in
|  cities.city_id; the API still answers with the v1 key names.)
|
| Deleted rows no longer have their code/slug rewritten: unique indexes include
| the `alive` column instead.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('languages', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 100);
            $table->string('code', 10);
            $table->string('native_name', 100)->nullable();
            $table->enum('direction', ['ltr', 'rtl'])->default('ltr');
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->enum('approval_status', ['approved', 'pending'])->default('approved');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->aliveColumn();
            $table->unique(['code', 'alive']);
        });

        Schema::create('currencies', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 100);
            $table->string('code', 3);
            $table->string('symbol', 10)->nullable();
            $table->decimal('exchange_rate', 10, 4)->default(1);
            $table->unsignedTinyInteger('decimal_places')->default(2);
            $table->string('decimal_separator', 5)->default('.');
            $table->string('thousand_separator', 5)->default(',');
            $table->enum('symbol_position', ['before', 'after'])->default('before');
            $table->boolean('space_between')->default(false);
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->enum('approval_status', ['approved', 'pending'])->default('approved');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->aliveColumn();
            $table->unique(['code', 'alive']);
        });

        Schema::create('countries', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 100);
            $table->string('code', 3);
            $table->string('flag', 500)->nullable();
            $table->string('nationality', 100)->nullable();
            $table->integer('sort_order')->default(0);
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->enum('approval_status', ['approved', 'pending'])->default('approved');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->aliveColumn();
            $table->unique(['code', 'alive']);
        });

        Schema::create('states', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('country_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->string('code', 10)->nullable();
            $table->string('slug', 100)->nullable();
            $table->integer('sort_order')->default(0);
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->enum('approval_status', ['approved', 'pending'])->default('approved');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['country_id', 'name']);
        });

        Schema::create('districts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('state_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->string('slug', 100)->nullable();
            $table->integer('sort_order')->default(0);
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->enum('approval_status', ['approved', 'pending'])->default('approved');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['state_id', 'name']);
        });

        Schema::create('cities', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('district_id')->constrained()->cascadeOnDelete();
            $table->string('name', 200);
            $table->string('pincode', 20)->nullable();
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->enum('approval_status', ['approved', 'pending'])->default('approved');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['district_id', 'name']);
            $table->index('pincode');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cities');
        Schema::dropIfExists('districts');
        Schema::dropIfExists('states');
        Schema::dropIfExists('countries');
        Schema::dropIfExists('currencies');
        Schema::dropIfExists('languages');
    }
};
