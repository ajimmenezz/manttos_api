# Inventario de mantenimientos

> **Este archivo se GENERA.** No lo edite a mano: se sobrescribe.
> `php artisan manttos:inventario`
>
> Generado el 2026-09-08 05:18
> Contra `mantenimientos` en `local`.

Los **hechos** salen del router y del esquema, así que no pueden mentir. El
**porqué** de cada cosa vive en `CLAUDE.md`, escrito por personas: un volcado
no explica una decisión.

Este archivo vale lo que valga la base contra la que se generó — si se corre
contra un ambiente atrasado, dirá lo de ese ambiente.

## 1. En números

| | |
|---|---:|
| Rutas de API | **481** |
| — con guarda declarada | **481** |
| — sin guarda declarada | **0** |
| Tablas (`mantenimientos`) | **87** |
| Migraciones | **121** |
| Comandos propios | **11** |
| Modelos | **69** |

> «Sin guarda declarada» incluye lo público por diseño —webhooks entrantes,
> login, páginas abiertas—: no todas son un pendiente.


## 2. Rutas de la API

Agrupadas por el primer tramo del camino, que es lo único estable: el
controlador puede moverse de carpeta, pero la URL que consume el front no.

### activity-types  

| Método | Ruta | Guardas |
|---|---|---|
| `GET` | `/api/activity-types/{activityType}/systems` | `api` · `auth:sanctum` |
| `GET` | `/api/activity-types/{activityType}/systems/{system}/automations` | `api` · `auth:sanctum` |
| `POST` | `/api/activity-types/{activityType}/systems/{system}/automations` | `api` · `auth:sanctum` |
| `GET` | `/api/activity-types/{activityType}/systems/{system}/automations/active` | `api` · `auth:sanctum` |
| `GET` | `/api/activity-types/{activityType}/systems/{system}/automations/options` | `api` · `auth:sanctum` |
| `POST` | `/api/activity-types/{activityType}/systems/{system}/automations/reorder` | `api` · `auth:sanctum` |
| `DELETE` | `/api/activity-types/{activityType}/systems/{system}/automations/{automation}` | `api` · `auth:sanctum` |
| `PUT` | `/api/activity-types/{activityType}/systems/{system}/automations/{automation}` | `api` · `auth:sanctum` |
| `POST` | `/api/activity-types/{activityType}/systems/{system}/automations/{automation}/toggle-status` | `api` · `auth:sanctum` |
| `GET` | `/api/activity-types/{activityType}/systems/{system}/fields` | `api` · `auth:sanctum` |
| `POST` | `/api/activity-types/{activityType}/systems/{system}/fields` | `api` · `auth:sanctum` |
| `POST` | `/api/activity-types/{activityType}/systems/{system}/fields/reorder` | `api` · `auth:sanctum` |
| `DELETE` | `/api/activity-types/{activityType}/systems/{system}/fields/{field}` | `api` · `auth:sanctum` |
| `PUT` | `/api/activity-types/{activityType}/systems/{system}/fields/{field}` | `api` · `auth:sanctum` |
| `POST` | `/api/activity-types/{activityType}/systems/{system}/fields/{field}/toggle-bitacora` | `api` · `auth:sanctum` |
| `POST` | `/api/activity-types/{activityType}/systems/{system}/fields/{field}/toggle-status` | `api` · `auth:sanctum` |
| `DELETE` | `/api/activity-types/{activityType}/systems/{system}/link` | `api` · `auth:sanctum` |
| `POST` | `/api/activity-types/{activityType}/systems/{system}/link` | `api` · `auth:sanctum` |

### ai  

| Método | Ruta | Guardas |
|---|---|---|
| `POST` | `/api/ai/chat` | `api` · `auth:sanctum` |
| `POST` | `/api/ai/chat/confirm` | `api` · `auth:sanctum` |
| `GET` | `/api/ai/config` | `api` · `auth:sanctum` |
| `PUT` | `/api/ai/config` | `api` · `auth:sanctum` |
| `POST` | `/api/ai/feedback` | `api` · `auth:sanctum` |
| `GET` | `/api/ai/interactions` | `api` · `auth:sanctum` |
| `GET` | `/api/ai/interactions/stats` | `api` · `auth:sanctum` |
| `GET` | `/api/ai/interactions/{interaction}` | `api` · `auth:sanctum` |
| `GET` | `/api/ai/reports/{report}` | `api` · `auth:sanctum` |
| `GET` | `/api/ai/status` | `api` · `auth:sanctum` |

### app-chat  

| Método | Ruta | Guardas |
|---|---|---|
| `GET` | `/api/app-chat` | `api` · `auth:sanctum` |
| `POST` | `/api/app-chat` | `api` · `auth:sanctum` |
| `GET` | `/api/app-chat/poll` | `api` · `auth:sanctum` |

### app-errors  

| Método | Ruta | Guardas |
|---|---|---|
| `GET` | `/api/app-errors` | `api` · `auth:sanctum` |
| `POST` | `/api/app-errors` | `api` · `throttle:30,1` |
| `GET` | `/api/app-errors/filters` | `api` · `auth:sanctum` |
| `POST` | `/api/app-errors/resolve` | `api` · `auth:sanctum` |
| `GET` | `/api/app-errors/{appError}` | `api` · `auth:sanctum` |

### audit  

| Método | Ruta | Guardas |
|---|---|---|
| `GET` | `/api/audit` | `api` · `auth:sanctum` |
| `GET` | `/api/audit/filters` | `api` · `auth:sanctum` |

### broadcasting  

| Método | Ruta | Guardas |
|---|---|---|
| `POST` | `/api/broadcasting/auth` | `api` · `auth:sanctum` · `permission:chat.use` |

### captacion  

| Método | Ruta | Guardas |
|---|---|---|
| `GET` | `/api/captacion/agent-rules` | `api` · `auth:sanctum` |
| `POST` | `/api/captacion/agent-rules` | `api` · `auth:sanctum` |
| `POST` | `/api/captacion/agent-rules/analyze` | `api` · `auth:sanctum` |
| `GET` | `/api/captacion/agent-rules/options` | `api` · `auth:sanctum` |
| `DELETE` | `/api/captacion/agent-rules/{rule}` | `api` · `auth:sanctum` |
| `PUT` | `/api/captacion/agent-rules/{rule}` | `api` · `auth:sanctum` |
| `GET` | `/api/captacion/contacts` | `api` · `auth:sanctum` |
| `POST` | `/api/captacion/contacts` | `api` · `auth:sanctum` |
| `DELETE` | `/api/captacion/contacts/{contact}` | `api` · `auth:sanctum` |
| `PATCH` | `/api/captacion/contacts/{contact}` | `api` · `auth:sanctum` |
| `GET` | `/api/captacion/conversations` | `api` · `auth:sanctum` |
| `GET` | `/api/captacion/conversations/{conversation}` | `api` · `auth:sanctum` |
| `PATCH` | `/api/captacion/conversations/{conversation}/handling` | `api` · `auth:sanctum` |
| `POST` | `/api/captacion/conversations/{conversation}/messages` | `api` · `auth:sanctum` |
| `GET` | `/api/captacion/simulator` | `api` · `auth:sanctum` |
| `POST` | `/api/captacion/simulator/message` | `api` · `auth:sanctum` |
| `POST` | `/api/captacion/simulator/reset` | `api` · `auth:sanctum` |
| `POST` | `/api/captacion/simulator/start` | `api` · `auth:sanctum` |

### catalogs  

| Método | Ruta | Guardas |
|---|---|---|
| `GET` | `/api/catalogs` | `api` · `auth:sanctum` |
| `POST` | `/api/catalogs` | `api` · `auth:sanctum` |
| `GET` | `/api/catalogs/active/{type}` | `api` · `auth:sanctum` |
| `GET` | `/api/catalogs/device-types/export` | `api` · `auth:sanctum` |
| `POST` | `/api/catalogs/device-types/import` | `api` · `auth:sanctum` |
| `DELETE` | `/api/catalogs/{catalog}` | `api` · `auth:sanctum` |
| `GET` | `/api/catalogs/{catalog}` | `api` · `auth:sanctum` |
| `PUT` | `/api/catalogs/{catalog}` | `api` · `auth:sanctum` |
| `POST` | `/api/catalogs/{catalog}/toggle-status` | `api` · `auth:sanctum` |
| `GET` | `/api/v1/catalogs/active/{type}` | `api` · `auth:sanctum` |

### change-password  

| Método | Ruta | Guardas |
|---|---|---|
| `POST` | `/api/change-password` | `api` · `auth:sanctum` |

### channels  

| Método | Ruta | Guardas |
|---|---|---|
| `GET` | `/api/channels` | `api` · `auth:sanctum` |
| `POST` | `/api/channels` | `api` · `auth:sanctum` |
| `DELETE` | `/api/channels/{channel}` | `api` · `auth:sanctum` |
| `PUT` | `/api/channels/{channel}` | `api` · `auth:sanctum` |
| `GET` | `/api/channels/{channel}/conversations` | `api` · `auth:sanctum` |
| `GET` | `/api/channels/{channel}/conversations/{conversation}/messages` | `api` · `auth:sanctum` |
| `POST` | `/api/channels/{channel}/verify-token` | `api` · `auth:sanctum` |

### clients  

