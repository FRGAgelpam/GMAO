<?php
require_once __DIR__ . '/session_init.php';
require_once 'db.php';
require_once 'csrf.php';

// --- SÉCURITÉ : réservé aux admins ---
if (!isset($_SESSION['user']) || !isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: maintenance.php");
    exit();
}

try {
    $db->exec("CREATE TABLE IF NOT EXISTS idees_amelioration (
        id INT AUTO_INCREMENT PRIMARY KEY,
        service VARCHAR(255),
        demandeur VARCHAR(255),
        titre VARCHAR(255),
        description TEXT,
        categorie VARCHAR(50),
        statut VARCHAR(30) DEFAULT 'Nouvelle',
        date_creation DATETIME DEFAULT CURRENT_TIMESTAMP,
        reponse_admin TEXT NULL,
        date_reponse DATETIME NULL
    )");
    $db->exec("CREATE TABLE IF NOT EXISTS idees_messages (
        id INT AUTO_INCREMENT PRIMARY KEY,
        idee_id INT NOT NULL,
        expediteur VARCHAR(255),
        message TEXT,
        is_admin TINYINT(1) DEFAULT 0,
        lu TINYINT(1) DEFAULT 0,
        date_envoi DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
} catch (Exception $e) {}

$STATUTS_VALIDES = ['Nouvelle', "À l'étude", 'Acceptée', 'Réalisée', 'Rejetée'];
$STATUT_LABELS_I18N = [
    'Nouvelle' => t('ideesadmin.statut_nouvelle'),
    "À l'étude" => t('ideesadmin.statut_etude'),
    'Acceptée' => t('ideesadmin.statut_acceptee'),
    'Réalisée' => t('ideesadmin.statut_realisee'),
    'Rejetée' => t('ideesadmin.statut_rejetee'),
];
$message = "";

// --- TRAITEMENT : MISE À JOUR STATUT + ENVOI D'UN MESSAGE AU SERVICE ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_idee') {
    if (!csrf_verifie($_POST['csrf_token'] ?? '')) {
        $message = "<div class='alert danger'>" . htmlspecialchars(t('ideesadmin.session_expiree')) . "</div>";
    } else {
        $id = (int)($_POST['id'] ?? 0);
        $statut = in_array($_POST['statut'] ?? '', $STATUTS_VALIDES, true) ? $_POST['statut'] : 'Nouvelle';
        $reponse = trim($_POST['reponse_admin'] ?? '');

        if ($id) {
            try {
                $db->prepare("UPDATE idees_amelioration SET statut = ? WHERE id = ?")->execute([$statut, $id]);

                if ($reponse !== '') {
                    $db->prepare("INSERT INTO idees_messages (idee_id, expediteur, message, is_admin, lu) VALUES (?, ?, ?, 1, 0)")
                       ->execute([$id, $_SESSION['user'], $reponse]);
                }

                if (function_exists('ajouterLog')) {
                    ajouterLog($db, $_SESSION['user'], "Idées GMAO", "A mis à jour l'idée #$id (statut : $statut).");
                }
                $message = "<div class='alert success'><i class='fa-solid fa-check-circle'></i> " . htmlspecialchars(t('ideesadmin.idee_mise_a_jour')) . "</div>";
            } catch (Exception $e) {
                $message = "<div class='alert danger'>" . htmlspecialchars(t('ideesadmin.err_enregistrement')) . "</div>";
            }
        }
    }
}

