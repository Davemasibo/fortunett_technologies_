#!/bin/bash
set -euo pipefail
cd /var/www/html/fortunett_technologies_
files=(api/diagnostics/automation_chain.php api/mikrotik/coexistence_check.php api/mikrotik/hotspot_status.php diag_router.php hotspot_diagnostics.php super_admin/diagnostics.php api/mikrotik/repair_hotspot_portal.php assets/diagnostics.css includes/hotspot_diagnostic_snapshot.php)
backup=/root/fortunett-git-sync-$(date -u +%Y%m%dT%H%M%SZ)
mkdir -m 700 "$backup"
tar -czf "$backup/deployed-diagnostics.tar.gz" "${files[@]}"
sha256sum "${files[@]}" > "$backup/deployed-checksums.txt"
git stash push --include-untracked -m 'Preserve deployed hotspot diagnostics before user-requested git pull' -- "${files[@]}"
git pull --ff-only
sha256sum -c "$backup/deployed-checksums.txt" || true
printf 'Preserved deployed files: %s\n' "$backup"
git status --short
