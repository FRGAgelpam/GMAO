<?php
require_once __DIR__ . '/session_init.php';
require 'db.php';

// --- SÉCURITÉ : Uniquement les Admins pour le Journal (Option B) ---
if (!isset($_SESSION['user']) || $_SESSION['role'] !== 'admin') {
    header("Location: maintenance.php");
    exit();
}

$is_admin = true;

// --- CRÉATION DE LA TABLE SI ELLE N'EXISTE PAS ---
try {
    $db->exec("CREATE TABLE IF NOT EXISTS logs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        date_log DATETIME DEFAULT CURRENT_TIMESTAMP,
        utilisateur VARCHAR(100),
        action VARCHAR(100),
        description TEXT
    )");
} catch (Exception $e) {}

// --- RÉCUPÉRATION DES LOGS ---
$logs = [];
try {
    // On récupère les 200 dernières actions pour avoir un bon historique
    $stmt = $db->query("SELECT * FROM logs ORDER BY date_log DESC LIMIT 200");
    if($stmt) {
        $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Exception $e) {}

// --- NOUVEAU : TRAITEMENT CHIRURGICAL DU VIDAGE DES LOGS ---
if (isset($_GET['action']) && $_GET['action'] === 'clear') {
    try {
        if (isset($db)) {
            $db->exec("DELETE FROM logs");
            header("Location: logs.php"); // Recharge la page proprement
            exit();
        }
    } catch (Exception $e) {}
}
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(langue_actuelle()); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title><?php echo htmlspecialchars(t('logs.page_title_tag')); ?></title>
    <link rel="icon" type="image/png" href="img/logo.png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Caveat:wght@600&family=Segoe+UI:wght@300;400;600;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #2c3e50; --accent: #3498db; --success: #2ecc71; 
            --danger: #e74c3c; --gelpam-green: #2ecc71; --gelpam-orange: #f39c12;
            --ardo-blue: #005696;
        }

        body { 
    margin: 0; 
    font-family: 'Segoe UI', sans-serif; 
    /* On change 'center center' par 'center 110px' pour décaler l'image vers le bas */
    background: linear-gradient(rgba(0, 0, 0, 0.2), rgba(0, 0, 0, 0.2)), url('img/fond.jpg') no-repeat center 0px fixed; 
    background-size: cover; 
    min-height: 100vh;
    padding-top: 98px;
}

        @keyframes pulse-dot { 0% { transform: scale(1); opacity: 0.6; } 100% { transform: scale(2.5); opacity: 0; } }

        /* --- HEADER & NAV (MENU MAÎTRE) --- */
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
        .btn-accueil:hover { background: rgba(0,0,0,0.06); }
        .btn-accueil-green:hover { background: rgba(46, 204, 113, 0.3); border-color: rgba(46, 204, 113, 0.6); }

        /* --- FILTRES --- */
        .container { max-width: 1200px; margin: 0 auto; padding: 0 15px 20px 15px; }
        .search-container { background: rgba(255,255,255,0.95); padding: 15px 20px; border-radius: 8px; display: flex; align-items: center; gap: 20px; box-shadow: 0 5px 15px rgba(0,0,0,0.1); margin-bottom: 20px; border-left: 5px solid var(--accent); flex-wrap: wrap; }
        .search-group { display: flex; align-items: center; gap: 10px; font-weight: 800; font-size: 0.75rem; color: var(--primary); text-transform: uppercase; }
        .search-group input, .search-group select { padding: 8px 12px; border: 1px solid #ccc; border-radius: 5px; font-family: 'Segoe UI', sans-serif; font-size: 0.85rem; outline: none; }
        .search-group input:focus, .search-group select:focus { border-color: var(--accent); }
        .btn-reset { background: #95a5a6; color: white; border: none; padding: 8px 15px; border-radius: 5px; font-weight: bold; cursor: pointer; transition: 0.2s; font-size: 0.8rem; }
        .btn-reset:hover { background: #7f8c8d; }

        /* --- TABLEAU LOGS COMPACT & PRO --- */
        .page-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px; background: rgba(255,255,255,0.9); padding: 15px 20px; border-radius: 8px; box-shadow: 0 5px 15px rgba(0,0,0,0.1); border-left: 5px solid var(--primary); }
        .page-title { font-family: 'Caveat', cursive; font-size: 1.8rem; color: var(--primary); margin: 0; }
        
        .table-container { background: white; border-radius: 8px; box-shadow: 0 5px 15px rgba(0,0,0,0.1); overflow: hidden; }
        table { width: 100%; border-collapse: collapse; }
        th { background: #f8f9fa; color: #666; padding: 12px 15px; text-align: left; font-size: 0.75rem; text-transform: uppercase; letter-spacing: 1px; border-bottom: 2px solid #eee; }
        td { padding: 10px 15px; border-bottom: 1px solid #f1f1f1; font-size: 0.85rem; color: #333; }
        tr:hover { background: rgba(52, 152, 219, 0.05); }
        
        

        .log-date { font-weight: 800; color: #555; font-size: 0.8rem; }
        .log-user { font-weight: 700; color: var(--primary); display: flex; align-items: center; gap: 8px; }
        
        /* Badges d'action dynamiques */
        .badge { padding: 4px 10px; border-radius: 20px; font-size: 0.7rem; font-weight: 800; text-transform: uppercase; display: inline-block; white-space: nowrap;}
        .action-add { background: rgba(46, 204, 113, 0.15); color: #27ae60; border: 1px solid rgba(46, 204, 113, 0.3); }
        .action-edit { background: rgba(52, 152, 219, 0.15); color: #2980b9; border: 1px solid rgba(52, 152, 219, 0.3); }
        .action-delete { background: rgba(231, 76, 60, 0.15); color: #c0392b; border: 1px solid rgba(231, 76, 60, 0.3); }
        .action-login { background: rgba(243, 156, 18, 0.15); color: #d35400; border: 1px solid rgba(243, 156, 18, 0.3); }
        .action-default { background: #eee; color: #666; border: 1px solid #ccc; }
        
        .empty-state { text-align: center; padding: 40px; color: #888; font-style: italic; }
        
        /* --- STYLE DU BOUTON CORRIGÉ --- */
        .btn-clear-logs { 
            background: var(--danger); 
            color: white; 
            border: none; 
            padding: 8px 15px; 
            border-radius: 5px; 
            font-weight: bold; 
            cursor: pointer; 
            transition: background 0.2s; 
            font-size: 0.8rem; 
            display: flex; 
            align-items: center; 
            gap: 8px; 
        }
        .btn-clear-logs:hover { background: #c0392b; }

        /* --- STYLE DE LA MODALE --- */
        .modal { display: none; position: fixed; z-index: 4000; left: 0; top: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.7); backdrop-filter: blur(5px); }
        .modal-content { animation: dropTop 0.3s cubic-bezier(0.175, 0.885, 0.32, 1.275) forwards; }
        @keyframes dropTop { 0% { transform: translateY(-50px); opacity: 0; } 100% { transform: translateY(0); opacity: 1; } }
        
    </style>
<?php include 'pwa_head.php'; ?>
</head>
<body onclick="checkCloseSidebar(event)">

<?php $breadcrumb_label = t('logs.breadcrumb'); include 'navbar.php'; ?>

<div class="container">
    <div class="page-header">
        <h1 class="page-title"><i class="fa-solid fa-clock-rotate-left" style="color:var(--primary); margin-right:10px;"></i> <?php echo htmlspecialchars(t('logs.h1')); ?></h1>

        <button class="btn-clear-logs" onclick="confirmClearLogs()"><i class="fa-solid fa-trash-can"></i> <?php echo htmlspecialchars(t('logs.btn_clear')); ?></button>
    </div>

    <div class="search-container">
        <div class="search-group">
            <i class="fa-solid fa-user"></i> <?php echo htmlspecialchars(t('logs.filter_user')); ?>
            <input type="text" id="filter-user" placeholder="<?php echo htmlspecialchars(t('logs.filter_user_placeholder')); ?>" onkeyup="filterLogs()">
        </div>
        <div class="search-group">
            <i class="fa-solid fa-filter"></i> <?php echo htmlspecialchars(t('logs.filter_action')); ?>
            <select id="filter-action" onchange="filterLogs()">
                <option value=""><?php echo htmlspecialchars(t('logs.filter_action_all')); ?></option>
                <option value="ajout"><?php echo htmlspecialchars(t('logs.filter_action_add')); ?></option>
                <option value="modif"><?php echo htmlspecialchars(t('logs.filter_action_edit')); ?></option>
                <option value="suppr"><?php echo htmlspecialchars(t('logs.filter_action_delete')); ?></option>
                <option value="connexion"><?php echo htmlspecialchars(t('logs.filter_action_login')); ?></option>
            </select>
        </div>
        <div class="search-group">
            <i class="fa-solid fa-magnifying-glass"></i> <?php echo htmlspecialchars(t('logs.filter_search')); ?>
            <input type="text" id="filter-search" placeholder="<?php echo htmlspecialchars(t('logs.filter_search_placeholder')); ?>" onkeyup="filterLogs()">
        </div>
        <button class="btn-reset" onclick="resetFilters()"><i class="fa-solid fa-rotate-right"></i> <?php echo htmlspecialchars(t('logs.btn_reset')); ?></button>
    </div>

    <div class="table-container">
        <table id="logsTable">
            <thead>
                <tr>
                    <th style="width: 15%;"><?php echo htmlspecialchars(t('logs.th_date')); ?></th>
                    <th style="width: 15%;"><?php echo htmlspecialchars(t('logs.th_user')); ?></th>
                    <th style="width: 15%;"><?php echo htmlspecialchars(t('logs.th_action')); ?></th>
                    <th style="width: 55%;"><?php echo htmlspecialchars(t('logs.th_details')); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if(empty($logs)): ?>
                    <tr id="emptyRow">
                        <td colspan="4" class="empty-state"><?php echo htmlspecialchars(t('logs.empty')); ?></td>
                    </tr>
                <?php else: ?>
                    <?php foreach($logs as $log): 
                        // Formatage de la date (si au format Y-m-d H:i:s)
                        $date_log = date("d/m/Y H:i", strtotime($log['date_log']));
                        
                        // Attribution de la couleur du badge selon le mot-clé de l'action
                        $action = strtolower($log['action']);
                        $badge_class = 'action-default';
                        
                        // Détection pour le badge (et le filtrage JS)
                        $action_cat = ""; 
                        if(strpos($action, 'ajout') !== false || strpos($action, 'création') !== false) { $badge_class = 'action-add'; $action_cat = "ajout"; }
                        elseif(strpos($action, 'modif') !== false || strpos($action, 'mise à jour') !== false) { $badge_class = 'action-edit'; $action_cat = "modif"; }
                        elseif(strpos($action, 'suppr') !== false) { $badge_class = 'action-delete'; $action_cat = "suppr"; }
                        elseif(strpos($action, 'connexion') !== false || strpos($action, 'login') !== false || strpos($action, 'déconnexion') !== false) { $badge_class = 'action-login'; $action_cat = "connexion"; }
                    ?>
                    <tr class="log-row" data-action="<?php echo $action_cat; ?>">
                        <td class="log-date"><?php echo htmlspecialchars($date_log); ?></td>
                        <td class="log-user"><i class="fa-solid fa-user-circle" style="color:#bdc3c7;"></i> <span class="u-name"><?php echo htmlspecialchars($log['utilisateur']); ?></span></td>
                        <td><span class="badge <?php echo $badge_class; ?>"><?php echo htmlspecialchars($log['action']); ?></span></td>
                        <td class="log-desc" style="color:#666;"><?php echo htmlspecialchars($log['description']); ?></td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
// --- GESTION DE LA SIDEBAR ---
function openNav(e) { e.stopPropagation(); document.getElementById("mySidebar").style.width = "280px"; }
function closeNav() { document.getElementById("mySidebar").style.width = "0"; }
function checkCloseSidebar(event) { if (document.getElementById("mySidebar").style.width === "280px" && !document.getElementById("mySidebar").contains(event.target)) closeNav(); }

// --- GESTION DES FILTRES EN TEMPS RÉEL ---
function filterLogs() {
    const inputUser = document.getElementById("filter-user").value.toLowerCase();
    const selectAction = document.getElementById("filter-action").value.toLowerCase();
    const inputSearch = document.getElementById("filter-search").value.toLowerCase();
    
    const rows = document.querySelectorAll(".log-row");

    rows.forEach(row => {
        // Récupération des données de la ligne
        const userName = row.querySelector(".u-name").textContent.toLowerCase();
        const actionCat = row.getAttribute("data-action");
        const details = row.querySelector(".log-desc").textContent.toLowerCase();
        
        // Vérification des 3 conditions
        const matchUser = userName.includes(inputUser);
        const matchAction = (selectAction === "" || actionCat === selectAction);
        const matchSearch = details.includes(inputSearch);

        // Affichage ou masquage
        if (matchUser && matchAction && matchSearch) {
            row.style.display = ""; // Rétablit l'affichage par défaut (table-row)
        } else {
            row.style.display = "none";
        }
    });
}

// --- RÉINITIALISER LES FILTRES ---
function resetFilters() {
    document.getElementById("filter-user").value = "";
    document.getElementById("filter-action").value = "";
    document.getElementById("filter-search").value = "";
    filterLogs(); // Relance le filtrage pour tout réafficher
}

// --- GESTION DE LA MODALE PERSONNALISÉE ---
function confirmClearLogs() {
      document.getElementById('modalConfirmDel').style.display = 'block';
}

function closeConfirmDel() {
    document.getElementById('modalConfirmDel').style.display = 'none';
}
  

</script>

<div id="modalConfirmDel" class="modal" onclick="if(event.target == this) closeConfirmDel()">
    <div class="modal-content" style="max-width: 400px !important; margin: 12% auto !important; border-top: 6px solid var(--danger); text-align: center; padding: 30px; border-radius: 15px; background: white; position: relative;">
        <div style="color: var(--danger); font-size: 4rem; margin-bottom: 15px;">
            <i class="fa-solid fa-circle-exclamation"></i>
        </div>
        <h2 style="font-family: 'Caveat', cursive; font-size: 2.2rem; color: var(--primary); margin: 0 0 10px 0;"><?php echo htmlspecialchars(t('logs.modal_title')); ?></h2>
        <p style="color: #64748b; font-size: 0.95rem; line-height: 1.5; margin-bottom: 25px;">
            <?php echo htmlspecialchars(t('logs.modal_msg')); ?><br>
            <span style="font-weight: bold; color: var(--danger);"><?php echo htmlspecialchars(t('logs.modal_irreversible')); ?></span>
        </p>

        <div style="display: flex; gap: 10px; justify-content: center;">
            <button onclick="closeConfirmDel()" style="flex: 1; background: #f1f5f9; border: none; color: #64748b; padding: 12px; border-radius: 8px; font-weight: 700; cursor: pointer; font-family: inherit;">
                <?php echo htmlspecialchars(t('logs.btn_cancel')); ?>
            </button>
            <a href="logs.php?action=clear" style="flex: 1; background: var(--danger); border: none; color: white; padding: 12px; border-radius: 8px; font-weight: 700; cursor: pointer; text-decoration: none; display: inline-block; box-shadow: 0 4px 12px rgba(231, 76, 60, 0.2); font-family: inherit;">
                <?php echo htmlspecialchars(t('logs.btn_confirm_clear')); ?>
            </a>
        </div>
    </div>
</div>


</body>
</html>