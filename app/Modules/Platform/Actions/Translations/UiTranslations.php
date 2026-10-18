<?php

declare(strict_types=1);

namespace App\Modules\Platform\Actions\Translations;

use App\Core\Activity\ActivityLogger;
use App\Core\Approvals\ApprovalHandler;
use App\Core\Contracts\MachineTranslator;
use App\Core\Exceptions\ApiException;
use App\Core\Exceptions\TranslationQuotaExceeded;
use App\Modules\Platform\Models\Language;
use App\Modules\Platform\Models\MissingTranslationKey;
use App\Modules\Platform\Models\Translation;
use App\Modules\Platform\Models\TranslationKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The admin panel's interface strings (v1 /translation-keys, /translations).
 * Keys are written in English; other languages are machine-translated
 * (MyMemory) when a key is created and can then be reviewed by hand.
 *
 * Also the approval handler of module "translations" (resource translation_key).
 * v1's approval step called a method the translation service did not have, so
 * approving a translation change always failed.
 */
final class UiTranslations implements ApprovalHandler
{
    /** Stop a bulk run after this many failures in a row: the quota is gone. */
    private const MAX_CONSECUTIVE_FAILURES = 5;

    public function __construct(
        private readonly MachineTranslator $translator,
        private readonly ActivityLogger $activity,
    ) {}

    /** @param array{key: string, default_value?: string|null, description?: string|null, group?: string|null} $input */
    public function createKey(array $input, ?int $userId, bool $autoTranslate = true, bool $pending = false): TranslationKey
    {
        if (TranslationKey::query()->where('key', $input['key'])->exists()) {
            throw ApiException::conflict('Translation key already exists');
        }

        $key = TranslationKey::query()->create([
            'key' => $input['key'],
            'default_value' => $input['default_value'] ?? null,
            'description' => $input['description'] ?? null,
            'group' => ($input['group'] ?? null) ?: $this->groupOf($input['key']),
            'approval_status' => $pending ? 'pending' : 'approved',
            'created_by' => $userId,
        ]);

        if ($autoTranslate && ! $pending) {
            $this->translateToAll($key, $userId);
        }

        $this->activity->log('translations', 'create', "Created translation key: {$key->key}", $key);

        return $key;
    }

    /** @param array<string, mixed> $input */
    public function updateKey(TranslationKey $key, array $input, ?int $userId): TranslationKey
    {
        if (isset($input['key']) && $input['key'] !== $key->key
            && TranslationKey::query()->where('key', $input['key'])->exists()) {
            throw ApiException::conflict('Translation key already exists');
        }

        $old = $key->only(['key', 'default_value', 'description', 'group']);
        $key->fill([...array_intersect_key($input, $old), 'updated_by' => $userId])->save();
        $this->activity->log('translations', 'update', "Updated translation key: {$key->key}", $key, $old, $input);

        return $key;
    }

    public function deleteKey(TranslationKey $key): void
    {
        $key->delete(); // translations cascade
        $this->activity->log('translations', 'delete', "Deleted translation key: {$key->key}", $key);
    }

    /**
     * Hand-edited values; they count as reviewed.
     *
     * @param  list<array{language_id: int, value: string|null}>  $values
     * @return list<Translation>
     */
    public function setValues(TranslationKey $key, array $values, ?int $userId): array
    {
        return DB::transaction(function () use ($key, $values, $userId): array {
            $saved = [];
            foreach ($values as $row) {
                $saved[] = Translation::query()->updateOrCreate(
                    ['translation_key_id' => $key->id, 'language_id' => (int) $row['language_id']],
                    ['value' => $row['value'], 'status' => 'reviewed', 'is_active' => true, 'updated_by' => $userId],
                );
            }

            return $saved;
        });
    }

    /** Machine-translate one key into one language, replacing what is there. */
    public function retranslate(TranslationKey $key, Language $language, ?int $userId): Translation
    {
        try {
            $value = $this->translator->translate((string) $key->default_value, 'en', $language->code);
        } catch (TranslationQuotaExceeded) {
            throw ApiException::serviceUnavailable('Daily translation quota exceeded. Try again tomorrow.');
        } catch (Throwable $e) {
            report($e);
            throw ApiException::serviceUnavailable('The translation service is not available right now.');
        }

        return Translation::query()->updateOrCreate(
            ['translation_key_id' => $key->id, 'language_id' => $language->id],
            ['value' => $value, 'status' => 'auto', 'is_active' => true, 'updated_by' => $userId],
        );
    }

    /**
     * Fill the languages this key has no value in yet. A failed call is
     * skipped (the public endpoint falls back to the English default).
     *
     * @return list<Translation>
     */
    public function translateToAll(TranslationKey $key, ?int $userId): array
    {
        $existing = Translation::query()->where('translation_key_id', $key->id)->pluck('language_id')->all();
        $languages = Language::query()->where('is_active', true)->whereNotIn('id', $existing)->get();

        foreach ($languages as $language) {
            $value = $this->tryTranslate((string) $key->default_value, $language->code);
            if ($value !== null) {
                Translation::query()->create([
                    'translation_key_id' => $key->id, 'language_id' => $language->id,
                    'value' => $value, 'status' => 'auto', 'is_active' => true, 'created_by' => $userId,
                ]);
            }
        }

        return Translation::query()->with('language')->where('translation_key_id', $key->id)->get()->all();
    }

