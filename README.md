# GMAO

Application de GMAO (Gestion de Maintenance Assistée par Ordinateur) permettant de gérer la maintenance curative et préventive d'un parc de machines industrielles.

## Fonctionnalités

- **Ordres de travail (OT)** — création, prise en charge, suivi du cycle de statuts et rapport de clôture.
- **Demandes de travaux** — portail permettant à la Production, la Qualité et les autres services de signaler une panne.
- **Maintenance préventive & planning** — gammes préventives avec génération automatique d'OT, planning hebdomadaire des interventions.
- **Gestion des machines** — fiches machines, historique d'intervention.
- **Plans de prévention (PDP)** — génération de documents pour les sous-traitants intervenant sur site.
- **Sous-traitants** — suivi des entreprises extérieures.
- **KPI & statistiques techniciens** — indicateurs de performance et de charge (admin).
- **Idées d'amélioration** — remontée et suivi des idées des utilisateurs.
- **Gestion des utilisateurs & rôles** — admin, technicien, ou une clé de service (compte "portail demandeur", accès limité au dépôt de demandes de travaux).

## Stack technique

- PHP (PDO / MySQL-MariaDB)
- [dompdf](https://github.com/dompdf/dompdf) pour la génération de PDF
- [PhpSpreadsheet](https://github.com/PHPOffice/PhpSpreadsheet) pour les exports/imports Excel

## Installation

```bash
composer install
```

La connexion à la base de données se configure dans un fichier `db_credentials.php` placé **en dehors** de la racine web (un niveau au-dessus de ce dossier), non versionné, définissant `$host`, `$dbname`, `$user`, `$pass`.

Les dossiers `vendor/` et `uploads/` sont générés/remplis à l'exécution et ne sont pas versionnés (voir `.gitignore`).
