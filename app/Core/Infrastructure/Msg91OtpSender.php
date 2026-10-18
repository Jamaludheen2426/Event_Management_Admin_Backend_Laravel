<?php

declare(strict_types=1);

namespace App\Core\Infrastructure;

use App\Core\Contracts\OtpSender;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * One-time codes through MSG91 (v1 msg91Whatsapp + msg91Sms): WhatsApp and,
 * when switched on, SMS, sent together. Delivered if either got through; the
 * reason reported is WhatsApp's (v1).
 *
 * Switches (v1 env names): MSG91_OTP_ENABLED (WhatsApp, on by default),
 * MSG91_SMS_OTP_ENABLED (SMS, off by default). "disabled" / "not_configured"
 * let the caller show the code locally instead.
 */
final class Msg91OtpSender implements OtpSender
{
    private const WHATSAPP_URL = 'https://api.msg91.com/api/v5/whatsapp/whatsapp-outbound-message/bulk/';

    private const SMS_URL = 'https://control.msg91.com/api/v5/flow/';

    public function send(string $dialCode, string $mobile, string $code, string $purpose): array
    {
        $to = $this->number($dialCode, $mobile);
        $whatsapp = $this->whatsapp($to, $code, $purpose);
        $sms = $this->sms($to, $code, $purpose);
        if (! $whatsapp['delivered'] && ! $sms['delivered'] && ! app()->isProduction()) {
            Log::info("[OTP] {$purpose} code for {$dialCode}{$mobile}: {$code} (NOT SENT — {$whatsapp['reason']})");
        }

        return ['delivered' => $whatsapp['delivered'] || $sms['delivered'], 'reason' => $whatsapp['delivered'] || $sms['delivered'] ? null : $whatsapp['reason']];
    }

    /** International digits: a national number gets the dial code (91 by default). */
    private function number(string $dialCode, string $mobile): string
    {
        $local = (string) preg_replace('/\D/', '', $mobile);
        if ($local === '') {
            return '';
        }
        $cc = (string) preg_replace('/\D/', '', $dialCode) ?: '91';

        return strlen($local) > 10 ? $local : $cc.$local;
    }

    /** @return array{delivered: bool, reason: string|null} */
    private function whatsapp(string $to, string $code, string $purpose): array
    {
        $c = (array) config('services.msg91');
        if (! ($c['whatsapp_enabled'] ?? true)) {
            return ['delivered' => false, 'reason' => 'disabled'];
        }
        $number = (string) preg_replace('/\D/', '', (string) ($c['whatsapp_number'] ?? ''));
        if (($c['auth_key'] ?? '') === '' || ($c['otp_template'] ?? '') === '' || $number === '') {
            return ['delivered' => false, 'reason' => 'not_configured'];
        }
        if ($to === '') {
            return ['delivered' => false, 'reason' => 'no_number'];
        }
        $components = ['body_1' => ['type' => 'text', 'value' => $code]];
        if ($c['otp_button'] ?? true) {
            $components['button_1'] = ['subtype' => 'url', 'type' => 'text', 'value' => $code];
        }
        $template = [
            'name' => $c['otp_template'],
            'language' => ['code' => $c['whatsapp_language'] ?? 'en', 'policy' => 'deterministic'],
            ...(($c['whatsapp_namespace'] ?? '') !== '' ? ['namespace' => $c['whatsapp_namespace']] : []),
            'to_and_components' => [['to' => [$to], 'components' => $components]],
        ];

        return $this->post(self::WHATSAPP_URL, (string) $c['auth_key'], [
            'integrated_number' => $number,
            'content_type' => 'template',
            'payload' => ['messaging_product' => 'whatsapp', 'type' => 'template', 'template' => $template],
        ], "WhatsApp {$purpose} OTP to {$to}");
    }

    /** @return array{delivered: bool, reason: string|null} */
    private function sms(string $to, string $code, string $purpose): array
    {
        $c = (array) config('services.msg91');
        if (! ($c['sms_enabled'] ?? false)) {
            return ['delivered' => false, 'reason' => 'disabled'];
        }
        $key = (string) (($c['sms_auth_key'] ?? '') ?: ($c['auth_key'] ?? ''));
        if ($key === '' || ($c['sms_flow_id'] ?? '') === '') {
            return ['delivered' => false, 'reason' => 'not_configured'];
        }
        if ($to === '') {
            return ['delivered' => false, 'reason' => 'no_number'];
        }
        $recipient = ['mobiles' => $to, ($c['sms_var'] ?? 'OTP') => $code];
        if (($c['sms_app_hash_var'] ?? '') !== '' && ($c['sms_app_hash'] ?? '') !== '') {
            $recipient[$c['sms_app_hash_var']] = $c['sms_app_hash'];
        }

        return $this->post(self::SMS_URL, $key, ['flow_id' => $c['sms_flow_id'], 'recipients' => [$recipient]], "SMS {$purpose} OTP to {$to}");
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array{delivered: bool, reason: string|null}
     */
    private function post(string $url, string $authKey, array $body, string $what): array
    {
        try {
            $response = Http::timeout(10)->withHeaders(['authkey' => $authKey])->asJson()->post($url, $body);
        } catch (Throwable $e) {
            Log::error("[MSG91] {$what} FAILED: ".$e->getMessage());

            return ['delivered' => false, 'reason' => str_contains($e->getMessage(), 'timed out') ? 'timeout' : 'network'];
        }
        if (! $response->successful() || $response->json('type') === 'error') {
            Log::error("[MSG91] {$what} REFUSED (http {$response->status()}): ".$response->body());

            return ['delivered' => false, 'reason' => (string) ($response->json('message') ?? 'http_'.$response->status())];
        }

        return ['delivered' => true, 'reason' => null];
    }
}
