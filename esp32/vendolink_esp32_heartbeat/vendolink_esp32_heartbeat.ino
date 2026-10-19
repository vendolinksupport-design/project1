/**
 * ============================================================
 * VendoLink ESP32 Controller — Power Recovery & Non-Blocking Architecture
 * ============================================================
 *
 * Microcontroller firmware for VendoLink Vending Machines.
 *
 * Power-Interruption Session Recovery (NVS Preferences):
 * - Persists active session state (service type, credit, remaining seconds, duration, txId)
 * - Safe periodic checkpointing (every 5 seconds) to prevent flash wear
 * - Upon boot after power failure, detects interrupted session
 * - Keeps all relays strictly OFF for safety (pump never starts unexpectedly)
 * - 20x4 LCD displays INTERRUPTED SESSION screen
 * - Strict Button Isolation: Only the interrupted service button can command recovery
 * - Multi-press detection:
 *     3x press within window -> RESUME service & relay ON
 *     2x press within window -> CANCEL session & clear storage
 *     1x press -> Discarded as incomplete sequence
 * - Coin slot disabled during recovery
 * - Normal service completion or cancellation cleanly purges recovery state
 *
 * True Non-Blocking Dual-Core Architecture:
 * - CORE 1: Real-Time Machine Control (Buttons, Relays, Coin ISR, LCD, Timer)
 *   Zero network latency. Loop cycle time < 1 millisecond.
 *   Service starting, coin detection, and credit updates NEVER wait for network.
 * - CORE 0: Background Network Worker (FreeRTOS Task)
 *   Handles Wi-Fi maintenance, server online/offline probing, heartbeat telemetry,
 *   and background offline transaction synchronization.
 *
 * Server Availability & Offline Detection:
 * Distinguishes:
 *   1. Wi-Fi CONNECTED + SERVER ONLINE (XAMPP running) -> Normal sync & telemetry
 *   2. Wi-Fi CONNECTED + SERVER OFFLINE (XAMPP stopped) -> Marks SERVER OFFLINE,
 *      circuits-breakers sync attempts, holds pending transactions in LittleFS queue,
 *      machine continues with 100% instant local response.
 *   3. Wi-Fi DISCONNECTED -> Non-blocking reconnect on Core 0.
 *
 * Anti-Glitch & Coin Noise Protections:
 * 1. Hardware pulse-width verification (15ms - 110ms) via CHANGE ISR.
 *    Rejects microsecond motor/spin-cycle electrical noise spikes.
 * 2. Inter-pulse spacing verification (50ms - 250ms) within pulse bursts.
 * 3. Strict denomination burst validation: 1 pulse (₱5), 2 pulses (₱10), 4 pulses (₱20).
 *    Spurious 3 pulses or >4 pulses are rejected as noise.
 * 4. Coin detection remains active during active service and networking.
 *
 * Persistent LittleFS Offline Queue:
 * - Circular FIFO ring buffer (max 500 pending records, persistent across reboots).
 * - Thread-safe mutex protection between Core 1 (append) and Core 0 (sync).
 * - Safe local deletion: local transaction is removed ONLY after server confirms success.
 * - Idempotent synchronization with unique client transaction IDs (VL-<MAC>-<SEQ>).
 *
 * Hardware Wiring:
 * - LCD 2004A: SDA ──► GPIO 21, SCL ──► GPIO 22, VCC ──► VIN (5V), GND ──► GND
 * - Coin Slot: COIN Signal ──► GPIO 4 (D4), GND ──► Common GND
 * - Water Button: Leg 1 ──► GPIO 15 (D15), Leg 2 ──► GND
 * - Water Relay:  ESP32 GPIO 25 (D25) ──► SSR-1 Water Solenoid Relay Signal
 * - Soap Button:  Leg 1 ──► GPIO 18 (D18), Leg 2 ──► GND
 * - Soap Relay:   ESP32 GPIO 33 (D33) ──► SSR-2 Soap Relay Signal
 * - Water Floater: Wire 1 ──► GPIO 13 (D13), Wire 2 ──► GND (LOW = Water Empty)
 * - Soap Floater:  Wire 1 ──► GPIO 12 (D12), Wire 2 ──► GND (LOW = Soap Empty)
 * - Piezo Buzzer: Signal Pin ──► GPIO 2 (D2), GND ──► GND (Optional VCC ──► 3V3/VIN)
 * - HW-482 Coin Relay: IN ──► GPIO 14 (D14), VCC ──► 5V (VIN), GND ──► GND
 *   COM ──► 12V PSU (+), NC ──► Coin Slot RED (+12V VCC)
 *
 * @package VendoLink IoT
 * @version 3.0.0
 */

#include <WiFi.h>
#include <WebServer.h>
#include <DNSServer.h>
#include <HTTPClient.h>
#include <HTTPUpdate.h>
#include <WiFiClientSecure.h>
#include <Wire.h>
#include <FS.h>
#include <LittleFS.h>
#include <Preferences.h>
#include <time.h>
#include <freertos/FreeRTOS.h>
#include <freertos/task.h>
#include <freertos/semphr.h>
#include "LiquidCrystal_I2C.h"
#include "config.h"

// ============================================================
// Data Structures: Offline Transaction Queue
// ============================================================
static const uint16_t OFFLINE_QUEUE_MAX     = 500;
static const uint32_t OFFLINE_QUEUE_MAGIC   = 0x564C5132UL; // "VLQ2"
static const uint32_t OFFLINE_RECORD_MAGIC  = 0x564C5432UL; // "VLT2"
static const char*    OFFLINE_QUEUE_FILE    = "/offline_queue.dat";

// Transaction Lifecycle States
enum TxStatus : uint8_t {
    TX_PENDING     = 0,
    TX_RUNNING     = 1,
    TX_COMPLETED   = 2,
    TX_CANCELLED   = 3,
    TX_INTERRUPTED = 4,
    TX_INCOMPLETE  = 5
};

// Circular Queue Header (stored at offset 0 of queue file)
struct QueueHeader {
    uint32_t magic;      // OFFLINE_QUEUE_MAGIC
    uint16_t head;       // Index of oldest pending record (0..499)
    uint16_t count;      // Number of pending records (0..500)
    uint16_t capacity;   // OFFLINE_QUEUE_MAX (500)
    uint16_t reserved;   // 2-byte alignment
    uint32_t lastTxSeq;  // Monotonically increasing transaction sequence counter
};

// Stored Transaction Record (fixed size for direct random seeking)
struct OfflineRecord {
    uint32_t magic;                // OFFLINE_RECORD_MAGIC
    uint32_t seq;                  // Sequence number
    uint32_t createdEpoch;         // Timestamp (epoch or millis-derived)
    uint32_t durationSeconds;      // Service duration in seconds
    float    amount;               // Amount paid (e.g. 5.00, 10.00, 20.00)
    char     clientTxId[36];       // Unique Idempotency ID: "VL-<MAC>-<SEQ>"
    char     machineKey[24];       // e.g. "machine_01"
    char     serviceKey[12];       // "water" or "soap"
    uint8_t  status;               // TxStatus (TX_COMPLETED, TX_INCOMPLETE, etc.)
    uint8_t  reserved;             // 1-byte padding
    uint16_t actualRuntimeSeconds; // Actual seconds elapsed before interruption
};

// ============================================================
// 🌐 Universal NVS Configuration & Dynamic Device Credentials
// ============================================================
Preferences nvsConfig;

String configWifiSsid      = "";
String configWifiPass      = "";
String configServerUrl     = DEFAULT_VENDOLINK_SERVER;
String configEnrollSecret  = DEFAULT_ENROLLMENT_SECRET;
String configMachineKey    = DEFAULT_MACHINE_KEY;
String configMachineCode   = DEFAULT_MACHINE_CODE;
String configApiToken      = "";
bool   isDeviceEnrolled    = false;
bool   isConfigured        = false;

// Captive Portal Objects (Runs on Core 0 when Wi-Fi unconfigured or failed)
WebServer* apServer        = nullptr;
DNSServer* dnsServer       = nullptr;
bool       apModeActive    = false;
unsigned long apModeStartedAt = 0;
volatile bool lcdNeedsRefresh = false;

inline String getDeviceMac() {
    return WiFi.macAddress();
}

inline String getDeviceMacSuffix() {
    String mac = WiFi.macAddress();
    mac.replace(":", "");
    if (mac.length() >= 4) {
        return mac.substring(mac.length() - 4);
    }
    return "0000";
}

inline String getApSSID() {
    return String(AP_SSID_PREFIX) + getDeviceMacSuffix();
}

// 🔗 Derived API Endpoints (Auto-constructed dynamically from configServerUrl)
inline String buildEndpointUrl(const char* endpointPath) {
    String base = configServerUrl;
    base.trim();
    while (base.endsWith("/")) {
        base.remove(base.length() - 1);
    }
    return base + endpointPath;
}

inline String maskSecret(const char* secret) {
    if (!secret) return "****";
    String s = String(secret);
    if (s.length() <= 4) return "****";
    return s.substring(0, 2) + "..." + s.substring(s.length() - 2);
}

// ------------------------------------------------------------
// Universal HTTP/HTTPS Connection Helper
// ------------------------------------------------------------
inline bool setupHttpConnection(HTTPClient& http, const String& url, WiFiClientSecure& secClient, WiFiClient& plainClient) {
    http.setFollowRedirects(HTTPC_STRICT_FOLLOW_REDIRECTS);
    if (url.startsWith("https://")) {
        secClient.setInsecure();
        return http.begin(secClient, url);
    } else {
        return http.begin(plainClient, url);
    }
}

// Forward declarations needed by captive portal and handlers
void clearLCD();
void printLCDLine(int row, String text);
void updateLCDDisplay();
void playTone(int frequency, int durationMs);
void playBeep(int durationMs, int count);

// ------------------------------------------------------------
// NVS Configuration Load & Save Helpers
// ------------------------------------------------------------
void loadDeviceConfig() {
    nvsConfig.begin("vlcfg", true);
    configWifiSsid     = nvsConfig.getString("ssid", "");
    configWifiPass     = nvsConfig.getString("pass", "");
    configServerUrl    = nvsConfig.getString("server", DEFAULT_VENDOLINK_SERVER);
    configEnrollSecret = nvsConfig.getString("boot_token", DEFAULT_ENROLLMENT_SECRET);
    configMachineKey   = nvsConfig.getString("mkey", DEFAULT_MACHINE_KEY);
    configMachineCode  = nvsConfig.getString("mcode", DEFAULT_MACHINE_CODE);
    configApiToken     = nvsConfig.getString("token", "");
    isDeviceEnrolled   = nvsConfig.getBool("enrolled", false);
    isConfigured       = (configWifiSsid.length() > 0);
    nvsConfig.end();

    // Auto-migrate stale local server IP if NVS points to old IP
    if (configServerUrl.indexOf("192.168.") != -1 || configServerUrl.indexOf("localhost") != -1) {
        Serial.print(F("⚠️ [MIGRATION] Local server IP detected in NVS. Updating server to: "));
        Serial.println(DEFAULT_VENDOLINK_SERVER);
        configServerUrl = DEFAULT_VENDOLINK_SERVER;
        nvsConfig.begin("vlcfg", false);
        nvsConfig.putString("server", DEFAULT_VENDOLINK_SERVER);
        nvsConfig.end();
    }

    Serial.println(F("⚙️ [NVS Config] Loaded Device Profile:"));
    Serial.print(F("   Device MAC:   ")); Serial.println(getDeviceMac());
    Serial.print(F("   Wi-Fi SSID:   ")); Serial.println(configWifiSsid.length() > 0 ? configWifiSsid : String(F("[NOT CONFIGURED — USING FACTORY HOTSPOT FALLBACK]")));
    Serial.print(F("   Server URL:   ")); Serial.println(configServerUrl);
    Serial.print(F("   Machine Key:  ")); Serial.println(configMachineKey);
    Serial.print(F("   Machine Code: ")); Serial.println(configMachineCode);
    Serial.print(F("   Bootstrap:    ")); Serial.println(configEnrollSecret.length() > 0 ? F("[CONFIGURED]") : F("[NOT CONFIGURED]"));
    Serial.print(F("   Enrolled:     ")); Serial.println(isDeviceEnrolled ? F("YES") : F("NO"));
}

void saveDeviceNetworkConfig(const String& ssid, const String& pass) {
    nvsConfig.begin("vlcfg", false);
    nvsConfig.putString("ssid", ssid);
    nvsConfig.putString("pass", pass);
    nvsConfig.end();

    configWifiSsid = ssid;
    configWifiPass = pass;
    isConfigured   = true;
}

void saveInstallerConfig(const String& server, const String& bootToken) {
    nvsConfig.begin("vlcfg", false);
    if (server.length() > 0) {
        nvsConfig.putString("server", server);
        configServerUrl = server;
    }
    if (bootToken.length() > 0) {
        nvsConfig.putString("boot_token", bootToken);
        configEnrollSecret = bootToken;
    }
    nvsConfig.end();
}

void saveDeviceCredentials(const String& mkey, const String& mcode, const String& token) {
    nvsConfig.begin("vlcfg", false);
    nvsConfig.putString("mkey", mkey);
    nvsConfig.putString("mcode", mcode);
    nvsConfig.putString("token", token);
    nvsConfig.putBool("enrolled", true);
    // Securely purge single-use bootstrap token from flash once enrolled
    nvsConfig.remove("boot_token");
    nvsConfig.end();

    configMachineKey   = mkey;
    configMachineCode  = mcode;
    configApiToken     = token;
    configEnrollSecret = "";
    isDeviceEnrolled   = true;
}

// ------------------------------------------------------------
// Captive Portal HTML & HTTP Request Handlers
// ------------------------------------------------------------

// 1. Customer Wi-Fi Setup Portal (Only SSID & Password)
void handleCaptivePortalRoot() {
    if (!apServer) return;
    String html = F("<!DOCTYPE html><html><head><meta name='viewport' content='width=device-width, initial-scale=1'>"
        "<title>VendoLink Wi-Fi Setup</title>"
        "<style>"
        "body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;background:#0a0f1d;color:#f1f5f9;padding:20px;margin:0;}"
        ".card{max-width:400px;margin:20px auto;background:#131d36;border:1px solid #1e293b;border-radius:14px;padding:24px;box-shadow:0 10px 25px rgba(0,0,0,0.5);}"
        "h2{margin-top:0;color:#38bdf8;font-size:22px;text-align:center;}"
        ".info{font-size:13px;color:#94a3b8;margin-bottom:20px;text-align:center;line-height:1.8;}"
        ".badge{display:inline-block;background:#0284c7;color:#fff;padding:2px 8px;border-radius:6px;font-family:monospace;font-size:12px;}"
        "label{display:block;font-size:13px;font-weight:600;color:#cbd5e1;margin-top:14px;margin-bottom:6px;}"
        "input{width:100%;box-sizing:border-box;padding:12px;background:#0b1120;border:1px solid #334155;border-radius:8px;color:#fff;font-size:14px;}"
        "input:focus{outline:none;border-color:#38bdf8;}"
        "button{width:100%;background:#0284c7;color:#fff;font-weight:bold;border:none;padding:14px;border-radius:8px;font-size:15px;margin-top:22px;cursor:pointer;}"
        "button:hover{background:#0369a1;}"
        ".footer{font-size:12px;text-align:center;color:#64748b;margin-top:20px;}"
        ".inst-link{color:#475569;text-decoration:none;font-size:11px;}"
        ".inst-link:hover{color:#94a3b8;}"
        "</style></head><body><div class='card'>"
        "<h2>VendoLink Wi-Fi Setup</h2>"
        "<div class='info'>Device MAC: <span class='badge'>");
    html += getDeviceMac();
    html += F("</span><br>Firmware: <span class='badge'>v");
    html += FIRMWARE_VERSION; // v3.0.0
    html += F("</span></div>"
        "<form method='POST' action='/save'>"
        "<label>Wi-Fi Network Name (SSID)</label>"
        "<input type='text' name='ssid' value='");
    html += configWifiSsid;
    html += F("' placeholder='Enter Wi-Fi Network Name' required>"
        "<label>Wi-Fi Password</label>"
        "<input type='password' name='pass' value='");
    html += configWifiPass;
    html += F("' placeholder='Enter Wi-Fi Password'>"
        "<button type='submit'>Connect Machine</button>"
        "</form><div class='footer'>VendoLink IoT Controller<br><a href='/installer' class='inst-link'>Installer Settings</a></div>"
        "</div></body></html>");

    apServer->send(200, "text/html", html);
}

