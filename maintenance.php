<?php
require_once __DIR__ . '/session_init.php';

// --- SÉCURITÉ DYNAMIQUE ---
if (!isset($_SESSION['user']) || !isset($_SESSION['role']) || !in_array($_SESSION['role'], ['admin', 'technicien'])) {
    header("Location: login.php");
    exit();
}

// On définit le statut admin pour l'affichage des boutons sensibles
$is_admin = ($_SESSION['role'] === 'admin'); 

// ============================================================================
// 3. MOTEUR DE POINTAGES (CORRIGÉ MARIADB)
// ============================================================================
try {
    require_once 'db.php';
    if(isset($db)) {
        // Syntaxe MariaDB : INT AUTO_INCREMENT et VARCHAR
        $db->exec("CREATE TABLE IF NOT EXISTS pointages (
            id INT AUTO_INCREMENT PRIMARY KEY, 
            task_id VARCHAR(255), 
            tech VARCHAR(100), 
            date DATETIME DEFAULT CURRENT_TIMESTAMP, 
            hours DECIMAL(10,2)
        )");
    }
    
    $db->exec("ALTER TABLE taches ADD COLUMN IF NOT EXISTS verif_vis TINYINT(1) DEFAULT 0");
    $db->exec("ALTER TABLE taches ADD COLUMN IF NOT EXISTS ligne VARCHAR(255) DEFAULT NULL");
    
    // --- CALCUL DU COMPTEUR DE NOTIFICATIONS ---
    $nb_en_attente = 0;
    try {
        $stmt_count = $db->query("SELECT COUNT(*) FROM taches WHERE num_bi IS NULL OR num_bi = ''");
        $nb_en_attente = $stmt_count->fetchColumn();
    } catch (Exception $e) { $nb_en_attente = 0; }

} catch (Throwable $e) {}

// --- HABILLAGE DES STATUTS/PRIORITÉS (configurable depuis Paramètres > Statuts & priorités) ---
// Ne change QUE le libellé/la couleur affichés : la classification (getStatusSlug côté JS)
// et les valeurs réellement stockées en base ne sont pas touchées.
$LIBELLES_WORKFLOW = [
    'attente' => ['label' => t('maint.lib_attente'), 'couleur' => '#95a5a6'],
    'afaire'  => ['label' => t('maint.lib_afaire'),  'couleur' => '#f39c12'],
    'encours' => ['label' => t('maint.lib_encours'), 'couleur' => '#3498db'],
    'termine' => ['label' => t('maint.lib_termine'), 'couleur' => '#27ae60'],
    'refuse'  => ['label' => t('maint.lib_refuse'),  'couleur' => '#e74c3c'],
    'normal'  => ['label' => t('maint.lib_normal'),  'couleur' => '#7f8c8d'],
    'urgent'  => ['label' => t('maint.lib_urgent'),  'couleur' => '#e74c3c'],
];
// Défauts français d'origine (avant l'i18n) : un libellé en base identique à l'un d'eux est traité
// comme non personnalisé (même correctif que suivi.php/index.php) — sinon un libellé jamais retouché
// par David resterait figé en français quelle que soit la langue choisie.
$SNAPSHOTS_FR_LIBELLES = [
    'attente' => 'En attente', 'afaire' => 'À faire', 'encours' => 'En cours', 'termine' => 'Terminée',
    'refuse' => 'Refusée', 'normal' => 'Normal', 'urgent' => 'Urgent',
];
try {
    if (isset($db)) {
        $resLib = $db->query("SELECT bucket, label, couleur FROM libelles_workflow")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($resLib as $l) {
            $labelAGarder = (($l['label'] ?? '') === '' || $l['label'] === ($SNAPSHOTS_FR_LIBELLES[$l['bucket']] ?? null))
                ? ($LIBELLES_WORKFLOW[$l['bucket']]['label'] ?? $l['label'])
                : $l['label'];
            $LIBELLES_WORKFLOW[$l['bucket']] = ['label' => $labelAGarder, 'couleur' => $l['couleur']];
        }
    }
} catch (Exception $e) {}

// Récupération des pointages
if (isset($_GET['get_pointages'])) {
    ob_clean();
    header('Content-Type: application/json');
    try {
        require_once 'db.php';
        // On demande à MariaDB de formater la date en Jour/Mois/Année Heure:Minute
        $sql = "SELECT id, task_id, tech, hours, date FROM pointages ORDER BY id DESC";
        echo json_encode($db->query($sql)->fetchAll(PDO::FETCH_ASSOC));
    } catch (Exception $e) { echo json_encode([]); }
    exit();
}

// --- TODO LIST PAR JOUR DU PLANNING (voir choisirTodoList/ouvrirTodoModal dans planning.php) ---
// Une tâche non cochée se reporte au jour suivant : plutôt qu'un cron séparé (comme cron_preventif.php),
// on fait avancer ici même, à chaque lecture, toute tâche non faite restée sur un jour déjà passé —
// aucune tâche ne reste donc jamais bloquée sur une date révolue, sans dépendre d'une tâche planifiée
// côté serveur (utile aussi en local/XAMPP, où rien de tel n'est configuré).
if (isset($_GET['get_todo'])) {
    ob_clean();
    header('Content-Type: application/json');
    try {
        require_once 'db.php';
        $db->exec("CREATE TABLE IF NOT EXISTS planning_todo (
            id INT AUTO_INCREMENT PRIMARY KEY,
            utilisateur VARCHAR(100) NOT NULL,
            jour DATE NOT NULL,
            texte VARCHAR(255) NOT NULL,
            fait TINYINT(1) NOT NULL DEFAULT 0,
            date_creation DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        )");
        $db->exec("UPDATE planning_todo SET jour = CURDATE() WHERE fait = 0 AND jour < CURDATE()");
        if ($is_admin) {
            $rows = $db->query("SELECT id, utilisateur, jour, texte, fait FROM planning_todo ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
        } else {
            $stmt = $db->prepare("SELECT id, utilisateur, jour, texte, fait FROM planning_todo WHERE utilisateur = ? ORDER BY id");
            $stmt->execute([$_SESSION['user']]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
        echo json_encode($rows);
    } catch (Exception $e) { echo json_encode([]); }
    exit();
}

// --- SAUVEGARDE DU POINTAGE (MÉTHODE ADAPTÉE POUR LE PLANNING) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_pointage') {
    try {
        require_once 'db.php';
        $tech = !empty($_POST['tech']) ? $_POST['tech'] : $_SESSION['user'];
        $hours = floatval(str_replace(',', '.', $_POST['hours']));
        
        // NOUVEAUTÉ : Si on reçoit une date précise (depuis le planning), on l'utilise
        // Sinon, on sécurise avec NOW()
        if (!empty($_POST['date'])) {
            $customDate = $_POST['date'] . ' ' . date('H:i:s');
            $stmt = $db->prepare("INSERT INTO pointages (task_id, tech, date, hours) VALUES (?, ?, ?, ?)");
            $stmt->execute([$_POST['task_id'], $tech, $customDate, $hours]);
        } else {
            $stmt = $db->prepare("INSERT INTO pointages (task_id, tech, date, hours) VALUES (?, ?, NOW(), ?)");
            $stmt->execute([$_POST['task_id'], $tech, $hours]);
        }
        
        echo "OK";
    } catch (Exception $e) {
        http_response_code(500); 
        error_log("maintenance.php: " . $e->getMessage());
        echo t('maint.err_server');
    }
    exit();
}

// --- MODIFICATION D'UN POINTAGE (CORRECTION CHIRURGICALE) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_hours') {
    try {
        require_once 'db.php';
        $id = $_POST['id'];
        $heures = floatval(str_replace(',', '.', $_POST['hours']));
        
        // CORRECTION : On ne touche SURTOUT PAS à la date ici, on ne met à jour QUE les heures !
        $stmt = $db->prepare("UPDATE pointages SET hours = ? WHERE id = ?");
        $stmt->execute([$heures, $id]);
        
        echo "OK";
    } catch (Exception $e) {
        http_response_code(500);
        error_log("maintenance.php: " . $e->getMessage());
        echo t('maint.err_server');
    }
    exit();
}

// --- SUPPRESSION D'UN POINTAGE ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_pointage') {
    try {
        require_once 'db.php';
        $id = $_POST['id'];
        
        $stmt = $db->prepare("DELETE FROM pointages WHERE id = ?");
        $stmt->execute([$id]);
        
        echo "OK";
    } catch (Exception $e) {
        http_response_code(500);
        error_log("maintenance.php: " . $e->getMessage());
        echo t('maint.err_server');
    }
    exit();
}

// --- SAUVEGARDE DE LA MISE EN PAGE PERSONNALISÉE DE L'ACCUEIL (PROPRE À CHAQUE UTILISATEUR) ---
// Format : {"layout": ["href1", {"type":"dossier","id":"...","titre":"...","icone":"...","couleur":"...","enfants":["href2"]}], "masquees": ["href3"]}
// Tout ce qui vient du client est revalidé ici : seules les pages réellement présentes sur l'accueil
// peuvent être référencées, et les champs des dossiers créés par l'utilisateur sont bornés/nettoyés.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_tuiles_ordre') {
    try {
        require_once 'db.php';
        $db->exec("CREATE TABLE IF NOT EXISTS tuiles_ordre (utilisateur VARCHAR(100) PRIMARY KEY, ordre TEXT)");

        $HREFS_VALIDES = [
            'maintenance.php', 'planning.php', 'sous_traitants.php', 'preventif.php',
            'stats_tech.php', 'kpi.php', 'admin_machines.php', 'admin_reset.php', 'idees_admin.php',
            'parametres.php', 'logs.php', 'accueil.php', 'aide.php',
        ];
        $ICONES_DOSSIER_VALIDES = [
            'fa-folder', 'fa-folder-open', 'fa-layer-group', 'fa-boxes-stacked', 'fa-toolbox',
            'fa-clipboard-list', 'fa-chart-pie', 'fa-users', 'fa-gear', 'fa-house-chimney',
            'fa-wrench', 'fa-bell', 'fa-flag', 'fa-shield-halved', 'fa-building',
            'fa-truck', 'fa-industry', 'fa-clock', 'fa-star', 'fa-bookmark',
            'fa-pen-to-square', 'fa-calendar-days', 'fa-users-gear', 'fa-hourglass-half', 'fa-calendar-check',
            'fa-user-clock', 'fa-chart-line', 'fa-gears', 'fa-user-shield', 'fa-lightbulb',
            'fa-sliders', 'fa-clock-rotate-left', 'fa-right-left', 'fa-circle-question',
        ];

        $decode = json_decode($_POST['ordre'] ?? '', true);
        if (!is_array($decode) || !isset($decode['layout']) || !is_array($decode['layout'])) {
            throw new Exception('Mise en page invalide.');
        }

        $layoutPropre = [];
        foreach ($decode['layout'] as $item) {
            if (is_string($item)) {
                if (in_array($item, $HREFS_VALIDES, true)) { $layoutPropre[] = $item; }
            } elseif (is_array($item) && ($item['type'] ?? '') === 'dossier') {
                $id = preg_match('/^[a-zA-Z0-9_-]{1,50}$/', $item['id'] ?? '') ? $item['id'] : ('dossier_' . bin2hex(random_bytes(4)));
                $titre = trim(mb_substr((string)($item['titre'] ?? 'Dossier'), 0, 30));
                if ($titre === '') { $titre = 'Dossier'; }
                $desc = trim(mb_substr((string)($item['desc'] ?? ''), 0, 120));
                $icone = in_array($item['icone'] ?? '', $ICONES_DOSSIER_VALIDES, true) ? $item['icone'] : 'fa-folder';
                $couleur = preg_match('/^#[0-9a-fA-F]{6}$/', $item['couleur'] ?? '') ? $item['couleur'] : '#34495e';
                $enfants = [];
                if (is_array($item['enfants'] ?? null)) {
                    foreach ($item['enfants'] as $h) {
                        if (is_string($h) && in_array($h, $HREFS_VALIDES, true) && !in_array($h, $enfants, true)) { $enfants[] = $h; }
                    }
                }
                $layoutPropre[] = ['type' => 'dossier', 'id' => $id, 'titre' => $titre, 'desc' => $desc, 'icone' => $icone, 'couleur' => $couleur, 'enfants' => $enfants];
            }
        }

        $masqueesPropre = [];
        if (is_array($decode['masquees'] ?? null)) {
            foreach ($decode['masquees'] as $h) {
                if (is_string($h) && in_array($h, $HREFS_VALIDES, true) && !in_array($h, $masqueesPropre, true)) { $masqueesPropre[] = $h; }
            }
        }

        $ordreJson = json_encode(['layout' => $layoutPropre, 'masquees' => $masqueesPropre]);

        $stmt = $db->prepare("INSERT INTO tuiles_ordre (utilisateur, ordre) VALUES (?, ?) ON DUPLICATE KEY UPDATE ordre = VALUES(ordre)");
        $stmt->execute([$_SESSION['user'], $ordreJson]);

        echo "OK";
    } catch (Exception $e) {
        http_response_code(500);
        error_log("maintenance.php: " . $e->getMessage());
        echo t('maint.err_server');
    }
    exit();
}

// --- SAUVEGARDE DE L'OBJECTIF D'HEURES ANNUALISÉES D'UN TECHNICIEN (ADMIN, OU LE TECHNICIEN LUI-MÊME) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_objectif') {
    $tech = $_POST['tech'];
    if (!$is_admin && $tech !== $_SESSION['user']) {
        http_response_code(403);
        echo t('maint.err_only_own_objectif');
        exit();
    }
    try {
        require_once 'db.php';
        // Source unique de l'objectif annuel : utilisateurs.objectif_heures_annuel (voir migration dans
        // planning.php). L'ancienne table planning_objectifs n'était jamais relue par le planning, ce qui
        // faisait qu'une modification ici semblait ne "rien faire" une fois revenu sur planning.php.
        $db->exec("ALTER TABLE utilisateurs ADD COLUMN IF NOT EXISTS objectif_heures_annuel DECIMAL(6,2) NULL DEFAULT 1607");
        $objectif = isset($_POST['objectif']) && $_POST['objectif'] !== '' ? floatval(str_replace(',', '.', $_POST['objectif'])) : null;

        $stmt = $db->prepare("UPDATE utilisateurs SET objectif_heures_annuel = ? WHERE username = ?");
        $stmt->execute([$objectif, $tech]);

        echo "OK";
    } catch (Exception $e) {
        http_response_code(500);
        error_log("maintenance.php: " . $e->getMessage());
        echo t('maint.err_server');
    }
    exit();
}

// --- SAUVEGARDE DES JOURS DE FRACTIONNEMENT (Art. 6) ---
// Chaque technicien gère ses propres heures, comme pour l'objectif annuel : il peut renseigner lui-même
// ses jours de fractionnement (1 ou 2 selon les conditions de prise du congé principal), un admin peut le
// faire pour n'importe qui.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_fractionnement') {
    $tech = $_POST['tech'];
    if (!$is_admin && $tech !== $_SESSION['user']) {
        http_response_code(403);
        echo t('maint.err_only_own_fractionnement');
        exit();
    }
    $periodeDebut = $_POST['periode_debut'];
    $jours = isset($_POST['jours']) ? (int)$_POST['jours'] : 0;
    if ($jours < 0) { $jours = 0; }
    if ($jours > 2) { $jours = 2; }
    try {
        require_once 'db.php';
        $db->exec("CREATE TABLE IF NOT EXISTS planning_fractionnement (
            utilisateur VARCHAR(50) NOT NULL,
            periode_debut DATE NOT NULL,
            jours TINYINT NOT NULL DEFAULT 0,
            PRIMARY KEY (utilisateur, periode_debut)
        )");
        $stmt = $db->prepare("INSERT INTO planning_fractionnement (utilisateur, periode_debut, jours) VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE jours = VALUES(jours)");
        $stmt->execute([$tech, $periodeDebut, $jours]);
        echo "OK";
    } catch (Exception $e) {
        http_response_code(500);
        error_log("maintenance.php: " . $e->getMessage());
        echo t('maint.err_server');
    }
    exit();
}

// --- SAUVEGARDE D'UNE AFFECTATION PLANNING (POSTE / ASTREINTE / HEURES) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_shift') {
    $tech = $_POST['tech'];
    if (!$is_admin && $tech !== $_SESSION['user']) {
        http_response_code(403);
        echo t('maint.err_only_own_days_plan');
        exit();
    }
    try {
        require_once 'db.php';
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
        $db->exec("ALTER TABLE planning_shifts ADD COLUMN IF NOT EXISTS demi_conge TINYINT(1) NOT NULL DEFAULT 0");
        $db->exec("ALTER TABLE planning_shifts ADD COLUMN IF NOT EXISTS jour_ferie TINYINT(1) NOT NULL DEFAULT 0");
        $jour = $_POST['date'];
        $poste = !empty($_POST['poste']) ? $_POST['poste'] : null;
        $astreinte = !empty($_POST['astreinte']) ? $_POST['astreinte'] : null;
        $heures = isset($_POST['heures']) && $_POST['heures'] !== '' ? floatval(str_replace(',', '.', $_POST['heures'])) : null;
        if ($heures !== null) { $heures = max(0, min(24, $heures)); }
        $note = isset($_POST['note']) && trim($_POST['note']) !== '' ? mb_substr(trim($_POST['note']), 0, 255) : null;
        // Demi-congé payé : peut s'ajouter à un poste normal (matin/après-midi/nuit/journée) pour signaler
        // qu'une partie de la journée a quand même été travaillée, ou rester seul comme avant.
        $demi_conge = !empty($_POST['demi_conge']) ? 1 : 0;
        // Jour férié : peut lui aussi s'ajouter à un poste normal (matin/après-midi/nuit/journée) pour
        // signaler un jour férié effectivement travaillé, sans effacer le poste choisi.
        $jour_ferie = !empty($_POST['jour_ferie']) ? 1 : 0;

        $stmt = $db->prepare("INSERT INTO planning_shifts (utilisateur, jour, poste, astreinte, heures, note, demi_conge, jour_ferie) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE poste = VALUES(poste), astreinte = VALUES(astreinte), heures = VALUES(heures), note = VALUES(note), demi_conge = VALUES(demi_conge), jour_ferie = VALUES(jour_ferie)");
        $stmt->execute([$tech, $jour, $poste, $astreinte, $heures, $note, $demi_conge, $jour_ferie]);

        echo "OK";
    } catch (Exception $e) {
        http_response_code(500);
        error_log("maintenance.php: " . $e->getMessage());
        echo t('maint.err_server');
    }
    exit();
}

// --- SUPPRESSION D'UNE AFFECTATION PLANNING ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'clear_shift') {
    if (!$is_admin && $_POST['tech'] !== $_SESSION['user']) {
        http_response_code(403);
        echo t('maint.err_only_own_days_modif');
        exit();
    }
    try {
        require_once 'db.php';
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
        $db->exec("ALTER TABLE planning_shifts ADD COLUMN IF NOT EXISTS demi_conge TINYINT(1) NOT NULL DEFAULT 0");
        $db->exec("ALTER TABLE planning_shifts ADD COLUMN IF NOT EXISTS jour_ferie TINYINT(1) NOT NULL DEFAULT 0");
        $stmt = $db->prepare("DELETE FROM planning_shifts WHERE utilisateur = ? AND jour = ?");
        $stmt->execute([$_POST['tech'], $_POST['date']]);

        echo "OK";
    } catch (Exception $e) {
        http_response_code(500);
        error_log("maintenance.php: " . $e->getMessage());
        echo t('maint.err_server');
    }
    exit();
}

// --- AJOUT D'UNE TÂCHE TODO LIST (voir get_todo plus haut pour le report automatique) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'todo_add') {
    $tech = $_POST['tech'] ?? '';
    if (!$is_admin && $tech !== $_SESSION['user']) {
        http_response_code(403);
        echo t('maint.err_only_own_days_plan');
        exit();
    }
    try {
        require_once 'db.php';
        $db->exec("CREATE TABLE IF NOT EXISTS planning_todo (
            id INT AUTO_INCREMENT PRIMARY KEY,
            utilisateur VARCHAR(100) NOT NULL,
            jour DATE NOT NULL,
            texte VARCHAR(255) NOT NULL,
            fait TINYINT(1) NOT NULL DEFAULT 0,
            date_creation DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        )");
        $texte = mb_substr(trim($_POST['texte'] ?? ''), 0, 255);
        if ($texte === '') { http_response_code(400); echo t('maint.err_server'); exit(); }
        $stmt = $db->prepare("INSERT INTO planning_todo (utilisateur, jour, texte) VALUES (?, ?, ?)");
        $stmt->execute([$tech, $_POST['date'] ?? '', $texte]);
        ob_clean();
        header('Content-Type: application/json');
        echo json_encode(['id' => (int)$db->lastInsertId()]);
    } catch (Exception $e) {
        http_response_code(500);
        error_log("maintenance.php: " . $e->getMessage());
        echo t('maint.err_server');
    }
    exit();
}

// --- COCHER/DÉCOCHER UNE TÂCHE TODO LIST ---
// Le propriétaire vient de la ligne en base (pas d'un champ du formulaire) : un technicien ne peut pas se
// donner accès à la tâche d'un collègue en falsifiant un paramètre "tech" côté client.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'todo_toggle') {
    try {
        require_once 'db.php';
        $stmt = $db->prepare("SELECT utilisateur FROM planning_todo WHERE id = ?");
        $stmt->execute([$_POST['id'] ?? '']);
        $proprietaire = $stmt->fetchColumn();
        if ($proprietaire === false) { http_response_code(404); exit(); }
        if (!$is_admin && $proprietaire !== $_SESSION['user']) {
            http_response_code(403);
            echo t('maint.err_only_own_days_modif');
            exit();
        }
        $stmt = $db->prepare("UPDATE planning_todo SET fait = ? WHERE id = ?");
        $stmt->execute([!empty($_POST['fait']) ? 1 : 0, $_POST['id']]);
        echo "OK";
    } catch (Exception $e) {
        http_response_code(500);
        error_log("maintenance.php: " . $e->getMessage());
        echo t('maint.err_server');
    }
    exit();
}

// --- SUPPRESSION D'UNE TÂCHE TODO LIST (même vérification de propriétaire que todo_toggle) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'todo_delete') {
    try {
        require_once 'db.php';
        $stmt = $db->prepare("SELECT utilisateur FROM planning_todo WHERE id = ?");
        $stmt->execute([$_POST['id'] ?? '']);
        $proprietaire = $stmt->fetchColumn();
        if ($proprietaire === false) { http_response_code(404); exit(); }
        if (!$is_admin && $proprietaire !== $_SESSION['user']) {
            http_response_code(403);
            echo t('maint.err_only_own_days_modif');
            exit();
        }
        $stmt = $db->prepare("DELETE FROM planning_todo WHERE id = ?");
        $stmt->execute([$_POST['id']]);
        echo "OK";
    } catch (Exception $e) {
        http_response_code(500);
        error_log("maintenance.php: " . $e->getMessage());
        echo t('maint.err_server');
    }
    exit();
}

// --- MISE À JOUR DÉPLACEMENT PLANNING (DISSOCIÉ) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_pointage_planif') {
    try {
        require_once 'db.php';
        $id = $_POST['id'];
        $tech = $_POST['tech'];
        // On récupère la date du planning et on y ajoute l'heure actuelle pour la précision
        $date = $_POST['date'] . ' ' . date('H:i:s');
        
        // 1. On met à jour le planning (table pointages)
        $stmt = $db->prepare("UPDATE pointages SET tech = ?, date = ? WHERE id = ?");
        $stmt->execute([$tech, $date, $id]);
        
        // 2. CORRECTION : On met à jour l'historique global (table taches) au bon jour
        $stmt_task = $db->prepare("UPDATE taches SET date = ? WHERE id = (SELECT task_id FROM pointages WHERE id = ?)");
        $stmt_task->execute([$_POST['date'] . " 08:00", $id]);
        
        echo "OK";
    } catch (Exception $e) {
        http_response_code(500);
        error_log("maintenance.php: " . $e->getMessage());
        echo t('maint.err_server');
    }
    exit();
}

// ============================================================================
// RÉCUPÉRATION DES MACHINES (CORRECTION FINALE)
// ============================================================================
$machines_db = [];
try {
    if(isset($db)) {
        // Attention : on utilise bien 'nom_machine' ici (Modifié pour inclure la ligne avec sécurité)
        try {
            $stmt = $db->query("SELECT usine, secteur, ligne, zone, nom_machine FROM machines ORDER BY usine, secteur, ligne, zone, ordre, nom_machine");
        } catch (Exception $e) {
            $stmt = $db->query("SELECT usine, secteur, zone, nom_machine FROM machines ORDER BY usine, secteur, zone, ordre, nom_machine");
        }
        if($stmt) { 
            $machines_db = $stmt->fetchAll(PDO::FETCH_ASSOC); 
        }
    }
} catch (Throwable $e) {}

$json_machines = json_encode($machines_db);
if (!$json_machines) { $json_machines = '[]'; }
// --- CHARGEMENT DES SOUS-TRAITANTS ---
$entreprises_ext = [];
try {
    if(isset($db)) {
        $resEE = $db->query("SELECT id, nom FROM entreprises_ext ORDER BY nom");
        if($resEE) { $entreprises_ext = $resEE->fetchAll(PDO::FETCH_ASSOC); }
    }
} catch (Throwable $e) {}
// ============================================================================
// --- RÉCUPÉRATION DES TOTAUX D'HEURES ---
$heures_par_tech = [];
try {
    if(isset($db)) {
        // On demande à la base de calculer le total d'heures par personne
        $res = $db->query("SELECT tech, SUM(hours) as total FROM pointages GROUP BY tech");
        $heures_par_tech = $res->fetchAll(PDO::FETCH_KEY_PAIR); 
    }
} catch (Exception $e) {}

// --- CHARGEMENT DYNAMIQUE DE L'ÉQUIPE MAINTENANCE ---
$team_maintenance = [];
try {
    if(isset($db)) {
        // La nouvelle requête avec ton tri par ordre, EN EXCLUANT LE DIRECTEUR
        $resEquipe = $db->query("
            SELECT username, role, fonction, photo
            FROM utilisateurs
            WHERE role IN ('admin', 'technicien')
            AND username != 'Directeur'
            ORDER BY
                CASE WHEN ordre > 0 THEN ordre ELSE 99 END ASC,
                username ASC
        ");

        if($resEquipe) {
            while($row = $resEquipe->fetch(PDO::FETCH_ASSOC)) {
                // Intitulé de poste : celui défini dans Gestion Utilisateurs (fonction) s'il existe,
                // sinon repli sur Responsable/Technicien selon le rôle d'accès.
                if (!empty($row['fonction'])) {
                    $role_a_afficher = $row['fonction'];
                } else {
                    $role_a_afficher = (strtolower($row['role']) === 'admin') ? 'Responsable' : 'Technicien';
                }
                $team_maintenance[] = [
                    "name" => $row['username'],
                    "role" => $role_a_afficher,
                    "photo" => $row['photo']
                ];
            }
        } // Fermeture du if($resEquipe)
    } // Fermeture du if(isset($db))
} catch (Exception $e) {
    // Optionnel : logger l'erreur ici
}
// Fin du bloc try-catch
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(langue_actuelle()); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>GMAO Maintenance</title>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
    <link rel="icon" type="image/png" href="img/logo.png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Caveat:wght@600&family=Segoe+UI:wght@300;400;600&display=swap" rel="stylesheet">
    <style>
        :root {
            /* TES COULEURS D'ORIGINE (Intactes) */
            --primary: #2c3e50; --accent: #3498db; --success: #2ecc71; 
            --danger: #e74c3c; --brand-green: #2ecc71; --brand-orange: #f39c12;
            --stat-red: #c0392b; --purple: #9b59b6; --dark-blue: #2980b9;

            /* NOUVELLES COULEURS "FINESSE" (Pour adoucir la modale) */
            --soft-blue: #ebf5fb;
            --soft-orange: #fff5e6;
            --soft-green: #e8f8f5;
            --text-main: #455a64; /* Gris ardoise doux au lieu du noir pur */
            --text-light: #90a4ae; /* Gris clair pour les sous-titres */
            --border-color: #eef2f5;
        }
        
        @keyframes pulse-red { 0% { background-color: var(--stat-red); } 50% { background-color: #e74c3c; } 100% { background-color: var(--stat-red); } }
        .blink-sirene { animation: pulse-red 1.5s ease-in-out infinite !important; background-color: var(--stat-red) !important; color: white !important; border: none !important; }
        /* Pastille "nombre de messages" sur un BI : bleu neutre en permanence dès qu'il y a un
           historique de discussion, et bascule en rouge clignotant (.blink-sirene, même animation
           que les tickets Urgent) tant qu'il reste au moins un message non lu. */
        .badge-msg-count { display: none; background: var(--accent); color: #fff; border-radius: 4px; padding: 2px 5px; font-size: 0.6rem; font-weight: 700; box-shadow: 0 1px 3px rgba(52,152,219,0.4); }
        @keyframes shake { 0%, 100% { transform: rotate(0deg); } 25% { transform: rotate(-15deg); } 75% { transform: rotate(15deg); } }
        .icon-urgent-blink { animation: shake 0.5s infinite; display: inline-block; margin-right: 5px; }
        @keyframes pulse-dot { 0% { transform: scale(1); opacity: 0.6; } 100% { transform: scale(2.5); opacity: 0; } }
        @keyframes notify-pulse { 0% { transform: scale(0.9); box-shadow: 0 0 0 0 rgba(231, 76, 60, 0.7); } 70% { transform: scale(1); box-shadow: 0 0 0 10px rgba(231, 76, 60, 0); } 100% { transform: scale(0.9); box-shadow: 0 0 0 0 rgba(231, 76, 60, 0); } }
        .badge-notify { position: absolute; top: -5px; right: -5px; width: 12px; height: 12px; background: var(--danger); border-radius: 50%; border: 2px solid white; display: none; animation: notify-pulse 1.5s infinite; }
        @keyframes water-ripple { 0% { box-shadow: 0 0 0 0 rgba(52, 152, 219, 0.4); } 100% { box-shadow: 0 0 0 15px rgba(52, 152, 219, 0); } }

        body { 
    margin: 0; 
    font-family: 'Segoe UI', sans-serif; 
    /* On change 'center center' par 'center 110px' pour décaler l'image vers le bas */
    background: linear-gradient(rgba(0, 0, 0, 0.2), rgba(0, 0, 0, 0.2)), url('img/fond.jpg') no-repeat center 0px fixed; 
    background-size: cover; 
    min-height: 100vh;
    padding-top: 98px;
}
        header { position: fixed; top: 0; width: 100%; z-index: 1000; overflow: hidden; box-shadow: 0 4px 20px rgba(0,0,0,0.25); }
        header::before { content: ""; position: absolute; top: -12px; left: -12px; right: -12px; bottom: -12px; background: linear-gradient(rgba(0, 0, 0, 0.2), rgba(0, 0, 0, 0.2)), url('img/fond.jpg') no-repeat center 0px fixed; background-size: cover; filter: blur(4px); z-index: -1; }
        .header-top { display: flex; justify-content: space-between; align-items: center; padding: 12px 20px; }
        .header-title { font-family: 'Caveat', cursive; font-size: 1.5rem; color: white; background: rgba(255,255,255,0.12); backdrop-filter: blur(14px) brightness(1.15); -webkit-backdrop-filter: blur(14px) brightness(1.15); border: 1px solid rgba(255,255,255,0.4); border-radius: 20px; padding: 6px 18px; text-shadow: 0 2px 6px rgba(0,0,0,0.4); box-shadow: 0 6px 16px rgba(0,0,0,0.2); }
        .nav-tabs { display: flex; background: #fff; padding: 0 10px; gap: 2px; }
        .tab-item { padding: 10px 18px; text-decoration: none; color: #7f8c8d; font-weight: 600; font-size: 0.8rem; border-bottom: 3px solid transparent; transition: 0.3s; display: flex; align-items: center; gap: 8px; }
        .tab-item.active { color: var(--accent); border-bottom: 3px solid var(--accent); background: rgba(52, 152, 219, 0.05); }
        .crumb-bar { display: flex; align-items: center; gap: 8px; font-size: 0.94rem; font-weight: 600; color: rgba(255,255,255,0.9); text-shadow: 0 1px 3px rgba(0,0,0,0.4); max-width: 99%; margin: 0 auto; padding: 0 10px 10px; }
        .crumb-bar .crumb-home { color: inherit; text-decoration: none; display: inline-flex; align-items: center; gap: 5px; }
        .crumb-bar .crumb-home:hover { text-decoration: underline; }
        .crumb-sep { color: rgba(255,255,255,0.45); font-weight: 400; }
        .crumb-current { color: rgba(255,255,255,0.75); font-weight: 400; }

        /* --- SIDEBAR HARMONISÉE (MENU MAÎTRE) --- */
        .sidebar { 
            height: 100%; 
            width: 0; 
            position: fixed; 
            z-index: 3000; 
            top: 0; 
            left: 0; 
            background-color: #1a252f; 
            overflow-x: hidden; 
            overflow-y: auto; /* Permet de scroller DANS le menu sur petite tablette/PC */
            transition: 0.4s; 
            padding-top: 60px; 
            padding-bottom: 20px; /* Air en bas de menu */
        }
        .sidebar a { 
            padding: 12px 25px; /* Marges réduites pour que tout rentre mieux */
            text-decoration: none; 
            font-size: 1.05rem; 
            color: #ecf0f1; 
            display: block; 
            transition: 0.3s; 
            border-left: 4px solid transparent; 
        }
        .sidebar a:hover { 
            background: #2c3e50; 
            border-left: 4px solid var(--accent); 
        }
        /* CLASSE POUR ALLUMER L'ONGLET ACTIF */
        .sidebar a.active-side { 
            background: #2c3e50; 
            border-left: 4px solid var(--accent); 
            color: var(--accent); 
            font-weight: bold;
        }
        .sidebar .closebtn { 
            position: absolute; 
            top: 10px; 
            right: 25px; 
            font-size: 36px; 
            color: white; 
            border: none; 
            background: none; 
            cursor: pointer; 
        }
        .openbtn {
            font-size: 22px;
            cursor: pointer;
            background: none;
            border: none;
            color: var(--primary);
            padding: 5px 10px;
        }
        .btn-accueil { font-size: 18px; color: white; text-decoration: none; display: flex; align-items: center; justify-content: center; width: 38px; height: 38px; border-radius: 50%; background: rgba(255,255,255,0.12); backdrop-filter: blur(14px) brightness(1.15); -webkit-backdrop-filter: blur(14px) brightness(1.15); border: 1px solid rgba(255,255,255,0.4); box-shadow: 0 6px 16px rgba(0,0,0,0.2); transition: transform 0.2s, background 0.2s; }
        .btn-accueil:hover { background: rgba(255,255,255,0.28); transform: translateY(-2px); }
        .btn-accueil-green:hover { background: rgba(46, 204, 113, 0.3); border-color: rgba(46, 204, 113, 0.6); }

        .user-badge { background: rgba(255,255,255,0.12); backdrop-filter: blur(14px) brightness(1.15); -webkit-backdrop-filter: blur(14px) brightness(1.15); padding: 6px 14px; border-radius: 20px; font-size: 0.75rem; font-weight: 600; display: flex; align-items: center; gap: 8px; color: white; border: 1px solid rgba(255,255,255,0.4); text-shadow: 0 1px 3px rgba(0,0,0,0.4); box-shadow: 0 6px 16px rgba(0,0,0,0.2); }
        .status-pulse { width: 8px; height: 8px; border-radius: 50%; position: relative; background: var(--success); }
        .status-pulse::after { content: ""; position: absolute; width: 100%; height: 100%; border-radius: 50%; background: inherit; animation: pulse-dot 2s infinite; opacity: 0.6; }
        
        .container { max-width: 99%; margin: 0 auto; padding: 10px; }
        .tech-stats { display: flex; justify-content: safe center; gap: 20px; margin-bottom: 25px; overflow-x: auto; padding: 30px 15px; }

        .stat-card {
            background: linear-gradient(160deg, rgba(255,255,255,0.92), rgba(255,255,255,0.72));
            backdrop-filter: blur(14px) saturate(160%); -webkit-backdrop-filter: blur(14px) saturate(160%);
            border-radius: 15px; padding: 15px 5px; min-width: 145px; max-width: 200px; flex: 1; text-align: center;
            border: 1px solid rgba(255,255,255,0.6);
            cursor: pointer; transition: all 0.35s ease; display: flex; flex-direction: column; align-items: center; position: relative;
        }
        .stat-card::after { content: ''; position: absolute; top: 0; left: 0; right: 0; bottom: 0; border-radius: 15px; z-index: -1; animation: water-ripple 2s infinite; }
        .stat-card:nth-child(odd) { border-bottom: 6px solid var(--brand-orange); box-shadow: inset 0 1px 0 rgba(255,255,255,0.7), 0 12px 28px -5px rgba(243, 156, 18, 0.75); }
        .stat-card:nth-child(even) { border-bottom: 6px solid var(--brand-green); box-shadow: inset 0 1px 0 rgba(255,255,255,0.7), 0 12px 28px -5px rgba(46, 204, 113, 0.75); }
        .stat-card:hover { transform: translateY(-8px); background: linear-gradient(160deg, rgba(255,255,255,0.98), rgba(255,255,255,0.85)); }
        .stat-card:nth-child(odd):hover {
            box-shadow: inset 0 1px 0 rgba(255,255,255,0.7),
                0 -10px 22px -8px rgba(243, 156, 18, 0.75),
                0 20px 36px -8px rgba(243, 156, 18, 0.85),
                0 8px 16px rgba(0,0,0,0.18);
        }
        .stat-card:nth-child(even):hover {
            box-shadow: inset 0 1px 0 rgba(255,255,255,0.7),
                0 -10px 22px -8px rgba(46, 204, 113, 0.75),
                0 20px 36px -8px rgba(46, 204, 113, 0.85),
                0 8px 16px rgba(0,0,0,0.18);
        }

        .avatar-img { width: 55px; height: 55px; border-radius: 50%; margin-bottom: 8px; border: 3px solid white; object-fit: cover; box-shadow: 0 3px 10px rgba(0,0,0,0.1); }
        .stat-row {
            width: 92%; display: flex; justify-content: space-between; align-items: center; margin-bottom: 2px;
            font-size: 0.75rem; font-weight: 600;
        }
        .stat-label { display: flex; align-items: center; gap: 5px; }
 
        .stat-footer {
            border-top: 1px dashed rgba(0,0,0,0.15);
            width: 95%;
            margin: 5px auto 0 auto;
            padding-top: 5px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 0.7rem;
            font-weight: 400;
            color: #000000;
            gap: 4px;
        }

        .stat-footer span {
            background: none !important;
            padding: 0 !important;
            box-shadow: none !important;
            border: none !important;
            font-size: 0.72rem;
            color: #000000 !important;
            font-weight: 400;
            margin-left: 2px;
        }

        /* Le libellé ("5 taak/taken in behandeling"...) peut être bien plus long en néerlandais qu'en
           français/anglais : il doit pouvoir passer à la ligne au lieu de déborder de la carte quand
           celle-ci est rétrécie (largeur tablette). Le nombre d'heures, lui, reste sur une seule ligne. */
        .stat-footer span:first-child { white-space: normal; text-align: left; flex: 1 1 auto; min-width: 0; }
        .stat-footer span:last-child { white-space: nowrap; flex-shrink: 0; }

        .kpi-tiles-section { margin-bottom: 20px; }
        /* Même système que .tuile / .tuiles-grid de preventif.php : grille + effet "vitre dépolie" */
        .recap-bar { display: flex; width: 100%; box-sizing: border-box; gap: 12px; align-items: stretch; justify-content: center; flex-wrap: wrap; }
        .recap-rows-wrap { display: flex; flex-direction: column; gap: 15px; flex: none; }
        .recap-row { display: flex; flex-wrap: nowrap; justify-content: center; gap: 12px; }

        /* Grande vignette "Taux de réalisation" — occupe la hauteur des 2 rangées, sur la gauche */
        .kpi-big-tile {
            display: flex; flex-direction: column; align-items: center; justify-content: center; text-align: center; gap: 8px;
            background: rgba(255, 255, 255, 0.025);
            backdrop-filter: blur(7px) brightness(1.2);
            -webkit-backdrop-filter: blur(7px) brightness(1.2);
            border-radius: 16px; padding: 18px 24px;
            box-shadow: 0 10px 25px rgba(0,0,0,0.2);
            border: 1px solid rgba(255,255,255,0.35);
            border-top: 4px solid var(--tile-color, var(--accent));
            cursor: default; user-select: none;
            flex: 0 0 210px;
            transition: transform 0.3s, box-shadow 0.3s;
        }
        .kpi-big-tile:hover {
            transform: translateY(-6px);
            box-shadow:
                0 -8px 18px -8px rgba(var(--tile-rgb, 52, 152, 219), 0.55),
                0 16px 28px -8px rgba(var(--tile-rgb, 52, 152, 219), 0.65),
                0 8px 16px rgba(0,0,0,0.22);
        }
        .kpi-big-icon {
            width: 56px; height: 56px; border-radius: 50%;
            background: var(--tile-color, var(--accent)); color: #fff;
            display: flex; align-items: center; justify-content: center; font-size: 1.6rem;
            box-shadow: 0 6px 15px -3px rgba(0,0,0,0.4);
        }
        .kpi-big-value { font-size: 2.4rem; font-weight: 900; color: #fff; line-height: 1; text-shadow: 0 1px 6px rgba(0,0,0,0.5); }
        .kpi-big-label { font-family: 'Caveat', cursive; font-size: 1.3rem; font-weight: 700; color: #fff; text-shadow: 0 1px 5px rgba(0,0,0,0.5); }
        .kpi-big-sub { font-size: 0.68rem; font-weight: 700; text-transform: uppercase; letter-spacing: .03em; color: rgba(255,255,255,0.85); text-shadow: 0 1px 4px rgba(0,0,0,0.45); }
        .recap-item {
            display: flex; flex-direction: column; align-items: center; text-align: center; gap: 6px;
            background: rgba(255, 255, 255, 0.025);
            backdrop-filter: blur(7px) brightness(1.2);
            -webkit-backdrop-filter: blur(7px) brightness(1.2);
            border-radius: 13px; padding: 13px 10px;
            box-shadow: 0 8px 20px rgba(0,0,0,0.18);
            border: 1px solid rgba(255,255,255,0.35);
            border-top: 4px solid var(--tile-color, var(--accent));
            cursor: pointer; transition: transform 0.3s, box-shadow 0.3s; user-select: none;
            flex: 0 0 165px;
        }
        /* --- VIGNETTES KPI (recap-item) : tablette/téléphone ---
           .recap-item a une largeur FIXE (flex:0 0 165px, ne rétrécit jamais) : sur écran étroit, un seul
           tient par ligne et le reste s'empile dessous à l'infini ("l'une sur l'autre"). En dessous de
           1024px on réduit d'abord la taille des vignettes pour que plusieurs tiennent naturellement par
           ligne (largeur flexible cette fois) ; en dessous de 480px (téléphone en portrait, le cas le
           plus étroit) on force explicitement 2 colonnes par grille plutôt que de laisser le flex-wrap
           décider, pour garantir "toujours par deux" comme demandé plutôt qu'un résultat imprévisible. */
        @media screen and (max-width: 1024px) {
            .recap-item { flex: 1 1 130px; max-width: 150px; padding: 10px 8px; gap: 4px; }
            .recap-item .kpi-tile-icon { width: 30px; height: 30px; font-size: 0.9rem; }
            .recap-item .kpi-tile-label { font-size: 0.95rem; }
            .recap-item .kpi-tile-value { font-size: 0.72rem; padding: 1px 9px; }
        }
        @media screen and (max-width: 480px) {
            /* Les deux .recap-row (statuts / types) font chacune 5 tuiles : en grille à 2 colonnes
               indépendante par ligne, la 5e tuile de CHAQUE groupe se retrouvait seule ("Terminée",
               "Vérif. visserie"). display:contents fait disparaître le conteneur .recap-row de l'arbre de
               mise en page (ses enfants deviennent des enfants directs de .recap-rows-wrap) : les deux
               groupes de 5 fusionnent en une seule séquence de 10 tuiles, qui se répartit alors en paires
               parfaites sans aucune tuile isolée. */
            .recap-rows-wrap { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 8px; }
            .recap-row { display: contents; }
            .recap-item { flex: none; max-width: none; width: auto; }
        }
        /* Filtre de dates "Historique Global" : Du/Au + bouton reset, chacun sur sa propre ligne pleine
           largeur plutôt qu'un flex-wrap qui les laissait quasiment collés/superposés visuellement. */
        @media screen and (max-width: 560px) {
            .histo-date-filter { flex-direction: column; align-items: stretch; width: 100%; }
            .histo-date-filter label { margin-top: 2px; }
            .histo-date-filter input[type="date"] { width: 100%; box-sizing: border-box; }
            .histo-date-filter button { width: 100%; box-sizing: border-box; }
        }
        .recap-item:hover {
            transform: translateY(-6px);
            box-shadow:
                0 -8px 18px -8px rgba(var(--tile-rgb, 52, 152, 219), 0.55),
                0 16px 28px -8px rgba(var(--tile-rgb, 52, 152, 219), 0.65),
                0 8px 16px rgba(0,0,0,0.22);
        }
        .recap-item .kpi-tile-icon {
            width: 36px; height: 36px; border-radius: 50%; flex: none;
            background: var(--tile-color, var(--accent));
            color: #fff;
            display: flex; align-items: center; justify-content: center; font-size: 1rem;
            box-shadow: 0 6px 15px -3px rgba(0,0,0,0.4);
        }
        .recap-item .kpi-tile-label { font-family: 'Caveat', cursive; font-size: 1.1rem; font-weight: 700; color: #fff; text-shadow: 0 1px 5px rgba(0,0,0,0.5); }
        .recap-item .kpi-tile-value {
            background: var(--tile-color, var(--accent)); color: #fff; font-weight: 800; font-size: 0.8rem;
            padding: 2px 12px; border-radius: 20px; box-shadow: 0 2px 6px rgba(0,0,0,0.25); line-height: 1.5;
        }
        .recap-item.chip-active { background: var(--tile-color, var(--accent)); }
        .recap-item.chip-active .kpi-tile-icon { background: rgba(255,255,255,0.3); }
        .recap-item.chip-active .kpi-tile-value { background: rgba(255,255,255,0.3); }

        .card { background: rgba(255, 255, 255, 0.85); padding: 15px; border-radius: 12px; margin-bottom: 20px; box-shadow: 0 5px 15px rgba(0,0,0,0.1); }
        .card-title { font-family: 'Caveat', cursive; font-size: 1.6rem; margin-bottom: 12px; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; row-gap: 8px; }
        .saisie-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 12px; }
        .field { display: flex; flex-direction: column; align-items: flex-start; font-size: 0.7rem; font-weight: bold; color: var(--primary); }
        .field input, .field select { width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 5px; margin-top: 4px; height: 35px; box-sizing: border-box; }
        .field select:disabled { background: #f1f2f6; cursor: not-allowed; color: #999; }
        
        #btn-submit { width: 100%; height: 40px; margin-top: 10px; background: var(--brand-green); color: white; border: none; border-radius: 5px; font-weight: bold; cursor: pointer; grid-column: 1 / -1; transition: 0.3s; }
        .wizard-nav #btn-submit { width: auto; height: auto; margin-top: 0; padding: 10px 22px; font-size: 0.88rem; border-radius: 8px; display: flex; align-items: center; gap: 8px; }
        #btn-submit:hover { background: #27ae60; }
        .btn-update { background: var(--accent) !important; }
        
        table { width: 100%; border-collapse: collapse; }
        th { padding: 10px; text-align: center; font-size: 0.65rem; border-bottom: 2px solid #eee; text-transform: uppercase; }
        td { padding: 10px; text-align: center; font-size: 0.85rem; border-bottom: 1px solid #f5f5f5; word-wrap: break-word; }
        /* Colonnes figées : un contenu trop long (ex. localisation) ne doit plus déformer les autres colonnes.
           Les largeurs réelles sont pilotées par le <colgroup> (table-layout:fixed ne lit que la 1ère ligne
           du <thead> sinon, d'où l'ajout d'un colgroup dédié plutôt que des width sur les <th>). */
        .task-table { table-layout: fixed; }
        .task-table .cell-loc-line { white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .task-table th.tc-tight, .task-table td.tc-tight { padding-left: 4px; padding-right: 4px; }

        /* --- HISTORIQUE GLOBAL : cadre fixe, seules les lignes défilent à l'intérieur --- */
        .histo-limit-bar { display: flex; align-items: center; justify-content: flex-end; gap: 10px; margin-bottom: 8px; }
        .histo-limit-label { font-size: 0.75rem; font-weight: 700; color: #64748b; }
        .histo-limit-buttons { display: flex; gap: 4px; }
        .histo-limit-buttons button { border: 1px solid #dcdfe3; background: #fff; color: var(--primary); width: 28px; height: 28px; border-radius: 6px; cursor: pointer; display: flex; align-items: center; justify-content: center; font-size: 0.75rem; transition: 0.15s; }
        .histo-limit-buttons button:hover:not(:disabled) { background: var(--brand-green); border-color: var(--brand-green); color: #fff; }
        .histo-limit-buttons button:disabled { opacity: 0.35; cursor: not-allowed; }
        .historique-table-scroll { overflow: auto; max-height: 78vh; border-radius: 8px; border: 1px solid rgba(0,0,0,0.06); }
        /* Seule la ligne des titres de colonnes reste collée en haut (la ligne de filtres défile avec le contenu) */
        .historique-table-scroll table.task-table thead tr:nth-child(2) th { position: sticky; top: 0; z-index: 2; background: #f8fafc; }

        /* --- VUE "CARTES" DE L'HISTORIQUE (tablette/téléphone) ---
           Le tableau à 10 colonnes est illisible en dessous de 820px, même avec défilement horizontal
           contenu : on bascule alors sur des cartes verticales (même esprit que les tuiles de "Suivi de
           mes demandes"), une par bon d'intervention. Les deux jeux de HTML sont générés en parallèle
           par renderHistoriqueBody (voir JS) ; seul l'affichage change ici, en CSS, selon la largeur —
           donc pas de recalcul JS au redimensionnement. Les filtres par colonne du tableau restent
           remplacés par la recherche globale déjà au-dessus (.global-search-bar), déjà utilisable seule
           sur mobile. */
        .bi-cards-grid { display: none; }
        /* Deux conditions en "OU" (virgule) plutôt qu'une seule sur la largeur : un téléphone/une tablette
           en paysage est large (souvent > 1024px) mais bas — s'appuyer uniquement sur la largeur les
           aurait fait retomber sur le tableau, illisible dans ce format. Un vrai écran de PC est large ET
           haut, donc ne déclenche ni l'une ni l'autre condition et garde le tableau. */
        @media screen and (max-width: 1024px), screen and (max-height: 600px) {
            .historique-table-scroll { display: none; }
            .bi-cards-grid { display: flex; flex-direction: column; gap: 10px; }
        }
        .bi-card { position: relative; background: #fff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 12px 14px 12px 18px; overflow: hidden; }
        .bi-card-accent { position: absolute; top: 0; left: 0; bottom: 0; width: 5px; }
        .bi-card-top { display: flex; align-items: center; justify-content: space-between; gap: 8px; margin-bottom: 6px; }
        .bi-card-num { font-weight: bold; color: var(--brand-orange); font-size: 0.95rem; display: flex; align-items: center; gap: 6px; cursor: pointer; }
        .bi-card-machine { font-weight: 600; color: var(--primary); font-size: 0.88rem; margin-bottom: 2px; }
        .bi-card-machine i { font-size: 0.7rem; opacity: 0.5; margin-right: 4px; }
        .bi-card-loc { font-size: 0.72rem; color: #64748b; margin-bottom: 6px; }
        .bi-card-desc { font-size: 0.78rem; color: #475569; margin-bottom: 8px; overflow: hidden; text-overflow: ellipsis; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; }
        .bi-card-meta { display: flex; flex-wrap: wrap; gap: 12px; font-size: 0.75rem; margin-bottom: 8px; }
        .bi-card-bottom { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 8px; padding-top: 8px; border-top: 1px dashed #eef2f5; font-size: 0.75rem; color: #64748b; }
        .bi-card-bottom > span { display: inline-flex; align-items: center; gap: 5px; white-space: nowrap; }
        .bi-card-actions { display: flex; align-items: center; gap: 10px; }
        .bi-card-actions button { border: none; background: none; cursor: pointer; padding: 4px; }
        /* Petits espaces demandés entre certaines colonnes : BI|Date, Date|Localisation, Intervenants|Durée, Durée|Statut, Statut|Type */
        .task-table th.gap-r, .task-table td.gap-r { padding-right: 18px; }
        /* Troncature de la colonne description (largeur réelle pilotée par le colgroup) */
        .cell-desc-tronquee {
          white-space: nowrap;      /* Une seule ligne */
          overflow: hidden;         /* Cache le débordement */
          text-overflow: ellipsis;  /* Ajoute les '...' */
          cursor: help;             /* Curseur d'aide au survol */
}
        
        /* Style des badges Premium */
        .status-badge { 
            padding: 6px 14px; 
            border-radius: 20px; 
            font-size: 0.72rem; 
            font-weight: 800; 
            color: white !important; 
            display: inline-flex; 
            align-items: center; 
            gap: 6px; 
            text-transform: uppercase; 
            box-shadow: 0 3px 6px rgba(0,0,0,0.12); 
            transition: 0.2s; 
            border: none;
        }

        /* On force bien les couleurs ici */
        .st-afaire { background: linear-gradient(135deg, #f39c12, #d35400) !important; } 
        .st-encours { background: linear-gradient(135deg, #3498db, #2980b9) !important; } 
        .st-termine { background: linear-gradient(135deg, #2ecc71, #27ae60) !important; } /* LE VERT ICI */
        .st-urgent { background: linear-gradient(135deg, #e74c3c, #c0392b) !important; }
        .st-attente { background: linear-gradient(135deg, #9b59b6, #8e44ad) !important; }
        .st-refuse { background: linear-gradient(135deg, #7f1d1d, #991b1b) !important; }
        .status-badge:hover { transform: scale(1.05); box-shadow: 0 5px 12px rgba(0,0,0,0.2); }
        
        /* L'animation de la cloche */
        .icon-urgent-blink {
            animation: ring 0.5s infinite alternate;
        }

        @keyframes ring {
            0% { transform: rotate(-15deg); }
            100% { transform: rotate(15deg); }
        }
        .filter-row th { padding: 5px; background: #f9f9f9; }
        .col-filter { width: 95%; padding: 5px; border: 1px solid #ddd; border-radius: 4px; font-size: 0.75rem; outline: none; height: 28px; }

        /* Barre de filtres par colonne, commune au tableau (desktop) et aux cartes (tablette/téléphone) */
        .histo-col-filters { display: flex; flex-wrap: wrap; gap: 10px 14px; background: #f9f9f9; border: 1px solid #eee; border-radius: 8px; padding: 10px 12px; margin-bottom: 10px; }
        .hcf-group { display: flex; flex-direction: column; gap: 3px; flex: 1 1 130px; min-width: 0; }
        .hcf-group label { font-size: 0.65rem; font-weight: 700; color: #7f8c8d; text-transform: uppercase; letter-spacing: 0.02em; }
        .hcf-group .col-filter { width: 100%; box-sizing: border-box; }
        @media screen and (max-width: 560px) {
            .hcf-group { flex: 1 1 calc(50% - 7px); }
        }

        .select-periode { padding: 5px 10px; border-radius: 15px; border: 1px solid var(--brand-green); font-size: 0.8rem; font-weight: bold; color: var(--primary); outline: none; cursor: pointer; }

        .modal { display:none; position:fixed; z-index:4000; left:0; top:0; width:100%; height:100%; background:rgba(0,0,0,0.6); backdrop-filter:blur(5px); }
        .modal-content { background:white; margin:2% auto; padding:20px; border-radius:15px; width:95%; max-height:90vh; overflow-y:auto; box-shadow: 0 10px 30px rgba(0,0,0,0.3); }
        .modal-form-grid { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 15px; background: #f8f9fa; padding: 20px; border-radius: 10px; border: 1px solid #ddd; }
        .modal-field { display: flex; flex-direction: column; font-weight: bold; font-size: 0.8rem; }
        .modal-field input, .modal-field select, .modal-field textarea { padding: 10px; border-radius: 5px; border: 1px solid #ccc; margin-top: 5px; font-family: inherit; }
        .btn-modal-save { grid-column: 1 / -1; padding: 15px; background: var(--brand-green); color: white; border: none; border-radius: 8px; font-weight: bold; cursor: pointer; font-size: 1rem; transition: 0.3s; }
        .icon-help { color:var(--accent); cursor:pointer; font-size:1rem; transition: transform 0.2s; }

        /* ============================================================
           SAS DE VALIDATION — bandeau d'alerte + modale + cartes
        ============================================================ */
        .sas-alert {
            display: flex; align-items: center; gap: 14px;
            max-width: 620px; margin: 0 auto 20px auto; padding: 12px 18px 12px 14px;
            background: linear-gradient(160deg, rgba(255,255,255,0.92), rgba(255,255,255,0.72));
            backdrop-filter: blur(14px) saturate(160%); -webkit-backdrop-filter: blur(14px) saturate(160%);
            border-radius: 12px; border: 1px solid rgba(255,255,255,0.6); border-left: 5px solid var(--stat-red);
            box-shadow: inset 0 1px 0 rgba(255,255,255,0.6), 0 8px 20px -6px rgba(192, 57, 43, 0.35);
            cursor: pointer; transition: transform 0.25s ease, box-shadow 0.25s ease;
        }
        .sas-alert:hover { transform: translateY(-3px); box-shadow: inset 0 1px 0 rgba(255,255,255,0.6), 0 14px 26px -6px rgba(192, 57, 43, 0.45); }
        .sas-alert-icon {
            position: relative; flex-shrink: 0; width: 42px; height: 42px; border-radius: 50%;
            background: #fdecea; color: var(--stat-red); display: flex; align-items: center; justify-content: center; font-size: 1.1rem;
        }
        .sas-alert-icon::after {
            content: ""; position: absolute; inset: -4px; border-radius: 50%;
            border: 2px solid var(--stat-red); opacity: 0.5; animation: notify-pulse 1.8s infinite;
        }
        .sas-alert-text { flex: 1; font-size: 0.85rem; color: var(--text-main); line-height: 1.3; }
        .sas-alert-text b { color: var(--primary); }
        .sas-alert-text .sas-alert-count { color: var(--stat-red); font-weight: 800; font-size: 1rem; }
        .sas-alert-cta {
            flex-shrink: 0; display: flex; align-items: center; gap: 6px; padding: 8px 14px;
            background: var(--stat-red); color: white; border-radius: 8px; font-size: 0.75rem; font-weight: 700;
            text-transform: uppercase; letter-spacing: 0.4px; white-space: nowrap;
        }

        .sas-modal-header { padding: 18px 26px; display: flex; justify-content: space-between; align-items: center; background: white; border-bottom: 1px solid var(--border-color); border-radius: 12px 12px 0 0; }
        .sas-modal-heading { display: flex; align-items: center; gap: 14px; }
        .sas-modal-icon { width: 44px; height: 44px; border-radius: 12px; background: var(--soft-orange); color: var(--brand-orange); display: flex; align-items: center; justify-content: center; font-size: 1.25rem; flex-shrink: 0; }
        .sas-modal-title { font-family: 'Caveat', cursive; margin: 0; font-size: 2rem; line-height: 1; color: var(--primary); font-weight: 600; }
        .sas-modal-subtitle { margin: 2px 0 0 0; font-size: 0.78rem; color: var(--text-light); }
        .sas-modal-close-btn { flex-shrink: 0; padding: 8px 18px; border-radius: 6px; border: 1px solid #e2e8f0; background: #fff; cursor: pointer; color: #64748b; font-size: 0.8rem; font-weight: 700; letter-spacing: 0.3px; font-family: inherit; transition: 0.2s; }
        .sas-modal-close-btn:hover { background: #f1f5f9; color: var(--primary); border-color: #cbd5e1; }

        .sas-state { text-align: center; padding: 60px 20px; color: var(--text-light); }
        .sas-state.sas-error { color: var(--danger); }
        .sas-state p { margin: 0; font-size: 0.9rem; }
        .sas-state.sas-empty p { font-family: 'Caveat', cursive; font-size: 1.8rem; color: #94a3b8; }

        /* --- vue fiche : liste maître + détail --- */
        .sas-split { display: grid; grid-template-columns: 300px 1fr; height: min(70vh, 640px); border-radius: 0 0 12px 12px; overflow: hidden; }
        .sas-master { min-height: 0; border-right: 1px solid var(--border-color); background: #eef1f5; overflow-y: auto; padding: 8px 10px; }
        .sas-master::-webkit-scrollbar, .sas-detail::-webkit-scrollbar { width: 8px; }
        .sas-master::-webkit-scrollbar-track, .sas-detail::-webkit-scrollbar-track { background: transparent; }
        .sas-master::-webkit-scrollbar-thumb, .sas-detail::-webkit-scrollbar-thumb { background: #d8dee6; border-radius: 8px; }
        .sas-master::-webkit-scrollbar-thumb:hover, .sas-detail::-webkit-scrollbar-thumb:hover { background: #c3cbd6; }
        .sas-master-head { padding: 6px 6px 10px; font-size: 0.68rem; font-weight: 700; color: var(--text-light); text-transform: uppercase; letter-spacing: 0.5px; }

        .sas-master-list { display: flex; flex-direction: column; gap: 8px; padding-bottom: 4px; }
        .sas-master-item { width: 100%; display: flex; flex-direction: column; gap: 4px; text-align: left; font-family: inherit; color: inherit; background: white; border: 1px solid var(--border-color); border-left: 4px solid var(--brand-orange); border-radius: 9px; padding: 11px 13px; cursor: pointer; box-shadow: 0 1px 2px rgba(15, 23, 42, 0.05); transition: 0.15s; }
        .sas-master-item:nth-child(even) { background: #e4eaf1; }
        .sas-master-item:hover { box-shadow: 0 4px 10px rgba(15, 23, 42, 0.1); transform: translateY(-1px); }
        .sas-master-item.is-active { background: white; border-color: var(--accent); border-left-color: var(--accent); box-shadow: 0 4px 12px rgba(52, 152, 219, 0.2); }
        .sas-master-item-top { display: flex; justify-content: space-between; align-items: center; gap: 8px; }
        .sas-master-badge-meta { display: flex; align-items: center; gap: 6px; }
        .sas-master-num { font-size: 0.66rem; font-weight: 700; color: var(--text-light); font-family: 'Consolas', monospace; }
        .sas-master-urgent-icon { color: var(--danger); font-size: 0.7rem; }
        .sas-master-service { font-weight: 700; font-size: 0.82rem; color: var(--primary); }
        .sas-master-user-icon { color: var(--accent); margin-right: 2px; }
        .sas-master-dot { width: 6px; height: 6px; border-radius: 50%; flex-shrink: 0; background: var(--brand-orange); }
        .sas-master-equip { font-size: 0.72rem; color: var(--text-light); }
        .sas-master-loc { font-size: 0.68rem; color: #94a3b8; }
        .sas-master-date { font-size: 0.68rem; color: #94a3b8; display: flex; align-items: center; gap: 4px; }
        .sas-master-desc { font-size: 0.72rem; color: #94a3b8; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }

        .sas-detail { min-height: 0; padding: 22px 26px; display: flex; flex-direction: column; gap: 18px; overflow-y: auto; }
        /* Masqué en desktop (les deux panneaux sont déjà côte à côte) — réapparaît en mobile/tablette
           (voir media query) où liste et fiche occupent l'écran l'une après l'autre. */
        .sas-detail-back { display: none; align-items: center; gap: 6px; align-self: flex-start; background: none; border: none; color: var(--accent); font-weight: 700; font-size: 0.8rem; padding: 4px 0; cursor: pointer; font-family: inherit; }
        .sas-detail-back:hover { text-decoration: underline; }
        .sas-detail-head { display: flex; justify-content: space-between; align-items: flex-start; gap: 16px; }
        /* min-width:0 : sans ça, un enfant flex garde par défaut sa largeur intrinsèque de contenu
           (min-width:auto) — un chemin de localisation long en nowrap forçait ce bloc, et donc toute
           la fiche, à déborder silencieusement au lieu de laisser .sas-detail-meta faire son travail
           de défilement/retour à la ligne. Repéré en corrigeant l'affichage mobile, valable aussi en
           desktop. */
        .sas-detail-head-main { min-width: 0; }
        .sas-detail-accent { height: 4px; border-radius: 4px; background: linear-gradient(90deg, var(--brand-orange), var(--soft-orange)); margin-top: -4px; }
        .sas-detail-id-row { display: flex; align-items: center; gap: 8px; margin-bottom: 6px; }
        .sas-detail-num { font-size: 0.68rem; font-weight: 700; color: var(--text-light); text-transform: uppercase; letter-spacing: 0.6px; font-family: 'Consolas', monospace; }
        .sas-tag { font-size: 0.62rem; font-weight: 700; letter-spacing: 0.04em; text-transform: uppercase; padding: 2px 7px; border-radius: 4px; background: #eef2f5; color: var(--text-light); border: 1px solid var(--border-color); }
        .sas-detail-service { font-weight: 700; font-size: 1.15rem; color: var(--primary); }
        .sas-detail-meta { display: flex; flex-direction: column; gap: 5px; font-size: 0.78rem; color: var(--text-light); margin-top: 4px; max-width: 100%; overflow-x: auto; }
        .sas-detail-meta span { display: flex; align-items: center; gap: 5px; white-space: nowrap; }
        .sas-detail-machine { color: var(--danger); font-weight: 700; }

        .sas-status-pill { display: flex; align-items: center; gap: 5px; font-size: 0.7rem; font-weight: 700; color: var(--brand-orange); background: var(--soft-orange); padding: 3px 9px; border-radius: 20px; text-transform: uppercase; letter-spacing: 0.3px; flex-shrink: 0; }
        .sas-status-pill i { font-size: 0.5rem; }

        .sas-desc-label { display: flex; align-items: center; gap: 5px; font-size: 0.62rem; color: var(--text-light); text-transform: uppercase; font-weight: 700; letter-spacing: 0.4px; margin-bottom: 4px; }
        .sas-desc-box { font-size: 0.86rem; color: var(--text-main); line-height: 1.55; background: #f8fafc; padding: 12px 14px; border-radius: 8px; border: 1px solid var(--border-color); max-height: 160px; overflow-y: auto; }

        .sas-assign { padding: 14px; background: #f8fafc; border-radius: 10px; border: 1px solid var(--border-color); }
        .sas-assign-title { font-size: 0.62rem; font-weight: 700; color: var(--text-light); text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 10px; display: flex; align-items: center; gap: 5px; }
        .sas-assign-grid { display: flex; gap: 12px; align-items: flex-end; }
        .sas-field-group { display: flex; flex-direction: column; }
        .sas-field-tech { width: 40%; }
        .sas-field-date { width: 32%; }
        .sas-field-prio { width: 28%; }
        .sas-field-label { font-size: 0.62rem; font-weight: 700; color: #64748b; margin-bottom: 4px; text-transform: uppercase; letter-spacing: 0.3px; }
        .sas-input { width: 100%; padding: 6px 8px; border-radius: 6px; border: 1px solid #cbd5e1; font-size: 0.8rem; color: var(--primary); background: white; font-family: inherit; height: 34px; box-sizing: border-box; transition: border-color 0.15s, box-shadow 0.15s; }
        .sas-input:focus { outline: none; border-color: var(--accent); box-shadow: 0 0 0 3px rgba(52, 152, 219, 0.15); }
        .sas-input:disabled { background: #f1f2f6; color: #94a3b8; cursor: not-allowed; }

        .sas-detail-actions { margin-top: auto; padding-top: 16px; border-top: 1px solid var(--border-color); display: flex; justify-content: space-between; align-items: center; gap: 8px; }
        .sas-actions-left { display: flex; gap: 8px; }
        .sas-btn { display: inline-flex; align-items: center; gap: 5px; border: none; padding: 8px 15px; border-radius: 7px; font-weight: 700; cursor: pointer; font-size: 0.72rem; transition: 0.2s; text-transform: uppercase; letter-spacing: 0.3px; }
        .sas-btn-ghost-danger { background: none; color: var(--danger); border: 1px solid #fecaca; }
        .sas-btn-ghost-danger:hover { background: #fef2f2; }
        .sas-btn-ghost-accent { background: none; color: var(--accent); border: 1px solid #bfe0f7; }
        .sas-btn-ghost-accent:hover { background: var(--soft-blue); }
        .sas-btn-ghost-preventif { background: none; color: var(--brand-green); border: 1px solid #b7e4c7; }
        .sas-btn-ghost-preventif:hover { background: #eafaf1; }
        .sas-btn-primary { background: var(--brand-green); color: white; padding: 9px 22px; box-shadow: 0 3px 8px rgba(46, 204, 113, 0.35); }
        .sas-btn-primary:hover { background: #27ae60; }

        /* 900px (pas 760px) : une tablette en portrait (ex. 768px) tombe déjà dans la zone où le
           panneau de détail (largeur totale moins les 300px fixes de la liste de gauche) redevient
           trop étroit pour la grille à 3 champs et la rangée de 4 boutons — sans ce palier plus haut,
           seuls les téléphones en profitaient et les tablettes gardaient le débordement horizontal. */
        @media (max-width: 900px) {
            /* Vue "liste de badges" puis "fiche complète", un écran à la fois, plutôt que d'empiler
               les deux dans une hauteur déjà comptée (l'ancien essai à 200px+1fr rendait la liste
               inutilisable dès qu'il y avait plus de 2-3 demandes). #sas-split-wrap bascule vers la
               fiche via la classe .sas-view-detail (voir applySasMobileView() en JS) ; le bouton
               "Retour" (.sas-detail-back) ramène à la liste sans recharger les données. */
            #sas-split-wrap { display: block; }
            #sas-split-wrap .sas-master { display: block; height: 100%; border-right: none; border-bottom: none; }
            #sas-split-wrap .sas-detail { display: none; height: 100%; }
            #sas-split-wrap.sas-view-detail .sas-master { display: none; }
            #sas-split-wrap.sas-view-detail .sas-detail { display: flex; }
            .sas-detail-back { display: inline-flex; }

            /* Le badge n'affiche que l'essentiel (dossier, urgence, service, équipement, date/heure) :
               localisation détaillée et aperçu de description restent réservés à la fiche complète. */
            .sas-master-extra { display: none; }

            /* En-tête de fiche : la pastille "En attente" passe sous les infos plutôt que de forcer
               tout le bloc à déborder horizontalement (avec 2-3 étiquettes désormais possibles sur
               l'id-row : dossier, tag préventif, urgent). */
            .sas-detail { padding: 16px; gap: 14px; }
            .sas-detail-head { flex-wrap: wrap; }
            .sas-detail-id-row { flex-wrap: wrap; row-gap: 6px; }

            /* Le chemin de localisation (usine > secteur > ligne > zone > machine) est une seule
               ligne "nowrap" en desktop pour rester compact — sur téléphone il est bien plus lisible
               en le laissant revenir à la ligne que de forcer un défilement horizontal caché. */
            .sas-detail-meta span { white-space: normal; align-items: flex-start; }

            /* Affectation du BI : Technicien / Date / Priorité empilés plutôt que 3 champs écrasés
               côte à côte (la Date et la Priorité devenaient illisibles, ~65-85px de large). */
            .sas-assign-grid { flex-direction: column; align-items: stretch; }
            .sas-field-tech, .sas-field-date, .sas-field-prio { width: 100%; }

            /* Actions : sur desktop la rangée de 4 boutons dépassait largement la largeur visible
               (le bouton principal "Créer le BI" se retrouvait hors écran, invisible sans scroll
               horizontal). On empile : actions secondaires groupées en haut, bouton principal en
               pleine largeur en bas, bien visible. */
            .sas-detail-actions { flex-direction: column; align-items: stretch; gap: 8px; }
            .sas-actions-left { flex-wrap: wrap; width: 100%; gap: 8px; }
            /* Boutons resserrés sur mobile (padding/police réduits) : en pleine largeur, le padding
               desktop (8-9px/15-22px) les rendait disproportionnés par rapport au reste de l'écran. */
            .sas-actions-left .sas-btn { flex: 1 1 auto; justify-content: center; padding: 7px 8px; font-size: 0.66rem; }
            .sas-btn-primary { width: 100%; justify-content: center; padding: 8px 8px; font-size: 0.7rem; }
        }
        .icon-help:hover { transform: scale(1.2); color:var(--dark-blue); }
        
        .btn-pointer { background: var(--accent); color: white; border: none; border-radius: 4px; padding: 6px 10px; font-size: 0.7rem; font-weight: bold; cursor: pointer; transition: 0.2s; box-shadow: 0 2px 4px rgba(0,0,0,0.1); margin-top:5px; }
        .btn-pointer:hover { background: var(--dark-blue); transform: scale(1.05); }

        #bulk-actions-bar {
            position: fixed; bottom: 30px; left: 50%; transform: translateX(-50%);
            display: none; gap: 12px; z-index: 5000;
        }
        #bulk-actions-bar button {
            color: white; border: none; padding: 15px 30px; border-radius: 30px; font-size: 1rem;
            font-weight: bold; cursor: pointer; transition: 0.2s;
        }
        #bulk-print-btn { background: var(--accent); box-shadow: 0 10px 20px rgba(52, 152, 219, 0.4); }
        #bulk-print-btn:hover { background: var(--dark-blue); transform: scale(1.05); }
        #bulk-delete-btn { background: var(--danger); box-shadow: 0 10px 20px rgba(231, 76, 60, 0.4); }
        #bulk-delete-btn:hover { background: #c0392b; transform: scale(1.05); }
        #modalHistory {z-index: 120000 !important;}

        /* ============================================================
           TUILE "CRÉER UN BON D'INTERVENTION" + ASSISTANT GUIDÉ (WIZARD)
        ============================================================ */
        /* Rangée SAS (gauche) + Créer un bon (droite), côte à côte */
        .top-row { display: flex; gap: 20px; align-items: stretch; margin-bottom: 20px; flex-wrap: wrap; }
        .top-row .sas-alert, .top-row .ot-create-tile { flex: 1 1 320px; max-width: none; margin: 0; }

        .ot-create-tile {
            display: flex; align-items: center; gap: 18px;
            background: linear-gradient(160deg, rgba(255,255,255,0.92), rgba(255,255,255,0.72));
            backdrop-filter: blur(14px) saturate(160%); -webkit-backdrop-filter: blur(14px) saturate(160%);
            border-radius: 15px; border: 1px solid rgba(255,255,255,0.6); border-left: 5px solid var(--brand-orange);
            padding: 20px 24px; margin-bottom: 20px; cursor: pointer;
            box-shadow: inset 0 1px 0 rgba(255,255,255,0.6), 0 12px 28px -6px rgba(243, 156, 18, 0.5);
            transition: transform 0.25s ease, box-shadow 0.25s ease;
        }
        .ot-create-tile:hover { transform: translateY(-4px); box-shadow: inset 0 1px 0 rgba(255,255,255,0.6), 0 18px 34px -8px rgba(243, 156, 18, 0.65); }
        .ot-create-tile-icon {
            flex-shrink: 0; width: 60px; height: 60px; border-radius: 50%;
            background: var(--soft-orange); color: var(--brand-orange);
            display: flex; align-items: center; justify-content: center; font-size: 1.6rem;
        }
        .ot-create-tile-text { flex: 1; min-width: 0; }
        .ot-create-tile-text h3 { margin: 0 0 4px 0; font-family: 'Caveat', cursive; font-size: 1.7rem; color: var(--primary); }
        .ot-create-tile-text p { margin: 0; font-size: 0.85rem; color: #475569; }
        .ot-create-tile-cta {
            flex-shrink: 0; display: flex; align-items: center; gap: 8px; padding: 12px 20px;
            background: var(--brand-orange); color: white; border-radius: 10px; font-size: 0.8rem; font-weight: 700;
            text-transform: uppercase; letter-spacing: 0.4px; white-space: nowrap;
        }

        /* --- Modale assistant (structure reprise de preventif.php) --- */
        /* max-height/margin relevés par rapport à .modal-content (90vh / 2%) : le contenu de l'étape 1
           a été agrandi (voir .wizard-content) pour laisser la place au menu déroulant "Intervenants
           prévus" une fois ouvert ; sans ce relèvement, c'est le plafond de hauteur de la fenêtre
           elle-même qui recommencerait à couper ce contenu agrandi. */
        /* display:flex column + overflow:hidden : le header et .wizard-nav restent fixes, seul
           .wizard-shell (ci-dessous) scrolle en interne — voir le commentaire sur .wizard-nav. */
        /* box-sizing:border-box : .modal-content est en content-box par défaut, donc max-height
           ignorait le padding (25px+20px) et laissait la fenêtre dépasser de ~45px à chaque fois. */
        .modal-content-wizard-ot { width: 980px; max-width: 96%; padding: 25px 32px 20px 32px; margin: 1.5vh auto !important; max-height: 97vh !important; box-sizing: border-box; display: flex; flex-direction: column; overflow: hidden; }
        /* flex:1 + min-height:0 + overflow-y:auto : c'est cette zone (sidebar + contenu de l'étape)
           qui scrolle désormais, pas plus .modal-content — ça permet aux boutons de .wizard-nav de
           rester fixes en bas de la fenêtre quel que soit la hauteur du contenu de l'étape affichée. */
        .wizard-shell { display: flex; gap: 24px; margin-top: 18px; align-items: stretch; flex: 1 1 auto; min-height: 0; overflow-y: auto; }
        .wizard-sidebar { width: 230px; flex: none; background: linear-gradient(165deg, #f8fafc, #eef2f7); border-radius: 14px; padding: 18px 14px; display: flex; flex-direction: column; }
        .wizard-sidebar-step { display: flex; align-items: flex-start; gap: 12px; padding: 10px 8px; border-radius: 10px; transition: 0.2s; }
        .wizard-sidebar-step.clickable { cursor: pointer; }
        .wizard-sidebar-step.clickable:hover { background: rgba(52,152,219,0.08); }
        .ss-icon { width: 34px; height: 34px; min-width: 34px; border-radius: 50%; background: #e2e8f0; color: #94a3b8; display: flex; align-items: center; justify-content: center; font-size: 0.9rem; transition: 0.3s; }
        .wizard-sidebar-step.active .ss-icon { background: var(--accent); color: #fff; box-shadow: 0 0 0 4px rgba(52,152,219,0.15); }
        .wizard-sidebar-step.done .ss-icon { background: var(--success); color: #fff; }
        .wizard-sidebar-step.done.step-incomplete .ss-icon { background: var(--brand-orange); }
        .ss-text strong { display: block; font-size: 0.8rem; color: #94a3b8; font-weight: 800; line-height: 1.3; }
        .ss-text span { font-size: 0.66rem; color: #cbd5e1; }
        .wizard-sidebar-step.active .ss-text strong { color: var(--primary); }
        .wizard-sidebar-step.done .ss-text strong { color: var(--success); }
        .wizard-sidebar-step.done.step-incomplete .ss-text strong { color: var(--brand-orange); }
        .wizard-sidebar-connector { width: 2px; height: 14px; background: #e2e8f0; margin-left: 25px; transition: 0.3s; }
        .wizard-sidebar-connector.done { background: var(--success); }

        .wizard-sidebar-progress-wrap { margin-top: auto; padding-top: 16px; }
        .wizard-mini-progress-bar { height: 6px; background: #e2e8f0; border-radius: 3px; overflow: hidden; }
        .wizard-mini-progress-fill { height: 100%; background: linear-gradient(90deg, var(--accent), var(--success)); transition: width 0.35s ease; width: 0%; }
        .wizard-mini-progress-label { font-size: 0.65rem; color: #94a3b8; font-weight: 700; margin-top: 7px; text-align: center; }

        .wizard-main { flex: 1; min-width: 0; display: flex; flex-direction: column; }
        /* 580px (au lieu de 320px) : le menu déroulant "Intervenants prévus" (étape 1) se positionne
           en absolu et ne pousse donc pas la hauteur naturelle de la fenêtre — sans cette réserve, la
           page doit scroller pour voir le bas de la liste des cases à cocher une fois ouverte. La liste
           elle-même n'a plus de hauteur limitée (voir #f-assign-tech-list) : tous les techniciens
           doivent être visibles d'un coup, sans scroll interne non plus. */
        .wizard-content { min-height: 580px; }
        .wizard-panel { display: none; animation: fadeInStep 0.25s ease; }
        .wizard-panel.active { display: block; }
        @keyframes fadeInStep { from { opacity: 0; transform: translateY(6px); } to { opacity: 1; transform: translateY(0); } }

        .wizard-panel-title { font-size: 1.15rem; font-weight: 800; color: var(--primary); display: flex; align-items: center; gap: 10px; margin-bottom: 4px; }
        .wizard-panel-desc { font-size: 0.8rem; color: #94a3b8; margin-bottom: 16px; }

        /* --- Bloc d'aide illustré, en haut de chaque étape --- */
        .wizard-step-help { display: flex; gap: 14px; align-items: center; background: #f7f9fb; border: 1px solid #e6e9ec; border-radius: 12px; padding: 12px 14px; margin-bottom: 18px; }
        .wizard-step-help img { width: 110px; height: 72px; object-fit: cover; object-position: top; border-radius: 8px; border: 1px solid #e2e8f0; flex-shrink: 0; background: #eef2f5; }
        .wizard-step-help-icon-fallback { width: 110px; height: 72px; border-radius: 8px; background: var(--soft-blue); color: var(--accent); display: flex; align-items: center; justify-content: center; font-size: 1.7rem; flex-shrink: 0; }
        .wizard-step-help-text { font-size: 0.8rem; color: #5a6b7a; line-height: 1.45; }
        .wizard-step-help-text b { color: var(--primary); }
        .wizard-smart-box { display: flex; gap: 12px; align-items: flex-start; background: #eef7ff; border: 1px solid #cfe4fb; border-radius: 12px; padding: 12px 14px; margin: 4px 0 16px; grid-column: 1 / -1; }
        .wizard-smart-box.is-warning { background: #fff8ec; border-color: #f5dcae; }
        .wizard-smart-box-icon { width: 32px; height: 32px; border-radius: 50%; background: var(--accent); color: #fff; display: flex; align-items: center; justify-content: center; flex-shrink: 0; font-size: 0.9rem; }
        .wizard-smart-box.is-warning .wizard-smart-box-icon { background: var(--brand-orange); }
        .wizard-smart-box-body { flex: 1; font-size: 0.8rem; color: #5a6b7a; line-height: 1.4; min-width: 0; }
        .wizard-smart-box-body b { color: var(--primary); }
        .wizard-smart-list { max-height: 220px; overflow-y: auto; margin-top: 4px; padding-right: 2px; }
        .wizard-smart-item { display: flex; align-items: center; gap: 8px; background: #fff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 6px 10px; margin-top: 8px; cursor: pointer; transition: border-color .15s; }
        .wizard-smart-item:hover { border-color: var(--accent); }
        .wizard-smart-item-desc { flex: 1; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; font-size: 0.78rem; color: var(--primary); }
        .wizard-smart-item-meta { font-size: 0.68rem; color: #94a3b8; flex-shrink: 0; white-space: nowrap; }

        .photo-picker { display: flex; flex-wrap: wrap; gap: 10px; align-items: flex-start; }
        .photo-add-btn { display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 4px; width: 84px; height: 84px; border: 2px dashed #cbd5e1; border-radius: 10px; color: var(--accent); font-size: 0.65rem; font-weight: 700; text-align: center; cursor: pointer; transition: border-color .15s, background .15s; flex-shrink: 0; }
        .photo-add-btn:hover { border-color: var(--accent); background: #eef7ff; }
        .photo-add-btn i { font-size: 1.3rem; }
        .photo-thumbs { display: flex; flex-wrap: wrap; gap: 10px; }
        .photo-thumb { position: relative; width: 84px; height: 84px; border-radius: 10px; overflow: hidden; border: 1px solid #e2e8f0; flex-shrink: 0; }
        .photo-thumb img { width: 100%; height: 100%; object-fit: cover; display: block; cursor: pointer; }
        .photo-thumb-remove { position: absolute; top: 3px; right: 3px; width: 20px; height: 20px; border-radius: 50%; background: rgba(15,23,42,0.65); color: #fff; border: none; display: flex; align-items: center; justify-content: center; cursor: pointer; font-size: 0.7rem; padding: 0; }
        .photo-thumb-remove:hover { background: var(--danger); }
        .photo-thumb-uploader { position: absolute; left: 0; right: 0; bottom: 0; background: rgba(15,23,42,0.55); color: #fff; font-size: 0.55rem; padding: 2px 4px; text-align: center; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }

        /* Hors de la zone scrollable (.wizard-shell) : reste toujours visible, voir .modal-content-wizard-ot. */
        .wizard-nav { display: flex; justify-content: space-between; align-items: center; margin-top: 20px; border-top: 1px solid #e2e8f0; padding-top: 18px; flex-shrink: 0; background: #fff; }
        .btn-wizard { border: none; font-weight: 700; border-radius: 8px; cursor: pointer; transition: 0.2s; font-size: 0.88rem; padding: 10px 22px; display: flex; align-items: center; gap: 8px; }
        .btn-wizard-prev { background: #f1f5f9; color: #64748b; }
        .btn-wizard-prev:hover { background: #e2e8f0; }
        .btn-wizard-next { background: var(--accent); color: #fff; }
        .btn-wizard-next:hover { background: #2980b9; }
        .btn-wizard-cancel { background: none; color: #94a3b8; font-weight: 600; }
        .btn-wizard-cancel:hover { color: var(--danger); }

        /* ============================================================
           LOCALISATION MACHINE — recherche + navigation par tuiles
        ============================================================ */
        .loc-search-field { grid-column: 1 / -1; }
        .loc-search-wrap { position: relative; width: 100%; }
        .loc-search-wrap .loc-search-icon { position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: #94a3b8; font-size: 0.9rem; pointer-events: none; }
        #loc-search-input { width: 100%; height: 42px; padding: 0 12px 0 36px; border: 2px solid #e2e8f0; border-radius: 8px; font-size: 0.9rem; font-family: inherit; box-sizing: border-box; transition: border-color 0.2s; }
        #loc-search-input:focus { outline: none; border-color: var(--accent); }
        .loc-search-results { position: absolute; z-index: 50; top: calc(100% + 4px); left: 0; right: 0; max-height: 320px; overflow-y: auto; background: #fff; border: 1px solid #e2e8f0; border-radius: 10px; box-shadow: 0 12px 28px rgba(15,23,42,0.15); display: none; }
        .loc-search-item { padding: 9px 14px; cursor: pointer; border-bottom: 1px solid #f1f5f9; }
        .loc-search-item:last-child { border-bottom: none; }
        .loc-search-item:hover, .loc-search-item.is-active { background: var(--soft-blue); }
        .loc-search-item-name { font-weight: 700; font-size: 0.85rem; color: var(--primary); }
        .loc-search-item-path { font-size: 0.72rem; color: #94a3b8; margin-top: 2px; }
        .loc-search-empty { padding: 14px; text-align: center; color: #94a3b8; font-size: 0.82rem; }

        .loc-breadcrumb { display: flex; flex-wrap: wrap; align-items: center; gap: 6px; margin: 14px 0 10px; }
        .loc-crumb { display: inline-flex; align-items: center; gap: 6px; padding: 6px 12px; border-radius: 20px; background: #f1f5f9; color: #64748b; font-size: 0.78rem; font-weight: 700; cursor: pointer; border: none; font-family: inherit; transition: 0.15s; }
        .loc-crumb:hover { background: #e2e8f0; }
        .loc-crumb.is-current { background: var(--soft-blue); color: var(--accent); cursor: default; }
        .loc-crumb.is-empty { background: none; color: #cbd5e1; font-weight: 600; cursor: default; }
        .loc-crumb-sep { color: #cbd5e1; font-size: 0.7rem; }

        .loc-tiles { display: grid; grid-template-columns: repeat(auto-fill, minmax(150px, 1fr)); gap: 10px; grid-column: 1 / -1; }
        .loc-tile { display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 8px; text-align: center; background: #fff; border: 2px solid #e2e8f0; border-radius: 12px; padding: 16px 10px; cursor: pointer; font-family: inherit; transition: 0.15s; }
        .loc-tile:hover { border-color: var(--accent); background: var(--soft-blue); transform: translateY(-2px); }
        .loc-tile-icon { width: 38px; height: 38px; border-radius: 10px; background: var(--soft-blue); color: var(--accent); display: flex; align-items: center; justify-content: center; font-size: 1.1rem; }
        .loc-tile-label { font-size: 0.8rem; font-weight: 700; color: var(--primary); line-height: 1.3; word-break: break-word; }
        .loc-tile-count { font-size: 0.68rem; color: #94a3b8; font-weight: 600; }
        .loc-empty-msg { grid-column: 1 / -1; padding: 20px; text-align: center; color: #94a3b8; font-size: 0.85rem; background: #f8fafc; border-radius: 10px; }

        .loc-done-card { grid-column: 1 / -1; display: flex; align-items: center; justify-content: space-between; gap: 14px; background: var(--soft-green); border: 2px solid #b8ecd9; border-radius: 12px; padding: 16px; flex-wrap: wrap; }
        .loc-done-info { display: flex; align-items: center; gap: 12px; }
        .loc-done-icon { width: 42px; height: 42px; border-radius: 10px; background: var(--brand-green); color: #fff; display: flex; align-items: center; justify-content: center; font-size: 1.2rem; flex-shrink: 0; }
        .loc-done-name { font-weight: 800; color: var(--primary); font-size: 0.95rem; }
        .loc-done-path { font-size: 0.75rem; color: #5a8a76; margin-top: 2px; }
        .loc-done-change { background: #fff; border: 1px solid #cbd5e1; color: var(--primary); font-weight: 700; font-size: 0.78rem; padding: 8px 14px; border-radius: 8px; cursor: pointer; font-family: inherit; }
        .loc-done-change:hover { border-color: var(--accent); color: var(--accent); }

        /* Récapitulatif — même style que demande.php (icône + libellé/valeur empilés + lien Modifier)
           Classes préfixées "ot-recap-" pour ne pas entrer en collision avec .recap-item des vignettes KPI. */
        .review-card { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px; padding: 4px 14px; margin-bottom: 14px; margin-top: 4px; }
        .ot-recap-item { display:flex; gap: 10px; align-items:flex-start; padding: 10px 0; border-bottom: 1px dashed #e2e8f0; }
        .ot-recap-item:last-child { border-bottom: none; }
        .ot-recap-item i { color: var(--accent); width: 18px; text-align:center; margin-top: 2px; }
        .ot-recap-label { font-size: 0.65rem; font-weight: 700; text-transform: uppercase; color: #94a3b8; }
        .ot-recap-val { font-size: 0.88rem; color: var(--primary); font-weight: 600; word-break: break-word; }
        .ot-recap-edit { margin-left: auto; font-size: 0.68rem; color: var(--accent); cursor: pointer; font-weight: 700; white-space: nowrap; }
        .ot-recap-edit:hover { text-decoration: underline; }

        /* ============================================================
           HISTORIQUE GLOBAL — recherche unique + puces actives
        ============================================================ */
        .global-search-bar { display: flex; align-items: center; gap: 10px; background: white; border: 2px solid #e2e8f0; border-radius: 12px; padding: 4px 6px 4px 16px; margin-bottom: 14px; transition: border-color 0.2s; }
        .global-search-bar:focus-within { border-color: var(--accent); }
        .global-search-bar i.fa-magnifying-glass { color: #94a3b8; font-size: 1rem; }
        .global-search-bar input { flex: 1; border: none; outline: none; font-size: 0.95rem; padding: 10px 4px; font-family: inherit; background: transparent; }
        .global-search-bar .btn-clear-search { background: #f1f5f9; color: #64748b; border: none; border-radius: 8px; padding: 8px 14px; font-size: 0.8rem; font-weight: 700; cursor: pointer; }
        .global-search-bar .btn-clear-search:hover { background: #e2e8f0; }
        /* La couleur par badge (actif ou non) vient désormais de --tile-color, posée en style
           inline sur chaque vignette (voir .recap-item / .recap-item.chip-active plus haut) —
           plus besoin d'une règle !important par data-chip-key. */

        /* ============================================================
           BULLES D'AIDE — contenu enrichi (ouvrirAide)
        ============================================================ */
        .aide-hero { display: flex; align-items: center; gap: 14px; padding-bottom: 16px; margin-bottom: 16px; border-bottom: 1px solid var(--border-color); }
        .aide-hero-icon { width: 50px; height: 50px; border-radius: 14px; display: flex; align-items: center; justify-content: center; font-size: 1.35rem; flex-shrink: 0; }
        .aide-hero-text h3 { margin: 0; font-size: 1.08rem; color: var(--primary); font-weight: 800; }
        .aide-hero-text p { margin: 3px 0 0; font-size: 0.8rem; color: var(--text-light); }
        .aide-body p { font-size: 0.9rem; color: var(--text-main); line-height: 1.6; margin: 0 0 10px; }
        .aide-list { list-style: none; margin: 10px 0; padding: 0; display: flex; flex-direction: column; gap: 9px; }
        .aide-list li { display: flex; align-items: flex-start; gap: 10px; font-size: 0.87rem; color: var(--text-main); line-height: 1.5; }
        .aide-list li i { margin-top: 3px; flex-shrink: 0; width: 16px; text-align: center; }
        .aide-callout { display: flex; gap: 10px; padding: 12px 14px; border-radius: 10px; font-size: 0.86rem; line-height: 1.55; margin-top: 12px; }
        .aide-callout i { flex-shrink: 0; margin-top: 2px; }
        .aide-callout-tip { background: var(--soft-green); color: #1f6b3a; }
        .aide-callout-warn { background: #fdeeec; color: #9a3226; }
        .aide-callout-info { background: var(--soft-blue); color: #1c5a85; }
        .icon-help { transition: transform 0.15s; }

/* ==========================================================================
   ?? RESPONSIVE DESIGN (TABLETTES & SMARTPHONES)
   ========================================================================== */

/* --- POUR LES TABLETTES (Écrans < 1024px) --- */
@media screen and (max-width: 1024px) {
    /* 1. Syndrome des "gros doigts" : Hauteur minimum pour tapoter facilement */
    input, select, button, .col-filter {
        min-height: 44px !important; 
        font-size: 0.95rem !important;
    }
    
    /* 2. On grossit les badges et petits boutons */
    .btn-pointer, .status-btn, .status-badge {
        padding: 10px 15px !important;
        font-size: 0.85rem !important;
    }

    /* 3. On libère la largeur des modales (on casse les 750px fixes) */
    #modalPointage > div {
        width: 95% !important;
        margin: 5% auto !important;
        max-height: 90vh;
        overflow-y: auto;
    }

    /* 4. Barre d'onglets du haut plus confortables */
    .tab-item {
        padding: 15px 10px;
        font-size: 0.9rem;
        flex: 1; /* Prend toute la largeur équitablement */
        justify-content: center;
    }

    /* 5. Assistant de création : la sidebar passe au-dessus du contenu, en rangée horizontale de puces */
    .wizard-shell {
        flex-direction: column;
    }
    .wizard-sidebar {
        width: 100%;
        flex-direction: row;
        flex-wrap: wrap;
        gap: 6px;
        padding: 12px;
    }
    .wizard-sidebar-connector { display: none; }
    .wizard-sidebar-step { flex: 1; min-width: 130px; }
    .wizard-sidebar-progress-wrap { display: none; }
    .global-search-bar input { font-size: 1rem; }

    /* 6. Les vignettes de filtres (recap-row) sont en nowrap sur desktop pour rester alignées ;
       sur tablette elles doivent pouvoir passer à la ligne, sinon toute la page défile horizontalement.
       .recap-rows-wrap est en flex:none (flex-shrink:0) : sans min-width:0/flex-basis:100%, sa taille
       "max-content" reste calculée comme si ses lignes ne passaient jamais à la ligne, donc il faut
       aussi l'autoriser à occuper toute la largeur pour que le wrap ci-dessus ait vraiment de l'effet. */
    .recap-row { flex-wrap: wrap; }
    .recap-rows-wrap { flex: 1 1 100%; min-width: 0; }
}

/* --- POUR LES SMARTPHONES (Écrans < 768px) --- */
@media screen and (max-width: 768px) {
    /* 1. On empile TOUS les formulaires de haut en bas (1 seule colonne) */
    .saisie-grid, .modal-form-grid {
        grid-template-columns: 1fr !important;
    }

    /* 2. On empile les champs "Technicien / Heures / Date" dans la modale Pointage */
    #modalPointage [style*="display: flex; gap: 12px;"] {
        flex-direction: column !important;
        align-items: stretch !important;
    }

    /* 3. On réduit la taille du titre en haut pour gagner de la place */
    .header-title {
        font-size: 1.2rem;
    }

    /* 4. On agrandit les polices du tableau pour ne pas s'arracher les yeux */
    th, td {
        font-size: 0.9rem !important;
    }
    
    /* 5. Le bouton "+ TEMPS" devient plus large pour être tapoté au pouce */
    .btn-pointer {
        width: 100%;
        margin-top: 8px;
    }

    /* 6. Tuile "Créer un bon d'intervention" et assistant : on empile en colonne.
       box-sizing:border-box impératif ici : .ot-create-tile-cta est en content-box par défaut, donc
       width:100% + padding:12px 20px ajoutait le padding EN PLUS des 100% (au lieu de le compter dedans)
       et le bouton débordait de sa tuile parente. */
    .ot-create-tile { flex-wrap: wrap; }
    .ot-create-tile-cta { width: 100%; justify-content: center; box-sizing: border-box; }
    .wizard-step-help { flex-direction: column; align-items: stretch; }
    .wizard-step-help img, .wizard-step-help-icon-fallback { width: 100%; height: 90px; }
    .wizard-nav { flex-direction: column-reverse; gap: 10px; align-items: stretch; }
    .wizard-nav > div { width: 100%; justify-content: stretch; }
    .wizard-nav .btn-wizard { flex: 1; justify-content: center; }
}

/* --- FICHE TÂCHE : carte compacte imprimable, à punaiser sur le tableau d'atelier
   pour attribuer un OT à un technicien. Masquée à l'écran, seule visible à l'impression
   (le reste de la page est masqué via visibility, pas display, pour ne pas casser la mise
   en page des modales déjà pilotées en display:none par le JS). */
#fiche-tache-print { display: none; }
@media print {
    /* display:none (pas visibility:hidden) : on retire vraiment le reste de la page du flux,
       sinon tout le contenu de la SPA (historique, modales...) reste présent hors écran et
       génère une 2e page blanche après la fiche. #fiche-tache-print est déplacé en direct
       enfant de <body> par le JS juste avant impression pour que ce sélecteur le trouve
       toujours, quel que soit son emplacement d'origine dans le HTML. */
    body > *:not(#fiche-tache-print) { display: none !important; }
    #fiche-tache-print { display: block; }
}
.fiche-tache-card {
    max-width: 480px;
    margin: 40px auto;
    background: #fff;
    border: 3px solid var(--brand-orange);
    border-radius: 10px;
    font-family: Arial, Helvetica, sans-serif;
    color: #2c3e50;
    overflow: hidden;
}
.fiche-tache-card.urgent { border-color: var(--danger); }
.fiche-tache-header {
    background: var(--brand-orange);
    color: white;
    padding: 16px 24px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
}
.fiche-tache-header.urgent { background: var(--danger); }
.fiche-tache-num { font-size: 1.6rem; font-weight: bold; }
.fiche-tache-prio-urgent { font-size: 0.9rem; font-weight: bold; background: rgba(255,255,255,0.25); padding: 4px 10px; border-radius: 20px; white-space: nowrap; }
.fiche-tache-body { padding: 20px 24px 0 24px; }
.fiche-tache-type-badge { display: inline-block; padding: 3px 10px; border-radius: 12px; color: white; font-size: 0.7rem; font-weight: 700; text-transform: uppercase; margin-bottom: 14px; }
.fiche-tache-row { margin: 12px 0; font-size: 1.05rem; padding-left: 10px; border-left: 4px solid #e2e8f0; }
.fiche-tache-row b { display: block; font-size: 0.72rem; text-transform: uppercase; letter-spacing: 0.03em; color: #64748b; margin-bottom: 2px; }
.fiche-tache-row.c-machine { border-left-color: var(--accent); }
.fiche-tache-row.c-loc { border-left-color: var(--dark-blue); }
.fiche-tache-row.c-desc { border-left-color: var(--primary); }
.fiche-tache-row.c-demandeur { border-left-color: var(--brand-orange); }
.fiche-tache-row.c-date { border-left-color: #94a3b8; }
.fiche-tache-row.c-tech { border-left-color: var(--brand-green); }
.fiche-tache-carnet {
    margin: 8px 24px 0 24px;
    background: #eff6fc;
    border: 1px solid #d6eaf8;
    border-left: 4px solid var(--accent);
    border-radius: 6px;
    padding: 10px 12px;
}
.fiche-tache-carnet b { display: block; font-size: 0.72rem; text-transform: uppercase; color: var(--accent); margin-bottom: 4px; }
.fiche-tache-carnet .content { font-size: 0.92rem; white-space: pre-wrap; }

/* --- Impression groupée (sélection via les cases à cocher + bouton "Imprimer la sélection") :
   plutôt que de forcer une grille CSS à une hauteur de page exacte (mal géré par les moteurs
   d'impression - Chrome fragmente très mal un display:grid entre deux pages, et ça produisait
   une 2e page vide même quand le calcul de hauteur disait que le contenu tenait largement),
   on laisse le navigateur paginer NATURELLEMENT : chaque fiche est un bloc "inline-block" de
   ~47% de large (2 par ligne), avec juste "ne jamais couper une fiche entre deux pages". C'est
   ce que les moteurs de pagination d'impression savent faire de façon fiable. */
@media print {
    @page { margin: 10mm; }
    /* body a "min-height:100vh" + "padding-top:98px" pour le header/fond d'écran de l'appli.
       Cette hauteur minimale reste appliquée même à l'impression, INDÉPENDAMMENT du contenu
       réellement affiché (tout le reste étant juste display:none, pas retiré du calcul de
       min-height) — sur un écran haut, ça peut à elle seule dépasser une page A4 et forcer
       une 2e page entièrement blanche même quand la fiche imprimée tient largement sur la 1re. */
    body { min-height: 0 !important; padding-top: 0 !important; }
}
.fiche-tache-card.compact {
    display: inline-block;
    vertical-align: top;
    width: 46%;
    height: 95mm;
    margin: 0 0.5% 8mm 0.5%;
    overflow: hidden;
    break-inside: avoid;
    page-break-inside: avoid;
    font-size: 0.82rem;
}
.fiche-tache-card.compact .fiche-tache-header { padding: 8px 14px; }
.fiche-tache-card.compact .fiche-tache-num { font-size: 1.1rem; }
.fiche-tache-card.compact .fiche-tache-prio-urgent { font-size: 0.7rem; padding: 3px 8px; }
.fiche-tache-card.compact .fiche-tache-body { padding: 10px 14px 0 14px; }
.fiche-tache-card.compact .fiche-tache-type-badge { font-size: 0.6rem; padding: 2px 8px; margin-bottom: 8px; }
.fiche-tache-card.compact .fiche-tache-row { margin: 6px 0; font-size: 0.8rem; padding-left: 8px; }
.fiche-tache-card.compact .fiche-tache-row b { font-size: 0.6rem; }
.fiche-tache-card.compact .fiche-tache-row.c-desc { max-height: 40px; overflow: hidden; }
.fiche-tache-card.compact .fiche-tache-carnet { margin: 6px 14px 0 14px; padding: 6px 8px; }
.fiche-tache-card.compact .fiche-tache-carnet b { font-size: 0.58rem; }
.fiche-tache-card.compact .fiche-tache-carnet .content { font-size: 0.72rem; max-height: 34px; overflow: hidden; }

    </style>
<?php include 'pwa_head.php'; ?>
</head>
<body onclick="checkCloseSidebar(event)">

<?php $breadcrumb_label = t('maint.breadcrumb'); include 'navbar.php'; ?>

<div class="container">
    <div id="statsArea" class="tech-stats"></div>

    <div class="top-row">
        <div id="bandeau-sirene-sas" onclick="ouvrirSasValidation()" class="sas-alert"
         style="display: <?php echo ($nb_en_attente > 0) ? 'flex' : 'none'; ?>;">
            <div class="sas-alert-icon"><i class="fa-solid fa-bell"></i></div>
            <div class="sas-alert-text">
                <span class="sas-alert-count" id="compteur-sirene-sas"><?php echo $nb_en_attente; ?></span> <b><?php echo htmlspecialchars(t('maint.sas_banner_label')); ?></b><br>
                <?php echo htmlspecialchars(t('maint.sas_banner_desc')); ?>
            </div>
            <div class="sas-alert-cta"><?php echo htmlspecialchars(t('maint.sas_banner_cta')); ?> <i class="fa-solid fa-arrow-right"></i></div>
        </div>

        <div class="ot-create-tile" onclick="ouvrirWizardOT()">
            <div class="ot-create-tile-icon"><i class="fa-solid fa-pen-to-square"></i></div>
            <div class="ot-create-tile-text">
                <h3><?php echo htmlspecialchars(t('maint.create_tile_title')); ?></h3>
                <p><?php echo htmlspecialchars(t('maint.create_tile_desc')); ?></p>
            </div>
            <div class="ot-create-tile-cta"><?php echo htmlspecialchars(t('maint.create_tile_cta')); ?> <i class="fa-solid fa-arrow-right"></i></div>
        </div>
    </div>

    <div class="kpi-tiles-section">
        <div id="globalRecap" class="recap-bar"></div>
    </div>

    <div class="card" style="border-left: 5px solid var(--brand-green);">
        <div class="card-title">
    <span><i class="fa-solid fa-clock-rotate-left"></i> <?php echo htmlspecialchars(t('maint.histo_title')); ?></span>
    <div class="histo-date-filter" style="display: flex; align-items: center; flex-wrap: wrap; gap: 10px; font-size: 0.85rem; font-family: 'Segoe UI', sans-serif;">
        <label style="color: var(--primary); font-weight: bold;"><?php echo htmlspecialchars(t('maint.date_from')); ?></label>
        <input type="date" id="filter-date-debut" class="select-periode" onchange="loadData()" style="height: 32px;">
        <label style="color: var(--primary); font-weight: bold;"><?php echo htmlspecialchars(t('maint.date_to')); ?></label>
        <input type="date" id="filter-date-fin" class="select-periode" onchange="loadData()" style="height: 32px;">
        <button onclick="document.getElementById('filter-date-debut').value=''; document.getElementById('filter-date-fin').value=''; loadData();" class="btn-pointer" style="margin: 0; height: 32px; padding: 0 12px;" title="<?php echo htmlspecialchars(t('maint.reset_dates_tooltip')); ?>"><i class="fa-solid fa-rotate-right"></i></button>
    </div>
</div>
        <div class="global-search-bar">
            <i class="fa-solid fa-magnifying-glass"></i>
            <input type="text" id="global-search-input" placeholder="<?php echo htmlspecialchars(t('maint.global_search_placeholder')); ?>" oninput="rechercheGlobale()">
            <button class="btn-clear-search" onclick="document.getElementById('global-search-input').value=''; rechercheGlobale();" title="<?php echo htmlspecialchars(t('maint.clear_search_tooltip')); ?>"><i class="fa-solid fa-xmark"></i></button>
        </div>

        <!-- Filtres par colonne (N° BI, date, localisation, description, technicien, statut, type, casse) :
             auparavant dans la 1ère ligne du <thead>, donc invisibles dès que le tableau est masqué
             (vue "cartes" tablette/téléphone, voir .bi-cards-grid). Sortis ici pour rester utilisables
             dans les deux vues — multiFilter() les lit toujours via la classe .col-filter, dans le même
             ordre qu'avant, donc aucun changement côté JS n'était nécessaire pour le filtrage du tableau ;
             seul le filtrage des cartes (voir filtrerCartesHistorique) est nouveau. -->
        <div class="histo-col-filters">
            <div class="hcf-group"><label><?php echo htmlspecialchars(t('maint.col_bi')); ?></label><input type="text" class="col-filter" placeholder="<?php echo htmlspecialchars(t('maint.col_bi_placeholder')); ?>" onkeyup="multiFilter()"></div>
            <div class="hcf-group"><label><?php echo htmlspecialchars(t('maint.col_date')); ?></label><input type="date" class="col-filter" onchange="multiFilter()"></div>
            <div class="hcf-group"><label><?php echo htmlspecialchars(t('maint.col_loc')); ?></label><input type="text" class="col-filter" placeholder="<?php echo htmlspecialchars(t('maint.col_loc_placeholder')); ?>" onkeyup="multiFilter()"></div>
            <div class="hcf-group"><label><?php echo htmlspecialchars(t('maint.col_desc')); ?></label><input type="text" class="col-filter" placeholder="<?php echo htmlspecialchars(t('maint.col_desc_placeholder')); ?>" onkeyup="multiFilter()"></div>
            <div class="hcf-group"><label><?php echo htmlspecialchars(t('maint.col_tech')); ?></label><select class="col-filter" id="filter-tech" onchange="multiFilter()"><option value=""><?php echo htmlspecialchars(t('maint.col_all')); ?></option></select></div>
            <div class="hcf-group"><label><?php echo htmlspecialchars(t('maint.col_statut')); ?></label><select id="filter-statut" class="col-filter" onchange="multiFilter()"><option value=""><?php echo htmlspecialchars(t('maint.col_all')); ?></option><option value="MESSAGE_NON_LU" style="font-weight:bold; color:var(--danger);"><?php echo htmlspecialchars(t('maint.filter_unread_msgs')); ?></option><option value="<?php echo htmlspecialchars($LIBELLES_WORKFLOW['urgent']['label']); ?>"><?php echo htmlspecialchars($LIBELLES_WORKFLOW['urgent']['label']); ?></option><option value="<?php echo htmlspecialchars($LIBELLES_WORKFLOW['afaire']['label']); ?>"><?php echo htmlspecialchars($LIBELLES_WORKFLOW['afaire']['label']); ?></option><option value="<?php echo htmlspecialchars($LIBELLES_WORKFLOW['encours']['label']); ?>"><?php echo htmlspecialchars($LIBELLES_WORKFLOW['encours']['label']); ?></option><option value="<?php echo htmlspecialchars($LIBELLES_WORKFLOW['termine']['label']); ?>"><?php echo htmlspecialchars($LIBELLES_WORKFLOW['termine']['label']); ?></option></select></div>
            <div class="hcf-group"><label><?php echo htmlspecialchars(t('maint.col_type')); ?></label><select id="filter-type" class="col-filter" onchange="multiFilter()"><option value=""><?php echo htmlspecialchars(t('maint.col_all')); ?></option><option value="Curatif"><?php echo htmlspecialchars(t('maint.type_curatif')); ?></option><option value="Préventif"><?php echo htmlspecialchars(t('maint.type_preventif')); ?></option><option value="Chantier"><?php echo htmlspecialchars(t('maint.type_chantier')); ?></option></select></div>
            <div class="hcf-group"><label><?php echo htmlspecialchars(t('maint.col_casse')); ?></label><select id="filter-casse" class="col-filter" onchange="multiFilter()"><option value=""><?php echo htmlspecialchars(t('maint.col_all')); ?></option><option value="CASSE"><?php echo htmlspecialchars(t('maint.casse_yes')); ?></option><option value="-"><?php echo htmlspecialchars(t('maint.casse_no')); ?></option></select></div>
        </div>

        <div class="histo-limit-bar">
            <span id="histoLimitLabel" class="histo-limit-label"></span>
            <div class="histo-limit-buttons">
                <button id="histoLimitMinus" onclick="changeHistoriqueLimit(-1)" title="<?php echo htmlspecialchars(t('maint.limit_minus_tooltip')); ?>"><i class="fa-solid fa-chevron-left"></i></button>
                <button id="histoLimitPlus" onclick="changeHistoriqueLimit(1)" title="<?php echo htmlspecialchars(t('maint.limit_plus_tooltip')); ?>"><i class="fa-solid fa-chevron-right"></i></button>
            </div>
        </div>
        <div class="historique-table-scroll">
            <table class="task-table">
                <!-- Localisation & Machine et Description sont volontairement laissées sans largeur :
                     en table-layout:fixed, les colonnes sans width se partagent le reste à parts égales,
                     ce qui les garde toujours strictement identiques quelle que soit la taille de l'écran. -->
                <colgroup>
                    <?php if($is_admin): ?><col style="width: 30px;"><?php endif; ?>
                    <col style="width: 88px;">
                    <col style="width: 92px;">
                    <col>
                    <col>
                    <col style="width: 130px;">
                    <col style="width: 90px;">
                    <col style="width: 118px;">
                    <col style="width: 88px;">
                    <col style="width: 56px;">
                    <col style="width: 104px;">
                </colgroup>
                <thead>
    <tr>
        <?php if($is_admin): ?><th class="tc-tight"><?php echo htmlspecialchars(t('maint.th_select')); ?> <input type="checkbox" id="check-all" onclick="toggleAllChecks(this)"></th><?php endif; ?>
        <th class="tc-tight gap-r"><?php echo htmlspecialchars(t('maint.col_bi')); ?></th>
        <th class="tc-tight gap-r"><?php echo htmlspecialchars(t('maint.col_date')); ?></th>
        <th><?php echo htmlspecialchars(t('maint.th_loc_machine')); ?></th>
        <th><?php echo htmlspecialchars(t('maint.col_desc')); ?></th>
        <th class="gap-r"><?php echo htmlspecialchars(t('maint.th_intervenants')); ?></th>
        <th class="tc-tight gap-r"><?php echo htmlspecialchars(t('maint.th_duree')); ?></th>
        <th class="gap-r"><?php echo htmlspecialchars(t('maint.col_statut')); ?></th>
        <th class="tc-tight"><?php echo htmlspecialchars(t('maint.col_type')); ?></th>
        <th class="tc-tight"><?php echo htmlspecialchars(t('maint.col_casse')); ?></th>
        <th class="tc-tight"><?php echo htmlspecialchars(t('maint.th_actions')); ?></th>
    </tr>
</thead>
                <tbody id="tableBody"></tbody>
            </table>
        </div>
        <div id="historiqueCards" class="bi-cards-grid"></div>
    </div>
</div>

<div id="modalPointage" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:100010; backdrop-filter: blur(2px); font-family: inherit;">
    <div style="background:white; width:750px; margin:20px auto; padding:0; border-radius:16px; position:relative; box-shadow: 0 15px 35px rgba(0,0,0,0.2); border: 1px solid #eee; box-sizing: border-box; display: flex; flex-direction: column; overflow: hidden;">
        
        <div id="pointage-title"></div>

        <div style="padding: 25px;">
            <input type="hidden" id="pointage-task-id">
            
            <div style="background: #fff9f0; padding: 18px; border-radius: 12px; border: 1px solid #ffecce; margin-bottom: 25px; box-sizing: border-box;">
                <div style="font-weight: 500; color: #d35400; margin-bottom: 15px; font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.5px;">
                    <i class="fa-solid fa-user-plus"></i> <?php echo htmlspecialchars(t('maint.ptg_new_pointage')); ?>
                </div>

                <div style="display:grid; grid-template-columns: 1.5fr 1fr; gap:15px; margin-bottom:15px;">
                    <div style="min-width: 0;">
                        <label style="display:block; font-size:0.75rem; color: #666; margin-bottom:5px;"><?php echo htmlspecialchars(t('maint.ptg_technicien')); ?></label>
                        <select id="ptg-tech" style="width:100%; padding:10px; border-radius:8px; border:1px solid #ddd; background: white; box-sizing: border-box; font-family: inherit;"></select>
                    </div>
                    <div style="min-width: 0;">
                        <label style="display:block; font-size:0.75rem; color: #666; margin-bottom:5px;"><?php echo htmlspecialchars(t('maint.ptg_heures')); ?></label>
                        <input type="number" id="ptg-hours" step="0.25" placeholder="ex: 1.5" style="width:100%; padding:10px; border-radius:8px; border:1px solid #ddd; background: white; box-sizing: border-box; font-family: inherit;">
                    </div>
                </div>

                <div style="margin-bottom:15px;">
                    <label style="display:block; font-size:0.75rem; color: #666; margin-bottom:5px;"><?php echo htmlspecialchars(t('maint.ptg_date_interv')); ?></label>
                    <input type="date" id="ptg-date" value="<?php echo date('Y-m-d'); ?>" style="width:100%; padding:10px; border-radius:8px; border:1px solid #ddd; background: white; box-sizing: border-box; font-family: inherit;">
                </div>

                <button onclick="ajouterPointage()" style="width:100%; background:#3498db; color:white; border:none; padding:12px; border-radius:8px; cursor:pointer; font-weight: 500; font-family: inherit; box-shadow: 0 4px 0 #2980b9;">
                    <i class="fa-solid fa-check"></i> <?php echo htmlspecialchars(t('maint.ptg_valider')); ?>
                </button>
            </div>

            <div style="font-weight: 500; color: var(--accent); margin-bottom: 10px; font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.5px;">
                <i class="fa-solid fa-list-check"></i> <?php echo htmlspecialchars(t('maint.ptg_interventions_enreg')); ?>
            </div>
            <div id="pointageList" style="min-height:200px; max-height:300px; overflow-y:auto; background: #fcfcfc; border-radius: 12px; border: 1px solid #eee; padding: 10px; box-sizing: border-box;"></div>

            <div style="text-align:right; margin-top:25px;">
                <button onclick="document.getElementById('modalPointage').style.display='none'" style="padding:10px 20px; border-radius:8px; border:1px solid #ccc; cursor:pointer; background:#f5f5f5; color:#666; font-family: inherit;"><?php echo htmlspecialchars(t('maint.close')); ?></button>
            </div>
        </div>
    </div>
</div>
<div id="modalEditPointage" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.6); z-index:400000; backdrop-filter: blur(2px); font-family: inherit; align-items:center; justify-content:center;">
    <div style="background:white; width:320px; padding:25px; border-radius:16px; box-shadow: 0 15px 35px rgba(0,0,0,0.2); text-align:center; border: 1px solid #eee;">
        
        <div style="background:#f0f7ff; width:50px; height:50px; border-radius:50%; display:flex; align-items:center; justify-content:center; margin:0 auto 15px auto; color:var(--accent); font-size:1.5rem;">
            <i class="fa-solid fa-pen-to-square"></i>
        </div>
        
        <h3 style="margin:0 0 15px 0; color:var(--primary); font-size:1.1rem; font-weight:600;"><?php echo htmlspecialchars(t('maint.edit_ptg_title')); ?></h3>
        <p style="font-size:0.8rem; color:#666; margin-bottom:15px;"><?php echo htmlspecialchars(t('maint.edit_ptg_desc')); ?></p>

        <input type="hidden" id="edit-ptg-id">
        <input type="hidden" id="edit-ptg-task-id">
        <input type="number" id="edit-ptg-hours" step="0.25" placeholder="ex: 1.5" style="width:100%; padding:12px; border-radius:8px; border:2px solid #3498db; margin-bottom:20px; font-family: inherit; box-sizing:border-box; text-align:center; font-size:1.2rem; font-weight:bold; color:var(--primary); outline:none;">

        <div style="display:flex; gap:10px; justify-content:center;">
            <button onclick="document.getElementById('modalEditPointage').style.display='none'" style="flex:1; padding:10px; border-radius:8px; border:1px solid #ccc; background:#f5f5f5; cursor:pointer; font-family:inherit; color:#666; font-weight:500;"><?php echo htmlspecialchars(t('maint.cancel')); ?></button>
            <button onclick="validerModification()" style="flex:1; padding:10px; border-radius:8px; border:none; background:#3498db; color:white; cursor:pointer; font-weight:bold; font-family:inherit; box-shadow: 0 4px 0 #2980b9;"><?php echo htmlspecialchars(t('maint.save')); ?></button>
        </div>
    </div>
</div>
<div id="modalConfirmDelete" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.6); z-index:300000; backdrop-filter: blur(2px); font-family: inherit; align-items:center; justify-content:center;">
    <div style="background:white; width:320px; padding:25px; border-radius:16px; box-shadow: 0 15px 35px rgba(0,0,0,0.2); text-align:center; border: 1px solid #eee;">
        
        <div style="background:#fff0f0; width:50px; height:50px; border-radius:50%; display:flex; align-items:center; justify-content:center; margin:0 auto 15px auto; color:#e74c3c; font-size:1.5rem;">
            <i class="fa-solid fa-trash-can"></i>
        </div>
        
        <h3 style="margin:0 0 10px 0; color:var(--primary); font-size:1.1rem; font-weight:600;"><?php echo htmlspecialchars(t('maint.del_ptg_title')); ?></h3>
        <p style="font-size:0.85rem; color:#666; margin-bottom:20px;"><?php echo htmlspecialchars(t('maint.del_ptg_desc')); ?></p>

        <input type="hidden" id="delete-ptg-id">
        <input type="hidden" id="delete-ptg-task-id">

        <div style="display:flex; gap:10px; justify-content:center;">
            <button onclick="document.getElementById('modalConfirmDelete').style.display='none'" style="flex:1; padding:10px; border-radius:8px; border:1px solid #ccc; background:#f5f5f5; cursor:pointer; font-family:inherit; color:#666; font-weight:500;"><?php echo htmlspecialchars(t('maint.cancel')); ?></button>
            <button onclick="validerSuppression()" style="flex:1; padding:10px; border-radius:8px; border:none; background:#e74c3c; color:white; cursor:pointer; font-weight:bold; font-family:inherit; box-shadow: 0 4px 0 #c0392b;"><?php echo htmlspecialchars(t('maint.yes_delete')); ?></button>
        </div>
    </div>
</div>
<div id="bulk-actions-bar">
    <button id="bulk-print-btn" onclick="imprimerSelectionMultiple()"><i class="fa-solid fa-print"></i> <?php echo htmlspecialchars(t('maint.bulk_print_btn')); ?></button>
    <button id="bulk-delete-btn" onclick="deleteSelected()"><i class="fa-solid fa-trash"></i> <?php echo htmlspecialchars(t('maint.bulk_delete_btn')); ?></button>
</div>

<div id="modalWizardOT" class="modal">
    <div class="modal-content modal-content-wizard-ot">

        <div style="display: flex; justify-content: space-between; align-items: center;">
            <h2 id="wizardTitleOT" style="font-family:'Caveat', cursive; font-size:2rem; color:var(--primary); margin:0; display:flex; align-items:center; gap:10px;">
                <i class="fa-solid fa-pen-to-square" style="color:var(--brand-orange);"></i> <?php echo htmlspecialchars(t('maint.create_tile_title')); ?>
            </h2>
            <button onclick="closeWizardOT()" style="background:none; border:none; font-size:2rem; cursor:pointer; color:#94a3b8;">&times;</button>
        </div>

        <input type="hidden" id="f-id">

        <div class="wizard-shell">

            <!-- SIDEBAR VERTICALE DES ÉTAPES -->
            <div class="wizard-sidebar">
                <div class="wizard-sidebar-step" data-step="1" onclick="tryGoToStepOT(1)">
                    <div class="ss-icon"><i class="fa-solid fa-user"></i></div>
                    <div class="ss-text"><strong><?php echo htmlspecialchars(t('maint.wizard_step1_title')); ?></strong><span><?php echo htmlspecialchars(t('maint.wizard_step1_sub')); ?></span></div>
                </div>
                <div class="wizard-sidebar-connector" data-connector="1"></div>
                <div class="wizard-sidebar-step" data-step="2" onclick="tryGoToStepOT(2)">
                    <div class="ss-icon"><i class="fa-solid fa-location-dot"></i></div>
                    <div class="ss-text"><strong><?php echo htmlspecialchars(t('maint.wizard_step2_title')); ?></strong><span><?php echo htmlspecialchars(t('maint.wizard_step2_sub')); ?></span></div>
                </div>
                <div class="wizard-sidebar-connector" data-connector="2"></div>
                <div class="wizard-sidebar-step" data-step="3" onclick="tryGoToStepOT(3)">
                    <div class="ss-icon"><i class="fa-solid fa-screwdriver-wrench"></i></div>
                    <div class="ss-text"><strong><?php echo htmlspecialchars(t('maint.wizard_step3_title')); ?></strong><span><?php echo htmlspecialchars(t('maint.wizard_step3_sub')); ?></span></div>
                </div>
                <div class="wizard-sidebar-connector" data-connector="3"></div>
                <div class="wizard-sidebar-step" data-step="4" onclick="tryGoToStepOT(4)">
                    <div class="ss-icon"><i class="fa-solid fa-clipboard-check"></i></div>
                    <div class="ss-text"><strong><?php echo htmlspecialchars(t('maint.wizard_step4_title')); ?></strong><span><?php echo htmlspecialchars(t('maint.wizard_step4_sub')); ?></span></div>
                </div>

                <div class="wizard-sidebar-progress-wrap">
                    <div class="wizard-mini-progress-bar"><div class="wizard-mini-progress-fill" id="wizardProgressFillOT"></div></div>
                    <div class="wizard-mini-progress-label" id="wizardProgressLabelOT"><?php echo htmlspecialchars(t('maint.wizard_progress', ['{n}' => '1', '{total}' => '4'])); ?></div>
                </div>
            </div>

            <div class="wizard-main">
                <div class="wizard-content">

                    <!-- ÉTAPE 1 : QUI -->
                    <div class="wizard-panel" data-panel="1">
                        <div class="wizard-panel-title"><i class="fa-solid fa-user" style="color:var(--accent);"></i> <?php echo htmlspecialchars(t('maint.s1_title')); ?></div>
                        <div class="wizard-panel-desc"><?php echo htmlspecialchars(t('maint.s1_desc')); ?></div>

                        <div class="wizard-step-help">
                            <img src="img/aide/ot_wizard_etape1.png" alt="<?php echo htmlspecialchars(t('maint.s1_img_alt')); ?>" onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                            <div class="wizard-step-help-icon-fallback" style="display:none;"><i class="fa-solid fa-user-pen"></i></div>
                            <div class="wizard-step-help-text"><?php echo t('maint.s1_help'); ?></div>
                        </div>

                        <div class="saisie-grid">
                            <?php if($is_admin): ?>
                            <div class="field" style="grid-column: 1 / -1;">
                                <span><?php echo htmlspecialchars(t('maint.label_declarant')); ?> <i class="fa-solid fa-circle-question icon-help" onclick="ouvrirAide('tech')"></i></span>
                                <select id="f-tech" style="width:100%; height: 35px;"></select>
                            </div>
                            <div class="field" style="grid-column: 1 / -1; position:relative;">
                                <span><?php echo htmlspecialchars(t('maint.label_intervenant_prevu')); ?> <i class="fa-solid fa-circle-question icon-help" onclick="ouvrirAide('assign_tech')"></i></span>
                                <button type="button" id="f-assign-tech-toggle" onclick="toggleAssignTechDropdown(event)" style="width:100%; height:35px; box-sizing:border-box; text-align:left; background:#fff; border:1px solid #ccc; border-radius:6px; padding:0 10px; font-family:inherit; font-size:0.85rem; font-weight:normal; color:var(--primary); cursor:pointer; display:flex; justify-content:space-between; align-items:center; gap:8px;">
                                    <span id="f-assign-tech-summary" style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap;"></span>
                                    <i class="fa-solid fa-chevron-down" style="font-size:0.7rem; color:#94a3b8; flex-shrink:0;"></i>
                                </button>
                                <div id="f-assign-tech-list" style="display:none; position:absolute; top:calc(100% + 4px); left:0; right:0; z-index:50; background:#fff; border:1px solid #ccc; border-radius:6px; box-shadow:0 8px 20px rgba(0,0,0,0.15); padding:6px; box-sizing:border-box;"></div>
                            </div>
                            <?php else: ?>
                            <div class="field">
                                <span><?php echo htmlspecialchars(t('maint.label_declarant')); ?> <i class="fa-solid fa-circle-question icon-help" onclick="ouvrirAide('tech')"></i></span>
                                <select id="f-tech"></select>
                            </div>
                            <?php endif; ?>
                            <div class="field" style="grid-column: 1 / -1; display: flex; flex-direction: row; align-items: center; gap: 10px; background: #f8fafc; padding: 10px; border-radius: 6px; border: 1px dashed #cbd5e1; margin-top: 5px;">
                                <input type="checkbox" id="f-is-st" onchange="toggleST()" style="width: 18px; height: 18px; margin: 0;">
                                <label for="f-is-st" style="color: var(--primary); font-size: 0.85rem; cursor: pointer; font-weight: bold;"><?php echo htmlspecialchars(t('maint.check_sous_traite')); ?> <i class="fa-solid fa-circle-question icon-help" onclick="ouvrirAide('sous_traitant')"></i></label>
                            </div>

                            <div class="field" id="container-ee" style="display: none; grid-column: 1 / -1; background: #fff5e6; padding: 10px; border-radius: 6px; border-left: 4px solid var(--brand-orange);">
                                <span style="color: var(--brand-orange);"><?php echo htmlspecialchars(t('maint.label_entreprise_ext')); ?></span>
                                <select id="f-entreprise">
                                    <option value=""><?php echo htmlspecialchars(t('maint.opt_choisir_entreprise')); ?></option>
                                    <?php foreach($entreprises_ext as $ee): ?>
                                        <option value="<?php echo htmlspecialchars($ee['id']); ?>"><?php echo htmlspecialchars($ee['nom']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </div>

                    <!-- ÉTAPE 2 : LOCALISATION -->
                    <div class="wizard-panel" data-panel="2">
                        <div class="wizard-panel-title"><i class="fa-solid fa-location-dot" style="color:var(--accent);"></i> <?php echo htmlspecialchars(t('maint.s2_title')); ?></div>
                        <div class="wizard-panel-desc"><?php echo htmlspecialchars(t('maint.s2_desc')); ?></div>

                        <div class="wizard-step-help">
                            <img src="img/aide/ot_wizard_etape2.png" alt="<?php echo htmlspecialchars(t('maint.s2_img_alt')); ?>" onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                            <div class="wizard-step-help-icon-fallback" style="display:none;"><i class="fa-solid fa-map-location-dot"></i></div>
                            <div class="wizard-step-help-text"><?php echo t('maint.s2_help'); ?></div>
                        </div>

                        <div class="saisie-grid">
                            <div class="field loc-search-field" id="loc-search-field">
                                <span><?php echo htmlspecialchars(t('maint.label_recherche_rapide')); ?> <i class="fa-solid fa-circle-question icon-help" onclick="ouvrirAide('machine')"></i></span>
                                <div class="loc-search-wrap">
                                    <i class="fa-solid fa-magnifying-glass loc-search-icon"></i>
                                    <input type="text" id="loc-search-input" placeholder="<?php echo htmlspecialchars(t('demande.search_placeholder')); ?>" autocomplete="off" oninput="rechercheMachine(this.value)" onfocus="rechercheMachine(this.value)">
                                    <div id="loc-search-results" class="loc-search-results"></div>
                                </div>
                            </div>

                            <div id="loc-breadcrumb" class="loc-breadcrumb"></div>
                            <div id="loc-tiles" class="loc-tiles"></div>

                            <div id="machine-stats-box" class="wizard-smart-box" style="display:none;"></div>

                            <!-- Champs réels du formulaire : pilotés par le picker visuel ci-dessus -->
                            <div id="container-usine" class="field" style="display:none;">
                                <span><?php echo htmlspecialchars(t('maint.label_lieu_usine')); ?></span>
                                <select id="f-usine" onchange="updateSecteurs()"><option value=""><?php echo htmlspecialchars(t('maint.opt_selectionner')); ?></option></select>
                            </div>
                            <div id="container-secteur" class="field" style="display:none;">
                                <span><?php echo htmlspecialchars(t('maint.label_secteur')); ?></span>
                                <select id="f-secteur" onchange="updateLignes()" disabled><option value=""><?php echo htmlspecialchars(t('maint.opt_en_attente')); ?></option></select>
                            </div>
                            <div id="container-ligne" class="field" style="display:none;">
                                <span><?php echo htmlspecialchars(t('maint.label_ligne')); ?></span>
                                <select id="f-ligne" onchange="updateZones()" disabled><option value=""><?php echo htmlspecialchars(t('maint.opt_en_attente')); ?></option></select>
                            </div>
                            <div id="container-zone" class="field" style="display:none;">
                                <span><?php echo htmlspecialchars(t('maint.label_zone')); ?></span>
                                <select id="f-zone" onchange="updateMachines()" disabled><option value=""><?php echo htmlspecialchars(t('maint.opt_en_attente')); ?></option></select>
                            </div>
                            <div id="container-equip" class="field" style="display:none;">
                                <span><?php echo htmlspecialchars(t('maint.label_machine')); ?></span>
                                <select id="f-equip" disabled><option value=""><?php echo htmlspecialchars(t('maint.opt_en_attente')); ?></option></select>
                            </div>
                        </div>
                    </div>

                    <!-- ÉTAPE 3 : DÉTAILS -->
                    <div class="wizard-panel" data-panel="3">
                        <div class="wizard-panel-title"><i class="fa-solid fa-screwdriver-wrench" style="color:var(--accent);"></i> <?php echo htmlspecialchars(t('maint.s3_title')); ?></div>
                        <div class="wizard-panel-desc"><?php echo htmlspecialchars(t('maint.s3_desc')); ?></div>

                        <div class="wizard-step-help">
                            <img src="img/aide/ot_wizard_etape3.png" alt="<?php echo htmlspecialchars(t('maint.s3_img_alt')); ?>" onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                            <div class="wizard-step-help-icon-fallback" style="display:none;"><i class="fa-solid fa-list-check"></i></div>
                            <div class="wizard-step-help-text"><?php echo t('maint.s3_help'); ?></div>
                        </div>

                        <div class="saisie-grid">
                            <div class="field">
                                <span><?php echo htmlspecialchars(t('maint.label_date')); ?> <i class="fa-solid fa-circle-question icon-help" onclick="ouvrirAide('date')"></i></span>
                                <input id="f-date" type="date">
                            </div>
                            <div class="field" style="display:none;"><input id="f-hours" type="number" step="0.25" value="0"></div>
                            <div class="field">
                                <span><?php echo htmlspecialchars(t('maint.label_type')); ?> <i class="fa-solid fa-circle-question icon-help" onclick="ouvrirAide('type')"></i></span>
                                <select id="f-type">
                                    <option value="Curatif"><?php echo htmlspecialchars(t('maint.type_curatif')); ?></option>
                                    <option value="Préventif"><?php echo htmlspecialchars(t('maint.type_preventif')); ?></option>
                                    <option value="Chantier"><?php echo htmlspecialchars(t('maint.type_chantier')); ?></option>
                                </select>
                            </div>
                            <div class="field">
                                <span><?php echo htmlspecialchars(t('maint.label_priorite')); ?> <i class="fa-solid fa-circle-question icon-help" onclick="ouvrirAide('prio')"></i></span>
                                <div style="display:flex; align-items:center; gap:5px; width:100%;">
                                    <select id="f-prio" onchange="updatePrioIcon()"><option value="Normal"><?php echo htmlspecialchars(t('maint.lib_normal')); ?></option><option value="Urgent"><?php echo htmlspecialchars(t('maint.lib_urgent')); ?></option></select>
                                    <span id="prio-icon-container"><i class="fa-solid fa-circle-check" style="color:var(--accent)"></i></span>
                                </div>
                            </div>
                            <div class="field" style="grid-column: 1 / -1; display: flex; flex-direction: row; align-items: center; gap: 20px; background: #f8fafc; padding: 10px; border-radius: 6px; border: 1px dashed #cbd5e1; margin-top: 15px;">
                                <div style="display: flex; align-items: center; gap: 8px;">
                                    <input type="checkbox" id="f-casse" style="width:18px; height:18px; margin: 0;">
                                    <label for="f-casse" style="color:var(--danger); font-size: 0.85rem; font-weight: bold; cursor: pointer;"><?php echo htmlspecialchars(t('maint.label_casse')); ?> <i class="fa-solid fa-circle-question icon-help" onclick="ouvrirAide('casse')"></i></label>
                                </div>
                                <div style="display: flex; align-items: center; gap: 8px; border-left: 2px solid #cbd5e1; padding-left: 20px;">
                                    <input type="checkbox" id="f-verif-vis" style="width:18px; height:18px; margin: 0;">
                                    <label for="f-verif-vis" style="color:var(--primary); font-size: 0.85rem; font-weight: bold; cursor: pointer;"><?php echo htmlspecialchars(t('maint.label_verif_visserie')); ?> <i class="fa-solid fa-circle-question icon-help" onclick="ouvrirAide('verif_vis')"></i></label>
                                </div>
                            </div>
                            <div class="field" style="grid-column: 1 / -1;">
                                <span><?php echo htmlspecialchars(t('maint.label_description')); ?> <i class="fa-solid fa-circle-question icon-help" onclick="ouvrirAide('desc')"></i></span>
                                <input id="f-desc" placeholder="<?php echo htmlspecialchars(t('maint.desc_placeholder2')); ?>" oninput="onDescInputOT()">
                            </div>
                            <div id="similar-ot-box" class="wizard-smart-box" style="display:none;"></div>
                            <div class="field" id="photo-picker-ot" style="grid-column: 1 / -1;">
                                <span><?php echo htmlspecialchars(t('maint.label_photos')); ?> <i class="fa-solid fa-circle-question icon-help" onclick="ouvrirAide('photos')"></i></span>
                                <div class="photo-picker">
                                    <label class="photo-add-btn" for="f-photos">
                                        <i class="fa-solid fa-camera"></i>
                                        <?php echo htmlspecialchars(t('demande.add_photo')); ?>
                                        <input type="file" id="f-photos" accept="image/*" multiple style="display:none;" onchange="onPhotosSelectedOT(this.files)">
                                    </label>
                                    <div class="photo-thumbs" id="photo-thumbs-ot"></div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- ÉTAPE 4 : RÉCAPITULATIF -->
                    <div class="wizard-panel" data-panel="4">
                        <div class="wizard-panel-title"><i class="fa-solid fa-clipboard-check" style="color:var(--accent);"></i> <?php echo htmlspecialchars(t('maint.s4_title')); ?></div>
                        <div class="wizard-panel-desc"><?php echo htmlspecialchars(t('maint.s4_desc')); ?></div>

                        <div class="wizard-step-help">
                            <img src="img/aide/ot_wizard_etape4.png" alt="<?php echo htmlspecialchars(t('maint.s4_img_alt')); ?>" onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                            <div class="wizard-step-help-icon-fallback" style="display:none;"><i class="fa-solid fa-circle-check"></i></div>
                            <div class="wizard-step-help-text"><?php echo t('maint.s4_help'); ?></div>
                        </div>

                        <div class="review-card" id="reviewCardOT"></div>
                    </div>

                </div>
            </div>
        </div>

        <!-- Hors de .wizard-shell (donc hors de la zone qui scrolle) : ces boutons restent toujours
             visibles en bas de la fenêtre, même quand le contenu d'une étape (ex. le récapitulatif
             final) dépasse la hauteur visible. -->
        <div class="wizard-nav">
            <button class="btn-wizard btn-wizard-cancel" onclick="closeWizardOT()"><?php echo htmlspecialchars(t('maint.cancel')); ?></button>
            <div style="display:flex; gap:10px;">
                <button class="btn-wizard btn-wizard-prev" id="btnWizardPrevOT" onclick="prevStepOT()" style="display:none;"><i class="fa-solid fa-arrow-left"></i> <?php echo htmlspecialchars(t('demande.btn_prev')); ?></button>
                <button class="btn-wizard btn-wizard-next" id="btnWizardNextOT" onclick="nextStepOT()"><?php echo htmlspecialchars(t('demande.btn_next')); ?> <i class="fa-solid fa-arrow-right"></i></button>
                <button id="btn-submit" onclick="saveTask()" style="display:none;"><i class="fa-solid fa-plus-circle"></i> <?php echo htmlspecialchars(t('maint.btn_creer_ticket')); ?></button>
            </div>
        </div>
    </div>
</div>

<div id="modalRefusDemande" class="modal">
    <div class="modal-content" style="max-width:460px;">
        <div style="display:flex; justify-content:space-between; align-items:center; border-bottom:3px solid var(--danger); padding-bottom:10px;">
            <h2 style="font-family:'Caveat', cursive; margin:0; color:var(--danger); display:flex; align-items:center; gap:10px;">
                <i class="fa-solid fa-ban"></i> <?php echo htmlspecialchars(t('maint.refus_title')); ?>
            </h2>
            <span style="cursor:pointer; font-size:30px; color:#94a3b8;" onclick="fermerModalRefus()">&times;</span>
        </div>
        <div style="margin-top:18px;">
            <p style="font-size:0.88rem; color:#5a6b7a; margin:0 0 12px;"><?php echo htmlspecialchars(t('maint.refus_desc')); ?></p>
            <label style="display:block; font-size:0.7rem; font-weight:700; color:#555; text-transform:uppercase; margin-bottom:6px;"><?php echo htmlspecialchars(t('maint.refus_label')); ?> <span style="color:var(--danger);">*</span> <span style="font-weight:400; text-transform:none; color:#94a3b8;"><?php echo htmlspecialchars(t('maint.refus_label_min')); ?></span></label>
            <textarea id="refus-motif-input" rows="4" placeholder="<?php echo htmlspecialchars(t('maint.refus_placeholder')); ?>" style="width:100%; padding:10px; border:1px solid #ddd; border-radius:8px; font-family:inherit; font-size:0.9rem; box-sizing:border-box; resize:vertical;" oninput="document.getElementById('refus-motif-count').textContent = this.value.trim().length + ' / 20'"></textarea>
            <div style="display:flex; justify-content:space-between; align-items:center; margin-top:6px;">
                <div id="refus-motif-error" style="font-size:0.68rem; color:var(--danger); font-weight:600; min-height:14px;"></div>
                <div id="refus-motif-count" style="font-size:0.68rem; color:#94a3b8; font-weight:600;">0 / 20</div>
            </div>
        </div>
        <div style="display:flex; gap:10px; margin-top:16px;">
            <button onclick="fermerModalRefus()" style="flex:1; padding:11px; border:none; border-radius:8px; background:#f1f5f9; color:#64748b; cursor:pointer; font-weight:700;"><?php echo htmlspecialchars(t('maint.cancel')); ?></button>
            <button onclick="confirmerRefus()" style="flex:1; padding:11px; border:none; border-radius:8px; background:var(--danger); color:white; cursor:pointer; font-weight:700;"><i class="fa-solid fa-ban"></i> <?php echo htmlspecialchars(t('maint.refus_confirm_btn')); ?></button>
        </div>
    </div>
</div>

<div id="modalHistory" class="modal">
    <div class="modal-content">
        <div style="display:flex; justify-content:space-between; align-items:center; border-bottom:3px solid var(--brand-orange); padding-bottom:10px;">
            <h2 id="modalTitle" style="font-family:'Caveat', cursive; margin:0;"></h2>
            <span style="cursor:pointer; font-size:30px;" onclick="closeModal()">&times;</span>
        </div>
        <div id="modalBody" style="margin-top:20px;"></div>
    </div>
</div>

<script>
let tasks = [];
let pointages = [];

// --- HISTORIQUE GLOBAL : affichage limité par défaut (perf), avec bascule 25/50/75/... ---
const HISTORIQUE_LIMIT_STEPS = [50, 100, 150, 200, 250, 300];
let historiqueLimit = 50;
let historiqueSorted = [];       // dernier tri complet (le plus récent d'abord) calculé par render()
let historiqueFullyRendered = false; // true quand le tableau contient tous les bons (recherche/filtre actif)

function rafraichirNotificationSas(nouveauCompteur) {
    const bandeau = document.getElementById('bandeau-sirene-sas');
    const compteurSpan = document.getElementById('compteur-sirene-sas');
    
    if (nouveauCompteur <= 0) {
        if (bandeau) bandeau.style.display = 'none'; // Cache si plus aucune demande
    } else {
        if (bandeau) bandeau.style.display = 'flex'; // Réaffiche si le bandeau était caché
        if (compteurSpan) compteurSpan.innerText = nouveauCompteur; // Met le chiffre à jour
    }
}

const team = <?php echo json_encode($team_maintenance); ?>;
const LIBELLES = <?php echo json_encode($LIBELLES_WORKFLOW); ?>;

const isAdmin = <?php echo json_encode($is_admin); ?>;
const currentUser = "<?php echo $_SESSION['user']; ?>";

const I18N_MAINT = <?php echo json_encode([
    'opt_selectionner' => t('maint.opt_selectionner'),
    'opt_select_secteur' => t('maint.opt_select_secteur'),
    'opt_select_ligne' => t('maint.opt_select_ligne'),
    'opt_select_zone' => t('maint.opt_select_zone'),
    'opt_select_machine' => t('maint.opt_select_machine'),
    'opt_en_attente' => t('maint.opt_en_attente'),
    'label_machine' => t('maint.label_machine'),
    'free_input_placeholder' => t('demande.free_input_placeholder'),
    'label_usine' => t('demande.label_usine'),
    'label_secteur' => t('maint.label_secteur'),
    'crumb_secteur' => t('demande.label_secteur'),
    'label_ligne' => t('demande.label_ligne'),
    'label_zone' => t('demande.label_zone'),
    'change_machine' => t('demande.change_machine'),
    'no_options' => t('demande.no_options'),
    'no_machine_found' => t('demande.no_machine_found'),
    'unnamed' => t('demande.unnamed'),
    'remove_photo_tooltip' => t('demande.remove_photo_tooltip'),
    'create_tile_title' => t('maint.create_tile_title'),
    'btn_creer_ticket' => t('maint.btn_creer_ticket'),
    'wizard_edit_title' => t('maint.wizard_edit_title'),
    'btn_maj_ticket' => t('maint.btn_maj_ticket'),
    'label_declarant' => t('maint.label_declarant'),
    'wizard_progress' => t('maint.wizard_progress'),
    'recap_declarant' => t('maint.recap_declarant'),
    'recap_entreprise_ext' => t('maint.recap_entreprise_ext'),
    'recap_intervenant_prevu' => t('maint.recap_intervenant_prevu'),
    'recap_localisation' => t('demande.recap_localisation'),
    'recap_machine' => t('demande.recap_machine'),
    'recap_date' => t('maint.recap_date'),
    'recap_type' => t('maint.recap_type'),
    'recap_priorite' => t('maint.recap_priorite'),
    'recap_casse' => t('maint.recap_casse'),
    'recap_verif_vis' => t('maint.recap_verif_vis'),
    'recap_description' => t('demande.recap_description'),
    'recap_oui' => t('maint.recap_oui'),
    'recap_non' => t('maint.recap_non'),
    'recap_edit' => t('demande.recap_edit'),
    'recap_empty' => t('demande.recap_empty_loc'),
    'confirm_open_existing_title' => t('maint.confirm_open_existing_title'),
    'confirm_open_existing_closed' => t('maint.confirm_open_existing_closed'),
    'confirm_open_existing_open' => t('maint.confirm_open_existing_open'),
    'badge_urgent' => t('maint.badge_urgent'),
    'sans_description' => t('maint.sans_description'),
    'similar_found_many' => t('maint.similar_found_many'),
    'similar_found_one' => t('maint.similar_found_one'),
    'similar_check' => t('maint.similar_check'),
    'machine_history_intro' => t('maint.machine_history_intro'),
    'machine_history_deja_enreg' => t('maint.machine_history_deja_enreg'),
    'machine_history_dont_ouverts' => t('maint.machine_history_dont_ouverts'),
    'machine_history_tous_clotures' => t('maint.machine_history_tous_clotures'),
    'saisie_close' => t('maint.saisie_close'),
    'plus_temps' => t('maint.plus_temps'),
    'btn_fiche_tache' => t('maint.btn_fiche_tache'),
    'fiche_tache_bi' => t('maint.fiche_tache_bi'),
    'fiche_tache_machine' => t('maint.fiche_tache_machine'),
    'fiche_tache_localisation' => t('maint.fiche_tache_localisation'),
    'fiche_tache_description' => t('maint.fiche_tache_description'),
    'fiche_tache_demandeur' => t('maint.fiche_tache_demandeur'),
    'fiche_tache_date' => t('maint.fiche_tache_date'),
    'fiche_tache_technicien_assigne' => t('maint.fiche_tache_technicien_assigne'),
    'fiche_tache_carnet' => t('maint.fiche_tache_carnet'),
    'type_curatif' => t('maint.type_curatif'),
    'type_preventif' => t('maint.type_preventif'),
    'type_chantier' => t('maint.type_chantier'),
    'casse_yes' => t('maint.casse_yes'),
    'kpi_taux_realisation' => t('maint.kpi_taux_realisation'),
    'kpi_bons_total' => t('maint.kpi_bons_total'),
    'kpi_tous_les_bons' => t('maint.kpi_tous_les_bons'),
    'kpi_urgent' => t('maint.lib_urgent'),
    'kpi_a_faire' => t('maint.lib_afaire'),
    'kpi_en_cours' => t('maint.lib_encours'),
    'kpi_termine' => t('maint.kpi_termine'),
    'kpi_messages_non_lus' => t('maint.filter_unread_msgs'),
    'kpi_non_assigne' => t('maint.kpi_non_assigne'),
    'opt_non_assigne' => t('maint.opt_non_assigne'),
    'kpi_preventif' => t('maint.type_preventif'),
    'kpi_curatif' => t('maint.type_curatif'),
    'kpi_materiel_casse' => t('maint.kpi_materiel_casse'),
    'kpi_verif_visserie' => t('maint.recap_verif_vis'),
    'stat_taches_en_cours' => t('maint.stat_taches_en_cours'),
    'limit_last_of' => t('maint.limit_last_of'),
    'limit_search_active' => t('maint.limit_search_active'),
    'bulk_delete_btn_n' => t('maint.bulk_delete_btn_n'),
    'bulk_print_btn_n' => t('maint.bulk_print_btn_n'),
    'del_no_rights_delete' => t('maint.del_no_rights_delete'),
    'del_selection_confirm' => t('maint.del_selection_confirm'),
    'del_irreversible' => t('maint.del_irreversible'),
    'del_single_confirm' => t('maint.del_single_confirm'),
    'del_deleting' => t('maint.del_deleting'),
    'del_supprimer' => t('maint.del_supprimer'),
    'del_no_rights_delete_single' => t('maint.del_no_rights_delete_single'),
    'del_no_rights_refuse' => t('maint.del_no_rights_refuse'),
    'del_err_server' => t('maint.del_err_server'),
    'del_err_server2' => t('maint.del_err_server2'),
    'err_network' => t('demande.err_network'),
    'err_server' => t('maint.err_server'),
    'hist_title' => t('maint.hist_title'),
    'hist_th_num_bi' => t('maint.hist_th_num_bi'),
    'hist_th_date' => t('maint.hist_th_date'),
    'hist_th_loc' => t('maint.hist_th_loc'),
    'hist_th_desc' => t('maint.hist_th_desc'),
    'hist_th_role_heures' => t('maint.hist_th_role_heures'),
    'hist_th_statut' => t('maint.hist_th_statut'),
    'hist_th_action' => t('maint.hist_th_action'),
    'col_bi_placeholder' => t('maint.col_bi_placeholder'),
    'hist_filter_loc_placeholder' => t('maint.hist_filter_loc_placeholder'),
    'hist_filter_desc_placeholder' => t('maint.hist_filter_desc_placeholder'),
    'col_all' => t('maint.col_all'),
    'lib_urgent' => t('maint.lib_urgent'),
    'lib_normal' => t('maint.lib_normal'),
    'lib_afaire' => t('maint.lib_afaire'),
    'lib_encours' => t('maint.lib_encours'),
    'lib_termine' => t('maint.lib_termine'),
    'hist_declarant_badge' => t('maint.hist_declarant_badge'),
    'hist_loc_non_specifiee' => t('maint.hist_loc_non_specifiee'),
    'err_db' => t('maint.err_db'),
    'err_comm_failed' => t('maint.err_comm_failed'),
    'reouvrir_confirm_title' => t('maint.reouvrir_confirm_title'),
    'reouvrir_confirm_msg' => t('maint.reouvrir_confirm_msg'),
    'err_status_change' => t('maint.err_status_change'),
    'err_connection_api' => t('maint.err_connection_api'),
    'access_denied_title' => t('maint.access_denied_title'),
    'access_denied_reopen' => t('maint.access_denied_reopen'),
    'reopen_confirm_title' => t('maint.reopen_confirm_title'),
    'reopen_confirm_msg' => t('maint.reopen_confirm_msg'),
    'success_title' => t('maint.success_title'),
    'reopen_success_msg' => t('maint.reopen_success_msg'),
    'err_reopen' => t('maint.err_reopen'),
    'select_technician' => t('maint.select_technician'),
    'enter_valid_duration' => t('maint.enter_valid_duration'),
    'err_save_server' => t('maint.err_save_server'),
    'err_modification' => t('maint.err_modification'),
    'enter_valid_duration2' => t('maint.enter_valid_duration2'),
    'saving' => t('maint.saving'),
    'note_success_msg' => t('maint.note_success_msg'),
    'err_server_refused' => t('maint.err_server_refused'),
    'err_network_unreachable' => t('maint.err_network_unreachable'),
    'sas_sync_loading' => t('maint.sas_sync_loading'),
    'sas_none_title' => t('maint.sas_none_title'),
    'sas_error_fetch' => t('maint.sas_error_fetch'),
    'sas_dossiers_count' => t('maint.sas_dossiers_count'),
    'sas_non_specifie' => t('maint.sas_non_specifie'),
    'sas_retour_liste' => t('maint.sas_retour_liste'),
    'sas_dossier_num' => t('maint.sas_dossier_num'),
    'sas_en_attente_pill' => t('maint.sas_en_attente_pill'),
    'sas_desc_panne' => t('maint.cloture_desc_panne'),
    'sas_photos_jointes' => t('suivi.label_photos'),
    'sas_chargement' => t('suivi.chargement'),
    'sas_no_photo' => t('suivi.aucune_photo'),
    'sas_affectation_title' => t('maint.sas_affectation_title'),
    'sas_technicien' => t('maint.ptg_technicien'),
    'sas_date_prevue' => t('maint.sas_date_prevue'),
    'sas_priorite' => t('maint.label_priorite'),
    'sas_refuser' => t('maint.sas_refuser'),
    'sas_messagerie' => t('maint.sas_messagerie'),
    'sas_vers_preventif' => t('maint.sas_vers_preventif'),
    'sas_creer_bi' => t('maint.sas_creer_bi'),
    'sas_dossier_introuvable' => t('maint.sas_dossier_introuvable'),
    'refus_err_empty' => t('maint.refus_err_empty'),
    'refus_err_short' => t('maint.refus_err_short'),
    'refus_err_notfound' => t('maint.refus_err_notfound'),
    'refus_err_network' => t('maint.refus_err_network'),
    'refus_message_to_requester' => t('maint.refus_message_to_requester'),
    'transfer_confirm_title' => t('maint.transfer_confirm_title'),
    'transfer_confirm_msg' => t('maint.transfer_confirm_msg'),
    'transfer_err_add' => t('maint.transfer_err_add'),
    'transfer_warn_partial' => t('maint.transfer_warn_partial'),
    'transfer_success_title' => t('maint.transfer_success_title'),
    'transfer_success_msg' => t('maint.transfer_success_msg'),
    'attention_title' => t('maint.attention_title'),
    'err_title' => t('maint.err_title'),
    'err_network_title' => t('maint.err_network_title'),
    'retry' => t('maint.retry'),
    'confirm_del_title' => t('maint.confirm_del_title'),
    'cloture_aucune_intervention' => t('maint.cloture_aucune_intervention'),
    'cloture_th_intervenant' => t('maint.cloture_th_intervenant'),
    'cloture_th_duree' => t('maint.cloture_th_duree'),
    'cloture_aucun_pointage' => t('maint.cloture_aucun_pointage'),
    'cloture_err_missing_report' => t('maint.cloture_err_missing_report'),
    'cloture_success_title' => t('maint.cloture_success_title'),
    'cloture_success_msg' => t('maint.cloture_success_msg'),
    'cloture_err_save' => t('maint.cloture_err_save'),
    'cloture_err_save_network' => t('maint.cloture_err_save_network'),
    'jours' => [t('maint.jour_0'), t('maint.jour_1'), t('maint.jour_2'), t('maint.jour_3'), t('maint.jour_4'), t('maint.jour_5'), t('maint.jour_6')],
    'ptg_modal_title' => t('maint.ptg_modal_title'),
    'ptg_equipement' => t('maint.ptg_equipement'),
    'ptg_technicien' => t('maint.ptg_technicien'),
    'ptg_heures' => t('maint.ptg_heures'),
    'ptg_date' => t('maint.col_date'),
    'ptg_ajouter' => t('maint.ptg_ajouter'),
    'ptg_lieu' => t('maint.ptg_lieu'),
    'ptg_loc_non_precisee' => t('maint.ptg_loc_non_precisee'),
    'ptg_detail' => t('maint.ptg_detail'),
    'ptg_aucune_desc' => t('maint.ptg_aucune_desc'),
    'ptg_th_date' => t('maint.col_date'),
    'ptg_th_technicien' => t('maint.ptg_technicien'),
    'ptg_th_heures' => t('maint.ptg_heures'),
    'ptg_th_actions' => t('maint.th_actions'),
    'locked_tooltip' => t('maint.locked_tooltip'),
    'locked_label' => t('maint.locked_label'),
    'modifier_tooltip' => t('maint.modifier_tooltip'),
    'supprimer_tooltip' => t('maint.supprimer_tooltip'),
    'aucun_temps' => t('maint.aucun_temps'),
    'aide_default_title' => t('demande.aide_default_title'),
    'aide_default_body' => t('demande.aide_default_body'),
    'msg_count_tooltip' => t('maint.msg_count_tooltip'),
    'date_at' => t('maint.date_at'),
]); ?>;

let dbMachines = [];
try { let parseData = <?php echo $json_machines ?: '[]'; ?>; if(Array.isArray(parseData)) dbMachines = parseData; } catch(e) {}

function toggleST() {
    const isST = document.getElementById('f-is-st').checked;
    document.getElementById('container-ee').style.display = isST ? 'block' : 'none';
}

// ============================================================================
// ASSISTANT GUIDÉ (WIZARD) DE CRÉATION / MODIFICATION D'UN BON D'INTERVENTION
// ============================================================================
let wizardStepOT = 1;
let wizardUnlockedOT = 1;
const WIZARD_STEPS_OT = 4;

function stepIsFilledOT(n) {
    if (n === 1) return !!document.getElementById('f-tech').value;
    if (n === 2) return !!document.getElementById('f-equip').value;
    if (n === 3) return !!document.getElementById('f-desc').value.trim();
    return true;
}

function updateWizardUIOT() {
    document.querySelectorAll('#modalWizardOT .wizard-panel').forEach(p => p.classList.toggle('active', parseInt(p.dataset.panel, 10) === wizardStepOT));
    document.querySelectorAll('#modalWizardOT .wizard-sidebar-step').forEach(item => {
        const n = parseInt(item.dataset.step, 10);
        const done = n < wizardStepOT;
        item.classList.toggle('active', n === wizardStepOT);
        item.classList.toggle('done', done);
        item.classList.toggle('step-incomplete', done && !stepIsFilledOT(n));
        item.classList.toggle('clickable', n <= wizardUnlockedOT);
    });
    document.querySelectorAll('#modalWizardOT .wizard-sidebar-connector').forEach(c => {
        const n = parseInt(c.dataset.connector, 10);
        c.classList.toggle('done', n < wizardStepOT);
    });
    document.getElementById('wizardProgressFillOT').style.width = ((wizardStepOT - 1) / (WIZARD_STEPS_OT - 1) * 100) + '%';
    document.getElementById('wizardProgressLabelOT').textContent = `Étape ${wizardStepOT} / ${WIZARD_STEPS_OT}`;

    document.getElementById('btnWizardPrevOT').style.display = wizardStepOT === 1 ? 'none' : 'flex';
    document.getElementById('btnWizardNextOT').style.display = wizardStepOT === WIZARD_STEPS_OT ? 'none' : 'flex';
    document.getElementById('btn-submit').style.display = wizardStepOT === WIZARD_STEPS_OT ? 'flex' : 'none';

    if (wizardStepOT === 3) updateSimilarOTBox();
    if (wizardStepOT === WIZARD_STEPS_OT) renderReviewOT();
}

function goToStepOT(n) {
    wizardStepOT = n;
    wizardUnlockedOT = Math.max(wizardUnlockedOT, n);
    updateWizardUIOT();
}

function tryGoToStepOT(n) { if (n <= wizardUnlockedOT) goToStepOT(n); }
function nextStepOT() { if (wizardStepOT < WIZARD_STEPS_OT) goToStepOT(wizardStepOT + 1); }
function prevStepOT() { if (wizardStepOT > 1) goToStepOT(wizardStepOT - 1); }

function renderReviewOT() {
    const g = id => { const el = document.getElementById(id); return el ? el.value : ''; };
    const isST = document.getElementById('f-is-st').checked;
    const entrepriseTxt = isST ? (document.getElementById('f-entreprise').selectedOptions[0]?.textContent || '—') : null;
    const intervenantsPrevus = getIntervenantsPrevusCoches();

    const locParts = ['f-usine', 'f-secteur', 'f-ligne', 'f-zone'].map(id => g(id)).filter(v => v && v !== 'N/A');

    const rowsData = [
        { icon: 'fa-user', label: I18N_MAINT.recap_declarant, val: g('f-tech') || I18N_MAINT.recap_empty, step: 1 },
        ...(isST ? [{ icon: 'fa-handshake', label: I18N_MAINT.recap_entreprise_ext, val: entrepriseTxt, step: 1 }] : []),
        ...(intervenantsPrevus.length ? [{ icon: 'fa-user-gear', label: I18N_MAINT.recap_intervenant_prevu, val: intervenantsPrevus.join(', '), step: 1 }] : []),
        { icon: 'fa-location-dot', label: I18N_MAINT.recap_localisation, val: locParts.join(' > ') || I18N_MAINT.recap_empty, step: 2 },
        { icon: 'fa-microchip', label: I18N_MAINT.recap_machine, val: g('f-equip') || I18N_MAINT.recap_empty, step: 2 },
        { icon: 'fa-calendar-days', iconStyle: 'fa-regular', label: I18N_MAINT.recap_date, val: g('f-date') ? g('f-date').split('-').reverse().join('/') : I18N_MAINT.recap_empty, step: 3 },
        { icon: 'fa-tag', label: I18N_MAINT.recap_type, val: g('f-type') === 'Préventif' ? I18N_MAINT.type_preventif : (g('f-type') === 'Chantier' ? I18N_MAINT.type_chantier : I18N_MAINT.type_curatif), step: 3 },
        { icon: 'fa-flag', label: I18N_MAINT.recap_priorite, val: g('f-prio') === 'Urgent' ? I18N_MAINT.lib_urgent : I18N_MAINT.lib_normal, step: 3 },
        { icon: 'fa-bolt', label: I18N_MAINT.recap_casse, val: document.getElementById('f-casse').checked ? I18N_MAINT.recap_oui : I18N_MAINT.recap_non, step: 3 },
        { icon: 'fa-screwdriver', label: I18N_MAINT.recap_verif_vis, val: document.getElementById('f-verif-vis').checked ? I18N_MAINT.recap_oui : I18N_MAINT.recap_non, step: 3 },
        { icon: 'fa-comment', label: I18N_MAINT.recap_description, val: g('f-desc') || I18N_MAINT.recap_empty, step: 3 },
    ];

    document.getElementById('reviewCardOT').innerHTML = rowsData.map(r => `
        <div class="ot-recap-item">
            <i class="${r.iconStyle || 'fa-solid'} ${r.icon}"></i>
            <div style="flex:1;">
                <div class="ot-recap-label">${r.label}</div>
                <div class="ot-recap-val">${(r.val || '').toString().replace(/</g, '&lt;')}</div>
            </div>
            <span class="ot-recap-edit" onclick="goToStepOT(${r.step})">${I18N_MAINT.recap_edit}</span>
        </div>
    `).join('');
}

// Poste de nuit = 21h-5h. Un technicien qui crée un BI entre minuit et 5h du matin doit voir le bon
// rattaché à la nuit qu'il vient de faire, pas au jour calendaire réel : un BI créé à 2h du matin le
// mercredi concerne la nuit de mardi à mercredi (donc mardi), pas le mercredi — qui peut même être son
// jour de repos. Entre 21h et minuit, "aujourd'hui" est déjà le bon jour (pas d'ajustement nécessaire) ;
// à partir de 5h, le poste de nuit est terminé, donc plus d'ajustement non plus.
// Retourne une chaîne "YYYY-MM-DD" (pas un objet Date) : .valueAsDate d'un <input type="date">
// interprète l'objet Date fourni en UTC, ce qui décale le résultat d'un jour de plus pile à minuit
// heure locale (France en UTC+1/+2) — assigner directement .value en évite tout risque.
function dateParDefautOT() {
    const now = new Date();
    if (now.getHours() < 5) {
        now.setDate(now.getDate() - 1);
    }
    const y = now.getFullYear();
    const m = String(now.getMonth() + 1).padStart(2, '0');
    const d = String(now.getDate()).padStart(2, '0');
    return `${y}-${m}-${d}`;
}

function resetWizardFieldsOT() {
    document.getElementById('f-id').value = '';
    const fTech = document.getElementById('f-tech');
    if (fTech) fTech.value = currentUser;
    cocherNonAssigne();
    document.getElementById('f-is-st').checked = false;
    toggleST();
    const fEntreprise = document.getElementById('f-entreprise');
    if (fEntreprise) fEntreprise.value = '';

    resetLocSelects();

    document.getElementById('f-date').value = dateParDefautOT();
    document.getElementById('f-hours').value = 0;
    document.getElementById('f-type').value = 'Curatif';
    document.getElementById('f-prio').value = 'Normal';
    updatePrioIcon();
    document.getElementById('f-casse').checked = false;
    document.getElementById('f-verif-vis').checked = false;
    document.getElementById('f-desc').value = '';

    const mBox = document.getElementById('machine-stats-box');
    if (mBox) { mBox.style.display = 'none'; mBox.innerHTML = ''; }
    const sBox = document.getElementById('similar-ot-box');
    if (sBox) { sBox.style.display = 'none'; sBox.innerHTML = ''; }

    // Nouveau ticket : le sélecteur de photos est visible et vidé (la gestion des photos d'un
    // BI existant se fait depuis sa fiche détail, pas depuis ce formulaire de création/édition).
    selectedPhotosOT = [];
    renderPhotoThumbsOT();
    const pPicker = document.getElementById('photo-picker-ot');
    if (pPicker) pPicker.style.display = '';
}

// --- PHOTOS jointes à la création d'un BI (voir bi_photos.php) ---
// Les fichiers restent en mémoire (File[]) le temps du formulaire, et ne sont envoyés au serveur
// qu'une fois le BI créé avec succès dans saveTask() — pour ne jamais avoir de photo orpheline
// si la création échoue.
let selectedPhotosOT = [];

function onPhotosSelectedOT(fileList) {
    for (const f of fileList) {
        if (!f.type.startsWith('image/')) continue;
        selectedPhotosOT.push(f);
    }
    document.getElementById('f-photos').value = ''; // permet de resélectionner le même fichier
    renderPhotoThumbsOT();
}

function removeSelectedPhotoOT(idx) {
    selectedPhotosOT.splice(idx, 1);
    renderPhotoThumbsOT();
}

function renderPhotoThumbsOT() {
    const box = document.getElementById('photo-thumbs-ot');
    if (!box) return;
    box.innerHTML = selectedPhotosOT.map((f, i) => `
        <div class="photo-thumb">
            <img src="${URL.createObjectURL(f)}" alt="${f.name}">
            <button type="button" class="photo-thumb-remove" onclick="removeSelectedPhotoOT(${i})" title="Retirer cette photo"><i class="fa-solid fa-xmark"></i></button>
        </div>`).join('');
}

// Envoie les photos en attente vers un BI qui vient d'être créé/confirmé (taskId connu).
async function uploadPendingPhotosOT(taskId) {
    if (selectedPhotosOT.length === 0) return;
    try {
        const tokenRes = await fetch('bi_photos.php');
        const tokenData = await tokenRes.json();
        for (const file of selectedPhotosOT) {
            const fd = new FormData();
            fd.append('action', 'upload');
            fd.append('task_id', taskId);
            fd.append('csrf_token', tokenData.csrf_token);
            fd.append('photo', file);
            await fetch('bi_photos.php', { method: 'POST', body: fd });
        }
    } catch (e) {
        console.error("Erreur lors de l'envoi des photos :", e);
    }
    selectedPhotosOT = [];
}

function ouvrirWizardOT() {
    document.getElementById('wizardTitleOT').innerHTML = '<i class="fa-solid fa-pen-to-square" style="color:var(--brand-orange);"></i> ' + I18N_MAINT.create_tile_title;
    const btn = document.getElementById('btn-submit');
    btn.innerHTML = '<i class="fa-solid fa-plus-circle"></i> ' + I18N_MAINT.btn_creer_ticket;
    btn.classList.remove('btn-update');

    resetWizardFieldsOT();
    wizardUnlockedOT = 1;
    goToStepOT(1);
    document.getElementById('modalWizardOT').style.display = "block";
}

function closeWizardOT() { document.getElementById('modalWizardOT').style.display = "none"; }

// Ouvre l'assistant de création avec la localisation déjà remplie — utilisé par le bouton
// "Créer BI" du Parc Machine (admin_machines.php), pour ne pas resaisir usine/secteur/ligne/zone/machine.
function ouvrirWizardOTPourMachine(usine, secteur, ligne, zone, equip) {
    ouvrirWizardOT();
    if (usine) locPickUsine(usine);
    if (secteur) locPickSecteur(secteur);
    if (ligne && ligne !== 'N/A') locPickLigne(ligne);
    if (zone) locPickZone(zone);
    if (equip) locPickEquip(equip);
    goToStepOT(2);
}

// Ouvre l'assistant de création avec le technicien et la date déjà remplis — utilisé par le choix
// "Créer un BI" d'une case du Planning (planning.php). On reste à l'étape 1 (la machine reste à
// choisir, planning.php ne la connaît pas) : le technicien/la date seront déjà là à l'étape 2.
function ouvrirWizardOTPourPlanning(tech, dateStr) {
    ouvrirWizardOT();
    if (tech) cocherIntervenantsPrevus([tech]);
    if (dateStr) document.getElementById('f-date').value = dateStr;
}

function initCascade() {
    if (dbMachines.length === 0) {
        // Aucune machine en base : pas de picker visuel possible, on retombe sur une saisie libre visible.
        const cEquip = document.getElementById('container-equip');
        cEquip.innerHTML = `<span>${I18N_MAINT.label_machine}</span><input id="f-equip" placeholder="${I18N_MAINT.free_input_placeholder}">`;
        cEquip.style.display = '';
        document.getElementById('loc-search-field').style.display = 'none';
        return;
    }
    const usines = [...new Set(dbMachines.map(m => m.usine))].sort();
    document.getElementById('f-usine').innerHTML = `<option value="">${I18N_MAINT.opt_selectionner}</option>` + usines.map(u => `<option value="${u}">${u}</option>`).join('');
    renderLocPicker();
}
function updateSecteurs() {
    const usine = document.getElementById('f-usine').value;
    const fSecteur = document.getElementById('f-secteur');
    if (!usine) { fSecteur.disabled = true; return; }
    const secteurs = [...new Set(dbMachines.filter(m => m.usine === usine).map(m => m.secteur).filter(Boolean))].sort();
    fSecteur.innerHTML = `<option value="">${I18N_MAINT.opt_select_secteur}</option>` + secteurs.map(s => `<option value="${s}">${s}</option>`).join('');
    fSecteur.disabled = false;
}
function updateLignes() {
    const usine = document.getElementById('f-usine').value;
    const secteur = document.getElementById('f-secteur').value;
    const fLigne = document.getElementById('f-ligne');
    if (!secteur) { fLigne.disabled = true; return; }
    const lignes = [...new Set(dbMachines.filter(m => m.usine === usine && m.secteur === secteur).map(m => m.ligne).filter(Boolean))].sort();
    if(lignes.length > 0) {
        fLigne.innerHTML = `<option value="">${I18N_MAINT.opt_select_ligne}</option>` + lignes.map(l => `<option value="${l}">${l}</option>`).join('');
        fLigne.disabled = false;
    } else {
        fLigne.innerHTML = '<option value="N/A">-</option>';
        fLigne.disabled = true;
        updateZones(); // S'il n'y a pas de ligne, on passe direct à la zone
    }
}
function updateZones() {
    const usine = document.getElementById('f-usine').value;
    const secteur = document.getElementById('f-secteur').value;
    const ligne = (document.getElementById('f-ligne') && document.getElementById('f-ligne').value !== 'N/A') ? document.getElementById('f-ligne').value : undefined;
    const fZone = document.getElementById('f-zone');

    let filtered = dbMachines.filter(m => m.usine === usine && m.secteur === secteur);
    if(ligne) filtered = filtered.filter(m => m.ligne === ligne);

    const zones = [...new Set(filtered.map(m => m.zone).filter(Boolean))].sort();
    fZone.innerHTML = `<option value="">${I18N_MAINT.opt_select_zone}</option>` + zones.map(z => `<option value="${z}">${z}</option>`).join('');
    fZone.disabled = false;
}
function updateMachines() {
    const usine = document.getElementById('f-usine').value;
    const secteur = document.getElementById('f-secteur').value;
    const ligne = (document.getElementById('f-ligne') && document.getElementById('f-ligne').value !== 'N/A') ? document.getElementById('f-ligne').value : undefined;
    const zone = document.getElementById('f-zone').value;
    const fEquip = document.getElementById('f-equip');
    if (!zone) { fEquip.disabled = true; return; }

    let filtered = dbMachines.filter(m => m.usine === usine && m.secteur === secteur && m.zone === zone);
    if(ligne) filtered = filtered.filter(m => m.ligne === ligne);

    const machines = filtered.sort((a,b) => (a.nom_machine||"").localeCompare(b.nom_machine||""));
    fEquip.innerHTML = `<option value="">${I18N_MAINT.opt_select_machine}</option>` + machines.map(m => `<option value="${m.nom_machine}">${m.nom_machine}</option>`).join('');
    fEquip.disabled = false;
}

// ============================================================================
// PICKER VISUEL DE LOCALISATION (recherche + navigation par tuiles)
// Pilote les <select> f-usine/f-secteur/f-ligne/f-zone/f-equip existants
// (gardés en compatibilité pour la revue, le reset et l'édition d'un BI).
// ============================================================================

function resetLocSelects() {
    document.getElementById('f-usine').value = '';
    const fSecteur = document.getElementById('f-secteur');
    fSecteur.innerHTML = `<option value="">${I18N_MAINT.opt_en_attente}</option>`; fSecteur.disabled = true;
    const fLigne = document.getElementById('f-ligne');
    fLigne.innerHTML = `<option value="">${I18N_MAINT.opt_en_attente}</option>`; fLigne.disabled = true;
    const fZone = document.getElementById('f-zone');
    fZone.innerHTML = `<option value="">${I18N_MAINT.opt_en_attente}</option>`; fZone.disabled = true;
    const fEquip = document.getElementById('f-equip');
    if (fEquip && fEquip.tagName === 'SELECT') { fEquip.innerHTML = `<option value="">${I18N_MAINT.opt_en_attente}</option>`; fEquip.disabled = true; }
    else if (fEquip) { fEquip.value = ''; }
    renderLocPicker();
}

function locHasLigneChoices() {
    const fLigne = document.getElementById('f-ligne');
    return [...fLigne.options].some(o => o.value && o.value !== 'N/A');
}

function locPickUsine(val) { document.getElementById('f-usine').value = val; updateSecteurs(); renderLocPicker(); }
function locPickSecteur(val) { document.getElementById('f-secteur').value = val; updateLignes(); renderLocPicker(); }
function locPickLigne(val) { document.getElementById('f-ligne').value = val; updateZones(); renderLocPicker(); }
function locPickZone(val) { document.getElementById('f-zone').value = val; updateMachines(); renderLocPicker(); }
function locPickEquip(val) { document.getElementById('f-equip').value = val; renderLocPicker(); }

function locGoBackTo(level) {
    const fUsine = document.getElementById('f-usine');
    const fSecteur = document.getElementById('f-secteur');
    const fLigne = document.getElementById('f-ligne');
    const fZone = document.getElementById('f-zone');
    const fEquip = document.getElementById('f-equip');
    const isEquipSelect = fEquip && fEquip.tagName === 'SELECT';

    if (level === 'usine') {
        resetLocSelects();
        return;
    }
    if (level === 'secteur') {
        fSecteur.value = '';
        fLigne.innerHTML = `<option value="">${I18N_MAINT.opt_en_attente}</option>`; fLigne.disabled = true;
        fZone.innerHTML = `<option value="">${I18N_MAINT.opt_en_attente}</option>`; fZone.disabled = true;
        if (isEquipSelect) { fEquip.innerHTML = `<option value="">${I18N_MAINT.opt_en_attente}</option>`; fEquip.disabled = true; }
        updateSecteurs();
    } else if (level === 'ligne') {
        fLigne.value = '';
        fZone.innerHTML = `<option value="">${I18N_MAINT.opt_en_attente}</option>`; fZone.disabled = true;
        if (isEquipSelect) { fEquip.innerHTML = `<option value="">${I18N_MAINT.opt_en_attente}</option>`; fEquip.disabled = true; }
        updateLignes();
    } else if (level === 'zone') {
        fZone.value = '';
        if (isEquipSelect) { fEquip.innerHTML = `<option value="">${I18N_MAINT.opt_en_attente}</option>`; fEquip.disabled = true; }
        updateZones();
    } else if (level === 'equip') {
        if (isEquipSelect) fEquip.value = '';
    }
    renderLocPicker();
}

function locTileIcon(level) {
    return { usine: 'fa-industry', secteur: 'fa-diagram-project', ligne: 'fa-arrows-left-right-to-line', zone: 'fa-map-pin', equip: 'fa-microchip' }[level];
}

function locOptionsFor(level) {
    const map = { usine: 'f-usine', secteur: 'f-secteur', ligne: 'f-ligne', zone: 'f-zone', equip: 'f-equip' };
    const sel = document.getElementById(map[level]);
    return [...sel.options].filter(o => o.value && o.value !== 'N/A');
}

function renderLocPicker() {
    const breadcrumbEl = document.getElementById('loc-breadcrumb');
    const tilesEl = document.getElementById('loc-tiles');
    if (!breadcrumbEl || !tilesEl) return;

    const fUsine = document.getElementById('f-usine');
    const fSecteur = document.getElementById('f-secteur');
    const fLigne = document.getElementById('f-ligne');
    const fZone = document.getElementById('f-zone');
    const fEquip = document.getElementById('f-equip');
    const isEquipSelect = fEquip && fEquip.tagName === 'SELECT';

    if (!isEquipSelect) {
        // Mode saisie libre (aucune machine en base) : pas de picker visuel à afficher.
        breadcrumbEl.innerHTML = '';
        tilesEl.innerHTML = '';
        return;
    }

    const usine = fUsine.value;
    const secteur = fSecteur.value;
    const ligneHasChoices = locHasLigneChoices();
    const ligne = (ligneHasChoices && fLigne.value && fLigne.value !== 'N/A') ? fLigne.value : '';
    const zone = fZone.value;
    const equip = fEquip.value;

    updateMachineStatsBox(equip);

    // --- Fil d'Ariane ---
    breadcrumbEl.innerHTML = '';
    const addCrumb = (label, current, level, isLast) => {
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'loc-crumb' + (current === '' ? ' is-current' : '');
        btn.textContent = current || label;
        btn.addEventListener('click', () => locGoBackTo(level));
        breadcrumbEl.appendChild(btn);
        if (!isLast) {
            const sep = document.createElement('i');
            sep.className = 'fa-solid fa-chevron-right loc-crumb-sep';
            breadcrumbEl.appendChild(sep);
        }
    };
    addCrumb(I18N_MAINT.label_usine, usine, 'usine', !usine);
    if (usine) addCrumb(I18N_MAINT.crumb_secteur, secteur, 'secteur', !secteur);
    if (secteur && ligneHasChoices) addCrumb(I18N_MAINT.label_ligne, ligne, 'ligne', !ligne);
    if (secteur && (!ligneHasChoices || ligne)) addCrumb(I18N_MAINT.label_zone, zone, 'zone', !(zone && equip));
    if (zone && equip) addCrumb(I18N_MAINT.label_machine, equip, 'equip', true);

    // --- Détermination du niveau à afficher en tuiles ---
    let level;
    if (!usine) level = 'usine';
    else if (!secteur) level = 'secteur';
    else if (ligneHasChoices && !ligne) level = 'ligne';
    else if (!zone) level = 'zone';
    else if (!equip) level = 'equip';
    else level = 'done';

    tilesEl.innerHTML = '';

    if (level === 'done') {
        const card = document.createElement('div');
        card.className = 'loc-done-card';
        const path = [usine, secteur, ligne, zone].filter(Boolean).join(' > ');
        card.innerHTML = `
            <div class="loc-done-info">
                <div class="loc-done-icon"><i class="fa-solid fa-circle-check"></i></div>
                <div>
                    <div class="loc-done-name"></div>
                    <div class="loc-done-path"></div>
                </div>
            </div>
            <button type="button" class="loc-done-change"><i class="fa-solid fa-rotate"></i> ${I18N_MAINT.change_machine}</button>
        `;
        card.querySelector('.loc-done-name').textContent = equip;
        card.querySelector('.loc-done-path').textContent = path;
        card.querySelector('.loc-done-change').addEventListener('click', () => locGoBackTo('zone'));
        tilesEl.appendChild(card);
        return;
    }

    const options = locOptionsFor(level);
    if (options.length === 0) {
        const msg = document.createElement('div');
        msg.className = 'loc-empty-msg';
        msg.textContent = I18N_MAINT.no_options;
        tilesEl.appendChild(msg);
        return;
    }

    const pickFns = { usine: locPickUsine, secteur: locPickSecteur, ligne: locPickLigne, zone: locPickZone, equip: locPickEquip };
    options.forEach(o => {
        const tile = document.createElement('button');
        tile.type = 'button';
        tile.className = 'loc-tile';
        const icon = document.createElement('div');
        icon.className = 'loc-tile-icon';
        icon.innerHTML = `<i class="fa-solid ${locTileIcon(level)}"></i>`;
        const label = document.createElement('div');
        label.className = 'loc-tile-label';
        label.textContent = o.textContent;
        tile.appendChild(icon);
        tile.appendChild(label);
        tile.addEventListener('click', () => pickFns[level](o.value));
        tilesEl.appendChild(tile);
    });
}

// ============================================================================
// ASSISTANT INTELLIGENT DU WIZARD : historique machine + détection de doublons
// Purement client (s'appuie sur "tasks", déjà chargé en mémoire par loadData()) —
// pas d'IA externe, juste un recoupement par mots-clés. Non bloquant : ça informe,
// ça ne bloque jamais la création du bon.
// ============================================================================
// On s'appuie sur getStatusSlug() (déjà utilisé pour les badges) plutôt que sur les libellés
// en dur : les statuts sont configurables depuis Paramètres, "Terminée" peut être renommé.
function wizardSmartEstFerme(statut) {
    const slug = getStatusSlug(statut);
    return slug === 'termine' || slug === 'refuse';
}
const WIZARD_SMART_STOPWORDS = new Set(['le','la','les','un','une','des','de','du','et','en','à','au','aux','sur','pour','avec','ne','pas','est','sont','a','ont','se','sa','son','ses','ce','cette','ces','qui','que','dans','par','plus','ou','il','elle','ils','elles','on','nous','vous','je','tu','y','été','être','fait','faire','tout','toute','encore','mais','donc','car']);

function wizardSmartNormalize(s) {
    return (s || '').toString().toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '');
}
function wizardSmartTokenize(s) {
    return wizardSmartNormalize(s).split(/[^a-z0-9]+/).filter(t => t.length >= 3 && !WIZARD_SMART_STOPWORDS.has(t));
}
function wizardSmartSimilarity(tokensA, tokensB) {
    if (tokensA.length === 0 || tokensB.length === 0) return 0;
    const setA = new Set(tokensA), setB = new Set(tokensB);
    let inter = 0;
    setA.forEach(t => { if (setB.has(t)) inter++; });
    const union = new Set([...setA, ...setB]).size;
    return union === 0 ? 0 : inter / union;
}
function wizardSmartDateFr(d) {
    return (d || '').split(' ')[0].split('-').reverse().join('/');
}
function wizardSmartItemHTML(t) {
    return `<div class="wizard-smart-item" onclick="ouvrirBonExistantOT('${t.id}')">
        ${getStatusBadgeHTML(t, false)}
        <span class="wizard-smart-item-desc">${(t.desc || I18N_MAINT.sans_description).toString().replace(/</g, '&lt;')}</span>
        <span class="wizard-smart-item-meta">${(t.num_bi || '')} · ${wizardSmartDateFr(t.date)}</span>
    </div>`;
}

function updateMachineStatsBox(equip) {
    const box = document.getElementById('machine-stats-box');
    if (!box) return;
    if (!equip) { box.style.display = 'none'; box.innerHTML = ''; return; }

    const currentId = document.getElementById('f-id').value;
    const historique = tasks.filter(t => t.equip === equip && t.id != currentId);
    if (historique.length === 0) { box.style.display = 'none'; box.innerHTML = ''; return; }

    const ouverts = historique.filter(t => !wizardSmartEstFerme(t.statut));
    const triee = [...historique].sort((a, b) => (b.date || '').localeCompare(a.date || ''));

    box.className = 'wizard-smart-box' + (ouverts.length > 0 ? ' is-warning' : '');
    box.style.display = 'flex';
    box.innerHTML = `
        <div class="wizard-smart-box-icon"><i class="fa-solid fa-clock-rotate-left"></i></div>
        <div class="wizard-smart-box-body">
            <b>${historique.length} ${I18N_MAINT.machine_history_intro}</b> ${I18N_MAINT.machine_history_deja_enreg}${ouverts.length > 0 ? I18N_MAINT.machine_history_dont_ouverts.replace('{n}', '<b>' + ouverts.length + '</b>') : I18N_MAINT.machine_history_tous_clotures}
            <div class="wizard-smart-list">${triee.map(wizardSmartItemHTML).join('')}</div>
        </div>
    `;
}

let wizardDescTimer = null;
function onDescInputOT() {
    clearTimeout(wizardDescTimer);
    wizardDescTimer = setTimeout(updateSimilarOTBox, 300);
}

function updateSimilarOTBox() {
    const box = document.getElementById('similar-ot-box');
    if (!box) return;
    const descEl = document.getElementById('f-desc');
    const equipEl = document.getElementById('f-equip');
    if (!descEl) return;

    const tokens = wizardSmartTokenize(descEl.value);
    if (tokens.length === 0) { box.style.display = 'none'; box.innerHTML = ''; return; }

    const currentId = document.getElementById('f-id').value;
    const equip = equipEl ? equipEl.value : '';
    const pool = (equip ? tasks.filter(t => t.equip === equip) : tasks).filter(t => t.id != currentId && t.desc);

    const scored = pool
        .map(t => ({ t, score: wizardSmartSimilarity(tokens, wizardSmartTokenize(t.desc)) }))
        .filter(x => x.score >= 0.25)
        .sort((a, b) => b.score - a.score || (b.t.date || '').localeCompare(a.t.date || ''))
        .slice(0, 3);

    if (scored.length === 0) { box.style.display = 'none'; box.innerHTML = ''; return; }

    box.className = 'wizard-smart-box is-warning';
    box.style.display = 'flex';
    box.innerHTML = `
        <div class="wizard-smart-box-icon"><i class="fa-solid fa-triangle-exclamation"></i></div>
        <div class="wizard-smart-box-body">
            <b>${scored.length > 1 ? I18N_MAINT.similar_found_many : I18N_MAINT.similar_found_one}</b> — ${I18N_MAINT.similar_check}
            ${scored.map(x => wizardSmartItemHTML(x.t)).join('')}
        </div>
    `;
}

async function ouvrirBonExistantOT(id) {
    const t = tasks.find(x => x.id == id);
    const estFerme = t ? wizardSmartEstFerme(t.statut) : false;

    const message = estFerme
        ? I18N_MAINT.confirm_open_existing_closed
        : I18N_MAINT.confirm_open_existing_open;
    const ok = await aspirineConfirm(I18N_MAINT.confirm_open_existing_title, message);
    if (!ok) return;

    closeWizardOT();
    // Un bon déjà clôturé : on montre le rapport (compte-rendu + temps passé), pas l'assistant
    // de création qui atterrit sur l'étape 1 et n'a pas grand-chose à montrer pour un ticket terminé.
    if (estFerme && typeof ouvrirModalCloture === 'function') {
        ouvrirModalCloture(id);
    } else {
        editTask(id);
    }
}

function rechercheMachine(query) {
    const resultsEl = document.getElementById('loc-search-results');
    if (!resultsEl) return;
    query = (query || '').trim().toLowerCase();
    if (!query) { resultsEl.style.display = 'none'; resultsEl.innerHTML = ''; return; }

    const tokens = query.split(/\s+/).filter(Boolean);
    const matches = dbMachines.filter(m => {
        const hay = [m.usine, m.secteur, m.ligne, m.zone, m.nom_machine].filter(Boolean).join(' ').toLowerCase();
        return tokens.every(t => hay.includes(t));
    }).slice(0, 30);

    resultsEl.innerHTML = '';
    if (matches.length === 0) {
        const empty = document.createElement('div');
        empty.className = 'loc-search-empty';
        empty.textContent = I18N_MAINT.no_machine_found;
        resultsEl.appendChild(empty);
        resultsEl.style.display = 'block';
        return;
    }
    matches.forEach(m => {
        const item = document.createElement('div');
        item.className = 'loc-search-item';
        const nameEl = document.createElement('div');
        nameEl.className = 'loc-search-item-name';
        nameEl.textContent = m.nom_machine || I18N_MAINT.unnamed;
        const pathEl = document.createElement('div');
        pathEl.className = 'loc-search-item-path';
        pathEl.textContent = [m.usine, m.secteur, m.ligne, m.zone].filter(Boolean).join(' > ');
        item.appendChild(nameEl);
        item.appendChild(pathEl);
        item.addEventListener('click', () => selectMachineFromSearch(m));
        resultsEl.appendChild(item);
    });
    resultsEl.style.display = 'block';
}

function selectMachineFromSearch(m) {
    document.getElementById('f-usine').value = m.usine || '';
    updateSecteurs();
    document.getElementById('f-secteur').value = m.secteur || '';
    updateLignes();
    if (m.ligne) { document.getElementById('f-ligne').value = m.ligne; }
    updateZones();
    document.getElementById('f-zone').value = m.zone || '';
    updateMachines();
    const fEquip = document.getElementById('f-equip');
    if (fEquip) fEquip.value = m.nom_machine || '';

    const searchInput = document.getElementById('loc-search-input');
    if (searchInput) searchInput.value = '';
    const resultsEl = document.getElementById('loc-search-results');
    if (resultsEl) { resultsEl.style.display = 'none'; resultsEl.innerHTML = ''; }

    renderLocPicker();
}

document.addEventListener('click', (e) => {
    const wrap = document.getElementById('loc-search-field');
    if (wrap && !wrap.contains(e.target)) {
        const resultsEl = document.getElementById('loc-search-results');
        if (resultsEl) resultsEl.style.display = 'none';
    }
});

function openNav(e) { e.stopPropagation(); document.getElementById("mySidebar").style.width = "280px"; }
function closeNav() { document.getElementById("mySidebar").style.width = "0"; }
function checkCloseSidebar(event) { if (document.getElementById("mySidebar").style.width === "280px" && !document.getElementById("mySidebar").contains(event.target)) closeNav(); }

function getStatusSlug(statut) {
    if (!statut) return 'afaire';
    let s = statut.toLowerCase().normalize("NFD").replace(/[\u0300-\u036f]/g, "").replace(/\s+/g, '');
    if (s.includes('refus')) return 'refuse';
    return s.includes('faire') ? 'afaire' : (s.includes('cours') ? 'encours' : (s.includes('termine') ? 'termine' : 'afaire'));
}

function getStatusBadgeHTML(t, isClickable = true) {
    let s = t.statut || "À faire";
    let slug = getStatusSlug(s);
    let isUrgent = (t.prio === "Urgent");
    let sirene = (isUrgent && slug === "afaire") ? "blink-sirene" : "";
    let bell = (isUrgent && slug !== "termine") ? '<i class="fa-solid fa-bell icon-urgent-blink"></i> ' : '';
    // Libellé traduit (LIBELLES) à la place du statut brut stocké en base, sinon un ticket refusé/terminé
    // etc. resterait affiché en français quelle que soit la langue choisie (voir getBadgeHtml).
    const texte = (isUrgent && slug === "afaire") ? I18N_MAINT.badge_urgent : ((LIBELLES[slug] && LIBELLES[slug].label) || s);
    if (isClickable) return `<button class="status-btn st-${slug} ${sirene}" onclick="cycleStatus('${t.id}', '${s}')">${bell}${texte}</button>`;
    return `<span class="status-btn st-${slug} ${sirene}" style="width:auto; padding: 4px 8px; font-size:0.6rem;">${bell}${texte}</span>`;
}

function getTypeBadge(type) {
    let color = '#7f8c8d';
    if(type === 'Préventif') color = '#3498db';
    if(type === 'Chantier') color = '#9b59b6';
    const typeLabel = type === 'Préventif' ? I18N_MAINT.type_preventif : (type === 'Chantier' ? I18N_MAINT.type_chantier : I18N_MAINT.type_curatif);
    return `<span style="padding: 2px 6px; border-radius: 4px; font-weight: bold; color: white; background: ${color}; font-size: 0.6rem; text-transform: uppercase;">${typeLabel}</span>`;
}

function getCasseBadge(casse) {
    if (!casse || casse === "false" || casse === "0") return '<span style="color:#ccc;">-</span>';
    return `<span style="padding: 2px 6px; border-radius: 4px; font-weight: bold; color: white; background: var(--danger); font-size: 0.6rem; text-transform: uppercase;">${I18N_MAINT.casse_yes}</span>`;
}

function filterByValue(filterId, value) { const select = document.getElementById(filterId); if(select) { select.value = value; multiFilter(); } }

function resetAllFilters() {
    // 1. On vide tous les champs de saisie et listes déroulantes de filtrage
    document.querySelectorAll('.col-filter').forEach(f => f.value = "");

    // 1bis. On vide aussi la barre de recherche globale
    const searchEl = document.getElementById('global-search-input');
    if (searchEl) searchEl.value = "";

    // 2. Plus aucun filtre actif : on revient à la vue limitée par défaut (perf), pas aux 349 lignes
    renderHistoriqueBody(false);

    // 3. On rafraîchit l'état du gros bouton de suppression groupée
    if (typeof toggleBulkDeleteBtn === 'function') toggleBulkDeleteBtn();

    setActiveChipOT('tous');
}

function multiFilter() {
    // Un filtre/recherche est actif : il faut chercher dans TOUS les bons, pas seulement
    // dans les N derniers affichés par défaut. On reconstruit le tableau si l'état a changé.
    const needFull = historiqueSearchActive();
    if (needFull !== historiqueFullyRendered) renderHistoriqueBody(needFull);

    let filters = document.querySelectorAll(".col-filter");
    let tr = document.getElementById("tableBody").getElementsByTagName("tr");
    let offset = isAdmin ? 1 : 0;
    const searchEl = document.getElementById('global-search-input');
    const globalSearch = searchEl ? searchEl.value.trim().toLowerCase() : "";
    // Colonnes interrogées par la recherche globale : N° BI, Localisation, Description, Intervenants
    const globalSearchCols = [0+offset, 2+offset, 3+offset, 4+offset];
    for (let i = 0; i < tr.length; i++) {
        let show = true;
        let colIndices = [0+offset, 1+offset, 2+offset, 3+offset, 4+offset, 6+offset, 7+offset, 8+offset];
        colIndices.forEach((colIdx, filterIdx) => {
            let filterVal = filters[filterIdx].value.toLowerCase();
            if (!filterVal) return;
            let td = tr[i].getElementsByTagName("td")[colIdx];
            if (td) {
                let txt = td.textContent || td.innerText;
                // CHIRURGIE : Correction de l'index à 5 pour cibler le menu STATUT
                if (filterIdx === 5 && filterVal === 'message_non_lu') {
                    let badge = tr[i].querySelector('[id^="badge-ticket-"]');
                    if (!badge || badge.style.display === 'none' || !badge.classList.contains('blink-sirene')) show = false;
                } else if (filterIdx === 1) {
                    let formatted = filterVal.split('-').reverse().join('/');
                    if (txt.indexOf(formatted) === -1) show = false;
                } else if (txt.toLowerCase().indexOf(filterVal) === -1) show = false;
            }
        });
        if (show && globalSearch) {
            const matches = globalSearchCols.some(colIdx => {
                const td = tr[i].getElementsByTagName("td")[colIdx];
                const txt = td ? (td.textContent || td.innerText) : "";
                return txt.toLowerCase().indexOf(globalSearch) !== -1;
            });
            if (!matches) show = false;
        }
        tr[i].style.display = show ? "" : "none";
    }
    filtrerCartesHistorique(globalSearch);
    toggleBulkDeleteBtn();
}

// Applique les mêmes filtres (.histo-col-filters + recherche globale) à la vue "cartes" (tablette/
// téléphone) qu'à la vue tableau ci-dessus — mêmes champs, lus cette fois via les data-* posés dans
// buildHistoriqueCardsHtml plutôt que via des <td> de tableau, puisque les cartes n'ont pas de colonnes.
function filtrerCartesHistorique(globalSearch) {
    const cards = document.querySelectorAll('#historiqueCards .bi-card');
    if (!cards.length) return;
    const filters = document.querySelectorAll('.col-filter');
    const [fBi, fDate, fLoc, fDesc, fTech, fStatut, fType, fCasse] = Array.from(filters).map(f => (f.value || '').trim().toLowerCase());

    cards.forEach(card => {
        const d = card.dataset;
        let show = true;
        if (fBi && !(d.bi || '').toLowerCase().includes(fBi)) show = false;
        if (show && fDate) {
            const formatted = fDate.split('-').reverse().join('/');
            if ((d.date || '').indexOf(formatted) === -1) show = false;
        }
        if (show && fLoc && !(d.loc || '').toLowerCase().includes(fLoc)) show = false;
        if (show && fDesc && !(d.desc || '').toLowerCase().includes(fDesc)) show = false;
        if (show && fTech && !(d.tech || '').toLowerCase().includes(fTech)) show = false;
        if (show && fStatut) {
            if (fStatut === 'message_non_lu') {
                const badge = card.querySelector('[id^="badge-ticket-card-"]');
                if (!badge || badge.style.display === 'none' || !badge.classList.contains('blink-sirene')) show = false;
            } else if (!(d.statut || '').toLowerCase().includes(fStatut)) show = false;
        }
        if (show && fType && !(d.type || '').toLowerCase().includes(fType)) show = false;
        if (show && fCasse && (d.casse || '').toLowerCase() !== fCasse) show = false;
        if (show && globalSearch) {
            const blob = `${d.bi} ${d.loc} ${d.desc} ${d.tech}`.toLowerCase();
            if (blob.indexOf(globalSearch) === -1) show = false;
        }
        card.style.display = show ? '' : 'none';
    });
}

function rechercheGlobale() {
    setActiveChipOT(null);
    multiFilter();
}

function toggleAllChecks(source) {
    document.querySelectorAll('.task-check').forEach(cb => { if(cb.closest('tr').style.display !== 'none') cb.checked = source.checked; });
    toggleBulkDeleteBtn();
}

function toggleBulkDeleteBtn() {
    const checked = document.querySelectorAll('.task-check:checked').length;
    const bar = document.getElementById('bulk-actions-bar');
    const btnDel = document.getElementById('bulk-delete-btn');
    const btnPrint = document.getElementById('bulk-print-btn');
    if (bar) bar.style.display = (checked > 0) ? 'flex' : 'none';
    if (btnDel) btnDel.innerHTML = `<i class="fa-solid fa-trash"></i> ` + I18N_MAINT.bulk_delete_btn_n.replace('{n}', checked);
    if (btnPrint) btnPrint.innerHTML = `<i class="fa-solid fa-print"></i> ` + I18N_MAINT.bulk_print_btn_n.replace('{n}', checked);
}

async function deleteSelected() {
    const checked = document.querySelectorAll('.task-check:checked');
    if (checked.length === 0) return;

    // 1. Si l'utilisateur n'est pas un admin, on bloque tout de suite
    if (!isAdmin) {
        await aspirineAlert(I18N_MAINT.access_denied_title, I18N_MAINT.del_no_rights_delete);
        return;
    }

    // 2. On cible le texte de ta modale pour l'adapter au nombre d'éléments cochés
    const modal = document.getElementById('modalConfirmDel');
    const textZone = modal.querySelector('p');

    if (textZone) {
        textZone.innerHTML = `${I18N_MAINT.del_selection_confirm.replace('{n}', '<strong>' + checked.length + '</strong>')}<br><span style="font-weight: bold; color: var(--danger);">${I18N_MAINT.del_irreversible}</span>`;
    }

    // 3. On intercepte le clic sur le gros bouton rouge "SUPPRIMER" de ta modale
    document.getElementById('btnConfirmDeleteFinal').onclick = async function() {
        this.disabled = true;
        this.innerText = I18N_MAINT.del_deleting;

        // Boucle de suppression sur les API
        for (let cb of checked) {
            await fetch('api.php?delete=' + encodeURIComponent(cb.value));
        }

        // On remet la modale et le bouton en état d'origine
        this.disabled = false;
        this.innerText = I18N_MAINT.del_supprimer;
        closeConfirmDel();
        // On décoche le "Tout sélectionner" général s'il existe
        const checkAll = document.getElementById('check-all');
        if(checkAll) checkAll.checked = false;

        // On cache le gros bouton rouge de force
        document.getElementById('bulk-actions-bar').style.display = 'none';
        
        // On recharge les données de la GMAO
        loadData();
    };

    // 4. On ouvre ta superbe modale
    modal.style.display = 'block';
}

function getBadgeHtml(statut, prio) {
    const s = statut || "À faire";
    const slug = getStatusSlug(s); 

    let color = "#7f8c8d"; 
    let icone = "";
    let texte = s;

    if (slug === "enattentevalidation") {
        const lAttente = LIBELLES.attente || { label: 'En attente', couleur: '#95a5a6' };
        return `<span style="background:${lAttente.couleur}; color:white; padding:2px 8px; border-radius:12px; font-size:0.65rem; font-weight:bold; text-transform:uppercase; display:inline-flex; align-items:center; gap:4px; white-space:nowrap;"><i class="fa-solid fa-hourglass-half"></i> ${lAttente.label}</span>`;
    }

    if (slug === "afaire") {
        if (prio === "Urgent") {
            const lUrgent = LIBELLES.urgent || { label: 'Urgent', couleur: '#e74c3c' };
            color = lUrgent.couleur;
            icone = '<i class="fa-solid fa-bell icon-urgent-blink"></i> ';
            texte = lUrgent.label;
        } else {
            const lAfaire = LIBELLES.afaire || { label: 'À faire', couleur: '#f39c12' };
            color = lAfaire.couleur;
            icone = '<i class="fa-solid fa-clipboard-list"></i> ';
            texte = lAfaire.label;
        }
    } else if (slug === "encours") {
        const lEncours = LIBELLES.encours || { label: 'En cours', couleur: '#3498db' };
        color = lEncours.couleur;
        icone = '<i class="fa-solid fa-gears"></i> ';
        texte = lEncours.label;
    } else if (slug === "termine") {
        const lTermine = LIBELLES.termine || { label: 'Terminée', couleur: '#27ae60' };
        color = lTermine.couleur;
        icone = '<i class="fa-solid fa-check-double"></i> ';
        texte = lTermine.label;
    } else if (slug === "refuse") {
        const lRefuse = LIBELLES.refuse || { label: 'Refusée', couleur: '#e74c3c' };
        color = lRefuse.couleur;
        icone = '<i class="fa-solid fa-ban"></i> ';
        texte = lRefuse.label;
    }

    return `<span style="
        background-color: ${color} !important; 
        color: white !important; 
        padding: 2px 8px !important; 
        border-radius: 12px !important; 
        font-size: 0.65rem !important; 
        font-weight: bold !important; 
        text-transform: uppercase !important; 
        display: inline-flex !important; 
        align-items: center !important;
        gap: 4px !important;
        white-space: nowrap !important;
    ">${icone}${texte}</span>`;
}

function render() {
    const statsHeuresSQL = <?php echo json_encode($heures_par_tech); ?>;
    
    // --- NOUVEAU FILTRE PAR PLAGE DE DATES ---
    const dateDebut = document.getElementById('filter-date-debut') ? document.getElementById('filter-date-debut').value : "";
    const dateFin = document.getElementById('filter-date-fin') ? document.getElementById('filter-date-fin').value : "";
    
    let ft = [...tasks];
    if (dateDebut) {
        ft = ft.filter(t => t.date.split(' ')[0] >= dateDebut);
    }
    if (dateFin) {
        ft = ft.filter(t => t.date.split(' ')[0] <= dateFin);
    }
    
    // --- DÉTECTION DU SAS ET MISE À JOUR DU BANDEAU ROUGE ---
    const nbEnAttenteReel = tasks.filter(t => (!t.num_bi || t.num_bi === "") && t.statut !== "Refusée").length;
    rafraichirNotificationSas(nbEnAttenteReel);

    // --- CHIRURGIE : CALCUL DES COMPTEURS (STATUTS + TYPES + CASSE) ---
    const ticketsValides = ft.filter(t => t.num_bi && t.num_bi !== "");
    
    // 1. Calculs des Statuts
    const countTotal   = ticketsValides.length;
    const countUrgent  = ticketsValides.filter(t => t.prio === "Urgent" && getStatusSlug(t.statut) === 'afaire').length;
    const countAfaire  = ticketsValides.filter(t => t.prio !== "Urgent" && getStatusSlug(t.statut) === 'afaire').length;
    const countEncours = ticketsValides.filter(t => getStatusSlug(t.statut) === 'encours').length;
    const countTermine = ticketsValides.filter(t => getStatusSlug(t.statut) === 'termine').length;
    
    // NOUVEAU : Calcul des "Non assignés" (Tickets sans aucun pointage)
    const countNonAssigne = ticketsValides.filter(t => !pointages.some(p => p.task_id === t.id)).length;

    // Taux de réalisation : part des bons Terminés parmi tous les bons — sert d'indicateur
    // d'alerte pour les techniciens (vert si ça va bien, orange si stable, rouge si ça décroche).
    const tauxRealisation = countTotal > 0 ? Math.round((countTermine / countTotal) * 100) : 100;
    let tauxIcon, tauxColorVar, tauxRgb;
    if (tauxRealisation >= 90) { tauxIcon = 'fa-arrow-trend-up'; tauxColorVar = 'var(--brand-green)'; tauxRgb = '46,204,113'; }
    else if (tauxRealisation >= 85) { tauxIcon = 'fa-minus'; tauxColorVar = 'var(--brand-orange)'; tauxRgb = '243,156,18'; }
    else { tauxIcon = 'fa-arrow-trend-down'; tauxColorVar = 'var(--danger)'; tauxRgb = '231,76,60'; }

    // 2. Calculs des Types & Casse
    const countPreventif = ticketsValides.filter(t => t.type === "Préventif").length;
    const countCuratif   = ticketsValides.filter(t => !t.type || t.type === "Curatif").length;
    const countCasse     = ticketsValides.filter(t => t.casse == 1 || t.casse === "1" || t.casse === true || t.casse === "true").length;
    const countVerifVis  = ticketsValides.filter(t => t.verif_vis == 1 || t.verif_vis === "1" || t.verif_vis === true || t.verif_vis === "true").length;

    const recapGlobalArea = document.getElementById('globalRecap');
    if (recapGlobalArea) {
        // Mise en page (grille responsive) entièrement gérée par la classe CSS .recap-bar —
        // ne plus fixer de style inline ici, ça écraserait la grille (c'était le bug des
        // vignettes empilées en pleine largeur : l'ancien inline style venait d'un temps où
        // #globalRecap empilait 2 rangées de puces en flex-direction:column).

        recapGlobalArea.innerHTML = `
            <div class="kpi-big-tile" onclick="resetAllFilters()" title="${I18N_MAINT.kpi_bons_total.replace('{n}', countTotal)}" style="--tile-color:${tauxColorVar}; --tile-rgb:${tauxRgb};">
                <div class="kpi-big-icon"><i class="fa-solid ${tauxIcon}"></i></div>
                <div class="kpi-big-value">${tauxRealisation}%</div>
                <div class="kpi-big-label">${I18N_MAINT.kpi_taux_realisation}</div>
                <div class="kpi-big-sub">${I18N_MAINT.kpi_bons_total.replace('{n}', countTotal)}</div>
            </div>
            <div class="recap-rows-wrap">
            <div class="recap-row">
                <div class="recap-item" data-chip-key="tous" onclick="resetAllFilters()" style="--tile-color:#7f8c8d; --tile-rgb:127,140,141;">
                    <div class="kpi-tile-icon"><i class="fa-solid fa-list"></i></div>
                    <div class="kpi-tile-label">${I18N_MAINT.kpi_tous_les_bons}</div>
                    <div class="kpi-tile-value">${countTotal}</div>
                </div>
                <div class="recap-item" data-chip-key="statut:Urgent" onclick="filtrerStatutBouton('Urgent')" style="--tile-color:var(--danger); --tile-rgb:231,76,60;">
                    <div class="kpi-tile-icon"><i class="fa-solid fa-bell ${countUrgent > 0 ? 'icon-urgent-blink' : ''}"></i></div>
                    <div class="kpi-tile-label">${I18N_MAINT.kpi_urgent}</div>
                    <div class="kpi-tile-value">${countUrgent}</div>
                </div>
                <div class="recap-item" data-chip-key="statut:À faire" onclick="filtrerStatutBouton('À faire')" style="--tile-color:var(--brand-orange); --tile-rgb:243,156,18;">
                    <div class="kpi-tile-icon"><i class="fa-solid fa-clipboard-list"></i></div>
                    <div class="kpi-tile-label">${I18N_MAINT.kpi_a_faire}</div>
                    <div class="kpi-tile-value">${countAfaire}</div>
                </div>
                <div class="recap-item" data-chip-key="statut:En cours" onclick="filtrerStatutBouton('En cours')" style="--tile-color:var(--accent); --tile-rgb:52,152,219;">
                    <div class="kpi-tile-icon"><i class="fa-solid fa-gears"></i></div>
                    <div class="kpi-tile-label">${I18N_MAINT.kpi_en_cours}</div>
                    <div class="kpi-tile-value">${countEncours}</div>
                </div>
                <div class="recap-item" data-chip-key="statut:Terminée" onclick="filtrerStatutBouton('Terminée')" style="--tile-color:var(--brand-green); --tile-rgb:46,204,113;">
                    <div class="kpi-tile-icon"><i class="fa-solid fa-check-double"></i></div>
                    <div class="kpi-tile-label">${I18N_MAINT.kpi_termine}</div>
                    <div class="kpi-tile-value">${countTermine}</div>
                </div>
                <div id="btn-recap-messages" class="recap-item" data-chip-key="statut:MESSAGE_NON_LU" onclick="filtrerStatutBouton('MESSAGE_NON_LU')" style="display:none; --tile-color:var(--danger); --tile-rgb:231,76,60;">
                    <div class="kpi-tile-icon"><i class="fa-solid fa-envelope"></i></div>
                    <div class="kpi-tile-label">${I18N_MAINT.kpi_messages_non_lus}</div>
                    <div class="kpi-tile-value" id="compteur-global-messages">0</div>
                </div>
            </div>
            <div class="recap-row">
                <div class="recap-item" data-chip-key="non_assigne" onclick="filtrerNonAssigneBouton()" style="--tile-color:#95a5a6; --tile-rgb:149,165,166;">
                    <div class="kpi-tile-icon"><i class="fa-solid fa-user-xmark"></i></div>
                    <div class="kpi-tile-label">${I18N_MAINT.kpi_non_assigne}</div>
                    <div class="kpi-tile-value">${countNonAssigne}</div>
                </div>
                <div class="recap-item" data-chip-key="type:Préventif" onclick="filtrerTypeOuCasseBouton('filter-type', 'Préventif')" style="--tile-color:#3498db; --tile-rgb:52,152,219;">
                    <div class="kpi-tile-icon"><i class="fa-solid fa-calendar-check"></i></div>
                    <div class="kpi-tile-label">${I18N_MAINT.kpi_preventif}</div>
                    <div class="kpi-tile-value">${countPreventif}</div>
                </div>
                <div class="recap-item" data-chip-key="type:Curatif" onclick="filtrerTypeOuCasseBouton('filter-type', 'Curatif')" style="--tile-color:#e74c3c; --tile-rgb:231,76,60;">
                    <div class="kpi-tile-icon"><i class="fa-solid fa-screwdriver-wrench"></i></div>
                    <div class="kpi-tile-label">${I18N_MAINT.kpi_curatif}</div>
                    <div class="kpi-tile-value">${countCuratif}</div>
                </div>
                <div class="recap-item" data-chip-key="casse" onclick="filtrerTypeOuCasseBouton('filter-casse', 'CASSE')" style="--tile-color:var(--stat-red); --tile-rgb:192,57,43;">
                    <div class="kpi-tile-icon"><i class="fa-solid fa-bolt"></i></div>
                    <div class="kpi-tile-label">${I18N_MAINT.kpi_materiel_casse}</div>
                    <div class="kpi-tile-value">${countCasse}</div>
                </div>
                <div class="recap-item" data-chip-key="verif_vis" onclick="filtrerVisBouton()" style="--tile-color:#8e44ad; --tile-rgb:142,68,173;">
                    <div class="kpi-tile-icon"><i class="fa-solid fa-screwdriver"></i></div>
                    <div class="kpi-tile-label">${I18N_MAINT.kpi_verif_visserie}</div>
                    <div class="kpi-tile-value">${countVerifVis}</div>
                </div>
            </div>
            </div>
        `;
        applyActiveChipOT();
    }

    const area = document.getElementById('statsArea');
    if(area) {
        // On mémorise tous les tickets qui ont au moins 1 pointage
        const allTaskIdsWithPointages = new Set(pointages.map(ptg => ptg.task_id));

        area.innerHTML = team.map(m => {
            const taskIdsWork = pointages.filter(p => p.tech === m.name).map(p => p.task_id);
            
            const tList = ft.filter(x => {
                if (x.statut === "EN ATTENTE VALIDATION") return false;
                if (taskIdsWork.includes(x.id)) return true; // Condition A : Il a pointé dessus
                if (!allTaskIdsWithPointages.has(x.id) && x.tech === m.name) return true; // Condition B : Il est déclarant ET personne n'a encore pointé
                return false;
            });
            
            // On calcule les heures en temps réel à partir des pointages rechargés par le JS
            const h = pointages.filter(p => p.tech === m.name).reduce((sum, p) => sum + parseFloat(p.hours || 0), 0);
            
            const nbTachesEnCours = tList.filter(x => getStatusSlug(x.statut) !== 'termine').length;

            return `<div class="stat-card" onclick="openHistory('${m.name}')">
                <img src="${m.photo || 'img/user.png'}" class="avatar-img" onerror="this.src='https://api.dicebear.com/7.x/initials/svg?seed=${m.name}'">
                <b>${m.name}</b><span style="font-size:0.6rem; color:var(--accent); display:block;">${m.role}</span>
                
                <div class="stat-row" style="color:#c0392b;"><span><i class="fa-solid fa-bell"></i> ${I18N_MAINT.kpi_urgent}</span><b>${tList.filter(x => x.prio==="Urgent" && getStatusSlug(x.statut)==='afaire').length}</b></div>
                <div class="stat-row" style="color:#d35400;"><span><i class="fa-solid fa-clipboard-list"></i> ${I18N_MAINT.kpi_a_faire}</span><b>${tList.filter(x => x.prio!=="Urgent" && getStatusSlug(x.statut)==='afaire').length}</b></div>
                <div class="stat-row" style="color:#2980b9;"><span><i class="fa-solid fa-spinner"></i> ${I18N_MAINT.kpi_en_cours}</span><b>${tList.filter(x => getStatusSlug(x.statut)==='encours').length}</b></div>
                <div class="stat-row" style="color:#27ae60;"><span><i class="fa-solid fa-check-circle"></i> ${I18N_MAINT.kpi_termine}</span><b>${tList.filter(x => getStatusSlug(x.statut)==='termine').length}</b></div>

                <div class="stat-footer">
                    <span>${I18N_MAINT.stat_taches_en_cours.replace('{n}', nbTachesEnCours)}</span>
                    <span style="font-weight:bold;">${parseFloat(h).toFixed(1)}h</span>
                </div>
            </div>`;
        }).join('');
    }

    historiqueSorted = ft.filter(t => t.num_bi && t.num_bi !== "").sort((a,b) => b.num_bi.localeCompare(a.num_bi));
    renderHistoriqueBody();

    const fT = document.getElementById('f-tech');
    if(fT && !fT.innerHTML) {
        // "GMAO" en tête de liste : déclarant générique pour un BI créé directement en GMAO (sans
        // demandeur externe), plutôt que de forcer le nom d'un technicien précis.
        fT.innerHTML = `<option value="GMAO">GMAO</option>` + team.map(m => `<option value="${m.name}">${m.name}</option>`).join('');
        fT.value = currentUser;

        if (typeof isAdmin !== 'undefined' && !isAdmin) {
            fT.disabled = true;
            fT.style.background = "#f1f2f6";
            fT.style.cursor = "not-allowed";
        }
    }

    // --- INTERVENANTS PRÉVUS : menu déroulant à cases à cocher (plusieurs techniciens possibles),
    // ou "Non assigné" pour laisser le technicien s'auto-assigner la tâche plus tard. ---
    const fAssignList = document.getElementById('f-assign-tech-list');
    if (fAssignList && !fAssignList.innerHTML) {
        fAssignList.innerHTML = `
            <label style="display:flex; align-items:center; gap:8px; font-weight:bold; color:#64748b; cursor:pointer; padding:5px 6px; border-radius:4px; font-size:0.85rem;" onmouseover="this.style.background='#f8fafc'" onmouseout="this.style.background=''">
                <input type="checkbox" class="assign-tech-cb" value="" checked style="width:16px; height:16px; flex-shrink:0; margin:0;"> ${I18N_MAINT.opt_non_assigne}
            </label>` +
            team.map(m => `
            <label style="display:flex; align-items:center; gap:8px; cursor:pointer; padding:5px 6px; border-radius:4px; font-size:0.85rem;" onmouseover="this.style.background='#f8fafc'" onmouseout="this.style.background=''">
                <input type="checkbox" class="assign-tech-cb" value="${m.name}" style="width:16px; height:16px; flex-shrink:0; margin:0;"> ${m.name}
            </label>`).join('');

        // Cocher "Non assigné" décoche tous les techniciens (et inversement) : les deux sont exclusifs.
        fAssignList.addEventListener('change', function(e) {
            if (!e.target.classList.contains('assign-tech-cb')) return;
            const cbs = fAssignList.querySelectorAll('.assign-tech-cb');
            if (e.target.value === '') {
                if (e.target.checked) cbs.forEach(cb => { if (cb.value !== '') cb.checked = false; });
            } else if (e.target.checked) {
                const nonAssigne = fAssignList.querySelector('.assign-tech-cb[value=""]');
                if (nonAssigne) nonAssigne.checked = false;
            }
            updateAssignTechSummary();
        });
        updateAssignTechSummary();
    }

    const fiT = document.getElementById('filter-tech');
    if(fiT && fiT.options.length <= 1) team.forEach(m => { let o = document.createElement('option'); o.value = m.name; o.innerText = m.name; fiT.appendChild(o); });
}

// Noms des techniciens cochés dans "Intervenants prévus" (vide si "Non assigné").
function getIntervenantsPrevusCoches() {
    const list = document.getElementById('f-assign-tech-list');
    if (!list) return [];
    return Array.from(list.querySelectorAll('.assign-tech-cb:checked')).map(cb => cb.value).filter(v => v !== '');
}

// Coche "Non assigné" et décoche tous les techniciens (état par défaut / réinitialisation).
function cocherNonAssigne() {
    const list = document.getElementById('f-assign-tech-list');
    if (!list) return;
    list.querySelectorAll('.assign-tech-cb').forEach(cb => { cb.checked = (cb.value === ''); });
    updateAssignTechSummary();
}

// Coche uniquement les techniciens de `noms` (utilisé au chargement d'un BI existant en modification).
function cocherIntervenantsPrevus(noms) {
    const list = document.getElementById('f-assign-tech-list');
    if (!list) return;
    const set = new Set(noms);
    let auMoinsUn = false;
    list.querySelectorAll('.assign-tech-cb').forEach(cb => {
        if (cb.value === '') return;
        cb.checked = set.has(cb.value);
        if (cb.checked) auMoinsUn = true;
    });
    const nonAssigne = list.querySelector('.assign-tech-cb[value=""]');
    if (nonAssigne) nonAssigne.checked = !auMoinsUn;
    updateAssignTechSummary();
}

// Texte affiché sur le bouton fermé du menu déroulant "Intervenants prévus".
function updateAssignTechSummary() {
    const summary = document.getElementById('f-assign-tech-summary');
    if (!summary) return;
    const coches = getIntervenantsPrevusCoches();
    summary.textContent = coches.length ? coches.join(', ') : I18N_MAINT.opt_non_assigne;
}

// Ouvre/ferme le menu déroulant "Intervenants prévus" ; se ferme au clic en dehors.
function toggleAssignTechDropdown(e) {
    if (e) e.stopPropagation();
    const list = document.getElementById('f-assign-tech-list');
    if (!list) return;
    const ouvert = list.style.display === 'block';
    list.style.display = ouvert ? 'none' : 'block';
}
document.addEventListener('click', function(e) {
    const list = document.getElementById('f-assign-tech-list');
    const toggle = document.getElementById('f-assign-tech-toggle');
    if (!list || list.style.display !== 'block') return;
    if (list.contains(e.target) || (toggle && toggle.contains(e.target))) return;
    list.style.display = 'none';
});

// ============================================================================
// HISTORIQUE GLOBAL : construction des lignes + limite d'affichage 25/50/75/...
// ============================================================================
function buildHistoriqueRowsHtml(list) {
    return list.map(t => {
        let taskPointages = pointages.filter(p => p.task_id === t.id);
        let dur = taskPointages.reduce((sum, p) => sum + parseFloat(p.hours), 0) || 0;
        let techsSet = new Set(taskPointages.map(p => p.tech));
        // Le déclarant (t.tech) n'est PAS forcément l'intervenant : depuis les cases à cocher
        // "Intervenants prévus", seuls les pointages (même à 0h, posés comme "réservé pour")
        // font foi ici. Sans ça, un BI explicitement laissé "Non assigné" affichait quand même
        // le nom du déclarant, contredisant le choix fait dans l'assistant.
        let intervenants = Array.from(techsSet).join(', ') || '-';

        let s = t.statut || "À faire";
        let sbadge = getBadgeHtml(s, t.prio);
        const statusSlug = getStatusSlug(s);
        const isTerminee = (statusSlug === 'termine');

        const btnTempsHtml = isTerminee
        ? `<div style="font-size:0.65rem; color:#27ae60; font-weight:bold; margin-top:2px;"><i class="fa-solid fa-lock"></i> ${I18N_MAINT.saisie_close}</div>`
        : `<button onclick="event.stopPropagation(); ouvrirModalPointage('${t.id}')"
            style="background-color: var(--dark-blue); color: white; border: none; border-radius: 4px; padding: 2px 6px; font-size: 0.65rem; font-weight: bold; cursor: pointer; margin-top: 4px; display: inline-flex; align-items: center; gap: 3px; white-space: nowrap;">
            <i class="fa-solid fa-clock" style="font-size: 0.6rem;"></i> ${I18N_MAINT.plus_temps}
           </button>`;

        let typeHtml = (t.type === 'Préventif') ? `<span style="color:var(--accent); font-weight:bold; font-size:0.78rem; white-space:nowrap;"><i class="fa-solid fa-calendar-check"></i> ${I18N_MAINT.type_preventif}</span>`
                     : (t.type === 'Chantier') ? `<span style="color:#f39c12; font-weight:bold; font-size:0.78rem; white-space:nowrap;"><i class="fa-solid fa-person-digging"></i> ${I18N_MAINT.type_chantier}</span>`
                     : `<span style="color:#e74c3c; font-weight:bold; font-size:0.78rem; white-space:nowrap;"><i class="fa-solid fa-screwdriver-wrench"></i> ${I18N_MAINT.type_curatif}</span>`;

        let casseHtml = (t.casse == 1 || t.casse === "1" || t.casse === true || t.casse === "true")
        ? `<span style="color:var(--danger); font-weight:bold; font-size:0.8rem; white-space:nowrap;"><i class="fa-solid fa-bolt"></i> ${I18N_MAINT.recap_oui}</span>`
        : '<span style="color:#bdc3c7; font-size:0.8rem;">-</span>';

        return `
    <tr data-verif="${(t.verif_vis == 1 || t.verif_vis === "1" || t.verif_vis === true || t.verif_vis === "true") ? '1' : '0'}">
        ${typeof isAdmin !== 'undefined' && isAdmin ? `<td class="tc-tight" onclick="event.stopPropagation();"><input type="checkbox" class="task-check" value="${t.id}" onchange="toggleBulkDeleteBtn()"></td>` : ''}

        <td class="tc-tight gap-r" onclick="showDetailBI('${t.id}')" style="font-weight:bold; color:var(--brand-orange); text-decoration:underline; cursor:pointer; position:relative; border-bottom:none;">
            <div style="display:flex; align-items:center; justify-content:center; gap:5px; white-space:nowrap;">
                ${t.num_bi || '-'}
                <span id="badge-ticket-${t.id}" class="badge-msg-count" title="${I18N_MAINT.msg_count_tooltip}">0</span>
            </div>
        </td>

        <td class="tc-tight gap-r">
            <div style="font-weight:600; color:#455a64; white-space:nowrap;">${t.date.split(' ')[0].split('-').reverse().join('/')}</div>
        </td>

        <td style="text-align:left; line-height:1.2; overflow:hidden;">
    ${(t.usine || t.secteur || t.ligne || t.zone) ? `<div class="cell-loc-line" style="font-size:0.75rem; color:#64748b; font-weight:normal; margin-bottom:3px;" title="${t.usine || '?'} > ${t.secteur || '?'} > ${t.ligne ? t.ligne + ' > ' : ''}${t.zone || '?'}">${t.usine || '?'} > ${t.secteur || '?'} > ${t.ligne ? t.ligne + ' > ' : ''}${t.zone || '?'}</div>` : ''}
    <div class="cell-loc-line" style="font-weight:600; color:var(--primary); font-size:0.85rem;" title="${(t.equip || '').replace(/"/g, '&quot;')}"><i class="fa-solid fa-microchip" style="font-size:0.7rem; opacity:0.5; margin-right:4px;"></i>${t.equip}</div>
</td>

        <td class="cell-desc-tronquee" title="${(t.desc || '').replace(/"/g, '&quot;')}" style="text-align:left; font-size:0.75rem;">
    ${t.desc || '-'}
</td>

        <td class="gap-r" style="font-size:0.85rem; color:#2c3e50;">
            <div style="display: flex; align-items: center; gap: 6px;">
                <i class="fa-solid fa-wrench" style="font-size:0.7rem; color:var(--accent); opacity:0.8;"></i>
                <span style="font-weight:600;">${intervenants}</span>
            </div>
        </td>

        <td class="tc-tight gap-r" onclick="event.stopPropagation();" style="text-align: center;">
            <div style="font-weight:bold; color:var(--dark-blue); font-size: 0.9rem; white-space:nowrap;">${dur.toFixed(2)}h</div>
            ${btnTempsHtml}
        </td>

        <td class="gap-r" style="text-align:center;">
            <div onclick="event.stopPropagation(); window.cycleStatus('${t.id}', '${s}')" style="cursor:pointer; display:inline-block; padding:5px;">${sbadge}</div>
        </td>

        <td class="tc-tight">${typeHtml}</td>

        <td class="tc-tight">${casseHtml}</td>

        <td class="tc-tight" onclick="event.stopPropagation();" style="white-space:nowrap;">
            <button onclick="imprimerFicheTache('${t.id}')" title="${I18N_MAINT.btn_fiche_tache}" style="border:none; background:none; cursor:pointer;"><i class="fa-solid fa-print" style="color:#64748b"></i></button>
            <button onclick="editTask('${t.id}')" style="border:none; background:none; cursor:pointer; margin-left:6px;"><i class="fa-solid fa-pen-to-square" style="color:var(--accent)"></i></button>
            ${typeof isAdmin !== 'undefined' && isAdmin ? `<button onclick="demanderSuppression('${t.id}')" style="border:none; background:none; cursor:pointer; margin-left:6px;"><i class="fa-solid fa-trash" style="color:var(--danger)"></i></button>` : ''}
        </td>
    </tr>`;
    }).join('');
}

function historiqueSearchActive() {
    const search = document.getElementById('global-search-input');
    if (search && search.value.trim()) return true;
    return Array.from(document.querySelectorAll('.col-filter')).some(f => f.value && String(f.value).trim());
}

function renderHistoriqueBody(forceFull) {
    const body = document.getElementById('tableBody');
    if (!body) return;
    const full = !!forceFull || historiqueSearchActive();
    const list = full ? historiqueSorted : historiqueSorted.slice(0, historiqueLimit);
    body.innerHTML = buildHistoriqueRowsHtml(list);
    const cards = document.getElementById('historiqueCards');
    if (cards) cards.innerHTML = buildHistoriqueCardsHtml(list);
    historiqueFullyRendered = full;
    updateHistoriqueLimitLabel();
}

// Version "carte" d'un bon d'intervention pour tablette/téléphone (voir .bi-cards-grid) : mêmes données
// et mêmes actions que la ligne de tableau (buildHistoriqueRowsHtml), juste réarrangées verticalement.
function buildHistoriqueCardsHtml(list) {
    return list.map(t => {
        let taskPointages = pointages.filter(p => p.task_id === t.id);
        let dur = taskPointages.reduce((sum, p) => sum + parseFloat(p.hours), 0) || 0;
        let techsSet = new Set(taskPointages.map(p => p.tech));
        // Le déclarant (t.tech) n'est PAS forcément l'intervenant : depuis les cases à cocher
        // "Intervenants prévus", seuls les pointages (même à 0h, posés comme "réservé pour")
        // font foi ici. Sans ça, un BI explicitement laissé "Non assigné" affichait quand même
        // le nom du déclarant, contredisant le choix fait dans l'assistant.
        let intervenants = Array.from(techsSet).join(', ') || '-';

        let s = t.statut || "À faire";
        let sbadge = getBadgeHtml(s, t.prio);
        const statusSlug = getStatusSlug(s);
        const isTerminee = (statusSlug === 'termine');
        const accentColor = (LIBELLES[statusSlug] && LIBELLES[statusSlug].couleur) || '#7f8c8d';

        const locLine = (t.usine || t.secteur || t.ligne || t.zone)
            ? `${t.usine || '?'} > ${t.secteur || '?'} > ${t.ligne ? t.ligne + ' > ' : ''}${t.zone || '?'}` : '';

        const typeMeta = (t.type === 'Préventif') ? `<span style="color:var(--accent); font-weight:bold;"><i class="fa-solid fa-calendar-check"></i> ${I18N_MAINT.type_preventif}</span>`
                        : (t.type === 'Chantier') ? `<span style="color:#f39c12; font-weight:bold;"><i class="fa-solid fa-person-digging"></i> ${I18N_MAINT.type_chantier}</span>`
                        : `<span style="color:#e74c3c; font-weight:bold;"><i class="fa-solid fa-screwdriver-wrench"></i> ${I18N_MAINT.type_curatif}</span>`;

        const aCasse = (t.casse == 1 || t.casse === "1" || t.casse === true || t.casse === "true");
        const casseMeta = aCasse ? `<span style="color:var(--danger); font-weight:bold;"><i class="fa-solid fa-bolt"></i> ${I18N_MAINT.casse_yes}</span>` : '';

        const btnTemps = isTerminee
            ? '' : `<button onclick="event.stopPropagation(); ouvrirModalPointage('${t.id}')" style="background-color:var(--dark-blue); color:white; border:none; border-radius:4px; padding:2px 6px; font-size:0.65rem; font-weight:bold; cursor:pointer;"><i class="fa-solid fa-clock" style="font-size:0.6rem;"></i> ${I18N_MAINT.plus_temps}</button>`;

        // Attributs data-* consommés par filtrerCartesHistorique() (voir plus bas) : la barre de filtres
        // par colonne (.histo-col-filters) est commune au tableau et aux cartes, donc chaque carte doit
        // exposer les mêmes champs filtrables que les <td> du tableau. statutLabel dérive le texte affiché
        // du badge de statut (ex. "À faire") plutôt que le slug brut, pour matcher les valeurs du <select>.
        const statutLabel = sbadge.replace(/<[^>]+>/g, '').trim();
        const dateFmt = t.date.split(' ')[0].split('-').reverse().join('/');
        const locBlob = `${t.usine || ''} ${t.secteur || ''} ${t.ligne || ''} ${t.zone || ''} ${t.equip || ''}`.trim();
        const dataAttrs = `data-bi="${(t.num_bi || '').replace(/"/g, '&quot;')}" data-date="${dateFmt}" data-loc="${locBlob.replace(/"/g, '&quot;')}" data-desc="${(t.desc || '').replace(/"/g, '&quot;')}" data-tech="${intervenants.replace(/"/g, '&quot;')}" data-statut="${statutLabel.replace(/"/g, '&quot;')}" data-type="${t.type || 'Curatif'}" data-casse="${aCasse ? 'CASSE' : '-'}"`;

        return `
    <div class="bi-card" onclick="showDetailBI('${t.id}')" ${dataAttrs}>
        <div class="bi-card-accent" style="background:${accentColor};"></div>
        <div class="bi-card-top">
            <span class="bi-card-num">${t.num_bi || '-'} <span id="badge-ticket-card-${t.id}" class="badge-msg-count" title="${I18N_MAINT.msg_count_tooltip}">0</span></span>
            <span onclick="event.stopPropagation(); window.cycleStatus('${t.id}', '${s}')">${sbadge}</span>
        </div>
        <div class="bi-card-machine"><i class="fa-solid fa-microchip"></i>${t.equip || '-'}</div>
        ${locLine ? `<div class="bi-card-loc">${locLine}</div>` : ''}
        <div class="bi-card-desc">${t.desc || '-'}</div>
        <div class="bi-card-meta">${typeMeta}${casseMeta}</div>
        <div class="bi-card-bottom">
            <span><i class="fa-regular fa-calendar"></i> ${t.date.split(' ')[0].split('-').reverse().join('/')}</span>
            <span><i class="fa-solid fa-wrench"></i> ${intervenants}</span>
            <span><i class="fa-solid fa-hourglass-half"></i> ${dur.toFixed(2)}h ${btnTemps}</span>
        </div>
        <div class="bi-card-actions" onclick="event.stopPropagation();">
            ${typeof isAdmin !== 'undefined' && isAdmin ? `<input type="checkbox" class="task-check" value="${t.id}" onchange="toggleBulkDeleteBtn()">` : ''}
            <button onclick="imprimerFicheTache('${t.id}')" title="${I18N_MAINT.btn_fiche_tache}"><i class="fa-solid fa-print" style="color:#64748b"></i></button>
            <button onclick="editTask('${t.id}')"><i class="fa-solid fa-pen-to-square" style="color:var(--accent)"></i></button>
            ${typeof isAdmin !== 'undefined' && isAdmin ? `<button onclick="demanderSuppression('${t.id}')"><i class="fa-solid fa-trash" style="color:var(--danger)"></i></button>` : ''}
        </div>
    </div>`;
    }).join('');
}

// Fiche tâche compacte : reprend l'essentiel du rapport (BI, machine, localisation, description,
// demandeur, date, intervenant(s) déjà assigné(s) via les pointages, carnet de bord en cours) +
// une ligne vide pour la signature de prise en charge, pensée pour être imprimée et punaisée sur
// le tableau d'atelier (attribution physique des tâches). Réutilisée par imprimerFicheTache (1 fiche
// pleine page) et imprimerSelectionMultiple (plusieurs fiches compactes, 4 par page A4).
function construireFicheTacheHtml(t, compact) {
    const locLine = (t.usine || t.secteur || t.ligne || t.zone)
        ? `${t.usine || '?'} > ${t.secteur || '?'} > ${t.ligne ? t.ligne + ' > ' : ''}${t.zone || '?'}` : '-';
    const isUrgent = t.prio === 'Urgent';
    const dateFmt = t.date ? t.date.split(' ')[0].split('-').reverse().join('/') : '-';

    // Même règle que buildHistoriqueCardsHtml/buildHistoriqueRowsHtml : seuls les pointages
    // (même à 0h, posés comme "réservé pour") font foi pour l'intervenant, pas le déclarant.
    const techsSet = new Set(pointages.filter(p => p.task_id === t.id).map(p => p.tech));
    const intervenants = Array.from(techsSet).join(', ') || I18N_MAINT.opt_non_assigne;

    const typeColor = t.type === 'Préventif' ? 'var(--accent)' : (t.type === 'Chantier' ? 'var(--purple)' : 'var(--danger)');
    const typeLabel = t.type === 'Préventif' ? I18N_MAINT.type_preventif : (t.type === 'Chantier' ? I18N_MAINT.type_chantier : I18N_MAINT.type_curatif);

    const carnetHtml = (t.rapport_intermediaire && t.rapport_intermediaire.trim() !== '')
        ? `<div class="fiche-tache-carnet"><b>${I18N_MAINT.fiche_tache_carnet}</b><div class="content">${t.rapport_intermediaire}</div></div>` : '';

    return `
        <div class="fiche-tache-card ${isUrgent ? 'urgent' : ''} ${compact ? 'compact' : ''}">
            <div class="fiche-tache-header ${isUrgent ? 'urgent' : ''}">
                <div class="fiche-tache-num">${I18N_MAINT.fiche_tache_bi} ${t.num_bi || '-'}</div>
                ${isUrgent ? `<div class="fiche-tache-prio-urgent"><i class="fa-solid fa-bell"></i> ${I18N_MAINT.badge_urgent}</div>` : ''}
            </div>
            <div class="fiche-tache-body">
                <span class="fiche-tache-type-badge" style="background:${typeColor};">${typeLabel}</span>
                <div class="fiche-tache-row c-machine"><b>${I18N_MAINT.fiche_tache_machine}</b>${t.equip || '-'}</div>
                <div class="fiche-tache-row c-loc"><b>${I18N_MAINT.fiche_tache_localisation}</b>${locLine}</div>
                <div class="fiche-tache-row c-desc"><b>${I18N_MAINT.fiche_tache_description}</b>${t.desc || '-'}</div>
                <div class="fiche-tache-row c-demandeur"><b>${I18N_MAINT.fiche_tache_demandeur}</b>${t.demandeur || t.tech || '-'}</div>
                <div class="fiche-tache-row c-date"><b>${I18N_MAINT.fiche_tache_date}</b>${dateFmt}</div>
                <div class="fiche-tache-row c-tech"><b>${I18N_MAINT.fiche_tache_technicien_assigne}</b>${intervenants}</div>
            </div>
            ${carnetHtml}
        </div>
    `;
}

// S'assure que #fiche-tache-print est un enfant direct de <body> : le CSS d'impression masque
// tout le reste via "body > *:not(#fiche-tache-print)", ce qui ne fonctionne que si l'élément
// est bien à ce niveau, quel que soit l'endroit où il a été inséré dans le HTML.
function remonterFicheTachePrintDansBody() {
    const el = document.getElementById('fiche-tache-print');
    if (el && el.parentElement !== document.body) document.body.appendChild(el);
    return el;
}

function imprimerFicheTache(id) {
    const t = tasks.find(x => x.id === id);
    if (!t) return;
    const el = remonterFicheTachePrintDansBody();
    el.innerHTML = construireFicheTacheHtml(t, false);
    window.print();
}

// Impression groupée : reprend les BI cochés via les cases à cocher (colonne réservée aux
// admins, comme pour la suppression en masse) et imprime une fiche compacte par BI, 4 par
// page A4 (grille 2x2), pour punaiser toute une tournée d'un coup sur le tableau d'atelier.
function imprimerSelectionMultiple() {
    const checked = Array.from(new Set(Array.from(document.querySelectorAll('.task-check:checked')).map(cb => cb.value)));
    if (checked.length === 0) return;
    const selected = checked.map(id => tasks.find(t => t.id === id)).filter(Boolean);

    // Chaque fiche est un bloc "inline-block" (~47% de large, 2 par ligne) : le navigateur les
    // enchaîne et saute de page naturellement, sans qu'on ait besoin de les grouper par 4 nous-mêmes.
    const el = remonterFicheTachePrintDansBody();
    el.innerHTML = selected.map(t => construireFicheTacheHtml(t, true)).join('');
    window.print();
}

function changeHistoriqueLimit(dir) {
    const idx = HISTORIQUE_LIMIT_STEPS.indexOf(historiqueLimit);
    const newIdx = Math.max(0, Math.min(HISTORIQUE_LIMIT_STEPS.length - 1, (idx === -1 ? 1 : idx) + dir));
    historiqueLimit = HISTORIQUE_LIMIT_STEPS[newIdx];
    renderHistoriqueBody();
}

function updateHistoriqueLimitLabel() {
    const el = document.getElementById('histoLimitLabel');
    if (!el) return;
    const total = historiqueSorted.length;
    if (historiqueFullyRendered) {
        el.textContent = I18N_MAINT.limit_search_active.replace('{n}', total);
    } else {
        el.textContent = I18N_MAINT.limit_last_of.replace('{n}', Math.min(historiqueLimit, total)).replace('{total}', total);
    }
    const idx = HISTORIQUE_LIMIT_STEPS.indexOf(historiqueLimit);
    const btnMinus = document.getElementById('histoLimitMinus');
    const btnPlus = document.getElementById('histoLimitPlus');
    if (btnMinus) btnMinus.disabled = historiqueFullyRendered || idx <= 0;
    if (btnPlus) btnPlus.disabled = historiqueFullyRendered || idx >= HISTORIQUE_LIMIT_STEPS.length - 1;
}

function updatePrioIcon() { document.getElementById('prio-icon-container').innerHTML = (document.getElementById('f-prio').value === "Urgent") ? '<i class="fa-solid fa-bell icon-urgent-blink" style="color:var(--danger)"></i>' : '<i class="fa-solid fa-circle-check" style="color:var(--accent)"></i>'; }

window.cycleStatus = async function(id, actuel) {
    let s = (actuel || "").toLowerCase();

    if (s.includes("faire")) {
        const t = tasks.find(x => x.id === id);
        if (!t) return;
        
        t.statut = "En cours"; 
        
        try {
            const response = await fetch('api.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(t)
            });
            
            if (response.ok) {
                const taskPointages = pointages.filter(p => p.task_id === id);
                
                if (taskPointages.length === 0) {
                    const formData = new URLSearchParams();
                    formData.append('action', 'save_pointage');
                    formData.append('task_id', id);
                    formData.append('tech', currentUser); 
                    formData.append('hours', '0'); 
                    formData.append('date', t.date.split(' ')[0]);
                    
                    await fetch('maintenance.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: formData
                    });
                }

                await loadData();
                render();
            }
        } catch (e) { 
            console.error(e); 
        }
    } 
    else if (s.includes("cours")) {
        window.ouvrirModalCloture(id);
    }
    else if (s.includes("termin")) {
        window.reouvrirTask(id);
    }
};

window.ouvrirModalCloture = function(id) {
    const t = tasks.find(x => x.id === id);
    if (!t) return;

    document.getElementById('cloture-id').value = id;
    document.getElementById('cloture-titre-bi').innerText = t.num_bi || "---";
    document.getElementById('cloture-info-desc').innerText = t.desc || "---";
    document.getElementById('cloture-cr').value = t.compte_rendu || "";

    // Badges + infos d'origine et de localisation : même calcul que showDetailBI (composant_rapport.php),
    // dupliqué ici volontairement pour ne pas risquer de casser la fiche Rapport en la réusinant.
    const badgeType = t.type === 'Préventif' ? `<span style="background:#3498db; color:white; padding:3px 8px; border-radius:4px; font-size:0.65rem; font-weight:500; text-transform:uppercase;">${I18N_RAPPORT.type_preventif}</span>` :
                      (t.type === 'Chantier' ? `<span style="background:#9b59b6; color:white; padding:3px 8px; border-radius:4px; font-size:0.65rem; font-weight:500; text-transform:uppercase;">${I18N_RAPPORT.type_chantier}</span>` :
                      `<span style="background:#e74c3c; color:white; padding:3px 8px; border-radius:4px; font-size:0.65rem; font-weight:500; text-transform:uppercase;">${I18N_RAPPORT.type_curatif}</span>`);
    const badgeCasse = (t.casse == 1 || t.casse === 'true' || t.casse === true) ? `<span style="background:var(--danger); color:white; padding:3px 8px; border-radius:4px; font-size:0.65rem; font-weight:500; text-transform:uppercase;"><i class="fa-solid fa-bolt"></i> ${I18N_RAPPORT.casse_accidentelle}</span>` : '';
    const badgeVisserie = (t.verif_vis == 1 || t.verif_vis === 'true' || t.verif_vis === true || t.verif_vis === "1") ? `<span style="background:#8e44ad; color:white; padding:3px 8px; border-radius:4px; font-size:0.65rem; font-weight:500; text-transform:uppercase;"><i class="fa-solid fa-screwdriver"></i> ${I18N_RAPPORT.visserie_controlee}</span>` : '';
    document.getElementById('cloture-badges').innerHTML = badgeType + ' ' + badgeCasse + ' ' + badgeVisserie;

    let dateReelleStr = t.date_creation || t.date;
    let dateOrigine = '--/--/----', heureOrigine = '--:--';
    if (dateReelleStr && dateReelleStr.includes('-')) {
        const parties = dateReelleStr.split(' ');
        dateOrigine = parties[0].split('-').reverse().join('/');
        if (parties[1]) {
            heureOrigine = parties[1].substring(0, 5);
            if (heureOrigine === "08:00" && t.date_creation && t.date_creation.includes(' ')) {
                heureOrigine = t.date_creation.split(' ')[1].substring(0, 5);
            }
        }
    }
    document.getElementById('cloture-info-signale').innerText = dateOrigine + ' ' + I18N_RAPPORT.date_at + ' ' + heureOrigine;
    document.getElementById('cloture-info-prevu').innerText = t.date ? t.date.split(' ')[0].split('-').reverse().join('/') : '---';
    document.getElementById('cloture-info-emetteur').innerText = t.demandeur || I18N_RAPPORT.non_renseigne;
    const infoPriorite = document.getElementById('cloture-info-priorite');
    infoPriorite.innerText = t.prio === 'Urgent' ? I18N_RAPPORT.lib_urgent : I18N_RAPPORT.lib_normal;
    infoPriorite.style.color = t.prio === 'Urgent' ? LIBELLES_RAPPORT.urgent.couleur : '#2c3e50';

    const stWrap = document.getElementById('cloture-info-st-wrap');
    if (t.is_sous_traitant == 1 || t.is_sous_traitant === "1" || t.is_sous_traitant === true) {
        let stName = I18N_RAPPORT.entreprise_ext_fallback;
        if (t.entreprise_ext_id && typeof dictionnaireEE !== 'undefined') {
            const foundEE = dictionnaireEE.find(e => e.id == t.entreprise_ext_id);
            if (foundEE) stName = foundEE.nom;
        }
        document.getElementById('cloture-info-st').innerText = stName;
        stWrap.style.display = 'contents';
    } else {
        stWrap.style.display = 'none';
    }

    document.getElementById('cloture-info-usine').innerText = t.usine || '---';
    document.getElementById('cloture-info-secteur').innerText = t.secteur || '---';
    const ligneWrap = document.getElementById('cloture-info-ligne-wrap');
    if (t.ligne) {
        document.getElementById('cloture-info-ligne').innerText = t.ligne;
        ligneWrap.style.display = 'contents';
    } else {
        ligneWrap.style.display = 'none';
    }
    document.getElementById('cloture-info-zone').innerText = t.zone || '---';
    document.getElementById('cloture-info-machine').innerText = t.equip || '---';

    renderRapportPhotos(id, 'cloture');

    const taskPointages = pointages.filter(p => p.task_id === id);
    // Le déclarant (t.tech) n'est pas forcément l'intervenant : voir buildHistoriqueRowsHtml.
    const intervenants = [...new Set(taskPointages.map(p => p.tech))].join(', ') || '---';
    document.getElementById('cloture-info-intervenants').innerText = intervenants;

    const recapArea = document.getElementById('cloture-recap-temps');
    let total = 0;
    
    if (taskPointages.length === 0) {
        recapArea.innerHTML = `<p style="font-size: 0.75rem; color: #94a3b8; margin: 0; font-style: italic;">${I18N_MAINT.cloture_aucun_pointage}</p>`;
    } else {
        let html = `<table style="width:100%; border-collapse: collapse; font-size: 0.8rem;">`;
        taskPointages.forEach(p => {
            total += parseFloat(p.hours || 0);
            html += `<tr style="border-bottom: 1px solid #f8fafc;">
                        <td style="padding: 4px 0; color: #475569;">${p.tech}</td>
                        <td style="padding: 4px 0; text-align: right; color: var(--primary); font-weight: 600;">${parseFloat(p.hours).toFixed(2)} h</td>
                     </tr>`;
        });
        html += `</table>`;
        recapArea.innerHTML = html;
    }

    document.getElementById('cloture-info-total-h').innerText = total.toFixed(2) + " h";

    applyClotureLockUI(wizardSmartEstFerme(t.statut));

    document.getElementById('modalClotureBI').style.display = 'flex';
    document.getElementById('modalClotureBI').style.alignItems = 'center';
    document.getElementById('modalClotureBI').style.justifyContent = 'center';
};

// Un bon déjà clôturé (Terminée/Refusée) reste modifiable en lecture seule pour un simple
// technicien : rouvrir un ticket clôturé est une action réservée aux admins (cf. reouvrirTask).
// On applique la même règle ici, sinon le détecteur de doublons du wizard offrirait une porte
// dérobée pour contourner cette restriction en ajoutant du temps / en rouvrant depuis ce chemin.
function applyClotureLockUI(estFerme) {
    const verrouille = estFerme && !isAdmin;

    const notice = document.getElementById('cloture-readonly-notice');
    if (notice) notice.style.display = verrouille ? 'flex' : 'none';

    const cr = document.getElementById('cloture-cr');
    if (cr) cr.readOnly = verrouille;

    ['cloture-btn-temps', 'cloture-btn-enregistrer-ouvert', 'cloture-btn-confirmer'].forEach(id => {
        const btn = document.getElementById(id);
        if (btn) btn.style.display = verrouille ? 'none' : '';
    });
}

window.validerCloture = async function() {
    const id = document.getElementById('cloture-id').value;
    const cr = document.getElementById('cloture-cr').value;
    
    const t = tasks.find(x => x.id === id);
    if (!t) return;

    if (!cr.trim()) {
        document.getElementById('modalAlerteRapport').style.display = 'flex';
        return;
    }

    t.statut = "Terminée";
    t.compte_rendu = cr;

    try {
        const response = await fetch('api.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(t)
        });
        
        if (response.ok) {
            document.getElementById('modalClotureBI').style.display = 'none';
            await loadData();
            render();
        }
    } catch (e) {
        console.error(e);
    }
};

// Sauvegarde le rapport en cours de rédaction SANS clôturer le bon : l'intervention n'est
// pas forcément terminée (ex : on rouvre un bon déjà clôturé pour continuer à travailler dessus
// via le détecteur de doublons du wizard), le ticket doit pouvoir rester actif.
async function enregistrerSansCloture() {
    const id = document.getElementById('cloture-id').value;
    const cr = document.getElementById('cloture-cr').value;

    const t = tasks.find(x => x.id === id);
    if (!t) return;

    t.statut = "En cours";
    t.compte_rendu = cr;
    t.action_user = currentUser;

    try {
        const response = await fetch('api.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(t)
        });

        if (response.ok) {
            document.getElementById('modalClotureBI').style.display = 'none';
            await loadData();
            render();
        } else {
            await aspirineAlert(I18N_MAINT.err_title, I18N_MAINT.cloture_err_save);
        }
    } catch (e) {
        console.error(e);
        await aspirineAlert(I18N_MAINT.err_network_title, I18N_MAINT.cloture_err_save_network);
    }
}

// Rafraîchit uniquement le récap des temps + le total dans la modale de clôture, sans toucher
// au textarea du compte-rendu (pour ne pas effacer ce que l'utilisateur est en train de taper)
// — appelé après l'ajout d'un pointage via le bouton "+ TEMPS" de cette même modale.
function refreshClotureTempsSiOuvert(taskId) {
    const modal = document.getElementById('modalClotureBI');
    if (!modal || modal.style.display === 'none') return;
    if (document.getElementById('cloture-id').value != taskId) return;

    const taskPointages = pointages.filter(p => p.task_id == taskId);
    // Le déclarant (t.tech) n'est pas forcément l'intervenant : voir buildHistoriqueRowsHtml.
    const intervenants = [...new Set(taskPointages.map(p => p.tech))].join(', ') || '---';
    document.getElementById('cloture-info-intervenants').innerText = intervenants;

    const recapArea = document.getElementById('cloture-recap-temps');
    let total = 0;

    if (taskPointages.length === 0) {
        recapArea.innerHTML = `<p style="font-size: 0.8rem; margin: 0;">${I18N_MAINT.cloture_aucune_intervention}</p>`;
    } else {
        let html = `<table style="width:100%; border-collapse: collapse; font-size: 0.8rem;">
            <thead>
                <tr style="color: var(--text-light); border-bottom: 1px solid var(--border-color); text-align: left;">
                    <th style="padding: 4px 5px;">${I18N_MAINT.cloture_th_intervenant}</th>
                    <th style="padding: 4px 5px; text-align: right;">${I18N_MAINT.cloture_th_duree}</th>
                </tr>
            </thead>
            <tbody>`;
        taskPointages.forEach(p => {
            const h = parseFloat(p.hours || 0);
            total += h;
            html += `
            <tr style="border-bottom: 1px solid #f8f9fa;">
                <td style="padding: 5px; font-weight: 500;">${p.tech}</td>
                <td style="padding: 5px; text-align: right; font-weight: 700; color: var(--dark-blue);">${h.toFixed(2)} h</td>
            </tr>`;
        });
        html += `</tbody></table>`;
        recapArea.innerHTML = html;
    }
    document.getElementById('cloture-info-total-h').innerText = total.toFixed(2) + " h";
}

async function saveStatusDirect(id, nouveauStatut) {
    const t = tasks.find(x => x.id === id);
    if (!t) return;
    t.statut = nouveauStatut;

    try {
        const response = await fetch('api.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(t)
        });
        if (response.ok) {
            await loadData();
            render();
        }
    } catch (e) {
        console.error("Erreur saveStatusDirect:", e);
    }
}

async function updateTaskStatus(id, newStatus) {
    const t = tasks.find(x => x.id === id);
    if (!t) return;

    t.statut = newStatus;

    try {
        const response = await fetch('api.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(t) 
        });

        if (response.ok) {
            await loadData(); 
        } else {
            await aspirineAlert(I18N_MAINT.err_title, "Le serveur n'a pas pu changer le statut.");
        }
    } catch (e) {
        console.error("Erreur réseau :", e);
    }
}

async function revertStatus(id, newS) {
    const t = tasks.find(x => x.id == id);
    if(t) { t.statut = newS; t.action_user = currentUser; await fetch('api.php', { method: 'POST', body: JSON.stringify(t) }); closeModal(); loadData(); }
}

function ouvrirModal() {
    document.getElementById('taskForm').reset();
    document.getElementById('f-id').value = '';
    document.getElementById('f-num-bi').value = genererNumeroBI();
    
    const now = new Date();
    const dateStr = now.toISOString().split('T')[0];
    const timeStr = now.toTimeString().split(' ')[0].substring(0,5);
    document.getElementById('f-date').value = dateStr + ' ' + timeStr;
    document.getElementById('modalTitle').innerText = "Nouveau Bon d'Intervention";
    document.getElementById('taskModal').style.display = 'block';
}
    
function ouvrirModalPointage(id) {
    const t = tasks.find(x => x.id === id);
    if (!t) return;

    document.getElementById('pointage-task-id').value = id;
    
    document.getElementById('pointage-title').innerHTML = `
        <div style="padding: 15px 25px; background: white; border-bottom: 1px solid #f1f5f9; display: flex; justify-content: space-between; align-items: center; border-radius: 12px 12px 0 0;">
            <div style="display: flex; align-items: center; gap: 12px;">
                <div style="background: var(--soft-blue); color: var(--accent); width: 38px; height: 38px; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 1.1rem;">
                    <i class="fa-solid fa-stopwatch"></i>
                </div>
                <div>
                    <h3 style="margin:0; font-family:'Caveat', cursive; font-size: 1.8rem; color: var(--primary); font-weight: 500;">${I18N_MAINT.ptg_modal_title}</h3>
                    <div style="font-size: 0.65rem; color: #94a3b8; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px;">
                        #${t.num_bi || '---'}
                    </div>
                </div>
            </div>
            <div style="text-align: right;">
                <label style="display:block; font-size: 0.6rem; color: #94a3b8; text-transform: uppercase; font-weight: 700;">${I18N_MAINT.ptg_equipement}</label>
                <span style="font-size: 0.85rem; font-weight: 600; color: var(--primary);">${t.equip || '---'}</span>
            </div>
        </div>
    `;

    const formZone = document.querySelector('#modalPointage [style*="background: #fff9f0"]') 
                  || document.querySelector('#modalPointage [style*="background: #f8fafc"]');
    
    if(formZone) {
        formZone.style.background = "#f8fafc";
        formZone.style.border = "1px solid #e2e8f0";
        formZone.style.padding = "15px";
        formZone.style.margin = "15px";
        formZone.style.borderRadius = "8px";
        
        formZone.innerHTML = `
            <div style="display: flex; gap: 12px; align-items: flex-end;">
                <div style="flex: 1.5;">
                    <label style="display:block; font-size: 0.65rem; font-weight: 700; color: #94a3b8; text-transform: uppercase; margin-bottom: 4px;">${I18N_MAINT.ptg_technicien}</label>
                    <select id="ptg-tech" ${isAdmin ? '' : 'disabled'} style="width:100%; padding:7px; border-radius:6px; border:1px solid #cbd5e1; font-size:0.85rem; color:#334155; background:${isAdmin ? 'white' : '#f1f2f6'}; height:35px; cursor:${isAdmin ? 'pointer' : 'not-allowed'};">
                        ${isAdmin
                            ? team.map(m => `<option value="${m.name}" ${m.name === currentUser ? 'selected' : ''}>${m.name}</option>`).join('')
                            : `<option value="${currentUser}" selected>${currentUser}</option>`}
                    </select>
                </div>
                <div style="flex: 1;">
                    <label style="display:flex; align-items:center; gap:5px; font-size: 0.65rem; font-weight: 700; color: #94a3b8; text-transform: uppercase; margin-bottom: 4px;">
                        ${I18N_MAINT.ptg_heures} <i class="fa-solid fa-circle-question icon-help" style="font-size:0.8rem; cursor:pointer;" onclick="ouvrirAide('duree')"></i>
                    </label>
                    <input type="number" id="ptg-hours" step="0.25" placeholder="0.00" style="width:100%; padding:7px; border-radius:6px; border:1px solid #cbd5e1; font-size:0.85rem; color:#334155; background:white; height:35px; box-sizing:border-box;">
                </div>
                <div style="flex: 1;">
                    <label style="display:block; font-size: 0.65rem; font-weight: 700; color: #94a3b8; text-transform: uppercase; margin-bottom: 4px;">${I18N_MAINT.ptg_date}</label>
                    <input type="date" id="ptg-date" value="${new Date().toISOString().split('T')[0]}" style="width:100%; padding:6px; border-radius:6px; border:1px solid #cbd5e1; font-size:0.85rem; color:#334155; background:white; height:35px; box-sizing:border-box; font-family:inherit;">
                </div>
                <button onclick="ajouterPointage()" style="background: var(--accent); color: white; border: none; padding: 0 20px; height: 35px; border-radius: 6px; cursor: pointer; font-weight: 700; font-size: 0.8rem; transition: 0.2s; white-space: nowrap; box-shadow: 0 2px 0 var(--dark-blue);">
                    <i class="fa-solid fa-plus"></i> ${I18N_MAINT.ptg_ajouter}
                </button>
            </div>
        `;
    }

    const list = document.getElementById('pointageList');
    const ptgs = pointages.filter(p => p.task_id === id);

    // Préparation des données de lieu et de description
    const lieuComplet = t.usine ? `${t.usine} > ${t.secteur} > ${t.zone}` : I18N_MAINT.ptg_loc_non_precisee;
    const descriptionPanne = t.desc || I18N_MAINT.ptg_aucune_desc;

    let htmlList = `
        <div style="padding: 12px 15px; background: #fffbe6; font-size: 0.8rem; border-bottom: 1px solid #ffe58f; color: #856404; display: flex; flex-direction: column; gap: 8px; border-radius: ${ptgs.length > 0 ? '0' : '8px'};">
            <div>
                <i class="fa-solid fa-location-dot" style="opacity:0.7; width:15px;"></i>
                <b>${I18N_MAINT.ptg_lieu}</b> ${lieuComplet} <i class="fa-solid fa-caret-right" style="margin:0 5px; opacity:0.5;"></i> <span style="font-weight:bold; color:#d35400; font-size:0.9rem;">${t.equip || '---'}</span>
            </div>
            <div>
                <i class="fa-solid fa-wrench" style="opacity:0.7; width:15px;"></i>
                <b>${I18N_MAINT.ptg_detail}</b> <span style="font-style: italic;">"${descriptionPanne}"</span>
            </div>
        </div>
    `;

    if (ptgs.length > 0) {
        htmlList += `
        <table style="width:100%; border-collapse: collapse; font-size: 0.85rem;">
            <thead>
                <tr style="background:#fbfcfd; border-bottom:1px solid #f1f5f9; color: #94a3b8; font-size: 0.65rem; text-transform: uppercase;">
                    <th style="padding:10px 15px; text-align:left; font-weight:700;">${I18N_MAINT.ptg_th_date}</th>
                    <th style="padding:10px 15px; text-align:left; font-weight:700;">${I18N_MAINT.ptg_th_technicien}</th>
                    <th style="padding:10px 15px; text-align:center; font-weight:700;">${I18N_MAINT.ptg_th_heures}</th>
                    <th style="padding:10px 15px; text-align:right; font-weight:700;">${I18N_MAINT.ptg_th_actions}</th>
                </tr>
            </thead>
            <tbody>
                ${ptgs.map(p => `
                    <tr style="border-bottom:1px solid #f8fafc;">
                        <td style="padding:10px 15px; color:#64748b; font-size:0.8rem;">${p.date.split(' ')[0]}</td>
                        <td style="padding:10px 15px; font-weight: 500; color: #334155;">${p.tech}</td>
                        <td style="padding:10px 15px; text-align:center;"><span style="background:#f1f5f9; color:var(--dark-blue); padding:2px 8px; border-radius:4px; font-weight:700; font-size:0.8rem;">${parseFloat(p.hours).toFixed(2)} h</span></td>
                        <td style="padding:10px 15px; text-align:right; white-space:nowrap;">
    ${(isAdmin || p.tech === currentUser) ? `
        <button onclick="modifierPointage(${p.id}, '${id}')" style="border:none; background:none; cursor:pointer; color:#94a3b8; transition:0.2s; margin-right:10px;" title="${I18N_MAINT.modifier_tooltip}">
            <i class="fa-solid fa-pen"></i>
        </button>
        <button onclick="supprimerPointage(${p.id}, '${id}')" style="border:none; background:none; cursor:pointer; color:var(--danger);" title="${I18N_MAINT.supprimer_tooltip}">
            <i class="fa-solid fa-trash-can"></i>
        </button>
    ` : `
        <span style="color:#cbd5e1; font-size:0.75rem; padding-right:5px;" title="${I18N_MAINT.locked_tooltip}">
            <i class="fa-solid fa-lock"></i> ${I18N_MAINT.locked_label}
        </span>
    `}
</td>
                    </tr>
                `).join('')}
            </tbody>
        </table>`;
    } else {
        htmlList += `<div style="text-align:center; color:#94a3b8; padding:30px; font-size:0.85rem; font-style:italic; background:#fff;">${I18N_MAINT.aucun_temps}</div>`;
    }

    list.innerHTML = htmlList;

    const modalPt = document.getElementById('modalPointage');
    if (modalPt) {
        // ON FORCE LE Z-INDEX A UN NIVEAU GIGANTESQUE POUR PASSER AU-DESSUS DE TOUTES LES AUTRES MODALES
        modalPt.style.zIndex = "200000"; 
        modalPt.style.display = 'flex';
        modalPt.style.alignItems = 'center';
        modalPt.style.justifyContent = 'center';
    } 
}
async function savePointage(tId) {
    const fd = new FormData(); fd.append('action', 'save_pointage'); fd.append('task_id', tId); fd.append('tech', document.getElementById('p-tech').value); fd.append('date', document.getElementById('p-date').value); fd.append('hours', document.getElementById('p-hours').value);
    await fetch('maintenance.php', { method: 'POST', body: fd });
    const t = tasks.find(x => x.id === tId); if(t) { t.hours = (parseFloat(t.hours || 0) + parseFloat(document.getElementById('p-hours').value)).toString(); if(getStatusSlug(t.statut)==='afaire') t.statut = "En cours"; await fetch('api.php', { method: 'POST', body: JSON.stringify(t) }); }
    closeModal(); loadData();
}

function ouvrirModaleBilan(t) {
    let totalHeures = pointages
        .filter(p => p.task_id === t.id)
        .reduce((sum, p) => sum + parseFloat(p.hours), 0) || parseFloat(t.hours || 0);

    document.getElementById('modalTitle').innerHTML = `
        <div style="display:flex; justify-content:space-between; align-items:center; width:100%; padding: 10px;">
            <div>
                <div style="font-family: 'Permanent Marker', cursive; font-size: 1.6rem; color: #34495e;">
                    <i class="fa-solid fa-file-signature" style="color: var(--brand-orange);"></i> Clôture du BI
                </div>
                <div style="font-size: 1.4rem; font-weight: 800; color: black; margin-top: 5px;">
                    <span style="color: var(--brand-orange); opacity: 0.7;">#</span>${t.num_bi || t.id}
                </div>
            </div>
            <button onclick="reouvrirTicket('${t.id}')" class="status-btn st-encours" style="font-size:0.6rem; min-width:auto; padding:8px 12px; border-radius: 8px;">
                <i class="fa-solid fa-undo"></i> RÉ-OUVRIR LE BON
            </button>
        </div>`;

    const localisation = t.local ? " - " + t.local : "";
    const machineComplete = (t.equip || "---") + localisation;

    document.getElementById('modalBody').innerHTML = `
    <div style="display:grid; grid-template-columns: 1fr 1fr; gap:15px; margin-bottom:20px;">
        <div style="background:#f0f7ff; padding:15px; border-radius:10px; border-left:5px solid #3498db;">
            <p style="margin:0; font-size:0.65rem; font-weight:800; color:#3498db; text-transform:uppercase;">Localisation</p>
            <p style="margin:5px 0; font-weight:bold; font-size:1rem; color:#2c3e50;">${machineComplete}</p>
            <p style="margin:0; font-size:0.8rem; color:#7f8c8d;"><i class="fa-solid fa-user"></i> Créé par : ${t.action_user || t.tech}</p>
        </div>
        <div style="background:#f4fff6; padding:15px; border-radius:10px; border-left:5px solid var(--success);">
            <p style="margin:0; font-size:0.65rem; font-weight:800; color:var(--success); text-transform:uppercase;">Bilan Temps</p>
            <p style="margin:5px 0; font-weight:bold; font-size:1.1rem; color:var(--success);">${totalHeures.toFixed(2)} h cumulées</p>
            <p style="margin:0; font-size:0.8rem; color:#7f8c8d;"><i class="fa-solid fa-calendar-day"></i> Date : ${t.date.split(' ')[0].split('-').reverse().join('/')}</p>
        </div>
    </div>

    <div class="modal-field">
        <label style="display:block; margin-bottom:10px; font-weight:800; color:var(--primary); font-size:0.7rem; text-transform:uppercase;">
            <i class="fa-solid fa-comment-medical"></i> Compte-rendu d'intervention (Rapport technique) :
        </label>
        <textarea id="bilan-comm" rows="5" 
            style="width:100%; border-radius:8px; border:1px solid #ddd; padding:12px; font-family:inherit; font-size:0.95rem; box-sizing:border-box; outline:none;"
            placeholder="Décrivez ici les travaux réalisés...">${t.comm_tech || ''}</textarea>
    </div>

    <button onclick="cloturerInterventionDefinitif('${t.id}')" class="btn-modal-save" 
        style="margin-top:20px; background:var(--success); height:55px; font-size:1.1rem; width:100%; border-radius:10px; font-weight:bold; box-shadow: 0 4px 10px rgba(39, 174, 96, 0.3);">
        <i class="fa-solid fa-check-double"></i> CLÔTURER DÉFINITIVEMENT LE BON
    </button>`;

    document.getElementById('modalHistory').style.display = "block";
}

async function cloturerInterventionDefinitif(id) {
    const t = tasks.find(x => x.id == id); if(t) { t.statut = "Terminée"; t.comm_tech = document.getElementById('bilan-comm').value; t.action_user = currentUser; await fetch('api.php', { method: 'POST', body: JSON.stringify(t) }); closeModal(); loadData(); }
}

const AIDE_DB = {
    tech: {
        icon: 'fa-user', bg: 'var(--soft-blue)', color: 'var(--accent)',
        title: <?php echo json_encode(t('maint.aide_tech_title')); ?>, subtitle: <?php echo json_encode(t('maint.aide_tech_sub')); ?>,
        body: <?php echo json_encode(t('maint.aide_tech_body')); ?>
    },
    assign_tech: {
        icon: 'fa-user-gear', bg: 'var(--soft-blue)', color: 'var(--accent)',
        title: <?php echo json_encode(t('maint.aide_assign_tech_title')); ?>, subtitle: <?php echo json_encode(t('maint.aide_assign_tech_sub')); ?>,
        body: <?php echo json_encode(t('maint.aide_assign_tech_body')); ?>
    },
    sous_traitant: {
        icon: 'fa-handshake', bg: 'var(--soft-orange)', color: 'var(--brand-orange)',
        title: <?php echo json_encode(t('maint.aide_sous_traitant_title')); ?>, subtitle: <?php echo json_encode(t('maint.aide_sous_traitant_sub')); ?>,
        body: <?php echo json_encode(t('maint.aide_sous_traitant_body')); ?>
    },
    localisation: {
        icon: 'fa-map-location-dot', bg: 'var(--soft-blue)', color: 'var(--accent)',
        title: <?php echo json_encode(t('maint.aide_localisation_title')); ?>, subtitle: <?php echo json_encode(t('maint.aide_localisation_sub')); ?>,
        body: <?php echo json_encode(t('maint.aide_localisation_body')); ?>
    },
    machine: {
        icon: 'fa-microchip', bg: 'var(--soft-blue)', color: 'var(--accent)',
        title: <?php echo json_encode(t('maint.aide_machine_title')); ?>, subtitle: <?php echo json_encode(t('maint.aide_machine_sub')); ?>,
        body: <?php echo json_encode(t('maint.aide_machine_body')); ?>
    },
    date: {
        icon: 'fa-calendar-day', bg: 'var(--soft-blue)', color: 'var(--accent)',
        title: <?php echo json_encode(t('maint.aide_date_title')); ?>, subtitle: <?php echo json_encode(t('maint.aide_date_sub')); ?>,
        body: <?php echo json_encode(t('maint.aide_date_body')); ?>
    },
    type: {
        icon: 'fa-tag', bg: 'var(--soft-blue)', color: 'var(--accent)',
        title: <?php echo json_encode(t('maint.aide_type_title')); ?>, subtitle: <?php echo json_encode(t('maint.aide_type_sub')); ?>,
        body: <?php echo json_encode(t('maint.aide_type_body')); ?>
    },
    prio: {
        icon: 'fa-flag', bg: '#fdeeec', color: 'var(--danger)',
        title: <?php echo json_encode(t('maint.aide_prio_title')); ?>, subtitle: <?php echo json_encode(t('maint.aide_prio_sub')); ?>,
        body: <?php echo json_encode(t('maint.aide_prio_body')); ?>
    },
    casse: {
        icon: 'fa-bolt', bg: '#fdeeec', color: 'var(--danger)',
        title: <?php echo json_encode(t('maint.aide_casse_title')); ?>, subtitle: <?php echo json_encode(t('maint.aide_casse_sub')); ?>,
        body: <?php echo json_encode(t('maint.aide_casse_body')); ?>
    },
    verif_vis: {
        icon: 'fa-screwdriver', bg: 'var(--soft-orange)', color: 'var(--brand-orange)',
        title: <?php echo json_encode(t('maint.aide_verif_vis_title')); ?>, subtitle: <?php echo json_encode(t('maint.aide_verif_vis_sub')); ?>,
        body: <?php echo json_encode(t('maint.aide_verif_vis_body')); ?>
    },
    desc: {
        icon: 'fa-comment', bg: 'var(--soft-blue)', color: 'var(--accent)',
        title: <?php echo json_encode(t('maint.aide_desc_title')); ?>, subtitle: <?php echo json_encode(t('maint.aide_desc_sub')); ?>,
        body: <?php echo json_encode(t('maint.aide_desc_body')); ?>
    },
    duree: {
        icon: 'fa-clock', bg: 'var(--soft-blue)', color: 'var(--accent)',
        title: <?php echo json_encode(t('maint.aide_duree_title')); ?>, subtitle: <?php echo json_encode(t('maint.aide_duree_sub')); ?>,
        body: <?php echo json_encode(t('maint.aide_duree_body')); ?>
    },
    demandes: {
        icon: 'fa-inbox', bg: 'var(--soft-orange)', color: 'var(--brand-orange)',
        title: <?php echo json_encode(t('maint.aide_demandes_title')); ?>, subtitle: <?php echo json_encode(t('maint.aide_demandes_sub')); ?>,
        body: <?php echo json_encode(t('maint.aide_demandes_body')); ?>
    },
    photos: {
        icon: 'fa-camera', bg: 'var(--soft-blue)', color: 'var(--accent)',
        title: <?php echo json_encode(t('maint.aide_photos_title')); ?>, subtitle: <?php echo json_encode(t('maint.aide_photos_sub')); ?>,
        body: <?php echo json_encode(t('maint.aide_photos_body')); ?>
    }
};

function ouvrirAide(type) {
    const a = AIDE_DB[type] || { icon: 'fa-circle-question', bg: 'var(--soft-blue)', color: 'var(--accent)', title: I18N_MAINT.aide_default_title, subtitle: '', body: I18N_MAINT.aide_default_body };

    document.getElementById('modalTitle').innerHTML = `<i class="fa-solid fa-circle-question"></i> ${a.title}`;
    document.getElementById('modalBody').innerHTML = `
        <div class="aide-hero">
            <div class="aide-hero-icon" style="background:${a.bg}; color:${a.color};"><i class="fa-solid ${a.icon}"></i></div>
            <div class="aide-hero-text"><h3>${a.title}</h3><p>${a.subtitle || ''}</p></div>
        </div>
        <div class="aide-body">${a.body}</div>
    `;
    document.getElementById('modalHistory').style.display = "block";
}

function ouvrirModalValidationDemande(id) {
    const t = tasks.find(x => x.id == id);
    document.getElementById('modalTitle').innerHTML = '<i class="fa-solid fa-check-to-slot"></i> Transformer la demande en B.I.';
    
    const usines = [...new Set(dbMachines.map(m => m.usine))].sort();
    const optionsUsine = usines.map(u => `<option value="${u}">${u}</option>`).join('');

    document.getElementById('modalBody').innerHTML = `
    <div style="background:#fff3cd; padding:10px; border-radius:8px; margin-bottom:15px; font-size:0.9rem; border-left:4px solid #ffc107;">
        <b>Message Production :</b> "${t.desc}" sur <b>${t.equip}</b>
    </div>
    
    <div class="modal-form-grid" style="grid-template-columns: 1fr 1fr;">
        <div class="modal-field"><label>Assigner à :</label>
            <select id="v-tech">${team.map(m => `<option ${m.name === currentUser ? 'selected' : ''}>${m.name}</option>`).join('')}</select>
        </div>
        <div class="modal-field"><label>Priorité :</label>
            <select id="v-prio"><option value="Normal">Normal</option><option value="Urgent">Urgent</option></select>
        </div>
        
        <div style="grid-column: 1 / -1; display:grid; grid-template-columns: 1fr 1fr 1fr 1fr; gap:10px; background:#f1f3f5; padding:10px; border-radius:8px;">
            <div class="modal-field"><label>Usine</label>
                <select id="v-usine" onchange="updateCascadeVal('secteur')"><option value="">Choisir...</option>${optionsUsine}</select>
            </div>
            <div class="modal-field"><label>Secteur</label>
                <select id="v-secteur" onchange="updateCascadeVal('ligne')" disabled><option value="">-</option></select>
            </div>
            <div class="modal-field"><label>Ligne</label>
                <select id="v-ligne" onchange="updateCascadeVal('zone')" disabled><option value="">-</option></select>
            </div>
            <div class="modal-field"><label>Zone</label>
                <select id="v-zone" onchange="updateCascadeVal('machine')" disabled><option value="">-</option></select>
            </div>
            <div class="modal-field" style="grid-column: 1 / -1;"><label>Confirmer la Machine</label>
                <select id="v-equip" disabled><option value="">-</option></select>
            </div>
        </div>
        
        <div class="modal-field" style="grid-column: 1/-1;"><label>Description technique finale :</label>
            <input type="text" id="v-desc" value="${t.desc}">
        </div>
        
        <button class="btn-modal-save" onclick="confirmerValidationDemande('${t.id}')" style="grid-column: 1/-1; background:var(--success); height:50px;">
            <i class="fa-solid fa-plus-circle"></i> CRÉER LE BON D'INTERVENTION
        </button>
    </div>`;
}

function updateCascadeVal(step) {
    const u = document.getElementById('v-usine').value;
    const s = document.getElementById('v-secteur');
    const l = document.getElementById('v-ligne');
    const z = document.getElementById('v-zone');
    const m = document.getElementById('v-equip');

    if (step === 'secteur') {
        const secteurs = [...new Set(dbMachines.filter(x => x.usine === u).map(x => x.secteur).filter(Boolean))].sort();
        s.innerHTML = '<option value="">Choisir...</option>' + secteurs.map(x => `<option value="${x}">${x}</option>`).join('');
        s.disabled = false; l.disabled = true; z.disabled = true; m.disabled = true;
        l.innerHTML = '<option value="">-</option>'; z.innerHTML = '<option value="">-</option>'; m.innerHTML = '<option value="">-</option>';
    } 
    else if (step === 'ligne') {
        const lignes = [...new Set(dbMachines.filter(x => x.usine === u && x.secteur === s.value).map(x => x.ligne).filter(Boolean))].sort();
        if(lignes.length > 0) {
            l.innerHTML = '<option value="">Choisir...</option>' + lignes.map(x => `<option value="${x}">${x}</option>`).join('');
            l.disabled = false;
        } else {
            l.innerHTML = '<option value="N/A">-</option>';
            l.disabled = true;
            updateCascadeVal('zone');
        }
        z.disabled = true; m.disabled = true;
    } 
    else if (step === 'zone') {
        const l_val = l.value === 'N/A' ? undefined : l.value;
        let filtered = dbMachines.filter(x => x.usine === u && x.secteur === s.value);
        if(l_val) filtered = filtered.filter(x => x.ligne === l_val);
        
        const zones = [...new Set(filtered.map(x => x.zone).filter(Boolean))].sort();
        z.innerHTML = '<option value="">Choisir...</option>' + zones.map(x => `<option value="${x}">${x}</option>`).join('');
        z.disabled = false; m.disabled = true;
    } 
    else if (step === 'machine') {
        const l_val = l.value === 'N/A' ? undefined : l.value;
        let filtered = dbMachines.filter(x => x.usine === u && x.secteur === s.value && x.zone === z.value);
        if(l_val) filtered = filtered.filter(x => x.ligne === l_val);
        
        const machines = filtered.sort((a,b) => (a.nom_machine||"").localeCompare(b.nom_machine||""));
        m.innerHTML = '<option value="">Choisir...</option>' + machines.map(x => `<option value="${x.nom_machine}">${x.nom_machine}</option>`).join('');
        m.disabled = false;
    }
}

function openHistory(name) {
    // --- ON REMPLACE LA LIGNE "const list..." PAR CE BLOC ---
    const allTaskIdsWithPointages = new Set(pointages.map(p => p.task_id));
    const taskIdsWork = pointages.filter(p => p.tech === name).map(p => p.task_id);

    const list = tasks.filter(x => {
        if (taskIdsWork.includes(x.id)) return true; // Condition A : Il a pointé dessus
        if (!allTaskIdsWithPointages.has(x.id) && x.tech === name) return true; // Condition B : Il est déclarant ET personne n'a encore pointé
        return false;
    }).sort((a,b) => new Date(b.date) - new Date(a.date));
    // --------------------------------------------------------
    
    document.getElementById('modalTitle').innerText = I18N_MAINT.hist_title.replace('{name}', name);

    let h = `<table style="width:100%; font-size:0.8rem; border-collapse: collapse;">
                <thead>
                    <tr style="background:#f8f9fa;">
                        <th style="padding:8px;">${I18N_MAINT.hist_th_num_bi}</th>
                        <th style="padding:8px;">${I18N_MAINT.hist_th_date}</th>
                        <th style="padding:8px; text-align:left;">${I18N_MAINT.hist_th_loc}</th>
                        <th style="padding:8px; text-align:left;">${I18N_MAINT.hist_th_desc}</th>
                        <th style="padding:8px;">${I18N_MAINT.hist_th_role_heures}</th>
                        <th style="padding:8px;">${I18N_MAINT.hist_th_statut}</th>
                        <th style="padding:8px;">${I18N_MAINT.hist_th_action}</th>
                    </tr>
                    <tr style="background:#f1f5f9; border-bottom:2px solid #ddd;">
                        <th style="padding:4px;"><input type="text" id="fh-bi" placeholder="${I18N_MAINT.col_bi_placeholder}" onkeyup="filterHistory()" style="width:90%; padding:4px; font-size:0.75rem; border:1px solid #ccc; border-radius:4px; outline:none;"></th>
                        <th style="padding:4px;"><input type="date" id="fh-date" onchange="filterHistory()" style="width:90%; padding:4px; font-size:0.75rem; border:1px solid #ccc; border-radius:4px; outline:none;"></th>
                        <th style="padding:4px;"><input type="text" id="fh-loc" placeholder="${I18N_MAINT.hist_filter_loc_placeholder}" onkeyup="filterHistory()" style="width:95%; padding:4px; font-size:0.75rem; border:1px solid #ccc; border-radius:4px; outline:none;"></th>
                        <th style="padding:4px;"><input type="text" id="fh-desc" placeholder="${I18N_MAINT.hist_filter_desc_placeholder}" onkeyup="filterHistory()" style="width:95%; padding:4px; font-size:0.75rem; border:1px solid #ccc; border-radius:4px; outline:none;"></th>
                        <th></th> <th style="padding:4px;">
                            <select id="fh-statut" onchange="filterHistory()" style="width:95%; padding:4px; font-size:0.75rem; border:1px solid #ccc; border-radius:4px; outline:none;">
                                <option value="">${I18N_MAINT.col_all}</option>
                                <option value="urgent">${I18N_MAINT.lib_urgent}</option>
                                <option value="à faire">${I18N_MAINT.lib_afaire}</option>
                                <option value="en cours">${I18N_MAINT.lib_encours}</option>
                                <option value="termin">${I18N_MAINT.lib_termine}</option>
                            </select>
                        </th>
                        <th></th> </tr>
                </thead>
                <tbody id="histTableBody">`;

    list.forEach(t => { 
        let pointagesTech = pointages.filter(p => p.task_id === t.id && p.tech === name);
        let pt = pointagesTech.reduce((s, x) => s + parseFloat(x.hours), 0);
        
        let affichageHeures = "";
        if (pointagesTech.length > 0) {
            affichageHeures = `<span style="font-weight:bold; color:var(--dark-blue); font-size:0.9rem;">${pt.toFixed(2)}h</span>`;
        } else if (t.tech === name) {
            affichageHeures = `<span style="background:#e2e8f0; color:#64748b; padding:3px 6px; border-radius:4px; font-size:0.65rem; font-weight:bold; text-transform:uppercase;"><i class="fa-solid fa-bullhorn"></i> ${I18N_MAINT.hist_declarant_badge}</span>`;
        } else {
            affichageHeures = `-`;
        }
        
        // CHIRURGIE 2 : Génération de la chaîne de localisation complète
        let localisationComplere = "";
        if (t.usine || t.secteur || t.zone) {
            localisationComplere = `<div style="font-size:0.7rem; color:#64748b; margin-bottom:2px; font-weight:normal;">
                <sup><i class="fa-solid fa-location-dot" style="opacity:0.5;"></i></sup> 
                ${t.usine || '?'} &gt; ${t.secteur || '?'} &gt; ${t.zone || '?'}
            </div>`;
        } else {
            localisationComplere = `<div style="font-size:0.7rem; color:#94a3b8; margin-bottom:2px; font-style:italic;">${I18N_MAINT.hist_loc_non_specifiee}</div>`;
        }
        
        h += `<tr style="border-bottom:1px solid #eee;">
                <td onclick="closeModal(); showDetailBI('${t.id}')" style="padding:8px; text-align:center; font-weight:bold; color:var(--brand-orange); text-decoration:underline; cursor:pointer;">
                    ${t.num_bi || '-'}
                </td>
                
                <td style="padding:8px; text-align:center;">${t.date ? t.date.split(' ')[0].split('-').reverse().join('/') : ''}</td>
                
                <td style="padding:8px; text-align:left;">
                    ${localisationComplere}
                    <b style="color:var(--primary); font-size:0.85rem;">${t.equip}</b>
                </td>
                
                <td style="padding:8px; text-align:left; font-size:0.7rem;">${t.desc || '-'}</td>
                <td style="padding:8px; text-align:center;">${affichageHeures}</td>
                <td style="padding:8px; text-align:center;">${getBadgeHtml(t.statut, t.prio)}</td>
                <td style="padding:8px; text-align:center;">
                    ${(getStatusSlug(t.statut) === 'termine')
                        ? `<div style="font-size:0.65rem; color:#27ae60; font-weight:bold;"><i class="fa-solid fa-lock"></i> ${I18N_MAINT.saisie_close}</div>`
                        : `<button onclick="ouvrirModalPointage('${t.id}')" class="btn-pointer" style="padding: 4px 8px; font-size: 0.65rem;">
                            <i class="fa-solid fa-clock"></i> ${I18N_MAINT.plus_temps}
                           </button>`
                    }
                </td>
              </tr>`; 
    });

    document.getElementById('modalBody').innerHTML = h + `</tbody></table>`;
    document.getElementById('modalHistory').style.display = "block";
}

function editTask(id) {
    const t = tasks.find(x => x.id == id);
    if (!t) return;

    // Mise à jour du label
    const labelLabel = document.querySelector("#f-tech").previousElementSibling;
    if (labelLabel) {
        labelLabel.innerHTML = I18N_MAINT.label_declarant + ' <i class="fa-solid fa-circle-question icon-help" onclick="ouvrirAide(\'tech\')"></i>';
    }

    document.getElementById('f-id').value = t.id;
    
    // CORRECTION ICI : On met t.tech (le déclarant enregistré) au lieu de currentUser
    document.getElementById('f-tech').value = t.tech || currentUser || ""; 
    
    document.getElementById('f-date').value = t.date ? t.date.split(' ')[0] : '';
    document.getElementById('f-prio').value = t.prio;
    document.getElementById('f-type').value = t.type || 'Curatif';
    document.getElementById('f-casse').checked = (t.casse == 1 || t.casse === "1" || t.casse === true || t.casse === "true");
    document.getElementById('f-verif-vis').checked = (t.verif_vis == 1 || t.verif_vis === "1" || t.verif_vis === true || t.verif_vis === "true");
    document.getElementById('f-desc').value = t.desc;
    // --- GESTION SOUS-TRAITANT (MODIFICATION) ---
    document.getElementById('f-is-st').checked = (t.is_sous_traitant == 1 || t.is_sous_traitant === true || t.is_sous_traitant === "1");
    toggleST();
    if (t.entreprise_ext_id) {
        document.getElementById('f-entreprise').value = t.entreprise_ext_id;
    } else {
        document.getElementById('f-entreprise').value = "";
    }
    // --- CHARGER LES INTERVENANTS PRÉVUS S'ILS EXISTENT (POUR LES CHEFS) ---
    // Un pointage à 0h posé sur ce ticket est un "réservé pour" (voir sauvegarderTacheOT) : on coche
    // tous les techniciens dans ce cas, jamais ceux qui ont déjà de vraies heures saisies.
    if (document.getElementById('f-assign-tech-list')) {
        const nomsPrevus = pointages.filter(p => p.task_id == t.id && parseFloat(p.hours || 0) === 0).map(p => p.tech);
        cocherIntervenantsPrevus(nomsPrevus);
    }
    // --- RESTAURATION DE LA LOCALISATION EN CASCADE (+ picker visuel) ---
    resetLocSelects();
    if (t.usine) {
        document.getElementById('f-usine').value = t.usine;
        updateSecteurs();

        if (t.secteur) {
            document.getElementById('f-secteur').value = t.secteur;
            updateLignes();

            if (t.ligne) {
                document.getElementById('f-ligne').value = t.ligne;
            }
            updateZones();

            if (t.zone) {
                document.getElementById('f-zone').value = t.zone;
                updateMachines();

                if (t.equip) {
                    const fEquip = document.getElementById('f-equip');
                    if (fEquip && fEquip.tagName === 'SELECT') {
                        // Sécurité : si la machine n'est pas dans la liste, on la recrée temporairement
                        if (![...fEquip.options].some(o => o.value === t.equip)) {
                            const opt = document.createElement('option');
                            opt.value = t.equip;
                            opt.innerText = t.equip + " (Hors liste)";
                            fEquip.appendChild(opt);
                        }
                        fEquip.value = t.equip;
                    } else if (fEquip) {
                        fEquip.value = t.equip;
                    }
                }
            }
        }
    } else if (t.equip) {
        // Sécurité : Si le vieux ticket n'a pas d'usine, on force l'affichage de la machine
        const fEquip = document.getElementById('f-equip');
        if (fEquip && fEquip.tagName === 'SELECT') {
            fEquip.innerHTML = `<option value="${t.equip}">${t.equip}</option>`;
            fEquip.disabled = false;
        } else if (fEquip) {
            fEquip.value = t.equip;
        }
    }
    renderLocPicker();
    // ----------------------------------------------------------------

    const btn = document.getElementById('btn-submit');
    btn.innerHTML = '<i class="fa-solid fa-rotate"></i> ' + I18N_MAINT.btn_maj_ticket;
    btn.classList.add('btn-update');

    updatePrioIcon();

    document.getElementById('wizardTitleOT').innerHTML = '<i class="fa-solid fa-pen" style="color:var(--brand-orange);"></i> ' + I18N_MAINT.wizard_edit_title;
    wizardUnlockedOT = WIZARD_STEPS_OT; // Édition : on autorise à naviguer librement, les données sont déjà valides

    // En édition, les photos se gèrent depuis la fiche détail du BI (bouton "Voir le détail"),
    // pas depuis ce formulaire — le sélecteur d'ajout n'a donc pas lieu d'être ici.
    selectedPhotosOT = [];
    const pPickerEdit = document.getElementById('photo-picker-ot');
    if (pPickerEdit) pPickerEdit.style.display = 'none';

    goToStepOT(1);
    document.getElementById('modalWizardOT').style.display = "block";
}

function closeModal() { document.getElementById('modalHistory').style.display = "none"; }

async function loadData() { try { tasks = await (await fetch('api.php?t=' + Date.now())).json(); pointages = await (await fetch('maintenance.php?get_pointages=1&t=' + Date.now())).json(); render(); } catch(e) {} }

async function deleteTask(id) { 
    const confirmation = await aspirineConfirm("Suppression", "Voulez-vous vraiment supprimer ce ticket ?");
    if(confirmation) { 
        await fetch('api.php?delete=' + encodeURIComponent(id)); 
        loadData(); 
    } 
}

async function saveTask() {
    const taskId = document.getElementById('f-id').value;
    const isU = taskId !== ""; 

    let numeroBI = "";
    if (isU) {
        const existing = tasks.find(x => x.id == taskId);
        numeroBI = existing ? existing.num_bi : genererNumeroBI();
    } else {
        numeroBI = genererNumeroBI();
    }

    const maintenant = new Date();
    const dateHeureAuto = maintenant.getFullYear() + '-' + 
        (maintenant.getMonth()+1).toString().padStart(2,'0') + '-' + 
        maintenant.getDate().toString().padStart(2,'0') + ' ' + 
        maintenant.getHours().toString().padStart(2,'0') + ':' + 
        maintenant.getMinutes().toString().padStart(2,'0');

    let heureOrigine = " 08:00"; // Sécurité par défaut
    if (isU) {
        const existing = tasks.find(x => x.id == taskId);
        if (existing && existing.date && existing.date.includes(' ')) {
            heureOrigine = " " + existing.date.split(' ')[1];
        }
    }
    const dateSaisie = document.getElementById('f-date').value;

    // CORRECTION : On écoute TOUJOURS la saisie de l'utilisateur, même en modification
    const declarantFinal = document.getElementById('f-tech').value;

    // Le demandeur d'ORIGINE (ex: un compte de service "S. Production" venu du Portail) ne doit
    // jamais être écrasé par la personne qui traite/modifie le ticket ensuite : on le préserve
    // en édition, et on ne l'initialise au déclarant que pour un tout nouveau ticket.
    const demandeurExistant = isU ? (tasks.find(x => x.id == taskId)?.demandeur || "") : "";
    const demandeurFinal = isU ? (demandeurExistant || declarantFinal) : declarantFinal;

    const isST = document.getElementById('f-is-st').checked ? 1 : 0;
    const entrepriseExtId = isST ? document.getElementById('f-entreprise').value : null;
    const t = {
        id: taskId || ("ID-" + Date.now()),
        num_bi: numeroBI,
        tech: declarantFinal,
        demandeur: demandeurFinal,
        is_sous_traitant: isST,
        entreprise_ext_id: entrepriseExtId,
        usine: document.getElementById('f-usine') ? document.getElementById('f-usine').value : "",
        secteur: document.getElementById('f-secteur') ? document.getElementById('f-secteur').value : "",
        ligne: (document.getElementById('f-ligne') && document.getElementById('f-ligne').value !== 'N/A') ? document.getElementById('f-ligne').value : "",
        zone: document.getElementById('f-zone') ? document.getElementById('f-zone').value : "",
        equip: document.getElementById('f-equip').value, 
        
        date: dateSaisie + heureOrigine, 
        date_creation: isU ? (tasks.find(x => x.id == taskId)?.date_creation || dateHeureAuto) : dateHeureAuto,
        
        hours: isU ? (tasks.find(x => x.id == taskId)?.hours || 0) : 0, // CORRECTION : Protège les heures
        prio: document.getElementById('f-prio').value, 
        type: document.getElementById('f-type').value, 
        casse: document.getElementById('f-casse').checked ? 1 : 0,
        verif_vis: document.getElementById('f-verif-vis').checked ? 1 : 0, 
        desc: document.getElementById('f-desc').value, 
        statut: isU ? (tasks.find(x => x.id == taskId)?.statut || "À faire") : "À faire", 
        compte_rendu: isU ? (tasks.find(x => x.id == taskId)?.compte_rendu || "") : "",
        
        action_user: currentUser, 
        is_update: isU 
    };

    try {
        const response = await fetch('api.php', { 
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(t) 
        });

        const result = await response.json();

        if (result.status === "success") {
            const labelLabel = document.querySelector("#f-tech").previousElementSibling;
            if (labelLabel) {
                labelLabel.innerHTML = I18N_MAINT.label_declarant + ' <i class="fa-solid fa-circle-question icon-help" onclick="ouvrirAide(\'tech\')"></i>';
            }

            // --- GESTION DES INTERVENANTS PRÉVUS (plusieurs techniciens possibles) ---
            // Un pointage à 0h = un technicien "réservé" sur ce ticket, avant toute heure réellement
            // pointée (voir chargement plus haut). En modification, on ne touche jamais aux pointages
            // qui portent déjà de vraies heures — seuls les 0h posés ici sont ajoutés/retirés.
            const intervenantsCoches = getIntervenantsPrevusCoches();
            if (!isU) {
                for (const nomTech of intervenantsCoches) {
                    const fdAssign = new URLSearchParams();
                    fdAssign.append('action', 'save_pointage');
                    fdAssign.append('task_id', t.id);
                    fdAssign.append('tech', nomTech);
                    fdAssign.append('hours', '0');
                    fdAssign.append('date', dateSaisie);
                    await fetch('maintenance.php', { method: 'POST', body: fdAssign });
                }
            } else {
                const placeholders = pointages.filter(p => p.task_id == t.id && parseFloat(p.hours || 0) === 0);
                const nomsExistants = placeholders.map(p => p.tech);

                for (const nomTech of intervenantsCoches) {
                    if (!nomsExistants.includes(nomTech)) {
                        const fdAssign = new URLSearchParams();
                        fdAssign.append('action', 'save_pointage');
                        fdAssign.append('task_id', t.id);
                        fdAssign.append('tech', nomTech);
                        fdAssign.append('hours', '0');
                        fdAssign.append('date', dateSaisie);
                        await fetch('maintenance.php', { method: 'POST', body: fdAssign });
                    }
                }
                for (const ptg of placeholders) {
                    if (!intervenantsCoches.includes(ptg.tech)) {
                        const paramsDel = new URLSearchParams();
                        paramsDel.append('action', 'delete_pointage');
                        paramsDel.append('id', ptg.id);
                        await fetch('maintenance.php', { method: 'POST', body: paramsDel });
                    }
                }
            }

            if (!isU) await uploadPendingPhotosOT(t.id);

            await loadData();
            location.reload();
        }
        else {
            await aspirineAlert(I18N_MAINT.err_title, I18N_MAINT.err_db.replace('{msg}', result.message));
        }
    } catch (e) {
        console.error("Erreur lors de la sauvegarde :", e);
        await aspirineAlert(I18N_MAINT.err_network_title, I18N_MAINT.err_comm_failed);
    }
}

document.getElementById('f-date').value = dateParDefautOT(); initCascade();
const chargementInitialOT = loadData();

// Arrivée depuis le Parc Machine ("Créer BI" sur une machine) ou depuis le Planning ("Créer un BI" sur
// une case) : on ouvre l'assistant pré-rempli.
(function () {
    const p = new URLSearchParams(window.location.search);
    if (p.get('creer_bi') === '1') {
        ouvrirWizardOTPourMachine(p.get('usine') || '', p.get('secteur') || '', p.get('ligne') || '', p.get('zone') || '', p.get('equip') || '');
        history.replaceState(null, '', 'maintenance.php');
    } else if (p.get('creer_bi_planning') === '1') {
        const tech = p.get('tech') || '';
        const dateStr = p.get('date') || '';
        history.replaceState(null, '', 'maintenance.php');
        // Contrairement à ouvrirWizardOTPourMachine (qui ne dépend que de dbMachines, déjà disponible au
        // chargement de la page), cocherIntervenantsPrevus a besoin que render() ait construit la liste
        // "Intervenants prévus" au moins une fois — donc on attend le premier chargement des données
        // plutôt que d'ouvrir l'assistant tout de suite (sinon le technicien ne se coche pas).
        chargementInitialOT.then(() => ouvrirWizardOTPourPlanning(tech, dateStr));
    }
})();

async function reouvrirTicket(id) {
    const confirmation = await aspirineConfirm(I18N_MAINT.reouvrir_confirm_title, I18N_MAINT.reouvrir_confirm_msg);
    if (!confirmation) return;

    try {
        const t = tasks.find(x => x.id == id);
        if (!t) return;

        t.statut = "En cours"; // Remet le statut actif pour libérer les modifications
        t.action_user = currentUser;

        const response = await fetch('api.php', { 
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(t)
        });
        
        if (response.ok) {
            // Ferme la modale de détails si elle est ouverte
            const modalDetail = document.getElementById('modalDetailBI');
            if (modalDetail) modalDetail.style.display = 'none';
            
            closeModal();
            await loadData();
            if (typeof render === 'function') render();
        } else {
            await aspirineAlert(I18N_MAINT.err_title, I18N_MAINT.err_status_change);
        }
    } catch (e) {
        console.error(e);
        await aspirineAlert(I18N_MAINT.err_network_title, I18N_MAINT.err_connection_api);
    }
}

async function reouvrirTask(id) {
    if (!isAdmin) {
        await aspirineAlert(I18N_MAINT.access_denied_title, I18N_MAINT.access_denied_reopen);
        return;
    }

    // Remplacement du confirm système par ta modale
    const confirmation = await aspirineConfirm(I18N_MAINT.reopen_confirm_title, I18N_MAINT.reopen_confirm_msg);
    if (!confirmation) return;

    try {
        const response = await fetch('api.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'reopen_task', id: id })
        });
        const data = await response.json();

        if (response.ok && data.status === 'success') {
            await loadData();
            render();
            // Remplacement du alert() système
            await aspirineAlert(I18N_MAINT.success_title, I18N_MAINT.reopen_success_msg);
        } else {
            await aspirineAlert(I18N_MAINT.err_title, data.message || I18N_MAINT.err_reopen);
        }
    } catch (e) {
        await aspirineAlert(I18N_MAINT.err_network_title, e.message);
    }
}

function supprimerPointage(idPtg, idTask) {
    document.getElementById('delete-ptg-id').value = idPtg;
    document.getElementById('delete-ptg-task-id').value = idTask;
    
    const modalDel = document.getElementById('modalConfirmDelete');
    modalDel.style.display = 'flex';
}

async function validerSuppression() {
    const idPtg = document.getElementById('delete-ptg-id').value;
    const idTask = document.getElementById('delete-ptg-task-id').value;

    const params = new URLSearchParams();
    params.append('action', 'delete_pointage');
    params.append('id', idPtg);

    try {
        const response = await fetch('maintenance.php', { // LIGNE MODIFIÉE ICI
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: params
        });

        if (response.ok) {
            document.getElementById('modalConfirmDelete').style.display = 'none'; 
            await loadData(); 
            ouvrirModalPointage(idTask);
        } else {
            await aspirineAlert(I18N_MAINT.err_title, I18N_MAINT.del_err_server2);
        }
    } catch (e) {
        await aspirineAlert(I18N_MAINT.err_network_title, I18N_MAINT.err_network);
    }
}

async function ajouterPointage() {
    const idTask = document.getElementById('pointage-task-id').value;
    
    const tech = document.getElementById('ptg-tech').value; 
    
    const hours = document.getElementById('ptg-hours').value;
    const date = document.getElementById('ptg-date').value;

    if (!tech) return await aspirineAlert(I18N_MAINT.attention_title, I18N_MAINT.select_technician);
    if (!hours || hours <= 0) return await aspirineAlert(I18N_MAINT.attention_title, I18N_MAINT.enter_valid_duration);

    const formData = new URLSearchParams();
    formData.append('action', 'save_pointage');
    formData.append('task_id', idTask);
    formData.append('tech', tech);
    formData.append('hours', hours.replace(',', '.'));
    formData.append('date', date);

    try {
        const response = await fetch('maintenance.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: formData
        });

        if (response.ok) {
            document.getElementById('ptg-hours').value = "";
            await loadData();
            ouvrirModalPointage(idTask);
            refreshClotureTempsSiOuvert(idTask);
        } else {
            await aspirineAlert(I18N_MAINT.err_title, I18N_MAINT.err_save_server);
        }
    } catch (e) {
        await aspirineAlert(I18N_MAINT.err_network_title, I18N_MAINT.err_network);
    }
}

function modifierPointage(idPtg, idTask) {
    document.getElementById('edit-ptg-id').value = idPtg;
    document.getElementById('edit-ptg-task-id').value = idTask;
    document.getElementById('edit-ptg-hours').value = "";
    
    const modalEdit = document.getElementById('modalEditPointage');
    modalEdit.style.display = 'flex';
    document.getElementById('edit-ptg-hours').focus();
}

async function validerModification() {
    const idPtg = document.getElementById('edit-ptg-id').value;
    const idTask = document.getElementById('edit-ptg-task-id').value;
    const nouvelleDuree = document.getElementById('edit-ptg-hours').value;

    if (!nouvelleDuree || nouvelleDuree <= 0) {
        await aspirineAlert(I18N_MAINT.attention_title, I18N_MAINT.enter_valid_duration2);
        return;
    }

    const params = new URLSearchParams();
    params.append('action', 'update_hours'); 
    params.append('id', idPtg);
    params.append('hours', nouvelleDuree.replace(',', '.'));

    try {
        const response = await fetch('maintenance.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: params
        });

        if (response.ok) {
            document.getElementById('modalEditPointage').style.display = 'none';
            await loadData();
            ouvrirModalPointage(idTask);
        } else {
            await aspirineAlert(I18N_MAINT.err_title, I18N_MAINT.err_modification);
        }
    } catch (e) {
        await aspirineAlert(I18N_MAINT.err_network_title, I18N_MAINT.err_network);
    }
}

document.addEventListener('keydown', function(event) {
    if (event.key === "Escape") {
        document.querySelectorAll('.modal').forEach(m => m.style.display = 'none');
        const forcage = ['modalPointage', 'modalEditPointage', 'modalDetailBI'];
        forcage.forEach(id => {
            const el = document.getElementById(id);
            if(el) el.style.display = 'none';
        });
    }
});

window.addEventListener('click', function(event) {
    // --- 1. PROTECTION INTELLIGENTE (Uniquement quand on tape du texte) ---
    
    // Protection de la modale de clôture standard (qui contient toujours le rapport)
    if (event.target.id === 'modalClotureBI') {
        return; // On bloque la fermeture
    }

    // Protection de l'assistant de création de BI : un clic dans le flou ne doit JAMAIS
    // effacer une saisie en cours. Seule la croix ou le bouton "Annuler" ferment la fenêtre.
    if (event.target.id === 'modalWizardOT') {
        return;
    }

    // Protection de la modale "History" UNIQUEMENT si on est en train de faire un Bilan définitif
    if (event.target.id === 'modalHistory') {
        // Le script cherche s'il y a la zone de saisie du compte-rendu
        if (document.getElementById('bilan-comm')) {
            return; // Il y a le champ texte : on bloque la fermeture !
        }
        // S'il n'y a pas le champ texte (ex: l'Aide), le script continue et va la fermer.
    }

    // Protection du Rapport d'intervention (#modalDetailBI) UNIQUEMENT si le Carnet de bord
    // (note intermédiaire, tant que le BI n'est pas Terminé) est affiché : un clic à côté
    // ne doit jamais effacer ce qui vient d'être tapé dedans.
    if (event.target.id === 'modalDetailBI') {
        if (document.querySelector('[id^="note-intermediaire-"]')) {
            return; // Carnet de bord ouvert : on bloque la fermeture !
        }
    }

    // --- 2. FERMETURE NORMALE (Clic dans le flou pour le reste) ---
    const mesModales = ['modalPointage', 'modalEditPointage', 'modalConfirmDelete', 'modalDetailBI', 'modalHistory', 'modalSasValidation'];
    if (mesModales.includes(event.target.id) || event.target.classList.contains('modal')) {
        event.target.style.display = 'none';
    }
});

function aspirineConfirm(titre, message) {
    return new Promise((resolve) => {
        const modal = document.getElementById('customConfirm');
        document.getElementById('confirmTitle').innerText = titre;
        document.getElementById('confirmMessage').innerText = message;
        
        modal.style.display = 'block';

        const btnOk = document.getElementById('confirmOk');
        const btnCancel = document.getElementById('confirmCancel');

        btnOk.onclick = () => {
            modal.style.display = 'none';
            resolve(true);
        };

        btnCancel.onclick = () => {
            modal.style.display = 'none';
            resolve(false);
        };
    });
}

function aspirineAlert(titre, message) {
    return new Promise((resolve) => {
        const modal = document.getElementById('customAlert');
        document.getElementById('alertTitle').innerText = titre;
        document.getElementById('alertMessage').innerText = message;
        
        modal.style.display = 'block';

        document.getElementById('alertOk').onclick = () => {
            modal.style.display = 'none';
            resolve();
        };
    });
}

function genererNumeroBI() {
    const now = new Date();
    const annee = now.getFullYear().toString().slice(-2); 
    const prefixeAnnee = "BI" + annee + "-"; 

    const bonsAnnee = tasks.filter(t => t.num_bi && t.num_bi.startsWith(prefixeAnnee));

    if (bonsAnnee.length === 0) {
        return prefixeAnnee + "001";
    }

    const numeros = bonsAnnee.map(t => {
        const parties = t.num_bi.split('-');
        const n = parseInt(parties[1]); 
        return isNaN(n) ? 0 : n;
    });

    const max = Math.max(...numeros);
    return prefixeAnnee + (max + 1).toString().padStart(3, '0');
}

let sasDemandes = [];
let sasActiveId = null;
// Sur mobile/tablette (≤900px, voir media query), le SAS bascule entre deux "écrans" au lieu
// d'empiler liste + détail : la liste de badges d'abord, la fiche complète seulement après un tap.
// Ignoré au-delà de 900px (CSS) où les deux restent toujours visibles côte à côte comme avant.
let sasMobileDetailOpen = false;

// Formate "YYYY-MM-DD HH:MM[:SS]" (ou juste "YYYY-MM-DD") en "Jeudi 14/08/2026 à 09:32"
function formatDateHeureFr(dateTimeStr) {
    if (!dateTimeStr) return '';
    const [datePart, timePart] = dateTimeStr.split(' ');
    const [y, m, d] = (datePart || '').split('-');
    if (!y || !m || !d) return '';
    const joursSemaine = I18N_MAINT.jours;
    const dateObj = new Date(y, m - 1, d);
    let out = `${joursSemaine[dateObj.getDay()]} ${d}/${m}/${y}`;
    if (timePart) out += ` ${I18N_MAINT.date_at} ${timePart.slice(0, 5)}`;
    return out;
}

// reinitialiserVueMobile=false pour les rafraîchissements silencieux (radar de notifications toutes
// les 5s, resynchronisation après refus/transfert) : sans ça, un technicien en train de lire une
// fiche sur son téléphone se ferait ramener à la liste de badges toutes les 5 secondes.
window.ouvrirSasValidation = async function(reinitialiserVueMobile = true) {
    if (reinitialiserVueMobile) { sasMobileDetailOpen = false; }
    const modal = document.getElementById('modalSasValidation');
    const content = document.getElementById('liste-sas-content');
    modal.style.display = 'flex';
    content.innerHTML = '<div class="sas-state"><p><i class="fa-solid fa-spinner fa-spin" style="margin-right:8px;"></i>' + I18N_MAINT.sas_sync_loading + '</p></div>';

    try {
        const response = await fetch('api.php?t=' + Date.now());
        const allTasks = await response.json();

        const enAttente = allTasks.filter(t => (!t.num_bi || t.num_bi === "" || t.statut === "EN ATTENTE") && t.statut !== "Refusée");

        // --- MISE À JOUR DU BANDEAU ROUGE EN TEMPS RÉEL ---
        const bandeauRouge = document.getElementById('bandeau-sirene-sas');
        const compteurBandeau = document.getElementById('compteur-sirene-sas');

        if (enAttente.length === 0) {
            // S'il n'y a plus de demandes, on fait disparaître le bandeau rouge de la page principale
            if (bandeauRouge) bandeauRouge.style.display = 'none';
            sasDemandes = [];
            sasActiveId = null;

            content.innerHTML = `
                <div class="sas-state sas-empty">
                    <i class="fa-solid fa-circle-check" style="font-size:2rem; color:#cbd5e1; margin-bottom:10px; display:block;"></i>
                    <p>${I18N_MAINT.sas_none_title}</p>
                </div>`;
            return;
        } else {
            // S'il reste des demandes, on ajuste le chiffre du bandeau en direct (ex: passe de 3 à 2)
            if (compteurBandeau) compteurBandeau.innerText = enAttente.length;
            if (bandeauRouge) bandeauRouge.style.display = 'flex'; // Au cas où
        }
        // --------------------------------------------------

        // On enrichit chaque demande avec le texte parsé (émetteur / message / étiquette)
        sasDemandes = enAttente.map(dem => {
            let descBrute = dem.desc || "";
            let demandeur = dem.demandeur || dem.action_user || "PRODUCTION";
            let message = descBrute;

            if (descBrute.includes("DEMANDE DE :")) {
                let p = descBrute.split(' - ');
                demandeur = p[0].replace("DEMANDE DE : ", "");
                message = p.slice(1).join(' - ');
            }

            let tag = null;
            const tagMatch = message.match(/^\[([^\]]+)\]\s*/);
            if (tagMatch) { tag = tagMatch[1]; message = message.replace(tagMatch[0], ''); }

            return Object.assign({}, dem, { _demandeur: demandeur, _message: message, _tag: tag });
        });

        // On garde la sélection en cours si elle existe toujours, sinon on prend la première
        if (!sasActiveId || !sasDemandes.find(d => d.id === sasActiveId)) {
            sasActiveId = sasDemandes[0].id;
        }

        content.innerHTML = `
            <div class="sas-split" id="sas-split-wrap">
                <div class="sas-master">
                    <div class="sas-master-head">${I18N_MAINT.sas_dossiers_count.replace('{n}', sasDemandes.length)}</div>
                    <div id="sas-master-list" class="sas-master-list"></div>
                </div>
                <div class="sas-detail" id="sas-detail-pane"></div>
            </div>`;

        renderSasMaster();
        renderSasDetail();
        applySasMobileView();

    } catch (e) {
        content.innerHTML = `<div class="sas-state sas-error"><p><i class="fa-solid fa-triangle-exclamation" style="margin-right:6px;"></i>${I18N_MAINT.sas_error_fetch}</p></div>`;
    }
};

function renderSasMaster() {
    const list = document.getElementById('sas-master-list');
    if (!list) return;
    list.innerHTML = sasDemandes.map(dem => `
        <button class="sas-master-item ${dem.id === sasActiveId ? 'is-active' : ''}" onclick="selectSasDemande('${dem.id}')">
            <div class="sas-master-item-top">
                <span class="sas-master-badge-meta">
                    <span class="sas-master-num">#${dem.id.slice(-5)}</span>
                    ${dem.prio === 'Urgent' ? '<i class="fa-solid fa-bell sas-master-urgent-icon" title="Urgent"></i>' : ''}
                </span>
                <span class="sas-master-dot"></span>
            </div>
            <span class="sas-master-service"><i class="fa-solid fa-user sas-master-user-icon"></i> ${dem._demandeur}</span>
            <span class="sas-master-equip">${dem.equip || I18N_MAINT.sas_non_specifie}</span>
            <span class="sas-master-desc">${dem._message}</span>
            <span class="sas-master-date"><i class="fa-solid fa-clock"></i>${formatDateHeureFr(dem.date_creation || dem.date)}</span>
            <div class="sas-master-extra">
                ${(dem.usine || dem.secteur || dem.ligne || dem.zone) ? `<span class="sas-master-loc">${dem.usine || '?'} &gt; ${dem.secteur || '?'} &gt; ${dem.ligne ? dem.ligne + ' &gt; ' : ''}${dem.zone || '?'}</span>` : ''}
            </div>
        </button>
    `).join('');
}

window.selectSasDemande = function(id) {
    sasActiveId = id;
    sasMobileDetailOpen = true;
    renderSasMaster();
    renderSasDetail();
    applySasMobileView();
};

// Bouton "Retour" affiché uniquement sur mobile/tablette (voir media query) : repasse à la vue
// liste de badges sans perdre les données déjà chargées.
window.sasRetourListe = function() {
    sasMobileDetailOpen = false;
    applySasMobileView();
};

function applySasMobileView() {
    const wrap = document.getElementById('sas-split-wrap');
    if (wrap) wrap.classList.toggle('sas-view-detail', sasMobileDetailOpen);
}

function renderSasDetail() {
    const pane = document.getElementById('sas-detail-pane');
    if (!pane) return;
    const dem = sasDemandes.find(d => d.id === sasActiveId);
    if (!dem) { pane.innerHTML = ''; return; }

    const optionsTech = team.map(m => `<option value="${m.name}" ${m.name === currentUser ? 'selected' : ''}>${m.name}</option>`).join('');

    pane.innerHTML = `
        <button class="sas-detail-back" onclick="sasRetourListe()"><i class="fa-solid fa-arrow-left"></i> ${I18N_MAINT.sas_retour_liste}</button>
        <div class="sas-detail-head">
            <div class="sas-detail-head-main">
                <div class="sas-detail-id-row">
                    <span class="sas-detail-num">${I18N_MAINT.sas_dossier_num.replace('{id}', dem.id.slice(-5))}</span>
                    ${dem._tag ? `<span class="sas-tag">${dem._tag}</span>` : ''}
                    ${dem.prio === 'Urgent' ? `<span class="sas-tag" style="background:#fee2e2; color:var(--danger); border-color:#fecaca;"><i class="fa-solid fa-bell"></i> ${I18N_MAINT.lib_urgent}</span>` : ''}
                </div>
                <div class="sas-detail-service">${dem._demandeur}</div>
                <div class="sas-detail-meta">
                    <span><i class="fa-solid fa-clock"></i>${formatDateHeureFr(dem.date_creation || dem.date)}</span>
                    <span><i class="fa-solid fa-location-dot"></i>${dem.usine || '?'} &gt; ${dem.secteur || '?'} &gt; ${dem.ligne ? dem.ligne + ' &gt; ' : ''}${dem.zone || '?'} &gt; <i class="fa-solid fa-gear"></i> <span class="sas-detail-machine">${dem.equip || I18N_MAINT.sas_non_specifie}</span></span>
                </div>
            </div>
            <span class="sas-status-pill"><i class="fa-solid fa-circle-dot"></i> ${I18N_MAINT.sas_en_attente_pill}</span>
        </div>

        <div class="sas-detail-accent"></div>

        <div>
            <div class="sas-desc-label"><i class="fa-solid fa-align-left"></i> ${I18N_MAINT.sas_desc_panne}</div>
            <div class="sas-desc-box">${dem._message}</div>
        </div>

        <div class="sas-photos-block" style="margin-top:14px;">
            <div class="sas-desc-label"><i class="fa-solid fa-camera"></i> ${I18N_MAINT.sas_photos_jointes} <span id="sas-photos-count-${dem.id}" style="font-weight:400; color:#94a3b8; text-transform:none; letter-spacing:0;"></span></div>
            <div id="sas-photos-body-${dem.id}" style="display:flex; flex-wrap:wrap; gap:10px; margin-top:6px;">
                <span style="font-size:0.7rem; color:#94a3b8;"><i class="fa-solid fa-spinner fa-spin"></i> ${I18N_MAINT.sas_chargement}</span>
            </div>
        </div>

        <div class="sas-assign">
            <div class="sas-assign-title"><i class="fa-solid fa-clipboard-list"></i> ${I18N_MAINT.sas_affectation_title}</div>
            <div class="sas-assign-grid">
                <div class="sas-field-group sas-field-tech">
                    <label class="sas-field-label">${I18N_MAINT.sas_technicien}</label>
                    <select id="tech_${dem.id}" class="sas-input" ${isAdmin ? '' : 'disabled'} style="cursor:${isAdmin ? 'pointer' : 'not-allowed'};">
    ${isAdmin ? optionsTech : `<option value="${currentUser}" selected>${currentUser}</option>`}
</select>
                </div>
                <div class="sas-field-group sas-field-date">
                    <label class="sas-field-label">${I18N_MAINT.sas_date_prevue}</label>
                    <input type="date" id="date_${dem.id}" class="sas-input" value="${new Date().toISOString().split('T')[0]}">
                </div>
                <div class="sas-field-group sas-field-prio">
                    <label class="sas-field-label">${I18N_MAINT.sas_priorite}</label>
                    <select id="prio_${dem.id}" class="sas-input">
                        <option value="Normal" ${dem.prio !== 'Urgent' ? 'selected' : ''}>${I18N_MAINT.lib_normal}</option>
                        <option value="Urgent" ${dem.prio === 'Urgent' ? 'selected' : ''}>${I18N_MAINT.lib_urgent}</option>
                    </select>
                </div>
            </div>
        </div>

        <div class="sas-detail-actions">
            <div class="sas-actions-left">
                ${isAdmin ? `
                <button class="sas-btn sas-btn-ghost-danger" onclick="demanderRefus('${dem.id}')">
                    <i class="fa-solid fa-ban"></i>${I18N_MAINT.sas_refuser}
                </button>` : ''}
                <button class="sas-btn sas-btn-ghost-accent" onclick="ouvrirChatTicket('${dem.id}')">
                    <i class="fa-solid fa-comments"></i>${I18N_MAINT.sas_messagerie}
                    <span id="badge-ticket-${dem.id}" class="badge-msg-count" title="${I18N_MAINT.msg_count_tooltip}">0</span>
                </button>
                ${isAdmin ? `
                <button class="sas-btn sas-btn-ghost-preventif" onclick="transfererVersPreventif('${dem.id}')">
                    <i class="fa-solid fa-recycle"></i>${I18N_MAINT.sas_vers_preventif}
                </button>` : ''}
            </div>
            <button class="sas-btn sas-btn-primary" onclick="validerDirectement('${dem.id}')">
                <i class="fa-solid fa-check"></i>${I18N_MAINT.sas_creer_bi}
            </button>
        </div>
    `;

    renderSasPhotos(dem.id);
}

// --- Photos jointes à une demande, affichées en lecture seule dans le SAS de validation (avant
// même qu'un BI existe) — réutilise le même stockage/endpoint que la galerie de la fiche BI
// (bi_photos.php, voir composant_rapport.php) et le même lightbox de zoom.
async function renderSasPhotos(taskId) {
    const body = document.getElementById('sas-photos-body-' + taskId);
    if (!body) return;

    let photos = [];
    try {
        const res = await fetch('bi_photos.php?action=list&task_id=' + encodeURIComponent(taskId));
        const data = await res.json();
        if (data.success) photos = data.photos;
    } catch (e) { console.error("Erreur chargement photos SAS :", e); }

    const countEl = document.getElementById('sas-photos-count-' + taskId);
    if (countEl) countEl.textContent = photos.length > 0 ? '(' + photos.length + ')' : '';

    if (photos.length === 0) {
        body.innerHTML = `<span style="font-size:0.72rem; color:#94a3b8; font-style:italic;">${I18N_MAINT.sas_no_photo}</span>`;
        return;
    }

    const escAttr = (s) => (s || '').replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;');
    body.innerHTML = photos.map(p => `
        <div style="position:relative; width:76px; height:76px; border-radius:8px; overflow:hidden; border:1px solid #e2e8f0; flex-shrink:0;">
            <img src="${p.chemin}" alt="${escAttr(p.nom_original)}" style="width:100%; height:100%; object-fit:cover; display:block; cursor:pointer;" onclick="openRapportPhotoLightbox('${p.chemin}')">
        </div>`).join('');
}

window.validerDirectement = async function(id) {
    const tech = document.getElementById('tech_' + id).value;
    const date = document.getElementById('date_' + id).value;
    const prio = document.getElementById('prio_' + id).value;

    const res = await fetch('api.php?t=' + Date.now());
    const tasks_all = await res.json();
    const t = tasks_all.find(x => x.id == id);
    
    if(!t) return await aspirineAlert(I18N_MAINT.err_title, I18N_MAINT.sas_dossier_introuvable);

    t.num_bi = genererNumeroBI(); 
    t.tech = tech;
    // On récupère l'heure actuelle pour être précis, au lieu de forcer 08:00
    const heureMaintenant = new Date().toTimeString().split(' ')[0].substring(0, 5); 
    t.date = date + " " + heureMaintenant;
    t.prio = prio;
    t.statut = "À faire";
    t.action_user = currentUser;

    try {
        await fetch('api.php', { method: 'POST', body: JSON.stringify(t) });
        document.getElementById('modalSasValidation').style.display = 'none';
        loadData();
    } catch(e) {
        await aspirineAlert(I18N_MAINT.err_network_title, I18N_MAINT.err_server);
    }
};

window.validerDemandeFixe = async function(id) {
    const t = tasks.find(x => x.id === id);
    if (!t) return;
    
    // On utilise ta modale au lieu du prompt() système
    const confirmation = await aspirineConfirm("Validation", "Voulez-vous transformer cette demande en Bon d'Intervention ?");
    if (!confirmation) return;

    t.num_bi = genererNumeroBI(); 
    t.statut = "À faire";         
    t.tech = currentUser;                
    // On récupère l'heure actuelle pour être précis, au lieu de forcer 08:00
    const heureMaintenant = new Date().toTimeString().split(' ')[0].substring(0, 5); 
    t.date = date + " " + heureMaintenant;     
    t.action_user = currentUser;

    try {
        await fetch('api.php', { method: 'POST', body: JSON.stringify(t) });
        document.getElementById('modalSasValidation').style.display = 'none';
        await loadData();
        render();
    } catch(e) {
        await aspirineAlert("Erreur", "Erreur réseau pendant la validation.");
    }
};

let idToDelete = null;
const utilisateurActuel = "<?php echo $_SESSION['user']; ?>";

// ============================================================================
// REFUS D'UNE DEMANDE (avec motif obligatoire + notification au demandeur)
// ============================================================================
let idARefuser = null;
const MOTIF_REFUS_MIN_LEN = 20; // Un vrai compte-rendu ("usine en dégivrage total, pas de production"), pas "RAS" ou "ok"

async function demanderRefus(id) {
    if (!isAdmin) {
        await aspirineAlert(I18N_MAINT.access_denied_title, I18N_MAINT.del_no_rights_refuse);
        return;
    }
    idARefuser = id;
    document.getElementById('refus-motif-input').value = '';
    document.getElementById('refus-motif-error').innerText = '';
    document.getElementById('refus-motif-count').textContent = '0 / 20';
    document.getElementById('modalRefusDemande').style.zIndex = "200000";
    document.getElementById('modalRefusDemande').style.display = 'block';
}

function fermerModalRefus() {
    document.getElementById('modalRefusDemande').style.display = 'none';
    idARefuser = null;
}

async function confirmerRefus() {
    const motif = document.getElementById('refus-motif-input').value.trim();
    const errEl = document.getElementById('refus-motif-error');
    if (!motif) {
        errEl.innerText = I18N_MAINT.refus_err_empty;
        return;
    }
    if (motif.length < MOTIF_REFUS_MIN_LEN) {
        errEl.innerText = I18N_MAINT.refus_err_short.replace('{n}', motif.length).replace('{min}', MOTIF_REFUS_MIN_LEN);
        return;
    }
    if (!idARefuser) return;

    try {
        const res = await fetch('api.php?t=' + Date.now());
        const allTasks = await res.json();
        const t = allTasks.find(x => x.id === idARefuser);
        if (!t) { errEl.innerText = I18N_MAINT.refus_err_notfound; return; }

        // On rattache ce refus à un vrai numéro de BI (comme une validation) pour qu'il reste
        // visible dans l'Historique Global — sans ça, un refus laisse un trou invisible dans
        // la traçabilité (l'Historique ne montre que les tickets ayant un num_bi).
        if (!t.num_bi) { t.num_bi = genererNumeroBI(); }
        t.statut = "Refusée";
        t.motif_refus = motif;
        t.compte_rendu = `Refusé par ${currentUser} le ${new Date().toLocaleDateString('fr-FR')} à ${new Date().toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' })}.\nMotif : ${motif}`;
        t.action_user = currentUser;

        await fetch('api.php', { method: 'POST', body: JSON.stringify(t) });

        // Notification au demandeur, via la messagerie du ticket (même mécanisme que les autres échanges)
        await fetch('api.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action: 'send_chat_message',
                task_id: idARefuser,
                expediteur: currentUser,
                message: I18N_MAINT.refus_message_to_requester.replace('{motif}', motif)
            })
        });

        fermerModalRefus();
        const modalSas = document.getElementById('modalSasValidation');
        if (modalSas && modalSas.style.display !== 'none' && typeof ouvrirSasValidation === 'function') {
            ouvrirSasValidation(false);
        }
        await loadData();
    } catch (e) {
        errEl.innerText = I18N_MAINT.refus_err_network;
    }
}

// ============================================================================
// TRANSFERT D'UNE DEMANDE EN ATTENTE VERS LA CHECKLIST SAISONNIÈRE DU PRÉVENTIF
// (SAS de validation) : une demande transférée ici est presque toujours une tâche PONCTUELLE
// (ex. "descendre le panneau de 2 mètres") — pas un vrai besoin récurrent. On l'ajoute donc
// directement à la checklist « Maintenance hivernal » (case à cocher, pas de fréquence, pas de
// BI généré automatiquement), plutôt que de créer une règle qui reviendrait chaque année. Pour
// le rare cas d'un vrai besoin récurrent, on continue de créer la règle à la main depuis la page
// Préventif (bouton « Nouvelle règle »), pas depuis ce raccourci.
// ============================================================================
async function transfererVersPreventif(id) {
    const dem = sasDemandes.find(d => d.id === id);
    if (!dem) return;

    const ok = await aspirineConfirm(I18N_MAINT.transfer_confirm_title, I18N_MAINT.transfer_confirm_msg);
    if (!ok) return;

    try {
        const resAdd = await fetch('preventif_liste.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action: 'checklist_add',
                categorie: 'maintenance_preventive',
                equip: dem.equip || '',
                desc: dem._message || '',
                signale_par: dem._demandeur || ''
            })
        });
        if (!resAdd.ok) { await aspirineAlert(I18N_MAINT.err_title, I18N_MAINT.transfer_err_add); return; }

        const resDel = await fetch('api.php?delete=' + encodeURIComponent(id));
        const dataDel = await resDel.json().catch(() => null);
        if (!dataDel || dataDel.status !== 'success') {
            await aspirineAlert(I18N_MAINT.attention_title, I18N_MAINT.transfer_warn_partial);
        }

        const modalSas = document.getElementById('modalSasValidation');
        if (modalSas && modalSas.style.display !== 'none' && typeof ouvrirSasValidation === 'function') {
            ouvrirSasValidation(false);
        }
        await loadData();
        await aspirineAlert(I18N_MAINT.transfer_success_title, I18N_MAINT.transfer_success_msg);
    } catch (e) {
        await aspirineAlert(I18N_MAINT.err_network_title, I18N_MAINT.retry);
    }
}

async function demanderSuppression(id) {
    if (!isAdmin) {
        await aspirineAlert(I18N_MAINT.access_denied_title, I18N_MAINT.del_no_rights_delete_single);
        return;
    }

    idToDelete = id;
    
    const modalDel = document.getElementById('modalConfirmDel');
    if (modalDel) {
        // Force la modale de confirmation à passer au premier plan, devant le SAS de validation
        modalDel.style.zIndex = "200000"; 
        modalDel.style.display = 'block';
    }

    document.getElementById('btnConfirmDeleteFinal').onclick = async function() {
        await executerSuppressionRéelle();
    };
}

function closeConfirmDel() {
    document.getElementById('modalConfirmDel').style.display = 'none';
    idToDelete = null;
    
    // Remet le texte d'origine pour la prochaine suppression individuelle
    const textZone = document.querySelector('#modalConfirmDel p');
    if (textZone) {
        textZone.innerHTML = `${I18N_MAINT.del_single_confirm}<br><span style="font-weight: bold; color: var(--danger);">${I18N_MAINT.del_irreversible}</span>`;
    }
}

async function executerSuppressionRéelle() {
    if (!idToDelete) return;

    try {
        const response = await fetch(`api.php?delete=${idToDelete}`);
        
        if (response.ok) {
            closeConfirmDel();
            
            // Rechargement des statistiques et du tableau principal en tâche de fond
            if (typeof loadData === 'function') {
                await loadData(); 
            }
            
            // SYNC EN TEMPS RÉEL : Si le SAS de validation est ouvert, on le rafraîchit immédiatement !
            // (false : simple resynchro des données, ne doit pas ramener de force à la liste de
            // badges un technicien qui est en train de lire une fiche sur téléphone/tablette)
            const modalSas = document.getElementById('modalSasValidation');
            if (modalSas && modalSas.style.display !== 'none') {
                if (typeof ouvrirSasValidation === 'function') {
                    await ouvrirSasValidation(false);
                }
            } else {
                // Si on était sur le tableau classique, on rafraîchit proprement la page
                location.reload();
            }
        } else {
            await aspirineAlert(I18N_MAINT.err_title, I18N_MAINT.del_err_server);
        }
    } catch (error) {
        console.error("Erreur technique lors du refus/suppression:", error);
    }
}

// --- ÉTAT VISUEL "ACTIF" DES PUCES DE FILTRAGE RAPIDE ---
let activeChipKeyOT = null;

function setActiveChipOT(key) {
    activeChipKeyOT = key;
    applyActiveChipOT();
}

function applyActiveChipOT() {
    document.querySelectorAll('#globalRecap .recap-item').forEach(el => {
        el.classList.toggle('chip-active', activeChipKeyOT !== null && el.dataset.chipKey === activeChipKeyOT);
    });
}

function clearGlobalSearchOT() {
    const searchEl = document.getElementById('global-search-input');
    if (searchEl) searchEl.value = "";
}

function filtrerNonAssigneBouton() {
    setActiveChipOT('non_assigne');
    clearGlobalSearchOT();
    // 1. On vide tous les autres filtres textes pour ne pas interférer
    document.querySelectorAll('.col-filter').forEach(f => f.value = "");

    // 2. On isole la colonne "Intervenants" et on cherche le tiret "-" (absence de technicien)
    if (!historiqueFullyRendered) renderHistoriqueBody(true);
    const tr = document.getElementById("tableBody").getElementsByTagName("tr");
    let offset = isAdmin ? 1 : 0;
    
    for (let i = 0; i < tr.length; i++) {
        // La colonne des intervenants est l'index 4 (ou 5 si la case à cocher Admin est affichée)
        let tdIntervenants = tr[i].getElementsByTagName("td")[4 + offset];
        if (tdIntervenants) {
            let texte = tdIntervenants.textContent || tdIntervenants.innerText;
            // On affiche uniquement les lignes où le texte de la colonne est strictement "-"
            tr[i].style.display = texte.trim() === "-" ? "" : "none";
        }
    }
    
    // On met à jour l'affichage du bouton de suppression de masse si nécessaire
    if (typeof toggleBulkDeleteBtn === 'function') toggleBulkDeleteBtn();
}

// --- CHIRURGIE : MOTEURS DE FILTRAGE DIRECTS POUR LES BOUTONS-COMPTEURS ---

function filtrerStatutBouton(valeur) {
    setActiveChipOT('statut:' + valeur);
    clearGlobalSearchOT();
    // 1. On vide tous les autres filtres pour ne pas accumuler et bloquer le tableau
    document.querySelectorAll('.col-filter').forEach(f => f.value = "");

    // 2. On synchronise le visuel du menu déroulant "Statut" pour l'utilisateur
    const fStatut = document.getElementById('filter-statut');
    if (fStatut) {
        if (valeur === "Terminée") {
            fStatut.value = "Terminée";
        } else {
            fStatut.value = valeur;
        }
    }

    // 3. On force le filtrage ligne par ligne de façon ultra-robuste
    executerFiltrageDirectBouton('statut', valeur);
}

function filtrerVisBouton() {
    setActiveChipOT('verif_vis');
    clearGlobalSearchOT();
    // 1. On vide tous les filtres texte pour repartir à zéro
    document.querySelectorAll('.col-filter').forEach(f => f.value = "");
    
    // 2. On filtre ligne par ligne selon l'attribut data-verif invisible
    if (!historiqueFullyRendered) renderHistoriqueBody(true);
    const tr = document.getElementById("tableBody").getElementsByTagName("tr");
    for (let i = 0; i < tr.length; i++) {
        // On affiche uniquement les lignes où le technicien a coché la visserie
        tr[i].style.display = (tr[i].getAttribute('data-verif') === "1") ? "" : "none";
    }
    
    // On met à jour le bouton de suppression de masse si besoin
    if (typeof toggleBulkDeleteBtn === 'function') toggleBulkDeleteBtn();
}

function filtrerTypeOuCasseBouton(idFiltre, valeur) {
    setActiveChipOT(idFiltre === 'filter-casse' ? 'casse' : ('type:' + valeur));
    clearGlobalSearchOT();
    // 1. On vide tous les autres filtres pour repartir à zéro
    document.querySelectorAll('.col-filter').forEach(f => f.value = "");

    // 2. On synchronise le visuel du menu déroulant concerné (Type ou Casse)
    const selectFiltre = document.getElementById(idFiltre);
    if (selectFiltre) {
        if (idFiltre === 'filter-casse') {
            selectFiltre.value = "CASSE";
        } else {
            selectFiltre.value = valeur;
        }
    }

    // 3. On force le filtrage ligne par ligne de façon ultra-robuste
    executerFiltrageDirectBouton(idFiltre === 'filter-casse' ? 'casse' : 'type', valeur);
}

function executerFiltrageDirectBouton(cible, recherche) {
    // Un filtre par bouton-compteur doit chercher parmi TOUS les bons, pas seulement les N derniers affichés.
    if (!historiqueFullyRendered) renderHistoriqueBody(true);
    const tr = document.getElementById("tableBody").getElementsByTagName("tr");
    const motCle = recherche.toLowerCase().trim();
    // On gère le décalage si la colonne des cases à cocher Admin est présente
    const offset = (typeof isAdmin !== 'undefined' && isAdmin) ? 1 : 0;

    for (let i = 0; i < tr.length; i++) {
        let textLigne = tr[i].innerText.toLowerCase();
        let cellules = tr[i].getElementsByTagName("td");
        let show = true;

        if (cible === 'statut') {
            if (motCle === 'message_non_lu') {
                // CHIRURGIE : Le bouton filtre directement les lignes avec la pastille rouge
                let badge = tr[i].querySelector('[id^="badge-ticket-"]');
                if (!badge || badge.style.display === 'none' || !badge.classList.contains('blink-sirene')) show = false;
            } else {
                // CHIRURGIE : On cible uniquement la cellule de la colonne "Statut" (Index 6 + décalage Admin)
                let tdStatut = cellules[6 + offset];
                if (tdStatut) {
                    let textStatut = (tdStatut.textContent || tdStatut.innerText).toLowerCase();
                    if (motCle.includes("termin")) {
                        if (!textStatut.includes("terminé") && !textStatut.includes("terminée")) show = false;
                    } else if (motCle.includes("faire")) {
                        if (!textStatut.includes("à faire")) show = false;
                    } else {
                        if (!textStatut.includes(motCle)) show = false;
                    }
                }
            }
        } 
        else if (cible === 'type') {
            if (!textLigne.includes(motCle)) show = false;
        } 
        else if (cible === 'casse') {
            // --- CHIRURGIE FINALE SUR LA CASSE ---
            let indexCasse = cellules.length - 2; 
            let celluleCasse = cellules[indexCasse];

            if (celluleCasse) {
                let aUnEclairCasse = celluleCasse.querySelector('.fa-bolt') !== null;
                if (!aUnEclairCasse) {
                    show = false;
                }
            }
        }

        // On applique le masquage ou l'affichage de la ligne
        tr[i].style.display = show ? "" : "none";
    }

    if (typeof toggleBulkDeleteBtn === 'function') toggleBulkDeleteBtn();
}

// ============================================================================
// SAUVEGARDE DU RAPPORT INTERMÉDIAIRE (SUIVI VIVANT)
// ============================================================================
async function sauvegarderNoteIntermediaire(id) {
    const textareaNote = document.getElementById('note-intermediaire-' + id);
    if (!textareaNote) return;

    const note = textareaNote.value;
    const btn = document.getElementById('btn-save-note-' + id);
    
    // Effet visuel pendant la sauvegarde
    const oldText = btn.innerHTML;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> ' + I18N_MAINT.saving;
    btn.disabled = true;

    try {
        const response = await fetch('api.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                id: id,
                rapport_intermediaire: note,
                action: 'update_note'
            })
        });

        if (response.ok) {
            await aspirineAlert(I18N_MAINT.success_title, I18N_MAINT.note_success_msg);
            await loadData(); // Rafraîchit les données en arrière-plan
        } else {
            await aspirineAlert(I18N_MAINT.err_title, I18N_MAINT.err_server_refused);
        }
    } catch(e) {
        console.error("Erreur de sauvegarde de la note:", e);
        await aspirineAlert(I18N_MAINT.err_network_title, I18N_MAINT.err_network_unreachable);
    }

    // On remet le bouton à son état normal
    btn.innerHTML = oldText;
    btn.disabled = false;
}

// ============================================================================
// MOTEUR DE RECHERCHE POUR LA MODALE HISTORIQUE TECHNICIEN
// ============================================================================
window.filterHistory = function() {
    const fBi = document.getElementById('fh-bi').value.toLowerCase();
    const fDate = document.getElementById('fh-date').value;
    const fLoc = document.getElementById('fh-loc').value.toLowerCase();
    const fDesc = document.getElementById('fh-desc').value.toLowerCase();
    const fStatut = document.getElementById('fh-statut').value.toLowerCase();

    // La GMAO affiche les dates en JJ/MM/AAAA, mais l'input type="date" crache du AAAA-MM-JJ. On inverse :
    let formattedDate = "";
    if (fDate) {
        formattedDate = fDate.split('-').reverse().join('/');
    }

    // On cible uniquement les lignes du tableau de l'historique
    const tr = document.getElementById("histTableBody").getElementsByTagName("tr");

    for (let i = 0; i < tr.length; i++) {
        let show = true;
        const td = tr[i].getElementsByTagName("td");

        // Si c'est bien une ligne de données (et pas un tableau imbriqué)
        if (td.length === 7) {
            const txtBi = (td[0].textContent || td[0].innerText).toLowerCase();
            const txtDate = (td[1].textContent || td[1].innerText);
            const txtLoc = (td[2].textContent || td[2].innerText).toLowerCase();
            const txtDesc = (td[3].textContent || td[3].innerText).toLowerCase();
            const txtStatut = (td[5].textContent || td[5].innerText).toLowerCase();

            // Si le texte tapé ne se trouve pas dans la colonne, on cache la ligne
            if (fBi && txtBi.indexOf(fBi) === -1) show = false;
            if (formattedDate && txtDate.indexOf(formattedDate) === -1) show = false;
            if (fLoc && txtLoc.indexOf(fLoc) === -1) show = false;
            if (fDesc && txtDesc.indexOf(fDesc) === -1) show = false;
            if (fStatut && txtStatut.indexOf(fStatut) === -1) show = false;
        }
        
        tr[i].style.display = show ? "" : "none";
    }
};

</script>

<div id="customConfirm" style="display:none; position:fixed; z-index:99999; left:0; top:0; width:100%; height:100%; background:rgba(0,0,0,0.5); backdrop-filter: blur(3px);">
    <div style="background:white; width:350px; margin:15% auto; padding:20px; border-radius:12px; text-align:center; box-shadow: 0 10px 25px rgba(0,0,0,0.2); border-top: 5px solid var(--brand-orange);">
        <i class="fa-solid fa-circle-question" style="font-size:3rem; color:var(--brand-orange); margin-bottom:15px;"></i>
        <h3 id="confirmTitle" style="margin:10px 0; color:var(--dark-blue);"><?php echo htmlspecialchars(t('maint.confirm_default_title')); ?></h3>
        <p id="confirmMessage" style="color:#666; font-size:0.9rem; margin-bottom:20px;"><?php echo htmlspecialchars(t('maint.confirm_default_msg')); ?></p>
        <div style="display:flex; justify-content:center; gap:10px;">
            <button id="confirmCancel" style="padding:10px 20px; border:none; border-radius:6px; background:#eee; cursor:pointer; font-weight:bold;"><?php echo htmlspecialchars(t('maint.cancel')); ?></button>
            <button id="confirmOk" style="padding:10px 20px; border:none; border-radius:6px; background:var(--brand-orange); color:white; cursor:pointer; font-weight:bold;"><?php echo htmlspecialchars(t('maint.confirm_btn')); ?></button>
        </div>
    </div>
</div>

<div id="customAlert" style="display:none; position:fixed; z-index:99999; left:0; top:0; width:100%; height:100%; background:rgba(0,0,0,0.5); backdrop-filter: blur(3px);">
    <div style="background:white; width:350px; margin:15% auto; padding:20px; border-radius:12px; text-align:center; box-shadow: 0 10px 25px rgba(0,0,0,0.2); border-top: 5px solid #27ae60;">
        <i class="fa-solid fa-circle-check" style="font-size:3rem; color:#27ae60; margin-bottom:15px;"></i>
        <h3 id="alertTitle" style="margin:10px 0; color:var(--dark-blue);"><?php echo htmlspecialchars(t('maint.alert_default_title')); ?></h3>
        <p id="alertMessage" style="color:#666; font-size:0.9rem; margin-bottom:20px;"><?php echo htmlspecialchars(t('maint.alert_default_msg')); ?></p>
        <div style="display:flex; justify-content:center;">
            <button id="alertOk" style="padding:10px 30px; border:none; border-radius:6px; background:#27ae60; color:white; cursor:pointer; font-weight:bold;"><?php echo htmlspecialchars(t('maint.ok')); ?></button>
        </div>
    </div>
</div>

<div id="fiche-tache-print"></div>

<div id="modalClotureBI" class="modal" style="display:none;">
    <input type="hidden" id="cloture-id">
    <div class="modal-content" style="max-width:900px !important; max-height: 92vh; padding:0 !important; border-radius:12px !important; border:none !important; overflow:hidden; background: #fff; box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.2); display: flex; flex-direction: column; margin: 2% auto;">

        <div style="padding: 16px 25px; background: white; border-bottom: 1px solid #f1f5f9; display: flex; justify-content: space-between; align-items: center; flex-shrink: 0;">
            <div style="display: flex; align-items: center; gap: 12px;">
                <div style="background: var(--soft-orange); color: var(--brand-orange); width: 40px; height: 40px; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 1.2rem;">
                    <i class="fa-solid fa-file-signature"></i>
                </div>
                <div>
                    <h3 style="margin:0; font-family:'Caveat', cursive; font-size: 1.8rem; color: var(--primary); font-weight: 500;"><?php echo htmlspecialchars(t('maint.cloture_title')); ?></h3>
                    <div style="font-size: 0.85rem; color: #64748b; font-weight: 500;">
                        <?php echo htmlspecialchars(t('maint.cloture_bi_prefix')); ?><span id="cloture-titre-bi" style="color: var(--brand-orange); font-weight: 700;"></span>
                    </div>
                </div>
            </div>
            <span onclick="document.getElementById('modalClotureBI').style.display='none'" style="cursor:pointer; font-size:28px; color:#cbd5e1;" onmouseover="this.style.color='#94a3b8'" onmouseout="this.style.color='#cbd5e1'">&times;</span>
        </div>

        <div style="padding: 18px 25px; background: #f8fafc; overflow-y: auto; flex-grow: 1;">

            <!-- Deux colonnes sur écran large (≥ ~700px de contenu) pour tenir sans scroller sur PC ;
                 repasse naturellement en 1 colonne sur téléphone/tablette (auto-fit, pas de media query). -->
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 20px; align-items: start;">

                <!-- COLONNE GAUCHE : identité du bon, même présentation que la fiche "Rapport
                     d'intervention" (composant_rapport.php) — à la demande de David, la clôture
                     doit donner accès à toutes les infos du BI. -->
                <div>
                    <div id="cloture-badges" style="display:flex; gap:5px; flex-wrap:wrap; margin-bottom: 10px;"></div>

                    <div style="display: flex; flex-direction: column; gap: 10px; margin-bottom: 10px;">
                        <div style="background: white; padding: 10px 12px; border-radius: 8px; border: 1px solid #e2e8f0;">
                            <h4 style="margin: 0 0 6px 0; font-size: 0.62rem; color: #94a3b8; text-transform: uppercase; border-bottom: 1px solid #e2e8f0; padding-bottom: 4px; font-weight: 700;"><i class="fa-solid fa-info-circle"></i> <?php echo htmlspecialchars(t('rapport.origine_demande')); ?></h4>
                            <div style="display: grid; grid-template-columns: 90px 1fr; gap: 4px; font-size: 0.75rem;">
                                <span style="color: #64748b;"><?php echo htmlspecialchars(t('rapport.signale_le')); ?></span> <span id="cloture-info-signale" style="color: #2c3e50; font-weight: 600;">---</span>
                                <span style="color: #64748b;"><?php echo htmlspecialchars(t('rapport.prevu_le')); ?></span> <span id="cloture-info-prevu" style="color: #3498db; font-weight: bold;">---</span>
                                <span style="color: #64748b;"><?php echo htmlspecialchars(t('rapport.emetteur')); ?></span> <span id="cloture-info-emetteur" style="color: #2c3e50; font-weight: 600;">---</span>
                                <span style="color: #64748b;"><?php echo htmlspecialchars(t('rapport.priorite_label')); ?></span> <span id="cloture-info-priorite" style="color: #2c3e50; font-weight: bold;">---</span>
                                <div id="cloture-info-st-wrap" style="display:none;">
                                    <span style="color: #64748b;"><?php echo htmlspecialchars(t('rapport.sous_traitant_label')); ?></span> <span id="cloture-info-st" style="color: var(--brand-orange); font-weight: bold;"></span>
                                </div>
                            </div>
                        </div>

                        <div style="background: #f0f7ff; padding: 10px 12px; border-radius: 8px; border: 1px solid #bae6fd;">
                            <h4 style="margin: 0 0 6px 0; font-size: 0.62rem; color: #38bdf8; text-transform: uppercase; border-bottom: 1px solid #bae6fd; padding-bottom: 4px; font-weight: 700;"><i class="fa-solid fa-location-dot"></i> <?php echo htmlspecialchars(t('rapport.localisation_precise')); ?></h4>
                            <div style="display: grid; grid-template-columns: 65px 1fr; gap: 4px; font-size: 0.75rem;">
                                <span style="color: #0284c7;"><?php echo htmlspecialchars(t('rapport.usine_label')); ?></span> <span id="cloture-info-usine" style="color: #2c3e50;">---</span>
                                <span style="color: #0284c7;"><?php echo htmlspecialchars(t('rapport.secteur_label')); ?></span> <span id="cloture-info-secteur" style="color: #2c3e50;">---</span>
                                <div id="cloture-info-ligne-wrap" style="display:none;">
                                    <span style="color: #0284c7;"><?php echo htmlspecialchars(t('rapport.ligne_label')); ?></span> <span id="cloture-info-ligne" style="color: #2c3e50;"></span>
                                </div>
                                <span style="color: #0284c7;"><?php echo htmlspecialchars(t('rapport.zone_label')); ?></span> <span id="cloture-info-zone" style="color: #2c3e50;">---</span>
                                <span style="color: #0284c7;"><?php echo htmlspecialchars(t('rapport.machine_label')); ?></span> <span id="cloture-info-machine" style="font-weight: bold; color: #2c3e50;">---</span>
                            </div>
                        </div>
                    </div>

                    <div style="background: white; border-radius: 5px; padding: 10px 12px; border: 1px solid #f1f5f9; border-left: 4px solid #f39c12;">
                        <label style="display: block; font-size: 0.62rem; font-weight: 700; color: #f39c12; text-transform: uppercase; margin-bottom: 4px;"><i class="fa-solid fa-triangle-exclamation"></i> <?php echo htmlspecialchars(t('rapport.panne_demande')); ?></label>
                        <div id="cloture-info-desc" style="font-size: 0.85rem; color: #64748b; font-style: italic; line-height: 1.4;">---</div>
                    </div>

                    <div id="cloture-readonly-notice" style="display:none; background:#fff8ec; border:1px solid #f5dcae; border-radius:8px; padding:10px 12px; margin-top:10px; font-size:0.8rem; color:#8a6116; align-items:center; gap:8px;">
                        <i class="fa-solid fa-lock"></i> <?php echo htmlspecialchars(t('maint.cloture_readonly_notice')); ?>
                    </div>
                </div>

                <!-- COLONNE DROITE : temps et clôture -->
                <div>
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-bottom: 10px;">
                        <div style="background: white; padding: 12px; border-radius: 8px; border: 1px solid #e2e8f0; border-left: 4px solid var(--brand-orange);">
                            <label style="display:block; color: #e67e22; font-size: 0.65rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 5px;"><?php echo htmlspecialchars(t('maint.cloture_total_time')); ?></label>
                            <span id="cloture-info-total-h" style="font-size: 1.1rem; color: #d35400; font-weight: 700;">0.00 h</span>
                        </div>
                        <div style="background: white; padding: 12px; border-radius: 8px; border: 1px solid #e2e8f0;">
                            <label style="display:block; color: #94a3b8; font-size: 0.65rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 5px;"><?php echo htmlspecialchars(t('maint.cloture_intervenants')); ?></label>
                            <span id="cloture-info-intervenants" style="font-size: 0.9rem; color: var(--accent); font-weight: 600;">---</span>
                        </div>
                    </div>

                    <div style="margin-bottom: 10px;">
                        <label style="display:block; color: var(--primary); font-size: 0.7rem; font-weight: 700; text-transform: uppercase; margin-bottom: 8px; display: flex; align-items: center; gap: 6px;">
                            <i class="fa-solid fa-pen-nib" style="color: var(--brand-green);"></i> <?php echo htmlspecialchars(t('maint.cloture_rapport_label')); ?>
                        </label>
                        <textarea id="cloture-cr" placeholder="<?php echo htmlspecialchars(t('maint.cloture_rapport_placeholder')); ?>"
                            style="width:100%; height:78px; min-height:78px; border:1px solid #cbd5e1; border-radius:8px; padding:12px; font-size:0.9rem; outline:none; resize: vertical; box-sizing: border-box; font-family: inherit; color: #334155; transition: border 0.2s;"
                            onfocus="this.style.border='1px solid var(--accent)'" onblur="this.style.border='1px solid #cbd5e1'"></textarea>
                    </div>

                    <div style="border: 1px solid #f1f5f9; padding: 12px; border-radius: 8px; background:#fff; margin-bottom: 10px;">
                        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom: 8px;">
                            <label style="display:block; font-size: 0.65rem; font-weight: 700; text-transform: uppercase; color:#94a3b8; margin:0;"><?php echo htmlspecialchars(t('maint.cloture_detail_temps')); ?></label>
                            <button id="cloture-btn-temps" onclick="ouvrirModalPointage(document.getElementById('cloture-id').value)"
                                style="background: var(--dark-blue); color: white; border: none; border-radius: 6px; padding: 5px 10px; font-size: 0.7rem; font-weight: 700; cursor: pointer; display:flex; align-items:center; gap:5px;">
                                <i class="fa-solid fa-clock"></i> <?php echo htmlspecialchars(t('maint.plus_temps')); ?>
                            </button>
                        </div>
                        <div id="cloture-recap-temps" style="max-height: 110px; overflow-y: auto;"></div>
                    </div>

                    <div style="background: #fff; border: 1px solid #e2e8f0; border-radius: 6px; overflow: hidden;">
                        <div style="background: #f8fafc; padding: 5px 10px; border-bottom: 1px solid #e2e8f0; display:flex; justify-content:space-between; align-items:center;">
                            <h4 style="margin: 0; font-size: 0.65rem; color: #2c3e50; text-transform: uppercase; font-weight: 600;"><i class="fa-solid fa-camera"></i> <?php echo htmlspecialchars(t('rapport.photos_title')); ?></h4>
                            <span id="cloture-photos-count" style="font-size: 0.58rem; color: #64748b;"></span>
                        </div>
                        <div id="cloture-photos-body" style="padding: 10px; display:flex; flex-wrap:wrap; gap:8px; max-height: 140px; overflow-y: auto;"></div>
                    </div>
                </div>
            </div>
        </div>

        <div style="padding: 15px 25px; background: white; display: flex; justify-content: flex-end; gap: 12px; border-top: 1px solid #f1f5f9; flex-shrink: 0;">
            <button onclick="document.getElementById('modalClotureBI').style.display='none'"
                style="padding: 8px 20px; border-radius: 6px; border: 1px solid #e2e8f0; background: white; color: #64748b; cursor: pointer; font-weight: 600; font-size: 0.85rem;">
                <?php echo htmlspecialchars(t('maint.cancel')); ?>
            </button>
            <button id="cloture-btn-enregistrer-ouvert" onclick="enregistrerSansCloture()"
                style="padding: 8px 20px; border-radius: 6px; border: 1px solid var(--accent); background: white; color: var(--accent); cursor: pointer; font-weight: 700; font-size: 0.85rem;">
                <i class="fa-solid fa-floppy-disk"></i> <?php echo htmlspecialchars(t('maint.cloture_btn_enregistrer_ouvert')); ?>
            </button>
            <button id="cloture-btn-confirmer" onclick="window.validerCloture()"
                style="padding: 8px 25px; border-radius: 6px; border: none; background: var(--brand-green); color: white; cursor: pointer; font-weight: 700; font-size: 0.85rem; transition: 0.2s;"
                onmouseover="this.style.background='#27ae60'" onmouseout="this.style.background='var(--brand-green)'">
                <?php echo htmlspecialchars(t('maint.cloture_btn_confirmer')); ?>
            </button>
        </div>
    </div>
</div>

<div id="modalAlerteRapport" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.6); z-index:110000; backdrop-filter: blur(3px); align-items:center; justify-content:center;">
    <div style="background:white; width:350px; padding:25px; border-radius:16px; box-shadow: 0 15px 35px rgba(0,0,0,0.3); text-align:center; border-top: 6px solid var(--danger);">
        
        <div style="background:#fff0f0; width:60px; height:60px; border-radius:50%; display:flex; align-items:center; justify-content:center; margin:0 auto 15px auto; color:var(--danger); font-size:2rem;">
            <i class="fa-solid fa-triangle-exclamation"></i>
        </div>
        
        <h3 style="margin:0 0 10px 0; color:var(--primary); font-family:'Caveat', cursive; font-size:1.8rem;"><?php echo htmlspecialchars(t('maint.alerte_rapport_title')); ?></h3>
        <p style="font-size:0.9rem; color:#666; margin-bottom:20px; line-height:1.4;">
            <?php echo t('maint.alerte_rapport_msg'); ?>
        </p>

        <button onclick="document.getElementById('modalAlerteRapport').style.display='none'"
                style="width:100%; padding:12px; border-radius:8px; border:none; background:var(--danger); color:white; cursor:pointer; font-weight:bold; font-family:inherit; box-shadow: 0 4px 0 #c0392b;">
            <?php echo htmlspecialchars(t('maint.alerte_rapport_btn')); ?>
        </button>
    </div>
</div>

<div id="modalSasValidation" class="modal" style="display:none; align-items:center; justify-content:center; background:rgba(15, 23, 42, 0.7);">
    <div class="modal-content" style="max-width:1150px; width:95%; border-top: 5px solid var(--brand-orange); background:#fdfdfd; padding:0; border-radius:12px; box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.2);">

        <div class="sas-modal-header">
            <div class="sas-modal-heading">
                <div class="sas-modal-icon"><i class="fa-solid fa-bell-concierge"></i></div>
                <div>
                    <h2 class="sas-modal-title"><?php echo htmlspecialchars(t('maint.sas_modal_title')); ?></h2>
                    <p class="sas-modal-subtitle"><?php echo htmlspecialchars(t('maint.sas_modal_subtitle')); ?></p>
                </div>
            </div>
            <button class="sas-modal-close-btn" onclick="document.getElementById('modalSasValidation').style.display='none'"><?php echo htmlspecialchars(t('maint.sas_fermer')); ?></button>
        </div>

        <div id="liste-sas-content"></div>
    </div>
</div>

<div id="modalConfirmDel" class="modal" onclick="if(event.target == this) closeConfirmDel()">
    <div class="modal-content" style="max-width: 400px !important; border-top: 6px solid var(--danger); text-align: center; padding: 30px; border-radius: 15px;">
        <div style="color: var(--danger); font-size: 4rem; margin-bottom: 15px;">
            <i class="fa-solid fa-circle-exclamation"></i>
        </div>
        <h2 style="font-family: 'Caveat', cursive; font-size: 2.2rem; color: var(--primary); margin: 0 0 10px 0;"><?php echo htmlspecialchars(t('maint.confirm_del_title')); ?></h2>
        <p style="color: #64748b; font-size: 0.95rem; line-height: 1.5; margin-bottom: 25px;">
            <?php echo htmlspecialchars(t('maint.del_single_confirm')); ?><br>
            <span style="font-weight: bold; color: var(--danger);"><?php echo htmlspecialchars(t('maint.del_irreversible')); ?></span>
        </p>

        <div style="display: flex; gap: 10px; justify-content: center;">
            <button onclick="closeConfirmDel()" style="flex: 1; background: #f1f5f9; border: none; color: #64748b; padding: 12px; border-radius: 8px; font-weight: 700; cursor: pointer; transition: 0.2s;">
                <?php echo htmlspecialchars(mb_strtoupper(t('maint.cancel'))); ?>
            </button>
            <button id="btnConfirmDeleteFinal" style="flex: 1; background: var(--danger); border: none; color: white; padding: 12px; border-radius: 8px; font-weight: 700; cursor: pointer; transition: 0.2s; box-shadow: 0 4px 12px rgba(231, 76, 60, 0.2);">
                <?php echo htmlspecialchars(mb_strtoupper(t('maint.del_supprimer'))); ?>
            </button>
        </div>
    </div>
</div>

<?php include 'composant_rapport.php'; ?>

<script>

// --- LE RADAR DE MESSAGERIE (MAINTENANCE) ---
// Compteur de messages non lus par ticket (task_id -> nb), tenu à jour à chaque poll ci-dessous.
// Lu par composant_rapport.php pour afficher une pastille sur le bouton "Messagerie" du rapport.
let notifTicketsNonLus = {};

async function verifierNotifications() {
    try {
        const res = await fetch('api.php?action=check_notifications&t=' + Date.now());
        const data = await res.json();

        notifTicketsNonLus = {};
        (data.tickets || []).forEach(ticket => { notifTicketsNonLus[String(ticket.task_id)] = ticket.nb; });

        // 1. Bouton global dans le compteur du haut
        const btnRecap = document.getElementById('btn-recap-messages');
        const compteurGlobal = document.getElementById('compteur-global-messages');
        
        if (btnRecap && compteurGlobal) {
            if (data.non_lus > 0 && data.tickets && data.tickets.length > 0) {
                btnRecap.style.display = 'flex';
                compteurGlobal.innerText = data.non_lus;
            } else {
                btnRecap.style.display = 'none';
                // Si on était filtré sur les messages et qu'il n'y en a plus, on remet tout
                if (document.getElementById('filter-statut').value === "MESSAGE_NON_LU") {
                    document.getElementById('filter-statut').value = "";
                    multiFilter();
                }
            }
        }
        
        // 2. Pastilles par Ticket dans le grand tableau : affichent en permanence le nombre TOTAL
        // de messages échangés sur ce BI (bleu neutre, .badge-msg-count), et basculent en rouge
        // clignotant (.blink-sirene) tant qu'il reste au moins un message non lu pour cet utilisateur.
        document.querySelectorAll('[id^="badge-ticket-"]').forEach(el => { el.style.display = 'none'; el.classList.remove('blink-sirene'); });

        const idsAvecNonLu = new Set((data.tickets || []).map(ticket => String(ticket.task_id)));

        if (data.totaux) {
            Object.entries(data.totaux).forEach(([taskId, nb]) => {
                // Deux pastilles pour le même BI : une dans la ligne du tableau (desktop), une dans la
                // carte équivalente (tablette/téléphone, voir buildHistoriqueCardsHtml) — les deux doivent
                // s'allumer ensemble.
                [document.getElementById('badge-ticket-' + taskId), document.getElementById('badge-ticket-card-' + taskId)].forEach(pastilleTicket => {
                    if (pastilleTicket) {
                        pastilleTicket.style.display = 'inline-block';
                        pastilleTicket.innerText = nb;
                        pastilleTicket.classList.toggle('blink-sirene', idsAvecNonLu.has(taskId));
                    }
                });
            });
        }
        
        // 3. Auto-actualisation du tableau si le filtre "Messages non lus" est actif
        if (document.getElementById('filter-statut').value === "MESSAGE_NON_LU") {
            multiFilter();
        }
        
    } catch(e) {}
}

// On lance le radar toutes les 5 secondes
setInterval(verifierNotifications, 5000);
// On vérifie immédiatement
verifierNotifications();
// On demande au tableau de revérifier les notifications à chaque fois qu'on crée ou modifie un BI
const oldRender = render;
render = function() {
    oldRender();
    verifierNotifications();
};

</script>

</body>
</html>