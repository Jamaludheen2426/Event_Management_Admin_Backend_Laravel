<?php

declare(strict_types=1);

namespace App\Modules\Billing\Actions;

use App\Core\Exceptions\ApiException;
use App\Core\Support\Dates;
use App\Modules\Billing\Models\ClientSubscription;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\PaymentMethod;
use App\Modules\Billing\Models\SubscriptionEvent;
use App\Modules\Billing\Models\Transaction;
use App\Modules\Billing\Support\Money;
use App\Modules\Clients\Models\Client;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Invoices, the money ledger and the billing history (v1 clientInvoice.service).
 *
 * One invoice per subscription term, raised when the term starts. Until a
 * payment provider is connected an invoice has no due date and says how to pay;
 * an admin records payments that arrive another way (admin /payments).
 */
final class Invoices
{
    public const PAYMENTS_DISABLED_REASON = 'Online payment is not enabled yet. Please contact us to settle an invoice.';

    private const STORED_STATUSES = ['draft', 'issued', 'paid', 'partially_paid', 'cancelled', 'refunded'];

    /** The invoice for one term — raised once; asking again returns the same one. */
    public function raiseForTerm(ClientSubscription $sub, CarbonInterface $periodStart, ?CarbonInterface $periodEnd, ?string $planName = null): Invoice
    {
        $existing = Invoice::query()->where('client_subscription_id', $sub->id)->whereDate('period_start', $periodStart)->first();
        if ($existing !== null) {
            return $existing;
        }

        /** @var Client $client */
        $client = $sub->client;
        $label = $planName ?? $sub->plan->name ?? 'Subscription';
        $price = (float) $sub->price;
        $totals = Money::invoiceTotals($price, 0, $sub->tax_rate);

        return DB::transaction(function () use ($sub, $client, $label, $price, $totals, $periodStart, $periodEnd): Invoice {
            $number = $this->nextNumber((int) $periodStart->format('Y'));
            $invoice = Invoice::query()->create([
                'invoice_number' => $number,
                'client_id' => $client->id,
                'client_subscription_id' => $sub->id,
                'plan_id' => $sub->plan_id,
                'status' => 'issued',
                'issue_date' => $periodStart,
                'due_date' => null,
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
                'currency_code' => $sub->currency_code,
                ...$totals,
                'amount_paid' => 0,
                'amount_due' => $totals['total'],
                'billing_name' => $client->name,
                'billing_email' => $client->email,
                'notes' => self::PAYMENTS_DISABLED_REASON,
            ]);
            $invoice->items()->create([
                'item_type' => 'plan',
                'description' => "{$label} subscription",
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
                'quantity' => 1,
                'unit_price' => $price,
                'amount' => $price,
                'sort_order' => 0,
            ]);
            Transaction::query()->create([
                'client_id' => $client->id,
                'client_subscription_id' => $sub->id,
                'invoice_id' => $invoice->id,
                'type' => 'invoice',
                'status' => 'completed',
                'description' => "Invoice {$number} — {$label}",
                'amount' => $totals['total'],
                'currency_code' => $sub->currency_code,
                'reference' => $number,
                'occurred_at' => $periodStart,
            ]);

            return $invoice;
        });
    }

