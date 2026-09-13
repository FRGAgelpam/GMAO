<?php
require_once __DIR__ . '/session_init.php';
require 'db.php';
require 'csrf.php';
require_once __DIR__ . '/schema_usine_data.php';
schema_usine_bootstrap($db);

// --- SÉCURITÉ : réservé aux admins ---
if (!isset($_SESSION['user']) || strtolower($_SESSION['role']) !== 'admin') {
    header("Location: maintenance.php");
    exit();
}

$message = "";

// Défauts français d'origine de libelles_workflow (avant l'i18n) — sert à la fois à ne pas figer
// en base un libellé non personnalisé (edit_libelle) et à retomber sur la traduction courante quand
// le champ est vide en base (même correctif que edit_tuile/$TUILES_ACCUEIL un peu plus bas).
$LIBELLES_DEFAUTS_FR = [
    'attente' => 'En attente', 'afaire' => 'À faire', 'encours' => 'En cours', 'termine' => 'Terminée',
    'refuse' => 'Refusée', 'normal' => 'Normal', 'urgent' => 'Urgent',
];
$LIBELLES_DEFAUTS_TRAD = [
    'attente' => t('maint.lib_attente'), 'afaire' => t('maint.lib_afaire'), 'encours' => t('maint.lib_encours'),
    'termine' => t('maint.lib_termine'), 'refuse' => t('maint.lib_refuse'), 'normal' => t('maint.lib_normal'),
    'urgent' => t('maint.lib_urgent'),
];

// --- AUTO-MIGRATION ---
try {
    $db->exec("CREATE TABLE IF NOT EXISTS parametres_general (
        cle VARCHAR(50) PRIMARY KEY,
        valeur TEXT
    )");
    $db->exec("CREATE TABLE IF NOT EXISTS types_equipement (
        id INT AUTO_INCREMENT PRIMARY KEY,
        nom VARCHAR(100) UNIQUE,
        icone VARCHAR(60) DEFAULT 'fa-gear',
        ordre INT DEFAULT 0
    )");
    $db->exec("CREATE TABLE IF NOT EXISTS composant_types (
        id INT AUTO_INCREMENT PRIMARY KEY,
        nom VARCHAR(100) UNIQUE,
        ordre INT DEFAULT 0
    )");
    $db->exec("CREATE TABLE IF NOT EXISTS fiche_composants (
        id INT AUTO_INCREMENT PRIMARY KEY,
        zone_id INT,
        composant_type_id INT,
        valeur VARCHAR(255) DEFAULT '',
        UNIQUE KEY uniq_zone_composant (zone_id, composant_type_id)
    )");
    $db->exec("CREATE TABLE IF NOT EXISTS services (
        id INT AUTO_INCREMENT PRIMARY KEY,
        cle VARCHAR(60) UNIQUE,
        label VARCHAR(100),
        ordre INT DEFAULT 0
    )");
    $db->exec("CREATE TABLE IF NOT EXISTS preventif_categories (
        id INT AUTO_INCREMENT PRIMARY KEY,
        cle VARCHAR(30) UNIQUE,
        label VARCHAR(100),
        description VARCHAR(255),
        icone VARCHAR(60) DEFAULT 'fa-gear',
        couleur VARCHAR(20) DEFAULT '#3498db',
        ordre INT DEFAULT 0
    )");
    $db->exec("CREATE TABLE IF NOT EXISTS libelles_workflow (
        bucket VARCHAR(20) PRIMARY KEY,
        dimension VARCHAR(10),
        label VARCHAR(50),
        couleur VARCHAR(20),
        ordre INT
    )");
    $db->exec("CREATE TABLE IF NOT EXISTS planning_postes (
        cle VARCHAR(20) PRIMARY KEY,
        label VARCHAR(50),
        couleur VARCHAR(20),
        ordre INT,
        categorie VARCHAR(20) NOT NULL DEFAULT 'poste'
    )");
    $db->exec("ALTER TABLE planning_postes ADD COLUMN IF NOT EXISTS categorie VARCHAR(20) NOT NULL DEFAULT 'poste'");
    $db->exec("CREATE TABLE IF NOT EXISTS planning_astreintes (
        cle VARCHAR(20) PRIMARY KEY,
        label VARCHAR(50),
        couleur VARCHAR(20),
        ordre INT
    )");
    $db->exec("CREATE TABLE IF NOT EXISTS planning_shifts (
        id INT AUTO_INCREMENT PRIMARY KEY,
        utilisateur VARCHAR(100) NOT NULL,
        jour DATE NOT NULL,
        poste VARCHAR(20) NULL,
        astreinte VARCHAR(20) NULL,
        heures DECIMAL(5,2) NULL,
        note VARCHAR(255) NULL,
        UNIQUE KEY uniq_user_jour (utilisateur, jour)
    )");
    $db->exec("ALTER TABLE planning_shifts ADD COLUMN IF NOT EXISTS note VARCHAR(255) NULL");
    $db->exec("ALTER TABLE utilisateurs ADD COLUMN IF NOT EXISTS objectif_heures_annuel DECIMAL(6,2) NULL DEFAULT 1607");
    $db->exec("UPDATE utilisateurs SET objectif_heures_annuel = 1607 WHERE objectif_heures_annuel IS NULL");
    $db->exec("CREATE TABLE IF NOT EXISTS tuiles_couleurs (href VARCHAR(100) PRIMARY KEY, couleur VARCHAR(20))");
    $db->exec("ALTER TABLE tuiles_couleurs ADD COLUMN IF NOT EXISTS titre VARCHAR(60) NULL");
    $db->exec("ALTER TABLE tuiles_couleurs ADD COLUMN IF NOT EXISTS description VARCHAR(255) NULL");
    $db->exec("ALTER TABLE tuiles_couleurs ADD COLUMN IF NOT EXISTS icone VARCHAR(60) NULL");

    if ($db->query("SELECT COUNT(*) FROM parametres_general")->fetchColumn() == 0) {
        $stmt = $db->prepare("INSERT INTO parametres_general (cle, valeur) VALUES (?, ?)");
        $stmt->execute(['nom_entreprise', "GMAO"]);
        $stmt->execute(['logo_path', 'img/logo.png']);
    }

    if ($db->query("SELECT COUNT(*) FROM types_equipement")->fetchColumn() == 0) {
        $defauts = [
            ['Mécanique', 'fa-screwdriver-wrench'],
            ['Électrique', 'fa-bolt'],
            ['Froid / Réfrigération', 'fa-snowflake'],
            ['Pneumatique / Hydraulique', 'fa-wind'],
            ['Instrumentation / Régulation', 'fa-gauge-high'],
            ['Convoyage', 'fa-arrows-left-right'],
            ['Emballage / Conditionnement', 'fa-box'],
        ];
        $stmt = $db->prepare("INSERT INTO types_equipement (nom, icone, ordre) VALUES (?, ?, ?)");
        foreach ($defauts as $i => $d) { $stmt->execute([$d[0], $d[1], $i]); }
    }

    if ($db->query("SELECT COUNT(*) FROM composant_types")->fetchColumn() == 0) {
        $defauts = ['Référence moteur', 'N° moteur', 'Référence réducteur', 'Référence courroie', 'N° câble', 'Roulement avant', 'Roulement arrière'];
        $stmt = $db->prepare("INSERT INTO composant_types (nom, ordre) VALUES (?, ?)");
        foreach ($defauts as $i => $nom) { $stmt->execute([$nom, $i]); }
    }

    if ($db->query("SELECT COUNT(*) FROM services")->fetchColumn() == 0) {
        $defauts = [
            ['production', 'Production'],
            ['rh', 'Ressources Humaines'],
            ['direction', 'Direction'],
            ['comptabilite', 'Comptabilité'],
            ['qualite', 'Qualité'],
            ['securite', 'Sécurité'],
            ['qhse', 'QHSE (Qualité & Sécurité)'],
            ['logistique', 'Logistique'],
            ['agronomie', 'Agronomie'],
        ];
        $stmt = $db->prepare("INSERT INTO services (cle, label, ordre) VALUES (?, ?, ?)");
        foreach ($defauts as $i => $d) { $stmt->execute([$d[0], $d[1], $i]); }
    }

    if ($db->query("SELECT COUNT(*) FROM preventif_categories")->fetchColumn() == 0) {
        $defauts = [
            ['process', 'Process', 'Gammes liées au fonctionnement des machines', 'fa-diagram-project', '#3498db'],
            ['audit', 'Qualité', "Contrôles et vérifications d'audit qualité", 'fa-clipboard-check', '#9b59b6'],
            ['reglementaire', 'Réglementaire', 'Inspections obligatoires (VGP, sécurité...)', 'fa-scale-balanced', '#c0392b'],
            ['quotidien', 'Quotidien', 'Gestes de routine : graissage, contrôles visuels', 'fa-oil-can', '#2ecc71'],
        ];
        $stmt = $db->prepare("INSERT INTO preventif_categories (cle, label, description, icone, couleur, ordre) VALUES (?, ?, ?, ?, ?, ?)");
        foreach ($defauts as $i => $d) { $stmt->execute([$d[0], $d[1], $d[2], $d[3], $d[4], $i]); }
    }

    if ($db->query("SELECT COUNT(*) FROM libelles_workflow")->fetchColumn() == 0) {
        $defauts = [
            ['attente', 'statut', 'En attente', '#95a5a6', 0],
            ['afaire', 'statut', 'À faire', '#f39c12', 1],
            ['encours', 'statut', 'En cours', '#3498db', 2],
            ['termine', 'statut', 'Terminée', '#27ae60', 3],
            ['refuse', 'statut', 'Refusée', '#e74c3c', 4],
            ['normal', 'priorite', 'Normal', '#7f8c8d', 0],
            ['urgent', 'priorite', 'Urgent', '#e74c3c', 1],
        ];
        $stmt = $db->prepare("INSERT INTO libelles_workflow (bucket, dimension, label, couleur, ordre) VALUES (?, ?, ?, ?, ?)");
        foreach ($defauts as $d) { $stmt->execute($d); }
    }

    if ($db->query("SELECT COUNT(*) FROM planning_postes")->fetchColumn() == 0) {
        $defauts = [
            ['matin', 'Matin', '#f1c40f', 0],
            ['apres_midi', 'Après-midi', '#2ecc71', 1],
            ['nuit', 'Nuit', '#3498db', 2],
            ['journee', 'Journée', '#d5d8dc', 3],
        ];
        $stmt = $db->prepare("INSERT INTO planning_postes (cle, label, couleur, ordre) VALUES (?, ?, ?, ?)");
        foreach ($defauts as $d) { $stmt->execute($d); }
    }

    // Ajout additif (n'écrase pas les libellés déjà personnalisés par l'admin sur les postes existants)
    $stmtAbsence = $db->prepare("INSERT IGNORE INTO planning_postes (cle, label, couleur, ordre, categorie) VALUES (?, ?, ?, ?, 'evenement')");
    foreach ([['cp', 'Congé payé', '#e67e22', 4], ['maladie', 'Maladie', '#e74c3c', 5], ['rtt', 'RTT', '#9b59b6', 6]] as $d) {
        $stmtAbsence->execute($d);
    }
    $db->exec("UPDATE planning_postes SET categorie='evenement' WHERE cle IN ('cp','maladie','rtt')");

    // Ajout du "Demi-congé payé" en 1re position des événements, devant "Congé payé" (migration à usage
    // unique : ne décale les ordres existants que la première fois, pour ne pas les re-décaler à chaque chargement).
    if ((int)$db->query("SELECT COUNT(*) FROM planning_postes WHERE cle = 'demi_cp'")->fetchColumn() === 0) {
        $db->exec("UPDATE planning_postes SET ordre = ordre + 1 WHERE cle IN ('cp', 'maladie', 'rtt')");
        $db->prepare("INSERT INTO planning_postes (cle, label, couleur, ordre, categorie) VALUES (?, ?, ?, ?, 'evenement')")
            ->execute(['demi_cp', 'Demi-congé payé', '#f0b27a', 4]);
    }

    // "Jour férié" : partage le bouton "Journée" en deux moitiés cliquables sur le planning (voir
    // planning.php) plutôt que d'ajouter une 5e case — même famille de poste, pas un événement à part.
    if ((int)$db->query("SELECT COUNT(*) FROM planning_postes WHERE cle = 'jour_ferie'")->fetchColumn() === 0) {
        $db->prepare("INSERT INTO planning_postes (cle, label, couleur, ordre, categorie) VALUES (?, ?, ?, ?, 'poste')")
            ->execute(['jour_ferie', 'Jour férié', '#1abc9c', 3]);
    }

    // "Repos" : jour de repos hebdomadaire d'une rotation variable (samedi/dimanche/n'importe quel jour de
    // semaine selon le planning), distinct du RTT — ne consomme aucun crédit d'heures, compté à 0h dû comme
    // CP/RTT (voir referenceDuJour() dans planning.php).
    if ((int)$db->query("SELECT COUNT(*) FROM planning_postes WHERE cle = 'repos'")->fetchColumn() === 0) {
        $db->prepare("INSERT INTO planning_postes (cle, label, couleur, ordre, categorie) VALUES (?, ?, ?, ?, 'evenement')")
            ->execute(['repos', 'Repos', '#7f8c8d', 7]);
    }

    if ($db->query("SELECT COUNT(*) FROM planning_astreintes")->fetchColumn() == 0) {
        $defauts = [
            ['classique', 'Astreinte', '#e74c3c', 0],
            ['froid', 'Astreinte froid', '#3498db', 1],
        ];
        $stmt = $db->prepare("INSERT INTO planning_astreintes (cle, label, couleur, ordre) VALUES (?, ?, ?, ?)");
        foreach ($defauts as $d) { $stmt->execute($d); }
    }
} catch (Exception $e) {
    error_log("parametres.php migration: " . $e->getMessage());
}

$ICONES_DISPONIBLES = [
    'fa-gear' => t('param.icon_general'), 'fa-screwdriver-wrench' => t('param.icon_mecanique'), 'fa-bolt' => t('param.icon_electrique'),
    'fa-snowflake' => t('param.icon_froid'), 'fa-wind' => t('param.icon_air_pneumatique'), 'fa-gauge-high' => t('param.icon_instrumentation'),
    'fa-arrows-left-right' => t('param.icon_convoyage'), 'fa-box' => t('param.icon_emballage'), 'fa-industry' => t('param.icon_industrie'),
    'fa-fire' => t('param.icon_chaleur'), 'fa-droplet' => t('param.icon_eau_fluides'), 'fa-fan' => t('param.icon_ventilation'),
    'fa-plug' => t('param.icon_electrique_prise'), 'fa-oil-can' => t('param.icon_lubrification'), 'fa-truck' => t('param.icon_logistique'),
    'fa-robot' => t('param.icon_automatisme'), 'fa-microchip' => t('param.icon_electronique'), 'fa-toolbox' => t('param.icon_outillage'),
    'fa-building' => t('param.icon_batiment'), 'fa-shield-halved' => t('param.icon_securite'),
];

// Même familles/libellés que le sélecteur de forme de l'éditeur de schéma (admin_machines.php,
// SU_SHAPE_FAMILIES) — dupliqué ici en PHP pour construire le <select> "Forme" des catégories de
// schéma sans avoir à réimporter tout le picker visuel (grille d'icônes) dans cette page.
$SHAPE_FAMILIES_SCHEMA = [
    t('pm.shapefam_texte') => ['text_only' => t('pm.shape_text_only')],
    t('pm.shapefam_industriel') => [
        'convoyeur_rouleaux' => t('pm.shape_convoyeur_rouleaux'), 'convoyeur_rouleaux_arc' => t('pm.shape_convoyeur_rouleaux_arc'), 'tapis_chevron' => t('pm.shape_tapis_chevron'), 'stadium' => t('pm.shape_stadium_convoyeur'),
        'vis_sans_fin' => t('pm.shape_vis_sans_fin'), 'robot_bras' => t('pm.shape_robot_bras'),
    ],
    t('pm.shapefam_rectangles') => [
        'rect' => t('pm.shape_rect'), 'roundRect' => t('pm.shape_roundrect'), 'bevel_rect' => t('pm.shape_bevel_rect'),
        'rect_snip_1' => t('pm.shape_rect_snip_1'), 'rect_snip_2_same' => t('pm.shape_rect_snip_2_same'), 'rect_snip_diag' => t('pm.shape_rect_snip_diag'), 'rect_round_1' => t('pm.shape_rect_round_1'),
        'parallelogram' => t('pm.shape_parallelogram'), 'trapezoid' => t('pm.shape_trapezoid'), 'trapezoid_inv' => t('pm.shape_trapezoid_inv'),
        'diamond' => t('pm.shape_diamond'), 'l_shape' => t('pm.shape_l_shape'), 't_shape' => t('pm.shape_t_shape'),
    ],
    t('pm.shapefam_cercles') => [
        'ellipse' => t('pm.shape_ellipse'), 'semicircle' => t('pm.shape_semicircle'), 'quarter_circle' => t('pm.shape_quarter_circle'),
        'elbow' => t('pm.shape_elbow'),
    ],
    t('pm.shapefam_triangles') => [
        'triangle' => t('pm.shape_triangle'), 'triangle_down' => t('pm.shape_triangle_down'), 'triangle_left' => t('pm.shape_triangle_left'), 'triangle_right' => t('pm.shape_triangle_right'),
        'right_triangle' => t('pm.shape_right_triangle'), 'rt_tl' => t('pm.shape_rt_tl'), 'rt_tr' => t('pm.shape_rt_tr'), 'rt_br' => t('pm.shape_rt_br'),
    ],
    t('pm.shapefam_polygones') => [
        'pentagon' => t('pm.shape_pentagon'), 'hexagon' => t('pm.shape_hexagon'), 'hexagon_v' => t('pm.shape_hexagon_v'),
        'heptagon' => t('pm.shape_heptagon'), 'octagon' => t('pm.shape_octagon'), 'decagon' => t('pm.shape_decagon'), 'dodecagon' => t('pm.shape_dodecagon'),
    ],
    t('pm.shapefam_etoiles') => [
        'star' => t('pm.shape_star'), 'cross' => t('pm.shape_cross'), 'shield' => t('pm.shape_shield'), 'bowtie' => t('pm.shape_bowtie'),
    ],
    t('pm.shapefam_fleches') => [
        'arrow_right' => t('pm.shape_arrow_right'), 'arrow_left' => t('pm.shape_arrow_left'), 'arrow_up' => t('pm.shape_arrow_up'), 'arrow_down' => t('pm.shape_arrow_down'),
        'double_arrow_h' => t('pm.shape_double_arrow_h'), 'double_arrow_v' => t('pm.shape_double_arrow_v'), 'arrow_cross' => t('pm.shape_arrow_cross'),
        'chevron_right' => t('pm.shape_chevron_right'), 'chevron_left' => t('pm.shape_chevron_left'),
        'plate_left' => t('pm.shape_plate_left'), 'plate_right' => t('pm.shape_plate_right'),
        'ribbon_right' => t('pm.shape_ribbon_right'), 'ribbon_left' => t('pm.shape_ribbon_left'),
    ],
    t('pm.shapefam_equation') => [
        'minus_sign' => t('pm.shape_minus_sign'), 'multiply_x' => t('pm.shape_multiply_x'),
    ],
    t('pm.shapefam_organigramme') => [
        'stadium' => t('pm.shape_stadium_terminateur'), 'document' => t('pm.shape_document'), 'manual_input' => t('pm.shape_manual_input'),
        'off_page_connector' => t('pm.shape_off_page_connector'), 'delay' => t('pm.shape_delay'), 'punched_tape' => t('pm.shape_punched_tape'),
    ],
];

$COULEURS_DISPONIBLES = [
    '#3498db', '#2980b9', '#5dade2', '#2c3e50', '#34495e',
    '#9b59b6', '#8e44ad', '#a569bd', '#6c5ce7', '#d980fa',
    '#e74c3c', '#c0392b', '#ec7063', '#e84393', '#fd79a8',
    '#f39c12', '#d35400', '#f5b041', '#f1c40f', '#f4d03f',
    '#2ecc71', '#27ae60', '#58d68d', '#00b894', '#16a085',
    '#1abc9c', '#48c9b0', '#7f8c8d', '#95a5a6', '#607d8b',
];
// Une couleur "libre" (choisie via un color picker) est acceptée en plus de cette liste,
// tant qu'elle respecte le format hexadécimal #rrggbb.
function couleur_est_valide($valeur) {
    return is_string($valeur) && preg_match('/^#[0-9a-fA-F]{6}$/', $valeur) === 1;
}

// Catalogue des tuiles de la page d'accueil (doit rester synchronisé avec le tableau $tuiles d'index.php).
// Sert à proposer leur personnalisation ici (titre, description, couleur) ; ce qui est enregistré dans
// tuiles_couleurs prime sur les valeurs par défaut définies dans index.php.
$TUILES_ACCUEIL = [
    ['href' => 'maintenance.php',    'icon' => 'fa-pen-to-square',      'titre' => 'Saisie & Historique',  'desc' => 'Créer et suivre les bons d\'intervention', 'defaut' => '#f39c12'],
    ['href' => 'planning.php',       'icon' => 'fa-calendar-days',      'titre' => 'Planning',              'desc' => 'Organiser les interventions à venir', 'defaut' => '#3498db'],
    ['href' => 'sous_traitants.php', 'icon' => 'fa-users-gear',         'titre' => 'Sous-traitants',        'desc' => 'Gérer les entreprises extérieures', 'defaut' => '#9b59b6'],
    ['href' => 'preventif.php',      'icon' => 'fa-calendar-check',     'titre' => 'Préventif',             'desc' => 'Suivre le plan de maintenance préventive', 'defaut' => '#2ecc71'],
    ['href' => 'stats_tech.php',     'icon' => 'fa-user-clock',         'titre' => 'Stats Technicien',      'desc' => 'Analyser l\'activité de chaque technicien', 'defaut' => '#2980b9'],
    ['href' => 'kpi.php',            'icon' => 'fa-chart-line',         'titre' => 'KPI',                   'desc' => 'Visualiser les indicateurs de performance', 'defaut' => '#c0392b'],
    ['href' => 'admin_machines.php', 'icon' => 'fa-gears',              'titre' => 'Parc Machine',           'desc' => 'Consulter et gérer le parc de machines', 'defaut' => '#e84393'],
    ['href' => 'admin_reset.php',    'icon' => 'fa-user-shield',        'titre' => 'Gestion Utilisateurs',  'desc' => 'Gérer les comptes et les droits d\'accès', 'defaut' => '#e67e22'],
    ['href' => 'idees_admin.php',    'icon' => 'fa-lightbulb',          'titre' => 'Idées reçues',          'desc' => 'Suggestions d\'amélioration envoyées par les services', 'defaut' => '#f1c40f'],
    ['href' => 'parametres.php',     'icon' => 'fa-sliders',            'titre' => 'Paramètres',            'desc' => 'Configurer les listes, catégories et réglages généraux', 'defaut' => '#1abc9c'],
    ['href' => 'logs.php',           'icon' => 'fa-clock-rotate-left',  'titre' => "Journal d'activité",    'desc' => 'Consulter l\'historique des actions effectuées', 'defaut' => '#607d8b'],
    ['href' => 'accueil.php',        'icon' => 'fa-right-left',         'titre' => 'Portail Services',      'desc' => 'Aperçu de l\'espace demandeur (Production, Qualité...)', 'defaut' => '#8e44ad'],
    ['href' => 'aide.php',           'icon' => 'fa-circle-question',    'titre' => 'Aide',                  'desc' => 'Consulter le mode opératoire', 'defaut' => '#16a085'],
];

// Icônes disponibles pour les dossiers personnalisés ET pour les tuiles fixes (même liste élargie
// que sur l'accueil — inclut les icônes d'origine de chaque tuile fixe, pour qu'enregistrer sans
// toucher à l'icône reste toujours valide).
$ICONES_DOSSIER = [
    'fa-folder' => t('param.icon_dossier'), 'fa-folder-open' => t('param.icon_dossier_ouvert'), 'fa-layer-group' => t('param.icon_groupe'),
    'fa-boxes-stacked' => t('param.icon_boites'), 'fa-toolbox' => t('param.icon_outils'), 'fa-clipboard-list' => t('param.icon_liste'),
    'fa-chart-pie' => t('param.icon_statistiques'), 'fa-users' => t('param.icon_equipe'), 'fa-gear' => t('param.icon_reglages'),
    'fa-house-chimney' => t('param.icon_maison'), 'fa-wrench' => t('param.icon_maintenance'), 'fa-bell' => t('param.icon_notifications'),
    'fa-flag' => t('param.icon_drapeau'), 'fa-shield-halved' => t('param.icon_securite'), 'fa-building' => t('param.icon_batiment'),
    'fa-truck' => t('param.icon_logistique'), 'fa-industry' => t('param.icon_industrie'), 'fa-clock' => t('param.icon_temps'),
    'fa-star' => t('param.icon_favori'), 'fa-bookmark' => t('param.icon_marque_page'),
    'fa-pen-to-square' => t('param.icon_edition'), 'fa-calendar-days' => t('param.icon_calendrier'), 'fa-users-gear' => t('param.icon_gestion_equipe'),
    'fa-hourglass-half' => t('param.icon_suivi_temps'), 'fa-calendar-check' => t('param.icon_planification'), 'fa-user-clock' => t('param.icon_suivi_horaire'),
    'fa-chart-line' => t('param.icon_performance'), 'fa-gears' => t('param.icon_mecanique'), 'fa-user-shield' => t('param.icon_securite_utilisateur'),
    'fa-lightbulb' => t('param.icon_idee'), 'fa-sliders' => t('param.icon_parametres'), 'fa-clock-rotate-left' => t('param.icon_historique'),
    'fa-right-left' => t('param.icon_echange'), 'fa-circle-question' => t('param.icon_aide'),
];

