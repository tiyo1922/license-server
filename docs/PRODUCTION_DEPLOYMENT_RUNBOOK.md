# Production Deployment & Cryptographic Disaster Recovery Runbook
**Authority Host:** `https://license.katresnanku.com`  
**System:** Central License Management Authority (Laravel 13.x / PHP 8.3.x / SQLite Immediate / Ed25519)  
**Target Environment:** cPanel / IDWebhost / Shared Hosting / VPS Reverse Proxy

---

## Table of Contents
1. [Architecture & Topology](#1-architecture--topology)
2. [Server Requirements](#2-server-requirements)
3. [cPanel / IDWebhost Setup & Document Root](#3-cpanel--idwebhost-setup--document-root)
4. [Production Environment Configuration (`.env`)](#4-production-environment-configuration-env)
5. [Database Configuration & File Permissions](#5-database-configuration--file-permissions)
6. [Ed25519 Cryptographic Keypair Provisioning](#6-ed25519-cryptographic-keypair-provisioning)
7. [Scheduler / Cron Automation](#7-scheduler--cron-automation)
8. [Trusted Proxy & Reverse Proxy Hardening](#8-trusted-proxy--reverse-proxy-hardening)
9. [Zero-Downtime Key Rotation Procedure](#9-zero-downtime-key-rotation-procedure)
10. [Backup, Disaster Recovery & Rollback](#10-backup-disaster-recovery--rollback)
11. [Post-Deployment Verification Checklist](#11-post-deployment-verification-checklist)

---

## 1. Architecture & Topology

```
+-------------------------------------------------------------------------+
| Client Application (WordPress Plugin / SaaS Node / Microservice)        |
+-------------------------------------------------------------------------+
       |                                                    ^
       | 1. POST /api/v1/license/activate                   | (Periodic Offline
       | 2. POST /api/v1/license/verify                     |  Verification via
       v                                                    |  Public Key)
+-----------------------------------------------------------+-------------+
| Cloudflare / cPanel Apache Reverse Proxy (HTTPS Termination)            |
| (Trusts X-Forwarded-For, X-Forwarded-Proto)                              |
+-------------------------------------------------------------------------+
       |
       v
+-------------------------------------------------------------------------+
| Central License Server (https://license.katresnanku.com)                |
| - Document Root: /public                                                |
| - PHP 8.3 + SQLite 3.35+ (WAL Mode, BEGIN IMMEDIATE Concurrency)        |
| - Ed25519 Signer (Primary Key ID: e.g. prod-2026-v1)                    |
| - Trusted Key Registry (Supports multiple public keys for zero-downtime)|
+-------------------------------------------------------------------------+
```

---

## 2. Server Requirements

* **PHP:** >= 8.3.0 with standard extensions:
  * `ext-sodium` (Critical: Edwards Curve 25519 cryptography)
  * `ext-pdo_sqlite` (Database engine)
  * `ext-mbstring`, `ext-ctype`, `ext-json`, `ext-tokenizer`, `ext-xml`, `ext-curl`
* **Web Server:** Apache 2.4+ (with `mod_rewrite`, `mod_headers`, `mod_ssl`) or Nginx.
* **SQLite:** >= 3.35.0 (for atomic transaction support).

---

## 3. cPanel / IDWebhost Setup & Document Root

### Step 3.1: Directory Structure
Place the application codebase outside the public web root for security isolation:
```
/home/<cpanel_user>/
├── license-server/                 <-- Application Root (Private)
│   ├── app/
│   ├── bootstrap/
│   ├── config/
│   ├── database/
│   │   └── database.sqlite
│   ├── public/                     <-- Web Entry Point
│   │   ├── index.php
│   │   └── .htaccess
│   ├── storage/
│   └── .env
└── public_html/
    └── license/                    <-- (If Subdomain document root points directly here)
```

### Step 3.2: Subdomain Configuration
1. In cPanel, navigate to **Domains** -> **Subdomains**.
2. Create `license.katresnanku.com`.
3. Set the **Document Root** directly to:
   `/home/<cpanel_user>/license-server/public`
4. Ensure `.htaccess` inside `public/` is active for route rewriting:
   ```apache
   <IfModule mod_rewrite.c>
       RewriteEngine On
       RewriteCond %{REQUEST_FILENAME} !-d
       RewriteCond %{REQUEST_FILENAME} !-f
       RewriteRule ^ index.php [L]
   </IfModule>
   ```

---

## 4. Production Environment Configuration (`.env`)

Create `/home/<cpanel_user>/license-server/.env` based on the template below:

```ini
APP_NAME="License Server"
APP_ENV=production
APP_KEY=base64:GENERATE_VIA_PHP_ARTISAN_KEY_GENERATE
APP_DEBUG=false
APP_TIMEZONE=UTC
APP_URL=https://license.katresnanku.com

LOG_CHANNEL=daily
LOG_LEVEL=warning

DB_CONNECTION=sqlite
DB_DATABASE=/home/<cpanel_user>/license-server/database/database.sqlite
DB_FOREIGN_KEYS=true

SESSION_DRIVER=database
SESSION_LIFETIME=120
SESSION_ENCRYPT=false
SESSION_PATH=/
SESSION_DOMAIN=license.katresnanku.com
SESSION_SECURE_COOKIE=true
SESSION_HTTP_ONLY=true
SESSION_SAME_SITE=lax

CACHE_STORE=file
QUEUE_CONNECTION=sync

# --- LICENSE SERVER CRYPTOGRAPHIC CONFIGURATION ---
# Key ID identifying the current primary active signing key
ED25519_KEY_ID=prod-2026-v1

# 32-byte Ed25519 private key (Base64-encoded) generated via CLI
ED25519_PRIVATE_KEY=PASTE_BASE64_PRIVATE_KEY_HERE

# 32-byte Ed25519 public key (Base64-encoded, optional - auto-derived if omitted)
ED25519_PUBLIC_KEY=PASTE_BASE64_PUBLIC_KEY_HERE

# Token issuer URL (Must match APP_URL authoritative domain)
LICENSE_TOKEN_ISSUER=https://license.katresnanku.com

# Token validity durations
LICENSE_TOKEN_DEFAULT_TTL_DAYS=30
LICENSE_TOKEN_MAX_TTL_DAYS=90
LICENSE_TOKEN_CLOCK_SKEW_LEEWAY_SECONDS=60
LICENSE_TOKEN_ROLLING_REFRESH_THRESHOLD_PERCENT=50

# Trusted reverse proxies (Use * for Cloudflare / cPanel reverse proxies)
TRUSTED_PROXIES=*
```

---

## 5. Database Configuration & File Permissions

### Step 5.1: Create SQLite Database
```bash
touch /home/<cpanel_user>/license-server/database/database.sqlite
```

### Step 5.2: Set Secure File Permissions
The web server user (`nobody`, `www-data`, or the cPanel user via PHP-FPM) requires read/write access to `storage` and `database`:
```bash
cd /home/<cpanel_user>/license-server
chmod -R 775 storage
chmod -R 775 bootstrap/cache
chmod 775 database
chmod 664 database/database.sqlite
```

### Step 5.3: Run Database Migrations
```bash
php artisan migrate --force
```

### Step 5.4: Enable SQLite Write-Ahead Logging (WAL Mode)
WAL mode dramatically increases concurrent read/write throughput:
```bash
sqlite3 database/database.sqlite "PRAGMA journal_mode=WAL;"
```

---

## 6. Ed25519 Cryptographic Keypair Provisioning

### Step 6.1: Generate Production Keypair
Run the dedicated Phase 8A keypair CLI command:
```bash
php artisan license:generate-keypair --key-id=prod-2026-v1
```
Output will display:
- **Key ID:** `prod-2026-v1`
- **Private Key (Base64):** `[32-byte base64 string]`
- **Public Key (Base64):** `[32-byte base64 string]`

### Step 6.2: Configure `.env`
1. Copy the `ED25519_PRIVATE_KEY` and `ED25519_KEY_ID` into your `.env`.
2. Ensure `APP_ENV=production` is set.
3. Verify that the application fails fast if `ED25519_PRIVATE_KEY` is missing or invalid.

---

## 7. Scheduler / Cron Automation

The license server requires a continuous cron heartbeat to synchronize expired licenses and clean expired sessions.

### In cPanel -> **Cron Jobs**:
Add a cron job running every minute:
```bash
* * * * * cd /home/<cpanel_user>/license-server && /usr/local/bin/php artisan schedule:run >> /dev/null 2>&1
```

Registered Scheduled Commands:
- `license:sync-expired` (Runs every 15 minutes to update authoritative expiration states).

---

## 8. Trusted Proxy & Reverse Proxy Hardening

1. **Cloudflare / Reverse Proxy SSL:**
   - In Cloudflare SSL/TLS settings, set encryption mode to **Full (Strict)**.
   - The license server automatically trusts incoming `X-Forwarded-For` and `X-Forwarded-Proto` headers when `TRUSTED_PROXIES=*` is configured.
2. **Security Headers Verification:**
   All HTTP responses include:
   - `X-Frame-Options: SAMEORIGIN`
   - `X-Content-Type-Options: nosniff`
   - `Referrer-Policy: strict-origin-when-cross-origin`
   - `Permissions-Policy: camera=(), microphone=(), geolocation=()`
   - `Strict-Transport-Security: max-age=31536000; includeSubDomains` (on HTTPS)

---

## 9. Zero-Downtime Key Rotation Procedure

When rotating the Ed25519 signing keypair (e.g. annual rotation or scheduled security refresh):

### Phase 1: Pre-distribute New Public Key to Clients
1. Generate new keypair:
   ```bash
   php artisan license:generate-keypair --key-id=prod-2027-v1
   ```
2. Add the new public key to `config/license.php` (or client applications' trusted key registry) under `trusted_public_keys`:
   ```php
   'trusted_public_keys' => [
       'prod-2026-v1' => env('ED25519_PUBLIC_KEY_OLD', '...'),
       'prod-2027-v1' => env('ED25519_PUBLIC_KEY_NEW', '...'),
   ],
   ```
3. Deploy the client update containing both public keys.

### Phase 2: Switch Server Signer Key
Update `.env` on the license server:
```ini
ED25519_KEY_ID=prod-2027-v1
ED25519_PRIVATE_KEY=PASTE_NEW_BASE64_PRIVATE_KEY
ED25519_PUBLIC_KEY=PASTE_NEW_BASE64_PUBLIC_KEY
```
Restart/clear config cache:
```bash
php artisan config:clear
```
All newly issued or refreshed tokens will now use `prod-2027-v1`. Existing clients will verify both `prod-2026-v1` and `prod-2027-v1` tokens without interruption.

### Phase 3: Retire Old Key
After all old tokens have expired (e.g. 30–90 days), remove `prod-2026-v1` from the trusted keys registry.

---

## 10. Backup, Disaster Recovery & Rollback

### Step 10.1: Database Backup
Because SQLite is used, perform live online backups using the SQLite backup API or copy with WAL flush:
```bash
# Online hot backup without locking
sqlite3 /home/<cpanel_user>/license-server/database/database.sqlite ".backup '/home/<cpanel_user>/backups/license_backup_$(date +%Y%m%d_%H%M%S).sqlite'"
```
Automate this daily via cPanel Cron.

### Step 10.2: Cryptographic Key Compromise / Disaster Recovery
If the server private key is compromised:
1. Immediately generate a new keypair (`php artisan license:generate-keypair --key-id=prod-recovery-v1`).
2. Update `.env` with the new keypair.
3. Release a patch for client applications with the updated public key registry removing the compromised `kid`.
4. Trigger client token re-verification: Clients verifying online will receive newly signed tokens under `prod-recovery-v1`.

### Step 10.3: Rollback Plan
If a deployment fails:
1. Revert codebase to previous release tag.
2. Restore SQLite database snapshot if schema was altered.
3. Clear application caches:
   ```bash
   php artisan optimize:clear
   ```

---

## 11. Production Pre-Flight & Admin Provisioning

### Step 11.1: Execute Server Pre-Flight Diagnostic
Before routing live domain traffic, run the automated pre-flight audit tool on the server:
```bash
php artisan license:preflight
```
The command verifies:
- PHP version >= 8.3.0 and `ext-sodium` availability.
- SQLite file existence and write permissions for `storage/` and `database/`.
- Database connectivity and migration currency.
- `APP_ENV=production` & `APP_DEBUG=false` safety flags.
- Valid, non-ephemeral Ed25519 signing key configuration.
- Background scheduler task registration.

Must output: `✅ PRE-FLIGHT VERIFICATION PASSED: License Server is ready for production operation.`

### Step 11.2: Provision Initial Administrator Account
On a clean production database, securely provision the initial admin account:
```bash
php artisan license:create-admin
```
Follow the interactive prompts to enter the admin name, email, and password (securely masked, min 10 characters).

---

## 12. Post-Deployment Smoke Verification Checklist

Execute these checks immediately after production deployment:

1. **System Healthcheck:**
   ```bash
   curl -i https://license.katresnanku.com/api/health
   ```
   - Must return HTTP `200 OK`
   - Must return JSON: `{"status":"UP","services":{"database":"OK","signer":"READY"}}`
2. **Security Headers Verification:**
   Verify response headers include:
   - `X-Frame-Options: SAMEORIGIN`
   - `X-Content-Type-Options: nosniff`
   - `Referrer-Policy: strict-origin-when-cross-origin`
   - `Strict-Transport-Security: max-age=31536000; includeSubDomains`
3. **Admin Web Authentication:**
   - Access `https://license.katresnanku.com/login`
   - Log in with administrator credentials provisioned in Step 11.2.
   - Verify Dashboard statistics and Audit Logs load properly.
4. **API Activation Test:**
   - Create a test application and API key in the admin panel.
   - Issue a test license.
   - Perform an activation request via `POST /api/v1/license/activate`.
   - Verify token signature with `ReferenceClientLicenseVerifier`.
5. **API Verification Test:**
   - Perform verification request via `POST /api/v1/license/verify`.
   - Verify rolling refresh threshold triggers as expected.
6. **Cron Execution Verification:**
   - Verify `license_logs` displays scheduler activity or inspect storage logs for successful command execution.
