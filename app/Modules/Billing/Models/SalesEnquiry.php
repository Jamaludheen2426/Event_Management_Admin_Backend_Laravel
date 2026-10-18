<?php

declare(strict_types=1);

namespace App\Modules\Billing\Models;

use App\Core\Database\Model;

/**
 * A "contact sales" request from the billing screens. Stored, not e-mailed.
 *
 * @property int $id
 * @property int|null $client_id
 * @property string $full_name
 * @property string $work_email
 * @property list<string>|null $interests
 * @property string $status
 */
final class SalesEnquiry extends Model
{
    protected $fillable = [
        'client_id', 'full_name', 'work_email', 'company_name', 'phone', 'events_per_year', 'interests', 'message',
        'preferred_time', 'status',
    ];

    protected function casts(): array
    {
        return ['interests' => 'array'];
    }
}