| Método | Ruta | Guardas |
|---|---|---|
| `GET` | `/api/clients` | `api` · `auth:sanctum` |
| `POST` | `/api/clients` | `api` · `auth:sanctum` |
| `GET` | `/api/clients/all` | `api` · `auth:sanctum` |
| `DELETE` | `/api/clients/{client}` | `api` · `auth:sanctum` |
| `GET` | `/api/clients/{client}` | `api` · `auth:sanctum` |
| `PUT|PATCH` | `/api/clients/{client}` | `api` · `auth:sanctum` |
| `GET` | `/api/clients/{client}/admins` | `api` · `auth:sanctum` |
| `POST` | `/api/clients/{client}/admins` | `api` · `auth:sanctum` |
| `GET` | `/api/clients/{client}/admins/candidates` | `api` · `auth:sanctum` |
| `DELETE` | `/api/clients/{client}/admins/{user}` | `api` · `auth:sanctum` |
| `GET` | `/api/clients/{client}/engineers` | `api` · `auth:sanctum` |
| `POST` | `/api/clients/{client}/engineers` | `api` · `auth:sanctum` |
| `GET` | `/api/clients/{client}/engineers/candidates` | `api` · `auth:sanctum` |
| `DELETE` | `/api/clients/{client}/engineers/{user}` | `api` · `auth:sanctum` |
| `POST` | `/api/clients/{client}/restore` | `api` · `auth:sanctum` |
| `GET` | `/api/clients/{client}/sites` | `api` · `auth:sanctum` |
| `POST` | `/api/clients/{client}/sites` | `api` · `auth:sanctum` |
| `GET` | `/api/clients/{client}/sites/compact` | `api` · `auth:sanctum` |
| `DELETE` | `/api/clients/{client}/sites/{site}` | `api` · `auth:sanctum` |
| `GET` | `/api/clients/{client}/sites/{site}` | `api` · `auth:sanctum` |
| `PUT|PATCH` | `/api/clients/{client}/sites/{site}` | `api` · `auth:sanctum` |
| `GET` | `/api/clients/{client}/sites/{site}/admins` | `api` · `auth:sanctum` |
| `POST` | `/api/clients/{client}/sites/{site}/admins` | `api` · `auth:sanctum` |
| `GET` | `/api/clients/{client}/sites/{site}/admins/candidates` | `api` · `auth:sanctum` |
| `DELETE` | `/api/clients/{client}/sites/{site}/admins/{user}` | `api` · `auth:sanctum` |
| `GET` | `/api/clients/{client}/sites/{site}/directories` | `api` · `auth:sanctum` |
| `POST` | `/api/clients/{client}/sites/{site}/directories` | `api` · `auth:sanctum` |
| `GET` | `/api/clients/{client}/sites/{site}/directories/{directory}` | `api` · `auth:sanctum` |
| `PUT|PATCH` | `/api/clients/{client}/sites/{site}/directories/{directory}` | `api` · `auth:sanctum` |
| `GET` | `/api/clients/{client}/sites/{site}/directories/{directory}/devices` | `api` · `auth:sanctum` |
| `POST` | `/api/clients/{client}/sites/{site}/directories/{directory}/devices` | `api` · `auth:sanctum` |
| `POST` | `/api/clients/{client}/sites/{site}/directories/{directory}/devices/archive-all` | `api` · `auth:sanctum` |
| `GET` | `/api/clients/{client}/sites/{site}/directories/{directory}/devices/export` | `api` · `auth:sanctum` |
| `GET` | `/api/clients/{client}/sites/{site}/directories/{directory}/devices/filter-values` | `api` · `auth:sanctum` |
| `POST` | `/api/clients/{client}/sites/{site}/directories/{directory}/devices/import` | `api` · `auth:sanctum` |
| `POST` | `/api/clients/{client}/sites/{site}/directories/{directory}/devices/import/validate` | `api` · `auth:sanctum` |
| `POST` | `/api/clients/{client}/sites/{site}/directories/{directory}/devices/restore-all` | `api` · `auth:sanctum` |
| `GET` | `/api/clients/{client}/sites/{site}/directories/{directory}/devices/{device}` | `api` · `auth:sanctum` |
| `PUT|PATCH` | `/api/clients/{client}/sites/{site}/directories/{directory}/devices/{device}` | `api` · `auth:sanctum` |
| `POST` | `/api/clients/{client}/sites/{site}/directories/{directory}/devices/{device}/restore` | `api` · `auth:sanctum` |
| `POST` | `/api/clients/{client}/sites/{site}/directories/{directory}/devices/{device}/toggle-status` | `api` · `auth:sanctum` |
| `POST` | `/api/clients/{client}/sites/{site}/directories/{directory}/toggle-status` | `api` · `auth:sanctum` |
| `GET` | `/api/clients/{client}/sites/{site}/engineers` | `api` · `auth:sanctum` |
| `POST` | `/api/clients/{client}/sites/{site}/engineers` | `api` · `auth:sanctum` |
| `GET` | `/api/clients/{client}/sites/{site}/engineers/candidates` | `api` · `auth:sanctum` |
| `DELETE` | `/api/clients/{client}/sites/{site}/engineers/{user}` | `api` · `auth:sanctum` |
| `GET` | `/api/clients/{client}/sites/{site}/floor-plans` | `api` · `auth:sanctum` |
| `POST` | `/api/clients/{client}/sites/{site}/floor-plans` | `api` · `auth:sanctum` |
| `GET` | `/api/clients/{client}/sites/{site}/floor-plans/placed-devices` | `api` · `auth:sanctum` |
| `DELETE` | `/api/clients/{client}/sites/{site}/floor-plans/{floorPlan}` | `api` · `auth:sanctum` |
| `GET` | `/api/clients/{client}/sites/{site}/floor-plans/{floorPlan}` | `api` · `auth:sanctum` |
| `PUT` | `/api/clients/{client}/sites/{site}/floor-plans/{floorPlan}` | `api` · `auth:sanctum` |
| `PUT` | `/api/clients/{client}/sites/{site}/floor-plans/{floorPlan}/directory-filter` | `api` · `auth:sanctum` |
| `DELETE` | `/api/clients/{client}/sites/{site}/floor-plans/{floorPlan}/placements` | `api` · `auth:sanctum` |
| `POST` | `/api/clients/{client}/sites/{site}/floor-plans/{floorPlan}/placements` | `api` · `auth:sanctum` |
| `DELETE` | `/api/clients/{client}/sites/{site}/floor-plans/{floorPlan}/placements/{device}` | `api` · `auth:sanctum` |
| `POST` | `/api/clients/{client}/sites/{site}/floor-plans/{floorPlan}/toggle-status` | `api` · `auth:sanctum` |
| `GET` | `/api/clients/{client}/sites/{site}/maintenances` | `api` · `auth:sanctum` |
| `POST` | `/api/clients/{client}/sites/{site}/maintenances` | `api` · `auth:sanctum` |
| `GET` | `/api/clients/{client}/sites/{site}/maintenances/available-systems` | `api` · `auth:sanctum` |
| `PUT` | `/api/clients/{client}/sites/{site}/maintenances/{maintenance}` | `api` · `auth:sanctum` |
| `GET` | `/api/clients/{client}/sites/{site}/maintenances/{maintenance}/engineers` | `api` · `auth:sanctum` |
| `POST` | `/api/clients/{client}/sites/{site}/maintenances/{maintenance}/engineers` | `api` · `auth:sanctum` |
| `GET` | `/api/clients/{client}/sites/{site}/maintenances/{maintenance}/engineers/candidates` | `api` · `auth:sanctum` |
| `DELETE` | `/api/clients/{client}/sites/{site}/maintenances/{maintenance}/engineers/{user}` | `api` · `auth:sanctum` |
| `POST` | `/api/clients/{client}/sites/{site}/restore` | `api` · `auth:sanctum` |
| `POST` | `/api/clients/{client}/sites/{site}/toggle-status` | `api` · `auth:sanctum` |
| `GET` | `/api/clients/{client}/system-templates` | `api` · `auth:sanctum` |
| `GET` | `/api/clients/{client}/systems/{system}/fields` | `api` · `auth:sanctum` |
| `POST` | `/api/clients/{client}/systems/{system}/fields` | `api` · `auth:sanctum` |
| `POST` | `/api/clients/{client}/systems/{system}/fields/reorder` | `api` · `auth:sanctum` |
| `DELETE` | `/api/clients/{client}/systems/{system}/fields/{field}` | `api` · `auth:sanctum` |
| `PUT` | `/api/clients/{client}/systems/{system}/fields/{field}` | `api` · `auth:sanctum` |
| `POST` | `/api/clients/{client}/systems/{system}/fields/{field}/toggle-dashboard` | `api` · `auth:sanctum` |
| `POST` | `/api/clients/{client}/systems/{system}/fields/{field}/toggle-status` | `api` · `auth:sanctum` |
| `POST` | `/api/clients/{client}/toggle-status` | `api` · `auth:sanctum` |
| `GET` | `/api/v1/clients` | `api` · `auth:sanctum` |
| `GET` | `/api/v1/clients/{client}` | `api` · `auth:sanctum` |
| `GET` | `/api/v1/clients/{client}/sites` | `api` · `auth:sanctum` |
| `GET` | `/api/v1/clients/{client}/sites/{site}` | `api` · `auth:sanctum` |
| `GET` | `/api/v1/clients/{client}/sites/{site}/maintenances` | `api` · `auth:sanctum` |
| `POST` | `/api/v1/clients/{client}/sites/{site}/maintenances` | `api` · `auth:sanctum` · `App\Http\Middleware\RequireWriteScope` |
| `PUT` | `/api/v1/clients/{client}/sites/{site}/maintenances/{maintenance}` | `api` · `auth:sanctum` · `App\Http\Middleware\RequireWriteScope` |

### conversations  

| Método | Ruta | Guardas |
|---|---|---|
| `GET` | `/api/conversations` | `api` · `auth:sanctum` · `permission:chat.use` |
| `POST` | `/api/conversations` | `api` · `auth:sanctum` · `permission:chat.use` |
| `GET` | `/api/conversations/contacts` | `api` · `auth:sanctum` · `permission:chat.use` |
| `GET` | `/api/conversations/unread-count` | `api` · `auth:sanctum` · `permission:chat.use` |
| `GET` | `/api/conversations/{conversation}` | `api` · `auth:sanctum` · `permission:chat.use` |
| `PATCH` | `/api/conversations/{conversation}` | `api` · `auth:sanctum` · `permission:chat.use` |
| `POST` | `/api/conversations/{conversation}/clear` | `api` · `auth:sanctum` · `permission:chat.use` |
| `POST` | `/api/conversations/{conversation}/events` | `api` · `auth:sanctum` · `permission:chat.use` |
| `POST` | `/api/conversations/{conversation}/link` | `api` · `auth:sanctum` · `permission:chat.use` |
| `GET` | `/api/conversations/{conversation}/links` | `api` · `auth:sanctum` · `permission:chat.use` |
| `DELETE` | `/api/conversations/{conversation}/links/{link}` | `api` · `auth:sanctum` · `permission:chat.use` |
| `GET` | `/api/conversations/{conversation}/messages` | `api` · `auth:sanctum` · `permission:chat.use` |
| `POST` | `/api/conversations/{conversation}/messages` | `api` · `auth:sanctum` · `permission:chat.use` |
| `GET` | `/api/conversations/{conversation}/messages/search` | `api` · `auth:sanctum` · `permission:chat.use` |
| `DELETE` | `/api/conversations/{conversation}/mute` | `api` · `auth:sanctum` · `permission:chat.use` |
| `POST` | `/api/conversations/{conversation}/mute` | `api` · `auth:sanctum` · `permission:chat.use` |
| `POST` | `/api/conversations/{conversation}/participants` | `api` · `auth:sanctum` · `permission:chat.use` |
| `DELETE` | `/api/conversations/{conversation}/participants/{userId}` | `api` · `auth:sanctum` · `permission:chat.use` |
| `POST` | `/api/conversations/{conversation}/read` | `api` · `auth:sanctum` · `permission:chat.use` |
| `POST` | `/api/conversations/{conversation}/typing` | `api` · `auth:sanctum` · `permission:chat.use` |