    /**
     * Money received against an invoice. The method is snapshotted onto the
     * payment (brand, last 4, label), so the receipt stays right after the
     * client removes the method.
     *
     * @param  array{amount: float, status?: string, reference?: string|null, gateway?: string|null, gateway_transaction_id?: string|null, payment_method_id?: int|null, description?: string|null, recorded_by?: int|null}  $payment
     */
    public function recordPayment(Invoice $invoice, array $payment): Transaction
    {
        $amount = Money::round($payment['amount']);
        if ($amount <= 0) {
            throw ApiException::badRequest('Enter the amount received.');
        }
        $method = null;
        if (! empty($payment['payment_method_id'])) {
            $method = PaymentMethod::query()->withTrashed()->where('client_id', $invoice->client_id)->find($payment['payment_method_id'])
                ?? throw ApiException::badRequest('That payment method is not on this account.');
        }
        $status = $payment['status'] ?? 'successful';

        return DB::transaction(function () use ($invoice, $payment, $amount, $method, $status): Transaction {
            if ($status === 'successful') {
                $this->applyPayment($invoice, $amount);
            }

            return Transaction::query()->create([
                'client_id' => $invoice->client_id,
                'client_subscription_id' => $invoice->client_subscription_id,
                'invoice_id' => $invoice->id,
                'type' => 'payment',
                'status' => $status,
                'description' => $payment['description'] ?? "Payment for {$invoice->invoice_number}",
                'amount' => -$amount,
                'currency_code' => $invoice->currency_code,
                'reference' => $payment['reference'] ?? $invoice->invoice_number,
                'gateway' => $payment['gateway'] ?? $method?->gateway,
                'gateway_transaction_id' => $payment['gateway_transaction_id'] ?? null,
                'payment_method_id' => $method?->id,
                'method_brand' => $method?->brand,
                'method_last4' => $method->last4 ?? $method?->account_last4,
                'method_label' => $method?->label(),
                'recorded_by' => $payment['recorded_by'] ?? null,
                'occurred_at' => now(),
            ]);
        });
    }

    /** A pending payment confirmed: it now counts against the invoice. */
    public function confirmPayment(Transaction $payment): void
    {
        DB::transaction(function () use ($payment): void {
            $payment->forceFill(['status' => 'successful'])->save();
            $invoice = $payment->invoice_id !== null ? Invoice::query()->lockForUpdate()->find($payment->invoice_id) : null;
            if ($invoice !== null) {
                $this->applyPayment($invoice, abs((float) $payment->amount));
            }
        });
    }

    /**
     * @param  array<string, mixed>  $filters  status, search, from, to, page, limit
     * @return array<string, mixed>
     */
    public function list(Client $client, array $filters): array
    {
        [$page, $limit] = $this->page($filters);
        $status = is_string($filters['status'] ?? null) ? $filters['status'] : null;

        $query = Invoice::query()->where('client_id', $client->id)->with('plan:id,name')
            ->when(is_string($filters['search'] ?? null) && trim($filters['search']) !== '', static fn ($q) => $q->where('invoice_number', 'like', '%'.trim((string) $filters['search']).'%'))
            ->when(is_string($filters['from'] ?? null) && $filters['from'] !== '', static fn ($q) => $q->whereDate('issue_date', '>=', $filters['from']))
            ->when(is_string($filters['to'] ?? null) && $filters['to'] !== '', static fn ($q) => $q->whereDate('issue_date', '<=', $filters['to']))
            ->when(in_array($status, self::STORED_STATUSES, true), static fn ($q) => $q->where('status', $status))
            ->orderByDesc('issue_date')->orderByDesc('id');

        $total = (clone $query)->count();
        $rows = $query->forPage($page, $limit)->get();
        $shaped = $rows->map(fn (Invoice $i): array => $this->present($i));
        if ($status !== null && $status !== '' && ! in_array($status, self::STORED_STATUSES, true)) {
            $shaped = $shaped->where('status', $status)->values();
        }

        // The Payment Method column: one query for the whole page.
        $payments = Transaction::query()->whereIn('invoice_id', $rows->pluck('id'))->where('type', 'payment')->where('status', '!=', 'failed')
            ->orderByDesc('occurred_at')->orderByDesc('id')->get()->unique('invoice_id')->keyBy('invoice_id');
        $shaped = $shaped->map(fn (array $i): array => [...$i, 'payment_method' => $this->presentMethod($payments->get($i['id']))]);

        $all = Invoice::query()->where('client_id', $client->id)->get(['id', 'status', 'total', 'amount_paid', 'amount_due', 'due_date']);
        $byStatus = $all->countBy(fn (Invoice $i): string => $this->displayStatus($i))->all();

        return [
            'invoices' => $shaped->values()->all(),
            'pagination' => ['page' => $page, 'limit' => $limit, 'totalItems' => $total, 'totalPages' => (int) ceil($total / $limit)],
            'stats' => [
                'total_invoices' => $all->count(),
                'total_amount' => Money::round($all->sum(static fn (Invoice $i): float => (float) $i->total)),
                'paid_amount' => Money::round($all->sum(static fn (Invoice $i): float => (float) $i->amount_paid)),
                'outstanding_amount' => Money::round($all->sum(static fn (Invoice $i): float => (float) $i->amount_due)),
                'by_status' => (object) $byStatus,
            ],
            'payments_enabled' => false,
            'payments_reason' => self::PAYMENTS_DISABLED_REASON,
        ];
    }

