<?php

declare(strict_types=1);

namespace App\Modules\Platform\Media;

use App\Core\Tenancy\CurrentCompany;
use App\Core\Tenancy\Tenancy;
use App\Modules\Platform\Models\Setting;

/**
 * A company's storage settings (settings group "media"), read once per
 * request. Outside a request (queued jobs, commands) the house company's
 * settings apply.
 *
 * Keys (v1): driver (local | s3 | r2 | digitalocean | wasabi | backblaze |
 * bunnycdn | minio | other), aws_access_key, aws_secret_key, aws_region,
 * aws_bucket, aws_url, aws_endpoint, aws_account_id, use_path_style_endpoint,
 * custom_s3_path.
 */
final class MediaSettings
{
    /** @var array<int, array<string, string|null>> */
    private array $cache = [];

    public function __construct(private readonly CurrentCompany $company) {}

    /** @return array<string, string|null> */
    public function forCurrentCompany(): array
    {
        $companyId = $this->company->has() ? $this->company->id() : (int) config('tenancy.house_company_id');

        return $this->cache[$companyId] ??= Tenancy::bypass(static fn (): array => Setting::query()
            ->where('company_id', $companyId)
            ->where('group', 'media')
            ->where('is_active', true)
            ->get()
            ->mapWithKeys(static fn (Setting $s): array => [$s->key => $s->value])
            ->all());
    }

    /**
     * v1 read the optimize group without a company filter; it is per company now.
     *
     * @return array{enabled: bool, quality: int}
     */
    public function compression(): array
    {
        $companyId = $this->company->has() ? $this->company->id() : (int) config('tenancy.house_company_id');
        $values = Tenancy::bypass(static fn (): array => Setting::query()
            ->where('company_id', $companyId)
            ->where('group', 'optimize')
            ->where('is_active', true)
            ->pluck('value', 'key')
            ->all());

        return [
            'enabled' => ($values['optimize.image_compression'] ?? '0') === '1',
            'quality' => min(100, max(1, (int) ($values['optimize.image_quality'] ?? 80))),
        ];
    }

    public function forget(): void
    {
        $this->cache = [];
    }
}
