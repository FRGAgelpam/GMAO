# De GMAO op je eigen server installeren

*[Lire en français](README.md) · [Read this in English](README.en.md)*

Deze map bevat alles om een lege instantie van deze GMAO (onderhoudsbeheersysteem) te installeren (geen echte gegevens, enkel de structuur).

## Vereisten

- PHP 8.x met de PDO MySQL-extensie ingeschakeld
- MariaDB of MySQL
- Een webserver (Apache/Nginx) waarvan de DocumentRoot naar de map `html/` wijst (de root van deze repository)
- `composer install` in de root van de repository om de dependencies te installeren (maakt de map `vendor/` aan, niet geversioneerd)

## 1. Database aanmaken

```sql
CREATE DATABASE gmao_db CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
CREATE USER 'gmao_user'@'localhost' IDENTIFIED BY 'een_sterk_wachtwoord';
GRANT ALL PRIVILEGES ON gmao_db.* TO 'gmao_user'@'localhost';
FLUSH PRIVILEGES;
```

Importeer vervolgens de tabelstructuur (geen gegevens, enkel het schema):

```bash
mysql -u gmao_user -p gmao_db < install/schema.sql
```

## 2. Verbindingsgegevens instellen

Het verbindingsbestand (`db.php`, in de root van de repository) zoekt de inloggegevens in een bestand **`db_credentials.php`, één niveau boven deze map** (dus buiten de door het web geserveerde root, nooit bereikbaar via een URL, zelfs niet bij een verkeerd geconfigureerde server).

1. Kopieer `db_credentials.example.php` (in de root van de repository) naar de bovenliggende map van `html/`.
2. Hernoem de kopie naar `db_credentials.php`.
3. Vervang de waarden (`$host`, `$dbname`, `$user`, `$pass`) door die van de database die je in stap 1 hebt aangemaakt.

Verwachte structuur rond de webroot:

```
/var/www/           (of jouw equivalent)
├── db_credentials.php   <- aangemaakt in stap 2, BUITEN de webroot
└── html/                <- webroot (= deze repository), waar de vhost naar wijst
```

## 3. Eerste beheerdersaccount aanmaken

De tabel `utilisateurs` is leeg na het importeren van het schema — er bestaat dus nog geen account om mee in te loggen. Je moet er handmatig één aanmaken.

1. Genereer een wachtwoord-hash (vervang `MijnWachtwoord` door het gewenste wachtwoord):

```bash
php -r "echo password_hash('MijnWachtwoord', PASSWORD_DEFAULT), PHP_EOL;"
```

2. Voeg het beheerdersaccount toe aan de database, en plak de verkregen hash in plaats van `HASH_HIERBOVEN`:

```sql
INSERT INTO utilisateurs (username, prenom, fonction, password, role, actif)
VALUES ('admin', 'Admin', 'Beheerder', 'HASH_HIERBOVEN', 'admin', 1);
```

3. Log in op `login.php` met gebruikersnaam `admin` en het wachtwoord gekozen in stap 1.

## 4. De identiteit van de GMAO aanpassen

De meegeleverde code is bewust generiek (naam "GMAO", neutraal logo, geen externe links). Eenmaal ingelogd als beheerder ga je naar **Instellingen > Algemeen** om dit te vervangen door je eigen identiteit, zonder de code aan te raken:

- **Weergegeven bedrijfs-/GMAO-naam** — vervangt "GMAO" overal in de applicatie (paginatitels, het PDP-document...).
- **Logo** — vervangt het generieke logo door je eigen logo (PNG, JPG, SVG of WEBP).
- **URL van het openbare portaal** en **URL van de openbare demo** — twee optionele links op de inlogpagina ("Terug naar portaal", "Demo testen"). Laat ze leeg als je dit soort pagina's niet hebt: de links blijven dan gewoon verborgen.

## 5. Verder gaan

De rest configureer je ook volledig via **Instellingen**, zonder de code aan te raken: categorieën voor preventief onderhoud, diensten, apparatuurtypes, en het aanmaken van de andere gebruikersaccounts (technici, dienstsleutels voor het aanvraagportaal).
