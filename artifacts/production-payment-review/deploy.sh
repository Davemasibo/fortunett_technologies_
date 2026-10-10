#!/bin/bash
set -euo pipefail
app=/var/www/html/fortunett_technologies_
release=/root/fortunett-payment-release-20261009
backup=/root/fortunett-payment-backup-$(date -u +%Y%m%dT%H%M%SZ)
mkdir -p "$release" "$backup"
chmod 700 "$release" "$backup"
tar -xzf /root/fortunett-payment-release.tar.gz -C "$release"
files=(includes/payment_notifications.php includes/auto_provision.php includes/payment_pipeline.php includes/stk_reconciliation.php cron/stk_poll.php cron/retry_provisions.php tools/audit_payment_delivery.php)
for file in "${files[@]}"; do php -l "$release/$file"; done
exec 8>/run/fortunett-stk.lock
flock -w 45 8
exec 9>/run/fortunett-provision.lock
flock -w 45 9
cd "$app"
tar -czf "$backup/original-files.tar.gz" includes/auto_provision.php includes/payment_pipeline.php includes/stk_reconciliation.php cron/stk_poll.php cron/retry_provisions.php tools/audit_payment_delivery.php
for file in "${files[@]}"; do
    install -o www-data -g www-data -m 644 "$release/$file" "$app/$file.payment-release"
    mv "$app/$file.payment-release" "$app/$file"
done
systemctl reload php8.3-fpm
echo "Backup: $backup/original-files.tar.gz"
sha256sum "${files[@]}"
