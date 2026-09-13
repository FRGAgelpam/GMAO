<?php
require_once __DIR__ . '/session_init.php';
require 'db.php';
require 'csrf.php';

// --- SÉCURITÉ : réservé aux admins ---
if (!isset($_SESSION['user']) || strtolower($_SESSION['role']) !== 'admin') {
    header("Location: maintenance.php");
    exit();
}

require 'vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;

$TMP_DIR = __DIR__ . '/uploads/tmp_import';

// Ménage : on retire les fichiers temporaires de plus d'une heure (aperçus jamais confirmés)
foreach (glob($TMP_DIR . '/*.xlsx') ?: [] as $f) {
    if (is_file($f) && (time() - filemtime($f)) > 3600) { @unlink($f); }
}

$COULEUR_POSTE = [
    'FFFFFF00' => 'matin',
    'FF92D050' => 'apres_midi',
    'FF0070C0' => 'nuit',
];
$COULEUR_ASTREINTE = [
    'FFFF0000' => 'classique',
    'FF00B0F0' => 'froid',
];
$MOIS_NUM = [
    'avril' => 4, 'mai' => 5, 'juin' => 6, 'juillet' => 7, 'aout' => 8, 'septembre' => 9,
    'octobre' => 10, 'novembre' => 11, 'decembre' => 12, 'janvier' => 1, 'fevrier' => 2, 'mars' => 3,
];
$MOIS_ORDRE = array_keys($MOIS_NUM); // avril..mars, dans l'ordre de la saison

function normaliserTexte($txt) {
    $txt = trim((string)$txt);
    $txt = str_replace(['é', 'è', 'ê', 'ë'], 'e', mb_strtolower($txt, 'UTF-8'));
    return $txt;
}

