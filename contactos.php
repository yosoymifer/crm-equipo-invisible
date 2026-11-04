<?php
require __DIR__ . '/lib.php';
$u = requiere_login();

// ---------- Agregar contacto a mano
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'agregar') {
    $nombre = trim((string)($_POST['nombre'] ?? ''));
    $tel = normalizar_telefono((string)($_POST['telefono'] ?? ''));
    $correo = trim((string)($_POST['correo'] ?? ''));
    $etapa = in_array($_POST['etapa'] ?? '', ETAPAS, true) ? $_POST['etapa'] : 'Nuevo';
    $origen = in_array($_POST['origen'] ?? '', ORIGENES, true) ? $_POST['origen'] : 'Manual';
    $pa = trim((string)($_POST['proxima_accion'] ?? ''));
    $pf = (string)($_POST['proxima_fecha'] ?? '');
    $pf = preg_match('/^\d{4}-\d{2}-\d{2}$/', $pf) ? $pf : null;
    $notas = trim((string)($_POST['notas'] ?? ''));
    if ($nombre === '' && !$tel) {
        flash('error', 'Escribe al menos el nombre o el teléfono.');
    } elseif ($correo !== '' && !filter_var($correo, FILTER_VALIDATE_EMAIL)) {
        flash('error', 'Ese correo no parece válido.');
    } elseif ($tel && q('SELECT id FROM contactos WHERE telefono = ?', [$tel])->fetchColumn()) {
        flash('error', 'Ya tienes un contacto con ese teléfono.');
    } else {
        crear_contacto(['nombre' => mb_substr($nombre, 0, 100), 'telefono' => $tel, 'correo' => $correo ?: null, 'origen' => $origen,
            'etapa' => $etapa, 'proxima_accion' => $pa ?: null, 'proxima_fecha' => $pf, 'notas' => $notas ?: null]);
        flash('ok', 'Contacto agregado.');
    }
    ir('contactos.php');
}

$buscar = trim((string)($_GET['q'] ?? ''));
$vista = ($_GET['vista'] ?? '') === 'tabla' ? 'tabla' : 'tablero';
$where = '';
$params = [];
if ($buscar !== '') {
    $where = 'WHERE nombre LIKE ? OR telefono LIKE ? OR correo LIKE ? OR notas LIKE ? OR proxima_accion LIKE ?';
    $like = '%' . $buscar . '%';
    $params = [$like, $like, $like, $like, $like];
}
$contactos = q("SELECT * FROM contactos $where ORDER BY COALESCE(ultimo_mensaje, actualizado) DESC LIMIT 1000", $params)->fetchAll();

// ---------- Descargar CSV (se abre en Excel)
if (isset($_GET['csv'])) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="contactos-' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    $cols = ['id', 'nombre', 'telefono', 'correo', 'origen', 'etapa', 'proxima_accion', 'proxima_fecha', 'notas', 'creado', 'ultimo_mensaje'];
    fputcsv($out, $cols, ';', '"', '');
    foreach ($contactos as $c) {
        $fila = [];
        foreach ($cols as $k) {
            $v = (string)($c[$k] ?? '');
            if ($v !== '' && in_array($v[0], ['=', '+', '-', '@'], true)) $v = "'" . $v;
            $fila[] = $v;
        }
        fputcsv($out, $fila, ';', '"', '');
    }
    fclose($out);
    exit;
}

$hoy = date('Y-m-d');
$sin_accion = 0;
foreach ($contactos as $c) {
    if ($c['etapa'] !== 'Perdido' && (empty($c['proxima_accion']) || empty($c['proxima_fecha']))) $sin_accion++;
}

function proxima_html(array $c, string $hoy): string
{
    if ($c['etapa'] === 'Perdido') return '';
    if (empty($c['proxima_accion']) || empty($c['proxima_fecha'])) {
        return '<span class="etiqueta etiqueta-alerta">⚠️ Sin próxima acción con fecha</span>';
    }
    $vencida = $c['proxima_fecha'] < $hoy;
    $f = date('d/m', strtotime($c['proxima_fecha']));
    return '<span class="proxima' . ($vencida ? ' vencida' : '') . '">' . ($vencida ? '⏰ ' : '📅 ') . h($f) . ' · ' . h($c['proxima_accion']) . '</span>';
}

$qs = $buscar !== '' ? '&q=' . rawurlencode($buscar) : '';
cabecera('Contactos', 'contactos');
?>
<div class="titulo-pagina">
    <h1>Contactos <span class="suave">(<?= count($contactos) ?>)</span></h1>
    <div class="acciones">
        <button class="btn" type="button" onclick="document.getElementById('nuevo').hidden=!document.getElementById('nuevo').hidden">+ Agregar contacto</button>
        <a class="btn btn-sec" href="contactos.php?csv=1<?= h($qs) ?>">Descargar CSV</a>
    </div>
</div>

