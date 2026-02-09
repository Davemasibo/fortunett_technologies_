# Fortunnet ISP - VPS Sync & Update Guide

If you are stuck moving changes from your laptop to the VPS, follow one of these two paths.

---

## 🚀 Path A: Incremental Update (Fastest)

Only upload these specific files that contain our recent improvements. This ensures you don't break your existing VPS setup.

**📁 Folders to Upload Entirely:**
*   `/api/routers/` (All 6 files inside)
*   `/customer/` (Update `payment.php` and `dashboard.php` here)

**📄 Individual Files to Upload:**
1.  `dashboard.php` (Main Admin Dashboard)
2.  `mikrotik.php` (Router Wizard Fix)
3.  `settings.php` (Proximity Logic)
4.  `billing.php` (UI Consistency)
5.  `includes/tenant.php` (Token Validation)

---

## 🧹 Path B: Full Refresh (Cleanest)

If you are worried about missing something, do this safely:

1.  **Zip your project** on your laptop.
2.  **Delete everything** on the VPS `/var/www/html/` folder EXCEPT for:
    *   `config/database.php`
    *   `includes/db_master.php`
    *   (These contain your VPS database password, don't lose them!)
3.  **Upload and Unzip** your new project.
4.  If you accidentally overwrote those two files, re-edit them on the VPS to point to your VPS database password.

---

## ⚙️ Automatic Provisioning URL

You noticed the URL was still `localhost`. Here is why:
1.  On your laptop, the command generates `localhost`.
2.  **Once you upload `mikrotik.php` to your VPS**, when you view that page at `http://72.61.147.86/mikrotik.php`, it will **automatically** change the command to:
    `url="http://72.61.147.86/..."`
3.  **The code is now dynamic.** It follows whatever IP/Domain you are currently using in your browser.

---

## ✅ Final Verification Checklist
1.  [ ] Visit `http://72.61.147.86/dashboard.php`
2.  [ ] Go to **Routers** -> **Add Router**.
3.  [ ] Type "RB951" and click Next.
4.  [ ] Verify the command now says `72.61.147.86`.
5.  [ ] Copy and Paste into Winbox.
