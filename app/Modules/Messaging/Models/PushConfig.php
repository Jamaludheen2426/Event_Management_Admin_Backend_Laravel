<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Models;

use App\Core\Database\Model;
use App\Core\Tenancy\BelongsToCompany;
use Illuminate\Support\Carbon;

/**
 * A Firebase project the app's push tokens belong to. One is active per company.
 *
 * @property int $id
 * @property int $company_id
 * @property string $name
 * @property bool $is_active
 * @property string|null $service_account_json
 * @property string|null $private_key
 * @property string|null $project_id
 * @property string|null $client_email
 * @property string $connection_status
 * @property Carbon|null $last_verified_at
 * @property string|null $validation_error
 * @property Carbon|null $updated_at
 */
final class PushConfig extends Model
{
    use BelongsToCompany;

    protected $table = 'push_notification_configs';

    protected $fillable = [
        'company_id', 'name', 'is_active', 'service_account_json', 'private_key', 'project_id', 'client_email',
        'web_api_key', 'app_id', 'messaging_sender_id', 'auth_domain', 'storage_bucket', 'measurement_id',
        'vapid_key', 'connection_status', 'last_verified_at', 'validation_error', 'created_by', 'updated_by',
    ];

    /** v1 toJSON: the credentials never leave the server. */
    protected $hidden = ['service_account_json', 'private_key'];

    protected $attributes = ['is_active' => false, 'connection_status' => 'pending'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'service_account_json' => 'encrypted',
            'private_key' => 'encrypted',
            'last_verified_at' => 'datetime',
        ];
    }

    public function canSend(): bool
    {
        return $this->client_email !== null && $this->private_key !== null && $this->project_id !== null;
    }
}
