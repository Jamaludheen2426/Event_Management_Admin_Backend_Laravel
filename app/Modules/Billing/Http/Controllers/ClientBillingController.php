<?php

declare(strict_types=1);

namespace App\Modules\Billing\Http\Controllers;

use App\Core\Http\ApiResponse;
use App\Modules\Billing\Actions\Invoices;
use App\Modules\Billing\Actions\PaymentMethods;
use App\Modules\Billing\Actions\SalesEnquiries;
use App\Modules\Billing\Actions\Subscriptions;
use App\Modules\Clients\Models\Client;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * /api/v1/client/billing/* — the signed-in client's own plan, invoices,
 * history and payment methods (v1 clientBilling.controller). Nothing takes an
 * account id: every route acts on the signed-in client.
 */
final class ClientBillingController
{
    public function __construct(
        private readonly Subscriptions $subscriptions,
        private readonly Invoices $invoices,
        private readonly PaymentMethods $methods,
    ) {}

    public function overview(Request $request): JsonResponse
    {
        return ApiResponse::success($this->subscriptions->overview($this->client($request)), 'Billing overview retrieved');
    }

    public function plans(Request $request): JsonResponse
    {
        return ApiResponse::success($this->subscriptions->plans($this->client($request)), 'Plans retrieved');
    }

    public function changePlan(Request $request): JsonResponse
    {
        return ApiResponse::success($this->subscriptions->changePlan($this->client($request), $request->input('plan_id')), 'Plan change scheduled');
    }

    public function cancel(Request $request): JsonResponse
    {
        $data = $this->subscriptions->cancel($this->client($request), $request->input('reason'), $request->input('comments'));

        return ApiResponse::success($data, 'Subscription cancelled');
    }

    public function resume(Request $request): JsonResponse
    {
        return ApiResponse::success($this->subscriptions->resume($this->client($request)), 'Subscription resumed');
    }

    public function history(Request $request): JsonResponse
    {
        return ApiResponse::success($this->invoices->history($this->client($request), $request->query()), 'Billing history retrieved');
    }

    public function invoices(Request $request): JsonResponse
    {
        return ApiResponse::success($this->invoices->list($this->client($request), $request->query()), 'Invoices retrieved');
    }

    /** The invoice, with usage counted for the invoice's own term (not today's). */
    public function invoice(Request $request, int $id): JsonResponse
    {
        $client = $this->client($request);
        $invoice = $this->invoices->find($client, $id);

        return ApiResponse::success([
            'invoice' => $this->invoices->present($invoice, detail: true),
            'payments_enabled' => false,
            'payments_reason' => Invoices::PAYMENTS_DISABLED_REASON,
            'usage' => $this->subscriptions->usage($client, $invoice->plan_id, $invoice->period_start, $invoice->period_end),
        ], 'Invoice retrieved');
    }

    public function contactSales(Request $request, SalesEnquiries $enquiries): JsonResponse
    {
        return ApiResponse::success($enquiries->create($this->client($request), $request->all()), 'Enquiry received');
    }

    public function paymentMethods(Request $request): JsonResponse
    {
        return ApiResponse::success($this->methods->list($this->client($request)), 'Payment methods retrieved');
    }

    public function addPaymentMethod(Request $request): JsonResponse
    {
        return ApiResponse::success(['method' => $this->methods->add($this->client($request), $request->all())], 'Payment method saved');
    }

    public function defaultPaymentMethod(Request $request, int $id): JsonResponse
    {
        return ApiResponse::success($this->methods->setDefault($this->client($request), $id), 'Default payment method updated');
    }

    public function removePaymentMethod(Request $request, int $id): JsonResponse
    {
        return ApiResponse::success($this->methods->remove($this->client($request), $id), 'Payment method removed');
    }

    private function client(Request $request): Client
    {
        /** @var Client */
        return $request->user();
    }
}
