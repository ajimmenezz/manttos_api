<?php

namespace App\Services\Pdf;

/**
 * Una bitácora como DOCUMENTO, no como tabla. La usan la de EVENTOS y la de
 * MANTENIMIENTO: las dos se leen igual porque las dibuja el mismo renderizador.
 *
 * Antes cada una era una tabla de seis columnas: cabía todo pero no se leía nada —el
 * detalle del formulario terminaba apelmazado en una celda "Detalle"— y los campos
 * dinámicos se perdían. Aquí cada registro es una ficha con lo mismo que muestra la
 * pantalla: sus datos, el dispositivo si lo hay y los campos marcados para bitácora.
 *
 * El contenido llega ya resuelto y con nombres genéricos (`title`, `badge`, `pairs`,
 * `wide`, `blocks`, `fields`, `images`): el renderizador no sabe si son eventos o
 * capturas, y por eso puede servir a ambas sin ramas internas.
 *
 * Está apretado a propósito, como una forma impresa: cuerpo de 7.2 pt, rótulos de 6 pt
 * y renglones de 3.5 mm. El objetivo es no gastar hojas de más sin perder información,
 * así que se prefieren renglones de dos columnas antes que bloques sueltos.
 *
 * **No se mide y luego se dibuja**: cada renglón pide su propio espacio con
 * `ensureSpace()`, de modo que una ficha larga se parte entre hojas sin cálculos
 * previos que puedan quedar desincronizados con lo que realmente se pinta.
 */
class LogPdf extends Pdf
{
    private const PAD      = 2.2;    // aire interno de cada ficha
    private const LINE_H   = 3.5;    // alto de renglón
    private const LABEL_W  = 19;     // ancho del rótulo en un par etiqueta/valor
    private const GAP      = 4;      // canal entre las dos columnas
    private const F_LABEL  = 6;
    private const F_VALUE  = 7.2;

    /** Máximo de miniaturas por bloque; más allá se resume. Evita bitácoras de 80 hojas. */
    private const MAX_THUMBS = 8;

    private const TINT = [246, 248, 251];

    public function render(): string
    {
        $this->AddPage();

        $count = (int) ($this->data['summary']['count'] ?? 0);
        $this->intro($count);

        $total = array_sum(array_map(fn ($d) => count($d['entries'] ?? []), $this->data['days'] ?? []));
        $n     = 0;

        foreach ($this->data['days'] ?? [] as $day) {
            $this->dayHeader((string) ($day['label'] ?? ''));

            foreach ($day['entries'] ?? [] as $entry) {
                if (++$n === $total) $this->reserveTailForSignature();
                $this->entry($entry);
            }
        }

        if ($count === 0) {
            $this->paragraph('No hay registros en el periodo seleccionado.');
        }

        if ($this->signature === 'end') $this->signatureBlock();

        return $this->Output('S');
    }

    private function intro(int $count): void
    {
        $this->SetFont('Arial', '', 7.5);
        $this->ink(self::MUTED);
        $this->SetXY(self::MARGIN, $this->y);
        // El sustantivo lo pone quien arma los datos: «eventos» o «capturas».
        $noun = $this->data['summary']['noun'] ?? 'registro';
        $this->Cell(self::CONTENT_W, 4, $this->t($count . ' ' . $noun . ($count === 1 ? '' : 's') . ' en el periodo'), 0, 0, 'L');

        $this->y += 6;
    }

