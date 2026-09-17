<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Aviso legal | Savid</title>
    <link rel="icon" type="image/png" sizes="32x32" href="/favicon-32.png">
    <link rel="apple-touch-icon" href="/apple-touch-icon.png">
    <style>
        :root{
            --bg:#0b0d0c; --card:#111; --border:#2a2a2a; --text:#e5e5e5;
            --muted:#a3a3a3; --accent:#1dd1a1;
        }
        *{ box-sizing:border-box; }
        body{
            margin:0; background:var(--bg); color:var(--text);
            font-family:'Segoe UI',sans-serif; line-height:1.6;
        }
        .wrap{ max-width:820px; margin:0 auto; padding:40px 24px 80px; }
        .volver{ display:inline-block; margin-bottom:24px; color:var(--accent); text-decoration:none; font-size:14px; }
        .volver:hover{ text-decoration:underline; }
        h1{ font-size:26px; margin:0 0 4px; }
        .fecha{ color:var(--muted); font-size:13px; margin-bottom:32px; }
        h2{ font-size:18px; color:var(--accent); margin:36px 0 10px; border-bottom:1px solid var(--border); padding-bottom:6px; }
        h3{ font-size:15px; margin:20px 0 6px; }
        p, li{ font-size:14.5px; color:var(--text); }
        ul, ol{ padding-left:22px; }
        li{ margin-bottom:6px; }
        table{ width:100%; border-collapse:collapse; margin:12px 0; font-size:14px; }
        th, td{ border:1px solid var(--border); padding:8px 10px; text-align:left; vertical-align:top; }
        th{ background:var(--card); color:var(--accent); }
        .placeholder{ color:#f0ad4e; font-weight:600; }
        .aviso{
            background:var(--card); border:1px solid var(--border); border-left:3px solid var(--accent);
            padding:14px 16px; border-radius:6px; font-size:13.5px; color:var(--muted); margin:20px 0;
        }
        .contacto{
            background:var(--card); border:1px solid var(--border); border-radius:8px; padding:18px 20px; margin-top:16px;
        }
        a{ color:var(--accent); }
    </style>
</head>
<body>
<div class="wrap">
    <a href="?url=login" class="volver">&larr; Volver al inicio de sesión</a>

    <h1>Aviso legal</h1>
    <?php
    // setlocale() depende de que el SO tenga el locale es_ES instalado (no
    // garantizado); se mapea a mano para no depender de eso.
    $meses = [1=>'enero',2=>'febrero',3=>'marzo',4=>'abril',5=>'mayo',6=>'junio',
        7=>'julio',8=>'agosto',9=>'septiembre',10=>'octubre',11=>'noviembre',12=>'diciembre'];
    $fechaActualizacion = (int)date('d') . ' de ' . $meses[(int)date('n')] . ' de ' . date('Y');
    ?>
    <p class="fecha">Última actualización: <?= $fechaActualizacion ?> · Aplica al sistema SAVID</p>

    <div class="aviso">
        Este documento identifica al prestador del servicio y las condiciones generales de uso de
        SAVID. <strong>No sustituye asesoría jurídica</strong>: antes de publicarlo formalmente,
        complete los datos marcados como <span class="placeholder">[pendiente]</span> y valide el
        texto con un abogado, en particular las secciones de responsabilidad y jurisdicción.
    </div>

    <h2>1. Identificación del prestador del servicio</h2>
    <table>
        <tr><th>Razón social</th><td class="placeholder">[RAZÓN SOCIAL — pendiente]</td></tr>
        <tr><th>NIT</th><td class="placeholder">[NIT — pendiente]</td></tr>
        <tr><th>Domicilio</th><td class="placeholder">[DIRECCIÓN — pendiente]</td></tr>
        <tr><th>Correo de contacto</th><td class="placeholder">[CORREO DE CONTACTO — pendiente]</td></tr>
    </table>

    <h2>2. Objeto</h2>
    <p>Este aviso legal regula el acceso y uso de SAVID, un sistema de gestión ofrecido a empresas
    clientes bajo un contrato de prestación de servicios independiente. No trata el manejo de
    datos personales — eso está en la <a href="?url=privacidad">Política de privacidad</a>.</p>

    <h2>3. Condiciones de uso</h2>
    <ul>
        <li>El acceso a SAVID está restringido a usuarios autorizados por la empresa cliente
            contratante, mediante credenciales personales e intransferibles.</li>
        <li>Queda prohibido: compartir credenciales de acceso, intentar vulnerar la seguridad del
            sistema, usar la plataforma para fines distintos a los autorizados por la empresa
            cliente, o extraer datos de terceros sin autorización.</li>
        <li>La empresa cliente es responsable del uso que sus usuarios autorizados hagan del
            sistema.</li>
    </ul>

    <h2>4. Propiedad intelectual</h2>
    <p>El software SAVID, su código, diseño, marca y logo son propiedad de
    <span class="placeholder">[RAZÓN SOCIAL — pendiente]</span> (o de sus licenciantes). El acceso
    al sistema no otorga ningún derecho de propiedad intelectual sobre él; queda prohibida su
    reproducción, distribución o ingeniería inversa sin autorización expresa.</p>
    <p>Los datos que cada empresa cliente registra en el sistema (terceros, información
    operativa) siguen siendo de su propiedad — SAVID actúa como encargado técnico, no como dueño
    de esos datos (ver <a href="?url=privacidad">Política de privacidad</a>, sección de
    terceros).</p>

    <h2>5. Limitación de responsabilidad</h2>
    <ul>
        <li>SAVID se presta "tal cual" (as-is); se hacen esfuerzos razonables por mantener el
            servicio disponible, pero no se garantiza disponibilidad ininterrumpida ni ausencia
            total de errores.</li>
        <li><span class="placeholder">[RAZÓN SOCIAL — pendiente]</span> no es responsable por
            decisiones que la empresa cliente tome con base en los datos o reportes generados por
            el sistema, ni por el uso indebido que un usuario autorizado haga de sus propias
            credenciales.</li>
        <li>Para los reportes que consultan sistemas externos (por ejemplo SIHOS), SAVID no
            controla ni garantiza la exactitud de los datos de origen — solo los presenta o cruza
            (ver <a href="?url=privacidad">Política de privacidad</a>).</li>
    </ul>

    <h2>6. Legislación aplicable y jurisdicción</h2>
    <p>Este aviso se rige por las leyes de la República de Colombia. Cualquier controversia
    derivada del uso de SAVID se someterá a los jueces y tribunales competentes de
    <span class="placeholder">[CIUDAD — pendiente]</span>, Colombia.</p>

    <h2>7. Modificaciones</h2>
    <p>Este aviso legal puede actualizarse. Los cambios se reflejarán en la fecha de "Última
    actualización" al inicio del documento.</p>

    <h2>8. Contacto</h2>
    <div class="contacto">
        <p style="margin:0;">Para consultas sobre este aviso legal:</p>
        <p style="margin:6px 0 0;" class="placeholder">[CORREO DE CONTACTO — pendiente]</p>
    </div>

    <p style="margin-top:40px;">
        <a href="?url=login" class="volver">&larr; Volver al inicio de sesión</a>
        &nbsp;·&nbsp;
        <a href="?url=privacidad" class="volver">Política de privacidad</a>
    </p>
</div>
</body>
</html>
