<?php

declare(strict_types=1);

namespace App\Modules\Billing\Actions;

use App\Core\Exceptions\ApiException;
use App\Core\Support\Dates;
use App\Core\Support\Flag;
use App\Modules\Billing\Models\ClientSubscription;
use App\Modules\Billing\Models\SubscriptionEvent;
use App\Modules\Billing\Support\Money;
use App\Modules\Billing\Support\UsageMeters;
use App\Modules\Catalog\Models\Plan;
use App\Modules\Catalog\Models\PlanMenu;
use App\Modules\Clients\Models\Client;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * A client's subscription to a platform plan (v1 clientBilling.service).
 *
 * Rules kept from v1:
 *  - A client the admin put on a plan (clients.plan_id) gets a subscription the
 *    first time billing is opened, its term rolled forward to today.
 *  - Reading a subscription brings it up to date: a scheduled plan change
 *    takes effect at the end of the term, an ended term renews (one invoice
 *    per term), a cancelled subscription ends at its term end.
 *  - A plan change is scheduled for the end of the current term; choosing the
 *    current plan again cancels a scheduled change. Cancelling keeps the plan
 *    until the term ends; resuming is possible until then.
 *  - clients.plan_id always mirrors the plan the subscription grants.
 */
final class Subscriptions
{
    private const ENDING = ['cancelled', 'cancelling'];

    private const LIMIT_KEYS = ['max_events', 'max_guests_per_event', 'max_rsvp_per_event', 'max_photos', 'max_videos'];

    private const PLAN_COLUMNS = [
        'id', 'company_id', 'name', 'plan_code', 'short_description', 'billing_cycle', 'currency_code', 'price', 'trial_days',
        'is_active', 'is_visible', 'sort_order', 'plan_badge_id', 'event_category_id',
    ];

    private const UNAVAILABLE = [
        'invoices' => 'Invoices are not generated yet.',
        'payment_methods' => 'No payment provider is connected, so cards cannot be saved.',
        'transactions' => 'Transaction history begins when payments are enabled.',
        'addons' => 'Add-ons are not available yet.',
        'coupons' => 'Coupon codes are not available yet.',
        'billing_address' => 'No billing address is stored on your account yet.',
    ];

    public function __construct(
        private readonly Invoices $invoices,
        private readonly UsageMeters $meters,
    ) {}

    /** The client's latest subscription, created from clients.plan_id if needed, brought up to date. */
    public function current(Client $client): ?ClientSubscription
    {
        $sub = ClientSubscription::query()->where('client_id', $client->id)->latest('id')->first()
            ?? $this->startFromAssignedPlan($client);
        if ($sub === null) {
            return null;
        }
        $sub->setRelation('client', $client);
        $this->reconcile($sub);

        return $sub->load(['plan', 'pendingPlan']);
    }

    /** @return array<string, mixed> */
    public function overview(Client $client): array
    {
        $sub = $this->current($client);
        if ($sub === null) {
            return [
                'subscription' => null,
                'reason' => $client->plan_id !== null
                    ? 'Your subscription plan is no longer available. Please contact us.'
                    : 'No subscription plan is assigned to your account yet. Please contact us.',
                'features' => [],
                'usage' => $this->usage($client, null, null, null),
                'unavailable' => self::UNAVAILABLE,
            ];
        }

        return [
            'subscription' => $this->present($sub),
            'reason' => null,
            'features' => $this->features($sub->plan_id),
            'usage' => $this->usage($client, $sub->plan_id, $sub->current_period_start, $sub->current_period_end),
            'unavailable' => self::UNAVAILABLE,
        ];
    }

    /** @return array<string, mixed> */
    public function plans(Client $client): array
    {
        $sub = $this->current($client);
        $plans = Plan::query()->withoutGlobalScopes()
            ->where('company_id', $client->tenantCompanyId())->where('is_active', 1)->where('is_visible', 1)
            ->orderBy('sort_order')->orderBy('price')->orderBy('id')
            ->get(self::PLAN_COLUMNS);
        $features = $this->featuresFor($plans->pluck('id')->all());

        return [
            'current_plan_id' => $sub?->plan_id,
            'subscription' => $sub !== null ? $this->present($sub) : null,
            'plans' => $plans->map(fn (Plan $p): array => [
                ...$this->planAttributes($p),
                'amount' => Money::withTax((string) $p->price, $sub !== null ? $sub->tax_rate : 18),
                'features' => $features[$p->id] ?? [],
                'is_current' => $sub?->plan_id === $p->id,
                'is_pending' => $sub?->pending_plan_id === $p->id,
            ])->all(),
            'change_effective_at' => Dates::at($sub?->current_period_end),
        ];
    }

