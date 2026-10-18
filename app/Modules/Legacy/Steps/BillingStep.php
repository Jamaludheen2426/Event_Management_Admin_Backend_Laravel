<?php

declare(strict_types=1);

namespace App\Modules\Legacy\Steps;

use App\Modules\Legacy\Support\Import;
use Illuminate\Support\Facades\DB;

/**
 * Billing (v1 client_* tables): subscriptions and their history, invoices
 * and items, payment methods, the ledger, sales enquiries. Ids kept; the
 * client is the website client of the same id. v1's empty `payments` table
 * is dropped (Phase 5).
 */
final class BillingStep implements Step
{
    public function name(): string
    {
        return 'billing';
    }

    public function run(Import $import): void
    {
        $i = $import;
        $plans = DB::table('plans')->pluck('id')->flip()->all();
        $plan = static fn (mixed $id): ?int => is_numeric($id) && isset($plans[(int) $id]) ? (int) $id : null;
        $client = static fn (mixed $id): ?int => $i->id('website_client', $id);

        $rows = $i->rows('client_subscriptions', withDeleted: false);
        $subscriptions = [];
        $i->put('client_subscriptions', 'client_subscriptions', array_values(array_filter(array_map(static function (array $r) use ($client, $plan, &$subscriptions): ?array {
            if (($id = $client($r['website_client_id'])) === null) {
                return null;
            }
            $subscriptions[(int) $r['id']] = true;

            return [
                'id' => $r['id'], 'client_id' => $id, 'plan_id' => $plan($r['subscription_plan_id']), 'status' => $r['status'], 'billing_cycle' => $r['billing_cycle'],
                'price' => $r['price'], 'currency_code' => $r['currency_code'], 'tax_rate' => $r['tax_rate'], 'started_at' => Import::at($r['started_at']),
                'current_period_start' => Import::at($r['current_period_start']), 'current_period_end' => Import::at($r['current_period_end']),
                'trial_ends_at' => Import::at($r['trial_ends_at']), 'cancel_at_period_end' => Import::bit($r['cancel_at_period_end'], 0),
                'cancelled_at' => Import::at($r['cancelled_at']), 'cancellation_reason' => $r['cancellation_reason'], 'cancellation_comments' => $r['cancellation_comments'],
                'pending_plan_id' => $plan($r['pending_plan_id']), 'pending_effective_at' => Import::at($r['pending_effective_at']),
                'created_at' => Import::at($r['created_at']), 'updated_at' => Import::at($r['updated_at']),
            ];
        }, $rows))), count($rows));

        $rows = $i->rows('client_subscription_events');
        $i->put('client_subscription_events', 'subscription_events', array_values(array_filter(array_map(static fn (array $r): ?array => ! isset($subscriptions[(int) $r['client_subscription_id']]) || ($id = $client($r['website_client_id'])) === null ? null : [
            'id' => $r['id'], 'client_subscription_id' => $r['client_subscription_id'], 'client_id' => $id, 'type' => $r['type'],
            'from_plan_id' => $plan($r['from_plan_id']), 'to_plan_id' => $plan($r['to_plan_id']), 'description' => $r['description'], 'amount' => $r['amount'],
            'currency_code' => $r['currency_code'], 'actor' => $r['actor'], 'actor_id' => $r['actor_id'], 'occurred_at' => Import::at($r['occurred_at']),
            'created_at' => Import::at($r['created_at']), 'updated_at' => Import::at($r['updated_at']),
        ], $rows))), count($rows));

        $rows = $i->rows('client_invoices', withDeleted: false);
        $invoices = [];
        $copy = ['invoice_number', 'status', 'currency_code', 'subtotal', 'discount_amount', 'tax_rate', 'tax_amount', 'total', 'amount_paid', 'amount_due',
            'billing_name', 'billing_email', 'billing_address', 'billing_gstin', 'notes'];
        $i->put('client_invoices', 'invoices', array_values(array_filter(array_map(static function (array $r) use ($client, $plan, $subscriptions, $copy, &$invoices): ?array {
            if (($id = $client($r['website_client_id'])) === null) {
                return null;
            }
            $invoices[(int) $r['id']] = true;

            return ['id' => $r['id'], 'client_id' => $id, ...array_intersect_key($r, array_flip($copy)),
                'client_subscription_id' => isset($subscriptions[(int) $r['client_subscription_id']]) ? $r['client_subscription_id'] : null,
                'plan_id' => $plan($r['subscription_plan_id']), 'issue_date' => $r['issue_date'], 'due_date' => Import::at($r['due_date']),
                'paid_at' => Import::at($r['paid_at']), 'period_start' => Import::at($r['period_start']), 'period_end' => Import::at($r['period_end']),
                'tax_breakdown' => Import::json($r['tax_breakdown']), 'tax_inclusive' => Import::bit($r['tax_inclusive'], 0),
                'created_at' => Import::at($r['created_at']), 'updated_at' => Import::at($r['updated_at'])];
        }, $rows))), count($rows));

        $rows = $i->rows('client_invoice_items');
        $i->put('client_invoice_items', 'invoice_items', array_values(array_map(static fn (array $r): array => [
            'id' => $r['id'], 'invoice_id' => $r['invoice_id'], 'item_type' => $r['item_type'], 'description' => $r['description'],
            'period_start' => Import::at($r['period_start']), 'period_end' => Import::at($r['period_end']), 'quantity' => $r['quantity'],
            'unit_price' => $r['unit_price'], 'amount' => $r['amount'], 'sort_order' => max(0, (int) $r['sort_order']),
            'created_at' => Import::at($r['created_at']), 'updated_at' => Import::at($r['updated_at']),
        ], array_filter($rows, static fn (array $r): bool => isset($invoices[(int) $r['invoice_id']])))), count($rows));

        $rows = $i->rows('client_payment_methods');
        $methods = [];
        $copy = ['gateway', 'gateway_customer_id', 'gateway_payment_method_id', 'method_type', 'brand', 'last4', 'exp_month', 'exp_year', 'holder_name',
            'upi_id', 'bank_name', 'account_last4', 'ifsc', 'status'];
        $i->put('client_payment_methods', 'payment_methods', array_values(array_filter(array_map(static function (array $r) use ($client, $copy, &$methods): ?array {
            if (($id = $client($r['website_client_id'])) === null) {
                return null;
            }
            $methods[(int) $r['id']] = true;

            return ['id' => $r['id'], 'client_id' => $id, ...array_intersect_key($r, array_flip($copy)), 'is_default' => Import::bit($r['is_default'], 0),
                'is_verified' => Import::bit($r['is_verified'], 0), 'created_at' => Import::at($r['created_at']), 'updated_at' => Import::at($r['updated_at']),
                'deleted_at' => Import::at($r['deleted_at'])];
        }, $rows))), count($rows));

        $rows = $i->rows('client_transactions');
        $copy = ['type', 'status', 'description', 'amount', 'currency_code', 'reference', 'gateway', 'gateway_transaction_id', 'method_brand', 'method_last4', 'method_label'];
        $i->put('client_transactions', 'transactions', array_values(array_filter(array_map(static fn (array $r): ?array => ($id = $client($r['website_client_id'])) === null ? null : [
            'id' => $r['id'], 'client_id' => $id, ...array_intersect_key($r, array_flip($copy)),
            'client_subscription_id' => isset($subscriptions[(int) $r['client_subscription_id']]) ? $r['client_subscription_id'] : null,
            'invoice_id' => isset($invoices[(int) $r['invoice_id']]) ? $r['invoice_id'] : null,
            'payment_method_id' => isset($methods[(int) $r['client_payment_method_id']]) ? $r['client_payment_method_id'] : null,
            'recorded_by' => null, 'occurred_at' => Import::at($r['occurred_at']), 'created_at' => Import::at($r['created_at']), 'updated_at' => Import::at($r['updated_at']),
        ], $rows))), count($rows));

        $rows = $i->rows('client_sales_enquiries', withDeleted: false);
        $copy = ['full_name', 'work_email', 'company_name', 'phone', 'events_per_year', 'message', 'preferred_time', 'status'];
        $i->put('client_sales_enquiries', 'sales_enquiries', array_map(static fn (array $r): array => [
            'id' => $r['id'], 'client_id' => $client($r['website_client_id']), ...array_intersect_key($r, array_flip($copy)),
            'interests' => Import::json($r['interests']), 'created_at' => Import::at($r['created_at']), 'updated_at' => Import::at($r['updated_at']),
        ], $rows), count($rows));
    }
}
