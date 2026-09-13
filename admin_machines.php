<?php
require_once __DIR__ . '/session_init.php';
require 'db.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/schema_usine_data.php';
schema_usine_bootstrap($db);

// --- SÉCURITÉ ---
// Admin : lecture + écriture complète de l'arborescence.
// Technicien / Maintenance : lecture seule (retrouver une machine vite, sans pouvoir la modifier).
if (!isset($_SESSION['user']) || !isset($_SESSION['role']) || !in_array($_SESSION['role'], ['admin', 'technicien'])) {
    header("Location: maintenance.php");
    exit();
}
$is_admin = ($_SESSION['role'] === 'admin');
$message = "";

// --- TYPES D'ÉQUIPEMENT (configurables depuis Paramètres > Types d'équipement) ---
try {
    $db->exec("CREATE TABLE IF NOT EXISTS types_equipement (
        id INT AUTO_INCREMENT PRIMARY KEY,
        nom VARCHAR(100) UNIQUE,
        icone VARCHAR(60) DEFAULT 'fa-gear',
        ordre INT DEFAULT 0
    )");
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
        $stmtSeed = $db->prepare("INSERT INTO types_equipement (nom, icone, ordre) VALUES (?, ?, ?)");
        foreach ($defauts as $i => $d) { $stmtSeed->execute([$d[0], $d[1], $i]); }
    }
} catch (Exception $e) {}

$TYPES_EQUIPEMENT = [];
$TYPES_ICONS = [];
foreach ($db->query("SELECT nom, icone FROM types_equipement ORDER BY ordre ASC")->fetchAll(PDO::FETCH_ASSOC) as $t) {
    $TYPES_EQUIPEMENT[] = $t['nom'];
    $TYPES_ICONS[$t['nom']] = $t['icone'];
}

// Catégories visuelles du schéma (configurables dans Paramètres > Catégories du schéma) : couleurs
// par défaut appliquées à une forme quand on lui choisit une catégorie dans le panneau propriétés.
$CATEGORIES_VISUELLES = $db->query("SELECT * FROM schema_categories_visuelles ORDER BY ordre ASC")->fetchAll(PDO::FETCH_ASSOC);

// --- STRUCTURE DE LA TABLE (MARIADB) ---
try {
    $db->exec("CREATE TABLE IF NOT EXISTS machines (
        id INT AUTO_INCREMENT PRIMARY KEY,
        usine VARCHAR(100) DEFAULT 'Gel-Pam',
        secteur VARCHAR(100),
        ligne VARCHAR(100),
        zone VARCHAR(100),
        nom_machine VARCHAR(100),
        type_equipement VARCHAR(60),
        ordre INT DEFAULT 0,
        emplacement VARCHAR(100)
    )");
    @$db->exec("ALTER TABLE machines ADD COLUMN IF NOT EXISTS ligne VARCHAR(100) AFTER secteur");
    @$db->exec("ALTER TABLE machines ADD COLUMN IF NOT EXISTS ordre INT DEFAULT 0");
    @$db->exec("ALTER TABLE machines ADD COLUMN IF NOT EXISTS ordre_secteur INT DEFAULT 0");
    @$db->exec("ALTER TABLE machines ADD COLUMN IF NOT EXISTS ordre_ligne INT DEFAULT 0");
    @$db->exec("ALTER TABLE machines ADD COLUMN IF NOT EXISTS ordre_zone INT DEFAULT 0");
    @$db->exec("ALTER TABLE machines ADD COLUMN IF NOT EXISTS type_equipement VARCHAR(60)");
} catch (Exception $e) {}

// Toute action qui modifie la base est réservée aux admins, même si la requête est envoyée à la main.
function refuserSiPasAdmin($is_admin) {
    if (!$is_admin) {
        echo json_encode(['success' => false, 'error' => t('pm.err_action_admin')]);
        exit();
    }
}

// Récupère soit la valeur du <select>, soit le texte tapé si "+ Nouveau..." / "Autre" a été choisi.
function getVal($prefix) {
    $val = isset($_POST[$prefix . '_select']) ? trim($_POST[$prefix . '_select']) : '';
    return ($val === 'autre' && isset($_POST[$prefix . '_new'])) ? trim($_POST[$prefix . '_new']) : $val;
}

// --- ASSIGNATION GROUPÉE D'UN TYPE D'ÉQUIPEMENT (SÉLECTION MULTIPLE) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'bulk_set_type') {
    refuserSiPasAdmin($is_admin);
    $ids = isset($_POST['ids']) ? json_decode($_POST['ids'], true) : [];
    $type_equipement = getVal('type');
    if (is_array($ids) && count($ids) > 0) {
        try {
            $stmt = $db->prepare("UPDATE machines SET type_equipement = ? WHERE id = ?");
            foreach ($ids as $id) {
                $stmt->execute([$type_equipement, (int)$id]);
            }
            if ($type_equipement !== '') { appliquer_composants_defaut_type($db, $type_equipement); }
            $label = $type_equipement !== '' ? $type_equipement : 'Non renseigné';
            ajouterLog($db, $_SESSION['user'], "Reclassement groupé", count($ids) . " machine(s) reclassée(s) en \"$label\"");
            echo json_encode(['success' => true]);
        } catch (Exception $e) {
            error_log("admin_machines.php: " . $e->getMessage());
            echo json_encode(['success' => false, 'error' => t('pm.err_serveur')]);
        }
    } else {
        echo json_encode(['success' => false, 'error' => t('pm.err_aucune_machine_selectionnee')]);
    }
    exit();
}

// --- GLISSER-DÉPOSER (RÉORGANISATION) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && in_array($_POST['action'], ['update_order', 'update_secteur_order', 'update_ligne_order', 'update_zone_order'])) {
    refuserSiPasAdmin($is_admin);
    $data = json_decode($_POST['order_data'], true);
    if (is_array($data)) {
        try {
            if ($_POST['action'] === 'update_order') {
                foreach ($data as $item) {
                    $stmtOld = $db->prepare("SELECT usine, secteur, zone, nom_machine FROM machines WHERE id = ?");
                    $stmtOld->execute([$item['id']]);
                    $oldM = $stmtOld->fetch(PDO::FETCH_ASSOC);

                    $stmt = $db->prepare("UPDATE machines SET ordre = ?, usine = ?, secteur = ?, ligne = ?, zone = ? WHERE id = ?");
                    $stmt->execute([$item['ordre'], $item['usine'], $item['secteur'], $item['ligne'], $item['zone'], $item['id']]);

                    if ($oldM) {
                        $stmtT = $db->prepare("UPDATE taches SET usine = ?, secteur = ?, zone = ? WHERE usine = ? AND secteur = ? AND zone = ? AND equip = ?");
                        $stmtT->execute([
                            $item['usine'], $item['secteur'], $item['zone'],
                            $oldM['usine'], $oldM['secteur'], $oldM['zone'], $oldM['nom_machine']
                        ]);
                    }
                }
            } elseif ($_POST['action'] === 'update_secteur_order') {
                $stmt = $db->prepare("UPDATE machines SET ordre_secteur = ? WHERE usine = ? AND secteur = ?");
                foreach ($data as $item) { $stmt->execute([$item['ordre'], $item['usine'], $item['secteur']]); }
            } elseif ($_POST['action'] === 'update_ligne_order') {
                $stmt = $db->prepare("UPDATE machines SET ordre_ligne = ? WHERE usine = ? AND secteur = ? AND ligne = ?");
                foreach ($data as $item) { $stmt->execute([$item['ordre'], $item['usine'], $item['secteur'], $item['ligne']]); }
            } elseif ($_POST['action'] === 'update_zone_order') {
                $stmt = $db->prepare("UPDATE machines SET ordre_zone = ? WHERE usine = ? AND secteur = ? AND ligne = ? AND zone = ?");
                foreach ($data as $item) { $stmt->execute([$item['ordre'], $item['usine'], $item['secteur'], $item['ligne'], $item['zone']]); }
            }
            echo json_encode(['success' => true]);
        } catch (Exception $e) {
            error_log("admin_machines.php: " . $e->getMessage());
            echo json_encode(['success' => false, 'error' => t('pm.err_serveur')]);
        }
    }
    exit();
}

// --- RENOMMAGE (USINE, SECTEUR, LIGNE, ZONE OU MACHINE) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'rename_node') {
    refuserSiPasAdmin($is_admin);
    $type = $_POST['node_type'];
    $old_name = $_POST['old_name'];
    $new_name = trim($_POST['new_name']);

    $parent_usine   = $_POST['parent_usine'] ?? '';
    $parent_secteur = $_POST['parent_secteur'] ?? '';
    $parent_ligne   = $_POST['parent_ligne'] ?? '';

    if (!empty($new_name) && $new_name !== $old_name) {
        try {
            if ($type === 'usine') {
                $stmt = $db->prepare("UPDATE machines SET usine = ? WHERE usine = ?");
                $stmt->execute([$new_name, $old_name]);
                $stmtT = $db->prepare("UPDATE taches SET usine = ? WHERE usine = ?");
                $stmtT->execute([$new_name, $old_name]);

            } elseif ($type === 'secteur') {
                $stmt = $db->prepare("UPDATE machines SET secteur = ? WHERE usine = ? AND secteur = ?");
                $stmt->execute([$new_name, $parent_usine, $old_name]);
                $stmtT = $db->prepare("UPDATE taches SET secteur = ? WHERE usine = ? AND secteur = ?");
                $stmtT->execute([$new_name, $parent_usine, $old_name]);

            } elseif ($type === 'ligne') {
                $stmt = $db->prepare("UPDATE machines SET ligne = ? WHERE usine = ? AND secteur = ? AND ligne = ?");
                $stmt->execute([$new_name, $parent_usine, $parent_secteur, $old_name]);

            } elseif ($type === 'zone') {
                $stmt = $db->prepare("UPDATE machines SET zone = ? WHERE usine = ? AND secteur = ? AND ligne = ? AND zone = ?");
                $stmt->execute([$new_name, $parent_usine, $parent_secteur, $parent_ligne, $old_name]);
                $stmtT = $db->prepare("UPDATE taches SET zone = ? WHERE usine = ? AND secteur = ? AND zone = ?");
                $stmtT->execute([$new_name, $parent_usine, $parent_secteur, $old_name]);

            } elseif ($type === 'machine') {
                $machine_id = $_POST['machine_id'];
                $stmtOld = $db->prepare("SELECT usine, secteur, zone, nom_machine FROM machines WHERE id=?");
                $stmtOld->execute([$machine_id]);
                $oldM = $stmtOld->fetch(PDO::FETCH_ASSOC);

                $stmt = $db->prepare("UPDATE machines SET nom_machine = ? WHERE id = ?");
                $stmt->execute([$new_name, $machine_id]);

                if ($oldM) {
                    $stmtT = $db->prepare("UPDATE taches SET equip = ? WHERE usine = ? AND secteur = ? AND zone = ? AND equip = ?");
                    $stmtT->execute([$new_name, $oldM['usine'], $oldM['secteur'], $oldM['zone'], $oldM['nom_machine']]);
                }
            }
            echo json_encode(['success' => true]);
        } catch (Exception $e) {
            error_log("admin_machines.php: " . $e->getMessage());
            echo json_encode(['success' => false, 'error' => t('pm.err_serveur')]);
        }
    } else {
        echo json_encode(['success' => false, 'error' => t('pm.err_nom_invalide')]);
    }
    exit();
}

// --- AJOUT / MODIFICATION D'UNE MACHINE ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && in_array($_POST['action'], ['add', 'edit'])) {
    if (!$is_admin) { header("Location: admin_machines.php"); exit(); }

    $usine   = getVal('usine');
    $secteur = getVal('secteur');
    $ligne   = getVal('ligne');
    $zone    = getVal('zone');
    $nom     = isset($_POST['nom_machine']) ? trim($_POST['nom_machine']) : '';
    $type_equipement = getVal('type');

    if (!empty($usine) && !empty($secteur) && !empty($ligne) && !empty($zone) && !empty($nom)) {
        try {
            if ($_POST['action'] === 'add') {
                $stmtMax = $db->prepare("SELECT MAX(ordre) as max_ordre FROM machines WHERE zone = ?");
                $stmtMax->execute([$zone]);
                $resMax = $stmtMax->fetch(PDO::FETCH_ASSOC);
                $nouvel_ordre = ($resMax['max_ordre'] !== null) ? $resMax['max_ordre'] + 1 : 0;

                $stmt = $db->prepare("INSERT INTO machines (usine, secteur, ligne, zone, nom_machine, type_equipement, ordre) VALUES (?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$usine, $secteur, $ligne, $zone, $nom, $type_equipement, $nouvel_ordre]);

                if ($type_equipement !== '') { appliquer_composants_defaut_type($db, $type_equipement); }
                ajouterLog($db, $_SESSION['user'], "Ajout Machine", "A ajouté la machine : $nom");

                header("Location: admin_machines.php?success=1");
                exit();

            } else {
                $stmtOld = $db->prepare("SELECT usine, secteur, zone, nom_machine FROM machines WHERE id=?");
                $stmtOld->execute([$_POST['machine_id']]);
                $oldM = $stmtOld->fetch(PDO::FETCH_ASSOC);

                $stmt = $db->prepare("UPDATE machines SET usine=?, secteur=?, ligne=?, zone=?, nom_machine=?, type_equipement=? WHERE id=?");
                $stmt->execute([$usine, $secteur, $ligne, $zone, $nom, $type_equipement, $_POST['machine_id']]);

                if ($type_equipement !== '') { appliquer_composants_defaut_type($db, $type_equipement); }

                if ($oldM) {
                    $stmtTaches = $db->prepare("UPDATE taches SET usine=?, secteur=?, zone=?, equip=? WHERE usine=? AND secteur=? AND zone=? AND equip=?");
                    $stmtTaches->execute([
                        $usine, $secteur, $zone, $nom,
                        $oldM['usine'], $oldM['secteur'], $oldM['zone'], $oldM['nom_machine']
                    ]);
                }

                ajouterLog($db, $_SESSION['user'], "Modification Machine", "A modifié la machine : $nom");

                header("Location: admin_machines.php?success=2");
                exit();
            }
        } catch (Exception $e) {
            error_log("admin_machines.php: " . $e->getMessage());
            $message = "<div class='alert danger'>" . t('pm.msg_erreur_enregistrement') . "</div>";
        }
    } else {
        $message = "<div class='alert danger'>" . t('pm.msg_champs_obligatoires') . "</div>";
    }
}

if (isset($_GET['success'])) {
    if ($_GET['success'] == 1) $message = "<div class='alert success'><i class='fa-solid fa-check-circle'></i> " . t('pm.msg_machine_enregistree') . "</div>";
    if ($_GET['success'] == 2) $message = "<div class='alert success'><i class='fa-solid fa-check-circle'></i> " . t('pm.msg_machine_modifiee') . "</div>";
}

// --- SUPPRESSION ---
if (isset($_GET['delete'])) {
    if ($is_admin) {
        $db->prepare("DELETE FROM machines WHERE id=?")->execute([$_GET['delete']]);
    }
    header("Location: admin_machines.php");
    exit();
}

// --- RÉCUPÉRATION DES DONNÉES ---
$machine_edit = null;
if ($is_admin && isset($_GET['edit'])) {
    $stmt = $db->prepare("SELECT * FROM machines WHERE id=?");
    $stmt->execute([$_GET['edit']]);
    $machine_edit = $stmt->fetch(PDO::FETCH_ASSOC);
}

$tree = [];
$res = $db->query("SELECT * FROM machines ORDER BY usine ASC, ordre_secteur ASC, secteur ASC, ordre_ligne ASC, ligne ASC, ordre_zone ASC, zone ASC, ordre ASC");
$all = $res->fetchAll(PDO::FETCH_ASSOC);
foreach ($all as $m) {
    $tree[$m['usine']][$m['secteur']][$m['ligne']][$m['zone']][] = $m;
}

function triAlpha(array $items) {
    if (class_exists('Collator')) {
        $col = new Collator('fr_FR');
        usort($items, [$col, 'compare']);
    } else {
        usort($items, 'strcasecmp');
    }
    return $items;
}

$list_usines   = triAlpha(array_values(array_unique(array_column($all, 'usine'))));
$list_secteurs = triAlpha(array_values(array_unique(array_column($all, 'secteur'))));
$list_lignes   = triAlpha(array_values(array_unique(array_column($all, 'ligne'))));
$list_zones    = triAlpha(array_values(array_unique(array_column($all, 'zone'))));
$list_types_used = triAlpha(array_values(array_unique(array_filter(array_column($all, 'type_equipement')))));
// Types créés à la volée (via "Autre") en plus des 7 types prédéfinis, pour qu'ils réapparaissent comme tuiles.
$custom_types_used = array_values(array_diff($list_types_used, $TYPES_EQUIPEMENT));

function compterZone($machinesZone) { return count($machinesZone); }
function compterLigne($zones) { $c = 0; foreach ($zones as $z) $c += compterZone($z); return $c; }
function compterSecteur($lignes) { $c = 0; foreach ($lignes as $l) $c += compterLigne($l); return $c; }
function compterUsine($secteurs) { $c = 0; foreach ($secteurs as $s) $c += compterSecteur($s); return $c; }

