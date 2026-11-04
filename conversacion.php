<?php
require __DIR__ . '/lib.php';
$u = requiere_login();

$id = (int)($_GET['id'] ?? 0);
$c = contacto($id);
if (!$c) {
    flash('error', 'No encontré esa conversación.');
    ir('index.php');
}
$url = 'conversacion.php?id=' . $id;

function burbujas(array $c): array
{
    $msjs = q('SELECT id, de, texto, canal, creado FROM mensajes WHERE contacto_id = ? ORDER BY id ASC LIMIT 500', [(int)$c['id']])->fetchAll();
    $nombre = trim((string)$c['nombre']) ?: 'Cliente';
    $agente = nombre_agente();
    $html = '';
    $ultimo = 0;
    foreach ($msjs as $m) {
        $quien = $m['de'] === 'cliente' ? $nombre : ($m['de'] === 'ia' ? '🤖 ' . $agente : 'Tú');
        $html .= '<div class="msj msj-' . h($m['de']) . '"><div class="msj-texto">' . nl2br(h($m['texto'])) . '</div>'
            . '<div class="msj-meta">' . h($quien) . ' · ' . h(fecha_corta($m['creado'])) . ' · ' . ($m['canal'] === 'whatsapp' ? 'WhatsApp' : 'Web') . '</div></div>';
        $ultimo = (int)$m['id'];
    }
    if ($html === '') $html = '<p class="suave centrado">Aún no hay mensajes en esta conversación.</p>';
    return [$html, $ultimo];
}

// ---------- Actualización automática (fetch)
if (isset($_GET['parcial'])) {
    [$html, $ultimo] = burbujas($c);
    json_salida(['ultimo' => $ultimo, 'html' => $html, 'necesita_humano' => (int)$c['necesita_humano'], 'ia_activa' => (int)$c['ia_activa']]);
}

// ---------- Acciones
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = (string)($_POST['accion'] ?? '');
    if ($accion === 'ia') {
        $on = isset($_POST['ia_activa']) ? 1 : 0;
        q('UPDATE contactos SET ia_activa = ?, actualizado = ? WHERE id = ?', [$on, ahora(), $id]);
        flash('ok', $on ? 'La IA vuelve a responder esta conversación.' : 'Listo: la IA ya no responde aquí. Ahora respondes tú.');
    } elseif ($accion === 'atendido') {
        q('UPDATE contactos SET necesita_humano = 0, actualizado = ? WHERE id = ?', [ahora(), $id]);
        flash('ok', 'Marcado como atendido.');
    } elseif ($accion === 'enviar') {
        $texto = trim((string)($_POST['texto'] ?? ''));
        if ($texto === '') {
            flash('error', 'Escribe un mensaje antes de enviar.');
        } elseif (canal_contacto($c) === 'whatsapp') {
            $r = wa_enviar((string)$c['telefono'], $texto);
            if ($r['ok']) {
                guardar_mensaje($id, 'humano', $texto, 'whatsapp', $r['wa_id']);
                q('UPDATE contactos SET necesita_humano = 0 WHERE id = ?', [$id]);
            } else {
                flash('error', 'No se pudo enviar por WhatsApp. ' . $r['error']);
                $_SESSION['borrador'][$id] = $texto;
            }
        } else {
            guardar_mensaje($id, 'humano', $texto, 'web');
            q('UPDATE contactos SET necesita_humano = 0 WHERE id = ?', [$id]);
        }
    } elseif ($accion === 'datos') {
        $nombre = trim((string)($_POST['nombre'] ?? ''));
        $tel = normalizar_telefono((string)($_POST['telefono'] ?? ''));
        $correo = trim((string)($_POST['correo'] ?? ''));
        $etapa = in_array($_POST['etapa'] ?? '', ETAPAS, true) ? $_POST['etapa'] : $c['etapa'];
        $pa = trim((string)($_POST['proxima_accion'] ?? ''));
        $pf = (string)($_POST['proxima_fecha'] ?? '');
        $pf = preg_match('/^\d{4}-\d{2}-\d{2}$/', $pf) ? $pf : null;
        $notas = trim((string)($_POST['notas'] ?? ''));
        $otro = $tel ? q('SELECT id FROM contactos WHERE telefono = ? AND id <> ?', [$tel, $id])->fetchColumn() : false;
        if ($correo !== '' && !filter_var($correo, FILTER_VALIDATE_EMAIL)) {
            flash('error', 'Ese correo no parece válido.');
        } elseif ($otro) {
            flash('error', 'Ya tienes otro contacto con ese teléfono.');
        } else {
            q('UPDATE contactos SET nombre = ?, telefono = ?, correo = ?, etapa = ?, proxima_accion = ?, proxima_fecha = ?, notas = ?, actualizado = ? WHERE id = ?',
                [mb_substr($nombre, 0, 100), $tel, $correo ?: null, $etapa, $pa ?: null, $pf, $notas ?: null, ahora(), $id]);
            flash('ok', 'Datos guardados.');
        }
    } elseif ($accion === 'eliminar') {
        q('DELETE FROM mensajes WHERE contacto_id = ?', [$id]);
        q('DELETE FROM contactos WHERE id = ?', [$id]);
        flash('ok', 'Contacto y conversación eliminados.');
        ir('index.php');
    }
    ir($url);
}

