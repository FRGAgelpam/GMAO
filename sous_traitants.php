<?php
require_once __DIR__ . '/session_init.php';

if (!isset($_SESSION['user']) || !isset($_SESSION['role']) || !in_array($_SESSION['role'], ['admin', 'technicien'])) {
    header("Location: demande.php");
    exit();
}

$is_admin = ($_SESSION['role'] === 'admin');
require_once 'db.php';

// --- ACTION RAPIDE : GESTION PRÉSENCE ET STATUT ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_st'])) {
    try {
        $id = $_POST['id'];
        if(isset($_POST['st_sur_site'])) {
            $stmt = $db->prepare("UPDATE taches SET st_sur_site = ? WHERE id = ?");
            $stmt->execute([$_POST['st_sur_site'], $id]);
        }
        if(isset($_POST['statut']) && !empty($_POST['statut'])) {
            $stmt = $db->prepare("UPDATE taches SET statut = ? WHERE id = ?");
            $stmt->execute([$_POST['statut'], $id]);
        }
        echo "ok";
    } catch (Exception $e) {}
    exit;
}

$st_sur_site = [];
$st_a_venir = [];
$st_historique = [];

try {
    if(isset($db)) {
        // 1. Actuellement sur site
        $sqlSurSite = "SELECT t.*, e.nom as nom_entreprise, e.date_fin_pdp, e.fichier_pdp 
                       FROM taches t 
                       LEFT JOIN entreprises_ext e ON t.entreprise_ext_id = e.id 
                       WHERE t.is_sous_traitant = 1 AND t.st_sur_site = 1 AND t.statut NOT LIKE '%termin%' 
                       ORDER BY t.date DESC";
        $st_sur_site = $db->query($sqlSurSite)->fetchAll(PDO::FETCH_ASSOC);

        // 2. Planifié ou en Pause
        $sqlAVenir = "SELECT t.*, e.nom as nom_entreprise, e.date_fin_pdp, e.fichier_pdp 
                      FROM taches t 
                      LEFT JOIN entreprises_ext e ON t.entreprise_ext_id = e.id 
                      WHERE t.is_sous_traitant = 1 AND (t.st_sur_site = 0 OR t.st_sur_site IS NULL) AND t.statut NOT LIKE '%termin%' 
                      ORDER BY t.date ASC";
        $st_a_venir = $db->query($sqlAVenir)->fetchAll(PDO::FETCH_ASSOC);

        // 3. Historique
        $sqlHisto = "SELECT t.*, e.nom as nom_entreprise 
                     FROM taches t 
                     LEFT JOIN entreprises_ext e ON t.entreprise_ext_id = e.id 
                     WHERE t.is_sous_traitant = 1 AND t.statut LIKE '%termin%' 
                     ORDER BY t.date DESC LIMIT 100";
        $st_historique = $db->query($sqlHisto)->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Exception $e) {}

// --- MISE A JOUR AUTO DE LA BASE DE DONNÉES ---
try {
    if(isset($db)) {
        // Ajoute la colonne "type_pdp" automatiquement si elle n'existe pas encore
        $db->exec("ALTER TABLE entreprises_ext ADD COLUMN IF NOT EXISTS type_pdp VARCHAR(20) DEFAULT 'Annuel'");
    }
} catch (Exception $e) {}

// --- GESTION ANNUAIRE ET FICHIER ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_entreprise'])) {
    try {
        if (isset($db)) {
            $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        }

        if ($_POST['action_entreprise'] === 'save') {
            $id = !empty($_POST['id']) ? $_POST['id'] : null;
            $nom = $_POST['nom'];
            $contact = $_POST['contact'];
            $telephone = $_POST['telephone'];
            $mail = $_POST['mail'];
            $date_pdp = !empty($_POST['date_pdp']) ? $_POST['date_pdp'] : null;
            // NOUVEAU : Récupération du type de PdP
            $type_pdp = !empty($_POST['type_pdp']) ? $_POST['type_pdp'] : 'Annuel';

            $fichier_pdp = null;
            if (isset($_FILES['fichier_pdp']) && $_FILES['fichier_pdp']['error'] === UPLOAD_ERR_OK) {
                $uploadDir = 'uploads/pdp/';
                if (!is_dir($uploadDir)) { mkdir($uploadDir, 0755, true); }

                // SÉCURITÉ : seule l'extension .pdf est autorisée (un Plan de Prévention
                // est toujours un PDF) pour empêcher le dépôt d'un fichier exécutable.
                $ext = strtolower(pathinfo($_FILES['fichier_pdp']['name'], PATHINFO_EXTENSION));
                if ($ext === 'pdf') {
                    $fileName = 'pdp_' . time() . '_' . rand(100, 999) . '.' . $ext;
                    $uploadFile = $uploadDir . $fileName;

                    if (move_uploaded_file($_FILES['fichier_pdp']['tmp_name'], $uploadFile)) {
                        $fichier_pdp = $uploadFile;
                    }
                }
            }

            if ($id) {
                if ($fichier_pdp) {
                    $stmt = $db->prepare("UPDATE entreprises_ext SET nom=?, contact_nom=?, telephone=?, mail=?, date_fin_pdp=?, fichier_pdp=?, type_pdp=? WHERE id=?");
                    $stmt->execute([$nom, $contact, $telephone, $mail, $date_pdp, $fichier_pdp, $type_pdp, $id]);
                } else {
                    if (isset($_POST['supprimer_pdp']) && $_POST['supprimer_pdp'] === '1') {
                        $stmt = $db->prepare("UPDATE entreprises_ext SET nom=?, contact_nom=?, telephone=?, mail=?, date_fin_pdp=?, fichier_pdp=NULL, type_pdp=? WHERE id=?");
                        $stmt->execute([$nom, $contact, $telephone, $mail, $date_pdp, $type_pdp, $id]);
                    } else {
                        $stmt = $db->prepare("UPDATE entreprises_ext SET nom=?, contact_nom=?, telephone=?, mail=?, date_fin_pdp=?, type_pdp=? WHERE id=?");
                        $stmt->execute([$nom, $contact, $telephone, $mail, $date_pdp, $type_pdp, $id]);
                    }
                }
            } else {
                $stmt = $db->prepare("INSERT INTO entreprises_ext (nom, contact_nom, telephone, mail, date_fin_pdp, fichier_pdp, type_pdp) VALUES (?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$nom, $contact, $telephone, $mail, $date_pdp, $fichier_pdp, $type_pdp]);
            }
        } elseif ($_POST['action_entreprise'] === 'delete') {
            $stmt = $db->prepare("DELETE FROM entreprises_ext WHERE id=?");
            $stmt->execute([$_POST['id']]);
        }
        echo "ok";
    } catch (Exception $e) {
        http_response_code(500);
        error_log("sous_traitants.php: " . $e->getMessage());
        echo "Erreur serveur.";
    }
    exit;
}

$machines_db = (isset($db)) ? $db->query("SELECT usine, secteur, zone, nom_machine FROM machines ORDER BY usine, secteur, zone, ordre, nom_machine")->fetchAll(PDO::FETCH_ASSOC) : [];
$json_machines = json_encode($machines_db);
$toutes_entreprises = (isset($db)) ? $db->query("SELECT * FROM entreprises_ext ORDER BY nom")->fetchAll(PDO::FETCH_ASSOC) : [];
$json_historique = json_encode($st_historique);
?>

<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(langue_actuelle()); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <link rel="apple-touch-icon" href="img/logo.png">

    <title><?php echo htmlspecialchars(t('st.page_title_tag')); ?></title>
    <link rel="icon" type="image/png" href="img/logo.png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Caveat:wght@600&family=Segoe+UI:wght@300;400;600;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #2c3e50; --accent: #3498db; --success: #2ecc71; 
            --danger: #e74c3c; --gelpam-green: #2ecc71; --gelpam-orange: #f39c12;
            --ardo-blue: #005696; --stat-red: #c0392b; --purple: #9b59b6; --dark-blue: #2980b9;
            --soft-blue: #ebf5fb; --soft-orange: #fff5e6; --soft-green: #e8f8f5;
            --text-main: #455a64; --text-light: #90a4ae; --border-color: #eef2f5;
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

        header { position: fixed; top: 0; width: 100%; z-index: 1000; overflow: hidden; box-shadow: 0 4px 20px rgba(0,0,0,0.25); }
        header::before { content: ""; position: absolute; top: -12px; left: -12px; right: -12px; bottom: -12px; background: linear-gradient(rgba(0, 0, 0, 0.2), rgba(0, 0, 0, 0.2)), url('img/fond.jpg') no-repeat center 0px fixed; background-size: cover; filter: blur(4px); z-index: -1; }
        .crumb-bar { display: flex; align-items: center; gap: 8px; font-size: 0.94rem; font-weight: 600; color: rgba(255,255,255,0.9); text-shadow: 0 1px 3px rgba(0,0,0,0.4); max-width: 99%; margin: 0 auto; padding: 0 10px 10px; }
        .crumb-bar .crumb-home { color: inherit; text-decoration: none; display: inline-flex; align-items: center; gap: 5px; }
        .crumb-bar .crumb-home:hover { text-decoration: underline; }
        .crumb-sep { color: rgba(255,255,255,0.45); font-weight: 400; }
        .crumb-current { color: rgba(255,255,255,0.75); font-weight: 400; }
        .header-top { display: flex; justify-content: space-between; align-items: center; padding: 12px 20px; }
        .header-title { font-family: 'Caveat', cursive; font-size: 1.5rem; color: white; background: rgba(255,255,255,0.12); backdrop-filter: blur(14px) brightness(1.15); -webkit-backdrop-filter: blur(14px) brightness(1.15); border: 1px solid rgba(255,255,255,0.4); border-radius: 20px; padding: 6px 18px; text-shadow: 0 2px 6px rgba(0,0,0,0.4); box-shadow: 0 6px 16px rgba(0,0,0,0.2); margin:0;}
        
        .nav-tabs { display: flex; background: #fff; padding: 0 10px; gap: 2px; overflow-x: auto; scrollbar-width: none; }
        .nav-tabs::-webkit-scrollbar { display: none; }
        .tab-item { padding: 10px 18px; text-decoration: none; color: #7f8c8d; font-weight: 600; font-size: 0.8rem; border-bottom: 3px solid transparent; transition: 0.3s; display: flex; align-items: center; gap: 8px; white-space: nowrap; }
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
        .openbtn { font-size: 22px; cursor: pointer; background: none; border: none; color: var(--primary); padding: 5px 10px; margin-right: 10px; }
        .btn-accueil { font-size: 18px; color: white; text-decoration: none; display: flex; align-items: center; justify-content: center; width: 38px; height: 38px; border-radius: 50%; background: rgba(255,255,255,0.12); backdrop-filter: blur(14px) brightness(1.15); -webkit-backdrop-filter: blur(14px) brightness(1.15); border: 1px solid rgba(255,255,255,0.4); box-shadow: 0 6px 16px rgba(0,0,0,0.2); transition: transform 0.2s, background 0.2s; }
        .btn-accueil:hover { background: rgba(255,255,255,0.28); transform: translateY(-2px); }
        .btn-accueil:hover { background: rgba(0,0,0,0.06); }
        .btn-accueil-green:hover { background: rgba(46, 204, 113, 0.3); border-color: rgba(46, 204, 113, 0.6); }

        .dashboard-container { max-width: 1400px; margin: 20px auto; padding: 0 20px; }
        .kpi-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 20px; margin-bottom: 20px; }
        
        .kpi-card { background: rgba(255, 255, 255, 0.85); backdrop-filter: blur(10px); padding: 20px; border-radius: 12px; box-shadow: 0 5px 15px rgba(0,0,0,0.1); display: flex; align-items: center; gap: 20px; border-left: 5px solid var(--primary); }
        .kpi-icon { width: 50px; height: 50px; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 1.5rem; }
        .kpi-value { font-size: 2rem; font-weight: 800; color: var(--primary); line-height: 1; }
        .kpi-label { font-size: 0.8rem; color: #64748b; font-weight: 700; text-transform: uppercase; margin-top: 5px; }

        .main-grid { display: grid; grid-template-columns: 1fr; gap: 20px; }
        .col-nowrap { white-space: nowrap; }
        
        .panel { background: rgba(255, 255, 255, 0.85); backdrop-filter: blur(10px); border-radius: 12px; box-shadow: 0 5px 15px rgba(0,0,0,0.1); overflow: hidden; display: flex; flex-direction: column; }
        .panel-header { background: rgba(255, 255, 255, 0.9); padding: 15px 20px; border-bottom: 1px solid #f1f5f9; display: flex; flex-wrap: wrap; row-gap: 10px; justify-content: space-between; align-items: center; }
        .panel-title { font-family: 'Caveat', cursive; font-size: 1.6rem; color: var(--primary); margin: 0; display: flex; align-items: center; gap: 10px; }
        
        .active-list { padding: 15px; display: flex; flex-direction: column; gap: 12px; overflow-y: auto; max-height: 600px; }
        .ee-card { border: 1px solid #e2e8f0; border-radius: 8px; padding: 15px; background: white; position: relative; transition: 0.2s; box-shadow: 0 2px 6px rgba(0,0,0,0.03); border-left: 4px solid var(--success); }
        .ee-card:hover { transform: translateY(-2px); box-shadow: 0 5px 15px rgba(0,0,0,0.08); }
        .ee-badge { position: absolute; top: 12px; right: 12px; display: flex; align-items: center; gap: 6px; font-size: 0.65rem; font-weight: bold; color: var(--success); background: #e8f5e9; padding: 4px 10px; border-radius: 20px; }
        .pulse-live { width: 6px; height: 6px; background: var(--success); border-radius: 50%; position: relative; }
        .pulse-live::after { content: ''; position: absolute; width: 100%; height: 100%; background: inherit; border-radius: 50%; animation: pulse-dot 1.5s infinite; }
        .ee-name { font-size: 1.05rem; font-weight: 800; color: var(--primary); margin-bottom: 5px; }
        .ee-detail { font-size: 0.8rem; color: #64748b; margin-bottom: 3px; display: flex; align-items: center; gap: 8px; }
        
        .table-container { padding: 0; overflow-x: auto; background: white; border-radius: 0 0 12px 12px; }
        table { width: 100%; border-collapse: collapse; }
        th { background: #f8fafc; padding: 12px 15px; text-align: left; font-size: 0.7rem; text-transform: uppercase; color: #64748b; border-bottom: 2px solid #e2e8f0; }
        td { padding: 12px 15px; border-bottom: 1px solid #f1f5f9; font-size: 0.85rem; color: #334155; }
        tr:hover { background: #fbfcfd; }
        
        .pdp-badge { padding: 4px 10px; border-radius: 6px; font-size: 0.7rem; font-weight: 700; text-transform: uppercase; display: inline-flex; align-items: center; gap: 5px; }
        .pdp-ok { background: var(--soft-green); color: #2e7d32; border: 1px solid #c8e6c9; }
        .pdp-nok { background: #ffebee; color: #c62828; border: 1px solid #ffcdd2; }
        .pdp-pending { background: var(--soft-orange); color: #f57f17; border: 1px solid #ffecb3; }

        .modal { display:none; position:fixed; z-index:4000; left:0; top:0; width:100%; height:100%; background:rgba(0,0,0,0.6); backdrop-filter:blur(4px); }
        .modal-content { background:white; margin:5% auto; padding:25px; border-radius:12px; box-shadow: 0 10px 30px rgba(0,0,0,0.5); }

        @media (max-width: 1024px) {
            .main-grid { grid-template-columns: 1fr; }
            .header-title { font-size: 1.2rem; }
            .modal-content { width: 95% !important; margin: 10% auto; }
        }
    </style>
<?php include 'pwa_head.php'; ?>
</head>
<body>

<?php $breadcrumb_label = t('st.breadcrumb'); include 'navbar.php'; ?>

<div class="dashboard-container" onclick="closeNav()">

    <?php if($is_admin): ?>
    <div style="display:flex; flex-wrap: wrap; justify-content:flex-end; gap:10px; margin-bottom: 15px;">
        <a href="plan_prevention.php" target="_blank" style="background:#f39c12; color:white; text-decoration:none; padding:10px 20px; border-radius:6px; font-weight:bold; box-shadow: 0 4px 6px rgba(0,0,0,0.1); display:inline-flex; align-items:center; gap:8px; transition:0.2s;" onmouseover="this.style.transform='translateY(-2px)'" onmouseout="this.style.transform='translateY(0)'">
            <i class="fa-solid fa-triangle-exclamation"></i> <?php echo htmlspecialchars(t('st.btn_pdp_vierge')); ?>
        </a>
        <button onclick="ouvrirModalEntreprises()" style="background:var(--primary); color:white; border:none; padding:10px 20px; border-radius:6px; cursor:pointer; font-weight:bold; box-shadow: 0 4px 6px rgba(0,0,0,0.1);">
            <i class="fa-solid fa-address-book"></i> <?php echo htmlspecialchars(t('st.btn_gerer_annuaire')); ?>
        </button>
    </div>
    <?php endif; ?>

    <div class="kpi-grid">
        <div class="kpi-card" style="border-color: var(--gelpam-green);">
            <div class="kpi-icon" style="background: #e8f5e9; color: var(--gelpam-green);"><i class="fa-solid fa-industry"></i></div>
            <div>
                <div class="kpi-value">0</div>
                <div class="kpi-label"><?php echo htmlspecialchars(t('st.kpi_entreprises_site')); ?></div>
            </div>
        </div>
        <div class="kpi-card" style="border-color: var(--accent);">
            <div class="kpi-icon" style="background: #e0f2fe; color: var(--accent);"><i class="fa-solid fa-calendar-check"></i></div>
            <div>
                <div class="kpi-value">0</div>
                <div class="kpi-label"><?php echo htmlspecialchars(t('st.kpi_interventions_planifiees')); ?></div>
            </div>
        </div>
        <div class="kpi-card" style="border-color: var(--danger);">
            <div class="kpi-icon" style="background: #ffebee; color: var(--danger);"><i class="fa-solid fa-file-signature"></i></div>
            <div>
                <div class="kpi-value">0</div>
                <div class="kpi-label"><?php echo htmlspecialchars(t('st.kpi_pdp_manquants')); ?></div>
            </div>
        </div>
    </div>

    <div class="main-grid">

        <div class="panel">
            <div class="panel-header">
                <h2 class="panel-title"><i class="fa-solid fa-calendar-days" style="color: var(--accent);"></i> <?php echo htmlspecialchars(t('st.panel_planification')); ?></h2>
                <div style="display: flex; flex-wrap: wrap; gap: 10px; align-items: center;">
                    <button onclick="ouvrirModalPlanifST()" style="background:var(--accent); color:white; border:none; padding:8px 15px; border-radius:6px; cursor:pointer; font-weight:bold; box-shadow: 0 4px 6px rgba(0,0,0,0.1);"><i class="fa-solid fa-plus-circle"></i> <?php echo htmlspecialchars(t('st.btn_nouvelle_intervention')); ?></button>
                    <button onclick="ouvrirModalHistorique()" style="background:#64748b; color:white; border:none; padding:8px 15px; border-radius:6px; cursor:pointer; font-weight:bold; box-shadow: 0 4px 6px rgba(0,0,0,0.1); display: flex; align-items: center; gap: 6px;">
                        <i class="fa-solid fa-clock-rotate-left"></i> <?php echo htmlspecialchars(t('st.btn_historique')); ?>
                    </button>
                    <select style="padding:6px 10px; border-radius:6px; border:1px solid #ccc; font-family:inherit; font-weight:bold;">
                        <option><?php echo htmlspecialchars(t('st.opt_cette_semaine')); ?></option>
                        <option><?php echo htmlspecialchars(t('st.opt_ce_mois')); ?></option>
                        <option><?php echo htmlspecialchars(t('st.opt_toutes')); ?></option>
                    </select>
                </div>
            </div>
            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th class="col-nowrap"><?php echo htmlspecialchars(t('st.th_date_prevue')); ?></th>
                            <th class="col-nowrap"><?php echo htmlspecialchars(t('st.th_bi')); ?></th>
                            <th class="col-nowrap"><?php echo htmlspecialchars(t('st.th_entreprise')); ?></th>
                            <th><?php echo htmlspecialchars(t('st.th_nature_travaux')); ?></th>
                            <th><?php echo htmlspecialchars(t('st.th_localisation_complete')); ?></th>
                            <th class="col-nowrap"><?php echo htmlspecialchars(t('st.th_plan_prevention')); ?></th> <th class="col-nowrap"><?php echo htmlspecialchars(t('st.th_actions')); ?></th>
                        </tr>
                    </thead>
                    <tbody id="upcomingTable"></tbody>
                </table>
            </div>
        </div>

        <div class="panel">
            <div class="panel-header">
                <h2 class="panel-title"><i class="fa-solid fa-location-dot" style="color: var(--danger);"></i> <?php echo htmlspecialchars(t('st.panel_actuellement_site')); ?></h2>
            </div>
            <div class="active-list" id="activeList"></div>
        </div>

    </div>

<div id="modalPlanifST" class="modal" onclick="if(event.target == this) this.style.display='none'">
    <div class="modal-content" style="max-width: 800px; padding: 20px; border-radius: 12px; margin: 1.5% auto; background: white; border-top: 5px solid var(--accent); position: relative;">
        <div style="display:flex; justify-content:space-between; align-items:center; border-bottom:2px solid #f1f5f9; padding-bottom:10px; margin-bottom:20px;">
            <h2 id="modalPlanifTitle" style="font-family:'Caveat', cursive; font-size:2rem; margin:0; color:var(--primary);"><i class="fa-solid fa-calendar-plus" style="color:var(--accent);"></i> <?php echo htmlspecialchars(t('st.modal_planif_title_new')); ?></h2>
            <span onclick="document.getElementById('modalPlanifST').style.display='none'" style="font-size: 30px; cursor: pointer; color: #cbd5e1;">&times;</span>
        </div>
        
        <input type="hidden" id="new-st-id" value="">

        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
            <div style="grid-column: 1 / -1; display:flex; gap:15px;">
                <div style="flex:2;">
                    <label style="font-size:0.75rem; font-weight:bold; color:#64748b; display:block; margin-bottom:5px;"><?php echo htmlspecialchars(t('st.label_entreprise_ext')); ?></label>
                    <div id="new-st-ee-container" style="width:100%; height:120px; overflow-y:auto; border:1px solid #cbd5e1; border-radius:6px; padding:8px; background:white; box-sizing:border-box;">
    <?php foreach($toutes_entreprises as $ee): ?>
        <label style="display:flex; align-items:center; gap:8px; padding:6px 4px; cursor:pointer; font-size:0.9rem; color:var(--primary); border-bottom:1px solid #f1f5f9;">
            <input type="checkbox" name="entreprise_multi[]" value="<?php echo htmlspecialchars($ee['id']); ?>" style="width:16px; height:16px; cursor:pointer;">
            <span style="font-weight:600;"><?php echo htmlspecialchars($ee['nom']); ?></span>
        </label>
    <?php endforeach; ?>
</div>
                </div>
                <div style="flex:1;">
                    <label style="font-size:0.75rem; font-weight:bold; color:#64748b; display:block; margin-bottom:5px;"><?php echo htmlspecialchars(t('st.label_type')); ?></label>
                    <select id="new-st-type" style="width:100%; padding:10px; border:1px solid #ccc; border-radius:6px;">
                        <option value="Chantier"><?php echo htmlspecialchars(t('maint.type_chantier')); ?></option>
                        <option value="Préventif"><?php echo htmlspecialchars(t('maint.type_preventif')); ?></option>
                        <option value="Curatif"><?php echo htmlspecialchars(t('maint.type_curatif')); ?></option>
                    </select>
                </div>
            </div>

            <div>
                <label style="font-size:0.75rem; font-weight:bold; color:#64748b; display:block; margin-bottom:5px;"><?php echo htmlspecialchars(t('st.label_date_intervention')); ?></label>
                <input type="date" id="new-st-date" style="width:100%; padding:10px; border:1px solid #ccc; border-radius:6px; box-sizing:border-box;">
            </div>

            <div>
                <label style="font-size:0.75rem; font-weight:bold; color:#64748b; display:block; margin-bottom:5px;"><?php echo htmlspecialchars(t('st.label_heure_arrivee')); ?></label>
                <input type="time" id="new-st-time" value="08:00" style="width:100%; padding:10px; border:1px solid #ccc; border-radius:6px; box-sizing:border-box;">
            </div>

            <div style="grid-column: 1 / -1; display: grid; grid-template-columns: 1fr 1fr; gap: 10px; background: #f8fafc; padding: 15px; border-radius: 8px; border: 1px solid #e2e8f0;">
                <label style="grid-column: 1 / -1; font-size:0.75rem; font-weight:bold; color:var(--accent); margin-bottom:0;"><i class="fa-solid fa-location-dot"></i> <?php echo htmlspecialchars(t('st.label_localisation_intervention')); ?></label>
                <div>
                    <label style="font-size:0.65rem; font-weight:bold; color:#64748b; display:block; margin-bottom:3px;"><?php echo htmlspecialchars(t('st.label_usine')); ?></label>
                    <select id="new-st-usine" onchange="updateSecteursST()" style="width:100%; padding:8px; border-radius:5px; border:1px solid #cbd5e1;"><option value=""><?php echo htmlspecialchars(t('st.select_defaut')); ?></option></select>
                </div>
                <div>
                    <label style="font-size:0.65rem; font-weight:bold; color:#64748b; display:block; margin-bottom:3px;"><?php echo htmlspecialchars(t('st.label_secteur')); ?></label>
                    <select id="new-st-secteur" onchange="updateZonesST()" disabled style="width:100%; padding:8px; border-radius:5px; border:1px solid #cbd5e1;"><option value=""><?php echo htmlspecialchars(t('st.select_en_attente')); ?></option></select>
                </div>
                <div>
                    <label style="font-size:0.65rem; font-weight:bold; color:#64748b; display:block; margin-bottom:3px;"><?php echo htmlspecialchars(t('st.label_zone')); ?></label>
                    <select id="new-st-zone" onchange="updateMachinesST()" disabled style="width:100%; padding:8px; border-radius:5px; border:1px solid #cbd5e1;"><option value=""><?php echo htmlspecialchars(t('st.select_en_attente')); ?></option></select>
                </div>
                <div>
                    <label style="font-size:0.65rem; font-weight:bold; color:#64748b; display:block; margin-bottom:3px;"><?php echo htmlspecialchars(t('st.label_machine')); ?></label>
                    <select id="new-st-equip" disabled style="width:100%; padding:8px; border-radius:5px; border:1px solid #cbd5e1; font-weight:bold; color:var(--primary);"><option value=""><?php echo htmlspecialchars(t('st.select_en_attente')); ?></option></select>
                </div>
            </div>

            <div style="grid-column: 1 / -1; display:flex; gap:15px; align-items:flex-start;">
                <div style="flex:1;">
                    <label style="font-size:0.75rem; font-weight:bold; color:#64748b; display:block; margin-bottom:5px;"><?php echo htmlspecialchars(t('st.label_technicien_suivi')); ?></label>
                    <select id="new-st-tech" style="width:100%; padding:10px; border:1px solid #ccc; border-radius:6px;">
                        <option value="Christophe">Christophe</option>
                        <option value="Didier">Didier</option>
                        <option value="David">David</option>
                        <option value="Gilbert">Gilbert</option>
                        <option value="Manu">Manu</option>
                        <option value="Teddy">Teddy</option>
                        <option value="Yannick">Yannick</option>
                    </select>
                </div>
                <div style="flex:2;">
                    <label style="font-size:0.75rem; font-weight:bold; color:#64748b; display:block; margin-bottom:5px;"><?php echo htmlspecialchars(t('st.label_nature_travaux_desc')); ?></label>
                    <input type="text" id="new-st-desc" placeholder="<?php echo htmlspecialchars(t('st.placeholder_nature_travaux')); ?>" style="width:100%; padding:10px; border:1px solid #ccc; border-radius:6px; box-sizing:border-box;">
                </div>
            </div>

            <div style="grid-column: 1 / -1; display:flex; align-items:center; gap:8px; background: #fff5f5; padding: 10px; border-radius: 6px; border: 1px solid #fecaca;">
                <input type="checkbox" id="new-st-casse" style="width:18px; height:18px;">
                <label for="new-st-casse" style="color:var(--danger); font-weight:bold; font-size:0.8rem; cursor:pointer;"><?php echo htmlspecialchars(t('st.label_casse')); ?></label>
            </div>
        </div>

        <button id="btnSavePlanifST" onclick="validerNouvellePlanifST()" style="width:100%; background:var(--accent); color:white; border:none; padding:15px; border-radius:8px; font-weight:bold; font-size:1.1rem; margin-top:20px; cursor:pointer; box-shadow: 0 4px 10px rgba(52, 152, 219, 0.3);">
            <i class="fa-solid fa-calendar-check"></i> <?php echo htmlspecialchars(t('st.btn_enregistrer_planning')); ?>
        </button>
    </div>
</div>

<div id="modalEntreprises" class="modal">
    <div class="modal-content" style="max-width: 1200px; width: 90%; padding: 25px; border-radius: 12px; margin: 3% auto; background: white; position: relative; border-top: 5px solid var(--primary);">
        <div style="display:flex; justify-content:space-between; align-items:center; border-bottom:2px solid #f1f5f9; padding-bottom:15px; margin-bottom:20px; gap: 20px; flex-wrap: wrap;">
            <div style="display: flex; align-items: center; gap: 15px;">
                <h2 style="font-family:'Caveat', cursive; font-size:2.2rem; margin:0; color:var(--primary);"><i class="fa-solid fa-address-book"></i> <?php echo htmlspecialchars(t('st.modal_entreprises_title')); ?></h2>
                <button onclick="resetFormEntreprise()" style="background:var(--gelpam-green); color:white; border:none; padding:8px 16px; border-radius:6px; cursor:pointer; font-weight:bold; font-size:0.85rem; display:flex; align-items:center; gap:6px; box-shadow: 0 2px 5px rgba(46, 204, 113, 0.2);">
                    <i class="fa-solid fa-plus-circle"></i> <?php echo htmlspecialchars(t('st.btn_ajouter_entreprise')); ?>
                </button>
            </div>
            <span onclick="document.getElementById('modalEntreprises').style.display='none'" style="font-size: 30px; cursor: pointer; color: #cbd5e1;">&times;</span>
        </div>
        
        <div style="max-height: 65vh; overflow-y: auto; overflow-x: auto; border: 1px solid #e2e8f0; border-radius: 10px;">
            <table style="width:100%; border-collapse:collapse;">
                <thead style="background: var(--primary); color: white; position:sticky; top:0; z-index:10; text-align:left;">
                    <tr>
                        <th style="padding:12px 15px; font-size:0.7rem; font-weight:700; text-transform:uppercase;"><?php echo htmlspecialchars(t('st.th_entreprise')); ?></th>
                        <th style="padding:12px 15px; font-size:0.7rem; font-weight:700; text-transform:uppercase;"><?php echo htmlspecialchars(t('st.label_contact')); ?></th>
                        <th style="padding:12px 15px; font-size:0.7rem; font-weight:700; text-transform:uppercase;"><?php echo htmlspecialchars(t('st.label_type_pdp')); ?></th>
                        <th style="padding:12px 15px; font-size:0.7rem; font-weight:700; text-transform:uppercase;"><?php echo htmlspecialchars(t('st.th_plan_prevention')); ?></th>
                        <th style="padding:12px 15px; font-size:0.7rem; font-weight:700; text-transform:uppercase;"><?php echo htmlspecialchars(t('st.th_documents')); ?></th>
                        <th style="padding:12px 15px; font-size:0.7rem; font-weight:700; text-transform:uppercase; text-align:right;"><?php echo htmlspecialchars(t('st.th_actions')); ?></th>
                    </tr>
                </thead>
                <tbody id="listeEntreprisesCards"></tbody>
            </table>
        </div>
    </div>
</div>

<div id="modalFormEntreprise" class="modal" style="z-index: 5000;">
    <div class="modal-content" style="max-width: 620px; width: 90%; padding: 25px; border-radius: 12px; margin: 4% auto; background: white; position: relative; border-top: 5px solid var(--accent); box-shadow: 0 15px 30px rgba(0,0,0,0.3);">
        <div style="display:flex; justify-content:space-between; align-items:center; border-bottom:1px solid #e2e8f0; padding-bottom:10px; margin-bottom:18px;">
            <h3 style="margin:0; font-size:1.1rem; color:var(--primary);" id="formTitle"><?php echo htmlspecialchars(t('st.form_title_ajouter')); ?></h3>
            <span onclick="document.getElementById('modalFormEntreprise').style.display='none'" style="font-size: 24px; cursor: pointer; color: #cbd5e1;">&times;</span>
        </div>

        <input type="hidden" id="e-id">
        <input type="hidden" id="e-pdp-supprime" value="0">

        <label style="font-size:0.75rem; font-weight:bold; color:#64748b; display:block; margin-bottom:5px;"><?php echo htmlspecialchars(t('st.label_nom_entreprise')); ?></label>
        <input type="text" id="e-nom" style="width:100%; padding:10px; margin-bottom:16px; border:1px solid #ccc; border-radius:6px; box-sizing:border-box; font-family: inherit; font-weight:bold; font-size:0.95rem;">

        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px;">
            <div style="background: #f8fafc; padding: 14px; border-radius: 8px; border: 1px solid #e2e8f0;">
                <h4 style="margin:0 0 10px 0; font-size:0.72rem; color:#64748b; text-transform:uppercase; letter-spacing:0.5px;"><i class="fa-solid fa-id-card"></i> <?php echo htmlspecialchars(t('st.coordonnees')); ?></h4>

                <label style="font-size:0.7rem; font-weight:bold; color:#64748b; display:block; margin-bottom:4px;"><?php echo htmlspecialchars(t('st.label_contact')); ?></label>
                <input type="text" id="e-contact" style="width:100%; padding:8px; margin-bottom:10px; border:1px solid #ccc; border-radius:6px; box-sizing:border-box; font-family: inherit; font-size:0.85rem;">

                <label style="font-size:0.7rem; font-weight:bold; color:#64748b; display:block; margin-bottom:4px;"><?php echo htmlspecialchars(t('st.label_telephone')); ?></label>
                <input type="text" id="e-tel" style="width:100%; padding:8px; margin-bottom:10px; border:1px solid #ccc; border-radius:6px; box-sizing:border-box; font-family: inherit; font-size:0.85rem;">

                <label style="font-size:0.7rem; font-weight:bold; color:#64748b; display:block; margin-bottom:4px;"><?php echo htmlspecialchars(t('st.label_email')); ?></label>
                <input type="email" id="e-email" style="width:100%; padding:8px; border:1px solid #ccc; border-radius:6px; box-sizing:border-box; font-family: inherit; font-size:0.85rem;">
            </div>

            <div style="background: #f8fafc; padding: 14px; border-radius: 8px; border: 1px solid #e2e8f0;">
                <h4 style="margin:0 0 10px 0; font-size:0.72rem; color:#64748b; text-transform:uppercase; letter-spacing:0.5px;"><i class="fa-solid fa-folder-open"></i> <?php echo htmlspecialchars(t('st.securite_admin')); ?></h4>

                <label style="font-size:0.7rem; font-weight:bold; color:#64748b; display:block; margin-bottom:4px;"><?php echo htmlspecialchars(t('st.label_type_pdp')); ?></label>
                <select id="e-type-pdp" style="width:100%; padding:8px; margin-bottom:10px; border:1px solid #ccc; border-radius:6px; box-sizing:border-box; font-family: inherit; font-weight: bold; color: var(--primary); font-size:0.85rem;">
                    <option value="Annuel"><?php echo htmlspecialchars(t('st.opt_annuel')); ?></option>
                    <option value="Ponctuel"><?php echo htmlspecialchars(t('st.opt_ponctuel')); ?></option>
                </select>

                <label style="font-size:0.7rem; font-weight:bold; color:#64748b; display:block; margin-bottom:4px;"><?php echo htmlspecialchars(t('st.label_fin_validite_pdp')); ?></label>
                <input type="date" id="e-pdp" style="width:100%; padding:8px; margin-bottom:10px; border:1px solid #ccc; border-radius:6px; box-sizing:border-box; font-family: inherit; font-size:0.85rem;">

                <label style="font-size:0.7rem; font-weight:bold; color:#64748b; display:block; margin-bottom:4px;"><?php echo htmlspecialchars(t('st.label_fichier_pdp')); ?></label>
                <input type="file" id="e-fichier" accept=".pdf" style="width:100%; font-size: 0.75rem; color: #64748b;">
                <div id="e-fichier-actuel" style="font-size: 0.72rem; margin-top: 6px; color: var(--accent); font-weight: bold;"></div>
            </div>
        </div>

        <button onclick="sauvegarderEntreprise()" style="width:100%; margin-top:20px; background:var(--gelpam-green); color:white; border:none; padding:12px; border-radius:6px; font-weight:bold; cursor:pointer; font-size: 1rem; box-shadow: 0 4px 6px rgba(46, 204, 113, 0.2);"><i class="fa-solid fa-save"></i> <?php echo htmlspecialchars(t('st.btn_enregistrer')); ?></button>
    </div>
</div>

<div id="modalDetailEntreprise" class="modal" onclick="if(event.target == this) this.style.display='none'">
    <div class="modal-content" style="max-width: 850px; width: 90%; padding: 25px; border-radius: 12px; margin: 4% auto; background: white; border-top: 5px solid var(--accent); position: relative; box-shadow: 0 15px 30px rgba(0,0,0,0.25);">
        <div style="display:flex; justify-content:space-between; align-items:center; border-bottom:2px solid #f1f5f9; padding-bottom:12px; margin-bottom:20px;">
            <div style="display: flex; align-items: center; gap: 12px;">
                <div style="background: #e0f2fe; color: var(--accent); width: 45px; height: 45px; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 1.3rem;">
                    <i class="fa-solid fa-building-user"></i>
                </div>
                <div>
                    <h2 id="detail-titre-entreprise" style="margin:0; font-size:1.4rem; color:var(--primary); font-weight:800;"><?php echo htmlspecialchars(t('st.detail_default_titre')); ?></h2>
                    <div id="detail-badge-pdp"><?php echo htmlspecialchars(t('st.detail_default_statut')); ?></div>
                </div>
            </div>
            <span onclick="document.getElementById('modalDetailEntreprise').style.display='none'" style="font-size: 30px; cursor: pointer; color: #cbd5e1;" onmouseover="this.style.color='#94a3b8'" onmouseout="this.style.color='#cbd5e1'">&times;</span>
        </div>

        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 25px;">
            <div style="background: #f8fafc; padding: 15px; border-radius: 8px; border: 1px solid #e2e8f0;">
                <h3 style="margin-top:0; margin-bottom:12px; font-size:0.9rem; color:#64748b; text-transform:uppercase; letter-spacing:0.5px;"><i class="fa-solid fa-id-card"></i> <?php echo htmlspecialchars(t('st.coordonnees')); ?></h3>
                <div style="display:flex; flex-direction:column; gap:8px; font-size:0.85rem;">
                    <div><strong><?php echo htmlspecialchars(t('st.contact_label')); ?></strong> <span id="detail-contact">-</span></div>
                    <div><strong><?php echo htmlspecialchars(t('st.telephone_label')); ?></strong> <span id="detail-tel">-</span></div>
                    <div><strong><?php echo htmlspecialchars(t('st.email_label')); ?></strong> <span id="detail-email">-</span></div>
                </div>
            </div>
            <div style="background: #f8fafc; padding: 15px; border-radius: 8px; border: 1px solid #e2e8f0;">
                <h3 style="margin-top:0; margin-bottom:12px; font-size:0.9rem; color:#64748b; text-transform:uppercase; letter-spacing:0.5px;"><i class="fa-solid fa-folder-open"></i> <?php echo htmlspecialchars(t('st.securite_admin')); ?></h3>
                <div style="display:flex; flex-direction:column; gap:8px; font-size:0.85rem;">
                    <div><strong><?php echo htmlspecialchars(t('st.type_pdp_label')); ?></strong> <span id="detail-type-pdp" style="font-weight:bold; color:var(--accent);">-</span></div>
                    <div><strong><?php echo htmlspecialchars(t('st.fin_validite_pdp_label')); ?></strong> <span id="detail-date-pdp">-</span></div>
                    <div style="margin-top:5px;" id="detail-action-pdf"></div>
                </div>
            </div>
        </div>

        <div>
            <h3 style="margin-top:0; margin-bottom:10px; font-size:0.95rem; color:var(--primary); font-weight:700; display:flex; align-items:center; gap:8px;">
                <i class="fa-solid fa-clock-rotate-left" style="color:var(--gelpam-orange);"></i> <?php echo htmlspecialchars(t('st.historique_societe')); ?>
            </h3>
            <div style="max-height: 220px; overflow-y: auto; border: 1px solid #e2e8f0; border-radius: 8px;">
                <table style="width:100%; border-collapse:collapse; font-size:0.8rem; table-layout: fixed;">
                    <thead style="background: var(--primary); color: white; position:sticky; top:0; z-index:10; text-align:left;">
                        <tr>
                            <th style="padding:10px; font-size:0.7rem; font-weight:700; text-transform:uppercase; width: 75px;"><?php echo htmlspecialchars(t('st.th_bi')); ?></th>
                            <th style="padding:10px; font-size:0.7rem; font-weight:700; text-transform:uppercase; width: 95px;"><?php echo htmlspecialchars(t('st.th_date')); ?></th>
                            <th style="padding:10px; font-size:0.7rem; font-weight:700; text-transform:uppercase;"><?php echo htmlspecialchars(t('st.th_machine_desc')); ?></th>
                            <th style="padding:10px; font-size:0.7rem; font-weight:700; text-transform:uppercase; width: 140px;"><?php echo htmlspecialchars(t('st.th_techniciens')); ?></th>
                            <th style="padding:10px; font-size:0.7rem; font-weight:700; text-transform:uppercase; width: 180px;"><?php echo htmlspecialchars(t('st.th_compte_rendu')); ?></th>
                        </tr>
                    </thead>
                    <tbody id="detailHistoriqueSocieteTable" style="color:#334155;"></tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<div id="modalClotureST" class="modal" style="display:none; position:fixed; z-index:9999; left:0; top:0; width:100%; height:100%; background:rgba(15, 23, 42, 0.75); align-items:center; justify-content:center;">
    <input type="hidden" id="st-cloture-id">
    <div class="modal-content" style="max-width:600px !important; padding:0 !important; border-radius:12px !important; border:none !important; overflow:hidden; background: #fff; box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.2); margin: 10% auto;">
        <div style="padding: 20px 25px; background: white; border-bottom: 1px solid #f1f5f9; display: flex; justify-content: space-between; align-items: center;">
            <div style="display: flex; align-items: center; gap: 12px;">
                <div style="background: #e8f5e9; color: var(--success); width: 40px; height: 40px; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 1.2rem;">
                    <i class="fa-solid fa-flag-checkered"></i>
                </div>
                <div>
                    <h3 style="margin:0; font-family:'Caveat', cursive; font-size: 1.8rem; color: var(--primary); font-weight: 500;"><?php echo htmlspecialchars(t('st.modal_cloture_title')); ?></h3>
                    <div style="font-size: 0.85rem; color: #64748b; font-weight: 500;" id="st-cloture-nom"><?php echo htmlspecialchars(t('st.entreprise_ext_default')); ?></div>
                </div>
            </div>
            <span onclick="document.getElementById('modalClotureST').style.display='none'" style="cursor:pointer; font-size:28px; color:#cbd5e1;">&times;</span>
        </div>

        <div style="padding: 25px; background: #f8fafc;">
            <div style="margin-bottom: 15px;">
                <label style="display:block; color: var(--primary); font-size: 0.7rem; font-weight: 700; text-transform: uppercase; margin-bottom: 8px; display: flex; align-items: center; gap: 6px;">
                    <i class="fa-solid fa-pen-nib" style="color: var(--gelpam-green);"></i> <?php echo htmlspecialchars(t('st.label_compte_rendu')); ?>
                </label>
                <textarea id="st-cloture-cr" placeholder="<?php echo htmlspecialchars(t('st.placeholder_compte_rendu')); ?>" style="width:100%; height:120px; border:1px solid #cbd5e1; border-radius:8px; padding:12px; font-size:0.9rem; outline:none; resize: none; box-sizing: border-box; font-family: inherit; color: #334155; transition: border 0.2s;" onfocus="this.style.border='1px solid var(--accent)'" onblur="this.style.border='1px solid #cbd5e1'"></textarea>
            </div>
        </div>

        <div style="padding: 15px 25px; background: white; display: flex; justify-content: flex-end; gap: 12px; border-top: 1px solid #f1f5f9;">
            <button onclick="document.getElementById('modalClotureST').style.display='none'" style="padding: 8px 20px; border-radius: 6px; border: 1px solid #e2e8f0; background: white; color: #64748b; cursor: pointer; font-weight: 600; font-size: 0.85rem;"><?php echo htmlspecialchars(t('st.btn_annuler')); ?></button>
            <button onclick="validerClotureST()" style="padding: 8px 25px; border-radius: 6px; border: none; background: var(--gelpam-green); color: white; cursor: pointer; font-weight: 700; font-size: 0.85rem; transition: 0.2s;"><?php echo htmlspecialchars(t('st.btn_confirmer_cloture')); ?></button>
        </div>
    </div>
</div>

<div id="modalHistoriqueST" class="modal" onclick="if(event.target == this) this.style.display='none'">
    <div class="modal-content" style="max-width: 900px; padding: 25px; border-radius: 12px; margin: 5% auto; background: white; border-top: 5px solid var(--primary); position: relative;">
        <div style="display:flex; justify-content:space-between; align-items:center; border-bottom:2px solid #f1f5f9; padding-bottom:10px; margin-bottom:20px;">
            <h2 style="font-family:'Caveat', cursive; font-size:2rem; margin:0; color:var(--primary);"><i class="fa-solid fa-clock-rotate-left"></i> <?php echo htmlspecialchars(t('st.modal_historique_title')); ?></h2>
            <span onclick="document.getElementById('modalHistoriqueST').style.display='none'" style="font-size: 30px; cursor: pointer; color: #cbd5e1;">&times;</span>
        </div>
        <div style="max-height: 400px; overflow-y: auto; border: 1px solid #e2e8f0; border-radius: 8px;">
            <table style="width:100%; border-collapse:collapse; table-layout: fixed;">
                <thead style="background: var(--primary); color: white; font-size:0.8rem; position:sticky; top:0; z-index:10; text-align:left;">
                    <tr>
                        <th style="padding:12px 10px; font-size:0.7rem; font-weight:700; text-transform:uppercase; width: 75px;"><?php echo htmlspecialchars(t('st.th_bi')); ?></th>
                        <th style="padding:12px 10px; font-size:0.7rem; font-weight:700; text-transform:uppercase; width: 95px;"><?php echo htmlspecialchars(t('st.th_date')); ?></th>
                        <th style="padding:12px 10px; font-size:0.7rem; font-weight:700; text-transform:uppercase; width: 140px;"><?php echo htmlspecialchars(t('st.th_entreprise')); ?></th>
                        <th style="padding:12px 10px; font-size:0.7rem; font-weight:700; text-transform:uppercase;"><?php echo htmlspecialchars(t('st.th_machine_desc')); ?></th>
                        <th style="padding:12px 10px; font-size:0.7rem; font-weight:700; text-transform:uppercase; width: 140px;"><?php echo htmlspecialchars(t('st.th_techniciens')); ?></th>
                        <th style="padding:12px 10px; font-size:0.7rem; font-weight:700; text-transform:uppercase; width: 180px;"><?php echo htmlspecialchars(t('st.th_compte_rendu')); ?></th>
                    </tr>
                </thead>
                <tbody id="historiqueTable" style="font-size:0.85rem; color:#334155;"></tbody>
            </table>
        </div>
    </div>
</div>

<script>

// --- GESTION SIDEBAR ---
function openNav(e) { 
    if(e) e.stopPropagation(); 
    document.getElementById("mySidebar").style.width = "280px"; 
}
function closeNav() { 
    document.getElementById("mySidebar").style.width = "0"; 
}
function checkCloseSidebar(event) { 
    if (document.getElementById("mySidebar").style.width === "280px" && !document.getElementById("mySidebar").contains(event.target)) {
        closeNav(); 
    }
}

const I18N_ST = <?php echo json_encode([
    'confirmation_title' => t('planning.confirmation_title'),
    'err_title' => t('maint.err_title'),
    'err_network_title' => t('maint.err_network_title'),
    'aucune_entreprise_site' => t('st.aucune_entreprise_site'),
    'badge_present' => t('st.badge_present'),
    'entreprise_inconnue' => t('st.entreprise_inconnue'),
    'intervention_fallback' => t('st.intervention_fallback'),
    'tooltip_sortir' => t('st.tooltip_sortir'),
    'btn_sortir_pause' => t('st.btn_sortir_pause'),
    'btn_cloturer' => t('st.btn_cloturer'),
    'aucune_intervention_planifiee' => t('st.aucune_intervention_planifiee'),
    'badge_en_pause' => t('st.badge_en_pause'),
    'ouvrir_bi' => t('st.ouvrir_bi'),
    'non_definie' => t('st.non_definie'),
    'a_definir' => t('st.a_definir'),
    'ouvrir_pdp' => t('st.ouvrir_pdp'),
    'tooltip_modifier' => t('st.tooltip_modifier'),
    'btn_faire_entrer' => t('st.btn_faire_entrer'),
    'aucune_entreprise_enregistree' => t('st.aucune_entreprise_enregistree'),
    'badge_pdp_ponctuel' => t('st.badge_pdp_ponctuel'),
    'badge_annuel' => t('st.badge_annuel'),
    'lie_au_chantier' => t('st.lie_au_chantier'),
    'tooltip_pdp_vierge' => t('st.tooltip_pdp_vierge'),
    'tooltip_voir_pdp_existant' => t('st.tooltip_voir_pdp_existant'),
    'tooltip_supprimer' => t('st.tooltip_supprimer'),
    'voir_pdf' => t('st.voir_pdf'),
    'tooltip_supprimer_pdf' => t('st.tooltip_supprimer_pdf'),
    'pdf_programme_suppression' => t('st.pdf_programme_suppression'),
    'ouvrir_ri' => t('kpi.ouvrir_ri'),
    'err_nom_obligatoire' => t('st.err_nom_obligatoire'),
    'err_serveur_prefix' => t('st.err_serveur_prefix'),
    'form_title_modifier' => t('st.form_title_modifier'),
    'form_title_ajouter' => t('st.form_title_ajouter'),
    'non_renseigne' => t('rapport.non_renseigne'),
    'non_applicable_chantier' => t('st.non_applicable_chantier'),
    'badge_pdp_ponctuel_alt' => t('st.badge_pdp_ponctuel'),
    'btn_ouvrir_plan_prevention' => t('st.btn_ouvrir_plan_prevention'),
    'aucun_document_pdf' => t('st.aucun_document_pdf'),
    'aucune_intervention_cloturee' => t('st.aucune_intervention_cloturee'),
    'prev_fallback' => t('stats.prev_fallback'),
    'non_assigne' => t('stats.non_assigne'),
    'aucun_rapport' => t('st.aucun_rapport'),
    'confirm_suppr_entreprise' => t('st.confirm_suppr_entreprise'),
    'err_cr_obligatoire' => t('st.err_cr_obligatoire'),
    'err_ticket_introuvable' => t('st.err_ticket_introuvable'),
    'err_sauvegarde_cr' => t('st.err_sauvegarde_cr'),
    'err_reseau_prefix' => t('st.err_reseau_prefix'),
    'modal_planif_title_new' => t('st.modal_planif_title_new'),
    'modal_planif_title_edit' => t('st.modal_planif_title_edit'),
    'btn_creer_bon' => t('st.btn_creer_bon'),
    'btn_maj_bon' => t('st.btn_maj_bon'),
    'select_usine' => t('st.select_usine'),
    'select_secteur' => t('st.select_secteur'),
    'select_zone' => t('st.select_zone'),
    'select_machine' => t('st.select_machine'),
    'select_en_attente' => t('st.select_en_attente'),
    'err_cocher_entreprise' => t('st.err_cocher_entreprise'),
    'err_ticket_introuvable_maj' => t('st.err_ticket_introuvable_maj'),
    'aucune_intervention_historique' => t('st.aucune_intervention_historique'),
    'pdp_valide' => t('st.pdp_valide'),
    'pdp_expire' => t('st.pdp_expire'),
    'en_attente' => t('st.en_attente'),
]); ?>;

// --- INITIALISATION DONNÉES GLOBALES ---
const stSurSite = <?php echo json_encode($st_sur_site); ?>;
const stAVenir = <?php echo json_encode($st_a_venir); ?>;
const toutesEntreprises = <?php echo json_encode($toutes_entreprises); ?>;
const stHistorique = <?php echo json_encode($st_historique); ?>;

// --- AJOUT CHIRURGICAL : RÉCUPÉRATION DES POINTAGES EN ARRIÈRE-PLAN ---
let allPointages = [];
fetch('maintenance.php?get_pointages=1&t=' + Date.now())
    .then(res => res.json())
    .then(data => allPointages = data)
    .catch(e => console.error("Erreur de chargement des pointages", e));

function getPdpStatus(dateFinPdp) {
    if (!dateFinPdp) return 'pending'; 
    const fin = new Date(dateFinPdp);
    const today = new Date();
    today.setHours(0,0,0,0);
    return fin >= today ? 'ok' : 'nok';
}

function getPdpBadge(status) {
    if(status === 'ok') return `<span class="pdp-badge pdp-ok"><i class="fa-solid fa-check"></i> ${I18N_ST.pdp_valide}</span>`;
    if(status === 'nok') return `<span class="pdp-badge pdp-nok"><i class="fa-solid fa-triangle-exclamation"></i> ${I18N_ST.pdp_expire}</span>`;
    return `<span class="pdp-badge pdp-pending"><i class="fa-solid fa-clock"></i> ${I18N_ST.en_attente}</span>`;
}

function formatHeure(dateString) {
    if (!dateString) return '--:--';
    const parts = dateString.split(' ');
    if (parts.length > 1) return parts[1].substring(0, 5);
    return '--:--';
}

function formatDate(dateString) {
    if (!dateString) return '--/--/----';
    const parts = dateString.split(' ')[0].split('-');
    return `${parts[2]}/${parts[1]}/${parts[0]}`;
}

async function gererPresenceST(id, surSite, statut) {
    const fd = new URLSearchParams();
    fd.append('action_st', '1');
    fd.append('id', id);
    fd.append('st_sur_site', surSite);
    if(statut !== '') fd.append('statut', statut);
    
    await fetch('sous_traitants.php', { method: 'POST', body: fd });
    location.reload(); 
}

// --- AFFICHAGE DASHBOARD ---
function renderDashboard() {
    const activeList = document.getElementById('activeList');
    if (stSurSite.length === 0) {
        activeList.innerHTML = `<div style="padding:20px; text-align:center; color:#94a3b8; font-style:italic;">${I18N_ST.aucune_entreprise_site}</div>`;
    } else {
        activeList.innerHTML = stSurSite.map(ee => {
            return `
            <div class="ee-card">
                <div class="ee-badge"><div class="pulse-live"></div> ${I18N_ST.badge_present}</div>
                <div class="ee-name">${ee.nom_entreprise || ee.tech || I18N_ST.entreprise_inconnue}</div>
                <div class="ee-detail"><i class="fa-solid fa-location-dot" style="width:15px; color:#94a3b8;"></i> ${ee.usine || ''} > ${ee.zone || ''}</div>
                <div class="ee-detail"><i class="fa-solid fa-wrench" style="width:15px; color:#94a3b8;"></i> ${ee.description || ee.equip || I18N_ST.intervention_fallback}</div>
                <hr style="border:0.5px solid #f1f5f9; margin: 10px 0;">
                <div style="display:flex; justify-content:space-between; align-items:center;">
                    <button onclick="gererPresenceST('${ee.id}', 0, '')" style="background:#f39c12; color:white; border:none; padding:5px 10px; border-radius:5px; cursor:pointer; font-size:0.7rem; font-weight:bold;" title="${I18N_ST.tooltip_sortir}"><i class="fa-solid fa-person-walking-arrow-right"></i> ${I18N_ST.btn_sortir_pause}</button>
                    <button onclick="ouvrirModaleClotureST('${ee.id}')" style="background:var(--success); color:white; border:none; padding:5px 10px; border-radius:5px; cursor:pointer; font-size:0.75rem; font-weight:bold;"><i class="fa-solid fa-check"></i> ${I18N_ST.btn_cloturer}</button>
                </div>
            </div>`;
        }).join('');
    }

    const table = document.getElementById('upcomingTable');
    if (stAVenir.length === 0) {
        table.innerHTML = `<tr><td colspan="6" style="text-align:center; padding:20px; color:#94a3b8; font-style:italic;">${I18N_ST.aucune_intervention_planifiee}</td></tr>`;
    } else {
        table.innerHTML = stAVenir.map(ee => {
            let pdpStat = getPdpStatus(ee.date_fin_pdp);
            let badgePause = (ee.statut === "En cours") ? `<span style="background:#f39c12; color:white; padding:3px 6px; border-radius:4px; font-size:0.6rem; font-weight:bold; margin-left:8px; vertical-align:middle;"><i class="fa-solid fa-pause"></i> ${I18N_ST.badge_en_pause}</span>` : '';

            // Design pour N° BI cliquable
            let biHtml = ee.num_bi
                ? `<a href="#" onclick="showDetailBI('${ee.id}'); return false;" style="color: #d35400; font-weight: 800; background: #fef5e7; border: 1px solid #f9e79f; padding: 3px 6px; border-radius: 4px; font-size: 0.75rem; text-decoration: none; display: inline-block;" title="${I18N_ST.ouvrir_bi} ${ee.num_bi}">#${ee.num_bi}</a>`
                : `<span style="color: #95a5a6; font-size: 0.75rem; font-style:italic;">N/A</span>`;

            // --- 1. NOUVELLE LOCALISATION COMPLÈTE (Avec retour à la ligne auto) ---
            let locArray = [ee.usine, ee.secteur, ee.ligne, ee.zone].filter(Boolean);
            let locHtml = locArray.length > 0
                ? `<div style="font-size:0.75rem; color:#455a64; line-height:1.4; display:flex; flex-wrap:wrap; align-items:center; gap:4px;">` +
                  locArray.join(' <i class="fa-solid fa-chevron-right" style="font-size:0.55rem; color:#cbd5e1;"></i> ') +
                  `</div>`
                : `<span style="color:#94a3b8; font-style:italic; font-size:0.8rem;">${I18N_ST.non_definie}</span>`;

            // --- 2. COLONNE PLAN DE PRÉVENTION FUSIONNÉE ---
            let numPdp = ee.entreprise_ext_id ? I18N_ST.a_definir : "-";

            return `
            <tr style="border-bottom: 1px solid #eef2f3; background: white; transition: 0.15s;" onmouseover="this.style.background='rgba(52, 152, 219, 0.02)'" onmouseout="this.style.background='white'">
                <td class="col-nowrap" style="font-weight:bold; vertical-align:top;">${formatDate(ee.date)}</td>
                <td class="col-nowrap" style="vertical-align:top;">${biHtml}</td>
                <td class="col-nowrap" style="font-weight:800; color:var(--primary); vertical-align:top;">${ee.nom_entreprise || ee.tech || I18N_ST.entreprise_inconnue} ${badgePause}</td>
                <td style="vertical-align:top;">${ee.description || ee.equip}</td>

                <td style="min-width: 160px; vertical-align:top;">${locHtml}</td>

                <td class="col-nowrap" style="vertical-align:top;">
                    <div style="display:flex; flex-direction:column; gap:4px; align-items:flex-start;">
                        <span style="font-size:0.7rem; font-weight:800; color:#2980b9;">N° ${numPdp}</span>
                        ${getPdpBadge(pdpStat)}
                        ${ee.fichier_pdp ? `<a href="${ee.fichier_pdp}" target="_blank" style="font-size:0.7rem; color:var(--accent); text-decoration:none; font-weight:bold; margin-top:2px;"><i class="fa-solid fa-file-pdf" style="color:#e74c3c;"></i> ${I18N_ST.ouvrir_pdp}</a>` : ''}
                    </div>
                </td>

                <td class="col-nowrap" style="display: flex; gap: 5px; vertical-align:top;">
                    <button onclick="editerPlanifST('${ee.id}')" style="background:#f1f5f9; color:#64748b; border:1px solid #cbd5e1; padding:6px 10px; border-radius:5px; cursor:pointer; font-weight:bold; font-size:0.8rem;" title="${I18N_ST.tooltip_modifier}"><i class="fa-solid fa-pen"></i></button>
                    <button onclick="gererPresenceST('${ee.id}', 1, 'En cours')" style="background:var(--accent); color:white; border:none; padding:6px 12px; border-radius:5px; cursor:pointer; font-weight:bold; font-size:0.8rem;"><i class="fa-solid fa-play"></i> ${I18N_ST.btn_faire_entrer}</button>
                </td>
            </tr>`;
        }).join('');
    }

    const valSurSite = document.querySelectorAll('.kpi-value')[0];
    const valAVenir = document.querySelectorAll('.kpi-value')[1];
    const valPdpExp = document.querySelectorAll('.kpi-value')[2];

    if(valSurSite) valSurSite.innerText = stSurSite.length;
    if(valAVenir) valAVenir.innerText = stAVenir.length;
    
    if(valPdpExp) {
        let nbPdpInvalides = 0;
        toutesEntreprises.forEach(e => {
            if (getPdpStatus(e.date_fin_pdp) !== 'ok') nbPdpInvalides++;
        });
        valPdpExp.innerText = nbPdpInvalides;
    }
}

// --- GESTION ANNUAIRE : CARTES ET ALIMENTATION ---
function ouvrirModalEntreprises() {
    renderListeEntreprises();
    document.getElementById('modalEntreprises').style.display = 'block';
}

function renderListeEntreprises() {
    const container = document.getElementById('listeEntreprisesCards');
    if(toutesEntreprises.length === 0) {
        container.innerHTML = `<tr><td colspan="6" style="text-align:center; padding:30px; color:#94a3b8; font-style:italic;">${I18N_ST.aucune_entreprise_enregistree}</td></tr>`;
        return;
    }

    // Tri alphabétique : un annuaire se parcourt, il n'a pas besoin de suivre l'ordre d'insertion en base.
    const listeTriee = [...toutesEntreprises].sort((a, b) => (a.nom || '').localeCompare(b.nom || ''));

    container.innerHTML = listeTriee.map(e => {
        let isPonctuel = (e.type_pdp === 'Ponctuel');
        let pdpStat = isPonctuel ? 'ok' : getPdpStatus(e.date_fin_pdp); // On force 'ok' pour le ponctuel pour éviter la bordure rouge
        let borderRowColor = isPonctuel ? 'var(--gelpam-orange)' : (pdpStat === 'ok' ? 'var(--success)' : (pdpStat === 'nok' ? 'var(--danger)' : 'var(--gelpam-orange)'));

        let badgeType = isPonctuel
            ? `<span style="background: #f39c12; color: white; padding: 3px 8px; border-radius: 20px; font-weight: bold; font-size:0.7rem; white-space:nowrap;"><i class="fa-solid fa-clipboard-check"></i> ${I18N_ST.badge_pdp_ponctuel}</span>`
            : `<span style="background: #3498db; color: white; padding: 3px 8px; border-radius: 20px; font-weight: bold; font-size:0.7rem; white-space:nowrap;"><i class="fa-solid fa-rotate"></i> ${I18N_ST.badge_annuel}</span>`;

        let validiteAffichage = isPonctuel
            ? `<span style="color: #90a4ae; font-style: italic; font-size:0.78rem;">${I18N_ST.lie_au_chantier}</span>`
            : `${getPdpBadge(pdpStat)} <span style="color:#64748b; font-size:0.78rem; margin-left:4px;">${formatDate(e.date_fin_pdp)}</span>`;

        let btnPdf = `<a href="generateur_pdp.php?id_ee=${e.id}" target="_blank" onclick="event.stopPropagation();" style="background:#f8fafc; color:#8e44ad; border:1px solid #cbd5e1; padding:5px 7px; border-radius:4px; font-size:0.75rem; text-decoration:none; display:inline-flex; align-items:center;" title="${I18N_ST.tooltip_pdp_vierge}"><i class="fa-solid fa-file-shield"></i></a>`;
        if (e.fichier_pdp) {
            btnPdf += `<a href="${e.fichier_pdp}" target="_blank" onclick="event.stopPropagation();" style="background:#f1f5f9; color:#e74c3c; border:1px solid #cbd5e1; padding:5px 7px; border-radius:4px; font-size:0.75rem; text-decoration:none; display:inline-flex; align-items:center; margin-left:4px;" title="${I18N_ST.tooltip_voir_pdp_existant}"><i class="fa-solid fa-file-pdf"></i></a>`;
        }

        return `
        <tr onclick="ouvrirModalDetailEntreprise('${e.id}')" style="cursor:pointer; border-left: 4px solid ${borderRowColor};" onmouseover="this.style.background='#f8fafc';" onmouseout="this.style.background='';">
            <td style="padding:12px 15px; border-bottom:1px solid #f1f5f9; font-weight:800; color:var(--primary); font-size:0.85rem; max-width:220px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;" title="${e.nom}">${e.nom}</td>
            <td style="padding:12px 15px; border-bottom:1px solid #f1f5f9; font-size:0.82rem; color:#455a64;">${e.contact_nom || '<span style="color:#cbd5e1;">—</span>'}</td>
            <td style="padding:12px 15px; border-bottom:1px solid #f1f5f9;">${badgeType}</td>
            <td style="padding:12px 15px; border-bottom:1px solid #f1f5f9; white-space:nowrap;">${validiteAffichage}</td>
            <td style="padding:12px 15px; border-bottom:1px solid #f1f5f9; white-space:nowrap;">${btnPdf}</td>
            <td style="padding:12px 15px; border-bottom:1px solid #f1f5f9; text-align:right; white-space:nowrap;">
                <button onclick='event.stopPropagation(); editerEntreprise(${JSON.stringify(e).replace(/'/g, "&#39;")})' style="background:#e0f2fe; border:none; color:var(--accent); padding:6px 8px; border-radius:4px; cursor:pointer; font-size:0.78rem;" title="${I18N_ST.tooltip_modifier}"><i class="fa-solid fa-pen"></i></button>
                <button onclick="event.stopPropagation(); supprimerEntreprise('${e.id}')" style="background:#ffebee; border:none; color:var(--danger); padding:6px 8px; border-radius:4px; cursor:pointer; font-size:0.78rem; margin-left:4px;" title="${I18N_ST.tooltip_supprimer}"><i class="fa-solid fa-trash"></i></button>
            </td>
        </tr>`;
    }).join('');
}

function editerEntreprise(e) {
    document.getElementById('formTitle').innerText = I18N_ST.form_title_modifier;
    document.getElementById('e-id').value = e.id;
    document.getElementById('e-nom').value = e.nom;
    document.getElementById('e-contact').value = e.contact_nom || '';
    document.getElementById('e-tel').value = e.telephone || '';
    document.getElementById('e-email').value = e.mail || '';
    document.getElementById('e-type-pdp').value = e.type_pdp || 'Annuel';
    document.getElementById('e-pdp').value = e.date_fin_pdp || '';
    document.getElementById('e-fichier').value = ''; 
    document.getElementById('e-pdp-supprime').value = '0';
    
    const divFichierActuel = document.getElementById('e-fichier-actuel');
    if (e.fichier_pdp) {
        divFichierActuel.innerHTML = `
            <span style="display: inline-flex; align-items: center; gap: 8px; background: #f1f5f9; padding: 4px 8px; border-radius: 4px;">
                <i class="fa-solid fa-paperclip"></i> <a href="${e.fichier_pdp}" target="_blank" style="color:var(--accent); text-decoration:none;">${I18N_ST.voir_pdf}</a>
                <button type="button" onclick="detacherPdf()" style="background:none; border:none; color:var(--danger); cursor:pointer; padding: 0 2px; font-size:0.85rem;" title="${I18N_ST.tooltip_supprimer_pdf}"><i class="fa-solid fa-trash"></i></button>
            </span>`;
    } else {
        divFichierActuel.innerHTML = "";
    }
    document.getElementById('modalFormEntreprise').style.display = 'block';
}

function resetFormEntreprise() {
    document.getElementById('formTitle').innerText = I18N_ST.form_title_ajouter;
    document.getElementById('e-id').value = '';
    document.getElementById('e-nom').value = '';
    document.getElementById('e-contact').value = '';
    document.getElementById('e-tel').value = '';
    document.getElementById('e-email').value = '';
    document.getElementById('e-type-pdp').value = 'Annuel';
    document.getElementById('e-pdp').value = '';
    document.getElementById('e-fichier').value = '';
    document.getElementById('e-pdp-supprime').value = '0';
    document.getElementById('e-fichier-actuel').innerHTML = "";
    document.getElementById('modalFormEntreprise').style.display = 'block';
}

function detacherPdf() {
    document.getElementById('e-pdp-supprime').value = '1';
    document.getElementById('e-fichier-actuel').innerHTML = `<span style='color:var(--danger); font-style:italic; font-size:0.8rem;'><i class='fa-solid fa-circle-info'></i> ${I18N_ST.pdf_programme_suppression}</span>`;
}

async function sauvegarderEntreprise() {
    const nom = document.getElementById('e-nom').value;
    if(!nom) return await aspirineAlert(I18N_ST.err_title, I18N_ST.err_nom_obligatoire);
    
    const fd = new FormData(); 
    fd.append('action_entreprise', 'save');
    fd.append('id', document.getElementById('e-id').value);
    fd.append('nom', nom);
    fd.append('contact', document.getElementById('e-contact').value);
    fd.append('telephone', document.getElementById('e-tel').value);
    fd.append('mail', document.getElementById('e-email').value);
    fd.append('type_pdp', document.getElementById('e-type-pdp').value);
    fd.append('date_pdp', document.getElementById('e-pdp').value);
    fd.append('supprimer_pdp', document.getElementById('e-pdp-supprime').value);
    
    const fileInput = document.getElementById('e-fichier');
    if(fileInput.files.length > 0) {
        fd.append('fichier_pdp', fileInput.files[0]);
    }
    
    const response = await fetch('sous_traitants.php', { method: 'POST', body: fd });
    if (response.ok) {
        location.reload(); 
    } else {
        const errText = await response.text();
        await aspirineAlert(I18N_ST.err_title, I18N_ST.err_serveur_prefix + errText);
    }
}

function ouvrirModalDetailEntreprise(id) {
    const entreprise = toutesEntreprises.find(x => x.id == id);
    if (!entreprise) return;

    document.getElementById('detail-titre-entreprise').innerText = entreprise.nom;
    document.getElementById('detail-contact').innerText = entreprise.contact_nom || '-';
    
    const elTel = document.getElementById('detail-tel');
    if(entreprise.telephone) { elTel.innerText = entreprise.telephone; elTel.style.color = "inherit"; elTel.style.fontStyle = "normal"; }
    else { elTel.innerText = I18N_ST.non_renseigne; elTel.style.color = "#94a3b8"; elTel.style.fontStyle = "italic"; }

    const elEmail = document.getElementById('detail-email');
    if(entreprise.mail) { elEmail.innerText = entreprise.mail; elEmail.style.color = "inherit"; elEmail.style.fontStyle = "normal"; }
    else { elEmail.innerText = I18N_ST.non_renseigne; elEmail.style.color = "#94a3b8"; elEmail.style.fontStyle = "italic"; }

    let isPonctuel = (entreprise.type_pdp === 'Ponctuel');

    document.getElementById('detail-type-pdp').innerText = entreprise.type_pdp || 'Annuel';
    document.getElementById('detail-date-pdp').innerText = isPonctuel ? I18N_ST.non_applicable_chantier : formatDate(entreprise.date_fin_pdp);

    let pdpStat = isPonctuel ? 'ok' : getPdpStatus(entreprise.date_fin_pdp);

    document.getElementById('detail-badge-pdp').innerHTML = isPonctuel
        ? `<span class="pdp-badge" style="background:#fff3cd; color:#f39c12; border:1px solid #ffe69c;"><i class="fa-solid fa-clipboard-check"></i> ${I18N_ST.badge_pdp_ponctuel_alt}</span>`
        : getPdpBadge(pdpStat);

    const divPdf = document.getElementById('detail-action-pdf');
    if (entreprise.fichier_pdp) {
        divPdf.innerHTML = `<a href="${entreprise.fichier_pdp}" target="_blank" style="background:var(--accent); color:white; border:none; padding:6px 12px; border-radius:6px; cursor:pointer; font-weight:bold; font-size:0.75rem; text-decoration:none; display:inline-flex; align-items:center; gap:6px;"><i class="fa-solid fa-file-pdf"></i> ${I18N_ST.btn_ouvrir_plan_prevention}</a>`;
    } else {
        divPdf.innerHTML = `<span style="color:#94a3b8; font-style:italic; font-size:0.8rem;"><i class="fa-solid fa-circle-xmark"></i> ${I18N_ST.aucun_document_pdf}</span>`;
    }

    const tbody = document.getElementById('detailHistoriqueSocieteTable');
    const histoFiltre = stHistorique.filter(h => h.entreprise_ext_id == id || h.nom_entreprise === entreprise.nom);

    if (histoFiltre.length === 0) {
        tbody.innerHTML = `<tr><td colspan="5" style="text-align:center; padding:15px; color:#94a3b8; font-style:italic;">${I18N_ST.aucune_intervention_cloturee}</td></tr>`;
    } else {
        tbody.innerHTML = histoFiltre.map(h => {
            let dateFormatee = formatDate(h.date);

            // Bouton cliquable orange
            let biHtml = h.num_bi && h.id 
                ? `<a href="#" onclick="showDetailBI('${h.id}'); return false;" style="color: #d35400; font-weight: 800; background: #fef5e7; border: 1px solid #f9e79f; padding: 2px 5px; border-radius: 4px; text-decoration: none; font-size: 0.65rem; display: inline-block;" title="${I18N_ST.ouvrir_ri} ${h.num_bi}">#${h.num_bi}</a>`
                : `<span style="color: #95a5a6; font-size: 0.65rem; font-style:italic;">${I18N_ST.prev_fallback}</span>`;

            // Techniciens multiples
            let techsSet = new Set();
            if (h.tech && h.tech.trim() !== "") techsSet.add(h.tech.trim());
            allPointages.forEach(p => {
                if (p.task_id == h.id && p.tech && p.tech.trim() !== "") techsSet.add(p.tech.trim());
            });
            let allTechs = Array.from(techsSet).join(', ');
            let techDisplay = allTechs !== "" 
                ? `<div style="font-weight: 600; color: #34495e; font-size: 0.75rem;"><i class="fa-solid fa-users" style="color:#90a4ae;"></i> ${allTechs}</div>` 
                : `<span style="color: #95a5a6; font-style: italic;">${I18N_ST.non_assigne}</span>`;
            
            // Troncature du compte-rendu avec affichage total au survol
            let crSafe = (h.compte_rendu || '').replace(/"/g, '&quot;');
            let crDisplay = h.compte_rendu 
                ? `<div style="display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical; overflow:hidden; text-overflow:ellipsis; white-space:normal; line-height:1.4;" title="${crSafe}">${h.compte_rendu}</div>`
                : `<span style="color:#94a3b8;">${I18N_ST.aucun_rapport}</span>`;
            
            let localisation = [h.usine, h.secteur, h.zone].filter(Boolean).join(' > ');

            return `
            <tr style="border-bottom: 1px solid #eef2f3; background: white; transition: 0.15s;" onmouseover="this.style.background='rgba(52, 152, 219, 0.02)'" onmouseout="this.style.background='white'">
                <td style="padding: 10px; font-weight: bold; vertical-align: middle;">${biHtml}</td>
                <td style="padding: 10px; white-space: nowrap; color: #7f8c8d; font-size: 0.75rem; font-weight: 600; vertical-align: middle;">${dateFormatee}</td>
                <td style="padding: 10px; vertical-align: middle;">
                    ${localisation ? `<div style="font-size: 0.55rem; color: var(--accent); font-weight: bold; text-transform: uppercase; margin-bottom: 1px;"><i class="fa-solid fa-location-dot"></i> ${localisation}</div>` : ''}
                    <div style="font-weight: bold; color: var(--primary); font-size: 0.8rem;">${h.equip || '-'}</div>
                    <div style="font-size: 0.7rem; color: #5a6c7d; font-style: italic; margin-top: 1px; max-width: 300px; white-space: normal; word-break: break-word;">"${h.desc || ''}"</div>
                </td>
                <td style="padding: 10px; vertical-align: middle;">${techDisplay}</td>
                <td style="padding: 10px; color: #64748b; font-size: 0.75rem; font-style: italic; vertical-align: middle;">${crDisplay}</td>
            </tr>`;
        }).join('');
    }

    document.getElementById('modalDetailEntreprise').style.display = 'block';
}

async function supprimerEntreprise(id) {
    if (await aspirineConfirm(I18N_ST.confirmation_title, I18N_ST.confirm_suppr_entreprise)) {
        const fd = new URLSearchParams();
        fd.append('action_entreprise', 'delete');
        fd.append('id', id);
        await fetch('sous_traitants.php', { method: 'POST', body: fd });
        location.reload();
    }
}

function aspirineConfirm(titre, message) {
    return new Promise((resolve) => {
        const modal = document.getElementById('customConfirm');
        if (!modal) return resolve(confirm(message)); // Sécurité
        document.getElementById('confirmTitle').innerText = titre;
        document.getElementById('confirmMessage').innerText = message;
        modal.style.display = 'block';
        document.getElementById('confirmOk').onclick = () => { modal.style.display = 'none'; resolve(true); };
        document.getElementById('confirmCancel').onclick = () => { modal.style.display = 'none'; resolve(false); };
    });
}

function aspirineAlert(titre, message) {
    return new Promise((resolve) => {
        const modal = document.getElementById('customAlert');
        if (!modal) { alert(message); return resolve(); } // Sécurité
        document.getElementById('alertTitle').innerText = titre;
        document.getElementById('alertMessage').innerText = message;
        modal.style.display = 'block';
        document.getElementById('alertOk').onclick = () => { modal.style.display = 'none'; resolve(); };
    });
}

// --- GESTION DE LA CLÔTURE OBLIGATOIRE ---
function ouvrirModaleClotureST(id) {
    const ee = stSurSite.find(x => x.id == id);
    const nomEntreprise = ee ? (ee.nom_entreprise || ee.tech || I18N_ST.entreprise_inconnue) : I18N_ST.entreprise_inconnue;

    document.getElementById('st-cloture-id').value = id;
    document.getElementById('st-cloture-nom').innerText = nomEntreprise;
    document.getElementById('st-cloture-cr').value = ""; 
    document.getElementById('modalClotureST').style.display = 'flex';
}

async function validerClotureST() {
    const id = document.getElementById('st-cloture-id').value;
    const cr = document.getElementById('st-cloture-cr').value.trim();

    if (cr === "") {
        await aspirineAlert(I18N_ST.err_title, I18N_ST.err_cr_obligatoire);
        return;
    }

    try {
        const resTasks = await fetch('api.php?t=' + Date.now());
        const allTasks = await resTasks.json();
        const t = allTasks.find(x => x.id === id);

        if (!t) {
            await aspirineAlert(I18N_ST.err_title, I18N_ST.err_ticket_introuvable);
            return;
        }

        t.statut = "Terminée";
        t.compte_rendu = cr;
        t.action_user = "<?php echo $_SESSION['user']; ?>";

        const response = await fetch('api.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(t)
        });

        if (response.ok) {
            document.getElementById('modalClotureST').style.display = 'none';
            location.reload(); 
        } else {
            await aspirineAlert(I18N_ST.err_title, I18N_ST.err_sauvegarde_cr);
        }
    } catch (e) {
        await aspirineAlert(I18N_ST.err_network_title, I18N_ST.err_reseau_prefix + e.message);
    }
}

// Initialisation de la grille
renderDashboard();

// --- GESTION DES LISTES EN CASCADE POUR SOUS-TRAITANTS ---
let dbMachinesST = [];
try { let pData = <?php echo $json_machines ?: '[]'; ?>; if(Array.isArray(pData)) dbMachinesST = pData; } catch(e) {}

function initCascadeST() {
    const usines = [...new Set(dbMachinesST.map(m => m.usine))].sort();
    document.getElementById('new-st-usine').innerHTML = `<option value="">${I18N_ST.select_usine}</option>` + usines.map(u => `<option value="${u}">${u}</option>`).join('');
}

function updateSecteursST() {
    const usine = document.getElementById('new-st-usine').value;
    const s = document.getElementById('new-st-secteur');
    if (!usine) { s.disabled = true; return; }
    const secteurs = [...new Set(dbMachinesST.filter(m => m.usine === usine).map(m => m.secteur))].sort();
    s.innerHTML = `<option value="">${I18N_ST.select_secteur}</option>` + secteurs.map(x => `<option value="${x}">${x}</option>`).join('');
    s.disabled = false;
}

function updateZonesST() {
    const u = document.getElementById('new-st-usine').value;
    const s = document.getElementById('new-st-secteur').value;
    const z = document.getElementById('new-st-zone');
    if (!s) { z.disabled = true; return; }
    const zones = [...new Set(dbMachinesST.filter(m => m.usine === u && m.secteur === s).map(m => m.zone))].sort();
    z.innerHTML = `<option value="">${I18N_ST.select_zone}</option>` + zones.map(x => `<option value="${x}">${x}</option>`).join('');
    z.disabled = false;
}

function updateMachinesST() {
    const u = document.getElementById('new-st-usine').value;
    const s = document.getElementById('new-st-secteur').value;
    const z = document.getElementById('new-st-zone').value;
    const e = document.getElementById('new-st-equip');
    if (!z) { e.disabled = true; return; }
    const machines = dbMachinesST.filter(m => m.usine === u && m.secteur === s && m.zone === z).sort((a,b) => a.nom_machine.localeCompare(b.nom_machine));
    e.innerHTML = `<option value="">${I18N_ST.select_machine}</option>` + machines.map(m => `<option value="${m.nom_machine}">${m.nom_machine}</option>`).join('');
    e.disabled = false;
}

initCascadeST();

// --- LOGIQUE CRÉATION ET MODIFICATION D'UNE PLANIFICATION S.T. ---
const currentUserConnecte = "<?php echo $_SESSION['user']; ?>";

function ouvrirModalPlanifST() {
    document.getElementById('new-st-id').value = '';
    document.getElementById('modalPlanifTitle').innerHTML = '<i class="fa-solid fa-calendar-plus" style="color:var(--accent);"></i> ' + I18N_ST.modal_planif_title_new;
    document.getElementById('btnSavePlanifST').innerHTML = '<i class="fa-solid fa-calendar-check"></i> ' + I18N_ST.btn_creer_bon;

    document.querySelectorAll('input[name="entreprise_multi[]"]').forEach(cb => cb.checked = false);
    document.getElementById('new-st-type').value = 'Chantier';
    document.getElementById('new-st-casse').checked = false;
    document.getElementById('new-st-date').value = new Date().toISOString().split('T')[0];
    document.getElementById('new-st-time').value = '08:00';
    document.getElementById('new-st-tech').value = currentUserConnecte;

    document.getElementById('new-st-usine').value = '';
    document.getElementById('new-st-secteur').innerHTML = `<option value="">${I18N_ST.select_en_attente}</option>`; document.getElementById('new-st-secteur').disabled = true;
    document.getElementById('new-st-zone').innerHTML = `<option value="">${I18N_ST.select_en_attente}</option>`; document.getElementById('new-st-zone').disabled = true;
    document.getElementById('new-st-equip').innerHTML = `<option value="">${I18N_ST.select_en_attente}</option>`; document.getElementById('new-st-equip').disabled = true;
    
    document.getElementById('new-st-desc').value = '';
    document.getElementById('modalPlanifST').style.display = 'block';
}

function editerPlanifST(id) {
    const ee = stAVenir.find(x => x.id === id);
    if (!ee) return;

    document.getElementById('new-st-id').value = ee.id;
    document.getElementById('modalPlanifTitle').innerHTML = '<i class="fa-solid fa-pen-to-square" style="color:var(--accent);"></i> ' + I18N_ST.modal_planif_title_edit;
    document.getElementById('btnSavePlanifST').innerHTML = '<i class="fa-solid fa-rotate"></i> ' + I18N_ST.btn_maj_bon;

    document.querySelectorAll('input[name="entreprise_multi[]"]').forEach(cb => cb.checked = false);
    const targetCheckbox = document.querySelector(`input[name="entreprise_multi[]"][value="${ee.entreprise_ext_id}"]`);
    if(targetCheckbox) targetCheckbox.checked = true;
    
    document.getElementById('new-st-type').value = ee.type || 'Chantier';
    document.getElementById('new-st-casse').checked = (ee.casse == 1 || ee.casse === "1" || ee.casse === true);
    document.getElementById('new-st-date').value = ee.date ? ee.date.split(' ')[0] : '';
    document.getElementById('new-st-time').value = ee.date && ee.date.includes(' ') ? ee.date.split(' ')[1].substring(0, 5) : '08:00';
    document.getElementById('new-st-tech').value = ee.tech || currentUserConnecte;
    document.getElementById('new-st-desc').value = ee.desc || '';

    document.getElementById('new-st-usine').value = ee.usine || '';
    updateSecteursST();
    setTimeout(() => {
        document.getElementById('new-st-secteur').value = ee.secteur || '';
        updateZonesST();
        setTimeout(() => {
            document.getElementById('new-st-zone').value = ee.zone || '';
            updateMachinesST();
            setTimeout(() => {
                document.getElementById('new-st-equip').value = ee.equip || '';
            }, 50);
        }, 50);
    }, 50);

    document.getElementById('modalPlanifST').style.display = 'block';
}

async function validerNouvellePlanifST() {
    const idToEdit = document.getElementById('new-st-id').value;
    const isUpdate = (idToEdit !== "");

    const checkboxes = document.querySelectorAll('input[name="entreprise_multi[]"]:checked');
    const selectedEE = [];
    checkboxes.forEach(cb => {
        selectedEE.push(cb.value);
    });

    const date = document.getElementById('new-st-date').value;
    const time = document.getElementById('new-st-time').value || "08:00";
    const tech = document.getElementById('new-st-tech').value;
    const desc = document.getElementById('new-st-desc').value;
    
    const type = document.getElementById('new-st-type').value;
    const casse = document.getElementById('new-st-casse').checked ? 1 : 0;
    const usine = document.getElementById('new-st-usine').value;
    const secteur = document.getElementById('new-st-secteur').value;
    const zone = document.getElementById('new-st-zone').value;
    const equip = document.getElementById('new-st-equip').value;

    if(selectedEE.length === 0 || !date || !desc) {
        return await aspirineAlert(I18N_ST.err_title, I18N_ST.err_cocher_entreprise);
    }

    try {
        const resTasks = await fetch('api.php?t=' + Date.now());
        const allTasks = await resTasks.json();

        if (isUpdate) {
            let t = allTasks.find(x => x.id === idToEdit);
            if (!t) return await aspirineAlert(I18N_ST.err_title, I18N_ST.err_ticket_introuvable_maj);
            
            t.entreprise_ext_id = selectedEE[0]; 
            t.date = date + " " + time;
            t.tech = tech;
            t.desc = desc;
            t.type = type;
            t.casse = casse;
            t.usine = usine;
            t.secteur = secteur;
            t.zone = zone;
            t.equip = equip;
            t.action_user = currentUserConnecte;
            t.is_update = true;

            const responseTask = await fetch('api.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(t)
            });
        } else {
            for (let i = 0; i < selectedEE.length; i++) {
                const currentEEId = selectedEE[i];
                const now = new Date();
                
                const dateHeureReelle = now.getFullYear() + '-' + 
                    (now.getMonth() + 1).toString().padStart(2, '0') + '-' + 
                    now.getDate().toString().padStart(2, '0') + ' ' + 
                    now.getHours().toString().padStart(2, '0') + ':' + 
                    now.getMinutes().toString().padStart(2, '0');

                const annee = now.getFullYear().toString().slice(-2);
                const prefixeAnnee = "BI" + annee + "-";
                
                const resTasksLoop = await fetch('api.php?t=' + Date.now());
                const allTasksLoop = await resTasksLoop.json();
                const bonsAnnee = allTasksLoop.filter(x => x.num_bi && x.num_bi.startsWith(prefixeAnnee));
                
                let num_bi = prefixeAnnee + "001";
                if (bonsAnnee.length > 0) {
                    const numeros = bonsAnnee.map(x => parseInt(x.num_bi.split('-')[1]) || 0);
                    const max = Math.max(...numeros);
                    num_bi = prefixeAnnee + (max + 1).toString().padStart(3, '0');
                }

                const newId = "ID-" + Date.now() + "-" + i;

                const t = {
                    id: newId,
                    num_bi: num_bi,
                    tech: tech,
                    demandeur: currentUserConnecte,
                    is_sous_traitant: 1,
                    entreprise_ext_id: currentEEId,
                    usine: usine, secteur: secteur, zone: zone, equip: equip,
                    date: date + " " + time, 
                    date_creation: dateHeureReelle, 
                    hours: 0,
                    prio: "Normal",
                    type: type,
                    casse: casse,
                    desc: desc,
                    statut: "À faire",
                    compte_rendu: "",
                    action_user: currentUserConnecte,
                    is_update: false
                };

                const responseTask = await fetch('api.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(t)
                });

                if (responseTask.ok) {
                    const fdAssign = new URLSearchParams();
                    fdAssign.append('action', 'save_pointage');
                    fdAssign.append('task_id', t.id);
                    fdAssign.append('tech', tech);
                    fdAssign.append('hours', '0');
                    fdAssign.append('date', date);

                    await fetch('maintenance.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: fdAssign
                    });
                }
            }
        }

        document.getElementById('modalPlanifST').style.display = 'none';
        location.reload(); 

    } catch (e) {
        await aspirineAlert(I18N_ST.err_network_title, I18N_ST.err_reseau_prefix + e.message);
    }
}

function ouvrirModalHistorique() {
    const tbody = document.getElementById('historiqueTable');
    if (!stHistorique || stHistorique.length === 0) {
        tbody.innerHTML = `<tr><td colspan="6" style="text-align:center; padding:20px; color:#94a3b8; font-style:italic;">${I18N_ST.aucune_intervention_historique}</td></tr>`;
    } else {
        tbody.innerHTML = stHistorique.map(h => {
            let dateFormatee = formatDate(h.date);

            // Bouton cliquable orange
            let biHtml = h.num_bi && h.id 
                ? `<a href="#" onclick="showDetailBI('${h.id}'); return false;" style="color: #d35400; font-weight: 800; background: #fef5e7; border: 1px solid #f9e79f; padding: 2px 5px; border-radius: 4px; text-decoration: none; font-size: 0.65rem; display: inline-block;" title="${I18N_ST.ouvrir_ri} ${h.num_bi}">#${h.num_bi}</a>`
                : `<span style="color: #95a5a6; font-size: 0.65rem; font-style:italic;">${I18N_ST.prev_fallback}</span>`;

            // Techniciens multiples
            let techsSet = new Set();
            if (h.tech && h.tech.trim() !== "") techsSet.add(h.tech.trim());
            allPointages.forEach(p => {
                if (p.task_id == h.id && p.tech && p.tech.trim() !== "") techsSet.add(p.tech.trim());
            });
            let allTechs = Array.from(techsSet).join(', ');
            let techDisplay = allTechs !== "" 
                ? `<div style="font-weight: 600; color: #34495e; font-size: 0.75rem;"><i class="fa-solid fa-users" style="color:#90a4ae;"></i> ${allTechs}</div>` 
                : `<span style="color: #95a5a6; font-style: italic;">${I18N_ST.non_assigne}</span>`;
            
            // Troncature du compte-rendu avec affichage total au survol
            let crSafe = (h.compte_rendu || '').replace(/"/g, '&quot;');
            let crDisplay = h.compte_rendu 
                ? `<div style="display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical; overflow:hidden; text-overflow:ellipsis; white-space:normal; line-height:1.4;" title="${crSafe}">${h.compte_rendu}</div>`
                : `<span style="color:#94a3b8;">${I18N_ST.aucun_rapport}</span>`;
            
            let localisation = [h.usine, h.secteur, h.zone].filter(Boolean).join(' > ');

            return `
            <tr style="border-bottom: 1px solid #eef2f3; background: white; transition: 0.15s;" onmouseover="this.style.background='rgba(52, 152, 219, 0.02)'" onmouseout="this.style.background='white'">
                <td style="padding: 10px; font-weight: bold; vertical-align: middle;">${biHtml}</td>
                <td style="padding: 10px; white-space: nowrap; color: #7f8c8d; font-size: 0.75rem; font-weight: 600; vertical-align: middle;">${dateFormatee}</td>
                <td style="padding: 10px; font-weight: 800; color: var(--primary); font-size: 0.85rem; vertical-align: middle;">${h.nom_entreprise || 'Entreprise Inconnue'}</td>
                <td style="padding: 10px; vertical-align: middle;">
                    ${localisation ? `<div style="font-size: 0.55rem; color: var(--accent); font-weight: bold; text-transform: uppercase; margin-bottom: 1px;"><i class="fa-solid fa-location-dot"></i> ${localisation}</div>` : ''}
                    <div style="font-weight: bold; color: var(--primary); font-size: 0.8rem;">${h.equip || '-'}</div>
                    <div style="font-size: 0.7rem; color: #5a6c7d; font-style: italic; margin-top: 1px; max-width: 300px; white-space: normal; word-break: break-word;">"${h.desc || ''}"</div>
                </td>
                <td style="padding: 10px; vertical-align: middle;">${techDisplay}</td>
                <td style="padding: 10px; color: #64748b; font-size: 0.75rem; font-style: italic; vertical-align: middle;">${crDisplay}</td>
            </tr>`;
        }).join('');
    }
    document.getElementById('modalHistoriqueST').style.display = 'block';
}
</script>

<div id="customConfirm" style="display:none; position:fixed; z-index:99999; left:0; top:0; width:100%; height:100%; background:rgba(0,0,0,0.5); backdrop-filter: blur(3px);">
    <div style="background:white; width:350px; margin:15% auto; padding:20px; border-radius:12px; text-align:center; box-shadow: 0 10px 25px rgba(0,0,0,0.2); border-top: 5px solid var(--danger);">
        <i class="fa-solid fa-triangle-exclamation" style="font-size:3rem; color:var(--danger); margin-bottom:15px;"></i>
        <h3 id="confirmTitle" style="margin:10px 0; color:var(--primary);"></h3>
        <p id="confirmMessage" style="color:#666; font-size:0.9rem; margin-bottom:20px;"></p>
        <div style="display:flex; justify-content:center; gap:10px;">
            <button id="confirmCancel" style="padding:10px 20px; border:none; border-radius:6px; background:#eee; cursor:pointer; font-weight:bold; font-family: inherit;"><?php echo htmlspecialchars(t('maint.cancel')); ?></button>
            <button id="confirmOk" style="padding:10px 20px; border:none; border-radius:6px; background:var(--danger); color:white; cursor:pointer; font-weight:bold; font-family: inherit;"><?php echo htmlspecialchars(t('planning.btn_confirmer')); ?></button>
        </div>
    </div>
</div>

<div id="customAlert" style="display:none; position:fixed; z-index:99999; left:0; top:0; width:100%; height:100%; background:rgba(0,0,0,0.5); backdrop-filter: blur(3px);">
    <div style="background:white; width:350px; margin:15% auto; padding:20px; border-radius:12px; text-align:center; box-shadow: 0 10px 25px rgba(0,0,0,0.2); border-top: 5px solid #27ae60;">
        <i class="fa-solid fa-circle-check" style="font-size:3rem; color:#27ae60; margin-bottom:15px;"></i>
        <h3 id="alertTitle" style="margin:10px 0; color:var(--primary);"></h3>
        <p id="alertMessage" style="color:#666; font-size:0.9rem; margin-bottom:20px;"></p>
        <div style="display:flex; justify-content:center;">
            <button id="alertOk" style="padding:10px 30px; border:none; border-radius:6px; background:#27ae60; color:white; cursor:pointer; font-weight:bold; font-family: inherit;"><?php echo htmlspecialchars(t('maint.ok')); ?></button>
        </div>
    </div>
</div>

<?php include 'composant_rapport.php'; ?>

</body>
</html>