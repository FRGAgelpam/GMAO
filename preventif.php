<?php
require_once __DIR__ . '/session_init.php';

// --- SÉCURITÉ : Uniquement les Admins pour le Plan Préventif ---
if (!isset($_SESSION['user']) || $_SESSION['role'] !== 'admin') {
    header("Location: maintenance.php");
    exit();
}

$is_admin = true;
$hide_menu_button = true;

function hex_to_rgb_prev($hex) {
    $hex = ltrim($hex, '#');
    return implode(', ', array_map('hexdec', str_split($hex, 2)));
}

// --- FAMILLES DU PLAN PRÉVENTIF (configurables depuis Paramètres > Catégories de préventif) ---
$categories = [];
$counts = [];
try {
    require_once 'db.php';
    if (isset($db)) {
        $db->exec("CREATE TABLE IF NOT EXISTS preventif_categories (
            id INT AUTO_INCREMENT PRIMARY KEY,
            cle VARCHAR(30) UNIQUE,
            label VARCHAR(100),
            description VARCHAR(255),
            icone VARCHAR(60) DEFAULT 'fa-gear',
            couleur VARCHAR(20) DEFAULT '#3498db',
            ordre INT DEFAULT 0
        )");
        if ($db->query("SELECT COUNT(*) FROM preventif_categories")->fetchColumn() == 0) {
            $defautsCat = [
                ['process', 'Process', 'Gammes liées au fonctionnement des machines', 'fa-diagram-project', '#3498db'],
                ['audit', 'Qualité', "Contrôles et vérifications d'audit qualité", 'fa-clipboard-check', '#9b59b6'],
                ['reglementaire', 'Réglementaire', 'Inspections obligatoires (VGP, sécurité...)', 'fa-scale-balanced', '#c0392b'],
                ['quotidien', 'Quotidien', 'Gestes de routine : graissage, contrôles visuels', 'fa-oil-can', '#2ecc71'],
            ];
            $stmtSeedCat = $db->prepare("INSERT INTO preventif_categories (cle, label, description, icone, couleur, ordre) VALUES (?, ?, ?, ?, ?, ?)");
            foreach ($defautsCat as $i => $d) { $stmtSeedCat->execute([$d[0], $d[1], $d[2], $d[3], $d[4], $i]); }
        }
        foreach ($db->query("SELECT * FROM preventif_categories ORDER BY ordre ASC")->fetchAll(PDO::FETCH_ASSOC) as $cRow) {
            $categories[$cRow['cle']] = [
                'titre' => t('preventif.prefix_categorie') . ' ' . $cRow['label'],
                'icon' => $cRow['icone'],
                'desc' => $cRow['description'],
                'couleur' => $cRow['couleur'],
            ];
        }

        $db->exec("CREATE TABLE IF NOT EXISTS preventif_regles (
            id VARCHAR(64) PRIMARY KEY,
            equip VARCHAR(255), descr TEXT, hours VARCHAR(20), type_op VARCHAR(255), prio VARCHAR(20),
            mode_planif VARCHAR(20) DEFAULT 'frequence', freq VARCHAR(20), jours VARCHAR(40),
            echeance DATE NULL, alerte_val VARCHAR(10), alerte_unit VARCHAR(10),
            usine VARCHAR(255), secteur VARCHAR(255), ligne VARCHAR(255), zone VARCHAR(255),
            impact VARCHAR(255), arret_h VARCHAR(20), intervenant VARCHAR(100), declarant VARCHAR(100),
            cause TEXT, pieces TEXT, commentaires TEXT, status VARCHAR(20) DEFAULT 'actif',
            categorie VARCHAR(30) DEFAULT 'process',
            last_gen DATETIME NULL, last_gen_date DATE NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $db->exec("ALTER TABLE preventif_regles ADD COLUMN IF NOT EXISTS categorie VARCHAR(30) DEFAULT 'process'");
        // categorie doit accueillir la même longueur que preventif_categories.cle (VARCHAR(30)) :
        // une catégorie créée depuis Paramètres avec un libellé un peu long générait une clé tronquée
        // en silence à l'enregistrement d'une règle (ex. "maintenance_preventive" -> "maintenance_preventi"),
        // ce qui décrochait la règle de sa catégorie.
        $db->exec("ALTER TABLE preventif_regles MODIFY COLUMN categorie VARCHAR(30) DEFAULT 'process'");

        $rows = $db->query("SELECT
                COALESCE(NULLIF(categorie,''),'process') AS cat,
                COUNT(*) AS total,
                SUM(CASE WHEN status != 'pause' THEN 1 ELSE 0 END) AS actifs,
                SUM(CASE WHEN status != 'pause' AND echeance IS NOT NULL AND echeance < CURDATE() THEN 1 ELSE 0 END) AS en_retard
            FROM preventif_regles GROUP BY cat")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
            $counts[$r['cat']] = ['actifs' => (int)$r['actifs'], 'en_retard' => (int)$r['en_retard']];
        }
    }
} catch (Throwable $e) {}
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(langue_actuelle()); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title><?php echo htmlspecialchars(t('preventif.page_title_tag')); ?></title>
    <link rel="icon" type="image/png" href="img/logo.png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Caveat:wght@600&family=Segoe+UI:wght@300;400;600&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #2c3e50; --accent: #3498db; --success: #2ecc71;
            --danger: #e74c3c; --gelpam-green: #2ecc71; --gelpam-orange: #f39c12;
        }

        html, body { height: 100%; }
        body {
            margin: 0;
            font-family: 'Segoe UI', sans-serif;
            overflow: hidden;
            padding-top: 98px;
            box-sizing: border-box;
        }
        body::before {
            content: "";
            position: fixed;
            top: 0; left: 0; right: 0; bottom: 0;
            background: linear-gradient(rgba(0, 0, 0, 0.25), rgba(0, 0, 0, 0.25)), url('img/fond.jpg') no-repeat center 0px;
            background-size: cover;
            z-index: -1;
        }
        header { position: fixed; top: 0; width: 100%; z-index: 1000; overflow: hidden; box-shadow: 0 4px 20px rgba(0,0,0,0.25); }
        header::before { content: ""; position: absolute; top: -12px; left: -12px; right: -12px; bottom: -12px; background: linear-gradient(rgba(0, 0, 0, 0.25), rgba(0, 0, 0, 0.25)), url('img/fond.jpg') no-repeat center 0px fixed; background-size: cover; filter: blur(4px); z-index: -1; }
        .header-top { display: flex; justify-content: space-between; align-items: center; padding: 12px 20px; }
        .crumb-bar { display: flex; align-items: center; gap: 8px; font-size: 0.94rem; font-weight: 600; color: rgba(255,255,255,0.9); text-shadow: 0 1px 3px rgba(0,0,0,0.4); max-width: 99%; margin: 0 auto; padding: 0 10px 10px; box-sizing: border-box; }
        .crumb-bar .crumb-home { color: inherit; text-decoration: none; display: inline-flex; align-items: center; gap: 5px; }
        .crumb-bar .crumb-home:hover { text-decoration: underline; }
        .crumb-sep { color: rgba(255,255,255,0.45); font-weight: 400; }
        .crumb-current { color: rgba(255,255,255,0.75); font-weight: 400; }
        .btn-accueil { font-size: 18px; color: white; text-decoration: none; display: flex; align-items: center; justify-content: center; width: 38px; height: 38px; border-radius: 50%; background: rgba(255,255,255,0.12); backdrop-filter: blur(14px) brightness(1.15); -webkit-backdrop-filter: blur(14px) brightness(1.15); border: 1px solid rgba(255,255,255,0.4); box-shadow: 0 6px 16px rgba(0,0,0,0.2); transition: transform 0.2s, background 0.2s; }
        .btn-accueil:hover { background: rgba(255,255,255,0.28); transform: translateY(-2px); }
        .btn-accueil-green:hover { background: rgba(46, 204, 113, 0.3); border-color: rgba(46, 204, 113, 0.6); }
        .user-badge { background: rgba(255,255,255,0.12); backdrop-filter: blur(14px) brightness(1.15); -webkit-backdrop-filter: blur(14px) brightness(1.15); padding: 6px 14px; border-radius: 20px; font-size: 0.75rem; font-weight: 600; display: flex; align-items: center; gap: 8px; color: white; border: 1px solid rgba(255,255,255,0.4); text-shadow: 0 1px 3px rgba(0,0,0,0.4); box-shadow: 0 6px 16px rgba(0,0,0,0.2); }
        .status-pulse { width: 8px; height: 8px; border-radius: 50%; position: relative; background: var(--success); }
        @keyframes pulse-dot { 0% { transform: scale(1); opacity: 0.6; } 100% { transform: scale(2.5); opacity: 0; } }
        .status-pulse::after { content: ""; position: absolute; width: 100%; height: 100%; border-radius: 50%; background: inherit; animation: pulse-dot 2s infinite; opacity: 0.6; }

        /* --- SIDEBAR HARMONISÉE (MENU MAÎTRE) --- */
        .sidebar { height: 100%; width: 0; position: fixed; z-index: 3000; top: 0; left: 0; background-color: #1a252f; overflow-x: hidden; overflow-y: auto; transition: 0.4s; padding-top: 60px; padding-bottom: 20px; }
        .sidebar a { padding: 12px 25px; text-decoration: none; font-size: 1.05rem; color: #ecf0f1; display: block; transition: 0.3s; border-left: 4px solid transparent; }
        .sidebar a:hover { background: #2c3e50; border-left: 4px solid var(--accent); }
        .sidebar a.active-side { background: #2c3e50; border-left: 4px solid var(--accent); color: var(--accent); font-weight: bold; }
        .sidebar .closebtn { position: absolute; top: 10px; right: 25px; font-size: 36px; color: white; border: none; background: none; cursor: pointer; }
        .openbtn { font-size: 22px; cursor: pointer; background: none; border: none; color: var(--primary); padding: 5px 10px; }

        .container {
            max-width: 1000px; margin: 0 auto; width: 100%;
            height: calc(100vh - 98px); box-sizing: border-box;
            display: flex; flex-direction: column; justify-content: center; align-items: stretch;
            padding: clamp(20px, 5vh, 60px) 20px 18px;
        }

        .accueil-hero {
            display: inline-flex; flex-direction: column; align-items: center;
            align-self: center;
            margin: 0 auto clamp(30px, 6vh, 70px); flex-shrink: 0;
            text-align: center; color: white;
            background: rgba(255, 255, 255, 0.09);
            backdrop-filter: blur(16px) brightness(1.15);
            -webkit-backdrop-filter: blur(16px) brightness(1.15);
            border: 1px solid rgba(255,255,255,0.35);
            border-radius: 22px;
            box-shadow: 0 10px 25px rgba(0,0,0,0.2);
            padding: clamp(10px, 1.8vh, 18px) clamp(22px, 5vw, 46px);
            text-shadow: 0 2px 6px rgba(0,0,0,0.6);
        }
        .accueil-icon {
            width: clamp(48px, 8vh, 84px); height: clamp(48px, 8vh, 84px);
            border-radius: 50%;
            background: rgba(255,255,255,0.15);
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
            border: 1px solid rgba(255,255,255,0.45);
            display: flex; align-items: center; justify-content: center;
            color: white; font-size: clamp(1.3rem, 3.6vh, 2.2rem);
            margin-bottom: clamp(4px, 0.8vh, 10px);
            box-shadow: 0 6px 16px rgba(0,0,0,0.3);
        }
        .accueil-brand { font-family: 'Caveat', cursive; font-size: clamp(1.25rem, 2.6vh, 1.85rem); font-weight: 700; letter-spacing: 2px; text-transform: uppercase; color: rgba(255,255,255,0.8); margin: 0; }
        .accueil-hero h1 { font-family: 'Caveat', cursive; font-size: clamp(1.15rem, 2.4vh, 1.7rem); margin: 3px 0 0; font-weight: 700; }

        .tuiles-grid { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); grid-auto-rows: 1fr; gap: clamp(10px, 1.8vh, 20px); flex: 1 1 auto; min-height: 0; }
        .tuile {
            background: rgba(255, 255, 255, 0.025);
            backdrop-filter: blur(7px) brightness(1.2);
            -webkit-backdrop-filter: blur(7px) brightness(1.2);
            border-radius: 16px;
            padding: clamp(9px, 1.6vh, 17px) clamp(11px, 1.4vw, 17px);
            text-decoration: none;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            text-align: center;
            gap: clamp(4px, 0.8vh, 8px);
            box-shadow: 0 10px 25px rgba(0,0,0,0.2);
            border: 1px solid rgba(255,255,255,0.35);
            border-top: 5px solid var(--tuile-color, var(--accent));
            transition: transform 0.3s, box-shadow 0.3s;
            position: relative;
            height: 100%;
            min-height: 0;
            overflow: hidden;
            box-sizing: border-box;
        }
        .tuile:hover {
            transform: translateY(-8px);
            box-shadow:
                0 -12px 28px -10px rgba(var(--tuile-rgb, 52, 152, 219), 0.55),
                0 26px 42px -8px rgba(var(--tuile-rgb, 52, 152, 219), 0.65),
                0 8px 18px rgba(0,0,0,0.22);
        }
        .tuile-icon {
            width: clamp(34px, 5.4vh, 56px); height: clamp(34px, 5.4vh, 56px); border-radius: 50%;
            background: var(--tuile-color, var(--accent));
            color: white; display: flex; align-items: center; justify-content: center;
            font-size: clamp(1rem, 2.1vh, 1.4rem); box-shadow: 0 6px 15px -3px rgba(0,0,0,0.4);
        }
        .tuile-titre { font-family: 'Caveat', cursive; font-size: clamp(1rem, 2vh, 1.35rem); color: white; font-weight: 700; text-shadow: 0 1px 5px rgba(0,0,0,0.5); }
        .tuile-desc { font-size: clamp(0.65rem, 1.15vh, 0.8rem); color: rgba(255,255,255,0.88); line-height: 1.3; text-shadow: 0 1px 4px rgba(0,0,0,0.45); }

        .tuile-badges { position: absolute; top: 12px; right: 12px; display: flex; gap: 6px; }
        .tuile-badge { min-width: 22px; height: 22px; padding: 0 7px; border-radius: 11px; background: rgba(0,0,0,0.55); color: white; font-size: 0.7rem; font-weight: 800; display: flex; align-items: center; justify-content: center; }
        .tuile-badge.b-retard { background: rgba(231, 76, 60, 0.9); }

        .tuile-apercu { grid-column: 1 / -1; align-self: center; height: auto; flex-direction: row; justify-content: center; gap: 14px; width: clamp(220px, 40%, 320px); margin: 4px auto 0; padding: clamp(10px, 1.6vh, 14px) clamp(18px, 2.4vw, 26px); }
        .tuile-apercu .tuile-icon { width: clamp(36px, 5.6vh, 48px); height: clamp(36px, 5.6vh, 48px); font-size: clamp(1rem, 2vh, 1.2rem); }
        .tuile-apercu .tuile-titre { font-size: clamp(1rem, 2vh, 1.25rem); }

        @media screen and (max-width: 820px) {
            .tuiles-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); gap: clamp(7px, 1.1vh, 14px); }
            .tuile { padding: clamp(8px, 1.2vh, 15px) clamp(9px, 2.6vw, 14px); gap: clamp(3px, 0.6vh, 7px); }
            .tuile-icon { width: clamp(30px, 4vh, 48px); height: clamp(30px, 4vh, 48px); font-size: clamp(0.9rem, 1.6vh, 1.2rem); }
            .tuile-titre { font-size: clamp(0.9rem, 1.4vh, 1.15rem); }
            .tuile-desc { font-size: clamp(0.6rem, 0.9vh, 0.72rem); }
            .accueil-hero { margin-bottom: clamp(10px, 1.6vh, 18px); }
            .accueil-hero h1 { font-size: clamp(1.05rem, 1.7vh, 1.5rem); }
        }

        /* --- PAYSAGE TÉLÉPHONE (large mais peu haut, < 480px de hauteur) ---
           Même bug qu'index.php : grid-auto-rows:1fr (implicite minmax(auto,1fr)) ne rétrécit jamais sous
           le contenu, donc sur un écran bas les tuiles se retrouvent écrasées/coupées (.tuile a
           overflow:hidden). Le seuil ci-dessus (max-width:820px) ne se déclenche pas en paysage (souvent
           > 820px de large) : il faut donc aussi un seuil sur la HAUTEUR, avec lignes auto + défilement. */
        @media screen and (max-height: 480px) {
            .tuiles-grid { grid-auto-rows: auto; overflow-y: auto; align-content: start; }
            .tuile { min-height: 60px; padding: 6px 10px; }
            .tuile-desc { display: none; }
            .accueil-hero { margin: 0 auto 6px; padding: 2px clamp(16px, 4vw, 30px); }
            .accueil-icon { display: none; }
        }
    </style>
<?php include 'pwa_head.php'; ?>
</head>
<body onclick="checkCloseSidebar(event)">

<?php $breadcrumb_label = t('preventif.breadcrumb'); include 'navbar.php'; ?>

<div class="container">
    <div class="accueil-hero">
        <span class="accueil-icon"><i class="fa-solid fa-calendar-check"></i></span>
        <p class="accueil-brand"><?php echo htmlspecialchars($nom_entreprise_nav); ?></p>
        <h1><?php echo htmlspecialchars(t('preventif.h1')); ?></h1>
    </div>

    <div class="tuiles-grid">
        <?php foreach ($categories as $key => $c): ?>
            <?php
                $cnt = $counts[$key] ?? ['actifs' => 0, 'en_retard' => 0];
            ?>
            <a href="preventif_liste.php?cat=<?php echo urlencode($key); ?>" class="tuile" style="--tuile-color: <?php echo htmlspecialchars($c['couleur']); ?>; --tuile-rgb: <?php echo hex_to_rgb_prev($c['couleur']); ?>;">
                <?php if ($cnt['actifs'] > 0 || $cnt['en_retard'] > 0): ?>
                <div class="tuile-badges">
                    <?php if ($cnt['en_retard'] > 0): ?><span class="tuile-badge b-retard" title="<?php echo htmlspecialchars(t('preventif.tooltip_regles_retard')); ?>"><?php echo $cnt['en_retard']; ?></span><?php endif; ?>
                    <span class="tuile-badge" title="<?php echo htmlspecialchars(t('preventif.tooltip_regles_actives')); ?>"><?php echo $cnt['actifs']; ?></span>
                </div>
                <?php endif; ?>
                <div class="tuile-icon"><i class="fa-solid <?php echo htmlspecialchars($c['icon']); ?>"></i></div>
                <div class="tuile-titre"><?php echo htmlspecialchars($c['titre']); ?></div>
                <div class="tuile-desc"><?php echo htmlspecialchars($c['desc']); ?></div>
            </a>
        <?php endforeach; ?>

        <a href="preventif_liste.php" class="tuile tuile-apercu" style="--tuile-color: #64748b; --tuile-rgb: 100, 116, 139;">
            <div class="tuile-icon"><i class="fa-solid fa-list-check"></i></div>
            <div class="tuile-titre"><?php echo htmlspecialchars(t('preventif.vue_ensemble')); ?></div>
        </a>
    </div>
</div>

<script>
function openNav(e) { if (e) e.stopPropagation(); document.getElementById("mySidebar").style.width = "280px"; }
function closeNav() { document.getElementById("mySidebar").style.width = "0"; }
function checkCloseSidebar(event) { if (document.getElementById("mySidebar").style.width === "280px" && !document.getElementById("mySidebar").contains(event.target)) closeNav(); }
</script>

</body>
</html>
