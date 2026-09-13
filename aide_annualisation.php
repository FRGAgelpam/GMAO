<?php
require_once __DIR__ . '/session_init.php';

if (!isset($_SESSION['user']) || !isset($_SESSION['role']) || !in_array($_SESSION['role'], ['admin', 'technicien'])) {
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
<title><?php echo htmlspecialchars(t('aide_annualisation.page_title_tag')); ?></title>
<link rel="icon" type="image/png" href="img/logo.png">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Caveat:wght@600&family=Segoe+UI:wght@300;400;600&family=Montserrat:wght@400;700;800;900&display=swap" rel="stylesheet">
<style>
:root {
    --primary: #2c3e50; --accent: #3498db; --success: #2ecc71;
    --danger: #e74c3c; --gelpam-green: #2ecc71; --gelpam-orange: #f39c12;
    --ardo-blue: #005696; --neutral-dark: #34495e; --attente: #95a5a6;
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
.aide-sec h3 { font-family: 'Montserrat', sans-serif; font-size: 1rem; font-weight: 700; color: var(--primary); margin: 18px 0 8px; }
.aide-sec p { color: #405060; line-height: 1.6; font-size: 0.94rem; }
.aide-sec ul, .aide-sec ol { color: #405060; line-height: 1.65; font-size: 0.94rem; }
.aide-sec li { margin-bottom: 6px; }

.tbl-wrap { overflow-x: auto; border: 1px solid #e6e9ec; border-radius: 12px; margin-top: 14px; }
table { border-collapse: collapse; width: 100%; min-width: 560px; font-size: 0.88rem; }
thead th { text-align: left; font-family: 'Montserrat', sans-serif; font-weight: 700; font-size: 0.72rem; letter-spacing: 0.04em; text-transform: uppercase; color: #7f8c9a; background: #f7f9fb; padding: 11px 14px; border-bottom: 1px solid #e6e9ec; }
tbody td { padding: 11px 14px; border-bottom: 1px solid #eef1f3; color: #405060; vertical-align: top; }
tbody tr:last-child td { border-bottom: none; }
tbody td:first-child { font-weight: 700; color: var(--primary); white-space: nowrap; }

.steps { display: flex; flex-direction: column; gap: 12px; margin-top: 14px; }
.step { display: grid; grid-template-columns: 2.3rem minmax(0,1fr); gap: 14px; background: #fff; border: 1px solid #e6e9ec; border-radius: 12px; padding: 15px 18px; }
.step-num { width: 2.3rem; height: 2.3rem; border-radius: 50%; background: #eaf3fb; color: var(--accent); display: flex; align-items: center; justify-content: center; font-family: 'Montserrat', sans-serif; font-weight: 800; font-size: 1rem; }
.step h4 { font-family: 'Montserrat', sans-serif; font-size: 0.96rem; margin: 0 0 4px; color: var(--primary); }
.step p { margin: 0; font-size: 0.92rem; color: #47576a; }

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
.callout-law { background: #eef3fc; border-color: #cfe0f7; color: #1c4c8a; }
.callout-law i { color: var(--accent); }
.callout b { font-weight: 700; }

.pill { display: inline-flex; align-items: center; gap: 6px; font-weight: 700; font-size: 0.72rem; letter-spacing: 0.03em; text-transform: uppercase; padding: 5px 12px; border-radius: 999px; color: #fff; white-space: nowrap; }
.pill-admin { background: var(--gelpam-orange); }
.pill-all { background: var(--accent); }
.pill-dot { width: 6px; height: 6px; border-radius: 50%; background: rgba(255,255,255,0.85); }

.cases { display: grid; grid-template-columns: 1fr; gap: 14px; margin-top: 14px; }
@media (min-width: 700px) { .cases { grid-template-columns: 1fr 1fr; } }
.case-card { background: #fff; border: 1px solid #e6e9ec; border-radius: 12px; padding: 16px 18px; }
.case-card h4 { display: flex; align-items: center; gap: 8px; font-family: 'Montserrat', sans-serif; font-size: 0.95rem; margin: 0 0 8px; color: var(--primary); }
.case-card h4 i { color: var(--accent); width: 18px; }
.case-card p { font-size: 0.88rem; margin: 0; color: #47576a; }

.gloss { display: grid; grid-template-columns: 1fr; gap: 14px; margin-top: 12px; }
@media (min-width: 700px) { .gloss { grid-template-columns: 1fr 1fr; } }
.gloss dt { font-family: 'Montserrat', sans-serif; font-weight: 800; color: var(--accent); font-size: 0.86rem; }
.gloss dd { margin: 3px 0 0; color: #5a6b7a; font-size: 0.88rem; }

.formula-box { background: #f7f9fb; border: 1px solid #e6e9ec; border-radius: 12px; padding: 18px 20px; margin: 14px 0; font-family: 'Consolas', monospace; font-size: 0.92rem; color: var(--primary); line-height: 2; overflow-x: auto; }
.formula-box .op { color: var(--danger); font-weight: 700; }
.formula-box .res { color: var(--success); font-weight: 700; }

.year-donut-wrap { display: flex; align-items: center; gap: 28px; flex-wrap: wrap; margin-top: 14px; }
.year-donut { width: 168px; height: 168px; border-radius: 50%; position: relative; flex: none; box-shadow: 0 8px 20px -10px rgba(0,0,0,0.35); }
.year-donut-hole { position: absolute; inset: 24px; background: #fff; border-radius: 50%; display: flex; flex-direction: column; align-items: center; justify-content: center; box-shadow: inset 0 0 0 1px #e6e9ec; text-align: center; }
.year-donut-num { font-family: 'Montserrat', sans-serif; font-weight: 800; font-size: 1.7rem; color: var(--primary); line-height: 1; }
.year-donut-label { font-size: 0.68rem; color: #7f8c9a; margin-top: 4px; line-height: 1.3; }
.year-legend { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 11px; font-size: 0.88rem; color: #405060; }
.year-legend li { display: flex; align-items: center; gap: 9px; }
.year-legend b { color: var(--primary); }
.year-legend .dot { width: 12px; height: 12px; border-radius: 4px; flex: none; }
.year-legend-pct { color: #94a3b8; font-size: 0.8rem; }
.year-donut-note { display: flex; align-items: center; gap: 8px; color: #5a6b7a; font-size: 0.88rem; margin: 12px 0 0; }
.year-donut-note i { color: var(--accent); }
.year-donut-note b { color: var(--primary); }
@media (max-width: 480px) { .year-donut-wrap { justify-content: center; } }

.example-card { background: #fff; border: 1px solid #e6e9ec; border-left: 4px solid var(--accent); border-radius: 10px; padding: 16px 18px; margin-top: 14px; }
.example-card h4 { display: flex; align-items: center; gap: 8px; font-family: 'Montserrat', sans-serif; font-size: 0.94rem; margin: 0 0 8px; color: var(--primary); }
.example-card h4 i { color: var(--accent); }
.example-card p { margin: 0 0 6px; font-size: 0.9rem; }
.example-card .calc { background: #f7f9fb; border-radius: 8px; padding: 10px 12px; font-family: 'Consolas', monospace; font-size: 0.84rem; color: #2c3e50; margin-top: 8px; }
.example-card .verdict { display: inline-flex; align-items: center; gap: 6px; font-weight: 700; font-size: 0.84rem; margin-top: 8px; padding: 4px 10px; border-radius: 999px; }
.verdict-ok { background: #e4f7ea; color: #1f8a4c; }
.verdict-info { background: #eaf3fb; color: #1c6aa8; }

.faq-item { border: 1px solid #e6e9ec; border-radius: 10px; padding: 14px 16px; margin-top: 10px; }
.faq-item summary { cursor: pointer; font-weight: 700; color: var(--primary); font-size: 0.92rem; }
.faq-item summary::marker { color: var(--accent); }
.faq-item p { margin: 8px 0 0; }

.sources-list { list-style: none; margin: 10px 0 0; padding: 0; }
.sources-list li { padding: 8px 0; border-bottom: 1px solid #eef1f3; font-size: 0.86rem; }
.sources-list li:last-child { border-bottom: none; }
.sources-list a { color: var(--accent); text-decoration: none; font-weight: 600; }
.sources-list a:hover { text-decoration: underline; }

.shot { margin: 16px 0; border-radius: 12px; overflow: hidden; border: 1px solid #e6e9ec; box-shadow: 0 10px 24px -12px rgba(0,0,0,0.28); background: #fff; }
.shot img { display: block; width: 100%; height: auto; }
.shot-cap { padding: 8px 14px; font-size: 0.78rem; color: #7f8c9a; background: #f7f9fb; border-top: 1px solid #e6e9ec; display: flex; align-items: center; gap: 8px; }
.shot-cap i { color: var(--accent); }

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

<?php $breadcrumb_parent_label = t('aide_hub.breadcrumb'); $breadcrumb_parent_href = 'aide.php'; $breadcrumb_label = t('aide_hub.tuile_annualisation_titre'); include 'navbar.php'; ?>

<div class="main-content">

    <div class="crumb"><span><i class="fa-solid fa-house"></i> <?php echo htmlspecialchars(t('aide_common.centre_aide')); ?></span> <span>/</span> <span><?php echo htmlspecialchars(t('aide_hub.tuile_annualisation_titre')); ?></span></div>

    <div class="hero">
        <span class="hero-eyebrow"><i class="fa-solid fa-scale-balanced"></i> <?php echo htmlspecialchars(t('aide_common.hero_eyebrow_standard')); ?></span>
        <h1><?php echo htmlspecialchars(t('aide_annualisation.hero_h1')); ?></h1>
        <p class="tagline"><?php echo htmlspecialchars(t('aide_annualisation.hero_tagline')); ?></p>
        <div class="hero-meta">
            <span class="hero-pill"><?php echo t('aide_annualisation.pill_version'); ?></span>
            <span class="hero-pill"><?php echo t('aide_annualisation.pill_updated'); ?></span>
            <span class="hero-pill"><?php echo t('aide_annualisation.pill_ecrans'); ?></span>
            <span class="hero-pill"><i class="fa-solid fa-gavel"></i> <?php echo htmlspecialchars(t('aide_annualisation.pill_sourced')); ?></span>
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
                <li><a href="#vue-ensemble"><i class="fa-solid fa-circle-info"></i> <?php echo htmlspecialchars(t('aide_annualisation.toc_vue_ensemble')); ?></a></li>
                <li><a href="#saisir"><i class="fa-solid fa-calendar-plus"></i> <?php echo htmlspecialchars(t('aide_annualisation.toc_saisir')); ?></a></li>
                <li><a href="#formule-1607"><i class="fa-solid fa-calculator"></i> <?php echo htmlspecialchars(t('aide_annualisation.toc_formule')); ?></a></li>
                <li><a href="#traitement"><i class="fa-solid fa-table-list"></i> <?php echo htmlspecialchars(t('aide_annualisation.toc_traitement')); ?></a></li>
                <li><a href="#ajustements"><i class="fa-solid fa-sliders"></i> <?php echo htmlspecialchars(t('aide_annualisation.toc_ajustements')); ?></a></li>
                <li><a href="#exemples"><i class="fa-solid fa-lightbulb"></i> <?php echo htmlspecialchars(t('aide_annualisation.toc_exemples')); ?></a></li>
                <li><a href="#repere"><i class="fa-solid fa-gauge-high"></i> <?php echo htmlspecialchars(t('aide_annualisation.toc_repere')); ?></a></li>
                <li class="toc-sep"></li>
                <li><a href="#faq"><i class="fa-solid fa-circle-question"></i> <?php echo htmlspecialchars(t('aide_annualisation.toc_faq')); ?></a></li>
                <li><a href="#glossaire"><i class="fa-solid fa-book"></i> <?php echo htmlspecialchars(t('aide_annualisation.toc_glossaire')); ?></a></li>
                <li><a href="#sources"><i class="fa-solid fa-gavel"></i> <?php echo htmlspecialchars(t('aide_annualisation.toc_sources')); ?></a></li>
            </ul>
        </nav>

        <main class="content">
            <div class="card">

                <section id="vue-ensemble" class="aide-sec">
                    <div class="sec-eyebrow"><i class="fa-solid fa-circle-info"></i><h2><?php echo htmlspecialchars(t('aide_annualisation.toc_vue_ensemble')); ?></h2></div>
                    <p class="lede"><?php echo t('aide_annualisation.vue_lede'); ?></p>

                    <div class="tbl-wrap">
                        <table>
                            <thead><tr><th><?php echo htmlspecialchars(t('aide_annualisation.tbl1_th_ou')); ?></th><th><?php echo htmlspecialchars(t('aide_annualisation.tbl1_th_usage')); ?></th></tr></thead>
                            <tbody>
                                <tr><td><?php echo htmlspecialchars(t('aide_annualisation.row_planning_label')); ?></td><td><?php echo t('aide_annualisation.row_planning_desc'); ?></td></tr>
                                <tr><td><?php echo htmlspecialchars(t('aide_annualisation.row_planning_annuel_label')); ?></td><td><?php echo htmlspecialchars(t('aide_annualisation.row_planning_annuel_desc')); ?></td></tr>
                            </tbody>
                        </table>
                    </div>

                    <div class="callout callout-tip">
                        <i class="fa-solid fa-lightbulb"></i>
                        <div><p><?php echo htmlspecialchars(t('aide_annualisation.callout_tip_pas_calculer')); ?></p></div>
                    </div>
                </section>

                <section id="saisir" class="aide-sec">
                    <div class="sec-eyebrow"><i class="fa-solid fa-calendar-plus"></i><h2><?php echo htmlspecialchars(t('aide_annualisation.toc_saisir')); ?></h2></div>
                    <p class="lede"><?php echo htmlspecialchars(t('aide_annualisation.saisir_lede')); ?></p>

                    <div class="steps">
                        <div class="step">
                            <div class="step-num">1</div>
                            <div><h4><?php echo htmlspecialchars(t('aide_annualisation.s_step1_titre')); ?></h4>
                            <p><?php echo htmlspecialchars(t('aide_annualisation.s_step1_desc')); ?></p></div>
                        </div>
                        <div class="step">
                            <div class="step-num">2</div>
                            <div><h4><?php echo htmlspecialchars(t('aide_annualisation.s_step2_titre')); ?></h4>
                            <p><?php echo htmlspecialchars(t('aide_annualisation.s_step2_desc')); ?></p></div>
                        </div>
                        <div class="step">
                            <div class="step-num">3</div>
                            <div><h4><?php echo htmlspecialchars(t('aide_annualisation.s_step3_titre')); ?></h4>
                            <p><?php echo t('aide_annualisation.s_step3_desc'); ?></p></div>
                        </div>
                    </div>

                    <div class="callout callout-warn">
                        <i class="fa-solid fa-triangle-exclamation"></i>
                        <div><p><?php echo t('aide_annualisation.callout_warn_ferie_travaille'); ?></p></div>
                    </div>
                </section>

                <section id="formule-1607" class="aide-sec">
                    <div class="sec-eyebrow"><i class="fa-solid fa-calculator"></i><h2><?php echo htmlspecialchars(t('aide_annualisation.toc_formule')); ?></h2></div>
                    <p class="lede"><?php echo htmlspecialchars(t('aide_annualisation.formule_lede')); ?></p>

                    <div class="year-donut-wrap">
                        <div class="year-donut" style="background: conic-gradient(var(--attente) 0% 28.5%, var(--accent) 28.5% 35.3%, var(--gelpam-orange) 35.3% 37.5%, var(--success) 37.5% 100%);">
                            <div class="year-donut-hole">
                                <span class="year-donut-num"><?php echo htmlspecialchars(t('aide_annualisation.donut_num')); ?></span>
                                <span class="year-donut-label"><?php echo t('aide_annualisation.donut_label'); ?></span>
                            </div>
                        </div>
                        <ul class="year-legend">
                            <li><span class="dot" style="background:var(--attente);"></span> <?php echo htmlspecialchars(t('aide_annualisation.leg_weekends_label')); ?> <b>— <?php echo htmlspecialchars(t('aide_annualisation.leg_weekends_jours')); ?></b> <span class="year-legend-pct">(29%)</span></li>
                            <li><span class="dot" style="background:var(--accent);"></span> <?php echo htmlspecialchars(t('aide_annualisation.leg_cp_label')); ?> <b>— <?php echo htmlspecialchars(t('aide_annualisation.leg_cp_jours')); ?></b> <span class="year-legend-pct">(7%)</span></li>
                            <li><span class="dot" style="background:var(--gelpam-orange);"></span> <?php echo htmlspecialchars(t('aide_annualisation.leg_feries_label')); ?> <b>— <?php echo htmlspecialchars(t('aide_annualisation.leg_feries_jours')); ?></b> <span class="year-legend-pct">(2%)</span></li>
                            <li><span class="dot" style="background:var(--success);"></span> <?php echo htmlspecialchars(t('aide_annualisation.leg_travailles_label')); ?> <b>— <?php echo htmlspecialchars(t('aide_annualisation.leg_travailles_jours')); ?></b> <span class="year-legend-pct">(62%)</span></li>
                        </ul>
                    </div>
                    <p class="year-donut-note"><i class="fa-solid fa-arrow-right-long"></i> <?php echo t('aide_annualisation.donut_note'); ?></p>

                    <div class="formula-box">
<?php echo t('aide_annualisation.formula_box'); ?>
                    </div>

                    <p><?php echo t('aide_annualisation.formule_p2'); ?></p>

                    <div class="callout callout-law">
                        <i class="fa-solid fa-gavel"></i>
                        <div><p><?php echo t('aide_annualisation.callout_law_samedi_p1'); ?></p>
                        <p><?php echo t('aide_annualisation.callout_law_samedi_p2'); ?></p></div>
                    </div>
                </section>

                <section id="traitement" class="aide-sec">
                    <div class="sec-eyebrow"><i class="fa-solid fa-table-list"></i><h2><?php echo htmlspecialchars(t('aide_annualisation.toc_traitement')); ?></h2></div>
                    <p class="lede"><?php echo htmlspecialchars(t('aide_annualisation.traitement_lede')); ?></p>

                    <div class="tbl-wrap">
                        <table>
                            <thead><tr><th><?php echo htmlspecialchars(t('aide_annualisation.tbl2_th_type')); ?></th><th><?php echo htmlspecialchars(t('aide_annualisation.tbl2_th_total')); ?></th><th><?php echo htmlspecialchars(t('aide_annualisation.tbl2_th_du')); ?></th><th><?php echo htmlspecialchars(t('aide_annualisation.tbl2_th_pourquoi')); ?></th></tr></thead>
                            <tbody>
                                <tr><td><?php echo htmlspecialchars(t('aide_annualisation.row2_cp_type')); ?></td><td><?php echo t('aide_annualisation.row2_cp_total'); ?></td><td><?php echo htmlspecialchars(t('aide_annualisation.row2_cp_du')); ?></td><td><?php echo htmlspecialchars(t('aide_annualisation.row2_cp_pourquoi')); ?></td></tr>
                                <tr><td><?php echo htmlspecialchars(t('aide_annualisation.row2_demicp_type')); ?></td><td><?php echo t('aide_annualisation.row2_demicp_total'); ?></td><td><?php echo htmlspecialchars(t('aide_annualisation.row2_demicp_du')); ?></td><td><?php echo htmlspecialchars(t('aide_annualisation.row2_demicp_pourquoi')); ?></td></tr>
                                <tr><td><?php echo htmlspecialchars(t('aide_annualisation.row2_rtt_type')); ?></td><td><?php echo t('aide_annualisation.row2_rtt_total'); ?></td><td><?php echo t('aide_annualisation.row2_rtt_du'); ?></td><td><?php echo htmlspecialchars(t('aide_annualisation.row2_rtt_pourquoi')); ?></td></tr>
                                <tr><td><?php echo htmlspecialchars(t('aide_annualisation.row2_repos_type')); ?></td><td><?php echo t('aide_annualisation.row2_repos_total'); ?></td><td><?php echo htmlspecialchars(t('aide_annualisation.row2_repos_du')); ?></td><td><?php echo htmlspecialchars(t('aide_annualisation.row2_repos_pourquoi')); ?></td></tr>
                                <tr><td><?php echo t('aide_annualisation.row2_ferienontrav_type'); ?></td><td><?php echo htmlspecialchars(t('aide_annualisation.row2_ferienontrav_total')); ?></td><td><?php echo htmlspecialchars(t('aide_annualisation.row2_ferienontrav_du')); ?></td><td><?php echo htmlspecialchars(t('aide_annualisation.row2_ferienontrav_pourquoi')); ?></td></tr>
                                <tr><td><?php echo t('aide_annualisation.row2_ferietrav_type'); ?></td><td><?php echo t('aide_annualisation.row2_ferietrav_total'); ?></td><td><?php echo t('aide_annualisation.row2_ferietrav_du'); ?></td><td><?php echo htmlspecialchars(t('aide_annualisation.row2_ferietrav_pourquoi')); ?></td></tr>
                                <tr><td><?php echo htmlspecialchars(t('aide_annualisation.row2_maladie_type')); ?></td><td><?php echo t('aide_annualisation.row2_maladie_total'); ?></td><td><?php echo htmlspecialchars(t('aide_annualisation.row2_maladie_du')); ?></td><td><?php echo htmlspecialchars(t('aide_annualisation.row2_maladie_pourquoi')); ?></td></tr>
                            </tbody>
                        </table>
                    </div>

                    <div class="callout callout-law">
                        <i class="fa-solid fa-gavel"></i>
                        <div><p><?php echo t('aide_annualisation.callout_law_rtt_repos'); ?></p></div>
                    </div>

                    <div class="callout callout-tip">
                        <i class="fa-solid fa-circle-check"></i>
                        <div><p><?php echo t('aide_annualisation.callout_tip_logique'); ?></p></div>
                    </div>
                </section>

                <section id="ajustements" class="aide-sec">
                    <div class="sec-eyebrow"><i class="fa-solid fa-sliders"></i><h2><?php echo htmlspecialchars(t('aide_annualisation.toc_ajustements')); ?></h2></div>
                    <p class="lede"><?php echo t('aide_annualisation.ajustements_lede'); ?></p>

                    <div class="tbl-wrap">
                        <table>
                            <thead><tr><th><?php echo htmlspecialchars(t('aide_annualisation.tbl3_th_ajustement')); ?></th><th><?php echo htmlspecialchars(t('aide_annualisation.tbl3_th_effet')); ?></th><th><?php echo htmlspecialchars(t('aide_annualisation.tbl3_th_qui')); ?></th></tr></thead>
                            <tbody>
                                <tr><td><?php echo htmlspecialchars(t('aide_annualisation.row3_objectif_ajustement')); ?></td><td><?php echo htmlspecialchars(t('aide_annualisation.row3_objectif_effet')); ?></td><td><?php echo htmlspecialchars(t('aide_annualisation.row3_objectif_qui')); ?></td></tr>
                                <tr><td><?php echo htmlspecialchars(t('aide_annualisation.row3_fractionnement_ajustement')); ?></td><td><?php echo t('aide_annualisation.row3_fractionnement_effet'); ?></td><td><?php echo htmlspecialchars(t('aide_annualisation.row3_fractionnement_qui')); ?></td></tr>
                                <tr><td><?php echo htmlspecialchars(t('aide_annualisation.row3_48h_ajustement')); ?></td><td><?php echo t('aide_annualisation.row3_48h_effet'); ?></td><td><?php echo htmlspecialchars(t('aide_annualisation.row3_48h_qui')); ?></td></tr>
                            </tbody>
                        </table>
                    </div>

                    <div class="callout callout-law">
                        <i class="fa-solid fa-gavel"></i>
                        <div><p><?php echo t('aide_annualisation.callout_law_fractionnement_p1'); ?></p>
                        <p><?php echo t('aide_annualisation.callout_law_fractionnement_p2'); ?></p></div>
                    </div>

                    <div class="callout callout-tip">
                        <i class="fa-solid fa-triangle-exclamation"></i>
                        <div><p><?php echo t('aide_annualisation.callout_tip_plafond_cp'); ?></p></div>
                    </div>
                </section>

                <section id="exemples" class="aide-sec">
                    <div class="sec-eyebrow"><i class="fa-solid fa-lightbulb"></i><h2><?php echo htmlspecialchars(t('aide_annualisation.toc_exemples')); ?></h2></div>
                    <p class="lede"><?php echo htmlspecialchars(t('aide_annualisation.exemples_lede')); ?></p>

                    <div class="example-card">
                        <h4><i class="fa-solid fa-umbrella-beach"></i> <?php echo htmlspecialchars(t('aide_annualisation.ex1_titre')); ?></h4>
                        <p><?php echo htmlspecialchars(t('aide_annualisation.ex1_desc')); ?></p>
                        <div class="calc"><?php echo t('aide_annualisation.ex1_calc'); ?></div>
                        <span class="verdict verdict-ok"><i class="fa-solid fa-check"></i> <?php echo htmlspecialchars(t('aide_annualisation.ex1_verdict')); ?></span>
                    </div>

                    <div class="example-card">
                        <h4><i class="fa-solid fa-briefcase-medical"></i> <?php echo htmlspecialchars(t('aide_annualisation.ex2_titre')); ?></h4>
                        <p><?php echo htmlspecialchars(t('aide_annualisation.ex2_desc')); ?></p>
                        <div class="calc"><?php echo t('aide_annualisation.ex2_calc'); ?></div>
                        <span class="verdict verdict-ok"><i class="fa-solid fa-check"></i> <?php echo htmlspecialchars(t('aide_annualisation.ex2_verdict')); ?></span>
                    </div>

                    <div class="example-card">
                        <h4><i class="fa-solid fa-flag"></i> <?php echo htmlspecialchars(t('aide_annualisation.ex3_titre')); ?></h4>
                        <p><?php echo t('aide_annualisation.ex3_desc'); ?></p>
                        <div class="calc"><?php echo t('aide_annualisation.ex3_calc'); ?></div>
                        <span class="verdict verdict-info"><i class="fa-solid fa-arrow-up"></i> <?php echo htmlspecialchars(t('aide_annualisation.ex3_verdict')); ?></span>
                    </div>

                    <div class="example-card">
                        <h4><i class="fa-solid fa-gauge-high"></i> <?php echo htmlspecialchars(t('aide_annualisation.ex4_titre')); ?></h4>
                        <p><?php echo htmlspecialchars(t('aide_annualisation.ex4_desc')); ?></p>
                        <div class="calc"><?php echo t('aide_annualisation.ex4_calc'); ?></div>
                        <span class="verdict verdict-info"><i class="fa-solid fa-arrow-up"></i> <?php echo htmlspecialchars(t('aide_annualisation.ex4_verdict')); ?></span>
                    </div>
                </section>

                <section id="repere" class="aide-sec">
                    <div class="sec-eyebrow"><i class="fa-solid fa-gauge-high"></i><h2><?php echo htmlspecialchars(t('aide_annualisation.toc_repere')); ?></h2></div>
                    <p class="lede"><?php echo t('aide_annualisation.repere_lede'); ?></p>

                    <ul>
                        <li><?php echo htmlspecialchars(t('aide_annualisation.repere_li1')); ?></li>
                        <li><?php echo t('aide_annualisation.repere_li2'); ?></li>
                        <li><?php echo t('aide_annualisation.repere_li3'); ?></li>
                        <li><?php echo t('aide_annualisation.repere_li4'); ?></li>
                        <li><?php echo htmlspecialchars(t('aide_annualisation.repere_li5')); ?></li>
                    </ul>

                    <p><?php echo htmlspecialchars(t('aide_annualisation.repere_p_avance_retard')); ?></p>

                    <div class="callout callout-tip">
                        <i class="fa-solid fa-calendar-day"></i>
                        <div><p><?php echo t('aide_annualisation.callout_tip_badge_jour'); ?></p></div>
                    </div>
                </section>

                <section id="faq" class="aide-sec">
                    <div class="sec-eyebrow"><i class="fa-solid fa-circle-question"></i><h2><?php echo htmlspecialchars(t('aide_annualisation.toc_faq')); ?></h2></div>

                    <?php for ($i = 1; $i <= 5; $i++): ?>
                    <details class="faq-item">
                        <summary><?php echo htmlspecialchars(t("aide_annualisation.faq{$i}_q")); ?></summary>
                        <p><?php echo t("aide_annualisation.faq{$i}_a"); ?></p>
                    </details>
                    <?php endfor; ?>
                </section>

                <section id="glossaire" class="aide-sec">
                    <div class="sec-eyebrow"><i class="fa-solid fa-book"></i><h2><?php echo htmlspecialchars(t('aide_annualisation.toc_glossaire')); ?></h2></div>
                    <dl class="gloss">
                        <?php for ($i = 1; $i <= 9; $i++): ?>
                        <div><dt><?php echo htmlspecialchars(t("aide_annualisation.gloss{$i}_dt")); ?></dt><dd><?php echo htmlspecialchars(t("aide_annualisation.gloss{$i}_dd")); ?></dd></div>
                        <?php endfor; ?>
                    </dl>
                </section>

                <section id="sources" class="aide-sec" style="margin-bottom:0;">
                    <div class="sec-eyebrow"><i class="fa-solid fa-gavel"></i><h2><?php echo htmlspecialchars(t('aide_annualisation.toc_sources')); ?></h2></div>
                    <p class="lede"><?php echo htmlspecialchars(t('aide_annualisation.sources_lede')); ?></p>
                    <ul class="sources-list">
                        <li><a href="https://blog.barthelemy-avocats.com/1607-heures-annualisation/" target="_blank" rel="noopener"><?php echo htmlspecialchars(t('aide_annualisation.source1')); ?></a></li>
                        <li><a href="https://factorial.fr/blog/rtt-conges-payes-differences/" target="_blank" rel="noopener"><?php echo htmlspecialchars(t('aide_annualisation.source2')); ?></a></li>
                        <li><a href="https://www.hdv-avocats.fr/absence-maladie-heures-supplementaires-organisation-annuelle-du-travail/" target="_blank" rel="noopener"><?php echo htmlspecialchars(t('aide_annualisation.source3')); ?></a></li>
                        <li><a href="https://blog.barthelemy-avocats.com/arret-maladie-et-annualisation/" target="_blank" rel="noopener"><?php echo htmlspecialchars(t('aide_annualisation.source4')); ?></a></li>
                    </ul>

                    <div class="callout callout-lock">
                        <i class="fa-solid fa-circle-info"></i>
                        <div><p><?php echo htmlspecialchars(t('aide_annualisation.callout_lock_pedagogique')); ?></p></div>
                    </div>

                    <?php if ($is_admin): ?>
                    <details class="devnote">
                        <summary><i class="fa-solid fa-code"></i> <?php echo htmlspecialchars(t('aide_common.devnote_summary')); ?></summary>
                        <ul style="font-size:0.88rem; color:#5a6b7a; margin-top:8px;">
                            <?php for ($i = 1; $i <= 7; $i++): ?>
                            <li><?php echo t("aide_annualisation.devnote_li{$i}"); ?></li>
                            <?php endfor; ?>
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