void handleCaptivePortalSave() {
    if (!apServer) return;
    String sSsid = apServer->arg("ssid");
    String sPass = apServer->arg("pass");

    sSsid.trim();

    if (sSsid.length() == 0) {
        apServer->send(400, "text/plain", "Missing required field: Wi-Fi SSID is required.");
        return;
    }

    saveDeviceNetworkConfig(sSsid, sPass);

    String resp = F("<!DOCTYPE html><html><head><meta name='viewport' content='width=device-width, initial-scale=1'>"
        "<title>Wi-Fi Saved</title><style>"
        "body{font-family:sans-serif;background:#0a0f1d;color:#22c55e;padding:40px;text-align:center;}"
        ".box{max-width:380px;margin:auto;background:#131d36;padding:30px;border-radius:12px;border:1px solid #22c55e;}"
        "h3{color:#38bdf8;} p{color:#cbd5e1;}"
        "</style></head><body><div class='box'>"
        "<h3>Configuration Saved!</h3>"
        "<p>Connecting to <strong>");
    resp += sSsid;
    resp += F("</strong>.<br>The machine is now connecting to the network.</p>"
        "</div></body></html>");

    apServer->send(200, "text/html", resp);
    delay(1000);

    // Signal Core 0 network worker to teardown AP and connect to Wi-Fi
    apModeActive = false;
}

// 2. Protected Installer Configuration Portal (/installer)
void handleInstallerRoot() {
    if (!apServer) return;
    String pin = apServer->arg("pin");
    bool isAuthorized = (pin.length() > 0 && pin == String(INSTALLER_PORTAL_PIN));

    String html = F("<!DOCTYPE html><html><head><meta name='viewport' content='width=device-width, initial-scale=1'>"
        "<title>Installer Configuration</title><style>"
        "body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;background:#0a0f1d;color:#f1f5f9;padding:20px;margin:0;}"
        ".card{max-width:420px;margin:20px auto;background:#131d36;border:1px solid #334155;border-radius:14px;padding:24px;box-shadow:0 10px 25px rgba(0,0,0,0.5);}"
        "h2{margin-top:0;color:#f59e0b;font-size:20px;text-align:center;}"
        ".info{font-size:13px;color:#94a3b8;margin-bottom:18px;text-align:center;line-height:1.7;}"
        ".badge{display:inline-block;background:#334155;color:#f8fafc;padding:2px 8px;border-radius:6px;font-family:monospace;font-size:12px;}"
        "label{display:block;font-size:13px;font-weight:600;color:#cbd5e1;margin-top:12px;margin-bottom:6px;}"
        "input{width:100%;box-sizing:border-box;padding:11px;background:#0b1120;border:1px solid #334155;border-radius:8px;color:#fff;font-size:14px;}"
        "button{width:100%;background:#d97706;color:#fff;font-weight:bold;border:none;padding:13px;border-radius:8px;font-size:14px;margin-top:18px;cursor:pointer;}"
        "button:hover{background:#b45309;}"
        ".footer{font-size:12px;text-align:center;color:#64748b;margin-top:16px;}"
        "a{color:#38bdf8;text-decoration:none;}"
        "</style></head><body><div class='card'>"
        "<h2>Installer Configuration</h2>");

    if (!isAuthorized) {
        html += F("<p class='info'>Enter installer security PIN to access deployment settings.</p>"
            "<form method='GET' action='/installer'>"
            "<label>Security PIN</label>"
            "<input type='password' name='pin' placeholder='Enter PIN' required autofocus>"
            "<button type='submit'>Access Installer Mode</button>"
            "</form>");
    } else {
        html += F("<div class='info'>Device MAC: <span class='badge'>");
        html += getDeviceMac();
        html += F("</span><br>Universal Firmware: <span class='badge'>v");
        html += FIRMWARE_VERSION;
        html += F("</span></div>"
            "<form method='POST' action='/installer/save'>"
            "<input type='hidden' name='pin' value='");
        html += pin;
        html += F("'>"
            "<label>VendoLink Server API URL</label>"
            "<input type='text' name='server' value='");
        html += configServerUrl;
        html += F("' placeholder='https://vendolink.online/api' required>"
            "<label>Per-Device Bootstrap Token</label>"
            "<input type='password' name='boot_token' value='' placeholder='Enter per-device bootstrap token'>"
            "<button type='submit'>Save Deployment Settings</button>"
            "</form>");
    }

    html += F("<div class='footer'><a href='/'>&larr; Back to Customer Wi-Fi Setup</a></div>"
        "</div></body></html>");

    apServer->send(200, "text/html", html);
}

void handleInstallerSave() {
    if (!apServer) return;
    String pin = apServer->arg("pin");
    if (pin != String(INSTALLER_PORTAL_PIN)) {
        apServer->send(403, "text/plain", "Forbidden: Invalid installer PIN.");
        return;
    }

    String sServer = apServer->arg("server");
    String sBoot   = apServer->arg("boot_token");
    sServer.trim();
    sBoot.trim();

    if (sServer.length() == 0) {
        apServer->send(400, "text/plain", "Bad Request: Server URL cannot be empty.");
        return;
    }

    saveInstallerConfig(sServer, sBoot);

    String resp = F("<!DOCTYPE html><html><head><meta name='viewport' content='width=device-width, initial-scale=1'>"
        "<title>Installer Saved</title><style>"
        "body{font-family:sans-serif;background:#0a0f1d;color:#f59e0b;padding:40px;text-align:center;}"
        ".box{max-width:380px;margin:auto;background:#131d36;padding:30px;border-radius:12px;border:1px solid #f59e0b;}"
        "h3{color:#f59e0b;} p{color:#cbd5e1;}"
        "a{color:#38bdf8;text-decoration:none;}"
        "</style></head><body><div class='box'>"
        "<h3>Installer Settings Saved</h3>"
        "<p>Server URL and bootstrap token updated in NVS.</p>"
        "<p><a href='/'>&larr; Return to Setup</a></p>"
        "</div></body></html>");

    apServer->send(200, "text/html", resp);
}

void startCaptivePortal() {
    if (apModeActive) return;

    String apSSID = getApSSID();
    WiFi.disconnect(true);
    WiFi.mode(WIFI_AP_STA);
    WiFi.softAP(apSSID.c_str(), AP_DEFAULT_PASS);

    delay(200);

    IPAddress apIP(192, 168, 4, 1);
    WiFi.softAPConfig(apIP, apIP, IPAddress(255, 255, 255, 0));

    if (!dnsServer) dnsServer = new DNSServer();
    dnsServer->setErrorReplyCode(DNSReplyCode::NoError);
    dnsServer->start(53, "*", apIP);

    if (!apServer) apServer = new WebServer(80);
    apServer->on("/", HTTP_GET, handleCaptivePortalRoot);
    apServer->on("/save", HTTP_POST, handleCaptivePortalSave);
    apServer->on("/installer", HTTP_GET, handleInstallerRoot);
    apServer->on("/installer", HTTP_POST, handleInstallerRoot);
    apServer->on("/installer/save", HTTP_POST, handleInstallerSave);
    apServer->onNotFound(handleCaptivePortalRoot);
    apServer->begin();

    apModeActive = true;
    apModeStartedAt = millis();

    Serial.println(F("========================================"));
    Serial.println(F("📲 [CAPTIVE PORTAL ACTIVE]"));
    Serial.print(F("   SSID:     ")); Serial.println(apSSID);
    Serial.print(F("   Password: ")); Serial.println(AP_DEFAULT_PASS);
    Serial.println(F("   IP:       192.168.4.1"));
    Serial.println(F("   Connect with phone to configure Wi-Fi"));
    Serial.println(F("========================================"));

    // Signal Core 1 (exclusive LCD owner) to display captive portal setup screen
    lcdNeedsRefresh = true;
}

void stopCaptivePortal() {
    if (!apModeActive) return;
    if (apServer) {
        apServer->stop();
        delete apServer;
        apServer = nullptr;
    }
    if (dnsServer) {
        dnsServer->stop();
        delete dnsServer;
        dnsServer = nullptr;
    }
    WiFi.softAPdisconnect(true);
    WiFi.mode(WIFI_STA);
    apModeActive = false;
    lcdNeedsRefresh = true;
    Serial.println(F("📶 [AP CLOSED] Captive Portal closed. Resuming Wi-Fi client mode."));
}

// Forward declaration of JSON helper functions
String extractJsonString(String body, String keyName, String defaultVal);

// ------------------------------------------------------------
// Automated Device Enrollment Handshake
// ------------------------------------------------------------
bool performDeviceEnrollment() {
    if (WiFi.status() != WL_CONNECTED) return false;

    String regUrl = buildEndpointUrl("/machine/register.php");
    Serial.println(F("🔑 [ENROLL] Handshaking with VendoLink enrollment endpoint..."));
    Serial.print(F("   URL: ")); Serial.println(regUrl);

    WiFiClientSecure secClient;
    WiFiClient plainClient;
    HTTPClient http;
    setupHttpConnection(http, regUrl, secClient, plainClient);
    http.setConnectTimeout(6000); // 6-second socket connect timeout
    http.setTimeout(5000);        // 5-second response read timeout
    http.addHeader("Content-Type", "application/json");
    if (configEnrollSecret.length() > 0) {
        http.addHeader("X-Bootstrap-Token", configEnrollSecret.c_str());
        http.addHeader("X-Enrollment-Token", configEnrollSecret.c_str());
    }

    String ipStr = WiFi.localIP().toString();
    String payload = "{";
    payload += "\"device_mac\":\"" + getDeviceMac() + "\",";
    payload += "\"firmware_version\":\"" + String(FIRMWARE_VERSION) + "\",";
    payload += "\"model\":\"ESP32-WROOM-32\",";
    payload += "\"ip_address\":\"" + ipStr + "\"";
    payload += "}";

    int code = http.POST(payload);
    Serial.print(F("🔑 [ENROLL] Response status: ")); Serial.println(code);

    if (code == 200 || code == 201) {
        String resp = http.getString();
        http.end();

        String mKey  = extractJsonString(resp, "machine_key", "");
        String mCode = extractJsonString(resp, "machine_code", "");
        String token = extractJsonString(resp, "api_token", "");

        if (mKey.length() > 0 && token.length() > 0) {
            saveDeviceCredentials(mKey, mCode, token);
            Serial.println(F("========================================"));
            Serial.println(F("✅ [ENROLL SUCCESS] Device enrolled successfully!"));
            Serial.print(F("   Assigned Machine Key:  ")); Serial.println(mKey);
            Serial.print(F("   Assigned Machine Code: ")); Serial.println(mCode);
            Serial.print(F("   Security Token:        ")); Serial.println(maskSecret(token.c_str()));
            Serial.println(F("========================================"));
            playTone(1500, 120);
            delay(80);
            playTone(2000, 200);
            return true;
        } else {
            Serial.println(F("❌ [ENROLL ERROR] Incomplete credentials in server response."));
            return false;
        }
    } else {
        http.end();
        if (code < 0) {
            Serial.print(F("❌ [ENROLL FAILED] Network connection failed (HTTP "));
            Serial.print(code);
            Serial.println(F("). Server unreachable — check Wi-Fi, Server IP (config.h), or Apache port."));
        } else if (code == 401 || code == 403) {
            Serial.print(F("❌ [ENROLL FAILED] Server rejected authorization (HTTP "));
            Serial.print(code);
            Serial.println(F("). Device requires valid bootstrap credential."));
        } else {
            Serial.print(F("❌ [ENROLL FAILED] Server error during enrollment (HTTP "));
            Serial.print(code);
            Serial.println(F(")."));
        }
        return false;
    }
}

// ============================================================
// Forward Declarations
// ============================================================
void printLCDLine(int row, String text);
void updateLCDDisplay();
void updateCoinSlotPower();
void playBeep(int durationMs = 80, int count = 1);
void playTone(int frequency, int durationMs);
void playCompletionMelody();
void runPreStartCountdown();
void checkTankSensors();
void handleServiceButtonPress(String serviceKeyName, int relayPin, float serviceCost, int durationSeconds);
void stopRunningService();
void savePersistentCredit(float credit);
float loadPersistentCredit();
void showInterruptedSessionScreen(int subPressState);
void handleRecoveryButtonPress();
void cancelInterruptedSession();
void resumeInterruptedSession();
void performOtaUpdate(const String& downloadUrl, const String& newVersion);



// ============================================================
// ⚡ Power-Interruption Session Recovery (NVS Preferences)
// ============================================================
Preferences sessionPrefs;

bool          recoveryModeActive        = false;
String        recoveryServiceKey        = "";
String        recoveryServiceName       = "";
float         recoveryAmount            = 0.0;
float         recoveryCredit            = 0.0;
int           recoveryTotalSec          = 0;
int           recoveryRemainingSec      = 0;
int           recoveryRelayPin          = -1;
String        recoveryClientTxId        = "";
int           recoveryPressCount        = 0;
unsigned long recoveryLastPressTime     = 0;
static const unsigned long RECOVERY_WINDOW_MS = 1500; // Multi-press window (1.5s)

// Checkpoint timing tracking (every 5 seconds during active service)
unsigned long lastSessionCheckpointTime = 0;
static const unsigned long SESSION_CHECKPOINT_INTERVAL_MS = 5000;

// Current Active Session clientTxId
String currentSessionTxId = "";

// ============================================================
// Global State & Handles
// ============================================================
LiquidCrystal_I2C* lcd = nullptr;

// FreeRTOS Task and Mutex Handles
TaskHandle_t      netTaskHandle = nullptr;
SemaphoreHandle_t queueMutex    = nullptr;

// Offline Queue runtime state
bool        offlineStorageReady = false;
QueueHeader queueHeader         = { OFFLINE_QUEUE_MAGIC, 0, 0, OFFLINE_QUEUE_MAX, 0, 0 };
volatile uint16_t offlinePendingCount = 0;

// 🌐 Network & Server Availability State (Managed by Core 0)
volatile bool isWiFiConnected   = false;
volatile bool isServerOnline    = false;
volatile bool serverStateLogged = false;
volatile bool isOtaUpdating     = false;

// Pending tank alert queue (Core 1 sets -> Core 0 transmits)
// -1 = no alert, 0 = low tank, 1 = normal tank
volatile int pendingWaterAlert = -1;
volatile int pendingSoapAlert  = -1;

