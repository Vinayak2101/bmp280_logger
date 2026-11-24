#!/usr/bin/env python3
"""
BMP280 webhook collector — dependency-free (Python 3 stdlib only).

  POST /ingest        store one reading (Bearer token required)
  GET  /latest        newest reading
  GET  /series        ?hours=24&device=...&limit=5000  -> JSON array
  GET  /export.csv    all readings as CSV
  GET  /health        {"ok":true}
  GET  /              small dashboard (chart + table)

Env: BMP280_DB, BMP280_TOKEN, BMP280_HOST, BMP280_PORT
"""

import csv
import hmac
import io
import json
import os
import sqlite3
import sys
import time
from datetime import datetime, timezone
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from urllib.parse import parse_qs, urlparse

DB = os.environ.get("BMP280_DB", "/var/lib/bmp280/bmp280.db")
TOKEN = os.environ.get("BMP280_TOKEN", "")
HOST = os.environ.get("BMP280_HOST", "127.0.0.1")
PORT = int(os.environ.get("BMP280_PORT", "8787"))
MAX_BODY = 8192

SCHEMA = """
CREATE TABLE IF NOT EXISTS readings (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    device        TEXT    NOT NULL,
    seq           INTEGER,
    sensor_ts     TEXT,
    uptime_s      INTEGER,
    temp_c        REAL    NOT NULL,
    press_hpa     REAL    NOT NULL,
    sea_level_hpa REAL,
    altitude_m    REAL,
    rssi          INTEGER,
    heap          INTEGER,
    received_epoch INTEGER NOT NULL,
    client_ip     TEXT
);
CREATE INDEX IF NOT EXISTS idx_readings_id ON readings(id);
CREATE INDEX IF NOT EXISTS idx_readings_device ON readings(device, id);
"""

INDEX_PAGE = """<!doctype html>
<html lang="en"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>BMP280 readings</title>
<style>
body{font:15px/1.5 system-ui,sans-serif;background:#0e1116;color:#e6edf3;margin:0;padding:20px}
h1{font-size:18px;margin:0 0 12px}
.card{background:#161b22;border:1px solid #21262d;border-radius:10px;padding:14px;margin-bottom:14px}
canvas{width:100%;height:220px;display:block}
table{width:100%;border-collapse:collapse;font-size:13px;font-variant-numeric:tabular-nums}
th,td{padding:6px;border-bottom:1px solid #21262d;text-align:right}
th{color:#8b949e;font-size:11px;text-transform:uppercase;letter-spacing:.6px}
td:first-child,th:first-child{text-align:left;color:#8b949e}
a{color:#2f81f7}
</style></head><body>
<h1>BMP280 readings</h1>
<div class="card"><canvas id="c"></canvas></div>
<div class="card">
<table><thead><tr><th>received</th><th>device</th><th>temp &deg;C</th><th>pressure hPa</th><th>alt m</th></tr></thead>
<tbody id="rows"></tbody></table>
<p style="color:#8b949e;font-size:12px"><a id="csv" href="export.csv">export.csv</a> &middot; <a id="json" href="series?hours=24">JSON</a></p>
</div>
<script>
const $=id=>document.getElementById(id);
// Token is passed on the page URL once:  /sensors/?token=<token>
const T = new URLSearchParams(location.search).get('token') || '';
const Q = T ? ('&token=' + encodeURIComponent(T)) : '';
if (T) { $('csv').href += '?token=' + encodeURIComponent(T); $('json').href += Q; }
async function load(){
  const d = await (await fetch('series?hours=24&limit=2000' + Q)).json();
  const rows = d.samples.slice(-30).reverse().map(s=>`<tr><td>${s.received_at}</td><td>${s.device}</td><td>${s.temp_c.toFixed(2)}</td><td>${s.press_hpa.toFixed(2)}</td><td>${(s.altitude_m||0).toFixed(1)}</td></tr>`).join('');
  $('rows').innerHTML = rows || '<tr><td colspan="5">no data yet</td></tr>';
  draw(d.samples.map(s=>s.temp_c), d.samples.map(s=>s.press_hpa));
}
function draw(t,p){
  const c=$('c'), w=c.clientWidth, h=220, dpr=devicePixelRatio||1;
  c.width=w*dpr; c.height=h*dpr;
  const g=c.getContext('2d'); g.setTransform(dpr,0,0,dpr,0,0); g.clearRect(0,0,w,h);
  if(t.length<2){g.fillStyle='#8b949e';g.fillText('waiting for data...',10,h/2);return;}
  const series=[{v:p,color:'#2f81f7',label:'hPa'},{v:t,color:'#3fb950',label:'°C'}];
  let all=[].concat(t,p), min=Math.min(...all), max=Math.max(...all);
  if(max-min<1){const m=(max+min)/2;min=m-1;max=m+1;}
  const pad=10, x=i=>pad+(w-2*pad)*i/(t.length-1), y=v=>h-pad-(h-2*pad)*(v-min)/(max-min);
  for(const s of series){
    g.beginPath(); s.v.forEach((v,i)=> i?g.lineTo(x(i),y(v)):g.moveTo(x(i),y(v)));
    g.strokeStyle=s.color; g.lineWidth=1.8; g.stroke();
  }
  g.fillStyle='#8b949e'; g.font='10px monospace';
  g.fillText(max.toFixed(1),4,10); g.fillText(min.toFixed(1),4,h-2);
}
load(); setInterval(load, 15000);
</script></body></html>
"""


