<?php

namespace App\Support;

/**
 * Estimación de costo aproximado del asistente de IA.
 *
 * Combina el catálogo de precios (config/ai.php → providers.*.models) con los
 * "arquetipos de acción" (config/ai.php → action_profiles) para calcular un
 * costo aproximado en USD por interacción. Es lo que la UI de configuración
 * muestra para que el admin elija proveedor/modelo con datos.
 *
 * Nota: es una ESTIMACIÓN. El costo real depende del tamaño real de los datos
 * y de cuántas herramientas invoque el modelo en cada turno.
 */
class AiPricing
{
    /** Factor de costo de un token servido desde caché (vs. token fresco). */
    private const CACHE_READ_FACTOR = 0.1;

    /**
     * Estima el costo (USD) de un arquetipo de acción con precios dados.
     *
     * @param  float  $priceIn   USD por 1M tokens de entrada
     * @param  float  $priceOut  USD por 1M tokens de salida
     * @param  array  $profile   ['input','output','cached_ratio']
     */
    public static function estimateAction(float $priceIn, float $priceOut, array $profile): float
    {
        $input       = (float) ($profile['input'] ?? 0);
        $output      = (float) ($profile['output'] ?? 0);
        $cachedRatio = (float) ($profile['cached_ratio'] ?? 0);

        // La porción cacheada de la entrada cuesta ~0.1x.
        $effectiveInput = $input * ((1 - $cachedRatio) + $cachedRatio * self::CACHE_READ_FACTOR);

        $costIn  = $effectiveInput / 1_000_000 * $priceIn;
        $costOut = $output          / 1_000_000 * $priceOut;

        return $costIn + $costOut;
    }

    /**
     * Costo real (USD) a partir de tokens consumidos y precios de lista.
     * Para el registro de auditoría (no usa el factor de caché — refleja lo que
     * el proveedor reportó).
     */
    public static function costFromTokens(int $inputTokens, int $outputTokens, float $priceIn, float $priceOut): float
    {
        return round($inputTokens / 1_000_000 * $priceIn + $outputTokens / 1_000_000 * $priceOut, 6);
    }

    /**
     * Estimación por acción para un modelo, con overrides opcionales de precio
     * (los proveedores con `prices_editable` permiten que el admin ajuste).
     *
     * @param  array       $model      entrada del catálogo (price_in/price_out)
     * @param  float|null  $priceIn    override USD/1M entrada
     * @param  float|null  $priceOut   override USD/1M salida
     * @return array<string,array{label:string,hint:string,usd:float}>
     */
    public static function perActionForModel(array $model, ?float $priceIn = null, ?float $priceOut = null): array
    {
        $pi = $priceIn  ?? (float) ($model['price_in']  ?? 0);
        $po = $priceOut ?? (float) ($model['price_out'] ?? 0);

        $out = [];
        foreach ((array) config('ai.action_profiles', []) as $key => $profile) {
            $out[$key] = [
                'label' => $profile['label'] ?? $key,
                'hint'  => $profile['hint']  ?? '',
                'usd'   => round(self::estimateAction($pi, $po, $profile), 5),
            ];
        }

        return $out;
    }

    /**
     * Estimación mensual aproximada, dado un número de acciones/mes por tipo.
     *
     * @param  array  $perAction   salida de perActionForModel()
     * @param  array  $volumes     ['consulta_simple'=>N, 'consulta_compleja'=>N, 'accion'=>N]
     */
    public static function monthlyEstimate(array $perAction, array $volumes): float
    {
        $total = 0.0;
        foreach ($perAction as $key => $row) {
            $total += ($row['usd'] ?? 0) * (int) ($volumes[$key] ?? 0);
        }

        return round($total, 2);
    }

    /**
     * Catálogo completo enriquecido con la estimación por acción de cada modelo.
     * Es lo que consume el selector de la UI (proveedor → modelo → costo/acción).
     */
    public static function catalogWithEstimates(): array
    {
        $providers = [];

        foreach ((array) config('ai.providers', []) as $pid => $provider) {
            $models = [];
            foreach ((array) ($provider['models'] ?? []) as $mid => $model) {
                $models[$mid] = array_merge($model, [
                    'id'         => $mid,
                    'per_action' => self::perActionForModel($model),
                ]);
            }

            $providers[$pid] = [
                'id'              => $pid,
                'label'           => $provider['label'] ?? $pid,
                'api_style'       => $provider['api_style'] ?? 'openai',
                'local'           => (bool) ($provider['local'] ?? false),
                'prices_editable' => (bool) ($provider['prices_editable'] ?? false),
                'key_hint'        => $provider['key_hint'] ?? '',
                'supports_mcp'    => (bool) ($provider['supports_mcp'] ?? false),
                'models'          => $models,
            ];
        }

        return [
            'providers'       => $providers,
            'action_profiles' => (array) config('ai.action_profiles', []),
        ];
    }
}
