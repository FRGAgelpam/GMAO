<?php
require_once __DIR__ . '/session_init.php';

// 1. Si pas de session, on dégage vers le login
if (!isset($_SESSION['user']) || !isset($_SESSION['role'])) {
    header("Location: login.php");
    exit();
}

// 2. On vérifie si le rôle est autorisé pour la GMAO (admin, technicien)
$roles_gmao = ['admin', 'technicien'];

if (!in_array($_SESSION['role'], $roles_gmao)) {
    header("Location: accueil.php");
    exit();
}

$is_admin = ($_SESSION['role'] === 'admin');
$hide_menu_button = true;
$hide_accueil_btn = true;
$header_logout = true;

function hex_to_rgb($hex) {
    $hex = ltrim($hex, '#');
    return implode(', ', array_map('hexdec', str_split($hex, 2)));
}

// Rendu HTML d'une tuile "feuille" (lien direct vers une page). Réutilisé pour les tuiles de premier
// niveau, les tuiles à l'intérieur d'un dossier, et les tuiles masquées (conservées hors grille pour
// pouvoir être réaffichées sans recharger la page).
function tuile_leaf_html($t) {
    $href = htmlspecialchars($t['href']);
    $titre = htmlspecialchars($t['titre']);
    $icon = htmlspecialchars($t['icon']);
    $desc = htmlspecialchars($t['desc'] ?? '');
    $couleur = htmlspecialchars($t['couleur']);
    $rgb = hex_to_rgb($t['couleur']);
    $badge = (int)($t['badge'] ?? 0);
    ob_start();
    ?>
<a href="<?php echo $href; ?>" class="tuile" data-href="<?php echo $href; ?>" data-titre="<?php echo $titre; ?>" data-icone="<?php echo $icon; ?>" data-badge="<?php echo $badge; ?>" style="--tuile-color: <?php echo $couleur; ?>; --tuile-rgb: <?php echo $rgb; ?>;"><?php if ($badge > 0): ?><span class="tuile-badge"><?php echo $badge; ?></span><?php endif; ?><span class="tuile-remove" title="<?php echo htmlspecialchars(t('index.hide_tile_tooltip')); ?>"><i class="fa-solid fa-xmark"></i></span><div class="tuile-icon"><i class="fa-solid <?php echo $icon; ?>"></i></div><div class="tuile-titre"><?php echo $titre; ?></div><?php if ($desc !== ''): ?><div class="tuile-desc"><?php echo $desc; ?></div><?php endif; ?></a>
    <?php
    return ob_get_clean();
}

// --- Connexion DB (nécessaire pour le nom d'entreprise paramétrable affiché dans la tuile d'accueil) ---
require_once 'db.php';

// --- ADMIN : compteur d'idées à traiter (nouvelles demandes + réponses de service non lues) ---
$nb_idees_nouvelles = 0;
if ($is_admin) {
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS idees_messages (
            id INT AUTO_INCREMENT PRIMARY KEY, idee_id INT NOT NULL, expediteur VARCHAR(255),
            message TEXT, is_admin TINYINT(1) DEFAULT 0, lu TINYINT(1) DEFAULT 0,
            date_envoi DATETIME DEFAULT CURRENT_TIMESTAMP
        )");
        $nb_idees_nouvelles = (int)$db->query("
            SELECT COUNT(DISTINCT i.id) FROM idees_amelioration i
            LEFT JOIN idees_messages m ON m.idee_id = i.id AND m.is_admin = 0 AND m.lu = 0
            WHERE i.statut = 'Nouvelle' OR m.id IS NOT NULL
        ")->fetchColumn();
    } catch (Exception $e) { $nb_idees_nouvelles = 0; }
}

// --- DÉFINITION DES TUILES (mêmes onglets et mêmes droits que la barre de navigation) ---
$tuiles = [
    ['href' => 'maintenance.php',     'icon' => 'fa-pen-to-square', 'titre' => t('tuile.maintenance.titre'), 'desc' => t('tuile.maintenance.desc'), 'couleur' => '#f39c12', 'admin_only' => false],
    ['href' => 'planning.php',        'icon' => 'fa-calendar-days', 'titre' => t('tuile.planning.titre'),    'desc' => t('tuile.planning.desc'),    'couleur' => '#3498db', 'admin_only' => false],
    ['href' => 'sous_traitants.php',  'icon' => 'fa-users-gear',    'titre' => t('tuile.sous_traitants.titre'), 'desc' => t('tuile.sous_traitants.desc'), 'couleur' => '#9b59b6', 'admin_only' => false],
    ['href' => 'preventif.php',       'icon' => 'fa-calendar-check','titre' => t('tuile.preventif.titre'),  'desc' => t('tuile.preventif.desc'),   'couleur' => '#2ecc71', 'admin_only' => true],
    ['href' => 'stats_tech.php',      'icon' => 'fa-user-clock',    'titre' => t('tuile.stats_tech.titre'), 'desc' => t('tuile.stats_tech.desc'),  'couleur' => '#2980b9', 'admin_only' => true],
    ['href' => 'kpi.php',             'icon' => 'fa-chart-line',    'titre' => t('tuile.kpi.titre'),        'desc' => t('tuile.kpi.desc'),         'couleur' => '#c0392b', 'admin_only' => true],
    ['href' => 'admin_machines.php',  'icon' => 'fa-gears',         'titre' => t('tuile.parc_machine.titre'), 'desc' => $is_admin ? t('tuile.parc_machine.desc_admin') : t('tuile.parc_machine.desc_autre'), 'couleur' => '#e84393', 'admin_only' => false],
    ['href' => 'admin_reset.php',     'icon' => 'fa-user-shield',   'titre' => t('tuile.utilisateurs.titre'), 'desc' => t('tuile.utilisateurs.desc'), 'couleur' => '#e67e22', 'admin_only' => true],
    ['href' => 'idees_admin.php',     'icon' => 'fa-lightbulb',     'titre' => t('tuile.idees.titre'),      'desc' => t('tuile.idees.desc'), 'couleur' => '#f1c40f', 'admin_only' => true, 'hidden_for_others' => true, 'badge' => $nb_idees_nouvelles],
    ['href' => 'parametres.php',      'icon' => 'fa-sliders',       'titre' => t('tuile.parametres.titre'), 'desc' => t('tuile.parametres.desc'), 'couleur' => '#1abc9c', 'admin_only' => true],
    ['href' => 'logs.php',            'icon' => 'fa-clock-rotate-left', 'titre' => t('tuile.logs.titre'),   'desc' => t('tuile.logs.desc'), 'couleur' => '#607d8b', 'admin_only' => true],
    ['href' => 'changelog.php',       'icon' => 'fa-rocket',        'titre' => t('tuile.changelog.titre'), 'desc' => t('tuile.changelog.desc'), 'couleur' => '#34495e', 'admin_only' => true],
    ['href' => 'accueil.php',         'icon' => 'fa-right-left',    'titre' => t('tuile.portail.titre'),    'desc' => t('tuile.portail.desc'), 'couleur' => '#8e44ad', 'admin_only' => true, 'hidden_for_others' => true],
];

// Personnalisation depuis Paramètres > Tuiles d'accueil (remplace titre / description / couleur par défaut si définis)
try {
    $tuiles_perso = [];
    foreach ($db->query("SELECT href, couleur, titre, description, icone FROM tuiles_couleurs")->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $tuiles_perso[$row['href']] = $row;
    }
} catch (Exception $e) { $tuiles_perso = []; }

// Correspondance href -> clés de traduction (pour distinguer une vraie personnalisation d'un simple
// changement de couleur, qui a pu enregistrer par effet de bord l'ancien titre/description français en
// base — auquel cas on veut quand même appliquer la traduction, pas figer le texte).
$__tuile_i18n_keys = [
    'maintenance.php' => ['titre' => 'tuile.maintenance.titre', 'desc' => ['tuile.maintenance.desc']],
    'planning.php' => ['titre' => 'tuile.planning.titre', 'desc' => ['tuile.planning.desc']],
    'sous_traitants.php' => ['titre' => 'tuile.sous_traitants.titre', 'desc' => ['tuile.sous_traitants.desc']],
    'preventif.php' => ['titre' => 'tuile.preventif.titre', 'desc' => ['tuile.preventif.desc']],
    'stats_tech.php' => ['titre' => 'tuile.stats_tech.titre', 'desc' => ['tuile.stats_tech.desc']],
    'kpi.php' => ['titre' => 'tuile.kpi.titre', 'desc' => ['tuile.kpi.desc']],
    'admin_machines.php' => ['titre' => 'tuile.parc_machine.titre', 'desc' => ['tuile.parc_machine.desc_admin', 'tuile.parc_machine.desc_autre']],
    'admin_reset.php' => ['titre' => 'tuile.utilisateurs.titre', 'desc' => ['tuile.utilisateurs.desc']],
    'idees_admin.php' => ['titre' => 'tuile.idees.titre', 'desc' => ['tuile.idees.desc']],
    'parametres.php' => ['titre' => 'tuile.parametres.titre', 'desc' => ['tuile.parametres.desc']],
    'logs.php' => ['titre' => 'tuile.logs.titre', 'desc' => ['tuile.logs.desc']],
    'changelog.php' => ['titre' => 'tuile.changelog.titre', 'desc' => ['tuile.changelog.desc']],
    'accueil.php' => ['titre' => 'tuile.portail.titre', 'desc' => ['tuile.portail.desc']],
    'aide.php' => ['titre' => 'tuile.aide.titre', 'desc' => ['tuile.aide.desc']],
];
$__defauts_fr = require __DIR__ . '/lang/fr.php';

