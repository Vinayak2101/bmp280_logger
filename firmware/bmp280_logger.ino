/*
 * ESP32 BMP280 Wi-Fi Logger
 * -------------------------
 * GY-BM250 / BMP280 (temperature + barometric pressure) over I2C.
 *
 * Every X seconds (X is set at runtime on the web page, default 30 s):
 *   1. reads temperature / pressure / sea-level pressure / altitude
 *   2. POSTs the reading as JSON to a webhook URL you configure
 *   3. appends it to a RAM history ring buffer shown on the dashboard
 *
 * Wiring (classic ESP32 DevKit):
 *   GY-BM250 VCC -> 3V3    GND -> GND    SDA -> GPIO21    SCL -> GPIO22
 *
 * First boot with no Wi-Fi credentials: the board raises the access point
 * "ESP32-Setup-XXXX" (password sensors123) - browse to http://192.168.4.1
 * and fill in Wi-Fi + webhook settings.
 *
 * Serial: 115200 baud, one JSON line per sample + boot banner.
 */

#include <WiFi.h>
#include <WebServer.h>
#include <ESPmDNS.h>
#include <HTTPClient.h>
#include <WiFiClientSecure.h>
#include <Preferences.h>
#include <Wire.h>
#include <Adafruit_BMP280.h>
#include <time.h>
#include <math.h>
#include "web_page.h"

// Optional local Wi-Fi defaults (SSID/password kept out of the main source).
#if __has_include("secrets.h")
#include "secrets.h"
#endif

// ------------------------------- config -------------------------------
static const int PIN_SDA = 21;
static const int PIN_SCL = 22;
static const int PIN_LED = 2;

static const uint16_t RING_SIZE = 360;   // history points kept in RAM
static const uint8_t QUEUE_SIZE = 20;    // unsent samples buffered on failure
static const uint32_t DEFAULT_INTERVAL_S = 30;
static const uint32_t SENSOR_RETRY_MS = 10000;
static const uint32_t WIFI_RETRY_MS = 15000;
static const uint32_t AP_FALLBACK_MS = 60000;
static const char *AP_PASS = "sensors123";
static const char *NTP1 = "pool.ntp.org";
static const char *NTP2 = "time.google.com";

// ------------------------------- types --------------------------------
struct Config {
  String ssid;
  String pass;
  String url;
  String token;
  String name;
  uint32_t interval_s;
  float altitude_m;
};

struct Sample {
  uint32_t seq;
  uint32_t uptime_s;
  time_t epoch;
  float temp_c;
  float press_hpa;
  float sea_hpa;
  float alt_m;
  int16_t rssi;
};

// ------------------------------- state --------------------------------
Config cfg;
Preferences prefs;
Adafruit_BMP280 bmp(&Wire);
WebServer server(80);

Sample ring[RING_SIZE];
uint16_t ringCount = 0;
uint16_t ringNext = 0;

Sample queue[QUEUE_SIZE];
uint8_t queueCount = 0;
uint8_t queueHead = 0;

bool sensorOk = false;
uint8_t sensorAddr = 0;
uint8_t sensorChip = 0;

uint32_t seqCounter = 0;
uint32_t nextSampleAt = 0;
uint32_t lastSensorTry = 0;
uint32_t nextSendAt = 0;
uint32_t lastWifiTry = 0;
uint32_t bootAt = 0;
uint32_t lastLedAt = 0;
uint32_t lastSendAt = 0;
uint32_t sentOk = 0;
uint32_t sentFail = 0;
uint8_t sendFails = 0;
int lastHttpCode = 0;
bool apMode = false;
bool mdnsStarted = false;
bool ledState = false;
String lastError;
int idleSda = -1;
int idleScl = -1;
int floatSda = -1;
int floatScl = -1;

// ------------------------------ helpers -------------------------------
static bool haveTime() {
  return time(nullptr) > 1600000000;
}

static void isoTime(time_t t, char *buf, size_t len) {
  struct tm tmv;
  gmtime_r(&t, &tmv);
  strftime(buf, len, "%Y-%m-%dT%H:%M:%SZ", &tmv);
}

static String jsonEscape(const String &in) {
  String out;
  out.reserve(in.length() + 8);
  for (size_t i = 0; i < in.length(); i++) {
    char c = in[i];
    if (c == '"' || c == '\\') {
      out += '\\';
      out += c;
    } else if ((uint8_t)c < 0x20) {
      continue;
    } else {
      out += c;
    }
  }
  return out;
}

