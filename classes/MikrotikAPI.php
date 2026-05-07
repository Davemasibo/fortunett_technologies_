<?php
/**
 * MikroTik RouterOS API Class
 * Handles communication with MikroTik routers via API
 */
class MikrotikAPI {
    private $host;
    private $port;
    private $username;
    private $password;
    private $socket;
    private $connected = false;
    private $debug = false;
    
    public function __construct($host, $username, $password, $port = 8728, $debug = false) {
        $this->host = $host;
        $this->port = $port;
        $this->username = $username;
        $this->password = $password;
        $this->debug = $debug;
    }
    
    /**
     * Connect to MikroTik router
     */
    /**
     * Test if host is reachable before attempting full API connection.
     * Returns true/false without throwing.
     */
    public function isReachable(int $timeoutSec = 3): bool {
        $fp = @fsockopen($this->host, $this->port, $errno, $errstr, $timeoutSec);
        if ($fp) { fclose($fp); return true; }
        return false;
    }

    public function connect() {
        if ($this->connected) {
            return true;
        }

        // Short connect timeout — prevents hanging on unreachable routers
        $this->socket = @fsockopen($this->host, $this->port, $errno, $errstr, 5);

        if (!$this->socket) {
            throw new Exception("Cannot connect to {$this->host}:{$this->port} — $errstr ($errno)");
        }

        stream_set_timeout($this->socket, 8);

        // ── RouterOS API Login ────────────────────────────────────────────────
        // Send /login + credentials in ONE sentence. This works on RouterOS 6.43+
        // (plain-text) and also on older firmware — older versions ignore the
        // extra params in the first sentence and return a challenge, which we
        // then answer with MD5.
        $this->writeWord('/login');
        $this->writeWord('=name=' . $this->username);
        $this->writeWord('=password=' . $this->password);
        $this->writeWord(''); // end of sentence

        $response = $this->read();

        if (isset($response[0]['!trap'])) {
            throw new Exception("Authentication failed: " . ($response[0]['message'] ?? 'Invalid credentials'));
        }

        // Older firmware (RouterOS < 6.43) ignores name/password in first sentence
        // and returns !done with =ret=<challenge>. Handle that here.
        $challenge = null;
        foreach ($response as $item) {
            if (isset($item['ret'])) { $challenge = $item['ret']; break; }
        }

        if ($challenge !== null) {
            // MD5 challenge-response for legacy firmware
            $hash = '00' . md5(chr(0) . $this->password . pack('H*', $challenge));
            $this->writeWord('/login');
            $this->writeWord('=name='     . $this->username);
            $this->writeWord('=response=' . $hash);
            $this->writeWord(''); // end of sentence

            $response = $this->read();

            if (isset($response[0]['!trap'])) {
                throw new Exception("Authentication failed (legacy): " . ($response[0]['message'] ?? 'Invalid credentials'));
            }
        }

        $this->connected = true;
        return true;
    }
    
    /**
     * Disconnect from router
     */
    public function disconnect() {
        if (is_resource($this->socket)) {
            fclose($this->socket);
        }
        $this->socket    = null;
        $this->connected = false;
    }
    
    /**
     * Write command to router
     */
    private function write($command, $tag = true, $params = []) {
        $data = [];
        $data[] = $command;
        
        foreach ($params as $param) {
            $data[] = $param;
        }
        
        if ($tag) {
            $data[] = '.tag=' . uniqid();
        }
        
        foreach ($data as $line) {
            $this->writeWord($line);
        }
        
        $this->writeWord('');
        
        if ($this->debug) {
            error_log("MikroTik Write: " . implode(' ', $data));
        }
    }
    
