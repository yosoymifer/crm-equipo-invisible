<?php
require __DIR__ . '/lib.php';

iniciar_sesion();
$_SESSION = [];
$p = session_get_cookie_params();
setcookie(session_name(), '', [
    'expires' => time() - 3600, 'path' => $p['path'], 'secure' => $p['secure'],
    'httponly' => true, 'samesite' => 'Lax',
]);
session_destroy();
ir('entrar.php');