$total_machines = count($all);
$total_secteurs = 0; $total_lignes = 0; $total_zones = 0;
foreach ($tree as $secteurs) {
    $total_secteurs += count($secteurs);
    foreach ($secteurs as $lignes) {
        $total_lignes += count($lignes);
        foreach ($lignes as $zones) { $total_zones += count($zones); }
    }
}
$total_usines = count($tree);
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(langue_actuelle()); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title><?php echo htmlspecialchars(t('pm.title')); ?></title>
    <link rel="icon" type="image/png" href="img/logo.png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Caveat:wght@600&family=Segoe+UI:wght@300;400;600&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sortablejs@latest/Sortable.min.js"></script>
    <style>
        :root {
            --primary: #2c3e50; --accent: #3498db; --success: #2ecc71;
            --danger: #e74c3c; --gelpam-green: #2ecc71; --gelpam-orange: #f39c12;
            --stat-red: #c0392b; --purple: #9b59b6; --dark-blue: #2980b9;
            --line: #e3e8ec; --line-strong: #ccd5db; --surface-2: #f4f6f8; --ink-500: #64748b; --accent-100: #eaf4fc;
        }

        @keyframes pulse-dot { 0% { transform: scale(1); opacity: 0.6; } 100% { transform: scale(2.5); opacity: 0; } }

        body {
            margin: 0;
            font-family: 'Segoe UI', sans-serif;
            background: linear-gradient(rgba(0, 0, 0, 0.2), rgba(0, 0, 0, 0.2)), url('img/fond.jpg') no-repeat center 0px fixed;
            background-size: cover;
            min-height: 100vh;
            padding-top: 98px;
        }

        /* HEADER ET NAVIGATION (MENU MAÎTRE, inchangés) */
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
        .tab-item:hover { background: rgba(0,0,0,0.02); }
        .tab-item.active { color: var(--accent); border-bottom: 3px solid var(--accent); background: rgba(52, 152, 219, 0.05); }

        .user-badge { background: rgba(255,255,255,0.12); backdrop-filter: blur(14px) brightness(1.15); -webkit-backdrop-filter: blur(14px) brightness(1.15); padding: 6px 14px; border-radius: 20px; font-size: 0.75rem; font-weight: 600; display: flex; align-items: center; gap: 8px; color: white; border: 1px solid rgba(255,255,255,0.4); text-shadow: 0 1px 3px rgba(0,0,0,0.4); box-shadow: 0 6px 16px rgba(0,0,0,0.2); }
        .status-pulse { width: 8px; height: 8px; border-radius: 50%; position: relative; background: var(--success); }
        .status-pulse::after { content: ""; position: absolute; width: 100%; height: 100%; border-radius: 50%; background: inherit; animation: pulse-dot 2s infinite; opacity: 0.6; }

        .sidebar { height: 100%; width: 0; position: fixed; z-index: 3000; top: 0; left: 0; background-color: #1a252f; overflow-x: hidden; overflow-y: auto; transition: 0.4s; padding-top: 60px; padding-bottom: 20px; }
        .sidebar a { padding: 12px 25px; text-decoration: none; font-size: 1.05rem; color: #ecf0f1; display: block; transition: 0.3s; border-left: 4px solid transparent; }
        .sidebar a:hover { background: #2c3e50; border-left: 4px solid var(--accent); }
        .sidebar a.active-side { background: #2c3e50; border-left: 4px solid var(--accent); color: var(--accent); font-weight: bold; }
        .sidebar .closebtn { position: absolute; top: 10px; right: 25px; font-size: 36px; color: white; border: none; background: none; cursor: pointer; }
        .openbtn { font-size: 22px; cursor: pointer; background: none; border: none; color: var(--primary); padding: 5px 10px; }
        .btn-accueil { font-size: 18px; color: white; text-decoration: none; display: flex; align-items: center; justify-content: center; width: 38px; height: 38px; border-radius: 50%; background: rgba(255,255,255,0.12); backdrop-filter: blur(14px) brightness(1.15); -webkit-backdrop-filter: blur(14px) brightness(1.15); border: 1px solid rgba(255,255,255,0.4); box-shadow: 0 6px 16px rgba(0,0,0,0.2); transition: transform 0.2s, background 0.2s; }
        .btn-accueil:hover { background: rgba(255,255,255,0.28); transform: translateY(-2px); }
        .btn-accueil-green:hover { background: rgba(46, 204, 113, 0.3); border-color: rgba(46, 204, 113, 0.6); }

        /* ===== CONTENU PARC MACHINE ===== */
        .container { max-width: 1180px; margin: 0 auto; padding: 10px; }
        .alert { padding: 10px 14px; border-radius: 8px; margin-bottom: 14px; font-weight: 600; font-size: 0.85rem; }
        .alert.success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .alert.danger { background: #f8d7da; color: #842029; border: 1px solid #f1aeb5; }

        .pm-panel { background: rgba(255,255,255,0.97); border-radius: 14px; box-shadow: 0 5px 20px rgba(0,0,0,0.12); overflow: hidden; }

        .pm-toolbar { padding: 18px 22px 14px; border-bottom: 1px solid var(--line); }
        .pm-toolbar-top { display: flex; align-items: center; justify-content: space-between; gap: 14px; flex-wrap: wrap; margin-bottom: 14px; }
        .pm-title { display: flex; align-items: center; gap: 10px; font-size: 1.25rem; font-weight: 700; color: var(--primary); }
        .pm-title i { color: var(--accent); }
        .pm-subtitle { font-size: 0.78rem; color: var(--ink-500); font-weight: 500; margin-top: 2px; }
        .pm-stats { display: flex; flex-wrap: wrap; gap: 7px; margin-top: 8px; }
        .pm-stat-pill { display: inline-flex; align-items: center; gap: 6px; padding: 4px 12px; border-radius: 20px; font-size: 0.72rem; font-weight: 700; white-space: nowrap; }
        .pm-stat-pill.usine { background: rgba(155,89,182,.12); color: #8e44ad; }
        .pm-stat-pill.secteur { background: rgba(243,156,18,.12); color: #c9820c; }
        .pm-stat-pill.ligne { background: rgba(52,152,219,.12); color: #2680c2; }
        .pm-stat-pill.zone { background: rgba(46,204,113,.12); color: #219150; }
        .pm-stat-pill.machine { background: rgba(44,62,80,.08); color: var(--primary); }
        .badge-readonly { background: var(--surface-2); border: 1px solid var(--line-strong); color: var(--ink-500); font-size: 0.68rem; font-weight: 700; text-transform: uppercase; letter-spacing: .04em; padding: 4px 10px; border-radius: 20px; display: inline-flex; align-items: center; gap: 6px; }

        .pm-search-row { display: flex; gap: 10px; flex-wrap: wrap; align-items: center; }
        .pm-search { flex: 1; min-width: 220px; display: flex; align-items: center; gap: 8px; background: #fff; border: 1.5px solid var(--line-strong); border-radius: 9px; padding: 9px 12px; }
        .pm-search:focus-within { border-color: var(--accent); box-shadow: 0 0 0 3px var(--accent-100); }
        .pm-search i { color: var(--ink-500); }
        .pm-search input { border: none; outline: none; font-size: 0.88rem; width: 100%; font-family: inherit; }
        .pm-search #searchClear { flex: none; border: none; background: none; color: var(--ink-500); cursor: pointer; padding: 2px; display: flex; border-radius: 50%; }
        .pm-search #searchClear:hover { color: var(--danger); }

        .pm-btn { display: inline-flex; align-items: center; gap: 7px; border-radius: 8px; border: 1px solid var(--line-strong); background: #fff; color: var(--primary); font-size: 0.78rem; font-weight: 700; padding: 9px 14px; cursor: pointer; transition: 0.15s; white-space: nowrap; font-family: inherit; }
        .pm-btn:hover { border-color: var(--accent); color: var(--accent); }
        .pm-btn.primary { background: var(--success); border-color: var(--success); color: #fff; }
        .pm-btn.primary:hover { background: #27ae60; border-color: #27ae60; color: #fff; }
        .pm-btn.ghost { border-color: transparent; background: none; padding: 8px 10px; }
        .pm-btn.ghost:hover { background: var(--surface-2); color: var(--primary); border-color: transparent; }
        .pm-btn.active { background: var(--accent); border-color: var(--accent); color: #fff; }

        .pm-chips { display: flex; gap: 6px; flex-wrap: wrap; margin-top: 12px; }
        .pm-chip { border: 1px solid var(--line-strong); background: #fff; color: var(--ink-500); font-size: 0.72rem; font-weight: 700; padding: 5px 11px; border-radius: 20px; cursor: pointer; transition: 0.15s; font-family: inherit; }
        .pm-chip:hover { border-color: var(--accent); color: var(--accent); }
        .pm-chip.active { background: var(--accent); border-color: var(--accent); color: #fff; }

        /* ===== ARBRE ===== */
        .tree-wrap { padding: 10px 14px 20px; }
        .tree-node { }
        .tree-row { display: flex; align-items: center; gap: 9px; padding: 8px 10px; border-radius: 8px; cursor: pointer; user-select: none; border-left: 3px solid transparent; transition: background .15s, border-color .15s; }
        .tree-row:hover { background: var(--surface-2); }
        .tree-row .chev { flex: none; width: 14px; height: 14px; color: var(--ink-500); transition: transform .15s; display: flex; align-items: center; justify-content: center; }
        .tree-node.open > .tree-row .chev { transform: rotate(90deg); }
        .tree-row .n-ico { flex: none; width: 18px; text-align: center; }
        .n-name { font-weight: 600; color: var(--primary); font-size: 0.86rem; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .n-count { flex: none; font-size: 0.68rem; font-weight: 700; color: var(--ink-500); background: #fff; border: 1px solid var(--line-strong); border-radius: 20px; padding: 1px 8px; font-variant-numeric: tabular-nums; }
        .row-actions { display: none; gap: 3px; margin-left: auto; flex: none; }
        .tree-row:hover .row-actions, .m-row:hover .row-actions { display: flex; }
        .row-btn { border: 1px solid var(--line-strong); background: #fff; border-radius: 6px; padding: 5px 7px; cursor: pointer; font-size: 0.7rem; color: var(--ink-500); transition: 0.15s; }
        .row-btn:hover { background: var(--surface-2); color: var(--accent); border-color: var(--accent); }
        .row-btn.danger:hover { color: var(--danger); border-color: var(--danger); }

        /* Hiérarchie visuelle : chaque niveau garde la couleur de son icône (déjà utilisée
           ailleurs dans l'appli — violet/orange/bleu/vert) comme accent de bordure + fond au survol,
           avec un poids de police dégressif du site (le plus important) à la zone. */
        .lvl-usine > .tree-row { border-left-color: #9b59b6; }
        .lvl-usine > .tree-row:hover { background: rgba(155,89,182,.07); }
        .lvl-usine > .tree-row .n-name { font-size: 0.97rem; font-weight: 600; }
        .lvl-usine > .tree-row .n-count { background: rgba(155,89,182,.12); color: #8e44ad; border-color: transparent; }
        .lvl-secteur > .tree-row { border-left-color: #f39c12; }
        .lvl-secteur > .tree-row:hover { background: rgba(243,156,18,.07); }
        .lvl-secteur > .tree-row .n-name { font-weight: 700; }
        .lvl-secteur > .tree-row .n-count { background: rgba(243,156,18,.12); color: #c9820c; border-color: transparent; }
        .lvl-ligne > .tree-row { border-left-color: #3498db; }
        .lvl-ligne > .tree-row:hover { background: rgba(52,152,219,.07); }
        .lvl-ligne > .tree-row .n-count { background: rgba(52,152,219,.12); color: #2680c2; border-color: transparent; }
        .lvl-zone > .tree-row { border-left-color: #2ecc71; }
        .lvl-zone > .tree-row:hover { background: rgba(46,204,113,.07); }
        .lvl-zone > .tree-row .n-name { color: var(--ink-500); }
        .lvl-zone > .tree-row .n-count { background: rgba(46,204,113,.12); color: #219150; border-color: transparent; }

        .tree-children { display: none; margin-left: 15px; padding-left: 15px; border-left: 2px dashed var(--line); }
        .tree-node.open > .tree-children { display: block; }
        .tree-node.filter-open > .tree-children { display: block; }

        .m-row { display: flex; align-items: center; gap: 9px; padding: 7px 10px 7px 4px; border-radius: 7px; margin: 3px 0; background: #fff; border: 1px solid var(--line); transition: .15s; }
        .m-row:hover { border-color: var(--accent); box-shadow: 0 2px 8px rgba(0,0,0,0.06); }
        .m-row .grip { flex: none; color: #c7d0d6; cursor: grab; width: 14px; text-align: center; }
        .m-row.dragging { opacity: 0.4; }
        .m-checkbox { display: none; flex: none; width: 16px; height: 16px; margin-right: 2px; accent-color: var(--accent); cursor: pointer; }
        .tree-wrap.select-mode .m-checkbox { display: inline-block; }
        .tree-wrap.select-mode .m-row .grip { display: none; }
        .m-row.m-selected { background: var(--accent-100); border-color: var(--accent); }
        #bulkTypeGrid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        .m-name { font-weight: 600; font-size: 0.84rem; color: var(--primary); flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .m-type { flex: none; font-size: 0.66rem; font-weight: 700; color: var(--dark-blue); background: rgba(41,128,185,0.09); border-radius: 20px; padding: 2px 9px; }

        .is-hidden { display: none !important; }
        mark { background: #fef08a; color: inherit; border-radius: 2px; padding: 0 1px; }

        .empty-zone { font-size: 0.78rem; color: var(--ink-500); padding: 6px 10px; font-style: italic; }
        .empty-search { text-align: center; padding: 50px 20px; color: var(--ink-500); }
        .empty-search i { font-size: 2.2rem; opacity: 0.4; margin-bottom: 10px; display: block; }

        /* ===== FENÊTRE AJOUT / MODIF (boîte de dialogue centrée) ===== */
        .pm-overlay { position: fixed; inset: 0; background: rgba(20,30,40,0.5); backdrop-filter: blur(3px); z-index: 4000; opacity: 0; pointer-events: none; transition: 0.2s; display: flex; align-items: center; justify-content: center; padding: 24px; }
        .pm-overlay.show { opacity: 1; pointer-events: auto; }
        .pm-side { width: 100%; max-width: 780px; max-height: 90vh; background: #fff; border-radius: 16px; box-shadow: 0 1px 2px rgba(19,27,36,.06), 0 20px 50px -18px rgba(19,27,36,.45); display: flex; flex-direction: column; overflow: hidden; transform: scale(.96) translateY(8px); transition: 0.2s; }
        .pm-overlay.show .pm-side { transform: scale(1) translateY(0); }
        .pm-side > form { display: flex; flex-direction: column; flex: 1; min-height: 0; }
        .pm-side-head { padding: 16px 22px; border-bottom: 1px solid var(--line); display: flex; align-items: center; justify-content: space-between; gap: 14px; }
        .pm-side-head-left { display: flex; align-items: center; gap: 12px; min-width: 0; }
        .pm-side-head-ico { width: 38px; height: 38px; border-radius: 10px; background: var(--accent-100); color: var(--accent); display: flex; align-items: center; justify-content: center; flex: none; font-size: 1.05rem; }
        .pm-side-head h3 { margin: 0; font-size: 1rem; color: var(--primary); }
        .pm-side-head p { margin: 2px 0 0; font-size: 0.74rem; color: var(--ink-500); font-weight: 400; }
        .pm-side-body { padding: 18px 22px; overflow-y: auto; flex: 1; min-height: 0; display: flex; flex-direction: column; gap: 18px; }
        .pm-side-foot { padding: 14px 22px; border-top: 1px solid var(--line); display: flex; align-items: center; justify-content: space-between; gap: 12px; background: var(--surface-2); }
        .pm-side-foot .hint { font-size: 0.72rem; color: var(--ink-500); display: flex; align-items: center; gap: 6px; }
        .pm-side-foot .actions { display: flex; gap: 10px; }

        .section-label { display: flex; align-items: center; gap: 8px; font-size: 0.68rem; font-weight: 800; text-transform: uppercase; letter-spacing: .06em; color: var(--ink-500); margin-bottom: 10px; }
        .section-label .rule { flex: 1; height: 1px; background: var(--line); }

        .loc-grid { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 10px; }
        .field { display: flex; flex-direction: column; gap: 5px; font-weight: 700; font-size: 0.68rem; color: var(--ink-500); min-width: 0; }
        .field select, .field input { width: 100%; min-width: 0; box-sizing: border-box; padding: 7px 8px; border-radius: 7px; border: 1.5px solid var(--line-strong); font-family: inherit; font-size: 0.78rem; font-weight: 500; color: var(--primary); }
        .field select:focus, .field input:focus { outline: none; border-color: var(--accent); box-shadow: 0 0 0 3px var(--accent-100); }

        .breadcrumb-preview { margin-top: 10px; display: flex; align-items: center; flex-wrap: wrap; gap: 6px; background: var(--surface-2); border: 1px dashed var(--line-strong); border-radius: 9px; padding: 9px 12px; font-size: 0.76rem; color: var(--ink-500); min-height: 18px; font-weight: 500; }
        .breadcrumb-preview b { color: var(--primary); font-weight: 700; }
        .breadcrumb-preview .sep { color: var(--line-strong); }
        .breadcrumb-preview .ph { font-style: italic; opacity: .7; }

        .name-input { font-size: 0.92rem !important; padding: 10px 11px !important; font-weight: 600 !important; }

        .type-grid { display: grid; grid-template-columns: repeat(6, minmax(0, 1fr)); gap: 7px; margin-top: 8px; }
        .type-chip { display: flex; flex-direction: column; align-items: center; gap: 5px; text-align: center; border: 1.5px solid var(--line-strong); background: #fff; border-radius: 9px; padding: 8px 4px; cursor: pointer; transition: .15s; color: var(--ink-500); min-width: 0; }
        .type-chip:hover { border-color: var(--accent); color: var(--accent); }
        .type-chip.active { border-color: var(--accent); background: var(--accent-100); color: var(--accent); }
        .type-chip i { font-size: 0.9rem; }
        .type-chip span { font-size: 0.6rem; font-weight: 700; line-height: 1.2; }
        #type-n { width: 100%; padding: 7px 8px; border-radius: 7px; border: 1.5px solid var(--line-strong); font-family: inherit; font-size: 0.78rem; font-weight: 500; color: var(--primary); box-sizing: border-box; }
        #type-n:focus { outline: none; border-color: var(--accent); box-shadow: 0 0 0 3px var(--accent-100); }
        @media (max-width: 640px) { .loc-grid { grid-template-columns: 1fr 1fr; } .type-grid { grid-template-columns: repeat(4, minmax(0, 1fr)); } }

        /* ===== MODALES ===== */
        .modal-bg { display: none; position: fixed; z-index: 5000; left: 0; top: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); backdrop-filter: blur(3px); }
        .modal-box { background: white; width: 350px; max-width: 90vw; max-height: 80vh; overflow-y: auto; margin: 8vh auto; padding: 22px; border-radius: 12px; text-align: center; box-shadow: 0 10px 25px rgba(0,0,0,0.2); }
        .modal-box.rename { border-top: 5px solid var(--accent); }
        .modal-box.confirm { border-top: 5px solid var(--danger); }
        .modal-box i.big { font-size: 2.6rem; margin-bottom: 10px; }
        .modal-box h3 { margin: 8px 0; color: var(--primary); font-family: 'Segoe UI', sans-serif; }
        .modal-box p { color: #666; font-size: 0.85rem; margin-bottom: 18px; }
        .modal-box input { width: 100%; box-sizing: border-box; padding: 10px; margin-bottom: 16px; border: 2px solid var(--accent); border-radius: 7px; font-weight: 600; outline: none; }
        .modal-actions { display: flex; justify-content: center; gap: 10px; }
        .modal-actions button { padding: 10px 20px; border: none; border-radius: 7px; cursor: pointer; font-weight: 700; font-family: inherit; }
        .modal-btn-cancel { background: #eee; color: #333; }
        .modal-btn-ok { background: var(--accent); color: #fff; }
        .modal-btn-danger { background: var(--danger); color: #fff; }

        #save-toast { position: fixed; bottom: 20px; right: 20px; background: var(--primary); color: white; padding: 10px 20px; border-radius: 8px; opacity: 0; transition: 0.3s; z-index: 6000; font-size: 0.85rem; display: flex; align-items: center; gap: 8px; pointer-events: none; }

        #bulkBar { position: fixed; bottom: 22px; left: 50%; transform: translateX(-50%); background: var(--primary); color: #fff; padding: 12px 16px; border-radius: 12px; box-shadow: 0 10px 30px rgba(0,0,0,0.25); z-index: 3500; display: flex; align-items: center; gap: 12px; font-size: 0.82rem; font-weight: 600; }
        #bulkBar .pm-btn { padding: 7px 13px; font-size: 0.76rem; }
        #bulkBar .pm-btn.ghost { color: #fff; border-color: rgba(255,255,255,0.3); }
        #bulkBar .pm-btn.ghost:hover { background: rgba(255,255,255,0.12); color: #fff; }
        #save-toast.show { opacity: 1; }

        @media (max-width: 700px) {
            .pm-toolbar-top { flex-direction: column; align-items: flex-start; }
        }

        /* ===== SCHÉMA USINE C ===== */
        .schema-overlay { display: none; position: fixed; inset: 0; z-index: 4500; background: rgba(20,30,40,0.55); backdrop-filter: blur(3px); padding: 20px; }
        .schema-overlay.show { display: flex; align-items: center; justify-content: center; }
        .schema-box { position: relative; width: 100%; max-width: 1180px; max-height: 92vh; background: #fff; border-radius: 16px; box-shadow: 0 20px 50px -18px rgba(19,27,36,.5); display: flex; flex-direction: column; overflow: hidden; }
        .schema-head { padding: 16px 22px; border-bottom: 1px solid var(--line); display: flex; align-items: center; justify-content: space-between; gap: 12px; }
        .schema-head-left { display: flex; align-items: center; gap: 12px; min-width: 0; }
        .schema-head-left button.su-back { border: 1px solid var(--line-strong); background: #fff; border-radius: 7px; padding: 6px 9px; cursor: pointer; color: var(--ink-500); display: none; }
        .schema-head-left button.su-back:hover { color: var(--accent); border-color: var(--accent); }
        .schema-head h3 { margin: 0; font-size: 1.05rem; color: var(--primary); }
        .schema-body { padding: 20px 22px; overflow: auto; flex: 1; }
        #schemaClose { cursor: pointer; font-size: 24px; color: #cbd5e1; line-height: 1; border: none; background: none; }

        .su-liste { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
        @media (max-width: 640px) { .su-liste { grid-template-columns: 1fr; } }
        .su-carte { display: flex; align-items: center; gap: 14px; text-align: left; border: 1.5px solid var(--line-strong); background: #fff; border-radius: 12px; padding: 18px; cursor: pointer; transition: .15s; font-family: inherit; }
        .su-carte:hover { border-color: var(--accent); background: var(--accent-100); }
        .su-carte i { font-size: 1.6rem; color: var(--accent); flex: none; }
        .su-carte h4 { margin: 0 0 4px; color: var(--primary); font-size: 0.95rem; }
        .su-carte p { margin: 0; font-size: 0.76rem; color: var(--ink-500); }

        .su-note { font-size: 0.76rem; color: var(--ink-500); background: var(--surface-2); border-radius: 8px; padding: 8px 12px; margin: 0 0 16px; }
        .su-section-title { font-size: 0.68rem; font-weight: 800; text-transform: uppercase; letter-spacing: .05em; color: var(--ink-500); margin: 18px 0 8px; }
        .su-blocks { display: flex; flex-wrap: wrap; gap: 8px; }
        .su-blocks-readonly { opacity: .85; align-items: center; }
        .su-block { border: 1.5px solid var(--dark-blue); background: rgba(41,128,185,0.08); color: var(--dark-blue); font-weight: 700; font-size: 0.78rem; padding: 8px 14px; border-radius: 8px; cursor: pointer; font-family: inherit; transition: .15s; }
        .su-block:hover { background: var(--dark-blue); color: #fff; }
        .su-block-ghost { cursor: default; border-style: dashed; opacity: .7; }
        .su-block-ghost:hover { background: rgba(41,128,185,0.08); color: var(--dark-blue); }
        .su-btn-lien { border: none; background: none; color: var(--accent); font-weight: 700; font-size: 0.78rem; cursor: pointer; font-family: inherit; padding: 8px 6px; }
        .su-btn-lien:hover { text-decoration: underline; }

        .su-lane { display: flex; flex-wrap: wrap; align-items: center; gap: 4px; padding: 12px; border-radius: 10px; background: var(--surface-2); }
        .su-lane-dechets { background: #fdecea; }
        .su-lane-tapis { background: #eef7ee; }
        .su-chip { border: 1.5px solid var(--line-strong); background: #fff; color: var(--primary); font-weight: 700; font-size: 0.72rem; padding: 5px 9px; border-radius: 20px; cursor: pointer; font-family: inherit; transition: .15s; white-space: nowrap; }
        .su-chip:hover { border-color: var(--accent); color: var(--accent); background: var(--accent-100); }
        .su-arrow { color: var(--line-strong); font-size: 0.7rem; flex: none; }

        /* Plan visuel (géométrie exacte extraite du schéma PowerPoint source, éditable en base) */
        .su-canvas { position: relative; width: 100%; background: #fafbfc; border: 1px solid var(--line-strong); border-radius: 10px; overflow: hidden; cursor: grab; touch-action: none; user-select: none; }
        .su-canvas.panning { cursor: grabbing; }
        .su-zone { position: absolute; box-sizing: border-box; border: 2px solid transparent; overflow: visible; font-weight: 700; font-size: clamp(0.5rem, 1vw, 0.75rem); line-height: 1.05; padding: 1px; font-family: inherit; }
        .su-zone-label { position: absolute; text-align: center; white-space: nowrap; pointer-events: none; }
        /* Forme "texte seul" : pas de fond pour intercepter le clic, donc le texte lui-même doit
           rester cliquable en permanence (pas seulement une fois la forme déjà sélectionnée). */
        .su-zone[data-shape="text_only"] .su-zone-label { pointer-events: auto; cursor: pointer; }
        .su-zone-selected .su-zone-label { pointer-events: auto; cursor: grab; border-radius: 4px; }
        .su-zone-selected .su-zone-label:hover { outline: 1.5px dashed var(--accent); outline-offset: 3px; }
        .su-zone-selected .su-zone-label.dragging { cursor: grabbing; outline: 1.5px dashed var(--accent); outline-offset: 3px; }
        .su-zone-click { cursor: pointer; transition: filter .15s; }
        .su-zone-click:hover { filter: brightness(0.94); }
        .su-zone-decor { pointer-events: none; }
        .su-hint { margin-top: 12px; font-size: 0.76rem; color: var(--ink-500); background: var(--surface-2); border-radius: 8px; padding: 9px 12px; display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
        .su-hint .su-btn-lien { margin-left: auto; }
        @media (max-width: 700px) {
            .su-canvas { aspect-ratio: unset !important; min-height: 640px; }
        }

        /* Agrandir/réduire le canevas (D-pad) */
        .su-grow-pad { display: none; flex-direction: column; align-items: center; gap: 8px; position: absolute; top: calc(100% + 6px); right: 0; z-index: 60; background: #fff; border: 1px solid var(--line-strong); border-radius: 12px; box-shadow: 0 12px 28px -8px rgba(19,27,36,.3); padding: 10px; width: 220px; }
        .su-grow-pad.show { display: flex; }
        .su-grow-mode { display: flex; gap: 4px; background: var(--surface-2); border-radius: 8px; padding: 3px; width: 100%; }
        .su-grow-mode-btn { flex: 1; border: none; background: transparent; border-radius: 6px; padding: 6px 4px; font-size: 0.72rem; font-weight: 700; color: var(--ink-500); cursor: pointer; display: flex; align-items: center; justify-content: center; gap: 4px; transition: .15s; font-family: inherit; }
        .su-grow-mode-btn.active { background: #fff; color: var(--accent); box-shadow: 0 1px 3px rgba(19,27,36,.15); }
        .su-grow-arrows { display: grid; grid-template-columns: 34px 34px 34px; grid-template-rows: 34px 34px 34px; gap: 4px; }
        .su-grow-top { grid-column: 2; grid-row: 1; }
        .su-grow-left { grid-column: 1; grid-row: 2; }
        .su-grow-center { grid-column: 2; grid-row: 2; display: flex; align-items: center; justify-content: center; color: var(--line-strong); font-size: 0.85rem; }
        .su-grow-right { grid-column: 3; grid-row: 2; }
        .su-grow-bottom { grid-column: 2; grid-row: 3; }
        .su-grow-arrow { border: 1px solid var(--line-strong); background: var(--surface-2); border-radius: 8px; cursor: pointer; display: flex; align-items: center; justify-content: center; color: var(--ink-500); transition: .15s; }
        .su-grow-arrow:hover { background: var(--accent-100); border-color: var(--accent); color: var(--accent); }
        .su-grow-hint { margin: 0; font-size: 0.65rem; color: var(--ink-500); text-align: center; line-height: 1.3; }

        /* --- Mode édition du plan (admin) --- */
        .su-canvas.edit-mode .su-zone { pointer-events: auto; cursor: grab; user-select: none; }
        .su-canvas.edit-mode .su-zone:active { cursor: grabbing; }
        .su-canvas.edit-mode .su-zone-editable { border-color: rgba(0,0,0,0.08); }
        .su-canvas.edit-mode .su-zone.su-zone-selected { outline: 1.5px solid var(--accent); outline-offset: 0px; z-index: 40 !important; }
        .su-canvas.edit-mode .su-zone.su-zone-multiselect { outline: 1.5px dashed #8e44ad; outline-offset: 1px; z-index: 39 !important; }
        .su-marquee-box { position: absolute; border: 1.5px dashed var(--accent); background: rgba(52,152,219,.12); z-index: 45; pointer-events: none; }
        .su-resize-handle { position: absolute; width: 9px; height: 9px; margin: -5px; background: var(--accent); border: 1.5px solid #fff; border-radius: 50%; z-index: 41; box-shadow: 0 1px 3px rgba(0,0,0,0.3); }
        .su-resize-handle[data-corner="nw"] { cursor: nwse-resize; }
        .su-resize-handle[data-corner="se"] { cursor: nwse-resize; }
        .su-resize-handle[data-corner="ne"] { cursor: nesw-resize; }
        .su-resize-handle[data-corner="sw"] { cursor: nesw-resize; }
        .su-resize-handle[data-corner="n"] { cursor: ns-resize; }
        .su-resize-handle[data-corner="s"] { cursor: ns-resize; }
        .su-resize-handle[data-corner="e"] { cursor: ew-resize; }
        .su-resize-handle[data-corner="w"] { cursor: ew-resize; }
        .su-rotate-line { position: absolute; width: 1.5px; background: var(--accent); z-index: 41; transform: translateX(-50%); pointer-events: none; }
        .su-rotate-handle { position: absolute; width: 18px; height: 18px; margin: -9px; background: #fff; border: 2px solid var(--accent); border-radius: 50%; z-index: 42; display: flex; align-items: center; justify-content: center; font-size: 0.6rem; color: var(--accent); cursor: grab; box-shadow: 0 1px 4px rgba(0,0,0,0.3); }
        .su-rotate-handle:active { cursor: grabbing; }
        /* Poignée dédiée à la rotation du TEXTE, indépendante de celle de la forme (voir
           suRenderTextRotateHandle) — violette pour la distinguer visuellement de celle de la forme
           (bleue). */
        .su-text-rotate-handle { position: absolute; width: 15px; height: 15px; margin: -7.5px; background: #fff; border: 2px solid #8e44ad; border-radius: 50%; z-index: 43; display: flex; align-items: center; justify-content: center; font-size: 0.5rem; color: #8e44ad; cursor: grab; box-shadow: 0 1px 4px rgba(0,0,0,0.3); }
        .su-text-rotate-handle:active { cursor: grabbing; }
        /* Graduations affichées uniquement pendant qu'on pivote (voir suRenderRotationTicks) — un
           repère visuel fixe (n'accompagne pas la forme) pour sentir où sont 0°/90°/180°/270°. */
        .su-rotate-tick { position: absolute; width: 2px; height: 6px; margin: -3px -1px; background: rgba(52,152,219,.45); border-radius: 1px; z-index: 40; pointer-events: none; }
        .su-rotate-tick.major { width: 3px; height: 10px; margin: -5px -1.5px; background: var(--accent); }

        /* position:fixed (et non absolute) : le panneau échappe ainsi à l'overflow:hidden et à la
           hauteur limitée (92vh) de .schema-box, pour pouvoir s'ouvrir sur toute la hauteur de
           l'écran sans barre de scroll interne. Sa position par défaut est recalculée en JS
           (suPositionPropsPanelDefault) pour rester visuellement collé au coin haut-droit du plan.
           resize:both fait apparaître une poignée de redimensionnement native (coin bas-droit) —
           l'utilisateur peut rétrécir le panneau si l'ouverture pleine hauteur est trop grande. */
        .su-props-panel { display: none; position: fixed; top: 90px; right: 24px; width: 620px; max-width: 92vw; max-height: calc(100vh - 110px); min-width: 380px; min-height: 240px; overflow: auto; resize: both; background: #fff; border: 1px solid var(--line-strong); border-radius: 16px; box-shadow: 0 20px 50px -12px rgba(19,27,36,.35); z-index: 4600; flex-direction: column; }
        .su-props-panel.show { display: flex; }
        .su-props-head { position: sticky; top: 0; z-index: 2; background: #fff; display: flex; align-items: flex-start; justify-content: space-between; gap: 10px; padding: 14px 20px; border-bottom: 1px solid var(--line); border-radius: 16px 16px 0 0; cursor: move; user-select: none; }
        .su-props-head span { font-weight: 800; font-size: 0.92rem; color: var(--primary); }
        .su-props-head span small { display: block; font-size: 0.68rem; font-weight: 500; text-transform: none; letter-spacing: 0; color: var(--ink-500); margin-top: 2px; }
        .su-props-body { padding: 16px 20px; display: flex; flex-direction: column; gap: 14px; }
        .su-props-panel .section-label { margin-bottom: 2px; }
        .su-props-panel .fv-grid { gap: 10px 14px; }
        .su-props-panel .fv-grid-3 { grid-template-columns: repeat(3, 1fr); }
        .su-props-panel .fv-grid-5 { grid-template-columns: repeat(5, 1fr); }
        .su-props-panel .fv-field { gap: 4px; font-size: 0.7rem; }
        .su-props-panel input[type="text"], .su-props-panel input[type="number"], .su-props-panel select, .su-props-panel textarea { padding: 7px 9px; font-size: 0.8rem; }
        .su-props-panel input[type="color"] { height: 34px; padding: 3px; cursor: pointer; }
        .su-props-row { display: flex; align-items: center; gap: 22px; flex-wrap: wrap; }
        .su-props-checkbox { display: flex; align-items: center; gap: 7px; font-size: 0.8rem; font-weight: 600; color: var(--primary); cursor: pointer; white-space: nowrap; }
        .su-props-order { display: flex; gap: 8px; flex: 1; min-width: 220px; }
        .su-props-order .pm-btn { flex: 1; justify-content: center; font-size: 0.74rem; padding: 8px; white-space: nowrap; }
        .su-props-actions { position: sticky; bottom: 0; z-index: 2; padding: 14px 20px; border-top: 1px solid var(--line); background: var(--surface-2); display: flex; gap: 10px; align-items: center; border-radius: 0 0 16px 16px; }
        .su-props-actions .pm-btn.primary { flex: 1; justify-content: center; padding: 10px 16px; font-size: 0.85rem; }
        .su-props-actions #btnDeleteZone { flex: none; width: 38px; height: 38px; justify-content: center; color: var(--danger); border-color: var(--danger); background: #fff; }
        .su-props-actions #btnDeleteZone:hover { background: var(--danger); color: #fff; }
        .su-shape-trigger { display: flex; align-items: center; gap: 10px; width: 100%; box-sizing: border-box; padding: 8px 10px; border: 1.5px solid var(--line-strong); border-radius: 7px; background: #fff; cursor: pointer; font-family: inherit; font-size: 0.82rem; font-weight: 600; color: var(--primary); }
        .su-shape-trigger:hover { border-color: var(--accent); }
        .su-shape-preview { flex: none; box-sizing: border-box; width: 20px; height: 20px; background: var(--accent-100); border: 1.5px solid var(--accent); display: flex; align-items: center; justify-content: center; font-size: 0.6rem; font-weight: 800; color: var(--accent); }
        .shape-picker-grid { display: flex; flex-direction: column; gap: 16px; max-height: 62vh; overflow-y: auto; padding: 2px 4px 2px 2px; }
        .shape-picker-family .section-label { margin-bottom: 8px; }
        .shape-picker-row { display: grid; grid-template-columns: repeat(auto-fill, minmax(76px, 1fr)); gap: 8px; }
        .shape-picker-tile { display: flex; flex-direction: column; align-items: center; gap: 6px; padding: 10px 4px; border: 1.5px solid var(--line-strong); border-radius: 10px; background: #fff; cursor: pointer; font-family: inherit; transition: .15s; }
        .shape-picker-tile:hover { border-color: var(--accent); background: var(--accent-100); }
        .shape-picker-tile.active { border-color: var(--accent); background: var(--accent-100); box-shadow: 0 0 0 2px var(--accent-100); }
        .shape-picker-tile .su-shape-preview { width: 30px; height: 30px; background: var(--accent-100); border-color: var(--accent); border-width: 1.5px; }
        .shape-picker-tile.active .su-shape-preview { background: var(--accent); }
        .shape-picker-tile span { font-size: 0.6rem; font-weight: 700; color: var(--primary); text-align: center; line-height: 1.2; }
        .su-effect-row { display: grid; grid-template-columns: repeat(5, 1fr); gap: 6px; }
        .su-effect-tile { display: flex; flex-direction: column; align-items: center; gap: 5px; padding: 7px 2px; border: 1.5px solid var(--line-strong); border-radius: 8px; background: #fff; cursor: pointer; font-family: inherit; transition: .15s; }
        .su-effect-tile:hover { border-color: var(--accent); background: var(--accent-100); }
        .su-effect-tile.active { border-color: var(--accent); background: var(--accent-100); box-shadow: 0 0 0 2px var(--accent-100); }
        .su-effect-swatch { width: 22px; height: 22px; border-radius: 5px; background: var(--accent-100); border: 1.5px solid var(--accent); box-sizing: border-box; }
        .su-effect-swatch.eff-shadow { filter: drop-shadow(2px 3px 3px rgba(0,0,0,.45)); }
        .su-effect-swatch.eff-bevel { box-shadow: inset -2px -2px 3px rgba(0,0,0,.35), inset 2px 2px 3px rgba(255,255,255,.8); }
        .su-effect-swatch.eff-inset { box-shadow: inset 0 0 5px rgba(0,0,0,.55); }
        .su-effect-swatch.eff-glow { filter: drop-shadow(0 0 3px var(--accent)) drop-shadow(0 0 6px var(--accent)); }
        .su-effect-tile span { font-size: 0.58rem; font-weight: 700; color: var(--primary); text-align: center; line-height: 1.15; }
        .su-dir-row { display: grid; grid-template-columns: repeat(5, 1fr); gap: 6px; margin-top: 8px; }
        .su-dir-tile { display: flex; flex-direction: column; align-items: center; gap: 2px; padding: 5px 2px; border: 1.5px solid var(--line-strong); border-radius: 8px; background: #fff; cursor: pointer; font-family: inherit; transition: .15s; }
        .su-dir-tile:hover { border-color: var(--accent); background: var(--accent-100); }
        .su-dir-tile.active { border-color: var(--accent); background: var(--accent-100); box-shadow: 0 0 0 2px var(--accent-100); }
        .su-dir-tile .su-dir-arrow { font-size: 1rem; line-height: 1; color: var(--primary); }
        .su-dir-tile.active .su-dir-arrow { color: var(--accent); }
        .su-dir-tile span:last-child { font-size: 0.56rem; font-weight: 700; color: var(--ink-500); text-align: center; line-height: 1.1; }
        @media (max-width: 1000px) {
            .su-props-panel { width: 460px; }
            .su-props-panel .fv-grid-5 { grid-template-columns: repeat(3, 1fr); }
        }
        @media (max-width: 700px) {
            .su-props-panel { position: fixed; top: auto; bottom: 0; left: 0; right: 0; width: auto; max-width: none; max-height: 72vh; border-radius: 16px 16px 0 0; }
            .su-props-panel .fv-grid-5 { grid-template-columns: 1fr 1fr; }
        }

        /* --- Zoom du plan --- */
        .su-zoom-controls { display: none; position: absolute; bottom: 14px; left: 14px; z-index: 45; background: #fff; border: 1px solid var(--line-strong); border-radius: 10px; box-shadow: 0 6px 18px rgba(0,0,0,0.18); overflow: hidden; }
        .su-zoom-controls.show { display: flex; align-items: stretch; }
        .su-zoom-controls button { border: none; background: #fff; color: var(--primary); padding: 8px 12px; cursor: pointer; font-family: inherit; font-size: 0.78rem; font-weight: 700; border-right: 1px solid var(--line); }
        .su-zoom-controls button:last-child { border-right: none; }
        .su-zoom-controls button:hover { background: var(--surface-2); color: var(--accent); }
        #suZoomReset { min-width: 52px; }

        /* ===== FICHE DE VIE MACHINE ===== */
        #ficheVieModal .modal-box { width: 1220px; max-width: 96vw; max-height: 88vh; margin: 3vh auto; text-align: left; }
        .fv-cards-row { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
        @media (max-width: 900px) { .fv-cards-row { grid-template-columns: 1fr; } }
        .fv-tabs { display: flex; gap: 4px; border-bottom: 1px solid var(--line); margin-bottom: 16px; }
        .fv-tab { border: none; background: none; padding: 9px 14px; font-weight: 700; font-size: 0.78rem; color: var(--ink-500); cursor: pointer; border-bottom: 3px solid transparent; font-family: inherit; }
        .fv-tab.active { color: var(--accent); border-bottom-color: var(--accent); }
        .fv-pane { display: none; }
        .fv-pane.active { display: block; }
        .fv-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
        @media (max-width: 640px) { .fv-grid { grid-template-columns: 1fr; } }
        .fv-grid-3 { grid-template-columns: repeat(3, 1fr); gap: 8px 10px; }
        .fv-grid-4 { grid-template-columns: repeat(4, 1fr); gap: 8px 10px; }
        @media (max-width: 640px) { .fv-grid-3, .fv-grid-4 { grid-template-columns: 1fr 1fr; } }
        .fv-field { display: flex; flex-direction: column; gap: 3px; font-weight: 700; font-size: 0.66rem; color: var(--ink-500); }
        .fv-field input, .fv-field textarea, .fv-field select { padding: 6px 8px; border-radius: 6px; border: 1.5px solid var(--line-strong); font-family: inherit; font-size: 0.76rem; font-weight: 500; color: var(--primary); }
        .fv-field input:focus, .fv-field textarea:focus, .fv-field select:focus { outline: none; border-color: var(--accent); box-shadow: 0 0 0 3px var(--accent-100); }
        #fvComposantsFields { gap: 4px 10px; }
        #fvComposantsFields .fv-field { gap: 1px; font-size: 0.6rem; }
        #fvComposantsFields .fv-field input { padding: 4px 7px; font-size: 0.72rem; }
        .fv-bi-badge { display: inline-flex; align-items: center; padding: 2px 10px; border-radius: 20px; background: var(--accent-100); color: var(--accent); font-weight: 700; font-size: 0.74rem; }
        .fv-histo-summary { font-size: 0.7rem; font-weight: 800; color: var(--ink-500); text-transform: uppercase; letter-spacing: .05em; margin-bottom: 10px; }
        .fv-bi-list { display: flex; flex-direction: column; gap: 8px; }
        .fv-bi-card { border: 1px solid var(--line); border-left: 4px solid var(--line-strong); border-radius: 9px; padding: 10px 12px; cursor: pointer; transition: .15s; background: #fff; }
        .fv-bi-card:hover { border-color: var(--accent); box-shadow: 0 2px 10px rgba(0,0,0,0.07); transform: translateY(-1px); }
        .fv-bi-card.fv-bi-afaire { border-left-color: #f39c12; }
        .fv-bi-card.fv-bi-encours { border-left-color: #3498db; }
        .fv-bi-card.fv-bi-termine { border-left-color: #2ecc71; }
        .fv-bi-card.fv-bi-refuse { border-left-color: #7f1d1d; }
        .fv-bi-card-top { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; margin-bottom: 6px; }
        .fv-bi-date { font-size: 0.7rem; color: var(--ink-500); font-weight: 600; white-space: nowrap; }
        .fv-bi-status { display: inline-flex; align-items: center; padding: 3px 10px; border-radius: 20px; font-size: 0.64rem; font-weight: 800; color: #fff; white-space: nowrap; }
        .fv-bi-urgent { display: inline-flex; align-items: center; gap: 4px; padding: 2px 8px; border-radius: 20px; background: rgba(231,76,60,0.14); color: var(--danger); font-size: 0.64rem; font-weight: 800; white-space: nowrap; }
        .fv-bi-chevron { margin-left: auto; color: var(--line-strong); font-size: 0.75rem; }
        .fv-bi-desc { font-size: 0.78rem; color: var(--primary); line-height: 1.4; }
        .fv-bi-tech { font-size: 0.7rem; color: var(--ink-500); margin-top: 5px; }
        .fv-bi-meta-row { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; margin-top: 5px; }
        .fv-bi-type { display: inline-flex; align-items: center; gap: 4px; font-size: 0.68rem; font-weight: 700; white-space: nowrap; }
        .fv-bi-casse { display: inline-flex; align-items: center; gap: 4px; font-size: 0.68rem; font-weight: 700; color: var(--danger); white-space: nowrap; }
        .fv-bi-st { display: inline-flex; align-items: center; gap: 4px; font-size: 0.68rem; font-weight: 700; color: #8e44ad; white-space: nowrap; }
        .fv-bi-cr { font-size: 0.74rem; color: var(--ink-500); background: var(--surface-2); border-radius: 7px; padding: 7px 9px; margin-top: 6px; line-height: 1.4; }
        .fv-bi-cr b { color: var(--primary); }
        .fv-materiel-block { background: var(--surface-2); border: 1px solid var(--line); border-radius: 8px; padding: 12px 14px; margin-bottom: 16px; }
        .fv-card { background: #fff; border: 1px solid var(--line); border-left: 4px solid var(--line-strong); border-radius: 10px; padding: 14px 16px; margin-bottom: 14px; }
        .fv-card:last-child { margin-bottom: 0; }
        .fv-card > .fv-field:last-child { margin-bottom: 0; }
        .fv-card-icon { display: inline-flex; align-items: center; justify-content: center; width: 24px; height: 24px; border-radius: 7px; font-size: 0.7rem; flex: none; }
        .fv-card-blue { border-left-color: #3498db; background: linear-gradient(180deg, rgba(52,152,219,.07), rgba(52,152,219,0) 70px); }
        .fv-card-orange { border-left-color: #f39c12; background: linear-gradient(180deg, rgba(243,156,18,.07), rgba(243,156,18,0) 70px); }
        .fv-card-purple { border-left-color: #9b59b6; background: linear-gradient(180deg, rgba(155,89,182,.07), rgba(155,89,182,0) 70px); }
        .fv-card-green { border-left-color: #2ecc71; background: linear-gradient(180deg, rgba(46,204,113,.07), rgba(46,204,113,0) 70px); }
        .fv-interv-card { border: 1px solid var(--line); border-left: 4px solid var(--line-strong); border-radius: 9px; padding: 10px 12px; background: #fff; }
        .fv-interv-card.fv-interv-ok { border-left-color: #2ecc71; }
        .fv-interv-card.fv-interv-pasok { border-left-color: var(--danger); }
        .fv-interv-card-top { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; margin-bottom: 6px; }
        .fv-interv-tech-badge { display: inline-flex; align-items: center; gap: 5px; font-size: 0.7rem; font-weight: 700; color: var(--primary); }
        .fv-doc-card { display: flex; align-items: center; gap: 12px; border: 1px solid var(--line); border-radius: 9px; padding: 10px 12px; background: #fff; transition: .15s; }
        .fv-doc-card:hover { border-color: var(--accent); box-shadow: 0 2px 8px rgba(0,0,0,0.05); }
        .fv-doc-icon { width: 34px; height: 34px; border-radius: 9px; display: flex; align-items: center; justify-content: center; font-size: 1rem; flex: none; }
        .fv-doc-info { flex: 1; min-width: 0; }
        .fv-doc-info a { display: block; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; color: var(--primary); font-weight: 700; font-size: 0.82rem; text-decoration: none; }
        .fv-doc-info a:hover { color: var(--accent); text-decoration: underline; }
        .fv-doc-meta { font-size: 0.68rem; color: var(--ink-500); margin-top: 2px; }
        .fv-materiel-list { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 8px; }
        .fv-materiel-chip { display: inline-flex; align-items: center; gap: 6px; padding: 5px 10px; border-radius: 20px; border: 1.5px solid var(--line-strong); background: #fff; font-size: 0.76rem; font-weight: 600; color: var(--primary); cursor: pointer; user-select: none; transition: .15s; white-space: nowrap; }
        .fv-materiel-chip input { margin: 0; }
        .fv-materiel-chip.active { border-color: var(--accent); background: var(--accent-100); color: var(--accent); }
        .fv-interv-table-wrap { overflow-x: auto; }
        .fv-interv-table { width: 100%; border-collapse: collapse; font-size: 0.8rem; }
        .fv-interv-table th { text-align: left; font-size: 0.64rem; text-transform: uppercase; letter-spacing: .04em; color: var(--ink-500); font-weight: 700; padding: 6px 8px; border-bottom: 2px solid var(--line-strong); white-space: nowrap; }
        .fv-interv-table td { padding: 8px; border-bottom: 1px solid var(--line); vertical-align: top; }
        .fv-interv-table td.fv-interv-date { white-space: nowrap; color: var(--ink-500); font-weight: 600; }
        .fv-interv-table td.fv-interv-tech { white-space: nowrap; font-weight: 600; color: var(--primary); }
        .fv-interv-table td.fv-interv-actions { white-space: nowrap; text-align: right; }
        .fv-statut-badge { display: inline-flex; align-items: center; gap: 5px; padding: 3px 9px; border-radius: 20px; font-size: 0.68rem; font-weight: 700; white-space: nowrap; }
        .fv-statut-ok { background: rgba(46,204,113,0.14); color: #1a9850; }
        .fv-statut-pas_ok { background: rgba(231,76,60,0.14); color: var(--danger); }
        .fv-statut-pending { background: var(--surface-2); color: var(--ink-500); }
        .fv-memo-item { border: 1px solid var(--line); border-left: 4px solid #f39c12; border-radius: 9px; background: #fff; padding: 12px 14px; margin-bottom: 10px; }
        .fv-memo-head { display: flex; align-items: center; justify-content: space-between; gap: 10px; margin-bottom: 8px; }
        .fv-memo-head-actions { display: flex; gap: 6px; flex: none; }
        .fv-memo-item .meta { font-size: 0.7rem; color: var(--ink-500); font-weight: 700; }
        .fv-memo-block { display: flex; gap: 9px; margin-top: 8px; }
        .fv-memo-block:first-of-type { margin-top: 0; }
        .fv-memo-icon { width: 22px; height: 22px; border-radius: 6px; display: flex; align-items: center; justify-content: center; font-size: 0.64rem; flex: none; margin-top: 1px; }
        .fv-memo-icon.constat { background: rgba(243,156,18,.16); color: #c9820c; }
        .fv-memo-icon.solution { background: rgba(46,204,113,.16); color: #219150; }
        .fv-memo-label { font-size: 0.64rem; text-transform: uppercase; letter-spacing: .04em; font-weight: 800; color: var(--ink-500); margin-bottom: 2px; }
        .fv-memo-text { font-size: 0.82rem; color: var(--primary); line-height: 1.4; }
        .fv-doc-item { display: flex; align-items: center; gap: 10px; border: 1px solid var(--line-strong); border-radius: 8px; padding: 8px 12px; margin-bottom: 8px; }
        .fv-doc-item a { flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; color: var(--primary); font-weight: 600; font-size: 0.82rem; text-decoration: none; }
        .fv-doc-item a:hover { color: var(--accent); text-decoration: underline; }
        .fv-empty { color: var(--ink-500); font-size: 0.8rem; font-style: italic; padding: 14px 0; text-align: center; }
        /* Picker de localisation — mêmes classes/gabarit que le "Créer un bon d'intervention" de maintenance.php */
        .su-loc-picker { margin-top: 6px; }
        .loc-search-wrap { position: relative; width: 100%; margin-bottom: 12px; }
        .loc-search-wrap .loc-search-icon { position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: #94a3b8; font-size: 0.9rem; pointer-events: none; }
        #fv-loc-search-input { width: 100%; height: 42px; padding: 0 12px 0 36px; border: 2px solid #e2e8f0; border-radius: 8px; font-size: 0.88rem; font-family: inherit; box-sizing: border-box; transition: border-color 0.2s; }
        #fv-loc-search-input:focus { outline: none; border-color: var(--accent); }
        .loc-search-results { position: absolute; z-index: 50; top: calc(100% + 4px); left: 0; right: 0; max-height: 280px; overflow-y: auto; background: #fff; border: 1px solid #e2e8f0; border-radius: 10px; box-shadow: 0 12px 28px rgba(15,23,42,0.15); display: none; }
        .loc-search-item { padding: 9px 14px; cursor: pointer; border-bottom: 1px solid #f1f5f9; }
        .loc-search-item:last-child { border-bottom: none; }
        .loc-search-item:hover, .loc-search-item.is-active { background: #ebf5fb; }
        .loc-search-item-name { font-weight: 700; font-size: 0.85rem; color: var(--primary); }
        .loc-search-item-path { font-size: 0.72rem; color: #94a3b8; margin-top: 2px; }
        .loc-search-empty { padding: 14px; text-align: center; color: #94a3b8; font-size: 0.82rem; }

        .loc-breadcrumb { display: flex; flex-wrap: wrap; align-items: center; gap: 6px; margin: 0 0 10px; }
        .loc-crumb { display: inline-flex; align-items: center; gap: 6px; padding: 6px 12px; border-radius: 20px; background: #f1f5f9; color: #64748b; font-size: 0.78rem; font-weight: 700; cursor: pointer; border: none; font-family: inherit; transition: 0.15s; }
        .loc-crumb:hover { background: #e2e8f0; }
        .loc-crumb.is-current { background: #ebf5fb; color: var(--accent); cursor: default; }
        .loc-crumb-sep { color: #cbd5e1; font-size: 0.7rem; }

        .loc-tiles { display: grid; grid-template-columns: repeat(auto-fill, minmax(140px, 1fr)); gap: 10px; }
        .loc-tile { display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 8px; text-align: center; background: #fff; border: 2px solid #e2e8f0; border-radius: 12px; padding: 16px 10px; cursor: pointer; font-family: inherit; transition: 0.15s; }
        .loc-tile:hover { border-color: var(--accent); background: #ebf5fb; transform: translateY(-2px); }
        .loc-tile-icon { width: 38px; height: 38px; border-radius: 10px; background: #ebf5fb; color: var(--accent); display: flex; align-items: center; justify-content: center; font-size: 1.1rem; }
        .loc-tile-label { font-size: 0.8rem; font-weight: 700; color: var(--primary); line-height: 1.3; word-break: break-word; }
        .loc-empty-msg { padding: 20px; text-align: center; color: #94a3b8; font-size: 0.85rem; background: #f8fafc; border-radius: 10px; }

        .loc-done-card { grid-column: 1 / -1; display: flex; flex-direction: row; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 10px; background: #e8f8f5; border: 1.5px solid #b8ecd9; border-radius: 10px; padding: 10px 12px; }
        .loc-done-info { display: flex; align-items: center; gap: 9px; min-width: 0; }
        .loc-done-icon { width: 28px; height: 28px; border-radius: 8px; background: var(--gelpam-green); color: #fff; display: flex; align-items: center; justify-content: center; font-size: 0.85rem; flex-shrink: 0; }
        .loc-done-name { font-weight: 800; color: var(--primary); font-size: 0.8rem; }
        .loc-done-path { font-size: 0.66rem; color: #5a8a76; margin-top: 1px; }
        .loc-done-actions { display: flex; gap: 8px; flex: none; }
        .loc-done-change { flex: none; background: #fff; border: 1px solid #cbd5e1; color: var(--primary); font-weight: 700; font-size: 0.68rem; padding: 7px 13px; border-radius: 7px; cursor: pointer; font-family: inherit; white-space: nowrap; text-align: center; }
        .loc-done-change:hover { border-color: var(--accent); color: var(--accent); }
    </style>
<?php include 'pwa_head.php'; ?>
</head>
<body onclick="checkCloseSidebar(event)">

<div id="save-toast"><i class="fa-solid fa-circle-check"></i> <span id="save-toast-text"><?php echo t('pm.enregistre'); ?></span></div>

<?php $breadcrumb_label = t('pm.breadcrumb'); include 'navbar.php'; ?>

<div class="container">
    <?php echo $message; ?>

    <div class="pm-panel">
        <div class="pm-toolbar">
            <div class="pm-toolbar-top">
                <div>
                    <div class="pm-title"><i class="fa-solid fa-sitemap"></i> <?php echo t('pm.pm_title'); ?></div>
                    <div class="pm-stats">
                        <span class="pm-stat-pill usine"><i class="fa-solid fa-industry"></i> <?php echo $total_usines; ?> <?php echo t($total_usines>1?'pm.stat_sites':'pm.stat_site'); ?></span>
                        <span class="pm-stat-pill secteur"><i class="fa-solid fa-diagram-project"></i> <?php echo $total_secteurs; ?> <?php echo t($total_secteurs>1?'pm.stat_secteurs':'pm.stat_secteur'); ?></span>
                        <span class="pm-stat-pill ligne"><i class="fa-solid fa-arrow-right-long"></i> <?php echo $total_lignes; ?> <?php echo t($total_lignes>1?'pm.stat_lignes':'pm.stat_ligne'); ?></span>
                        <span class="pm-stat-pill zone"><i class="fa-solid fa-location-dot"></i> <?php echo $total_zones; ?> <?php echo t($total_zones>1?'pm.stat_zones':'pm.stat_zone'); ?></span>
                        <span class="pm-stat-pill machine"><i class="fa-solid fa-gear"></i> <?php echo $total_machines; ?> <?php echo t($total_machines>1?'pm.stat_machines':'pm.stat_machine'); ?></span>
                    </div>
                </div>
                <div style="display:flex; align-items:center; gap:10px;">
                    <button type="button" class="pm-btn" id="btnSchemaUsine">
                        <i class="fa-solid fa-diagram-project"></i> <?php echo t('pm.btn_schema'); ?>
                    </button>
                    <?php if (!$is_admin): ?>
                        <span class="badge-readonly"><i class="fa-solid fa-eye"></i> <?php echo t('pm.lecture_seule'); ?></span>
                    <?php else: ?>
                        <button type="button" class="pm-btn primary" id="btnAddTop">
                            <i class="fa-solid fa-plus"></i> <?php echo t('pm.btn_ajouter_machine'); ?>
                        </button>
                    <?php endif; ?>
                </div>
            </div>

            <div class="pm-search-row">
                <div class="pm-search">
                    <i class="fa-solid fa-magnifying-glass"></i>
                    <input type="text" id="searchInput" placeholder="<?php echo htmlspecialchars(t('pm.search_placeholder')); ?>">
                    <button type="button" id="searchClear" title="<?php echo htmlspecialchars(t('pm.tooltip_effacer_recherche')); ?>" style="display:none"><i class="fa-solid fa-circle-xmark"></i></button>
                </div>
                <button type="button" class="pm-btn ghost" id="btnExpandAll" title="<?php echo htmlspecialchars(t('pm.tooltip_tout_deplier')); ?>"><i class="fa-solid fa-angles-down"></i></button>
                <button type="button" class="pm-btn ghost" id="btnCollapseAll" title="<?php echo htmlspecialchars(t('pm.tooltip_tout_replier')); ?>"><i class="fa-solid fa-angles-up"></i></button>
                <?php if ($is_admin): ?>
                <button type="button" class="pm-btn ghost" id="btnSelectMode" title="<?php echo htmlspecialchars(t('pm.tooltip_selection_multiple')); ?>"><i class="fa-solid fa-square-check"></i> <?php echo t('pm.selection_multiple'); ?></button>
                <?php endif; ?>
            </div>

            <?php if (!empty($list_types_used)): ?>
            <div class="pm-chips" id="typeChips">
                <button type="button" class="pm-chip active" data-type=""><?php echo t('pm.tous_les_types'); ?></button>
                <?php foreach ($list_types_used as $t): ?>
                    <button type="button" class="pm-chip" data-type="<?php echo htmlspecialchars($t); ?>"><?php echo htmlspecialchars($t); ?></button>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>

        <div class="tree-wrap" id="treeRoot">
            <?php if (empty($tree)): ?>
                <div class="empty-search"><i class="fa-solid fa-industry"></i><?php echo t('pm.empty_aucune_machine'); ?></div>
            <?php endif; ?>
            <?php foreach ($tree as $u => $secteurs): $idU = 'n_' . md5('u|' . $u); ?>
            <div class="tree-node lvl-usine open" id="<?php echo $idU; ?>" data-level="usine">
                <div class="tree-row">
                    <span class="chev"><i class="fa-solid fa-chevron-right"></i></span>
                    <span class="n-ico"><i class="fa-solid fa-industry" style="color:var(--purple)"></i></span>
                    <span class="n-name" data-original="<?php echo htmlspecialchars($u); ?>"><?php echo htmlspecialchars($u); ?></span>
                    <span class="n-count"><?php echo compterUsine($secteurs); ?></span>
                    <?php if ($is_admin): ?>
                    <span class="row-actions">
                        <button type="button" class="row-btn btn-add-here" data-usine="<?php echo htmlspecialchars($u); ?>" data-secteur="" data-ligne="" data-zone="" title="<?php echo htmlspecialchars(t('pm.tooltip_ajouter_ici')); ?>"><i class="fa-solid fa-plus"></i></button>
                        <button type="button" class="row-btn btn-rename" data-type="usine" data-old="<?php echo htmlspecialchars($u); ?>" title="<?php echo htmlspecialchars(t('pm.tooltip_renommer')); ?>"><i class="fa-solid fa-pen"></i></button>
                    </span>
                    <?php endif; ?>
                </div>
                <div class="tree-children">
                    <?php foreach ($secteurs as $s => $lignes): $idS = 'n_' . md5('s|' . $u . '|' . $s); ?>
                    <div class="tree-node lvl-secteur" id="<?php echo $idS; ?>" data-level="secteur">
                        <div class="tree-row">
                            <span class="chev"><i class="fa-solid fa-chevron-right"></i></span>
                            <span class="n-ico"><i class="fa-solid fa-diagram-project" style="color:var(--gelpam-orange)"></i></span>
                            <span class="n-name" data-original="<?php echo htmlspecialchars($s); ?>"><?php echo htmlspecialchars($s); ?></span>
                            <span class="n-count"><?php echo compterSecteur($lignes); ?></span>
                            <?php if ($is_admin): ?>
                            <span class="row-actions">
                                <button type="button" class="row-btn btn-add-here" data-usine="<?php echo htmlspecialchars($u); ?>" data-secteur="<?php echo htmlspecialchars($s); ?>" data-ligne="" data-zone="" title="<?php echo htmlspecialchars(t('pm.tooltip_ajouter_ici')); ?>"><i class="fa-solid fa-plus"></i></button>
                                <button type="button" class="row-btn btn-rename" data-type="secteur" data-old="<?php echo htmlspecialchars($s); ?>" data-parent-u="<?php echo htmlspecialchars($u); ?>" title="<?php echo htmlspecialchars(t('pm.tooltip_renommer')); ?>"><i class="fa-solid fa-pen"></i></button>
                            </span>
                            <?php endif; ?>
                        </div>
                        <div class="tree-children">
                            <?php foreach ($lignes as $l => $zones): $idL = 'n_' . md5('l|' . $u . '|' . $s . '|' . $l); ?>
                            <div class="tree-node lvl-ligne" id="<?php echo $idL; ?>" data-level="ligne">
                                <div class="tree-row">
                                    <span class="chev"><i class="fa-solid fa-chevron-right"></i></span>
                                    <span class="n-ico"><i class="fa-solid fa-arrow-right-long" style="color:var(--accent)"></i></span>
                                    <span class="n-name" data-original="<?php echo htmlspecialchars($l); ?>"><?php echo htmlspecialchars($l); ?></span>
                                    <span class="n-count"><?php echo compterLigne($zones); ?></span>
                                    <?php if ($is_admin): ?>
                                    <span class="row-actions">
                                        <button type="button" class="row-btn btn-add-here" data-usine="<?php echo htmlspecialchars($u); ?>" data-secteur="<?php echo htmlspecialchars($s); ?>" data-ligne="<?php echo htmlspecialchars($l); ?>" data-zone="" title="<?php echo htmlspecialchars(t('pm.tooltip_ajouter_ici')); ?>"><i class="fa-solid fa-plus"></i></button>
                                        <button type="button" class="row-btn btn-rename" data-type="ligne" data-old="<?php echo htmlspecialchars($l); ?>" data-parent-u="<?php echo htmlspecialchars($u); ?>" data-parent-s="<?php echo htmlspecialchars($s); ?>" title="<?php echo htmlspecialchars(t('pm.tooltip_renommer')); ?>"><i class="fa-solid fa-pen"></i></button>
                                    </span>
                                    <?php endif; ?>
                                </div>
                                <div class="tree-children">
                                    <?php foreach ($zones as $z => $machines): $idZ = 'n_' . md5('z|' . $u . '|' . $s . '|' . $l . '|' . $z); ?>
                                    <div class="tree-node lvl-zone" id="<?php echo $idZ; ?>" data-level="zone">
                                        <div class="tree-row">
                                            <span class="chev"><i class="fa-solid fa-chevron-right"></i></span>
                                            <span class="n-ico"><i class="fa-solid fa-location-dot" style="color:var(--gelpam-green)"></i></span>
                                            <span class="n-name" data-original="<?php echo htmlspecialchars($z); ?>"><?php echo htmlspecialchars($z); ?></span>
                                            <span class="n-count"><?php echo compterZone($machines); ?></span>
                                            <span class="row-actions">
                                                <?php if ($is_admin): ?>
                                                <button type="button" class="row-btn btn-add-here" data-usine="<?php echo htmlspecialchars($u); ?>" data-secteur="<?php echo htmlspecialchars($s); ?>" data-ligne="<?php echo htmlspecialchars($l); ?>" data-zone="<?php echo htmlspecialchars($z); ?>" title="<?php echo htmlspecialchars(t('pm.tooltip_ajouter_ici')); ?>"><i class="fa-solid fa-plus"></i></button>
                                                <button type="button" class="row-btn btn-rename" data-type="zone" data-old="<?php echo htmlspecialchars($z); ?>" data-parent-u="<?php echo htmlspecialchars($u); ?>" data-parent-s="<?php echo htmlspecialchars($s); ?>" data-parent-l="<?php echo htmlspecialchars($l); ?>" title="<?php echo htmlspecialchars(t('pm.tooltip_renommer')); ?>"><i class="fa-solid fa-pen"></i></button>
                                                <?php endif; ?>
                                            </span>
                                        </div>
                                        <div class="tree-children sortable-m" data-usine="<?php echo htmlspecialchars($u); ?>" data-secteur="<?php echo htmlspecialchars($s); ?>" data-ligne="<?php echo htmlspecialchars($l); ?>" data-zone="<?php echo htmlspecialchars($z); ?>">
                                            <?php if (empty($machines)): ?>
                                                <div class="empty-zone"><?php echo t('pm.empty_zone'); ?></div>
                                            <?php endif; ?>
                                            <?php foreach ($machines as $m): ?>
                                            <div class="m-row" draggable="<?php echo $is_admin ? 'true' : 'false'; ?>" data-id="<?php echo $m['id']; ?>" data-type="<?php echo htmlspecialchars($m['type_equipement'] ?? ''); ?>">
                                                <?php if ($is_admin): ?>
                                                <input type="checkbox" class="m-checkbox" data-id="<?php echo $m['id']; ?>">
                                                <span class="grip"><i class="fa-solid fa-grip-vertical"></i></span>
                                                <?php endif; ?>
                                                <i class="fa-solid fa-gear" style="color:#b8c2ca; flex:none;"></i>
                                                <span class="m-name" data-original="<?php echo htmlspecialchars($m['nom_machine']); ?>"><?php echo htmlspecialchars($m['nom_machine']); ?></span>
                                                <?php if (!empty($m['type_equipement'])): ?><span class="m-type"><?php echo htmlspecialchars($m['type_equipement']); ?></span><?php endif; ?>
                                                <span class="row-actions">
                                                    <button type="button" class="row-btn btn-bi" data-id="<?php echo $m['id']; ?>" data-name="<?php echo htmlspecialchars($m['nom_machine']); ?>" data-usine="<?php echo htmlspecialchars($u); ?>" data-secteur="<?php echo htmlspecialchars($s); ?>" data-ligne="<?php echo htmlspecialchars($l); ?>" data-zone="<?php echo htmlspecialchars($z); ?>" title="<?php echo htmlspecialchars(t('pm.tooltip_bi')); ?>"><i class="fa-solid fa-clipboard-list"></i> BI</button>
                                                    <button type="button" class="row-btn btn-creer-bi" data-name="<?php echo htmlspecialchars($m['nom_machine']); ?>" data-usine="<?php echo htmlspecialchars($u); ?>" data-secteur="<?php echo htmlspecialchars($s); ?>" data-ligne="<?php echo htmlspecialchars($l); ?>" data-zone="<?php echo htmlspecialchars($z); ?>" title="<?php echo htmlspecialchars(t('pm.tooltip_creer_bi')); ?>"><i class="fa-solid fa-plus"></i> <?php echo t('pm.btn_creer_bi_court'); ?></button>
                                                    <?php if ($is_admin): ?>
                                                    <a href="admin_machines.php?edit=<?php echo $m['id']; ?>#panel" class="row-btn" title="<?php echo htmlspecialchars(t('pm.tooltip_modifier')); ?>"><i class="fa-solid fa-pen"></i></a>
                                                    <button type="button" class="row-btn danger btn-delete" data-id="<?php echo $m['id']; ?>" data-name="<?php echo htmlspecialchars($m['nom_machine']); ?>" title="<?php echo htmlspecialchars(t('pm.tooltip_supprimer')); ?>"><i class="fa-solid fa-trash"></i></button>
                                                    <?php endif; ?>
                                                </span>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endforeach; ?>
            <div class="empty-search" id="noResults" style="display:none;"><i class="fa-solid fa-magnifying-glass"></i><?php echo t('pm.empty_aucun_resultat'); ?></div>
        </div>
    </div>
</div>

<div class="modal-bg" id="biMachineModal">
    <div class="modal-box" style="width:800px; max-width:95vw; text-align:left;">
        <div style="display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:14px; gap:12px;">
            <div>
                <h3 style="margin:0;"><i class="fa-solid fa-clipboard-list" style="color:var(--accent); margin-right:8px;"></i><span id="biMachineTitle"></span></h3>
                <p id="biMachineLoc" style="margin:4px 0 0; font-size:0.78rem; color:#94a3b8;"></p>
            </div>
            <span style="cursor:pointer; font-size:24px; color:#cbd5e1; line-height:1; flex:none;" id="biMachineClose">&times;</span>
        </div>
        <div id="biMachineFilters" style="display:none; gap:10px; margin-bottom:12px;">
            <select id="biFilterTech" class="pm-btn" style="flex:1;"><option value=""><?php echo t('pm.bi_tous_techniciens'); ?></option></select>
            <select id="biFilterStatut" class="pm-btn" style="flex:1;">
                <option value=""><?php echo t('pm.bi_tous_statuts'); ?></option>
                <option value="afaire"><?php echo t('maint.lib_afaire'); ?></option>
                <option value="encours"><?php echo t('maint.lib_encours'); ?></option>
                <option value="termine"><?php echo t('maint.lib_termine'); ?></option>
            </select>
        </div>
        <div id="biMachineBody" style="max-height:60vh; overflow-y:auto;"></div>
    </div>
</div>

<div class="schema-overlay" id="schemaOverlay">
    <div class="schema-box" id="schemaBox">
        <div class="schema-head">
            <div class="schema-head-left">
                <button type="button" class="su-back" id="schemaBack" title="<?php echo htmlspecialchars(t('pm.schema_tooltip_retour')); ?>"><i class="fa-solid fa-arrow-left"></i></button>
                <h3 id="schemaTitle"><i class="fa-solid fa-diagram-project" style="color:var(--accent); margin-right:8px;"></i><?php echo t('pm.schema_titre_defaut'); ?></h3>
            </div>
            <div style="display:flex; align-items:center; gap:8px;">
                <?php if ($is_admin): ?>
                <button type="button" class="pm-btn" id="btnAddZone" style="display:none;"><i class="fa-solid fa-plus"></i> <?php echo t('pm.schema_ajouter_forme'); ?></button>
                <a href="parametres.php?tab=schema_categories" class="pm-btn" id="btnCategoriesSchema" style="display:none; text-decoration:none;" title="<?php echo htmlspecialchars(t('pm.schema_tooltip_categories')); ?>"><i class="fa-solid fa-gear"></i></a>
                <button type="button" class="pm-btn" id="btnEditToggle" style="display:none;"><i class="fa-solid fa-pen"></i> <?php echo t('pm.schema_modifier_plan'); ?></button>
                <div class="su-grow-wrap" style="position:relative; display:none;" id="suGrowWrap">
                    <button type="button" class="pm-btn" id="btnGrowCanvas" title="<?php echo htmlspecialchars(t('pm.schema_tooltip_agrandir')); ?>"><i class="fa-solid fa-up-right-and-down-left-from-center"></i> <?php echo t('pm.schema_agrandir_plan'); ?></button>
                    <div class="su-grow-pad" id="suGrowPad">
                        <div class="su-grow-mode" id="suGrowMode">
                            <button type="button" class="su-grow-mode-btn active" data-mode="grow"><i class="fa-solid fa-plus"></i> <?php echo t('pm.schema_mode_agrandir'); ?></button>
                            <button type="button" class="su-grow-mode-btn" data-mode="shrink"><i class="fa-solid fa-minus"></i> <?php echo t('pm.schema_mode_reduire'); ?></button>
                        </div>
                        <div class="su-grow-arrows">
                            <button type="button" class="su-grow-arrow su-grow-top" data-dir="top" title="<?php echo htmlspecialchars(t('pm.schema_dir_haut')); ?>"><i class="fa-solid fa-chevron-up"></i></button>
                            <button type="button" class="su-grow-arrow su-grow-left" data-dir="left" title="<?php echo htmlspecialchars(t('pm.schema_dir_gauche')); ?>"><i class="fa-solid fa-chevron-left"></i></button>
                            <span class="su-grow-center"><i class="fa-solid fa-border-none"></i></span>
                            <button type="button" class="su-grow-arrow su-grow-right" data-dir="right" title="<?php echo htmlspecialchars(t('pm.schema_dir_droite')); ?>"><i class="fa-solid fa-chevron-right"></i></button>
                            <button type="button" class="su-grow-arrow su-grow-bottom" data-dir="bottom" title="<?php echo htmlspecialchars(t('pm.schema_dir_bas')); ?>"><i class="fa-solid fa-chevron-down"></i></button>
                        </div>
                        <p class="su-grow-hint"><?php echo t('pm.schema_hint_reduire'); ?></p>
                    </div>
                </div>
                <?php endif; ?>
                <button type="button" id="schemaClose">&times;</button>
            </div>
        </div>
        <div class="schema-body" id="schemaBody">
            <p class="fv-empty"><i class="fa-solid fa-spinner fa-spin"></i> <?php echo t('pm.schema_chargement'); ?></p>
        </div>
        <div class="su-zoom-controls" id="suZoomControls">
            <button type="button" id="suZoomOut" title="<?php echo htmlspecialchars(t('pm.zoom_arriere')); ?>"><i class="fa-solid fa-magnifying-glass-minus"></i></button>
            <button type="button" id="suZoomReset" title="<?php echo htmlspecialchars(t('pm.zoom_reinitialiser')); ?>">100%</button>
            <button type="button" id="suZoomIn" title="<?php echo htmlspecialchars(t('pm.zoom_avant')); ?>"><i class="fa-solid fa-magnifying-glass-plus"></i></button>
        </div>
        <?php if ($is_admin): ?>
        <div class="su-props-panel" id="suPropsPanel">
            <div class="su-props-head">
                <span><?php echo t('pm.props_titre'); ?><small><i class="fa-solid fa-arrows-up-down-left-right"></i> <?php echo t('pm.props_hint'); ?></small></span>
                <button type="button" id="suPropsClose" class="row-btn"><i class="fa-solid fa-xmark"></i></button>
            </div>
            <div class="su-props-body">

                <div class="fv-grid" style="grid-template-columns: 1fr 1.4fr;">
                    <div>
                        <div class="section-label"><span><?php echo t('pm.props_texte'); ?></span><span class="rule"></span><button type="button" id="btnRecenterText" class="row-btn" title="<?php echo htmlspecialchars(t('pm.props_tooltip_recentrer')); ?>"><i class="fa-solid fa-compress"></i></button></div>
                        <div class="fv-field"><input type="text" id="sp-label" placeholder="<?php echo htmlspecialchars(t('pm.props_texte_placeholder')); ?>"></div>
                        <p style="margin:6px 0 0; font-size:0.66rem; color:var(--ink-500);"><?php echo t('pm.props_hint_glisser_texte'); ?></p>
                    </div>
                    <div>
                        <div class="section-label"><span><?php echo t('pm.props_forme'); ?></span><span class="rule"></span></div>
                        <div class="fv-field">
                            <input type="hidden" id="sp-shape" value="rect">
                            <button type="button" class="su-shape-trigger" id="btnShapePicker">
                                <span class="su-shape-preview" id="shapePickerPreview"></span>
                                <span id="shapePickerLabel"><?php echo t('pm.tag_rectangle'); ?></span>
                                <i class="fa-solid fa-chevron-down" style="margin-left:auto; color:var(--ink-500);"></i>
                            </button>
                        </div>
                    </div>
                </div>

                <div>
                    <div class="section-label"><span><?php echo t('pm.props_categorie'); ?></span><span class="rule"></span></div>
                    <div class="fv-field" style="margin-bottom:14px;">
                        <div style="display:flex; gap:8px; align-items:center;">
                            <select id="sp-categorie" style="flex:1;">
                                <option value=""><?php echo t('pm.props_proprietes_libres'); ?></option>
                                <?php foreach ($CATEGORIES_VISUELLES as $cv): ?>
                                <option value="<?php echo (int)$cv['id']; ?>"><?php echo htmlspecialchars($cv['nom']); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <button type="button" class="row-btn" id="btnReappliquerCategorie" title="<?php echo htmlspecialchars(t('pm.props_tooltip_reappliquer')); ?>"><i class="fa-solid fa-rotate"></i></button>
                        </div>
                        <div id="spAdvancedToggleRow" style="display:none; margin-top:8px;">
                            <button type="button" class="row-btn" id="btnToggleAdvanced" style="width:100%; justify-content:center; font-size:0.72rem; gap:6px;"><i class="fa-solid fa-sliders"></i> <span id="btnToggleAdvancedLabel"><?php echo t('pm.props_afficher_avances'); ?></span></button>
                        </div>
                        <p style="margin:6px 0 0; font-size:0.66rem; color:var(--ink-500);"><?php echo t('pm.props_hint_categorie'); ?></p>
                    </div>
                </div>

                <div id="spAdvancedSettings" style="display:flex; flex-direction:column; gap:14px;">

                <div>
                    <div class="section-label"><span><?php echo t('pm.props_couleurs'); ?></span><span class="rule"></span></div>
                    <div class="fv-grid fv-grid-5">
                        <div class="fv-field"><?php echo t('pm.props_fond'); ?><input type="color" id="sp-fill"></div>
                        <div class="fv-field" id="spFill2Wrap" style="display:none;"><?php echo t('pm.props_fond2'); ?><input type="color" id="sp-fill2"></div>
                        <div class="fv-field"><?php echo t('pm.props_bordure'); ?><input type="color" id="sp-border"></div>
                        <div class="fv-field"><?php echo t('pm.props_texte_color'); ?><input type="color" id="sp-text-color"></div>
                        <div class="fv-field" style="justify-content:flex-end; grid-column: span 2;">
                            <label class="su-props-checkbox"><input type="checkbox" id="sp-gradient"> <?php echo t('pm.props_degrade'); ?></label>
                            <label class="su-props-checkbox" style="margin-top:8px;"><input type="checkbox" id="sp-border-none"> <?php echo t('pm.props_sans_bordure'); ?></label>
                            <label class="su-props-checkbox" style="margin-top:8px;"><input type="checkbox" id="sp-text-color-auto"> <?php echo t('pm.props_texte_auto'); ?></label>
                        </div>
                        <div id="spGradientDirWrap" style="display:none; grid-column: 1 / -1;">
                            <input type="hidden" id="sp-gradient-angle" value="135">
                            <div class="su-dir-row" id="suGradientDirRow">
                                <button type="button" class="su-dir-tile" data-angle="135" title="<?php echo htmlspecialchars(t('pm.props_dir_diagonal_tooltip')); ?>"><span class="su-dir-arrow">↘</span><span><?php echo t('pm.props_dir_diagonal'); ?></span></button>
                                <button type="button" class="su-dir-tile" data-angle="90" title="<?php echo htmlspecialchars(t('pm.props_dir_gd_tooltip')); ?>"><span class="su-dir-arrow">→</span><span><?php echo t('pm.props_dir_gd'); ?></span></button>
                                <button type="button" class="su-dir-tile" data-angle="180" title="<?php echo htmlspecialchars(t('pm.props_dir_hb_tooltip')); ?>"><span class="su-dir-arrow">↓</span><span><?php echo t('pm.props_dir_hb'); ?></span></button>
                                <button type="button" class="su-dir-tile" data-angle="270" title="<?php echo htmlspecialchars(t('pm.props_dir_dg_tooltip')); ?>"><span class="su-dir-arrow">←</span><span><?php echo t('pm.props_dir_dg'); ?></span></button>
                                <button type="button" class="su-dir-tile" data-angle="0" title="<?php echo htmlspecialchars(t('pm.props_dir_bh_tooltip')); ?>"><span class="su-dir-arrow">↑</span><span><?php echo t('pm.props_dir_bh'); ?></span></button>
                            </div>
                        </div>
                    </div>
                </div>

                <div>
                    <div class="section-label"><span><?php echo t('pm.props_effet'); ?></span><span class="rule"></span></div>
                    <input type="hidden" id="sp-effect" value="none">
                    <div class="su-effect-row" id="suEffectRow">
                        <button type="button" class="su-effect-tile" data-effect="none"><span class="su-effect-swatch"></span><span><?php echo t('pm.effet_aucun'); ?></span></button>
                        <button type="button" class="su-effect-tile" data-effect="shadow"><span class="su-effect-swatch eff-shadow"></span><span><?php echo t('pm.effet_ombre'); ?></span></button>
                        <button type="button" class="su-effect-tile" data-effect="bevel"><span class="su-effect-swatch eff-bevel"></span><span><?php echo t('pm.effet_relief'); ?></span></button>
                        <button type="button" class="su-effect-tile" data-effect="inset"><span class="su-effect-swatch eff-inset"></span><span><?php echo t('pm.effet_creux'); ?></span></button>
                        <button type="button" class="su-effect-tile" data-effect="glow"><span class="su-effect-swatch eff-glow"></span><span><?php echo t('pm.effet_lueur'); ?></span></button>
                    </div>
                </div>

                <div>
                    <div class="section-label"><span><?php echo t('pm.props_taille_rotation'); ?></span><span class="rule"></span></div>
                    <div class="fv-grid fv-grid-3">
                        <div class="fv-field"><?php echo t('pm.props_largeur'); ?>
                            <div style="display:flex; align-items:center; gap:5px;">
                                <button type="button" class="row-btn" id="btnWidthSmaller" title="<?php echo htmlspecialchars(t('pm.props_tooltip_reduire_largeur')); ?>"><i class="fa-solid fa-minus"></i></button>
                                <input type="number" id="sp-width" step="0.5" min="0.5" max="100" style="text-align:center;">
                                <button type="button" class="row-btn" id="btnWidthBigger" title="<?php echo htmlspecialchars(t('pm.props_tooltip_agrandir_largeur')); ?>"><i class="fa-solid fa-plus"></i></button>
                            </div>
                        </div>
                        <div class="fv-field"><?php echo t('pm.props_hauteur'); ?>
                            <div style="display:flex; align-items:center; gap:5px;">
                                <button type="button" class="row-btn" id="btnHeightSmaller" title="<?php echo htmlspecialchars(t('pm.props_tooltip_reduire_hauteur')); ?>"><i class="fa-solid fa-minus"></i></button>
                                <input type="number" id="sp-height" step="0.5" min="0.5" max="100" style="text-align:center;">
                                <button type="button" class="row-btn" id="btnHeightBigger" title="<?php echo htmlspecialchars(t('pm.props_tooltip_agrandir_hauteur')); ?>"><i class="fa-solid fa-plus"></i></button>
                            </div>
                        </div>
                        <div class="fv-field"><?php echo t('pm.props_taille_texte'); ?>
                            <div style="display:flex; align-items:center; gap:5px;">
                                <button type="button" class="row-btn" id="btnFontSmaller" title="<?php echo htmlspecialchars(t('pm.props_tooltip_reduire')); ?>"><i class="fa-solid fa-minus"></i></button>
                                <input type="number" id="sp-font-size" step="0.05" min="0.3" max="3" placeholder="auto" style="text-align:center;">
                                <button type="button" class="row-btn" id="btnFontBigger" title="<?php echo htmlspecialchars(t('pm.props_tooltip_agrandir')); ?>"><i class="fa-solid fa-plus"></i></button>
                            </div>
                        </div>
                        <div class="fv-field"><?php echo t('pm.props_rotation_forme'); ?><input type="number" id="sp-rotation" step="1" min="-180" max="180"></div>
                        <div class="fv-field"><?php echo t('pm.props_rotation_texte'); ?><input type="number" id="sp-text-rotation" step="1" min="-180" max="180"></div>
                    </div>
                </div>

                <div>
                    <div class="section-label"><span><?php echo t('pm.props_comportement'); ?></span><span class="rule"></span></div>
                    <div class="su-props-row">
                        <label class="su-props-checkbox"><input type="checkbox" id="sp-clickable"> <?php echo t('pm.props_cliquable'); ?></label>
                        <div class="su-props-order">
                            <button type="button" class="pm-btn" id="btnSendBack"><i class="fa-solid fa-arrow-down"></i> <?php echo t('pm.props_arriere_plan'); ?></button>
                            <button type="button" class="pm-btn" id="btnSendFront"><i class="fa-solid fa-arrow-up"></i> <?php echo t('pm.props_premier_plan'); ?></button>
                        </div>
                    </div>
                </div>

                </div>

            </div>
            <div class="su-props-actions">
                <button type="button" class="pm-btn primary" id="btnValiderForme"><i class="fa-solid fa-check"></i> <?php echo t('pm.props_btn_valider'); ?></button>
                <button type="button" class="row-btn danger" id="btnDeleteZone" title="<?php echo htmlspecialchars(t('pm.props_tooltip_supprimer_forme')); ?>"><i class="fa-solid fa-trash"></i></button>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<div class="modal-bg" id="ficheVieModal">
    <div class="modal-box">
        <div style="display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:6px; gap:12px;">
            <div>
                <h3 style="margin:0;"><i class="fa-solid fa-id-card" style="color:var(--accent); margin-right:8px;"></i><span id="fvTitle"></span></h3>
                <p id="fvSchemaNom" style="margin:4px 0 0; font-size:0.78rem; color:#94a3b8;"></p>
            </div>
            <button type="button" class="pm-btn" id="fvClose" style="flex:none; background:var(--danger); border-color:var(--danger); color:#fff;"><i class="fa-solid fa-xmark"></i> <?php echo t('pm.fv_btn_fermer'); ?></button>
        </div>

        <div class="fv-tabs">
            <button type="button" class="fv-tab active" data-pane="fv-pane-refs"><?php echo t('pm.fv_tab_fiche_technique'); ?></button>
            <button type="button" class="fv-tab" data-pane="fv-pane-histo"><?php echo t('pm.fv_tab_historique_bi'); ?></button>
            <button type="button" class="fv-tab" data-pane="fv-pane-interv"><?php echo t('pm.fv_tab_interventions'); ?></button>
            <button type="button" class="fv-tab" data-pane="fv-pane-docs"><?php echo t('pm.fv_tab_documentation'); ?></button>
            <button type="button" class="fv-tab" data-pane="fv-pane-devis"><?php echo t('pm.fv_tab_devis'); ?></button>
            <button type="button" class="fv-tab" data-pane="fv-pane-aide"><i class="fa-solid fa-lightbulb"></i> <?php echo t('pm.fv_tab_aide'); ?></button>
        </div>

        <div id="fvBody" style="max-height:74vh; overflow-y:auto;">
            <p class="fv-empty"><i class="fa-solid fa-spinner fa-spin"></i> <?php echo t('pm.chargement'); ?></p>
        </div>
    </div>
</div>

<div class="modal-bg" id="fvConfirmModal">
    <div class="modal-box confirm">
        <i class="fa-solid fa-circle-exclamation big" style="color:var(--danger);"></i>
        <h3><?php echo t('pm.fv_confirmer_titre'); ?></h3>
        <p id="fvConfirmText"><?php echo t('pm.delete_defaut'); ?></p>
        <div class="modal-actions">
            <button class="modal-btn-cancel" id="fvConfirmCancel"><?php echo t('pm.btn_annuler'); ?></button>
            <button class="modal-btn-danger" id="fvConfirmOk"><?php echo t('pm.delete_btn'); ?></button>
        </div>
    </div>
</div>

<div class="modal-bg" id="fvLocPickerModal">
    <div class="modal-box" style="width:640px; max-width:94vw; max-height:90vh; margin:5vh auto; text-align:left; overflow-y:auto;">
        <div style="display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:12px; gap:12px;">
            <h3 style="margin:0;"><?php echo t('pm.loc_rattacher_titre'); ?></h3>
            <span style="cursor:pointer; font-size:22px; color:#cbd5e1; line-height:1; flex:none;" id="fvLocPickerClose">&times;</span>
        </div>
        <div class="su-loc-picker">
            <div class="loc-search-wrap">
                <i class="fa-solid fa-magnifying-glass loc-search-icon"></i>
                <input type="text" id="fv-loc-search-input" placeholder="<?php echo htmlspecialchars(t('pm.loc_search_placeholder')); ?>">
                <div class="loc-search-results" id="fv-loc-search-results"></div>
            </div>
            <div class="loc-breadcrumb" id="fv-loc-breadcrumb"></div>
            <div class="loc-tiles" id="fv-loc-tiles"></div>
        </div>
    </div>
</div>

<div class="modal-bg" id="fvMaterielModal">
    <div class="modal-box" style="width:680px; max-width:94vw; max-height:90vh; margin:5vh auto; text-align:left;">
        <h3><?php echo t('pm.fv_titre_materiel_modal'); ?></h3>
        <p style="font-size:0.78rem; color:#666; margin:-6px 0 14px;"><?php echo t('pm.fv_hint_materiel_modal'); ?></p>
        <div class="fv-materiel-list" id="fvMaterielModalList"></div>
        <div class="modal-actions">
            <button type="button" class="modal-btn-cancel" id="fvMaterielCancel"><?php echo t('pm.btn_annuler'); ?></button>
            <button type="button" class="modal-btn-ok" id="fvMaterielOk"><?php echo t('pm.btn_valider'); ?></button>
        </div>
    </div>
</div>

<?php if ($is_admin): ?>
<div class="modal-bg" id="shapePickerModal">
    <div class="modal-box" style="width:640px; max-width:96vw; text-align:left;">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:14px;">
            <h3 style="margin:0;"><i class="fa-solid fa-shapes" style="color:var(--accent); margin-right:8px;"></i><?php echo t('pm.shape_picker_titre'); ?></h3>
            <span style="cursor:pointer; font-size:24px; color:#cbd5e1; line-height:1;" id="shapePickerClose">&times;</span>
        </div>
        <div class="shape-picker-grid" id="shapePickerGrid"></div>
    </div>
</div>
<?php endif; ?>

<?php if ($is_admin): ?>
<div class="pm-overlay" id="pmOverlay">
    <div class="pm-side" id="panel">
        <div class="pm-side-head">
            <div class="pm-side-head-left">
                <div class="pm-side-head-ico"><i class="fa-solid fa-gear" id="panelHeadIco"></i></div>
                <div>
                    <h3 id="panelTitle"><?php echo t('pm.form_ajouter_machine'); ?></h3>
                    <p><?php echo t('pm.form_hint'); ?></p>
                </div>
            </div>
            <button type="button" class="row-btn" id="panelClose"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <form method="POST" id="machineForm">
            <div class="pm-side-body">
                <input type="hidden" name="action" id="f-action" value="<?php echo $machine_edit ? 'edit' : 'add'; ?>">
                <?php if ($machine_edit): ?><input type="hidden" name="machine_id" value="<?php echo $machine_edit['id']; ?>"><?php endif; ?>

                <div>
                    <div class="section-label"><span><?php echo t('pm.form_emplacement'); ?></span><span class="rule"></span></div>
                    <div class="loc-grid">
                        <div class="field"><?php echo t('pm.form_usine_lieu'); ?>
                            <select name="usine_select" id="u-s" onchange="check('u'); updateBreadcrumbPreview();" required>
                                <option value="" disabled <?php echo !$machine_edit ? 'selected' : ''; ?>><?php echo t('pm.form_choisir'); ?></option>
                                <?php foreach ($list_usines as $u) echo "<option value='" . htmlspecialchars($u) . "' " . ($machine_edit && $machine_edit['usine'] == $u ? 'selected' : '') . ">" . htmlspecialchars($u) . "</option>"; ?>
                                <option value="autre"><?php echo t('pm.form_nouveau'); ?></option>
                            </select>
                            <input type="text" name="usine_new" id="u-n" style="display:none" placeholder="<?php echo htmlspecialchars(t('pm.form_placeholder_nom')); ?>" oninput="updateBreadcrumbPreview()">
                        </div>

                        <div class="field"><?php echo t('pm.form_secteur'); ?>
                            <select name="secteur_select" id="s-s" onchange="check('s'); updateBreadcrumbPreview();" required>
                                <option value="" disabled <?php echo !$machine_edit ? 'selected' : ''; ?>><?php echo t('pm.form_choisir'); ?></option>
                                <?php foreach ($list_secteurs as $s) echo "<option value='" . htmlspecialchars($s) . "' " . ($machine_edit && $machine_edit['secteur'] == $s ? 'selected' : '') . ">" . htmlspecialchars($s) . "</option>"; ?>
                                <option value="autre"><?php echo t('pm.form_nouveau'); ?></option>
                            </select>
                            <input type="text" name="secteur_new" id="s-n" style="display:none" placeholder="<?php echo htmlspecialchars(t('pm.form_placeholder_nom')); ?>" oninput="updateBreadcrumbPreview()">
                        </div>

                        <div class="field"><?php echo t('pm.form_ligne'); ?>
                            <select name="ligne_select" id="l-s" onchange="check('l'); updateBreadcrumbPreview();" required>
                                <option value="" disabled <?php echo !$machine_edit ? 'selected' : ''; ?>><?php echo t('pm.form_choisir'); ?></option>
                                <?php foreach ($list_lignes as $l) echo "<option value='" . htmlspecialchars($l) . "' " . ($machine_edit && $machine_edit['ligne'] == $l ? 'selected' : '') . ">" . htmlspecialchars($l) . "</option>"; ?>
                                <option value="autre"><?php echo t('pm.form_nouveau'); ?></option>
                            </select>
                            <input type="text" name="ligne_new" id="l-n" style="display:none" placeholder="<?php echo htmlspecialchars(t('pm.form_placeholder_nom')); ?>" oninput="updateBreadcrumbPreview()">
                        </div>

                        <div class="field"><?php echo t('pm.form_zone'); ?>
                            <select name="zone_select" id="z-s" onchange="check('z'); updateBreadcrumbPreview();" required>
                                <option value="" disabled <?php echo !$machine_edit ? 'selected' : ''; ?>><?php echo t('pm.form_choisir'); ?></option>
                                <?php foreach ($list_zones as $z) echo "<option value='" . htmlspecialchars($z) . "' " . ($machine_edit && $machine_edit['zone'] == $z ? 'selected' : '') . ">" . htmlspecialchars($z) . "</option>"; ?>
                                <option value="autre"><?php echo t('pm.form_nouveau'); ?></option>
                            </select>
                            <input type="text" name="zone_new" id="z-n" style="display:none" placeholder="<?php echo htmlspecialchars(t('pm.form_placeholder_nom')); ?>" oninput="updateBreadcrumbPreview()">
                        </div>
                    </div>
                    <div class="breadcrumb-preview" id="breadcrumbPreview"><span class="ph"><?php echo t('pm.form_breadcrumb_placeholder'); ?></span></div>
                </div>

                <div>
                    <div class="section-label"><span><?php echo t('pm.form_identite'); ?></span><span class="rule"></span></div>
                    <div class="field" style="margin-bottom:16px;"><?php echo t('pm.form_nom_machine'); ?>
                        <input type="text" name="nom_machine" id="f-nom" class="name-input" value="<?php echo $machine_edit ? htmlspecialchars($machine_edit['nom_machine']) : ''; ?>" required>
                    </div>

                    <div class="field"><?php echo t('pm.form_type_equipement'); ?> <span style="text-transform:none; font-weight:400; color:#aaa;"><?php echo t('pm.form_optionnel'); ?></span></div>
                    <?php $type_est_fixe_ou_connu = $machine_edit && (in_array($machine_edit['type_equipement'], $TYPES_EQUIPEMENT) || in_array($machine_edit['type_equipement'], $custom_types_used)); ?>
                    <select name="type_select" id="type-s" onchange="check('type'); syncTypeChips();" style="display:none">
                        <option value="" <?php echo (!$machine_edit || empty($machine_edit['type_equipement'])) ? 'selected' : ''; ?>><?php echo t('pm.fv_non_renseigne'); ?></option>
                        <?php foreach ($TYPES_EQUIPEMENT as $t): ?>
                            <option value="<?php echo htmlspecialchars($t); ?>" <?php echo ($machine_edit && $machine_edit['type_equipement'] === $t) ? 'selected' : ''; ?>><?php echo htmlspecialchars($t); ?></option>
                        <?php endforeach; ?>
                        <?php foreach ($custom_types_used as $t): ?>
                            <option value="<?php echo htmlspecialchars($t); ?>" <?php echo ($machine_edit && $machine_edit['type_equipement'] === $t) ? 'selected' : ''; ?>><?php echo htmlspecialchars($t); ?></option>
                        <?php endforeach; ?>
                        <option value="autre" <?php echo ($machine_edit && !empty($machine_edit['type_equipement']) && !$type_est_fixe_ou_connu) ? 'selected' : ''; ?>><?php echo t('pm.form_autre_preciser'); ?></option>
                    </select>
                    <div class="type-grid" id="typeGrid">
                        <?php foreach ($TYPES_EQUIPEMENT as $t): ?>
                        <div class="type-chip" data-value="<?php echo htmlspecialchars($t); ?>"><i class="fa-solid <?php echo htmlspecialchars($TYPES_ICONS[$t]); ?>"></i><span><?php echo htmlspecialchars($t); ?></span></div>
                        <?php endforeach; ?>
                        <?php foreach ($custom_types_used as $t): ?>
                        <div class="type-chip" data-value="<?php echo htmlspecialchars($t); ?>"><i class="fa-solid fa-tag"></i><span><?php echo htmlspecialchars($t); ?></span></div>
                        <?php endforeach; ?>
                        <div class="type-chip" data-value="autre"><i class="fa-solid fa-ellipsis"></i><span><?php echo t('pm.form_autre'); ?></span></div>
                    </div>
                    <input type="text" name="type_new" id="type-n" style="display:none; margin-top:10px;" placeholder="<?php echo htmlspecialchars(t('pm.form_placeholder_type')); ?>" value="<?php echo ($machine_edit && !empty($machine_edit['type_equipement']) && !$type_est_fixe_ou_connu) ? htmlspecialchars($machine_edit['type_equipement']) : ''; ?>">
                </div>
            </div>
            <div class="pm-side-foot">
                <div class="hint"><i class="fa-solid fa-circle-info"></i> <?php echo t('pm.form_footer_hint'); ?></div>
                <div class="actions">
                    <button type="button" class="pm-btn" id="panelCancel"><?php echo t('pm.btn_annuler'); ?></button>
                    <button type="submit" class="pm-btn primary"><i class="fa-solid fa-floppy-disk"></i> <?php echo t('pm.form_btn_enregistrer'); ?></button>
                </div>
            </div>
        </form>
    </div>
</div>

<div class="modal-bg" id="renameModal">
    <div class="modal-box rename">
        <h3><?php echo t('pm.rename_titre'); ?></h3>
        <p id="renameHint"><?php echo t('pm.rename_hint'); ?></p>
        <input type="text" id="renameInput">
        <div class="modal-actions">
            <button class="modal-btn-cancel" id="renameCancel"><?php echo t('pm.btn_annuler'); ?></button>
            <button class="modal-btn-ok" id="renameOk"><?php echo t('pm.rename_btn_enregistrer'); ?></button>
        </div>
    </div>
</div>

<div class="modal-bg" id="confirmModal">
    <div class="modal-box confirm">
        <i class="fa-solid fa-circle-exclamation big" style="color:var(--danger);"></i>
        <h3><?php echo t('pm.delete_titre'); ?></h3>
        <p id="confirmText"><?php echo t('pm.delete_defaut'); ?></p>
        <div class="modal-actions">
            <button class="modal-btn-cancel" id="confirmCancel"><?php echo t('pm.btn_annuler'); ?></button>
            <button class="modal-btn-danger" id="confirmOk"><?php echo t('pm.delete_btn'); ?></button>
        </div>
    </div>
</div>

<div id="bulkBar" style="display:none;">
    <span id="bulkCount">0 <?php echo t('pm.bulk_machine_selectionnee'); ?></span>
    <button type="button" class="pm-btn primary" id="btnBulkAssign"><i class="fa-solid fa-tag"></i> <?php echo t('pm.bulk_btn_assigner'); ?></button>
    <button type="button" class="pm-btn ghost" id="btnBulkClear"><?php echo t('pm.bulk_btn_desselectionner'); ?></button>
</div>

<div class="modal-bg" id="bulkModal">
    <div class="modal-box rename" style="width:420px;">
        <h3><?php echo t('pm.bulk_titre'); ?></h3>
        <p id="bulkModalHint"><?php echo t('pm.bulk_hint_defaut'); ?></p>
        <div class="type-grid" id="bulkTypeGrid" style="margin-bottom:14px;">
            <?php foreach ($TYPES_EQUIPEMENT as $t): ?>
            <div class="type-chip" data-value="<?php echo htmlspecialchars($t); ?>"><i class="fa-solid <?php echo htmlspecialchars($TYPES_ICONS[$t]); ?>"></i><span><?php echo htmlspecialchars($t); ?></span></div>
            <?php endforeach; ?>
            <?php foreach ($custom_types_used as $t): ?>
            <div class="type-chip" data-value="<?php echo htmlspecialchars($t); ?>"><i class="fa-solid fa-tag"></i><span><?php echo htmlspecialchars($t); ?></span></div>
            <?php endforeach; ?>
            <div class="type-chip" data-value="autre"><i class="fa-solid fa-ellipsis"></i><span><?php echo t('pm.form_autre'); ?></span></div>
        </div>
        <input type="text" id="bulkTypeNew" style="display:none; margin-top:10px;" placeholder="<?php echo htmlspecialchars(t('pm.form_placeholder_type')); ?>">
        <div class="modal-actions">
            <button class="modal-btn-cancel" id="bulkCancel"><?php echo t('pm.btn_annuler'); ?></button>
            <button class="modal-btn-ok" id="bulkOk"><?php echo t('pm.bulk_btn_appliquer'); ?></button>
        </div>
    </div>
</div>

<?php endif; ?>

<script>
const SU_CSRF = <?php echo json_encode(csrf_token()); ?>;
const SU_CATEGORIES_VISUELLES = <?php echo json_encode($CATEGORIES_VISUELLES); ?>;
// Généré automatiquement à partir de toutes les clés 'pm.*' des fichiers lang/ (voir i18n.php) —
// évite de lister ~200 clés à la main et reste synchronisé si de nouvelles clés pm.* sont ajoutées.
const I18N_PM = <?php
    $__pm_i18n = array_filter($GLOBALS['__gmao_i18n'], function ($k) { return strpos($k, 'pm.') === 0; }, ARRAY_FILTER_USE_KEY);
    $__pm_i18n_short = [];
    foreach ($__pm_i18n as $k => $v) { $__pm_i18n_short[substr($k, 3)] = $v; }
    echo json_encode($__pm_i18n_short);
?>;
// Statuts/types canoniques de bon d'intervention (maint.*), réutilisés tels quels pour l'affichage
// dans l'onglet "Historique BI" de la Fiche de Vie — voir composant_rapport.php pour le même pattern.
const I18N_PM_MAINT = {
    type_preventif: <?php echo json_encode(t('maint.type_preventif')); ?>,
    type_chantier: <?php echo json_encode(t('maint.type_chantier')); ?>,
    type_curatif: <?php echo json_encode(t('maint.type_curatif')); ?>,
    lib_afaire: <?php echo json_encode(t('maint.lib_afaire')); ?>,
    lib_encours: <?php echo json_encode(t('maint.lib_encours')); ?>,
    lib_termine: <?php echo json_encode(t('maint.lib_termine')); ?>,
    lib_refuse: <?php echo json_encode(t('maint.lib_refuse')); ?>,
};

// --- SIDEBAR (MENU MAÎTRE) ---
function openNav(e) { if (e) e.stopPropagation(); document.getElementById("mySidebar").style.width = "280px"; }
function closeNav() { document.getElementById("mySidebar").style.width = "0"; }
function checkCloseSidebar(event) { if (document.getElementById("mySidebar").style.width === "280px" && !document.getElementById("mySidebar").contains(event.target)) closeNav(); }

// --- OUTILS ---
var DIACRITICS_RE = new RegExp('[' + String.fromCharCode(0x0300) + '-' + String.fromCharCode(0x036f) + ']', 'g');
function normalize(s) { return (s || '').toString().toLowerCase().normalize('NFD').replace(DIACRITICS_RE, ''); }
function showToast(msg, isError) {
    const t = document.getElementById('save-toast');
    document.getElementById('save-toast-text').textContent = msg;
    t.style.background = isError ? 'var(--danger)' : 'var(--primary)';
    t.classList.add('show');
    clearTimeout(window._toastTimer);
    window._toastTimer = setTimeout(() => t.classList.remove('show'), isError ? 3200 : 2200);
}

// --- MODALE DE CONFIRMATION GÉNÉRIQUE (FICHE DE VIE) ---
const fvConfirmModal = document.getElementById('fvConfirmModal');
let fvConfirmAction = null;
let fvConfirmCancelAction = null;
function fvConfirm(text, onConfirm, onCancel) {
    document.getElementById('fvConfirmText').textContent = text;
    fvConfirmAction = onConfirm;
    fvConfirmCancelAction = onCancel || null;
    fvConfirmModal.style.display = 'block';
}
document.getElementById('fvConfirmCancel').addEventListener('click', () => {
    fvConfirmModal.style.display = 'none';
    if (fvConfirmCancelAction) fvConfirmCancelAction();
    fvConfirmCancelAction = null;
});
document.getElementById('fvConfirmOk').addEventListener('click', () => {
    fvConfirmModal.style.display = 'none';
    fvConfirmCancelAction = null;
    if (fvConfirmAction) fvConfirmAction();
});

// --- SCHÉMA USINE C ---
const schemaOverlay = document.getElementById('schemaOverlay');
const schemaBody = document.getElementById('schemaBody');
const schemaTitle = document.getElementById('schemaTitle');
const schemaBack = document.getElementById('schemaBack');
const schemaIsAdmin = <?php echo $is_admin ? 'true' : 'false'; ?>;
const SU_TITRES = {
    salle_tri: <?php echo json_encode(GMAO_EST_DEMO ? t('pm.schema_lignes_prod') : t('pm.schema_salle_tri')); ?>,
    conditionnement: <?php echo json_encode(t('pm.schema_conditionnement')); ?>
};

const btnEditToggle = document.getElementById('btnEditToggle');
const btnAddZone = document.getElementById('btnAddZone');
const btnCategoriesSchema = document.getElementById('btnCategoriesSchema');
const suGrowWrap = document.getElementById('suGrowWrap');
const btnGrowCanvas = document.getElementById('btnGrowCanvas');
const suGrowPad = document.getElementById('suGrowPad');
let suGrowMode = 'grow';
const suPropsPanel = document.getElementById('suPropsPanel');
const suZoomControls = document.getElementById('suZoomControls');

const SU_SHAPE_CSS = {
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
function suShapeCss(shape) { return SU_SHAPE_CSS[shape] || SU_SHAPE_CSS.rect; }

// Miroir JS de su_effect_css() (schema_usine.php), pour un aperçu instantané dans l'éditeur
// sans attendre le rechargement du plan depuis le serveur.
function suEffectStyle(effect, fill, border) {
    switch (effect) {
        case 'shadow': return { filter: 'drop-shadow(3px 4px 6px rgba(0,0,0,.4))', boxShadow: '' };
        case 'bevel': return { filter: '', boxShadow: 'inset -3px -3px 6px rgba(0,0,0,.3), inset 3px 3px 6px rgba(255,255,255,.6)' };
        case 'inset': return { filter: '', boxShadow: 'inset 0 0 10px rgba(0,0,0,.5), inset 0 0 3px rgba(0,0,0,.55)' };
        case 'glow': {
            const glow = (border && border !== 'none') ? border : (fill || '#3b82f6');
            return { filter: 'drop-shadow(0 0 5px ' + glow + ') drop-shadow(0 0 10px ' + glow + ')', boxShadow: '' };
        }
        default: return { filter: '', boxShadow: '' };
    }
}
const suEffectRowEl = document.getElementById('suEffectRow');
if (suEffectRowEl) suEffectRowEl.addEventListener('click', (e) => {
    const tile = e.target.closest('.su-effect-tile');
    if (!tile) return;
    document.getElementById('sp-effect').value = tile.dataset.effect;
    document.querySelectorAll('.su-effect-tile').forEach(t => t.classList.toggle('active', t === tile));
    suApplyPropsToSelected();
});
const suGradientDirRowEl = document.getElementById('suGradientDirRow');
if (suGradientDirRowEl) suGradientDirRowEl.addEventListener('click', (e) => {
    const tile = e.target.closest('.su-dir-tile');
    if (!tile) return;
    document.getElementById('sp-gradient-angle').value = tile.dataset.angle;
    document.querySelectorAll('.su-dir-tile').forEach(t => t.classList.toggle('active', t === tile));
    suApplyPropsToSelected();
});

const SU_SHAPE_FAMILIES = [
    { title: I18N_PM.shapefam_texte, shapes: [
        ['text_only', I18N_PM.shape_text_only],
    ] },
    { title: I18N_PM.shapefam_industriel, shapes: [
        ['convoyeur_rouleaux', I18N_PM.shape_convoyeur_rouleaux],
        ['convoyeur_rouleaux_arc', I18N_PM.shape_convoyeur_rouleaux_arc],
        ['tapis_chevron', I18N_PM.shape_tapis_chevron],
        ['stadium', I18N_PM.shape_stadium_convoyeur],
        ['vis_sans_fin', I18N_PM.shape_vis_sans_fin],
        ['robot_bras', I18N_PM.shape_robot_bras],
    ] },
    { title: I18N_PM.shapefam_rectangles, shapes: [
        ['rect', I18N_PM.shape_rect], ['roundRect', I18N_PM.shape_roundrect], ['bevel_rect', I18N_PM.shape_bevel_rect],
        ['rect_snip_1', I18N_PM.shape_rect_snip_1], ['rect_snip_2_same', I18N_PM.shape_rect_snip_2_same], ['rect_snip_diag', I18N_PM.shape_rect_snip_diag], ['rect_round_1', I18N_PM.shape_rect_round_1],
        ['parallelogram', I18N_PM.shape_parallelogram], ['trapezoid', I18N_PM.shape_trapezoid], ['trapezoid_inv', I18N_PM.shape_trapezoid_inv],
        ['diamond', I18N_PM.shape_diamond], ['l_shape', I18N_PM.shape_l_shape], ['t_shape', I18N_PM.shape_t_shape],
    ] },
    { title: I18N_PM.shapefam_cercles, shapes: [
        ['ellipse', I18N_PM.shape_ellipse], ['semicircle', I18N_PM.shape_semicircle], ['quarter_circle', I18N_PM.shape_quarter_circle],
        ['elbow', I18N_PM.shape_elbow],
    ] },
    { title: I18N_PM.shapefam_triangles, shapes: [
        ['triangle', I18N_PM.shape_triangle], ['triangle_down', I18N_PM.shape_triangle_down], ['triangle_left', I18N_PM.shape_triangle_left], ['triangle_right', I18N_PM.shape_triangle_right],
        ['right_triangle', I18N_PM.shape_right_triangle], ['rt_tl', I18N_PM.shape_rt_tl], ['rt_tr', I18N_PM.shape_rt_tr], ['rt_br', I18N_PM.shape_rt_br],
    ] },
    { title: I18N_PM.shapefam_polygones, shapes: [
        ['pentagon', I18N_PM.shape_pentagon], ['hexagon', I18N_PM.shape_hexagon], ['hexagon_v', I18N_PM.shape_hexagon_v],
        ['heptagon', I18N_PM.shape_heptagon], ['octagon', I18N_PM.shape_octagon], ['decagon', I18N_PM.shape_decagon], ['dodecagon', I18N_PM.shape_dodecagon],
    ] },
    { title: I18N_PM.shapefam_etoiles, shapes: [
        ['star', I18N_PM.shape_star], ['cross', I18N_PM.shape_cross], ['shield', I18N_PM.shape_shield], ['bowtie', I18N_PM.shape_bowtie],
    ] },
    { title: I18N_PM.shapefam_fleches, shapes: [
        ['arrow_right', I18N_PM.shape_arrow_right], ['arrow_left', I18N_PM.shape_arrow_left], ['arrow_up', I18N_PM.shape_arrow_up], ['arrow_down', I18N_PM.shape_arrow_down],
        ['double_arrow_h', I18N_PM.shape_double_arrow_h], ['double_arrow_v', I18N_PM.shape_double_arrow_v], ['arrow_cross', I18N_PM.shape_arrow_cross],
        ['chevron_right', I18N_PM.shape_chevron_right], ['chevron_left', I18N_PM.shape_chevron_left],
        ['plate_left', I18N_PM.shape_plate_left], ['plate_right', I18N_PM.shape_plate_right],
        ['ribbon_right', I18N_PM.shape_ribbon_right], ['ribbon_left', I18N_PM.shape_ribbon_left],
    ] },
    { title: I18N_PM.shapefam_equation, shapes: [
        ['minus_sign', I18N_PM.shape_minus_sign], ['multiply_x', I18N_PM.shape_multiply_x],
    ] },
    { title: I18N_PM.shapefam_organigramme, shapes: [
        ['stadium', I18N_PM.shape_stadium_terminateur], ['document', I18N_PM.shape_document], ['manual_input', I18N_PM.shape_manual_input],
        ['off_page_connector', I18N_PM.shape_off_page_connector], ['delay', I18N_PM.shape_delay], ['punched_tape', I18N_PM.shape_punched_tape],
    ] },
];
const SU_SHAPE_LABELS = {};
SU_SHAPE_FAMILIES.forEach(f => f.shapes.forEach(([key, label]) => { SU_SHAPE_LABELS[key] = label; }));

function suUpdateShapeTrigger() {
    const key = document.getElementById('sp-shape').value || 'rect';
    const css = suShapeCss(key);
    const preview = document.getElementById('shapePickerPreview');
    if (preview) {
        preview.style.borderRadius = css.radius; preview.style.clipPath = css.clip;
        preview.style.background = key === 'text_only' ? 'transparent' : '';
        preview.style.borderStyle = key === 'text_only' ? 'dashed' : '';
        preview.textContent = key === 'text_only' ? 'T' : '';
    }
    const label = document.getElementById('shapePickerLabel');
    if (label) label.textContent = SU_SHAPE_LABELS[key] || key;
    document.querySelectorAll('.shape-picker-tile').forEach(t => t.classList.toggle('active', t.dataset.shape === key));
}

function suRenderShapePicker() {
    const grid = document.getElementById('shapePickerGrid');
    if (!grid || grid.childElementCount) return; // grille déjà construite une fois, réutilisée ensuite
    SU_SHAPE_FAMILIES.forEach(family => {
        const section = document.createElement('div');
        section.className = 'shape-picker-family';
        const heading = document.createElement('div');
        heading.className = 'section-label';
        heading.innerHTML = '<span>' + family.title + '</span><span class="rule"></span>';
        section.appendChild(heading);
        const row = document.createElement('div');
        row.className = 'shape-picker-row';
        family.shapes.forEach(([key, label]) => {
            const css = suShapeCss(key);
            const tile = document.createElement('button');
            tile.type = 'button';
            tile.className = 'shape-picker-tile';
            tile.dataset.shape = key;
            const previewStyle = key === 'text_only'
                ? 'background:transparent; border-style:dashed;'
                : ('border-radius:' + css.radius + '; clip-path:' + css.clip + ';');
            const previewContent = key === 'text_only' ? 'T' : '';
            tile.innerHTML = '<span class="su-shape-preview" style="' + previewStyle + '">' + previewContent + '</span><span>' + label + '</span>';
            tile.addEventListener('click', () => {
                document.getElementById('sp-shape').value = key;
                suUpdateShapeTrigger();
                suApplyPropsToSelected();
                document.getElementById('shapePickerModal').style.display = 'none';
            });
            row.appendChild(tile);
        });
        section.appendChild(row);
        grid.appendChild(section);
    });
}
const btnShapePicker = document.getElementById('btnShapePicker');
if (btnShapePicker) btnShapePicker.addEventListener('click', () => {
    suRenderShapePicker();
    suUpdateShapeTrigger();
    document.getElementById('shapePickerModal').style.display = 'block';
});
const shapePickerClose = document.getElementById('shapePickerClose');
if (shapePickerClose) shapePickerClose.addEventListener('click', () => document.getElementById('shapePickerModal').style.display = 'none');
const shapePickerModalEl = document.getElementById('shapePickerModal');
if (shapePickerModalEl) shapePickerModalEl.addEventListener('click', (e) => { if (e.target === shapePickerModalEl) shapePickerModalEl.style.display = 'none'; });

let suEditMode = false;
let suSelectedZoneId = null;
let suCurrentVue = 'liste';
let suDrag = null;
let suZoom = 1;
// Sélection multiple (rectangle de sélection sur le vide, puis déplacement groupé) — indépendante
// de la sélection simple (suSelectedZoneId) qui pilote le panneau Propriétés/les poignées, non
// pertinentes pour un groupe. Voir suStartMarquee/suFinishMarquee plus bas.
let suMultiSelected = new Set();
function suClearMultiSelection() {
    document.querySelectorAll('.su-zone-multiselect').forEach(el => el.classList.remove('su-zone-multiselect'));
    suMultiSelected.clear();
}
function suApplyMultiSelectVisual() {
    document.querySelectorAll('.su-zone-multiselect').forEach(el => el.classList.remove('su-zone-multiselect'));
    suMultiSelected.forEach(id => {
        const el = document.querySelector('.su-zone[data-zone-id="' + id + '"]');
        if (el) el.classList.add('su-zone-multiselect');
    });
}

function suApplyZoom() {
    const canvas = document.getElementById('suCanvas');
    if (!canvas) return;
    canvas.style.transform = 'scale(' + suZoom + ')';
    canvas.style.transformOrigin = 'top left';
    const resetBtn = document.getElementById('suZoomReset');
    if (resetBtn) resetBtn.textContent = Math.round(suZoom * 100) + '%';
}
document.getElementById('suZoomIn').addEventListener('click', () => { suZoom = Math.min(3, Math.round((suZoom + 0.25) * 100) / 100); suApplyZoom(); });
document.getElementById('suZoomOut').addEventListener('click', () => { suZoom = Math.max(0.5, Math.round((suZoom - 0.25) * 100) / 100); suApplyZoom(); });
document.getElementById('suZoomReset').addEventListener('click', () => { suZoom = 1; suApplyZoom(); });

// Zoom à la molette, centré sur le pointeur (le point survolé reste sous la souris) : permet de
// naviguer dans le plan rien qu'en zoomant, sans avoir à cliquer-glisser. Remplace le défilement
// natif de la molette dans le plan (transform-origin:top left, cohérent avec le calcul ci-dessous).
schemaBody.addEventListener('wheel', (e) => {
    const canvas = document.getElementById('suCanvas');
    if (!canvas) return;
    e.preventDefault();
    const rect = schemaBody.getBoundingClientRect();
    const mouseX = e.clientX - rect.left;
    const mouseY = e.clientY - rect.top;
    const oldZoom = suZoom;
    const step = Math.min(0.3, Math.abs(e.deltaY) / 300) || 0.1;
    let newZoom = e.deltaY < 0 ? oldZoom + step : oldZoom - step;
    newZoom = Math.max(0.5, Math.min(3, Math.round(newZoom * 100) / 100));
    if (newZoom === oldZoom) return;
    const contentX = schemaBody.scrollLeft + mouseX;
    const contentY = schemaBody.scrollTop + mouseY;
    suZoom = newZoom;
    suApplyZoom();
    schemaBody.scrollLeft = contentX * (newZoom / oldZoom) - mouseX;
    schemaBody.scrollTop = contentY * (newZoom / oldZoom) - mouseY;
}, { passive: false });

async function loadSchemaVue(vue) {
    // Ne réinitialise le zoom que lors d'un vrai changement de vue — un simple rafraîchissement du
    // même schéma (après ajout/suppression/réorganisation d'une forme) doit garder le zoom en cours,
    // sinon un plan agrandi repasse brutalement à 100% et déborde de l'écran à chaque petite action.
    if (vue !== suCurrentVue) suZoom = 1;
    suCurrentVue = vue;
    suDeselectZone();
    suClearMultiSelection();
    suResetPropsPanelPosition();
    schemaBody.innerHTML = '<p class="fv-empty"><i class="fa-solid fa-spinner fa-spin"></i> Chargement...</p>';
    schemaBack.style.display = (vue === 'liste') ? 'none' : 'inline-flex';
    schemaTitle.innerHTML = '<i class="fa-solid fa-diagram-project" style="color:var(--accent); margin-right:8px;"></i>' + (SU_TITRES[vue] || I18N_PM.schema_titre_defaut);

    const isSchemaView = (vue === 'salle_tri' || vue === 'conditionnement');
    if (schemaIsAdmin) {
        [btnAddZone, btnCategoriesSchema, btnEditToggle].forEach(b => { if (b) b.style.display = isSchemaView ? 'inline-flex' : 'none'; });
        if (suGrowWrap) suGrowWrap.style.display = isSchemaView ? 'inline-flex' : 'none';
        if (suGrowPad) suGrowPad.classList.remove('show');
    }
    if (suZoomControls) suZoomControls.classList.toggle('show', isSchemaView);
    if (!isSchemaView && suEditMode) suSetEditMode(false);

    try {
        const res = await fetch('schema_usine.php?vue=' + encodeURIComponent(vue));
        schemaBody.innerHTML = await res.text();
        const canvas = document.getElementById('suCanvas');
        if (canvas) canvas.classList.toggle('edit-mode', suEditMode);
        suApplyZoom();
    } catch (e) {
        schemaBody.innerHTML = `<p class="fv-empty" style="color:var(--danger);">${I18N_PM.schema_erreur_chargement}</p>`;
    }
}

document.getElementById('btnSchemaUsine').addEventListener('click', () => {
    schemaOverlay.classList.add('show');
    loadSchemaVue('liste');
});
document.getElementById('schemaClose').addEventListener('click', () => schemaOverlay.classList.remove('show'));
schemaOverlay.addEventListener('click', (e) => { if (e.target === schemaOverlay) schemaOverlay.classList.remove('show'); });
schemaBack.addEventListener('click', () => loadSchemaVue('liste'));

schemaBody.addEventListener('click', (e) => {
    const carte = e.target.closest('[data-vue]');
    if (carte) { loadSchemaVue(carte.dataset.vue); return; }
    if (suEditMode) {
        // Clic sur le vide du plan (pas sur une forme, ni sur une poignée) : désélectionne la forme
        // en cours (sinon elle reste sélectionnée indéfiniment, il fallait cliquer sur une autre
        // forme pour "remplacer" la sélection — bug remonté par David). Ne referme jamais le
        // panneau Propriétés tout seul, volontairement (voir suClearSelectionVisual).
        if (!e.target.closest('.su-zone, .su-resize-handle, .su-rotate-handle, .su-rotate-line, .su-text-rotate-handle') && suSelectedZoneId) {
            suClearSelectionVisual();
        }
        return;
    }
    const zoneEl = e.target.closest('.su-zone.su-zone-click');
    if (zoneEl) { openFicheVie(zoneEl.dataset.zoneId); }
});

// --- MODE ÉDITION DU PLAN (admin) ---
function suSetEditMode(on) {
    suEditMode = !!(on && schemaIsAdmin);
    if (btnEditToggle) {
        btnEditToggle.classList.toggle('active', suEditMode);
        btnEditToggle.innerHTML = suEditMode ? `<i class="fa-solid fa-check"></i> ${I18N_PM.schema_terminer}` : `<i class="fa-solid fa-pen"></i> ${I18N_PM.schema_modifier_plan}`;
    }
    const canvas = document.getElementById('suCanvas');
    if (canvas) canvas.classList.toggle('edit-mode', suEditMode);
    if (!suEditMode) { suDeselectZone(); suClearMultiSelection(); }
}
if (btnEditToggle) btnEditToggle.addEventListener('click', () => suSetEditMode(!suEditMode));

function suTextColor(hex) {
    hex = (hex || '#ffffff').replace('#', '');
    if (hex.length === 3) hex = hex.split('').map(c => c + c).join('');
    const r = parseInt(hex.substr(0, 2), 16), g = parseInt(hex.substr(2, 2), 16), b = parseInt(hex.substr(4, 2), 16);
    return (0.299 * r + 0.587 * g + 0.114 * b) < 150 ? '#ffffff' : '#2c3e50';
}
function suLabelSpan(zoneEl) { return zoneEl.querySelector('.su-zone-label'); }
// Repositionne le span de texte selon son décalage libre (data-text-offset-x/y, en % de la forme)
// et sa rotation propre — centralisé ici pour rester identique que ce soit après un glisser du
// texte lui-même ou après une rotation de la forme (qui doit conserver le décalage déjà posé).
function suApplyTextOffset(zoneEl) {
    const span = suLabelSpan(zoneEl);
    if (!span) return;
    const offX = parseFloat(zoneEl.dataset.textOffsetX) || 0;
    const offY = parseFloat(zoneEl.dataset.textOffsetY) || 0;
    span.style.left = 'calc(50% + ' + offX + '%)';
    span.style.top = 'calc(50% + ' + offY + '%)';
    const rotation = parseFloat(zoneEl.dataset.rotation) || 0;
    const textRotation = parseFloat(zoneEl.dataset.textRotation) || 0;
    const effTextRot = textRotation - rotation;
    span.style.transform = 'translate(-50%,-50%)' + (effTextRot != 0 ? ' rotate(' + effTextRot + 'deg)' : '');
}

function suSelectZone(zoneEl, showProps) {
    document.querySelectorAll('.su-zone-selected').forEach(el => el.classList.remove('su-zone-selected'));
    zoneEl.classList.add('su-zone-selected');
    suSelectedZoneId = zoneEl.dataset.zoneId;
    suRenderHandles(zoneEl);
    if (!suPropsPanel) return;
    // Le simple clic (gauche) sélectionne la forme pour la déplacer/redimensionner sans ouvrir le
    // panneau de propriétés — trop intrusif quand on veut juste réarranger le plan. Le panneau ne
    // s'ouvre que sur clic droit (voir contextmenu plus bas), ou automatiquement après un ajout de
    // forme / un envoi premier-plan-arrière-plan, où l'utilisateur s'attend à l'y retrouver ouvert.
    // Important : les champs sont TOUJOURS repeuplés ci-dessous (même si le panneau reste caché),
    // pour qu'ils ne gardent jamais les valeurs d'une forme précédente — sinon un clic sur une
    // tuile Effet/Dégradé juste après un simple clic de sélection réenregistrerait ces anciennes
    // valeurs sur la nouvelle forme sélectionnée (bug déjà rencontré).
    if (showProps) {
        // Ne recalcule la position par défaut que lors d'une véritable ouverture (panneau
        // jusque-là caché) — pas à chaque changement de forme sélectionnée pendant qu'il reste
        // ouvert, sinon il saute de position à chaque clic droit sur une autre machine.
        const wasHidden = !suPropsPanel.classList.contains('show');
        suPropsPanel.classList.add('show');
        if (wasHidden) suPositionPropsPanelDefault();
    }
    const span = suLabelSpan(zoneEl);
    document.getElementById('sp-label').value = span ? span.textContent.trim() : '';
    document.getElementById('sp-fill').value = (zoneEl.dataset.fill || '#ffffff').toLowerCase();
    const isGradient = zoneEl.dataset.gradient === '1';
    document.getElementById('sp-gradient').checked = isGradient;
    document.getElementById('sp-fill2').value = (zoneEl.dataset.fill2 || zoneEl.dataset.fill || '#ffffff').toLowerCase();
    document.getElementById('spFill2Wrap').style.display = isGradient ? '' : 'none';
    const gradAngle = zoneEl.dataset.gradientAngle || '135';
    document.getElementById('sp-gradient-angle').value = gradAngle;
    document.getElementById('spGradientDirWrap').style.display = isGradient ? '' : 'none';
    document.querySelectorAll('.su-dir-tile').forEach(t => t.classList.toggle('active', t.dataset.angle === gradAngle));
    const hasBorder = zoneEl.dataset.border && zoneEl.dataset.border !== 'none';
    document.getElementById('sp-border').value = (hasBorder ? zoneEl.dataset.border : '#95a5a6').toLowerCase();
    document.getElementById('sp-border-none').checked = !hasBorder;
    const effect = zoneEl.dataset.effect || 'none';
    document.getElementById('sp-effect').value = effect;
    document.querySelectorAll('.su-effect-tile').forEach(t => t.classList.toggle('active', t.dataset.effect === effect));
    document.getElementById('sp-shape').value = zoneEl.dataset.shape || 'rect';
    suUpdateShapeTrigger();
    document.getElementById('sp-width').value = Math.round((parseFloat(zoneEl.style.width) || 0) * 100) / 100;
    document.getElementById('sp-height').value = Math.round((parseFloat(zoneEl.style.height) || 0) * 100) / 100;
    document.getElementById('sp-rotation').value = zoneEl.dataset.rotation || 0;
    document.getElementById('sp-text-rotation').value = zoneEl.dataset.textRotation || 0;
    document.getElementById('sp-font-size').value = zoneEl.dataset.fontSize || '';
    const hasTextColor = !!zoneEl.dataset.textColor;
    document.getElementById('sp-text-color').value = (hasTextColor ? zoneEl.dataset.textColor : suTextColor(zoneEl.dataset.fill || '#ffffff')).toLowerCase();
    document.getElementById('sp-text-color-auto').checked = !hasTextColor;
    document.getElementById('sp-text-color').disabled = !hasTextColor;
    document.getElementById('sp-clickable').checked = zoneEl.dataset.clickable === '1';
    document.getElementById('sp-categorie').value = zoneEl.dataset.categorieVisuelleId || '';
    suUpdateAdvancedForCategorie();
}
function suSetAdvancedVisible(visible) {
    const wrap = document.getElementById('spAdvancedSettings');
    if (wrap) wrap.style.display = visible ? 'flex' : 'none';
    const lbl = document.getElementById('btnToggleAdvancedLabel');
    if (lbl) lbl.textContent = visible ? I18N_PM.props_masquer_avances : I18N_PM.props_afficher_avances;
}
function suUpdateAdvancedForCategorie() {
    const catEl = document.getElementById('sp-categorie');
    const hasCategorie = !!(catEl && catEl.value);
    const toggleRow = document.getElementById('spAdvancedToggleRow');
    if (toggleRow) toggleRow.style.display = hasCategorie ? '' : 'none';
    suSetAdvancedVisible(!hasCategorie);
}
// N'enlève que le contour/les poignées de sélection, sans toucher au panneau Propriétés — utilisé
// pour le clic sur le vide du plan (voir plus bas), qui doit désélectionner la forme sans jamais
// refermer le panneau tout seul (demande explicite de David, faite une première fois pour éviter
// que le panneau se ferme automatiquement).
function suClearSelectionVisual() {
    document.querySelectorAll('.su-zone-selected').forEach(el => el.classList.remove('su-zone-selected'));
    document.querySelectorAll('.su-resize-handle, .su-rotate-handle, .su-rotate-line, .su-text-rotate-handle').forEach(el => el.remove());
    suSelectedZoneId = null;
}
function suDeselectZone() {
    suClearSelectionVisual();
    if (suPropsPanel) suPropsPanel.classList.remove('show');
}
// Les 8 poignées (4 coins + 4 arêtes) et la poignée de rotation doivent suivre la rotation propre
// de la forme — sinon, une fois pivotée à 90°, elles restent plaquées sur son rectangle d'origine
// non pivoté (bug remonté par David). On travaille en pixels réels (pas en %, dont l'échelle X/Y
// diffère dès que le canevas n'est pas carré) : centre + demi-dimensions en px, un point local
// tourné de l'angle de la forme donne sa position réelle sur le canevas.
function suRotatedHandlePoint(zoneEl, canvasRect, localDxPx, localDyPx) {
    const left = parseFloat(zoneEl.style.left), top = parseFloat(zoneEl.style.top);
    const w = parseFloat(zoneEl.style.width), h = parseFloat(zoneEl.style.height);
    const rotation = parseFloat(zoneEl.dataset.rotation) || 0;
    const rad = rotation * Math.PI / 180;
    const cos = Math.cos(rad), sin = Math.sin(rad);
    const cw = canvasRect.width, ch = canvasRect.height;
    const centerXpx = (left + w / 2) / 100 * cw;
    const centerYpx = (top + h / 2) / 100 * ch;
    const rx = localDxPx * cos - localDyPx * sin;
    const ry = localDxPx * sin + localDyPx * cos;
    return { xPct: (centerXpx + rx) / cw * 100, yPct: (centerYpx + ry) / ch * 100 };
}
const SU_HANDLE_DEFS = [['nw', -1, -1], ['n', 0, -1], ['ne', 1, -1], ['w', -1, 0], ['e', 1, 0], ['sw', -1, 1], ['s', 0, 1], ['se', 1, 1]];
function suRenderHandles(zoneEl) {
    document.querySelectorAll('.su-resize-handle, .su-rotate-handle, .su-rotate-line, .su-text-rotate-handle').forEach(el => el.remove());
    const canvas = document.getElementById('suCanvas');
    if (!canvas) return;
    const canvasRect = canvas.getBoundingClientRect();
    const w = parseFloat(zoneEl.style.width), h = parseFloat(zoneEl.style.height);
    const halfWpx = (w / 2) / 100 * canvasRect.width, halfHpx = (h / 2) / 100 * canvasRect.height;
    SU_HANDLE_DEFS.forEach(([corner, sx, sy]) => {
        const p = suRotatedHandlePoint(zoneEl, canvasRect, sx * halfWpx, sy * halfHpx);
        const handle = document.createElement('div');
        handle.className = 'su-resize-handle';
        handle.dataset.corner = corner;
        handle.dataset.zoneId = zoneEl.dataset.zoneId;
        handle.style.left = p.xPct + '%';
        handle.style.top = p.yPct + '%';
        canvas.appendChild(handle);
    });

    // Poignée de rotation, reliée par un petit trait au centre-haut LOCAL de la forme (tourne donc
    // avec elle, contrairement aux poignées de redimensionnement qui ont chacune une position figée
    // par rapport au centre — la poignée de rotation, elle, doit rester "au-dessus" de la forme
    // quelle que soit son orientation actuelle).
    const offsetPx = 28;
    const topP = suRotatedHandlePoint(zoneEl, canvasRect, 0, -halfHpx);
    const handleP = suRotatedHandlePoint(zoneEl, canvasRect, 0, -halfHpx - offsetPx);
    // Angle et longueur du trait calculés en pixels réels (pas en %, dont l'échelle X/Y diffère) :
    // le trait, vertical par défaut (pointe vers le bas, angle écran 90°), est tourné pour aller
    // de la poignée de rotation jusqu'au centre-haut LOCAL de la forme.
    const topPpx = { x: topP.xPct / 100 * canvasRect.width, y: topP.yPct / 100 * canvasRect.height };
    const handlePpx = { x: handleP.xPct / 100 * canvasRect.width, y: handleP.yPct / 100 * canvasRect.height };
    const lineLenPx = Math.hypot(topPpx.x - handlePpx.x, topPpx.y - handlePpx.y);
    const lineAngle = Math.atan2(topPpx.y - handlePpx.y, topPpx.x - handlePpx.x) * 180 / Math.PI - 90;

    const line = document.createElement('div');
    line.className = 'su-rotate-line';
    line.dataset.zoneId = zoneEl.dataset.zoneId;
    line.style.left = handleP.xPct + '%';
    line.style.top = handleP.yPct + '%';
    line.style.height = lineLenPx + 'px';
    line.style.transformOrigin = 'top center';
    line.style.transform = 'rotate(' + lineAngle + 'deg) translateX(-50%)';
    canvas.appendChild(line);

    const rotateHandle = document.createElement('div');
    rotateHandle.className = 'su-rotate-handle';
    rotateHandle.dataset.zoneId = zoneEl.dataset.zoneId;
    rotateHandle.title = 'Glisser pour pivoter (Maj = par 15°)';
    rotateHandle.style.left = handleP.xPct + '%';
    rotateHandle.style.top = handleP.yPct + '%';
    rotateHandle.innerHTML = '<i class="fa-solid fa-rotate"></i>';
    canvas.appendChild(rotateHandle);

    suRenderTextRotateHandle(zoneEl);
}
// Anneau de graduations (tous les 15°, plus marquées à 0/90/180/270) affiché pendant qu'on tire la
// poignée de rotation — repère visuel fixe (ne tourne pas avec la forme) pour "sentir" où sont les
// angles ronds, demandé par David qui ne savait jamais s'il était à 0°/10°/20°/30° en tournant à la
// souris. Couplé à un magnétisme doux (voir suSnapRotation) qui accroche ces mêmes angles.
function suClearRotationTicks() {
    document.querySelectorAll('.su-rotate-tick').forEach(el => el.remove());
}
function suRenderTicksAt(centerXpx, centerYpx, radiusPx) {
    suClearRotationTicks();
    const canvas = document.getElementById('suCanvas');
    if (!canvas) return;
    const canvasRect = canvas.getBoundingClientRect();
    for (let a = 0; a < 360; a += 15) {
        const rad = a * Math.PI / 180;
        const tx = centerXpx + Math.sin(rad) * radiusPx;
        const ty = centerYpx - Math.cos(rad) * radiusPx;
        const tick = document.createElement('div');
        tick.className = 'su-rotate-tick' + (a % 90 === 0 ? ' major' : '');
        tick.style.left = (tx / canvasRect.width * 100) + '%';
        tick.style.top = (ty / canvasRect.height * 100) + '%';
        canvas.appendChild(tick);
    }
}
function suRenderRotationTicks(zoneEl) {
    const canvas = document.getElementById('suCanvas');
    if (!canvas) return;
    const canvasRect = canvas.getBoundingClientRect();
    const left = parseFloat(zoneEl.style.left), top = parseFloat(zoneEl.style.top);
    const w = parseFloat(zoneEl.style.width), h = parseFloat(zoneEl.style.height);
    const centerXpx = (left + w / 2) / 100 * canvasRect.width;
    const centerYpx = (top + h / 2) / 100 * canvasRect.height;
    const halfHpx = (h / 2) / 100 * canvasRect.height;
    suRenderTicksAt(centerXpx, centerYpx, halfHpx + 28);
}
// Même anneau de graduations, mais centré sur le TEXTE (pas la forme) — utilisé pendant qu'on tire
// sa propre poignée de rotation.
function suRenderTextRotationTicks(zoneEl) {
    const canvas = document.getElementById('suCanvas');
    const span = suLabelSpan(zoneEl);
    if (!canvas || !span) return;
    const canvasRect = canvas.getBoundingClientRect();
    const sr = span.getBoundingClientRect();
    const cx = sr.left + sr.width / 2 - canvasRect.left;
    const cy = sr.top + sr.height / 2 - canvasRect.top;
    suRenderTicksAt(cx, cy, 20);
}
// Aimante doucement vers les angles cardinaux (0/90/180/270) même sans Maj (qui, elle, accroche
// tous les 15° comme avant) — assez de tolérance (3°) pour se poser pile sur 0° sans viser au pixel.
function suSnapRotation(angle, shiftKey) {
    if (shiftKey) return Math.round(angle / 15) * 15;
    const cardinals = [0, 90, -90, 180, -180];
    for (const c of cardinals) { if (Math.abs(angle - c) < 3) return c; }
    return angle;
}
// Poignée dédiée à la rotation du TEXTE seul, indépendante de la rotation de la forme (David : la
// forme et son texte doivent pouvoir être pivotés séparément — plus d'alignement automatique de
// l'un sur l'autre). Positionnée juste au-dessus du texte, tournée avec lui ; on repart de sa
// position réelle à l'écran (getBoundingClientRect) plutôt que de recalculer offset+rotation à la
// main, plus simple et toujours exact même avec un décalage de texte libre.
function suRenderTextRotateHandle(zoneEl) {
    document.querySelectorAll('.su-text-rotate-handle').forEach(el => el.remove());
    const canvas = document.getElementById('suCanvas');
    const span = suLabelSpan(zoneEl);
    if (!canvas || !span || !span.textContent.trim()) return;
    const canvasRect = canvas.getBoundingClientRect();
    const spanRect = span.getBoundingClientRect();
    const textRotation = parseFloat(zoneEl.dataset.textRotation) || 0;
    const rad = textRotation * Math.PI / 180;
    const offsetPx = 16;
    const cx = spanRect.left + spanRect.width / 2 - canvasRect.left;
    const cy = spanRect.top + spanRect.height / 2 - canvasRect.top;
    const hx = cx + Math.sin(rad) * offsetPx;
    const hy = cy - Math.cos(rad) * offsetPx;
    const handle = document.createElement('div');
    handle.className = 'su-text-rotate-handle';
    handle.dataset.zoneId = zoneEl.dataset.zoneId;
    handle.title = 'Glisser pour pivoter le texte (Maj = par 15°)';
    handle.style.left = (hx / canvasRect.width * 100) + '%';
    handle.style.top = (hy / canvasRect.height * 100) + '%';
    handle.innerHTML = '<i class="fa-solid fa-a"></i>';
    canvas.appendChild(handle);
}

// Navigation à la souris (cliquer-glisser sur le fond du plan, comme une carte) : indépendant
// du mode édition, ne se déclenche que sur le fond du canevas lui-même (jamais sur une forme),
// donc ne rentre jamais en conflit avec le déplacement/redimensionnement d'une forme ci-dessous.
let suPan = null;
schemaBody.addEventListener('pointerdown', (e) => {
    const canvas = document.getElementById('suCanvas');
    // Désactivé en mode édition : le clic-glisser sur le vide sert plutôt à la sélection
    // rectangulaire (voir plus bas) — la molette/barre de défilement restent disponibles pour naviguer.
    if (suEditMode) return;
    if (!canvas || e.target !== canvas || e.button !== 0) return;
    suPan = { startClientX: e.clientX, startClientY: e.clientY, startScrollLeft: schemaBody.scrollLeft, startScrollTop: schemaBody.scrollTop };
    canvas.classList.add('panning');
});
document.addEventListener('pointermove', (e) => {
    if (!suPan) return;
    schemaBody.scrollLeft = suPan.startScrollLeft - (e.clientX - suPan.startClientX);
    schemaBody.scrollTop = suPan.startScrollTop - (e.clientY - suPan.startClientY);
});
document.addEventListener('pointerup', () => {
    if (!suPan) return;
    suPan = null;
    const canvas = document.getElementById('suCanvas');
    if (canvas) canvas.classList.remove('panning');
});

schemaBody.addEventListener('pointerdown', (e) => {
    if (!suEditMode) return;
    const canvas = document.getElementById('suCanvas');
    if (!canvas) return;
    const canvasRect = canvas.getBoundingClientRect();

    const handle = e.target.closest('.su-resize-handle');
    if (handle) {
        e.preventDefault();
        const zoneEl = canvas.querySelector('.su-zone[data-zone-id="' + handle.dataset.zoneId + '"]');
        if (!zoneEl) return;
        const corner = handle.dataset.corner;
        const [, sx, sy] = SU_HANDLE_DEFS.find(([c]) => c === corner);
        const startPW = parseFloat(zoneEl.style.width), startPH = parseFloat(zoneEl.style.height);
        const rotation = parseFloat(zoneEl.dataset.rotation) || 0;
        const rad = rotation * Math.PI / 180;
        // Le coin/l'arête OPPOSÉ à celui qu'on tire reste fixe à l'écran pendant tout le
        // redimensionnement — son point (en pixels canevas) est calculé une seule fois ici, en
        // tenant compte de la rotation actuelle de la forme.
        const anchor = suRotatedHandlePoint(zoneEl, canvasRect, -sx * (startPW / 2 / 100 * canvasRect.width), -sy * (startPH / 2 / 100 * canvasRect.height));
        suDrag = {
            type: 'resize', corner, sx, sy, zoneEl, canvasRect, rotation,
            cos: Math.cos(rad), sin: Math.sin(rad),
            anchorPx: { x: anchor.xPct / 100 * canvasRect.width, y: anchor.yPct / 100 * canvasRect.height },
            startPX: parseFloat(zoneEl.style.left), startPY: parseFloat(zoneEl.style.top),
            startPW, startPH,
            startClientX: e.clientX, startClientY: e.clientY,
        };
        return;
    }
    const rotateHandle = e.target.closest('.su-rotate-handle');
    if (rotateHandle) {
        e.preventDefault();
        const zoneEl = canvas.querySelector('.su-zone[data-zone-id="' + rotateHandle.dataset.zoneId + '"]');
        if (!zoneEl) return;
        const zr = zoneEl.getBoundingClientRect();
        const centerX = zr.left + zr.width / 2, centerY = zr.top + zr.height / 2;
        const startRotation = parseFloat(zoneEl.dataset.rotation) || 0;
        const startAngle = Math.atan2(e.clientX - centerX, -(e.clientY - centerY)) * 180 / Math.PI;
        suDrag = { type: 'rotate', zoneEl, centerX, centerY, startRotation, startAngle };
        suRenderRotationTicks(zoneEl);
        return;
    }
    const textRotateHandle = e.target.closest('.su-text-rotate-handle');
    if (textRotateHandle) {
        e.preventDefault();
        const zoneEl = canvas.querySelector('.su-zone[data-zone-id="' + textRotateHandle.dataset.zoneId + '"]');
        const span = zoneEl ? suLabelSpan(zoneEl) : null;
        if (!zoneEl || !span) return;
        const sr = span.getBoundingClientRect();
        const centerX = sr.left + sr.width / 2, centerY = sr.top + sr.height / 2;
        const startTextRotation = parseFloat(zoneEl.dataset.textRotation) || 0;
        const startAngle = Math.atan2(e.clientX - centerX, -(e.clientY - centerY)) * 180 / Math.PI;
        suDrag = { type: 'textrotate', zoneEl, centerX, centerY, startTextRotation, startAngle };
        suRenderTextRotationTicks(zoneEl);
        return;
    }
    // Glisser le texte lui-même (uniquement une fois la forme déjà sélectionnée, pour ne jamais
    // capter le tout premier clic qui doit sélectionner/déplacer la forme entière) : le texte est
    // un enfant de la forme et hérite donc de sa rotation, il faut donc "dé-tourner" le déplacement
    // souris pour le convertir dans le repère local de la forme avant de l'ajouter au décalage.
    const labelEl = e.target.closest('.su-zone-label');
    if (labelEl) {
        const zEl = labelEl.closest('.su-zone');
        if (zEl && zEl.classList.contains('su-zone-selected')) {
            e.preventDefault();
            e.stopPropagation();
            labelEl.classList.add('dragging');
            suDrag = {
                type: 'textpos', zoneEl: zEl, labelEl,
                rotation: parseFloat(zEl.dataset.rotation) || 0,
                zoneWpx: (parseFloat(zEl.style.width) || 1) / 100 * canvasRect.width,
                zoneHpx: (parseFloat(zEl.style.height) || 1) / 100 * canvasRect.height,
                startOffX: parseFloat(zEl.dataset.textOffsetX) || 0,
                startOffY: parseFloat(zEl.dataset.textOffsetY) || 0,
                startClientX: e.clientX, startClientY: e.clientY,
            };
            return;
        }
    }
    const zoneEl = e.target.closest('.su-zone');
    if (zoneEl) {
        e.preventDefault();
        if (suMultiSelected.size > 1 && suMultiSelected.has(zoneEl.dataset.zoneId)) {
            // La forme cliquée fait partie d'une sélection multiple existante : on déplace tout le
            // groupe ensemble, chacune gardant sa position relative aux autres.
            const zones = Array.from(suMultiSelected)
                .map(id => canvas.querySelector('.su-zone[data-zone-id="' + id + '"]'))
                .filter(Boolean)
                .map(z => ({ zoneEl: z, startPX: parseFloat(z.style.left), startPY: parseFloat(z.style.top) }));
            suDrag = { type: 'move', zones, canvasRect, startClientX: e.clientX, startClientY: e.clientY };
        } else {
            suClearMultiSelection();
            suSelectZone(zoneEl, false);
            suDrag = {
                type: 'move', canvasRect, startClientX: e.clientX, startClientY: e.clientY,
                zones: [{ zoneEl, startPX: parseFloat(zoneEl.style.left), startPY: parseFloat(zoneEl.style.top) }],
            };
        }
        return;
    }
    // Clic-glisser sur le vide du plan : démarre un rectangle de sélection (voir pointermove) pour
    // choisir plusieurs formes d'un coup, à déplacer ensuite ensemble — demande de David.
    suDrag = { type: 'marquee', canvasRect, startClientX: e.clientX, startClientY: e.clientY };
});

// Clic droit = ouvrir le panneau de propriétés (le clic gauche ne fait que sélectionner/déplacer,
// pour ne pas ouvrir le panneau à chaque réarrangement du plan).
schemaBody.addEventListener('contextmenu', (e) => {
    if (!suEditMode) return;
    const zoneEl = e.target.closest('.su-zone');
    if (!zoneEl) return;
    e.preventDefault();
    suClearMultiSelection();
    suSelectZone(zoneEl, true);
});

document.addEventListener('pointermove', (e) => {
    if (!suDrag) return;
    if (suDrag.type === 'marquee') {
        const x1 = Math.min(e.clientX, suDrag.startClientX), x2 = Math.max(e.clientX, suDrag.startClientX);
        const y1 = Math.min(e.clientY, suDrag.startClientY), y2 = Math.max(e.clientY, suDrag.startClientY);
        let box = document.getElementById('suMarqueeBox');
        if (!box) {
            box = document.createElement('div');
            box.id = 'suMarqueeBox';
            box.className = 'su-marquee-box';
            document.getElementById('suCanvas').appendChild(box);
        }
        box.style.left = (x1 - suDrag.canvasRect.left) + 'px';
        box.style.top = (y1 - suDrag.canvasRect.top) + 'px';
        box.style.width = (x2 - x1) + 'px';
        box.style.height = (y2 - y1) + 'px';
        // Sélectionne (aperçu en direct) toute forme dont le rectangle recoupe le cadre tiré.
        const ids = new Set();
        document.querySelectorAll('.su-zone').forEach(z => {
            const zr = z.getBoundingClientRect();
            const intersects = zr.left < x2 && zr.right > x1 && zr.top < y2 && zr.bottom > y1;
            z.classList.toggle('su-zone-multiselect', intersects);
            if (intersects) ids.add(z.dataset.zoneId);
        });
        suDrag.previewIds = ids;
        return;
    }
    if (suDrag.type === 'rotate') {
        const zoneEl = suDrag.zoneEl;
        const angle = Math.atan2(e.clientX - suDrag.centerX, -(e.clientY - suDrag.centerY)) * 180 / Math.PI;
        let newRotation = suDrag.startRotation + (angle - suDrag.startAngle);
        newRotation = ((newRotation + 180) % 360 + 360) % 360 - 180; // normalise dans [-180, 180]
        newRotation = suSnapRotation(newRotation, e.shiftKey); // Maj = accroche tous les 15°, sinon aimant doux sur 0/90/180/270
        zoneEl.style.transform = 'rotate(' + newRotation + 'deg)';
        zoneEl.dataset.rotation = newRotation;
        if (suSelectedZoneId === zoneEl.dataset.zoneId) {
            const rotInput = document.getElementById('sp-rotation');
            if (rotInput) rotInput.value = Math.round(newRotation * 100) / 100;
        }
        suApplyTextOffset(zoneEl);
        suRenderHandles(zoneEl);
        return;
    }
    if (suDrag.type === 'textrotate') {
        const zoneEl = suDrag.zoneEl;
        const angle = Math.atan2(e.clientX - suDrag.centerX, -(e.clientY - suDrag.centerY)) * 180 / Math.PI;
        let newTextRotation = suDrag.startTextRotation + (angle - suDrag.startAngle);
        newTextRotation = ((newTextRotation + 180) % 360 + 360) % 360 - 180;
        newTextRotation = suSnapRotation(newTextRotation, e.shiftKey); // Maj = accroche tous les 15°, sinon aimant doux sur 0/90/180/270
        zoneEl.dataset.textRotation = newTextRotation;
        if (suSelectedZoneId === zoneEl.dataset.zoneId) {
            const trInput = document.getElementById('sp-text-rotation');
            if (trInput) trInput.value = Math.round(newTextRotation * 100) / 100;
        }
        suApplyTextOffset(zoneEl);
        suRenderTextRotateHandle(zoneEl);
        return;
    }
    if (suDrag.type === 'textpos') {
        const dxPx = e.clientX - suDrag.startClientX;
        const dyPx = e.clientY - suDrag.startClientY;
        // Annule la rotation de la forme pour convertir le déplacement souris (repère écran) dans
        // le repère local (non pivoté) de la forme, cohérent avec text_offset_x/y.
        const rad = -suDrag.rotation * Math.PI / 180;
        const localDx = dxPx * Math.cos(rad) - dyPx * Math.sin(rad);
        const localDy = dxPx * Math.sin(rad) + dyPx * Math.cos(rad);
        const newOffX = suDrag.startOffX + (localDx / suDrag.zoneWpx * 100);
        const newOffY = suDrag.startOffY + (localDy / suDrag.zoneHpx * 100);
        suDrag.zoneEl.dataset.textOffsetX = Math.round(newOffX * 100) / 100;
        suDrag.zoneEl.dataset.textOffsetY = Math.round(newOffY * 100) / 100;
        suApplyTextOffset(suDrag.zoneEl);
        suRenderTextRotateHandle(suDrag.zoneEl);
        return;
    }
    if (suDrag.type === 'move') {
        const dxPct = (e.clientX - suDrag.startClientX) / suDrag.canvasRect.width * 100;
        const dyPct = (e.clientY - suDrag.startClientY) / suDrag.canvasRect.height * 100;
        suDrag.zones.forEach(z => {
            z.zoneEl.style.left = (z.startPX + dxPct) + '%';
            z.zoneEl.style.top = (z.startPY + dyPct) + '%';
        });
        // Poignées non affichées pendant un déplacement groupé (pas de forme "unique" à cibler) —
        // le contour pointillé violet (su-zone-multiselect) suffit à voir le groupe qui bouge.
        if (suDrag.zones.length === 1) suRenderHandles(suDrag.zones[0].zoneEl);
    } else {
        // Redimensionnement conscient de la rotation : le coin/l'arête opposé (sx,sy=0 pour une
        // arête = cet axe ne bouge pas) reste fixe à l'écran ; le vecteur ancre→souris est ramené
        // dans le repère LOCAL non pivoté de la forme pour calculer largeur/hauteur normalement,
        // puis reconverti en absolu pour repositionner le centre. Sans ça, tirer une poignée sur
        // une forme pivotée à 90° redimensionnait le mauvais axe (bug remonté par David).
        const d = suDrag;
        const mousePx = { x: e.clientX - d.canvasRect.left, y: e.clientY - d.canvasRect.top };
        const vx = mousePx.x - d.anchorPx.x, vy = mousePx.y - d.anchorPx.y;
        const localX = vx * d.cos + vy * d.sin;
        const localY = -vx * d.sin + vy * d.cos;
        const minPx = 8;
        const newWpx = (d.sx === 0) ? (d.startPW / 100 * d.canvasRect.width) : Math.max(minPx, d.sx * localX);
        const newHpx = (d.sy === 0) ? (d.startPH / 100 * d.canvasRect.height) : Math.max(minPx, d.sy * localY);
        const centerLocalX = d.sx * newWpx / 2, centerLocalY = d.sy * newHpx / 2;
        const centerPx = {
            x: d.anchorPx.x + centerLocalX * d.cos - centerLocalY * d.sin,
            y: d.anchorPx.y + centerLocalX * d.sin + centerLocalY * d.cos,
        };
        const nw = Math.max(0.5, newWpx / d.canvasRect.width * 100);
        const nh = Math.max(0.5, newHpx / d.canvasRect.height * 100);
        d.zoneEl.style.left = (centerPx.x / d.canvasRect.width * 100 - nw / 2) + '%';
        d.zoneEl.style.top = (centerPx.y / d.canvasRect.height * 100 - nh / 2) + '%';
        d.zoneEl.style.width = nw + '%'; d.zoneEl.style.height = nh + '%';
        suRenderHandles(d.zoneEl);
    }
});

document.addEventListener('pointerup', () => {
    if (!suDrag) return;
    if (suDrag.labelEl) suDrag.labelEl.classList.remove('dragging');
    if (suDrag.type === 'rotate' || suDrag.type === 'textrotate') suClearRotationTicks();
    if (suDrag.type === 'marquee') {
        const box = document.getElementById('suMarqueeBox');
        if (box) box.remove();
        const ids = suDrag.previewIds || new Set();
        if (ids.size >= 2) {
            // Au moins 2 formes recoupées par le cadre : sélection multiple active, panneau
            // Propriétés fermé (il n'a de sens que pour une forme unique).
            suMultiSelected = ids;
            suApplyMultiSelectVisual();
            suDeselectZone();
        } else if (ids.size === 1) {
            suClearMultiSelection();
            const only = document.querySelector('.su-zone[data-zone-id="' + Array.from(ids)[0] + '"]');
            if (only) suSelectZone(only, false);
        } else {
            suClearMultiSelection();
            suClearSelectionVisual();
        }
        suDrag = null;
        return;
    }
    if (suDrag.type === 'move') {
        suDrag.zones.forEach(z => suSaveGeometry(z.zoneEl));
        suDrag = null;
        return;
    }
    const zoneEl = suDrag.zoneEl;
    if (suDrag.type === 'resize') {
        const w = parseFloat(zoneEl.style.width), h = parseFloat(zoneEl.style.height);
        // Le glisser-déposer redimensionne directement zoneEl.style sans repasser par
        // suApplyPropsToSelected() (qui ne lit que les champs du panneau) — il faut donc
        // régénérer ici le SVG des formes industrielles pour que le nombre de rouleaux/filets
        // se recalcule sur la nouvelle taille (écart constant, voir suRollerCount).
        const shape = zoneEl.dataset.shape;
        if (SU_SVG_SHAPES.has(shape)) {
            const layer = suGetOrCreateSvgLayer(zoneEl);
            layer.innerHTML = suIndustrialSvg(shape, zoneEl.dataset.fill, zoneEl.dataset.zoneId || 'x', w, h, zoneEl.dataset.border);
        }
    }
    suDrag = null;
    suSaveGeometry(zoneEl);
});

// Échap désélectionne tout (sélection simple ou multiple), pratique pour sortir d'un groupe sans
// devoir cliquer précisément sur le vide du plan.
document.addEventListener('keydown', (e) => {
    if (!suEditMode || e.key !== 'Escape') return;
    suClearMultiSelection();
    suClearSelectionVisual();
});
// Déplacement au clavier de la forme sélectionnée (flèches = petit pas, Maj+flèche = grand pas) —
// pratique pour un positionnement fin, en complément du glisser-déposer à la souris.
document.addEventListener('keydown', (e) => {
    if (!suEditMode || !suSelectedZoneId) return;
    if (!['ArrowLeft', 'ArrowRight', 'ArrowUp', 'ArrowDown'].includes(e.key)) return;
    const tag = document.activeElement ? document.activeElement.tagName : '';
    if (tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT') return; // laisse les flèches agir normalement dans les champs du panneau

    const zoneEl = document.querySelector('.su-zone[data-zone-id="' + suSelectedZoneId + '"]');
    if (!zoneEl) return;
    e.preventDefault();

    const step = e.shiftKey ? 1 : 0.2;
    let dx = 0, dy = 0;
    if (e.key === 'ArrowLeft') dx = -step;
    else if (e.key === 'ArrowRight') dx = step;
    else if (e.key === 'ArrowUp') dy = -step;
    else if (e.key === 'ArrowDown') dy = step;

    zoneEl.style.left = ((parseFloat(zoneEl.style.left) || 0) + dx) + '%';
    zoneEl.style.top = ((parseFloat(zoneEl.style.top) || 0) + dy) + '%';
    suRenderHandles(zoneEl);

    clearTimeout(window._suNudgeTimer);
    window._suNudgeTimer = setTimeout(() => suSaveGeometry(zoneEl), 350);
});

async function suSaveGeometry(zoneEl) {
    if (!zoneEl) return;
    const span = suLabelSpan(zoneEl);
    const fd = new FormData();
    fd.append('action', 'update_geometry');
    fd.append('zone_id', zoneEl.dataset.zoneId);
    fd.append('pos_x', parseFloat(zoneEl.style.left) || 0);
    fd.append('pos_y', parseFloat(zoneEl.style.top) || 0);
    fd.append('pos_w', parseFloat(zoneEl.style.width) || 1);
    fd.append('pos_h', parseFloat(zoneEl.style.height) || 1);
    fd.append('rotation', zoneEl.dataset.rotation || 0);
    fd.append('text_rotation', zoneEl.dataset.textRotation || 0);
    fd.append('font_size', zoneEl.dataset.fontSize || '');
    fd.append('text_color', zoneEl.dataset.textColor || '');
    fd.append('text_offset_x', zoneEl.dataset.textOffsetX || 0);
    fd.append('text_offset_y', zoneEl.dataset.textOffsetY || 0);
    fd.append('shape_type', zoneEl.dataset.shape || 'rect');
    fd.append('fill_color', zoneEl.dataset.fill || '#ffffff');
    fd.append('fill_color2', zoneEl.dataset.fill2 || '');
    fd.append('gradient', zoneEl.dataset.gradient === '1' ? '1' : '');
    fd.append('gradient_angle', zoneEl.dataset.gradientAngle || '135');
    fd.append('border_color', (zoneEl.dataset.border && zoneEl.dataset.border !== 'none') ? zoneEl.dataset.border : '');
    fd.append('effect', zoneEl.dataset.effect || 'none');
    fd.append('label', span ? span.textContent.trim() : '');
    fd.append('est_clickable', zoneEl.dataset.clickable === '1' ? '1' : '');
    fd.append('categorie_visuelle_id', zoneEl.dataset.categorieVisuelleId || '');
    fd.append('csrf_token', SU_CSRF);
    try {
        const res = await fetch('schema_editor_api.php', { method: 'POST', body: fd });
        const data = await res.json();
        if (data.success) showToast(I18N_PM.toast_plan_maj);
        else showToast(data.error || I18N_PM.toast_erreur, true);
    } catch (e) {
        showToast(I18N_PM.toast_erreur_reseau, true);
    }
}

// Éclaircit (percent > 0) ou assombrit (percent < 0) une couleur hex — utilisé pour générer les
// dégradés des icônes industrielles (effet "3D") à partir de la seule couleur choisie par l'utilisateur.
function suShadeColor(hex, percent) {
    hex = (hex || '#3498db').replace('#', '');
    if (hex.length === 3) hex = hex.split('').map(c => c + c).join('');
    let r = parseInt(hex.substr(0, 2), 16), g = parseInt(hex.substr(2, 2), 16), b = parseInt(hex.substr(4, 2), 16);
    const adj = (c) => Math.max(0, Math.min(255, Math.round(c + (percent / 100) * (percent > 0 ? (255 - c) : c))));
    r = adj(r); g = adj(g); b = adj(b);
    return '#' + [r, g, b].map(v => v.toString(16).padStart(2, '0')).join('');
}
// Formes "industrielles" : au lieu d'un simple aplat + clip-path, ce sont de petites illustrations
// SVG en dégradés (rails/axes en gris acier, éléments actifs dans la couleur choisie avec un
// dégradé clair/foncé pour un effet de volume 3D). id : identifiant unique (zone) pour éviter les
// collisions d'id de <linearGradient>/<radialGradient> quand plusieurs formes de ce type coexistent.
const SU_SVG_SHAPES = new Set(['convoyeur_rouleaux', 'convoyeur_rouleaux_arc', 'vis_sans_fin', 'tapis_chevron', 'robot_bras']);
// Nombre de rouleaux tel que l'écart RÉEL entre deux rouleaux reste constant quelle que soit la
// largeur (%) de la forme — on ajoute des rouleaux en agrandissant plutôt que d'étirer leur
// espacement. 0.62 (% de canevas par intervalle) fixe l'écartement souhaité entre deux rouleaux.
function suRollerCount(posW) {
    const usable = (posW || 10) * 0.88;
    const pitch = 0.62;
    const gaps = Math.max(2, Math.round(usable / pitch));
    return gaps + 1;
}
// Largeur d'un rouleau en unités du viewBox (0-100), calculée pour que sa largeur RÉELLE reste
// constante (~0.303% du canevas, calibré sur le convoyeur de référence) quelle que soit la largeur
// de la forme — sinon, comme le viewBox est toujours étiré sur la largeur réelle, les rouleaux
// grossissent avec la forme et finissent par se chevaucher au lieu de garder le vide entre eux.
function suRollerWidth(posW) {
    const targetPercentOfCanvas = 0.303;
    return Math.max(0.3, Math.min(20, (targetPercentOfCanvas / (posW || 10)) * 100));
}
function suIndustrialSvg(shape, fill, id, posW, posH, border) {
    const light = suShadeColor(fill, 40), dark = suShadeColor(fill, -35);
    // Couleur du châssis/rails : suit la couleur de Bordure choisie (réutilisée pour ça, ces
    // formes n'ayant pas de vraie bordure CSS) si elle est définie, sinon gris acier par défaut —
    // inchangé pour toute forme existante qui n'a jamais eu de bordure choisie.
    const hasChassis = !!(border && border !== 'none');
    const steelLight = hasChassis ? suShadeColor(border, 35) : '#c7ccd1';
    const steelMid = hasChassis ? border : '#9aa1a8';
    const steelDark = hasChassis ? suShadeColor(border, -35) : '#5b6167';
    const g1 = 'su-g1-' + id, g2 = 'su-g2-' + id, g3 = 'su-g3-' + id;
    // Le coude à 90° a des rouleaux tournés (non alignés sur les axes) : les étirer indépendamment
    // en X/Y (preserveAspectRatio="none", utilisé pour toutes les autres formes industrielles, faites
    // pour être étirées en longueur) les déforme en parallélogrammes de tailles différentes selon
    // leur angle sur la courbe — ça donnait l'impression d'une vue en perspective/diagonale plutôt
    // que d'un vrai plan vu de dessus (remonté par David). "xMidYMid meet" force un cadrage centré,
    // à l'échelle uniforme, qui garde tous les rouleaux identiques quelle que soit la forme du cadre.
    const svgOpen = '<svg viewBox="0 0 100 100" preserveAspectRatio="' + (shape === 'convoyeur_rouleaux_arc' ? 'xMidYMid meet' : 'none') + '" style="width:100%;height:100%;display:block;">';
    if (shape === 'convoyeur_rouleaux') {
        let rollers = '';
        const n = suRollerCount(posW);
        const rw = suRollerWidth(posW);
        // Le contour de chaque rouleau doit rétrécir avec lui (même ratio qu'à l'origine, 0.4 pour
        // une largeur de 6.4) — sinon, une fois les rouleaux devenus minuscules sur un très long
        // convoyeur, une épaisseur de trait restée fixe finit par être plus large que les rouleaux
        // eux-mêmes et les fait fusionner visuellement en un seul bloc gris (remonté par David).
        const strokeW = Math.max(0.03, rw * 0.0625);
        for (let i = 0; i < n; i++) {
            const cx = 6 + i * (88 / (n - 1));
            rollers += '<rect x="' + (cx - rw / 2) + '" y="20" width="' + rw + '" height="60" rx="' + (rw / 2) + '" fill="url(#' + g2 + ')" stroke="' + dark + '" stroke-width="' + strokeW + '"/>';
        }
        // Les rails s'arrêtent pile au bord du premier/dernier rouleau (au lieu de courir sur toute
        // la largeur de la forme) — sinon ils dépassent en un bout arrondi qui n'a plus de rouleau
        // dessous et ressort visuellement comme un moignon pointu.
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
        // Coude à 90° : rouleaux disposés en éventail (radiaux) le long d'un arc de cercle centré
        // sur le coin bas-gauche de la forme — même logique que le convoyeur droit, transposée en polaire.
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
function suGetOrCreateSvgLayer(zoneEl) {
    let layer = zoneEl.querySelector('.su-zone-svg');
    if (!layer) {
        layer = document.createElement('div');
        layer.className = 'su-zone-svg';
        layer.style.cssText = 'position:absolute; inset:0; pointer-events:none;';
        zoneEl.insertBefore(layer, zoneEl.firstChild);
    }
    return layer;
}
function suApplyPropsToSelected() {
    const zoneEl = document.querySelector('.su-zone[data-zone-id="' + suSelectedZoneId + '"]');
    if (!zoneEl) return;
    const span = suLabelSpan(zoneEl);
    const label = document.getElementById('sp-label').value;
    const fill = document.getElementById('sp-fill').value;
    const gradient = document.getElementById('sp-gradient').checked;
    const fill2 = document.getElementById('sp-fill2').value;
    const gradientAngle = document.getElementById('sp-gradient-angle').value || '135';
    document.getElementById('spFill2Wrap').style.display = gradient ? '' : 'none';
    document.getElementById('spGradientDirWrap').style.display = gradient ? '' : 'none';
    const noBorder = document.getElementById('sp-border-none').checked;
    const border = noBorder ? 'none' : document.getElementById('sp-border').value;
    const shape = document.getElementById('sp-shape').value;
    const effect = document.getElementById('sp-effect').value || 'none';
    const widthRaw = document.getElementById('sp-width').value;
    const heightRaw = document.getElementById('sp-height').value;
    const rotation = parseFloat(document.getElementById('sp-rotation').value || 0);
    const textRotationInput = document.getElementById('sp-text-rotation');
    const textRotation = parseFloat(textRotationInput.value || 0);
    const fontSizeRaw = document.getElementById('sp-font-size').value;
    const fontSize = fontSizeRaw === '' ? '' : parseFloat(fontSizeRaw);
    const textColorAuto = document.getElementById('sp-text-color-auto').checked;
    const textColor = textColorAuto ? '' : document.getElementById('sp-text-color').value;
    const clickable = document.getElementById('sp-clickable').checked;
    const categorieVisuelleId = document.getElementById('sp-categorie').value;

    if (span) span.textContent = label;
    zoneEl.title = label;
    zoneEl.dataset.categorieVisuelleId = categorieVisuelleId;
    zoneEl.dataset.fill = fill;
    zoneEl.dataset.fill2 = fill2;
    zoneEl.dataset.gradient = gradient ? '1' : '0';
    zoneEl.dataset.gradientAngle = gradientAngle;
    zoneEl.dataset.border = border;
    zoneEl.dataset.shape = shape;
    zoneEl.dataset.effect = effect;
    zoneEl.dataset.rotation = rotation;
    zoneEl.dataset.textRotation = textRotation;
    zoneEl.dataset.fontSize = fontSize;
    zoneEl.dataset.textColor = textColor;
    zoneEl.dataset.clickable = clickable ? '1' : '0';
    if (widthRaw !== '') zoneEl.style.width = Math.max(0.5, Math.min(100, parseFloat(widthRaw))) + '%';
    if (heightRaw !== '') zoneEl.style.height = Math.max(0.5, Math.min(100, parseFloat(heightRaw))) + '%';

    const css = suShapeCss(shape);
    const isTextOnly = shape === 'text_only';
    const isSvgShape = SU_SVG_SHAPES.has(shape);
    if (isSvgShape) {
        zoneEl.style.background = 'transparent';
        zoneEl.style.borderColor = 'transparent';
        zoneEl.style.borderRadius = '0';
        zoneEl.style.clipPath = 'none';
        const layer = suGetOrCreateSvgLayer(zoneEl);
        layer.innerHTML = suIndustrialSvg(shape, fill, zoneEl.dataset.zoneId || 'x', parseFloat(zoneEl.style.width) || 10, parseFloat(zoneEl.style.height) || 10, border);
    } else {
        const oldLayer = zoneEl.querySelector('.su-zone-svg');
        if (oldLayer) oldLayer.remove();
        zoneEl.style.background = isTextOnly ? 'transparent' : ((gradient && fill2) ? ('linear-gradient(' + gradientAngle + 'deg, ' + fill + ', ' + fill2 + ')') : fill);
        zoneEl.style.borderColor = (!isTextOnly && border !== 'none') ? border : 'transparent';
        zoneEl.style.borderRadius = css.radius;
        zoneEl.style.clipPath = css.clip;
    }
    const effStyle = (isTextOnly || isSvgShape) ? { filter: '', boxShadow: '' } : suEffectStyle(effect, fill, border);
    zoneEl.style.filter = effStyle.filter;
    zoneEl.style.boxShadow = effStyle.boxShadow;
    zoneEl.style.transform = (rotation != 0) ? ('rotate(' + rotation + 'deg)') : '';
    zoneEl.dataset.textOffsetX = zoneEl.dataset.textOffsetX || 0;
    zoneEl.dataset.textOffsetY = zoneEl.dataset.textOffsetY || 0;
    if (span) {
        span.style.color = textColor || suTextColor(isTextOnly ? '#ffffff' : fill);
        span.style.fontSize = (fontSize !== '') ? (fontSize + 'rem') : '';
    }
    suApplyTextOffset(zoneEl);
    zoneEl.classList.toggle('su-zone-click', clickable);
    zoneEl.classList.toggle('su-zone-decor', !clickable);

    suRenderHandles(zoneEl);
    suSaveGeometry(zoneEl);
}
const spTextColorAutoEl = document.getElementById('sp-text-color-auto');
if (spTextColorAutoEl) spTextColorAutoEl.addEventListener('change', () => {
    document.getElementById('sp-text-color').disabled = spTextColorAutoEl.checked;
    suApplyPropsToSelected();
});
['sp-fill', 'sp-fill2', 'sp-gradient', 'sp-border', 'sp-border-none', 'sp-shape', 'sp-width', 'sp-height', 'sp-rotation', 'sp-text-rotation', 'sp-font-size', 'sp-text-color', 'sp-clickable'].forEach(id => {
    const el = document.getElementById(id);
    if (el) el.addEventListener('change', suApplyPropsToSelected);
});
// Choisir une catégorie applique aussitôt SES couleurs par défaut (fond/fond2/dégradé/bordure)
// sur la forme sélectionnée, en repeuplant les champs couleur normaux avant de suivre le même
// chemin d'enregistrement que n'importe quel autre changement — la catégorie n'est qu'un raccourci
// de remplissage, jamais une contrainte : les couleurs restent modifiables librement après coup.
const spCategorieEl = document.getElementById('sp-categorie');
function suApplyCategorieValues() {
    const cat = SU_CATEGORIES_VISUELLES.find(c => String(c.id) === spCategorieEl.value);
    if (cat) {
        document.getElementById('sp-fill').value = cat.fill_color || '#ffffff';
        const hasBorder = !!cat.border_color;
        document.getElementById('sp-border-none').checked = !hasBorder;
        if (hasBorder) document.getElementById('sp-border').value = cat.border_color;
        const isGradient = !!(cat.gradient && cat.gradient != '0');
        document.getElementById('sp-gradient').checked = isGradient;
        if (isGradient && cat.fill_color2) document.getElementById('sp-fill2').value = cat.fill_color2;
        const catAngle = String(cat.gradient_angle || 135);
        document.getElementById('sp-gradient-angle').value = catAngle;
        document.querySelectorAll('.su-dir-tile').forEach(t => t.classList.toggle('active', t.dataset.angle === catAngle));
        const catEffect = cat.effect || 'none';
        document.getElementById('sp-effect').value = catEffect;
        document.querySelectorAll('.su-effect-tile').forEach(t => t.classList.toggle('active', t.dataset.effect === catEffect));

        // Valeurs de départ (forme, taille, rotation, texte, comportement) : posées une seule fois ici,
        // au moment du choix de la catégorie — jamais réappliquées ensuite (contrairement aux couleurs/
        // effet ci-dessus), pour que les réglages faits forme par forme survivent à un futur changement
        // global de la catégorie dans Paramètres.
        document.getElementById('sp-shape').value = cat.shape_type || 'rect';
        suUpdateShapeTrigger();
        if (cat.pos_w != null && cat.pos_w !== '') document.getElementById('sp-width').value = cat.pos_w;
        if (cat.pos_h != null && cat.pos_h !== '') document.getElementById('sp-height').value = cat.pos_h;
        document.getElementById('sp-rotation').value = cat.rotation || 0;
        document.getElementById('sp-text-rotation').value = cat.text_rotation || 0;
        document.getElementById('sp-font-size').value = (cat.font_size != null && cat.font_size !== '') ? cat.font_size : '';
        const hasTextColor = !!cat.text_color;
        document.getElementById('sp-text-color-auto').checked = !hasTextColor;
        document.getElementById('sp-text-color').disabled = !hasTextColor;
        if (hasTextColor) document.getElementById('sp-text-color').value = cat.text_color;
        document.getElementById('sp-clickable').checked = cat.est_clickable == '1' || cat.est_clickable == 1;
        if (cat.label) document.getElementById('sp-label').value = cat.label;
    }
    suApplyPropsToSelected();
    suUpdateAdvancedForCategorie();
}
if (spCategorieEl) spCategorieEl.addEventListener('change', suApplyCategorieValues);
const btnReappliquerCategorie = document.getElementById('btnReappliquerCategorie');
if (btnReappliquerCategorie) btnReappliquerCategorie.addEventListener('click', () => {
    if (spCategorieEl && spCategorieEl.value) suApplyCategorieValues();
});
const btnToggleAdvanced = document.getElementById('btnToggleAdvanced');
if (btnToggleAdvanced) btnToggleAdvanced.addEventListener('click', () => {
    const wrap = document.getElementById('spAdvancedSettings');
    const isVisible = wrap && wrap.style.display !== 'none';
    suSetAdvancedVisible(!isVisible);
});
const btnFontSmaller = document.getElementById('btnFontSmaller');
const btnFontBigger = document.getElementById('btnFontBigger');
function suNudgeFontSize(delta) {
    const spInput = document.getElementById('sp-font-size');
    const zoneEl = document.querySelector('.su-zone[data-zone-id="' + suSelectedZoneId + '"]');
    let base = parseFloat(spInput.value);
    if (isNaN(base)) {
        // Pas de taille explicite : on part de la taille réellement affichée (le clamp() responsive).
        const span = zoneEl ? suLabelSpan(zoneEl) : null;
        base = span ? parseFloat(getComputedStyle(span).fontSize) / 16 : 0.75;
    }
    spInput.value = Math.max(0.3, Math.min(3, Math.round((base + delta) * 100) / 100));
    suApplyPropsToSelected();
}
if (btnFontSmaller) btnFontSmaller.addEventListener('click', () => suNudgeFontSize(-0.05));
if (btnFontBigger) btnFontBigger.addEventListener('click', () => suNudgeFontSize(0.05));

// Largeur/hauteur ajustées indépendamment l'une de l'autre, plutôt que par les poignées de coin
// qui changent les deux à la fois (risque de déformer la forme sans le vouloir).
function suNudgeSize(fieldId, styleProp, delta) {
    const input = document.getElementById(fieldId);
    const zoneEl = document.querySelector('.su-zone[data-zone-id="' + suSelectedZoneId + '"]');
    if (!zoneEl) return;
    let base = parseFloat(input.value);
    if (isNaN(base)) base = parseFloat(zoneEl.style[styleProp]) || 5;
    input.value = Math.max(0.5, Math.min(100, Math.round((base + delta) * 100) / 100));
    suApplyPropsToSelected();
}
const btnWidthSmaller = document.getElementById('btnWidthSmaller');
const btnWidthBigger = document.getElementById('btnWidthBigger');
const btnHeightSmaller = document.getElementById('btnHeightSmaller');
const btnHeightBigger = document.getElementById('btnHeightBigger');
if (btnWidthSmaller) btnWidthSmaller.addEventListener('click', () => suNudgeSize('sp-width', 'width', -0.5));
if (btnWidthBigger) btnWidthBigger.addEventListener('click', () => suNudgeSize('sp-width', 'width', 0.5));
if (btnHeightSmaller) btnHeightSmaller.addEventListener('click', () => suNudgeSize('sp-height', 'height', -0.5));
if (btnHeightBigger) btnHeightBigger.addEventListener('click', () => suNudgeSize('sp-height', 'height', 0.5));
const spLabel = document.getElementById('sp-label');
if (spLabel) spLabel.addEventListener('blur', suApplyPropsToSelected);
const suPropsClose = document.getElementById('suPropsClose');
if (suPropsClose) suPropsClose.addEventListener('click', suDeselectZone);
const btnValiderForme = document.getElementById('btnValiderForme');
if (btnValiderForme) btnValiderForme.addEventListener('click', suDeselectZone);

// Position par défaut du panneau (coin haut-droit du plan) : calculée en JS plutôt qu'en CSS fixe
// car le panneau est en position:fixed (pour échapper à l'overflow:hidden et à la hauteur limitée
// de .schema-box, voir commentaire CSS) — sa position par défaut doit donc rester visuellement
// collée au plan quelle que soit la largeur de l'écran.
function suPositionPropsPanelDefault() {
    const panel = document.getElementById('suPropsPanel');
    const box = document.getElementById('schemaBox');
    if (!panel || !box) return;
    const boxRect = box.getBoundingClientRect();
    panel.style.width = '';
    panel.style.height = '';
    const rect = panel.getBoundingClientRect();
    panel.style.left = Math.max(4, boxRect.right - rect.width - 16) + 'px';
    const margin = 20, minHeight = 240;
    const top = Math.min(Math.max(4, boxRect.top + 62), Math.max(4, window.innerHeight - minHeight - margin));
    panel.style.top = top + 'px';
    panel.style.maxHeight = 'calc(100vh - ' + top + 'px - ' + margin + 'px)';
}

// Panneau de propriétés déplaçable (glisser depuis son en-tête) — pour pouvoir le sortir du
// dessus d'une machine qu'il masquerait sinon dans le plan. Coordonnées en repère viewport
// (position:fixed), pas relatives à .schema-box.
(function suMakePropsPanelDraggable() {
    const panel = document.getElementById('suPropsPanel');
    const head = panel ? panel.querySelector('.su-props-head') : null;
    if (!panel || !head) return;
    let drag = null;
    head.addEventListener('pointerdown', (e) => {
        if (e.target.closest('#suPropsClose')) return;
        const rect = panel.getBoundingClientRect();
        drag = {
            startX: e.clientX, startY: e.clientY,
            startLeft: rect.left, startTop: rect.top,
            maxLeft: window.innerWidth - rect.width - 4, maxTop: window.innerHeight - rect.height - 4,
        };
        panel.style.left = drag.startLeft + 'px';
        panel.style.top = drag.startTop + 'px';
        e.preventDefault();
    });
    document.addEventListener('pointermove', (e) => {
        if (!drag) return;
        const nl = Math.max(4, Math.min(drag.maxLeft, drag.startLeft + (e.clientX - drag.startX)));
        const nt = Math.max(4, Math.min(drag.maxTop, drag.startTop + (e.clientY - drag.startY)));
        panel.style.left = nl + 'px';
        panel.style.top = nt + 'px';
    });
    document.addEventListener('pointerup', () => { drag = null; });
})();
function suResetPropsPanelPosition() {
    const panel = document.getElementById('suPropsPanel');
    if (!panel) return;
    panel.style.left = '';
    panel.style.top = '';
    panel.style.width = '';
    panel.style.height = '';
}

async function suReorder(direction) {
    if (!suSelectedZoneId) return;
    const zid = suSelectedZoneId;
    const fd = new FormData();
    fd.append('action', 'reorder');
    fd.append('zone_id', zid);
    fd.append('direction', direction);
    fd.append('csrf_token', SU_CSRF);
    const res = await fetch('schema_editor_api.php', { method: 'POST', body: fd });
    const data = await res.json();
    if (!data.success) { showToast(data.error || I18N_PM.toast_erreur, true); return; }
    showToast(direction === 'front' ? I18N_PM.toast_premier_plan : I18N_PM.toast_arriere_plan);
    await loadSchemaVue(suCurrentVue);
    const canvas = document.getElementById('suCanvas');
    if (canvas) canvas.classList.add('edit-mode');
    const zoneEl = document.querySelector('.su-zone[data-zone-id="' + zid + '"]');
    if (zoneEl) suSelectZone(zoneEl, true);
}
const btnSendFront = document.getElementById('btnSendFront');
const btnSendBack = document.getElementById('btnSendBack');
if (btnSendFront) btnSendFront.addEventListener('click', () => suReorder('front'));
if (btnSendBack) btnSendBack.addEventListener('click', () => suReorder('back'));

const btnRecenterText = document.getElementById('btnRecenterText');
if (btnRecenterText) btnRecenterText.addEventListener('click', () => {
    if (!suSelectedZoneId) return;
    const zoneEl = document.querySelector('.su-zone[data-zone-id="' + suSelectedZoneId + '"]');
    if (!zoneEl) return;
    zoneEl.dataset.textOffsetX = 0;
    zoneEl.dataset.textOffsetY = 0;
    suApplyTextOffset(zoneEl);
    suSaveGeometry(zoneEl);
});

if (btnAddZone) btnAddZone.addEventListener('click', async () => {
    const schemaEl = document.querySelector('.su-schema');
    if (!schemaEl) return;
    const fd = new FormData();
    fd.append('action', 'add_zone');
    fd.append('schema_id', schemaEl.dataset.schemaId);
    fd.append('csrf_token', SU_CSRF);
    const res = await fetch('schema_editor_api.php', { method: 'POST', body: fd });
    const data = await res.json();
    if (!data.success) { showToast(data.error || I18N_PM.toast_erreur, true); return; }
    showToast(I18N_PM.toast_forme_ajoutee);
    if (!suEditMode) suSetEditMode(true);
    await loadSchemaVue(suCurrentVue);
    const canvas = document.getElementById('suCanvas');
    if (canvas) canvas.classList.add('edit-mode');
    const zoneEl = document.querySelector('.su-zone[data-zone-id="' + data.zone_id + '"]');
    // Sélectionne la nouvelle forme (poignées visibles) sans ouvrir le panneau de propriétés —
    // clic droit pour l'ouvrir quand on est prêt à la personnaliser (cohérent avec le reste).
    if (zoneEl) suSelectZone(zoneEl, false);
});

if (btnGrowCanvas) btnGrowCanvas.addEventListener('click', (e) => {
    e.stopPropagation();
    suGrowPad.classList.toggle('show');
});
document.addEventListener('click', (e) => {
    if (suGrowPad && suGrowPad.classList.contains('show') && !e.target.closest('.su-grow-wrap')) suGrowPad.classList.remove('show');
});
const suGrowModeEl = document.getElementById('suGrowMode');
if (suGrowModeEl) suGrowModeEl.addEventListener('click', (e) => {
    const btn = e.target.closest('.su-grow-mode-btn');
    if (!btn) return;
    suGrowMode = btn.dataset.mode;
    document.querySelectorAll('.su-grow-mode-btn').forEach(b => b.classList.toggle('active', b === btn));
});
if (suGrowPad) suGrowPad.addEventListener('click', async (e) => {
    const btn = e.target.closest('.su-grow-arrow');
    if (!btn) return;
    const schemaEl = document.querySelector('.su-schema');
    if (!schemaEl) return;
    const fd = new FormData();
    fd.append('action', 'resize_canvas');
    fd.append('schema_id', schemaEl.dataset.schemaId);
    fd.append('direction', btn.dataset.dir);
    fd.append('mode', suGrowMode);
    fd.append('csrf_token', SU_CSRF);
    const res = await fetch('schema_editor_api.php', { method: 'POST', body: fd });
    const data = await res.json();
    if (!data.success) { showToast(data.error || I18N_PM.toast_erreur, true); return; }
    const wasEdit = suEditMode;
    await loadSchemaVue(suCurrentVue);
    if (wasEdit) suSetEditMode(true);
    suGrowPad.classList.add('show');
});

const btnDeleteZone = document.getElementById('btnDeleteZone');
if (btnDeleteZone) btnDeleteZone.addEventListener('click', () => {
    if (!suSelectedZoneId) return;
    const zid = suSelectedZoneId;
    fvConfirm(I18N_PM.confirm_suppr_forme, async () => {
        const fd = new FormData();
        fd.append('action', 'delete_zone');
        fd.append('zone_id', zid);
        fd.append('csrf_token', SU_CSRF);
        const res = await fetch('schema_editor_api.php', { method: 'POST', body: fd });
        const data = await res.json();
        if (data.success) { showToast(I18N_PM.toast_forme_supprimee); await loadSchemaVue(suCurrentVue); if (document.getElementById('suCanvas')) document.getElementById('suCanvas').classList.add('edit-mode'); }
        else showToast(data.error || I18N_PM.toast_erreur, true);
    });
});

// --- FICHE DE VIE MACHINE ---
const ficheVieModal = document.getElementById('ficheVieModal');
let fvData = null;

function fvEsc(s) { return (s || '').toString().replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c])); }
function fvDate(s) { return s ? s.split(' ')[0].split('-').reverse().join('/') + (s.split(' ')[1] ? ' ' + s.split(' ')[1].slice(0, 5) : '') : '-'; }

async function openFicheVie(zoneId) {
    ficheVieModal.style.display = 'block';
    document.getElementById('fvTitle').textContent = I18N_PM.chargement;
    document.getElementById('fvSchemaNom').textContent = '';
    document.getElementById('fvBody').innerHTML = `<p class="fv-empty"><i class="fa-solid fa-spinner fa-spin"></i> ${I18N_PM.chargement}</p>`;
    document.querySelectorAll('.fv-tab').forEach((t, i) => t.classList.toggle('active', i === 0));

    try {
        const res = await fetch('fiche_vie_machine.php?zone_id=' + encodeURIComponent(zoneId));
        const data = await res.json();
        if (!data.success) { document.getElementById('fvBody').innerHTML = '<p class="fv-empty" style="color:var(--danger);">' + fvEsc(data.error || I18N_PM.toast_erreur) + '</p>'; return; }
        fvData = data;
        document.getElementById('fvTitle').textContent = data.zone.label;
        document.getElementById('fvSchemaNom').textContent = data.zone.schema_nom;
        renderFvPane('fv-pane-refs');
    } catch (e) {
        document.getElementById('fvBody').innerHTML = `<p class="fv-empty" style="color:var(--danger);">${I18N_PM.bi_erreur_chargement}</p>`;
    }
}
document.getElementById('fvClose').addEventListener('click', () => ficheVieModal.style.display = 'none');
// Pas de fermeture au clic sur le fond : un clic à côté par mégarde (souris ou doigt qui dérape
// sur tablette) ne doit pas faire perdre la saisie en cours — seul le bouton "Fermer" ferme.

document.querySelectorAll('.fv-tab').forEach(tab => {
    tab.addEventListener('click', () => {
        document.querySelectorAll('.fv-tab').forEach(t => t.classList.remove('active'));
        tab.classList.add('active');
        renderFvPane(tab.dataset.pane);
    });
});

function renderFvPane(pane) {
    if (!fvData) return;
    const body = document.getElementById('fvBody');
    if (pane === 'fv-pane-refs') { body.innerHTML = fvRenderRefs(); fvWireRefs(); }
    else if (pane === 'fv-pane-histo') { body.innerHTML = fvRenderHisto(); }
    else if (pane === 'fv-pane-interv') { body.innerHTML = fvRenderInterv(); fvWireInterv(); }
    else if (pane === 'fv-pane-docs') { body.innerHTML = fvRenderDocs(); fvWireDocs(); }
    else if (pane === 'fv-pane-devis') { body.innerHTML = fvRenderDevis(); fvWireDevis(); }
    else if (pane === 'fv-pane-aide') { body.innerHTML = fvRenderMemo(); fvWireMemo(); }
}

function fvRenderRefs() {
    const f = fvData.fiche;
    const majInfo = f.maj_par ? `<p style="font-size:0.7rem; color:#94a3b8; margin:10px 0 0;">${I18N_PM.fv_maj_par.replace('{who}', fvEsc(f.maj_par)).replace('{date}', fvDate(f.maj_le))}</p>` : '';
    const machineLiee = (fvData.historique && fvData.historique.machine) ? fvData.historique.machine : null;
    const machineLocalisation = machineLiee ? [machineLiee.usine, machineLiee.secteur, machineLiee.ligne, machineLiee.zone].filter(Boolean).map(fvEsc).join(' <span class="sep">/</span> ') : '';

    const machineInfoHtml = machineLiee
        ? '<i class="fa-solid fa-gear" style="color:var(--accent); margin-right:7px;"></i>' + fvEsc(machineLiee.nom_machine)
            + '<span style="font-size:0.68rem; font-weight:500; color:var(--ink-500); text-transform:none; margin-left:10px;"><i class="fa-solid fa-location-dot" style="margin-right:4px;"></i>' + machineLocalisation + '</span>'
        : '<span style="font-style:italic; font-weight:500; color:var(--ink-500);">' + I18N_PM.fv_aucune_machine_rattachee + (fvData.is_admin ? I18N_PM.fv_utilise_rattachement : '') + '</span>';

    let categorieField = '';
    if (fvData.is_admin && machineLiee) {
        const typesOptions = (fvData.types_equipement || []).map(t =>
            `<option value="${fvEsc(t.nom)}" ${machineLiee.type_equipement === t.nom ? 'selected' : ''}>${fvEsc(t.nom)}</option>`
        ).join('');
        categorieField = `
        <div class="fv-field">
            <span>${I18N_PM.fv_categorie} <span style="font-weight:400; text-transform:none;">${I18N_PM.fv_categorie_hint}</span></span>
            <select id="fv-type-equipement-select">
                <option value="">${I18N_PM.fv_non_renseigne}</option>
                ${typesOptions}
            </select>
        </div>`;
    }

    let lienMachineCard = '';
    if (fvData.is_admin) {
        const locSummaryHtml = machineLiee ? `
            <div class="loc-done-card">
                <div class="loc-done-info">
                    <div class="loc-done-icon"><i class="fa-solid fa-circle-check"></i></div>
                    <div><div class="loc-done-name">${fvEsc(machineLiee.nom_machine)}</div><div class="loc-done-path">${machineLocalisation}</div></div>
                </div>
                <div class="loc-done-actions">
                    <button type="button" class="loc-done-change" id="fvLocChangeBtn"><i class="fa-solid fa-rotate"></i> ${I18N_PM.fv_btn_changer}</button>
                    <button type="button" class="loc-done-change" id="fvLocDetachBtn"><i class="fa-solid fa-link-slash"></i> ${I18N_PM.fv_btn_detacher}</button>
                </div>
            </div>` : `
            <button type="button" class="pm-btn" id="fvLocAttachBtn"><i class="fa-solid fa-link"></i> ${I18N_PM.fv_btn_rattacher}</button>`;
        lienMachineCard = `
        <div class="fv-card fv-card-green">
            <div class="section-label"><span style="display:inline-flex; align-items:center; gap:8px;"><span class="fv-card-icon" style="background:rgba(46,204,113,.16); color:#219150;"><i class="fa-solid fa-link"></i></span>${I18N_PM.fv_rattachement_titre}</span><span class="rule"></span></div>
            ${locSummaryHtml}
            <p class="field-hint" style="margin:8px 0 0; font-size:0.68rem; color:var(--ink-500);">${I18N_PM.fv_rattachement_hint}</p>
        </div>`;
    }

    const tousTypes = fvData.composant_types || [];
    const composantsActuels = fvData.composants || [];
    const idsCoches = new Set(composantsActuels.map(c => String(c.composant_type_id)));
    const valeurParType = {};
    composantsActuels.forEach(c => { valeurParType[c.composant_type_id] = c.valeur; });

    const composantsFieldsHtml = tousTypes
        .filter(t => idsCoches.has(String(t.id)))
        .map(t => `<div class="fv-field">${fvEsc(t.nom)}<input type="text" name="composants[${t.id}]" value="${fvEsc(valeurParType[t.id] || '')}"></div>`)
        .join('') || `<p class="fv-empty" style="padding:4px 0;">${I18N_PM.fv_aucun_materiel}</p>`;

    const identiteFieldsHtml = categorieField ? `<div class="fv-grid" style="align-items:start;">
            <div class="fv-field"><span>${I18N_PM.fv_machine_parc}</span>
                <div style="font-size:0.9rem; font-weight:700; color:var(--primary); padding:7px 9px; border-radius:6px; background:var(--surface-2); border:1.5px solid var(--line-strong);">${machineInfoHtml}</div>
            </div>
            ${categorieField}
        </div>` : `<div class="fv-field">
            <span>${I18N_PM.fv_machine_parc}</span>
            <div style="font-size:0.9rem; font-weight:700; color:var(--primary); padding:7px 9px; border-radius:6px; background:var(--surface-2); border:1.5px solid var(--line-strong);">${machineInfoHtml}</div>
        </div>`;

    const suiviCard = `
        <div class="fv-card fv-card-purple">
            <div class="section-label"><span style="display:inline-flex; align-items:center; gap:8px;"><span class="fv-card-icon" style="background:rgba(155,89,182,.16); color:#8e44ad;"><i class="fa-solid fa-note-sticky"></i></span>${I18N_PM.fv_suivi_notes}</span><span class="rule"></span></div>
            <div class="fv-field" style="margin-bottom:10px;">${I18N_PM.fv_repere_automate}<input type="text" name="repere_automate" value="${fvEsc(f.repere_automate)}" placeholder="ex : 201052"></div>
            <div class="fv-field">${I18N_PM.fv_notes}<textarea name="notes" rows="2">${fvEsc(f.notes)}</textarea></div>
        </div>`;

    return `
    <div class="fv-card fv-card-blue">
        <div class="section-label"><span style="display:inline-flex; align-items:center; gap:8px;"><span class="fv-card-icon" style="background:rgba(52,152,219,.16); color:#2680c2;"><i class="fa-solid fa-industry"></i></span>${I18N_PM.fv_identification}</span><span class="rule"></span></div>
        ${identiteFieldsHtml}
    </div>

    <form id="fvRefsForm">
        <div class="fv-card fv-card-orange">
            <div style="display:flex; justify-content:space-between; align-items:center; gap:10px; margin-bottom:10px;">
                <div class="section-label" style="margin:0;"><span style="display:inline-flex; align-items:center; gap:8px;"><span class="fv-card-icon" style="background:rgba(243,156,18,.16); color:#c9820c;"><i class="fa-solid fa-toolbox"></i></span>${I18N_PM.fv_materiel_titre}</span><span class="rule"></span></div>
                <button type="button" class="pm-btn" id="btnOuvrirMateriel"><i class="fa-solid fa-plus"></i> ${I18N_PM.fv_btn_ajouter_materiel}</button>
            </div>
            <div class="fv-grid fv-grid-4" id="fvComposantsFields">${composantsFieldsHtml}</div>
        </div>

        ${lienMachineCard ? `<div class="fv-cards-row">${suiviCard}${lienMachineCard}</div>` : suiviCard}

        ${majInfo}
        <div style="margin-top:14px; text-align:right;">
            <button type="submit" class="pm-btn primary"><i class="fa-solid fa-floppy-disk"></i> ${I18N_PM.fv_btn_enregistrer}</button>
        </div>
    </form>`;
}

// Sauvegarde les champs du formulaire de références (moteur, câble, rouleaux, notes, repère
// automate...) AVANT toute action qui recharge la fiche depuis le serveur (changement de
// matériel coché ou de catégorie) — sinon une saisie pas encore validée par "Enregistrer" est
// silencieusement écrasée par les anciennes valeurs venues de la base au rechargement.
async function fvSauverRefsEnCours() {
    const form = document.getElementById('fvRefsForm');
    if (!form) return;
    const fd = new FormData(form);
    fd.append('action', 'update_refs');
    fd.append('zone_id', fvData.zone.id);
    fd.append('csrf_token', fvData.csrf_token);
    await fetch('fiche_vie_machine.php', { method: 'POST', body: fd });
}

function fvWireRefs() {
    document.getElementById('fvRefsForm').addEventListener('submit', async (e) => {
        e.preventDefault();
        const fd = new FormData(e.target);
        fd.append('action', 'update_refs');
        fd.append('zone_id', fvData.zone.id);
        fd.append('csrf_token', fvData.csrf_token);
        const res = await fetch('fiche_vie_machine.php', { method: 'POST', body: fd });
        const data = await res.json();
        if (data.success) { showToast(I18N_PM.fv_toast_fiche_enregistree); ficheVieModal.style.display = 'none'; }
        else { showToast(data.error || I18N_PM.fv_erreur_enregistrement, true); }
    });

    const btnLocAttach = document.getElementById('fvLocAttachBtn');
    if (btnLocAttach) { btnLocAttach.addEventListener('click', fvOpenLocPickerModal); }
    const btnLocChange = document.getElementById('fvLocChangeBtn');
    if (btnLocChange) { btnLocChange.addEventListener('click', fvOpenLocPickerModal); }
    const btnLocDetach = document.getElementById('fvLocDetachBtn');
    if (btnLocDetach) {
        btnLocDetach.addEventListener('click', () => {
            const remplies = (fvData.composants || []).filter(c => c.valeur);
            if (remplies.length) {
                fvConfirm(I18N_PM.fv_confirm_detacher, () => fvLinkMachine(''));
            } else {
                fvLinkMachine('');
            }
        });
    }

    const selectTypeEquipement = document.getElementById('fv-type-equipement-select');
    if (selectTypeEquipement) {
        let previousValue = selectTypeEquipement.value;
        selectTypeEquipement.addEventListener('change', () => {
            const nouvelleValeur = selectTypeEquipement.value;
            const typeChoisi = (fvData.types_equipement || []).find(t => t.nom === nouvelleValeur);
            const nouveauxIds = (typeChoisi ? (fvData.type_equipement_composants || {})[typeChoisi.id] : null) || [];
            const nouveauxSet = new Set(nouveauxIds.map(String));
            const typesById = {};
            (fvData.composant_types || []).forEach(t => { typesById[t.id] = t.nom; });
            const perdus = (fvData.composants || []).filter(c => c.valeur && !nouveauxSet.has(String(c.composant_type_id)));

            const appliquer = async () => {
                await fvSauverRefsEnCours();
                const fd = new FormData();
                fd.append('action', 'update_type_machine');
                fd.append('zone_id', fvData.zone.id);
                fd.append('csrf_token', fvData.csrf_token);
                fd.append('type_equipement', nouvelleValeur);
                nouveauxIds.forEach(id => fd.append('composant_type_ids[]', id));
                const res = await fetch('fiche_vie_machine.php', { method: 'POST', body: fd });
                const data = await res.json();
                if (data.success) {
                    previousValue = nouvelleValeur;
                    await openFicheVie(fvData.zone.id);
                    document.querySelector('[data-pane="fv-pane-refs"]').click();
                    showToast(I18N_PM.fv_toast_categorie_maj);
                } else { showToast(data.error || I18N_PM.toast_erreur, true); }
            };
            if (perdus.length) {
                const noms = perdus.map(c => typesById[c.composant_type_id] || '?').join(', ');
                fvConfirm(I18N_PM.fv_confirm_changer_categorie.replace('{noms}', noms), appliquer, () => { selectTypeEquipement.value = previousValue; });
            } else {
                appliquer();
            }
        });
    }

    const btnOuvrirMateriel = document.getElementById('btnOuvrirMateriel');
    if (btnOuvrirMateriel) { btnOuvrirMateriel.addEventListener('click', fvOpenMaterielModal); }
}

// --- MODALE "AJOUTER DU MATÉRIEL" (cases à cocher pleine liste, séparée de la fiche) ---
const fvMaterielModal = document.getElementById('fvMaterielModal');
function fvOpenMaterielModal() {
    const tousTypes = fvData.composant_types || [];
    const idsCoches = new Set((fvData.composants || []).map(c => String(c.composant_type_id)));
    const list = document.getElementById('fvMaterielModalList');
    list.innerHTML = tousTypes.length
        ? tousTypes.map(t => `
            <label class="fv-materiel-chip ${idsCoches.has(String(t.id)) ? 'active' : ''}">
                <input type="checkbox" class="fv-materiel-check" value="${t.id}" ${idsCoches.has(String(t.id)) ? 'checked' : ''}>
                ${fvEsc(t.nom)}
            </label>`).join('')
        : `<p class="fv-empty" style="padding:6px 0;">${I18N_PM.fv_aucun_type_materiel}</p>`;
    list.querySelectorAll('.fv-materiel-check').forEach(chk => {
        chk.addEventListener('change', () => chk.closest('.fv-materiel-chip').classList.toggle('active', chk.checked));
    });
    fvMaterielModal.style.display = 'block';
}
document.getElementById('fvMaterielCancel').addEventListener('click', () => fvMaterielModal.style.display = 'none');
fvMaterielModal.addEventListener('click', (e) => { if (e.target === fvMaterielModal) fvMaterielModal.style.display = 'none'; });
document.getElementById('fvMaterielOk').addEventListener('click', () => {
    const cochesIds = Array.from(document.querySelectorAll('#fvMaterielModalList .fv-materiel-check:checked')).map(c => c.value);
    const cochesSet = new Set(cochesIds);
    const typesById = {};
    (fvData.composant_types || []).forEach(t => { typesById[t.id] = t.nom; });
    const perdus = (fvData.composants || []).filter(c => c.valeur && !cochesSet.has(String(c.composant_type_id)));
    const appliquer = async () => {
        await fvSauverRefsEnCours();
        const fd = new FormData();
        fd.append('action', 'update_composants_selection');
        fd.append('zone_id', fvData.zone.id);
        fd.append('csrf_token', fvData.csrf_token);
        cochesIds.forEach(id => fd.append('composant_type_ids[]', id));
        const res = await fetch('fiche_vie_machine.php', { method: 'POST', body: fd });
        const data = await res.json();
        if (data.success) {
            fvMaterielModal.style.display = 'none';
            await openFicheVie(fvData.zone.id);
            document.querySelector('[data-pane="fv-pane-refs"]').click();
            showToast(I18N_PM.fv_toast_materiel_maj);
        } else { showToast(data.error || I18N_PM.toast_erreur, true); }
    };
    if (perdus.length) {
        const noms = perdus.map(c => typesById[c.composant_type_id] || '?').join(', ');
        fvConfirm(I18N_PM.fv_confirm_decocher.replace('{noms}', noms).replace('{element}', perdus.length > 1 ? I18N_PM.fv_ces_elements : I18N_PM.fv_cet_element), appliquer);
    } else {
        appliquer();
    }
});

// --- MODALE "RATTACHEMENT PARC MACHINE" (recherche + tuiles + fil d'Ariane, un seul niveau
// affiché à la fois) — même mécanique que "Créer un bon d'intervention" dans maintenance.php,
// rejouée ici sur les données déjà chargées (fvData.machines_dispo) plutôt que des <select>.
// La modale est statique (persiste entre les ouvertures) donc le câblage des éléments fixes
// (recherche, fermeture) se fait UNE SEULE FOIS ici ; seul l'état de navigation (fvLocState)
// est réinitialisé à chaque ouverture via fvOpenLocPickerModal().
const fvLocPickerModal = document.getElementById('fvLocPickerModal');
const fvLocIcons = { usine: 'fa-industry', secteur: 'fa-diagram-project', ligne: 'fa-arrows-left-right-to-line', zone: 'fa-map-pin', machine: 'fa-microchip' };
let fvLocState = { usine: '', secteur: '', ligne: '', zone: '', machine_id: '' };

function fvLocUniqueSorted(arr) { return [...new Set(arr.filter(Boolean))].sort((a, b) => a.localeCompare(b, 'fr')); }
function fvLocMachinesAt(machines, u, s, l, z) { return machines.filter(m => m.usine === u && m.secteur === s && m.ligne === l && m.zone === z); }
function fvLocHasLigneChoices(machines, u, s) { return fvLocUniqueSorted(machines.filter(m => m.usine === u && m.secteur === s).map(m => m.ligne)).length > 0; }

function fvLocGoBackTo(level) {
    if (level === 'usine') { fvLocState.usine = ''; fvLocState.secteur = ''; fvLocState.ligne = ''; fvLocState.zone = ''; fvLocState.machine_id = ''; }
    else if (level === 'secteur') { fvLocState.secteur = ''; fvLocState.ligne = ''; fvLocState.zone = ''; fvLocState.machine_id = ''; }
    else if (level === 'ligne') { fvLocState.ligne = ''; fvLocState.zone = ''; fvLocState.machine_id = ''; }
    else if (level === 'zone') { fvLocState.zone = ''; fvLocState.machine_id = ''; }
    fvLocRender();
}

function fvLocRender() {
    const machines = fvData.machines_dispo || [];
    const breadcrumbEl = document.getElementById('fv-loc-breadcrumb');
    const tilesEl = document.getElementById('fv-loc-tiles');
    if (!breadcrumbEl || !tilesEl) return;

    const ligneHasChoices = (fvLocState.usine && fvLocState.secteur) ? fvLocHasLigneChoices(machines, fvLocState.usine, fvLocState.secteur) : false;
    const ligne = (ligneHasChoices && fvLocState.ligne) ? fvLocState.ligne : '';

    breadcrumbEl.innerHTML = '';
    const addCrumb = (label, val, level, isLast) => {
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'loc-crumb' + (val === '' ? ' is-current' : '');
        btn.textContent = val || label;
        btn.addEventListener('click', () => fvLocGoBackTo(level));
        breadcrumbEl.appendChild(btn);
        if (!isLast) {
            const sep = document.createElement('i');
            sep.className = 'fa-solid fa-chevron-right loc-crumb-sep';
            breadcrumbEl.appendChild(sep);
        }
    };
    addCrumb(I18N_PM.loc_crumb_usine, fvLocState.usine, 'usine', !fvLocState.usine);
    if (fvLocState.usine) addCrumb(I18N_PM.loc_crumb_secteur, fvLocState.secteur, 'secteur', !fvLocState.secteur);
    if (fvLocState.secteur && ligneHasChoices) addCrumb(I18N_PM.loc_crumb_ligne, ligne, 'ligne', !ligne);
    if (fvLocState.secteur && (!ligneHasChoices || ligne)) addCrumb(I18N_PM.loc_crumb_zone, fvLocState.zone, 'zone', true);

    let level;
    if (!fvLocState.usine) level = 'usine';
    else if (!fvLocState.secteur) level = 'secteur';
    else if (ligneHasChoices && !ligne) level = 'ligne';
    else if (!fvLocState.zone) level = 'zone';
    else level = 'machine';

    tilesEl.innerHTML = '';

    let options = [];
    if (level === 'usine') options = fvLocUniqueSorted(machines.map(m => m.usine));
    else if (level === 'secteur') options = fvLocUniqueSorted(machines.filter(m => m.usine === fvLocState.usine).map(m => m.secteur));
    else if (level === 'ligne') options = fvLocUniqueSorted(machines.filter(m => m.usine === fvLocState.usine && m.secteur === fvLocState.secteur).map(m => m.ligne));
    else if (level === 'zone') options = fvLocUniqueSorted(machines.filter(m => m.usine === fvLocState.usine && m.secteur === fvLocState.secteur && m.ligne === ligne).map(m => m.zone));
    else if (level === 'machine') options = fvLocMachinesAt(machines, fvLocState.usine, fvLocState.secteur, ligne, fvLocState.zone).map(m => m.nom_machine);

    if (options.length === 0 && level !== 'machine') {
        const msg = document.createElement('div');
        msg.className = 'loc-empty-msg';
        msg.textContent = I18N_PM.loc_aucune_option;
        tilesEl.appendChild(msg);
        return;
    }

    options.forEach(opt => {
        const tile = document.createElement('button');
        tile.type = 'button';
        tile.className = 'loc-tile';
        tile.innerHTML = `<div class="loc-tile-icon"><i class="fa-solid ${fvLocIcons[level]}"></i></div><div class="loc-tile-label">${fvEsc(opt)}</div>`;
        tile.addEventListener('click', () => {
            if (level === 'usine') { fvLocState.usine = opt; fvLocState.secteur = ''; fvLocState.ligne = ''; fvLocState.zone = ''; fvLocState.machine_id = ''; fvLocRender(); }
            else if (level === 'secteur') { fvLocState.secteur = opt; fvLocState.ligne = ''; fvLocState.zone = ''; fvLocState.machine_id = ''; fvLocRender(); }
            else if (level === 'ligne') { fvLocState.ligne = opt; fvLocState.zone = ''; fvLocState.machine_id = ''; fvLocRender(); }
            else if (level === 'zone') { fvLocState.zone = opt; fvLocState.machine_id = ''; fvLocRender(); }
            else if (level === 'machine') {
                const m = fvLocMachinesAt(machines, fvLocState.usine, fvLocState.secteur, ligne, fvLocState.zone).find(mm => mm.nom_machine === opt);
                if (m) fvLinkMachine(String(m.id));
            }
        });
        tilesEl.appendChild(tile);
    });

    // La machine cherchée n'existe pas encore dans le Parc Machine : on peut la créer ici,
    // directement dans l'emplacement déjà choisi, plutôt que d'aller sur une autre page.
    if (level === 'machine') {
        const addTile = document.createElement('button');
        addTile.type = 'button';
        addTile.className = 'loc-tile';
        addTile.style.borderStyle = 'dashed';
        addTile.style.color = 'var(--accent)';
        addTile.innerHTML = `<div class="loc-tile-icon"><i class="fa-solid fa-plus"></i></div><div class="loc-tile-label">${I18N_PM.loc_nouvelle_machine}</div>`;
        addTile.addEventListener('click', () => fvLocRenderCreateForm(ligne));
        tilesEl.appendChild(addTile);
    }
}

function fvLocRenderCreateForm(ligne) {
    const tilesEl = document.getElementById('fv-loc-tiles');
    const chemin = [fvLocState.usine, fvLocState.secteur, ligne, fvLocState.zone].filter(Boolean).join(' > ');
    tilesEl.innerHTML = `<div class="loc-empty-msg" style="text-align:left; background:#fff; border:2px dashed var(--line-strong); display:flex; flex-direction:column; gap:10px; padding:14px;">
        <div style="font-weight:700; color:var(--primary); font-size:0.8rem;">${I18N_PM.loc_nouvelle_machine_dans}<br>${fvEsc(chemin)}</div>
        <input type="text" id="fvNewMachineName" placeholder="${I18N_PM.loc_placeholder_nom_machine}" style="padding:8px 10px; border:1.5px solid var(--line-strong); border-radius:7px; font-family:inherit; font-size:0.82rem; box-sizing:border-box;">
        <div style="display:flex; gap:8px;">
            <button type="button" class="pm-btn" id="fvCancelNewMachine" style="flex:1; justify-content:center;">${I18N_PM.btn_annuler}</button>
            <button type="button" class="pm-btn primary" id="fvCreateNewMachine" style="flex:1; justify-content:center;"><i class="fa-solid fa-plus"></i> ${I18N_PM.loc_btn_creer}</button>
        </div>
    </div>`;
    const nameInput = document.getElementById('fvNewMachineName');
    nameInput.focus();
    document.getElementById('fvCancelNewMachine').addEventListener('click', fvLocRender);
    const doCreate = async () => {
        const nom = nameInput.value.trim();
        if (!nom) { nameInput.focus(); return; }
        await fvCreateAndLinkMachine(fvLocState.usine, fvLocState.secteur, ligne, fvLocState.zone, nom);
    };
    document.getElementById('fvCreateNewMachine').addEventListener('click', doCreate);
    nameInput.addEventListener('keydown', (e) => { if (e.key === 'Enter') { e.preventDefault(); doCreate(); } });
}

function fvOpenLocPickerModal() {
    const machines = fvData.machines_dispo || [];
    const current = fvData.zone.machine_id ? machines.find(m => String(m.id) === String(fvData.zone.machine_id)) : null;
    fvLocState = {
        usine: current ? current.usine : '', secteur: current ? current.secteur : '',
        ligne: current ? current.ligne : '', zone: current ? current.zone : '',
        machine_id: current ? String(current.id) : '',
    };
    const searchInputEl = document.getElementById('fv-loc-search-input');
    if (searchInputEl) { searchInputEl.value = ''; }
    const searchResultsEl = document.getElementById('fv-loc-search-results');
    if (searchResultsEl) { searchResultsEl.style.display = 'none'; searchResultsEl.innerHTML = ''; }
    fvLocPickerModal.style.display = 'block';
    if (current) { fvLocGoBackTo('zone'); } else { fvLocRender(); }
}
document.getElementById('fvLocPickerClose').addEventListener('click', () => fvLocPickerModal.style.display = 'none');
fvLocPickerModal.addEventListener('click', (e) => { if (e.target === fvLocPickerModal) fvLocPickerModal.style.display = 'none'; });

const fvLocSearchInputEl = document.getElementById('fv-loc-search-input');
const fvLocSearchResultsEl = document.getElementById('fv-loc-search-results');
if (fvLocSearchInputEl) {
    fvLocSearchInputEl.addEventListener('input', () => {
        const q = fvLocSearchInputEl.value.trim().toLowerCase();
        const machines = fvData.machines_dispo || [];
        if (!q) { fvLocSearchResultsEl.style.display = 'none'; fvLocSearchResultsEl.innerHTML = ''; return; }
        const matches = machines.filter(m => (m.nom_machine + ' ' + m.usine + ' ' + m.secteur + ' ' + (m.ligne || '') + ' ' + m.zone).toLowerCase().includes(q)).slice(0, 30);
        fvLocSearchResultsEl.innerHTML = matches.length === 0
            ? `<div class="loc-search-empty">${I18N_PM.loc_aucun_resultat}</div>`
            : matches.map(m => `<div class="loc-search-item" data-id="${m.id}">
                <div class="loc-search-item-name">${fvEsc(m.nom_machine)}</div>
                <div class="loc-search-item-path">${fvEsc([m.usine, m.secteur, m.ligne, m.zone].filter(Boolean).join(' > '))}</div>
              </div>`).join('');
        fvLocSearchResultsEl.querySelectorAll('.loc-search-item').forEach(item => {
            item.addEventListener('click', () => { fvLinkMachine(item.dataset.id); });
        });
        fvLocSearchResultsEl.style.display = 'block';
    });
    fvLocSearchInputEl.addEventListener('focus', () => { if (fvLocSearchInputEl.value.trim()) fvLocSearchResultsEl.style.display = 'block'; });
    fvLocSearchInputEl.addEventListener('blur', () => setTimeout(() => { fvLocSearchResultsEl.style.display = 'none'; }, 200));
}

async function fvLinkMachine(machineId) {
    await fvSauverRefsEnCours();
    const fd = new FormData();
    fd.append('action', 'link_machine');
    fd.append('zone_id', fvData.zone.id);
    fd.append('machine_id', machineId);
    fd.append('csrf_token', fvData.csrf_token);
    const res = await fetch('fiche_vie_machine.php', { method: 'POST', body: fd });
    const data = await res.json();
    if (data.success) {
        fvLocPickerModal.style.display = 'none';
        await openFicheVie(fvData.zone.id);
        document.querySelector('[data-pane="fv-pane-refs"]').click();
        showToast(I18N_PM.fv_toast_rattachement_maj);
    } else { showToast(data.error || I18N_PM.toast_erreur, true); }
}

async function fvCreateAndLinkMachine(usine, secteur, ligne, zone, nomMachine) {
    await fvSauverRefsEnCours();
    const fd = new FormData();
    fd.append('action', 'create_and_link_machine');
    fd.append('zone_id', fvData.zone.id);
    fd.append('usine', usine);
    fd.append('secteur', secteur);
    fd.append('ligne', ligne);
    fd.append('zone', zone);
    fd.append('nom_machine', nomMachine);
    fd.append('csrf_token', fvData.csrf_token);
    const res = await fetch('fiche_vie_machine.php', { method: 'POST', body: fd });
    const data = await res.json();
    if (data.success) {
        fvLocPickerModal.style.display = 'none';
        await openFicheVie(fvData.zone.id);
        document.querySelector('[data-pane="fv-pane-refs"]').click();
        showToast(I18N_PM.fv_toast_machine_creee);
    } else { showToast(data.error || I18N_PM.toast_erreur, true); }
}

function fvBiStatusSlug(statut) {
    if (!statut) return 'afaire';
    const s = statut.toString().toLowerCase().normalize('NFD').replace(new RegExp('[\\u0300-\\u036f]', 'g'), '').replace(/\s+/g, '');
    if (s.includes('refus')) return 'refuse';
    if (s.includes('cours')) return 'encours';
    if (s.includes('termine')) return 'termine';
    return 'afaire';
}
const FV_BI_STATUS_COLORS = {
    afaire: 'linear-gradient(135deg, #f39c12, #d35400)',
    encours: 'linear-gradient(135deg, #3498db, #2980b9)',
    termine: 'linear-gradient(135deg, #2ecc71, #27ae60)',
    refuse: 'linear-gradient(135deg, #7f1d1d, #991b1b)',
};

function fvRenderHisto() {
    const h = fvData.historique;
    if (!h) {
        return `<p class="fv-empty">${I18N_PM.fv_histo_non_rattache}${fvData.is_admin ? I18N_PM.fv_histo_rattacher_depuis : ''}</p>`;
    }
    const bons = h.bons || [];
    if (!bons.length) return `<p class="fv-empty">${I18N_PM.fv_histo_aucun_bi}</p>`;

    const nbTermines = bons.filter(b => fvBiStatusSlug(b.statut) === 'termine').length;
    const summary = I18N_PM.fv_histo_summary.replace('{n}', bons.length).replace('{t}', nbTermines);

    const FV_TYPE_STYLE = {
        'Préventif': { icon: 'fa-calendar-check', color: 'var(--accent)' },
        'Chantier': { icon: 'fa-person-digging', color: '#f39c12' },
    };
    const FV_TYPE_LABELS = { 'Préventif': I18N_PM_MAINT.type_preventif, 'Chantier': I18N_PM_MAINT.type_chantier, 'Curatif': I18N_PM_MAINT.type_curatif };
    // Mappe via le slug normalisé (fvBiStatusSlug, insensible aux accents/genre) plutôt qu'une
    // correspondance exacte de chaîne — au moins un bon en base a "Terminé" (masculin) au lieu du
    // "Terminée" (féminin) canonique utilisé partout ailleurs (bug préexistant, hors périmètre ici).
    const FV_STATUT_LABELS_BY_SLUG = { afaire: I18N_PM_MAINT.lib_afaire, encours: I18N_PM_MAINT.lib_encours, termine: I18N_PM_MAINT.lib_termine, refuse: I18N_PM_MAINT.lib_refuse };

    const cards = bons.map(b => {
        const slug = fvBiStatusSlug(b.statut);
        const isUrgent = b.prio === 'Urgent';
        const typeInfo = FV_TYPE_STYLE[b.type] || { icon: 'fa-screwdriver-wrench', color: 'var(--danger)' };
        const aCasse = (b.casse == 1 || b.casse === '1' || b.casse === true || b.casse === 'true');
        const estSousTraitant = (b.is_sous_traitant == 1 || b.is_sous_traitant === '1' || b.is_sous_traitant === true || b.is_sous_traitant === 'true');
        const crApercu = (b.compte_rendu || '').trim();
        return `
        <div class="fv-bi-card fv-bi-${slug}" onclick="ficheVieModal.style.display='none'; showDetailBI('${fvEsc(b.id)}')" title="${I18N_PM.fv_tooltip_ouvrir_rapport}">
            <div class="fv-bi-card-top">
                <span class="fv-bi-badge">${fvEsc(b.num_bi || ('#' + b.id))}</span>
                <span class="fv-bi-date"><i class="fa-solid fa-calendar-day"></i> ${fvDate(b.date)}</span>
                ${isUrgent ? `<span class="fv-bi-urgent"><i class="fa-solid fa-bell"></i> ${I18N_PM.fv_urgent}</span>` : ''}
                <span class="fv-bi-status" style="background:${FV_BI_STATUS_COLORS[slug]};">${fvEsc(FV_STATUT_LABELS_BY_SLUG[slug] || b.statut || I18N_PM_MAINT.lib_afaire)}</span>
                <i class="fa-solid fa-chevron-right fv-bi-chevron"></i>
            </div>
            <div class="fv-bi-desc">${fvEsc(b.description || I18N_PM.fv_sans_description)}</div>
            <div class="fv-bi-meta-row">
                <span class="fv-bi-type" style="color:${typeInfo.color};"><i class="fa-solid ${typeInfo.icon}"></i> ${fvEsc(FV_TYPE_LABELS[b.type] || b.type || I18N_PM_MAINT.type_curatif)}</span>
                ${b.tech ? `<span class="fv-bi-tech" style="margin-top:0;"><i class="fa-solid fa-user-gear"></i> ${fvEsc(b.tech)}</span>` : ''}
                ${aCasse ? `<span class="fv-bi-casse"><i class="fa-solid fa-bolt"></i> ${I18N_PM.fv_casse}</span>` : ''}
                ${estSousTraitant ? `<span class="fv-bi-st"><i class="fa-solid fa-helmet-safety"></i> ${I18N_PM.fv_sous_traitant}</span>` : ''}
            </div>
            ${crApercu ? `<div class="fv-bi-cr"><b>${I18N_PM.fv_compte_rendu}</b> ${fvEsc(crApercu.length > 220 ? crApercu.slice(0, 220) + '…' : crApercu)}</div>` : ''}
        </div>`;
    }).join('');

    return `<div class="fv-histo-summary">${summary}</div><div class="fv-bi-list">${cards}</div>`;
}

function fvStatutBadge(statut) {
    if (statut === 'ok') return '<span class="fv-statut-badge fv-statut-ok"><i class="fa-solid fa-check"></i> OK</span>';
    if (statut === 'pas_ok') return `<span class="fv-statut-badge fv-statut-pas_ok"><i class="fa-solid fa-xmark"></i> ${I18N_PM.fv_pas_ok}</span>`;
    return '<span class="fv-statut-badge fv-statut-pending">—</span>';
}

function fvRenderInterv() {
    const list = fvData.interventions || [];
    const table = list.length ? `<div class="fv-bi-list">${list.map(i => {
        const statutClass = i.statut === 'ok' ? 'fv-interv-ok' : (i.statut === 'pas_ok' ? 'fv-interv-pasok' : '');
        return `
        <div class="fv-interv-card ${statutClass}">
            <div class="fv-interv-card-top">
                <span class="fv-bi-date"><i class="fa-solid fa-calendar-day"></i> ${fvDate(i.date_intervention || i.date_saisie)}</span>
                ${i.technicien ? `<span class="fv-interv-tech-badge"><i class="fa-solid fa-user-gear"></i> ${fvEsc(i.technicien)}</span>` : ''}
                ${fvStatutBadge(i.statut)}
                <button type="button" class="row-btn danger btn-fv-del-interv" data-id="${i.id}" title="${I18N_PM.tooltip_supprimer}" style="margin-left:auto;"><i class="fa-solid fa-trash"></i></button>
            </div>
            <div class="fv-bi-desc">${fvEsc(i.texte).replace(/\n/g, '<br>')}</div>
        </div>`;
    }).join('')}</div>` : `<p class="fv-empty">${I18N_PM.fv_interv_aucune}</p>`;
    const today = new Date().toISOString().slice(0, 10);
    const techniciens = fvData.techniciens_dispo || [];
    return `
        <form id="fvIntervForm" style="margin-bottom:18px;">
            <div class="fv-grid fv-grid-3">
                <div class="fv-field">${I18N_PM.fv_interv_date}<input type="date" name="date_intervention" value="${today}" required></div>
                <div class="fv-field">${I18N_PM.fv_interv_technicien}
                    <select id="fvIntervTechSelect">
                        <option value="">${I18N_PM.fv_interv_selectionner}</option>
                        ${techniciens.map(t => `<option value="${fvEsc(t)}">${fvEsc(t)}</option>`).join('')}
                        <option value="__autre__">${I18N_PM.fv_interv_autre}</option>
                    </select>
                    <input type="text" name="technicien" id="fvIntervTechInput" placeholder="Nom(s)..." style="display:none; margin-top:6px;">
                </div>
                <div class="fv-field">${I18N_PM.fv_interv_statut}
                    <select name="statut">
                        <option value="">${I18N_PM.fv_interv_non_renseigne}</option>
                        <option value="ok">OK</option>
                        <option value="pas_ok">${I18N_PM.fv_pas_ok}</option>
                    </select>
                </div>
            </div>
            <div class="fv-field" style="margin-top:8px;">${I18N_PM.fv_interv_description}<textarea name="texte" rows="2" placeholder="${I18N_PM.fv_interv_placeholder}" required></textarea></div>
            <div style="margin-top:10px; text-align:right;">
                <button type="submit" class="pm-btn primary"><i class="fa-solid fa-plus"></i> ${I18N_PM.fv_interv_btn_ajouter}</button>
            </div>
        </form>
        ${table}`;
}
function fvWireInterv() {
    const techSelect = document.getElementById('fvIntervTechSelect');
    const techInput = document.getElementById('fvIntervTechInput');
    techSelect.addEventListener('change', () => {
        if (techSelect.value === '__autre__') {
            techInput.style.display = 'block';
            techInput.value = '';
            techInput.focus();
        } else {
            techInput.style.display = 'none';
            techInput.value = techSelect.value;
        }
    });
    document.getElementById('fvIntervForm').addEventListener('submit', async (e) => {
        e.preventDefault();
        const fd = new FormData(e.target);
        fd.append('action', 'add_intervention');
        fd.append('zone_id', fvData.zone.id);
        fd.append('csrf_token', fvData.csrf_token);
        const res = await fetch('fiche_vie_machine.php', { method: 'POST', body: fd });
        const data = await res.json();
        if (data.success) { await openFicheVie(fvData.zone.id); document.querySelector('[data-pane="fv-pane-interv"]').click(); showToast(I18N_PM.fv_interv_toast_ajoutee); }
        else { showToast(data.error || I18N_PM.toast_erreur, true); }
    });
    document.querySelectorAll('.btn-fv-del-interv').forEach(btn => {
        btn.addEventListener('click', () => {
            fvConfirm(I18N_PM.fv_interv_confirm_suppr, async () => {
                const fd = new FormData();
                fd.append('action', 'delete_intervention');
                fd.append('zone_id', fvData.zone.id);
                fd.append('interv_id', btn.dataset.id);
                fd.append('csrf_token', fvData.csrf_token);
                const res = await fetch('fiche_vie_machine.php', { method: 'POST', body: fd });
                const data = await res.json();
                if (data.success) { await openFicheVie(fvData.zone.id); document.querySelector('[data-pane="fv-pane-interv"]').click(); showToast(I18N_PM.fv_interv_toast_supprimee); }
                else { showToast(data.error || I18N_PM.toast_erreur, true); }
            });
        });
    });
}

function fvRenderMemo() {
    const list = fvData.pannes_memo || [];
    const summary = list.length ? `<div class="fv-histo-summary">${I18N_PM.fv_memo_summary.replace('{n}', list.length)}</div>` : '';
    const items = list.length
        ? list.map(p => `
            <div class="fv-memo-item" data-id="${p.id}">
                <div class="fv-memo-head">
                    <span class="meta"><i class="fa-solid fa-user"></i> ${fvEsc(p.auteur)} · ${fvDate(p.date_saisie)}</span>
                    <span class="fv-memo-head-actions">
                        <button type="button" class="row-btn btn-fv-edit-memo" title="${I18N_PM.tooltip_modifier}"><i class="fa-solid fa-pen"></i></button>
                        ${fvData.is_admin ? `<button type="button" class="row-btn danger btn-fv-del-memo" title="${I18N_PM.tooltip_supprimer}"><i class="fa-solid fa-trash"></i></button>` : ''}
                    </span>
                </div>
                <div class="fv-memo-block">
                    <div class="fv-memo-icon constat"><i class="fa-solid fa-triangle-exclamation"></i></div>
                    <div><div class="fv-memo-label">${I18N_PM.fv_memo_panne_constatee}</div><div class="fv-memo-text">${fvEsc(p.constat).replace(/\n/g, '<br>')}</div></div>
                </div>
                ${p.solution ? `<div class="fv-memo-block">
                    <div class="fv-memo-icon solution"><i class="fa-solid fa-check"></i></div>
                    <div><div class="fv-memo-label">${I18N_PM.fv_memo_solution}</div><div class="fv-memo-text">${fvEsc(p.solution).replace(/\n/g, '<br>')}</div></div>
                </div>` : ''}
            </div>`).join('')
        : `<p class="fv-empty">${I18N_PM.fv_memo_aucune}</p>`;
    return `
        <div class="callout callout-tip" style="margin-bottom:14px;">
            <i class="fa-solid fa-circle-info"></i>
            <div><p>${I18N_PM.fv_memo_callout}</p></div>
        </div>
        <form id="fvMemoForm" style="margin-bottom:16px;">
            <div class="fv-field"><span>${I18N_PM.fv_memo_panne_constatee}</span><textarea name="constat" rows="2" placeholder="${I18N_PM.fv_memo_placeholder_constat}" required></textarea></div>
            <div class="fv-field" style="margin-top:8px;"><span>${I18N_PM.fv_memo_solution}</span><textarea name="solution" rows="2" placeholder="${I18N_PM.fv_memo_placeholder_solution}"></textarea></div>
            <div style="margin-top:10px; text-align:right;">
                <button type="submit" class="pm-btn primary"><i class="fa-solid fa-plus"></i> ${I18N_PM.fv_memo_btn_ajouter}</button>
            </div>
        </form>
        ${summary}${items}`;
}
function fvWireMemo() {
    document.getElementById('fvMemoForm').addEventListener('submit', async (e) => {
        e.preventDefault();
        const fd = new FormData(e.target);
        fd.append('action', 'add_memo');
        fd.append('zone_id', fvData.zone.id);
        fd.append('csrf_token', fvData.csrf_token);
        const res = await fetch('fiche_vie_machine.php', { method: 'POST', body: fd });
        const data = await res.json();
        if (data.success) { await openFicheVie(fvData.zone.id); document.querySelector('[data-pane="fv-pane-aide"]').click(); showToast(I18N_PM.fv_memo_toast_ajoute); }
        else { showToast(data.error || I18N_PM.toast_erreur, true); }
    });
    document.querySelectorAll('.btn-fv-edit-memo').forEach(btn => {
        btn.addEventListener('click', () => {
            const card = btn.closest('.fv-memo-item');
            const id = card.dataset.id;
            const p = (fvData.pannes_memo || []).find(m => String(m.id) === String(id));
            if (!p) return;
            card.innerHTML = `
                <div class="fv-field"><span>${I18N_PM.fv_memo_panne_constatee}</span><textarea rows="2" class="fv-memo-edit-constat">${fvEsc(p.constat)}</textarea></div>
                <div class="fv-field" style="margin-top:8px;"><span>${I18N_PM.fv_memo_solution}</span><textarea rows="2" class="fv-memo-edit-solution">${fvEsc(p.solution || '')}</textarea></div>
                <div style="margin-top:10px; display:flex; gap:8px; justify-content:flex-end;">
                    <button type="button" class="pm-btn ghost btn-fv-cancel-memo">${I18N_PM.btn_annuler}</button>
                    <button type="button" class="pm-btn primary btn-fv-save-memo">${I18N_PM.fv_btn_enregistrer}</button>
                </div>`;
            card.querySelector('.btn-fv-cancel-memo').addEventListener('click', () => renderFvPane('fv-pane-aide'));
            card.querySelector('.btn-fv-save-memo').addEventListener('click', async () => {
                const constat = card.querySelector('.fv-memo-edit-constat').value.trim();
                if (!constat) { showToast(I18N_PM.fv_memo_err_vide, true); return; }
                const fd = new FormData();
                fd.append('action', 'update_memo');
                fd.append('zone_id', fvData.zone.id);
                fd.append('memo_id', id);
                fd.append('constat', constat);
                fd.append('solution', card.querySelector('.fv-memo-edit-solution').value.trim());
                fd.append('csrf_token', fvData.csrf_token);
                const res = await fetch('fiche_vie_machine.php', { method: 'POST', body: fd });
                const data = await res.json();
                if (data.success) { await openFicheVie(fvData.zone.id); document.querySelector('[data-pane="fv-pane-aide"]').click(); showToast(I18N_PM.fv_memo_toast_modifie); }
                else { showToast(data.error || I18N_PM.toast_erreur, true); }
            });
        });
    });
    document.querySelectorAll('.btn-fv-del-memo').forEach(btn => {
        btn.addEventListener('click', () => {
            const id = btn.closest('.fv-memo-item').dataset.id;
            fvConfirm(I18N_PM.fv_memo_confirm_suppr, async () => {
                const fd = new FormData();
                fd.append('action', 'delete_memo');
                fd.append('zone_id', fvData.zone.id);
                fd.append('memo_id', id);
                fd.append('csrf_token', fvData.csrf_token);
                const res = await fetch('fiche_vie_machine.php', { method: 'POST', body: fd });
                const data = await res.json();
                if (data.success) { await openFicheVie(fvData.zone.id); document.querySelector('[data-pane="fv-pane-aide"]').click(); showToast(I18N_PM.fv_memo_toast_supprime); }
                else { showToast(data.error || I18N_PM.toast_erreur, true); }
            });
        });
    });
}

function fvDocIcon(ext) {
    const e = (ext || '').toLowerCase();
    if (e === 'pdf') return { icon: 'fa-file-pdf', color: '#e74c3c' };
    if (['jpg', 'jpeg', 'png'].includes(e)) return { icon: 'fa-file-image', color: '#9b59b6' };
    if (['doc', 'docx'].includes(e)) return { icon: 'fa-file-word', color: '#2980b9' };
    if (['xls', 'xlsx'].includes(e)) return { icon: 'fa-file-excel', color: '#27ae60' };
    return { icon: 'fa-file', color: 'var(--ink-500)' };
}
function fvRenderDocs() {
    const list = fvData.documents || [];
    const items = list.length
        ? `<div class="fv-bi-list">${list.map(d => {
            const ic = fvDocIcon(d.type_fichier);
            return `
            <div class="fv-doc-card">
                <div class="fv-doc-icon" style="background:${ic.color}1f; color:${ic.color};"><i class="fa-solid ${ic.icon}"></i></div>
                <div class="fv-doc-info">
                    <a href="${fvEsc(d.chemin)}" target="_blank">${fvEsc(d.nom_original)}</a>
                    <div class="fv-doc-meta">${fvEsc(d.uploade_par)} · ${fvDate(d.uploade_le)}</div>
                </div>
                ${fvData.is_admin ? `<button type="button" class="row-btn danger btn-fv-del-doc" data-id="${d.id}" title="${I18N_PM.tooltip_supprimer}"><i class="fa-solid fa-trash"></i></button>` : ''}
            </div>`;
        }).join('')}</div>`
        : `<p class="fv-empty">${I18N_PM.fv_docs_aucun}</p>`;
    const formHtml = fvData.is_admin ? `
        <form id="fvDocForm" style="margin-bottom:16px; display:flex; gap:8px; align-items:center;">
            <input type="file" name="document" required style="flex:1; font-size:0.8rem;">
            <button type="submit" class="pm-btn primary"><i class="fa-solid fa-upload"></i> ${I18N_PM.fv_docs_envoyer}</button>
        </form>` : `<p class="fv-empty" style="margin-bottom:12px;"><i class="fa-solid fa-lock"></i> ${I18N_PM.fv_docs_reserve_admin}</p>`;
    return `${formHtml}${items}`;
}
function fvWireDocs() {
    const form = document.getElementById('fvDocForm');
    if (form) form.addEventListener('submit', async (e) => {
        e.preventDefault();
        const fd = new FormData(e.target);
        fd.append('action', 'upload_document');
        fd.append('zone_id', fvData.zone.id);
        fd.append('csrf_token', fvData.csrf_token);
        const btn = e.target.querySelector('button');
        btn.disabled = true;
        const res = await fetch('fiche_vie_machine.php', { method: 'POST', body: fd });
        const data = await res.json();
        btn.disabled = false;
        if (data.success) { await openFicheVie(fvData.zone.id); document.querySelector('[data-pane="fv-pane-docs"]').click(); showToast(I18N_PM.fv_docs_toast_envoye); }
        else { showToast(data.error || I18N_PM.toast_erreur, true); }
    });
    document.querySelectorAll('.btn-fv-del-doc').forEach(btn => {
        btn.addEventListener('click', () => {
            fvConfirm(I18N_PM.fv_docs_confirm_suppr, async () => {
                const fd = new FormData();
                fd.append('action', 'delete_document');
                fd.append('zone_id', fvData.zone.id);
                fd.append('doc_id', btn.dataset.id);
                fd.append('csrf_token', fvData.csrf_token);
                const res = await fetch('fiche_vie_machine.php', { method: 'POST', body: fd });
                const data = await res.json();
                if (data.success) { await openFicheVie(fvData.zone.id); document.querySelector('[data-pane="fv-pane-docs"]').click(); showToast(I18N_PM.fv_docs_toast_supprime); }
                else { showToast(data.error || I18N_PM.toast_erreur, true); }
            });
        });
    });
}
function fvRenderDevis() {
    const list = fvData.devis || [];
    const items = list.length
        ? `<div class="fv-bi-list">${list.map(d => {
            const ic = fvDocIcon(d.type_fichier);
            return `
            <div class="fv-doc-card">
                <div class="fv-doc-icon" style="background:${ic.color}1f; color:${ic.color};"><i class="fa-solid ${ic.icon}"></i></div>
                <div class="fv-doc-info">
                    <a href="${fvEsc(d.chemin)}" target="_blank">${fvEsc(d.nom_original)}</a>
                    <div class="fv-doc-meta">${fvEsc(d.uploade_par)} · ${fvDate(d.uploade_le)}</div>
                </div>
                ${fvData.is_admin ? `<button type="button" class="row-btn danger btn-fv-del-devis" data-id="${d.id}" title="${I18N_PM.tooltip_supprimer}"><i class="fa-solid fa-trash"></i></button>` : ''}
            </div>`;
        }).join('')}</div>`
        : `<p class="fv-empty">${I18N_PM.fv_devis_aucun}</p>`;
    const formHtml = fvData.is_admin ? `
        <form id="fvDevisForm" style="margin-bottom:16px; display:flex; gap:8px; align-items:center;">
            <input type="file" name="document" required style="flex:1; font-size:0.8rem;">
            <button type="submit" class="pm-btn primary"><i class="fa-solid fa-upload"></i> ${I18N_PM.fv_docs_envoyer}</button>
        </form>` : `<p class="fv-empty" style="margin-bottom:12px;"><i class="fa-solid fa-lock"></i> ${I18N_PM.fv_devis_reserve_admin}</p>`;
    return `${formHtml}${items}`;
}
function fvWireDevis() {
    const form = document.getElementById('fvDevisForm');
    if (form) form.addEventListener('submit', async (e) => {
        e.preventDefault();
        const fd = new FormData(e.target);
        fd.append('action', 'upload_devis');
        fd.append('zone_id', fvData.zone.id);
        fd.append('csrf_token', fvData.csrf_token);
        const btn = e.target.querySelector('button');
        btn.disabled = true;
        const res = await fetch('fiche_vie_machine.php', { method: 'POST', body: fd });
        const data = await res.json();
        btn.disabled = false;
        if (data.success) { await openFicheVie(fvData.zone.id); document.querySelector('[data-pane="fv-pane-devis"]').click(); showToast(I18N_PM.fv_devis_toast_envoye); }
        else { showToast(data.error || I18N_PM.toast_erreur, true); }
    });
    document.querySelectorAll('.btn-fv-del-devis').forEach(btn => {
        btn.addEventListener('click', () => {
            fvConfirm(I18N_PM.fv_devis_confirm_suppr, async () => {
                const fd = new FormData();
                fd.append('action', 'delete_devis');
                fd.append('zone_id', fvData.zone.id);
                fd.append('doc_id', btn.dataset.id);
                fd.append('csrf_token', fvData.csrf_token);
                const res = await fetch('fiche_vie_machine.php', { method: 'POST', body: fd });
                const data = await res.json();
                if (data.success) { await openFicheVie(fvData.zone.id); document.querySelector('[data-pane="fv-pane-devis"]').click(); showToast(I18N_PM.fv_devis_toast_supprime); }
                else { showToast(data.error || I18N_PM.toast_erreur, true); }
            });
        });
    });
}

// --- EXPAND / COLLAPSE + PERSISTANCE ---
document.querySelectorAll('.tree-row').forEach(row => {
    row.addEventListener('click', (e) => {
        if (e.target.closest('.row-actions')) return;
        const node = row.parentElement;
        const kids = node.querySelector(':scope > .tree-children');
        if (!kids) return;
        node.classList.toggle('open');
        localStorage.setItem('pm_' + node.id, node.classList.contains('open') ? '1' : '0');
    });
});
document.querySelectorAll('.tree-node').forEach(node => {
    const stored = localStorage.getItem('pm_' + node.id);
    if (stored === '1') node.classList.add('open');
    else if (stored === '0') node.classList.remove('open');
});
document.getElementById('btnExpandAll').addEventListener('click', () => {
    document.querySelectorAll('.tree-node').forEach(n => { n.classList.add('open'); localStorage.setItem('pm_' + n.id, '1'); });
});
document.getElementById('btnCollapseAll').addEventListener('click', () => {
    document.querySelectorAll('.tree-node').forEach(n => { n.classList.remove('open'); localStorage.setItem('pm_' + n.id, '0'); });
});

// --- RECHERCHE + FILTRE PAR TYPE ---
let pmQuery = '';
let pmType = '';

function markText(el) {
    const original = el.dataset.original;
    if (!pmQuery) { el.textContent = original; return; }
    const norm = normalize(original);
    const i = norm.indexOf(pmQuery);
    if (i < 0) { el.textContent = original; return; }
    el.innerHTML = escapeHtml(original.slice(0, i)) + '<mark>' + escapeHtml(original.slice(i, i + pmQuery.length)) + '</mark>' + escapeHtml(original.slice(i + pmQuery.length));
}
function escapeHtml(s) { return s.replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c])); }

function computeVisibility(node) {
    const row = node.querySelector(':scope > .tree-row');
    const nameEl = row.querySelector('.n-name');
    markText(nameEl);
    const ownMatch = pmQuery && normalize(nameEl.dataset.original).includes(pmQuery);

    const kids = node.querySelector(':scope > .tree-children');
    let anyVisible = false;

    if (kids) {
        kids.querySelectorAll(':scope > .m-row').forEach(mrow => {
            const nameSpan = mrow.querySelector('.m-name');
            markText(nameSpan);
            const name = normalize(nameSpan.dataset.original);
            const type = mrow.dataset.type || '';
            const okQ = !pmQuery || name.includes(pmQuery);
            const okT = !pmType || type === pmType;
            const ok = okQ && okT;
            mrow.classList.toggle('is-hidden', !ok);
            if (ok) anyVisible = true;
        });
        kids.querySelectorAll(':scope > .tree-node').forEach(child => {
            if (computeVisibility(child)) anyVisible = true;
        });
    }

    const visible = anyVisible || (ownMatch && !pmType);
    node.classList.toggle('is-hidden', !visible);
    node.classList.toggle('filter-open', anyVisible && (!!pmQuery || !!pmType));
    return visible;
}

function applyFilters() {
    const active = !!pmQuery || !!pmType;
    let anyVisible = true;
    if (!active) {
        document.querySelectorAll('.tree-node, .m-row').forEach(el => el.classList.remove('is-hidden'));
        document.querySelectorAll('.tree-node').forEach(el => el.classList.remove('filter-open'));
        document.querySelectorAll('.n-name, .m-name').forEach(el => { el.textContent = el.dataset.original; });
    } else {
        anyVisible = false;
        document.querySelectorAll('#treeRoot > .tree-node').forEach(n => { if (computeVisibility(n)) anyVisible = true; });
    }
    document.getElementById('noResults').style.display = (active && !anyVisible) ? 'block' : 'none';
}

const searchInput = document.getElementById('searchInput');
const searchClear = document.getElementById('searchClear');
searchInput.addEventListener('input', (e) => {
    pmQuery = normalize(e.target.value.trim());
    searchClear.style.display = e.target.value ? 'flex' : 'none';
    applyFilters();
});
searchClear.addEventListener('click', () => {
    searchInput.value = '';
    pmQuery = '';
    searchClear.style.display = 'none';
    applyFilters();
    searchInput.focus();
});
document.querySelectorAll('.pm-chip').forEach(chip => {
    chip.addEventListener('click', () => {
        pmType = chip.dataset.type;
        document.querySelectorAll('.pm-chip').forEach(c => c.classList.toggle('active', c === chip));
        applyFilters();
    });
});

// --- HISTORIQUE DES BONS D'INTERVENTION D'UNE MACHINE ---
const biMachineModal = document.getElementById('biMachineModal');
let biMachineList = [];

function biStatusSlug(statut) {
    if (!statut) return 'afaire';
    const s = statut.toLowerCase();
    if (s.includes('attente')) return 'attente';
    if (s.includes('refus')) return 'refuse';
    if (s.includes('cours')) return 'encours';
    if (s.includes('termin')) return 'termine';
    return 'afaire';
}

function biBadgeHtml(statut) {
    const slug = biStatusSlug(statut);
    const fallback = { afaire: '#f39c12', encours: '#3498db', termine: '#27ae60', attente: '#95a5a6', refuse: '#e74c3c' };
    const couleur = (typeof LIBELLES_RAPPORT !== 'undefined' && LIBELLES_RAPPORT[slug] && LIBELLES_RAPPORT[slug].couleur) || fallback[slug] || '#7f8c8d';
    const labels = { afaire: I18N_PM_MAINT.lib_afaire, encours: I18N_PM_MAINT.lib_encours, termine: I18N_PM_MAINT.lib_termine, refuse: I18N_PM_MAINT.lib_refuse };
    const texte = labels[slug] || statut || I18N_PM_MAINT.lib_afaire;
    return `<span style="background:${couleur}; color:#fff; padding:3px 9px; border-radius:12px; font-size:0.65rem; font-weight:bold; text-transform:uppercase; white-space:nowrap; display:inline-block;">${texte}</span>`;
}

function renderBiMachineTable() {
    const body = document.getElementById('biMachineBody');
    const fTech = document.getElementById('biFilterTech').value;
    const fStatut = document.getElementById('biFilterStatut').value;

    const filtered = biMachineList.filter(t => {
        if (fTech && (t.tech || '') !== fTech) return false;
        if (fStatut && biStatusSlug(t.statut) !== fStatut) return false;
        return true;
    });

    if (filtered.length === 0) {
        body.innerHTML = `<p style="text-align:center; color:#94a3b8; padding:24px; font-style:italic;">${I18N_PM.bi_aucun}</p>`;
        return;
    }

    body.innerHTML = `<table style="width:100%; font-size:0.8rem; border-collapse:collapse; table-layout:fixed;">
        <colgroup>
            <col style="width:16%;"><col style="width:12%;"><col><col style="width:18%;"><col style="width:16%;">
        </colgroup>
        <thead>
            <tr style="background:#f8f9fa;">
                <th style="padding:8px; text-align:left;">${I18N_PM.bi_th_numero}</th>
                <th style="padding:8px;">${I18N_PM.bi_th_date}</th>
                <th style="padding:8px; text-align:left;">${I18N_PM.bi_th_description}</th>
                <th style="padding:8px;">${I18N_PM.bi_th_technicien}</th>
                <th style="padding:8px;">${I18N_PM.bi_th_statut}</th>
            </tr>
        </thead>
        <tbody>
            ${filtered.map(t => `
            <tr class="bi-row" data-id="${t.id}" style="border-bottom:1px solid #eee; cursor:pointer;" onmouseover="this.style.background='#f8fafc'" onmouseout="this.style.background=''">
                <td style="padding:8px; font-weight:bold; color:var(--gelpam-orange); white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">${t.num_bi || '-'}</td>
                <td style="padding:8px; text-align:center; white-space:nowrap;">${t.date ? t.date.split(' ')[0].split('-').reverse().join('/') : '-'}</td>
                <td style="padding:8px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;" title="${(t.description || '').replace(/"/g, '&quot;')}">${(t.description || '-').replace(/</g, '&lt;')}</td>
                <td style="padding:8px; text-align:center; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">${t.tech || '-'}</td>
                <td style="padding:8px; text-align:center;">${biBadgeHtml(t.statut)}</td>
            </tr>`).join('')}
        </tbody>
    </table>`;

    body.querySelectorAll('.bi-row').forEach(row => {
        row.addEventListener('click', () => {
            biMachineModal.style.display = 'none';
            showDetailBI(row.dataset.id);
        });
    });
}

document.querySelectorAll('.btn-bi').forEach(btn => {
    btn.addEventListener('click', async (e) => {
        e.stopPropagation();
        const nom = btn.dataset.name;
        const loc = [btn.dataset.usine, btn.dataset.secteur, btn.dataset.ligne, btn.dataset.zone].filter(Boolean).join(' > ');

        document.getElementById('biMachineTitle').textContent = nom;
        document.getElementById('biMachineLoc').textContent = loc;
        const body = document.getElementById('biMachineBody');
        body.innerHTML = `<p style="text-align:center; color:#94a3b8; padding:24px;"><i class="fa-solid fa-spinner fa-spin"></i> ${I18N_PM.chargement}</p>`;
        biMachineModal.style.display = 'block';

        try {
            const params = new URLSearchParams({
                equip: nom,
                usine: btn.dataset.usine || '',
                secteur: btn.dataset.secteur || '',
                ligne: btn.dataset.ligne || '',
                zone: btn.dataset.zone || ''
            });
            const res = await fetch('get_bi_machine.php?' + params.toString());
            const list = await res.json();
            biMachineList = Array.isArray(list) ? list : [];

            const techs = [...new Set(biMachineList.map(t => t.tech).filter(Boolean))].sort();
            const fTech = document.getElementById('biFilterTech');
            fTech.innerHTML = `<option value="">${I18N_PM.bi_tous_techniciens}</option>` + techs.map(t => `<option value="${t.replace(/"/g, '&quot;')}">${t}</option>`).join('');
            document.getElementById('biFilterStatut').value = '';

            if (biMachineList.length === 0) {
                document.getElementById('biMachineFilters').style.display = 'none';
                body.innerHTML = `<p style="text-align:center; color:#94a3b8; padding:24px; font-style:italic;">${I18N_PM.bi_aucun_pour_machine}</p>`;
                return;
            }
            document.getElementById('biMachineFilters').style.display = 'flex';
            renderBiMachineTable();
        } catch (e) {
            document.getElementById('biMachineFilters').style.display = 'none';
            body.innerHTML = `<p style="text-align:center; color:var(--danger); padding:24px;">${I18N_PM.bi_erreur_chargement}</p>`;
        }
    });
});
document.getElementById('biMachineClose').addEventListener('click', () => biMachineModal.style.display = 'none');
document.getElementById('biFilterTech').addEventListener('change', renderBiMachineTable);
document.getElementById('biFilterStatut').addEventListener('change', renderBiMachineTable);

// --- CRÉER UN BON D'INTERVENTION DEPUIS UNE MACHINE ---
document.querySelectorAll('.btn-creer-bi').forEach(btn => {
    btn.addEventListener('click', (e) => {
        e.stopPropagation();
        const params = new URLSearchParams({
            creer_bi: '1',
            usine: btn.dataset.usine || '',
            secteur: btn.dataset.secteur || '',
            ligne: btn.dataset.ligne || '',
            zone: btn.dataset.zone || '',
            equip: btn.dataset.name || ''
        });
        window.location.href = 'maintenance.php?' + params.toString();
    });
});
</script>

<?php if ($is_admin): ?>
<script>
// --- FORMULAIRE : champ "+ Nouveau..." ---
function check(id) {
    const s = document.getElementById(id + '-s');
    const n = document.getElementById(id + '-n');
    n.style.display = (s.value === 'autre') ? 'block' : 'none';
    if (s.value === 'autre') n.focus();
}

// --- PANNEAU LATÉRAL AJOUT / MODIF ---
const pmOverlay = document.getElementById('pmOverlay');
function openPanel() { pmOverlay.classList.add('show'); }
function closePanel() {
    pmOverlay.classList.remove('show');
    if (window.location.search.includes('edit=')) window.location.href = 'admin_machines.php';
}
function resetFormToBlank() {
    const idInput = document.querySelector('input[name="machine_id"]');
    if (idInput) idInput.remove();
    document.getElementById('f-action').value = 'add';
    ['u-s', 's-s', 'l-s', 'z-s', 'type-s'].forEach(id => document.getElementById(id).value = '');
    document.getElementById('f-nom').value = '';
    document.getElementById('panelTitle').textContent = I18N_PM.form_ajouter_machine;
    ['u', 's', 'l', 'z', 'type'].forEach(check);
    updateBreadcrumbPreview();
    syncTypeChips();
}
document.getElementById('btnAddTop').addEventListener('click', () => {
    resetFormToBlank();
    openPanel();
});

// Ouvre le panneau "Ajouter" en pré-remplissant directement l'emplacement (usine/secteur/ligne/zone).
function openAddAt(usine, secteur, ligne, zone) {
    resetFormToBlank();
    setSelectValue('u-s', usine || '');
    setSelectValue('s-s', secteur || '');
    setSelectValue('l-s', ligne || '');
    setSelectValue('z-s', zone || '');
    updateBreadcrumbPreview();
    openPanel();
    document.getElementById('f-nom').focus();
}

// --- APERÇU DU CHEMIN CHOISI ---
function updateBreadcrumbPreview() {
    const vals = ['u-s', 's-s', 'l-s', 'z-s'].map(id => {
        const v = document.getElementById(id).value;
        return (v && v !== 'autre') ? v : null;
    }).filter(Boolean);
    const el = document.getElementById('breadcrumbPreview');
    el.innerHTML = vals.length
        ? vals.map(v => '<b>' + v.replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c])) + '</b>').join(' <span class="sep">/</span> ')
        : `<span class="ph">${I18N_PM.form_breadcrumb_placeholder}</span>`;
}

// --- TUILES "TYPE D'ÉQUIPEMENT" (reliées au <select> caché type-s, qui reste la source pour le formulaire) ---
document.querySelectorAll('.type-chip').forEach(chip => {
    chip.addEventListener('click', () => {
        const sel = document.getElementById('type-s');
        const alreadyActive = chip.classList.contains('active');
        sel.value = alreadyActive ? '' : chip.dataset.value;
        sel.dispatchEvent(new Event('change'));
    });
});
function syncTypeChips() {
    const val = document.getElementById('type-s').value;
    document.querySelectorAll('.type-chip').forEach(c => c.classList.toggle('active', c.dataset.value === val));
}
document.querySelectorAll('.btn-add-here').forEach(btn => {
    btn.addEventListener('click', (e) => {
        e.stopPropagation();
        openAddAt(btn.dataset.usine, btn.dataset.secteur, btn.dataset.ligne, btn.dataset.zone);
    });
});

// Cliquer sur une machine déjà présente = ouvrir sa propre fiche (édition), pas un panneau d'ajout.
// Pour ajouter une machine, on passe désormais par le "+" de la catégorie (usine/secteur/ligne/zone).
document.querySelectorAll('.m-row').forEach(mrow => {
    mrow.addEventListener('click', (e) => {
        if (e.target.closest('.row-actions')) return;
        if (document.getElementById('treeRoot').classList.contains('select-mode')) {
            if (e.target.classList.contains('m-checkbox')) return;
            const cb = mrow.querySelector('.m-checkbox');
            if (cb) { cb.checked = !cb.checked; cb.dispatchEvent(new Event('change')); }
            return;
        }
        window.location.href = 'admin_machines.php?edit=' + mrow.dataset.id + '#panel';
    });
});

function setSelectValue(id, value) {
    const sel = document.getElementById(id);
    const hasOption = Array.from(sel.options).some(o => o.value === value);
    sel.value = hasOption ? value : '';
}
document.getElementById('panelClose').addEventListener('click', closePanel);
document.getElementById('panelCancel').addEventListener('click', closePanel);
<?php if ($machine_edit): ?>
document.getElementById('panelTitle').textContent = I18N_PM.form_modifier_machine;
updateBreadcrumbPreview();
syncTypeChips();
openPanel();
<?php endif; ?>

// --- MÉMORISER L'EMPLACEMENT POUR ENCHAÎNER PLUSIEURS AJOUTS SANS TOUT RESAISIR ---
const PM_LAST_LOCATION_KEY = 'pm_last_location';
function resolvedFieldValue(prefix) {
    const s = document.getElementById(prefix + '-s').value;
    if (s === 'autre') return document.getElementById(prefix + '-n').value.trim();
    return s;
}
document.getElementById('machineForm').addEventListener('submit', () => {
    if (document.getElementById('f-action').value === 'add') {
        sessionStorage.setItem(PM_LAST_LOCATION_KEY, JSON.stringify({
            usine: resolvedFieldValue('u'), secteur: resolvedFieldValue('s'),
            ligne: resolvedFieldValue('l'), zone: resolvedFieldValue('z')
        }));
    } else {
        sessionStorage.removeItem(PM_LAST_LOCATION_KEY);
    }
});
<?php if (isset($_GET['success']) && $_GET['success'] == 1): ?>
(function () {
    const raw = sessionStorage.getItem(PM_LAST_LOCATION_KEY);
    if (!raw) return;
    try {
        const loc = JSON.parse(raw);
        openAddAt(loc.usine, loc.secteur, loc.ligne, loc.zone);
    } catch (e) {}
})();
<?php endif; ?>

// --- RENOMMAGE ---
const renameModal = document.getElementById('renameModal');
let renameCtx = null;
document.querySelectorAll('.btn-rename').forEach(btn => {
    btn.addEventListener('click', (e) => {
        e.stopPropagation();
        renameCtx = {
            type: btn.dataset.type,
            old: btn.dataset.old,
            parentU: btn.dataset.parentU || '',
            parentS: btn.dataset.parentS || '',
            parentL: btn.dataset.parentL || '',
        };
        document.getElementById('renameInput').value = renameCtx.old;
        renameModal.style.display = 'block';
        document.getElementById('renameInput').focus();
        document.getElementById('renameInput').select();
    });
});
document.getElementById('renameCancel').addEventListener('click', () => renameModal.style.display = 'none');
document.getElementById('renameOk').addEventListener('click', () => {
    const newName = document.getElementById('renameInput').value.trim();
    if (!newName || !renameCtx || newName === renameCtx.old) { renameModal.style.display = 'none'; return; }
    const fd = new FormData();
    fd.append('action', 'rename_node');
    fd.append('node_type', renameCtx.type);
    fd.append('old_name', renameCtx.old);
    fd.append('new_name', newName);
    fd.append('parent_usine', renameCtx.parentU);
    fd.append('parent_secteur', renameCtx.parentS);
    fd.append('parent_ligne', renameCtx.parentL);
    fetch('admin_machines.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            if (data.success) { location.reload(); }
            else { showToast(I18N_PM.rename_err + data.error, true); }
        });
});

// --- SUPPRESSION MACHINE ---
const confirmModal = document.getElementById('confirmModal');
let deleteId = null;
document.querySelectorAll('.btn-delete').forEach(btn => {
    btn.addEventListener('click', (e) => {
        e.stopPropagation();
        deleteId = btn.dataset.id;
        document.getElementById('confirmText').textContent = I18N_PM.delete_confirm_texte.replace('{nom}', btn.dataset.name);
        confirmModal.style.display = 'block';
    });
});
document.getElementById('confirmCancel').addEventListener('click', () => confirmModal.style.display = 'none');
document.getElementById('confirmOk').addEventListener('click', () => {
    if (deleteId) window.location.href = 'admin_machines.php?delete=' + deleteId;
});


// --- SÉLECTION MULTIPLE + ASSIGNATION GROUPÉE D'UN TYPE ---
const treeRootEl = document.getElementById('treeRoot');
const btnSelectMode = document.getElementById('btnSelectMode');
const bulkBar = document.getElementById('bulkBar');
let selectedIds = new Set();

function updateBulkBar() {
    const n = selectedIds.size;
    bulkBar.style.display = n > 0 ? 'flex' : 'none';
    document.getElementById('bulkCount').textContent = n + ' ' + (n > 1 ? I18N_PM.bulk_machines_selectionnees : I18N_PM.bulk_machine_selectionnee);
}

btnSelectMode.addEventListener('click', () => {
    const active = treeRootEl.classList.toggle('select-mode');
    btnSelectMode.classList.toggle('active');
    if (!active) {
        selectedIds.clear();
        document.querySelectorAll('.m-checkbox').forEach(cb => cb.checked = false);
        document.querySelectorAll('.m-row.m-selected').forEach(r => r.classList.remove('m-selected'));
        updateBulkBar();
    }
});

document.querySelectorAll('.m-checkbox').forEach(cb => {
    cb.addEventListener('click', (e) => e.stopPropagation());
    cb.addEventListener('change', () => {
        const id = cb.dataset.id;
        const row = cb.closest('.m-row');
        if (cb.checked) { selectedIds.add(id); row.classList.add('m-selected'); }
        else { selectedIds.delete(id); row.classList.remove('m-selected'); }
        updateBulkBar();
    });
});

document.getElementById('btnBulkClear').addEventListener('click', () => {
    selectedIds.clear();
    document.querySelectorAll('.m-checkbox').forEach(cb => cb.checked = false);
    document.querySelectorAll('.m-row.m-selected').forEach(r => r.classList.remove('m-selected'));
    updateBulkBar();
});

// --- MODALE D'ASSIGNATION GROUPÉE ---
const bulkModal = document.getElementById('bulkModal');
const bulkTypeNewInput = document.getElementById('bulkTypeNew');
let bulkType = '';
document.getElementById('btnBulkAssign').addEventListener('click', () => {
    document.getElementById('bulkModalHint').textContent = I18N_PM.bulk_hint_dynamique.replace('{n}', selectedIds.size);
    bulkType = '';
    document.querySelectorAll('#bulkTypeGrid .type-chip').forEach(c => c.classList.remove('active'));
    bulkTypeNewInput.value = '';
    bulkTypeNewInput.style.display = 'none';
    bulkModal.style.display = 'block';
});
document.querySelectorAll('#bulkTypeGrid .type-chip').forEach(chip => {
    chip.addEventListener('click', () => {
        if (chip.dataset.value === 'autre') {
            document.querySelectorAll('#bulkTypeGrid .type-chip').forEach(c => c.classList.remove('active'));
            chip.classList.add('active');
            bulkType = 'autre';
            bulkTypeNewInput.style.display = 'block';
            bulkTypeNewInput.focus();
            return;
        }
        bulkTypeNewInput.style.display = 'none';
        bulkTypeNewInput.value = '';
        const already = chip.classList.contains('active');
        document.querySelectorAll('#bulkTypeGrid .type-chip').forEach(c => c.classList.remove('active'));
        bulkType = already ? '' : chip.dataset.value;
        if (bulkType) chip.classList.add('active');
    });
});
document.getElementById('bulkCancel').addEventListener('click', () => bulkModal.style.display = 'none');
document.getElementById('bulkOk').addEventListener('click', () => {
    const resolvedType = (bulkType === 'autre') ? bulkTypeNewInput.value.trim() : bulkType;
    if (bulkType === 'autre' && !resolvedType) { showToast(I18N_PM.bulk_type_manquant, true); bulkTypeNewInput.focus(); return; }
    const fd = new FormData();
    fd.append('action', 'bulk_set_type');
    fd.append('ids', JSON.stringify(Array.from(selectedIds)));
    fd.append('type_select', bulkType || '');
    fd.append('type_new', resolvedType);
    fetch('admin_machines.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            if (data.success) { location.reload(); }
            else { showToast(I18N_PM.bulk_err + data.error, true); }
        });
});

// --- GLISSER-DÉPOSER ---
function saveOrderData(actionName, dataArray) {
    const fd = new FormData();
    fd.append('action', actionName);
    fd.append('order_data', JSON.stringify(dataArray));
    fetch('admin_machines.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => { if (data.success) showToast(I18N_PM.toast_ordre_enregistre); });
}

document.querySelectorAll('.sortable-m').forEach(el => {
    new Sortable(el, {
        group: 'machines', animation: 150, handle: '.m-row', filter: '.empty-zone',
        onEnd: function (evt) {
            const container = evt.to;
            const u = container.getAttribute('data-usine'), s = container.getAttribute('data-secteur'),
                  l = container.getAttribute('data-ligne'), z = container.getAttribute('data-zone');
            const data = [];
            container.querySelectorAll(':scope > .m-row').forEach((item, index) => {
                data.push({ id: item.getAttribute('data-id'), ordre: index, usine: u, secteur: s, ligne: l, zone: z });
            });
            saveOrderData('update_order', data);
        }
    });
});

// Réordonnancement Secteurs / Lignes / Zones : on relit les noms depuis les lignes voisines après chaque déplacement.
document.querySelectorAll('.lvl-usine > .tree-children').forEach(container => {
    new Sortable(container, {
        animation: 150, handle: ':scope > .lvl-secteur > .tree-row',
        onEnd: function (evt) {
            const usine = evt.to.closest('.lvl-usine').querySelector(':scope > .tree-row .n-name').dataset.original;
            const data = [];
            evt.to.querySelectorAll(':scope > .lvl-secteur').forEach((item, index) => {
                const name = item.querySelector(':scope > .tree-row .n-name').dataset.original;
                data.push({ usine: usine, secteur: name, ordre: index });
            });
            saveOrderData('update_secteur_order', data);
        }
    });
});
document.querySelectorAll('.lvl-secteur > .tree-children').forEach(container => {
    new Sortable(container, {
        animation: 150, handle: ':scope > .lvl-ligne > .tree-row',
        onEnd: function (evt) {
            const secteurNode = evt.to.closest('.lvl-secteur');
            const usineNode = secteurNode.closest('.lvl-usine');
            const usine = usineNode.querySelector(':scope > .tree-row .n-name').dataset.original;
            const secteur = secteurNode.querySelector(':scope > .tree-row .n-name').dataset.original;
            const data = [];
            evt.to.querySelectorAll(':scope > .lvl-ligne').forEach((item, index) => {
                const name = item.querySelector(':scope > .tree-row .n-name').dataset.original;
                data.push({ usine: usine, secteur: secteur, ligne: name, ordre: index });
            });
            saveOrderData('update_ligne_order', data);
        }
    });
});
document.querySelectorAll('.lvl-ligne > .tree-children').forEach(container => {
    new Sortable(container, {
        animation: 150, handle: ':scope > .lvl-zone > .tree-row',
        onEnd: function (evt) {
            const ligneNode = evt.to.closest('.lvl-ligne');
            const secteurNode = ligneNode.closest('.lvl-secteur');
            const usineNode = secteurNode.closest('.lvl-usine');
            const usine = usineNode.querySelector(':scope > .tree-row .n-name').dataset.original;
            const secteur = secteurNode.querySelector(':scope > .tree-row .n-name').dataset.original;
            const ligne = ligneNode.querySelector(':scope > .tree-row .n-name').dataset.original;
            const data = [];
            evt.to.querySelectorAll(':scope > .lvl-zone').forEach((item, index) => {
                const name = item.querySelector(':scope > .tree-row .n-name').dataset.original;
                data.push({ usine: usine, secteur: secteur, ligne: ligne, zone: name, ordre: index });
            });
            saveOrderData('update_zone_order', data);
        }
    });
});
</script>
<?php endif; ?>

<?php include 'composant_rapport.php'; ?>

</body>
</html>
