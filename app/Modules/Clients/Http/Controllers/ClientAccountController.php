<?php

declare(strict_types=1);

namespace App\Modules\Clients\Http\Controllers;

use App\Core\Auth\SessionTokens;
use App\Core\Http\ApiResponse;
use App\Modules\Clients\Actions\ClientDevices;
use App\Modules\Clients\Actions\ClientSessions;
use App\Modules\Clients\Actions\ClientSettings;
use App\Modules\Clients\Actions\ClientSignIn;
use App\Modules\Clients\Actions\ClientWriter;
use App\Modules\Clients\Http\Resources\V1\ClientResource;
use App\Modules\Clients\Models\Client;
use App\Modules\Clients\Models\ClientSession;
use App\Modules\Platform\Media\MediaProxy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * /api/v1/client/* — the signed-in client's own account: profile, password,
 * photo, closing the account, settings, push devices, favourite templates.
 */
final class ClientAccountController
{
    public function __construct(private readonly ClientWriter $writer) {}

    public function me(Request $request): JsonResponse
    {
        return ApiResponse::success(['client' => $this->present($this->client($request))], 'Client profile retrieved');
    }

    public function update(Request $request): JsonResponse
    {
        $client = $this->writer->updateOwnProfile($this->client($request), $request->all());

        return ApiResponse::success(['client' => $this->present($client)], 'Profile updated');
    }

    public function changePassword(Request $request, ClientSignIn $signIn, ClientSessions $sessions): JsonResponse
    {
        $client = $this->client($request);
        $signIn->changePassword($client, $request->input('current_password'), $request->input('new_password'), 'website');
        $revoked = $sessions->endOthers($client, $this->session($request), 'password_change');

        $message = $revoked > 0
            ? 'Password updated. You have been signed out on '.$revoked.' other '.($revoked === 1 ? 'device' : 'devices').'.'
            : 'Password updated';

        return ApiResponse::success(['signed_out_sessions' => $revoked], $message);
    }

    public function destroy(Request $request, SessionTokens $tokens): JsonResponse
    {
        $this->writer->closeAccount($this->client($request), $request->input('password'), $request->input('confirm_email'));

        return $tokens->forget(ApiResponse::success(null, 'Your account has been closed'), ClientAuthController::ACCESS_COOKIE, ClientAuthController::REFRESH_COOKIE);
    }

    public function updateAvatar(Request $request): JsonResponse
    {
        $file = $request->file('file');
        $client = $this->writer->updateAvatar($this->client($request), is_array($file) ? null : $file);

        return ApiResponse::success(['client' => $this->present($client)], 'Photo updated');
    }

    public function removeAvatar(Request $request): JsonResponse
    {
        return ApiResponse::success(['client' => $this->present($this->writer->removeAvatar($this->client($request)))], 'Photo removed');
    }

    public function favouriteTemplates(Request $request): JsonResponse
    {
        $codes = $this->writer->setFavouriteTemplates($this->client($request), $request->input('template_ids'));

        return ApiResponse::success(['favourite_templates' => $codes], 'Favourites updated');
    }

    public function mediaProxy(Request $request, MediaProxy $proxy): JsonResponse
    {
        return ApiResponse::success($proxy->read((string) $request->query('url', '')));
    }

    // ---- settings ---------------------------------------------------------------

    public function settings(Request $request, ClientSettings $settings): JsonResponse
    {
        return ApiResponse::success($settings->get($this->client($request)), 'Settings retrieved');
    }

    public function updatePreferences(Request $request, ClientSettings $settings): JsonResponse
    {
        return ApiResponse::success($settings->updatePreferences($this->client($request), $request->all()), 'Preferences saved');
    }

    public function updateNotifications(Request $request, ClientSettings $settings): JsonResponse
    {
        return ApiResponse::success($settings->updateNotifications($this->client($request), $request->all()), 'Notification settings saved');
    }

    // ---- push devices -------------------------------------------------------------

    public function devices(Request $request, ClientDevices $devices): JsonResponse
    {
        $list = $devices->list($this->client($request));

        return ApiResponse::success(['devices' => $list, 'push_enabled' => collect($list)->contains('is_active', true)], 'Devices retrieved');
    }

    public function registerDevice(Request $request, ClientDevices $devices): JsonResponse
    {
        $devices->register($this->client($request), $request->all());

        return ApiResponse::success(['registered' => true], 'Device registered');
    }

    public function releaseDevice(Request $request, ClientDevices $devices): JsonResponse
    {
        $devices->release($this->client($request), $request->input('token'));

        return ApiResponse::success(['released' => true], 'Device released');
    }

    private function present(Client $client): ClientResource
    {
        return new ClientResource($client->loadMissing(['vendor', 'favourites', 'plan']));
    }

    private function session(Request $request): ?ClientSession
    {
        $session = $request->attributes->get('client_session');

        return $session instanceof ClientSession ? $session : null;
    }

    private function client(Request $request): Client
    {
        /** @var Client */
        return $request->user();
    }
}
