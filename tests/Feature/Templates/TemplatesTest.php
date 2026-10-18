<?php

declare(strict_types=1);

use App\Core\Contracts\ObjectStorage;
use App\Modules\Catalog\Models\EventCategory;
use App\Modules\Catalog\Models\Plan;
use App\Modules\Templates\Models\Decoration;
use App\Modules\Templates\Models\FrameStyle;
use App\Modules\Templates\Models\TemplateCategory;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;

/*
| Invitation design catalogue: styles, frames, decorations, fonts, templates.
*/

beforeEach(function () {
    seedHouse();
    $this->token = accessToken(adminUser());
    $this->uploads = storage_path('framework/testing/uploads-'.uniqid());
    config(['media.local_root' => $this->uploads, 'media.local_url' => 'http://api.test/uploads']);
});

afterEach(function () {
    File::deleteDirectory($this->uploads);
});

function frame(TemplateCategory $category, array $state = []): FrameStyle
{
    return FrameStyle::query()->create([
        'company_id' => 1, 'name' => 'Gold border', 'template_category_id' => $category->id,
        'file_url' => 'http://api.test/uploads/frame.svg', 'status' => 'published', ...$state,
    ]);
}

it('keeps style slugs unique, counts frames per style, and says which frames a delete orphans', function () {
    $floral = $this->withToken($this->token)->postJson('/api/v1/template-categories', ['name' => 'Floral'])->assertCreated()->json('data.category');
    $again = $this->withToken($this->token)->postJson('/api/v1/template-categories', ['name' => 'Floral'])->json('data.category');
    expect($floral['slug'])->toBe('floral')->and($again['slug'])->toBe('floral-2');

    frame(TemplateCategory::query()->find($floral['id']));
    $this->withToken($this->token)->getJson('/api/v1/template-categories')->assertJsonPath('data.0.frame_styles_count', 1);

    $this->withToken($this->token)->deleteJson("/api/v1/template-categories/{$floral['id']}")
        ->assertOk()->assertJsonPath('message', 'Category deleted. 1 frame style became uncategorised.');
    expect(FrameStyle::query()->first()->template_category_id)->toBeNull();
});

it('requires a category and a file for frame styles and normalises the layouts', function () {
    $category = TemplateCategory::query()->create(['company_id' => 1, 'name' => 'Royal', 'slug' => 'royal']);

    $this->withToken($this->token)->postJson('/api/v1/frame-styles', ['name' => 'X', 'file_url' => 'http://x/a.svg'])
        ->assertStatus(400)->assertJsonPath('message', 'Category is required.');
    $this->withToken($this->token)->postJson('/api/v1/frame-styles', ['name' => 'X', 'template_category_id' => $category->id])
        ->assertStatus(400)->assertJsonPath('message', 'Please upload the frame / border file.');

    $this->withToken($this->token)->postJson('/api/v1/frame-styles', [
        'name' => 'Arch', 'template_category_id' => $category->id, 'file_url' => 'http://x/a.svg', 'supported_layouts' => 'square,portrait,bogus',
    ])->assertCreated()
        ->assertJsonPath('data.frameStyle.supported_layouts', ['portrait', 'square'])
        ->assertJsonPath('data.frameStyle.supported_layouts_label', 'Portrait, Square')
        ->assertJsonPath('data.frameStyle.category.slug', 'royal');

    $this->withToken($this->token)->getJson('/api/v1/frame-styles?layout=landscape')->assertJsonPath('pagination.totalItems', 0);
    $this->withToken($this->token)->getJson('/api/v1/frame-styles?layout=square')->assertJsonPath('pagination.totalItems', 1);
    $this->withToken($this->token)->getJson('/api/v1/frame-styles/stats')->assertJsonPath('data.stats', ['total' => 1, 'active' => 1, 'inactive' => 0, 'draft' => 0]);
});

