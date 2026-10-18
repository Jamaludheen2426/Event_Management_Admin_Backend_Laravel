<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Models;

use App\Core\Database\Model;
use App\Core\Tenancy\BelongsToVendor;

/**
 * One newsletter a vendor sent to its subscribed (or unsubscribed) clients.
 *
 * @property int $id
 * @property int $vendor_id
 * @property int|null $template_id
 * @property string $template_name
 * @property string $user_type
 * @property list<string>|null $plans
 * @property int $total_sent
 * @property int|null $mail_id
 */
final class VendorNewsletter extends Model
{
    use BelongsToVendor;

    protected $fillable = ['vendor_id', 'template_id', 'template_name', 'category_id', 'user_type', 'plans', 'total_sent', 'mail_id'];

    protected function casts(): array
    {
        return ['plans' => 'array'];
    }
}
