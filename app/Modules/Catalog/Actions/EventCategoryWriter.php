<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Actions;

use App\Modules\Catalog\Models\EventCategory;

/** @extends NamedListWriter<EventCategory> */
final class EventCategoryWriter extends NamedListWriter
{
    protected function model(): string
    {
        return EventCategory::class;
    }

    protected function module(): string
    {
        return 'event_categories';
    }

    protected function label(): string
    {
        return 'Event category';
    }

    protected function noun(): string
    {
        return 'event category';
    }
}
