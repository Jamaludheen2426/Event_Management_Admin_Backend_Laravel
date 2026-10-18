<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Actions;

use App\Core\Contracts\ObjectStorage;
use App\Core\Exceptions\ApiException;
use App\Core\Support\Dates;
use App\Modules\Messaging\Models\Mail;
use App\Modules\Messaging\Models\MailFolder;
use App\Modules\Messaging\Models\MailNotification;
use App\Modules\Messaging\Models\MailRecipient;
use App\Modules\Messaging\Support\Actor;
use App\Modules\Messaging\Support\People;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * In-app mail between admins, vendors and clients (v1 mail.service): drafts,
 * send, inbox / sent / drafts / trash, labels, folders and the mail bell.
 * Nothing is e-mailed; a mail lives only here.
 *
 * Every copy belongs to one person: the sender's on the mail row, each
 * recipient's on their recipient row. Trash, labels and folders change only
 * the caller's copy.
 */
final class Mailbox
{
    private const LABELS = ['social', 'promotions', 'updates'];

    private const MAX_FOLDERS = 10;

    public function __construct(
        private readonly People $people,
        private readonly ObjectStorage $storage,
    ) {}

    /** @return array<string, list<array<string, mixed>>> */
    public function contacts(Actor $actor): array
    {
        return $this->people->contacts($actor, withAvatars: false);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function saveDraft(Actor $actor, array $input): array
    {
        $this->assertSubject($input);
        $recipients = $this->recipients($actor, $input['recipients'] ?? []);
        $draftId = $input['id'] ?? $input['draft_id'] ?? null;
        if (is_numeric($draftId)) {
            return $this->updateDraft($actor, (int) $draftId, $input);
        }

        $mail = DB::transaction(function () use ($actor, $input, $recipients): Mail {
            $mail = Mail::query()->create([
                'company_id' => $actor->companyId, 'sender_type' => $actor->type, 'sender_id' => $actor->id,
                'subject' => (string) $input['subject'], 'body' => $this->storeImages((string) ($input['body'] ?? '')), 'status' => 'draft',
            ]);
            $this->syncRecipients($mail, $recipients);

            return $mail;
        });

        return $this->mail($mail);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function updateDraft(Actor $actor, int $id, array $input): array
    {
        $this->assertSubject($input);
        $mail = $this->sent($actor)->where('status', 'draft')->find($id) ?? throw ApiException::notFound('Draft not found.');
        $recipients = $this->recipients($actor, $input['recipients'] ?? []);
        DB::transaction(function () use ($mail, $input, $recipients): void {
            $mail->fill(['subject' => (string) $input['subject'], 'body' => $this->storeImages((string) ($input['body'] ?? '')), 'sender_is_active' => 1])->save();
            $this->syncRecipients($mail, $recipients);
        });

        return $this->mail($mail->load('recipients'));
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function send(Actor $actor, array $input): array
    {
        $this->assertSendable($input);
        $recipients = $this->recipients($actor, $input['recipients']);
        $draftId = $input['id'] ?? $input['draft_id'] ?? null;
        if (is_numeric($draftId)) {
            return $this->sendDraft($actor, (int) $draftId, $input);
        }

        $mail = DB::transaction(function () use ($actor, $input, $recipients): Mail {
            $mail = Mail::query()->create([
                'company_id' => $actor->companyId, 'sender_type' => $actor->type, 'sender_id' => $actor->id,
                'subject' => (string) $input['subject'], 'body' => $this->storeImages((string) $input['body']), 'status' => 'sent', 'sent_at' => now(),
            ]);
            $this->deliver($mail, $recipients);

            return $mail;
        });

        return $this->mail($mail);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function sendDraft(Actor $actor, int $id, array $input): array
    {
        $mail = $this->sent($actor)->where('status', 'draft')->find($id) ?? throw ApiException::notFound('Draft not found.');
        $payload = ['subject' => $input['subject'] ?? $mail->subject, 'body' => $input['body'] ?? $mail->body, 'recipients' => $input['recipients'] ?? []];
        $this->assertSendable($payload);
        $recipients = $this->recipients($actor, $payload['recipients']);

        DB::transaction(function () use ($mail, $payload, $recipients): void {
            $mail->fill([
                'subject' => (string) $payload['subject'], 'body' => $this->storeImages((string) $payload['body']),
                'status' => 'sent', 'sent_at' => now(), 'error_message' => null, 'sender_is_active' => 1,
            ])->save();
            $this->deliver($mail, $recipients);
        });

        return $this->mail($mail->load('recipients'));
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array{total: int, rows: list<array<string, mixed>>}
     */
    public function inbox(Actor $actor, array $query): array
    {
        [$limit, $page] = $this->page($query);
        $rows = $this->received($actor)->where('is_active', 1)
            ->when(($query['label'] ?? '') !== '', static fn (Builder $q) => $q->where('label', $query['label']))
            ->when(is_numeric($query['folder_id'] ?? null), static fn (Builder $q) => $q->where('custom_folder_id', (int) $query['folder_id']))
            ->whereHas('mail', static fn (Builder $q) => $q->where('status', 'sent'));
        $total = (clone $rows)->count();
        $list = $rows->with('mail')
            ->orderByDesc(Mail::query()->select('sent_at')->whereColumn('mails.id', 'mail_recipients.mail_id'))
            ->forPage($page, $limit)->get();

        return ['total' => $total, 'rows' => $list->map(fn (MailRecipient $r): array => $this->recipient($r))->all()];
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array{count: int, rows: list<array<string, mixed>>}
     */
    public function sentBox(Actor $actor, array $query, string $status): array
    {
        [$limit, $page] = $this->page($query);
        $rows = $this->sent($actor)->where('status', $status)->where('sender_is_active', 1)
            ->when(($query['label'] ?? '') !== '', static fn (Builder $q) => $q->where('sender_label', $query['label']))
            ->when(is_numeric($query['folder_id'] ?? null), static fn (Builder $q) => $q->where('sender_custom_folder_id', (int) $query['folder_id']));
        $count = (clone $rows)->count();
        $list = $rows->with('recipients')->orderByDesc($status === 'sent' ? 'sent_at' : 'updated_at')->orderByDesc('id')->forPage($page, $limit)->get();

        // Sent shows who each mail went to by name.
        $names = $status === 'sent'
            ? $this->people->profiles($list->flatMap(static fn (Mail $m) => $m->recipients->map(static fn (MailRecipient $r): array => [$r->recipient_type, $r->recipient_id]))->values()->all())
            : [];

        return ['count' => $count, 'rows' => $list->map(fn (Mail $m): array => $this->mail($m, $names))->all()];
    }

    /** @return array{mail: array<string, mixed>, recipientRow: array<string, mixed>|null} */
    public function find(Actor $actor, int $id): array
    {
        $mail = Mail::query()->with('recipients')->find($id) ?? throw ApiException::notFound('Mail not found.');
        $ownsIt = $mail->sender_type === $actor->type && $mail->sender_id === $actor->id && $mail->sender_is_active !== 2;
        $copy = $this->received($actor)->where('mail_id', $id)->where('is_active', '!=', 2)->first();
        if (! $ownsIt && $copy === null) {
            throw ApiException::notFound('Mail not found.');
        }

        return ['mail' => $this->mail($mail), 'recipientRow' => $copy === null ? null : $this->recipient($copy)];
    }

    /** Trash = 0, restore = 1, delete for good = 2; the caller's own copy only. */
    public function setState(Actor $actor, int $id, int $from, int $to): void
    {
        $own = $this->sent($actor)->whereKey($id)->where('sender_is_active', $from === 1 ? 1 : $from)->first();
        if ($own !== null) {
            $own->forceFill(['sender_is_active' => $to])->save();

            return;
        }
        $this->received($actor)->where('mail_id', $id)->where('is_active', $from)->update(['is_active' => $to]);
    }

    /** @param list<int> $ids */
    public function trashMany(Actor $actor, array $ids): void
    {
        $this->sent($actor)->whereKey($ids)->where('sender_is_active', 1)->update(['sender_is_active' => 0]);
        $this->received($actor)->whereIn('mail_id', $ids)->where('is_active', 1)->update(['is_active' => 0]);
    }

    /** @return list<array<string, mixed>> */
    public function trash(Actor $actor): array
    {
        $received = $this->received($actor)->where('is_active', 0)->with('mail')->orderByDesc('updated_at')->get()
            ->map(fn (MailRecipient $r): array => $this->recipient($r));
        $sent = $this->sent($actor)->where('sender_is_active', 0)->with('recipients')->orderByDesc('updated_at')->get()
            ->map(fn (Mail $m): array => [
                'id' => $m->id, 'mail_id' => $m->id, 'recipient_type' => $m->sender_type, 'recipient_id' => $m->sender_id,
                'role' => 'to', 'is_read' => 1, 'is_active' => 0, 'label' => $m->getAttribute('sender_label'),
                'custom_folder_id' => $m->getAttribute('sender_custom_folder_id'), 'owner' => 'sender',
                'updatedAt' => Dates::at($m->updated_at), 'mail' => $this->mail($m),
            ]);

        return $received->concat($sent)->sortByDesc(static fn (array $row): string => (string) ($row['updatedAt'] ?? $row['mail']['updatedAt'] ?? ''))->values()->all();
    }

    /** Flips read / unread; reading also clears the bell for that mail. */
    public function toggleRead(Actor $actor, int $id): void
    {
        $copy = $this->received($actor)->where('mail_id', $id)->first();
        if ($copy === null) {
            return;
        }
        $copy->forceFill(['is_read' => ! $copy->is_read])->save();
        if ($copy->is_read) {
            $this->bell($actor)->where('mail_id', $id)->update(['is_read' => true]);
        }
    }

    /** @param list<int> $ids */
    public function markMany(Actor $actor, array $ids, bool $read): void
    {
        $this->received($actor)->whereIn('mail_id', $ids)->update(['is_read' => $read]);
    }

    /** @param list<int> $ids */
    public function label(Actor $actor, array $ids, mixed $label): void
    {
        $label = $label === null || $label === '' ? null : (string) $label;
        if ($label !== null && ! in_array($label, self::LABELS, true)) {
            throw ApiException::badRequest('Invalid label.');
        }
        $this->sent($actor)->whereKey($ids)->where('sender_is_active', '!=', 2)->update(['sender_label' => $label]);
        $this->received($actor)->whereIn('mail_id', $ids)->where('is_active', '!=', 2)->update(['label' => $label]);
    }

    /** @param list<int> $ids */
    public function moveToFolder(Actor $actor, array $ids, mixed $folderId): void
    {
        $folder = is_numeric($folderId) && (int) $folderId > 0 ? (int) $folderId : null;
        if ($folder !== null && ! $this->folders($actor)->whereKey($folder)->where('is_active', 1)->exists()) {
            throw ApiException::notFound('Folder not found.');
        }
        $this->sent($actor)->whereKey($ids)->where('sender_is_active', '!=', 2)->update(['sender_custom_folder_id' => $folder]);
        $this->received($actor)->whereIn('mail_id', $ids)->where('is_active', '!=', 2)->update(['custom_folder_id' => $folder]);
    }

    /** @return list<array<string, mixed>> */
    public function folderList(Actor $actor): array
    {
        return $this->folders($actor)->where('is_active', 1)->orderBy('name')->get()->map(fn (MailFolder $f): array => $this->folder($f))->all();
    }

    /** @return array<string, mixed> */
    public function createFolder(Actor $actor, mixed $name): array
    {
        $name = trim(is_scalar($name) ? (string) $name : '');
        if ($name === '') {
            throw ApiException::badRequest('Folder name is required.');
        }
        if ($this->folders($actor)->where('is_active', 1)->count() >= self::MAX_FOLDERS) {
            throw ApiException::badRequest('Maximum 10 folders allowed.');
        }

        return $this->folder(MailFolder::query()->create(['owner_type' => $actor->type, 'owner_id' => $actor->id, 'name' => mb_substr($name, 0, 100)]));
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function updateFolder(Actor $actor, int $id, array $input): array
    {
        $folder = $this->folders($actor)->find($id) ?? throw ApiException::notFound('Folder not found.');
        $folder->fill([
            'name' => isset($input['name']) && trim((string) $input['name']) !== '' ? mb_substr(trim((string) $input['name']), 0, 100) : $folder->name,
            'is_active' => isset($input['is_active']) ? (int) filter_var($input['is_active'], FILTER_VALIDATE_BOOL) : $folder->is_active,
        ])->save();

        return $this->folder($folder);
    }

    /** Mails in the folder fall back to their box. */
    public function deleteFolder(Actor $actor, int $id): void
    {
        $folder = $this->folders($actor)->find($id) ?? throw ApiException::notFound('Folder not found.');
        DB::transaction(function () use ($actor, $folder): void {
            $this->received($actor)->where('custom_folder_id', $folder->id)->update(['custom_folder_id' => null]);
            $this->sent($actor)->where('sender_custom_folder_id', $folder->id)->update(['sender_custom_folder_id' => null]);
            $folder->delete();
        });
    }

    /** @return array{unread_count: int, notifications: list<array<string, mixed>>} */
    public function notifications(Actor $actor): array
    {
        return [
            'unread_count' => $this->bell($actor)->where('is_read', false)->count(),
            'notifications' => $this->bell($actor)->with('mail:id,subject,sender_type,sender_id,sent_at')->orderByDesc('created_at')->orderByDesc('id')->limit(10)->get()
                ->map(static fn (MailNotification $n): array => [
                    'id' => $n->id, 'mail_id' => $n->mail_id, 'recipient_type' => $n->recipient_type, 'recipient_id' => $n->recipient_id,
                    'is_read' => (int) $n->is_read, 'createdAt' => Dates::at($n->created_at),
                    'mail' => $n->mail === null ? null : ['id' => $n->mail->id, 'subject' => $n->mail->subject, 'sender_type' => $n->mail->sender_type, 'sender_id' => $n->mail->sender_id, 'sent_at' => Dates::at($n->mail->sent_at)],
                ])->all(),
        ];
    }

    public function readAllNotifications(Actor $actor): void
    {
        $this->bell($actor)->where('is_read', false)->update(['is_read' => true]);
    }

    // ---------------------------------------------------------------------

    /**
     * @param  list<array{recipient_type: string, recipient_id: int, role: string}>  $recipients
     */
    private function deliver(Mail $mail, array $recipients): void
    {
        $this->syncRecipients($mail, $recipients);
        $to = array_values(array_filter($recipients, static fn (array $r): bool => $r['role'] === 'to'));
        if ($to !== []) {
            MailNotification::query()->insert(array_map(static fn (array $r): array => [
                'mail_id' => $mail->id, 'recipient_type' => $r['recipient_type'], 'recipient_id' => $r['recipient_id'], 'is_read' => false, 'created_at' => now(),
            ], $to));
        }
    }

    /** @param list<array{recipient_type: string, recipient_id: int, role: string}> $recipients */
    private function syncRecipients(Mail $mail, array $recipients): void
    {
        MailRecipient::query()->where('mail_id', $mail->id)->delete();
        $now = now();
        if ($recipients !== []) {
            MailRecipient::query()->insert(array_map(static fn (array $r): array => [
                ...$r, 'mail_id' => $mail->id, 'is_read' => false, 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now,
            ], $recipients));
        }
        $mail->unsetRelation('recipients');
    }

    /**
     * The form's [{type, id, role}], each checked against who the caller may write to.
     *
     * @return list<array{recipient_type: string, recipient_id: int, role: string}>
     */
    private function recipients(Actor $actor, mixed $raw): array
    {
        $out = [];
        foreach (is_array($raw) ? $raw : [] as $r) {
            if (! is_array($r) || ! in_array($r['type'] ?? null, Actor::TYPES, true) || ! is_numeric($r['id'] ?? null) || (int) $r['id'] <= 0) {
                continue;
            }
            $this->people->assertCanReach($actor, (string) $r['type'], (int) $r['id'], adminsToAdmins: true);
            $role = in_array($r['role'] ?? 'to', ['to', 'cc', 'bcc'], true) ? (string) ($r['role'] ?? 'to') : 'to';
            $out[] = ['recipient_type' => (string) $r['type'], 'recipient_id' => (int) $r['id'], 'role' => $role];
        }

        return $out;
    }

    /** @param array<string, mixed> $input */
    private function assertSubject(array $input): void
    {
        if (trim(is_scalar($input['subject'] ?? null) ? (string) $input['subject'] : '') === '') {
            throw ApiException::badRequest('Subject is required.');
        }
    }

    /** @param array<string, mixed> $input */
    private function assertSendable(array $input): void
    {
        $this->assertSubject($input);
        $body = is_scalar($input['body'] ?? null) ? trim((string) $input['body']) : '';
        if ($body === '' || $body === '<p><br></p>') {
            throw ApiException::badRequest('Message body is required.');
        }
        if (! is_array($input['recipients'] ?? null) || $input['recipients'] === []) {
            throw ApiException::badRequest('At least one recipient is required.');
        }
    }

    /** Pasted images arrive as data: URIs inside the HTML; they are stored and linked instead. */
    private function storeImages(string $html): string
    {
        return (string) preg_replace_callback('#data:image/[a-z0-9.+-]+;base64,[A-Za-z0-9+/=]+#i', function (array $m): string {
            return $this->storage->storeDataUri($m[0], 'mail', 'mail-image') ?? $m[0];
        }, $html);
    }

    /** @return Builder<Mail> */
    private function sent(Actor $actor): Builder
    {
        return Mail::query()->where('sender_type', $actor->type)->where('sender_id', $actor->id)->where('sender_is_active', '!=', 2);
    }

    /** @return Builder<MailRecipient> */
    private function received(Actor $actor): Builder
    {
        return MailRecipient::query()->where('recipient_type', $actor->type)->where('recipient_id', $actor->id);
    }

    /** @return Builder<MailFolder> */
    private function folders(Actor $actor): Builder
    {
        return MailFolder::query()->where('owner_type', $actor->type)->where('owner_id', $actor->id);
    }

    /** @return Builder<MailNotification> */
    private function bell(Actor $actor): Builder
    {
        return MailNotification::query()->where('recipient_type', $actor->type)->where('recipient_id', $actor->id);
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array{0: int, 1: int}
     */
    private function page(array $query): array
    {
        return [min(100, max(1, (int) ($query['limit'] ?? 50) ?: 50)), max(1, (int) ($query['page'] ?? 1))];
    }

    /**
     * v1's Sequelize row: camelCase timestamps (createdAt) — plus snake_case,
     * which one inbox screen reads.
     *
     * @return array<string, mixed>
     */
    private function times(Model $model): array
    {
        $created = Dates::at($model->getAttribute('created_at'));
        $updated = Dates::at($model->getAttribute('updated_at'));

        return ['createdAt' => $created, 'updatedAt' => $updated, 'created_at' => $created, 'updated_at' => $updated];
    }

    /**
     * @param  array<string, array{name: string, email: string|null, avatar: string|null, vendor_id: int|null}>  $names
     * @return array<string, mixed>
     */
    private function mail(Mail $m, array $names = []): array
    {
        return [
            'id' => $m->id, 'company_id' => $m->company_id, 'sender_type' => $m->sender_type, 'sender_id' => $m->sender_id,
            'subject' => $m->subject, 'body' => $m->body, 'status' => $m->status, 'sent_at' => Dates::at($m->sent_at),
            'error_message' => $m->getAttribute('error_message'), 'sender_is_active' => $m->sender_is_active,
            'sender_label' => $m->getAttribute('sender_label'), 'sender_custom_folder_id' => $m->getAttribute('sender_custom_folder_id'),
            ...$this->times($m),
            ...($m->relationLoaded('recipients') ? ['recipients' => $m->recipients->map(fn (MailRecipient $r): array => [
                ...$this->recipient($r),
                ...(isset($names["{$r->recipient_type}:{$r->recipient_id}"]) ? [
                    'recipient_name' => $names["{$r->recipient_type}:{$r->recipient_id}"]['name'],
                    'recipient_email' => $names["{$r->recipient_type}:{$r->recipient_id}"]['email'],
                ] : []),
            ])->all()] : []),
        ];
    }

    /** @return array<string, mixed> */
    private function recipient(MailRecipient $r): array
    {
        return [
            'id' => $r->id, 'mail_id' => $r->mail_id, 'recipient_type' => $r->recipient_type, 'recipient_id' => $r->recipient_id,
            'role' => $r->role, 'is_read' => (int) $r->is_read, 'is_active' => $r->is_active, 'label' => $r->getAttribute('label'),
            'custom_folder_id' => $r->getAttribute('custom_folder_id'), ...$this->times($r),
            ...($r->relationLoaded('mail') && $r->mail !== null ? ['mail' => $this->mail($r->mail)] : []),
        ];
    }

    /** @return array<string, mixed> */
    private function folder(MailFolder $f): array
    {
        return ['id' => $f->id, 'owner_type' => $f->owner_type, 'owner_id' => $f->owner_id, 'name' => $f->name, 'is_active' => $f->is_active, ...$this->times($f)];
    }
}
