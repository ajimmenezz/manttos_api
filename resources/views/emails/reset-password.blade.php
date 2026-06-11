<!DOCTYPE html>
<html lang="es" xmlns="http://www.w3.org/1999/xhtml">
<head>
  <meta charset="UTF-8">
  <meta http-equiv="X-UA-Compatible" content="IE=edge">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Recuperación de contraseña</title>
</head>
<body style="margin:0;padding:0;background-color:#eef2f7;-webkit-font-smoothing:antialiased;font-family:-apple-system,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;">

  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#eef2f7;padding:40px 16px;">
    <tr><td align="center">
      <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:600px;border-radius:20px;overflow:hidden;box-shadow:0 4px 24px rgba(0,0,0,.10);">

        {{-- HEADER --}}
        <tr>
          <td style="background-color:{{ $ec['header_from'] }};background-image:linear-gradient(135deg,{{ $ec['header_from'] }} 0%,{{ $ec['header_to'] }} 100%);padding:36px 40px 28px;">
            <table role="presentation" cellpadding="0" cellspacing="0">
              <tr>
                @if($logoUrl)
                <td style="vertical-align:middle;">
                  <img src="{{ $logoUrl }}" alt="{{ $appName }}" width="40" height="40"
                       style="display:block;width:40px;height:40px;object-fit:contain;border-radius:10px;background:rgba(255,255,255,.12);">
                </td>
                <td style="padding-left:14px;vertical-align:middle;">
                @else
                <td style="vertical-align:middle;">
                @endif
                  <p style="margin:0;color:#ffffff;font-size:18px;font-weight:700;letter-spacing:-0.3px;">{{ $appName }}</p>
                  <p style="margin:2px 0 0;color:rgba(255,255,255,.5);font-size:12px;">Plataforma de gestión</p>
                </td>
              </tr>
            </table>
          </td>
        </tr>

        {{-- Accent bar --}}
        <tr>
          <td style="background-image:linear-gradient(90deg,{{ $ec['accent_from'] }},{{ $ec['accent_to'] }},{{ $ec['accent_from'] }});height:4px;font-size:0;line-height:0;">&nbsp;</td>
        </tr>

        {{-- HERO --}}
        <tr>
          <td style="background-color:#ffffff;padding:44px 40px 0;">
            <table role="presentation" cellpadding="0" cellspacing="0">
              <tr>
                <td style="background-color:#eff6ff;border-radius:50%;width:56px;height:56px;text-align:center;vertical-align:middle;">
                  <span style="font-size:26px;line-height:56px;">🔒</span>
                </td>
              </tr>
            </table>
            <p style="margin:20px 0 6px;font-size:26px;font-weight:700;color:#0f172a;letter-spacing:-0.5px;line-height:1.2;">Recupera tu<br>contraseña</p>
            <p style="margin:0;font-size:15px;color:#64748b;line-height:1.7;">
              Hola, <strong style="color:#0f172a;">{{ $userName }}</strong>. Recibimos una solicitud para restablecer
              la contraseña de tu cuenta. Si fuiste tú, haz clic en el botón de abajo.
            </p>
          </td>
        </tr>

        {{-- CTA BUTTON --}}
        <tr>
          <td style="background-color:#ffffff;padding:32px 40px;">
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
              <tr>
                <td align="center">
                  <a href="{{ $resetUrl }}"
                     style="display:inline-block;background-color:{{ $ec['primary'] }};background-image:linear-gradient(135deg,{{ $ec['primary'] }},{{ $ec['primary_dark'] }});
                            color:#ffffff;text-decoration:none;font-size:15px;font-weight:700;
                            letter-spacing:0.2px;padding:16px 40px;border-radius:12px;
                            box-shadow:0 4px 14px {{ $ec['btn_shadow'] }};">
                    Restablecer contraseña &rarr;
                  </a>
                </td>
              </tr>
            </table>
          </td>
        </tr>

        {{-- FALLBACK URL --}}
        <tr>
          <td style="background-color:#ffffff;padding:0 40px 32px;">
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
              <tr>
                <td style="border-top:1px solid #e2e8f0;padding-top:24px;">
                  <p style="margin:0 0 8px;font-size:13px;font-weight:600;color:#94a3b8;text-transform:uppercase;letter-spacing:.06em;">¿El botón no funciona?</p>
                  <p style="margin:0 0 8px;font-size:13px;color:#64748b;line-height:1.6;">Copia y pega este enlace en tu navegador:</p>
                  <p style="margin:0;background-color:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:10px 14px;font-size:12px;word-break:break-all;">
                    <a href="{{ $resetUrl }}" style="color:{{ $ec['primary'] }};text-decoration:none;">{{ $resetUrl }}</a>
                  </p>
                </td>
              </tr>
            </table>
          </td>
        </tr>

        {{-- SECURITY NOTE --}}
        <tr>
          <td style="background-color:#fffbeb;padding:20px 40px;border-top:1px solid #fde68a;">
            <table role="presentation" cellpadding="0" cellspacing="0">
              <tr>
                <td style="width:20px;vertical-align:top;padding-top:1px;"><span style="font-size:15px;">⚠️</span></td>
                <td style="padding-left:10px;">
                  <p style="margin:0;font-size:13px;color:#92400e;line-height:1.6;">
                    Este enlace expira en <strong>{{ $expiresMinutes }} minutos</strong>.
                    Si no solicitaste este cambio, ignora este correo — tu contraseña no cambiará.
                  </p>
                </td>
              </tr>
            </table>
          </td>
        </tr>

        {{-- FOOTER --}}
        <tr>
          <td style="background-color:#f8fafc;border-top:1px solid #e2e8f0;padding:24px 40px;text-align:center;">
            <p style="margin:0 0 6px;font-size:13px;font-weight:600;color:#0f172a;">Sistema de Gestión de Mantenimientos</p>
            <p style="margin:0;font-size:12px;color:#94a3b8;">Correo generado automáticamente · No respondas a este mensaje</p>
            <p style="margin:10px 0 0;font-size:11px;color:#cbd5e1;">&copy; {{ date('Y') }} · Todos los derechos reservados</p>
          </td>
        </tr>

      </table>
    </td></tr>
  </table>
</body>
</html>
