<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Models;

use App\Core\Database\Model;
use App\Core\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A platform e-mail (Settings → Email → Templates): a full template, or a
 * header / footer part templates put around their body. {{variables}} are
 * filled when it is sent.
 *
 * @property int $id
 * @property int $company_id
 * @property string $name
 * @property string $slug
 * @property string $type
 * @property string|null $subject
 * @property string|null $body
 * @property list<string>|null $variables
 * @property int|null $header_id
 * @property int|null $footer_id
 * @property int|null $email_config_id
 * @property int $is_active
 */
final class EmailTemplate extends Model
{
    use BelongsToCompany;
    use SoftDeletes;

    protected $fillable = [
        'company_id', 'name', 'slug', 'type', 'subject', 'body', 'variables', 'description', 'header_id', 'footer_id',
        'email_config_id', 'is_active', 'approval_status', 'created_by', 'updated_by',
    ];

    protected $attributes = ['type' => 'template', 'is_active' => 1];

    protected function casts(): array
    {
        return ['variables' => 'array'];
    }

    /** @return BelongsTo<EmailTemplate, $this> */
    public function header(): BelongsTo
    {
        return $this->belongsTo(self::class, 'header_id');
    }

    /** @return BelongsTo<EmailTemplate, $this> */
    public function footer(): BelongsTo
    {
        return $this->belongsTo(self::class, 'footer_id');
    }

    /** @return BelongsTo<EmailConfig, $this> */
    public function emailConfig(): BelongsTo
    {
        return $this->belongsTo(EmailConfig::class);
    }
}