static String sanitizeHostname(String name) {
  String out;
  name.trim();
  for (size_t i = 0; i < name.length() && out.length() < 24; i++) {
    char c = (char)tolower(name[i]);
    if ((c >= 'a' && c <= 'z') || (c >= '0' && c <= '9') || c == '-') out += c;
    else if (c == ' ' || c == '_') out += '-';
  }
  if (out.length() == 0) out = "esp32-bmp280";
  return out;
}

static String sampleTs(const Sample &s) {
  if (s.epoch <= 1600000000) return String("null");
  char buf[24];
  isoTime(s.epoch, buf, sizeof(buf));
  return String("\"") + buf + "\"";
}

// ---------------------------- configuration ---------------------------
static void loadConfig() {
  prefs.begin("logger", true);
  cfg.ssid = prefs.getString("ssid", "");
  cfg.pass = prefs.getString("pass", "");
  cfg.url = prefs.getString("url", "");
  cfg.token = prefs.getString("token", "");
  cfg.name = prefs.getString("name", "esp32-bmp280");
  cfg.interval_s = prefs.getUInt("interval", DEFAULT_INTERVAL_S);
  cfg.altitude_m = prefs.getFloat("alt", 0.0f);
  prefs.end();

  // Fall back to the compiled-in credentials when nothing is stored yet.
  if (cfg.ssid.length() == 0) {
#ifdef DEFAULT_WIFI_SSID
    cfg.ssid = DEFAULT_WIFI_SSID;
    cfg.pass = DEFAULT_WIFI_PASS;
#endif
  }

  if (cfg.interval_s < 2 || cfg.interval_s > 3600) cfg.interval_s = DEFAULT_INTERVAL_S;
  if (cfg.name.length() == 0) cfg.name = "esp32-bmp280";
  if (isnan(cfg.altitude_m) || cfg.altitude_m < -500.0f || cfg.altitude_m > 9000.0f) cfg.altitude_m = 0.0f;
}

static void saveConfig() {
  prefs.begin("logger", false);
  prefs.putString("ssid", cfg.ssid);
  prefs.putString("pass", cfg.pass);
  prefs.putString("url", cfg.url);
  prefs.putString("token", cfg.token);
  prefs.putString("name", cfg.name);
  prefs.putUInt("interval", cfg.interval_s);
  prefs.putFloat("alt", cfg.altitude_m);
  prefs.end();
}

// ------------------------------- sensor -------------------------------
static uint8_t probeChip(uint8_t addr) {
  Wire.beginTransmission(addr);
  Wire.write((uint8_t)0xD0); // chip id register
  if (Wire.endTransmission(false) != 0) return 0;
  if (Wire.requestFrom(addr, (uint8_t)1) != 1) return 0;
  return (uint8_t)Wire.read();
}

static String scanSummary;   // e.g. "0x76,0x77" or "" - exposed at /scan
static uint8_t scanCount = 0;

static void i2cScan() {
  Serial.print(F("[i2c] scan:"));
  scanSummary = "";
  scanCount = 0;
  for (uint8_t a = 1; a < 127; a++) {
    Wire.beginTransmission(a);
    if (Wire.endTransmission() == 0) {
      char buf[12];
      snprintf(buf, sizeof(buf), " 0x%02X", a);
      Serial.print(buf);
      if (scanCount) scanSummary += ",";
      snprintf(buf, sizeof(buf), "0x%02X", a);
      scanSummary += buf;
      scanCount++;
    }
  }
  if (!scanCount) Serial.print(F(" nothing (check 3V3/GND/SDA/SCL)"));
  Serial.println();
}

static bool setupSensor() {
  static const uint8_t candidates[2] = {0x76, 0x77};
  for (uint8_t i = 0; i < 2; i++) {
    uint8_t id = probeChip(candidates[i]);
    if (id != 0x58 && id != 0x60) continue;
    if (!bmp.begin(candidates[i], id)) continue;
    bmp.setSampling(Adafruit_BMP280::MODE_FORCED,
                    Adafruit_BMP280::SAMPLING_X2,
                    Adafruit_BMP280::SAMPLING_X16,
                    Adafruit_BMP280::FILTER_X16,
                    Adafruit_BMP280::STANDBY_MS_500);
    sensorAddr = candidates[i];
    sensorChip = id;
    sensorOk = true;
    Serial.printf("[bmp] %s at 0x%02X, chip 0x%02X\n", id == 0x60 ? "BME280 (temp/press only)" : "BMP280",
                  sensorAddr, sensorChip);
    return true;
  }
  sensorOk = false;
  return false;
}

