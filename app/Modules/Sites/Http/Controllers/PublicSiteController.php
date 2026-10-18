<?php

declare(strict_types=1);

namespace App\Modules\Sites\Http\Controllers;

use App\Core\Exceptions\ApiException;
use App\Core\Http\ApiResponse;
use App\Modules\Sites\Actions\PublicSiteBundle;
use App\Modules\Sites\Actions\PublicVendorSite;
use App\Modules\Sites\Actions\SitePayloads;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * /api/v1/public — sites as visitors see them, no sign-in.
 */
final class PublicSiteController
{
    /** A vendor's public website (/vendors/{slug}/website-data, /website/{slug}). */
    public function vendorWebsite(SitePayloads $payloads, string $slug): JsonResponse
    {
        $data = $payloads->publicWebsite($slug) ?? throw ApiException::notFound('Vendor not found');

        return ApiResponse::success($data, 'Vendor data retrieved');
    }

    public function contact(Request $request, PublicVendorSite $site, string $slug): JsonResponse
    {
        return ApiResponse::created($site->contact($slug, $request->all()), 'Contact message submitted successfully');
    }

    /**
     * Is this host a site, and is it live? Answers 200 {found: false} rather
     * than 404, so the caller can tell "no such site" from "API down" (v1).
     */
    public function resolve(Request $request, PublicSiteBundle $sites): JsonResponse
    {
        $site = $sites->resolve(self::host($request), $request->query('slug'), $request->query('company_id'));

        return $site === null
            ? ApiResponse::success(['found' => false], 'No site for this host')
            : ApiResponse::success($sites->identity($site), 'Site resolved');
    }

    /** Everything needed to render any page of the site (?lang= adds that language's overlay). */
    public function bundle(Request $request, PublicSiteBundle $sites): JsonResponse
    {
        $site = $sites->resolve(self::host($request), $request->query('slug'), $request->query('company_id'));
        if ($site === null) {
            return ApiResponse::success(['found' => false], 'No site for this host');
        }
        $language = $request->query('lang', $request->query('code'));

        return ApiResponse::success($sites->bundle($site, is_string($language) ? $language : null), 'Site bundle retrieved');
    }

    /** The host the visitor typed: ?host= (local development), else the forwarded or own Host. */
    private static function host(Request $request): string
    {
        foreach ([$request->query('host'), $request->header('x-forwarded-host'), $request->header('x-site-host'), $request->getHost()] as $host) {
            if (is_string($host) && $host !== '') {
                return $host;
            }
        }

        return '';
    }
}