### custom-catalogs  

| Método | Ruta | Guardas |
|---|---|---|
| `GET` | `/api/custom-catalogs` | `api` · `auth:sanctum` |
| `POST` | `/api/custom-catalogs` | `api` · `auth:sanctum` |
| `GET` | `/api/custom-catalogs/options` | `api` · `auth:sanctum` |
| `GET` | `/api/custom-catalogs/options-template` | `api` · `auth:sanctum` |
| `POST` | `/api/custom-catalogs/parse-options` | `api` · `auth:sanctum` |
| `DELETE` | `/api/custom-catalogs/{customCatalog}` | `api` · `auth:sanctum` |
| `GET` | `/api/custom-catalogs/{customCatalog}` | `api` · `auth:sanctum` |
| `PUT` | `/api/custom-catalogs/{customCatalog}` | `api` · `auth:sanctum` |
| `PUT` | `/api/custom-catalogs/{customCatalog}/options` | `api` · `auth:sanctum` |
| `POST` | `/api/custom-catalogs/{customCatalog}/toggle-status` | `api` · `auth:sanctum` |

### developer  

| Método | Ruta | Guardas |
|---|---|---|
| `POST` | `/api/developer/imports/adist/ai-match` | `api` · `auth:sanctum` |
| `POST` | `/api/developer/imports/adist/commit` | `api` · `auth:sanctum` |
| `POST` | `/api/developer/imports/adist/fields` | `api` · `auth:sanctum` |
| `GET` | `/api/developer/imports/adist/options` | `api` · `auth:sanctum` |
| `POST` | `/api/developer/imports/adist/preview` | `api` · `auth:sanctum` |
| `POST` | `/api/developer/imports/adist/task` | `api` · `auth:sanctum` |
| `POST` | `/api/developer/imports/adist/task-types` | `api` · `auth:sanctum` |
| `GET` | `/api/developer/integrations` | `api` · `auth:sanctum` |
| `PUT` | `/api/developer/integrations` | `api` · `auth:sanctum` |
| `DELETE` | `/api/developer/integrations/{integration}` | `api` · `auth:sanctum` |
| `POST` | `/api/developer/integrations/{integration}/action` | `api` · `auth:sanctum` |
| `GET` | `/api/developer/integrations/{integration}/logs` | `api` · `auth:sanctum` |
| `POST` | `/api/developer/integrations/{integration}/regenerate-inbound-secret` | `api` · `auth:sanctum` |
| `POST` | `/api/developer/integrations/{integration}/test` | `api` · `auth:sanctum` |
| `GET` | `/api/developer/tokens` | `api` · `auth:sanctum` |
| `POST` | `/api/developer/tokens` | `api` · `auth:sanctum` |
| `DELETE` | `/api/developer/tokens/{id}` | `api` · `auth:sanctum` |
| `GET` | `/api/developer/webhooks` | `api` · `auth:sanctum` |
| `POST` | `/api/developer/webhooks` | `api` · `auth:sanctum` |
| `GET` | `/api/developer/webhooks/options` | `api` · `auth:sanctum` |
| `DELETE` | `/api/developer/webhooks/{webhook}` | `api` · `auth:sanctum` |
| `GET` | `/api/developer/webhooks/{webhook}` | `api` · `auth:sanctum` |
| `PATCH` | `/api/developer/webhooks/{webhook}` | `api` · `auth:sanctum` |
| `GET` | `/api/developer/webhooks/{webhook}/deliveries` | `api` · `auth:sanctum` |
| `POST` | `/api/developer/webhooks/{webhook}/regenerate-secret` | `api` · `auth:sanctum` |
| `POST` | `/api/developer/webhooks/{webhook}/test` | `api` · `auth:sanctum` |

### device-change-requests  

| Método | Ruta | Guardas |
|---|---|---|
| `GET` | `/api/device-change-requests` | `api` · `auth:sanctum` |
| `POST` | `/api/device-change-requests` | `api` · `auth:sanctum` |
| `POST` | `/api/device-change-requests/{deviceChangeRequest}/apply` | `api` · `auth:sanctum` |
| `POST` | `/api/device-change-requests/{deviceChangeRequest}/reject` | `api` · `auth:sanctum` |

### device-tokens  

| Método | Ruta | Guardas |
|---|---|---|
| `DELETE` | `/api/device-tokens` | `api` · `auth:sanctum` · `permission:chat.use` |
| `POST` | `/api/device-tokens` | `api` · `auth:sanctum` · `permission:chat.use` |

### device-types  

| Método | Ruta | Guardas |
|---|---|---|
| `POST` | `/api/device-types/{catalog}/merge` | `api` · `auth:sanctum` |
| `GET` | `/api/device-types/{catalog}/systems` | `api` · `auth:sanctum` |
| `POST` | `/api/device-types/{catalog}/systems/sync` | `api` · `auth:sanctum` |

### directories  

| Método | Ruta | Guardas |
|---|---|---|
| `GET` | `/api/directories` | `api` · `auth:sanctum` |
| `POST` | `/api/directories/{directory}/analyzer/analyze` | `api` · `auth:sanctum` |
| `POST` | `/api/directories/{directory}/analyzer/apply` | `api` · `auth:sanctum` |
| `POST` | `/api/directories/{directory}/analyzer/export` | `api` · `auth:sanctum` |
| `GET` | `/api/directories/{directory}/analyzer/fields` | `api` · `auth:sanctum` |
| `POST` | `/api/directories/{directory}/analyzer/import` | `api` · `auth:sanctum` |

### event-sla  

| Método | Ruta | Guardas |
|---|---|---|
| `GET` | `/api/event-sla` | `api` · `auth:sanctum` |
| `GET` | `/api/event-sla/settings` | `api` · `auth:sanctum` |
| `PUT` | `/api/event-sla/settings` | `api` · `auth:sanctum` |
| `DELETE` | `/api/event-sla/settings/{client}` | `api` · `auth:sanctum` |
| `PUT` | `/api/event-sla/status-tiers` | `api` · `auth:sanctum` |
| `POST` | `/api/event-sla/tiers` | `api` · `auth:sanctum` |
| `POST` | `/api/event-sla/tiers/reorder` | `api` · `auth:sanctum` |
| `DELETE` | `/api/event-sla/tiers/{tier}` | `api` · `auth:sanctum` |
| `PUT` | `/api/event-sla/tiers/{tier}` | `api` · `auth:sanctum` |

### event-statuses  

| Método | Ruta | Guardas |
|---|---|---|
| `GET` | `/api/event-statuses` | `api` · `auth:sanctum` |
| `POST` | `/api/event-statuses` | `api` · `auth:sanctum` |
| `POST` | `/api/event-statuses/reorder` | `api` · `auth:sanctum` |
| `POST` | `/api/event-statuses/transitions` | `api` · `auth:sanctum` |
| `PUT` | `/api/event-statuses/{eventStatus}` | `api` · `auth:sanctum` |
| `POST` | `/api/event-statuses/{eventStatus}/toggle-status` | `api` · `auth:sanctum` |

### event-types  

| Método | Ruta | Guardas |
|---|---|---|
| `GET` | `/api/event-types` | `api` · `auth:sanctum` |
| `POST` | `/api/event-types` | `api` · `auth:sanctum` |
| `GET` | `/api/event-types/{eventType}` | `api` · `auth:sanctum` |
| `PUT` | `/api/event-types/{eventType}` | `api` · `auth:sanctum` |
| `GET` | `/api/event-types/{eventType}/systems` | `api` · `auth:sanctum` |
| `GET` | `/api/event-types/{eventType}/systems/{system}/automations` | `api` · `auth:sanctum` |
| `POST` | `/api/event-types/{eventType}/systems/{system}/automations` | `api` · `auth:sanctum` |
| `GET` | `/api/event-types/{eventType}/systems/{system}/automations/options` | `api` · `auth:sanctum` |
| `POST` | `/api/event-types/{eventType}/systems/{system}/automations/reorder` | `api` · `auth:sanctum` |
| `DELETE` | `/api/event-types/{eventType}/systems/{system}/automations/{automation}` | `api` · `auth:sanctum` |
| `PUT` | `/api/event-types/{eventType}/systems/{system}/automations/{automation}` | `api` · `auth:sanctum` |
| `POST` | `/api/event-types/{eventType}/systems/{system}/automations/{automation}/toggle-status` | `api` · `auth:sanctum` |
| `GET` | `/api/event-types/{eventType}/systems/{system}/fields` | `api` · `auth:sanctum` |
| `POST` | `/api/event-types/{eventType}/systems/{system}/fields` | `api` · `auth:sanctum` |
| `POST` | `/api/event-types/{eventType}/systems/{system}/fields/reorder` | `api` · `auth:sanctum` |
| `DELETE` | `/api/event-types/{eventType}/systems/{system}/fields/{field}` | `api` · `auth:sanctum` |
| `PUT` | `/api/event-types/{eventType}/systems/{system}/fields/{field}` | `api` · `auth:sanctum` |
| `POST` | `/api/event-types/{eventType}/systems/{system}/fields/{field}/toggle-bitacora` | `api` · `auth:sanctum` |
| `POST` | `/api/event-types/{eventType}/systems/{system}/fields/{field}/toggle-report` | `api` · `auth:sanctum` |
| `POST` | `/api/event-types/{eventType}/systems/{system}/fields/{field}/toggle-service-sheet` | `api` · `auth:sanctum` |
| `POST` | `/api/event-types/{eventType}/systems/{system}/fields/{field}/toggle-status` | `api` · `auth:sanctum` |
| `DELETE` | `/api/event-types/{eventType}/systems/{system}/link` | `api` · `auth:sanctum` |
| `POST` | `/api/event-types/{eventType}/systems/{system}/link` | `api` · `auth:sanctum` |
| `POST` | `/api/event-types/{eventType}/toggle-status` | `api` · `auth:sanctum` |
| `GET` | `/api/event-types/{eventType}/transitions` | `api` · `auth:sanctum` |
| `POST` | `/api/event-types/{eventType}/transitions` | `api` · `auth:sanctum` |

