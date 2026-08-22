<?php

namespace App\Services\Reports;

use App\Services\Pdf\Pdf;

/**
 * Reporte EJECUTIVO en PDF: acumulados generales del sitio y una sección por tipo de
 * servicio (preventivo, pruebas, correctivos…), cada una con su avance, su reparto
 * por tipo de dispositivo, sus agrupaciones del directorio y su serie por fecha.
 *
 * Los datos vienen ya calculados de ExecutiveReport: aquí no se consulta nada.
 */
class ExecutivePdf extends Pdf
{
    // ── Composición ───────────────────────────────────────────────────────────


    public function render(): string
    {
        $this->AddPage();

        if ($summary = $this->data['summary'] ?? null) {
            $this->renderSummary($summary);
        }

        foreach (array_values($this->data['sections'] ?? []) as $i => $section) {
            $this->renderSection($section, self::SERIES[$i % count(self::SERIES)]);
        }

        if (! ($this->data['summary'] ?? null) && ! ($this->data['sections'] ?? [])) {
            $this->SetFont('Arial', '', 10);
            $this->ink(self::MUTED);
            $this->SetXY(self::MARGIN, 60);
            $this->Cell(self::CONTENT_W, 6, $this->t('No hay servicios registrados en el periodo seleccionado.'), 0, 0, 'C');
        }

        if ($this->signature) $this->signatureBlock();

        return $this->Output('S');
    }

    private function renderSummary(array $summary): void
    {
        $this->bandTitle('Acumulados generales de los servicios brindados');

        // Datos duros + reparto por tipo de servicio, en la misma banda.
        $h = self::KPI_H;
        $this->ensureSpace($h + 4);
        $y = $this->y;

        $this->kpi(self::MARGIN, $y, 58, $h, 'Total de dispositivos', $this->num($summary['total_devices'] ?? 0));
        $this->kpi(self::MARGIN + 62, $y, 58, $h, 'Total de servicios brindados', $this->num($summary['total_services'] ?? 0));

        $px = self::MARGIN + 124;
        $pw = self::CONTENT_W - 124;
        $cy = $this->panel($px, $y, $pw, $h, 'Tipos de servicios brindados');
        $this->hBarsSeries($summary['service_types'] ?? [], $px + 1, $cy, $pw - 2, $h - 11);

        $this->y = $y + $h + 5;

        // Un panel por agrupación, dos por renglón.
        $this->grid($summary['groups'] ?? [], $this->brandDark, 'Total de servicios por ');
    }

    /** Barras del reparto por tipo de servicio, cada una con su color de sección. */
    private function hBarsSeries(array $rows, float $x, float $y, float $w, float $h): void
    {
        if (! $rows) { $this->emptyBox($x, $y, $w, $h); return; }

        $max    = max(1, max(array_column($rows, 'count')));
        $labelW = $w * 0.40;
        $trackW = $w - $labelW - 15;
        $rowH   = min(6, $h / count($rows));

        foreach ($rows as $i => $row) {
            $ry  = $y + $i * $rowH;
            $rgb = self::SERIES[$i % count(self::SERIES)];

            $this->SetFont('Arial', '', 6.5);
            $this->ink(self::INK);
            $this->SetXY($x + 1, $ry);
            $this->Cell($labelW, $rowH, $this->fit((string) $row['label'], $labelW - 1), 0, 0, 'R');

            $len = max(0.6, $trackW * ($row['count'] / $max));
            $this->fill($rgb);
            $this->Rect($x + $labelW + 2, $ry + $rowH / 2 - 1.8, $len, 3.6, 'F');

            $this->SetFont('Arial', 'B', 6.5);
            $this->SetXY($x + $labelW + 3 + $len, $ry);
            $this->Cell(13, $rowH, $this->num($row['count']), 0, 0, 'L');
        }
    }

    /** Rejilla de paneles de barras a dos columnas. */
    private function grid(array $groups, array $rgb, string $prefix): void
    {
        $h = self::PANEL_H;

        foreach (array_chunk($groups, 2) as $pair) {
            $this->ensureSpace($h + 4);
            $y = $this->y;

            foreach ($pair as $i => $group) {
                $x  = self::MARGIN + $i * (self::COL_W + self::GUTTER);
                $cy = $this->panel($x, $y, self::COL_W, $h, $prefix . mb_strtolower($group['label']));
                $this->hBars($group['rows'] ?? [], $x + 1, $cy, self::COL_W - 2, $h - 11, $rgb);
            }

            $this->y = $y + $h + 4;
        }
    }

    private function renderSection(array $section, array $rgb): void
    {
        $this->bandTitle($section['title'] ?? $section['label'] ?? '');

        // Avance + reparto por tipo de dispositivo.
        $h = self::PANEL_H;
        $this->ensureSpace($h + 4);
        $y = $this->y;

        $hasProgress = $section['progress_pct'] !== null;
        $leftW       = 58;

        if ($hasProgress) {
            $cy = $this->panel(self::MARGIN, $y, $leftW, $h, 'Avance sobre el directorio');
            $this->donut(self::MARGIN + $leftW / 2, $cy + 17, 14.5, (float) $section['progress_pct'], $rgb);

            $this->SetFont('Arial', '', 6.5);
            $this->ink(self::MUTED);
            $this->SetXY(self::MARGIN + 2, $cy + 34);
            $this->Cell($leftW - 4, 4, $this->t(
                $this->num($section['devices_covered'] ?? 0) . ' dispositivos atendidos'
            ), 0, 0, 'C');
        }

        $rx = $hasProgress ? self::MARGIN + $leftW + self::GUTTER : self::MARGIN;
        $rw = self::CONTENT_W - ($hasProgress ? $leftW + self::GUTTER : 0);

        $cy = $this->panel($rx, $y, $rw, $h, 'Total de ' . mb_strtolower($section['label']) . ' por tipo de dispositivo');
        $this->hBars($section['by_device_type'] ?? [], $rx + 1, $cy, $rw - 2, $h - 11, $rgb);

        // Total del periodo, discreto bajo el panel de avance.
        $this->y = $y + $h + 4;

        $this->grid($section['groups'] ?? [], $rgb, 'Total de ' . mb_strtolower($section['label']) . ' por ');

        if ($timeline = $section['timeline'] ?? []) {
            $th = self::TIME_H;
            $this->ensureSpace($th + 4);
            $y  = $this->y;
            $cy = $this->panel(self::MARGIN, $y, self::CONTENT_W, $th, mb_convert_case($section['label'], MB_CASE_TITLE, 'UTF-8') . ' por fecha');
            $this->vBars($timeline, self::MARGIN + 2, $cy, self::CONTENT_W - 4, $th - 11, $rgb);
            $this->y = $y + $th + 4;
        }
    }
}
