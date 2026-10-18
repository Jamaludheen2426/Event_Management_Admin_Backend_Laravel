<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Clients a vendor adds may share a mobile number (v1 vendor_clients allowed
| it, and production has such clients). Sign-up, the client's own profile and
| admin-made clients still refuse a number already in use — that check lives
| in the code, so the database keeps only a lookup index.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table): void {
            $table->index(['vendor_id', 'mobile']);
            $table->dropUnique(['vendor_id', 'mobile', 'alive']);
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table): void {
            $table->unique(['vendor_id', 'mobile', 'alive']);
            $table->dropIndex(['vendor_id', 'mobile']);
        });
    }
};
