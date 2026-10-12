/**
 * ============================================================
 * VendoLink ESP32 Controller — Hardware Configuration File
 * ============================================================
 *
 * Hardware pinouts, LCD 2004A display, coin slot pulse parameters,
 * SSR relay outputs, tank floaters, and hardware timing constants.
 *
 * 🌐 NOTE: Wi-Fi credentials, server base URL, and machine API token
 * are configured in the easy-to-edit section at the top of
 * "vendolink_esp32_heartbeat.ino" for quick switching between
 * Home, Phone Hotspot, School XAMPP, and Online hosting.
 *
 * @package VendoLink IoT
 * @version 1.6.0
 */

#ifndef VENDOLINK_CONFIG_H
#define VENDOLINK_CONFIG_H

// ============================================================
// LCD 2004A (20x4 I2C Display) Configuration
// ============================================================
const uint8_t LCD_I2C_ADDR = 0x27;
const int LCD_COLS         = 20;
const int LCD_ROWS         = 4;
const int I2C_SDA_PIN      = 21; // D21
const int I2C_SCL_PIN      = 22; // D22

// ============================================================
// Coin Slot Hardware Configuration & Dynamic Credit Cutoff
// ============================================================
// COIN Signal Line ──► ESP32 GPIO 4 (D4)
// GND             ──► ESP32 GND (Common GND with 12V Power Adapter)
const int COIN_SIGNAL_PIN       = 4;
const float COIN_VALUE          = 0.0;   // 0.0 = Automatically use configured rate saved on VendoLink website
const float MAX_CREDIT_LIMIT    = 50.0;  // Maximum accumulated credit limit (₱50.00)
const float CREDIT_CUTOFF_LIMIT = 50.0;  // Cut off coin slot power when credit reaches ₱50.00
const float CREDIT_RESUME_LIMIT = 45.0;  // Re-enable coin slot power when credit drops to ₱45.00 or below
const char* SERVICE_KEY         = "water";

const unsigned long PULSE_TIMEOUT_MS = 300;

// ============================================================
// Service Start Push Buttons & Relay Outputs (SSR-1 & SSR-2)
// ============================================================
// 💧 Water Service (SSR-1)
// Push Button: Leg 1 ──► ESP32 GPIO 15 (D15), Leg 2 ──► GND
// Relay Output: ESP32 GPIO 25 (D25) ──► SSR-1 Water Relay Signal
const int WATER_BUTTON_PIN = 15; // D15 - Water Start Button
const int WATER_RELAY_PIN  = 25; // D25 - SSR-1 Water Relay Output

// ✨ Soap Service (SSR-2)
// Push Button: Leg 1 ──► ESP32 GPIO 18 (D18), Leg 2 ──► GND
// Relay Output: ESP32 GPIO 33 (D33) ──► SSR-2 Soap Relay Signal
const int SOAP_BUTTON_PIN  = 18; // D18 - Soap Start Button
const int SOAP_RELAY_PIN   = 33; // D33 - SSR-2 Soap Relay Output

// Relay Trigger Logic (Active HIGH vs Active LOW)
// Change RELAY_ON_STATE to LOW if your SSR / Relay module is Active LOW
const int RELAY_ON_STATE  = HIGH; // HIGH = ON, LOW = OFF
const int RELAY_OFF_STATE = LOW;

// 🌊 Water & Soap Tank Level Sensors (2-Wire Float Switches)
// Water Floater: Wire 1 ──► ESP32 GPIO 13 (D13), Wire 2 ──► GND (LOW = Tank Empty)
// Soap Floater:  Wire 1 ──► ESP32 GPIO 12 (D12), Wire 2 ──► GND (LOW = Tank Empty)
const int WATER_FLOATER_PIN = 13; // D13 - Water Tank Floater Sensor Input
const int SOAP_FLOATER_PIN  = 12; // D12 - Soap Tank Floater Sensor Input

// 🔊 Piezo Buzzer / Sound Speaker Pin
// 2-Pin Buzzer: Long Leg (+) ──► D2, Short Leg (-) ──► GND
// 3-Pin Board:  S/Out ──► D2, VCC ──► 3V3/VIN, GND ──► GND
const int BUZZER_PIN = 2; // D2 (GPIO 2) - Audio Beeping Output Pin

// ============================================================
// 🔌 HW-482 V2.0.1 Coin Slot Power Relay (GPIO 14 — NC + COM Wiring)
// ============================================================
// Control side: S ──► GPIO 14, + ──► VIN (5V), – ──► GND
// Power side:   COM ──► 12V PSU(+), NC ──► Coin Slot RED (+12V), NO ──► (Unconnected)
// Common GND:   12V PSU(–), Coin Slot BLACK (GND), ESP32 GND all connected together.
const int COIN_POWER_RELAY_PIN = 14;   // D14 — HW-482 Coin Slot Power Relay Signal
const int COIN_RELAY_POWER_ON  = HIGH; // HIGH = Relay De-energized → NC Closed → 12V ON
const int COIN_RELAY_POWER_OFF = LOW;  // LOW  = Relay Energized (CLICK) → NC Opens → 12V CUT OFF

// ============================================================
// Machine Identity
// ============================================================
const char* MACHINE_KEY       = "machine_01";
const char* MACHINE_CODE      = "MACHINE-01";
const char* FIRMWARE_VERSION  = "2.8.1";

// ============================================================
// Telemetry Timing Settings
// ============================================================
const unsigned long HEARTBEAT_INTERVAL_MS = 15000;
const unsigned long WIFI_TIMEOUT_MS        = 10000;

#endif // VENDOLINK_CONFIG_H
