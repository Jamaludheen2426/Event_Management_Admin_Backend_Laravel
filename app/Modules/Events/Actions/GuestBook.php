<?php

declare(strict_types=1);

namespace App\Modules\Events\Actions;

use App\Core\Contracts\ObjectStorage;
use App\Core\Exceptions\ApiException;
use App\Modules\Catalog\Models\GuestOption;
use App\Modules\Catalog\Models\Plan;
use App\Modules\Clients\Events\ClientNotice;
use App\Modules\Clients\Models\Client;
use App\Modules\Events\Models\Event;
use App\Modules\Events\Models\Guest;
use App\Modules\Events\Models\GuestGroup;
use App\Modules\Events\Support\GuestFields;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * A client's phone book of guests (v1 clientGuest.service, §581). Guests are
 * never attached to an event by being invited; a participant links back to a
 * guest by mobile when they join. One mobile number = one guest. The plan's
 * max_guests_per_event caps the whole phone book (v1).
 */
final class GuestBook
{
    public function __construct(private readonly ObjectStorage $storage) {}

    /** @return Builder<Guest> the client's guests with the number of events each joined */
    public function query(Client $client): Builder
    {
        return Guest::query()->where('guests.client_id', $client->id)
            ->with('group:id,name,color')
            ->withCount('participations as events_joined');
    }

    /** @param Builder<Guest> $query */
    public function filter(Builder $query, Request $request): void
    {
        $tab = mb_strtolower((string) $request->query('status', 'all'));
        $search = trim((string) $request->query('search', ''));
        $group = $request->query('group_id');

        match ($tab) {
            'imported' => $query->where('source', 'import'),
            'joined' => $query->has('participations'),
            'not_joined' => $query->doesntHave('participations'),
            default => null,
        };
        if (is_numeric($group) && (int) $group > 0) {
            $query->where('group_id', (int) $group);
        } elseif ($group === '0') {
            $query->whereNull('group_id');
        }
        if ($search !== '') {
            $query->where(static fn ($q) => $q->where('name', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%")
                ->orWhere('mobile', 'like', "%{$search}%")->orWhere('company', 'like', "%{$search}%"));
        }
    }

    public function find(Client $client, int $id): Guest
    {
        return $this->query($client)->find($id) ?? throw ApiException::notFound('Guest not found.');
    }

    /** @param array<string, mixed> $input */
    public function create(Client $client, array $input): Guest
    {
        $values = GuestFields::person($client->id, $input, partial: false);

        $guest = DB::transaction(function () use ($client, $values): Guest {
            $this->assertCapacity($client, 1);
            $this->assertNumberFree($client, $values['mobile'] ?? null);
            if (! array_key_exists('group_id', $values)) {
                $values['group_id'] = GuestGroup::query()->where('client_id', $client->id)->where('is_default', true)->value('id');
            }

            return Guest::query()->create([...$values, 'client_id' => $client->id, 'source' => 'manual']);
        });
        ClientNotice::dispatch($client->id, 'guest_added', 'New guest added', "{$guest->name} was added to your guest list.", "/dashboard/guests/{$guest->id}");

        return $this->find($client, $guest->id);
    }

    /** @param array<string, mixed> $input */
    public function update(Client $client, Guest $guest, array $input): Guest
    {
        $values = GuestFields::person($client->id, $input, partial: true, existing: $guest->attributesToArray());
        if (! empty($values['mobile'])) {
            $this->assertNumberFree($client, $values['mobile'], $guest->id);
        }
        $guest->fill($values)->save();

        return $this->find($client, $guest->id);
    }

    /** @return array{action: string, affected: int} */
    public function bulk(Client $client, mixed $ids, mixed $action, mixed $value): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', is_array($ids) ? $ids : []))));
        if ($ids === []) {
            throw ApiException::badRequest('No guests selected.');
        }
        if (count($ids) > 500) {
            throw ApiException::badRequest('Select 500 guests or fewer at a time.');
        }
        $scope = Guest::query()->where('client_id', $client->id)->whereIn('id', $ids);

        return match ($action) {
            'delete' => ['action' => 'delete', 'affected' => $scope->get()->each->delete()->count()],
            'group' => ['action' => 'group', 'affected' => $scope->update(['group_id' => $this->ownGroup($client, $value)])],
            'status' => throw ApiException::badRequest('RSVP status is set per event — change it on the RSVP screen.'),
            default => throw ApiException::badRequest('Unknown bulk action.'),
        };
    }

    /** @return array<string, int|float> */
    public function stats(Client $client): array
    {
        $guests = Guest::query()->where('client_id', $client->id)->withCount('participations')->get(['id', 'group_id', 'source']);
        $total = $guests->count();
        $grouped = $guests->whereNotNull('group_id')->count();
        $joined = $guests->where('participations_count', '>', 0)->count();
        $pct = static fn (int $n): float => $total > 0 ? round($n / $total * 100, 1) : 0;

        return [
            'grouped' => $grouped, 'grouped_pct' => $pct($grouped),
            'ungrouped' => $total - $grouped, 'ungrouped_pct' => $pct($total - $grouped),
            'imported' => $guests->where('source', 'import')->count(),
            'joined' => $joined, 'joined_pct' => $pct($joined),
            'total_guests' => $total, 'total_rows' => $total,
        ];
    }

