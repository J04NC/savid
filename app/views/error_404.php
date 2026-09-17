<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Página no encontrada | Savid</title>
    <link rel="icon" type="image/png" sizes="32x32" href="/favicon-32.png">
    <link rel="apple-touch-icon" href="/apple-touch-icon.png">
    <style>
        :root{
            --bg:#0b0d0c; --card:#111; --border:#2a2a2a; --text:#e5e5e5;
            --muted:#a3a3a3; --accent:#1dd1a1;
        }
        *{ box-sizing:border-box; }
        body{
            margin:0; min-height:100vh; display:flex; align-items:center; justify-content:center;
            background:var(--bg); color:var(--text); font-family:'Segoe UI',sans-serif;
            padding:24px;
        }
        .card{
            max-width:420px; width:100%; text-align:center;
            background:var(--card); border:1px solid var(--border); border-radius:12px;
            padding:40px 32px;
        }
        .codigo{ font-size:64px; font-weight:700; color:var(--accent); line-height:1; margin:0 0 12px; }
        h1{ font-size:19px; margin:0 0 10px; }
        p{ font-size:14px; color:var(--muted); margin:0 0 24px; }
        .volver{
            display:inline-block; padding:10px 22px; border-radius:8px;
            background:var(--accent); color:#0b0d0c; font-weight:600; text-decoration:none; font-size:14px;
        }
        .volver:hover{ opacity:.9; }
    </style>
</head>
<body>
<div class="card">
    <div class="codigo">404</div>
    <h1>Página no encontrada</h1>
    <p>La dirección a la que intentaste acceder no existe o ya no está disponible.</p>
    <a href="<?= $error404VolverUrl ?>" class="volver">&larr; <?= htmlspecialchars($error404VolverTexto, ENT_QUOTES, 'UTF-8') ?></a>
</div>
</body>
</html>
