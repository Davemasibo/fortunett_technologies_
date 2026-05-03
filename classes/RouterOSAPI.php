<?php
/**
 * RouterOSAPI — lightweight RouterOS API client.
 * Used by tools/deploy_hotspot.php and api/mikrotik/ scripts.
 * For the full-featured API client see classes/MikrotikAPI.php.
 */
class RouterOSAPI {
    public $debug    = false;
    public $connected = false;
    public $port     = 8728;
    public $ssl      = false;
    public $timeout  = 3;
    public $attempts = 5;
    public $delay    = 3;

    private $socket;
    public  $error_no;
    public  $error_str;

    public function connect($ip, $login, $password) {
        for ($i = 0; $i < $this->attempts; $i++) {
            try {
                $this->connected = $this->connectAttempt($ip, $login, $password);
            } catch (Exception $e) {
                $this->error_str = $e->getMessage();
                $this->connected = false;
            }
            if ($this->connected) return true;
            if ($i < $this->attempts - 1) sleep($this->delay);
        }
        return false;
    }

    private function connectAttempt($ip, $login, $password) {
        $prefix  = $this->ssl ? 'ssl://' : '';
        $context = stream_context_create(['ssl' => ['verify_peer' => false, 'verify_peer_name' => false]]);

        $this->socket = @stream_socket_client(
            $prefix . $ip . ':' . $this->port,
            $this->error_no, $this->error_str,
            $this->timeout, STREAM_CLIENT_CONNECT, $context
        );
        if (!$this->socket) return false;

        stream_set_timeout($this->socket, $this->timeout);

        // RouterOS 7: single-sentence login (plain-text password).
        // RouterOS 6: same sentence — older firmware returns a challenge token
        // in the !done reply which we handle below.
        $this->writeWord('/login');
        $this->writeWord('=name=' . $login);
        $this->writeWord('=password=' . $password);
        $this->writeWord('');  // end of sentence

        $response = $this->read();

        // !trap = bad credentials
        foreach ($response as $item) {
            if (isset($item['!trap'])) return false;
        }

        // Legacy firmware (RouterOS < 6.43) returns !done with =ret=<challenge>.
        // Answer with MD5(chr(0) + password + hex2bin(challenge)).
        $challenge = null;
        foreach ($response as $item) {
            if (isset($item['ret'])) { $challenge = $item['ret']; break; }
        }

        if ($challenge !== null) {
            $hash = '00' . md5(chr(0) . $password . pack('H*', $challenge));
            $this->writeWord('/login');
            $this->writeWord('=name='     . $login);
            $this->writeWord('=response=' . $hash);
            $this->writeWord('');

            $response = $this->read();
            foreach ($response as $item) {
                if (isset($item['!trap'])) return false;
            }
        }

        return true;
    }

    public function disconnect() {
        if ($this->socket) {
            @fclose($this->socket);
            $this->socket = null;
        }
        $this->connected = false;
    }

    /**
     * Send a command and return an array of !re records as associative arrays.
     * Each record has ['!re' => true, 'key' => 'value', ...].
     *
     * $params is an associative array: ['key' => 'value'] → sends '=key=value'.
     */
    public function comm($command, $params = []) {
        $this->writeWord($command);
        foreach ($params as $k => $v) {
            $this->writeWord('=' . $k . '=' . $v);
        }
        $this->writeWord('');  // end of sentence

        return $this->read();
    }

    // ── Private helpers ─────────────────────────────────────────────────────────

    private function read() {
        $words = [];
        $done  = false;

        while (true) {
            try {
                $word = $this->readWord();
            } catch (Exception $e) {
                if ($done || !empty($words)) break;
                throw $e;
            }

            if ($word === '') {
                if ($done) break;
                continue;  // sentence boundary — keep reading
            }

            $words[] = $word;

            if ($word === '!done' || str_starts_with($word, '!fatal')) {
                $done = true;
            }
        }

        return $this->parseWords($words);
    }

    private function parseWords(array $words) {
        $result  = [];
        $current = [];

        foreach ($words as $word) {
            if ($word[0] === '!') {
                if (!empty($current)) $result[] = $current;
                $current = [$word => true];
            } elseif ($word[0] === '=') {
                $pos = strpos($word, '=', 1);
                if ($pos !== false) {
                    $current[substr($word, 1, $pos - 1)] = substr($word, $pos + 1);
                }
            }
        }

        if (!empty($current)) $result[] = $current;

        return $result;
    }

    private function readWord() {
        $len = $this->readLen();
        if ($len === 0) return '';

        $buf = '';
        while (strlen($buf) < $len) {
            $chunk = fread($this->socket, $len - strlen($buf));
            if ($chunk === false || $chunk === '') {
                $meta = stream_get_meta_data($this->socket);
                throw new Exception(($meta['timed_out'] ?? false)
                    ? 'RouterOS API read timeout'
                    : 'Connection closed by router');
            }
            $buf .= $chunk;
        }
        return $buf;
    }

    private function readLen() {
        $raw = fread($this->socket, 1);
        if ($raw === false || $raw === '') {
            $meta = stream_get_meta_data($this->socket);
            throw new Exception(($meta['timed_out'] ?? false)
                ? 'RouterOS API read timeout'
                : 'Connection closed by router');
        }
        $b = ord($raw);

        if ($b === 0)               return 0;
        if (($b & 0x80) === 0)      return $b;
        if (($b & 0xC0) === 0x80)   return (($b & 0x3F) << 8)  | ord(fread($this->socket, 1));
        if (($b & 0xE0) === 0xC0)   return (($b & 0x1F) << 16) | (ord(fread($this->socket, 1)) << 8) | ord(fread($this->socket, 1));
        if (($b & 0xF0) === 0xE0)   return (($b & 0x0F) << 24) | (ord(fread($this->socket, 1)) << 16) | (ord(fread($this->socket, 1)) << 8) | ord(fread($this->socket, 1));
        return (ord(fread($this->socket, 1)) << 24) | (ord(fread($this->socket, 1)) << 16) | (ord(fread($this->socket, 1)) << 8) | ord(fread($this->socket, 1));
    }

    private function writeWord($word) {
        $len = strlen($word);
        if ($len < 0x80) {
            fwrite($this->socket, chr($len));
        } elseif ($len < 0x4000) {
            fwrite($this->socket, chr(($len >> 8) | 0x80) . chr($len & 0xFF));
        } elseif ($len < 0x200000) {
            fwrite($this->socket, chr(($len >> 16) | 0xC0) . chr(($len >> 8) & 0xFF) . chr($len & 0xFF));
        } elseif ($len < 0x10000000) {
            fwrite($this->socket, chr(($len >> 24) | 0xE0) . chr(($len >> 16) & 0xFF) . chr(($len >> 8) & 0xFF) . chr($len & 0xFF));
        } else {
            fwrite($this->socket, chr(0xF0) . chr(($len >> 24) & 0xFF) . chr(($len >> 16) & 0xFF) . chr(($len >> 8) & 0xFF) . chr($len & 0xFF));
        }
        if ($len > 0) fwrite($this->socket, $word);
    }
}