    /** @return array{limit: int|null, used: int, full: bool, remaining: int|null} */
    public function capacity(Client $client): array
    {
        $limit = $this->limit($client);
        $used = Guest::query()->where('client_id', $client->id)->count();

        return ['limit' => $limit, 'used' => $used, 'full' => $limit !== null && $used >= $limit, 'remaining' => $limit === null ? null : max(0, $limit - $used)];
    }

    /**
     * Relationship / food dropdowns for the event's category (or the general ones).
     *
     * @return array<string, mixed>
     */
    public function formOptions(Client $client, mixed $eventId): array
    {
        $category = null;
        if (is_numeric($eventId) && (int) $eventId > 0) {
            $event = Event::query()->where('client_id', $client->id)->find((int) $eventId, ['id', 'event_category_id']) ?? throw ApiException::notFound('Event not found.');
            $category = $event->event_category_id;
        }

        return [
            'relationship_options' => $this->options('relationship', $client->tenantCompanyId(), $category),
            'food_preference_options' => $this->options('food_preference', $client->tenantCompanyId(), $category),
            'genders' => ['male', 'female', 'other'],
        ];
    }

    /** @return array{url: string} */
    public function uploadPhoto(Client $client, ?UploadedFile $file): array
    {
        if ($file === null) {
            throw ApiException::badRequest('Please choose an image to upload.');
        }
        if (! in_array($file->getMimeType(), ['image/jpeg', 'image/png', 'image/webp', 'image/gif'], true)) {
            throw ApiException::badRequest('Please choose a JPG, PNG, WEBP or GIF image.');
        }
        if ($file->getSize() > 4 * 1024 * 1024) {
            throw ApiException::badRequest('That image is larger than 4MB.');
        }

        return ['url' => $this->storage->upload($file, 'guests/'.$client->id)['url']];
    }

    /** @return array<string, mixed> v1 present(): the row plus full_name, is_imported, events_joined, has_joined */
    public function present(Guest $guest): array
    {
        $joined = (int) $guest->getAttribute('events_joined');
        $data = $guest->toArray();
        unset($data['client_id']);

        return [
            ...$data,
            'website_client_id' => $guest->client_id,
            'full_name' => $guest->name,
            'event_id' => null,
            'is_imported' => $guest->source === 'import',
            'invite_source' => $guest->source,
            'events_joined' => $joined,
            'has_joined' => $joined > 0,
        ];
    }

    /** The phone-book guest with this number, if any. */
    public function byNumber(int $clientId, ?string $mobile, ?int $except = null): ?Guest
    {
        $key = GuestFields::mobileKey($mobile);
        if ($key === '') {
            return null;
        }

        return Guest::query()->where('client_id', $clientId)->when($except !== null, static fn ($q) => $q->whereKeyNot($except))
            ->whereRaw("right(regexp_replace(coalesce(mobile, ''), '[^0-9]', ''), 10) = ?", [$key])->first();
    }

    /** The plan's guest limit for this host (null = unlimited). */
    public function limit(Client $client): ?int
    {
        if ($client->plan_id === null) {
            return null;
        }
        $value = Plan::query()->withoutGlobalScopes()->withTrashed()->whereKey($client->plan_id)->value('max_guests_per_event');

        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }

    public function assertCapacity(Client $client, int $adding, ?string $message = null): void
    {
        // Serialise concurrent adds for one host.
        Client::query()->withoutGlobalScopes()->whereKey($client->id)->lockForUpdate()->value('id');
        $max = $this->limit($client);
        if ($max === null) {
            return;
        }
        $used = Guest::query()->where('client_id', $client->id)->count();
        if ($used + $adding > $max) {
            $left = max(0, $max - $used);
            throw ApiException::badRequest($message ?? ($left === 0
                ? "Your plan allows {$max} guest".($max === 1 ? '' : 's').' in total and you have reached that limit. Please upgrade your plan to add more guests.'
                : "Your plan allows {$max} guests in total and you can add only {$left} more. Please add fewer guests or upgrade your plan."));
        }
    }

    private function assertNumberFree(Client $client, ?string $mobile, ?int $except = null): void
    {
        $clash = $this->byNumber($client->id, $mobile, $except);
        if ($clash !== null) {
            throw ApiException::conflict("{$clash->name} is already in your guest list with this mobile number.");
        }
    }

    private function ownGroup(Client $client, mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return GuestGroup::query()->where('client_id', $client->id)->whereKey((int) $value)->value('id')
            ?? throw ApiException::badRequest('That guest group is not on your account.');
    }

    /** @return list<array{id: int, name: string}> */
    private function options(string $kind, int $companyId, ?int $category): array
    {
        $query = static fn (?int $cat) => GuestOption::query()->withoutGlobalScopes()->where('company_id', $companyId)->where('kind', $kind)
            ->where('is_active', 1)->where('event_category_id', $cat)->orderBy('sort_order')->orderBy('name')->get(['id', 'name'])
            ->map(static fn (GuestOption $o): array => ['id' => $o->id, 'name' => $o->name])->all();
        $scoped = $category !== null ? $query($category) : [];

        return $scoped !== [] ? $scoped : $query(null);
    }
}
