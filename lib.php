<?php
// CRM Equipo Invisible — funciones compartidas.
require_once __DIR__ . '/config.php';

date_default_timezone_set('America/Caracas');
mb_internal_encoding('UTF-8');

const ETAPAS  = ['Nuevo', 'Conversando', 'Interesado', 'Cliente', 'Perdido'];
const ORIGENES = ['WhatsApp', 'Web', 'Formulario', 'Manual'];
const MODELOS_GEMINI = ['gemini-3.6-flash', 'gemini-3.5-flash-lite', 'gemini-3.8-flash'];
const RESPUESTA_FALLA = '¡Gracias por escribir! En un momento te atiende una persona.';

const PLANTILLA_SABE = <<<TXT
NEGOCIO
Nombre del negocio:
A qué nos dedicamos (en 2 o 3 líneas):
Ciudad / zona donde atendemos:

QUÉ VENDEMOS Y PRECIOS
- Producto o servicio 1: precio
- Producto o servicio 2: precio
- ¿Hay delivery o envíos? ¿Cuánto cuesta?

HORARIO Y UBICACIÓN
Horario: lunes a viernes de 8:00 a 5:00, sábados de 9:00 a 1:00
Dirección:
Enlace de Google Maps:

FORMAS DE PAGO
- Pago móvil / transferencia (banco):
- Divisas en efectivo / Zelle / Binance:
- ¿Se puede pagar en cuotas?

PREGUNTAS FRECUENTES
P:
R:

P:
R:

CÓMO HABLAMOS
Cercanos y respetuosos, tuteamos al cliente, usamos pocos emojis.

QUÉ NO DEBE HACER
- No dar descuentos ni inventar promociones.
- No confirmar disponibilidad ni citas: eso lo confirma una persona.
- No hablar de temas que no tengan que ver con el negocio.
TXT;

// ---------------------------------------------------------------- utilidades

