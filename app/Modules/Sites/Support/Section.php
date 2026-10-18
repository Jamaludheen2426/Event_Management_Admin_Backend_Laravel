<?php

declare(strict_types=1);

namespace App\Modules\Sites\Support;

use App\Modules\Sites\Models\Site;
use App\Modules\Sites\Models\SiteRecord;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * One list section of a site (testimonials, pages, gallery items...): which
 * table holds it and the few ways it differs from the others. SiteContent
 * runs every section with the same create / update / delete / replace steps.
 */
final class Section
{
    /**
     * @param  class-string<SiteRecord>  $model
     * @param  array<string, mixed>  $fixed  column values every row of the section has (logos: type)
     * @param  list<array{0: string, 1: string}>  $order  [column, asc|desc]
     * @param  array<string, string>  $aliases  v1 alternative input key => column
     * @param  array<string, class-string<SiteRecord>>  $references  column => the section table it must point into (else null)
     * @param  array<string, list<string>>  $vendorEnums  column => allowed values on vendor sites (v1 ENUM columns)
     * @param  (Closure(array<string, mixed>, Site): array<string, mixed>)|null  $normalize  input → column values
     * @param  (Closure(Site, array<string, mixed>): array<string, mixed>)|null  $defaults  values a new row starts with
     * @param  (Closure(Builder<SiteRecord>, Request): void)|null  $filter  list filters (?search=, ?category_id=)
     * @param  (Closure(array<string, mixed>, SiteRecord): array<string, mixed>)|null  $present  extra keys on each row
     * @param  list<string>  $hidden  columns never sent
     * @param  array<string, string>  $messages  created / updated / deleted / listed / saved / status
     * @param  string  $folder  where pasted (base64) images are stored
     * @param  list<string>  $with  relations $present reads
     */
    public function __construct(
        public readonly string $model,
        public readonly array $fixed = [],
        public readonly array $order = [['sort_order', 'asc'], ['id', 'asc']],
        public readonly array $aliases = [],
        public readonly array $references = [],
        public readonly array $vendorEnums = [],
        public readonly ?Closure $normalize = null,
        public readonly ?Closure $defaults = null,
        public readonly ?Closure $filter = null,
        public readonly ?Closure $present = null,
        public readonly array $hidden = [],
        public readonly array $messages = [],
        public readonly string $folder = 'website-builder',
        public readonly array $with = [],
    ) {}

    public function message(string $action, string $fallback): string
    {
        return $this->messages[$action] ?? $fallback;
    }
}
