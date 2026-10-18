<?php

declare(strict_types=1);

namespace App\Modules\Platform\Models;

use App\Core\Database\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The value of one UI key in one language.
 *
 * @property int $id
 * @property int $translation_key_id
 * @property int $language_id
 * @property string|null $value
 * @property string $status
 */
final class Translation extends Model
{
    protected $fillable = ['translation_key_id', 'language_id', 'value', 'status', 'is_active', 'created_by', 'updated_by'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    /** @return BelongsTo<TranslationKey, $this> */
    public function translationKey(): BelongsTo
    {
        return $this->belongsTo(TranslationKey::class);
    }

    /** @return BelongsTo<Language, $this> */
    public function language(): BelongsTo
    {
        return $this->belongsTo(Language::class);
    }
}
