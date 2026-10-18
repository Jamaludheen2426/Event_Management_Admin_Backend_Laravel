<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Models;

use App\Core\Database\Model;
use Illuminate\Support\Carbon;

/**
 * Who a newsletter went to, as they were when it was sent.
 *
 * @property int $id
 * @property int $newsletter_id
 * @property int|null $client_id
 * @property string $email
 * @property string $name
 * @property string|null $membership
 * @property string $template
 * @property string $status
 * @property Carbon|null $opened_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class VendorNewsletterRecipient extends Model
{
    protected $fillable = ['newsletter_id', 'client_id', 'email', 'name', 'membership', 'template', 'status', 'opened_at', 'clicked_at'];

    protected function casts(): array
    {
        return ['opened_at' => 'datetime', 'clicked_at' => 'datetime'];
    }
}
