<?php
require_once __DIR__ . '/session_init.php';
// Page volontairement accessible SANS connexion : c'est le seul article d'aide
// qu'un nouvel utilisateur doit pouvoir ouvrir avant même de savoir se connecter.
$hide_header = true;
$is_admin = (isset($_SESSION['role']) && $_SESSION['role'] === 'admin');

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(langue_actuelle()); ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
<title><?php echo htmlspecialchars(t('aide_connexion.page_title_tag')); ?></title>
<link rel="icon" type="image/png" href="img/logo.png">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Caveat:wght@600&family=Segoe+UI:wght@300;400;600&family=Montserrat:wght@400;700;800;900&display=swap" rel="stylesheet">
<style>
:root {
    --primary: #2c3e50; --accent: #3498db; --success: #2ecc71;
    --danger: #e74c3c; --brand-green: #2ecc71; --brand-orange: #f39c12;
    --brand-blue: #005696; --neutral-dark: #34495e; --attente: #95a5a6;
}
html { background-color: #1a2733; }
body { margin: 0; font-family: 'Segoe UI', sans-serif; background: linear-gradient(rgba(0, 0, 0, 0.2), rgba(0, 0, 0, 0.2)), url('img/fond.jpg') no-repeat center center fixed; background-color: #1a2733; background-size: cover; min-height: 100vh; padding-top: 60px; color: var(--primary); }
@keyframes pulse-dot { 0% { transform: scale(1); opacity: 0.6; } 100% { transform: scale(2.5); opacity: 0; } }
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

html { scroll-behavior: smooth; }
@media (prefers-reduced-motion: reduce) { html { scroll-behavior: auto; } }
.main-content { max-width: 1280px; margin: 0 auto; padding: 22px 20px 70px; }
.crumb { display: flex; align-items: center; gap: 8px; font-size: 0.82rem; margin-bottom: 12px; }
.crumb a { color: #cfe4f5; text-decoration: none; font-weight: 600; }
.crumb a:hover { text-decoration: underline; }
.crumb span { color: rgba(255,255,255,0.5); }
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
.toc-card { background: rgba(255,255,255,0.97); border-radius: 14px; border-top: 4px solid var(--accent); box-shadow: 0 10px 25px rgba(0,0,0,0.4); overflow: hidden; }
@media (min-width: 980px) { .toc-card { position: sticky; top: 24px; } }
.toc-toggle { display: none; }
.toc-label { display: flex; align-items: center; justify-content: space-between; gap: 10px; cursor: pointer; padding: 12px 16px; font-weight: 700; color: var(--primary); font-size: 0.95rem; }
.toc-label span { display: flex; align-items: center; gap: 10px; }
.toc-label .toc-icon-badge { width: 30px; height: 30px; border-radius: 8px; background: rgba(52,152,219,0.12); color: var(--accent); display: flex; align-items: center; justify-content: center; font-size: 0.85rem; flex-shrink: 0; }
.toc-label .fa-chevron-down { transition: transform 0.2s ease; color: var(--accent); }
.toc-toggle:checked ~ .toc-label .fa-chevron-down { transform: rotate(180deg); }
.toc-list { list-style: none; margin: 0; padding: 0 8px 10px; display: none; }
.toc-toggle:checked ~ .toc-list { display: block; }
.toc-list a { display: flex; align-items: center; gap: 10px; padding: 9px 10px; border-radius: 8px; color: #5a6b7a; text-decoration: none; font-size: 0.86rem; font-weight: 600; transition: 0.15s; }
.toc-list a i { color: var(--accent); width: 16px; text-align: center; }
.toc-list a:hover { background: #eef4fa; color: var(--primary); }
@media (min-width: 980px) { .toc-label { display: none; } .toc-list { display: block !important; padding: 10px; } }

main.content { min-width: 0; }
.card { background: rgba(255,255,255,0.97); border-radius: 14px; box-shadow: 0 10px 28px -10px rgba(0,0,0,0.22); padding: 26px 28px; }
section.aide-sec { margin-bottom: 22px; scroll-margin-top: 24px; }
.sec-eyebrow { display: flex; align-items: center; gap: 10px; margin-bottom: 4px; }
.sec-eyebrow i { color: #fff; background: var(--accent); width: 34px; height: 34px; border-radius: 9px; display: flex; align-items: center; justify-content: center; font-size: 0.95rem; flex: none; }
.aide-sec h2 { font-family: 'Montserrat', sans-serif; font-weight: 800; font-size: 1.28rem; color: var(--primary); margin: 0; }
.lede { color: #5a6b7a; font-size: 1rem; max-width: 68ch; margin: 10px 0 18px; line-height: 1.6; }
.aide-sec p { color: #405060; line-height: 1.6; font-size: 0.94rem; }

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

.grid-2 { display: grid; grid-template-columns: 1fr; gap: 16px; margin-top: 14px; }
@media (min-width: 700px) { .grid-2 { grid-template-columns: 1fr 1fr; } }
.route-card { border: 1px solid #e6e9ec; border-radius: 12px; padding: 18px 20px; background: #fff; border-top: 4px solid var(--accent); }
.route-card.alt { border-top-color: var(--brand-orange); }
.route-tag { display: inline-block; font-size: 0.68rem; font-weight: 700; letter-spacing: 0.05em; text-transform: uppercase; color: #8a97a3; background: #f1f4f6; border-radius: 6px; padding: 3px 9px; margin-bottom: 10px; }
.route-card h3 { display: flex; align-items: center; gap: 8px; margin: 0 0 8px; font-family: 'Montserrat', sans-serif; font-size: 1rem; }
.route-card h3 i { color: var(--accent); }
.route-card.alt h3 i { color: var(--brand-orange); }
.route-card ul { margin: 8px 0 0; padding-left: 18px; }
.route-card li { font-size: 0.88rem; color: #47576a; line-height: 1.55; margin-bottom: 4px; }

.shot { margin: 16px 0; border-radius: 12px; overflow: hidden; border: 1px solid #e6e9ec; box-shadow: 0 10px 24px -12px rgba(0,0,0,0.28); background: #fff; }
.shot img { display: block; width: 100%; height: auto; }
.shot-cap { padding: 8px 14px; font-size: 0.78rem; color: #7f8c9a; background: #f7f9fb; border-top: 1px solid #e6e9ec; display: flex; align-items: center; gap: 8px; }
.shot-cap i { color: var(--accent); }
.shot.shot-narrow { max-width: 420px; margin-left: auto; margin-right: auto; }

.back-top { display: inline-flex; align-items: center; gap: 8px; margin-top: 26px; color: var(--accent); text-decoration: none; font-weight: 600; font-size: 0.85rem; }
.back-top:hover { text-decoration: underline; }
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

<?php
$retour_href = 'login.php';
$retour_label = t('aide_connexion.toc_connexion');
if (isset($_SESSION['user'])) {
    $role = strtolower($_SESSION['role'] ?? '');
    if (in_array($role, ['admin', 'technicien'])) { $retour_href = 'aide.php'; $retour_label = t('aide_hub.breadcrumb'); }
    else { $retour_href = 'accueil.php'; $retour_label = t('tag.portail_services'); }
}
?>
<a class="btn-floating-nav home" href="<?php echo $retour_href; ?>" title="<?php echo htmlspecialchars(t('aide_common.retour_accueil')); ?>" aria-label="<?php echo htmlspecialchars(t('aide_common.retour_accueil')); ?>"><i class="fa-solid fa-house"></i></a>
<div class="crumb-bar">
    <a href="<?php echo htmlspecialchars($retour_href); ?>" class="crumb-home"><?php echo htmlspecialchars($retour_label); ?></a>
    <span class="crumb-sep">/</span>
    <span class="crumb-current"><?php echo htmlspecialchars(t('aide_connexion.breadcrumb_current')); ?></span>
</div>

<div class="main-content">

    <div class="hero">
        <span class="hero-eyebrow"><i class="fa-solid fa-door-open"></i> <?php echo htmlspecialchars(t('aide_connexion.hero_eyebrow')); ?></span>
        <h1><?php echo htmlspecialchars(t('aide_connexion.hero_title')); ?></h1>
        <p class="tagline"><?php echo htmlspecialchars(t('aide_connexion.hero_tagline')); ?></p>
        <div class="hero-meta">
            <span class="hero-pill"><?php echo t('aide_connexion.pill_sans_connexion'); ?></span>
            <span class="hero-pill"><?php echo t('aide_connexion.pill_ecrans'); ?></span>
            <span class="hero-pill"><i class="fa-solid fa-camera"></i> <?php echo htmlspecialchars(t('aide_common.pill_captures')); ?></span>
            <span class="hero-pill"><i class="fa-solid fa-language"></i> <?php echo t('aide_common.pill_langues'); ?></span>
        </div>
    </div>

    <div class="layout">
        <nav class="toc-card" aria-label="<?php echo htmlspecialchars(t('aide_common.sommaire')); ?>">
            <input type="checkbox" id="toc-toggle" class="toc-toggle">
            <label for="toc-toggle" class="toc-label">
                <span><span class="toc-icon-badge"><i class="fa-solid fa-list-ul"></i></span> <?php echo htmlspecialchars(t('aide_common.sommaire')); ?></span>
                <i class="fa-solid fa-chevron-down"></i>
            </label>
            <ul class="toc-list">
                <li><a href="#modes"><i class="fa-solid fa-code-branch"></i> <?php echo htmlspecialchars(t('aide_connexion.toc_modes')); ?></a></li>
                <li><a href="#connexion"><i class="fa-solid fa-right-to-bracket"></i> <?php echo htmlspecialchars(t('aide_connexion.toc_connexion')); ?></a></li>
                <li><a href="#langue"><i class="fa-solid fa-language"></i> <?php echo htmlspecialchars(t('aide_connexion.toc_langue')); ?></a></li>
                <li><a href="#erreur"><i class="fa-solid fa-triangle-exclamation"></i> <?php echo htmlspecialchars(t('aide_connexion.toc_erreur')); ?></a></li>
                <li><a href="#accueil"><i class="fa-solid fa-house"></i> <?php echo htmlspecialchars(t('aide_connexion.toc_accueil')); ?></a></li>
            </ul>
        </nav>

        <main class="content">
            <div class="card">

                <section id="modes" class="aide-sec">
                    <div class="sec-eyebrow"><i class="fa-solid fa-code-branch"></i><h2><?php echo htmlspecialchars(t('aide_connexion.toc_modes')); ?></h2></div>
                    <p class="lede"><?php echo htmlspecialchars(t('aide_connexion.modes_lede')); ?></p>

                    <div class="grid-2">
                        <div class="route-card">
                            <span class="route-tag"><?php echo htmlspecialchars(t('aide_connexion.route_maint_tag')); ?></span>
                            <h3><i class="fa-solid fa-screwdriver-wrench"></i> <?php echo htmlspecialchars(t('aide_connexion.route_maint_title')); ?></h3>
                            <ul>
                                <li><?php echo t('aide_connexion.route_maint_li1'); ?></li>
                                <li><?php echo t('aide_connexion.route_maint_li2'); ?></li>
                                <li><?php echo t('aide_connexion.route_maint_li3'); ?></li>
                            </ul>
                        </div>
                        <div class="route-card alt">
                            <span class="route-tag"><?php echo htmlspecialchars(t('aide_connexion.route_service_tag')); ?></span>
                            <h3><i class="fa-solid fa-comments"></i> <?php echo htmlspecialchars(t('aide_connexion.route_service_title')); ?></h3>
                            <ul>
                                <li><?php echo t('aide_connexion.route_service_li1'); ?></li>
                                <li><?php echo t('aide_connexion.route_service_li2'); ?></li>
                                <li><?php echo t('aide_connexion.route_service_li3'); ?></li>
                            </ul>
                        </div>
                    </div>

                    <div class="callout callout-warn">
                        <i class="fa-solid fa-signature"></i>
                        <div><p><?php echo t('aide_connexion.modes_warn'); ?></p></div>
                    </div>
                </section>

                <section id="connexion" class="aide-sec">
                    <div class="sec-eyebrow"><i class="fa-solid fa-right-to-bracket"></i><h2><?php echo htmlspecialchars(t('aide_connexion.toc_connexion')); ?></h2></div>
                    <p class="lede"><?php echo htmlspecialchars(t('aide_connexion.connexion_lede')); ?></p>

                    <div class="shot shot-narrow">
                        <img src="img/aide/connexion_page_login.png" alt="<?php echo htmlspecialchars(t('aide_connexion.shot_login_alt')); ?>">
                        <div class="shot-cap"><i class="fa-solid fa-camera"></i> <?php echo htmlspecialchars(t('aide_connexion.shot_login_cap')); ?></div>
                    </div>

                    <div class="steps">
                        <div class="step">
                            <div class="step-num">1</div>
                            <div><h4><?php echo htmlspecialchars(t('aide_connexion.step1_title')); ?></h4>
                            <p><?php echo t('aide_connexion.step1_desc'); ?></p></div>
                        </div>
                        <div class="step">
                            <div class="step-num">2</div>
                            <div><h4><?php echo htmlspecialchars(t('aide_connexion.step2_title')); ?></h4>
                            <p><?php echo htmlspecialchars(t('aide_connexion.step2_desc')); ?></p></div>
                        </div>
                        <div class="step">
                            <div class="step-num">3</div>
                            <div><h4><?php echo htmlspecialchars(t('aide_connexion.step3_title')); ?></h4>
                            <p><?php echo htmlspecialchars(t('aide_connexion.step3_desc')); ?></p></div>
                        </div>
                    </div>

                    <div class="callout callout-tip">
                        <i class="fa-solid fa-lightbulb"></i>
                        <div><p><?php echo t('aide_connexion.tip_code'); ?></p></div>
                    </div>
                </section>

                <section id="langue" class="aide-sec">
                    <div class="sec-eyebrow"><i class="fa-solid fa-language"></i><h2><?php echo htmlspecialchars(t('aide_connexion.toc_langue')); ?></h2></div>
                    <p class="lede"><?php echo htmlspecialchars(t('aide_connexion.langue_lede')); ?></p>

                    <div class="steps">
                        <div class="step">
                            <div class="step-num">1</div>
                            <div><h4><?php echo htmlspecialchars(t('aide_connexion.langue_step1_title')); ?></h4>
                            <p><?php echo htmlspecialchars(t('aide_connexion.langue_step1_desc')); ?></p></div>
                        </div>
                        <div class="step">
                            <div class="step-num">2</div>
                            <div><h4><?php echo htmlspecialchars(t('aide_connexion.langue_step2_title')); ?></h4>
                            <p><?php echo htmlspecialchars(t('aide_connexion.langue_step2_desc')); ?></p></div>
                        </div>
                        <div class="step">
                            <div class="step-num">3</div>
                            <div><h4><?php echo htmlspecialchars(t('aide_connexion.langue_step3_title')); ?></h4>
                            <p><?php echo htmlspecialchars(t('aide_connexion.langue_step3_desc')); ?></p></div>
                        </div>
                    </div>

                    <div class="callout callout-tip">
                        <i class="fa-solid fa-circle-info"></i>
                        <div><p><?php echo htmlspecialchars(t('aide_connexion.langue_tip')); ?></p></div>
                    </div>
                </section>

                <section id="erreur" class="aide-sec">
                    <div class="sec-eyebrow"><i class="fa-solid fa-triangle-exclamation"></i><h2><?php echo htmlspecialchars(t('aide_connexion.toc_erreur')); ?></h2></div>
                    <p class="lede"><?php echo htmlspecialchars(t('aide_connexion.erreur_lede')); ?></p>

                    <div class="shot shot-narrow">
                        <img src="img/aide/connexion_erreur.png" alt="<?php echo htmlspecialchars(t('aide_connexion.shot_erreur_alt')); ?>">
                        <div class="shot-cap"><i class="fa-solid fa-camera"></i> <?php echo htmlspecialchars(t('aide_connexion.shot_erreur_cap')); ?></div>
                    </div>

                    <div class="callout callout-warn">
                        <i class="fa-solid fa-triangle-exclamation"></i>
                        <div>
                            <p><?php echo htmlspecialchars(t('aide_connexion.erreur_check_intro')); ?></p>
                            <p><?php echo t('aide_connexion.erreur_check_list'); ?></p>
                            <p><?php echo htmlspecialchars(t('aide_connexion.erreur_contact')); ?></p>
                        </div>
                    </div>
                </section>

                <section id="accueil" class="aide-sec" style="margin-bottom:0;">
                    <div class="sec-eyebrow"><i class="fa-solid fa-house"></i><h2><?php echo htmlspecialchars(t('aide_connexion.toc_accueil')); ?></h2></div>
                    <p class="lede"><?php echo htmlspecialchars(t('aide_connexion.accueil_lede')); ?></p>

                    <h3><i class="fa-solid fa-screwdriver-wrench" style="color:var(--accent); margin-right:6px;"></i><?php echo htmlspecialchars(t('aide_connexion.accueil_maint_h3')); ?></h3>
                    <p><?php echo t('aide_connexion.accueil_maint_p'); ?></p>

                    <div class="shot">
                        <img src="img/aide/connexion_accueil_tuiles.png" alt="<?php echo htmlspecialchars(t('aide_connexion.shot_accueil_tuiles_alt')); ?>">
                        <div class="shot-cap"><i class="fa-solid fa-camera"></i> <?php echo htmlspecialchars(t('aide_connexion.shot_accueil_tuiles_cap')); ?></div>
                    </div>

                    <div class="callout callout-lock">
                        <i class="fa-solid fa-lock"></i>
                        <div><p><?php echo t('aide_connexion.accueil_lock_note'); ?></p></div>
                    </div>

                    <div class="callout callout-tip">
                        <i class="fa-solid fa-bars"></i>
                        <div><p><?php echo t('aide_connexion.accueil_menu_tip'); ?></p></div>
                    </div>

                    <h3 style="margin-top:24px;"><i class="fa-solid fa-comments" style="color:var(--brand-orange); margin-right:6px;"></i><?php echo htmlspecialchars(t('aide_connexion.accueil_service_h3')); ?></h3>
                    <p><?php echo t('aide_connexion.accueil_service_p'); ?></p>

                    <div class="shot">
                        <img src="img/aide/connexion_accueil_service_tuiles.png" alt="<?php echo htmlspecialchars(t('aide_connexion.shot_accueil_service_alt')); ?>">
                        <div class="shot-cap"><i class="fa-solid fa-camera"></i> <?php echo htmlspecialchars(t('aide_connexion.shot_accueil_service_cap')); ?></div>
                    </div>

                    <div class="callout callout-tip">
                        <i class="fa-solid fa-circle-info"></i>
                        <div><p><?php echo t('aide_connexion.accueil_service_tip'); ?></p></div>
                    </div>
                </section>

            </div>
        </main>
    </div>
</div>

</body>
</html>
