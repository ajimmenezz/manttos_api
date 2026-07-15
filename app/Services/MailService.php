<?php

namespace App\Services;

use App\Models\AppSetting;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

class MailService
{
    public static function isConfigured(): bool
    {
        $host = AppSetting::allAsMap()['smtp_host'] ?? null;
        return !empty($host);
    }

    /** Tope diario de envíos configurado (0 = sin límite). */
    public static function dailyLimit(): int
    {
        return (int) (AppSetting::allAsMap()['mail_daily_limit'] ?? 0);
    }

    /** Correos enviados hoy (para el contador y el tope). */
    public static function sentToday(): int
    {
        return DB::table('mail_send_logs')->whereDate('created_at', now()->toDateString())->count();
    }

    /** ¿Ya se alcanzó el tope diario? */
    public static function dailyLimitReached(): bool
    {
        $limit = self::dailyLimit();
        return $limit > 0 && self::sentToday() >= $limit;
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

        // Tope diario: si se alcanzó, no se envía (evita spam/bloqueos); el flujo que
        // llama puede ofrecer el enlace de acceso para compartirlo por otro medio.
        if (self::dailyLimitReached()) {
            return [
                'sent'      => false,
                'throttled' => true,
                'preview'   => self::renderPreview($mailable, $toEmail),
            ];
        }

        self::configure();

        try {
            Mail::to($toEmail, $toName ?: $toEmail)->send($mailable);
            self::logSent($mailable, $toEmail);
            return ['sent' => true];
        } catch (\Throwable $e) {
            return [
                'sent'    => false,
                'error'   => $e->getMessage(),
                'preview' => self::renderPreview($mailable, $toEmail),
            ];
        }
    }

    /** Registra un envío exitoso para el contador/tope diario (no debe romper el envío). */
    private static function logSent(Mailable $mailable, string $toEmail): void
    {
        try {
            $subject = null;
            try { $subject = $mailable->envelope()->subject; } catch (\Throwable) {}
            DB::table('mail_send_logs')->insert([
                'to_email'   => $toEmail,
                'subject'    => $subject,
                'mailable'   => class_basename($mailable),
                'created_at' => now(),
            ]);
        } catch (\Throwable) {
            // La bitácora es best-effort.
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
