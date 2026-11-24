<?php
/**
 * BMP280 collector — single file. No dependencies, no build, no service.
 *
 *   POST  bmp280.php                                 store one reading (Bearer token)
 *   GET   bmp280.php?token=...                       chart + table
 *   GET   bmp280.php?format=json&hours=24&token=...  JSON
 *   GET   bmp280.php?format=csv&token=...            CSV
 *   GET   bmp280.php?health=1                        {"ok":true,"count":N}
 *
 * Setup: copy .env.example to .env (ideally one level above the web root) and fill it in,
 * upload this file to your site's document root. The table is created automatically on
 * first request. Without a .env the defaults below apply.
 */

declare(strict_types=1);

// ------------------------------ configuration -----------------------------
// Reads .env from the script's folder or one level above it. Environment variables that
// are already set (e.g. by PHP-FPM) win over the file.
foreach ([dirname(__DIR__) . '/.env', __DIR__ . '/.env'] as $envFile) {
    if (!is_readable($envFile)) {
        continue;
    }
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
            continue;
        }
        [$k, $v] = explode('=', $line, 2);
        $k = trim($k);
        $v = trim($v);
        if (strlen($v) > 1 && (($v[0] === '"' && substr($v, -1) === '"') || ($v[0] === "'" && substr($v, -1) === "'"))) {
            $v = substr($v, 1, -1);
        }
        if (getenv($k) === false) {
            putenv("$k=$v");
        }
    }
    break;
}

function env_or(string $key, string $default): string
{
    $v = getenv($key);
    return ($v === false || $v === '') ? $default : $v;
}

define('DB_HOST', env_or('BMP280_DB_HOST', 'localhost'));
define('DB_NAME', env_or('BMP280_DB_NAME', 'bmp280'));   // created in CloudPanel -> Databases
define('DB_USER', env_or('BMP280_DB_USER', 'bmp280'));   // database user
define('DB_PASS', env_or('BMP280_DB_PASS', 'CHANGE-ME'));
define('TOKEN',   env_or('BMP280_TOKEN', 'CHANGE-ME'));  // openssl rand -hex 24, also on the ESP32
// --------------------------------------------------------------------------

const TABLE_SQL = 'CREATE TABLE IF NOT EXISTS readings (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    device VARCHAR(64) NOT NULL,
    seq INT NULL,
    sensor_ts VARCHAR(32) NULL,
    uptime_s INT NULL,
    temp_c DOUBLE NOT NULL,
    press_hpa DOUBLE NOT NULL,
    sea_level_hpa DOUBLE NULL,
    altitude_m DOUBLE NULL,
    rssi INT NULL,
    heap INT NULL,
    received_epoch INT UNSIGNED NOT NULL,
    KEY idx_epoch (received_epoch)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4';

const CONFIG_SQL = 'CREATE TABLE IF NOT EXISTS config (
    k VARCHAR(64) NOT NULL PRIMARY KEY,
    v TEXT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4';

function out(int $code, string $body, string $type = 'application/json'): void
{
    http_response_code($code);
    header('Content-Type: ' . $type);
    header('Cache-Control: no-store');
    echo $body;
    exit;
}

function jout(int $code, array $data): void
{
    out($code, json_encode($data, JSON_UNESCAPED_SLASHES));
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new PDO(
            sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', DB_HOST, DB_NAME),
            DB_USER,
            DB_PASS,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
             PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
             PDO::ATTR_EMULATE_PREPARES => false]
        );
        $pdo->exec(TABLE_SQL);
        $pdo->exec(CONFIG_SQL);
    }
    return $pdo;
}

/** Runtime setting: DB value wins, then .env, then the given default. */
function cfg_get(string $key, string $default = ''): string
{
    static $cache = [];
    if (array_key_exists($key, $cache)) return $cache[$key];
    $q = db()->prepare('SELECT v FROM config WHERE k = ?');
    $q->execute([$key]);
    $r = $q->fetch();
    $cache[$key] = ($r && $r['v'] !== null && $r['v'] !== '') ? (string)$r['v'] : $default;
    return $cache[$key];
}

function cfg_set(string $key, string $value): void
{
    $q = db()->prepare('REPLACE INTO config (k, v) VALUES (?, ?)');
    $q->execute([$key, $value]);
}

function given_token(): string
{
    $h = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if ($h === '' && function_exists('getallheaders')) {
        foreach (getallheaders() as $k => $v) {
            if (strcasecmp($k, 'Authorization') === 0) {
                $h = $v;
            }
        }
    }
    if (preg_match('/Bearer\s+(.+)/i', $h, $m)) {
        return trim($m[1]);
    }
    return trim((string)($_GET['token'] ?? ($_COOKIE['bmp280_token'] ?? '')));
}

function authorized(): bool
{
    return TOKEN !== 'CHANGE-ME' && hash_equals(TOKEN, given_token());
}

try {
    $pdo = db();
} catch (Throwable $e) {
    jout(500, ['ok' => false, 'error' => 'database: ' . $e->getMessage()]);
}

