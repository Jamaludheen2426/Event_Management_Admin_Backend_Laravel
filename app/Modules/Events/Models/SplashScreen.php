<?php

declare(strict_types=1);

namespace App\Modules\Events\Models;

use App\Core\Database\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * The mobile app's opening screen for one event (one per event).
 *
 * @property int $id
 * @property int $client_id
 * @property int|null $event_id
 * @property string $name
 * @property string|null $theme_id
 * @property array<string, mixed>|null $background_config
 * @property array<string, mixed>|null $components
 * @property string $status
 */
final class SplashScreen extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'client_id', 'event_id', 'name', 'main_title', 'sub_title', 'event_name', 'theme_id', 'tagline', 'background_type', 'background_url',
        'background_config', 'loader_enabled', 'loader_config', 'primary_color', 'secondary_color', 'bg_color', 'decoration_url',
        'show_decoration', 'components', 'component_order', 'status',
    ];

    protected $attributes = ['background_type' => 'image', 'loader_enabled' => true, 'show_decoration' => true, 'status' => 'draft'];

    protected function casts(): array
    {
        return [
            'background_config' => 'array', 'loader_config' => 'array', 'components' => 'array', 'component_order' => 'array',
            'loader_enabled' => 'boolean', 'show_decoration' => 'boolean',
        ];
    }
}
