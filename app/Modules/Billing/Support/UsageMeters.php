<?php

declare(strict_types=1);

namespace App\Modules\Billing\Support;

use App\Modules\Clients\Models\Client;
use Carbon\CarbonInterface;
use Closure;

/**
 * What a client has used, for the billing overview and invoices. The numbers
 * belong to other modules (Events counts events, guests and gallery storage),
 * so they register a meter here instead of Billing reaching into them.
 *
 * A meter returns any of: events, guests, heads, storage_bytes. Missing
 * numbers count as 0 — true while no module owns them yet.
 */
final class UsageMeters
{
    /** @var list<Closure(Client, ?CarbonInterface, ?CarbonInterface): array<string, int>> */
    private array $meters = [];

    /** @param Closure(Client, ?CarbonInterface, ?CarbonInterface): array<string, int> $meter */
    public function register(Closure $meter): void
    {
        $this->meters[] = $meter;
    }

    /** @return array{events: int, guests: int, heads: int, storage_bytes: int} */
    public function measure(Client $client, ?CarbonInterface $from, ?CarbonInterface $to): array
    {
        $totals = ['events' => 0, 'guests' => 0, 'heads' => 0, 'storage_bytes' => 0];
        foreach ($this->meters as $meter) {
            foreach ($meter($client, $from, $to) as $key => $value) {
                if (array_key_exists($key, $totals)) {
                    $totals[$key] += $value;
                }
            }
        }

        return $totals;
    }
}
