<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Models;

use App\Core\Database\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * The mail bell: one row per "to" recipient of a sent mail.
 *
 * @property int $id
 * @property int $mail_id
 * @property string $recipient_type
 * @property int $recipient_id
 * @property bool $is_read
 * @property Carbon|null $created_at
 * @property-read Mail|null $mail
 */
final class MailNotification extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['mail_id', 'recipient_type', 'recipient_id', 'is_read'];

    protected $attributes = ['is_read' => false];

    protected function casts(): array
    {
        return ['is_read' => 'boolean', 'recipient_id' => 'integer'];
    }

    /** @return BelongsTo<Mail, $this> */
    public function mail(): BelongsTo
    {
        return $this->belongsTo(Mail::class);
    }
}
