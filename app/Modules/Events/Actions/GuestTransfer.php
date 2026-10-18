<?php

declare(strict_types=1);

namespace App\Modules\Events\Actions;

use App\Core\Exceptions\ApiException;
use App\Modules\Clients\Models\Client;
use App\Modules\Events\Models\Guest;
use App\Modules\Events\Models\GuestGroup;
use App\Modules\Events\Support\GuestFields;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * CSV import and export of the phone book (v1 clientGuestImport / Export).
 *
 * Import handles what real spreadsheets do: a BOM, ';' as the delimiter,
 * quoted commas and newlines, header spelling drift, numbers Excel turned into
 * 9.19877E+11, blank rows, the same number twice. Rows are keyed by mobile; a
 * bad row is reported, not fatal. Export guards cells against formula
 * injection (=, +, -, @ at the start).
 */
final class GuestTransfer
{
    private const MAX_ROWS = 5000;

    private const MAX_BYTES = 10 * 1024 * 1024;

    private const HEADERS = [
        'first_name' => ['first name', 'firstname', 'first_name', 'given name'],
        'last_name' => ['last name', 'lastname', 'last_name', 'surname', 'family name'],
        'email' => ['email', 'email address', 'e-mail', 'mail'],
        'mobile' => ['phone number', 'phone', 'mobile', 'mobile number', 'contact'],
        'whatsapp' => ['whatsapp number', 'whatsapp', 'whats app number'],
        'group_name' => ['guest group', 'group', 'group name'],
        'company' => ['company / organization', 'company', 'organization', 'organisation'],
        'title' => ['title / salutation', 'title', 'salutation'],
        'address_line1' => ['address line 1', 'address1', 'address'],
        'address_line2' => ['address line 2', 'address2'],
        'city' => ['city', 'town'],
        'state' => ['state / province', 'state', 'province'],
        'postal_code' => ['pin / zip code', 'pin', 'zip', 'zip code', 'postal code', 'pincode'],
        'country' => ['country'],
        'dietary_preference' => ['dietary preference', 'dietary preferences', 'diet'],
        'special_requirements' => ['special requirements', 'special requirement', 'requirements'],
        'notes' => ['notes', 'note', 'remarks'],
    ];

    /** Export columns: header => guest field (group handled separately). */
    private const COLUMNS = [
        'First Name*' => 'first_name', 'Last Name' => 'last_name', 'Phone Number*' => 'mobile', 'WhatsApp Number' => 'whatsapp',
        'Email' => 'email', 'Guest Group' => 'group', 'Title / Salutation' => 'title', 'Company / Organization' => 'company',
        'Address Line 1' => 'address_line1', 'Address Line 2' => 'address_line2', 'City' => 'city', 'State / Province' => 'state',
        'PIN / ZIP Code' => 'postal_code', 'Country' => 'country', 'Dietary Preference' => 'dietary_preference',
        'Special Requirements' => 'special_requirements', 'Notes' => 'notes',
    ];

    public function __construct(private readonly GuestBook $book) {}

    /**
     * The preview step: nothing is written.
     *
     * @return array<string, mixed>
     */
    public function preview(Client $client, mixed $content): array
    {
        $result = $this->analyse($client, $content);

        return [
            'delimiter' => $result['delimiter'],
            'mapping' => $result['mapping'],
            'unmapped' => $result['unmapped'],
            'total_rows' => $result['total_rows'],
            'valid_count' => count($result['valid']),
            'skipped_count' => count($result['skipped']),
            'error_count' => count($result['errors']),
            'new_groups' => $result['new_groups'],
            'preview' => array_slice($result['valid'], 0, 20),
            'skipped' => array_slice($result['skipped'], 0, 50),
            'errors' => array_slice($result['errors'], 0, 50),
        ];
    }

