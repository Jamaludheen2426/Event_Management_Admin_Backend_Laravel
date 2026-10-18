<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Actions;

use App\Modules\Messaging\Models\EmailConfig;
use App\Modules\Messaging\Support\MailTransports;
use Closure;
use Symfony\Component\Mailer\Transport\Smtp\SmtpTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Throwable;

/**
 * Settings → Email "Test" and "Debug" (v1 emailSender.testConfig and
 * emailConfig.debugSmtpConnection). Both answer success:false with the
 * reason instead of failing the request, as v1 did.
 */
final class EmailConfigChecks
{
    /** @var (Closure(EmailConfig, int): array{subject: string, html: string})|null */
    private ?Closure $template = null;

    public function __construct(private readonly MailTransports $transports) {}

    /**
     * The e-mail templates (registered later in this module) render the
     * "send using this template" option of the test.
     *
     * @param  Closure(EmailConfig, int): array{subject: string, html: string}  $render
     */
    public function renderTemplatesUsing(Closure $render): void
    {
        $this->template = $render;
    }

    /** @return array{success: bool, message: string} */
    public function test(EmailConfig $config, ?string $testEmail, ?int $templateId): array
    {
        try {
            $transport = $this->transports->for($config);
            if ($transport instanceof SmtpTransport) {
                $transport->start();
            }
            if ($testEmail === null || $testEmail === '') {
                if ($transport instanceof SmtpTransport) {
                    $transport->stop();
                }

                return ['success' => true, 'message' => 'Connection verified successfully'];
            }

            $subject = 'Test Email - Configuration Verified';
            $html = '<p>This is a test email from your <strong>'.e($config->name).'</strong> configuration.</p><p>If you received this, your email settings are working correctly.</p>';
            if ($templateId !== null && $this->template !== null) {
                $rendered = ($this->template)($config, $templateId);
                $subject = '[TEST] '.$rendered['subject'];
                $html = $rendered['html'];
            }

            $transport->send((new Email)
                ->from(new Address($config->from_email, (string) $config->from_name))
                ->to($testEmail)->subject($subject)->html($html));

            return ['success' => true, 'message' => $templateId !== null
                ? "Test email sent successfully to {$testEmail} using template"
                : "Test email sent successfully to {$testEmail}"];
        } catch (Throwable $e) {
            report($e);

            return ['success' => false, 'message' => $e->getMessage() ?: 'Connection failed'];
        }
    }

    /**
     * DNS, then a socket to the configured port, then (if that fails) the
     * usual alternatives — tells "wrong password" apart from "port blocked".
     *
     * @return array<string, mixed>
     */
    public function debug(EmailConfig $config): array
    {
        $host = (string) $config->host;
        $port = (int) $config->port;
        $results = [
            'config' => ['name' => $config->name, 'host' => $config->host, 'port' => $config->port, 'driver' => $config->driver, 'encryption' => $config->encryption],
            'tests' => [],
        ];

        $addresses = $host === '' ? false : gethostbynamel($host);
        if ($addresses === false) {
            $results['tests'][] = ['name' => 'DNS Resolution', 'success' => false, 'message' => "Could not resolve {$host}"];

            return $results;
        }
        $results['tests'][] = ['name' => 'DNS Resolution', 'success' => true, 'message' => 'Resolved to: '.implode(', ', $addresses)];

        $secure = $config->encryption === 'ssl' || $port === 465;
        $error = $this->connect($host, $port, $secure, 15);
        $results['tests'][] = $error === null
            ? ['name' => 'Socket Connection', 'success' => true, 'message' => "Connected to {$host}:{$port} successfully"]
            : ['name' => 'Socket Connection', 'success' => false, 'message' => "Failed to connect: {$error}"];

        if ($error !== null) {
            foreach ([587, 25, 2525] as $alternative) {
                if ($alternative !== $port && $this->connect($host, $alternative, false, 5) === null) {
                    $results['tests'][] = ['name' => "Alternative Port {$alternative}", 'success' => true, 'message' => "Port {$alternative} is reachable - consider using this instead"];
                }
            }
        }

        $allPassed = collect($results['tests'])->every(static fn (array $t): bool => $t['success']);
        $results['summary'] = $allPassed
            ? 'All connectivity tests passed. The issue may be with authentication or SMTP protocol.'
            : 'Network connectivity issues detected. The host may be blocking outbound SMTP ports.';

        return $results;
    }

    /** null when the port answered, else the reason. */
    private function connect(string $host, int $port, bool $secure, int $timeout): ?string
    {
        $context = stream_context_create(['ssl' => ['verify_peer' => false, 'verify_peer_name' => false]]);
        $socket = @stream_socket_client(($secure ? 'ssl' : 'tcp')."://{$host}:{$port}", $code, $message, $timeout, STREAM_CLIENT_CONNECT, $context);
        if ($socket === false) {
            return $message !== '' ? $message : "Connection timed out after {$timeout}s - port may be blocked";
        }
        fclose($socket);

        return null;
    }
}
