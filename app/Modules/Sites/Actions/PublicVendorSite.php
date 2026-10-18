<?php

declare(strict_types=1);

namespace App\Modules\Sites\Actions;

use App\Core\Exceptions\ApiException;
use App\Core\Tenancy\CurrentCompany;
use App\Core\Tenancy\CurrentVendor;
use App\Modules\Vendors\Models\Vendor;

/**
 * What a visitor does on a vendor's public website that the Sites module
 * owns: sending a message through the contact form (v1 createPublicContactMessage).
 * Sign-up, sign-in and the newsletter form belong to Clients.
 */
final class PublicVendorSite
{
    public function __construct(
        private readonly Sites $sites,
        private readonly SiteContent $content,
        private readonly CurrentVendor $vendors,
        private readonly CurrentCompany $companies,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function contact(string $slug, array $input): array
    {
        $vendor = Vendor::findActiveBySlug($slug) ?? throw ApiException::notFound('Vendor not found');

        $text = static fn (string $key): string => trim(is_scalar($input[$key] ?? null) ? (string) $input[$key] : '');
        if ($text('name') === '' || $text('email') === '' || $text('message') === '') {
            throw ApiException::badRequest('Name, email, and message are required');
        }

        return $this->companies->runAs((int) $vendor->company_id, fn (): array => $this->vendors->runAs($vendor->id, function () use ($vendor, $input, $text): array {
            $site = $this->sites->ensureVendorSite($vendor);

            return $this->content->create($site, 'contactMessages', [
                'category_id' => $input['category_id'] ?? null,
                'category_other' => $text('category_other') !== '' ? $text('category_other') : null,
                'name' => $text('name'),
                'email' => $text('email'),
                'phone' => $text('phone') !== '' ? $text('phone') : null,
                'subject' => $text('subject') !== '' ? $text('subject') : null,
                'message' => $text('message'),
                'status' => 'new',
                'metadata_json' => is_array($input['metadata'] ?? null) ? $input['metadata'] : null,
            ]);
        }));
    }
}
