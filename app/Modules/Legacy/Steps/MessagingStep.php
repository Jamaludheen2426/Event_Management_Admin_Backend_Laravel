<?php

declare(strict_types=1);

namespace App\Modules\Legacy\Steps;

use App\Modules\Legacy\Support\Import;
use Illuminate\Support\Facades\DB;

/**
 * Messaging: providers, the client notification feed, messages and
 * notifications to guests (two v1 table pairs → one broadcast pair, new ids),
 * in-app mail and chat, the vendor's e-mail templates and newsletter.
 *
 * In v1 mail and chat a "client" was a vendor client; those actors are
 * re-pointed to the merged clients (legacy_id_map entity vendor_client).
 * Chat read states fold into the participants.
 */
final class MessagingStep implements Step
{
    public function name(): string
    {
        return 'messaging';
    }

    public function run(Import $import): void
    {
        $this->providers($import);
        $this->feed($import);
        $this->broadcasts($import);
        $this->mail($import);
        $this->chat($import);
        $this->vendorEmail($import);
    }

    /** An actor of mail / chat in the new numbering, or null when it no longer exists. */
    private function actor(Import $i, string $type, mixed $id): ?int
    {
        return match ($type) {
            'client' => $i->id('vendor_client', $id),
            'vendor' => $i->id('vendor_owner', $id),
            'admin' => is_numeric($id) && DB::table('users')->where('id', (int) $id)->exists() ? (int) $id : null,
            default => null,
        };
    }

    private function providers(Import $i): void
    {
        $rows = $i->rows('push_notification_configs');
        $copy = ['name', 'project_id', 'client_email', 'web_api_key', 'app_id', 'messaging_sender_id', 'auth_domain', 'storage_bucket', 'measurement_id',
            'vapid_key', 'connection_status', 'validation_error'];
        $i->put('push_notification_configs', 'push_notification_configs', array_map(static fn (array $r): array => [
            'id' => $r['id'], 'company_id' => 1, ...array_intersect_key($r, array_flip($copy)), 'is_active' => Import::bit($r['is_active']),
            'service_account_json' => Import::encrypt($r['service_account_json']), 'private_key' => Import::encrypt($r['private_key']),
            'last_verified_at' => Import::at($r['last_verified_at']), 'created_by' => null, 'updated_by' => null,
            'created_at' => Import::at($r['created_at']), 'updated_at' => Import::at($r['updated_at']),
        ], $rows), count($rows));

        $rows = $i->rows('email_configs', withDeleted: false);
        $copy = ['name', 'from_email', 'from_name', 'driver', 'host', 'port', 'username', 'encryption', 'domain', 'region'];
        $i->put('email_configs', 'email_configs', array_map(static fn (array $r): array => [
            'id' => $r['id'], 'company_id' => $r['company_id'] ?? 1, ...array_intersect_key($r, array_flip($copy)),
            'password' => Import::encrypt($r['password']), 'api_key' => Import::encrypt($r['api_key']), 'is_default' => Import::bit($r['is_default'], 0),
            ...Import::approval($r['is_active']), 'created_by' => $r['created_by'], 'updated_by' => $r['updated_by'],
            'created_at' => Import::at($r['created_at']), 'updated_at' => Import::at($r['updated_at']), 'deleted_at' => null,
        ], $rows), count($rows));

        $rows = $i->rows('email_templates', withDeleted: false);
        $copy = ['name', 'slug', 'type', 'subject', 'body', 'description'];
        $ids = array_flip(array_map(static fn (array $r): int => (int) $r['id'], $rows));
        $configs = DB::table('email_configs')->pluck('id')->flip()->all();
        $i->put('email_templates', 'email_templates', array_map(static fn (array $r): array => [
            'id' => $r['id'], 'company_id' => $r['company_id'] ?? 1, ...array_intersect_key($r, array_flip($copy)), 'variables' => Import::json($r['variables']),
            'header_id' => isset($ids[(int) $r['header_id']]) ? $r['header_id'] : null, 'footer_id' => isset($ids[(int) $r['footer_id']]) ? $r['footer_id'] : null,
            'email_config_id' => isset($configs[(int) $r['email_config_id']]) ? $r['email_config_id'] : null, ...Import::approval($r['is_active']),
            'created_by' => $r['created_by'], 'updated_by' => $r['updated_by'], 'created_at' => Import::at($r['created_at']),
            'updated_at' => Import::at($r['updated_at']), 'deleted_at' => null,
        ], $rows), count($rows));
    }

