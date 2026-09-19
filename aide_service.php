<?php
require_once __DIR__ . '/session_init.php';
// Volontairement accessible sans connexion : cet article est aussi lié depuis
// la page d'aide à la connexion (aide_connexion.php), lisible avant de se connecter.
$est_connecte = isset($_SESSION['user']);
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(langue_actuelle()); ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
<title><?php echo htmlspecialchars(t('aide_service.page_title_tag')); ?></title>
<link rel="icon" type="image/png" href="img/logo.png">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Caveat:wght@600;700&family=Segoe+UI:wght@300;400;600&family=Montserrat:wght@400;700;800;900&display=swap" rel="stylesheet">
<style>
:root {
    --primary: #2c3e50; --accent: #3498db; --success: #2ecc71;
    --danger: #e74c3c; --brand-green: #2ecc71; --brand-orange: #f39c12;
    --violet: #8e44ad; --attente: #95a5a6; --refuse: #7f1d1d;
}
* { box-sizing: border-box; }
body { margin: 0; font-family: 'Segoe UI', sans-serif; background: linear-gradient(rgba(0, 0, 0, 0.35), rgba(0, 0, 0, 0.35)), url('img/fond.jpg') no-repeat center center fixed; background-size: cover; min-height: 100vh; padding: 58px 20px 60px; color: var(--primary); }
html { scroll-behavior: smooth; }
@media (prefers-reduced-motion: reduce) { html { scroll-behavior: auto; } }

