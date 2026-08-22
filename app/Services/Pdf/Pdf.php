<?php

namespace App\Services\Pdf;

use App\Support\Branding;
use Carbon\Carbon;
use FPDF;

/**
 * Base de TODOS los imprimibles del sistema: membrete, retícula, tablas, gráficas y
 * bloque de firma, dibujados por COORDENADAS con FPDF.
 *
 * Se dibuja por coordenadas —no es HTML convertido— porque los reportes son una
 * retícula: paneles de ancho fijo, barras proporcionales, tablas que se parten por
 * renglón. Así el resultado es idéntico en cualquier servidor y no depende de cómo
 * un motor de maquetación decida romper la página.
 *
 * Convenciones internas:
 *  - Todo en milímetros sobre A4 vertical; el área útil es MARGIN..(210-MARGIN).
 *  - `$this->y` es el cursor vertical; `ensureSpace()` salta de página.
 *  - Los textos pasan por `t()`: las fuentes base de FPDF son cp1252, no UTF-8.
 *  - `$data['meta']` lleva title/client/site/system/period_label/generated_at.
 */
abstract class Pdf extends FPDF
{
    protected const MARGIN = 10;
    protected const PAGE_W = 210;
    protected const CONTENT_W = 190;      // 210 - 2*10
    protected const COL_W = 92.5;         // dos columnas con 5mm de canal
    protected const GUTTER = 5;
    protected const PANEL_H = 48;         // alto de un panel de barras
    protected const TIME_H  = 36;         // alto del panel de serie por fecha
    protected const KPI_H   = 24;
    protected const BOTTOM  = 283;        // último milímetro utilizable (arriba del pie)

    /** Distancia del borde inferior a la línea de firma que va en CADA hoja. */
    protected const SIGN_UP = 22;

    /**
     * Franja que el contenido le cede a esa firma.
     *
     * Sin esto, el contenido llegaba hasta BOTTOM (283) y la firma se dibujaba en 275:
     * compartían los mismos milímetros y la línea quedaba pegada —o encima— del último
     * panel. La reserva empuja ese panel a la hoja siguiente, que es justo lo que hace
     * falta para poder firmar.
     */
    protected const SIGN_RESERVE = 16;

    protected const NAVY   = [30, 58, 95];
    protected const INK    = [51, 65, 85];
    protected const MUTED  = [120, 133, 150];
    protected const LINE   = [214, 222, 232];

    /** Paleta por sección, en el orden en que aparecen. */
    protected const SERIES = [
        [96, 165, 120],   // verde  (preventivo)
        [226, 178, 54],   // ámbar  (pruebas)
        [183, 58, 58],    // rojo   (correctivo)
        [79, 70, 229],    // índigo
        [14, 165, 233],   // azul
        [139, 92, 246],   // violeta
    ];

    protected array $data;
    protected ?string $logo = null;
    protected ?string $headerNote = null;

    /**
     * Colores de la marca del tenant. Arrancan en los históricos y `withBranding()` los
     * cambia por los del dominio que pidió el documento. Se guardan como propiedades —y
     * no como constantes— justamente para que puedan variar por cliente: `self::NAVY`
     * queda sólo como respaldo.
     */
    protected array $brandDark    = self::NAVY;
    protected array $brandDarkTo  = self::NAVY;
    /** Tono de TRAZO: títulos, barras y filetes. Ver `withBranding()`. */
    protected array $brandInk     = self::NAVY;
    protected array $brandPrimary = [37, 99, 235];

    /**
     * Cierre de conformidad: 'end' = una línea al final del documento, 'page' = una
     * en cada hoja, null = ninguna. Lo elige quien manda a imprimir.
     */
    protected ?string $signature = null;

    /** Dónde se coloca esa línea a lo ancho: 'left' | 'center' | 'right'. */
    protected string $signatureAlign = 'center';

    /**
     * Milímetros que el ÚLTIMO bloque le cede a la firma final.
     *
     * Con firma al final, si el último bloque llegaba hasta abajo la firma se iba sola a
     * una hoja nueva —una página entera con una raya—. Reservando al llegar al último
     * bloque, ese bloque corta antes y la firma cierra el documento donde debe.
     * Sólo se activa en el tramo final: reservarlo en todas las hojas desperdiciaría
     * espacio en un documento largo.
     */
    protected float $tailReserve = 0;

