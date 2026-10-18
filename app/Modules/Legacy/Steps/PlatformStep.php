<?php

declare(strict_types=1);

namespace App\Modules\Legacy\Steps;

use App\Modules\Identity\Models\User;
use App\Modules\Legacy\Support\Import;
use App\Modules\Platform\Models\Setting;
use Illuminate\Support\Facades\DB;

/**
 * Companies, settings, plugins, languages, currencies, locations, the admin
 * UI translations and the activity log. Ids are kept.
 *
 * Locations keep v1's ids but not its names for them: v1 "cities" were
 * localities under a district (cities.city_id = district).
 */
final class PlatformStep implements Step
{
    private const COMPANY = 1;

    public function name(): string
    {
        return 'platform';
    }

    public function run(Import $import): void
    {
        $this->companies($import);
        $this->settings($import);
        $this->simple($import);
        $this->locations($import);
        $this->uiTranslations($import);
        $this->activity($import);
    }

    private function companies(Import $i): void
    {
        $rows = $i->rows('companies');
        $i->put('companies', 'companies', array_map(static fn (array $r): array => [
            'id' => $r['id'], 'name' => $r['name'], 'slug' => $r['slug'], 'domain' => $r['domain'], 'logo' => $r['logo'],
            'favicon' => $r['favicon'] ?? null, 'email' => $r['email'], 'phone' => $r['phone'], 'address' => $r['address'],
            'timezone' => $r['timezone'], 'max_users' => $r['max_users'], 'is_active' => Import::bit($r['is_active']),
            'created_by' => $r['created_by'], 'updated_by' => $r['updated_by'],
            'created_at' => Import::at($r['created_at']), 'updated_at' => Import::at($r['updated_at']), 'deleted_at' => Import::at($r['deleted_at']),
        ], $rows), count($rows));
    }

    /** One value per company and key (v1 keys were global); secrets encrypted at rest. */
    private function settings(Import $i): void
    {
        $rows = $i->rows('settings', withDeleted: false);
        $out = [];
        foreach ($rows as $r) {
            $company = $r['company_id'] ?? self::COMPANY;
            $out["{$company}|{$r['key']}"] = [
                'company_id' => $company, 'key' => $r['key'],
                'value' => Setting::isSecret((string) $r['key']) ? Import::encrypt($r['value']) : $r['value'],
                'group' => $r['group'], 'type' => $r['type'], 'description' => $r['description'], 'is_active' => Import::bit($r['is_active']),
                'created_by' => $r['created_by'], 'updated_by' => $r['updated_by'],
                'created_at' => Import::at($r['created_at']), 'updated_at' => Import::at($r['updated_at']),
            ];
        }
        $i->put('settings', 'settings', array_values($out), count($rows), ['company_id', 'key']);
    }

    private function simple(Import $i): void
    {
        $rows = $i->rows('plugins', withDeleted: false);
        $i->put('plugins', 'plugins', array_map(static fn (array $r): array => [
            'company_id' => $r['company_id'] ?? self::COMPANY, 'slug' => $r['slug'], 'name' => $r['name'],
            'description' => $r['description'], 'category' => $r['category'], 'icon' => $r['icon'], 'is_active' => Import::bit($r['is_active']),
            'config_group' => $r['config_group'], 'config_route' => $r['config_route'],
            'created_at' => Import::at($r['created_at']), 'updated_at' => Import::at($r['updated_at']),
        ], $rows), count($rows), ['company_id', 'slug']);

        $rows = $i->rows('languages');
        $i->put('languages', 'languages', array_map(static fn (array $r): array => [
            'id' => $r['id'], 'name' => $r['name'], 'code' => $r['code'], 'native_name' => $r['native_name'], 'direction' => $r['direction'],
            'is_default' => Import::bit($r['is_default'], 0), ...Import::approval($r['is_active']),
            'created_by' => $r['created_by'], 'updated_by' => $r['updated_by'],
            'created_at' => Import::at($r['created_at']), 'updated_at' => Import::at($r['updated_at']), 'deleted_at' => Import::at($r['deleted_at']),
        ], $rows), count($rows));

        $rows = $i->rows('currencies');
        $i->put('currencies', 'currencies', array_map(static fn (array $r): array => [
            'id' => $r['id'], 'name' => $r['name'], 'code' => $r['code'], 'symbol' => $r['symbol'], 'exchange_rate' => $r['exchange_rate'],
            'decimal_places' => $r['decimal_places'], 'decimal_separator' => $r['decimal_separator'], 'thousand_separator' => $r['thousand_separator'],
            'symbol_position' => $r['symbol_position'], 'space_between' => Import::bit($r['space_between'], 0),
            'is_default' => Import::bit($r['is_default'], 0), ...Import::approval($r['is_active']),
            'created_by' => $r['created_by'], 'updated_by' => $r['updated_by'],
            'created_at' => Import::at($r['created_at']), 'updated_at' => Import::at($r['updated_at']), 'deleted_at' => Import::at($r['deleted_at']),
        ], $rows), count($rows));
    }