    /**
     * Bring a (new) language up to date: revive its switched-off values,
     * machine-translate the keys it has no value for.
     *
     * @return array{created: int, failed: int, reactivated: int, quotaExceeded: bool}
     */
    public function translateAllTo(Language $language, ?int $userId): array
    {
        $reactivated = Translation::query()->where('language_id', $language->id)->where('is_active', false)
            ->update(['is_active' => true, 'updated_by' => $userId]);

        $done = Translation::query()->where('language_id', $language->id)->pluck('translation_key_id')->all();
        $keys = TranslationKey::query()->whereNotIn('id', $done)->get(['id', 'default_value']);

        $created = 0;
        $failures = 0;
        $consecutive = 0;
        $rows = [];
        foreach ($keys as $key) {
            if ($consecutive >= self::MAX_CONSECUTIVE_FAILURES) {
                break;
            }
            $value = $this->tryTranslate((string) $key->default_value, $language->code);
            if ($value === null) {
                $failures++;
                $consecutive++;

                continue;
            }
            $consecutive = 0;
            $rows[] = [
                'translation_key_id' => $key->id, 'language_id' => $language->id, 'value' => $value,
                'status' => 'auto', 'is_active' => true, 'created_by' => $userId, 'created_at' => now(), 'updated_at' => now(),
            ];
            if (count($rows) >= 100) {
                $created += Translation::query()->insertOrIgnore($rows);
                $rows = [];
            }
        }
        if ($rows !== []) {
            $created += Translation::query()->insertOrIgnore($rows);
        }

        $skipped = $keys->count() - $created - $failures;

        return [
            'created' => $created,
            'failed' => $failures + max(0, $skipped),
            'reactivated' => $reactivated,
            'quotaExceeded' => $consecutive >= self::MAX_CONSECUTIVE_FAILURES,
        ];
    }

    /** @return array<string, string|null> key => value, English default where untranslated */
    public function forLanguage(string $code): array
    {
        $language = Language::query()->where('code', $code)->first() ?? throw ApiException::notFound('Language not found');

        $map = TranslationKey::query()->pluck('default_value', 'key')->all();
        $values = Translation::query()
            ->join('translation_keys', 'translation_keys.id', '=', 'translations.translation_key_id')
            ->where('translations.language_id', $language->id)
            ->where('translations.is_active', true)
            ->pluck('translations.value', 'translation_keys.key');

        foreach ($values as $key => $value) {
            if ($value !== null && $value !== '') {
                $map[$key] = $value;
            }
        }

        return $map;
    }

    /** @return array<string, string|null> */
    public function forGroup(string $code, string $group): array
    {
        $language = Language::query()->where('code', $code)->first() ?? throw ApiException::notFound('Language not found');

        return Translation::query()
            ->join('translation_keys', 'translation_keys.id', '=', 'translations.translation_key_id')
            ->where('translations.language_id', $language->id)
            ->where('translation_keys.group', $group)
            ->pluck('translations.value', 'translation_keys.key')
            ->all();
    }

    /** @return array{total_keys: int, languages: list<array<string, mixed>>} two queries, whatever the size */
    public function stats(): array
    {
        $total = TranslationKey::query()->count();
        $counts = Translation::query()->where('is_active', true)
            ->selectRaw('language_id, count(*) as total, sum(status = ?) as auto, sum(status = ?) as reviewed', ['auto', 'reviewed'])
            ->groupBy('language_id')->get()->keyBy('language_id');

        $languages = Language::query()->where('is_active', true)->get()->map(static function (Language $l) use ($counts, $total): array {
            $row = $counts->get($l->id);
            $done = (int) ($row?->getAttribute('total') ?? 0);

            return [
                'id' => $l->id, 'code' => $l->code, 'name' => $l->name,
                'total' => $done,
                'auto' => (int) ($row?->getAttribute('auto') ?? 0),
                'reviewed' => (int) ($row?->getAttribute('reviewed') ?? 0),
                'missing' => $total - $done,
                'completion' => $total > 0 ? (int) round($done / $total * 100) : 0,
            ];
        })->values()->all();

        return ['total_keys' => $total, 'languages' => $languages];
    }

    /**
     * @param  list<array<string, mixed>>  $keys
     * @return array{created: int, skipped: int, errors: list<array{key: string, error: string}>}
     */
    public function bulkImport(array $keys, ?int $userId, bool $autoTranslate): array
    {
        $result = ['created' => 0, 'skipped' => 0, 'errors' => []];
        $existing = array_flip(TranslationKey::query()->pluck('key')->all());

        foreach ($keys as $data) {
            $name = is_string($data['key'] ?? null) ? trim($data['key']) : '';
            if ($name === '' || isset($existing[$name])) {
                $result['skipped']++;

                continue;
            }
            try {
                $this->createKey([...$data, 'key' => $name], $userId, $autoTranslate);
                $existing[$name] = true;
                $result['created']++;
            } catch (Throwable $e) {
                $result['errors'][] = ['key' => $name, 'error' => $e->getMessage()];
            }
        }

        return $result;
    }

