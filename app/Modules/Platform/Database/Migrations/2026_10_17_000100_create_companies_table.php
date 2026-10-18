<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Was `companies` (18 columns). Dropped: `settings` JSON (company settings live
| in the settings table). created_by / updated_by stay plain ids: users belong
| to a company, so a foreign key here would be circular.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('companies', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 200);
            $table->string('slug', 200);
            $table->string('domain')->nullable();
            $table->string('logo', 500)->nullable();
            $table->string('favicon', 500)->nullable();
            $table->string('email')->nullable();
            $table->string('phone', 20)->nullable();
            $table->text('address')->nullable();
            $table->string('timezone', 100)->nullable();
            $table->unsignedInteger('max_users')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->aliveColumn();

            $table->unique(['slug', 'alive']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('companies');
    }
};
