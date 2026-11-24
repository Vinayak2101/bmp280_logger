# BMP280 collector for CloudPanel VPS

Small, dependency-free receiver for the ESP32 logger. Stores every posted reading in
SQLite, and gives you JSON / CSV / a tiny chart page.

- `POST /ingest` — store one reading (requires `Authorization: Bearer <token>`)
- `GET /latest`, `GET /series?hours=24`, `GET /export.csv`, `GET /health`
- `GET /` — chart + table (open in a browser; token via `?token=` on first hit is not stored, so protect it with nginx basic-auth if it is public)

No pip packages. Python 3 standard library only. SQLite in WAL mode.

## 1. Copy the files to the VPS

From Windows:

```powershell
scp C:\ESP32\vps\* root@<vps-ip>:/root/bmp280/
```

(or upload via CloudPanel's file manager to `/root/bmp280/`).

## 2. Install

```bash
ssh root@<vps-ip>
cd /root/bmp280
bash install.sh
```

It creates the `bmp280` system user, installs `/opt/bmp280/webhook.py`, generates a random
API token in `/etc/default/bmp280-webhook` (mode 600), starts `bmp280-webhook.service`
bound to `127.0.0.1:8787`, and prints the token.

Check:

```bash
systemctl status bmp280-webhook --no-pager
curl -s http://127.0.0.1:8787/health
journalctl -u bmp280-webhook -n 30
```

## 3. Publish it over HTTPS

Add the `location /sensors/` block from `nginx-location.conf` to your site's vhost in
`/etc/nginx/sites-enabled/`, then:

```bash
nginx -t && systemctl reload nginx
```

Test from anywhere:

```bash
TOKEN=<token from install>
curl -s -X POST https://<domain>/sensors/ingest \
  -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' \
  -d '{"device":"curl-test","seq":1,"temp_c":25.4,"press_hpa":1009.2,"rssi":-50}'
curl -s "https://<domain>/sensors/health?token=$TOKEN"
curl -s "https://<domain>/sensors/series?hours=1&token=$TOKEN"
```

## 4. Point the ESP32 at it

Open `http://192.168.0.156/` → **Settings**:

| Field | Value |
| --- | --- |
| Webhook URL | `https://<domain>/sensors/ingest` |
| Bearer token | the token from step 2 |
| Reporting interval | `30` (or whatever you want, 2–3600 s) |

Save, then press **Send test** — it should report `Webhook OK (HTTP 200)`, and a row
should appear at `https://<domain>/sensors/`.

## Schema

```sql
CREATE TABLE readings (
  id             INTEGER PRIMARY KEY AUTOINCREMENT,
  device         TEXT,      -- "esp32-bmp280"
  seq            INTEGER,   -- sensor sample counter, resets on reboot
  sensor_ts      TEXT,      -- ISO8601 UTC from the ESP32's NTP clock (NULL if unsynced)
  uptime_s       INTEGER,
  temp_c         REAL,
  press_hpa      REAL,
  sea_level_hpa  REAL,
  altitude_m     REAL,
  rssi           INTEGER,
  heap           INTEGER,
  received_epoch INTEGER,   -- server time, seconds
  client_ip      TEXT
);
```

## Notes and limits

- **Retries can duplicate rows.** The device queues up to 20 samples and retries on failure;
  if a POST succeeds but the response is lost, that sample is stored twice. Deduplicate with
  `(device, sensor_ts)` if it matters.
- `received_epoch` is authoritative; `sensor_ts` relies on the ESP32's NTP sync.
- Backups: the DB is `/var/lib/bmp280/bmp280.db` (plus `-wal`/`-shm`). Use
  `sqlite3 bmp280.db ".backup /root/bmp280-backup.db"` for a consistent copy.
- Uninstall: `systemctl disable --now bmp280-webhook && rm -f /etc/systemd/system/bmp280-webhook.service /opt/bmp280/webhook.py`.