#!/bin/bash
set -euo pipefail
cd /var/www/html/fortunett_technologies_
backup=/root/ecoland-redirect-deploy-$(date -u +%Y%m%dT%H%M%SZ)
mkdir -m 700 "$backup"
tar -czf "$backup/source-before.tar.gz" includes/auto_provision.php includes/hotspot_sync.php includes/hotspot_diagnostic_snapshot.php
exec 8>/run/fortunett-stk.lock
flock -w 45 8
exec 9>/run/fortunett-provision.lock
flock -w 45 9
git pull --ff-only
php -l includes/auto_provision.php
php -l includes/hotspot_sync.php
php -l includes/hotspot_diagnostic_snapshot.php
php tools/test_hotspot_catalog_sync.php
php tools/test_hotspot_diagnostics.php
systemctl reload php8.3-fpm
printf 'Source backup: %s\n' "$backup"