foreach ($tuiles as &$tCouleur) {
    $p = $tuiles_perso[$tCouleur['href']] ?? null;
    if (!empty($p['couleur'])) { $tCouleur['couleur'] = $p['couleur']; }
    $__cles = $__tuile_i18n_keys[$tCouleur['href']] ?? null;

    $__titre_snapshot_fr = $__cles && ($p['titre'] ?? null) === ($__defauts_fr[$__cles['titre']] ?? null);
    if (!empty($p['titre']) && !$__titre_snapshot_fr) { $tCouleur['titre'] = $p['titre']; }

    $__desc_valeurs_fr = $__cles ? array_map(function ($k) use ($__defauts_fr) { return $__defauts_fr[$k] ?? null; }, $__cles['desc']) : [];
    $__desc_snapshot_fr = in_array($p['description'] ?? null, $__desc_valeurs_fr, true);
    if (isset($p['description']) && $p['description'] !== null && $p['description'] !== '' && !$__desc_snapshot_fr) { $tCouleur['desc'] = $p['description']; }

    if (!empty($p['icone'])) { $tCouleur['icon'] = $p['icone']; }
}
unset($tCouleur);
$aide_perso = $tuiles_perso['aide.php'] ?? null;
$couleur_aide = !empty($aide_perso['couleur']) ? $aide_perso['couleur'] : '#16a085';
$__aide_titre_snapshot_fr = ($aide_perso['titre'] ?? null) === ($__defauts_fr['tuile.aide.titre'] ?? null);
$titre_aide = (!empty($aide_perso['titre']) && !$__aide_titre_snapshot_fr) ? $aide_perso['titre'] : t('tuile.aide.titre');
$__aide_desc_snapshot_fr = ($aide_perso['description'] ?? null) === ($__defauts_fr['tuile.aide.desc'] ?? null);
$desc_aide = (isset($aide_perso['description']) && $aide_perso['description'] !== null && $aide_perso['description'] !== '' && !$__aide_desc_snapshot_fr) ? $aide_perso['description'] : t('tuile.aide.desc');
$icone_aide = !empty($aide_perso['icone']) ? $aide_perso['icone'] : 'fa-circle-question';

// Certaines tuiles admin sont entièrement masquées (pas juste verrouillées) pour les non-admins
if (!$is_admin) {
    $tuiles = array_filter($tuiles, function($t) { return empty($t['hidden_for_others']); });
}

// Les tuiles réservées admin sont entièrement masquées pour les non-admins (pas de tuile cadenas).
$tuiles_verrouillees = [];
$tuiles_disponibles = [];
foreach ($tuiles as $t) {
    if ($t['admin_only'] && !$is_admin) { continue; }
    $tuiles_disponibles[] = $t;
}
$tuilesParHref = [];
foreach ($tuiles_disponibles as $t) { $tuilesParHref[$t['href']] = $t; }

// Icônes disponibles pour les tuiles-dossier créées depuis l'accueil, ET pour recolorer/réicôner
// les tuiles fixes depuis Paramètres > Tuiles d'accueil (inclut donc aussi les icônes d'origine de
// chaque tuile fixe, pour qu'enregistrer sans toucher à l'icône reste toujours valide).
$ICONES_DOSSIER = [
    'fa-folder' => t('icone.dossier'), 'fa-folder-open' => t('icone.dossier_ouvert'), 'fa-layer-group' => t('icone.groupe'),
    'fa-boxes-stacked' => t('icone.boites'), 'fa-toolbox' => t('icone.outils'), 'fa-clipboard-list' => t('icone.liste'),
    'fa-chart-pie' => t('icone.statistiques'), 'fa-users' => t('icone.equipe'), 'fa-gear' => t('icone.reglages'),
    'fa-house-chimney' => t('icone.maison'), 'fa-wrench' => t('icone.maintenance'), 'fa-bell' => t('icone.notifications'),
    'fa-flag' => t('icone.drapeau'), 'fa-shield-halved' => t('icone.securite'), 'fa-building' => t('icone.batiment'),
    'fa-truck' => t('icone.logistique'), 'fa-industry' => t('icone.industrie'), 'fa-clock' => t('icone.temps'),
    'fa-star' => t('icone.favori'), 'fa-bookmark' => t('icone.marque_page'),
    'fa-pen-to-square' => t('icone.edition'), 'fa-calendar-days' => t('icone.calendrier'), 'fa-users-gear' => t('icone.gestion_equipe'),
    'fa-hourglass-half' => t('icone.suivi_temps'), 'fa-calendar-check' => t('icone.planification'), 'fa-user-clock' => t('icone.suivi_horaire'),
    'fa-chart-line' => t('icone.performance'), 'fa-gears' => t('icone.mecanique'), 'fa-user-shield' => t('icone.securite_utilisateur'),
    'fa-lightbulb' => t('icone.idee'), 'fa-sliders' => t('icone.parametres'), 'fa-clock-rotate-left' => t('icone.historique'),
    'fa-right-left' => t('icone.echange'), 'fa-circle-question' => t('icone.aide'), 'fa-rocket' => t('icone.nouveautes'),
];
// Même palette élargie que Paramètres, dupliquée ici pour le sélecteur de couleur des dossiers.
$COULEURS_DOSSIER = [
    '#3498db', '#2980b9', '#5dade2', '#2c3e50', '#34495e',
    '#9b59b6', '#8e44ad', '#a569bd', '#6c5ce7', '#d980fa',
    '#e74c3c', '#c0392b', '#ec7063', '#e84393', '#fd79a8',
    '#f39c12', '#d35400', '#f5b041', '#f1c40f', '#f4d03f',
    '#2ecc71', '#27ae60', '#58d68d', '#00b894', '#16a085',
    '#1abc9c', '#48c9b0', '#7f8c8d', '#95a5a6', '#607d8b',
];

// --- MISE EN PAGE PERSONNALISÉE DE L'ACCUEIL (glisser-déposer, dossiers, propre à chaque utilisateur) ---
// Format stocké (table tuiles_ordre, colonne "ordre", en JSON) :
//   {"layout": ["href1", {"type":"dossier","id":"...","titre":"...","icone":"...","couleur":"...","enfants":["href2","href3"]}], "masquees": ["href4"]}
// L'ancien format (simple tableau de hrefs) reste accepté pour ne pas casser les réglages déjà enregistrés.
$layout = [];
$masquees = [];
$layout_sauvegarde_existe = false;

try {
    if (isset($db)) {
        $db->exec("CREATE TABLE IF NOT EXISTS tuiles_ordre (utilisateur VARCHAR(100) PRIMARY KEY, ordre TEXT)");
        $stmtOrdre = $db->prepare("SELECT ordre FROM tuiles_ordre WHERE utilisateur = ?");
        $stmtOrdre->execute([$_SESSION['user']]);
        $ordreRow = $stmtOrdre->fetchColumn();
        $decode = $ordreRow ? json_decode($ordreRow, true) : null;

        if (is_array($decode)) {
            if (array_key_exists('layout', $decode)) {
                $layout = is_array($decode['layout']) ? $decode['layout'] : [];
                $masquees = is_array($decode['masquees'] ?? null) ? $decode['masquees'] : [];
            } else {
                $layout = $decode; // ancien format
            }
            $layout_sauvegarde_existe = true;
        }
    }
} catch (Exception $e) {}

// Pas de mise en page enregistrée : on construit la disposition par défaut. Pour un admin, les tuiles
// de gestion sont regroupées dans un dossier "Administration" prêt à l'emploi.
if (!$layout_sauvegarde_existe) {
    $hrefs_dossier_defaut = ['logs.php', 'changelog.php', 'parametres.php', 'idees_admin.php', 'admin_reset.php', 'accueil.php'];
    $layout = [];
    foreach ($tuiles_disponibles as $t) {
        if ($is_admin && in_array($t['href'], $hrefs_dossier_defaut, true)) { continue; }
        $layout[] = $t['href'];
    }
    if ($is_admin) {
        $enfants_dossier_defaut = array_values(array_filter($hrefs_dossier_defaut, function($h) use ($tuilesParHref) { return isset($tuilesParHref[$h]); }));
        if (!empty($enfants_dossier_defaut)) {
            $layout[] = [
                'type' => 'dossier', 'id' => 'dossier_administration', 'titre' => t('index.admin_folder_title'),
                'desc' => t('index.admin_folder_desc'),
                'icone' => 'fa-folder', 'couleur' => '#34495e', 'enfants' => $enfants_dossier_defaut,
            ];
        }
    }
}

// Les tuiles pas encore connues de la mise en page enregistrée (ex : nouvelle page ajoutée à l'appli
// après le dernier enregistrement) apparaissent automatiquement en fin de grille.
$hrefs_utilises = [];
foreach ($layout as $item) {
    if (is_array($item) && ($item['type'] ?? '') === 'dossier') {
        foreach (($item['enfants'] ?? []) as $h) { $hrefs_utilises[$h] = true; }
    } elseif (is_string($item)) {
        $hrefs_utilises[$item] = true;
    }
}
foreach ($masquees as $h) { $hrefs_utilises[$h] = true; }
foreach ($tuiles_disponibles as $t) {
    if (!isset($hrefs_utilises[$t['href']])) { $layout[] = $t['href']; }
}