    /**
     * Write word to socket
     */
    private function writeWord($word) {
        $len = strlen($word);
        
        if ($len < 0x80) {
            fwrite($this->socket, chr($len));
        } elseif ($len < 0x4000) {
            fwrite($this->socket, chr(($len >> 8) | 0x80));
            fwrite($this->socket, chr($len & 0xFF));
        } elseif ($len < 0x200000) {
            fwrite($this->socket, chr(($len >> 16) | 0xC0));
            fwrite($this->socket, chr(($len >> 8) & 0xFF));
            fwrite($this->socket, chr($len & 0xFF));
        } elseif ($len < 0x10000000) {
            fwrite($this->socket, chr(($len >> 24) | 0xE0));
            fwrite($this->socket, chr(($len >> 16) & 0xFF));
            fwrite($this->socket, chr(($len >> 8) & 0xFF));
            fwrite($this->socket, chr($len & 0xFF));
        } else {
            fwrite($this->socket, chr(0xF0));
            fwrite($this->socket, chr(($len >> 24) & 0xFF));
            fwrite($this->socket, chr(($len >> 16) & 0xFF));
            fwrite($this->socket, chr(($len >> 8) & 0xFF));
            fwrite($this->socket, chr($len & 0xFF));
        }
        
        fwrite($this->socket, $word);
    }
    
    /**
     * Read response from router
     */
    private function read() {
        $response = [];
        $done     = false;

        while (true) {
            try {
                $word = $this->readWord();
            } catch (Exception $e) {
                // Some RouterOS versions close the TCP connection immediately after
                // sending !done / !fatal without sending the trailing empty word.
                // If we already have data (or saw !done), return what we have
                // rather than surfacing a misleading "connection closed" error.
                if ($done || !empty($response)) {
                    break;
                }
                throw $e;
            }

            if ($word === '') {
                // Empty word = end of one sentence.
                // Only stop when we have already seen !done (or !fatal) —
                // otherwise keep reading because more !re sentences may follow.
                if ($done) {
                    break;
                }
                continue; // mid-response sentence boundary — keep going
            }

            $response[] = $word;

            // !done signals the router has sent the full reply.
            // !fatal signals a fatal error that closes the connection.
            if ($word === '!done' || str_starts_with($word, '!fatal')) {
                $done = true;
            }
        }

        return $this->parseResponse($response);
    }
    
    /**
     * Read word from socket
     */
    private function readWord() {
        $len = $this->readLen();
        
        if ($len === 0) {
            return '';
        }
        
        $word = '';
        $remaining = $len;
        
        while ($remaining > 0) {
            $data = fread($this->socket, $remaining);
            if ($data === false || $data === '') {
                throw new Exception("Connection lost while reading");
            }
            $word .= $data;
            $remaining -= strlen($data);
        }
        
        return $word;
    }
    
    /**
     * Read length from socket
     */
    private function readLen() {
        $data = fread($this->socket, 1);

        // Empty string or false means socket timed out or was closed by the router.
        // Returning 0 here would cause read() to loop forever, so throw instead.
        if ($data === false || $data === '') {
            $meta = stream_get_meta_data($this->socket);
            if ($meta['timed_out'] ?? false) {
                throw new Exception("Router API read timeout — no response within the allowed window");
            }
            throw new Exception("Connection closed by router unexpectedly");
        }

        $byte = ord($data);

        if ($byte == 0) {
            return 0;
        }

        if (($byte & 0x80) == 0) {
            return $byte;
        }

        if (($byte & 0xC0) == 0x80) {
            return (($byte & 0x3F) << 8) + ord(fread($this->socket, 1));
        }

        if (($byte & 0xE0) == 0xC0) {
            return (($byte & 0x1F) << 16) + (ord(fread($this->socket, 1)) << 8) + ord(fread($this->socket, 1));
        }

        if (($byte & 0xF0) == 0xE0) {
            return (($byte & 0x0F) << 24) + (ord(fread($this->socket, 1)) << 16) + (ord(fread($this->socket, 1)) << 8) + ord(fread($this->socket, 1));
        }

        return (ord(fread($this->socket, 1)) << 24) + (ord(fread($this->socket, 1)) << 16) + (ord(fread($this->socket, 1)) << 8) + ord(fread($this->socket, 1));
    }
    
    /**
     * Parse response from router
     */
    private function parseResponse($response) {
        $parsed = [];
        $current = [];
        
        foreach ($response as $line) {
            if (substr($line, 0, 1) == '!') {
                if (!empty($current)) {
                    $parsed[] = $current;
                }
                $current = [$line => true];
            } elseif (substr($line, 0, 1) == '=') {
                $pos = strpos($line, '=', 1);
                if ($pos !== false) {
                    $key = substr($line, 1, $pos - 1);
                    $value = substr($line, $pos + 1);
                    $current[$key] = $value;
                }
            }
        }
        
        if (!empty($current)) {
            $parsed[] = $current;
        }
        
        if ($this->debug) {
            error_log("MikroTik Read: " . print_r($parsed, true));
        }
        
        return $parsed;
    }
    
