<?php
// Solo para Mac/Linux sin Apache: php -S localhost:8000 router.php
// El servidor de PHP ignora .htaccess, así que aquí se bloquean la carpeta datos/ y los archivos ocultos (.git, .env).

// Sin parse_url: con "//datos/..." lo tomaría como nombre de servidor y no como carpeta.
$ruta = explode('?', (string)($_SERVER['REQUEST_URI'] ?? '/'), 2)[0];
$ruta = str_replace(chr(92), '/', rawurldecode($ruta));          // chr(92) = barra invertida
$ruta = '/' . ltrim(preg_replace('#/+#', '/', $ruta), '/');

$base = realpath(__DIR__);
$datos = realpath(__DIR__ . '/datos');
$archivo = realpath(__DIR__ . $ruta);

$prohibido = preg_match('#(^|/)\.#', $ruta) || preg_match('#^/datos(/|$)#i', $ruta);
if ($archivo !== false) {
    $rel = str_replace(chr(92), '/', substr($archivo, strlen($base)));
    if (($datos && stripos($archivo, $datos) === 0) || preg_match('#(^|/)\.#', $rel)) $prohibido = true;
}
if ($prohibido) {
    http_response_code(403);
    exit('Prohibido');
}
return false;
