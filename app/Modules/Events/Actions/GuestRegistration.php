<?php

declare(strict_types=1);

namespace App\Modules\Events\Actions;

use App\Core\Exceptions\ApiException;
use App\Core\Support\EventQr;
use App\Core\Support\Phone;
use App\Core\Tenancy\Tenancy;
use App\Core\Tenancy\VendorScope;
use App\Modules\Catalog\Models\GuestOption;
use App\Modules\Clients\Actions\ClientSignIn;
use App\Modules\Clients\Enums\ClientSource;
use App\Modules\Clients\Events\ClientNotice;
use App\Modules\Clients\Models\Client;
use App\Modules\Events\Events\ParticipantAdded;
use App\Modules\Events\Models\Event;
use App\Modules\Events\Models\Participant;
use App\Modules\Events\Support\EventAccess;
use App\Modules\Events\Support\PlanEntitlements;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * A guest joining an event from its QR code (v1 guestRegistration.service):
 * resolve the invitation, verify the phone with a code, join (or update their
 * entry), then answer the RSVP once from the app.
 *
 * The QR is the capability: only a code that decrypts to a real event opens
 * the door. A guest's account is made in the EVENT's vendor (v1 always used
 * vendor 1).
 */
final class GuestRegistration
{
    private const PUBLIC_FIELDS = [
        'id', 'name', 'tagline', 'description', 'start_date', 'end_date', 'start_time', 'end_time', 'timezone', 'venue_name',
        'venue_address', 'host_one', 'host_two', 'organizer', 'event_category_id', 'cover_image',
    ];

    public function __construct(
        private readonly EventQr $qr,
        private readonly ClientSignIn $signIn,
        private readonly Participants $participants,
        private readonly GuestBook $book,
        private readonly Rsvps $rsvps,
        private readonly EventSettings $settings,
        private readonly EventAccess $access,
        private readonly PlanEntitlements $plans,
    ) {}

    /** @return array<string, mixed> POST /public/events/qr/resolve */
    public function resolve(mixed $token): array
    {
        $event = $this->eventFromToken($token);

        return [
            'event' => $this->publicEvent($event),
            'relationship_options' => $this->options('relationship', $event),
            'food_preference_options' => $this->options('food_preference', $event),
            'rsvp_enabled' => $this->rsvpEnabled($event),
            'participants_full' => $this->participants->status($event)['full'],
        ];
    }

    /**
     * @param  array<string, mixed>  $input  token, mobile, dial_code, name
     * @return array<string, mixed>
     */
    public function requestCode(array $input): array
    {
        $event = $this->eventFromToken($input['token'] ?? null);
        $digits = Phone::digits($input['mobile'] ?? null);
        if (! Phone::isPlausible($digits)) {
            throw ApiException::badRequest('Please enter a valid mobile number.');
        }
        if ($this->participants->status($event)['full'] && ! Participant::query()->where('event_id', $event->id)->whereIn('mobile', Phone::candidates($digits))->exists()) {
            throw ApiException::badRequest('Sorry, this event is full and cannot take more participants. Please contact the host.');
        }

        $client = Phone::whereMatches($this->signIn->clients($event->vendor_id), 'mobile', $digits)->first();
        $created = false;
        if ($client === null) {
            $client = Client::query()->create([
                'vendor_id' => $event->vendor_id,
                'source' => ClientSource::Website,
                'name' => trim(is_string($input['name'] ?? null) ? $input['name'] : '') ?: $digits,
                'dial_code' => is_string($input['dial_code'] ?? null) && $input['dial_code'] !== '' ? $input['dial_code'] : '+91',
                'mobile' => strlen($digits) > 10 ? substr($digits, -10) : $digits,
            ])->load('vendor');
            $created = true;
        } elseif (! $client->isActive()) {
            throw ApiException::forbidden('Your account is not active. Please contact us.');
        }

        return [...$this->signIn->sendCode($client, $digits, 'registration'), 'account_created' => $created];
    }

