<?php

declare(strict_types=1);

namespace App\Modules\Identity\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** v1 templates "account_activated" / "account_deactivated". */
final class AccountStatusMail extends Mailable
{
    public function __construct(public readonly string $userName, public readonly bool $active) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->active ? 'Your account is active' : 'Your account was deactivated');
    }

    public function content(): Content
    {
        $name = e($this->userName);
        $text = $this->active
            ? 'Your account has been activated. You can now sign in.'
            : 'Your account has been deactivated. Contact your administrator if you think this is a mistake.';

        return new Content(htmlString: "<p>Hello {$name},</p><p>{$text}</p>");
    }
}
