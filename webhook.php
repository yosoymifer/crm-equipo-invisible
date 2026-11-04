<?php
// Webhook de WhatsApp (Meta). Es público: aquí no hay login.
require __DIR__ . '/lib.php';

// ---------- Verificación (Meta llama con GET al tocar "Verify and save")
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    header('Content-Type: text/plain; charset=utf-8');
    // PHP convierte "hub.mode" en "hub_mode"
    $modo = (string)($_GET['hub_mode'] ?? '');
    $token = (string)($_GET['hub_verify_token'] ?? '');
    $reto = (string)($_GET['hub_challenge'] ?? '');
    $esperado = ajuste('wa_verify');
    if ($modo === 'subscribe' && $esperado !== '' && hash_equals($esperado, $token)) {
        registrar('Webhook: Meta verificó la dirección ✔');
        echo $reto;
        exit;
    }
    if ($modo === '') {
        registrar('Webhook: alguien abrió la dirección sin datos de Meta (normal si la abriste en el navegador).');
    } else {
        registrar('Webhook: verificación rechazada — el token de verificación no coincide con el de Ajustes.');
    }
    http_response_code(403);
    echo 'Token de verificación incorrecto. Esta dirección es para Meta.';
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit;
}

// ---------- Mensajes entrantes (POST)
$raw = (string)file_get_contents('php://input');
$datos = json_decode($raw, true);

// Respondemos 200 de inmediato para que Meta no reintente mientras la IA piensa.
ignore_user_abort(true);
@set_time_limit(120);
http_response_code(200);
header('Content-Type: text/plain; charset=utf-8');
header('Connection: close');
header('Content-Length: 2');
echo 'OK';
while (ob_get_level() > 0) @ob_end_flush();
flush();
if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();

if (!is_array($datos)) {
    registrar('Webhook: llegó un POST que no es JSON (' . strlen($raw) . ' bytes).');
    exit;
}

$n_msj = 0;
$n_est = 0;
foreach (($datos['entry'] ?? []) as $entry) {
    foreach (($entry['changes'] ?? []) as $change) {
        $v = $change['value'] ?? [];
        $n_est += count($v['statuses'] ?? []);
        $n_msj += count($v['messages'] ?? []);
    }
}
registrar('Webhook: llegó un aviso de Meta (' . $n_msj . ' mensaje(s), ' . $n_est . ' estado(s)).');

foreach (($datos['entry'] ?? []) as $entry) {
    foreach (($entry['changes'] ?? []) as $change) {
        $v = $change['value'] ?? [];
        if (empty($v['messages']) || !is_array($v['messages'])) continue; // estados de entrega, etc.
        $perfil = [];
        foreach (($v['contacts'] ?? []) as $ct) {
            if (!empty($ct['wa_id'])) $perfil[solo_digitos((string)$ct['wa_id'])] = (string)($ct['profile']['name'] ?? '');
        }
        $nombre_defecto = (string)($v['contacts'][0]['profile']['name'] ?? '');

        foreach ($v['messages'] as $m) {
            try {
                procesar_mensaje($m, $perfil, $nombre_defecto);
            } catch (Throwable $e) {
                registrar('Webhook: error procesando un mensaje: ' . $e->getMessage());
            }
        }
    }
}
exit;

function procesar_mensaje(array $m, array $perfil, string $nombre_defecto): void
{
    $from = solo_digitos((string)($m['from'] ?? ''));
    $wamid = (string)($m['id'] ?? '');
    $tipo = (string)($m['type'] ?? '');
    if ($from === '' || $wamid === '') return;

    $texto = null;
    if ($tipo === 'text') $texto = (string)($m['text']['body'] ?? '');
    elseif ($tipo === 'button') $texto = (string)($m['button']['text'] ?? '');
    elseif ($tipo === 'interactive') $texto = (string)($m['interactive']['button_reply']['title'] ?? $m['interactive']['list_reply']['title'] ?? '');
    $es_texto = $texto !== null && trim($texto) !== '';
    $guardar = $es_texto ? $texto : '[El cliente envió: ' . ($tipo ?: 'algo') . ']';

    $c = q('SELECT * FROM contactos WHERE telefono = ?', [$from])->fetch();
    if (!$c) {
        $nombre = $perfil[$from] ?? $nombre_defecto;
        $cid = crear_contacto(['nombre' => $nombre !== '' ? mb_substr($nombre, 0, 100) : null, 'telefono' => $from, 'origen' => 'WhatsApp']);
        $c = contacto($cid);
        registrar('Webhook: nuevo contacto de WhatsApp ' . $from . ($nombre !== '' ? ' (' . $nombre . ')' : '') . '.');
    }
    $cid = (int)$c['id'];

    $mid = guardar_mensaje($cid, 'cliente', $guardar, 'whatsapp', $wamid);
    if ($mid === 0) {
        registrar('Webhook: mensaje repetido de ' . $from . ' (Meta reintentó), lo ignoro.');
        return;
    }
    registrar('Webhook: mensaje de ' . $from . ': «' . mb_substr($guardar, 0, 80) . '»');

    if (!ia_responde_a($c)) {
        marcar_humano($cid);
        registrar('Webhook: la IA está apagada para este contacto; lo responde una persona.');
        return;
    }

    $resp = $es_texto ? ia_responder($cid) : 'Por ahora solo puedo leer mensajes de texto 🙏';
    $r = wa_enviar($from, $resp);
    guardar_mensaje($cid, 'ia', $resp, 'whatsapp', $r['wa_id'] ?? null);
    if ($r['ok']) {
        registrar('Webhook: respuesta enviada a ' . $from . '.');
    } else {
        marcar_humano($cid);
    }
}
