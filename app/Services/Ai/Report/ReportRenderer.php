<?php

namespace App\Services\Ai\Report;

use Illuminate\Support\Carbon;

/**
 * Renderiza un reporte visual (HTML autocontenido: CSS embebido + gráficas SVG
 * inline, sin dependencias externas → descargable y visible offline). Recibe una
 * estructura declarativa (título + secciones: kpis, texto, tabla, gráfica) que
 * arma el asistente con sus herramientas.
 */
class ReportRenderer
{
    /** Paleta categórica accesible (contrasta en claro). */
    private const PALETTE = ['#3b5bdb', '#0ca678', '#f08c00', '#e8590c', '#7048e8', '#1098ad', '#e64980', '#66a80f'];

    public function render(array $spec): string
    {
        $title    = (string) ($spec['title'] ?? 'Reporte');
        $subtitle = $spec['subtitle'] ?? null;
        $date     = Carbon::now()->locale('es')->isoFormat('D [de] MMMM [de] YYYY, HH:mm');

        $body = '';
        foreach ((array) ($spec['sections'] ?? []) as $section) {
            $body .= match ($section['type'] ?? '') {
                'kpis'  => $this->kpis($section),
                'text'  => $this->text($section),
                'table' => $this->table($section),
                'chart' => $this->chart($section),
                default => '',
            };
        }

        $t  = $this->e($title);
        $st = $subtitle ? '<p class="subtitle">' . $this->e($subtitle) . '</p>' : '';
        $css = $this->css();

        return <<<HTML
        <!doctype html>
        <html lang="es">
        <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>{$t}</title>
        <style>{$css}</style>
        </head>
        <body>
          <div class="page">
            <header class="report-head">
              <div>
                <h1>{$t}</h1>
                {$st}
              </div>
              <div class="brand">Mantenimientos Siccob</div>
            </header>
            <p class="meta">Generado el {$date}</p>
            {$body}
            <footer class="report-foot">Reporte generado por el asistente de IA · Mantenimientos Siccob</footer>
          </div>
        </body>
        </html>
        HTML;
    }

    // ── Secciones ──────────────────────────────────────────────────────────────

    private function kpis(array $s): string
    {
        $cards = '';
        foreach ((array) ($s['items'] ?? []) as $it) {
            $label = $this->e($it['label'] ?? '');
            $value = $this->e((string) ($it['value'] ?? ''));
            $hint  = isset($it['hint']) ? '<div class="kpi-hint">' . $this->e($it['hint']) . '</div>' : '';
            $cards .= "<div class=\"kpi\"><div class=\"kpi-label\">{$label}</div><div class=\"kpi-value\">{$value}</div>{$hint}</div>";
        }
        return "<section class=\"kpis\">{$cards}</section>";
    }

    private function text(array $s): string
    {
        $h = ! empty($s['heading']) ? '<h2>' . $this->e($s['heading']) . '</h2>' : '';
        $c = nl2br($this->e((string) ($s['content'] ?? '')));
        return "<section class=\"block\">{$h}<p class=\"prose\">{$c}</p></section>";
    }

    private function table(array $s): string
    {
        $title   = ! empty($s['title']) ? '<h2>' . $this->e($s['title']) . '</h2>' : '';
        $cols    = (array) ($s['columns'] ?? []);
        $rows    = (array) ($s['rows'] ?? []);

        $head = '';
        foreach ($cols as $c) {
            $head .= '<th>' . $this->e((string) $c) . '</th>';
        }

        $bodyRows = '';
        foreach ($rows as $row) {
            $tds = '';
            foreach ((array) $row as $cell) {
                $num = is_numeric($cell) ? ' class="num"' : '';
                $tds .= "<td{$num}>" . $this->e((string) $cell) . '</td>';
            }
            $bodyRows .= "<tr>{$tds}</tr>";
        }

        return "<section class=\"block\">{$title}<div class=\"table-wrap\"><table><thead><tr>{$head}</tr></thead><tbody>{$bodyRows}</tbody></table></div></section>";
    }