    public function __construct(array $data)
    {
        parent::__construct('P', 'mm', 'A4');

        $this->data = $data;
        $this->withBranding(null);   // marca base; el controlador la afina por dominio

        $this->SetAutoPageBreak(false);   // la paginación la decide ensureSpace()
        $this->SetMargins(self::MARGIN, self::MARGIN, self::MARGIN);
        $this->SetTitle($this->str('title'), true);
        $this->AliasNbPages();
    }

    /**
     * Activa el cierre de conformidad. Es opcional en TODOS los imprimibles porque
     * unos se archivan firmados por el cliente y otros son de consulta interna.
     */
    public function withSignature(?string $mode, ?string $align = null): static
    {
        $this->signature = in_array($mode, ['end', 'page'], true) ? $mode : null;

        // La alineación importa de verdad cuando la firma va en CADA hoja: según cómo se
        // archive el entregable (engargolado, perforado, sellado a un costado), la línea
        // estorba en un lado o en el otro.
        $this->signatureAlign = in_array($align, ['left', 'center', 'right'], true) ? $align : 'center';

        return $this;
    }

    /**
     * Aplica la identidad del dominio que pidió el documento: logo y colores salen de la
     * configuración del tenant, igual que la app y los correos.
     */
    public function withBranding(?string $tenant): static
    {
        $brand = Branding::for($tenant);

        $this->logo         = $brand->logo;
        $this->brandDark    = $brand->dark;
        $this->brandDarkTo  = $brand->darkTo;

        // Punto medio del degradado. El extremo oscuro (#0f1f3d en el preset azul) es casi
        // negro: bien para la banda, pero deja los títulos y las barras sin color de
        // marca. El extremo claro es demasiado saturado para texto. El medio se lee como
        // el azul marino de siempre, pero teñido por el preset del cliente.
        $this->brandInk = [
            (int) round(($brand->dark[0] + $brand->darkTo[0]) / 2),
            (int) round(($brand->dark[1] + $brand->darkTo[1]) / 2),
            (int) round(($brand->dark[2] + $brand->darkTo[2]) / 2),
        ];
        $this->brandPrimary = $brand->primary;

        return $this;
    }

    /** Coordenada X de un bloque de ancho $w según la alineación de la firma. */
    protected function signatureX(float $w): float
    {
        return match ($this->signatureAlign) {
            'left'  => self::MARGIN,
            'right' => self::MARGIN + self::CONTENT_W - $w,
            default => self::MARGIN + (self::CONTENT_W - $w) / 2,
        };
    }

    /** Encabezado a la derecha del membrete (folio, estado, total…). */
    public function withHeaderNote(?string $note): static
    {
        $this->headerNote = $note;

        return $this;
    }

    abstract public function render(): string;

    // ── Utilidades ────────────────────────────────────────────────────────────

    /** Las fuentes base de FPDF hablan cp1252; el sistema, UTF-8. */
    protected function t(?string $s): string
    {
        // OJO con `?:`: la cadena "0" es falsy en PHP y un KPI en cero saldría vacío.
        $out = iconv('UTF-8', 'windows-1252//TRANSLIT', (string) $s);

        return $out === false ? '' : $out;
    }

    protected function str(string $key): string
    {
        return (string) ($this->data['meta'][$key] ?? '');
    }

    /** Recorta con elipsis para que quepa en `$w` con la fuente activa. */
    protected function fit(string $s, float $w): string
    {
        $s = $this->t($s);
        if ($this->GetStringWidth($s) <= $w) return $s;

        while (mb_strlen($s) > 1 && $this->GetStringWidth($s . '...') > $w) {
            $s = mb_substr($s, 0, mb_strlen($s) - 1);
        }

        return $s . '...';
    }

    protected function num(float|int $n): string
    {
        return number_format($n, 0, ',', '.');
    }

    protected function fill(array $rgb): void { $this->SetFillColor(...$rgb); }
    protected function ink(array $rgb): void  { $this->SetTextColor(...$rgb); }
    protected function draw(array $rgb): void { $this->SetDrawColor(...$rgb); }


