<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Support;

use Closure;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;

/**
 * The "configured" mailer: every e-mail goes out through the provider the
 * admin set as default in Settings → Email (v1 read email_configs on every
 * send), from that config's sender. With none configured, the e-mail is
 * written to the log instead, so a missing provider never breaks a sign-in.
 */
final class ConfiguredTransport implements TransportInterface
{
    /** @param Closure(): TransportInterface $fallback */
    public function __construct(
        private readonly MailTransports $transports,
        private readonly Closure $fallback,
    ) {}

    public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
    {
        $config = $this->transports->active();
        if ($config === null) {
            Log::warning('[mail] No email configuration is active; the e-mail was logged, not sent.');

            return ($this->fallback)()->send($message, $envelope);
        }

        if ($message instanceof Email) {
            $message->from(new Address($config->from_email, (string) $config->from_name));
            $envelope = null;
        }

        return $this->transports->for($config)->send($message, $envelope);
    }

    public function __toString(): string
    {
        return 'configured';
    }
}