    /** @return array<string, mixed> */
    public function import(Client $client, mixed $content, bool $createGroups): array
    {
        $result = $this->analyse($client, $content);
        $rows = $result['valid'];
        $outcome = ['imported' => 0, 'skipped' => count($result['skipped']), 'failed' => count($result['errors']), 'created_groups' => [], 'errors' => array_slice($result['errors'], 0, 50)];
        if ($rows === []) {
            return $outcome;
        }

        return DB::transaction(function () use ($client, $rows, $createGroups, $outcome): array {
            $max = $this->book->limit($client);
            if ($max !== null) {
                $used = Guest::query()->where('client_id', $client->id)->count();
                if ($used + count($rows) > $max) {
                    $left = max(0, $max - $used);
                    throw ApiException::badRequest("Your plan allows {$max} guests in total and you already have {$used}. This file adds ".count($rows).", but only {$left} more can be added. Please upgrade your plan or import fewer guests.");
                }
            }

            $created = [];
            if ($createGroups) {
                foreach (array_unique(array_filter(array_column($rows, 'group_name'))) as $name) {
                    $group = GuestGroup::query()->create(['client_id' => $client->id, 'name' => mb_substr($name, 0, 100), 'description' => 'Created during a guest import.']);
                    $created[] = ['id' => $group->id, 'name' => $group->name];
                    foreach ($rows as &$row) {
                        if ($row['group_name'] === $name) {
                            $row['group_id'] = $group->id;
                        }
                    }
                    unset($row);
                }
            }

            $now = now();
            $payload = array_map(static function (array $row) use ($client, $now): array {
                unset($row['row'], $row['group_name']);

                return [...$row, 'client_id' => $client->id, 'created_at' => $now, 'updated_at' => $now];
            }, $rows);
            foreach (array_chunk($payload, 500) as $chunk) {
                Guest::query()->insert($chunk);
            }

            return [...$outcome, 'imported' => count($payload), 'created_groups' => $created];
        });
    }

    /** @return array{filename: string, content: string} */
    public function export(Client $client, Request $request): array
    {
        $query = Guest::query()->where('client_id', $client->id)->orderByDesc('created_at')->orderByDesc('id');
        $tab = mb_strtolower((string) $request->query('status', 'all'));
        if ($tab === 'imported') {
            $query->where('source', 'import');
        }
        $this->book->filter($query, $request->duplicate(array_diff_key($request->query(), ['status' => 1])));
        $groups = GuestGroup::query()->where('client_id', $client->id)->pluck('name', 'id');

        $rows = [array_keys(self::COLUMNS)];
        foreach ($query->get() as $guest) {
            $rows[] = $this->row($guest->attributesToArray(), $groups[$guest->group_id] ?? '');
        }

        return ['filename' => 'guests-'.today()->format('Y-m-d').'.csv', 'content' => "\u{FEFF}".$this->csv($rows)];
    }

    /** @return array{filename: string, content: string} */
    public function sample(Client $client): array
    {
        $group = GuestGroup::query()->where('client_id', $client->id)->orderBy('id')->value('name') ?? 'Family';
        $samples = [
            ['first_name' => 'Amit', 'last_name' => 'Sharma', 'mobile' => '+919876543210', 'whatsapp' => '+919876543210', 'email' => 'amit.sharma@example.com',
                'title' => 'Mr.', 'company' => '', 'address_line1' => '12 Gandhi Street', 'address_line2' => '', 'city' => 'Chennai', 'state' => 'Tamil Nadu',
                'postal_code' => '600001', 'country' => 'India', 'dietary_preference' => 'Vegetarian', 'special_requirements' => 'Wheelchair access', 'notes' => 'Close relative'],
            ['first_name' => 'Priya', 'last_name' => 'Mehta', 'mobile' => '+919812345678', 'whatsapp' => '', 'email' => '', 'title' => 'Ms.', 'company' => 'ABC Events',
                'address_line1' => '45 Rose Avenue', 'address_line2' => 'Apt 2B', 'city' => 'Bengaluru', 'state' => 'Karnataka', 'postal_code' => '560001',
                'country' => 'India', 'dietary_preference' => 'Vegan', 'special_requirements' => '', 'notes' => 'College friend'],
        ];

        return [
            'filename' => 'guest-import-sample.csv',
            'content' => "\u{FEFF}".$this->csv([array_keys(self::COLUMNS), ...array_map(fn (array $s): array => $this->row($s, $group), $samples)]),
        ];
    }

    /**
     * @param  array<string, mixed>  $guest
     * @return list<string|null>
     */
    private function row(array $guest, string $group): array
    {
        return array_values(array_map(static fn (string $field) => match ($field) {
            'group' => $group,
            'first_name' => $guest['first_name'] ?? $guest['name'] ?? null,
            default => isset($guest[$field]) ? (string) $guest[$field] : null,
        }, self::COLUMNS));
    }

