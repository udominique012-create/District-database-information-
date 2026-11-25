<?php
declare(strict_types=1);
$C = require __DIR__ . '/config.php';
require __DIR__ . '/sms.php';
require __DIR__ . '/mail.php';

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store');
header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'");
if (!empty($_SERVER['HTTPS'])) header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
if (!empty($C['cors_origin'])) {   // frontend na backend biri ku mazina atandukanye
    header('Access-Control-Allow-Origin: ' . $C['cors_origin']);
    header('Access-Control-Allow-Credentials: true');
    header('Access-Control-Allow-Headers: Content-Type, X-CSRF, X-Sector');
    header('Access-Control-Allow-Methods: GET,POST,PUT,DELETE,OPTIONS');
    header('Vary: Origin');
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') { http_response_code(204); exit; }
}
session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax', 'secure' => !empty($_SERVER['HTTPS'])]);
ini_set('session.use_strict_mode', '1');
session_start();

/** Umurenge ukoreshwa: install (CLI) > umurenge wa session (winjiye) > uwa kode ya 2FA > umutwe X-Sector (mbere yo kwinjira). */
function all_sectors(): array { global $C; return $C['sectors'] + ['district' => ['name' => 'Akarere ka ' . ($C['district'] ?? 'Muhanga'), 'db' => $C['district_db']]]; }
/** Umurenge ukoreshwa: install (CLI) > umurenge wa session (winjiye) > uwa kode ya 2FA > umutwe X-Sector. 'district' = abayobozi b'Akarere. */
function cur_sector(): string {
    if (isset($GLOBALS['SECTOR_OVERRIDE'])) $s = $GLOBALS['SECTOR_OVERRIDE'];
    elseif (!empty($_SESSION['u'])) $s = $_SESSION['sector'] ?? '';
    elseif (!empty($_SESSION['otp']['sec'])) $s = $_SESSION['otp']['sec'];
    else $s = $_SERVER['HTTP_X_SECTOR'] ?? ($_GET['sector'] ?? '');
    return (is_string($s) && isset(all_sectors()[$s])) ? $s : '';
}
function sector_name(): string { return all_sectors()[cur_sector()]['name'] ?? ''; }
function sector_dsn(string $dsn, string $db): string { return rtrim(preg_replace('/;?dbname=[^;]*/', '', $dsn), ';') . ';dbname=' . $db; }
/** Database y'umurenge (cyangwa y'Akarere) ukurikije slug. */
function conn(string $s): PDO {
    global $C; static $conns = []; $all = all_sectors();
    if (!isset($all[$s])) fail('Hitamo umurenge', 400);
    return $conns[$s] ??= new PDO(sector_dsn($C['db']['dsn'], $all[$s]['db']), $C['db']['user'], $C['db']['pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false, PDO::MYSQL_ATTR_INIT_COMMAND => "SET time_zone='+00:00'",
    ]);
}
function db(): PDO { $s = cur_sector(); if ($s === '') fail('Hitamo umurenge', 400); return conn($s); }
function qs(string $s, string $sql, array $p = []): PDOStatement { $st = conn($s)->prepare($sql); $st->execute($p); return $st; }
function q(string $sql, array $p = []): PDOStatement { $s = db()->prepare($sql); $s->execute($p); return $s; }
function out(mixed $d, int $c = 200): never { http_response_code($c); header('Content-Type: application/json; charset=utf-8'); echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; }
function fail(string $m, int $c = 400): never { out(['e' => $m], $c); }
function body(): array { static $b = null; return $b ??= (json_decode(file_get_contents('php://input'), true) ?: $_POST); }
function lg(string $u, string $w): void { q('insert into `log`(u,w) values(?,?)', [$u, $w]); }

