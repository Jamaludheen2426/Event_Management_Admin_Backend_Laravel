<?php

declare(strict_types=1);

namespace App\Modules\Events\Actions;

use App\Core\Contracts\ObjectStorage;
use App\Core\Exceptions\ApiException;
use App\Modules\Catalog\Models\GuestOption;
use App\Modules\Clients\Models\Client;
use App\Modules\Events\Models\Event;
use App\Modules\Events\Models\EventSetting;
use App\Modules\Events\Models\FamilyMember;
use App\Modules\Events\Support\EventAccess;
use App\Modules\Events\Support\EventMenus;
use App\Modules\Events\Support\Ordering;
use Illuminate\Database\Eloquent\Collection;

/**
 * The families shown on an event (v1 clientFamily.service). Guests see names
 * and sides; photo and relationship only when the host allows it, and never
 * the contact details.
 */
final class Family
{
    private const FLAGS = ['profile_screen' => true, 'profile_photo' => true, 'relationship' => true];

    public function __construct(
        private readonly EventAccess $access,
        private readonly ObjectStorage $storage,
    ) {}

    /** @return array<string, mixed> */
    public function list(Client $actor, int $eventId): array
    {
        [$event, $manages] = $this->access->viewable($actor, $eventId);
        $settings = $this->settings($event);

        return [
            'items' => $this->members($event)->map(fn (FamilyMember $m): array => $this->present($m, $manages, $settings))->all(),
            'settings' => $settings,
            ...EventMenus::state($event, 'family'),
            'can_edit' => $manages,
        ];
    }

