<?php

namespace App\Support\Integrations\Providers;

use App\Models\Integration;
use App\Models\IntegrationLink;
use App\Services\Integrations\Jira\JiraClient;
use App\Support\Integrations\AbstractIntegrationProvider;
use Illuminate\Support\Str;

/**
 * Conector REAL a Jira Service Management: nuestros eventos → tickets (issues), y los
 * cambios posteriores (comentarios, estado, asignación) actualizan el MISMO ticket usando
 * el vínculo guardado en integration_links.
 *
 * - Crea el issue con project_key + issue_type (API v2) o, si se configuran service_desk_id
 *   + request_type_id, como solicitud de cliente (servicedeskapi).
 * - `auto_create_on_events` controla si los eventos crean tickets automáticamente. Aunque
 *   esté apagado, el "probador" (supportedActions/runAction) permite disparar acciones a
 *   mano y verlas ejecutarse en Jira.
 */
class JiraServiceManagementProvider extends AbstractIntegrationProvider
{
    public function key(): string { return 'jira'; }
    public function label(): string { return 'Jira Service Management'; }
    public function description(): string { return 'Convierte eventos en tickets (issues) de Jira Service Management y sincroniza comentarios y estado.'; }

    public function configSchema(): array
    {
        return [
            ['key' => 'base_url',        'label' => 'URL de Jira',            'type' => 'url',     'required' => true,  'secret' => false, 'placeholder' => 'https://tu-org.atlassian.net', 'help' => 'La base de tu instancia de Jira Cloud (sin / al final).'],
            ['key' => 'email',           'label' => 'Correo de la cuenta',    'type' => 'email',   'required' => true,  'secret' => false, 'placeholder' => 'integraciones@tu-org.com', 'help' => 'El correo de la cuenta de Atlassian dueña del API token.'],
            ['key' => 'api_token',       'label' => 'API token',              'type' => 'password','required' => true,  'secret' => true,  'help' => 'Token de API de Atlassian. Míralo en “¿Cómo consigo estos datos?”.'],
            ['key' => 'project_key',     'label' => 'Clave del proyecto',     'type' => 'text',    'required' => true,  'secret' => false, 'placeholder' => 'SUP', 'help' => 'Proyecto donde se crean los tickets. Usa “Listar proyectos” en el probador si no la sabes.'],
            ['key' => 'issue_type',      'label' => 'Tipo de issue',          'type' => 'text',    'required' => false, 'secret' => false, 'placeholder' => 'Task', 'help' => 'Tipo por defecto (p. ej. Task, Incident, Service Request). Vacío = “Task”.'],
            ['key' => 'service_desk_id', 'label' => 'ID del service desk',    'type' => 'text',    'required' => false, 'secret' => false, 'placeholder' => '(opcional)', 'help' => 'Solo si quieres crear SOLICITUDES de cliente (JSM) en vez de issues. Vacío = issues normales.'],
            ['key' => 'request_type_id', 'label' => 'ID del tipo de solicitud','type' => 'text',   'required' => false, 'secret' => false, 'placeholder' => '(opcional)', 'help' => 'Requerido junto al service desk para crear solicitudes de cliente.'],
            ['key' => 'auto_create_on_events', 'label' => 'Crear tickets automáticamente al ocurrir eventos', 'type' => 'boolean', 'required' => false, 'secret' => false, 'help' => 'Si lo apagas, los eventos NO crean tickets solos; úsalo con el probador para ensayar primero.'],
        ];
    }

    public function subscribedEvents(): array
    {
        return [
            'event.created', 'event.updated', 'event.status_changed',
            'event.assigned', 'event.comment_added',
        ];
    }

    // ── Salida real: evento → ticket ──────────────────────────────────

