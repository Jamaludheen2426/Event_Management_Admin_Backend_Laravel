<?php

declare(strict_types=1);

namespace App\Modules\Clients\Http\Controllers;

use App\Core\Exceptions\ApiException;
use App\Core\Http\ApiResponse;
use App\Modules\Clients\Actions\ClientSignIn;
use App\Modules\Clients\Actions\NewsletterSubscribers;
use App\Modules\Clients\Http\Resources\V1\VendorClientResource;
use App\Modules\Vendors\Models\Vendor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * /api/v1/public/vendors/{slug}/... — sign-up, sign-in and the newsletter form
 * on a vendor's public site. Sign-in answers a one-minute handoff token that
 * the vendor-client portal exchanges for its own session (no cookies here: the
 * public routes allow any origin, without credentials).
 */
final class VendorSiteClientController
{
    public function __construct(private readonly ClientSignIn $signIn) {}

    public function register(Request $request, NewsletterSubscribers $subscribers, string $slug): JsonResponse
    {
        $vendor = $this->vendor($slug);
        $client = $this->signIn->registerAtVendorSite($vendor, $request->all());
        if ($client->newsletter_opt_in) {
            $subscribers->addToList($vendor->id, (string) $client->email);
        }

        return ApiResponse::success(new VendorClientResource($client), 'Registration completed successfully', 201);
    }

    public function login(Request $request, string $slug): JsonResponse
    {
        $token = $this->signIn->vendorSiteLogin($this->vendor($slug), $request->input('email'), $request->input('password'));

        return ApiResponse::success(['handoff_token' => $token], 'Login successful');
    }

    public function subscribe(Request $request, NewsletterSubscribers $subscribers, string $slug): JsonResponse
    {
        ['client' => $client, 'created' => $created] = $subscribers->subscribeFromSite($this->vendor($slug), $request->all());

        return ApiResponse::success(new VendorClientResource($client), 'Newsletter subscribed successfully', $created ? 201 : 200);
    }

    private function vendor(string $slug): Vendor
    {
        return Vendor::findActiveBySlug($slug) ?? throw ApiException::notFound('Vendor not found');
    }
}
