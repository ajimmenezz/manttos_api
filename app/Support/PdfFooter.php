<?php

namespace App\Support;

use App\Models\AppSetting;
use Carbon\Carbon;
use Throwable;

/**
 * Pie de página configurable de los imprimibles.
 *
 * Se define en la configuración global (por dominio, como el resto de la marca): de una a
 * tres columnas, y en cada una texto libre con **saltos de línea** y fichas que se
 * sustituyen al imprimir (`{pagina}`, `{fecha}`, …). Así un cliente puede poner su razón
 * social, un aviso de confidencialidad o un folio de control sin tocar código.
 *
 * ⚠️ El alto del pie **deja de ser fijo**: un pie de cuatro líneas se come cuatro veces
 * más hoja. Por eso `Pdf::bottomLimit()` se calcula a partir de `lineCount()` y no de una
 * constante — si no, el contenido escribiría encima del pie.
 */
class PdfFooter
{
    /** Máximo de columnas: con más, en A4 no cabe nada legible. */
    public const MAX_COLUMNS = 3;

    /** Tope de líneas: es un pie, no una segunda portada. */
    public const MAX_LINES = 6;

    /**
     * Fichas disponibles. Se documentan en la pantalla de configuración; al agregar una
     * hay que darla de alta aquí Y en la ayuda del front, o el usuario no sabrá que existe.
     */
    public const TOKENS = [
        '{pagina}'     => 'Número de página',
        '{paginas}'    => 'Total de páginas',
        '{fecha}'      => 'Fecha de generación',
        '{hora}'       => 'Hora de generación',
        '{fecha_hora}' => 'Fecha y hora de generación',
        '{titulo}'     => 'Título del documento',
        '{cliente}'    => 'Cliente',
        '{sitio}'      => 'Sitio',
        '{sistema}'    => 'Sistema',
        '{periodo}'    => 'Periodo del reporte',
        '{app}'        => 'Nombre del sistema',
    ];

    /** @param array<int, array{text:string, align:string}> $columns */
    private function __construct(public readonly array $columns)
    {
    }

    /** El pie de siempre: fecha a la izquierda, paginación a la derecha. */
    public static function default(): self
    {
        return new self([
            ['text' => 'Generado el {fecha_hora}', 'align' => 'L'],
            ['text' => '',                         'align' => 'C'],
            ['text' => 'Página {pagina}/{paginas}', 'align' => 'R'],
        ]);
    }

    public static function for(?string $tenant = null): self
    {
        try {
            $raw = AppSetting::allAsMap($tenant ?: AppSetting::DEFAULT_TENANT)['pdf_footer'] ?? null;
            $cfg = is_array($raw) ? $raw : json_decode((string) $raw, true);

            return self::fromArray(is_array($cfg) ? $cfg : []);
        } catch (Throwable) {
            return self::default();   // el pie nunca debe impedir que salga el documento
        }
    }

    public static function fromArray(array $cfg): self
    {
        $columns = array_values(array_filter(
            $cfg['columns'] ?? [],
            fn ($c) => is_array($c) || is_string($c),
        ));

        if (! $columns) return self::default();

        $columns = array_slice($columns, 0, self::MAX_COLUMNS);
        $n       = count($columns);

        $normalised = [];
        foreach ($columns as $i => $c) {
            $text  = is_string($c) ? $c : (string) ($c['text'] ?? '');
            $align = is_array($c) ? strtoupper((string) ($c['align'] ?? '')) : '';

            // Sin alineación explícita se deduce de la posición: la primera pega a la
            // izquierda, la última a la derecha y las de en medio al centro. Es lo que
            // espera cualquiera que haya maquetado un pie.
            if (! in_array($align, ['L', 'C', 'R'], true)) {
                $align = match (true) {
                    $n === 1     => 'C',
                    $i === 0     => 'L',
                    $i === $n - 1 => 'R',
                    default      => 'C',
                };
            }

            $normalised[] = ['text' => $text, 'align' => $align];
        }

        return new self($normalised);
    }

    /** Cuántas líneas ocupa el pie: manda la columna más alta. */
    public function lineCount(): int
    {
        $max = 0;

        foreach ($this->columns as $c) {
            if (trim($c['text']) === '') continue;
            $max = max($max, count(preg_split('/\r\n|\r|\n/', $c['text'])));
        }

        return max(1, min($max, self::MAX_LINES));
    }

    /**
     * Las columnas con las fichas ya sustituidas, cada una partida en líneas.
     *
     * `{paginas}` se traduce al alias de FPDF (`{nb}`), que se reemplaza al cerrar el
     * documento: es la única forma de saber el total mientras aún se está escribiendo.
     *
     * @return array<int, array{lines:string[], align:string}>
     */
    public function resolve(array $meta, int $page, string $nbAlias = '{nb}'): array
    {
        $generated = self::date($meta['generated_at'] ?? null);

        $values = [
            '{pagina}'     => (string) $page,
            '{paginas}'    => $nbAlias,
            '{fecha}'      => $generated?->locale('es')->isoFormat('D [de] MMMM YYYY') ?? '',
            // OJO: `H:i`, no `HH:mm`. En PHP `date()` no existe `HH` —repite la hora— ni
            // `mm` —repite los minutos—; eso es sintaxis de isoFormat y salía «0707:0808».
            '{hora}'       => $generated?->format('H:i') ?? '',
            '{fecha_hora}' => $generated?->locale('es')->isoFormat('D [de] MMMM YYYY, HH:mm') ?? '',
            '{titulo}'     => (string) ($meta['title'] ?? ''),
            '{cliente}'    => (string) ($meta['client'] ?? ''),
            '{sitio}'      => (string) ($meta['site'] ?? ''),
            '{sistema}'    => (string) ($meta['system'] ?? ''),
            '{periodo}'    => (string) ($meta['period_label'] ?? ''),
            '{app}'        => (string) ($meta['app_name'] ?? ''),
        ];

        $out = [];

        foreach ($this->columns as $c) {
            $text = strtr($c['text'], $values);

            $out[] = [
                'lines' => array_slice(preg_split('/\r\n|\r|\n/', $text), 0, self::MAX_LINES),
                'align' => $c['align'],
            ];
        }

        return $out;
    }

    /** Para guardar en la configuración. */
    public function toArray(): array
    {
        return ['columns' => $this->columns];
    }

    private static function date(mixed $v): ?Carbon
    {
        try {
            return $v ? Carbon::parse((string) $v) : Carbon::now();
        } catch (Throwable) {
            return Carbon::now();
        }
    }
}