    /** @param list<list<string|null>> $rows */
    private function csv(array $rows): string
    {
        $cell = static function (?string $value): string {
            if ($value === null) {
                return '""';
            }
            $guarded = preg_match('/^[+=\-@\t\r]/', $value) === 1 ? "\t".$value : $value;

            return '"'.str_replace('"', '""', $guarded).'"';
        };

        return implode("\r\n", array_map(static fn (array $row): string => implode(',', array_map($cell, $row)), $rows));
    }

    /** @return array<string, mixed> */
    private function analyse(Client $client, mixed $content): array
    {
        if (! is_string($content) || trim($content) === '') {
            throw ApiException::badRequest('The file is empty.');
        }
        if (strlen($content) > self::MAX_BYTES) {
            throw ApiException::badRequest('That file is larger than 10 MB.');
        }
        $lines = array_values(array_filter($this->splitRows($content), static fn (string $l): bool => trim($l) !== ''));
        if (count($lines) < 2) {
            throw ApiException::badRequest('The file has a header but no rows.');
        }

        $delimiter = substr_count($lines[0], ';') > substr_count($lines[0], ',') ? ';' : ',';
        $mapping = [];
        foreach ($this->splitLine($lines[0], $delimiter) as $index => $header) {
            $clean = (string) preg_replace('/^\x{FEFF}/u', '', $header);
            $mapping[] = ['index' => $index, 'header' => $clean, 'field' => $this->match($clean)];
        }
        $found = array_filter(array_column($mapping, 'field'));
        $missing = array_diff(['first_name', 'mobile'], $found);
        if ($missing !== []) {
            throw ApiException::badRequest('The file is missing required column(s): '.implode(', ', array_map(static fn (string $m): string => str_replace('_', ' ', $m), $missing)).'.');
        }
        $dataLines = array_slice($lines, 1);
        if (count($dataLines) > self::MAX_ROWS) {
            throw ApiException::badRequest('That file has '.count($dataLines).' rows. The limit is '.self::MAX_ROWS.'.');
        }

        $groups = GuestGroup::query()->where('client_id', $client->id)->get(['id', 'name'])->keyBy(static fn (GuestGroup $g): string => mb_strtolower(trim($g->name)));
        $existing = Guest::query()->where('client_id', $client->id)->whereNotNull('mobile')->pluck('mobile')
            ->map(static fn ($m): string => GuestFields::mobileKey((string) $m))->flip();
        $columns = array_column(array_filter($mapping, static fn (array $m): bool => $m['field'] !== null), 'index', 'field');

        $valid = $errors = $skipped = $seen = $newGroups = [];
        foreach ($dataLines as $i => $line) {
            $rowNumber = $i + 2;
            $cells = $this->splitLine($line, $delimiter);
            $get = static fn (string $f): string => isset($columns[$f]) ? trim($cells[$columns[$f]] ?? '') : '';

            $first = $get('first_name');
            $email = mb_strtolower($get('email'));
            $rawMobile = $get('mobile');
            if ($first === '' && $email === '' && $rawMobile === '') {
                continue;
            }
            $rowErrors = [];
            if ($first === '') {
                $rowErrors[] = 'First name is required.';
            }
            if ($rawMobile === '') {
                $rowErrors[] = 'Mobile number is required.';
            }
            if ($email !== '' && preg_match('/^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/', $email) !== 1) {
                $rowErrors[] = 'Email is not valid.';
            }
            [$mobile, $mobileError] = $this->phone($rawMobile);
            [$whatsapp, $whatsappError] = $this->phone($get('whatsapp'));
            array_push($rowErrors, ...array_filter([$mobileError, $whatsappError]));
            if ($rowErrors !== []) {
                $errors[] = ['row' => $rowNumber, 'name' => $first ?: ($email ?: "Row {$rowNumber}"), 'errors' => $rowErrors];

                continue;
            }

            $key = GuestFields::mobileKey($mobile);
            if (isset($seen[$key])) {
                $skipped[] = ['row' => $rowNumber, 'name' => $first, 'email' => $email, 'reason' => 'Duplicate of an earlier row in this file.'];

                continue;
            }
            if (isset($existing[$key])) {
                $skipped[] = ['row' => $rowNumber, 'name' => $first, 'email' => $email, 'reason' => 'Already in your guest list.'];

                continue;
            }
            $seen[$key] = true;

            $groupName = $get('group_name');
            $groupId = $groupName !== '' ? $groups->get(mb_strtolower($groupName))?->id : null;
            if ($groupName !== '' && $groupId === null) {
                $newGroups[$groupName] = true;
            }
            $cut = static fn (string $f, int $max): ?string => mb_substr($get($f), 0, $max) ?: null;
            $valid[] = [
                'row' => $rowNumber,
                'group_id' => $groupId,
                'group_name' => $groupId !== null ? null : ($groupName ?: null),
                'title' => $cut('title', 20),
                'first_name' => mb_substr($first, 0, 100),
                'last_name' => $cut('last_name', 100),
                'name' => GuestFields::composeName($first, $get('last_name'), $first),
                'email' => $email ?: null,
                'dial_code' => '+91',
                'mobile' => $mobile,
                'whatsapp' => $whatsapp,
                'company' => $cut('company', 150),
                'source' => 'import',
                'address_line1' => $cut('address_line1', 255),
                'address_line2' => $cut('address_line2', 255),
                'city' => $cut('city', 100),
                'state' => $cut('state', 100),
                'postal_code' => $cut('postal_code', 20),
                'country' => $cut('country', 100) ?? 'India',
                'dietary_preference' => $cut('dietary_preference', 100),
                'special_requirements' => $cut('special_requirements', 500),
                'notes' => $cut('notes', 500),
            ];
        }

        return [
            'delimiter' => $delimiter,
            'mapping' => $mapping,
            'unmapped' => array_column(array_filter($mapping, static fn (array $m): bool => $m['field'] === null), 'header'),
            'total_rows' => count($dataLines),
            'valid' => $valid,
            'skipped' => $skipped,
            'errors' => $errors,
            'new_groups' => array_keys($newGroups),
        ];
    }

