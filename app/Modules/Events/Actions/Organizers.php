<?php

declare(strict_types=1);

namespace App\Modules\Events\Actions;

use App\Core\Support\Phone;
use App\Core\Tenancy\Tenancy;
use App\Core\Tenancy\VendorScope;
use App\Modules\Clients\Enums\ClientSource;
use App\Modules\Clients\Models\Client;
use App\Modules\Events\Models\Event;
use App\Modules\Events\Models\Participant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Event organizers and phone sign-in (v1 eventOrganizer.service and the
 * participant parts of websiteClient.findClientByMobile / verifyLoginOtp).
 *
 * The host types the organizer's number on the event (contact_phone). The
 * client account with that number (same vendor) becomes the organizer and may
 * manage the event. Someone who signs in with an organizer's or a guest's
 * number for the first time gets an account made from the event's details.
 *
 * Sign-in runs before a tenant is known, so these queries name the vendor.
 */
final class Organizers
{
    /** Point the event at the account behind its contact number (or at nobody). */
    public function syncEvent(Event $event): void
    {
        $national = $this->national($event->contact_phone);
        $organizer = null;
        if ($national !== null) {
            $account = Client::query()->withoutGlobalScope(VendorScope::class)->where('vendor_id', $event->vendor_id)
                ->where('mobile', 'like', '%'.$national)->where('id', '!=', $event->client_id)->value('id');
            $organizer = $account !== null ? (int) $account : null;
        }
        $event->forceFill(['organizer_client_id' => $organizer])->save();
    }

    /**
     * Link this client to every event of their vendor that names their number.
     *
     * @return int how many events they organise
     */
    public function claimFor(Client $client): int
    {
        $events = $this->eventsForNumber($this->national($client->mobile), $client->vendor_id)
            ->filter(static fn (Event $e): bool => $e->client_id !== $client->id);
        $unlinked = $events->filter(static fn (Event $e): bool => $e->organizer_client_id !== $client->id)->pluck('id');
        if ($unlinked->isNotEmpty()) {
            Event::query()->withoutGlobalScope(VendorScope::class)->whereIn('id', $unlinked)->update(['organizer_client_id' => $client->id]);
        }

        return $events->count();
    }

    /** Does this client run any event (own or organised)? */
    public function organizes(Client $client): bool
    {
        return $this->claimFor($client) > 0
            || Event::query()->withoutGlobalScope(VendorScope::class)->where('client_id', $client->id)->exists();
    }

    /**
     * No account has this number: a guest's number gets one from their newest
     * participation (not for an organizer sign-in), an organizer's from the
     * event that names it.
     *
     * @param  list<string>  $numbers
     */
    public function accountFor(array $numbers, int $vendorId, bool $asOrganizer): ?Client
    {
        if (! $asOrganizer) {
            $participant = $this->participants($numbers, $vendorId)->latest('id')->first();
            if ($participant !== null) {
                $client = Client::query()->create([
                    'vendor_id' => $vendorId,
                    'source' => ClientSource::Website,
                    'name' => $participant->name ?: 'Participant',
                    'dial_code' => $participant->getAttribute('dial_code') ?: '+91',
                    'mobile' => substr(Phone::digits($numbers[0] ?? ''), -10),
                    'avatar_url' => $participant->photo,
                ]);
                $this->participants($numbers, $vendorId)->whereNull('client_id')->update(['client_id' => $client->id]);

                return $client->load('vendor');
            }
        }

        $event = $this->eventsForNumber($this->national($numbers[0] ?? null), $vendorId)->first();
        if ($event === null) {
            return null;
        }

        return Client::query()->create([
            'vendor_id' => $vendorId,
            'source' => ClientSource::Website,
            'name' => trim((string) $event->organizer) ?: 'Organizer',
            'dial_code' => $this->dial($event->contact_phone),
            'mobile' => $this->national($numbers[0] ?? null),
            'avatar_url' => $event->organizer_image,
        ])->load('vendor');
    }

    /** After a verified phone sign-in: link their guest rows, fill a bare profile, claim organised events. */
    public function signedIn(Client $client): void
    {
        $numbers = array_values(array_filter(Phone::candidates($client->mobile), static fn (string $d): bool => strlen($d) >= 7));
        if ($numbers !== []) {
            $this->participants($numbers, $client->vendor_id)->whereNull('client_id')->update(['client_id' => $client->id]);
            $latest = $this->participants($numbers, $client->vendor_id)->latest('id')->first();
            if ($latest !== null) {
                $changes = [];
                if ($latest->photo !== null && $client->avatar_url === null) {
                    $changes['avatar_url'] = $latest->photo;
                }
                if ($latest->name !== '' && in_array($client->name, ['', 'Participant', $client->mobile], true)) {
                    $changes['name'] = $latest->name;
                }
                if ($changes !== []) {
                    $client->forceFill($changes)->save();
                }
            }
        }
        $this->claimFor($client);
    }

    /**
     * Participants of this vendor's events with one of these numbers.
     *
     * @param  list<string>  $numbers
     * @return Builder<Participant>
     */
    private function participants(array $numbers, int $vendorId): Builder
    {
        return Participant::query()->whereIn('mobile', $numbers)
            ->whereHas('event', static fn ($q) => $q->withoutGlobalScope(VendorScope::class)->where('vendor_id', $vendorId));
    }

    /** @return Collection<int, Event> newest first */
    private function eventsForNumber(?string $national, int $vendorId): Collection
    {
        if ($national === null) {
            return collect();
        }

        return Tenancy::bypass(static fn () => Event::query()->where('vendor_id', $vendorId)
            ->whereRaw("replace(replace(contact_phone, ' ', ''), '-', '') like ?", ['%'.$national])
            ->orderByDesc('id')->get(['id', 'client_id', 'organizer', 'organizer_image', 'contact_phone', 'organizer_client_id']));
    }

    private function national(?string $raw): ?string
    {
        $digits = Phone::digits($raw);

        return strlen($digits) >= 7 ? substr($digits, -10) : null;
    }

    private function dial(?string $raw): string
    {
        $digits = Phone::digits($raw);

        return strlen($digits) > 10 ? '+'.substr($digits, 0, -10) : '+91';
    }
}
