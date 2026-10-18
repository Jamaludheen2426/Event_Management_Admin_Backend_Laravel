<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Actions;

use App\Core\Crud\RecordWriter;
use App\Core\Exceptions\ApiException;
use Illuminate\Database\Eloquent\Model;

/**
 * Lists whose rows are a name plus looks (icon, colour, order): event
 * categories and notification categories. Name required and unique per company.
 *
 * @template TModel of Model
 *
 * @extends RecordWriter<TModel>
 */
abstract class NamedListWriter extends RecordWriter
{
    /** "event category" — used in "An event category named ... already exists." */
    abstract protected function noun(): string;

    protected function columns(): array
    {
        return ['name', 'description', 'icon', 'color', 'sort_order', 'is_active'];
    }

    protected function prepare(array $values, ?Model $existing): array
    {
        if ($existing === null || array_key_exists('name', $values)) {
            $values['name'] = trim((string) ($values['name'] ?? ''));
            if ($values['name'] === '') {
                throw ApiException::badRequest('Category name is required');
            }
        }

        return $values;
    }

    protected function assertUnique(array $values, ?int $exceptId): void
    {
        if ($this->taken($this->query(), ['name' => $values['name']], $exceptId)) {
            $article = in_array(strtolower($this->noun()[0]), ['a', 'e', 'i', 'o', 'u'], true) ? 'An' : 'A';
            throw ApiException::badRequest("{$article} {$this->noun()} named \"{$values['name']}\" already exists.");
        }
    }
}
