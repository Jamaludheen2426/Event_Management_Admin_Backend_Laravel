<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Console;

use App\Modules\Messaging\Actions\EventNotices;
use App\Modules\Messaging\Actions\MessageCampaigns;
use Illuminate\Console\Command;

/**
 * Sends scheduled messages and event notifications whose time has come
 * (v1 campaignScheduler + eventNotificationScheduler, each every minute).
 */
final class SendDueBroadcasts extends Command
{
    protected $signature = 'messaging:send-due';

    protected $description = 'Send scheduled messages and event notifications that are due';

    public function handle(MessageCampaigns $campaigns, EventNotices $notices): int
    {
        $messages = $campaigns->fireDue();
        $notifications = $notices->sendDue();
        $this->info("Sent {$messages} scheduled message(s) and {$notifications} event notification(s).");

        return self::SUCCESS;
    }
}