// Résolution finale : chaque référence est remplacée par les données complètes de la tuile, et le
// badge de chaque dossier est la somme des badges de ses enfants (ex : idées reçues).
$grille = [];
foreach ($layout as $item) {
    if (is_array($item) && ($item['type'] ?? '') === 'dossier') {
        $enfants = [];
        $badge_dossier = 0;
        foreach (($item['enfants'] ?? []) as $h) {
            if (isset($tuilesParHref[$h])) {
                $enfants[] = $tuilesParHref[$h];
                $badge_dossier += (int)($tuilesParHref[$h]['badge'] ?? 0);
            }
        }
        $idBrut = preg_replace('/[^a-zA-Z0-9_-]/', '', (string)($item['id'] ?? ''));
        $grille[] = [
            'type' => 'dossier',
            'id' => $idBrut !== '' ? $idBrut : ('dossier_' . substr(md5(json_encode($item) . microtime()), 0, 8)),
            'titre' => (string)($item['titre'] ?? t('index.folder_default_title')) !== '' ? (string)($item['titre'] ?? t('index.folder_default_title')) : t('index.folder_default_title'),
            'desc' => trim(mb_substr((string)($item['desc'] ?? ''), 0, 120)),
            'icone' => array_key_exists($item['icone'] ?? '', $ICONES_DOSSIER) ? $item['icone'] : 'fa-folder',
            'couleur' => preg_match('/^#[0-9a-fA-F]{6}$/', $item['couleur'] ?? '') ? $item['couleur'] : '#34495e',
            'enfants' => $enfants,
            'badge' => $badge_dossier,
        ];
    } elseif (is_string($item) && isset($tuilesParHref[$item])) {
        $grille[] = ['type' => 'tuile'] + $tuilesParHref[$item];
    }
}
$tuiles_masquees = array_values(array_filter(array_map(function($h) use ($tuilesParHref) {
    return $tuilesParHref[$h] ?? null;
}, $masquees)));
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(langue_actuelle()); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title><?php echo htmlspecialchars(t('index.page_title')); ?></title>
    <link rel="icon" type="image/png" href="img/logo.png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Caveat:wght@600&family=Segoe+UI:wght@300;400;600&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #2c3e50; --accent: #3498db; --success: #2ecc71;
            --danger: #e74c3c; --brand-green: #2ecc71; --brand-orange: #f39c12;
        }

        html, body { height: 100%; }
        body {
            margin: 0;
            font-family: 'Segoe UI', sans-serif;
            overflow: hidden;
            padding-top: 68px;
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
        .header-title { font-family: 'Caveat', cursive; font-size: 1.5rem; color: white; background: rgba(255,255,255,0.12); backdrop-filter: blur(14px) brightness(1.15); -webkit-backdrop-filter: blur(14px) brightness(1.15); border: 1px solid rgba(255,255,255,0.4); border-radius: 20px; padding: 6px 18px; text-shadow: 0 2px 6px rgba(0,0,0,0.4); box-shadow: 0 6px 16px rgba(0,0,0,0.2); }
        .nav-tabs { display: flex; background: #fff; padding: 0 10px; gap: 2px; overflow-x: auto; }
        .tab-item { padding: 10px 18px; text-decoration: none; color: #7f8c8d; font-weight: 600; font-size: 0.8rem; border-bottom: 3px solid transparent; transition: 0.3s; display: flex; align-items: center; gap: 8px; white-space: nowrap; }
        .tab-item.active { color: var(--accent); border-bottom: 3px solid var(--accent); background: rgba(52, 152, 219, 0.05); }

        .btn-logout-header:hover {
            background: rgba(231, 76, 60, 0.3);
            border-color: rgba(231, 76, 60, 0.6);
        }

        /* --- SIDEBAR HARMONISÉE (MENU MAÎTRE) --- */
        .sidebar { height: 100%; width: 0; position: fixed; z-index: 3000; top: 0; left: 0; background-color: #1a252f; overflow-x: hidden; overflow-y: auto; transition: 0.4s; padding-top: 60px; padding-bottom: 20px; }
        .sidebar a { padding: 12px 25px; text-decoration: none; font-size: 1.05rem; color: #ecf0f1; display: block; transition: 0.3s; border-left: 4px solid transparent; }
        .sidebar a:hover { background: #2c3e50; border-left: 4px solid var(--accent); }
        .sidebar a.active-side { background: #2c3e50; border-left: 4px solid var(--accent); color: var(--accent); font-weight: bold; }
        .sidebar .closebtn { position: absolute; top: 10px; right: 25px; font-size: 36px; color: white; border: none; background: none; cursor: pointer; }
        .openbtn { font-size: 22px; cursor: pointer; background: none; border: none; color: var(--primary); padding: 5px 10px; }
        .btn-accueil { font-size: 18px; color: white; text-decoration: none; display: flex; align-items: center; justify-content: center; width: 38px; height: 38px; border-radius: 50%; background: rgba(255,255,255,0.12); backdrop-filter: blur(14px) brightness(1.15); -webkit-backdrop-filter: blur(14px) brightness(1.15); border: 1px solid rgba(255,255,255,0.4); box-shadow: 0 6px 16px rgba(0,0,0,0.2); transition: transform 0.2s, background 0.2s; }
        .btn-accueil:hover { background: rgba(255,255,255,0.28); transform: translateY(-2px); }
        .btn-accueil:hover { background: rgba(0,0,0,0.06); }

        .user-badge { background: rgba(255,255,255,0.12); backdrop-filter: blur(14px) brightness(1.15); -webkit-backdrop-filter: blur(14px) brightness(1.15); padding: 6px 14px; border-radius: 20px; font-size: 0.75rem; font-weight: 600; display: flex; align-items: center; gap: 8px; color: white; border: 1px solid rgba(255,255,255,0.4); text-shadow: 0 1px 3px rgba(0,0,0,0.4); box-shadow: 0 6px 16px rgba(0,0,0,0.2); }
        .status-pulse { width: 8px; height: 8px; border-radius: 50%; position: relative; background: var(--success); }
        @keyframes pulse-dot { 0% { transform: scale(1); opacity: 0.6; } 100% { transform: scale(2.5); opacity: 0; } }
        .status-pulse::after { content: ""; position: absolute; width: 100%; height: 100%; border-radius: 50%; background: inherit; animation: pulse-dot 2s infinite; opacity: 0.6; }

        .container {
            max-width: 1100px; margin: 0 auto; width: 100%;
            height: calc(100vh - 68px); box-sizing: border-box;
            display: flex; flex-direction: column; justify-content: flex-start; align-items: stretch;
            padding: clamp(10px, 2.5vh, 30px) 20px 18px;
            overflow: hidden;
        }

        .accueil-hero {
            display: inline-flex; flex-direction: column; align-items: center;
            align-self: center;
            max-width: 100%; box-sizing: border-box;
            margin: 0 auto clamp(10px, 1.6vh, 16px); flex-shrink: 0;
            text-align: center; color: white;
            background: rgba(255, 255, 255, 0.09);
            backdrop-filter: blur(16px) brightness(1.15);
            -webkit-backdrop-filter: blur(16px) brightness(1.15);
            border: 1px solid rgba(255,255,255,0.35);
            border-radius: 22px;
            box-shadow: 0 10px 25px rgba(0,0,0,0.2);
            padding: clamp(4px, 0.8vh, 8px) clamp(22px, 5vw, 46px);
            text-shadow: 0 2px 6px rgba(0,0,0,0.6);
        }
        .accueil-icon {
            width: clamp(26px, 3.6vh, 40px); height: clamp(26px, 3.6vh, 40px);
            border-radius: 50%;
            background: rgba(255,255,255,0.15);
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
            border: 1px solid rgba(255,255,255,0.45);
            display: flex; align-items: center; justify-content: center;
            color: white; font-size: clamp(0.95rem, 2.3vh, 1.5rem);
            margin-bottom: clamp(2px, 0.5vh, 6px);
            box-shadow: 0 6px 16px rgba(0,0,0,0.3);
        }
        .accueil-brand { font-family: 'Caveat', cursive; font-size: clamp(1.05rem, 2vh, 1.5rem); font-weight: 700; letter-spacing: 2px; text-transform: uppercase; color: rgba(255,255,255,0.8); margin: 0; }
        .accueil-hero h1 { font-family: 'Caveat', cursive; font-size: clamp(0.95rem, 1.8vh, 1.35rem); margin: 2px 0 0; font-weight: 700; }

        /* padding-top : sans lui, le survol d'une tuile de la 1re ligne (qui remonte de 8px + halo) se
           fait couper par le overflow-y:auto ci-dessous (nécessaire si jamais il y a plus de 16 tuiles). */
        .tuiles-grid { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); grid-template-rows: repeat(4, minmax(0, 1fr)); grid-auto-rows: minmax(0, 1fr); gap: clamp(12px, 2vh, 20px); flex: 1 1 auto; min-height: 0; padding: 24px 0; overflow-y: auto; overflow-x: hidden; scrollbar-width: thin; }
        .tuiles-grid::-webkit-scrollbar { width: 6px; }
        .tuiles-grid::-webkit-scrollbar-thumb { background: rgba(255,255,255,0.25); border-radius: 3px; }
        .tuiles-grid::-webkit-scrollbar-track { background: transparent; }
        /* --- DESIGN DES TUILES « Sombre premium » (choisi sur maquette, 19/09/2026) : carte en verre sombre
           avec une lueur de la couleur de la tuile en haut à gauche, icône en anneau lumineux qui se remplit
           au survol. Style uniquement : le glisser-déposer, les dossiers, le badge et les croix de masquage
           n'ont pas changé. --tuile-base est surchargé dans la modale d'un dossier (voile clair). --- */
        .tuile {
            --tuile-base: rgba(13, 19, 27, 0.66);
            background: radial-gradient(120% 90% at 0% 0%, rgba(var(--tuile-rgb, 52, 152, 219), 0.26), transparent 58%), var(--tuile-base);
            backdrop-filter: blur(16px) saturate(130%);
            -webkit-backdrop-filter: blur(16px) saturate(130%);
            border-radius: 22px;
            padding: clamp(8px, 1.2vh, 14px) clamp(9px, 1.2vw, 14px);
            text-decoration: none;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            text-align: center;
            gap: clamp(4px, 0.9vh, 9px);
            box-shadow: 0 14px 34px -10px rgba(0,0,0,0.6);
            border: 1px solid rgba(255,255,255,0.10);
            transition: transform 0.35s cubic-bezier(0.2, 0.8, 0.2, 1), box-shadow 0.35s, border-color 0.35s;
            height: 100%;
            min-height: 0;
            overflow: hidden;
            box-sizing: border-box;
        }
        .tuile { position: relative; z-index: 1; }
        .tuile:hover {
            z-index: 2;
            transform: translateY(-6px);
            border-color: rgba(var(--tuile-rgb, 52, 152, 219), 0.65);
            box-shadow:
                0 22px 44px -12px rgba(var(--tuile-rgb, 52, 152, 219), 0.55),
                0 0 0 1px rgba(var(--tuile-rgb, 52, 152, 219), 0.35);
        }
        .tuile::before { content: ''; position: absolute; left: 0; right: 0; bottom: 0; height: 2px; background: linear-gradient(90deg, transparent, var(--tuile-color, var(--accent)), transparent); opacity: 0; transition: opacity 0.35s; pointer-events: none; }
        .tuile:hover::before { opacity: 1; }
        .tuile-locked { cursor: not-allowed; filter: grayscale(75%); opacity: 0.55; }
        .tuile-locked:hover { transform: none; box-shadow: 0 10px 25px rgba(0,0,0,0.2); }
        .tuile-lock {
            position: absolute; top: 8px; right: 8px;
            width: clamp(18px, 2.6vh, 24px); height: clamp(18px, 2.6vh, 24px);
            background: rgba(0,0,0,0.6); color: white; border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            font-size: clamp(0.55rem, 1.2vh, 0.7rem);
        }
        .tuile-badge {
            position: absolute; top: 8px; right: 8px;
            min-width: clamp(18px, 2.6vh, 22px); height: clamp(18px, 2.6vh, 22px);
            padding: 0 5px; box-sizing: border-box;
            background: var(--danger); color: white; border-radius: 20px;
            display: flex; align-items: center; justify-content: center;
            font-size: clamp(0.6rem, 1.2vh, 0.72rem); font-weight: 800;
            box-shadow: 0 2px 6px rgba(0,0,0,0.35);
        }
        .tuile-icon {
            width: clamp(26px, 4.4vh, 44px); height: clamp(26px, 4.4vh, 44px); border-radius: 50%;
            background: var(--tuile-color, var(--accent));
            color: white; display: flex; align-items: center; justify-content: center;
            font-size: clamp(0.85rem, 1.7vh, 1.15rem); box-shadow: 0 6px 15px -3px rgba(0,0,0,0.4);
        }
        .tuile-titre { font-family: 'Segoe UI', sans-serif; font-size: clamp(0.86rem, 1.85vh, 1.02rem); color: #fff; font-weight: 600; letter-spacing: 0.2px; line-height: 1.2; }
        .tuile-desc { font-size: clamp(0.66rem, 1.15vh, 0.76rem); color: rgba(255,255,255,0.62); line-height: 1.35; max-width: 92%; }
        /* Emplacement de 2 lignes réservé : sans lui, une description sur 2 lignes pousse l'icône vers le haut par rapport aux tuiles voisines à 1 ligne (le contenu est centré verticalement). */
        .tuiles-grid > .tuile:not(.tuile-aide) .tuile-desc, .tuiles-grid > .tuile-dossier .tuile-dossier-header .tuile-desc { min-height: 2.7em; }
        .tuile-aide .tuile-desc { min-height: 0; }
        .tuile .tuile-icon {
            width: clamp(32px, 5.2vh, 50px); height: clamp(32px, 5.2vh, 50px);
            background: rgba(var(--tuile-rgb, 52, 152, 219), 0.16);
            color: color-mix(in srgb, var(--tuile-color, var(--accent)) 50%, #fff);
            font-size: clamp(0.9rem, 1.9vh, 1.2rem);
            box-shadow: inset 0 0 0 1.5px color-mix(in srgb, var(--tuile-color, var(--accent)) 72%, #fff), 0 0 24px -4px rgba(var(--tuile-rgb, 52, 152, 219), 0.55);
            transition: background 0.35s, color 0.35s, box-shadow 0.35s;
            flex-shrink: 0;
        }
        .tuile:hover .tuile-icon { background: var(--tuile-color, var(--accent)); color: #fff; box-shadow: inset 0 0 0 1.5px rgba(255,255,255,0.4), 0 0 30px -2px rgba(var(--tuile-rgb, 52, 152, 219), 0.9); }
        .tuile-aide { grid-column: 1 / -1; width: clamp(180px, 26%, 240px); margin: 0 auto; cursor: pointer; align-self: center; height: auto; }

        .btn-reorganiser, .btn-plus { display:flex; align-items:center; gap:8px; background: rgba(255,255,255,0.12); backdrop-filter: blur(14px) brightness(1.15); -webkit-backdrop-filter: blur(14px) brightness(1.15); border: 1px solid rgba(255,255,255,0.4); color: white; padding: 7px 14px; border-radius: 20px; font-size: 0.75rem; font-weight: 500; cursor: pointer; box-shadow: 0 6px 16px rgba(0,0,0,0.2); transition: 0.2s; font-family: inherit; }
        .btn-reorganiser:hover, .btn-plus:hover { background: rgba(255,255,255,0.22); }
        .btn-reorganiser.active { background: rgba(46, 204, 113, 0.35); border-color: rgba(46, 204, 113, 0.6); }
        .btn-plus:disabled { opacity: 0.4; cursor: not-allowed; }
        .btn-plus:disabled:hover { background: rgba(255,255,255,0.12); }
        .toolbar-accueil { display:flex; justify-content:flex-end; align-items:center; gap:8px; margin: 0 0 8px; position: relative; }
        .tuile-drag-mode { cursor: grab; animation: tuile-wiggle 0.25s ease-in-out infinite alternate; }
        .tuile-drag-mode:active { cursor: grabbing; }
        @keyframes tuile-wiggle { from { transform: rotate(-0.6deg); } to { transform: rotate(0.6deg); } }

        /* --- Menu "+" --- */
        .plus-menu { display:none; position:absolute; top: calc(100% + 8px); right: 0; background:#fff; border-radius: 12px; box-shadow: 0 10px 25px rgba(0,0,0,0.25); overflow:hidden; z-index: 20; min-width: 220px; }
        .plus-menu.show { display:block; }
        .plus-menu button { display:flex; align-items:center; gap:10px; width:100%; box-sizing:border-box; padding: 12px 16px; border:none; background:#fff; color:#2c3e50; font-size:0.82rem; font-weight:600; cursor:pointer; text-align:left; font-family: inherit; }
        .plus-menu button:hover { background:#f4f6f8; }
        .plus-menu button i { color: var(--accent); width: 16px; }

        /* --- Tuiles-dossier --- */
        .tuile-dossier { cursor: pointer; }
        .tuile-dossier-header { display:flex; flex-direction:column; align-items:center; justify-content:center; gap: clamp(3px, 0.6vh, 6px); width:100%; height:100%; position:relative; }
        .tuile-dossier-chevron { font-size: 0.65em; margin-left: 5px; opacity: 0.8; }
        .tuile-dossier-body { display:none; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: clamp(6px, 1.2vh, 12px); margin-top: 8px; min-height: 54px; }
        .tuile-dossier-body .tuile { min-height: 90px; }
        .tuile-dossier-empty { grid-column: 1/-1; display:flex; align-items:center; justify-content:center; color: rgba(255,255,255,0.6); font-size: 0.75rem; font-style: italic; border: 1.5px dashed rgba(255,255,255,0.3); border-radius: 12px; padding: 14px; }
        @media screen and (max-width: 820px) { .tuile-dossier-body { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
        .tuile-dossier.drop-hover { box-shadow: 0 0 0 3px var(--tuile-color, var(--accent)) inset, 0 10px 25px rgba(0,0,0,0.2); }

        .tuile-remove, .tuile-dossier-edit {
            display:none; position:absolute; z-index: 5;
            width: clamp(18px, 2.6vh, 22px); height: clamp(18px, 2.6vh, 22px);
            background: rgba(0,0,0,0.6); color: white; border-radius: 50%; border:none;
            align-items:center; justify-content:center; font-size: clamp(0.55rem, 1.1vh, 0.68rem); cursor:pointer;
        }
        .tuile-remove:hover { background: var(--danger); }
        .tuile-dossier-edit:hover { background: var(--accent); }
        .tuile-remove { top: 8px; left: 8px; }
        .tuile-dossier-edit { top: 8px; left: 34px; }
        .tuile-drag-mode .tuile-remove, .tuile-drag-mode .tuile-dossier-edit { display:flex; }
        .tuile-drag-mode.tuile-dossier .tuile-dossier-body .tuile-remove { display:flex; }

        /* --- Modaux (dossier, tuiles masquées) --- */
        .modal-bg { display: none; position: fixed; z-index: 5000; left: 0; top: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); backdrop-filter: blur(3px); align-items: center; justify-content: center; }
        .modal-bg.show { display: flex; }
        .modal-box { background: white; width: 420px; max-width: 90vw; max-height: 85vh; overflow-y: auto; overflow-x: hidden; padding: 24px; border-radius: 12px; box-shadow: 0 10px 25px rgba(0,0,0,0.2); }
        .modal-box-large { width: min(880px, 94vw); max-height: 94vh; }
        .dossier-form-split { display: flex; gap: 22px; align-items: flex-start; }
        .dossier-form-col { flex: 1 1 0; min-width: 0; }
        .field-block-label { font-weight: 700; font-size: 0.72rem; color: #7f8c8d; text-transform: uppercase; letter-spacing: .03em; margin-bottom: 8px; }
        /* auto-fill au lieu d'un nombre de colonnes fixe : la grille s'adapte toujours à la largeur
           réellement disponible (padding + gap + éventuelle scrollbar), donc plus jamais de débordement
           horizontal, quelle que soit la largeur exacte de la modale. */
        #modalDossier .dossier-form-col .icon-grid { grid-template-columns: repeat(auto-fill, minmax(58px, 1fr)); margin-bottom: 0; }
        #modalDossier .dossier-form-col .color-grid { grid-template-columns: repeat(auto-fill, minmax(46px, 1fr)); margin-bottom: 0; }
        #modalDossier #dossierFormDescription { margin-bottom: 12px; }
        @media screen and (max-width: 640px) { .dossier-form-split { flex-direction: column; } }

        /* --- Modale "contenu d'un dossier" : même habillage que la page d'accueil (photo + verre dépoli) --- */
        .modal-box h3 { margin: 0 0 16px; color: var(--primary); font-family: 'Segoe UI', sans-serif; }
        #modalVoirDossier .modal-box-accueil {
            width: 95vw; max-width: 1400px; max-height: 84vh;
            display: flex; flex-direction: column;
            background: linear-gradient(rgba(255, 255, 255, 0.22), rgba(255, 255, 255, 0.22)), url('img/fond.jpg') no-repeat center center;
            background-size: cover;
            border-radius: 22px;
            border: 1px solid rgba(255,255,255,0.35);
            box-shadow: 0 20px 50px rgba(0,0,0,0.5);
        }
        /* Padding généreux en haut : sans lui, le survol des tuiles (qui remontent de 8px + halo) se fait
           couper par le overflow-y:auto de ce conteneur, ce qui masquait le liseré de couleur en haut. */
        #modalVoirDossier .voir-dossier-corps { flex: 1 1 auto; overflow-y: auto; overflow-x: hidden; padding-top: 26px; padding-bottom: 26px; }
        #modalVoirDossier .modal-actions { flex: 0 0 auto; padding-top: 16px; }
        .voir-dossier-crumb { display: flex; align-items: center; gap: 8px; font-size: 0.94rem; font-weight: 600; color: rgba(255,255,255,0.92); text-shadow: 0 1px 5px rgba(0,0,0,0.8); margin-bottom: 10px; }
        .voir-dossier-crumb .crumb-home { color: inherit; text-decoration: none; display: inline-flex; align-items: center; gap: 5px; }
        .voir-dossier-crumb .crumb-home:hover { text-decoration: underline; }
        .voir-dossier-crumb .crumb-sep { color: rgba(255,255,255,0.55); font-weight: 400; }
        #modalVoirDossier h3 { font-family: 'Caveat', cursive; font-size: 2.2rem; font-weight: 700; color: #fff; margin-bottom: 4px; text-shadow: 0 2px 8px rgba(0,0,0,0.85); }
        .voir-dossier-description { margin: 0 0 16px; color: rgba(255,255,255,0.92); font-size: 0.85rem; text-shadow: 0 1px 5px rgba(0,0,0,0.8); }
        #modalVoirDossier .modal-btn-cancel { background: rgba(255,255,255,0.15); color: #fff; border: 1px solid rgba(255,255,255,0.4); backdrop-filter: blur(6px); }
        #modalVoirDossier .modal-btn-cancel:hover { background: rgba(255,255,255,0.28); }
        .voir-dossier-corps .tuile-dossier-body { display: grid !important; margin-top: 0; }
        /* Le voile de la modale est clair : on redonne aux tuiles un fond sombre translucide pour
           que leur texte blanc reste lisible (comme sur l'accueil, où c'est la photo assombrie qui joue ce rôle). */
        .voir-dossier-corps .tuile { min-height: 120px; --tuile-base: rgba(13, 19, 27, 0.82); }
        .voir-dossier-corps .tuile-dossier-empty { background: rgba(20, 26, 34, 0.4); }
        @media screen and (max-width: 700px) { .voir-dossier-corps .tuile-dossier-body { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
        .modal-box input[type=text] { width: 100%; box-sizing: border-box; padding: 11px; margin-bottom: 16px; border: 2px solid var(--accent); border-radius: 7px; font-weight: 600; outline: none; font-family: inherit; }
        .modal-actions { display: flex; justify-content: flex-end; gap: 10px; }
        .modal-actions button { padding: 10px 20px; border: none; border-radius: 7px; cursor: pointer; font-weight: 700; font-family: inherit; }
        .modal-btn-cancel { background: #eee; color: #333; }
        .modal-btn-ok { background: var(--accent); color: #fff; }

        .icon-grid { display: grid; grid-template-columns: repeat(6, minmax(0, 1fr)); gap: 8px; margin-bottom: 18px; }
        .icon-chip { display: flex; align-items: center; justify-content: center; border: 1.5px solid #ccd5db; background: #fff; border-radius: 9px; padding: 10px 4px; cursor: pointer; color: #64748b; transition: 0.15s; }
        .icon-chip:hover { border-color: var(--accent); color: var(--accent); }
        .icon-chip.active { border-color: var(--accent); background: rgba(52,152,219,0.1); color: var(--accent); }

        .color-grid { display: grid; grid-template-columns: repeat(6, minmax(0, 1fr)); gap: 8px; margin-bottom: 18px; }
        .modal-box-large .icon-grid, .modal-box-large .color-grid { grid-template-columns: repeat(8, minmax(0, 1fr)); }
        .color-chip { width: 100%; aspect-ratio: 1; border-radius: 9px; cursor: pointer; border: 2.5px solid transparent; box-shadow: inset 0 0 0 1px rgba(0,0,0,0.08); }
        .color-chip.active { border-color: var(--primary); transform: scale(1.08); }
        .color-chip-custom { position: relative; width: 100%; aspect-ratio: 1; border-radius: 9px; cursor: pointer; border: 2.5px solid #ccd5db; padding: 0; overflow: hidden; background: conic-gradient(red, yellow, lime, cyan, blue, magenta, red); }
        .color-chip-custom.active { border-color: var(--primary); transform: scale(1.08); }
        .color-chip-custom input[type=color] { position: absolute; inset: -4px; width: calc(100% + 8px); height: calc(100% + 8px); border: none; padding: 0; cursor: pointer; }
        .color-chip-custom-label { position: absolute; inset: 0; display: flex; align-items: center; justify-content: center; pointer-events: none; color: #fff; text-shadow: 0 1px 3px rgba(0,0,0,0.7); font-size: 0.8rem; }

        .masquee-row { display:flex; align-items:center; gap:12px; width:100%; box-sizing:border-box; padding: 10px 12px; border-radius: 9px; border: 1px solid #e3e8ec; margin-bottom: 8px; background:#fff; cursor:pointer; font-family: inherit; font-size: 0.85rem; font-weight:600; color:#2c3e50; text-align:left; }
        .masquee-row:hover { border-color: var(--accent); background: rgba(52,152,219,0.05); }
        .masquee-row .tuile-icon { width: 30px; height: 30px; font-size: 0.85rem; flex: none; }
        .set-empty { font-size: 0.85rem; color: #64748b; font-style: italic; padding: 6px 2px; }

        @media screen and (max-width: 820px) {
            .tuiles-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); grid-template-rows: repeat(8, minmax(0, 1fr)); gap: clamp(7px, 1.1vh, 14px); }
            .tuile { padding: clamp(8px, 1.2vh, 15px) clamp(9px, 2.6vw, 14px); gap: clamp(3px, 0.6vh, 7px); }
            .tuile .tuile-icon { width: clamp(30px, 4vh, 48px); height: clamp(30px, 4vh, 48px); font-size: clamp(0.9rem, 1.6vh, 1.2rem); }
            .tuile-titre { font-size: clamp(0.9rem, 1.7vh, 1.05rem); }
            .tuile-desc { display: none; }
            .tuile-aide { width: clamp(160px, 50%, 220px); }
            .accueil-hero { margin-bottom: clamp(6px, 1.1vh, 14px); }
            .accueil-logo { height: clamp(26px, 3.4vh, 54px); }
            .accueil-hero h1 { font-size: clamp(1.05rem, 1.7vh, 1.7rem); }
        }

        @media screen and (max-width: 768px) {
            .header-title { font-size: 1.2rem; }
        }

        /* --- TÉLÉPHONE (< 480px) ---
           À 820px, la grille à 8 lignes de hauteur égale (repeat(8, minmax(0,1fr))) tient dans la fenêtre
           car les titres restent sur 1 ligne. En dessous de ~480px, des titres à 2 mots ("Saisie &
           Historique", "Stats Technicien") passent sur 2 lignes : une hauteur de ligne figée les tronque
           alors verticalement. On repasse donc à des lignes de hauteur AUTO (dimensionnées par leur
           contenu) qui ne s'étirent pas (align-content:start) : la grille déborde alors naturellement vers
           le bas et défile (voir overflow-y:auto déjà posé sur .tuiles-grid), comme une liste mobile
           classique plutôt qu'un tableau de bord figé à l'écran. */
        @media screen and (max-width: 480px) {
            .tuiles-grid { grid-template-rows: none; grid-auto-rows: auto; align-content: start; }
            .tuile { min-height: 98px; }
            .tuile-titre { font-size: 0.92rem; line-height: 1.2; }
        }

        /* --- PAYSAGE TÉLÉPHONE (écran large mais peu haut, < 480px de hauteur) ---
           Aucun des seuils ci-dessus ne couvre ce cas : ils se basent tous sur la LARGEUR, or un téléphone
           en paysage est large (souvent > 820px, donc encore sur la grille "desktop" à 4 rangées de
           hauteur égale) mais très bas (~350-430px). Les 4 rangées de repeat(4, minmax(0,1fr)) se
           partagent alors une hauteur ridicule (ex. 24px/tuile) : icônes et titres se retrouvent
           écrasés/coupés. Même remède que côté largeur — lignes AUTO + défilement — mais déclenché par la
           HAUTEUR cette fois, indépendamment de la largeur disponible. */
        @media screen and (max-height: 480px) {
            .tuiles-grid { grid-template-rows: none; grid-auto-rows: auto; align-content: start; }
            .tuile { min-height: 56px; padding: 6px 10px; }
            .tuile-desc { display: none; }
            .tuile .tuile-icon { width: 26px; height: 26px; font-size: 0.8rem; }
            .accueil-hero { padding: 2px clamp(16px, 4vw, 30px); margin-bottom: 4px; }
            .accueil-icon { display: none; }
        }
    </style>
<?php include 'pwa_head.php'; ?>
</head>
<body onclick="checkCloseSidebar(event)">

<?php include 'navbar.php'; ?>

<div class="container">
    <div class="accueil-hero">
        <span class="accueil-icon"><i class="fa-solid fa-screwdriver-wrench"></i></span>
        <p class="accueil-brand"><?php echo htmlspecialchars($nom_entreprise_nav); ?></p>
        <h1><?php echo htmlspecialchars(t('index.greeting', ['{user}' => $_SESSION['user']])); ?></h1>
    </div>

    <div class="toolbar-accueil">
        <button type="button" id="btn-plus" class="btn-plus" onclick="togglePlusMenu(event)" title="<?php echo htmlspecialchars(t('index.add_button')); ?>">
            <i class="fa-solid fa-plus"></i> <?php echo htmlspecialchars(t('index.add_button')); ?>
        </button>
        <div class="plus-menu" id="plusMenu">
            <button type="button" onclick="ouvrirModalDossier()"><i class="fa-solid fa-folder-plus"></i> <?php echo htmlspecialchars(t('index.menu_new_folder')); ?></button>
            <button type="button" onclick="ouvrirModalMasquees()"><i class="fa-solid fa-eye"></i> <?php echo htmlspecialchars(t('index.menu_show_hidden')); ?></button>
        </div>
        <button type="button" id="btn-reorganiser" class="btn-reorganiser" onclick="toggleReorganisation()">
            <i class="fa-solid fa-arrows-up-down-left-right"></i> <?php echo htmlspecialchars(t('index.reorganize')); ?>
        </button>
    </div>

    <div class="tuiles-grid" id="tuilesGrid">
        <?php foreach ($grille as $item): ?>
            <?php if ($item['type'] === 'dossier'): ?>
                <div class="tuile tuile-dossier" data-dossier-id="<?php echo htmlspecialchars($item['id']); ?>" data-titre="<?php echo htmlspecialchars($item['titre']); ?>" data-description="<?php echo htmlspecialchars($item['desc']); ?>" data-icone="<?php echo htmlspecialchars($item['icone']); ?>" data-couleur="<?php echo htmlspecialchars($item['couleur']); ?>" style="--tuile-color: <?php echo htmlspecialchars($item['couleur']); ?>; --tuile-rgb: <?php echo hex_to_rgb($item['couleur']); ?>;">
                    <div class="tuile-dossier-header">
                        <?php if (!empty($item['badge'])): ?><span class="tuile-badge"><?php echo (int)$item['badge']; ?></span><?php endif; ?>
                        <span class="tuile-remove tuile-dossier-remove" title="<?php echo htmlspecialchars(t('index.dissolve_folder_tooltip')); ?>"><i class="fa-solid fa-xmark"></i></span>
                        <span class="tuile-dossier-edit" title="<?php echo htmlspecialchars(t('index.edit_folder_tooltip')); ?>"><i class="fa-solid fa-pen"></i></span>
                        <div class="tuile-icon"><i class="fa-solid <?php echo htmlspecialchars($item['icone']); ?>"></i></div>
                        <div class="tuile-titre"><?php echo htmlspecialchars($item['titre']); ?> <i class="fa-solid fa-chevron-down tuile-dossier-chevron"></i></div>
                        <?php if ($item['desc'] !== ''): ?><div class="tuile-desc"><?php echo htmlspecialchars($item['desc']); ?></div><?php endif; ?>
                    </div>
                    <div class="tuile-dossier-body">
                        <?php foreach ($item['enfants'] as $enf): ?>
                            <?php echo tuile_leaf_html($enf); ?>
                        <?php endforeach; ?>
                        <?php if (empty($item['enfants'])): ?><div class="tuile-dossier-empty"><?php echo htmlspecialchars(t('index.drop_tile_here')); ?></div><?php endif; ?>
                    </div>
                </div>
            <?php else: ?>
                <?php echo tuile_leaf_html($item); ?>
            <?php endif; ?>
        <?php endforeach; ?>

        <?php foreach ($tuiles_verrouillees as $t): ?>
            <div class="tuile tuile-locked" style="--tuile-color: <?php echo htmlspecialchars($t['couleur']); ?>; --tuile-rgb: <?php echo hex_to_rgb($t['couleur']); ?>;" title="<?php echo htmlspecialchars(t('index.admin_only_tooltip')); ?>">
                <span class="tuile-lock"><i class="fa-solid fa-lock"></i></span>
                <div class="tuile-icon"><i class="fa-solid <?php echo htmlspecialchars($t['icon']); ?>"></i></div>
                <div class="tuile-titre"><?php echo htmlspecialchars($t['titre']); ?></div>
                <div class="tuile-desc"><?php echo htmlspecialchars($t['desc']); ?></div>
            </div>
        <?php endforeach; ?>

        <a href="aide.php" class="tuile tuile-aide" data-href="aide.php" style="--tuile-color: <?php echo htmlspecialchars($couleur_aide); ?>; --tuile-rgb: <?php echo hex_to_rgb($couleur_aide); ?>;">
            <div class="tuile-icon"><i class="fa-solid <?php echo htmlspecialchars($icone_aide); ?>"></i></div>
            <div class="tuile-titre"><?php echo htmlspecialchars($titre_aide); ?></div>
            <div class="tuile-desc"><?php echo htmlspecialchars($desc_aide); ?></div>
        </a>
    </div>

    <div id="tuilesMasqueesTemplate" style="display:none;">
        <?php foreach ($tuiles_masquees as $t): ?>
            <?php echo tuile_leaf_html($t); ?>
        <?php endforeach; ?>
    </div>
</div>

<div class="modal-bg" id="modalDossier">
    <div class="modal-box modal-box-large">
        <h3 id="dossierModalTitle"><?php echo htmlspecialchars(t('index.folder_modal_new_title')); ?></h3>
        <input type="text" id="dossierFormTitre" placeholder="<?php echo htmlspecialchars(t('index.folder_name_placeholder')); ?>" maxlength="30">
        <textarea id="dossierFormDescription" rows="2" maxlength="120" placeholder="<?php echo htmlspecialchars(t('index.folder_desc_placeholder')); ?>" style="width:100%; box-sizing:border-box; padding:11px; margin-bottom:16px; border:2px solid var(--accent); border-radius:7px; font-family:inherit; font-size:0.9rem; resize:vertical;"></textarea>
        <div class="dossier-form-split">
            <div class="dossier-form-col">
                <div class="field-block-label"><?php echo htmlspecialchars(t('index.icon_label')); ?></div>
                <div class="icon-grid" id="dossierIconGrid">
                    <?php foreach ($ICONES_DOSSIER as $ic => $lbl): ?>
                    <div class="icon-chip" data-value="<?php echo htmlspecialchars($ic); ?>" title="<?php echo htmlspecialchars($lbl); ?>"><i class="fa-solid <?php echo htmlspecialchars($ic); ?>"></i></div>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="dossier-form-col">
                <div class="field-block-label"><?php echo htmlspecialchars(t('index.color_label')); ?></div>
                <div class="color-grid" id="dossierColorGrid">
                    <?php foreach ($COULEURS_DOSSIER as $col): ?>
                    <div class="color-chip" data-value="<?php echo htmlspecialchars($col); ?>" style="background:<?php echo htmlspecialchars($col); ?>;"></div>
                    <?php endforeach; ?>
                    <label class="color-chip-custom" title="<?php echo htmlspecialchars(t('index.custom_color_title')); ?>">
                        <input type="color" id="dossierColorCustom">
                        <span class="color-chip-custom-label"><i class="fa-solid fa-eye-dropper"></i></span>
                    </label>
                </div>
            </div>
        </div>
        <div class="modal-actions">
            <button type="button" class="modal-btn-cancel" onclick="closeModal('modalDossier')"><?php echo htmlspecialchars(t('index.cancel')); ?></button>
            <button type="button" class="modal-btn-ok" onclick="validerModalDossier()"><?php echo htmlspecialchars(t('index.save')); ?></button>
        </div>
    </div>
</div>

<div class="modal-bg" id="modalMasquees">
    <div class="modal-box">
        <h3><?php echo htmlspecialchars(t('index.hidden_tiles_title')); ?></h3>
        <div id="listeMasqueesBody"></div>
        <div class="modal-actions">
            <button type="button" class="modal-btn-cancel" onclick="closeModal('modalMasquees')"><?php echo htmlspecialchars(t('index.close')); ?></button>
        </div>
    </div>
</div>

<div class="modal-bg" id="modalVoirDossier">
    <div class="modal-box modal-box-accueil">
        <div class="voir-dossier-crumb">
            <a href="#" onclick="fermerModalDossier(); return false;" class="crumb-home"><i class="fa-solid fa-house"></i> <?php echo htmlspecialchars(t('index.crumb_home')); ?></a>
            <span class="crumb-sep">/</span>
            <span class="crumb-current" id="voirDossierCrumbTitre"><?php echo htmlspecialchars(t('index.folder_default_title')); ?></span>
        </div>
        <h3 id="voirDossierTitre"><?php echo htmlspecialchars(t('index.folder_default_title')); ?></h3>
        <p id="voirDossierDescription" class="voir-dossier-description"></p>
        <div id="voirDossierCorps" class="voir-dossier-corps"></div>
        <div class="modal-actions">
            <button type="button" class="modal-btn-cancel" onclick="fermerModalDossier()"><?php echo htmlspecialchars(t('index.close')); ?></button>
        </div>
    </div>
</div>

<script>
function openNav(e) { if (e) e.stopPropagation(); document.getElementById("mySidebar").style.width = "280px"; }
function closeNav() { document.getElementById("mySidebar").style.width = "0"; }
function checkCloseSidebar(event) {
    if (document.getElementById("mySidebar").style.width === "280px" && !document.getElementById("mySidebar").contains(event.target)) closeNav();
    const menu = document.getElementById('plusMenu');
    if (menu.classList.contains('show') && !menu.contains(event.target) && event.target.id !== 'btn-plus' && !document.getElementById('btn-plus').contains(event.target)) {
        menu.classList.remove('show');
    }
}

const ICONES_DOSSIER_JS = <?php echo json_encode(array_keys($ICONES_DOSSIER)); ?>;
const I18N_INDEX = {
    dropTileHere: <?php echo json_encode(t('index.drop_tile_here')); ?>,
    dissolveFolder: <?php echo json_encode(t('index.dissolve_folder_tooltip_short')); ?>,
    editFolder: <?php echo json_encode(t('index.edit_folder_tooltip')); ?>,
    reorganize: <?php echo json_encode(t('index.reorganize')); ?>,
    reorganizeDone: <?php echo json_encode(t('index.reorganize_done')); ?>,
    newFolderTitle: <?php echo json_encode(t('index.folder_modal_new_title')); ?>,
    editFolderTitle: <?php echo json_encode(t('index.folder_modal_edit_title')); ?>,
    folderDefaultTitle: <?php echo json_encode(t('index.folder_default_title')); ?>,
    noHiddenTiles: <?php echo json_encode(t('index.no_hidden_tiles')); ?>
};
let modeReorganisation = false;
let draggedTuile = null;
let dossierEnEdition = null;

/* ---------- Réorganisation / drag & drop ---------- */
function blockClicPendantEdition(e) { e.preventDefault(); }
function onTuileDragStart(e) {
    draggedTuile = e.currentTarget;
    e.dataTransfer.effectAllowed = 'move';
    e.stopPropagation();
    setTimeout(() => { draggedTuile.style.opacity = '0.4'; }, 0);
}
function estApresCurseur(e, cible) {
    const rect = cible.getBoundingClientRect();
    return (e.clientX - rect.left) > rect.width / 2;
}
function onTuileDragOver(e) {
    e.preventDefault();
    e.stopPropagation();
    const cible = e.currentTarget;
    if (!draggedTuile || cible === draggedTuile || cible.contains(draggedTuile)) return;
    const conteneur = cible.parentElement;
    if (estApresCurseur(e, cible)) { conteneur.insertBefore(draggedTuile, cible.nextSibling); }
    else { conteneur.insertBefore(draggedTuile, cible); }
}
// Dragover sur une tuile-dossier repliée : on se contente d'un survol (pas de déplacement du DOM tout
// de suite, sinon la tuile glissée disparaîtrait dans le dossier caché et ferait bouger toute la grille
// à chaque frame). Seul le cas dossier-sur-dossier réordonne en direct, comme pour deux tuiles simples.
function onDossierDragOver(e) {
    e.preventDefault();
    e.stopPropagation();
    const dossierEl = e.currentTarget;
    if (!draggedTuile || dossierEl === draggedTuile) return;
    if (draggedTuile.classList.contains('tuile-dossier')) {
        const conteneur = dossierEl.parentElement;
        if (estApresCurseur(e, dossierEl)) { conteneur.insertBefore(draggedTuile, dossierEl.nextSibling); }
        else { conteneur.insertBefore(draggedTuile, dossierEl); }
    }
}
// Le dépôt effectif dans le dossier n'a lieu qu'ici, au relâchement — pas pendant le survol.
function onDossierDrop(e) {
    e.preventDefault();
    e.stopPropagation();
    const dossierEl = e.currentTarget;
    dossierEl.classList.remove('drop-hover');
    if (!draggedTuile || draggedTuile === dossierEl || draggedTuile.classList.contains('tuile-dossier')) return;
    const body = dossierEl.querySelector('.tuile-dossier-body');
    body.appendChild(draggedTuile);
    majEtatVideDossier(body);
}
function onDossierBodyDragOver(e) {
    e.preventDefault();
    const body = e.currentTarget;
    if (!draggedTuile || draggedTuile.classList.contains('tuile-dossier') || body.contains(draggedTuile)) return;
    body.appendChild(draggedTuile);
}
function onTuileDrop(e) { e.preventDefault(); e.stopPropagation(); }
function onTuileDragEnd() {
    if (draggedTuile) draggedTuile.style.opacity = '1';
    draggedTuile = null;
    document.querySelectorAll('.tuile-dossier.drop-hover').forEach(d => d.classList.remove('drop-hover'));
    recalculerTousLesBadges();
}

/* ---------- Entrée en mode Réorganiser par appui long (comme sur mobile) ---------- */
function armerAppuiLong(el) {
    let minuteur = null;
    const demarrer = function () {
        if (modeReorganisation) return;
        minuteur = setTimeout(function () { toggleReorganisation(); }, 550);
    };
    const annuler = function () { clearTimeout(minuteur); };
    el.addEventListener('mousedown', function (e) { if (e.button === 0) demarrer(); });
    el.addEventListener('touchstart', demarrer, { passive: true });
    ['mouseup', 'mouseleave', 'dragstart', 'touchend', 'touchmove', 'touchcancel'].forEach(function (evt) {
        el.addEventListener(evt, annuler);
    });
}

function initTuile(el) {
    if (el.dataset.tuileInit) return;
    el.dataset.tuileInit = '1';
    el.addEventListener('dragstart', onTuileDragStart);
    el.addEventListener('drop', onTuileDrop);
    el.addEventListener('dragend', onTuileDragEnd);
    armerAppuiLong(el);
    const btnRemove = el.querySelector(':scope > .tuile-remove, :scope > .tuile-dossier-header > .tuile-remove');
    if (btnRemove) {
        btnRemove.addEventListener('click', function (e) {
            e.preventDefault(); e.stopPropagation();
            if (el.classList.contains('tuile-dossier')) { dissoudreDossier(el); }
            else if (el.closest('.tuile-dossier-body')) { extraireDeDossier(el); }
            else { masquerTuile(el); }
        });
    }
    const btnEdit = el.querySelector(':scope > .tuile-dossier-header > .tuile-dossier-edit');
    if (btnEdit) {
        btnEdit.addEventListener('click', function (e) {
            e.preventDefault(); e.stopPropagation();
            ouvrirModalDossier(el);
        });
    }
    if (el.classList.contains('tuile-dossier')) {
        el.addEventListener('dragover', onDossierDragOver);
        el.addEventListener('drop', onDossierDrop);
        el.addEventListener('dragenter', function () { if (draggedTuile && !draggedTuile.classList.contains('tuile-dossier')) el.classList.add('drop-hover'); });
        el.addEventListener('dragleave', function (e) { if (!el.contains(e.relatedTarget)) el.classList.remove('drop-hover'); });
        el.querySelector('.tuile-dossier-header').addEventListener('click', function () {
            ouvrirDossierModal(el);
        });
        const body = el.querySelector('.tuile-dossier-body');
        body.addEventListener('dragover', onDossierBodyDragOver);
        body.addEventListener('drop', onTuileDrop);
    } else {
        el.addEventListener('dragover', onTuileDragOver);
    }
}
document.querySelectorAll('.tuile, .tuile-dossier').forEach(initTuile);

function masquerTuile(el) {
    const dossierParent = el.closest('.tuile-dossier-body');
    el.removeAttribute('draggable');
    el.classList.remove('tuile-drag-mode');
    el.style.opacity = '';
    el.removeEventListener('click', blockClicPendantEdition);
    document.getElementById('tuilesMasqueesTemplate').appendChild(el);
    if (dossierParent) { majEtatVideDossier(dossierParent); }
    recalculerTousLesBadges();
}
function extraireDeDossier(el) {
    const dossierBody = el.closest('.tuile-dossier-body');
    const grid = document.getElementById('tuilesGrid');
    const pointAncrage = grid.querySelector('.tuile-locked, .tuile-aide');
    grid.insertBefore(el, pointAncrage);
    if (dossierBody) { majEtatVideDossier(dossierBody); }
    recalculerTousLesBadges();
}
function dissoudreDossier(dossierEl) {
    const body = dossierEl.querySelector('.tuile-dossier-body');
    const grid = document.getElementById('tuilesGrid');
    Array.from(body.children).forEach(enfant => {
        if (enfant.classList.contains('tuile')) grid.insertBefore(enfant, dossierEl);
    });
    dossierEl.remove();
}
function majEtatVideDossier(body) {
    const aDesEnfants = Array.from(body.children).some(c => c.classList.contains('tuile'));
    let placeholder = body.querySelector('.tuile-dossier-empty');
    if (!aDesEnfants && !placeholder) {
        placeholder = document.createElement('div');
        placeholder.className = 'tuile-dossier-empty';
        placeholder.textContent = I18N_INDEX.dropTileHere;
        body.appendChild(placeholder);
    } else if (aDesEnfants && placeholder) {
        placeholder.remove();
    }
}
function recalculerTousLesBadges() {
    document.querySelectorAll('.tuile-dossier').forEach(function (dossierEl) {
        const body = dossierEl.querySelector('.tuile-dossier-body');
        let total = 0;
        Array.from(body.children).forEach(c => { if (c.classList.contains('tuile')) total += parseInt(c.dataset.badge || '0', 10); });
        const header = dossierEl.querySelector('.tuile-dossier-header');
        let badgeEl = header.querySelector(':scope > .tuile-badge');
        if (total > 0) {
            if (!badgeEl) { badgeEl = document.createElement('span'); badgeEl.className = 'tuile-badge'; header.prepend(badgeEl); }
            badgeEl.textContent = total;
        } else if (badgeEl) {
            badgeEl.remove();
        }
        majEtatVideDossier(body);
    });
}

/* ---------- Ouverture d'un dossier en fenêtre modale (navigation normale) ---------- */
let dossierOuvertModal = null;
function ouvrirDossierModal(dossierEl) {
    dossierOuvertModal = dossierEl;
    document.getElementById('voirDossierTitre').textContent = dossierEl.dataset.titre;
    document.getElementById('voirDossierCrumbTitre').textContent = dossierEl.dataset.titre;
    const description = dossierEl.dataset.description || '';
    const pDescription = document.getElementById('voirDossierDescription');
    pDescription.textContent = description;
    pDescription.style.display = description ? 'block' : 'none';
    const body = dossierEl.querySelector('.tuile-dossier-body');
    document.getElementById('voirDossierCorps').appendChild(body);
    document.getElementById('modalVoirDossier').classList.add('show');
}
function fermerModalDossier() {
    if (dossierOuvertModal) {
        const body = document.getElementById('voirDossierCorps').querySelector('.tuile-dossier-body');
        if (body) { dossierOuvertModal.appendChild(body); }
        dossierOuvertModal = null;
    }
    document.getElementById('modalVoirDossier').classList.remove('show');
}

function toggleReorganisation() {
    modeReorganisation = !modeReorganisation;
    const btn = document.getElementById('btn-reorganiser');
    const btnPlus = document.getElementById('btn-plus');
    const elements = document.querySelectorAll('#tuilesGrid .tuile:not(.tuile-aide):not(.tuile-locked), #tuilesGrid .tuile-dossier');

    if (modeReorganisation) {
        btnPlus.disabled = true;
        document.getElementById('plusMenu').classList.remove('show');
        btn.innerHTML = '<i class="fa-solid fa-check"></i> ' + I18N_INDEX.reorganizeDone;
        btn.classList.add('active');
        elements.forEach(t => {
            t.setAttribute('draggable', 'true');
            t.classList.add('tuile-drag-mode');
            if (!t.classList.contains('tuile-dossier')) { t.addEventListener('click', blockClicPendantEdition); }
        });
    } else {
        btnPlus.disabled = false;
        btn.innerHTML = '<i class="fa-solid fa-arrows-up-down-left-right"></i> ' + I18N_INDEX.reorganize;
        btn.classList.remove('active');
        elements.forEach(t => {
            t.removeAttribute('draggable');
            t.classList.remove('tuile-drag-mode');
            t.removeEventListener('click', blockClicPendantEdition);
        });
        sauvegarderLayout();
    }
}

function construireLayoutDepuisDOM() {
    // Si une modale "voir le dossier" est encore ouverte, son corps (.tuile-dossier-body) a été déplacé
    // dans la modale et n'est plus un descendant de sa tuile-dossier : on le rattache d'abord, sinon
    // ce dossier serait lu comme vide (ou ferait planter la lecture).
    fermerModalDossier();
    const layout = [];
    Array.from(document.getElementById('tuilesGrid').children).forEach(el => {
        if (el.classList.contains('tuile-aide') || el.classList.contains('tuile-locked')) return;
        if (el.classList.contains('tuile-dossier')) {
            const body = el.querySelector('.tuile-dossier-body');
            layout.push({
                type: 'dossier', id: el.dataset.dossierId, titre: el.dataset.titre, desc: el.dataset.description || '',
                icone: el.dataset.icone, couleur: el.dataset.couleur,
                enfants: body ? Array.from(body.children).filter(c => c.classList.contains('tuile')).map(c => c.dataset.href) : [],
            });
        } else if (el.classList.contains('tuile') && el.dataset.href) {
            layout.push(el.dataset.href);
        }
    });
    const masquees = Array.from(document.getElementById('tuilesMasqueesTemplate').children).map(el => el.dataset.href);
    return { layout: layout, masquees: masquees };
}
async function sauvegarderLayout() {
    const fd = new FormData();
    fd.append('action', 'save_tuiles_ordre');
    fd.append('ordre', JSON.stringify(construireLayoutDepuisDOM()));
    try { await fetch('maintenance.php', { method: 'POST', body: fd }); } catch (e) {}
}

/* ---------- Menu "+" ---------- */
function togglePlusMenu(e) {
    e.stopPropagation();
    if (document.getElementById('btn-plus').disabled) return;
    document.getElementById('plusMenu').classList.toggle('show');
}

/* ---------- Modal dossier (création / édition) ---------- */
function setDossierIconValue(val) {
    document.getElementById('dossierIconGrid').dataset.value = val;
    document.querySelectorAll('#dossierIconGrid .icon-chip').forEach(c => c.classList.toggle('active', c.dataset.value === val));
}
function setDossierColorValue(val) {
    document.getElementById('dossierColorGrid').dataset.value = val;
    let estPreset = false;
    document.querySelectorAll('#dossierColorGrid .color-chip').forEach(function (c) {
        const actif = c.dataset.value === val;
        c.classList.toggle('active', actif);
        if (actif) estPreset = true;
    });
    document.getElementById('dossierColorCustom').value = /^#[0-9a-fA-F]{6}$/.test(val) ? val : '#34495e';
    document.getElementById('dossierColorCustom').closest('.color-chip-custom').classList.toggle('active', !estPreset);
}
document.querySelectorAll('#dossierIconGrid .icon-chip').forEach(chip => chip.addEventListener('click', () => setDossierIconValue(chip.dataset.value)));
document.querySelectorAll('#dossierColorGrid .color-chip').forEach(chip => chip.addEventListener('click', () => setDossierColorValue(chip.dataset.value)));
document.getElementById('dossierColorCustom').addEventListener('input', function () { setDossierColorValue(this.value); });

function ouvrirModalDossier(dossierElAEditer) {
    document.getElementById('plusMenu').classList.remove('show');
    dossierEnEdition = dossierElAEditer || null;
    document.getElementById('dossierModalTitle').textContent = dossierEnEdition ? I18N_INDEX.editFolderTitle : I18N_INDEX.newFolderTitle;
    document.getElementById('dossierFormTitre').value = dossierEnEdition ? dossierEnEdition.dataset.titre : '';
    document.getElementById('dossierFormDescription').value = dossierEnEdition ? (dossierEnEdition.dataset.description || '') : '';
    setDossierIconValue(dossierEnEdition ? dossierEnEdition.dataset.icone : 'fa-folder');
    setDossierColorValue(dossierEnEdition ? dossierEnEdition.dataset.couleur : '#34495e');
    document.getElementById('modalDossier').classList.add('show');
}

function creerElementDossier(id, titre, description, icone, couleur) {
    const rgb = [1, 3, 5].map(i => parseInt(couleur.slice(i, i + 2), 16)).join(', ');
    const div = document.createElement('div');
    div.className = 'tuile tuile-dossier';
    div.dataset.dossierId = id;
    div.dataset.titre = titre;
    div.dataset.description = description;
    div.dataset.icone = icone;
    div.dataset.couleur = couleur;
    div.style.setProperty('--tuile-color', couleur);
    div.style.setProperty('--tuile-rgb', rgb);
    div.innerHTML = '<div class="tuile-dossier-header">'
        + '<span class="tuile-remove tuile-dossier-remove" title="' + I18N_INDEX.dissolveFolder + '"><i class="fa-solid fa-xmark"></i></span>'
        + '<span class="tuile-dossier-edit" title="' + I18N_INDEX.editFolder + '"><i class="fa-solid fa-pen"></i></span>'
        + '<div class="tuile-icon"><i class="fa-solid ' + icone + '"></i></div>'
        + '<div class="tuile-titre">' + titre + ' <i class="fa-solid fa-chevron-down tuile-dossier-chevron"></i></div>'
        + (description ? '<div class="tuile-desc">' + description + '</div>' : '')
        + '</div>'
        + '<div class="tuile-dossier-body"><div class="tuile-dossier-empty">' + I18N_INDEX.dropTileHere + '</div></div>';
    return div;
}

function validerModalDossier() {
    const titre = document.getElementById('dossierFormTitre').value.trim().slice(0, 30) || I18N_INDEX.folderDefaultTitle;
    const description = document.getElementById('dossierFormDescription').value.trim().slice(0, 120);
    const icone = document.getElementById('dossierIconGrid').dataset.value || 'fa-folder';
    const couleur = document.getElementById('dossierColorGrid').dataset.value || '#34495e';
    if (!ICONES_DOSSIER_JS.includes(icone)) { return; }
    if (!/^#[0-9a-fA-F]{6}$/.test(couleur)) { return; }
    const titreEchappe = titre.replace(/</g, '&lt;');
    const descriptionEchappee = description.replace(/</g, '&lt;');

    if (dossierEnEdition) {
        dossierEnEdition.dataset.titre = titre;
        dossierEnEdition.dataset.description = description;
        dossierEnEdition.dataset.icone = icone;
        dossierEnEdition.dataset.couleur = couleur;
        const rgb = [1, 3, 5].map(i => parseInt(couleur.slice(i, i + 2), 16)).join(', ');
        dossierEnEdition.style.setProperty('--tuile-color', couleur);
        dossierEnEdition.style.setProperty('--tuile-rgb', rgb);
        dossierEnEdition.querySelector('.tuile-icon i').className = 'fa-solid ' + icone;
        dossierEnEdition.querySelector('.tuile-dossier-header .tuile-titre').innerHTML = titreEchappe + ' <i class="fa-solid fa-chevron-down tuile-dossier-chevron"></i>';
        let descEl = dossierEnEdition.querySelector(':scope > .tuile-dossier-header > .tuile-desc');
        if (description) {
            if (!descEl) {
                descEl = document.createElement('div');
                descEl.className = 'tuile-desc';
                dossierEnEdition.querySelector('.tuile-dossier-header').appendChild(descEl);
            }
            descEl.innerHTML = descriptionEchappee;
        } else if (descEl) {
            descEl.remove();
        }
        closeModal('modalDossier');
        sauvegarderLayout();
    } else {
        const id = 'dossier_' + Date.now().toString(36) + Math.random().toString(36).slice(2, 6);
        const el = creerElementDossier(id, titreEchappe, descriptionEchappee, icone, couleur);
        const grid = document.getElementById('tuilesGrid');
        const pointAncrage = grid.querySelector('.tuile-locked, .tuile-aide');
        grid.insertBefore(el, pointAncrage);
        initTuile(el);
        closeModal('modalDossier');
        sauvegarderLayout();
    }
}

/* ---------- Modal tuiles masquées ---------- */
function ouvrirModalMasquees() {
    document.getElementById('plusMenu').classList.remove('show');
    const conteneur = document.getElementById('tuilesMasqueesTemplate');
    const liste = document.getElementById('listeMasqueesBody');
    liste.innerHTML = '';
    const items = Array.from(conteneur.children);
    if (items.length === 0) {
        liste.innerHTML = '<p class="set-empty">' + I18N_INDEX.noHiddenTiles + '</p>';
    } else {
        items.forEach(el => {
            const row = document.createElement('button');
            row.type = 'button';
            row.className = 'masquee-row';
            const couleur = el.style.getPropertyValue('--tuile-color').trim();
            row.innerHTML = '<span class="tuile-icon" style="background:' + couleur + ';"><i class="fa-solid ' + el.dataset.icone + '"></i></span><span>' + el.dataset.titre + '</span>';
            row.addEventListener('click', function () {
                const grid = document.getElementById('tuilesGrid');
                const pointAncrage = grid.querySelector('.tuile-locked, .tuile-aide');
                grid.insertBefore(el, pointAncrage);
                closeModal('modalMasquees');
                recalculerTousLesBadges();
                sauvegarderLayout();
            });
            liste.appendChild(row);
        });
    }
    document.getElementById('modalMasquees').classList.add('show');
}

function closeModal(id) { document.getElementById(id).classList.remove('show'); }
window.addEventListener('click', function (event) {
    if (event.target.classList && event.target.classList.contains('modal-bg')) {
        if (event.target.id === 'modalVoirDossier') { fermerModalDossier(); }
        else { event.target.classList.remove('show'); }
    }
});
</script>

</body>
</html>