    public function find(Client $client, int $id): Invoice
    {
        return Invoice::query()->where('client_id', $client->id)->with(['plan:id,name', 'items', 'transactions'])->find($id)
            ?? throw ApiException::notFound('Invoice not found.');
    }

    /**
     * Payments, invoices and subscription events on one timeline. The summary
     * counts the whole ledger, not the filtered page.
     *
     * @param  array<string, mixed>  $filters  type, status, search, from, to, page, limit
     * @return array<string, mixed>
     */
    public function history(Client $client, array $filters): array
    {
        [$page, $limit] = $this->page($filters);

        $rows = Transaction::query()->where('client_id', $client->id)->with('invoice:id,invoice_number')->get()
            ->map(static fn (Transaction $t): array => [
                'key' => 'tx-'.$t->id,
                'occurred_at' => $t->occurred_at,
                'description' => $t->description,
                'type' => $t->type,
                'amount' => (float) $t->amount,
                'currency_code' => $t->currency_code,
                'status' => $t->status,
                'reference' => $t->reference,
                'invoice_id' => $t->invoice_id,
                'invoice_number' => $t->invoice?->invoice_number,
            ])
            ->concat(SubscriptionEvent::query()->where('client_id', $client->id)->get()->map(static fn (SubscriptionEvent $e): array => [
                'key' => 'ev-'.$e->id,
                'occurred_at' => $e->occurred_at,
                'description' => $e->description,
                'type' => 'setup',
                'event_type' => $e->type,
                'amount' => null,
                'currency_code' => $e->currency_code,
                'status' => 'completed',
                'reference' => null,
                'invoice_id' => null,
                'invoice_number' => null,
            ]))
            ->sortByDesc(static fn (array $r): int => $r['occurred_at']->getTimestamp())->values();

        $summary = ['all' => $rows->count(), 'payment' => 0, 'invoice' => 0, 'refund' => 0, 'setup' => 0, 'failed' => 0];
        foreach ($rows as $row) {
            if (array_key_exists($row['type'], $summary)) {
                $summary[$row['type']]++;
            }
            if ($row['status'] === 'failed') {
                $summary['failed']++;
            }
        }

        $filtered = collect($this->filterHistory($rows->all(), $filters));

        return [
            'transactions' => $filtered->forPage($page, $limit)->map(static fn (array $r): array => [...$r, 'occurred_at' => Dates::at($r['occurred_at'])])->values()->all(),
            'pagination' => ['page' => $page, 'limit' => $limit, 'totalItems' => $filtered->count(), 'totalPages' => max(1, (int) ceil($filtered->count() / $limit))],
            'summary' => $summary,
            'filtered_count' => $filtered->count(),
            'note' => 'Payments appear here once online payment is enabled.',
        ];
    }

