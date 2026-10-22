#!/bin/bash
set -euo pipefail
app=/var/www/html/fortunett_technologies_
release=/tmp/billing-payout-release
backup=/root/billing-payout-backup-$(date -u +%Y%m%dT%H%M%SZ)
mkdir -m 700 "$backup"
files=(billing.php includes/tenant_payouts.php includes/payouts.php includes/payment_resets.php includes/payment_pipeline.php includes/stk_reconciliation.php api/tenant_payout.php api/payment/b2c_result.php cron/disburse_payouts.php tools/test_tenant_withdrawals.php)
existing=()
for file in "${files[@]}"; do
    php -l "$release/$file"
    if [[ -f "$app/$file" ]]; then existing+=("$file"); else echo "$file" >> "$backup/new-files.txt"; fi
done
cd "$app"
tar -czf "$backup/original-files.tar.gz" "${existing[@]}"
for file in "${files[@]}"; do
    install -o www-data -g www-data -m 644 "$release/$file" "$app/$file.billing-release"
    mv "$app/$file.billing-release" "$app/$file"
done
systemctl reload php8.3-fpm
echo "Backup: $backup"