    // ── Estructura de página ──────────────────────────────────────────────────

    public function Header(): void
    {
        $h = 20;

        // Degradado entre los dos extremos de la marca: FPDF no tiene gradientes, se
        // dibuja en tiras. Es el mismo degradado del encabezado de los correos.
        $this->gradient(0, 0, self::PAGE_W, $h, $this->brandDark, $this->brandDarkTo);

        // Filete de acento al pie de la banda: remata el membrete y es lo que hace que
        // el documento se lea como del mismo sistema que la app.
        $this->fill($this->brandPrimary);
        $this->Rect(0, $h, self::PAGE_W, 1.2, 'F');

        $logoW = $this->logoBlock($h);

        if ($this->headerNote) {
            $this->ink([255, 255, 255]);
            $this->SetFont('Arial', 'B', 12);
            $this->SetXY(self::PAGE_W - self::MARGIN - 45, 5.5);
            $this->Cell(45, 6, $this->fit($this->headerNote, 45), 0, 0, 'R');
        }

        // El bloque de texto reserva el MISMO margen a los dos lados para que quede
        // centrado en la hoja, y ese margen es el del lado que más ocupa. Reservar 45mm
        // fijos a la derecha "por si hay nota" estrangulaba el título cuando no la hay:
        // el subtítulo salía recortado sin necesidad.
        $side  = max($logoW, $this->headerNote ? 45 : 0) + 4;
        $textW = self::PAGE_W - 2 * (self::MARGIN + $side);

        $this->ink([255, 255, 255]);
        $this->SetFont('Arial', 'B', 11);
        $this->SetXY(self::MARGIN + $side, 4.5);
        $this->Cell($textW, 5, $this->fit($this->str('title'), $textW), 0, 2, 'C');

        $this->SetFont('Arial', '', 8.5);
        $sub = trim($this->str('client') . ' · ' . $this->str('site'), ' ·');
        if ($this->str('system')) $sub .= ' · ' . $this->str('system');
        $this->Cell($textW, 4.5, $this->fit($sub, $textW), 0, 2, 'C');
        $this->Cell($textW, 4.5, $this->fit($this->str('period_label'), $textW), 0, 2, 'C');

        $this->y = $h + 7;
    }

    /**
     * Logo sobre una tarjeta blanca, y devuelve cuánto ancho ocupó.
     *
     * La tarjeta no es adorno: los logos que suben los clientes suelen ser JPEG con
     * fondo blanco, y puestos directamente sobre la banda de color se ven como un
     * recuadro sucio. Sobre blanco, cualquier logo se lee bien.
     */
    protected function logoBlock(float $bandH): float
    {
        if (! $this->logo) return 0;

        try {
            $size = @getimagesize($this->logo);
            if (! $size || ! $size[1]) return 0;

            $imgH = 11;
            $imgW = min(38, $size[0] / $size[1] * $imgH);   // tope: un logo apaisado no puede comerse el título
            $imgH = $size[1] / $size[0] * $imgW;            // recalcula por si el tope recortó
            $pad  = 1.5;

            $y = ($bandH - $imgH) / 2;

            $this->fill([255, 255, 255]);
            $this->Rect(self::MARGIN - $pad, $y - $pad, $imgW + 2 * $pad, $imgH + 2 * $pad, 'F');
            $this->Image($this->logo, self::MARGIN, $y, $imgW, $imgH);

            return $imgW + $pad;
        } catch (\Throwable) {
            return 0;   // el membrete nunca debe tumbar el documento
        }
    }

    /** Degradado horizontal por tiras (FPDF no tiene gradientes). */
    protected function gradient(float $x, float $y, float $w, float $h, array $from, array $to): void
    {
        $steps = $from === $to ? 1 : 48;
        $sw    = $w / $steps;

        for ($i = 0; $i < $steps; $i++) {
            $k = $steps === 1 ? 0 : $i / ($steps - 1);

            $this->fill([
                (int) round($from[0] + ($to[0] - $from[0]) * $k),
                (int) round($from[1] + ($to[1] - $from[1]) * $k),
                (int) round($from[2] + ($to[2] - $from[2]) * $k),
            ]);

            // +0.2 de solape: sin él quedan hilos del fondo entre tira y tira.
            $this->Rect($x + $i * $sw, $y, $sw + 0.2, $h, 'F');
        }
    }

