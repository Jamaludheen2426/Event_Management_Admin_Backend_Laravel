<?php

declare(strict_types=1);

namespace App\Modules\Platform\Models;

use App\Core\Database\Model;
use App\Core\Tenancy\BelongsToCompany;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Support\Facades\Crypt;

/**
 * One company setting. Values of keys listed in config('settings.secret_keys')
 * are encrypted at rest; reading `value` always returns plain text.
 *
 * @property int $id
 * @property int $company_id
 * @property string $key
 * @property string|null $value
 * @property string $group
 * @property string $type
 * @property bool $is_active
 */
final class Setting extends Model
{
    use BelongsToCompany;

    protected $fillable = ['company_id', 'key', 'value', 'group', 'type', 'description', 'is_active', 'created_by', 'updated_by'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public static function isSecret(string $key): bool
    {
        return in_array($key, (array) config('settings.secret_keys'), true);
    }

    public static function isPrivate(string $key): bool
    {
        return self::isSecret($key) || in_array($key, (array) config('settings.private_keys'), true);
    }

    protected static function booted(): void
    {
        // Encrypt on save, once the key is known (fill order is not guaranteed).
        self::saving(static function (Setting $setting): void {
            $raw = $setting->getAttributes()['value'] ?? null;
            if ($raw !== null && $raw !== '' && self::isSecret($setting->key) && ! self::looksEncrypted($raw)) {
                $setting->attributes['value'] = Crypt::encryptString((string) $raw);
            }
        });
    }

    /** @return Attribute<string|null, never> */
    protected function value(): Attribute
    {
        // Decided by the value alone (only our own ciphertext matches), so a
        // query that selected `value` without `key` still reads plain text.
        return Attribute::get(static function (?string $raw): ?string {
            if ($raw === null || $raw === '' || ! self::looksEncrypted($raw)) {
                return $raw;
            }
            try {
                return Crypt::decryptString($raw);
            } catch (DecryptException) {
                return $raw; // imported plaintext not yet re-saved
            }
        });
    }

    private static function looksEncrypted(string $raw): bool
    {
        $decoded = base64_decode($raw, true);

        return $decoded !== false && str_starts_with($decoded, '{"iv"');
    }
}