    private function chart(array $s): string
    {
        $title = ! empty($s['title']) ? '<h2>' . $this->e($s['title']) . '</h2>' : '';
        $kind  = $s['chart'] ?? 'bar';
        $svg   = match ($kind) {
            'line'         => $this->lineChart($s),
            'pie', 'donut' => $this->pieChart($s, $kind === 'donut'),
            default        => $this->barChart($s),
        };
        return "<section class=\"block\">{$title}<div class=\"chart\">{$svg}</div></section>";
    }

    // ── Gráficas SVG ─────────────────────────────────────────────────────────────

    private function barChart(array $s): string
    {
        $labels = array_map('strval', (array) ($s['labels'] ?? []));
        $series = (array) ($s['series'] ?? []);
        if ($labels === [] || $series === []) {
            return '<p class="empty">Sin datos para graficar.</p>';
        }

        $W = 760; $H = 340; $padL = 44; $padB = 56; $padT = 16; $padR = 16;
        $plotW = $W - $padL - $padR; $plotH = $H - $padT - $padB;

        $max = 0.0;
        foreach ($series as $ser) {
            foreach ((array) ($ser['data'] ?? []) as $v) { $max = max($max, (float) $v); }
        }
        $max = $max > 0 ? $max : 1;

        $groups   = count($labels);
        $perGroup = count($series);
        $gap      = 0.35; // proporción de espacio entre grupos
        $groupW   = $plotW / $groups;
        $barW     = ($groupW * (1 - $gap)) / max(1, $perGroup);

        $bars = ''; $xLabels = '';
        foreach ($labels as $gi => $lab) {
            $gx = $padL + $gi * $groupW + ($groupW * $gap / 2);
            foreach ($series as $si => $ser) {
                $v  = (float) (($ser['data'] ?? [])[$gi] ?? 0);
                $h  = ($v / $max) * $plotH;
                $x  = $gx + $si * $barW;
                $y  = $padT + $plotH - $h;
                $col = self::PALETTE[$si % count(self::PALETTE)];
                $bars .= "<rect x=\"" . round($x, 1) . "\" y=\"" . round($y, 1) . "\" width=\"" . round($barW - 3, 1) . "\" height=\"" . round($h, 1) . "\" rx=\"3\" fill=\"{$col}\"><title>" . $this->e((string) $v) . "</title></rect>";
                if ($perGroup === 1) {
                    $bars .= "<text x=\"" . round($x + $barW / 2, 1) . "\" y=\"" . round($y - 5, 1) . "\" class=\"bar-val\">" . $this->e($this->fmt($v)) . "</text>";
                }
            }
            $xLabels .= "<text x=\"" . round($padL + $gi * $groupW + $groupW / 2, 1) . "\" y=\"" . ($H - $padB + 20) . "\" class=\"x-lab\">" . $this->e($lab) . "</text>";
        }

        $axis   = "<line x1=\"{$padL}\" y1=\"" . ($padT + $plotH) . "\" x2=\"" . ($W - $padR) . "\" y2=\"" . ($padT + $plotH) . "\" class=\"axis\"/>";
        $legend = $perGroup > 1 ? $this->legend($series) : '';

        return "<svg viewBox=\"0 0 {$W} {$H}\" class=\"svg-chart\" role=\"img\">{$axis}{$bars}{$xLabels}</svg>{$legend}";
    }