    /** Separador de día: la bitácora es cronológica y el día es su unidad de lectura. */
    private function dayHeader(string $label): void
    {
        $this->ensureSpace(20);   // un día no debe quedar huérfano al pie de la hoja

        $this->SetFont('Arial', 'B', 7.5);
        $this->ink($this->brandInk);
        $this->SetXY(self::MARGIN, $this->y);
        // Sólo la inicial en mayúscula: `MB_CASE_TITLE` capitaliza también las
        // preposiciones y dejaba «Martes 11 De Agosto De 2026».
        $this->Cell(self::CONTENT_W, 4.6, $this->fit(mb_strtoupper(mb_substr($label, 0, 1, 'UTF-8'), 'UTF-8') . mb_substr($label, 1, null, 'UTF-8'), self::CONTENT_W), 0, 0, 'L');

        $this->draw($this->brandInk);
        $this->SetLineWidth(0.4);
        $this->Line(self::MARGIN, $this->y + 4.9, self::MARGIN + self::CONTENT_W, $this->y + 4.9);
        $this->SetLineWidth(0.2);

        $this->y += 7;
    }

    private function entry(array $e): void
    {
        // La banda y las primeras líneas viajan juntas: un encabezado de ficha solo al
        // pie de la hoja obliga a pasar la página para saber de qué registro se habla.
        $this->ensureSpace(22);
        $this->band($e);

        $this->pairs($e['pairs'] ?? []);

        // Valores que no caben en media línea (dispositivo, directorio…).
        foreach ($e['wide'] ?? [] as [$label, $value]) {
            if (trim((string) $value) !== '') $this->wide((string) $label, (string) $value);
        }

        // Rótulo arriba y texto corrido debajo (descripción, notas…).
        foreach ($e['blocks'] ?? [] as [$label, $text]) {
            if (trim((string) $text) !== '') $this->block((string) $label, (string) $text);
        }

        foreach ($e['fields'] ?? [] as $field) $this->field($field);

        if (! empty($e['images'])) $this->thumbs($e['images_label'] ?? 'Imágenes', $e['images']);

        // Cierre de la ficha: sin él, dos registros seguidos se leen como uno solo.
        $this->ensureSpace(3);
        $this->draw(self::LINE);
        $this->Line(self::MARGIN, $this->y + 0.5, self::MARGIN + self::CONTENT_W, $this->y + 0.5);
        $this->y += 3.5;
    }

    /** Banda de identificación: título a la izquierda, distintivo (folio, DID…) a la derecha. */
    private function band(array $e): void
    {
        $h = 5.4;

        $this->fill(self::TINT);
        $this->Rect(self::MARGIN, $this->y, self::CONTENT_W, $h, 'F');

        // Filete de color (el estado del evento, por ejemplo): da la lectura de un
        // vistazo, como el punto de color de la pantalla.
        if ($rgb = $this->hex($e['accent'] ?? null)) {
            $this->fill($rgb);
            $this->Rect(self::MARGIN, $this->y, 1.4, $h, 'F');
        }

        $badge  = (string) ($e['badge'] ?? '');
        $badgeW = 0;

        if ($badge !== '') {
            $this->SetFont('Arial', 'B', 7.5);
            $badgeW = $this->GetStringWidth($this->t($badge)) + 2;
            $this->ink($this->brandInk);
            $this->SetXY(self::MARGIN + self::CONTENT_W - $badgeW - self::PAD, $this->y + 1.1);
            $this->Cell($badgeW, 3.4, $this->t($badge), 0, 0, 'R');
        }

        $title = trim((string) ($e['title'] ?? ''));
        $w     = self::CONTENT_W - $badgeW - 2 * self::PAD - 2;

        $this->SetFont('Arial', 'B', 7.5);
        $this->ink(self::INK);
        $this->SetXY(self::MARGIN + self::PAD + 1.4, $this->y + 1.1);
        $this->Cell($w, 3.4, $this->fit($title, $w), 0, 0, 'L');

        $this->y += $h + 1.2;
    }

