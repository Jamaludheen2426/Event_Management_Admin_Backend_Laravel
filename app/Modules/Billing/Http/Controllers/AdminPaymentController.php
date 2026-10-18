<?php

declare(strict_types=1);

namespace App\Modules\Billing\Http\Controllers;

use App\Core\Http\ApiResponse;
use App\Core\Queries\CallbackListQuery;
use App\Modules\Billing\Actions\AdminPayments;
use App\Modules\Billing\Models\Transaction;
use App\Modules\Identity\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * /api/v1/payments — the admin Payments screen (v1 payment.controller).
 */
final class AdminPaymentController
{
    public function __construct(private readonly AdminPayments $payments) {}

    public function index(Request $request): JsonResponse
    {
        $status = $request->query('status');
        $gateway = $request->query('gateway');
        $search = trim((string) $request->query('search', ''));
        $payments = $this->payments;

        $page = (new CallbackListQuery(
            $this->payments->query(),
            [],
            ['occurred_at'],
            false,
            static function (Builder $query) use ($payments, $status, $gateway, $search): void {
                if (is_string($status) && in_array($status, AdminPayments::STATUSES, true)) {
                    $payments->filterByScreenStatus($query, $status);
                }
                if (is_string($gateway) && $gateway !== '') {
                    $query->where('gateway', $gateway);
                }
                if ($search !== '') {
                    $query->where(static fn (Builder $q) => $q->where('gateway_transaction_id', 'like', "%{$search}%")
                        ->orWhere('description', 'like', "%{$search}%")
                        ->orWhere('reference', 'like', "%{$search}%"));
                }
            },
        ))->paginate($request->duplicate(array_diff_key($request->query(), ['search' => 1, 'status' => 1])))
            ->through(fn (Transaction $t): array => $this->payments->present($t));

        return ApiResponse::paginated($page);
    }

    public function stats(): JsonResponse
    {
        return ApiResponse::success($this->payments->stats());
    }

    public function show(int $id): JsonResponse
    {
        return ApiResponse::success(['payment' => $this->payments->present($this->payments->find($id))]);
    }

    public function store(Request $request): JsonResponse
    {
        /** @var User $admin */
        $admin = $request->user();
        $payment = $this->payments->record($request->all(), $admin);

        return ApiResponse::created(['payment' => $this->payments->present($payment)], 'Payment recorded successfully');
    }

    public function status(Request $request, int $id): JsonResponse
    {
        $payment = $this->payments->setStatus($this->payments->find($id), $request->input('status'));

        return ApiResponse::success(['payment' => $this->payments->present($payment)], 'Payment status updated successfully');
    }
}
