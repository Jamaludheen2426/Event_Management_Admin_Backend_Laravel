<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Resources\V1;

use App\Core\Http\Resources\RowResource;
use Illuminate\Http\Request;

/** Guest dropdown option + its event category {id, name}. `kind` is internal. */
final class GuestOptionResource extends RowResource
{
    protected const HIDDEN = ['alive', 'approval_status', 'kind'];

    protected function extra(Request $request): array
    {
        return $this->brief('category', ['id', 'name']);
    }
}