    /** @return array<string, mixed> */
    public function changePlan(Client $client, mixed $planId): array
    {
        $targetId = Flag::id($planId) ?? throw ApiException::badRequest('Please choose a plan.');
        $sub = $this->current($client) ?? throw ApiException::badRequest('There is no active subscription on this account to change. Please contact us.');

        if (in_array($this->status($sub), self::ENDING, true)) {
            throw ApiException::badRequest($sub->current_period_end !== null
                ? 'Your subscription is set to end on '.$sub->current_period_end->format('Y-m-d').'. Resume it before changing plan.'
                : 'Your subscription has been cancelled. Resume it before changing plan.');
        }

        $plan = Plan::query()->withoutGlobalScopes()->whereKey($targetId)->where('company_id', $client->tenantCompanyId())
            ->where('is_active', 1)->where('is_visible', 1)->first(self::PLAN_COLUMNS)
            ?? throw ApiException::badRequest('That plan is not available.');

        if ($sub->plan_id === $plan->id) {
            if ($sub->pending_plan_id !== null) {
                $this->log($sub, 'change_cancelled', 'Scheduled plan change cancelled', 'client', ['from_plan_id' => $sub->plan_id, 'to_plan_id' => $sub->pending_plan_id]);
                $sub->forceFill(['pending_plan_id' => null, 'pending_effective_at' => null])->save();
            }

            return $this->plans($client);
        }

        $sub->forceFill(['pending_plan_id' => $plan->id, 'pending_effective_at' => $sub->current_period_end ?? now()])->save();
        $this->log($sub, 'change_scheduled', "Plan change to {$plan->name} scheduled", 'client', [
            'from_plan_id' => $sub->plan_id, 'to_plan_id' => $plan->id, 'amount' => $plan->price, 'currency_code' => $plan->getAttribute('currency_code'),
        ]);

        return $this->plans($client);
    }

    /** @return array<string, mixed> */
    public function cancel(Client $client, mixed $reason, mixed $comments): array
    {
        $sub = $this->current($client) ?? throw ApiException::badRequest('There is no active subscription to cancel.');
        if (in_array($this->status($sub), self::ENDING, true)) {
            throw ApiException::badRequest('This subscription is already cancelled.');
        }
        $reason = is_scalar($reason) && (string) $reason !== '' ? (string) $reason : null;

        $sub->forceFill([
            'cancel_at_period_end' => true,
            'cancelled_at' => now(),
            'cancellation_reason' => $reason !== null ? mb_substr($reason, 0, 150) : null,
            'cancellation_comments' => is_scalar($comments) && (string) $comments !== '' ? mb_substr((string) $comments, 0, 300) : null,
            'pending_plan_id' => null,
            'pending_effective_at' => null,
        ])->save();
        $this->log($sub, 'cancelled', $reason !== null ? 'Subscription cancelled — '.mb_substr($reason, 0, 200) : 'Subscription cancelled', 'client', ['from_plan_id' => $sub->plan_id]);

        return $this->overview($client);
    }

    /** @return array<string, mixed> */
    public function resume(Client $client): array
    {
        $sub = $this->current($client) ?? throw ApiException::badRequest('There is no subscription to resume.');
        if (! in_array($this->status($sub), self::ENDING, true)) {
            throw ApiException::badRequest('This subscription is not cancelled.');
        }
        if ($sub->current_period_end !== null && $sub->current_period_end->lte(now())) {
            throw ApiException::badRequest('This subscription has already ended. Please choose a plan to start a new one.');
        }

        $sub->forceFill(['cancel_at_period_end' => false, 'cancelled_at' => null, 'cancellation_reason' => null, 'cancellation_comments' => null, 'status' => 'active'])->save();
        $this->entitle($client, $sub->plan_id);
        $this->log($sub, 'resumed', 'Subscription resumed', 'client', ['to_plan_id' => $sub->plan_id]);

        return $this->overview($client);
    }