[$html, $ultimo] = burbujas($c);
$borrador = $_SESSION['borrador'][$id] ?? '';
unset($_SESSION['borrador'][$id]);
$canal = canal_contacto($c);
$nombre = trim((string)$c['nombre']) ?: ($c['telefono'] ?: 'Sin nombre');
$wa = $c['telefono'] ? 'https://wa.me/' . wa_numero((string)$c['telefono']) : '';

cabecera($nombre, 'conversaciones');
?>
<a class="volver" href="index.php">← Conversaciones</a>
<div class="conv-pagina">
    <section class="tarjeta chat">
        <div class="chat-cabeza">
            <div class="avatar"><?= h(mb_strtoupper(mb_substr($nombre, 0, 1))) ?></div>
            <div class="chat-titulo">
                <b><?= h($nombre) ?></b>
                <div><?= etiqueta_canal($canal) ?> <span class="etiqueta etiqueta-etapa"><?= h($c['etapa']) ?></span>
                    <span id="alerta" class="etiqueta etiqueta-roja"<?= (int)$c['necesita_humano'] ? '' : ' hidden' ?>>Quiere hablar con una persona</span></div>
            </div>
        </div>
        <div class="chat-controles">
            <form method="post" action="<?= h($url) ?>" class="interruptor-form">
                <?= csrf_campo() ?><input type="hidden" name="accion" value="ia">
                <label class="interruptor">
                    <input type="checkbox" name="ia_activa" value="1" <?= (int)$c['ia_activa'] ? 'checked' : '' ?> onchange="this.form.submit()">
                    <span class="interruptor-bola"></span>
                    <span>La IA responde esta conversación</span>
                </label>
            </form>
            <?php if (!ia_general_activa()): ?><span class="etiqueta etiqueta-gris" title="Se activa en Mi agente">La IA está apagada para todos</span><?php endif; ?>
            <form method="post" action="<?= h($url) ?>" id="form-atendido"<?= (int)$c['necesita_humano'] ? '' : ' hidden' ?>>
                <?= csrf_campo() ?><input type="hidden" name="accion" value="atendido">
                <button class="btn btn-sec btn-chico" type="submit">✓ Ya lo atendí</button>
            </form>
        </div>
        <div id="mensajes" class="mensajes" data-ultimo="<?= (int)$ultimo ?>"><?= $html ?></div>
        <form method="post" action="<?= h($url) ?>" class="redactar">
            <?= csrf_campo() ?><input type="hidden" name="accion" value="enviar">
            <textarea name="texto" id="texto" rows="2" placeholder="<?= $canal === 'whatsapp' ? 'Escribe tu respuesta (se envía por WhatsApp)…' : 'Escribe tu respuesta (le llega al chat de tu web)…' ?>" required><?= h($borrador) ?></textarea>
            <button class="btn" type="submit">Enviar</button>
        </form>
        <p class="suave chico">Consejo: si vas a responder tú, apaga la IA de esta conversación para que no se crucen. Ctrl + Enter envía.</p>
    </section>

    <aside class="tarjeta datos">
        <h3>Datos del contacto</h3>
        <form method="post" action="<?= h($url) ?>" class="formulario">
            <?= csrf_campo() ?><input type="hidden" name="accion" value="datos">
            <label>Nombre<input name="nombre" value="<?= h($c['nombre']) ?>" maxlength="100"></label>
            <label>Teléfono (con código de país)<input name="telefono" value="<?= h($c['telefono']) ?>" placeholder="584141234567" inputmode="tel"></label>
            <label>Correo<input type="email" name="correo" value="<?= h($c['correo']) ?>"></label>
            <label>Etapa
                <select name="etapa"><?php foreach (ETAPAS as $e): ?><option<?= $e === $c['etapa'] ? ' selected' : '' ?>><?= h($e) ?></option><?php endforeach; ?></select>
            </label>
            <label>Próxima acción<input name="proxima_accion" value="<?= h($c['proxima_accion']) ?>" placeholder="Ej: Llamarlo para enviar cotización"></label>
            <label>Fecha de la próxima acción<input type="date" name="proxima_fecha" value="<?= h($c['proxima_fecha']) ?>"></label>
            <?php if (empty($c['proxima_accion']) || empty($c['proxima_fecha'])): ?>
                <div class="aviso aviso-alerta chico">⚠️ Este contacto no tiene próxima acción con fecha.</div>
            <?php endif; ?>
            <label>Notas<textarea name="notas" rows="4"><?= h($c['notas']) ?></textarea></label>
            <button class="btn" type="submit">Guardar datos</button>
        </form>
        <div class="datos-extra">
            <?php if ($wa): ?><a class="btn btn-wa" href="<?= h($wa) ?>" target="_blank" rel="noopener">Abrir en WhatsApp</a><?php endif; ?>
            <p class="suave chico">Origen: <?= h($c['origen']) ?> · Creado: <?= h(fecha_corta($c['creado'])) ?></p>
            <form method="post" action="<?= h($url) ?>" onsubmit="return confirm('¿Seguro? Se borra este contacto y toda su conversación. No se puede deshacer.')">
                <?= csrf_campo() ?><input type="hidden" name="accion" value="eliminar">
                <button class="btn-texto peligro" type="submit">Eliminar contacto</button>
            </form>
        </div>
    </aside>
</div>

<script>
(function () {
    var caja = document.getElementById('mensajes');
    var alerta = document.getElementById('alerta'), atendido = document.getElementById('form-atendido');
    var texto = document.getElementById('texto');
    caja.scrollTop = caja.scrollHeight;
    texto.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) { e.preventDefault(); texto.form.submit(); }
    });
    function refrescar() {
        fetch('conversacion.php?id=<?= (int)$id ?>&parcial=1', {headers: {'X-Requested-With': 'fetch'}, cache: 'no-store'})
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (d) {
                if (!d) return;
                if (String(d.ultimo) !== caja.getAttribute('data-ultimo')) {
                    var abajo = caja.scrollHeight - caja.scrollTop - caja.clientHeight < 80;
                    caja.innerHTML = d.html;
                    caja.setAttribute('data-ultimo', d.ultimo);
                    if (abajo) caja.scrollTop = caja.scrollHeight;
                }
                alerta.hidden = !d.necesita_humano;
                atendido.hidden = !d.necesita_humano;
            }).catch(function () {});
    }
    setInterval(refrescar, 4000);
})();
</script>
<?php pie();
