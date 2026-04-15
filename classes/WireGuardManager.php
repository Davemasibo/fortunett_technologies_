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
        $private = trim(shell_exec('wg genkey 2>/dev/null') ?: '');
        if (empty($private)) {
            throw new \RuntimeException(
                'wg command unavailable. Install wireguard-tools: apt install wireguard'
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
        $key = trim(shell_exec('wg show ' . escapeshellarg(self::WG_INTERFACE) . ' public-key 2>/dev/null') ?: '');
        if (empty($key)) {
            throw new \RuntimeException(
                'WireGuard interface ' . self::WG_INTERFACE . ' is not running. ' .
                'Run: systemctl start wg-quick@wg0'
            );
        }
        return $key;
    }

    /**
     * Add (or update) a router peer to the running wg0 instance and persist config.
     */
    public static function addPeer(string $publicKey, string $vpnIp): void
    {
        $allowed = $vpnIp . '/32';
        $cmd = sprintf(
            'wg set %s peer %s allowed-ips %s persistent-keepalive 25 2>&1',
            escapeshellarg(self::WG_INTERFACE),
            escapeshellarg($publicKey),
            escapeshellarg($allowed)
        );
        $out = shell_exec($cmd);
        if ($out) {
            error_log('[WireGuard] addPeer: ' . trim($out));
        }
        // Persist to /etc/wireguard/wg0.conf so it survives restarts
        shell_exec('wg-quick save ' . escapeshellarg(self::WG_INTERFACE) . ' 2>/dev/null');
    }

    /**
     * Remove a router peer from the running wg0 instance and persist config.
     */
    public static function removePeer(string $publicKey): void
    {
        shell_exec(sprintf(
            'wg set %s peer %s remove 2>/dev/null',
            escapeshellarg(self::WG_INTERFACE),
            escapeshellarg($publicKey)
        ));
        shell_exec('wg-quick save ' . escapeshellarg(self::WG_INTERFACE) . ' 2>/dev/null');
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
