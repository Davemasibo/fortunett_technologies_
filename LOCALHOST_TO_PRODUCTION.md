# Localhost to Production Deployment Guide

## Complete Step-by-Step Guide to Deploy Multi-Tenant Changes

---

## Part 1: Testing on Localhost (CURRENT STEP)

### Step 1.1: Verify Your Account

Since you can't receive emails on localhost, manually verify your account:

```bash
cd c:\xampp\htdocs\fortunett_technologies_
php verify_account_manual.php
```

This will verify your account and show your login credentials.

### Step 1.2: Login and Test Features

**Login at:** `http://localhost/fortunett_technologies_/login.php`

Use the username/email and password you created during signup.

### Step 1.3: Run Database Migrations (If Not Done)

```bash
cd c:\xampp\htdocs\fortunett_technologies_
php run_multi_tenant_migration.php
```

This ensures all multi-tenant tables exist.

### Step 1.4: Test Core Features Locally

**✅ Test Account Number Generation:**
1. Navigate to Clients section
2. Add a new client
3. Verify account number is auto-generated (e.g., e001, e002)

**✅ Test Payment Gateway (API):**
```bash
# Test the save endpoint
curl -X POST http://localhost/fortunett_technologies_/api/payment_gateways/save.php \
  -H "Content-Type: application/x-www-form-urlencoded" \
  -d "gateway_type=paybill_no_api&gateway_name=My Paybill&paybill_number=123456&account_number=MyAccount&is_default=1"
```

**✅ Check Tables Were Created:**
```bash
# Connect to MySQL
mysql -u root -p fortunett_technologies

# Check tables
SHOW TABLES LIKE 'tenants';
SHOW TABLES LIKE 'payment_gateways';
SHOW TABLES LIKE 'router_services';

# Check your tenant
SELECT * FROM tenants;

# Check users have tenant_id
SELECT id, username, email, tenant_id FROM users;
```

---

## Part 2: Prepare for Production Deployment

### Step 2.1: Create Database Backup

**On your VPS (72.61.147.86):**

```bash
# SSH into VPS
ssh root@72.61.147.86

# Create backup directory
mkdir -p /root/backups

# Backup current database
mysqldump -u root -p fortunett_technologies > /root/backups/fortunett_backup_$(date +%Y%m%d_%H%M%S).sql

# Verify backup was created
ls -lh /root/backups/
```

### Step 2.2: Prepare Files for Upload

**On your local machine:**

Create a deployment package with only the new/modified files:

```bash
cd c:\xampp\htdocs\fortunett_technologies_

# Create deployment directory
mkdir deployment_package
cd deployment_package

# Copy new files
mkdir -p includes api/tenants api/payment_gateways api/mikrotik sql/migrations

# Copy all new PHP classes
copy ..\includes\tenant.php includes\
copy ..\includes\account_number_generator.php includes\
copy ..\includes\payment_gateway.php includes\

# Copy updated clients API
copy ..\api\clients.php api\

# Copy new API endpoints
copy ..\api\tenants\check_subdomain.php api\tenants\
copy ..\api\routers\auto_register.php api\routers\
copy ..\api\payment_gateways\save.php api\payment_gateways\
copy ..\api\payment_gateways\list.php api\payment_gateways\

# Copy MikroTik deployment APIs
copy ..\api\mikrotik\deploy_pppoe.php api\mikrotik\
copy ..\api\mikrotik\deploy_hotspot.php api\mikrotik\

# Copy migration scripts
copy ..\sql\migrations\multi_tenant_schema.sql sql\migrations\
copy ..\run_multi_tenant_migration.php .
copy ..\migrate_existing_users.php .
copy ..\provisioning_script_template.rsc .
```

### Step 2.3: Upload to VPS

**Option A: Using SCP (Recommended)**

```bash
# From deployment_package directory
scp -r * root@72.61.147.86:/var/www/html/fortunett_technologies_/
```

**Option B: Using WinSCP or FileZilla**
- Connect to 72.61.147.86
- Navigate to `/var/www/html/fortunett_technologies_/`
- Upload all files from `deployment_package`

---

## Part 3: Production Database Migration

