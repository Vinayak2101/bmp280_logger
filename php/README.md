# Single-file PHP collector (simplest option)

One file. No dependencies, no build, no systemd, no nginx edits, no SQL to run — the table
creates itself on the first request. CloudPanel provides the vhost and the certificate.

## Map: pick the station by searching

The dashboard has an **OpenStreetMap** card (Leaflet + OSM tiles):

- type a place in the search box → Nominatim geocodes it, the map jumps there and drops a marker
- or click anywhere on the map to place the marker
- **Save location** stores lat/lon/label in the database (`config` table) and looks up the ground
  elevation from Open-Meteo's free elevation API, so the sea-level pressure card starts working
  without you entering a height by hand

Saved-in-DB location wins over `.env`; `.env` remains the fallback for a fresh install.
`GET ?format=config` returns the current values, `POST ?set_location=1` (JSON `{lat,lon,label}`)
sets them.

## Weather comparison (Open-Meteo, no API key)

When lat/lon are set, the page fetches a forecast from
[open-meteo.com](https://open-meteo.com/) — cached 15 minutes in the DB, so it costs one request
per quarter hour:

- **Forecast now** card: model temperature and mean-sea-level pressure next to your measurement,
  plus `measured − forecast` deltas (green = you read higher)
- **Next hours** table: the following six hours, in your configured timezone

This gives an independent reference for "is my sensor drifting?" — a persistent 5 hPa offset
usually means the station elevation is wrong, not a broken sensor.

Rate limits (both services are free and keyless): Nominatim allows ~1 request/second and OSM
tiles are for low-volume use; a personal dashboard is well within both.

## Location, timezone and sea-level pressure

Optional keys in `.env` (everything falls back to UTC / no location):

```ini
BMP280_TZ=Asia/Kolkata        # IANA zone name — display only
BMP280_LOCATION=City, IN      # label in the dashboard header
BMP280_ELEV_M=18              # station height above sea level, metres
BMP280_LAT=22.57              # optional, adds a map link
BMP280_LON=88.36
```

- The database **always stores UTC** (`received_epoch`); the zone is applied at render time,
  so changing `BMP280_TZ` retro-actively relabels history instead of corrupting it.
- **No zone configured?** the dashboard adopts the zone that Open-Meteo resolves for your
  coordinates (the API is called with `timezone=auto`, returning e.g. `Asia/Kolkata`), and the
  map's *Save location* button also stores your browser's zone. Setting `BMP280_TZ` explicitly
  always wins.
- `BMP280_ELEV_M` enables the **Sea-level pressure** card: station pressure is reduced with
  `P0 = P / (1 - h/44330)^5.255`, which is what weather services report. At 18 m the correction
  is about +2 hPa.
- Charts label the first and last sample with local clock times; the table column header shows
  the zone abbreviation (e.g. `IST`).

## What the page shows

- 1 h / 6 h / 24 h / 7 d / 30 d range pills
- current temperature and pressure with 1 h / 24 h deltas and a trend arrow
- min–max over the selected range, average pressure, sample count
- **analysis**: WMO-style 3 h pressure tendency (rising / falling / steady with a rapid-change
  class), least-squares temperature slope in °C/h, and a comparison against 1 h and 3 h ago
- **forecast**: linear extrapolation of the last 2 h of data — temperature +1 h, pressure +3 h,
  drawn as a dashed amber continuation of the line, with the fit residual shown as σ
- charts per quantity with the raw series, a 30-sample mean, and a "now" marker
- last 25 readings table, plus `export.csv` and a JSON range endpoint

The forecast is deliberately a straight-line extrapolation, not a weather model: it is
honest about being short-horizon. Pressure tendency is the genuinely predictive signal —
falling pressure usually means deteriorating weather.

## 1. Create the site

CloudPanel → **Sites → Add Site → PHP Site** → domain `sensors.example.com` → Create.
Then **SSL/TLS → Actions → New Let's Encrypt Certificate**.

## 2. Create the database

CloudPanel → **Databases → Add Database**:

- Name: `bmp280`
- User: `bmp280` (CloudPanel may prefix both with the site user — **copy exactly what it shows**)
- Password: generate, copy it

## 3. Put the files in place

Copy `bmp280.php` **and** `index.php` to the site's document root:

```
/home/<site-user>/htdocs/sensors.example.com/bmp280.php   <- device webhook target
/home/<site-user>/htdocs/sensors.example.com/index.php    <- dashboard at the root URL
```

`index.php` just requires `bmp280.php`, so the dashboard is `https://<domain>/` and the device
keeps posting to `https://<domain>/bmp280.php`. Opening the root URL in a browser asks for the
token once, then stores it in an HttpOnly cookie and remembers the browser for 90 days.

Copy `.env.example` to `.env` **one level above the document root**, so it can never be
downloaded over HTTP:

```
/home/<site-user>/htdocs/.env
```

Then fill it in:

```ini
BMP280_DB_HOST=localhost
BMP280_DB_NAME=bmp280          # exactly as created
BMP280_DB_USER=bmp280          # exactly as created
BMP280_DB_PASS=the-password
BMP280_TOKEN=<openssl rand -hex 24>
```

The script looks for `.env` next to itself or one directory above it, and falls back to
`localhost`/`bmp280`/`CHANGE-ME` if neither exists. Permissions: `640` for both files, owner the
site user. `bmp280.php` must be readable by PHP-FPM; `.env` should not be web-readable at all —
if in doubt, `chmod 640` and confirm `https://sensors.example.com/.env` returns 403/404.

## 4. Check it

```bash
curl -s 'https://sensors.example.com/bmp280.php?health=1'
# {"ok":true,"count":0}

curl -s -X POST 'https://sensors.example.com/bmp280.php' \
  -H "Authorization: Bearer <TOKEN>" -H 'Content-Type: application/json' \
  -d '{"device":"curl","seq":1,"temp_c":25.4,"press_hpa":1009.2}'
# {"ok":true,"id":1}

curl -s 'https://sensors.example.com/bmp280.php?token=<TOKEN>' | head -20   # the page
```

Open the page in a browser: `https://sensors.example.com/bmp280.php?token=<TOKEN>`

## 5. Point the ESP32 at it

Dashboard → **Settings**:

| Field | Value |
| --- | --- |
| Webhook URL | `https://sensors.example.com/bmp280.php` |
| Bearer token | the same TOKEN |
| Reporting interval | `30` |

**Send test** → `Webhook OK (HTTP 200)`.

## Notes

- If you rename the file, update the device URL to match.
- `count` in `?health=1` is your row count; if it stops growing the device can't reach the endpoint.
- Rows are in `readings`; drop them with `TRUNCATE TABLE readings;` via phpMyAdmin if you want a reset.
- The token is a shared secret: anyone with it can post readings (and read them), so don't put the
  page URL in a public place.