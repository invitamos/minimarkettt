<?php
/* =========================================================
   Minimarket · Servidor de sincronización
   Suba este archivo a la carpeta del subdominio api-minimarketspycomers en su hosting (PHP 7 o superior).
   Los datos se guardan en la carpeta "datos" (se crea sola y queda protegida).
   ========================================================= */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') { http_response_code(204); exit; }

$dir = __DIR__ . '/datos';
if (!is_dir($dir)) { @mkdir($dir, 0755, true); }
if (!file_exists("$dir/.htaccess")) { @file_put_contents("$dir/.htaccess", "Require all denied\nDeny from all\n"); @file_put_contents("$dir/index.html", ''); }
if (!is_writable($dir)) { echo json_encode(['ok' => false, 'error' => 'La carpeta "datos" no tiene permiso de escritura en el hosting.']); exit; }

$dbFile = "$dir/db.json";
$sesFile = "$dir/sesiones.json";

function leer($f, $def) { if (!file_exists($f)) return $def; $j = json_decode(file_get_contents($f), true); return $j === null ? $def : $j; }
function escribir($f, $data) { $tmp = $f . '.' . bin2hex(random_bytes(4)) . '.tmp'; file_put_contents($tmp, json_encode($data, JSON_UNESCAPED_UNICODE)); rename($tmp, $f); }

$in = json_decode(file_get_contents('php://input'), true);
if (!is_array($in)) $in = [];
$accion = $in['action'] ?? '';

$lock = fopen("$dir/lock", 'c');
flock($lock, LOCK_EX);
function salir($r) { global $lock; flock($lock, LOCK_UN); echo json_encode($r, JSON_UNESCAPED_UNICODE); exit; }

$db = leer($dbFile, null);
$ses = leer($sesFile, []);
$ahora = time();
foreach ($ses as $k => $s) { if (($s['exp'] ?? 0) < $ahora) unset($ses[$k]); }

function nuevoToken($uid) { global $ses, $sesFile; $t = bin2hex(random_bytes(24)); $ses[$t] = ['uid' => $uid, 'exp' => time() + 60 * 60 * 24 * 30]; escribir($sesFile, $ses); return $t; }
function buscarUsuario($data, $usuario) { foreach (($data['usuarios'] ?? []) as $u) { if (strtolower($u['usuario'] ?? '') === strtolower((string)$usuario)) return $u; } return null; }
function claveOk($u, $hash) { return $u && hash_equals((string)($u['pass'] ?? ''), (string)$hash); }
function datosValidos($d) { return is_array($d) && !empty($d['usuarios']) && is_array($d['usuarios']) && isset($d['productos']); }
function validar() {
  global $in, $ses, $db;
  $t = $in['token'] ?? '';
  if (!$t || !isset($ses[$t]) || !$db) salir(['ok' => false, 'auth' => true]);
  foreach ($db['data']['usuarios'] as $u) { if (($u['id'] ?? '') === $ses[$t]['uid'] && !empty($u['activo'])) return $u; }
  salir(['ok' => false, 'auth' => true]);
}
function respaldoDiario() {
  global $dir, $dbFile;
  if (!file_exists($dbFile)) return;
  $hoy = "$dir/respaldo-" . date('Y-m-d') . ".json";
  if (!file_exists($hoy)) { @copy($dbFile, $hoy); $viejos = glob("$dir/respaldo-*.json"); sort($viejos); while (count($viejos) > 30) { @unlink(array_shift($viejos)); } }
}

switch ($accion) {
  case 'ping':
    salir(['ok' => true, 'empty' => !$db]);
  case 'login':
    if (!$db) salir(['ok' => false, 'empty' => true]);
    $u = buscarUsuario($db['data'], $in['usuario'] ?? '');
    if (!claveOk($u, $in['hash'] ?? '')) { usleep(400000); salir(['ok' => false]); }
    if (empty($u['activo'])) salir(['ok' => false, 'inactivo' => true]);
    salir(['ok' => true, 'token' => nuevoToken($u['id']), 'version' => $db['version'], 'data' => $db['data']]);
  case 'init':
  case 'replace':
    $data = $in['data'] ?? null;
    if (!datosValidos($data)) salir(['ok' => false, 'error' => 'Los datos enviados no son válidos.']);
    if ($accion === 'init' && $db) salir(['ok' => false, 'exists' => true]);
    if ($accion === 'replace') {
      if (!$db) salir(['ok' => false, 'empty' => true]);
      $adm = buscarUsuario($db['data'], $in['usuario'] ?? '');
      if (!claveOk($adm, $in['hash'] ?? '') || ($adm['rol'] ?? '') !== 'admin') { usleep(400000); salir(['ok' => false, 'error' => 'Usuario o contraseña de administrador del servidor incorrectos.']); }
      respaldoDiario();
    }
    $propio = buscarUsuario($data, $in['usuarioLocal'] ?? ($in['usuario'] ?? ''));
    if (!$propio) $propio = $data['usuarios'][0];
    $db = ['version' => ($db['version'] ?? 0) + 1, 'data' => $data];
    escribir($dbFile, $db);
    salir(['ok' => true, 'token' => nuevoToken($propio['id']), 'version' => $db['version']]);
  case 'pull':
    validar();
    if ((int)($in['version'] ?? -1) === (int)$db['version']) salir(['ok' => true, 'same' => true]);
    salir(['ok' => true, 'version' => $db['version'], 'data' => $db['data']]);
  case 'push':
    validar();
    if ((int)($in['base'] ?? -1) !== (int)$db['version']) salir(['ok' => false, 'conflict' => true, 'version' => $db['version'], 'data' => $db['data']]);
    $data = $in['data'] ?? null;
    if (!datosValidos($data)) salir(['ok' => false, 'error' => 'Los datos enviados no son válidos.']);
    respaldoDiario();
    $db = ['version' => $db['version'] + 1, 'data' => $data];
    escribir($dbFile, $db);
    salir(['ok' => true, 'version' => $db['version']]);
  default:
    salir(['ok' => false, 'error' => 'Acción desconocida']);
}
