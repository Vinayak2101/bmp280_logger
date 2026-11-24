# ESP32 BMP280 Wi-Fi Logger

An ESP32 reads a **BMP280 / GY-BM E/P 280** (temperature + barometric pressure) over I²C and,
every X seconds, POSTs the reading as JSON to a webhook, while serving a local dashboard with
live values, history charts and a settings page. Samples are queued and retried if the network
or the endpoint is down, so nothing is lost during an outage.

A dependency-free Python collector (`vps/`) receives those posts on a server, stores them in
SQLite and exposes a JSON/CSV API plus a small chart page.

```
 BMP280 ──I²C──▶ ESP32 ──HTTPS POST──▶ collector (nginx → Python → SQLite)
                   │
                   └── web dashboard (chart, history, settings)
```

## Repository layout

```
firmware/     Arduino sketch for the ESP32 (bmp280_logger.ino, web_page.h)
vps/          collector: webhook.py, systemd unit, install.sh, nginx snippet
```

## Hardware

| Module pin (GY-BM E/P 280) | ESP32 | Notes |
| --- | --- | --- |
| VCC | 3V3 | **3.3 V only** — this breakout has no regulator |
| GND | GND | |
| SCL | GPIO22 | I²C clock |
| SDA | GPIO21 | I²C data |
| CSB | — | internal pull-up keeps the chip in I²C mode |
| SDO | — | address hardwired to `0x76` |

Four wires. The firmware also probes `0x77` and accepts a BME280 (chip ID `0x60`), reporting
temperature and pressure only.

Verified on an ESP32-D0WD-V3 (4 MB flash) with `arduino-cli 1.6.0` and core `esp32:esp32 4.0.0`.

## Build and flash

```bash
# dependencies
arduino-cli core install esp32:esp32 --additional-urls https://espressif.github.io/arduino-esp32/package_esp32_index.json
arduino-cli lib install "Adafruit BMP280 Library" "Adafruit Unified Sensor"

# optional: compiled-in Wi-Fi defaults, so a fresh board joins your LAN on first boot
cp firmware/secrets.h.example firmware/secrets.h   # then edit

arduino-cli compile --fqbn 'esp32:esp32:esp32:PartitionScheme=huge_app' firmware
arduino-cli upload  -p /dev/ttyUSB0 --fqbn 'esp32:esp32:esp32:PartitionScheme=huge_app' firmware
```

`huge_app` is required: the image is ~1.24 MB (Wi-Fi + TLS + web server) and does not fit the
default 1.3 MB app partition with room to spare.

Serial console is 115200 baud — one JSON line per sample, plus a boot banner with the I²C scan.

## Provisioning

Without stored credentials the board raises an access point:

- SSID `ESP32-Setup-XXXX` (last two MAC bytes), password `sensors123`
- open `http://192.168.4.1`, enter a **2.4 GHz** SSID/password, webhook URL, token and interval

Settings are saved to NVS and survive reboots. `firmware/secrets.h` is only the fallback used
when NVS is empty — once provisioned, the file can be deleted.

## HTTP API (on the device)

| Method | Path | Purpose |
| --- | --- | --- |
| GET | `/` | dashboard: charts, settings form, recent samples |
| GET | `/data` | current status + last sample |
| GET | `/history` | RAM ring buffer (360 samples) |
| GET | `/scan` | live I²C probe: addresses found, bus line levels, hint |
| GET | `/settings` | current settings (secrets redacted) |
| POST | `/settings` | save settings (form-urlencoded) |
| POST | `/test` | take one reading and POST it now |
| POST | `/reboot` | restart |
| GET | `/health` | `{"ok":true}` |

`/scan` is the wiring diagnostic: it reports `float_sda/float_scl` with the ESP32's pull-ups
disabled, so you can tell "module unpowered" from "module powered but not ACKing".

## Webhook payload

```json
{"device":"esp32-bmp280","seq":42,"ts":"2026-11-24T12:40:05Z","uptime_s":1234,
 "temp_c":23.41,"press_hpa":1008.32,"sea_level_hpa":1013.90,"altitude_m":46.2,
 "rssi":-57,"heap":210000}
```

- `ts` is `null` until NTP syncs.
- `sea_level_hpa` uses the configured reference altitude (`P0 = P / (1 - h/44330)^5.255`).
- `altitude_m` is derived against the standard 1013.25 hPa reference.
- Optional `Authorization: Bearer <token>` header.
- Failures are queued (up to 20) and retried with exponential backoff.

## Collector (VPS)

See [`vps/README.md`](vps/README.md) for the short version, and
**[`docs/DEPLOY.md`](docs/DEPLOY.md)** for the full CloudPanel walkthrough — DNS, site type,
certificate, install, nginx, verification commands with expected output, backups, token
rotation and a troubleshooting table.

```bash
scp -r vps root@server:/root/bmp280 && ssh root@server 'cd /root/bmp280 && bash install.sh'
```

Then proxy it with the snippet in `vps/nginx-location.conf` and point the device at
`https://<domain>/sensors/ingest` with the generated bearer token.

## Implementation notes

- TLS to HTTPS webhooks uses an unverified client (`setInsecure`) — the ESP32 core's CA store
  cannot validate most Let's Encrypt endpoints. The bearer token authenticates the request.
- BMP280 is read in forced mode (16× pressure oversampling, IIR ×16) once per interval.
- The dashboard HTML lives in `web_page.h`, not in the `.ino`: the Arduino prototype generator
  mangles large raw string literals inside a sketch.
- History is RAM-only; the VPS collector is the durable record.

## License

MIT — see [LICENSE](LICENSE).