// Charge/sauvegarde les dossiers personnalisés de L'UTILISATEUR COURANT (stockés dans tuiles_ordre,
// propre à chaque utilisateur — un dossier créé par David n'existe que sur son propre compte).
function charger_layout_utilisateur($db, $utilisateur) {
    $db->exec("CREATE TABLE IF NOT EXISTS tuiles_ordre (utilisateur VARCHAR(100) PRIMARY KEY, ordre TEXT)");
    $stmt = $db->prepare("SELECT ordre FROM tuiles_ordre WHERE utilisateur = ?");
    $stmt->execute([$utilisateur]);
    $decode = json_decode((string)$stmt->fetchColumn(), true);
    if (!is_array($decode)) { return []; }
    return array_key_exists('layout', $decode) && is_array($decode['layout']) ? $decode['layout'] : (is_array($decode) ? $decode : []);
}
function sauvegarder_layout_utilisateur($db, $utilisateur, $layout) {
    $db->exec("CREATE TABLE IF NOT EXISTS tuiles_ordre (utilisateur VARCHAR(100) PRIMARY KEY, ordre TEXT)");
    $stmtMasquees = $db->prepare("SELECT ordre FROM tuiles_ordre WHERE utilisateur = ?");
    $stmtMasquees->execute([$utilisateur]);
    $decodeActuel = json_decode((string)$stmtMasquees->fetchColumn(), true);
    $masquees = (is_array($decodeActuel) && is_array($decodeActuel['masquees'] ?? null)) ? $decodeActuel['masquees'] : [];
    $stmt = $db->prepare("INSERT INTO tuiles_ordre (utilisateur, ordre) VALUES (?, ?) ON DUPLICATE KEY UPDATE ordre = VALUES(ordre)");
    $stmt->execute([$utilisateur, json_encode(['layout' => $layout, 'masquees' => $masquees])]);
}

function genererCle($label) {
    $cle = strtolower(trim($label));
    $cle = str_replace(['é', 'è', 'ê', 'ë'], 'e', $cle);
    $cle = str_replace(['à', 'â'], 'a', $cle);
    $cle = str_replace(['î', 'ï'], 'i', $cle);
    $cle = str_replace('ô', 'o', $cle);
    $cle = str_replace(['û', 'ù'], 'u', $cle);
    $cle = str_replace('ç', 'c', $cle);
    $cle = preg_replace('/[^a-z0-9]+/', '_', $cle);
    return trim($cle, '_');
}

function deplacerElement($db, $table, $id, $direction) {
    if (!in_array($table, ['types_equipement', 'services', 'preventif_categories', 'composant_types', 'schema_categories_visuelles'], true)) return;
    $stmt = $db->prepare("SELECT id, ordre FROM $table WHERE id=?");
    $stmt->execute([$id]);
    $cur = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$cur) return;
    if ($direction === 'up') {
        $stmtN = $db->prepare("SELECT id, ordre FROM $table WHERE ordre < ? ORDER BY ordre DESC LIMIT 1");
    } else {
        $stmtN = $db->prepare("SELECT id, ordre FROM $table WHERE ordre > ? ORDER BY ordre ASC LIMIT 1");
    }
    $stmtN->execute([$cur['ordre']]);
    $voisin = $stmtN->fetch(PDO::FETCH_ASSOC);
    if ($voisin) {
        $db->prepare("UPDATE $table SET ordre=? WHERE id=?")->execute([$voisin['ordre'], $cur['id']]);
        $db->prepare("UPDATE $table SET ordre=? WHERE id=?")->execute([$cur['ordre'], $voisin['id']]);
    }
}

$active_tab = $_POST['tab_actif'] ?? ($_GET['tab'] ?? 'general');
if (!in_array($active_tab, ['general', 'types', 'materiel', 'services', 'categories', 'workflow', 'planning', 'tuiles', 'schema_categories'], true)) { $active_tab = 'general'; }

// --- ACTIONS POST ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verifie($_POST['csrf_token'] ?? '')) {
        $message = "<div class='alert danger'>" . t('param.msg_session_expiree') . "</div>";
    } else {
        $action = $_POST['action'] ?? '';
        try {
            if ($action === 'save_general') {
                $nom = trim($_POST['nom_entreprise']);
                if ($nom !== '') {
                    $db->prepare("UPDATE parametres_general SET valeur=? WHERE cle='nom_entreprise'")->execute([$nom]);
                }
                $db->prepare("INSERT INTO parametres_general (cle, valeur) VALUES ('url_portail', ?) ON DUPLICATE KEY UPDATE valeur=VALUES(valeur)")->execute([trim($_POST['url_portail'] ?? '')]);
                $db->prepare("INSERT INTO parametres_general (cle, valeur) VALUES ('url_demo', ?) ON DUPLICATE KEY UPDATE valeur=VALUES(valeur)")->execute([trim($_POST['url_demo'] ?? '')]);
                if (isset($_FILES['logo']) && $_FILES['logo']['error'] === UPLOAD_ERR_OK) {
                    $ext = strtolower(pathinfo($_FILES['logo']['name'], PATHINFO_EXTENSION));
                    if (in_array($ext, ['png', 'jpg', 'jpeg', 'svg', 'webp'], true)) {
                        $filename = 'logo_' . time() . '.' . $ext;
                        $dest = __DIR__ . '/img/' . $filename;
                        if (move_uploaded_file($_FILES['logo']['tmp_name'], $dest)) {
                            $db->prepare("UPDATE parametres_general SET valeur=? WHERE cle='logo_path'")->execute(['img/' . $filename]);
                        }
                    } else {
                        $message = "<div class='alert danger'>" . t('param.msg_logo_format') . "</div>";
                    }
                }
                ajouterLog($db, $_SESSION['user'], "Paramètres", "A modifié les informations générales.");
                if (empty($message)) { $message = "<div class='alert success'><i class='fa-solid fa-check-circle'></i> " . t('param.msg_general_ok') . "</div>"; }

            } elseif ($action === 'add_type') {
                $nom = trim($_POST['nom']);
                $icone = array_key_exists($_POST['icone'] ?? '', $ICONES_DISPONIBLES) ? $_POST['icone'] : 'fa-gear';
                if ($nom === '') {
                    $message = "<div class='alert danger'>" . t('param.msg_nom_type_obligatoire') . "</div>";
                } else {
                    $chk = $db->prepare("SELECT COUNT(*) FROM types_equipement WHERE nom=?");
                    $chk->execute([$nom]);
                    if ($chk->fetchColumn() > 0) {
                        $message = "<div class='alert danger'>" . t('param.msg_type_existe') . "</div>";
                    } else {
                        $maxO = $db->query("SELECT COALESCE(MAX(ordre),-1) FROM types_equipement")->fetchColumn();
                        $db->prepare("INSERT INTO types_equipement (nom, icone, ordre) VALUES (?, ?, ?)")->execute([$nom, $icone, $maxO + 1]);
                        ajouterLog($db, $_SESSION['user'], "Paramètres", "A ajouté le type d'équipement : $nom");
                        $message = "<div class='alert success'>" . t('param.msg_type_ajoute') . "</div>";
                    }
                }

            } elseif ($action === 'edit_type') {
                $id = (int)$_POST['id'];
                $nom = trim($_POST['nom']);
                $icone = array_key_exists($_POST['icone'] ?? '', $ICONES_DISPONIBLES) ? $_POST['icone'] : 'fa-gear';
                if ($nom === '') {
                    $message = "<div class='alert danger'>" . t('param.msg_nom_type_obligatoire') . "</div>";
                } else {
                    $chk = $db->prepare("SELECT COUNT(*) FROM types_equipement WHERE nom=? AND id<>?");
                    $chk->execute([$nom, $id]);
                    if ($chk->fetchColumn() > 0) {
                        $message = "<div class='alert danger'>" . t('param.msg_type_existe') . "</div>";
                    } else {
                        $stmtOld = $db->prepare("SELECT nom FROM types_equipement WHERE id=?");
                        $stmtOld->execute([$id]);
                        $oldNom = $stmtOld->fetchColumn();
                        $db->prepare("UPDATE types_equipement SET nom=?, icone=? WHERE id=?")->execute([$nom, $icone, $id]);
                        if ($oldNom !== false && $oldNom !== $nom) {
                            $db->prepare("UPDATE machines SET type_equipement=? WHERE type_equipement=?")->execute([$nom, $oldNom]);
                        }
                        ajouterLog($db, $_SESSION['user'], "Paramètres", "A modifié le type d'équipement : $nom");
                        $message = "<div class='alert success'>" . t('param.msg_type_modifie') . "</div>";
                    }
                }

            } elseif ($action === 'delete_type') {
                $id = (int)$_POST['id'];
                $stmt = $db->prepare("SELECT nom FROM types_equipement WHERE id=?");
                $stmt->execute([$id]);
                $nom = $stmt->fetchColumn();
                $enUsage = $db->prepare("SELECT COUNT(*) FROM machines WHERE type_equipement=?");
                $enUsage->execute([$nom]);
                if ($enUsage->fetchColumn() > 0) {
                    $message = "<div class='alert danger'>" . t('param.msg_type_en_usage') . "</div>";
                } else {
                    $db->prepare("DELETE FROM types_equipement WHERE id=?")->execute([$id]);
                    ajouterLog($db, $_SESSION['user'], "Paramètres", "A supprimé le type d'équipement : $nom");
                    $message = "<div class='alert success'>" . t('param.msg_type_supprime') . "</div>";
                }

            } elseif ($action === 'move_type') {
                deplacerElement($db, 'types_equipement', (int)$_POST['id'], $_POST['direction']);

            } elseif ($action === 'save_type_composants') {
                $type_id = (int)$_POST['type_id'];
                $composant_ids = array_map('intval', $_POST['composant_type_ids'] ?? []);
                $stmtNomType = $db->prepare("SELECT nom FROM types_equipement WHERE id=?");
                $stmtNomType->execute([$type_id]);
                $nomType = $stmtNomType->fetchColumn();
                if ($nomType === false) {
                    $message = "<div class='alert danger'>" . t('param.msg_type_introuvable') . "</div>";
                } else {
                    $db->prepare("DELETE FROM type_equipement_composants WHERE type_equipement_id=?")->execute([$type_id]);
                    if ($composant_ids) {
                        $insMap = $db->prepare("INSERT IGNORE INTO type_equipement_composants (type_equipement_id, composant_type_id) VALUES (?, ?)");
                        foreach ($composant_ids as $cid) { $insMap->execute([$type_id, $cid]); }
                    }
                    appliquer_composants_defaut_type($db, $nomType);
                    ajouterLog($db, $_SESSION['user'], "Paramètres", "A mis à jour le matériel par défaut du type d'équipement : $nomType");
                    $message = "<div class='alert success'>" . t('param.msg_materiel_defaut_ok') . "</div>";
                }

            } elseif ($action === 'add_composant') {
                $nom = trim($_POST['nom']);
                if ($nom === '') {
                    $message = "<div class='alert danger'>" . t('param.msg_nom_materiel_obligatoire') . "</div>";
                } else {
                    $chk = $db->prepare("SELECT COUNT(*) FROM composant_types WHERE nom=?");
                    $chk->execute([$nom]);
                    if ($chk->fetchColumn() > 0) {
                        $message = "<div class='alert danger'>" . t('param.msg_materiel_existe') . "</div>";
                    } else {
                        $maxO = $db->query("SELECT COALESCE(MAX(ordre),-1) FROM composant_types")->fetchColumn();
                        $db->prepare("INSERT INTO composant_types (nom, ordre) VALUES (?, ?)")->execute([$nom, $maxO + 1]);
                        ajouterLog($db, $_SESSION['user'], "Paramètres", "A ajouté le type de matériel : $nom");
                        $message = "<div class='alert success'>" . t('param.msg_materiel_ajoute') . "</div>";
                    }
                }

            } elseif ($action === 'edit_composant') {
                $id = (int)$_POST['id'];
                $nom = trim($_POST['nom']);
                if ($nom === '') {
                    $message = "<div class='alert danger'>" . t('param.msg_nom_materiel_obligatoire') . "</div>";
                } else {
                    $chk = $db->prepare("SELECT COUNT(*) FROM composant_types WHERE nom=? AND id<>?");
                    $chk->execute([$nom, $id]);
                    if ($chk->fetchColumn() > 0) {
                        $message = "<div class='alert danger'>" . t('param.msg_materiel_existe') . "</div>";
                    } else {
                        $db->prepare("UPDATE composant_types SET nom=? WHERE id=?")->execute([$nom, $id]);
                        ajouterLog($db, $_SESSION['user'], "Paramètres", "A modifié le type de matériel : $nom");
                        $message = "<div class='alert success'>" . t('param.msg_materiel_modifie') . "</div>";
                    }
                }

            } elseif ($action === 'delete_composant') {
                $id = (int)$_POST['id'];
                $stmt = $db->prepare("SELECT nom FROM composant_types WHERE id=?");
                $stmt->execute([$id]);
                $nom = $stmt->fetchColumn();
                $enUsage = $db->prepare("SELECT COUNT(*) FROM fiche_composants WHERE composant_type_id=?");
                $enUsage->execute([$id]);
                if ($enUsage->fetchColumn() > 0) {
                    $message = "<div class='alert danger'>" . t('param.msg_materiel_en_usage') . "</div>";
                } else {
                    $db->prepare("DELETE FROM composant_types WHERE id=?")->execute([$id]);
                    ajouterLog($db, $_SESSION['user'], "Paramètres", "A supprimé le type de matériel : $nom");
                    $message = "<div class='alert success'>" . t('param.msg_materiel_supprime') . "</div>";
                }

            } elseif ($action === 'move_composant') {
                deplacerElement($db, 'composant_types', (int)$_POST['id'], $_POST['direction']);

            } elseif ($action === 'add_categorie_schema' || $action === 'edit_categorie_schema') {
                $nom = trim($_POST['nom'] ?? '');
                $fill = preg_match('/^#[0-9a-fA-F]{6}$/', $_POST['fill_color'] ?? '') ? $_POST['fill_color'] : '#3498db';
                $gradient = !empty($_POST['gradient']) ? 1 : 0;
                $fill2Raw = trim($_POST['fill_color2'] ?? '');
                $fill2 = ($gradient && preg_match('/^#[0-9a-fA-F]{6}$/', $fill2Raw)) ? $fill2Raw : null;
                $sansBordure = !empty($_POST['border_none']);
                $borderRaw = trim($_POST['border_color'] ?? '');
                $border = (!$sansBordure && preg_match('/^#[0-9a-fA-F]{6}$/', $borderRaw)) ? $borderRaw : null;
                $effect = in_array($_POST['effect'] ?? '', ['none', 'shadow', 'bevel', 'inset', 'glow'], true) ? $_POST['effect'] : 'none';
                $gradientAngleRaw = (int)($_POST['gradient_angle'] ?? 135);
                $gradientAngle = in_array($gradientAngleRaw, [0, 90, 135, 180, 270], true) ? $gradientAngleRaw : 135;

                // Valeurs de départ : forme/taille/rotation/texte/comportement. Uniquement appliquées
                // au moment où on choisit la catégorie sur une forme (voir JS) — jamais réimposées après
                // coup, contrairement à l'apparence ci-dessus. C'est pourquoi elles ne figurent PAS dans
                // l'UPDATE rétroactif de schema_zones plus bas.
                $shapesAutorisees = ['rect', 'roundRect', 'ellipse', 'triangle', 'diamond', 'pentagon', 'hexagon', 'star', 'arrow_right', 'arrow_left', 'arrow_up', 'arrow_down', 'parallelogram', 'octagon', 'trapezoid', 'trapezoid_inv', 'cross', 'chevron_right', 'chevron_left', 'right_triangle', 'semicircle', 'double_arrow_h', 'double_arrow_v', 'bevel_rect', 'plate_left', 'plate_right', 'triangle_down', 'triangle_left', 'triangle_right', 'quarter_circle', 'hexagon_v', 'l_shape', 't_shape', 'rt_tl', 'rt_tr', 'rt_br', 'shield', 'ribbon_right', 'ribbon_left', 'rect_snip_1', 'rect_snip_2_same', 'rect_snip_diag', 'rect_round_1', 'heptagon', 'decagon', 'dodecagon', 'arrow_cross', 'minus_sign', 'multiply_x', 'document', 'manual_input', 'off_page_connector', 'bowtie', 'delay', 'punched_tape', 'stadium', 'elbow', 'text_only', 'convoyeur_rouleaux', 'convoyeur_rouleaux_arc', 'vis_sans_fin', 'tapis_chevron', 'robot_bras'];
                $shapeType = in_array($_POST['shape_type'] ?? '', $shapesAutorisees, true) ? $_POST['shape_type'] : 'rect';
                $posWRaw = trim($_POST['pos_w'] ?? ''); $posW = ($posWRaw !== '' && is_numeric($posWRaw)) ? max(0.5, min(100, (float)$posWRaw)) : null;
                $posHRaw = trim($_POST['pos_h'] ?? ''); $posH = ($posHRaw !== '' && is_numeric($posHRaw)) ? max(0.5, min(100, (float)$posHRaw)) : null;
                $rotation = is_numeric($_POST['rotation'] ?? '') ? max(-360, min(360, (float)$_POST['rotation'])) : 0;
                $textRotation = is_numeric($_POST['text_rotation'] ?? '') ? max(-360, min(360, (float)$_POST['text_rotation'])) : 0;
                $fontSizeRaw = trim($_POST['font_size'] ?? ''); $fontSize = ($fontSizeRaw !== '' && is_numeric($fontSizeRaw)) ? max(0.3, min(3, (float)$fontSizeRaw)) : null;
                $textColorAuto = !empty($_POST['text_color_auto']);
                $textColorRaw = trim($_POST['text_color'] ?? '');
                $textColor = (!$textColorAuto && preg_match('/^#[0-9a-fA-F]{6}$/', $textColorRaw)) ? $textColorRaw : null;
                $estClickable = !empty($_POST['est_clickable']) ? 1 : 0;
                $labelRaw = trim($_POST['label'] ?? '');
                $label = $labelRaw !== '' ? $labelRaw : null;

                if ($nom === '') {
                    $message = "<div class='alert danger'>" . t('param.msg_nom_categorie_obligatoire') . "</div>";
                } else {
                    $id = (int)($_POST['id'] ?? 0);
                    $chk = $db->prepare("SELECT COUNT(*) FROM schema_categories_visuelles WHERE nom=? AND id<>?");
                    $chk->execute([$nom, $id]);
                    if ($chk->fetchColumn() > 0) {
                        $message = "<div class='alert danger'>" . t('param.msg_categorie_existe') . "</div>";
                    } elseif ($action === 'add_categorie_schema') {
                        $maxO = $db->query("SELECT COALESCE(MAX(ordre),-1) FROM schema_categories_visuelles")->fetchColumn();
                        $db->prepare("INSERT INTO schema_categories_visuelles (nom, fill_color, fill_color2, gradient, gradient_angle, border_color, effect, shape_type, pos_w, pos_h, rotation, text_rotation, font_size, text_color, est_clickable, label, ordre) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
                            ->execute([$nom, $fill, $fill2, $gradient, $gradientAngle, $border, $effect, $shapeType, $posW, $posH, $rotation, $textRotation, $fontSize, $textColor, $estClickable, $label, $maxO + 1]);
                        ajouterLog($db, $_SESSION['user'], "Paramètres", "A ajouté la catégorie de schéma : $nom");
                        $message = "<div class='alert success'>" . t('param.msg_categorie_schema_ajoutee') . "</div>";
                    } else {
                        $db->prepare("UPDATE schema_categories_visuelles SET nom=?, fill_color=?, fill_color2=?, gradient=?, gradient_angle=?, border_color=?, effect=?, shape_type=?, pos_w=?, pos_h=?, rotation=?, text_rotation=?, font_size=?, text_color=?, est_clickable=?, label=? WHERE id=?")
                            ->execute([$nom, $fill, $fill2, $gradient, $gradientAngle, $border, $effect, $shapeType, $posW, $posH, $rotation, $textRotation, $fontSize, $textColor, $estClickable, $label, $id]);
                        // Rétroactif, MAIS uniquement l'apparence (couleurs + dégradé + effet) : toute
                        // forme déjà réglée individuellement (taille, rotation, texte...) garde ses réglages.
                        $db->prepare("UPDATE schema_zones SET fill_color=?, fill_color2=?, gradient=?, gradient_angle=?, border_color=?, effect=? WHERE categorie_visuelle_id=?")
                            ->execute([$fill, $fill2, $gradient, $gradientAngle, $border, $effect, $id]);
                        ajouterLog($db, $_SESSION['user'], "Paramètres", "A modifié la catégorie de schéma : $nom (apparence répercutée sur les formes déjà assignées, valeurs de départ inchangées)");
                        $message = "<div class='alert success'>" . t('param.msg_categorie_schema_modifiee') . "</div>";
                    }
                }

            } elseif ($action === 'delete_categorie_schema') {
                $id = (int)$_POST['id'];
                $stmt = $db->prepare("SELECT nom FROM schema_categories_visuelles WHERE id=?");
                $stmt->execute([$id]);
                $nom = $stmt->fetchColumn();
                $enUsage = $db->prepare("SELECT COUNT(*) FROM schema_zones WHERE categorie_visuelle_id=?");
                $enUsage->execute([$id]);
                if ($enUsage->fetchColumn() > 0) {
                    $message = "<div class='alert danger'>" . t('param.msg_categorie_schema_en_usage') . "</div>";
                } else {
                    $db->prepare("DELETE FROM schema_categories_visuelles WHERE id=?")->execute([$id]);
                    ajouterLog($db, $_SESSION['user'], "Paramètres", "A supprimé la catégorie de schéma : $nom");
                    $message = "<div class='alert success'>" . t('param.msg_categorie_schema_supprimee') . "</div>";
                }

            } elseif ($action === 'move_categorie_schema') {
                deplacerElement($db, 'schema_categories_visuelles', (int)$_POST['id'], $_POST['direction']);

            } elseif ($action === 'add_service') {
                $label = trim($_POST['label']);
                if ($label === '') {
                    $message = "<div class='alert danger'>" . t('param.msg_nom_service_obligatoire') . "</div>";
                } else {
                    $base = genererCle($label);
                    $cle = $base !== '' ? $base : 'service';
                    $chk = $db->prepare("SELECT COUNT(*) FROM services WHERE cle=?");
                    $i = 2;
                    while (true) {
                        $chk->execute([$cle]);
                        if ($chk->fetchColumn() == 0) { break; }
                        $cle = $base . '_' . $i;
                        $i++;
                    }
                    $maxO = $db->query("SELECT COALESCE(MAX(ordre),-1) FROM services")->fetchColumn();
                    $db->prepare("INSERT INTO services (cle, label, ordre) VALUES (?, ?, ?)")->execute([$cle, $label, $maxO + 1]);
                    ajouterLog($db, $_SESSION['user'], "Paramètres", "A ajouté le service : $label");
                    $message = "<div class='alert success'>" . t('param.msg_service_ajoute') . "</div>";
                }

            } elseif ($action === 'edit_service') {
                $id = (int)$_POST['id'];
                $label = trim($_POST['label']);
                if ($label === '') {
                    $message = "<div class='alert danger'>" . t('param.msg_nom_service_obligatoire') . "</div>";
                } else {
                    $db->prepare("UPDATE services SET label=? WHERE id=?")->execute([$label, $id]);
                    ajouterLog($db, $_SESSION['user'], "Paramètres", "A modifié le service : $label");
                    $message = "<div class='alert success'>" . t('param.msg_service_modifie') . "</div>";
                }

            } elseif ($action === 'delete_service') {
                $id = (int)$_POST['id'];
                $stmt = $db->prepare("SELECT cle, label FROM services WHERE id=?");
                $stmt->execute([$id]);
                $svc = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($svc) {
                    $enUsage = $db->prepare("SELECT COUNT(*) FROM utilisateurs WHERE role=?");
                    $enUsage->execute([$svc['cle']]);
                    if ($enUsage->fetchColumn() > 0) {
                        $message = "<div class='alert danger'>" . t('param.msg_service_en_usage') . "</div>";
                    } else {
                        $db->prepare("DELETE FROM services WHERE id=?")->execute([$id]);
                        ajouterLog($db, $_SESSION['user'], "Paramètres", "A supprimé le service : " . $svc['label']);
                        $message = "<div class='alert success'>" . t('param.msg_service_supprime') . "</div>";
                    }
                }

            } elseif ($action === 'move_service') {
                deplacerElement($db, 'services', (int)$_POST['id'], $_POST['direction']);

            } elseif ($action === 'add_categorie') {
                $label = trim($_POST['label']);
                $description = trim($_POST['description'] ?? '');
                $icone = array_key_exists($_POST['icone'] ?? '', $ICONES_DISPONIBLES) ? $_POST['icone'] : 'fa-gear';
                $couleur = couleur_est_valide($_POST['couleur'] ?? '') ? $_POST['couleur'] : '#3498db';
                if ($label === '') {
                    $message = "<div class='alert danger'>" . t('param.msg_nom_categorie_obligatoire') . "</div>";
                } else {
                    $base = genererCle($label);
                    $cle = $base !== '' ? $base : 'categorie';
                    $chk = $db->prepare("SELECT COUNT(*) FROM preventif_categories WHERE cle=?");
                    $i = 2;
                    while (true) {
                        $chk->execute([$cle]);
                        if ($chk->fetchColumn() == 0) { break; }
                        $cle = $base . '_' . $i;
                        $i++;
                    }
                    $maxO = $db->query("SELECT COALESCE(MAX(ordre),-1) FROM preventif_categories")->fetchColumn();
                    $db->prepare("INSERT INTO preventif_categories (cle, label, description, icone, couleur, ordre) VALUES (?, ?, ?, ?, ?, ?)")->execute([$cle, $label, $description, $icone, $couleur, $maxO + 1]);
                    ajouterLog($db, $_SESSION['user'], "Paramètres", "A ajouté la catégorie de préventif : $label");
                    $message = "<div class='alert success'>" . t('param.msg_categorie_ajoutee') . "</div>";
                }

            } elseif ($action === 'edit_categorie') {
                $id = (int)$_POST['id'];
                $label = trim($_POST['label']);
                $description = trim($_POST['description'] ?? '');
                $icone = array_key_exists($_POST['icone'] ?? '', $ICONES_DISPONIBLES) ? $_POST['icone'] : 'fa-gear';
                $couleur = couleur_est_valide($_POST['couleur'] ?? '') ? $_POST['couleur'] : '#3498db';
                if ($label === '') {
                    $message = "<div class='alert danger'>" . t('param.msg_nom_categorie_obligatoire') . "</div>";
                } else {
                    $db->prepare("UPDATE preventif_categories SET label=?, description=?, icone=?, couleur=? WHERE id=?")->execute([$label, $description, $icone, $couleur, $id]);
                    ajouterLog($db, $_SESSION['user'], "Paramètres", "A modifié la catégorie de préventif : $label");
                    $message = "<div class='alert success'>" . t('param.msg_categorie_modifiee') . "</div>";
                }

            } elseif ($action === 'delete_categorie') {
                $id = (int)$_POST['id'];
                $total = $db->query("SELECT COUNT(*) FROM preventif_categories")->fetchColumn();
                $stmt = $db->prepare("SELECT cle, label FROM preventif_categories WHERE id=?");
                $stmt->execute([$id]);
                $cat = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($total <= 1) {
                    $message = "<div class='alert danger'>" . t('param.msg_categorie_min1') . "</div>";
                } elseif ($cat) {
                    $enUsage = $db->prepare("SELECT COUNT(*) FROM preventif_regles WHERE categorie=?");
                    $enUsage->execute([$cat['cle']]);
                    if ($enUsage->fetchColumn() > 0) {
                        $message = "<div class='alert danger'>" . t('param.msg_categorie_en_usage') . "</div>";
                    } else {
                        $db->prepare("DELETE FROM preventif_categories WHERE id=?")->execute([$id]);
                        ajouterLog($db, $_SESSION['user'], "Paramètres", "A supprimé la catégorie de préventif : " . $cat['label']);
                        $message = "<div class='alert success'>" . t('param.msg_categorie_supprimee') . "</div>";
                    }
                }

            } elseif ($action === 'move_categorie') {
                deplacerElement($db, 'preventif_categories', (int)$_POST['id'], $_POST['direction']);

            } elseif ($action === 'edit_libelle') {
                $bucket = $_POST['bucket'] ?? '';
                $label = trim($_POST['label']);
                $couleur = couleur_est_valide($_POST['couleur'] ?? '') ? $_POST['couleur'] : '#3498db';
                if ($label === '') {
                    $message = "<div class='alert danger'>" . t('param.msg_libelle_obligatoire') . "</div>";
                } else {
                    // Si le libellé n'a pas été modifié par rapport au défaut français d'origine (le
                    // formulaire le pré-remplit toujours, même quand seule la couleur change), on
                    // n'enregistre pas ce champ : les pages qui l'affichent peuvent alors continuer à
                    // le traduire automatiquement (FR/EN/NL) au lieu de le figer en français — même
                    // principe que edit_tuile un peu plus haut.
                    $labelAEnregistrer = ($label === ($LIBELLES_DEFAUTS_FR[$bucket] ?? null)) ? '' : $label;
                    $stmt = $db->prepare("UPDATE libelles_workflow SET label=?, couleur=? WHERE bucket=?");
                    $stmt->execute([$labelAEnregistrer, $couleur, $bucket]);
                    ajouterLog($db, $_SESSION['user'], "Paramètres", "A modifié l'habillage du statut/priorité : $label");
                    $message = "<div class='alert success'>" . t('param.msg_libelle_ok') . "</div>";
                }

            } elseif ($action === 'edit_planning_couleur') {
                $table = $_POST['table'] ?? '';
                $cle = $_POST['cle'] ?? '';
                $label = trim($_POST['label']);
                $couleur = couleur_est_valide($_POST['couleur'] ?? '') ? $_POST['couleur'] : '#3498db';
                if (!in_array($table, ['planning_postes', 'planning_astreintes'], true)) {
                    $message = "<div class='alert danger'>" . t('param.msg_table_inconnue') . "</div>";
                } elseif ($label === '') {
                    $message = "<div class='alert danger'>" . t('param.msg_libelle_obligatoire') . "</div>";
                } else {
                    $stmt = $db->prepare("UPDATE $table SET label=?, couleur=? WHERE cle=?");
                    $stmt->execute([$label, $couleur, $cle]);
                    ajouterLog($db, $_SESSION['user'], "Paramètres", "A modifié l'habillage planning ($table) : $label");
                    $message = "<div class='alert success'>" . t('param.msg_libelle_ok') . "</div>";
                }

            } elseif ($action === 'reset_planning') {
                $nb = (int)$db->query("SELECT COUNT(*) FROM planning_shifts")->fetchColumn();
                $db->exec("DELETE FROM planning_shifts");
                ajouterLog($db, $_SESSION['user'], "Paramètres", "A réinitialisé le planning importé ($nb ligne(s) supprimée(s)).");
                $message = "<div class='alert success'>" . t('param.msg_planning_reset', ['{n}' => $nb]) . "</div>";

            } elseif ($action === 'reset_heures_planning') {
                $nb = (int)$db->query("SELECT COUNT(*) FROM planning_shifts WHERE heures IS NOT NULL")->fetchColumn();
                $db->exec("UPDATE planning_shifts SET heures = NULL");
                ajouterLog($db, $_SESSION['user'], "Paramètres", "A réinitialisé les heures du planning ($nb ligne(s) remise(s) à zéro), postes et astreintes conservés.");
                $message = "<div class='alert success'>" . t('param.msg_heures_reset', ['{n}' => $nb]) . "</div>";

            } elseif ($action === 'edit_objectif_annuel') {
                $username = $_POST['username'] ?? '';
                $objectif = isset($_POST['objectif']) ? floatval(str_replace(',', '.', $_POST['objectif'])) : 0;
                if ($objectif <= 0 || $objectif > 9999) {
                    $message = "<div class='alert danger'>" . t('param.msg_objectif_invalide') . "</div>";
                } else {
                    $stmt = $db->prepare("UPDATE utilisateurs SET objectif_heures_annuel = ? WHERE username = ?");
                    $stmt->execute([$objectif, $username]);
                    ajouterLog($db, $_SESSION['user'], "Paramètres", "A modifié l'objectif annuel de $username : {$objectif}h.");
                    $message = "<div class='alert success'>" . t('param.msg_objectif_ok', ['{username}' => $username]) . "</div>";
                }

            } elseif ($action === 'edit_tuile') {
                $href = $_POST['href'] ?? '';
                $couleur = couleur_est_valide($_POST['couleur'] ?? '') ? $_POST['couleur'] : '';
                $titre = trim(mb_substr((string)($_POST['titre'] ?? ''), 0, 60));
                $description = trim(mb_substr((string)($_POST['description'] ?? ''), 0, 255));
                $icone = array_key_exists($_POST['icone'] ?? '', $ICONES_DOSSIER) ? $_POST['icone'] : '';
                $hrefsValides = array_column($TUILES_ACCUEIL, 'href');
                if (!in_array($href, $hrefsValides, true) || $couleur === '' || $titre === '' || $icone === '') {
                    $message = "<div class='alert danger'>" . t('param.msg_tuile_invalide') . "</div>";
                } else {
                    // Si le titre/la description n'ont pas été modifiés par rapport au libellé français
                    // d'origine (le formulaire les pré-remplit toujours, même quand seule la couleur ou
                    // l'icône change), on n'enregistre pas ces deux champs : index.php peut alors continuer
                    // à les traduire automatiquement (FR/EN/NL) au lieu de les figer en français dès le
                    // premier enregistrement — cf. i18n, session_init.php.
                    $defautTuile = null;
                    foreach ($TUILES_ACCUEIL as $tDef) { if ($tDef['href'] === $href) { $defautTuile = $tDef; break; } }
                    $titreAEnregistrer = ($defautTuile && $titre === $defautTuile['titre']) ? '' : $titre;
                    $descriptionAEnregistrer = ($defautTuile && $description === $defautTuile['desc']) ? '' : $description;

                    $stmt = $db->prepare("INSERT INTO tuiles_couleurs (href, couleur, titre, description, icone) VALUES (?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE couleur = VALUES(couleur), titre = VALUES(titre), description = VALUES(description), icone = VALUES(icone)");
                    $stmt->execute([$href, $couleur, $titreAEnregistrer, $descriptionAEnregistrer, $icone]);
                    ajouterLog($db, $_SESSION['user'], "Paramètres", "A modifié la tuile d'accueil \"$href\".");
                    $message = "<div class='alert success'>" . t('param.msg_tuile_ok') . "</div>";
                }

            } elseif ($action === 'edit_dossier_perso') {
                $id = $_POST['id'] ?? '';
                $titre = trim(mb_substr((string)($_POST['titre'] ?? ''), 0, 30));
                $description = trim(mb_substr((string)($_POST['description'] ?? ''), 0, 120));
                $icone = array_key_exists($_POST['icone'] ?? '', $ICONES_DOSSIER) ? $_POST['icone'] : 'fa-folder';
                $couleur = couleur_est_valide($_POST['couleur'] ?? '') ? $_POST['couleur'] : '';
                if ($titre === '' || $couleur === '' || !preg_match('/^[a-zA-Z0-9_-]{1,50}$/', $id)) {
                    $message = "<div class='alert danger'>" . t('param.msg_dossier_invalide') . "</div>";
                } else {
                    $layoutPerso = charger_layout_utilisateur($db, $_SESSION['user']);
                    $trouve = false;
                    foreach ($layoutPerso as &$itemPerso) {
                        if (is_array($itemPerso) && ($itemPerso['type'] ?? '') === 'dossier' && ($itemPerso['id'] ?? '') === $id) {
                            $itemPerso['titre'] = $titre;
                            $itemPerso['desc'] = $description;
                            $itemPerso['icone'] = $icone;
                            $itemPerso['couleur'] = $couleur;
                            $trouve = true;
                            break;
                        }
                    }
                    unset($itemPerso);
                    if (!$trouve) {
                        $message = "<div class='alert danger'>" . t('param.msg_dossier_introuvable') . "</div>";
                    } else {
                        sauvegarder_layout_utilisateur($db, $_SESSION['user'], $layoutPerso);
                        ajouterLog($db, $_SESSION['user'], "Paramètres", "A modifié son dossier d'accueil \"$titre\".");
                        $message = "<div class='alert success'>" . t('param.msg_dossier_ok') . "</div>";
                    }
                }
            }
        } catch (Exception $e) {
            error_log("parametres.php: " . $e->getMessage());
            $message = "<div class='alert danger'>" . t('param.msg_erreur_serveur') . "</div>";
        }
    }
}

