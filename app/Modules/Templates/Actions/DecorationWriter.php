<?php

declare(strict_types=1);

namespace App\Modules\Templates\Actions;

use App\Core\Crud\RecordWriter;
use App\Core\Exceptions\ApiException;
use App\Core\Support\Flag;
use App\Modules\Templates\Models\Decoration;
use Illuminate\Database\Eloquent\Model;

/**
 * Decorations (v1 /decorations). The image is uploaded through /media first.
 *
 * @extends RecordWriter<Decoration>
 */
final class DecorationWriter extends RecordWriter
{
    public const TYPES = ['corner' => 'Corner', 'divider' => 'Divider', 'ornament' => 'Ornament', 'top' => 'Top', 'bottom' => 'Bottom', 'motif' => 'Motif'];

    public const FORMATS = ['PNG', 'JPG', 'WEBP', 'SVG'];

    protected function model(): string
    {
        return Decoration::class;
    }

    protected function module(): string
    {
        return 'decorations';
    }

    protected function label(): string
    {
        return 'Decoration';
    }

    protected function columns(): array
    {
        return ['name', 'type', 'file_url', 'file_name', 'file_format', 'file_size', 'is_active', 'sort_order'];
    }

    protected function defaults(): array
    {
        return ['type' => 'corner'];
    }

    protected function prepare(array $values, ?Model $existing): array
    {
        $creating = $existing === null;
        if ($creating || array_key_exists('name', $values)) {
            $values['name'] = mb_substr(trim((string) ($values['name'] ?? '')), 0, 150);
            if ($values['name'] === '') {
                throw ApiException::badRequest('Decoration name is required.');
            }
        }
        if (array_key_exists('type', $values)) {
            $values['type'] = Flag::oneOf($values['type'], array_keys(self::TYPES), 'corner');
        }
        foreach (['file_url', 'file_name'] as $key) {
            if (array_key_exists($key, $values)) {
                $values[$key] = trim((string) $values[$key]) ?: null;
            }
        }
        if (($creating || array_key_exists('file_url', $values)) && empty($values['file_url'])) {
            throw ApiException::badRequest('Please upload the decoration image.');
        }
        if (array_key_exists('file_format', $values) || array_key_exists('file_name', $values)) {
            $values['file_format'] = self::format($values['file_format'] ?? null, (string) ($values['file_name'] ?? ''));
        }
        if (array_key_exists('file_size', $values)) {
            $values['file_size'] = Flag::id($values['file_size']);
        }
        if (array_key_exists('sort_order', $values)) {
            $values['sort_order'] = Flag::clamp($values['sort_order'], 0, 999999, 0);
        }

        return $values;
    }

    /** v1 toFormat(): "image/svg+xml" or "x.svg" -> "SVG"; JPEG -> JPG; unknown -> null. */
    public static function format(mixed $raw, string $fileName = ''): ?string
    {
        $value = trim(is_scalar($raw) ? (string) $raw : '');
        if (str_contains($value, '/')) {
            $value = (string) substr($value, (int) strrpos($value, '/') + 1);
        }
        $value = explode('+', $value)[0];
        if ($value === '' && $fileName !== '') {
            $value = pathinfo($fileName, PATHINFO_EXTENSION);
        }
        $value = (string) preg_replace('/[^A-Z0-9]/', '', strtoupper($value));
        if ($value === 'JPEG') {
            $value = 'JPG';
        }

        return in_array($value, self::FORMATS, true) ? $value : null;
    }
}
