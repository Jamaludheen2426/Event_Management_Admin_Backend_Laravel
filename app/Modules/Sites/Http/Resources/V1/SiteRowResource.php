<?php

declare(strict_types=1);

namespace App\Modules\Sites\Http\Resources\V1;

use App\Core\Http\Resources\RowResource;
use Illuminate\Http\Request;

/** A site table row as v1 sent it (site_id left out), with its category when loaded. */
final class SiteRowResource extends RowResource
{
    protected const HIDDEN = ['alive', 'approval_status', 'site_id'];

    /** @return array<string, mixed> */
    protected function extra(Request $request): array
    {
        return $this->brief('category', ['id', 'name']);
    }
}
