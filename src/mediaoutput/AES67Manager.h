#pragma once
/*
 * This file is part of the Falcon Player (FPP) and is Copyright (C)
 * 2013-2025 by the Falcon Player Developers.
 *
 * The Falcon Player (FPP) is free software, and is covered under
 * multiple Open Source licenses.  Please see the included 'LICENSES'
 * file for descriptions of what files are covered by each license.
 *
 * This source file is covered under the LGPL v2.1 as described in the
 * included LICENSE.LGPL file.
 */

// AES67Manager — GStreamer-based AES67 audio-over-IP send/receive
//
// Replaces the previous PipeWire RTP module approach with GStreamer pipelines
// that use GstPtpClock for IEEE 1588 PTP-derived media clock timestamps,
// achieving true AES67 compliance.
//
// Features:
//   - Send: pipewiresrc → audioconvert → rtpL24pay → rtpbin → udpsink
//   - Receive: udpsrc → rtpbin (rfc7273-sync) → rtpL24depay → audioconvert → pipewiresink
//   - GstPtpClock for PTP-derived RTP timestamps
//   - Built-in SAP announcer (replaces external fpp_aes67_sap Python daemon)
//   - SAP receiver for inbound stream discovery
//   - Config format: same pipewire-aes67-instances.json (backward-compatible)

#if __has_include(<gst/gst.h>)
#define HAS_AES67_GSTREAMER

#include <gst/gst.h>
#include <httpserver.hpp>

#include <atomic>
#include <functional>
#include <map>
#include <mutex>
#include <string>
#include <thread>
#include <vector>

// ──────────────────────────────────────────────────────────────────────────────
// AES67 Protocol Constants (mirror fpp_aes67_common.py)
// ──────────────────────────────────────────────────────────────────────────────
namespace AES67 {

constexpr int RTP_PAYLOAD_TYPE      = 96;
constexpr int AUDIO_RATE            = 48000;
constexpr int AUDIO_RTP_TTL         = 4;
constexpr int DEFAULT_PTIME_MS      = 4;        // 4ms packet time
constexpr int DEFAULT_PORT          = 5004;
constexpr int DEFAULT_CHANNELS      = 2;
constexpr int DEFAULT_LATENCY_MS    = 10;

constexpr const char* DEFAULT_MULTICAST_IP = "239.69.0.1";
constexpr const char* AUDIO_FORMAT         = "S24BE";
constexpr const char* SAP_MCAST_ADDRESS    = "239.255.255.255";
constexpr int SAP_PORT                     = 9875;
constexpr int SAP_VERSION                  = 1;
constexpr int SAP_ANNOUNCE_INTERVAL_S      = 30;
constexpr int SAP_TTL                      = 255;

// Valid AES67 ptimes
inline bool IsValidPtime(int ptime) { return ptime == 1 || ptime == 4; }

// Channel position names for SDP
const char* GetSDPChannelNames(int channels);

} // namespace AES67

// ──────────────────────────────────────────────────────────────────────────────
// Instance configuration — mirrors the JSON schema
// ──────────────────────────────────────────────────────────────────────────────
struct AES67Instance {
    int id = 0;
    std::string name;
    bool enabled = true;
    std::string mode = "send";           // "send" | "receive" | "both"
    std::string multicastIP = AES67::DEFAULT_MULTICAST_IP;
    int port = AES67::DEFAULT_PORT;
    int channels = AES67::DEFAULT_CHANNELS;
    std::string interface;               // network interface (e.g., "eth0")
    std::string sessionName;
    int latency = AES67::DEFAULT_LATENCY_MS;
    bool sapEnabled = true;
    int ptime = AES67::DEFAULT_PTIME_MS;
};

struct AES67Config {
    std::vector<AES67Instance> instances;
    bool ptpEnabled = true;
    std::string ptpInterface = "eth0";
};

// ──────────────────────────────────────────────────────────────────────────────
// Pipeline wrapper — one per send or receive direction of an instance
// ──────────────────────────────────────────────────────────────────────────────
struct AES67Pipeline {
    int instanceId = 0;
    bool isSend = true;
    GstElement* pipeline = nullptr;
    GstElement* rtpbin = nullptr;

    // Pipeline state
    bool running = false;
    std::string errorMessage;
};

