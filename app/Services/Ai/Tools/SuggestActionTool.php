<?php

namespace App\Services\Ai\Tools;

use App\Models\User;
use App\Services\Ai\Tools\Contracts\Tool;

/**
 * Ofrece una ACCIÓN como OPCIÓN tras explicarle al usuario cómo hacer algo. NO ejecuta
 * nada: emite un marcador `__suggestion__` que el front pinta como un botón "Hacerlo por
 * mí". Al pulsarlo, el front manda `message` como el siguiente turno del usuario, y ahí sí
 * el agente ejecuta la acción (heredando permisos y con el gate de confirmación normal).
 *
 * Úsala SOLO al final de una explicación de "cómo se hace X", para que el usuario aprenda
 * primero y actúe si quiere. No la uses si el usuario ya pidió que hicieras la acción.
 */
class SuggestActionTool implements Tool
{
    public function name(): string
    {
        return 'sugerir_accion';
    }

    public function description(): string
    {
        return 'Ofrece hacer una acción por el usuario como OPCIÓN, tras explicarle cómo hacerla él mismo. '
            . 'Muestra un botón "Hacerlo por mí". Úsala al final de una respuesta de tipo "¿cómo se hace X?" '
            . 'cuando esa acción sea automatizable (dar de alta un cliente/sitio, registrar un evento, etc.). '
            . 'NO ejecuta nada: solo ofrece. No la uses si el usuario ya pidió que lo hicieras.';
    }

    public function parameters(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'label'   => ['type' => 'string', 'description' => 'Texto del botón, en imperativo breve. Ej.: "Dar de alta un cliente".'],
                'message' => ['type' => 'string', 'description' => 'Lo que se enviará como mensaje del usuario al pulsar el botón, para que TÚ ejecutes la acción. Ej.: "Da de alta un cliente por mí".'],
            ],
            'required' => ['label', 'message'],
        ];
    }

    public function mutating(): bool { return false; }
    public function confirm(): bool { return false; }

    public function handle(array $args, User $user): array
    {
        $label = trim((string) ($args['label'] ?? ''));
        $message = trim((string) ($args['message'] ?? ''));
        if ($label === '' || $message === '') {
            return ['error' => 'Faltan label o message para sugerir la acción.'];
        }

        // El marcador __suggestion__ lo recoge el Agent para pintar el botón en el front.
        return [
            'message'        => 'Se le ofreció al usuario un botón "'.$label.'" para hacerlo por él si lo desea.',
            '__suggestion__' => ['label' => $label, 'message' => $message],
        ];
    }
}
