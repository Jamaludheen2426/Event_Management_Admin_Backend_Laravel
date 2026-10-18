<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Actions;

use App\Modules\Catalog\Models\GuestOption;

/** "Food Preference" options (v1 /guest-food-preference-options). */
final class FoodPreferenceOptionWriter extends GuestOptionWriter
{
    public function kind(): string
    {
        return GuestOption::FOOD_PREFERENCE;
    }

    protected function noun(): string
    {
        return 'food preference';
    }

    protected function module(): string
    {
        return 'guest_food_preference_options';
    }

    protected function label(): string
    {
        return 'Food preference option';
    }
}
