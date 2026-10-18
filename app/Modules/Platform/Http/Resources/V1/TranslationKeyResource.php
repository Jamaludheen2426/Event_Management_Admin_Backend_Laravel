<?php

declare(strict_types=1);

namespace App\Modules\Platform\Http\Resources\V1;

use App\Core\Http\Resources\V1Resource;
use App\Modules\Platform\Models\Translation;
use App\Modules\Platform\Models\TranslationKey;
use Illuminate\Http\Request;

/**
 * @mixin TranslationKey
 *
 * @property TranslationKey $resource
 */
final class TranslationKeyResource extends V1Resource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $key = $this->resource;

        return [
            'id' => $key->id,
            'key' => $key->key,
            'default_value' => $key->default_value,
            'description' => $key->getAttribute('description'),
            'group' => $key->group,
            'is_active' => $key->getAttribute('approval_status') === 'pending' ? 2 : 1,
            'created_by' => $key->getAttribute('created_by'),
            'updated_by' => $key->getAttribute('updated_by'),
            'created_at' => $this->isoDate($key->created_at),
            'updated_at' => $this->isoDate($key->updated_at),
            'translations' => $this->whenLoaded('translations', fn () => $key->translations
                ->filter(static fn (Translation $t): bool => (bool) $t->getAttribute('is_active'))
                ->map(static fn (Translation $t): array => self::translation($t))
                ->values()),
            ...$this->pendingApprovalField(),
        ];
    }

    /** @return array<string, mixed> */
    public static function translation(Translation $t): array
    {
        $language = $t->relationLoaded('language') ? $t->language : null;

        return [
            'id' => $t->id,
            'translation_key_id' => $t->translation_key_id,
            'language_id' => $t->language_id,
            'value' => $t->value,
            'status' => $t->status,
            'is_active' => (bool) $t->getAttribute('is_active'),
            'created_at' => $t->created_at?->utc()->format('Y-m-d\TH:i:s.v\Z'),
            'updated_at' => $t->updated_at?->utc()->format('Y-m-d\TH:i:s.v\Z'),
            'language' => $language === null ? null : ['id' => $language->id, 'code' => $language->code, 'name' => $language->name],
        ];
    }
}
