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

    // ── Búsqueda / metadatos / acceso completo ────────────────────────

    /** Búsqueda por JQL. Proyección legible; para control total usa raw(). */
    public function search(string $jql, array $fields = [], int $max = 20): array
    {
        $body = ['jql' => $jql, 'maxResults' => $max];
        if (! empty($fields)) {
            $body['fields'] = $fields;
        }
        $data = $this->post('/rest/api/2/search', $body);
        return array_map(fn ($i) => [
            'key'      => $i['key'] ?? null,
            'summary'  => $i['fields']['summary'] ?? null,
            'status'   => $i['fields']['status']['name'] ?? null,
            'assignee' => $i['fields']['assignee']['displayName'] ?? null,
            'url'      => $this->browseUrl($i['key'] ?? ''),
        ], $data['issues'] ?? []);
    }

    /** Crea un issue con campos arbitrarios (control total del objeto fields). */
    public function createIssueRaw(array $fields): array
    {
        $res = $this->post('/rest/api/2/issue', ['fields' => $fields]);
        return ['key' => $res['key'] ?? null, 'id' => $res['id'] ?? null, 'url' => $this->browseUrl($res['key'] ?? '')];
    }

    /** Actualiza campos de un issue (PUT /issue/{key}). */
    public function updateIssue(string $key, array $fields): array
    {
        $this->put("/rest/api/2/issue/{$key}", ['fields' => $fields]);
        return ['ok' => true, 'url' => $this->browseUrl($key)];
    }

    /** Asigna un issue por accountId. */
    public function assignIssue(string $key, string $accountId): array
    {
        $this->put("/rest/api/2/issue/{$key}/assignee", ['accountId' => $accountId]);
        return ['ok' => true, 'url' => $this->browseUrl($key)];
    }

    public function users(string $query, int $max = 20): array
    {
        $data = $this->get('/rest/api/2/user/search', ['query' => $query, 'maxResults' => $max]);
        return array_map(fn ($u) => [
            'account_id' => $u['accountId'] ?? null,
            'name'       => $u['displayName'] ?? null,
            'email'      => $u['emailAddress'] ?? null,
            'active'     => $u['active'] ?? null,
        ], is_array($data) ? $data : []);
    }

    public function priorities(): array
    {
        $data = $this->get('/rest/api/2/priority');
        return array_map(fn ($p) => ['id' => $p['id'] ?? null, 'name' => $p['name'] ?? null], is_array($data) ? $data : []);
    }

    public function statuses(): array
    {
        $data = $this->get('/rest/api/2/status');
        return array_map(fn ($s) => ['id' => $s['id'] ?? null, 'name' => $s['name'] ?? null, 'category' => $s['statusCategory']['name'] ?? null], is_array($data) ? $data : []);
    }

    public function fieldsList(): array
    {
        $data = $this->get('/rest/api/2/field');
        return array_map(fn ($f) => ['id' => $f['id'] ?? null, 'name' => $f['name'] ?? null, 'custom' => $f['custom'] ?? null], is_array($data) ? $data : []);
    }

    public function comments(string $key): array
    {
        $data = $this->get("/rest/api/2/issue/{$key}/comment");
        return array_map(fn ($c) => [
            'author'  => $c['author']['displayName'] ?? null,
            'body'    => $c['body'] ?? null,
            'created' => $c['created'] ?? null,
        ], $data['comments'] ?? []);
    }

    /**
     * Petición CRUDA a cualquier endpoint de la API de Jira. Acceso total.
     *
     * @param  array<string,mixed>|null  $body
     * @return array<string,mixed>
     */
    public function raw(string $method, string $path, ?array $body = null): array
    {
        $http = $this->http();
        $path = '/' . ltrim($path, '/');
        $res = match (strtoupper($method)) {
            'GET'    => $http->get($path),
            'DELETE' => $http->delete($path),
            'PUT'    => $http->put($path, $body ?? []),
            'PATCH'  => $http->patch($path, $body ?? []),
            default  => $http->post($path, $body ?? []),
        };
        return $this->handle($res);
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

    private function put(string $path, array $body): array
    {
        return $this->handle($this->http()->put($path, $body));
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
