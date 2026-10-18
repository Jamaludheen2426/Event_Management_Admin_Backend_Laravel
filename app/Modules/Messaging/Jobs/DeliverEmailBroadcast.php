<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Jobs;

use App\Modules\Clients\Models\Client;
use App\Modules\Events\Models\Event;
use App\Modules\Messaging\Models\Broadcast;
use App\Modules\Messaging\Models\BroadcastRecipient;
use App\Modules\Messaging\Support\MergeFields;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Sends an e-mail broadcast, one personalised e-mail per queued recipient,
 * through Settings → Email's provider. Runs on the queue: an SMTP server
 * answering in a second each would hold a request for minutes.
 */
final class DeliverEmailBroadcast implements ShouldQueue
{
    use Queueable;

    public int $timeout = 900;

    public function __construct(public readonly int $broadcastId) {}

    public function handle(): void
    {
        $broadcast = Broadcast::query()->find($this->broadcastId);
        if ($broadcast === null || $broadcast->channel !== 'email') {
            return;
        }
        $event = Event::query()->withoutGlobalScopes()->find($broadcast->event_id);
        $host = $broadcast->sent_by_client_id === null ? null
            : Client::query()->withoutGlobalScopes()->whereKey($broadcast->sent_by_client_id)->value('name');

        $sent = 0;
        $failed = 0;
        BroadcastRecipient::query()->where('broadcast_id', $broadcast->id)->where('status', 'queued')
            ->with('participant')->chunkById(100, function ($recipients) use ($broadcast, $event, $host, &$sent, &$failed): void {
                foreach ($recipients as $recipient) {
                    /** @var BroadcastRecipient $recipient */
                    $guest = $recipient->participant;
                    $to = (string) $guest?->getAttribute('email');
                    try {
                        if ($to === '') {
                            throw new \RuntimeException('No email address on file');
                        }
                        $subject = MergeFields::render($broadcast->title, $guest, $event, $host);
                        $body = MergeFields::render($broadcast->body, $guest, $event, $host);
                        Mail::html(nl2br(e($body)), static fn ($m) => $m->to($to)->subject($subject));
                        $recipient->forceFill(['status' => 'sent', 'sent_at' => now()])->save();
                        $sent++;
                    } catch (Throwable $e) {
                        report($e);
                        $recipient->forceFill(['status' => 'failed', 'failed_reason' => mb_substr($e->getMessage(), 0, 255)])->save();
                        $failed++;
                    }
                }
            });

        $broadcast->forceFill([
            'status' => $sent > 0 || $failed === 0 ? 'sent' : 'failed',
            'delivered_count' => $broadcast->delivered_count + $sent,
            'failed_count' => $broadcast->failed_count + $failed,
            'failed_reason' => $sent === 0 && $failed > 0 ? 'No email could be delivered' : null,
        ])->save();
    }
}