    public function handleEvent(string $eventType, array $data, Integration $config): array
    {
        // Sin auto-creación, no tocamos Jira automáticamente (el probador sigue disponible).
        if (! filter_var($config->conf('auto_create_on_events'), FILTER_VALIDATE_BOOL)) {
            return ['status' => 'skipped', 'note' => 'auto_create_on_events apagado; usa el probador para ensayar.'];
        }

        $eventId = $data['id'] ?? null;
        if (! $eventId) {
            return ['status' => 'skipped', 'note' => 'Evento sin id.'];
        }

        $client = new JiraClient($config);
        $link = IntegrationLink::where('integration_id', $config->id)
            ->where('local_type', 'event')->where('local_id', $eventId)->first();

        // Aún no existe el ticket: créalo en el primer evento que lo amerite.
        if (! $link) {
            $created = $this->createTicket($client, $config, $data);
            IntegrationLink::updateOrCreate(
                ['integration_id' => $config->id, 'local_type' => 'event', 'local_id' => $eventId],
                ['provider' => 'jira', 'external_key' => $created['key'], 'external_id' => $created['id'], 'external_url' => $created['url']],
            );
            return ['status' => 'created', 'issue' => $created['key'], 'url' => $created['url']];
        }

        // Ya existe: reflejar el cambio en el mismo ticket.
        $key = $link->external_key;
        return match ($eventType) {
            'event.comment_added' => $this->pushComment($client, $key, $this->commentText($data)),
            'event.status_changed' => $this->pushStatus($client, $key, $data),
            'event.assigned' => $this->pushComment($client, $key, 'Asignación actualizada' . (isset($data['assigned_to']['name']) ? ' → ' . $data['assigned_to']['name'] : ' (retirada)') . '.'),
            'event.updated' => $this->pushComment($client, $key, 'El evento se actualizó en Mantenimientos.'),
            default => ['status' => 'ignored', 'issue' => $key],
        };
    }

    private function createTicket(JiraClient $client, Integration $config, array $data): array
    {
        $folio = $data['folio'] ?? ('#' . ($data['id'] ?? '?'));
        $summary = "[{$folio}] " . Str::limit(trim((string) ($data['description'] ?? 'Evento')), 200, '');
        $description = $this->issueDescription($data);

        $sd = trim((string) $config->conf('service_desk_id'));
        $rt = trim((string) $config->conf('request_type_id'));
        if ($sd !== '' && $rt !== '') {
            return $client->createRequest($sd, $rt, $summary, $description);
        }
        return $client->createIssue(
            (string) $config->conf('project_key'),
            (string) ($config->conf('issue_type') ?: 'Task'),
            $summary,
            $description,
        );
    }

    private function pushComment(JiraClient $client, string $key, string $body): array
    {
        $client->addComment($key, $body);
        return ['status' => 'commented', 'issue' => $key, 'url' => $client->browseUrl($key)];
    }

    private function pushStatus(JiraClient $client, string $key, array $data): array
    {
        $label = $data['status']['label'] ?? 'nuevo estado';
        $actor = $data['actor']['name'] ?? 'Sistema';
        $client->addComment($key, "{$actor} cambió el estado a «{$label}» en Mantenimientos.");
        $moved = $client->transitionByName($key, (string) $label); // best-effort
        return ['status' => 'status_synced', 'issue' => $key, 'transitioned' => $moved !== null, 'url' => $client->browseUrl($key)];
    }

    private function commentText(array $data): string
    {
        $author = $data['comment']['author']['name'] ?? ($data['actor']['name'] ?? 'Usuario');
        $body   = $data['comment']['body'] ?? '';
        return trim("{$author}: {$body}");
    }

    private function issueDescription(array $data): string
    {
        $lines = [
            (string) ($data['description'] ?? ''),
            '',
            'Folio: ' . ($data['folio'] ?? '—'),
            'Prioridad: ' . ($data['priority'] ?? '—'),
            'Estado: ' . ($data['status']['label'] ?? '—'),
            'Creado por: ' . ($data['created_by']['name'] ?? '—'),
            'Origen: Mantenimientos Siccob (evento ' . ($data['id'] ?? '?') . ')',
        ];
        return implode("\n", $lines);
    }

    // ── Entrada (bidireccional) ───────────────────────────────────────

    public function handleInbound(array $payload, Integration $config): array
    {
        // Jira envía webhooks del issue; aquí se mapearía el estado de vuelta al evento.
        // TODO(integración real): actualizar el evento local según payload['issue'].
        return ['status' => 'received', 'issue' => $payload['issue']['key'] ?? null, 'note' => 'Entrada registrada (mapeo de vuelta pendiente).'];
    }

    // ── Prueba de conexión ────────────────────────────────────────────

