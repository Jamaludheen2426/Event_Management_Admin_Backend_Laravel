<?php

declare(strict_types=1);

use App\Modules\Billing\Models\ClientSubscription;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Support\Money;
use App\Modules\Billing\Support\UsageMeters;
use App\Modules\Catalog\Models\EventMenu;
use App\Modules\Catalog\Models\Plan;
use App\Modules\Catalog\Models\PlanMenu;
use App\Modules\Clients\Models\Client;

/*
| Client billing (/api/v1/client/billing/*) and the admin Payments screen
| (/api/v1/payments). v1 clientBilling, clientInvoice, clientPaymentMethod and
| payment services.
*/

beforeEach(function () {
    seedHouse();
    $this->travelTo(now()->setDate(2026, 10, 20)->setTime(10, 0));
});

function billingPlan(string $code, float $price, array $attributes = []): Plan
{
    return Plan::query()->create(['company_id' => 1, 'name' => ucfirst(strtolower($code)), 'plan_code' => $code, 'price' => $price, 'billing_cycle' => 'monthly', 'is_active' => 1, ...$attributes]);
}

it('starts the subscription of a client the admin put on a plan, with its first invoice', function () {
    $silver = billingPlan('SILVER', 1000, ['max_events' => 3, 'storage_limit' => 2, 'storage_unit' => 'GB']);
    $menu = EventMenu::query()->create(['company_id' => 1, 'name' => 'Gallery', 'slug' => 'gallery', 'menu_group' => 'addon']);
    PlanMenu::query()->create(['plan_id' => $silver->id, 'event_menu_id' => $menu->id, 'sort_order' => 1]);
    $client = client(['plan_id' => $silver->id, 'created_at' => now()->subDays(40)]);
    app(UsageMeters::class)->register(static fn (Client $c): array => ['events' => 2, 'storage_bytes' => 512 * 1024 * 1024]);

    $token = accessToken($client);
    $overview = $this->withToken($token)->getJson('/api/v1/client/billing/overview')
        ->assertOk()->assertJsonPath('message', 'Billing overview retrieved')
        ->assertJsonPath('data.subscription.status', 'active')
        ->assertJsonPath('data.subscription.plan.plan_code', 'SILVER')
        ->assertJsonPath('data.subscription.amount.total', 1180)
        ->assertJsonPath('data.features.0.label', 'Gallery')->assertJsonPath('data.features.0.group', 'addon')
        ->assertJsonPath('data.usage.events.used', 2)->assertJsonPath('data.usage.events.limit', 3)
        ->assertJsonPath('data.usage.storage.used', 0.5)->assertJsonPath('data.usage.storage.limit_gb', 2)
        ->json('data.subscription');

    // Joined 40 days ago on a monthly plan: the term was rolled forward to today.
    expect($overview['current_period_start'])->toStartWith('2026-10-10')->and($overview['current_period_end'])->toStartWith('2026-11-10');

    $this->withToken($token)->getJson('/api/v1/client/billing/invoices')
        ->assertOk()->assertJsonPath('data.invoices.0.invoice_number', 'INV-2026-000001')
        ->assertJsonPath('data.invoices.0.status', 'unpaid')->assertJsonPath('data.invoices.0.total', 1180)
        ->assertJsonPath('data.stats.outstanding_amount', 1180)->assertJsonPath('data.payments_enabled', false);

    // Opening billing again does not create a second subscription or invoice.
    $this->withToken($token)->getJson('/api/v1/client/billing/overview')->assertOk();
    expect(ClientSubscription::query()->count())->toBe(1)->and(Invoice::query()->count())->toBe(1);
});

it('explains when there is no plan', function () {
    $this->withToken(accessToken(client()))->getJson('/api/v1/client/billing/overview')
        ->assertOk()->assertJsonPath('data.subscription', null)
        ->assertJsonPath('data.reason', 'No subscription plan is assigned to your account yet. Please contact us.');
    $this->withToken(accessToken(client()))->postJson('/api/v1/client/billing/cancel')
        ->assertStatus(400)->assertJsonPath('message', 'There is no active subscription to cancel.');
});

