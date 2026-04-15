<?php
/**
 * WireGuardManager
 *
 * Manages the VPS-side WireGuard server (wg0) for router VPN tunnels.
 * Each MikroTik router behind NAT connects OUT to the VPS on UDP 51820.
 * The VPS can then reach every router via its assigned VPN IP (10.200.200.x).
 *
 * Requirements on the VPS:
 *   apt install wireguard
 *   # wg0 interface must be running (see scripts/setup_wireguard_server.sh)
 *   # PHP must be able to run: wg, wg-quick  (via sudo or cap_net_admin)
 */
class WireGuardManager
{
    const WG_INTERFACE = 'wg0';
    const WG_PORT      = 51820;
    const VPN_SUBNET   = '10.200.200';
    const VPS_VPN_IP   = '10.200.200.1';

    // ── Key generation ─────────────────────────────────────────────────────────

    /**
     * Generate a WireGuard key pair using the wg CLI tool.
     * Returns ['private' => '...', 'public' => '...']
     */
    public static function generateKeyPair(): array
    {
        // wg genkey/pubkey don't touch the kernel interface — no sudo needed
        $private = trim(shell_exec('wg genkey 2>/dev/null') ?: '');
        if (empty($private)) {
            throw new \RuntimeException(
                'wg command unavailable. Install: apt install wireguard-tools'
            );
        }
        $public = trim(shell_exec('echo ' . escapeshellarg($private) . ' | wg pubkey 2>/dev/null') ?: '');
        if (empty($public)) {
            throw new \RuntimeException('Failed to derive WireGuard public key');
        }
        return ['private' => $private, 'public' => $public];
    }

    // ── VPN IP assignment ──────────────────────────────────────────────────────

    /**
     * Compute the deterministic VPN IP for a router.
     * router_id = 7  →  10.200.200.7
     * Supports up to 253 routers per VPS (IDs 2–254).
     */
    public static function vpnIp(int $routerId): string
    {
        if ($routerId < 2 || $routerId > 254) {
            throw new \RuntimeException("Router ID $routerId out of VPN range (2–254)");
        }
        return self::VPN_SUBNET . '.' . $routerId;
    }

    // ── VPS peer management ────────────────────────────────────────────────────

    /**
     * Get the VPS WireGuard public key from the running wg0 interface.
     */
    public static function getVpsPublicKey(): string
    {
        $key = trim(shell_exec('sudo wg show ' . escapeshellarg(self::WG_INTERFACE) . ' public-key 2>/dev/null') ?: '');
        if (empty($key)) {
            throw new \RuntimeException(
                'WireGuard interface ' . self::WG_INTERFACE . ' is not running. ' .
                'Run: systemctl start wg-quick@wg0'
            );
        }
        return $key;
    }

    /**
     * Add (or update) a router peer to the running wg0 instance and persist to config file.
     */
    public static function addPeer(string $publicKey, string $vpnIp): void
    {
        $allowed = $vpnIp . '/32';
        // Add to running instance
        $cmd = sprintf(
            'sudo wg set %s peer %s allowed-ips %s persistent-keepalive 25 2>&1',
            escapeshellarg(self::WG_INTERFACE),
            escapeshellarg($publicKey),
            escapeshellarg($allowed)
        );
        $out = shell_exec($cmd);
        if ($out) error_log('[WireGuard] addPeer: ' . trim($out));

        // Persist — write peer block directly to config file so it survives reboot
        $peerConf = "\n[Peer]\n# added by Fortunett\nPublicKey = $publicKey\nAllowedIPs = $allowed\nPersistentKeepalive = 25\n";
        $confPath = '/etc/wireguard/' . self::WG_INTERFACE . '.conf';
        // Only append if not already present
        $existing = file_get_contents($confPath) ?: '';
        if (strpos($existing, $publicKey) === false) {
            file_put_contents($confPath, $peerConf, FILE_APPEND | LOCK_EX);
        }
    }

    /**
     * Remove a router peer from the running wg0 instance and config file.
     */
    public static function removePeer(string $publicKey): void
    {
        shell_exec(sprintf(
            'sudo wg set %s peer %s remove 2>/dev/null',
            escapeshellarg(self::WG_INTERFACE),
            escapeshellarg($publicKey)
        ));
        // Remove from config file
        $confPath = '/etc/wireguard/' . self::WG_INTERFACE . '.conf';
        $conf = file_get_contents($confPath) ?: '';
        // Strip the [Peer] block containing this public key
        $conf = preg_replace(
            '/\n\[Peer\][^\[]*' . preg_quote($publicKey, '/') . '[^\[]*/s',
            '',
            $conf
        );
        file_put_contents($confPath, $conf, LOCK_EX);
    }

    /**
     * Check whether WireGuard is available and wg0 is running.
     */
    public static function isAvailable(): bool
    {
        try {
            self::getVpsPublicKey();
            return true;
        } catch (\RuntimeException $e) {
            return false;
        }
    }
}
