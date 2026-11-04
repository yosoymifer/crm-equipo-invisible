<?php
require __DIR__ . '/lib.php';

// ---------- Primera vez: crear la cuenta
if (!hay_usuarios()) {
    iniciar_sesion();
    $error = '';
    $email = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        verificar_csrf();
        $email = strtolower(trim((string)($_POST['email'] ?? '')));
        $c1 = (string)($_POST['clave'] ?? '');
        $c2 = (string)($_POST['clave2'] ?? '');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190) $error = 'Escribe un correo válido.';
        elseif (mb_strlen($c1) < 8) $error = 'La contraseña debe tener al menos 8 caracteres.';
        elseif ($c1 !== $c2) $error = 'Las dos contraseñas no coinciden.';
        elseif (hay_usuarios()) $error = 'Ya existe una cuenta. Entra con ella.';
        else {
            q('INSERT INTO usuarios (email, clave_hash, creado) VALUES (?, ?, ?)', [$email, password_hash($c1, PASSWORD_DEFAULT), ahora()]);
            entrar_como((int)db()->lastInsertId());
            token_verificacion();
            flash('ok', '¡Listo! Tu CRM ya está creado. Primer paso: pon tu llave de Gemini en Ajustes.');
            ir('index.php');
        }
    }
    cabecera('Crea tu cuenta', '', false);
    ?>
    <div class="caja-acceso">
        <div class="marca marca-grande"><span class="marca-logo">✦</span><span>CRM <b>Equipo Invisible</b></span></div>
        <h1>Crea tu cuenta</h1>
        <p class="suave">Es la primera vez que abres tu CRM. Crea el usuario con el que vas a entrar. Todo se guarda solo en esta computadora.</p>
        <?php if ($error): ?><div class="aviso aviso-error"><?= h($error) ?></div><?php endif; ?>
        <form method="post" class="formulario">
            <?= csrf_campo() ?>
            <label>Tu correo<input type="email" name="email" value="<?= h($email) ?>" required autofocus autocomplete="username"></label>
            <label>Contraseña (mínimo 8 caracteres)<input type="password" name="clave" minlength="8" required autocomplete="new-password"></label>
            <label>Repite la contraseña<input type="password" name="clave2" minlength="8" required autocomplete="new-password"></label>
            <button class="btn btn-grande" type="submit">Crear mi cuenta</button>
        </form>
    </div>
    <?php
    pie();
    exit;
}

$u = requiere_login();

// ---------- Lista de conversaciones
function lista_conversaciones(): string
{
    $filas = q("SELECT c.id, c.nombre, c.telefono, c.etapa, c.notas, c.necesita_humano, c.ia_activa, c.sesion_web, c.ultimo_mensaje,
                    m.texto AS ultimo_texto, m.de AS ultimo_de, m.canal AS ultimo_canal
                FROM contactos c
                LEFT JOIN mensajes m ON m.id = (SELECT MAX(id) FROM mensajes WHERE contacto_id = c.id)
                WHERE c.ultimo_mensaje IS NOT NULL
                ORDER BY c.necesita_humano DESC, c.ultimo_mensaje DESC
                LIMIT 200")->fetchAll();
    if (!$filas) return '';
    $html = '';
    foreach ($filas as $f) {
        $canal = $f['ultimo_canal'] ?: canal_contacto($f);
        $nombre = trim((string)$f['nombre']) ?: ($f['telefono'] ?: 'Sin nombre');
        $quien = ['cliente' => '', 'ia' => '🤖 ', 'humano' => 'Tú: '][$f['ultimo_de']] ?? '';
        $html .= '<a class="conv' . ((int)$f['necesita_humano'] ? ' conv-alerta' : '') . '" href="conversacion.php?id=' . (int)$f['id'] . '">'
            . '<div class="avatar">' . h(mb_strtoupper(mb_substr($nombre, 0, 1))) . '</div>'
            . '<div class="conv-centro"><div class="conv-fila"><b>' . h($nombre) . '</b>'
            . ($f['notas'] === 'Prueba' ? ' <span class="etiqueta etiqueta-gris">Prueba</span>' : '')
            . '<span class="conv-hora">' . h(fecha_corta($f['ultimo_mensaje'])) . '</span></div>'
            . '<div class="conv-fila"><span class="conv-previa">' . h($quien . mb_substr((string)$f['ultimo_texto'], 0, 90)) . '</span></div>'
            . '<div class="conv-fila conv-etiquetas">' . etiqueta_canal($canal)
            . '<span class="etiqueta etiqueta-etapa">' . h($f['etapa']) . '</span>'
            . ((int)$f['ia_activa'] ? '' : '<span class="etiqueta etiqueta-gris">IA apagada</span>')
            . ((int)$f['necesita_humano'] ? '<span class="etiqueta etiqueta-roja">Quiere hablar con una persona</span>' : '')
            . '</div></div></a>';
    }
    return $html;
}

if (isset($_GET['parcial'])) {
    json_salida(['html' => lista_conversaciones()]);
}

$lista = lista_conversaciones();
$falta_llave = ajuste('gemini_llave') === '';
$falta_sabe = ajuste('agente_sabe') === '';
$falta_wa = ajuste('wa_token') === '' || ajuste('wa_phone_id') === '';

cabecera('Conversaciones', 'conversaciones');
?>
<div class="titulo-pagina">
    <h1>Conversaciones</h1>
    <span class="suave">Se actualiza sola <span class="punto-vivo"></span></span>
</div>

<?php if ($falta_llave || $falta_sabe || $falta_wa): ?>
<div class="tarjeta pasos">
    <h3>Pon a trabajar a tu equipo invisible en 3 pasos</h3>
    <ol>
        <li class="<?= $falta_llave ? '' : 'hecho' ?>"><a href="ajustes.php">Pega tu llave de Gemini</a> (gratis) para darle cerebro a tu agente.</li>
        <li class="<?= $falta_sabe ? '' : 'hecho' ?>"><a href="agente.php">Enséñale tu negocio</a> y pruébalo en el chat de prueba.</li>
        <li class="<?= $falta_wa ? '' : 'hecho' ?>"><a href="ajustes.php#whatsapp">Conecta WhatsApp</a> o <a href="ajustes.php#widget">pega el chat en tu web</a>.</li>
    </ol>
</div>
<?php endif; ?>

<div id="lista" class="lista-conv"><?= $lista ?></div>
<div id="vacio" class="vacio"<?= $lista ? ' hidden' : '' ?>>
    <div class="vacio-icono">💬</div>
    <p><b>Todavía no hay conversaciones.</b></p>
    <p class="suave">Cuando alguien te escriba por WhatsApp o por el chat de tu web, aparecerá aquí al instante.<br>
        ¿Quieres verlo ya? Escríbele a tu agente en <a href="agente.php">Mi agente → Prueba tu agente</a>.</p>
</div>

<script>
(function () {
    var lista = document.getElementById('lista'), vacio = document.getElementById('vacio'), previo = null;
    function refrescar() {
        fetch('index.php?parcial=1', {headers: {'X-Requested-With': 'fetch'}, cache: 'no-store'})
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (d) {
                if (!d) return;
                if (previo !== d.html) lista.innerHTML = d.html;
                previo = d.html;
                vacio.hidden = d.html !== '';
            }).catch(function () {});
    }
    setInterval(refrescar, 5000);
})();
</script>
<?php pie();
