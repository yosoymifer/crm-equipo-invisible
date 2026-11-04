<?php
require __DIR__ . '/lib.php';
$u = requiere_login();

function sesion_prueba(bool $nueva = false): string
{
    if ($nueva || empty($_SESSION['prueba_sesion']) || !sesion_valida((string)$_SESSION['prueba_sesion'])) {
        $_SESSION['prueba_sesion'] = 'prueba' . bin2hex(random_bytes(10));
    }
    return $_SESSION['prueba_sesion'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = (string)($_POST['accion'] ?? '');
    if ($accion === 'probar') {
        if (!ia_general_activa()) {
            json_salida(['error' => 'La IA está apagada. Activa «La IA responde» y guarda para probarla.'], 400);
        }
        $r = chat_web_procesar(sesion_prueba(), (string)($_POST['texto'] ?? ''), true);
        json_salida($r['datos'], $r['codigo']);
    }
    if ($accion === 'reiniciar') {
        sesion_prueba(true);
        json_salida(['ok' => true]);
    }
    if ($accion === 'guardar') {
        $nombre = trim((string)($_POST['agente_nombre'] ?? ''));
        guardar_ajuste('agente_nombre', mb_substr($nombre !== '' ? $nombre : 'Sofía', 0, 60));
        guardar_ajuste('agente_saludo', mb_substr(trim((string)($_POST['agente_saludo'] ?? '')), 0, 500));
        guardar_ajuste('ia_activa', isset($_POST['ia_activa']) ? '1' : '0');
        guardar_ajuste('agente_sabe', mb_substr(trim((string)($_POST['agente_sabe'] ?? '')), 0, 60000));
        flash('ok', '¡Guardado! Tu agente ya usa esta información.');
        ir('agente.php');
    }
    ir('agente.php');
}

$saludo = ajuste('agente_saludo', '¡Hola! 👋 ¿En qué te puedo ayudar?');
$sabe = ajuste('agente_sabe', PLANTILLA_SABE);
$sesion = sesion_prueba();
$cprueba = q('SELECT id FROM contactos WHERE sesion_web = ?', [$sesion])->fetchColumn();
$historial = $cprueba ? mensajes_web((int)$cprueba, 0) : [];

cabecera('Mi agente', 'agente');
?>
<div class="titulo-pagina"><h1>Mi agente</h1></div>
<div class="agente-pagina">
    <section class="tarjeta">
        <form method="post" class="formulario">
            <?= csrf_campo() ?><input type="hidden" name="accion" value="guardar">
            <div class="rejilla-2">
                <label>Nombre de tu agente<input name="agente_nombre" value="<?= h(nombre_agente()) ?>" maxlength="60" placeholder="Sofía"></label>
                <label>Saludo inicial del chat de tu web<input name="agente_saludo" value="<?= h($saludo) ?>" maxlength="500"></label>
            </div>
            <label class="interruptor">
                <input type="checkbox" name="ia_activa" value="1" <?= ia_general_activa() ? 'checked' : '' ?>>
                <span class="interruptor-bola"></span>
                <span><b>La IA responde</b> (si lo apagas, nadie recibe respuestas automáticas: respondes tú)</span>
            </label>
            <label>Lo que tu agente sabe de tu negocio
                <span class="ayuda">Rellena cada parte con tus datos reales. Tu agente solo responde con lo que está aquí: si algo no está, dirá que no lo sabe y ofrecerá pasar con una persona.</span>
                <textarea name="agente_sabe" class="sabe" rows="24"><?= h($sabe) ?></textarea>
            </label>
            <button class="btn btn-grande" type="submit">Guardar</button>
        </form>
    </section>

    <section class="tarjeta prueba">
        <div class="prueba-cabeza">
            <div><h3>Prueba tu agente</h3><span class="suave chico">Escríbele como si fueras un cliente. Usa lo que guardaste.</span></div>
            <button class="btn btn-sec btn-chico" type="button" id="reiniciar">Empezar de nuevo</button>
        </div>
        <div id="prueba-msjs" class="mensajes mensajes-prueba">
            <div class="msj msj-ia"><div class="msj-texto"><?= nl2br(h($saludo)) ?></div></div>
            <?php foreach ($historial as $m): ?>
                <div class="msj msj-<?= h($m['de']) ?>"><div class="msj-texto"><?= nl2br(h($m['texto'])) ?></div></div>
            <?php endforeach; ?>
        </div>
        <form id="prueba-form" class="redactar">
            <input id="prueba-texto" maxlength="1000" placeholder="Ej: ¿Cuánto cuesta? ¿Hacen delivery?" autocomplete="off">
            <button class="btn" type="submit">Enviar</button>
        </form>
    </section>
</div>

<script>
(function () {
    var csrf = <?= json_encode(csrf_token()) ?>;
    var caja = document.getElementById('prueba-msjs'), form = document.getElementById('prueba-form'), input = document.getElementById('prueba-texto');
    var saludo = <?= json_encode($saludo, JSON_UNESCAPED_UNICODE) ?>;
    caja.scrollTop = caja.scrollHeight;
    function burbuja(de, texto, extra) {
        var d = document.createElement('div'); d.className = 'msj msj-' + de + (extra ? ' ' + extra : '');
        var t = document.createElement('div'); t.className = 'msj-texto'; t.textContent = texto;
        d.appendChild(t); caja.appendChild(d); caja.scrollTop = caja.scrollHeight; return d;
    }
    function enviar(datos) {
        datos.append('csrf', csrf);
        return fetch('agente.php', {method: 'POST', body: datos, headers: {'X-Requested-With': 'fetch'}})
            .then(function (r) { return r.json().catch(function () { return {error: 'Respuesta inesperada del servidor.'}; }); });
    }
    form.addEventListener('submit', function (e) {
        e.preventDefault();
        var texto = input.value.trim(); if (!texto) return;
        input.value = ''; input.disabled = true;
        burbuja('cliente', texto);
        var escribiendo = burbuja('ia', 'Escribiendo…', 'escribiendo');
        var fd = new FormData(); fd.append('accion', 'probar'); fd.append('texto', texto);
        enviar(fd).then(function (d) {
            escribiendo.remove();
            if (d.error) { burbuja('sistema', d.error); return; }
            (d.mensajes || []).forEach(function (m) { if (m.de !== 'cliente') burbuja(m.de, m.texto); });
            if (d.humano) burbuja('sistema', 'Tu agente pasó esta conversación a una persona (así funcionará con tus clientes). Toca «Empezar de nuevo» para seguir probando.');
        }).catch(function () { escribiendo.remove(); burbuja('sistema', 'No pude conectar. ¿Laragon sigue encendido?'); })
          .then(function () { input.disabled = false; input.focus(); });
    });
    document.getElementById('reiniciar').addEventListener('click', function () {
        var fd = new FormData(); fd.append('accion', 'reiniciar');
        enviar(fd).then(function () { caja.innerHTML = ''; burbuja('ia', saludo); input.focus(); });
    });
})();
</script>
<?php pie();