### events  

| Método | Ruta | Guardas |
|---|---|---|
| `GET` | `/api/events` | `api` · `auth:sanctum` |
| `POST` | `/api/events` | `api` · `auth:sanctum` |
| `GET` | `/api/events/bitacora` | `api` · `auth:sanctum` |
| `GET` | `/api/events/bitacora-pdf` | `api` · `auth:sanctum` |
| `GET` | `/api/events/dashboard` | `api` · `auth:sanctum` |
| `GET` | `/api/events/device-filters` | `api` · `auth:sanctum` |
| `GET` | `/api/events/devices` | `api` · `auth:sanctum` |
| `GET` | `/api/events/directory-fields` | `api` · `auth:sanctum` |
| `GET` | `/api/events/export` | `api` · `auth:sanctum` |
| `GET` | `/api/events/form-fields` | `api` · `auth:sanctum` |
| `GET` | `/api/events/plan-devices` | `api` · `auth:sanctum` |
| `GET` | `/api/events/report-list` | `api` · `auth:sanctum` |
| `GET` | `/api/events/report-pdf` | `api` · `auth:sanctum` |
| `POST` | `/api/events/service-sheets` | `api` · `auth:sanctum` |
| `GET` | `/api/events/service-sheets/{serviceSheetExport}` | `api` · `auth:sanctum` |
| `GET` | `/api/events/service-sheets/{serviceSheetExport}/download` | `api` · `auth:sanctum` |
| `GET` | `/api/events/sites` | `api` · `auth:sanctum` |
| `GET` | `/api/events/sla-context` | `api` · `auth:sanctum` |
| `GET` | `/api/events/sync-bundle` | `api` · `auth:sanctum` |
| `GET` | `/api/events/{event}` | `api` · `auth:sanctum` |
| `PUT` | `/api/events/{event}` | `api` · `auth:sanctum` |
| `POST` | `/api/events/{event}/ai-summary` | `api` · `auth:sanctum` |
| `POST` | `/api/events/{event}/archive` | `api` · `auth:sanctum` |
| `POST` | `/api/events/{event}/assign` | `api` · `auth:sanctum` |
| `GET` | `/api/events/{event}/assignable` | `api` · `auth:sanctum` |
| `GET` | `/api/events/{event}/comments` | `api` · `auth:sanctum` |
| `POST` | `/api/events/{event}/comments` | `api` · `auth:sanctum` |
| `DELETE` | `/api/events/{event}/comments/{comment}` | `api` · `auth:sanctum` |
| `PUT` | `/api/events/{event}/comments/{comment}` | `api` · `auth:sanctum` |
| `GET` | `/api/events/{event}/conversation` | `api` · `auth:sanctum` · `permission:chat.use` |
| `POST` | `/api/events/{event}/diagnose` | `api` · `auth:sanctum` |
| `GET` | `/api/events/{event}/mentionable-users` | `api` · `auth:sanctum` |
| `POST` | `/api/events/{event}/restore` | `api` · `auth:sanctum` |
| `GET` | `/api/events/{event}/service-sheet` | `api` · `auth:sanctum` |
| `POST` | `/api/events/{event}/status` | `api` · `auth:sanctum` |

### forgot-password  

| Método | Ruta | Guardas |
|---|---|---|
| `POST` | `/api/forgot-password` | `api` |

### impersonate  

| Método | Ruta | Guardas |
|---|---|---|
| `POST` | `/api/impersonate/stop` | `api` · `auth:sanctum` |
| `GET` | `/api/impersonate/users` | `api` · `auth:sanctum` |
| `POST` | `/api/impersonate/{user}` | `api` · `auth:sanctum` |

### integrations  

| Método | Ruta | Guardas |
|---|---|---|
| `POST` | `/api/integrations/{provider}/inbound` | `api` |

### knowledge  

| Método | Ruta | Guardas |
|---|---|---|
| `GET` | `/api/knowledge` | `api` · `auth:sanctum` |
| `POST` | `/api/knowledge` | `api` · `auth:sanctum` |
| `GET` | `/api/knowledge/articles` | `api` · `auth:sanctum` |
| `GET` | `/api/knowledge/articles/search` | `api` · `auth:sanctum` |
| `GET` | `/api/knowledge/articles/{document}` | `api` · `auth:sanctum` |
| `GET` | `/api/knowledge/options` | `api` · `auth:sanctum` |
| `GET` | `/api/knowledge/topics` | `api` · `auth:sanctum` |
| `DELETE` | `/api/knowledge/{document}` | `api` · `auth:sanctum` |
| `PUT` | `/api/knowledge/{document}` | `api` · `auth:sanctum` |
| `GET` | `/api/knowledge/{document}/download` | `api` · `auth:sanctum` |
| `POST` | `/api/knowledge/{document}/reingest` | `api` · `auth:sanctum` |

### login  

| Método | Ruta | Guardas |
|---|---|---|
| `POST` | `/api/login` | `api` |

### logout  

| Método | Ruta | Guardas |
|---|---|---|
| `POST` | `/api/logout` | `api` · `auth:sanctum` |

### maintenances  

| Método | Ruta | Guardas |
|---|---|---|
| `GET` | `/api/maintenances/report` | `api` · `auth:sanctum` |
| `GET` | `/api/maintenances/report/export` | `api` · `auth:sanctum` |
| `GET` | `/api/maintenances/report/list` | `api` · `auth:sanctum` |
| `GET` | `/api/maintenances/report/pdf` | `api` · `auth:sanctum` |
| `GET` | `/api/maintenances/{maintenance}` | `api` · `auth:sanctum` |
| `GET` | `/api/maintenances/{maintenance}/action-plan` | `api` · `auth:sanctum` |
| `GET` | `/api/maintenances/{maintenance}/action-plan/agenda` | `api` · `auth:sanctum` |
| `POST` | `/api/maintenances/{maintenance}/action-plan/agenda` | `api` · `auth:sanctum` |
| `GET` | `/api/maintenances/{maintenance}/action-plan/agenda-options` | `api` · `auth:sanctum` |
| `PUT` | `/api/maintenances/{maintenance}/action-plan/rules` | `api` · `auth:sanctum` |
| `POST` | `/api/maintenances/{maintenance}/activities` | `api` · `auth:sanctum` |
| `GET` | `/api/maintenances/{maintenance}/activities/export` | `api` · `auth:sanctum` |
| `DELETE` | `/api/maintenances/{maintenance}/activities/{activity}` | `api` · `auth:sanctum` |
| `GET` | `/api/maintenances/{maintenance}/activities/{activity}` | `api` · `auth:sanctum` |
| `PUT` | `/api/maintenances/{maintenance}/activities/{activity}` | `api` · `auth:sanctum` |
| `POST` | `/api/maintenances/{maintenance}/activities/{activity}/retype` | `api` · `auth:sanctum` |
| `GET` | `/api/maintenances/{maintenance}/activities/{activity}/transfer-options` | `api` · `auth:sanctum` |
| `POST` | `/api/maintenances/{maintenance}/activities/{activity}/transfer-to-event` | `api` · `auth:sanctum` |
| `GET` | `/api/maintenances/{maintenance}/activity-counts` | `api` · `auth:sanctum` |
| `GET` | `/api/maintenances/{maintenance}/activity-devices` | `api` · `auth:sanctum` |
| `GET` | `/api/maintenances/{maintenance}/activity-types` | `api` · `auth:sanctum` |
| `POST` | `/api/maintenances/{maintenance}/archive` | `api` · `auth:sanctum` |
| `GET` | `/api/maintenances/{maintenance}/contract-dashboard` | `api` · `auth:sanctum` |
| `GET` | `/api/maintenances/{maintenance}/contract-files` | `api` · `auth:sanctum` |
| `POST` | `/api/maintenances/{maintenance}/contract-files` | `api` · `auth:sanctum` |
| `DELETE` | `/api/maintenances/{maintenance}/contract-files/{file}` | `api` · `auth:sanctum` |
| `GET` | `/api/maintenances/{maintenance}/conversation` | `api` · `auth:sanctum` · `permission:chat.use` |
| `GET` | `/api/maintenances/{maintenance}/dashboard` | `api` · `auth:sanctum` |
| `GET` | `/api/maintenances/{maintenance}/device-schedules` | `api` · `auth:sanctum` |
| `POST` | `/api/maintenances/{maintenance}/device-schedules` | `api` · `auth:sanctum` |
| `DELETE` | `/api/maintenances/{maintenance}/device-schedules/{schedule}` | `api` · `auth:sanctum` |
| `PUT` | `/api/maintenances/{maintenance}/device-schedules/{schedule}` | `api` · `auth:sanctum` |
| `GET` | `/api/maintenances/{maintenance}/devices/{device}/activities` | `api` · `auth:sanctum` |
| `GET` | `/api/maintenances/{maintenance}/floor-plans` | `api` · `auth:sanctum` |
| `GET` | `/api/maintenances/{maintenance}/frequencies` | `api` · `auth:sanctum` |
| `POST` | `/api/maintenances/{maintenance}/frequencies/sync` | `api` · `auth:sanctum` |
| `GET` | `/api/maintenances/{maintenance}/log` | `api` · `auth:sanctum` |
| `GET` | `/api/maintenances/{maintenance}/log-pdf` | `api` · `auth:sanctum` |
| `POST` | `/api/maintenances/{maintenance}/restore` | `api` · `auth:sanctum` |
| `GET` | `/api/v1/maintenances` | `api` · `auth:sanctum` |
| `GET` | `/api/v1/maintenances/{maintenance}` | `api` · `auth:sanctum` |
| `POST` | `/api/v1/maintenances/{maintenance}/activities` | `api` · `auth:sanctum` · `App\Http\Middleware\RequireWriteScope` |
| `GET` | `/api/v1/maintenances/{maintenance}/activities/export` | `api` · `auth:sanctum` |
| `GET` | `/api/v1/maintenances/{maintenance}/activities/{activity}` | `api` · `auth:sanctum` |
| `PUT` | `/api/v1/maintenances/{maintenance}/activities/{activity}` | `api` · `auth:sanctum` · `App\Http\Middleware\RequireWriteScope` |
| `GET` | `/api/v1/maintenances/{maintenance}/activity-counts` | `api` · `auth:sanctum` |
| `GET` | `/api/v1/maintenances/{maintenance}/activity-devices` | `api` · `auth:sanctum` |
| `GET` | `/api/v1/maintenances/{maintenance}/activity-types` | `api` · `auth:sanctum` |
| `GET` | `/api/v1/maintenances/{maintenance}/contract-dashboard` | `api` · `auth:sanctum` |
| `GET` | `/api/v1/maintenances/{maintenance}/dashboard` | `api` · `auth:sanctum` |
| `GET` | `/api/v1/maintenances/{maintenance}/devices/{device}/activities` | `api` · `auth:sanctum` |
| `GET` | `/api/v1/maintenances/{maintenance}/log` | `api` · `auth:sanctum` |

