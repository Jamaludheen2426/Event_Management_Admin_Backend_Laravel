<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Actions;

use App\Core\Exceptions\ApiException;
use App\Core\Support\Dates;
use App\Modules\Messaging\Models\VendorEmailCategory;
use App\Modules\Messaging\Models\VendorEmailTemplate;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * A vendor's own e-mail categories and templates, used by its newsletter
 * (v1 vendorEmailCategory + vendorEmailTemplate services). Rows are the
 * signed-in vendor's only (vendor scope).
 */
final class VendorEmails
{
    private const CATEGORY_SORTS = ['sort_order', 'name', 'created_at'];

    private const TEMPLATE_SORTS = ['created_at', 'name'];

    /**
     * @param  array<string, mixed>  $query
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function categories(array $query): LengthAwarePaginator
    {
        $search = trim((string) ($query['search'] ?? ''));
        $rows = VendorEmailCategory::query()
            ->when($search !== '', static fn (Builder $q) => $q->where(static fn (Builder $w) => $w->where('name', 'like', "%{$search}%")->orWhere('description', 'like', "%{$search}%")));

        return $this->page($rows, $query, self::CATEGORY_SORTS, 'sort_order', 'asc', 20)
            ->through(fn (VendorEmailCategory $c): array => $this->category($c));
    }

    /** @return array<string, mixed> */
    public function category(VendorEmailCategory $c): array
    {
        return [
            'id' => $c->id, 'vendor_id' => $c->vendor_id, 'name' => $c->name, 'description' => $c->description,
            'sort_order' => $c->sort_order, 'is_active' => (int) $c->is_active, ...$this->times($c),
        ];
    }

    public function findCategory(int $id): VendorEmailCategory
    {
        return VendorEmailCategory::query()->find($id) ?? throw ApiException::notFound('Email category not found');
    }

    /** @param array<string, mixed> $input */
    public function saveCategory(?VendorEmailCategory $category, array $input): VendorEmailCategory
    {
        $name = trim((string) ($input['name'] ?? $category->name ?? ''));
        if ($name === '') {
            throw ApiException::badRequest('Name is required');
        }
        $taken = VendorEmailCategory::query()->where('name', $name)
            ->when($category !== null, static fn (Builder $q) => $q->whereKeyNot($category->id))->exists();
        if ($taken) {
            throw ApiException::badRequest("Category \"{$name}\" already exists");
        }
        $category ??= new VendorEmailCategory;
        $category->fill([
            'name' => $name,
            'description' => array_key_exists('description', $input) ? $input['description'] : $category->description,
            'sort_order' => array_key_exists('sort_order', $input) ? (int) $input['sort_order'] : $category->sort_order,
            'is_active' => array_key_exists('is_active', $input) ? (int) filter_var($input['is_active'], FILTER_VALIDATE_BOOL) : $category->is_active,
        ])->save();

        return $category;
    }

    /**
     * @param  array<string, mixed>  $query
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function templates(array $query): LengthAwarePaginator
    {
        $search = trim((string) ($query['search'] ?? ''));
        $rows = VendorEmailTemplate::query()->with('category:id,name')
            ->when($search !== '', static fn (Builder $q) => $q->where('name', 'like', "%{$search}%"))
            ->when(is_numeric($query['category_id'] ?? null), static fn (Builder $q) => $q->where('category_id', (int) $query['category_id']));

        return $this->page($rows, $query, self::TEMPLATE_SORTS, 'created_at', 'desc', 10)
            ->through(fn (VendorEmailTemplate $t): array => $this->template($t));
    }

    /** @return array<string, mixed> */
    public function template(VendorEmailTemplate $t): array
    {
        return [
            'id' => $t->id, 'vendor_id' => $t->vendor_id, 'category_id' => $t->category_id, 'name' => $t->name,
            'description' => $t->description, 'is_active' => (int) $t->is_active, ...$this->times($t),
            'category' => $t->relationLoaded('category') && $t->category !== null ? ['id' => $t->category->id, 'name' => $t->category->name] : null,
        ];
    }

    public function findTemplate(int $id): VendorEmailTemplate
    {
        return VendorEmailTemplate::query()->with('category:id,name')->find($id) ?? throw ApiException::notFound('Email template not found');
    }

    /** @param array<string, mixed> $input */
    public function saveTemplate(?VendorEmailTemplate $template, array $input): VendorEmailTemplate
    {
        $name = trim((string) ($input['name'] ?? $template->name ?? ''));
        if ($name === '') {
            throw ApiException::badRequest('Name is required');
        }
        $categoryId = array_key_exists('category_id', $input) ? (is_numeric($input['category_id']) ? (int) $input['category_id'] : null) : $template?->category_id;
        if ($categoryId !== null && $categoryId !== $template?->category_id && ! VendorEmailCategory::query()->whereKey($categoryId)->exists()) {
            throw ApiException::badRequest('Invalid category');
        }
        $template ??= new VendorEmailTemplate;
        $template->fill([
            'name' => $name, 'category_id' => $categoryId,
            'description' => array_key_exists('description', $input) ? $input['description'] : $template->description,
            'is_active' => array_key_exists('is_active', $input) ? (int) filter_var($input['is_active'], FILTER_VALIDATE_BOOL) : $template->is_active,
        ])->save();

        return $this->findTemplate($template->id);
    }

    public function toggleTemplate(VendorEmailTemplate $template): VendorEmailTemplate
    {
        $template->forceFill(['is_active' => (int) $template->is_active === 1 ? 0 : 1])->save();

        return $template;
    }

    /** v1 tables used createdAt / updatedAt / deletedAt columns, so their rows carried those keys. */
    /** @return array<string, string|null> */
    private function times(Model $m): array
    {
        return ['createdAt' => Dates::at($m->getAttribute('created_at')), 'updatedAt' => Dates::at($m->getAttribute('updated_at')), 'deletedAt' => null];
    }

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $rows
     * @param  array<string, mixed>  $query
     * @param  list<string>  $sorts
     * @return LengthAwarePaginator<int, TModel>
     */
    private function page(Builder $rows, array $query, array $sorts, string $sort, string $direction, int $limit): LengthAwarePaginator
    {
        $by = (string) ($query['sort_by'] ?? $sort);
        $by = $by === 'createdAt' ? 'created_at' : $by;
        $dir = strtolower((string) ($query['sort_order'] ?? $direction)) === 'asc' ? 'asc' : 'desc';

        return $rows->orderBy(in_array($by, $sorts, true) ? $by : $sort, $dir)->orderBy('id')
            ->paginate(min(100, max(1, (int) ($query['limit'] ?? $limit) ?: $limit)), ['*'], 'page', max(1, (int) ($query['page'] ?? 1)));
    }
}
