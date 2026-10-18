<?php

declare(strict_types=1);

namespace App\Modules\Platform\Actions;

use App\Core\Activity\ActivityLogger;
use App\Core\Approvals\ApprovalHandler;
use App\Core\Exceptions\ApiException;
use App\Modules\Platform\Media\MediaSettings;
use App\Modules\Platform\Models\Setting;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Company settings. Also the approval handler for module "settings" (a bulk
 * save that needs approval is stored and re-run on approval).
 *
 * v1 matched rows by `key` alone because key was globally unique; one company
 * could therefore overwrite another's settings. Rows are per company now.
 */
final class SettingWriter implements ApprovalHandler
{
    public function __construct(
        private readonly ActivityLogger $activity,
        private readonly MediaSettings $media,
    ) {}

    public function update(string $key, mixed $value, ?int $userId): Setting
    {
        $setting = Setting::query()->where('key', $key)->first() ?? throw ApiException::notFound('Setting not found');
        $old = $setting->value;

        $setting->fill(['value' => $this->text($value), 'updated_by' => $userId])->save();
        $this->media->forget();

        $this->activity->log('settings', 'update', "Updated setting: {$key}", $setting,
            ['value' => Setting::isSecret($key) ? '[REDACTED]' : $old],
            ['value' => Setting::isSecret($key) ? '[REDACTED]' : $setting->value]);

        return $setting;
    }

    /**
     * Upsert many keys of one group.
     *
     * @param  array<string, mixed>  $values
     * @return list<Setting>
     */
    public function bulk(array $values, string $group, ?int $userId): array
    {
        $saved = DB::transaction(function () use ($values, $group, $userId): array {
            $existing = Setting::query()->whereIn('key', array_keys($values))->get()->keyBy('key');
            $saved = [];

            foreach ($values as $key => $value) {
                $setting = $existing->get($key) ?? new Setting(['key' => $key, 'group' => $group, 'type' => 'text', 'created_by' => $userId]);
                $setting->fill(['value' => $this->text($value), 'updated_by' => $userId])->save();
                $saved[] = $setting;
            }

            return $saved;
        });

        $this->media->forget();
        $this->activity->log('settings', 'bulk_update', 'Bulk updated '.count($saved).' settings', null, [], ['group' => $group, 'keys' => array_keys($values)]);

        return $saved;
    }

    // ---- ApprovalHandler ------------------------------------------------

    public function snapshot(int $id): ?array
    {
        return null;
    }

    public function createPending(array $payload, Model $requester): int
    {
        throw new \LogicException('Settings are never created under approval.');
    }

    public function apply(string $action, ?int $resourceId, array $payload, Model $requester): ?Model
    {
        $group = is_string($payload['group'] ?? null) ? $payload['group'] : 'general';
        unset($payload['group']);

        $this->bulk($payload, $group, (int) $requester->getKey());

        return null;
    }

    public function discard(int $resourceId, Model $reviewer): void {}

    private function text(mixed $value): ?string
    {
        return match (true) {
            $value === null => null,
            is_bool($value) => $value ? 'true' : 'false',
            is_array($value) => (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            default => (string) $value,
        };
    }
}
