#!/bin/bash
set -euo pipefail
app=/var/www/html/fortunett_technologies_
backup=/root/fortunett-sms-template-backup-$(date -u +%Y%m%dT%H%M%SZ)
mkdir -m 700 "$backup"
cd "$app"
tar -czf "$backup/source-before.tar.gz" classes/SMSHelper.php includes/sms_templates.php includes/payment_pipeline.php
exec 8>/run/fortunett-stk.lock
flock -w 45 8
exec 9>/run/fortunett-provision.lock
flock -w 45 9
git pull --ff-only
for file in classes/SMSHelper.php includes/sms_templates.php includes/payment_pipeline.php; do php -l "$file"; done
systemctl reload php8.3-fpm
php tools/test_sms_templates.php
php tools/test_sms_communication.php
php tools/test_sms_pppoe_privacy.php
php tools/test_customer_sms.php
php tools/test_payment_notifications_mysql.php
printf 'Rollback source backup: %s\n' "$backup"
git status --short
