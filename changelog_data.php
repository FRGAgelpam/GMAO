<?php
// Historique des évolutions de la GMAO, par date (la plus récente en premier, tel qu'affiché).
// Entretenu manuellement : ajouter une entrée en tête de tableau à chaque nouveau lot de
// modifications déployé (voir CLAUDE.md : "chaque nouveau lot" = un feu vert de déploiement).
// Reconstitué en 2026-09-18 à partir de l'historique Git — mais pas uniquement la branche "main"
// du dépôt principal : certains correctifs vivent uniquement sur la branche du dépôt public
// FRGAgelpam (ex. retrait de données personnelles réelles), et d'autres (ex. sécurisation des
// sauvegardes du 23/08) n'ont jamais été un commit, juste une opération faite en direct sur le
// serveur. Pour un lot futur qui touche uniquement la prod/démo sans repasser par un commit local,
// penser à ajouter la ligne ici à la main.
// Contenu volontairement non traduit (comme les descriptions du Journal d'activité) : c'est un
// historique de développement, pas une donnée destinée aux comptes "portail" multilingues.
return [
    ['date' => '2026-09-18', 'items' => [
        "Ajout d'une page « Journal des évolutions », visible depuis l'accueil (admin), qui liste l'historique de toutes les modifications de la GMAO par date.",
        "Mise à jour de l'aide Planning avec la présentation du nouvel affichage Jour / Semaine / Mois.",
        "Réduction de la taille des tuiles et du titre sur la page d'accueil de l'Aide, pour en voir davantage sans avoir à dézoomer.",
        "Correction des captures d'écran de l'aide Paramètres qui s'affichaient en pleine résolution, débordant du cadre de la page.",
    ]],
    ['date' => '2026-09-17', 'items' => [
        "Ajout d'un affichage Jour / Semaine / Mois sur le Planning.",
        "Correction des avatars du Planning qui n'affichaient pas la vraie photo de profil de chacun.",
        "Kit d'installation public : retrait de données personnelles réelles restées dans le code (nom de l'entreprise, numéros de téléphone).",
        "Kit d'installation public : retrait du plan d'accès réel et d'une seconde image de localisation du site.",
        "Correction des cartes Stats Techniciens invisibles sur la démo : les données de test n'étaient plus à jour avec les dernières fonctionnalités ajoutées à l'application.",
    ]],
    ['date' => '2026-09-16', 'items' => [
        "Mise en place d'un suivi de version et d'une procédure de mise à jour manuelle pour les installations hors-ligne.",
        "Remplacement du logo générique par défaut par une icône ronde neutre.",
        "Correction du logo générique affiché à tort sur les pages d'accueil du portail et de demande de travaux.",
    ]],
    ['date' => '2026-09-15', 'items' => [
        "Ajout de la sous-traitance à la checklist Travaux Hiver et correction d'un désalignement de colonnes.",
        "Mise en place d'un miroir complet de la prod (code + fichiers uploadés) dans le dépôt privé, pour servir de sauvegarde.",
    ]],
    ['date' => '2026-09-13', 'items' => [
        "Kit d'installation pour de nouvelles instances de la GMAO, avec passage en marque blanche (logos et textes neutres, personnalisables).",
        "Documentation de la personnalisation de l'identité (logo, nom) dans le guide d'installation.",
        "Ajout des prérequis techniques manquants (extensions PHP, configuration serveur) au guide d'installation.",
    ]],
    ['date' => '2026-09-11', 'items' => [
        "Correction d'un débordement horizontal et d'un défilement bloqué sur mobile dans le Préventif.",
        "Ajustement des fenêtres modales du Préventif sur mobile.",
        "Ajout d'une vue en cartes, plus lisible sur mobile, pour les tableaux du Préventif.",
        "Remplacement du terme « OT » par « BI » dans toutes les pages d'aide (FR/EN/NL).",
        "Ajout de l'impression groupée de fiches tâche pour le tableau d'atelier, et documentation dans l'aide.",
        "Ajout d'une fiche tâche imprimable pour l'attribution sur le tableau d'atelier.",
    ]],
    ['date' => '2026-09-10', 'items' => [
        "Reprise des captures d'écran des pages d'aide directement depuis la démo.",
        "Traduction des 14 pages d'aide en français / anglais / néerlandais.",
        "Suppression de l'outil de renumérotation massive des BI, qui n'était plus utilisé.",
        "Un BI déjà créé peut maintenant être relancé tant qu'il reste « à faire ».",
        "Correction de BI invisibles côté service demandeur quand ils étaient classés en Préventif.",
        "Retrait des dernières fenêtres de dialogue natives du navigateur, remplacées par les modales stylées maison.",
        "Ajout de l'upload d'une photo de profil par utilisateur, à la place des anciennes photos nominatives fixes.",
    ]],
    ['date' => '2026-09-09', 'items' => [
        "Correction de l'affichage de l'intervenant qui retombait à tort sur le déclarant.",
        "Correction de boutons inaccessibles à la dernière étape de l'assistant de création de BI.",
        "Possibilité d'assigner plusieurs techniciens à la création d'un BI, et correction de la date des BI de nuit.",
        "Restructuration de l'annuaire des entreprises extérieures et enrichissement des Stats Techniciens.",
        "Correctifs et enrichissement du Plan de Prévention.",
    ]],
    ['date' => '2026-09-08', 'items' => [
        "Ajout de la relance de demande et enrichissement de la messagerie / clôture des OT.",
    ]],
    ['date' => '2026-09-07', 'items' => [
        "Correction d'une perte de saisie non enregistrée dans la fiche technique.",
        "Mise à jour des pages d'aide concernées par le chantier de traduction et les correctifs récents.",
        "Correction d'un bug qui figeait certains libellés de statut/priorité en français malgré le changement de langue.",
        "Remplacement des dernières fenêtres de dialogue natives par des modales stylées dans le Parc Machine.",
        "Correction de l'accord du statut « Terminé » écrit à la clôture d'un OT.",
        "Traduction complète de la GMAO en français / anglais / néerlandais.",
    ]],
    ['date' => '2026-09-06', 'items' => [
        "Correction de l'attribution des interventions par technicien et de doublons de BI.",
        "Coloration du panneau Pannes récurrentes par secteur selon le type actif.",
    ]],
    ['date' => '2026-09-04', 'items' => [
        "Mise à jour de l'aide OT sur la visibilité côté demandeur après clôture.",
        "Mise à jour de l'aide du portail service avec les nouveautés du suivi de demande.",
        "Enrichissement du suivi portail service et correction du tracker de statut des BI.",
        "Correction d'une échéance figée sur les règles préventives en mode fréquence.",
        "Mise à jour de l'aide Préventif et de l'aide OT avec les nouveautés du SAS de validation.",
        "Enrichissement du SAS de validation (photos, urgence, messages), utilisable sur mobile/tablette.",
        "Ajout de la génération manuelle d'une demande préventive avec assignation automatique au technicien du matin.",
        "Compression automatique des photos de BI, et confidentialité des notes de planning en dehors du planning annuel.",
    ]],
    ['date' => '2026-09-03', 'items' => [
        "Documentation de la limite de taille des uploads (300 Mo) dans l'aide Parc Machine.",
        "Ajout des photos sur les BI, du comptage/clignotement des messages, et mise à jour de l'aide KPI.",
        "Ajout de la répartition curatif/préventif/chantier avec un carrousel sur la page KPI.",
    ]],
    ['date' => '2026-09-02', 'items' => [
        "Message d'erreur explicite quand un upload de document/devis dépasse la taille limite.",
        "Remplacement des 3 dernières fenêtres de confirmation natives de Paramètres par la modale stylée maison.",
        "Réservation de l'ajout/suppression de documents et devis aux admins, mise à jour de l'aide Parc Machine.",
        "Ajout de l'onglet Devis à la fiche de vie machine et enrichissement de l'historique des BI.",
    ]],
    ['date' => '2026-08-30', 'items' => [
        "Ajout de la sélection multiple (rectangle + déplacement groupé) dans l'éditeur de Schéma Usine ; remplacement des icônes provisoires de l'application installable par le vrai logo.",
        "Ajout de formes industrielles au Schéma (convoyeurs, vis sans fin, tapis, bras robotisé) avec des illustrations dégradées, et corrections d'affichage à l'agrandissement/la rotation.",
        "Mise à jour de l'aide Parc Machine sur les catégories visuelles du schéma et la rotation de texte automatique.",
        "Ajout des catégories visuelles du Schéma (couleurs, effets, panneau de propriétés complet) et retrait d'un bouton de réinitialisation jugé trop dangereux.",
    ]],
    ['date' => '2026-08-29', 'items' => [
        "Modernisation de la page Paramètres (menu latéral, présentation épurée) et affinage de l'arborescence du Parc Machine.",
        "Correction d'un retour à la ligne indésirable dans le détail du SAS de validation.",
        "Ajout du matériel par défaut par type d'équipement, et refonte complète de la fiche de vie machine.",
        "Correction de l'affichage du SAS de validation et de la localisation précise du rapport.",
    ]],
    ['date' => '2026-08-28', 'items' => [
        "Ajout d'un système de matériel configurable sur la fiche technique et amélioration de l'onglet Interventions.",
        "Affichage du nom et de la localisation de la machine du Parc Machine dans la fiche technique.",
        "Correction du bouton Schéma pour les techniciens sur la fiche machine.",
    ]],
    ['date' => '2026-08-27', 'items' => [
        "Ajout d'un lien vers la démo publique sur la page de connexion.",
        "Mise en place d'une détection d'environnement démo, utilisée pour nettoyer automatiquement la démo publique.",
        "La démo publique n'affiche plus le vrai Schéma d'usine (confidentiel).",
        "Réorganisation et compacité de la fenêtre du rapport d'intervention, ajout de la bascule vers le préventif hivernal.",
        "Correction du calcul d'avance/retard 35h dans l'aperçu en direct de la modale planning.",
        "Refonte visuelle de la page KPI et rééquilibrage des indicateurs pannes/technicien.",
        "Schéma Usine : effets de forme (ombre, relief, creux, lueur), fond en dégradé, panneau de propriétés redimensionnable.",
    ]],
    ['date' => '2026-08-26', 'items' => [
        "Schéma Usine : texte déplaçable librement, forme « texte seul », zoom à la molette.",
        "Amélioration du Schéma Usine (agrandissement du canevas, navigation au glisser, courbes lissées).",
        "Ajout du Schéma Usine interactif, avec éditeur visuel complet.",
        "Amélioration du confort de l'onglet Travaux Hiver du Préventif.",
        "Ajout d'une colonne Usine, de zones gérables et de colonnes redimensionnables.",
    ]],
    ['date' => '2026-08-25', 'items' => [
        "Amélioration de la checklist Travaux Divers du Préventif (statut, filtres, ergonomie).",
        "Ajout d'une checklist saisonnière au Préventif, correction du transfert depuis le SAS.",
        "Documentation du transfert d'une demande vers une règle préventive.",
        "Ajout du transfert d'une demande en attente vers une règle préventive.",
    ]],
    ['date' => '2026-08-24', 'items' => [
        "Ajout d'une vue mensuelle aérée au Planning annuel sur mobile/tablette.",
    ]],
    ['date' => '2026-08-23', 'items' => [
        "Documentation du guide intelligent (détection de doublons) dans l'aide des OT.",
        "Ajout d'un guide intelligent, détectant les doublons, à l'assistant de création de bon d'intervention.",
        "Nettoyage des dernières traces du rôle « maintenance », supprimé.",
        "Remplacement des listes déroulantes de localisation par des tuiles dans le Préventif.",
        "Fiabilisation du calcul d'avance/retard de l'annualisation.",
        "Sécurisation des sauvegardes : les fichiers de sauvegarde (.bak) laissés dans les dossiers accessibles par le web (prod, démo) ont été déplacés hors de portée d'une URL — ils pouvaient auparavant être téléchargés par n'importe qui connaissant leur nom.",
    ]],
    ['date' => '2026-08-22', 'items' => [
        "Planning mobile : vue personnelle par technicien, agenda en cartes, et correctifs d'annualisation.",
        "Transformation de la GMAO en application installable (PWA) et refonte de l'ergonomie tablette/téléphone.",
    ]],
    ['date' => '2026-08-21', 'items' => [
        "Nettoyage du rôle « maintenance », ajout d'un bouton Créer BI sur le Parc Machine, et unification de la messagerie GMAO/Portail Services.",
        "Correction du fuseau horaire du préventif automatique, ajout de l'historique des BI par machine et de la localisation dans le SAS.",
        "Mise à jour de la fiche d'aide du Portail Services.",
    ]],
    ['date' => '2026-08-20', 'items' => [
        "Ajout de 5 pages d'aide détaillées : Sous-traitants, Parc Machine, Utilisateurs, Paramètres, Journal d'activité.",
        "Ajout de la page d'aide détaillée du Planning, avec captures d'écran réelles.",
        "Masquage des tuiles verrouillées pour les non-admins, agrandissement des titres/descriptions.",
        "Suppression de la page Annualisation, fusionnée dans le Planning annuel ; les techniciens peuvent gérer leur propre fractionnement.",
        "Correction du RTT (reste dû, distinct du Repos).",
        "Ajout d'une case « Repos » dans le planning, distincte du RTT.",
        "Ajout d'une bulle d'aide « Comprendre mon avance/retard » sur le Planning annuel.",
        "Planning annuel : postes affichés en badges plutôt qu'en case pleine couleur.",
        "Affinage visuel des badges d'écart annuel, masqués sur un jour vide.",
        "Un jour férié travaillé compte désormais comme un jour normal, badges plus lisibles.",
        "Amélioration du repère 35h/semaine (samedi/dimanche travaillés, écarts par jour/mois).",
        "Correction de la détection automatique des jours fériés (ignore astreinte/heures isolées).",
    ]],
    ['date' => '2026-08-19', 'items' => [
        "Planning : remplissage rapide sur plusieurs jours, et jours fériés français automatiques.",
        "Planning annuel : objectif éditable, fractionnement, semaines à 48h, alerte de plafond de congés payés.",
    ]],
    ['date' => '2026-08-18', 'items' => [
        "Planning : la grille tient désormais dans la fenêtre sans aucune barre de défilement.",
        "Planning : fenêtre « Planifier » rendue scrollable, ajout des badges CP/RTT/maladie/demi-CP.",
        "Planning annuel : un jour férié seul n'occupe plus toute la case en violet.",
        "Planning annuel : badge « JF » en texte, accolé à l'initiale du poste plutôt qu'un simple point.",
        "Planning annuel : badge rond dédié pour un jour férié travaillé.",
        "Planning : un jour férié peut désormais se cumuler avec un poste travaillé (matin/après-midi/nuit/journée).",
        "Correction du donut Curatif vs Préventif qui restait vide sur Stats Techniciens.",
        "Rafraîchissement des captures d'écran des pages d'aide.",
        "Ajout des sections Congés/RTT/Annualisation et Planning (à venir) au portail services.",
        "Correction du calcul d'annualisation (CP/RTT/fériés/maladie) et documentation des règles légales.",
    ]],
    ['date' => '2026-08-17', 'items' => [
        "Mise en ligne de la toute première version de la GMAO Gel'pam.",
    ]],
];