    public function Footer(): void
    {
        if ($this->signature === 'page') {
            $w = 90;
            $x = $this->signatureX($w);
            $y = $this->h - self::SIGN_UP;

            $this->draw(self::INK);
            $this->SetLineWidth(0.3);
            $this->Line($x, $y, $x + $w, $y);
            $this->SetLineWidth(0.2);

            $this->SetFont('Arial', '', 7.5);
            $this->ink(self::INK);
            $this->SetXY($x, $y + 1);
            $this->Cell($w, 4, $this->t('Nombre y Firma de Conformidad'), 0, 0, $this->signatureAlign === 'center' ? 'C' : strtoupper($this->signatureAlign[0]));
        }

        $this->SetY(-12);
        $this->SetFont('Arial', '', 7.5);
        $this->ink(self::MUTED);
        $this->Cell(
            self::CONTENT_W / 2,
            5,
            $this->t('Generado el ' . Carbon::parse($this->str('generated_at'))->locale('es')->isoFormat('D [de] MMMM YYYY, HH:mm')),
            0, 0, 'L'
        );
        $this->Cell(self::CONTENT_W / 2, 5, $this->t('Página ') . $this->PageNo() . '/{nb}', 0, 0, 'R');
    }

    /** Salta de página si el bloque que sigue no cabe completo. */
    protected function ensureSpace(float $height): void
    {
        if ($this->PageNo() === 0) { $this->AddPage(); return; }
        if ($this->y + $height > $this->bottomLimit()) $this->AddPage();
    }

    /**
     * Último milímetro que puede ocupar el contenido en esta hoja.
     *
     * Con firma en cada hoja hay menos: esa línea se dibuja en el pie de TODAS las
     * páginas, así que el espacio no es negociable. Con firma sólo al final —o sin
     * firma— la hoja se aprovecha entera; el bloque final ya pide su propio hueco.
     */
    protected function bottomLimit(): float
    {
        return self::BOTTOM - ($this->signature === 'page' ? self::SIGN_RESERVE : 0) - $this->tailReserve;
    }

    /** Título de banda: la separación entre secciones del tablero. */
    protected function bandTitle(string $text): void
    {
        // Reserva el título MÁS el primer bloque: un encabezado huérfano al pie de
        // página se lee como si la sección viniera vacía.
        $this->ensureSpace(10 + self::PANEL_H + 4);

        $this->SetFont('Arial', 'B', 10);
        $this->ink($this->brandInk);
        $this->SetXY(self::MARGIN, $this->y);
        $this->Cell(self::CONTENT_W, 6, $this->fit($text, self::CONTENT_W), 0, 0, 'C');

        $this->draw($this->brandInk);
        $this->SetLineWidth(0.5);
        $this->Line(self::MARGIN, $this->y + 6.8, self::MARGIN + self::CONTENT_W, $this->y + 6.8);
        $this->SetLineWidth(0.2);

        $this->y += 10;
    }

    /** Marco + encabezado de un panel; devuelve la Y donde empieza su contenido. */
    protected function panel(float $x, float $y, float $w, float $h, string $title): float
    {
        $this->draw(self::LINE);
        $this->fill([255, 255, 255]);
        $this->Rect($x, $y, $w, $h, 'DF');

        $this->fill([244, 247, 251]);
        $this->Rect($x, $y, $w, 7, 'F');
        $this->Line($x, $y + 7, $x + $w, $y + 7);

        $this->SetFont('Arial', 'B', 7.5);
        $this->ink($this->brandInk);
        $this->SetXY($x + 2, $y + 1.2);
        $this->Cell($w - 4, 4.6, $this->fit($title, $w - 4), 0, 0, 'C');

        return $y + 9;
    }

