<?php

declare(strict_types=1);

namespace App\Modules\Templates\Actions;

use App\Core\Activity\ActivityLogger;
use App\Core\Contracts\ObjectStorage;
use App\Core\Exceptions\ApiException;
use App\Core\Support\Flag;
use App\Modules\Platform\Media\MediaStorage;
use App\Modules\Templates\Models\TemplateFont;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;

/**
 * Template fonts (v1 /template-fonts): an uploaded TTF/OTF/WOFF/WOFF2 file,
 * or an https link (Google Fonts stylesheet or a hosted font file).
 * Not part of the approval workflow in v1.
 */
final class TemplateFontWriter
{
    public function __construct(
        private readonly ObjectStorage $storage,
        private readonly MediaStorage $media,
        private readonly ActivityLogger $activity,
    ) {}

    /** @param array<string, mixed> $input */
    public function create(array $input, ?UploadedFile $file, ?int $userId): TemplateFont
    {
        $name = $this->name($input['name'] ?? null);
        $this->assertFree($name);

        $font = new TemplateFont([
            'name' => $name,
            'is_active' => array_key_exists('is_active', $input) ? Flag::bit($input['is_active'], 1) : 1,
            'created_by' => $userId,
        ]);

        if ($file !== null) {
            $format = TemplateFont::formatOf($file->getClientOriginalName())
                ?? throw ApiException::badRequest('Upload a TTF, OTF, WOFF or WOFF2 font file.');
            $stored = $this->storage->upload($file, 'template-fonts');
            $font->fill([
                'source' => 'upload',
                'file_url' => $stored['url'],
                'file_name' => mb_substr($file->getClientOriginalName(), 0, 255),
                'file_format' => $format[0],
                'file_size' => $file->getSize(),
            ]);
        } else {
            $font->fill(['source' => 'link', 'link_url' => $this->link($input['link_url'] ?? null)]);
        }

        $font->save();
        $this->activity->log('event_templates', 'create', "Added font: {$name}", $font);

        return $font;
    }

    /** @param array<string, mixed> $input */
    public function update(TemplateFont $font, array $input, ?int $userId): TemplateFont
    {
        $changes = ['updated_by' => $userId];
        if (array_key_exists('name', $input)) {
            $name = $this->name($input['name']);
            if ($name !== $font->name) {
                $this->assertFree($name, $font->id);
            }
            $changes['name'] = $name;
        }
        if (array_key_exists('is_active', $input)) {
            $changes['is_active'] = Flag::bit($input['is_active'], 1);
        }
        if (array_key_exists('link_url', $input) && $font->source === 'link') {
            $changes['link_url'] = $this->link($input['link_url']);
        }

        $font->fill($changes)->save();

        return $font;
    }

    public function delete(TemplateFont $font): void
    {
        $font->delete();
        $this->activity->log('event_templates', 'delete', "Deleted font: {$font->name}", $font);
    }

    /**
     * The font file itself, for @font-face (no login: browsers send none).
     *
     * @return array{bytes: string, mime: string}
     */
    public function file(int $id): array
    {
        $font = TemplateFont::query()->withoutGlobalScopes()->whereNull('deleted_at')
            ->where('is_active', 1)->where('source', 'upload')->find($id);
        if ($font === null || $font->file_url === null) {
            throw ApiException::notFound('Font not found.');
        }

        $mime = (TemplateFont::formatOf($font->file_name) ?? TemplateFont::formatOf($font->file_url))[1] ?? 'application/octet-stream';
        $path = $this->media->pathFromUrl($font->file_url);
        $bytes = $path !== null ? $this->media->read($path) : null;

        if ($bytes === null) {
            $response = Http::timeout(15)->get($font->file_url);
            if (! $response->successful()) {
                throw ApiException::notFound('That font file no longer exists.');
            }
            $bytes = $response->body();
        }

        return ['bytes' => $bytes, 'mime' => $mime];
    }

    private function name(mixed $raw): string
    {
        $name = mb_substr(trim((string) preg_replace('/\s+/', ' ', is_scalar($raw) ? (string) $raw : '')), 0, 100);
        if ($name === '') {
            throw ApiException::badRequest('Please enter the font name.');
        }
        if (! preg_match('/^[A-Za-z0-9][A-Za-z0-9 _.\-]*$/', $name)) {
            throw ApiException::badRequest('A font name may contain letters, numbers, spaces, hyphens, dots and underscores only.');
        }

        return $name;
    }

    private function link(mixed $raw): string
    {
        $link = trim(is_scalar($raw) ? (string) $raw : '');
        if ($link === '') {
            throw ApiException::badRequest('Please enter the font link.');
        }
        if (strlen($link) > 1000) {
            throw ApiException::badRequest('That link is too long.');
        }
        $parts = parse_url($link);
        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            throw ApiException::badRequest('Please enter a valid link, starting with https://');
        }
        if ($parts['scheme'] !== 'https') {
            throw ApiException::badRequest('The font link must start with https://');
        }

        return $link;
    }

    private function assertFree(string $name, ?int $exceptId = null): void
    {
        $taken = TemplateFont::query()->where('name', $name)
            ->when($exceptId !== null, static fn ($q) => $q->whereKeyNot($exceptId))->exists();
        if ($taken) {
            throw ApiException::badRequest("A font named \"{$name}\" already exists.");
        }
    }
}
