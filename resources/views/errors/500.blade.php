{{--
    Last-resort 500.

    Normally a 500 is re-rendered as Pages/Error.vue by the respond() callback in
    bootstrap/app.php. This file is what is left when even that fails - no session,
    no built asset manifest mid-deploy, a broken Inertia render - so it stays plain
    HTML with inline styles and no container calls.
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>Server Error - Fursuit Badge System</title>
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
        a {
            display: inline-block;
            margin-top: 1.75rem;
            padding: .75rem 1.25rem;
            font-size: 1rem;
            font-weight: 600;
            color: #fff;
            background: #2563eb;
            border-radius: .5rem;
            text-decoration: none;
        }
        a:hover { background: #1d4ed8; }
    </style>
</head>
<body>
    <div class="card">
        <p class="code">500</p>
        <h1>Server Error</h1>
        <p>Something went wrong on our side. The team has been notified.</p>
        <a href="/">Go Home</a>
    </div>
</body>
</html>
