<?php

declare(strict_types=1);

namespace App\Modules\Events;

use App\Core\Support\ModuleServiceProvider;
use App\Modules\Billing\Support\UsageMeters;
use App\Modules\Clients\Models\Client;
use App\Modules\Clients\Support\MobileSignInHooks;
use App\Modules\Events\Actions\Organizers;
use App\Modules\Events\Models\Event;
use App\Modules\Events\Models\Guest;
use App\Modules\Events\Models\Participant;
use App\Modules\Events\Support\MediaQuota;
use App\Modules\Events\Support\MessageLog;
use Carbon\CarbonInterface;

/**
 * Events: a client's events and everything on them — QR codes, the phone book
 * of guests, participants and RSVPs, agenda, family, gallery, wishes, splash
 * screens.
 */
final class EventsServiceProvider extends ModuleServiceProvider
{
    public function register(): void
    {
        // Messaging registers its reader once at boot; every caller must see it.
        $this->app->singleton(MessageLog::class);
    }

    protected function bootModule(): void
    {
        // Phone sign-in: guests' and organizers' numbers get accounts, and
        // a verified sign-in links the person to their events.
        $hooks = $this->app->make(MobileSignInHooks::class);
        $hooks->accountFor(fn (array $numbers, int $vendorId, bool $asOrganizer): ?Client => $this->app->make(Organizers::class)->accountFor($numbers, $vendorId, $asOrganizer));
        $hooks->isOrganizer(fn (Client $client): bool => $this->app->make(Organizers::class)->organizes($client));
        $hooks->signedIn(fn (Client $client) => $this->app->make(Organizers::class)->signedIn($client));

        // Billing's usage panel: events created in the term, guests and heads.
        $this->app->make(UsageMeters::class)->register(static function (Client $client, ?CarbonInterface $from, ?CarbonInterface $to): array {
            $events = Event::query()->withoutGlobalScopes()->withTrashed()->where('client_id', $client->id)
                ->when($from !== null, static fn ($q) => $q->where('created_at', '>=', $from))
                ->when($to !== null, static fn ($q) => $q->where('created_at', '<', $to))
                ->count();
            $heads = (int) Participant::query()->whereHas('event', static fn ($q) => $q->withoutGlobalScopes()->where('client_id', $client->id))->sum('party_size');

            return [
                'events' => $events,
                'heads' => $heads,
                'guests' => Guest::query()->where('client_id', $client->id)->count(),
                'storage_bytes' => app(MediaQuota::class)->storageUsed($client->id),
            ];
        });
    }
}