    /** @return list<array<string, mixed>> */
    public function export(): array
    {
        return TranslationKey::query()->with('translations.language:id,code')->orderBy('key')->get()
            ->map(static fn (TranslationKey $key): array => [
                'key' => $key->key,
                'default_value' => $key->default_value,
                'description' => $key->getAttribute('description'),
                'group' => $key->group,
                'translations' => $key->translations->filter(static fn (Translation $t): bool => $t->language !== null)
                    ->mapWithKeys(static fn (Translation $t): array => [$t->language->code => ['value' => $t->value, 'status' => $t->status]])
                    ->all(),
            ])->values()->all();
    }

    // ---- missing keys -----------------------------------------------------

    /** @return array{status: string, key: string} */
    public function reportMissing(string $key, ?string $default, ?string $pageUrl): array
    {
        if (TranslationKey::query()->where('key', $key)->exists()) {
            return ['status' => 'exists', 'key' => $key];
        }

        $missing = MissingTranslationKey::query()->where('key', $key)->first();
        if ($missing !== null) {
            $missing->fill([
                'report_count' => $missing->report_count + 1,
                'last_reported_at' => now(),
                'default_value' => $default ?: $missing->getAttribute('default_value'),
                'page_url' => $pageUrl ?: $missing->getAttribute('page_url'),
            ])->save();

            return ['status' => 'updated', 'key' => $key];
        }

        MissingTranslationKey::query()->create([
            'key' => $key, 'default_value' => $default, 'page_url' => $pageUrl,
            'report_count' => 1, 'first_reported_at' => now(), 'last_reported_at' => now(),
        ]);

        return ['status' => 'created', 'key' => $key];
    }

    /** @param array{default_value?: string|null, description?: string|null, group?: string|null} $overrides */
    public function createFromMissing(MissingTranslationKey $missing, array $overrides, ?int $userId): TranslationKey
    {
        $key = $this->createKey([
            'key' => $missing->key,
            'default_value' => ($overrides['default_value'] ?? null) ?: ($missing->getAttribute('default_value') ?: $missing->key),
            'description' => $overrides['description'] ?? null,
            'group' => $overrides['group'] ?? null,
        ], $userId);

        $missing->forceFill(['resolved_at' => now()])->save();

        return $key;
    }

    /** @return array{created: int, failed: int, errors: list<array{key: string, error: string}>} */
    public function createAllMissing(?int $userId): array
    {
        $result = ['created' => 0, 'failed' => 0, 'errors' => []];

        foreach (MissingTranslationKey::query()->whereNull('resolved_at')->get() as $missing) {
            try {
                if (TranslationKey::query()->where('key', $missing->key)->exists()) {
                    $missing->forceFill(['resolved_at' => now()])->save();

                    continue;
                }
                $this->createFromMissing($missing, [], $userId);
                $result['created']++;
            } catch (Throwable $e) {
                $result['failed']++;
                $result['errors'][] = ['key' => $missing->key, 'error' => $e->getMessage()];
            }
        }

        return $result;
    }

    // ---- ApprovalHandler ------------------------------------------------

    public function snapshot(int $id): ?array
    {
        return TranslationKey::query()->find($id)?->attributesToArray();
    }

    public function createPending(array $payload, Model $requester): int
    {
        /** @var array{key: string, default_value?: string|null, description?: string|null, group?: string|null} $payload */
        return $this->createKey($payload, (int) $requester->getKey(), pending: true)->id;
    }

    public function apply(string $action, ?int $resourceId, array $payload, Model $requester): ?Model
    {
        $key = $resourceId === null ? null : TranslationKey::query()->find($resourceId);
        if ($key === null) {
            throw ApiException::notFound('Translation key not found');
        }
        $userId = (int) $requester->getKey();

        if ($action === 'create') {
            $key->forceFill(['approval_status' => 'approved'])->save();
            $this->translateToAll($key, $userId);

            return $key;
        }
        if ($action === 'delete') {
            $this->deleteKey($key);

            return null;
        }
        if (isset($payload['translations']) && is_array($payload['translations'])) {
            /** @var list<array{language_id: int, value: string|null}> $values */
            $values = $payload['translations'];
            $this->setValues($key, $values, $userId);

            return $key;
        }

        return $this->updateKey($key, $payload, $userId);
    }

    public function discard(int $resourceId, Model $reviewer): void
    {
        TranslationKey::query()->whereKey($resourceId)->where('approval_status', 'pending')->first()?->delete();
    }

    // ---------------------------------------------------------------------

    private function tryTranslate(string $text, string $to): ?string
    {
        try {
            return $this->translator->translate($text, 'en', $to);
        } catch (Throwable $e) {
            if (! $e instanceof TranslationQuotaExceeded) {
                report($e);
            }

            return null;
        }
    }

    /** "common.save" -> "common" (v1). */
    private function groupOf(string $key): string
    {
        $parts = explode('.', $key);

        return count($parts) > 1 ? $parts[0] : 'common';
    }
}
