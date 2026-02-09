# Multi-Tenant ISP Management - Deployment Guide

## Overview

This guide will help you deploy the multi-tenant ISP management system to your VPS at `72.61.147.86`.

## Prerequisites

- VPS with root access (72.61.147.86)
- Domain: fortunetttech.site
- PHP 7.4+ with PDO MySQL extension
- MySQL/MariaDB database
- Apache or Nginx web server

## Step-by-Step Deployment

### 1. Database Migration

**On your local machine first (for testing):**

```bash
cd c:\xampp\htdocs\fortunett_technologies_
php run_multi_tenant_migration.php
```

This creates all new tables and columns. Review the output for any errors.

**Migrate existing users to tenants:**

```bash
php migrate_existing_users.php
```

This converts your existing admin users into tenants with their own subdomains.

### 2. DNS Configuration

**Add wildcard subdomain to your DNS provider:**

1. Log into your DNS provider (where fortunetttech.site is hosted)
2. Add an A record:
   - **Name**: `*`
   - **Type**: A
   - **Value**: `72.61.147.86`
   - **TTL**: 3600 (or default)

3. Add the main domain A record if not already there:
   - **Name**: `@` or leave blank
   - **Type**: A
   - **Value**: `72.61.147.86`

**Verify DNS propagation** (wait 5-30 minutes):

```bash
nslookup test.fortunetttech.site
# Should resolve to 72.61.147.86
```

### 3. SSL Certificate Setup (Let's Encrypt)

**On the VPS:**

```bash
# Install certbot
sudo apt-get update
sudo apt-get install certbot python3-certbot-apache

# Get wildcard certificate
sudo certbot certonly --manual --preferred-challenges dns -d "*.fortunetttech.site" -d "fortunetttech.site"
```

Follow the prompts to add DNS TXT records for verification.

**Configure auto-renewal:**

```bash
sudo certbot renew --dry-run
```

### 4. Upload Code to VPS

**From the VPS, pull the code:**

```bash
cd /var/www/html
# Or your web root directory

# If using Git (recommended)
git pull origin main

# Or manually upload via SFTP/SCP
```

**Set correct permissions:**

```bash
sudo chown -R www-data:www-data /var/www/html/fortunett_technologies_
sudo chmod -R 755 /var/www/html/fortunett_technologies_
sudo chmod -R 777 /var/www/html/fortunett_technologies_/config
sudo chmod -R 777 /var/www/html/fortunett_technologies_/logs
```

###  5. Database Setup on VPS

**Connect to MySQL:**

```bash
mysql -u root -p
```

**Create production database:**

```sql
CREATE DATABASE IF NOT EXISTS fortunett_technologies;
CREATE USER IF NOT EXISTS 'fortunett_user'@'localhost' IDENTIFIED BY 'YOUR_SECURE_PASSWORD';
GRANT ALL PRIVILEGES ON fortunett_technologies.* TO 'fortunett_user'@'localhost';
FLUSH PRIVILEGES;
EXIT;
```

**Update database credentials:**

```bash
cd /var/www/html/fortunett_technologies_
nano includes/db_master.php
```

Change:
```php
$DB_HOST = 'localhost';
$DB_NAME = 'fortunett_technologies';
$DB_USER = 'fortunett_user';
$DB_PASS = 'YOUR_SECURE_PASSWORD';
```

**Run migrations on VPS:**

```bash
php run_multi_tenant_migration.php
php migrate_existing_users.php
```

### 6. Apache Virtual Host Configuration

**Create Apache config:**

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
</VirtualHost>

