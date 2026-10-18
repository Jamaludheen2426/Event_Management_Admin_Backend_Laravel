<?php

declare(strict_types=1);

namespace App\Modules\Vendors\Http\Resources\V1;

use App\Core\Http\Resources\V1Resource;
use App\Modules\Vendors\Models\Vendor;
use Illuminate\Http\Request;

/**
 * A vendor as v1 sent it: ONE object holding the business (company_*), the
 * owner's person and login (name, email, contact, profile, address) and the
 * bank details. The business is the vendors row; the person is the owner
 * vendor_user. Location keys keep v1's names (city_id = district,
 * pincode_id = city); `membership` is the plan type's name.
 *
 * Needs relations owner and planType loaded; district / city for detail.
 *
 * @property Vendor $resource
 */
final class VendorResource extends V1Resource
{
    private bool $withFeatures = false;

    /** /vendors/auth/me adds features.website_management. */
    public function withFeatures(bool $websiteManagement): self
    {
        $this->withFeatures = $websiteManagement;
        $this->featuresSet = true;

        return $this;
    }

    private bool $featuresSet = false;

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $v = $this->resource;
        $owner = $v->relationLoaded('owner') ? $v->owner : null;
        $district = $v->relationLoaded('district') ? $v->district : null;
        $city = $v->relationLoaded('city') ? $v->city : null;

        $row = [
            'id' => $v->id,
            'company_id' => $v->company_id,
            'company_name' => $v->company_name,
            'company_logo' => $v->getAttribute('logo'),
            'country_id' => $v->getAttribute('country_id'),
            'state_id' => $v->getAttribute('state_id'),
            'city_id' => $v->getAttribute('district_id'),
            'pincode_id' => $v->getAttribute('city_id'),
            'latitude' => $v->getAttribute('latitude'),
            'longitude' => $v->getAttribute('longitude'),
            'reg_no' => $v->getAttribute('reg_no'),
            'gst_no' => $v->getAttribute('gst_no'),
            'company_address' => $v->getAttribute('address'),
            'about_us' => $v->getAttribute('about_us'),
            'company_information' => $v->getAttribute('company_information'),
            'short_description' => $v->getAttribute('short_description'),
            'company_contact' => $v->getAttribute('phone'),
            'landline' => $v->getAttribute('landline'),
            'company_email' => $v->getAttribute('email'),
            'website' => $v->getAttribute('website'),
            'name' => $owner?->name,
            'profile' => $owner?->profile_pic,
            'address' => $owner?->getAttribute('address'),
            'alt_address' => $v->getAttribute('alt_address'),
            'alt_email' => $v->getAttribute('alt_email'),
            'contact_mode' => $v->getAttribute('contact_mode'),
            'contact' => $owner?->mobile,
            'alt_contact' => $v->getAttribute('alt_phone'),
            'email' => $owner?->email,
            'membership' => $v->relationLoaded('planType') ? $v->planType?->name : null,
            'plan_type_id' => $v->getAttribute('plan_type_id'),
            'website_enabled' => $this->flag($v->getAttribute('website_enabled')),
            'copywrite' => $v->getAttribute('copywrite'),
            'poweredby' => $v->getAttribute('poweredby'),
            'status' => $v->status->value,
            'bank_name' => $v->getAttribute('bank_name'),
            'acc_no' => $v->getAttribute('account_number'),
            'ifsc_code' => $v->getAttribute('ifsc_code'),
            'acc_type' => $v->getAttribute('account_type'),
            'branch' => $v->getAttribute('branch'),
            'bank_logo' => $v->getAttribute('bank_logo'),
            'password_changed_at' => $this->isoDate($owner?->password_changed_at),
            'created_at' => $this->isoDate($v->created_at),
            'updated_at' => $this->isoDate($v->updated_at),
            'deleted_at' => $this->isoDate($v->getAttribute('deleted_at')),
        ];

        if ($v->relationLoaded('district') || $v->relationLoaded('city')) {
            $row['district'] = $district === null ? null : ['id' => $district->id, 'name' => $district->name];
            $row['locality'] = $city === null ? null : ['id' => $city->id, 'name' => $city->name, 'pincode' => $city->pincode];
            $row['city'] = $city->name ?? $district?->name;
        }
        if ($this->featuresSet) {
            $row['features'] = ['website_management' => $this->withFeatures];
        }

        return [...$row, ...$this->pendingApprovalField()];
    }
}
