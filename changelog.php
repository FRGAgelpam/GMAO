<?php
require_once __DIR__ . '/session_init.php';

// --- SÉCURITÉ : réservé aux Admins, comme le Journal d'activité ---
if (!isset($_SESSION['user']) || $_SESSION['role'] !== 'admin') {
    header("Location: maintenance.php");
    exit();
}

$is_admin = true;

$MOIS_FR = [1 => 'janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];

$journal = require __DIR__ . '/changelog_data.php';
$nb_total = 0;
foreach ($journal as $jour) { $nb_total += count($jour['items']); }
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(langue_actuelle()); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title><?php echo htmlspecialchars(t('changelog.page_title_tag')); ?></title>
    <link rel="icon" type="image/png" href="img/logo.png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Caveat:wght@600&family=Segoe+UI:wght@300;400;600;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #2c3e50; --accent: #3498db; --success: #2ecc71;
            --danger: #e74c3c; --brand-green: #2ecc71; --brand-orange: #f39c12;
            --brand-blue: #005696;
        }

        body {
            margin: 0;
            font-family: 'Segoe UI', sans-serif;
            background: linear-gradient(rgba(0, 0, 0, 0.2), rgba(0, 0, 0, 0.2)), url('img/fond.jpg') no-repeat center 0px fixed;
            background-size: cover;
            min-height: 100vh;
            padding-top: 98px;
        }

        @keyframes pulse-dot { 0% { transform: scale(1); opacity: 0.6; } 100% { transform: scale(2.5); opacity: 0; } }

        /* --- HEADER & NAV (MENU MAÎTRE, identique aux autres pages admin) --- */
        header { position: fixed; top: 0; width: 100%; z-index: 1000; overflow: hidden; box-shadow: 0 4px 20px rgba(0,0,0,0.25); }
        header::before { content: ""; position: absolute; top: -12px; left: -12px; right: -12px; bottom: -12px; background: linear-gradient(rgba(0, 0, 0, 0.2), rgba(0, 0, 0, 0.2)), url('img/fond.jpg') no-repeat center 0px fixed; background-size: cover; filter: blur(4px); z-index: -1; }
        .crumb-bar { display: flex; align-items: center; gap: 8px; font-size: 0.94rem; font-weight: 600; color: rgba(255,255,255,0.9); text-shadow: 0 1px 3px rgba(0,0,0,0.4); max-width: 99%; margin: 0 auto; padding: 0 10px 10px; }
        .crumb-bar .crumb-home { color: inherit; text-decoration: none; display: inline-flex; align-items: center; gap: 5px; }
        .crumb-bar .crumb-home:hover { text-decoration: underline; }
        .crumb-sep { color: rgba(255,255,255,0.45); font-weight: 400; }
        .crumb-current { color: rgba(255,255,255,0.75); font-weight: 400; }
        .header-top { display: flex; justify-content: space-between; align-items: center; padding: 12px 20px; }
        .user-badge { background: rgba(255,255,255,0.12); backdrop-filter: blur(14px) brightness(1.15); -webkit-backdrop-filter: blur(14px) brightness(1.15); padding: 6px 14px; border-radius: 20px; font-size: 0.75rem; font-weight: 600; display: flex; align-items: center; gap: 8px; color: white; border: 1px solid rgba(255,255,255,0.4); text-shadow: 0 1px 3px rgba(0,0,0,0.4); box-shadow: 0 6px 16px rgba(0,0,0,0.2); }
        .status-pulse { width: 8px; height: 8px; border-radius: 50%; position: relative; background: var(--success); }
        .status-pulse::after { content: ""; position: absolute; width: 100%; height: 100%; border-radius: 50%; background: inherit; animation: pulse-dot 2s infinite; opacity: 0.6; }
        .btn-accueil { font-size: 18px; color: white; text-decoration: none; display: flex; align-items: center; justify-content: center; width: 38px; height: 38px; border-radius: 50%; background: rgba(255,255,255,0.12); backdrop-filter: blur(14px) brightness(1.15); -webkit-backdrop-filter: blur(14px) brightness(1.15); border: 1px solid rgba(255,255,255,0.4); box-shadow: 0 6px 16px rgba(0,0,0,0.2); transition: transform 0.2s, background 0.2s; }
        .btn-accueil:hover { background: rgba(255,255,255,0.28); transform: translateY(-2px); }
        .btn-accueil-green:hover { background: rgba(46, 204, 113, 0.3); border-color: rgba(46, 204, 113, 0.6); }

        .sidebar { height: 100%; width: 0; position: fixed; z-index: 3000; top: 0; left: 0; background-color: #1a252f; overflow-x: hidden; overflow-y: auto; transition: 0.4s; padding-top: 60px; padding-bottom: 20px; }

        /* --- CONTENU --- */
        .container { max-width: 900px; margin: 0 auto; padding: 0 15px 40px 15px; }
        .page-header { display: flex; flex-wrap: wrap; justify-content: space-between; align-items: center; gap: 10px; margin-bottom: 8px; background: rgba(255,255,255,0.9); padding: 15px 20px; border-radius: 8px; box-shadow: 0 5px 15px rgba(0,0,0,0.1); border-left: 5px solid var(--primary); }
        .page-title { font-family: 'Caveat', cursive; font-size: 1.8rem; color: var(--primary); margin: 0; }
        .page-subtitle { margin: 4px 0 16px; color: rgba(255,255,255,0.9); text-shadow: 0 1px 3px rgba(0,0,0,0.4); font-size: 0.85rem; }
        .count-badge { background: rgba(52, 152, 219, 0.15); color: #2980b9; border: 1px solid rgba(52, 152, 219, 0.3); padding: 5px 12px; border-radius: 20px; font-size: 0.75rem; font-weight: 800; white-space: nowrap; }

        .search-container { background: rgba(255,255,255,0.95); padding: 12px 20px; border-radius: 8px; display: flex; align-items: center; gap: 15px; box-shadow: 0 5px 15px rgba(0,0,0,0.1); margin-bottom: 20px; border-left: 5px solid var(--accent); flex-wrap: wrap; }
        .search-group { display: flex; align-items: center; gap: 10px; font-weight: 800; font-size: 0.75rem; color: var(--primary); text-transform: uppercase; flex: 1; min-width: 200px; }
        .search-group input { width: 100%; padding: 8px 12px; border: 1px solid #ccc; border-radius: 5px; font-family: 'Segoe UI', sans-serif; font-size: 0.85rem; outline: none; }
        .search-group input:focus { border-color: var(--accent); }
        .btn-reset { background: #95a5a6; color: white; border: none; padding: 8px 15px; border-radius: 5px; font-weight: bold; cursor: pointer; transition: 0.2s; font-size: 0.8rem; white-space: nowrap; }
        .btn-reset:hover { background: #7f8c8d; }

        .timeline { background: white; border-radius: 8px; box-shadow: 0 5px 15px rgba(0,0,0,0.1); overflow: hidden; }
        .jour-bloc { border-bottom: 1px solid #f1f1f1; padding: 14px 20px; }
        .jour-bloc:last-child { border-bottom: none; }
        .jour-date { font-weight: 800; color: var(--primary); font-size: 0.85rem; text-transform: capitalize; display: flex; align-items: center; gap: 8px; margin-bottom: 8px; }
        .jour-date i { color: var(--accent); }
        .jour-items { margin: 0; padding-left: 26px; list-style: none; }
        .jour-items li { position: relative; font-size: 0.85rem; color: #333; line-height: 1.5; padding: 3px 0; }
        .jour-items li::before { content: "•"; color: var(--accent); font-weight: bold; position: absolute; left: -16px; }
        .jour-items li.masque { display: none; }
        .jour-bloc.masque { display: none; }

        .empty-state { text-align: center; padding: 40px; color: #888; font-style: italic; }
    </style>
<?php include 'pwa_head.php'; ?>
</head>
<body onclick="checkCloseSidebar(event)">

<?php $breadcrumb_label = t('changelog.breadcrumb'); include 'navbar.php'; ?>

<div class="container">
    <div class="page-header">
        <h1 class="page-title"><i class="fa-solid fa-rocket" style="color:var(--primary); margin-right:10px;"></i> <?php echo htmlspecialchars(t('changelog.h1')); ?></h1>
        <span class="count-badge" id="countBadge"><?php echo (int)$nb_total; ?> <?php echo htmlspecialchars($nb_total > 1 ? t('changelog.count_plural') : t('changelog.count_singular')); ?></span>
    </div>
    <p class="page-subtitle"><?php echo htmlspecialchars(t('changelog.subtitle')); ?></p>

    <div class="search-container">
        <div class="search-group">
            <i class="fa-solid fa-magnifying-glass"></i>
            <input type="text" id="filter-search" placeholder="<?php echo htmlspecialchars(t('changelog.filter_search_placeholder')); ?>" onkeyup="filterChangelog()">
        </div>
        <button class="btn-reset" onclick="resetFilter()"><i class="fa-solid fa-rotate-right"></i> <?php echo htmlspecialchars(t('changelog.btn_reset')); ?></button>
    </div>

    <div class="timeline" id="timeline">
        <?php if (empty($journal)): ?>
            <div class="empty-state" id="emptyState"><?php echo htmlspecialchars(t('changelog.empty')); ?></div>
        <?php else: ?>
            <?php foreach ($journal as $jour):
                $ts = strtotime($jour['date']);
                $libelle_date = date('j', $ts) . ' ' . $MOIS_FR[(int)date('n', $ts)] . ' ' . date('Y', $ts);
            ?>
            <div class="jour-bloc" data-jour>
                <div class="jour-date"><i class="fa-solid fa-calendar-day"></i> <?php echo htmlspecialchars($libelle_date); ?></div>
                <ul class="jour-items">
                    <?php foreach ($jour['items'] as $texte): ?>
                    <li data-texte="<?php echo htmlspecialchars(mb_strtolower($texte)); ?>"><?php echo htmlspecialchars($texte); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <?php endforeach; ?>
            <div class="empty-state" id="emptyState" style="display:none;"><?php echo htmlspecialchars(t('changelog.empty')); ?></div>
        <?php endif; ?>
    </div>
</div>

<script>
function openNav(e) { e.stopPropagation(); document.getElementById("mySidebar").style.width = "280px"; }
function closeNav() { document.getElementById("mySidebar").style.width = "0"; }
function checkCloseSidebar(event) { if (document.getElementById("mySidebar").style.width === "280px" && !document.getElementById("mySidebar").contains(event.target)) closeNav(); }

function filterChangelog() {
    const q = document.getElementById("filter-search").value.trim().toLowerCase();
    const jours = document.querySelectorAll("[data-jour]");
    let visibles = 0;

    jours.forEach(jour => {
        let jourAUneCorrespondance = false;
        jour.querySelectorAll("li").forEach(li => {
            const correspond = q === "" || li.getAttribute("data-texte").includes(q);
            li.classList.toggle("masque", !correspond);
            if (correspond) { jourAUneCorrespondance = true; visibles++; }
        });
        jour.classList.toggle("masque", !jourAUneCorrespondance);
    });

    document.getElementById("emptyState").style.display = (visibles === 0) ? "block" : "none";
}

function resetFilter() {
    document.getElementById("filter-search").value = "";
    filterChangelog();
}
</script>

</body>
</html>
