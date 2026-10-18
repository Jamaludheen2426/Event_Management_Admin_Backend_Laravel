<?php

declare(strict_types=1);

namespace App\Modules\Platform\Actions\Reference;

use App\Core\Crud\RecordWriter;
use App\Core\Exceptions\ApiException;
use App\Modules\Platform\Models\Language;
use App\Modules\Platform\Models\Translation;
use Illuminate\Database\Eloquent\Model;

/** @extends RecordWriter<Language> */
final class LanguageWriter extends RecordWriter
{
    protected function model(): string
    {
        return Language::class;
    }

    protected function module(): string
    {
        return 'languages';
    }

    protected function label(): string
    {
        return 'Language';
    }

    protected function columns(): array
    {
        return ['name', 'code', 'native_name', 'direction', 'is_default', 'is_active'];
    }

    protected function assertUnique(array $values, ?int $exceptId): void
    {
        if (isset($values['code']) && $this->taken(Language::query(), ['code' => $values['code']], $exceptId)) {
            throw ApiException::conflict('Language with this code already exists');
        }
    }

    /** A language deleted earlier comes back with its translations (v1). */
    protected function revivable(array $values): ?Model
    {
        $old = Language::onlyTrashed()->where('code', $values['code'] ?? null)->first();
        if ($old !== null) {
            $old->restore();
            Translation::query()->where('language_id', $old->id)->update(['is_active' => true]);
        }

        return $old;
    }

    protected function beforeDelete(Model $model): void
    {
        /** @var Language $model */
        if ($model->is_default) {
            throw ApiException::badRequest('Cannot delete default language');
        }
        Translation::query()->where('language_id', $model->id)->update(['is_active' => false]);
    }
}
