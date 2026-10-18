<?php

declare(strict_types=1);

namespace App\Modules\Legacy\Steps;

use App\Core\Support\Phone;
use App\Modules\Legacy\Support\Import;
use Illuminate\Support\Facades\DB;

/**
 * One clients table for v1's two:
 *
 *  - website_clients keep their ids (events, billing and the app point at them);
 *  - vendor_clients are numbered after the last website client (entity
 *    "vendor_client" in legacy_id_map) — mail, chat and the newsletter
 *    pointed at them and are re-pointed in the Messaging step.
 *
 * E-mail and mobile are unique per vendor among live clients: a later
 * duplicate is kept but retired (deleted) and reported. Sessions and refresh
 * tokens are not imported; everyone signs in again.
 */
final class ClientsStep implements Step
{
    /** @var array<string, string> "vendor|email" / "vendor|mobile" => who has it */
    private array $taken = [];

    public function name(): string
    {
        return 'clients';
    }

    public function run(Import $import): void
    {
        $this->websiteClients($import);
        $this->vendorClients($import);
        $this->satellites($import);
    }

    private function websiteClients(Import $i): void
    {
        $plans = DB::table('plans')->pluck('id')->flip()->all();
        $vendors = DB::table('vendors')->pluck('id')->flip()->all();
        $rows = $i->rows('website_clients');
        $out = [];
        $favourites = [];
        foreach ($rows as $r) {
            if (! isset($vendors[(int) $r['vendor_id']])) {
                $i->note("website client {$r['id']}: vendor {$r['vendor_id']} does not exist, skipped");

                continue;
            }
            $deleted = Import::at($r['deleted_at']);
            $email = $r['email'] === null || $r['email'] === '' ? null : mb_strtolower(trim((string) $r['email']));
            $mobile = Phone::digits($r['mobile']) ?: null;
            $retire = $this->retire($i, (int) $r['vendor_id'], $email, $deleted === null, "website client {$r['id']}");
            $out[] = [
                'id' => $r['id'], 'vendor_id' => $r['vendor_id'], 'code' => null, 'source' => $r['source'], 'provider_id' => $r['provider_id'],
                'name' => $r['name'], 'company_name' => $r['company_name'], 'bio' => $r['bio'], 'email' => $email, 'dial_code' => $r['dial_code'],
                'mobile' => $mobile, 'password' => $r['password'], 'avatar_url' => Import::text($r['avatar_url'], 500), 'address' => null,
                'country' => null, 'state' => null, 'district' => null, 'city' => null, 'locality' => null, 'pincode' => null,
                'plan_id' => isset($plans[(int) $r['subscription_plan_id']]) ? $r['subscription_plan_id'] : null, 'plan_type_id' => null,
                'registration_type' => 'client', 'newsletter_opt_in' => 0, 'login_access' => 1, 'send_credentials_to_email' => 0,
                'email_verified' => Import::bit($r['email_verified'], 0), 'mobile_verified' => Import::bit($r['mobile_verified'], 0),
                'otp_hash' => null, 'otp_expires_at' => null, 'otp_attempts' => 0, 'is_active' => Import::bit($r['is_active']),
                'last_login_at' => Import::at($r['last_login_at']), 'password_changed_at' => null, 'created_by' => null,
                'created_at' => Import::at($r['created_at']), 'updated_at' => Import::at($r['updated_at']),
                'deleted_at' => $deleted ?? ($retire ? now()->toDateTimeString() : null),
            ];
            $i->map('website_client', (int) $r['id'], (int) $r['id']);
            foreach (['template' => 'favourite_templates', 'event' => 'favourite_events'] as $kind => $column) {
                foreach (array_values(array_unique(array_filter(array_map('strval', Import::list($r[$column]))))) as $position => $ref) {
                    $favourites[] = ['client_id' => $r['id'], 'kind' => $kind, 'ref' => mb_substr($ref, 0, 64), 'sort_order' => $position,
                        'created_at' => Import::at($r['updated_at']), 'updated_at' => Import::at($r['updated_at'])];
                }
            }
        }
        $i->put('website_clients', 'clients', $out, count($rows));
        $i->link('website_clients.favourite_*', 'client_favourites', $favourites, count($favourites));
    }

