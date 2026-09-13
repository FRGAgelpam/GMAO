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
<title><?php echo htmlspecialchars(t('aide_ot.page_title_tag')); ?></title>
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
.aide-sec h3 { font-family: 'Montserrat', sans-serif; font-size: 1rem; font-weight: 700; color: var(--primary); margin: 0 0 6px; }
.aide-sec p { color: #405060; line-height: 1.6; font-size: 0.94rem; }
.aide-sec ul { color: #405060; line-height: 1.65; font-size: 0.94rem; }
.aide-sec li { margin-bottom: 6px; }

.journey { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 10px; background: #f7f9fb; border: 1px solid #e6e9ec; border-radius: 12px; padding: 16px 18px; margin: 18px 0 6px; }
.journey-step { display: flex; align-items: center; gap: 10px; font-family: 'Montserrat', sans-serif; font-weight: 700; font-size: 0.88rem; color: var(--primary); }
.journey-step i { color: var(--accent); font-size: 1.1rem; }
.journey .sep { color: #c3cdd6; font-size: 1rem; }

.grid-2 { display: grid; grid-template-columns: 1fr; gap: 16px; margin-top: 14px; }
@media (min-width: 700px) { .grid-2 { grid-template-columns: 1fr 1fr; } }
.route-card { border: 1px solid #e6e9ec; border-radius: 12px; padding: 18px 20px; background: #fff; border-top: 4px solid var(--accent); }
.route-card.alt { border-top-color: var(--gelpam-orange); }
.route-tag { display: inline-block; font-size: 0.68rem; font-weight: 700; letter-spacing: 0.05em; text-transform: uppercase; color: #8a97a3; background: #f1f4f6; border-radius: 6px; padding: 3px 9px; margin-bottom: 10px; }
.route-card h3 { display: flex; align-items: center; gap: 8px; }
.route-card h3 i { color: var(--accent); }
.route-card.alt h3 i { color: var(--gelpam-orange); }

.tbl-wrap { overflow-x: auto; border: 1px solid #e6e9ec; border-radius: 12px; margin-top: 14px; }
table { border-collapse: collapse; width: 100%; min-width: 560px; font-size: 0.88rem; }
thead th { text-align: left; font-family: 'Montserrat', sans-serif; font-weight: 700; font-size: 0.72rem; letter-spacing: 0.04em; text-transform: uppercase; color: #7f8c9a; background: #f7f9fb; padding: 11px 14px; border-bottom: 1px solid #e6e9ec; }
tbody td { padding: 11px 14px; border-bottom: 1px solid #eef1f3; color: #405060; vertical-align: top; }
tbody tr:last-child td { border-bottom: none; }
tbody td:first-child { font-weight: 700; color: var(--primary); white-space: nowrap; }
.req-soft { color: var(--gelpam-orange); font-weight: 700; }
.req-auto { color: #9aa7b2; }

.pill { display: inline-flex; align-items: center; gap: 6px; font-weight: 700; font-size: 0.72rem; letter-spacing: 0.03em; text-transform: uppercase; padding: 5px 12px; border-radius: 999px; color: #fff; white-space: nowrap; }
.pill-attente { background: var(--attente); }
.pill-afaire { background: var(--gelpam-orange); }
.pill-urgent { background: var(--danger); }
.pill-cours { background: var(--accent); }
.pill-termine { background: var(--gelpam-green); }
.pill-refuse { background: var(--danger); }
.pill-dot { width: 6px; height: 6px; border-radius: 50%; background: rgba(255,255,255,0.85); }
.pulse-badge { animation: pulse-badge 1.5s ease-in-out infinite; }
@keyframes pulse-badge { 0%,100% { box-shadow: 0 0 0 0 rgba(231,76,60,0.55);} 50% { box-shadow: 0 0 0 6px rgba(231,76,60,0);} }

.cycle { display: flex; flex-wrap: wrap; gap: 14px 0; background: #f7f9fb; border: 1px solid #e6e9ec; border-radius: 14px; padding: 22px 18px; margin-top: 16px; align-items: flex-start; }
.cy-step { display: flex; flex-direction: column; align-items: center; text-align: center; width: 168px; gap: 8px; }
.cy-trig { font-size: 0.76rem; color: #647486; line-height: 1.4; min-height: 2.8em; }
.cy-who { display: block; font-size: 0.66rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.04em; color: #9aa7b2; margin-top: 3px; }
.cy-arrow { display: flex; align-items: flex-start; justify-content: center; width: 30px; flex: none; color: #c3cdd6; padding-top: 8px; }
@media (max-width: 840px) { .cy-arrow { display: none; } .cycle { flex-direction: column; } .cy-step { width: 100%; flex-direction: row; text-align: left; justify-content: flex-start; } }

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
.callout b { font-weight: 700; }

.cases { display: grid; grid-template-columns: 1fr; gap: 14px; margin-top: 14px; }
@media (min-width: 700px) { .cases { grid-template-columns: 1fr 1fr; } }
.case-card { background: #fff; border: 1px solid #e6e9ec; border-radius: 12px; padding: 16px 18px; }
.case-card h4 { display: flex; align-items: center; gap: 8px; font-family: 'Montserrat', sans-serif; font-size: 0.95rem; margin: 0 0 8px; color: var(--primary); }
.case-card h4 i { color: var(--accent); width: 18px; }
.case-card p { font-size: 0.88rem; margin: 0; color: #47576a; }

.yn { display: inline-flex; align-items: center; gap: 6px; font-size: 0.85rem; }
.yn.y { color: #1f8a4c; font-weight: 700; }
.yn.n { color: #8a97a3; }

.gloss { display: grid; grid-template-columns: 1fr; gap: 14px; margin-top: 12px; }
@media (min-width: 700px) { .gloss { grid-template-columns: 1fr 1fr; } }
.gloss dt { font-family: 'Montserrat', sans-serif; font-weight: 800; color: var(--accent); font-size: 0.86rem; }
.gloss dd { margin: 3px 0 0; color: #5a6b7a; font-size: 0.88rem; }

.roadmap { list-style: none; margin: 14px 0 0; padding: 0; display: flex; flex-direction: column; gap: 8px; }
.roadmap li { display: flex; align-items: center; gap: 10px; background: #fff; border: 1px solid #e6e9ec; border-radius: 10px; padding: 10px 14px; font-size: 0.88rem; color: #405060; }
.stat { font-family: 'Montserrat', sans-serif; font-size: 0.65rem; font-weight: 800; text-transform: uppercase; letter-spacing: 0.04em; padding: 4px 9px; border-radius: 6px; flex: none; }
.stat-done { background: #e4f7ea; color: #1f8a4c; }
.stat-next { background: #fdf1e0; color: #b9720c; }

.devnote { background: #f7f9fb; border: 1px dashed #cdd5db; border-radius: 12px; padding: 16px 18px; margin-top: 20px; }
.devnote summary { cursor: pointer; font-family: 'Montserrat', sans-serif; font-weight: 700; color: var(--primary); font-size: 0.92rem; }
.devnote ul { margin-top: 10px; }
.devnote code, .aide-sec code { font-family: 'Consolas', monospace; background: #eef1f3; border-radius: 4px; padding: 2px 6px; font-size: 0.86em; color: #2c3e50; }

.shot { margin: 16px 0; border-radius: 12px; overflow: hidden; border: 1px solid #e6e9ec; box-shadow: 0 10px 24px -12px rgba(0,0,0,0.28); background: #fff; }
.shot img { display: block; width: 100%; height: auto; }
.shot-cap { padding: 8px 14px; font-size: 0.78rem; color: #7f8c9a; background: #f7f9fb; border-top: 1px solid #e6e9ec; display: flex; align-items: center; gap: 8px; }
.shot-cap i { color: var(--accent); }
.shot-row { display: grid; grid-template-columns: 1fr; gap: 14px; }
@media (min-width: 780px) { .shot-row.two { grid-template-columns: 1fr 1fr; } }

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

<?php $breadcrumb_parent_label = t('aide_hub.breadcrumb'); $breadcrumb_parent_href = 'aide.php'; $breadcrumb_label = t('aide_hub.tuile_ot_titre'); include 'navbar.php'; ?>

<div class="main-content">

    <div class="crumb"><span><i class="fa-solid fa-house"></i> <?php echo htmlspecialchars(t('aide_common.centre_aide')); ?></span> <span>/</span> <span><?php echo htmlspecialchars(t('aide_hub.tuile_ot_titre')); ?></span></div>

    <div class="hero">
        <span class="hero-eyebrow"><i class="fa-solid fa-book-open"></i> <?php echo htmlspecialchars(t('aide_common.hero_eyebrow_standard')); ?></span>
        <h1><?php echo htmlspecialchars(t('aide_hub.tuile_ot_titre')); ?></h1>
        <p class="tagline"><?php echo htmlspecialchars(t('aide_ot.hero_tagline')); ?></p>
        <div class="hero-meta">
            <span class="hero-pill"><?php echo t('aide_ot.pill_version'); ?></span>
            <span class="hero-pill"><?php echo t('aide_ot.pill_updated'); ?></span>
            <span class="hero-pill"><?php echo t('aide_ot.pill_ecrans'); ?></span>
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
                <li><a href="#vue-ensemble"><i class="fa-solid fa-circle-info"></i> <?php echo htmlspecialchars(t('aide_ot.toc_vue_ensemble')); ?></a></li>
                <li><a href="#portes"><i class="fa-solid fa-code-branch"></i> <?php echo htmlspecialchars(t('aide_ot.toc_portes')); ?></a></li>
                <li><a href="#formulaire"><i class="fa-solid fa-pen-to-square"></i> <?php echo htmlspecialchars(t('aide_ot.toc_formulaire')); ?></a></li>
                <li><a href="#assistant-intelligent"><i class="fa-solid fa-wand-magic-sparkles"></i> <?php echo htmlspecialchars(t('aide_ot.toc_assistant_intelligent')); ?></a></li>
                <li><a href="#cycle"><i class="fa-solid fa-rotate"></i> <?php echo htmlspecialchars(t('aide_ot.toc_cycle')); ?></a></li>
                <li><a href="#prise-en-charge"><i class="fa-solid fa-hand-pointer"></i> <?php echo htmlspecialchars(t('aide_ot.toc_prise_en_charge')); ?></a></li>
                <li><a href="#cloture"><i class="fa-solid fa-clipboard-check"></i> <?php echo htmlspecialchars(t('aide_ot.toc_cloture')); ?></a></li>
                <li><a href="#cas-particuliers"><i class="fa-solid fa-layer-group"></i> <?php echo htmlspecialchars(t('aide_ot.toc_cas_particuliers')); ?></a></li>
                <li><a href="#droits"><i class="fa-solid fa-shield-halved"></i> <?php echo htmlspecialchars(t('aide_ot.toc_droits')); ?></a></li>
                <li class="toc-sep"></li>
                <li><a href="#astuces"><i class="fa-solid fa-lightbulb"></i> <?php echo htmlspecialchars(t('aide_ot.toc_astuces')); ?></a></li>
                <li><a href="#glossaire"><i class="fa-solid fa-book"></i> <?php echo htmlspecialchars(t('aide_ot.toc_glossaire')); ?></a></li>
                <li><a href="#roadmap"><i class="fa-solid fa-road"></i> <?php echo htmlspecialchars(t('aide_common.roadmap_title')); ?></a></li>
            </ul>
        </nav>

        <main class="content">
            <div class="card">

                <section id="vue-ensemble" class="aide-sec">
                    <div class="sec-eyebrow"><i class="fa-solid fa-circle-info"></i><h2><?php echo htmlspecialchars(t('aide_ot.toc_vue_ensemble')); ?></h2></div>
                    <p class="lede"><?php echo t('aide_ot.vue_ensemble_lede'); ?></p>

                    <div class="journey">
                        <div class="journey-step"><i class="fa-solid fa-pen-to-square"></i> <?php echo htmlspecialchars(t('aide_ot.journey_creer')); ?></div>
                        <span class="sep"><i class="fa-solid fa-arrow-right-long"></i></span>
                        <div class="journey-step"><i class="fa-solid fa-hand-pointer"></i> <?php echo htmlspecialchars(t('aide_ot.journey_prendre')); ?></div>
                        <span class="sep"><i class="fa-solid fa-arrow-right-long"></i></span>
                        <div class="journey-step"><i class="fa-solid fa-screwdriver-wrench"></i> <?php echo htmlspecialchars(t('aide_ot.journey_intervenir')); ?></div>
                        <span class="sep"><i class="fa-solid fa-arrow-right-long"></i></span>
                        <div class="journey-step"><i class="fa-solid fa-clipboard-check"></i> <?php echo htmlspecialchars(t('aide_ot.journey_cloturer')); ?></div>
                    </div>

                    <div class="shot">
                        <img src="img/aide/ot_cartes_techniciens.png" alt="<?php echo htmlspecialchars(t('aide_ot.shot_cartes_alt')); ?>">
                        <div class="shot-cap"><i class="fa-solid fa-camera"></i> <?php echo htmlspecialchars(t('aide_ot.shot_cartes_cap')); ?></div>
                    </div>

                    <div class="callout callout-tip">
                        <i class="fa-solid fa-circle-info"></i>
                        <div><p><?php echo t('aide_ot.callout_numero_bi'); ?></p></div>
                    </div>
                </section>

                <section id="portes" class="aide-sec">
                    <div class="sec-eyebrow"><i class="fa-solid fa-code-branch"></i><h2><?php echo htmlspecialchars(t('aide_ot.toc_portes')); ?></h2></div>
                    <p class="lede"><?php echo htmlspecialchars(t('aide_ot.portes_lede')); ?></p>

                    <div class="grid-2">
                        <div class="route-card">
                            <span class="route-tag"><?php echo htmlspecialchars(t('aide_ot.route1_tag')); ?></span>
                            <h3><i class="fa-solid fa-pen-to-square"></i> <?php echo htmlspecialchars(t('aide_ot.route1_titre')); ?></h3>
                            <p><?php echo t('aide_ot.route1_p1'); ?></p>
                            <p><?php echo t('aide_ot.route1_p2'); ?></p>
                        </div>
                        <div class="route-card alt">
                            <span class="route-tag"><?php echo htmlspecialchars(t('aide_ot.route2_tag')); ?></span>
                            <h3><i class="fa-solid fa-comments"></i> <?php echo htmlspecialchars(t('aide_ot.route2_titre')); ?></h3>
                            <p><?php echo t('aide_ot.route2_p1'); ?></p>
                            <p><?php echo t('aide_ot.route2_p2'); ?></p>
                        </div>
                    </div>

                    <div class="shot">
                        <img src="img/aide/ot_bandeau_validation.png" alt="<?php echo htmlspecialchars(t('aide_ot.shot_bandeau_alt')); ?>">
                        <div class="shot-cap"><i class="fa-solid fa-camera"></i> <?php echo htmlspecialchars(t('aide_ot.shot_bandeau_cap')); ?></div>
                    </div>
                    <div class="shot">
                        <img src="img/aide/ot_sas_validation.png" alt="<?php echo htmlspecialchars(t('aide_ot.shot_sas_alt')); ?>">
                        <div class="shot-cap"><i class="fa-solid fa-camera"></i> <?php echo htmlspecialchars(t('aide_ot.shot_sas_cap')); ?></div>
                    </div>

                    <div class="callout callout-tip">
                        <i class="fa-solid fa-camera"></i>
                        <div><p><?php echo t('aide_ot.callout_photos'); ?></p></div>
                    </div>

                    <div class="callout callout-tip">
                        <i class="fa-solid fa-mobile-screen-button"></i>
                        <div><p><?php echo t('aide_ot.callout_mobile'); ?></p></div>
                    </div>

                    <div class="callout callout-tip">
                        <i class="fa-solid fa-circle-info"></i>
                        <div><p><?php echo t('aide_ot.callout_refuser'); ?></p></div>
                    </div>

                    <div class="callout callout-tip">
                        <i class="fa-solid fa-recycle"></i>
                        <div><p><?php echo t('aide_ot.callout_vers_preventif'); ?></p></div>
                    </div>

                    <div class="callout callout-warn">
                        <i class="fa-solid fa-triangle-exclamation"></i>
                        <div><p><?php echo t('aide_ot.callout_warn_etiquette'); ?></p></div>
                    </div>
                </section>

                <section id="formulaire" class="aide-sec">
                    <div class="sec-eyebrow"><i class="fa-solid fa-pen-to-square"></i><h2><?php echo htmlspecialchars(t('aide_ot.toc_formulaire')); ?></h2></div>
                    <p class="lede"><?php echo t('aide_ot.formulaire_lede'); ?></p>

                    <div class="callout callout-lock">
                        <i class="fa-solid fa-lock"></i>
                        <div><p><?php echo t('aide_ot.callout_lock_assistant'); ?></p></div>
                    </div>

                    <div class="steps">
                        <?php for ($i = 1; $i <= 4; $i++): ?>
                        <div class="step">
                            <div class="step-num"><?php echo $i; ?></div>
                            <div><h4><?php echo htmlspecialchars(t("aide_ot.step{$i}_titre")); ?></h4>
                            <p><?php echo htmlspecialchars(t("aide_ot.step{$i}_desc")); ?></p></div>
                        </div>
                        <?php endfor; ?>
                    </div>

                    <div class="shot-row">
                        <?php for ($i = 1; $i <= 4; $i++): ?>
                        <div class="shot">
                            <img src="img/aide/ot_wizard_etape<?php echo $i; ?>.png" alt="<?php echo htmlspecialchars(t("aide_ot.shot_etape{$i}_alt")); ?>">
                            <div class="shot-cap"><i class="fa-solid fa-camera"></i> <?php echo htmlspecialchars(t("aide_ot.shot_etape{$i}_cap")); ?></div>
                        </div>
                        <?php endfor; ?>
                    </div>

                    <div class="tbl-wrap">
                        <table>
                            <thead><tr><th><?php echo htmlspecialchars(t('aide_ot.tbl_th_champ')); ?></th><th><?php echo htmlspecialchars(t('aide_ot.tbl_th_etape')); ?></th><th><?php echo htmlspecialchars(t('aide_ot.tbl_th_description')); ?></th><th><?php echo htmlspecialchars(t('aide_ot.tbl_th_obligatoire')); ?></th></tr></thead>
                            <tbody>
                                <?php
                                $rows = [
                                    [1, 1, 'auto'],
                                    [2, 1, 'si_besoin'],
                                    [3, 1, 'si_besoin'],
                                    [4, 2, 'conseille'],
                                    [5, 3, 'conseille'],
                                    [6, 3, 'conseille'],
                                    [7, 3, 'conseille'],
                                    [8, 3, 'si_besoin'],
                                    [9, 3, 'si_besoin'],
                                    [10, 3, 'conseille'],
                                ];
                                foreach ($rows as [$n, $etape, $req]):
                                    $reqClass = $req === 'conseille' ? 'req-soft' : 'req-auto';
                                    $reqKey = $req === 'auto' ? 'aide_ot.req_auto' : ($req === 'si_besoin' ? 'aide_ot.req_si_besoin' : 'aide_ot.req_conseille');
                                ?>
                                <tr><td><?php echo htmlspecialchars(t("aide_ot.row{$n}_champ")); ?></td><td><?php echo $etape; ?></td><td><?php echo htmlspecialchars(t("aide_ot.row{$n}_desc")); ?></td><td><span class="<?php echo $reqClass; ?>"><?php echo htmlspecialchars(t($reqKey)); ?></span></td></tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <div class="callout callout-warn">
                        <i class="fa-solid fa-triangle-exclamation"></i>
                        <div><p><?php echo t('aide_ot.callout_warn_champs'); ?></p></div>
                    </div>

                    <div class="callout callout-tip">
                        <i class="fa-solid fa-circle-question"></i>
                        <div><p><?php echo t('aide_ot.callout_tip_bulle'); ?></p></div>
                    </div>
                </section>

                <section id="assistant-intelligent" class="aide-sec">
                    <div class="sec-eyebrow"><i class="fa-solid fa-wand-magic-sparkles"></i><h2><?php echo htmlspecialchars(t('aide_ot.toc_assistant_intelligent')); ?></h2></div>
                    <p class="lede"><?php echo htmlspecialchars(t('aide_ot.assistant_intelligent_lede')); ?></p>

                    <div class="steps">
                        <div class="step">
                            <div class="step-num"><i class="fa-solid fa-location-dot"></i></div>
                            <div><h4><?php echo htmlspecialchars(t('aide_ot.step_historique_titre')); ?></h4>
                            <p><?php echo htmlspecialchars(t('aide_ot.step_historique_desc')); ?></p></div>
                        </div>
                        <div class="step">
                            <div class="step-num"><i class="fa-solid fa-screwdriver-wrench"></i></div>
                            <div><h4><?php echo htmlspecialchars(t('aide_ot.step_similaires_titre')); ?></h4>
                            <p><?php echo htmlspecialchars(t('aide_ot.step_similaires_desc')); ?></p></div>
                        </div>
                    </div>

                    <div class="callout callout-tip">
                        <i class="fa-solid fa-circle-info"></i>
                        <div><p><?php echo t('aide_ot.callout_tip_clic_suggestion'); ?></p></div>
                    </div>

                    <div class="callout callout-warn">
                        <i class="fa-solid fa-triangle-exclamation"></i>
                        <div><p><?php echo t('aide_ot.callout_warn_aide_pas_ia'); ?></p></div>
                    </div>
                </section>

                <section id="cycle" class="aide-sec">
                    <div class="sec-eyebrow"><i class="fa-solid fa-rotate"></i><h2><?php echo htmlspecialchars(t('aide_ot.toc_cycle')); ?></h2></div>
                    <p class="lede"><?php echo htmlspecialchars(t('aide_ot.cycle_lede')); ?></p>

                    <div class="cycle">
                        <div class="cy-step">
                            <span class="pill pill-attente"><span class="pill-dot"></span><?php echo htmlspecialchars(t('aide_ot.pill_en_attente')); ?></span>
                            <div class="cy-trig"><?php echo htmlspecialchars(t('aide_ot.cy_attente_trig')); ?><span class="cy-who"><?php echo htmlspecialchars(t('aide_ot.cy_attente_who')); ?></span></div>
                        </div>
                        <div class="cy-arrow"><i class="fa-solid fa-arrow-right-long"></i></div>
                        <div class="cy-step">
                            <span class="pill pill-afaire"><span class="pill-dot"></span><?php echo htmlspecialchars(t('aide_ot.pill_a_faire')); ?></span>
                            <div class="cy-trig"><?php echo htmlspecialchars(t('aide_ot.cy_afaire_trig')); ?><span class="cy-who"><?php echo htmlspecialchars(t('aide_ot.cy_afaire_who')); ?></span></div>
                        </div>
                        <div class="cy-arrow"><i class="fa-solid fa-arrow-right-long"></i></div>
                        <div class="cy-step">
                            <span class="pill pill-cours"><span class="pill-dot"></span><?php echo htmlspecialchars(t('aide_ot.pill_en_cours')); ?></span>
                            <div class="cy-trig"><?php echo htmlspecialchars(t('aide_ot.cy_cours_trig')); ?><span class="cy-who"><?php echo htmlspecialchars(t('aide_ot.cy_cours_who')); ?></span></div>
                        </div>
                        <div class="cy-arrow"><i class="fa-solid fa-arrow-right-long"></i></div>
                        <div class="cy-step">
                            <span class="pill pill-termine"><span class="pill-dot"></span><?php echo htmlspecialchars(t('aide_ot.pill_terminee')); ?></span>
                            <div class="cy-trig"><?php echo htmlspecialchars(t('aide_ot.cy_termine_trig')); ?><span class="cy-who"><?php echo htmlspecialchars(t('aide_ot.cy_termine_who')); ?></span></div>
                        </div>
                    </div>

                    <div class="shot">
                        <img src="img/aide/ot_tableau_statuts.png" alt="<?php echo htmlspecialchars(t('aide_ot.shot_tableau_alt')); ?>">
                        <div class="shot-cap"><i class="fa-solid fa-camera"></i> <?php echo htmlspecialchars(t('aide_ot.shot_tableau_cap')); ?></div>
                    </div>

                    <div class="callout callout-lock">
                        <i class="fa-solid fa-lock"></i>
                        <div><p><?php echo t('aide_ot.callout_lock_reouverture'); ?></p></div>
                    </div>

                    <div class="callout callout-tip">
                        <i class="fa-solid fa-bell"></i>
                        <div><p><?php echo t('aide_ot.callout_tip_urgent'); ?></p></div>
                    </div>
                </section>

                <section id="prise-en-charge" class="aide-sec">
                    <div class="sec-eyebrow"><i class="fa-solid fa-hand-pointer"></i><h2><?php echo htmlspecialchars(t('aide_ot.toc_prise_en_charge')); ?></h2></div>
                    <p class="lede"><?php echo htmlspecialchars(t('aide_ot.prise_en_charge_lede')); ?></p>

                    <div class="steps">
                        <div class="step">
                            <div class="step-num">1</div>
                            <div><h4><?php echo htmlspecialchars(t('aide_ot.pec_step1_titre')); ?></h4>
                            <p><?php echo htmlspecialchars(t('aide_ot.pec_step1_desc')); ?></p></div>
                        </div>
                        <div class="step">
                            <div class="step-num">2</div>
                            <div><h4><?php echo htmlspecialchars(t('aide_ot.pec_step2_titre')); ?></h4>
                            <p><?php echo htmlspecialchars(t('aide_ot.pec_step2_desc')); ?></p></div>
                        </div>
                        <div class="step">
                            <div class="step-num">3</div>
                            <div><h4><?php echo htmlspecialchars(t('aide_ot.pec_step3_titre')); ?></h4>
                            <p><?php echo t('aide_ot.pec_step3_desc'); ?></p></div>
                        </div>
                    </div>

                    <div class="callout callout-warn">
                        <i class="fa-solid fa-triangle-exclamation"></i>
                        <div><p><?php echo t('aide_ot.callout_warn_pec'); ?></p></div>
                    </div>
                </section>

                <section id="cloture" class="aide-sec">
                    <div class="sec-eyebrow"><i class="fa-solid fa-clipboard-check"></i><h2><?php echo htmlspecialchars(t('aide_ot.toc_cloture')); ?></h2></div>
                    <p class="lede"><?php echo htmlspecialchars(t('aide_ot.cloture_lede')); ?></p>

                    <div class="steps">
                        <div class="step">
                            <div class="step-num">1</div>
                            <div><h4><?php echo htmlspecialchars(t('aide_ot.clo_step1_titre')); ?></h4>
                            <p><?php echo htmlspecialchars(t('aide_ot.clo_step1_desc')); ?></p></div>
                        </div>
                        <div class="step">
                            <div class="step-num">2</div>
                            <div><h4><?php echo htmlspecialchars(t('aide_ot.clo_step2_titre')); ?></h4>
                            <p><?php echo htmlspecialchars(t('aide_ot.clo_step2_desc')); ?></p></div>
                        </div>
                        <div class="step">
                            <div class="step-num">3</div>
                            <div><h4><?php echo htmlspecialchars(t('aide_ot.clo_step3_titre')); ?></h4>
                            <p><?php echo t('aide_ot.clo_step3_desc'); ?></p></div>
                        </div>
                        <div class="step">
                            <div class="step-num">4</div>
                            <div><h4><?php echo htmlspecialchars(t('aide_ot.clo_step4_titre')); ?></h4>
                            <p><?php echo t('aide_ot.clo_step4_desc'); ?></p></div>
                        </div>
                    </div>

                    <div class="shot">
                        <img src="img/aide/ot_modale_cloture.png" alt="<?php echo htmlspecialchars(t('aide_ot.shot_modale_alt')); ?>">
                        <div class="shot-cap"><i class="fa-solid fa-camera"></i> <?php echo htmlspecialchars(t('aide_ot.shot_modale_cap')); ?></div>
                    </div>

                    <div class="callout callout-warn">
                        <i class="fa-solid fa-triangle-exclamation"></i>
                        <div><p><?php echo t('aide_ot.callout_warn_rapport_vide'); ?></p></div>
                    </div>

                    <div class="callout callout-tip">
                        <i class="fa-solid fa-clock"></i>
                        <div><p><?php echo t('aide_ot.callout_tip_enregistrer_ouvert'); ?></p></div>
                    </div>

                    <div class="callout callout-tip">
                        <i class="fa-solid fa-book"></i>
                        <div><p><?php echo t('aide_ot.callout_tip_carnet'); ?></p></div>
                    </div>

                    <div class="callout callout-tip">
                        <i class="fa-solid fa-eye"></i>
                        <div><p><?php echo t('aide_ot.callout_tip_demandeur_voit'); ?></p></div>
                    </div>

                    <div class="callout callout-lock">
                        <i class="fa-solid fa-file-pdf"></i>
                        <div><p><?php echo t('aide_ot.callout_lock_pdf'); ?></p></div>
                    </div>
                </section>

                <section id="cas-particuliers" class="aide-sec">
                    <div class="sec-eyebrow"><i class="fa-solid fa-layer-group"></i><h2><?php echo htmlspecialchars(t('aide_ot.toc_cas_particuliers')); ?></h2></div>
                    <p class="lede"><?php echo htmlspecialchars(t('aide_ot.cas_particuliers_lede')); ?></p>

                    <div class="cases">
                        <div class="case-card">
                            <h4><i class="fa-solid fa-handshake"></i> <?php echo htmlspecialchars(t('aide_ot.case_soustraitance_titre')); ?></h4>
                            <p><?php echo t('aide_ot.case_soustraitance_desc'); ?></p>
                        </div>
                        <div class="case-card">
                            <h4><i class="fa-solid fa-bell"></i> <?php echo htmlspecialchars(t('aide_ot.case_urgent_titre')); ?></h4>
                            <p><?php echo htmlspecialchars(t('aide_ot.case_urgent_desc')); ?></p>
                        </div>
                        <div class="case-card">
                            <h4><i class="fa-solid fa-comments"></i> <?php echo htmlspecialchars(t('aide_ot.case_messagerie_titre')); ?></h4>
                            <p><?php echo htmlspecialchars(t('aide_ot.case_messagerie_desc')); ?></p>
                        </div>
                        <div class="case-card">
                            <h4><i class="fa-solid fa-trash"></i> <?php echo htmlspecialchars(t('aide_ot.case_suppression_titre')); ?></h4>
                            <p><?php echo t('aide_ot.case_suppression_desc'); ?></p>
                        </div>
                        <div class="case-card">
                            <h4><i class="fa-solid fa-print"></i> <?php echo htmlspecialchars(t('aide_ot.case_impression_titre')); ?></h4>
                            <p><?php echo t('aide_ot.case_impression_desc'); ?></p>
                        </div>
                    </div>
                </section>

                <section id="droits" class="aide-sec">
                    <div class="sec-eyebrow"><i class="fa-solid fa-shield-halved"></i><h2><?php echo htmlspecialchars(t('aide_ot.toc_droits')); ?></h2></div>
                    <p class="lede"><?php echo htmlspecialchars(t('aide_ot.droits_lede')); ?></p>

                    <div class="tbl-wrap">
                        <table>
                            <thead><tr><th><?php echo htmlspecialchars(t('aide_ot.tbl_th_action')); ?></th><th><?php echo htmlspecialchars(t('aide_ot.tbl_th_technicien')); ?></th><th><?php echo htmlspecialchars(t('aide_ot.tbl_th_admin')); ?></th></tr></thead>
                            <tbody>
                                <?php
                                $droits = [
                                    [1, null],
                                    [2, 'aide_ot.droit2_tech'],
                                    [3, 'aide_common.non'],
                                    [4, null],
                                    [5, 'aide_ot.droit5_tech'],
                                    [6, 'aide_common.non'],
                                    [7, 'aide_common.non'],
                                    [8, 'aide_ot.droit8_tech'],
                                    [9, 'aide_ot.droit9_tech'],
                                ];
                                foreach ($droits as [$n, $techKey]):
                                ?>
                                <tr>
                                    <td><?php echo htmlspecialchars(t("aide_ot.droit{$n}_action")); ?></td>
                                    <td><?php if ($techKey): ?><span class="yn n"><i class="fa-solid fa-lock"></i> <?php echo htmlspecialchars(t($techKey)); ?></span><?php else: ?><span class="yn y"><i class="fa-solid fa-check"></i> <?php echo htmlspecialchars(t('aide_common.oui')); ?></span><?php endif; ?></td>
                                    <td><span class="yn y"><i class="fa-solid fa-check"></i> <?php echo htmlspecialchars(t('aide_common.oui')); ?></span></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </section>

                <section id="astuces" class="aide-sec">
                    <div class="sec-eyebrow"><i class="fa-solid fa-lightbulb"></i><h2><?php echo htmlspecialchars(t('aide_ot.toc_astuces')); ?></h2></div>
                    <ul>
                        <?php for ($i = 1; $i <= 6; $i++): ?>
                        <li><?php echo t("aide_ot.astuces_li{$i}"); ?></li>
                        <?php endfor; ?>
                    </ul>
                </section>

                <section id="glossaire" class="aide-sec">
                    <div class="sec-eyebrow"><i class="fa-solid fa-book"></i><h2><?php echo htmlspecialchars(t('aide_ot.toc_glossaire')); ?></h2></div>
                    <dl class="gloss">
                        <?php for ($i = 1; $i <= 7; $i++): ?>
                        <div><dt><?php echo htmlspecialchars(t("aide_ot.gloss{$i}_dt")); ?></dt><dd><?php echo htmlspecialchars(t("aide_ot.gloss{$i}_dd")); ?></dd></div>
                        <?php endfor; ?>
                    </dl>
                </section>

                <section id="roadmap" class="aide-sec" style="margin-bottom:0;">
                    <div class="sec-eyebrow"><i class="fa-solid fa-road"></i><h2><?php echo htmlspecialchars(t('aide_common.roadmap_title')); ?></h2></div>
                    <p class="lede"><?php echo t('aide_common.roadmap_text'); ?></p>

                    <?php if ($is_admin): ?>
                    <details class="devnote">
                        <summary><i class="fa-solid fa-code"></i> <?php echo htmlspecialchars(t('aide_common.devnote_summary')); ?></summary>
                        <p style="margin-top:8px; font-size:0.88rem; color:#5a6b7a;"><?php echo htmlspecialchars(t('aide_ot.devnote_intro')); ?></p>
                        <ul style="font-size:0.88rem; color:#5a6b7a;">
                            <?php for ($i = 1; $i <= 4; $i++): ?>
                            <li><?php echo t("aide_ot.devnote_li{$i}"); ?></li>
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
