# De GMAO op je eigen server installeren

*[Lire en français](README.md) · [Read this in English](README.en.md)*

Deze map bevat alles om een lege instantie van deze GMAO (onderhoudsbeheersysteem) te installeren (geen echte gegevens, enkel de structuur).

## Vereisten

- PHP 8.x met de extensies PDO MySQL, `mbstring`, `xml`, `curl`, `gd` en **`zip`** ingeschakeld (bv. Debian/Ubuntu: `apt install php php-mysql php-mbstring php-xml php-curl php-gd php-zip`)
- MariaDB of MySQL
- Een webserver (Apache/Nginx) waarvan de DocumentRoot naar de map `html/` wijst (de root van deze repository) — schakel bij Apache ook `mod_rewrite` in (`a2enmod rewrite`) en zet `AllowOverride All` op die map (nodig voor het `.htaccess`-bestand van het project)
- `composer install` in de root van de repository om de dependencies te installeren (maakt de map `vendor/` aan, niet geversioneerd) — vereist de `zip`-extensie hierboven, anders mislukt de installatie van `phpoffice/phpspreadsheet`

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

## 6. Bijwerken

**Er bestaat geen automatische update.** Deze repository neemt vanaf jouw server nooit contact op met het internet, en dat is bewust zo — veel installaties draaien op geïsoleerde netwerken zonder enige uitgaande toegang. Het is dus aan jou om regelmatig te controleren of er een nieuwe versie is, en die dan handmatig toe te passen.

### Hoe weet je of er een update is

- Het bestand `VERSION` in de root van jouw installatie toont je huidige versie.
- Vergelijk dit met dat van de repository op GitHub, en bekijk [`CHANGELOG.md`](../CHANGELOG.md) om te zien wat er veranderd is.
- Als de server waarop de GMAO draait geen internettoegang heeft, moet iemand dit controleren vanaf een ander toestel dat dat wel heeft. Klik op **Watch → Custom → Releases** bovenaan de GitHub-pagina van de repository om automatisch een e-mail te krijgen zodra er een nieuwe versie verschijnt.

### Hoe je een update toepast (ook zonder internettoegang op de server)

1. Download vanaf een toestel met internettoegang de laatste versie: de groene knop **Code → Download ZIP** op GitHub (branch `main`), of de ZIP die bij de laatste "Release" van de repository hoort.
2. Breng deze ZIP over naar de server via de methode die je IT-beleid toelaat (USB-stick, tussenliggende server...) — deze stap hangt volledig af van de beveiligingsregels van je eigen netwerk en kan niet in jouw plaats gedaan worden.
3. Vervang de bestanden van de installatie door die uit de ZIP, **behalve**:
   - de map `uploads/` (je echte bijlagen: foto's, PDF's, avatars...);
   - `db_credentials.php` (staat buiten deze map, en zit sowieso niet in de ZIP).
4. Kijk in `install/updates/` of er een of meerdere `.sql`-bestanden staan genummerd tussen jouw oude en de nieuwe versie. Voer ze zo ja in volgorde uit (zie de instructies in die map). **Importeer nooit opnieuw `install/schema.sql`** op een bestaande database: dit bestand is enkel bedoeld voor een allereerste installatie en zou je gegevens wissen.
5. Werk het bestand `VERSION` bij met het nieuwe nummer (dit zit in de ZIP, dus is bij stap 3 normaal al gebeurd).
