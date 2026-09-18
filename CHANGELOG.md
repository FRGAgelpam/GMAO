# Historique des versions

Ce fichier liste les changements apportés à cette GMAO, version par version. Il sert de repère à toute personne ayant installé sa propre instance : compare le numéro ci-dessous avec le contenu de ton fichier `VERSION` local pour savoir si tu es à jour.

Pour la procédure de mise à jour (y compris sans accès Internet sur le serveur), voir la section "Mettre à jour" de [install/README.md](install/README.md).

## 1.1.0 — 2026-09-18

- Numéro de version affiché dans l'appli : en-tête de chaque page, page de connexion, et Journal des évolutions.
- Planning : cliquer sur une case ouvre désormais un choix entre planifier les heures, créer un bon d'intervention ou ouvrir la TODO list du jour.
- Nouvelle TODO list personnelle par jour sur le Planning (une par technicien, admins compris), avec report automatique des tâches non cochées au jour suivant et une pastille sur la case indiquant qu'il y en a.
- Créer un bon d'intervention depuis le Planning pré-remplit désormais le technicien et la date dans l'assistant de création.
- Vue Mois du Planning : bulle d'information au survol d'un bon d'intervention et flèches de navigation, comme la vue Semaine.
- Corrections diverses (bulles d'information qui débordaient du cadre en début/fin de semaine, pastilles de filtre de la checklist saisonnière).

Aucune migration de base de données obligatoire : la table `planning_todo` se crée automatiquement au premier usage.

## 1.0.0 — 2026-09-16

Première version numérotée du kit d'installation public.

- Logo par défaut : icône ronde bleu/vert neutre (aucun texte), remplaçant l'ancien badge bleu carré.
- Mise en place du suivi de version (`VERSION`, ce fichier, dossier `install/updates/` pour les futures migrations de base de données).

Aucune migration de base de données nécessaire pour cette version (installation neuve uniquement, via `install/schema.sql`).