### mcp  

| Método | Ruta | Guardas |
|---|---|---|
| `POST` | `/api/mcp` | `api` · `auth:sanctum` |

### me  

| Método | Ruta | Guardas |
|---|---|---|
| `GET` | `/api/me` | `api` · `auth:sanctum` |
| `GET` | `/api/me/notification-preferences` | `api` · `auth:sanctum` |
| `PUT` | `/api/me/notification-preferences` | `api` · `auth:sanctum` |
| `GET` | `/api/me/preferences/{key}` | `api` · `auth:sanctum` |
| `PUT` | `/api/me/preferences/{key}` | `api` · `auth:sanctum` |
| `GET` | `/api/v1/me` | `api` · `auth:sanctum` |

### media  

| Método | Ruta | Guardas |
|---|---|---|
| `DELETE` | `/api/media` | `api` · `auth:sanctum` |
| `POST` | `/api/media/upload` | `api` · `auth:sanctum` |

### messages  

| Método | Ruta | Guardas |
|---|---|---|
| `DELETE` | `/api/messages/{message}` | `api` · `auth:sanctum` · `permission:chat.use` |

### my-maintenances  

| Método | Ruta | Guardas |
|---|---|---|
| `GET` | `/api/my-maintenances` | `api` · `auth:sanctum` |
| `POST` | `/api/my-maintenances` | `api` · `auth:sanctum` |

### notifications  

| Método | Ruta | Guardas |
|---|---|---|
| `GET` | `/api/notifications` | `api` · `auth:sanctum` |
| `POST` | `/api/notifications/read-all` | `api` · `auth:sanctum` |
| `GET` | `/api/notifications/unread-count` | `api` · `auth:sanctum` |
| `POST` | `/api/notifications/{notification}/read` | `api` · `auth:sanctum` |

### permissions  

| Método | Ruta | Guardas |
|---|---|---|
| `GET` | `/api/permissions` | `api` · `auth:sanctum` |
| `GET` | `/api/permissions/flat` | `api` · `auth:sanctum` |

### portal  

| Método | Ruta | Guardas |
|---|---|---|
| `GET` | `/api/portal/knowledge` | `api` · `auth:sanctum` |
| `GET` | `/api/portal/knowledge/search` | `api` · `auth:sanctum` |
| `GET` | `/api/portal/knowledge/topics` | `api` · `auth:sanctum` |
| `GET` | `/api/portal/knowledge/{document}` | `api` · `auth:sanctum` |

### profile  

| Método | Ruta | Guardas |
|---|---|---|
| `GET` | `/api/profile` | `api` · `auth:sanctum` |
| `PUT` | `/api/profile` | `api` · `auth:sanctum` |

### report-sections  

| Método | Ruta | Guardas |
|---|---|---|
| `GET` | `/api/report-sections/catalog` | `api` · `auth:sanctum` |
| `GET` | `/api/report-sections/{scopeType}/{scopeId}` | `api` · `auth:sanctum` |
| `PUT` | `/api/report-sections/{scopeType}/{scopeId}` | `api` · `auth:sanctum` |

### report-templates  

| Método | Ruta | Guardas |
|---|---|---|
| `GET` | `/api/report-templates` | `api` · `auth:sanctum` |
| `POST` | `/api/report-templates` | `api` · `auth:sanctum` |
| `DELETE` | `/api/report-templates/{reportExportTemplate}` | `api` · `auth:sanctum` |
| `PUT` | `/api/report-templates/{reportExportTemplate}` | `api` · `auth:sanctum` |

### reports  

| Método | Ruta | Guardas |
|---|---|---|
| `GET` | `/api/reports/executive/options` | `api` · `auth:sanctum` |
| `GET` | `/api/reports/executive/pdf` | `api` · `auth:sanctum` |
| `GET` | `/api/reports/executive/preview` | `api` · `auth:sanctum` |
| `GET` | `/api/reports/executive/sites` | `api` · `auth:sanctum` |
| `POST` | `/api/reports/executive/templates` | `api` · `auth:sanctum` |
| `DELETE` | `/api/reports/executive/templates/{template}` | `api` · `auth:sanctum` |
| `GET` | `/api/reports/personnel` | `api` · `auth:sanctum` |
| `GET` | `/api/reports/personnel/pdf` | `api` · `auth:sanctum` |

### reset-password  

| Método | Ruta | Guardas |
|---|---|---|
| `POST` | `/api/reset-password` | `api` |

### roles  

| Método | Ruta | Guardas |
|---|---|---|
| `GET` | `/api/roles` | `api` · `auth:sanctum` |
| `POST` | `/api/roles` | `api` · `auth:sanctum` |
| `DELETE` | `/api/roles/{role}` | `api` · `auth:sanctum` |
| `GET` | `/api/roles/{role}` | `api` · `auth:sanctum` |
| `PUT|PATCH` | `/api/roles/{role}` | `api` · `auth:sanctum` |
| `POST` | `/api/roles/{role}/permissions` | `api` · `auth:sanctum` |
| `POST` | `/api/roles/{role}/restore` | `api` · `auth:sanctum` |

### settings  

| Método | Ruta | Guardas |
|---|---|---|
| `GET` | `/api/settings` | `api` · `auth:sanctum` |
| `PUT` | `/api/settings` | `api` · `auth:sanctum` |
| `GET` | `/api/settings/public` | `api` |
| `GET` | `/api/settings/tenants` | `api` · `auth:sanctum` |
| `POST` | `/api/settings/test-mail` | `api` · `auth:sanctum` |

### sites  

| Método | Ruta | Guardas |
|---|---|---|
| `GET` | `/api/sites` | `api` · `auth:sanctum` |

### snapshots  

| Método | Ruta | Guardas |
|---|---|---|
| `GET` | `/api/snapshots` | `api` · `auth:sanctum` |
| `POST` | `/api/snapshots` | `api` · `auth:sanctum` |
| `GET` | `/api/snapshots/import-progress` | `api` · `signed` |
| `POST` | `/api/snapshots/upload` | `api` · `auth:sanctum` |
| `DELETE` | `/api/snapshots/{name}` | `api` · `auth:sanctum` |
| `GET` | `/api/snapshots/{name}/download-link` | `api` · `auth:sanctum` |
| `GET` | `/api/snapshots/{name}/file` | `api` · `signed` |
| `POST` | `/api/snapshots/{name}/import` | `api` · `auth:sanctum` |

### solicitantes  

| Método | Ruta | Guardas |
|---|---|---|
| `GET` | `/api/solicitantes` | `api` · `auth:sanctum` |
| `POST` | `/api/solicitantes` | `api` · `auth:sanctum` |
| `POST` | `/api/solicitantes/import` | `api` · `auth:sanctum` |
| `GET` | `/api/solicitantes/import/template` | `api` · `auth:sanctum` |
| `DELETE` | `/api/solicitantes/{user}` | `api` · `auth:sanctum` |
| `PUT` | `/api/solicitantes/{user}` | `api` · `auth:sanctum` |
| `POST` | `/api/solicitantes/{user}/access-link` | `api` · `auth:sanctum` |

### systems  

| Método | Ruta | Guardas |
|---|---|---|
| `GET` | `/api/systems/{system}/activity-types` | `api` · `auth:sanctum` |
| `GET` | `/api/systems/{system}/device-types` | `api` · `auth:sanctum` |
| `GET` | `/api/systems/{system}/device-types/active` | `api` · `auth:sanctum` |
| `POST` | `/api/systems/{system}/device-types/sync` | `api` · `auth:sanctum` |
| `GET` | `/api/systems/{system}/fields` | `api` · `auth:sanctum` |
| `POST` | `/api/systems/{system}/fields` | `api` · `auth:sanctum` |
| `POST` | `/api/systems/{system}/fields/reorder` | `api` · `auth:sanctum` |
| `DELETE` | `/api/systems/{system}/fields/{field}` | `api` · `auth:sanctum` |
| `PUT` | `/api/systems/{system}/fields/{field}` | `api` · `auth:sanctum` |
| `GET` | `/api/systems/{system}/fields/{field}/impact` | `api` · `auth:sanctum` |
| `POST` | `/api/systems/{system}/fields/{field}/toggle-bitacora` | `api` · `auth:sanctum` |
| `POST` | `/api/systems/{system}/fields/{field}/toggle-dashboard` | `api` · `auth:sanctum` |
| `POST` | `/api/systems/{system}/fields/{field}/toggle-event-report` | `api` · `auth:sanctum` |
| `POST` | `/api/systems/{system}/fields/{field}/toggle-service-sheet` | `api` · `auth:sanctum` |
| `POST` | `/api/systems/{system}/fields/{field}/toggle-status` | `api` · `auth:sanctum` |
| `GET` | `/api/systems/{system}/frequencies` | `api` · `auth:sanctum` |
| `POST` | `/api/systems/{system}/frequencies/sync` | `api` · `auth:sanctum` |
| `GET` | `/api/systems/{system}/task-durations` | `api` · `auth:sanctum` |
| `POST` | `/api/systems/{system}/task-durations/sync` | `api` · `auth:sanctum` |