    /** POST /public/events/join/otp/verify — the account whose code this is. */
    public function verifyCode(mixed $mobile, mixed $otp): Client
    {
        $digits = Phone::digits($mobile);
        if (! Phone::isPlausible($digits)) {
            throw ApiException::badRequest('Please enter a valid mobile number.');
        }
        // The request step made or found the account in the event's vendor;
        // the pending code identifies it.
        $client = Phone::whereMatches(Client::query()->withoutGlobalScope(VendorScope::class)->with('vendor'), 'mobile', $digits)
            ->whereNotNull('otp_hash')->latest('updated_at')->first()
            ?? throw ApiException::badRequest('Please request a code first.');
        $this->signIn->spendCode($client, $otp);
        $client->forceFill(['mobile_verified' => true, 'last_login_at' => now()])->save();

        return $client;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{event: array<string, mixed>, guest: Participant, created: bool}
     */
    public function join(Client $client, array $input): array
    {
        $event = $this->eventFromToken($input['token'] ?? null);
        $digits = Phone::digits($client->mobile);
        $existing = $digits !== '' ? Participant::query()->where('event_id', $event->id)->whereIn('mobile', Phone::candidates($digits))->first() : null;

        $rsvpOn = $this->rsvpEnabled($event);
        $response = $rsvpOn && in_array($input['response_type'] ?? null, ['yes', 'no', 'maybe'], true) ? $input['response_type'] : 'none';
        $extra = $rsvpOn ? max(0, min(255, (int) ($input['guest_count'] ?? 0))) : 0;
        $sent = static fn (string $k): bool => isset($input[$k]) && trim(is_scalar($input[$k]) ? (string) $input[$k] : '') !== '';
        $take = static fn (string $k): bool => $existing === null || $sent($k);
        $text = static fn (string $k, int $max): ?string => $sent($k) ? mb_substr(trim((string) $input[$k]), 0, $max) : null;
        $writeAnswer = $existing === null || ($response !== 'none' && $existing->response_type === 'none');
        $before = $existing !== null ? $this->rsvps->snapshot($existing) : ['response_type' => 'none', 'party_size' => 1, 'dietary_preference' => null, 'accommodation' => 'unknown', 'notes' => null];

        $fields = ['client_id' => $client->id];
        if ($take('name')) {
            $fields['name'] = $text('name', 200) ?? $existing->name ?? $client->name;
        }
        if ($take('title')) {
            $fields['title'] = $text('title', 20) ?? $existing?->getAttribute('title');
        }
        if ($take('gender')) {
            $fields['gender'] = in_array($input['gender'] ?? null, ['male', 'female', 'other'], true) ? $input['gender'] : null;
        }
        if ($take('relationship')) {
            $fields['relationship'] = $text('relationship', 60);
            $fields['relationship_option_id'] = $this->optionId($input['relationship_option_id'] ?? null);
        }
        if ($take('email')) {
            $fields['email'] = $sent('email') ? mb_strtolower(trim((string) $input['email'])) : $existing?->getAttribute('email');
        }
        if ($take('food_preference')) {
            $fields['dietary_preference'] = $text('food_preference', 100);
            $fields['food_preference_option_id'] = $this->optionId($input['food_preference_option_id'] ?? null);
        }
        if ($take('special_request')) {
            $fields['special_requirements'] = $text('special_request', 500);
        }
        if ($take('photo')) {
            $fields['photo'] = $text('photo', 500) ?? $existing?->photo;
        }
        if ($take('message')) {
            $fields['notes'] = $text('message', 500);
        }
        if ($writeAnswer) {
            $fields += [
                'plus_one' => $extra > 0,
                'plus_one_count' => $extra,
                'party_size' => $extra + 1,
                'response_type' => $response,
                'rsvp_status' => ['yes' => 'accepted', 'no' => 'declined', 'maybe' => 'pending'][$response] ?? ($rsvpOn ? 'pending' : 'invited'),
                'responded_at' => $response === 'none' ? null : now(),
            ];
        }

        if ($existing !== null) {
            $existing->fill($fields)->save();
            $participant = $existing;
        } else {
            $participant = DB::transaction(function () use ($event, $fields, $client, $digits): Participant {
                $this->participants->assertCapacity($event, 1, 'Sorry, this event is full and cannot take more participants. Please contact the host.');

                return Participant::query()->create([
                    ...$fields,
                    'event_id' => $event->id,
                    'guest_id' => $this->book->byNumber($event->client_id, $digits)?->id,
                    'dial_code' => $client->dial_code ?: '+91',
                    'mobile' => strlen($digits) > 10 ? substr($digits, -10) : $digits,
                    'invite_source' => 'qr',
                    'invited_at' => now(),
                ]);
            });
        }

        $profile = [];
        if (isset($fields['name']) && in_array(trim($client->name), ['', $digits], true)) {
            $profile['name'] = $fields['name'];
        }
        if (isset($fields['photo']) && $client->avatar_url === null) {
            $profile['avatar_url'] = $fields['photo'];
        }
        if ($profile !== []) {
            $client->forceFill($profile)->save();
        }
        if ($writeAnswer && $response !== 'none') {
            $this->recordAnswer($participant, $before, $fields, $client->id, $event);
        }
        ParticipantAdded::dispatch($participant);

        return ['event' => $this->publicEvent($event), 'guest' => $participant, 'created' => $existing === null];
    }

    /** @return list<array<string, mixed>> the events this client joined */
    public function myEvents(Client $client): array
    {
        return $this->mine($client)->with(['event' => static fn ($q) => $q->withoutGlobalScope(VendorScope::class)->with('category:id,name')])
            ->whereHas('event', static fn ($q) => $q->withoutGlobalScope(VendorScope::class))
            ->latest()->get()
            ->map(fn (Participant $p): array => [
                ...$this->publicEvent($p->event),
                'guest_id' => $p->id,
                'response_type' => $p->response_type,
                'rsvp_status' => $p->rsvp_status,
                'party_size' => $p->party_size,
            ])->values()->all();
    }

    /** @return array<string, mixed> */
    public function myRsvp(Client $client, int $eventId): array
    {
        $participant = $this->mine($client)->where('event_id', $eventId)->first();
        if ($participant === null) {
            if (Event::query()->where('client_id', $client->id)->whereKey($eventId)->exists()) {
                return ['is_host' => true, 'can_respond' => false, 'rsvp_enabled' => false, 'rsvp' => null];
            }
            throw ApiException::notFound('You are not a guest of this event.');
        }
        $event = Event::query()->with(['client', 'settings', 'menuChoices'])->findOrFail($eventId);
        $settings = $this->settings->rsvp($event);
        $on = $this->rsvpEnabled($event) && $settings['enabled'];
        $closed = $this->settings->rsvpDeadlinePassed($event);
        $foods = $settings['allow_food_preference'] ? $this->options('food_preference', $event) : [];
        $default = collect($foods)->first(static fn (array $o): bool => mb_strtolower(trim((string) preg_replace('/\s+/', ' ', $o['name']))) === 'no preference');

        return [
            'is_host' => false,
            'rsvp_enabled' => $on,
            'deadline_passed' => $closed,
            'can_respond' => $on && ! $closed && $participant->response_type === 'none',
            'settings' => $settings,
            'relationship_options' => $settings['allow_relationship'] ? $this->options('relationship', $event) : [],
            'food_preference_options' => $foods,
            'default_food_preference_option_id' => $default['id'] ?? null,
            'rsvp' => [
                'guest_id' => $participant->id,
                'name' => $participant->name,
                'dial_code' => $participant->getAttribute('dial_code'),
                'mobile' => $participant->mobile,
                'email' => $participant->getAttribute('email'),
                'response_type' => $participant->response_type,
                'rsvp_status' => $participant->rsvp_status,
                'party_size' => $participant->party_size ?: 1,
                'special_requirements' => $participant->getAttribute('special_requirements'),
                'dietary_preference' => $participant->getAttribute('dietary_preference'),
                'food_preference_option_id' => $participant->getAttribute('food_preference_option_id'),
                'rsvp_side' => $participant->getAttribute('rsvp_side'),
                'relationship' => $participant->getAttribute('relationship'),
                'relationship_option_id' => $participant->getAttribute('relationship_option_id'),
                'notes' => $participant->getAttribute('notes'),
                'responded_at' => $participant->getAttribute('responded_at') !== null ? $participant->responded_at?->utc()->format('Y-m-d\TH:i:s.v\Z') : null,
            ],
        ];
    }

    /**
     * The guest answers once; a change afterwards goes through the host.
     *
     * @param  array<string, mixed>  $input
     */
    public function submitMyRsvp(Client $client, int $eventId, array $input): void
    {
        $participant = $this->mine($client)->where('event_id', $eventId)->first();
        if ($participant === null) {
            if (Event::query()->where('client_id', $client->id)->whereKey($eventId)->exists()) {
                throw ApiException::badRequest('You are hosting this event — RSVPs are for your guests.');
            }
            throw ApiException::notFound('You are not a guest of this event.');
        }
        $event = Event::query()->with(['client', 'settings', 'menuChoices'])->findOrFail($eventId);
        $settings = $this->settings->rsvp($event);
        if (! $this->rsvpEnabled($event) || ! $settings['enabled']) {
            throw ApiException::badRequest('RSVP is not enabled for this event.');
        }
        if ($this->settings->rsvpDeadlinePassed($event)) {
            throw ApiException::badRequest('The last date to respond to this event has passed.');
        }
        $response = mb_strtolower(is_scalar($input['response_type'] ?? null) ? (string) $input['response_type'] : '');
        if (! in_array($response, ['yes', 'no', 'maybe'], true)) {
            throw ApiException::badRequest('Please choose a response.');
        }
        if (! in_array($response, $settings['response_options'], true)) {
            throw ApiException::badRequest('That response is not available for this event.');
        }
        $party = 1;
        if ($response !== 'no' && $settings['allow_guest_count']) {
            $party = filter_var($input['party_size'] ?? 1, FILTER_VALIDATE_INT);
            if ($party === false || $party < 1 || $party > 50) {
                throw ApiException::badRequest('Number of guests must be between 1 and 50.');
            }
        }

        $before = $this->rsvps->snapshot($participant);
        $values = [
            'client_id' => $client->id, 'response_type' => $response, 'rsvp_status' => ['yes' => 'accepted', 'no' => 'declined', 'maybe' => 'pending'][$response],
            'responded_at' => now(), 'party_size' => $party, 'plus_one' => $party > 1, 'plus_one_count' => $party - 1,
        ];
        if (array_key_exists('special_requirements', $input) && $settings['allow_special_requests']) {
            $values['special_requirements'] = is_scalar($input['special_requirements']) && (string) $input['special_requirements'] !== '' ? mb_substr((string) $input['special_requirements'], 0, 500) : null;
        }
        if (array_key_exists('notes', $input)) {
            $values['notes'] = is_scalar($input['notes']) && (string) $input['notes'] !== '' ? mb_substr((string) $input['notes'], 0, 500) : null;
        }
        if ($settings['allow_food_preference'] && ! in_array($input['food_preference_option_id'] ?? null, [null, ''], true)) {
            $option = collect($this->options('food_preference', $event))->firstWhere('id', (int) $input['food_preference_option_id'])
                ?? throw ApiException::badRequest('That food preference is not available for this event.');
            $values['food_preference_option_id'] = $option['id'];
            $values['dietary_preference'] = mb_substr($option['name'], 0, 100);
        }
        if ($settings['allow_relationship']) {
            if (! in_array($input['rsvp_side'] ?? null, [null, ''], true)) {
                $side = mb_strtolower((string) $input['rsvp_side']);
                $values['rsvp_side'] = in_array($side, ['groom', 'bride'], true) ? $side : throw ApiException::badRequest('Please choose Groom or Bride.');
            }
            if (! in_array($input['relationship_option_id'] ?? null, [null, ''], true)) {
                $option = collect($this->options('relationship', $event))->firstWhere('id', (int) $input['relationship_option_id'])
                    ?? throw ApiException::badRequest('That relationship is not available for this event.');
                $values['relationship_option_id'] = $option['id'];
                $values['relationship'] = mb_substr($option['name'], 0, 60);
            }
        }

        // Only an unanswered row may be answered here (no double submit).
        if (Participant::query()->whereKey($participant->id)->where('response_type', 'none')->update($values) !== 1) {
            throw ApiException::badRequest('You have already responded to this event. To change your answer, please contact the host.');
        }
        $this->recordAnswer($participant->refresh(), $before, $values, $client->id, $event);
    }

    /** @return list<array<string, mixed>> who else joined (no contact details) */
    public function directory(Client $client, int $eventId): array
    {
        $event = Event::query()->with('client')->find($eventId) ?? throw ApiException::notFound('Event not found.');
        if (! $this->access->canManage($client, $event) && ! $this->access->isParticipant($client, $eventId)) {
            throw ApiException::notFound('You are not a guest of this event.');
        }

        return Participant::query()->where('event_id', $eventId)->whereNotNull('client_id')->orderBy('name')
            ->get(['id', 'name', 'title', 'photo', 'relationship', 'rsvp_status'])
            ->map(static fn (Participant $p): array => [
                'id' => $p->id, 'full_name' => $p->name, 'title' => $p->getAttribute('title'), 'photo' => $p->photo,
                'relationship' => $p->getAttribute('relationship'), 'rsvp_status' => $p->rsvp_status, 'has_joined' => true,
            ])->all();
    }

    /** @return array<string, mixed> what a guest may see of an event */
    public function publicEvent(Event $event): array
    {
        $event->loadMissing('category');

        return [
            ...$event->only(self::PUBLIC_FIELDS),
            'start_date' => $event->start_date?->format('Y-m-d'),
            'end_date' => $event->end_date?->format('Y-m-d'),
            'category_name' => $event->category?->name,
            'derived_status' => $event->derivedStatus(),
            'updated_at' => $event->updated_at?->utc()->format('Y-m-d\TH:i:s.v\Z'),
        ];
    }

    /**
     * The event's RSVP menu is switched on and the owner's plan still grants it.
     */
    public function rsvpEnabled(Event $event): bool
    {
        $event->loadMissing(['client', 'menuChoices']);
        [$plan, $reason] = $this->plans->planOf($event->client);
        if ($plan === null || $reason !== null) {
            return false;
        }
        $selected = $event->menuChoices->where('selected', true)->pluck('event_menu_id')->map(static fn ($id): int => (int) $id)->all();

        return collect($this->plans->grantedMenus($plan))
            ->contains(static fn (array $m): bool => in_array((int) $m['id'], $selected, true) && preg_match('/^rsvp(-\d+)?$/', (string) $m['slug']) === 1);
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $values
     */
    private function recordAnswer(Participant $p, array $before, array $values, int $by, Event $event): void
    {
        $this->rsvps->log($p, $before, $values, 'guest', $by);
        $after = $values['response_type'] ?? null;
        if (in_array($after, ['yes', 'no', 'maybe'], true) && $after !== $before['response_type']) {
            $word = ['yes' => 'accepted', 'no' => 'declined', 'maybe' => 'replied maybe to'][$after];
            ClientNotice::dispatch($event->client_id, 'rsvp_'.['yes' => 'accepted', 'no' => 'declined', 'maybe' => 'maybe'][$after], 'New RSVP', "{$p->name} {$word} your invitation.", "/dashboard/rsvps/{$p->id}", $event->id, $p->id, ['response' => $after, 'source' => 'guest']);
        }
    }

    /** @return Builder<Participant> this client's own participant rows */
    private function mine(Client $client): Builder
    {
        $numbers = array_values(array_filter(Phone::candidates($client->mobile), static fn (string $d): bool => strlen($d) >= 7));

        return Participant::query()->where(static function ($q) use ($client, $numbers): void {
            $q->where('client_id', $client->id);
            if ($numbers !== []) {
                $q->orWhereIn('mobile', $numbers);
            }
        });
    }

    private function eventFromToken(mixed $token): Event
    {
        if (! is_string($token) || $token === '') {
            throw ApiException::badRequest('No QR token supplied.');
        }
        $payload = $this->qr->decrypt($token);
        $id = $payload !== null ? EventQr::expand($payload)['event_id'] : null;
        if (! is_numeric($id)) {
            throw ApiException::badRequest('This QR code is not valid.');
        }

        return Tenancy::bypass(static fn () => Event::query()->with(['client', 'category:id,name', 'settings', 'menuChoices'])->find((int) $id))
            ?? throw ApiException::notFound('That event no longer exists.');
    }

    /** @return list<array{id: int, name: string}> */
    private function options(string $kind, Event $event): array
    {
        $company = $event->client->tenantCompanyId();
        $query = static fn (?int $category) => GuestOption::query()->withoutGlobalScopes()->where('company_id', $company)->where('kind', $kind)
            ->where('is_active', 1)->where('event_category_id', $category)->orderBy('sort_order')->orderBy('name')->get(['id', 'name'])
            ->map(static fn (GuestOption $o): array => ['id' => $o->id, 'name' => $o->name])->all();
        $scoped = $event->event_category_id !== null ? $query($event->event_category_id) : [];

        return $scoped !== [] ? $scoped : $query(null);
    }

    private function optionId(mixed $value): ?int
    {
        return is_numeric($value) && (int) $value > 0 && GuestOption::query()->withoutGlobalScopes()->whereKey((int) $value)->exists() ? (int) $value : null;
    }
}
