<?php

declare(strict_types=1);

namespace App\Modules\Clients\Models;

use App\Core\Database\Model;
use Illuminate\Support\Carbon;

/**
 * A client's display and quiet-hours settings (one row per client).
 *
 * @property int $id
 * @property int $client_id
 * @property Carbon|null $dnd_starts_at
 * @property Carbon|null $dnd_ends_at
 */
final class ClientPreference extends Model
{
    /** Column defaults, also sent to the settings screen as "defaults". */
    public const DEFAULTS = [
        'language_code' => 'en', 'date_format' => 'DD/MM/YYYY', 'time_zone' => 'Asia/Kolkata', 'theme' => 'light',
        'default_landing' => 'dashboard', 'items_per_page' => 20, 'compact_mode' => false, 'auto_save' => true,
        'show_tips' => true, 'emails_disabled' => false, 'in_app_disabled' => false,
    ];

    protected $fillable = [
        'client_id', 'language_code', 'date_format', 'time_zone', 'theme', 'default_landing', 'items_per_page',
        'compact_mode', 'auto_save', 'show_tips', 'emails_disabled', 'in_app_disabled', 'dnd_starts_at', 'dnd_ends_at',
    ];

    protected function casts(): array
    {
        return [
            'items_per_page' => 'integer',
            'compact_mode' => 'boolean',
            'auto_save' => 'boolean',
            'show_tips' => 'boolean',
            'emails_disabled' => 'boolean',
            'in_app_disabled' => 'boolean',
            'dnd_starts_at' => 'datetime',
            'dnd_ends_at' => 'datetime',
        ];
    }
}