    /** Countries, states, districts, cities: ~380,000 rows, read and written in chunks. */
    private function locations(Import $i): void
    {
        if ($i->skipLocations) {
            $i->note('locations left out (--skip-locations)');

            return;
        }
        $common = static fn (array $r): array => [
            'is_default' => Import::bit($r['is_default'] ?? 0, 0), ...Import::approval($r['is_active'] ?? 1),
            'created_by' => $r['created_by'], 'updated_by' => $r['updated_by'],
            'created_at' => Import::at($r['created_at']), 'updated_at' => Import::at($r['updated_at']), 'deleted_at' => Import::at($r['deleted_at']),
        ];

        $countries = [];
        $i->chunks('countries', 5000, function (array $rows) use ($i, $common, &$countries): void {
            $i->put('countries', 'countries', array_map(static function (array $r) use ($common, &$countries): array {
                $countries[(int) $r['id']] = true;

                return ['id' => $r['id'], 'name' => $r['name'], 'code' => $r['code'], 'flag' => $r['flag'], 'nationality' => $r['nationality'],
                    'sort_order' => (int) ($r['sort_order'] ?? 0), ...$common($r)];
            }, $rows), count($rows));
        });

        $states = [];
        $i->chunks('states', 5000, function (array $rows) use ($i, $common, $countries, &$states): void {
            $keep = array_values(array_filter($rows, static fn (array $r): bool => isset($countries[(int) $r['country_id']])));
            $i->put('states', 'states', array_map(static function (array $r) use ($common, &$states): array {
                $states[(int) $r['id']] = true;

                return ['id' => $r['id'], 'country_id' => $r['country_id'], 'name' => $r['name'], 'code' => $r['code'], 'slug' => $r['slug'],
                    'sort_order' => (int) ($r['sort_order'] ?? 0), ...$common($r)];
            }, $keep), count($rows));
        });

        $districts = [];
        $i->chunks('districts', 5000, function (array $rows) use ($i, $common, $states, &$districts): void {
            $keep = array_values(array_filter($rows, static fn (array $r): bool => isset($states[(int) $r['state_id']])));
            $i->put('districts', 'districts', array_map(static function (array $r) use ($common, &$districts): array {
                $districts[(int) $r['id']] = true;

                return ['id' => $r['id'], 'state_id' => $r['state_id'], 'name' => $r['name'], 'slug' => $r['slug'],
                    'sort_order' => (int) ($r['sort_order'] ?? 0), ...$common($r)];
            }, $keep), count($rows));
        });

        $i->chunks('cities', 5000, function (array $rows) use ($i, $common, $districts): void {
            $keep = array_values(array_filter($rows, static fn (array $r): bool => isset($districts[(int) $r['city_id']])));
            $i->put('cities', 'cities', array_map(static fn (array $r): array => [
                'id' => $r['id'], 'district_id' => $r['city_id'], 'name' => $r['name'], 'pincode' => $r['pincode'], ...$common($r),
            ], $keep), count($rows));
        });

        $orphans = array_sum(array_map(static fn (array $c): int => $c['skipped'], array_intersect_key($i->counts(), array_flip(['states → states', 'districts → districts', 'cities → cities']))));
        if ($orphans > 0) {
            $i->note("{$orphans} locations skipped: their parent (country, state or district) does not exist");
        }
    }

