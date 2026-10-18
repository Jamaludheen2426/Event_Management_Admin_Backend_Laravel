<?php

declare(strict_types=1);

namespace App\Modules\Events\Support;

use Closure;

/**
 * Messages sent to participants (invitations, reminders) belong to the
 * Messaging module. RSVP details, guest profiles and analytics show them
 * through this; until Messaging registers a reader there are none — true, as
 * nothing has been sent from the new system.
 *
 * A message row: id, participant_id, event_id, channel, kind, status, sender,
 * sent_at, delivered_at, opened_at, clicked_at, created_at.
 */
final class MessageLog
{
    /** @var (Closure(list<int>): list<array<string, mixed>>)|null */
    private ?Closure $forParticipants = null;

    /** @var (Closure(int): list<array<string, mixed>>)|null */
    private ?Closure $forClient = null;

    /**
     * @param  Closure(list<int>): list<array<string, mixed>>  $forParticipants  participant ids → their messages
     * @param  Closure(int): list<array<string, mixed>>  $forClient  host client id → every message of their events
     */
    public function readUsing(Closure $forParticipants, Closure $forClient): void
    {
        $this->forParticipants = $forParticipants;
        $this->forClient = $forClient;
    }

    /**
     * @param  list<int>  $participantIds
     * @return list<array<string, mixed>>
     */
    public function forParticipants(array $participantIds): array
    {
        return $this->forParticipants !== null && $participantIds !== [] ? ($this->forParticipants)($participantIds) : [];
    }

    /** @return list<array<string, mixed>> */
    public function forClient(int $clientId): array
    {
        return $this->forClient !== null ? ($this->forClient)($clientId) : [];
    }
}