    /**
     * What the client has used in a period, against the plan's limits.
     *
     * @return array<string, mixed>
     */
    public function usage(Client $client, ?int $planId, ?CarbonInterface $from, ?CarbonInterface $to): array
    {
        $limits = $this->limits($planId);
        $used = $this->meters->measure($client, $from, $to);
        $unit = ($limits['storage_unit'] ?? null) === 'GB' ? 'GB' : 'MB';
        $bytes = $used['storage_bytes'];
        $limitGb = isset($limits['storage_limit']) ? round($unit === 'MB' ? $limits['storage_limit'] / 1024 : $limits['storage_limit'], 2) : null;

        return [
            'period_start' => Dates::at($from),
            'period_end' => Dates::at($to),
            'events' => ['used' => $used['events'], 'limit' => $limits['max_events'] ?? null, 'available' => true],
            'guests' => ['used' => $used['guests'], 'heads' => $used['heads'], 'limit' => $limits['max_guests_per_event'] ?? null, 'per_event_limit' => null, 'available' => true],
            'messages' => ['used' => null, 'limit' => null, 'available' => false, 'reason' => 'Messaging is paused — no messages have been sent from this account.'],
            'storage' => [
                'used' => round($bytes / ($unit === 'GB' ? 1024 ** 3 : 1024 ** 2), 2),
                'used_gb' => round($bytes / 1024 ** 3, 2),
                'limit_gb' => $limitGb,
                'limit' => $limits['storage_limit'] ?? null,
                'unit' => $unit,
                'available' => true,
            ],
            'rsvps' => ['limit' => $limits['max_rsvp_per_event'] ?? null],
        ];
    }

    /** Derived status: trialing / active / cancelling / cancelled / expired. */
    public function status(ClientSubscription $sub): string
    {
        if ($sub->status === 'cancelled') {
            return 'cancelled';
        }
        $end = $sub->current_period_end;
        if ($end !== null && $end->lte(now())) {
            return $sub->cancel_at_period_end ? 'cancelled' : 'expired';
        }
        if ($sub->cancel_at_period_end) {
            return 'cancelling';
        }
        if ($sub->trial_ends_at !== null && $sub->trial_ends_at->isFuture()) {
            return 'trialing';
        }

        return $sub->status === 'expired' ? 'active' : $sub->status;
    }

    /** @return array<string, mixed> */
    public function present(ClientSubscription $sub): array
    {
        $status = $this->status($sub);

        return [
            'id' => $sub->id,
            'status' => $status,
            'stored_status' => $sub->status,
            'plan' => $sub->plan !== null ? $this->planAttributes($sub->plan) : null,
            'billing_cycle' => $sub->billing_cycle,
            'started_at' => Dates::at($sub->started_at),
            'current_period_start' => Dates::at($sub->current_period_start),
            'current_period_end' => Dates::at($sub->current_period_end),
            'next_billing_date' => $status === 'cancelled' || $sub->cancel_at_period_end ? null : Dates::at($sub->current_period_end),
            'trial_ends_at' => Dates::at($sub->trial_ends_at),
            'is_trialing' => $status === 'trialing',
            'cancel_at_period_end' => $sub->cancel_at_period_end,
            'cancelled_at' => Dates::at($sub->cancelled_at),
            'cancellation_reason' => $sub->cancellation_reason,
            'amount' => Money::withTax($sub->price, $sub->tax_rate),
            'currency_code' => $sub->currency_code,
            'pending_change' => $sub->pending_plan_id !== null ? [
                'plan' => $sub->pendingPlan !== null ? $this->planAttributes($sub->pendingPlan) : null,
                'effective_at' => Dates::at($sub->pending_effective_at),
            ] : null,
        ];
    }

    // ---- internals ------------------------------------------------------------

