<?php

namespace App\Services\Pdf;

/**
 * Imprimible genérico de un tablero: una fila de indicadores y luego bloques de
 * barras, series por fecha y tablas. Lo usan los dos reportes-tablero (eventos y
 * mantenimientos) y las bitácoras, que sólo tienen que traducir su payload a esta
 * forma en vez de tener cada uno su propia maqueta.
 *
 * `data` = [
 *   'meta'   => [...],                                  // membrete (ver Pdf::Header)
 *   'kpis'   => [['label'=>…,'value'=>…], …],
 *   'blocks' => [
 *      ['type'=>'bars',     'title'=>…, 'rows'=>[['label'=>…,'count'=>…], …], 'half'=>true],
 *      ['type'=>'timeline', 'title'=>…, 'rows'=>[['label'=>…,'month'=>…,'count'=>…], …]],
 *      ['type'=>'table',    'title'=>…, 'cols'=>[['label'=>…,'w'=>…], …], 'rows'=>[[…], …]],
 *      ['type'=>'section',  'title'=>…],                // sólo un título de banda
 *   ],
 * ]
 */
class DashboardPdf extends Pdf
{
    public function render(): string
    {
        $this->AddPage();

        $this->kpiRow($this->data['kpis'] ?? []);

        // Los bloques de barras marcados como `half` se emparejan de dos en dos;
        // el resto ocupa el ancho completo.
        $blocks  = array_values($this->data['blocks'] ?? []);
        $pending = null;

        foreach ($blocks as $block) {
            $isHalf = ($block['type'] ?? '') === 'bars' && ($block['half'] ?? true);

            if ($isHalf) {
                if ($pending === null) { $pending = $block; continue; }
                $this->barsRow($pending, $block);
                $pending = null;
                continue;
            }

            if ($pending !== null) { $this->barsRow($pending, null); $pending = null; }

            match ($block['type'] ?? '') {
                'timeline' => $this->timelineBlock($block),
                'table'    => $this->tableBlock($block),
                'section'  => $this->bandTitle($block['title'] ?? ''),
                'bars'     => $this->barsRow($block, null),
                default    => null,
            };
        }

        if ($pending !== null) $this->barsRow($pending, null);

        if ($this->signature === 'end') $this->signatureBlock();

        return $this->Output('S');
    }

    private function kpiRow(array $kpis): void
    {
        if (! $kpis) return;

        foreach (array_chunk($kpis, 4) as $chunk) {
            $this->ensureSpace(self::KPI_H + 4);

            $w = (self::CONTENT_W - 3 * 4) / 4;
            $y = $this->y;

            foreach (array_values($chunk) as $i => $kpi) {
                $this->kpi(
                    self::MARGIN + $i * ($w + 4), $y, $w, self::KPI_H,
                    (string) $kpi['label'],
                    (string) $kpi['value'],
                );
            }

            $this->y = $y + self::KPI_H + 4;
        }
    }

    private function barsRow(array $left, ?array $right): void
    {
        $h = self::PANEL_H;
        $this->ensureSpace($h + 4);
        $y = $this->y;

        $full = $right === null && ! ($left['half'] ?? true);
        $w    = $full ? self::CONTENT_W : self::COL_W;

        $cy = $this->panel(self::MARGIN, $y, $w, $h, $left['title'] ?? '');
        $this->hBars($left['rows'] ?? [], self::MARGIN + 1, $cy, $w - 2, $h - 11, $this->brandInk);

        if ($right) {
            $x  = self::MARGIN + self::COL_W + self::GUTTER;
            $cy = $this->panel($x, $y, self::COL_W, $h, $right['title'] ?? '');
            $this->hBars($right['rows'] ?? [], $x + 1, $cy, self::COL_W - 2, $h - 11, $this->brandInk);
        }

        $this->y = $y + $h + 4;
    }

    private function timelineBlock(array $block): void
    {
        $h = self::TIME_H;
        $this->ensureSpace($h + 4);
        $y = $this->y;

        $cy = $this->panel(self::MARGIN, $y, self::CONTENT_W, $h, $block['title'] ?? '');
        $this->vBars($block['rows'] ?? [], self::MARGIN + 2, $cy, self::CONTENT_W - 4, $h - 11, $this->brandInk);

        $this->y = $y + $h + 4;
    }

    private function tableBlock(array $block): void
    {
        $this->sectionTitle($block['title'] ?? '');
        $this->table($block['cols'] ?? [], $block['rows'] ?? [], $block['size'] ?? 7);
    }
}
