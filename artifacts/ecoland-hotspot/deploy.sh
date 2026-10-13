#!/bin/bash
set -euo pipefail
app=/var/www/html/fortunett_technologies_
release=/root/ecoland-hotspot-release
backup=/root/ecoland-hotspot-backup-$(date -u +%Y%m%dT%H%M%SZ)
mkdir -p "$release" "$backup"
chmod 700 "$release" "$backup"
tar -xzf /root/ecoland-hotspot-release.tar.gz -C "$release"
files=(hotspot_diagnostics.php api/diagnostics/automation_chain.php api/mikrotik/hotspot_status.php api/mikrotik/coexistence_check.php api/mikrotik/repair_hotspot_portal.php includes/hotspot_diagnostic_snapshot.php assets/diagnostics.css super_admin/diagnostics.php diag_router.php)
for file in "${files[@]}"; do if [[ "$file" == *.php ]]; then php -l "$release/$file"; fi; done
cd "$app"
existing=()
for file in "${files[@]}"; do if [[ -f "$file" ]]; then existing+=("$file"); else echo "$file" >> "$backup/new-files.txt"; fi; done
tar -czf "$backup/original-files.tar.gz" "${existing[@]}"
for file in "${files[@]}"; do install -o www-data -g www-data -m 644 "$release/$file" "$app/$file.ecoland-release"; mv "$app/$file.ecoland-release" "$app/$file"; done
systemctl reload php8.3-fpm
printf 'Rollback backup: %s\n' "$backup"
sha256sum "${files[@]}"
