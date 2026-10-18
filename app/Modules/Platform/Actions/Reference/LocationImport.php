<?php

declare(strict_types=1);

namespace App\Modules\Platform\Actions\Reference;

use App\Modules\Platform\Models\City;
use App\Modules\Platform\Models\Country;
use App\Modules\Platform\Models\District;
use App\Modules\Platform\Models\State;
use Illuminate\Database\Eloquent\Model;

/**
 * CSV bulk import of locations (v1 /locations/{level}/bulk). Rows that point
 * at an unknown parent are skipped and counted; duplicates are ignored.
 *
 * v1 built one INSERT by string concatenation with foreign keys switched off;
 * this inserts in parameterised batches of 500 with keys on (prod is ~374 ms
 * per query, so batches, never row by row).
 */
final class LocationImport
{
    private const CHUNK = 500;

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array{imported: int}
     */
    public function countries(array $rows, ?int $userId): array
    {
        $records = array_map(fn (array $r): array => $this->stamp([
            'name' => (string) ($r['name'] ?? ''),
            'code' => strtoupper(substr(trim((string) ($r['code'] ?? '')), 0, 3)),
            'nationality' => $r['nationality'] ?? null,
            'sort_order' => (int) ($r['sort_order'] ?? 0),
            'is_active' => $this->active($r['is_active'] ?? null),
            'is_default' => $this->isDefault($r['is_default'] ?? null),
        ], $userId), $rows);

        return ['imported' => $this->insert(new Country, $records)];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array{imported: int, skipped: int, missingCountryCodes: list<string>}
     */
    public function states(array $rows, ?int $userId): array
    {
        $codes = $this->upperValues($rows, 'country_code');
        $countries = Country::query()->whereIn('code', $codes)->pluck('id', 'code')->mapWithKeys(
            static fn (int $id, string $code): array => [strtoupper($code) => $id],
        );

        $records = [];
        $missing = [];
        foreach ($rows as $r) {
            $countryId = is_numeric($r['country_id'] ?? null) ? (int) $r['country_id'] : null;
            $code = strtoupper((string) ($r['country_code'] ?? ''));
            $countryId ??= $code !== '' ? $countries->get($code) : null;
            if ($countryId === null) {
                if ($code !== '') {
                    $missing[$code] = $code;
                }

                continue;
            }
            $records[] = $this->stamp([
                'name' => (string) ($r['name'] ?? ''),
                'code' => $r['code'] ?? null,
                'slug' => $r['slug'] ?? null,
                'country_id' => $countryId,
                'sort_order' => (int) ($r['sort_order'] ?? 0),
                'is_active' => $this->active($r['is_active'] ?? null),
                'is_default' => $this->isDefault($r['is_default'] ?? null),
            ], $userId);
        }

        $imported = $this->insert(new State, $records);

        return ['imported' => $imported, 'skipped' => count($rows) - count($records), 'missingCountryCodes' => array_values($missing)];
    }

    /**
     * State codes are matched together with the country code when given
     * (TN = Tamil Nadu in IN, Tennessee in US).
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array{imported: int, skipped: int}
     */
    public function districts(array $rows, ?int $userId): array
    {
        $countryIds = Country::query()->whereIn('code', $this->upperValues($rows, 'country_code'))->pluck('id', 'code')
            ->mapWithKeys(static fn (int $id, string $code): array => [strtoupper($code) => $id]);
        $countryCodes = $countryIds->flip();

        $states = [];
        State::query()
            ->whereIn('code', $this->upperValues($rows, 'state_code'))
            ->when($countryIds->isNotEmpty(), static fn ($q) => $q->whereIn('country_id', $countryIds->values()))
            ->get(['id', 'code', 'country_id'])
            ->each(static function (State $s) use (&$states, $countryCodes): void {
                $code = strtoupper((string) $s->getAttribute('code'));
                $states[$code.'|'.($countryCodes->get($s->country_id) ?? '')] = $s->id;
                $states[$code] ??= $s->id;
            });

        $records = [];
        foreach ($rows as $r) {
            $stateId = is_numeric($r['state_id'] ?? null) ? (int) $r['state_id'] : null;
            if ($stateId === null && ! empty($r['state_code'])) {
                $code = strtoupper((string) $r['state_code']);
                $stateId = $states[$code.'|'.strtoupper((string) ($r['country_code'] ?? ''))] ?? $states[$code] ?? null;
            }
            if ($stateId === null) {
                continue;
            }
            $records[] = $this->stamp([
                'name' => (string) ($r['name'] ?? ''),
                'slug' => $r['slug'] ?? null,
                'state_id' => $stateId,
                'sort_order' => (int) ($r['sort_order'] ?? 0),
                'is_active' => $this->active($r['is_active'] ?? null),
                'is_default' => $this->isDefault($r['is_default'] ?? null),
            ], $userId);
        }

        return ['imported' => $this->insert(new District, $records), 'skipped' => count($rows) - count($records)];
    }

    /**
     * Parent district by district_id, v1 city_id, or district_name (+ state_code).
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array{imported: int, skipped: int}
     */
    public function cities(array $rows, ?int $userId): array
    {
        $names = array_values(array_unique(array_filter(array_map(static fn (array $r): string => (string) ($r['district_name'] ?? ''), $rows))));
        $districts = [];
        if ($names !== []) {
            District::query()->whereIn('name', $names)->with('state:id,code')->get(['id', 'name', 'state_id'])
                ->each(static function (District $d) use (&$districts): void {
                    $name = mb_strtolower($d->name);
                    $districts[$name.'|'.strtoupper((string) $d->state?->getAttribute('code'))] = $d->id;
                    $districts[$name] ??= $d->id;
                });
        }

        $records = [];
        foreach ($rows as $r) {
            $districtId = is_numeric($r['district_id'] ?? null) ? (int) $r['district_id']
                : (is_numeric($r['city_id'] ?? null) ? (int) $r['city_id'] : null);
            if ($districtId === null && ! empty($r['district_name'])) {
                $name = mb_strtolower((string) $r['district_name']);
                $districtId = $districts[$name.'|'.strtoupper((string) ($r['state_code'] ?? ''))] ?? $districts[$name] ?? null;
            }
            if ($districtId === null) {
                continue;
            }
            $records[] = $this->stamp([
                'name' => (string) ($r['name'] ?? ''),
                'pincode' => (string) ($r['pincode'] ?? ''),
                'district_id' => $districtId,
                'is_active' => $this->active($r['is_active'] ?? null),
                'is_default' => $this->isDefault($r['is_default'] ?? null),
            ], $userId);
        }

        return ['imported' => $this->insert(new City, $records), 'skipped' => count($rows) - count($records)];
    }

    /** @param list<array<string, mixed>> $records */
    private function insert(Model $model, array $records): int
    {
        $records = array_values(array_filter($records, static fn (array $r): bool => $r['name'] !== ''));
        $inserted = 0;
        foreach (array_chunk($records, self::CHUNK) as $chunk) {
            $inserted += $model->newQuery()->insertOrIgnore($chunk);
        }

        return $inserted;
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function stamp(array $values, ?int $userId): array
    {
        $now = now();

        return [...$values, 'approval_status' => 'approved', 'created_by' => $userId, 'updated_by' => $userId, 'created_at' => $now, 'updated_at' => $now];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<string>
     */
    private function upperValues(array $rows, string $key): array
    {
        return array_values(array_unique(array_filter(array_map(static fn (array $r): string => strtoupper((string) ($r[$key] ?? '')), $rows))));
    }

    private function active(mixed $value): bool
    {
        return ! ($value === false || $value === 0 || $value === '0');
    }

    private function isDefault(mixed $value): bool
    {
        return $value === true || $value === 1 || $value === '1';
    }
}
