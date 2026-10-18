<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Actions;

use App\Modules\Catalog\Models\NotificationCategory;

/** @extends NamedListWriter<NotificationCategory> */
final class NotificationCategoryWriter extends NamedListWriter
{
    protected function model(): string
    {
        return NotificationCategory::class;
    }

    protected function module(): string
    {
        return 'notification_categories';
    }

    protected function label(): string
    {
        return 'Notification category';
    }

    protected function noun(): string
    {
        return 'notification category';
    }
}
