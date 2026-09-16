# Installing the GMAO on your own server

*[Lire en français](README.md) · [Lees dit in het Nederlands](README.nl.md)*

This folder contains everything needed to install a blank instance of this GMAO (CMMS) — no real data, structure only.

## Requirements

- PHP 8.x with the PDO MySQL, `mbstring`, `xml`, `curl`, `gd` and **`zip`** extensions enabled (e.g. Debian/Ubuntu: `apt install php php-mysql php-mbstring php-xml php-curl php-gd php-zip`)
- MariaDB or MySQL
- A web server (Apache/Nginx) with its DocumentRoot pointing at the `html/` folder (the root of this repository) — on Apache, remember to enable `mod_rewrite` (`a2enmod rewrite`) and `AllowOverride All` on that folder (the project's `.htaccess` needs it)
- `composer install` at the repository root to install dependencies (creates the `vendor/` folder, not versioned) — needs the `zip` extension above, otherwise installing `phpoffice/phpspreadsheet` fails

## 1. Create the database

```sql
CREATE DATABASE gmao_db CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
CREATE USER 'gmao_user'@'localhost' IDENTIFIED BY 'a_strong_password';
GRANT ALL PRIVILEGES ON gmao_db.* TO 'gmao_user'@'localhost';
FLUSH PRIVILEGES;
```

Then import the table structure (no data, just the schema):

```bash
mysql -u gmao_user -p gmao_db < install/schema.sql
```

## 2. Configure the connection credentials

The connection file (`db.php`, at the repository root) looks for credentials in a file **`db_credentials.php` placed one level above this folder** (so outside the web-served root, never reachable by a URL even if the server is misconfigured).

1. Copy `db_credentials.example.php` (at the repository root) to the parent folder of `html/`.
2. Rename the copy to `db_credentials.php`.
3. Replace the values (`$host`, `$dbname`, `$user`, `$pass`) with those of the database created in step 1.

Expected layout around the web root:

```
/var/www/           (or your equivalent)
├── db_credentials.php   <- created in step 2, OUTSIDE the web root
└── html/                <- web root (= this repository), pointed to by the vhost
```

## 3. Create the first administrator account

The `utilisateurs` table is empty after importing the schema — so there is no account to log in with yet. You need to create one manually.

1. Generate a password hash (replace `MyPassword` with the password you want):

```bash
php -r "echo password_hash('MyPassword', PASSWORD_DEFAULT), PHP_EOL;"
```

2. Insert the admin account into the database, pasting the hash you got in place of `HASH_FROM_ABOVE`:

```sql
INSERT INTO utilisateurs (username, prenom, fonction, password, role, actif)
VALUES ('admin', 'Admin', 'Administrator', 'HASH_FROM_ABOVE', 'admin', 1);
```

3. Log in at `login.php` with the username `admin` and the password chosen in step 1.

## 4. Personalize the GMAO's identity

The code ships deliberately generic (name "GMAO", neutral logo, no external links). Once logged in as admin, go to **Settings > General** to replace that with your own identity, without touching the code:

- **Displayed company / GMAO name** — replaces "GMAO" everywhere in the app (page titles, the PDP document...).
- **Logo** — replaces the generic badge with your own (PNG, JPG, SVG or WEBP).
- **Public portal URL** and **Public demo URL** — two optional links shown on the login page ("Back to portal", "Try the demo"). Leave them empty if you don't have that kind of page: the links simply stay hidden.

## 5. Going further

Everything else is also configured entirely from **Settings**, without touching the code: preventive-maintenance categories, services, equipment types, and creating the other user accounts (technicians, service keys for the request portal).

## 6. Updating

**There is no automatic update mechanism.** This repository never contacts the Internet from your server, and that's intentional — many installations run on isolated networks with no outbound access at all. So it's up to you to check for new versions and apply them manually.

### How to know if an update is available

- The `VERSION` file at the root of your installation shows your current version.
- Compare it with the one on GitHub, and check [`CHANGELOG.md`](../CHANGELOG.md) to see what changed.
- If the server hosting the GMAO has no Internet access, someone needs to check from another machine that does. Consider clicking **Watch → Custom → Releases** at the top of the repository's GitHub page to get an email whenever a new version is published.

### How to apply it (including with no Internet access on the server)

1. From a machine with Internet access, download the latest version: the green **Code → Download ZIP** button on GitHub (`main` branch), or the ZIP attached to the repository's latest "Release".
2. Transfer that ZIP to the server through whatever method your IT policy allows (USB drive, jump host...) — this step depends entirely on your own network's security rules and can't be done for you.
3. Replace the installation's files with the ones from the ZIP, **except**:
   - the `uploads/` folder (your real attachments: photos, PDFs, avatars...);
   - `db_credentials.php` (located outside this folder, so it isn't in the ZIP anyway).
4. Check `install/updates/` for any `.sql` file(s) numbered between your old version and the new one. If there are any, run them in order (see the instructions in that folder). **Never re-import `install/schema.sql`** on an existing database: it's meant for a first installation only and would wipe your data.
5. Update the `VERSION` file to the new number (it's included in the ZIP, so this is normally already done by step 3).
