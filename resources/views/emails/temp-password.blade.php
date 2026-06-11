<!DOCTYPE html>
<html lang="es" xmlns="http://www.w3.org/1999/xhtml">
<head>
  <meta charset="UTF-8">
  <meta http-equiv="X-UA-Compatible" content="IE=edge">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Tus credenciales de acceso</title>
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

        {{-- Accent bar (verde semántico — bienvenida/éxito) --}}
        <tr>
          <td style="background-image:linear-gradient(90deg,#059669,#10b981,#34d399);height:4px;font-size:0;line-height:0;">&nbsp;</td>
        </tr>

        {{-- HERO --}}
        <tr>
          <td style="background-color:#ffffff;padding:44px 40px 0;">
            <table role="presentation" cellpadding="0" cellspacing="0">
              <tr>
                <td style="background-color:#ecfdf5;border-radius:50%;width:56px;height:56px;text-align:center;vertical-align:middle;">
                  <span style="font-size:26px;line-height:56px;">👋</span>
                </td>
              </tr>
            </table>
            <p style="margin:20px 0 6px;font-size:26px;font-weight:700;color:#0f172a;letter-spacing:-0.5px;line-height:1.2;">¡Bienvenido,<br>{{ $userName }}!</p>
            <p style="margin:0;font-size:15px;color:#64748b;line-height:1.7;">
              Tu cuenta en el sistema ha sido creada. A continuación encontrarás tus credenciales para iniciar sesión.
            </p>
          </td>
        </tr>

        {{-- CREDENTIALS CARD --}}
        <tr>
          <td style="background-color:#ffffff;padding:28px 40px 8px;">
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0"
                   style="border-radius:14px;overflow:hidden;border:1px solid #e2e8f0;">
              <tr>
                <td style="background-color:#f8fafc;border-bottom:1px solid #e2e8f0;padding:14px 20px;">
                  <p style="margin:0;font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.08em;">🔑 &nbsp;Tus credenciales de acceso</p>
                </td>
              </tr>
              {{-- Email row --}}
              <tr>
                <td style="padding:16px 20px 0;">
                  <table role="presentation" cellpadding="0" cellspacing="0">
                    <tr>
                      <td style="width:130px;vertical-align:top;">
                        <p style="margin:0;font-size:12px;color:#94a3b8;font-weight:600;text-transform:uppercase;letter-spacing:.05em;padding-top:2px;">Correo</p>
                      </td>
                      <td>
                        <p style="margin:0;font-size:14px;font-weight:600;color:#0f172a;">{{ $userEmail }}</p>
                      </td>
                    </tr>
                  </table>
                </td>
              </tr>
              <tr><td style="padding:12px 20px;"><hr style="border:none;border-top:1px dashed #e2e8f0;margin:0;"></td></tr>
              {{-- Password row --}}
              <tr>
                <td style="padding:0 20px 20px;">
                  <table role="presentation" cellpadding="0" cellspacing="0">
                    <tr>
                      <td style="width:130px;vertical-align:middle;">
                        <p style="margin:0;font-size:12px;color:#94a3b8;font-weight:600;text-transform:uppercase;letter-spacing:.05em;line-height:1.4;">Contraseña<br>temporal</p>
                      </td>
                      <td>
                        <table role="presentation" cellpadding="0" cellspacing="0">
                          <tr>
                            <td style="background-color:#eff6ff;border:2px solid {{ $ec['primary'] }};border-radius:10px;padding:10px 20px;">
                              <p style="margin:0;font-family:'Courier New',Courier,monospace;font-size:22px;font-weight:700;color:{{ $ec['primary'] }};letter-spacing:4px;">{{ $tempPassword }}</p>
                            </td>
                          </tr>
                        </table>
                      </td>
                    </tr>
                  </table>
                </td>
              </tr>
            </table>
          </td>
        </tr>

        {{-- CTA BUTTON --}}
        <tr>
          <td style="background-color:#ffffff;padding:24px 40px 32px;">
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
              <tr>
                <td align="center">
                  <a href="{{ $loginUrl }}"
                     style="display:inline-block;background-color:{{ $ec['primary'] }};background-image:linear-gradient(135deg,{{ $ec['primary'] }},{{ $ec['primary_dark'] }});
                            color:#ffffff;text-decoration:none;font-size:15px;font-weight:700;
                            letter-spacing:0.2px;padding:16px 40px;border-radius:12px;
                            box-shadow:0 4px 14px {{ $ec['btn_shadow'] }};">
                    Iniciar sesión &rarr;
                  </a>
                </td>
              </tr>
            </table>
          </td>
        </tr>

        {{-- SECURITY NOTE --}}
        <tr>
          <td style="background-color:#f0fdf4;padding:20px 40px;border-top:1px solid #bbf7d0;">
            <table role="presentation" cellpadding="0" cellspacing="0">
              <tr>
                <td style="width:20px;vertical-align:top;padding-top:1px;"><span style="font-size:15px;">🔐</span></td>
                <td style="padding-left:10px;">
                  <p style="margin:0;font-size:13px;color:#065f46;line-height:1.6;">
                    Por seguridad, el sistema te pedirá <strong>cambiar esta contraseña</strong> al primer inicio de sesión. Guarda estas credenciales en un lugar seguro.
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
