<?php

declare(strict_types=1);

namespace App\Modules\Sites\Http\Controllers;

use App\Core\Approvals\Approvals;
use App\Core\Crud\RecordWriter;
use App\Core\Http\Controllers\RecordController;
use App\Core\Http\Resources\V1Resource;
use App\Core\Queries\CallbackListQuery;
use App\Core\Queries\ListQuery;
use App\Modules\Sites\Actions\FaqCategoryWriter;
use App\Modules\Sites\Actions\Sites;
use App\Modules\Sites\Http\Resources\V1\SiteRowResource;
use App\Modules\Sites\Models\SiteFaqCategory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * /api/v1/faq-categories — the admin FAQ screen's groups (the marketing site's).
 *
 * @extends RecordController<SiteFaqCategory>
 */
final class FaqCategoryController extends RecordController
{
    protected string $key = 'faqCategory';

    protected string $module = 'faq_categories';

    protected string $type = 'faq_categories';

    protected array $messages = [
        'created' => 'FAQ category created successfully',
        'updated' => 'FAQ category updated successfully',
        'status' => 'FAQ category status updated successfully',
        'deleted' => 'FAQ category deleted successfully',
    ];

    public function __construct(Approvals $approvals, private readonly FaqCategoryWriter $writer, private readonly Sites $sites)
    {
        parent::__construct($approvals);
    }

    protected function writer(): RecordWriter
    {
        return $this->writer;
    }

    protected function listQuery(Request $request): ListQuery
    {
        return (new CallbackListQuery(
            SiteFaqCategory::query()->where('site_id', $this->sites->marketing()->id),
            ['name', 'description'],
            ['sort_order', 'name', 'created_at'],
            true,
        ))->defaultOrder('sort_order');
    }

    protected function present(Model $model): V1Resource
    {
        return new SiteRowResource($model);
    }
}
