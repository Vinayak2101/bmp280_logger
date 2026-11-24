# Firmware

`bmp280_logger.ino` + `web_page.h` (dashboard markup). Build with the Arduino core for ESP32.

## Wiring

```
GY-BM E/P 280          ESP32
  VCC  ──────────────── 3V3     (3.3 V only, no regulator on the breakout)
  GND  ──────────────── GND
  SCL  ──────────────── GPIO22
  SDA  ──────────────── GPIO21
  CSB  (leave open)     internal pull-up → I²C mode
  SDO  (leave open)     address hardwired to 0x76
```

## Build

```bash
arduino-cli lib install "Adafruit BMP280 Library" "Adafruit Unified Sensor"
arduino-cli compile --fqbn 'esp32:esp32:esp32:PartitionScheme=huge_app' .
arduino-cli upload  -p /dev/ttyUSB0 --fqbn 'esp32:esp32:esp32:PartitionScheme=huge_app' .
```

Optionally copy `secrets.h.example` to `secrets.h` and fill in a 2.4 GHz SSID/password; those are
used only when NVS holds no credentials yet. `secrets.h` is git-ignored.

## Behaviour

- Sensor polled every `interval_s` (2–3600 s, default 30), forced-mode measurement.
- Each sample: printed as JSON on serial, pushed to the RAM ring buffer (360 entries), queued
  for the webhook, then POSTed (queue retries with backoff, max 20 entries).
- No sensor → retry every 10 s, dashboard keeps serving, nothing crashes.
- No Wi-Fi → setup AP `ESP32-Setup-XXXX` (password `sensors123`) at `http://192.168.4.1`.

## Diagnostics

`GET /scan` returns the live bus state:

```json
{"count":1,"addresses":"0x76","float_sda":1,"float_scl":1,"idle_sda":1,"idle_scl":1,
 "sensor_ok":true,"hint":"device ACKs on the bus"}
```

`float_*` is read with the ESP32's internal pull-ups **disabled** — if both are `0`, nothing on
the bus is pulling up, which normally means the module is unpowered or not actually connected.