.topbar { max-width: 1160px; margin: 0 auto 18px; position: relative; overflow: hidden; background: radial-gradient(90% 170% at 0% 0%, rgba(46, 204, 113, 0.20), transparent 60%), radial-gradient(70% 170% at 100% 100%, rgba(52, 152, 219, 0.16), transparent 60%), rgba(13, 19, 27, 0.66); backdrop-filter: blur(16px) saturate(130%); -webkit-backdrop-filter: blur(16px) saturate(130%); border: 1px solid rgba(255,255,255,0.10); border-radius: 22px; box-shadow: 0 14px 34px -10px rgba(0,0,0,0.6); padding: 10px 18px; display:flex; align-items:center; justify-content:space-between; }
.topbar::after { content: ''; position: absolute; left: 0; right: 0; bottom: 0; height: 2px; background: linear-gradient(90deg, transparent, var(--brand-green), var(--brand-orange), transparent); opacity: 0.75; pointer-events: none; }
.topbar-links { display:flex; align-items:center; gap: 18px; }
.topbar-links a { color: rgba(255,255,255,0.85); text-decoration:none; font-size: 0.78rem; display:flex; align-items:center; gap:6px; font-weight:600; padding: 5px 12px; border-radius: 20px; background: rgba(255,255,255,0.08); border: 1px solid rgba(255,255,255,0.18); transition: 0.25s; }
.topbar-links a:hover { color: #fff; background: rgba(142, 68, 173, 0.3); border-color: rgba(142, 68, 173, 0.65); }
.topbar-links a.logout:hover { color: #fff; background: rgba(231, 76, 60, 0.3); border-color: rgba(231, 76, 60, 0.6); }
.who-badge { background: rgba(255,255,255,0.10); border: 1px solid rgba(255,255,255,0.18); padding: 5px 12px; border-radius: 20px; font-size: 0.78rem; font-weight: 600; color: #fff; }

.main-content { max-width: 1160px; margin: 0 auto; }
.hero { background: radial-gradient(120% 140% at 100% 0%, rgba(52, 152, 219, 0.28), transparent 55%), radial-gradient(80% 120% at 0% 100%, rgba(46, 204, 113, 0.14), transparent 60%), rgba(13, 19, 27, 0.72); backdrop-filter: blur(16px) saturate(130%); -webkit-backdrop-filter: blur(16px) saturate(130%); border: 1px solid rgba(255,255,255,0.10); border-radius: 22px; padding: 30px 38px; color: #fff; position: relative; overflow: hidden; box-shadow: 0 14px 34px -10px rgba(0,0,0,0.6); margin-bottom: 24px; }
.hero::before { content: ''; position: absolute; left: 0; right: 0; bottom: 0; height: 2px; background: linear-gradient(90deg, transparent, var(--brand-green), var(--brand-orange), transparent); opacity: 0.75; }
.hero::after { content: ""; position: absolute; right: -60px; top: -60px; width: 260px; height: 260px; border-radius: 50%; background: radial-gradient(circle, rgba(52,152,219,0.35), transparent 70%); }
.hero-eyebrow { display: inline-flex; align-items: center; gap: 8px; font-size: 0.72rem; font-weight: 700; letter-spacing: 0.08em; text-transform: uppercase; background: rgba(255,255,255,0.12); border: 1px solid rgba(255,255,255,0.25); padding: 6px 14px; border-radius: 999px; margin-bottom: 16px; }
.hero h1 { font-family: 'Montserrat', sans-serif; font-weight: 900; font-size: clamp(1.7rem, 3vw, 2.35rem); margin: 0 0 6px; letter-spacing: -0.01em; }
.hero .tagline { font-family: 'Caveat', cursive; font-size: 1.35rem; color: #cfe4f5; margin: 0 0 18px; }
.hero-meta { display: flex; flex-wrap: wrap; gap: 10px; position: relative; z-index: 1; }
.hero-pill { font-size: 0.78rem; font-family: 'Segoe UI', sans-serif; background: rgba(255,255,255,0.1); border: 1px solid rgba(255,255,255,0.22); border-radius: 8px; padding: 6px 12px; }
.hero-pill b { color: #fff; }

.layout { display: grid; grid-template-columns: 1fr; gap: 22px; align-items: start; }
@media (min-width: 980px) { .layout { grid-template-columns: 240px minmax(0,1fr); } }
.toc-card { background: radial-gradient(90% 40% at 0% 0%, rgba(52, 152, 219, 0.16), transparent 60%), rgba(13, 19, 27, 0.66); backdrop-filter: blur(16px) saturate(130%); -webkit-backdrop-filter: blur(16px) saturate(130%); border: 1px solid rgba(255,255,255,0.10); border-radius: 22px; box-shadow: 0 14px 34px -10px rgba(0,0,0,0.6); overflow: hidden; }
@media (min-width: 980px) { .toc-card { position: sticky; top: 20px; } }
.toc-toggle { display: none; }
.toc-label { display: flex; align-items: center; justify-content: space-between; gap: 10px; cursor: pointer; padding: 14px 16px; font-weight: 700; color: #fff; font-size: 0.95rem; }
.toc-label .fa-chevron-down { transition: transform 0.2s ease; color: color-mix(in srgb, var(--accent) 50%, #fff); }
.toc-toggle:checked ~ .toc-label .fa-chevron-down { transform: rotate(180deg); }
.toc-list { list-style: none; margin: 0; padding: 0 8px 10px; display: none; }
.toc-toggle:checked ~ .toc-list { display: block; }
.toc-list a { display: flex; align-items: center; gap: 10px; padding: 9px 10px; border-radius: 10px; color: rgba(255,255,255,0.75); text-decoration: none; font-size: 0.86rem; font-weight: 600; transition: 0.2s; }
.toc-list a i { color: color-mix(in srgb, var(--accent) 50%, #fff); width: 16px; text-align: center; }
.toc-list a:hover { background: rgba(255,255,255,0.10); color: #fff; }
.toc-sep { height: 1px; background: rgba(255,255,255,0.12); margin: 6px 6px; }
@media (min-width: 980px) { .toc-label { display: none; } .toc-list { display: block !important; padding: 10px; } }

main.content { min-width: 0; }
.card { background: rgba(255,255,255,0.97); border-radius: 22px; box-shadow: 0 14px 34px -10px rgba(0,0,0,0.6); padding: 26px 28px; }
section.aide-sec { margin-bottom: 26px; scroll-margin-top: 20px; }
.sec-eyebrow { display: flex; align-items: center; gap: 10px; margin-bottom: 4px; }
.sec-eyebrow i { color: #fff; background: linear-gradient(145deg, color-mix(in srgb, var(--accent) 80%, #fff), var(--accent)); width: 34px; height: 34px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 0.95rem; flex: none; box-shadow: 0 6px 14px -4px rgba(52, 152, 219, 0.6); }
.aide-sec h2 { font-family: 'Montserrat', sans-serif; font-weight: 800; font-size: 1.28rem; color: var(--primary); margin: 0; }
.lede { color: #5a6b7a; font-size: 1rem; max-width: 68ch; margin: 10px 0 18px; line-height: 1.6; }
.aide-sec h3 { font-family: 'Montserrat', sans-serif; font-size: 1rem; font-weight: 700; color: var(--primary); margin: 0 0 6px; }
.aide-sec p { color: #405060; line-height: 1.6; font-size: 0.94rem; }
.aide-sec ul { color: #405060; line-height: 1.65; font-size: 0.94rem; }
.aide-sec li { margin-bottom: 6px; }

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

.pill { display: inline-flex; align-items: center; gap: 6px; font-weight: 700; font-size: 0.72rem; letter-spacing: 0.03em; text-transform: uppercase; padding: 5px 12px; border-radius: 999px; color: #fff; white-space: nowrap; }
.pill-attente { background: var(--attente); }
.pill-afaire { background: var(--brand-orange); }
.pill-urgent { background: var(--danger); }
.pill-cours { background: var(--accent); }
.pill-termine { background: var(--brand-green); }
.pill-refuse { background: var(--refuse); }
.pill-dot { width: 6px; height: 6px; border-radius: 50%; background: rgba(255,255,255,0.85); }

.gloss { display: grid; grid-template-columns: 1fr; gap: 14px; margin-top: 12px; }
@media (min-width: 700px) { .gloss { grid-template-columns: 1fr 1fr; } }
.gloss dt { font-family: 'Montserrat', sans-serif; font-weight: 800; color: var(--accent); font-size: 0.86rem; }
.gloss dd { margin: 3px 0 0; color: #5a6b7a; font-size: 0.88rem; }

.tbl-wrap { overflow-x: auto; border: 1px solid #e6e9ec; border-radius: 12px; margin-top: 14px; }
.tbl-wrap table { border-collapse: collapse; width: 100%; min-width: 480px; font-size: 0.88rem; }
.tbl-wrap thead th { text-align: left; font-family: 'Montserrat', sans-serif; font-weight: 700; font-size: 0.72rem; letter-spacing: 0.04em; text-transform: uppercase; color: #7f8c9a; background: #f7f9fb; padding: 11px 14px; border-bottom: 1px solid #e6e9ec; }
.tbl-wrap tbody td { padding: 11px 14px; border-bottom: 1px solid #eef1f3; color: #405060; vertical-align: top; }
.tbl-wrap tbody tr:last-child td { border-bottom: none; }
.tbl-wrap tbody td:first-child { font-weight: 700; color: var(--primary); white-space: nowrap; }

.shot { margin: 16px 0; border-radius: 12px; overflow: hidden; border: 1px solid #e6e9ec; box-shadow: 0 10px 24px -12px rgba(0,0,0,0.28); background: #fff; }
.shot img { display: block; width: 100%; height: auto; }
.shot-cap { padding: 8px 14px; font-size: 0.78rem; color: #7f8c9a; background: #f7f9fb; border-top: 1px solid #e6e9ec; display: flex; align-items: center; gap: 8px; }
.shot-cap i { color: var(--accent); }
.shot.shot-narrow { max-width: 420px; margin-left: auto; margin-right: auto; }

.tiles-grid { display: grid; grid-template-columns: 1fr; gap: 14px; margin-top: 14px; }
@media (min-width: 700px) { .tiles-grid { grid-template-columns: repeat(4, minmax(0, 1fr)); } }
.tile-card { background: radial-gradient(120% 90% at 0% 0%, color-mix(in srgb, var(--t-color, var(--accent)) 26%, transparent), transparent 58%), rgba(13, 19, 27, 0.86); border: 1px solid rgba(255,255,255,0.10); border-radius: 18px; padding: 16px; }
.tile-card i.tile-icon { width: 40px; height: 40px; border-radius: 50%; background: color-mix(in srgb, var(--t-color, var(--accent)) 16%, transparent); color: color-mix(in srgb, var(--t-color, var(--accent)) 50%, #fff); box-shadow: inset 0 0 0 1.5px color-mix(in srgb, var(--t-color, var(--accent)) 72%, #fff), 0 0 20px -4px color-mix(in srgb, var(--t-color, var(--accent)) 55%, transparent); display:flex; align-items:center; justify-content:center; font-size: 1rem; margin-bottom: 10px; }
.tile-card h4 { font-family: 'Montserrat', sans-serif; font-size: 0.9rem; margin: 0 0 6px; color: #fff; }
.tile-card p { margin: 0; font-size: 0.82rem; color: rgba(255,255,255,0.65); line-height: 1.5; }

.back-top { display: inline-flex; align-items: center; gap: 8px; margin-top: 8px; color: var(--accent); text-decoration: none; font-weight: 600; font-size: 0.85rem; }
.back-top:hover { text-decoration: underline; }

/* --- FAQ (questions fréquentes) --- */
.faq-item { border: 1px solid #e2e8f0; border-radius: 10px; margin-bottom: 8px; overflow: hidden; }
.faq-q { display:flex; align-items:center; justify-content:space-between; gap: 10px; padding: 12px 14px; cursor: pointer; background: #fff; font-weight: 600; font-size: 0.86rem; color: var(--primary); }
.faq-q:hover { background: #f8fafc; }
.faq-q i.chevron { color: #94a3b8; transition: transform 0.2s ease; flex-shrink: 0; }
.faq-item.open .faq-q i.chevron { transform: rotate(180deg); }
.faq-a { max-height: 0; overflow: hidden; transition: max-height 0.25s ease; background: #f8fafc; }
.faq-item.open .faq-a { max-height: 400px; }
.faq-a-inner { padding: 4px 14px 14px; font-size: 0.86rem; color: #5a6b7a; line-height: 1.55; }

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

<?php if ($est_connecte): ?>
<a href="accueil.php" class="btn-floating-nav home" title="<?php echo htmlspecialchars(t('aide_service.accueil_title')); ?>" aria-label="<?php echo htmlspecialchars(t('aide_service.accueil_title')); ?>"><i class="fa-solid fa-house"></i></a>
<div class="crumb-bar">
    <a href="accueil.php" class="crumb-home"><?php echo htmlspecialchars(t('aide_service.crumb_portail_services')); ?></a>
    <span class="crumb-sep">/</span>
    <span class="crumb-current"><?php echo htmlspecialchars(t('aide_service.crumb_aide')); ?></span>
</div>
<?php endif; ?>

<div class="topbar">
    <?php if ($est_connecte): ?>
        <span class="who-badge"><i class="fa-solid fa-building"></i> <?php echo htmlspecialchars($_SESSION['user']); ?></span>
    <?php else: ?>
        <span class="who-badge"><i class="fa-solid fa-circle-info"></i> <?php echo htmlspecialchars(t('aide_service.badge_non_connecte')); ?></span>
        <div class="topbar-links">
            <a href="login.php"><i class="fa-solid fa-right-to-bracket"></i> <?php echo htmlspecialchars(t('aide_service.lien_se_connecter')); ?></a>
        </div>
    <?php endif; ?>
</div>

<div class="main-content">

    <div class="hero">
        <span class="hero-eyebrow"><i class="fa-solid fa-book-open"></i> <?php echo htmlspecialchars(t('aide_service.hero_eyebrow')); ?></span>
        <h1><?php echo htmlspecialchars(t('aide_service.hero_h1')); ?></h1>
        <p class="tagline"><?php echo htmlspecialchars(t('aide_service.hero_tagline')); ?></p>
        <div class="hero-meta">
            <span class="hero-pill"><?php echo t('aide_service.pill_updated'); ?></span>
            <span class="hero-pill"><?php echo t('aide_service.pill_public'); ?></span>
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
                <li><a href="#connexion"><i class="fa-solid fa-right-to-bracket"></i> <?php echo htmlspecialchars(t('aide_service.toc_connexion')); ?></a></li>
                <li><a href="#accueil"><i class="fa-solid fa-house"></i> <?php echo htmlspecialchars(t('aide_service.toc_accueil')); ?></a></li>
                <li><a href="#demande"><i class="fa-solid fa-screwdriver-wrench"></i> <?php echo htmlspecialchars(t('aide_service.toc_demande')); ?></a></li>
                <li><a href="#suivi"><i class="fa-solid fa-satellite-dish"></i> <?php echo htmlspecialchars(t('aide_service.toc_suivi')); ?></a></li>
                <li><a href="#idees"><i class="fa-solid fa-lightbulb"></i> <?php echo htmlspecialchars(t('aide_service.toc_idees')); ?></a></li>
                <li class="toc-sep"></li>
                <li><a href="#annualisation"><i class="fa-solid fa-scale-balanced"></i> <?php echo htmlspecialchars(t('aide_service.toc_annualisation')); ?></a></li>
                <li><a href="#planning"><i class="fa-solid fa-calendar-days"></i> <?php echo htmlspecialchars(t('aide_service.toc_planning')); ?></a></li>
                <li class="toc-sep"></li>
                <li><a href="#astuces"><i class="fa-solid fa-graduation-cap"></i> <?php echo htmlspecialchars(t('aide_service.toc_astuces')); ?></a></li>
                <li><a href="#glossaire"><i class="fa-solid fa-book"></i> <?php echo htmlspecialchars(t('aide_service.toc_glossaire')); ?></a></li>
                <li><a href="#faq"><i class="fa-solid fa-circle-question"></i> <?php echo htmlspecialchars(t('aide_service.toc_faq')); ?></a></li>
            </ul>
        </nav>

        <main class="content">
            <div class="card">

                <section id="connexion" class="aide-sec">
                    <div class="sec-eyebrow"><i class="fa-solid fa-right-to-bracket"></i><h2><?php echo htmlspecialchars(t('aide_service.toc_connexion')); ?></h2></div>
                    <p class="lede"><?php echo htmlspecialchars(t('aide_service.connexion_lede')); ?></p>

                    <div class="shot shot-narrow">
                        <img src="img/aide/connexion_page_login.png" alt="<?php echo htmlspecialchars(t('aide_service.shot_login_alt')); ?>" onerror="this.parentElement.style.display='none';">
                        <div class="shot-cap"><i class="fa-solid fa-camera"></i> <?php echo htmlspecialchars(t('aide_service.shot_login_cap')); ?></div>
                    </div>

                    <div class="steps">
                        <div class="step">
                            <div class="step-num">1</div>
                            <div><h4><?php echo htmlspecialchars(t('aide_service.co_step1_titre')); ?></h4>
                            <p><?php echo htmlspecialchars(t('aide_service.co_step1_desc')); ?></p></div>
                        </div>
                        <div class="step">
                            <div class="step-num">2</div>
                            <div><h4><?php echo htmlspecialchars(t('aide_service.co_step2_titre')); ?></h4>
                            <p><?php echo htmlspecialchars(t('aide_service.co_step2_desc')); ?></p></div>
                        </div>
                        <div class="step">
                            <div class="step-num">3</div>
                            <div><h4><?php echo htmlspecialchars(t('aide_service.co_step3_titre')); ?></h4>
                            <p><?php echo htmlspecialchars(t('aide_service.co_step3_desc')); ?></p></div>
                        </div>
                    </div>

                    <div class="callout callout-warn">
                        <i class="fa-solid fa-signature"></i>
                        <div><p><?php echo t('aide_service.callout_warn_code_perso'); ?></p></div>
                    </div>

                    <div class="shot shot-narrow">
                        <img src="img/aide/connexion_erreur.png" alt="<?php echo htmlspecialchars(t('aide_service.shot_erreur_alt')); ?>" onerror="this.parentElement.style.display='none';">
                        <div class="shot-cap"><i class="fa-solid fa-camera"></i> <?php echo htmlspecialchars(t('aide_service.shot_erreur_cap')); ?></div>
                    </div>
                </section>

                <section id="accueil" class="aide-sec">
                    <div class="sec-eyebrow"><i class="fa-solid fa-house"></i><h2><?php echo htmlspecialchars(t('aide_service.toc_accueil')); ?></h2></div>
                    <p class="lede"><?php echo htmlspecialchars(t('aide_service.accueil_lede')); ?></p>

                    <div class="tiles-grid">
                        <div class="tile-card" style="--t-color: var(--brand-green);">
                            <i class="fa-solid fa-screwdriver-wrench tile-icon"></i>
                            <h4><?php echo htmlspecialchars(t('aide_service.tile1_titre')); ?></h4>
                            <p><?php echo htmlspecialchars(t('aide_service.tile1_desc')); ?></p>
                        </div>
                        <div class="tile-card" style="--t-color: var(--accent);">
                            <i class="fa-solid fa-satellite-dish tile-icon"></i>
                            <h4><?php echo htmlspecialchars(t('aide_service.tile2_titre')); ?></h4>
                            <p><?php echo htmlspecialchars(t('aide_service.tile2_desc')); ?></p>
                        </div>
                        <div class="tile-card" style="--t-color: var(--brand-orange);">
                            <i class="fa-solid fa-lightbulb tile-icon"></i>
                            <h4><?php echo htmlspecialchars(t('aide_service.tile3_titre')); ?></h4>
                            <p><?php echo htmlspecialchars(t('aide_service.tile3_desc')); ?></p>
                        </div>
                        <div class="tile-card" style="--t-color: var(--violet);">
                            <i class="fa-solid fa-circle-question tile-icon"></i>
                            <h4><?php echo htmlspecialchars(t('aide_service.tile4_titre')); ?></h4>
                            <p><?php echo htmlspecialchars(t('aide_service.tile4_desc')); ?></p>
                        </div>
                    </div>

                    <div class="callout callout-tip">
                        <i class="fa-solid fa-lightbulb"></i>
                        <div><p><?php echo t('aide_service.callout_tip_badge_rouge'); ?></p></div>
                    </div>
                </section>

                <section id="demande" class="aide-sec">
                    <div class="sec-eyebrow"><i class="fa-solid fa-screwdriver-wrench"></i><h2><?php echo htmlspecialchars(t('aide_service.toc_demande')); ?></h2></div>
                    <p class="lede"><?php echo t('aide_service.demande_lede'); ?></p>

                    <div class="shot">
                        <img src="img/aide/demande_etape1_identite.png" alt="<?php echo htmlspecialchars(t('aide_service.shot_etape1_alt')); ?>" onerror="this.parentElement.style.display='none';">
                        <div class="shot-cap"><i class="fa-solid fa-camera"></i> <?php echo htmlspecialchars(t('aide_service.shot_etape1_cap')); ?></div>
                    </div>

                    <div class="steps">
                        <div class="step">
                            <div class="step-num">1</div>
                            <div><h4><?php echo htmlspecialchars(t('aide_service.d_step1_titre')); ?></h4>
                            <p><?php echo htmlspecialchars(t('aide_service.d_step1_desc')); ?></p></div>
                        </div>
                        <div class="step">
                            <div class="step-num">2</div>
                            <div><h4><?php echo htmlspecialchars(t('aide_service.d_step2_titre')); ?></h4>
                            <p><?php echo t('aide_service.d_step2_desc'); ?></p></div>
                        </div>
                        <div class="step">
                            <div class="step-num">3</div>
                            <div><h4><?php echo htmlspecialchars(t('aide_service.d_step3_titre')); ?></h4>
                            <p><?php echo t('aide_service.d_step3_desc'); ?></p></div>
                        </div>
                        <div class="step">
                            <div class="step-num">4</div>
                            <div><h4><?php echo htmlspecialchars(t('aide_service.d_step4_titre')); ?></h4>
                            <p><?php echo t('aide_service.d_step4_desc'); ?></p></div>
                        </div>
                    </div>

                    <div class="shot-row two" style="display:grid; gap:14px; grid-template-columns:1fr 1fr; margin-top:14px;">
                        <div class="shot" style="margin:0;">
                            <img src="img/aide/demande_etape2_localisation.png" alt="<?php echo htmlspecialchars(t('aide_service.shot_etape2_alt')); ?>" onerror="this.parentElement.style.display='none';">
                            <div class="shot-cap"><i class="fa-solid fa-camera"></i> <?php echo htmlspecialchars(t('aide_service.shot_etape2_cap')); ?></div>
                        </div>
                        <div class="shot" style="margin:0;">
                            <img src="img/aide/demande_etape3_description.png" alt="<?php echo htmlspecialchars(t('aide_service.shot_etape3_alt')); ?>" onerror="this.parentElement.style.display='none';">
                            <div class="shot-cap"><i class="fa-solid fa-camera"></i> <?php echo htmlspecialchars(t('aide_service.shot_etape3_cap')); ?></div>
                        </div>
                    </div>

                    <div class="callout callout-tip">
                        <i class="fa-solid fa-circle-check"></i>
                        <div><p><?php echo t('aide_service.callout_tip_envoyee'); ?></p></div>
                    </div>
                </section>

                <section id="suivi" class="aide-sec">
                    <div class="sec-eyebrow"><i class="fa-solid fa-satellite-dish"></i><h2><?php echo htmlspecialchars(t('aide_service.toc_suivi')); ?></h2></div>
                    <p class="lede"><?php echo htmlspecialchars(t('aide_service.suivi_lede')); ?></p>

                    <div class="shot">
                        <img src="img/aide/demande_suivi.png" alt="<?php echo htmlspecialchars(t('aide_service.shot_suivi_alt')); ?>" onerror="this.parentElement.style.display='none';">
                        <div class="shot-cap"><i class="fa-solid fa-camera"></i> <?php echo htmlspecialchars(t('aide_service.shot_suivi_cap')); ?></div>
                    </div>

                    <h3><?php echo htmlspecialchars(t('aide_service.h3_5_statuts')); ?></h3>
                    <ul>
                        <li><span class="pill pill-attente"><span class="pill-dot"></span><?php echo htmlspecialchars(t('aide_service.pill_attente_label')); ?></span> — <?php echo htmlspecialchars(t('aide_service.pill_attente_desc')); ?></li>
                        <li><span class="pill pill-afaire"><span class="pill-dot"></span><?php echo htmlspecialchars(t('aide_service.pill_afaire_label')); ?></span> — <?php echo htmlspecialchars(t('aide_service.pill_afaire_desc')); ?></li>
                        <li><span class="pill pill-cours"><span class="pill-dot"></span><?php echo htmlspecialchars(t('aide_service.pill_cours_label')); ?></span> — <?php echo htmlspecialchars(t('aide_service.pill_cours_desc')); ?></li>
                        <li><span class="pill pill-termine"><span class="pill-dot"></span><?php echo htmlspecialchars(t('aide_service.pill_termine_label')); ?></span> — <?php echo htmlspecialchars(t('aide_service.pill_termine_desc')); ?></li>
                        <li><span class="pill pill-refuse"><span class="pill-dot"></span><?php echo htmlspecialchars(t('aide_service.pill_refuse_label')); ?></span> — <?php echo htmlspecialchars(t('aide_service.pill_refuse_desc')); ?></li>
                    </ul>

                    <div class="callout callout-lock">
                        <i class="fa-solid fa-circle-info"></i>
                        <div><p><?php echo t('aide_service.callout_lock_renommage'); ?></p></div>
                    </div>

                    <h3><?php echo htmlspecialchars(t('aide_service.h3_vignettes')); ?></h3>
                    <p><?php echo t('aide_service.vignettes_p1'); ?></p>
                    <ul>
                        <li><?php echo t('aide_service.vf_li1'); ?></li>
                        <li><?php echo t('aide_service.vf_li2'); ?></li>
                        <li><?php echo t('aide_service.vf_li3'); ?></li>
                        <li><?php echo t('aide_service.vf_li4'); ?></li>
                    </ul>
                    <p><?php echo t('aide_service.vignettes_p2'); ?></p>

                    <h3><?php echo htmlspecialchars(t('aide_service.h3_carte')); ?></h3>
                    <p><?php echo htmlspecialchars(t('aide_service.carte_lede')); ?></p>
                    <div class="tiles-grid">
                        <div class="tile-card" style="--t-color: var(--danger);">
                            <i class="fa-solid fa-bell tile-icon"></i>
                            <h4><?php echo htmlspecialchars(t('aide_service.tile_urgent_titre')); ?></h4>
                            <p><?php echo htmlspecialchars(t('aide_service.tile_urgent_desc')); ?></p>
                        </div>
                        <div class="tile-card" style="--t-color: var(--violet);">
                            <i class="fa-solid fa-handshake tile-icon"></i>
                            <h4><?php echo htmlspecialchars(t('aide_service.tile_soustraite_titre')); ?></h4>
                            <p><?php echo htmlspecialchars(t('aide_service.tile_soustraite_desc')); ?></p>
                        </div>
                        <div class="tile-card" style="--t-color: var(--brand-orange);">
                            <i class="fa-solid fa-triangle-exclamation tile-icon"></i>
                            <h4><?php echo htmlspecialchars(t('aide_service.tile_bris_titre')); ?></h4>
                            <p><?php echo htmlspecialchars(t('aide_service.tile_bris_desc')); ?></p>
                        </div>
                        <div class="tile-card" style="--t-color: var(--accent);">
                            <i class="fa-solid fa-screwdriver tile-icon"></i>
                            <h4><?php echo htmlspecialchars(t('aide_service.tile_visserie_titre')); ?></h4>
                            <p><?php echo htmlspecialchars(t('aide_service.tile_visserie_desc')); ?></p>
                        </div>
                    </div>

                    <p><?php echo t('aide_service.frise_p'); ?></p>

                    <p><?php echo t('aide_service.heures_p'); ?></p>

                    <div class="callout callout-tip">
                        <i class="fa-solid fa-book"></i>
                        <div><p><?php echo t('aide_service.callout_tip_carnet'); ?></p></div>
                    </div>

                    <h3><?php echo htmlspecialchars(t('aide_service.h3_messagerie')); ?></h3>
                    <div class="callout callout-tip">
                        <i class="fa-solid fa-comments"></i>
                        <div><p><?php echo t('aide_service.callout_tip_messagerie'); ?></p></div>
                    </div>

                    <div class="callout callout-warn">
                        <i class="fa-solid fa-bell"></i>
                        <div><p><?php echo t('aide_service.callout_warn_badge_rouge'); ?></p></div>
                    </div>
                </section>

                <section id="idees" class="aide-sec">
                    <div class="sec-eyebrow"><i class="fa-solid fa-lightbulb"></i><h2><?php echo htmlspecialchars(t('aide_service.toc_idees')); ?></h2></div>
                    <p class="lede"><?php echo t('aide_service.idees_lede'); ?></p>

                    <div class="steps">
                        <div class="step">
                            <div class="step-num">1</div>
                            <div><h4><?php echo htmlspecialchars(t('aide_service.i_step1_titre')); ?></h4>
                            <p><?php echo htmlspecialchars(t('aide_service.i_step1_desc')); ?></p></div>
                        </div>
                        <div class="step">
                            <div class="step-num">2</div>
                            <div><h4><?php echo htmlspecialchars(t('aide_service.i_step2_titre')); ?></h4>
                            <p><?php echo htmlspecialchars(t('aide_service.i_step2_desc')); ?></p></div>
                        </div>
                        <div class="step">
                            <div class="step-num">3</div>
                            <div><h4><?php echo htmlspecialchars(t('aide_service.i_step3_titre')); ?></h4>
                            <p><?php echo t('aide_service.i_step3_desc'); ?></p></div>
                        </div>
                    </div>

                    <div class="callout callout-tip">
                        <i class="fa-solid fa-comment-dots"></i>
                        <div><p><?php echo t('aide_service.callout_tip_nouveau'); ?></p></div>
                    </div>
                </section>

                <section id="annualisation" class="aide-sec">
                    <div class="sec-eyebrow"><i class="fa-solid fa-scale-balanced"></i><h2><?php echo htmlspecialchars(t('aide_service.toc_annualisation')); ?></h2></div>
                    <p class="lede"><?php echo htmlspecialchars(t('aide_service.annualisation_lede')); ?></p>

                    <h3><?php echo htmlspecialchars(t('aide_service.h3_1607h')); ?></h3>
                    <p><?php echo t('aide_service.p_1607h'); ?></p>

                    <div class="tbl-wrap">
                        <table>
                            <thead><tr><th><?php echo htmlspecialchars(t('aide_service.tbl_th_type_jour')); ?></th><th><?php echo htmlspecialchars(t('aide_service.tbl_th_heures_dues')); ?></th><th><?php echo htmlspecialchars(t('aide_service.tbl_th_debite')); ?></th><th><?php echo htmlspecialchars(t('aide_service.tbl_th_pourquoi')); ?></th></tr></thead>
                            <tbody>
                                <tr><td><?php echo htmlspecialchars(t('aide_service.row_cp_type')); ?></td><td><?php echo htmlspecialchars(t('aide_service.row_cp_heures')); ?></td><td><?php echo htmlspecialchars(t('aide_service.row_cp_debite')); ?></td><td><?php echo htmlspecialchars(t('aide_service.row_cp_pourquoi')); ?></td></tr>
                                <tr><td><?php echo htmlspecialchars(t('aide_service.row_demicp_type')); ?></td><td><?php echo htmlspecialchars(t('aide_service.row_demicp_heures')); ?></td><td><?php echo htmlspecialchars(t('aide_service.row_demicp_debite')); ?></td><td><?php echo htmlspecialchars(t('aide_service.row_demicp_pourquoi')); ?></td></tr>
                                <tr><td><?php echo htmlspecialchars(t('aide_service.row_rtt_type')); ?></td><td><?php echo t('aide_service.row_rtt_heures'); ?></td><td><?php echo t('aide_service.row_rtt_debite'); ?></td><td><?php echo htmlspecialchars(t('aide_service.row_rtt_pourquoi')); ?></td></tr>
                                <tr><td><?php echo htmlspecialchars(t('aide_service.row_repos_type')); ?></td><td><?php echo htmlspecialchars(t('aide_service.row_repos_heures')); ?></td><td><?php echo htmlspecialchars(t('aide_service.row_repos_debite')); ?></td><td><?php echo htmlspecialchars(t('aide_service.row_repos_pourquoi')); ?></td></tr>
                                <tr><td><?php echo t('aide_service.row_ferienontrav_type'); ?></td><td><?php echo htmlspecialchars(t('aide_service.row_ferienontrav_heures')); ?></td><td><?php echo htmlspecialchars(t('aide_service.row_ferienontrav_debite')); ?></td><td><?php echo htmlspecialchars(t('aide_service.row_ferienontrav_pourquoi')); ?></td></tr>
                                <tr><td><?php echo t('aide_service.row_ferietrav_type'); ?></td><td><?php echo htmlspecialchars(t('aide_service.row_ferietrav_heures')); ?></td><td><?php echo htmlspecialchars(t('aide_service.row_ferietrav_debite')); ?></td><td><?php echo htmlspecialchars(t('aide_service.row_ferietrav_pourquoi')); ?></td></tr>
                                <tr><td><?php echo htmlspecialchars(t('aide_service.row_maladie_type')); ?></td><td><?php echo htmlspecialchars(t('aide_service.row_maladie_heures')); ?></td><td><?php echo htmlspecialchars(t('aide_service.row_maladie_debite')); ?></td><td><?php echo htmlspecialchars(t('aide_service.row_maladie_pourquoi')); ?></td></tr>
                            </tbody>
                        </table>
                    </div>

                    <div class="callout callout-warn">
                        <i class="fa-solid fa-triangle-exclamation"></i>
                        <div><p><?php echo t('aide_service.callout_warn_piege_rtt'); ?></p></div>
                    </div>

                    <div class="callout callout-tip">
                        <i class="fa-solid fa-circle-info"></i>
                        <div><p><?php echo htmlspecialchars(t('aide_service.callout_tip_suivi_detaille')); ?></p></div>
                    </div>

                    <div class="callout callout-lock">
                        <i class="fa-solid fa-signature"></i>
                        <div><p><?php echo htmlspecialchars(t('aide_service.callout_lock_pedagogique')); ?></p></div>
                    </div>
                </section>

                <section id="planning" class="aide-sec">
                    <div class="sec-eyebrow"><i class="fa-solid fa-calendar-days"></i><h2><?php echo htmlspecialchars(t('aide_service.toc_planning')); ?></h2></div>
                    <p class="lede"><?php echo htmlspecialchars(t('aide_service.planning_lede')); ?></p>

                    <div class="callout callout-lock">
                        <i class="fa-solid fa-clock"></i>
                        <div><p><?php echo t('aide_service.callout_lock_pas_dispo'); ?></p></div>
                    </div>

                    <p><?php echo htmlspecialchars(t('aide_service.planning_p2')); ?></p>
                    <ul>
                        <li><?php echo t('aide_service.planning_li1'); ?></li>
                        <li><?php echo t('aide_service.planning_li2'); ?></li>
                    </ul>

                    <div class="shot">
                        <img src="img/aide/planning_grille_semaine.png" alt="<?php echo htmlspecialchars(t('aide_service.shot_planning_alt')); ?>" onerror="this.parentElement.style.display='none';">
                        <div class="shot-cap"><i class="fa-solid fa-camera"></i> <?php echo htmlspecialchars(t('aide_service.shot_planning_cap')); ?></div>
                    </div>

                    <div class="callout callout-tip">
                        <i class="fa-solid fa-lightbulb"></i>
                        <div><p><?php echo htmlspecialchars(t('aide_service.callout_tip_bientot')); ?></p></div>
                    </div>
                </section>

                <section id="astuces" class="aide-sec">
                    <div class="sec-eyebrow"><i class="fa-solid fa-graduation-cap"></i><h2><?php echo htmlspecialchars(t('aide_service.toc_astuces')); ?></h2></div>
                    <ul>
                        <?php for ($i = 1; $i <= 5; $i++): ?>
                        <li><?php echo t("aide_service.astuces_li{$i}"); ?></li>
                        <?php endfor; ?>
                    </ul>
                </section>

                <section id="glossaire" class="aide-sec">
                    <div class="sec-eyebrow"><i class="fa-solid fa-book"></i><h2><?php echo htmlspecialchars(t('aide_service.toc_glossaire')); ?></h2></div>
                    <dl class="gloss">
                        <?php for ($i = 1; $i <= 6; $i++): ?>
                        <div><dt><?php echo htmlspecialchars(t("aide_service.gloss{$i}_dt")); ?></dt><dd><?php echo t("aide_service.gloss{$i}_dd"); ?></dd></div>
                        <?php endfor; ?>
                    </dl>
                </section>

                <section id="faq" class="aide-sec" style="margin-bottom:0;">
                    <div class="sec-eyebrow"><i class="fa-solid fa-circle-question"></i><h2><?php echo htmlspecialchars(t('aide_service.toc_faq')); ?></h2></div>
                    <p class="lede"><?php echo htmlspecialchars(t('aide_service.faq_lede')); ?></p>

                    <div class="faq-item">
                        <div class="faq-q"><span><?php echo htmlspecialchars(t('aide_service.faq1_q')); ?></span><i class="fa-solid fa-chevron-down chevron"></i></div>
                        <div class="faq-a"><div class="faq-a-inner">
                            <ul>
                                <li><?php echo t('aide_service.faq1_li1'); ?></li>
                                <li><?php echo t('aide_service.faq1_li2'); ?></li>
                                <li><?php echo t('aide_service.faq1_li3'); ?></li>
                            </ul>
                        </div></div>
                    </div>

                    <div class="faq-item">
                        <div class="faq-q"><span><?php echo htmlspecialchars(t('aide_service.faq2_q')); ?></span><i class="fa-solid fa-chevron-down chevron"></i></div>
                        <div class="faq-a"><div class="faq-a-inner">
                            <?php echo t('aide_service.faq2_a'); ?>
                        </div></div>
                    </div>

                    <div class="faq-item">
                        <div class="faq-q"><span><?php echo htmlspecialchars(t('aide_service.faq3_q')); ?></span><i class="fa-solid fa-chevron-down chevron"></i></div>
                        <div class="faq-a"><div class="faq-a-inner">
                            <ul>
                                <li><?php echo t('aide_service.faq3_li1'); ?></li>
                                <li><?php echo t('aide_service.faq3_li2'); ?></li>
                                <li><?php echo t('aide_service.faq3_li3'); ?></li>
                            </ul>
                        </div></div>
                    </div>

                    <div class="faq-item">
                        <div class="faq-q"><span><?php echo htmlspecialchars(t('aide_service.faq4_q')); ?></span><i class="fa-solid fa-chevron-down chevron"></i></div>
                        <div class="faq-a"><div class="faq-a-inner">
                            <?php echo t('aide_service.faq4_a'); ?>
                        </div></div>
                    </div>

                    <div class="faq-item">
                        <div class="faq-q"><span><?php echo htmlspecialchars(t('aide_service.faq5_q')); ?></span><i class="fa-solid fa-chevron-down chevron"></i></div>
                        <div class="faq-a"><div class="faq-a-inner">
                            <?php echo t('aide_service.faq5_a'); ?>
                        </div></div>
                    </div>

                    <div class="faq-item">
                        <div class="faq-q"><span><?php echo htmlspecialchars(t('aide_service.faq6_q')); ?></span><i class="fa-solid fa-chevron-down chevron"></i></div>
                        <div class="faq-a"><div class="faq-a-inner">
                            <?php echo t('aide_service.faq6_a'); ?>
                        </div></div>
                    </div>

                    <div class="faq-item">
                        <div class="faq-q"><span><?php echo htmlspecialchars(t('aide_service.faq7_q')); ?></span><i class="fa-solid fa-chevron-down chevron"></i></div>
                        <div class="faq-a"><div class="faq-a-inner">
                            <?php echo t('aide_service.faq7_a'); ?>
                        </div></div>
                    </div>

                    <div class="faq-item">
                        <div class="faq-q"><span><?php echo htmlspecialchars(t('aide_service.faq8_q')); ?></span><i class="fa-solid fa-chevron-down chevron"></i></div>
                        <div class="faq-a"><div class="faq-a-inner">
                            <?php echo t('aide_service.faq8_a'); ?>
                        </div></div>
                    </div>

                    <div class="faq-item">
                        <div class="faq-q"><span><?php echo htmlspecialchars(t('aide_service.faq9_q')); ?></span><i class="fa-solid fa-chevron-down chevron"></i></div>
                        <div class="faq-a"><div class="faq-a-inner">
                            <?php echo t('aide_service.faq9_a'); ?>
                        </div></div>
                    </div>

                    <div class="callout callout-lock" style="margin-top:16px;">
                        <i class="fa-solid fa-circle-info"></i>
                        <div><?php echo htmlspecialchars(t('aide_service.callout_contact')); ?></div>
                    </div>
                </section>

            </div>
        </main>
    </div>
</div>

<script>
document.querySelectorAll('.faq-q').forEach(q => {
    q.addEventListener('click', () => {
        q.parentElement.classList.toggle('open');
    });
});
</script>

</body>
</html>
