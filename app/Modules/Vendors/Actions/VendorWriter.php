<?php

declare(strict_types=1);

namespace App\Modules\Vendors\Actions;

use App\Core\Activity\ActivityLogger;
use App\Core\Approvals\ApprovalHandler;
use App\Core\Auth\SessionTokens;
use App\Core\Contracts\ObjectStorage;
use App\Core\Exceptions\ApiException;
use App\Core\Tenancy\Tenancy;
use App\Modules\Catalog\Models\PlanType;
use App\Modules\Vendors\Enums\VendorStatus;
use App\Modules\Vendors\Http\Resources\V1\VendorResource;
use App\Modules\Vendors\Models\Vendor;
use App\Modules\Vendors\Models\VendorUser;
use App\Modules\Vendors\Support\VendorPassword;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Vendor accounts: the admin's vendor screens (create, edit, status, delete)
 * and the owner's own profile / About screens.
 *
 * Input uses v1 keys. Business keys go to the vendors row, the owner's
 * person and login to the owner vendor_user:
 *   company_logo->logo, company_contact->phone, company_email->email,
 *   company_address->address, alt_contact->alt_phone, acc_no->account_number,
 *   acc_type->account_type, city_id->district_id, pincode_id->city_id,
 *   membership (plan type name)->plan_type_id;
 *   owner: name, email, contact->mobile, profile->profile_pic, address, password.
 */
final class VendorWriter implements ApprovalHandler
{
    /** v1 key => vendors column */
    private const VENDOR_KEYS = [
        'company_name' => 'company_name', 'company_logo' => 'logo', 'short_description' => 'short_description',
        'about_us' => 'about_us', 'company_information' => 'company_information', 'company_email' => 'email',
        'company_contact' => 'phone', 'website' => 'website', 'company_address' => 'address',
        'contact_mode' => 'contact_mode', 'alt_email' => 'alt_email', 'alt_contact' => 'alt_phone',
        'alt_address' => 'alt_address', 'country_id' => 'country_id', 'state_id' => 'state_id',
        'city_id' => 'district_id', 'pincode_id' => 'city_id', 'latitude' => 'latitude', 'longitude' => 'longitude',
        'reg_no' => 'reg_no', 'gst_no' => 'gst_no', 'landline' => 'landline', 'copywrite' => 'copywrite',
        'poweredby' => 'poweredby', 'website_enabled' => 'website_enabled', 'status' => 'status',
        'bank_name' => 'bank_name', 'acc_no' => 'account_number', 'ifsc_code' => 'ifsc_code',
        'acc_type' => 'account_type', 'branch' => 'branch', 'bank_logo' => 'bank_logo',
    ];

    /** v1 key => owner vendor_users column */
    private const OWNER_KEYS = ['name' => 'name', 'email' => 'email', 'contact' => 'mobile', 'profile' => 'profile_pic', 'address' => 'address'];

    /** What the owner may change about themselves (v1 updateProfile whitelist). */
    private const SELF_EDITABLE = [
        'name', 'contact', 'alt_contact', 'address', 'alt_address', 'alt_email', 'profile',
        'company_name', 'company_logo', 'company_contact', 'company_address', 'about_us', 'short_description',
        'company_email', 'website', 'bank_name', 'acc_no', 'ifsc_code', 'acc_type', 'branch', 'contact_mode',
    ];

    public function __construct(
        private readonly ObjectStorage $storage,
        private readonly SessionTokens $tokens,
        private readonly ActivityLogger $activity,
    ) {}

    /** @param array<string, mixed> $input */
    public function create(array $input, ?Model $actor): Vendor
    {
        $this->requireLocation($input);
        $email = mb_strtolower(trim((string) ($input['email'] ?? '')));
        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw ApiException::badRequest('Enter a valid email address');
        }
        if (trim((string) ($input['company_name'] ?? '')) === '' || trim((string) ($input['name'] ?? '')) === '') {
            throw ApiException::badRequest('Company name and contact name are required');
        }
        $this->assertEmailFree($email);
        VendorPassword::assertValid($input['password'] ?? null);