// 🪙 Local Credit Balance (Accumulates on Coin Drop)
float currentCredit = 0.0;

// 🛠️ Hardware Diagnostic Maintenance Mode (3-Second Dual Hold)
bool          diagnosticScreenActive = false;
unsigned long diagnosticScreenUntil  = 0;
unsigned long dualBtnHoldStart       = 0;
int           lastDiagCountdownSec   = -1;

// ⚙️ Dynamic Configured Rates, Times & Service Status (Synced from Website via Heartbeat)
float waterCoinValue        = 5.0;
float soapCoinValue         = 5.0;
int   waterServiceSec       = 120; // Default: 2:00
int   soapServiceSec        = 60;  // Default: 1:00
bool  isWaterServiceEnabled = true;
bool  isSoapServiceEnabled  = true;

// 📺 20x4 LCD Screen Customizer Lines (Synced from Website)
String customLcdLine1 = "== VendoLink v" + String(FIRMWARE_VERSION).substring(0, 3) + " ==";
String customLcdLine2 = "WELCOME CUSTOMER!";
String customLcdLine3 = "INSERT COIN FIRST!";
String customLcdLine4 = "WATER:P5 | SOAP:P5";

// Machine Profile & Dynamic Service Names (Carwash vs Tire Air & Blower vs Solo Inflator)
String machineType       = "carwash";       // "carwash", "air_blower", "tire_air"
String relay1ServiceName = "WATER SERVICE"; // Dynamic name for Relay 1
String relay2ServiceName = "SOAP SERVICE";  // Dynamic name for Relay 2

// ------------------------------------------------------------
// Persistent Machine Service & LCD Configuration (NVS "vlsvc")
// Ensures owner's custom times/rates persist across power cuts & offline mode
// ------------------------------------------------------------
void loadServiceConfigFromNVS() {
    Preferences nvsSvc;
    nvsSvc.begin("vlsvc", true);

    waterServiceSec       = nvsSvc.getInt("w_sec", 120);
    soapServiceSec        = nvsSvc.getInt("s_sec", 60);
    waterCoinValue        = nvsSvc.getFloat("w_coin", 5.0f);
    soapCoinValue         = nvsSvc.getFloat("s_coin", 5.0f);
    isWaterServiceEnabled = nvsSvc.getBool("w_en", true);
    isSoapServiceEnabled  = nvsSvc.getBool("s_en", true);

    String savedMType = nvsSvc.getString("m_type", "");
    if (savedMType.length() > 0) machineType = savedMType;

    String savedR1 = nvsSvc.getString("r1_name", "");
    if (savedR1.length() > 0) {
        relay1ServiceName = savedR1;
    } else if (machineType == "air_blower" || machineType == "tire_air") {
        relay1ServiceName = "TIRE AIR";
    } else {
        relay1ServiceName = "WATER SERVICE";
    }

    String savedR2 = nvsSvc.getString("r2_name", "");
    if (savedR2.length() > 0) {
        relay2ServiceName = savedR2;
    } else if (machineType == "air_blower") {
        relay2ServiceName = "CAR BLOWER";
    } else {
        relay2ServiceName = "SOAP SERVICE";
    }

    String savedL1 = nvsSvc.getString("l1", "");
    if (savedL1.length() > 0) customLcdLine1 = savedL1;
    String savedL2 = nvsSvc.getString("l2", "");
    if (savedL2.length() > 0) customLcdLine2 = savedL2;
    String savedL3 = nvsSvc.getString("l3", "");
    if (savedL3.length() > 0) customLcdLine3 = savedL3;
    String savedL4 = nvsSvc.getString("l4", "");
    if (savedL4.length() > 0) customLcdLine4 = savedL4;

    nvsSvc.end();

    Serial.println(F("⚙️ [NVS Services] Loaded Persistent Machine Service Config:"));
    Serial.print(F("   Machine Type: ")); Serial.println(machineType);
    Serial.print(F("   Relay 1: ")); Serial.print(relay1ServiceName);
    Serial.print(F(" | Time: ")); Serial.print(waterServiceSec);
    Serial.print(F("s | Rate: P")); Serial.print(waterCoinValue, 2);
    Serial.println(isWaterServiceEnabled ? F(" [ENABLED]") : F(" [DISABLED]"));
    Serial.print(F("   Relay 2: ")); Serial.print(relay2ServiceName);
    Serial.print(F(" | Time: ")); Serial.print(soapServiceSec);
    Serial.print(F("s | Rate: P")); Serial.print(soapCoinValue, 2);
    Serial.println(isSoapServiceEnabled ? F(" [ENABLED]") : F(" [DISABLED]"));
}

void saveServiceConfigToNVS() {
    Preferences nvsSvc;
    if (!nvsSvc.begin("vlsvc", false)) {
        Serial.println(F("❌ [NVS Services] Failed to open 'vlsvc' namespace for writing!"));
        return;
    }

    nvsSvc.putInt("w_sec", waterServiceSec);
    nvsSvc.putInt("s_sec", soapServiceSec);
    nvsSvc.putFloat("w_coin", waterCoinValue);
    nvsSvc.putFloat("s_coin", soapCoinValue);
    nvsSvc.putBool("w_en", isWaterServiceEnabled);
    nvsSvc.putBool("s_en", isSoapServiceEnabled);
    nvsSvc.putString("m_type", machineType);
    nvsSvc.putString("r1_name", relay1ServiceName);
    nvsSvc.putString("r2_name", relay2ServiceName);
    nvsSvc.putString("l1", customLcdLine1);
    nvsSvc.putString("l2", customLcdLine2);
    nvsSvc.putString("l3", customLcdLine3);
    nvsSvc.putString("l4", customLcdLine4);

    nvsSvc.end();
    Serial.println(F("💾 [NVS Services] Machine service config successfully saved to NVS!"));
}

// Service Relay & Active Service Tracking
volatile bool activeServiceRunning = false;
bool          isServicePaused      = false;
bool          pauseUsed            = false;
bool          resumeUsed           = false;
String        activeServiceName    = "";
String        activeServiceKey     = "";
float         activeServiceCost    = 0.0;
int           activeServiceSecs    = 0;
int           activeRelayPin       = -1;
unsigned long serviceStartTime     = 0;
unsigned long serviceDurationMs    = 120000;
int           lastWarningBeepSec   = -1;

// Non-blocking completion screen tracking
bool          completionScreenActive = false;
unsigned long completionScreenUntil  = 0;

// Button Debounce tracking
unsigned long lastWaterBtnTime = 0;
unsigned long lastSoapBtnTime  = 0;
int           lastWaterBtnState = HIGH;
int           lastSoapBtnState  = HIGH;

// 🌊 Tank Floater Sensor Tracking (Edge Detection)
int lastWaterFloaterState = -1;
int lastSoapFloaterState  = -1;

// 🔌 HW-482 Coin Slot Power Relay State Tracking (GPIO 14)
bool coinSlotPowered    = false;
bool creditCutoffActive = false;

// ============================================================
// 🪙 Customer Credit Persistence Functions (NVS)
// ============================================================
void savePersistentCredit(float credit) {
    Preferences cp;
    cp.begin("vlcredit", false);
    cp.putFloat("bal", credit);
    cp.end();
    Serial.print(F("💾 [Credit Persist] Saved customer credit balance: ₱"));
    Serial.println(credit);
}

float loadPersistentCredit() {
    Preferences cp;
    cp.begin("vlcredit", true); // read-only
    float c = cp.getFloat("bal", 0.0);
    cp.end();
    if (c < 0.0 || isnan(c)) c = 0.0;
    if (c > MAX_CREDIT_LIMIT) c = MAX_CREDIT_LIMIT;
    return c;
}

// ============================================================
// ⚡ Session Recovery Persistence Functions (NVS)
// ============================================================
void saveActiveSession(const char* sKey, const char* sName, float cost, float credit, int totalSec, int remSec, int rPin, const char* txId) {
    sessionPrefs.begin("vlsession", false);
    sessionPrefs.putBool("active", true);
    sessionPrefs.putString("key", sKey);
    sessionPrefs.putString("name", sName);
    sessionPrefs.putFloat("cost", cost);
    sessionPrefs.putFloat("credit", credit);
    sessionPrefs.putInt("total", totalSec);
    sessionPrefs.putInt("rem", remSec);
    sessionPrefs.putInt("pin", rPin);
    sessionPrefs.putString("txId", txId);
    sessionPrefs.end();

    Serial.print(F("💾 [Session Persist] Saved active session: "));
    Serial.print(sName); Serial.print(F(" | Remaining: "));
    Serial.print(remSec); Serial.print(F("s | Credit: ₱"));
    Serial.println(credit);
}

void checkpointSessionRemainingTime(int remSec) {
    sessionPrefs.begin("vlsession", false);
    sessionPrefs.putInt("rem", remSec);
    sessionPrefs.end();
}

void clearActiveSession() {
    sessionPrefs.begin("vlsession", false);
    sessionPrefs.putBool("active", false);
    sessionPrefs.putInt("rem", 0);
    sessionPrefs.clear();
    sessionPrefs.end();
    currentSessionTxId   = "";
    recoveryRemainingSec = 0;
    recoveryServiceKey   = "";
    recoveryServiceName  = "";
    recoveryRelayPin     = -1;
    Serial.println(F("🧹 [Session Persist] Active service session cleared."));
}

bool loadActiveSession() {
    sessionPrefs.begin("vlsession", true); // Read-only
    bool active = sessionPrefs.getBool("active", false);
    if (!active) {
        sessionPrefs.end();
        return false;
    }

    recoveryServiceKey   = sessionPrefs.getString("key", "water");
    recoveryServiceName  = sessionPrefs.getString("name", "WATER SERVICE");
    recoveryAmount       = sessionPrefs.getFloat("cost", 5.0);
    recoveryCredit       = sessionPrefs.getFloat("credit", 0.0);
    recoveryTotalSec     = sessionPrefs.getInt("total", 120);
    recoveryRemainingSec = sessionPrefs.getInt("rem", 0);
    recoveryRelayPin     = sessionPrefs.getInt("pin", WATER_RELAY_PIN);
    recoveryClientTxId   = sessionPrefs.getString("txId", "");
    sessionPrefs.end();

    // If persistent credit namespace hasn't been initialized or is 0, backfill from recoveryCredit
    if (currentCredit == 0.0 && recoveryCredit > 0.0) {
        currentCredit = recoveryCredit;
        savePersistentCredit(currentCredit);
    }

    if (recoveryRemainingSec <= 0) {
        clearActiveSession();
        return false;
    }
    return true;
}

// ============================================================
// 🛡️ Software Coin Pulse Validation & Noise Filtering
// ============================================================
volatile unsigned long isrPulseFallUs      = 0;
volatile unsigned long isrLastValidRiseMs  = 0;
volatile bool          isrPulseInProgress  = false;
volatile int           pulseCount          = 0;
volatile unsigned long lastPulseTime       = 0;
volatile bool          pulseActive         = false;

void IRAM_ATTR coinPinChangeISR() {
    int pinState = digitalRead(COIN_SIGNAL_PIN);
    unsigned long nowUs = micros();
    unsigned long nowMs = millis();

    if (pinState == LOW) {
        isrPulseFallUs = nowUs;
        isrPulseInProgress = true;
    } else {
        if (isrPulseInProgress) {
            isrPulseInProgress = false;
            unsigned long pulseWidthUs = nowUs - isrPulseFallUs;
            unsigned long pulseWidthMs = pulseWidthUs / 1000;

            if (pulseWidthMs >= 15 && pulseWidthMs <= 110) {
                if (pulseCount == 0) {
                    pulseCount = 1;
                    isrLastValidRiseMs = nowMs;
                    lastPulseTime = nowMs;
                    pulseActive = true;
                } else {
                    unsigned long spacingMs = nowMs - isrLastValidRiseMs;
                    if (spacingMs >= 50 && spacingMs <= 250) {
                        pulseCount++;
                        isrLastValidRiseMs = nowMs;
                        lastPulseTime = nowMs;
                        pulseActive = true;
                    }
                }
            }
        }
    }
}

// ============================================================
// 💾 LittleFS Offline Queue Implementation (Thread-Safe)
// ============================================================
size_t getRecordFileOffset(uint16_t slot) {
    return sizeof(QueueHeader) + ((size_t)slot * sizeof(OfflineRecord));
}

bool saveQueueHeader() {
    if (!offlineStorageReady) return false;
    File f = LittleFS.open(OFFLINE_QUEUE_FILE, "r+");
    if (!f) {
        f = LittleFS.open(OFFLINE_QUEUE_FILE, "w");
        if (!f) return false;
    }
    f.seek(0);
    bool ok = (f.write((const uint8_t*)&queueHeader, sizeof(QueueHeader)) == sizeof(QueueHeader));
    f.flush();
    f.close();
    return ok;
}

void initOfflineStorage() {
    offlineStorageReady = LittleFS.begin(false);
    if (!offlineStorageReady) {
        Serial.println(F("⚠️ [Offline Storage] LittleFS mount failed; trying safe init..."));
        offlineStorageReady = LittleFS.begin(true);
    }

    if (!offlineStorageReady) {
        Serial.println(F("❌ [Offline Storage] LittleFS could not be initialized. Queue disabled."));
        return;
    }

    File f = LittleFS.open(OFFLINE_QUEUE_FILE, "r");
    bool validHeader = false;
    if (f && f.size() >= sizeof(QueueHeader)) {
        if (f.read((uint8_t*)&queueHeader, sizeof(QueueHeader)) == sizeof(QueueHeader)) {
            if (queueHeader.magic == OFFLINE_QUEUE_MAGIC && queueHeader.count <= OFFLINE_QUEUE_MAX) {
                validHeader = true;
                offlinePendingCount = queueHeader.count;
            }
        }
        f.close();
    }

    if (!validHeader) {
        Serial.println(F("💾 [Offline Storage] Initializing fresh queue header..."));
        queueHeader.magic     = OFFLINE_QUEUE_MAGIC;
        queueHeader.head      = 0;
        queueHeader.count     = 0;
        queueHeader.capacity  = OFFLINE_QUEUE_MAX;
        queueHeader.reserved  = 0;
        queueHeader.lastTxSeq = 0;
        saveQueueHeader();
        offlinePendingCount = 0;
    }

    Serial.println(F("========================================"));
    Serial.println(F("💾 OFFLINE TRANSACTION QUEUE READY"));
    Serial.print(F("Storage Partition Total: ")); Serial.print(LittleFS.totalBytes()); Serial.println(F(" bytes"));
    Serial.print(F("Storage Partition Used:  ")); Serial.print(LittleFS.usedBytes()); Serial.println(F(" bytes"));
    Serial.print(F("Record Size:             ")); Serial.print(sizeof(OfflineRecord)); Serial.println(F(" bytes"));
    Serial.print(F("Max Queue Capacity:      ")); Serial.print(OFFLINE_QUEUE_MAX); Serial.println(F(" records"));
    Serial.print(F("Current Pending Records: ")); Serial.println(offlinePendingCount);
    Serial.println(F("========================================"));
}

