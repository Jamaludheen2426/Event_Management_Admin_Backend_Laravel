<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Resources\V1;

use App\Core\Http\Resources\RowResource;
use Illuminate\Http\Request;

/** Event menu row + category {id, name, color} (+ creator/updater on detail). */
final class EventMenuResource extends RowResource
{
    protected function extra(Request $request): array
    {
        return [...$this->brief('category', ['id', 'name', 'color']), ...$this->authors()];
    }
}