        return DB::transaction(function () use ($input, $email, $actor): Vendor {
            $vendor = Vendor::query()->create([
                ...$this->vendorColumns($input),
                'status' => $input['status'] ?? VendorStatus::Active->value,
            ]);
            Tenancy::bypass(fn () => VendorUser::query()->create([
                ...$this->ownerColumns($input, $vendor),
                'vendor_id' => $vendor->id,
                'is_owner' => true,
                'email' => $email,
                'password' => $input['password'],
                'emp_id' => null,
            ]));

            $this->activity->log('vendors', 'create', "Created vendor: {$vendor->company_name}", $vendor, causer: $actor);

            return $vendor;
        });
    }

    /** @param array<string, mixed> $input */
    public function update(Vendor $vendor, array $input, ?Model $actor): Vendor
    {
        $this->requireLocation($input);
        $owner = $vendor->owner ?? throw ApiException::notFound('Vendor not found');
        if (isset($input['email']) && mb_strtolower(trim((string) $input['email'])) !== $owner->email) {
            $this->assertEmailFree((string) $input['email'], $owner->id);
        }
        $password = $input['password'] ?? null;
        if ($password !== null && $password !== '') {
            VendorPassword::assertValid($password);
        }

        return DB::transaction(function () use ($vendor, $owner, $input, $password, $actor): Vendor {
            $planBefore = $vendor->getAttribute('plan_type_id');
            $vendor->fill($this->vendorColumns($input))->save();

            $owner->fill($this->ownerColumns($input, $vendor));
            if ($password !== null && $password !== '') {
                $owner->forceFill(['password' => $password, 'password_changed_at' => now()]);
            }
            $owner->save();

            // v1 forced a fresh login when the admin changed the plan or the password.
            if (($password !== null && $password !== '') || $planBefore !== $vendor->getAttribute('plan_type_id')) {
                $this->tokens->revokeAll($owner);
            }

            $this->activity->log('vendors', 'update', "Updated vendor: {$vendor->company_name}", $vendor, causer: $actor);

            return $vendor;
        });
    }

    public function setStatus(Vendor $vendor, mixed $status, ?Model $actor): Vendor
    {
        $value = VendorStatus::tryFrom(is_string($status) ? strtolower($status) : '')
            ?? throw ApiException::badRequest('Status must be active or inactive');
        $vendor->forceFill(['status' => $value])->save();

        if ($value === VendorStatus::Inactive) {
            foreach ($vendor->users as $user) {
                $this->tokens->revokeAll($user);
            }
        }
        $this->activity->log('vendors', 'update_status', "Vendor {$vendor->company_name} set {$value->value}", $vendor, causer: $actor);

        return $vendor;
    }

    public function delete(Vendor $vendor, ?Model $actor): void
    {
        if ($vendor->isHouse()) {
            throw ApiException::badRequest('The house vendor cannot be deleted');
        }

        DB::transaction(function () use ($vendor, $actor): void {
            foreach ($vendor->users as $user) {
                $this->tokens->revokeAll($user);
                $user->delete();
            }
            $vendor->delete();
            $this->activity->log('vendors', 'delete', "Deleted vendor: {$vendor->company_name}", $vendor, causer: $actor);
        });
    }

    /**
     * The owner edits the business and themselves (v1 updateProfile / updateAbout).
     *
     * @param  array<string, mixed>  $input
     */
    public function updateOwnProfile(VendorUser $owner, array $input): Vendor
    {
        $input = array_intersect_key($input, array_flip(self::SELF_EDITABLE));
        foreach (['acc_type', 'contact_mode'] as $optional) {
            if (($input[$optional] ?? null) === '') {
                unset($input[$optional]);
            }
        }
        $this->validateContacts($input);

        $vendor = $owner->vendor;
        DB::transaction(function () use ($vendor, $owner, $input): void {
            $vendor->fill($this->vendorColumns($input))->save();
            $owner->fill($this->ownerColumns($input, $vendor))->save();
        });

        $this->activity->log('vendor_profile', 'update_profile', 'Profile updated successfully', $vendor, causer: $owner);

        return $vendor;
    }

    // ---- ApprovalHandler (module vendors) ---------------------------------

    public function snapshot(int $id): ?array
    {
        $vendor = Vendor::query()->with(['owner', 'planType'])->find($id);

        return $vendor === null ? null : (new VendorResource($vendor))->resolve();
    }

    /** A vendor created under approval exists at once, inactive, until approved. */
    public function createPending(array $payload, Model $requester): int
    {
        return $this->create([...$payload, 'status' => VendorStatus::Inactive->value], $requester)->id;
    }

    public function apply(string $action, ?int $resourceId, array $payload, Model $requester): ?Model
    {
        $vendor = $resourceId === null ? null : Vendor::query()->find($resourceId);
        if ($vendor === null) {
            throw ApiException::notFound('Vendor not found');
        }

        return match ($action) {
            'create' => $this->setStatus($vendor, VendorStatus::Active->value, $requester),
            'delete' => tap(null, fn () => $this->delete($vendor, $requester)),
            default => $this->update($vendor, $payload, $requester),
        };
    }

    public function discard(int $resourceId, Model $reviewer): void
    {
        $vendor = Vendor::query()->find($resourceId);
        if ($vendor !== null && $vendor->status === VendorStatus::Inactive) {
            DB::transaction(static function () use ($vendor): void {
                VendorUser::query()->withoutGlobalScopes()->where('vendor_id', $vendor->id)->forceDelete();
                $vendor->forceDelete();
            });
        }
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function vendorColumns(array $input): array
    {
        $columns = [];
        foreach (self::VENDOR_KEYS as $key => $column) {
            if (array_key_exists($key, $input)) {
                $columns[$column] = $input[$key] === '' ? null : $input[$key];
            }
        }
        foreach (['email', 'alt_email'] as $email) {
            if (isset($columns[$email])) {
                $columns[$email] = mb_strtolower(trim((string) $columns[$email]));
            }
        }
        if (array_key_exists('website_enabled', $columns)) {
            $columns['website_enabled'] = (bool) filter_var($columns['website_enabled'], FILTER_VALIDATE_BOOL);
        }
        if (array_key_exists('contact_mode', $columns) && ! in_array($columns['contact_mode'], ['default', 'alternative'], true)) {
            throw ApiException::badRequest('Contact mode must be default or alternative');
        }
        foreach (['logo', 'bank_logo'] as $image) {
            if (isset($columns[$image])) {
                $columns[$image] = $this->storage->storeDataUri((string) $columns[$image], 'vendors', $image);
            }
        }
        if (array_key_exists('membership', $input)) {
            $columns['plan_type_id'] = $this->planTypeId($input['membership']);
        }

        return $columns;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function ownerColumns(array $input, Vendor $vendor): array
    {
        $columns = [];
        foreach (self::OWNER_KEYS as $key => $column) {
            if (array_key_exists($key, $input) && $key !== 'email') {
                $columns[$column] = $input[$key] === '' ? null : $input[$key];
            }
        }
        if (isset($input['email']) && $input['email'] !== '') {
            $columns['email'] = mb_strtolower(trim((string) $input['email']));
        }
        if (isset($columns['profile_pic'])) {
            $columns['profile_pic'] = $this->storage->storeDataUri((string) $columns['profile_pic'], 'vendors', 'profile');
        }
        if (! isset($columns['name']) && ! $vendor->owner()->exists()) {
            $columns['name'] = (string) ($input['company_name'] ?? 'Owner');
        }

        return $columns;
    }

    /** The plan type a membership NAME refers to (case-insensitive; custom tier of this vendor first). */
    private function planTypeId(mixed $name): ?int
    {
        $name = mb_strtolower(trim(is_scalar($name) ? (string) $name : ''));
        if ($name === '') {
            return null;
        }
        $type = PlanType::query()->whereRaw('lower(name) = ?', [$name])->orderByDesc('is_custom')->first();

        return $type->id ?? throw ApiException::badRequest('Select a valid subscription plan');
    }

    /** @param array<string, mixed> $input */
    private function requireLocation(array $input): void
    {
        foreach (['country_id' => 'Country', 'state_id' => 'State', 'city_id' => 'District', 'pincode_id' => 'City'] as $key => $label) {
            if (! isset($input[$key]) || $input[$key] === '') {
                throw ApiException::badRequest("{$label} is required");
            }
        }
    }

    /** @param array<string, mixed> $input v1 validateVendorContactFields */
    private function validateContacts(array &$input): void
    {
        foreach (['company_email', 'alt_email'] as $field) {
            if (isset($input[$field]) && trim((string) $input[$field]) !== '') {
                if (! preg_match('/^[^\s@]+@[^\s@]{2,}\.[^\s@]{2,}$/', trim((string) $input[$field]))) {
                    throw ApiException::badRequest('Enter a valid email address');
                }
            }
        }
        foreach (['company_contact', 'contact', 'alt_contact'] as $field) {
            if (isset($input[$field]) && trim((string) $input[$field]) !== '') {
                $value = trim((string) $input[$field]);
                $digits = (string) preg_replace('/\D/', '', $value);
                if (strlen($digits) < 7 || strlen($digits) > 15 || ! preg_match('/^\+?[\d\s\-(). ]{7,20}$/', $value)) {
                    throw ApiException::badRequest('Enter a valid mobile number');
                }
                $input[$field] = $value;
            }
        }
    }

    private function assertEmailFree(string $email, ?int $exceptId = null): void
    {
        $email = mb_strtolower(trim($email));
        $taken = Tenancy::bypass(static fn (): bool => VendorUser::query()->where('email', $email)
            ->when($exceptId !== null, static fn ($q) => $q->whereKeyNot($exceptId))->exists());
        if ($taken) {
            throw ApiException::conflict('A vendor with this email already exists');
        }
    }
}
