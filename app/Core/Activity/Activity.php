<?php

declare(strict_types=1);

namespace App\Core\Activity;

use App\Core\Database\SerializesV1Dates;
use Spatie\Activitylog\Models\Activity as SpatieActivity;

/**
 * One audit entry. Spatie's activity_log plus the request facts the v1 log kept
 * (company, ip, user agent, url, method) as real columns, so the activity
 * screens can filter on them.
 *
 *   log_name = module   ("roles")      event = action ("update")
 *   causer   = who      (admin user)   subject = what was changed
 *
 * @property int|null $company_id
 * @property string|null $ip_address
 */
final class Activity extends SpatieActivity
{
    use SerializesV1Dates;

    protected $fillable = [
        'log_name', 'description', 'subject_type', 'subject_id', 'causer_type', 'causer_id',
        'properties', 'event', 'batch_uuid', 'company_id', 'ip_address', 'user_agent', 'url', 'method',
    ];
}