bool offlineQueueAppendWithTxId(const char* serviceKeyName, float amount, uint32_t durationSec, const char* txId, uint8_t status = TX_COMPLETED, uint16_t actualRuntimeSec = 0) {
    if (!offlineStorageReady) {
        Serial.println(F("⚠️ [Offline Queue] Storage not ready; cannot store transaction."));
        return false;
    }

    if (queueMutex) xSemaphoreTake(queueMutex, portMAX_DELAY);

    if (queueHeader.count >= OFFLINE_QUEUE_MAX) {
        if (queueMutex) xSemaphoreGive(queueMutex);
        Serial.println(F("🛑 [Offline Queue] OFFLINE QUEUE FULL — 500 pending records. No overwrite allowed."));
        return false;
    }

    uint16_t targetSlot = (queueHeader.head + queueHeader.count) % OFFLINE_QUEUE_MAX;
    queueHeader.lastTxSeq++;

    OfflineRecord rec;
    memset(&rec, 0, sizeof(rec));
    rec.magic = OFFLINE_RECORD_MAGIC;
    rec.seq = queueHeader.lastTxSeq;
    rec.createdEpoch = (uint32_t)time(nullptr);
    if (rec.createdEpoch < 1000000000UL) {
        rec.createdEpoch = (uint32_t)(millis() / 1000);
    }
    rec.durationSeconds = durationSec;
    rec.amount = amount;
    if (txId && strlen(txId) > 0) {
        strncpy(rec.clientTxId, txId, sizeof(rec.clientTxId) - 1);
    } else {
        uint64_t chipMac = ESP.getEfuseMac();
        char macBuf[16];
        snprintf(macBuf, sizeof(macBuf), "%04X%08X", (uint16_t)(chipMac >> 32), (uint32_t)chipMac);
        snprintf(rec.clientTxId, sizeof(rec.clientTxId), "VL-%s-%05u", macBuf, (unsigned int)(queueHeader.lastTxSeq % 100000));
    }
    strncpy(rec.machineKey, configMachineKey.c_str(), sizeof(rec.machineKey) - 1);
    strncpy(rec.serviceKey, serviceKeyName, sizeof(rec.serviceKey) - 1);
    rec.status = status;
    rec.actualRuntimeSeconds = (status == TX_COMPLETED && actualRuntimeSec == 0) ? (uint16_t)durationSec : actualRuntimeSec;

    File f = LittleFS.open(OFFLINE_QUEUE_FILE, "r+");
    if (!f) {
        f = LittleFS.open(OFFLINE_QUEUE_FILE, "w");
        if (!f) {
            if (queueMutex) xSemaphoreGive(queueMutex);
            return false;
        }
        saveQueueHeader();
    }

    f.seek(getRecordFileOffset(targetSlot));
    size_t written = f.write((const uint8_t*)&rec, sizeof(OfflineRecord));
    f.flush();
    f.close();

    if (written != sizeof(OfflineRecord)) {
        if (queueMutex) xSemaphoreGive(queueMutex);
        Serial.println(F("❌ [Offline Queue] Write failure."));
        return false;
    }

    queueHeader.count++;
    saveQueueHeader();
    offlinePendingCount = queueHeader.count;

    if (queueMutex) xSemaphoreGive(queueMutex);

    Serial.print(F("💾 [Offline Queue] Stored completed transaction "));
    Serial.print(rec.clientTxId);
    Serial.print(F(" | Pending: "));
    Serial.println(offlinePendingCount);
    return true;
}

bool offlineQueuePeekOldest(OfflineRecord &outRec) {
    if (!offlineStorageReady || offlinePendingCount == 0) return false;

    if (queueMutex) xSemaphoreTake(queueMutex, portMAX_DELAY);

    if (queueHeader.count == 0) {
        if (queueMutex) xSemaphoreGive(queueMutex);
        return false;
    }

    File f = LittleFS.open(OFFLINE_QUEUE_FILE, "r");
    if (!f) {
        if (queueMutex) xSemaphoreGive(queueMutex);
        return false;
    }

    f.seek(getRecordFileOffset(queueHeader.head));
    size_t r = f.read((uint8_t*)&outRec, sizeof(OfflineRecord));
    f.close();

    if (queueMutex) xSemaphoreGive(queueMutex);

    return (r == sizeof(OfflineRecord) && outRec.magic == OFFLINE_RECORD_MAGIC);
}

bool offlineQueuePopOldest() {
    if (!offlineStorageReady || offlinePendingCount == 0) return false;

    if (queueMutex) xSemaphoreTake(queueMutex, portMAX_DELAY);

    if (queueHeader.count == 0) {
        if (queueMutex) xSemaphoreGive(queueMutex);
        return false;
    }

    queueHeader.head = (queueHeader.head + 1) % OFFLINE_QUEUE_MAX;
    queueHeader.count--;
    saveQueueHeader();
    offlinePendingCount = queueHeader.count;

    if (queueMutex) xSemaphoreGive(queueMutex);
    return true;
}

// ============================================================
// JSON Helper Functions
// ============================================================
float extractJsonFloat(String body, String keyName, float defaultVal) {
    int keyIdx = body.indexOf("\"" + keyName + "\"");
    if (keyIdx == -1) return defaultVal;
    int colonIdx = body.indexOf(":", keyIdx);
    if (colonIdx == -1) return defaultVal;
    int commaIdx = body.indexOf(",", colonIdx);
    int braceIdx = body.indexOf("}", colonIdx);
    int endIdx = (commaIdx != -1 && (braceIdx == -1 || commaIdx < braceIdx)) ? commaIdx : braceIdx;
    if (endIdx == -1) return defaultVal;

    String valStr = body.substring(colonIdx + 1, endIdx);
    valStr.replace("\"", "");
    valStr.trim();
    return valStr.toFloat();
}

String extractJsonString(String body, String keyName, String defaultVal) {
    int keyIdx = body.indexOf("\"" + keyName + "\"");
    if (keyIdx == -1) return defaultVal;
    int colonIdx = body.indexOf(":", keyIdx);
    if (colonIdx == -1) return defaultVal;
    int firstQuote = body.indexOf("\"", colonIdx);
    if (firstQuote == -1) return defaultVal;
    int secondQuote = body.indexOf("\"", firstQuote + 1);
    if (secondQuote == -1) return defaultVal;

    String val = body.substring(firstQuote + 1, secondQuote);
    val.replace("\\/", "/");
    return val;
}

// ============================================================
// ⚡ Wireless Over-The-Air (OTA) Firmware Flasher
// ============================================================
void performOtaUpdate(const String& rawDownloadUrl, const String& newVersion) {
    isOtaUpdating = true; // Block Core 1 loop from touching LCD or GPIOs
    delay(100);           // Allow Core 1 to yield

    String downloadUrl = rawDownloadUrl;
    downloadUrl.replace("\\/", "/");
    downloadUrl.trim();

    String cleanVer = newVersion;
    if (cleanVer.startsWith("v") || cleanVer.startsWith("V")) {
        cleanVer = cleanVer.substring(1);
    }

    // 1. Safety: Turn off all relays immediately
    digitalWrite(WATER_RELAY_PIN, RELAY_OFF_STATE);
    digitalWrite(SOAP_RELAY_PIN, RELAY_OFF_STATE);
    // Cut off coin slot power to prevent coin drops during update
    digitalWrite(COIN_POWER_RELAY_PIN, COIN_RELAY_POWER_OFF);

    // 2. Clear LCD and Display OTA Update Notice on 20x4 LCD
    clearLCD();
    printLCDLine(0, "== VendoLink OTA ==");
    printLCDLine(1, "UPDATING FIRMWARE...");
    char verBuf[21];
    snprintf(verBuf, sizeof(verBuf), "v%s -> v%s", FIRMWARE_VERSION, cleanVer.c_str());
    printLCDLine(2, String(verBuf));
    printLCDLine(3, "CONNECTING...");

    // Beep to alert technician/owner
    playTone(1000, 150);
    delay(100);
    playTone(1200, 200);

    Serial.println(F("========================================"));
    Serial.println(F("🚀 [OTA] STARTING FIRMWARE DOWNLOAD & FLASH"));
    Serial.print(F("   From: ")); Serial.println(downloadUrl);
    Serial.print(F("   Target Version: v")); Serial.println(cleanVer);
    Serial.print(F("   Free Heap: ")); Serial.print(ESP.getFreeHeap()); Serial.println(F(" bytes"));
    Serial.println(F("========================================"));

    httpUpdate.rebootOnUpdate(false);
    httpUpdate.setFollowRedirects(HTTPC_STRICT_FOLLOW_REDIRECTS);

    // Visual progress callback on 20x4 LCD
    httpUpdate.onProgress([](size_t cur, size_t total) {
        static int lastPct = -1;
        if (total > 0) {
            int pct = (int)((cur * 100) / total);
            if (pct != lastPct && pct % 10 == 0) {
                lastPct = pct;
                char pBuf[21];
                snprintf(pBuf, sizeof(pBuf), "PROGRESS: %d%%", pct);
                printLCDLine(3, String(pBuf));
                Serial.printf("⚡ [OTA] Progress: %d%% (%u / %u bytes)\n", pct, (unsigned)cur, (unsigned)total);
            }
        }
    });

    t_httpUpdate_return ret;
    if (downloadUrl.startsWith("https://")) {
        WiFiClientSecure secureClient;
        secureClient.setInsecure();
        secureClient.setTimeout(30);
        ret = httpUpdate.update(secureClient, downloadUrl);
    } else {
        WiFiClient client;
        client.setTimeout(30);
        ret = httpUpdate.update(client, downloadUrl);
    }

    if (ret == HTTP_UPDATE_OK) {
        Serial.println(F("✅ [OTA] FIRMWARE UPDATE SUCCESSFUL! Rebooting into new version..."));
        clearLCD();
        printLCDLine(0, "== UPDATE COMPLETE =");
        printLCDLine(1, "FIRMWARE INSTALLED!");
        printLCDLine(2, "NEW VERSION: v" + cleanVer);
        printLCDLine(3, "REBOOTING NOW...");
        playCompletionMelody();
        delay(2500);
        ESP.restart();
    } else {
        Serial.print(F("❌ [OTA] Firmware Update Failed. Error ("));
        Serial.print(httpUpdate.getLastError());
        Serial.print(F("): "));
        Serial.println(httpUpdate.getLastErrorString());

        clearLCD();
        printLCDLine(0, "== OTA FAILED ==");
        printLCDLine(1, "CODE: " + String(httpUpdate.getLastError()));
        String errStr = httpUpdate.getLastErrorString();
        if (errStr.length() > 20) errStr = errStr.substring(0, 20);
        printLCDLine(2, errStr);
        printLCDLine(3, "RESUMING IN 5s...");
        playTone(400, 500);
        delay(5000);
        isOtaUpdating = false;
        clearLCD();
        updateLCDDisplay();
    }
}

