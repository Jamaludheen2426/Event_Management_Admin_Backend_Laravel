<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Actions;

use App\Core\Activity\ActivityLogger;
use App\Core\Exceptions\ApiException;
use App\Modules\Messaging\Models\PushConfig;
use App\Modules\Messaging\Support\FirebaseAuth;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Settings → Push Notifications (v1 pushNotificationConfig.service): Firebase
 * projects, one active per company. Uploading the service-account JSON fills
 * project id, client e-mail and key, and checks them with Google at once.
 */
final class PushConfigs
{
    private const TEXT_FIELDS = ['project_id', 'web_api_key', 'app_id', 'messaging_sender_id', 'auth_domain', 'storage_bucket', 'measurement_id', 'vapid_key'];

    public function __construct(
        private readonly FirebaseAuth $auth,
        private readonly ActivityLogger $activity,
    ) {}

    public function find(int $id): PushConfig
    {
        return PushConfig::query()->find($id) ?? throw ApiException::notFound('PushNotificationConfig not found');
    }

    public function active(): ?PushConfig
    {
        return PushConfig::query()->where('is_active', true)->first();
    }

    /** @param array<string, mixed> $input */
    public function create(array $input, ?Model $actor): PushConfig
    {
        $name = trim((string) ($input['name'] ?? ''));
        if ($name === '') {
            throw ApiException::badRequest('Configuration Label / Name is required');
        }
        $values = ['name' => $name, ...$this->texts($input), ...$this->serviceAccount($input)];
        // The first project is active whatever the form says (v1).
        $active = filter_var($input['is_active'] ?? false, FILTER_VALIDATE_BOOL) || ! PushConfig::query()->exists();

        $config = $this->save(new PushConfig, [...$values, 'is_active' => $active, 'connection_status' => 'pending', 'created_by' => $actor?->getKey()]);
        $this->activity->log('push_notification_configs', 'create', "Created push notification config: {$config->name}", $config);

        return $this->verifyIfNew($config, isset($values['private_key']));
    }

    /** @param array<string, mixed> $input */
    public function update(PushConfig $config, array $input, ?Model $actor): PushConfig
    {
        $values = $this->texts($input);
        if (array_key_exists('name', $input)) {
            $values['name'] = trim((string) $input['name']);
            if ($values['name'] === '') {
                throw ApiException::badRequest('Configuration Label / Name cannot be empty');
            }
        }
        if (array_key_exists('is_active', $input)) {
            $values['is_active'] = filter_var($input['is_active'], FILTER_VALIDATE_BOOL);
        }
        // The JSON's project id wins over the typed one (v1).
        $credentials = $this->serviceAccount($input);
        $values = [...$values, ...$credentials];

        $this->save($config, [...$values, 'updated_by' => $actor?->getKey()]);
        $this->activity->log('push_notification_configs', 'update', "Updated push notification config: {$config->name}", $config);

        return $this->verifyIfNew($config, $credentials !== []);
    }

    /** v1 PATCH /:id/toggle and /:id/active: makes this the active project. */
    public function activate(PushConfig $config, ?Model $actor): PushConfig
    {
        return $this->save($config, ['is_active' => true, 'updated_by' => $actor?->getKey()]);
    }

    public function delete(PushConfig $config): void
    {
        $config->delete();
        $this->activity->log('push_notification_configs', 'delete', "Deleted push notification config: {$config->name}", $config);
    }

    /** @return array<string, mixed> */
    public function test(PushConfig $config): array
    {
        if ($config->client_email === null || $config->private_key === null) {
            $config->forceFill(['connection_status' => 'disconnected', 'validation_error' => 'No service account credentials or private key stored.'])->save();

            return ['connected' => false, 'status' => $config->connection_status, 'message' => $config->validation_error];
        }
        $this->verify($config);

        return $config->connection_status === 'connected'
            ? ['connected' => true, 'status' => 'connected', 'last_verified_at' => $config->last_verified_at?->utc()->format('Y-m-d\TH:i:s.v\Z'),
                'message' => 'Automated Handshake succeeded: Google Cloud OAuth2 token endpoint verified.']
            : ['connected' => false, 'status' => $config->connection_status, 'message' => $config->validation_error];
    }

    /** @param array<string, mixed> $values */
    private function save(PushConfig $config, array $values): PushConfig
    {
        return DB::transaction(static function () use ($config, $values): PushConfig {
            $config->fill($values)->save();
            if ($config->is_active) {
                PushConfig::query()->whereKeyNot($config->id)->where('is_active', true)->update(['is_active' => false]);
            }

            return $config;
        });
    }

    private function verifyIfNew(PushConfig $config, bool $credentialsChanged): PushConfig
    {
        if ($credentialsChanged && $config->client_email !== null && $config->private_key !== null) {
            $this->verify($config);
        }

        return $config;
    }

    private function verify(PushConfig $config): void
    {
        $handshake = $this->auth->handshake($config->client_email, $config->private_key);
        $config->forceFill($handshake['success']
            ? ['connection_status' => 'connected', 'last_verified_at' => now(), 'validation_error' => null]
            : ['connection_status' => 'error', 'validation_error' => $handshake['error']])->save();
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, string|null>
     */
    private function texts(array $input): array
    {
        $values = [];
        foreach (self::TEXT_FIELDS as $field) {
            if (array_key_exists($field, $input)) {
                $value = trim((string) $input[$field]);
                $values[$field] = $value === '' ? null : $value;
            }
        }

        return $values;
    }

    /**
     * The uploaded or pasted service-account JSON, split into its parts.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, string|null>
     */
    private function serviceAccount(array $input): array
    {
        $raw = trim((string) ($input['service_account_json'] ?? ''));
        if ($raw === '') {
            return [];
        }
        $parsed = json_decode($raw, true);
        if (! is_array($parsed)) {
            throw ApiException::badRequest('Invalid Service Account JSON: '.json_last_error_msg());
        }
        if (isset($parsed['type']) && $parsed['type'] !== 'service_account') {
            throw ApiException::badRequest('Invalid Service Account JSON: JSON is not a Google service_account credential file.');
        }

        $values = [
            'service_account_json' => $raw,
            'client_email' => is_string($parsed['client_email'] ?? null) ? $parsed['client_email'] : null,
            'private_key' => is_string($parsed['private_key'] ?? null) ? $parsed['private_key'] : null,
        ];
        if (is_string($parsed['project_id'] ?? null) && $parsed['project_id'] !== '') {
            $values['project_id'] = $parsed['project_id'];
        }

        return $values;
    }
}
