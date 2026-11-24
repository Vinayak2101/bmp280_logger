# Single-file PHP collector (simplest option)

One file. No dependencies, no build, no systemd, no nginx edits, no SQL to run — the table
creates itself on the first request. CloudPanel provides the vhost and the certificate.

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