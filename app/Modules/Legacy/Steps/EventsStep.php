<?php

declare(strict_types=1);

namespace App\Modules\Legacy\Steps;

use App\Modules\Legacy\Support\Import;
use Illuminate\Support\Facades\DB;

/**
 * Events and everything in them. Event ids are kept: printed invitations
 * carry the id inside the QR code.
 *
 *  - menu_ids / menu_order (JSON) become the event's menu choices;
 *  - rsvp / participant / family / wish settings become event_settings, with
 *    the wish word filter on its own;
 *  - children carry only their parent: the repeated website_client_id and
 *    company_id columns are dropped;
 *  - guest dropdown choices are re-pointed to guest_options.
 */
final class EventsStep implements Step
{
    public function name(): string
    {
        return 'events';
    }

    public function run(Import $import): void
    {
        $this->events($import);
        $this->guestBook($import);
        $this->participants($import);
        $this->eventParts($import);
    }

    private function events(Import $i): void
    {
        $plans = DB::table('plans')->pluck('id')->flip()->all();
        $categories = DB::table('event_categories')->pluck('id')->flip()->all();
        $vendors = DB::table('vendors')->pluck('id')->flip()->all();
        $copy = ['name', 'host_one', 'host_two', 'tagline', 'description', 'start_date', 'end_date', 'start_time', 'end_time', 'timezone', 'venue_name',
            'venue_address', 'venue_landmark', 'venue_map_link', 'venue_image', 'venue_lat', 'venue_lng', 'ceremony_title', 'ceremony_venue',
            'ceremony_description', 'organizer', 'contact_phone', 'contact_email', 'footer_note', 'privacy', 'status', 'theme_id', 'primary_color',
            'secondary_color', 'cover_image', 'organizer_image', 'custom_image', 'invitation_image', 'qr_token', 'qr_version', 'qr_style'];

        $rows = $i->rows('events');
        $events = [];
        $settings = [];
        $menus = [];
        $menuIds = DB::table('event_menus')->pluck('id')->flip()->all();
        foreach ($rows as $r) {
            $client = $i->id('website_client', $r['website_client_id']);
            if ($client === null || ! isset($vendors[(int) $r['vendor_id']])) {
                $i->note("event {$r['id']}: its client or vendor does not exist, skipped");

                continue;
            }
            $events[] = ['id' => $r['id'], 'vendor_id' => $r['vendor_id'], 'client_id' => $client,
                'organizer_client_id' => $i->id('website_client', $r['organizer_client_id']),
                'plan_id' => isset($plans[(int) $r['subscription_plan_id']]) ? $r['subscription_plan_id'] : null,
                'event_category_id' => isset($categories[(int) $r['event_category_id']]) ? $r['event_category_id'] : null,
                ...array_intersect_key($r, array_flip($copy)),
                'start_date' => Import::at($r['start_date']), 'end_date' => Import::at($r['end_date']),
                'components' => Import::json($r['components']), 'component_order' => Import::json($r['component_order']),
                'qr_issued_at' => Import::at($r['qr_issued_at']),
                'created_at' => Import::at($r['created_at']), 'updated_at' => Import::at($r['updated_at']), 'deleted_at' => Import::at($r['deleted_at'])];
            $i->map('event', (int) $r['id'], (int) $r['id']);

            $wish = Import::list($r['wish_settings']);
            $filter = is_array($wish['filter'] ?? null) ? $wish['filter'] : null;
            unset($wish['filter']);
            $settings[] = ['event_id' => $r['id'], 'rsvp' => Import::json($r['rsvp_settings']), 'participant' => Import::json($r['participant_settings']),
                'family' => Import::json($r['family_settings']), 'wish' => $wish === [] ? null : Import::json($wish),
                'wish_filter' => $filter === null ? null : Import::json($filter),
                'created_at' => Import::at($r['created_at']), 'updated_at' => Import::at($r['updated_at'])];

            $selected = array_values(array_unique(array_map('intval', Import::list($r['menu_ids']))));
            $order = array_values(array_unique(array_map('intval', Import::list($r['menu_order']))));
            foreach (array_unique([...$selected, ...$order]) as $menu) {
                if (isset($menuIds[$menu])) {
                    $position = array_search($menu, $order, true);
                    $menus[] = ['event_id' => $r['id'], 'event_menu_id' => $menu, 'selected' => in_array($menu, $selected, true) ? 1 : 0,
                        'position' => $position === false ? null : $position];
                }
            }
        }
        $i->put('events', 'events', $events, count($rows));
        $i->put('events.*_settings', 'event_settings', $settings, count($events), ['event_id']);
        DB::table('event_menu')->whereIn('event_id', array_column($events, 'id'))->delete();
        $i->link('events.menu_ids', 'event_menu', $menus, count($menus));
    }