    /** @return array<string, mixed> */
    public function present(Invoice $i, bool $detail = false): array
    {
        $data = [
            'id' => $i->id,
            'invoice_number' => $i->invoice_number,
            'status' => $this->displayStatus($i),
            'stored_status' => $i->status,
            'issue_date' => Dates::day($i->issue_date),
            'due_date' => Dates::day($i->due_date),
            'paid_at' => Dates::at($i->paid_at),
            'period_start' => Dates::day($i->period_start),
            'period_end' => Dates::day($i->period_end),
            'currency_code' => $i->currency_code,
            'amount_in_words' => Money::inWords($i->total, $i->currency_code),
            'subtotal' => (float) $i->subtotal,
            'discount_amount' => (float) $i->discount_amount,
            'tax_rate' => (float) $i->tax_rate,
            'tax_amount' => (float) $i->tax_amount,
            'tax_breakdown' => $i->tax_breakdown ?? [],
            'tax_inclusive' => $i->tax_inclusive,
            'total' => (float) $i->total,
            'amount_paid' => (float) $i->amount_paid,
            'amount_due' => (float) $i->amount_due,
            'billing_name' => $i->billing_name,
            'billing_email' => $i->billing_email,
            'billing_address' => $i->billing_address,
            'billing_gstin' => $i->billing_gstin,
            'notes' => $i->notes,
            'plan' => $i->relationLoaded('plan') ? $i->plan?->only(['id', 'name']) : null,
            'created_at' => Dates::at($i->created_at),
        ];
        if (! $detail) {
            return $data;
        }

        $transactions = $i->transactions;
        $settling = $transactions->filter(static fn (Transaction $t): bool => $t->type === 'payment' && $t->status !== 'failed')->sortByDesc('occurred_at')->first();

        return [
            ...$data,
            'items' => $i->items->map(static fn ($it): array => [
                'id' => $it->id,
                'item_type' => $it->item_type,
                'description' => $it->description,
                'period_start' => Dates::day($it->period_start),
                'period_end' => Dates::day($it->period_end),
                'quantity' => (float) $it->quantity,
                'unit_price' => (float) $it->unit_price,
                'amount' => (float) $it->amount,
            ])->all(),
            'transactions' => $transactions->map(fn (Transaction $t): array => [
                'id' => $t->id,
                'type' => $t->type,
                'status' => $t->status,
                'description' => $t->description,
                'amount' => (float) $t->amount,
                'reference' => $t->reference,
                'gateway' => $t->gateway,
                'gateway_transaction_id' => $t->gateway_transaction_id,
                'payment_method' => $this->presentMethod($t),
                'occurred_at' => Dates::at($t->occurred_at),
            ])->all(),
            'payment_method' => $this->presentMethod($settling),
            'timeline' => $this->timeline($i, $transactions),
        ];
    }

    /** What the invoice shows: paid / partially paid / overdue / unpaid, from the amounts. */
    public function displayStatus(Invoice $i): string
    {
        if (in_array($i->status, ['cancelled', 'refunded', 'draft'], true)) {
            return $i->status;
        }
        $paid = (float) $i->amount_paid;
        $due = (float) $i->amount_due;

        return match (true) {
            $due <= 0 && $paid > 0 => 'paid',
            $paid > 0 && $due > 0 => 'partially_paid',
            $i->due_date !== null && $i->due_date->lt(today()) => 'overdue',
            default => 'unpaid',
        };
    }

    private function applyPayment(Invoice $invoice, float $amount): void
    {
        $paid = Money::round((float) $invoice->amount_paid + $amount);
        $due = Money::round((float) $invoice->total - $paid);
        $invoice->forceFill([
            'amount_paid' => $paid,
            'amount_due' => max($due, 0),
            'status' => $due <= 0 ? 'paid' : 'partially_paid',
            'paid_at' => $due <= 0 ? now() : $invoice->paid_at,
        ])->save();
    }

    /** INV-<year>-<6 digits>, the next free number (row lock against a concurrent invoice). */
    private function nextNumber(int $year): string
    {
        $prefix = "INV-{$year}-";
        $last = Invoice::query()->where('invoice_number', 'like', $prefix.'%')->orderByDesc('invoice_number')->lockForUpdate()->value('invoice_number');
        $sequence = $last !== null ? (int) substr((string) $last, strlen($prefix)) + 1 : 1;

        return $prefix.str_pad((string) $sequence, 6, '0', STR_PAD_LEFT);
    }

