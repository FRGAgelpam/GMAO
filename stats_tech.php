<?php
require_once __DIR__ . '/session_init.php';

// --- SÉCURITÉ : Uniquement les Admins pour les Stats ---
if (!isset($_SESSION['user']) || $_SESSION['role'] !== 'admin') {
    header("Location: maintenance.php");
    exit();
}

$is_admin = true;
require_once 'db.php';

// --- HABILLAGE DES STATUTS (configurable depuis Paramètres > Statuts & priorités) ---
// Ne change QUE le libellé/la couleur affichés du badge : la classification (substring
// "termin"/"cours" côté JS) et les valeurs stockées en base ne sont pas touchées.
$LIBELLES_WORKFLOW = [
    'afaire'  => ['label' => t('maint.lib_afaire'),  'couleur' => '#f39c12'],
    'encours' => ['label' => t('maint.lib_encours'), 'couleur' => '#3498db'],
    'termine' => ['label' => t('maint.lib_termine'), 'couleur' => '#27ae60'],
];
// Défauts français d'origine (avant l'i18n) : un libellé en base identique à l'un d'eux est traité
// comme non personnalisé (même correctif que suivi.php/index.php/maintenance.php) — sinon un libellé
// jamais retouché par David resterait figé en français quelle que soit la langue choisie.
$SNAPSHOTS_FR_LIBELLES = ['afaire' => 'À faire', 'encours' => 'En cours', 'termine' => 'Terminée'];
try {
    if (isset($db)) {
        foreach ($db->query("SELECT bucket, label, couleur FROM libelles_workflow")->fetchAll(PDO::FETCH_ASSOC) as $l) {
            $labelAGarder = (($l['label'] ?? '') === '' || $l['label'] === ($SNAPSHOTS_FR_LIBELLES[$l['bucket']] ?? null))
                ? ($LIBELLES_WORKFLOW[$l['bucket']]['label'] ?? $l['label'])
                : $l['label'];
            $LIBELLES_WORKFLOW[$l['bucket']] = ['label' => $labelAGarder, 'couleur' => $l['couleur']];
        }
    }
} catch (Exception $e) {}

// --- RÉCUPÉRATION DES DONNÉES STATISTIQUES (Préparation SQL) ---
$stats_techs = [];
$stats_jours = [];
$stats_types = [];