    private function guestBook(Import $i): void
    {
        $rows = $i->rows('guest_groups');
        $groups = [];
        $i->put('guest_groups', 'guest_groups', array_values(array_filter(array_map(static function (array $r) use ($i, &$groups): ?array {
            if (($client = $i->id('website_client', $r['website_client_id'])) === null) {
                return null;
            }
            $groups[(int) $r['id']] = true;

            return ['id' => $r['id'], 'client_id' => $client, 'name' => $r['name'], 'description' => Import::text($r['description'], 255),
                'color' => $r['color'], 'visibility' => $r['visibility'], 'is_default' => Import::bit($r['is_default'], 0),
                'created_at' => Import::at($r['created_at']), 'updated_at' => Import::at($r['updated_at']), 'deleted_at' => Import::at($r['deleted_at'])];
        }, $rows))), count($rows));

        $rows = $i->rows('guests');
        $i->put('guests', 'guests', array_values(array_filter(array_map(function (array $r) use ($i, $groups): ?array {
            if (($client = $i->id('website_client', $r['website_client_id'])) === null) {
                return null;
            }
            $i->map('guest', (int) $r['id'], (int) $r['id']);

            return ['id' => $r['id'], 'client_id' => $client, 'group_id' => isset($groups[(int) $r['group_id']]) ? $r['group_id'] : null,
                ...$this->person($i, $r), 'source' => $r['source'] ?? 'manual',
                'created_at' => Import::at($r['created_at']), 'updated_at' => Import::at($r['updated_at']), 'deleted_at' => Import::at($r['deleted_at'])];
        }, $rows))), count($rows));
    }

