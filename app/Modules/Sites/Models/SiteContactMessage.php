<?php

declare(strict_types=1);

namespace App\Modules\Sites\Models;

/**
 * A message a visitor sent through the contact form.
 *
 * @property int $id
 * @property int $site_id
 */
final class SiteContactMessage extends SiteRecord
{
    protected $table = 'site_contact_messages';

    protected $fillable = ['site_id', 'category_id', 'category_other', 'name', 'email', 'phone', 'subject', 'message', 'status', 'metadata_json', 'is_active'];

    protected function casts(): array
    {
        return ['metadata_json' => 'array'];
    }
}
