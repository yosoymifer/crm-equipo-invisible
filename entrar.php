<?php
require __DIR__ . '/lib.php';

if (!hay_usuarios()) ir('index.php');
if (usuario_actual()) ir('index.php');

$error = '';
$email = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verificar_csrf();
    $email = strtolower(trim((string)($_POST['email'] ?? '')));
    $clave = (string)($_POST['clave'] ?? '');
    $u = q('SELECT id, clave_hash FROM usuarios WHERE email = ?', [$email])->fetch();
    if ($u && password_verify($clave, $u['clave_hash'])) {
        entrar_como((int)$u['id']);
        ir('index.php');
    }
    usleep(500000);
    $error = 'Correo o contraseña incorrectos.';
}

cabecera('Entrar', '', false);
?>
<div class="caja-acceso">
    <div class="marca marca-grande"><span class="marca-logo">✦</span><span>CRM <b>Equipo Invisible</b></span></div>
    <h1>Entra a tu CRM</h1>
    <?php if ($error): ?><div class="aviso aviso-error"><?= h($error) ?></div><?php endif; ?>
    <form method="post" class="formulario">
        <?= csrf_campo() ?>
        <label>Correo<input type="email" name="email" value="<?= h($email) ?>" required autofocus autocomplete="username"></label>
        <label>Contraseña<input type="password" name="clave" required autocomplete="current-password"></label>
        <button class="btn btn-grande" type="submit">Entrar</button>
    </form>
</div>
<?php pie();