    /** Recuadro de dato duro (los "Total de dispositivos" del tablero). */
    protected function kpi(float $x, float $y, float $w, float $h, string $label, string $value): void
    {
        $this->draw(self::LINE);
        $this->fill([255, 255, 255]);
        $this->Rect($x, $y, $w, $h, 'DF');

        $this->fill($this->brandInk);
        $this->Rect($x, $y, 1.6, $h, 'F');

        $this->SetFont('Arial', '', 7.5);
        $this->ink(self::MUTED);
        $this->SetXY($x + 4, $y + 3);
        $this->Cell($w - 6, 4, $this->fit($label, $w - 6), 0, 2, 'L');

        $this->SetFont('Arial', 'B', 16);
        $this->ink($this->brandInk);
        $this->Cell($w - 6, 8, $this->t($value), 0, 0, 'L');
    }

    // ── Gráficas ──────────────────────────────────────────────────────────────

    /**
     * Barras horizontales con etiqueta y valor, como las del tablero original.
     * `$rows` = [['label'=>string,'count'=>int], …] ya ordenado y recortado.
     */
    protected function hBars(array $rows, float $x, float $y, float $w, float $h, array $rgb): void
    {
        if (! $rows) { $this->emptyBox($x, $y, $w, $h); return; }

        $max      = max(1, max(array_column($rows, 'count')));
        $labelW   = $w * 0.46;
        $valueW   = 11;
        $trackW   = $w - $labelW - $valueW - 4;
        $rowH     = min(6.5, $h / count($rows));
        $barH     = min(4, $rowH - 1.4);

        foreach ($rows as $i => $row) {
            $ry = $y + $i * $rowH;
            if ($ry + $rowH > $y + $h) break;

            $this->SetFont('Arial', '', 6.5);
            $this->ink(self::INK);
            $this->SetXY($x + 1, $ry);
            $this->Cell($labelW, $rowH, $this->fit((string) $row['label'], $labelW - 1), 0, 0, 'R');

            $len = max(0.6, $trackW * ($row['count'] / $max));
            $this->fill($rgb);
            $this->Rect($x + $labelW + 2, $ry + ($rowH - $barH) / 2, $len, $barH, 'F');

            $this->SetFont('Arial', 'B', 6.5);
            $this->ink(self::INK);
            $this->SetXY($x + $labelW + 2 + $len + 1, $ry);
            $this->Cell($valueW, $rowH, $this->num($row['count']), 0, 0, 'L');
        }
    }

    /** Serie temporal en columnas (día o mes, según lo que quepa). */
    protected function vBars(array $rows, float $x, float $y, float $w, float $h, array $rgb): void
    {
        if (! $rows) { $this->emptyBox($x, $y, $w, $h); return; }

        $max    = max(1, max(array_column($rows, 'count')));
        $slot   = $w / count($rows);
        $barW   = min(6, max(0.8, $slot * 0.68));
        $plotH  = $h - 8;                       // deja aire para valor y etiqueta
        $showLabels = $slot >= 3.2;

        foreach ($rows as $i => $row) {
            $cx  = $x + $i * $slot + $slot / 2;
            $len = max(0.4, $plotH * ($row['count'] / $max));

            $this->fill($rgb);
            $this->Rect($cx - $barW / 2, $y + $plotH - $len + 4, $barW, $len, 'F');

            if ($showLabels) {
                $this->SetFont('Arial', 'B', 5.2);
                $this->ink(self::INK);
                $this->SetXY($cx - $slot / 2, $y + $plotH - $len);
                $this->Cell($slot, 4, $this->num($row['count']), 0, 0, 'C');

                $this->SetFont('Arial', '', 5.2);
                $this->ink(self::MUTED);
                $this->SetXY($cx - $slot / 2, $y + $plotH + 4.4);
                $this->Cell($slot, 3.4, $this->t((string) $row['label']), 0, 0, 'C');
            }
        }

        // Eje y rótulo del tramo (mes … mes), que es lo que orienta la lectura.
        $this->draw(self::LINE);
        $this->Line($x, $y + $plotH + 4, $x + $w, $y + $plotH + 4);

        $first = $rows[0]['month'] ?? null;
        $last  = $rows[count($rows) - 1]['month'] ?? null;
        if ($first) {
            $this->SetFont('Arial', '', 5.6);
            $this->ink(self::MUTED);
            $this->SetXY($x, $y + $plotH + ($showLabels ? 7.6 : 4.6));
            $this->Cell($w, 3.4, $this->t($first === $last ? $first : "$first  –  $last"), 0, 0, 'C');
        }
    }