// ------------------------------- ingest ----------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_GET['set_location'])) {
    if (!authorized()) {
        jout(401, ['ok' => false, 'error' => 'unauthorized']);
    }
    $body = file_get_contents('php://input');
    if ($body === '' || strlen($body) > 8192) {
        jout(400, ['ok' => false, 'error' => 'bad body']);
    }
    $d = json_decode($body, true);
    if (!is_array($d)) {
        jout(400, ['ok' => false, 'error' => 'invalid json']);
    }
    $temp  = filter_var($d['temp_c'] ?? null, FILTER_VALIDATE_FLOAT);
    $press = filter_var($d['press_hpa'] ?? null, FILTER_VALIDATE_FLOAT);
    if ($temp === false || $press === false || $temp < -60 || $temp > 100 || $press < 300 || $press > 1100) {
        jout(422, ['ok' => false, 'error' => 'missing or out-of-range temp_c/press_hpa']);
    }

    $stmt = $pdo->prepare('INSERT INTO readings (device, seq, sensor_ts, uptime_s, temp_c, press_hpa,
        sea_level_hpa, altitude_m, rssi, heap, received_epoch)
        VALUES (?,?,?,?,?,?,?,?,?,?,?)');
    $stmt->execute([
        substr((string)($d['device'] ?? 'unknown'), 0, 64),
        isset($d['seq']) ? (int)$d['seq'] : null,
        isset($d['ts']) && $d['ts'] !== '' ? substr((string)$d['ts'], 0, 32) : null,
        isset($d['uptime_s']) ? (int)$d['uptime_s'] : null,
        $temp, $press,
        isset($d['sea_level_hpa']) ? (float)$d['sea_level_hpa'] : null,
        isset($d['altitude_m']) ? (float)$d['altitude_m'] : null,
        isset($d['rssi']) ? (int)$d['rssi'] : null,
        isset($d['heap']) ? (int)$d['heap'] : null,
        time(),
    ]);
    jout(200, ['ok' => true, 'id' => (int)$pdo->lastInsertId()]);
}

// -------------------------------- health ---------------------------------
if (isset($_GET['health'])) {
    jout(200, ['ok' => true, 'count' => (int)$pdo->query('SELECT COUNT(*) FROM readings')->fetchColumn()]);
}

