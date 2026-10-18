<?php

declare(strict_types=1);

namespace App\Modules\Events\Actions;

use App\Core\Contracts\ObjectStorage;
use App\Core\Exceptions\ApiException;
use App\Modules\Clients\Models\Client;
use App\Modules\Events\Models\Event;
use App\Modules\Events\Models\SplashScreen;
use App\Modules\Events\Support\EventAccess;
use App\Modules\Templates\Models\Decoration;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;

/**
 * The mobile app's splash screens (v1 clientSplashScreen.service): one per
 * event, played when a guest opens the event. Organizer details (QR, contact)
 * never appear on a splash.
 */
final class SplashScreens
{
    private const BACKGROUNDS = ['image', 'video', 'solid_color', 'gradient', 'logo', 'couple_photo'];

    private const ORGANIZER_KEYS = ['event_qr_code', 'organizer', 'contact_details'];

    private const HOST_ONLY = ['client_id', 'name', 'deleted_at'];

    private const MEDIA_MIMES = ['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'image/svg+xml', 'video/mp4', 'video/webm', 'audio/mpeg', 'audio/wav', 'audio/ogg'];

    public function __construct(
        private readonly EventAccess $access,
        private readonly ObjectStorage $storage,
        private readonly EventReader $events,
    ) {}

    /** @return Builder<SplashScreen> */
    public function query(Client $client): Builder
    {
        return SplashScreen::query()->where('client_id', $client->id);
    }

    public function find(Client $client, int $id): SplashScreen
    {
        return $this->query($client)->find($id) ?? throw ApiException::notFound('Splash screen not found.');
    }

    /** @param array<string, mixed> $input */
    public function create(Client $client, array $input): SplashScreen
    {
        return SplashScreen::query()->create([...$this->normalise($client, $input, null), 'client_id' => $client->id]);
    }

    /** @param array<string, mixed> $input */
    public function update(Client $client, SplashScreen $splash, array $input): SplashScreen
    {
        $splash->fill($this->normalise($client, $input, $splash))->save();

        return $splash;
    }

    public function delete(SplashScreen $splash): void
    {
        $splash->forceFill(['event_id' => null])->save();
        $splash->delete();
    }

    /** @return array<string, mixed>|null the active splash of an event, for its host or a guest */
    public function forEvent(Client $actor, int $eventId): ?array
    {
        $event = Event::query()->with('client')->find($eventId);
        if ($event === null || (! $this->access->canManage($actor, $event) && ! $this->access->isParticipant($actor, $eventId))) {
            return null;
        }
        $splash = SplashScreen::query()->where('event_id', $event->id)->where('client_id', $event->client_id)->where('status', 'active')->first();
        if ($splash === null) {
            return null;
        }
        $data = $splash->toArray();
        foreach (self::HOST_ONLY as $field) {
            unset($data[$field]);
        }
        $data['components'] = $this->withoutOrganizer($splash->components);
        if (is_array($data['background_config'] ?? null) && is_array($data['background_config']['components'] ?? null)) {
            $data['background_config']['components'] = $this->withoutOrganizer($data['background_config']['components']);
        }
        $data['design'] = $this->events->designFor($splash->theme_id, $event->client->tenantCompanyId());

        return $data;
    }

    /** @return array{decorations: list<array<string, mixed>>, frames: list<mixed>, all: list<array<string, mixed>>} */
    public function decorations(Client $client): array
    {
        $rows = Decoration::query()->withoutGlobalScopes()->where('company_id', $client->tenantCompanyId())->where('is_active', 1)
            ->orderBy('sort_order')->orderBy('id')->get(['id', 'name', 'type', 'file_url', 'file_format'])->toArray();

        return ['decorations' => $rows, 'frames' => [], 'all' => $rows];
    }

    /** @return array{url: string} */
    public function upload(?UploadedFile $file): array
    {
        if ($file === null) {
            throw ApiException::badRequest('Please choose a file to upload.');
        }
        if (! in_array($file->getMimeType(), self::MEDIA_MIMES, true)) {
            throw ApiException::badRequest('Please choose an image, video (MP4/WebM) or audio (MP3/WAV/OGG) file.');
        }
        if ($file->getSize() > 20 * 1024 * 1024) {
            throw ApiException::badRequest('That file is larger than 20MB.');
        }

        return ['url' => $this->storage->upload($file, 'splash-screens')['url']];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function normalise(Client $client, array $input, ?SplashScreen $existing): array
    {
        $has = static fn (string $f): bool => array_key_exists($f, $input);
        $required = static fn (string $f): bool => $existing === null || array_key_exists($f, $input);
        $str = static fn (mixed $v, int $max): ?string => is_scalar($v) && trim((string) $v) !== '' ? mb_substr(trim((string) $v), 0, $max) : null;
        $v = [];

        if ($required('event_id')) {
            $event = is_numeric($input['event_id'] ?? null) ? Event::query()->with('client')->find((int) $input['event_id']) : null;
            if (($input['event_id'] ?? null) === null || $input['event_id'] === '') {
                throw ApiException::badRequest('Please choose an event.');
            }
            if ($event === null || ! $this->access->canManage($client, $event)) {
                throw ApiException::badRequest('That event is not on your account.');
            }
            $clash = SplashScreen::query()->where('event_id', $event->id)->when($existing !== null, static fn ($q) => $q->whereKeyNot($existing?->id))->first(['id', 'name']);
            if ($clash !== null) {
                throw ApiException::badRequest("That event already has a splash screen (\"{$clash->name}\"). Edit that one, or pick another event.");
            }
            $v['event_id'] = $event->id;
            $v['event_name'] = mb_substr($event->name, 0, 100) ?: 'Untitled event';
        }
        if ($required('name')) {
            $v['name'] = $str($input['name'] ?? null, 150) ?? throw ApiException::badRequest('Please give this splash screen a name.');
        }
        if ($required('main_title')) {
            $v['main_title'] = $str($input['main_title'] ?? null, 60) ?? throw ApiException::badRequest('Please enter a main title.');
        }
        foreach (['sub_title' => 20, 'tagline' => 150, 'background_url' => 500, 'theme_id' => 50, 'decoration_url' => 500] as $field => $max) {
            if ($has($field)) {
                $v[$field] = $str($input[$field], $max);
            }
        }
        if ($required('background_type')) {
            $raw = mb_strtolower(is_scalar($input['background_type'] ?? null) ? (string) $input['background_type'] : '');
            $type = ['color' => 'solid_color', 'custom' => 'image'][$raw] ?? $raw;
            $v['background_type'] = in_array($type, self::BACKGROUNDS, true) ? $type : throw ApiException::badRequest('Invalid background type.');
        }
        if ($has('background_config')) {
            $config = $this->config($input['background_config']);
            if (is_array($config['components'] ?? null)) {
                $config['components'] = $this->withoutOrganizer($config['components']);
            }
            $v['background_config'] = $config;
        }
        if ($has('loader_enabled')) {
            $v['loader_enabled'] = (bool) $input['loader_enabled'];
        }
        if ($has('loader_config')) {
            $v['loader_config'] = $this->config($input['loader_config']);
        }
        foreach (['primary_color' => 'Primary colour must be a hex value like #E91E63.', 'secondary_color' => 'Secondary colour must be a hex value like #E91E63.', 'bg_color' => 'Background colour must be a hex value like #FFFFFF.'] as $field => $message) {
            if ($has($field)) {
                $v[$field] = $str($input[$field], 9);
                if ($v[$field] !== null && preg_match('/^#([0-9a-fA-F]{6}|[0-9a-fA-F]{8})$/', $v[$field]) !== 1) {
                    throw ApiException::badRequest($message);
                }
            }
        }
        if ($has('show_decoration')) {
            $v['show_decoration'] = (bool) $input['show_decoration'];
        }
        if ($has('components')) {
            $v['components'] = $this->withoutOrganizer($this->config($input['components']));
        }
        if ($has('component_order') && ($input['component_order'] === null || is_array($input['component_order']))) {
            $v['component_order'] = $input['component_order'];
        }
        if ($has('status')) {
            $status = mb_strtolower(is_scalar($input['status']) ? (string) $input['status'] : '');
            $v['status'] = in_array($status, ['draft', 'active'], true) ? $status : throw ApiException::badRequest('Invalid status.');
        }

        return $v;
    }

    /** @return array<string, mixed>|null */
    private function config(mixed $value): ?array
    {
        if ($value === null) {
            return null;
        }
        if (! is_array($value) || ($value !== [] && array_is_list($value))) {
            throw ApiException::badRequest('Configuration must be an object.');
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>|null  $components
     * @return array<string, mixed>|null
     */
    private function withoutOrganizer(?array $components): ?array
    {
        if ($components === null) {
            return null;
        }
        foreach (self::ORGANIZER_KEYS as $key) {
            $components[$key] = false;
        }

        return $components;
    }
}
