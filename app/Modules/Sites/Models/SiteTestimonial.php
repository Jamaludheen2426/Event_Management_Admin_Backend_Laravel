<?php

declare(strict_types=1);

namespace App\Modules\Sites\Models;

/**
 * A customer's words about the site owner.
 *
 * @property int $id
 * @property int $site_id
 */
final class SiteTestimonial extends SiteRecord
{
    protected $table = 'site_testimonials';

    protected $fillable = ['site_id', 'customer_name', 'photo_url', 'event_name', 'feedback', 'rating', 'show_rating', 'is_featured', 'sort_order', 'is_active'];
}
