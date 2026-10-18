<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Billing (vendor layer, children of clients — tenancy comes from the client).
|
| One subscription per client to a platform plan, the history of what happened
| to it, invoices raised per term, a ledger of money movements, how the client
| pays (never a card number: a provider token, or a manual UPI / bank / cash
| record), and "contact sales" enquiries. v1 client_* tables, renamed:
|
|   client_subscriptions        → client_subscriptions
|   client_subscription_events  → subscription_events
|   client_invoices (+ items)   → invoices, invoice_items
|   client_transactions         → transactions (also serves the admin /payments
|                                 screen; v1's separate `payments` table is dead)
|   client_payment_methods      → payment_methods
|   client_sales_enquiries      → sales_enquiries
|
| Money is decimal(12,2) + a currency code. Invoice numbers are INV-<year>-<6
| digits>, unique.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_subscriptions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('plan_id')->nullable()->constrained()->nullOnDelete();
            $table->enum('status', ['trialing', 'active', 'cancelled', 'expired'])->default('active');
            $table->enum('billing_cycle', ['monthly', 'quarterly', 'yearly', 'lifetime'])->default('monthly');
            $table->decimal('price', 12, 2)->default(0);
            $table->string('currency_code', 10)->default('INR');
            $table->decimal('tax_rate', 5, 2)->default(18);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('current_period_start')->nullable();
            $table->timestamp('current_period_end')->nullable();
            $table->timestamp('trial_ends_at')->nullable();
            $table->boolean('cancel_at_period_end')->default(false);
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancellation_reason', 150)->nullable();
            $table->string('cancellation_comments', 300)->nullable();
            $table->foreignId('pending_plan_id')->nullable()->constrained('plans')->nullOnDelete();
            $table->timestamp('pending_effective_at')->nullable();
            $table->timestamps();
            $table->index(['client_id', 'created_at']);
        });

        Schema::create('subscription_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('client_subscription_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->enum('type', ['created', 'change_scheduled', 'change_cancelled', 'change_applied', 'plan_changed', 'cancelled', 'resumed', 'renewed', 'expired']);
            $table->foreignId('from_plan_id')->nullable()->constrained('plans')->nullOnDelete();
            $table->foreignId('to_plan_id')->nullable()->constrained('plans')->nullOnDelete();
            $table->string('description', 300)->nullable();
            $table->decimal('amount', 12, 2)->nullable();
            $table->string('currency_code', 10)->nullable();
            $table->enum('actor', ['client', 'admin', 'system'])->default('client');
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();
            $table->index(['client_id', 'occurred_at']);
        });

        Schema::create('invoices', function (Blueprint $table): void {
            $table->id();
            $table->string('invoice_number', 40)->unique();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_subscription_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('plan_id')->nullable()->constrained()->nullOnDelete();
            $table->enum('status', ['draft', 'issued', 'paid', 'partially_paid', 'cancelled', 'refunded'])->default('issued');
            $table->date('issue_date');
            $table->date('due_date')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->date('period_start')->nullable();
            $table->date('period_end')->nullable();
            $table->string('currency_code', 10)->default('INR');
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('discount_amount', 12, 2)->default(0);
            $table->decimal('tax_rate', 5, 2)->default(18);
            $table->decimal('tax_amount', 12, 2)->default(0);
            $table->json('tax_breakdown')->nullable();
            $table->boolean('tax_inclusive')->default(false);
            $table->decimal('total', 12, 2)->default(0);
            $table->decimal('amount_paid', 12, 2)->default(0);
            $table->decimal('amount_due', 12, 2)->default(0);
            $table->string('billing_name', 150)->nullable();
            $table->string('billing_email', 190)->nullable();
            $table->text('billing_address')->nullable();
            $table->string('billing_gstin', 20)->nullable();
            $table->string('notes', 500)->nullable();
            $table->timestamps();
            // One invoice per subscription term.
            $table->unique(['client_subscription_id', 'period_start']);
            $table->index(['client_id', 'issue_date']);
        });

        Schema::create('invoice_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->enum('item_type', ['plan', 'addon', 'adjustment', 'discount'])->default('plan');
            $table->string('description', 300);
            $table->date('period_start')->nullable();
            $table->date('period_end')->nullable();
            $table->decimal('quantity', 10, 2)->default(1);
            $table->decimal('unit_price', 12, 2)->default(0);
            $table->decimal('amount', 12, 2)->default(0);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('payment_methods', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->string('gateway', 30);
            $table->string('gateway_customer_id', 120)->nullable();
            $table->string('gateway_payment_method_id', 120)->nullable();
            $table->string('method_type', 20)->default('card');
            $table->string('brand', 30)->nullable();
            $table->char('last4', 4)->nullable();
            $table->unsignedTinyInteger('exp_month')->nullable();
            $table->unsignedSmallInteger('exp_year')->nullable();
            $table->string('holder_name', 120)->nullable();
            $table->string('upi_id', 120)->nullable();
            $table->string('bank_name', 120)->nullable();
            $table->char('account_last4', 4)->nullable();
            $table->string('ifsc', 11)->nullable();
            $table->boolean('is_default')->default(false);
            $table->boolean('is_verified')->default(false);
            $table->enum('status', ['active', 'expired', 'removed'])->default('active');
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('transactions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_subscription_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('invoice_id')->nullable()->constrained()->nullOnDelete();
            $table->enum('type', ['invoice', 'payment', 'refund', 'adjustment', 'setup']);
            $table->enum('status', ['pending', 'successful', 'failed', 'completed', 'cancelled'])->default('completed');
            $table->string('description', 300)->nullable();
            $table->decimal('amount', 12, 2)->default(0);
            $table->string('currency_code', 10)->default('INR');
            $table->string('reference', 80)->nullable();
            $table->string('gateway', 50)->nullable();
            $table->string('gateway_transaction_id', 190)->nullable();
            $table->foreignId('payment_method_id')->nullable()->constrained()->nullOnDelete();
            // The method as it was when it paid (it may be removed later).
            $table->string('method_brand', 30)->nullable();
            $table->char('method_last4', 4)->nullable();
            $table->string('method_label', 120)->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('occurred_at');
            $table->timestamps();
            $table->index(['client_id', 'occurred_at']);
            $table->index(['type', 'status']);
        });

        Schema::create('sales_enquiries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete();
            $table->string('full_name', 150);
            $table->string('work_email', 190);
            $table->string('company_name', 190)->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('events_per_year', 50)->nullable();
            $table->json('interests')->nullable();
            $table->text('message');
            $table->string('preferred_time', 50)->nullable();
            $table->enum('status', ['new', 'contacted', 'closed'])->default('new');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_enquiries');
        Schema::dropIfExists('transactions');
        Schema::dropIfExists('payment_methods');
        Schema::dropIfExists('invoice_items');
        Schema::dropIfExists('invoices');
        Schema::dropIfExists('subscription_events');
        Schema::dropIfExists('client_subscriptions');
    }
};
