<?php

declare(strict_types=1);

namespace App\Modules\Clients\Models;

use App\Core\Database\Model;
use App\Core\Tenancy\BelongsToVendor;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * An e-mail on a vendor's newsletter list (v1 vendor_subscribers).
 *
 * @property int $id
 * @property int $vendor_id
 * @property string $email
 * @property bool $is_active
 */
final class NewsletterSubscriber extends Model
{
    use BelongsToVendor;
    use SoftDeletes;

    protected $fillable = ['vendor_id', 'email', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