function h($v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function ahora(): string
{
    return date('Y-m-d H:i:s');
}

function fallo_fatal(string $titulo, string $detalle, int $codigo = 500): void
{
    http_response_code($codigo);
    echo '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<div style="font-family:system-ui,sans-serif;max-width:560px;margin:60px auto;padding:24px;border:1px solid #f1c0c0;border-radius:12px;background:#fff6f6">'
        . '<h2 style="margin-top:0">⚠️ ' . h($titulo) . '</h2><p>' . h($detalle) . '</p></div>';
    exit;
}

if (!extension_loaded('pdo_sqlite')) {
    fallo_fatal('Falta activar pdo_sqlite en PHP', 'En Laragon: clic derecho en la ventana de Laragon → PHP → Extensions → marca pdo_sqlite. Luego reinicia (Stop / Start All) y recarga esta página.');
}

function es_https(): bool
{
    if (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') return true;
    $proto = strtolower(trim(explode(',', (string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''))[0]));
    if ($proto === 'https') return true;
    if (str_contains((string)($_SERVER['HTTP_CF_VISITOR'] ?? ''), 'https')) return true;
    return false;
}

// Ruta de la carpeta del CRM en la URL, sin barra final. Ej: "/crm" o "".
function base_path(): string
{
    $dir = str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/index.php')));
    $niveles = defined('CRM_NIVEL') ? (int)CRM_NIVEL : 0; // scripts dentro de subcarpetas (api/)
    for ($i = 0; $i < $niveles; $i++) {
        $dir = str_replace('\\', '/', dirname($dir));
    }
    return rtrim($dir, '/');
}

// Dirección completa del CRM calculada desde la petición. Ej: "https://xxxx.trycloudflare.com/crm".
function base_url(): string
{
    $host = (string)($_SERVER['HTTP_X_FORWARDED_HOST'] ?? $_SERVER['HTTP_HOST'] ?? 'localhost');
    $host = preg_replace('/[^A-Za-z0-9.\-:\[\]]/', '', trim(explode(',', $host)[0])) ?: 'localhost';
    return (es_https() ? 'https' : 'http') . '://' . $host . base_path();
}

function registrar(string $texto): void
{
    $dir = CRM_DATOS;
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    $archivo = $dir . '/registro.log';
    if (is_file($archivo) && filesize($archivo) > 1024 * 1024) {
        @rename($archivo, $dir . '/registro.anterior.log');
    }
    $texto = str_replace(["\r", "\n"], ' ', $texto);
    @file_put_contents($archivo, '[' . ahora() . '] ' . $texto . "\n", FILE_APPEND | LOCK_EX);
}

function enmascarar(string $v): string
{
    if ($v === '') return '';
    return '••••••••' . mb_substr($v, -4);
}

function solo_digitos(?string $tel): string
{
    return preg_replace('/\D+/', '', (string)$tel);
}

// Normaliza un teléfono: solo dígitos y, si es venezolano escrito como 0414..., lo pasa a 58414...
function normalizar_telefono(?string $tel): ?string
{
    $d = solo_digitos($tel);
    if ($d === '') return null;
    if (strlen($d) === 11 && $d[0] === '0') $d = '58' . substr($d, 1);
    return $d;
}

function ir(string $url): void
{
    header('Location: ' . $url);
    exit;
}

function json_salida($datos, int $codigo = 200): void
{
    http_response_code($codigo);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($datos, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// ---------------------------------------------------------------- base de datos

function db(): PDO
{
    static $pdo = null;
    if ($pdo) return $pdo;
    $dir = CRM_DATOS;
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    if (!is_dir($dir) || !is_writable($dir)) {
        fallo_fatal('No puedo escribir en la carpeta de datos', 'Revisa que la carpeta "' . $dir . '" exista y que se pueda escribir en ella.');
    }
    try {
        $pdo = new PDO('sqlite:' . $dir . '/crm.sqlite', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_TIMEOUT => 10,
        ]);
        $pdo->exec('PRAGMA busy_timeout = 10000');
        $pdo->exec('PRAGMA journal_mode = WAL');
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS usuarios (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                email TEXT NOT NULL UNIQUE,
                clave_hash TEXT NOT NULL,
                creado TEXT NOT NULL
            );
            CREATE TABLE IF NOT EXISTS contactos (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                nombre TEXT,
                telefono TEXT UNIQUE,
                correo TEXT,
                origen TEXT NOT NULL DEFAULT 'Manual',
                etapa TEXT NOT NULL DEFAULT 'Nuevo',
                notas TEXT,
                proxima_accion TEXT,
                proxima_fecha TEXT,
                ia_activa INTEGER NOT NULL DEFAULT 1,
                necesita_humano INTEGER NOT NULL DEFAULT 0,
                sesion_web TEXT UNIQUE,
                ultimo_mensaje TEXT,
                creado TEXT NOT NULL,
                actualizado TEXT NOT NULL
            );
            CREATE TABLE IF NOT EXISTS mensajes (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                contacto_id INTEGER NOT NULL REFERENCES contactos(id) ON DELETE CASCADE,
                de TEXT NOT NULL,
                texto TEXT NOT NULL,
                canal TEXT NOT NULL,
                wa_id TEXT UNIQUE,
                creado TEXT NOT NULL
            );
            CREATE INDEX IF NOT EXISTS idx_mensajes_contacto ON mensajes(contacto_id, id);
            CREATE INDEX IF NOT EXISTS idx_contactos_ultimo ON contactos(ultimo_mensaje);
            CREATE TABLE IF NOT EXISTS ajustes (
                clave TEXT PRIMARY KEY,
                valor TEXT
            );
        ");
    } catch (PDOException $e) {
        fallo_fatal('No pude abrir la base de datos', $e->getMessage());
    }
    return $pdo;
}

function q(string $sql, array $params = []): PDOStatement
{
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st;
}

function ajustes_todos(bool $recargar = false): array
{
    static $cache = null;
    if ($cache === null || $recargar) {
        $cache = [];
        foreach (q('SELECT clave, valor FROM ajustes')->fetchAll() as $f) {
            $cache[$f['clave']] = (string)$f['valor'];
        }
    }
    return $cache;
}

function ajuste(string $clave, string $def = ''): string
{
    $a = ajustes_todos();
    return array_key_exists($clave, $a) ? $a[$clave] : $def;
}

function guardar_ajuste(string $clave, string $valor): void
{
    q('INSERT OR REPLACE INTO ajustes (clave, valor) VALUES (?, ?)', [$clave, $valor]);
    ajustes_todos(true);
}

function nombre_agente(): string
{
    return ajuste('agente_nombre', 'Sofía') ?: 'Sofía';
}

function ia_general_activa(): bool
{
    return ajuste('ia_activa', '1') === '1';
}

function modelo_gemini(): string
{
    return ajuste('gemini_modelo', MODELOS_GEMINI[0]) ?: MODELOS_GEMINI[0];
}

function version_graph(): string
{
    $v = ajuste('wa_version', 'v23.0');
    return preg_match('/^v\d+\.\d+$/', $v) ? $v : 'v23.0';
}

function token_verificacion(): string
{
    $t = ajuste('wa_verify');
    if ($t === '') {
        $t = 'crm-' . bin2hex(random_bytes(8));
        guardar_ajuste('wa_verify', $t);
    }
    return $t;
}

// ---------------------------------------------------------------- contactos y mensajes

function contacto(int $id): ?array
{
    $c = q('SELECT * FROM contactos WHERE id = ?', [$id])->fetch();
    return $c ?: null;
}

function crear_contacto(array $d): int
{
    $t = ahora();
    q('INSERT INTO contactos (nombre, telefono, correo, origen, etapa, notas, proxima_accion, proxima_fecha, sesion_web, creado, actualizado)
       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [
        $d['nombre'] ?? null,
        $d['telefono'] ?? null,
        $d['correo'] ?? null,
        $d['origen'] ?? 'Manual',
        $d['etapa'] ?? 'Nuevo',
        $d['notas'] ?? null,
        $d['proxima_accion'] ?? null,
        $d['proxima_fecha'] ?? null,
        $d['sesion_web'] ?? null,
        $t, $t,
    ]);
    return (int)db()->lastInsertId();
}

// Canal por el que se le responde a un contacto.
function canal_contacto(array $c): string
{
    if (!empty($c['sesion_web'])) return 'web';
    if (!empty($c['telefono'])) return 'whatsapp';
    return 'web';
}

// Guarda un mensaje. Devuelve su id, o 0 si ya existía (mismo wa_id: Meta reintenta).
function guardar_mensaje(int $contacto_id, string $de, string $texto, string $canal, ?string $wa_id = null): int
{
    $t = ahora();
    $st = q('INSERT OR IGNORE INTO mensajes (contacto_id, de, texto, canal, wa_id, creado) VALUES (?, ?, ?, ?, ?, ?)',
        [$contacto_id, $de, $texto, $canal, $wa_id ?: null, $t]);
    if ($st->rowCount() === 0) return 0;
    $id = (int)db()->lastInsertId();
    q('UPDATE contactos SET ultimo_mensaje = ?, actualizado = ? WHERE id = ?', [$t, $t, $contacto_id]);
    return $id;
}

function marcar_humano(int $contacto_id): void
{
    q('UPDATE contactos SET necesita_humano = 1, actualizado = ? WHERE id = ?', [ahora(), $contacto_id]);
}

function ia_responde_a(array $c): bool
{
    return ia_general_activa() && (int)$c['ia_activa'] === 1;
}

function nombre_desconocido(?string $n): bool
{
    $n = trim((string)$n);
    return $n === '' || $n === 'Visitante web';
}

// ---------------------------------------------------------------- HTTP (curl)

function http_json(string $url, array $cabeceras, ?array $cuerpo, int $timeout = 30): array
{
    if (!function_exists('curl_init')) {
        return ['ok' => false, 'status' => 0, 'json' => null, 'raw' => '', 'error' => 'Falta activar la extensión curl de PHP (Laragon → PHP → Extensions → curl).'];
    }
    $intentar = function (bool $ca_windows) use ($url, $cabeceras, $cuerpo, $timeout) {
        $ch = curl_init($url);
        $op = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HTTPHEADER => array_merge(['Content-Type: application/json'], $cabeceras),
        ];
        if ($cuerpo !== null) {
            $op[CURLOPT_POST] = true;
            $op[CURLOPT_POSTFIELDS] = json_encode($cuerpo, JSON_UNESCAPED_UNICODE);
        }
        if ($ca_windows && defined('CURLSSLOPT_NATIVE_CA')) {
            $op[CURLOPT_SSL_OPTIONS] = CURLSSLOPT_NATIVE_CA;
        }
        curl_setopt_array($ch, $op);
        $raw = curl_exec($ch);
        $errno = curl_errno($ch);
        $err = curl_error($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        return [$raw, $errno, $err, $status];
    };
    [$raw, $errno, $err, $status] = $intentar(false);
    if (in_array($errno, [60, 77], true) && defined('CURLSSLOPT_NATIVE_CA')) {
        // Windows sin certificados configurados en PHP: usamos los del sistema.
        [$raw, $errno, $err, $status] = $intentar(true);
    }
    if ($errno) {
        return ['ok' => false, 'status' => 0, 'json' => null, 'raw' => '', 'error' => 'Sin conexión (' . $errno . '): ' . $err];
    }
    $json = json_decode((string)$raw, true);
    return ['ok' => true, 'status' => $status, 'json' => is_array($json) ? $json : null, 'raw' => (string)$raw, 'error' => ''];
}

// ---------------------------------------------------------------- Gemini

// Llama a Gemini. Devuelve ['ok'=>bool, 'texto'=>string, 'error'=>string].
function gemini_generar(string $instrucciones, array $contents, bool $estructurado = true): array
{
    $llave = trim(ajuste('gemini_llave'));
    if ($llave === '') {
        return ['ok' => false, 'texto' => '', 'error' => 'Falta la llave de Gemini (ve a Ajustes).'];
    }
    if ($llave === 'prueba') {
        $ultimo = '';
        foreach ($contents as $c) if ($c['role'] === 'user') $ultimo = $c['parts'][0]['text'];
        $humano = (bool)preg_match('/persona|humano|asesor/i', $ultimo);
        $resp = $estructurado
            ? json_encode([
                'respuesta' => '¡Hola! Soy ' . nombre_agente() . ' (respuesta simulada). Me escribiste: «' . mb_substr($ultimo, 0, 80) . '»',
                'pasar_a_humano' => $humano,
                'interes' => $humano ? '' : 'Prueba del agente',
            ], JSON_UNESCAPED_UNICODE)
            : 'OK';
        return ['ok' => true, 'texto' => $resp, 'error' => ''];
    }
    $modelo = modelo_gemini();
    $cuerpo = [
        'contents' => $contents,
    ];
    if ($instrucciones !== '') {
        $cuerpo['systemInstruction'] = ['parts' => [['text' => $instrucciones]]];
    }
    if ($estructurado) {
        $cuerpo['generationConfig'] = [
            'responseMimeType' => 'application/json',
            'responseSchema' => [
                'type' => 'OBJECT',
                'properties' => [
                    'respuesta' => ['type' => 'STRING'],
                    'nombre' => ['type' => 'STRING'],
                    'correo' => ['type' => 'STRING'],
                    'interes' => ['type' => 'STRING'],
                    'pasar_a_humano' => ['type' => 'BOOLEAN'],
                ],
                'required' => ['respuesta', 'pasar_a_humano'],
            ],
        ];
    }
    $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode($modelo) . ':generateContent';
    $r = http_json($url, ['x-goog-api-key: ' . $llave], $cuerpo, 30);
    if (!$r['ok']) return ['ok' => false, 'texto' => '', 'error' => $r['error']];
    $j = $r['json'];
    if ($r['status'] < 200 || $r['status'] >= 300) {
        $msg = $j['error']['message'] ?? mb_substr($r['raw'], 0, 300);
        $est = $j['error']['status'] ?? '';
        return ['ok' => false, 'texto' => '', 'error' => 'Google respondió (HTTP ' . $r['status'] . ($est ? ' ' . $est : '') . '): ' . $msg];
    }
    $texto = '';
    foreach (($j['candidates'][0]['content']['parts'] ?? []) as $p) {
        if (!empty($p['thought'])) continue;
        $texto .= (string)($p['text'] ?? '');
    }
    if (trim($texto) === '') {
        $motivo = $j['promptFeedback']['blockReason'] ?? ($j['candidates'][0]['finishReason'] ?? 'respuesta vacía');
        return ['ok' => false, 'texto' => '', 'error' => 'Gemini no devolvió texto (' . $motivo . ').'];
    }
    return ['ok' => true, 'texto' => $texto, 'error' => ''];
}

function gemini_probar(): array
{
    return gemini_generar('', [['role' => 'user', 'parts' => [['text' => 'Responde solo: OK']]]], false);
}

// Genera la respuesta de la IA para un contacto y aplica los efectos (datos, etapa, pasar a humano).
// Devuelve el texto que se le envía al cliente.
function ia_responder(int $contacto_id): string
{
    $c = contacto($contacto_id);
    if (!$c) return RESPUESTA_FALLA;
    $canal = canal_contacto($c);
    $agente = nombre_agente();
    $sabe = trim(ajuste('agente_sabe', PLANTILLA_SABE));

    $datos = [];
    $datos[] = 'Nombre: ' . (nombre_desconocido($c['nombre']) ? '(aún no lo sabes)' : $c['nombre']);
    if (!empty($c['correo'])) $datos[] = 'Correo: ' . $c['correo'];

    $instr = "Eres {$agente}, atiendes a los clientes de este negocio por " . ($canal === 'whatsapp' ? 'WhatsApp' : 'la web') . ".\n"
        . "Reglas:\n"
        . "- Respuestas cortas, estilo WhatsApp (máximo 3 o 4 líneas), en español, con tono cálido y cercano.\n"
        . "- Usa SOLO la información del negocio que aparece abajo. No inventes precios, datos, disponibilidad ni promesas.\n"
        . "- Si no sabes algo, dilo con honestidad y ofrece pasar con una persona del equipo.\n"
        . "- Si todavía no sabes el nombre del cliente, pídeselo de forma natural (sin presionar).\n"
        . "- Si el cliente te da su nombre o correo, ponlos en los campos 'nombre' y 'correo'. En 'interes' resume en pocas palabras qué le interesa.\n"
        . "- 'pasar_a_humano' = true si pide hablar con una persona, quiere comprar, pagar o agendar, o está molesto. En ese caso dile en la respuesta que en breve lo atiende una persona del equipo.\n"
        . "- Nunca menciones que eres un modelo de Google ni hables de cómo funcionas por dentro.\n\n"
        . "Datos que ya tienes del cliente:\n" . implode("\n", $datos) . "\n\n"
        . "=== LO QUE SABES DEL NEGOCIO ===\n" . $sabe;

    $hist = q('SELECT de, texto FROM (SELECT id, de, texto FROM mensajes WHERE contacto_id = ? ORDER BY id DESC LIMIT 20) ORDER BY id ASC', [$contacto_id])->fetchAll();
    $contents = [];
    foreach ($hist as $m) {
        $rol = $m['de'] === 'cliente' ? 'user' : 'model';
        if (!$contents && $rol !== 'user') continue; // el primero debe ser del cliente
        $n = count($contents);
        if ($n && $contents[$n - 1]['role'] === $rol) {
            $contents[$n - 1]['parts'][0]['text'] .= "\n" . $m['texto'];
        } else {
            $contents[] = ['role' => $rol, 'parts' => [['text' => (string)$m['texto']]]];
        }
    }
    if (!$contents) return RESPUESTA_FALLA;

    $r = gemini_generar($instr, $contents, true);
    if (!$r['ok']) {
        registrar('Gemini falló (contacto #' . $contacto_id . '): ' . $r['error']);
        marcar_humano($contacto_id);
        return RESPUESTA_FALLA;
    }

    $d = json_decode(trim($r['texto']), true);
    if (!is_array($d) || !isset($d['respuesta'])) {
        $d = ['respuesta' => trim($r['texto']), 'pasar_a_humano' => false];
    }
    $respuesta = trim((string)$d['respuesta']);
    if ($respuesta === '') $respuesta = '¿Me lo repites, por favor? 🙏';
    $humano = !empty($d['pasar_a_humano']);

    // Efectos sobre el contacto
    $c = contacto($contacto_id);
    $cambios = [];
    $nombre = trim((string)($d['nombre'] ?? ''));
    if ($nombre !== '' && nombre_desconocido($c['nombre'])) $cambios['nombre'] = mb_substr($nombre, 0, 100);
    $correo = trim((string)($d['correo'] ?? ''));
    if ($correo !== '' && empty($c['correo']) && filter_var($correo, FILTER_VALIDATE_EMAIL)) $cambios['correo'] = $correo;
    $interes = trim((string)($d['interes'] ?? ''));
    if ($interes !== '' && trim((string)$c['notas']) === '') $cambios['notas'] = 'Le interesa: ' . mb_substr($interes, 0, 300);
    $etapa = $c['etapa'];
    if ($etapa === 'Nuevo') $etapa = 'Conversando';
    if ($humano) {
        $cambios['ia_activa'] = 0;
        $cambios['necesita_humano'] = 1;
        if (in_array($etapa, ['Nuevo', 'Conversando'], true)) $etapa = 'Interesado';
        if (stripos($respuesta, 'persona') === false) {
            $respuesta .= "\n\nEn breve te atiende una persona del equipo 🙌";
        }
    }
    if ($etapa !== $c['etapa']) $cambios['etapa'] = $etapa;
    if ($cambios) {
        $cambios['actualizado'] = ahora();
        $sets = implode(', ', array_map(fn($k) => "$k = ?", array_keys($cambios)));
        q("UPDATE contactos SET $sets WHERE id = ?", array_merge(array_values($cambios), [$contacto_id]));
    }
    return $respuesta;
}

// ---------------------------------------------------------------- WhatsApp (Meta)

function wa_numero(string $telefono): string
{
    $d = solo_digitos($telefono);
    // México: Meta entrega 521XXXXXXXXXX pero hay que enviar a 52XXXXXXXXXX.
    if (strlen($d) === 13 && str_starts_with($d, '521')) $d = '52' . substr($d, 3);
    return $d;
}

// Devuelve ['ok'=>bool, 'wa_id'=>?string, 'error'=>string, 'respuesta'=>string]
function wa_llamar(string $telefono, array $mensaje): array
{
    $token = trim(ajuste('wa_token'));
    $pid = solo_digitos(ajuste('wa_phone_id'));
    if ($token === '' || $pid === '') {
        $e = 'Falta configurar WhatsApp (token de acceso y Phone number ID) en Ajustes.';
        registrar('WhatsApp: ' . $e);
        return ['ok' => false, 'wa_id' => null, 'error' => $e, 'respuesta' => ''];
    }
    $to = wa_numero($telefono);
    if ($to === '') return ['ok' => false, 'wa_id' => null, 'error' => 'El número está vacío.', 'respuesta' => ''];
    $cuerpo = array_merge(['messaging_product' => 'whatsapp', 'to' => $to], $mensaje);
    $url = 'https://graph.facebook.com/' . version_graph() . '/' . $pid . '/messages';
    $r = http_json($url, ['Authorization: Bearer ' . $token], $cuerpo, 20);
    if (!$r['ok']) {
        registrar('WhatsApp: no pude conectar con Meta: ' . $r['error']);
        return ['ok' => false, 'wa_id' => null, 'error' => $r['error'], 'respuesta' => ''];
    }
    $j = $r['json'] ?? [];
    if ($r['status'] >= 200 && $r['status'] < 300 && !empty($j['messages'][0]['id'])) {
        return ['ok' => true, 'wa_id' => (string)$j['messages'][0]['id'], 'error' => '', 'respuesta' => $r['raw']];
    }
    $cod = (int)($j['error']['code'] ?? 0);
    $msg = (string)($j['error']['message'] ?? mb_substr($r['raw'], 0, 300));
    $det = (string)($j['error']['error_data']['details'] ?? '');
    $error = 'Meta respondió (HTTP ' . $r['status'] . ', código ' . $cod . '): ' . $msg . ($det ? ' — ' . $det : '');
    if ($cod === 190) $error .= ' 👉 Tu token de Meta venció o está mal copiado (el temporal dura 24 h). Genera otro en API Setup y pégalo en Ajustes.';
    if ($cod === 131030) $error .= ' 👉 Agrega este número como destinatario en tu app de Meta (API Setup → To).';
    if ($cod === 131047) $error .= ' 👉 Pasaron más de 24 h desde el último mensaje del cliente: WhatsApp solo deja escribirle con una plantilla aprobada.';
    registrar('WhatsApp: error al enviar a ' . $to . ': ' . $error);
    return ['ok' => false, 'wa_id' => null, 'error' => $error, 'respuesta' => $r['raw']];
}

// Comprueba token + Phone number ID y, si hay WhatsApp Business Account ID, suscribe la app a esa cuenta
// (sin esa suscripción, con números reales los mensajes no llegan al webhook).
// Devuelve una lista de [ok(bool), texto].
function wa_verificar(): array
{
    $token = trim(ajuste('wa_token'));
    $pid = solo_digitos(ajuste('wa_phone_id'));
    $waba = solo_digitos(ajuste('wa_waba_id'));
    $base = 'https://graph.facebook.com/' . version_graph() . '/';
    $aut = ['Authorization: Bearer ' . $token];
    $res = [];
    if ($token === '' || $pid === '') return [[false, 'Primero guarda el token de acceso y el Phone number ID.']];

    $r = http_json($base . $pid . '?fields=display_phone_number,verified_name', $aut, null, 20);
    $j = $r['json'] ?? [];
    if ($r['ok'] && $r['status'] === 200 && !empty($j['display_phone_number'])) {
        $res[] = [true, 'Token y Phone number ID correctos. Número: ' . $j['display_phone_number'] . ' (' . ($j['verified_name'] ?? '') . ').'];
    } else {
        $cod = (int)($j['error']['code'] ?? 0);
        $msg = $r['ok'] ? (string)($j['error']['message'] ?? $r['raw']) : $r['error'];
        $pista = $cod === 190 ? ' 👉 El token venció o está mal copiado.' : ($cod === 100 ? ' 👉 Revisa el Phone number ID: es el número largo de "Phone number ID", no tu teléfono.' : '');
        $res[] = [false, 'Meta no aceptó tus datos: ' . $msg . $pista];
        return $res;
    }

    if ($waba === '') {
        $res[] = [false, 'Falta el WhatsApp Business Account ID: pégalo y guarda para conectar tu app a tu cuenta.'];
        return $res;
    }
    // POST sin cuerpo a /{waba}/subscribed_apps
    $ch = curl_init($base . $waba . '/subscribed_apps');
    $op = [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => '', CURLOPT_TIMEOUT => 20, CURLOPT_HTTPHEADER => $aut];
    if (defined('CURLSSLOPT_NATIVE_CA')) $op[CURLOPT_SSL_OPTIONS] = CURLSSLOPT_NATIVE_CA;
    curl_setopt_array($ch, $op);
    $raw = (string)curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);
    $j = json_decode($raw, true) ?: [];
    if (!empty($j['success'])) {
        $res[] = [true, 'Tu app quedó conectada a tu cuenta de WhatsApp Business. Los mensajes van a llegar a tu webhook.'];
    } else {
        $msg = $err ?: (string)($j['error']['message'] ?? $raw);
        $res[] = [false, 'No pude conectar tu app con la cuenta de WhatsApp Business: ' . $msg . ' 👉 Revisa el WhatsApp Business Account ID (está en API Setup, debajo del Phone number ID).'];
    }
    registrar('WhatsApp: verificación → ' . implode(' | ', array_map(fn($x) => ($x[0] ? 'OK ' : 'ERROR ') . $x[1], $res)));
    return $res;
}

function wa_enviar(string $telefono, string $texto): array
{
    return wa_llamar($telefono, ['type' => 'text', 'text' => ['preview_url' => false, 'body' => $texto]]);
}

function wa_plantilla_hola(string $telefono): array
{
    return wa_llamar($telefono, ['type' => 'template', 'template' => ['name' => 'hello_world', 'language' => ['code' => 'en_US']]]);
}

// ---------------------------------------------------------------- chat web (widget y prueba)

function sesion_valida(string $s): bool
{
    return (bool)preg_match('/^[A-Za-z0-9]{8,64}$/', $s);
}

function mensaje_publico(array $m): array
{
    return ['id' => (int)$m['id'], 'de' => $m['de'], 'texto' => $m['texto'], 'hora' => substr((string)$m['creado'], 11, 5)];
}

function mensajes_web(int $contacto_id, int $desde): array
{
    $f = q('SELECT id, de, texto, creado FROM mensajes WHERE contacto_id = ? AND id > ? ORDER BY id ASC LIMIT 200', [$contacto_id, $desde])->fetchAll();
    return array_map('mensaje_publico', $f);
}

// Procesa un mensaje que llega por la web. Devuelve ['codigo'=>int, 'datos'=>array]
function chat_web_procesar(string $sesion, string $texto, bool $prueba = false): array
{
    $texto = trim(str_replace("\0", '', $texto));
    if (!sesion_valida($sesion)) return ['codigo' => 400, 'datos' => ['error' => 'Sesión no válida.']];
    if ($texto === '') return ['codigo' => 400, 'datos' => ['error' => 'Escribe un mensaje.']];
    $texto = mb_substr($texto, 0, 1000);

    $c = q('SELECT * FROM contactos WHERE sesion_web = ?', [$sesion])->fetch();
    if (!$c) {
        $id = crear_contacto(['nombre' => 'Visitante web', 'origen' => 'Web', 'sesion_web' => $sesion, 'notas' => $prueba ? 'Prueba' : null]);
        $c = contacto($id);
    }
    $cid = (int)$c['id'];
    $hace_un_minuto = date('Y-m-d H:i:s', time() - 60);
    $recientes = (int)q("SELECT COUNT(*) FROM mensajes WHERE contacto_id = ? AND de = 'cliente' AND creado >= ?", [$cid, $hace_un_minuto])->fetchColumn();
    if ($recientes >= 12) {
        return ['codigo' => 429, 'datos' => ['error' => 'Vas muy rápido 😅 Espera un minuto y vuelve a escribir.']];
    }
    $mid = guardar_mensaje($cid, 'cliente', $texto, 'web');
    $humano = false;
    if (ia_responde_a($c)) {
        $resp = ia_responder($cid);
        guardar_mensaje($cid, 'ia', $resp, 'web');
        $c2 = contacto($cid);
        $humano = (int)$c2['ia_activa'] === 0 || (int)$c2['necesita_humano'] === 1;
    } else {
        marcar_humano($cid);
        $humano = true;
    }
    $mensajes = mensajes_web($cid, $mid - 1);
    $ultimo = $mensajes ? end($mensajes)['id'] : $mid;
    return ['codigo' => 200, 'datos' => ['mensajes' => $mensajes, 'ultimo_id' => $ultimo, 'humano' => $humano]];
}

// ---------------------------------------------------------------- sesión, login y CSRF

function iniciar_sesion(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) return;
    $dir = CRM_DATOS . '/sesiones';
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    if (is_dir($dir) && is_writable($dir)) session_save_path($dir);
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.gc_maxlifetime', '2592000');
    session_name('crm_ei');
    session_set_cookie_params([
        'lifetime' => 2592000,
        'path' => base_path() . '/',
        'secure' => es_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

function csrf_token(): string
{
    iniciar_sesion();
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf'];
}

function csrf_campo(): string
{
    return '<input type="hidden" name="csrf" value="' . h(csrf_token()) . '">';
}

function verificar_csrf(): void
{
    $t = (string)($_POST['csrf'] ?? $_SERVER['HTTP_X_CSRF'] ?? '');
    if (!hash_equals(csrf_token(), $t)) {
        if (es_ajax()) json_salida(['error' => 'La página expiró. Recárgala.'], 400);
        http_response_code(400);
        fallo_fatal('La página expiró', 'Vuelve atrás, recarga la página (F5) e inténtalo de nuevo.', 400);
    }
}

function es_ajax(): bool
{
    return isset($_GET['parcial']) || ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'fetch';
}

function hay_usuarios(): bool
{
    return (int)q('SELECT COUNT(*) FROM usuarios')->fetchColumn() > 0;
}

function usuario_actual(): ?array
{
    iniciar_sesion();
    if (empty($_SESSION['usuario_id'])) return null;
    $u = q('SELECT id, email FROM usuarios WHERE id = ?', [(int)$_SESSION['usuario_id']])->fetch();
    return $u ?: null;
}

function requiere_login(): array
{
    $u = usuario_actual();
    if (!$u) {
        if (es_ajax()) json_salida(['error' => 'Tu sesión terminó. Vuelve a entrar.'], 401);
        ir(hay_usuarios() ? 'entrar.php' : 'index.php');
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST') verificar_csrf();
    return $u;
}

function entrar_como(int $usuario_id): void
{
    iniciar_sesion();
    session_regenerate_id(true);
    $_SESSION['usuario_id'] = $usuario_id;
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

function flash(string $tipo, string $texto, string $detalle = ''): void
{
    iniciar_sesion();
    $_SESSION['flash'][] = ['tipo' => $tipo, 'texto' => $texto, 'detalle' => $detalle];
}

function mostrar_flash(): string
{
    iniciar_sesion();
    $html = '';
    foreach ($_SESSION['flash'] ?? [] as $f) {
        $html .= '<div class="aviso aviso-' . h($f['tipo']) . '">' . h($f['texto'])
            . ($f['detalle'] !== '' ? '<pre>' . h($f['detalle']) . '</pre>' : '') . '</div>';
    }
    unset($_SESSION['flash']);
    return $html;
}

// ---------------------------------------------------------------- diseño

function cabecera(string $titulo, string $activo = '', bool $menu = true): void
{
    header('Content-Type: text/html; charset=utf-8');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: same-origin');
    $pendientes = 0;
    if ($menu) $pendientes = (int)q('SELECT COUNT(*) FROM contactos WHERE necesita_humano = 1')->fetchColumn();
    $items = [
        'conversaciones' => ['index.php', 'Conversaciones'],
        'contactos' => ['contactos.php', 'Contactos'],
        'agente' => ['agente.php', 'Mi agente'],
        'ajustes' => ['ajustes.php', 'Ajustes'],
    ];
    echo '<!doctype html><html lang="es"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>' . h($titulo) . ' · CRM Equipo Invisible</title>'
        . '<link rel="icon" href="data:image/svg+xml,%3Csvg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 100 100%22%3E%3Ctext y=%22.9em%22 font-size=%2290%22%3E%F0%9F%92%AC%3C/text%3E%3C/svg%3E">'
        . '<link rel="stylesheet" href="assets/estilo.css?v=1">'
        . '</head><body' . ($menu ? '' : ' class="pantalla-simple"') . '>';
    if ($menu) {
        echo '<header class="barra"><div class="barra-in">'
            . '<a class="marca" href="index.php"><span class="marca-logo">✦</span><span>CRM <b>Equipo Invisible</b></span></a>'
            . '<nav class="menu">';
        foreach ($items as $k => [$url, $txt]) {
            echo '<a href="' . $url . '"' . ($k === $activo ? ' class="activo"' : '') . '>' . h($txt)
                . ($k === 'conversaciones' && $pendientes ? ' <span class="contador">' . $pendientes . '</span>' : '') . '</a>';
        }
        echo '<a href="salir.php" class="salir">Salir</a></nav></div></header>';
    }
    echo '<main class="contenedor">' . mostrar_flash();
}

function pie(): void
{
    echo '</main></body></html>';
}

function etiqueta_canal(string $canal): string
{
    return $canal === 'whatsapp'
        ? '<span class="canal canal-wa">WhatsApp</span>'
        : '<span class="canal canal-web">Web</span>';
}

function fecha_corta(?string $f): string
{
    if (!$f) return '';
    $ts = strtotime($f);
    if (!$ts) return '';
    if (date('Y-m-d', $ts) === date('Y-m-d')) return date('g:i a', $ts);
    if (date('Y-m-d', $ts) === date('Y-m-d', strtotime('-1 day'))) return 'ayer';
    return date('d/m/Y', $ts);
}
