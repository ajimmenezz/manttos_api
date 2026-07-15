<?php

/*
|--------------------------------------------------------------------------
| Configuración del Asistente de IA (chat + herramientas MCP)
|--------------------------------------------------------------------------
| Catálogo de REFERENCIA de proveedores y modelos. Es data estática que
| alimenta:
|   - el selector de proveedor/modelo en la UI de configuración,
|   - la estimación de costo aproximado por acción (App\Support\AiPricing),
|   - la resolución del proveedor de chat en tiempo de ejecución.
|
| La ELECCIÓN concreta del cliente (proveedor activo, modelo, API key) NO
| vive aquí: se guarda en `app_settings` (ver App\Services\Ai\AiSettings) y
| se edita desde Configuración → Asistente IA. Así el mismo despliegue puede
| empezar en local (Ollama, costo 0) y migrar a un proveedor de pago con solo
| cambiar la configuración, sin tocar código.
|
| PRECIOS: en USD por 1,000,000 de tokens (entrada / salida).
|   - Anthropic: precios reales (ref. jul-2026).
|   - OpenAI / DeepSeek: aproximados y EDITABLES por el admin (pueden cambiar).
|   - Ollama (local): 0 — corre en hardware propio.
*/

return [

    // Perfil por defecto al no haber configuración guardada. Local = sin costo,
    // ideal para desarrollo/pruebas.
    'default_provider' => env('AI_PROVIDER', 'ollama'),

    /*
    |--------------------------------------------------------------------------
    | Embeddings (RAG del manual)
    |--------------------------------------------------------------------------
    | Los embeddings SIEMPRE usan OpenAI (barato y buen recall). La llave se toma
    | de la config del asistente si el proveedor es OpenAI; si no, de OPENAI_API_KEY.
    */
    'embeddings' => [
        'model'      => env('AI_EMBEDDING_MODEL', 'text-embedding-3-small'),
        'base_url'   => env('OPENAI_BASE_URL', 'https://api.openai.com/v1'),
        'dimensions' => 1536,
    ],

    /*
    |--------------------------------------------------------------------------
    | RAG — fuentes del manual/guías para la base de conocimiento
    |--------------------------------------------------------------------------
    | Carpetas que el comando `ai:ingest-docs` recorre. Acepta .md y .tsx (de
    | los componentes de guía/manuales del front, extrayendo su texto).
    */
    'rag' => [
        'sources' => [
            base_path('../documentacion/manuales'),
            base_path('../app/src/components/docs'),
        ],
        'chunk_size' => 1200,
    ],

    /*
    |--------------------------------------------------------------------------
    | Arquetipos de acción — para estimar costo aproximado
    |--------------------------------------------------------------------------
    | Un "turno" del chat con herramientas hace varias llamadas al modelo
    | (pregunta → decide herramienta → recibe datos → responde). Estos perfiles
    | son promedios razonables de tokens por tipo de interacción. Con caché de
    | prompts, la porción cacheada de la entrada cuesta ~0.1x.
    |
    |   input        : tokens de entrada acumulados en el turno completo
    |   output       : tokens de salida acumulados
    |   cached_ratio : fracción de la entrada servida desde caché (0.1x)
    */
    'action_profiles' => [
        'consulta_simple' => [
            'label'        => 'Consulta simple',
            'hint'         => 'Pregunta directa que usa 1–2 herramientas (p. ej. "¿cuántos mantenimientos tengo pendientes?").',
            'input'        => 8000,
            'output'       => 500,
            'cached_ratio' => 0.6,
        ],
        'consulta_compleja' => [
            'label'        => 'Consulta compleja',
            'hint'         => 'Varias herramientas o conjuntos de datos grandes (p. ej. "resume los eventos abiertos por sitio del último mes").',
            'input'        => 30000,
            'output'       => 1500,
            'cached_ratio' => 0.7,
        ],
        'accion' => [
            'label'        => 'Acción (crear/editar)',
            'hint'         => 'La IA ejecuta una escritura real (crear mantenimiento, registrar actividad, cambiar estado).',
            'input'        => 12000,
            'output'       => 700,
            'cached_ratio' => 0.6,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Proveedores y modelos
    |--------------------------------------------------------------------------
    | api_style:
    |   - 'anthropic'         → API de Anthropic (Messages API, MCP nativo)
    |   - 'openai'            → API compatible con OpenAI (/chat/completions)
    | local: true = corre en hardware propio (sin costo por token, requiere
    |        el motor levantado; útil en dev y como plan de "graduación").
    | prices_editable: true = los precios son aproximados y el admin puede
    |        ajustarlos desde la configuración (por si cambian).
    */
    'providers' => [

        'anthropic' => [
            'label'           => 'Anthropic (Claude)',
            'api_style'       => 'anthropic',
            'base_url'        => 'https://api.anthropic.com',
            'local'           => false,
            'prices_editable' => false,
            'key_hint'        => 'API key de Anthropic (empieza con sk-ant-...). Se guarda cifrada.',
            'supports_mcp'    => true,
            'models' => [
                'claude-haiku-4-5' => [
                    'label'         => 'Claude Haiku 4.5',
                    'price_in'      => 1.0,
                    'price_out'     => 5.0,
                    'context'       => 200000,
                    'tool_calling'  => 'excelente',
                    'recommended'   => true,
                    'note'          => 'Lo más barato con function calling muy confiable. Recomendado para empezar.',
                ],
                'claude-sonnet-5' => [
                    'label'         => 'Claude Sonnet 5',
                    'price_in'      => 3.0,
                    'price_out'     => 15.0,
                    'context'       => 1000000,
                    'tool_calling'  => 'excelente',
                    'recommended'   => false,
                    'note'          => 'Más razonamiento para consultas difíciles.',
                ],
                'claude-opus-4-8' => [
                    'label'         => 'Claude Opus 4.8',
                    'price_in'      => 5.0,
                    'price_out'     => 25.0,
                    'context'       => 1000000,
                    'tool_calling'  => 'excelente',
                    'recommended'   => false,
                    'note'          => 'Lo máximo; sobra para este caso de uso.',
                ],
            ],
        ],

        'openai' => [
            'label'           => 'OpenAI (GPT)',
            'api_style'       => 'openai',
            'base_url'        => 'https://api.openai.com/v1',
            'local'           => false,
            'prices_editable' => true,
            'key_hint'        => 'API key de OpenAI (empieza con sk-...). Se guarda cifrada.',
            'supports_mcp'    => true,
            'models' => [
                'gpt-4o-mini' => [
                    'label'         => 'GPT-4o mini',
                    'price_in'      => 0.15,
                    'price_out'     => 0.60,
                    'context'       => 128000,
                    'tool_calling'  => 'buena',
                    'recommended'   => true,
                    'note'          => 'Económico y con function calling sólido. Precios aproximados: ajústalos si cambian.',
                ],
                'gpt-4o' => [
                    'label'         => 'GPT-4o',
                    'price_in'      => 2.50,
                    'price_out'     => 10.0,
                    'context'       => 128000,
                    'tool_calling'  => 'excelente',
                    'recommended'   => false,
                    'note'          => 'Más capaz. Precios aproximados: ajústalos si cambian.',
                ],
            ],
        ],

        'deepseek' => [
            'label'           => 'DeepSeek',
            'api_style'       => 'openai', // compatible con OpenAI
            'base_url'        => 'https://api.deepseek.com',
            'local'           => false,
            'prices_editable' => true,
            'key_hint'        => 'API key de DeepSeek. Se guarda cifrada.',
            'supports_mcp'    => false,
            'models' => [
                'deepseek-chat' => [
                    'label'         => 'DeepSeek Chat',
                    'price_in'      => 0.27,
                    'price_out'     => 1.10,
                    'context'       => 64000,
                    'tool_calling'  => 'buena',
                    'recommended'   => true,
                    'note'          => 'Muy económico. Precios aproximados: ajústalos si cambian.',
                ],
            ],
        ],

        'ollama' => [
            'label'           => 'Local (Ollama)',
            'api_style'       => 'openai', // Ollama expone /v1 compatible con OpenAI
            'base_url'        => env('OLLAMA_BASE_URL', 'http://localhost:11434/v1'),
            'local'           => true,
            'prices_editable' => false,
            'key_hint'        => 'No requiere API key (corre en tu servidor).',
            'supports_mcp'    => false,
            'models' => [
                'qwen2.5:7b-instruct' => [
                    'label'         => 'Qwen 2.5 7B Instruct',
                    'price_in'      => 0.0,
                    'price_out'     => 0.0,
                    'context'       => 32000,
                    'tool_calling'  => 'aceptable',
                    'recommended'   => true,
                    'note'          => 'Sin costo por token. Requiere el motor Ollama levantado. Ideal para dev/pruebas.',
                ],
                'llama3.1:8b' => [
                    'label'         => 'Llama 3.1 8B',
                    'price_in'      => 0.0,
                    'price_out'     => 0.0,
                    'context'       => 128000,
                    'tool_calling'  => 'aceptable',
                    'recommended'   => false,
                    'note'          => 'Sin costo por token. Alternativa local.',
                ],
            ],
        ],
    ],
];
