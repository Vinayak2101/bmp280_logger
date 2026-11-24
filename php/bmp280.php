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
    }
    return $pdo;
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
    return trim((string)($_GET['token'] ?? ''));
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
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
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
    jout(401, ['ok' => false, 'error' => 'unauthorized']);
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
$rows = $pdo->query('SELECT * FROM readings ORDER BY id DESC LIMIT 25')->fetchAll();
$total = (int)$pdo->query('SELECT COUNT(*) FROM readings')->fetchColumn();
$last = $rows[0] ?? null;
$t = htmlspecialchars($_GET['token'], ENT_QUOTES);
?>
<!doctype html>
<html lang="en"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>BMP280 readings</title>
<style>
body{margin:0;padding:20px;background:#0e1116;color:#e6edf3;font:15px/1.5 system-ui,-apple-system,Segoe UI,sans-serif}
h1{font-size:18px;margin:0 0 12px}
.card{background:#161b22;border:1px solid #21262d;border-radius:10px;padding:14px;margin-bottom:14px}
.big{font-size:26px;font-weight:600}
.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:12px}
canvas{width:100%;height:200px;display:block}
table{width:100%;border-collapse:collapse;font-size:13px;font-variant-numeric:tabular-nums}
th,td{padding:6px;text-align:right;border-bottom:1px solid #21262d}
th{color:#8b949e;font-size:11px;text-transform:uppercase;letter-spacing:.6px}
td:first-child,th:first-child{text-align:left;color:#8b949e}
a{color:#2f81f7}
</style></head><body>
<h1>BMP280 readings</h1>
<div class="grid">
 <div class="card"><div style="color:#8b949e;font-size:11px;text-transform:uppercase">Temperature</div>
   <div class="big"><?= $last ? number_format((float)$last['temp_c'], 2) : '—' ?><span style="font-size:13px;color:#8b949e"> °C</span></div></div>
 <div class="card"><div style="color:#8b949e;font-size:11px;text-transform:uppercase">Pressure</div>
   <div class="big"><?= $last ? number_format((float)$last['press_hpa'], 2) : '—' ?><span style="font-size:13px;color:#8b949e"> hPa</span></div></div>
 <div class="card"><div style="color:#8b949e;font-size:11px;text-transform:uppercase">Altitude</div>
   <div class="big"><?= $last && $last['altitude_m'] !== null ? number_format((float)$last['altitude_m'], 1) : '—' ?><span style="font-size:13px;color:#8b949e"> m</span></div></div>
 <div class="card"><div style="color:#8b949e;font-size:11px;text-transform:uppercase">Stored / last seen</div>
   <div class="big"><?= $total ?></div>
   <div style="font-size:12px;color:#8b949e"><?= $last ? htmlspecialchars(gmdate('Y-m-d H:i:s', (int)$last['received_epoch'])) . ' UTC' : 'no data yet' ?></div></div>
</div>
<div class="card"><canvas id="c"></canvas></div>
<div class="card"><table>
<thead><tr><th>received (UTC)</th><th>device</th><th>temp °C</th><th>pressure hPa</th><th>alt m</th></tr></thead>
<tbody>
<?php foreach ($rows as $r): ?>
<tr><td><?= htmlspecialchars(gmdate('Y-m-d H:i:s', (int)$r['received_epoch'])) ?></td>
    <td><?= htmlspecialchars($r['device']) ?></td>
    <td><?= number_format((float)$r['temp_c'], 2) ?></td>
    <td><?= number_format((float)$r['press_hpa'], 2) ?></td>
    <td><?= $r['altitude_m'] === null ? '—' : number_format((float)$r['altitude_m'], 1) ?></td></tr>
<?php endforeach; if (!$rows): ?>
<tr><td colspan="5">no data yet</td></tr>
<?php endif; ?>
</tbody></table>
<p style="font-size:12px;color:#8b949e">
 <a href="?format=csv&token=<?= $t ?>">export.csv</a> ·
 <a href="?format=json&hours=24&token=<?= $t ?>">JSON 24h</a>
</p></div>
<script>
const T = new URLSearchParams(location.search).get('token') || '';
async function load(){
  const d = await (await fetch('?format=json&hours=24&limit=2000&token=' + encodeURIComponent(T))).json();
  draw(d.samples.map(s => s.temp_c), d.samples.map(s => s.press_hpa));
}
function draw(t, p){
  const c = document.getElementById('c'), w = c.clientWidth, h = 200, dpr = devicePixelRatio || 1;
  c.width = w * dpr; c.height = h * dpr;
  const g = c.getContext('2d'); g.setTransform(dpr,0,0,dpr,0,0); g.clearRect(0,0,w,h);
  if (t.length < 2) { g.fillStyle = '#8b949e'; g.font = '12px system-ui'; g.fillText('collecting…', 10, h/2); return; }
  const all = t.concat(p); let min = Math.min(...all), max = Math.max(...all);
  if (max - min < 1) { const m = (max + min) / 2; min = m - 1; max = m + 1; }
  const pad = 10, x = i => pad + (w - 2*pad) * i / (t.length - 1),
        y = v => h - pad - (h - 2*pad) * (v - min) / (max - min);
  [[p, '#2f81f7'], [t, '#3fb950']].forEach(([arr, colour]) => {
    g.beginPath(); arr.forEach((v, i) => i ? g.lineTo(x(i), y(v)) : g.moveTo(x(i), y(v)));
    g.strokeStyle = colour; g.lineWidth = 1.8; g.stroke();
  });
  g.fillStyle = '#8b949e'; g.font = '10px monospace';
  g.fillText(max.toFixed(1), 4, 10); g.fillText(min.toFixed(1), 4, h - 2);
}
load(); setInterval(load, 15000);
</script></body></html>