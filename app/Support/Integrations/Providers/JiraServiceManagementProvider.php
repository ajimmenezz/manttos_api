<?php

namespace App\Support\Integrations\Providers;

use App\Models\Integration;
use App\Support\Integrations\AbstractIntegrationProvider;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Conector a Jira Service Management. Propósito: nuestros eventos → tickets (issues).
 *
 * ESTADO: cableado. `testConnection` ya verifica credenciales reales; `handleEvent`
 * PREPARA el payload del issue y lo registra en bitácora, pero todavía NO crea el issue
 * en Jira (para no ensuciar un proyecto real). Cuando quieras activarlo de verdad, el
 * POST va donde está marcado con TODO. Bidireccional: `handleInbound` recibe los cambios
 * de estado de Jira de vuelta (por ahora solo los registra).
 */
class JiraServiceManagementProvider extends AbstractIntegrationProvider
{
    public function key(): string { return 'jira'; }
    public function label(): string { return 'Jira Service Management'; }
    public function description(): string { return 'Convierte eventos en tickets (issues) de Jira Service Management y sincroniza su estado.'; }

    public function configSchema(): array
    {
        return [
            ['key' => 'base_url',     'label' => 'URL de Jira',        'type' => 'url',    'required' => true,  'secret' => false, 'placeholder' => 'https://tu-org.atlassian.net', 'help' => 'La base de tu instancia de Jira Cloud.'],
            ['key' => 'email',        'label' => 'Correo de la cuenta', 'type' => 'email',  'required' => true,  'secret' => false, 'placeholder' => 'integraciones@tu-org.com'],
            ['key' => 'api_token',    'label' => 'API token',          'type' => 'password','required' => true,  'secret' => true,  'help' => 'Token de API de Atlassian (id.atlassian.com → tokens).'],
            ['key' => 'project_key',  'label' => 'Clave del proyecto', 'type' => 'text',   'required' => true,  'secret' => false, 'placeholder' => 'SUP', 'help' => 'Proyecto de servicio donde se crearán los tickets.'],
            ['key' => 'issue_type',   'label' => 'Tipo de issue',      'type' => 'text',   'required' => false, 'secret' => false, 'placeholder' => 'Incidencia', 'help' => 'Tipo por defecto (si se deja vacío, se usa el del proyecto).'],
        ];
    }

    /** Nos interesan los eventos del ciclo de vida de un evento del negocio. */
    public function subscribedEvents(): array
    {
        return [
            'event.created', 'event.updated', 'event.status_changed',
            'event.assigned', 'event.comment_added',
        ];
    }

    public function handleEvent(string $eventType, array $data, Integration $config): array
    {
        // Prepara el issue que se crearía/actualizaría. Aún NO escribe en Jira.
        $issue = $this->buildIssuePayload($eventType, $data, $config);

        // TODO(integración real): crear/actualizar el issue en Jira, p. ej.
        //   $res = $this->client($config)->post('/rest/api/3/issue', $issue);
        //   return ['created' => $res->successful(), 'issue' => $res->json('key')];

        Log::info('[jira] evento preparado (sin enviar todavía)', ['type' => $eventType, 'project' => $config->conf('project_key')]);

        return ['status' => 'prepared', 'would_create' => $issue, 'note' => 'Cableado listo; falta activar el POST real.'];
    }

    public function handleInbound(array $payload, Integration $config): array
    {
        // TODO(integración real): mapear el cambio de estado del issue de Jira a nuestro evento.
        Log::info('[jira] entrada recibida', ['keys' => array_keys($payload)]);
        return ['status' => 'received', 'note' => 'Registrado; el mapeo de vuelta se implementará al integrar.'];
    }

    public function testConnection(Integration $config): array
    {
        if (! $this->isConfigured($config)) {
            return ['ok' => false, 'message' => 'Faltan datos de configuración.'];
        }

        try {
            $res = $this->client($config)->get('/rest/api/3/myself');
            if ($res->successful()) {
                return ['ok' => true, 'message' => 'Conexión correcta como ' . ($res->json('displayName') ?? 'usuario') . '.'];
            }
            return ['ok' => false, 'message' => 'Jira respondió ' . $res->status() . '. Revisa credenciales y URL.'];
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => 'No se pudo conectar: ' . $e->getMessage()];
        }
    }

    /** Cliente HTTP autenticado (basic auth email:token). */
    private function client(Integration $config)
    {
        return Http::withBasicAuth((string) $config->conf('email'), (string) $config->conf('api_token'))
            ->acceptJson()
            ->timeout(10)
            ->baseUrl(rtrim((string) $config->conf('base_url'), '/'));
    }

    /** Arma el cuerpo de un issue a partir de un evento del negocio. */
    private function buildIssuePayload(string $eventType, array $data, Integration $config): array
    {
        $folio = $data['folio'] ?? ('#' . ($data['id'] ?? '?'));
        $summary = match ($eventType) {
            'event.status_changed' => "[{$folio}] Cambio de estado: " . ($data['status']['label'] ?? ''),
            'event.assigned'       => "[{$folio}] Asignación",
            'event.comment_added'  => "[{$folio}] Nuevo comentario",
            default                => "[{$folio}] " . ($data['description'] ?? 'Evento'),
        };

        return [
            'fields' => [
                'project'     => ['key' => $config->conf('project_key')],
                'summary'     => mb_substr($summary, 0, 250),
                'description' => (string) ($data['description'] ?? ''),
                'issuetype'   => ['name' => $config->conf('issue_type') ?: 'Task'],
                // labels/priority/campos custom se mapean al integrar de verdad.
            ],
            '_source' => ['event_type' => $eventType, 'event_id' => $data['id'] ?? null],
        ];
    }
}
