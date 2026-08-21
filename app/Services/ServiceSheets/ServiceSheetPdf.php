<?php

namespace App\Services\ServiceSheets;

use App\Services\Pdf\Pdf;

/**
 * Hoja de servicio de un evento, dibujada con FPDF sobre la base común.
 *
 * Consume el arreglo que arma ServiceSheetRenderer (los mismos datos que se veían en
 * la página imprimible del front), de modo que la descarga individual y el ZIP por
 * sitio salen de UNA sola maqueta y no pueden divergir.
 */
class ServiceSheetPdf extends Pdf
{
    public function render(): string
    {
        $this->AddPage();

        $g = $this->data['general'] ?? [];

        $this->sectionTitle('Datos del evento');
        $this->kvGrid(array_values(array_filter([
            ['label' => 'Folio',        'value' => $this->data['folio'] ?? '—'],
            ['label' => 'Estado',       'value' => $this->data['status']['label'] ?? '—'],
            ['label' => 'Tipo',         'value' => $g['tipo'] ?? '—'],
            ['label' => 'Naturaleza',   'value' => ucfirst((string) ($g['naturaleza'] ?? '')) ?: '—'],
            ['label' => 'Prioridad',    'value' => $g['prioridad'] ?? '—'],
            ['label' => 'Sistema',      'value' => $g['sistema'] ?? '—'],
            $g['impacto']  ? ['label' => 'Impacto',  'value' => $g['impacto']] : null,
            $g['urgencia'] ? ['label' => 'Urgencia', 'value' => $g['urgencia']] : null,
            ['label' => 'Ocurrencia',   'value' => $g['ocurrencia'] ?? '—'],
            ['label' => 'Registrado',   'value' => $g['creado'] ?? '—'],
            ['label' => 'Registró',     'value' => $g['creado_por'] ?? '—'],
        ])));

        if (trim((string) ($g['descripcion'] ?? '')) !== '') {
            $this->sectionTitle('Descripción');
            $this->paragraph($g['descripcion']);
        }

        if ($device = $this->data['device'] ?? null) {
            $this->sectionTitle('Dispositivo');
            $this->kvGrid([
                ['label' => 'DID',       'value' => $device['did'] ?? '—'],
                ['label' => 'Nombre',    'value' => $device['nombre'] ?? '—'],
                ['label' => 'Tipo',      'value' => $device['tipo'] ?? '—'],
                ['label' => 'Ubicación', 'value' => $device['ubicacion'] ?? '—'],
            ]);

            if ($dir = $this->data['dirEntries'] ?? []) {
                $this->kvGrid(array_map(
                    fn ($e) => ['label' => $e['label'], 'value' => $this->flat($e['value'])],
                    $dir,
                ));
            }
        }

        if ($form = $this->data['formRows'] ?? []) {
            $this->sectionTitle('Documentación del servicio');
            $this->kvGrid(array_map(
                fn ($r) => ['label' => $r['label'], 'value' => $this->flat($r['value'])],
                $form,
            ), 2);
        }

        if ($photos = $this->data['photos'] ?? []) {
            $this->sectionTitle('Evidencia fotográfica');
            $this->imageGrid($photos, 4, 38);
        }

        if ($history = $this->data['history'] ?? []) {
            $this->sectionTitle('Historial de estados');
            $this->table(
                [
                    ['label' => 'Fecha',  'w' => 28],
                    ['label' => 'De',     'w' => 30],
                    ['label' => 'A',      'w' => 30],
                    ['label' => 'Quién',  'w' => 40],
                    ['label' => 'Nota',   'w' => 62],
                ],
                array_map(fn ($h) => [
                    $h['date'] ?? '', $h['from'] ?? '—', $h['to'] ?? '', $h['user'] ?? '', $h['note'] ?? '',
                ], $history),
            );
        }

        if ($comments = $this->data['comments'] ?? []) {
            $this->sectionTitle('Comentarios');
            $this->table(
                [
                    ['label' => 'Fecha', 'w' => 28],
                    ['label' => 'Quién', 'w' => 42],
                    ['label' => 'Comentario', 'w' => 120],
                ],
                array_map(fn ($c) => [$c['date'] ?? '', $c['user'] ?? '', $c['body'] ?? ''], $comments),
            );
        }

        // Firmas CAPTURADAS en el servicio (campos tipo firma), distintas del cierre
        // de conformidad opcional que agrega la base al final.
        $signed = array_values(array_filter($this->data['signatures'] ?? [], fn ($s) => ! empty($s['image'])));
        if ($signed) {
            $this->sectionTitle('Firmas');
            $this->imageGrid(array_column($signed, 'image'), min(3, count($signed)), 30);

            $this->SetFont('Arial', '', 7);
            $this->ink(self::MUTED);
            $w = self::CONTENT_W / min(3, count($signed));
            $y = $this->y;
            foreach ($signed as $i => $s) {
                if ($i >= 3) break;
                $this->SetXY(self::MARGIN + $i * $w, $y);
                $this->Cell($w, 4, $this->fit((string) $s['label'], $w - 2), 0, 0, 'C');
            }
            $this->y = $y + 6;
        }

        if ($this->signature === 'end') $this->signatureBlock();

        return $this->Output('S');
    }

    /** Los campos multivaluados llegan como arreglo; en la hoja van en una línea. */
    private function flat($value): string
    {
        if (is_array($value)) {
            $value = implode(', ', array_filter($value, fn ($v) => is_scalar($v)));
        }

        return trim((string) $value) ?: '—';
    }
}