### telegram  

| Método | Ruta | Guardas |
|---|---|---|
| `POST` | `/api/telegram/webhook/{channel}` | `api` |

### users  

| Método | Ruta | Guardas |
|---|---|---|
| `GET` | `/api/users` | `api` · `auth:sanctum` |
| `POST` | `/api/users` | `api` · `auth:sanctum` |
| `DELETE` | `/api/users/{user}` | `api` · `auth:sanctum` |
| `GET` | `/api/users/{user}` | `api` · `auth:sanctum` |
| `PUT|PATCH` | `/api/users/{user}` | `api` · `auth:sanctum` |
| `POST` | `/api/users/{user}/access-link` | `api` · `auth:sanctum` |
| `POST` | `/api/users/{user}/permissions` | `api` · `auth:sanctum` |
| `POST` | `/api/users/{user}/restore` | `api` · `auth:sanctum` |
| `POST` | `/api/users/{user}/send-temp-password` | `api` · `auth:sanctum` |
| `POST` | `/api/users/{user}/toggle-status` | `api` · `auth:sanctum` |

### whatsapp  

| Método | Ruta | Guardas |
|---|---|---|
| `GET` | `/api/whatsapp/webhook` | `api` |
| `POST` | `/api/whatsapp/webhook` | `api` |

### work-calendar  

| Método | Ruta | Guardas |
|---|---|---|
| `GET` | `/api/work-calendar` | `api` · `auth:sanctum` |
| `PUT` | `/api/work-calendar` | `api` · `auth:sanctum` |
| `POST` | `/api/work-calendar/holidays` | `api` · `auth:sanctum` |
| `POST` | `/api/work-calendar/holidays/bulk` | `api` · `auth:sanctum` |
| `GET` | `/api/work-calendar/holidays/suggest` | `api` · `auth:sanctum` |
| `DELETE` | `/api/work-calendar/holidays/{holiday}` | `api` · `auth:sanctum` |



## 3. Permisos

De la tabla `permissions`, que es la fuente de verdad: **no están en el código**.

| Llave | Nombre |
|---|---|
| `users.view` | — |
| `users.create` | — |
| `users.edit` | — |
| `users.toggle-status` | — |
| `users.send-temp-password` | — |
| `users.assign-permissions` | — |
| `roles.view` | — |
| `roles.create` | — |
| `roles.edit` | — |
| `roles.assign-permissions` | — |
| `permissions.view` | — |
| `profile.view` | — |
| `profile.edit` | — |
| `clients.view` | — |
| `clients.create` | — |
| `clients.edit` | — |
| `clients.toggle-status` | — |
| `sites.view` | — |
| `sites.create` | — |
| `sites.edit` | — |
| `sites.toggle-status` | — |
| `catalogs.view` | — |
| `catalogs.create` | — |
| `catalogs.edit` | — |
| `client-admins.view` | — |
| `client-admins.assign` | — |
| `client-admins.remove` | — |
| `site-admins.view` | — |
| `site-admins.assign` | — |
| `site-admins.remove` | — |
| `system-config.view` | — |
| `system-config.manage` | — |
| `directories.view` | — |
| `directories.create` | — |
| `directories.edit` | — |
| `directories.toggle-status` | — |
| `devices.view` | — |
| `devices.create` | — |
| `devices.edit` | — |
| `devices.toggle-status` | — |
| `maintenances.view` | — |
| `maintenances.create` | — |
| `maintenances.edit` | — |
| `maintenances.assign-engineers` | — |
| `maintenances.record-activity` | — |
| `activities.view-registration-date` | — |
| `config.manage` | — |
| `client-engineers.view` | — |
| `client-engineers.assign` | — |
| `client-engineers.remove` | — |
| `site-engineers.view` | — |
| `site-engineers.assign` | — |
| `site-engineers.remove` | — |
| `floor-plans.view` | — |
| `floor-plans.manage` | — |
| `events.view` | — |
| `events.create` | — |
| `events.fill-form` | — |
| `events.change-status` | — |
| `events.assign` | — |
| `maintenances.action-plan` | — |
| `manuals.view` | — |
| `users.archive` | — |
| `roles.archive` | — |
| `clients.archive` | — |
| `sites.archive` | — |
| `devices.import` | — |
| `devices.export` | — |
| `floor-plans.place` | — |
| `catalogs.toggle-status` | — |
| `catalogs.merge-device-types` | — |
| `activity-types.configure` | — |
| `event-config.manage` | — |
| `event-sla.manage` | — |
| `events.comment` | — |
| `maintenances.manage-contract` | — |
| `maintenances.schedule-devices` | — |
| `channels.manage` | — |
| `knowledge.manage` | — |
| `solicitantes.view` | — |
| `solicitantes.create` | — |
| `solicitantes.edit` | — |
| `solicitantes.delete` | — |
| `chat.use` | — |
| `chat.group-manage` | — |
| `chat.all-conversations` | — |
| `devices.archive` | — |
| `webhooks.view` | — |
| `webhooks.manage` | — |
| `integrations.view` | — |
| `integrations.manage` | — |
| `events.archive` | — |
| `maintenances.archive` | — |
| `integrations.import-external` | — |
| `devices.request-change` | — |
| `devices.apply-change` | — |
| `activities.set-execution-date` | — |
| `maintenances.report` | — |
| `audit.view` | — |
| `snapshot.manage` | — |
| `report-sections.manage` | — |
| `reports.executive` | — |
| `reports.personnel` | — |
| `app-errors.view` | — |
| `app-errors.resolve` | — |


## 4. Esquema de datos — `mantenimientos`

Tablas reales de la base, con sus columnas. **Salen del esquema, no de las
migraciones**: reflejan lo que hay, no lo que se pretendía.

