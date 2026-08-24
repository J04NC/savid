<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Política de privacidad | Savid</title>
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
        code{ background:#1a1a1a; padding:1px 5px; border-radius:4px; font-size:13px; }
    </style>
</head>
<body>
<div class="wrap">
    <a href="?url=login" class="volver">&larr; Volver al inicio de sesión</a>

    <h1>Política de privacidad y tratamiento de datos personales</h1>
    <?php
    // setlocale() depende de que el SO tenga el locale es_ES instalado (no
    // garantizado); se mapea a mano para no depender de eso.
    $meses = [1=>'enero',2=>'febrero',3=>'marzo',4=>'abril',5=>'mayo',6=>'junio',
        7=>'julio',8=>'agosto',9=>'septiembre',10=>'octubre',11=>'noviembre',12=>'diciembre'];
    $fechaActualizacion = (int)date('d') . ' de ' . $meses[(int)date('n')] . ' de ' . date('Y');
    ?>
    <p class="fecha">Última actualización: <?= $fechaActualizacion ?> · Aplica al sistema SAVID</p>

    <div class="aviso">
        Este documento describe con precisión técnica lo que el sistema SAVID hace hoy con los
        datos que registra. <strong>No sustituye asesoría jurídica</strong>: antes de publicarlo
        formalmente, complete los datos marcados como <span class="placeholder">[pendiente]</span>
        y valide el texto con un abogado, en especial las secciones sobre derechos del titular y
        régimen sancionatorio (Ley 1581 de 2012, Decreto 1377 de 2013 y demás normas colombianas
        de protección de datos aplicables).
    </div>

    <h2>1. Responsable del tratamiento</h2>
    <table>
        <tr><th>Razón social</th><td class="placeholder">[RAZÓN SOCIAL — pendiente]</td></tr>
        <tr><th>NIT</th><td class="placeholder">[NIT — pendiente]</td></tr>
        <tr><th>Domicilio</th><td class="placeholder">[DIRECCIÓN — pendiente]</td></tr>
        <tr><th>Correo para solicitudes de datos</th><td class="placeholder">[CORREO HABEAS DATA — pendiente]</td></tr>
        <tr><th>Encargado de protección de datos</th><td class="placeholder">[NOMBRE/ROL o "No se ha designado uno de forma independiente" — pendiente]</td></tr>
    </table>

    <h2>2. Qué datos recolecta el sistema</h2>
    <p>SAVID es un sistema de gestión que usan empresas clientes para administrar su propia
    operación. Recolecta dos tipos de datos personales, con dueños distintos:</p>

    <h3>2.1. Datos de las personas que usan el sistema (empleados/usuarios de la empresa cliente)</h3>
    <ul>
        <li><strong>Identificación:</strong> nombres, apellidos o razón social, tipo y número de
            documento de identidad, dígito de verificación, fechas de expedición/vencimiento.</li>
        <li><strong>Contacto:</strong> correo electrónico, teléfono, celular, dirección y ubicación
            (país, departamento, municipio, zona, comuna o corregimiento, barrio o vereda).</li>
        <li><strong>Imágenes:</strong> foto y firma, si la empresa cliente decide cargarlas.</li>
        <li><strong>Credenciales de acceso:</strong> nombre de usuario y contraseña — la contraseña
            <strong>nunca se guarda en texto plano</strong>, solo su hash.</li>
        <li><strong>Datos técnicos de sesión:</strong> dirección IP, navegador (user agent), fecha y
            hora de inicio/cierre de sesión, e intentos de inicio de sesión (exitosos y fallidos).</li>
        <li><strong>Rastro de auditoría:</strong> cada creación, modificación o baja de un registro
            queda asociada a qué usuario la hizo, cuándo y desde qué IP (sección 6).</li>
    </ul>
    <p>Salvo el tipo y número de documento de identidad, estos campos son <strong>opcionales</strong>
    a nivel de base de datos: la empresa cliente decide cuáles pide realmente en su operación.</p>

    <h3>2.2. Datos de terceros que la empresa cliente registra en el sistema</h3>
    <p>SAVID también almacena datos de personas que <strong>no son usuarios del sistema</strong> —
    por ejemplo, clientes, proveedores o contactos de la empresa que lo usa (registro genérico de
    "terceros"). Estas personas no tienen relación directa con SAVID: quien decide qué datos suyos
    se registran, y responde por haber obtenido su autorización para hacerlo, es la
    <strong>empresa cliente</strong>, no SAVID. Los campos son los mismos descritos en 2.1
    (identificación y contacto), sin credenciales de acceso.</p>

    <div class="aviso">
        Integración con software hospitalario (SIHOS): SAVID <strong>no almacena historias clínicas
        ni datos de pacientes</strong>. La aplicación puede consultar en tiempo real, con acceso
        de <strong>solo lectura forzado por el motor de base de datos</strong>, distintos reportes
        agregados de gestión (por ejemplo, cruces de presupuesto y contabilidad, y otros que se
        irán habilitando con el tiempo) contra la base de datos propia de la institución de salud
        cliente — esos datos siguen siendo, en todo momento, de esa institución, no de SAVID. En un
        número reducido de acciones explícitas y auditadas, un usuario autorizado puede corregir un
        movimiento <strong>contable o presupuestal</strong> puntual en esa base (nunca un dato
        clínico), usando una credencial de escritura separada y opcional que cada institución
        configura si así lo decide.
    </div>

    <h2>3. Uso de inteligencia artificial</h2>
    <p><strong>SAVID no usa inteligencia artificial ni modelos de lenguaje para procesar,
    analizar o tomar decisiones sobre los datos de sus usuarios.</strong> No hay ninguna
    integración con servicios de IA (OpenAI, Anthropic u otros) en el código de la aplicación
    que usted usa día a día.</p>
    <p>Nota de transparencia, aparte de lo anterior: como buena parte del software moderno, el
    <em>desarrollo</em> de SAVID puede apoyarse en herramientas de asistencia de programación con
    IA (por ejemplo, para escribir o revisar código). Eso es un proceso interno de construcción
    del software, igual que usar un editor de código — no implica que sus datos, una vez el
    sistema está en producción, pasen por ningún servicio de IA.</p>

    <h2>4. Con quién se comparten los datos</h2>
    <p>SAVID no vende ni cede datos personales a terceros con fines comerciales. Los únicos
    servicios externos que reciben datos, y solo lo estrictamente necesario para su función
    puntual, son:</p>
    <table>
        <tr><th>Servicio</th><th>Para qué</th><th>Qué recibe</th></tr>
        <tr>
            <td>Proveedor de correo saliente (configurable por cada instalación; en un despliegue
                típico, Gmail/Google Workspace u otro proveedor SMTP)</td>
            <td>Enviar el código de doble factor de autenticación, el enlace para recuperar
                contraseña, y alertas de seguridad (p. ej. bloqueo por intentos fallidos)</td>
            <td>Correo electrónico del destinatario y el contenido de ese mensaje puntual</td>
        </tr>
        <tr>
            <td>Cloudflare Turnstile</td>
            <td>Distinguir personas reales de bots automatizados en el inicio de sesión</td>
            <td>Un token de verificación y la dirección IP del visitante — nada más</td>
        </tr>
    </table>
    <p>No se usan redes sociales, publicidad ni analítica de terceros dentro de la aplicación.</p>

    <h2>5. Dónde viven los datos</h2>
    <p>La base de datos corre en un servidor propio de la empresa/proveedor de hospedaje
    contratado <span class="placeholder">[precisar proveedor de hospedaje — pendiente]</span>,
    no en un servicio de nube gestionado por un tercero externo con acceso a los datos. Las copias
    de seguridad se generan y almacenan en el mismo servidor; no se suben automáticamente a
    ningún destino externo.</p>

    <h2>6. Rastro de auditoría y seguridad de la información</h2>
    <p>Toda creación, modificación o baja de un registro queda escrita en un rastro de auditoría
    interno, con quién la hizo, cuándo, desde qué dirección IP y desde qué navegador. Este rastro
    existe para poder investigar accesos indebidos y sustentar la integridad de la información
    ante la propia empresa cliente — no se usa con fines distintos.</p>
    <p>Los registros de auditoría más antiguos que la ventana de retención activa se archivan (no
    se eliminan) a una tabla histórica separada. Las contraseñas siempre se guardan cifradas
    (hash), nunca en texto plano; las credenciales de conexión a sistemas externos se cifran en la
    base de datos.</p>

    <h2>7. Sus derechos sobre los datos (Habeas Data)</h2>
    <p>Como titular de sus datos personales, usted puede solicitar en cualquier momento:</p>
    <ol>
        <li><strong>Conocer</strong> qué datos suyos tenemos registrados.</li>
        <li><strong>Actualizar o corregir</strong> datos desactualizados o inexactos.</li>
        <li><strong>Solicitar la eliminación</strong> de sus datos, en los casos en que la ley lo
            permita.</li>
        <li><strong>Revocar</strong> la autorización de tratamiento otorgada, cuando aplique.</li>
    </ol>
    <p>Si usted es empleado o usuario directo de una empresa que usa SAVID, o un tercero
    registrado por ella (cliente, proveedor, contacto), la vía más rápida es contactar
    directamente a esa empresa — ella es quien administra su información día a día. También puede
    escribirnos a <span class="placeholder">[correo de contacto — pendiente]</span> y
    trasladaremos su solicitud a la empresa correspondiente.</p>

    <h3>7.1. Límites reales al derecho de supresión — explicados con honestidad</h3>
    <p>Cuando se solicita eliminar un dato, esto es lo que ocurre técnicamente hoy, y por qué:</p>
    <ul>
        <li><strong>El registro se desactiva</strong> (queda marcado como eliminado y deja de
            aparecer en el sistema, en listados y en reportes) en lugar de borrarse físicamente de
            inmediato. Esto es así porque el mismo registro puede estar referenciado por
            operaciones históricas (facturas, matrículas, permisos ya otorgados, expedientes
            documentales) que la empresa cliente puede tener obligación legal o contable de
            conservar — borrarlo de golpe rompería esa trazabilidad para todos los demás
            registros relacionados, no solo el suyo.</li>
        <li><strong>El rastro de auditoría no se borra</strong> aunque el dato al que se refiere sí
            se desactive. Es un registro histórico que mezcla eventos de muchos usuarios y
            tablas a la vez: eliminar selectivamente las líneas de una sola persona rompería la
            integridad de todo el historial (no se podría confiar en que el resto del log está
            completo). Estos registros sí se archivan automáticamente por antigüedad, y ese
            archivo histórico puede purgarse pasado un tiempo — pero no a solicitud selectiva de
            una sola persona.</li>
        <li><strong>Lo que sí se hace de inmediato</strong> ante una solicitud válida: se
            desactiva el registro (deja de ser visible/usable en el sistema), se elimina o
            enmascara la información de contacto e identificación no requerida por ninguna
            obligación de retención, y se documenta la solicitud atendida.</li>
    </ul>
    <p>Si su caso requiere un borrado físico completo y no encuentra razonable esta explicación,
    puede escalarlo por el mismo correo de contacto — se evaluará caso por caso frente a las
    obligaciones legales concretas que apliquen.</p>

    <h2>8. Testimonios y contenido de mercadeo</h2>
    <p><strong>No usamos testimonios fabricados, comprados o de personas que no sean clientes
    reales.</strong> Todo testimonio o caso de éxito que se muestre en materiales de SAVID
    corresponde a una empresa o persona real que efectivamente usa el sistema, con su
    autorización explícita para publicarlo. Si un testimonio o cita atribuida a SAVID o a un
    cliente le parece falsa o fue publicada sin autorización, repórtelo a
    <span class="placeholder">[correo de contacto — pendiente]</span> y será retirado mientras se
    verifica.</p>

    <h2>9. Cambios a esta política</h2>
    <p>Si esta política cambia de forma sustancial, se actualizará la fecha al inicio del
    documento y, cuando el cambio afecte cómo se tratan sus datos, se hará un aviso adicional a
    los usuarios del sistema.</p>

    <h2>10. Contacto</h2>
    <div class="contacto">
        <p style="margin:0;">Para preguntas sobre esta política o para ejercer sus derechos:</p>
        <p style="margin:6px 0 0;" class="placeholder">[correo de contacto — pendiente]</p>
    </div>

    <p style="margin-top:40px;"><a href="?url=login" class="volver">&larr; Volver al inicio de sesión</a></p>
</div>
</body>
</html>
