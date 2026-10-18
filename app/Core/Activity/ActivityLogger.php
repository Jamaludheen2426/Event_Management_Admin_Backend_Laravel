<?php

declare(strict_types=1);

namespace App\Core\Activity;

use App\Core\Tenancy\CurrentCompany;
use App\Core\Tenancy\ResolvesTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Throwable;

/**
 * Writes the audit trail. Every Action that changes data calls log().
 *
 * Secrets are redacted here, centrally, before anything is stored: the Node API
 * wrote plaintext passwords into activity_logs when an admin changed another
 * employee's password. Logging never breaks the request it is recording.
 */
final class ActivityLogger
{
    /** Keys whose values are never stored, at any depth. */
    public const REDACTED = [
        'password', 'new_password', 'current_password', 'confirm_password', 'password_confirmation',
        'smtp_password', 'mail_password', 'api_key', 'api_secret', 'client_secret', 'secret',
        'private_key', 'token', 'access_token', 'refresh_token', 'reset_token', 'remember_token',
        'otp', 'otp_hash', 'account_number',
    ];

    public function __construct(
        private readonly Request $request,
        private readonly CurrentCompany $company,
    ) {}

    /**
     * @param  array<string, mixed>  $old
     * @param  array<string, mixed>  $new
     */
    public function log(
        string $module,
        string $action,
        string $description,
        ?Model $subject = null,
        array $old = [],
        array $new = [],
        ?Model $causer = null,
        ?int $companyId = null,
    ): void {
        try {
            $causer ??= $this->request->user();

            Activity::query()->create([
                'log_name' => $module,
                'event' => $action,
                'description' => $description,
                'subject_type' => $subject?->getMorphClass(),
                'subject_id' => $subject?->getKey(),
                'causer_type' => $causer instanceof Model ? $causer->getMorphClass() : null,
                'causer_id' => $causer instanceof Model ? $causer->getKey() : null,
                'properties' => array_filter([
                    'old_values' => self::redact($old),
                    'new_values' => self::redact($new),
                ], static fn (array $values): bool => $values !== []),
                'company_id' => $companyId ?? $this->companyFor($causer),
                'ip_address' => $this->request->ip(),
                'user_agent' => $this->request->userAgent(),
                'url' => $this->request->getRequestUri() !== '' ? mb_substr($this->request->getRequestUri(), 0, 500) : null,
                'method' => $this->request->method(),
            ]);
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    public static function redact(array $values): array
    {
        $safe = [];
        foreach ($values as $key => $value) {
            if (in_array((string) $key, self::REDACTED, true)) {
                $safe[$key] = '[REDACTED]';
            } elseif (is_array($value)) {
                $safe[$key] = self::redact($value);
            } else {
                $safe[$key] = $value;
            }
        }

        return $safe;
    }

    private function companyFor(mixed $causer): ?int
    {
        if ($this->company->has()) {
            return $this->company->id();
        }

        return $causer instanceof ResolvesTenant ? $causer->tenantCompanyId() : null;
    }
}
