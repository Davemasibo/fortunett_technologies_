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
    public function connect() {
        if ($this->connected) {
            return true;
        }
        
        $this->socket = @fsockopen($this->host, $this->port, $errno, $errstr, 10);
        
        if (!$this->socket) {
            throw new Exception("Cannot connect to {$this->host}:{$this->port} - $errstr ($errno)");
        }
        
        stream_set_timeout($this->socket, 10);
        
        // Login
        $this->write('/login');
        $response = $this->read();
        
        if (isset($response[0]['!trap'])) {
            throw new Exception("Login failed: " . ($response[0]['message'] ?? 'Unknown error'));
        }
        
        // Send credentials
        $this->write('/login', false, [
            '=name=' . $this->username,
            '=password=' . $this->password
        ]);
        
        $response = $this->read();
        
        if (isset($response[0]['!trap'])) {
            throw new Exception("Authentication failed: " . ($response[0]['message'] ?? 'Invalid credentials'));
        }
        
        $this->connected = true;
        return true;
    }
    
    /**
     * Disconnect from router
     */
    public function disconnect() {
        if ($this->socket) {
            fclose($this->socket);
            $this->connected = false;
        }
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
        
        while (true) {
            $word = $this->readWord();
            
            if ($word === '') {
                break;
            }
            
            $response[] = $word;
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
        $byte = ord(fread($this->socket, 1));
        
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
    public function addPPPoEUser($username, $password, $profile = 'default', $service = 'pppoe') {
        $params = [
            '=name=' . $username,
            '=password=' . $password,
            '=service=' . $service,
            '=profile=' . $profile
        ];
        
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
     * Get active PPPoE sessions
     */
    public function getActiveSessions() {
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
    public function createPPPoEProfile($name, $localAddress, $remoteAddress, $rateLimit = null) {
        $params = [
            '=name=' . $name,
            '=local-address=' . $localAddress,
            '=remote-address=' . $remoteAddress
        ];
        
        if ($rateLimit) {
            $params[] = '=rate-limit=' . $rateLimit;
        }
        
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

    public function __destruct() {
        $this->disconnect();
    }
}
