<?php

declare(strict_types=1);

namespace App\Modules\Billing\Actions;

use App\Core\Exceptions\ApiException;
use App\Core\Support\Dates;
use App\Core\Support\Flag;
use App\Core\Tenancy\CurrentCompany;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\Transaction;
use App\Modules\Identity\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The admin Payments screen (/payments).
 *
 * v1 read a separate `payments` table that nothing ever wrote to, and its
 * stats query crashed (bug 4). Payments are the clients' payment and refund
 * rows of the billing ledger now, for every vendor of the admin's company.
 * Recording one settles (part of) an invoice — the way an admin enters a UPI,
 * bank or cash payment that arrived outside a payment provider.
 *
 * v1 statuses for the screen: pending / completed / failed / refunded / cancelled.
 */
final class AdminPayments
{
    public const STATUSES = ['pending', 'completed', 'failed', 'refunded', 'cancelled'];

    public function __construct(
        private readonly CurrentCompany $company,
        private readonly Invoices $invoices,
    ) {}

    /** @return Builder<Transaction> */
    public function query(): Builder
    {
        $companyId = $this->company->id();

        return Transaction::query()->whereIn('type', ['payment', 'refund'])
            ->whereHas('client', static fn ($q) => $q->withoutGlobalScopes()->whereHas('vendor', static fn ($v) => $v->withoutGlobalScopes()->where('company_id', $companyId)))
            ->with(['client' => static fn ($q) => $q->withoutGlobalScopes()->withTrashed(), 'invoice:id,invoice_number']);
    }

    /** The ledger status as the screen names it. */
    public function screenStatus(Transaction $t): string
    {
        return match (true) {
            $t->type === 'refund' => 'refunded',
            $t->status === 'successful', $t->status === 'completed' => 'completed',
            default => $t->status,
        };
    }

    /** @param Builder<Transaction> $query */
    public function filterByScreenStatus(Builder $query, string $status): void
    {
        match ($status) {
            'refunded' => $query->where('type', 'refund'),
            'completed' => $query->where('type', 'payment')->whereIn('status', ['successful', 'completed']),
            default => $query->where('type', 'payment')->where('status', $status),
        };
    }

    public function find(int $id): Transaction
    {
        return $this->query()->find($id) ?? throw ApiException::notFound('Payment not found');
    }

    /** @return array<string, mixed> */
    public function stats(): array
    {
        $stats = ['total_count' => 0, 'total_revenue' => 0.0];
        foreach (self::STATUSES as $status) {
            $stats[$status] = ['count' => 0, 'total' => 0.0];
        }
        foreach ($this->query()->get(['id', 'client_id', 'invoice_id', 'type', 'status', 'amount']) as $t) {
            $status = $this->screenStatus($t);
            $amount = abs((float) $t->amount);
            $stats[$status]['count']++;
            $stats[$status]['total'] = round($stats[$status]['total'] + $amount, 2);
            $stats['total_count']++;
            if ($status === 'completed') {
                $stats['total_revenue'] = round($stats['total_revenue'] + $amount, 2);
            }
        }

        return $stats;
    }

    /**
     * Record money received for an invoice of a client of this company.
     *
     * @param  array<string, mixed>  $input  invoice_id, amount, status (pending|completed), gateway, gateway_transaction_id, description
     */
    public function record(array $input, User $admin): Transaction
    {
        $invoiceId = Flag::id($input['invoice_id'] ?? null) ?? throw ApiException::badRequest('Choose the invoice this payment settles.');
        $companyId = $this->company->id();
        $invoice = Invoice::query()->whereKey($invoiceId)
            ->whereHas('client', static fn ($c) => $c->whereHas('vendor', static fn ($v) => $v->withoutGlobalScopes()->where('company_id', $companyId)))
            ->first() ?? throw ApiException::notFound('Invoice not found');
        if (in_array($invoice->status, ['paid', 'cancelled', 'refunded'], true)) {
            throw ApiException::badRequest('This invoice is already '.$invoice->status.'.');
        }
        $status = Flag::oneOf($input['status'] ?? null, ['pending', 'completed'], 'completed');

        $payment = $this->invoices->recordPayment($invoice, [
            'amount' => is_numeric($input['amount'] ?? null) ? (float) $input['amount'] : 0.0,
            'status' => $status === 'completed' ? 'successful' : 'pending',
            'gateway' => is_string($input['gateway'] ?? null) && $input['gateway'] !== '' ? mb_substr($input['gateway'], 0, 50) : 'manual',
            'gateway_transaction_id' => is_string($input['gateway_transaction_id'] ?? null) ? mb_substr($input['gateway_transaction_id'], 0, 190) : null,
            'description' => is_string($input['description'] ?? null) && $input['description'] !== '' ? mb_substr($input['description'], 0, 300) : null,
            'recorded_by' => $admin->id,
        ]);

        return $this->find($payment->id);
    }

    /**
     * v1 PATCH /payments/:id/status. A pending payment can be confirmed
     * (completed — it then counts against its invoice), failed or cancelled.
     * A payment that already counted cannot be flipped back here: refunds go
     * through the payment provider.
     */
    public function setStatus(Transaction $payment, mixed $status): Transaction
    {
        if (! is_string($status) || ! in_array($status, self::STATUSES, true)) {
            throw ApiException::badRequest('Invalid status. Allowed: '.implode(', ', self::STATUSES));
        }
        if ($this->screenStatus($payment) === $status) {
            return $payment;
        }
        if ($payment->type !== 'payment' || $payment->status !== 'pending') {
            throw ApiException::badRequest('Only a pending payment can change status. Refunds are issued through the payment provider.');
        }
        if ($status === 'refunded') {
            throw ApiException::badRequest('Only a pending payment can change status. Refunds are issued through the payment provider.');
        }

        DB::transaction(function () use ($payment, $status): void {
            $status === 'completed'
                ? $this->invoices->confirmPayment($payment)
                : $payment->forceFill(['status' => $status])->save();
        });

        return $this->find($payment->id);
    }

    /** @return array<string, mixed> v1 `payments` row shape, with the client as `user` */
    public function present(Transaction $t): array
    {
        return [
            'id' => $t->id,
            'company_id' => $this->company->id(),
            'user_id' => $t->client_id,
            'amount' => number_format(abs((float) $t->amount), 2, '.', ''),
            'currency' => $t->currency_code,
            'status' => $this->screenStatus($t),
            'gateway' => $t->gateway,
            'gateway_transaction_id' => $t->gateway_transaction_id,
            'description' => $t->description,
            'metadata' => [
                'invoice_id' => $t->invoice_id,
                'invoice_number' => $t->invoice?->invoice_number,
                'reference' => $t->reference,
                'method' => $t->method_label,
            ],
            'user' => ['id' => $t->client->id, 'name' => $t->client->name, 'email' => $t->client->email],
            'created_at' => Dates::at($t->occurred_at),
            'updated_at' => Dates::at($t->updated_at),
        ];
    }
}
