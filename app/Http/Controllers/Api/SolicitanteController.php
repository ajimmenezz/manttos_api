<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Site;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password as PasswordBroker;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Gestión de SOLICITANTES (usuarios de portal / autoservicio) CON ALCANCE:
 * superadmin/admin ven todos; admin-cliente los de sus clientes; admin-sitio los de
 * sus sitios. Alta individual + importación masiva, asociando a un cliente o sitio
 * dentro del alcance del administrador. Entrega un enlace de acceso copiable (no
 * depende del correo).
 */
class SolicitanteController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('solicitantes.view'), 403);

        [$clientIds, $siteIds, $all] = $this->scope($request->user());

        $users = User::role('solicitante')
            ->with(['solicitanteClients:id,name,short_name', 'solicitanteSites:id,name'])
            ->when(! $all, fn ($q) => $q->where(fn ($w) =>
                $w->whereHas('solicitanteClients', fn ($c) => $c->whereIn('clients.id', $clientIds))
                  ->orWhereHas('solicitanteSites', fn ($s) => $s->whereIn('sites.id', $siteIds))))
            ->when($request->filled('client_id'), fn ($q) => $q->whereHas('solicitanteClients', fn ($c) => $c->where('clients.id', $request->client_id)))
            ->when($request->filled('site_id'), fn ($q) => $q->whereHas('solicitanteSites', fn ($s) => $s->where('sites.id', $request->site_id)))
            ->when($request->filled('search'), fn ($q) => $q->where(fn ($w) =>
                $w->where('name', 'ilike', "%{$request->search}%")->orWhere('email', 'ilike', "%{$request->search}%")))
            ->orderBy('name')->limit(500)->get();

        return response()->json($users->map(fn ($u) => $this->serialize($u)));
    }

    public function store(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('solicitantes.create'), 403);

        $data = $request->validate([
            'name'              => 'required|string|max:255',
            'email'             => 'required|email|unique:users,email',
            'telegram_username' => 'nullable|string|max:80',
            'whatsapp_number'   => 'nullable|string|max:40',
            'client_id'         => 'nullable|exists:clients,id',
            'site_id'           => 'nullable|exists:sites,id',
        ]);

        if (empty($data['client_id']) && empty($data['site_id'])) {
            return response()->json(['message' => 'Asocia el solicitante a un cliente o a un sitio.'], 422);
        }
        $this->authorizeScopeTargets($request->user(), $data['client_id'] ?? null, $data['site_id'] ?? null);

        $identity = $this->messagingIdentity($data, null);

        $user = User::create([
            'name'                 => $data['name'],
            'email'                => $data['email'],
            'password'             => Str::password(16),
            'must_change_password' => true,
            'is_active'            => true,
            'created_by'           => $request->user()->id,
            'telegram_username'    => $identity['telegram_username'],
            'whatsapp_number'      => $identity['whatsapp_number'],
        ]);
        $user->assignRole('solicitante');
        if (! empty($data['client_id'])) $user->solicitanteClients()->syncWithoutDetaching([$data['client_id']]);
        if (! empty($data['site_id']))   $user->solicitanteSites()->syncWithoutDetaching([$data['site_id']]);

        return response()->json([
            'message'     => 'Solicitante creado.',
            'solicitante' => $this->serialize($user->load('solicitanteClients:id,name,short_name', 'solicitanteSites:id,name')),
            'access_link' => $this->buildAccessLink($user),
        ], 201);
    }

    public function update(Request $request, User $user): JsonResponse
    {
        abort_unless($request->user()->can('solicitantes.edit'), 403);
        $this->authorizeManages($request->user(), $user);

        $data = $request->validate([
            'name'              => 'sometimes|required|string|max:255',
            'email'             => "sometimes|required|email|unique:users,email,{$user->id}",
            'telegram_username' => 'nullable|string|max:80',
            'whatsapp_number'   => 'nullable|string|max:40',
            'client_id'         => 'nullable|exists:clients,id',
            'site_id'           => 'nullable|exists:sites,id',
        ]);

        $identity = $this->messagingIdentity($data, $user->id);
        $user->fill(array_merge(
            array_filter($request->only('name', 'email'), fn ($v) => $v !== null),
            $identity,
        ))->save();

        // Reasignación de alcance (si viene explícita).
        if ($request->has('client_id') || $request->has('site_id')) {
            $this->authorizeScopeTargets($request->user(), $data['client_id'] ?? null, $data['site_id'] ?? null);
            $user->solicitanteClients()->sync(array_filter([$data['client_id'] ?? null]));
            $user->solicitanteSites()->sync(array_filter([$data['site_id'] ?? null]));
        }

        return response()->json([
            'message'     => 'Solicitante actualizado.',
            'solicitante' => $this->serialize($user->load('solicitanteClients:id,name,short_name', 'solicitanteSites:id,name')),
        ]);
    }

    public function destroy(Request $request, User $user): JsonResponse
    {
        abort_unless($request->user()->can('solicitantes.delete'), 403);
        $this->authorizeManages($request->user(), $user);

        $user->delete(); // soft delete

        return response()->json(['message' => 'Solicitante eliminado.']);
    }

    /** Enlace de acceso copiable (definir contraseña) para compartir por cualquier medio. */
    public function accessLink(Request $request, User $user): JsonResponse
    {
        abort_unless($request->user()->can('solicitantes.edit') || $request->user()->can('solicitantes.create'), 403);
        $this->authorizeManages($request->user(), $user);

        return response()->json(['url' => $this->buildAccessLink($user)]);
    }

    // ── Importación masiva ───────────────────────────────────────────

    /** Plantilla Excel de importación (encabezados + ejemplo). */
    public function importTemplate(Request $request): StreamedResponse
    {
        abort_unless($request->user()->can('solicitantes.create'), 403);

        $book = new Spreadsheet();
        $sheet = $book->getActiveSheet();
        $sheet->fromArray([
            ['name', 'email', 'telegram_username', 'whatsapp_number'],
            ['Juan Pérez', 'juan@ejemplo.com', '@juanperez', '5215512345678'],
        ]);
        foreach (range('A', 'D') as $col) $sheet->getColumnDimension($col)->setAutoSize(true);

        return response()->streamDownload(function () use ($book) {
            (new Xlsx($book))->save('php://output');
        }, 'plantilla-solicitantes.xlsx', ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }

    /** Importa solicitantes de un Excel/CSV y los asocia al cliente/sitio indicado. */
    public function import(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('solicitantes.create'), 403);

        $request->validate([
            'file'      => 'required|file|mimes:xlsx,xls,csv,txt|max:5120',
            'client_id' => 'nullable|exists:clients,id',
            'site_id'   => 'nullable|exists:sites,id',
        ]);
        if (! $request->filled('client_id') && ! $request->filled('site_id')) {
            return response()->json(['message' => 'Indica el cliente o sitio al que se asociarán los solicitantes.'], 422);
        }
        $this->authorizeScopeTargets($request->user(), $request->client_id, $request->site_id);

        $rows = $this->readRows($request->file('file')->getRealPath());
        $created = 0; $errors = [];

        foreach ($rows as $i => $row) {
            $line  = $i + 2; // +1 header, +1 base-1
            $name  = trim((string) ($row['name'] ?? ''));
            $email = strtolower(trim((string) ($row['email'] ?? '')));

            if ($name === '' || $email === '') { $errors[] = ['row' => $line, 'reason' => 'Falta nombre o correo.']; continue; }
            if (! filter_var($email, FILTER_VALIDATE_EMAIL)) { $errors[] = ['row' => $line, 'email' => $email, 'reason' => 'Correo inválido.']; continue; }
            if (User::where('email', $email)->exists()) { $errors[] = ['row' => $line, 'email' => $email, 'reason' => 'Correo ya registrado.']; continue; }

            try {
                $identity = $this->messagingIdentity([
                    'telegram_username' => $row['telegram_username'] ?? null,
                    'whatsapp_number'   => $row['whatsapp_number'] ?? null,
                ], null);
            } catch (\Throwable $e) {
                $errors[] = ['row' => $line, 'email' => $email, 'reason' => 'Telegram/WhatsApp ya asignado a otra persona.'];
                continue;
            }

            $user = User::create([
                'name'                 => $name,
                'email'                => $email,
                'password'             => Str::password(16),
                'must_change_password' => true,
                'is_active'            => true,
                'created_by'           => $request->user()->id,
                'telegram_username'    => $identity['telegram_username'],
                'whatsapp_number'      => $identity['whatsapp_number'],
            ]);
            $user->assignRole('solicitante');
            if ($request->filled('client_id')) $user->solicitanteClients()->syncWithoutDetaching([$request->client_id]);
            if ($request->filled('site_id'))   $user->solicitanteSites()->syncWithoutDetaching([$request->site_id]);
            $created++;
        }

        return response()->json([
            'message' => "Importación terminada: {$created} creados, " . count($errors) . ' con error.',
            'created' => $created,
            'errors'  => $errors,
        ]);
    }

    // ── Internos ─────────────────────────────────────────────────────

    /** @return array{0:\Illuminate\Support\Collection,1:\Illuminate\Support\Collection,2:bool} [clientIds, siteIds, all] */
    private function scope(User $actor): array
    {
        if ($actor->hasAnyRole(['superadmin', 'admin'])) {
            return [collect(), collect(), true];
        }
        $clientIds = $actor->clientsAsAdmin()->pluck('clients.id');
        $siteIds   = $actor->sitesAsAdmin()->pluck('sites.id')
            ->merge(Site::whereIn('client_id', $clientIds)->pluck('id'))->unique()->values();
        return [$clientIds, $siteIds, false];
    }

    private function authorizeScopeTargets(User $actor, $clientId, $siteId): void
    {
        if ($actor->hasAnyRole(['superadmin', 'admin'])) return;
        [$clientIds, $siteIds] = $this->scope($actor);

        if ($clientId && ! $clientIds->contains((int) $clientId)) {
            abort(403, 'No puedes asociar solicitantes a ese cliente.');
        }
        if ($siteId && ! $siteIds->contains((int) $siteId)) {
            abort(403, 'No puedes asociar solicitantes a ese sitio.');
        }
    }

    /** Verifica que el actor administre a este solicitante (por alguna asociación en su alcance). */
    private function authorizeManages(User $actor, User $solicitante): void
    {
        abort_unless($solicitante->hasRole('solicitante'), 404);
        if ($actor->hasAnyRole(['superadmin', 'admin'])) return;

        [$clientIds, $siteIds, $all] = $this->scope($actor);
        $ok = $solicitante->solicitanteClients()->whereIn('clients.id', $clientIds)->exists()
            || $solicitante->solicitanteSites()->whereIn('sites.id', $siteIds)->exists();
        abort_unless($ok, 403, 'Este solicitante no está en tu alcance.');
    }

    private function buildAccessLink(User $user): string
    {
        $token = PasswordBroker::broker()->createToken($user);
        return rtrim(config('app.frontend_url', config('app.url')), '/')
            . '/reset-password?token=' . $token . '&email=' . urlencode($user->email);
    }

    /**
     * @return array{telegram_username:?string, whatsapp_number:?string}
     */
    private function messagingIdentity(array $data, ?int $ignoreId): array
    {
        $tg = ltrim(strtolower(trim((string) ($data['telegram_username'] ?? ''))), '@') ?: null;
        $wa = preg_replace('/\D+/', '', (string) ($data['whatsapp_number'] ?? '')) ?: null;

        if ($tg && User::where('telegram_username', $tg)->where('id', '!=', $ignoreId)->exists()) {
            abort(422, 'Ese usuario de Telegram ya está asignado a otra persona.');
        }
        if ($wa && User::where('whatsapp_number', $wa)->where('id', '!=', $ignoreId)->exists()) {
            abort(422, 'Ese número de WhatsApp ya está asignado a otra persona.');
        }
        return ['telegram_username' => $tg, 'whatsapp_number' => $wa];
    }

    /** Lee filas de un xlsx/csv como arreglos asociativos por encabezado normalizado. */
    private function readRows(string $path): array
    {
        $sheet = IOFactory::load($path)->getActiveSheet();
        $raw = $sheet->toArray(null, true, false, false);
        if (empty($raw)) return [];

        $headers = array_map(fn ($h) => strtolower(trim((string) $h)), array_shift($raw));
        $rows = [];
        foreach ($raw as $line) {
            if (count(array_filter($line, fn ($v) => trim((string) $v) !== '')) === 0) continue; // fila vacía
            $assoc = [];
            foreach ($headers as $idx => $key) {
                if ($key === '') continue;
                $assoc[$key] = $line[$idx] ?? null;
            }
            $rows[] = $assoc;
        }
        return $rows;
    }

    private function serialize(User $u): array
    {
        return [
            'id'                => $u->id,
            'name'              => $u->name,
            'email'             => $u->email,
            'is_active'         => $u->is_active,
            'telegram_username' => $u->telegram_username,
            'whatsapp_number'   => $u->whatsapp_number,
            'last_login_at'     => $u->last_login_at,
            'clients'           => $u->solicitanteClients->map(fn ($c) => ['id' => $c->id, 'name' => $c->short_name ?: $c->name]),
            'sites'             => $u->solicitanteSites->map(fn ($s) => ['id' => $s->id, 'name' => $s->name]),
        ];
    }
}