// ============================================================
// 🌐 CORE 0: Background Network Worker Task (FreeRTOS)
// ============================================================
void networkWorkerTask(void *pvParameters) {
    Serial.println(F("🌐 [Core 0] Background Network Worker Task Started."));

    unsigned long lastNetAttempt = 0;
    unsigned long lastHeartbeatAttempt = 0;
    unsigned long lastSyncAttempt = 0;
    unsigned long wifiConnectStartTime = 0;
    bool wifiAttemptInProgress = false;

    for (;;) {
        unsigned long now = millis();

        // 0. If Captive Portal is active, serve DNS & Web clients
        if (apModeActive) {
            if (dnsServer) dnsServer->processNextRequest();
            if (apServer) apServer->handleClient();

            // If user saved credentials, apModeActive became false, so stop AP
            if (!apModeActive) {
                stopCaptivePortal();
                lcdNeedsRefresh = true;
            } else if (isConfigured && (now - apModeStartedAt >= AP_PORTAL_TIMEOUT_MS)) {
                // If portal timed out and Wi-Fi credentials exist, retry Wi-Fi
                Serial.println(F("⏱️ [PORTAL TIMEOUT] Window expired. Re-attempting Wi-Fi connection."));
                stopCaptivePortal();
                lcdNeedsRefresh = true;
            }

            vTaskDelay(pdMS_TO_TICKS(10));
            continue;
        }

        // 1. Maintain Wi-Fi Connection
        if (WiFi.status() == WL_CONNECTED) {
            if (!isWiFiConnected) {
                isWiFiConnected = true;
                wifiAttemptInProgress = false;
                Serial.println();
                Serial.println(F("========================================"));
                Serial.print(F("📶 [NET] Wi-Fi Connected! IP: "));
                Serial.println(WiFi.localIP().toString());
                Serial.println(F("========================================"));
            }
        } else {
            isWiFiConnected = false;
            isServerOnline  = false;

            String targetSsid = configWifiSsid;
            String targetPass = configWifiPass;
            bool isFactoryAttempt = false;

            // If customer has not configured Wi-Fi, attempt connection to Factory / Workshop Hotspot
            if (targetSsid.length() == 0) {
                targetSsid = FACTORY_WIFI_SSID;
                targetPass = FACTORY_WIFI_PASS;
                isFactoryAttempt = true;
            }

            if (!wifiAttemptInProgress) {
                if (now - lastNetAttempt >= 10000) {
                    lastNetAttempt = now;
                    wifiConnectStartTime = now;
                    wifiAttemptInProgress = true;
                    if (isFactoryAttempt) {
                        Serial.println(F("----------------------------------------"));
                        Serial.print(F("🏭 [FACTORY PROVISION] Attempting Workshop Hotspot: "));
                        Serial.println(targetSsid);
                        Serial.println(F("   (Turn on phone hotspot: 'VendoLink-Factory' / 'factory12345')"));
                        Serial.println(F("----------------------------------------"));
                    } else {
                        Serial.print(F("📶 [NET] Connecting to Wi-Fi SSID: "));
                        Serial.print(targetSsid);
                        Serial.println(F(" (Password: [PROTECTED])"));
                    }
                    WiFi.mode(WIFI_STA);
                    WiFi.begin(targetSsid.c_str(), targetPass.c_str());
                }
            } else {
                if (now - wifiConnectStartTime >= 25000) {
                    if (isFactoryAttempt) {
                        Serial.println(F("⚠️ [FACTORY TIMEOUT] Workshop hotspot not detected (Customer location)."));
                        Serial.println(F("📲 Launching Captive Portal setup for customer Wi-Fi..."));
                        wifiAttemptInProgress = false;
                        startCaptivePortal();
                        vTaskDelay(pdMS_TO_TICKS(100));
                        continue;
                    } else {
                        // Wi-Fi was already configured by owner! This is an offline / power outage scenario.
                        // Machine MUST stay in normal operation with owner's custom LCD lines and times!
                        Serial.println(F("⚠️ [NET OFFLINE] Configured Wi-Fi not reachable (Outage / Router offline)."));
                        Serial.println(F("🔌 Machine continuing in 100% OFFLINE mode with owner's saved NVS config."));
                        wifiAttemptInProgress = false;
                        lastNetAttempt = now; // Silent background retry after 15 seconds
                    }
                }
            }
        }

        // 2. Only proceed if Wi-Fi connected AND machine is not actively dispensing
        if (isWiFiConnected && !activeServiceRunning) {

            // 2.1 Automated Device Self-Enrollment check
            if (!isDeviceEnrolled || configApiToken.length() == 0) {
                bool enrolled = performDeviceEnrollment();
                if (!enrolled) {
                    vTaskDelay(pdMS_TO_TICKS(5000));
                    continue;
                }
            }

            // 3. Server Reachability Probe / Heartbeat
            unsigned long heartbeatInterval = isServerOnline ? HEARTBEAT_INTERVAL_MS : 20000;
            if (now - lastHeartbeatAttempt >= heartbeatInterval) {
                lastHeartbeatAttempt = now;

                unsigned long startMs = millis();
                Serial.println(F("[NET:Heartbeat] Starting request..."));

                WiFiClientSecure secClient;
                WiFiClient plainClient;
                HTTPClient http;
                setupHttpConnection(http, buildEndpointUrl("/machine/heartbeat.php"), secClient, plainClient);
                http.addHeader("Content-Type", "application/json");
                http.addHeader("X-Machine-Token", configApiToken.c_str());
                http.setTimeout(4000);

                String ipStr = WiFi.localIP().toString();
                String jsonPayload = "{";
                jsonPayload += "\"machine_key\":\"" + configMachineKey + "\",";
                jsonPayload += "\"status\":\"online\",";
                jsonPayload += "\"ip_address\":\"" + ipStr + "\",";
                jsonPayload += "\"firmware_version\":\"" + String(FIRMWARE_VERSION) + "\"";
                if (recoveryModeActive && recoveryClientTxId.length() > 0) {
                    int actualRun = recoveryTotalSec - recoveryRemainingSec;
                    if (actualRun < 0) actualRun = 0;
                    jsonPayload += ",\"recovery_mode\":true";
                    jsonPayload += ",\"interrupted_session\":{";
                    jsonPayload += "\"client_transaction_id\":\"" + recoveryClientTxId + "\",";
                    jsonPayload += "\"service_key\":\"" + recoveryServiceKey + "\",";
                    jsonPayload += "\"amount\":" + String(recoveryAmount, 2) + ",";
                    jsonPayload += "\"total_seconds\":" + String(recoveryTotalSec) + ",";
                    jsonPayload += "\"remaining_seconds\":" + String(recoveryRemainingSec) + ",";
                    jsonPayload += "\"actual_runtime_seconds\":" + String(actualRun);
                    jsonPayload += "}";
                }
                jsonPayload += "}";

                int httpResponseCode = http.POST(jsonPayload);
                unsigned long elapsedMs = millis() - startMs;

                if (httpResponseCode == 200) {
                    if (!isServerOnline) {
                        isServerOnline = true;
                        Serial.println(F("----------------------------------------"));
                        Serial.println(F("🌐 [NET] VendoLink API Reachable! SERVER ONLINE."));
                        Serial.println(F("----------------------------------------"));
                    }
                    Serial.print(F("[NET:Heartbeat] Request finished: "));
                    Serial.print(elapsedMs);
                    Serial.println(F(" ms (HTTP 200 OK)"));

                    String responseBody = http.getString();

                    // 1. Extract Machine Profile Type ("carwash", "air_blower", "tire_air")
                    bool configChanged = false;

                    String mType = extractJsonString(responseBody, "machine_type", machineType);
                    if (mType.length() > 0 && mType != machineType) {
                        machineType = mType;
                        configChanged = true;
                        Serial.print(F("⚙️ [Config] Machine Profile updated from server: "));
                        Serial.println(machineType);
                        lcdNeedsRefresh = true;
                    }

                    int waterPos = responseBody.indexOf("\"water\"");
                    if (waterPos == -1) waterPos = responseBody.indexOf("\"air\"");
                    if (waterPos != -1) {
                        String waterChunk = responseBody.substring(waterPos);
                        float val   = extractJsonFloat(waterChunk, "coin_value", waterCoinValue);
                        float ssec  = extractJsonFloat(waterChunk, "service_seconds", (float)waterServiceSec);
                        float en    = extractJsonFloat(waterChunk, "is_enabled", isWaterServiceEnabled ? 1.0 : 0.0);
                        String wName = extractJsonString(waterChunk, "name", "");

                        if (val > 0 && abs(val - waterCoinValue) > 0.001f) {
                            waterCoinValue = val;
                            configChanged = true;
                        }
                        if (ssec > 0 && (int)ssec != waterServiceSec) {
                            waterServiceSec = (int)ssec;
                            configChanged = true;
                        }
                        bool newWEn = (en > 0.5);
                        if (newWEn != isWaterServiceEnabled) {
                            isWaterServiceEnabled = newWEn;
                            configChanged = true;
                        }
                        String newR1Name = "";
                        if (wName.length() > 0) {
                            newR1Name = wName;
                        } else if (machineType == "air_blower" || machineType == "tire_air") {
                            newR1Name = "TIRE AIR";
                        } else {
                            newR1Name = "WATER SERVICE";
                        }
                        if (newR1Name != relay1ServiceName) {
                            relay1ServiceName = newR1Name;
                            configChanged = true;
                        }
                    }

                    int soapPos = responseBody.indexOf("\"soap\"");
                    if (soapPos == -1) soapPos = responseBody.indexOf("\"blower\"");
                    if (soapPos != -1) {
                        String soapChunk = responseBody.substring(soapPos);
                        float val   = extractJsonFloat(soapChunk, "coin_value", soapCoinValue);
                        float ssec  = extractJsonFloat(soapChunk, "service_seconds", (float)soapServiceSec);
                        float en    = extractJsonFloat(soapChunk, "is_enabled", isSoapServiceEnabled ? 1.0 : 0.0);
                        String sName = extractJsonString(soapChunk, "name", "");

                        if (val > 0 && abs(val - soapCoinValue) > 0.001f) {
                            soapCoinValue = val;
                            configChanged = true;
                        }
                        if (ssec > 0 && (int)ssec != soapServiceSec) {
                            soapServiceSec = (int)ssec;
                            configChanged = true;
                        }
                        bool newSEn = (en > 0.5);
                        if (newSEn != isSoapServiceEnabled) {
                            isSoapServiceEnabled = newSEn;
                            configChanged = true;
                        }
                        String newR2Name = "";
                        if (sName.length() > 0) {
                            newR2Name = sName;
                        } else if (machineType == "air_blower") {
                            newR2Name = "CAR BLOWER";
                        } else {
                            newR2Name = "SOAP SERVICE";
                        }
                        if (newR2Name != relay2ServiceName) {
                            relay2ServiceName = newR2Name;
                            configChanged = true;
                        }
                    }

                    int lcdPos = responseBody.indexOf("\"lcd_custom\"");
                    if (lcdPos != -1) {
                        String lcdChunk = responseBody.substring(lcdPos);
                        String l1 = extractJsonString(lcdChunk, "line1", customLcdLine1);
                        String l2 = extractJsonString(lcdChunk, "line2", customLcdLine2);
                        String l3 = extractJsonString(lcdChunk, "line3", customLcdLine3);
                        String l4 = extractJsonString(lcdChunk, "line4", customLcdLine4);

                        if (l1 != customLcdLine1 || l2 != customLcdLine2 || l3 != customLcdLine3 || l4 != customLcdLine4) {
                            customLcdLine1 = l1;
                            customLcdLine2 = l2;
                            customLcdLine3 = l3;
                            customLcdLine4 = l4;
                            lcdNeedsRefresh = true;
                            configChanged = true;
                        }
                    }

                    // 💾 Persist to NVS if any machine rate, time, profile, or LCD customized lines changed
                    if (configChanged) {
                        saveServiceConfigToNVS();
                    }

                    // ⚡ Check for Wireless OTA Firmware Update
                    int otaPos = responseBody.indexOf("\"ota_update\"");
                    if (otaPos != -1) {
                        String otaChunk = responseBody.substring(otaPos);
                        if (otaChunk.indexOf("\"available\":true") != -1) {
                            String targetVer   = extractJsonString(otaChunk, "target_version", "");
                            String downloadUrl = extractJsonString(otaChunk, "download_url", "");

                            if (targetVer.length() > 0 && downloadUrl.length() > 0 && targetVer != String(FIRMWARE_VERSION)) {
                                Serial.print(F("⚡ [OTA] Pending Update: v"));
                                Serial.print(targetVer);
                                Serial.print(F(" | URL: "));
                                Serial.println(downloadUrl);

                                // Safety Check: Machine must be idle (no active service, no credit, no recovery mode)
                                if (!activeServiceRunning && currentCredit <= 0.01 && !recoveryModeActive) {
                                    Serial.println(F("⚡ [OTA] Machine is idle. Starting Over-The-Air Update..."));
                                    http.end();
                                    performOtaUpdate(downloadUrl, targetVer);
                                    return; // performOtaUpdate reboots upon completion
                                } else {
                                    Serial.println(F("⏳ [OTA] Machine is vending or has credit. Postponing update until idle."));
                                }
                            }
                        }
                    }
                } else {
                    if (httpResponseCode == 401 || httpResponseCode == 404) {
                        Serial.println(F("⚠️ [AUTH] Device token or identity rejected by server. Refreshing enrollment..."));
                        isDeviceEnrolled = false;
                        configApiToken = "";
                        nvsConfig.begin("vlcfg", false);
                        nvsConfig.remove("token");
                        nvsConfig.putBool("enrolled", false);
                        nvsConfig.end();
                    }

                    if (isServerOnline || !serverStateLogged) {
                        isServerOnline    = false;
                        serverStateLogged = true;
                        Serial.println(F("----------------------------------------"));
                        Serial.print(F("🌐 [NET] Server unreachable: "));
                        Serial.print(elapsedMs);
                        Serial.println(F(" ms (XAMPP/API OFF). Marked SERVER OFFLINE."));
                        Serial.println(F("----------------------------------------"));
                    } else {
                        Serial.print(F("[NET:Heartbeat] Server unreachable: "));
                        Serial.print(elapsedMs);
                        Serial.print(F(" ms (HTTP ")); Serial.print(httpResponseCode); Serial.println(F(")"));
                    }
                }
                http.end();
            }

            // 4. Background Offline Transaction Synchronization
            if (isServerOnline && offlinePendingCount > 0 && !activeServiceRunning) {
                if (now - lastSyncAttempt >= 4000) {
                    lastSyncAttempt = now;

                    OfflineRecord rec;
                    if (offlineQueuePeekOldest(rec)) {
                        unsigned long startMs = millis();
                        Serial.print(F("[NET:Sync] Starting transaction sync request for "));
                        Serial.print(rec.clientTxId);
                        Serial.println(F("..."));

                        WiFiClientSecure secClient;
                        WiFiClient plainClient;
                        HTTPClient http;
                        setupHttpConnection(http, buildEndpointUrl("/machine/coin.php"), secClient, plainClient);
                        http.addHeader("Content-Type", "application/json");
                        http.addHeader("X-Machine-Token", configApiToken.c_str());
                        http.setTimeout(4000);

                        String statusStr = (rec.status == TX_INCOMPLETE || rec.status == TX_CANCELLED) ? "incomplete" : "completed";
                        String jsonPayload = "{";
                        jsonPayload += "\"machine_key\":\"" + configMachineKey + "\",";
                        jsonPayload += "\"service_key\":\"" + String(rec.serviceKey) + "\",";
                        jsonPayload += "\"amount\":" + String(rec.amount, 2) + ",";
                        jsonPayload += "\"duration_seconds\":" + String(rec.durationSeconds) + ",";
                        jsonPayload += "\"actual_runtime_seconds\":" + String(rec.actualRuntimeSeconds) + ",";
                        jsonPayload += "\"status\":\"" + statusStr + "\",";
                        if (statusStr == "incomplete") {
                            jsonPayload += "\"status_reason\":\"POWER INTERRUPTION — SESSION CANCELLED\",";
                        }
                        jsonPayload += "\"client_transaction_id\":\"" + String(rec.clientTxId) + "\"";
                        jsonPayload += "}";

                        int httpCode = http.POST(jsonPayload);
                        unsigned long elapsedMs = millis() - startMs;
                        String responseBody = (httpCode > 0) ? http.getString() : "";
                        http.end();

                        bool confirmed = (httpCode == 200 || httpCode == 201 || httpCode == 409);
                        if (httpCode == 200 && responseBody.indexOf("\"success\":false") != -1) {
                            confirmed = false;
                        }

                        if (confirmed) {
                            offlineQueuePopOldest();
                            Serial.print(F("[NET:Sync] Request finished: "));
                            Serial.print(elapsedMs);
                            Serial.print(F(" ms. Confirmed & deleted locally: "));
                            Serial.print(rec.clientTxId);
                            Serial.print(F(" | Remaining Pending: "));
                            Serial.println(offlinePendingCount);
                        } else {
                            Serial.print(F("[NET:Sync] Server unreachable: "));
                            Serial.print(elapsedMs);
                            Serial.print(F(" ms (HTTP ")); Serial.print(httpCode);
                            Serial.println(F("). Marked SERVER OFFLINE. Local record preserved."));
                            isServerOnline = false;
                        }
                    }
                }
            }

            // 5. Background Tank Alerts
            if (isServerOnline && (pendingWaterAlert != -1 || pendingSoapAlert != -1)) {
                String tKey = (pendingWaterAlert != -1) ? "water" : "soap";
                int alertVal = (pendingWaterAlert != -1) ? pendingWaterAlert : pendingSoapAlert;
                if (pendingWaterAlert != -1) pendingWaterAlert = -1;
                else pendingSoapAlert = -1;

                String sStr = (alertVal == 0) ? "low" : "normal";

                unsigned long startMs = millis();
                Serial.print(F("[NET:TankAlert] Starting request for "));
                Serial.println(tKey);

                WiFiClientSecure secClient;
                WiFiClient plainClient;
                HTTPClient http;
                setupHttpConnection(http, buildEndpointUrl("/machine/tank.php"), secClient, plainClient);
                http.addHeader("Content-Type", "application/json");
                http.addHeader("X-Machine-Token", configApiToken.c_str());
                http.setTimeout(3000);

                String jsonBody = "{\"machine_key\":\"" + configMachineKey +
                                  "\",\"tank_key\":\"" + tKey +
                                  "\",\"status\":\"" + sStr +
                                  "\",\"api_token\":\"" + configApiToken + "\"}";

                int httpCode = http.POST(jsonBody);
                unsigned long elapsedMs = millis() - startMs;
                Serial.print(F("[NET:TankAlert] Finished: "));
                Serial.print(elapsedMs);
                Serial.print(F(" ms (HTTP ")); Serial.print(httpCode); Serial.println(F(")"));
                http.end();
            }
        }

        vTaskDelay(pdMS_TO_TICKS(100));
    }
}

// ============================================================
// Tank Floater Sensors (Runs on Core 1)
// ============================================================
void checkTankSensors() {
    // Air/Blower machines do not have tank floaters; bypass floater alerts
    if (machineType != "carwash") {
        updateCoinSlotPower();
        return;
    }

    int currentWater = digitalRead(WATER_FLOATER_PIN);
    int currentSoap  = digitalRead(SOAP_FLOATER_PIN);

    if (lastWaterFloaterState != -1 && currentWater != lastWaterFloaterState) {
        pendingWaterAlert = (currentWater == LOW) ? 0 : 1;
        lcdNeedsRefresh   = true;
    }
    lastWaterFloaterState = currentWater;

    if (lastSoapFloaterState != -1 && currentSoap != lastSoapFloaterState) {
        pendingSoapAlert = (currentSoap == LOW) ? 0 : 1;
        lcdNeedsRefresh  = true;
    }
    lastSoapFloaterState = currentSoap;

    updateCoinSlotPower();
}

