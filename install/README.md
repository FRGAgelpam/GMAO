# Installer la GMAO sur son propre serveur

*[Read this in English](README.en.md) · [Lees dit in het Nederlands](README.nl.md)*

Ce dossier contient de quoi installer une instance vierge de cette GMAO (aucune donnée réelle, structure de base uniquement).

## Prérequis

- PHP 8.x avec les extensions PDO MySQL, `mbstring`, `xml`, `curl`, `gd` et **`zip`** activées (ex. Debian/Ubuntu : `apt install php php-mysql php-mbstring php-xml php-curl php-gd php-zip`)
- MariaDB ou MySQL
- Un serveur web (Apache/Nginx) pointant son DocumentRoot sur le dossier `html/` (la racine de ce dépôt) — sur Apache, pense à activer `mod_rewrite` (`a2enmod rewrite`) et `AllowOverride All` sur ce dossier (le `.htaccess` du projet en a besoin)
- `composer install` à la racine du dépôt pour installer les dépendances (dossier `vendor/`, non versionné) — nécessite l'extension `zip` ci-dessus, sinon l'installation de `phpoffice/phpspreadsheet` échoue

## 1. Créer la base de données

```sql
CREATE DATABASE gmao_db CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
CREATE USER 'gmao_user'@'localhost' IDENTIFIED BY 'un_mot_de_passe_solide';
GRANT ALL PRIVILEGES ON gmao_db.* TO 'gmao_user'@'localhost';
FLUSH PRIVILEGES;
```

Puis importer la structure des tables (aucune donnée, juste le schéma) :

```bash
mysql -u gmao_user -p gmao_db < install/schema.sql
```

## 2. Configurer les identifiants de connexion

Le fichier de connexion (`db.php`, à la racine du dépôt) va chercher les identifiants dans un fichier **`db_credentials.php` placé un niveau au-dessus de ce dossier** (donc hors de la racine servie par le web, jamais accessible par une URL même en cas de mauvaise config du serveur).

1. Copie `db_credentials.example.php` (à la racine du dépôt) vers le dossier parent de `html/`.
2. Renomme la copie en `db_credentials.php`.
3. Remplace les valeurs (`$host`, `$dbname`, `$user`, `$pass`) par celles de ta base créée à l'étape 1.

Structure attendue autour de la racine web :

```
/var/www/           (ou équivalent chez toi)
├── db_credentials.php   <- créé à l'étape 2, HORS de la racine web
└── html/                <- racine web (= ce dépôt), pointée par le vhost
```

## 3. Créer le premier compte administrateur

La table `utilisateurs` est vide après l'import du schéma — il n'existe donc aucun compte pour se connecter. Il faut en créer un manuellement.

1. Génère un hash de mot de passe (remplace `MonMotDePasse` par le mot de passe souhaité) :

```bash
php -r "echo password_hash('MonMotDePasse', PASSWORD_DEFAULT), PHP_EOL;"
```

2. Insère le compte admin dans la base, en collant le hash obtenu à la place de `HASH_OBTENU_CI_DESSUS` :

```sql
INSERT INTO utilisateurs (username, prenom, fonction, password, role, actif)
VALUES ('admin', 'Admin', 'Administrateur', 'HASH_OBTENU_CI_DESSUS', 'admin', 1);
```

3. Connecte-toi sur `login.php` avec l'identifiant `admin` et le mot de passe choisi à l'étape 1.

## 4. Personnaliser l'identité de la GMAO

Le code livré est volontairement générique (nom "GMAO", logo neutre, aucun lien externe). Une fois connecté en admin, va dans **Paramètres > Général** pour remplacer ça par ta propre identité, sans toucher au code :

- **Nom affiché de l'entreprise / de la GMAO** — remplace "GMAO" partout dans l'application (titres de pages, document PDP...).
- **Logo** — remplace le badge générique par le tien (PNG, JPG, SVG ou WEBP).
- **URL du portail public** et **URL de la démo publique** — deux liens optionnels affichés sur la page de connexion ("Retour au portail", "Tester la démo"). Laisse-les vides si tu n'as pas ce genre de pages : les liens restent simplement masqués.

## 5. Aller plus loin

Le reste se configure aussi entièrement depuis **Paramètres**, sans toucher au code : catégories de préventif, services, types d'équipement, et création des autres comptes utilisateurs (techniciens, clés de service pour le portail de demandes).