### Step 3.1: Connect to VPS

```bash
ssh root@72.61.147.86
cd /var/www/html/fortunett_technologies_
```

### Step 3.2: Verify Database Connection

Edit database credentials if needed:

```bash
nano includes/db_master.php
```

Ensure credentials match your production database:
```php
$DB_HOST = 'localhost';
$DB_NAME = 'fortunett_technologies';
$DB_USER = 'root'; // or your DB user
$DB_PASS = 'your_password';
```

### Step 3.3: Run Production Migration

```bash
cd /var/www/html/fortunett_technologies_
php run_multi_tenant_migration.php
```

**Expected Output:**
```
===================================
Multi-Tenant Schema Migration
===================================

Reading migration file...
Starting migration...

✓ Executed on table: tenants
✓ Executed on table: payment_gateways
✓ Executed on table: router_services
...

Migration Complete!
```

### Step 3.4: Migrate Existing Users (If Any)

```bash
php migrate_existing_users.php
```

This converts your existing admin users to tenants.

### Step 3.5: Verify Tables Were Created

```bash
mysql -u root -p fortunett_technologies

# Check new tables
SHOW TABLES;

# Should see:
# - tenants
# - payment_gateways
# - router_services
# - tenant_settings

# Check new columns
DESCRIBE users;
DESCRIBE clients;
DESCRIBE mikrotik_routers;

# Exit MySQL
EXIT;
```

---

## Part 4: DNS & SSL Configuration

### Step 4.1: Configure Wildcard DNS

**In your DNS provider (where fortunetttech.site is hosted):**

Add these DNS records:

| Type | Name | Value | TTL |
|------|------|-------|-----|
| A | @ | 72.61.147.86 | 3600 |
| A | * | 72.61.147.86 | 3600 |

**Verify DNS propagation (wait 5-30 minutes):**
```bash
nslookup fortunetttech.site
nslookup test.fortunetttech.site
```

Both should resolve to `72.61.147.86`

### Step 4.2: Install SSL Certificate

**On VPS:**

```bash
# Install certbot
sudo apt-get update
sudo apt-get install certbot python3-certbot-apache -y

# Get wildcard certificate
sudo certbot certonly --manual --preferred-challenges dns \
  -d "fortunetttech.site" \
  -d "*.fortunetttech.site"
```

**Follow the prompts:**
1. Certbot will ask you to add a TXT record to your DNS
2. Add the TXT record in your DNS provider
3. Wait 2-3 minutes for DNS propagation
4. Press Enter to continue
5. Certificate will be saved to `/etc/letsencrypt/live/fortunetttech.site/`

### Step 4.3: Configure Apache Virtual Host

**Create Apache configuration:**

```bash
sudo nano /etc/apache2/sites-available/fortunett.conf
```

**Paste this configuration:**

```apache
<VirtualHost *:80>
    ServerName fortunetttech.site
    ServerAlias *.fortunetttech.site
    
    DocumentRoot /var/www/html/fortunett_technologies_
    
    <Directory /var/www/html/fortunett_technologies_>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>
    
    ErrorLog ${APACHE_LOG_DIR}/fortunett_error.log
    CustomLog ${APACHE_LOG_DIR}/fortunett_access.log combined
    
    # Redirect HTTP to HTTPS
    RewriteEngine On
    RewriteCond %{HTTPS} off
    RewriteRule ^(.*)$ https://%{HTTP_HOST}$1 [R=301,L]
</VirtualHost>

<VirtualHost *:443>
    ServerName fortunetttech.site
    ServerAlias *.fortunetttech.site
    
    DocumentRoot /var/www/html/fortunett_technologies_
    
    SSLEngine on
    SSLCertificateFile /etc/letsencrypt/live/fortunetttech.site/fullchain.pem
    SSLCertificateKeyFile /etc/letsencrypt/live/fortunetttech.site/privkey.pem
    Include /etc/letsencrypt/options-ssl-apache.conf
    
    <Directory /var/www/html/fortunett_technologies_>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>
    
    ErrorLog ${APACHE_LOG_DIR}/fortunett_ssl_error.log
    CustomLog ${APACHE_LOG_DIR}/fortunett_ssl_access.log combined
</VirtualHost>
```

