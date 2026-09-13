<?php
require_once __DIR__ . '/session_init.php';

if (!isset($_SESSION['user'])) {
    header("Location: login.php");
    exit();
}

$role = $_SESSION['role'] ?? '';
$is_admin = ($role === 'admin');
$is_gmao = in_array($role, ['admin', 'technicien']); // Équipe maintenance (accès à index.php et ses pages)
$hide_menu_button = true;

// Centre d'aide unique : tout le monde voit toutes les tuiles, mais celles qui documentent
// une page à laquelle le compte connecté n'a pas accès sont grisées + verrouillées plutôt
// que masquées — pour que chacun sache que la fonction existe, sans pouvoir l'ouvrir.
// 'all'   = ouvert à tous (portail services compris)
// 'gmao'  = admin + technicien uniquement (même accès que index.php)
// 'admin' = admin uniquement
$aide_tuiles = [
    ['href' => 'aide_connexion.php', 'icon' => 'fa-door-open', 'titre' => t('aide_hub.tuile_connexion_titre'), 'desc' => t('aide_hub.tuile_connexion_desc'), 'tags' => [t('tag.connexion'), t('tag.accueil'), t('tag.tous_roles')], 'access' => 'all'],
    ['href' => 'aide_ot.php', 'icon' => 'fa-pen-to-square', 'titre' => t('aide_hub.tuile_ot_titre'), 'desc' => t('aide_hub.tuile_ot_desc'), 'tags' => [t('tag.saisie_historique'), t('tag.equipe_maintenance')], 'access' => 'gmao'],
    ['href' => 'aide_planning.php', 'icon' => 'fa-calendar-days', 'titre' => t('aide_hub.tuile_planning_titre'), 'desc' => t('aide_hub.tuile_planning_desc'), 'tags' => [t('tag.planning'), t('tag.equipe_maintenance')], 'access' => 'gmao'],
    ['href' => 'aide_service.php', 'icon' => 'fa-comments', 'titre' => t('aide_hub.tuile_service_titre'), 'desc' => t('aide_hub.tuile_service_desc'), 'tags' => [t('tag.portail_services'), t('tag.tous_services')], 'access' => 'all'],
    ['href' => 'aide_preventif.php', 'icon' => 'fa-calendar-check', 'titre' => t('aide_hub.tuile_preventif_titre'), 'desc' => t('aide_hub.tuile_preventif_desc'), 'tags' => [t('tag.preventif'), t('tag.planning')], 'access' => 'admin'],
    ['href' => 'aide_sous_traitants.php', 'icon' => 'fa-users-gear', 'titre' => t('aide_hub.tuile_sous_traitants_titre'), 'desc' => t('aide_hub.tuile_sous_traitants_desc'), 'tags' => [t('tag.sous_traitants'), t('tag.equipe_maintenance')], 'access' => 'gmao'],
    ['href' => 'aide_parc_machine.php', 'icon' => 'fa-gears', 'titre' => t('aide_hub.tuile_parc_machine_titre'), 'desc' => t('aide_hub.tuile_parc_machine_desc'), 'tags' => [t('tag.parc_machine'), t('tag.equipe_maintenance')], 'access' => 'gmao'],
    ['href' => 'aide_utilisateurs.php', 'icon' => 'fa-user-shield', 'titre' => t('aide_hub.tuile_utilisateurs_titre'), 'desc' => t('aide_hub.tuile_utilisateurs_desc'), 'tags' => [t('tag.gestion_utilisateurs')], 'access' => 'admin'],
    ['href' => 'aide_parametres.php', 'icon' => 'fa-sliders', 'titre' => t('aide_hub.tuile_parametres_titre'), 'desc' => t('aide_hub.tuile_parametres_desc'), 'tags' => [t('tag.parametres')], 'access' => 'admin'],
    ['href' => 'aide_logs.php', 'icon' => 'fa-clock-rotate-left', 'titre' => t('aide_hub.tuile_logs_titre'), 'desc' => t('aide_hub.tuile_logs_desc'), 'tags' => [t('tag.journal_activite')], 'access' => 'admin'],
    ['href' => 'aide_annualisation.php', 'icon' => 'fa-scale-balanced', 'titre' => t('aide_hub.tuile_annualisation_titre'), 'desc' => t('aide_hub.tuile_annualisation_desc'), 'tags' => [t('tag.planning'), t('tag.annualisation')], 'access' => 'gmao'],
    ['href' => 'aide_kpi.php', 'icon' => 'fa-chart-line', 'titre' => t('aide_hub.tuile_kpi_titre'), 'desc' => t('aide_hub.tuile_kpi_desc'), 'tags' => [t('tag.kpi'), t('tag.stats_techniciens')], 'access' => 'admin'],
];
foreach ($aide_tuiles as &$t) {
    $t['locked'] = ($t['access'] === 'admin' && !$is_admin) || ($t['access'] === 'gmao' && !$is_gmao);
    $t['lock_msg'] = ($t['access'] === 'admin') ? t('aide_hub.lock_admin') : t('aide_hub.lock_gmao');
}
unset($t);

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(langue_actuelle()); ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
<title><?php echo htmlspecialchars(t('aide_hub.page_title_tag')); ?></title>
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
header { position: fixed; top: 0; width: 100%; z-index: 1000; overflow: hidden; box-shadow: 0 2px 15px rgba(0,0,0,0.15); }
header::before { content: ""; position: absolute; top: -12px; left: -12px; right: -12px; bottom: -12px; background: linear-gradient(rgba(0, 0, 0, 0.2), rgba(0, 0, 0, 0.2)), url('img/fond.jpg') no-repeat center center fixed; background-size: cover; filter: blur(4px); z-index: -1; }
.crumb-bar { display: flex; align-items: center; gap: 8px; font-size: 0.94rem; font-weight: 600; color: rgba(255,255,255,0.9); text-shadow: 0 1px 3px rgba(0,0,0,0.4); max-width: 99%; margin: 0 auto; padding: 0 10px 10px; }
.crumb-bar .crumb-home { color: inherit; text-decoration: none; display: inline-flex; align-items: center; gap: 5px; }
.crumb-bar .crumb-home:hover { text-decoration: underline; }
.crumb-bar .crumb-parent { color: inherit; text-decoration: none; }
.crumb-bar .crumb-parent:hover { text-decoration: underline; }
.crumb-sep { color: rgba(255,255,255,0.45); font-weight: 400; }
.crumb-current { color: rgba(255,255,255,0.75); font-weight: 400; }
.header-top { display: flex; justify-content: space-between; align-items: center; padding: 8px 20px; }
.header-title { font-family: 'Caveat', cursive; font-size: 1.6rem; color: white; }
.nav-tabs { display: flex; background: #fff; padding: 0 10px; gap: 2px; overflow-x: auto; }
.tab-item { padding: 10px 18px; text-decoration: none; color: #7f8c8d; font-weight: 600; font-size: 0.8rem; border-bottom: 3px solid transparent; transition: 0.3s; display: flex; align-items: center; gap: 8px; white-space: nowrap; }
.tab-item:hover { background: rgba(0,0,0,0.02); }
.tab-item.active { color: var(--accent); border-bottom: 3px solid var(--accent); background: rgba(52, 152, 219, 0.05); }
.user-badge { background: rgba(255,255,255,0.12); backdrop-filter: blur(14px) brightness(1.15); -webkit-backdrop-filter: blur(14px) brightness(1.15); padding: 5px 12px; border-radius: 20px; font-size: 0.75rem; font-weight: 600; display: flex; align-items: center; gap: 8px; color: white; border: 1px solid rgba(255,255,255,0.4); text-shadow: 0 1px 3px rgba(0,0,0,0.4); box-shadow: 0 6px 16px rgba(0,0,0,0.2); }
.btn-accueil { font-size: 16px; color: white; text-decoration: none; display: flex; align-items: center; justify-content: center; width: 34px; height: 34px; border-radius: 50%; background: rgba(255,255,255,0.12); backdrop-filter: blur(14px) brightness(1.15); -webkit-backdrop-filter: blur(14px) brightness(1.15); border: 1px solid rgba(255,255,255,0.4); box-shadow: 0 6px 16px rgba(0,0,0,0.2); transition: transform 0.2s, background 0.2s; }
.btn-accueil:hover { background: rgba(255,255,255,0.28); transform: translateY(-2px); }
.btn-accueil-green:hover { background: rgba(46, 204, 113, 0.15); border-color: rgba(46, 204, 113, 0.5); color: var(--gelpam-green); }
.status-pulse { width: 8px; height: 8px; border-radius: 50%; position: relative; background: var(--success); }
.status-pulse::after { content: ""; position: absolute; width: 100%; height: 100%; border-radius: 50%; background: inherit; animation: pulse-dot 2s infinite; opacity: 0.6; }
.sidebar { height: 100%; width: 0; position: fixed; z-index: 3000; top: 0; left: 0; background-color: #1a252f; overflow-x: hidden; overflow-y: auto; transition: 0.4s; padding-top: 60px; padding-bottom: 20px; }
.sidebar a { padding: 12px 25px; text-decoration: none; font-size: 1.05rem; color: #ecf0f1; display: block; transition: 0.3s; border-left: 4px solid transparent; }
.sidebar a:hover { background: #2c3e50; border-left: 4px solid var(--accent); }
.sidebar a.active-side { background: #2c3e50; border-left: 4px solid var(--accent); color: var(--accent); font-weight: bold; }
.sidebar .closebtn { position: absolute; top: 10px; right: 25px; font-size: 36px; color: white; border: none; background: none; cursor: pointer; }
.openbtn { font-size: 22px; cursor: pointer; background: none; border: none; color: var(--primary); padding: 5px 10px; }

.main-content { max-width: 1180px; margin: 0 auto; padding: 22px 20px 70px; }
.hero { background: linear-gradient(120deg, var(--primary) 0%, #1a2733 100%); border-radius: 18px; padding: 34px 38px; color: #fff; position: relative; overflow: hidden; box-shadow: 0 18px 40px -14px rgba(0,0,0,0.45); margin-bottom: 28px; }
.hero::after { content: ""; position: absolute; right: -60px; top: -60px; width: 260px; height: 260px; border-radius: 50%; background: radial-gradient(circle, rgba(52,152,219,0.35), transparent 70%); }
.hero-eyebrow { display: inline-flex; align-items: center; gap: 8px; font-size: 0.72rem; font-weight: 700; letter-spacing: 0.08em; text-transform: uppercase; background: rgba(255,255,255,0.12); border: 1px solid rgba(255,255,255,0.25); padding: 6px 14px; border-radius: 999px; margin-bottom: 16px; }
.hero h1 { font-family: 'Montserrat', sans-serif; font-weight: 900; font-size: clamp(1.7rem, 3vw, 2.35rem); margin: 0 0 6px; letter-spacing: -0.01em; }
.hero .tagline { font-family: 'Caveat', cursive; font-size: 1.35rem; color: #cfe4f5; margin: 0; }

.section-label { font-family: 'Montserrat', sans-serif; font-weight: 800; font-size: 0.78rem; letter-spacing: 0.08em; text-transform: uppercase; color: #5a6b7a; margin: 0 0 14px; display: flex; align-items: center; gap: 10px; }
.section-label::after { content: ""; flex: 1; height: 1px; background: rgba(255,255,255,0.5); }

.art-grid { display: grid; grid-template-columns: 1fr; gap: 18px; margin-bottom: 34px; }
@media (min-width: 720px) { .art-grid { grid-template-columns: 1fr 1fr; } }
@media (min-width: 1080px) { .art-grid { grid-template-columns: 1fr 1fr; } }

.art-card { background: rgba(255,255,255,0.97); border-radius: 16px; box-shadow: 0 12px 30px -12px rgba(0,0,0,0.3); padding: 24px 26px; text-decoration: none; color: var(--primary); display: flex; flex-direction: column; gap: 10px; transition: transform 0.18s ease, box-shadow 0.18s ease; position: relative; overflow: hidden; border-top: 4px solid var(--accent); }
.art-card:hover { transform: translateY(-3px); box-shadow: 0 18px 40px -14px rgba(0,0,0,0.4); }
.art-card .art-icon { width: 46px; height: 46px; border-radius: 12px; background: #eaf3fb; color: var(--accent); display: flex; align-items: center; justify-content: center; font-size: 1.3rem; }
.art-card h2 { font-family: 'Montserrat', sans-serif; font-weight: 800; font-size: 1.12rem; margin: 4px 0 0; }
.art-card p { margin: 0; font-size: 0.9rem; color: #5a6b7a; line-height: 1.55; }
.art-card .art-tags { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 4px; }
.art-tag { font-size: 0.66rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.04em; background: #f1f4f6; color: #7f8c9a; border-radius: 6px; padding: 3px 8px; }
.art-cta { margin-top: 8px; font-size: 0.84rem; font-weight: 700; color: var(--accent); display: flex; align-items: center; gap: 6px; }
.art-badge { position: absolute; top: 16px; right: 16px; font-family: 'Montserrat', sans-serif; font-size: 0.65rem; font-weight: 800; text-transform: uppercase; letter-spacing: 0.04em; padding: 4px 10px; border-radius: 999px; }
.badge-live { background: #e4f7ea; color: #1f8a4c; }
.badge-admin { background: #fdf1e0; color: #b9720c; }
.badge-locked { background: #eef1f3; color: #7f8c9a; }

.art-card.locked { cursor: default; opacity: 0.6; filter: grayscale(0.5); }
.art-card.locked:hover { transform: none; box-shadow: 0 12px 30px -12px rgba(0,0,0,0.3); }
.art-card.locked .art-icon { background: #f1f4f6; color: #9aa7b2; }
.lock-note { margin-top: 8px; font-size: 0.82rem; font-weight: 700; color: #9aa7b2; display: flex; align-items: center; gap: 6px; }

.art-card.soon { border-top-color: #cdd5db; cursor: default; opacity: 0.85; }
.art-card.soon:hover { transform: none; box-shadow: 0 12px 30px -12px rgba(0,0,0,0.3); }
.art-card.soon .art-icon { background: #f1f4f6; color: #9aa7b2; }
.badge-soon { background: #eef1f3; color: #7f8c9a; }

.help-footer { background: rgba(255,255,255,0.9); border-radius: 14px; padding: 18px 22px; font-size: 0.86rem; color: #5a6b7a; display: flex; align-items: center; gap: 12px; }
.help-footer i { color: var(--accent); font-size: 1.2rem; }

</style>
<?php include 'pwa_head.php'; ?>
</head>
<body onclick="checkCloseSidebar(event)">

<?php $breadcrumb_label = t('aide_hub.breadcrumb'); include 'navbar.php'; ?>

<div class="main-content">

    <div class="hero">
        <span class="hero-eyebrow"><i class="fa-solid fa-book-open"></i> <?php echo htmlspecialchars(t('aide_hub.hero_eyebrow')); ?></span>
        <h1><?php echo htmlspecialchars(t('aide_hub.hero_title')); ?></h1>
        <p class="tagline"><?php echo htmlspecialchars(t('aide_hub.hero_tagline')); ?></p>
        <span class="hero-eyebrow" style="margin-bottom:0;"><i class="fa-solid fa-language"></i> <?php echo t('aide_hub.hero_lang_note'); ?></span>
    </div>

    <div class="section-label"><?php echo htmlspecialchars(t('aide_hub.section_fonctions')); ?></div>
    <div class="art-grid">

        <?php foreach ($aide_tuiles as $t): $tag = $t['locked'] ? 'div' : 'a'; ?>
        <<?php echo $tag; ?><?php if (!$t['locked']): ?> href="<?php echo htmlspecialchars($t['href']); ?>"<?php endif; ?> class="art-card<?php echo $t['locked'] ? ' locked' : ''; ?>">
            <?php if ($t['locked']): ?>
                <span class="art-badge badge-locked"><i class="fa-solid fa-lock"></i></span>
            <?php elseif ($t['access'] === 'admin'): ?>
                <span class="art-badge badge-admin"><?php echo htmlspecialchars(t('aide_hub.badge_admin')); ?></span>
            <?php else: ?>
                <span class="art-badge badge-live"><?php echo htmlspecialchars(t('aide_hub.badge_disponible')); ?></span>
            <?php endif; ?>
            <div class="art-icon"><i class="fa-solid <?php echo htmlspecialchars($t['icon']); ?>"></i></div>
            <h2><?php echo htmlspecialchars($t['titre']); ?></h2>
            <p><?php echo htmlspecialchars($t['desc']); ?></p>
            <div class="art-tags"><?php foreach ($t['tags'] as $tg) echo '<span class="art-tag">' . htmlspecialchars($tg) . '</span>'; ?></div>
            <?php if ($t['locked']): ?>
                <div class="lock-note"><i class="fa-solid fa-lock"></i> <?php echo htmlspecialchars($t['lock_msg']); ?></div>
            <?php else: ?>
                <div class="art-cta"><?php echo htmlspecialchars(t('aide_hub.cta_lire')); ?> <i class="fa-solid fa-arrow-right"></i></div>
            <?php endif; ?>
        </<?php echo $tag; ?>>
        <?php endforeach; ?>

    </div>

    <div class="section-label"><?php echo htmlspecialchars(t('aide_hub.section_a_venir')); ?></div>
    <div class="art-grid" style="margin-bottom:22px;">
        <div class="art-card soon">
            <span class="art-badge badge-soon"><?php echo htmlspecialchars(t('aide_hub.badge_bientot')); ?></span>
            <div class="art-icon"><i class="fa-solid fa-boxes-stacked"></i></div>
            <h2><?php echo htmlspecialchars(t('aide_hub.soon_titre')); ?></h2>
            <p><?php echo htmlspecialchars(t('aide_hub.soon_desc')); ?></p>
        </div>
    </div>

    <div class="help-footer">
        <i class="fa-solid fa-circle-info"></i>
        <span><?php echo htmlspecialchars(t('aide_hub.footer_text')); ?></span>
    </div>

</div>

<script>
function openNav(e) { if (e) e.stopPropagation(); document.getElementById("mySidebar").style.width = "280px"; }
function closeNav() { document.getElementById("mySidebar").style.width = "0"; }
function checkCloseSidebar(event) { if (document.getElementById("mySidebar").style.width === "280px" && !document.getElementById("mySidebar").contains(event.target)) closeNav(); }
</script>

</body>
</html>
