<?php

declare(strict_types=1);

namespace App\Modules\Events\Actions;

use App\Core\Exceptions\ApiException;
use App\Modules\Clients\Models\Client;
use App\Modules\Events\Models\Guest;
use App\Modules\Events\Models\GuestGroup;
use App\Modules\Events\Models\Participant;
use App\Modules\Events\Support\GuestFields;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Groups in a client's phone book (v1 clientGuestGroup.service). One group per
 * name (case-insensitive); at most one default group, which new guests join.
 */
final class GuestGroups
{
    /** @return Builder<GuestGroup> */
    public function query(Client $client): Builder
    {
        return GuestGroup::query()->where('client_id', $client->id)->orderByDesc('is_default')->orderBy('name');
    }

    public function find(Client $client, int $id): GuestGroup
    {
        return GuestGroup::query()->where('client_id', $client->id)->find($id) ?? throw ApiException::notFound('Group not found.');
    }

    /** @param array<string, mixed> $input */
    public function create(Client $client, array $input): GuestGroup
    {
        $values = $this->normalise($input, partial: false);
        $this->assertNameFree($client, $values['name']);

        return DB::transaction(function () use ($client, $values): GuestGroup {
            $group = GuestGroup::query()->create([...$values, 'client_id' => $client->id]);
            if ($group->is_default) {
                $this->clearOtherDefaults($client, $group->id);
            }

            return $group;
        });
    }

    /** @param array<string, mixed> $input */
    public function update(Client $client, GuestGroup $group, array $input): GuestGroup
    {
        $values = $this->normalise($input, partial: true);
        if (isset($values['name'])) {
            $this->assertNameFree($client, $values['name'], $group->id);
        }
        DB::transaction(function () use ($client, $group, $values): void {
            $group->fill($values)->save();
            if (! empty($values['is_default'])) {
                $this->clearOtherDefaults($client, $group->id);
            }
        });

        return $group;
    }

    /** @return array{deleted: true, ungrouped_guests: int} */
    public function delete(Client $client, GuestGroup $group): array
    {
        $members = Guest::query()->where('client_id', $client->id)->where('group_id', $group->id)->count();
        DB::transaction(static function () use ($client, $group): void {
            Guest::query()->where('client_id', $client->id)->where('group_id', $group->id)->update(['group_id' => null]);
            $group->delete();
        });

        return ['deleted' => true, 'ungrouped_guests' => $members];
    }

    /** @return array<string, int> */
    public function stats(Client $client): array
    {
        $groups = $this->withCounts(GuestGroup::query()->where('client_id', $client->id)->get());

        return [
            'total_groups' => count($groups),
            'total_members' => array_sum(array_column($groups, 'members_count')),
            'groups_in_use' => count(array_filter($groups, static fn (array $g): bool => $g['events_count'] > 0)),
            'private_groups' => count(array_filter($groups, static fn (array $g): bool => $g['visibility'] === 'private')),
        ];
    }

    /**
     * Each group with its members and the number of events its members joined
     * (two grouped queries for the whole page).
     *
     * @param  iterable<GuestGroup>  $groups
     * @return list<array<string, mixed>>
     */
    public function withCounts(iterable $groups): array
    {
        $groups = collect($groups);
        if ($groups->isEmpty()) {
            return [];
        }
        $ids = $groups->pluck('id')->all();
        $members = Guest::query()->whereIn('group_id', $ids)->selectRaw('group_id, count(*) as n')->groupBy('group_id')->pluck('n', 'group_id');
        $events = Participant::query()->join('guests', 'guests.id', '=', 'participants.guest_id')->whereNull('guests.deleted_at')
            ->whereIn('guests.group_id', $ids)->selectRaw('guests.group_id as group_id, count(distinct participants.event_id) as n')
            ->groupBy('guests.group_id')->pluck('n', 'group_id');

        return $groups->map(static fn (GuestGroup $g): array => [
            ...$g->toArray(),
            'website_client_id' => $g->client_id,
            'members_count' => (int) ($members[$g->id] ?? 0),
            'events_count' => (int) ($events[$g->id] ?? 0),
        ])->values()->all();
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function normalise(array $input, bool $partial): array
    {
        $v = [];
        if (! $partial || array_key_exists('name', $input)) {
            $v['name'] = GuestFields::str($input['name'] ?? null, 100) ?? throw ApiException::badRequest('Please enter a group name.');
        }
        if (array_key_exists('description', $input)) {
            $v['description'] = GuestFields::str($input['description'], 255);
        }
        if (array_key_exists('color', $input)) {
            $v['color'] = GuestFields::str($input['color'], 9);
            if ($v['color'] !== null && preg_match('/^#([0-9a-fA-F]{6}|[0-9a-fA-F]{8})$/', $v['color']) !== 1) {
                throw ApiException::badRequest('Group colour must be a hex value like #EC4899.');
            }
        }
        if (array_key_exists('visibility', $input)) {
            $value = mb_strtolower(is_scalar($input['visibility']) ? (string) $input['visibility'] : '');
            $v['visibility'] = in_array($value, ['private', 'public'], true) ? $value : throw ApiException::badRequest('Invalid group visibility.');
        }
        if (array_key_exists('is_default', $input)) {
            $v['is_default'] = (bool) $input['is_default'];
        }

        return $v;
    }

    private function assertNameFree(Client $client, string $name, ?int $except = null): void
    {
        $clash = GuestGroup::query()->where('client_id', $client->id)->whereRaw('lower(name) = ?', [mb_strtolower($name)])
            ->when($except !== null, static fn ($q) => $q->whereKeyNot($except))->first();
        if ($clash !== null) {
            throw ApiException::conflict("You already have a group called \"{$clash->name}\".");
        }
    }

    private function clearOtherDefaults(Client $client, int $keep): void
    {
        GuestGroup::query()->where('client_id', $client->id)->where('is_default', true)->whereKeyNot($keep)->update(['is_default' => false]);
    }

    /** @return Collection<int, GuestGroup> */
    public function all(Client $client): Collection
    {
        return $this->query($client)->get();
    }
}
