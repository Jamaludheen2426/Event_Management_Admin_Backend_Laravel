<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Resources\V1;

use App\Core\Http\Resources\V1Resource;
use App\Modules\Platform\Models\Company;
use Illuminate\Http\Request;

/**
 * @mixin Company
 *
 * @property Company $resource
 */
final class CompanyResource extends V1Resource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $company = $this->resource;

        return [
            'id' => $company->id,
            'name' => $company->name,
            'slug' => $company->slug,
            'domain' => $company->getAttribute('domain'),
            'logo' => $company->getAttribute('logo'),
            'email' => $company->getAttribute('email'),
            'phone' => $company->getAttribute('phone'),
            'address' => $company->getAttribute('address'),
            'timezone' => $company->getAttribute('timezone'),
            'is_active' => $this->flag($company->is_active),
            'settings' => null,
            'max_users' => $company->getAttribute('max_users'),
            'created_by' => $company->getAttribute('created_by'),
            'updated_by' => $company->getAttribute('updated_by'),
            'created_at' => $this->isoDate($company->created_at),
            'updated_at' => $this->isoDate($company->updated_at),
            'deleted_at' => $this->isoDate($company->getAttribute('deleted_at')),
            'user_count' => $this->whenHas('user_count'),
            'active_user_count' => $this->whenHas('active_user_count'),
        ];
    }
}
