<?php

declare(strict_types=1);

use App\Core\Tenancy\BelongsToVendor;
use App\Core\Tenancy\CurrentCompany;
use App\Core\Tenancy\CurrentVendor;
use App\Core\Tenancy\Tenancy;
use App\Core\Tenancy\TenantNotResolved;
use App\Modules\Platform\Models\Company;
use App\Modules\Vendors\Models\Vendor;
use Illuminate\Database\Eloquent\Model;

it('refuses to query tenant data before the tenant is known', function () {
    Vendor::query()->get();
})->throws(TenantNotResolved::class);

it('filters platform rows to the current company', function () {
    seedHouse();
    $otherCompany = Tenancy::bypass(fn () => Company::factory()->create());
    Tenancy::bypass(fn () => Vendor::factory()->count(2)->create(['company_id' => $otherCompany->id]));

    app(CurrentCompany::class)->set((int) config('tenancy.house_company_id'));

    expect(Vendor::query()->pluck('id')->all())->toBe([Vendor::houseId()]);
});

it('sees every tenant only inside an explicit bypass', function () {
    seedHouse();
    $otherCompany = Tenancy::bypass(fn () => Company::factory()->create());
    Tenancy::bypass(fn () => Vendor::factory()->create(['company_id' => $otherCompany->id]));

    expect(Tenancy::bypass(fn () => Vendor::query()->count()))->toBe(2)
        ->and(Tenancy::isBypassed())->toBeFalse();
});

it('stamps the current company on new platform rows', function () {
    seedHouse();
    app(CurrentCompany::class)->set((int) config('tenancy.house_company_id'));

    $vendor = Vendor::query()->create(['company_name' => 'New Vendor']);

    expect($vendor->company_id)->toBe((int) config('tenancy.house_company_id'));
});

it('runs work as another tenant and restores the previous one', function () {
    $vendor = app(CurrentVendor::class);
    $vendor->set(1);

    $inside = $vendor->runAs(7, fn () => Tenancy::vendorId());

    expect($inside)->toBe(7)->and(Tenancy::vendorId())->toBe(1);
});

it('scopes vendor-layer models to the current vendor in SQL', function () {
    $probe = new class extends Model
    {
        use BelongsToVendor;

        protected $table = 'tenancy_probe';
    };

    app(CurrentVendor::class)->set(42);
    $query = $probe->newQuery();

    expect($query->toSql())->toContain('`tenancy_probe`.`vendor_id` = ?')
        ->and($query->getBindings())->toBe([42]);
});

it('returns the house vendor from configuration', function () {
    seedHouse();
    seedHouse(); // idempotent

    expect(Vendor::house()->id)->toBe(1)
        ->and(Vendor::house()->isHouse())->toBeTrue()
        ->and(Tenancy::bypass(fn () => Vendor::query()->count()))->toBe(1);
});