    private function participants(Import $i): void
    {
        $rows = $i->rows('event_participants');
        $i->put('event_participants', 'participants', array_values(array_filter(array_map(function (array $r) use ($i): ?array {
            if ($i->id('event', $r['event_id']) === null) {
                return null;
            }
            $i->map('participant', (int) $r['id'], (int) $r['id']);

            return ['id' => $r['id'], 'event_id' => $r['event_id'], 'guest_id' => $i->id('guest', $r['guest_id']),
                'client_id' => $i->id('website_client', $r['participant_client_id']), 'added_by_client_id' => $i->id('website_client', $r['added_by_client_id']),
                ...$this->person($i, $r), 'table_number' => $r['table_number'], 'party_size' => (int) $r['party_size'], 'rsvp_status' => $r['rsvp_status'],
                'response_type' => $r['response_type'], 'invite_source' => $r['invite_source'], 'invited_at' => Import::at($r['invited_at']),
                'responded_at' => Import::at($r['responded_at']), 'plus_one' => Import::bit($r['plus_one'], 0), 'plus_one_count' => (int) $r['plus_one_count'],
                'custom_answers' => Import::json($r['custom_answers']), 'accommodation' => $r['accommodation'], 'rsvp_side' => $r['rsvp_side'],
                'created_at' => Import::at($r['created_at']), 'updated_at' => Import::at($r['updated_at']), 'deleted_at' => Import::at($r['deleted_at'])];
        }, $rows))), count($rows));

        $rows = $i->rows('event_participant_response_logs');
        $i->put('event_participant_response_logs', 'participant_response_logs', array_values(array_filter(array_map(static fn (array $r): ?array => $i->id('participant', $r['participant_id']) === null ? null : [
            'id' => $r['id'], 'participant_id' => $r['participant_id'], 'from_response_type' => $r['from_response_type'], 'to_response_type' => $r['to_response_type'],
            'party_size' => (int) $r['party_size'], 'dietary_preference' => Import::text($r['dietary_preference'], 100), 'accommodation' => $r['accommodation'],
            'notes' => $r['notes'], 'source' => $r['source'], 'changed_by_client_id' => $i->id('website_client', $r['changed_by_client_id']),
            'changed_at' => Import::at($r['changed_at']), 'created_at' => Import::at($r['created_at']), 'updated_at' => Import::at($r['created_at']),
        ], $rows))), count($rows));

        // Guest notes, tags and reminders hang off a guest or a participant.
        $owner = static fn (array $r): array => ['guest_id' => $i->id('guest', $r['guest_id']), 'participant_id' => $i->id('participant', $r['participant_id'])];
        $kept = static fn (array $r): bool => $owner($r)['guest_id'] !== null || $owner($r)['participant_id'] !== null;
        $rows = $i->rows('guest_notes');
        $i->put('guest_notes', 'guest_notes', array_values(array_map(static fn (array $r): array => ['id' => $r['id'], ...$owner($r), 'title' => $r['title'],
            'body' => $r['body'], 'category' => $r['category'], 'visibility' => $r['visibility'], 'is_pinned' => Import::bit($r['is_pinned'], 0),
            'created_by_client_id' => $i->id('website_client', $r['created_by_client_id']), 'created_at' => Import::at($r['created_at']),
            'updated_at' => Import::at($r['updated_at']), 'deleted_at' => Import::at($r['deleted_at'])], array_filter($rows, $kept))), count($rows));
        $rows = $i->rows('guest_tags');
        $i->put('guest_tags', 'guest_tags', array_values(array_map(static fn (array $r): array => ['id' => $r['id'], ...$owner($r),
            'label' => Import::text($r['label'], 50), 'color' => $r['color'], 'created_at' => Import::at($r['created_at']), 'updated_at' => Import::at($r['updated_at']),
            'deleted_at' => Import::at($r['deleted_at'])], array_filter($rows, $kept))), count($rows));
        $rows = $i->rows('guest_reminders');
        $notes = DB::table('guest_notes')->pluck('id')->flip()->all();
        $i->put('guest_reminders', 'guest_reminders', array_values(array_map(static fn (array $r): array => ['id' => $r['id'], ...$owner($r),
            'note_id' => isset($notes[(int) $r['note_id']]) ? $r['note_id'] : null, 'title' => $r['title'], 'due_at' => Import::at($r['due_at']),
            'status' => $r['status'], 'completed_at' => Import::at($r['completed_at']), 'created_by_client_id' => $i->id('website_client', $r['created_by_client_id']),
            'created_at' => Import::at($r['created_at']), 'updated_at' => Import::at($r['updated_at']), 'deleted_at' => Import::at($r['deleted_at'])],
            array_filter($rows, $kept))), count($rows));
    }