    public function testConnection(Integration $config): array
    {
        if (! $this->isConfigured($config)) {
            return ['ok' => false, 'message' => 'Faltan datos de configuración.'];
        }
        try {
            $me = (new JiraClient($config))->myself();
            return ['ok' => true, 'message' => 'Conexión correcta como ' . ($me['displayName'] ?? 'usuario') . '.'];
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }

    // ── Probador de acciones ──────────────────────────────────────────

    public function supportedActions(): array
    {
        return [
            ['key' => 'ping',               'label' => 'Probar conexión',            'params' => []],
            ['key' => 'list_projects',      'label' => 'Listar proyectos',           'description' => 'Para descubrir tu clave de proyecto.', 'params' => []],
            ['key' => 'list_issue_types',   'label' => 'Listar tipos de issue',      'params' => []],
            ['key' => 'list_service_desks', 'label' => 'Listar service desks (JSM)',  'params' => []],
            ['key' => 'list_request_types', 'label' => 'Listar tipos de solicitud',  'params' => [['key' => 'service_desk_id', 'label' => 'ID del service desk', 'required' => true]]],
            ['key' => 'create_issue',       'label' => 'Crear ticket de prueba',     'description' => 'Crea un issue real en tu proyecto.', 'params' => [['key' => 'summary', 'label' => 'Resumen', 'required' => true], ['key' => 'description', 'label' => 'Descripción', 'type' => 'textarea']]],
            ['key' => 'get_issue',          'label' => 'Ver ticket',                 'params' => [['key' => 'key', 'label' => 'Clave (p. ej. SUP-1)', 'required' => true]]],
            ['key' => 'add_comment',        'label' => 'Comentar ticket',            'params' => [['key' => 'key', 'label' => 'Clave', 'required' => true], ['key' => 'body', 'label' => 'Comentario', 'type' => 'textarea', 'required' => true]]],
            ['key' => 'list_transitions',   'label' => 'Ver transiciones posibles',  'params' => [['key' => 'key', 'label' => 'Clave', 'required' => true]]],
            ['key' => 'transition_issue',   'label' => 'Cambiar estado (transición)', 'params' => [['key' => 'key', 'label' => 'Clave', 'required' => true], ['key' => 'transition_id', 'label' => 'ID de transición', 'required' => true]]],
        ];
    }

    public function runAction(string $action, array $params, Integration $config): array
    {
        if (! $this->isConfigured($config)) {
            return ['ok' => false, 'message' => 'Configura y guarda la conexión antes de probar acciones.'];
        }

        $client = new JiraClient($config);
        try {
            switch ($action) {
                case 'ping':
                    $me = $client->myself();
                    return ['ok' => true, 'message' => 'Conectado como ' . ($me['displayName'] ?? 'usuario') . '.', 'data' => ['account' => $me['displayName'] ?? null, 'email' => $me['emailAddress'] ?? null]];
                case 'list_projects':
                    return ['ok' => true, 'message' => 'Proyectos disponibles.', 'data' => $client->projects()];
                case 'list_issue_types':
                    return ['ok' => true, 'message' => 'Tipos de issue.', 'data' => $client->issueTypes()];
                case 'list_service_desks':
                    return ['ok' => true, 'message' => 'Service desks.', 'data' => $client->serviceDesks()];
                case 'list_request_types':
                    return ['ok' => true, 'message' => 'Tipos de solicitud.', 'data' => $client->requestTypes((string) ($params['service_desk_id'] ?? ''))];
                case 'create_issue':
                    $r = $client->createIssue((string) $config->conf('project_key'), (string) ($config->conf('issue_type') ?: 'Task'), (string) ($params['summary'] ?? 'Ticket de prueba'), (string) ($params['description'] ?? 'Creado desde el probador de Mantenimientos Siccob.'));
                    return ['ok' => true, 'message' => 'Ticket creado: ' . $r['key'], 'data' => $r, 'url' => $r['url']];
                case 'get_issue':
                    $r = $client->getIssue((string) ($params['key'] ?? ''));
                    return ['ok' => true, 'message' => 'Ticket ' . $r['key'] . ' — ' . ($r['status'] ?? ''), 'data' => $r, 'url' => $r['url']];
                case 'add_comment':
                    $r = $client->addComment((string) ($params['key'] ?? ''), (string) ($params['body'] ?? ''));
                    return ['ok' => true, 'message' => 'Comentario agregado.', 'data' => $r, 'url' => $r['url']];
                case 'list_transitions':
                    return ['ok' => true, 'message' => 'Transiciones disponibles.', 'data' => $client->transitions((string) ($params['key'] ?? ''))];
                case 'transition_issue':
                    $r = $client->transition((string) ($params['key'] ?? ''), (string) ($params['transition_id'] ?? ''));
                    return ['ok' => true, 'message' => 'Estado cambiado.', 'data' => $r, 'url' => $r['url']];
                default:
                    return ['ok' => false, 'message' => 'Acción desconocida: ' . $action];
            }
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }
}