    private function match(string $raw): ?string
    {
        $key = (string) preg_replace('/\s+/', ' ', mb_strtolower(trim((string) preg_replace('/\*+$/', '', trim($raw)))));
        if ($key === '') {
            return null;
        }
        foreach (self::HEADERS as $field => $spellings) {
            if (in_array($key, $spellings, true)) {
                return $field;
            }
        }
        $snake = (string) preg_replace('/[^a-z0-9]+/', '_', $key);

        return array_key_exists($snake, self::HEADERS) ? $snake : null;
    }

    /** @return array{0: string|null, 1: string|null} the cleaned number, or an error */
    private function phone(string $raw): array
    {
        if ($raw === '') {
            return [null, null];
        }
        if (preg_match('/e\+?\d+$/i', str_replace(' ', '', $raw)) === 1) {
            return [null, 'Phone looks like it was converted to a number by a spreadsheet (e.g. 9.19877E+11). Format the column as Text and export again.'];
        }
        $cleaned = (string) preg_replace('/[^\d+]/', '', $raw);
        if (strlen((string) preg_replace('/\D/', '', $cleaned)) < 5) {
            return [null, 'Phone number is too short.'];
        }

        return [substr($cleaned, 0, 20), null];
    }

    /** @return list<string> */
    private function splitRows(string $text): array
    {
        $rows = [];
        $current = '';
        $quoted = false;
        $length = strlen($text);
        for ($i = 0; $i < $length; $i++) {
            $ch = $text[$i];
            if ($ch === '"') {
                if ($quoted && ($text[$i + 1] ?? '') === '"') {
                    $current .= '""';
                    $i++;

                    continue;
                }
                $quoted = ! $quoted;
                $current .= $ch;

                continue;
            }
            if (! $quoted && ($ch === "\n" || $ch === "\r")) {
                if ($ch === "\r" && ($text[$i + 1] ?? '') === "\n") {
                    $i++;
                }
                $rows[] = $current;
                $current = '';

                continue;
            }
            $current .= $ch;
        }
        if ($current !== '') {
            $rows[] = $current;
        }

        return $rows;
    }

    /** @return list<string> */
    private function splitLine(string $line, string $delimiter): array
    {
        $out = [];
        $field = '';
        $quoted = false;
        $length = strlen($line);
        for ($i = 0; $i < $length; $i++) {
            $ch = $line[$i];
            if ($quoted) {
                if ($ch === '"') {
                    if (($line[$i + 1] ?? '') === '"') {
                        $field .= '"';
                        $i++;
                    } else {
                        $quoted = false;
                    }
                } else {
                    $field .= $ch;
                }

                continue;
            }
            if ($ch === '"') {
                $quoted = true;
            } elseif ($ch === $delimiter) {
                $out[] = trim($field);
                $field = '';
            } else {
                $field .= $ch;
            }
        }
        $out[] = trim($field);

        return $out;
    }
}