// --- TRAITEMENT : SUPPRESSION D'UNE IDÉE ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_idee') {
    if (!csrf_verifie($_POST['csrf_token'] ?? '')) {
        $message = "<div class='alert danger'>" . htmlspecialchars(t('ideesadmin.session_expiree')) . "</div>";
    } else {
        $id = (int)($_POST['id'] ?? 0);
        if ($id) {
            try {
                $db->prepare("DELETE FROM idees_messages WHERE idee_id = ?")->execute([$id]);
                $stmt = $db->prepare("DELETE FROM idees_amelioration WHERE id = ?");
                $stmt->execute([$id]);
                if (function_exists('ajouterLog')) {
                    ajouterLog($db, $_SESSION['user'], "Idées GMAO", "A supprimé l'idée #$id.");
                }
                $message = "<div class='alert success'><i class='fa-solid fa-check-circle'></i> " . htmlspecialchars(t('ideesadmin.idee_supprimee')) . "</div>";
            } catch (Exception $e) {
                $message = "<div class='alert danger'>" . htmlspecialchars(t('ideesadmin.err_suppression')) . "</div>";
            }
        }
    }
}

// --- FILTRE PAR STATUT ---
$filtre = $_GET['statut'] ?? 'toutes';
if ($filtre !== 'toutes' && !in_array($filtre, $STATUTS_VALIDES, true)) { $filtre = 'toutes'; }

// --- COMPTEURS PAR STATUT (pour les onglets) ---
$compteurs = ['toutes' => 0, 'Nouvelle' => 0, "À l'étude" => 0, 'Acceptée' => 0, 'Réalisée' => 0, 'Rejetée' => 0];
try {
    $resComptage = $db->query("SELECT statut, COUNT(*) as nb FROM idees_amelioration GROUP BY statut")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($resComptage as $c) {
        if (isset($compteurs[$c['statut']])) { $compteurs[$c['statut']] = (int)$c['nb']; }
        $compteurs['toutes'] += (int)$c['nb'];
    }
} catch (Exception $e) {}