// ============================================================
// Audio Feedback & Beepers (Runs on Core 1)
// ============================================================
void playBeep(int durationMs, int count) {
    pinMode(BUZZER_PIN, OUTPUT);
    for (int i = 0; i < count; i++) {
        digitalWrite(BUZZER_PIN, HIGH);
        delay(durationMs);
        digitalWrite(BUZZER_PIN, LOW);
        if (i < count - 1) {
            delay(durationMs);
        }
    }
}

void playTone(int frequency, int durationMs) {
    tone(BUZZER_PIN, frequency, durationMs);
    delay(durationMs);
    noTone(BUZZER_PIN);
    pinMode(BUZZER_PIN, OUTPUT);
    digitalWrite(BUZZER_PIN, LOW);
}

void playCompletionMelody() {
    playTone(800, 150);
    delay(60);
    playTone(1000, 180);
    delay(60);
    playTone(1200, 250);
}

void runPreStartCountdown() {
    printLCDLine(0, "SERVICE STARTING");
    printLCDLine(1, "Starting in...");
    printLCDLine(3, "GET READY...");

    for (int count = 3; count >= 1; count--) {
        char countBuf[21];
        snprintf(countBuf, sizeof(countBuf), "       00:0%d        ", count);
        printLCDLine(2, String(countBuf));

        playBeep(100, 1);
        delay(900);
    }

    printLCDLine(2, "       00:00        ");
    delay(200);

    clearLCD();
}

// ============================================================
// Service Availability & Coin Slot Power Control (Runs on Core 1)
// ============================================================
bool isWaterAvailable() {
    // Air/Blower / Solo Inflator: No water tank or floater required!
    if (machineType != "carwash") {
        return isWaterServiceEnabled;
    }
    bool waterTankOk = (digitalRead(WATER_FLOATER_PIN) != LOW);
    return (waterTankOk && isWaterServiceEnabled);
}

bool isSoapAvailable() {
    // Air/Blower: Relay 2 (Blower) is independent and requires no floater!
    if (machineType != "carwash") {
        return isSoapServiceEnabled;
    }
    if (!isWaterAvailable()) return false;
    bool soapTankOk = (digitalRead(SOAP_FLOATER_PIN) != LOW);
    return (soapTankOk && isSoapServiceEnabled);
}

void updateCoinSlotPower() {
    // While in Recovery Mode, coin slot must remain CUT OFF
    if (recoveryModeActive) {
        if (coinSlotPowered) {
            digitalWrite(COIN_POWER_RELAY_PIN, COIN_RELAY_POWER_OFF);
            coinSlotPowered = false;
        }
        return;
    }

    bool serviceAvail = (machineType != "carwash")
        ? (isWaterServiceEnabled || isSoapServiceEnabled)
        : isWaterAvailable();

    if (currentCredit >= CREDIT_CUTOFF_LIMIT) {
        if (!creditCutoffActive) {
            creditCutoffActive = true;
            Serial.println(F("🪙 [Credit Limit] Reached ₱50.00 -> Disabling Coin Slot (Relay ON / NC Opened)."));
        }
    } else if (currentCredit <= CREDIT_RESUME_LIMIT) {
        if (creditCutoffActive) {
            creditCutoffActive = false;
            Serial.println(F("🪙 [Credit Limit] Dropped to ₱45.00 or below -> Re-enabling Coin Slot (Relay OFF / NC Closed)."));
        }
    }

    bool shouldBePowered = (serviceAvail && !creditCutoffActive);
    if (shouldBePowered == coinSlotPowered) return;

    if (shouldBePowered) {
        digitalWrite(COIN_POWER_RELAY_PIN, COIN_RELAY_POWER_ON);
        coinSlotPowered = true;
        Serial.println(F("🔌 [HW-482 GPIO 14] Relay OFF (NC Closed) -> Coin Slot POWERED ON."));
    } else {
        digitalWrite(COIN_POWER_RELAY_PIN, COIN_RELAY_POWER_OFF);
        coinSlotPowered = false;
        if (!serviceAvail) {
            Serial.println(F("🔌 [HW-482 GPIO 14] Relay ON (NC Opened) -> Coin Slot CUT OFF (Services Disabled/Tank Empty)."));
            playBeep(200, 2);
        } else if (creditCutoffActive) {
            Serial.println(F("🔌 [HW-482 GPIO 14] Relay ON (NC Opened) -> Coin Slot CUT OFF (Max ₱50 Credit Reached)."));
            playBeep(100, 2);
        }
    }
}

// ============================================================
// LCD 2004A Display Management (Runs on Core 1)
// ============================================================
uint8_t scanI2CAddress() {
    unsigned long startI2c = millis();
    Serial.println(F("[I2C] Scanning I2C bus (SDA=21, SCL=22)..."));

    const uint8_t commonAddrs[] = { LCD_I2C_ADDR, 0x3F, 0x20, 0x38 };
    for (size_t i = 0; i < sizeof(commonAddrs); i++) {
        Wire.beginTransmission(commonAddrs[i]);
        if (Wire.endTransmission() == 0) {
            unsigned long dur = millis() - startI2c;
            Serial.print(F("[I2C] FAST SUCCESS! Found LCD at 0x"));
            Serial.print(commonAddrs[i], HEX);
            Serial.print(F(" in ")); Serial.print(dur); Serial.println(F(" ms."));
            return commonAddrs[i];
        }
    }

    Serial.println(F("[I2C] Common addresses not responding; probing full bus 1..126..."));
    for (uint8_t addr = 1; addr < 127; addr++) {
        Wire.beginTransmission(addr);
        if (Wire.endTransmission() == 0) {
            unsigned long dur = millis() - startI2c;
            Serial.print(F("[I2C] SUCCESS! Found device at 0x"));
            Serial.print(addr, HEX);
            Serial.print(F(" in ")); Serial.print(dur); Serial.println(F(" ms."));
            return addr;
        }
    }

    Serial.println(F("[I2C] WARNING: No I2C devices responded! Defaulting to 0x27."));
    return LCD_I2C_ADDR;
}

// Cache for the 4 LCD lines to prevent redundant I2C transmission
static String cachedLcdLines[4] = {"", "", "", ""};

void clearLCD() {
    if (!lcd) return;
    if (xPortGetCoreID() != 1) {
        lcdNeedsRefresh = true;
        return;
    }
    for (int i = 0; i < 4; i++) {
        cachedLcdLines[i] = "";
    }
    lcd->clear();
}

void printLCDLine(int row, String text) {
    if (!lcd || row < 0 || row > 3) return;

    // Strict multi-core concurrency defense: LCD/I2C hardware is exclusively owned by Core 1.
    // Any attempt from Core 0 or an ISR will simply set the refresh flag and return safely.
    if (xPortGetCoreID() != 1) {
        lcdNeedsRefresh = true;
        return;
    }

    while (text.length() < 20) {
        text += " ";
    }
    if (text.length() > 20) {
        text = text.substring(0, 20);
    }

    // Skip redundant I2C transmission if this row's content hasn't changed
    if (cachedLcdLines[row] == text) {
        return;
    }

    cachedLcdLines[row] = text;
    lcd->setCursor(0, row);
    lcd->print(text);
}

void updateLCDDisplay() {
    if (!lcd || activeServiceRunning || completionScreenActive || recoveryModeActive) return;

    // Concurrency defense: Only Core 1 executes LCD updates
    if (xPortGetCoreID() != 1) {
        lcdNeedsRefresh = true;
        return;
    }

    lastWarningBeepSec = -1;

    // 0. Captive Portal Setup Screen: Display credentials when AP mode is active and no credit is queued
    if (apModeActive && currentCredit <= 0.0) {
        printLCDLine(0, "== VendoLink Setup ==");
        printLCDLine(1, getApSSID());
        printLCDLine(2, "Pass: " + String(AP_DEFAULT_PASS));
        printLCDLine(3, "IP: 192.168.4.1");
        return;
    }

    bool isWaterEmpty = (digitalRead(WATER_FLOATER_PIN) == LOW);
    bool isSoapEmpty  = (digitalRead(SOAP_FLOATER_PIN)  == LOW);
    bool waterAvail   = isWaterAvailable();
    bool soapAvail    = isSoapAvailable();

    printLCDLine(0, customLcdLine1);

    // Multi-Profile Handling for Tire Air & Blower or Solo Tire Inflator (No floater alerts)
    if (machineType != "carwash") {
        if (!isWaterServiceEnabled && !isSoapServiceEnabled) {
            printLCDLine(1, "SERVICES DISABLED!");
            printLCDLine(2, "UNDER MAINTENANCE");
            printLCDLine(3, "COIN SLOT: DISABLED");
        } else if (currentCredit > 0.0) {
            String creditStr = "CREDIT: P" + String((int)currentCredit) + ".00";
            printLCDLine(1, creditStr);
            if (isWaterServiceEnabled && isSoapServiceEnabled) {
                printLCDLine(2, "PRESS BUTTON 1 OR 2");
            } else if (isWaterServiceEnabled) {
                printLCDLine(2, "PRESS START BUTTON");
            } else {
                printLCDLine(2, "PRESS BUTTON 2");
            }
            if (creditCutoffActive || currentCredit >= CREDIT_CUTOFF_LIMIT) {
                printLCDLine(3, "MAX CREDIT REACHED!");
            } else {
                printLCDLine(3, customLcdLine4);
            }
        } else {
            printLCDLine(1, customLcdLine2);
            printLCDLine(2, customLcdLine3);
            printLCDLine(3, customLcdLine4);
        }
        return;
    }

    if (!waterAvail && isSoapEmpty) {
        printLCDLine(1, "WATER & SOAP EMPTY!");
        printLCDLine(2, "PLEASE REFILL TANKS");
        printLCDLine(3, "COIN SLOT: DISABLED");
    } else if (!waterAvail) {
        printLCDLine(1, isWaterEmpty ? "WATER TANK EMPTY!" : "WATER DISABLED!");
        printLCDLine(2, "SERVICES DISABLED");
        printLCDLine(3, "COIN SLOT: DISABLED");
    } else if (!soapAvail) {
        if (currentCredit > 0.0) {
            String creditStr = "CREDIT: P" + String((int)currentCredit) + ".00";
            printLCDLine(1, creditStr);
            printLCDLine(2, "PRESS WATER START");
            if (creditCutoffActive || currentCredit >= CREDIT_CUTOFF_LIMIT) {
                printLCDLine(3, "MAX CREDIT REACHED!");
            } else {
                printLCDLine(3, customLcdLine4);
            }
        } else {
            printLCDLine(1, isSoapEmpty ? "SOAP TANK EMPTY!" : "SOAP DISABLED");
            printLCDLine(2, "WATER AVAILABLE");
            printLCDLine(3, customLcdLine4);
        }
    } else if (currentCredit > 0.0) {
        String creditStr = "CREDIT: P" + String((int)currentCredit) + ".00";
        printLCDLine(1, creditStr);
        printLCDLine(2, "PRESS WATER OR SOAP");
        if (creditCutoffActive || currentCredit >= CREDIT_CUTOFF_LIMIT) {
            printLCDLine(3, "MAX CREDIT REACHED!");
        } else {
            printLCDLine(3, customLcdLine4);
        }
    } else {
        printLCDLine(1, customLcdLine2);
        printLCDLine(2, customLcdLine3);
        printLCDLine(3, customLcdLine4);
    }
}

/**
 * Display Interrupted Session Recovery Screen on 20x4 LCD:
 * Line 0: "INTERRUPTED SESSION " (20 chars)
 * Line 1: "Water          01:32" or "Soap           01:32" (20 chars)
 * Line 2: "Press WATER button  " or "Press SOAP button   " (20 chars)
 * Line 3: "3x RESUME  2x CANCEL" or multi-press feedback (20 chars)
 */
void showInterruptedSessionScreen(int subPressState = 0) {
    if (!lcd) return;

    printLCDLine(0, "INTERRUPTED SESSION ");

    int mins = recoveryRemainingSec / 60;
    int secs = recoveryRemainingSec % 60;
    char timeBuf[6];
    snprintf(timeBuf, sizeof(timeBuf), "%02d:%02d", mins, secs);

    String svcLabel = (recoveryServiceKey == "water") ? "Water" : "Soap";
    int spacesNeeded = 20 - svcLabel.length() - 5;
    if (spacesNeeded < 1) spacesNeeded = 1;
    String pad = "";
    for (int i = 0; i < spacesNeeded; i++) pad += " ";
    String line1 = svcLabel + pad + String(timeBuf);
    printLCDLine(1, line1);

    if (recoveryServiceKey == "water") {
        printLCDLine(2, "Press WATER button  ");
    } else {
        printLCDLine(2, "Press SOAP button   ");
    }

    if (subPressState == 1) {
        printLCDLine(3, "PRESS: [1] ...      ");
    } else if (subPressState == 2) {
        printLCDLine(3, "PRESS: [2] (CANCEL?)");
    } else {
        printLCDLine(3, "3x RESUME  2x CANCEL");
    }
}

// ============================================================
// 🛠️ Physical Diagnostic Maintenance Screen (Approach 1: 3s Dual Hold)
// Displays firmware version, connection status, IP, and 7s exit countdown
// ============================================================
void triggerDiagnosticScreen() {
    diagnosticScreenActive = true;
    diagnosticScreenUntil  = millis() + 7000;
    lastDiagCountdownSec   = -1;
    dualBtnHoldStart       = 0;

    playBeep(80, 2);
    clearLCD();

    printLCDLine(0, "== SYSTEM INFO ==   ");

    String fwStr = "FW: v" + String(FIRMWARE_VERSION) + (isWiFiConnected ? " [ONLINE]" : " [OFFL]");
    if (fwStr.length() > 20) fwStr = fwStr.substring(0, 20);
    printLCDLine(1, fwStr);

    String ipStr = isWiFiConnected ? ("IP: " + WiFi.localIP().toString()) : "IP: NO WI-FI";
    if (ipStr.length() > 20) ipStr = ipStr.substring(0, 20);
    printLCDLine(2, ipStr);

    printLCDLine(3, "EXIT IN 7s (OR COIN)");

    Serial.println(F("🛠️ [MAINTENANCE] Physical Diagnostic Screen displayed (3s dual hold)."));
}

// ============================================================
// Coin Processing Logic (Runs on Core 1)
// ============================================================
void processCoinPulses(int pulses) {
    // In Recovery Mode, reject/ignore coins to prevent credit corruption
    if (recoveryModeActive) {
        Serial.println(F("⚠️ [Coin Slot] Coin rejected — Interrupted session recovery pending."));
        return;
    }

    // Instant Dismissal: If diagnostic maintenance screen is showing, return to normal display immediately
    if (diagnosticScreenActive) {
        diagnosticScreenActive = false;
        dualBtnHoldStart = 0;
        updateLCDDisplay();
    }

    float addedAmount = 0.0;

    switch (pulses) {
        case 1:
            addedAmount = 5.0;
            break;
        case 2:
            addedAmount = 10.0;
            break;
        case 4:
            addedAmount = 20.0;
            break;
        default:
            Serial.print(F("🛡️ [Coin Noise Filter] REJECTED "));
            Serial.print(pulses);
            Serial.println(F(" pulse(s) — Not a valid coin denomination (1=₱5, 2=₱10, 4=₱20)."));
            return;
    }

    currentCredit += addedAmount;
    if (currentCredit > MAX_CREDIT_LIMIT) {
        currentCredit = MAX_CREDIT_LIMIT;
    }
    savePersistentCredit(currentCredit);

    completionScreenActive = false;

    updateCoinSlotPower();
    playBeep(80, 1);

    Serial.println(F("----------------------------------------"));
    Serial.print(F("🪙 [Coin Accepted] "));
    Serial.print(pulses);
    Serial.print(F(" pulse(s) = +₱"));
    Serial.print(addedAmount);
    Serial.print(F(" | Credit: ₱"));
    Serial.println(currentCredit);
    Serial.println(F("----------------------------------------"));

    updateLCDDisplay();
}

