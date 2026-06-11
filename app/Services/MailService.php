<?php

namespace App\Services;

use App\Models\AppSetting;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Mail;

class MailService
{
    public static function isConfigured(): bool
    {
        $host = AppSetting::allAsMap()['smtp_host'] ?? null;
        return !empty($host);
    }

    /** Aplica la configuración SMTP de la BD al mailer en tiempo de ejecución */
    public static function configure(): void
    {
        $s = AppSetting::allAsMap();

        $enc = $s['smtp_encryption'] ?? 'tls';

        config([
            'mail.default'                 => 'smtp',
            'mail.mailers.smtp.host'       => $s['smtp_host'],
            'mail.mailers.smtp.port'       => (int) ($s['smtp_port'] ?? 587),
            'mail.mailers.smtp.encryption' => $enc === 'none' ? null : $enc,
            'mail.mailers.smtp.username'   => $s['smtp_username'] ?? '',
            'mail.mailers.smtp.password'   => $s['smtp_password'] ?? '',
            'mail.from.address'            => $s['smtp_from_email'] ?? config('mail.from.address'),
            'mail.from.name'               => $s['smtp_from_name']  ?? config('mail.from.name'),
        ]);
    }

    /**
     * Intenta enviar un Mailable.
     * Devuelve ['sent' => true] o ['sent' => false, 'preview' => [...]]
     */
    public static function send(Mailable $mailable, string $toEmail, string $toName = ''): array
    {
        if (!self::isConfigured()) {
            return ['sent' => false, 'preview' => self::renderPreview($mailable, $toEmail)];
        }

        self::configure();

        try {
            Mail::to($toEmail, $toName ?: $toEmail)->send($mailable);
            return ['sent' => true];
        } catch (\Throwable $e) {
            return [
                'sent'    => false,
                'error'   => $e->getMessage(),
                'preview' => self::renderPreview($mailable, $toEmail),
            ];
        }
    }

    private static function renderPreview(Mailable $mailable, string $toEmail): array
    {
        // Laravel 12 usa envelope()/content() — build() ya no existe
        $envelope = $mailable->envelope();
        $content  = $mailable->content();

        $viewName = $content->view ?? '';

        // Las propiedades públicas del Mailable se pasan automáticamente a la vista
        $viewData = [];
        foreach ((new \ReflectionClass($mailable))->getProperties(\ReflectionProperty::IS_PUBLIC) as $prop) {
            $viewData[$prop->getName()] = $prop->getValue($mailable);
        }
        // Merge con cualquier dato explícito en content()->with
        $viewData = array_merge($viewData, $content->with ?? []);

        $html = view($viewName, $viewData)->render();

        // Convertir a texto plano legible para copiar/pegar
        $text = html_entity_decode(trim(preg_replace(
            ['/[ \t]+/', '/(\n){3,}/'],
            [' ', "\n\n"],
            strip_tags(str_replace(
                ['<br>', '<br/>', '<br />', '</p>', '</tr>', '</li>'],
                "\n",
                $html
            ))
        )), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return [
            'to'      => $toEmail,
            'subject' => $envelope->subject ?? '',
            'body'    => $text,
            'html'    => $html,
        ];
    }
}
