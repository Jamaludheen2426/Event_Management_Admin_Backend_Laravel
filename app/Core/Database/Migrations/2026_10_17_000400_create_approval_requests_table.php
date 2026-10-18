<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Changes waiting for a reviewer. Was approval_requests with a tri-state
| is_active (2 pending / 1 approved / 0 rejected); now an explicit status.
| Pending creates point at a row inserted with approval_status = pending.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('approval_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('requester_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('approver_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('module_slug', 100);
            $table->string('permission_slug', 100);
            $table->string('action', 50);
            $table->string('resource_type', 50);
            $table->unsignedBigInteger('resource_id')->nullable();
            $table->json('request_data')->nullable();
            $table->json('old_data')->nullable();
            $table->enum('status', ['pending', 'approved', 'rejected'])->default('pending');
            $table->text('review_notes')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'status']);
            $table->index(['module_slug', 'resource_type', 'resource_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('approval_requests');
    }
};
