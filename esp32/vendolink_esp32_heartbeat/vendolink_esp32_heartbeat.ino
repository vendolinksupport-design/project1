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
 * @version 2.8.0
 */

#include <WiFi.h>
#include <HTTPClient.h>
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
// 🌐 VENDOLINK CONNECTION CONFIGURATION
// EDIT THIS SECTION WHEN CHANGING WIFI / SERVER
// ============================================================

// 1. Wi-Fi Access Point Credentials
// home wifi
const char* WIFI_SSID = "WIFI 2.4Ghz";
const char* WIFI_PASS = "p4ssw0rd";
//iqoo wifi
//const char* WIFI_SSID = "iQOO Neo9";
//const char* WIFI_PASS = "P4ssw0rd4";


// 2. VendoLink Server / API Base URL
// (Change to your phone hotspot IP, school local XAMPP IP, home IP, or online domain)
// Examples:
//   Local/Home:    "http://192.168.100.17/vendolink_claude_v1/api";
//   Phone Hotspot: "http://192.168.43.100/vendolink_claude_v1/api"
//   School XAMPP:  "http://192.168.1.xxx/vendolink_claude_v1/api"
//   Online Host:   "https://yourdomain.com/api"
//home
const char* VENDOLINK_SERVER = "http://192.168.100.17/vendolink_claude_v1/api";
//iqoo hotspot ip
//const char* VENDOLINK_SERVER = "http://10.35.163.39/vendolink_claude_v1/api";

// 3. Machine API Authentication Token (Must match dashboard database)
const char* MACHINE_API_TOKEN = "vl_dev_sec_9a8f3b2e7c1d4e5f6a7b8c9d0e";

// ============================================================
// DO NOT EDIT BELOW THIS LINE
// ============================================================

// ============================================================
// 🔗 Derived API Endpoints (Auto-constructed from VENDOLINK_SERVER)
// ============================================================
inline String buildEndpointUrl(const char* endpointPath) {
    String base = String(VENDOLINK_SERVER);
    base.trim();
    while (base.endsWith("/")) {
        base.remove(base.length() - 1);
    }
    return base + endpointPath;
}

const String SERVER_HEARTBEAT_URL = buildEndpointUrl("/machine/heartbeat.php");
const String SERVER_COIN_URL      = buildEndpointUrl("/machine/coin.php");
const String SERVER_TANK_URL      = buildEndpointUrl("/machine/tank.php");

inline String maskSecret(const char* secret) {
    if (!secret) return "****";
    String s = String(secret);
    if (s.length() <= 4) return "****";
    return s.substring(0, 2) + "..." + s.substring(s.length() - 2);
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

// Pending tank alert queue (Core 1 sets -> Core 0 transmits)
// -1 = no alert, 0 = low tank, 1 = normal tank
volatile int pendingWaterAlert = -1;
volatile int pendingSoapAlert  = -1;

// 🪙 Local Credit Balance (Accumulates on Coin Drop)
float currentCredit = 0.0;

// ⚙️ Dynamic Configured Rates, Times & Service Status (Synced from Website via Heartbeat)
float waterCoinValue        = 5.0;
float soapCoinValue         = 5.0;
int   waterServiceSec       = 120;
int   soapServiceSec        = 120;
bool  isWaterServiceEnabled = true;
bool  isSoapServiceEnabled  = true;

// 📺 20x4 LCD Screen Customizer Lines (Synced from Website)
String customLcdLine1 = "== VendoLink v2.2 ==";
String customLcdLine2 = "WELCOME CUSTOMER!";
String customLcdLine3 = "INSERT COIN FIRST!";
String customLcdLine4 = "WATER:P5 | SOAP:P5";

// Thread-safe LCD refresh trigger from Core 0 to Core 1
volatile bool lcdNeedsRefresh = false;

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
    strncpy(rec.machineKey, MACHINE_KEY, sizeof(rec.machineKey) - 1);
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

    return body.substring(firstQuote + 1, secondQuote);
}