// ============================================================
// Service Buttons & Transaction Lifecycle (Runs on Core 1)
// ============================================================
void handleServiceButtonPress(String serviceKeyName, int relayPin, float serviceCost, int durationSeconds) {
    completionScreenActive = false;

    // 1. Active Service Running: One-time Pause / Resume
    if (activeServiceRunning) {
        if (serviceKeyName == activeServiceKey) {
            if (!isServicePaused) {
                if (!pauseUsed) {
                    isServicePaused = true;
                    pauseUsed       = true;
                    digitalWrite(activeRelayPin, RELAY_OFF_STATE);
                    playBeep(80, 1);
                    Serial.println(F("⏸️ [Service] ONE-TIME PAUSE activated. SSR OFF."));
                } else {
                    Serial.println(F("⚠️ [Service] Pause ignored — One-time pause already used."));
                }
            } else {
                if (!resumeUsed) {
                    isServicePaused = false;
                    resumeUsed      = true;
                    digitalWrite(activeRelayPin, RELAY_ON_STATE);
                    playBeep(80, 1);
                    Serial.println(F("▶️ [Service] ONE-TIME RESUME activated. SSR ON."));
                } else {
                    Serial.println(F("⚠️ [Service] Resume ignored — One-time resume already used."));
                }
            }
        }
        return;
    }

    playBeep(80, 1);

    // 2. Service 1 (Water / Tire Air) Availability Check
    if (serviceKeyName == "water" && !isWaterAvailable()) {
        Serial.println(F("❌ [Service 1] Service UNAVAILABLE!"));
        playBeep(120, 3);
        printLCDLine(2, (machineType == "carwash") ? "WATER UNAVAILABLE!" : "SERVICE DISABLED!");
        delay(2000);
        updateLCDDisplay();
        return;
    }

    // 3. Service 2 (Soap / Car Blower) Availability Check
    if (serviceKeyName == "soap" && !isSoapAvailable()) {
        Serial.println(F("❌ [Service 2] Service UNAVAILABLE!"));
        playBeep(120, 3);
        if (machineType == "carwash") {
            printLCDLine(2, (digitalRead(SOAP_FLOATER_PIN) == LOW) ? "SOAP TANK EMPTY!" : "SOAP UNAVAILABLE!");
        } else {
            printLCDLine(2, "SERVICE DISABLED!");
        }
        delay(2000);
        updateLCDDisplay();
        return;
    }

    // 4. Check Credit
    if (currentCredit < serviceCost) {
        Serial.print(F("❌ [Service] Insufficient Credit! Required: ₱"));
        Serial.print(serviceCost);
        Serial.print(F(", Current: ₱"));
        Serial.println(currentCredit);

        playBeep(200, 1);
        printLCDLine(0, customLcdLine1);
        printLCDLine(1, "INSERT COIN FIRST");
        printLCDLine(2, relay1ServiceName + ": P" + String((int)waterCoinValue));
        printLCDLine(3, relay2ServiceName + ": P" + String((int)soapCoinValue));
        delay(2000);
        updateLCDDisplay();
        return;
    }

    // 5. Deduct Credit (Instant Local Transaction Start)
    currentCredit -= serviceCost;
    if (currentCredit < 0) currentCredit = 0.0;
    savePersistentCredit(currentCredit);
    updateCoinSlotPower();

    if (durationSeconds <= 0) durationSeconds = (serviceKeyName == "soap") ? 60 : 120;
    serviceDurationMs = (unsigned long)durationSeconds * 1000;
    lastWarningBeepSec = -1;

    activeServiceCost = serviceCost;
    activeServiceSecs = durationSeconds;

    // Generate unique Client Transaction ID for this session
    uint64_t chipMac = ESP.getEfuseMac();
    char macBuf[16];
    snprintf(macBuf, sizeof(macBuf), "%04X%08X", (uint16_t)(chipMac >> 32), (uint32_t)chipMac);
    char newTxId[36];
    snprintf(newTxId, sizeof(newTxId), "VL-%s-%05u", macBuf, (unsigned int)(millis() % 100000));
    currentSessionTxId = String(newTxId);

    String currentDisplayName = (serviceKeyName == "water") ? relay1ServiceName : relay2ServiceName;

    // ⚡ Persist Active Session in NVS before relay activation
    saveActiveSession(
        serviceKeyName.c_str(),
        currentDisplayName.c_str(),
        serviceCost,
        currentCredit,
        durationSeconds,
        durationSeconds,
        relayPin,
        newTxId
    );
    lastSessionCheckpointTime = millis();

    Serial.println(F("----------------------------------------"));
    Serial.print(F("🚀 [Service Selected] Deducted ₱"));
    Serial.print(serviceCost);
    Serial.print(F(". Remaining Credit: ₱"));
    Serial.println(currentCredit);

    // 6. Pre-Start Countdown (3... 2... 1...) BEFORE relay turns ON
    runPreStartCountdown();

    // 7. Turn ON Relay & Transition to SERVICE RUNNING
    digitalWrite(relayPin, RELAY_ON_STATE);
    activeServiceRunning = true;
    isServicePaused      = false;
    pauseUsed            = false;
    resumeUsed           = false;
    activeServiceName    = currentDisplayName;
    activeServiceKey     = serviceKeyName;
    activeRelayPin       = relayPin;
    serviceStartTime     = millis();

    Serial.println(F("========================================"));
    Serial.println(F("🚀 SERVICE RUNNING (RELAY ON)"));
    Serial.print(F("Service: ")); Serial.println(activeServiceName);
    Serial.print(F("Duration: ")); Serial.print(durationSeconds); Serial.println(F("s"));
    Serial.println(F("========================================"));

    printLCDLine(0, customLcdLine1);
    printLCDLine(1, "RUNNING: " + activeServiceName);
    printLCDLine(2, "SSR: ACTIVE");
}

/**
 * Service Completed: Timer reaches 0.
 * Relay turns OFF, completion melody plays, and transaction is committed to offline queue.
 * Clears active session from NVS storage.
 */
void stopRunningService() {
    if (!activeServiceRunning) return;

    if (activeRelayPin >= 0) {
        digitalWrite(activeRelayPin, RELAY_OFF_STATE);
    }

    activeServiceRunning = false;
    isServicePaused      = false;
    pauseUsed            = false;
    resumeUsed           = false;

    // ⚡ Normal Completion: Cleanly purge active session from NVS
    clearActiveSession();

    Serial.println(F("========================================"));
    Serial.println(F("⏹️ SERVICE COMPLETED"));
    Serial.print(F("Service: ")); Serial.println(activeServiceName);
    Serial.print(F("Amount: ₱")); Serial.println(activeServiceCost);
    Serial.println(F("========================================"));

    playCompletionMelody();

    // Commit completed transaction into LittleFS offline queue with original TxId
    bool queued = offlineQueueAppendWithTxId(
        activeServiceKey.c_str(),
        activeServiceCost,
        (uint32_t)activeServiceSecs,
        currentSessionTxId.c_str()
    );
    if (!queued) {
        Serial.println(F("⚠️ [Transaction Lifecycle] Offline queue write uncommitted (storage full or unavailable)."));
    }

    activeServiceName  = "";
    activeServiceKey   = "";
    activeRelayPin     = -1;
    lastWarningBeepSec = -1;

    printLCDLine(0, customLcdLine1);
    printLCDLine(1, "SERVICE COMPLETED");
    printLCDLine(2, "THANK YOU!");
    printLCDLine(3, customLcdLine4);

    completionScreenActive = true;
    completionScreenUntil  = millis() + 2000;
}

// ============================================================
// ⚡ Session Recovery Multi-Press State Machine
// 2x = CANCEL (clears session, relays OFF, returns to READY)
// 3x = RESUME (restores time/credit, relay ON, continues timer)
// 1x = Incomplete sequence (resets to 0 after window timeout)
// ============================================================
void handleRecoveryButtonPress() {
    recoveryPressCount++;
    recoveryLastPressTime = millis();

    playBeep(40, 1); // Short audio feedback on each press

    if (recoveryPressCount == 1) {
        Serial.println(F("⚡ [Recovery] Press 1 detected. Waiting for multi-press sequence..."));
        showInterruptedSessionScreen(1);
    } else if (recoveryPressCount == 2) {
        Serial.println(F("⚡ [Recovery] Press 2 detected. CANCEL candidate. Waiting for 3rd press or timeout..."));
        showInterruptedSessionScreen(2);
    } else if (recoveryPressCount >= 3) {
        Serial.println(F("⚡ [Recovery] Press 3 detected! RESUME triggered immediately!"));
        resumeInterruptedSession();
    }
}

void cancelInterruptedSession() {
    Serial.println(F("🛑 [Recovery] 2x button press confirmed -> CANCELLING interrupted service session."));

    // 1. Keep relays OFF
    digitalWrite(WATER_RELAY_PIN, RELAY_OFF_STATE);
    digitalWrite(SOAP_RELAY_PIN, RELAY_OFF_STATE);

    // 2. Play Cancel Audio Tone (2 low warning beeps)
    playBeep(150, 2);

    // 3. Queue Incomplete Transaction into LittleFS queue for backend reporting
    int actualSec = recoveryTotalSec - recoveryRemainingSec;
    if (actualSec < 0) actualSec = 0;
    if (recoveryClientTxId.length() > 0 && recoveryAmount > 0.0) {
        offlineQueueAppendWithTxId(
            recoveryServiceKey.c_str(),
            recoveryAmount,
            (uint32_t)recoveryTotalSec,
            recoveryClientTxId.c_str(),
            TX_INCOMPLETE,
            (uint16_t)actualSec
        );
        Serial.print(F("📝 [Recovery] Incomplete transaction queued: "));
        Serial.print(recoveryClientTxId);
        Serial.print(F(" | Actual runtime: "));
        Serial.print(actualSec);
        Serial.println(F("s"));
    }

    // 4. Clear ONLY active service session from NVS (DO NOT touch credit!)
    clearActiveSession();

    // 4. Ensure customer credit is preserved
    // DO NOT set credit to zero.
    // DO NOT refund by adding credit.
    // DO NOT subtract credit.
    // Simply preserve the existing credit balance.
    if (currentCredit == 0.0 && recoveryCredit > 0.0) {
        currentCredit = recoveryCredit;
    }
    savePersistentCredit(currentCredit);

    // 5. Reset Recovery Flags
    recoveryModeActive = false;
    recoveryPressCount = 0;

    printLCDLine(0, customLcdLine1);
    printLCDLine(1, "SERVICE CANCELLED");
    if (currentCredit > 0.0) {
        printLCDLine(2, "CREDIT: P" + String((int)currentCredit) + ".00");
        printLCDLine(3, "SELECT SERVICE");
    } else {
        printLCDLine(2, "THANK YOU!");
        printLCDLine(3, customLcdLine4);
    }
    delay(1500);

    // 6. Restore coin slot power (evaluates ₱50 credit cutoff) and return to normal READY screen
    updateCoinSlotPower();
    updateLCDDisplay();

    Serial.print(F("🪙 [Recovery] Interrupted service cancelled. Preserved credit balance: ₱"));
    Serial.println(currentCredit);
}

void resumeInterruptedSession() {
    Serial.println(F("▶️ [Recovery] 3x button press confirmed -> RESUMING interrupted session!"));

    // 1. Play Resume Melodic Tone
    playTone(1000, 100);
    delay(50);
    playTone(1200, 150);

    // 2. Restore all saved session states
    activeServiceName         = recoveryServiceName;
    activeServiceKey          = recoveryServiceKey;
    activeServiceCost         = recoveryAmount;
    activeServiceSecs         = recoveryTotalSec;
    activeRelayPin            = recoveryRelayPin;
    if (recoveryCredit > 0.0 || currentCredit == 0.0) {
        currentCredit = recoveryCredit;
    }
    savePersistentCredit(currentCredit);
    currentSessionTxId        = recoveryClientTxId;
    serviceDurationMs         = (unsigned long)recoveryRemainingSec * 1000;
    serviceStartTime          = millis();
    lastWarningBeepSec        = -1;
    lastSessionCheckpointTime = millis();

    // 3. Reset Recovery Mode Flags
    recoveryModeActive = false;
    recoveryPressCount = 0;

    // 4. Re-checkpoint session in NVS
    saveActiveSession(
        activeServiceKey.c_str(),
        activeServiceName.c_str(),
        activeServiceCost,
        currentCredit,
        activeServiceSecs,
        recoveryRemainingSec,
        activeRelayPin,
        currentSessionTxId.c_str()
    );

    // 5. Activate ONLY the correct service relay!
    digitalWrite(activeRelayPin, RELAY_ON_STATE);
    activeServiceRunning = true;
    isServicePaused      = false;
    pauseUsed            = false;
    resumeUsed           = false;

    // 6. Restore Coin Slot Power if credit allows
    updateCoinSlotPower();

    printLCDLine(0, customLcdLine1);
    printLCDLine(1, "RUNNING: " + activeServiceName);
    printLCDLine(2, "SSR: ACTIVE");

    Serial.println(F("========================================"));
    Serial.println(F("▶️ SERVICE RESUMED SUCCESSFULLY"));
    Serial.print(F("Service: ")); Serial.println(activeServiceName);
    Serial.print(F("Remaining Duration: ")); Serial.print(recoveryRemainingSec); Serial.println(F("s"));
    Serial.print(F("Credit: ₱")); Serial.println(currentCredit);
    Serial.print(F("Relay Pin: ")); Serial.println(activeRelayPin);
    Serial.println(F("========================================"));
}

