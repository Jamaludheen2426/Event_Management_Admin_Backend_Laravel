<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Password reset codes of the vendor, staff and client logins, stored hashed
| (v1 kept them in plain text on each account row). `scope` keeps the portals
| apart, so one email can reset in one portal without touching another.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reset_codes', function (Blueprint $table): void {
            $table->string('scope', 30);
            $table->string('email');
            $table->string('code_hash');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('created_at')->nullable();
            $table->primary(['scope', 'email']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reset_codes');
    }
};
