<?php

declare(strict_types=1);

namespace App\Modules\Clients\Support;

use App\Modules\Clients\Models\Client;
use Closure;

/**
 * How later modules join in mobile-number sign-in without Clients depending on
 * them (Events registers these in Phase 6):
 *
 *  - accountFor:   no client has this number — is it a guest's or an event
 *                  organizer's number? Create and return its account (v1:
 *                  participants and organizer numbers get accounts on first
 *                  sign-in);
 *  - isOrganizer:  "login as organizer" — does this client run an event?
 *  - signedIn:     after a verified sign-in (link guest rows, claim events).
 */
final class MobileSignInHooks
{
    /** @var list<Closure(list<string>, int, bool): ?Client> */
    private array $accountFor = [];

    /** @var list<Closure(Client): bool> */
    private array $isOrganizer = [];

    /** @var list<Closure(Client): void> */
    private array $signedIn = [];

    /** @param Closure(list<string>, int, bool): ?Client $resolver (number forms, vendor id, organizer sign-in) */
    public function accountFor(Closure $resolver): void
    {
        $this->accountFor[] = $resolver;
    }

    /** @param Closure(Client): bool $check */
    public function isOrganizer(Closure $check): void
    {
        $this->isOrganizer[] = $check;
    }

    /** @param Closure(Client): void $listener */
    public function signedIn(Closure $listener): void
    {
        $this->signedIn[] = $listener;
    }

    /** @param list<string> $numbers */
    public function resolveAccount(array $numbers, int $vendorId, bool $asOrganizer): ?Client
    {
        foreach ($this->accountFor as $resolver) {
            $client = $resolver($numbers, $vendorId, $asOrganizer);
            if ($client !== null) {
                return $client;
            }
        }

        return null;
    }

    public function organizes(Client $client): bool
    {
        foreach ($this->isOrganizer as $check) {
            if ($check($client)) {
                return true;
            }
        }

        return false;
    }

    public function afterSignIn(Client $client): void
    {
        foreach ($this->signedIn as $listener) {
            $listener($client);
        }
    }
}
