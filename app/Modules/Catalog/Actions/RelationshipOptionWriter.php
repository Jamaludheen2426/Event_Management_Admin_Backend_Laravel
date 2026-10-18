<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Actions;

use App\Modules\Catalog\Models\GuestOption;

/** "Relationship with Invitor" options (v1 /guest-relationship-options). */
final class RelationshipOptionWriter extends GuestOptionWriter
{
    public function kind(): string
    {
        return GuestOption::RELATIONSHIP;
    }

    protected function noun(): string
    {
        return 'relationship';
    }

    protected function module(): string
    {
        return 'guest_relationship_options';
    }

    protected function label(): string
    {
        return 'Relationship option';
    }
}
