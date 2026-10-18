<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Support;

use App\Core\Exceptions\ApiException;
use App\Core\Tenancy\CompanyScope;
use App\Core\Tenancy\CurrentCompany;
use App\Modules\Messaging\Models\EmailConfig;
use Symfony\Component\Mailer\Transport\SendmailTransport;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mailer\Transport\Smtp\Stream\SocketStream;
use Symfony\Component\Mailer\Transport\TransportInterface;

/**
 * Turns an e-mail config row into a Symfony transport (v1 createTransporter)
 * and finds the config that sends the platform's e-mail.
 */
final class MailTransports
{
    public function __construct(private readonly CurrentCompany $company) {}

    /**
     * The current company's default active config; outside a company (a
     * signed-out "forgot password"), the first company's (v1: any default).
     */
    public function active(): ?EmailConfig
    {
        $query = EmailConfig::query()->withoutGlobalScope(CompanyScope::class)
            ->where('is_default', true)->where('is_active', 1);
        if ($this->company->has()) {
            $own = (clone $query)->where('company_id', $this->company->id())->first();
            if ($own !== null) {
                return $own;
            }
        }

        return $query->orderBy('company_id')->orderBy('id')->first();
    }

    /** Whether a company has an e-mail provider switched on. */
    public function configuredFor(int $companyId): bool
    {
        return EmailConfig::query()->withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $companyId)->where('is_default', true)->where('is_active', 1)->exists();
    }

    public function for(EmailConfig $config): TransportInterface
    {
        return match ($config->driver) {
            'smtp' => $this->smtp((string) $config->host, (int) $config->port, $config->encryption, $config->username, $config->password),
            // Brevo's free SMTP relay: the login e-mail and the SMTP key.
            'brevo' => $this->smtp('smtp-relay.brevo.com', 587, 'tls', $config->username, $config->api_key ?: $config->password),
            'sendmail' => new SendmailTransport('/usr/sbin/sendmail -bs'),
            default => throw ApiException::badRequest("Unsupported email driver: {$config->driver}"),
        };
    }

    private function smtp(string $host, int $port, ?string $encryption, ?string $username, ?string $password): EsmtpTransport
    {
        // ssl = TLS from the first byte (465); tls = STARTTLS; none = plain.
        $transport = new EsmtpTransport($host, $port, $encryption === 'ssl');
        if ($encryption === 'none') {
            $transport->setAutoTls(false);
        }
        if ($encryption === 'tls') {
            // v1 tls: { rejectUnauthorized: false } — shared hosts often present
            // a certificate for their own name, not the customer's domain.
            $stream = $transport->getStream();
            if ($stream instanceof SocketStream) {
                $stream->setStreamOptions(['ssl' => ['verify_peer' => false, 'verify_peer_name' => false]]);
            }
        }
        if ($username !== null && $username !== '') {
            $transport->setUsername($username);
            $transport->setPassword((string) $password);
        }

        return $transport;
    }
}