    private function eventParts(Import $i): void
    {
        $ofEvent = static fn (array $rows): array => array_values(array_filter($rows, static fn (array $r): bool => $i->id('event', $r['event_id']) !== null));
        $stamps = static fn (array $r): array => ['created_at' => Import::at($r['created_at']), 'updated_at' => Import::at($r['updated_at'] ?? $r['created_at'])];

        $rows = $i->rows('event_agenda_items');
        $i->put('event_agenda_items', 'agenda_items', array_map(static fn (array $r): array => ['id' => $r['id'], 'event_id' => $r['event_id'],
            'title' => $r['title'], 'description' => $r['description'], 'agenda_date' => $r['agenda_date'], 'end_date' => Import::at($r['end_date']),
            'start_time' => $r['start_time'], 'end_time' => $r['end_time'], 'location' => $r['location'], 'images' => Import::json($r['images']),
            'sort_order' => (int) $r['sort_order'], ...$stamps($r)], $ofEvent($rows)), count($rows));

        $rows = $i->rows('event_family_members');
        $i->put('event_family_members', 'family_members', array_map(static fn (array $r): array => ['id' => $r['id'], 'event_id' => $r['event_id'],
            'title' => Import::text($r['title'], 20), 'name' => $r['name'], 'photo' => $r['photo'], 'date_of_birth' => Import::at($r['date_of_birth']),
            'side' => $r['side'], 'relationship' => $r['relationship'], 'relationship_option_id' => $i->id('guest_relationship_options', $r['relationship_option_id']),
            'dial_code' => $r['dial_code'], 'mobile' => $r['mobile'], 'email' => $r['email'], 'notes' => $r['notes'], 'sort_order' => (int) $r['sort_order'],
            ...$stamps($r)], $ofEvent($rows)), count($rows));

        $rows = $ofEvent($i->rows('event_gallery_categories'));
        $albums = array_flip(array_map(static fn (array $r): int => (int) $r['id'], $rows));
        $i->put('event_gallery_categories', 'gallery_categories', array_map(static fn (array $r): array => ['id' => $r['id'], 'event_id' => $r['event_id'],
            'name' => Import::text($r['name'], 100), 'description' => Import::text($r['description'], 255), 'cover_image' => $r['cover_image'], 'icon' => $r['icon'],
            'sort_order' => (int) $r['sort_order'], 'is_default' => Import::bit($r['is_default'], 0), ...$stamps($r), 'deleted_at' => Import::at($r['deleted_at'])],
            $rows), count($rows));

        $rows = $i->rows('event_gallery_items');
        $i->put('event_gallery_items', 'gallery_items', array_map(static fn (array $r): array => ['id' => $r['id'], 'event_id' => $r['event_id'],
            'category_id' => isset($albums[(int) $r['category_id']]) ? $r['category_id'] : null, 'type' => $r['type'], 'url' => $r['url'],
            'thumbnail_url' => $r['thumbnail_url'], 'file_name' => $r['file_name'], 'mime_type' => $r['mime_type'], 'size_bytes' => (int) $r['size_bytes'],
            'caption' => $r['caption'], 'show_in_app' => Import::bit($r['show_in_app']), 'sort_order' => (int) $r['sort_order'],
            'uploaded_by' => $i->id('website_client', $r['uploaded_by']), ...$stamps($r), 'deleted_at' => Import::at($r['deleted_at'])], $ofEvent($rows)), count($rows));

        $rows = $ofEvent($i->rows('event_wishes'));
        $wishes = array_flip(array_map(static fn (array $r): int => (int) $r['id'], $rows));
        $i->put('event_wishes', 'wishes', array_map(static fn (array $r): array => ['id' => $r['id'], 'event_id' => $r['event_id'],
            'author_client_id' => $i->id('website_client', $r['author_client_id']), 'post_type' => $r['post_type'], 'audience' => $r['audience'],
            'guest_name' => $r['guest_name'], 'mobile' => $r['mobile'], 'message' => $r['message'], 'photo' => $r['photo'], 'video_url' => $r['video_url'],
            'thumbnail_url' => $r['thumbnail_url'], 'duration_seconds' => $r['duration_seconds'], 'size_bytes' => (int) $r['size_bytes'],
            'is_hidden' => Import::bit($r['is_hidden'], 0), 'is_flagged' => Import::bit($r['is_flagged'], 0), ...$stamps($r)], $rows), count($rows));

        $rows = $i->rows('event_wish_likes');
        $i->put('event_wish_likes', 'wish_likes', array_values(array_filter(array_map(static fn (array $r): ?array => ! isset($wishes[(int) $r['wish_id']])
            || ($client = $i->id('website_client', $r['client_id'])) === null ? null
            : ['id' => $r['id'], 'wish_id' => $r['wish_id'], 'client_id' => $client, 'created_at' => Import::at($r['created_at'])], $rows))), count($rows));

        $rows = $i->rows('event_wish_blocks');
        $i->put('event_wish_blocks', 'wish_blocks', array_values(array_filter(array_map(static fn (array $r): ?array => $i->id('event', $r['event_id']) === null
            || ($client = $i->id('website_client', $r['author_client_id'])) === null ? null
            : ['id' => $r['id'], 'event_id' => $r['event_id'], 'author_client_id' => $client, 'hide_menu' => Import::bit($r['hide_menu'], 0),
                'created_at' => Import::at($r['created_at'])], $rows))), count($rows));

        $rows = $i->rows('splash_screens');
        $copy = ['name', 'main_title', 'sub_title', 'event_name', 'theme_id', 'tagline', 'background_type', 'background_url', 'primary_color',
            'secondary_color', 'bg_color', 'decoration_url', 'status'];
        $i->put('splash_screens', 'splash_screens', array_values(array_filter(array_map(static fn (array $r): ?array => ($client = $i->id('website_client', $r['website_client_id'])) === null ? null : [
            'id' => $r['id'], 'client_id' => $client, 'event_id' => $i->id('event', $r['event_id']), ...array_intersect_key($r, array_flip($copy)),
            'background_config' => Import::json($r['background_config']), 'loader_enabled' => Import::bit($r['loader_enabled'], 0),
            'loader_config' => Import::json($r['loader_config']), 'show_decoration' => Import::bit($r['show_decoration'], 0),
            'components' => Import::json($r['components']), 'component_order' => Import::json($r['component_order']),
            ...$stamps($r), 'deleted_at' => Import::at($r['deleted_at']),
        ], $rows))), count($rows));

        $templates = DB::table('notification_templates')->pluck('id')->flip()->all();
        $rows = $i->rows('event_notification_template_prefs');
        $i->put('event_notification_template_prefs', 'event_notification_template_prefs', array_values(array_map(static fn (array $r): array => [
            'id' => $r['id'], 'event_id' => $r['event_id'], 'notification_template_id' => $r['notification_template_id'], 'enabled' => Import::bit($r['enabled']),
            ...$stamps($r),
        ], array_filter($ofEvent($rows), static fn (array $r): bool => isset($templates[(int) $r['notification_template_id']])))), count($rows));
    }

