<?php
require_once __DIR__ . '/session_init.php';
require_once 'db.php';

if (!isset($_SESSION['user'])) { header("Location: login.php"); exit(); }

// --- 1. MOTEUR AJAX : VÉRIFICATION ET AUTO-ENREGISTREMENT DU CODE ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'verify_pin') {
    header('Content-Type: application/json');
    $id_demandeur = $_POST['id_demandeur'];
    $pin = trim($_POST['pin']);

    try {
        $stmt = $db->prepare("SELECT code_personnel, prenom, username as nom, fonction FROM utilisateurs WHERE id = ?");
        $stmt->execute([$id_demandeur]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user) {
            if (empty($user['code_personnel'])) {
                $stmtUpdate = $db->prepare("UPDATE utilisateurs SET code_personnel = ? WHERE id = ?");
                $stmtUpdate->execute([$pin, $id_demandeur]);
                $user['code_personnel'] = $pin;
                echo json_encode(['success' => true, 'first_time' => true, 'user' => $user]);
            }
            elseif ($user['code_personnel'] === $pin) {
                echo json_encode(['success' => true, 'first_time' => false, 'user' => $user]);
            }
            else {
                echo json_encode(['success' => false, 'error' => 'bad_pin']);
            }
        } else {
            echo json_encode(['success' => false, 'error' => 'not_found']);
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => 'db_error']);
    }
    exit();
}
// --------------------------------------------------------

$user_session = trim($_SESSION['user']);

// --- 2. RÉCUPÉRATION DYNAMIQUE DU PERSONNEL ET MACHINES ---
$personnel = [];
$fonctions = [];
$machines_db = [];