static bool readSample(Sample &s) {
  if (!sensorOk) return false;
  bmp.takeForcedMeasurement();
  float t = bmp.readTemperature();
  float p = bmp.readPressure() / 100.0f; // Pa -> hPa
  if (isnan(t) || isnan(p) || p < 300.0f || p > 1100.0f) return false;
  if (isnan(t) || t < -50.0f || t > 90.0f) return false;

  s.seq = ++seqCounter;
  s.uptime_s = millis() / 1000UL;
  s.epoch = haveTime() ? time(nullptr) : 0;
  s.temp_c = t;
  s.press_hpa = p;
  // barometric formula, reference altitude from config
  s.sea_hpa = p / powf(1.0f - (cfg.altitude_m / 44330.0f), 5.255f);
  s.alt_m = 44330.0f * (1.0f - powf(p / 1013.25f, 1.0f / 5.255f));
  s.rssi = (WiFi.status() == WL_CONNECTED) ? (int16_t)WiFi.RSSI() : (int16_t)-127;
  return true;
}

// ------------------------------ history -------------------------------
static void pushRing(const Sample &s) {
  ring[ringNext] = s;
  ringNext = (ringNext + 1) % RING_SIZE;
  if (ringCount < RING_SIZE) ringCount++;
}

static void enqueue(const Sample &s) {
  if (queueCount == QUEUE_SIZE) { // drop oldest
    queueHead = (queueHead + 1) % QUEUE_SIZE;
    queueCount--;
  }
  queue[(queueHead + queueCount) % QUEUE_SIZE] = s;
  queueCount++;
}

// ------------------------------ webhook -------------------------------
static String buildPayload(const Sample &s) {
  String j;
  j.reserve(320);
  j += F("{\"device\":\"");
  j += jsonEscape(cfg.name);
  j += F("\",\"seq\":");
  j += String(s.seq);
  j += F(",\"ts\":");
  j += sampleTs(s);
  j += F(",\"uptime_s\":");
  j += String(s.uptime_s);
  j += F(",\"temp_c\":");
  j += String(s.temp_c, 2);
  j += F(",\"press_hpa\":");
  j += String(s.press_hpa, 2);
  j += F(",\"sea_level_hpa\":");
  j += String(s.sea_hpa, 2);
  j += F(",\"altitude_m\":");
  j += String(s.alt_m, 1);
  j += F(",\"rssi\":");
  j += String(s.rssi);
  j += F(",\"heap\":");
  j += String(ESP.getFreeHeap());
  j += F("}");
  return j;
}

static WiFiClientSecure secureClient;   // TLS transport for https:// webhooks
static bool secureReady = false;

static bool postWebhook(const Sample &s) {
  if (cfg.url.length() < 8) {
    lastError = "webhook url not set";
    return false;
  }
  HTTPClient http;
  http.setTimeout(6000);
  http.setConnectTimeout(4000);

  bool began;
  if (cfg.url.startsWith("https://")) {
    // Certificate validation is disabled: the payload is telemetry and the
    // endpoint is authenticated with the bearer token below.
    if (!secureReady) {
      secureClient.setInsecure();
      secureReady = true;
    }
    began = http.begin(secureClient, cfg.url);
  } else {
    began = http.begin(cfg.url);
  }
  if (!began) {
    lastError = "http.begin failed";
    lastHttpCode = -1;
    return false;
  }
  http.addHeader("Content-Type", "application/json");
  if (cfg.token.length() > 0) http.addHeader("Authorization", "Bearer " + cfg.token);
  int code = http.POST(buildPayload(s));
  http.end();
  lastHttpCode = code;
  lastSendAt = millis();
  if (code >= 200 && code < 300) {
    sentOk++;
    lastError = "";
    return true;
  }
  sentFail++;
  lastError = "HTTP " + String(code);
  return false;
}

