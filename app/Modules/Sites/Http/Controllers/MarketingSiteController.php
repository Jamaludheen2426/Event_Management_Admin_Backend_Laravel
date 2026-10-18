<?php

declare(strict_types=1);

namespace App\Modules\Sites\Http\Controllers;

use App\Core\Http\ApiResponse;
use App\Core\Support\Flag;
use App\Modules\Sites\Actions\SiteContent;
use App\Modules\Sites\Actions\Sites;
use App\Modules\Sites\Http\Concerns\ServesSections;
use App\Modules\Sites\Models\Site;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * /api/v1/website-builder/* — the admin panel's Website Builder: the
 * marketing site of the house vendor.
 *
 * Content reads need no sign-in (v1): the admin app's own preview pages read
 * them. Everything that changes the site needs website_builder.edit; visitor
 * messages and the translation screens need website_builder.view (v1 let
 * anyone read the messages and any admin write anything).
 */
final class MarketingSiteController
{
    use ServesSections;

    public function __construct(private readonly Sites $sites) {}

    public function highlights(Request $request, SiteContent $content): JsonResponse
    {
        $page = (string) $request->query('page_slug', 'home');
        $data = $content->highlights($this->requiredSite($request), $page !== '' ? $page : 'home', Flag::clamp($request->query('instance'), 1, 1000, 1));

        return ApiResponse::success($data, $data === null ? 'No highlights settings found' : 'Highlights settings retrieved');
    }

    public function saveHighlights(Request $request, SiteContent $content): JsonResponse
    {
        return ApiResponse::success($content->saveHighlights($this->requiredSite($request), $request->all()), 'Highlights settings saved successfully');
    }

    protected function site(Request $request, bool $create): Site
    {
        return $this->sites->marketing();
    }
}
