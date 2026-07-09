<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'Sistema de Mantenimientos')</title>
    <style>
        :root { color-scheme: dark; }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        html, body { height: 100%; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            background: radial-gradient(1200px 600px at 50% -10%, #23305e 0%, #1A1F3A 45%, #0d1024 100%);
            color: #e8ebf5;
            display: flex; align-items: center; justify-content: center;
            padding: 24px; text-align: center;
        }
        .card {
            width: 100%; max-width: 460px;
            background: rgba(255,255,255,.03);
            border: 1px solid rgba(255,255,255,.08);
            border-radius: 20px;
            padding: 40px 32px;
            box-shadow: 0 20px 60px rgba(0,0,0,.35);
            backdrop-filter: blur(6px);
        }
        .brand {
            display: inline-flex; align-items: center; gap: 10px;
            font-weight: 700; letter-spacing: .2px; font-size: 15px;
            color: #c9d2f5;
        }
        .brand .dot {
            width: 30px; height: 30px; border-radius: 9px;
            background: linear-gradient(135deg, #4F90FF, #3B5BDB);
            display: inline-flex; align-items: center; justify-content: center;
            font-size: 15px; color: #fff; font-weight: 800;
        }
        .code {
            margin: 26px 0 6px;
            font-size: 64px; font-weight: 800; line-height: 1;
            background: linear-gradient(135deg, #4F90FF, #7aa8ff);
            -webkit-background-clip: text; background-clip: text; -webkit-text-fill-color: transparent;
        }
        h1 { font-size: 20px; font-weight: 700; margin-top: 8px; }
        p { color: #9aa3c7; font-size: 14px; line-height: 1.6; margin-top: 12px; }
        .status {
            display: inline-flex; align-items: center; gap: 8px;
            margin-top: 22px; padding: 8px 14px; border-radius: 999px;
            background: rgba(79,144,255,.12); border: 1px solid rgba(79,144,255,.25);
            color: #bcd2ff; font-size: 13px; font-weight: 600;
        }
        .status .pulse {
            width: 8px; height: 8px; border-radius: 50%; background: #34d399;
            box-shadow: 0 0 0 0 rgba(52,211,153,.6); animation: pulse 2s infinite;
        }
        @keyframes pulse {
            0% { box-shadow: 0 0 0 0 rgba(52,211,153,.5); }
            70% { box-shadow: 0 0 0 8px rgba(52,211,153,0); }
            100% { box-shadow: 0 0 0 0 rgba(52,211,153,0); }
        }
        .foot { margin-top: 28px; color: #5c6690; font-size: 12px; }
    </style>
</head>
<body>
    <main class="card">
        <div class="brand"><span class="dot">M</span> Mantenimientos</div>
        @hasSection('code')
            <div class="code">@yield('code')</div>
        @endif
        <h1>@yield('heading')</h1>
        <p>@yield('message')</p>
        @yield('extra')
        <div class="foot">API del Sistema de Mantenimientos</div>
    </main>
</body>
</html>
