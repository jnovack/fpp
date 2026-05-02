#pragma once

// Forward declaration to avoid pulling in PixelOverlayModel.h in a header
// that's transitively #included by FX.cpp.
class PixelOverlayModel;
PixelOverlayModel* currentBusModel();   // defined in wled.cpp

class BusConfig {
public:
    BusConfig(...) {}

    size_t memUsage(size_t digitalCount) const {
        return 0;
    }
    uint8_t type = TYPE_DIGITAL_MIN;
    uint8_t count = 1;
};
class Bus {
public:
    int getStart() const { return 0; }
    int getType() const { return 0; }

    // RGBW awareness: when the current overlay model has 4 bytes per
    // pixel (RGBW), tell WLED that this bus has a white channel.
    // Triggers Segment::refreshLightCapabilities() to flip on
    // SEG_CAPABILITY_W and (with the auto-white mode below) makes
    // every effect's setPixelColor call route the bright/white parts
    // to the W channel rather than driving R+G+B together.
    bool hasWhite() const;
    bool hasRGB() const { return true; }

    int getLength() const;
    uint32_t getPixelColor(int i) const;
    void setPixelColor(int i, uint32_t c);

    void setBrightness(uint32_t b) {}
    void begin() {}

    bool hasCCT() const { return false; }
    bool isOk() const { return true; }
    // Default to AUTO_BRIGHTER on RGBW models — every existing WLED
    // effect emits pure RGB and relies on the bus to extract a W
    // value. AUTO_BRIGHTER takes min(r,g,b) for W, leaving RGB intact;
    // visually best on warm/neutral white LEDs.
    uint8_t getAutoWhiteMode() const;
    static uint8_t getGlobalAWMode() { return AW_GLOBAL_DISABLED; }
    bool isOffRefreshRequired() { return false; }
    bool isVirtual() const { return false; }
    bool isPWM() const { return false; }
    bool is2Pin() const { return false; }
    static constexpr unsigned getNumberOfPins(uint8_t type) { return 4; }

    static constexpr bool isTypeValid(uint8_t type) { return (type > 15 && type < 128); }
    static constexpr bool isDigital(uint8_t type) { return (type >= TYPE_DIGITAL_MIN && type <= TYPE_DIGITAL_MAX) || is2Pin(type); }
    static constexpr bool is2Pin(uint8_t type) { return (type >= TYPE_2PIN_MIN && type <= TYPE_2PIN_MAX); }
    static constexpr bool isOnOff(uint8_t type) { return (type == TYPE_ONOFF); }
    static constexpr bool isPWM(uint8_t type) { return (type >= TYPE_ANALOG_MIN && type <= TYPE_ANALOG_MAX); }
    static constexpr bool isVirtual(uint8_t type) { return (type >= TYPE_VIRTUAL_MIN && type <= TYPE_VIRTUAL_MAX); }
    static constexpr bool is16bit(uint8_t type) { return type == TYPE_UCS8903 || type == TYPE_UCS8904 || type == TYPE_SM16825; }
    static constexpr bool mustRefresh(uint8_t type) { return type == TYPE_TM1814; }
    static constexpr int numPWMPins(uint8_t type) { return (type - 40); }
};
namespace BusManager
{
    extern Bus bus;

    constexpr uint32_t getNumBusses() { return 1; }
    constexpr Bus* getBus(size_t b) { return &bus; }
    constexpr void setBrightness(uint32_t b) {}
    constexpr bool hasRGB() { return true; }
    constexpr bool hasWhite() { return false; }
    constexpr bool canAllShow() { return false; }
    constexpr void show() {}
    constexpr void setSegmentCCT(int i, bool b = false) {}
    constexpr int getSegmentCCT() { return 0; }
    inline uint32_t getPixelColor(int i) { return bus.getPixelColor(i); }
    inline void setPixelColor(int i, uint32_t c) { bus.setPixelColor(i, c); }
    constexpr int add(...) { return -1; }
    constexpr uint32_t memUsage() { return 0; }
};

constexpr uint32_t WLED_NUM_PINS = 40;
namespace PinManager
{
    constexpr bool isPinOk(int pin, bool b) { return true; }
    constexpr bool isPinAllocated(int pin) { return true; }
};