try {
    if(isset($db)) {
        // 1. Heures totales par technicien (pour le camembert)
        $sql1 = "SELECT tech, SUM(hours) as total FROM pointages GROUP BY tech";
        $res1 = $db->query($sql1);
        $stats_techs = $res1->fetchAll(PDO::FETCH_ASSOC);

        // 2. Évolution des heures sur les 7 derniers jours (pour la courbe)
        $sql2 = "SELECT DATE(date) as jour, SUM(hours) as total FROM pointages WHERE date >= DATE(NOW()) - INTERVAL 7 DAY GROUP BY DATE(date) ORDER BY jour ASC";
        $res2 = $db->query($sql2);
        $stats_jours = $res2->fetchAll(PDO::FETCH_ASSOC);

        // 3. Répartition Curatif vs Préventif (Si tu as l'info dans tes tâches)
        // $sql3 = "SELECT type, COUNT(id) as total FROM tasks GROUP BY type";
        // $res3 = $db->query($sql3);
        // $stats_types = $res3->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Exception $e) {}

// --- PHOTOS + RÔLES PAR TECHNICIEN (pour la liste "Charge par technicien") ---
$photos_par_tech = [];
$roles_par_tech = [];
try {
    if (isset($db)) {
        $photos_par_tech = $db->query("SELECT username, photo FROM utilisateurs WHERE photo IS NOT NULL AND photo != ''")->fetchAll(PDO::FETCH_KEY_PAIR);

        // Intitulé de poste : celui défini dans Gestion Utilisateurs (fonction) s'il existe,
        // sinon repli sur Responsable/Technicien selon le rôle d'accès (même logique que maintenance.php).
        $resRoles = $db->query("SELECT username, role, fonction FROM utilisateurs WHERE role IN ('admin', 'technicien')");
        foreach ($resRoles->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if (!empty($r['fonction'])) {
                $roles_par_tech[$r['username']] = $r['fonction'];
            } else {
                $roles_par_tech[$r['username']] = (strtolower($r['role']) === 'admin') ? t('stats.role_responsable') : t('stats.role_default');
            }
        }
    }
} catch (Exception $e) {}

// --- ANNUALISATION : période du 1er avril au 31 mars, total d'heures planning par technicien ---
$annee_debut_annualisation = (int)date('n') >= 4 ? (int)date('Y') : (int)date('Y') - 1;
$periode_annualisation_debut = "$annee_debut_annualisation-04-01";
$periode_annualisation_fin = ($annee_debut_annualisation + 1) . "-03-31";
$stats_annualisation = [];
try {
    if (isset($db)) {
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
        $db->exec("ALTER TABLE planning_shifts ADD COLUMN IF NOT EXISTS demi_conge TINYINT(1) NOT NULL DEFAULT 0");
        $equipe_annualisation = $db->query("SELECT username FROM utilisateurs WHERE role IN ('admin', 'technicien') AND username != 'Florent' ORDER BY username")->fetchAll(PDO::FETCH_COLUMN);
        // Congé/RTT/férié comptent pour 0h : le seuil de 1607h les a déjà déduits en amont. La maladie est
        // neutralisée "comme si" (jurisprudence annualisation) : créditée à 7h même sans heures saisies.
        $stmtAnn = $db->prepare("SELECT utilisateur, SUM(COALESCE(heures,0) + CASE WHEN poste = 'maladie' THEN 7 ELSE 0 END) as total FROM planning_shifts WHERE jour BETWEEN ? AND ? GROUP BY utilisateur");
        $stmtAnn->execute([$periode_annualisation_debut, $periode_annualisation_fin]);
        $totauxParTech = [];
        foreach ($stmtAnn->fetchAll(PDO::FETCH_ASSOC) as $r) { $totauxParTech[$r['utilisateur']] = (float)$r['total']; }
        foreach ($equipe_annualisation as $u) {
            $stats_annualisation[] = ['tech' => $u, 'total' => $totauxParTech[$u] ?? 0];
        }
        usort($stats_annualisation, fn($a, $b) => $b['total'] <=> $a['total']);
    }
} catch (Exception $e) {}

// --- OBJECTIF ANNUEL + REPÈRE "35H/SEMAINE" PAR TECHNICIEN ---
// Miroir de la logique déjà utilisée par la fenêtre "Objectif annualisé" de planning.php
// (referenceDuJour / calculerReferenceHeures35h / getObjectifEffectif, ~L.2600-2850 de ce fichier) :
// si les règles d'annualisation changent là-bas (accord d'entreprise, jurisprudence...), les reporter ici.
function datePaquesPHP($annee) {
    $a = $annee % 19; $b = intdiv($annee, 100); $c = $annee % 100;
    $d = intdiv($b, 4); $e = $b % 4; $f = intdiv($b + 8, 25);
    $g = intdiv($b - $f + 1, 3); $h = (19 * $a + $b - $d - $g + 15) % 30;
    $i = intdiv($c, 4); $k = $c % 4; $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
    $m = intdiv($a + 11 * $h + 22 * $l, 451);
    $mois = intdiv($h + $l - 7 * $m + 114, 31);
    $jour = (($h + $l - 7 * $m + 114) % 31) + 1;
    return new DateTime(sprintf('%04d-%02d-%02d', $annee, $mois, $jour));
}
function referenceDuJourPHP($shift, $dStr, $isWeekend, &$joursFeries) {
    $postesTravailles = ['matin', 'apres_midi', 'nuit', 'journee'];
    $poste = $shift['poste'] ?? null;
    $demiConge = !empty($shift['demi_conge']);
    $jourFerie = !empty($shift['jour_ferie']);
    $heures = isset($shift['heures']) ? (float)$shift['heures'] : 0;
    $ligneVide = !$shift || (!$poste && !$demiConge && !$jourFerie);
    $estTravaille = $poste && in_array($poste, $postesTravailles);
    $posteProgrammeNonSaisi = $poste && in_array($poste, $postesTravailles) && !($heures > 0);

    if ($isWeekend) {
        if ($ligneVide) return 0;
        if (in_array($poste, ['cp', 'repos'])) return 0;
        if ($jourFerie && !$estTravaille) return 0;
        if ($demiConge) return 3.5;
        if ($posteProgrammeNonSaisi) return 0;
        return 7;
    }
    if ($shift && in_array($poste, ['cp', 'repos'])) return 0;
    if ($shift && $jourFerie && !$estTravaille) return 0;
    if ($shift && $demiConge) return 3.5;
    if ($ligneVide && isset($joursFeries[$dStr])) return 0;
    if ($posteProgrammeNonSaisi) return 0;
    return 7;
}

$annualisation_detail = [];
try {
    if (isset($db)) {
        $db->exec("ALTER TABLE utilisateurs ADD COLUMN IF NOT EXISTS objectif_heures_annuel DECIMAL(6,2) NULL DEFAULT 1607");
        $db->exec("ALTER TABLE planning_shifts ADD COLUMN IF NOT EXISTS jour_ferie TINYINT(1) NOT NULL DEFAULT 0");

        $objectifs_annuels = [];
        foreach ($db->query("SELECT username, objectif_heures_annuel FROM utilisateurs")->fetchAll(PDO::FETCH_ASSOC) as $o) {
            $objectifs_annuels[$o['username']] = $o['objectif_heures_annuel'] !== null ? (float)$o['objectif_heures_annuel'] : 1607.0;
        }

        $db->exec("CREATE TABLE IF NOT EXISTS planning_fractionnement (
            utilisateur VARCHAR(50) NOT NULL, periode_debut DATE NOT NULL, jours TINYINT NOT NULL DEFAULT 0,
            PRIMARY KEY (utilisateur, periode_debut)
        )");
        $fractionnement_annuel = [];
        $stmtFrac = $db->prepare("SELECT utilisateur, jours FROM planning_fractionnement WHERE periode_debut = ?");
        $stmtFrac->execute([$periode_annualisation_debut]);
        foreach ($stmtFrac->fetchAll(PDO::FETCH_ASSOC) as $f) { $fractionnement_annuel[$f['utilisateur']] = (int)$f['jours']; }

        $stmtShifts = $db->prepare("SELECT utilisateur, jour, poste, heures, demi_conge, jour_ferie FROM planning_shifts WHERE jour BETWEEN ? AND ?");
        $stmtShifts->execute([$periode_annualisation_debut, $periode_annualisation_fin]);
        $shiftsParTech = [];
        foreach ($stmtShifts->fetchAll(PDO::FETCH_ASSOC) as $s) {
            $jourKey = substr($s['jour'], 0, 10);
            $shiftsParTech[$s['utilisateur']][$jourKey] = $s;
        }

        $joursFeries = [];
        foreach ([$annee_debut_annualisation, $annee_debut_annualisation + 1] as $an) {
            $paques = datePaquesPHP($an);
            foreach (["$an-01-01", "$an-05-01", "$an-05-08", "$an-07-14", "$an-08-15", "$an-11-01", "$an-11-11", "$an-12-25"] as $f) {
                $joursFeries[$f] = true;
            }
            foreach ([1, 39, 50] as $offset) {
                $d = clone $paques; $d->modify("+$offset days");
                $joursFeries[$d->format('Y-m-d')] = true;
            }
        }

        $today = new DateTime(); $today->setTime(0, 0, 0);
        $debutPeriode = new DateTime($periode_annualisation_debut);
        $finPeriode = new DateTime($periode_annualisation_fin);

        foreach ($equipe_annualisation as $u) {
            $shiftsTech = $shiftsParTech[$u] ?? [];

            $heuresParSemaine = [];
            foreach ($shiftsTech as $jour => $s) {
                if ($s['heures'] !== null && $s['heures'] !== '') {
                    $lundi = new DateTime($jour);
                    $lundi->modify('monday this week');
                    $cle = $lundi->format('Y-m-d');
                    $heuresParSemaine[$cle] = ($heuresParSemaine[$cle] ?? 0) + (float)$s['heures'];
                }
            }
            $semaines48h = 0;
            foreach ($heuresParSemaine as $h) { if ($h >= 48) $semaines48h++; }

            $joursFractionnement = $fractionnement_annuel[$u] ?? 0;
            $ajustements = ($joursFractionnement * 7) + ($semaines48h * 4);
            $objectifEffectif = ($objectifs_annuels[$u] ?? 1607.0) - $ajustements;

            $reference = 0.0;
            if ($today >= $debutPeriode) {
                $limite = $today < $finPeriode ? $today : $finPeriode;
                $cursor = clone $debutPeriode;
                while ($cursor <= $limite) {
                    $dStr = $cursor->format('Y-m-d');
                    $isWeekend = in_array($cursor->format('N'), ['6', '7']);
                    $reference += referenceDuJourPHP($shiftsTech[$dStr] ?? null, $dStr, $isWeekend, $joursFeries);
                    $cursor->modify('+1 day');
                }
            }
            $reference = max(0, $reference - $ajustements);

            $heuresFait = $totauxParTech[$u] ?? 0;
            $annualisation_detail[$u] = [
                'objectif' => $objectifEffectif,
                'fait' => $heuresFait,
                'reference' => $reference,
                'ecart' => $heuresFait - $reference,
            ];
        }
    }
} catch (Exception $e) {}
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(langue_actuelle()); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title><?php echo htmlspecialchars(t('stats.page_title_tag')); ?></title>
    <link rel="icon" type="image/png" href="img/logo.png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Caveat:wght@600&family=Segoe+UI:wght@300;400;600;800&display=swap" rel="stylesheet">
    
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

    <style>
        :root {
            --primary: #2c3e50; --accent: #3498db; --success: #2ecc71; 
            --danger: #e74c3c; --gelpam-green: #2ecc71; --gelpam-orange: #f39c12;
            --ardo-blue: #005696; --bg-light: #f4f7f6;
            --stat-red: #c0392b;
        }

        body { 
    margin: 0; 
    font-family: 'Segoe UI', sans-serif; 
    /* On change 'center center' par 'center 110px' pour décaler l'image vers le bas */
    background: linear-gradient(rgba(0, 0, 0, 0.2), rgba(0, 0, 0, 0.2)), url('img/fond.jpg') no-repeat center 0px fixed; 
    background-size: cover; 
    min-height: 100vh;
    padding-top: 98px;
}

        @keyframes pulse-dot { 0% { transform: scale(1); opacity: 0.6; } 100% { transform: scale(2.5); opacity: 0; } }

        /* --- HEADER & NAV --- */
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
        .tab-item:hover { background: rgba(0,0,0,0.02); }
        .tab-item.active { color: var(--accent); border-bottom: 3px solid var(--accent); background: rgba(52, 152, 219, 0.05); }

        .user-badge { background: rgba(255,255,255,0.12); backdrop-filter: blur(14px) brightness(1.15); -webkit-backdrop-filter: blur(14px) brightness(1.15); padding: 6px 14px; border-radius: 20px; font-size: 0.75rem; font-weight: 600; display: flex; align-items: center; gap: 8px; color: white; border: 1px solid rgba(255,255,255,0.4); text-shadow: 0 1px 3px rgba(0,0,0,0.4); box-shadow: 0 6px 16px rgba(0,0,0,0.2); }
        .status-pulse { width: 8px; height: 8px; border-radius: 50%; position: relative; background: var(--success); }
        .status-pulse::after { content: ""; position: absolute; width: 100%; height: 100%; border-radius: 50%; background: inherit; animation: pulse-dot 2s infinite; opacity: 0.6; }

        /* --- SIDEBAR --- */
        .sidebar { 
            height: 100%; width: 0; position: fixed; z-index: 3000; top: 0; left: 0; 
            background-color: #1a252f; overflow-x: hidden; overflow-y: auto; 
            transition: 0.4s; padding-top: 60px; padding-bottom: 20px; 
        }
        .sidebar a { padding: 12px 25px; text-decoration: none; font-size: 1.05rem; color: #ecf0f1; display: block; transition: 0.3s; border-left: 4px solid transparent; }
        .sidebar a:hover { background: #2c3e50; border-left: 4px solid var(--accent); }
        .sidebar a.active-side { background: #2c3e50; border-left: 4px solid var(--accent); color: var(--accent); font-weight: bold; }
        .sidebar .closebtn { position: absolute; top: 10px; right: 25px; font-size: 36px; color: white; border: none; background: none; cursor: pointer; }
        .openbtn { font-size: 22px; cursor: pointer; background: none; border: none; color: var(--primary); padding: 5px 10px; }
        .btn-accueil { font-size: 18px; color: white; text-decoration: none; display: flex; align-items: center; justify-content: center; width: 38px; height: 38px; border-radius: 50%; background: rgba(255,255,255,0.12); backdrop-filter: blur(14px) brightness(1.15); -webkit-backdrop-filter: blur(14px) brightness(1.15); border: 1px solid rgba(255,255,255,0.4); box-shadow: 0 6px 16px rgba(0,0,0,0.2); transition: transform 0.2s, background 0.2s; }
        .btn-accueil:hover { background: rgba(255,255,255,0.28); transform: translateY(-2px); }
        .btn-accueil:hover { background: rgba(0,0,0,0.06); }
        .btn-accueil-green:hover { background: rgba(46, 204, 113, 0.3); border-color: rgba(46, 204, 113, 0.6); }

        /* --- MODAL (IMPORTÉE DE KPI) --- */
        .modal { display: none; position: fixed; z-index: 4000; left: 0; top: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.7); backdrop-filter: blur(5px); }
        .modal-content { background: white; margin: 5% auto; padding: 25px; border-radius: 15px; width: 85%; max-width: 1100px; max-height: 80vh; overflow-y: auto; position: relative; }

        /* --- DASHBOARD STYLES --- */
        .container { max-width: 1400px; margin: 0 auto; padding: 0 20px 20px 20px; }
        .page-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; background: rgba(255,255,255,0.9); padding: 15px 20px; border-radius: 8px; box-shadow: 0 5px 15px rgba(0,0,0,0.1); border-left: 5px solid var(--accent); }
        .page-title { font-family: 'Caveat', cursive; font-size: 1.8rem; color: var(--primary); margin: 0; }
        
        .dashboard-grid { display: grid; grid-template-columns: repeat(12, minmax(0, 1fr)); gap: 20px; }
        .chart-card { background: rgba(255, 255, 255, 0.95); backdrop-filter: blur(5px); padding: 20px; border-radius: 12px; box-shadow: 0 8px 25px rgba(0,0,0,0.1); border-top: 4px solid var(--accent); }

        .card-main { grid-column: span 12; border-top-color: var(--gelpam-green); }
        .card-half { grid-column: span 6; }
        @media (max-width: 900px) { .card-half { grid-column: span 12; } }

        .card-title { font-size: 1rem; color: var(--primary); font-weight: 800; text-transform: uppercase; margin-bottom: 15px; display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #eee; padding-bottom: 10px; }
        .card-title i { color: #888; }
        
        .chart-container { position: relative; height: 300px; width: 100%; }
        .chart-container-pie { position: relative; height: 250px; width: 100%; display: flex; justify-content: center; cursor: pointer; }

        /* --- Charge par technicien (liste classée, importée de la page KPI) --- */
        .tech-row { display: flex; align-items: center; gap: 13px; padding: 10px 0; border-bottom: 1px solid #f4f6f8; cursor: pointer; border-radius: 8px; transition: background .15s; }
        .tech-row:last-child { border-bottom: none; }
        .tech-row:hover { background: #f8fafc; }
        .tech-rank { flex: none; width: 22px; text-align: center; font-size: 0.68rem; font-weight: 800; color: #94a3b8; }
        .tech-img { width: 38px; height: 38px; border-radius: 50%; border: 2px solid #fff; box-shadow: 0 0 0 2px var(--accent); object-fit: cover; flex: none; }
        .tech-data { flex: 1; min-width: 0; }
        .tech-data .row1 { display: flex; justify-content: space-between; align-items: baseline; gap: 8px; }
        .tech-name { font-weight: 700; font-size: 0.85rem; color: var(--primary); }
        .tech-role { font-size: 0.66rem; color: #94a3b8; font-weight: 600; margin-left: 6px; }
        .tech-hours { font-weight: 800; font-size: 0.85rem; color: var(--primary); white-space: nowrap; }
        .progress-box { background: #eef2f6; height: 6px; border-radius: 4px; overflow: hidden; margin-top: 6px; }
        .progress-bar { height: 100%; border-radius: 4px; transition: width 1s ease-in-out; }
        .bar-tech { background: linear-gradient(90deg, #3498db, #6cb6e8); }

        /* --- Barre "Objectif annualisé" par technicien (même présentation que la fiche jour de planning.php,
           pour qu'un même repère visuel soit reconnu d'une page à l'autre) --- */
        .annual-tech-box { background: #f8fafc; border-radius: 8px; padding: 8px 12px; border: 1px solid #eef1f4; }
        .annual-tech-box + .annual-tech-box { margin-top: 6px; }
        .shift-objectif-header { display: flex; justify-content: space-between; align-items: baseline; font-size: 0.68rem; color: #666; font-weight: 600; margin-bottom: 4px; }
        .shift-objectif-header strong { font-size: 0.95rem; color: var(--primary); font-weight: 800; }
        .shift-objectif-track { position: relative; height: 8px; background: #e2e8f0; border-radius: 5px; margin-top: 3px; }
        .shift-objectif-fill { height: 100%; border-radius: 6px; background: linear-gradient(90deg, #3498db, var(--gelpam-green)); transition: width 0.35s ease; max-width: 100%; }
        .shift-objectif-fill.over { background: linear-gradient(90deg, var(--gelpam-orange), var(--danger)); }
        .objectif-marker-35h { position: absolute; top: -2px; bottom: -2px; width: 2px; background: rgba(44, 62, 80, 0.75); z-index: 3; pointer-events: none; }
        .shift-objectif-legend { display: flex; justify-content: space-between; align-items: baseline; gap: 10px; font-size: 0.65rem; color: #888; margin-top: 5px; font-weight: 600; }
        .shift-objectif-detail-stack { display: flex; flex-direction: row; gap: 14px; }
        .shift-objectif-pace { font-weight: 700; white-space: nowrap; }
        .shift-objectif-pace.avance { color: var(--gelpam-green); }
        .shift-objectif-pace.retard { color: var(--danger); }
    </style>
<?php include 'pwa_head.php'; ?>
</head>
<body onclick="checkCloseSidebar(event)">

<?php $breadcrumb_label = t('stats.breadcrumb'); include 'navbar.php'; ?>

<div class="container">
    <div class="page-header">
        <h1 class="page-title"><i class="fa-solid fa-chart-pie" style="color:var(--accent); margin-right:10px;"></i> <?php echo htmlspecialchars(t('stats.h1')); ?></h1>
    </div>

    <div class="dashboard-grid">

        <div class="chart-card card-main">
            <div class="card-title"><?php echo htmlspecialchars(t('stats.card_evolution')); ?> <i class="fa-solid fa-arrow-trend-up"></i></div>
            <div class="chart-container">
                <canvas id="lineChart"></canvas>
            </div>
        </div>

        <div class="chart-card card-main" style="border-top-color: var(--gelpam-orange);" title="<?php echo htmlspecialchars(t('stats.card_repartition_tech_tooltip')); ?>">
            <div class="card-title"><?php echo htmlspecialchars(t('stats.card_repartition_tech')); ?> <i class="fa-solid fa-users"></i></div>
            <div class="chart-container-pie">
                <canvas id="pieTechChart"></canvas>
            </div>
        </div>

        <div class="chart-card card-main">
            <div class="card-title"><?php echo htmlspecialchars(t('stats.card_charge')); ?> <i class="fa-solid fa-users-gear"></i></div>
            <div id="tech-list"></div>
        </div>

        <div class="chart-card card-main" style="border-top-color: var(--stat-red);">
            <div class="card-title"><?php echo htmlspecialchars(t('stats.card_annualisees')); ?> — <?php echo (new DateTime($periode_annualisation_debut))->format('d/m/Y') . ' ' . htmlspecialchars(t('stats.au_connector')) . ' ' . (new DateTime($periode_annualisation_fin))->format('d/m/Y'); ?> <i class="fa-solid fa-calendar-days"></i></div>
            <?php if (empty($stats_annualisation)): ?>
                <div style="text-align:center; padding: 20px; color:#95a5a6; font-weight:600;"><?php echo htmlspecialchars(t('stats.no_hours_period')); ?></div>
            <?php else: ?>
                <div>
                    <?php foreach ($stats_annualisation as $row):
                        $d = $annualisation_detail[$row['tech']] ?? ['objectif' => 1607.0, 'fait' => $row['total'], 'reference' => 0, 'ecart' => 0];
                        $objectif = $d['objectif'] > 0 ? $d['objectif'] : 1;
                        $pct = ($d['fait'] / $objectif) * 100;
                        $pctFill = min(100, max(0, $pct));
                        $pctRef = min(100, max(0, ($d['reference'] / $objectif) * 100));
                        $over = $pct > 100;
                        $ecart = $d['ecart'];
                        if (abs($ecart) < 1) {
                            $paceClass = ''; $paceTxt = str_replace('{jalon}', t('planning.a_ce_jour'), t('planning.pile_rythme'));
                        } elseif ($ecart > 0) {
                            $paceClass = 'avance'; $paceTxt = str_replace(['{n}', '{jalon}'], [round($ecart), t('planning.a_ce_jour')], t('planning.avance_rythme'));
                        } else {
                            $paceClass = 'retard'; $paceTxt = str_replace(['{n}', '{jalon}'], [round(abs($ecart)), t('planning.a_ce_jour')], t('planning.retard_rythme'));
                        }
                        $detailTxt = $d['fait'] >= $d['objectif']
                            ? str_replace('{n}', round($d['fait'] - $d['objectif']), t('planning.objectif_depasse'))
                            : str_replace(['{n}', '{objectif}'], [round($d['objectif'] - $d['fait']), round($d['objectif'])], t('planning.h_restantes_sur'));
                        $faitNum = number_format($d['fait'], 1, ',', '');
                        if (substr($faitNum, -2) === ',0') { $faitNum = substr($faitNum, 0, -2); }
                        $faitTxt = str_replace(['{fait}', '{objectif}'], [$faitNum, round($d['objectif'])], t('planning.h_faites_sur'));
                        $tooltipRepere = str_replace(['{n}', '{jalon}'], [round($d['reference']), t('planning.a_ce_jour')], t('planning.tooltip_repere_35h'));
                    ?>
                    <div class="annual-tech-box">
                        <div class="shift-objectif-header">
                            <span><?php echo htmlspecialchars($row['tech']); ?></span>
                            <strong><?php echo round($pct); ?>%</strong>
                        </div>
                        <div class="shift-objectif-track">
                            <div class="shift-objectif-fill<?php echo $over ? ' over' : ''; ?>" style="width:<?php echo $pctFill; ?>%"></div>
                            <div class="objectif-marker-35h" style="left:<?php echo $pctRef; ?>%" title="<?php echo htmlspecialchars($tooltipRepere); ?>"></div>
                        </div>
                        <div class="shift-objectif-legend">
                            <div class="shift-objectif-detail-stack">
                                <span><?php echo htmlspecialchars($faitTxt); ?></span>
                                <span><?php echo htmlspecialchars($detailTxt); ?></span>
                            </div>
                            <span class="shift-objectif-pace<?php echo $paceClass ? ' ' . $paceClass : ''; ?>"><?php echo htmlspecialchars($paceTxt); ?></span>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

    </div>
</div>

<div id="historyModal" class="modal">
    <div class="modal-content">
        <span class="close-modal" onclick="document.getElementById('historyModal').style.display='none'" style="position: absolute; right: 20px; top: 15px; font-size: 28px; cursor: pointer;">&times;</span>
        <h2 id="modalTitle" style="font-family: 'Caveat', cursive; color: var(--primary); margin-top:0;"><?php echo htmlspecialchars(t('stats.modal_default_title')); ?></h2>
        <div id="modalBody"></div>
    </div>
</div>

<script>
    const LIBELLES = <?php echo json_encode($LIBELLES_WORKFLOW); ?>;
    const I18N_STATS = <?php echo json_encode([
        'role_responsable' => t('stats.role_responsable'),
        'role_adjoint' => t('stats.role_adjoint'),
        'role_chef_projet' => t('stats.role_chef_projet'),
        'role_default' => t('stats.role_default'),
        'no_charge' => t('stats.no_charge'),
        'voir_interventions' => t('stats.voir_interventions'),
        'heures_prestees' => t('stats.heures_prestees'),
        'no_data' => t('stats.no_data'),
        'no_tech' => t('stats.no_tech'),
        'interventions_liees_a' => t('stats.interventions_liees_a'),
        'aucune_intervention' => t('stats.aucune_intervention'),
        'th_bi' => t('stats.th_bi'),
        'th_date' => t('maint.col_date'),
        'th_equip_desc' => t('stats.th_equip_desc'),
        'th_techs' => t('stats.th_techs'),
        'th_statut' => t('stats.th_statut'),
        'prev_fallback' => t('stats.prev_fallback'),
        'non_assigne' => t('stats.non_assigne'),
        'type_curatif' => t('maint.type_curatif'),
        'type_preventif' => t('maint.type_preventif'),
    ]); ?>;

    // --- GESTION SIDEBAR ---
    function openNav(e) { e.stopPropagation(); document.getElementById("mySidebar").style.width = "280px"; }
    function closeNav() { document.getElementById("mySidebar").style.width = "0"; }
    function checkCloseSidebar(event) { if (document.getElementById("mySidebar").style.width === "280px" && !document.getElementById("mySidebar").contains(event.target)) closeNav(); }

    window.onclick = e => { 
        if (e.target == document.getElementById('historyModal')) {
            document.getElementById('historyModal').style.display = "none";
        }
    };

    // --- CHARGEMENT DES DONNÉES EN ARRIÈRE-PLAN ---
    let allTasks = [];
    let allPointages = []; 

    async function loadData() {
        try {
            const [tasksRes, pointagesRes] = await Promise.all([
                fetch('api.php?t=' + Date.now()),
                fetch('maintenance.php?get_pointages=1&t=' + Date.now())
            ]);
            allTasks = await tasksRes.json();
            allPointages = await pointagesRes.json();
        } catch (e) { console.error("Erreur chargement :", e); }
    }
    window.onload = loadData;

    // --- DONNÉES PHP (Injectées dans JS) ---
    const rawStatsTechs = <?php echo json_encode($stats_techs); ?>;
    const rawStatsJours = <?php echo json_encode($stats_jours); ?>;
    const photosTech = <?php echo json_encode($photos_par_tech); ?>;
    const rolesTech = <?php echo json_encode($roles_par_tech); ?>;

    // 1. Données Courbe (Évolution sur 7 jours)
    let labelsJours = rawStatsJours.length > 0 ? rawStatsJours.map(r => r.jour) : [I18N_STATS.no_data];
    let dataJours = rawStatsJours.length > 0 ? rawStatsJours.map(r => parseFloat(r.total)) : [0];

    // 2. Données Camembert Techniciens (Heures par tech)
    let labelsTechs = rawStatsTechs.length > 0 ? rawStatsTechs.map(r => r.tech) : [I18N_STATS.no_tech];
    let dataTechs = rawStatsTechs.length > 0 ? rawStatsTechs.map(r => parseFloat(r.total)) : [0];

    // --- Charge par technicien (liste classée, importée de la page KPI) ---
    function getRole(name) { return rolesTech[name] || I18N_STATS.role_default; }

    (function renderTechList() {
        const sortedT = rawStatsTechs
            .map(r => [r.tech, parseFloat(r.total) || 0])
            .filter(([n, v]) => n && v > 0)
            .sort((a, b) => b[1] - a[1]);
        const listEl = document.getElementById('tech-list');
        if (!listEl) return;

        if (sortedT.length === 0) {
            listEl.innerHTML = `<div style="text-align:center; padding:30px 20px; color:#95a5a6; font-weight:700;"><i class="fa-solid fa-mug-hot" style="font-size:1.8rem; margin-bottom:8px; display:block; color:#dbe3ea;"></i>${I18N_STATS.no_charge}</div>`;
            return;
        }
        const maxH = sortedT[0][1];
        listEl.innerHTML = sortedT.map(([n, v], i) => `
            <div class="tech-row" onclick="openTechHistory('${n.replace(/'/g, "\\'")}')" title="${I18N_STATS.voir_interventions} ${n}">
                <span class="tech-rank">${i + 1}</span>
                <img src="${photosTech[n] || 'img/user.png'}" class="tech-img" onerror="this.src='https://api.dicebear.com/7.x/initials/svg?seed=${n}'">
                <div class="tech-data">
                    <div class="row1">
                        <span class="tech-name">${n}<span class="tech-role">${getRole(n)}</span></span>
                        <span class="tech-hours">${v.toFixed(1)}h</span>
                    </div>
                    <div class="progress-box"><div class="progress-bar bar-tech" style="width:${(v / maxH) * 100}%"></div></div>
                </div>
            </div>`).join('');
    })();

    // --- CONFIGURATION DE CHART.JS ---
    Chart.defaults.font.family = "'Segoe UI', sans-serif";
    Chart.defaults.color = '#555';

    // Graphique 1 : Ligne / Courbe
    const ctxLine = document.getElementById('lineChart').getContext('2d');
    new Chart(ctxLine, {
        type: 'line',
        data: {
            labels: labelsJours,
            datasets: [{
                label: I18N_STATS.heures_prestees,
                data: dataJours,
                borderColor: '#2ecc71',
                backgroundColor: 'rgba(46, 204, 113, 0.2)',
                borderWidth: 3,
                pointBackgroundColor: '#2ecc71',
                pointBorderColor: '#fff',
                pointRadius: 5,
                fill: true,
                tension: 0.4
            }]
        },
        options: {
            responsive: true, maintainAspectRatio: false,
            plugins: { legend: { display: false } },
            scales: { y: { beginAtZero: true, grid: { color: '#f0f0f0' } }, x: { grid: { display: false } } }
        }
    });

    // --- LA FONCTION D'OUVERTURE DE MODALE (COMME SUR KPI) ---
    function openTechHistory(techName) {
        const modal = document.getElementById('historyModal');
        const body = document.getElementById('modalBody');
        const title = document.getElementById('modalTitle');
        
        // Le champ "tech" de la tâche ne reflète que qui a créé/validé le BI (l'émetteur),
        // jamais forcément qui est intervenu (voir le même principe dans suivi.php/aide_ot.php) :
        // s'y fier en plus des pointages faisait apparaître ici des BI traités par quelqu'un
        // d'autre, juste parce que cet admin avait validé/édité le bon un jour. Le camembert
        // (basé uniquement sur SUM(hours) des pointages) était déjà correct — seule cette liste
        // de détail reprenait à tort le champ "tech". Seul un vrai pointage (même 0h, qui fait
        // aussi apparaître comme intervenant) doit compter.
        let filteredTasks = allTasks.filter(t =>
            allPointages.some(p => p.task_id == t.id && p.tech && p.tech.trim() === techName)
        );

        title.innerHTML = `<i class="fa-solid fa-user-gear" style="color:var(--accent);"></i> ${I18N_STATS.interventions_liees_a} ${techName}`;

        if (filteredTasks.length === 0) {
            body.innerHTML = `<div style="text-align: center; padding: 40px; color: #95a5a6; font-weight: bold;">${I18N_STATS.aucune_intervention}</div>`;
            modal.style.display = "block";
            return;
        }

        // On génère le même tableau luxueux que sur la page KPI
        body.innerHTML = `
        <div style="overflow-x:auto; margin-top: 10px;">
            <table style="width:100%; border-collapse: collapse; font-family: 'Segoe UI', sans-serif; box-shadow: 0 4px 15px rgba(0,0,0,0.05); border-radius: 8px; overflow: hidden; table-layout: fixed;">
                <thead style="background: var(--primary); color: white;">
                    <tr>
                        <th style="padding:12px 10px; text-align:left; font-size:0.75rem; font-weight:700; text-transform:uppercase; width: 75px;">${I18N_STATS.th_bi}</th>
                        <th style="padding:12px 10px; text-align:left; font-size:0.75rem; font-weight:700; text-transform:uppercase; width: 95px;">${I18N_STATS.th_date}</th>
                        <th style="padding:12px 10px; text-align:left; font-size:0.75rem; font-weight:700; text-transform:uppercase;">${I18N_STATS.th_equip_desc}</th>
                        <th style="padding:12px 10px; text-align:left; font-size:0.75rem; font-weight:700; text-transform:uppercase; width: 140px;">${I18N_STATS.th_techs}</th>
                        <th style="padding:12px 10px; text-align:center; font-size:0.75rem; font-weight:700; text-transform:uppercase; width: 100px;">${I18N_STATS.th_statut}</th>
                    </tr>
                </thead>
                <tbody>
                    ${filteredTasks.map(t => {
                        let dateFormatee = t.date.split(' ')[0];
                        if (dateFormatee.includes('-')) dateFormatee = dateFormatee.split('-').reverse().join('/');

                        let biHtml = (t.num_bi && t.id) 
                            ? `<a href="#" onclick="showDetailBI('${t.id}'); return false;" style="color: #d35400; font-weight: 800; background: #fef5e7; border: 1px solid #f9e79f; padding: 3px 6px; border-radius: 4px; text-decoration: none; font-size: 0.7rem; display: inline-block; text-align:center; transition: 0.2s;" onmouseover="this.style.background='#fdebd0'" onmouseout="this.style.background='#fef5e7'">#${t.num_bi}</a>`
                            : `<span style="color: #95a5a6; font-weight: 600; font-size: 0.7rem; font-style:italic;">${I18N_STATS.prev_fallback}</span>`;

                        let s = (t.statut || "").toLowerCase();
                        let badgeStatut = '';
                        if (s.includes("termin")) {
                            badgeStatut = `<span style="background: ${LIBELLES.termine.couleur}; color: white; padding: 4px 8px; border-radius: 4px; font-size: 0.65rem; font-weight: 700; display: inline-block;">${LIBELLES.termine.label.toUpperCase()}</span>`;
                        } else if (s.includes("cours")) {
                            badgeStatut = `<span style="background: ${LIBELLES.encours.couleur}; color: white; padding: 4px 8px; border-radius: 4px; font-size: 0.65rem; font-weight: 700; display: inline-block;">${LIBELLES.encours.label.toUpperCase()}</span>`;
                        } else {
                            badgeStatut = `<span style="background: ${LIBELLES.afaire.couleur}; color: white; padding: 4px 8px; border-radius: 4px; font-size: 0.65rem; font-weight: 700; display: inline-block;">${LIBELLES.afaire.label.toUpperCase()}</span>`;
                        }

                        let localisation = [t.usine, t.secteur, t.zone].filter(Boolean).join(' > ');

                        let techsSet = new Set();
                        if (t.tech && t.tech.trim() !== "") techsSet.add(t.tech.trim());
                        allPointages.forEach(p => {
                            if (p.task_id == t.id && p.tech && p.tech.trim() !== "") techsSet.add(p.tech.trim());
                        });
                        let allTechs = Array.from(techsSet).join(', ');
                        let techDisplay = allTechs !== "" ? allTechs : `<span style="color: #95a5a6; font-style: italic;">${I18N_STATS.non_assigne}</span>`;

                        return `
                        <tr style="border-bottom: 1px solid #eef2f3; transition: background 0.15s;" onmouseover="this.style.background='rgba(52, 152, 219, 0.03)'" onmouseout="this.style.background='transparent'">
                            <td style="padding: 12px 10px; font-weight: bold; vertical-align: middle;">${biHtml}</td>
                            <td style="padding: 12px 10px; white-space: nowrap; color: #7f8c8d; font-size: 0.8rem; font-weight: 600; vertical-align: middle;">${dateFormatee}</td>
                            <td style="padding: 12px 10px; vertical-align: middle;">
                                ${localisation ? `<div style="font-size: 0.6rem; color: var(--accent); font-weight: bold; text-transform: uppercase; margin-bottom: 2px;"><i class="fa-solid fa-location-dot"></i> ${localisation}</div>` : ''}
                                <div style="font-weight: 700; color: var(--primary); font-size: 0.85rem;">${t.equip}</div>
                                <div style="font-size: 0.75rem; color: #5a6c7d; margin-top: 2px; font-style: italic; max-width: 500px; white-space: normal; word-break: break-word;">"${t.desc || ''}"</div>
                            </td>
                            <td style="padding: 12px 10px; font-weight: 600; color: #34495e; font-size: 0.8rem; vertical-align: middle;">${techDisplay}</td>
                            <td style="padding: 12px 10px; text-align: center; vertical-align: middle;">${badgeStatut}</td>
                        </tr>`;
                    }).join('')}
                </tbody>
            </table>
        </div>`;
        
        modal.style.display = "block";
    }

    // Graphique 2 : Camembert Techniciens (Doughnut) AVEC ONCLICK INCLUS
    const ctxPieTech = document.getElementById('pieTechChart').getContext('2d');
    new Chart(ctxPieTech, {
        type: 'doughnut',
        data: {
            labels: labelsTechs,
            datasets: [{
                data: dataTechs,
                backgroundColor: [
                    '#3498db', '#e74c3c', '#f1c40f', '#2ecc71', '#9b59b6', '#34495e', '#e67e22'
                ],
                borderWidth: 2,
                borderColor: '#fff'
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            cutout: '60%', 
            plugins: {
                legend: { position: 'right', labels: { boxWidth: 12 } }
            },
            // LA MAGIE DU CLIC EST ICI :
            onClick: (evt, activeElements) => {
                if (activeElements && activeElements.length > 0) {
                    const index = activeElements[0].index;
                    const clickedTech = labelsTechs[index];
                    openTechHistory(clickedTech);
                }
            }
        }
    });
</script>

<?php include 'composant_rapport.php'; ?>

</body>
</html>