**Enable site and modules:**

```bash
# Enable required modules
sudo a2enmod rewrite
sudo a2enmod ssl
sudo a2enmod headers

# Enable site
sudo a2ensite fortunett.conf

# Test configuration
sudo apache2ctl configtest

# Should output: Syntax OK

# Restart Apache
sudo systemctl restart apache2

# Check Apache status
sudo systemctl status apache2
```

### Step 4.4: Set File Permissions

```bash
cd /var/www/html/fortunett_technologies_

# Set ownership
sudo chown -R www-data:www-data .

# Set directory permissions
sudo find . -type d -exec chmod 755 {} \;

# Set file permissions
sudo find . -type f -exec chmod 644 {} \;

# Make config directory writable (for encryption key)
sudo chmod 777 config/
sudo chmod 777 logs/

# If uploads directory exists
sudo chmod 777 uploads/
```

---

## Part 5: Testing on Production

### Step 5.1: Test Main Domain

**Open browser:**
```
https://fortunetttech.site
```

Should load your application with SSL (green padlock).

### Step 5.2: Test Signup Flow

1. Go to `https://fortunetttech.site/signup.php`
2. Create a new account (e.g., username: "testuser")
3. Note the subdomain created
4. Manually verify (since email won't work yet):

```bash
# On VPS
cd /var/www/html/fortunett_technologies_

# Get verification token
mysql -u root -p fortunett_technologies -e "SELECT id, username, verification_token FROM users WHERE username='testuser';"

# Copy the token and create verification script
nano verify_user.php
```

Add:
```php
<?php
require_once 'includes/db_master.php';
$token = "PASTE_TOKEN_HERE";
$stmt = $pdo->prepare("UPDATE users SET email_verified = 1 WHERE verification_token = ?");
$stmt->execute([$token]);
echo "User verified!";
?>
```

Run:
```bash
php verify_user.php
```

### Step 5.3: Test Subdomain Access

Login at `https://testuser.fortunetttech.site`

(Replace "testuser" with your actual subdomain)

### Step 5.4: Test Account Number Generation

1. Login to admin dashboard
2. Navigate to Clients
3. Add a new client
4. Verify account number is generated (e.g., t001, t002)

### Step 5.5: Test Payment Gateway

1. Go to Settings → Payments
2. Add a Paybill gateway
3. Enter paybill number
4. Save
5. Verify it appears in the list

### Step 5.6: Test MikroTik Auto-Registration

**Get your provisioning token:**
```bash
mysql -u root -p fortunett_technologies -e "SELECT subdomain, provisioning_token FROM tenants;"
```

**Generate provisioning script:**

Copy the token and create a MikroTik script:

```mikrotik
:local Token "YOUR_PROVISIONING_TOKEN_HERE"
:local ApiUrl "https://fortunetttech.site/api/routers/auto_register.php"
:local RouterIP [/ip address get [find interface=ether1] address]
:local RouterMAC [/interface ethernet get ether1 mac-address]
:local RouterIdentity [/system identity get name]

:log info "Registering router with ISP system..."
/tool fetch url=$ApiUrl mode=https http-method=post http-data="provisioning_token=$Token&router_ip=$RouterIP&router_mac=$RouterMAC&router_identity=$RouterIdentity&router_username=admin&router_password=yourpassword" keep-result=no
:log info "Router registration complete"
```

Run on your MikroTik router and check if it appears in the admin dashboard.

---

## Part 6: Post-Deployment Verification

### Step 6.1: Check Logs

```bash
# Apache error logs
sudo tail -f /var/log/apache2/fortunett_error.log

# SSL error logs
sudo tail -f /var/log/apache2/fortunett_ssl_error.log
```

### Step 6.2: Test All API Endpoints

```bash
# Test subdomain check
curl -X POST https://fortunetttech.site/api/tenants/check_subdomain.php \
  -d "subdomain=test123"

# Should return JSON with availability status

# Test payment gateway list (requires auth cookie)
curl https://fortunetttech.site/api/payment_gateways/list.php
```

### Step 6.3: Database Health Check

```bash
mysql -u root -p fortunett_technologies

-- Check tenant count
SELECT COUNT(*) FROM tenants;

-- Check users have tenant_id
SELECT username, tenant_id, email_verified FROM users;

-- Check clients have account numbers
SELECT id, full_name, account_number, tenant_id FROM clients ORDER BY id DESC LIMIT 10;

-- Check payment gateways
SELECT id, tenant_id, gateway_type, gateway_name, is_active FROM payment_gateways;

EXIT;
```

---

## Part 7: Troubleshooting Common Issues

### Issue 1: Subdomain Shows 404

**Cause:** Apache not configured for wildcard subdomains

**Fix:**
```bash
# Check Apache virtual host
sudo apache2ctl -S

# Should show *.fortunetttech.site

# If not, edit virtual host
sudo nano /etc/apache2/sites-available/fortunett.conf

# Ensure ServerAlias *.fortunetttech.site is there
# Restart Apache
sudo systemctl restart apache2
```

### Issue 2: SSL Certificate Error

**Cause:** Certificate doesn't cover wildcard

**Fix:**
```bash
# Check certificate
sudo certbot certificates

# Should show both fortunetttech.site and *.fortunetttech.site

# If not, regenerate
sudo certbot delete --cert-name fortunetttech.site
sudo certbot certonly --manual --preferred-challenges dns -d "fortunetttech.site" -d "*.fortunetttech.site"
```

### Issue 3: Account Numbers Not Generating

**Cause:** Session doesn't have tenant_id

**Fix:**
```bash
# Check if users table was updated
mysql -u root -p fortunett_technologies -e "DESCRIBE users;"

# tenant_id column should exist

# Check if user has tenant_id
mysql -u root -p fortunett_technologies -e "SELECT id, username, tenant_id FROM users;"

# If NULL, run user migration
php migrate_existing_users.php
```

### Issue 4: Payment Gateway Save Fails

**Cause:** Encryption key not generated

**Fix:**
```bash
cd /var/www/html/fortunett_technologies_

# Check if encryption key exists
ls -la config/encryption.key

# If not, make config writable
sudo chmod 777 config/

# Try saving payment gateway again (it will create the key)
```

### Issue 5: Router Auto-Registration Fails

**Cause:** API endpoint not accessible or token invalid

**Fix:**
```bash
# Test endpoint directly
curl https://fortunetttech.site/api/routers/auto_register.php

# Should return error about missing parameters, not 404

# Check provisioning token
mysql -u root -p fortunett_technologies -e "SELECT subdomain, provisioning_token FROM tenants;"

# Verify MikroTik can reach VPS
# From MikroTik terminal:
/tool fetch url="https://fortunetttech.site" mode=https
```

---

## Summary Checklist

### ✅ Localhost Testing
- [ ] Account verified manually
- [ ] Can login successfully
- [ ] Account numbers auto-generate on client creation
- [ ] Database migrations completed

### ✅ Production Deployment
- [ ] Database backup created
- [ ] Files uploaded to VPS
- [ ] Database migrations run on production
- [ ] DNS configured (wildcard A record)
- [ ] SSL certificate installed
- [ ] Apache virtual host configured
- [ ] File permissions set correctly

### ✅ Production Testing
- [ ] Main domain loads with SSL
- [ ] Can signup new account
- [ ] Subdomain routing works
- [ ] Account numbers generate
- [ ] Payment gateway saves successfully
- [ ] Router auto-registration works (if testing with MikroTik)
- [ ] Tenant data isolation verified

---

## Quick Command Reference

**Localhost:**
```bash
cd c:\xampp\htdocs\fortunett_technologies_
php verify_account_manual.php
php run_multi_tenant_migration.php
```

**Production (VPS):**
```bash
ssh root@72.61.147.86
cd /var/www/html/fortunett_technologies_
php run_multi_tenant_migration.php
php migrate_existing_users.php
sudo systemctl restart apache2
```

**MySQL Checks:**
```sql
SELECT * FROM tenants;
SELECT username, tenant_id FROM users;
SELECT id, account_number FROM clients ORDER BY id DESC LIMIT 10;
```

You're all set! 🚀