static void trySendQueue() {
  if (queueCount == 0) return;
  if ((int32_t)(millis() - nextSendAt) < 0) return;
  if (WiFi.status() != WL_CONNECTED) return;

  Sample &s = queue[queueHead];
  if (postWebhook(s)) {
    queueHead = (queueHead + 1) % QUEUE_SIZE;
    queueCount--;
    sendFails = 0;
    nextSendAt = millis() + 100;
  } else {
    if (sendFails < 6) sendFails++;
    nextSendAt = millis() + (5000UL << sendFails); // 10s .. ~5min backoff
  }
}

// -------------------------------- JSON --------------------------------
static String buildStatusJson() {
  String j;
  j.reserve(700);
  j += F("{\"ok\":true,\"device\":\"");
  j += jsonEscape(cfg.name);
  j += F("\",\"interval_s\":");
  j += String(cfg.interval_s);
  j += F(",\"altitude_ref_m\":");
  j += String(cfg.altitude_m, 1);
  j += F(",\"sensor\":{\"ok\":");
  j += sensorOk ? F("true") : F("false");
  j += F(",\"addr\":\"0x");
  char addrBuf[5];
  snprintf(addrBuf, sizeof(addrBuf), "%02X", sensorAddr);
  j += addrBuf;
  j += F("\",\"chip\":\"");
  j += (sensorChip == 0x60) ? F("BME280") : F("BMP280");
  j += F("\"}");

  j += F(",\"wifi\":{\"connected\":");
  j += (WiFi.status() == WL_CONNECTED) ? F("true") : F("false");
  j += F(",\"ap\":");
  j += apMode ? F("true") : F("false");
  j += F(",\"ip\":\"");
  j += (WiFi.status() == WL_CONNECTED) ? WiFi.localIP().toString() : (apMode ? WiFi.softAPIP().toString() : String(""));
  j += F("\",\"rssi\":");
  j += (WiFi.status() == WL_CONNECTED) ? String(WiFi.RSSI()) : String("null");
  j += F("}");

  j += F(",\"webhook\":{\"url_set\":");
  j += (cfg.url.length() > 0) ? F("true") : F("false");
  j += F(",\"last_code\":");
  j += String(lastHttpCode);
  j += F(",\"sent_ok\":");
  j += String(sentOk);
  j += F(",\"sent_fail\":");
  j += String(sentFail);
  j += F(",\"queued\":");
  j += String(queueCount);
  j += F(",\"last_send_s\":");
  j += String(lastSendAt ? ((millis() - lastSendAt) / 1000) : 0);
  j += F(",\"error\":\"");
  j += jsonEscape(lastError);
  j += F("\"}");

  j += F(",\"uptime_s\":");
  j += String(millis() / 1000UL);
  j += F(",\"heap\":");
  j += String(ESP.getFreeHeap());

  if (ringCount > 0) {
    uint16_t idx = (ringNext + RING_SIZE - 1) % RING_SIZE;
    const Sample &last = ring[idx];
    j += F(",\"last\":{\"seq\":");
    j += String(last.seq);
    j += F(",\"ts\":");
    j += sampleTs(last);
    j += F(",\"temp_c\":");
    j += String(last.temp_c, 2);
    j += F(",\"press_hpa\":");
    j += String(last.press_hpa, 2);
    j += F(",\"sea_level_hpa\":");
    j += String(last.sea_hpa, 2);
    j += F(",\"altitude_m\":");
    j += String(last.alt_m, 1);
    j += F(",\"rssi\":");
    j += String(last.rssi);
    j += F("}");
  }
  j += F("}");
  return j;
}

static String buildHistoryJson() {
  String j;
  j.reserve(64 + ringCount * 90);
  j += F("{\"count\":");
  j += String(ringCount);
  j += F(",\"interval_s\":");
  j += String(cfg.interval_s);
  j += F(",\"samples\":[");
  for (uint16_t n = 0; n < ringCount; n++) {
    uint16_t idx = (ringNext + RING_SIZE - ringCount + n) % RING_SIZE;
    const Sample &s = ring[idx];
    if (n) j += ',';
    j += F("{\"seq\":");
    j += String(s.seq);
    j += F(",\"ts\":");
    j += sampleTs(s);
    j += F(",\"uptime_s\":");
    j += String(s.uptime_s);
    j += F(",\"temp_c\":");
    j += String(s.temp_c, 2);
    j += F(",\"press_hpa\":");
    j += String(s.press_hpa, 2);
    j += F("}");
  }
  j += F("]}");
  return j;
}