function meOf(array $u): array {
    return ['id' => (int)$u['id'], 'name' => $u['name'], 'role' => $u['role'], 'cell' => $u['cell'] ?? '',
            'phone' => $u['phone'] ?? '', 'email' => $u['email'] ?? '', 'age' => $u['age'] ?? '', 'status' => $u['status'] ?? '',
            'sex' => $u['sex'] ?? '', 'village' => $u['village'] ?? '', 'isibo' => $u['isibo'] ?? '', 'photo' => $u['photo'] ?? '',
            'district' => 'Muhanga', 'sector' => sector_name(), 'sector_slug' => cur_sector()];
}
/** 0788123456, +250788123456, 250788123456 -> 0788123456 */
function norm_phone(string $p): string {
    $d = preg_replace('/\D/', '', $p);
    if (str_starts_with($d, '250')) $d = '0' . substr($d, 3); elseif (strlen($d) === 9) $d = '0' . $d;
    return $d;
}
const PW_MSG = 'Ijambobanga rigomba kuba nibura inyuguti 8, rikagira inyuguti n’imibare';
function pw_ok(string $p): bool { return strlen($p) >= 8 && preg_match('/[A-Za-z]/', $p) && preg_match('/\d/', $p); }
/** Admin, staff na executive: bahabwa kode ya SMS (2FA) buri gihe binjira. */
function priv(string $role): bool { return in_array($role, ['admin', 'staff', 'exec', 'worker'], true); }   // abayobozi bose (kuva ku Karere kugera ku kagari)
function mask_email(string $e): string { $p = explode('@', $e); return substr($p[0], 0, 2) . '***@' . ($p[1] ?? ''); }
/** '' = nta kibazo; 'password' = agomba guhindura ijambobanga ry'agateganyo; 'phone' = agomba kongeramo telefone. */
function must_state(array $u): string {
    if (!empty($u['must_change'])) return 'password';
    if (priv($u['role']) && !filter_var((string)($u['email'] ?? ''), FILTER_VALIDATE_EMAIL)) return 'email';
    return '';
}
function send_otp(array $u): array {
    global $C;
    $code = (string)random_int(100000, 999999);
    $_SESSION['otp'] = ['uid' => (int)$u['id'], 'sec' => cur_sector(), 'h' => password_hash($code, PASSWORD_DEFAULT), 'exp' => time() + 300, 'tries' => 0, 'sent' => time()];
    $ok = mail_send((string)($u['email'] ?? ''), 'Kode yo kwinjira - Akarere ka Muhanga', "Muraho {$u['name']},\n\nKode yawe yo kwinjira (" . sector_name() . "): $code\nImara iminota 5. Ntuyihe undi muntu. Niba utari wowe wayisabye, hindura ijambobanga ryawe vuba.\n");
    if (!$ok && empty($C['otp_debug'])) { unset($_SESSION['otp']); fail('Email ntiyoherejwe. Ongera ugerageze, cyangwa ubaze admin.', 503); }
    return !empty($C['otp_debug']) ? ['debug_code' => $code] : [];
}
function login_as(array $u): array {
    session_regenerate_id(true);
    $_SESSION['sector'] = cur_sector();
    $_SESSION['u'] = meOf($u); $_SESSION['csrf'] = bin2hex(random_bytes(16)); $_SESSION['last'] = time(); $_SESSION['must'] = must_state($u);
    return ['csrf' => $_SESSION['csrf'], 'me' => $_SESSION['u'], 'must' => $_SESSION['must']];
}
/** Genzura ko winjiye n'uruhare rwawe; ku bikorwa bihindura amakuru, CSRF iraba. */
function need(string ...$roles): array {
    $u = $_SESSION['u'] ?? null;
    if (!$u) fail('Injira', 401);
    if (priv($u['role']) && time() - ($_SESSION['last'] ?? 0) > 1800) { session_destroy(); fail('Session yarangiye kubera kudakora iminota 30. Ongera winjire.', 401); }
    $_SESSION['last'] = time();
    if (!empty($_SESSION['must']) && !in_array(($GLOBALS['m'] ?? '') . ' ' . ($GLOBALS['res'] ?? ''), ['POST password', 'PUT profile', 'POST logout'], true))
        fail('Banza uhindure ijambobanga cyangwa wongereho telefone', 403);
    if ($roles && !in_array($u['role'], $roles, true)) fail('Ntiwemerewe', 403);
    if ($_SERVER['REQUEST_METHOD'] !== 'GET' && !hash_equals($_SESSION['csrf'] ?? '', $_SERVER['HTTP_X_CSRF'] ?? '')) fail('Session yarangiye, ongera winjire', 403);
    return $u;
}
/** Ikintu gishobora guhindurwa na nyiracyo cyangwa admin gusa. $t ni izina ry'ibiri muri code, ntiriva ku mukoresha. */
function mine(string $t, array $u, int $id): array {
    $x = q("select * from `$t` where id=?", [$id])->fetch();
    if (!$x || !($u['role'] === 'admin' || (int)($x['uid'] ?? 0) === $u['id'] || (($x['uid'] ?? null) === null && ($x['u'] ?? '') === $u['name']))) fail('Ntiwemerewe', 403);
    return $x;
}
function upload(string $k): string {
    if (empty($_FILES[$k]) || $_FILES[$k]['error'] === UPLOAD_ERR_NO_FILE) return '';
    $f = $_FILES[$k];
    if ($f['error'] !== UPLOAD_ERR_OK) fail('Dosiye ntiyoherejwe (ishobora kuba nini cyane)');
    $ok = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif', 'video/mp4' => 'mp4', 'video/webm' => 'webm', 'video/quicktime' => 'mov'];
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
    if (!isset($ok[$mime])) fail('Ubwoko bwa dosiye ntiyemewe (ifoto cyangwa video gusa)');
    $n = bin2hex(random_bytes(12)) . '.' . $ok[$mime];
    move_uploaded_file($f['tmp_name'], __DIR__ . '/../public/uploads/' . $n);
    return '/uploads/' . $n;
}
function rmfile(?string $f): void { if ($f) @unlink(__DIR__ . '/../public' . $f); }
