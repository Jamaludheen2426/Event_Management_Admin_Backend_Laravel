<?php

declare(strict_types=1);

namespace App\Modules\Events\Events;

use App\Modules\Events\Models\Participant;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Someone joined an event (QR scan) or the host added them. Messaging sends
 * the welcome invitation from this (v1 notificationTrigger.triggerWelcomeInvitation).
 */
final class ParticipantAdded
{
    use Dispatchable;

    public function __construct(public readonly Participant $participant) {}
}