// ──────────────────────────────────────────────────────────────────────────────
// SAP discovered stream (from remote announcements)
// ──────────────────────────────────────────────────────────────────────────────
struct SAPDiscoveredStream {
    uint16_t msgIdHash = 0;
    std::string originAddress;
    std::string sessionName;
    std::string multicastIP;
    int port = 0;
    int channels = 2;
    int ptime = 4;
    std::string ptpClockId;
    uint64_t lastSeenMs = 0;
    int autoCreatedInstanceId = -1;      // ID of auto-created receive pipeline, or -1
};

// ──────────────────────────────────────────────────────────────────────────────
// AES67Manager — singleton managing all AES67 GStreamer pipelines
// ──────────────────────────────────────────────────────────────────────────────
class AES67Manager : public httpserver::http_resource {
public:
    static AES67Manager& INSTANCE;

    // HTTP API endpoint (registered at /aes67)
    HTTP_RESPONSE_CONST std::shared_ptr<httpserver::http_response> render_GET(
        const httpserver::http_request& req);

    // Lifecycle
    bool Init();                         // Called from fppd startup
    void Shutdown();                     // Called from fppd shutdown

    // Apply configuration — reads JSON and rebuilds pipelines
    // Called from PHP API (POST /api/pipewire/aes67/apply) and boot
    bool ApplyConfig();

    // Cleanup — stop all pipelines, remove PipeWire configs
    void Cleanup();

    // Status query — for PHP API (GET /api/pipewire/aes67/status)
    struct Status {
        struct PipelineStatus {
            int instanceId;
            std::string name;
            std::string mode;        // "send" or "receive"
            bool running;
            std::string error;
        };
        std::vector<PipelineStatus> pipelines;
        bool ptpSynced = false;
        int64_t ptpOffsetNs = 0;     // offset from PTP master
        std::string ptpGrandmasterId;
        std::vector<SAPDiscoveredStream> discoveredStreams;
    };
    Status GetStatus();

    // Signal from fppd that PipeWire is ready (called after PipeWire restart)
    void OnPipeWireReady();

    // Check if manager is active
    bool IsActive() const { return m_active.load(); }

    AES67Manager();
    virtual ~AES67Manager();

private:

    // Config
    AES67Config m_config;
    std::string m_configPath;
    bool LoadConfig();

    // PTP
    bool m_ptpInitialized = false;
    GstClock* m_ptpClock = nullptr;
    bool InitPTP();
    void ShutdownPTP();
    std::string GetPTPClockId();         // EUI-64 from interface MAC

    // Pipeline management
    std::map<int, AES67Pipeline> m_sendPipelines;    // keyed by instance ID
    std::map<int, AES67Pipeline> m_recvPipelines;
    std::mutex m_pipelineMutex;

    bool CreateSendPipeline(const AES67Instance& inst);
    bool CreateRecvPipeline(const AES67Instance& inst);
    void StopPipeline(AES67Pipeline& p);
    void StopAllPipelines();

    // Get source IP for the network interface
    std::string GetInterfaceIP(const std::string& iface);

    // SAP announcer thread
    std::thread m_sapAnnounceThread;
    std::atomic<bool> m_sapAnnounceRunning{false};
    void SAPAnnounceLoop();
    std::string BuildSDP(const AES67Instance& inst, const std::string& sourceIP,
                         const std::string& ptpClockId);
    std::vector<uint8_t> BuildSAPPacket(const std::string& sourceIP,
                                        uint16_t msgIdHash,
                                        const std::string& sdp,
                                        bool isDeletion = false);
    uint16_t ComputeSAPHash(const AES67Instance& inst);

    // SAP receiver thread
    std::thread m_sapRecvThread;
    std::atomic<bool> m_sapRecvRunning{false};
    void SAPReceiveLoop();
    std::map<uint16_t, SAPDiscoveredStream> m_discoveredStreams;
    std::mutex m_discoveredMutex;
    void HandleSAPPacket(const uint8_t* data, size_t len,
                         const std::string& senderAddr);

    // GStreamer bus callback
    static gboolean OnBusMessage(GstBus* bus, GstMessage* msg, gpointer userData);

    // State
    std::atomic<bool> m_active{false};
    std::atomic<bool> m_initialized{false};

    // Safe node name (matches fpp_aes67_common.py safe_node_name)
    static std::string SafeNodeName(const std::string& name);
};

#endif // HAS_AES67_GSTREAMER
