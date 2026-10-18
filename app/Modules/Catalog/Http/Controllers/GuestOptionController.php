<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Controllers;

use App\Core\Approvals\Approvals;
use App\Core\Crud\RecordWriter;
use App\Core\Http\Controllers\RecordController;
use App\Core\Http\Resources\V1Resource;
use App\Core\Queries\CallbackListQuery;
use App\Core\Queries\ListQuery;
use App\Modules\Catalog\Actions\FoodPreferenceOptionWriter;
use App\Modules\Catalog\Actions\GuestOptionWriter;
use App\Modules\Catalog\Actions\RelationshipOptionWriter;
use App\Modules\Catalog\Http\Resources\V1\GuestOptionResource;
use App\Modules\Catalog\Models\GuestOption;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * /api/v1/guest-relationship-options and /api/v1/guest-food-preference-options.
 * One controller; the route passes which list (`kind` route default).
 * ?event_category_id=<id> | null filters (null = the fallback list).
 *
 * @extends RecordController<GuestOption>
 */
final class GuestOptionController extends RecordController
{
    protected string $key = 'option';

    protected string $module = 'guest_relationship_options';

    protected string $type = 'guest_relationship_option';

    /** @var list<string> */
    protected array $detail = ['category'];

    protected array $messages = [
        'created' => 'Option created successfully',
        'updated' => 'Option updated successfully',
        'status' => 'Option status updated successfully',
        'deleted' => 'Option deleted successfully',
    ];

    private GuestOptionWriter $writer;

    public function __construct(Approvals $approvals, Request $request)
    {
        parent::__construct($approvals);

        $food = $request->route('kind') === GuestOption::FOOD_PREFERENCE;
        $this->writer = app($food ? FoodPreferenceOptionWriter::class : RelationshipOptionWriter::class);
        $this->key = $food ? 'foodPreferenceOption' : 'relationshipOption';
        $this->module = $food ? 'guest_food_preference_options' : 'guest_relationship_options';
        $this->type = $food ? 'guest_food_preference_option' : 'guest_relationship_option';
    }

    protected function writer(): RecordWriter
    {
        return $this->writer;
    }

    protected function listQuery(Request $request): ListQuery
    {
        $category = $request->query('event_category_id');

        return (new CallbackListQuery(
            GuestOption::query()->where('kind', $this->writer->kind())->with('category'),
            ['name', 'description'],
            ['sort_order', 'name', 'created_at'],
            true,
            static function (Builder $query) use ($category): void {
                if ($category === 'null') {
                    $query->whereNull('event_category_id');
                } elseif (is_numeric($category) && (int) $category > 0) {
                    $query->where('event_category_id', (int) $category);
                }
            },
        ))->defaultOrder('sort_order');
    }

    protected function present(Model $model): V1Resource
    {
        return new GuestOptionResource($model);
    }
}