    /**
     * Dona de avance. FPDF no trae arcos: se aproxima cada tramo con curvas de
     * Bézier (`sector`) y el centro se tapa con un círculo blanco.
     */
    protected function donut(float $cx, float $cy, float $r, float $pct, array $rgb): void
    {
        $pct = max(0, min(100, $pct));

        $this->fill([228, 233, 239]);
        $this->sector($cx, $cy, $r, 0, 360);

        if ($pct > 0) {
            $this->fill($rgb);
            $this->sector($cx, $cy, $r, 0, min(359.99, $pct * 3.6));
        }

        $this->fill([255, 255, 255]);
        $this->sector($cx, $cy, $r * 0.62, 0, 360);

        $this->SetFont('Arial', 'B', 13);
        $this->ink($this->brandInk);
        $this->SetXY($cx - $r, $cy - 5);
        $this->Cell($r * 2, 6, $this->t(number_format($pct, 2, ',', '.') . ' %'), 0, 0, 'C');

        $this->SetFont('Arial', '', 6.5);
        $this->ink(self::MUTED);
        $this->SetXY($cx - $r, $cy + 1);
        $this->Cell($r * 2, 4, $this->t('% avance'), 0, 0, 'C');
    }

    /** Sector circular relleno, en grados y sentido horario desde las 12. */
    protected function sector(float $cx, float $cy, float $r, float $a, float $b): void
    {
        $k = $this->k;
        $h = $this->h;

        // De "grados horarios desde las 12" a radianes matemáticos.
        $a = deg2rad(90 - $a);
        $b = deg2rad(90 - $b);

        $this->_out(sprintf('%.2F %.2F m', ($cx) * $k, ($h - $cy) * $k));
        $this->_out(sprintf('%.2F %.2F l', ($cx + $r * cos($a)) * $k, ($h - ($cy - $r * sin($a))) * $k));

        // Tramos de ≤90° para que la aproximación de Bézier no se note.
        $steps = max(1, (int) ceil(abs($a - $b) / (M_PI / 2)));
        $delta = ($b - $a) / $steps;
        $ang   = $a;

        for ($i = 0; $i < $steps; $i++) {
            $next = $ang + $delta;
            $t    = 4 / 3 * tan(($next - $ang) / 4);

            // Control de arco→Bézier estándar; con $t negativo el mismo cálculo
            // sirve para el sentido horario (que es como avanza la dona).
            $x1 = $cx + $r * (cos($ang) - $t * sin($ang));
            $y1 = $cy - $r * (sin($ang) + $t * cos($ang));
            $x2 = $cx + $r * (cos($next) + $t * sin($next));
            $y2 = $cy - $r * (sin($next) - $t * cos($next));
            $x3 = $cx + $r * cos($next);
            $y3 = $cy - $r * sin($next);

            $this->_out(sprintf('%.2F %.2F %.2F %.2F %.2F %.2F c',
                $x1 * $k, ($h - $y1) * $k, $x2 * $k, ($h - $y2) * $k, $x3 * $k, ($h - $y3) * $k));

            $ang = $next;
        }

        $this->_out('h f');
    }

    /** Encabezado de sección dentro del documento (el "h2" de los imprimibles). */
    protected function sectionTitle(string $text): void
    {
        $this->ensureSpace(14);

        $this->SetFont('Arial', 'B', 8.5);
        $this->ink($this->brandInk);
        $this->SetXY(self::MARGIN, $this->y);
        $this->Cell(self::CONTENT_W, 5, $this->fit(mb_strtoupper($text, 'UTF-8'), self::CONTENT_W), 0, 0, 'L');

        $this->draw(self::LINE);
        $this->Line(self::MARGIN, $this->y + 5.4, self::MARGIN + self::CONTENT_W, $this->y + 5.4);

        $this->y += 8;
    }

