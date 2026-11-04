<?php
require __DIR__ . '/lib.php';
$u = requiere_login();

$verify = token_verificacion();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = (string)($_POST['accion'] ?? '');
    $ancla = '';

    if ($accion === 'gemini' || $accion === 'gemini_probar') {
        $ancla = '#gemini';
        $llave = trim((string)($_POST['gemini_llave'] ?? ''));
        if ($llave !== '') guardar_ajuste('gemini_llave', $llave);
        $modelo = (string)($_POST['gemini_modelo'] ?? '');
        if ($modelo === 'otro') $modelo = trim((string)($_POST['gemini_modelo_otro'] ?? ''));
        if (preg_match('/^[A-Za-z0-9.\-_]{3,80}$/', $modelo)) {
            guardar_ajuste('gemini_modelo', $modelo);
        } else {
            flash('error', 'El nombre del modelo no es válido. Dejé ' . modelo_gemini() . '.');
        }
        if ($accion === 'gemini_probar') {
            $r = gemini_probar();
            if ($r['ok']) flash('ok', '✅ ¡Conexión con Gemini OK! Modelo: ' . modelo_gemini() . '. Tu agente ya tiene cerebro.');
            else flash('error', '❌ Gemini no respondió. Este es el error exacto:', $r['error']);
        } else {
            flash('ok', 'Ajustes de Gemini guardados.');
        }
    }

    if ($accion === 'whatsapp' || $accion === 'wa_verificar') {
        $ancla = '#whatsapp';
        $token = trim((string)($_POST['wa_token'] ?? ''));
        if ($token !== '') guardar_ajuste('wa_token', preg_replace('/\s+/', '', $token));
        guardar_ajuste('wa_phone_id', solo_digitos((string)($_POST['wa_phone_id'] ?? '')));
        guardar_ajuste('wa_waba_id', solo_digitos((string)($_POST['wa_waba_id'] ?? '')));
        $v = trim((string)($_POST['wa_verify'] ?? ''));
        if (preg_match('/^[A-Za-z0-9_\-.]{4,80}$/', $v)) guardar_ajuste('wa_verify', $v);
        else flash('error', 'El token de verificación solo puede tener letras, números, guiones y puntos (mínimo 4). Dejé el anterior.');
        $ver = trim((string)($_POST['wa_version'] ?? 'v23.0'));
        guardar_ajuste('wa_version', preg_match('/^v\d+\.\d+$/', $ver) ? $ver : 'v23.0');
        if ($accion === 'whatsapp') flash('ok', 'Ajustes de WhatsApp guardados. Ahora toca "Verificar y conectar con Meta".');
    }

    if ($accion === 'wa_verificar') {
        $ancla = '#whatsapp';
        $res = wa_verificar();
        $todo_ok = !in_array(false, array_column($res, 0), true);
        $texto = implode("\n", array_map(fn($x) => ($x[0] ? '✅ ' : '❌ ') . $x[1], $res));
        flash($todo_ok ? 'ok' : 'error', $todo_ok ? '✅ Todo listo con Meta.' : 'Revisa esto:', $texto);
    }

    if ($accion === 'wa_prueba') {
        $ancla = '#whatsapp';
        $tel = normalizar_telefono((string)($_POST['telefono'] ?? ''));
        if (!$tel) {
            flash('error', 'Escribe el número al que quieres enviar la prueba (con código de país).');
        } else {
            $r = wa_plantilla_hola($tel);
            if ($r['ok']) flash('ok', '✅ Meta aceptó el mensaje de prueba. Revisa el WhatsApp de ' . $tel . '.', $r['respuesta']);
            else flash('error', '❌ No se pudo enviar: ' . $r['error'], $r['respuesta']);
        }
    }

    if ($accion === 'widget') {
        $ancla = '#widget';
        guardar_ajuste('widget_titulo', mb_substr(trim((string)($_POST['widget_titulo'] ?? '')), 0, 60));
        $color = (string)($_POST['widget_color'] ?? '');
        guardar_ajuste('widget_color', preg_match('/^#[0-9a-fA-F]{6}$/', $color) ? $color : '#4f46e5');
        flash('ok', 'Widget guardado. Copia el código de nuevo si cambiaste algo.');
    }

    if ($accion === 'pasar_mysql') {
        $ancla = '#base';
        $c = [
            'servidor' => trim((string)($_POST['servidor'] ?? '')) ?: 'localhost',
            'puerto' => (int)($_POST['puerto'] ?? 0) ?: 3306,
            'base' => trim((string)($_POST['base'] ?? '')) ?: 'crm',
            'usuario' => trim((string)($_POST['usuario'] ?? '')) ?: 'root',
            'clave' => (string)($_POST['clave'] ?? ''),
        ];
        $copiar = isset($_POST['copiar']);
        $_SESSION['form_mysql'] = ['servidor' => $c['servidor'], 'puerto' => $c['puerto'], 'base' => $c['base'], 'usuario' => $c['usuario'], 'copiar' => $copiar];
        if (db_motor() === 'mysql') {
            flash('error', 'Ya estás usando MySQL.');
        } elseif (!preg_match('/^[A-Za-z0-9.\-_]{1,255}$/', $c['servidor'])) {
            flash('error', 'El servidor no es válido. En Laragon es: localhost');
        } elseif (!preg_match('/^[A-Za-z0-9_]{1,64}$/', $c['base'])) {
            flash('error', 'El nombre de la base solo puede tener letras, números y guion bajo (ej: crm).');
        } else {
            try {
                $my = conectar_mysql($c, true);
                crear_tablas($my, 'mysql');
                $n = contar_filas($my);
                if ($copiar && array_sum($n) > 0) {
                    flash('error', 'La base «' . $c['base'] . '» ya tiene datos (' . $n['usuarios'] . ' usuario(s), ' . $n['contactos'] . ' contacto(s), '
                        . $n['mensajes'] . ' mensaje(s)). Para no duplicar nada, no copié y sigues en SQLite.',
                        "Tienes dos opciones:\n• Escribe otro nombre de base (se crea nueva y vacía) y vuelve a tocar el botón.\n• O, si quieres usar los datos que ya están en esa base, desmarca «Copiar mis datos actuales».");
                } else {
                    $copiados = $copiar ? copiar_datos(db(), $my) : null;
                    if (!escribir_conexion($c)) {
                        flash('error', 'Conecté con MySQL pero no pude guardar el archivo datos/conexion.php. Sigues en SQLite. Revisa que la carpeta datos/ se pueda escribir.');
                    } else {
                        registrar('Base de datos: el CRM ahora usa MySQL (' . $c['usuario'] . '@' . $c['servidor'] . ':' . $c['puerto'] . '/' . $c['base'] . ').');
                        unset($_SESSION['form_mysql']);
                        if ($copiados) {
                            flash('ok', '✅ ¡Listo! Tu CRM ahora usa MySQL (base «' . $c['base'] . '»). Copié ' . $copiados['usuarios'] . ' usuario(s), '
                                . $copiados['contactos'] . ' contacto(s), ' . $copiados['mensajes'] . ' mensaje(s) y ' . $copiados['ajustes'] . ' ajuste(s).');
                        } else {
                            flash('ok', '✅ ¡Listo! Tu CRM ahora usa MySQL (base «' . $c['base'] . '»). No copié datos: usas lo que ya había en esa base.'
                                . ($n['usuarios'] === 0 ? ' Como la base no tenía cuentas, tendrás que crear tu cuenta de nuevo.' : ''));
                        }
                    }
                }
            } catch (Throwable $e) {
                flash('error', '❌ No pude pasar a MySQL. Sigues en SQLite, no se perdió nada. ' . explicar_error_mysql($e->getMessage()), $e->getMessage());
            }
        }
    }

    if ($accion === 'volver_sqlite') {
        $ancla = '#base';
        if (db_motor() === 'mysql' && @unlink(archivo_conexion())) {
            registrar('Base de datos: el CRM volvió a SQLite.');
            flash('ok', 'Volviste a SQLite. Ves los datos que tenías antes de pasar a MySQL. Lo que hiciste mientras usabas MySQL se queda en MySQL.');
        } else {
            flash('error', 'No pude volver a SQLite. Borra a mano el archivo datos/conexion.php.');
        }
    }

    if ($accion === 'vaciar_registro') {
        $ancla = '#registro';
        @file_put_contents(CRM_DATOS . '/registro.log', '');
        flash('ok', 'Registro vaciado.');
    }

    ir('ajustes.php' . $ancla);
}