    private function startFromAssignedPlan(Client $client): ?ClientSubscription
    {
        if ($client->plan_id === null) {
            return null;
        }
        $plan = Plan::query()->withoutGlobalScopes()->find($client->plan_id, self::PLAN_COLUMNS);
        if ($plan === null) {
            return null;
        }

        $started = CarbonImmutable::instance($client->created_at ?? now());
        $start = $started;
        $end = $this->addCycle($start, (string) $plan->getAttribute('billing_cycle'));
        for ($guard = 0; $end !== null && $end->lte(now()) && $guard < 600; $guard++) {
            $start = $end;
            $end = $this->addCycle($start, (string) $plan->getAttribute('billing_cycle'));
        }
        $trialEnds = $plan->trial_days > 0 ? $started->addDays($plan->trial_days) : null;

        return DB::transaction(function () use ($client, $plan, $started, $start, $end, $trialEnds): ClientSubscription {
            $sub = ClientSubscription::query()->create([
                'client_id' => $client->id,
                'plan_id' => $plan->id,
                'status' => $trialEnds !== null && $trialEnds->isFuture() ? 'trialing' : 'active',
                'billing_cycle' => $plan->getAttribute('billing_cycle'),
                'price' => $plan->price,
                'currency_code' => $plan->getAttribute('currency_code') ?: 'INR',
                'started_at' => $started,
                'current_period_start' => $start,
                'current_period_end' => $end,
                'trial_ends_at' => $trialEnds,
            ]);
            $sub->setRelation('client', $client)->setRelation('plan', $plan);
            $this->log($sub, 'created', "Subscription created — {$plan->name} ({$plan->getAttribute('billing_cycle')})", 'system', [
                'to_plan_id' => $plan->id, 'amount' => $plan->price, 'currency_code' => $sub->currency_code, 'occurred_at' => $started,
            ]);
            $this->invoices->raiseForTerm($sub, $start, $end, $plan->name);

            return $sub;
        });
    }

    /** Bring the subscription to today: apply a due plan change, renew ended terms, end a cancelled one. */
    private function reconcile(ClientSubscription $sub): void
    {
        /** @var Client $client */
        $client = $sub->client;

        if ($sub->pending_plan_id !== null && $sub->pending_effective_at !== null && $sub->pending_effective_at->lte(now())) {
            $next = Plan::query()->withoutGlobalScopes()->whereKey($sub->pending_plan_id)->where('is_active', 1)->first(self::PLAN_COLUMNS);
            if ($next === null) {
                $this->log($sub, 'change_cancelled', 'Scheduled plan change was dropped — the target plan is no longer available.', 'system', [
                    'from_plan_id' => $sub->plan_id, 'to_plan_id' => $sub->pending_plan_id,
                ]);
                $sub->forceFill(['pending_plan_id' => null, 'pending_effective_at' => null])->save();
            } else {
                $this->log($sub, 'change_applied', "Plan changed to {$next->name}", 'system', [
                    'from_plan_id' => $sub->plan_id, 'to_plan_id' => $next->id, 'amount' => $next->price, 'currency_code' => $next->getAttribute('currency_code'),
                ]);
                $start = CarbonImmutable::instance($sub->current_period_end ?? now());
                $sub->forceFill([
                    'plan_id' => $next->id,
                    'billing_cycle' => $next->getAttribute('billing_cycle'),
                    'price' => $next->price,
                    'currency_code' => $next->getAttribute('currency_code') ?: 'INR',
                    'pending_plan_id' => null,
                    'pending_effective_at' => null,
                    'current_period_start' => $start,
                    'current_period_end' => $this->addCycle($start, (string) $next->getAttribute('billing_cycle')),
                    'status' => 'active',
                ])->save();
                $sub->setRelation('plan', $next);
                $this->entitle($client, $next->id);
                $this->invoices->raiseForTerm($sub, $start, $sub->current_period_end, $next->name);
            }
        }

        if ($sub->status !== 'cancelled' && ! $sub->cancel_at_period_end) {
            for ($guard = 0; $sub->current_period_end !== null && $sub->current_period_end->lte(now()) && $guard < 240; $guard++) {
                $start = CarbonImmutable::instance($sub->current_period_end);
                $end = $this->addCycle($start, $sub->billing_cycle);
                if ($end === null) {
                    break;
                }
                $this->log($sub, 'renewed', 'Subscription term renewed', 'system', [
                    'to_plan_id' => $sub->plan_id, 'amount' => $sub->price, 'currency_code' => $sub->currency_code, 'occurred_at' => $start,
                ]);
                $sub->forceFill(['current_period_start' => $start, 'current_period_end' => $end])->save();
                $this->invoices->raiseForTerm($sub, $start, $end);
            }
        }

        if ($sub->cancel_at_period_end && $sub->status !== 'cancelled' && $sub->current_period_end !== null && $sub->current_period_end->lte(now())) {
            $this->log($sub, 'expired', 'Subscription ended after cancellation', 'system', ['from_plan_id' => $sub->plan_id]);
            $sub->forceFill(['status' => 'cancelled'])->save();
            $this->entitle($client, null);
        }
    }

