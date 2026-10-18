<?php

declare(strict_types=1);

namespace App\Modules\Messaging;

use App\Core\Approvals\ApprovalRegistry;
use App\Core\Http\Middleware\PortalFromHeader;
use App\Core\Support\ModuleServiceProvider;
use App\Modules\Clients\Actions\ClientSettings;
use App\Modules\Clients\Events\ClientNotice;
use App\Modules\Clients\Models\Client;
use App\Modules\Events\Actions\EventReader;
use App\Modules\Events\Events\ParticipantAdded;
use App\Modules\Events\Models\Event as EventModel;
use App\Modules\Events\Support\MessageLog;
use App\Modules\Messaging\Actions\ClientNotifications;
use App\Modules\Messaging\Actions\EmailConfigChecks;
use App\Modules\Messaging\Actions\EmailConfigWriter;
use App\Modules\Messaging\Actions\EmailTemplates;
use App\Modules\Messaging\Actions\EmailTemplateWriter;
use App\Modules\Messaging\Actions\MessageCampaigns;
use App\Modules\Messaging\Actions\NoticeTemplates;
use App\Modules\Messaging\Console\SendDueBroadcasts;
use App\Modules\Messaging\Http\Controllers\ChatController;
use App\Modules\Messaging\Models\Broadcast;
use App\Modules\Messaging\Models\BroadcastRecipient;
use App\Modules\Messaging\Models\EmailConfig;
use App\Modules\Messaging\Support\ConfiguredTransport;
use App\Modules\Messaging\Support\MailTransports;
use App\Modules\Vendors\Models\Vendor;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Mail\MailManager;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;

/**
 * Messaging: how messages leave the platform (e-mail provider, Firebase
 * push), the client's notification feed, messages and notifications from an
 * organizer to their guests, and the automatic Welcome Invitation.
 */
final class MessagingServiceProvider extends ModuleServiceProvider
{
    public function register(): void
    {
        // Holds the template renderer registered below.
        $this->app->singleton(EmailConfigChecks::class);
    }

    protected function bootModule(): void
    {
        $approvals = $this->app->make(ApprovalRegistry::class);
        $approvals->register('email_configs', 'email_config', EmailConfigWriter::class);
        $approvals->register('email_templates', 'email_template', EmailTemplateWriter::class);

        // Settings → Email "Test" can send a chosen template with sample values.
        $this->app->make(EmailConfigChecks::class)->renderTemplatesUsing(
            fn (EmailConfig $config, int $templateId): array => $this->app->make(EmailTemplates::class)->sample($config, $templateId),
        );

        // The "configured" mailer sends through Settings → Email's default provider.
        $this->app->make(MailManager::class)->extend('configured', function (): ConfiguredTransport {
            $mail = $this->app->make(MailManager::class);

            return new ConfiguredTransport(
                $this->app->make(MailTransports::class),
                static fn () => $mail->createSymfonyTransport(['transport' => 'log', 'channel' => config('mail.mailers.log.channel')]),
            );
        });

        // Client settings: whether e-mail notifications can actually go out.
        $this->app->make(ClientSettings::class)->emailDeliveryUsing(function (Client $client): bool {
            $companyId = Vendor::query()->withoutGlobalScopes()->whereKey($client->vendor_id)->value('company_id');

            return $companyId !== null && $this->app->make(MailTransports::class)->configuredFor((int) $companyId);
        });

        // Every module's "a client should hear about this" lands in the feed.
        Event::listen(ClientNotice::class, fn (ClientNotice $notice) => $this->app->make(ClientNotifications::class)->record($notice));

        // Someone joined or was added: the Welcome Invitation.
        Event::listen(ParticipantAdded::class, function (ParticipantAdded $added): void {
            $event = EventModel::query()->withoutGlobalScopes()->find($added->participant->event_id);
            if ($event !== null) {
                $this->app->make(NoticeTemplates::class)->welcome($event, $added->participant);
            }
        });

        // Events shows message history (RSVP details, guest profile, analytics) and invitations sent.
        $this->app->make(MessageLog::class)->readUsing(
            fn (array $participantIds): array => $this->app->make(MessageCampaigns::class)
                ->log(BroadcastRecipient::query()->whereIn('participant_id', $participantIds)),
            fn (int $hostId): array => $this->app->make(MessageCampaigns::class)
                ->log(BroadcastRecipient::query()->whereIn('event_id', EventModel::query()->withoutGlobalScopes()->where('client_id', $hostId)->select('id'))),
        );
        EventReader::countInvitationsUsing(static fn (array $eventIds): array => BroadcastRecipient::query()
            ->whereIn('event_id', $eventIds)->where('kind', 'invite')->whereIn('status', ['sent', 'delivered'])
            ->where(static fn (Builder $q) => $q->whereNull('broadcast_id')
                ->orWhereIn('broadcast_id', Broadcast::query()->withTrashed()->where('source', Broadcast::MESSAGE)->select('id')))
            ->groupBy('event_id')->selectRaw('event_id, COUNT(*) as total')->pluck('total', 'event_id')
            ->map(static fn ($n): int => (int) $n)->all());

        // Mail and chat: one set of routes for three portals; and the chat relay's own endpoints.
        Route::prefix('api/v1')->middleware(['api', PortalFromHeader::class])->group($this->modulePath('routes/shared.php'));
        Route::prefix('api/internal/chat')->middleware('api')->group(static function (): void {
            Route::post('join', [ChatController::class, 'relayJoin']);
            Route::post('send', [ChatController::class, 'relaySend']);
            Route::post('read', [ChatController::class, 'relayRead']);
        });

        // Scheduled messages and notifications, every minute (v1 node-cron).
        $this->commands([SendDueBroadcasts::class]);
        $this->callAfterResolving(Schedule::class, static function (Schedule $schedule): void {
            $schedule->command('messaging:send-due')->everyMinute()->withoutOverlapping()->onOneServer();
        });
    }
}