- **`activity_logs`** — id (núm), user_id (núm, opcional), impersonator_id (núm, opcional), source (texto), module (texto, opcional), action (texto), description (texto largo, opcional), subject_type (texto, opcional), subject_id (núm, opcional), subject_label (texto, opcional), properties (json, opcional), method (texto, opcional), route (texto, opcional), path (texto, opcional), status (núm, opcional), ip (texto, opcional), user_agent (texto, opcional), created_at (fecha, opcional)
- **`activity_type_automations`** — id (núm), activity_type_id (núm), system_id (núm), name (texto), is_active (sí/no), sort_order (núm), trigger (json, opcional), action_type (texto), target_activity_type_id (núm, opcional), target_event_type_id (núm, opcional), prefill (json, opcional), created_at (fecha, opcional), updated_at (fecha, opcional)
- **`activity_type_fields`** — id (núm), activity_type_id (núm), system_id (núm), label (texto), field_key (texto), field_type (texto), catalog_type (texto, opcional), is_required (sí/no), max_length (núm, opcional), sort_order (núm), is_active (sí/no), created_at (fecha, opcional), updated_at (fecha, opcional), show_in_bitacora (sí/no), legend_text (texto largo, opcional), rules (json, opcional), visibility (json, opcional), config (json, opcional)
- **`activity_type_systems`** — id (núm), activity_type_id (núm), system_id (núm), created_at (fecha, opcional), updated_at (fecha, opcional)
- **`ai_document_chunks`** — id (núm), ai_document_id (núm), idx (núm), heading (texto, opcional), content (texto largo), embedding (json), embedding_model (texto, opcional), created_at (fecha, opcional), updated_at (fecha, opcional), collection (texto), catalog_id (núm, opcional), client_id (núm, opcional), audience (texto)
- **`ai_documents`** — id (núm), title (texto), source (texto), kind (texto), chunks_count (núm), created_at (fecha, opcional), updated_at (fecha, opcional), collection (texto), catalog_id (núm, opcional), client_id (núm, opcional), audience (texto), original_filename (texto, opcional), file_path (texto, opcional), status (texto), error (texto largo, opcional), is_active (sí/no), structured (sí/no), created_by (núm, opcional), body_md (texto largo, opcional)
- **`ai_feedbacks`** — id (núm), ai_interaction_id (núm), user_id (núm, opcional), rating (texto), comment (texto largo, opcional), created_at (fecha, opcional), updated_at (fecha, opcional)
- **`ai_interactions`** — id (núm), conversation_id (uuid, opcional), user_id (núm, opcional), prompt (texto largo), reply (texto largo, opcional), provider (texto, opcional), model (texto, opcional), input_tokens (núm), output_tokens (núm), cost_usd (decimal), price_in (decimal, opcional), price_out (decimal, opcional), duration_ms (núm), iterations (núm), actions (json, opcional), status (texto), error (texto largo, opcional), created_at (fecha, opcional), updated_at (fecha, opcional), source (texto)
- **`ai_reports`** — id (núm), user_id (núm, opcional), conversation_id (uuid, opcional), title (texto), html (texto largo), created_at (fecha, opcional), updated_at (fecha, opcional)
- **`app_error_logs`** — id (núm), user_id (núm, opcional), device_id (texto, opcional), device_name (texto, opcional), device_model (texto, opcional), platform (texto, opcional), os_version (texto, opcional), app_version (texto, opcional), build (texto, opcional), kind (texto), context (texto, opcional), message (texto), detail (texto largo, opcional), http_method (texto, opcional), http_url (texto, opcional), http_status (núm, opcional), fingerprint (texto), occurred_at (fecha, opcional), created_at (fecha, opcional), resolved_at (fecha, opcional), resolved_by (núm, opcional), resolution_note (texto largo, opcional)
- **`app_settings`** — key (texto), value (texto largo, opcional), updated_at (fecha, opcional), tenant (texto)
- **`capture_agent_rules`** — id (núm), scope (texto), channel_id (núm, opcional), catalog_id (núm, opcional), title (texto, opcional), instruction (texto largo), example_bad (texto largo, opcional), example_good (texto largo, opcional), is_active (sí/no), sort_order (núm), created_by (núm, opcional), source_conversation_id (núm, opcional), source_context (json, opcional), created_at (fecha, opcional), updated_at (fecha, opcional), ai_score (núm, opcional), ai_review (json, opcional)
- **`capture_contacts`** — id (núm), channel_id (núm), external_id (texto, opcional), name (texto, opcional), created_at (fecha, opcional), updated_at (fecha, opcional), client_id (núm, opcional), site_id (núm, opcional), username (texto, opcional), pre_registered (sí/no), user_id (núm, opcional)
- **`capture_conversations`** — id (núm), channel_id (núm), contact_id (núm), status (texto), state (json, opcional), event_id (núm, opcional), last_message_at (fecha, opcional), created_at (fecha, opcional), updated_at (fecha, opcional), handling (texto), assigned_agent_id (núm, opcional), context_summary (texto largo, opcional), unread_count (núm), last_inbound_at (fecha, opcional), is_simulation (sí/no)
- **`capture_messages`** — id (núm), conversation_id (núm), channel_id (núm), direction (texto), external_message_id (texto, opcional), body (texto largo, opcional), payload (json, opcional), created_at (fecha, opcional), sender_user_id (núm, opcional)
- **`catalogs`** — id (núm), type (texto), label (texto), sort_order (núm), is_active (sí/no), created_at (fecha, opcional), updated_at (fecha, opcional), nomenclatura (texto, opcional)
- **`channels`** — id (núm), name (texto), provider (texto), client_id (núm, opcional), access_token (texto largo, opcional), phone_number_id (texto, opcional), default_event_type_id (núm, opcional), default_system_id (núm, opcional), created_by_user_id (núm, opcional), agent_name (texto), instructions (texto largo, opcional), ai_enabled (sí/no), is_active (sí/no), metadata (json, opcional), created_at (fecha, opcional), updated_at (fecha, opcional), first_level_support (texto), require_registered (sí/no)
- **`client_engineers`** — client_id (núm), user_id (núm), created_at (fecha, opcional), updated_at (fecha, opcional)
- **`client_user`** — client_id (núm), user_id (núm), created_at (fecha, opcional), updated_at (fecha, opcional)
- **`clients`** — id (núm), name (texto), short_name (texto, opcional), rfc (texto, opcional), industry (texto, opcional), contact_name (texto, opcional), contact_email (texto, opcional), contact_phone (texto, opcional), is_active (sí/no), notes (texto largo, opcional), created_by (núm, opcional), created_at (fecha, opcional), updated_at (fecha, opcional), deleted_at (fecha, opcional), event_folio_config (json, opcional)
- **`conversation_links`** — id (núm), conversation_id (núm), linkable_type (texto), linkable_id (núm), created_by (núm), created_at (fecha, opcional), updated_at (fecha, opcional)
- **`conversation_participants`** — id (núm), conversation_id (núm), user_id (núm), role (texto), joined_at (fecha, opcional), left_at (fecha, opcional), last_read_message_id (núm, opcional), muted_until (fecha, opcional), created_at (fecha, opcional), updated_at (fecha, opcional), cleared_before_message_id (núm, opcional)
- **`conversations`** — id (núm), type (texto), name (texto, opcional), avatar_url (texto, opcional), created_by (núm), client_id (núm, opcional), direct_key (texto, opcional), last_message_at (fecha, opcional), created_at (fecha, opcional), updated_at (fecha, opcional), deleted_at (fecha, opcional)
- **`custom_catalogs`** — id (núm), client_id (núm, opcional), name (texto), description (texto, opcional), options (json), is_active (sí/no), created_by (núm, opcional), created_at (fecha, opcional), updated_at (fecha, opcional)
- **`device_change_requests`** — id (núm), device_id (núm), event_id (núm, opcional), requested_by (núm), changes (json), note (texto largo, opcional), status (texto), reviewed_by (núm, opcional), reviewed_at (fecha, opcional), review_note (texto largo, opcional), created_at (fecha, opcional), updated_at (fecha, opcional)
- **`device_field_values`** — id (núm), device_id (núm), system_field_id (núm), field_key (texto), value_text (texto largo, opcional), value_number (decimal, opcional), value_date (fecha, opcional), value_boolean (sí/no, opcional), created_at (fecha, opcional), updated_at (fecha, opcional)
- **`device_placements`** — id (núm), floor_plan_id (núm), device_id (núm), x (decimal), y (decimal), created_by (núm, opcional), created_at (fecha, opcional), updated_at (fecha, opcional)
- **`device_schedules`** — id (núm), maintenance_id (núm), device_id (núm), scheduled_date (fecha), created_by (núm, opcional), created_at (fecha, opcional), updated_at (fecha, opcional)
- **`device_tokens`** — id (núm), user_id (núm), token (texto), platform (texto), provider (texto), app_version (texto, opcional), device_name (texto, opcional), last_seen_at (fecha, opcional), created_at (fecha, opcional), updated_at (fecha, opcional)
- **`devices`** — id (núm), directory_id (núm), name (texto, opcional), device_type (texto, opcional), brand (texto, opcional), model (texto, opcional), serial_number (texto, opcional), location (texto, opcional), status (texto, opcional), notes (texto largo, opcional), is_active (sí/no), created_by (núm, opcional), created_at (fecha, opcional), updated_at (fecha, opcional), custom_fields (json, opcional), archived_at (fecha, opcional)
- **`directories`** — id (núm), site_id (núm), catalog_id (núm), name (texto, opcional), notes (texto largo, opcional), is_active (sí/no), created_by (núm, opcional), created_at (fecha, opcional), updated_at (fecha, opcional)
- **`event_automation_runs`** — id (núm), event_automation_id (núm), event_id (núm), status (texto), result (json, opcional), error (texto largo, opcional), ran_at (fecha)
- **`event_comment_mentions`** — id (núm), comment_id (núm), user_id (núm), created_at (fecha, opcional), updated_at (fecha, opcional)
- **`event_comments`** — id (núm), event_id (núm), user_id (núm), parent_id (núm, opcional), body (texto largo), deleted_at (fecha, opcional), created_at (fecha, opcional), updated_at (fecha, opcional)
- **`event_folio_counters`** — id (núm), client_id (núm), period (texto), last_seq (núm), created_at (fecha, opcional), updated_at (fecha, opcional)
- **`event_sla_settings`** — id (núm), client_id (núm, opcional), enabled (sí/no), matrix (json), priorities (json), calendar (json), created_at (fecha, opcional), updated_at (fecha, opcional)
- **`event_sla_tiers`** — id (núm), key (texto), label (texto), sort_order (núm), is_active (sí/no), created_at (fecha, opcional), updated_at (fecha, opcional)
- **`event_status_history`** — id (núm), event_id (núm), from_status_id (núm, opcional), to_status_id (núm), user_id (núm), note (texto largo, opcional), created_at (fecha)
- **`event_status_transitions`** — id (núm), from_status_id (núm), to_status_id (núm), created_at (fecha, opcional), updated_at (fecha, opcional)
- **`event_statuses`** — id (núm), key (texto), label (texto), color (texto), category (texto), is_initial (sí/no), is_terminal (sí/no), sort_order (núm), is_active (sí/no), created_at (fecha, opcional), updated_at (fecha, opcional), category_id (núm, opcional), requires_form (sí/no), requires_note (sí/no), sla_tier_id (núm, opcional)
- **`event_type_automations`** — id (núm), event_type_id (núm), system_id (núm), name (texto), is_active (sí/no), sort_order (núm), event (texto), status_key (texto, opcional), trigger (json, opcional), action_kind (texto), provider (texto, opcional), action (texto, opcional), params_map (json, opcional), result_target (texto, opcional), internal_action (texto, opcional), internal_config (json, opcional), target_event_type_id (núm, opcional), prefill (json, opcional), run_once (sí/no), created_at (fecha, opcional), updated_at (fecha, opcional), lines_map (json, opcional)
- **`event_type_fields`** — id (núm), event_type_id (núm), system_id (núm), label (texto), field_key (texto), field_type (texto), catalog_type (texto, opcional), legend_text (texto largo, opcional), rules (json, opcional), visibility (json, opcional), config (json, opcional), is_required (sí/no), max_length (núm, opcional), sort_order (núm), is_active (sí/no), created_at (fecha, opcional), updated_at (fecha, opcional), show_in_report (sí/no), show_in_service_sheet (sí/no), show_in_bitacora (sí/no)
- **`event_type_systems`** — id (núm), event_type_id (núm), system_id (núm), created_at (fecha, opcional), updated_at (fecha, opcional)
- **`event_type_transitions`** — id (núm), event_type_id (núm), from_status_id (núm), to_status_id (núm), created_at (fecha, opcional), updated_at (fecha, opcional)
- **`event_types`** — id (núm), label (texto), nature (texto), color (texto), default_priority (texto), sort_order (núm), is_active (sí/no), created_by (núm, opcional), created_at (fecha, opcional), updated_at (fecha, opcional)
- **`events`** — id (núm), folio (texto), client_id (núm), site_id (núm), system_id (núm), event_type_id (núm), device_id (núm, opcional), status_id (núm), priority (texto), description (texto largo), field_values (json, opcional), created_by (núm), assigned_to (núm, opcional), occurred_at (fecha, opcional), created_at (fecha, opcional), updated_at (fecha, opcional), client_uuid (texto, opcional), impact (texto, opcional), urgency (texto, opcional), priority_auto (sí/no), scheduled_attention_at (fecha, opcional), images (json, opcional), ai_diagnosis (json, opcional), ai_diagnosis_at (fecha, opcional), ai_summary (texto largo, opcional), ai_summary_at (fecha, opcional), ai_summary_stale (sí/no), archived_at (fecha, opcional), source_ref (texto, opcional), assigned_at (fecha, opcional)
- **`executive_report_templates`** — id (núm), site_id (núm), system_id (núm, opcional), name (texto), config (json), created_by (núm, opcional), created_at (fecha, opcional), updated_at (fecha, opcional)
- **`floor_plan_directory_filters`** — id (núm), floor_plan_id (núm), directory_id (núm), filters (json), created_at (fecha, opcional), updated_at (fecha, opcional)
- **`floor_plans`** — id (núm), site_id (núm), name (texto), image_url (texto), image_width (núm, opcional), image_height (núm, opcional), sort_order (núm), is_active (sí/no), created_by (núm, opcional), created_at (fecha, opcional), updated_at (fecha, opcional)
- **`holidays`** — id (núm), date (fecha), label (texto, opcional), created_at (fecha, opcional), updated_at (fecha, opcional)
- **`impersonation_logs`** — id (núm), impersonator_id (núm), impersonated_id (núm), token_id (núm, opcional), ip (texto, opcional), started_at (fecha), ended_at (fecha, opcional), created_at (fecha, opcional), updated_at (fecha, opcional)
- **`integration_links`** — id (núm), integration_id (núm), provider (texto), local_type (texto), local_id (núm), external_key (texto, opcional), external_id (texto, opcional), external_url (texto, opcional), meta (json, opcional), created_at (fecha, opcional), updated_at (fecha, opcional)
- **`integration_logs`** — id (núm), integration_id (núm, opcional), provider (texto), client_id (núm, opcional), direction (texto), event_type (texto), status (texto), attempts (núm), payload (json, opcional), response (json, opcional), error (texto largo, opcional), delivered_at (fecha, opcional), created_at (fecha, opcional), updated_at (fecha, opcional)
- **`integrations`** — id (núm), provider (texto), client_id (núm, opcional), is_active (sí/no), config (texto largo, opcional), inbound_secret (texto, opcional), last_ok_at (fecha, opcional), last_error_at (fecha, opcional), last_error (texto largo, opcional), created_by (núm, opcional), created_at (fecha, opcional), updated_at (fecha, opcional)
- **`mail_send_logs`** — id (núm), to_email (texto, opcional), subject (texto, opcional), mailable (texto, opcional), created_at (fecha, opcional)
- **`maintenance_activities`** — id (núm), maintenance_id (núm), device_id (núm), activity_type_id (núm), user_id (núm), field_values (json), performed_at (fecha), created_at (fecha, opcional), updated_at (fecha, opcional), source_ref (texto, opcional)
- **`maintenance_contract_files`** — id (núm), maintenance_id (núm), name (texto), path (texto), mime (texto, opcional), size (núm), uploaded_by (núm, opcional), created_at (fecha, opcional), updated_at (fecha, opcional)
- **`maintenance_contract_frequencies`** — id (núm), maintenance_id (núm), device_type_id (núm), activity_type_id (núm), period_value (núm, opcional), period_unit (texto), created_at (fecha, opcional), updated_at (fecha, opcional)
- **`maintenance_engineers`** — maintenance_id (núm), user_id (núm), created_at (fecha, opcional), updated_at (fecha, opcional)
- **`maintenance_frequencies`** — id (núm), system_id (núm), device_type_id (núm), activity_type_id (núm), period_value (núm, opcional), period_unit (texto), created_at (fecha, opcional), updated_at (fecha, opcional)
- **`maintenances`** — id (núm), site_id (núm), catalog_id (núm), start_date (fecha), end_date (fecha), status (texto), notes (texto largo, opcional), created_by (núm, opcional), created_at (fecha, opcional), updated_at (fecha, opcional), type (texto), agenda_rules (json, opcional), archived_at (fecha, opcional)
- **`message_attachments`** — id (núm), message_id (núm), url (texto), mime (texto, opcional), size (núm, opcional), kind (texto), width (núm, opcional), height (núm, opcional), thumb_url (texto, opcional), created_at (fecha, opcional), updated_at (fecha, opcional)
- **`message_reads`** — id (núm), message_id (núm), user_id (núm), delivered_at (fecha, opcional), read_at (fecha, opcional), created_at (fecha, opcional), updated_at (fecha, opcional)
- **`messages`** — id (núm), conversation_id (núm), sender_id (núm), body (texto largo, opcional), reply_to_id (núm, opcional), client_uuid (texto, opcional), edited_at (fecha, opcional), created_at (fecha, opcional), updated_at (fecha, opcional), deleted_at (fecha, opcional)
- **`model_has_permissions`** — permission_id (núm), model_type (texto), model_id (núm)
- **`model_has_roles`** — role_id (núm), model_type (texto), model_id (núm)
- **`notification_preferences`** — id (núm), user_id (núm), type (texto), enabled (sí/no), created_at (fecha, opcional), updated_at (fecha, opcional)
- **`notifications`** — id (núm), user_id (núm), type (texto), data (json, opcional), read_at (fecha, opcional), created_at (fecha, opcional), updated_at (fecha, opcional)
- **`permissions`** — id (núm), name (texto), guard_name (texto), created_at (fecha, opcional), updated_at (fecha, opcional)
- **`report_export_templates`** — id (núm), report (texto), user_id (núm), name (texto), sections (json), signature (texto, opcional), signature_align (texto, opcional), created_at (fecha, opcional), updated_at (fecha, opcional)
- **`report_section_settings`** — id (núm), scope_type (texto), scope_id (núm), report (texto), overrides (json), created_at (fecha, opcional), updated_at (fecha, opcional)
- **`role_has_permissions`** — permission_id (núm), role_id (núm)
- **`roles`** — id (núm), name (texto), guard_name (texto), created_at (fecha, opcional), updated_at (fecha, opcional), deleted_at (fecha, opcional)
- **`service_sheet_exports`** — id (núm), client_id (núm), from_date (fecha), to_date (fecha), status (texto), requested_by (núm), event_count (núm, opcional), file_path (texto, opcional), error (texto largo, opcional), created_at (fecha, opcional), updated_at (fecha, opcional), tenant (texto, opcional), site_id (núm, opcional), signature (texto, opcional), signature_align (texto, opcional)
- **`site_engineers`** — site_id (núm), user_id (núm), created_at (fecha, opcional), updated_at (fecha, opcional)
- **`site_user`** — site_id (núm), user_id (núm), created_at (fecha, opcional), updated_at (fecha, opcional)
- **`sites`** — id (núm), client_id (núm), name (texto), code (texto, opcional), type (texto), address (texto, opcional), city (texto, opcional), state (texto, opcional), country (texto), contact_name (texto, opcional), contact_email (texto, opcional), contact_phone (texto, opcional), is_active (sí/no), notes (texto largo, opcional), created_by (núm, opcional), created_at (fecha, opcional), updated_at (fecha, opcional), deleted_at (fecha, opcional)
- **`snapshot_exports`** — id (núm), status (texto), file_name (texto), size (núm, opcional), no_media (sí/no), error (texto largo, opcional), requested_by (núm), created_at (fecha, opcional), updated_at (fecha, opcional)
- **`solicitante_client`** — user_id (núm), client_id (núm), created_at (fecha, opcional), updated_at (fecha, opcional)
- **`solicitante_site`** — user_id (núm), site_id (núm), created_at (fecha, opcional), updated_at (fecha, opcional)
- **`system_device_types`** — id (núm), system_catalog_id (núm), device_type_catalog_id (núm), created_at (fecha, opcional), updated_at (fecha, opcional)
- **`system_fields`** — id (núm), catalog_id (núm), label (texto), field_key (texto), field_type (texto), catalog_type (texto, opcional), is_required (sí/no), max_length (núm, opcional), sort_order (núm), is_active (sí/no), created_by (núm, opcional), created_at (fecha, opcional), updated_at (fecha, opcional), client_id (núm, opcional), show_in_bitacora (sí/no), show_in_dashboard (sí/no), config (json, opcional), show_in_event_report (sí/no), show_in_service_sheet (sí/no)
- **`task_durations`** — id (núm), system_id (núm), device_type_id (núm), activity_type_id (núm), minutes (núm), created_at (fecha, opcional), updated_at (fecha, opcional)
- **`user_preferences`** — id (núm), user_id (núm), key (texto), value (json, opcional), created_at (fecha, opcional), updated_at (fecha, opcional)
- **`users`** — id (núm), name (texto), email (texto), password (texto), must_change_password (sí/no), is_active (sí/no), created_by (núm, opcional), last_login_at (fecha, opcional), remember_token (texto, opcional), created_at (fecha, opcional), updated_at (fecha, opcional), deleted_at (fecha, opcional), telegram_username (texto, opcional), whatsapp_number (texto, opcional)
- **`webhook_deliveries`** — id (núm), webhook_endpoint_id (núm), event_type (texto), payload (json), status (texto), attempts (núm), response_status (núm, opcional), response_body (texto largo, opcional), error (texto largo, opcional), delivered_at (fecha, opcional), created_at (fecha, opcional), updated_at (fecha, opcional)
- **`webhook_endpoints`** — id (núm), client_id (núm), site_id (núm, opcional), url (texto), secret (texto), description (texto, opcional), events (json, opcional), is_active (sí/no), created_by (núm, opcional), last_success_at (fecha, opcional), last_failure_at (fecha, opcional), created_at (fecha, opcional), updated_at (fecha, opcional)