    /**
     * Execute command and return response
     */
    public function comm($command, $params = []) {
        if (!$this->connected) {
            $this->connect();
        }
        
        $this->write($command, true, $params);
        return $this->read();
    }
    
    /**
     * Get all PPPoE users
     */
    public function getPPPoEUsers() {
        $response = $this->comm('/ppp/secret/print');
        $users = [];
        
        foreach ($response as $item) {
            if (isset($item['!re'])) {
                unset($item['!re']);
                $users[] = $item;
            }
        }
        
        return $users;
    }
    
    /**
     * Add PPPoE user
     */
    public function addPPPoEUser($username, $password, $profile = 'default', $service = 'pppoe', $rateLimit = null) {
        $params = [
            '=name='     . $username,
            '=password=' . $password,
            '=service='  . $service,
            '=profile='  . $profile,
        ];
        // Apply bandwidth limit directly on the secret — enforced even if profile lacks limits
        if (!empty($rateLimit)) {
            $params[] = '=rate-limit=' . $rateLimit;
        }

        $response = $this->comm('/ppp/secret/add', $params);
        
        if (isset($response[0]['!trap'])) {
            throw new Exception("Failed to add user: " . ($response[0]['message'] ?? 'Unknown error'));
        }
        
        return true;
    }
    
    /**
     * Update PPPoE user
     */
    public function updatePPPoEUser($username, $newPassword = null, $newProfile = null) {
        // Find user ID
        $users = $this->getPPPoEUsers();
        $userId = null;
        
        foreach ($users as $user) {
            if ($user['name'] === $username) {
                $userId = $user['.id'];
                break;
            }
        }
        
        if (!$userId) {
            throw new Exception("User not found: $username");
        }
        
        $params = ['=.id=' . $userId];
        
        if ($newPassword !== null) {
            $params[] = '=password=' . $newPassword;
        }
        
        if ($newProfile !== null) {
            $params[] = '=profile=' . $newProfile;
        }
        
        $response = $this->comm('/ppp/secret/set', $params);
        
        if (isset($response[0]['!trap'])) {
            throw new Exception("Failed to update user: " . ($response[0]['message'] ?? 'Unknown error'));
        }
        
        return true;
    }
    
    /**
     * Delete PPPoE user
     */
    public function deletePPPoEUser($username) {
        // Find user ID
        $users = $this->getPPPoEUsers();
        $userId = null;
        
        foreach ($users as $user) {
            if ($user['name'] === $username) {
                $userId = $user['.id'];
                break;
            }
        }
        
        if (!$userId) {
            throw new Exception("User not found: $username");
        }
        
        $params = ['=.id=' . $userId];
        $response = $this->comm('/ppp/secret/remove', $params);
        
        if (isset($response[0]['!trap'])) {
            throw new Exception("Failed to delete user: " . ($response[0]['message'] ?? 'Unknown error'));
        }
        
        return true;
    }
    
    /**
     * Get active PPPoE sessions (raw array).
     * No proplist — RouterOS 7.x returns all fields including rx-byte/tx-byte by
     * default when no filter is applied. Using proplist causes RouterOS to omit stats
     * fields (they require "print stats" in CLI), so we avoid it here.
     */
    public function getActiveSessions(): array {
        $response = $this->comm('/ppp/active/print');
        $sessions = [];
        foreach ($response as $item) {
            if (isset($item['!re'])) {
                unset($item['!re']);
                $sessions[] = $item;
            }
        }
        return $sessions;
    }

