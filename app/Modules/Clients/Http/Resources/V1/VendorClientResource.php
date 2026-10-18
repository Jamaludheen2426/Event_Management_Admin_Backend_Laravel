<?php

declare(strict_types=1);

namespace App\Modules\Clients\Http\Resources\V1;

use App\Modules\Clients\Models\Client;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A client as v1 sent vendor_clients: the vendor and staff portals and the
 * vendor-client portal. client_id is the "CLI-…" code; plan is the vendor
 * tier's name; client_type is the newsletter choice.
 *
 * @mixin Client
 */
final class VendorClientResource extends JsonResource
{
    /** @return array<int|string, mixed> the v1 keys; mergeWhen() entries have int keys until the resource flattens them */
    public function toArray(Request $request): array
    {
        /** @var Client $c */
        $c = $this->resource;
        $iso = static fn ($date): ?string => $date?->utc()->format('Y-m-d\TH:i:s.v\Z');

        return [
            'id' => $c->id,
            'vendor_id' => $c->vendor_id,
            'client_id' => $c->code,
            'name' => $c->name,
            'mobile' => $c->mobile,
            'email' => $c->email,
            'profile_pic' => $c->avatar_url,
            'registration_type' => $c->registration_type,
            'plan' => $c->relationLoaded('planType') ? $c->planType?->name : null,
            'subscription_id' => $c->plan_type_id,
            'is_active' => $c->is_active,
            'address' => $c->getAttribute('address'),
            'country' => $c->getAttribute('country'),
            'state' => $c->getAttribute('state'),
            'district' => $c->getAttribute('district'),
            'city' => $c->getAttribute('city'),
            'locality' => $c->getAttribute('locality'),
            'pincode' => $c->getAttribute('pincode'),
            'login_access' => (int) $c->login_access,
            'send_credentials_to_email' => (int) $c->send_credentials_to_email,
            'client_type' => $c->newsletter_opt_in ? 'subscribed' : 'unsubscribed',
            'source' => $c->source->value,
            'created_at' => $iso($c->created_at),
            'updated_at' => $iso($c->updated_at),
            $this->mergeWhen($c->relationLoaded('vendor'), fn (): array => [
                'vendor' => [
                    'id' => $c->vendor->id,
                    'company_name' => $c->vendor->company_name,
                    'company_logo' => $c->vendor->getAttribute('logo'),
                    'status' => $c->vendor->status->value,
                ],
            ]),
        ];
    }
}