// -------------------------------- pages -------------------------------
// dashboard markup lives in web_page.h


// ------------------------------ handlers ------------------------------
static void handleRoot() {
  server.send_P(200, "text/html", INDEX_HTML);
}

static void handleData() {
  server.send(200, "application/json", buildStatusJson());
}

static void handleHistory() {
  server.send(200, "application/json", buildHistoryJson());
}

static String settingsJson() {
  String j = F("{\"ok\":true,\"device_name\":\"");
  j += jsonEscape(cfg.name);
  j += F("\",\"interval_s\":");
  j += String(cfg.interval_s);
  j += F(",\"wifi_ssid\":\"");
  j += jsonEscape(cfg.ssid);
  j += F("\",\"wifi_pass_set\":");
  j += (cfg.pass.length() > 0) ? F("true") : F("false");
  j += F(",\"webhook_url\":\"");
  j += jsonEscape(cfg.url);
  j += F("\",\"webhook_token_set\":");
  j += (cfg.token.length() > 0) ? F("true") : F("false");
  j += F(",\"altitude_m\":");
  j += String(cfg.altitude_m, 1);
  j += F(",\"sensor_ok\":");
  j += sensorOk ? F("true") : F("false");
  j += F("}");
  return j;
}

static void handleSettingsGet() {
  server.send(200, "application/json", settingsJson());
}

static void handleSettingsPost() {
  String err;
  bool wifiChanged = false;

  if (server.hasArg("interval_s")) {
    long v = server.arg("interval_s").toInt();
    if (v < 2 || v > 3600) err = "interval must be 2-3600 seconds";
    else cfg.interval_s = (uint32_t)v;
  }
  if (server.hasArg("altitude_m")) {
    float v = server.arg("altitude_m").toFloat();
    if (v < -500.0f || v > 9000.0f) err = "altitude out of range";
    else cfg.altitude_m = v;
  }
  if (server.hasArg("device_name")) {
    String v = server.arg("device_name");
    v.trim();
    if (v.length() > 0) cfg.name = sanitizeHostname(v);
  }
  if (server.hasArg("wifi_ssid")) {
    String v = server.arg("wifi_ssid");
    v.trim();
    if (v != cfg.ssid) {
      cfg.ssid = v;
      cfg.pass = server.hasArg("wifi_pass") ? server.arg("wifi_pass") : "";
      wifiChanged = true;
    } else if (server.hasArg("wifi_pass") && server.arg("wifi_pass").length() > 0) {
      cfg.pass = server.arg("wifi_pass");
      wifiChanged = true;
    }
  } else if (server.hasArg("wifi_pass") && server.arg("wifi_pass").length() > 0) {
    cfg.pass = server.arg("wifi_pass");
    wifiChanged = true;
  }
  if (server.hasArg("webhook_url")) {
    String v = server.arg("webhook_url");
    v.trim();
    if (v.length() > 0 && !v.startsWith("http://") && !v.startsWith("https://")) {
      err = "webhook url must start with http:// or https://";
    } else {
      cfg.url = v;
    }
  }
  if (server.hasArg("webhook_token")) {
    String v = server.arg("webhook_token");
    v.trim();
    if (v.length() > 0) cfg.token = v;
  }

  if (err.length() > 0) {
    server.send(400, "application/json", "{\"ok\":false,\"error\":\"" + jsonEscape(err) + "\"}");
    return;
  }

  saveConfig();
  nextSampleAt = millis(); // apply new cadence right away
  nextSendAt = 0;          // and retry any queued samples immediately
  sendFails = 0;
  lastError = "";
  Serial.println(F("[cfg] settings updated"));

  if (wifiChanged) {
    Serial.printf("[cfg] wifi changed -> %s\n", cfg.ssid.c_str());
    WiFi.disconnect(true);
    delay(50);
    if (cfg.ssid.length() == 0) {
      startAP();
    } else {
      WiFi.begin(cfg.ssid.c_str(), cfg.pass.c_str());
      lastWifiTry = millis();
      apMode = false;
    }
  }

  server.send(200, "application/json", "{\"ok\":true}");
}