    private function lineChart(array $s): string
    {
        $labels = array_map('strval', (array) ($s['labels'] ?? []));
        $series = (array) ($s['series'] ?? []);
        if ($labels === [] || $series === []) {
            return '<p class="empty">Sin datos para graficar.</p>';
        }

        $W = 760; $H = 340; $padL = 44; $padB = 56; $padT = 16; $padR = 16;
        $plotW = $W - $padL - $padR; $plotH = $H - $padT - $padB;

        $max = 0.0;
        foreach ($series as $ser) {
            foreach ((array) ($ser['data'] ?? []) as $v) { $max = max($max, (float) $v); }
        }
        $max = $max > 0 ? $max : 1;

        $n    = count($labels);
        $stepX = $n > 1 ? $plotW / ($n - 1) : $plotW;

        $paths = '';
        foreach ($series as $si => $ser) {
            $col = self::PALETTE[$si % count(self::PALETTE)];
            $pts = [];
            foreach ($labels as $i => $_) {
                $v = (float) (($ser['data'] ?? [])[$i] ?? 0);
                $x = $padL + $i * $stepX;
                $y = $padT + $plotH - ($v / $max) * $plotH;
                $pts[] = round($x, 1) . ',' . round($y, 1);
            }
            $poly = implode(' ', $pts);
            $paths .= "<polyline points=\"{$poly}\" fill=\"none\" stroke=\"{$col}\" stroke-width=\"2.5\" stroke-linejoin=\"round\" stroke-linecap=\"round\"/>";
            foreach ($pts as $p) {
                [$px, $py] = explode(',', $p);
                $paths .= "<circle cx=\"{$px}\" cy=\"{$py}\" r=\"3\" fill=\"{$col}\"/>";
            }
        }

        $xLabels = '';
        foreach ($labels as $i => $lab) {
            $xLabels .= "<text x=\"" . round($padL + $i * $stepX, 1) . "\" y=\"" . ($H - $padB + 20) . "\" class=\"x-lab\">" . $this->e($lab) . "</text>";
        }
        $axis   = "<line x1=\"{$padL}\" y1=\"" . ($padT + $plotH) . "\" x2=\"" . ($W - $padR) . "\" y2=\"" . ($padT + $plotH) . "\" class=\"axis\"/>";
        $legend = count($series) > 1 ? $this->legend($series) : '';

        return "<svg viewBox=\"0 0 {$W} {$H}\" class=\"svg-chart\" role=\"img\">{$axis}{$paths}{$xLabels}</svg>{$legend}";
    }

    private function pieChart(array $s, bool $donut): string
    {
        $labels = array_map('strval', (array) ($s['labels'] ?? []));
        $data   = (array) (($s['series'][0]['data'] ?? []));
        if ($labels === [] || $data === []) {
            return '<p class="empty">Sin datos para graficar.</p>';
        }

        $total = array_sum(array_map('floatval', $data));
        $total = $total > 0 ? $total : 1;

        $cx = 170; $cy = 170; $r = 150; $angle = -M_PI / 2;
        $arcs = ''; $legendItems = [];
        foreach ($data as $i => $v) {
            $frac = (float) $v / $total;
            $end  = $angle + $frac * 2 * M_PI;
            $x1 = $cx + $r * cos($angle); $y1 = $cy + $r * sin($angle);
            $x2 = $cx + $r * cos($end);   $y2 = $cy + $r * sin($end);
            $large = $frac > 0.5 ? 1 : 0;
            $col = self::PALETTE[$i % count(self::PALETTE)];
            $arcs .= "<path d=\"M{$cx},{$cy} L" . round($x1, 1) . ',' . round($y1, 1) . " A{$r},{$r} 0 {$large},1 " . round($x2, 1) . ',' . round($y2, 1) . " Z\" fill=\"{$col}\"><title>" . $this->e(($labels[$i] ?? '') . ': ' . $this->fmt((float) $v)) . "</title></path>";
            $legendItems[] = ['name' => $labels[$i] ?? '', 'pct' => round($frac * 100)];
            $angle = $end;
        }
        $hole = $donut ? "<circle cx=\"{$cx}\" cy=\"{$cy}\" r=\"" . round($r * 0.58) . "\" fill=\"#fff\"/>" : '';

        $legend = '<div class="legend">';
        foreach ($legendItems as $i => $li) {
            $col = self::PALETTE[$i % count(self::PALETTE)];
            $legend .= "<span class=\"leg\"><span class=\"dot\" style=\"background:{$col}\"></span>" . $this->e($li['name']) . " <b>{$li['pct']}%</b></span>";
        }
        $legend .= '</div>';

        return "<svg viewBox=\"0 0 340 340\" class=\"svg-pie\" role=\"img\">{$arcs}{$hole}</svg>{$legend}";
    }