    /**
     * Pares etiqueta/valor a dos columnas.
     *
     * Es lo que más aprieta el documento: en la pantalla cada dato es una línea, aquí
     * caben dos por renglón. Los vacíos se descartan antes de repartir para que no
     * queden huecos a media rejilla.
     */
    private function pairs(array $pairs): void
    {
        $pairs = array_values(array_filter(
            $pairs,
            fn ($p) => ($p[1] ?? null) !== null && trim((string) $p[1]) !== '' && $p[1] !== '—',
        ));

        if (! $pairs) return;

        $colW = (self::CONTENT_W - 2 * self::PAD - self::GAP) / 2;

        // El rótulo se ensancha hasta donde haga falta (con tope): con un ancho fijo,
        // etiquetas del directorio como «TIPO DISPOSITIVO» salían recortadas.
        $this->SetFont('Arial', '', self::F_LABEL);
        $labelW = self::LABEL_W;
        foreach ($pairs as [$label, ]) {
            $labelW = max($labelW, $this->GetStringWidth($this->t(mb_strtoupper((string) $label, 'UTF-8'))) + 2);
        }
        $labelW = min($labelW, $colW * 0.45);

        foreach (array_chunk($pairs, 2) as $row) {
            $this->ensureSpace(self::LINE_H + 1);
            $y = $this->y;

            foreach (array_values($row) as $i => [$label, $value]) {
                $x = self::MARGIN + self::PAD + $i * ($colW + self::GAP);

                $this->SetFont('Arial', '', self::F_LABEL);
                $this->ink(self::MUTED);
                $this->SetXY($x, $y);
                $this->Cell($labelW, self::LINE_H, $this->fit(mb_strtoupper((string) $label, 'UTF-8'), $labelW), 0, 0, 'L');

                $this->SetFont('Arial', '', self::F_VALUE);
                $this->ink(self::INK);
                $this->SetXY($x + $labelW, $y);
                $this->Cell($colW - $labelW, self::LINE_H, $this->fit((string) $value, $colW - $labelW), 0, 0, 'L');
            }

            $this->y = $y + self::LINE_H;
        }
    }

    /** Par etiqueta/valor a todo lo ancho, para valores que no caben en media línea. */
    private function wide(string $label, string $value): void
    {
        $valueW = self::CONTENT_W - 2 * self::PAD - self::LABEL_W;

        foreach ($this->wrapAt($value, $valueW, self::F_VALUE) as $i => $line) {
            $this->ensureSpace(self::LINE_H + 1);

            if ($i === 0) {
                $this->SetFont('Arial', '', self::F_LABEL);
                $this->ink(self::MUTED);
                $this->SetXY(self::MARGIN + self::PAD, $this->y);
                $this->Cell(self::LABEL_W, self::LINE_H, $this->fit(mb_strtoupper($label, 'UTF-8'), self::LABEL_W), 0, 0, 'L');
            }

            $this->SetFont('Arial', '', self::F_VALUE);
            $this->ink(self::INK);
            $this->SetXY(self::MARGIN + self::PAD + self::LABEL_W, $this->y);
            $this->Cell($valueW, self::LINE_H, $line, 0, 0, 'L');

            $this->y += self::LINE_H;
        }
    }

    /** Rótulo arriba y texto corrido debajo: para la descripción y las leyendas. */
    private function block(string $label, string $text, bool $tinted = false): void
    {
        $w     = self::CONTENT_W - 2 * self::PAD;
        $lines = $this->wrapAt($text, $w - ($tinted ? 3 : 0), self::F_VALUE);

        $this->ensureSpace(self::LINE_H * 2 + 2);

        $this->SetFont('Arial', '', self::F_LABEL);
        $this->ink(self::MUTED);
        $this->SetXY(self::MARGIN + self::PAD, $this->y);
        $this->Cell($w, 3.2, $this->fit(mb_strtoupper($label, 'UTF-8'), $w), 0, 0, 'L');
        $this->y += 3.2;

        foreach ($lines as $line) {
            $this->ensureSpace(self::LINE_H + 1);

            if ($tinted) {
                $this->fill(self::TINT);
                $this->Rect(self::MARGIN + self::PAD, $this->y, $w, self::LINE_H, 'F');
            }

            $this->SetFont('Arial', '', self::F_VALUE);
            $this->ink(self::INK);
            $this->SetXY(self::MARGIN + self::PAD + ($tinted ? 1.5 : 0), $this->y);
            $this->Cell($w, self::LINE_H, $line, 0, 0, 'L');

            $this->y += self::LINE_H;
        }

        $this->y += 0.8;
    }

