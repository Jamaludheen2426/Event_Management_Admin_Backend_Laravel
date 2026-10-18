<?php

declare(strict_types=1);

namespace App\Modules\Events\Http\Controllers;

use App\Core\Auth\SessionTokens;
use App\Core\Http\ApiResponse;
use App\Modules\Clients\Actions\ClientSessions;
use App\Modules\Clients\Http\Controllers\ClientAuthController;
use App\Modules\Clients\Http\Resources\V1\ClientResource;
use App\Modules\Clients\Models\Client;
use App\Modules\Events\Actions\GuestRegistration;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Joining an event from its QR code (v1 guestRegistration.controller):
 * /api/v1/public/events/qr/resolve and join/otp/*, then, signed in,
 * /client/events/join, joined, {id}/my-rsvp and {id}/participants.
 */
final class GuestJoinController
{
    public function __construct(private readonly GuestRegistration $registration) {}

    public function resolve(Request $request): JsonResponse
    {
        return ApiResponse::success($this->registration->resolve($request->input('token') ?? $request->input('qr_token')), 'Invitation found');
    }

    public function requestCode(Request $request): JsonResponse
    {
        return ApiResponse::success($this->registration->requestCode($request->all()), 'Verification code sent.');
    }

    public function verifyCode(Request $request, ClientSessions $sessions, SessionTokens $tokens): JsonResponse
    {
        $client = $this->registration->verifyCode($request->input('mobile'), $request->input('otp'));
        $issued = $sessions->start($client, $request);
        $response = ApiResponse::success([
            'client' => new ClientResource($client->loadMissing(['vendor', 'favourites'])),
            'access_token' => $issued['access'],
            'refresh_token' => $issued['refresh'],
            'token_type' => 'Bearer',
            'expires_in' => SessionTokens::ACCESS_MINUTES * 60,
        ], 'Mobile number verified');

        return $tokens->attach($response, ClientAuthController::ACCESS_COOKIE, $issued['access'], ClientAuthController::REFRESH_COOKIE, $issued['refresh']);
    }

    public function join(Request $request): JsonResponse
    {
        $result = $this->registration->join($this->client($request), $request->all());

        return ApiResponse::success($result, $result['created'] ? 'You have joined the event' : 'Your details have been updated', $result['created'] ? 201 : 200);
    }

    public function joined(Request $request): JsonResponse
    {
        return ApiResponse::success(['events' => $this->registration->myEvents($this->client($request))]);
    }

    public function myRsvp(Request $request, int $id): JsonResponse
    {
        return ApiResponse::success($this->registration->myRsvp($this->client($request), $id), 'RSVP retrieved');
    }

    public function submitMyRsvp(Request $request, int $id): JsonResponse
    {
        $client = $this->client($request);
        $this->registration->submitMyRsvp($client, $id, $request->all());

        return ApiResponse::success($this->registration->myRsvp($client, $id), 'Your RSVP has been submitted');
    }

    public function directory(Request $request, int $id): JsonResponse
    {
        return ApiResponse::success($this->registration->directory($this->client($request), $id), 'Participants retrieved');
    }

    private function client(Request $request): Client
    {
        /** @var Client */
        return $request->user();
    }
}
