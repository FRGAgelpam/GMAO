<?php
require_once __DIR__ . '/session_init.php';

// --- 1. LE VIGILE (SÉCURITÉ) ---
if (!isset($_SESSION['user']) || $_SESSION['role'] !== 'admin') {
    if (!isset($_SESSION['user'])) {
        header("Location: login.php");
    } else {
        header("Location: demande.php");
    }
    exit();
}

$is_admin = ($_SESSION['role'] === 'admin'); // Variable pour le Menu Maître

// --- HABILLAGE DES STATUTS/PRIORITÉS (configurable depuis Paramètres > Statuts & priorités) ---
// Ne change QUE le libellé/la couleur affichés des badges : la classification (substring
// "termin"/"cours" côté JS) et les valeurs stockées en base ne sont pas touchées.
$LIBELLES_WORKFLOW = [
    'afaire'  => ['label' => t('maint.lib_afaire'),  'couleur' => '#f39c12'],
    'encours' => ['label' => t('maint.lib_encours'), 'couleur' => '#3498db'],
    'termine' => ['label' => t('maint.lib_termine'), 'couleur' => '#27ae60'],
    'urgent'  => ['label' => t('maint.lib_urgent'),  'couleur' => '#e74c3c'],
];
// Défauts français d'origine (avant l'i18n) : un libellé en base identique à l'un d'eux est traité
// comme non personnalisé (même correctif que suivi.php/index.php/maintenance.php/stats_tech.php).
$SNAPSHOTS_FR_LIBELLES = ['afaire' => 'À faire', 'encours' => 'En cours', 'termine' => 'Terminée', 'urgent' => 'Urgent'];
try {
    require_once 'db.php';
    if (isset($db)) {
        foreach ($db->query("SELECT bucket, label, couleur FROM libelles_workflow")->fetchAll(PDO::FETCH_ASSOC) as $l) {
            $labelAGarder = (($l['label'] ?? '') === '' || $l['label'] === ($SNAPSHOTS_FR_LIBELLES[$l['bucket']] ?? null))
                ? ($LIBELLES_WORKFLOW[$l['bucket']]['label'] ?? $l['label'])
                : $l['label'];
            $LIBELLES_WORKFLOW[$l['bucket']] = ['label' => $labelAGarder, 'couleur' => $l['couleur']];
        }
    }
} catch (Exception $e) {}

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(langue_actuelle()); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title><?php echo htmlspecialchars(t('kpi.page_title_tag')); ?></title>
    <link rel="icon" type="image/png" href="img/logo.png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Caveat:wght@600&family=Segoe+UI:wght@300;400;600&family=Montserrat:wght@400;700;900&family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #2c3e50; --accent: #3498db; --success: #2ecc71;
            --danger: #e74c3c; --brand-green: #2ecc71; --brand-orange: #f39c12;
            --brand-blue: #005696; --neutral-dark: #34495e;
            --stat-red: #c0392b;
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
            overflow-y: auto; /* MAGIE : Permet de scroller DANS le menu si l'écran est trop petit */
            transition: 0.4s;
            padding-top: 60px;
            padding-bottom: 20px; /* Ajoute un peu d'air tout en bas */
        }
        .sidebar a {
            padding: 12px 25px; /* RÉDUIT : 12px au lieu de 15px pour gagner de la place */
            text-decoration: none;
            font-size: 1.05rem; /* Lisse la taille de la police */
            color: #ecf0f1;
            display: block;
            transition: 0.3s;
            border-left: 4px solid transparent;
        }
        .sidebar a:hover {
            background: #2c3e50;
            border-left: 4px solid var(--accent);
        }
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

        /* ================================================================
           REFONTE — TABLEAU DE BORD KPI (design system dédié à cette page)
           ================================================================ */
        .kdash { --k-radius: 18px; --k-radius-sm: 12px; --k-border: rgba(15, 23, 42, 0.07);
            --k-shadow: 0 1px 2px rgba(15,23,42,.04), 0 12px 32px -12px rgba(15,23,42,.18);
            --k-shadow-hover: 0 1px 2px rgba(15,23,42,.05), 0 20px 44px -14px rgba(15,23,42,.26);
            --k-ink: #0f172a; --k-ink-soft: #64748b; --k-ink-faint: #94a3b8;
            max-width: 1440px; margin: 0 auto; padding: 0 20px 50px; font-family: 'Inter', 'Segoe UI', sans-serif;
        }
        .kdash * { box-sizing: border-box; }
        .kdash h1, .kdash h2, .kdash h3 { font-family: 'Inter', 'Segoe UI', sans-serif; margin: 0; }

        /* --- En-tête de section --- */
        .kdash-hero { display: flex; align-items: center; justify-content: space-between; gap: 16px; flex-wrap: wrap; margin-bottom: 18px; padding: 18px 24px; border-radius: var(--k-radius); background: rgba(255,255,255,0.92); backdrop-filter: blur(10px); box-shadow: var(--k-shadow); border: 1px solid var(--k-border); }
        .kdash-hero h1 { font-size: 1.35rem; font-weight: 800; color: var(--k-ink); display: flex; align-items: center; gap: 10px; letter-spacing: -0.01em; }
        .kdash-hero h1 i { color: var(--accent); font-size: 1.15rem; }
        .kdash-hero p { margin: 3px 0 0 32px; font-size: 0.82rem; color: var(--k-ink-soft); font-weight: 500; }
        .kdash-live { display: flex; align-items: center; gap: 8px; font-size: 0.72rem; font-weight: 700; color: var(--k-ink-soft); background: #f1f5f9; border: 1px solid var(--k-border); padding: 7px 14px; border-radius: 999px; text-transform: uppercase; letter-spacing: 0.03em; }
        .kdash-live .dot { width: 7px; height: 7px; border-radius: 50%; background: var(--success); position: relative; flex: none; }
        .kdash-live .dot::after { content: ""; position: absolute; inset: 0; border-radius: 50%; background: inherit; animation: pulse-dot 2s infinite; }

        /* --- Barre de filtres --- */
        .kdash-filters { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; margin-bottom: 22px; padding: 14px 18px; border-radius: var(--k-radius); background: rgba(255,255,255,0.92); backdrop-filter: blur(10px); box-shadow: var(--k-shadow); border: 1px solid var(--k-border); }
        .kfield { display: flex; align-items: center; gap: 8px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 11px; padding: 8px 12px; }
        .kfield i { color: var(--k-ink-faint); font-size: 0.78rem; }
        .kfield label { font-size: 0.66rem; font-weight: 800; text-transform: uppercase; letter-spacing: 0.04em; color: var(--k-ink-faint); }
        .kfield input, .kfield select { border: none; background: transparent; font-family: 'Inter', sans-serif; font-size: 0.82rem; font-weight: 600; color: var(--k-ink); outline: none; cursor: pointer; }
        .kfield input[type="date"] { cursor: text; }
        .kdash-filters .kspacer { flex: 1; }
        .kbtn { border: none; padding: 10px 18px; border-radius: 11px; font-weight: 700; font-size: 0.78rem; cursor: pointer; display: inline-flex; align-items: center; gap: 7px; transition: all .18s ease; font-family: 'Inter', sans-serif; }
        .kbtn-primary { background: var(--accent); color: #fff; box-shadow: 0 8px 18px -6px rgba(52,152,219,.6); }
        .kbtn-primary:hover { background: #2b86c5; transform: translateY(-1px); box-shadow: 0 10px 22px -6px rgba(52,152,219,.7); }
        .kbtn-ghost { background: #f1f5f9; color: var(--k-ink-soft); }
        .kbtn-ghost:hover { background: #e2e8f0; color: var(--k-ink); }

        /* --- Cartes KPI --- */
        .kpi-grid { display: grid; grid-template-columns: repeat(5, minmax(0, 1fr)); gap: 14px; margin-bottom: 18px; }
        .kpi-card { background: rgba(255,255,255,0.95); border-radius: var(--k-radius); padding: 18px; box-shadow: var(--k-shadow); border: 1px solid var(--k-border); cursor: pointer; transition: transform .2s ease, box-shadow .2s ease; display: flex; flex-direction: column; gap: 12px; min-width: 0; }
        .kpi-card:hover { transform: translateY(-4px); box-shadow: var(--k-shadow-hover); }
        .kpi-top { display: flex; align-items: flex-start; justify-content: space-between; gap: 8px; }
        .kpi-icon { width: 42px; height: 42px; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 1.05rem; flex: none; }
        .kpi-card[data-tone="blue"]   .kpi-icon { background: rgba(52,152,219,.13); color: #2f80c9; }
        .kpi-card[data-tone="green"]  .kpi-icon { background: rgba(46,204,113,.14); color: #1e9e5a; }
        .kpi-card[data-tone="purple"] .kpi-icon { background: rgba(155,89,182,.14); color: #8e44ad; }
        .kpi-card[data-tone="red"]    .kpi-icon { background: rgba(231,76,60,.13); color: #c0392b; }
        .kpi-card[data-tone="orange"] .kpi-icon { background: rgba(243,156,18,.15); color: #d68910; }
        .kpi-chip { font-size: 0.62rem; font-weight: 800; padding: 3px 8px; border-radius: 999px; text-transform: uppercase; letter-spacing: 0.03em; white-space: nowrap; }
        .kpi-card[data-tone="blue"]   .kpi-chip { background: rgba(52,152,219,.1); color: #2f80c9; }
        .kpi-card[data-tone="green"]  .kpi-chip { background: rgba(46,204,113,.12); color: #1e9e5a; }
        .kpi-card[data-tone="purple"] .kpi-chip { background: rgba(155,89,182,.12); color: #8e44ad; }
        .kpi-card[data-tone="red"]    .kpi-chip { background: rgba(231,76,60,.1); color: #c0392b; }
        .kpi-card[data-tone="orange"] .kpi-chip { background: rgba(243,156,18,.13); color: #d68910; }
        .kpi-label { display: block; color: var(--k-ink-soft); font-size: 0.72rem; font-weight: 700; margin-bottom: 4px; }
        .kpi-value { display: block; color: var(--k-ink); font-family: 'Inter', sans-serif; font-size: 1.7rem; font-weight: 800; letter-spacing: -0.02em; line-height: 1.15; font-variant-numeric: tabular-nums; }
        #k-top.kpi-value { font-size: 1.05rem; font-weight: 700; min-height: 1.4em; line-height: 1.25; }

        /* --- Ratio Curatif / Préventif --- */
        .ratio-container { margin-bottom: 18px; background: rgba(255,255,255,0.95); border-radius: var(--k-radius); padding: 18px 22px; box-shadow: var(--k-shadow); border: 1px solid var(--k-border); }
        .ratio-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 12px; flex-wrap: wrap; gap: 10px; }
        .ratio-title { font-size: 0.78rem; font-weight: 800; color: var(--k-ink); text-transform: uppercase; letter-spacing: 0.03em; display: flex; align-items: center; gap: 8px; }
        .ratio-title i { color: var(--k-ink-faint); }
        .ratio-legend { display: flex; gap: 10px; flex-wrap: wrap; }
        .ratio-legend span { display: inline-flex; align-items: center; gap: 6px; font-size: 0.78rem; font-weight: 700; color: var(--k-ink-soft); }
        .ratio-legend-item {
            cursor: pointer; padding: 6px 12px 6px 10px; border-radius: 999px;
            background: #fff; border: 1.5px solid var(--k-border); border-left-width: 5px;
            box-shadow: 0 1px 3px rgba(0,0,0,.06); transition: transform .15s, box-shadow .15s, background .15s;
        }
        .ratio-legend-item:hover { transform: translateY(-2px); box-shadow: 0 6px 14px rgba(0,0,0,.12); }
        .ratio-legend-item.type-curatif { border-left-color: var(--stat-red); }
        .ratio-legend-item.type-curatif:hover { background: #fdf1ef; }
        .ratio-legend-item.type-preventif { border-left-color: var(--accent); }
        .ratio-legend-item.type-preventif:hover { background: #eef6fc; }
        .ratio-legend-item.type-chantier { border-left-color: var(--brand-orange); }
        .ratio-legend-item.type-chantier:hover { background: #fef6e9; }
        .ratio-legend-item .ratio-link-icon { font-size: 0.72rem; margin-left: 2px; }
        .ratio-legend-item.type-curatif .ratio-link-icon { color: var(--stat-red); }
        .ratio-legend-item.type-preventif .ratio-link-icon { color: var(--accent); }
        .ratio-legend-item .ratio-type-icon { font-size: 0.85rem; }
        .ratio-legend-item.type-curatif .ratio-type-icon { color: var(--stat-red); }
        .ratio-legend-item.type-preventif .ratio-type-icon { color: var(--accent); }
        .ratio-legend-item.type-chantier .ratio-type-icon { color: var(--brand-orange); }
        .ratio-legend-item.type-chantier .ratio-link-icon { color: var(--brand-orange); }
        .ratio-legend .dot { width: 9px; height: 9px; border-radius: 3px; flex: none; }
        .ratio-legend b { color: var(--k-ink); font-weight: 800; }
        .ratio-legend .ratio-pct { color: var(--k-ink-faint); font-weight: 600; }
        .ratio-legend .ratio-hours { color: var(--k-ink-faint); font-weight: 600; padding-left: 8px; border-left: 1px solid var(--k-border); }
        .progress-bar-global { height: 12px; background: #eef2f6; border-radius: 999px; display: flex; overflow: hidden; }
        .progress-curatif { background: linear-gradient(90deg, #e05c48, var(--stat-red)); height: 100%; transition: width .8s ease-in-out; }
        .progress-preventif { background: linear-gradient(90deg, var(--accent), #2b86c5); height: 100%; transition: width .8s ease-in-out; }
        .progress-chantier { background: linear-gradient(90deg, #f5a623, var(--brand-orange)); height: 100%; transition: width .8s ease-in-out; }

        /* --- Panneaux (technicien / équipement) --- */
        .stats-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 10px; }
        .stats-panel { background: rgba(255,255,255,0.95); border-radius: var(--k-radius); box-shadow: var(--k-shadow); border: 1px solid var(--k-border); display: flex; flex-direction: column; min-height: 380px; max-height: 62vh; min-width: 0; overflow: hidden; }
        .trend-panel { min-height: 0; max-height: none; }
        .trend-body { padding: 18px 22px 24px; height: 260px; position: relative; }
        @media (max-width: 480px) { .trend-body { height: 220px; padding: 14px 14px 20px; } }
        .panel-head { display: flex; align-items: center; justify-content: space-between; padding: 16px 20px 12px; border-bottom: 1px solid #f1f5f9; }
        .panel-title { font-size: 0.92rem; font-weight: 800; color: var(--k-ink); display: flex; align-items: center; gap: 9px; letter-spacing: -0.01em; }
        .panel-title i { color: var(--accent); font-size: 0.85rem; width: 30px; height: 30px; border-radius: 9px; background: rgba(52,152,219,.12); display: flex; align-items: center; justify-content: center; }
        .panel-badge { font-size: 0.68rem; font-weight: 700; color: var(--k-ink-faint); background: #f1f5f9; padding: 3px 10px; border-radius: 999px; transition: background .2s, color .2s; }
        .panel-badge.type-curatif { background: rgba(192,57,43,.12); color: var(--stat-red); }
        .panel-badge.type-preventif { background: rgba(52,152,219,.12); color: var(--accent); }
        .panel-badge.type-chantier { background: rgba(243,156,18,.15); color: #b9770e; }

        /* --- Carrousel curatif / préventif / chantier --- */
        .carousel-bar { display: flex; align-items: center; gap: 14px; flex-wrap: wrap; margin-bottom: 16px; background: rgba(255,255,255,0.95); border-radius: var(--k-radius); padding: 10px 16px; box-shadow: var(--k-shadow); border: 1px solid var(--k-border); }
        .carousel-bar-label { font-size: 0.72rem; font-weight: 800; color: var(--k-ink-faint); text-transform: uppercase; letter-spacing: 0.03em; display: flex; align-items: center; gap: 6px; white-space: nowrap; }
        .carousel-types { display: flex; gap: 8px; flex-wrap: wrap; }
        .carousel-type-pill { cursor: pointer; display: inline-flex; align-items: center; gap: 6px; font-size: 0.76rem; font-weight: 700; padding: 5px 12px; border-radius: 999px; border: 1.5px solid var(--k-border); color: var(--k-ink-soft); background: #fff; transition: all .15s; }
        .carousel-type-pill:hover { border-color: var(--k-ink-faint); }
        .carousel-type-pill.active.type-curatif { background: var(--stat-red); border-color: var(--stat-red); color: #fff; box-shadow: 0 3px 8px rgba(192,57,43,.35); }
        .carousel-type-pill.active.type-preventif { background: var(--accent); border-color: var(--accent); color: #fff; box-shadow: 0 3px 8px rgba(52,152,219,.35); }
        .carousel-type-pill.active.type-chantier { background: var(--brand-orange); border-color: var(--brand-orange); color: #fff; box-shadow: 0 3px 8px rgba(243,156,18,.35); }
        .carousel-pause-btn { cursor: pointer; margin-left: auto; display: inline-flex; align-items: center; gap: 7px; font-size: 0.76rem; font-weight: 700; padding: 6px 14px; border-radius: 999px; border: 1.5px solid var(--k-border); background: #fff; color: var(--k-ink); transition: background .15s; }
        .carousel-pause-btn:hover { background: #f1f5f9; }
        .carousel-pause-btn.is-paused { background: #fff7e6; border-color: #f3c969; color: #b9770e; }
        .scroll-area { overflow-y: auto; overflow-x: hidden; flex: 1; padding: 14px 20px 16px; }
        .scroll-area::-webkit-scrollbar { width: 7px; }
        .scroll-area::-webkit-scrollbar-thumb { background: #e2e8f0; border-radius: 10px; }

        .kempty { text-align: center; padding: 40px 20px; color: var(--k-ink-faint); font-weight: 700; font-size: 0.85rem; }
        .kempty i { font-size: 1.9rem; margin-bottom: 10px; color: #dbe3ea; display: block; }

        .tech-row { display: flex; align-items: center; gap: 13px; padding: 10px 0; border-bottom: 1px solid #f4f6f8; }
        .tech-row:last-child { border-bottom: none; }
        .tech-rank { flex: none; width: 22px; text-align: center; font-size: 0.68rem; font-weight: 800; color: var(--k-ink-faint); }
        .tech-img { width: 38px; height: 38px; border-radius: 50%; border: 2px solid #fff; box-shadow: 0 0 0 2px var(--accent); object-fit: cover; flex: none; }
        .tech-data { flex: 1; min-width: 0; }
        .tech-data .row1 { display: flex; justify-content: space-between; align-items: baseline; gap: 8px; }
        .tech-name { font-weight: 700; font-size: 0.85rem; color: var(--k-ink); }
        .tech-role { font-size: 0.66rem; color: var(--k-ink-faint); font-weight: 600; margin-left: 6px; }
        .tech-hours { font-weight: 800; font-size: 0.85rem; color: var(--k-ink); font-variant-numeric: tabular-nums; white-space: nowrap; }
        .progress-box { background: #eef2f6; height: 6px; border-radius: 4px; overflow: hidden; margin-top: 6px; }
        .progress-bar { height: 100%; border-radius: 4px; transition: width 1s ease-in-out; }
        .bar-tech { background: linear-gradient(90deg, #3498db, #6cb6e8); }
        /* Couleur pilotée par le carrousel (curatif/préventif/chantier) via des variables CSS
           posées en JS sur #secteur-list (voir renderCarouselPanels) — fallback rouge curatif. */
        .bar-secteur { background: linear-gradient(90deg, var(--secteur-color, #e05c48), var(--secteur-color-light, #eb8a76)); }
        .secteur-icon { width: 38px; height: 38px; border-radius: 11px; background: var(--secteur-color-bg, rgba(224,92,72,.12)); color: var(--secteur-color-dark, #c0392b); display: flex; align-items: center; justify-content: center; font-size: 0.95rem; flex: none; transition: background .15s; }
        .secteur-row-click { cursor: pointer; border-radius: 10px; margin: 0 -8px; padding-left: 8px; padding-right: 8px; transition: background .15s; }
        .secteur-row-click:hover { background: #f8fafc; }
        .secteur-row-click:hover .secteur-icon { background: var(--secteur-color-bg-hover, rgba(224,92,72,.22)); }

        .equip-card { padding: 12px 0; border-bottom: 1px solid #f4f6f8; }
        .equip-card:last-child { border-bottom: none; }
        .equip-loc { font-size: 0.62rem; color: var(--accent); font-weight: 800; text-transform: uppercase; letter-spacing: 0.03em; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; margin-bottom: 3px; display: flex; align-items: center; gap: 5px; }
        .equip-main { display: flex; justify-content: space-between; align-items: center; gap: 10px; }
        .equip-name { color: var(--k-ink); font-weight: 700; font-size: 0.86rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .equip-count { flex: none; color: #d68910; font-size: 0.68rem; font-weight: 800; background: rgba(243,156,18,.12); padding: 3px 9px; border-radius: 999px; }
        .equip-desc-row { font-size: 0.68rem; color: var(--k-ink-soft); margin-top: 6px; display: flex; align-items: center; gap: 5px; line-height: 1.4; }
        .equip-desc-row .bullet { color: var(--k-ink-faint); flex: none; }
        .equip-desc-row .txt { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .chip { font-size: 0.56rem; font-weight: 800; padding: 2px 6px; border-radius: 5px; white-space: nowrap; flex: none; }
        .chip-bi { color: #d35400; background: #fef5e7; border: 1px solid #f9e79f; text-decoration: none; }
        .chip-type-prev { background: rgba(52,152,219,.14); color: #2f80c9; }
        .chip-type-cura { background: rgba(231,76,60,.13); color: #c0392b; }
        .chip-type-chan { background: rgba(243,156,18,.16); color: #b9770e; }

        @media (max-width: 900px) {
            .stats-grid { grid-template-columns: 1fr; }
            .kpi-grid { grid-template-columns: repeat(3, minmax(0, 1fr)); }
            .kdash-hero p { margin-left: 0; }
        }
        @media (max-width: 480px) {
            .kpi-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
            .kdash { padding: 0 12px 40px; }
        }

        /* --- Modale historique (restylée) --- */
        .modal { display: none; position: fixed; z-index: 4000; left: 0; top: 0; width: 100%; height: 100%; background: rgba(15,23,42,0.55); backdrop-filter: blur(6px); }
        .modal-content { background: #fff; margin: 4% auto; padding: 26px 28px; border-radius: 20px; width: 90%; max-width: 1120px; max-height: 84vh; overflow-y: auto; position: relative; font-family: 'Inter', sans-serif; box-shadow: 0 30px 70px -20px rgba(0,0,0,.4); }
        .modal-content h2 { font-family: 'Inter', sans-serif !important; font-weight: 800 !important; font-size: 1.15rem !important; display: flex; align-items: center; gap: 10px; }
        .close-modal { color: var(--k-ink-faint); transition: color .15s; }
        .close-modal:hover { color: var(--k-ink); }
        .ktable { width: 100%; border-collapse: collapse; font-family: 'Inter', sans-serif; border-radius: 12px; overflow: hidden; table-layout: fixed; box-shadow: 0 4px 15px rgba(0,0,0,0.05); }
        .ktable thead { background: var(--primary); color: #fff; }
        .ktable th { padding: 11px 10px; text-align: left; font-size: 0.68rem; font-weight: 800; text-transform: uppercase; letter-spacing: 0.04em; }
        .ktable td { padding: 11px 10px; vertical-align: middle; border-bottom: 1px solid #eef2f3; font-size: 0.8rem; }
        .ktable tbody tr { transition: background .15s; }
        .ktable tbody tr:hover { background: rgba(52,152,219,.035); }
        .badge-status { padding: 4px 9px; border-radius: 999px; font-size: 0.62rem; font-weight: 800; display: inline-block; text-transform: uppercase; letter-spacing: 0.02em; }
    </style>
<?php include 'pwa_head.php'; ?>
</head>
<body onclick="checkCloseSidebar(event)">

<?php $breadcrumb_label = t('kpi.breadcrumb'); include 'navbar.php'; ?>

<div class="main-content kdash">

    <div class="kdash-hero">
        <div>
            <h1><i class="fa-solid fa-chart-line"></i> <?php echo htmlspecialchars(t('kpi.h1')); ?></h1>
            <p><?php echo htmlspecialchars(t('kpi.subtitle')); ?></p>
        </div>
        <div class="kdash-live"><span class="dot"></span> <span id="kpi-last-update"><?php echo htmlspecialchars(t('suivi.chargement')); ?></span></div>
    </div>

    <div class="kdash-filters">
        <div class="kfield"><i class="fa-regular fa-calendar"></i> <label><?php echo htmlspecialchars(t('kpi.filter_du')); ?></label> <input type="date" id="date-debut"></div>
        <div class="kfield"><i class="fa-regular fa-calendar-check"></i> <label><?php echo htmlspecialchars(t('kpi.filter_au')); ?></label> <input type="date" id="date-fin"></div>
        <div class="kfield"><i class="fa-solid fa-user-gear"></i> <label><?php echo htmlspecialchars(t('kpi.filter_tech')); ?></label>
            <select id="filter-tech">
                <option value=""><?php echo htmlspecialchars(t('kpi.filter_tous')); ?></option>
                <option value="Technicien 1">Technicien 1</option>
                <option value="Technicien 3">Technicien 3</option>
                <option value="Technicien 2">Technicien 2</option>
                <option value="Technicien 4">Technicien 4</option>
                <option value="Technicien 5">Technicien 5</option>
                <option value="Technicien 6">Technicien 6</option>
                <option value="Technicien 7">Technicien 7</option>
            </select>
        </div>
        <div class="kspacer"></div>
        <button class="kbtn kbtn-primary" onclick="filterData()"><i class="fa-solid fa-filter"></i> <?php echo htmlspecialchars(t('kpi.btn_filtrer')); ?></button>
        <button class="kbtn kbtn-ghost" onclick="resetFilters()"><i class="fa-solid fa-arrow-rotate-left"></i> <?php echo htmlspecialchars(t('logs.btn_reset')); ?></button>
    </div>

    <div class="kpi-grid">
        <div class="kpi-card" data-tone="blue" onclick="openHistory('total')">
            <div class="kpi-top"><div class="kpi-icon"><i class="fa-solid fa-list-check"></i></div><span class="kpi-chip"><?php echo htmlspecialchars(t('kpi.chip_volume')); ?></span></div>
            <div><span class="kpi-label"><?php echo htmlspecialchars(t('kpi.label_total_interventions')); ?></span><span class="kpi-value" id="k-total">0</span></div>
        </div>
        <div class="kpi-card" data-tone="green" onclick="openHistory('done')">
            <div class="kpi-top"><div class="kpi-icon"><i class="fa-solid fa-circle-check"></i></div><span class="kpi-chip"><?php echo htmlspecialchars(t('kpi.chip_cloture')); ?></span></div>
            <div><span class="kpi-label"><?php echo htmlspecialchars(t('kpi.label_taux_realisation')); ?></span><span class="kpi-value" id="k-rate">0%</span></div>
        </div>
        <div class="kpi-card" data-tone="purple">
            <div class="kpi-top"><div class="kpi-icon"><i class="fa-solid fa-clock"></i></div><span class="kpi-chip"><?php echo htmlspecialchars(t('kpi.chip_temps_mo')); ?></span></div>
            <div><span class="kpi-label"><?php echo htmlspecialchars(t('kpi.label_heures_cumulees')); ?></span><span class="kpi-value" id="k-hours">0h</span></div>
        </div>
        <div class="kpi-card" data-tone="red" onclick="openHistory('casse')">
            <div class="kpi-top"><div class="kpi-icon"><i class="fa-solid fa-bolt"></i></div><span class="kpi-chip"><?php echo htmlspecialchars(t('kpi.chip_urgences')); ?></span></div>
            <div><span class="kpi-label"><?php echo htmlspecialchars(t('kpi.label_taux_casse')); ?></span><span class="kpi-value" id="k-break-rate">0%</span></div>
        </div>
        <div class="kpi-card" data-tone="orange" onclick="openHistory('recurring')">
            <div class="kpi-top"><div class="kpi-icon"><i class="fa-solid fa-arrows-rotate"></i></div><span class="kpi-chip"><?php echo htmlspecialchars(t('kpi.chip_recurrence')); ?></span></div>
            <div><span class="kpi-label"><?php echo htmlspecialchars(t('kpi.label_panne_recurrente')); ?></span><span class="kpi-value" id="k-top">-</span></div>
        </div>
    </div>

    <div class="ratio-container">
        <div class="ratio-header">
            <span class="ratio-title"><i class="fa-solid fa-scale-balanced"></i> <?php echo htmlspecialchars(t('kpi.ratio_title')); ?></span>
            <div class="ratio-legend">
                <span class="ratio-legend-item type-curatif" onclick="openTypeHistory('curatif')" title="<?php echo htmlspecialchars(str_replace('{type}', t('maint.type_curatif'), t('kpi.voir_bi_type'))); ?>"><i class="fa-solid fa-screwdriver-wrench ratio-type-icon"></i> <?php echo htmlspecialchars(t('maint.type_curatif')); ?> <b id="count-curatif">0</b> <span class="ratio-pct" id="pct-curatif"></span> <span class="ratio-hours" id="hours-curatif"></span> <i class="fa-solid fa-circle-arrow-right ratio-link-icon"></i></span>
                <span class="ratio-legend-item type-preventif" onclick="openTypeHistory('préventif')" title="<?php echo htmlspecialchars(str_replace('{type}', t('maint.type_preventif'), t('kpi.voir_bi_type'))); ?>"><i class="fa-solid fa-calendar-check ratio-type-icon"></i> <?php echo htmlspecialchars(t('maint.type_preventif')); ?> <b id="count-preventif">0</b> <span class="ratio-pct" id="pct-preventif"></span> <span class="ratio-hours" id="hours-preventif"></span> <i class="fa-solid fa-circle-arrow-right ratio-link-icon"></i></span>
                <span class="ratio-legend-item type-chantier" onclick="openTypeHistory('chantier')" title="<?php echo htmlspecialchars(str_replace('{type}', t('maint.type_chantier'), t('kpi.voir_bi_type'))); ?>"><i class="fa-solid fa-person-digging ratio-type-icon"></i> <?php echo htmlspecialchars(t('maint.type_chantier')); ?> <b id="count-chantier">0</b> <span class="ratio-pct" id="pct-chantier"></span> <span class="ratio-hours" id="hours-chantier"></span> <i class="fa-solid fa-circle-arrow-right ratio-link-icon"></i></span>
            </div>
        </div>
        <div class="progress-bar-global">
            <div id="bar-curatif" class="progress-curatif" style="width: 34%"></div>
            <div id="bar-preventif" class="progress-preventif" style="width: 33%"></div>
            <div id="bar-chantier" class="progress-chantier" style="width: 33%"></div>
        </div>
    </div>

    <div class="carousel-bar">
        <span class="carousel-bar-label"><i class="fa-solid fa-arrows-rotate"></i> <?php echo htmlspecialchars(t('kpi.carousel_label')); ?></span>
        <div class="carousel-types">
            <span class="carousel-type-pill type-curatif active" id="carousel-pill-curatif" onclick="setCarouselType('curatif')"><i class="fa-solid fa-screwdriver-wrench"></i> <?php echo htmlspecialchars(t('maint.type_curatif')); ?></span>
            <span class="carousel-type-pill type-preventif" id="carousel-pill-préventif" onclick="setCarouselType('préventif')"><i class="fa-solid fa-calendar-check"></i> <?php echo htmlspecialchars(t('maint.type_preventif')); ?></span>
            <span class="carousel-type-pill type-chantier" id="carousel-pill-chantier" onclick="setCarouselType('chantier')"><i class="fa-solid fa-person-digging"></i> <?php echo htmlspecialchars(t('maint.type_chantier')); ?></span>
        </div>
        <button class="carousel-pause-btn" id="carousel-toggle-btn" onclick="toggleCarousel()" title="<?php echo htmlspecialchars(t('kpi.carousel_pause_tooltip')); ?>">
            <i class="fa-solid fa-pause" id="carousel-toggle-icon"></i> <span id="carousel-toggle-label"><?php echo htmlspecialchars(t('kpi.pause')); ?></span>
        </button>
    </div>

    <div class="stats-grid">
        <div class="stats-panel">
            <div class="panel-head">
                <span class="panel-title"><i class="fa-solid fa-map-location-dot"></i> <?php echo htmlspecialchars(t('kpi.panel_secteur')); ?></span>
                <span class="panel-badge" id="badge-secteur"><?php echo htmlspecialchars(t('maint.type_curatif')); ?></span>
            </div>
            <div class="scroll-area" id="secteur-list"></div>
        </div>
        <div class="stats-panel">
            <div class="panel-head">
                <span class="panel-title"><i class="fa-solid fa-gears"></i> <?php echo htmlspecialchars(t('kpi.panel_equip')); ?></span>
                <span class="panel-badge" id="badge-equip"><?php echo htmlspecialchars(str_replace('{label}', t('maint.type_curatif'), t('kpi.badge_top15_suffix'))); ?></span>
            </div>
            <div class="scroll-area" id="equip-list"></div>
        </div>
    </div>

    <div class="stats-grid" style="margin-top:16px;">
        <div class="stats-panel trend-panel">
            <div class="panel-head">
                <span class="panel-title"><i class="fa-solid fa-chart-column"></i> <?php echo htmlspecialchars(t('kpi.panel_trend')); ?></span>
                <span class="panel-badge" id="badge-trend"><?php echo htmlspecialchars(str_replace('{label}', t('maint.type_curatif'), t('kpi.badge_12mois_suffix'))); ?></span>
            </div>
            <div class="trend-body" id="trend-body">
                <canvas id="trendChart"></canvas>
            </div>
        </div>
        <div class="stats-panel trend-panel">
            <div class="panel-head">
                <span class="panel-title"><i class="fa-solid fa-arrow-trend-up"></i> <?php echo htmlspecialchars(t('kpi.panel_hours_trend')); ?></span>
                <span class="panel-badge"><?php echo htmlspecialchars(t('kpi.badge_7j')); ?></span>
            </div>
            <div class="trend-body" id="hours-trend-body">
                <canvas id="hoursTrendChart"></canvas>
            </div>
        </div>
    </div>
</div>

<div id="historyModal" class="modal">
    <div class="modal-content">
        <span class="close-modal" onclick="closeModal()" style="position: absolute; right: 22px; top: 20px; font-size: 26px; cursor: pointer;">&times;</span>
        <h2 id="modalTitle"><?php echo htmlspecialchars(t('stats.modal_default_title')); ?></h2>
        <div id="modalBody"></div>
    </div>
</div>

<script>
// --- GESTION DE LA SIDEBAR ---
function openNav(e) { if(e) e.stopPropagation(); document.getElementById("mySidebar").style.width = "280px"; }
function closeNav() { document.getElementById("mySidebar").style.width = "0"; }
function checkCloseSidebar(event) { if (document.getElementById("mySidebar").style.width === "280px" && !document.getElementById("mySidebar").contains(event.target)) closeNav(); }

const LIBELLES = <?php echo json_encode($LIBELLES_WORKFLOW); ?>;
const I18N_KPI = <?php echo json_encode([
    'actualise_a' => t('kpi.actualise_a'),
    'voir_bi_type' => t('kpi.voir_bi_type'),
    'mois_abbr' => [t('mois.jan'), t('mois.fev'), t('mois.mar'), t('mois.avr'), t('mois.mai'), t('mois.juin'), t('mois.juil'), t('mois.aout'), t('mois.sep'), t('mois.oct'), t('mois.nov'), t('mois.dec')],
    'intervention_singulier' => t('kpi.intervention_singulier'),
    'intervention_pluriel' => t('kpi.intervention_pluriel'),
    'th_bi' => t('stats.th_bi'),
    'th_date' => t('maint.col_date'),
    'th_techs' => t('stats.th_techs'),
    'th_statut' => t('stats.th_statut'),
    'prev_fallback' => t('stats.prev_fallback'),
    'non_assigne' => t('stats.non_assigne'),
    'non_renseigne' => t('rapport.non_renseigne'),
    'ouvrir_ri' => t('kpi.ouvrir_ri'),
    'equip_col_desc' => t('kpi.equip_col_desc'),
    'loc_col_desc' => t('kpi.loc_col_desc'),
    'aucune_panne_periode' => t('kpi.aucune_panne_periode'),
    'title_type_history' => t('kpi.title_type_history'),
    'type_curatif' => t('maint.type_curatif'),
    'type_preventif' => t('maint.type_preventif'),
    'type_chantier' => t('maint.type_chantier'),
    'aucun_bi_periode' => t('kpi.aucun_bi_periode'),
    'pas_assez_donnees_tendance' => t('kpi.pas_assez_donnees_tendance'),
    'heures_pointees' => t('kpi.heures_pointees'),
    'pas_assez_pointages_tendance' => t('kpi.pas_assez_pointages_tendance'),
    'badge_top15_suffix' => t('kpi.badge_top15_suffix'),
    'badge_12mois_suffix' => t('kpi.badge_12mois_suffix'),
    'aucune_panne_detectee' => t('kpi.aucune_panne_detectee'),
    'aucun_equipement_periode' => t('kpi.aucun_equipement_periode'),
    'chip_prev' => t('kpi.chip_prev'),
    'chip_chan' => t('kpi.chip_chan'),
    'chip_cura' => t('kpi.chip_cura'),
    'pause' => t('kpi.pause'),
    'reprendre' => t('kpi.reprendre'),
    'title_historique_total' => t('kpi.title_historique_total'),
    'title_interventions_cloturees' => t('kpi.title_interventions_cloturees'),
    'title_urgences_casse' => t('kpi.title_urgences_casse'),
    'title_analyse_recurrentes' => t('kpi.title_analyse_recurrentes'),
    'curatif_uniquement' => t('kpi.curatif_uniquement'),
    'aucune_donnee' => t('kpi.aucune_donnee'),
    'click_segment_tooltip' => t('kpi.click_segment_tooltip'),
    'click_ligne_segment' => t('kpi.click_ligne_segment'),
    'th_equipement_top' => t('kpi.th_equipement_top'),
    'th_pannes' => t('kpi.th_pannes'),
    'autres' => t('kpi.autres'),
    'equipements_secondaires' => t('kpi.equipements_secondaires'),
    'equipement_prefix' => t('kpi.equipement_prefix'),
    'bi_pour' => t('kpi.bi_pour'),
]); ?>;

let allTasks = [];
let allPointages = [];
let currentData = [];
let currentPointages = [];

// --- Carrousel curatif / préventif / chantier pour les panneaux "Pannes récurrentes par
// secteur/équipement" et "Tendance mensuelle" : bascule automatiquement toutes les 5s (pause
// possible), sans toucher au KPI "Panne la plus récurrente" ni au drill-down "recurring" qui,
// eux, restent volontairement sur curatif+chantier combinés (cf. commentaire dans updateUI).
const CAROUSEL_TYPES = ['curatif', 'préventif', 'chantier'];
const CAROUSEL_META = {
    curatif: { icon: 'fa-screwdriver-wrench', label: I18N_KPI.type_curatif, color: '#e05c48', hoverColor: '#c0392b' },
    'préventif': { icon: 'fa-calendar-check', label: I18N_KPI.type_preventif, color: '#3498db', hoverColor: '#2b86c5' },
    chantier: { icon: 'fa-person-digging', label: I18N_KPI.type_chantier, color: '#f5a623', hoverColor: '#f39c12' }
};
let carouselType = 'curatif';
let carouselTimer = null;
let carouselPaused = false;
let kpiAggByType = { curatif: { sMap: {}, eMap: {}, monthMap: {} }, 'préventif': { sMap: {}, eMap: {}, monthMap: {} }, chantier: { sMap: {}, eMap: {}, monthMap: {} } };

// Convertit un hex ('#rrggbb') en 'rgba(r,g,b,a)' — pour des badges tons pastel cohérents
// avec n'importe quelle couleur choisie dans Paramètres > Statuts & priorités.
function tint(hex, alpha) {
    hex = (hex || '#95a5a6').replace('#', '');
    if (hex.length === 3) hex = hex[0]+hex[0]+hex[1]+hex[1]+hex[2]+hex[2];
    const r = parseInt(hex.substr(0,2), 16) || 0;
    const g = parseInt(hex.substr(2,2), 16) || 0;
    const b = parseInt(hex.substr(4,2), 16) || 0;
    return `rgba(${r},${g},${b},${alpha})`;
}
// Éclaircit une couleur hex en la mélangeant vers le blanc (pour le second point du dégradé
// des barres de progression) — pendant du tint() ci-dessus, mais opaque.
function lighten(hex, amt) {
    hex = (hex || '#95a5a6').replace('#', '');
    if (hex.length === 3) hex = hex[0]+hex[0]+hex[1]+hex[1]+hex[2]+hex[2];
    const r = parseInt(hex.substr(0,2), 16) || 0;
    const g = parseInt(hex.substr(2,2), 16) || 0;
    const b = parseInt(hex.substr(4,2), 16) || 0;
    const mix = (c) => Math.round(c + (255 - c) * amt);
    return `rgb(${mix(r)},${mix(g)},${mix(b)})`;
}
function statusBadge(statutRaw, size) {
    const s = (statutRaw || "").toLowerCase();
    let key = 'afaire';
    if (s.includes('termin')) key = 'termine';
    else if (s.includes('cours')) key = 'encours';
    const c = LIBELLES[key].couleur;
    const pad = size === 'sm' ? '2px 7px' : '4px 9px';
    const fs = size === 'sm' ? '0.56rem' : '0.62rem';
    return `<span class="badge-status" style="background:${tint(c,0.14)}; color:${c}; padding:${pad}; font-size:${fs};">${LIBELLES[key].label.toUpperCase()}</span>`;
}
function updateLastRefresh() {
    const el = document.getElementById('kpi-last-update');
    if (el) el.textContent = I18N_KPI.actualise_a + ' ' + new Date().toLocaleTimeString('fr-FR', {hour:'2-digit', minute:'2-digit'});
}

// --- Tendance mensuelle des pannes (graphique en barres, Chart.js chargé à la demande) ---
let trendChartInstance = null;
const MOIS_ABBR = I18N_KPI.mois_abbr;

function renderTrendChart(monthsData) {
    const canvas = document.getElementById('trendChart');
    if (!canvas) return;
    const labels = monthsData.map(([key]) => { const [y, m] = key.split('-'); return MOIS_ABBR[parseInt(m, 10) - 1] + ' ' + y.slice(2); });
    const values = monthsData.map(([, v]) => v);

    const meta = CAROUSEL_META[carouselType] || CAROUSEL_META.curatif;

    if (trendChartInstance) trendChartInstance.destroy();
    trendChartInstance = new Chart(canvas.getContext('2d'), {
        type: 'bar',
        data: { labels, datasets: [{ data: values, backgroundColor: meta.color, hoverBackgroundColor: meta.hoverColor, borderRadius: 6, maxBarThickness: 34 }] },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: { display: false }, tooltip: { callbacks: { label: (ctx) => ctx.parsed.y + ' ' + (ctx.parsed.y > 1 ? I18N_KPI.intervention_pluriel : I18N_KPI.intervention_singulier) } } },
            scales: {
                y: { beginAtZero: true, ticks: { precision: 0 }, grid: { color: '#f1f5f9' } },
                x: { grid: { display: false } }
            }
        }
    });
}

// Tableau réutilisable des bons d'intervention (utilisé par le tableau détaillé, le
// drill-down équipement, et le drill-down secteur) — factorisé pour éviter de dupliquer
// 3 fois la même génération de lignes (BI, date, localisation, techniciens, statut).
function renderTasksTable(tasks, colLabel) {
    return `
    <table class="ktable">
        <thead>
            <tr>
                <th style="width:75px;">${I18N_KPI.th_bi}</th>
                <th style="width:95px;">${I18N_KPI.th_date}</th>
                <th>${colLabel}</th>
                <th style="width:140px;">${I18N_KPI.th_techs}</th>
                <th style="text-align:center; width:100px;">${I18N_KPI.th_statut}</th>
            </tr>
        </thead>
        <tbody>
            ${tasks.map(t => {
                let dateFormatee = t.date.split(' ')[0];
                if (dateFormatee.includes('-')) dateFormatee = dateFormatee.split('-').reverse().join('/');

                let biHtml = t.num_bi && t.id
                    ? `<a href="#" onclick="showDetailBI('${t.id}'); return false;" class="chip chip-bi" style="font-size:0.7rem; padding:4px 8px;" title="${I18N_KPI.ouvrir_ri} ${t.num_bi}">#${t.num_bi}</a>`
                    : `<span style="color:#94a3b8; font-weight:600; font-size:0.7rem; font-style:italic;">${I18N_KPI.prev_fallback}</span>`;

                let badgeStatut = statusBadge(t.statut);
                let localisation = [t.usine, t.secteur, t.zone].filter(Boolean).join(' > ');

                let techsSet = new Set();
                if (t.tech && t.tech.trim() !== "") techsSet.add(t.tech.trim());
                currentPointages.forEach(p => {
                    if (p.task_id == t.id && p.tech && p.tech.trim() !== "") techsSet.add(p.tech.trim());
                });
                let allTechs = Array.from(techsSet).join(', ');
                let techDisplay = allTechs !== "" ? allTechs : `<span style="color:#94a3b8; font-style:italic;">${I18N_KPI.non_assigne}</span>`;

                return `
                <tr>
                    <td style="font-weight:bold;">${biHtml}</td>
                    <td style="white-space:nowrap; color:#64748b; font-weight:600;">${dateFormatee}</td>
                    <td>
                        ${localisation ? `<div style="font-size:0.6rem; color:var(--accent); font-weight:800; text-transform:uppercase; margin-bottom:2px;"><i class="fa-solid fa-location-dot"></i> ${localisation}</div>` : ''}
                        <div style="font-weight:700; color:var(--primary);">${t.equip}</div>
                        <div style="font-size:0.75rem; color:#5a6c7d; margin-top:2px; font-style:italic; white-space:normal; word-break:break-word;">"${t.desc || ''}"</div>
                    </td>
                    <td style="font-weight:600; color:#34495e;">${techDisplay}</td>
                    <td style="text-align:center;">${badgeStatut}</td>
                </tr>`;
            }).join('')}
        </tbody>
    </table>`;
}

// Drill-down depuis le panneau "Pannes récurrentes par secteur" : ouvre la modale historique
// avec les bons d'intervention curatifs de ce secteur (même filtre que le comptage affiché).
// Drill-down depuis le panneau "Pannes récurrentes par secteur" : ce panneau suit le carrousel
// (variable globale carouselType), donc le filtre ici doit rester sur la même catégorie que
// celle affichée au moment du clic — sinon on ouvre du Curatif alors que le badge affiche Chantier.
function openSecteurHistory(secteurKey) {
    const modal = document.getElementById('historyModal');
    const body = document.getElementById('modalBody');
    const title = document.getElementById('modalTitle');

    const filtered = currentData.filter(t => {
        const tType = (t.type || "").toLowerCase();
        const typeKey = tType === "préventif" ? "préventif" : (tType === "chantier" ? "chantier" : "curatif");
        if (typeKey !== carouselType) return false;
        const key = `${t.usine || I18N_KPI.non_renseigne} > ${t.secteur || I18N_KPI.non_renseigne}`;
        return key === secteurKey;
    });

    const meta = CAROUSEL_META[carouselType];
    title.innerHTML = `<i class="fa-solid ${meta.icon}" style="color:${meta.color};"></i> ${meta.label} — ${secteurKey}`;

    if (filtered.length === 0) {
        body.innerHTML = `<div class="kempty"><i class="fa-solid fa-inbox"></i>${I18N_KPI.aucune_panne_periode}</div>`;
    } else {
        body.innerHTML = `<div style="overflow-x:auto; margin-top:12px;">${renderTasksTable(filtered, I18N_KPI.equip_col_desc)}</div>`;
    }
    modal.style.display = "block";
}

// Drill-down depuis la légende "Répartition curatif / préventif / chantier" : ouvre la modale
// historique avec tous les BI de ce type (même filtre que le comptage affiché juste au-dessus).
function openTypeHistory(type) {
    const modal = document.getElementById('historyModal');
    const body = document.getElementById('modalBody');
    const title = document.getElementById('modalTitle');

    const filtered = currentData.filter(t => {
        const tType = (t.type || "").toLowerCase();
        if (type === "préventif") return tType === "préventif";
        if (type === "chantier") return tType === "chantier";
        return tType !== "préventif" && tType !== "chantier";
    });

    const meta = {
        curatif: { icon: 'fa-screwdriver-wrench', color: 'var(--stat-red)', label: I18N_KPI.type_curatif },
        'préventif': { icon: 'fa-calendar-check', color: 'var(--accent)', label: I18N_KPI.type_preventif },
        chantier: { icon: 'fa-person-digging', color: 'var(--brand-orange)', label: I18N_KPI.type_chantier }
    }[type];

    title.innerHTML = `<i class="fa-solid ${meta.icon}" style="color:${meta.color};"></i> ${I18N_KPI.title_type_history.replace('{label}', meta.label)}`;

    if (filtered.length === 0) {
        body.innerHTML = `<div class="kempty"><i class="fa-solid fa-inbox"></i>${I18N_KPI.aucun_bi_periode}</div>`;
    } else {
        body.innerHTML = `<div style="overflow-x:auto; margin-top:12px;">${renderTasksTable(filtered, I18N_KPI.equip_col_desc)}</div>`;
    }
    modal.style.display = "block";
}

function updateTrendChart(monthsData) {
    if (monthsData.length === 0) {
        document.getElementById('trend-body').innerHTML = `<div class="kempty"><i class="fa-solid fa-chart-column"></i>${I18N_KPI.pas_assez_donnees_tendance}</div>`;
        trendChartInstance = null;
        return;
    }
    if (!document.getElementById('trendChart')) {
        document.getElementById('trend-body').innerHTML = '<canvas id="trendChart"></canvas>';
    }
    if (!window.Chart) {
        const script = document.createElement('script');
        script.src = 'https://cdn.jsdelivr.net/npm/chart.js';
        script.onload = () => renderTrendChart(monthsData);
        document.head.appendChild(script);
    } else {
        renderTrendChart(monthsData);
    }
}

// --- Évolution des heures d'intervention (courbe, même esprit que sur la page Technicien) ---
let hoursTrendChartInstance = null;

function renderHoursTrendChart(daysData) {
    const canvas = document.getElementById('hoursTrendChart');
    if (!canvas) return;
    const labels = daysData.map(([key]) => { const [y, m, d] = key.split('-'); return `${d}/${m}`; });
    const values = daysData.map(([, v]) => Math.round(v * 10) / 10);

    if (hoursTrendChartInstance) hoursTrendChartInstance.destroy();
    hoursTrendChartInstance = new Chart(canvas.getContext('2d'), {
        type: 'line',
        data: {
            labels,
            datasets: [{
                label: I18N_KPI.heures_pointees,
                data: values,
                borderColor: '#2ecc71',
                backgroundColor: 'rgba(46, 204, 113, 0.15)',
                borderWidth: 3,
                pointBackgroundColor: '#2ecc71',
                pointBorderColor: '#fff',
                pointRadius: 5,
                fill: true,
                tension: 0.35
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: { display: false }, tooltip: { callbacks: { label: (ctx) => ctx.parsed.y + 'h' } } },
            scales: {
                y: { beginAtZero: true, grid: { color: '#f1f5f9' } },
                x: { grid: { display: false } }
            }
        }
    });
}

function updateHoursTrendChart(daysData) {
    if (daysData.length === 0) {
        document.getElementById('hours-trend-body').innerHTML = `<div class="kempty"><i class="fa-solid fa-arrow-trend-up"></i>${I18N_KPI.pas_assez_pointages_tendance}</div>`;
        hoursTrendChartInstance = null;
        return;
    }
    if (!document.getElementById('hoursTrendChart')) {
        document.getElementById('hours-trend-body').innerHTML = '<canvas id="hoursTrendChart"></canvas>';
    }
    if (!window.Chart) {
        const script = document.createElement('script');
        script.src = 'https://cdn.jsdelivr.net/npm/chart.js';
        script.onload = () => renderHoursTrendChart(daysData);
        document.head.appendChild(script);
    } else {
        renderHoursTrendChart(daysData);
    }
}

async function loadData() {
    try {
        const [tasksRes, pointagesRes] = await Promise.all([
            fetch('api.php?t=' + Date.now()),
            fetch('maintenance.php?get_pointages=1&t=' + Date.now())
        ]);
        allTasks = await tasksRes.json();
        allPointages = await pointagesRes.json();
        currentData = [...allTasks];
        currentPointages = [...allPointages];
        updateUI(currentData, currentPointages);
        updateLastRefresh();
        if (!carouselTimer && !carouselPaused) restartCarouselTimer();
    } catch (e) { console.error("Erreur chargement :", e); }
}

function filterData() {
    const d1 = document.getElementById('date-debut').value;
    const d2 = document.getElementById('date-fin').value;
    const tech = document.getElementById('filter-tech').value;

    currentData = allTasks.filter(t => {
        let mD = (d1 && d2) ? (t.date >= d1 && t.date <= d2) : true;
        let techName = t.tech ? t.tech.trim() : "";
        let mT = tech ? (techName === tech) : true;
        return mD && mT;
    });

    currentPointages = allPointages.filter(p => {
        let mD = (d1 && d2) ? (p.date >= d1 && p.date <= d2) : true;
        let techName = p.tech ? p.tech.trim() : "";
        let mT = tech ? (techName === tech) : true;
        return mD && mT;
    });

    updateUI(currentData, currentPointages);
}

function resetFilters() {
    document.getElementById('date-debut').value = '';
    document.getElementById('date-fin').value = '';
    document.getElementById('filter-tech').value = '';
    currentData = [...allTasks];
    currentPointages = [...allPointages];
    updateUI(currentData, currentPointages);
}

function updateUI(data, pointages) {
    let hrs = 0, done = 0, curatif = 0, preventif = 0, chantier = 0;
    let hrsCuratif = 0, hrsPreventif = 0, hrsChantier = 0;
    let cassePeriode = 0, totalPeriode = 0;
    let eMapInfo = {};
    let dayHoursMap = {};

    // Map id -> type, pour ventiler les heures pointées (table pointages, liée par task_id)
    // entre curatif et préventif sans dupliquer la logique de détection du type.
    let taskTypeMap = {};
    data.forEach(t => { taskTypeMap[t.id] = (t.type || "").toLowerCase(); });

    pointages.forEach(p => {
        const h = parseFloat(p.hours || 0);
        hrs += h;
        if (taskTypeMap[p.task_id] === "préventif") hrsPreventif += h;
        else if (taskTypeMap[p.task_id] === "chantier") hrsChantier += h;
        else if (p.task_id in taskTypeMap) hrsCuratif += h;
        if (p.date) {
            const dayKey = p.date.split(' ')[0];
            dayHoursMap[dayKey] = (dayHoursMap[dayKey] || 0) + h;
        }
    });

    data.forEach(t => {
        if((t.statut||"").toLowerCase().includes("termin")) done++;
        const tType = (t.type || "").toLowerCase();
        if (tType === "préventif") preventif++; else if (tType === "chantier") chantier++; else curatif++;

        // Taux de casse calculé sur la période filtrée (Du/Au en haut de page), pas sur le
        // mois calendaire en cours — pour rester cohérent avec le reste de la page qui suit
        // toujours ce filtre.
        totalPeriode++;
        if(t.casse == 1 || t.casse === true || t.casse === "on") cassePeriode++;

        // KPI "Panne la plus récurrente" : uniquement le curatif+chantier (pannes, casses). Le
        // préventif est programmé pour revenir tous les jours/semaines par nature (inspections,
        // relevés...) — l'inclure noierait les vraies pannes récurrentes sous ces contrôles de
        // routine, ce qui rendrait ce classement inutile pour repérer un équipement qui pose
        // vraiment problème.
        const isPreventifTask = (t.type || "").toLowerCase() === "préventif";
        if (isPreventifTask) return;

        let lieuComplet = `${t.usine || ''} > ${t.secteur || ''} > ${t.zone || ''}`;
        let machineNom = t.equip ? t.equip.trim() : "Inconnu";
        let uniqueKey = lieuComplet + " > " + machineNom;

        if(!eMapInfo[uniqueKey]) {
            eMapInfo[uniqueKey] = {
                count: 0,
                descriptions: [],
                shortName: machineNom,
                fullPath: lieuComplet,
                lastType: t.type,
                lastStatut: t.statut,
                lastPrio: t.prio,
                lastCasse: t.casse,
                lastNumBi: t.num_bi //
            };
        }
        eMapInfo[uniqueKey].count++;
        if(t.desc) {
            eMapInfo[uniqueKey].descriptions.push({
                id: t.id,
                desc: t.desc,
                num_bi: t.num_bi,
                type: t.type,
                prio: t.prio,
                statut: t.statut,
                casse: t.casse
            });
        }
    });

    // Mise à jour des KPI (Haut)
    const total = data.length || 1;
    const pcCuratif = Math.round((curatif / total) * 100);
    const pcPreventif = Math.round((preventif / total) * 100);
    const pcChantier = 100 - pcCuratif - pcPreventif;
    document.getElementById('k-total').innerText = data.length;
    document.getElementById('k-rate').innerText = Math.round((done/total)*100) + "%";
    document.getElementById('k-hours').innerText = Math.round(hrs) + "h";
    document.getElementById('k-break-rate').innerText = totalPeriode > 0 ? Math.round((cassePeriode/totalPeriode)*100) + "%" : "0%";
    document.getElementById('count-curatif').innerText = curatif;
    document.getElementById('count-preventif').innerText = preventif;
    document.getElementById('count-chantier').innerText = chantier;
    document.getElementById('pct-curatif').innerText = "(" + pcCuratif + "%)";
    document.getElementById('pct-preventif').innerText = "(" + pcPreventif + "%)";
    document.getElementById('pct-chantier').innerText = "(" + pcChantier + "%)";
    document.getElementById('hours-curatif').innerText = Math.round(hrsCuratif) + "h";
    document.getElementById('hours-preventif').innerText = Math.round(hrsPreventif) + "h";
    document.getElementById('hours-chantier').innerText = Math.round(hrsChantier) + "h";
    document.getElementById('bar-curatif').style.width = pcCuratif + "%";
    document.getElementById('bar-preventif').style.width = pcPreventif + "%";
    document.getElementById('bar-chantier').style.width = pcChantier + "%";

    // --- Évolution des heures d'intervention, 7 derniers jours avec des données ---
    const sortedDays = Object.entries(dayHoursMap).sort((a,b) => a[0].localeCompare(b[0])).slice(-7);
    updateHoursTrendChart(sortedDays);

    // --- KPI "Panne la plus récurrente" : curatif+chantier combinés (préventif exclu), cf.
    // commentaire plus haut sur eMapInfo. Indépendant du carrousel ci-dessous.
    const sortedE = Object.entries(eMapInfo).sort((a,b) => b[1].count - a[1].count);
    document.getElementById('k-top').innerText = sortedE[0] ? sortedE[0][1].shortName : "-";

    // --- Carrousel curatif / préventif / chantier : mêmes agrégations (secteur, équipement,
    // tendance mensuelle) que ci-dessus, mais calculées séparément pour chacun des 3 types afin
    // de pouvoir basculer l'affichage des panneaux sans les mélanger.
    let sMapByType = { curatif: {}, 'préventif': {}, chantier: {} };
    let eMapByType = { curatif: {}, 'préventif': {}, chantier: {} };
    let monthMapByType = { curatif: {}, 'préventif': {}, chantier: {} };

    data.forEach(t => {
        const tType = (t.type || "").toLowerCase();
        const typeKey = tType === "préventif" ? "préventif" : (tType === "chantier" ? "chantier" : "curatif");

        const d = new Date(t.date);
        if (!isNaN(d)) {
            const monthKey = `${d.getFullYear()}-${String(d.getMonth()+1).padStart(2,'0')}`;
            monthMapByType[typeKey][monthKey] = (monthMapByType[typeKey][monthKey] || 0) + 1;
        }

        let secteurKey = `${t.usine || I18N_KPI.non_renseigne} > ${t.secteur || I18N_KPI.non_renseigne}`;
        if (!sMapByType[typeKey][secteurKey]) sMapByType[typeKey][secteurKey] = { count: 0 };
        sMapByType[typeKey][secteurKey].count++;

        let lieuComplet = `${t.usine || ''} > ${t.secteur || ''} > ${t.zone || ''}`;
        let machineNom = t.equip ? t.equip.trim() : "Inconnu";
        let uniqueKey = lieuComplet + " > " + machineNom;

        if (!eMapByType[typeKey][uniqueKey]) {
            eMapByType[typeKey][uniqueKey] = { count: 0, descriptions: [], shortName: machineNom, fullPath: lieuComplet };
        }
        eMapByType[typeKey][uniqueKey].count++;
        if (t.desc) {
            eMapByType[typeKey][uniqueKey].descriptions.push({
                id: t.id, desc: t.desc, num_bi: t.num_bi, type: t.type, prio: t.prio, statut: t.statut, casse: t.casse
            });
        }
    });

    kpiAggByType = {
        curatif: { sMap: sMapByType.curatif, eMap: eMapByType.curatif, monthMap: monthMapByType.curatif },
        'préventif': { sMap: sMapByType['préventif'], eMap: eMapByType['préventif'], monthMap: monthMapByType['préventif'] },
        chantier: { sMap: sMapByType.chantier, eMap: eMapByType.chantier, monthMap: monthMapByType.chantier }
    };

    renderCarouselPanels();
}

// Affiche, pour le type actif du carrousel (variable globale carouselType), les 3 panneaux
// "Pannes récurrentes par secteur/équipement" et "Tendance mensuelle" à partir de kpiAggByType
// (recalculé à chaque updateUI). Appelée au chargement, à chaque bascule manuelle/auto du
// carrousel, et à chaque refiltrage de la page.
function renderCarouselPanels() {
    const agg = kpiAggByType[carouselType] || { sMap: {}, eMap: {}, monthMap: {} };
    const meta = CAROUSEL_META[carouselType];
    const badgeClass = carouselType === 'préventif' ? 'type-preventif' : 'type-' + carouselType;

    CAROUSEL_TYPES.forEach(t => {
        const pill = document.getElementById('carousel-pill-' + t);
        if (pill) pill.classList.toggle('active', t === carouselType);
    });

    const badgeSecteur = document.getElementById('badge-secteur');
    const badgeEquip = document.getElementById('badge-equip');
    const badgeTrend = document.getElementById('badge-trend');
    if (badgeSecteur) { badgeSecteur.textContent = meta.label; badgeSecteur.className = 'panel-badge ' + badgeClass; }
    if (badgeEquip) { badgeEquip.textContent = I18N_KPI.badge_top15_suffix.replace('{label}', meta.label); badgeEquip.className = 'panel-badge ' + badgeClass; }
    if (badgeTrend) { badgeTrend.textContent = I18N_KPI.badge_12mois_suffix.replace('{label}', meta.label); badgeTrend.className = 'panel-badge ' + badgeClass; }

    // --- Pannes récurrentes par secteur ---
    const sortedS = Object.entries(agg.sMap).sort((a,b) => b[1].count - a[1].count);
    const maxS = sortedS[0] ? sortedS[0][1].count : 1;

    // Icône + barre de progression suivent la couleur du type actif (rouge curatif, bleu
    // préventif, orange chantier) via des variables CSS lues par .bar-secteur/.secteur-icon.
    const secteurListEl = document.getElementById('secteur-list');
    secteurListEl.style.setProperty('--secteur-color', meta.color);
    secteurListEl.style.setProperty('--secteur-color-light', lighten(meta.color, 0.35));
    secteurListEl.style.setProperty('--secteur-color-bg', tint(meta.color, 0.12));
    secteurListEl.style.setProperty('--secteur-color-bg-hover', tint(meta.color, 0.22));
    secteurListEl.style.setProperty('--secteur-color-dark', meta.hoverColor);

    if (sortedS.length === 0) {
        secteurListEl.innerHTML = `
            <div class="kempty"><i class="fa-solid fa-map"></i>${I18N_KPI.aucune_panne_detectee}</div>`;
    } else {
        secteurListEl.innerHTML = sortedS.map(([name, info], i) => `
            <div class="tech-row secteur-row-click" onclick="openSecteurHistory('${name.replace(/'/g, "\\'")}')" title="${I18N_KPI.voir_bi_type.replace('{type}', meta.label)}">
                <span class="tech-rank">${i+1}</span>
                <span class="secteur-icon"><i class="fa-solid fa-industry"></i></span>
                <div class="tech-data">
                    <div class="row1">
                        <span class="tech-name">${name}</span>
                        <span class="tech-hours">x ${info.count}</span>
                    </div>
                    <div class="progress-box"><div class="progress-bar bar-secteur" style="width:${(info.count/maxS)*100}%"></div></div>
                </div>
            </div>`).join('');
    }

    // --- Tendance mensuelle des pannes, 12 derniers mois avec des données ---
    const sortedMonths = Object.entries(agg.monthMap).sort((a,b) => a[0].localeCompare(b[0])).slice(-12);
    updateTrendChart(sortedMonths);

    // --- Équipements ---
    const sortedE = Object.entries(agg.eMap).sort((a,b) => b[1].count - a[1].count);

    if (sortedE.length === 0) {
        document.getElementById('equip-list').innerHTML = `
            <div class="kempty"><i class="fa-solid fa-box-open"></i>${I18N_KPI.aucun_equipement_periode}</div>`;
    } else {
        document.getElementById('equip-list').innerHTML = sortedE.slice(0,15).map(([key, info]) => {

            let lastDescs = info.descriptions.map(item => {
                let linkHtml = '';
                if (item.num_bi && item.id) {
                    linkHtml = `<a href="#" onclick="showDetailBI('${item.id}'); return false;" class="chip chip-bi" title="${I18N_KPI.ouvrir_ri} ${item.num_bi}">#${item.num_bi}</a>`;
                } else {
                    linkHtml = `<span class="chip chip-bi">${I18N_KPI.prev_fallback}</span>`;
                }

                let itemType = (item.type || "").toLowerCase();
                let badgeType = itemType === "préventif" ? `<span class="chip chip-type-prev">${I18N_KPI.chip_prev}</span>`
                    : itemType === "chantier" ? `<span class="chip chip-type-chan">${I18N_KPI.chip_chan}</span>`
                    : `<span class="chip chip-type-cura">${I18N_KPI.chip_cura}</span>`;

                let badgePrio = item.prio === "Urgent"
                    ? `<span class="chip" style="background:${tint(LIBELLES.urgent.couleur,0.14)}; color:${LIBELLES.urgent.couleur};"><i class="fa-solid fa-triangle-exclamation"></i> ${LIBELLES.urgent.label.toUpperCase()}</span>`
                    : '';

                let badgeStatut = statusBadge(item.statut, 'sm');

                return `<div class="equip-desc-row">
                    <span class="bullet">•</span>${linkHtml}${badgeType}${badgePrio}${badgeStatut}
                    <span class="txt">: ${item.desc}</span>
                </div>`;
            }).join('');

            return `
            <div class="equip-card">
                <div class="equip-loc"><i class="fa-solid fa-location-arrow"></i> ${info.fullPath}</div>
                <div class="equip-main">
                    <span class="equip-name">${info.shortName}</span>
                    <span class="equip-count">x ${info.count}</span>
                </div>
                ${lastDescs}
            </div>`;
        }).join('');
    }
}

// Change le type actif du carrousel. `auto` = appelé par le minuteur (ne redémarre pas le
// minuteur en plein cycle) ; sinon (clic manuel sur une pastille) redémarre le minuteur à zéro.
function setCarouselType(type, auto) {
    carouselType = type;
    renderCarouselPanels();
    if (!auto && !carouselPaused) restartCarouselTimer();
}

function advanceCarousel() {
    const idx = CAROUSEL_TYPES.indexOf(carouselType);
    setCarouselType(CAROUSEL_TYPES[(idx + 1) % CAROUSEL_TYPES.length], true);
}

function restartCarouselTimer() {
    if (carouselTimer) clearInterval(carouselTimer);
    carouselTimer = setInterval(advanceCarousel, 5000);
}

function toggleCarousel() {
    carouselPaused = !carouselPaused;
    const btn = document.getElementById('carousel-toggle-btn');
    const icon = document.getElementById('carousel-toggle-icon');
    const label = document.getElementById('carousel-toggle-label');
    if (carouselPaused) {
        clearInterval(carouselTimer);
        carouselTimer = null;
        btn.classList.add('is-paused');
        icon.className = 'fa-solid fa-play';
        label.textContent = I18N_KPI.reprendre;
    } else {
        restartCarouselTimer();
        btn.classList.remove('is-paused');
        icon.className = 'fa-solid fa-pause';
        label.textContent = I18N_KPI.pause;
    }
}

function openHistory(type) {
    const modal = document.getElementById('historyModal');
    const body = document.getElementById('modalBody');
    const title = document.getElementById('modalTitle');
    let filtered = [];

    if(type === 'total') {
        filtered = currentData;
        title.innerHTML = `<i class="fa-solid fa-list-check" style="color:var(--accent);"></i> ${I18N_KPI.title_historique_total}`;
    } else if(type === 'done') {
        filtered = currentData.filter(t => (t.statut||"").toLowerCase().includes("termin"));
        title.innerHTML = `<i class="fa-solid fa-circle-check" style="color:var(--success);"></i> ${I18N_KPI.title_interventions_cloturees}`;
    } else if(type === 'casse') {
        filtered = currentData.filter(t => (t.casse == 1 || t.casse === true || t.casse === "true" || t.casse === "on"));
        title.innerHTML = `<i class="fa-solid fa-bolt" style="color:var(--danger);"></i> ${I18N_KPI.title_urgences_casse}`;
    } else if(type === 'recurring') {
        // Uniquement le curatif : le préventif revient par nature tous les jours/semaines
        // (inspections, relevés...), l'inclure noierait les vraies pannes récurrentes.
        let equipCounts = {};
        currentData.forEach(t => {
            if ((t.type || "").toLowerCase() === "préventif") return;
            let eq = t.equip ? t.equip.trim() : "Inconnu";
            equipCounts[eq] = (equipCounts[eq] || 0) + 1;
        });

        let sorted = Object.entries(equipCounts).sort((a, b) => b[1] - a[1]);
        let top5Names = sorted.slice(0, 5).map(([name, _]) => name);

        title.innerHTML = `<i class="fa-solid fa-chart-pie" style="color: var(--brand-orange);"></i> ${I18N_KPI.title_analyse_recurrentes} <span style="font-size:0.6em; color:#94a3b8; font-weight:600; text-transform:uppercase; margin-left:6px;">${I18N_KPI.curatif_uniquement}</span>`;

        if (sorted.length === 0) {
            body.innerHTML = `<div class="kempty"><i class="fa-solid fa-inbox"></i>${I18N_KPI.aucune_donnee}</div>`;
            modal.style.display = "block";
            return;
        }

        body.innerHTML = `
        <div style="display: flex; flex-wrap: wrap; gap: 30px; align-items: center; justify-content: center; padding: 15px 0 20px; border-bottom: 1px solid #eef2f3;">
            <div style="width: 250px; height: 250px; position: relative; cursor: pointer;" title="${I18N_KPI.click_segment_tooltip}">
                <canvas id="recurringChart"></canvas>
            </div>
            <div style="flex: 1; min-width: 280px;">
                <div style="font-size: 0.7rem; color: #94a3b8; font-weight: 700; margin-bottom: 8px; text-transform: uppercase; letter-spacing:.03em;"><i class="fa-solid fa-arrow-pointer"></i> ${I18N_KPI.click_ligne_segment}</div>
                <table class="ktable">
                    <thead><tr><th>${I18N_KPI.th_equipement_top}</th><th style="text-align:center; width:90px;">${I18N_KPI.th_pannes}</th></tr></thead>
                    <tbody>
                        ${sorted.slice(0, 5).map(([name, count]) => `
                            <tr style="cursor:pointer;" onclick="window.triggerChartClick('${name.replace(/'/g, "\\'")}')">
                                <td style="font-weight:700; color:var(--primary); overflow:hidden; text-overflow:ellipsis; white-space:nowrap;"><i class="fa-solid fa-gears" style="color:#cbd5e1; margin-right:6px;"></i>${name}</td>
                                <td style="text-align:center;"><span class="chip chip-bi" style="font-size:0.7rem; padding:3px 9px; border-radius:999px;">x ${count}</span></td>
                            </tr>
                        `).join('')}
                    </tbody>
                </table>
            </div>
        </div>
        <div id="zoneDetailsRecurrence" style="margin-top: 20px; width: 100%;"></div>`;

        modal.style.display = "block";

        let displaySpecificEquipRows = (equipTarget) => {
            const targetZone = document.getElementById('zoneDetailsRecurrence');
            let filteredTasks = [];
            let affichageTitre = "";

            // Même filtre curatif que pour le comptage ci-dessus, pour que le nombre de lignes
            // affichées corresponde exactement au "x N" cliqué.
            const curatifOnly = t => (t.type || "").toLowerCase() !== "préventif";
            if (equipTarget === I18N_KPI.autres) {
                filteredTasks = currentData.filter(t => t.equip && curatifOnly(t) && !top5Names.includes(t.equip.trim()));
                affichageTitre = I18N_KPI.equipements_secondaires;
            } else {
                filteredTasks = currentData.filter(t => t.equip && curatifOnly(t) && t.equip.trim() === equipTarget);
                affichageTitre = `${I18N_KPI.equipement_prefix} <strong>${equipTarget}</strong>`;
            }

            if (filteredTasks.length === 0) return;

            targetZone.innerHTML = `
            <h3 style="font-family:'Inter',sans-serif; color:var(--primary); font-size:0.95rem; font-weight:800; margin-bottom:12px; padding-bottom:8px; border-bottom:2px solid var(--brand-orange); display:flex; align-items:center; gap:8px;">
                <i class="fa-solid fa-folder-open" style="color: var(--brand-orange);"></i> ${I18N_KPI.bi_pour} ${affichageTitre}
            </h3>
            <div style="overflow-x:auto;">${renderTasksTable(filteredTasks, I18N_KPI.loc_col_desc)}</div>`;

            targetZone.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        };

        window.triggerChartClick = displaySpecificEquipRows;

        let renderChart = () => {
            const ctx = document.getElementById('recurringChart').getContext('2d');
            let chartLabels = [];
            let chartData = [];

            sorted.slice(0, 5).forEach(([name, count]) => {
                chartLabels.push(name);
                chartData.push(count);
            });

            if (sorted.length > 5) {
                let restSum = sorted.slice(5).reduce((sum, [_, count]) => sum + count, 0);
                chartLabels.push(I18N_KPI.autres);
                chartData.push(restSum);
            }

            new Chart(ctx, {
                type: 'doughnut',
                data: {
                    labels: chartLabels,
                    datasets: [{
                        data: chartData,
                        backgroundColor: ['#3498db', '#f39c12', '#e74c3c', '#2ecc71', '#9b59b6', '#cbd5e1'],
                        borderWidth: 3,
                        borderColor: '#ffffff'
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: { legend: { display: false } },
                    cutout: '68%',
                    onClick: (evt, activeElements) => {
                        if (activeElements && activeElements.length > 0) {
                            const index = activeElements[0].index;
                            const clickedLabel = chartLabels[index];
                            displaySpecificEquipRows(clickedLabel);
                        }
                    }
                }
            });
        };

        if (!window.Chart) {
            const script = document.createElement('script');
            script.src = 'https://cdn.jsdelivr.net/npm/chart.js';
            script.onload = renderChart;
            document.head.appendChild(script);
        } else {
            renderChart();
        }
        return;
    }

    // --- Rendu du tableau détaillé (Total, Clôturées, Urgences) ---
    body.innerHTML = `<div style="overflow-x:auto; margin-top: 12px;">${renderTasksTable(filtered, I18N_KPI.equip_col_desc)}</div>`;

    modal.style.display = "block";
}

function closeModal() { document.getElementById('historyModal').style.display = "none"; }
window.onclick = e => {
    if (e.target == document.getElementById('historyModal')) closeModal();
}
window.onload = loadData;
</script>
<?php include 'composant_rapport.php'; ?>
</body>
</html>
