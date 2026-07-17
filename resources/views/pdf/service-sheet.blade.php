<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<style>
  * { box-sizing: border-box; }
  body { font-family: DejaVu Sans, sans-serif; color: #1f2430; font-size: 11px; margin: 0; }
  .sheet { padding: 4px 2px; }
  .header { border-bottom: 2px solid #1f2937; padding-bottom: 8px; }
  .header table { width: 100%; }
  .brand { font-size: 15px; font-weight: bold; }
  .brand-sub { font-size: 9px; color: #6b7280; }
  .folio { font-family: DejaVu Sans Mono, monospace; font-size: 15px; font-weight: bold; text-align: right; }
  .status { font-size: 11px; font-weight: bold; text-align: right; }
  .gen { font-size: 9px; color: #6b7280; text-align: right; }
  h2 { font-size: 11px; text-transform: uppercase; letter-spacing: .04em; border-bottom: 1px solid #d1d5db;
       padding-bottom: 3px; margin: 14px 0 8px; }
  .grid td { vertical-align: top; padding: 0 10px 8px 0; width: 33%; }
  .lbl { font-size: 8px; text-transform: uppercase; letter-spacing: .04em; color: #6b7280; }
  .val { font-size: 11px; font-weight: bold; margin-top: 1px; }
  .desc { font-size: 11px; white-space: pre-wrap; margin-top: 2px; }
  .thumb { height: 54px; margin: 2px 3px 0 0; border: 1px solid #d1d5db; }
  table.hist { width: 100%; border-collapse: collapse; }
  table.hist td { padding: 3px 4px; border-bottom: 1px solid #eceef1; vertical-align: top; }
  .note { color: #4b5563; font-size: 10px; }
  .cmt { margin-bottom: 6px; }
  .cmt .who { font-size: 9px; color: #6b7280; }
  .cmt .who b { color: #111827; }
  .cmt .body { white-space: pre-wrap; }
  .sign td { width: 50%; text-align: center; padding: 16px 12px 0; vertical-align: bottom; }
  .sign .line { border-bottom: 1px solid #111827; height: 70px; text-align: center; }
  .sign .line img { max-height: 68px; }
  .sign .cap { font-size: 9px; color: #4b5563; margin-top: 4px; }
  .muted { color: #6b7280; }
</style>
</head>
<body>
<div class="sheet">

  <div class="header">
    <table>
      <tr>
        <td style="width:65%; vertical-align: middle;">
          @if($logo)<img src="{{ $logo }}" style="height:42px; vertical-align: middle;">@endif
          <span class="brand" style="vertical-align: middle;">{{ $appName }}</span>
          <div class="brand-sub">Hoja de servicio</div>
        </td>
        <td style="width:35%; vertical-align: top;">
          <div class="folio">{{ $folio }}</div>
          @if($status['label'])<div class="status" style="color: {{ $status['color'] ?: '#111827' }}">{{ $status['label'] }}</div>@endif
          <div class="gen">Generada: {{ $generatedAt }}</div>
        </td>
      </tr>
    </table>
  </div>

  <h2>Datos del evento</h2>
  <table class="grid">
    <tr>
      <td><div class="lbl">Cliente</div><div class="val">{{ $general['cliente'] ?? '—' }}</div></td>
      <td><div class="lbl">Sitio</div><div class="val">{{ $general['sitio'] ?? '—' }}</div></td>
      <td><div class="lbl">Sistema</div><div class="val">{{ $general['sistema'] ?? '—' }}</div></td>
    </tr>
    <tr>
      <td><div class="lbl">Tipo de evento</div><div class="val">{{ $general['tipo'] ?? '—' }}@if($general['naturaleza'])<span class="muted"> · {{ ucfirst($general['naturaleza']) }}</span>@endif</div></td>
      <td><div class="lbl">Prioridad</div><div class="val">{{ $general['prioridad'] ?? '—' }}</div></td>
      <td><div class="lbl">Estado</div><div class="val">{{ $general['estado'] ?? '—' }}</div></td>
    </tr>
    <tr>
      @if($general['impacto'])<td><div class="lbl">Impacto</div><div class="val">{{ $general['impacto'] }}</div></td>@endif
      @if($general['urgencia'])<td><div class="lbl">Urgencia</div><div class="val">{{ $general['urgencia'] }}</div></td>@endif
      <td><div class="lbl">Fecha de ocurrencia</div><div class="val">{{ $general['ocurrencia'] ?? '—' }}</div></td>
    </tr>
    <tr>
      <td><div class="lbl">Creado por</div><div class="val">{{ $general['creado_por'] ?? '—' }}</div></td>
      <td><div class="lbl">Fecha de creación</div><div class="val">{{ $general['creado'] ?? '—' }}</div></td>
      <td></td>
    </tr>
  </table>
  <div class="lbl">Descripción</div>
  <div class="desc">{{ $general['descripcion'] ?: '—' }}</div>

  @if($device)
    <h2>Dispositivo</h2>
    <table class="grid">
      <tr>
        <td><div class="lbl">DID</div><div class="val">{{ $device['did'] ?? '—' }}</div></td>
        <td><div class="lbl">Nombre</div><div class="val">{{ $device['nombre'] }}</div></td>
        @if($device['tipo'])<td><div class="lbl">Tipo de dispositivo</div><div class="val">{{ $device['tipo'] }}</div></td>@endif
      </tr>
      @if($device['ubicacion'])
      <tr><td><div class="lbl">Ubicación</div><div class="val">{{ $device['ubicacion'] }}</div></td><td></td><td></td></tr>
      @endif
    </table>
    @if(count($dirEntries))
      <div class="lbl" style="margin-top:6px;">Datos del directorio</div>
      <table class="grid">
        @foreach(array_chunk($dirEntries, 3) as $row)
          <tr>
            @foreach($row as $e)
              <td>
                <div class="lbl">{{ $e['label'] }}</div>
                @if(is_array($e['value']))
                  <div>@foreach($e['value']['images'] ?? [] as $img)@if($img)<img class="thumb" src="{{ $img }}">@endif @endforeach</div>
                @else
                  <div class="val">{{ $e['value'] }}</div>
                @endif
              </td>
            @endforeach
          </tr>
        @endforeach
      </table>
    @endif
  @endif

  @if(count($formRows))
    <h2>Formulario del evento</h2>
    <table class="grid">
      @foreach(array_chunk($formRows, 3) as $row)
        <tr>
          @foreach($row as $e)
            <td>
              <div class="lbl">{{ $e['label'] }}</div>
              @if(is_array($e['value']))
                <div>@foreach($e['value']['images'] ?? [] as $img)@if($img)<img class="thumb" src="{{ $img }}">@endif @endforeach</div>
              @else
                <div class="val">{{ $e['value'] }}</div>
              @endif
            </td>
          @endforeach
        </tr>
      @endforeach
    </table>
  @endif

  <h2>Historial de estados</h2>
  @if(count($history))
    <table class="hist">
      @foreach($history as $h)
        <tr>
          <td>
            <b>@if($h['from']){{ $h['from'] }} → @else Estado inicial: @endif{{ $h['to'] }}</b>
            <span class="muted"> · {{ $h['date'] }} · {{ $h['user'] }}</span>
            @if($h['note'])<div class="note">{{ $h['note'] }}</div>@endif
          </td>
        </tr>
      @endforeach
    </table>
  @else
    <div class="muted">Sin movimientos.</div>
  @endif

  @if(count($comments))
    <h2>Comentarios</h2>
    @foreach($comments as $c)
      <div class="cmt">
        <div class="who"><b>{{ $c['user'] }}</b> · {{ $c['date'] }}</div>
        <div class="body">{{ $c['body'] }}</div>
      </div>
    @endforeach
  @endif

  @if(count($signatures))
    <h2>Firmas</h2>
    <table class="sign">
      @foreach(array_chunk($signatures, 2) as $row)
        <tr>
          @foreach($row as $s)
            <td>
              <div class="line">@if($s['image'])<img src="{{ $s['image'] }}">@endif</div>
              <div class="cap">{{ $s['label'] }}</div>
            </td>
          @endforeach
          @if(count($row) === 1)<td></td>@endif
        </tr>
      @endforeach
    </table>
  @endif

</div>
</body>
</html>
