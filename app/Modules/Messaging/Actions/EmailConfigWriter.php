<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Actions;

use App\Core\Crud\RecordWriter;
use App\Core\Exceptions\ApiException;
use App\Modules\Messaging\Models\EmailConfig;
use Illuminate\Database\Eloquent\Model;

/**
 * /email-configs create, edit, delete (v1 emailConfig.service). Setting one
 * as default unsets the company's other default. A blank password or API key
 * on edit keeps the stored one (the form never receives it back).
 *
 * @extends RecordWriter<EmailConfig>
 */
final class EmailConfigWriter extends RecordWriter
{
    private const DRIVERS = ['smtp', 'brevo', 'elasticemail', 'sendmail'];

    private const ENCRYPTIONS = ['tls', 'ssl', 'none'];

    protected function model(): string
    {
        return EmailConfig::class;
    }

    protected function module(): string
    {
        return 'email_configs';
    }

    protected function label(): string
    {
        return 'Email configuration';
    }

    protected function columns(): array
    {
        return ['name', 'from_email', 'from_name', 'driver', 'host', 'port', 'username', 'password', 'api_key',
            'encryption', 'domain', 'region', 'is_default', 'is_active'];
    }

    protected function prepare(array $values, ?Model $existing): array
    {
        foreach (['password', 'api_key'] as $secret) {
            if (array_key_exists($secret, $values) && ($values[$secret] === null || $values[$secret] === '')) {
                unset($values[$secret]);
            }
        }
        foreach (['name', 'from_email', 'from_name', 'host', 'username', 'domain', 'region'] as $text) {
            if (array_key_exists($text, $values)) {
                $values[$text] = $values[$text] === null ? null : trim((string) $values[$text]);
            }
        }

        $name = $values['name'] ?? $existing?->name;
        $from = $values['from_email'] ?? $existing?->from_email;
        if ($name === null || $name === '') {
            throw ApiException::badRequest('Name is required');
        }
        if ($from === null || $from === '' || filter_var($from, FILTER_VALIDATE_EMAIL) === false) {
            throw ApiException::badRequest('A valid from email is required');
        }
        if (isset($values['driver']) && ! in_array($values['driver'], self::DRIVERS, true)) {
            throw ApiException::badRequest('Driver must be one of: '.implode(', ', self::DRIVERS));
        }
        if (isset($values['encryption']) && ! in_array($values['encryption'], self::ENCRYPTIONS, true)) {
            throw ApiException::badRequest('Encryption must be one of: '.implode(', ', self::ENCRYPTIONS));
        }
        if (array_key_exists('port', $values)) {
            $values['port'] = $values['port'] === null || $values['port'] === '' ? null : (int) $values['port'];
        }
        if (array_key_exists('is_default', $values)) {
            $values['is_default'] = filter_var($values['is_default'], FILTER_VALIDATE_BOOL);
        }

        return $values;
    }

    protected function assertUnique(array $values, ?int $exceptId): void
    {
        if ($this->taken($this->query(), ['name' => $values['name']], $exceptId)) {
            throw ApiException::badRequest("An email config with the name \"{$values['name']}\" already exists.");
        }
    }

    /** v1 PATCH /:id/toggle: flips on and off. */
    public function toggle(EmailConfig $config, ?Model $actor): EmailConfig
    {
        $config->forceFill(['is_active' => (int) $config->is_active === 1 ? 0 : 1, 'updated_by' => $actor?->getKey()])->save();
        $this->activity->log('email_configs', 'toggle', "Email config {$config->id} active status toggled to {$config->is_active}", $config);

        return $config;
    }
}