it('labels decorations by type, format and size', function () {
    $this->withToken($this->token)->postJson('/api/v1/decorations', [
        'name' => 'Leaf', 'type' => 'DIVIDER', 'file_url' => 'http://x/leaf.svg', 'file_name' => 'leaf.svg', 'file_format' => 'image/svg+xml', 'file_size' => 250000,
    ])->assertCreated()
        ->assertJsonPath('data.decoration.type', 'divider')
        ->assertJsonPath('data.decoration.type_label', 'Divider')
        ->assertJsonPath('data.decoration.file_format', 'SVG')
        ->assertJsonPath('data.decoration.file_size_label', '244 KB');

    $this->withToken($this->token)->getJson('/api/v1/decorations?file_format=svg')->assertJsonPath('pagination.totalItems', 1);
    $this->withToken($this->token)->getJson('/api/v1/decorations/stats')->assertJsonPath('data.stats.total_size_label', '244 KB');
});

it('recolours an SVG upload into a new file and reports the palette', function () {
    $url = app(ObjectStorage::class)->put('<svg><path fill="#f00"/><path fill="#FF0000"/><path fill="#0000ff"/></svg>', 'image/svg+xml', 'flower.svg', 'decorations')['url'];

    $this->withToken($this->token)->getJson('/api/v1/decorations/svg-source?file_url='.urlencode($url))
        ->assertOk()->assertJsonPath('data.colors.0', ['color' => '#FF0000', 'count' => 2]);

    $result = $this->withToken($this->token)->postJson('/api/v1/decorations/recolor', [
        'file_url' => $url, 'color_map' => [['from' => '#ff0000', 'to' => '#00ff00']], 'file_name' => 'flower.svg',
    ])->assertOk()->assertJsonPath('data.replaced', 2)->assertJsonPath('data.file_name', 'flower-recoloured.svg')->json('data');

    expect($result['url'])->not->toBe($url);
    $this->withToken($this->token)->postJson('/api/v1/decorations/recolor', ['file_url' => $url, 'color_map' => ['#123456' => '#654321']])
        ->assertStatus(400)->assertJsonPath('message', 'None of those colours appear in this decoration.');
    $this->withToken($this->token)->getJson('/api/v1/frame-styles/svg-source?file_url='.urlencode('http://169.254.169.254/x.svg'))
        ->assertStatus(400)->assertJsonPath('message', 'That file is not a frame style upload.');
});

