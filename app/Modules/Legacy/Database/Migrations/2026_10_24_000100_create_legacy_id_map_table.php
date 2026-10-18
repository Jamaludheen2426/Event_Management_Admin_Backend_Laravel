<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| legacy:import's record of which old row became which new row, for the
| entities whose ids change (merged tables: clients, vendor users, sites,
| guest options, broadcasts...). Kept after the import so support questions
| ("what happened to vendor client 12?") can be answered.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('legacy_id_map', function (Blueprint $table): void {
            $table->id();
            $table->string('entity', 80);
            $table->unsignedBigInteger('old_id');
            $table->unsignedBigInteger('new_id');
            $table->timestamp('created_at')->nullable();
            $table->unique(['entity', 'old_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('legacy_id_map');
    }
};
