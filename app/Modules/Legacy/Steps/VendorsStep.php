<?php

declare(strict_types=1);

namespace App\Modules\Legacy\Steps;

use App\Modules\Legacy\Support\Import;
use Illuminate\Support\Facades\DB;

/**
 * Vendors: the business (vendors, ids kept) and its logins (vendor_users).
 *
 *  - The owner's login sat on the vendors row in v1; it becomes the vendor
 *    user with the vendor's own id (is_owner).
 *  - Staff become vendor users numbered after the last vendor.
 *  - A login e-mail may be used once among live logins: a later duplicate is
 *    kept but retired (deleted) and reported.
 */
final class VendorsStep implements Step
{
    public function name(): string
    {
        return 'vendors';
    }

    public function run(Import $import): void
    {
        $i = $import;
        $known = static fn (string $table): array => DB::table($table)->pluck('id')->flip()->all();
        [$countries, $states, $districts, $cities] = [$known('countries'), $known('states'), $known('districts'), $known('cities')];
        $ref = static fn (array $set, mixed $id): ?int => is_numeric($id) && isset($set[(int) $id]) ? (int) $id : null;
        $tiers = DB::table('plan_types')->whereNull('deleted_at')->pluck('id', 'name')->mapWithKeys(static fn ($id, $name): array => [mb_strtolower(trim((string) $name)) => $id])->all();

        $vendors = $i->rows('vendors');
        $i->put('vendors', 'vendors', array_map(function (array $r) use ($i, $tiers, $ref, $countries, $states, $districts, $cities): array {
            $membership = mb_strtolower(trim((string) $r['membership']));
            $tier = ClientsStep::tier($tiers, $membership);
            if ($membership !== '' && $tier === null) {
                $i->note("vendor {$r['id']}: tier \"{$r['membership']}\" matches no tier, left empty");
            }

            return [
                'id' => $r['id'], 'company_id' => $r['company_id'] ?? 1, 'company_name' => $r['company_name'], 'logo' => $r['company_logo'],
                'short_description' => $r['short_description'], 'about_us' => $r['about_us'], 'company_information' => $r['company_information'],
                'email' => $r['company_email'], 'phone' => $r['company_contact'], 'website' => $r['website'], 'address' => $r['company_address'],
                'contact_mode' => $r['contact_mode'] === 'alternative' ? 'alternative' : 'default', 'alt_email' => $r['alt_email'],
                'alt_phone' => $r['alt_contact'], 'alt_address' => $r['alt_address'], 'country_id' => $ref($countries, $r['country_id']),
                'state_id' => $ref($states, $r['state_id']), 'district_id' => $ref($districts, $r['city_id']), 'city_id' => $ref($cities, $r['pincode_id']),
                'plan_type_id' => $tier, 'latitude' => $r['latitude'], 'longitude' => $r['longitude'], 'reg_no' => $r['reg_no'], 'gst_no' => $r['gst_no'],
                'landline' => $r['landline'], 'copywrite' => $r['copywrite'], 'poweredby' => $r['poweredby'], 'website_enabled' => Import::bit($r['website_enabled'], 0),
                'status' => $r['status'] === 'inactive' ? 'inactive' : 'active', 'bank_name' => $r['bank_name'], 'account_number' => Import::encrypt($r['acc_no']),
                'ifsc_code' => $r['ifsc_code'], 'account_type' => $r['acc_type'], 'branch' => $r['branch'], 'bank_logo' => $r['bank_logo'],
                'created_at' => Import::at($r['created_at']), 'updated_at' => Import::at($r['updated_at']), 'deleted_at' => Import::at($r['deleted_at']),
            ];
        }, $vendors), count($vendors));

        // Custom tiers belong to a vendor (Catalog recorded which).
        foreach ($i->mapOf('plan_type_vendor') as $tier => $vendor) {
            if (DB::table('vendors')->where('id', $vendor)->exists()) {
                DB::table('plan_types')->where('id', $tier)->update(['vendor_id' => $vendor]);
            }
        }

        $taken = [];
        $login = function (string $email, bool $live, string $who) use ($i, &$taken): bool {
            $key = mb_strtolower(trim($email));
            if (! $live) {
                return false;
            }
            if (isset($taken[$key])) {
                $i->note("{$who}: e-mail {$key} is already the login of {$taken[$key]}; kept but retired");

                return true;
            }
            $taken[$key] = $who;

            return false;
        };

        $owners = [];
        foreach ($vendors as $r) {
            $deleted = Import::at($r['deleted_at']);
            $retire = $login((string) $r['email'], $deleted === null, "vendor {$r['id']} owner");
            $owners[] = [
                'id' => $r['id'], 'vendor_id' => $r['id'], 'is_owner' => 1, 'department_id' => null, 'emp_id' => null, 'name' => $r['name'],
                'email' => mb_strtolower(trim((string) $r['email'])), 'mobile' => Import::text($r['contact'], 20), 'password' => $r['password'],
                'profile_pic' => $i->file($r['profile'], 'vendors', "vendor {$r['id']} owner"), 'designation' => null, 'dob' => null, 'doj' => null, 'dor' => null,
                'address' => $r['address'], 'country' => null, 'state' => null, 'district' => null, 'city' => null, 'locality' => null, 'pincode' => null,
                'login_access' => 1, 'is_active' => $r['status'] === 'inactive' ? 0 : 1, 'work_status' => 'active', 'last_login_at' => null,
                'password_changed_at' => Import::at($r['password_changed_at']), 'remember_token' => null,
                'created_at' => Import::at($r['created_at']), 'updated_at' => Import::at($r['updated_at']),
                'deleted_at' => $deleted ?? ($retire ? now()->toDateTimeString() : null),
            ];
            $i->map('vendor_owner', (int) $r['id'], (int) $r['id']);
        }
        $i->put('vendors (owner login)', 'vendor_users', $owners, count($vendors));

        $offset = (int) max(array_map(static fn (array $r): int => (int) $r['id'], $vendors) ?: [0]);
        $staff = $i->rows('vendor_staff');
        $vendorIds = array_flip(array_map(static fn (array $r): int => (int) $r['id'], $vendors));
        $out = [];
        foreach ($staff as $r) {
            if (! isset($vendorIds[(int) $r['vendor_id']])) {
                $i->note("staff {$r['id']}: vendor {$r['vendor_id']} does not exist, skipped");

                continue;
            }
            $id = $offset + (int) $r['id'];
            $deleted = Import::at($r['deleted_at']);
            $retire = $login((string) $r['email'], $deleted === null, "staff {$r['id']}");
            $out[] = [
                'id' => $id, 'vendor_id' => $r['vendor_id'], 'is_owner' => 0, 'department_id' => null, 'emp_id' => $r['emp_id'], 'name' => $r['name'],
                'email' => mb_strtolower(trim((string) $r['email'])), 'mobile' => Import::text($r['mobile'], 20), 'password' => $r['password'],
                'profile_pic' => $i->file($r['profile_pic'], 'vendor-staff', "staff {$r['id']}"), 'designation' => $r['designation'],
                'dob' => Import::at($r['dob']), 'doj' => Import::at($r['doj']), 'dor' => Import::at($r['dor']), 'address' => $r['address'],
                'country' => $r['country'], 'state' => $r['state'], 'district' => $r['district'], 'city' => $r['city'], 'locality' => $r['locality'],
                'pincode' => $r['pincode'], 'login_access' => Import::bit($r['login_access']), 'is_active' => Import::bit($r['is_active']),
                'work_status' => $r['work_status'] ?? 'active', 'last_login_at' => null, 'password_changed_at' => null, 'remember_token' => null,
                'created_at' => Import::at($r['created_at']), 'updated_at' => Import::at($r['updated_at']),
                'deleted_at' => $deleted ?? ($retire ? now()->toDateTimeString() : null),
            ];
            $i->map('vendor_staff', (int) $r['id'], $id);
            if ($r['role_id'] !== null) {
                $i->map('staff_role', (int) $r['id'], (int) $r['role_id']);
            }
        }
        $i->put('vendor_staff', 'vendor_users', $out, count($staff));

        $rows = $i->rows('vendor_departments');
        $i->put('vendor_departments', 'vendor_departments', array_map(static fn (array $r): array => [
            'id' => $r['id'], 'vendor_id' => $r['vendor_id'], 'name' => $r['name'], 'description' => $r['description'], 'is_active' => Import::bit($r['is_active']),
            'created_at' => Import::at($r['created_at']), 'updated_at' => Import::at($r['updated_at']), 'deleted_at' => Import::at($r['deleted_at']),
        ], array_values(array_filter($rows, static fn (array $r): bool => isset($vendorIds[(int) $r['vendor_id']])))), count($rows));
    }
}
