<?php
require_once __DIR__ . '/session_init.php';
require_once 'db.php';

// --- HABILLAGE DES STATUTS (configurable depuis Paramètres > Statuts & priorités) ---
// Ne change QUE le libellé affiché sur le portail client : la classification (préfixe/substring
// sur le statut brut) et les valeurs stockées en base ne sont pas touchées.
$LIBELLES_WORKFLOW = [
    'afaire'  => ['label' => t('suivi.status_afaire')],
    'encours' => ['label' => t('suivi.status_encours')],
    'termine' => ['label' => t('suivi.status_termine')],
];
// Défauts français d'origine (avant l'i18n) : un libellé en base identique à l'un d'eux est traité
// comme non personnalisé (voir le même correctif sur les tuiles d'accueil, index.php) — sinon un
// libellé jamais retouché par David resterait figé en français quelle que soit la langue choisie.
$SNAPSHOTS_FR_WORKFLOW = ['afaire' => ['À faire'], 'encours' => ['En cours'], 'termine' => ['Terminé', 'Terminée']];
try {
    if (isset($db)) {
        foreach ($db->query("SELECT bucket, label FROM libelles_workflow WHERE bucket IN ('afaire','encours','termine')")->fetchAll(PDO::FETCH_ASSOC) as $l) {
            $label = $l['label'] ?? '';
            if ($label !== '' && !in_array($label, $SNAPSHOTS_FR_WORKFLOW[$l['bucket']] ?? [], true)) {
                $LIBELLES_WORKFLOW[$l['bucket']] = ['label' => $label];
            }
        }
    }
} catch (Exception $e) {}

if (!isset($_SESSION['user'])) { header("Location: login.php"); exit(); }

// --- AJAX : VÉRIFICATION DU CODE PERSONNEL (pour la messagerie) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'verify_pin') {
    header('Content-Type: application/json');
    $id_demandeur = $_POST['id_demandeur'];
    $pin = trim($_POST['pin']);

    try {
        $stmt = $db->prepare("SELECT code_personnel, prenom, username as nom, fonction FROM utilisateurs WHERE id = ?");
        $stmt->execute([$id_demandeur]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user) {
            if (empty($user['code_personnel'])) {
                $stmtUpdate = $db->prepare("UPDATE utilisateurs SET code_personnel = ? WHERE id = ?");
                $stmtUpdate->execute([$pin, $id_demandeur]);
                $user['code_personnel'] = $pin;
                echo json_encode(['success' => true, 'first_time' => true, 'user' => $user]);
            }
            elseif ($user['code_personnel'] === $pin) {
                echo json_encode(['success' => true, 'first_time' => false, 'user' => $user]);
            }
            else {
                echo json_encode(['success' => false, 'error' => 'bad_pin']);
            }
        } else {
            echo json_encode(['success' => false, 'error' => 'not_found']);
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => 'db_error']);
    }
    exit();
}
// --------------------------------------------------------

$user_session = trim($_SESSION['user']);

