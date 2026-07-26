<?php
/**
 * Hotspot portal self-update.
 *
 * Instead of re-provisioning every router whenever the captive portal changes,
 * each router runs a small RouterOS script on a schedule. The script asks the
 * server for a fingerprint of the page it *would* be served, compares it with
 * the copy on flash, and only re-downloads when they differ.
 *
 * This is a pull, so it works for routers behind CGNAT/dynamic IPs that the
 * server can never reach — the exact routers a server-side push cannot help.
 *
 * Server side:  hotspot/login_version.php  → 12-char fingerprint
 *               hotspot/login_serve.php    → the full branded page
 * Router side:  /system script   "FortuNett-Portal-Sync"
 *               /system scheduler "FortuNett-Portal-Sync" (hourly + on startup)
 */

const HOTSPOT_SYNC_NAME = 'FortuNett-Portal-Sync';

/**
 * Resolve the portal URLs for a tenant.
 * Returns null when the tenant has no provisioning token (nothing to sync with).
 */
function hotspotPortalUrls(PDO $pdo, int $tenantId): ?array
{
    $st = $pdo->prepare("SELECT provisioning_token, subdomain FROM tenants WHERE id = ? LIMIT 1");
    $st->execute([$tenantId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row || empty($row['provisioning_token'])) {
        return null;
    }

    $platformDomain = 'fortunetttech.site';
    try {
        $pdSt = $pdo->query("SELECT setting_value FROM platform_settings WHERE setting_key='platform_domain' LIMIT 1");
        $pd = $pdSt ? $pdSt->fetchColumn() : null;
        if ($pd) $platformDomain = $pd;
    } catch (Throwable $_e) {}

    // Always build from the tenant subdomain rather than HTTP_HOST: this URL is
    // baked into a router-side script that must keep working long after the
    // request that created it, including when generated from cron.
    $sub  = $row['subdomain'] ?? '';
    $host = ($sub ? $sub . '.' : '') . $platformDomain;
    $base = 'https://' . $host;
    $tok  = rawurlencode($row['provisioning_token']);

    return [
        'host'    => $host,
        'base'    => $base,
        'token'   => $row['provisioning_token'],
        'page'    => $base . '/hotspot/login_serve.php?token='   . $tok,
        'version' => $base . '/hotspot/login_version.php?token=' . $tok,
    ];
}

/**
 * The RouterOS source for the sync script.
 *
 * Deliberately defensive: every fetch is wrapped in :do/on-error so a server
 * outage, expired cert or DNS hiccup logs a warning and retries next interval
 * instead of leaving a half-written login.html on flash.
 */
function hotspotSyncScriptBody(string $pageUrl, string $verUrl): string
{
    // RouterOS 7 reports html-directory as flash/flash/hotspot even when it was
    // set to flash/hotspot, so both are covered plus whatever the profiles
    // actually report at run time.
    return <<<ROS
:local VerUrl  "{$verUrl}"
:local PageUrl "{$pageUrl}"
:local VerFile "fortunett-portal.ver"
:local TmpFile "fortunett-portal.ver.new"

:local Dirs {"flash/hotspot";"flash/flash/hotspot"}
:foreach p in=[/ip hotspot profile find] do={
  :do {
    :local d [/ip hotspot profile get \$p html-directory]
    :if ([:len \$d] > 0 && [:typeof [:find \$Dirs \$d]] = "nil") do={ :set Dirs (\$Dirs , \$d) }
  } on-error={}
}

:if ([:len [/file find name=\$TmpFile]] > 0) do={ /file remove [find name=\$TmpFile] }

:local ok true
:do {
  /tool fetch url=\$VerUrl dst-path=\$TmpFile check-certificate=no
} on-error={ :set ok false ; :log warning "FortuNett portal sync: version check failed" }

:if (\$ok) do={
  :delay 2s
  :local new ""
  :local cur ""
  :if ([:len [/file find name=\$TmpFile]] > 0) do={ :set new [/file get [find name=\$TmpFile] contents] }
  :if ([:len [/file find name=\$VerFile]] > 0) do={ :set cur [/file get [find name=\$VerFile] contents] }

  :local have false
  :foreach d in=\$Dirs do={
    :if ([:len [/file find name=(\$d . "/login.html")]] > 0) do={ :set have true }
  }

  :if ([:len \$new] = 12 && (\$new != \$cur || \$have = false)) do={
    :log info ("FortuNett portal sync: new build " . \$new . " - downloading")
    :local wrote false
    :foreach d in=\$Dirs do={
      :do {
        /tool fetch url=\$PageUrl dst-path=(\$d . "/login.html") check-certificate=no
        :set wrote true
      } on-error={}
    }
    :if (\$wrote) do={
      :if ([:len [/file find name=\$VerFile]] > 0) do={ /file remove [find name=\$VerFile] }
      :do { /file set [find name=\$TmpFile] name=\$VerFile } on-error={}
      :log info "FortuNett portal sync: login page updated"
    } else={
      :log warning "FortuNett portal sync: page download failed - keeping old page"
      :if ([:len [/file find name=\$TmpFile]] > 0) do={ /file remove [find name=\$TmpFile] }
    }
  } else={
    :if ([:len [/file find name=\$TmpFile]] > 0) do={ /file remove [find name=\$TmpFile] }
  }
}
ROS;
}

/**
 * Create or refresh the sync script + scheduler on a router via the API.
 *
 * Idempotent: an existing entry has its source/interval overwritten so an
 * updated script body reaches routers that already had an older one.
 *
 * @param object $api Connected MikrotikAPI instance
 * @return array{installed:bool,message:string}
 */
function installHotspotSyncScheduler($api, string $pageUrl, string $verUrl, string $interval = '1h'): array
{
    $source = hotspotSyncScriptBody($pageUrl, $verUrl);

    try {
        // ── /system/script ────────────────────────────────────────────────────
        $scriptId = null;
        foreach ($api->comm('/system/script/print') as $s) {
            if (($s['name'] ?? '') === HOTSPOT_SYNC_NAME) { $scriptId = $s['.id'] ?? null; break; }
        }
        if ($scriptId) {
            $api->comm('/system/script/set', [
                '=.id='     . $scriptId,
                '=source='  . $source,
                '=policy=read,write,test,policy,ftp',
            ]);
        } else {
            $api->comm('/system/script/add', [
                '=name='    . HOTSPOT_SYNC_NAME,
                '=source='  . $source,
                '=policy=read,write,test,policy,ftp',
                '=comment=Pulls the branded hotspot login page when it changes',
            ]);
        }

        // ── /system/scheduler ─────────────────────────────────────────────────
        // start-time=startup also fires ~3 min after every reboot, so a router
        // that was offline during a portal change catches up as soon as it boots.
        $schedId = null;
        foreach ($api->comm('/system/scheduler/print') as $s) {
            if (($s['name'] ?? '') === HOTSPOT_SYNC_NAME) { $schedId = $s['.id'] ?? null; break; }
        }
        $onEvent = '/system script run ' . HOTSPOT_SYNC_NAME;
        if ($schedId) {
            $api->comm('/system/scheduler/set', [
                '=.id='         . $schedId,
                '=interval='    . $interval,
                '=on-event='    . $onEvent,
                '=start-time=startup',
                '=policy=read,write,test,policy,ftp',
                '=disabled=no',
            ]);
        } else {
            $api->comm('/system/scheduler/add', [
                '=name='        . HOTSPOT_SYNC_NAME,
                '=interval='    . $interval,
                '=on-event='    . $onEvent,
                '=start-time=startup',
                '=policy=read,write,test,policy,ftp',
                '=comment=Keeps the hotspot login page in sync with the portal',
            ]);
        }

        return ['installed' => true, 'message' => 'Sync scheduler installed (' . $interval . ')'];

    } catch (Throwable $e) {
        error_log('installHotspotSyncScheduler: ' . $e->getMessage());
        return ['installed' => false, 'message' => $e->getMessage()];
    }
}

/**
 * The same thing as a paste-able .rsc block, for routers the API can't reach
 * (no port forward, VPN down, API disabled). Uses source={...} so the operator
 * never has to escape quotes by hand.
 */
function hotspotSyncInstallerRsc(string $pageUrl, string $verUrl, string $interval = '1h'): string
{
    $name = HOTSPOT_SYNC_NAME;
    $body = hotspotSyncScriptBody($pageUrl, $verUrl);

    return <<<RSC
# ── FortuNett hotspot portal auto-sync ────────────────────────────────────────
# Paste this whole block into the router's terminal (WinBox → New Terminal, or SSH).
# After this runs, the router checks for portal updates every {$interval} and on every
# reboot, and downloads a new login page only when one is actually published.
# Re-pasting is safe — it replaces the previous copy.

/system scheduler remove [find name="{$name}"]
/system script remove [find name="{$name}"]

/system script add name="{$name}" policy=read,write,test,policy,ftp \\
  comment="Pulls the branded hotspot login page when it changes" source={
{$body}
}

/system scheduler add name="{$name}" interval={$interval} start-time=startup \\
  policy=read,write,test,policy,ftp \\
  comment="Keeps the hotspot login page in sync with the portal" \\
  on-event="/system script run {$name}"

# Run it once now so the current page lands immediately
/system script run "{$name}"
:put "FortuNett portal sync installed. Check /log print for progress."
RSC;
}
