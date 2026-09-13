# Installer la GMAO sur son propre serveur

*[Read this in English](README.en.md) · [Lees dit in het Nederlands](README.nl.md)*

Ce dossier contient de quoi installer une instance vierge de cette GMAO (aucune donnée réelle, structure de base uniquement).

## Prérequis

- PHP 8.x avec l'extension PDO MySQL activée
- MariaDB ou MySQL
- Un serveur web (Apache/Nginx) pointant son DocumentRoot sur le dossier `html/` (la racine de ce dépôt)
- `composer install` à la racine du dépôt pour installer les dépendances (dossier `vendor/`, non versionné)

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

## 4. Aller plus loin

Une fois connecté en admin, la page **Paramètres** permet de configurer sans toucher au code : catégories de préventif, services, types d'équipement, et de créer les autres comptes utilisateurs (techniciens, clés de service pour le portail de demandes).
