<?php

declare(strict_types=1);

namespace App\Modules\Identity\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** Confirmation after a password change or reset (v1 template "password_changed"). */
final class PasswordChangedMail extends Mailable
{
    public function __construct(public readonly string $userName) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Your password was changed');
    }

    public function content(): Content
    {
        $name = e($this->userName);

        return new Content(htmlString: "<p>Hello {$name},</p>"
            .'<p>Your password was just changed. If this was not you, contact your administrator immediately.</p>');
    }
}