    /**
     * Rejilla de dato/valor a N columnas. `$rows` = [['label'=>…,'value'=>…], …].
     * Los valores largos se envuelven en varias líneas y la fila crece con ellos.
     */
    protected function kvGrid(array $rows, int $cols = 3): void
    {
        if (! $rows) return;

        $colW = self::CONTENT_W / $cols;

        foreach (array_chunk($rows, $cols) as $chunk) {
            $this->SetFont('Arial', 'B', 8);
            $lines = 1;
            foreach ($chunk as $cell) {
                $lines = max($lines, count($this->wrap((string) ($cell['value'] ?? '-'), $this->textWidth($colW - 4))));
            }
            $rowH = 5 + $lines * 4;
            $this->ensureSpace($rowH + 2);

            $y = $this->y;
            foreach (array_values($chunk) as $i => $cell) {
                $x = self::MARGIN + $i * $colW;

                $this->SetFont('Arial', '', 6.5);
                $this->ink(self::MUTED);
                $this->SetXY($x, $y);
                $this->Cell($colW - 4, 4, $this->fit(mb_strtoupper((string) $cell['label'], 'UTF-8'), $colW - 4), 0, 0, 'L');

                $this->SetFont('Arial', 'B', 8);
                $this->ink(self::INK);
                $this->SetXY($x, $y + 3.6);
                $this->MultiCell($colW - 4, 4, $this->t((string) ($cell['value'] ?? '')) ?: '-', 0, 'L');
            }

            $this->y = $y + $rowH;
        }
    }

    /** Texto corrido que respeta saltos de línea y se parte por página. */
    protected function paragraph(?string $text, float $size = 8): void
    {
        $text = trim((string) $text);
        if ($text === '') return;

        $this->SetFont('Arial', '', $size);
        $this->ink(self::INK);

        foreach ($this->wrap($text, self::CONTENT_W) as $line) {
            $this->ensureSpace(6);
            $this->SetXY(self::MARGIN, $this->y);
            $this->Cell(self::CONTENT_W, 4.4, $line, 0, 0, 'L');
            $this->y += 4.4;
        }

        $this->y += 1.5;
    }

    /**
     * Tabla con encabezado que se repite al cambiar de página. `$cols` =
     * [['label'=>…,'w'=>mm,'align'=>'L|R'], …]; `$rows` = arreglos de strings.
     */
    protected function table(array $cols, array $rows, float $size = 7.5): void
    {
        if (! $rows) {
            $this->paragraph('Sin registros.', $size);
            return;
        }

        $header = function () use ($cols, $size) {
            $this->fill([244, 247, 251]);
            $this->draw(self::LINE);
            $this->SetFont('Arial', 'B', $size);
            $this->ink($this->brandInk);

            $x = self::MARGIN;
            foreach ($cols as $c) {
                $this->SetXY($x, $this->y);
                $this->Cell($c['w'], 6, $this->fit((string) $c['label'], $c['w'] - 2), 1, 0, $c['align'] ?? 'L', true);
                $x += $c['w'];
            }
            $this->y += 6;
        };

        $this->ensureSpace(18);
        $header();

        foreach ($rows as $row) {
            $this->SetFont('Arial', '', $size);
            $lines = 1;
            foreach ($cols as $i => $c) {
                $lines = max($lines, count($this->wrap((string) ($row[$i] ?? ''), $this->textWidth($c['w'] - 3))));
            }
            $rowH = max(5.5, $lines * 4 + 1.5);

            if ($this->y + $rowH > $this->bottomLimit()) {
                $this->AddPage();
                $header();
            }

            $x = self::MARGIN;
            $y = $this->y;
            foreach ($cols as $i => $c) {
                $this->draw(self::LINE);
                $this->Rect($x, $y, $c['w'], $rowH);

                $this->SetFont('Arial', '', $size);
                $this->ink(self::INK);
                $this->SetXY($x + 1.5, $y + 1);
                $this->MultiCell($c['w'] - 3, 4, $this->t((string) ($row[$i] ?? '')), 0, $c['align'] ?? 'L');
                $x += $c['w'];
            }

            $this->y = $y + $rowH;
        }

        $this->y += 2;
    }

