<?php
require_once __DIR__ . '/session_init.php';
date_default_timezone_set('Europe/Paris');

error_reporting(0);
ini_set('display_errors', 0);

header("Content-Type: application/json; charset=utf-8");
require_once 'db.php';

// SÉCURITÉ : api.php manipule toutes les interventions (lecture ET écriture).
// Toutes les pages qui l'appellent exigent déjà une session active ; ce verrou
// bloque uniquement les appels directs anonymes (hors de l'appli).
if (!isset($_SESSION['user'])) {
    http_response_code(401);
    echo json_encode(["status" => "error", "message" => "Non autorisé"]);
    exit;
}

try {
    $db->exec("ALTER TABLE taches ADD COLUMN IF NOT EXISTS demandeur VARCHAR(255)");
    $db->exec("ALTER TABLE taches ADD COLUMN IF NOT EXISTS casse TINYINT(1) DEFAULT 0");
    $db->exec("ALTER TABLE taches ADD COLUMN IF NOT EXISTS verif_vis TINYINT(1) DEFAULT 0");
    $db->exec("ALTER TABLE taches ADD COLUMN IF NOT EXISTS compte_rendu TEXT");
    $db->exec("ALTER TABLE taches ADD COLUMN IF NOT EXISTS date_creation DATETIME");
    $db->exec("ALTER TABLE taches ADD COLUMN IF NOT EXISTS rapport_intermediaire TEXT");
    $db->exec("ALTER TABLE taches ADD COLUMN IF NOT EXISTS st_sur_site TINYINT(1) DEFAULT 0");

    // CHIRURGIE : Sécurité pour s'assurer que la colonne "ligne" existe bien dans la BDD
    $db->exec("ALTER TABLE taches ADD COLUMN IF NOT EXISTS ligne VARCHAR(255)");

    // Lien de traçabilité vers la règle de maintenance préventive à l'origine du ticket
    $db->exec("ALTER TABLE taches ADD COLUMN IF NOT EXISTS rule_id VARCHAR(64) DEFAULT NULL");

    // Motif saisi par un admin lorsqu'une demande de travaux est refusée
    $db->exec("ALTER TABLE taches ADD COLUMN IF NOT EXISTS motif_refus TEXT DEFAULT NULL");

    $db->exec("CREATE TABLE IF NOT EXISTS taches_messages (
        id INT AUTO_INCREMENT PRIMARY KEY,
        task_id VARCHAR(255),
        expediteur VARCHAR(100),
        message TEXT,
        lu TINYINT(1) DEFAULT 0,
        date_envoi DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
} catch (Exception $e) {}

function writeLog($db, $user, $type, $details) {
    try {
        $stmt = $db->prepare("INSERT INTO historique (utilisateur, action_type, details) VALUES (?, ?, ?)");
        $stmt->execute([$user, $type, $details]);
    } catch (Exception $e) {}
}

$method = $_SERVER['REQUEST_METHOD'];

if (isset($_GET['action']) && $_GET['action'] === 'get_chat_messages') {
    try {
        $stmt = $db->prepare("SELECT * FROM taches_messages WHERE task_id = ? ORDER BY date_envoi ASC");
        $stmt->execute([$_GET['task_id']]);
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    } catch (Exception $e) {
        echo json_encode([]);
    }
    exit;
}

if (isset($_GET['action']) && $_GET['action'] === 'check_notifications') {
    if (!isset($_SESSION['user'])) { echo json_encode(["non_lus" => 0, "tickets" => []]); exit; }
    try {
        $is_maintenance = (isset($_SESSION['role']) && in_array(strtolower($_SESSION['role']), ['admin', 'technicien']));
        $condition_expediteur = $is_maintenance ? 
            "AND tm.expediteur NOT IN (SELECT username FROM utilisateurs WHERE role IN ('admin', 'technicien'))" : 
            "AND tm.expediteur IN (SELECT username FROM utilisateurs WHERE role IN ('admin', 'technicien'))";

        $stmtTickets = $db->prepare("
            SELECT tm.task_id, COUNT(*) as nb, t.num_bi 
            FROM taches_messages tm 
            JOIN taches t ON t.id = tm.task_id 
            WHERE tm.lu = 0 
            AND tm.expediteur != ?
            $condition_expediteur
            GROUP BY tm.task_id
        ");
        $stmtTickets->execute([$_SESSION['user']]);
        $ticketsRaw = $stmtTickets->fetchAll(PDO::FETCH_ASSOC);

        $tickets = [];
        foreach ($ticketsRaw as $row) {
            $tickets[] = [
                "task_id" => $row['task_id'],
                "nb" => $row['nb'],
                "bi" => !empty($row['num_bi']) ? $row['num_bi'] : "Sans BI"
            ];
        }

        // Nombre TOTAL de messages par BI (lus + non lus, tous expéditeurs confondus) — sert à
        // afficher une pastille en permanence sur chaque ticket ayant un historique de discussion,
        // pas seulement ceux avec du non-lu (cf. badge-ticket-* dans maintenance.php).
        $totaux = [];
        foreach ($db->query("SELECT task_id, COUNT(*) as nb FROM taches_messages GROUP BY task_id")->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $totaux[$row['task_id']] = (int)$row['nb'];
        }

        echo json_encode(["non_lus" => array_sum(array_column($tickets, 'nb')), "tickets" => $tickets, "totaux" => $totaux]);
    } catch (Exception $e) {
        echo json_encode(["non_lus" => 0, "tickets" => [], "totaux" => []]);
    }
    exit;
}

if (isset($_GET['action']) && $_GET['action'] === 'check_idees_notifications') {
    if (!isset($_SESSION['user'])) { echo json_encode(["non_lues" => 0]); exit; }
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS idees_messages (
            id INT AUTO_INCREMENT PRIMARY KEY, idee_id INT NOT NULL, expediteur VARCHAR(255),
            message TEXT, is_admin TINYINT(1) DEFAULT 0, lu TINYINT(1) DEFAULT 0,
            date_envoi DATETIME DEFAULT CURRENT_TIMESTAMP
        )");
        $stmt = $db->prepare("
            SELECT COUNT(*) FROM idees_messages m
            JOIN idees_amelioration i ON i.id = m.idee_id
            WHERE i.service = ? AND m.is_admin = 1 AND m.lu = 0
        ");
        $stmt->execute([$_SESSION['user']]);
        echo json_encode(["non_lues" => (int)$stmt->fetchColumn()]);
    } catch (Exception $e) {
        echo json_encode(["non_lues" => 0]);
    }
    exit;
}

if (isset($_GET['delete'])) {
    // SÉCURITÉ : la suppression d'une intervention est réservée au personnel maintenance
    // (le contrôle n'existait auparavant que côté navigateur, donc contournable).
    if (!isset($_SESSION['role']) || !in_array(strtolower($_SESSION['role']), ['admin', 'technicien'])) {
        echo json_encode(["status" => "error", "message" => "Droits insuffisants"]); exit;
    }
    try {
        $stmt = $db->prepare("DELETE FROM taches WHERE id = ?");
        $stmt->execute([$_GET['delete']]);
        $stmtPt = $db->prepare("DELETE FROM pointages WHERE task_id = ?");
        $stmtPt->execute([$_GET['delete']]);
        $stmtMsg = $db->prepare("DELETE FROM taches_messages WHERE task_id = ?");
        $stmtMsg->execute([$_GET['delete']]);
        
        writeLog($db, $_SESSION['user'], "Suppression", "ID BI: " . $_GET['delete']);
        echo json_encode(["status" => "success"]);
    } catch (Exception $e) {
        error_log("api.php: " . $e->getMessage());
        echo json_encode(["status" => "error", "message" => "Erreur serveur."]);
    }
    exit;
}

if ($method === 'POST') {
    $input = file_get_contents('php://input');
    $t = json_decode($input, true);
    
    if (isset($t['action']) && $t['action'] === 'marquer_lus') {
        try {
            $stmt = $db->prepare("UPDATE taches_messages SET lu = 1 WHERE task_id = ? AND expediteur != ?");
            $stmt->execute([$t['task_id'], $t['expediteur']]);
            echo json_encode(['success' => true]);
        } catch (Exception $e) {
            echo json_encode(['success' => false]);
        }
        exit();
    }

    if (isset($t['action']) && $t['action'] === 'send_chat_message') {
        try {
            $stmt = $db->prepare("INSERT INTO taches_messages (task_id, expediteur, message, date_envoi) VALUES (?, ?, ?, NOW())");
            $stmt->execute([$t['task_id'], $t['expediteur'], $t['message']]);
            echo json_encode(['status' => 'success']);
        } catch (Exception $e) {
            echo json_encode(['status' => 'error']);
        }
        exit();
    }

    if (isset($t['action']) && $t['action'] === 'reopen_task') {
        // SÉCURITÉ : réouverture d'un ticket clôturé réservée aux admins, contrôle
        // côté serveur (le seul contrôle avant existait uniquement côté navigateur).
        if (!isset($_SESSION['role']) || strtolower($_SESSION['role']) !== 'admin') {
            echo json_encode(['status' => 'error', 'message' => 'Droits insuffisants']);
            exit();
        }
        try {
            $check = $db->prepare("SELECT statut FROM taches WHERE id = ?");
            $check->execute([$t['id']]);
            $row = $check->fetch();
            // "Terminé"/"Terminée" coexistent dans les données existantes (deux fonctions de
            // clôture historiques dans maintenance.php n'accordaient pas le participe pareil) :
            // on vérifie donc juste que ça commence par "Termin", pas une chaîne exacte.
            if (!$row || stripos($row['statut'], 'Termin') !== 0) {
                echo json_encode(['status' => 'error', 'message' => "Ce ticket n'est pas au statut Terminée."]);
                exit();
            }
            $stmt = $db->prepare("UPDATE taches SET statut = 'À faire' WHERE id = ?");
            $stmt->execute([$t['id']]);
            writeLog($db, $_SESSION['user'], "Réouverture", "ID BI: " . $t['id'] . " (remis en 'À faire' depuis 'Terminée')");
            echo json_encode(['status' => 'success']);
        } catch (Exception $e) {
            echo json_encode(['status' => 'error', 'message' => 'Erreur serveur.']);
        }
        exit();
    }

    if (isset($t['action']) && $t['action'] === 'update_note') {
        try {
            $stmt = $db->prepare("UPDATE taches SET rapport_intermediaire = ? WHERE id = ?");
            $stmt->execute([$t['rapport_intermediaire'], $t['id']]);
            echo json_encode(['status' => 'success']);
        } catch (Exception $e) {
            echo json_encode(['status' => 'error']);
        }
        exit(); 
    }
    
    if ($t && isset($t['id'])) {
        try {
            $valeur_casse = (!empty($t['casse']) && ($t['casse'] == 1 || $t['casse'] === '1' || $t['casse'] === 'true' || $t['casse'] === true)) ? 1 : 0;
            $valeur_verif_vis = (!empty($t['verif_vis']) && ($t['verif_vis'] == 1 || $t['verif_vis'] === '1' || $t['verif_vis'] === 'true' || $t['verif_vis'] === true)) ? 1 : 0;
            $is_sous_traitant = (!empty($t['is_sous_traitant']) && ($t['is_sous_traitant'] == 1 || $t['is_sous_traitant'] === 'true' || $t['is_sous_traitant'] === true)) ? 1 : 0;
            $entreprise_ext_id = !empty($t['entreprise_ext_id']) ? $t['entreprise_ext_id'] : null;
            $description = $t['desc'] ?? ''; 

            $check = $db->prepare("SELECT id FROM taches WHERE id = ?");
            $check->execute([$t['id']]);
            
            if ($check->fetch()) {
                // CHIRURGIE : Ajout de `ligne = ?` + `rule_id = ?` + `motif_refus = ?`
                $sql = "UPDATE taches SET num_bi = ?, date_creation = ?, date = ?, tech = ?, demandeur = ?, usine = ?, secteur = ?, ligne = ?, zone = ?, equip = ?, statut = ?, prio = ?, type = ?, casse = ?, verif_vis = ?, description = ?, compte_rendu = ?, is_sous_traitant = ?, entreprise_ext_id = ?, rule_id = ?, motif_refus = ? WHERE id = ?";
                $stmt = $db->prepare($sql);
                // CHIRURGIE : Ajout de la valeur de la ligne + rule_id + motif_refus
                $stmt->execute([$t['num_bi'], $t['date_creation'], $t['date'], $t['tech'], ($t['demandeur'] ?? null), $t['usine'], $t['secteur'], ($t['ligne'] ?? null), $t['zone'], $t['equip'], $t['statut'], $t['prio'], $t['type'], $valeur_casse, $valeur_verif_vis, $description, ($t['compte_rendu'] ?? ''), $is_sous_traitant, $entreprise_ext_id, ($t['rule_id'] ?? null), ($t['motif_refus'] ?? null), $t['id']]);
            } else {
                // Filet de sécurité serveur : genererNumeroBI() (maintenance.php) calcule le
                // prochain numéro à partir des tâches déjà chargées en mémoire côté client, sans
                // verrou — deux créations quasi simultanées (ex. deux validations SAS rapprochées)
                // peuvent donc calculer le même numéro avant que l'une des deux ne soit visible
                // dans le cache de l'autre (vécu le 2026-09-04 : BI26-480 attribué deux fois). On
                // revérifie donc l'unicité ici et on réattribue le prochain numéro libre plutôt que
                // de laisser deux bons différents porter le même numéro.
                $numBi = $t['num_bi'] ?? '';
                if ($numBi !== '' && preg_match('/^(BI\d+-)(\d+)$/', $numBi, $mBi)) {
                    $stmtCheckBi = $db->prepare("SELECT COUNT(*) FROM taches WHERE num_bi = ?");
                    $stmtCheckBi->execute([$numBi]);
                    if ((int)$stmtCheckBi->fetchColumn() > 0) {
                        $prefixeBi = $mBi[1];
                        $stmtMaxBi = $db->prepare("SELECT num_bi FROM taches WHERE num_bi LIKE ? ORDER BY CAST(SUBSTRING(num_bi, LENGTH(?) + 1) AS UNSIGNED) DESC LIMIT 1");
                        $stmtMaxBi->execute([$prefixeBi . '%', $prefixeBi]);
                        $dernierNum = (int)substr((string)$stmtMaxBi->fetchColumn(), strlen($prefixeBi));
                        $numBi = $prefixeBi . str_pad((string)($dernierNum + 1), 3, '0', STR_PAD_LEFT);
                    }
                }
                // CHIRURGIE : Ajout de `ligne,` et de son `?` + `rule_id` + `motif_refus`
                $sql = "INSERT INTO taches (id, num_bi, date_creation, date, tech, demandeur, usine, secteur, ligne, zone, equip, statut, prio, type, casse, verif_vis, description, compte_rendu, is_sous_traitant, entreprise_ext_id, rule_id, motif_refus) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                $stmt = $db->prepare($sql);
                // CHIRURGIE : Ajout de la valeur de la ligne + rule_id + motif_refus
                $stmt->execute([$t['id'], $numBi, $t['date_creation'], $t['date'], $t['tech'], ($t['demandeur'] ?? null), $t['usine'], $t['secteur'], ($t['ligne'] ?? null), $t['zone'], $t['equip'], $t['statut'], $t['prio'], $t['type'], $valeur_casse, $valeur_verif_vis, $description, ($t['compte_rendu'] ?? ''), $is_sous_traitant, $entreprise_ext_id, ($t['rule_id'] ?? null), ($t['motif_refus'] ?? null)]);
            }
            echo json_encode(["status" => "success"]);
            exit;
        } catch (Exception $e) {
            error_log("api.php: " . $e->getMessage());
            echo json_encode(["status" => "error", "message" => "Erreur serveur."]);
            exit;
        }
    }
} 

// LECTURE SÉCURISÉE (Bouclier Anti-Crash)
try {
    $res = $db->query("SELECT * FROM taches ORDER BY date DESC");
    $tasks = $res->fetchAll(PDO::FETCH_ASSOC);
    foreach($tasks as &$task) {
        $task['desc'] = $task['description'];
    }
    // L'argument JSON_INVALID_UTF8_SUBSTITUTE empêche JSON de crasher si un texte est corrompu
    echo json_encode($tasks, JSON_INVALID_UTF8_SUBSTITUTE);
} catch (Exception $e) {
    echo json_encode([]);
}