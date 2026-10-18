<?php

declare(strict_types=1);

use App\Core\Http\ApiResponse;
use App\Core\Queries\ListQuery;
use App\Core\Tenancy\CurrentCompany;
use App\Core\Tenancy\Tenancy;
use App\Modules\Vendors\Models\Vendor;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/** A list over vendors, the only listable table in Phase 1. */
function vendorList(): ListQuery
{
    return new class extends ListQuery
    {
        protected array $searchable = ['company_name', 'email'];

        protected array $sortable = ['created_at', 'company_name'];

        protected string $defaultSort = 'company_name';

        protected string $defaultDirection = 'asc';

        protected bool $filtersActive = false;

        protected function query(Request $request): Builder
        {
            return Vendor::query();
        }
    };
}

beforeEach(function () {
    seedHouse();
    Tenancy::bypass(function () {
        foreach (['Charlie Events', 'alpha decor', 'Bravo Weddings'] as $name) {
            Vendor::factory()->create(['company_id' => 1, 'company_name' => $name]);
        }
    });
    app(CurrentCompany::class)->set(1);
});

function names($page): array
{
    return collect($page->items())->pluck('company_name')->all();
}

it('uses the module default sort when none is requested', function () {
    $page = vendorList()->paginate(Request::create('/x'));

    expect(names($page))->toBe(['alpha decor', 'Bravo Weddings', 'Charlie Events', 'House Vendor']);
});

it('searches across the searchable columns', function () {
    $page = vendorList()->paginate(Request::create('/x', 'GET', ['search' => 'wed']));

    expect(names($page))->toBe(['Bravo Weddings']);
});

it('sorts descending unless asc is asked for, in any case', function () {
    $desc = vendorList()->paginate(Request::create('/x', 'GET', ['sort_by' => 'company_name', 'sort_order' => 'DESC']));
    $asc = vendorList()->paginate(Request::create('/x', 'GET', ['sort_by' => 'company_name', 'sort_order' => 'aSc']));

    expect(names($desc)[0])->toBe('House Vendor')
        ->and(names($asc)[0])->toBe('alpha decor');
});

it('falls back to created_at for an unknown sort column, as v1 did', function () {
    $page = vendorList()->paginate(Request::create('/x', 'GET', ['sort_by' => 'password', 'sort_order' => 'asc']));

    expect($page->total())->toBe(4);
});

it('clamps limit to 1..1000 and defaults to 10', function () {
    expect(vendorList()->paginate(Request::create('/x', 'GET', ['limit' => '0']))->perPage())->toBe(10)
        ->and(vendorList()->paginate(Request::create('/x', 'GET', ['limit' => '-5']))->perPage())->toBe(1)
        ->and(vendorList()->paginate(Request::create('/x', 'GET', ['limit' => '5000']))->perPage())->toBe(1000)
        ->and(vendorList()->paginate(Request::create('/x', 'GET', ['limit' => 'abc']))->perPage())->toBe(10);
});

it('pages through results and reports v1 pagination', function () {
    $page = vendorList()->paginate(Request::create('/x', 'GET', ['limit' => '3', 'page' => '2']));

    expect(names($page))->toBe(['House Vendor'])
        ->and(ApiResponse::paginated($page)->getData(true)['pagination'])->toMatchArray([
            'page' => 2, 'limit' => 3, 'totalItems' => 4, 'totalPages' => 2, 'hasNextPage' => false, 'hasPrevPage' => true,
        ]);
});
