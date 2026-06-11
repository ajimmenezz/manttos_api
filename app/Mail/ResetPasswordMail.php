<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class ResetPasswordMail extends Mailable
{
    public function __construct(
        public readonly string $userName,
        public readonly string $resetUrl,
        public readonly int    $expiresMinutes = 60,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Recuperación de contraseña');
    }

    public function content(): Content
    {
        return new Content(view: 'emails.reset-password');
    }
}
