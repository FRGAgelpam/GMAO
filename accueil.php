<?php
require_once __DIR__ . '/session_init.php';
require_once 'db.php';

if (!isset($_SESSION['user'])) { header("Location: login.php"); exit(); }

$vient_de_gmao = in_array($_SESSION['role'] ?? '', ['admin', 'technicien']);

$user_session = trim($_SESSION['user']);

// --- LOGO (configurable depuis Paramètres > Général, même pattern que navbar.php) ---
$logo_path_accueil = "img/logo.png";
try {
    $general_accueil = $db->query("SELECT cle, valeur FROM parametres_general")->fetchAll(PDO::FETCH_KEY_PAIR);
    if (!empty($general_accueil['logo_path'])) { $logo_path_accueil = $general_accueil['logo_path']; }
} catch (Exception $e) {}

// --- Récupération des IDs de tickets du service (pour le badge de notifications) ---
$mes_ticket_ids = [];
try {
    if (isset($db)) {
        $nom_service = str_replace('S. ', '', $user_session);
        // Même logique que suivi.php : on ne cherche le nom du service que dans la parenthèse de
        // fonction du motif "DEMANDE DE : Nom (Fonction) - ...", jamais dans tout le texte libre,
        // pour ne pas faire remonter des tickets sans lien juste parce qu'ils mentionnent le mot.
        $motCle = (stripos($nom_service, 'agro') !== false) ? 'Agro' : $nom_service;

        // Un OT préventif est généré automatiquement (jamais demandé par un service externe) :
        // on l'exclut toujours, même si sa description/localisation mentionne le service par coïncidence.
        $stmtIds = $db->prepare("
            SELECT id FROM taches
            WHERE (demandeur = ? OR description LIKE ?)
            AND (type IS NULL OR type <> 'Préventif')
        ");
        $stmtIds->execute([
            $user_session,
            'DEMANDE DE :%(%' . $motCle . '%)%'
        ]);
        $mes_ticket_ids = $stmtIds->fetchAll(PDO::FETCH_COLUMN);
    }
} catch (Exception $e) {}

function safe_json($data) {
    $json = json_encode($data, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
    return ($json === false || $json === 'null' || empty($json)) ? '[]' : $json;
}
$json_ticket_ids = safe_json($mes_ticket_ids);
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(langue_actuelle()); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title><?php echo htmlspecialchars(t('accueil.page_title')); ?></title>
    <link rel="icon" type="image/png" href="img/logo.png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Caveat:wght@600;700&family=Segoe+UI:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root { --primary: #2c3e50; --accent: #3498db; --brand-orange: #f39c12; --brand-green: #2ecc71; --danger: #e74c3c; --violet: #8e44ad; }
        * { box-sizing: border-box; }
        body {
            margin: 0; font-family: 'Segoe UI', sans-serif;
            background: linear-gradient(rgba(0,0,0,0.5), rgba(0,0,0,0.5)), url('img/fond.jpg') no-repeat center center fixed;
            background-size: cover; min-height: 100vh; padding: 30px 20px;
        }

        .page-wrap { max-width: 1040px; margin: 0 auto; }
        .crumb-bar { display: flex; align-items: center; gap: 8px; font-size: 0.94rem; font-weight: 600; color: rgba(255,255,255,0.9); text-shadow: 0 1px 3px rgba(0,0,0,0.4); margin: 0 0 14px; }
        .crumb-bar .crumb-home { color: inherit; text-decoration: none; display: inline-flex; align-items: center; gap: 5px; }
        .crumb-bar .crumb-home:hover { text-decoration: underline; }
        .crumb-sep { color: rgba(255,255,255,0.55); font-weight: 400; }
        .crumb-current { color: rgba(255,255,255,0.75); font-weight: 400; }

        .topbar {
            background: rgba(255,255,255,0.97); border-radius: 16px; box-shadow: 0 10px 30px rgba(0,0,0,0.4);
            padding: 14px 24px; display: flex; align-items: center; justify-content: space-between; margin-bottom: 30px;
        }
        .brand { display:flex; align-items:center; gap: 12px; }
        .brand img { height: 38px; }
        .brand h1 { font-family: 'Caveat', cursive; color: var(--primary); font-size: 1.6rem; margin: 0; line-height: 1; }
        .who { display:flex; align-items:center; gap: 16px; }
        .who-badge { background:#f1f5f9; padding: 6px 14px; border-radius: 20px; font-size: 0.8rem; font-weight: 600; color: var(--primary); }

        .hero { text-align:center; margin-bottom: 30px; color: #fff; }
        .hero h2 { font-family: 'Caveat', cursive; font-size: 2.3rem; margin: 0 0 4px; text-shadow: 0 2px 10px rgba(0,0,0,0.4); }
        .hero p { font-size: 0.95rem; opacity: 0.9; margin: 0; }

        .tiles-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(230px, 1fr)); gap: 20px; }

        .tile {
            background: rgba(255,255,255,0.05);
            backdrop-filter: blur(10px) brightness(1.15);
            -webkit-backdrop-filter: blur(10px) brightness(1.15);
            border-radius: 16px; padding: 24px 22px 20px;
            box-shadow: 0 10px 25px rgba(0,0,0,0.25); text-decoration:none; color: #fff;
            display:flex; flex-direction:column; gap: 10px; position: relative; overflow:hidden;
            border: 1px solid rgba(255,255,255,0.35);
            border-top: 4px solid var(--tile-accent, var(--accent));
            transition: transform 0.3s ease, box-shadow 0.3s ease;
        }
        .tile:hover {
            transform: translateY(-8px);
            box-shadow:
                0 -12px 26px -10px rgba(var(--tile-rgb, 52, 152, 219), 0.5),
                0 24px 40px -8px rgba(var(--tile-rgb, 52, 152, 219), 0.6),
                0 8px 18px rgba(0,0,0,0.22);
        }

        .tile-top { display:flex; align-items:center; justify-content:center; }
        .tile-icon {
            width: 48px; height: 48px; border-radius: 50%; display:flex; align-items:center; justify-content:center;
            background: var(--tile-accent, var(--accent));
            color: #fff; font-size: 1.3rem; flex-shrink:0;
            box-shadow: 0 6px 15px -3px rgba(0,0,0,0.4);
        }
        .tile-badge {
            position: absolute; top: 14px; right: 14px;
            font-size: 0.68rem; font-weight: 700; padding: 3px 9px; border-radius: 20px;
            background: var(--danger); color: #fff; white-space: nowrap; display:none;
        }
        .tile-title { font-size: 1.08rem; font-weight: 700; margin: 2px 0 0; color: #fff; text-shadow: 0 1px 5px rgba(0,0,0,0.5); }
        .tile-desc { font-size: 0.82rem; color: rgba(255,255,255,0.88); line-height: 1.45; margin: 0; text-shadow: 0 1px 4px rgba(0,0,0,0.45); }
        .tile-cta {
            margin-top: auto; padding-top: 8px; font-size: 0.72rem; font-weight: 700; text-transform: uppercase;
            letter-spacing: 0.03em; color: var(--tile-accent, var(--accent)); display:flex; align-items:center; gap:6px;
        }
        .tile-cta i { transition: transform 0.2s ease; }
        .tile:hover .tile-cta i { transform: translateX(3px); }

        .tile.t-demande { --tile-accent: var(--brand-green); --tile-rgb: 46, 204, 113; }
        .tile.t-suivi { --tile-accent: var(--accent); --tile-rgb: 52, 152, 219; }
        .tile.t-idee { --tile-accent: var(--brand-orange); --tile-rgb: 243, 156, 18; }
        .tile.t-aide { --tile-accent: var(--violet); --tile-rgb: 142, 68, 173; }

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
        .btn-floating-nav.logout:hover { background: rgba(231,76,60,0.3); border-color: rgba(231,76,60,0.6); box-shadow: 0 14px 26px -8px rgba(231,76,60,0.5), 0 8px 18px rgba(0,0,0,0.2); }
        .btn-floating-nav.home:hover { background: rgba(46,204,113,0.3); border-color: rgba(46,204,113,0.6); box-shadow: 0 14px 26px -8px rgba(46,204,113,0.5), 0 8px 18px rgba(0,0,0,0.2); }

        @media (max-width: 600px) {
            .topbar { flex-direction: column; gap: 10px; }
        }
        /* .btn-floating-nav est fixe en haut à gauche (top:20/left:20, 46px) ; .page-wrap est centré via
           margin:0 auto donc ne s'approche de ce coin que si le viewport est plus étroit que sa max-width
           (1040px) — sous ce seuil, le crumb-bar (1er élément, même coin) passe derrière le bouton. */
        @media (max-width: 1080px) {
            .crumb-bar { margin-top: 56px; }
        }
    </style>
<?php include 'pwa_head.php'; ?>
</head>
<body>

<?php if ($vient_de_gmao): ?>
<a href="index.php" class="btn-floating-nav home" title="<?php echo htmlspecialchars(t('accueil.back_to_gmao')); ?>" aria-label="<?php echo htmlspecialchars(t('accueil.back_to_gmao')); ?>"><i class="fa-solid fa-house"></i></a>
<?php else: ?>
<a href="logout.php" class="btn-floating-nav logout" title="<?php echo htmlspecialchars(t('accueil.logout')); ?>" aria-label="<?php echo htmlspecialchars(t('accueil.logout')); ?>"><i class="fa-solid fa-right-from-bracket"></i></a>
<?php endif; ?>

<div style="position:fixed; top:20px; right:20px; z-index:1000;">
    <?php include 'lang_switcher.php'; ?>
</div>

<div class="page-wrap">
    <div class="crumb-bar">
        <?php if ($vient_de_gmao): ?>
        <a href="index.php" class="crumb-home"><i class="fa-solid fa-house"></i> <?php echo htmlspecialchars(t('nav.home')); ?></a>
        <span class="crumb-sep">/</span>
        <?php endif; ?>
        <span class="crumb-current"><?php echo htmlspecialchars(t('accueil.crumb_current')); ?></span>
    </div>
    <div class="topbar">
        <div class="brand">
            <img src="<?php echo htmlspecialchars($logo_path_accueil); ?>" alt="Logo">
            <h1><?php echo htmlspecialchars(t('accueil.heading')); ?></h1>
        </div>
        <div class="who">
            <span class="who-badge"><i class="fa-solid fa-building"></i> <?php echo htmlspecialchars($_SESSION['user']); ?></span>
        </div>
    </div>

    <div class="hero">
        <h2><?php echo htmlspecialchars(t('accueil.greeting')); ?></h2>
        <p><?php echo htmlspecialchars(t('accueil.subtitle')); ?></p>
    </div>

    <div class="tiles-grid">
        <a class="tile t-demande" href="demande.php">
            <div class="tile-top">
                <div class="tile-icon"><i class="fa-solid fa-screwdriver-wrench"></i></div>
            </div>
            <div class="tile-title"><?php echo htmlspecialchars(t('accueil.tile_demande_title')); ?></div>
            <p class="tile-desc"><?php echo htmlspecialchars(t('accueil.tile_demande_desc')); ?></p>
            <span class="tile-cta"><?php echo htmlspecialchars(t('accueil.tile_demande_cta')); ?> <i class="fa-solid fa-arrow-right"></i></span>
        </a>

        <a class="tile t-suivi" href="suivi.php">
            <div class="tile-top">
                <div class="tile-icon"><i class="fa-solid fa-satellite-dish"></i></div>
                <span class="tile-badge" id="badge-suivi">0</span>
            </div>
            <div class="tile-title"><?php echo htmlspecialchars(t('accueil.tile_suivi_title')); ?></div>
            <p class="tile-desc"><?php echo htmlspecialchars(t('accueil.tile_suivi_desc')); ?></p>
            <span class="tile-cta"><?php echo htmlspecialchars(t('accueil.tile_suivi_cta')); ?> <i class="fa-solid fa-arrow-right"></i></span>
        </a>

        <a class="tile t-idee" href="idee.php">
            <div class="tile-top">
                <div class="tile-icon"><i class="fa-solid fa-lightbulb"></i></div>
                <span class="tile-badge" id="badge-idee">0</span>
            </div>
            <div class="tile-title"><?php echo htmlspecialchars(t('accueil.tile_idee_title')); ?></div>
            <p class="tile-desc"><?php echo htmlspecialchars(t('accueil.tile_idee_desc')); ?></p>
            <span class="tile-cta"><?php echo htmlspecialchars(t('accueil.tile_idee_cta')); ?> <i class="fa-solid fa-arrow-right"></i></span>
        </a>

        <a class="tile t-aide" href="aide_service.php">
            <div class="tile-top">
                <div class="tile-icon"><i class="fa-solid fa-circle-question"></i></div>
            </div>
            <div class="tile-title"><?php echo t('accueil.tile_aide_title'); ?></div>
            <p class="tile-desc"><?php echo htmlspecialchars(t('accueil.tile_aide_desc')); ?></p>
            <span class="tile-cta"><?php echo htmlspecialchars(t('accueil.tile_aide_cta')); ?> <i class="fa-solid fa-arrow-right"></i></span>
        </a>
    </div>
</div>

<script>
const mesTicketIds = <?php echo $json_ticket_ids; ?>.map(String);
const I18N_ACCUEIL = {
    nouveauSingulier: <?php echo json_encode(t('accueil.badge_new_singular')); ?>,
    nouveauPluriel: <?php echo json_encode(t('accueil.badge_new_plural')); ?>,
    reponseSingulier: <?php echo json_encode(t('accueil.badge_reply_singular')); ?>,
    reponsePluriel: <?php echo json_encode(t('accueil.badge_reply_plural')); ?>
};

async function chargerBadgeSuivi() {
    if (mesTicketIds.length === 0) return;
    try {
        const res = await fetch('api.php?action=check_notifications&t=' + Date.now());
        const data = await res.json();
        if (!data.tickets) return;

        let total = 0;
        data.tickets.forEach(ticket => {
            if (mesTicketIds.includes(String(ticket.task_id))) {
                total += parseInt(ticket.nb, 10) || 0;
            }
        });

        const badge = document.getElementById('badge-suivi');
        if (total > 0) {
            badge.style.display = 'inline-block';
            badge.innerText = total + ' ' + (total > 1 ? I18N_ACCUEIL.nouveauPluriel : I18N_ACCUEIL.nouveauSingulier);
        }
    } catch (e) {}
}
chargerBadgeSuivi();

async function chargerBadgeIdees() {
    try {
        const res = await fetch('api.php?action=check_idees_notifications&t=' + Date.now());
        const data = await res.json();
        const nb = parseInt(data.non_lues, 10) || 0;
        const badge = document.getElementById('badge-idee');
        if (nb > 0) {
            badge.style.display = 'inline-block';
            badge.innerText = nb + ' ' + (nb > 1 ? I18N_ACCUEIL.reponsePluriel : I18N_ACCUEIL.reponseSingulier);
        }
    } catch (e) {}
}
chargerBadgeIdees();
</script>

</body>
</html>
