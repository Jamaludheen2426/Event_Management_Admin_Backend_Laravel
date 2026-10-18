<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Models;

use App\Core\Database\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One recipient's copy of a mail: read, trash, label and folder are theirs alone.
 *
 * @property int $id
 * @property int $mail_id
 * @property string $recipient_type
 * @property int $recipient_id
 * @property string $role
 * @property bool $is_read
 * @property int $is_active
 * @property-read Mail|null $mail
 */
final class MailRecipient extends Model
{
    protected $fillable = ['mail_id', 'recipient_type', 'recipient_id', 'role', 'is_read', 'is_active', 'label', 'custom_folder_id'];

    protected $attributes = ['role' => 'to', 'is_read' => false, 'is_active' => 1];

    protected function casts(): array
    {
        return ['is_read' => 'boolean', 'recipient_id' => 'integer', 'is_active' => 'integer'];
    }

    /** @return BelongsTo<Mail, $this> */
    public function mail(): BelongsTo
    {
        return $this->belongsTo(Mail::class);
    }
}
