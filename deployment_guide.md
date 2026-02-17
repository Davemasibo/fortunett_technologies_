 # Deployment & Migration Guide

This guide provides step-by-step instructions on how to push your local changes to a server and set up the unified database on a new environment.

## 1. Pushing Changes (Using Git)

If you are using Git (recommended), follow these steps to push your changes:

1. **Check status**:
   ```bash
   git status
   ```
2. **Stage your changes**:
   ```bash
   git add .
   ```
3. **Commit your changes**:
   ```bash
   git commit -m "Unify SQL schema and update deployment documentation"
   ```
4. **Push to your repository**:
   ```bash
   git push origin main
   ```

---

## 2. Setting up the Database on a New Server

When setting up on a new server, you no longer need to run multiple migration files. Use the unified `master_database.sql` file.

### Prerequisites
- MySQL or MariaDB installed and running.
- Access to the MySQL command line or a tool like phpMyAdmin.

### Step-by-Step Setup

1. **Locate the SQL file**:
   The unified file is located at `sql/master_database.sql`.

2. **Import via Command Line**:
   Login to your server via SSH and run:
   ```bash
   mysql -u your_username -p < sql/master_database.sql
   ```
   *(This will create the `fortunnet_technologies` database and all necessary tables.)*

3. **Import via phpMyAdmin**:
   - Open phpMyAdmin.
   - (Optional) Create a new database named `fortunnet_technologies`.
   - Click the **Import** tab.
   - Choose `sql/master_database.sql`.
   - Click **Go** (at the bottom).

---

## 3. Handling Multiple SQL Instances in the Future

To keep your environment clean and easy to migrate, follow these practices:

- **Single Master Schema**: Always keep `sql/master_database.sql` up to date as the "source of truth" for a fresh install.
- **Controlled Migrations**: If you add new features, create a new file in `sql/migrations/` (e.g., `2026-02-17-add-logic.sql`).
- **Periodic Merges**: Once a migration is tested and working in production, merge it into the `master_database.sql` so the next server setup is even easier.

---

## 4. Configuration Update

Once the database is imported, ensure your application's connection settings are updated.

- Open `includes/db_connect.php` (or your equivalent config file).
- Update the following constants/variables:
  ```php
  define('DB_HOST', 'localhost');
  define('DB_USER', 'your_production_user');
  define('DB_PASS', 'your_secure_password');
  define('DB_NAME', 'fortunnet_technologies');
  ```