<?php if ($sin_accion): ?>
<div class="aviso aviso-alerta"><b>Regla de oro: ningún cliente sin próxima acción con fecha.</b>
    Tienes <?= $sin_accion ?> contacto<?= $sin_accion === 1 ? '' : 's' ?> sin próxima acción. Ábrelos y decide qué sigue.</div>
<?php endif; ?>

<div id="nuevo" class="tarjeta" hidden>
    <h3>Nuevo contacto</h3>
    <form method="post" class="formulario rejilla-form">
        <?= csrf_campo() ?><input type="hidden" name="accion" value="agregar">
        <label>Nombre<input name="nombre" maxlength="100"></label>
        <label>Teléfono (con código de país)<input name="telefono" placeholder="584141234567" inputmode="tel"></label>
        <label>Correo<input type="email" name="correo"></label>
        <label>Etapa<select name="etapa"><?php foreach (ETAPAS as $e): ?><option><?= h($e) ?></option><?php endforeach; ?></select></label>
        <label>Origen<select name="origen"><?php foreach (ORIGENES as $o): ?><option<?= $o === 'Manual' ? ' selected' : '' ?>><?= h($o) ?></option><?php endforeach; ?></select></label>
        <label>Próxima acción<input name="proxima_accion" placeholder="Ej: Enviarle la cotización"></label>
        <label>Fecha<input type="date" name="proxima_fecha"></label>
        <label class="ancho">Notas<textarea name="notas" rows="2"></textarea></label>
        <div class="ancho"><button class="btn" type="submit">Guardar contacto</button></div>
    </form>
</div>

<form class="buscador" method="get">
    <input type="hidden" name="vista" value="<?= h($vista) ?>">
    <input type="search" name="q" value="<?= h($buscar) ?>" placeholder="Buscar por nombre, teléfono, correo o notas…">
    <button class="btn btn-sec" type="submit">Buscar</button>
    <div class="pestanas">
        <a href="contactos.php?vista=tablero<?= h($qs) ?>" class="<?= $vista === 'tablero' ? 'activo' : '' ?>">Tablero</a>
        <a href="contactos.php?vista=tabla<?= h($qs) ?>" class="<?= $vista === 'tabla' ? 'activo' : '' ?>">Tabla</a>
    </div>
</form>

<?php if (!$contactos): ?>
    <div class="vacio"><div class="vacio-icono">👥</div><p><b><?= $buscar !== '' ? 'No encontré contactos con esa búsqueda.' : 'Todavía no tienes contactos.' ?></b></p>
        <p class="suave">Se crean solos cuando alguien te escribe, o agrégalos a mano.</p></div>
<?php elseif ($vista === 'tablero'): ?>
<div class="tablero">
    <?php foreach (ETAPAS as $e):
        $grupo = array_filter($contactos, fn($c) => $c['etapa'] === $e); ?>
    <div class="columna">
        <div class="columna-cabeza"><b><?= h($e) ?></b><span class="contador-gris"><?= count($grupo) ?></span></div>
        <?php foreach ($grupo as $c): $n = trim((string)$c['nombre']) ?: ($c['telefono'] ?: 'Sin nombre'); ?>
        <a class="ficha" href="conversacion.php?id=<?= (int)$c['id'] ?>">
            <b><?= h($n) ?></b>
            <?php if ((int)$c['necesita_humano']): ?><span class="etiqueta etiqueta-roja">Quiere hablar con una persona</span><?php endif; ?>
            <span class="suave chico"><?= h($c['origen']) ?><?= $c['telefono'] ? ' · ' . h($c['telefono']) : '' ?></span>
            <?= proxima_html($c, $hoy) ?>
        </a>
        <?php endforeach; ?>
    </div>
    <?php endforeach; ?>
</div>
<?php else: ?>
<div class="tarjeta tabla-envoltura">
    <table class="tabla">
        <thead><tr><th>Nombre</th><th>Teléfono</th><th>Correo</th><th>Origen</th><th>Etapa</th><th>Próxima acción</th><th>Último mensaje</th></tr></thead>
        <tbody>
        <?php foreach ($contactos as $c): $n = trim((string)$c['nombre']) ?: 'Sin nombre'; ?>
            <tr onclick="location.href='conversacion.php?id=<?= (int)$c['id'] ?>'">
                <td><a href="conversacion.php?id=<?= (int)$c['id'] ?>"><b><?= h($n) ?></b></a>
                    <?php if ((int)$c['necesita_humano']): ?><br><span class="etiqueta etiqueta-roja">Quiere hablar con una persona</span><?php endif; ?></td>
                <td><?= h($c['telefono']) ?></td>
                <td><?= h($c['correo']) ?></td>
                <td><?= h($c['origen']) ?></td>
                <td><span class="etiqueta etiqueta-etapa"><?= h($c['etapa']) ?></span></td>
                <td><?= proxima_html($c, $hoy) ?></td>
                <td class="suave"><?= h(fecha_corta($c['ultimo_mensaje'])) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>
<?php pie();