$personnel = [];
try {
    $stmtRole = $db->prepare("SELECT role FROM utilisateurs WHERE username = ?");
    $stmtRole->execute([$user_session]);
    $current_role = $stmtRole->fetchColumn();

    $stmt = $db->prepare("SELECT id, fonction, username as nom, prenom, code_personnel FROM utilisateurs WHERE role = ? AND (password IS NULL OR password = '') AND actif = 1 ORDER BY fonction ASC, username ASC");
    $stmt->execute([$current_role]);
    $personnel = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

function safe_json($data) {
    $json = json_encode($data, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
    return ($json === false || $json === 'null' || empty($json)) ? '[]' : $json;
}
$json_personnel = safe_json($personnel);
$json_user_session = safe_json($_SESSION['user']);

// --- RÉCUPÉRATION DE L'HISTORIQUE (FILTRAGE SÉCURISÉ PAR SERVICE — logique existante, inchangée) ---
// La requête est juste enrichie d'une jointure pour récupérer le nom de l'entreprise sous-traitante.
$historique_demandes = [];
try {
    if (isset($db)) {
        $nom_service = str_replace('S. ', '', $user_session);
        // Beaucoup de tickets déjà traités ont un "demandeur" écrasé par le technicien qui les a
        // édités (voir maintenance.php) — pour ne pas perdre ces tickets côté service, on retombe
        // sur le motif exact laissé par le portail dans la description : "DEMANDE DE : Nom (Fonction) - ...".
        // On ne cherche le nom du service QUE dans cette parenthèse de fonction, jamais dans tout le
        // texte libre : sinon un ticket qui mentionne juste le mot en passant (ex: "douche de sécurité",
        // une ligne qui s'appelle "Production"...) se retrouvait à tort dans le suivi d'un service
        // qui n'avait rien demandé.
        $motCle = (stripos($nom_service, 'agro') !== false) ? 'Agro' : $nom_service;

        // Un OT préventif est en général généré automatiquement par le système (jamais demandé par un
        // service externe) : on l'exclut du repli approximatif par description, car celui-ci pourrait
        // sinon matcher par coïncidence (ex: une ligne ou une zone qui s'appelle "Production"). Mais si
        // le champ demandeur correspond EXACTEMENT au compte du service, c'est une preuve certaine qu'il
        // a bien été demandé par ce service (même si le BI a ensuite été classé "Préventif" lors de son
        // traitement) : ce cas ne doit jamais être exclu, sous peine de rendre le ticket invisible à son
        // propre demandeur (bug constaté sur BI26-482, demandé par "S. Qualité" mais classé Préventif).
        $stmtHisto = $db->prepare("
            SELECT t.*, e.nom AS nom_entreprise
            FROM taches t
            LEFT JOIN entreprises_ext e ON t.entreprise_ext_id = e.id
            WHERE t.demandeur = ?
               OR (t.description LIKE ? AND (t.type IS NULL OR t.type <> 'Préventif'))
            ORDER BY t.date_creation DESC LIMIT 200
        ");

        $stmtHisto->execute([
            $user_session,
            'DEMANDE DE :%(%' . $motCle . '%)%'
        ]);
        $historique_demandes = $stmtHisto->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Exception $e) {}

// --- INTERVENANTS RÉELS, HEURES D'INTERVENTION & DATE DE RÉALISATION (basé sur les pointages) ---
// Le champ "tech" de la table taches ne reflète que la personne qui a créé/édité le BI (l'émetteur),
// pas forcément celle qui est intervenue. Le seul indicateur fiable de qui a réellement travaillé
// sur le ticket est la présence d'un pointage d'heures (voir aide_ot.php : "un pointage vous fait
// apparaître comme intervenant sur la fiche"). On en profite pour cumuler le temps passé (SUM des
// heures pointées, tous intervenants confondus) et déterminer la date de réalisation (le pointage
// le plus récent = le dernier jour où quelqu'un a réellement travaillé sur le ticket).
$intervenants_par_ticket = [];
$heures_par_ticket = [];
$date_realisation_par_ticket = [];
if (!empty($historique_demandes)) {
    try {
        $ids = array_column($historique_demandes, 'id');
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmtPtg = $db->prepare("SELECT task_id, tech, hours, date FROM pointages WHERE task_id IN ($placeholders)");
        $stmtPtg->execute($ids);
        foreach ($stmtPtg->fetchAll(PDO::FETCH_ASSOC) as $p) {
            if (!empty($p['tech']) && !in_array($p['tech'], $intervenants_par_ticket[$p['task_id']] ?? [], true)) {
                $intervenants_par_ticket[$p['task_id']][] = $p['tech'];
            }
            $heures_par_ticket[$p['task_id']] = ($heures_par_ticket[$p['task_id']] ?? 0) + (float)$p['hours'];
            if (!empty($p['date']) && (empty($date_realisation_par_ticket[$p['task_id']]) || $p['date'] > $date_realisation_par_ticket[$p['task_id']])) {
                $date_realisation_par_ticket[$p['task_id']] = $p['date'];
            }
        }
    } catch (Exception $e) {}
}

// --- STATISTIQUES RAPIDES (pour les vignettes du tableau de bord) ---
$stats = ['EN ATTENTE' => 0, 'A FAIRE' => 0, 'EN COURS' => 0, 'TERMIN' => 0, 'REFUS' => 0];
foreach ($historique_demandes as $t) {
    $s = strtoupper(trim($t['statut']));
    if (strpos($s, 'REFUS') !== false) $stats['REFUS']++;
    elseif ($s === 'EN ATTENTE') $stats['EN ATTENTE']++;
    elseif (strpos($s, 'COURS') !== false) $stats['EN COURS']++;
    elseif (strpos($s, 'TERMIN') !== false) $stats['TERMIN']++;
    else $stats['A FAIRE']++;
}
$stats_total = count($historique_demandes);
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(langue_actuelle()); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title><?php echo htmlspecialchars(t('suivi.page_title')); ?></title>
    <link rel="icon" type="image/png" href="img/logo.png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Caveat:wght@600;700&family=Segoe+UI:wght@400;500;600;700&family=Montserrat:wght@700;800;900&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root { --primary: #2c3e50; --accent: #3498db; --brand-orange: #f39c12; --brand-green: #2ecc71; --danger: #e74c3c; --violet: #8e44ad; --attente: #95a5a6; }
        * { box-sizing: border-box; }

        body {
            margin: 0; font-family: 'Segoe UI', sans-serif;
            background: linear-gradient(rgba(0,0,0,0.5), rgba(0,0,0,0.5)), url('img/fond.jpg') no-repeat center center fixed;
            background-color: #1a2733;
            background-size: cover; min-height: 100vh; padding: 58px 20px 60px; box-sizing: border-box;
        }

        .page-wrap { width: 100%; max-width: 1160px; margin: 0 auto; display: flex; flex-direction: column; gap: 18px; }

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

        /* ============ EN-TÊTE ============ */
        .topbar {
            background: rgba(255,255,255,0.97); border-radius: 14px; box-shadow: 0 10px 25px rgba(0,0,0,0.4);
            padding: 12px 20px; display:flex; align-items:center; justify-content:space-between; flex-wrap: wrap; gap: 10px;
        }
        .topbar-links { display:flex; align-items:center; gap: 18px; }
        .topbar-links a { color:#94a3b8; text-decoration:none; font-size: 0.8rem; display:flex; align-items:center; gap:6px; font-weight:600; transition: 0.15s; }
        .topbar-links a:hover { color: var(--accent); }
        .who-badge { background:#f1f5f9; padding: 6px 14px; border-radius: 20px; font-size: 0.8rem; font-weight: 600; color: var(--primary); display:flex; align-items:center; gap:8px; }

        .hero-suivi {
            background: rgba(255,255,255,0.09); backdrop-filter: blur(16px) brightness(1.15); -webkit-backdrop-filter: blur(16px) brightness(1.15);
            border: 1px solid rgba(255,255,255,0.35); border-radius: 18px; padding: 22px 26px; color: #fff;
            box-shadow: 0 10px 25px rgba(0,0,0,0.25); text-shadow: 0 2px 6px rgba(0,0,0,0.5);
        }
        .hero-suivi-top { display:flex; align-items:center; gap: 14px; flex-wrap: wrap; justify-content: space-between; }
        .hero-suivi h1 { font-family: 'Caveat', cursive; font-size: 2rem; margin: 0; font-weight: 700; display:flex; align-items:center; gap:12px; }
        .hero-suivi p { margin: 4px 0 0; font-size: 0.88rem; color: rgba(255,255,255,0.85); }
        #pastille-notification { display:none; background:var(--danger); color:white; border-radius:20px; padding:6px 14px; font-size:0.72rem; font-weight:600; box-shadow: 0 2px 8px rgba(231,76,60,0.5); text-transform:uppercase; white-space: nowrap; }

        /* ============ VIGNETTES STATISTIQUES ============ */
        .stats-row { display: grid; grid-template-columns: repeat(5, minmax(0, 1fr)); gap: 10px; margin-top: 18px; }
        @media (max-width: 780px) { .stats-row { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
        .stat-card {
            background: rgba(255,255,255,0.1); border: 1px solid rgba(255,255,255,0.3); border-radius: 12px;
            padding: 12px 10px; text-align: center; cursor: pointer; transition: 0.2s; color: #fff;
        }
        .stat-card:hover { background: rgba(255,255,255,0.18); transform: translateY(-2px); }
        .stat-card.is-active { background: rgba(255,255,255,0.28); border-color: #fff; }
        .stat-card .stat-num { font-family: 'Montserrat', sans-serif; font-weight: 700; font-size: 1.6rem; line-height: 1; }
        .stat-card .stat-label { font-size: 0.68rem; text-transform: uppercase; letter-spacing: 0.04em; font-weight: 600; color: rgba(255,255,255,0.85); margin-top: 4px; }
        .stat-card.stat-attente .stat-num { color: #f1c40f; }
        .stat-card.stat-afaire .stat-num { color: var(--brand-orange); }
        .stat-card.stat-encours .stat-num { color: #5dade2; }
        .stat-card.stat-termine .stat-num { color: var(--brand-green); }
        .stat-card.stat-refuse .stat-num { color: #e57373; }

        /* ============ CARTE SUIVI / FILTRES ============ */
        .tracking-card { background: rgba(255, 255, 255, 0.97); padding: 22px 26px; border-radius: 16px; box-shadow: 0 10px 25px rgba(0, 0, 0, 0.5); display: flex; flex-direction: column; border-top: 4px solid var(--accent); }
        .tracking-title { font-family: 'Montserrat', sans-serif; font-weight: 700; color: var(--primary); font-size: 1.05rem; margin: 0; text-transform: uppercase; letter-spacing: 0.03em; display:flex; align-items:center; gap:10px; }

        .filter-controls { margin-bottom: 16px; padding: 12px 16px; background: #f8fafc; border-radius: 10px; border: 1px solid #e2e8f0; display: flex; gap: 12px; align-items: center; flex-wrap: wrap; }
        .filter-controls .fc-label { font-size: 0.8rem; font-weight: 600; color: #64748b; display:flex; align-items:center; gap:6px; }
        .filter-controls input[type="text"], .filter-controls select {
            padding: 8px 10px; font-size: 0.82rem; border: 1px solid #cbd5e1; border-radius: 6px; font-family: inherit; background: #fff;
        }
        #filterSearch { flex: 1.4; min-width: 180px; }
        #filterDemandeur { flex: 1; min-width: 160px; max-width: 250px; }
        #filterStatut { flex: 1; min-width: 150px; max-width: 200px; }
        .btn-reset-filters { background: #eef2f6; border: 1px solid #cbd5e1; color: #64748b; border-radius: 6px; padding: 8px 12px; font-size: 0.78rem; font-weight: 600; cursor: pointer; display:flex; align-items:center; gap:6px; }
        .btn-reset-filters:hover { background: #e2e8f0; color: var(--primary); }

        .tickets-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
        @media (max-width: 860px) { .tickets-grid { grid-template-columns: 1fr; } }

        /* ============ CARTE TICKET (enrichie) ============ */
        .ticket-card { position: relative; background: #fff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 14px 16px 14px 18px; cursor: pointer; transition: 0.15s; overflow: hidden; }
        .ticket-card:hover { background: #f8fafc; transform: translateY(-2px); box-shadow: 0 8px 18px -6px rgba(0,0,0,0.18); }
        .tc-accent { position: absolute; left: 0; top: 0; bottom: 0; width: 5px; }
        .tc-top { display: flex; justify-content: space-between; align-items: center; gap: 8px; margin-bottom: 6px; }
        .tc-bi { font-size: 0.82rem; font-weight: 700; display:flex; align-items:center; gap:6px; }
        .tc-flags { display: flex; gap: 5px; }
        .flag { width: 20px; height: 20px; border-radius: 50%; display:flex; align-items:center; justify-content:center; font-size: 0.62rem; color: #fff; flex-shrink: 0; }
        .flag-urgent { background: var(--danger); animation: pulse-flag 1.6s ease-in-out infinite; }
        .flag-sous { background: var(--violet); }
        .flag-casse { background: #d35400; }
        @keyframes pulse-flag { 0%,100% { box-shadow: 0 0 0 0 rgba(231,76,60,0.5); } 50% { box-shadow: 0 0 0 5px rgba(231,76,60,0); } }

        .badge-text { font-size: 0.56rem; font-weight: 500; letter-spacing: 0.02em; padding: 3px 8px; border-radius: 20px; color: white; white-space: nowrap; }
        .bg-pending { background: #f1c40f; color: #000; }
        .bg-todo { background: var(--brand-orange); }
        .bg-progress { background: var(--accent); }
        .bg-done { background: var(--brand-green); }
        .bg-refuse { background: #7f1d1d; }
        .badge-unread { display:none; background:var(--danger); color:white; border-radius:20px; padding:2px 8px; font-size:0.62rem; font-weight:600; box-shadow: 0 1px 3px rgba(231,76,60,0.5); text-transform:uppercase; white-space:nowrap; }

        .tc-desc { font-size: 0.87rem; color: #334155; font-weight: 400; margin-bottom: 8px; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; line-height: 1.35; min-height: 2.35em; }

        .tc-meta { display: flex; flex-wrap: wrap; gap: 8px 14px; font-size: 0.68rem; color: #64748b; margin-bottom: 8px; }
        .tc-meta span { display: flex; align-items: center; gap: 5px; }
        .tc-meta i { color: var(--accent); }

        .tc-bottom { display: flex; justify-content: space-between; align-items: center; font-size: 0.68rem; color: #94a3b8; border-top: 1px dashed #eef1f3; padding-top: 8px; }
        .tc-bottom span { display: flex; align-items: center; gap: 5px; }

        .empty-state { text-align:center; padding: 40px 20px; color:#94a3b8; font-style:italic; grid-column: 1 / -1; }
        .empty-state i { font-size: 2.2rem; margin-bottom: 12px; color: #cbd5e1; display:block; }

        .pagination-row { display: flex; justify-content: space-between; align-items: center; margin-top: 18px; padding-top: 14px; border-top: 1px solid #e2e8f0; }
        .pagination-row button { padding: 8px 14px; border: 1px solid #cbd5e1; background: #fff; border-radius: 6px; cursor: pointer; color: var(--primary); font-weight: 600; font-size: 0.82rem; transition: 0.15s; }
        .pagination-row button:hover:not(:disabled) { background: #f1f5f9; }
        #pageInfo { font-size: 0.85rem; font-weight: 600; color: #64748b; }

        /* ============ MODALE DE DÉTAIL (design pro, police Inter) ============ */
        .modal-overlay { display: none; position: fixed; z-index: 9999; left: 0; top: 0; width: 100%; height: 100%; background: rgba(15, 23, 42, 0.72); backdrop-filter: blur(5px); align-items: center; justify-content: center; padding: 20px; }
        .modal-overlay.active { display: flex; }
        .modal-content-detail {
            background: white; padding: 0; border-radius: 18px; width: 100%; max-width: 760px; max-height: 92vh; overflow-y: auto;
            box-shadow: 0 30px 60px -12px rgba(15, 23, 42, 0.5), 0 0 0 1px rgba(15, 23, 42, 0.04);
            font-family: 'Inter', 'Segoe UI', sans-serif;
        }
        .detail-header { position: sticky; top:0; z-index:2; background: rgba(255,255,255,0.98); backdrop-filter: blur(6px); padding: 22px 32px; border-bottom: 1px solid #eef1f5; display: flex; justify-content: space-between; align-items: center; gap: 12px; flex-wrap: wrap; }
        .detail-header-bi { font-size: 1.05rem; font-weight: 500; letter-spacing: -0.005em; color: var(--brand-orange); }
        .btn-close { font-size: 22px; font-weight: 300; cursor: pointer; color: #94a3b8; line-height: 1; transition: 0.2s;}
        .btn-close:hover { color: var(--danger); }
        .detail-body { padding: 28px 32px 34px; }

        .status-tracker { display: flex; align-items: flex-start; margin-bottom: 28px; }
        .st-step { flex: 1; display: flex; flex-direction: column; align-items: center; text-align: center; position: relative; }
        .st-dot { width: 30px; height: 30px; border-radius: 50%; background: #eef1f5; color: #94a3b8; display:flex; align-items:center; justify-content:center; font-size: 0.72rem; font-weight: 500; border: 3px solid #fff; box-shadow: 0 0 0 2px #eef1f5; z-index: 1; transition: 0.3s; }
        .st-line { position: absolute; top: 15px; left: -50%; width: 100%; height: 2px; background: #eef1f5; z-index: 0; }
        .st-step:first-child .st-line { display: none; }
        .st-label { font-size: 0.58rem; font-weight: 500; text-transform: uppercase; color: #94a3b8; margin-top: 8px; letter-spacing: 0.04em; }
        .st-step.done .st-dot { background: var(--brand-green); color: #fff; box-shadow: 0 0 0 2px var(--brand-green); }
        .st-step.done .st-line { background: var(--brand-green); }
        .st-step.current .st-dot { background: var(--accent); color: #fff; box-shadow: 0 0 0 4px rgba(52,152,219,0.2); }
        .st-step.current .st-label { color: var(--accent); font-weight: 500; }
        /* Le trait qui mène à l'étape active doit être "rempli" comme les étapes déjà validées :
           seul le point reste bleu pour signaler "vous êtes ici", sinon le trait semblait non dessiné. */
        .st-step.current .st-line { background: var(--brand-green); }
        .st-step.done .st-label { color: var(--brand-green); }
        /* Refusée : seule l'étape réellement atteinte (marquée "current" par le JS, toujours la
           1ère) doit s'afficher en rouge — l'ancien sélecteur non ciblé (.st-dot tout court)
           colorait à tort les 4 points, laissant croire que les 4 étapes avaient eu lieu. */
        .status-tracker.refused .st-step.current .st-dot { background: #7f1d1d !important; color: #fff !important; box-shadow: 0 0 0 2px #7f1d1d !important; }
        .status-tracker.refused .st-step.current .st-label { color: #7f1d1d !important; }

        .dt-tag-row { display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 22px; }
        .dt-tag { display: inline-flex; align-items: center; gap: 6px; font-size: 0.64rem; font-weight: 500; padding: 5px 11px; border-radius: 20px; letter-spacing: 0.01em; }
        .dt-tag-type { background: #eef4fa; color: var(--accent); }
        .dt-tag-urgent { background: #fdeeec; color: var(--danger); }
        .dt-tag-casse { background: #fdf1e0; color: #b9720c; }
        .dt-tag-sous { background: #f4ecf9; color: var(--violet); }

        .data-row { margin-bottom: 20px; }
        .data-label { font-size: 0.6rem; text-transform: uppercase; font-weight: 500; color: #94a3b8; margin-bottom: 6px; display:flex; align-items:center; gap:6px; letter-spacing: 0.06em; }
        .data-val { font-size: 0.82rem; color: #334155; font-weight: 400; line-height: 1.5; }
        .dt-machine { color: var(--primary); font-weight: 500; font-size: 0.92rem; margin-top:3px; letter-spacing: -0.005em; }
        .desc-box { background: rgba(52, 152, 219, 0.04); padding: 14px 17px; border-radius: 10px; border: 1px solid rgba(52, 152, 219, 0.15); font-style: italic; font-weight: 400; font-size: 0.78rem; line-height: 1.55; }
        .carnet-box { background: rgba(243, 156, 18, 0.05); padding: 14px 17px; border-radius: 10px; border: 1px solid rgba(243, 156, 18, 0.2); font-size: 0.76rem; font-weight: 400; color: #b9720c; line-height: 1.55; }
        .retour-box { background: rgba(46, 204, 113, 0.05); padding: 14px 17px; border-radius: 10px; border: 1px solid rgba(46, 204, 113, 0.2); font-size: 0.78rem; font-weight: 400; color: #27ae60; line-height: 1.55; }
        .refus-box { background: rgba(127, 29, 29, 0.06); padding: 14px 17px; border-radius: 10px; border: 1px solid rgba(127, 29, 29, 0.2); font-size: 0.78rem; font-weight: 400; color: #7f1d1d; line-height: 1.55; }
        .sous-box { background: rgba(142, 68, 173, 0.05); padding: 14px 17px; border-radius: 10px; border: 1px solid rgba(142, 68, 173, 0.18); font-size: 0.78rem; font-weight: 400; color: #6c3483; line-height: 1.55; }
        .two-col { display: grid; grid-template-columns: 1fr 1fr; gap: 22px; }
        @media (max-width: 480px) { .two-col { grid-template-columns: 1fr; } }
        .three-col { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 22px; }
        @media (max-width: 680px) { .three-col { grid-template-columns: 1fr; } }
    </style>
<?php include 'pwa_head.php'; ?>
</head>
<body>

<a href="accueil.php" class="btn-floating-nav home" title="<?php echo htmlspecialchars(t('suivi.home_tooltip')); ?>" aria-label="<?php echo htmlspecialchars(t('suivi.home_tooltip')); ?>"><i class="fa-solid fa-house"></i></a>
<div style="position:fixed; top:20px; right:20px; z-index:1000;">
    <?php include 'lang_switcher.php'; ?>
</div>
<div class="crumb-bar">
    <a href="accueil.php" class="crumb-home"><?php echo htmlspecialchars(t('suivi.crumb_portal')); ?></a>
    <span class="crumb-sep">/</span>
    <span class="crumb-current"><?php echo htmlspecialchars(t('suivi.crumb_current')); ?></span>
</div>

<div class="page-wrap">
    <div class="topbar">
        <span class="who-badge"><i class="fa-solid fa-building"></i> <?php echo htmlspecialchars($_SESSION['user']); ?></span>
        <div class="topbar-links">
            <a href="demande.php"><i class="fa-solid fa-plus"></i> <?php echo htmlspecialchars(t('suivi.new_request')); ?></a>
        </div>
    </div>

    <div class="hero-suivi">
        <div class="hero-suivi-top">
            <div>
                <h1><i class="fa-solid fa-satellite-dish"></i> <?php echo htmlspecialchars(t('suivi.heading')); ?></h1>
                <p><?php echo t($stats_total > 1 ? 'suivi.subtitle_many' : 'suivi.subtitle_one', ['{n}' => $stats_total, '{user}' => htmlspecialchars($_SESSION['user'])]); ?></p>
            </div>
            <span id="pastille-notification">0</span>
        </div>

        <div class="stats-row">
            <div class="stat-card stat-attente" id="stat-EN ATTENTE" onclick="quickFilter('EN ATTENTE')">
                <div class="stat-num"><?php echo $stats['EN ATTENTE']; ?></div>
                <div class="stat-label"><?php echo htmlspecialchars(t('suivi.status_attente')); ?></div>
            </div>
            <div class="stat-card stat-afaire" id="stat-A FAIRE" onclick="quickFilter('A FAIRE')">
                <div class="stat-num"><?php echo $stats['A FAIRE']; ?></div>
                <div class="stat-label"><?php echo htmlspecialchars($LIBELLES_WORKFLOW['afaire']['label']); ?></div>
            </div>
            <div class="stat-card stat-encours" id="stat-EN COURS" onclick="quickFilter('EN COURS')">
                <div class="stat-num"><?php echo $stats['EN COURS']; ?></div>
                <div class="stat-label"><?php echo htmlspecialchars($LIBELLES_WORKFLOW['encours']['label']); ?></div>
            </div>
            <div class="stat-card stat-termine" id="stat-TERMIN" onclick="quickFilter('TERMIN')">
                <div class="stat-num"><?php echo $stats['TERMIN']; ?></div>
                <div class="stat-label"><?php echo htmlspecialchars($LIBELLES_WORKFLOW['termine']['label']); ?></div>
            </div>
            <div class="stat-card stat-refuse" id="stat-REFUS" onclick="quickFilter('REFUS')">
                <div class="stat-num"><?php echo $stats['REFUS']; ?></div>
                <div class="stat-label"><?php echo htmlspecialchars(t('suivi.status_refusee')); ?></div>
            </div>
        </div>
    </div>

    <div class="tracking-card">
        <div style="border-bottom: 2px solid #f1f5f9; padding-bottom: 10px; margin-bottom: 16px;">
            <h2 class="tracking-title"><i class="fa-solid fa-list-check"></i> <?php echo htmlspecialchars(t('suivi.details_title')); ?></h2>
        </div>

        <div class="filter-controls">
            <span class="fc-label"><i class="fa-solid fa-filter"></i> <?php echo htmlspecialchars(t('suivi.filter_label')); ?></span>

            <input type="text" id="filterSearch" oninput="currentPage=1; filterTicketsAdvanced()" placeholder="<?php echo htmlspecialchars(t('suivi.search_placeholder')); ?>">

            <select id="filterDemandeur" onchange="currentPage=1; filterTicketsAdvanced()">
                <option value=""><?php echo htmlspecialchars(t('suivi.filter_all_requesters')); ?></option>
                <?php
                foreach ($personnel as $p):
                    $nomComplet = trim(($p['prenom'] ?? '') . ' ' . $p['nom']);
                    $valeurFiltre = mb_strtoupper($nomComplet, 'UTF-8');
                ?>
                    <option value="<?php echo htmlspecialchars($valeurFiltre, ENT_QUOTES, 'UTF-8'); ?>">
                        <?php echo htmlspecialchars($nomComplet, ENT_QUOTES, 'UTF-8'); ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <select id="filterStatut" onchange="currentPage=1; filterTicketsAdvanced()">
                <option value=""><?php echo htmlspecialchars(t('suivi.filter_all_status')); ?></option>
                <option value="MESSAGE_NON_LU" style="font-weight:600; color:var(--danger);"><?php echo htmlspecialchars(t('suivi.filter_unread')); ?></option>
                <option value="EN ATTENTE"><?php echo htmlspecialchars(t('suivi.status_attente')); ?></option>
                <option value="A FAIRE"><?php echo htmlspecialchars($LIBELLES_WORKFLOW['afaire']['label']); ?></option>
                <option value="EN COURS"><?php echo htmlspecialchars($LIBELLES_WORKFLOW['encours']['label']); ?></option>
                <option value="TERMIN"><?php echo htmlspecialchars($LIBELLES_WORKFLOW['termine']['label']); ?></option>
                <option value="REFUS" style="color:var(--danger);"><?php echo htmlspecialchars(t('suivi.status_refusee')); ?></option>
            </select>

            <button type="button" class="btn-reset-filters" onclick="resetFilters()"><i class="fa-solid fa-rotate-left"></i> <?php echo htmlspecialchars(t('suivi.reset_filters')); ?></button>
        </div>

        <div class="tickets-grid" id="ticketsGrid">
            <?php if (empty($historique_demandes)): ?>
                <div class="empty-state">
                    <i class="fa-solid fa-clipboard-check"></i>
                    <?php echo htmlspecialchars(t('suivi.empty_no_ticket')); ?>
                </div>
            <?php else: ?>
                <?php foreach ($historique_demandes as $t):
                    $s = strtoupper(trim($t['statut']));
                    $bgClass = 'bg-todo';
                    $textBadge = mb_strtoupper($LIBELLES_WORKFLOW['afaire']['label']);
                    // $stepIndex = nombre d'étapes du tracker (Envoyée/Validée/Prise en charge/Terminée)
                    // RÉELLEMENT ATTEINTES, pas juste "le statut actuel" : "à faire" veut dire que la
                    // validation a déjà eu lieu (num_bi attribué), donc Validée doit s'afficher acquise
                    // (verte) et c'est Prise en charge qui devient l'étape en attente (bleue) — idem
                    // pour "en cours", où Prise en charge est déjà acquise et Terminée est en attente.
                    $stepIndex = 2; // défaut "à faire" : Envoyée + Validée acquises

                    if (strpos($s, 'REFUS') !== false) {
                        $textBadge = t('suivi.badge_refusee');
                        $bgClass = 'bg-refuse';
                        $stepIndex = -1;
                    } elseif ($s === 'EN ATTENTE') {
                        $textBadge = t('suivi.badge_attente_validation');
                        $bgClass = 'bg-pending';
                        $stepIndex = 1; // Envoyée acquise, Validée en attente
                    } elseif (strpos($s, 'COURS') !== false) {
                        $bgClass = 'bg-progress';
                        $textBadge = mb_strtoupper($LIBELLES_WORKFLOW['encours']['label']);
                        $stepIndex = 3; // + Prise en charge acquise, Terminée en attente
                    } elseif (strpos($s, 'TERMIN') !== false) {
                        $bgClass = 'bg-done';
                        $textBadge = mb_strtoupper($LIBELLES_WORKFLOW['termine']['label']);
                        $stepIndex = 4; // les 4 étapes acquises
                    }

                    $estRefusee = (strpos($s, 'REFUS') !== false);

                    $dateParts = explode(' ', $t['date_creation'] ?? $t['date']);
                    $dateStr = date('d/m/Y', strtotime($dateParts[0]));
                    // Date + heure complètes, pour l'encart de contexte de la messagerie.
                    $dateHeureStr = date('d/m/Y', strtotime($t['date_creation'] ?? $t['date'])) . ' ' . t('idee.date_at') . ' ' . date('H:i', strtotime($t['date_creation'] ?? $t['date']));

                    $isPending = empty($t['num_bi']) && !$estRefusee;
                    $biDisplay = $estRefusee ? t('suivi.bi_refusee') : ($isPending ? t('suivi.bi_attente') : "#" . htmlspecialchars($t['num_bi']));
                    $biColor = ($isPending || $estRefusee) ? "var(--danger)" : "var(--brand-orange)";

                    $cleanDesc = preg_replace('/\s*\[REF:.*?\]\s*$/', '', $t['description']);
                    $fullLoc = trim(implode(' > ', array_filter([$t['usine'], $t['secteur'], $t['ligne'], $t['zone']])));

                    // Les demandes venant du portail externe encodent "DEMANDE DE : Nom (Fonction) - texte"
                    // dans la description (le champ demandeur, lui, ne contient que le compte de service générique).
                    // On extrait le vrai nom du demandeur pour l'affichage et on nettoie la description en conséquence.
                    $demandeurNom = $t['demandeur'] ?: 'Non renseigné';
                    $demandeurFonction = '';
                    if (preg_match('/^DEMANDE DE\s*:\s*(.+?)\s*\(([^)]+)\)\s*-\s*(.*)$/su', $cleanDesc, $mDemande)) {
                        $demandeurNom = trim($mDemande[1]);
                        $demandeurFonction = trim($mDemande[2]);
                        $cleanDesc = trim($mDemande[3]);
                    }
                    $demandePar = $demandeurNom . ($demandeurFonction ? ' ' . $demandeurFonction : '');

                    $listeIntervenants = $intervenants_par_ticket[$t['id']] ?? [];
                    $intervenantAffiche = !empty($listeIntervenants) ? implode(', ', $listeIntervenants) : 'Aucun pointage enregistré';
                    $totalHeuresTicket = round($heures_par_ticket[$t['id']] ?? 0, 2);
                    $dateRealisationRaw = $date_realisation_par_ticket[$t['id']] ?? null;
                    $dateRealisationStr = $dateRealisationRaw ? date('d/m/Y', strtotime($dateRealisationRaw)) : '';

                    $estUrgent = (($t['prio'] ?? '') === 'Urgent');
                    $aCasse = !empty($t['casse']);
                    $aVerifVis = !empty($t['verif_vis']);
                    $estSousTraite = !empty($t['is_sous_traitant']);
                    $nomEntreprise = $t['nom_entreprise'] ?? '';
                    $typeInterv = $t['type'] ?? 'Curatif';
                    $typeIcon = 'fa-screwdriver-wrench';
                    if (stripos($typeInterv, 'prevent') !== false) $typeIcon = 'fa-calendar-check';
                    elseif (stripos($typeInterv, 'chantier') !== false) $typeIcon = 'fa-person-digging';

                    $accentColor = 'var(--brand-orange)';
                    if ($bgClass === 'bg-pending') $accentColor = '#f1c40f';
                    elseif ($bgClass === 'bg-progress') $accentColor = 'var(--accent)';
                    elseif ($bgClass === 'bg-done') $accentColor = 'var(--brand-green)';
                    elseif ($bgClass === 'bg-refuse') $accentColor = '#7f1d1d';

                    $searchBlob = mb_strtolower($cleanDesc . ' ' . ($t['equip'] ?? '') . ' ' . $biDisplay . ' ' . $fullLoc . ' ' . $demandePar, 'UTF-8');

                    $jsonTicket = htmlspecialchars(json_encode([
                        'id' => $t['id'],
                        'bi' => $biDisplay, 'isPending' => $isPending, 'date' => $dateStr, 'dateHeure' => $dateHeureStr, 'statutText' => $textBadge, 'statutBg' => $bgClass,
                        'stepIndex' => $stepIndex,
                        'localisation' => $fullLoc,
                        'machine' => $t['equip'], 'desc' => $cleanDesc, 'tech' => $intervenantAffiche,
                        'heures' => $totalHeuresTicket, 'dateRealisation' => $dateRealisationStr,
                        'demandeurNom' => $demandeurNom, 'demandeurFonction' => $demandeurFonction,
                        'rapport' => $t['compte_rendu'] ?: '',
                        'carnet' => $t['rapport_intermediaire'] ?: '',
                        'motifRefus' => $t['motif_refus'] ?? '',
                        'type' => $typeInterv, 'urgent' => $estUrgent, 'casse' => $aCasse, 'verifVis' => $aVerifVis,
                        'sousTraite' => $estSousTraite, 'entreprise' => $nomEntreprise
                    ]), ENT_QUOTES, 'UTF-8');
                ?>
                <div class="ticket-card" onclick="ouvrirDetailTicket(<?php echo $jsonTicket; ?>)">
                    <div class="tc-accent" style="background:<?php echo $accentColor; ?>"></div>

                    <div class="ticket-demandeur" style="display:none;"><?php echo htmlspecialchars($t['description']); ?></div>
                    <div class="ticket-statut-raw" style="display:none;"><?php echo $s; ?></div>
                    <div class="ticket-search-blob" style="display:none;"><?php echo htmlspecialchars($searchBlob); ?></div>

                    <div class="tc-top">
                        <span class="tc-bi" style="color: <?php echo $biColor; ?>;">
                            <?php echo $biDisplay; ?>
                            <span id="badge-ticket-<?php echo $t['id']; ?>" class="badge-unread">0</span>
                        </span>
                        <div class="tc-flags">
                            <?php if ($estUrgent): ?><span class="flag flag-urgent" title="<?php echo htmlspecialchars(t('suivi.flag_urgent')); ?>"><i class="fa-solid fa-bell"></i></span><?php endif; ?>
                            <?php if ($estSousTraite): ?><span class="flag flag-sous" title="<?php echo htmlspecialchars(t('suivi.flag_sous_traite')) . ($nomEntreprise ? ' : ' . htmlspecialchars($nomEntreprise) : ''); ?>"><i class="fa-solid fa-handshake"></i></span><?php endif; ?>
                            <?php if ($aCasse): ?><span class="flag flag-casse" title="<?php echo htmlspecialchars(t('suivi.flag_casse')); ?>"><i class="fa-solid fa-triangle-exclamation"></i></span><?php endif; ?>
                        </div>
                        <?php if ($s !== 'EN ATTENTE' || !empty($t['num_bi'])): ?>
                            <span class="badge-text <?php echo $bgClass; ?>"><?php echo $textBadge; ?></span>
                        <?php endif; ?>
                    </div>

                    <div class="tc-desc"><?php echo htmlspecialchars($cleanDesc); ?></div>

                    <div class="tc-meta">
                        <span><i class="fa-solid <?php echo $typeIcon; ?>"></i> <?php echo htmlspecialchars($typeInterv); ?></span>
                        <?php if (!empty($t['equip'])): ?><span><i class="fa-solid fa-gears"></i> <?php echo htmlspecialchars($t['equip']); ?></span><?php endif; ?>
                    </div>

                    <div class="tc-bottom">
                        <span style="overflow: hidden; text-overflow: ellipsis; white-space: nowrap; max-width: 60%;">
                            <i class="fa-solid fa-location-dot"></i> <?php echo htmlspecialchars($fullLoc ?: t('suivi.loc_non_precisee')); ?>
                        </span>
                        <span><i class="fa-regular fa-calendar"></i> <?php echo $dateStr; ?></span>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <div class="pagination-row">
            <button id="btnPrev" onclick="changePage(-1)"><i class="fa-solid fa-chevron-left"></i> <?php echo htmlspecialchars(t('suivi.pagination_prev')); ?></button>
            <span id="pageInfo"><?php echo htmlspecialchars(t('suivi.pagination_page_of', ['{n}' => '1', '{total}' => '1'])); ?></span>
            <button id="btnNext" onclick="changePage(1)"><?php echo htmlspecialchars(t('suivi.pagination_next')); ?> <i class="fa-solid fa-chevron-right"></i></button>
        </div>
    </div>
</div>

<div id="modalDetailTicket" class="modal-overlay" onclick="if(event.target == this) fermerDetailTicket()">
    <div class="modal-content-detail">
        <div class="detail-header">
            <div style="display:flex; align-items:center; gap:10px; flex-wrap: wrap;">
                <span class="detail-header-bi" id="dt-bi"></span>
                <span id="dt-statut" class="badge-text"></span>
            </div>
            <div style="display:flex; gap: 15px; align-items:center;">
                <button id="btn-relancer-demande" style="background:var(--brand-orange); color:white; border:none; padding:7px 14px; border-radius:6px; font-weight:600; cursor:pointer; font-size:0.75rem; transition:0.2s; text-transform:uppercase; box-shadow: 0 2px 4px rgba(243,156,18,0.35); display:none;" onmouseover="this.style.filter='brightness(0.92)';" onmouseout="this.style.filter='';">
                    <i class="fa-solid fa-bell" style="margin-right:4px;"></i><?php echo htmlspecialchars(t('suivi.btn_relancer')); ?>
                </button>
                <button id="btn-chat-demandeur" style="background:#3498db; color:white; border:none; padding:7px 14px; border-radius:6px; font-weight:600; cursor:pointer; font-size:0.75rem; transition:0.2s; text-transform:uppercase; box-shadow: 0 2px 4px rgba(52,152,219,0.3); display:none;" onmouseover="this.style.background='#2980b9';" onmouseout="this.style.background='#3498db';">
                    <i class="fa-solid fa-comments" style="margin-right:4px;"></i><?php echo htmlspecialchars(t('suivi.btn_messagerie')); ?>
                </button>
                <span class="btn-close" onclick="fermerDetailTicket()">&times;</span>
            </div>
        </div>
        <div class="detail-body">

            <div class="status-tracker" id="dt-tracker">
                <div class="st-step" data-step="0"><div class="st-line"></div><div class="st-dot"><i class="fa-solid fa-paper-plane"></i></div><div class="st-label"><?php echo htmlspecialchars(t('suivi.tracker_envoyee')); ?></div></div>
                <div class="st-step" data-step="1"><div class="st-line"></div><div class="st-dot"><i class="fa-solid fa-check"></i></div><div class="st-label"><?php echo htmlspecialchars(t('suivi.tracker_validee')); ?></div></div>
                <div class="st-step" data-step="2"><div class="st-line"></div><div class="st-dot"><i class="fa-solid fa-user-gear"></i></div><div class="st-label"><?php echo htmlspecialchars(t('suivi.tracker_prise_en_charge')); ?></div></div>
                <div class="st-step" data-step="3"><div class="st-line"></div><div class="st-dot"><i class="fa-solid fa-flag-checkered"></i></div><div class="st-label"><?php echo htmlspecialchars(t('suivi.tracker_terminee')); ?></div></div>
            </div>

            <div class="dt-tag-row" id="dt-tags"></div>

            <div class="three-col">
                <div class="data-row">
                    <div class="data-label"><i class="fa-regular fa-calendar"></i> <?php echo htmlspecialchars(t('suivi.label_date_emission')); ?></div>
                    <div class="data-val" id="dt-date"></div>
                </div>
                <div class="data-row">
                    <div class="data-label"><i class="fa-solid fa-user" style="color:var(--accent);"></i> <?php echo htmlspecialchars(t('suivi.label_demande_par')); ?></div>
                    <div class="data-val" id="dt-demandeur" style="color:var(--primary); font-weight:400;"></div>
                </div>
                <div class="data-row">
                    <div class="data-label"><i class="fa-solid fa-user-gear" style="color:var(--brand-orange);"></i> <?php echo htmlspecialchars(t('suivi.label_technicien')); ?></div>
                    <div class="data-val" id="dt-tech" style="color:var(--primary); font-weight:400;"></div>
                </div>
            </div>

            <div class="data-row">
                <div class="data-label"><i class="fa-solid fa-map-location-dot" style="color:var(--accent);"></i> <?php echo htmlspecialchars(t('suivi.label_loc_machine')); ?></div>
                <div class="data-val" style="color:var(--accent); font-size:0.66rem; font-weight:500; text-transform:uppercase;" id="dt-loc"></div>
                <div class="dt-machine" id="dt-machine"></div>
            </div>

            <div class="two-col" id="dt-realisation-row" style="display:none; margin-bottom:16px;">
                <div class="data-row" style="margin-bottom:0;">
                    <div class="data-label"><i class="fa-solid fa-hourglass-half" style="color:var(--brand-orange);"></i> <?php echo htmlspecialchars(t('suivi.label_heures')); ?></div>
                    <div class="data-val" id="dt-heures" style="color:var(--primary); font-weight:400;"></div>
                </div>
                <div class="data-row" style="margin-bottom:0;">
                    <div class="data-label"><i class="fa-solid fa-calendar-check" style="color:var(--brand-green);"></i> <?php echo htmlspecialchars(t('suivi.label_date_realisation')); ?></div>
                    <div class="data-val" id="dt-date-realisation" style="color:var(--primary); font-weight:400;"></div>
                </div>
            </div>

            <div class="data-row sous-box" id="dt-sous-row" style="display:none;">
                <div class="data-label" style="color:var(--violet);"><i class="fa-solid fa-handshake"></i> <?php echo htmlspecialchars(t('suivi.label_sous_traitee')); ?></div>
                <div id="dt-sous-nom" style="margin-top:5px; font-weight:400;"></div>
            </div>

            <div class="data-row" id="dt-photos-row">
                <div class="data-label"><i class="fa-solid fa-camera" style="color:var(--accent);"></i> <?php echo htmlspecialchars(t('suivi.label_photos')); ?> <span id="dt-photos-count" style="font-weight:400; color:#94a3b8; text-transform:none; letter-spacing:0;"></span></div>
                <div id="dt-photos-body" style="display:flex; flex-wrap:wrap; gap:8px; margin-top:6px;"></div>
            </div>

            <div class="data-row desc-box">
                <div class="data-label" style="color:var(--accent);"><i class="fa-solid fa-circle-exclamation"></i> <?php echo htmlspecialchars(t('suivi.label_description')); ?></div>
                <div id="dt-desc" style="margin-top:5px; color:#334155;"></div>
            </div>

            <div class="data-row refus-box" id="dt-refus-row" style="display:none;">
                <div class="data-label" style="color:var(--danger);"><i class="fa-solid fa-ban"></i> <?php echo htmlspecialchars(t('suivi.label_motif_refus')); ?></div>
                <div id="dt-motif-refus" style="margin-top:5px;"></div>
            </div>

            <div class="data-row carnet-box" id="dt-carnet-row" style="display:none;">
                <div class="data-label" style="color:#b9720c;"><i class="fa-solid fa-book"></i> <?php echo htmlspecialchars(t('suivi.label_carnet')); ?></div>
                <div id="dt-carnet" style="margin-top:5px;"></div>
            </div>

            <div class="data-row retour-box" id="dt-retour-row" style="display:none;">
                <div class="data-label" style="color:#27ae60;"><i class="fa-solid fa-comment-dots"></i> <?php echo htmlspecialchars(t('suivi.label_rapport')); ?></div>
                <div id="dt-rapport" style="margin-top:5px;"></div>
            </div>
        </div>
    </div>
</div>

<div id="modalAuthChat" class="modal-overlay" style="z-index: 200100;" onclick="if(event.target == this) this.style.display='none'">
    <div class="modal-content-detail" style="max-width:350px; border-top: 5px solid var(--danger); padding:20px; text-align:center; background:white; display:flex; flex-direction:column; gap:10px;" onclick="event.stopPropagation()">
        <i class="fa-solid fa-lock" style="font-size:3rem; color:var(--danger); margin-bottom:5px;"></i>
        <h3 style="margin:0; color:var(--primary); font-family:'Segoe UI', sans-serif;"><?php echo htmlspecialchars(t('suivi.auth_title')); ?></h3>
        <p style="font-size:0.85rem; color:#666; margin-bottom:15px; margin-top:5px;"><?php echo htmlspecialchars(t('suivi.auth_desc')); ?></p>

        <input type="hidden" id="auth-chat-task-id">
        <input type="hidden" id="auth-chat-tech-name">

        <div style="text-align:left;">
            <label style="font-size:0.7rem; font-weight:600; color:#555;"><?php echo htmlspecialchars(t('suivi.auth_who')); ?></label>
            <select id="auth-chat-user" style="width:100%; padding:10px; border-radius:6px; border:1px solid #ccc; margin-top:5px; font-family:inherit;"></select>
        </div>

        <div style="text-align:left; margin-bottom:10px;">
            <label style="font-size:0.7rem; font-weight:600; color:#555;"><?php echo htmlspecialchars(t('suivi.auth_code')); ?></label>
            <input type="password" id="auth-chat-pin" placeholder="••••" maxlength="10" style="width:100%; padding:10px; border-radius:6px; border:1px solid #ccc; margin-top:5px; text-align:center; letter-spacing:5px; font-weight:600; font-family:inherit;">
        </div>

        <div style="display:flex; gap:10px; margin-top:10px;">
            <button onclick="document.getElementById('modalAuthChat').style.display='none'" style="flex:1; padding:10px; border:none; border-radius:6px; background:#eee; color:#555; cursor:pointer; font-weight:600; transition:0.2s;"><?php echo htmlspecialchars(t('suivi.auth_cancel')); ?></button>
            <button onclick="validerAuthChat()" style="flex:1; padding:10px; border:none; border-radius:6px; background:var(--danger); color:white; cursor:pointer; font-weight:600; transition:0.2s;"><?php echo htmlspecialchars(t('suivi.auth_unlock')); ?></button>
        </div>
    </div>
</div>

<?php include 'composant_messagerie.php'; ?>

<script>
const personnelData = <?php echo $json_personnel; ?>;
const sessionUser = <?php echo $json_user_session; ?>;
let currentUserChat = sessionUser;

const I18N_SUIVI = <?php echo json_encode([
    'err_title' => t('maint.err_title'),
    'err_network_title' => t('maint.err_network_title'),
    'loc_non_precisee' => t('suivi.loc_non_precisee'),
    'machine_non_precise' => t('suivi.machine_non_precise'),
    'aucune_heure' => t('suivi.aucune_heure'),
    'date_non_renseignee' => t('suivi.date_non_renseignee'),
    'entreprise_a_preciser' => t('suivi.entreprise_a_preciser'),
    'tag_type_default' => 'Curatif',
    'tag_urgent' => t('suivi.flag_urgent'),
    'tag_casse' => t('suivi.flag_casse'),
    'tag_verif_vis' => t('suivi.tag_verif_vis'),
    'tag_sous_traite' => t('suivi.flag_sous_traite'),
    'bi_attente' => t('suivi.bi_attente'),
    'chargement' => t('suivi.chargement'),
    'aucune_photo' => t('suivi.aucune_photo'),
    'select_your_name' => t('suivi.select_your_name'),
    'alert_missing_auth' => t('suivi.alert_missing_auth'),
    'alert_bad_pin' => t('suivi.alert_bad_pin'),
    'alert_network' => t('suivi.alert_network'),
    'no_match_filters' => t('suivi.no_match_filters'),
    'pagination_page_of' => t('suivi.pagination_page_of'),
    'msg_unread_one' => t('suivi.msg_unread_one'),
    'msg_unread_many' => t('suivi.msg_unread_many'),
    'msg_unread_global' => t('suivi.msg_unread_global'),
]); ?>;

/* ================= DÉTAIL D'UN TICKET (SUIVI) ================= */
function escapeHtml(str) {
    const div = document.createElement('div');
    div.textContent = str ?? '';
    return div.innerHTML;
}

function ouvrirDetailTicket(ticket) {
    document.getElementById('dt-bi').innerText = ticket.bi;
    document.getElementById('dt-date').innerText = ticket.date;
    document.getElementById('dt-loc').innerText = ticket.localisation || I18N_SUIVI.loc_non_precisee;
    document.getElementById('dt-machine').innerText = ticket.machine || I18N_SUIVI.machine_non_precise;
    document.getElementById('dt-desc').innerText = ticket.desc;

    // Affichage "Fonction, Nom" (ex: "Chef de production, Sylvain Fontaine") plutôt que le nom
    // suivi de la fonction sur une ligne à part, à la demande de David.
    const demandeurHtml = ticket.demandeurFonction
        ? `${escapeHtml(ticket.demandeurFonction)}, ${escapeHtml(ticket.demandeurNom)}`
        : escapeHtml(ticket.demandeurNom);
    document.getElementById('dt-demandeur').innerHTML = demandeurHtml;
    document.getElementById('dt-tech').innerText = ticket.tech;

    // Heures d'intervention + date de réalisation : basées sur les pointages réels des techniciens,
    // donc masquées tant qu'aucun pointage n'existe (ticket pas encore réellement travaillé).
    const aDesPointages = (parseFloat(ticket.heures) > 0) || !!ticket.dateRealisation;
    document.getElementById('dt-realisation-row').style.display = aDesPointages ? '' : 'none';
    document.getElementById('dt-heures').innerText = (parseFloat(ticket.heures) > 0) ? parseFloat(ticket.heures).toFixed(2).replace('.', ',') + ' h' : I18N_SUIVI.aucune_heure;
    document.getElementById('dt-date-realisation').innerText = ticket.dateRealisation || I18N_SUIVI.date_non_renseignee;

    chargerPhotosTicket(ticket.id);

    const estRefusee = !!ticket.motifRefus || ticket.stepIndex === -1;
    document.getElementById('dt-refus-row').style.display = estRefusee ? 'block' : 'none';
    document.getElementById('dt-motif-refus').innerText = ticket.motifRefus || '';

    document.getElementById('dt-carnet-row').style.display = (!estRefusee && ticket.carnet) ? 'block' : 'none';
    document.getElementById('dt-carnet').innerHTML = (ticket.carnet || '').replace(/\n/g, '<br>');

    document.getElementById('dt-retour-row').style.display = (!estRefusee && ticket.rapport) ? 'block' : 'none';
    document.getElementById('dt-rapport').innerHTML = (ticket.rapport || '').replace(/\n/g, '<br>');

    document.getElementById('dt-sous-row').style.display = ticket.sousTraite ? 'block' : 'none';
    document.getElementById('dt-sous-nom').innerText = ticket.entreprise ? ticket.entreprise : I18N_SUIVI.entreprise_a_preciser;

    // Étiquettes (type, urgent, casse, sous-traitance)
    const tagsWrap = document.getElementById('dt-tags');
    let tagsHtml = `<span class="dt-tag dt-tag-type"><i class="fa-solid fa-wrench"></i> ${ticket.type || I18N_SUIVI.tag_type_default}</span>`;
    if (ticket.urgent) tagsHtml += `<span class="dt-tag dt-tag-urgent"><i class="fa-solid fa-bell"></i> ${I18N_SUIVI.tag_urgent}</span>`;
    if (ticket.casse) tagsHtml += `<span class="dt-tag dt-tag-casse"><i class="fa-solid fa-triangle-exclamation"></i> ${I18N_SUIVI.tag_casse}</span>`;
    if (ticket.verifVis) tagsHtml += `<span class="dt-tag dt-tag-casse"><i class="fa-solid fa-screwdriver"></i> ${I18N_SUIVI.tag_verif_vis}</span>`;
    if (ticket.sousTraite) tagsHtml += `<span class="dt-tag dt-tag-sous"><i class="fa-solid fa-handshake"></i> ${I18N_SUIVI.tag_sous_traite}</span>`;
    tagsWrap.innerHTML = tagsHtml;

    // Timeline de statut
    const tracker = document.getElementById('dt-tracker');
    tracker.classList.toggle('refused', estRefusee);
    document.querySelectorAll('#dt-tracker .st-step').forEach(step => {
        const idx = parseInt(step.dataset.step, 10);
        step.classList.remove('done', 'current');
        if (!estRefusee) {
            // ticket.stepIndex = nombre d'étapes réellement acquises (voir suivi.php, calcul PHP).
            // Les étapes déjà acquises passent en vert ; l'étape juste après (celle qu'on attend)
            // passe en bleu "current". Une fois les 4 acquises (Terminée), idx ne peut jamais valoir
            // 4 : tout reste donc naturellement vert, sans étape bleue restante.
            if (idx < ticket.stepIndex) step.classList.add('done');
            else if (idx === ticket.stepIndex) step.classList.add('current');
        } else if (idx === 0) {
            step.classList.add('current');
        }
    });

    const elStatut = document.getElementById('dt-statut');
    elStatut.innerText = ticket.statutText;
    elStatut.className = "badge-text " + ticket.statutBg;

    if (ticket.isPending || estRefusee) {
        elStatut.style.backgroundColor = "var(--danger)";
        elStatut.style.color = "white";
    } else {
        elStatut.style.backgroundColor = "";
        elStatut.style.color = "";
    }

    document.getElementById('modalDetailTicket').classList.add('active');
    const btnChat = document.getElementById('btn-chat-demandeur');
    btnChat.style.display = 'block';
    btnChat.onclick = () => demanderAuthChat(ticket);

    // "Relancer" : tant que le dossier attend encore une validation (pas de num_bi
    // attribué), ou une fois converti en BI tant qu'il reste "à faire" (pas encore
    // pris en charge) — dans les deux cas, la maintenance ne s'est pas encore
    // manifestée et une relance a du sens. Le message envoyé diffère selon le cas
    // (voir chat.relance_message / chat.relance_message_bi). Une fois "en cours"
    // ou "terminée", la maintenance s'est déjà manifestée : la messagerie normale
    // suffit, plus besoin du raccourci de relance.
    const btnRelance = document.getElementById('btn-relancer-demande');
    btnRelance.style.display = (ticket.isPending || ticket.statutBg === 'bg-todo') ? 'block' : 'none';
    btnRelance.onclick = () => demanderAuthChat(ticket, true);
}

function fermerDetailTicket() {
    document.getElementById('modalDetailTicket').classList.remove('active');
}

// --- PHOTOS jointes au ticket (voir bi_photos.php) : simple lecture, pas d'ajout/suppression
// depuis le portail service (réservé à la maintenance, voir composant_rapport.php).
async function chargerPhotosTicket(taskId) {
    const body = document.getElementById('dt-photos-body');
    const countEl = document.getElementById('dt-photos-count');
    if (!body) return;
    body.innerHTML = `<span style="font-size:0.7rem; color:#94a3b8;"><i class="fa-solid fa-spinner fa-spin"></i> ${I18N_SUIVI.chargement}</span>`;
    if (countEl) countEl.textContent = '';

    let photos = [];
    try {
        const res = await fetch('bi_photos.php?action=list&task_id=' + encodeURIComponent(taskId));
        const data = await res.json();
        if (data.success) photos = data.photos;
    } catch (e) { console.error("Erreur chargement photos :", e); }

    if (countEl) countEl.textContent = photos.length > 0 ? '(' + photos.length + ')' : '';

    if (photos.length === 0) {
        body.innerHTML = `<span style="font-size:0.75rem; color:#94a3b8; font-style:italic;">${I18N_SUIVI.aucune_photo}</span>`;
        return;
    }

    const escAttr = (s) => (s || '').replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;');
    body.innerHTML = photos.map(p => `
        <div style="position:relative; width:78px; height:78px; border-radius:8px; overflow:hidden; border:1px solid #e2e8f0; flex-shrink:0;">
            <img src="${p.chemin}" alt="${escAttr(p.nom_original)}" style="width:100%; height:100%; object-fit:cover; display:block; cursor:pointer;" onclick="ouvrirPhotoTicketLightbox('${p.chemin}')">
        </div>`).join('');
}

function ouvrirPhotoTicketLightbox(url) {
    let overlay = document.getElementById('suiviPhotoLightbox');
    if (!overlay) {
        overlay = document.createElement('div');
        overlay.id = 'suiviPhotoLightbox';
        overlay.style.cssText = 'display:flex; position:fixed; z-index:200200; left:0; top:0; width:100%; height:100%; background:rgba(0,0,0,0.85); align-items:center; justify-content:center; cursor:zoom-out; padding:20px; box-sizing:border-box;';
        overlay.onclick = () => { overlay.style.display = 'none'; };
        overlay.innerHTML = '<img id="suiviPhotoLightboxImg" style="max-width:100%; max-height:100%; border-radius:6px; box-shadow:0 10px 40px rgba(0,0,0,0.5);">';
        document.body.appendChild(overlay);
    }
    document.getElementById('suiviPhotoLightboxImg').src = url;
    overlay.style.display = 'flex';
}

let currentPage = 1;
const itemsPerPage = 8;

function cleanAccents(str) {
    return str.normalize("NFD").replace(/[̀-ͯ]/g, "");
}

function quickFilter(statutValue) {
    document.getElementById('filterStatut').value = statutValue;
    currentPage = 1;
    filterTicketsAdvanced();
}

function resetFilters() {
    document.getElementById('filterSearch').value = '';
    document.getElementById('filterDemandeur').value = '';
    document.getElementById('filterStatut').value = '';
    currentPage = 1;
    filterTicketsAdvanced();
}

function filterTicketsAdvanced() {
    const demFilter = cleanAccents(document.getElementById('filterDemandeur').value.toUpperCase());
    const statFilterRaw = document.getElementById('filterStatut').value;
    const statFilter = cleanAccents(statFilterRaw.toUpperCase());
    const searchTerm = cleanAccents(document.getElementById('filterSearch').value.trim().toLowerCase());

    document.querySelectorAll('.stat-card').forEach(c => c.classList.toggle('is-active', statFilterRaw !== '' && c.id === 'stat-' + statFilterRaw));

    const items = Array.from(document.getElementsByClassName('ticket-card'));
    let visibleItems = [];

    items.forEach(item => {
        const dem = cleanAccents(item.querySelector('.ticket-demandeur')?.innerText.toUpperCase() || "");
        const stat = cleanAccents(item.querySelector('.ticket-statut-raw')?.innerText.toUpperCase() || "");
        const blob = cleanAccents(item.querySelector('.ticket-search-blob')?.innerText.toLowerCase() || "");

        let matchStatut = false;

        if (statFilterRaw === "MESSAGE_NON_LU") {
            const badge = item.querySelector('[id^="badge-ticket-"]');
            if (badge && badge.style.display === 'inline-block') {
                matchStatut = true;
            }
        } else if (statFilter === "" || stat.includes(statFilter)) {
            matchStatut = true;
        }

        const matchSearch = searchTerm === "" || blob.includes(searchTerm);
        const match = dem.includes(demFilter) && matchStatut && matchSearch;

        if (match) {
            visibleItems.push(item);
        }
        item.style.display = "none";
    });

    const totalPages = Math.ceil(visibleItems.length / itemsPerPage) || 1;
    if (currentPage > totalPages) currentPage = totalPages;
    if (currentPage < 1) currentPage = 1;

    const startIndex = (currentPage - 1) * itemsPerPage;
    const endIndex = startIndex + itemsPerPage;

    for (let i = startIndex; i < endIndex && i < visibleItems.length; i++) {
        visibleItems[i].style.display = "";
    }

    const grid = document.getElementById('ticketsGrid');
    let emptyMsg = grid.querySelector('.empty-state-dynamic');
    if (visibleItems.length === 0 && items.length > 0) {
        if (!emptyMsg) {
            emptyMsg = document.createElement('div');
            emptyMsg.className = 'empty-state empty-state-dynamic';
            emptyMsg.innerHTML = '<i class="fa-solid fa-filter-circle-xmark"></i>' + I18N_SUIVI.no_match_filters;
            grid.appendChild(emptyMsg);
        }
        emptyMsg.style.display = '';
    } else if (emptyMsg) {
        emptyMsg.style.display = 'none';
    }

    document.getElementById('pageInfo').innerText = I18N_SUIVI.pagination_page_of.replace('{n}', currentPage).replace('{total}', totalPages);
    document.getElementById('btnPrev').disabled = (currentPage === 1);
    document.getElementById('btnNext').disabled = (currentPage === totalPages);

    document.getElementById('btnPrev').style.opacity = (currentPage === 1) ? "0.5" : "1";
    document.getElementById('btnNext').style.opacity = (currentPage === totalPages) ? "0.5" : "1";
}

function changePage(direction) {
    currentPage += direction;
    filterTicketsAdvanced();
}

document.addEventListener('DOMContentLoaded', function() {
    filterTicketsAdvanced();
});

/* ================= MESSAGERIE ================= */
let chatTicketContext = null; // Ticket complet (localisation, panne, date...) pour l'encart de contexte de la messagerie.
let relanceEnCours = false; // true quand l'auth PIN qui suit vient du bouton "Relancer" : un message de relance auto est envoyé une fois le chat ouvert.

function demanderAuthChat(ticket, isRelance = false) {
    chatTicketContext = ticket;
    relanceEnCours = isRelance;
    document.getElementById('auth-chat-task-id').value = ticket.id;
    document.getElementById('auth-chat-tech-name').value = ticket.tech;
    document.getElementById('auth-chat-pin').value = '';

    const selectUser = document.getElementById('auth-chat-user');
    selectUser.innerHTML = `<option value="" disabled selected>${I18N_SUIVI.select_your_name}</option>`;

    personnelData.forEach(p => {
        const option = document.createElement('option');
        option.value = p.id;
        const prenom = p.prenom ? p.prenom + " " : "";
        option.textContent = prenom + p.nom;
        selectUser.appendChild(option);
    });

    document.getElementById('modalAuthChat').style.display = 'flex';
}

async function validerAuthChat() {
    const idUser = document.getElementById('auth-chat-user').value;
    const pin = document.getElementById('auth-chat-pin').value.trim();
    const taskId = document.getElementById('auth-chat-task-id').value;
    const techName = document.getElementById('auth-chat-tech-name').value;

    if (!idUser || !pin) {
        await aspirineAlert(I18N_SUIVI.err_title, I18N_SUIVI.alert_missing_auth);
        return;
    }

    const formData = new FormData();
    formData.append('action', 'verify_pin');
    formData.append('id_demandeur', idUser);
    formData.append('pin', pin);

    try {
        const res = await fetch('suivi.php', { method: 'POST', body: formData });
        const data = await res.json();

        if (data.success) {
            const u = data.user;
            const prenom = u.prenom ? u.prenom + " " : "";
            currentUserChat = (prenom + u.nom).trim();
            setMessagerieUser(currentUserChat);

            document.getElementById('modalAuthChat').style.display = 'none';

            const t = chatTicketContext || {};
            const localisationTxt = [t.localisation, t.machine].filter(Boolean).join(' — ');
            const numBi = (t.bi && t.bi.startsWith('#')) ? t.bi.substring(1) : '';
            await ouvrirChatTicket(taskId, techName, {
                numBi: numBi,
                localisation: localisationTxt,
                panne: t.desc || '',
                dateHeure: t.dateHeure || ''
            });

            if (relanceEnCours) {
                relanceEnCours = false;
                const msgRelance = t.isPending
                    ? I18N_CHAT.relance_message.replace('{date}', t.date || '')
                    : I18N_CHAT.relance_message_bi.replace('{bi}', numBi ? ('#' + numBi) : '').replace('{date}', t.date || '');
                document.getElementById('chat-ticket-input').value = msgRelance;
                await envoyerMessageTicket();
            }
        } else {
            await aspirineAlert(I18N_SUIVI.err_title, I18N_SUIVI.alert_bad_pin);
            document.getElementById('auth-chat-pin').value = '';
        }
    } catch (e) {
        await aspirineAlert(I18N_SUIVI.err_network_title, I18N_SUIVI.alert_network);
    }
}

function aspirineAlert(titre, message) {
    return new Promise((resolve) => {
        const modal = document.getElementById('customAlert');
        if (!modal) { alert(message); return resolve(); } // Sécurité
        document.getElementById('alertTitle').innerText = titre;
        document.getElementById('alertMessage').innerText = message;
        modal.style.display = 'block';
        document.getElementById('alertOk').onclick = () => { modal.style.display = 'none'; resolve(); };
    });
}

/* ================= RADAR NOTIFICATIONS ================= */
async function verifierNotifications() {
    try {
        const res = await fetch('api.php?action=check_notifications&t=' + Date.now());
        const data = await res.json();

        let localNonLus = 0;

        document.querySelectorAll('[id^="badge-ticket-"]').forEach(el => el.style.display = 'none');

        if (data.tickets && data.tickets.length > 0) {
            data.tickets.forEach(ticket => {
                const pastilleTicket = document.getElementById('badge-ticket-' + ticket.task_id);
                if (pastilleTicket) {
                    pastilleTicket.style.display = 'inline-block';
                    let texteMessage = parseInt(ticket.nb) > 1 ? I18N_SUIVI.msg_unread_many : I18N_SUIVI.msg_unread_one;
                    pastilleTicket.innerText = ticket.nb + ' ' + texteMessage;
                    localNonLus += parseInt(ticket.nb);
                }
            });
        }

        const pastilleGlobal = document.getElementById('pastille-notification');
        if (pastilleGlobal) {
            if (localNonLus > 0) {
                pastilleGlobal.style.display = 'inline-block';
                pastilleGlobal.innerText = I18N_SUIVI.msg_unread_global.replace('{n}', localNonLus);
            } else {
                pastilleGlobal.style.display = 'none';
            }
        }

        if (document.getElementById('filterStatut') && document.getElementById('filterStatut').value === "MESSAGE_NON_LU") {
            filterTicketsAdvanced();
        }
    } catch(e) {}
}

setInterval(verifierNotifications, 5000);
verifierNotifications();
</script>

<div id="customAlert" style="display:none; position:fixed; z-index:99999; left:0; top:0; width:100%; height:100%; background:rgba(0,0,0,0.5); backdrop-filter: blur(3px);">
    <div style="background:white; width:350px; margin:15% auto; padding:20px; border-radius:12px; text-align:center; box-shadow: 0 10px 25px rgba(0,0,0,0.2); border-top: 5px solid #27ae60;">
        <i class="fa-solid fa-circle-check" style="font-size:3rem; color:#27ae60; margin-bottom:15px;"></i>
        <h3 id="alertTitle" style="margin:10px 0; color:var(--primary);"></h3>
        <p id="alertMessage" style="color:#666; font-size:0.9rem; margin-bottom:20px;"></p>
        <div style="display:flex; justify-content:center;">
            <button id="alertOk" style="padding:10px 30px; border:none; border-radius:6px; background:#27ae60; color:white; cursor:pointer; font-weight:bold; font-family: inherit;"><?php echo htmlspecialchars(t('maint.ok')); ?></button>
        </div>
    </div>
</div>

</body>
</html>