static void handleTest() {
  Sample s;
  if (!readSample(s)) {
    server.send(503, "application/json", "{\"ok\":false,\"error\":\"sensor read failed\"}");
    return;
  }
  if (cfg.url.length() < 8) {
    server.send(400, "application/json", "{\"ok\":false,\"error\":\"webhook url not set\"}");
    return;
  }
  bool ok = postWebhook(s);
  String j = F("{\"ok\":");
  j += ok ? F("true") : F("false");
  j += F(",\"code\":");
  j += String(lastHttpCode);
  j += F(",\"error\":\"");
  j += jsonEscape(lastError);
  j += F("\"}");
  server.send(ok ? 200 : 502, "application/json", j);
}

static void handleReboot() {
  server.send(200, "application/json", "{\"ok\":true,\"message\":\"rebooting\"}");
  delay(200);
  ESP.restart();
}

static void handleScan() {
  probeLines();
  Wire.setClock(100000);   // slower clock is more forgiving of long/dupont wiring
  i2cScan();
  Wire.setClock(400000);
  String j = F("{\"ok\":true,\"count\":");
  j += String(scanCount);
  j += F(",\"addresses\":\"");
  j += scanSummary;
  j += F("\",\"float_sda\":");
  j += String(floatSda);
  j += F(",\"float_scl\":");
  j += String(floatScl);
  j += F(",\"idle_sda\":");
  j += String(idleSda);
  j += F(",\"idle_scl\":");
  j += String(idleScl);
  j += F(",\"sensor_ok\":");
  j += sensorOk ? F("true") : F("false");
  j += F(",\"hint\":\"");
  if (scanCount > 0) j += F("device ACKs on the bus");
  else if (floatSda == 0 && floatScl == 0) j += F("no pull-up on SDA/SCL: module unpowered or not on these pins");
  else if (idleSda == 0) j += F("SDA held low: short to GND or driven pin");
  else if (idleScl == 0) j += F("SCL held low: short to GND or driven pin");
  else j += F("module pull-ups seen but no ACK: try swapping SDA/SCL");
  j += F("\"}");
  server.send(200, "application/json", j);
}

static void handleNotFound() {
  server.send(404, "application/json", "{\"ok\":false,\"error\":\"not found\"}");
}

// ------------------------------ network -------------------------------
static void startAP() {
  if (apMode) return;
  WiFi.mode(WIFI_AP);
  uint8_t mac[6];
  WiFi.macAddress(mac);
  char ssid[24];
  snprintf(ssid, sizeof(ssid), "ESP32-Setup-%02X%02X", mac[4], mac[5]);
  WiFi.softAP(ssid, AP_PASS);
  apMode = true;
  Serial.printf("[wifi] setup AP \"%s\" (pw %s)\n", ssid, AP_PASS);
  Serial.printf("[wifi] browse to http://%s to set Wi-Fi + webhook\n", WiFi.softAPIP().toString().c_str());
}

static void connectWifi() {
  if (cfg.ssid.length() == 0) {
    Serial.println(F("[wifi] no credentials - starting setup AP"));
    startAP();
    return;
  }
  WiFi.mode(WIFI_STA);
  WiFi.setHostname(cfg.name.c_str());
  WiFi.begin(cfg.ssid.c_str(), cfg.pass.c_str());
  Serial.printf("[wifi] connecting to %s", cfg.ssid.c_str());
  uint32_t start = millis();
  while (WiFi.status() != WL_CONNECTED && millis() - start < 15000) {
    delay(300);
    Serial.print('.');
  }
  Serial.println();
  if (WiFi.status() == WL_CONNECTED) {
    Serial.printf("[wifi] ip=%s rssi=%d\n", WiFi.localIP().toString().c_str(), WiFi.RSSI());
    configTime(0, 0, NTP1, NTP2);
    if (!mdnsStarted && MDNS.begin(cfg.name.c_str())) {
      MDNS.addService("http", "tcp", 80);
      mdnsStarted = true;
      Serial.printf("[wifi] http://%s.local/\n", cfg.name.c_str());
    }
  } else {
    Serial.println(F("[wifi] connect failed - will retry, AP fallback in 60s"));
  }
}