function parserPlanningExcel(string $cheminFichier, int $anneeDebutSaison, PDO $db): array {
    global $COULEUR_POSTE, $COULEUR_ASTREINTE, $MOIS_NUM, $MOIS_ORDRE;

    $utilisateursConnus = $db->query("SELECT username FROM utilisateurs WHERE role IN ('admin','technicien')")
                              ->fetchAll(PDO::FETCH_COLUMN);

    $spreadsheet = IOFactory::load($cheminFichier);
    $sheet = $spreadsheet->getActiveSheet();
    $ligneMax = $sheet->getHighestRow();

    $lignes = [];
    $avertissements = [];
    $blocsDetectes = 0;
    $ligne = 1;

    while ($ligne <= $ligneMax && $blocsDetectes < 12) {
        $texteA = normaliserTexte($sheet->getCell('A' . $ligne)->getValue());
        if (!isset($MOIS_NUM[$texteA])) { $ligne++; continue; }

        // --- Bloc mensuel détecté ---
        $blocsDetectes++;
        $moisNumero = $MOIS_NUM[$texteA];
        $rangDansSaison = array_search($texteA, $MOIS_ORDRE, true);
        $anneeDuBloc = $anneeDebutSaison + ($rangDansSaison >= 9 ? 1 : 0);

        $ligneJours = $ligne + 2; // ligne des numéros de jour (1, 2, 3...)
        $colonnesJours = [];
        for ($col = 3; $col <= 40; $col++) { // colonnes C(3) à AN(40)
            $val = $sheet->getCell([$col, $ligneJours])->getValue();
            if (is_numeric($val) && (int)$val >= 1 && (int)$val <= 31) {
                $colonnesJours[$col] = sprintf('%04d-%02d-%02d', $anneeDuBloc, $moisNumero, (int)$val);
            }
        }

        // --- Lignes personnes : on avance tant qu'on trouve un nom en colonne B ---
        $lignePersonne = $ligne + 4;
        $limiteSecurite = $ligne + 14;
        while ($lignePersonne <= $limiteSecurite) {
            $nomExcel = trim((string)$sheet->getCell('B' . $lignePersonne)->getValue());
            $texteAici = normaliserTexte($sheet->getCell('A' . $lignePersonne)->getValue());
            if (isset($MOIS_NUM[$texteAici])) { break; } // bloc suivant déjà atteint
            if ($nomExcel === '') { $lignePersonne++; if ($lignePersonne > $ligne + 5 && empty($colonnesJours)) break; continue; }

            $utilisateur = null;
            foreach ($utilisateursConnus as $u) {
                if ($u === $nomExcel || mb_strtolower($u, 'UTF-8') === mb_strtolower($nomExcel, 'UTF-8')) { $utilisateur = $u; break; }
            }
            if ($utilisateur === null) {
                $avertissements[] = "Ligne $lignePersonne : « $nomExcel » ne correspond à aucun utilisateur GMAO, ignorée.";
                $lignePersonne++;
                continue;
            }

            foreach ($colonnesJours as $col => $date) {
                $cell = $sheet->getCell([$col, $lignePersonne]);
                $valeur = $cell->getValue();
                if ($valeur === null || $valeur === '' || !is_numeric($valeur) || (float)$valeur <= 0) { continue; }
                $heures = (float)$valeur;

                $style = $cell->getStyle();
                $fillArgb = $style->getFill()->getStartColor()->getARGB();
                $poste = $COULEUR_POSTE[$fillArgb] ?? null;
                if ($poste === null) {
                    $jourSemaine = (int)date('N', strtotime($date)); // 1=lundi .. 7=dimanche
                    if ($jourSemaine <= 5) { $poste = 'journee'; } // semaine non coloriée -> Journée par défaut
                    // week-end non colorié -> $poste reste null (pas de badge), décision confirmée
                }

                $astreinte = null;
                $bordures = $style->getBorders();
                foreach ([$bordures->getLeft(), $bordures->getRight(), $bordures->getTop(), $bordures->getBottom()] as $cote) {
                    $argbBordure = $cote->getColor()->getARGB();
                    if (isset($COULEUR_ASTREINTE[$argbBordure])) { $astreinte = $COULEUR_ASTREINTE[$argbBordure]; break; }
                }

                $lignes[] = [$utilisateur, $date, $poste, $astreinte, $heures];
            }

            $lignePersonne++;
        }

        $ligne = max($lignePersonne, $ligne + 11);
    }

    return [
        'lignes' => $lignes,
        'avertissements' => $avertissements,
        'stats' => ['blocs_mois' => $blocsDetectes, 'lignes_prevues' => count($lignes)],
    ];
}

$message = "";
$resultat = null;
$tokenApercu = null;
$anneeSoumise = null;

