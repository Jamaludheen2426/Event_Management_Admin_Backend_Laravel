<?php

declare(strict_types=1);

namespace App\Modules\Sites\Http\Controllers;

use App\Core\Approvals\Approvals;
use App\Core\Crud\RecordWriter;
use App\Core\Http\Controllers\RecordController;
use App\Core\Http\Resources\V1Resource;
use App\Core\Queries\CallbackListQuery;
use App\Core\Queries\ListQuery;
use App\Modules\Sites\Actions\FaqWriter;
use App\Modules\Sites\Actions\Sites;
use App\Modules\Sites\Http\Resources\V1\SiteRowResource;
use App\Modules\Sites\Models\SiteFaq;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * /api/v1/faqs — the admin FAQ screen: the marketing site's FAQs, with their
 * category ({id, name}) as v1 included it.
 *
 * @extends RecordController<SiteFaq>
 */
final class FaqController extends RecordController
{
    protected string $key = 'faq';

    protected string $module = 'faqs';

    protected string $type = 'faqs';

    protected array $detail = ['category'];

    protected array $messages = [
        'created' => 'FAQ created successfully',
        'updated' => 'FAQ updated successfully',
        'status' => 'FAQ status updated successfully',
        'deleted' => 'FAQ deleted successfully',
    ];

    public function __construct(Approvals $approvals, private readonly FaqWriter $writer, private readonly Sites $sites)
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
            SiteFaq::query()->where('site_id', $this->sites->marketing()->id)->with('category'),
            ['question', 'answer'],
            ['sort_order', 'created_at'],
            true,
        ))->defaultOrder('sort_order');
    }

    protected function present(Model $model): V1Resource
    {
        return new SiteRowResource($model);
    }
}
