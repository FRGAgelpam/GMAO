<?php
require_once __DIR__ . '/session_init.php';

if (!isset($_SESSION['user']) || ($_SESSION['role'] ?? '') !== 'admin') {
    header("Location: login.php");
    exit();
}

$is_admin = ($_SESSION['role'] === 'admin');
$hide_accueil_btn = true;
$hide_menu_button = true;

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(langue_actuelle()); ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
<title><?php echo htmlspecialchars(t('aide_kpi.page_title_tag')); ?></title>
<link rel="icon" type="image/png" href="img/logo.png">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Caveat:wght@600&family=Segoe+UI:wght@300;400;600&family=Montserrat:wght@400;700;800;900&display=swap" rel="stylesheet">
<style>
:root {
    --primary: #2c3e50; --accent: #3498db; --success: #2ecc71;
    --danger: #e74c3c; --brand-green: #2ecc71; --brand-orange: #f39c12;
    --brand-blue: #005696; --neutral-dark: #34495e; --attente: #95a5a6;
}
body { margin: 0; font-family: 'Segoe UI', sans-serif; background: linear-gradient(rgba(0, 0, 0, 0.2), rgba(0, 0, 0, 0.2)), url('img/fond.jpg') no-repeat center center fixed; background-color: #1a2733; background-size: cover; min-height: 100vh; padding-top: 54px; color: var(--primary); box-sizing: border-box; }
@keyframes pulse-dot { 0% { transform: scale(1); opacity: 0.6; } 100% { transform: scale(2.5); opacity: 0; } }
header { position: fixed; top: 0; width: 100%; z-index: 1000; overflow: hidden; box-shadow: 0 4px 20px rgba(0,0,0,0.25); }
        header::before { content: ""; position: absolute; top: -12px; left: -12px; right: -12px; bottom: -12px; background: linear-gradient(rgba(0, 0, 0, 0.2), rgba(0, 0, 0, 0.2)), url('img/fond.jpg') no-repeat center center fixed; background-size: cover; filter: blur(4px); z-index: -1; }
.crumb-bar { display: flex; align-items: center; gap: 8px; font-size: 0.94rem; font-weight: 600; color: rgba(255,255,255,0.9); text-shadow: 0 1px 3px rgba(0,0,0,0.4); max-width: 99%; margin: 0 auto; padding: 0 10px 10px; }
.crumb-bar .crumb-home { color: inherit; text-decoration: none; display: inline-flex; align-items: center; gap: 5px; }
.crumb-bar .crumb-home:hover { text-decoration: underline; }
.crumb-bar .crumb-parent { color: inherit; text-decoration: none; }
.crumb-bar .crumb-parent:hover { text-decoration: underline; }
.crumb-sep { color: rgba(255,255,255,0.45); font-weight: 400; }
.crumb-current { color: rgba(255,255,255,0.75); font-weight: 400; }
.header-top { display: flex; justify-content: space-between; align-items: center; padding: 8px 20px; }
.header-title { font-family: 'Caveat', cursive; font-size: 1.6rem; color: var(--primary); }
.nav-tabs { display: flex; background: #fff; padding: 0 10px; gap: 2px; overflow-x: auto; }
.tab-item { padding: 10px 18px; text-decoration: none; color: #7f8c8d; font-weight: 600; font-size: 0.8rem; border-bottom: 3px solid transparent; transition: 0.3s; display: flex; align-items: center; gap: 8px; white-space: nowrap; }
.tab-item:hover { background: rgba(0,0,0,0.02); }
.tab-item.active { color: var(--accent); border-bottom: 3px solid var(--accent); background: rgba(52, 152, 219, 0.05); }
.user-badge { background: rgba(255,255,255,0.12); backdrop-filter: blur(14px) brightness(1.15); -webkit-backdrop-filter: blur(14px) brightness(1.15); padding: 5px 12px; border-radius: 20px; font-size: 0.75rem; font-weight: 600; display: flex; align-items: center; gap: 8px; color: white; border: 1px solid rgba(255,255,255,0.4); text-shadow: 0 1px 3px rgba(0,0,0,0.4); box-shadow: 0 6px 16px rgba(0,0,0,0.2); }
.status-pulse { width: 8px; height: 8px; border-radius: 50%; position: relative; background: var(--success); }
.status-pulse::after { content: ""; position: absolute; width: 100%; height: 100%; border-radius: 50%; background: inherit; animation: pulse-dot 2s infinite; opacity: 0.6; }
.sidebar { height: 100%; width: 0; position: fixed; z-index: 3000; top: 0; left: 0; background-color: #1a252f; overflow-x: hidden; overflow-y: auto; transition: 0.4s; padding-top: 60px; padding-bottom: 20px; }
.sidebar a { padding: 12px 25px; text-decoration: none; font-size: 1.05rem; color: #ecf0f1; display: block; transition: 0.3s; border-left: 4px solid transparent; }
.sidebar a:hover { background: #2c3e50; border-left: 4px solid var(--accent); }
.sidebar a.active-side { background: #2c3e50; border-left: 4px solid var(--accent); color: var(--accent); font-weight: bold; }
.sidebar .closebtn { position: absolute; top: 10px; right: 25px; font-size: 36px; color: white; border: none; background: none; cursor: pointer; }
.openbtn { font-size: 22px; cursor: pointer; background: none; border: none; color: var(--primary); padding: 5px 10px; }

html { scroll-behavior: smooth; }
@media (prefers-reduced-motion: reduce) { html { scroll-behavior: auto; } }
.main-content { max-width: 1280px; margin: 0 auto; padding: 22px 20px 70px; }
.crumb { display: flex; align-items: center; gap: 8px; font-size: 0.98rem; font-weight: 600; margin-bottom: 12px; color: #fff; }
.crumb span:nth-child(2) { color: rgba(255,255,255,0.45); font-weight: 400; }
.hero { background: linear-gradient(120deg, var(--primary) 0%, #1a2733 100%); border-radius: 18px; padding: 30px 38px; color: #fff; position: relative; overflow: hidden; box-shadow: 0 18px 40px -14px rgba(0,0,0,0.45); margin-bottom: 24px; }
.hero::after { content: ""; position: absolute; right: -60px; top: -60px; width: 260px; height: 260px; border-radius: 50%; background: radial-gradient(circle, rgba(52,152,219,0.35), transparent 70%); }
.hero-eyebrow { display: inline-flex; align-items: center; gap: 8px; font-size: 0.72rem; font-weight: 700; letter-spacing: 0.08em; text-transform: uppercase; background: rgba(255,255,255,0.12); border: 1px solid rgba(255,255,255,0.25); padding: 6px 14px; border-radius: 999px; margin-bottom: 16px; }
.hero h1 { font-family: 'Montserrat', sans-serif; font-weight: 900; font-size: clamp(1.7rem, 3vw, 2.35rem); margin: 0 0 6px; letter-spacing: -0.01em; }
.hero .tagline { font-family: 'Caveat', cursive; font-size: 1.35rem; color: #cfe4f5; margin: 0 0 18px; }
.hero-meta { display: flex; flex-wrap: wrap; gap: 10px; position: relative; z-index: 1; }
.hero-pill { font-size: 0.78rem; font-family: 'Segoe UI', sans-serif; background: rgba(255,255,255,0.1); border: 1px solid rgba(255,255,255,0.22); border-radius: 8px; padding: 6px 12px; }
.hero-pill b { color: #fff; }

.layout { display: grid; grid-template-columns: 1fr; gap: 22px; align-items: start; }
@media (min-width: 980px) { .layout { grid-template-columns: 250px minmax(0,1fr); } }
.toc-card { background: rgba(255,255,255,0.97); border-radius: 14px; box-shadow: 0 10px 28px -10px rgba(0,0,0,0.25); overflow: hidden; }
@media (min-width: 980px) { .toc-card { position: sticky; top: 124px; } }
.toc-toggle { display: none; }
.toc-label { display: flex; align-items: center; justify-content: space-between; gap: 10px; cursor: pointer; padding: 14px 16px; font-weight: 700; color: var(--primary); font-size: 0.95rem; }
.toc-label .fa-chevron-down { transition: transform 0.2s ease; color: var(--accent); }
.toc-toggle:checked ~ .toc-label .fa-chevron-down { transform: rotate(180deg); }
.toc-list { list-style: none; margin: 0; padding: 0 8px 10px; display: none; }
.toc-toggle:checked ~ .toc-list { display: block; }
.toc-list a { display: flex; align-items: center; gap: 10px; padding: 9px 10px; border-radius: 8px; color: #5a6b7a; text-decoration: none; font-size: 0.86rem; font-weight: 600; transition: 0.15s; }
.toc-list a i { color: var(--accent); width: 16px; text-align: center; }
.toc-list a:hover { background: #eef4fa; color: var(--primary); }
.toc-sep { height: 1px; background: #e6e9ec; margin: 6px 6px; }
@media (min-width: 980px) { .toc-label { display: none; } .toc-list { display: block !important; padding: 10px; } }

main.content { min-width: 0; }
.card { background: rgba(255,255,255,0.97); border-radius: 14px; box-shadow: 0 10px 28px -10px rgba(0,0,0,0.22); padding: 26px 28px; }
section.aide-sec { margin-bottom: 22px; scroll-margin-top: 128px; }
.sec-eyebrow { display: flex; align-items: center; gap: 10px; margin-bottom: 4px; }
.sec-eyebrow i { color: #fff; background: var(--accent); width: 34px; height: 34px; border-radius: 9px; display: flex; align-items: center; justify-content: center; font-size: 0.95rem; flex: none; }
.aide-sec h2 { font-family: 'Montserrat', sans-serif; font-weight: 800; font-size: 1.28rem; color: var(--primary); margin: 0; }
.lede { color: #5a6b7a; font-size: 1rem; max-width: 68ch; margin: 10px 0 18px; line-height: 1.6; }
.aide-sec h3 { font-family: 'Montserrat', sans-serif; font-size: 1rem; font-weight: 700; color: var(--primary); margin: 0 0 6px; }
.aide-sec p { color: #405060; line-height: 1.6; font-size: 0.94rem; }
.aide-sec ul { color: #405060; line-height: 1.65; font-size: 0.94rem; }
.aide-sec li { margin-bottom: 6px; }

.tbl-wrap { overflow-x: auto; border: 1px solid #e6e9ec; border-radius: 12px; margin-top: 14px; }
table { border-collapse: collapse; width: 100%; min-width: 560px; font-size: 0.88rem; }
thead th { text-align: left; font-family: 'Montserrat', sans-serif; font-weight: 700; font-size: 0.72rem; letter-spacing: 0.04em; text-transform: uppercase; color: #7f8c9a; background: #f7f9fb; padding: 11px 14px; border-bottom: 1px solid #e6e9ec; }
tbody td { padding: 11px 14px; border-bottom: 1px solid #eef1f3; color: #405060; vertical-align: top; }
tbody tr:last-child td { border-bottom: none; }
tbody td:first-child { font-weight: 700; color: var(--primary); white-space: nowrap; }

.callout { display: flex; gap: 12px; border-radius: 10px; padding: 14px 16px; border: 1px solid; font-size: 0.9rem; margin: 14px 0; }
.callout i { font-size: 1.05rem; margin-top: 2px; flex: none; }
.callout p { margin: 0; }
.callout p + p { margin-top: 6px; }
.callout-tip { background: #eef8f0; border-color: #cdeed4; color: #1f6b3a; }
.callout-tip i { color: var(--success); }
.callout-warn { background: #fdeeec; border-color: #f6cec7; color: #9a3226; }
.callout-warn i { color: var(--danger); }
.callout-lock { background: #f4f6f7; border-color: #e2e7ea; color: #445565; }
.callout-lock i { color: #7f8c9a; }
.callout b { font-weight: 700; }

.pill { display: inline-flex; align-items: center; gap: 6px; font-weight: 700; font-size: 0.72rem; letter-spacing: 0.03em; text-transform: uppercase; padding: 5px 12px; border-radius: 999px; color: #fff; white-space: nowrap; }
.pill-admin { background: var(--brand-orange); }
.pill-all { background: var(--accent); }
.pill-dot { width: 6px; height: 6px; border-radius: 50%; background: rgba(255,255,255,0.85); }

.gloss { display: grid; grid-template-columns: 1fr; gap: 14px; margin-top: 12px; }
@media (min-width: 700px) { .gloss { grid-template-columns: 1fr 1fr; } }
.gloss dt { font-family: 'Montserrat', sans-serif; font-weight: 800; color: var(--accent); font-size: 0.86rem; }
.gloss dd { margin: 3px 0 0; color: #5a6b7a; font-size: 0.88rem; }

.shot { margin: 16px 0; border-radius: 12px; overflow: hidden; border: 1px solid #e6e9ec; box-shadow: 0 10px 24px -12px rgba(0,0,0,0.28); background: #fff; }
.shot img { display: block; width: 100%; height: auto; }
.shot-cap { padding: 8px 14px; font-size: 0.78rem; color: #7f8c9a; background: #f7f9fb; border-top: 1px solid #e6e9ec; display: flex; align-items: center; gap: 8px; }
.shot-cap i { color: var(--accent); }
.shot-row { display: grid; grid-template-columns: 1fr; gap: 14px; }
@media (min-width: 780px) { .shot-row.two { grid-template-columns: 1fr 1fr; } }

.pitfalls { display: flex; flex-direction: column; gap: 10px; margin-top: 14px; }
.pf { display: grid; grid-template-columns: 2.3rem minmax(0,1fr); gap: 14px; background: #fdf7ec; border: 1px solid #f3e3bf; border-radius: 12px; padding: 15px 18px; }
.pf-num { width: 2.3rem; height: 2.3rem; border-radius: 50%; background: #f9e6bd; color: #92660a; display: flex; align-items: center; justify-content: center; font-size: 1.1rem; }
.pf h4 { font-family: 'Montserrat', sans-serif; font-size: 0.94rem; margin: 0 0 4px; color: #7a5407; }
.pf p { margin: 0; font-size: 0.9rem; color: #6b5220; }

.devnote { background: #f7f9fb; border: 1px dashed #cdd5db; border-radius: 12px; padding: 16px 18px; margin-top: 20px; }
.devnote summary { cursor: pointer; font-family: 'Montserrat', sans-serif; font-weight: 700; color: var(--primary); font-size: 0.92rem; }
.devnote ul { margin-top: 10px; }
.devnote code, .aide-sec code { font-family: 'Consolas', monospace; background: #eef1f3; border-radius: 4px; padding: 2px 6px; font-size: 0.86em; color: #2c3e50; }

.back-top { display: inline-flex; align-items: center; gap: 8px; margin-top: 26px; color: var(--accent); text-decoration: none; font-weight: 600; font-size: 0.85rem; }
.back-top:hover { text-decoration: underline; }

.btn-floating-nav { position: fixed; top: 20px; left: 20px; z-index: 999; width: 46px; height: 46px; border-radius: 50%; background: rgba(255, 255, 255, 0.12); backdrop-filter: blur(14px) brightness(1.15); -webkit-backdrop-filter: blur(14px) brightness(1.15); border: 1px solid rgba(255,255,255,0.4); color: white; font-size: 1.15rem; display: flex; align-items: center; justify-content: center; text-decoration: none; box-shadow: 0 8px 20px rgba(0,0,0,0.25); transition: transform 0.3s, box-shadow 0.3s, background 0.3s, border-color 0.3s; }
.btn-floating-nav:hover { transform: translateY(-3px); }
.btn-floating-nav.home:hover { background: rgba(46,204,113,0.3); border-color: rgba(46,204,113,0.6); box-shadow: 0 14px 26px -8px rgba(46,204,113,0.5), 0 8px 18px rgba(0,0,0,0.2); }
</style>
<?php include 'pwa_head.php'; ?>
</head>
<body onclick="checkCloseSidebar(event)">

<a href="aide.php" class="btn-floating-nav home" title="<?php echo htmlspecialchars(t('aide_common.retour_centre_aide')); ?>" aria-label="<?php echo htmlspecialchars(t('aide_common.retour_centre_aide')); ?>"><i class="fa-solid fa-house"></i></a>

<?php $breadcrumb_parent_label = t('aide_hub.breadcrumb'); $breadcrumb_parent_href = 'aide.php'; $breadcrumb_label = t('aide_kpi.breadcrumb_label'); include 'navbar.php'; ?>

<div class="main-content">

    <div class="crumb"><span><i class="fa-solid fa-house"></i> <?php echo htmlspecialchars(t('aide_common.centre_aide')); ?></span> <span>/</span> <span><?php echo htmlspecialchars(t('aide_kpi.breadcrumb_label')); ?></span></div>

    <div class="hero">
        <span class="hero-eyebrow"><i class="fa-solid fa-book-open"></i> <?php echo htmlspecialchars(t('aide_common.hero_eyebrow_standard')); ?></span>
        <h1><?php echo htmlspecialchars(t('aide_hub.tuile_kpi_titre')); ?></h1>
        <p class="tagline"><?php echo htmlspecialchars(t('aide_kpi.hero_tagline')); ?></p>
        <div class="hero-meta">
            <span class="hero-pill"><?php echo t('aide_kpi.pill_version'); ?></span>
            <span class="hero-pill"><?php echo t('aide_kpi.pill_updated'); ?></span>
            <span class="hero-pill"><?php echo t('aide_kpi.pill_ecrans'); ?></span>
            <span class="hero-pill"><i class="fa-solid fa-camera"></i> <?php echo htmlspecialchars(t('aide_common.pill_captures')); ?></span>
        </div>
    </div>

    <div class="layout">
        <nav class="toc-card" aria-label="<?php echo htmlspecialchars(t('aide_common.sommaire')); ?>">
            <input type="checkbox" id="toc-toggle" class="toc-toggle">
            <label for="toc-toggle" class="toc-label">
                <span><i class="fa-solid fa-list-ul"></i> <?php echo htmlspecialchars(t('aide_common.sommaire')); ?></span>
                <i class="fa-solid fa-chevron-down"></i>
            </label>
            <ul class="toc-list">
                <li><a href="#vue-ensemble"><i class="fa-solid fa-circle-info"></i> <?php echo htmlspecialchars(t('aide_kpi.toc_vue_ensemble')); ?></a></li>
                <li><a href="#filtres"><i class="fa-solid fa-filter"></i> <?php echo htmlspecialchars(t('aide_kpi.toc_filtres')); ?></a></li>
                <li><a href="#graphiques"><i class="fa-solid fa-chart-pie"></i> <?php echo htmlspecialchars(t('aide_kpi.toc_graphiques')); ?></a></li>
                <li><a href="#stats-tech"><i class="fa-solid fa-user-clock"></i> <?php echo htmlspecialchars(t('aide_kpi.toc_stats_tech')); ?></a></li>
                <li><a href="#export"><i class="fa-solid fa-file-pdf"></i> <?php echo htmlspecialchars(t('aide_kpi.toc_export')); ?></a></li>
                <li class="toc-sep"></li>
                <li><a href="#pieges"><i class="fa-solid fa-triangle-exclamation"></i> <?php echo htmlspecialchars(t('aide_kpi.toc_pieges')); ?></a></li>
                <li><a href="#glossaire"><i class="fa-solid fa-book"></i> <?php echo htmlspecialchars(t('aide_kpi.toc_glossaire')); ?></a></li>
            </ul>
        </nav>

        <main class="content">
            <div class="card">

                <section id="vue-ensemble" class="aide-sec">
                    <div class="sec-eyebrow"><i class="fa-solid fa-circle-info"></i><h2><?php echo htmlspecialchars(t('aide_kpi.toc_vue_ensemble')); ?></h2></div>
                    <p class="lede"><?php echo htmlspecialchars(t('aide_kpi.vue_ensemble_lede')); ?></p>

                    <div class="tbl-wrap">
                        <table>
                            <thead><tr><th><?php echo htmlspecialchars(t('aide_kpi.tbl_ecran')); ?></th><th><?php echo htmlspecialchars(t('aide_kpi.tbl_qui')); ?></th><th><?php echo htmlspecialchars(t('aide_kpi.tbl_periode')); ?></th></tr></thead>
                            <tbody>
                                <tr><td><?php echo htmlspecialchars(t('aide_kpi.row_kpi_ecran')); ?></td><td><span class="pill pill-admin"><span class="pill-dot"></span><?php echo htmlspecialchars(t('aide_common.pill_admin_uniquement')); ?></span></td><td><?php echo htmlspecialchars(t('aide_kpi.row_kpi_periode')); ?></td></tr>
                                <tr><td><?php echo htmlspecialchars(t('aide_kpi.row_stats_ecran')); ?></td><td><span class="pill pill-admin"><span class="pill-dot"></span><?php echo htmlspecialchars(t('aide_common.pill_admin_uniquement')); ?></span></td><td><?php echo htmlspecialchars(t('aide_kpi.row_stats_periode')); ?></td></tr>
                            </tbody>
                        </table>
                    </div>
                </section>

                <section id="filtres" class="aide-sec">
                    <div class="sec-eyebrow"><i class="fa-solid fa-filter"></i><h2><?php echo htmlspecialchars(t('aide_kpi.toc_filtres')); ?></h2></div>
                    <p class="lede"><?php echo htmlspecialchars(t('aide_kpi.filtres_lede')); ?></p>

                    <div class="shot">
                        <img src="img/aide/kpi_filtres.png" alt="<?php echo htmlspecialchars(t('aide_kpi.shot_filtres_alt')); ?>">
                        <div class="shot-cap"><i class="fa-solid fa-camera"></i> <?php echo htmlspecialchars(t('aide_kpi.shot_filtres_cap')); ?></div>
                    </div>

                    <div class="shot">
                        <img src="img/aide/kpi_cartes.png" alt="<?php echo htmlspecialchars(t('aide_kpi.shot_cartes_alt')); ?>">
                        <div class="shot-cap"><i class="fa-solid fa-camera"></i> <?php echo htmlspecialchars(t('aide_kpi.shot_cartes_cap')); ?></div>
                    </div>

                    <div class="tbl-wrap">
                        <table>
                            <thead><tr><th><?php echo htmlspecialchars(t('aide_kpi.tbl2_carte')); ?></th><th><?php echo htmlspecialchars(t('aide_kpi.tbl2_repr')); ?></th></tr></thead>
                            <tbody>
                                <tr><td><?php echo htmlspecialchars(t('aide_kpi.card_total_nom')); ?></td><td><?php echo htmlspecialchars(t('aide_kpi.card_total_desc')); ?></td></tr>
                                <tr><td><?php echo htmlspecialchars(t('aide_kpi.card_taux_nom')); ?></td><td><?php echo htmlspecialchars(t('aide_kpi.card_taux_desc')); ?></td></tr>
                                <tr><td><?php echo htmlspecialchars(t('aide_kpi.card_heures_nom')); ?></td><td><?php echo htmlspecialchars(t('aide_kpi.card_heures_desc')); ?></td></tr>
                                <tr><td><?php echo htmlspecialchars(t('aide_kpi.card_casse_nom')); ?></td><td><?php echo t('aide_kpi.card_casse_desc'); ?></td></tr>
                                <tr><td><?php echo htmlspecialchars(t('aide_kpi.card_panne_nom')); ?></td><td><?php echo t('aide_kpi.card_panne_desc'); ?></td></tr>
                            </tbody>
                        </table>
                    </div>

                    <p><?php echo htmlspecialchars(t('aide_kpi.filtres_footer_p')); ?></p>
                </section>

                <section id="graphiques" class="aide-sec">
                    <div class="sec-eyebrow"><i class="fa-solid fa-chart-pie"></i><h2><?php echo htmlspecialchars(t('aide_kpi.toc_graphiques')); ?></h2></div>

                    <div class="callout callout-lock">
                        <i class="fa-solid fa-camera"></i>
                        <div>
                            <p><?php echo t('aide_kpi.graphiques_callout'); ?></p>
                        </div>
                    </div>

                    <div class="shot">
                        <img src="img/aide/kpi_ratio_curatif_preventif.png" alt="<?php echo htmlspecialchars(t('aide_kpi.shot_ratio_alt')); ?>">
                        <div class="shot-cap"><i class="fa-solid fa-camera"></i> <?php echo htmlspecialchars(t('aide_kpi.shot_ratio_cap')); ?></div>
                    </div>

                    <p><?php echo htmlspecialchars(t('aide_kpi.graphiques_p1')); ?></p>

                    <h3><?php echo htmlspecialchars(t('aide_kpi.carrousel_h3')); ?></h3>
                    <p><?php echo t('aide_kpi.carrousel_intro'); ?></p>
                    <ul>
                        <li><?php echo t('aide_kpi.carrousel_li1'); ?></li>
                        <li><?php echo t('aide_kpi.carrousel_li2'); ?></li>
                        <li><?php echo t('aide_kpi.carrousel_li3'); ?></li>
                        <li><?php echo t('aide_kpi.carrousel_li4'); ?></li>
                    </ul>
                    <p><?php echo t('aide_kpi.carrousel_p2'); ?></p>

                    <div class="shot">
                        <img src="img/aide/kpi_panneaux_charge.png" alt="<?php echo htmlspecialchars(t('aide_kpi.shot_panneaux_alt')); ?>">
                        <div class="shot-cap"><i class="fa-solid fa-camera"></i> <?php echo htmlspecialchars(t('aide_kpi.shot_panneaux_cap')); ?></div>
                    </div>

                    <div class="shot">
                        <img src="img/aide/kpi_donut_recurrentes.png" alt="<?php echo htmlspecialchars(t('aide_kpi.shot_donut_alt')); ?>">
                        <div class="shot-cap"><i class="fa-solid fa-camera"></i> <?php echo htmlspecialchars(t('aide_kpi.shot_donut_cap')); ?></div>
                    </div>
                </section>

                <section id="stats-tech" class="aide-sec">
                    <div class="sec-eyebrow"><i class="fa-solid fa-user-clock"></i><h2><?php echo htmlspecialchars(t('aide_kpi.toc_stats_tech')); ?></h2></div>
                    <p class="lede"><?php echo t('aide_kpi.stats_tech_lede'); ?></p>

                    <div class="shot">
                        <img src="img/aide/stats_tech_ensemble.png" alt="<?php echo htmlspecialchars(t('aide_kpi.shot_stats_alt')); ?>">
                        <div class="shot-cap"><i class="fa-solid fa-camera"></i> <?php echo htmlspecialchars(t('aide_kpi.shot_stats_cap')); ?></div>
                    </div>

                    <ul>
                        <li><?php echo t('aide_kpi.stats_li1'); ?></li>
                        <li><?php echo t('aide_kpi.stats_li2'); ?></li>
                        <li><?php echo t('aide_kpi.stats_li3'); ?></li>
                    </ul>
                </section>

                <section id="export" class="aide-sec">
                    <div class="sec-eyebrow"><i class="fa-solid fa-file-pdf"></i><h2><?php echo htmlspecialchars(t('aide_kpi.toc_export')); ?></h2></div>
                    <p class="lede"><?php echo t('aide_kpi.export_p'); ?></p>
                </section>

                <section id="pieges" class="aide-sec">
                    <div class="sec-eyebrow"><i class="fa-solid fa-triangle-exclamation"></i><h2><?php echo htmlspecialchars(t('aide_kpi.pieges_h2')); ?></h2></div>
                    <p class="lede"><?php echo htmlspecialchars(t('aide_kpi.pieges_lede')); ?></p>

                    <div class="pitfalls">
                        <?php for ($i = 1; $i <= 8; $i++): ?>
                        <div class="pf">
                            <div class="pf-num"><?php echo $i; ?></div>
                            <div><h4><?php echo htmlspecialchars(t("aide_kpi.pf{$i}_title")); ?></h4>
                            <p><?php echo t("aide_kpi.pf{$i}_desc"); ?></p></div>
                        </div>
                        <?php endfor; ?>
                    </div>
                </section>

                <section id="glossaire" class="aide-sec" style="margin-bottom:0;">
                    <div class="sec-eyebrow"><i class="fa-solid fa-book"></i><h2><?php echo htmlspecialchars(t('aide_kpi.toc_glossaire')); ?></h2></div>
                    <dl class="gloss">
                        <div><dt><?php echo htmlspecialchars(t('aide_kpi.gloss_taux_dt')); ?></dt><dd><?php echo htmlspecialchars(t('aide_kpi.gloss_taux_dd')); ?></dd></div>
                        <div><dt><?php echo htmlspecialchars(t('aide_kpi.gloss_pointage_dt')); ?></dt><dd><?php echo htmlspecialchars(t('aide_kpi.gloss_pointage_dd')); ?></dd></div>
                        <div><dt><?php echo htmlspecialchars(t('aide_kpi.gloss_panne_dt')); ?></dt><dd><?php echo htmlspecialchars(t('aide_kpi.gloss_panne_dd')); ?></dd></div>
                        <div><dt><?php echo htmlspecialchars(t('aide_kpi.gloss_chantier_dt')); ?></dt><dd><?php echo htmlspecialchars(t('aide_kpi.gloss_chantier_dd')); ?></dd></div>
                    </dl>

                    <?php if ($is_admin): ?>
                    <details class="devnote">
                        <summary><i class="fa-solid fa-code"></i> <?php echo htmlspecialchars(t('aide_kpi.devnote_summary')); ?></summary>
                        <ul style="font-size:0.88rem; color:#5a6b7a; margin-top:8px;">
                            <li><?php echo t('aide_kpi.devnote_li1'); ?></li>
                            <li><?php echo t('aide_kpi.devnote_li2'); ?></li>
                        </ul>
                    </details>
                    <?php endif; ?>

                    <a href="aide.php" class="back-top"><i class="fa-solid fa-arrow-left"></i> <?php echo htmlspecialchars(t('aide_common.retour_centre_aide')); ?></a>
                </section>

            </div>
        </main>
    </div>
</div>

<script>
function openNav(e) { if (e) e.stopPropagation(); document.getElementById("mySidebar").style.width = "280px"; }
function closeNav() { document.getElementById("mySidebar").style.width = "0"; }
function checkCloseSidebar(event) { if (document.getElementById("mySidebar").style.width === "280px" && !document.getElementById("mySidebar").contains(event.target)) closeNav(); }
</script>

</body>
</html>