$anneeDefaut = ((int)date('n') >= 4) ? (int)date('Y') : (int)date('Y') - 1;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verifie($_POST['csrf_token'] ?? '')) {
        $message = "<div class='alert danger'>Session expirée, merci de recharger la page et réessayer.</div>";
    } else {
        $mode = $_POST['mode'] ?? '';
        $anneeSoumise = (int)($_POST['annee_saison'] ?? $anneeDefaut);

        try {
            $cheminAUtiliser = null;

            if (!empty($_FILES['fichier_excel']['name'] ?? '')) {
                if ($_FILES['fichier_excel']['error'] !== UPLOAD_ERR_OK) {
                    $message = "<div class='alert danger'>Échec de l'envoi du fichier.</div>";
                } elseif (strtolower(pathinfo($_FILES['fichier_excel']['name'], PATHINFO_EXTENSION)) !== 'xlsx') {
                    $message = "<div class='alert danger'>Seuls les fichiers .xlsx sont acceptés.</div>";
                } else {
                    $tokenApercu = bin2hex(random_bytes(16));
                    $cheminAUtiliser = $TMP_DIR . '/' . $tokenApercu . '.xlsx';
                    if (!move_uploaded_file($_FILES['fichier_excel']['tmp_name'], $cheminAUtiliser)) {
                        $err = error_get_last();
                        error_log("import_planning.php: move_uploaded_file a échoué (tmp=" . $_FILES['fichier_excel']['tmp_name'] . " -> $cheminAUtiliser) : " . ($err['message'] ?? 'raison inconnue'));
                        $message = "<div class='alert danger'>Impossible d'enregistrer le fichier envoyé.</div>";
                        $cheminAUtiliser = null;
                    }
                }
            } elseif (!empty($_POST['token'] ?? '')) {
                $tokenApercu = preg_replace('/[^a-f0-9]/', '', $_POST['token']);
                $cheminCandidat = $TMP_DIR . '/' . $tokenApercu . '.xlsx';
                if (is_file($cheminCandidat)) {
                    $cheminAUtiliser = $cheminCandidat;
                } else {
                    $message = "<div class='alert danger'>Le fichier temporaire a expiré, merci de le renvoyer.</div>";
                }
            } else {
                $message = "<div class='alert danger'>Merci de sélectionner un fichier .xlsx.</div>";
            }

            if ($cheminAUtiliser !== null) {
                $resultat = parserPlanningExcel($cheminAUtiliser, $anneeSoumise, $db);

                if ($mode === 'import') {
                    if (empty($resultat['lignes'])) {
                        $message = "<div class='alert danger'>Aucune ligne à importer, rien n'a été enregistré.</div>";
                    } else {
                        $dates = array_column($resultat['lignes'], 1);
                        $minDate = min($dates);
                        $maxDate = max($dates);

                        $db->exec("CREATE TABLE IF NOT EXISTS planning_shifts (
                            id INT AUTO_INCREMENT PRIMARY KEY,
                            utilisateur VARCHAR(100) NOT NULL,
                            jour DATE NOT NULL,
                            poste VARCHAR(20) NULL,
                            astreinte VARCHAR(20) NULL,
                            heures DECIMAL(5,2) NULL,
                            note VARCHAR(255) NULL,
                            UNIQUE KEY uniq_user_jour (utilisateur, jour)
                        )");
                        $db->exec("ALTER TABLE planning_shifts ADD COLUMN IF NOT EXISTS note VARCHAR(255) NULL");
                        $db->beginTransaction();
                        $del = $db->prepare("DELETE FROM planning_shifts WHERE jour BETWEEN ? AND ?");
                        $del->execute([$minDate, $maxDate]);
                        $ins = $db->prepare("INSERT INTO planning_shifts (utilisateur, jour, poste, astreinte, heures) VALUES (?, ?, ?, ?, ?)");
                        foreach ($resultat['lignes'] as $l) { $ins->execute($l); }
                        $db->commit();

                        ajouterLog($db, $_SESSION['user'], "Planning", "A importé le planning ($anneeSoumise-" . ($anneeSoumise + 1) . ") : " . count($resultat['lignes']) . " lignes, " . count($resultat['avertissements']) . " avertissements.");
                        $message = "<div class='alert success'>Import terminé : " . count($resultat['lignes']) . " lignes enregistrées (" . $minDate . " → " . $maxDate . ").</div>";

                        @unlink($cheminAUtiliser);
                        $tokenApercu = null;
                        $resultat = null;
                    }
                }
            }
        } catch (Exception $e) {
            if ($db->inTransaction()) { $db->rollBack(); }
            error_log("import_planning.php: " . $e->getMessage());
            $message = "<div class='alert danger'>Erreur lors du traitement du fichier. Vérifie qu'il s'agit bien d'un export du planning attendu.</div>";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Importer le planning - GMAO</title>
    <link rel="icon" type="image/png" href="img/logo.png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Caveat:wght@600&family=Segoe+UI:wght@300;400;600;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #2c3e50; --accent: #3498db; --success: #2ecc71;
            --danger: #e74c3c; --line: #e3e8ec; --line-strong: #ccd5db;
            --surface-2: #f4f6f8; --ink-500: #64748b;
        }
        body { margin: 0; font-family: 'Segoe UI', sans-serif; background: linear-gradient(rgba(0,0,0,0.2), rgba(0,0,0,0.2)), url('img/fond.jpg') no-repeat center 0px fixed; background-size: cover; min-height: 100vh; padding-top: 98px; }

        header { position: fixed; top: 0; width: 100%; z-index: 1000; overflow: hidden; box-shadow: 0 4px 20px rgba(0,0,0,0.25); }
        header::before { content: ""; position: absolute; top: -12px; left: -12px; right: -12px; bottom: -12px; background: linear-gradient(rgba(0, 0, 0, 0.2), rgba(0, 0, 0, 0.2)), url('img/fond.jpg') no-repeat center 0px fixed; background-size: cover; filter: blur(4px); z-index: -1; }
        .crumb-bar { display: flex; align-items: center; gap: 8px; font-size: 0.94rem; font-weight: 600; color: rgba(255,255,255,0.9); text-shadow: 0 1px 3px rgba(0,0,0,0.4); max-width: 99%; margin: 0 auto; padding: 0 10px 10px; }
        .crumb-bar .crumb-home { color: inherit; text-decoration: none; display: inline-flex; align-items: center; gap: 5px; }
        .crumb-bar .crumb-home:hover { text-decoration: underline; }
        .crumb-sep { color: rgba(255,255,255,0.45); font-weight: 400; }
        .crumb-current { color: rgba(255,255,255,0.75); font-weight: 400; }
        .header-top { display: flex; justify-content: space-between; align-items: center; padding: 12px 20px; }
        .header-title { font-family: 'Caveat', cursive; font-size: 1.5rem; color: white; background: rgba(255,255,255,0.12); backdrop-filter: blur(14px) brightness(1.15); -webkit-backdrop-filter: blur(14px) brightness(1.15); border: 1px solid rgba(255,255,255,0.4); border-radius: 20px; padding: 6px 18px; text-shadow: 0 2px 6px rgba(0,0,0,0.4); box-shadow: 0 6px 16px rgba(0,0,0,0.2); }
        .nav-tabs { display: flex; background: #fff; padding: 0 10px; gap: 2px; }
        .tab-item { padding: 10px 18px; text-decoration: none; color: #7f8c8d; font-weight: 600; font-size: 0.8rem; border-bottom: 3px solid transparent; transition: 0.3s; display: flex; align-items: center; gap: 8px; }
        .tab-item.active { color: var(--accent); border-bottom: 3px solid var(--accent); background: rgba(52,152,219,0.05); }
        .user-badge { background: rgba(255,255,255,0.12); backdrop-filter: blur(14px) brightness(1.15); -webkit-backdrop-filter: blur(14px) brightness(1.15); padding: 6px 14px; border-radius: 20px; font-size: 0.75rem; font-weight: 600; display: flex; align-items: center; gap: 8px; color: white; border: 1px solid rgba(255,255,255,0.4); text-shadow: 0 1px 3px rgba(0,0,0,0.4); box-shadow: 0 6px 16px rgba(0,0,0,0.2); }
        .status-pulse { width: 8px; height: 8px; border-radius: 50%; position: relative; background: var(--success); }
        .status-pulse::after { content: ""; position: absolute; width: 100%; height: 100%; border-radius: 50%; background: inherit; animation: pulse-dot 2s infinite; opacity: 0.6; }
        @keyframes pulse-dot { 0% { transform: scale(1); opacity: 0.6; } 100% { transform: scale(2.5); opacity: 0; } }

        .sidebar { height: 100%; width: 0; position: fixed; z-index: 3000; top: 0; left: 0; background-color: #1a252f; overflow-x: hidden; overflow-y: auto; transition: 0.4s; padding-top: 60px; padding-bottom: 20px; }
        .sidebar a { padding: 12px 25px; text-decoration: none; font-size: 1.05rem; color: #ecf0f1; display: block; transition: 0.3s; border-left: 4px solid transparent; }
        .sidebar a:hover { background: #2c3e50; border-left: 4px solid var(--accent); }
        .sidebar a.active-side { background: #2c3e50; border-left: 4px solid var(--accent); color: var(--accent); font-weight: bold; }
        .sidebar .closebtn { position: absolute; top: 10px; right: 25px; font-size: 36px; color: white; border: none; background: none; cursor: pointer; }
        .openbtn { font-size: 22px; cursor: pointer; background: none; border: none; color: var(--primary); padding: 5px 10px; }
        .btn-accueil { font-size: 18px; color: white; text-decoration: none; display: flex; align-items: center; justify-content: center; width: 38px; height: 38px; border-radius: 50%; background: rgba(255,255,255,0.12); backdrop-filter: blur(14px) brightness(1.15); -webkit-backdrop-filter: blur(14px) brightness(1.15); border: 1px solid rgba(255,255,255,0.4); box-shadow: 0 6px 16px rgba(0,0,0,0.2); transition: transform 0.2s, background 0.2s; }
        .btn-accueil:hover { background: rgba(255,255,255,0.28); transform: translateY(-2px); }
        .btn-accueil:hover { background: rgba(0,0,0,0.06); }
        .btn-accueil-green:hover { background: rgba(46, 204, 113, 0.3); border-color: rgba(46, 204, 113, 0.6); }

        .container { max-width: 900px; margin: 0 auto; padding: 10px 20px 40px; }
        .page-title { font-family: 'Caveat', cursive; font-size: 2rem; color: #fff; text-shadow: 1px 1px 3px rgba(0,0,0,0.5); margin: 10px 0 15px; display: flex; align-items: center; gap: 10px; }
        .alert { padding: 10px 14px; border-radius: 8px; margin-bottom: 14px; font-weight: 600; font-size: 0.85rem; }
        .alert.success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .alert.danger { background: #f8d7da; color: #842029; border: 1px solid #f1aeb5; }
        .panel { background: rgba(255,255,255,0.97); border-radius: 14px; box-shadow: 0 5px 20px rgba(0,0,0,0.12); padding: 26px; margin-bottom: 20px; }
        .field-block { display: flex; flex-direction: column; gap: 6px; margin-bottom: 20px; max-width: 320px; }
        .field-block label { font-weight: 700; font-size: 0.78rem; color: var(--ink-500); text-transform: uppercase; letter-spacing: .03em; }
        .field-block input { padding: 11px 12px; border-radius: 8px; border: 1.5px solid var(--line-strong); font-family: inherit; font-size: 0.9rem; }
        .field-hint { font-size: 0.75rem; color: var(--ink-500); margin: 0 0 18px; }
        .pm-btn { display: inline-flex; align-items: center; gap: 7px; border-radius: 8px; border: 1px solid var(--line-strong); background: #fff; color: var(--primary); font-size: 0.85rem; font-weight: 700; padding: 11px 18px; cursor: pointer; transition: 0.15s; font-family: inherit; }
        .pm-btn:hover { border-color: var(--accent); color: var(--accent); }
        .pm-btn.primary { background: var(--success); border-color: var(--success); color: #fff; }
        .pm-btn.primary:hover { background: #27ae60; border-color: #27ae60; color: #fff; }
        .stats-row { display: flex; gap: 14px; margin-bottom: 18px; flex-wrap: wrap; }
        .stat-chip { background: var(--surface-2); border-radius: 10px; padding: 10px 16px; font-size: 0.82rem; color: var(--primary); font-weight: 600; }
        .stat-chip b { font-family: 'Caveat', cursive; font-size: 1.3rem; color: var(--accent); margin-right: 4px; }
        table.apercu { width: 100%; border-collapse: collapse; font-size: 0.8rem; margin-bottom: 18px; }
        table.apercu th { text-align: left; padding: 6px 8px; border-bottom: 2px solid var(--line-strong); color: var(--ink-500); text-transform: uppercase; font-size: 0.68rem; }
        table.apercu td { padding: 6px 8px; border-bottom: 1px solid var(--line); }
        .warn-list { font-size: 0.8rem; color: #8a5a00; background: #fff8e6; border: 1px solid #ffe3a3; border-radius: 8px; padding: 10px 14px; margin-bottom: 18px; max-height: 200px; overflow-y: auto; }
        .warn-list p { margin: 3px 0; }
        a.back-link { color: var(--ink-500); text-decoration: none; font-size: 0.82rem; display: inline-flex; align-items: center; gap: 6px; margin-bottom: 14px; }
        a.back-link:hover { color: var(--accent); }
        .modal-bg { display: none; position: fixed; z-index: 5000; left: 0; top: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); backdrop-filter: blur(3px); align-items: center; justify-content: center; }
        .modal-bg.show { display: flex; }
        .modal-box { background: white; width: 420px; max-width: 90vw; padding: 24px; border-radius: 12px; box-shadow: 0 10px 25px rgba(0,0,0,0.2); }
        .modal-box h3 { margin: 0 0 12px; color: var(--primary); }
        .modal-box p { font-size: 0.88rem; color: var(--primary); margin: 0 0 18px; }
        .modal-actions { display: flex; justify-content: flex-end; gap: 10px; }
        .modal-actions button { padding: 10px 20px; border: none; border-radius: 7px; cursor: pointer; font-weight: 700; font-family: inherit; }
        .modal-btn-cancel { background: #eee; color: #333; }
        .modal-btn-ok { background: var(--accent); color: #fff; }
    </style>
<?php include 'pwa_head.php'; ?>
</head>
<body onclick="checkCloseSidebar(event)">

<?php $breadcrumb_label = 'Importer le planning'; include 'navbar.php'; ?>

<div class="container">
    <a class="back-link" href="parametres.php?tab=planning"><i class="fa-solid fa-arrow-left"></i> Retour aux Paramètres</a>
    <div class="page-title"><i class="fa-solid fa-file-import"></i> Importer le planning</div>
    <?php echo $message; ?>

    <div class="panel">
        <p class="field-hint">Choisis le fichier Excel du planning d'équipe. "Aperçu" analyse le fichier sans rien enregistrer ; "Importer" écrit les données en base (remplace l'existant sur la période couverte par le fichier).</p>
        <form method="POST" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>">
            <div class="field-block">
                <label>Fichier planning (.xlsx)</label>
                <input type="file" name="fichier_excel" accept=".xlsx">
            </div>
            <div class="field-block">
                <label>Année de début de saison</label>
                <input type="number" name="annee_saison" value="<?php echo htmlspecialchars($anneeSoumise ?? $anneeDefaut); ?>" min="2020" max="2100">
            </div>
            <div style="display:flex; gap:10px;">
                <button type="submit" name="mode" value="preview" class="pm-btn"><i class="fa-solid fa-eye"></i> Aperçu (sans écriture en base)</button>
                <button type="button" class="pm-btn primary" id="btnImportDirect"><i class="fa-solid fa-upload"></i> Importer directement</button>
            </div>
            <p id="erreurFichierManquant" style="display:none; color:var(--danger); font-size:0.8rem; font-weight:600; margin-top:10px;">Sélectionne d'abord un fichier .xlsx.</p>
        </form>
    </div>

    <?php if ($resultat !== null): ?>
    <div class="panel">
        <div class="stats-row">
            <div class="stat-chip"><b><?php echo $resultat['stats']['blocs_mois']; ?></b> blocs mois détectés (attendu : 12)</div>
            <div class="stat-chip"><b><?php echo $resultat['stats']['lignes_prevues']; ?></b> lignes prévues à l'import</div>
            <div class="stat-chip"><b><?php echo count($resultat['avertissements']); ?></b> avertissement(s)</div>
        </div>

        <?php if (!empty($resultat['avertissements'])): ?>
        <div class="warn-list">
            <?php foreach ($resultat['avertissements'] as $a): ?>
            <p><i class="fa-solid fa-triangle-exclamation"></i> <?php echo htmlspecialchars($a); ?></p>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <p class="field-hint" style="margin-top:0;">Échantillon (20 premières lignes détectées) :</p>
        <table class="apercu">
            <thead><tr><th>Utilisateur</th><th>Jour</th><th>Poste</th><th>Astreinte</th><th>Heures</th></tr></thead>
            <tbody>
                <?php foreach (array_slice($resultat['lignes'], 0, 20) as $l): ?>
                <tr>
                    <td><?php echo htmlspecialchars($l[0]); ?></td>
                    <td><?php echo htmlspecialchars($l[1]); ?></td>
                    <td><?php echo htmlspecialchars($l[2] ?? '—'); ?></td>
                    <td><?php echo htmlspecialchars($l[3] ?? '—'); ?></td>
                    <td><?php echo htmlspecialchars($l[4]); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <?php if ($tokenApercu && !empty($resultat['lignes'])): ?>
        <form method="POST" id="formConfirmerImport">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>">
            <input type="hidden" name="token" value="<?php echo htmlspecialchars($tokenApercu); ?>">
            <input type="hidden" name="annee_saison" value="<?php echo htmlspecialchars($anneeSoumise); ?>">
            <input type="hidden" name="mode" value="import">
            <button type="button" class="pm-btn primary" id="btnConfirmerApercu" data-nb="<?php echo (int)count($resultat['lignes']); ?>"><i class="fa-solid fa-check"></i> Confirmer l'import de ces données</button>
        </form>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>

<div class="modal-bg" id="modalConfirmImport">
    <div class="modal-box">
        <h3><i class="fa-solid fa-triangle-exclamation"></i> Confirmer l'import</h3>
        <p id="modalConfirmImportMsg"></p>
        <div class="modal-actions">
            <button type="button" class="modal-btn-cancel" id="btnAnnulerImport">Annuler</button>
            <button type="button" class="modal-btn-ok" id="btnValiderImport">Importer</button>
        </div>
    </div>
</div>

<script>
function openNav(e) { if (e) e.stopPropagation(); document.getElementById("mySidebar").style.width = "280px"; }
function closeNav() { document.getElementById("mySidebar").style.width = "0"; }
function checkCloseSidebar(event) { if (document.getElementById("mySidebar").style.width === "280px" && !document.getElementById("mySidebar").contains(event.target)) closeNav(); }

let formASoumettre = null;

function ouvrirConfirmImport(message, form) {
    document.getElementById('modalConfirmImportMsg').textContent = message;
    formASoumettre = form;
    document.getElementById('modalConfirmImport').classList.add('show');
}
document.getElementById('btnAnnulerImport').addEventListener('click', function () {
    document.getElementById('modalConfirmImport').classList.remove('show');
    formASoumettre = null;
});
document.getElementById('btnValiderImport').addEventListener('click', function () {
    if (formASoumettre) { formASoumettre.submit(); }
});

const btnDirect = document.getElementById('btnImportDirect');
if (btnDirect) {
    btnDirect.addEventListener('click', function () {
        const fichier = document.querySelector('input[name="fichier_excel"]');
        const erreur = document.getElementById('erreurFichierManquant');
        if (!fichier || !fichier.files.length) { erreur.style.display = 'block'; return; }
        erreur.style.display = 'none';
        const form = btnDirect.closest('form');
        const hiddenMode = document.createElement('input');
        hiddenMode.type = 'hidden'; hiddenMode.name = 'mode'; hiddenMode.value = 'import';
        form.appendChild(hiddenMode);
        ouvrirConfirmImport("Importer directement sans aperçu préalable ? Les données seront écrites en base immédiatement.", form);
    });
}

const btnConfirmerApercu = document.getElementById('btnConfirmerApercu');
if (btnConfirmerApercu) {
    btnConfirmerApercu.addEventListener('click', function () {
        const nb = btnConfirmerApercu.dataset.nb;
        ouvrirConfirmImport("Confirmer l'import de " + nb + " lignes ? Ça remplacera les données déjà importées sur cette période.", document.getElementById('formConfirmerImport'));
    });
}

window.onclick = function (event) {
    if (event.target.classList && event.target.classList.contains('modal-bg')) {
        event.target.classList.remove('show');
    }
};
</script>
</body>
</html>
