<?php
declare(strict_types=1);

/**
 * TalIA Connect WA - Política de privacidad pública
 * URL sugerida:
 * https://regionsur.com.mx/plataforma/talia-whatsapp/privacidad.php
 *
 * Esta página NO requiere autenticación.
 */

header('Content-Type: text/html; charset=UTF-8');

$ultimaActualizacion = '2 de octubre de 2026';
$contacto = 'armasanmar@outlook.com';
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Política de privacidad | TalIA Connect WA</title>
<meta name="description" content="Política de privacidad de TalIA Connect WA.">
<style>
:root{--ink:#172033;--muted:#667085;--brand:#6c3df0;--line:#e4e7ec;--bg:#f7f8fb}
*{box-sizing:border-box}
body{margin:0;background:var(--bg);font-family:Inter,Segoe UI,Arial,sans-serif;color:var(--ink);line-height:1.65}
header{background:#111827;color:#fff}.wrap{max-width:920px;margin:auto;padding:28px 24px}
.brand{display:flex;align-items:center;gap:12px}.logo{width:46px;height:46px;border-radius:14px;background:linear-gradient(135deg,var(--brand),#9b74ff);display:grid;place-items:center;font-weight:800;font-size:21px}
.brand h1{font-size:19px;margin:0}.brand small{color:#cbd5e1}
main.wrap{padding-top:38px;padding-bottom:54px}
article{background:#fff;border:1px solid var(--line);border-radius:20px;padding:34px;box-shadow:0 14px 36px rgba(20,32,51,.06)}
h2{font-size:30px;line-height:1.2;margin:0 0 8px}h3{margin-top:30px;font-size:19px}p,li{color:#344054}
.meta{color:var(--muted);margin-bottom:28px}.callout{background:#f4f0ff;border-left:4px solid var(--brand);padding:15px 17px;border-radius:10px}
a{color:#4f46e5}.footer{margin-top:24px;color:var(--muted);font-size:13px}
@media(max-width:640px){article{padding:22px}.wrap{padding-left:14px;padding-right:14px}h2{font-size:25px}}
</style>
</head>
<body>
<header>
<div class="wrap">
    <div class="brand">
        <div class="logo">T</div>
        <div><h1>TalIA Connect WA</h1><small>Política de privacidad</small></div>
    </div>
</div>
</header>

<main class="wrap">
<article>
    <h2>Política de privacidad</h2>
    <div class="meta">Última actualización: <?= htmlspecialchars($ultimaActualizacion, ENT_QUOTES, 'UTF-8') ?></div>

    <div class="callout">
        TalIA Connect WA es una solución tecnológica para administrar comunicaciones de atención
        mediante WhatsApp Business Platform y herramientas internas de TalIA.
    </div>

    <h3>1. Responsable y alcance</h3>
    <p>
        Esta política describe el tratamiento de información realizado por TalIA Connect WA
        al operar funciones de mensajería, atención automatizada y seguimiento de conversaciones.
        Aplica a las personas que interactúan con números de WhatsApp conectados a la plataforma
        y a los usuarios autorizados que configuran u operan el servicio.
    </p>

    <h3>2. Información que podemos tratar</h3>
    <p>Dependiendo de la interacción y de la configuración del servicio, podemos tratar:</p>
    <ul>
        <li>Identificadores de WhatsApp, número telefónico y nombre de perfil disponible.</li>
        <li>Contenido de mensajes enviados y recibidos, incluidos tipos de mensaje y metadatos necesarios para procesarlos.</li>
        <li>Identificadores técnicos de mensajes, fechas, horas y estados de entrega, lectura o falla.</li>
        <li>Datos de configuración del canal, como horarios, modo de atención y mensajes parametrizados.</li>
        <li>Registros técnicos y de diagnóstico indispensables para seguridad, trazabilidad y resolución de incidencias.</li>
    </ul>

    <h3>3. Finalidades</h3>
    <p>La información se utiliza para:</p>
    <ul>
        <li>Recibir, procesar, organizar y responder comunicaciones de WhatsApp.</li>
        <li>Permitir atención automatizada, manual o híbrida según la configuración autorizada.</li>
        <li>Gestionar continuidad de conversaciones y estados de los mensajes.</li>
        <li>Proteger la plataforma, prevenir uso indebido y mantener trazabilidad técnica.</li>
        <li>Atender solicitudes de soporte, privacidad y eliminación de datos.</li>
    </ul>

    <h3>4. Proveedores y transferencias necesarias</h3>
    <p>
        Para prestar el servicio utilizamos infraestructura tecnológica y la plataforma de WhatsApp
        proporcionada por Meta. Determinados datos pueden ser procesados por dichos proveedores
        únicamente en la medida necesaria para operar la mensajería, alojamiento, seguridad y soporte.
        TalIA Connect WA no vende datos personales a terceros.
    </p>

    <h3>5. Conservación</h3>
    <p>
        Conservamos la información únicamente durante el tiempo necesario para las finalidades
        descritas, para mantener continuidad operativa, resolver incidencias y cumplir obligaciones
        aplicables. Los registros técnicos pueden mantenerse durante periodos razonables de
        seguridad y auditoría.
    </p>

    <h3>6. Seguridad</h3>
    <p>
        Aplicamos controles técnicos y organizativos razonables para proteger la información,
        incluyendo restricciones de acceso, autenticación, manejo controlado de credenciales,
        separación de funciones y registros de eventos. Ningún sistema es completamente inmune
        a riesgos, por lo que los controles se revisan de forma continua.
    </p>

    <h3>7. Derechos y solicitudes de privacidad</h3>
    <p>
        Las personas pueden solicitar información sobre sus datos, su corrección, oposición,
        limitación o eliminación cuando corresponda. También pueden solicitar la revocación del
        consentimiento cuando la base del tratamiento lo permita.
    </p>
    <p>
        Para presentar una solicitud, escribe a
        <a href="mailto:<?= htmlspecialchars($contacto, ENT_QUOTES, 'UTF-8') ?>">
            <?= htmlspecialchars($contacto, ENT_QUOTES, 'UTF-8') ?>
        </a>.
        Podremos solicitar información razonable para verificar la identidad y localizar los datos
        asociados a la solicitud.
    </p>

    <h3>8. Uso de WhatsApp</h3>
    <p>
        Las comunicaciones procesadas mediante WhatsApp también están sujetas a las políticas,
        condiciones y controles propios de WhatsApp y Meta. TalIA Connect WA procesa la información
        necesaria para la integración técnica y la operación de las conversaciones autorizadas.
    </p>

    <h3>9. Menores de edad</h3>
    <p>
        El servicio no está diseñado para recopilar de manera intencional información de menores
        de edad. Si se identifica información de este tipo sin una base válida para su tratamiento,
        podrá solicitarse su eliminación mediante el canal de contacto indicado.
    </p>

    <h3>10. Cambios a esta política</h3>
    <p>
        Esta política puede actualizarse para reflejar cambios en la plataforma, en los servicios
        o en requisitos legales y de seguridad. La versión vigente se publicará permanentemente
        en esta misma URL.
    </p>

    <h3>11. Contacto</h3>
    <p>
        Para preguntas relacionadas con privacidad o tratamiento de información:
        <a href="mailto:<?= htmlspecialchars($contacto, ENT_QUOTES, 'UTF-8') ?>">
            <?= htmlspecialchars($contacto, ENT_QUOTES, 'UTF-8') ?>
        </a>.
    </p>

    <div class="footer">TalIA Connect WA · regionsur.com.mx</div>
</article>
</main>
</body>
</html>