// ============================================================
// Arduino Setup & Loop (Runs on Core 1)
// ============================================================
void setup() {
    unsigned long bootStartMs = millis();
    unsigned long stepStartMs = bootStartMs;

    Serial.begin(115200);
    delay(200);

    loadDeviceConfig();
    loadServiceConfigFromNVS();

    Serial.println();
    Serial.println(F("========================================"));
    Serial.println(F("       VendoLink ESP32 Controller       "));
    Serial.print(F("   Universal Dual-Core v")); Serial.println(FIRMWARE_VERSION);
    Serial.print(F("   Hardware MAC:  ")); Serial.println(getDeviceMac());
    Serial.print(F("   Target Server: ")); Serial.println(configServerUrl);
    Serial.print(F("   Wi-Fi SSID:    ")); Serial.println(configWifiSsid.length() > 0 ? configWifiSsid : String(F("[AP SETUP REQUIRED]")));
    Serial.print(F("   API Token:     ")); Serial.println(maskSecret(configApiToken.c_str()));
    Serial.print(F("   Enrollment:    ")); Serial.println(isDeviceEnrolled ? F("ENROLLED") : F("PENDING ENROLLMENT"));
    Serial.println(F("========================================"));

    // Step 1: Initialize Mutex & GPIOs
    stepStartMs = millis();
    queueMutex = xSemaphoreCreateMutex();

    pinMode(BUZZER_PIN, OUTPUT);
    digitalWrite(BUZZER_PIN, LOW);

    pinMode(WATER_RELAY_PIN, OUTPUT);
    pinMode(SOAP_RELAY_PIN, OUTPUT);
    digitalWrite(WATER_RELAY_PIN, RELAY_OFF_STATE);
    digitalWrite(SOAP_RELAY_PIN, RELAY_OFF_STATE);

    pinMode(WATER_BUTTON_PIN, INPUT_PULLUP);
    pinMode(SOAP_BUTTON_PIN, INPUT_PULLUP);

    // Hardware Reset Check: If Water Button (D15) is held down during boot, clear NVS network & service config
    if (digitalRead(WATER_BUTTON_PIN) == LOW) {
        delay(600);
        if (digitalRead(WATER_BUTTON_PIN) == LOW) {
            Serial.println(F("⚠️ [RESET] Water button held at startup! Clearing NVS credentials & restoring defaults..."));
            nvsConfig.begin("vlcfg", false);
            nvsConfig.remove("ssid");
            nvsConfig.remove("pass");
            nvsConfig.remove("server");
            nvsConfig.remove("token");
            nvsConfig.putBool("enrolled", false);
            nvsConfig.end();

            Preferences nvsSvcReset;
            nvsSvcReset.begin("vlsvc", false);
            nvsSvcReset.clear();
            nvsSvcReset.end();

            configWifiSsid        = "";
            configWifiPass        = "";
            configServerUrl       = DEFAULT_VENDOLINK_SERVER;
            configApiToken        = "";
            isDeviceEnrolled      = false;
            isConfigured          = false;
            waterServiceSec       = 120;
            soapServiceSec        = 60;
            waterCoinValue        = 5.0;
            soapCoinValue         = 5.0;
            isWaterServiceEnabled = true;
            isSoapServiceEnabled  = true;
            machineType           = "carwash";
            relay1ServiceName     = "WATER SERVICE";
            relay2ServiceName     = "SOAP SERVICE";
            customLcdLine1        = "== VendoLink v" + String(FIRMWARE_VERSION).substring(0, 3) + " ==";
            customLcdLine2        = "WELCOME CUSTOMER!";
            customLcdLine3        = "INSERT COIN FIRST!";
            customLcdLine4        = "WATER:P5 | SOAP:P5";

            // Beep 3 times to confirm hardware reset
            for (int b = 0; b < 3; b++) {
                digitalWrite(BUZZER_PIN, HIGH);
                delay(80);
                digitalWrite(BUZZER_PIN, LOW);
                delay(80);
            }
        }
    }

    pinMode(WATER_FLOATER_PIN, INPUT_PULLUP);
    pinMode(SOAP_FLOATER_PIN, INPUT_PULLUP);

    lastWaterFloaterState = digitalRead(WATER_FLOATER_PIN);
    lastSoapFloaterState  = digitalRead(SOAP_FLOATER_PIN);

    pinMode(COIN_POWER_RELAY_PIN, OUTPUT);
    coinSlotPowered = false;

    // 🪙 Load Customer Credit FIRST from persistent storage!
    currentCredit = loadPersistentCredit();
    Serial.print(F("🪙 [BOOT] Loaded persistent customer credit: ₱"));
    Serial.println(currentCredit);

    updateCoinSlotPower();

    unsigned long gpioDuration = millis() - stepStartMs;
    Serial.print(F("⏱️ [BOOT] GPIOs & Relays initialized: "));
    Serial.print(gpioDuration); Serial.println(F(" ms"));

    // Step 2: Initialize I2C Bus & LCD 2004A
    stepStartMs = millis();
    delay(50); // Power-on / reboot stabilization delay for I2C bus and PCF8574 backpack
    Wire.begin(I2C_SDA_PIN, I2C_SCL_PIN);
    uint8_t detectedAddr = scanI2CAddress();

    lcd = new LiquidCrystal_I2C(detectedAddr, LCD_COLS, LCD_ROWS);
    lcd->init();
    lcd->backlight();
    clearLCD();

    // Display boot screen on LCD (Owner custom Line 1 displayed immediately)
    printLCDLine(0, customLcdLine1);
    printLCDLine(1, "BOOTING SYSTEM...");
    printLCDLine(2, "INIT HARDWARE...");
    printLCDLine(3, "PLEASE WAIT...");

    unsigned long lcdDuration = millis() - stepStartMs;
    Serial.print(F("⏱️ [BOOT] I2C & LCD initialized: "));
    Serial.print(lcdDuration); Serial.println(F(" ms"));

    // Step 3: Initialize LittleFS Offline Storage & Load Queue Header
    stepStartMs = millis();
    initOfflineStorage();
    unsigned long storageDuration = millis() - stepStartMs;
    Serial.print(F("⏱️ [BOOT] LittleFS storage initialized: "));
    Serial.print(storageDuration); Serial.println(F(" ms"));

    // Step 4: Configure Coin Slot Pin & Noise-Filtered CHANGE Interrupt
    stepStartMs = millis();
    pinMode(COIN_SIGNAL_PIN, INPUT_PULLUP);
    attachInterrupt(digitalPinToInterrupt(COIN_SIGNAL_PIN), coinPinChangeISR, CHANGE);
    unsigned long isrDuration = millis() - stepStartMs;
    Serial.print(F("⏱️ [BOOT] Coin interrupt attached: "));
    Serial.print(isrDuration); Serial.println(F(" ms"));

    // Step 5: Start Background Network Worker on Core 0
    stepStartMs = millis();
    xTaskCreatePinnedToCore(
        networkWorkerTask,
        "NetWorker",
        20480, // Increased to 20KB to support mbedTLS HTTPS OTA updates without stack overflow
        NULL,
        1,
        &netTaskHandle,
        0 // Core 0
    );
    unsigned long taskDuration = millis() - stepStartMs;
    Serial.print(F("⏱️ [BOOT] Core 0 Network Worker spawned: "));
    Serial.print(taskDuration); Serial.println(F(" ms"));

    // Step 6: ⚡ Power-Interruption Session Check
    stepStartMs = millis();
    bool hasInterruptedSession = loadActiveSession();

    if (hasInterruptedSession) {
        recoveryModeActive = true;
        recoveryPressCount = 0;

        // CRITICAL SAFETY REQUIREMENT: Relays MUST remain strictly OFF!
        digitalWrite(WATER_RELAY_PIN, RELAY_OFF_STATE);
        digitalWrite(SOAP_RELAY_PIN, RELAY_OFF_STATE);

        // Disable coin slot during recovery
        digitalWrite(COIN_POWER_RELAY_PIN, COIN_RELAY_POWER_OFF);
        coinSlotPowered = false;

        Serial.println(F("========================================"));
        Serial.println(F("⚠️ [BOOT:RECOVERY] INTERRUPTED SESSION DETECTED!"));
        Serial.print(F("   Service:   ")); Serial.println(recoveryServiceName);
        Serial.print(F("   Remaining: ")); Serial.print(recoveryRemainingSec); Serial.println(F("s"));
        Serial.print(F("   Credit:    ₱")); Serial.println(recoveryCredit);
        Serial.print(F("   TxId:      ")); Serial.println(recoveryClientTxId);
        Serial.println(F("   Relays locked OFF. Awaiting customer command."));
        Serial.println(F("========================================"));

        delay(250);
        clearLCD();
        showInterruptedSessionScreen(0);
        playBeep(100, 3); // 3 warning beeps to notify customer of power recovery
    } else {
        // Normal Boot: Automatic transition to Ready Screen without button press
        delay(250);
        clearLCD();
        updateLCDDisplay();
        playBeep(50, 2);
    }

    unsigned long bootDuration = millis() - bootStartMs;
    Serial.print(F("🚀 [BOOT COMPLETE] System Ready in "));
    Serial.print(bootDuration); Serial.println(F(" ms total."));
}

void loop() {
    // ⚡ OTA Safety Guard: Freeze Core 1 while Core 0 downloads & flashes firmware
    if (isOtaUpdating) {
        delay(50);
        return;
    }

    unsigned long currentMillis = millis();

    // 1. Tank Floater Level Sensors
    checkTankSensors();

    // 2. Coin Pulse Burst Processing (Accumulates Credit)
    if (pulseActive && (currentMillis - lastPulseTime >= PULSE_TIMEOUT_MS)) {
        noInterrupts();
        int pulsesReceived = pulseCount;
        pulseCount  = 0;
        pulseActive = false;
        interrupts();

        if (pulsesReceived > 0) {
            processCoinPulses(pulsesReceived);
        }
    }

    // 3. Button Read & Edge Tracking
    int waterBtnState = digitalRead(WATER_BUTTON_PIN);
    int soapBtnState  = digitalRead(SOAP_BUTTON_PIN);

    // ========================================================
    // 🛠️ HARDWARE DIAGNOSTIC MAINTENANCE SCREEN (ACTIVE)
    // ========================================================
    if (diagnosticScreenActive) {
        if (currentMillis >= diagnosticScreenUntil) {
            diagnosticScreenActive = false;
            dualBtnHoldStart       = 0;
            updateLCDDisplay();
        } else {
            int remainingSec = (int)((diagnosticScreenUntil - currentMillis + 999) / 1000);
            if (remainingSec != lastDiagCountdownSec) {
                lastDiagCountdownSec = remainingSec;
                char exitBuf[21];
                snprintf(exitBuf, sizeof(exitBuf), "EXIT IN %ds (OR COIN)", remainingSec);
                printLCDLine(3, String(exitBuf));
            }
            // Dismiss immediately on single button press
            if ((waterBtnState == LOW && lastWaterBtnState == HIGH) || (soapBtnState == LOW && lastSoapBtnState == HIGH)) {
                diagnosticScreenActive = false;
                dualBtnHoldStart       = 0;
                playBeep(50, 1);
                updateLCDDisplay();
            }
        }
        lastWaterBtnState = waterBtnState;
        lastSoapBtnState  = soapBtnState;
        delay(5);
        return;
    }

    // 🛠️ Physical Diagnostic Trigger Check (Dual 3-Second Hold):
    // Only available when machine is completely IDLE: No active service, no recovery, and ₱0.00 credit!
    if (!activeServiceRunning && !recoveryModeActive && currentCredit <= 0.0) {
        if (waterBtnState == LOW && soapBtnState == LOW) {
            if (dualBtnHoldStart == 0) {
                dualBtnHoldStart = currentMillis;
            } else if (currentMillis - dualBtnHoldStart >= 3000) {
                triggerDiagnosticScreen();
                dualBtnHoldStart  = 0;
                lastWaterBtnState = waterBtnState;
                lastSoapBtnState  = soapBtnState;
                delay(5);
                return;
            }
        } else {
            dualBtnHoldStart = 0;
        }
    } else {
        dualBtnHoldStart = 0;
    }

    // ========================================================
    // ⚡ RECOVERY MODE HANDLING (INTERRUPTED SESSION)
    // ========================================================
    if (recoveryModeActive) {
        // BUTTON ISOLATION: Only the interrupted service button is monitored!
        if (recoveryServiceKey == "water") {
            if (waterBtnState == LOW && lastWaterBtnState == HIGH) {
                if (currentMillis - lastWaterBtnTime >= 250) {
                    lastWaterBtnTime = currentMillis;
                    handleRecoveryButtonPress();
                }
            }
            // Soap button is completely ignored!
        } else if (recoveryServiceKey == "soap") {
            if (soapBtnState == LOW && lastSoapBtnState == HIGH) {
                if (currentMillis - lastSoapBtnTime >= 250) {
                    lastSoapBtnTime = currentMillis;
                    handleRecoveryButtonPress();
                }
            }
            // Water button is completely ignored!
        }

        // Check Multi-Press Window Expiration
        if (recoveryPressCount > 0 && (currentMillis - recoveryLastPressTime >= RECOVERY_WINDOW_MS)) {
            if (recoveryPressCount == 2) {
                // 2x confirmed by window timeout -> CANCEL
                cancelInterruptedSession();
            } else {
                // 1x timeout -> Incomplete press sequence, reset back to base screen
                recoveryPressCount = 0;
                showInterruptedSessionScreen(0);
            }
        }
    }
    // ========================================================
    // NORMAL OPERATION BUTTON HANDLING
    // ========================================================
    else {
        // Water Start Push Button (GPIO 15 / D15)
        if (waterBtnState == LOW && lastWaterBtnState == HIGH) {
            if (currentMillis - lastWaterBtnTime >= 300) {
                lastWaterBtnTime = currentMillis;
                handleServiceButtonPress("water", WATER_RELAY_PIN, waterCoinValue, waterServiceSec);
            }
        }

        // Soap Start Push Button (GPIO 18 / D18)
        if (soapBtnState == LOW && lastSoapBtnState == HIGH) {
            if (currentMillis - lastSoapBtnTime >= 300) {
                lastSoapBtnTime = currentMillis;
                handleServiceButtonPress("soap", SOAP_RELAY_PIN, soapCoinValue, soapServiceSec);
            }
        }
    }

    lastWaterBtnState = waterBtnState;
    lastSoapBtnState  = soapBtnState;

    // 4. Active Service Countdown Timer & Display Updates
    if (activeServiceRunning) {
        unsigned long nowMs = millis();
        if (nowMs >= serviceStartTime) {
            unsigned long elapsed = nowMs - serviceStartTime;
            if (elapsed >= serviceDurationMs) {
                stopRunningService();
            } else {
                unsigned long remainingSec = (serviceDurationMs - elapsed) / 1000;
                int mins = (int)(remainingSec / 60);
                int secs = (int)(remainingSec % 60);
                char timeBuf[21];
                snprintf(timeBuf, sizeof(timeBuf), "TIME LEFT: %02d:%02d", mins, secs);

                // Checkpoint active session every 5 seconds to NVS
                if (nowMs - lastSessionCheckpointTime >= SESSION_CHECKPOINT_INTERVAL_MS) {
                    lastSessionCheckpointTime = nowMs;
                    checkpointSessionRemainingTime((int)remainingSec);
                }

                // Final 3-Second Warning Beeps (00:03, 00:02, 00:01)
                if (remainingSec <= 3 && remainingSec >= 1 && (int)remainingSec != lastWarningBeepSec) {
                    lastWarningBeepSec = (int)remainingSec;
                    playBeep(100, 1);
                }

                static unsigned long lastDebugLog = 0;
                if (nowMs - lastDebugLog >= 1000) {
                    lastDebugLog = nowMs;
                    Serial.print(F("⏳ Elapsed: ")); Serial.print(elapsed);
                    Serial.print(F(" ms | Remaining: ")); Serial.print(remainingSec);
                    Serial.print(F(" s | Paused: ")); Serial.println(isServicePaused ? "YES" : "NO");
                }

                printLCDLine(0, customLcdLine1);
                if (isServicePaused) {
                    String title = activeServiceName + " PAUSED";
                    if (title.length() > 20) title = title.substring(0, 20);
                    printLCDLine(1, title);
                    printLCDLine(2, String(timeBuf));
                    printLCDLine(3, "PRESS TO RESUME");
                } else {
                    printLCDLine(1, "RUNNING: " + activeServiceName);
                    printLCDLine(2, String(timeBuf));
                    printLCDLine(3, "SSR: ACTIVE");
                }
            }
        }
    } else if (!recoveryModeActive) {
        if (completionScreenActive && currentMillis >= completionScreenUntil) {
            completionScreenActive = false;
            updateLCDDisplay();
        }

        if (lcdNeedsRefresh) {
            lcdNeedsRefresh = false;
            updateLCDDisplay();
        }
    }

    delay(5);
}
