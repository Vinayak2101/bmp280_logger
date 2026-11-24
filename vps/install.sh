#!/usr/bin/env bash
# Install the BMP280 webhook collector on a CloudPanel / Debian-Ubuntu VPS.
# Run as root:  sudo bash install.sh
set -euo pipefail

[[ $EUID -eq 0 ]] || { echo "run as root (sudo bash install.sh)"; exit 1; }
SRC="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

command -v python3 >/dev/null || { echo "python3 missing: apt install -y python3"; exit 1; }

id bmp280 >/dev/null 2>&1 || useradd --system --home /var/lib/bmp280 --shell /usr/sbin/nologin bmp280
install -d -o bmp280 -g bmp280 -m 750 /var/lib/bmp280
install -d -m 755 /opt/bmp280
install -m 755 "$SRC/webhook.py" /opt/bmp280/webhook.py
install -m 644 "$SRC/bmp280-webhook.service" /etc/systemd/system/bmp280-webhook.service

if [[ ! -f /etc/default/bmp280-webhook ]]; then
  TOKEN="$(openssl rand -hex 24)"
  umask 077
  cat > /etc/default/bmp280-webhook <<EOF
BMP280_DB=/var/lib/bmp280/bmp280.db
BMP280_HOST=127.0.0.1
BMP280_PORT=8787
BMP280_TOKEN=$TOKEN
EOF
  umask 022
fi

systemctl daemon-reload
systemctl enable --now bmp280-webhook
sleep 1

TOKEN="$(sed -n 's/^BMP280_TOKEN=//p' /etc/default/bmp280-webhook)"

echo
echo "== service =="
systemctl --no-pager --lines=0 is-active bmp280-webhook || true
echo "== health =="
curl -fsS "http://127.0.0.1:8787/health" || echo "(not answering - check: journalctl -u bmp280-webhook -n 50)"
echo
echo "== token (keep secret) =="
echo "$TOKEN"
echo
echo "Next: put it behind nginx (see nginx-location.conf), then set on the ESP32:"
echo "  webhook URL : https://<your-domain>/sensors/ingest"
echo "  bearer token: $TOKEN"