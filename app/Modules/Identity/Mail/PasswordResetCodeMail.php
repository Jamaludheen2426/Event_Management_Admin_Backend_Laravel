<?php

declare(strict_types=1);

namespace App\Modules\Identity\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * The admin reset code. Plain built-in text for now; when the email-template
 * module is ported it renders the company's "password_reset_otp" template.
 */
final class PasswordResetCodeMail extends Mailable
{
    public function __construct(
        public readonly string $userName,
        public readonly string $code,
        public readonly int $minutes,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Your password reset code');
    }

    public function content(): Content
    {
        $name = e($this->userName);

        return new Content(htmlString: "<p>Hello {$name},</p>"
            ."<p>Your password reset code is <strong>{$this->code}</strong>.</p>"
            ."<p>It expires in {$this->minutes} minutes. If you did not ask for it, ignore this email.</p>");
    }
}