// --- DONNÉES POUR L'AFFICHAGE ---
$general_rows = $db->query("SELECT cle, valeur FROM parametres_general")->fetchAll(PDO::FETCH_KEY_PAIR);
$types = $db->query("SELECT * FROM types_equipement ORDER BY ordre ASC")->fetchAll(PDO::FETCH_ASSOC);
$composants_types = $db->query("SELECT * FROM composant_types ORDER BY ordre ASC")->fetchAll(PDO::FETCH_ASSOC);
$categories_schema = $db->query("SELECT * FROM schema_categories_visuelles ORDER BY ordre ASC")->fetchAll(PDO::FETCH_ASSOC);
$type_composants_map = [];
foreach ($db->query("SELECT type_equipement_id, composant_type_id FROM type_equipement_composants") as $r) {
    $type_composants_map[(int)$r['type_equipement_id']][] = (int)$r['composant_type_id'];
}
$services = $db->query("SELECT * FROM services ORDER BY ordre ASC")->fetchAll(PDO::FETCH_ASSOC);
$categories = $db->query("SELECT * FROM preventif_categories ORDER BY ordre ASC")->fetchAll(PDO::FETCH_ASSOC);
$libelles = $db->query("SELECT * FROM libelles_workflow ORDER BY dimension DESC, ordre ASC")->fetchAll(PDO::FETCH_ASSOC);
foreach ($libelles as &$lRef) {
    if (($lRef['label'] ?? '') === '') { $lRef['label'] = $LIBELLES_DEFAUTS_TRAD[$lRef['bucket']] ?? $lRef['label']; }
}
unset($lRef);
$libelles_statuts = array_values(array_filter($libelles, fn($l) => $l['dimension'] === 'statut'));
$libelles_priorites = array_values(array_filter($libelles, fn($l) => $l['dimension'] === 'priorite'));
$planning_postes = $db->query("SELECT * FROM planning_postes ORDER BY ordre ASC")->fetchAll(PDO::FETCH_ASSOC);
$planning_astreintes = $db->query("SELECT * FROM planning_astreintes ORDER BY ordre ASC")->fetchAll(PDO::FETCH_ASSOC);
$planning_shifts_count = (int)$db->query("SELECT COUNT(*) FROM planning_shifts")->fetchColumn();
$planning_heures_count = (int)$db->query("SELECT COUNT(*) FROM planning_shifts WHERE heures IS NOT NULL")->fetchColumn();
$equipe_objectifs = $db->query("SELECT username, objectif_heures_annuel FROM utilisateurs WHERE role IN ('admin','technicien') AND username != 'Florent' ORDER BY ordre")->fetchAll(PDO::FETCH_ASSOC);
$tuiles_perso = [];
foreach ($db->query("SELECT href, couleur, titre, description, icone FROM tuiles_couleurs")->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $tuiles_perso[$row['href']] = $row;
}
foreach ($TUILES_ACCUEIL as &$tRef) {
    $p = $tuiles_perso[$tRef['href']] ?? null;
    $tRef['couleur'] = ($p['couleur'] ?? '') !== '' ? $p['couleur'] : $tRef['defaut'];
    if (!empty($p['titre'])) { $tRef['titre'] = $p['titre']; }
    if (isset($p['description']) && $p['description'] !== null && $p['description'] !== '') { $tRef['desc'] = $p['description']; }
    if (!empty($p['icone'])) { $tRef['icon'] = $p['icone']; }
}
unset($tRef);
$dossiers_perso = array_values(array_filter(charger_layout_utilisateur($db, $_SESSION['user']), function ($it) {
    return is_array($it) && ($it['type'] ?? '') === 'dossier';
}));
$nom_entreprise = $general_rows['nom_entreprise'] ?? "GMAO";
$logo_path = $general_rows['logo_path'] ?? 'img/logo.png';
$url_portail = $general_rows['url_portail'] ?? '';
$url_demo = $general_rows['url_demo'] ?? '';
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(langue_actuelle()); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title><?php echo htmlspecialchars(t('param.title')); ?></title>
    <link rel="icon" type="image/png" href="img/logo.png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Caveat:wght@600&family=Segoe+UI:wght@300;400;600;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #2c3e50; --accent: #3498db; --success: #2ecc71;
            --danger: #e74c3c; --gelpam-green: #2ecc71; --gelpam-orange: #f39c12;
            --line: #e3e8ec; --line-strong: #ccd5db; --surface-2: #f4f6f8; --ink-500: #64748b; --accent-100: #eaf4fc;
        }
        body { margin: 0; font-family: 'Segoe UI', sans-serif; background: linear-gradient(rgba(0,0,0,0.2), rgba(0,0,0,0.2)), url('img/fond.jpg') no-repeat center 0px fixed; background-size: cover; min-height: 100vh; padding-top: 98px; }

        header { position: fixed; top: 0; width: 100%; z-index: 1000; overflow: hidden; box-shadow: 0 4px 20px rgba(0,0,0,0.25); }
        header::before { content: ""; position: absolute; top: -12px; left: -12px; right: -12px; bottom: -12px; background: linear-gradient(rgba(0, 0, 0, 0.2), rgba(0, 0, 0, 0.2)), url('img/fond.jpg') no-repeat center 0px fixed; background-size: cover; filter: blur(4px); z-index: -1; }
        .crumb-bar { display: flex; align-items: center; gap: 8px; font-size: 0.94rem; font-weight: 600; color: rgba(255,255,255,0.9); text-shadow: 0 1px 3px rgba(0,0,0,0.4); max-width: 99%; margin: 0 auto; padding: 0 10px 10px; }
        .crumb-bar .crumb-home { color: inherit; text-decoration: none; display: inline-flex; align-items: center; gap: 5px; }
        .crumb-bar .crumb-home:hover { text-decoration: underline; }
        .crumb-sep { color: rgba(255,255,255,0.45); font-weight: 400; }
        .crumb-current { color: rgba(255,255,255,0.75); font-weight: 400; }
        .header-top { display: flex; justify-content: space-between; align-items: center; padding: 12px 20px; }
        .header-title { font-family: 'Caveat', cursive; font-size: 1.5rem; color: white; background: rgba(255,255,255,0.12); backdrop-filter: blur(14px) brightness(1.15); -webkit-backdrop-filter: blur(14px) brightness(1.15); border: 1px solid rgba(255,255,255,0.4); border-radius: 20px; padding: 6px 18px; text-shadow: 0 2px 6px rgba(0,0,0,0.4); box-shadow: 0 6px 16px rgba(0,0,0,0.2); }
        .nav-tabs { display: flex; background: #fff; padding: 0 10px; gap: 2px; }
        .tab-item { padding: 10px 18px; text-decoration: none; color: #7f8c8d; font-weight: 600; font-size: 0.8rem; border-bottom: 3px solid transparent; transition: 0.3s; display: flex; align-items: center; gap: 8px; }
        .tab-item.active { color: var(--accent); border-bottom: 3px solid var(--accent); background: rgba(52,152,219,0.05); }
        .user-badge { background: rgba(255,255,255,0.12); backdrop-filter: blur(14px) brightness(1.15); -webkit-backdrop-filter: blur(14px) brightness(1.15); padding: 6px 14px; border-radius: 20px; font-size: 0.75rem; font-weight: 600; display: flex; align-items: center; gap: 8px; color: white; border: 1px solid rgba(255,255,255,0.4); text-shadow: 0 1px 3px rgba(0,0,0,0.4); box-shadow: 0 6px 16px rgba(0,0,0,0.2); }
        .status-pulse { width: 8px; height: 8px; border-radius: 50%; position: relative; background: var(--success); }
        .status-pulse::after { content: ""; position: absolute; width: 100%; height: 100%; border-radius: 50%; background: inherit; animation: pulse-dot 2s infinite; opacity: 0.6; }
        @keyframes pulse-dot { 0% { transform: scale(1); opacity: 0.6; } 100% { transform: scale(2.5); opacity: 0; } }

        .sidebar { height: 100%; width: 0; position: fixed; z-index: 3000; top: 0; left: 0; background-color: #1a252f; overflow-x: hidden; overflow-y: auto; transition: 0.4s; padding-top: 60px; padding-bottom: 20px; }
        .sidebar a { padding: 12px 25px; text-decoration: none; font-size: 1.05rem; color: #ecf0f1; display: block; transition: 0.3s; border-left: 4px solid transparent; }
        .sidebar a:hover { background: #2c3e50; border-left: 4px solid var(--accent); }
        .sidebar a.active-side { background: #2c3e50; border-left: 4px solid var(--accent); color: var(--accent); font-weight: bold; }
        .sidebar .closebtn { position: absolute; top: 10px; right: 25px; font-size: 36px; color: white; border: none; background: none; cursor: pointer; }
        .openbtn { font-size: 22px; cursor: pointer; background: none; border: none; color: var(--primary); padding: 5px 10px; }
        .btn-accueil { font-size: 18px; color: white; text-decoration: none; display: flex; align-items: center; justify-content: center; width: 38px; height: 38px; border-radius: 50%; background: rgba(255,255,255,0.12); backdrop-filter: blur(14px) brightness(1.15); -webkit-backdrop-filter: blur(14px) brightness(1.15); border: 1px solid rgba(255,255,255,0.4); box-shadow: 0 6px 16px rgba(0,0,0,0.2); transition: transform 0.2s, background 0.2s; }
        .btn-accueil:hover { background: rgba(255,255,255,0.28); transform: translateY(-2px); }
        .btn-accueil:hover { background: rgba(0,0,0,0.06); }
        .btn-accueil-green:hover { background: rgba(46, 204, 113, 0.3); border-color: rgba(46, 204, 113, 0.6); }

        .container { max-width: 1180px; margin: 0 auto; padding: 10px 20px 40px; }
        .alert { padding: 10px 14px; border-radius: 8px; margin-bottom: 14px; font-weight: 600; font-size: 0.85rem; }
        .alert.success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .alert.danger { background: #f8d7da; color: #842029; border: 1px solid #f1aeb5; }

        .page-title { font-family: inherit; font-size: 1.55rem; font-weight: 800; color: #fff; text-shadow: 0 2px 10px rgba(0,0,0,0.35); margin: 10px 0 20px; display: flex; align-items: center; gap: 13px; letter-spacing: -.01em; }
        .page-title i { display: flex; align-items: center; justify-content: center; flex: none; width: 42px; height: 42px; border-radius: 12px; font-size: 1.05rem; background: rgba(255,255,255,0.14); backdrop-filter: blur(14px) brightness(1.15); -webkit-backdrop-filter: blur(14px) brightness(1.15); border: 1px solid rgba(255,255,255,0.35); box-shadow: 0 6px 16px rgba(0,0,0,0.18); }

        .settings-shell { display: flex; align-items: flex-start; gap: 24px; }
        .settings-tabs { display: flex; flex-direction: column; gap: 1px; flex: none; width: 252px; background: #fff; border: 1px solid var(--line); border-radius: 14px; box-shadow: 0 1px 3px rgba(15,23,42,0.04); padding: 8px; position: sticky; top: 112px; }
        .stab { display: flex; align-items: center; gap: 11px; border: none; border-left: 3px solid transparent; background: none; color: #55606b; font-weight: 600; font-size: 0.83rem; padding: 11px 12px 11px 11px; border-radius: 8px; cursor: pointer; font-family: inherit; text-align: left; width: 100%; box-sizing: border-box; transition: background .15s, color .15s, border-color .15s; }
        .stab i { width: 16px; text-align: center; flex: none; color: #9aa5b1; transition: color .15s; font-size: 0.86rem; }
        .stab:hover { background: var(--surface-2); color: var(--primary); }
        .stab.active { background: var(--accent-100); color: var(--accent); border-left-color: var(--accent); font-weight: 700; }
        .stab.active i { color: var(--accent); }
        .settings-content { flex: 1; min-width: 0; }

        .settings-panel { background: #fff; border: 1px solid var(--line); border-radius: 16px; box-shadow: 0 1px 3px rgba(15,23,42,0.04); padding: 30px; }

        @media (max-width: 860px) {
            .settings-shell { flex-direction: column; }
            .settings-tabs { flex-direction: row; flex-wrap: wrap; width: 100%; position: static; }
            .stab { width: auto; border-left: none; border-bottom: 3px solid transparent; }
            .stab.active { border-left: none; border-bottom-color: var(--accent); }
        }
        .set-card { max-width: 520px; }
        .field-block { display: flex; flex-direction: column; gap: 6px; margin-bottom: 20px; }
        .field-block label { font-weight: 700; font-size: 0.78rem; color: var(--ink-500); text-transform: uppercase; letter-spacing: .03em; }
        .field-block input[type=text], .field-block input[type=number], .field-block select { width: 100%; box-sizing: border-box; padding: 11px 12px; border-radius: 8px; border: 1.5px solid var(--line-strong); font-family: inherit; font-size: 0.9rem; color: var(--primary); background: #fff; }
        .field-block input[type=text]:focus, .field-block input[type=number]:focus, .field-block select:focus { outline: none; border-color: var(--accent); }
        #modalCategorieSchema .field-block label { display: block; min-height: 2.3em; }
        .field-hint { font-size: 0.72rem; color: var(--ink-500); margin: 4px 0 0; }
        .logo-preview-row { display: flex; align-items: center; gap: 16px; }
        .logo-preview { height: 50px; max-width: 160px; object-fit: contain; border: 1px solid var(--line); border-radius: 8px; padding: 6px; background: #fff; }

        .pm-btn { display: inline-flex; align-items: center; gap: 7px; border-radius: 8px; border: 1px solid var(--line-strong); background: #fff; color: var(--primary); font-size: 0.8rem; font-weight: 700; padding: 10px 16px; cursor: pointer; transition: 0.15s; font-family: inherit; }
        .pm-btn:hover { border-color: var(--accent); color: var(--accent); }
        .pm-btn.primary { background: var(--success); border-color: var(--success); color: #fff; }
        .pm-btn.primary:hover { background: #27ae60; border-color: #27ae60; color: #fff; }

        .set-toolbar { display: flex; align-items: center; justify-content: space-between; margin-bottom: 18px; }
        .set-subtitle { font-size: 0.7rem; color: var(--ink-500); font-weight: 800; text-transform: uppercase; letter-spacing: .05em; }

        .set-row { display: flex; align-items: center; gap: 13px; padding: 11px 14px; border-radius: 11px; border: 1px solid var(--line); margin-bottom: 7px; transition: border-color .15s, background .15s; }
        .set-row:hover { border-color: var(--line-strong); background: var(--surface-2); }
        .set-icon { flex: none; width: 34px; height: 34px; border-radius: 10px; background: var(--accent-100); display: flex; align-items: center; justify-content: center; color: var(--accent); font-size: 0.92rem; }
        .set-name { flex: 1; font-weight: 600; color: var(--primary); font-size: 0.89rem; }
        .set-key { font-size: 0.66rem; color: var(--ink-500); background: var(--surface-2); border-radius: 20px; padding: 2px 9px; margin-left: 8px; font-weight: 700; }
        .set-actions { display: flex; gap: 2px; flex: none; }
        .row-btn { border: 1px solid transparent; background: none; border-radius: 7px; padding: 7px 9px; cursor: pointer; font-size: 0.75rem; color: #94a3b8; transition: 0.15s; }
        .row-btn:hover { background: var(--surface-2); color: var(--accent); border-color: var(--line); }
        .row-btn.danger:hover { color: var(--danger); }
        .row-btn:disabled { opacity: 0.3; cursor: not-allowed; }
        .row-btn:disabled:hover { background: none; color: #94a3b8; border-color: transparent; }
        .set-empty { font-size: 0.85rem; color: var(--ink-500); font-style: italic; padding: 14px 4px; }

        .modal-bg { display: none; position: fixed; z-index: 5000; left: 0; top: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); backdrop-filter: blur(3px); align-items: center; justify-content: center; }
        .modal-bg.show { display: flex; }
        .modal-box { background: white; width: 420px; max-width: 90vw; max-height: 85vh; overflow-y: auto; overflow-x: hidden; padding: 24px; border-radius: 12px; box-shadow: 0 10px 25px rgba(0,0,0,0.2); }
        .modal-box h3 { margin: 0 0 16px; color: var(--primary); }
        .modal-box-large { width: min(880px, 94vw); max-height: 94vh; }
        .dossier-form-split { display: flex; gap: 22px; align-items: flex-start; }
        .dossier-form-col { flex: 1 1 0; min-width: 0; }
        .field-block-label { font-weight: 700; font-size: 0.72rem; color: var(--ink-500); text-transform: uppercase; letter-spacing: .03em; margin-bottom: 8px; }
        #modalDossierPerso .dossier-form-col .icon-grid { grid-template-columns: repeat(auto-fill, minmax(58px, 1fr)); margin-bottom: 0; }
        #modalDossierPerso .dossier-form-col .color-grid { grid-template-columns: repeat(auto-fill, minmax(46px, 1fr)); margin-bottom: 0; }
        #modalTuileCouleur .dossier-form-col .icon-grid { grid-template-columns: repeat(auto-fill, minmax(58px, 1fr)); margin-bottom: 0; }
        #modalTuileCouleur .dossier-form-col .color-grid { grid-template-columns: repeat(auto-fill, minmax(46px, 1fr)); margin-bottom: 0; }
        @media screen and (max-width: 640px) { .dossier-form-split { flex-direction: column; } }
        .su-dir-row { display: grid; grid-template-columns: repeat(5, 1fr); gap: 6px; margin-top: 8px; }
        .su-dir-tile { display: flex; flex-direction: column; align-items: center; gap: 2px; padding: 5px 2px; border: 1.5px solid var(--line-strong); border-radius: 8px; background: #fff; cursor: pointer; font-family: inherit; transition: .15s; }
        .su-dir-tile:hover { border-color: var(--accent); background: var(--accent-100); }
        .su-dir-tile.active { border-color: var(--accent); background: var(--accent-100); box-shadow: 0 0 0 2px var(--accent-100); }
        .su-dir-tile .su-dir-arrow { font-size: 1rem; line-height: 1; color: var(--primary); }
        .su-dir-tile.active .su-dir-arrow { color: var(--accent); }
        .su-dir-tile span:last-child { font-size: 0.56rem; font-weight: 700; color: var(--ink-500); text-align: center; line-height: 1.1; }
        .su-effect-row { display: grid; grid-template-columns: repeat(5, 1fr); gap: 6px; }
        .su-effect-tile { display: flex; flex-direction: column; align-items: center; gap: 4px; padding: 6px 2px; border: 1.5px solid var(--line-strong); border-radius: 8px; background: #fff; cursor: pointer; font-family: inherit; transition: .15s; }
        .su-effect-tile:hover { border-color: var(--accent); background: var(--accent-100); }
        .su-effect-tile.active { border-color: var(--accent); background: var(--accent-100); box-shadow: 0 0 0 2px var(--accent-100); }
        .su-effect-swatch { width: 20px; height: 20px; border-radius: 5px; background: var(--accent-100); border: 1.5px solid var(--accent); box-sizing: border-box; }
        .su-effect-swatch.eff-shadow { filter: drop-shadow(2px 3px 3px rgba(0,0,0,.45)); }
        .su-effect-swatch.eff-bevel { box-shadow: inset -2px -2px 3px rgba(0,0,0,.35), inset 2px 2px 3px rgba(255,255,255,.8); }
        .su-effect-swatch.eff-inset { box-shadow: inset 0 0 5px rgba(0,0,0,.55); }
        .su-effect-swatch.eff-glow { filter: drop-shadow(0 0 3px var(--accent)) drop-shadow(0 0 6px var(--accent)); }
        .su-effect-tile span { font-size: 0.56rem; font-weight: 700; color: var(--primary); text-align: center; line-height: 1.1; }
        #modalCategorieSchema .field-block { margin-bottom: 10px; }
        #modalCategorieSchema .field-block-label { margin-bottom: 8px; }
        #modalCategorieSchema .modal-box { padding: 20px 24px; }
        .modal-box input[type=text] { width: 100%; box-sizing: border-box; padding: 11px; margin-bottom: 16px; border: 2px solid var(--accent); border-radius: 7px; font-weight: 600; outline: none; font-family: inherit; }
        .modal-actions { display: flex; justify-content: flex-end; gap: 10px; }
        .modal-actions button { padding: 10px 20px; border: none; border-radius: 7px; cursor: pointer; font-weight: 700; font-family: inherit; }
        .modal-btn-cancel { background: #eee; color: #333; }
        .modal-btn-ok { background: var(--accent); color: #fff; }

        .icon-grid { display: grid; grid-template-columns: repeat(6, minmax(0, 1fr)); gap: 8px; margin-bottom: 18px; }
        .icon-chip { display: flex; align-items: center; justify-content: center; border: 1.5px solid var(--line-strong); background: #fff; border-radius: 9px; padding: 10px 4px; cursor: pointer; color: var(--ink-500); transition: 0.15s; }
        .icon-chip:hover { border-color: var(--accent); color: var(--accent); }
        .icon-chip.active { border-color: var(--accent); background: rgba(52,152,219,0.1); color: var(--accent); }
        @media (max-width: 600px) { .icon-grid { grid-template-columns: repeat(4, minmax(0, 1fr)); } }

        .color-grid { display: grid; grid-template-columns: repeat(6, minmax(0, 1fr)); gap: 8px; margin-bottom: 18px; }
        .color-chip { width: 100%; aspect-ratio: 1; border-radius: 9px; cursor: pointer; border: 2.5px solid transparent; box-shadow: inset 0 0 0 1px rgba(0,0,0,0.08); }
        .color-chip.active { border-color: var(--primary); transform: scale(1.08); }
        .color-chip-custom { position: relative; width: 100%; aspect-ratio: 1; border-radius: 9px; cursor: pointer; border: 2.5px solid var(--line-strong); padding: 0; overflow: hidden; background: conic-gradient(red, yellow, lime, cyan, blue, magenta, red); }
        .color-chip-custom.active { border-color: var(--primary); transform: scale(1.08); }
        .color-chip-custom input[type=color] { position: absolute; inset: -4px; width: calc(100% + 8px); height: calc(100% + 8px); border: none; padding: 0; cursor: pointer; }
        .color-chip-custom-label { position: absolute; inset: 0; display: flex; align-items: center; justify-content: center; pointer-events: none; color: #fff; text-shadow: 0 1px 3px rgba(0,0,0,0.7); font-size: 0.8rem; }
        .set-swatch { flex: none; width: 14px; height: 14px; border-radius: 50%; box-shadow: inset 0 0 0 1px rgba(0,0,0,0.15); }
        .field-block textarea { padding: 11px 12px; border-radius: 8px; border: 1.5px solid var(--line-strong); font-family: inherit; font-size: 0.9rem; resize: vertical; }
        .modal-box .field-block { margin-bottom: 16px; }
        .modal-box .field-block label { display: block; margin-bottom: 6px; }
    </style>
<?php include 'pwa_head.php'; ?>
</head>
<body onclick="checkCloseSidebar(event)">

<?php $breadcrumb_label = t('param.breadcrumb'); include 'navbar.php'; ?>

<div class="container">
    <div class="page-title"><i class="fa-solid fa-sliders"></i> <?php echo t('param.page_title'); ?></div>
    <?php echo $message; ?>

    <div class="settings-shell">
    <div class="settings-tabs">
        <button type="button" class="stab <?php echo $active_tab === 'general' ? 'active' : ''; ?>" data-tab="general"><i class="fa-solid fa-building"></i> <?php echo t('param.tab_general'); ?></button>
        <button type="button" class="stab <?php echo $active_tab === 'types' ? 'active' : ''; ?>" data-tab="types"><i class="fa-solid fa-sitemap"></i> <?php echo t('param.tab_types'); ?></button>
        <button type="button" class="stab <?php echo $active_tab === 'materiel' ? 'active' : ''; ?>" data-tab="materiel"><i class="fa-solid fa-gears"></i> <?php echo t('param.tab_materiel'); ?></button>
        <button type="button" class="stab <?php echo $active_tab === 'services' ? 'active' : ''; ?>" data-tab="services"><i class="fa-solid fa-people-group"></i> <?php echo t('param.tab_services'); ?></button>
        <button type="button" class="stab <?php echo $active_tab === 'categories' ? 'active' : ''; ?>" data-tab="categories"><i class="fa-solid fa-calendar-check"></i> <?php echo t('param.tab_categories'); ?></button>
        <button type="button" class="stab <?php echo $active_tab === 'workflow' ? 'active' : ''; ?>" data-tab="workflow"><i class="fa-solid fa-flag"></i> <?php echo t('param.tab_workflow'); ?></button>
        <button type="button" class="stab <?php echo $active_tab === 'planning' ? 'active' : ''; ?>" data-tab="planning"><i class="fa-solid fa-calendar-days"></i> <?php echo t('param.tab_planning'); ?></button>
        <button type="button" class="stab <?php echo $active_tab === 'tuiles' ? 'active' : ''; ?>" data-tab="tuiles"><i class="fa-solid fa-palette"></i> <?php echo t('param.tab_tuiles'); ?></button>
        <button type="button" class="stab <?php echo $active_tab === 'schema_categories' ? 'active' : ''; ?>" data-tab="schema_categories"><i class="fa-solid fa-draw-polygon"></i> <?php echo t('param.tab_schema_categories'); ?></button>
    </div>
    <div class="settings-content">

    <div class="settings-panel" id="panel-general" style="display: <?php echo $active_tab === 'general' ? 'block' : 'none'; ?>;">
        <div class="set-card">
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>">
                <input type="hidden" name="tab_actif" class="tab-actif-field" value="general">
                <input type="hidden" name="action" value="save_general">
                <div class="field-block">
                    <label><?php echo t('param.general_nom_entreprise'); ?></label>
                    <input type="text" name="nom_entreprise" value="<?php echo htmlspecialchars($nom_entreprise); ?>" required>
                </div>
                <div class="field-block">
                    <label><?php echo t('param.general_logo'); ?></label>
                    <div class="logo-preview-row">
                        <img src="<?php echo htmlspecialchars($logo_path); ?>" class="logo-preview" onerror="this.style.display='none'">
                        <input type="file" name="logo" accept="image/png,image/jpeg,image/svg+xml,image/webp">
                    </div>
                    <p class="field-hint"><?php echo t('param.general_logo_hint'); ?></p>
                </div>
                <div class="field-block">
                    <label><?php echo t('param.general_url_portail'); ?></label>
                    <input type="url" name="url_portail" value="<?php echo htmlspecialchars($url_portail); ?>" placeholder="https://...">
                    <p class="field-hint"><?php echo t('param.general_url_portail_hint'); ?></p>
                </div>
                <div class="field-block">
                    <label><?php echo t('param.general_url_demo'); ?></label>
                    <input type="url" name="url_demo" value="<?php echo htmlspecialchars($url_demo); ?>" placeholder="https://...">
                    <p class="field-hint"><?php echo t('param.general_url_demo_hint'); ?></p>
                </div>
                <button type="submit" class="pm-btn primary"><i class="fa-solid fa-floppy-disk"></i> <?php echo t('param.btn_enregistrer'); ?></button>
            </form>
        </div>
    </div>

    <div class="settings-panel" id="panel-types" style="display: <?php echo $active_tab === 'types' ? 'block' : 'none'; ?>;">
        <div class="set-toolbar">
            <span class="set-subtitle"><?php echo count($types); ?> <?php echo t(count($types) > 1 ? 'param.n_types' : 'param.n_type'); ?> <?php echo t('param.suffix_equipement'); ?></span>
            <button type="button" class="pm-btn primary" onclick="openAddType()"><i class="fa-solid fa-plus"></i> <?php echo t('param.btn_ajouter_type'); ?></button>
        </div>
        <?php if (empty($types)): ?>
            <p class="set-empty"><?php echo t('param.empty_types'); ?></p>
        <?php endif; ?>
        <?php foreach ($types as $i => $t): ?>
        <div class="set-row">
            <span class="set-icon"><i class="fa-solid <?php echo htmlspecialchars($t['icone']); ?>"></i></span>
            <span class="set-name"><?php echo htmlspecialchars($t['nom']); ?></span>
            <div class="set-actions">
                <form method="POST" style="display:inline">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>">
                    <input type="hidden" name="tab_actif" value="types">
                    <input type="hidden" name="action" value="move_type">
                    <input type="hidden" name="id" value="<?php echo $t['id']; ?>">
                    <input type="hidden" name="direction" value="up">
                    <button type="submit" class="row-btn" <?php echo $i === 0 ? 'disabled' : ''; ?> title="<?php echo htmlspecialchars(t('param.tooltip_monter')); ?>"><i class="fa-solid fa-arrow-up"></i></button>
                </form>
                <form method="POST" style="display:inline">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>">
                    <input type="hidden" name="tab_actif" value="types">
                    <input type="hidden" name="action" value="move_type">
                    <input type="hidden" name="id" value="<?php echo $t['id']; ?>">
                    <input type="hidden" name="direction" value="down">
                    <button type="submit" class="row-btn" <?php echo $i === count($types) - 1 ? 'disabled' : ''; ?> title="<?php echo htmlspecialchars(t('param.tooltip_descendre')); ?>"><i class="fa-solid fa-arrow-down"></i></button>
                </form>
                <button type="button" class="row-btn btn-type-composants" data-id="<?php echo $t['id']; ?>" data-nom="<?php echo htmlspecialchars($t['nom']); ?>" title="<?php echo htmlspecialchars(t('param.tooltip_materiel_defaut')); ?>"><i class="fa-solid fa-list-check"></i></button>
                <button type="button" class="row-btn btn-edit-type" data-id="<?php echo $t['id']; ?>" data-nom="<?php echo htmlspecialchars($t['nom']); ?>" data-icone="<?php echo htmlspecialchars($t['icone']); ?>" title="<?php echo htmlspecialchars(t('pm.tooltip_modifier')); ?>"><i class="fa-solid fa-pen"></i></button>
                <button type="button" class="row-btn danger" onclick="openConfirmSuppr('delete_type', <?php echo $t['id']; ?>, 'types', <?php echo htmlspecialchars(json_encode(t('param.confirm_suppr_type'))); ?>)" title="<?php echo htmlspecialchars(t('pm.tooltip_supprimer')); ?>"><i class="fa-solid fa-trash"></i></button>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <div class="settings-panel" id="panel-materiel" style="display: <?php echo $active_tab === 'materiel' ? 'block' : 'none'; ?>;">
        <p class="field-hint" style="margin-bottom:14px;"><?php echo t('param.materiel_hint'); ?></p>
        <div class="set-toolbar">
            <span class="set-subtitle"><?php echo count($composants_types); ?> <?php echo t(count($composants_types) > 1 ? 'param.n_materiels' : 'param.n_materiel'); ?> <?php echo t('param.suffix_materiel'); ?></span>
            <button type="button" class="pm-btn primary" onclick="openAddComposant()"><i class="fa-solid fa-plus"></i> <?php echo t('param.btn_ajouter_materiel'); ?></button>
        </div>
        <?php if (empty($composants_types)): ?>
            <p class="set-empty"><?php echo t('param.empty_materiel'); ?></p>
        <?php endif; ?>
        <?php foreach ($composants_types as $i => $c): ?>
        <div class="set-row">
            <span class="set-icon"><i class="fa-solid fa-gear"></i></span>
            <span class="set-name"><?php echo htmlspecialchars($c['nom']); ?></span>
            <div class="set-actions">
                <form method="POST" style="display:inline">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>">
                    <input type="hidden" name="tab_actif" value="materiel">
                    <input type="hidden" name="action" value="move_composant">
                    <input type="hidden" name="id" value="<?php echo $c['id']; ?>">
                    <input type="hidden" name="direction" value="up">
                    <button type="submit" class="row-btn" <?php echo $i === 0 ? 'disabled' : ''; ?> title="<?php echo htmlspecialchars(t('param.tooltip_monter')); ?>"><i class="fa-solid fa-arrow-up"></i></button>
                </form>
                <form method="POST" style="display:inline">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>">
                    <input type="hidden" name="tab_actif" value="materiel">
                    <input type="hidden" name="action" value="move_composant">
                    <input type="hidden" name="id" value="<?php echo $c['id']; ?>">
                    <input type="hidden" name="direction" value="down">
                    <button type="submit" class="row-btn" <?php echo $i === count($composants_types) - 1 ? 'disabled' : ''; ?> title="<?php echo htmlspecialchars(t('param.tooltip_descendre')); ?>"><i class="fa-solid fa-arrow-down"></i></button>
                </form>
                <button type="button" class="row-btn btn-edit-composant" data-id="<?php echo $c['id']; ?>" data-nom="<?php echo htmlspecialchars($c['nom']); ?>" title="<?php echo htmlspecialchars(t('pm.tooltip_modifier')); ?>"><i class="fa-solid fa-pen"></i></button>
                <button type="button" class="row-btn danger" onclick="openConfirmSuppr('delete_composant', <?php echo $c['id']; ?>, 'materiel', <?php echo htmlspecialchars(json_encode(t('param.confirm_suppr_materiel'))); ?>)" title="<?php echo htmlspecialchars(t('pm.tooltip_supprimer')); ?>"><i class="fa-solid fa-trash"></i></button>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <div class="settings-panel" id="panel-services" style="display: <?php echo $active_tab === 'services' ? 'block' : 'none'; ?>;">
        <div class="set-toolbar">
            <span class="set-subtitle"><?php echo count($services); ?> <?php echo t(count($services) > 1 ? 'param.n_services' : 'param.n_service'); ?> <?php echo t(count($services) > 1 ? 'param.suffix_definis' : 'param.suffix_defini'); ?></span>
            <button type="button" class="pm-btn primary" onclick="openAddService()"><i class="fa-solid fa-plus"></i> <?php echo t('param.btn_ajouter_service'); ?></button>
        </div>
        <?php if (empty($services)): ?>
            <p class="set-empty"><?php echo t('param.empty_services'); ?></p>
        <?php endif; ?>
        <?php foreach ($services as $i => $s): ?>
        <div class="set-row">
            <span class="set-icon"><i class="fa-solid fa-tag"></i></span>
            <span class="set-name"><?php echo htmlspecialchars($s['label']); ?><span class="set-key"><?php echo htmlspecialchars($s['cle']); ?></span></span>
            <div class="set-actions">
                <form method="POST" style="display:inline">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>">
                    <input type="hidden" name="tab_actif" value="services">
                    <input type="hidden" name="action" value="move_service">
                    <input type="hidden" name="id" value="<?php echo $s['id']; ?>">
                    <input type="hidden" name="direction" value="up">
                    <button type="submit" class="row-btn" <?php echo $i === 0 ? 'disabled' : ''; ?> title="<?php echo htmlspecialchars(t('param.tooltip_monter')); ?>"><i class="fa-solid fa-arrow-up"></i></button>
                </form>
                <form method="POST" style="display:inline">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>">
                    <input type="hidden" name="tab_actif" value="services">
                    <input type="hidden" name="action" value="move_service">
                    <input type="hidden" name="id" value="<?php echo $s['id']; ?>">
                    <input type="hidden" name="direction" value="down">
                    <button type="submit" class="row-btn" <?php echo $i === count($services) - 1 ? 'disabled' : ''; ?> title="<?php echo htmlspecialchars(t('param.tooltip_descendre')); ?>"><i class="fa-solid fa-arrow-down"></i></button>
                </form>
                <button type="button" class="row-btn btn-edit-service" data-id="<?php echo $s['id']; ?>" data-label="<?php echo htmlspecialchars($s['label']); ?>" title="<?php echo htmlspecialchars(t('pm.tooltip_modifier')); ?>"><i class="fa-solid fa-pen"></i></button>
                <button type="button" class="row-btn danger" onclick="openConfirmSuppr('delete_service', <?php echo $s['id']; ?>, 'services', <?php echo htmlspecialchars(json_encode(t('param.confirm_suppr_service'))); ?>)" title="<?php echo htmlspecialchars(t('pm.tooltip_supprimer')); ?>"><i class="fa-solid fa-trash"></i></button>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <div class="settings-panel" id="panel-categories" style="display: <?php echo $active_tab === 'categories' ? 'block' : 'none'; ?>;">
        <div class="set-toolbar">
            <span class="set-subtitle"><?php echo count($categories); ?> <?php echo t(count($categories) > 1 ? 'param.n_categories' : 'param.n_categorie'); ?> <?php echo t('param.suffix_preventif'); ?></span>
            <button type="button" class="pm-btn primary" onclick="openAddCategorie()"><i class="fa-solid fa-plus"></i> <?php echo t('param.btn_ajouter_categorie'); ?></button>
        </div>
        <?php if (empty($categories)): ?>
            <p class="set-empty"><?php echo t('param.empty_categories'); ?></p>
        <?php endif; ?>
        <?php foreach ($categories as $i => $c): ?>
        <div class="set-row">
            <span class="set-icon" style="background:<?php echo htmlspecialchars($c['couleur']); ?>22; color:<?php echo htmlspecialchars($c['couleur']); ?>;"><i class="fa-solid <?php echo htmlspecialchars($c['icone']); ?>"></i></span>
            <span class="set-name"><?php echo htmlspecialchars($c['label']); ?><span class="set-key"><?php echo htmlspecialchars($c['cle']); ?></span></span>
            <div class="set-actions">
                <form method="POST" style="display:inline">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>">
                    <input type="hidden" name="tab_actif" value="categories">
                    <input type="hidden" name="action" value="move_categorie">
                    <input type="hidden" name="id" value="<?php echo $c['id']; ?>">
                    <input type="hidden" name="direction" value="up">
                    <button type="submit" class="row-btn" <?php echo $i === 0 ? 'disabled' : ''; ?> title="<?php echo htmlspecialchars(t('param.tooltip_monter')); ?>"><i class="fa-solid fa-arrow-up"></i></button>
                </form>
                <form method="POST" style="display:inline">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>">
                    <input type="hidden" name="tab_actif" value="categories">
                    <input type="hidden" name="action" value="move_categorie">
                    <input type="hidden" name="id" value="<?php echo $c['id']; ?>">
                    <input type="hidden" name="direction" value="down">
                    <button type="submit" class="row-btn" <?php echo $i === count($categories) - 1 ? 'disabled' : ''; ?> title="<?php echo htmlspecialchars(t('param.tooltip_descendre')); ?>"><i class="fa-solid fa-arrow-down"></i></button>
                </form>
                <button type="button" class="row-btn btn-edit-categorie" data-id="<?php echo $c['id']; ?>" data-label="<?php echo htmlspecialchars($c['label']); ?>" data-description="<?php echo htmlspecialchars($c['description']); ?>" data-icone="<?php echo htmlspecialchars($c['icone']); ?>" data-couleur="<?php echo htmlspecialchars($c['couleur']); ?>" title="<?php echo htmlspecialchars(t('pm.tooltip_modifier')); ?>"><i class="fa-solid fa-pen"></i></button>
                <button type="button" class="row-btn danger" onclick="openConfirmSuppr('delete_categorie', <?php echo $c['id']; ?>, 'categories', <?php echo htmlspecialchars(json_encode(t('param.confirm_suppr_categorie'))); ?>)" title="<?php echo htmlspecialchars(t('pm.tooltip_supprimer')); ?>"><i class="fa-solid fa-trash"></i></button>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <div class="settings-panel" id="panel-workflow" style="display: <?php echo $active_tab === 'workflow' ? 'block' : 'none'; ?>;">
        <p class="field-hint" style="margin-bottom:18px;"><?php echo t('param.workflow_hint'); ?></p>
        <div class="set-toolbar"><span class="set-subtitle"><?php echo t('param.workflow_statuts'); ?></span></div>
        <?php foreach ($libelles_statuts as $l): ?>
        <div class="set-row">
            <span class="set-swatch" style="background:<?php echo htmlspecialchars($l['couleur']); ?>;"></span>
            <span class="set-name"><?php echo htmlspecialchars($l['label']); ?></span>
            <div class="set-actions">
                <button type="button" class="row-btn btn-edit-libelle" data-bucket="<?php echo htmlspecialchars($l['bucket']); ?>" data-label="<?php echo htmlspecialchars($l['label']); ?>" data-couleur="<?php echo htmlspecialchars($l['couleur']); ?>" title="<?php echo htmlspecialchars(t('pm.tooltip_modifier')); ?>"><i class="fa-solid fa-pen"></i></button>
            </div>
        </div>
        <?php endforeach; ?>
        <div class="set-toolbar" style="margin-top:22px;"><span class="set-subtitle"><?php echo t('param.workflow_priorites'); ?></span></div>
        <?php foreach ($libelles_priorites as $l): ?>
        <div class="set-row">
            <span class="set-swatch" style="background:<?php echo htmlspecialchars($l['couleur']); ?>;"></span>
            <span class="set-name"><?php echo htmlspecialchars($l['label']); ?></span>
            <div class="set-actions">
                <button type="button" class="row-btn btn-edit-libelle" data-bucket="<?php echo htmlspecialchars($l['bucket']); ?>" data-label="<?php echo htmlspecialchars($l['label']); ?>" data-couleur="<?php echo htmlspecialchars($l['couleur']); ?>" title="<?php echo htmlspecialchars(t('pm.tooltip_modifier')); ?>"><i class="fa-solid fa-pen"></i></button>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <div class="settings-panel" id="panel-planning" style="display: <?php echo $active_tab === 'planning' ? 'block' : 'none'; ?>;">
        <p class="field-hint" style="margin-bottom:18px;"><?php echo t('param.planning_badges_hint'); ?></p>
        <div class="set-toolbar"><span class="set-subtitle"><?php echo t('param.planning_postes'); ?></span></div>
        <?php foreach ($planning_postes as $p): if ($p['categorie'] !== 'poste') continue; ?>
        <div class="set-row">
            <span class="set-swatch" style="background:<?php echo htmlspecialchars($p['couleur']); ?>;"></span>
            <span class="set-name"><?php echo htmlspecialchars($p['label']); ?></span>
            <div class="set-actions">
                <button type="button" class="row-btn btn-edit-planning" data-table="planning_postes" data-cle="<?php echo htmlspecialchars($p['cle']); ?>" data-label="<?php echo htmlspecialchars($p['label']); ?>" data-couleur="<?php echo htmlspecialchars($p['couleur']); ?>" title="<?php echo htmlspecialchars(t('pm.tooltip_modifier')); ?>"><i class="fa-solid fa-pen"></i></button>
            </div>
        </div>
        <?php endforeach; ?>
        <div class="set-toolbar" style="margin-top:22px;"><span class="set-subtitle"><?php echo t('param.planning_evenements'); ?></span></div>
        <?php foreach ($planning_postes as $p): if ($p['categorie'] !== 'evenement') continue; ?>
        <div class="set-row">
            <span class="set-swatch" style="background:<?php echo htmlspecialchars($p['couleur']); ?>;"></span>
            <span class="set-name"><?php echo htmlspecialchars($p['label']); ?></span>
            <div class="set-actions">
                <button type="button" class="row-btn btn-edit-planning" data-table="planning_postes" data-cle="<?php echo htmlspecialchars($p['cle']); ?>" data-label="<?php echo htmlspecialchars($p['label']); ?>" data-couleur="<?php echo htmlspecialchars($p['couleur']); ?>" title="<?php echo htmlspecialchars(t('pm.tooltip_modifier')); ?>"><i class="fa-solid fa-pen"></i></button>
            </div>
        </div>
        <?php endforeach; ?>
        <div class="set-toolbar" style="margin-top:22px;"><span class="set-subtitle"><?php echo t('param.planning_astreintes'); ?></span></div>
        <?php foreach ($planning_astreintes as $a): ?>
        <div class="set-row">
            <span class="set-swatch" style="background:<?php echo htmlspecialchars($a['couleur']); ?>;"></span>
            <span class="set-name"><?php echo htmlspecialchars($a['label']); ?></span>
            <div class="set-actions">
                <button type="button" class="row-btn btn-edit-planning" data-table="planning_astreintes" data-cle="<?php echo htmlspecialchars($a['cle']); ?>" data-label="<?php echo htmlspecialchars($a['label']); ?>" data-couleur="<?php echo htmlspecialchars($a['couleur']); ?>" title="<?php echo htmlspecialchars(t('pm.tooltip_modifier')); ?>"><i class="fa-solid fa-pen"></i></button>
            </div>
        </div>
        <?php endforeach; ?>
        <div class="set-toolbar" style="margin-top:22px;"><span class="set-subtitle"><?php echo t('param.planning_objectifs'); ?></span></div>
        <p class="field-hint" style="margin-bottom:12px;"><?php echo t('param.planning_objectifs_hint'); ?></p>
        <?php foreach ($equipe_objectifs as $eo): ?>
        <div class="set-row">
            <span class="set-name"><?php echo htmlspecialchars($eo['username']); ?></span>
            <span style="color:var(--ink-500); font-size:0.85rem; font-weight:600;"><?php echo htmlspecialchars(rtrim(rtrim(number_format((float)$eo['objectif_heures_annuel'], 2, '.', ''), '0'), '.')); ?><?php echo t('param.planning_h_an'); ?></span>
            <div class="set-actions">
                <button type="button" class="row-btn btn-edit-objectif" data-username="<?php echo htmlspecialchars($eo['username']); ?>" data-objectif="<?php echo htmlspecialchars($eo['objectif_heures_annuel']); ?>" title="<?php echo htmlspecialchars(t('pm.tooltip_modifier')); ?>"><i class="fa-solid fa-pen"></i></button>
            </div>
        </div>
        <?php endforeach; ?>
        <div class="set-toolbar" style="margin-top:22px;"><span class="set-subtitle"><?php echo t('param.planning_import'); ?></span></div>
        <p class="field-hint" style="margin-bottom:12px;"><?php echo t('param.planning_import_hint'); ?></p>
        <div style="display:flex; gap:10px; flex-wrap:wrap;">
            <a href="import_planning.php" class="modal-btn-ok" style="display:inline-flex; align-items:center; gap:8px; text-decoration:none; width:fit-content; padding:10px 18px;"><i class="fa-solid fa-file-import"></i> <?php echo t('param.btn_importer_planning'); ?></a>
            <button type="button" id="btnOuvrirResetPlanning" style="display:inline-flex; align-items:center; gap:8px; padding:10px 18px; border-radius:8px; border:1px solid #f1aeb5; background:#fff; color:#c0392b; font-weight:700; font-size:0.85rem; cursor:pointer; font-family:inherit;"<?php echo $planning_shifts_count === 0 ? ' disabled' : ''; ?>><i class="fa-solid fa-trash-can"></i> <?php echo str_replace(['{n}','{s}'], [$planning_shifts_count, $planning_shifts_count > 1 ? 's' : ''], t('param.btn_reset_planning')); ?></button>
            <button type="button" id="btnOuvrirResetHeures" style="display:inline-flex; align-items:center; gap:8px; padding:10px 18px; border-radius:8px; border:1px solid #ffe3a3; background:#fff; color:#8a5a00; font-weight:700; font-size:0.85rem; cursor:pointer; font-family:inherit;"<?php echo $planning_heures_count === 0 ? ' disabled' : ''; ?>><i class="fa-solid fa-clock-rotate-left"></i> <?php echo str_replace(['{n}','{s}'], [$planning_heures_count, $planning_heures_count > 1 ? 's' : ''], t('param.btn_reset_heures')); ?></button>
        </div>
        <p class="field-hint" style="margin-top:8px; margin-bottom:0;"><?php echo t('param.planning_reset_footer_hint'); ?></p>
    </div>

    <div class="settings-panel" id="panel-tuiles" style="display: <?php echo $active_tab === 'tuiles' ? 'block' : 'none'; ?>;">
        <p class="field-hint" style="margin-bottom:18px;"><?php echo t('param.tuiles_hint'); ?></p>
        <?php foreach ($TUILES_ACCUEIL as $t): ?>
        <div class="set-row">
            <span class="set-icon" style="background:<?php echo htmlspecialchars($t['couleur']); ?>22; color:<?php echo htmlspecialchars($t['couleur']); ?>;"><i class="fa-solid <?php echo htmlspecialchars($t['icon']); ?>"></i></span>
            <span class="set-name"><?php echo htmlspecialchars($t['titre']); ?></span>
            <div class="set-actions">
                <button type="button" class="row-btn btn-edit-tuile" data-href="<?php echo htmlspecialchars($t['href']); ?>" data-titre="<?php echo htmlspecialchars($t['titre']); ?>" data-description="<?php echo htmlspecialchars($t['desc']); ?>" data-icone="<?php echo htmlspecialchars($t['icon']); ?>" data-couleur="<?php echo htmlspecialchars($t['couleur']); ?>" title="<?php echo htmlspecialchars(t('pm.tooltip_modifier')); ?>"><i class="fa-solid fa-pen"></i></button>
            </div>
        </div>
        <?php endforeach; ?>

        <div class="set-toolbar" style="margin-top:22px;"><span class="set-subtitle"><?php echo t('param.mes_dossiers'); ?></span></div>
        <p class="field-hint" style="margin-bottom:12px;"><?php echo t('param.mes_dossiers_hint'); ?></p>
        <?php if (empty($dossiers_perso)): ?>
        <p class="set-empty"><?php echo t('param.empty_dossiers'); ?></p>
        <?php else: foreach ($dossiers_perso as $d):
            $dTitre = (string)($d['titre'] ?? t('param.dossier_fallback')); if ($dTitre === '') { $dTitre = t('param.dossier_fallback'); }
            $dDesc = (string)($d['desc'] ?? '');
            $dIcone = array_key_exists($d['icone'] ?? '', $ICONES_DOSSIER) ? $d['icone'] : 'fa-folder';
            $dCouleur = preg_match('/^#[0-9a-fA-F]{6}$/', $d['couleur'] ?? '') ? $d['couleur'] : '#34495e';
        ?>
        <div class="set-row">
            <span class="set-icon" style="background:<?php echo htmlspecialchars($dCouleur); ?>22; color:<?php echo htmlspecialchars($dCouleur); ?>;"><i class="fa-solid <?php echo htmlspecialchars($dIcone); ?>"></i></span>
            <span class="set-name"><?php echo htmlspecialchars($dTitre); ?></span>
            <div class="set-actions">
                <button type="button" class="row-btn btn-edit-dossier-perso" data-id="<?php echo htmlspecialchars($d['id'] ?? ''); ?>" data-titre="<?php echo htmlspecialchars($dTitre); ?>" data-description="<?php echo htmlspecialchars($dDesc); ?>" data-icone="<?php echo htmlspecialchars($dIcone); ?>" data-couleur="<?php echo htmlspecialchars($dCouleur); ?>" title="<?php echo htmlspecialchars(t('pm.tooltip_modifier')); ?>"><i class="fa-solid fa-pen"></i></button>
            </div>
        </div>
        <?php endforeach; endif; ?>
    </div>

    <div class="settings-panel" id="panel-schema_categories" style="display: <?php echo $active_tab === 'schema_categories' ? 'block' : 'none'; ?>;">
        <p class="field-hint" style="margin-bottom:18px;"><?php echo t('param.schema_cat_hint'); ?></p>
        <div class="set-toolbar">
            <span class="set-subtitle"><?php echo count($categories_schema); ?> <?php echo t(count($categories_schema) > 1 ? 'param.n_categories' : 'param.n_categorie'); ?></span>
            <button type="button" class="pm-btn primary" onclick="openAddCategorieSchema()"><i class="fa-solid fa-plus"></i> <?php echo t('param.btn_ajouter_categorie'); ?></button>
        </div>
        <?php if (empty($categories_schema)): ?>
            <p class="set-empty"><?php echo t('param.empty_categories_schema'); ?></p>
        <?php endif; ?>
        <?php foreach ($categories_schema as $i => $cs): ?>
        <div class="set-row">
            <span class="set-icon" style="background:<?php echo htmlspecialchars($cs['fill_color']); ?>; <?php if (!empty($cs['border_color'])): ?>box-shadow: inset 0 0 0 2px <?php echo htmlspecialchars($cs['border_color']); ?>;<?php endif; ?>"></span>
            <span class="set-name"><?php echo htmlspecialchars($cs['nom']); ?></span>
            <div class="set-actions">
                <form method="POST" style="display:inline">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>">
                    <input type="hidden" name="tab_actif" value="schema_categories">
                    <input type="hidden" name="action" value="move_categorie_schema">
                    <input type="hidden" name="id" value="<?php echo $cs['id']; ?>">
                    <input type="hidden" name="direction" value="up">
                    <button type="submit" class="row-btn" <?php echo $i === 0 ? 'disabled' : ''; ?> title="<?php echo htmlspecialchars(t('param.tooltip_monter')); ?>"><i class="fa-solid fa-arrow-up"></i></button>
                </form>
                <form method="POST" style="display:inline">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>">
                    <input type="hidden" name="tab_actif" value="schema_categories">
                    <input type="hidden" name="action" value="move_categorie_schema">
                    <input type="hidden" name="id" value="<?php echo $cs['id']; ?>">
                    <input type="hidden" name="direction" value="down">
                    <button type="submit" class="row-btn" <?php echo $i === count($categories_schema) - 1 ? 'disabled' : ''; ?> title="<?php echo htmlspecialchars(t('param.tooltip_descendre')); ?>"><i class="fa-solid fa-arrow-down"></i></button>
                </form>
                <button type="button" class="row-btn btn-edit-categorie-schema"
                    data-id="<?php echo $cs['id']; ?>" data-nom="<?php echo htmlspecialchars($cs['nom']); ?>"
                    data-label="<?php echo htmlspecialchars($cs['label'] ?? ''); ?>"
                    data-fill="<?php echo htmlspecialchars($cs['fill_color']); ?>" data-fill2="<?php echo htmlspecialchars($cs['fill_color2'] ?? ''); ?>" data-gradient="<?php echo $cs['gradient'] ? '1' : '0'; ?>" data-gradient-angle="<?php echo (int)($cs['gradient_angle'] ?? 135); ?>"
                    data-border="<?php echo htmlspecialchars($cs['border_color'] ?? ''); ?>" data-effect="<?php echo htmlspecialchars($cs['effect'] ?? 'none'); ?>"
                    data-shape="<?php echo htmlspecialchars($cs['shape_type'] ?? 'rect'); ?>" data-pos-w="<?php echo htmlspecialchars($cs['pos_w'] ?? ''); ?>" data-pos-h="<?php echo htmlspecialchars($cs['pos_h'] ?? ''); ?>"
                    data-rotation="<?php echo htmlspecialchars($cs['rotation'] ?? 0); ?>" data-text-rotation="<?php echo htmlspecialchars($cs['text_rotation'] ?? 0); ?>" data-font-size="<?php echo htmlspecialchars($cs['font_size'] ?? ''); ?>"
                    data-text-color="<?php echo htmlspecialchars($cs['text_color'] ?? ''); ?>" data-clickable="<?php echo $cs['est_clickable'] ? '1' : '0'; ?>"
                    title="<?php echo htmlspecialchars(t('pm.tooltip_modifier')); ?>"><i class="fa-solid fa-pen"></i></button>
                <button type="button" class="row-btn danger" onclick="openConfirmSuppr('delete_categorie_schema', <?php echo $cs['id']; ?>, 'schema_categories', <?php echo htmlspecialchars(json_encode(t('param.confirm_suppr_categorie_schema'))); ?>)" title="<?php echo htmlspecialchars(t('pm.tooltip_supprimer')); ?>"><i class="fa-solid fa-trash"></i></button>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    </div>
    </div>
</div>

<div class="modal-bg" id="modalDossierPerso">
    <div class="modal-box modal-box-large">
        <h3><?php echo t('param.modal_dossier_titre'); ?></h3>
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>">
            <input type="hidden" name="tab_actif" class="tab-actif-field" value="tuiles">
            <input type="hidden" name="action" value="edit_dossier_perso">
            <input type="hidden" name="id" id="dossierPersoFormId" value="">
            <div class="field-block">
                <label><?php echo t('param.champ_titre'); ?></label>
                <input type="text" name="titre" id="dossierPersoFormTitre" placeholder="<?php echo htmlspecialchars(t('param.placeholder_nom_dossier')); ?>" maxlength="30" required>
            </div>
            <textarea name="description" id="dossierPersoFormDescription" rows="2" maxlength="120" placeholder="<?php echo htmlspecialchars(t('param.placeholder_desc_optionnel')); ?>" style="width:100%; box-sizing:border-box; padding:11px; margin-bottom:12px; border:2px solid var(--accent); border-radius:7px; font-family:inherit; font-size:0.9rem; resize:vertical;"></textarea>
            <div class="dossier-form-split">
                <div class="dossier-form-col">
                    <div class="field-block-label"><?php echo t('param.champ_icone'); ?></div>
                    <div class="icon-grid" id="dossierPersoIconGrid">
                        <?php foreach ($ICONES_DOSSIER as $ic => $lbl): ?>
                        <div class="icon-chip" data-value="<?php echo htmlspecialchars($ic); ?>" title="<?php echo htmlspecialchars($lbl); ?>"><i class="fa-solid <?php echo htmlspecialchars($ic); ?>"></i></div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="dossier-form-col">
                    <div class="field-block-label"><?php echo t('param.champ_couleur'); ?></div>
                    <div class="color-grid" id="dossierPersoColorGrid">
                        <?php foreach ($COULEURS_DISPONIBLES as $col): ?>
                        <div class="color-chip" data-value="<?php echo htmlspecialchars($col); ?>" style="background:<?php echo htmlspecialchars($col); ?>;"></div>
                        <?php endforeach; ?>
                        <label class="color-chip-custom" title="<?php echo htmlspecialchars(t('param.icon_couleur_perso')); ?>">
                            <input type="color" id="dossierPersoColorCustom">
                            <span class="color-chip-custom-label"><i class="fa-solid fa-eye-dropper"></i></span>
                        </label>
                    </div>
                </div>
            </div>
            <input type="hidden" name="icone" id="dossierPersoFormIcone" value="fa-folder">
            <input type="hidden" name="couleur" id="dossierPersoFormCouleur" value="#34495e">
            <div class="modal-actions">
                <button type="button" class="modal-btn-cancel" onclick="closeModal('modalDossierPerso')"><?php echo t('pm.btn_annuler'); ?></button>
                <button type="submit" class="modal-btn-ok"><?php echo t('param.btn_enregistrer'); ?></button>
            </div>
        </form>
    </div>
</div>

<div class="modal-bg" id="modalResetPlanning">
    <div class="modal-box">
        <h3><i class="fa-solid fa-triangle-exclamation"></i> <?php echo t('param.modal_reset_planning_titre'); ?></h3>
        <p><?php echo str_replace(['{n}','{s}'], [$planning_shifts_count, $planning_shifts_count > 1 ? 's' : ''], t('param.modal_reset_planning_texte')); ?></p>
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>">
            <input type="hidden" name="tab_actif" value="planning">
            <input type="hidden" name="action" value="reset_planning">
            <div class="modal-actions">
                <button type="button" class="modal-btn-cancel" onclick="closeModal('modalResetPlanning')"><?php echo t('pm.btn_annuler'); ?></button>
                <button type="submit" class="modal-btn-ok" style="background:#e74c3c;"><?php echo t('param.btn_reinitialiser'); ?></button>
            </div>
        </form>
    </div>
</div>

<div class="modal-bg" id="modalResetHeures">
    <div class="modal-box">
        <h3><i class="fa-solid fa-triangle-exclamation"></i> <?php echo t('param.modal_reset_heures_titre'); ?></h3>
        <p><?php echo str_replace(['{n}','{s}'], [$planning_heures_count, $planning_heures_count > 1 ? 's' : ''], t('param.modal_reset_heures_texte')); ?></p>
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>">
            <input type="hidden" name="tab_actif" value="planning">
            <input type="hidden" name="action" value="reset_heures_planning">
            <div class="modal-actions">
                <button type="button" class="modal-btn-cancel" onclick="closeModal('modalResetHeures')"><?php echo t('pm.btn_annuler'); ?></button>
                <button type="submit" class="modal-btn-ok" style="background:#e67e22;"><?php echo t('param.btn_reinitialiser'); ?></button>
            </div>
        </form>
    </div>
</div>

<div class="modal-bg" id="modalObjectif">
    <div class="modal-box">
        <h3 id="objectifModalTitle"><?php echo t('param.modal_objectif_titre'); ?></h3>
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>">
            <input type="hidden" name="tab_actif" value="planning">
            <input type="hidden" name="action" value="edit_objectif_annuel">
            <input type="hidden" name="username" id="objectifFormUsername" value="">
            <div class="field-block">
                <label><?php echo t('param.champ_heures_par_an'); ?></label>
                <input type="number" name="objectif" id="objectifFormValeur" step="0.5" min="1" max="9999" required style="padding: 11px 12px; border-radius: 8px; border: 1.5px solid var(--line-strong); font-family: inherit; font-size: 0.9rem;">
            </div>
            <div class="modal-actions">
                <button type="button" class="modal-btn-cancel" onclick="closeModal('modalObjectif')"><?php echo t('pm.btn_annuler'); ?></button>
                <button type="submit" class="modal-btn-ok"><?php echo t('param.btn_enregistrer'); ?></button>
            </div>
        </form>
    </div>
</div>

<div class="modal-bg" id="modalType">
    <div class="modal-box">
        <h3 id="typeModalTitle"><?php echo t('param.modal_ajouter_type'); ?></h3>
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>">
            <input type="hidden" name="tab_actif" class="tab-actif-field" value="types">
            <input type="hidden" name="action" id="typeFormAction" value="add_type">
            <input type="hidden" name="id" id="typeFormId" value="">
            <input type="text" name="nom" id="typeFormNom" placeholder="<?php echo htmlspecialchars(t('param.placeholder_nom_type')); ?>" required>
            <div class="icon-grid" id="typeIconGrid">
                <?php foreach ($ICONES_DISPONIBLES as $ic => $lbl): ?>
                <div class="icon-chip" data-value="<?php echo htmlspecialchars($ic); ?>" title="<?php echo htmlspecialchars($lbl); ?>"><i class="fa-solid <?php echo htmlspecialchars($ic); ?>"></i></div>
                <?php endforeach; ?>
            </div>
            <input type="hidden" name="icone" id="typeFormIcone" value="fa-gear">
            <div class="modal-actions">
                <button type="button" class="modal-btn-cancel" onclick="closeModal('modalType')"><?php echo t('pm.btn_annuler'); ?></button>
                <button type="submit" class="modal-btn-ok"><?php echo t('param.btn_enregistrer'); ?></button>
            </div>
        </form>
    </div>
</div>

<div class="modal-bg" id="modalTypeComposants">
    <div class="modal-box">
        <h3 id="typeComposantsModalTitle"><?php echo t('param.modal_materiel_defaut_titre'); ?></h3>
        <p class="field-hint" style="margin-bottom:14px;"><?php echo t('param.materiel_defaut_hint'); ?></p>
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>">
            <input type="hidden" name="tab_actif" class="tab-actif-field" value="types">
            <input type="hidden" name="action" value="save_type_composants">
            <input type="hidden" name="type_id" id="typeComposantsFormTypeId" value="">
            <?php if (empty($composants_types)): ?>
                <p class="set-empty"><?php echo t('param.empty_materiel_types'); ?></p>
            <?php else: ?>
            <div id="typeComposantsList" style="max-height:280px; overflow-y:auto; margin-bottom:16px;">
                <?php foreach ($composants_types as $c): ?>
                <label style="display:flex; align-items:center; gap:8px; padding:6px 0; font-weight:400;">
                    <input type="checkbox" name="composant_type_ids[]" value="<?php echo $c['id']; ?>">
                    <?php echo htmlspecialchars($c['nom']); ?>
                </label>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
            <div class="modal-actions">
                <button type="button" class="modal-btn-cancel" onclick="closeModal('modalTypeComposants')"><?php echo t('pm.btn_annuler'); ?></button>
                <button type="submit" class="modal-btn-ok"><?php echo t('param.btn_enregistrer'); ?></button>
            </div>
        </form>
    </div>
</div>

<div class="modal-bg" id="modalComposant">
    <div class="modal-box">
        <h3 id="composantModalTitle"><?php echo t('param.modal_ajouter_materiel'); ?></h3>
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>">
            <input type="hidden" name="tab_actif" class="tab-actif-field" value="materiel">
            <input type="hidden" name="action" id="composantFormAction" value="add_composant">
            <input type="hidden" name="id" id="composantFormId" value="">
            <input type="text" name="nom" id="composantFormNom" placeholder="<?php echo htmlspecialchars(t('param.placeholder_nom_materiel')); ?>" required>
            <div class="modal-actions">
                <button type="button" class="modal-btn-cancel" onclick="closeModal('modalComposant')"><?php echo t('pm.btn_annuler'); ?></button>
                <button type="submit" class="modal-btn-ok"><?php echo t('param.btn_enregistrer'); ?></button>
            </div>
        </form>
    </div>
</div>

<div class="modal-bg" id="modalCategorieSchema">
    <div class="modal-box" style="width:min(1080px, 96vw); max-height:94vh;">
        <h3 id="categorieSchemaModalTitle"><?php echo t('param.modal_ajouter_categorie_schema'); ?></h3>
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>">
            <input type="hidden" name="tab_actif" class="tab-actif-field" value="schema_categories">
            <input type="hidden" name="action" id="categorieSchemaFormAction" value="add_categorie_schema">
            <input type="hidden" name="id" id="categorieSchemaFormId" value="">
            <input type="text" name="nom" id="categorieSchemaFormNom" placeholder="<?php echo htmlspecialchars(t('param.placeholder_nom_categorie_schema')); ?>" required>
            <div class="dossier-form-split">
                <div class="dossier-form-col">
                    <div class="field-block-label"><?php echo t('param.apparence'); ?> <span style="font-weight:400; text-transform:none; color:var(--ink-500);"><?php echo t('param.apparence_hint'); ?></span></div>
                    <div class="field-block">
                        <label><?php echo t('param.couleur_de_fond'); ?></label>
                        <input type="color" name="fill_color" id="categorieSchemaFormFill" style="height:42px; width:100%; padding:2px; border-radius:7px; border:1.5px solid var(--line-strong); cursor:pointer;">
                    </div>
                    <div class="field-block">
                        <label><input type="checkbox" id="categorieSchemaFormGradient" name="gradient" style="margin-right:6px;"><?php echo t('pm.props_degrade'); ?></label>
                    </div>
                    <div class="field-block" id="categorieSchemaFill2Wrap" style="display:none;">
                        <label><?php echo t('param.couleur_de_fond_2'); ?></label>
                        <input type="color" name="fill_color2" id="categorieSchemaFormFill2" style="height:42px; width:100%; padding:2px; border-radius:7px; border:1.5px solid var(--line-strong); cursor:pointer;">
                        <input type="hidden" name="gradient_angle" id="categorieSchemaFormGradientAngle" value="135">
                        <div class="su-dir-row" id="categorieSchemaGradientDirRow">
                            <button type="button" class="su-dir-tile" data-angle="135" title="<?php echo htmlspecialchars(t('pm.props_dir_diagonal_tooltip')); ?>"><span class="su-dir-arrow">↘</span><span><?php echo t('pm.props_dir_diagonal'); ?></span></button>
                            <button type="button" class="su-dir-tile" data-angle="90" title="<?php echo htmlspecialchars(t('pm.props_dir_gd_tooltip')); ?>"><span class="su-dir-arrow">→</span><span><?php echo t('pm.props_dir_gd'); ?></span></button>
                            <button type="button" class="su-dir-tile" data-angle="180" title="<?php echo htmlspecialchars(t('pm.props_dir_hb_tooltip')); ?>"><span class="su-dir-arrow">↓</span><span><?php echo t('pm.props_dir_hb'); ?></span></button>
                            <button type="button" class="su-dir-tile" data-angle="270" title="<?php echo htmlspecialchars(t('pm.props_dir_dg_tooltip')); ?>"><span class="su-dir-arrow">←</span><span><?php echo t('pm.props_dir_dg'); ?></span></button>
                            <button type="button" class="su-dir-tile" data-angle="0" title="<?php echo htmlspecialchars(t('pm.props_dir_bh_tooltip')); ?>"><span class="su-dir-arrow">↑</span><span><?php echo t('pm.props_dir_bh'); ?></span></button>
                        </div>
                    </div>
                    <div class="field-block">
                        <label><input type="checkbox" id="categorieSchemaFormBorderNone" name="border_none" style="margin-right:6px;"><?php echo t('pm.props_sans_bordure'); ?></label>
                    </div>
                    <div class="field-block" id="categorieSchemaBorderWrap">
                        <label><?php echo t('param.couleur_de_bordure'); ?></label>
                        <input type="color" name="border_color" id="categorieSchemaFormBorder" style="height:42px; width:100%; padding:2px; border-radius:7px; border:1.5px solid var(--line-strong); cursor:pointer;">
                    </div>
                    <div class="field-block">
                        <label><?php echo t('param.effet_visuel'); ?></label>
                        <input type="hidden" name="effect" id="categorieSchemaFormEffect" value="none">
                        <div class="su-effect-row" id="categorieSchemaEffectRow">
                            <button type="button" class="su-effect-tile active" data-effect="none"><span class="su-effect-swatch"></span><span><?php echo t('pm.effet_aucun'); ?></span></button>
                            <button type="button" class="su-effect-tile" data-effect="shadow"><span class="su-effect-swatch eff-shadow"></span><span><?php echo t('pm.effet_ombre'); ?></span></button>
                            <button type="button" class="su-effect-tile" data-effect="bevel"><span class="su-effect-swatch eff-bevel"></span><span><?php echo t('pm.effet_relief'); ?></span></button>
                            <button type="button" class="su-effect-tile" data-effect="inset"><span class="su-effect-swatch eff-inset"></span><span><?php echo t('pm.effet_creux'); ?></span></button>
                            <button type="button" class="su-effect-tile" data-effect="glow"><span class="su-effect-swatch eff-glow"></span><span><?php echo t('pm.effet_lueur'); ?></span></button>
                        </div>
                    </div>
                </div>
                <div class="dossier-form-col">
                    <div class="field-block-label"><?php echo t('param.valeurs_depart'); ?> <span style="font-weight:400; text-transform:none; color:var(--ink-500);"><?php echo t('param.valeurs_depart_hint'); ?></span></div>
                    <div class="field-block">
                        <label><?php echo t('param.texte_par_defaut'); ?> <span style="font-weight:400; text-transform:none;"><?php echo t('param.optionnel'); ?></span></label>
                        <input type="text" name="label" id="categorieSchemaFormLabel" placeholder="<?php echo htmlspecialchars(t('param.placeholder_ex_vis')); ?>" style="width:100%; box-sizing:border-box; padding:11px 12px; border-radius:8px; border:1.5px solid var(--line-strong); font-family:inherit; font-size:0.9rem;">
                    </div>
                    <div class="field-block">
                        <label><?php echo t('param.champ_forme'); ?></label>
                        <select name="shape_type" id="categorieSchemaFormShape">
                            <?php foreach ($SHAPE_FAMILIES_SCHEMA as $famille => $formes): ?>
                            <optgroup label="<?php echo htmlspecialchars($famille); ?>">
                                <?php foreach ($formes as $slug => $label): ?>
                                <option value="<?php echo htmlspecialchars($slug); ?>"><?php echo htmlspecialchars($label); ?></option>
                                <?php endforeach; ?>
                            </optgroup>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div style="display:grid; grid-template-columns:1fr 1fr; gap:10px;">
                        <div class="field-block"><label><?php echo t('param.champ_largeur_pct'); ?></label><input type="number" name="pos_w" id="categorieSchemaFormPosW" step="0.5" min="0.5" max="100" placeholder="<?php echo htmlspecialchars(t('param.placeholder_ex_10')); ?>" style="height:42px;"></div>
                        <div class="field-block"><label><?php echo t('param.champ_hauteur_pct'); ?></label><input type="number" name="pos_h" id="categorieSchemaFormPosH" step="0.5" min="0.5" max="100" placeholder="<?php echo htmlspecialchars(t('param.placeholder_ex_8')); ?>" style="height:42px;"></div>
                        <div class="field-block"><label><?php echo t('param.champ_rotation_forme'); ?></label><input type="number" name="rotation" id="categorieSchemaFormRotation" step="1" min="-360" max="360" value="0" style="height:42px;"></div>
                        <div class="field-block"><label><?php echo t('param.champ_rotation_texte'); ?></label><input type="number" name="text_rotation" id="categorieSchemaFormTextRotation" step="1" min="-360" max="360" value="0" style="height:42px;"></div>
                    </div>
                    <div style="display:grid; grid-template-columns:1fr 1fr; gap:10px;">
                        <div class="field-block"><label><?php echo t('param.champ_taille_texte'); ?></label><input type="number" name="font_size" id="categorieSchemaFormFontSize" step="0.05" min="0.3" max="3" placeholder="auto" style="height:42px;"></div>
                        <div class="field-block" id="categorieSchemaTextColorWrap" style="display:none;">
                            <label><?php echo t('param.champ_couleur_texte'); ?></label>
                            <input type="color" name="text_color" id="categorieSchemaFormTextColor" style="height:42px; width:100%; padding:2px; border-radius:7px; border:1.5px solid var(--line-strong); cursor:pointer;">
                        </div>
                    </div>
                    <div style="display:grid; grid-template-columns:1fr 1fr; gap:10px;">
                        <div class="field-block">
                            <label style="text-transform:none; font-weight:500;"><input type="checkbox" id="categorieSchemaFormTextColorAuto" name="text_color_auto" style="margin-right:6px;" checked><?php echo t('param.texte_auto_contraste'); ?></label>
                        </div>
                        <div class="field-block">
                            <label style="text-transform:none; font-weight:500;"><input type="checkbox" name="est_clickable" id="categorieSchemaFormClickable" style="margin-right:6px;" checked><?php echo t('param.cliquable'); ?></label>
                        </div>
                    </div>
                </div>
                <div class="dossier-form-col">
                    <div class="field-block-label"><?php echo t('param.apercu_direct'); ?></div>
                    <div id="categorieSchemaPreviewWrap" style="height:220px; border-radius:10px; border:1px solid var(--line); background:var(--surface-2); display:flex; align-items:center; justify-content:center; overflow:hidden;">
                        <div id="categorieSchemaPreview" style="position:relative; width:180px; height:100px; display:flex; align-items:center; justify-content:center;">
                            <span id="categorieSchemaPreviewLabel" style="position:relative; white-space:nowrap; font-weight:700;"></span>
                        </div>
                    </div>
                    <p class="field-hint" style="margin-top:10px;"><?php echo t('param.apercu_direct_hint'); ?></p>
                </div>
            </div>
            <div class="modal-actions">
                <button type="button" class="modal-btn-cancel" onclick="closeModal('modalCategorieSchema')"><?php echo t('pm.btn_annuler'); ?></button>
                <button type="submit" class="modal-btn-ok"><?php echo t('param.btn_enregistrer'); ?></button>
            </div>
        </form>
    </div>
</div>

<div class="modal-bg" id="modalConfirmSuppr">
    <div class="modal-box">
        <h3><?php echo t('param.confirmer'); ?></h3>
        <p id="confirmSupprText" style="color:#666; font-size:0.9rem; margin-bottom:18px;"><?php echo t('param.action_definitive'); ?></p>
        <form method="POST" id="confirmSupprForm">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>">
            <input type="hidden" name="tab_actif" class="tab-actif-field" id="confirmSupprTab" value="">
            <input type="hidden" name="action" id="confirmSupprAction" value="">
            <input type="hidden" name="id" id="confirmSupprId" value="">
            <div class="modal-actions">
                <button type="button" class="modal-btn-cancel" onclick="closeModal('modalConfirmSuppr')"><?php echo t('pm.btn_annuler'); ?></button>
                <button type="submit" class="modal-btn-ok" style="background:#e74c3c;"><?php echo t('pm.delete_btn'); ?></button>
            </div>
        </form>
    </div>
</div>

<div class="modal-bg" id="modalService">
    <div class="modal-box">
        <h3 id="serviceModalTitle"><?php echo t('param.modal_ajouter_service'); ?></h3>
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>">
            <input type="hidden" name="tab_actif" class="tab-actif-field" value="services">
            <input type="hidden" name="action" id="serviceFormAction" value="add_service">
            <input type="hidden" name="id" id="serviceFormId" value="">
            <input type="text" name="label" id="serviceFormLabel" placeholder="<?php echo htmlspecialchars(t('param.placeholder_nom_service')); ?>" required>
            <div class="modal-actions">
                <button type="button" class="modal-btn-cancel" onclick="closeModal('modalService')"><?php echo t('pm.btn_annuler'); ?></button>
                <button type="submit" class="modal-btn-ok"><?php echo t('param.btn_enregistrer'); ?></button>
            </div>
        </form>
    </div>
</div>

<div class="modal-bg" id="modalCategorie">
    <div class="modal-box">
        <h3 id="categorieModalTitle"><?php echo t('param.modal_ajouter_categorie'); ?></h3>
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>">
            <input type="hidden" name="tab_actif" class="tab-actif-field" value="categories">
            <input type="hidden" name="action" id="categorieFormAction" value="add_categorie">
            <input type="hidden" name="id" id="categorieFormId" value="">
            <div class="field-block">
                <label><?php echo t('param.champ_nom'); ?></label>
                <input type="text" name="label" id="categorieFormLabel" placeholder="<?php echo htmlspecialchars(t('param.placeholder_nom_categorie')); ?>" required>
            </div>
            <div class="field-block">
                <label><?php echo t('param.champ_description'); ?> <span style="text-transform:none; font-weight:400;"><?php echo t('param.description_hint_accueil'); ?></span></label>
                <textarea name="description" id="categorieFormDescription" rows="2" placeholder="<?php echo htmlspecialchars(t('param.placeholder_courte_description')); ?>"></textarea>
            </div>
            <div class="icon-grid" id="categorieIconGrid">
                <?php foreach ($ICONES_DISPONIBLES as $ic => $lbl): ?>
                <div class="icon-chip" data-value="<?php echo htmlspecialchars($ic); ?>" title="<?php echo htmlspecialchars($lbl); ?>"><i class="fa-solid <?php echo htmlspecialchars($ic); ?>"></i></div>
                <?php endforeach; ?>
            </div>
            <input type="hidden" name="icone" id="categorieFormIcone" value="fa-gear">
            <div class="color-grid" id="categorieColorGrid">
                <?php foreach ($COULEURS_DISPONIBLES as $col): ?>
                <div class="color-chip" data-value="<?php echo htmlspecialchars($col); ?>" style="background:<?php echo htmlspecialchars($col); ?>;"></div>
                <?php endforeach; ?>
                <label class="color-chip-custom" title="<?php echo htmlspecialchars(t('param.icon_couleur_perso')); ?>">
                    <input type="color" id="categorieColorCustom">
                    <span class="color-chip-custom-label"><i class="fa-solid fa-eye-dropper"></i></span>
                </label>
            </div>
            <input type="hidden" name="couleur" id="categorieFormCouleur" value="#3498db">
            <div class="modal-actions">
                <button type="button" class="modal-btn-cancel" onclick="closeModal('modalCategorie')"><?php echo t('pm.btn_annuler'); ?></button>
                <button type="submit" class="modal-btn-ok"><?php echo t('param.btn_enregistrer'); ?></button>
            </div>
        </form>
    </div>
</div>

<div class="modal-bg" id="modalLibelle">
    <div class="modal-box">
        <h3 id="libelleModalTitle"><?php echo t('param.modal_libelle_titre'); ?></h3>
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>">
            <input type="hidden" name="tab_actif" class="tab-actif-field" value="workflow">
            <input type="hidden" name="action" value="edit_libelle">
            <input type="hidden" name="bucket" id="libelleFormBucket" value="">
            <div class="field-block">
                <label><?php echo t('param.champ_libelle_affiche'); ?></label>
                <input type="text" name="label" id="libelleFormLabel" placeholder="<?php echo htmlspecialchars(t('param.placeholder_libelle')); ?>" required>
            </div>
            <div class="color-grid" id="libelleColorGrid">
                <?php foreach ($COULEURS_DISPONIBLES as $col): ?>
                <div class="color-chip" data-value="<?php echo htmlspecialchars($col); ?>" style="background:<?php echo htmlspecialchars($col); ?>;"></div>
                <?php endforeach; ?>
                <label class="color-chip-custom" title="<?php echo htmlspecialchars(t('param.icon_couleur_perso')); ?>">
                    <input type="color" id="libelleColorCustom">
                    <span class="color-chip-custom-label"><i class="fa-solid fa-eye-dropper"></i></span>
                </label>
            </div>
            <input type="hidden" name="couleur" id="libelleFormCouleur" value="#3498db">
            <div class="modal-actions">
                <button type="button" class="modal-btn-cancel" onclick="closeModal('modalLibelle')"><?php echo t('pm.btn_annuler'); ?></button>
                <button type="submit" class="modal-btn-ok"><?php echo t('param.btn_enregistrer'); ?></button>
            </div>
        </form>
    </div>
</div>

<div class="modal-bg" id="modalPlanningCouleur">
    <div class="modal-box">
        <h3><?php echo t('param.modal_libelle_titre'); ?></h3>
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>">
            <input type="hidden" name="tab_actif" class="tab-actif-field" value="planning">
            <input type="hidden" name="action" value="edit_planning_couleur">
            <input type="hidden" name="table" id="planningFormTable" value="">
            <input type="hidden" name="cle" id="planningFormCle" value="">
            <div class="field-block">
                <label><?php echo t('param.champ_libelle_affiche'); ?></label>
                <input type="text" name="label" id="planningFormLabel" placeholder="<?php echo htmlspecialchars(t('param.placeholder_libelle')); ?>" required>
            </div>
            <div class="color-grid" id="planningColorGrid">
                <?php foreach ($COULEURS_DISPONIBLES as $col): ?>
                <div class="color-chip" data-value="<?php echo htmlspecialchars($col); ?>" style="background:<?php echo htmlspecialchars($col); ?>;"></div>
                <?php endforeach; ?>
                <label class="color-chip-custom" title="<?php echo htmlspecialchars(t('param.icon_couleur_perso')); ?>">
                    <input type="color" id="planningColorCustom">
                    <span class="color-chip-custom-label"><i class="fa-solid fa-eye-dropper"></i></span>
                </label>
            </div>
            <input type="hidden" name="couleur" id="planningFormCouleur" value="#3498db">
            <div class="modal-actions">
                <button type="button" class="modal-btn-cancel" onclick="closeModal('modalPlanningCouleur')"><?php echo t('pm.btn_annuler'); ?></button>
                <button type="submit" class="modal-btn-ok"><?php echo t('param.btn_enregistrer'); ?></button>
            </div>
        </form>
    </div>
</div>

<div class="modal-bg" id="modalTuileCouleur">
    <div class="modal-box modal-box-large">
        <h3 id="tuileModalTitle"><?php echo t('param.modal_tuile_titre'); ?></h3>
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>">
            <input type="hidden" name="tab_actif" class="tab-actif-field" value="tuiles">
            <input type="hidden" name="action" value="edit_tuile">
            <input type="hidden" name="href" id="tuileFormHref" value="">
            <div class="field-block">
                <label><?php echo t('param.champ_titre'); ?></label>
                <input type="text" name="titre" id="tuileFormTitre" placeholder="<?php echo htmlspecialchars(t('param.placeholder_titre')); ?>" maxlength="60" required>
            </div>
            <div class="field-block">
                <label><?php echo t('param.champ_description'); ?> <span style="text-transform:none; font-weight:400;"><?php echo t('param.description_hint_tuile'); ?></span></label>
                <textarea name="description" id="tuileFormDescription" rows="2" maxlength="255" placeholder="<?php echo htmlspecialchars(t('param.placeholder_courte_description')); ?>"></textarea>
            </div>
            <div class="dossier-form-split">
                <div class="dossier-form-col">
                    <div class="field-block-label"><?php echo t('param.champ_icone'); ?></div>
                    <div class="icon-grid" id="tuileIconGrid">
                        <?php foreach ($ICONES_DOSSIER as $ic => $lbl): ?>
                        <div class="icon-chip" data-value="<?php echo htmlspecialchars($ic); ?>" title="<?php echo htmlspecialchars($lbl); ?>"><i class="fa-solid <?php echo htmlspecialchars($ic); ?>"></i></div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="dossier-form-col">
                    <div class="field-block-label"><?php echo t('param.champ_couleur'); ?></div>
                    <div class="color-grid" id="tuileColorGrid">
                        <?php foreach ($COULEURS_DISPONIBLES as $col): ?>
                        <div class="color-chip" data-value="<?php echo htmlspecialchars($col); ?>" style="background:<?php echo htmlspecialchars($col); ?>;"></div>
                        <?php endforeach; ?>
                        <label class="color-chip-custom" title="<?php echo htmlspecialchars(t('param.icon_couleur_perso')); ?>">
                            <input type="color" id="tuileColorCustom">
                            <span class="color-chip-custom-label"><i class="fa-solid fa-eye-dropper"></i></span>
                        </label>
                    </div>
                </div>
            </div>
            <input type="hidden" name="icone" id="tuileFormIcone" value="fa-gear">
            <input type="hidden" name="couleur" id="tuileFormCouleur" value="#3498db">
            <div class="modal-actions">
                <button type="button" class="modal-btn-cancel" onclick="closeModal('modalTuileCouleur')"><?php echo t('pm.btn_annuler'); ?></button>
                <button type="submit" class="modal-btn-ok"><?php echo t('param.btn_enregistrer'); ?></button>
            </div>
        </form>
    </div>
</div>

<script>
const I18N_PARAM = <?php
    $__param_i18n = array_filter($GLOBALS['__gmao_i18n'], function ($k) { return strpos($k, 'param.') === 0; }, ARRAY_FILTER_USE_KEY);
    $__param_i18n_short = [];
    foreach ($__param_i18n as $k => $v) { $__param_i18n_short[substr($k, 6)] = $v; }
    echo json_encode($__param_i18n_short);
?>;

function openNav(e) { if (e) e.stopPropagation(); document.getElementById("mySidebar").style.width = "280px"; }
function closeNav() { document.getElementById("mySidebar").style.width = "0"; }
function checkCloseSidebar(event) { if (document.getElementById("mySidebar").style.width === "280px" && !document.getElementById("mySidebar").contains(event.target)) closeNav(); }

document.querySelectorAll('.stab').forEach(function (btn) {
    btn.addEventListener('click', function () {
        document.querySelectorAll('.stab').forEach(function (b) { b.classList.remove('active'); });
        document.querySelectorAll('.settings-panel').forEach(function (p) { p.style.display = 'none'; });
        btn.classList.add('active');
        document.getElementById('panel-' + btn.dataset.tab).style.display = 'block';
        document.querySelectorAll('.tab-actif-field').forEach(function (i) { i.value = btn.dataset.tab; });
    });
});

function closeModal(id) { document.getElementById(id).classList.remove('show'); }

function openAddType() {
    document.getElementById('typeModalTitle').textContent = I18N_PARAM.modal_ajouter_type;
    document.getElementById('typeFormAction').value = 'add_type';
    document.getElementById('typeFormId').value = '';
    document.getElementById('typeFormNom').value = '';
    setIconValue('fa-gear');
    document.getElementById('modalType').classList.add('show');
}
function openEditType(id, nom, icone) {
    document.getElementById('typeModalTitle').textContent = I18N_PARAM.modal_modifier_type;
    document.getElementById('typeFormAction').value = 'edit_type';
    document.getElementById('typeFormId').value = id;
    document.getElementById('typeFormNom').value = nom;
    setIconValue(icone);
    document.getElementById('modalType').classList.add('show');
}
function setIconValue(val) {
    document.getElementById('typeFormIcone').value = val;
    document.querySelectorAll('#typeIconGrid .icon-chip').forEach(function (c) {
        c.classList.toggle('active', c.dataset.value === val);
    });
}
document.querySelectorAll('#typeIconGrid .icon-chip').forEach(function (chip) {
    chip.addEventListener('click', function () { setIconValue(chip.dataset.value); });
});
document.querySelectorAll('.btn-edit-type').forEach(function (btn) {
    btn.addEventListener('click', function () { openEditType(btn.dataset.id, btn.dataset.nom, btn.dataset.icone); });
});

var TYPE_COMPOSANTS_MAP = <?php echo json_encode($type_composants_map); ?>;
function openTypeComposants(id, nom) {
    document.getElementById('typeComposantsModalTitle').textContent = I18N_PARAM.modal_materiel_defaut_titre_nom.replace('{nom}', nom);
    document.getElementById('typeComposantsFormTypeId').value = id;
    var checked = (TYPE_COMPOSANTS_MAP[id] || []).map(String);
    document.querySelectorAll('#typeComposantsList input[type=checkbox]').forEach(function (cb) {
        cb.checked = checked.indexOf(cb.value) !== -1;
    });
    document.getElementById('modalTypeComposants').classList.add('show');
}
document.querySelectorAll('.btn-type-composants').forEach(function (btn) {
    btn.addEventListener('click', function () { openTypeComposants(btn.dataset.id, btn.dataset.nom); });
});

function openAddComposant() {
    document.getElementById('composantModalTitle').textContent = I18N_PARAM.modal_ajouter_materiel;
    document.getElementById('composantFormAction').value = 'add_composant';
    document.getElementById('composantFormId').value = '';
    document.getElementById('composantFormNom').value = '';
    document.getElementById('modalComposant').classList.add('show');
}
function openEditComposant(id, nom) {
    document.getElementById('composantModalTitle').textContent = I18N_PARAM.modal_modifier_materiel;
    document.getElementById('composantFormAction').value = 'edit_composant';
    document.getElementById('composantFormId').value = id;
    document.getElementById('composantFormNom').value = nom;
    document.getElementById('modalComposant').classList.add('show');
}
document.querySelectorAll('.btn-edit-composant').forEach(function (btn) {
    btn.addEventListener('click', function () { openEditComposant(btn.dataset.id, btn.dataset.nom); });
});

// Copie exacte de SU_SHAPE_CSS / suShapeCss (admin_machines.php) — pour que l'aperçu affiche la
// vraie découpe de chaque forme, pas juste un rectangle coloré.
const CS_SHAPE_CSS = {
    rect: { radius: '3px', clip: 'none' },
    roundRect: { radius: '16%', clip: 'none' },
    ellipse: { radius: '50%', clip: 'none' },
    triangle: { radius: '0', clip: 'polygon(50% 0%, 0% 100%, 100% 100%)' },
    diamond: { radius: '0', clip: 'polygon(50% 0%, 100% 50%, 50% 100%, 0% 50%)' },
    pentagon: { radius: '0', clip: 'polygon(50% 0%, 100% 38%, 82% 100%, 18% 100%, 0% 38%)' },
    hexagon: { radius: '0', clip: 'polygon(25% 0%, 75% 0%, 100% 50%, 75% 100%, 25% 100%, 0% 50%)' },
    star: { radius: '0', clip: 'polygon(50% 0%,61% 35%,98% 35%,68% 57%,79% 91%,50% 70%,21% 91%,32% 57%,2% 35%,39% 35%)' },
    arrow_right: { radius: '0', clip: 'polygon(0% 20%,60% 20%,60% 0%,100% 50%,60% 100%,60% 80%,0% 80%)' },
    arrow_left: { radius: '0', clip: 'polygon(100% 20%,40% 20%,40% 0%,0% 50%,40% 100%,40% 80%,100% 80%)' },
    arrow_up: { radius: '0', clip: 'polygon(20% 100%,20% 40%,0% 40%,50% 0%,100% 40%,80% 40%,80% 100%)' },
    arrow_down: { radius: '0', clip: 'polygon(20% 0%,20% 60%,0% 60%,50% 100%,100% 60%,80% 60%,80% 0%)' },
    parallelogram: { radius: '0', clip: 'polygon(20% 0%,100% 0%,80% 100%,0% 100%)' },
    octagon: { radius: '0', clip: 'polygon(30% 0%,70% 0%,100% 30%,100% 70%,70% 100%,30% 100%,0% 70%,0% 30%)' },
    trapezoid: { radius: '0', clip: 'polygon(20% 0%,80% 0%,100% 100%,0% 100%)' },
    trapezoid_inv: { radius: '0', clip: 'polygon(0% 0%,100% 0%,80% 100%,20% 100%)' },
    cross: { radius: '0', clip: 'polygon(35% 0%,65% 0%,65% 35%,100% 35%,100% 65%,65% 65%,65% 100%,35% 100%,35% 65%,0% 65%,0% 35%,35% 35%)' },
    chevron_right: { radius: '0', clip: 'polygon(0% 0%,60% 0%,100% 50%,60% 100%,0% 100%,40% 50%)' },
    chevron_left: { radius: '0', clip: 'polygon(100% 0%,40% 0%,0% 50%,40% 100%,100% 100%,60% 50%)' },
    right_triangle: { radius: '0', clip: 'polygon(0% 0%,0% 100%,100% 100%)' },
    semicircle: { radius: '0', clip: 'polygon(0% 50%,3.8% 30.9%,14.6% 14.6%,30.9% 3.8%,50% 0%,69.1% 3.8%,85.4% 14.6%,96.2% 30.9%,100% 50%,100% 100%,0% 100%)' },
    double_arrow_h: { radius: '0', clip: 'polygon(10% 50%,30% 20%,30% 40%,70% 40%,70% 20%,90% 50%,70% 80%,70% 60%,30% 60%,30% 80%)' },
    double_arrow_v: { radius: '0', clip: 'polygon(50% 10%,20% 30%,40% 30%,40% 70%,20% 70%,50% 90%,80% 70%,60% 70%,60% 30%,80% 30%)' },
    bevel_rect: { radius: '0', clip: 'polygon(12% 0%,88% 0%,100% 12%,100% 88%,88% 100%,12% 100%,0% 88%,0% 12%)' },
    plate_left: { radius: '0', clip: 'polygon(30% 0%,100% 0%,100% 100%,30% 100%,0% 50%)' },
    plate_right: { radius: '0', clip: 'polygon(0% 0%,70% 0%,100% 50%,70% 100%,0% 100%)' },
    triangle_down: { radius: '0', clip: 'polygon(0% 0%,100% 0%,50% 100%)' },
    triangle_left: { radius: '0', clip: 'polygon(100% 0%,100% 100%,0% 50%)' },
    triangle_right: { radius: '0', clip: 'polygon(0% 0%,0% 100%,100% 50%)' },
    quarter_circle: { radius: '0', clip: 'polygon(0% 100%,100% 100%,92.4% 61.7%,70.7% 29.3%,38.3% 7.6%,0% 0%)' },
    hexagon_v: { radius: '0', clip: 'polygon(50% 0%,100% 25%,100% 75%,50% 100%,0% 75%,0% 25%)' },
    l_shape: { radius: '0', clip: 'polygon(0% 0%,40% 0%,40% 60%,100% 60%,100% 100%,0% 100%)' },
    t_shape: { radius: '0', clip: 'polygon(0% 0%,100% 0%,100% 30%,65% 30%,65% 100%,35% 100%,35% 30%,0% 30%)' },
    rt_tl: { radius: '0', clip: 'polygon(0% 0%,100% 0%,0% 100%)' },
    rt_tr: { radius: '0', clip: 'polygon(0% 0%,100% 0%,100% 100%)' },
    rt_br: { radius: '0', clip: 'polygon(100% 0%,100% 100%,0% 100%)' },
    shield: { radius: '0', clip: 'polygon(0% 0%,100% 0%,100% 60%,50% 100%,0% 60%)' },
    ribbon_right: { radius: '0', clip: 'polygon(0% 0%,85% 0%,100% 50%,85% 100%,0% 100%,15% 50%)' },
    ribbon_left: { radius: '0', clip: 'polygon(100% 0%,15% 0%,0% 50%,15% 100%,100% 100%,85% 50%)' },
    rect_snip_1: { radius: '0', clip: 'polygon(0% 0%,85% 0%,100% 15%,100% 100%,0% 100%)' },
    rect_snip_2_same: { radius: '0', clip: 'polygon(15% 0%,85% 0%,100% 15%,100% 100%,0% 100%,0% 15%)' },
    rect_snip_diag: { radius: '0', clip: 'polygon(15% 0%,100% 0%,100% 85%,85% 100%,0% 100%,0% 15%)' },
    rect_round_1: { radius: '0', clip: 'polygon(0% 0%,75% 0%,88% 2%,96% 9%,100% 22%,100% 100%,0% 100%)' },
    heptagon: { radius: '0', clip: 'polygon(50% 0%,89.1% 18.8%,98.7% 61.1%,71.7% 95%,28.3% 95%,1.3% 61.1%,10.9% 18.8%)' },
    decagon: { radius: '0', clip: 'polygon(50% 0%,79.4% 19.1%,97.6% 34.5%,97.6% 65.5%,79.4% 80.9%,50% 100%,20.6% 80.9%,2.4% 65.5%,2.4% 34.5%,20.6% 19.1%)' },
    dodecagon: { radius: '0', clip: 'polygon(50% 0%,75% 13.4%,93.3% 25%,100% 50%,93.3% 75%,75% 86.6%,50% 100%,25% 86.6%,6.7% 75%,0% 50%,6.7% 25%,25% 13.4%)' },
    arrow_cross: { radius: '0', clip: 'polygon(50% 0%,65% 20%,55% 20%,55% 45%,80% 45%,80% 35%,100% 50%,80% 65%,80% 55%,55% 55%,55% 80%,65% 80%,50% 100%,35% 80%,45% 80%,45% 55%,20% 55%,20% 65%,0% 50%,20% 35%,20% 45%,45% 45%,45% 20%,35% 20%)' },
    minus_sign: { radius: '0', clip: 'polygon(10% 42%,90% 42%,90% 58%,10% 58%)' },
    multiply_x: { radius: '0', clip: 'polygon(8% 0%,50% 34%,92% 0%,100% 8%,64% 50%,100% 92%,92% 100%,50% 66%,8% 100%,0% 92%,36% 50%,0% 8%)' },
    document: { radius: '0', clip: 'polygon(0% 0%,100% 0%,100% 78%,83% 92%,66% 78%,50% 92%,33% 78%,17% 92%,0% 78%)' },
    manual_input: { radius: '0', clip: 'polygon(0% 20%,100% 0%,100% 100%,0% 100%)' },
    off_page_connector: { radius: '0', clip: 'polygon(0% 0%,100% 0%,100% 70%,50% 100%,0% 70%)' },
    bowtie: { radius: '0', clip: 'polygon(0% 0%,100% 0%,0% 100%,100% 100%)' },
    delay: { radius: '0', clip: 'polygon(0% 0%,70% 0%,85% 3%,96% 12%,100% 25%,100% 75%,96% 88%,85% 97%,70% 100%,0% 100%)' },
    punched_tape: { radius: '0', clip: 'polygon(0% 15%,16.5% 0%,33% 15%,50% 0%,66% 15%,83% 0%,100% 15%,100% 85%,83% 100%,66% 85%,50% 100%,33% 85%,16.5% 100%,0% 85%)' },
    stadium: { radius: '999px', clip: 'none' },
    elbow: { radius: '0', clip: 'polygon(0% 100%, 1.23% 84.36%, 4.89% 69.1%, 10.9% 54.6%, 19.1% 41.22%, 29.29% 29.29%, 41.22% 19.1%, 54.6% 10.9%, 69.1% 4.89%, 84.36% 1.23%, 100% 0%, 100% 55%, 92.96% 55.55%, 86.09% 57.2%, 79.57% 59.9%, 73.55% 63.59%, 68.18% 68.18%, 63.59% 73.55%, 59.9% 79.57%, 57.2% 86.09%, 55.55% 92.96%, 55% 100%)' },
    text_only: { radius: '0', clip: 'none' },
    convoyeur_rouleaux: { radius: '0', clip: 'polygon(4.33% 0%,12.33% 0%,12.33% 65%,21% 65%,21% 0%,29% 0%,29% 65%,37.67% 65%,37.67% 0%,45.67% 0%,45.67% 65%,54.33% 65%,54.33% 0%,62.33% 0%,62.33% 65%,71% 65%,71% 0%,79% 0%,79% 65%,87.67% 65%,87.67% 0%,95.67% 0%,95.67% 65%,100% 65%,100% 100%,0% 100%,0% 65%,4.33% 65%)' },
    vis_sans_fin: { radius: '0', clip: 'polygon(0% 30%,8.33% 10%,16.67% 30%,25% 10%,33.33% 30%,41.67% 10%,50% 30%,58.33% 10%,66.67% 30%,75% 10%,83.33% 30%,91.67% 10%,100% 30%,100% 70%,91.67% 90%,83.33% 70%,75% 90%,66.67% 70%,58.33% 90%,50% 70%,41.67% 90%,33.33% 70%,25% 90%,16.67% 70%,8.33% 90%,0% 70%)' },
    tapis_chevron: { radius: '0', clip: 'polygon(0% 45%,12.5% 15%,25% 45%,37.5% 15%,50% 45%,62.5% 15%,75% 45%,87.5% 15%,100% 45%,100% 100%,0% 100%)' },
    robot_bras: { radius: '0', clip: 'polygon(20% 100%,20% 88%,42% 88%,42% 35%,75% 35%,75% 22%,85% 15%,78% 28%,85% 40%,75% 45%,58% 45%,58% 88%,80% 88%,80% 100%)' },
};
function categorieSchemaShapeCss(shape) { return CS_SHAPE_CSS[shape] || CS_SHAPE_CSS.rect; }
function categorieSchemaTextColor(hex) {
    hex = (hex || '#ffffff').replace('#', '');
    if (hex.length === 3) hex = hex.split('').map(c => c + c).join('');
    const r = parseInt(hex.substr(0, 2), 16), g = parseInt(hex.substr(2, 2), 16), b = parseInt(hex.substr(4, 2), 16);
    return (0.299 * r + 0.587 * g + 0.114 * b) < 150 ? '#ffffff' : '#2c3e50';
}

function updateCategorieSchemaFieldsVisibility() {
    document.getElementById('categorieSchemaFill2Wrap').style.display = document.getElementById('categorieSchemaFormGradient').checked ? '' : 'none';
    document.getElementById('categorieSchemaBorderWrap').style.display = document.getElementById('categorieSchemaFormBorderNone').checked ? 'none' : '';
    document.getElementById('categorieSchemaTextColorWrap').style.display = document.getElementById('categorieSchemaFormTextColorAuto').checked ? 'none' : '';
}
function categorieSchemaEffectStyle(effect, fill, border) {
    switch (effect) {
        case 'shadow': return { filter: 'drop-shadow(3px 4px 6px rgba(0,0,0,.4))', boxShadow: '' };
        case 'bevel': return { filter: '', boxShadow: 'inset -3px -3px 6px rgba(0,0,0,.3), inset 3px 3px 6px rgba(255,255,255,.6)' };
        case 'inset': return { filter: '', boxShadow: 'inset 0 0 10px rgba(0,0,0,.5), inset 0 0 3px rgba(0,0,0,.55)' };
        case 'glow': {
            const glow = border || fill || '#3b82f6';
            return { filter: 'drop-shadow(0 0 5px ' + glow + ') drop-shadow(0 0 10px ' + glow + ')', boxShadow: '' };
        }
        default: return { filter: '', boxShadow: '' };
    }
}
// Aperçu en temps réel — reproduit EXACTEMENT le rendu réel d'une forme du schéma (même formules
// que suApplyPropsToSelected en admin_machines.php) : découpe de la forme, dégradé orienté, effet,
// rotation de la forme, et le texte avec sa propre rotation/taille/couleur.
function suShadeColor(hex, percent) {
    hex = (hex || '#3498db').replace('#', '');
    if (hex.length === 3) hex = hex.split('').map(c => c + c).join('');
    let r = parseInt(hex.substr(0, 2), 16), g = parseInt(hex.substr(2, 2), 16), b = parseInt(hex.substr(4, 2), 16);
    const adj = (c) => Math.max(0, Math.min(255, Math.round(c + (percent / 100) * (percent > 0 ? (255 - c) : c))));
    r = adj(r); g = adj(g); b = adj(b);
    return '#' + [r, g, b].map(v => v.toString(16).padStart(2, '0')).join('');
}
const SU_SVG_SHAPES = new Set(['convoyeur_rouleaux', 'convoyeur_rouleaux_arc', 'vis_sans_fin', 'tapis_chevron', 'robot_bras']);
function suRollerCount(posW) {
    const usable = (posW || 10) * 0.88;
    const pitch = 0.62;
    const gaps = Math.max(2, Math.round(usable / pitch));
    return gaps + 1;
}
function suRollerWidth(posW) {
    const targetPercentOfCanvas = 0.303;
    return Math.max(0.3, Math.min(20, (targetPercentOfCanvas / (posW || 10)) * 100));
}
// Miroir exact de suIndustrialSvg() (admin_machines.php) — doit rester synchronisé.
function suIndustrialSvg(shape, fill, id, posW, posH, border) {
    const light = suShadeColor(fill, 40), dark = suShadeColor(fill, -35);
    const hasChassis = !!(border && border !== 'none');
    const steelLight = hasChassis ? suShadeColor(border, 35) : '#c7ccd1';
    const steelMid = hasChassis ? border : '#9aa1a8';
    const steelDark = hasChassis ? suShadeColor(border, -35) : '#5b6167';
    const g1 = 'su-g1-' + id, g2 = 'su-g2-' + id, g3 = 'su-g3-' + id;
    const svgOpen = '<svg viewBox="0 0 100 100" preserveAspectRatio="' + (shape === 'convoyeur_rouleaux_arc' ? 'xMidYMid meet' : 'none') + '" style="width:100%;height:100%;display:block;">';
    if (shape === 'convoyeur_rouleaux') {
        let rollers = '';
        const n = suRollerCount(posW);
        const rw = suRollerWidth(posW);
        const strokeW = Math.max(0.03, rw * 0.0625);
        for (let i = 0; i < n; i++) {
            const cx = 6 + i * (88 / (n - 1));
            rollers += '<rect x="' + (cx - rw / 2) + '" y="20" width="' + rw + '" height="60" rx="' + (rw / 2) + '" fill="url(#' + g2 + ')" stroke="' + dark + '" stroke-width="' + strokeW + '"/>';
        }
        const railStart = 6 - rw / 2, railEnd = 94 + rw / 2, railW = railEnd - railStart;
        return svgOpen + '<defs>'
            + '<linearGradient id="' + g1 + '" x1="0" y1="0" x2="0" y2="1"><stop offset="0%" stop-color="' + steelLight + '"/><stop offset="45%" stop-color="' + steelMid + '"/><stop offset="100%" stop-color="' + steelDark + '"/></linearGradient>'
            + '<linearGradient id="' + g2 + '" x1="0" y1="0" x2="1" y2="0"><stop offset="0%" stop-color="' + dark + '"/><stop offset="42%" stop-color="' + light + '"/><stop offset="58%" stop-color="' + light + '"/><stop offset="100%" stop-color="' + dark + '"/></linearGradient>'
            + '</defs>'
            + '<rect x="' + railStart + '" y="12" width="' + railW + '" height="9" rx="2" fill="url(#' + g1 + ')"/>'
            + '<rect x="' + railStart + '" y="79" width="' + railW + '" height="9" rx="2" fill="url(#' + g1 + ')"/>'
            + rollers + '</svg>';
    }
    if (shape === 'convoyeur_rouleaux_arc') {
        let rollers = '';
        const sizeAvg = ((posW || 10) + (posH || 10)) / 2;
        const n = suRollerCount(sizeAvg);
        const rw = suRollerWidth(sizeAvg);
        const rOut = 95, rIn = 65, rMid = (rOut + rIn) / 2, rollerLen = rOut - rIn;
        const strokeW = Math.max(0.03, rw * 0.0625);
        for (let i = 0; i < n; i++) {
            const theta = i * (90 / (n - 1));
            const rad = theta * Math.PI / 180;
            const cx = Math.sin(rad) * rMid;
            const cy = 100 - Math.cos(rad) * rMid;
            rollers += '<rect x="' + (cx - rw / 2) + '" y="' + (cy - rollerLen / 2) + '" width="' + rw + '" height="' + rollerLen + '" rx="' + (rw / 2) + '" fill="url(#' + g2 + ')" stroke="' + dark + '" stroke-width="' + strokeW + '" transform="rotate(' + theta + ' ' + cx + ' ' + cy + ')"/>';
        }
        return svgOpen + '<defs>'
            + '<linearGradient id="' + g1 + '" x1="0" y1="0" x2="1" y2="1"><stop offset="0%" stop-color="' + steelLight + '"/><stop offset="50%" stop-color="' + steelMid + '"/><stop offset="100%" stop-color="' + steelDark + '"/></linearGradient>'
            + '<linearGradient id="' + g2 + '" x1="0" y1="0" x2="1" y2="0"><stop offset="0%" stop-color="' + dark + '"/><stop offset="42%" stop-color="' + light + '"/><stop offset="58%" stop-color="' + light + '"/><stop offset="100%" stop-color="' + dark + '"/></linearGradient>'
            + '</defs>'
            + '<path d="M 0 5 A 95 95 0 0 1 95 100" fill="none" stroke="url(#' + g1 + ')" stroke-width="9" stroke-linecap="round"/>'
            + '<path d="M 0 35 A 65 65 0 0 1 65 100" fill="none" stroke="url(#' + g1 + ')" stroke-width="9" stroke-linecap="round"/>'
            + rollers + '</svg>';
    }
    if (shape === 'vis_sans_fin') {
        let threads = '';
        const n = 10;
        for (let i = 0; i < n; i++) {
            const cx = 6 + i * (88 / (n - 1));
            threads += '<path d="M ' + (cx - 7) + ' 10 L ' + (cx + 7) + ' 90" stroke="' + dark + '" stroke-width="6.5" stroke-linecap="round"/>';
            threads += '<path d="M ' + (cx - 5.3) + ' 10 L ' + (cx + 8.7) + ' 90" stroke="' + light + '" stroke-width="2.3" stroke-linecap="round"/>';
        }
        return svgOpen + '<defs>'
            + '<linearGradient id="' + g1 + '" x1="0" y1="0" x2="0" y2="1"><stop offset="0%" stop-color="' + steelLight + '"/><stop offset="50%" stop-color="' + steelMid + '"/><stop offset="100%" stop-color="' + steelDark + '"/></linearGradient>'
            + '</defs>'
            + '<rect x="0" y="34" width="100" height="32" rx="16" fill="url(#' + g1 + ')"/>'
            + threads + '</svg>';
    }
    if (shape === 'tapis_chevron') {
        let chevrons = '';
        const n = 5;
        for (let i = 0; i < n; i++) {
            const cx = 20 + i * 15;
            chevrons += '<path d="M ' + (cx - 6) + ' 42 L ' + cx + ' 30 L ' + (cx + 6) + ' 42" fill="none" stroke="' + dark + '" stroke-width="2.2" stroke-linecap="round" opacity="0.55"/>';
        }
        return svgOpen + '<defs>'
            + '<linearGradient id="' + g1 + '" x1="0" y1="0" x2="0" y2="1"><stop offset="0%" stop-color="' + light + '"/><stop offset="55%" stop-color="' + fill + '"/><stop offset="100%" stop-color="' + dark + '"/></linearGradient>'
            + '<radialGradient id="' + g3 + '" cx="35%" cy="35%" r="70%"><stop offset="0%" stop-color="' + steelLight + '"/><stop offset="100%" stop-color="' + steelDark + '"/></radialGradient>'
            + '</defs>'
            + '<circle cx="12" cy="50" r="16" fill="url(#' + g3 + ')"/>'
            + '<circle cx="88" cy="50" r="16" fill="url(#' + g3 + ')"/>'
            + '<rect x="12" y="30" width="76" height="40" fill="url(#' + g1 + ')"/>'
            + chevrons + '</svg>';
    }
    if (shape === 'robot_bras') {
        return svgOpen + '<defs>'
            + '<linearGradient id="' + g1 + '" x1="0" y1="0" x2="0" y2="1"><stop offset="0%" stop-color="' + steelLight + '"/><stop offset="50%" stop-color="' + steelMid + '"/><stop offset="100%" stop-color="' + steelDark + '"/></linearGradient>'
            + '<linearGradient id="' + g2 + '" x1="0" y1="0" x2="1" y2="1"><stop offset="0%" stop-color="' + light + '"/><stop offset="50%" stop-color="' + fill + '"/><stop offset="100%" stop-color="' + dark + '"/></linearGradient>'
            + '<radialGradient id="' + g3 + '" cx="35%" cy="35%" r="70%"><stop offset="0%" stop-color="' + light + '"/><stop offset="100%" stop-color="' + dark + '"/></radialGradient>'
            + '</defs>'
            + '<rect x="25" y="82" width="50" height="14" rx="4" fill="url(#' + g1 + ')"/>'
            + '<rect x="42" y="40" width="16" height="46" rx="8" fill="url(#' + g1 + ')"/>'
            + '<circle cx="50" cy="40" r="11" fill="url(#' + g3 + ')"/>'
            + '<rect x="46" y="18" width="42" height="13" rx="6.5" fill="url(#' + g2 + ')" transform="rotate(-18 50 24)"/>'
            + '<circle cx="82" cy="16" r="7" fill="url(#' + g3 + ')"/>'
            + '</svg>';
    }
    return '';
}
function updateCategorieSchemaPreview() {
    const wrap = document.getElementById('categorieSchemaPreviewWrap');
    const preview = document.getElementById('categorieSchemaPreview');
    const label = document.getElementById('categorieSchemaPreviewLabel');
    const fill = document.getElementById('categorieSchemaFormFill').value;
    const gradient = document.getElementById('categorieSchemaFormGradient').checked;
    const fill2 = document.getElementById('categorieSchemaFormFill2').value;
    const gradientAngle = document.getElementById('categorieSchemaFormGradientAngle').value || '135';
    const noBorder = document.getElementById('categorieSchemaFormBorderNone').checked;
    const border = document.getElementById('categorieSchemaFormBorder').value;
    const effect = document.getElementById('categorieSchemaFormEffect').value;
    const shape = document.getElementById('categorieSchemaFormShape').value;
    const posW = parseFloat(document.getElementById('categorieSchemaFormPosW').value);
    const posH = parseFloat(document.getElementById('categorieSchemaFormPosH').value);
    const rotation = parseFloat(document.getElementById('categorieSchemaFormRotation').value || 0);
    // Rotation du texte détachée de celle de la forme (David : la forme et son texte doivent
    // pouvoir être orientés indépendamment) — plus d'auto-alignement, le champ ci-dessous est la
    // seule source de vérité.
    const textRotationEl = document.getElementById('categorieSchemaFormTextRotation');
    const textRotation = parseFloat(textRotationEl.value || 0);
    const fontSizeRaw = document.getElementById('categorieSchemaFormFontSize').value;
    const textColorAuto = document.getElementById('categorieSchemaFormTextColorAuto').checked;
    const textColor = textColorAuto ? '' : document.getElementById('categorieSchemaFormTextColor').value;
    const texte = document.getElementById('categorieSchemaFormLabel').value || document.getElementById('categorieSchemaFormNom').value || I18N_PARAM.apercu_texte_defaut;

    // Taille de la vignette : respecte le ratio largeur/hauteur choisi, contenue dans le cadre.
    const maxW = 220, maxH = 160;
    let w = maxW, h = maxH * 0.6;
    if (posW > 0 && posH > 0) {
        const ratio = posW / posH;
        w = maxW; h = w / ratio;
        if (h > maxH) { h = maxH; w = h * ratio; }
    }
    preview.style.width = Math.max(30, w) + 'px';
    preview.style.height = Math.max(24, h) + 'px';

    const isTextOnly = shape === 'text_only';
    const isSvgShape = SU_SVG_SHAPES.has(shape);
    let svgLayer = preview.querySelector('.su-zone-svg');
    if (isSvgShape) {
        preview.style.background = 'transparent';
        preview.style.borderColor = 'transparent';
        preview.style.borderWidth = '0';
        preview.style.borderRadius = '0';
        preview.style.clipPath = 'none';
        if (!svgLayer) {
            svgLayer = document.createElement('div');
            svgLayer.className = 'su-zone-svg';
            svgLayer.style.cssText = 'position:absolute; inset:0; pointer-events:none;';
            preview.insertBefore(svgLayer, preview.firstChild);
        }
        svgLayer.innerHTML = suIndustrialSvg(shape, fill, 'preview', posW, posH, noBorder ? null : border);
    } else {
        if (svgLayer) svgLayer.remove();
        preview.style.background = isTextOnly ? 'transparent' : ((gradient && fill2) ? ('linear-gradient(' + gradientAngle + 'deg, ' + fill + ', ' + fill2 + ')') : fill);
        preview.style.borderColor = (!isTextOnly && !noBorder) ? border : 'transparent';
        preview.style.borderWidth = (!isTextOnly && !noBorder) ? '3px' : '0';
        preview.style.borderStyle = 'solid';
        const css = categorieSchemaShapeCss(shape);
        preview.style.borderRadius = css.radius;
        preview.style.clipPath = css.clip;
    }
    const st = (isTextOnly || isSvgShape) ? { filter: '', boxShadow: '' } : categorieSchemaEffectStyle(effect, fill, noBorder ? null : border);
    preview.style.filter = st.filter;
    preview.style.boxShadow = st.boxShadow;
    preview.style.transform = rotation ? ('rotate(' + rotation + 'deg)') : '';

    label.textContent = texte;
    label.style.color = textColor || categorieSchemaTextColor(isTextOnly ? '#ffffff' : fill);
    label.style.fontSize = fontSizeRaw !== '' ? (fontSizeRaw + 'rem') : '';
    const textRot = textRotation - rotation;
    label.style.transform = textRot ? ('rotate(' + textRot + 'deg)') : '';
}
document.querySelectorAll('.su-dir-tile').forEach(function (tile) {
    tile.addEventListener('click', function () {
        document.getElementById('categorieSchemaFormGradientAngle').value = tile.dataset.angle;
        document.querySelectorAll('.su-dir-tile').forEach(function (t) { t.classList.toggle('active', t === tile); });
        updateCategorieSchemaPreview();
    });
});
document.querySelectorAll('#categorieSchemaEffectRow .su-effect-tile').forEach(function (tile) {
    tile.addEventListener('click', function () {
        document.getElementById('categorieSchemaFormEffect').value = tile.dataset.effect;
        document.querySelectorAll('#categorieSchemaEffectRow .su-effect-tile').forEach(function (t) { t.classList.toggle('active', t === tile); });
        updateCategorieSchemaPreview();
    });
});
function categorieSchemaSetEffect(effect) {
    effect = effect || 'none';
    document.getElementById('categorieSchemaFormEffect').value = effect;
    document.querySelectorAll('#categorieSchemaEffectRow .su-effect-tile').forEach(function (t) { t.classList.toggle('active', t.dataset.effect === effect); });
}
[
    'categorieSchemaFormFill', 'categorieSchemaFormFill2', 'categorieSchemaFormGradient', 'categorieSchemaFormBorder', 'categorieSchemaFormBorderNone',
    'categorieSchemaFormEffect', 'categorieSchemaFormNom', 'categorieSchemaFormTextColorAuto', 'categorieSchemaFormLabel', 'categorieSchemaFormShape',
    'categorieSchemaFormPosW', 'categorieSchemaFormPosH', 'categorieSchemaFormRotation', 'categorieSchemaFormTextRotation', 'categorieSchemaFormFontSize',
    'categorieSchemaFormTextColor',
].forEach(function (id) {
    const el = document.getElementById(id);
    el.addEventListener('input', function () { updateCategorieSchemaFieldsVisibility(); updateCategorieSchemaPreview(); });
    el.addEventListener('change', function () { updateCategorieSchemaFieldsVisibility(); updateCategorieSchemaPreview(); });
});
function categorieSchemaSetGradientAngle(angle) {
    angle = String(angle || 135);
    document.getElementById('categorieSchemaFormGradientAngle').value = angle;
    document.querySelectorAll('.su-dir-tile').forEach(function (t) { t.classList.toggle('active', t.dataset.angle === angle); });
}
function openAddCategorieSchema() {
    document.getElementById('categorieSchemaModalTitle').textContent = I18N_PARAM.modal_ajouter_categorie_schema;
    document.getElementById('categorieSchemaFormAction').value = 'add_categorie_schema';
    document.getElementById('categorieSchemaFormId').value = '';
    document.getElementById('categorieSchemaFormNom').value = '';
    document.getElementById('categorieSchemaFormLabel').value = '';
    document.getElementById('categorieSchemaFormFill').value = '#3498db';
    document.getElementById('categorieSchemaFormFill2').value = '#3498db';
    document.getElementById('categorieSchemaFormGradient').checked = false;
    categorieSchemaSetGradientAngle(135);
    document.getElementById('categorieSchemaFormBorder').value = '#95a5a6';
    document.getElementById('categorieSchemaFormBorderNone').checked = false;
    categorieSchemaSetEffect('none');
    document.getElementById('categorieSchemaFormShape').value = 'rect';
    document.getElementById('categorieSchemaFormPosW').value = '';
    document.getElementById('categorieSchemaFormPosH').value = '';
    document.getElementById('categorieSchemaFormRotation').value = 0;
    document.getElementById('categorieSchemaFormTextRotation').value = 0;
    document.getElementById('categorieSchemaFormFontSize').value = '';
    document.getElementById('categorieSchemaFormTextColor').value = '#2c3e50';
    document.getElementById('categorieSchemaFormTextColorAuto').checked = true;
    document.getElementById('categorieSchemaFormClickable').checked = true;
    updateCategorieSchemaFieldsVisibility();
    updateCategorieSchemaPreview();
    document.getElementById('modalCategorieSchema').classList.add('show');
}
function openEditCategorieSchema(data) {
    document.getElementById('categorieSchemaModalTitle').textContent = I18N_PARAM.modal_modifier_categorie_schema;
    document.getElementById('categorieSchemaFormAction').value = 'edit_categorie_schema';
    document.getElementById('categorieSchemaFormId').value = data.id;
    document.getElementById('categorieSchemaFormNom').value = data.nom;
    document.getElementById('categorieSchemaFormLabel').value = data.label || '';
    document.getElementById('categorieSchemaFormFill').value = data.fill || '#3498db';
    document.getElementById('categorieSchemaFormFill2').value = data.fill2 || data.fill || '#3498db';
    document.getElementById('categorieSchemaFormGradient').checked = data.gradient === '1';
    categorieSchemaSetGradientAngle(data.gradientAngle);
    document.getElementById('categorieSchemaFormBorder').value = data.border || '#95a5a6';
    document.getElementById('categorieSchemaFormBorderNone').checked = !data.border;
    categorieSchemaSetEffect(data.effect || 'none');
    document.getElementById('categorieSchemaFormShape').value = data.shape || 'rect';
    document.getElementById('categorieSchemaFormPosW').value = data.posW || '';
    document.getElementById('categorieSchemaFormPosH').value = data.posH || '';
    document.getElementById('categorieSchemaFormRotation').value = data.rotation || 0;
    document.getElementById('categorieSchemaFormTextRotation').value = data.textRotation || 0;
    document.getElementById('categorieSchemaFormFontSize').value = data.fontSize || '';
    document.getElementById('categorieSchemaFormTextColor').value = data.textColor || '#2c3e50';
    document.getElementById('categorieSchemaFormTextColorAuto').checked = !data.textColor;
    document.getElementById('categorieSchemaFormClickable').checked = data.clickable === '1';
    updateCategorieSchemaFieldsVisibility();
    updateCategorieSchemaPreview();
    document.getElementById('modalCategorieSchema').classList.add('show');
}
document.querySelectorAll('.btn-edit-categorie-schema').forEach(function (btn) {
    btn.addEventListener('click', function () {
        openEditCategorieSchema({
            id: btn.dataset.id, nom: btn.dataset.nom, label: btn.dataset.label, fill: btn.dataset.fill, fill2: btn.dataset.fill2,
            gradient: btn.dataset.gradient, gradientAngle: btn.dataset.gradientAngle, border: btn.dataset.border, effect: btn.dataset.effect,
            shape: btn.dataset.shape, posW: btn.dataset.posW, posH: btn.dataset.posH,
            rotation: btn.dataset.rotation, textRotation: btn.dataset.textRotation, fontSize: btn.dataset.fontSize,
            textColor: btn.dataset.textColor, clickable: btn.dataset.clickable,
        });
    });
});

function openConfirmSuppr(action, id, tabActif, texte) {
    document.getElementById('confirmSupprText').textContent = texte;
    document.getElementById('confirmSupprAction').value = action;
    document.getElementById('confirmSupprId').value = id;
    document.getElementById('confirmSupprTab').value = tabActif;
    document.getElementById('modalConfirmSuppr').classList.add('show');
}

function openAddService() {
    document.getElementById('serviceModalTitle').textContent = I18N_PARAM.modal_ajouter_service;
    document.getElementById('serviceFormAction').value = 'add_service';
    document.getElementById('serviceFormId').value = '';
    document.getElementById('serviceFormLabel').value = '';
    document.getElementById('modalService').classList.add('show');
}
function openEditService(id, label) {
    document.getElementById('serviceModalTitle').textContent = I18N_PARAM.modal_modifier_service;
    document.getElementById('serviceFormAction').value = 'edit_service';
    document.getElementById('serviceFormId').value = id;
    document.getElementById('serviceFormLabel').value = label;
    document.getElementById('modalService').classList.add('show');
}
document.querySelectorAll('.btn-edit-service').forEach(function (btn) {
    btn.addEventListener('click', function () { openEditService(btn.dataset.id, btn.dataset.label); });
});

function openAddCategorie() {
    document.getElementById('categorieModalTitle').textContent = I18N_PARAM.modal_ajouter_categorie;
    document.getElementById('categorieFormAction').value = 'add_categorie';
    document.getElementById('categorieFormId').value = '';
    document.getElementById('categorieFormLabel').value = '';
    document.getElementById('categorieFormDescription').value = '';
    setCategorieIconValue('fa-gear');
    setCategorieColorValue('#3498db');
    document.getElementById('modalCategorie').classList.add('show');
}
function openEditCategorie(id, label, description, icone, couleur) {
    document.getElementById('categorieModalTitle').textContent = I18N_PARAM.modal_modifier_categorie;
    document.getElementById('categorieFormAction').value = 'edit_categorie';
    document.getElementById('categorieFormId').value = id;
    document.getElementById('categorieFormLabel').value = label;
    document.getElementById('categorieFormDescription').value = description;
    setCategorieIconValue(icone);
    setCategorieColorValue(couleur);
    document.getElementById('modalCategorie').classList.add('show');
}
function setCategorieIconValue(val) {
    document.getElementById('categorieFormIcone').value = val;
    document.querySelectorAll('#categorieIconGrid .icon-chip').forEach(function (c) {
        c.classList.toggle('active', c.dataset.value === val);
    });
}
function setCategorieColorValue(val) {
    document.getElementById('categorieFormCouleur').value = val;
    let estPreset = false;
    document.querySelectorAll('#categorieColorGrid .color-chip').forEach(function (c) {
        const actif = c.dataset.value === val;
        c.classList.toggle('active', actif);
        if (actif) estPreset = true;
    });
    document.getElementById('categorieColorCustom').value = /^#[0-9a-fA-F]{6}$/.test(val) ? val : '#3498db';
    document.getElementById('categorieColorCustom').closest('.color-chip-custom').classList.toggle('active', !estPreset);
}
document.querySelectorAll('#categorieIconGrid .icon-chip').forEach(function (chip) {
    chip.addEventListener('click', function () { setCategorieIconValue(chip.dataset.value); });
});
document.querySelectorAll('#categorieColorGrid .color-chip').forEach(function (chip) {
    chip.addEventListener('click', function () { setCategorieColorValue(chip.dataset.value); });
});
document.getElementById('categorieColorCustom').addEventListener('input', function () { setCategorieColorValue(this.value); });
document.querySelectorAll('.btn-edit-categorie').forEach(function (btn) {
    btn.addEventListener('click', function () {
        openEditCategorie(btn.dataset.id, btn.dataset.label, btn.dataset.description, btn.dataset.icone, btn.dataset.couleur);
    });
});

function openEditLibelle(bucket, label, couleur) {
    document.getElementById('libelleFormBucket').value = bucket;
    document.getElementById('libelleFormLabel').value = label;
    setLibelleColorValue(couleur);
    document.getElementById('modalLibelle').classList.add('show');
}
function setLibelleColorValue(val) {
    document.getElementById('libelleFormCouleur').value = val;
    let estPreset = false;
    document.querySelectorAll('#libelleColorGrid .color-chip').forEach(function (c) {
        const actif = c.dataset.value === val;
        c.classList.toggle('active', actif);
        if (actif) estPreset = true;
    });
    document.getElementById('libelleColorCustom').value = /^#[0-9a-fA-F]{6}$/.test(val) ? val : '#3498db';
    document.getElementById('libelleColorCustom').closest('.color-chip-custom').classList.toggle('active', !estPreset);
}
document.querySelectorAll('#libelleColorGrid .color-chip').forEach(function (chip) {
    chip.addEventListener('click', function () { setLibelleColorValue(chip.dataset.value); });
});
document.getElementById('libelleColorCustom').addEventListener('input', function () { setLibelleColorValue(this.value); });
document.querySelectorAll('.btn-edit-libelle').forEach(function (btn) {
    btn.addEventListener('click', function () { openEditLibelle(btn.dataset.bucket, btn.dataset.label, btn.dataset.couleur); });
});

function openEditPlanningCouleur(table, cle, label, couleur) {
    document.getElementById('planningFormTable').value = table;
    document.getElementById('planningFormCle').value = cle;
    document.getElementById('planningFormLabel').value = label;
    setPlanningColorValue(couleur);
    document.getElementById('modalPlanningCouleur').classList.add('show');
}
function setPlanningColorValue(val) {
    document.getElementById('planningFormCouleur').value = val;
    let estPreset = false;
    document.querySelectorAll('#planningColorGrid .color-chip').forEach(function (c) {
        const actif = c.dataset.value === val;
        c.classList.toggle('active', actif);
        if (actif) estPreset = true;
    });
    document.getElementById('planningColorCustom').value = /^#[0-9a-fA-F]{6}$/.test(val) ? val : '#3498db';
    document.getElementById('planningColorCustom').closest('.color-chip-custom').classList.toggle('active', !estPreset);
}
document.querySelectorAll('#planningColorGrid .color-chip').forEach(function (chip) {
    chip.addEventListener('click', function () { setPlanningColorValue(chip.dataset.value); });
});
document.getElementById('planningColorCustom').addEventListener('input', function () { setPlanningColorValue(this.value); });
document.querySelectorAll('.btn-edit-planning').forEach(function (btn) {
    btn.addEventListener('click', function () {
        openEditPlanningCouleur(btn.dataset.table, btn.dataset.cle, btn.dataset.label, btn.dataset.couleur);
    });
});

const btnOuvrirResetPlanning = document.getElementById('btnOuvrirResetPlanning');
if (btnOuvrirResetPlanning) {
    btnOuvrirResetPlanning.addEventListener('click', function () {
        document.getElementById('modalResetPlanning').classList.add('show');
    });
}

const btnOuvrirResetHeures = document.getElementById('btnOuvrirResetHeures');
if (btnOuvrirResetHeures) {
    btnOuvrirResetHeures.addEventListener('click', function () {
        document.getElementById('modalResetHeures').classList.add('show');
    });
}

document.querySelectorAll('.btn-edit-objectif').forEach(function (btn) {
    btn.addEventListener('click', function () {
        document.getElementById('objectifModalTitle').textContent = I18N_PARAM.modal_objectif_titre_username.replace('{username}', btn.dataset.username);
        document.getElementById('objectifFormUsername').value = btn.dataset.username;
        document.getElementById('objectifFormValeur').value = btn.dataset.objectif;
        document.getElementById('modalObjectif').classList.add('show');
    });
});

function openEditTuileCouleur(href, titre, description, icone, couleur) {
    document.getElementById('tuileModalTitle').textContent = I18N_PARAM.modal_tuile_titre_nom.replace('{titre}', titre);
    document.getElementById('tuileFormHref').value = href;
    document.getElementById('tuileFormTitre').value = titre;
    document.getElementById('tuileFormDescription').value = description;
    setTuileIconValue(icone);
    setTuileColorValue(couleur);
    document.getElementById('modalTuileCouleur').classList.add('show');
}
function setTuileIconValue(val) {
    document.getElementById('tuileFormIcone').value = val;
    document.querySelectorAll('#tuileIconGrid .icon-chip').forEach(function (c) {
        c.classList.toggle('active', c.dataset.value === val);
    });
}
document.querySelectorAll('#tuileIconGrid .icon-chip').forEach(function (chip) {
    chip.addEventListener('click', function () { setTuileIconValue(chip.dataset.value); });
});
function setTuileColorValue(val) {
    document.getElementById('tuileFormCouleur').value = val;
    let estPreset = false;
    document.querySelectorAll('#tuileColorGrid .color-chip').forEach(function (c) {
        const actif = c.dataset.value === val;
        c.classList.toggle('active', actif);
        if (actif) estPreset = true;
    });
    document.getElementById('tuileColorCustom').value = /^#[0-9a-fA-F]{6}$/.test(val) ? val : '#3498db';
    document.getElementById('tuileColorCustom').closest('.color-chip-custom').classList.toggle('active', !estPreset);
}
document.querySelectorAll('#tuileColorGrid .color-chip').forEach(function (chip) {
    chip.addEventListener('click', function () { setTuileColorValue(chip.dataset.value); });
});
document.getElementById('tuileColorCustom').addEventListener('input', function () { setTuileColorValue(this.value); });
document.querySelectorAll('.btn-edit-tuile').forEach(function (btn) {
    btn.addEventListener('click', function () {
        openEditTuileCouleur(btn.dataset.href, btn.dataset.titre, btn.dataset.description, btn.dataset.icone, btn.dataset.couleur);
    });
});

function openEditDossierPerso(id, titre, description, icone, couleur) {
    document.getElementById('dossierPersoFormId').value = id;
    document.getElementById('dossierPersoFormTitre').value = titre;
    document.getElementById('dossierPersoFormDescription').value = description;
    setDossierPersoIconValue(icone);
    setDossierPersoColorValue(couleur);
    document.getElementById('modalDossierPerso').classList.add('show');
}
function setDossierPersoIconValue(val) {
    document.getElementById('dossierPersoFormIcone').value = val;
    document.querySelectorAll('#dossierPersoIconGrid .icon-chip').forEach(function (c) {
        c.classList.toggle('active', c.dataset.value === val);
    });
}
function setDossierPersoColorValue(val) {
    document.getElementById('dossierPersoFormCouleur').value = val;
    let estPreset = false;
    document.querySelectorAll('#dossierPersoColorGrid .color-chip').forEach(function (c) {
        const actif = c.dataset.value === val;
        c.classList.toggle('active', actif);
        if (actif) estPreset = true;
    });
    document.getElementById('dossierPersoColorCustom').value = /^#[0-9a-fA-F]{6}$/.test(val) ? val : '#34495e';
    document.getElementById('dossierPersoColorCustom').closest('.color-chip-custom').classList.toggle('active', !estPreset);
}
document.querySelectorAll('#dossierPersoIconGrid .icon-chip').forEach(function (chip) {
    chip.addEventListener('click', function () { setDossierPersoIconValue(chip.dataset.value); });
});
document.querySelectorAll('#dossierPersoColorGrid .color-chip').forEach(function (chip) {
    chip.addEventListener('click', function () { setDossierPersoColorValue(chip.dataset.value); });
});
document.getElementById('dossierPersoColorCustom').addEventListener('input', function () { setDossierPersoColorValue(this.value); });
document.querySelectorAll('.btn-edit-dossier-perso').forEach(function (btn) {
    btn.addEventListener('click', function () {
        openEditDossierPerso(btn.dataset.id, btn.dataset.titre, btn.dataset.description, btn.dataset.icone, btn.dataset.couleur);
    });
});

window.onclick = function (event) {
    if (event.target.classList && event.target.classList.contains('modal-bg')) {
        event.target.classList.remove('show');
    }
};
</script>
</body>
</html>