    /**
     * Get map of username → session info for fast online-status lookups.
     * Returns [ 'username' => ['uptime'=>'...','address'=>'...'], ... ]
     *
     * RouterOS 7.x does not include rx-byte/tx-byte in /ppp/active/print (they
     * are "stats" fields omitted from the standard API response). As a fallback,
     * each active PPPoE session creates an interface named after the username, so
     * we also fetch /interface/print which always carries rx-byte/tx-byte.
     */
    public function getActiveSessionsMap(): array {
        $sessions = $this->getActiveSessions();

        // Pre-load interface byte counters keyed by interface name (= PPPoE username).
        $ifBytes = [];
        try {
            $ifResp = $this->comm('/interface/print');
            foreach ($ifResp as $item) {
                if (!isset($item['!re'], $item['name'])) continue;
                $ifBytes[strtolower($item['name'])] = [
                    'rx' => (string)(int)($item['rx-byte'] ?? '0'),
                    'tx' => (string)(int)($item['tx-byte'] ?? '0'),
                ];
            }
        } catch (Throwable $_e) {}

        $map = [];
        foreach ($sessions as $s) {
            $name = $s['name'] ?? ($s['user'] ?? null);
            if (!$name) continue;

            $key = strtolower($name);

            // Priority: session-level counters → interface counters
            $rxRaw = $s['rx-byte']   ?? '';
            $txRaw = $s['tx-byte']   ?? '';
            $rxAlt = $s['bytes-in']  ?? '';
            $txAlt = $s['bytes-out'] ?? '';

            $rx = $rxRaw !== '' ? $rxRaw
                : ($rxAlt !== '' ? $rxAlt : ($ifBytes[$key]['rx'] ?? '0'));
            $tx = $txRaw !== '' ? $txRaw
                : ($txAlt !== '' ? $txAlt : ($ifBytes[$key]['tx'] ?? '0'));

            $map[$key] = [
                'uptime'  => $s['uptime']    ?? '',
                'address' => $s['address']   ?? '',
                'rx_byte' => (string)(int)$rx,
                'tx_byte' => (string)(int)$tx,
                'caller'  => $s['caller-id'] ?? '',
            ];
        }
        return $map;
    }

    /**
     * Get active Hotspot sessions map (username → session info).
     * Returns empty array if hotspot is not configured on this router.
     * No proplist is sent — requesting all fields avoids RouterOS version
     * differences in which proplist entries are recognised.
     */
    public function getActiveHotspotSessionsMap(): array {
        $response = $this->comm('/ip/hotspot/active/print');

        foreach ($response as $item) {
            if (isset($item['!trap'])) {
                // Hotspot package not installed or not configured — treat as no sessions
                error_log('MikrotikAPI getActiveHotspotSessionsMap trap: ' . ($item['message'] ?? 'unknown trap'));
                return [];
            }
        }

        $map = [];
        foreach ($response as $item) {
            if (isset($item['!re'])) {
                unset($item['!re']);
                // Use 'user' if non-empty; fall back to MAC address for MAC-auth sessions
                $name = (!empty($item['user'])) ? $item['user'] : ($item['mac-address'] ?? null);
                if ($name) {
                    $map[strtolower($name)] = [
                        'uptime'  => $item['uptime']      ?? '',
                        'address' => $item['address']     ?? '',
                        'mac'     => $item['mac-address'] ?? '',
                        'rx_byte' => $item['bytes-in']    ?? '0',
                        'tx_byte' => $item['bytes-out']   ?? '0',
                    ];
                }
            }
        }
        return $map;
    }

    /**
     * Enable a PPPoE secret (re-enable a disabled user)
     */
    public function enablePPPoEUser(string $username): bool {
        $users = $this->getPPPoEUsers();
        foreach ($users as $u) {
            if (strcasecmp($u['name'] ?? '', $username) === 0) {
                $r = $this->comm('/ppp/secret/enable', ['=.id=' . $u['.id']]);
                return !isset($r[0]['!trap']);
            }
        }
        return false;
    }

    /**
     * Disable a PPPoE secret (block without deleting)
     */
    public function disablePPPoEUser(string $username): bool {
        $users = $this->getPPPoEUsers();
        foreach ($users as $u) {
            if (strcasecmp($u['name'] ?? '', $username) === 0) {
                $r = $this->comm('/ppp/secret/disable', ['=.id=' . $u['.id']]);
                return !isset($r[0]['!trap']);
            }
        }
        return false;
    }

