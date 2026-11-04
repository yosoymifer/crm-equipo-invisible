<?php
// API pública del chat de tu web (la usa widget.js). Aquí no hay login.
const CRM_NIVEL = 1; // este archivo vive en una subcarpeta (api/)
require __DIR__ . '/../lib.php';

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Access-Control-Max-Age: 86400');

$metodo = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($metodo === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$saludo = ajuste('agente_saludo', '¡Hola! 👋 ¿En qué te puedo ayudar?');

if ($metodo === 'GET') {
    $sesion = (string)($_GET['sesion'] ?? '');
    $desde = max(0, (int)($_GET['desde'] ?? 0));
    $base = ['saludo' => $saludo, 'agente' => nombre_agente(), 'mensajes' => []];
    if ($sesion === '') json_salida($base);
    if (!sesion_valida($sesion)) json_salida(['error' => 'Sesión no válida.'], 400);
    $cid = q('SELECT id FROM contactos WHERE sesion_web = ?', [$sesion])->fetchColumn();
    if ($cid) $base['mensajes'] = mensajes_web((int)$cid, $desde);
    json_salida($base);
}

if ($metodo === 'POST') {
    $raw = (string)file_get_contents('php://input', false, null, 0, 20000);
    $d = json_decode($raw, true, 8, JSON_INVALID_UTF8_SUBSTITUTE);
    if (!is_array($d)) $d = $_POST;
    $r = chat_web_procesar((string)($d['sesion'] ?? ''), (string)($d['texto'] ?? ''));
    json_salida($r['datos'], $r['codigo']);
}

json_salida(['error' => 'Método no permitido.'], 405);