def connect():
    conn = sqlite3.connect(DB, timeout=10)
    conn.row_factory = sqlite3.Row
    conn.execute("PRAGMA journal_mode=WAL")
    conn.execute("PRAGMA busy_timeout=5000")
    return conn


def token_ok(header_value, query):
    supplied = ""
    if header_value and header_value.lower().startswith("bearer "):
        supplied = header_value[7:].strip()
    if not supplied and query.get("token"):
        supplied = query["token"][0]
    if not TOKEN or not supplied:
        return False
    return hmac.compare_digest(supplied, TOKEN)


def iso(epoch):
    return datetime.fromtimestamp(epoch, timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ")


def valid_reading(obj):
    try:
        temp = float(obj["temp_c"])
        press = float(obj["press_hpa"])
    except (KeyError, TypeError, ValueError):
        return None
    if not (-60.0 <= temp <= 100.0) or not (300.0 <= press <= 1100.0):
        return None
    return temp, press


class Handler(BaseHTTPRequestHandler):
    server_version = "bmp280-collector/1.0"

    def log_message(self, fmt, *args):
        sys.stderr.write("%s - %s\n" % (self.address_string(), fmt % args))

    def send_json(self, code, payload):
        body = json.dumps(payload).encode()
        self.send_response(code)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(body)))
        self.end_headers()
        self.wfile.write(body)

    def send_text(self, code, body, ctype="text/html; charset=utf-8"):
        data = body.encode() if isinstance(body, str) else body
        self.send_response(code)
        self.send_header("Content-Type", ctype)
        self.send_header("Content-Length", str(len(data)))
        self.end_headers()
        self.wfile.write(data)

    def do_GET(self):
        u = urlparse(self.path)
        q = parse_qs(u.query)
        path = u.path.rstrip("/") or "/"

        if path == "/health":
            return self.send_json(200, {"ok": True, "count": self.count()})

        if not token_ok(self.headers.get("Authorization"), q):
            return self.send_json(401, {"ok": False, "error": "unauthorized"})

        if path == "/":
            return self.send_text(200, INDEX_PAGE)

        if path == "/latest":
            with connect() as conn:
                row = conn.execute("SELECT * FROM readings ORDER BY id DESC LIMIT 1").fetchone()
            if not row:
                return self.send_json(200, {"ok": True, "reading": None})
            return self.send_json(200, {"ok": True, "reading": self.row_dict(row)})

        if path == "/series":
            hours = 24
            try:
                hours = max(1, min(24 * 365, int(q.get("hours", ["24"])[0])))
            except ValueError:
                pass
            limit = 5000
            try:
                limit = max(1, min(20000, int(q.get("limit", ["5000"])[0])))
            except ValueError:
                pass
            since = int(time.time()) - hours * 3600
            sql = "SELECT * FROM readings WHERE received_epoch >= ?"
            args = [since]
            if q.get("device"):
                sql += " AND device = ?"
                args.append(q["device"][0])
            sql += " ORDER BY id ASC LIMIT ?"
            args.append(limit)
            with connect() as conn:
                rows = conn.execute(sql, args).fetchall()
            return self.send_json(200, {"ok": True, "hours": hours,
                                        "samples": [self.row_dict(r) for r in rows]})

        if path == "/export.csv":
            with connect() as conn:
                rows = conn.execute("SELECT * FROM readings ORDER BY id ASC").fetchall()
            buf = io.StringIO()
            w = csv.writer(buf)
            w.writerow(["received_at", "device", "seq", "sensor_ts", "uptime_s", "temp_c",
                        "press_hpa", "sea_level_hpa", "altitude_m", "rssi", "heap", "client_ip"])
            for r in rows:
                w.writerow([iso(r["received_epoch"]), r["device"], r["seq"], r["sensor_ts"],
                            r["uptime_s"], r["temp_c"], r["press_hpa"], r["sea_level_hpa"],
                            r["altitude_m"], r["rssi"], r["heap"], r["client_ip"]])
            return self.send_text(200, buf.getvalue(), "text/csv; charset=utf-8")

        return self.send_json(404, {"ok": False, "error": "not found"})

    def do_POST(self):
        u = urlparse(self.path)
        q = parse_qs(u.query)
        path = u.path.rstrip("/") or "/"

        if path != "/ingest":
            return self.send_json(404, {"ok": False, "error": "not found"})

        if not token_ok(self.headers.get("Authorization"), q):
            return self.send_json(401, {"ok": False, "error": "unauthorized"})

        try:
            length = int(self.headers.get("Content-Length", "0"))
        except ValueError:
            length = 0
        if length <= 0 or length > MAX_BODY:
            return self.send_json(400, {"ok": False, "error": "bad content length"})

        raw = self.rfile.read(length)
        try:
            obj = json.loads(raw.decode("utf-8"))
        except (UnicodeDecodeError, json.JSONDecodeError):
            return self.send_json(400, {"ok": False, "error": "invalid json"})

        parsed = valid_reading(obj)
        if not parsed:
            return self.send_json(422, {"ok": False, "error": "missing or out-of-range temp_c/press_hpa"})
        temp, press = parsed

        device = str(obj.get("device") or "unknown")[:64]
        try:
            seq = int(obj["seq"]) if obj.get("seq") is not None else None
        except (TypeError, ValueError):
            seq = None

        def num(key, cast=float):
            try:
                v = obj.get(key)
                return None if v is None else cast(v)
            except (TypeError, ValueError):
                return None

        row = (device, seq, str(obj.get("ts") or "") or None, num("uptime_s", int),
               temp, press, num("sea_level_hpa"), num("altitude_m"),
               num("rssi", int), num("heap", int), int(time.time()), self.client_address[0])

        with connect() as conn:
            cur = conn.execute(
                "INSERT INTO readings (device, seq, sensor_ts, uptime_s, temp_c, press_hpa,"
                " sea_level_hpa, altitude_m, rssi, heap, received_epoch, client_ip)"
                " VALUES (?,?,?,?,?,?,?,?,?,?,?,?)", row)
            new_id = cur.lastrowid
        self.send_json(200, {"ok": True, "id": new_id})

    def count(self):
        with connect() as conn:
            return conn.execute("SELECT COUNT(*) FROM readings").fetchone()[0]

    @staticmethod
    def row_dict(r):
        return {
            "id": r["id"], "device": r["device"], "seq": r["seq"],
            "ts": r["sensor_ts"], "received_at": iso(r["received_epoch"]),
            "uptime_s": r["uptime_s"], "temp_c": r["temp_c"], "press_hpa": r["press_hpa"],
            "sea_level_hpa": r["sea_level_hpa"], "altitude_m": r["altitude_m"],
            "rssi": r["rssi"], "heap": r["heap"],
        }


def main():
    os.makedirs(os.path.dirname(DB) or ".", exist_ok=True)
    with connect() as conn:
        conn.executescript(SCHEMA)
    if not TOKEN:
        print("WARNING: BMP280_TOKEN is empty - every request will be rejected", file=sys.stderr)
    print("listening on http://%s:%d  db=%s" % (HOST, PORT, DB), flush=True)
    ThreadingHTTPServer((HOST, PORT), Handler).serve_forever()


if __name__ == "__main__":
    main()