## 5. Comandos y tareas programadas

| Comando | Qué hace | Cuándo corre |
|---|---|---|
| `activities:import-adist` | Importa tareas de ADIST3 como actividades (device por DID, descripción + imágenes, preserva fecha) | a mano |
| `activities:reconcile-adist` | Genera hoja CSV para reconciliar a mano los devices que no matchearon | a mano |
| `activity:prune` | Elimina los registros de auditoría más antiguos que N días (por defecto 90). | `15 3 * * *` |
| `ai:ingest-docs` | Ingesta manuales/guías al RAG del asistente (extrae texto, embebe y guarda). | a mano |
| `app-errors:prune` | Elimina los errores de la app móvil más antiguos que N días (por defecto 90). | `20 3 * * *` |
| `devicetypes:transfer` | Exporta/importa tipos de dispositivo (con nomenclatura) entre ambientes | a mano |
| `manttos:inventario` | Genera docs/INVENTARIO.md con el estado real de rutas, esquema y comandos | a mano |
| `media:optimize` | Reduce el peso de las imágenes ya almacenadas, sin cambiar de formato | a mano |
| `snapshot:export` | Exporta toda la instalación (base de datos + archivos) a un ZIP portable | a mano |
| `snapshot:import` | Restaura un snapshot completo (base de datos + archivos) generado con snapshot:export | a mano |
| `telegram:poll` | Sondea Telegram (dev) y procesa mensajes entrantes de captación | a mano |

> Todo esto cuelga de **un solo** `schedule:run` en el cron. Si ese cron no
> está, no falla nada de forma visible: simplemente deja de correr.
