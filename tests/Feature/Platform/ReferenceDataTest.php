<?php

declare(strict_types=1);

use App\Modules\Platform\Models\City;
use App\Modules\Platform\Models\Country;
use App\Modules\Platform\Models\Currency;
use App\Modules\Platform\Models\District;
use App\Modules\Platform\Models\Language;
use App\Modules\Platform\Models\State;

/*
| Languages, currencies and the location tree.
*/

beforeEach(function () {
    seedHouse();
});

function india(): array
{
    $country = Country::query()->create(['name' => 'India', 'code' => 'IN']);
    $state = State::query()->create(['country_id' => $country->id, 'name' => 'Tamil Nadu', 'code' => 'TN']);
    $district = District::query()->create(['state_id' => $state->id, 'name' => 'Chennai']);

    return [$country, $state, $district];
}

it('serves the location lists without login, cities keyed by their district as city_id', function () {
    [, $state, $district] = india();
    City::query()->create(['district_id' => $district->id, 'name' => 'Adyar', 'pincode' => '600020']);

    $this->getJson('/api/v1/locations/countries')->assertOk()->assertJsonPath('data.0.code', 'IN');
    $this->getJson("/api/v1/locations/states/{$state->country_id}")->assertJsonPath('data.0.country.code', 'IN');
    $this->getJson("/api/v1/locations/districts?state_id={$state->id}")->assertJsonPath('data.0.name', 'Chennai');
    $this->getJson("/api/v1/locations/cities/{$district->id}")
        ->assertOk()
        ->assertJsonPath('data.0.city_id', $district->id)
        ->assertJsonPath('data.0.pincode', '600020')
        ->assertJsonPath('data.0.district.state.country.name', 'India');
    $this->getJson("/api/v1/locations/cities?state_id={$state->id}")->assertJsonPath('pagination.totalItems', 1);
});

it('creates a city from the v1 body (district as city_id) and refuses a duplicate name in that district', function () {
    [, , $district] = india();
    $token = accessToken(adminUser());

    $this->withToken($token)->postJson('/api/v1/locations/cities', ['name' => 'Adyar', 'cityId' => $district->id, 'pincode' => '600020'])
        ->assertCreated()->assertJsonPath('message', 'City created successfully')->assertJsonPath('data.city.city_id', $district->id);

    $this->withToken($token)->postJson('/api/v1/locations/cities', ['name' => 'Adyar', 'city_id' => $district->id])
        ->assertStatus(400)->assertJsonPath('message', 'A city with the name "Adyar" already exists in this district.');

    $this->withToken($token)->postJson('/api/v1/locations/cities', ['name' => 'Nowhere', 'city_id' => 999999])
        ->assertStatus(400)->assertJsonPath('message', 'Invalid district');
});

it('edits and deletes locations (route default `level` must not swallow the id)', function () {
    [$country, $state, $district] = india();
    $token = accessToken(adminUser());

    $this->withToken($token)->putJson("/api/v1/locations/districts/{$district->id}", ['name' => 'Chennai City'])
        ->assertOk()->assertJsonPath('message', 'District updated successfully')->assertJsonPath('data.district.name', 'Chennai City');
    $this->withToken($token)->putJson("/api/v1/locations/states/{$state->id}", ['name' => 'TN'])
        ->assertOk()->assertJsonPath('data.state.country.code', 'IN');
    $this->withToken($token)->deleteJson("/api/v1/locations/districts/{$district->id}")
        ->assertOk()->assertJsonPath('message', 'District deleted successfully');
    $this->withToken($token)->putJson("/api/v1/locations/countries/{$country->id}", ['name' => 'Bharat'])
        ->assertOk()->assertJsonPath('data.country.name', 'Bharat');
});

it('bulk imports states by country code and districts by state code, reporting skips', function () {
    Country::query()->create(['name' => 'India', 'code' => 'IN']);
    $token = accessToken(adminUser());

    $this->withToken($token)->postJson('/api/v1/locations/states/bulk', ['rows' => [
        ['name' => 'Tamil Nadu', 'code' => 'TN', 'country_code' => 'IN'],
        ['name' => 'Texas', 'code' => 'TX', 'country_code' => 'US'],
    ]])->assertOk()
        ->assertJsonPath('message', 'Imported 1 states (1 skipped)')
        ->assertJsonPath('data.missingCountryCodes', ['US']);

    $this->withToken($token)->postJson('/api/v1/locations/districts/bulk', ['rows' => [
        ['name' => 'Chennai', 'state_code' => 'TN', 'country_code' => 'IN'],
        ['name' => 'Madurai', 'state_code' => 'TN'],
    ]])->assertOk()->assertJsonPath('data.imported', 2);

    $this->withToken($token)->postJson('/api/v1/locations/cities/bulk', ['rows' => [
        ['name' => 'Adyar', 'pincode' => '600020', 'district_name' => 'chennai', 'state_code' => 'TN'],
    ]])->assertOk()->assertJsonPath('data.imported', 1);

    expect(City::query()->first()->district->name)->toBe('Chennai');
});

it('keeps one default language, refuses to delete it, and revives a deleted code', function () {
    $token = accessToken(adminUser());
    $en = Language::query()->create(['name' => 'English', 'code' => 'en', 'is_default' => true]);
    $ta = $this->withToken($token)->postJson('/api/v1/languages', ['name' => 'Tamil', 'code' => 'ta'])->assertCreated()->json('data.language.id');

    $this->withToken($token)->patchJson("/api/v1/languages/{$ta}/default")->assertOk()->assertJsonPath('data.language.is_default', true);
    expect($en->fresh()->is_default)->toBeFalse();

    $this->withToken($token)->deleteJson("/api/v1/languages/{$ta}")->assertStatus(400)->assertJsonPath('message', 'Cannot delete default language');
    $this->withToken($token)->deleteJson("/api/v1/languages/{$en->id}")->assertOk();

    $this->withToken($token)->postJson('/api/v1/languages', ['name' => 'English', 'code' => 'en'])
        ->assertCreated()->assertJsonPath('data.language.id', $en->id);
});

it('serves active languages and currencies without login, default first', function () {
    Language::query()->create(['name' => 'Tamil', 'code' => 'ta']);
    Language::query()->create(['name' => 'English', 'code' => 'en', 'is_default' => true]);
    Language::query()->create(['name' => 'Hidden', 'code' => 'hd', 'is_active' => false]);
    Currency::query()->create(['name' => 'Rupee', 'code' => 'INR', 'symbol' => '₹', 'is_default' => true]);

    $this->getJson('/api/v1/languages/active')->assertOk()->assertJsonCount(2, 'data.languages')->assertJsonPath('data.languages.0.code', 'en');
    $this->getJson('/api/v1/currencies/active')->assertOk()->assertJsonPath('data.currencies.0.symbol', '₹')
        ->assertJsonPath('data.currencies.0.exchange_rate', '1.0000');
});

it('filters the v1 tri-state is_active: 2 means waiting for approval', function () {
    Currency::query()->create(['name' => 'Rupee', 'code' => 'INR']);
    Currency::query()->create(['name' => 'Dollar', 'code' => 'USD', 'is_active' => false, 'approval_status' => 'pending']);
    $token = accessToken(adminUser());

    $this->withToken($token)->getJson('/api/v1/currencies?is_active=2')->assertJsonPath('pagination.totalItems', 1)
        ->assertJsonPath('data.0.code', 'USD')->assertJsonPath('data.0.is_active', 2);
    $this->withToken($token)->getJson('/api/v1/currencies?is_active=1')->assertJsonPath('data.0.code', 'INR');
});
