<?php

declare(strict_types=1);

namespace App\Modules\Billing\Actions;

use App\Core\Exceptions\ApiException;
use App\Core\Support\Dates;
use App\Modules\Billing\Models\SalesEnquiry;
use App\Modules\Clients\Models\Client;

/**
 * "Contact sales" from the billing screens. Stored for the team, not e-mailed
 * (v1 behaviour: there is no outgoing mail for this yet).
 */
final class SalesEnquiries
{
    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function create(Client $client, array $input): array
    {
        $text = static fn (string $key, int $max): ?string => is_scalar($input[$key] ?? null) && trim((string) $input[$key]) !== ''
            ? mb_substr(trim((string) $input[$key]), 0, $max) : null;

        $name = $text('full_name', 150) ?? throw ApiException::badRequest('Please enter your name.');
        $email = $text('work_email', 190);
        if ($email === null || preg_match('/^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/', $email) !== 1) {
            throw ApiException::badRequest('Please enter a valid work email.');
        }
        $message = $text('message', 5000) ?? throw ApiException::badRequest('Please tell us about your requirements.');
        $interests = is_array($input['interests'] ?? null)
            ? array_slice(array_map(static fn ($i): string => mb_substr(is_scalar($i) ? (string) $i : '', 0, 80), array_values($input['interests'])), 0, 20)
            : null;

        $row = SalesEnquiry::query()->create([
            'client_id' => $client->id,
            'full_name' => $name,
            'work_email' => $email,
            'company_name' => $text('company_name', 190),
            'phone' => $text('phone', 30),
            'events_per_year' => $text('events_per_year', 50),
            'interests' => $interests,
            'message' => $message,
            'preferred_time' => $text('preferred_time', 50),
            'status' => 'new',
        ]);

        return [
            'id' => $row->id,
            'submitted_at' => Dates::at($row->created_at),
            'message' => 'Thanks — your enquiry is saved and our team will get back to you.',
            'delivery' => 'stored',
        ];
    }
}
