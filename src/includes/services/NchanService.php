<?php
/**
 * <module_context>
 *     <name>NchanService</name>
 *     <description>Publishes real-time status updates via Unraid's Nchan infrastructure.</description>
 *     <dependencies>Unraid's Nchan nginx module (built-in)</dependencies>
 *     <constraints>Under 80 lines. Fire-and-forget publishing — never blocks callers.</constraints>
 * </module_context>
 */

namespace AICliAgents\Services;

class NchanService {

    /**
     * Publish a message to an Nchan channel via localhost HTTP POST.
     * Uses curl directly to the nginx /pub/ endpoint (avoids publish.php include path issues).
     * @param string $channel Channel suffix (e.g., 'install_gemini-cli', 'status_root')
     * @param array $data Associative array to JSON-encode and publish
     */
    public static function publish(string $channel, array $data): void {
        try {
            $endpoint = "aicli_$channel";
            $message = json_encode($data);
            // Unraid's Nchan publisher lives on the internal Unix socket server,
            // not the main HTTP server. Must publish via the socket.
            $url = "http://localhost/pub/$endpoint?buffer_length=1";

            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_UNIX_SOCKET_PATH, '/var/run/nginx.socket');
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $message);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 2);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 1);
            curl_exec($ch);
            curl_close($ch);
        } catch (\Throwable $e) {
            // Fire and forget — never let Nchan failures break the caller
        }
    }

    /**
     * Publish install progress for an agent.
     */
    public static function publishInstallProgress(string $agentId, int $progress, string $step, string $reason = ''): void {
        self::publish("install_$agentId", [
            'agentId' => $agentId,
            'progress' => $progress,
            'step' => $step,
            'completed' => $progress >= 100,
            'reason' => $reason,
            'timestamp' => time()
        ]);
    }

    /**
     * Publish storage operation progress.
     */
    public static function publishStorageProgress(string $type, string $id, int $progress, string $step): void {
        self::publish("storage_{$type}_{$id}", [
            'type' => $type,
            'id' => $id,
            'progress' => $progress,
            'step' => $step,
            'completed' => $progress >= 100,
            'timestamp' => time()
        ]);
    }

    /**
     * Publish session status update.
     */
    public static function publishSessionStatus(string $user, array $statusData): void {
        self::publish("status_$user", $statusData);
    }
}
