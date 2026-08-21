<?php

namespace App\Services\Pdf;

use App\Models\AppSetting;
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

    /** Cierra el documento con la línea de "Nombre y Firma de Conformidad". */
    protected bool $signature = false;

    public function __construct(array $data)
    {
        parent::__construct('P', 'mm', 'A4');

        $this->data = $data;
        $this->logo = $this->resolveLogo();

        $this->SetAutoPageBreak(false);   // la paginación la decide ensureSpace()
        $this->SetMargins(self::MARGIN, self::MARGIN, self::MARGIN);
        $this->SetTitle($this->str('title'), true);
        $this->AliasNbPages();
    }

    /**
     * Activa el cierre de conformidad. Es opcional en TODOS los imprimibles porque
     * unos se archivan firmados por el cliente y otros son de consulta interna.
     */
    public function withSignature(bool $on = true): static
    {
        $this->signature = $on;

        return $this;
    }

    abstract public function render(): string;

    // ── Utilidades ────────────────────────────────────────────────────────────

    /** Las fuentes base de FPDF hablan cp1252; el sistema, UTF-8. */
    protected function t(?string $s): string
    {
        return iconv('UTF-8', 'windows-1252//TRANSLIT', (string) $s) ?: '';
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

    /** El logo del tenant, si se puede resolver a un archivo local legible. */
    protected function resolveLogo(): ?string
    {
        try {
            $url = AppSetting::allAsMap('default')['logo_url'] ?? null;
            if (! $url) return null;

            $path = parse_url($url, PHP_URL_PATH) ?: $url;
            if (! str_contains($path, '/storage/')) return null;

            $file = storage_path('app/public/' . ltrim(explode('/storage/', $path, 2)[1], '/'));
            $ext  = strtolower(pathinfo($file, PATHINFO_EXTENSION));

            return (is_file($file) && in_array($ext, ['png', 'jpg', 'jpeg'], true)) ? $file : null;
        } catch (\Throwable) {
            return null;   // el membrete es un adorno: nunca debe tumbar el reporte
        }
    }

    // ── Estructura de página ──────────────────────────────────────────────────

    public function Header(): void
    {
        $h = 20;
        $this->fill(self::NAVY);
        $this->Rect(0, 0, self::PAGE_W, $h, 'F');

        if ($this->logo) {
            try { $this->Image($this->logo, self::MARGIN, 4, 0, 12); } catch (\Throwable) {}
        }

        $this->ink([255, 255, 255]);
        $this->SetFont('Arial', 'B', 11);
        $this->SetXY(self::MARGIN + 22, 5);
        $this->Cell(self::CONTENT_W - 44, 5, $this->fit($this->str('title'), self::CONTENT_W - 44), 0, 2, 'C');

        $this->SetFont('Arial', '', 8.5);
        $sub = trim($this->str('client') . ' · ' . $this->str('site'), ' ·');
        if ($this->str('system')) $sub .= ' · ' . $this->str('system');
        $this->Cell(self::CONTENT_W - 44, 4.5, $this->fit($sub, self::CONTENT_W - 44), 0, 2, 'C');
        $this->Cell(self::CONTENT_W - 44, 4.5, $this->t($this->str('period_label')), 0, 2, 'C');

        $this->y = $h + 6;
    }

    public function Footer(): void
    {
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
        if ($this->y + $height > self::BOTTOM) $this->AddPage();
    }

    /** Título de banda: la separación entre secciones del tablero. */
    protected function bandTitle(string $text): void
    {
        // Reserva el título MÁS el primer bloque: un encabezado huérfano al pie de
        // página se lee como si la sección viniera vacía.
        $this->ensureSpace(10 + self::PANEL_H + 4);

        $this->SetFont('Arial', 'B', 10);
        $this->ink(self::NAVY);
        $this->SetXY(self::MARGIN, $this->y);
        $this->Cell(self::CONTENT_W, 6, $this->fit($text, self::CONTENT_W), 0, 0, 'C');

        $this->draw(self::NAVY);
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
        $this->ink(self::NAVY);
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

        $this->fill(self::NAVY);
        $this->Rect($x, $y, 1.6, $h, 'F');

        $this->SetFont('Arial', '', 7.5);
        $this->ink(self::MUTED);
        $this->SetXY($x + 4, $y + 3);
        $this->Cell($w - 6, 4, $this->fit($label, $w - 6), 0, 2, 'L');

        $this->SetFont('Arial', 'B', 16);
        $this->ink(self::NAVY);
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
        $this->ink(self::NAVY);
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

    /**
     * Cierre de conformidad: una sola línea para nombre y firma, al final del
     * documento. Se llama al terminar de componer, nunca por página, para que no
     * queden firmas sueltas a media hoja.
     */
    protected function signatureBlock(string $caption = 'Nombre y Firma de Conformidad'): void
    {
        $this->ensureSpace(34);

        $w  = 110;
        $x  = self::MARGIN + (self::CONTENT_W - $w) / 2;
        $y  = $this->y + 16;

        $this->draw(self::INK);
        $this->SetLineWidth(0.3);
        $this->Line($x, $y, $x + $w, $y);
        $this->SetLineWidth(0.2);

        $this->SetFont('Arial', '', 8);
        $this->ink(self::INK);
        $this->SetXY($x, $y + 1.5);
        $this->Cell($w, 5, $this->t($caption), 0, 0, 'C');

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
