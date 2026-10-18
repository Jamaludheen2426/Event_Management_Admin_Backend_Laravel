<?php

declare(strict_types=1);

namespace App\Modules\Templates\Models;

use App\Core\Database\Model;
use App\Core\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A font templates can use: an uploaded file, or a link (Google Fonts
 * stylesheet or a font file hosted elsewhere).
 *
 * @property int $id
 * @property int $company_id
 * @property string $name
 * @property string $source
 * @property string|null $file_url
 * @property string|null $file_name
 * @property string|null $link_url
 * @property int $is_active
 */
final class TemplateFont extends Model
{
    use BelongsToCompany;
    use SoftDeletes;

    public const FORMATS = [
        'ttf' => ['TTF', 'font/ttf'],
        'otf' => ['OTF', 'font/otf'],
        'woff' => ['WOFF', 'font/woff'],
        'woff2' => ['WOFF2', 'font/woff2'],
    ];

    protected $fillable = [
        'company_id', 'name', 'source', 'file_url', 'file_name', 'file_format', 'file_size', 'link_url',
        'is_active', 'created_by', 'updated_by',
    ];

    /**
     * [format label, mime type] for a file name or URL, or null when not a font file.
     *
     * @return array{0: string, 1: string}|null
     */
    public static function formatOf(?string $name): ?array
    {
        $extension = strtolower(pathinfo((string) strtok((string) $name, '?'), PATHINFO_EXTENSION));

        return self::FORMATS[$extension] ?? null;
    }
}
