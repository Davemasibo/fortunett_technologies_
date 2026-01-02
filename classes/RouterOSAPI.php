<?php

class RouterOSAPI {
    var $debug = false;
    var $connected = false;
    var $port = 8728;
    var $ssl = false;
    var $timeout = 3;
    var $attempts = 5;
    var $delay = 3;
    
    var $socket;
    var $error_no;
    var $error_str;

    public function connect($ip, $login, $password) {
        for ($i = 0; $i < $this->attempts; $i++) {
            $this->connected = $this->connect_attempt($ip, $login, $password);
            if ($this->connected) break;
            sleep($this->delay);
        }
        return $this->connected;
    }

    private function connect_attempt($ip, $login, $password) {
        $protocol = $this->ssl ? 'ssl://' : '';
        $context = stream_context_create(['ssl' => ['verify_peer' => false, 'verify_peer_name' => false]]);
        
        $this->socket = @stream_socket_client($protocol . $ip . ':' . $this->port, $this->error_no, $this->error_str, $this->timeout, STREAM_CLIENT_CONNECT, $context);
        
        if ($this->socket) {
            socket_set_timeout($this->socket, $this->timeout);
            $this->write('/login', false);
            $this->write('=name=' . $login, false);
            $this->write('=password=' . $password);
            
            $response = $this->read(false);
            if (isset($response[0]) && $response[0] == '!done') {
                return true;
            }
        }
        return false;
    }

    public function disconnect() {
        if ($this->socket) {
            fclose($this->socket);
        }
        $this->connected = false;
    }

    public function comm($com, $arr = array()) {
        $count = count($arr);
        $this->write($com, !($count > 0));
        $i = 0;
        foreach ($arr as $k => $v) {
            switch ($k) {
                case 0:
                    $this->write('?'.$v, ($i++ == $count-1));
                    break;
                default:
                    $this->write('='.$k.'='.$v, ($i++ == $count-1));
            }
        }
        return $this->read();
    }

    private function read($parse = true) {
        $response = array();
        $paramLines = 0;
        while (true) {
            $byte = ord(fread($this->socket, 1));
            $length = 0;
            if ($byte & 128) {
                if (($byte & 192) == 128) {
                    $length = (($byte & 63) << 8) + ord(fread($this->socket, 1));
                } else {
                    if (($byte & 224) == 192) {
                        $length = (($byte & 31) << 8) + ord(fread($this->socket, 1));
                        $length = ($length << 8) + ord(fread($this->socket, 1));
                    } else {
                        if (($byte & 240) == 224) {
                            $length = (($byte & 15) << 8) + ord(fread($this->socket, 1));
                            $length = ($length << 8) + ord(fread($this->socket, 1));
                            $length = ($length << 8) + ord(fread($this->socket, 1));
                        } else {
                            $length = ord(fread($this->socket, 1));
                            $length = ($length << 8) + ord(fread($this->socket, 1));
                            $length = ($length << 8) + ord(fread($this->socket, 1));
                            $length = ($length << 8) + ord(fread($this->socket, 1));
                        }
                    }
                }
            } else {
                $length = $byte;
            }

            $line = "";
            if ($length > 0) {
                $line = "";
                $rec = 0;
                while($rec < $length) {
                    $r = fread($this->socket, $length - $rec);
                    $rec += strlen($r);
                    $line .= $r;
                }
            }
            
            if ($line == '!done') {
                break;
            } elseif ($line == '!trap') {
                // Error
            } elseif ($line == '!re') {
                // Response end marker for a row usually
            } else {
                 $response[] = $line;
            }
        }
        
        if($parse) $this->parseResponse($response);
        return $response;
    }
    
    private function parseResponse(&$response) {
        // Simplified parser for this use case
        // Standard lib is more complex
    }
    
    // Standard write function logic for RouterOS API
    private function write($command, $param2 = true) {
        if ($command) {
            $data = $command;
            $length = strlen($data);
            if ($length < 0x80) {
                fwrite($this->socket, chr($length));
            } elseif ($length < 0x4000) {
                fwrite($this->socket, chr(0x80 | ($length >> 8)) . chr($length & 0xFF));
            } elseif ($length < 0x200000) {
                fwrite($this->socket, chr(0xC0 | ($length >> 16)) . chr(($length >> 8) & 0xFF) . chr($length & 0xFF));
            } elseif ($length < 0x10000000) {
                fwrite($this->socket, chr(0xE0 | ($length >> 24)) . chr(($length >> 16) & 0xFF) . chr(($length >> 8) & 0xFF) . chr($length & 0xFF));
            } elseif ($length < 0x100000000) {
                 fwrite($this->socket, chr(0xF0) . chr(($length >> 24) & 0xFF) . chr(($length >> 16) & 0xFF) . chr(($length >> 8) & 0xFF) . chr($length & 0xFF));
            }
            fwrite($this->socket, $data);
        }
        if ($param2) {
             fwrite($this->socket, chr(0));
        }
    }
}
?>