$llave = ajuste('gemini_llave');
$modelo = modelo_gemini();
$es_otro = !in_array($modelo, MODELOS_GEMINI, true);
$webhook = base_url() . '/webhook.php';
$ruta_webhook = base_path() . '/webhook.php';
$w_titulo = ajuste('widget_titulo') ?: nombre_agente();
$w_color = ajuste('widget_color', '#4f46e5') ?: '#4f46e5';

function explicar_error_mysql(string $m): string
{
    if (str_contains($m, '1045') || stripos($m, 'Access denied') !== false) return 'El usuario o la contraseña de MySQL no son correctos (en Laragon: usuario root y contraseña vacía).';
    if (str_contains($m, '2002') || str_contains($m, '10061') || stripos($m, 'refused') !== false) return 'MySQL no responde: ¿está encendido? En Laragon toca Start All.';
    if (str_contains($m, '2005') || stripos($m, 'Unknown MySQL server host') !== false) return 'No encuentro ese servidor. En Laragon es: localhost';
    if (str_contains($m, '1049') || str_contains($m, '1044')) return 'La base no existe y tu usuario no tiene permiso para crearla. Créala primero (en Laragon: botón Database) o usa root.';
    if (stripos($m, 'pdo_mysql') !== false) return 'Activa pdo_mysql: Laragon → PHP → Extensions → pdo_mysql.';
    return 'Revisa los datos e inténtalo de nuevo.';
}

