<?php

namespace App\Services\Integrations\Jira;

use App\Models\Integration;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Cliente de la API de Jira Cloud / Jira Service Management.
 *
 * Usa la REST API v2 para lo del núcleo (cuerpos en texto plano, sin ADF) y la
 * servicedeskapi para lo específico de JSM. Cada método devuelve datos decodificados en
 * éxito o LANZA una \RuntimeException con el mensaje de error real de Jira. Así el
 * probador muestra el motivo y el job de integración reintenta.
 */
class JiraClient
{
    public function __construct(private Integration $config)
    {
    }

    // ── Cuenta / descubrimiento ───────────────────────────────────────

    public function myself(): array
    {
        return $this->get('/rest/api/2/myself');
    }

    /** Proyectos accesibles (para elegir project_key). */
    public function projects(): array
    {
        $data = $this->get('/rest/api/2/project/search', ['maxResults' => 50]);
        return array_map(fn ($p) => [
            'id'   => $p['id'] ?? null,
            'key'  => $p['key'] ?? null,
            'name' => $p['name'] ?? null,
        ], $data['values'] ?? []);
    }

    /** Tipos de issue de la instancia (para elegir issue_type). */
    public function issueTypes(): array
    {
        $data = $this->get('/rest/api/2/issuetype');
        return array_values(array_unique(array_filter(array_map(fn ($t) => $t['name'] ?? null, $data))));
    }

    /** Service desks (JSM). */
    public function serviceDesks(): array
    {
        $data = $this->get('/rest/servicedeskapi/servicedesk');
        return array_map(fn ($d) => [
            'id'         => $d['id'] ?? null,
            'project_key' => $d['projectKey'] ?? null,
            'name'       => $d['projectName'] ?? null,
        ], $data['values'] ?? []);
    }

    /** Tipos de solicitud de un service desk (JSM). */
    public function requestTypes(string $serviceDeskId): array
    {
        $data = $this->get("/rest/servicedeskapi/servicedesk/{$serviceDeskId}/requesttype");
        return array_map(fn ($t) => [
            'id'   => $t['id'] ?? null,
            'name' => $t['name'] ?? null,
        ], $data['values'] ?? []);
    }

    // ── Escritura ─────────────────────────────────────────────────────

    /** Crea un issue del núcleo (project_key + issue_type). Devuelve la clave (SUP-123). */
    public function createIssue(string $projectKey, string $issueType, string $summary, string $description = ''): array
    {
        $res = $this->post('/rest/api/2/issue', [
            'fields' => [
                'project'     => ['key' => $projectKey],
                'summary'     => mb_substr($summary, 0, 250),
                'description' => $description,
                'issuetype'   => ['name' => $issueType],
            ],
        ]);
        return ['key' => $res['key'] ?? null, 'id' => $res['id'] ?? null, 'url' => $this->browseUrl($res['key'] ?? '')];
    }

    /** Crea una solicitud de cliente (JSM). */
    public function createRequest(string $serviceDeskId, string $requestTypeId, string $summary, string $description = ''): array
    {
        $res = $this->post('/rest/servicedeskapi/request', [
            'serviceDeskId'     => $serviceDeskId,
            'requestTypeId'     => $requestTypeId,
            'requestFieldValues' => [
                'summary'     => mb_substr($summary, 0, 250),
                'description' => $description,
            ],
        ]);
        $key = $res['issueKey'] ?? null;
        return ['key' => $key, 'id' => $res['issueId'] ?? null, 'url' => $this->browseUrl($key ?? '')];
    }

    public function getIssue(string $key): array
    {
        $data = $this->get("/rest/api/2/issue/{$key}", ['fields' => 'summary,status,assignee,priority,created,updated']);
        $f = $data['fields'] ?? [];
        return [
            'key'      => $data['key'] ?? $key,
            'summary'  => $f['summary'] ?? null,
            'status'   => $f['status']['name'] ?? null,
            'assignee' => $f['assignee']['displayName'] ?? null,
            'priority' => $f['priority']['name'] ?? null,
            'url'      => $this->browseUrl($key),
        ];
    }

    public function addComment(string $key, string $body): array
    {
        $res = $this->post("/rest/api/2/issue/{$key}/comment", ['body' => $body]);
        return ['id' => $res['id'] ?? null, 'url' => $this->browseUrl($key)];
    }

    /** Transiciones disponibles del issue (id + nombre del estado destino). */
    public function transitions(string $key): array
    {
        $data = $this->get("/rest/api/2/issue/{$key}/transitions");
        return array_map(fn ($t) => [
            'id'   => $t['id'] ?? null,
            'name' => $t['name'] ?? null,
            'to'   => $t['to']['name'] ?? null,
        ], $data['transitions'] ?? []);
    }

    public function transition(string $key, string $transitionId): array
    {
        $this->post("/rest/api/2/issue/{$key}/transitions", ['transition' => ['id' => $transitionId]]);
        return ['ok' => true, 'url' => $this->browseUrl($key)];
    }

    /** Aplica la transición cuyo estado destino coincide (por nombre) con $statusLabel, si existe. */
    public function transitionByName(string $key, string $statusLabel): ?array
    {
        $target = mb_strtolower(trim($statusLabel));
        foreach ($this->transitions($key) as $t) {
            if (mb_strtolower((string) $t['to']) === $target || mb_strtolower((string) $t['name']) === $target) {
                $this->transition($key, (string) $t['id']);
                return $t;
            }
        }
        return null;
    }

    public function browseUrl(string $key): string
    {
        return $key ? rtrim((string) $this->config->conf('base_url'), '/') . '/browse/' . $key : '';
    }

    // ── HTTP interno ──────────────────────────────────────────────────

    private function http(): PendingRequest
    {
        return Http::withBasicAuth((string) $this->config->conf('email'), (string) $this->config->conf('api_token'))
            ->acceptJson()
            ->asJson()
            ->timeout(15)
            ->baseUrl(rtrim((string) $this->config->conf('base_url'), '/'));
    }

    private function get(string $path, array $query = []): array
    {
        return $this->handle($this->http()->get($path, $query));
    }

    private function post(string $path, array $body): array
    {
        return $this->handle($this->http()->post($path, $body));
    }

    private function handle(Response $res): array
    {
        if ($res->successful()) {
            return is_array($res->json()) ? $res->json() : [];
        }
        throw new \RuntimeException($this->errorMessage($res));
    }

    /** Extrae un mensaje legible del cuerpo de error de Jira. */
    private function errorMessage(Response $res): string
    {
        $json = $res->json();
        $parts = [];
        if (is_array($json)) {
            foreach ((array) ($json['errorMessages'] ?? []) as $m) $parts[] = $m;
            foreach ((array) ($json['errors'] ?? []) as $field => $m) $parts[] = "{$field}: {$m}";
            if (! empty($json['errorMessage'])) $parts[] = $json['errorMessage'];
            if (! empty($json['message']))      $parts[] = $json['message'];
        }
        $detail = $parts ? implode(' · ', $parts) : trim(mb_substr($res->body(), 0, 300));
        return "Jira HTTP {$res->status()}" . ($detail ? ": {$detail}" : '.');
    }
}
