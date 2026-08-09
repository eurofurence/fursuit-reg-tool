{{--
    Maintenance mode.

    Deliberately self-contained: no Vite, no layout, no Inertia. Maintenance mode
    aborts in global middleware before the session starts, and `artisan down --render`
    prerenders this file into storage/framework/maintenance.php, where it is served
    straight from index.php with the framework never booted. Anything that needs the
    container or a built asset manifest would blow up there.

    Bring the site back with: php artisan up
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>Down for Maintenance - Fursuit Badge System</title>
    <style>
        :root { color-scheme: light; }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1.5rem;
            background: #f3f4f6;
            color: #1f2937;
            font-family: ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            line-height: 1.6;
        }
        .card {
            width: 100%;
            max-width: 34rem;
            background: #fff;
            border-radius: 1rem;
            box-shadow: 0 10px 30px rgba(0, 0, 0, .08);
            padding: 2.5rem 2rem;
            text-align: center;
        }
        .code { font-size: 3.5rem; font-weight: 700; margin: 0; color: #111827; }
        h1 { font-size: 1.5rem; margin: .5rem 0 0; color: #374151; }
        p { color: #4b5563; margin: 1rem 0 0; }
        .hint { font-size: .875rem; color: #6b7280; margin-top: 1.75rem; }
        button {
            margin-top: 1.75rem;
            padding: .75rem 1.25rem;
            font-size: 1rem;
            font-weight: 600;
            color: #fff;
            background: #2563eb;
            border: 0;
            border-radius: .5rem;
            cursor: pointer;
        }
        button:hover { background: #1d4ed8; }
    </style>
</head>
<body>
    <div class="card">
        <p class="code">503</p>
        <h1>Down for Maintenance</h1>
        <p>The Fursuit Badge System is being updated right now. It will be back in a few minutes.</p>
        <button type="button" onclick="window.location.reload()">Try Again</button>
        <p class="hint">If you are at the convention and need a badge, please visit the Fursuit Badge desk.</p>
    </div>
</body>
</html>