$mysql = conexion_mysql();
$fm = ($_SESSION['form_mysql'] ?? []) + ['servidor' => 'localhost', 'puerto' => 3306, 'base' => 'crm', 'usuario' => 'root', 'copiar' => true];

$lineas = [];
$archivo = CRM_DATOS . '/registro.log';
if (is_file($archivo)) {
    $todo = file($archivo, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    $lineas = array_slice($todo, -60);
}

cabecera('Ajustes', 'ajustes');
?>
<div class="titulo-pagina"><h1>Ajustes</h1></div>

<section class="tarjeta" id="gemini">
    <h2><span class="num">1</span> Cerebro de tu agente (Gemini)</h2>
    <p class="suave">Tu agente piensa con Gemini, la IA de Google. La llave es gratis: entra a
        <a href="https://aistudio.google.com/apikey" target="_blank" rel="noopener">aistudio.google.com/apikey</a>, toca <b>Create API key</b>, cópiala y pégala aquí.</p>
    <form method="post" class="formulario">
        <?= csrf_campo() ?>
        <div class="rejilla-2">
            <label>Llave de Gemini (API key)
                <input type="password" name="gemini_llave" autocomplete="off" placeholder="<?= $llave ? h(enmascarar($llave)) . ' (guardada — déjalo vacío para no cambiarla)' : 'Pega aquí tu llave (empieza por AIza…)' ?>">
            </label>
            <label>Modelo
                <select name="gemini_modelo" id="modelo">
                    <?php foreach (MODELOS_GEMINI as $i => $m): ?>
                        <option value="<?= h($m) ?>"<?= $m === $modelo ? ' selected' : '' ?>><?= h($m) ?><?= $i === 0 ? ' (recomendado)' : '' ?></option>
                    <?php endforeach; ?>
                    <option value="otro"<?= $es_otro ? ' selected' : '' ?>>Otro…</option>
                </select>
                <input name="gemini_modelo_otro" id="modelo-otro" value="<?= $es_otro ? h($modelo) : '' ?>" placeholder="Escribe el nombre del modelo"<?= $es_otro ? '' : ' hidden' ?>>
            </label>
        </div>
        <div class="acciones">
            <button class="btn" type="submit" name="accion" value="gemini">Guardar</button>
            <button class="btn btn-sec" type="submit" name="accion" value="gemini_probar">Probar conexión con Gemini</button>
        </div>
        <?php if ($llave): ?><p class="suave chico">Llave guardada: <?= h(enmascarar($llave)) ?></p><?php endif; ?>
    </form>
</section>

<section class="tarjeta" id="whatsapp">
    <h2><span class="num">2</span> WhatsApp (Meta)</h2>
    <p class="suave">Copia estos datos desde <a href="https://developers.facebook.com/apps" target="_blank" rel="noopener">developers.facebook.com</a> → tu app → WhatsApp → <b>API Setup</b>.</p>
    <form method="post" class="formulario">
        <?= csrf_campo() ?><input type="hidden" name="accion" value="whatsapp">
        <div class="rejilla-2">
            <label>Token de acceso (Access token)
                <input type="password" name="wa_token" autocomplete="off" placeholder="<?= ajuste('wa_token') ? h(enmascarar(ajuste('wa_token'))) . ' (guardado — déjalo vacío para no cambiarlo)' : 'Pega el token (empieza por EAA…)' ?>">
            </label>
            <label>Phone number ID
                <input name="wa_phone_id" value="<?= h(ajuste('wa_phone_id')) ?>" inputmode="numeric" placeholder="Ej: 123456789012345">
            </label>
            <label>WhatsApp Business Account ID
                <input name="wa_waba_id" value="<?= h(ajuste('wa_waba_id')) ?>" inputmode="numeric" placeholder="Está debajo del Phone number ID">
            </label>
            <label>Token de verificación (lo inventas tú; pégalo igual en Meta)
                <input name="wa_verify" value="<?= h($verify) ?>">
            </label>
            <label>Versión de Graph
                <input name="wa_version" value="<?= h(version_graph()) ?>">
            </label>
        </div>
        <div class="acciones">
            <button class="btn" type="submit">Guardar</button>
            <button class="btn btn-sec" type="submit" name="accion" value="wa_verificar">Verificar y conectar con Meta</button>
        </div>
    </form>

    <div class="caja-dato">
        <span class="suave chico">Tu dirección del webhook</span>
        <div class="copiar-fila"><code id="url-webhook"><?= h($webhook) ?></code><button class="btn btn-sec btn-chico" type="button" data-copiar="url-webhook">Copiar</button></div>
        <?php if (!es_https()): ?>
            <div class="aviso aviso-alerta chico">Meta necesita una dirección <b>https</b> pública. Abre el túnel y usa la dirección que te da, terminada en <b><?= h($ruta_webhook) ?></b>
                (ejemplo: <code>https://xxxx.trycloudflare.com<?= h($ruta_webhook) ?></code>).</div>
        <?php else: ?>
            <div class="aviso aviso-ok chico">Esta dirección ya es https: puedes pegarla en Meta → Configuration → Webhook.</div>
        <?php endif; ?>
    </div>

    <form method="post" class="formulario fila-prueba">
        <?= csrf_campo() ?><input type="hidden" name="accion" value="wa_prueba">
        <label>Enviar mensaje de prueba (plantilla hello_world) a:
            <input name="telefono" placeholder="Tu celular con código de país, ej: 584141234567" inputmode="tel">
        </label>
        <button class="btn btn-sec" type="submit">Enviar mensaje de prueba</button>
    </form>
</section>

<section class="tarjeta" id="widget">
    <h2><span class="num">3</span> Chat para tu web</h2>
    <p class="suave">Pega este código en tu página (en WordPress: bloque <b>«HTML personalizado»</b>). Aparece una burbuja de chat abajo a la derecha atendida por tu agente.</p>
    <form method="post" class="formulario">
        <?= csrf_campo() ?><input type="hidden" name="accion" value="widget">
        <div class="rejilla-2">
            <label>Título del chat<input name="widget_titulo" id="w-titulo" value="<?= h($w_titulo) ?>" maxlength="60"></label>
            <label>Color<input type="color" name="widget_color" id="w-color" value="<?= h($w_color) ?>"></label>
        </div>
        <button class="btn btn-sec" type="submit">Guardar</button>
    </form>
    <div class="caja-dato">
        <span class="suave chico">Tu código para copiar</span>
        <textarea id="codigo-widget" class="codigo" rows="2" readonly></textarea>
        <button class="btn" type="button" data-copiar="codigo-widget">Copiar código</button>
        <?php if (!es_https()): ?>
            <p class="suave chico">Ojo: para que funcione en tu web publicada, abre el CRM con la dirección del túnel (https) o de tu servidor y copia el código desde ahí. Con <code>localhost</code> solo funciona en tu PC.</p>
        <?php endif; ?>
    </div>
</section>

<section class="tarjeta" id="base">
    <h2><span class="num">4</span> Base de datos</h2>
    <?php if ($mysql): ?>
        <div class="aviso aviso-ok">Ahora usas <b>MySQL</b>: base «<?= h($mysql['base']) ?>» en <?= h($mysql['servidor'] . ':' . $mysql['puerto']) ?> (usuario <?= h($mysql['usuario']) ?>).</div>
        <form method="post" onsubmit="return confirm('¿Volver a SQLite? Verás los datos que tenías antes de pasar a MySQL. Lo que hiciste en MySQL se queda en MySQL.')">
            <?= csrf_campo() ?><input type="hidden" name="accion" value="volver_sqlite">
            <button class="btn btn-sec" type="submit">Volver a SQLite</button>
        </form>
        <p class="suave chico">Si vuelves a SQLite, lo que hiciste mientras usabas MySQL se queda en MySQL (no se copia de vuelta).</p>
    <?php else: ?>
        <div class="aviso aviso-ok">Ahora usas <b>SQLite</b>: un archivo en tu PC (datos/crm.sqlite). No necesitas nada más. Solo cambia a MySQL si lo prefieres.</div>
        <p class="suave">¿Prefieres MySQL? En Laragon los datos ya vienen puestos: servidor <b>localhost</b>, puerto <b>3306</b>, usuario <b>root</b> y contraseña <b>vacía</b>. Si la base no existe, la creo.</p>
        <form method="post" class="formulario">
            <?= csrf_campo() ?><input type="hidden" name="accion" value="pasar_mysql">
            <div class="rejilla-2">
                <label>Servidor<input name="servidor" value="<?= h($fm['servidor']) ?>" placeholder="localhost"></label>
                <label>Puerto<input name="puerto" value="<?= h($fm['puerto']) ?>" inputmode="numeric" placeholder="3306"></label>
                <label>Nombre de la base<input name="base" value="<?= h($fm['base']) ?>" placeholder="crm"></label>
                <label>Usuario<input name="usuario" value="<?= h($fm['usuario']) ?>" placeholder="root" autocomplete="off"></label>
                <label>Contraseña<input type="password" name="clave" placeholder="En Laragon se deja vacía" autocomplete="new-password"></label>
            </div>
            <label class="casilla"><input type="checkbox" name="copiar" value="1"<?= $fm['copiar'] ? ' checked' : '' ?>> Copiar mis datos actuales (tu cuenta, contactos, conversaciones y llaves)</label>
            <button class="btn" type="submit">Probar y pasar a MySQL</button>
        </form>
    <?php endif; ?>
</section>

<section class="tarjeta" id="registro">
    <h2><span class="num">5</span> Registro</h2>
    <p class="suave">Aquí ves lo que va pasando: si Meta está llegando, si Gemini o WhatsApp dieron algún error. Últimas 60 líneas.</p>
    <pre class="registro"><?= $lineas ? h(implode("\n", $lineas)) : 'Todavía no hay nada en el registro.' ?></pre>
    <div class="acciones">
        <a class="btn btn-sec" href="ajustes.php#registro">Actualizar</a>
        <form method="post" onsubmit="return confirm('¿Vaciar el registro?')">
            <?= csrf_campo() ?><input type="hidden" name="accion" value="vaciar_registro">
            <button class="btn-texto peligro" type="submit">Vaciar registro</button>
        </form>
    </div>
</section>

<script>
(function () {
    var base = <?= json_encode(base_url(), JSON_UNESCAPED_SLASHES) ?>;
    var sel = document.getElementById('modelo'), otro = document.getElementById('modelo-otro');
    sel.addEventListener('change', function () { otro.hidden = sel.value !== 'otro'; if (!otro.hidden) otro.focus(); });

    var t = document.getElementById('w-titulo'), c = document.getElementById('w-color'), code = document.getElementById('codigo-widget');
    function esc(s) { return String(s).replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;'); }
    function pintar() {
        code.value = '<script src="' + base + '/widget.js" data-titulo="' + esc(t.value) + '" data-color="' + esc(c.value) + '"></' + 'script>';
    }
    t.addEventListener('input', pintar); c.addEventListener('input', pintar); pintar();

    document.querySelectorAll('[data-copiar]').forEach(function (b) {
        b.addEventListener('click', function () {
            var el = document.getElementById(b.getAttribute('data-copiar'));
            var txt = el.value !== undefined ? el.value : el.textContent;
            var listo = function () { var o = b.textContent; b.textContent = '¡Copiado!'; setTimeout(function () { b.textContent = o; }, 1500); };
            if (navigator.clipboard && window.isSecureContext) {
                navigator.clipboard.writeText(txt).then(listo, function () { copiarViejo(el, txt); listo(); });
            } else { copiarViejo(el, txt); listo(); }
        });
    });
    function copiarViejo(el, txt) {
        var ta = document.createElement('textarea'); ta.value = txt; document.body.appendChild(ta); ta.select();
        try { document.execCommand('copy'); } catch (e) {} document.body.removeChild(ta);
    }
})();
</script>
<?php pie();
