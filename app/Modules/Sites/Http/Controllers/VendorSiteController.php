<?php

declare(strict_types=1);

namespace App\Modules\Sites\Http\Controllers;

use App\Core\Http\ApiResponse;
use App\Modules\Sites\Actions\SiteContent;
use App\Modules\Sites\Actions\SitePayloads;
use App\Modules\Sites\Actions\Sites;
use App\Modules\Sites\Http\Concerns\ServesSections;
use App\Modules\Sites\Models\Site;
use App\Modules\Vendors\Models\Vendor;
use App\Modules\Vendors\Models\VendorUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * /api/v1/vendors/website/* — the builder app: the signed-in vendor's own
 * site. Reads answer empty until the site exists; the first save sets it up.
 */
final class VendorSiteController
{
    use ServesSections;

    public function __construct(private readonly Sites $sites) {}

    /** v1 checked that its tables existed; with migrations they always do. */
    public function schemaStatus(): JsonResponse
    {
        return ApiResponse::success(['schemaReady' => true, 'missingTables' => []], 'Website builder schema status retrieved');
    }

    public function initialize(Request $request): JsonResponse
    {
        return ApiResponse::success($this->sites->present($this->sites->ensureVendorSite($this->vendor($request), $request->all())), 'Vendor website initialized');
    }

    public function builderData(Request $request, SitePayloads $payloads): JsonResponse
    {
        return ApiResponse::success($payloads->builder($this->site($request, false), $this->vendor($request)->company_id), 'Website builder data retrieved');
    }

    public function previewPayload(Request $request, SitePayloads $payloads): JsonResponse
    {
        return ApiResponse::success($payloads->preview($this->site($request, false), $this->vendor($request)->company_id), 'Website preview data retrieved');
    }

    public function website(Request $request): JsonResponse
    {
        $site = $this->site($request, false);

        return ApiResponse::success($site === null ? null : $this->sites->present($site), 'Website settings retrieved');
    }

    public function updateWebsite(Request $request): JsonResponse
    {
        return ApiResponse::success($this->sites->present($this->sites->update($this->requiredSite($request, true), $request->all())), 'Website settings saved');
    }

    public function publish(Request $request, SitePayloads $payloads): JsonResponse
    {
        $site = $this->sites->publish($this->requiredSite($request, true));

        return ApiResponse::success([
            'website' => $this->sites->present($site),
            'payload' => $payloads->builder($site, $this->vendor($request)->company_id),
        ], 'Website published');
    }

    public function slides(Request $request, SiteContent $content, int $sliderId): JsonResponse
    {
        $site = $this->site($request, false);
        $rows = $site === null ? [] : $content->rows($site, 'slides', null, ['slider_id' => $content->owned($site, 'sliders', $sliderId)]);

        return ApiResponse::success($rows, 'Slider items retrieved');
    }

    public function replaceSlides(Request $request, SiteContent $content, int $sliderId): JsonResponse
    {
        $site = $this->requiredSite($request, true);
        $scope = ['slider_id' => $content->owned($site, 'sliders', $sliderId)];

        return ApiResponse::success($content->replace($site, 'slides', self::items($request), $scope), 'Slider items saved');
    }

    public function storeSlide(Request $request, SiteContent $content, int $sliderId): JsonResponse
    {
        $site = $this->requiredSite($request, true);
        $extra = ['slider_id' => $content->owned($site, 'sliders', $sliderId)];

        return ApiResponse::created($content->create($site, 'slides', $request->all(), $extra), 'Slider item created');
    }

    protected function site(Request $request, bool $create): ?Site
    {
        return $create ? $this->sites->ensureVendorSite($this->vendor($request)) : $this->sites->vendorSite();
    }

    private function vendor(Request $request): Vendor
    {
        /** @var VendorUser $user */
        $user = $request->user();

        return $user->vendor;
    }
}