    /** Parte un texto en líneas que caben en `$w` con la fuente activa. */
    /**
     * Ancho REAL de texto dentro de un MultiCell de ancho $w.
     *
     * FPDF le descuenta su propio margen de celda a cada lado, así que medir con $w a
     * secas cuenta de menos: el alto de fila salía para 2 renglones y MultiCell pintaba
     * 3, que se desbordaban sobre la fila siguiente.
     */
    protected function textWidth(float $w): float
    {
        return $w - 2 * $this->cMargin;
    }

    protected function wrap(string $text, float $w): array
    {
        $out = [];

        foreach (preg_split('/\r\n|\r|\n/', $this->t($text)) as $paragraph) {
            $line = '';
            foreach (explode(' ', $paragraph) as $word) {
                $try = $line === '' ? $word : "$line $word";
                if ($this->GetStringWidth($try) <= $w) { $line = $try; continue; }
                if ($line !== '') $out[] = $line;
                $line = $word;
            }
            $out[] = $line;
        }

        return $out ?: [''];
    }

    /**
     * Rejilla de imágenes (evidencia, firmas). Las que no se puedan dibujar se
     * ignoran: una foto corrupta no debe tumbar el documento.
     */
    protected function imageGrid(array $images, int $cols = 4, float $h = 34): void
    {
        if (! $images) return;

        $w = (self::CONTENT_W - ($cols - 1) * 3) / $cols;

        foreach (array_chunk($images, $cols) as $chunk) {
            $this->ensureSpace($h + 4);
            $y = $this->y;

            foreach (array_values($chunk) as $i => $img) {
                $x = self::MARGIN + $i * ($w + 3);
                try {
                    $this->Image($img, $x, $y, $w, $h, $this->imageType($img));
                    $this->draw(self::LINE);
                    $this->Rect($x, $y, $w, $h);
                } catch (\Throwable) {
                    // imagen ilegible: se omite
                }
            }

            $this->y = $y + $h + 3;
        }
    }

    /** FPDF necesita el tipo cuando la "ruta" es un data-URI o no trae extensión. */
    protected function imageType(string $img): string
    {
        if (str_starts_with($img, 'data:image/png')) return 'PNG';
        if (str_starts_with($img, 'data:image/jpeg') || str_starts_with($img, 'data:image/jpg')) return 'JPEG';

        return strtoupper(pathinfo(parse_url($img, PHP_URL_PATH) ?: $img, PATHINFO_EXTENSION)) === 'PNG' ? 'PNG' : 'JPEG';
    }

    /**
     * Lo llama cada imprimible justo antes de componer su ÚLTIMO bloque, para que la
     * firma final no acabe sola en una hoja. No hace nada si no hay firma al final.
     */
    protected function reserveTailForSignature(): void
    {
        if ($this->signature === 'end') $this->tailReserve = 30;
    }

    /**
     * Cierre de conformidad: una sola línea para nombre y firma, al final del
     * documento. Se llama al terminar de componer, nunca por página, para que no
     * queden firmas sueltas a media hoja.
     */
    protected function signatureBlock(string $caption = 'Nombre y Firma de Conformidad'): void
    {
        // La reserva era para esto: se libera antes de pedir el sitio.
        $this->tailReserve = 0;
        $this->ensureSpace(34);

        $w  = 110;
        $x  = $this->signatureX($w);
        $y  = $this->y + 16;

        $this->draw(self::INK);
        $this->SetLineWidth(0.3);
        $this->Line($x, $y, $x + $w, $y);
        $this->SetLineWidth(0.2);

        $this->SetFont('Arial', '', 8);
        $this->ink(self::INK);
        $this->SetXY($x, $y + 1.5);
        $this->Cell($w, 5, $this->t($caption), 0, 0, $this->signatureAlign === 'center' ? 'C' : strtoupper($this->signatureAlign[0]));

        $this->y = $y + 10;
    }

    protected function emptyBox(float $x, float $y, float $w, float $h): void
    {
        $this->SetFont('Arial', 'I', 7);
        $this->ink(self::MUTED);
        $this->SetXY($x, $y + $h / 2 - 3);
        $this->Cell($w, 5, $this->t('Sin datos en el periodo'), 0, 0, 'C');
    }
}