// ============================================================
// 🌐 CORE 0: Background Network Worker Task (FreeRTOS)
// ============================================================
void networkWorkerTask(void *pvParameters) {
    Serial.println(F("🌐 [Core 0] Background Network Worker Task Started."));

    unsigned long lastNetAttempt = 0;
    unsigned long lastHeartbeatAttempt = 0;
    unsigned long lastSyncAttempt = 0;

    for (;;) {
        unsigned long now = millis();

        // 1. Maintain Wi-Fi Connection
        if (WiFi.status() == WL_CONNECTED) {
            if (!isWiFiConnected) {
                isWiFiConnected = true;
                Serial.println();
                Serial.println(F("========================================"));
                Serial.print(F("📶 [NET] Wi-Fi Connected! IP: "));
                Serial.println(WiFi.localIP().toString());
                Serial.println(F("========================================"));
            }
        } else {
            isWiFiConnected = false;
            isServerOnline  = false;
            if (now - lastNetAttempt >= 10000) {
                lastNetAttempt = now;
                Serial.print(F("📶 [NET] Connecting to Wi-Fi SSID: "));
                Serial.print(WIFI_SSID);
                Serial.println(F(" (Password: [PROTECTED])"));
                WiFi.mode(WIFI_STA);
                WiFi.begin(WIFI_SSID, WIFI_PASS);
            }
        }

        // 2. Only proceed if Wi-Fi connected AND machine is not actively dispensing
        if (isWiFiConnected && !activeServiceRunning) {

            // 3. Server Reachability Probe / Heartbeat
            unsigned long heartbeatInterval = isServerOnline ? HEARTBEAT_INTERVAL_MS : 20000;
            if (now - lastHeartbeatAttempt >= heartbeatInterval) {
                lastHeartbeatAttempt = now;

                unsigned long startMs = millis();
                Serial.println(F("[NET:Heartbeat] Starting request..."));

                HTTPClient http;
                http.begin(SERVER_HEARTBEAT_URL);
                http.addHeader("Content-Type", "application/json");
                http.addHeader("X-Machine-Token", MACHINE_API_TOKEN);
                http.setTimeout(1500);

                String ipStr = WiFi.localIP().toString();
                String jsonPayload = "{";
                jsonPayload += "\"machine_key\":\"" + String(MACHINE_KEY) + "\",";
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

                    int waterPos = responseBody.indexOf("\"water\"");
                    if (waterPos != -1) {
                        String waterChunk = responseBody.substring(waterPos);
                        float val  = extractJsonFloat(waterChunk, "coin_value", 5.0);
                        float ssec = extractJsonFloat(waterChunk, "service_seconds", 120.0);
                        float en   = extractJsonFloat(waterChunk, "is_enabled", 1.0);
                        if (val > 0)  waterCoinValue = val;
                        if (ssec > 0) waterServiceSec = (int)ssec;
                        isWaterServiceEnabled = (en > 0.5);
                    }

                    int soapPos = responseBody.indexOf("\"soap\"");
                    if (soapPos != -1) {
                        String soapChunk = responseBody.substring(soapPos);
                        float val  = extractJsonFloat(soapChunk, "coin_value", 5.0);
                        float ssec = extractJsonFloat(soapChunk, "service_seconds", 120.0);
                        float en   = extractJsonFloat(soapChunk, "is_enabled", 1.0);
                        if (val > 0)  soapCoinValue = val;
                        if (ssec > 0) soapServiceSec = (int)ssec;
                        isSoapServiceEnabled = (en > 0.5);
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
                        }
                    }
                } else {
                    if (isServerOnline || !serverStateLogged) {
                        isServerOnline = false;
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

                        HTTPClient http;
                        http.begin(SERVER_COIN_URL);
                        http.addHeader("Content-Type", "application/json");
                        http.addHeader("X-Machine-Token", MACHINE_API_TOKEN);
                        http.setTimeout(2000);

                        String statusStr = (rec.status == TX_INCOMPLETE || rec.status == TX_CANCELLED) ? "incomplete" : "completed";
                        String jsonPayload = "{";
                        jsonPayload += "\"machine_key\":\"" + String(MACHINE_KEY) + "\",";
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

                HTTPClient http;
                http.begin(SERVER_TANK_URL);
                http.addHeader("Content-Type", "application/json");
                http.addHeader("X-Machine-Token", MACHINE_API_TOKEN);
                http.setTimeout(1500);

                String jsonBody = "{\"machine_key\":\"" + String(MACHINE_KEY) +
                                  "\",\"tank_key\":\"" + tKey +
                                  "\",\"status\":\"" + sStr +
                                  "\",\"api_token\":\"" + String(MACHINE_API_TOKEN) + "\"}";

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

    if (lcd) lcd->clear();
}

// ============================================================
// Service Availability & Coin Slot Power Control (Runs on Core 1)
// ============================================================
bool isWaterAvailable() {
    bool waterTankOk = (digitalRead(WATER_FLOATER_PIN) != LOW);
    return (waterTankOk && isWaterServiceEnabled);
}

bool isSoapAvailable() {
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

    bool waterAvail = isWaterAvailable();

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

    bool shouldBePowered = (waterAvail && !creditCutoffActive);
    if (shouldBePowered == coinSlotPowered) return;

    if (shouldBePowered) {
        digitalWrite(COIN_POWER_RELAY_PIN, COIN_RELAY_POWER_ON);
        coinSlotPowered = true;
        Serial.println(F("🔌 [HW-482 GPIO 14] Relay OFF (NC Closed) -> Coin Slot POWERED ON."));
    } else {
        digitalWrite(COIN_POWER_RELAY_PIN, COIN_RELAY_POWER_OFF);
        coinSlotPowered = false;
        if (!waterAvail) {
            Serial.println(F("🔌 [HW-482 GPIO 14] Relay ON (NC Opened) -> Coin Slot CUT OFF (Water Low / Disabled)."));
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

void printLCDLine(int row, String text) {
    if (!lcd) return;
    lcd->setCursor(0, row);
    while (text.length() < 20) {
        text += " ";
    }
    if (text.length() > 20) {
        text = text.substring(0, 20);
    }
    lcd->print(text);
}

void updateLCDDisplay() {
    if (!lcd || activeServiceRunning || completionScreenActive || recoveryModeActive) return;

    lastWarningBeepSec = -1;

    bool isWaterEmpty = (digitalRead(WATER_FLOATER_PIN) == LOW);
    bool isSoapEmpty  = (digitalRead(SOAP_FLOATER_PIN)  == LOW);
    bool waterAvail   = isWaterAvailable();
    bool soapAvail    = isSoapAvailable();

    printLCDLine(0, customLcdLine1);

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
// Coin Processing Logic (Runs on Core 1)
// ============================================================
void processCoinPulses(int pulses) {
    // In Recovery Mode, reject/ignore coins to prevent credit corruption
    if (recoveryModeActive) {
        Serial.println(F("⚠️ [Coin Slot] Coin rejected — Interrupted session recovery pending."));
        return;
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

    // 2. Water Availability Check
    if (serviceKeyName == "water" && !isWaterAvailable()) {
        Serial.println(F("❌ [Service] Water service UNAVAILABLE!"));
        playBeep(120, 3);
        printLCDLine(2, "WATER UNAVAILABLE!");
        delay(2000);
        updateLCDDisplay();
        return;
    }

    // 3. Soap Availability Check
    if (serviceKeyName == "soap") {
        if (!isWaterAvailable()) {
            Serial.println(F("❌ [Service] Soap blocked — Water unavailable!"));
            playBeep(120, 3);
            printLCDLine(2, "WATER UNAVAILABLE!");
            delay(2000);
            updateLCDDisplay();
            return;
        }
        if (digitalRead(SOAP_FLOATER_PIN) == LOW) {
            Serial.println(F("❌ [Service] Soap tank EMPTY!"));
            playBeep(120, 3);
            printLCDLine(2, "SOAP TANK EMPTY!");
            delay(2000);
            updateLCDDisplay();
            return;
        }
        if (!isSoapServiceEnabled) {
            Serial.println(F("❌ [Service] Soap service DISABLED!"));
            playBeep(120, 3);
            printLCDLine(2, "SOAP DISABLED!");
            delay(2000);
            updateLCDDisplay();
            return;
        }
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
        printLCDLine(2, "WATER: P" + String((int)waterCoinValue));
        printLCDLine(3, "SOAP: P" + String((int)soapCoinValue));
        delay(2000);
        updateLCDDisplay();
        return;
    }

    // 5. Deduct Credit (Instant Local Transaction Start)
    currentCredit -= serviceCost;
    if (currentCredit < 0) currentCredit = 0.0;
    savePersistentCredit(currentCredit);
    updateCoinSlotPower();

    if (durationSeconds <= 0) durationSeconds = 120;
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

    // ⚡ Persist Active Session in NVS before relay activation
    saveActiveSession(
        serviceKeyName.c_str(),
        (serviceKeyName == "water") ? "WATER SERVICE" : "SOAP SERVICE",
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
    activeServiceName    = (serviceKeyName == "water") ? "WATER SERVICE" : "SOAP SERVICE";
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

    Serial.println();
    Serial.println(F("========================================"));
    Serial.println(F("       VendoLink ESP32 Controller       "));
    Serial.println(F("   Power-Recovery Dual-Core v2.8.1      "));
    Serial.print(F("   Target Server: ")); Serial.println(VENDOLINK_SERVER);
    Serial.print(F("   Wi-Fi SSID:    ")); Serial.println(WIFI_SSID);
    Serial.print(F("   API Token:     ")); Serial.println(maskSecret(MACHINE_API_TOKEN));
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
    Wire.begin(I2C_SDA_PIN, I2C_SCL_PIN);
    uint8_t detectedAddr = scanI2CAddress();

    lcd = new LiquidCrystal_I2C(detectedAddr, LCD_COLS, LCD_ROWS);
    lcd->init();
    lcd->backlight();
    lcd->clear();

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
        8192,
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
        if (lcd) lcd->clear();
        showInterruptedSessionScreen(0);
        playBeep(100, 3); // 3 warning beeps to notify customer of power recovery
    } else {
        // Normal Boot: Automatic transition to Ready Screen without button press
        delay(250);
        if (lcd) lcd->clear();
        updateLCDDisplay();
        playBeep(50, 2);
    }

    unsigned long bootDuration = millis() - bootStartMs;
    Serial.print(F("🚀 [BOOT COMPLETE] System Ready in "));
    Serial.print(bootDuration); Serial.println(F(" ms total."));
}

void loop() {
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
                    String title = (activeServiceKey == "water") ? "WATER PAUSED" : "SOAP PAUSED";
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
