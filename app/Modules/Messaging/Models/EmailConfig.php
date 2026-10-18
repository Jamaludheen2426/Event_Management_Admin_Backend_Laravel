<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Models;

use App\Core\Database\Model;
use App\Core\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * An outgoing e-mail provider (SMTP, Brevo, sendmail). The company's default
 * active one sends every e-mail the platform writes.
 *
 * @property int $id
 * @property int $company_id
 * @property string $name
 * @property string $from_email
 * @property string|null $from_name
 * @property string $driver
 * @property string|null $host
 * @property int|null $port
 * @property string|null $username
 * @property string|null $password
 * @property string|null $api_key
 * @property string|null $encryption
 * @property bool $is_default
 * @property int $is_active
 */
final class EmailConfig extends Model
{
    use BelongsToCompany;
    use SoftDeletes;

    protected $fillable = [
        'company_id', 'name', 'from_email', 'from_name', 'driver', 'host', 'port', 'username', 'password',
        'api_key', 'encryption', 'domain', 'region', 'is_default', 'is_active', 'approval_status',
        'created_by', 'updated_by',
    ];

    /** v1 toJSON: secrets never leave the server. */
    protected $hidden = ['password', 'api_key'];

    protected $attributes = ['driver' => 'smtp', 'encryption' => 'tls', 'is_default' => false, 'is_active' => 1];

    protected function casts(): array
    {
        return ['password' => 'encrypted', 'api_key' => 'encrypted', 'is_default' => 'boolean', 'port' => 'integer'];
    }
}