it('schedules a plan change for the term end and applies it then', function () {
    $silver = billingPlan('SILVER', 1000);
    $gold = billingPlan('GOLD', 2000);
    billingPlan('HIDDEN', 5, ['is_visible' => 0]);
    $client = client(['plan_id' => $silver->id]);
    $token = accessToken($client);

    $this->withToken($token)->getJson('/api/v1/client/billing/plans')
        ->assertOk()->assertJsonCount(2, 'data.plans')->assertJsonPath('data.plans.0.is_current', true)
        ->assertJsonPath('data.plans.1.amount.total', 2360);

    $this->travel(1)->minutes();
    $this->withToken($token)->postJson('/api/v1/client/billing/change-plan', ['plan_id' => 999])
        ->assertStatus(400)->assertJsonPath('message', 'That plan is not available.');
    $this->withToken($token)->postJson('/api/v1/client/billing/change-plan', ['plan_id' => $gold->id])
        ->assertOk()->assertJsonPath('message', 'Plan change scheduled')
        ->assertJsonPath('data.plans.1.is_pending', true)
        ->assertJsonPath('data.subscription.pending_change.plan.plan_code', 'GOLD');

    // A month later the change has happened, with an invoice for the new term.
    $this->travel(31)->days();
    $this->withToken(accessToken($client))->getJson('/api/v1/client/billing/overview')
        ->assertJsonPath('data.subscription.plan.plan_code', 'GOLD')->assertJsonPath('data.subscription.pending_change', null);
    expect($client->fresh()->plan_id)->toBe($gold->id);

    $history = $this->withToken(accessToken($client))->getJson('/api/v1/client/billing/history?type=setup')
        ->assertOk()->json('data');
    expect(collect($history['transactions'])->pluck('event_type')->all())->toBe(['change_applied', 'change_scheduled', 'created'])
        ->and($history['summary']['invoice'])->toBe(2);
});

it('cancels at the term end, can resume before it, and ends after it', function () {
    $silver = billingPlan('SILVER', 1000);
    $client = client(['plan_id' => $silver->id]);
    $token = accessToken($client);

    $this->withToken($token)->postJson('/api/v1/client/billing/cancel', ['reason' => 'Too expensive'])
        ->assertOk()->assertJsonPath('data.subscription.status', 'cancelling')->assertJsonPath('data.subscription.next_billing_date', null);
    $this->withToken($token)->postJson('/api/v1/client/billing/change-plan', ['plan_id' => $silver->id])
        ->assertStatus(400)->assertJsonPath('message', 'Your subscription is set to end on 2026-11-20. Resume it before changing plan.');

    $this->withToken($token)->postJson('/api/v1/client/billing/resume')->assertOk()->assertJsonPath('data.subscription.status', 'active');
    $this->withToken($token)->postJson('/api/v1/client/billing/cancel')->assertOk();

    $this->travel(32)->days();
    $this->withToken(accessToken($client))->getJson('/api/v1/client/billing/overview')
        ->assertJsonPath('data.subscription.status', 'cancelled');
    $this->withToken(accessToken($client))->postJson('/api/v1/client/billing/resume')
        ->assertStatus(400)->assertJsonPath('message', 'This subscription has already ended. Please choose a plan to start a new one.');
    expect($client->fresh()->plan_id)->toBeNull();
});

it('keeps card numbers off the server and records manual payment methods', function () {
    $token = accessToken(client());

    $this->withToken($token)->postJson('/api/v1/client/billing/payment-methods', ['card_number' => '4111111111111111'])
        ->assertStatus(400)->assertJsonPath('message', 'Card details must never be sent to this server. The payment provider takes the card in your browser and returns a token; send that token instead.');
    $this->withToken($token)->postJson('/api/v1/client/billing/payment-methods', ['method_type' => 'upi', 'upi_id' => '4111 1111 1111 1111'])
        ->assertStatus(400)->assertJsonPath('message', 'That looks like a card number. Card details must never be sent to this server — send the token the payment provider gives your browser.');
    $this->withToken($token)->postJson('/api/v1/client/billing/payment-methods', ['token' => 'pm_123'])
        ->assertStatus(503);
    $this->withToken($token)->postJson('/api/v1/client/billing/payment-methods', ['method_type' => 'bank_transfer', 'bank_name' => 'HDFC', 'account_last4' => '123456789'])
        ->assertStatus(400)->assertJsonPath('message', 'Enter only the LAST 4 digits of your account number, not the whole number.');

    $this->withToken($token)->postJson('/api/v1/client/billing/payment-methods', ['method_type' => 'upi', 'upi_id' => 'Asha@okhdfc'])
        ->assertOk()->assertJsonPath('data.method.label', 'UPI · asha@okhdfc')->assertJsonPath('data.method.is_default', true)
        ->assertJsonPath('data.method.is_chargeable', false);
    $this->withToken($token)->postJson('/api/v1/client/billing/payment-methods', ['method_type' => 'upi', 'upi_id' => 'asha@okhdfc'])
        ->assertStatus(400)->assertJsonPath('message', 'You have already saved that payment method.');
    $bank = $this->withToken($token)->postJson('/api/v1/client/billing/payment-methods', ['method_type' => 'bank_transfer', 'bank_name' => 'HDFC', 'account_last4' => '4321', 'ifsc' => 'hdfc0001234'])
        ->assertOk()->assertJsonPath('data.method.label', 'HDFC ending in 4321')->json('data.method.id');

    $this->withToken($token)->putJson("/api/v1/client/billing/payment-methods/{$bank}/default")
        ->assertOk()->assertJsonPath('data.default_method.id', $bank);
    $this->withToken($token)->deleteJson("/api/v1/client/billing/payment-methods/{$bank}")
        ->assertOk()->assertJsonCount(1, 'data.methods')->assertJsonPath('data.default_method.label', 'UPI · asha@okhdfc');

    // Another client's method is not ours.
    $this->withToken(accessToken(client()))->deleteJson("/api/v1/client/billing/payment-methods/{$bank}")->assertNotFound();
});