    private function legend(array $series): string
    {
        $out = '<div class="legend">';
        foreach ($series as $i => $ser) {
            $col = self::PALETTE[$i % count(self::PALETTE)];
            $out .= "<span class=\"leg\"><span class=\"dot\" style=\"background:{$col}\"></span>" . $this->e((string) ($ser['name'] ?? ('Serie ' . ($i + 1)))) . '</span>';
        }
        return $out . '</div>';
    }

    // ── Utilidades ───────────────────────────────────────────────────────────────

    private function fmt(float $v): string
    {
        return $v == (int) $v ? (string) (int) $v : number_format($v, 2);
    }

    private function e(string $v): string
    {
        return htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function css(): string
    {
        return <<<CSS
        *{box-sizing:border-box;margin:0;padding:0}
        body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;background:#eef1f6;color:#1f2430;line-height:1.5;padding:24px}
        .page{max-width:900px;margin:0 auto;background:#fff;border-radius:18px;box-shadow:0 10px 40px rgba(20,30,60,.08);padding:40px 44px}
        .report-head{display:flex;justify-content:space-between;align-items:flex-start;gap:16px;border-bottom:2px solid #eef1f6;padding-bottom:18px}
        h1{font-size:26px;font-weight:800;letter-spacing:-.02em;color:#141b2d}
        .subtitle{color:#5b6472;font-size:14px;margin-top:4px}
        .brand{font-size:12px;font-weight:700;color:#3b5bdb;background:#eef2ff;padding:6px 12px;border-radius:999px;white-space:nowrap}
        .meta{color:#8a93a3;font-size:12px;margin:14px 0 8px}
        section.block{margin-top:28px}
        h2{font-size:16px;font-weight:700;color:#2a3245;margin-bottom:12px}
        .prose{font-size:14.5px;color:#3a4252}
        .kpis{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:14px;margin-top:24px}
        .kpi{background:#f7f9fc;border:1px solid #eaeef5;border-radius:14px;padding:16px 18px}
        .kpi-label{font-size:12px;color:#6b7280;font-weight:600;text-transform:uppercase;letter-spacing:.04em}
        .kpi-value{font-size:28px;font-weight:800;color:#141b2d;margin-top:6px;letter-spacing:-.02em}
        .kpi-hint{font-size:12px;color:#8a93a3;margin-top:2px}
        .table-wrap{overflow-x:auto;border:1px solid #eaeef5;border-radius:12px}
        table{width:100%;border-collapse:collapse;font-size:13.5px}
        thead th{background:#f7f9fc;text-align:left;padding:11px 14px;font-weight:700;color:#4b5563;border-bottom:1px solid #eaeef5;white-space:nowrap}
        tbody td{padding:10px 14px;border-bottom:1px solid #f1f4f9;color:#374151}
        tbody tr:last-child td{border-bottom:none}
        tbody tr:nth-child(even){background:#fafbfd}
        td.num{text-align:right;font-variant-numeric:tabular-nums}
        .chart{margin-top:6px}
        .svg-chart{width:100%;height:auto;max-height:360px}
        .svg-pie{width:280px;max-width:100%;height:auto}
        .axis{stroke:#d5dbe6;stroke-width:1}
        .x-lab{font-size:11px;fill:#6b7280;text-anchor:middle}
        .bar-val{font-size:11px;fill:#374151;text-anchor:middle;font-weight:600}
        .legend{display:flex;flex-wrap:wrap;gap:14px;margin-top:12px}
        .leg{display:inline-flex;align-items:center;gap:6px;font-size:12.5px;color:#4b5563}
        .dot{width:11px;height:11px;border-radius:3px;display:inline-block}
        .empty{color:#8a93a3;font-size:13px;font-style:italic}
        .report-foot{margin-top:36px;padding-top:16px;border-top:1px solid #eef1f6;color:#a0a8b6;font-size:11px;text-align:center}
        @media print{body{background:#fff;padding:0}.page{box-shadow:none;border-radius:0}}
        CSS;
    }
}
