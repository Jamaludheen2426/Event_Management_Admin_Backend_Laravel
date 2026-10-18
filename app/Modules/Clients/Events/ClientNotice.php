<?php

declare(strict_types=1);

namespace App\Modules\Clients\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Something a client should hear about in their notification feed ("New
 * guest added", "New RSVP"...). v1 wrote the feed row inline; here modules
 * dispatch this and the Messaging module turns it into a notification.
 */
final class ClientNotice
{
    use Dispatchable;

    public function __construct(
        public readonly int $clientId,
        public readonly string $type,
        public readonly string $title,
        public readonly string $body,
        public readonly ?string $link = null,
        public readonly ?int $eventId = null,
        public readonly ?int $participantId = null,
        /** @var array<string, mixed>|null extra detail for the side panel, never load-bearing */
        public readonly ?array $meta = null,
    ) {}
}
