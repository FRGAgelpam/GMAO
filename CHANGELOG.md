# Historique des versions

Ce fichier liste les changements apportés à cette GMAO, version par version. Il sert de repère à toute personne ayant installé sa propre instance : compare le numéro ci-dessous avec le contenu de ton fichier `VERSION` local pour savoir si tu es à jour.

Pour la procédure de mise à jour (y compris sans accès Internet sur le serveur), voir la section "Mettre à jour" de [install/README.md](install/README.md).

## 1.2.1 — 2026-09-19

- Accueil : nouveau design des tuiles (verre sombre, lueur de la couleur de chaque tuile, icône en anneau lumineux qui se remplit au survol), avec plus d'espace entre les rangées et des icônes toujours alignées à la même hauteur.
- Portail services : les quatre tuiles et le bandeau du haut adoptent le même design ; le logo s'affiche directement sur le bandeau, sans fond, avec une douce lueur.
- Pages Suivi des demandes, Idées et Aide du portail : même charte sombre ; les tuiles des bons d'intervention et les cadres des idées gardent un fond clair pour bien se distinguer.

Aucune migration de base de données : changements d'affichage uniquement.

## 1.2.0 — 2026-09-18

- Planning : les fenêtres du Planning (choix de case, planification des heures, TODO list, remplissage rapide, planning annuel) reprennent un même en-tête soigné avec le technicien concerné et la date ; les boutons de « Planifier les heures » sont resserrés pour éviter de défiler.
- TODO list : fenêtre remodelée en deux colonnes sur grand écran (liste à gauche, formulaire à droite), avec résumé d'avancement, filtres Toutes / À faire / Faites et tri par priorité.
- TODO list : une tâche peut maintenant être modifiée après sa création, et renseigner une priorité, une catégorie, une durée estimée, une heure prévue, une machine ou un lieu et un détail. Le suivi (création, report automatique, fin) s'affiche sous chaque tâche.
- Planning : le survol de la pastille de TODO list d'une case affiche une bulle d'information par tâche, à la place de l'infobulle du navigateur ; le compteur de la pastille se met à jour dès la fermeture de la TODO list.
- Paramètres : nouvel onglet « TODO list » pour gérer les catégories de tâches (nom, icône, couleur, ordre) et les durées estimées proposées.
- Pages d'aide du Planning et des Paramètres mises à jour.

Aucune migration de base de données obligatoire : les nouvelles colonnes et tables (`planning_todo`, `todo_categories`) se créent automatiquement au premier usage.
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
