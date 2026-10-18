<?php

declare(strict_types=1);

namespace App\Modules\Clients\Http\Resources\V1;

use App\Modules\Clients\Models\Client;
use App\Modules\Clients\Models\ClientFavourite;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A client as v1 sent website_clients: the client portal, the app and the
 * admin /website-clients screens. Never the password or OTP hash; has_password
 * tells the Settings page whether "current password" makes sense.
 *
 * @mixin Client
 */
final class ClientResource extends JsonResource
{
    /** @return array<int|string, mixed> the v1 keys; mergeWhen() entries have int keys until the resource flattens them */
    public function toArray(Request $request): array
    {
        /** @var Client $c */
        $c = $this->resource;
        $favourites = $c->relationLoaded('favourites') ? $c->favourites : collect();
        $refs = static fn (string $kind): array => $favourites->where('kind', $kind)->map(static fn (ClientFavourite $f): string => $f->ref)->values()->all();
        $iso = static fn ($date): ?string => $date?->utc()->format('Y-m-d\TH:i:s.v\Z');

        return [
            'id' => $c->id,
            'vendor_id' => $c->vendor_id,
            'company_id' => $c->relationLoaded('vendor') ? $c->vendor->company_id : null,
            'name' => $c->name,
            'company_name' => $c->company_name,
            'bio' => $c->bio,
            'email' => $c->email,
            'dial_code' => $c->dial_code,
            'mobile' => $c->mobile,
            'source' => $c->source->value,
            'subscription_plan_id' => $c->plan_id,
            'provider_id' => $c->provider_id,
            'avatar_url' => $c->avatar_url,
            'email_verified' => (int) $c->email_verified,
            'mobile_verified' => (int) $c->mobile_verified,
            'is_active' => $c->is_active,
            'login_access' => (int) $c->login_access,
            'last_login_at' => $iso($c->last_login_at),
            'created_at' => $iso($c->created_at),
            'updated_at' => $iso($c->updated_at),
            'favourite_templates' => $refs('template'),
            'favourite_events' => $refs('event'),
            'has_password' => $c->hasPassword() ? 1 : 0,
            $this->mergeWhen($c->relationLoaded('vendor'), fn (): array => [
                'vendor' => ['id' => $c->vendor->id, 'company_name' => $c->vendor->company_name],
            ]),
            $this->mergeWhen($c->relationLoaded('plan'), fn (): array => [
                'plan' => $c->plan?->only(['id', 'name', 'plan_code', 'billing_cycle', 'short_description', 'is_active']),
            ]),
        ];
    }
}