<VirtualHost *:443>
    ServerName fortunetttech.site
    ServerAlias *.fortunetttech.site
    
    DocumentRoot /var/www/html/fortunett_technologies_
    
    SSLEngine on
    SSLCertificateFile /etc/letsencrypt/live/fortunetttech.site/fullchain.pem
    SSLCertificateKeyFile /etc/letsencrypt/live/fortunetttech.site/privkey.pem
    
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
sudo a2enmod rewrite
sudo a2enmod ssl
sudo a2ensite fortunett.conf
sudo systemctl restart apache2
```

### 7. Testing

**Test main domain:**
```
https://fortunetttech.site
```

**Test subdomain (replace 'ecco' with your actual username):**
```
https://ecco.fortunetttech.site
```

**Test router auto-registration:**

On a MikroTik router, run the provisioning script from your admin dashboard.

**Test account number generation:**

1. Log into admin dashboard
2. Add a new client
3. Verify account number is auto-generated (e.g., e001)

**Test payment gateway:**

1. Go to Settings > Payments
2. Add a Paybill (without API keys)
3. Verify it saves and displays in the list

## How Multi-Tenancy Works

### Subdomain Routing

When a user visits `ecco.fortunetttech.site`:

1. Apache routes all `*.fortunetttech.site` to the application
2. The `tenant.php` class extracts "ecco" from the HTTP_HOST
3. Database lookup finds the tenant by subdomain
4. All queries automatically filter by `tenant_id`
5. User only sees their own data

### Account Number Generation

When you create a new client as admin "ecco":

1. System extracts prefix "e" from username
2. Queries highest existing account number with prefix "e"
3. Generates next number: e001, e002, e003, etc.
4. Stores in `clients.account_number` field

### Router Auto-Provisioning

When you run the provisioning script on Mikro Tik:

1. Router sends its IP, MAC, and provisioning token to VPS
2. System validates token and identifies tenant
3. Router auto-registers and appears in admin portal
4. Admin can now deploy services (PPPoE/Hotspot) to this router

### Payment Gateways

Each tenant can configure multiple payment gateways:

- Credentials are encrypted using AES-256
- Supports: Paybill (no API), M-Pesa API, Bank Transfer, Kopo Kopo, PayPal
- Customers see tenant's configured payment methods

## Security Notes

1. **Encryption Key**: Generated automatically in `config/encryption.key` - backup this file!
2. **Database Credentials**: Never commit to Git
3. **Provisioning Tokens**: Unique per tenant, regenerate if compromised
4. **Session Security**: Tenant ID stored in session, validated on every request
5. **Tenant Isolation**: All queries include `WHERE tenant_id = ?`

## Troubleshooting

### Subdomain not working

**Issue**: Subdomain shows 404 or doesn't resolve

**Fix**:
1. Check DNS propagation: `nslookup test.fortunetttech.site`
2. Verify Apache is serving all subdomains: check virtual host config
3. Check Apache error logs: `tail -f /var/log/apache2/fortunett_error.log`

### Account numbers not generating

**Issue**: New clients don't get account numbers

**Fix**:
1. Check `users.account_prefix` is set: `SELECT username, account_prefix FROM users WHERE role='admin';`
2. Run: `UPDATE users SET account_prefix = LEFT(username, 1) WHERE role='admin' AND account_prefix IS NULL;`
3. Check session has tenant_id: `print_r($_SESSION);`

### Router not auto-registering

**Issue**: Router runs script but doesn't appear in portal

**Fix**:
1. Check router can reach internet: `ping 8.8.8.8`
2. Verify provisioning token: Check database `SELECT provisioning_token FROM tenants;`
3. Check VPS firewall allows HTTP from router IP
4. Check `/api/routers/auto_register.php` endpoint is accessible

### Payment gateway errors

**Issue**: Can't save payment gateway

**Fix**:
1. Check `config/encryption.key` exists and is readable
2. Verify `payment_gateways` table exists
3. Check session has `tenant_id`
4. Check browser console for AJAX errors

## Support

For issues during deployment, check:
- Apache error logs: `/var/log/apache2/fortunett_error.log`
- PHP error logs: `/var/log/php_errors.log`
- Application logs: `fortunett_technologies_/logs/`

---

**Deployment Checklist:**

- [ ] Run database migrations locally
- [ ] Migrate existing users to tenants
- [ ] Configure wildcard DNS
- [ ] Set up SSL certificate
- [ ] Upload code to VPS
- [ ] Update database credentials
- [ ] Run migrations on VPS
- [ ] Configure Apache virtual host
- [ ] Test main domain access
- [ ] Test subdomain access
- [ ] Test client creation (account numbers)
- [ ] Test router provisioning
- [ ] Test payment gateway configuration