if (!authorized()) {
    if (isset($_GET['format'])) {
        jout(401, ['ok' => false, 'error' => 'unauthorized']);
    }
    // Browser without a token: ask once, then remember it in a cookie.
    http_response_code(401);
    header('Content-Type: text/html; charset=utf-8');
    $bad = isset($_GET['token']);
    ?>
<!doctype html><html lang="en"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1"><title>BMP280 · sign in</title>
<style>
body{margin:0;min-height:100vh;display:grid;place-items:center;background:#0e1116;color:#e6edf3;
     font:15px/1.5 system-ui,-apple-system,Segoe UI,sans-serif}
form{background:#161b22;border:1px solid #21262d;border-radius:12px;padding:22px;width:min(90vw,340px)}
h1{font-size:16px;margin:0 0 4px}
p{color:#8b949e;font-size:12px;margin:0 0 14px}
input{width:100%;box-sizing:border-box;padding:9px 11px;border-radius:8px;border:1px solid #30363d;
      background:#0d1117;color:#e6edf3;font:13px ui-monospace,monospace}
button{margin-top:12px;width:100%;padding:9px;border-radius:8px;border:1px solid #2ea043;
       background:#238636;color:#fff;font-weight:600;cursor:pointer}
.err{color:#f85149;font-size:12px;margin-top:10px}
</style></head><body>
<form method="get" action="">
  <h1>BMP280 dashboard</h1>
  <p>Paste the API token to unlock this browser.</p>
  <input name="token" type="password" placeholder="token" autofocus autocomplete="current-password">
  <button type="submit">Open dashboard</button>
  <?php if ($bad): ?><div class="err">Token did not match.</div><?php endif; ?>
</form></body></html>
    <?php
    exit;
}

// -------------------------------- csv ------------------------------------
if (($_GET['format'] ?? '') === 'csv') {
    $rows = $pdo->query('SELECT * FROM readings ORDER BY id ASC')->fetchAll();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="bmp280.csv"');
    $fh = fopen('php://output', 'w');
    fputcsv($fh, ['received_at', 'device', 'seq', 'sensor_ts', 'uptime_s',
                  'temp_c', 'press_hpa', 'sea_level_hpa', 'altitude_m', 'rssi', 'heap'], ',', '"', '');
    foreach ($rows as $r) {
        fputcsv($fh, [gmdate('Y-m-d\TH:i:s\Z', (int)$r['received_epoch']), $r['device'], $r['seq'],
                      $r['sensor_ts'], $r['uptime_s'], $r['temp_c'], $r['press_hpa'],
                      $r['sea_level_hpa'], $r['altitude_m'], $r['rssi'], $r['heap']], ',', '"', '');
    }
    fclose($fh);
    exit;
}

// ------------------------------- json ------------------------------------
if (($_GET['format'] ?? '') === 'json') {
    $hours = max(1, min(8760, (int)($_GET['hours'] ?? 24)));
    $limit = max(1, min(20000, (int)($_GET['limit'] ?? 5000)));
    $stmt = $pdo->prepare('SELECT * FROM readings WHERE received_epoch >= ? ORDER BY id ASC LIMIT ?');
    // LIMIT must be bound as an integer: with native prepares MySQL rejects a string there.
    $stmt->bindValue(1, time() - $hours * 3600, PDO::PARAM_INT);
    $stmt->bindValue(2, $limit, PDO::PARAM_INT);
    $stmt->execute();
    $samples = [];
    foreach ($stmt->fetchAll() as $r) {
        $samples[] = [
            'id' => (int)$r['id'], 'device' => $r['device'], 'seq' => $r['seq'] === null ? null : (int)$r['seq'],
            'ts' => $r['sensor_ts'], 'received_at' => gmdate('Y-m-d\TH:i:s\Z', (int)$r['received_epoch']),
            'temp_c' => (float)$r['temp_c'], 'press_hpa' => (float)$r['press_hpa'],
            'sea_level_hpa' => $r['sea_level_hpa'] === null ? null : (float)$r['sea_level_hpa'],
            'altitude_m' => $r['altitude_m'] === null ? null : (float)$r['altitude_m'],
            'rssi' => $r['rssi'] === null ? null : (int)$r['rssi'],
        ];
    }
    jout(200, ['ok' => true, 'hours' => $hours, 'samples' => $samples]);
}

// ------------------------------- page ------------------------------------
// Remember the token in a cookie so the URL only needs it once.
if (isset($_GET['token']) && hash_equals(TOKEN, (string)$_GET['token'])) {
    setcookie('bmp280_token', TOKEN, [
        'expires'  => time() + 90 * 86400,
        'path'     => '/',
        'httponly' => true,
        'secure'   => true,
        'samesite' => 'Lax',
    ]);
}

$hours = max(1, min(8760, (int)($_GET['hours'] ?? 24)));
$rows  = $pdo->query('SELECT * FROM readings ORDER BY id DESC LIMIT 25')->fetchAll();
$total = (int)$pdo->query('SELECT COUNT(*) FROM readings')->fetchColumn();
$last  = $rows[0] ?? null;

$st = $pdo->prepare('SELECT COUNT(*) n, MIN(temp_c) tmin, MAX(temp_c) tmax, AVG(temp_c) tavg,
                            MIN(press_hpa) pmin, MAX(press_hpa) pmax, AVG(press_hpa) pavg
                     FROM readings WHERE received_epoch >= ?');
$st->execute([time() - $hours * 3600]);
$s = $st->fetch() ?: [];

$seen   = $last ? (int)$last['received_epoch'] : 0;
$online = $seen > 0 && (time() - $seen) < 300;
$tok    = htmlspecialchars((string)($_GET['token'] ?? ''), ENT_QUOTES);
$age    = $seen ? (time() - $seen) : 0;
$ranges = [1 => '1 h', 6 => '6 h', 24 => '24 h', 168 => '7 d', 720 => '30 d'];

// --- station location + display timezone: DB (set from the map) wins, then .env ---
$tzName   = cfg_get('tz', env_or('BMP280_TZ', 'UTC'));
$location = cfg_get('label', env_or('BMP280_LOCATION', ''));
$elevM    = (float)cfg_get('elev_m', env_or('BMP280_ELEV_M', '0'));
$lat      = cfg_get('lat', env_or('BMP280_LAT', ''));
$lon      = cfg_get('lon', env_or('BMP280_LON', ''));

try {
    $tzObj = new DateTimeZone($tzName);
} catch (Throwable $e) {
    $tzObj = new DateTimeZone('UTC');
    $tzName = 'UTC';
}

function local_str(int $epoch, DateTimeZone $tz): string
{
    $d = new DateTime('@' . $epoch);
    $d->setTimezone($tz);
    return $d->format('Y-m-d H:i');
}

function tz_abbrev(DateTimeZone $tz): string
{
    return (new DateTime('now', $tz))->format('T');
}

/** Reduce station pressure to sea level (barometric formula). */
function to_sea_level(float $p, float $h): float
{
    if ($h <= 0.0) return $p;
    return $p / pow(1.0 - ($h / 44330.0), 5.255);
}

/** Open-Meteo forecast, cached for 15 minutes in the config table. */
function weather_get(string $lat, string $lon, string $tzName): ?array
{
    $cached = cfg_get('wx_json', '');
    $ts     = (int)cfg_get('wx_ts', '0');
    if ($cached !== '' && (time() - $ts) < 900) {
        $a = json_decode($cached, true);
        if (is_array($a) && isset($a['current'])) return $a;
    }
    $url = 'https://api.open-meteo.com/v1/forecast'
         . '?latitude=' . urlencode($lat) . '&longitude=' . urlencode($lon)
         . '&current=temperature_2m,pressure_msl'
         . '&hourly=temperature_2m,pressure_msl'
         . '&forecast_days=2&timezone=' . urlencode($tzName);
    $ctx = stream_context_create(['http' => [
        'timeout' => 8,
        'header'  => "User-Agent: bmp280-collector/1.0\r\n",
    ]]);
    $raw = @file_get_contents($url, false, $ctx);
    if ($raw === false || $raw === '') return null;
    $a = json_decode($raw, true);
    if (!is_array($a) || !isset($a['current'])) return null;
    cfg_set('wx_json', $raw);
    cfg_set('wx_ts', (string)time());
    return $a;
}

// ------------------------- location + config endpoints -------------------
if (isset($_GET['set_location']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $d = json_decode((string)file_get_contents('php://input'), true);
    if (!is_array($d)) {
        jout(400, ['ok' => false, 'error' => 'invalid json']);
    }
    $nlat = isset($d['lat']) ? (float)$d['lat'] : null;
    $nlon = isset($d['lon']) ? (float)$d['lon'] : null;
    if ($nlat === null || $nlon === null || abs($nlat) > 90 || abs($nlon) > 180 || ($nlat === 0.0 && $nlon === 0.0)) {
        jout(422, ['ok' => false, 'error' => 'lat/lon out of range']);
    }
    $label = substr(trim((string)($d['label'] ?? '')), 0, 120);
    $elev  = isset($d['elev']) && $d['elev'] !== null && $d['elev'] !== '' ? (float)$d['elev'] : null;

    if ($elev === null) {   // free elevation lookup, no key
        $ctx = stream_context_create(['http' => ['timeout' => 6]]);
        $raw = @file_get_contents('https://api.open-meteo.com/v1/elevation?latitude=' . $nlat . '&longitude=' . $nlon, false, $ctx);
        $ea  = $raw ? json_decode($raw, true) : null;
        if (isset($ea['elevation'][0])) $elev = (float)$ea['elevation'][0];
    }

    cfg_set('lat', (string)$nlat);
    cfg_set('lon', (string)$nlon);
    if ($label !== '') cfg_set('label', $label);
    if ($elev !== null && $elev > -500 && $elev < 9000) cfg_set('elev_m', (string)round($elev, 1));
    cfg_set('wx_json', '');
    cfg_set('wx_ts', '0');
    jout(200, ['ok' => true, 'lat' => $nlat, 'lon' => $nlon, 'label' => $label, 'elev' => $elev]);
}

if (($_GET['format'] ?? '') === 'config') {
    jout(200, ['ok' => true, 'lat' => $lat, 'lon' => $lon, 'label' => $location,
               'elev_m' => $elevM, 'tz' => $tzName]);
}

$seaNow = $last ? to_sea_level((float)$last['press_hpa'], $elevM) : null;
$tzShort = tz_abbrev($tzObj);

// --- Open-Meteo forecast (free, no key), used as an independent reference ---
$wxCur = null;
$wxHours = [];
if ($lat !== '' && $lon !== '') {
    $wx = weather_get($lat, $lon, $tzName);
    if ($wx && isset($wx['current'])) {
        $wxCur = $wx['current'];
        $nowKey = substr((string)($wxCur['time'] ?? ''), 0, 13);
        $start = null;
        if ($nowKey !== '' && isset($wx['hourly']['time'])) {
            foreach ($wx['hourly']['time'] as $i => $t) {
                if (strpos($t, $nowKey) === 0) { $start = $i; break; }
            }
        }
        if ($start !== null) {
            $n = count($wx['hourly']['time']);
            for ($k = $start + 1; $k <= $start + 6 && $k < $n; $k++) {
                $wxHours[] = [
                    't'     => substr($wx['hourly']['time'][$k], 11, 5),
                    'temp'  => $wx['hourly']['temperature_2m'][$k] ?? null,
                    'press' => $wx['hourly']['pressure_msl'][$k] ?? null,
                ];
            }
        }
    }
}
$wxDeltaT = null;
$wxDeltaP = null;

function fnum($v, int $d = 2): string
{
    return $v === null ? '—' : number_format((float)$v, $d, '.', ',');
}

function age_text(int $s): string
{
    if ($s <= 0) return 'never';
    if ($s < 60) return $s . ' s ago';
    if ($s < 3600) return intdiv($s, 60) . ' min ago';
    if ($s < 86400) return intdiv($s, 3600) . ' h ago';
    return intdiv($s, 86400) . ' d ago';
}

/** Nearest sample at or before $epoch, or null. */
function value_at(PDO $pdo, int $epoch, string $col): ?array
{
    if (!in_array($col, ['temp_c', 'press_hpa'], true)) return null;
    $q = $pdo->prepare("SELECT $col AS v, received_epoch AS e FROM readings
                        WHERE received_epoch <= ? ORDER BY received_epoch DESC LIMIT 1");
    $q->execute([$epoch]);
    $r = $q->fetch();
    return $r ? ['v' => (float)$r['v'], 'e' => (int)$r['e']] : null;
}

/** Least-squares fit over the last $n samples: slope per hour + residual sigma. */
function trend(PDO $pdo, string $col, int $n = 120): array
{
    if (!in_array($col, ['temp_c', 'press_hpa'], true)) return [0.0, 0.0];
    $q = $pdo->prepare("SELECT $col AS v, received_epoch AS e FROM readings
                        ORDER BY id DESC LIMIT $n");
    $q->execute();
    $pts = array_reverse($q->fetchAll());
    $k = count($pts);
    if ($k < 4) return [0.0, 0.0];

    $t0 = (int)$pts[0]['e'];
    $sx = $sy = $sxy = $sxx = 0.0;
    foreach ($pts as $p) {
        $x = ((int)$p['e'] - $t0) / 3600.0;
        $y = (float)$p['v'];
        $sx += $x; $sy += $y; $sxy += $x * $y; $sxx += $x * $x;
    }
    $den = $k * $sxx - $sx * $sx;
    if (abs($den) < 1e-9) return [0.0, 0.0];
    $slope = ($k * $sxy - $sx * $sy) / $den;
    $inter = ($sy - $slope * $sx) / $k;

    $ss = 0.0;
    foreach ($pts as $p) {
        $x = ((int)$p['e'] - $t0) / 3600.0;
        $res = (float)$p['v'] - ($inter + $slope * $x);
        $ss += $res * $res;
    }
    return [$slope, sqrt($ss / $k)];   // hPa or °C per hour, residual sigma
}

$now = time();
[$tSlope, $tSigma] = trend($pdo, 'temp_c');
[$pSlope, $pSigma] = trend($pdo, 'press_hpa');

$tempNow = $last ? (float)$last['temp_c'] : null;
$pressNow = $last ? (float)$last['press_hpa'] : null;

// Observed deltas over fixed windows (nearest sample at or before the window start).
$t1h = value_at($pdo, $now - 3600, 'temp_c');
$t24h = value_at($pdo, $now - 86400, 'temp_c');
$p3h = value_at($pdo, $now - 10800, 'press_hpa');
$p24h = value_at($pdo, $now - 86400, 'press_hpa');

$dT1h = ($tempNow !== null && $t1h) ? $tempNow - $t1h['v'] : null;
$dT24h = ($tempNow !== null && $t24h) ? $tempNow - $t24h['v'] : null;
$dP3h = ($pressNow !== null && $p3h) ? $pressNow - $p3h['v'] : null;
$dP24h = ($pressNow !== null && $p24h) ? $pressNow - $p24h['v'] : null;

// Forecast = linear extrapolation of the last-2h trend (not a weather model).
$fcTemp = $tempNow === null ? null : $tempNow + $tSlope * 1.0;
$fcPress = $pressNow === null ? null : $pressNow + $pSlope * 3.0;

// Measured minus Open-Meteo forecast (both at "now").
$wxDeltaT = ($wxCur && $tempNow !== null && isset($wxCur['temperature_2m']))
    ? $tempNow - (float)$wxCur['temperature_2m'] : null;
$wxDeltaP = ($wxCur && $seaNow !== null && isset($wxCur['pressure_msl']))
    ? $seaNow - (float)$wxCur['pressure_msl'] : null;

// WMO-style pressure tendency over 3 h.
function tendency(?float $d): array
{
    if ($d === null) return ['unknown', 'not enough history yet'];
    if ($d <= -3.5) return ['falling rapidly', 'weather likely to deteriorate quickly'];
    if ($d <= -1.0) return ['falling', 'weather likely to deteriorate'];
    if ($d >= 3.5) return ['rising rapidly', 'weather likely to improve quickly'];
    if ($d >= 1.0) return ['rising', 'weather likely to improve'];
    return ['steady', 'weather likely to stay similar'];
}

[$tendLabel, $tendNote] = tendency($dP3h);

function arrow(?float $d, float $deadband = 0.1): string
{
    if ($d === null) return '·';
    if ($d > $deadband) return '▲';
    if ($d < -$deadband) return '▼';
    return '▬';
}

function delta_text(?float $d, int $digits = 2): string
{
    if ($d === null) return '—';
    return ($d >= 0 ? '+' : '') . number_format($d, $digits, '.', '');
}
?>
<!doctype html>
<html lang="en"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>BMP280 · <?= htmlspecialchars($last['device'] ?? 'sensor') ?></title>
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<style>
:root{color-scheme:dark}
body{margin:0;padding:18px;background:#0e1116;color:#e6edf3;font:15px/1.5 system-ui,-apple-system,Segoe UI,sans-serif}
header{display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:14px}
h1{font-size:17px;margin:0;font-weight:600}
.dot{display:inline-block;width:8px;height:8px;border-radius:50%;margin-right:6px;background:#f85149}
.dot.on{background:#3fb950}
.muted{color:#8b949e;font-size:12px}
.card{background:#161b22;border:1px solid #21262d;border-radius:10px;padding:14px;margin-bottom:12px}
.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:12px}
.big{font-size:25px;font-weight:600;font-variant-numeric:tabular-nums;line-height:1.2}
.unit{font-size:13px;color:#8b949e;font-weight:400}
.up{color:#3fb950}.down{color:#f85149}.flat{color:#8b949e}
canvas{width:100%;height:220px;display:block}
table{width:100%;border-collapse:collapse;font-size:13px;font-variant-numeric:tabular-nums}
th,td{padding:6px;text-align:right;border-bottom:1px solid #21262d}
th{color:#8b949e;font-size:11px;text-transform:uppercase;letter-spacing:.6px}
td:first-child,th:first-child{text-align:left;color:#8b949e}
a{color:#2f81f7;text-decoration:none}
.pill{display:inline-block;padding:2px 9px;border-radius:999px;background:#21262d;font-size:12px;margin-right:6px}
.pill.active{background:#238636}
ul.analysis{margin:0;padding-left:18px}
ul.analysis li{margin:3px 0}
.legend{font-size:11px;color:#8b949e;margin-top:6px}
.legend i{display:inline-block;width:14px;height:2px;vertical-align:middle;margin:0 4px 2px 10px}
</style></head><body>
<header>
  <div><h1><?= htmlspecialchars($last['device'] ?? 'BMP280') ?></h1>
    <div class="muted"><span class="dot<?= $online ? ' on' : '' ?>"></span>
      <?= $online ? 'online' : 'offline' ?> · last reading <?= age_text($age) ?><?= $last ? ' at ' . local_str($seen, $tzObj) . ' ' . $tzShort : '' ?> · <?= number_format($total) ?> rows stored</div>
    <?php if ($location !== '' || ($lat !== '' && $lon !== '')): ?>
    <div class="muted"><?= htmlspecialchars($location) ?><?php if ($lat !== '' && $lon !== ''): ?>
      · <a href="https://www.openstreetmap.org/?mlat=<?= urlencode($lat) ?>&mlon=<?= urlencode($lon) ?>#map=13/<?= urlencode($lat) ?>/<?= urlencode($lon) ?>">map</a><?php endif; ?></div>
    <?php endif; ?></div>
  <div><?php foreach ($ranges as $h => $label): ?>
      <a class="pill<?= $h === $hours ? ' active' : '' ?>" href="?hours=<?= $h ?>&token=<?= $tok ?>"><?= $label ?></a>
  <?php endforeach; ?></div>
</header>

<div class="grid">
  <div class="card"><div class="muted">Temperature</div>
    <div class="big"><?= fnum($tempNow) ?><span class="unit"> °C</span></div>
    <div class="muted">1 h <span class="<?= $dT1h > 0.1 ? 'up' : ($dT1h < -0.1 ? 'down' : 'flat') ?>"><?= arrow($dT1h) ?> <?= delta_text($dT1h) ?></span>
      · 24 h <span class="<?= $dT24h > 0.1 ? 'up' : ($dT24h < -0.1 ? 'down' : 'flat') ?>"><?= delta_text($dT24h) ?></span></div>
    <div class="muted">trend <?= delta_text($tSlope, 3) ?> °C/h · next hour ≈ <?= fnum($fcTemp, 1) ?> °C</div></div>

  <div class="card"><div class="muted">Pressure</div>
    <div class="big"><?= fnum($pressNow) ?><span class="unit"> hPa</span></div>
    <div class="muted">3 h <span class="<?= $dP3h > 0.2 ? 'up' : ($dP3h < -0.2 ? 'down' : 'flat') ?>"><?= arrow($dP3h) ?> <?= delta_text($dP3h) ?></span>
      · 24 h <span class="<?= $dP24h > 0.2 ? 'up' : ($dP24h < -0.2 ? 'down' : 'flat') ?>"><?= delta_text($dP24h) ?></span></div>
    <div class="muted">trend <?= delta_text($pSlope, 3) ?> hPa/h · next 3 h ≈ <?= fnum($fcPress, 1) ?> hPa</div></div>

  <div class="card"><div class="muted">Sea-level pressure</div>
    <div class="big"><?= fnum($seaNow) ?><span class="unit"> hPa</span></div>
    <div class="muted"><?= $elevM > 0 ? 'station is ' . fnum($elevM, 1) . ' m above sea level (+' . fnum($seaNow - (float)($last['press_hpa'] ?? 0), 2) . ' hPa)' : 'set BMP280_ELEV_M to enable' ?></div></div>

  <div class="card"><div class="muted">Range <?= $ranges[$hours] ?? ($hours . ' h') ?></div>
    <div class="big"><?= fnum($s['tmin'] ?? null, 1) ?> – <?= fnum($s['tmax'] ?? null, 1) ?><span class="unit"> °C</span></div>
    <div class="muted">pressure <?= fnum($s['pmin'] ?? null, 1) ?> – <?= fnum($s['pmax'] ?? null, 1) ?> hPa · avg <?= fnum($s['pavg'] ?? null, 1) ?></div></div>

  <div class="card"><div class="muted">Readings in range</div>
    <div class="big"><?= number_format((int)($s['n'] ?? 0)) ?></div>
    <div class="muted">samples over <?= $ranges[$hours] ?? ($hours . ' h') ?> · all time <?= number_format($total) ?></div></div>

  <?php if ($wxCur): ?>
  <div class="card"><div class="muted">Forecast now · Open-Meteo</div>
    <div class="big"><?= fnum($wxCur['temperature_2m'] ?? null, 1) ?><span class="unit"> °C</span></div>
    <div class="muted">MSL <?= fnum($wxCur['pressure_msl'] ?? null, 1) ?> hPa</div>
    <div class="muted">measured − forecast: <span class="<?= $wxDeltaT > 0.3 ? 'up' : ($wxDeltaT < -0.3 ? 'down' : 'flat') ?>"><?= delta_text($wxDeltaT, 1) ?> °C</span>
      / <span class="<?= $wxDeltaP > 0.5 ? 'up' : ($wxDeltaP < -0.5 ? 'down' : 'flat') ?>"><?= delta_text($wxDeltaP, 1) ?> hPa</span></div></div>
  <?php elseif ($lat !== ''): ?>
  <div class="card"><div class="muted">Forecast · Open-Meteo</div>
    <div class="big">—</div><div class="muted">weather API unreachable right now</div></div>
  <?php endif; ?>
</div>

<?php if ($wxHours): ?>
<div class="card"><div class="muted" style="margin-bottom:6px">Next hours — forecast reference</div>
<table><thead><tr><th>hour (<?= htmlspecialchars($tzShort) ?>)</th><th>forecast temp °C</th><th>forecast MSL hPa</th></tr></thead>
<tbody>
<?php foreach ($wxHours as $w): ?>
<tr><td><?= htmlspecialchars($w['t']) ?></td><td><?= fnum($w['temp'], 1) ?></td><td><?= fnum($w['press'], 1) ?></td></tr>
<?php endforeach; ?>
</tbody></table></div>
<?php endif; ?>

<div class="card" id="mapcard">
  <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:8px">
    <div class="muted">Station location</div>
    <div class="muted" id="locmsg"><?= $location !== '' ? htmlspecialchars($location) : 'not set — search or click the map' ?></div>
  </div>
  <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:8px">
    <input id="q" placeholder="Search a place, e.g. Kolkata" style="flex:1;min-width:200px;padding:8px 10px;border-radius:8px;border:1px solid #30363d;background:#0d1117;color:#e6edf3;font:14px system-ui">
    <button onclick="searchPlace()">Search</button>
    <button class="primary" onclick="saveLoc()">Save location</button>
  </div>
  <div id="map" style="height:320px;border-radius:8px;border:1px solid #21262d"></div>
</div>

<div class="card"><div class="muted" style="margin-bottom:8px">Analysis</div>
<ul class="analysis">
  <li>Pressure is <b><?= htmlspecialchars($tendLabel) ?></b> (<?= delta_text($dP3h) ?> hPa in 3 h) — <?= htmlspecialchars($tendNote) ?>.</li>
  <li>Temperature trend over the last 2 h is <b><?= $tSlope >= 0 ? 'rising' : 'falling' ?></b> at <?= delta_text(abs($tSlope), 2) ?> °C/h<?= $tSigma > 0 ? ' (fit σ ' . number_format($tSigma, 2) . ' °C)' : '' ?>.</li>
  <li>Current reading is <?= delta_text($dT1h) ?> °C / <?= delta_text($dP3h) ?> hPa versus one and three hours ago; range extremes <?= fnum($s['tmin'] ?? null, 1) ?>–<?= fnum($s['tmax'] ?? null, 1) ?> °C.</li>
</ul>
<div class="muted" style="margin-top:8px">Forecast is a linear extrapolation of the recent trend, not a weather model. Expect it to be wrong when conditions change.</div>
</div>

<div class="card"><div class="muted" style="margin-bottom:6px">Temperature · <?= $ranges[$hours] ?? ($hours . ' h') ?></div>
  <canvas id="ct" data-kind="temp" data-slope="<?= $tSlope ?>" data-horizon="60"></canvas>
  <div class="legend">value <i style="background:#3fb950"></i> 30-sample mean <i style="background:#8b949e"></i> forecast <i style="background:#d29922"></i></div></div>

<div class="card"><div class="muted" style="margin-bottom:6px">Pressure · <?= $ranges[$hours] ?? ($hours . ' h') ?></div>
  <canvas id="cp" data-kind="press" data-slope="<?= $pSlope ?>" data-horizon="180"></canvas>
  <div class="legend">value <i style="background:#2f81f7"></i> 30-sample mean <i style="background:#8b949e"></i> forecast <i style="background:#d29922"></i></div></div>

<div class="card"><table>
<thead><tr><th>received (<?= htmlspecialchars($tzShort) ?>)</th><th>temp °C</th><th>pressure hPa</th><th>alt m</th><th>rssi</th></tr></thead>
<tbody>
<?php foreach ($rows as $r): ?>
<tr><td><?= htmlspecialchars(local_str((int)$r['received_epoch'], $tzObj)) ?></td>
    <td><?= number_format((float)$r['temp_c'], 2) ?></td>
    <td><?= number_format((float)$r['press_hpa'], 2, '.', ',') ?></td>
    <td><?= $r['altitude_m'] === null ? '—' : number_format((float)$r['altitude_m'], 1) ?></td>
    <td><?= $r['rssi'] === null ? '—' : (int)$r['rssi'] ?></td></tr>
<?php endforeach; if (!$rows): ?>
<tr><td colspan="5">no data yet</td></tr>
<?php endif; ?>
</tbody></table>
<p class="muted" style="margin:10px 0 0">
 <a href="?format=csv&token=<?= $tok ?>">export.csv</a> ·
 <a href="?format=json&hours=<?= $hours ?>&token=<?= $tok ?>">JSON <?= $ranges[$hours] ?? ($hours . ' h') ?></a>
</p></div>

<script>
const token = <?= json_encode(isset($_GET['token']) ? $_GET['token'] : (string)($_COOKIE['bmp280_token'] ?? '')) ?>;
const hours = <?= (int)$hours ?>;
const tzName = <?= json_encode($tzName) ?>;
const tzShort = <?= json_encode($tzShort) ?>;

function clockLabel(iso, withDate){
  const d = new Date(iso);
  if (isNaN(d)) return '';
  const opt = { timeZone: tzName, hour: '2-digit', minute: '2-digit', hour12: false };
  if (withDate) { opt.day = '2-digit'; opt.month = 'short'; }
  return d.toLocaleString('en-GB', opt) + ' ' + tzShort;
}

// ------------------- station map: OSM tiles + Nominatim search -------------------
const startLat = <?= json_encode($lat !== '' ? (float)$lat : null) ?>;
const startLon = <?= json_encode($lon !== '' ? (float)$lon : null) ?>;
let map = null, marker = null;
let picked = { lat: startLat, lon: startLon, label: '' };

function setMarker(){
  if (!map) return;
  if (marker) marker.setLatLng([picked.lat, picked.lon]);
  else marker = L.marker([picked.lat, picked.lon]).addTo(map);
}

function initMap(){
  const msg = document.getElementById('locmsg');
  if (!window.L) { msg.textContent = 'map library blocked'; return; }
  const centre = picked.lat !== null ? [picked.lat, picked.lon] : [22.5726, 88.3639];
  map = L.map('map').setView(centre, picked.lat !== null ? 13 : 5);
  L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
    maxZoom: 19, attribution: '&copy; OpenStreetMap contributors'
  }).addTo(map);
  if (picked.lat !== null) marker = L.marker(centre).addTo(map);
  map.on('click', e => {
    picked.lat = e.latlng.lat; picked.lon = e.latlng.lng; picked.label = '';
    setMarker();
    msg.textContent = 'picked ' + picked.lat.toFixed(4) + ', ' + picked.lon.toFixed(4) + ' — press Save location';
  });
}

async function searchPlace(){
  const q = document.getElementById('q').value.trim();
  const msg = document.getElementById('locmsg');
  if (!q) return;
  msg.textContent = 'searching…';
  try {
    const r = await fetch('https://nominatim.openstreetmap.org/search?format=json&limit=1&q=' + encodeURIComponent(q));
    const a = await r.json();
    if (!a.length) { msg.textContent = 'no match for “' + q + '”'; return; }
    picked = { lat: parseFloat(a[0].lat), lon: parseFloat(a[0].lon), label: a[0].display_name };
    map.setView([picked.lat, picked.lon], 13);
    setMarker();
    msg.textContent = a[0].display_name;
  } catch (e) { msg.textContent = 'search failed'; }
}

async function saveLoc(){
  const msg = document.getElementById('locmsg');
  if (picked.lat === null || picked.lon === null) { msg.textContent = 'pick a spot first'; return; }
  msg.textContent = 'saving…';
  try {
    const r = await fetch('?set_location=1&token=' + encodeURIComponent(token), {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ lat: picked.lat, lon: picked.lon, label: picked.label })
    });
    const d = await r.json();
    if (!d.ok) { msg.textContent = 'error: ' + (d.error || 'unknown'); return; }
    msg.textContent = 'saved ' + d.lat.toFixed(4) + ', ' + d.lon.toFixed(4)
      + (d.elev != null ? ' · ' + Math.round(d.elev) + ' m' : '');
    setTimeout(() => location.reload(), 1200);
  } catch (e) { msg.textContent = 'save failed'; }
}

initMap();

function mean(arr, win){
  return arr.map((_, i) => {
    const from = Math.max(0, i - win + 1);
    const slice = arr.slice(from, i + 1);
    return slice.reduce((a, b) => a + b, 0) / slice.length;
  });
}

function draw(canvas, values, colour, slope, horizonMin, stepMin, startISO, endISO){
  const w = canvas.clientWidth, h = canvas.clientHeight || 220, dpr = devicePixelRatio || 1;
  canvas.width = w * dpr; canvas.height = h * dpr;
  const g = canvas.getContext('2d');
  g.setTransform(dpr, 0, 0, dpr, 0, 0); g.clearRect(0, 0, w, h);
  if (values.length < 2) { g.fillStyle = '#8b949e'; g.font = '12px system-ui'; g.fillText('collecting…', 10, h/2); return; }

  const fc = values[values.length - 1] + slope * (horizonMin / 60);
  const all = values.concat([fc]);
  let min = Math.min(...all), max = Math.max(...all);
  if (max - min < 1) { const m = (max + min) / 2; min = m - 1; max = m + 1; }

  const padL = 46, padR = 12, padT = 12, padB = 18;
  const totalMin = (values.length - 1) * stepMin + horizonMin;
  const xOf = m => padL + (w - padL - padR) * m / totalMin;
  const yOf = v => h - padB - (h - padT - padB) * (v - min) / (max - min);

  g.strokeStyle = 'rgba(139,148,158,.16)'; g.lineWidth = 1;
  for (let k = 0; k <= 4; k++) {
    const yy = padT + (h - padT - padB) * k / 4;
    g.beginPath(); g.moveTo(padL, yy); g.lineTo(w - padR, yy); g.stroke();
    g.fillStyle = '#8b949e'; g.font = '10px ui-monospace,monospace';
    g.fillText((max - (max - min) * k / 4).toFixed(1), 2, yy + 3);
  }

  const nowX = xOf((values.length - 1) * stepMin);
  g.strokeStyle = 'rgba(139,148,158,.35)'; g.setLineDash([4, 3]);
  g.beginPath(); g.moveTo(nowX, padT); g.lineTo(nowX, h - padB); g.stroke();
  g.setLineDash([]);
  g.fillStyle = '#8b949e'; g.fillText('now', nowX + 4, padT + 10);

  const line = (arr, offsetMin, col, width, dash) => {
    g.beginPath();
    arr.forEach((v, i) => {
      const xx = xOf(offsetMin + i * stepMin), yy = yOf(v);
      i ? g.lineTo(xx, yy) : g.moveTo(xx, yy);
    });
    g.strokeStyle = col; g.lineWidth = width; g.setLineDash(dash || []);
    g.stroke(); g.setLineDash([]);
  };

  line(mean(values, 30), 0, 'rgba(139,148,158,.75)', 1.2);
  line(values, 0, colour, 1.8);

  // Forecast: straight extrapolation from "now" to "now + horizon".
  g.beginPath();
  g.moveTo(nowX, yOf(values[values.length - 1]));
  g.lineTo(xOf((values.length - 1) * stepMin + horizonMin), yOf(fc));
  g.strokeStyle = '#d29922'; g.lineWidth = 1.6; g.setLineDash([5, 4]);
  g.stroke(); g.setLineDash([]);

  g.fillStyle = '#d29922'; g.font = '10px monospace';
  g.fillText(fc.toFixed(2), Math.min(nowX + 6, w - 46), yOf(fc) - 6);

  // Time axis: first and last sample in the configured zone.
  g.fillStyle = '#8b949e'; g.font = '10px ui-monospace,monospace';
  g.fillText(clockLabel(startISO, false), padL, h - 4);
  const endLbl = clockLabel(endISO, false);
  g.fillText(endLbl, w - padR - g.measureText(endLbl).width, h - 4);
}

async function load(){
  const d = await (await fetch('?format=json&hours=' + hours + '&limit=5000&token=' + encodeURIComponent(token))).json();
  const s = d.samples;
  if (!s.length) return;
  const span = Math.max(1, (new Date(s[s.length-1].received_at) - new Date(s[0].received_at)) / 1000);
  const stepMin = Math.max(0.5, span / 60 / Math.max(1, s.length - 1));

  const ct = document.getElementById('ct'), cp = document.getElementById('cp');
  const first = s[0].received_at, last = s[s.length - 1].received_at;
  draw(ct, s.map(r => r.temp_c), '#3fb950', parseFloat(ct.dataset.slope), 60, stepMin, first, last);
  draw(cp, s.map(r => r.press_hpa), '#2f81f7', parseFloat(cp.dataset.slope), 180, stepMin, first, last);
}
load(); setInterval(load, 30000);
window.addEventListener('resize', () => load());
</script></body></html>