it('builds an event template the way the v1 wizard stored it', function () {
    $wedding = EventCategory::query()->create(['company_id' => 1, 'name' => 'Wedding']);
    $royal = TemplateCategory::query()->create(['company_id' => 1, 'name' => 'Royal', 'slug' => 'royal']);
    $frame = frame($royal);
    $draftFrame = frame($royal, ['status' => 'draft']);
    $a = Decoration::query()->create(['company_id' => 1, 'name' => 'A', 'file_url' => 'http://x/a.png']);
    $b = Decoration::query()->create(['company_id' => 1, 'name' => 'B', 'file_url' => 'http://x/b.png']);
    $off = Decoration::query()->create(['company_id' => 1, 'name' => 'Off', 'file_url' => 'http://x/c.png', 'is_active' => 0]);
    $gold = Plan::query()->create(['company_id' => 1, 'name' => 'Gold', 'plan_code' => 'GOLD']);

    $template = $this->withToken($this->token)->postJson('/api/v1/event-templates', [
        'name' => 'Royal Wedding', 'eventCategoryId' => $wedding->id, 'templateCategoryId' => $royal->id, 'style' => 'floral',
        'frameStyleId' => $frame->id, 'decorationIds' => [$b->id, $off->id, $a->id], 'backgroundColor' => '#abcdef', 'gradientVia' => 'nope',
        'imageSize' => 999, 'components' => ['venue' => 0], 'componentOrder' => ['venue', 'bogus'], 'availableFor' => ['both'],
        'planAvailability' => 'selected', 'planIds' => [$gold->id], 'tags' => 'gold, royal, gold',
    ])->assertCreated()
        ->assertJsonPath('data.template.code', 'royal-wedding')
        ->assertJsonPath('data.template.style', 'royal')
        ->assertJsonPath('data.template.background_color', '#ABCDEF')
        ->assertJsonPath('data.template.gradient_via', null)
        ->assertJsonPath('data.template.image_size', 400)
        ->assertJsonPath('data.template.components.venue', 0)
        ->assertJsonPath('data.template.components.host_names', 1)
        ->assertJsonPath('data.template.component_order.0', 'venue')
        ->assertJsonPath('data.template.available_for', ['individual', 'company'])
        ->assertJsonPath('data.template.decoration_ids', [$b->id, $a->id])
        ->assertJsonPath('data.template.decorationItems.0.name', 'B')
        ->assertJsonPath('data.template.plan_ids', [$gold->id])
        ->assertJsonPath('data.template.tags', ['gold', 'royal'])
        ->assertJsonPath('data.template.frameStyle.id', $frame->id)
        ->json('data.template');

    $this->withToken($this->token)->postJson('/api/v1/event-templates', ['name' => 'X', 'event_category_id' => $wedding->id, 'frame_style_id' => $draftFrame->id])
        ->assertStatus(400)->assertJsonPath('message', 'That frame style is not available. Publish and activate it first.');
    $this->withToken($this->token)->postJson('/api/v1/event-templates', ['name' => 'X'])
        ->assertStatus(400)->assertJsonPath('message', 'Event category is required.');

    $this->withToken($this->token)->patchJson("/api/v1/event-templates/{$template['id']}/featured", ['is_featured' => true])
        ->assertJsonPath('data.template.is_featured', 1);
    $this->withToken($this->token)->postJson("/api/v1/event-templates/{$template['id']}/duplicate")
        ->assertCreated()
        ->assertJsonPath('data.template.code', 'royal-wedding-copy')
        ->assertJsonPath('data.template.status', 'draft')
        ->assertJsonPath('data.template.is_featured', 0)
        ->assertJsonPath('data.template.decoration_ids', [$b->id, $a->id]);
    $this->withToken($this->token)->getJson('/api/v1/event-templates/stats')->assertJsonPath('data.stats.featured', 1);
});

it('adds fonts by link or upload and serves uploaded files without login', function () {
    $this->withToken($this->token)->postJson('/api/v1/template-fonts', ['name' => 'Great Vibes', 'link_url' => 'https://fonts.googleapis.com/css2?family=Great+Vibes'])
        ->assertCreated()->assertJsonPath('data.font.source', 'link')->assertJsonPath('data.font.link_kind', 'stylesheet');
    $this->withToken($this->token)->postJson('/api/v1/template-fonts', ['name' => 'Other', 'link_url' => 'http://insecure.example.com/f.css'])
        ->assertStatus(400)->assertJsonPath('message', 'The font link must start with https://');
    $this->withToken($this->token)->postJson('/api/v1/template-fonts', ['name' => 'Great Vibes', 'link_url' => 'https://x.example.com/a.woff2'])
        ->assertStatus(400)->assertJsonPath('message', 'A font named "Great Vibes" already exists.');

    $font = $this->withToken($this->token)->post('/api/v1/template-fonts', [
        'name' => 'Brand Sans', 'file' => UploadedFile::fake()->createWithContent('brand.woff2', 'wOF2-bytes'),
    ], ['Accept' => 'application/json'])->assertCreated()->assertJsonPath('data.font.file_format', 'WOFF2')->json('data.font');

    $this->app['auth']->forgetGuards();
    $response = $this->get("/api/v1/template-fonts/{$font['id']}/file")->assertOk();
    expect($response->getContent())->toBe('wOF2-bytes')
        ->and($response->headers->get('Content-Type'))->toBe('font/woff2')
        ->and($response->headers->get('Access-Control-Allow-Origin'))->toBe('*');

    $this->withToken($this->token)->getJson('/api/v1/template-fonts')->assertJsonCount(2, 'data.fonts');
});