    private function addCycle(CarbonImmutable $date, string $cycle): ?CarbonImmutable
    {
        return match ($cycle) {
            'monthly' => $date->addMonthNoOverflow(),
            'quarterly' => $date->addMonthsNoOverflow(3),
            'yearly' => $date->addYearNoOverflow(),
            default => null,
        };
    }

    private function entitle(Client $client, ?int $planId): void
    {
        $client->forceFill(['plan_id' => $planId])->save();
    }

    /** @param array<string, mixed> $extra */
    private function log(ClientSubscription $sub, string $type, string $description, string $actor, array $extra = []): void
    {
        SubscriptionEvent::query()->create([
            'client_subscription_id' => $sub->id,
            'client_id' => $sub->client_id,
            'type' => $type,
            'description' => $description,
            'actor' => $actor,
            'occurred_at' => now(),
            ...$extra,
        ]);
    }

    /** @return array<string, int|string> */
    private function limits(?int $planId): array
    {
        if ($planId === null) {
            return [];
        }
        $plan = Plan::query()->withoutGlobalScopes()->withTrashed()->find($planId, ['id', ...self::LIMIT_KEYS, 'storage_limit', 'storage_unit']);
        if ($plan === null) {
            return [];
        }
        $limits = [];
        foreach (self::LIMIT_KEYS as $key) {
            $n = (int) $plan->getAttribute($key);
            if ($n > 0) {
                $limits[$key] = $n;
            }
        }
        $storage = (int) $plan->getAttribute('storage_limit');
        if ($storage > 0 && in_array($plan->getAttribute('storage_unit'), ['MB', 'GB'], true)) {
            $limits['storage_limit'] = $storage;
            $limits['storage_unit'] = (string) $plan->getAttribute('storage_unit');
        }

        return $limits;
    }

    /** @return list<array{id: int, label: string, group: string}> */
    private function features(?int $planId): array
    {
        return $planId === null ? [] : ($this->featuresFor([$planId])[$planId] ?? []);
    }

    /**
     * The active menus each plan grants, in plan order (one query for all plans).
     *
     * @param  list<int>  $planIds
     * @return array<int, list<array{id: int, label: string, group: string}>>
     */
    private function featuresFor(array $planIds): array
    {
        if ($planIds === []) {
            return [];
        }

        return PlanMenu::query()->whereIn('plan_id', $planIds)
            ->whereHas('menu', static fn ($q) => $q->withoutGlobalScopes()->where('is_active', 1))
            ->with(['menu' => static fn ($q) => $q->withoutGlobalScopes()])
            ->orderBy('sort_order')->orderBy('id')->get()
            ->groupBy('plan_id')
            ->map(static fn ($rows): array => $rows->map(static fn (PlanMenu $r): array => [
                'id' => $r->menu->id,
                'label' => $r->menu->name,
                'group' => $r->menu->getAttribute('menu_group') ?: 'core',
            ])->values()->all())
            ->all();
    }

    /** @return array<string, mixed> */
    private function planAttributes(Plan $plan): array
    {
        return [
            'id' => $plan->id,
            'name' => $plan->name,
            'plan_code' => $plan->plan_code,
            'short_description' => $plan->getAttribute('short_description'),
            'billing_cycle' => $plan->getAttribute('billing_cycle'),
            'currency_code' => $plan->getAttribute('currency_code'),
            'price' => $plan->price,
            'trial_days' => $plan->trial_days,
            'is_active' => $plan->is_active,
            'is_visible' => $plan->getAttribute('is_visible'),
            'sort_order' => $plan->getAttribute('sort_order'),
            'plan_badge_id' => $plan->getAttribute('plan_badge_id'),
            'event_category_id' => $plan->getAttribute('event_category_id'),
        ];
    }
}