    /** The admin panel's own UI text (Settings → Translations). */
    private function uiTranslations(Import $i): void
    {
        $keys = $i->rows('translation_keys', withDeleted: false);
        $kept = [];
        $i->put('translation_keys', 'translation_keys', array_map(static function (array $r) use (&$kept): array {
            $kept[(int) $r['id']] = true;

            return ['id' => $r['id'], 'key' => $r['key'], 'default_value' => $r['default_value'], 'description' => $r['description'],
                'group' => $r['group'] ?? 'common', 'approval_status' => 'approved', 'created_by' => $r['created_by'], 'updated_by' => $r['updated_by'],
                'created_at' => Import::at($r['created_at']), 'updated_at' => Import::at($r['updated_at'])];
        }, $keys), count($keys));

        $languages = DB::table('languages')->pluck('id')->flip()->all();
        $rows = $i->rows('translations', withDeleted: false);
        $keep = array_values(array_filter($rows, static fn (array $r): bool => isset($kept[(int) $r['translation_key_id']], $languages[(int) $r['language_id']])));
        $i->put('translations', 'translations', array_map(static fn (array $r): array => [
            'id' => $r['id'], 'translation_key_id' => $r['translation_key_id'], 'language_id' => $r['language_id'], 'value' => $r['value'],
            'status' => $r['status'] ?? 'reviewed', 'is_active' => Import::bit($r['is_active']), 'created_by' => $r['created_by'], 'updated_by' => $r['updated_by'],
            'created_at' => Import::at($r['created_at']), 'updated_at' => Import::at($r['updated_at']),
        ], $keep), count($rows));

        $rows = $i->rows('missing_translation_keys');
        $i->put('missing_translation_keys', 'missing_translation_keys', array_map(static fn (array $r): array => [
            'id' => $r['id'], 'key' => $r['key'], 'default_value' => $r['default_value'], 'page_url' => $r['page_url'],
            'report_count' => (int) $r['report_count'], 'first_reported_at' => Import::at($r['first_reported_at']),
            'last_reported_at' => Import::at($r['last_reported_at']),
            // v1 switched an entry off when it was handled.
            'resolved_at' => Import::bit($r['is_active']) === 0 ? Import::at($r['updated_at']) : null,
            'created_at' => Import::at($r['created_at']), 'updated_at' => Import::at($r['updated_at']),
        ], $rows), count($rows));
    }

    /**
     * The admin activity log, in the new log's shape. Passwords and other
     * secrets are removed from the recorded values: v1 logged a changed
     * password in plain text (bug 7).
     */
    private function activity(Import $i): void
    {
        $users = DB::table('users')->pluck('id')->flip()->all();
        $userType = (new User)->getMorphClass();
        $rows = $i->rows('activity_logs');
        $i->put('activity_logs', 'activity_log', array_map(static function (array $r) use ($users, $userType): array {
            $causer = isset($users[(int) $r['user_id']]) ? (int) $r['user_id'] : null;
            $properties = array_filter([
                'old_values' => self::redact(Import::list($r['old_values'])),
                'new_values' => self::redact(Import::list($r['new_values'])),
            ], static fn (array $v): bool => $v !== []);

            return [
                'id' => $r['id'], 'log_name' => Import::text($r['module'], 255), 'description' => (string) ($r['description'] ?? ''),
                'subject_type' => null, 'subject_id' => null, 'event' => Import::text($r['action'], 255),
                'causer_type' => $causer === null ? null : $userType, 'causer_id' => $causer,
                'properties' => $properties === [] ? null : json_encode($properties, JSON_UNESCAPED_UNICODE),
                'batch_uuid' => null, 'company_id' => $r['company_id'] ?? self::COMPANY,
                'ip_address' => $r['ip_address'], 'user_agent' => $r['user_agent'], 'url' => Import::text($r['url'], 500), 'method' => $r['method'],
                'created_at' => Import::at($r['created_at']), 'updated_at' => Import::at($r['created_at']),
            ];
        }, $rows), count($rows));
    }

    /**
     * @param  array<array-key, mixed>  $values
     * @return array<array-key, mixed>
     */
    private static function redact(array $values): array
    {
        foreach ($values as $key => $value) {
            if (is_string($key) && preg_match('/password|secret|token|otp|api_key|private_key|acc_no|account_number/i', $key) === 1) {
                unset($values[$key]);
            } elseif (is_array($value)) {
                $values[$key] = self::redact($value);
            }
        }

        return $values;
    }
}