    /** Un campo del formulario, según su tipo. */
    private function field(array $f): void
    {
        $label = (string) ($f['label'] ?? '');
        $kind  = (string) ($f['kind'] ?? 'text');

        if ($kind === 'images') {
            if (! empty($f['images'])) $this->thumbs($label, $f['images']);

            return;
        }

        $value = trim((string) ($f['value'] ?? ''));
        if ($value === '' || $value === '—') return;

        if ($kind === 'legend') { $this->block($label, $value, true); return; }

        // Un valor corto comparte renglón con su rótulo; uno largo se envuelve.
        $short = self::CONTENT_W - 2 * self::PAD - self::LABEL_W;
        $this->SetFont('Arial', '', self::F_VALUE);

        $this->GetStringWidth($this->t($value)) <= $short
            ? $this->pairs([[$label, $value]])
            : $this->wide($label, $value);
    }

    /** Miniaturas en fila. La evidencia se ve, pero sin comerse la hoja. */
    private function thumbs(string $label, array $images): void
    {
        $images = array_values(array_filter($images, fn ($i) => is_array($i) && ! empty($i['file'])));
        if (! $images) return;

        $extra  = max(0, count($images) - self::MAX_THUMBS);
        $images = array_slice($images, 0, self::MAX_THUMBS);

        $w = 21;
        $h = 16;
        $perRow = 8;

        $this->ensureSpace($h + 6);

        $this->SetFont('Arial', '', self::F_LABEL);
        $this->ink(self::MUTED);
        $this->SetXY(self::MARGIN + self::PAD, $this->y);
        $this->Cell(self::CONTENT_W, 3.2, $this->fit(mb_strtoupper($label, 'UTF-8') . ($extra ? " (+{$extra} más)" : '') . '  ·  clic para ver en grande', self::CONTENT_W), 0, 0, 'L');
        $this->y += 3.4;

        foreach (array_chunk($images, $perRow) as $chunk) {
            $this->ensureSpace($h + 2);
            $y = $this->y;

            foreach (array_values($chunk) as $i => $img) {
                $x    = self::MARGIN + self::PAD + $i * ($w + 2);
                $file = $img['file'] ?? null;
                if (! $file) continue;

                try {
                    $this->Image($file, $x, $y, $w, $h, $this->imageType($file));
                    $this->draw(self::LINE);
                    $this->Rect($x, $y, $w, $h);

                    // Zona enlazada sobre la miniatura: en el PDF se ve chica, pero un clic
                    // abre la foto original en el navegador. La miniatura no tiene detalle
                    // suficiente para revisar una evidencia; la original sí.
                    if (! empty($img['url'])) $this->Link($x, $y, $w, $h, $img['url']);
                } catch (\Throwable) {
                    // foto ilegible: se omite, nunca tumba el documento
                }
            }

            $this->y = $y + $h + 1.5;
        }
    }

    /** `wrap()` mide con la fuente activa; aquí se fija primero la del cuerpo. */
    private function wrapAt(string $text, float $w, float $size): array
    {
        $this->SetFont('Arial', '', $size);

        return $this->wrap($text, $w);
    }

    /** @return array{0:int,1:int,2:int}|null */
    private function hex(?string $color): ?array
    {
        if (! $color || ! preg_match('/^#?([0-9a-f]{6})$/i', trim($color), $m)) return null;

        return [hexdec(substr($m[1], 0, 2)), hexdec(substr($m[1], 2, 2)), hexdec(substr($m[1], 4, 2))];
    }
}
