<?php

declare(strict_types=1);

namespace App\Modules\Templates\Http\Controllers;

use App\Core\Exceptions\ApiException;
use App\Core\Http\ApiResponse;
use App\Modules\Templates\Actions\TemplateFontWriter;
use App\Modules\Templates\Http\Resources\V1\TemplateFontResource;
use App\Modules\Templates\Models\TemplateFont;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Symfony\Component\HttpFoundation\Response;

/**
 * /api/v1/template-fonts — the whole list at once (not paginated, v1), plus
 * GET /:id/file (no login) for @font-face.
 */
final class TemplateFontController
{
    private const MAX_KILOBYTES = 5 * 1024;

    public function __construct(private readonly TemplateFontWriter $writer) {}

    public function index(Request $request): JsonResponse
    {
        $search = trim((string) $request->query('search', ''));
        $source = $request->query('source');
        $active = $request->query('is_active');

        $fonts = TemplateFont::query()
            ->when($search !== '', static fn ($q) => $q->where('name', 'like', "%{$search}%"))
            ->when(in_array($source, ['upload', 'link'], true), static fn ($q) => $q->where('source', $source))
            ->when($active !== null && $active !== '', static fn ($q) => $q->where('is_active', (int) $active ? 1 : 0))
            ->orderBy('name')
            ->get();

        return ApiResponse::success(['fonts' => TemplateFontResource::collection($fonts)]);
    }

    public function store(Request $request): JsonResponse
    {
        $file = $request->file('file');
        if ($file instanceof UploadedFile) {
            if ($file->getSize() > self::MAX_KILOBYTES * 1024) {
                throw ApiException::badRequest('The font file is larger than 5MB.');
            }
            if (TemplateFont::formatOf($file->getClientOriginalName()) === null) {
                throw ApiException::badRequest('Upload a TTF, OTF, WOFF or WOFF2 font file.');
            }
        }

        $font = $this->writer->create($request->except('file'), $file instanceof UploadedFile ? $file : null, $this->userId($request));

        return ApiResponse::created(['font' => new TemplateFontResource($font)], 'Font added successfully');
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $font = $this->writer->update($this->find($id), $request->all(), $this->userId($request));

        return ApiResponse::success(['font' => new TemplateFontResource($font)], 'Font updated successfully');
    }

    public function destroy(int $id): JsonResponse
    {
        $this->writer->delete($this->find($id));

        return ApiResponse::success(null, 'Font deleted successfully');
    }

    /** The font file itself. Public; a replaced font is a new row, so cache forever. */
    public function file(int $id): Response
    {
        ['bytes' => $bytes, 'mime' => $mime] = $this->writer->file($id);

        return new Response($bytes, 200, [
            'Content-Type' => $mime,
            'Content-Length' => (string) strlen($bytes),
            'Access-Control-Allow-Origin' => '*',
            'Cross-Origin-Resource-Policy' => 'cross-origin',
            'Cache-Control' => 'public, max-age=31536000, immutable',
        ]);
    }

    private function find(int $id): TemplateFont
    {
        return TemplateFont::query()->find($id) ?? throw ApiException::notFound('Font not found.');
    }

    private function userId(Request $request): ?int
    {
        $id = $request->user()?->getAuthIdentifier();

        return is_numeric($id) ? (int) $id : null;
    }
}