try {
    $stmtRole = $db->prepare("SELECT role FROM utilisateurs WHERE username = ?");
    $stmtRole->execute([$user_session]);
    $current_role = $stmtRole->fetchColumn();

    $stmt = $db->prepare("SELECT id, fonction, username as nom, prenom, code_personnel FROM utilisateurs WHERE role = ? AND (password IS NULL OR password = '') AND actif = 1 ORDER BY fonction ASC, username ASC");
    $stmt->execute([$current_role]);
    $personnel = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($personnel as $p) {
        if (!empty($p['fonction']) && !in_array($p['fonction'], $fonctions)) {
            $fonctions[] = $p['fonction'];
        }
    }

    $stmtMachines = $db->query("SELECT usine, secteur, ligne, zone, nom_machine FROM machines ORDER BY usine, secteur, ligne, zone, nom_machine");
    if ($stmtMachines) {
        $machines_db = $stmtMachines->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Exception $e) {}

// --- BOUCLIER ANTI-CRASH JSON ---
function safe_json($data) {
    $json = json_encode($data, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
    return ($json === false || $json === 'null' || empty($json)) ? '[]' : $json;
}

$json_machines = safe_json($machines_db);
$json_personnel = safe_json($personnel);
$json_user_session = safe_json($_SESSION['user']);
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(langue_actuelle()); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title><?php echo htmlspecialchars(t('demande.page_title')); ?></title>
    <link rel="icon" type="image/png" href="img/logo.png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Caveat:wght@600;700&family=Segoe+UI:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root { --primary: #2c3e50; --accent: #3498db; --gelpam-orange: #f39c12; --gelpam-green: #2ecc71; --danger: #e74c3c; --violet: #8e44ad; --soft-blue: #ebf5fb; --soft-green: #e8f8f5; }
        * { box-sizing: border-box; }

        body {
            margin: 0; font-family: 'Segoe UI', sans-serif;
            background: linear-gradient(rgba(0,0,0,0.5), rgba(0,0,0,0.5)), url('img/fond.jpg') no-repeat center center fixed;
            background-size: cover; display: flex; justify-content: center; align-items: flex-start; min-height: 100vh; padding: 62px 20px 24px; box-sizing: border-box;
        }

        /* ============ FENÊTRE ASSISTANT ============ */
        .window-card { background: rgba(255, 255, 255, 0.98); border-radius: 18px; box-shadow: 0 15px 40px rgba(0,0,0,0.5); width: 960px; max-width: 100%; overflow: hidden; }

        .window-titlebar { display:flex; align-items:center; justify-content:space-between; padding: 18px 26px 14px; border-bottom: 1px solid #f1f5f9; }
        .window-titlebar h1 { font-family: 'Caveat', cursive; color: var(--primary); font-size: 1.9rem; margin: 0; display:flex; align-items:center; gap: 12px; }
        .window-titlebar h1 img { height: 34px; }
        .window-close { background:none; border:none; font-size: 1.9rem; line-height:1; color:#94a3b8; cursor:pointer; text-decoration:none; padding: 0 4px; }
        .window-close:hover { color: var(--danger); }

        .window-body { padding: 22px 26px 26px; }

        /* ============ WIZARD SHELL (repris du modèle "bon d'intervention") ============ */
        .wizard-shell { display: flex; gap: 24px; align-items: stretch; }
        .wizard-sidebar { width: 220px; flex: none; background: linear-gradient(165deg, #f8fafc, #eef2f7); border-radius: 14px; padding: 18px 14px; display: flex; flex-direction: column; }
        .wizard-sidebar-step { display: flex; align-items: flex-start; gap: 12px; padding: 10px 8px; border-radius: 10px; transition: 0.2s; }
        .wizard-sidebar-step.clickable { cursor: pointer; }
        .wizard-sidebar-step.clickable:hover { background: rgba(52,152,219,0.08); }
        .ss-icon { width: 34px; height: 34px; min-width: 34px; border-radius: 50%; background: #e2e8f0; color: #94a3b8; display: flex; align-items: center; justify-content: center; font-size: 0.9rem; transition: 0.3s; }
        .wizard-sidebar-step.active .ss-icon { background: var(--accent); color: #fff; box-shadow: 0 0 0 4px rgba(52,152,219,0.15); }
        .wizard-sidebar-step.done .ss-icon { background: var(--gelpam-green); color: #fff; }
        .wizard-sidebar-step.done.step-incomplete .ss-icon { background: var(--gelpam-orange); }
        .ss-text strong { display: block; font-size: 0.8rem; color: #94a3b8; font-weight: 800; line-height: 1.3; }
        .ss-text span { font-size: 0.66rem; color: #cbd5e1; }
        .wizard-sidebar-step.active .ss-text strong { color: var(--primary); }
        .wizard-sidebar-step.done .ss-text strong { color: var(--gelpam-green); }
        .wizard-sidebar-step.done.step-incomplete .ss-text strong { color: var(--gelpam-orange); }
        .wizard-sidebar-connector { width: 2px; height: 14px; background: #e2e8f0; margin-left: 25px; transition: 0.3s; }
        .wizard-sidebar-connector.done { background: var(--gelpam-green); }

        .wizard-sidebar-progress-wrap { margin-top: auto; padding-top: 16px; }
        .wizard-mini-progress-bar { height: 6px; background: #e2e8f0; border-radius: 3px; overflow: hidden; }
        .wizard-mini-progress-fill { height: 100%; background: linear-gradient(90deg, var(--accent), var(--gelpam-green)); transition: width 0.35s ease; width: 0%; }
        .wizard-mini-progress-label { font-size: 0.65rem; color: #94a3b8; font-weight: 700; margin-top: 7px; text-align: center; }

        .wizard-main { flex: 1; min-width: 0; display: flex; flex-direction: column; }
        .wizard-content { min-height: 380px; }
        .wizard-panel { display: none; animation: fadeInStep 0.25s ease; }
        .wizard-panel.active { display: block; }
        @keyframes fadeInStep { from { opacity: 0; transform: translateY(6px); } to { opacity: 1; transform: translateY(0); } }

        .wizard-panel-title { font-size: 1.2rem; font-weight: 800; color: var(--primary); display: flex; align-items: center; gap: 10px; margin-bottom: 4px; }
        .wizard-panel-desc { font-size: 0.8rem; color: #94a3b8; margin-bottom: 16px; }

        /* --- Bloc d'aide illustré, en haut de chaque étape --- */
        .wizard-step-help { display: flex; gap: 14px; align-items: center; background: #f7f9fb; border: 1px solid #e6e9ec; border-radius: 12px; padding: 12px 14px; margin-bottom: 18px; }
        .wizard-step-help img { width: 110px; height: 72px; object-fit: cover; object-position: top; border-radius: 8px; border: 1px solid #e2e8f0; flex-shrink: 0; background: #eef2f5; }
        .wizard-step-help-icon-fallback { width: 110px; height: 72px; border-radius: 8px; background: rgba(52,152,219,0.12); color: var(--accent); display: flex; align-items: center; justify-content: center; font-size: 1.7rem; flex-shrink: 0; }
        .wizard-step-help-text { font-size: 0.8rem; color: #5a6b7a; line-height: 1.45; }
        .wizard-step-help-text b { color: var(--primary); }

        /* --- Bulles d'aide au niveau des champs --- */
        .icon-help { color: var(--accent); cursor: pointer; font-size: 0.95rem; transition: transform 0.15s; margin-left: 4px; }
        .icon-help:hover { transform: scale(1.2); }

        .field { margin-bottom: 12px; }
        label, .field-label { display: flex; align-items:center; font-size: 0.7rem; font-weight: 600; color: #555; margin-bottom: 4px; text-transform: uppercase; }
        input, select, textarea { width: 100%; padding: 9px 10px; border: 1px solid #ddd; border-radius: 8px; box-sizing: border-box; font-size: 0.9rem; font-family: inherit; background: #fff; }
        input:focus, select:focus, textarea:focus { outline: none; border-color: var(--accent); box-shadow: 0 0 0 3px rgba(52,152,219,0.12); }
        select:disabled, input:disabled { background: #f1f5f9; color: #b0bac5; cursor: not-allowed; }

        .cascade-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-bottom: 4px; background: #f8fafc; padding: 14px; border-radius: 10px; border: 1px solid #e2e8f0;}
        .cascade-grid .field-label { color: var(--accent); font-size: 0.65rem;}
        .cascade-grid .field { margin-bottom: 0; }

        /* ============================================================
           LOCALISATION MACHINE — recherche + navigation par tuiles
           (même système que la création de bons d'intervention côté maintenance.php)
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

        .loc-breadcrumb { display: flex; flex-wrap: wrap; align-items: center; gap: 6px; margin: 14px 0 10px; grid-column: 1 / -1; }
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
        .loc-empty-msg { grid-column: 1 / -1; padding: 20px; text-align: center; color: #94a3b8; font-size: 0.85rem; background: #f8fafc; border-radius: 10px; }

        .loc-done-card { grid-column: 1 / -1; display: flex; align-items: center; justify-content: space-between; gap: 14px; background: var(--soft-green); border: 2px solid #b8ecd9; border-radius: 12px; padding: 16px; flex-wrap: wrap; }
        .loc-done-info { display: flex; align-items: center; gap: 12px; }
        .loc-done-icon { width: 42px; height: 42px; border-radius: 10px; background: var(--gelpam-green); color: #fff; display: flex; align-items: center; justify-content: center; font-size: 1.2rem; flex-shrink: 0; }
        .loc-done-name { font-weight: 800; color: var(--primary); font-size: 0.95rem; }
        .loc-done-path { font-size: 0.75rem; color: #5a8a76; margin-top: 2px; }
        .loc-done-change { background: #fff; border: 1px solid #cbd5e1; color: var(--primary); font-weight: 700; font-size: 0.78rem; padding: 8px 14px; border-radius: 8px; cursor: pointer; font-family: inherit; }
        .loc-done-change:hover { border-color: var(--accent); color: var(--accent); }

        .security-box { background: rgba(231, 76, 60, 0.05); padding: 14px; border-radius: 10px; border: 1px dashed var(--danger); margin-bottom: 10px;}
        .security-box input { border-color: #fab1a0; text-align: center; letter-spacing: 5px; font-weight: 700; font-size: 1.15rem; padding: 8px;}
        .security-box .field-label { color: var(--danger); }

        .pin-status { font-size: 0.68rem; font-weight: 600; text-align:center; margin-top: 6px; min-height: 14px; }
        .pin-status.ok { color: var(--gelpam-green); }
        .pin-status.wait { color: #94a3b8; }

        .urgency-toggle { display: flex; gap: 10px; margin-bottom: 4px; }
        .urgency-opt { flex:1; border: 2px solid #e2e8f0; border-radius: 10px; padding: 12px 8px; text-align:center; cursor:pointer; transition: 0.15s; background:#fff; }
        .urgency-opt i { font-size: 1.3rem; display:block; margin-bottom: 4px; }
        .urgency-opt .u-title { font-weight: 700; font-size: 0.8rem; }
        .urgency-opt .u-sub { font-size: 0.62rem; color: #94a3b8; margin-top: 2px; display:block; }
        .urgency-opt.normal.selected { border-color: var(--gelpam-green); background: rgba(46,204,113,0.08); }
        .urgency-opt.normal.selected i, .urgency-opt.normal.selected .u-title { color: var(--gelpam-green); }
        .urgency-opt.urgent.selected { border-color: var(--danger); background: rgba(231,76,60,0.08); }
        .urgency-opt.urgent.selected i, .urgency-opt.urgent.selected .u-title { color: var(--danger); }
        .urgency-opt:not(.selected) i, .urgency-opt:not(.selected) .u-title { color: #94a3b8; }

        .char-counter { text-align:right; font-size: 0.65rem; color: #b0bac5; margin-top: -6px; margin-bottom: 10px; }
        .char-counter.warn { color: var(--danger); font-weight: 600; }
        .char-counter.ready { color: var(--gelpam-green); font-weight: 600; }

        .photo-picker { display: flex; flex-wrap: wrap; gap: 10px; align-items: flex-start; margin-top: 6px; }
        .photo-add-btn { display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 4px; width: 76px; height: 76px; border: 2px dashed #cbd5e1; border-radius: 10px; color: var(--accent); font-size: 0.6rem; font-weight: 700; text-align: center; cursor: pointer; transition: border-color .15s, background .15s; flex-shrink: 0; }
        .photo-add-btn:hover { border-color: var(--accent); background: var(--soft-blue); }
        .photo-add-btn i { font-size: 1.2rem; }
        .photo-thumbs { display: flex; flex-wrap: wrap; gap: 10px; }
        .photo-thumb { position: relative; width: 76px; height: 76px; border-radius: 10px; overflow: hidden; border: 1px solid #e2e8f0; flex-shrink: 0; }
        .photo-thumb img { width: 100%; height: 100%; object-fit: cover; display: block; }
        .photo-thumb-remove { position: absolute; top: 3px; right: 3px; width: 20px; height: 20px; border-radius: 50%; background: rgba(15,23,42,0.65); color: #fff; border: none; display: flex; align-items: center; justify-content: center; cursor: pointer; font-size: 0.7rem; padding: 0; }
        .photo-thumb-remove:hover { background: var(--danger); }

        /* Récapitulatif */
        .recap-item { display:flex; gap: 10px; align-items:flex-start; padding: 10px 0; border-bottom: 1px dashed #e2e8f0; }
        .recap-item:last-child { border-bottom: none; }
        .recap-item i { color: var(--accent); width: 18px; text-align:center; margin-top: 2px; }
        .recap-label { font-size: 0.65rem; font-weight: 700; text-transform: uppercase; color: #94a3b8; }
        .recap-val { font-size: 0.88rem; color: var(--primary); font-weight: 600; word-break: break-word; }
        .recap-edit { margin-left: auto; font-size: 0.68rem; color: var(--accent); cursor: pointer; font-weight: 700; white-space: nowrap; }
        .recap-edit:hover { text-decoration: underline; }

        .wizard-nav { display: flex; justify-content: flex-end; align-items: center; margin-top: 22px; border-top: 1px solid #e2e8f0; padding-top: 18px; }
        .btn-wizard { border: none; font-weight: 700; border-radius: 8px; cursor: pointer; transition: 0.2s; font-size: 0.86rem; padding: 11px 20px; display: flex; align-items: center; gap: 8px; }
        .btn-wizard-prev { background: #f1f5f9; color: #64748b; }
        .btn-wizard-prev:hover { background: #e2e8f0; }
        .btn-wizard-next { background: var(--accent); color: #fff; }
        .btn-wizard-next:hover:not(:disabled) { background: #2980b9; }
        .btn-wizard-next:disabled { background: #cbd5e1; cursor: not-allowed; }
        .btn-wizard-submit { background: var(--gelpam-green); color: #fff; }
        .btn-wizard-submit:hover:not(:disabled) { background: #27ae60; }
        .btn-wizard-submit:disabled { background: #cbd5e1; cursor: not-allowed; }

        .helper-text { font-size: 0.65rem; color: var(--danger); font-weight: 600; text-align:center; margin-top: 8px; min-height: 14px; }

        /* ============ MODALES (aide + succès) ============ */
        .modal-overlay { display: none; position: fixed; z-index: 9999; left: 0; top: 0; width: 100%; height: 100%; background: rgba(15, 23, 42, 0.7); backdrop-filter: blur(4px); align-items: center; justify-content: center; }
        .modal-overlay.active { display: flex; }
        .modal-content-detail { background: white; padding: 22px 24px; border-radius: 14px; width: 95%; max-width: 480px; overflow: hidden; box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.3); position: relative; }
        .modal-close-x { position: absolute; top: 12px; right: 16px; font-size: 24px; cursor: pointer; color: #94a3b8; line-height: 1; transition: 0.2s;}
        .modal-close-x:hover { color: var(--danger); }

        .aide-hero { display: flex; align-items: center; gap: 14px; padding-bottom: 14px; margin-bottom: 14px; border-bottom: 1px solid #e2e8f0; }
        .aide-hero-icon { width: 46px; height: 46px; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 1.25rem; flex-shrink: 0; }
        .aide-hero-text h3 { margin: 0; font-size: 1.02rem; color: var(--primary); font-weight: 800; }
        .aide-hero-text p { margin: 3px 0 0; font-size: 0.78rem; color: #64748b; }
        .aide-body p { font-size: 0.87rem; color: #334155; line-height: 1.6; margin: 0 0 10px; }
        .aide-list { list-style: none; margin: 10px 0; padding: 0; display: flex; flex-direction: column; gap: 9px; }
        .aide-list li { display: flex; align-items: flex-start; gap: 10px; font-size: 0.85rem; color: #334155; line-height: 1.5; }
        .aide-list li i { margin-top: 3px; flex-shrink: 0; width: 16px; text-align: center; color: var(--accent); }
        .aide-callout { display: flex; gap: 10px; padding: 12px 14px; border-radius: 10px; font-size: 0.83rem; line-height: 1.5; margin-top: 12px; background: rgba(52,152,219,0.08); color: #1c5a85; }
        .aide-callout i { flex-shrink: 0; margin-top: 2px; }

        @media (max-width: 780px) {
            .window-card { width: 100%; }
            .wizard-shell { flex-direction: column; }
            .wizard-sidebar { width: 100%; flex-direction: row; flex-wrap: wrap; gap: 6px; padding: 12px; }
            .wizard-sidebar-connector { display: none; }
            .wizard-sidebar-step { flex: 1; min-width: 130px; }
            .wizard-sidebar-progress-wrap { display: none; }
            .wizard-step-help { flex-direction: column; align-items: stretch; }
            .wizard-step-help img, .wizard-step-help-icon-fallback { width: 100%; height: 90px; }
            .wizard-nav { flex-direction: column-reverse; gap: 10px; align-items: stretch; }
            .wizard-nav .btn-wizard { justify-content: center; }
        }

        .btn-floating-nav {
            position: fixed; top: 20px; left: 20px; z-index: 1000;
            width: 46px; height: 46px; border-radius: 50%;
            background: rgba(255, 255, 255, 0.12);
            backdrop-filter: blur(14px) brightness(1.15);
            -webkit-backdrop-filter: blur(14px) brightness(1.15);
            border: 1px solid rgba(255,255,255,0.4);
            color: white; font-size: 1.15rem; display: flex; align-items: center; justify-content: center;
            text-decoration: none; box-shadow: 0 8px 20px rgba(0,0,0,0.25);
            transition: transform 0.3s, box-shadow 0.3s, background 0.3s, border-color 0.3s;
        }
        .btn-floating-nav:hover { transform: translateY(-3px); }
        .btn-floating-nav.home:hover { background: rgba(46,204,113,0.3); border-color: rgba(46,204,113,0.6); box-shadow: 0 14px 26px -8px rgba(46,204,113,0.5), 0 8px 18px rgba(0,0,0,0.2); }
        .crumb-bar { position: fixed; top: 33px; left: 76px; z-index: 1000; display: flex; align-items: center; gap: 8px; font-size: 0.94rem; font-weight: 600; color: rgba(255,255,255,0.9); text-shadow: 0 1px 3px rgba(0,0,0,0.4); }
        .crumb-bar .crumb-home { color: inherit; text-decoration: none; display: inline-flex; align-items: center; gap: 5px; }
        .crumb-bar .crumb-home:hover { text-decoration: underline; }
        .crumb-sep { color: rgba(255,255,255,0.55); font-weight: 400; }
        .crumb-current { color: rgba(255,255,255,0.75); font-weight: 400; }
        @media (max-width: 600px) { .crumb-bar { display: none; } }
    </style>
<?php include 'pwa_head.php'; ?>
</head>
<body>

<a href="accueil.php" class="btn-floating-nav home" title="<?php echo htmlspecialchars(t('demande.home_tooltip')); ?>" aria-label="<?php echo htmlspecialchars(t('demande.home_tooltip')); ?>"><i class="fa-solid fa-house"></i></a>
<div style="position:fixed; top:20px; right:20px; z-index:1000;">
    <?php include 'lang_switcher.php'; ?>
</div>
<div class="crumb-bar">
    <a href="accueil.php" class="crumb-home"><?php echo htmlspecialchars(t('demande.crumb_portal')); ?></a>
    <span class="crumb-sep">/</span>
    <span class="crumb-current"><?php echo htmlspecialchars(t('demande.crumb_current')); ?></span>
</div>

<div class="window-card">
    <div class="window-titlebar">
        <h1><img src="img/logo.png" alt="Logo"> <?php echo htmlspecialchars(t('demande.window_title')); ?></h1>
        <a class="window-close" href="accueil.php" title="<?php echo htmlspecialchars(t('demande.window_close_tooltip')); ?>">&times;</a>
    </div>

    <div class="window-body">
        <div class="wizard-shell">

            <!-- SIDEBAR VERTICALE DES ÉTAPES -->
            <div class="wizard-sidebar" id="stepperSidebar">
                <div class="wizard-sidebar-step" data-step="1" onclick="tryGoToStep(1)">
                    <div class="ss-icon"><i class="fa-solid fa-id-badge"></i></div>
                    <div class="ss-text"><strong><?php echo htmlspecialchars(t('demande.step1_title')); ?></strong><span><?php echo htmlspecialchars(t('demande.step1_sub')); ?></span></div>
                </div>
                <div class="wizard-sidebar-connector" data-connector="1"></div>
                <div class="wizard-sidebar-step" data-step="2" onclick="tryGoToStep(2)">
                    <div class="ss-icon"><i class="fa-solid fa-map-location-dot"></i></div>
                    <div class="ss-text"><strong><?php echo htmlspecialchars(t('demande.step2_title')); ?></strong><span><?php echo htmlspecialchars(t('demande.step2_sub')); ?></span></div>
                </div>
                <div class="wizard-sidebar-connector" data-connector="2"></div>
                <div class="wizard-sidebar-step" data-step="3" onclick="tryGoToStep(3)">
                    <div class="ss-icon"><i class="fa-solid fa-comment-dots"></i></div>
                    <div class="ss-text"><strong><?php echo htmlspecialchars(t('demande.step3_title')); ?></strong><span><?php echo htmlspecialchars(t('demande.step3_sub')); ?></span></div>
                </div>
                <div class="wizard-sidebar-connector" data-connector="3"></div>
                <div class="wizard-sidebar-step" data-step="4" onclick="tryGoToStep(4)">
                    <div class="ss-icon"><i class="fa-solid fa-paper-plane"></i></div>
                    <div class="ss-text"><strong><?php echo htmlspecialchars(t('demande.step4_title')); ?></strong><span><?php echo htmlspecialchars(t('demande.step4_sub')); ?></span></div>
                </div>

                <div class="wizard-sidebar-progress-wrap">
                    <div class="wizard-mini-progress-bar"><div class="wizard-mini-progress-fill" id="progressBar"></div></div>
                    <div class="wizard-mini-progress-label" id="progressLabel"><?php echo htmlspecialchars(t('demande.progress_label', ['{n}' => '1', '{total}' => '4'])); ?></div>
                </div>
            </div>

            <div class="wizard-main">
                <div class="wizard-content">

                    <!-- ============ ÉTAPE 1 : IDENTITÉ ============ -->
                    <div class="wizard-panel" data-panel="1">
                        <div class="wizard-panel-title"><i class="fa-solid fa-id-badge" style="color:var(--accent);"></i> <?php echo htmlspecialchars(t('demande.s1_title')); ?></div>
                        <div class="wizard-panel-desc"><?php echo htmlspecialchars(t('demande.s1_desc')); ?></div>

                        <div class="wizard-step-help">
                            <img src="img/aide/demande_etape1_identite.png" alt="<?php echo htmlspecialchars(t('demande.s1_img_alt')); ?>" onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                            <div class="wizard-step-help-icon-fallback" style="display:none;"><i class="fa-solid fa-id-badge"></i></div>
                            <div class="wizard-step-help-text"><?php echo t('demande.s1_help'); ?></div>
                        </div>

                        <div class="field">
                            <span class="field-label"><?php echo htmlspecialchars(t('demande.label_service')); ?></span>
                            <input type="text" value="<?php echo htmlspecialchars($_SESSION['user']); ?>" readonly style="background:#f1f5f9; color: #94a3b8; cursor: not-allowed;">
                        </div>

                        <div class="field">
                            <span class="field-label"><?php echo htmlspecialchars(t('demande.label_fonction')); ?> <i class="fa-solid fa-circle-question icon-help" onclick="ouvrirAide('poste')"></i></span>
                            <select id="f-fonction" onchange="mettreAJourNoms(); validateStep1();">
                                <option value="" disabled selected><?php echo htmlspecialchars(t('demande.opt_choose_poste')); ?></option>
                                <?php foreach ($fonctions as $f): ?>
                                    <option value="<?php echo htmlspecialchars($f); ?>"><?php echo htmlspecialchars($f); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="field">
                            <span class="field-label"><?php echo htmlspecialchars(t('demande.label_nom')); ?> <i class="fa-solid fa-circle-question icon-help" onclick="ouvrirAide('nom')"></i></span>
                            <select id="f-identite" disabled onchange="validateStep1();">
                                <option value="" disabled selected><?php echo htmlspecialchars(t('demande.opt_choose_poste_first')); ?></option>
                            </select>
                        </div>

                        <div class="security-box">
                            <span class="field-label"><i class="fa-solid fa-lock"></i>&nbsp;<?php echo htmlspecialchars(t('demande.label_code')); ?> <i class="fa-solid fa-circle-question icon-help" onclick="ouvrirAide('code')"></i></span>
                            <input type="password" id="f-pin" placeholder="••••" maxlength="10" oninput="validateStep1();">
                            <div class="pin-status wait" id="pin-status"><?php echo htmlspecialchars(t('demande.pin_default')); ?></div>
                        </div>

                        <div class="helper-text" id="step1-error"></div>
                    </div>

                    <!-- ============ ÉTAPE 2 : LOCALISATION ============ -->
                    <div class="wizard-panel" data-panel="2">
                        <div class="wizard-panel-title"><i class="fa-solid fa-map-location-dot" style="color:var(--accent);"></i> <?php echo htmlspecialchars(t('demande.s2_title')); ?></div>
                        <div class="wizard-panel-desc"><?php echo htmlspecialchars(t('demande.s2_desc')); ?></div>

                        <div class="wizard-step-help">
                            <img src="img/aide/demande_etape2_localisation.png" alt="<?php echo htmlspecialchars(t('demande.s2_img_alt')); ?>" onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                            <div class="wizard-step-help-icon-fallback" style="display:none;"><i class="fa-solid fa-map-location-dot"></i></div>
                            <div class="wizard-step-help-text"><?php echo t('demande.s2_help'); ?></div>
                        </div>

                        <div class="cascade-grid">
                            <div class="field loc-search-field" id="loc-search-field">
                                <span class="field-label"><?php echo htmlspecialchars(t('demande.label_search')); ?> <i class="fa-solid fa-circle-question icon-help" onclick="ouvrirAide('machine')"></i></span>
                                <div class="loc-search-wrap">
                                    <i class="fa-solid fa-magnifying-glass loc-search-icon"></i>
                                    <input type="text" id="loc-search-input" placeholder="<?php echo htmlspecialchars(t('demande.search_placeholder')); ?>" autocomplete="off" oninput="rechercheMachine(this.value)" onfocus="rechercheMachine(this.value)">
                                    <div id="loc-search-results" class="loc-search-results"></div>
                                </div>
                            </div>

                            <div id="loc-breadcrumb" class="loc-breadcrumb"></div>
                            <div id="loc-tiles" class="loc-tiles"></div>

                            <!-- Champs réels du formulaire : pilotés par le picker visuel ci-dessus -->
                            <div id="container-usine" class="field" style="display:none;">
                                <span class="field-label"><?php echo htmlspecialchars(t('demande.label_usine')); ?></span>
                                <select id="f-usine" onchange="updateSecteurs(); validateStep2();"><option value=""><?php echo htmlspecialchars(t('demande.opt_usine')); ?></option></select>
                            </div>
                            <div id="container-secteur" class="field" style="display:none;">
                                <span class="field-label"><?php echo htmlspecialchars(t('demande.label_secteur')); ?></span>
                                <select id="f-secteur" onchange="updateLignes(); validateStep2();" disabled><option value=""><?php echo htmlspecialchars(t('demande.opt_secteur')); ?></option></select>
                            </div>
                            <div id="container-ligne" class="field" style="display:none;">
                                <span class="field-label"><?php echo htmlspecialchars(t('demande.label_ligne')); ?></span>
                                <select id="f-ligne" onchange="updateZones(); validateStep2();" disabled><option value=""><?php echo htmlspecialchars(t('demande.opt_ligne')); ?></option></select>
                            </div>
                            <div id="container-zone" class="field" style="display:none;">
                                <span class="field-label"><?php echo htmlspecialchars(t('demande.label_zone')); ?></span>
                                <select id="f-zone" onchange="updateMachines(); validateStep2();" disabled><option value=""><?php echo htmlspecialchars(t('demande.opt_zone')); ?></option></select>
                            </div>
                            <div id="container-equip" class="field" style="display:none;">
                                <span class="field-label"><?php echo htmlspecialchars(t('demande.label_machine')); ?> <i class="fa-solid fa-circle-question icon-help" onclick="ouvrirAide('machine')"></i></span>
                                <select id="f-equip" disabled onchange="validateStep2();" style="font-weight:600; color:var(--primary);"><option value=""><?php echo htmlspecialchars(t('demande.opt_machine')); ?></option></select>
                            </div>
                        </div>

                        <div class="helper-text" id="step2-error"></div>
                    </div>

                    <!-- ============ ÉTAPE 3 : PROBLÈME ============ -->
                    <div class="wizard-panel" data-panel="3">
                        <div class="wizard-panel-title"><i class="fa-solid fa-comment-dots" style="color:var(--accent);"></i> <?php echo htmlspecialchars(t('demande.s3_title')); ?></div>
                        <div class="wizard-panel-desc"><?php echo htmlspecialchars(t('demande.s3_desc')); ?></div>

                        <div class="wizard-step-help">
                            <img src="img/aide/demande_etape3_description.png" alt="<?php echo htmlspecialchars(t('demande.s3_img_alt')); ?>" onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                            <div class="wizard-step-help-icon-fallback" style="display:none;"><i class="fa-solid fa-comment-dots"></i></div>
                            <div class="wizard-step-help-text"><?php echo t('demande.s3_help'); ?></div>
                        </div>

                        <div class="field">
                            <span class="field-label"><?php echo htmlspecialchars(t('demande.label_description')); ?> <i class="fa-solid fa-circle-question icon-help" onclick="ouvrirAide('description')"></i></span>
                            <textarea id="f-desc" rows="4" placeholder="<?php echo htmlspecialchars(t('demande.desc_placeholder')); ?>" oninput="updateCharCounter(); validateStep3();"></textarea>
                        </div>
                        <div class="char-counter" id="char-counter"><?php echo htmlspecialchars(t('demande.counter_zero')); ?></div>

                        <span class="field-label" style="margin-top:6px;"><?php echo htmlspecialchars(t('demande.label_urgent_q')); ?> <i class="fa-solid fa-circle-question icon-help" onclick="ouvrirAide('urgence')"></i></span>
                        <div class="urgency-toggle">
                            <div class="urgency-opt normal selected" id="opt-normal" onclick="setUrgency('Normal')">
                                <i class="fa-solid fa-circle-check"></i>
                                <span class="u-title"><?php echo htmlspecialchars(t('demande.urgency_normal')); ?></span>
                                <span class="u-sub"><?php echo htmlspecialchars(t('demande.urgency_normal_sub')); ?></span>
                            </div>
                            <div class="urgency-opt urgent" id="opt-urgent" onclick="setUrgency('Urgent')">
                                <i class="fa-solid fa-bell"></i>
                                <span class="u-title"><?php echo htmlspecialchars(t('demande.urgency_urgent')); ?></span>
                                <span class="u-sub"><?php echo htmlspecialchars(t('demande.urgency_urgent_sub')); ?></span>
                            </div>
                        </div>

                        <div class="field" style="margin-top:10px;">
                            <span class="field-label"><?php echo htmlspecialchars(t('demande.label_photos')); ?> <i class="fa-solid fa-circle-question icon-help" onclick="ouvrirAide('photos')"></i></span>
                            <div class="photo-picker">
                                <label class="photo-add-btn" for="f-photos">
                                    <i class="fa-solid fa-camera"></i>
                                    <?php echo htmlspecialchars(t('demande.add_photo')); ?>
                                    <input type="file" id="f-photos" accept="image/*" multiple style="display:none;" onchange="onPhotosSelectedDemande(this.files)">
                                </label>
                                <div class="photo-thumbs" id="photo-thumbs-demande"></div>
                            </div>
                        </div>

                        <div class="helper-text" id="step3-error"></div>
                    </div>

                    <!-- ============ ÉTAPE 4 : RÉCAPITULATIF ============ -->
                    <div class="wizard-panel" data-panel="4">
                        <div class="wizard-panel-title"><i class="fa-solid fa-paper-plane" style="color:var(--accent);"></i> <?php echo htmlspecialchars(t('demande.s4_title')); ?></div>
                        <div class="wizard-panel-desc"><?php echo htmlspecialchars(t('demande.s4_desc')); ?></div>

                        <div class="wizard-step-help">
                            <img src="img/aide/demande_etape4_recap.png" alt="<?php echo htmlspecialchars(t('demande.s4_img_alt')); ?>" onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                            <div class="wizard-step-help-icon-fallback" style="display:none;"><i class="fa-solid fa-circle-check"></i></div>
                            <div class="wizard-step-help-text"><?php echo t('demande.s4_help'); ?></div>
                        </div>

                        <div id="recap-container" style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:10px; padding: 4px 14px; margin-bottom: 14px;"></div>

                        <div class="helper-text" id="step4-error"></div>
                    </div>

                </div>

                <div class="wizard-nav">
                    <div style="display:flex; gap:10px;">
                        <button class="btn-wizard btn-wizard-prev" id="btnPrev" onclick="prevStep()" style="display:none;"><i class="fa-solid fa-arrow-left"></i> <?php echo htmlspecialchars(t('demande.btn_prev')); ?></button>
                        <button class="btn-wizard btn-wizard-next" id="btnNext" onclick="nextStep()" disabled><?php echo htmlspecialchars(t('demande.btn_next')); ?> <i class="fa-solid fa-arrow-right"></i></button>
                        <button class="btn-wizard btn-wizard-submit" id="btn-submit" onclick="envoyerDemande()" style="display:none;"><i class="fa-solid fa-paper-plane"></i> <?php echo htmlspecialchars(t('demande.btn_submit')); ?></button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ============ MODALE BULLE D'AIDE ============ -->
<div id="modalAide" class="modal-overlay" onclick="if(event.target == this) fermerAide()">
    <div class="modal-content-detail">
        <span class="modal-close-x" onclick="fermerAide()">&times;</span>
        <div id="aideContent"></div>
    </div>
</div>

<!-- ============ MODALE SUCCÈS ============ -->
<div id="successModal" class="modal-overlay" style="z-index: 10000;">
    <div class="modal-content-detail" style="text-align:center; max-width:380px; border-top:4px solid var(--gelpam-green);">
        <div style="font-size: 50px; color: var(--gelpam-green); margin-bottom: 10px;"><i class="fa-solid fa-circle-check"></i></div>
        <h3 style="margin:0; color:var(--primary); font-size:1.5rem;"><?php echo htmlspecialchars(t('demande.success_title')); ?></h3>
        <p style="color:#64748b; font-size:0.9rem; margin-bottom:20px;"><?php echo htmlspecialchars(t('demande.success_text')); ?></p>
        <div style="display:flex; gap:10px;">
            <button onclick="location.reload()" style="flex:1; background:#f1f5f9; color:#64748b; border:none; padding:11px; border-radius:8px; font-weight:600; cursor:pointer;"><?php echo htmlspecialchars(t('demande.btn_new_request')); ?></button>
            <button onclick="location.href='suivi.php'" style="flex:1; background:var(--gelpam-green); color:white; border:none; padding:11px; border-radius:8px; font-weight:600; cursor:pointer;"><?php echo htmlspecialchars(t('demande.btn_view_tracking')); ?></button>
        </div>
    </div>
</div>

<script>
const dbMachines = <?php echo $json_machines; ?>;
const personnelData = <?php echo $json_personnel; ?>;
const sessionUser = <?php echo $json_user_session; ?>;

const I18N_DEMANDE = <?php echo json_encode([
    'progress_label' => t('demande.progress_label'),
    'err_step1' => t('demande.err_step1'),
    'pin_wait' => t('demande.pin_wait'),
    'pin_ok' => t('demande.pin_ok'),
    'pin_default' => t('demande.pin_default'),
    'err_step2' => t('demande.err_step2'),
    'counter_zero' => t('demande.counter_zero'),
    'counter_warn' => t('demande.counter_warn'),
    'counter_ready' => t('demande.counter_ready'),
    'err_step3' => t('demande.err_step3'),
    'recap_demandeur' => t('demande.recap_demandeur'),
    'recap_localisation' => t('demande.recap_localisation'),
    'recap_machine' => t('demande.recap_machine'),
    'recap_description' => t('demande.recap_description'),
    'recap_urgence' => t('demande.recap_urgence'),
    'recap_photos' => t('demande.recap_photos'),
    'recap_urgent_val' => t('demande.recap_urgent_val'),
    'recap_normal_val' => t('demande.recap_normal_val'),
    'recap_no_photo' => t('demande.recap_no_photo'),
    'recap_photo_one' => t('demande.recap_photo_one'),
    'recap_photo_many' => t('demande.recap_photo_many'),
    'recap_edit' => t('demande.recap_edit'),
    'recap_empty_loc' => t('demande.recap_empty_loc'),
    'opt_usine' => t('demande.opt_usine'),
    'opt_secteur' => t('demande.opt_secteur'),
    'opt_ligne' => t('demande.opt_ligne'),
    'opt_zone' => t('demande.opt_zone'),
    'opt_machine' => t('demande.opt_machine'),
    'opt_autre' => t('demande.opt_autre'),
    'opt_sans_nom' => t('demande.opt_sans_nom'),
    'free_input_placeholder' => t('demande.free_input_placeholder'),
    'label_machine' => t('demande.label_machine'),
    'label_usine' => t('demande.label_usine'),
    'label_secteur' => t('demande.label_secteur'),
    'label_ligne' => t('demande.label_ligne'),
    'label_zone' => t('demande.label_zone'),
    'change_machine' => t('demande.change_machine'),
    'no_options' => t('demande.no_options'),
    'no_machine_found' => t('demande.no_machine_found'),
    'unnamed' => t('demande.unnamed'),
    'remove_photo_tooltip' => t('demande.remove_photo_tooltip'),
    'err_missing_info' => t('demande.err_missing_info'),
    'btn_sending' => t('demande.btn_sending'),
    'btn_submit' => t('demande.btn_submit'),
    'err_bad_pin' => t('demande.err_bad_pin'),
    'err_save' => t('demande.err_save'),
    'err_network' => t('demande.err_network'),
    'opt_choose_poste_first' => t('demande.opt_choose_poste_first'),
    'opt_who_are_you' => t('login.who_are_you'),
]); ?>;

/* ================= BANQUE DE BULLES D'AIDE ================= */
const AIDE_DB = {
    poste: {
        icon: 'fa-user-tag', bg: 'rgba(52,152,219,0.12)', color: 'var(--accent)',
        title: <?php echo json_encode(t('demande.aide_poste_title')); ?>, subtitle: <?php echo json_encode(t('demande.aide_poste_sub')); ?>,
        body: <?php echo json_encode(t('demande.aide_poste_body')); ?>.replace('{user}', sessionUser)
    },
    nom: {
        icon: 'fa-user', bg: 'rgba(52,152,219,0.12)', color: 'var(--accent)',
        title: <?php echo json_encode(t('demande.aide_nom_title')); ?>, subtitle: <?php echo json_encode(t('demande.aide_nom_sub')); ?>,
        body: <?php echo json_encode(t('demande.aide_nom_body')); ?>
    },
    code: {
        icon: 'fa-lock', bg: 'rgba(231,76,60,0.1)', color: 'var(--danger)',
        title: <?php echo json_encode(t('demande.aide_code_title')); ?>, subtitle: <?php echo json_encode(t('demande.aide_code_sub')); ?>,
        body: <?php echo json_encode(t('demande.aide_code_body')); ?>
    },
    machine: {
        icon: 'fa-gears', bg: 'rgba(243,156,18,0.12)', color: 'var(--gelpam-orange)',
        title: <?php echo json_encode(t('demande.aide_machine_title')); ?>, subtitle: <?php echo json_encode(t('demande.aide_machine_sub')); ?>,
        body: <?php echo json_encode(t('demande.aide_machine_body')); ?>
    },
    description: {
        icon: 'fa-comment-dots', bg: 'rgba(52,152,219,0.12)', color: 'var(--accent)',
        title: <?php echo json_encode(t('demande.aide_description_title')); ?>, subtitle: <?php echo json_encode(t('demande.aide_description_sub')); ?>,
        body: <?php echo json_encode(t('demande.aide_description_body')); ?>
    },
    urgence: {
        icon: 'fa-bell', bg: 'rgba(231,76,60,0.1)', color: 'var(--danger)',
        title: <?php echo json_encode(t('demande.aide_urgence_title')); ?>, subtitle: <?php echo json_encode(t('demande.aide_urgence_sub')); ?>,
        body: <?php echo json_encode(t('demande.aide_urgence_body')); ?>
    },
    photos: {
        icon: 'fa-camera', bg: 'rgba(52,152,219,0.12)', color: 'var(--accent)',
        title: <?php echo json_encode(t('demande.aide_photos_title')); ?>, subtitle: <?php echo json_encode(t('demande.aide_photos_sub')); ?>,
        body: <?php echo json_encode(t('demande.aide_photos_body')); ?>
    }
};

function ouvrirAide(type) {
    const a = AIDE_DB[type] || { icon: 'fa-circle-question', bg: 'rgba(52,152,219,0.12)', color: 'var(--accent)', title: <?php echo json_encode(t('demande.aide_default_title')); ?>, subtitle: '', body: <?php echo json_encode(t('demande.aide_default_body')); ?> };
    document.getElementById('aideContent').innerHTML = `
        <div class="aide-hero">
            <div class="aide-hero-icon" style="background:${a.bg}; color:${a.color};"><i class="fa-solid ${a.icon}"></i></div>
            <div class="aide-hero-text"><h3>${a.title}</h3><p>${a.subtitle || ''}</p></div>
        </div>
        <div class="aide-body">${a.body}</div>
    `;
    document.getElementById('modalAide').classList.add('active');
}
function fermerAide() { document.getElementById('modalAide').classList.remove('active'); }

/* ================= WIZARD : NAVIGATION ================= */
let currentStepNum = 1;
let wizardUnlocked = 1;
const WIZARD_STEPS = 4;
let urgenceChoisie = "Normal";

function stepIsFilled(n) {
    if (n === 1) return !!document.getElementById('f-fonction').value && !!document.getElementById('f-identite').value && document.getElementById('f-pin').value.trim().length >= 4;
    if (n === 2) return !!document.getElementById('f-equip').value;
    if (n === 3) return document.getElementById('f-desc').value.trim().length >= 10;
    return true;
}

function updateStepperUI() {
    document.querySelectorAll('.wizard-sidebar-step').forEach(item => {
        const n = parseInt(item.dataset.step, 10);
        const done = n < currentStepNum;
        item.classList.toggle('active', n === currentStepNum);
        item.classList.toggle('done', done);
        item.classList.toggle('step-incomplete', done && !stepIsFilled(n));
        item.classList.toggle('clickable', n <= wizardUnlocked);
    });
    document.querySelectorAll('.wizard-sidebar-connector').forEach(c => {
        const n = parseInt(c.dataset.connector, 10);
        c.classList.toggle('done', n < currentStepNum);
    });
    document.getElementById('progressBar').style.width = ((currentStepNum - 1) / (WIZARD_STEPS - 1) * 100) + '%';
    document.getElementById('progressLabel').textContent = I18N_DEMANDE.progress_label.replace('{n}', currentStepNum).replace('{total}', WIZARD_STEPS);

    document.getElementById('btnPrev').style.display = currentStepNum === 1 ? 'none' : 'flex';
    document.getElementById('btnNext').style.display = currentStepNum === WIZARD_STEPS ? 'none' : 'flex';
    document.getElementById('btn-submit').style.display = currentStepNum === WIZARD_STEPS ? 'flex' : 'none';
}

function showStep(n) {
    document.querySelectorAll('.wizard-panel').forEach(p => p.classList.remove('active'));
    document.querySelector('.wizard-panel[data-panel="' + n + '"]').classList.add('active');
    currentStepNum = n;
    updateStepperUI();
    if (n === 4) buildRecap();
    revalidateCurrentStep();
}

function revalidateCurrentStep() {
    if (currentStepNum === 1) validateStep1();
    if (currentStepNum === 2) validateStep2();
    if (currentStepNum === 3) validateStep3();
}

function nextStep() {
    if (currentStepNum === 1 && !validateStep1(true)) return;
    if (currentStepNum === 2 && !validateStep2(true)) return;
    if (currentStepNum === 3 && !validateStep3(true)) return;
    const target = currentStepNum + 1;
    if (target > wizardUnlocked) wizardUnlocked = target;
    showStep(target);
}

function prevStep() { showStep(currentStepNum - 1); }
function tryGoToStep(n) { if (n <= wizardUnlocked) showStep(n); }

/* ================= ÉTAPE 1 : VALIDATION ================= */
function validateStep1(showError) {
    const fonction = document.getElementById('f-fonction').value;
    const identite = document.getElementById('f-identite').value;
    const pin = document.getElementById('f-pin').value.trim();
    const btn = document.getElementById('btnNext');
    const err = document.getElementById('step1-error');
    const pinStatus = document.getElementById('pin-status');

    let ok = !!fonction && !!identite && pin.length >= 4;
    if (currentStepNum === 1) btn.disabled = !ok;
    if (err) err.innerText = '';

    if (pin.length > 0 && pin.length < 4) {
        pinStatus.className = 'pin-status wait';
        pinStatus.innerText = I18N_DEMANDE.pin_wait;
    } else if (pin.length >= 4) {
        pinStatus.className = 'pin-status ok';
        pinStatus.innerText = I18N_DEMANDE.pin_ok;
    } else {
        pinStatus.className = 'pin-status wait';
        pinStatus.innerText = I18N_DEMANDE.pin_default;
    }

    if (showError && !ok) {
        err.innerText = I18N_DEMANDE.err_step1;
    }
    return ok;
}

/* ================= ÉTAPE 2 : VALIDATION ================= */
function validateStep2(showError) {
    const equip = document.getElementById('f-equip').value;
    const btn = document.getElementById('btnNext');
    const err = document.getElementById('step2-error');
    const ok = !!equip;
    if (currentStepNum === 2) btn.disabled = !ok;
    if (err) err.innerText = '';
    if (showError && !ok) {
        err.innerText = I18N_DEMANDE.err_step2;
    }
    return ok;
}

/* ================= ÉTAPE 3 : VALIDATION ================= */
function updateCharCounter() {
    const len = document.getElementById('f-desc').value.trim().length;
    const counter = document.getElementById('char-counter');
    if (len === 0) {
        counter.className = 'char-counter warn';
        counter.innerText = I18N_DEMANDE.counter_zero;
    } else if (len < 10) {
        counter.className = 'char-counter warn';
        counter.innerText = I18N_DEMANDE.counter_warn.replace('{n}', len);
    } else {
        counter.className = 'char-counter ready';
        counter.innerText = I18N_DEMANDE.counter_ready.replace('{n}', len);
    }
}

function setUrgency(val) {
    urgenceChoisie = val;
    document.getElementById('opt-normal').classList.toggle('selected', val === 'Normal');
    document.getElementById('opt-urgent').classList.toggle('selected', val === 'Urgent');
}

function validateStep3(showError) {
    const desc = document.getElementById('f-desc').value.trim();
    const btn = document.getElementById('btnNext');
    const err = document.getElementById('step3-error');
    const ok = desc.length >= 10;
    if (currentStepNum === 3) btn.disabled = !ok;
    if (err) err.innerText = '';
    if (showError && !ok) {
        err.innerText = I18N_DEMANDE.err_step3;
    }
    return ok;
}

/* ================= ÉTAPE 4 : RÉCAPITULATIF ================= */
function buildRecap() {
    const fonction = document.getElementById('f-fonction').value;
    const identiteSelect = document.getElementById('f-identite');
    const identiteLabel = identiteSelect.options[identiteSelect.selectedIndex] ? identiteSelect.options[identiteSelect.selectedIndex].text : '';
    const usine = document.getElementById('f-usine').value;
    const secteur = document.getElementById('f-secteur').value;
    const ligne = document.getElementById('f-ligne').value;
    const zone = document.getElementById('f-zone').value;
    const equip = document.getElementById('f-equip').value;
    const desc = document.getElementById('f-desc').value.trim();
    const loc = [usine, secteur, ligne, zone].filter(Boolean).join(' > ');

    const nbPhotos = selectedPhotosDemande.length;
    const photoLabel = nbPhotos > 0
        ? (nbPhotos > 1 ? I18N_DEMANDE.recap_photo_many.replace('{n}', nbPhotos) : I18N_DEMANDE.recap_photo_one.replace('{n}', nbPhotos))
        : I18N_DEMANDE.recap_no_photo;

    const rows = [
        { icon: 'fa-id-badge', label: I18N_DEMANDE.recap_demandeur, val: identiteLabel + ' (' + fonction + ')', step: 1 },
        { icon: 'fa-map-location-dot', label: I18N_DEMANDE.recap_localisation, val: loc || I18N_DEMANDE.recap_empty_loc, step: 2 },
        { icon: 'fa-gears', label: I18N_DEMANDE.recap_machine, val: equip, step: 2 },
        { icon: 'fa-comment-dots', label: I18N_DEMANDE.recap_description, val: desc, step: 3 },
        { icon: urgenceChoisie === 'Urgent' ? 'fa-bell' : 'fa-circle-check', label: I18N_DEMANDE.recap_urgence, val: urgenceChoisie === 'Urgent' ? I18N_DEMANDE.recap_urgent_val : I18N_DEMANDE.recap_normal_val, step: 3 },
        { icon: 'fa-camera', label: I18N_DEMANDE.recap_photos, val: photoLabel, step: 3 }
    ];

    document.getElementById('recap-container').innerHTML = rows.map(r => `
        <div class="recap-item">
            <i class="fa-solid ${r.icon}"></i>
            <div style="flex:1;">
                <div class="recap-label">${r.label}</div>
                <div class="recap-val">${(r.val || '').toString().replace(/</g,'&lt;')}</div>
            </div>
            <span class="recap-edit" onclick="showStep(${r.step})">${I18N_DEMANDE.recap_edit}</span>
        </div>
    `).join('');
}

/* ================= CASCADE LOCALISATION ================= */
function initCascade() {
    if (dbMachines.length === 0) {
        // Aucune machine en base : pas de picker visuel possible, on retombe sur une saisie libre visible.
        const cEquip = document.getElementById('container-equip');
        cEquip.innerHTML = `<span class="field-label">${I18N_DEMANDE.label_machine}</span><input id="f-equip" placeholder="${I18N_DEMANDE.free_input_placeholder}" oninput="validateStep2();">`;
        cEquip.style.display = '';
        document.getElementById('loc-search-field').style.display = 'none';
        return;
    }
    const usines = [...new Set(dbMachines.map(m => m.usine))].filter(Boolean).sort();
    document.getElementById('f-usine').innerHTML = `<option value="">${I18N_DEMANDE.opt_usine}</option>` + usines.map(u => `<option value="${u.replace(/"/g, '&quot;')}">${u}</option>`).join('');
    renderLocPicker();
}

function updateSecteurs() {
    const usine = document.getElementById('f-usine').value;
    const fSecteur = document.getElementById('f-secteur');
    if (!usine) { fSecteur.disabled = true; return; }
    const secteurs = [...new Set(dbMachines.filter(m => m.usine === usine).map(m => m.secteur).filter(Boolean))].sort();
    fSecteur.innerHTML = `<option value="">${I18N_DEMANDE.opt_secteur}</option>` + secteurs.map(s => `<option value="${s.replace(/"/g, '&quot;')}">${s}</option>`).join('');
    fSecteur.disabled = false;
}

function updateLignes() {
    const usine = document.getElementById('f-usine').value;
    const secteur = document.getElementById('f-secteur').value;
    const fLigne = document.getElementById('f-ligne');
    if (!secteur) { fLigne.disabled = true; return; }
    const lignes = [...new Set(dbMachines.filter(m => m.usine === usine && m.secteur === secteur).map(m => m.ligne).filter(Boolean))].sort();
    if (lignes.length > 0) {
        fLigne.innerHTML = `<option value="">${I18N_DEMANDE.opt_ligne}</option>` + lignes.map(l => `<option value="${l.replace(/"/g, '&quot;')}">${l}</option>`).join('');
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
    if (ligne) filtered = filtered.filter(m => m.ligne === ligne);

    const zones = [...new Set(filtered.map(m => m.zone).filter(Boolean))].sort();
    fZone.innerHTML = `<option value="">${I18N_DEMANDE.opt_zone}</option>` + zones.map(z => `<option value="${z.replace(/"/g, '&quot;')}">${z}</option>`).join('');
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
    if (ligne) filtered = filtered.filter(m => m.ligne === ligne);

    const machines = filtered.sort((a, b) => (a.nom_machine || '').localeCompare(b.nom_machine || ''));
    fEquip.innerHTML = `<option value="">${I18N_DEMANDE.opt_machine}</option>` + machines.map(m => {
        const nomOk = m.nom_machine ? m.nom_machine : I18N_DEMANDE.opt_sans_nom;
        return `<option value="${nomOk.replace(/"/g, '&quot;')}">${nomOk}</option>`;
    }).join('') + `<option value="${I18N_DEMANDE.opt_autre}">${I18N_DEMANDE.opt_autre}</option>`;
    fEquip.disabled = false;
}

// ============================================================================
// PICKER VISUEL DE LOCALISATION (recherche + navigation par tuiles)
// Même système que la création de bons d'intervention côté maintenance.php.
// Pilote les <select> f-usine/f-secteur/f-ligne/f-zone/f-equip existants.
// ============================================================================

function resetLocSelects() {
    document.getElementById('f-usine').value = '';
    const fSecteur = document.getElementById('f-secteur');
    fSecteur.innerHTML = `<option value="">${I18N_DEMANDE.opt_secteur}</option>`; fSecteur.disabled = true;
    const fLigne = document.getElementById('f-ligne');
    fLigne.innerHTML = `<option value="">${I18N_DEMANDE.opt_ligne}</option>`; fLigne.disabled = true;
    const fZone = document.getElementById('f-zone');
    fZone.innerHTML = `<option value="">${I18N_DEMANDE.opt_zone}</option>`; fZone.disabled = true;
    const fEquip = document.getElementById('f-equip');
    if (fEquip && fEquip.tagName === 'SELECT') { fEquip.innerHTML = `<option value="">${I18N_DEMANDE.opt_machine}</option>`; fEquip.disabled = true; }
    else if (fEquip) { fEquip.value = ''; }
    renderLocPicker();
    validateStep2();
}

function locHasLigneChoices() {
    const fLigne = document.getElementById('f-ligne');
    return [...fLigne.options].some(o => o.value && o.value !== 'N/A');
}

function locPickUsine(val) { document.getElementById('f-usine').value = val; updateSecteurs(); renderLocPicker(); validateStep2(); }
function locPickSecteur(val) { document.getElementById('f-secteur').value = val; updateLignes(); renderLocPicker(); validateStep2(); }
function locPickLigne(val) { document.getElementById('f-ligne').value = val; updateZones(); renderLocPicker(); validateStep2(); }
function locPickZone(val) { document.getElementById('f-zone').value = val; updateMachines(); renderLocPicker(); validateStep2(); }
function locPickEquip(val) { document.getElementById('f-equip').value = val; renderLocPicker(); validateStep2(); }

function locGoBackTo(level) {
    const fSecteur = document.getElementById('f-secteur');
    const fLigne = document.getElementById('f-ligne');
    const fZone = document.getElementById('f-zone');
    const fEquip = document.getElementById('f-equip');
    const isEquipSelect = fEquip && fEquip.tagName === 'SELECT';

    if (level === 'usine') { resetLocSelects(); return; }
    if (level === 'secteur') {
        fSecteur.value = '';
        fLigne.innerHTML = `<option value="">${I18N_DEMANDE.opt_ligne}</option>`; fLigne.disabled = true;
        fZone.innerHTML = `<option value="">${I18N_DEMANDE.opt_zone}</option>`; fZone.disabled = true;
        if (isEquipSelect) { fEquip.innerHTML = `<option value="">${I18N_DEMANDE.opt_machine}</option>`; fEquip.disabled = true; }
        updateSecteurs();
    } else if (level === 'ligne') {
        fLigne.value = '';
        fZone.innerHTML = `<option value="">${I18N_DEMANDE.opt_zone}</option>`; fZone.disabled = true;
        if (isEquipSelect) { fEquip.innerHTML = `<option value="">${I18N_DEMANDE.opt_machine}</option>`; fEquip.disabled = true; }
        updateLignes();
    } else if (level === 'zone') {
        fZone.value = '';
        if (isEquipSelect) { fEquip.innerHTML = `<option value="">${I18N_DEMANDE.opt_machine}</option>`; fEquip.disabled = true; }
        updateZones();
    } else if (level === 'equip') {
        if (isEquipSelect) fEquip.value = '';
    }
    renderLocPicker();
    validateStep2();
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
    addCrumb(I18N_DEMANDE.label_usine, usine, 'usine', !usine);
    if (usine) addCrumb(I18N_DEMANDE.label_secteur, secteur, 'secteur', !secteur);
    if (secteur && ligneHasChoices) addCrumb(I18N_DEMANDE.label_ligne, ligne, 'ligne', !ligne);
    if (secteur && (!ligneHasChoices || ligne)) addCrumb(I18N_DEMANDE.label_zone, zone, 'zone', !(zone && equip));
    if (zone && equip) addCrumb(I18N_DEMANDE.label_machine, equip, 'equip', true);

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
            <button type="button" class="loc-done-change"><i class="fa-solid fa-rotate"></i> ${I18N_DEMANDE.change_machine}</button>
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
        msg.textContent = I18N_DEMANDE.no_options;
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
        empty.textContent = I18N_DEMANDE.no_machine_found;
        resultsEl.appendChild(empty);
        resultsEl.style.display = 'block';
        return;
    }
    matches.forEach(m => {
        const item = document.createElement('div');
        item.className = 'loc-search-item';
        const nameEl = document.createElement('div');
        nameEl.className = 'loc-search-item-name';
        nameEl.textContent = m.nom_machine || I18N_DEMANDE.unnamed;
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
    validateStep2();
}

document.addEventListener('click', (e) => {
    const wrap = document.getElementById('loc-search-field');
    if (wrap && !wrap.contains(e.target)) {
        const resultsEl = document.getElementById('loc-search-results');
        if (resultsEl) resultsEl.style.display = 'none';
    }
});

function mettreAJourNoms() {
    const fonctionChoisie = document.getElementById('f-fonction').value;
    const selectIdentite = document.getElementById('f-identite');
    selectIdentite.innerHTML = `<option value="" disabled selected>${I18N_DEMANDE.opt_who_are_you}</option>`;
    selectIdentite.disabled = false;

    personnelData.forEach(p => {
        if (p.fonction === fonctionChoisie) {
            const option = document.createElement('option');
            option.value = p.id;
            const prenom = p.prenom ? p.prenom + " " : "";
            option.textContent = prenom + p.nom;
            selectIdentite.appendChild(option);
        }
    });
}

/* ================= ENVOI DE LA DEMANDE ================= */
// --- PHOTOS jointes à la demande (voir bi_photos.php) ---
// Comme côté maintenance.php : les fichiers restent en mémoire le temps du formulaire, et ne
// sont envoyés qu'une fois le ticket créé avec succès, pour éviter toute photo orpheline.
let selectedPhotosDemande = [];

function onPhotosSelectedDemande(fileList) {
    for (const f of fileList) {
        if (!f.type.startsWith('image/')) continue;
        selectedPhotosDemande.push(f);
    }
    document.getElementById('f-photos').value = '';
    renderPhotoThumbsDemande();
}

function removeSelectedPhotoDemande(idx) {
    selectedPhotosDemande.splice(idx, 1);
    renderPhotoThumbsDemande();
}

function renderPhotoThumbsDemande() {
    const box = document.getElementById('photo-thumbs-demande');
    if (!box) return;
    box.innerHTML = selectedPhotosDemande.map((f, i) => `
        <div class="photo-thumb">
            <img src="${URL.createObjectURL(f)}" alt="${f.name}">
            <button type="button" class="photo-thumb-remove" onclick="removeSelectedPhotoDemande(${i})" title="${I18N_DEMANDE.remove_photo_tooltip}"><i class="fa-solid fa-xmark"></i></button>
        </div>`).join('');
}

async function uploadPendingPhotosDemande(taskId) {
    if (selectedPhotosDemande.length === 0) return;
    try {
        const tokenRes = await fetch('bi_photos.php');
        const tokenData = await tokenRes.json();
        for (const file of selectedPhotosDemande) {
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
    selectedPhotosDemande = [];
}

async function envoyerDemande() {
    const idDemandeur = document.getElementById('f-identite').value;
    const pin = document.getElementById('f-pin').value.trim();
    const equip = document.getElementById('f-equip').value;
    const descAction = document.getElementById('f-desc').value.trim();
    const usine = document.getElementById('f-usine').value;
    const secteur = document.getElementById('f-secteur').value;
    const zone = document.getElementById('f-zone').value;
    const err = document.getElementById('step4-error');
    const btnSubmit = document.getElementById('btn-submit');

    if(!equip || !idDemandeur || !descAction || !pin) {
        err.innerText = I18N_DEMANDE.err_missing_info;
        return;
    }

    err.innerText = '';
    btnSubmit.disabled = true;
    btnSubmit.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> ' + I18N_DEMANDE.btn_sending;

    const formData = new FormData();
    formData.append('action', 'verify_pin');
    formData.append('id_demandeur', idDemandeur);
    formData.append('pin', pin);

    try {
        const checkRes = await fetch('demande.php', { method: 'POST', body: formData });
        const checkData = await checkRes.json();

        if (!checkData.success) {
            err.innerText = I18N_DEMANDE.err_bad_pin;
            document.getElementById('f-pin').value = "";
            btnSubmit.disabled = false;
            btnSubmit.innerHTML = '<i class="fa-solid fa-paper-plane"></i> ' + I18N_DEMANDE.btn_submit;
            return;
        }

        const vraiUtilisateur = checkData.user;
        const prenomFormat = vraiUtilisateur.prenom ? vraiUtilisateur.prenom + " " : "";

        // Horodatage local (jour + heure) de la demande, pour affichage côté SAS
        const maintenantDem = new Date();
        const dateCreationStr = maintenantDem.getFullYear() + '-' + String(maintenantDem.getMonth() + 1).padStart(2, '0') + '-' + String(maintenantDem.getDate()).padStart(2, '0')
            + ' ' + String(maintenantDem.getHours()).padStart(2, '0') + ':' + String(maintenantDem.getMinutes()).padStart(2, '0');

        const data = {
            id: "DEM-" + Date.now(),
            num_bi: "",
            tech: "À ATTRIBUER",
            usine: usine,
            secteur: secteur,
            ligne: (document.getElementById('f-ligne').value !== 'N/A') ? document.getElementById('f-ligne').value : "",
            zone: zone,
            equip: equip,
            date: new Date().toISOString().split('T')[0],
            date_creation: dateCreationStr,
            hours: 0,
            prio: urgenceChoisie,
            type: "Curatif",
            casse: false,
            desc: "DEMANDE DE : " + prenomFormat + vraiUtilisateur.nom + " (" + vraiUtilisateur.fonction + ") - " + descAction,
            demandeur: sessionUser,
            statut: "EN ATTENTE",
            action_user: sessionUser,
            is_update: false
        };

        const response = await fetch('api.php', { method: 'POST', body: JSON.stringify(data) });
        if(response.ok) {
            await uploadPendingPhotosDemande(data.id);
            document.getElementById('successModal').classList.add('active');
        } else {
            err.innerText = I18N_DEMANDE.err_save;
            btnSubmit.disabled = false;
            btnSubmit.innerHTML = '<i class="fa-solid fa-paper-plane"></i> ' + I18N_DEMANDE.btn_submit;
        }
    } catch (error) {
        err.innerText = I18N_DEMANDE.err_network;
        btnSubmit.disabled = false;
        btnSubmit.innerHTML = '<i class="fa-solid fa-paper-plane"></i> ' + I18N_DEMANDE.btn_submit;
    }
}

document.addEventListener('DOMContentLoaded', function() {
    initCascade();
    showStep(1);
});
</script>

</body>
</html>
