<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CaptureContact;
use App\Models\Channel;
use App\Models\Site;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Contactos de captación etiquetados a un cliente/sitio. Permite listar, etiquetar
 * un contacto existente y pre-registrar contactos nuevos (por teléfono en WhatsApp
 * o usuario de Telegram) desde la ficha del cliente/sitio. Gateado por `channels.manage`.
 */
class CaptureContactController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('channels.manage'), 403);

        $contacts = CaptureContact::query()
            ->where('external_id', 'not like', 'sim:%') // oculta los contactos del simulador
            ->with(['channel:id,name,provider', 'client:id,name,short_name', 'site:id,name'])
            ->addSelect(['conversation_id' => \App\Models\CaptureConversation::query()
                ->select('id')->whereColumn('contact_id', 'capture_contacts.id')
                ->orderByDesc('id')->limit(1)])
            ->when($request->filled('channel_id'), fn ($q) => $q->where('channel_id', $request->channel_id))
            ->when($request->filled('client_id'),  fn ($q) => $q->where('client_id', $request->client_id))
            ->when($request->filled('site_id'),    fn ($q) => $q->where('site_id', $request->site_id))
            ->when($request->boolean('untagged'),  fn ($q) => $q->whereNull('client_id')->whereNull('site_id'))
            ->when($request->filled('search'), fn ($q) => $q->where(fn ($s) =>
                $s->where('name', 'ilike', "%{$request->search}%")
                  ->orWhere('external_id', 'ilike', "%{$request->search}%")
                  ->orWhere('username', 'ilike', "%{$request->search}%")))
            ->orderBy('name')->orderBy('id')
            ->limit(500)->get();

        return response()->json($contacts);
    }

    /** Pre-registra un contacto (o etiqueta uno ya existente por su identificador). */
    public function store(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('channels.manage'), 403);

        $data = $request->validate([
            'channel_id' => 'required|exists:channels,id',
            'name'       => 'nullable|string|max:160',
            'phone'      => 'nullable|string|max:40',   // WhatsApp (wa_id / teléfono)
            'username'   => 'nullable|string|max:80',   // Telegram (@usuario)
            'client_id'  => 'nullable|exists:clients,id',
            'site_id'    => 'nullable|exists:sites,id',
        ]);

        $channel = Channel::findOrFail($data['channel_id']);
        [$clientId, $siteId] = $this->resolveScope($data['client_id'] ?? null, $data['site_id'] ?? null);

        $externalId = null;
        $username   = null;

        if ($channel->isWhatsApp()) {
            $externalId = preg_replace('/\D+/', '', (string) ($data['phone'] ?? ''));
            if (! $externalId) {
                return response()->json(['message' => 'Indica el teléfono (wa_id) del contacto de WhatsApp.'], 422);
            }
        } else {
            $username = ltrim(strtolower(trim((string) ($data['username'] ?? ''))), '@');
            if (! $username) {
                return response()->json(['message' => 'Indica el usuario de Telegram (sin @) del contacto.'], 422);
            }
        }

        // Upsert: por identificador del canal (WA) o por usuario pendiente (TG).
        $contact = $channel->isWhatsApp()
            ? CaptureContact::firstOrNew(['channel_id' => $channel->id, 'external_id' => $externalId])
            : (CaptureContact::where('channel_id', $channel->id)->where('username', $username)->first()
                ?? new CaptureContact(['channel_id' => $channel->id, 'username' => $username]));

        $contact->fill([
            'name'           => $data['name'] ?? $contact->name,
            'client_id'      => $clientId,
            'site_id'        => $siteId,
            'pre_registered' => $contact->exists ? $contact->pre_registered : true,
        ]);
        if ($channel->isWhatsApp()) $contact->external_id = $externalId;
        if ($username) $contact->username = $username;
        $contact->save();

        return response()->json([
            'message' => 'Contacto registrado.',
            'contact' => $contact->fresh(['channel:id,name,provider', 'client:id,name,short_name', 'site:id,name']),
        ], 201);
    }

    /** Etiqueta/edita un contacto existente. */
    public function update(Request $request, CaptureContact $contact): JsonResponse
    {
        abort_unless($request->user()->can('channels.manage'), 403);

        $data = $request->validate([
            'name'      => 'nullable|string|max:160',
            'client_id' => 'nullable|exists:clients,id',
            'site_id'   => 'nullable|exists:sites,id',
        ]);

        [$clientId, $siteId] = $this->resolveScope($data['client_id'] ?? null, $data['site_id'] ?? null);

        $contact->fill([
            'name'      => array_key_exists('name', $data) ? $data['name'] : $contact->name,
            'client_id' => $clientId,
            'site_id'   => $siteId,
        ])->save();

        return response()->json([
            'message' => 'Contacto actualizado.',
            'contact' => $contact->fresh(['channel:id,name,provider', 'client:id,name,short_name', 'site:id,name']),
        ]);
    }

    public function destroy(Request $request, CaptureContact $contact): JsonResponse
    {
        abort_unless($request->user()->can('channels.manage'), 403);

        $contact->delete();

        return response()->json(['message' => 'Contacto eliminado.']);
    }

    /** Si se da un sitio, el cliente se deriva de él (coherencia). */
    private function resolveScope(?int $clientId, ?int $siteId): array
    {
        if ($siteId) {
            $site = Site::find($siteId);
            return [$site?->client_id, $siteId];
        }
        return [$clientId, null];
    }
}
