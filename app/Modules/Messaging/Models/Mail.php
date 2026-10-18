<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Models;

use App\Core\Database\Model;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * An in-app mail (never e-mailed). The sender's copy lives on this row
 * (sender_is_active, label, folder); each recipient's copy on its own row.
 *
 * @property int $id
 * @property int $company_id
 * @property string $sender_type
 * @property int $sender_id
 * @property string $subject
 * @property string $body
 * @property string $status
 * @property int $sender_is_active
 * @property Carbon|null $sent_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, MailRecipient> $recipients
 */
final class Mail extends Model
{
    protected $fillable = [
        'company_id', 'sender_type', 'sender_id', 'subject', 'body', 'status', 'sent_at', 'error_message',
        'sender_is_active', 'sender_label', 'sender_custom_folder_id',
    ];

    protected $attributes = ['status' => 'draft', 'sender_is_active' => 1];

    protected function casts(): array
    {
        return ['sent_at' => 'datetime', 'sender_id' => 'integer', 'sender_is_active' => 'integer'];
    }

    /** @return HasMany<MailRecipient, $this> */
    public function recipients(): HasMany
    {
        return $this->hasMany(MailRecipient::class);
    }
}