    private function vendorClients(Import $i): void
    {
        $offset = (int) ($i->has('website_clients') ? DB::connection('legacy')->table('website_clients')->max('id') : 0);
        $vendors = DB::table('vendors')->pluck('id')->flip()->all();
        $tiers = DB::table('plan_types')->whereNull('deleted_at')->get(['id', 'name']);
        $tierIds = $tiers->pluck('id')->flip()->all();
        $tierByName = $tiers->mapWithKeys(static fn ($t): array => [mb_strtolower(trim((string) $t->name)) => $t->id])->all();

        $rows = $i->rows('vendor_clients');
        $out = [];
        foreach ($rows as $r) {
            if (! isset($vendors[(int) $r['vendor_id']])) {
                $i->note("vendor client {$r['id']}: vendor {$r['vendor_id']} does not exist, skipped");

                continue;
            }
            $id = $offset + (int) $r['id'];
            $deleted = Import::at($r['deleted_at']);
            $email = $r['email'] === null || $r['email'] === '' ? null : mb_strtolower(trim((string) $r['email']));
            $mobile = Phone::digits($r['mobile']) ?: null;
            $retire = $this->retire($i, (int) $r['vendor_id'], $email, $deleted === null, "vendor client {$r['id']}");

            // The vendor's tier: by id when v1 kept one, else by its name.
            $label = mb_strtolower(trim((string) $r['plan']));
            $tier = isset($tierIds[(int) $r['subscription_id']]) ? (int) $r['subscription_id'] : self::tier($tierByName, $label);
            if ($tier === null && $label !== '') {
                $i->note("vendor client {$r['id']}: tier \"{$r['plan']}\" matches no tier, left empty");
            }

            $out[] = [
                'id' => $id, 'vendor_id' => $r['vendor_id'], 'code' => $r['client_id'], 'source' => 'vendor', 'provider_id' => null,
                'name' => $r['name'], 'company_name' => null, 'bio' => null, 'email' => $email, 'dial_code' => null, 'mobile' => $mobile,
                'password' => $r['password'], 'avatar_url' => $i->file($r['profile_pic'], 'clients', "vendor client {$r['id']}"),
                'address' => $r['address'], 'country' => $r['country'], 'state' => $r['state'], 'district' => $r['district'], 'city' => $r['city'],
                'locality' => $r['locality'], 'pincode' => $r['pincode'], 'plan_id' => null, 'plan_type_id' => $tier,
                'registration_type' => $r['registration_type'] === 'guest' ? 'guest' : 'client',
                'newsletter_opt_in' => $r['client_type'] === 'subscribed' ? 1 : 0, 'login_access' => Import::bit($r['login_access'], 0),
                'send_credentials_to_email' => Import::bit($r['send_credentials_to_email'], 0), 'email_verified' => 0, 'mobile_verified' => 0,
                'otp_hash' => null, 'otp_expires_at' => null, 'otp_attempts' => 0, 'is_active' => Import::bit($r['is_active']),
                'last_login_at' => null, 'password_changed_at' => Import::at($r['password_changed_at']), 'created_by' => null,
                'created_at' => Import::at($r['created_at']), 'updated_at' => Import::at($r['updated_at']),
                'deleted_at' => $deleted ?? ($retire ? now()->toDateTimeString() : null),
            ];
            $i->map('vendor_client', (int) $r['id'], $id);
        }
        $i->put('vendor_clients', 'clients', $out, count($rows));
    }