    /**
     * Kick active PPPoE session for a user (forces reconnect)
     */
    public function kickPPPoESession(string $username): bool {
        $sessions = $this->getActiveSessions();
        foreach ($sessions as $s) {
            $name = $s['name'] ?? ($s['user'] ?? '');
            if (strcasecmp($name, $username) === 0 && isset($s['.id'])) {
                $r = $this->comm('/ppp/active/remove', ['=.id=' . $s['.id']]);
                return !isset($r[0]['!trap']);
            }
        }
        return false;
    }

    /**
     * Kick an active Hotspot session (forces disconnection)
     */
    public function kickHotspotSession(string $username): bool {
        $response = $this->comm('/ip/hotspot/active/print');
        foreach ($response as $item) {
            if (!isset($item['!re'])) continue;
            $name = $item['user'] ?? '';
            if (strcasecmp($name, $username) === 0 && isset($item['.id'])) {
                $r = $this->comm('/ip/hotspot/active/remove', ['=.id=' . $item['.id']]);
                return !isset($r[0]['!trap']);
            }
        }
        return false;
    }

    /**
     * Admin-initiated disconnect: clear MAC auth binding then kick the active session.
     * Without clearing the MAC, the device immediately re-authenticates via MAC bypass
     * and the disconnect appears to have no effect.
     */
    public function disconnectHotspotUser(string $username): bool {
        $users = $this->getHotspotUsers();
        foreach ($users as $u) {
            if (strcasecmp($u['name'] ?? '', $username) === 0) {
                // Clear MAC so device must go back through the captive portal
                $this->comm('/ip/hotspot/user/set', ['=.id=' . $u['.id'], '=mac-address=']);
                break;
            }
        }
        return $this->kickHotspotSession($username);
    }

    /**
     * Get router resources (CPU, memory, uptime)
     */
    public function getResources() {
        $response = $this->comm('/system/resource/print');
        
        if (isset($response[0]['!re'])) {
            unset($response[0]['!re']);
            return $response[0];
        }
        
        return [];
    }
    
    /**
     * Get interface statistics
     */
    public function getInterfaces() {
        $response = $this->comm('/interface/print', ['=stats']);
        $interfaces = [];
        
        foreach ($response as $item) {
            if (isset($item['!re'])) {
                unset($item['!re']);
                $interfaces[] = $item;
            }
        }
        
        return $interfaces;
    }
    
    /**
     * Create PPPoE profile
     */
    public function createPPPoEProfile($name, $localAddress = null, $remoteAddress = null, $rateLimit = null) {
        $params = ['=name=' . $name];
        // Only set addresses if explicitly provided — avoids referencing non-existent pools
        if (!empty($localAddress))  $params[] = '=local-address='  . $localAddress;
        if (!empty($remoteAddress)) $params[] = '=remote-address=' . $remoteAddress;
        if (!empty($rateLimit))     $params[] = '=rate-limit='     . $rateLimit;

        $response = $this->comm('/ppp/profile/add', $params);
        
        if (isset($response[0]['!trap'])) {
            throw new Exception("Failed to create profile: " . ($response[0]['message'] ?? 'Unknown error'));
        }
        
        return true;
    }
    
    /**
     * Get all PPPoE profiles
     */
    public function getPPPoEProfiles() {
        $response = $this->comm('/ppp/profile/print');
        $profiles = [];
        
        foreach ($response as $item) {
            if (isset($item['!re'])) {
                unset($item['!re']);
                $profiles[] = $item;
            }
        }
        
        return $profiles;
    }
    
    // ---------------- Hotspot Methods ----------------

    /**
     * Enable a hotspot user (re-enable after being disabled by expiry/suspension)
     */
    public function enableHotspotUser(string $username): bool {
        $users = $this->getHotspotUsers();
        foreach ($users as $u) {
            if (strcasecmp($u['name'] ?? '', $username) === 0) {
                $r = $this->comm('/ip/hotspot/user/enable', ['=.id=' . $u['.id']]);
                return !isset($r[0]['!trap']);
            }
        }
        return false;
    }

    /**
     * Disable a hotspot user (block without deleting) and kick any active session
     */
    public function disableHotspotUser(string $username): bool {
        $users = $this->getHotspotUsers();
        foreach ($users as $u) {
            if (strcasecmp($u['name'] ?? '', $username) === 0) {
                $r = $this->comm('/ip/hotspot/user/disable', ['=.id=' . $u['.id']]);
                if (!isset($r[0]['!trap'])) {
                    $this->kickHotspotSession($username);
                    return true;
                }
                return false;
            }
        }
        return false;
    }