// --- LISTE DES IDÉES (selon filtre) ---
$idees = [];
try {
    if ($filtre === 'toutes') {
        $idees = $db->query("SELECT * FROM idees_amelioration ORDER BY date_creation DESC")->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $stmt = $db->prepare("SELECT * FROM idees_amelioration WHERE statut = ? ORDER BY date_creation DESC");
        $stmt->execute([$filtre]);
        $idees = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Exception $e) {}

// --- FIL DE MESSAGES DE CHAQUE IDÉE AFFICHÉE ---
$messagesParIdee = [];
try {
    if (!empty($idees)) {
        $ids = array_column($idees, 'id');
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmtMsg = $db->prepare("SELECT * FROM idees_messages WHERE idee_id IN ($placeholders) ORDER BY date_envoi ASC");
        $stmtMsg->execute($ids);
        foreach ($stmtMsg->fetchAll(PDO::FETCH_ASSOC) as $m) {
            $messagesParIdee[$m['idee_id']][] = $m;
        }
    }
    // Le fait de consulter cette page vaut lecture de tous les messages envoyés par les services.
    $db->exec("UPDATE idees_messages SET lu = 1 WHERE is_admin = 0 AND lu = 0");
} catch (Exception $e) {}

$jourSemaineMap = ['Sunday' => t('jour.dimanche'), 'Monday' => t('jour.lundi'), 'Tuesday' => t('jour.mardi'), 'Wednesday' => t('jour.mercredi'), 'Thursday' => t('jour.jeudi'), 'Friday' => t('jour.vendredi'), 'Saturday' => t('jour.samedi')];
function formatDateHeureIdee($dateStr, $jourSemaineMap) {
    if (!$dateStr) return '';
    $ts = strtotime($dateStr);
    $jour = $jourSemaineMap[date('l', $ts)] ?? '';
    return trim($jour . ' ' . date('d/m/Y', $ts) . ' ' . t('idee.date_at') . ' ' . date('H:i', $ts));
}
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(langue_actuelle()); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title><?php echo htmlspecialchars(t('ideesadmin.page_title_tag')); ?></title>
    <link rel="icon" type="image/png" href="img/logo.png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Caveat:wght@600;700&family=Segoe+UI:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #2c3e50; --accent: #3498db; --success: #2ecc71; --danger: #e74c3c;
            --gelpam-green: #2ecc71; --gelpam-orange: #f39c12; --purple: #9b59b6;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0; font-family: 'Segoe UI', sans-serif;
            background: linear-gradient(rgba(0, 0, 0, 0.2), rgba(0, 0, 0, 0.2)), url('img/fond.jpg') no-repeat center 0px fixed;
            background-size: cover; min-height: 100vh; padding-top: 98px;
        }

        @keyframes pulse-dot { 0% { transform: scale(1); opacity: 0.6; } 100% { transform: scale(2.5); opacity: 0; } }

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
        .tab-item.active { color: var(--accent); border-bottom: 3px solid var(--accent); background: rgba(52, 152, 219, 0.05); }

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

        .user-badge { background: rgba(255,255,255,0.12); backdrop-filter: blur(14px) brightness(1.15); -webkit-backdrop-filter: blur(14px) brightness(1.15); padding: 6px 14px; border-radius: 20px; font-size: 0.75rem; font-weight: 600; display: flex; align-items: center; gap: 8px; color: white; border: 1px solid rgba(255,255,255,0.4); text-shadow: 0 1px 3px rgba(0,0,0,0.4); box-shadow: 0 6px 16px rgba(0,0,0,0.2); }
        .status-pulse { width: 8px; height: 8px; border-radius: 50%; position: relative; background: var(--success); }
        .status-pulse::after { content: ""; position: absolute; width: 100%; height: 100%; border-radius: 50%; background: inherit; animation: pulse-dot 2s infinite; opacity: 0.6; }

        .container { max-width: 900px; margin: 0 auto; padding: 20px; }

        .page-head { display: flex; align-items: center; gap: 14px; margin-bottom: 18px; color: #fff; }
        .page-head i { font-size: 1.8rem; color: var(--gelpam-orange); text-shadow: 0 2px 10px rgba(0,0,0,0.4); }
        .page-head h1 { font-family: 'Caveat', cursive; font-size: 2.1rem; margin: 0; text-shadow: 0 2px 10px rgba(0,0,0,0.4); min-width: 0; }

        .alert { padding: 12px 16px; border-radius: 8px; font-size: 0.85rem; font-weight: 600; margin-bottom: 16px; background: #fff; box-shadow: 0 4px 14px rgba(0,0,0,0.2); }
        .alert.success { color: #1e8449; border-left: 4px solid var(--gelpam-green); }
        .alert.danger { color: #922b21; border-left: 4px solid var(--danger); }

        .filtres { display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 18px; }
        .filtre-btn {
            text-decoration: none; padding: 7px 14px; border-radius: 20px; font-size: 0.75rem; font-weight: 700;
            background: rgba(255,255,255,0.9); color: var(--primary); border: 1px solid rgba(255,255,255,0.5);
            display: flex; align-items: center; gap: 6px; transition: 0.2s;
        }
        .filtre-btn:hover { background: #fff; transform: translateY(-1px); }
        .filtre-btn.is-active { background: var(--primary); color: #fff; }
        .filtre-btn .cnt { background: rgba(0,0,0,0.12); padding: 1px 7px; border-radius: 10px; font-size: 0.68rem; }
        .filtre-btn.is-active .cnt { background: rgba(255,255,255,0.25); }

        .idee-card { background: rgba(255,255,255,0.97); border-radius: 14px; box-shadow: 0 10px 25px rgba(0,0,0,0.3); padding: 18px 20px; margin-bottom: 14px; border-left: 5px solid var(--gelpam-orange); transition: box-shadow 0.2s; }
        .idee-card-nouveau { border-left-color: #e84393; box-shadow: 0 10px 25px rgba(232,67,147,0.3); }
        .idee-top { display: flex; justify-content: space-between; align-items: flex-start; gap: 12px; cursor: pointer; }
        .idee-titre { font-weight: 700; color: var(--primary); font-size: 1.05rem; display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }

        .badge-nouveau-msg { font-size: 0.6rem; font-weight: 800; letter-spacing: 0.3px; color: #fff; background: #e84393; padding: 3px 9px; border-radius: 10px; display: inline-flex; align-items: center; gap: 5px; text-transform: uppercase; animation: pulse-nouveau-msg 1.6s ease-in-out infinite; }
        @keyframes pulse-nouveau-msg { 0%, 100% { opacity: 1; } 50% { opacity: 0.55; } }
        .idee-meta { font-size: 0.72rem; color: #94a3b8; margin-top: 3px; display: flex; gap: 10px; flex-wrap: wrap; }
        .idee-meta span { display: flex; align-items: center; gap: 4px; }
        .idee-desc { font-size: 0.85rem; color: #64748b; line-height: 1.5; margin: 10px 0; white-space: pre-line; }

        .idee-card-chevron { color: #cbd5e1; font-size: 0.9rem; transition: transform 0.2s ease; }
        .idee-card.is-open .idee-card-chevron { transform: rotate(180deg); }
        .idee-card-body { display: none; margin-top: 6px; }
        .idee-card.is-open .idee-card-body { display: block; }

        .statut-pill { font-size: 0.62rem; font-weight: 700; padding: 3px 10px; border-radius: 20px; color: #fff; white-space: nowrap; text-transform: uppercase; flex-shrink: 0; }
        .statut-nouvelle { background: var(--accent); }
        .statut-etude { background: #16a085; }
        .statut-acceptee { background: var(--gelpam-green); }
        .statut-realisee { background: var(--purple); }
        .statut-rejetee { background: var(--danger); }

        .btn-delete-idee { border: none; background: none; color: #cbd5e1; font-size: 0.95rem; cursor: pointer; padding: 4px 6px; border-radius: 6px; transition: 0.2s; }
        .btn-delete-idee:hover { color: var(--danger); background: rgba(231,76,60,0.1); }

        .idee-thread { display: flex; flex-direction: column; gap: 8px; margin: 10px 0; padding: 12px; background: #f8fafc; border-radius: 8px; border: 1px solid #eef2f5; max-height: 260px; overflow-y: auto; }
        .msg-bulle-admin { max-width: 80%; padding: 8px 11px; border-radius: 12px; font-size: 0.8rem; line-height: 1.4; box-shadow: 0 1px 3px rgba(15,23,42,0.08); }
        .msg-de-service { align-self: flex-start; background: #fff; border: 1px solid #e2e8f0; color: #334155; border-bottom-left-radius: 4px; }
        .msg-de-admin { align-self: flex-end; background: linear-gradient(135deg, #4f8ef7, #3d6fe0); color: #fff; border-bottom-right-radius: 4px; }
        .msg-auteur { font-size: 0.6rem; font-weight: 700; text-transform: uppercase; opacity: 0.7; margin-bottom: 2px; }
        .msg-date { font-size: 0.6rem; opacity: 0.65; margin-top: 4px; text-align: right; }

        .idee-admin-form { display: flex; gap: 10px; align-items: flex-end; flex-wrap: wrap; border-top: 1px dashed #e2e8f0; padding-top: 12px; margin-top: 8px; }
        .idee-admin-form .champ { display: flex; flex-direction: column; gap: 4px; }
        .idee-admin-form label { font-size: 0.65rem; font-weight: 700; color: #64748b; text-transform: uppercase; }
        .idee-admin-form select, .idee-admin-form textarea { padding: 8px 10px; border: 1px solid #ddd; border-radius: 6px; font-family: inherit; font-size: 0.82rem; }
        .idee-admin-form select { min-width: 140px; }
        .idee-admin-form textarea { flex: 1; min-width: 220px; resize: vertical; min-height: 38px; }
        .idee-admin-form button { padding: 9px 16px; border: none; border-radius: 8px; background: linear-gradient(135deg, #3ddc84, var(--gelpam-green)); color: #fff; font-weight: 700; cursor: pointer; font-size: 0.78rem; white-space: nowrap; transition: 0.2s; box-shadow: 0 3px 8px rgba(46,204,113,0.35); }
        .idee-admin-form button:hover { background: #27ae60; transform: translateY(-1px); box-shadow: 0 5px 12px rgba(46,204,113,0.45); }

        .empty-state { text-align: center; padding: 40px 20px; color: #fff; background: rgba(255,255,255,0.08); border-radius: 14px; }
        .empty-state i { font-size: 2rem; margin-bottom: 10px; display: block; opacity: 0.8; }
        .empty-state p { font-family: 'Caveat', cursive; font-size: 1.5rem; margin: 0; }

        /* --- MODALE DE CONFIRMATION DE SUPPRESSION --- */
        .modal-suppr {
            display: flex; position: fixed; z-index: 10000; left: 0; top: 0; width: 100%; height: 100%;
            background: rgba(15, 23, 42, 0.55); backdrop-filter: blur(4px);
            align-items: center; justify-content: center;
            opacity: 0; visibility: hidden; transition: opacity 0.2s ease, visibility 0.2s ease;
        }
        .modal-suppr.show { opacity: 1; visibility: visible; }
        .modal-suppr-content {
            background: white; width: 380px; max-width: 90%; padding: 30px; border-radius: 16px;
            text-align: center; border-top: 6px solid var(--danger); box-shadow: 0 20px 40px rgba(0,0,0,0.3);
            transform: scale(0.92); transition: transform 0.2s ease;
        }
        .modal-suppr.show .modal-suppr-content { transform: scale(1); }
        .modal-suppr-icon { color: var(--danger); font-size: 3.2rem; margin-bottom: 12px; }
        .modal-suppr-content h2 { font-family: 'Caveat', cursive; font-size: 2rem; color: var(--primary); margin: 0 0 10px; }
        .modal-suppr-content p { color: #64748b; font-size: 0.9rem; line-height: 1.5; margin: 0 0 22px; }
        .modal-suppr-warn { font-weight: 700; color: var(--danger); }
        .modal-suppr-actions { display: flex; gap: 10px; }
        .modal-suppr-actions button { flex: 1; padding: 11px; border: none; border-radius: 8px; font-weight: 700; font-size: 0.82rem; cursor: pointer; transition: 0.2s; font-family: inherit; }
        .btn-suppr-annuler { background: #f1f5f9; color: #64748b; }
        .btn-suppr-annuler:hover { background: #e2e8f0; }
        .btn-suppr-confirmer { background: var(--danger); color: white; box-shadow: 0 4px 10px rgba(231,76,60,0.35); }
        .btn-suppr-confirmer:hover { background: #c0392b; }
    </style>
<?php include 'pwa_head.php'; ?>
</head>
<body onclick="checkCloseSidebar(event)">

<?php $breadcrumb_label = t('ideesadmin.breadcrumb'); include 'navbar.php'; ?>

<div class="container">
    <div class="page-head">
        <i class="fa-solid fa-lightbulb"></i>
        <h1><?php echo htmlspecialchars(t('ideesadmin.h1')); ?></h1>
    </div>

    <?php echo $message; ?>

    <div class="filtres">
        <?php
        $labelsFiltres = ['toutes' => t('ideesadmin.statut_toutes')] + $STATUT_LABELS_I18N;
        foreach ($labelsFiltres as $val => $label):
        ?>
            <a class="filtre-btn <?php echo ($filtre === $val) ? 'is-active' : ''; ?>" href="idees_admin.php?statut=<?php echo urlencode($val); ?>">
                <?php echo htmlspecialchars($label); ?> <span class="cnt"><?php echo $compteurs[$val]; ?></span>
            </a>
        <?php endforeach; ?>
    </div>

    <?php if (empty($idees)): ?>
        <div class="empty-state">
            <i class="fa-solid fa-comment-dots"></i>
            <p><?php echo htmlspecialchars(t('ideesadmin.empty')); ?></p>
        </div>
    <?php else: ?>
        <?php foreach ($idees as $idee):
            $statutClass = 'statut-nouvelle';
            switch ($idee['statut']) {
                case "À l'étude": $statutClass = 'statut-etude'; break;
                case 'Acceptée': $statutClass = 'statut-acceptee'; break;
                case 'Réalisée': $statutClass = 'statut-realisee'; break;
                case 'Rejetée': $statutClass = 'statut-rejetee'; break;
            }

            $aNouveauMessage = false;
            foreach (($messagesParIdee[$idee['id']] ?? []) as $m) {
                if ((int)$m['is_admin'] === 0 && (int)$m['lu'] === 0) { $aNouveauMessage = true; break; }
            }
        ?>
        <div class="idee-card <?php echo $aNouveauMessage ? 'idee-card-nouveau' : ''; ?>">
            <div class="idee-top" onclick="toggleIdeeCard(this)">
                <div>
                    <div class="idee-titre">
                        <?php echo htmlspecialchars($idee['titre']); ?>
                        <?php if ($aNouveauMessage): ?><span class="badge-nouveau-msg"><i class="fa-solid fa-comment-dots"></i> <?php echo htmlspecialchars(t('ideesadmin.new_message')); ?></span><?php endif; ?>
                    </div>
                    <div class="idee-meta">
                        <span><i class="fa-solid fa-user"></i> <?php echo htmlspecialchars($idee['demandeur']); ?> (<?php echo htmlspecialchars($idee['service']); ?>)</span>
                        <span><i class="fa-solid fa-clock"></i> <?php echo formatDateHeureIdee($idee['date_creation'], $jourSemaineMap); ?></span>
                        <span><i class="fa-solid fa-tag"></i> <?php echo htmlspecialchars($idee['categorie']); ?></span>
                    </div>
                </div>
                <div style="display:flex; align-items:center; gap:10px; flex-shrink:0;">
                    <span class="statut-pill <?php echo $statutClass; ?>"><?php echo htmlspecialchars($STATUT_LABELS_I18N[$idee['statut']] ?? $idee['statut']); ?></span>
                    <form method="post" action="idees_admin.php<?php echo ($filtre !== 'toutes') ? '?statut=' . urlencode($filtre) : ''; ?>" onsubmit="return false;" class="form-delete-idee">
                        <input type="hidden" name="action" value="delete_idee">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>">
                        <input type="hidden" name="id" value="<?php echo (int)$idee['id']; ?>">
                        <button type="button" class="btn-delete-idee" title="<?php echo htmlspecialchars(t('ideesadmin.tooltip_delete')); ?>" data-titre="<?php echo htmlspecialchars($idee['titre']); ?>" onclick="event.stopPropagation(); demanderSuppressionIdee(this.closest('form'), this.dataset.titre)"><i class="fa-solid fa-trash-can"></i></button>
                    </form>
                    <i class="fa-solid fa-chevron-down idee-card-chevron"></i>
                </div>
            </div>

            <div class="idee-card-body">
                <div class="idee-desc"><?php echo htmlspecialchars($idee['description']); ?></div>

                <?php
                    $msgsIdee = $messagesParIdee[$idee['id']] ?? [];
                    // Rétro-compatibilité : les idées répondues avant le fil de discussion n'ont qu'un reponse_admin isolé.
                    if (empty($msgsIdee) && !empty($idee['reponse_admin'])) {
                        $msgsIdee[] = [
                            'expediteur' => t('chat.you'), 'message' => $idee['reponse_admin'], 'is_admin' => 1,
                            'date_envoi' => $idee['date_reponse'] ?? $idee['date_creation'],
                        ];
                    }
                ?>
                <?php if (!empty($msgsIdee)): ?>
                    <div class="idee-thread">
                        <?php foreach ($msgsIdee as $m): ?>
                            <div class="msg-bulle-admin <?php echo $m['is_admin'] ? 'msg-de-admin' : 'msg-de-service'; ?>">
                                <div class="msg-auteur"><?php echo $m['is_admin'] ? htmlspecialchars(t('chat.you')) : htmlspecialchars($idee['demandeur']); ?></div>
                                <div class="msg-texte"><?php echo nl2br(htmlspecialchars($m['message'])); ?></div>
                                <div class="msg-date"><?php echo formatDateHeureIdee($m['date_envoi'], $jourSemaineMap); ?></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <form class="idee-admin-form" method="post" action="idees_admin.php<?php echo ($filtre !== 'toutes') ? '?statut=' . urlencode($filtre) : ''; ?>">
                    <input type="hidden" name="action" value="update_idee">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>">
                    <input type="hidden" name="id" value="<?php echo (int)$idee['id']; ?>">
                    <div class="champ">
                        <label><?php echo htmlspecialchars(t('ideesadmin.label_statut')); ?></label>
                        <select name="statut">
                            <?php foreach ($STATUTS_VALIDES as $s): ?>
                                <option value="<?php echo htmlspecialchars($s); ?>" <?php echo ($idee['statut'] === $s) ? 'selected' : ''; ?>><?php echo htmlspecialchars($STATUT_LABELS_I18N[$s] ?? $s); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="champ" style="flex:1;">
                        <label><?php echo htmlspecialchars(t('ideesadmin.label_new_message_field')); ?></label>
                        <textarea name="reponse_admin" placeholder="<?php echo htmlspecialchars(t('ideesadmin.placeholder_reponse')); ?>"></textarea>
                    </div>
                    <button type="submit"><i class="fa-solid fa-check"></i> <?php echo htmlspecialchars(t('ideesadmin.btn_save')); ?></button>
                </form>
            </div>
        </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<div id="modalConfirmDelIdee" class="modal-suppr" onclick="if(event.target === this) fermerSuppressionIdee();">
    <div class="modal-suppr-content">
        <div class="modal-suppr-icon"><i class="fa-solid fa-circle-exclamation"></i></div>
        <h2><?php echo htmlspecialchars(t('ideesadmin.modal_del_title')); ?></h2>
        <p><?php echo htmlspecialchars(t('ideesadmin.modal_del_prefix')); ?> <b id="modal-suppr-titre"></b> ?<br><span class="modal-suppr-warn"><?php echo htmlspecialchars(t('ideesadmin.modal_irreversible')); ?></span></p>
        <div class="modal-suppr-actions">
            <button type="button" class="btn-suppr-annuler" onclick="fermerSuppressionIdee()"><?php echo htmlspecialchars(t('ideesadmin.btn_cancel')); ?></button>
            <button type="button" class="btn-suppr-confirmer" id="btn-suppr-confirmer"><?php echo htmlspecialchars(t('ideesadmin.btn_delete')); ?></button>
        </div>
    </div>
</div>

<script>
function openNav(e) { e.stopPropagation(); document.getElementById("mySidebar").style.width = "280px"; }
function closeNav() { document.getElementById("mySidebar").style.width = "0"; }
function checkCloseSidebar(event) { if (document.getElementById("mySidebar").style.width === "280px" && !document.getElementById("mySidebar").contains(event.target)) closeNav(); }

function toggleIdeeCard(topEl) {
    topEl.closest('.idee-card').classList.toggle('is-open');
}

let formeIdeeASupprimer = null;

function demanderSuppressionIdee(form, titre) {
    formeIdeeASupprimer = form;
    document.getElementById('modal-suppr-titre').innerText = '« ' + titre + ' »';
    document.getElementById('modalConfirmDelIdee').classList.add('show');
}

function fermerSuppressionIdee() {
    formeIdeeASupprimer = null;
    document.getElementById('modalConfirmDelIdee').classList.remove('show');
}

document.getElementById('btn-suppr-confirmer').onclick = function() {
    if (formeIdeeASupprimer) { formeIdeeASupprimer.submit(); }
};
</script>

</body>
</html>