// Sample the bus lines with and without the ESP32's internal pull-ups.
//  - float=0 with pullup=1  -> only the ESP32 is pulling: module has no pull-ups
//    of its own, i.e. it is very likely unpowered / not connected.
//  - float=1                -> something on the bus is pulling up: module is alive.
static void probeLines() {
  pinMode(PIN_SDA, INPUT);
  pinMode(PIN_SCL, INPUT);
  delay(5);
  floatSda = digitalRead(PIN_SDA);
  floatScl = digitalRead(PIN_SCL);

  pinMode(PIN_SDA, INPUT_PULLUP);
  pinMode(PIN_SCL, INPUT_PULLUP);
  delay(5);
  idleSda = digitalRead(PIN_SDA);
  idleScl = digitalRead(PIN_SCL);

  Wire.begin(PIN_SDA, PIN_SCL);
  Wire.setClock(400000);
}

// -------------------------------- setup --------------------------------
void setup() {
  Serial.begin(115200);
  delay(200);
  Serial.println();
  Serial.println(F("=== ESP32 BMP280 Wi-Fi Logger ==="));
  Serial.printf("chip=%s rev=%d flash=%uMB\n", ESP.getChipModel(), ESP.getChipRevision(),
                ESP.getFlashChipSize() / (1024 * 1024));

  pinMode(PIN_LED, OUTPUT);
  digitalWrite(PIN_LED, LOW);

  loadConfig();
  Serial.printf("[cfg] interval=%us webhook=%s alt=%.1fm\n", cfg.interval_s,
                cfg.url.length() ? cfg.url.c_str() : "(none)", cfg.altitude_m);

  // Idle-line check: with pull-ups and nothing driving the bus both lines must
  // read HIGH. A LOW line at boot means a short, a miswire, or a dead module.
  probeLines();
  Serial.printf("[i2c] float: SDA=%d SCL=%d  pullup: SDA=%d SCL=%d\n", floatSda, floatScl, idleSda, idleScl);
  i2cScan();
  if (!setupSensor()) Serial.println(F("[bmp] not detected - retrying every 10s"));

  connectWifi();

  server.on("/", handleRoot);
  server.on("/data", handleData);
  server.on("/history", handleHistory);
  server.on("/settings", HTTP_GET, handleSettingsGet);
  server.on("/settings", HTTP_POST, handleSettingsPost);
  server.on("/test", HTTP_POST, handleTest);
  server.on("/reboot", HTTP_POST, handleReboot);
  server.on("/health", []() { server.send(200, "application/json", "{\"ok\":true}"); });
  server.on("/scan", handleScan);
  server.onNotFound(handleNotFound);
  server.begin();
  Serial.println(F("[http] server started on port 80"));

  bootAt = millis();
  nextSampleAt = millis() + 500;
}

// --------------------------------- loop --------------------------------
void loop() {
  uint32_t now = millis();

  if (!sensorOk && (int32_t)(now - lastSensorTry) >= (int32_t)SENSOR_RETRY_MS) {
    lastSensorTry = now;
    if (setupSensor()) Serial.println(F("[bmp] sensor recovered"));
  }

  if (sensorOk && (int32_t)(now - nextSampleAt) >= 0) {
    Sample s;
    if (readSample(s)) {
      pushRing(s);
      enqueue(s);
      Serial.println(buildPayload(s));
      nextSampleAt = now + cfg.interval_s * 1000UL;
    } else {
      Serial.println(F("[bmp] read failed"));
      nextSampleAt = now + 2000;
    }
  }

  trySendQueue();

  if ((int32_t)(now - lastLedAt) >= 500) {
    lastLedAt = now;
    ledState = !ledState;
    digitalWrite(PIN_LED, ledState ? HIGH : LOW);
  }

  if (WiFi.status() == WL_CONNECTED) {
    if (apMode) { // STA came back while AP was up
      WiFi.mode(WIFI_STA);
      apMode = false;
      Serial.println(F("[wifi] STA recovered, AP closed"));
    }
    server.handleClient();
  } else {
    server.handleClient(); // AP mode / early boot
    if (cfg.ssid.length() > 0 && !apMode && (int32_t)(now - bootAt) > (int32_t)AP_FALLBACK_MS) {
      Serial.println(F("[wifi] STA failed for 60s - starting setup AP"));
      startAP();
    }
    if (cfg.ssid.length() > 0 && !apMode && (int32_t)(now - lastWifiTry) >= (int32_t)WIFI_RETRY_MS) {
      lastWifiTry = now;
      Serial.println(F("[wifi] retrying..."));
      WiFi.reconnect();
    }
  }
}
