<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Support;

use App\Core\Tenancy\CompanyScope;
use App\Modules\Messaging\Models\EmailTemplate;
use App\Modules\Platform\Models\Setting;

/**
 * Turns an e-mail template into subject + HTML (v1 emailSender buildFullBody
 * and replaceVariables): its header and footer parts around the body, inside
 * the platform's base layout, with {{variables}} filled — the company's
 * settings first, the caller's values over them.
 */
final class EmailRenderer
{
    /**
     * @param  array<string, mixed>  $variables
     * @return array{subject: string, body: string}
     */
    public function render(EmailTemplate $template, array $variables = [], bool $sample = false): array
    {
        $values = [...$this->defaults($template->company_id), ...$variables];
        if ($sample) {
            foreach ($template->variables ?? [] as $name) {
                $values[$name] ??= "[Sample {$name}]";
            }
        }

        return [
            'subject' => self::fill((string) $template->subject, $values),
            'body' => self::fill($this->fullBody($template), $values),
        ];
    }

    /** Header + body + footer (active parts only), in the base layout. */
    public function fullBody(EmailTemplate $template): string
    {
        $parts = EmailTemplate::query()->withoutGlobalScope(CompanyScope::class)->where('company_id', $template->company_id)
            ->whereKey(array_filter([$template->header_id, $template->footer_id]))->where('is_active', 1)->get()->keyBy('id');
        $content = implode("\n", array_filter([
            $template->header_id !== null ? $parts->get($template->header_id)?->body : null,
            (string) $template->body,
            $template->footer_id !== null ? $parts->get($template->footer_id)?->body : null,
        ], static fn ($p): bool => $p !== null));

        return str_replace('@@CONTENT@@', $content, (string) file_get_contents(__DIR__.'/../Resources/email-base.html'));
    }

    /**
     * {{name}} placeholders; ones without a value stay as written (v1).
     *
     * @param  array<string, mixed>  $values
     */
    public static function fill(string $text, array $values): string
    {
        foreach ($values as $key => $value) {
            $text = str_replace('{{'.$key.'}}', is_scalar($value) ? (string) $value : '', $text);
        }

        return $text;
    }

    /** @return list<string> the {{names}} a subject and body use */
    public static function variablesIn(?string $subject, ?string $body): array
    {
        preg_match_all('/\{\{(\w+)\}\}/', "{$subject} {$body}", $m);

        return array_values(array_unique($m[1]));
    }

    /** @return array<string, string|int> */
    private function defaults(int $companyId): array
    {
        $settings = Setting::query()->withoutGlobalScope(CompanyScope::class)->where('company_id', $companyId)->where('is_active', true)
            ->whereIn('key', ['admin_title', 'support_email', 'company_name', 'company_address', 'company_phone', 'site_url'])
            ->pluck('value', 'key');

        return [
            'app_name' => (string) ($settings['admin_title'] ?? config('app.name')),
            'support_email' => (string) ($settings['support_email'] ?? 'support@example.com'),
            'company_name' => (string) ($settings['company_name'] ?? ''),
            'company_address' => (string) ($settings['company_address'] ?? ''),
            'company_phone' => (string) ($settings['company_phone'] ?? ''),
            'app_url' => (string) ($settings['site_url'] ?? config('app.url')),
            'current_year' => (int) now()->format('Y'),
        ];
    }
}