    private function feed(Import $i): void
    {
        $events = DB::table('events')->pluck('id')->flip()->all();
        $participants = DB::table('participants')->pluck('id')->flip()->all();
        $rows = $i->rows('client_notifications');
        $i->put('client_notifications', 'client_notifications', array_values(array_filter(array_map(static fn (array $r): ?array => ($client = $i->id('website_client', $r['website_client_id'])) === null ? null : [
            'id' => $r['id'], 'client_id' => $client, 'category' => $r['category'], 'type' => $r['type'], 'title' => $r['title'], 'body' => $r['body'],
            'event_id' => isset($events[(int) $r['event_id']]) ? $r['event_id'] : null,
            'participant_id' => isset($participants[(int) $r['participant_id']]) ? $r['participant_id'] : null,
            'link' => $r['link'], 'meta' => Import::json($r['meta']), 'is_read' => Import::bit($r['is_read'], 0), 'read_at' => Import::at($r['read_at']),
            'archived_at' => Import::at($r['archived_at']), 'created_at' => Import::at($r['created_at']), 'updated_at' => Import::at($r['updated_at']),
        ], $rows))), count($rows));
    }

    /** Messages (campaigns) first, then notices, numbered in that order. */
    private function broadcasts(Import $i): void
    {
        $events = DB::table('events')->pluck('id')->flip()->all();
        $next = 1;
        $out = [];
        $channelsOf = [];

        $campaigns = $i->rows('event_message_campaigns');
        foreach ($campaigns as $r) {
            if (! isset($events[(int) $r['event_id']])) {
                continue;
            }
            $i->map('event_message_campaign', (int) $r['id'], $next);
            $out[] = $this->broadcast($next++, $r, 'message', [
                'sent_by_client_id' => $i->id('website_client', $r['website_client_id']), 'title' => $r['subject'], 'body' => $r['body'], 'channel' => $r['channel'], 'channels' => Import::json([$r['channel']]), 'kind' => $r['kind'],
                'audience' => $r['audience'], 'group_ids' => Import::json($r['group_ids']), 'participant_ids' => Import::json($r['guest_ids']),
                'sides' => null, 'relationships' => null, 'click_action' => $r['click_action'], 'deep_link' => $r['deep_link'],
                'data_payload' => Import::json($r['data_payload']), 'push_options' => Import::json($r['push_options']),
                'delivered_count' => 0, 'failed_count' => 0,
            ]);
        }
        $notices = $i->rows('event_notifications');
        foreach ($notices as $r) {
            if (! isset($events[(int) $r['event_id']])) {
                continue;
            }
            $i->map('event_notification', (int) $r['id'], $next);
            $channelsOf[(int) $r['id']] = Import::list($r['channels']);
            $out[] = $this->broadcast($next++, $r, 'notice', [
                'sent_by_client_id' => $i->id('website_client', $r['website_client_id']), 'title' => $r['title'], 'body' => $r['message'], 'channel' => null, 'channels' => Import::json($r['channels']), 'kind' => null,
                'audience' => $r['audience'], 'group_ids' => null, 'participant_ids' => Import::json($r['participant_ids']),
                'sides' => Import::json($r['sides']), 'relationships' => Import::json($r['relationships']), 'click_action' => null, 'deep_link' => null,
                'data_payload' => null, 'push_options' => null, 'delivered_count' => (int) $r['delivered_count'], 'failed_count' => (int) $r['failed_count'],
            ]);
        }
        $i->put('event_message_campaigns + event_notifications', 'event_broadcasts', $out, count($campaigns) + count($notices));

        $participants = DB::table('participants')->pluck('event_id', 'id')->all();
        $recipients = [];
        $seen = [];
        $messages = $i->rows('event_messages', withDeleted: false);
        foreach ($messages as $r) {
            $broadcast = $i->id('event_message_campaign', $r['campaign_id']);
            if (! isset($events[(int) $r['event_id']]) || ($broadcast !== null && isset($seen["{$broadcast}|{$r['participant_id']}"]))) {
                continue;
            }
            $seen["{$broadcast}|{$r['participant_id']}"] = true;
            $recipients[] = $this->recipient($i, $broadcast, $r, $participants, [
                'channel' => $r['channel'], 'kind' => $r['kind'], 'status' => $r['status'], 'sender' => $r['sender'] ?? 'client',
                'sender_client_id' => $i->id('website_client', $r['sender_client_id']), 'client_id' => null,
                'delivered_at' => Import::at($r['delivered_at']), 'opened_at' => Import::at($r['opened_at']), 'clicked_at' => Import::at($r['clicked_at']),
                'is_read' => 0, 'read_at' => null,
            ]);
        }
        $noticeRows = $i->rows('event_notification_recipients');
        $owners = array_column($notices, 'website_client_id', 'id');
        foreach ($noticeRows as $r) {
            $broadcast = $i->id('event_notification', $r['notification_id']);
            if ($broadcast === null || isset($seen["{$broadcast}|{$r['participant_id']}"])) {
                continue;
            }
            $seen["{$broadcast}|{$r['participant_id']}"] = true;
            $recipients[] = $this->recipient($i, $broadcast, $r, $participants, [
                'channel' => in_array('push', $channelsOf[(int) $r['notification_id']] ?? [], true) ? 'push' : 'in_app', 'kind' => null,
                'status' => $r['status'], 'sender' => 'client', 'sender_client_id' => $i->id('website_client', $owners[$r['notification_id']] ?? null),
                'client_id' => $i->id('website_client', $r['participant_client_id']), 'delivered_at' => null, 'opened_at' => null, 'clicked_at' => null,
                'is_read' => Import::bit($r['is_read'], 0), 'read_at' => Import::at($r['read_at']),
            ]);
        }
        $recipients = array_map(static fn (array $row, int $k): array => ['id' => $k + 1, ...$row], $recipients, array_keys($recipients));
        $i->put('event_messages + event_notification_recipients', 'event_broadcast_recipients', $recipients, count($messages) + count($noticeRows));
    }

