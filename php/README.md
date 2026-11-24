# Single-file PHP collector (simplest option)

One file. No dependencies, no build, no systemd, no nginx edits, no SQL to run — the table
creates itself on the first request. CloudPanel provides the vhost and the certificate.

## 1. Create the site

CloudPanel → **Sites → Add Site → PHP Site** → domain `sensors.example.com` → Create.
Then **SSL/TLS → Actions → New Let's Encrypt Certificate**.

## 2. Create the database

CloudPanel → **Databases → Add Database**:

- Name: `bmp280`
- User: `bmp280` (CloudPanel may prefix both with the site user — **copy exactly what it shows**)
- Password: generate, copy it

## 3. Put the files in place

Copy `bmp280.php` to the site's document root:

```
/home/<site-user>/htdocs/sensors.example.com/bmp280.php
```

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