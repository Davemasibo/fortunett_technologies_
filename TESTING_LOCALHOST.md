# Quick Testing Guide - Localhost

## ✅ Your Account is Verified!

You can now login at: `http://localhost/fortunett_technologies_/login.php`

---

## Testing Checklist

### 1. Login Test
- [ ] Go to `http://localhost/fortunett_technologies_/login.php`
- [ ] Login with your credentials
- [ ] Verify you can access the dashboard

### 2. Check if Tenant Was Created
Run this to see your tenant details:
```bash
php -r "require 'includes/db_master.php'; \$stmt = \$pdo->query('SELECT * FROM tenants'); print_r(\$stmt->fetchAll());"
```

### 3. Test Account Number Generation
1. Navigate to Clients section
2. Click "Add New Client"
3. Fill in client details:
   - Name: Test Customer
   - Email: test@example.com
   - Phone: +254712345678
4. Save and verify account number is generated (e.g., t001, t002, etc.)

### 4. Test Payment Gateway (Manual)
Run this to add a test payment gateway:
```bash
cd c:\xampp\htdocs\fortunett_technologies_
php test_payment_gateway.php
```

### 5. Verify Database Schema
Check that all tables exist:
```bash
php -r "require 'includes/db_master.php'; \$tables = ['tenants', 'payment_gateways', 'router_services', 'tenant_settings']; foreach(\$tables as \$t) { \$r = \$pdo->query(\"SHOW TABLES LIKE '\$t'\"); echo \$t . ': ' . (\$r->rowCount() > 0 ? '✓ EXISTS' : '✗ MISSING') . PHP_EOL; }"
```

---

## What to Look For

### 1. Dashboard Changes
- Check if tenant information is displayed
- View your provisioning token (for MikroTik auto-registration)

### 2. Client Management
- When you add a client, account number should auto-generate
- Account numbers should follow pattern: [first letter of username]001, 002, etc.
- Example: If username is "dave", clients get: d001, d002, d003...

### 3. Payment Gateway (Settings Page)
The settings page may need UI updates. For now, test via API:

**Test saving a payment gateway:**
```php
<?php
session_start();
$_SESSION['user_id'] = 1; // Your user ID
$_SESSION['tenant_id'] = 1; // Your tenant ID

$_POST['gateway_type'] = 'paybill_no_api';
$_POST['gateway_name'] = 'My Paybill';
$_POST['paybill_number'] = '123456';
$_POST['account_number'] = 'MyAccount';
$_POST['is_default'] = '1';

include 'api/payment_gateways/save.php';
?>
```

---

## Next: Production Deployment

Once you verify everything works locally, follow **LOCALHOST_TO_PRODUCTION.md** for deployment steps.

### Quick Production Checklist:
1. ✅ Database backup on VPS
2. ✅ Upload files to VPS
3. ✅ Run migration on VPS
4. ✅ Configure DNS (wildcard *.fortunetttech.site)
5. ✅ Setup SSL certificate
6. ✅ Configure Apache virtual host
7. ✅ Test on production

---

## Troubleshooting

**Can't login after verification?**
- Clear browser cache/cookies
- Try incognito mode
- Check user is verified:
```bash
php -r "require 'includes/db_master.php'; \$stmt = \$pdo->query('SELECT username,email,email_verified FROM users'); print_r(\$stmt->fetchAll());"
```

**Account numbers not generating?**
- Check if tenant_id is set in session
- Check if users table has account_prefix
- Run: `php migrate_existing_users.php` to create tenant for your user

**Need to reset and start over?**
```bash
# Drop and recreate tables (WARNING: DELETES DATA)
php -r "require 'includes/db_master.php'; \$pdo->exec('DROP TABLE IF EXISTS tenants, payment_gateways, router_services, tenant_settings');"
php run_simple_migration.php
```