    /**
     * Get all Hotspot users
     */
    public function getHotspotUsers() {
        $response = $this->comm('/ip/hotspot/user/print');
        $users = [];
        
        foreach ($response as $item) {
            if (isset($item['!re'])) {
                unset($item['!re']);
                $users[] = $item;
            }
        }
        
        return $users;
    }

    /**
     * Add Hotspot user
     */
    public function addHotspotUser($username, $password, $profile = 'default', $server = 'all') {
        $params = [
            '=name=' . $username,
            '=password=' . $password,
            '=profile=' . $profile,
            '=server=' . $server
        ];
        
        $response = $this->comm('/ip/hotspot/user/add', $params);
        
        if (isset($response[0]['!trap'])) {
            throw new Exception("Failed to add hotspot user: " . ($response[0]['message'] ?? 'Unknown error'));
        }
        
        return true;
    }

    /**
     * Update Hotspot user
     */
    public function updateHotspotUser($username, $newPassword = null, $newProfile = null) {
        // Find user ID
        $users = $this->getHotspotUsers();
        $userId = null;
        
        foreach ($users as $user) {
            if ($user['name'] === $username) {
                $userId = $user['.id'];
                break;
            }
        }
        
        if (!$userId) {
            throw new Exception("Hotspot user not found: $username");
        }
        
        $params = ['=.id=' . $userId];
        
        if ($newPassword !== null) {
            $params[] = '=password=' . $newPassword;
        }
        
        if ($newProfile !== null) {
            $params[] = '=profile=' . $newProfile;
        }
        
        $response = $this->comm('/ip/hotspot/user/set', $params);
        
        if (isset($response[0]['!trap'])) {
            throw new Exception("Failed to update hotspot user: " . ($response[0]['message'] ?? 'Unknown error'));
        }
        
        return true;
    }

    /**
     * Delete Hotspot user
     */
    public function deleteHotspotUser($username) {
        $users = $this->getHotspotUsers();
        $userId = null;
        
        foreach ($users as $user) {
            if ($user['name'] === $username) {
                $userId = $user['.id'];
                break;
            }
        }
        
        if (!$userId) {
            throw new Exception("Hotspot user not found: $username");
        }
        
        $params = ['=.id=' . $userId];
        $response = $this->comm('/ip/hotspot/user/remove', $params);
        
        if (isset($response[0]['!trap'])) {
            throw new Exception("Failed to delete hotspot user: " . ($response[0]['message'] ?? 'Unknown error'));
        }
        
        return true;
    }
    
    /**
     * Create Hotspot Profile
     */
    public function createHotspotProfile($name, $rateLimit = null) {
        $params = [
            '=name=' . $name,
             // Hotspot profiles typically don't need local/remote address pools defined directly here for simple setup, 
             // but often link to a user pool. For simplicity MVP:
            '=shared-users=1'
        ];
        
        if ($rateLimit) {
            $params[] = '=rate-limit=' . $rateLimit;
        }
        
        $response = $this->comm('/ip/hotspot/user/profile/add', $params);
        
        if (isset($response[0]['!trap'])) {
            throw new Exception("Failed to create hotspot profile: " . ($response[0]['message'] ?? 'Unknown error'));
        }
        
        return true;
    }
    
    /**
     * Get all Hotspot Profiles
     */
    public function getHotspotUserProfiles() {
        $response = $this->comm('/ip/hotspot/user/profile/print');
        $profiles = [];

        foreach ($response as $item) {
            if (isset($item['!re'])) {
                unset($item['!re']);
                $profiles[] = $item;
            }
        }

        return $profiles;
    }

    public function getHotspotServers() {
        $response = $this->comm('/ip/hotspot/print');
        $servers = [];
        foreach ($response as $item) {
            if (isset($item['!re'])) {
                unset($item['!re']);
                $servers[] = $item;
            }
        }
        return $servers;
    }

    public function __destruct() {
        $this->disconnect();
    }
}
