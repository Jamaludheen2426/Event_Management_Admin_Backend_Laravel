<?php

declare(strict_types=1);

namespace App\Modules\Identity\Models\Concerns;

use App\Modules\Identity\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * created_by / updated_by as relations — the "Created By / Updated By" lines
 * the admin detail screens show (v1 `creator` / `updater` includes).
 * Deleted employees still show (withTrashed).
 */
trait HasAuthors
{
    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by')->withTrashed()->withoutGlobalScopes();
    }

    /** @return BelongsTo<User, $this> */
    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by')->withTrashed()->withoutGlobalScopes();
    }
}
