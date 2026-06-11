<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class TempPasswordMail extends Mailable
{
    public function __construct(
        public readonly string $userName,
        public readonly string $userEmail,
        public readonly string $tempPassword,
        public readonly string $loginUrl,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Tus credenciales de acceso');
    }

    public function content(): Content
    {
        return new Content(view: 'emails.temp-password');
    }
}
