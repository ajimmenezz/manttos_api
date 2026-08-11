<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Consulta de la Auditoría del sistema. Sensible → gateado por `audit.view`
 * (superadmin por defecto). Permite ver por usuario, por módulo y por registro concreto.
 */
class AuditController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('audit.view'), 403, 'No autorizado para ver la auditoría.');

        $dateFrom = $request->filled('date_from') ? Carbon::parse($request->date_from)->startOfDay() : null;
        $dateTo   = $request->filled('date_to')   ? Carbon::parse($request->date_to)->endOfDay()     : null;

        $logs = ActivityLog::query()
            ->when($request->filled('user_id'),      fn ($q) => $q->where('user_id', $request->user_id))
            ->when($request->filled('module'),       fn ($q) => $q->where('module', $request->module))
            ->when($request->filled('action'),       fn ($q) => $q->where('action', $request->action))
            ->when($request->filled('source'),       fn ($q) => $q->where('source', $request->source))
            ->when($request->filled('subject_type'), fn ($q) => $q->where('subject_type', $request->subject_type))
            ->when($request->filled('subject_id'),   fn ($q) => $q->where('subject_id', $request->subject_id))
            ->when($dateFrom, fn ($q) => $q->where('created_at', '>=', $dateFrom))
            ->when($dateTo,   fn ($q) => $q->where('created_at', '<=', $dateTo))
            ->when($request->filled('search'), function ($q) use ($request) {
                $s = '%' . $request->search . '%';
                $q->where(fn ($w) => $w->where('description', 'ilike', $s)->orWhere('subject_label', 'ilike', $s));
            })
            ->with(['user:id,name', 'impersonator:id,name'])
            ->orderByDesc('id');

        $perPage = min(100, max(10, (int) $request->input('per_page', 30)));
        $page    = $logs->paginate($perPage);

        return response()->json([
            'data' => collect($page->items())->map(fn (ActivityLog $l) => [
                'id'            => $l->id,
                'created_at'    => $l->created_at,
                'user'          => $l->user ? ['id' => $l->user->id, 'name' => $l->user->name] : null,
                'impersonator'  => $l->impersonator ? ['id' => $l->impersonator->id, 'name' => $l->impersonator->name] : null,
                'source'        => $l->source,
                'module'        => $l->module,
                'action'        => $l->action,
                'description'   => $l->description,
                'subject_type'  => $l->subject_type,
                'subject_id'    => $l->subject_id,
                'subject_label' => $l->subject_label,
                'properties'    => $l->properties,
                'method'        => $l->method,
                'route'         => $l->route,
                'path'          => $l->path,
                'status'        => $l->status,
                'ip'            => $l->ip,
            ]),
            'pagination' => [
                'page' => $page->currentPage(), 'per_page' => $page->perPage(),
                'total' => $page->total(), 'last_page' => $page->lastPage(),
            ],
        ]);
    }

    /** Opciones para los filtros (usuarios con actividad, módulos y acciones presentes). */
    public function filters(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('audit.view'), 403, 'No autorizado para ver la auditoría.');

        $userIds = ActivityLog::query()->whereNotNull('user_id')->distinct()->pluck('user_id');
        $users   = User::whereIn('id', $userIds)->orderBy('name')->get(['id', 'name'])
            ->map(fn ($u) => ['id' => $u->id, 'name' => $u->name]);

        $modules = ActivityLog::query()->whereNotNull('module')->distinct()->orderBy('module')->pluck('module');
        $actions = ActivityLog::query()->distinct()->orderBy('action')->pluck('action');

        return response()->json([
            'users'   => $users,
            'modules' => $modules,
            'actions' => $actions,
        ]);
    }
}
