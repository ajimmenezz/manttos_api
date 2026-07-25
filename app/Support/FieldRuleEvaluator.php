<?php

namespace App\Support;

/**
 * Evaluador en PHP del árbol de condiciones `RuleGroup` que hoy solo existía en el cliente
 * (app/src/lib/fieldRules.ts). Se usa server-side para las automatizaciones de evento:
 * decide si una automatización dispara según los valores del formulario del evento, los
 * campos del directorio del dispositivo ligado y los atributos del propio evento.
 *
 * Contrato del árbol (idéntico al del front):
 *   grupo:     { kind:'group', combinator:'and'|'or', children:[nodo,...] }
 *   condición: { kind:'condition', source?:'form'|'device'|'event', field_key, operator, value? }
 *
 * El contexto es un mapa por fuente: ['form'=>[...], 'device'=>[...], 'event'=>[...]].
 * Un árbol nulo o sin hijos = "siempre" (lo decide el llamador).
 */
class FieldRuleEvaluator
{
    /**
     * @param  array<string,mixed>|null  $group    árbol RuleGroup
     * @param  array<string,array<string,mixed>>  $context  ['form'=>[], 'device'=>[], 'event'=>[]]
     */
    public static function matches(?array $group, array $context): bool
    {
        if (! $group || empty($group['children'] ?? [])) {
            return true; // sin condiciones = siempre
        }
        return self::evalNode($group, $context);
    }

    /** @param array<string,mixed> $node */
    private static function evalNode(array $node, array $context): bool
    {
        if (($node['kind'] ?? null) === 'condition') {
            return self::evalCondition($node, $context);
        }
        $children = $node['children'] ?? [];
        if (empty($children)) {
            return false; // grupo vacío nunca coincide
        }
        $and = ($node['combinator'] ?? 'and') === 'and';
        foreach ($children as $child) {
            $res = self::evalNode($child, $context);
            if ($and && ! $res) return false;
            if (! $and && $res) return true;
        }
        return $and;
    }

    /** @param array<string,mixed> $cond */
    private static function evalCondition(array $cond, array $context): bool
    {
        $source = $cond['source'] ?? 'form';
        $map = $context[$source] ?? [];
        $a = $map[$cond['field_key'] ?? ''] ?? null;
        $b = $cond['value'] ?? '';
        $op = $cond['operator'] ?? 'eq';

        // Selección múltiple: el valor guardado es un arreglo → contains = pertenencia.
        if (is_array($a) && ($op === 'contains' || $op === 'not_contains')) {
            $has = in_array((string) $b, array_map('strval', $a), true);
            return $op === 'contains' ? $has : ! $has;
        }

        $sa = is_array($a) ? implode(',', $a) : (string) ($a ?? '');
        $sb = (string) $b;

        return match ($op) {
            'eq'           => $sa === $sb,
            'neq'          => $sa !== $sb,
            'contains'     => $sb !== '' && str_contains(mb_strtolower($sa), mb_strtolower($sb)),
            'not_contains' => ! ($sb !== '' && str_contains(mb_strtolower($sa), mb_strtolower($sb))),
            'gt'           => is_numeric($a) && is_numeric($b) && (float) $a >  (float) $b,
            'gte'          => is_numeric($a) && is_numeric($b) && (float) $a >= (float) $b,
            'lt'           => is_numeric($a) && is_numeric($b) && (float) $a <  (float) $b,
            'lte'          => is_numeric($a) && is_numeric($b) && (float) $a <= (float) $b,
            'before'       => ! self::isEmpty($a) && $sa <  $sb,
            'after'        => ! self::isEmpty($a) && $sa >  $sb,
            'is_true'      => self::truthy($a),
            'is_false'     => ! self::truthy($a),
            'empty'        => self::isEmpty($a),
            'not_empty'    => ! self::isEmpty($a),
            default        => false,
        };
    }

    private static function isEmpty(mixed $v): bool
    {
        return $v === null || $v === '' || (is_array($v) && count($v) === 0);
    }

    private static function truthy(mixed $v): bool
    {
        return $v === true || $v === 1 || $v === '1' || $v === 'true';
    }
}