    /** Devices, preferences, notification switches, 2FA, the vendor's newsletter sign-ups. */
    private function satellites(Import $i): void
    {
        $client = static fn (Import $i, mixed $old): ?int => $i->id('website_client', $old);

        $rows = $i->rows('client_device_tokens');
        $seen = [];
        $out = [];
        foreach ($rows as $r) {
            $id = $client($i, $r['website_client_id']);
            if ($id === null || isset($seen[$r['token']])) {
                continue;
            }
            $seen[$r['token']] = true;
            $out[] = ['id' => $r['id'], 'client_id' => $id, 'token' => $r['token'], 'platform' => $r['platform'], 'device_name' => $r['device_name'],
                'app_version' => $r['app_version'], 'is_active' => Import::bit($r['is_active']), 'disabled_reason' => $r['disabled_reason'],
                'last_seen_at' => Import::at($r['last_seen_at']), 'created_at' => Import::at($r['created_at']), 'updated_at' => Import::at($r['updated_at'])];
        }
        $i->put('client_device_tokens', 'client_devices', $out, count($rows));

        $rows = $i->rows('client_preferences');
        $copy = ['language_code', 'date_format', 'time_zone', 'theme', 'default_landing', 'items_per_page', 'compact_mode', 'auto_save', 'show_tips',
            'emails_disabled', 'in_app_disabled'];
        $i->put('client_preferences', 'client_preferences', array_values(array_filter(array_map(static fn (array $r): ?array => ($id = $client($i, $r['website_client_id'])) === null ? null : [
            'id' => $r['id'], 'client_id' => $id, ...array_intersect_key($r, array_flip($copy)),
            'dnd_starts_at' => Import::at($r['dnd_starts_at']), 'dnd_ends_at' => Import::at($r['dnd_ends_at']),
            'created_at' => Import::at($r['created_at']), 'updated_at' => Import::at($r['updated_at']),
        ], $rows))), count($rows));

        $rows = $i->rows('client_notification_prefs');
        $i->put('client_notification_prefs', 'client_notification_prefs', array_values(array_filter(array_map(static fn (array $r): ?array => ($id = $client($i, $r['website_client_id'])) === null ? null : [
            'id' => $r['id'], 'client_id' => $id, 'channel' => $r['channel'], 'type' => $r['type'], 'enabled' => Import::bit($r['enabled']),
            'frequency' => $r['frequency'], 'sound' => $r['sound'], 'created_at' => Import::at($r['created_at']), 'updated_at' => Import::at($r['updated_at']),
        ], $rows))), count($rows));

        $rows = $i->rows('client_two_factor');
        $i->put('client_two_factor', 'client_two_factor', array_values(array_filter(array_map(static fn (array $r): ?array => ($id = $client($i, $r['website_client_id'])) === null ? null : [
            'id' => $r['id'], 'client_id' => $id, 'secret' => Import::encrypt($r['secret']), 'is_enabled' => Import::bit($r['is_enabled'], 0),
            'confirmed_at' => Import::at($r['confirmed_at']), 'last_used_at' => Import::at($r['last_used_at']), 'last_used_counter' => $r['last_used_counter'],
            'created_at' => Import::at($r['created_at']), 'updated_at' => Import::at($r['updated_at']),
        ], $rows))), count($rows));

        $rows = $i->rows('client_backup_codes');
        $i->put('client_backup_codes', 'client_backup_codes', array_values(array_filter(array_map(static fn (array $r): ?array => ($id = $client($i, $r['website_client_id'])) === null ? null : [
            'id' => $r['id'], 'client_id' => $id, 'code_hash' => $r['code_hash'], 'used_at' => Import::at($r['used_at']),
            'created_at' => Import::at($r['created_at']), 'updated_at' => Import::at($r['updated_at']),
        ], $rows))), count($rows));

        $vendors = DB::table('vendors')->pluck('id')->flip()->all();
        $rows = $i->rows('vendor_subscribers');
        $i->put('vendor_subscribers', 'newsletter_subscribers', array_values(array_map(static fn (array $r): array => [
            'id' => $r['id'], 'vendor_id' => $r['vendor_id'], 'email' => mb_strtolower(trim((string) $r['email'])), 'is_active' => Import::bit($r['is_active']),
            'created_at' => Import::at($r['created_at']), 'updated_at' => Import::at($r['updated_at']), 'deleted_at' => Import::at($r['deleted_at']),
        ], array_filter($rows, static fn (array $r): bool => isset($vendors[(int) $r['vendor_id']])))), count($rows));
    }

    /**
     * A tier by its name; a label that is part of exactly one tier's name
     * ("gold" → "Sample Gold") counts as that tier.
     *
     * @param  array<string, int>  $byName  lower-case name => id
     */
    public static function tier(array $byName, string $label): ?int
    {
        if ($label === '') {
            return null;
        }
        if (isset($byName[$label])) {
            return $byName[$label];
        }
        $partial = array_values(array_filter($byName, static fn (string $name): bool => str_contains($name, $label), ARRAY_FILTER_USE_KEY));

        return count($partial) === 1 ? $partial[0] : null;
    }

    /** True when this live client's e-mail is already another live client's in the vendor. */
    private function retire(Import $i, int $vendor, ?string $email, bool $live, string $who): bool
    {
        if (! $live) {
            return false;
        }
        // Only the e-mail is one-per-vendor; clients may share a mobile number.
        if ($email !== null && isset($this->taken["{$vendor}|{$email}"])) {
            $i->note("{$who}: e-mail {$email} already belongs to {$this->taken["{$vendor}|{$email}"]}; kept but retired");

            return true;
        }
        if ($email !== null) {
            $this->taken["{$vendor}|{$email}"] = $who;
        }

        return false;
    }
}
