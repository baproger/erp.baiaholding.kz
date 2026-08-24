<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>500 — Что-то пошло не так · BAIA ERP</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Figtree', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            min-height: 100vh; display: flex; align-items: center; justify-content: center;
            background: linear-gradient(160deg, #f8fafc 0%, #eef2ff 100%); color: #0f172a; padding: 24px;
        }
        .card {
            max-width: 480px; width: 100%; background: #fff; border: 1px solid #e2e8f0;
            border-radius: 16px; box-shadow: 0 1px 3px rgba(0,0,0,.06); padding: 48px 40px; text-align: center;
        }
        .icon {
            width: 64px; height: 64px; margin: 0 auto 24px; border-radius: 16px;
            background: #fef2f2; color: #e11d48; display: flex; align-items: center; justify-content: center;
        }
        .code { font-size: 13px; font-weight: 600; letter-spacing: .08em; color: #94a3b8; text-transform: uppercase; }
        h1 { font-size: 24px; font-weight: 700; margin: 8px 0 12px; }
        p { font-size: 15px; line-height: 1.6; color: #64748b; }
        .msg { margin-top: 8px; font-size: 14px; color: #e11d48; background: #fff1f2; border-radius: 10px; padding: 10px 14px; }
        .actions { margin-top: 32px; display: flex; gap: 12px; justify-content: center; flex-wrap: wrap; }
        a.btn {
            display: inline-flex; align-items: center; gap: 8px; border-radius: 12px; padding: 10px 20px;
            font-size: 14px; font-weight: 600; text-decoration: none; transition: all .15s ease;
        }
        a.primary { background: #4f46e5; color: #fff; }
        a.primary:hover { background: #4338ca; }
        a.secondary { background: #f1f5f9; color: #334155; }
        a.secondary:hover { background: #e2e8f0; }
    </style>
</head>
<body>
    <div class="card">
        <div class="icon">
            <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                <path d="M12 9v4"/><path d="M12 17h.01"/><path d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0Z"/>
            </svg>
        </div>
        <div class="code">Ошибка 500</div>
        <h1>Что-то пошло не так</h1>
        <p>Ошибка уже записана в журнал — мы её увидим и исправим.<br>Попробуйте обновить страницу или вернитесь на главную.</p>
        <div class="actions">
            <a class="btn secondary" href="javascript:location.reload()">↻ Обновить</a>
            <a class="btn primary" href="{{ url('/') }}">На главную</a>
        </div>
    </div>
</body>
</html>
