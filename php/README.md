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

## 3. Put the file in place

Copy `bmp280.php` to the site's document root:

```
/home/<site-user>/htdocs/sensors.example.com/bmp280.php
```

Use CloudPanel's **File Manager** (Sites → domain → File Manager) or SFTP.

Then edit the five constants at the top of the file:

```php
const DB_HOST = 'localhost';
const DB_NAME = 'bmp280';          // exactly as created
const DB_USER = 'bmp280';          // exactly as created
const DB_PASS = 'the-password';
const TOKEN   = 'paste output of: openssl rand -hex 24';
```

Set permissions: `640` for the file is enough (it is web-served, so don't use 600).

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