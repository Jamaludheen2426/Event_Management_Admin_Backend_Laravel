<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Actions;

use App\Core\Exceptions\ApiException;
use App\Modules\Messaging\Models\EmailConfig;
use App\Modules\Messaging\Models\EmailTemplate;
use App\Modules\Messaging\Support\EmailRenderer;
use App\Modules\Messaging\Support\MailTransports;
use App\Modules\Platform\Models\Setting;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * Using a platform e-mail template (v1 emailTemplate preview / send /
 * getAvailableVariables, emailSender.sendEmail).
 */
final class EmailTemplates
{
    private const SYSTEM_VARIABLES = [
        ['key' => 'user_name', 'description' => 'Username', 'category' => 'user'],
        ['key' => 'full_name', 'description' => 'user full name', 'category' => 'user'],
        ['key' => 'user_email', 'description' => "User's email address", 'category' => 'user'],
        ['key' => 'app_name', 'description' => 'Application name', 'category' => 'system'],
        ['key' => 'app_url', 'description' => 'Application URL', 'category' => 'system'],
        ['key' => 'current_year', 'description' => 'Current year', 'category' => 'system'],
        ['key' => 'current_date', 'description' => 'Current date', 'category' => 'system'],
        ['key' => 'otp_code', 'description' => 'Verification code', 'category' => 'auth'],
        ['key' => 'reset_link', 'description' => 'Password reset link', 'category' => 'auth'],
        ['key' => 'verification_link', 'description' => 'Email verification link', 'category' => 'auth'],
        ['key' => 'support_email', 'description' => 'Support email address', 'category' => 'system'],
        ['key' => 'company_name', 'description' => 'Company name', 'category' => 'system'],
        ['key' => 'company_address', 'description' => 'Company address', 'category' => 'system'],
        ['key' => 'company_phone', 'description' => 'Company phone number', 'category' => 'system'],
    ];

    public function __construct(
        private readonly EmailRenderer $renderer,
        private readonly MailTransports $transports,
    ) {}

    /**
     * @param  array<string, mixed>  $variables
     * @return array{subject: string, body: string, variables: list<string>|null}
     */
    public function preview(EmailTemplate $template, array $variables): array
    {
        return [...$this->renderer->render($template, ['user_name' => 'John Doe', 'user_email' => 'john@example.com', ...$variables]), 'variables' => $template->variables];
    }

    /**
     * Sends through the template's own provider when it names one, else the default.
     *
     * @param  array<string, mixed>  $variables
     * @return array{success: true, to: string|list<string>, subject: string}
     */
    public function send(EmailTemplate $template, mixed $to, array $variables): array
    {
        $recipients = array_values(array_filter(array_map('strval', is_array($to) ? $to : [$to]), static fn (string $a): bool => filter_var($a, FILTER_VALIDATE_EMAIL) !== false));
        if ($recipients === []) {
            throw ApiException::badRequest('Recipient email (to) is required');
        }
        if ((int) $template->is_active !== 1 || $template->type !== 'template') {
            throw ApiException::notFound("Email template \"{$template->slug}\" not found or inactive");
        }
        ['subject' => $subject, 'body' => $body] = $this->renderer->render($template, $variables);

        $config = $template->email_config_id === null ? null : EmailConfig::query()->find($template->email_config_id);
        if ($config !== null) {
            $email = (new Email)->from(new Address($config->from_email, (string) $config->from_name))->subject($subject)->html($body);
            foreach ($recipients as $address) {
                $email->addTo($address);
            }
            $this->transports->for($config)->send($email);
        } else {
            if ($this->transports->active() === null) {
                throw ApiException::badRequest('No email configuration found. Please configure an email provider.');
            }
            Mail::html($body, static fn ($m) => $m->to($recipients)->subject($subject));
        }

        return ['success' => true, 'to' => is_array($to) ? $recipients : $recipients[0], 'subject' => $subject];
    }

    /** @return list<array{key: string, description: string, category: string}> */
    public function variables(): array
    {
        $custom = Setting::query()->where('group', 'email_variables')->where('is_active', true)->orderBy('key')->get(['key', 'value', 'description'])
            ->map(static fn (Setting $s): array => ['key' => $s->key, 'description' => (string) ($s->getAttribute('description') ?: $s->value), 'category' => 'custom'])->all();

        return [...self::SYSTEM_VARIABLES, ...$custom];
    }

    /**
     * Settings → Email "Test with template": sample values for every variable.
     *
     * @return array{subject: string, html: string}
     */
    public function sample(EmailConfig $config, int $templateId): array
    {
        $template = EmailTemplate::query()->find($templateId) ?? throw ApiException::notFound('Email template not found');
        if ((int) $template->is_active !== 1) {
            throw ApiException::badRequest('Selected template is not active');
        }
        $rendered = $this->renderer->render($template, [], sample: true);

        return ['subject' => $rendered['subject'] ?: 'Test Email - Configuration Verified', 'html' => $rendered['body']];
    }
}