    /**
     * @param  array<string, mixed>  $r
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function broadcast(int $id, array $r, string $source, array $values): array
    {
        return ['id' => $id, 'event_id' => $r['event_id'], 'source' => $source, ...$values,
            'image_url' => $r['image_url'], 'status' => $r['status'], 'scheduled_at' => Import::at($r['scheduled_at']), 'timezone' => $r['timezone'],
            'sent_at' => Import::at($r['sent_at']), 'recipients_count' => (int) $r['recipients_count'], 'failed_reason' => $r['failed_reason'],
            'created_at' => Import::at($r['created_at']), 'updated_at' => Import::at($r['updated_at']), 'deleted_at' => Import::at($r['deleted_at'])];
    }

    /**
     * @param  array<string, mixed>  $r
     * @param  array<int, int>  $participants  participant id => event id
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function recipient(Import $i, ?int $broadcast, array $r, array $participants, array $values): array
    {
        $participant = isset($participants[(int) $r['participant_id']]) ? (int) $r['participant_id'] : null;

        return ['broadcast_id' => $broadcast, 'event_id' => $r['event_id'], 'participant_id' => $participant, ...$values,
            'sent_at' => Import::at($r['sent_at']), 'failed_reason' => $r['failed_reason'],
            'created_at' => Import::at($r['created_at']), 'updated_at' => Import::at($r['updated_at'])];
    }

    private function mail(Import $i): void
    {
        $rows = $i->rows('mail_folders', withDeleted: false);
        $folders = [];
        $i->put('mail_folders', 'mail_folders', array_values(array_filter(array_map(function (array $r) use ($i, &$folders): ?array {
            if (($owner = $this->actor($i, $r['owner_type'], $r['owner_id'])) === null) {
                return null;
            }
            $folders[(int) $r['id']] = true;

            return ['id' => $r['id'], 'owner_type' => $r['owner_type'], 'owner_id' => $owner, 'name' => $r['name'], 'is_active' => Import::bit($r['is_active']),
                'created_at' => Import::at($r['created_at']), 'updated_at' => Import::at($r['updated_at']), 'deleted_at' => null];
        }, $rows))), count($rows));
        $folder = static fn (mixed $id): ?int => is_numeric($id) && isset($folders[(int) $id]) ? (int) $id : null;

        $rows = $i->rows('mails');
        $mails = [];
        $i->put('mails', 'mails', array_values(array_filter(array_map(function (array $r) use ($i, $folder, &$mails): ?array {
            if (($sender = $this->actor($i, $r['sender_type'], $r['sender_id'])) === null) {
                $i->note("mail {$r['id']}: its sender ({$r['sender_type']} {$r['sender_id']}) no longer exists, skipped");

                return null;
            }
            $mails[(int) $r['id']] = true;

            return ['id' => $r['id'], 'company_id' => $r['company_id'] ?? 1, 'sender_type' => $r['sender_type'], 'sender_id' => $sender,
                'subject' => $r['subject'], 'body' => $r['body'], 'status' => $r['status'], 'sent_at' => Import::at($r['sent_at']),
                'error_message' => $r['error_message'], 'sender_is_active' => Import::bit($r['sender_is_active']), 'sender_label' => $r['sender_label'],
                'sender_custom_folder_id' => $folder($r['sender_custom_folder_id']),
                'created_at' => Import::at($r['created_at']), 'updated_at' => Import::at($r['updated_at'])];
        }, $rows))), count($rows));

        $rows = $i->rows('mail_recipients');
        $i->put('mail_recipients', 'mail_recipients', array_values(array_filter(array_map(fn (array $r): ?array => ! isset($mails[(int) $r['mail_id']])
            || ($to = $this->actor($i, $r['recipient_type'], $r['recipient_id'])) === null ? null : [
                'id' => $r['id'], 'mail_id' => $r['mail_id'], 'recipient_type' => $r['recipient_type'], 'recipient_id' => $to, 'role' => $r['role'],
                'is_read' => Import::bit($r['is_read'], 0), 'is_active' => Import::bit($r['is_active']), 'label' => $r['label'],
                'custom_folder_id' => $folder($r['custom_folder_id']), 'created_at' => Import::at($r['created_at']), 'updated_at' => Import::at($r['updated_at']),
            ], $rows))), count($rows));

        $rows = $i->rows('mail_notifications');
        $i->put('mail_notifications', 'mail_notifications', array_values(array_filter(array_map(fn (array $r): ?array => ! isset($mails[(int) $r['mail_id']])
            || ($to = $this->actor($i, $r['recipient_type'], $r['recipient_id'])) === null ? null : [
                'id' => $r['id'], 'mail_id' => $r['mail_id'], 'recipient_type' => $r['recipient_type'], 'recipient_id' => $to,
                'is_read' => Import::bit($r['is_read'], 0), 'created_at' => Import::at($r['created_at']),
            ], $rows))), count($rows));
    }

    private function chat(Import $i): void
    {
        $vendors = DB::table('vendors')->pluck('id')->flip()->all();
        $rows = $i->rows('chat_conversations');
        $conversations = [];
        $i->put('chat_conversations', 'chat_conversations', array_map(static function (array $r) use ($vendors, &$conversations): array {
            $conversations[(int) $r['id']] = true;

            return ['id' => $r['id'], 'company_id' => $r['company_id'] ?? 1, 'vendor_id' => isset($vendors[(int) $r['vendor_id']]) ? $r['vendor_id'] : null,
                'conversation_type' => $r['conversation_type'], 'context_type' => $r['context_type'] ?: 'general', 'title' => $r['title'],
                'last_message_id' => null, 'last_message_at' => Import::at($r['last_message_at']), 'is_active' => Import::bit($r['is_active']),
                'created_at' => Import::at($r['created_at']), 'updated_at' => Import::at($r['updated_at']), 'deleted_at' => Import::at($r['deleted_at'])];
        }, $rows), count($rows));

        $rows = $i->rows('chat_messages');
        $messages = [];
        $i->put('chat_messages', 'chat_messages', array_values(array_filter(array_map(function (array $r) use ($i, $conversations, &$messages): ?array {
            if (! isset($conversations[(int) $r['conversation_id']]) || ($sender = $this->actor($i, $r['sender_type'], $r['sender_id'])) === null) {
                return null;
            }
            $messages[(int) $r['id']] = (int) $r['conversation_id'];

            return ['id' => $r['id'], 'conversation_id' => $r['conversation_id'], 'sender_type' => $r['sender_type'], 'sender_id' => $sender,
                'message' => $r['message'], 'message_type' => $r['message_type'], 'metadata' => Import::json($r['metadata']),
                'created_at' => Import::at($r['created_at']), 'updated_at' => Import::at($r['updated_at']), 'deleted_at' => Import::at($r['deleted_at'])];
        }, $rows))), count($rows));

        // Each conversation's last message, now that the messages exist.
        foreach ($i->rows('chat_conversations') as $r) {
            if (isset($messages[(int) $r['last_message_id']])) {
                DB::table('chat_conversations')->where('id', $r['id'])->update(['last_message_id' => $r['last_message_id']]);
            }
        }

        // Read state: v1 kept it twice; the later of the two wins.
        $reads = [];
        foreach ($i->rows('chat_read_states') as $s) {
            $reads["{$s['conversation_id']}|{$s['actor_type']}|{$s['actor_id']}"] = $s;
        }
        $rows = $i->rows('chat_participants', withDeleted: false);
        $i->put('chat_participants', 'chat_participants', array_values(array_filter(array_map(function (array $r) use ($i, $conversations, $messages, $reads): ?array {
            if (! isset($conversations[(int) $r['conversation_id']]) || ($actor = $this->actor($i, $r['actor_type'], $r['actor_id'])) === null) {
                return null;
            }
            $read = $reads["{$r['conversation_id']}|{$r['actor_type']}|{$r['actor_id']}"] ?? null;
            $lastId = max((int) $r['last_read_message_id'], (int) ($read['last_read_message_id'] ?? 0));
            $lastAt = max((string) Import::at($r['last_read_at']), (string) Import::at($read['last_read_at'] ?? null));

            return ['id' => $r['id'], 'conversation_id' => $r['conversation_id'], 'actor_type' => $r['actor_type'], 'actor_id' => $actor,
                'display_name' => $r['display_name'], 'avatar' => Import::text($r['avatar'], 500),
                'last_read_message_id' => isset($messages[$lastId]) ? $lastId : null, 'last_read_at' => $lastAt === '' ? null : $lastAt,
                'is_active' => Import::bit($r['is_active']), 'created_at' => Import::at($r['created_at']), 'updated_at' => Import::at($r['updated_at'])];
        }, $rows))), count($rows));
    }

    private function vendorEmail(Import $i): void
    {
        $vendors = DB::table('vendors')->pluck('id')->flip()->all();
        $ofVendor = static fn (array $rows): array => array_values(array_filter($rows, static fn (array $r): bool => isset($vendors[(int) $r['vendor_id']])));

        $rows = $i->rows('vendor_email_categories');
        $categories = [];
        $i->put('vendor_email_categories', 'vendor_email_categories', array_map(static function (array $r) use (&$categories): array {
            $categories[(int) $r['id']] = true;

            return ['id' => $r['id'], 'vendor_id' => $r['vendor_id'], 'name' => $r['name'], 'description' => $r['description'],
                'sort_order' => (int) $r['sort_order'], 'is_active' => Import::bit($r['is_active']),
                'created_at' => Import::at($r['createdAt']), 'updated_at' => Import::at($r['updatedAt']), 'deleted_at' => Import::at($r['deletedAt'])];
        }, $ofVendor($rows)), count($rows));

        $rows = $i->rows('vendor_email_templates');
        $templates = [];
        $i->put('vendor_email_templates', 'vendor_email_templates', array_map(static function (array $r) use ($categories, &$templates): array {
            $templates[(int) $r['id']] = true;

            return ['id' => $r['id'], 'vendor_id' => $r['vendor_id'], 'category_id' => isset($categories[(int) $r['category_id']]) ? $r['category_id'] : null,
                'name' => $r['name'], 'description' => $r['description'], 'is_active' => Import::bit($r['is_active']),
                'created_at' => Import::at($r['createdAt']), 'updated_at' => Import::at($r['updatedAt']), 'deleted_at' => Import::at($r['deletedAt'])];
        }, $ofVendor($rows)), count($rows));

        $rows = $i->rows('vendor_newsletter_sends');
        $sends = [];
        $i->put('vendor_newsletter_sends', 'vendor_newsletters', array_map(static function (array $r) use ($categories, $templates, &$sends): array {
            $sends[(int) $r['id']] = true;

            return ['id' => $r['id'], 'vendor_id' => $r['vendor_id'], 'template_id' => isset($templates[(int) $r['template_id']]) ? $r['template_id'] : null,
                'template_name' => $r['template_name'], 'category_id' => isset($categories[(int) $r['category_id']]) ? $r['category_id'] : null,
                'user_type' => $r['user_type'], 'plans' => Import::json($r['plans']), 'total_sent' => (int) $r['total_sent'], 'mail_id' => null,
                'created_at' => Import::at($r['createdAt']), 'updated_at' => Import::at($r['updatedAt'])];
        }, $ofVendor($rows)), count($rows));

        $rows = $i->rows('vendor_newsletter_sent_logs');
        $orphans = count(array_filter($rows, static fn (array $r): bool => ! isset($sends[(int) $r['campaign_id']])));
        if ($orphans > 0) {
            $i->note("{$orphans} newsletter log rows belong to sends that no longer exist, left out");
        }
        $i->put('vendor_newsletter_sent_logs', 'vendor_newsletter_recipients', array_values(array_map(static fn (array $r): array => [
            'id' => $r['id'], 'newsletter_id' => $r['campaign_id'], 'client_id' => $i->id('vendor_client', $r['client_id']), 'email' => $r['email'],
            'name' => $r['name'], 'membership' => $r['membership'], 'template' => $r['template'], 'status' => $r['status'] ?? 'sent',
            'opened_at' => Import::at($r['opened_at']), 'clicked_at' => Import::at($r['clicked_at']),
            'created_at' => Import::at($r['createdAt']), 'updated_at' => Import::at($r['updatedAt']),
        ], array_filter($rows, static fn (array $r): bool => isset($sends[(int) $r['campaign_id']])))), count($rows));
    }
}