    /** @return array<string, mixed> */
    public function show(Client $actor, int $memberId): array
    {
        $member = FamilyMember::query()->find($memberId) ?? throw ApiException::notFound('That family member was not found.');
        [$event, $manages] = $this->access->viewable($actor, $member->event_id);
        $settings = $this->settings($event);
        if (! $manages && ! $settings['profile_screen']) {
            throw ApiException::forbidden('The organizer has turned off family profiles.');
        }

        return $this->present($member, $manages, $settings);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function create(Client $actor, int $eventId, array $input): array
    {
        $event = $this->managed($actor, $eventId);
        $values = $this->normalise($input, partial: false);
        $next = FamilyMember::query()->where('event_id', $event->id)->exists() ? (int) FamilyMember::query()->where('event_id', $event->id)->max('sort_order') + 1 : 0;

        return $this->present(FamilyMember::query()->create([...$values, 'event_id' => $event->id, 'sort_order' => $next]), true, $this->settings($event));
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function update(Client $actor, int $memberId, array $input): array
    {
        $member = $this->member($actor, $memberId);
        $before = $member->photo;
        $values = $this->normalise($input, partial: true);
        $member->fill($values)->save();
        if (array_key_exists('photo', $values) && $before !== null && $before !== $values['photo']) {
            $this->storage->deleteByUrl($before);
        }

        return $this->present($member, true, $this->settings(Event::query()->with('settings')->findOrFail($member->event_id)));
    }

    /** @return array{removed: true} */
    public function delete(Client $actor, int $memberId): array
    {
        $member = $this->member($actor, $memberId);
        $member->delete();
        if ($member->photo !== null) {
            $this->storage->deleteByUrl($member->photo);
        }

        return ['removed' => true];
    }

    /** @return array<string, mixed> */
    public function reorder(Client $actor, int $eventId, mixed $ids): array
    {
        $event = $this->managed($actor, $eventId);
        if (! is_array($ids)) {
            throw ApiException::badRequest('Invalid family order.');
        }
        Ordering::apply(FamilyMember::query()->where('event_id', $event->id)->orderBy('sort_order')->orderBy('id'), $ids);

        return $this->list($actor, $eventId);
    }

    /** @return array<string, bool> */
    public function settings(Event $event): array
    {
        $event->loadMissing('settings');
        $stored = $event->settings->family ?? [];
        $flag = static fn (string $k, bool $d): bool => ($stored[$k] ?? null) === null ? $d : in_array($stored[$k], [true, 1, '1', 'true'], true);

        return ['profile_screen' => $flag('profile_screen', true), 'profile_photo' => $flag('profile_photo', true), 'name' => true, 'relationship' => $flag('relationship', true)];
    }

    /** @return array{event_id: int, settings: array<string, bool>} */
    public function viewSettings(Client $actor, int $eventId): array
    {
        [$event] = $this->access->viewable($actor, $eventId);

        return ['event_id' => $event->id, 'settings' => $this->settings($event)];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{event_id: int, settings: array<string, bool>}
     */
    public function updateSettings(Client $actor, int $eventId, array $input): array
    {
        $event = $this->managed($actor, $eventId);
        $current = $this->settings($event);
        $next = ['name' => true];
        foreach (array_keys(self::FLAGS) as $flag) {
            $next[$flag] = ($input[$flag] ?? null) === null ? $current[$flag] : in_array($input[$flag], [true, 1, '1', 'true'], true);
        }
        $next = ['profile_screen' => $next['profile_screen'], 'profile_photo' => $next['profile_photo'], 'name' => true, 'relationship' => $next['relationship']];
        EventSetting::query()->updateOrCreate(['event_id' => $event->id], ['family' => $next]);

        return ['event_id' => $event->id, 'settings' => $next];
    }

    /** @return Collection<int, FamilyMember> */
    private function members(Event $event): Collection
    {
        return FamilyMember::query()->where('event_id', $event->id)->orderBy('sort_order')->orderBy('id')->get();
    }

    /**
     * @param  array<string, bool>  $settings
     * @return array<string, mixed>
     */
    private function present(FamilyMember $m, bool $manages, array $settings): array
    {
        $base = ['id' => $m->id, 'event_id' => $m->event_id, 'title' => $m->title, 'full_name' => $m->name, 'side' => $m->side, 'sort_order' => $m->sort_order];
        if ($manages) {
            return [
                ...$base, 'photo' => $m->photo, 'relationship' => $m->relationship, 'relationship_option_id' => $m->relationship_option_id,
                'date_of_birth' => $m->date_of_birth?->format('Y-m-d'), 'dial_code' => $m->dial_code, 'mobile' => $m->mobile, 'email' => $m->email,
                'notes' => $m->notes, 'created_at' => $m->created_at?->utc()->format('Y-m-d\TH:i:s.v\Z'),
            ];
        }

        return [...$base, 'photo' => $settings['profile_photo'] ? $m->photo : null, 'relationship' => $settings['relationship'] ? $m->relationship : null];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function normalise(array $input, bool $partial): array
    {
        $has = static fn (string $f): bool => array_key_exists($f, $input);
        $str = static fn (mixed $v, int $max): ?string => is_string($v) && trim($v) !== '' ? mb_substr(trim($v), 0, $max) : null;
        $mandatory = 'Please fill all mandatory fields.';
        $v = [];

        if (! $partial || $has('first_name') || $has('last_name') || $has('name')) {
            $name = $has('name') ? $str($input['name'], 150) : $str(implode(' ', array_filter([$input['first_name'] ?? null, $input['last_name'] ?? null], 'is_string')), 150);
            $v['name'] = $name ?? throw ApiException::badRequest($mandatory);
        }
        if ($has('title')) {
            $v['title'] = $str($input['title'], 20);
        }
        if (! $partial || $has('side')) {
            $side = mb_strtolower(trim(is_string($input['side'] ?? null) ? $input['side'] : ''));
            $v['side'] = in_array($side, ['groom', 'bride'], true) ? $side : throw ApiException::badRequest($mandatory);
        }
        if (! $partial || $has('relationship')) {
            $v['relationship'] = $str($input['relationship'] ?? null, 100) ?? throw ApiException::badRequest($mandatory);
        }
        if ($has('relationship_option_id')) {
            $id = is_numeric($input['relationship_option_id']) ? (int) $input['relationship_option_id'] : 0;
            $v['relationship_option_id'] = $id > 0 && GuestOption::query()->withoutGlobalScopes()->whereKey($id)->exists() ? $id : null;
        }
        if ($has('photo')) {
            $v['photo'] = $str($input['photo'], 500);
            if ($v['photo'] !== null && preg_match('#^(https?://|/uploads/)#i', $v['photo']) !== 1) {
                throw ApiException::badRequest('Invalid photo.');
            }
        }
        if ($has('email')) {
            $v['email'] = $str($input['email'], 150);
            if ($v['email'] !== null && preg_match('/^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/', $v['email']) !== 1) {
                throw ApiException::badRequest('Please enter a valid email address.');
            }
        }
        if ($has('mobile') || $has('dial_code')) {
            $mobile = $str($input['mobile'] ?? null, 20);
            $digits = (string) preg_replace('/\D/', '', (string) $mobile);
            if ($mobile !== null && (strlen($digits) < 6 || strlen($digits) > 15)) {
                throw ApiException::badRequest('Please enter a valid mobile number.');
            }
            $v['mobile'] = $mobile !== null ? $digits : null;
            $v['dial_code'] = $mobile !== null ? ($str($input['dial_code'] ?? null, 8) ?? '+91') : null;
        }
        if ($has('date_of_birth')) {
            $dob = $str($input['date_of_birth'], 10);
            if ($dob !== null) {
                $parsed = \DateTime::createFromFormat('!Y-m-d', $dob);
                if ($parsed === false || $parsed->format('Y-m-d') !== $dob) {
                    throw ApiException::badRequest('Please choose a valid date of birth.');
                }
                if ($dob > today()->format('Y-m-d')) {
                    throw ApiException::badRequest('Date of birth cannot be in the future.');
                }
            }
            $v['date_of_birth'] = $dob;
        }
        if ($has('notes')) {
            $v['notes'] = $str($input['notes'], 500);
        }

        return $v;
    }

    private function managed(Client $actor, int $eventId): Event
    {
        $event = Event::query()->with(['client', 'settings'])->find($eventId);
        if ($event === null || ! $this->access->canManage($actor, $event)) {
            throw ApiException::notFound('That event is not on your account.');
        }

        return $event;
    }

    private function member(Client $actor, int $memberId): FamilyMember
    {
        return FamilyMember::query()->whereIn('event_id', $this->access->managedEventIds($actor))->find($memberId)
            ?? throw ApiException::notFound('That family member was not found.');
    }
}