    /**
     * The person columns guests and participants share.
     *
     * @param  array<string, mixed>  $r
     * @return array<string, mixed>
     */
    private function person(Import $i, array $r): array
    {
        return [
            'title' => Import::text($r['title'], 20), 'first_name' => $r['first_name'], 'last_name' => $r['last_name'], 'name' => $r['name'],
            'date_of_birth' => Import::at($r['date_of_birth']), 'gender' => $r['gender'], 'email' => Import::text($r['email'], 150),
            'dial_code' => $r['dial_code'], 'mobile' => $r['mobile'], 'whatsapp' => $r['whatsapp'], 'company' => Import::text($r['company'], 150),
            'relationship' => $r['relationship'], 'relationship_option_id' => $i->id('guest_relationship_options', $r['relationship_option_id']),
            'address_line1' => $r['address_line1'], 'address_line2' => $r['address_line2'], 'city' => Import::text($r['city'], 100),
            'state' => Import::text($r['state'], 100), 'postal_code' => $r['postal_code'], 'country' => $r['country'],
            'dietary_preference' => Import::text($r['dietary_preference'], 100),
            'food_preference_option_id' => $i->id('guest_food_preference_options', $r['food_preference_option_id']),
            'special_requirements' => $r['special_requirements'], 'notes' => $r['notes'], 'photo' => $r['photo'],
        ];
    }
}