it('lets the admin record a payment that settles an invoice', function () {
    $silver = billingPlan('SILVER', 1000);
    $client = client(['plan_id' => $silver->id, 'name' => 'Payer']);
    $this->withToken(accessToken($client))->postJson('/api/v1/client/billing/payment-methods', ['method_type' => 'cash'])->assertOk();
    $this->withToken(accessToken($client))->getJson('/api/v1/client/billing/overview')->assertOk();
    $invoice = Invoice::query()->firstOrFail();
    $admin = accessToken(adminUser());

    $pending = $this->withToken($admin)->postJson('/api/v1/payments', ['invoice_id' => $invoice->id, 'amount' => 500, 'status' => 'pending', 'gateway' => 'upi'])
        ->assertCreated()->assertJsonPath('data.payment.status', 'pending')->assertJsonPath('data.payment.user.name', 'Payer')->json('data.payment.id');
    $this->withToken($admin)->patchJson("/api/v1/payments/{$pending}/status", ['status' => 'completed'])
        ->assertOk()->assertJsonPath('data.payment.status', 'completed');
    $this->withToken($admin)->patchJson("/api/v1/payments/{$pending}/status", ['status' => 'refunded'])
        ->assertStatus(400)->assertJsonPath('message', 'Only a pending payment can change status. Refunds are issued through the payment provider.');
    $this->travel(1)->minutes();
    $this->withToken($admin)->postJson('/api/v1/payments', ['invoice_id' => $invoice->id, 'amount' => 680])->assertCreated();

    $this->withToken($admin)->getJson('/api/v1/payments/stats')
        ->assertOk()->assertJsonPath('data.total_count', 2)->assertJsonPath('data.total_revenue', 1180)->assertJsonPath('data.completed.count', 2);
    $this->withToken($admin)->getJson('/api/v1/payments?status=completed')
        ->assertOk()->assertJsonPath('pagination.totalItems', 2)->assertJsonPath('data.0.amount', '680.00');

    $this->withToken(accessToken($client))->getJson("/api/v1/client/billing/invoices/{$invoice->id}")
        ->assertOk()->assertJsonPath('data.invoice.status', 'paid')->assertJsonPath('data.invoice.amount_due', 0)
        ->assertJsonPath('data.invoice.amount_in_words', 'One Thousand One Hundred Eighty Rupees Only')
        ->assertJsonPath('data.invoice.tax_breakdown.0.label', 'CGST')
        ->assertJsonPath('data.invoice.timeline.0.key', 'created');

    $this->withToken($admin)->postJson('/api/v1/payments', ['invoice_id' => $invoice->id, 'amount' => 1])
        ->assertStatus(400)->assertJsonPath('message', 'This invoice is already paid.');

    // An employee without payments.view sees nothing.
    $this->withToken(accessToken(adminUser(role: null)))->getJson('/api/v1/payments')->assertStatus(403);
});

it('stores contact-sales enquiries', function () {
    $token = accessToken(client());
    $this->withToken($token)->postJson('/api/v1/client/billing/contact-sales', ['full_name' => 'A', 'work_email' => 'bad'])
        ->assertStatus(400)->assertJsonPath('message', 'Please enter a valid work email.');
    $this->withToken($token)->postJson('/api/v1/client/billing/contact-sales', ['full_name' => 'A', 'work_email' => 'a@ra.test', 'message' => 'Need 50 events', 'interests' => ['api', 'sso']])
        ->assertOk()->assertJsonPath('data.delivery', 'stored');
});

it('writes amounts in Indian words', function () {
    expect(Money::inWords(12345678.5))->toBe('One Crore Twenty Three Lakh Forty Five Thousand Six Hundred Seventy Eight Rupees and Fifty Paise Only')
        ->and(Money::inWords(1))->toBe('One Rupee Only')
        ->and(Money::inWords(0))->toBe('Zero Rupees Only')
        ->and(Money::inWords(10, 'USD'))->toBeNull();
});