    /** @return array<string, mixed>|null */
    private function presentMethod(?Transaction $t): ?array
    {
        if ($t === null || ($t->method_label === null && $t->method_brand === null && $t->method_last4 === null && $t->gateway === null)) {
            return null;
        }
        $brand = $t->method_brand !== null ? ucfirst($t->method_brand) : null;

        return [
            'payment_method_id' => $t->payment_method_id,
            'brand' => $t->method_brand,
            'last4' => $t->method_last4,
            'gateway' => $t->gateway,
            'gateway_transaction_id' => $t->gateway_transaction_id,
            'label' => $t->method_label
                ?? ($t->method_last4 !== null ? ($brand ?? 'Card').' ending in '.$t->method_last4 : ($brand ?? ($t->gateway !== null ? ucfirst($t->gateway) : 'Payment method'))),
        ];
    }

    /**
     * Derived from what happened, never stored.
     *
     * @param  Collection<int, Transaction>  $transactions
     * @return list<array<string, mixed>>
     */
    private function timeline(Invoice $i, Collection $transactions): array
    {
        $entries = [['key' => 'created', 'label' => 'Invoice created', 'detail' => "Invoice {$i->invoice_number} was created.", 'at' => $i->created_at ?? $i->issue_date]];
        foreach ($transactions as $t) {
            if ($t->type === 'payment' && $t->status !== 'failed') {
                $via = $t->method_label !== null ? ' via '.$t->method_label : ($t->method_last4 !== null ? ' via '.(ucfirst((string) $t->method_brand) ?: 'card').' ending in '.$t->method_last4 : '');
                $entries[] = ['key' => 'payment-'.$t->id, 'label' => 'Payment received', 'detail' => ($t->description ?? 'Payment received').$via.'.', 'at' => $t->occurred_at];
            }
            if ($t->type === 'refund') {
                $entries[] = ['key' => 'refund-'.$t->id, 'label' => 'Refund issued', 'detail' => $t->description ?? 'Refund issued.', 'at' => $t->occurred_at];
            }
        }
        if ($i->paid_at !== null) {
            $entries[] = ['key' => 'paid', 'label' => 'Invoice paid', 'detail' => 'Invoice marked as paid.', 'at' => $i->paid_at];
        }
        usort($entries, static fn (array $a, array $b): int => $a['at'] <=> $b['at']);

        return array_map(static fn (array $e): array => [...$e, 'at' => Dates::at($e['at'])], $entries);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, mixed>  $filters
     * @return list<array<string, mixed>>
     */
    private function filterHistory(array $rows, array $filters): array
    {
        $type = $filters['type'] ?? null;
        $status = $filters['status'] ?? null;
        $search = is_string($filters['search'] ?? null) ? mb_strtolower(trim($filters['search'])) : '';
        $from = $this->date($filters['from'] ?? null);
        $to = $this->date($filters['to'] ?? null);
        // A plain date as "to" includes that whole day.
        if ($to !== null && is_string($filters['to']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($filters['to']))) {
            $to = $to->endOfDay();
        }

        return collect($rows)
            ->when(is_string($type) && $type !== '' && $type !== 'all', static fn (Collection $c) => $c->where('type', $type))
            ->when(is_string($status) && $status !== '' && $status !== 'all', static fn (Collection $c) => $c->where('status', $status))
            ->when($search !== '', static fn (Collection $c) => $c->filter(static fn (array $r): bool => collect([$r['description'], $r['invoice_number'], $r['reference']])
                ->contains(static fn ($v): bool => $v !== null && str_contains(mb_strtolower((string) $v), $search))))
            ->when($from !== null, static fn (Collection $c) => $c->filter(static fn (array $r): bool => $r['occurred_at'] >= $from))
            ->when($to !== null, static fn (Collection $c) => $c->filter(static fn (array $r): bool => $r['occurred_at'] <= $to))
            ->values()
            ->all();
    }

    private function date(mixed $value): ?Carbon
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }
        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{int, int}
     */
    private function page(array $filters): array
    {
        $page = max(1, (int) ($filters['page'] ?? 1));
        $limit = min(100, max(1, (int) ($filters['limit'] ?? 10)));

        return [$page, $limit];
    }
}
