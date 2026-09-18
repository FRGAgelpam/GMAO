<?php
require_once __DIR__ . '/session_init.php';

if (!isset($_SESSION['user']) || !isset($_SESSION['role']) || !in_array($_SESSION['role'], ['admin', 'technicien'])) {
    header("Location: demande.php");
    exit();
}

// Variable pour gérer l'affichage dynamique des onglets et boutons
$is_admin = ($_SESSION['role'] === 'admin');

require_once 'db.php';

// --- HABILLAGE DES STATUTS (configurable depuis Paramètres > Statuts & priorités) ---
// Ne change QUE la couleur affichée : les valeurs stockées en base ne sont pas touchées.
$LIBELLES_WORKFLOW = [
    'afaire'  => ['label' => t('maint.lib_afaire'),  'couleur' => '#f1c40f'],
    'encours' => ['label' => t('maint.lib_encours'), 'couleur' => '#3498db'],
    'termine' => ['label' => t('maint.lib_termine'), 'couleur' => '#2ecc71'],
];
try {
    if (isset($db)) {
        foreach ($db->query("SELECT bucket, label, couleur FROM libelles_workflow")->fetchAll(PDO::FETCH_ASSOC) as $l) {
            if (isset($LIBELLES_WORKFLOW[$l['bucket']])) { $LIBELLES_WORKFLOW[$l['bucket']]['couleur'] = $l['couleur']; }
        }
    }
} catch (Exception $e) {}

$heures_sql = [];
try {
    if(isset($db)) {
        // On récupère la somme des heures groupée par technicien et par date (format Y-m-d)
        $sql = "SELECT tech, DATE(date) as date_jour, SUM(hours) as total 
                FROM pointages 
                GROUP BY tech, DATE(date)";
        $res = $db->query($sql);
        $heures_sql = $res->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Exception $e) {}
// --- CHARGEMENT DES SOUS-TRAITANTS ---
    $entreprises_ext = [];
    if(isset($db)) {
        $resEE = $db->query("SELECT id, nom FROM entreprises_ext ORDER BY nom");
        $entreprises_ext = $resEE->fetchAll(PDO::FETCH_ASSOC);
    }

// --- NOUVEAU : CHARGEMENT DES MACHINES POUR LES CASCADES ---
    $machines_db = [];
    if(isset($db)) {
        // Ajuste les noms des colonnes (usine, secteur, zone, nom_machine) si elles sont différentes dans ta table
        $resM = $db->query("SELECT usine, secteur, zone, nom_machine FROM machines ORDER BY usine, secteur, zone, nom_machine");
        $machines_db = $resM->fetchAll(PDO::FETCH_ASSOC);
    }

// --- NOUVEAU : CHARGEMENT DE L'ÉQUIPE DE MAINTENANCE ---
    $equipe_db = [];
    if(isset($db)) {
        // On récupère l'équipe, mais ON EXCLUT le directeur (Directeur)
        $resE = $db->query("SELECT username, photo FROM utilisateurs WHERE role IN ('admin', 'technicien') AND username != 'Directeur' ORDER BY username");
        $equipe_db = $resE->fetchAll(PDO::FETCH_ASSOC);
    }

// --- HORAIRES D'ÉQUIPE IMPORTÉS (Paramètres > Planning) ---
    $planning_postes = [];
    $planning_astreintes = [];
    $planning_shifts = [];
    if (isset($db)) {
        try {
            $db->exec("CREATE TABLE IF NOT EXISTS planning_postes (cle VARCHAR(20) PRIMARY KEY, label VARCHAR(50), couleur VARCHAR(20), ordre INT, categorie VARCHAR(20) NOT NULL DEFAULT 'poste')");
            $db->exec("ALTER TABLE planning_postes ADD COLUMN IF NOT EXISTS categorie VARCHAR(20) NOT NULL DEFAULT 'poste'");
            $db->exec("CREATE TABLE IF NOT EXISTS planning_astreintes (cle VARCHAR(20) PRIMARY KEY, label VARCHAR(50), couleur VARCHAR(20), ordre INT)");
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
            $db->exec("ALTER TABLE planning_shifts ADD COLUMN IF NOT EXISTS jour_ferie TINYINT(1) NOT NULL DEFAULT 0");
            // Migration : "demi_cp" était auparavant un poste comme un autre (mutuellement exclusif avec
            // matin/après-midi/nuit/journée). Il devient une case "demi-congé" cumulable avec un poste — les
            // anciennes lignes enregistrées avec poste='demi_cp' deviennent poste vide + demi_conge=1 (même sens).
            $db->exec("UPDATE planning_shifts SET demi_conge = 1, poste = NULL WHERE poste = 'demi_cp'");
            // Même logique pour "jour_ferie" : devient une case cumulable avec un poste (matin/après-midi/
            // nuit/journée) plutôt qu'un poste exclusif, pour pouvoir cocher "travaillé de nuit" ET "jour férié"
            // le même jour sans que l'un efface l'autre.
            $db->exec("UPDATE planning_shifts SET jour_ferie = 1, poste = NULL WHERE poste = 'jour_ferie'");
            if ($db->query("SELECT COUNT(*) FROM planning_postes")->fetchColumn() == 0) {
                $stmtSeedPostes = $db->prepare("INSERT INTO planning_postes (cle, label, couleur, ordre) VALUES (?, ?, ?, ?)");
                foreach ([['matin', 'Matin', '#f1c40f', 0], ['apres_midi', 'Après-midi', '#2ecc71', 1], ['nuit', 'Nuit', '#3498db', 2], ['journee', 'Journée', '#d5d8dc', 3]] as $d) {
                    $stmtSeedPostes->execute($d);
                }
            }
            // "Repos" : jour de repos hebdomadaire d'une rotation variable (samedi/dimanche/n'importe quel
            // jour de semaine selon le planning), distinct du RTT — il ne consomme aucun crédit d'heures,
            // c'est juste la contrepartie légale du repos, comptée à 0h dû comme CP/RTT (voir referenceDuJour).
            if ((int)$db->query("SELECT COUNT(*) FROM planning_postes WHERE cle = 'repos'")->fetchColumn() === 0) {
                $db->prepare("INSERT INTO planning_postes (cle, label, couleur, ordre, categorie) VALUES (?, ?, ?, ?, 'evenement')")
                    ->execute(['repos', 'Repos', '#7f8c8d', 7]);
            }
            if ($db->query("SELECT COUNT(*) FROM planning_astreintes")->fetchColumn() == 0) {
                $stmtSeedAstreintes = $db->prepare("INSERT INTO planning_astreintes (cle, label, couleur, ordre) VALUES (?, ?, ?, ?)");
                foreach ([['classique', 'Astreinte', '#e74c3c', 0], ['froid', 'Astreinte froid', '#3498db', 1]] as $d) {
                    $stmtSeedAstreintes->execute($d);
                }
            }

            $planning_postes = $db->query("SELECT cle, label, couleur, categorie FROM planning_postes ORDER BY ordre")->fetchAll(PDO::FETCH_ASSOC);
            $planning_astreintes = $db->query("SELECT cle, label, couleur FROM planning_astreintes ORDER BY ordre")->fetchAll(PDO::FETCH_ASSOC);
            // Un libellé de poste/astreinte identique au défaut français d'origine (jamais personnalisé
            // par David) se retraduit selon la langue courante — même principe que maintenance.php
            // $SNAPSHOTS_FR_LIBELLES : seul un libellé vraiment renommé reste figé tel quel.
            $SNAPSHOTS_FR_POSTES = [
                'matin' => 'Matin', 'apres_midi' => 'Après-midi', 'nuit' => 'Nuit', 'journee' => 'Journée',
                'jour_ferie' => 'Jour férié', 'cp' => 'Congé payé', 'demi_cp' => 'Demi-congé payé',
                'maladie' => 'Maladie', 'rtt' => 'RTT', 'repos' => 'Repos',
            ];
            $SNAPSHOTS_FR_ASTREINTES = ['classique' => 'Astreinte', 'froid' => 'Astreinte froid'];
            foreach ($planning_postes as &$pp) {
                if (($pp['label'] ?? '') === '' || $pp['label'] === ($SNAPSHOTS_FR_POSTES[$pp['cle']] ?? null)) {
                    $pp['label'] = t('planning.poste_defaut_' . $pp['cle']);
                }
            }
            unset($pp);
            foreach ($planning_astreintes as &$pa) {
                if (($pa['label'] ?? '') === '' || $pa['label'] === ($SNAPSHOTS_FR_ASTREINTES[$pa['cle']] ?? null)) {
                    $pa['label'] = t('planning.astreinte_defaut_' . $pa['cle']);
                }
            }
            unset($pa);
            $planning_shifts = $db->query("SELECT utilisateur, jour, poste, astreinte, heures, note, demi_conge, jour_ferie FROM planning_shifts")->fetchAll(PDO::FETCH_ASSOC);
            // Confidentialité des notes : un technicien ne voit que ses propres notes, pas celles de ses collègues (réservé aux admins)
            if (!$is_admin) {
                foreach ($planning_shifts as &$ps) {
                    if ($ps['utilisateur'] !== $_SESSION['user']) { $ps['note'] = null; }
                }
                unset($ps);
            }
        } catch (Exception $e) {}
    }

// --- OBJECTIFS ANNUELS D'HEURES (paramétrables par technicien depuis Paramètres > Planning) ---
    $objectifs_annuels = [];
    if (isset($db)) {
        try {
            $db->exec("ALTER TABLE utilisateurs ADD COLUMN IF NOT EXISTS objectif_heures_annuel DECIMAL(6,2) NULL DEFAULT 1607");
            // Migration : l'objectif était auparavant modifié depuis Paramètres > Annualisation dans une
            // table à part (planning_objectifs), jamais relue par le planning — deux sources désynchronisées.
            // On reporte une bonne fois les valeurs déjà personnalisées vers utilisateurs, source unique désormais.
            if ($db->query("SHOW TABLES LIKE 'planning_objectifs'")->rowCount() > 0) {
                $db->exec("UPDATE utilisateurs u INNER JOIN planning_objectifs p ON p.utilisateur = u.username
                    SET u.objectif_heures_annuel = p.objectif_heures WHERE p.objectif_heures IS NOT NULL");
                // Une fois reportées, on vide la table pour que cette migration ne s'exécute qu'une seule
                // fois par technicien (sinon elle écraserait toute future modification à chaque rechargement).
                $db->exec("DELETE FROM planning_objectifs WHERE objectif_heures IS NOT NULL");
            }
            foreach ($db->query("SELECT username, objectif_heures_annuel FROM utilisateurs")->fetchAll(PDO::FETCH_ASSOC) as $o) {
                $objectifs_annuels[$o['username']] = $o['objectif_heures_annuel'] !== null ? (float)$o['objectif_heures_annuel'] : 1607.0;
            }
        } catch (Exception $e) {}
    }

// --- ANNUALISATION : période du 1er avril au 31 mars, et total d'heures par technicien sur cette période ---
    $annee_debut_annualisation = (int)date('n') >= 4 ? (int)date('Y') : (int)date('Y') - 1;
    $periode_annualisation_debut = "$annee_debut_annualisation-04-01";
    $periode_annualisation_fin = ($annee_debut_annualisation + 1) . "-03-31";
    $totaux_annualises = [];
    if (isset($db)) {
        try {
            // Congé/RTT/férié comptent pour 0h : le seuil de 1607h les a déjà déduits en amont. La maladie
            // est neutralisée "comme si" (jurisprudence annualisation) : créditée à 7h même sans heures
            // saisies, pour ne pas pénaliser un salarié qui ne peut pas rattraper une absence subie.
            $stmtTot = $db->prepare("SELECT utilisateur, SUM(COALESCE(heures,0) + CASE WHEN poste = 'maladie' THEN 7 ELSE 0 END) as total FROM planning_shifts WHERE jour BETWEEN ? AND ? GROUP BY utilisateur");
            $stmtTot->execute([$periode_annualisation_debut, $periode_annualisation_fin]);
            foreach ($stmtTot->fetchAll(PDO::FETCH_ASSOC) as $t) {
                $totaux_annualises[$t['utilisateur']] = (float)$t['total'];
            }
        } catch (Exception $e) {}
    }

// --- JOURS DE FRACTIONNEMENT (Art. 6 de l'accord d'annualisation) : accordés au cas par cas par la RH
// selon les conditions de prise du congé principal, pas calculables automatiquement. Saisie admin, par
// période de référence (remis à zéro chaque 1er avril puisque l'éligibilité se réévalue chaque année).
// 1 jour = -7h sur l'objectif annuel, 2 jours = -14h.
    $fractionnement_annuel = [];
    if (isset($db)) {
        try {
            $db->exec("CREATE TABLE IF NOT EXISTS planning_fractionnement (
                utilisateur VARCHAR(50) NOT NULL,
                periode_debut DATE NOT NULL,
                jours TINYINT NOT NULL DEFAULT 0,
                PRIMARY KEY (utilisateur, periode_debut)
            )");
            $stmtFrac = $db->prepare("SELECT utilisateur, jours FROM planning_fractionnement WHERE periode_debut = ?");
            $stmtFrac->execute([$periode_annualisation_debut]);
            foreach ($stmtFrac->fetchAll(PDO::FETCH_ASSOC) as $f) {
                $fractionnement_annuel[$f['utilisateur']] = (int)$f['jours'];
            }
        } catch (Exception $e) {}
    }

?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(langue_actuelle()); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <title><?php echo htmlspecialchars(t('planning.page_title_tag')); ?></title>
    <link rel="icon" type="image/png" href="img/logo.png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Caveat:wght@600&family=Segoe+UI:wght@300;400;600&family=Montserrat:wght@400;700;900&display=swap" rel="stylesheet">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
    <style>
        :root {
            --primary: #2c3e50; --accent: #3498db; --success: #2ecc71; 
            --danger: #e74c3c; --brand-green: #2ecc71; --brand-orange: #f39c12;
            --brand-blue: #005696;
        }

        body { 
            margin: 0; 
            font-family: 'Segoe UI', sans-serif; 
            /* On décale l'image de 100px vers le bas pour compenser la barre */
            background: linear-gradient(rgba(0, 0, 0, 0.2), rgba(0, 0, 0, 0.2)), url('img/fond.jpg') no-repeat center 0px fixed; 
            background-size: cover; 
            min-height: 100vh;
            padding-top: 98px;
            overflow: hidden;
        }

        @keyframes pulse-dot { 0% { transform: scale(1); opacity: 0.6; } 100% { transform: scale(2.5); opacity: 0; } }

        /* --- HEADER HARMONISÉ --- */
        header {
            position: fixed; top: 0; width: 100%; z-index: 1000; overflow: hidden;
            box-shadow: 0 4px 20px rgba(0,0,0,0.25);
        }
        header::before {
            content: ""; position: absolute; top: -12px; left: -12px; right: -12px; bottom: -12px;
            background: linear-gradient(rgba(0, 0, 0, 0.2), rgba(0, 0, 0, 0.2)), url('img/fond.jpg') no-repeat center 0px fixed;
            background-size: cover;
            filter: blur(4px);
            z-index: -1;
        }
        .header-top { display: flex; justify-content: space-between; align-items: center; padding: 12px 20px; }
        .header-title { font-family: 'Caveat', cursive; font-size: 1.5rem; color: white; background: rgba(255,255,255,0.12); backdrop-filter: blur(14px) brightness(1.15); -webkit-backdrop-filter: blur(14px) brightness(1.15); border: 1px solid rgba(255,255,255,0.4); border-radius: 20px; padding: 6px 18px; text-shadow: 0 2px 6px rgba(0,0,0,0.4); box-shadow: 0 6px 16px rgba(0,0,0,0.2); }

        .week-nav-center { display: flex; flex-direction: column; align-items: center; justify-content: center; background: #fff; border: 1px solid rgba(0,0,0,0.08); border-radius: 20px; padding: 4px 18px; box-shadow: 0 6px 16px rgba(0,0,0,0.2); }
        .crumb-bar { display: flex; align-items: center; gap: 8px; font-size: 0.94rem; font-weight: 600; color: rgba(255,255,255,0.9); text-shadow: 0 1px 3px rgba(0,0,0,0.4); padding: 0 20px 8px; }
        .crumb-bar .crumb-home { color: inherit; text-decoration: none; display: inline-flex; align-items: center; gap: 5px; }
        .crumb-bar .crumb-home:hover { text-decoration: underline; }
        .crumb-sep { color: rgba(255,255,255,0.45); font-weight: 400; }
        .crumb-current { color: rgba(255,255,255,0.75); font-weight: 400; }
        .nav-controls { display: flex; align-items: center; gap: 15px; }
        .week-label { font-family: 'Caveat', cursive; font-size: 1.6rem; color: var(--brand-green); font-weight: 700; line-height: 1; white-space: nowrap; }
        .date-range-sub { font-size: 0.65rem; font-weight: 800; color: var(--primary); text-transform: uppercase; margin-top: -2px; white-space: nowrap; }
        /* Sur tablette/téléphone, le bandeau .week-nav-center partage le peu de place restante avec le
           bouton accueil et le badge utilisateur (voir .header-top) : "Semaine XX" passait sur 2 lignes
           faute de largeur. white-space:nowrap seul aurait juste débordé/coupé le texte — on réduit donc
           aussi la police et les marges pour qu'il tienne réellement sur une ligne. */
        @media screen and (max-width: 1024px) {
            .nav-controls { gap: 8px; }
            .week-nav-center { padding: 3px 10px; }
            .week-label { font-size: 1.15rem; }
            .date-range-sub { font-size: 0.55rem; }
        }
        .nav-btn { background: rgba(46, 204, 113, 0.12); border: 1px solid rgba(46, 204, 113, 0.4); border-radius: 5px; padding: 2px 8px; cursor: pointer; transition: 0.2s; color: var(--brand-green); }
        .nav-btn:hover { background: rgba(46, 204, 113, 0.25); }

        .nav-tabs { display: flex; background: #fff; padding: 0 10px; gap: 2px; }
        .tab-item { 
            padding: 10px 18px; text-decoration: none; color: #7f8c8d; font-weight: 600; font-size: 0.8rem;
            border-bottom: 3px solid transparent; transition: 0.3s; display: flex; align-items: center; gap: 8px;
        }
        .tab-item:hover { background: rgba(0,0,0,0.02); }
        .tab-item.active { color: var(--accent); border-bottom: 3px solid var(--accent); background: rgba(52, 152, 219, 0.05); }

        .user-badge { background: rgba(255,255,255,0.12); backdrop-filter: blur(14px) brightness(1.15); -webkit-backdrop-filter: blur(14px) brightness(1.15); padding: 6px 14px; border-radius: 20px; font-size: 0.75rem; font-weight: 600; display: flex; align-items: center; gap: 8px; color: white; border: 1px solid rgba(255,255,255,0.4); text-shadow: 0 1px 3px rgba(0,0,0,0.4); box-shadow: 0 6px 16px rgba(0,0,0,0.2); }
        .status-pulse { width: 8px; height: 8px; border-radius: 50%; position: relative; background: var(--success); }
        .status-pulse::after { content: ""; position: absolute; width: 100%; height: 100%; border-radius: 50%; background: inherit; animation: pulse-dot 2s infinite; opacity: 0.6; }

        /* --- SIDEBAR HARMONISÉE (MENU MAÎTRE) --- */
        .sidebar { 
            height: 100%; 
            width: 0; 
            position: fixed; 
            z-index: 3000; 
            top: 0; 
            left: 0; 
            background-color: #1a252f; 
            overflow-x: hidden; 
            overflow-y: auto; 
            transition: 0.4s; 
            padding-top: 60px; 
            padding-bottom: 20px; 
        }
        .sidebar a { 
            padding: 12px 25px; 
            text-decoration: none; 
            font-size: 1.05rem; 
            color: #ecf0f1; 
            display: block; 
            transition: 0.3s; 
            border-left: 4px solid transparent; 
        }
        .sidebar a:hover { 
            background: #2c3e50; 
            border-left: 4px solid var(--accent); 
        }
        .sidebar a.active-side { 
            background: #2c3e50; 
            border-left: 4px solid var(--accent); 
            color: var(--accent); 
            font-weight: bold;
        }
        .sidebar .closebtn { 
            position: absolute; 
            top: 10px; 
            right: 25px; 
            font-size: 36px; 
            color: white; 
            border: none; 
            background: none; 
            cursor: pointer; 
        }
        .openbtn {
            font-size: 22px;
            cursor: pointer;
            background: none;
            border: none;
            color: var(--primary);
            padding: 5px 10px;
        }
        .btn-accueil { font-size: 18px; color: white; text-decoration: none; display: flex; align-items: center; justify-content: center; width: 38px; height: 38px; border-radius: 50%; background: rgba(255,255,255,0.12); backdrop-filter: blur(14px) brightness(1.15); -webkit-backdrop-filter: blur(14px) brightness(1.15); border: 1px solid rgba(255,255,255,0.4); box-shadow: 0 6px 16px rgba(0,0,0,0.2); transition: transform 0.2s, background 0.2s; }
        .btn-accueil:hover { background: rgba(255,255,255,0.28); transform: translateY(-2px); }
        .btn-accueil-green:hover { background: rgba(46, 204, 113, 0.3); border-color: rgba(46, 204, 113, 0.6); }

        .side-nav-btn {
            position: fixed; top: 50%; transform: translateY(-50%); width: 50px; height: 100px;
            background: rgba(44, 62, 80, 0.4); backdrop-filter: blur(5px); border: 1px solid rgba(255, 255, 255, 0.2);
            color: white; display: flex; align-items: center; justify-content: center; cursor: pointer;
            z-index: 4500; transition: 0.3s; font-size: 2rem; opacity: 0.7;
        }
        .side-nav-btn:hover { background: var(--primary); opacity: 1; color: var(--brand-green); width: 65px; }
        .side-btn-left { left: 0; border-radius: 0 15px 15px 0; }
        .side-btn-right { right: 0; border-radius: 15px 0 0 15px; }
        /* Ces flèches servent à glisser un bon d'intervention vers le bord de l'écran pour changer de
           semaine (voir dragover plus bas) — un geste souris, sans équivalent tactile utile. Sur
           tablette/téléphone, la navigation se fait déjà via #daySwitcher / #techSwitcher : elles ne font
           que flotter au milieu de l'écran sans rien apporter. */
        @media screen and (max-width: 1024px) {
            .side-nav-btn { display: none; }
        }

        /* --- PLANNING --- */
        .planning-container {
            display: grid; 
            grid-template-columns: 160px repeat(7, minmax(0, 1fr));
            background: rgba(255, 255, 255, 0.85);
            margin: 0 4px 4px 4px; /* On rogne la grande marge blanche */
            border-radius: 8px;
            height: calc(100vh - 102px); /* Ajusté pour coller juste sous le nouvel en-tête plus fin */
            /* grid-template-rows posé en JS (renderTable) à "auto repeat(N, 1fr)" selon le nombre réel de
               techniciens : l'en-tête garde sa hauteur naturelle, les N lignes techniciens se partagent
               À PARTS ÉGALES le reste — elles tiennent donc toujours dans la fenêtre, quel que soit leur
               contenu (voir overflow-y sur .drop-zone/.tech-sidebar pour l'excédent d'une case précise). */
            overflow-y: auto; /* Filet de sécurité résiduel, ne devrait normalement plus jamais se déclencher */
            overflow-x: hidden;
            border: 1px solid rgba(0,0,0,0.1);
            backdrop-filter: blur(3px);
        }
        /* Vue "1 technicien" (voir renderTable, JS) : la ligne unique est en hauteur "auto" plutôt que
           "1fr", donc sans ceci elle s'étirerait quand même pour combler tout .planning-container
           (align-content:stretch est la valeur par défaut d'une grille). */
        .planning-container.un-seul-tech { align-content: start; }

        /* --- VUE "1 JOUR" (tablette/mobile, écran < 1024px) ---
           Le tableau à 7 colonnes fixes (160px + repeat(7, minmax(0, 1fr))) déborde largement sous 1024px de large.
           Plutôt que de réécrire le moteur de rendu (renderTable), on masque en JS les cellules des 6 autres
           jours (voir data-day-idx posé dans renderTable + appliquerVisibiliteJours) et on réduit la grille à
           2 colonnes ici : la grille CSS se replace alors naturellement autour des seules cellules visibles,
           sans toucher à la logique de génération des cellules elle-même. */
        @media screen and (max-width: 1024px) {
            .planning-container.mode-jour { grid-template-columns: 108px 1fr; }
        }
        .day-switcher { display: none; }
        .day-switcher.show { display: flex; align-items: center; gap: 4px; justify-content: center; padding: 6px 4px; background: rgba(255,255,255,0.9); backdrop-filter: blur(3px); margin: 0 4px; border-radius: 8px 8px 0 0; }
        .day-switcher-arrow { background: rgba(46, 204, 113, 0.12); border: 1px solid rgba(46, 204, 113, 0.4); border-radius: 6px; color: var(--brand-green); width: 30px; height: 34px; flex-shrink: 0; cursor: pointer; font-size: 0.9rem; }
        .day-switcher-arrow:hover { background: rgba(46, 204, 113, 0.25); }
        .day-switcher-pills { display: flex; gap: 3px; flex: 1; min-width: 0; }
        .day-switcher-pill { flex: 1; min-width: 0; border: 1px solid rgba(0,0,0,0.1); background: #f8f9fa; border-radius: 6px; padding: 4px 2px; cursor: pointer; text-align: center; line-height: 1.15; }
        .day-switcher-pill .dsp-name { display: block; font-size: 0.62rem; font-weight: 800; color: #7f8c8d; text-transform: uppercase; }
        .day-switcher-pill .dsp-date { display: block; font-size: 0.6rem; font-weight: 600; color: var(--brand-blue); }
        .day-switcher-pill.active { background: var(--brand-green); border-color: var(--brand-green); }
        .day-switcher-pill.active .dsp-name, .day-switcher-pill.active .dsp-date { color: #fff; }
        .day-switcher-pill.today:not(.active) { border-color: var(--brand-green); border-width: 2px; }

        /* --- SÉLECTEUR DE TECHNICIEN (vue "1 technicien", tablette/mobile) --- */
        .tech-switcher { display: none; }
        .tech-switcher.show { display: flex; align-items: center; gap: 10px; padding: 8px 12px; background: #fff; margin: 6px 4px 10px; border-radius: 12px; box-shadow: 0 4px 14px rgba(0,0,0,0.18); color: var(--primary); }
        .tech-switcher-icon { width: 30px; height: 30px; border-radius: 50%; flex-shrink: 0; background: var(--brand-green); color: #fff; display: flex; align-items: center; justify-content: center; font-size: 0.85rem; box-shadow: 0 3px 8px -2px rgba(46,204,113,0.6); }
        .tech-switcher select { flex: 1; border: 1.5px solid #e2e8f0; border-radius: 8px; padding: 8px 10px; font-family: 'Segoe UI', sans-serif; font-size: 0.9rem; font-weight: 700; color: var(--primary); background: #fff; }
        .tech-switcher select:focus { border-color: var(--brand-green); outline: none; }
        .tech-switcher select:disabled { background: #f8fafc; color: #64748b; border-color: #e2e8f0; opacity: 1; -webkit-appearance: none; appearance: none; }
        /* Téléphone portrait : sélecteur de technicien, pastilles de jour et tuile horaire du jour, tous
           un peu plus grands qu'en paysage/tablette (voir aussi .pdc-shift, plus bas) — davantage de place
           verticale disponible dans ce format, donc pas besoin de rester aussi compact. */
        @media screen and (max-width: 480px) {
            .tech-switcher-icon { width: 36px; height: 36px; font-size: 1rem; }
            .tech-switcher select { font-size: 1rem; padding: 10px 12px; }
            .day-switcher-pill { padding: 8px 4px; }
            .day-switcher-pill .dsp-name { font-size: 0.72rem; }
            .day-switcher-pill .dsp-date { font-size: 0.7rem; }
        }

        /* --- AGENDA "CARTES" (vue mobile 1 technicien x 1 jour) ---
           Remplace la grille desktop dans ce mode : les infos qui n'étaient visibles qu'au survol sur les
           étiquettes compactes (.task-tooltip, inutilisable au tactile — pas de hover sur mobile) sont ici
           directement affichées en clair sur la carte, comme demandé. */
        .planning-day-cards { display: none; flex-direction: column; gap: 10px; margin: 4px; }
        .planning-day-cards.show { display: flex; }
        .pdc-card { position: relative; background: #fff; border-radius: 12px; padding: 12px 14px 12px 18px; box-shadow: 0 4px 14px rgba(0,0,0,0.15); overflow: hidden; }
        .pdc-accent { position: absolute; top: 0; left: 0; bottom: 0; width: 5px; }
        .pdc-shift-poste { font-weight: 700; color: var(--primary); font-size: 0.92rem; display: flex; align-items: center; gap: 7px; }
        .pdc-shift-astreinte { font-size: 0.82rem; color: var(--c, #555); font-weight: 600; margin-top: 5px; display: flex; align-items: center; gap: 7px; }
        .pdc-shift-note { font-size: 0.78rem; color: #8a6d00; background: #fff8e6; border: 1px solid #f5cb5c; border-radius: 6px; padding: 6px 8px; margin-top: 7px; display: flex; align-items: flex-start; gap: 6px; }
        /* 3 compteurs sur une ligne + barre de progression fine, sous la tuile horaire. Mêmes valeurs que
           la fenêtre "Planning annuel" (getObjectifEffectif/calculerReferenceHeures35h réutilisés tels
           quels dans renderPlanningDayCards, JS) : avance/retard sur le rythme 35h/semaine, heures faites
           sur objectif, heures restantes (ou dépassement). */
        .pdc-obj-row { display: flex; gap: 6px; margin-top: 10px; padding-top: 10px; border-top: 1px dashed #eef2f5; }
        .pdc-obj-stat { flex: 1; min-width: 0; text-align: center; display: flex; flex-direction: column; gap: 1px; }
        .pdc-obj-val { font-weight: 800; color: var(--primary); font-size: 0.98rem; white-space: nowrap; }
        .pdc-obj-label { font-size: 0.58rem; font-weight: 700; color: #94a3b8; text-transform: uppercase; letter-spacing: 0.02em; }
        .pdc-obj-stat.avance .pdc-obj-val { color: var(--brand-green); }
        .pdc-obj-stat.retard .pdc-obj-val { color: var(--danger); }
        .pdc-obj-track-row { display: flex; align-items: center; gap: 8px; margin-top: 10px; }
        .pdc-obj-track { position: relative; flex: 1; height: 5px; background: #e2e8f0; border-radius: 3px; }
        .pdc-obj-fill { height: 100%; border-radius: 3px; background: linear-gradient(90deg, #3498db, var(--brand-green)); transition: width 0.3s ease; }
        .pdc-obj-fill.over { background: linear-gradient(90deg, var(--brand-orange), var(--danger)); }
        /* Repère 35h/semaine (congés déjà posés inclus) : trait vertical qui déborde légèrement de la
           barre (top/bottom négatifs) pour rester visible même quand il tombe près du remplissage. */
        .pdc-obj-marker { position: absolute; top: -2px; bottom: -2px; width: 2px; background: rgba(44,62,80,0.8); }
        .pdc-obj-track-label { font-size: 0.68rem; font-weight: 700; color: #64748b; white-space: nowrap; flex-shrink: 0; }
        /* Placé APRÈS les règles de base .pdc-shift-*/.pdc-obj-* ci-dessus : à spécificité égale, une media
           query ne l'emporte que si elle apparaît plus loin dans le fichier (même piège déjà rencontré
           avec .tech-annual-badge) — sinon la règle de base la re-écrase silencieusement. */
        @media screen and (max-width: 480px) {
            .pdc-shift { padding: 16px 18px 16px 22px; }
            .pdc-shift-poste { font-size: 1.05rem; gap: 9px; }
            .pdc-shift-astreinte { font-size: 0.92rem; }
            .pdc-obj-row { margin-top: 12px; padding-top: 12px; }
            .pdc-obj-val { font-size: 1.05rem; }
            .pdc-obj-label { font-size: 0.62rem; }
            .pdc-obj-track { height: 6px; }
            .pdc-obj-track-label { font-size: 0.74rem; }
        }
        .pdc-task { cursor: pointer; }
        .pdc-task-top { display: flex; align-items: center; justify-content: space-between; gap: 8px; margin-bottom: 6px; }
        .pdc-task-bi { font-weight: 800; color: var(--brand-orange); font-size: 0.95rem; }
        .pdc-task-statut { font-size: 0.72rem; font-weight: 700; text-transform: uppercase; }
        .pdc-task-machine { font-weight: 600; color: var(--primary); font-size: 0.86rem; margin-bottom: 4px; }
        .pdc-task-machine i { opacity: 0.5; margin-right: 4px; font-size: 0.75rem; }
        .pdc-task-loc { font-size: 0.72rem; color: #64748b; margin-bottom: 6px; }
        .pdc-task-desc { font-size: 0.8rem; color: #475569; line-height: 1.35; margin-bottom: 8px; }
        .pdc-task-meta { display: flex; flex-wrap: wrap; gap: 12px; font-size: 0.75rem; font-weight: 600; color: #64748b; margin-bottom: 8px; }
        .pdc-task-bottom { font-size: 0.75rem; color: #64748b; display: flex; align-items: center; gap: 6px; padding-top: 7px; border-top: 1px dashed #eef2f5; }
        .pdc-empty { text-align: center; color: rgba(255,255,255,0.85); font-size: 0.85rem; font-style: italic; padding: 30px 10px; text-shadow: 0 1px 4px rgba(0,0,0,0.5); }

        /* On fige les jours en haut de l'écran lors du défilement */
        .day-header, .planning-container > div:first-child { 
            padding: 8px 5px; text-align: center; border-bottom: 4px solid var(--brand-green); 
            background: rgba(255,255,255,0.95); position: sticky; top: 0; z-index: 50; 
        }
        .day-header .day-name { font-family: 'Caveat', cursive; font-size: 1.4rem; color: #222; line-height: 1; }
        .day-header .day-date { font-size: 0.65rem; color: var(--brand-blue); font-weight: 800; }

        .day-header.today-active { background: rgba(46, 204, 113, 0.15); border: 2px solid var(--brand-green); border-bottom: 4px solid var(--brand-green); }
        .day-header.today-active::after {
            content: '<?php echo addslashes(t('planning.aujourdhui')); ?>'; position: absolute; bottom: -2px; left: 50%; transform: translateX(-50%);
            background: var(--brand-green); color: white; font-size: 0.5rem; padding: 0 6px; border-radius: 3px; font-weight: 900;
        }

        .tech-sidebar { border-right: 1px solid rgba(0,0,0,0.1); border-bottom: 1px solid rgba(0,0,0,0.05); background: rgba(255,255,255,0.2); display: flex; align-items: center; padding: 4px 8px; gap: 6px; overflow: hidden; min-height: 0; }
        .tech-sidebar-clickable { cursor: pointer; transition: background 0.15s; }
        .tech-sidebar-clickable:hover { background: rgba(52,152,219,0.14); }
        .avatar-wrapper { width: 26px; height: 26px; border-radius: 50%; border: 2px solid #fff; overflow: hidden; flex-shrink: 0; }
        .avatar-wrapper img { width: 100%; height: 100%; object-fit: cover; }
        .tech-name { font-family: 'Caveat', cursive; font-size: 1.15rem; color: var(--primary); font-weight: 600; line-height: 1; text-transform: capitalize; }
        .tech-role { font-size: 0.55rem; color: #555; font-style: italic; line-height: 1.05; margin-top: 1px; }
        .tech-hours-badge { font-size: 0.56rem; font-weight: 800; color: #fff; background: var(--brand-blue); padding: 0px 5px; border-radius: 10px; display: inline-block; margin-top: 2px; }
        .tech-annual-badge { font-size: 0.52rem; font-weight: 700; color: var(--primary); background: rgba(0,0,0,0.06); padding: 0px 5px; border-radius: 10px; display: inline-block; margin-top: 2px; margin-left: 3px; }
        .tech-annual-badge i { font-size: 0.52rem; margin-right: 2px; }

        /* --- PAYSAGE TÉLÉPHONE (large mais peu haut, < 480px de hauteur) ---
           Avec beaucoup de techniciens, le partage à parts égales de la hauteur (voir renderTable, JS)
           peut descendre sous la taille lisible d'une ligne — le JS impose alors un plancher de 46px
           (minmax(46px,1fr)) et laisse le conteneur défiler ; ici on allège le contenu de chaque ligne
           technicien pour qu'il tienne dans ce plancher (rôle et badge annuel masqués, avatar/police
           réduits). Placé APRÈS les règles de base .tech-* : à spécificité égale, une media query ne
           l'emporte que si elle apparaît plus loin dans le fichier — sinon la règle de base la re-écrase.*/
        @media screen and (max-height: 480px) {
            .tech-sidebar { padding: 2px 6px; gap: 4px; }
            .avatar-wrapper { width: 20px; height: 20px; }
            .tech-name { font-size: 0.82rem; line-height: 0.9; }
            .tech-role { display: none; }
            .tech-hours-badge { font-size: 0.48rem; }
            .tech-annual-badge { display: none; }
        }

        /* --- BADGE ULTRA COMPACT (Style Étiquette 6-places) --- */
        .task-badge-compact {
            background: white; border-radius: 4px;
            padding: 1px 3px; /* On affine l'intérieur du badge */
            box-shadow: 0 2px 4px rgba(0,0,0,0.15); cursor: grab; border-left: 3px solid #ccc;
            position: relative; z-index: 10; display: flex; justify-content: center;
            align-items: center; width: 100%; box-sizing: border-box;
            /* Pas d'overflow:hidden ici : ça masquerait aussi la tuile d'info (.task-tooltip),
               qui est un enfant positionné en absolu et doit pouvoir déborder de cette boîte.
               La troncature du texte de l'étiquette se fait uniquement sur le span ci-dessous. */
            font-size: 0.52rem; /* Police réduite pour que les cases tiennent sans jamais défiler */
            font-weight: 800; color: var(--primary);
            transition: 0.2s; white-space: nowrap;
        }
        .task-badge-compact > span:first-child { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; min-width: 0; }

        .task-badge-compact:hover { background: #f8f9fa; transform: scale(1.05); z-index: 100; box-shadow: 0 4px 8px rgba(0,0,0,0.2); }

        .task-tooltip {
            visibility: hidden; opacity: 0; position: absolute; z-index: 10000;
            background: rgba(26, 37, 47, 0.95); backdrop-filter: blur(5px); color: white;
            text-align: left; padding: 12px; border-radius: 8px; width: 220px; box-sizing: border-box;
            /* .task-badge-compact (l'ancêtre) impose white-space:nowrap pour son étiquette compacte ;
               ça se transmet par héritage à la tuile si on ne le réinitialise pas ici, ce qui empêchait
               tout retour à la ligne (et rendait break-word ci-dessous inopérant). */
            white-space: normal; overflow-wrap: break-word; word-break: break-word;
            top: 120%; left: 50%; transform: translateX(-50%);
            transition: all 0.2s ease-in-out; pointer-events: none;
            box-shadow: 0 10px 25px rgba(0,0,0,0.4); font-weight: normal;
            border: 1px solid rgba(255,255,255,0.1);
        }
        .task-tooltip::after {
            content: ""; position: absolute; bottom: 100%; left: 50%;
            margin-left: -6px; border-width: 6px; border-style: solid;
            border-color: transparent transparent rgba(26, 37, 47, 0.95) transparent;
        }
        .task-badge-compact:hover .task-tooltip { visibility: visible; opacity: 1; top: 135%; }

        /* Dernière ligne technicien (voir tech-row-last posé en JS) : la tuile d'info s'ouvre vers le bas
           par défaut, ce qui la fait sortir de la fenêtre pour la ligne tout en bas — on l'ouvre donc vers
           le haut uniquement pour cette ligne-là, quel que soit le technicien qui s'y trouve. */
        .tech-row-last .task-tooltip { top: auto; bottom: 120%; }
        .tech-row-last .task-badge-compact:hover .task-tooltip { top: auto; bottom: 135%; }
        .tech-row-last .task-tooltip::after {
            bottom: auto; top: 100%;
            border-color: rgba(26, 37, 47, 0.95) transparent transparent transparent;
        }

        /* Colonne de gauche/droite (voir edge-col-first/edge-col-last poses en JS, semaine ET mois) : la
           tuile de 220px, centree par defaut sous l'etiquette, deborderait du cadre du planning pour un
           bon d'intervention du premier ou dernier jour affiche — on colle alors la tuile contre le bord
           interieur de l'etiquette au lieu de la centrer, pour qu'elle grandisse vers l'interieur du cadre. */
        .edge-col-first .task-tooltip { left: 0; transform: none; }
        .edge-col-first .task-tooltip::after { left: 20px; margin-left: 0; }
        .edge-col-last .task-tooltip { left: auto; right: 0; transform: none; }
        .edge-col-last .task-tooltip::after { left: auto; right: 20px; margin-left: 0; }

        .status-afaire { border-left-color: var(--brand-orange) !important; }
        .status-encours { border-left-color: var(--accent) !important; }
        .status-termine { border-left-color: var(--brand-green) !important; opacity: 0.6; }
        .status-urgent { border-left-color: var(--danger) !important; background: #fff5f5; }
        .blink-icon { color: var(--danger); animation: blinker 1s linear infinite; font-size: 0.7rem; }
        @keyframes blinker { 50% { opacity: 0; } }
        
        /* --- CONTENEUR 2 COLONNES FIXES (les bons ne s'empilent plus en 1 seule colonne) --- */
        .drop-zone {
            border-right: 1px solid rgba(0,0,0,0.05); border-bottom: 1px solid rgba(0,0,0,0.05);
            padding: 2px; /* On réduit la marge de la case */
            min-height: 0; /* Explicite (pas "auto") : la ligne (grid-template-rows posé en JS) fixe déjà la
                              hauteur dispo, ce chiffre empêche le contenu d'agrandir la case/la grille — donc
                              jamais de scrollbar. Overflow reste "visible" (pas de valeur posée ici) pour ne
                              pas rogner la tuile d'info (.task-tooltip), positionnée en absolu et qui doit
                              pouvoir déborder de la case ; le contenu est rendu compact pour que ce cas
                              (déborder visuellement de sa case) reste rarissime en usage réel. */
            transition: all 0.15s ease-in-out;
            display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); align-content: flex-start; align-items: start;
            gap: 2px; /* Espace entre les badges réduit */
        }
        .drop-zone.drag-over { background: rgba(46, 204, 113, 0.2) !important; border: 2px dashed var(--brand-green); }

        /* --- Badges d'horaires d'équipe (postes/astreintes importés depuis Excel) --- */
        .shift-badges-row {
            grid-column: 1 / -1; /* Réserve toute la 1ère ligne : les bons d'intervention ne peuvent jamais s'y glisser */
            display: flex; flex-wrap: wrap; gap: 3px;
        }
        .shift-badge-compact {
            --c: #ccc;
            width: fit-content;
            background: white; border-radius: 4px;
            padding: 1px 4px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.15);
            border: 1px solid var(--c);
            border-left: 3px solid var(--c);
            font-size: 0.52rem; font-weight: 800; color: var(--primary);
            text-transform: uppercase; letter-spacing: 0.01em;
        }
        .shift-badge-nohours {
            background: repeating-linear-gradient(45deg, #fff, #fff 4px, #fafafa 4px, #fafafa 8px);
            border-style: dashed;
            opacity: 0.75;
        }
        .shift-note-badge {
            width: fit-content;
            background: #fff8e6; border: 1px solid #f5cb5c; border-radius: 4px;
            padding: 1px 4px; box-shadow: 0 2px 4px rgba(0,0,0,0.15);
            color: #8a6d00; font-size: 0.54rem; cursor: help;
            position: relative;
        }
        .shift-note-bubble {
            visibility: hidden; opacity: 0; position: absolute; z-index: 500;
            bottom: 135%; left: 0;
            background: #fffde7; color: #4a3f00;
            text-align: left; padding: 8px 10px; border-radius: 6px 6px 6px 2px;
            width: 170px; box-sizing: border-box;
            white-space: normal; overflow-wrap: break-word; word-break: break-word;
            font-family: 'Segoe UI', sans-serif; font-weight: 500; font-size: 0.7rem; line-height: 1.35;
            border: 1px solid #e6d375;
            box-shadow: 3px 4px 8px rgba(0,0,0,0.22);
            transition: opacity 0.15s ease-in-out;
            pointer-events: none;
        }
        .shift-note-bubble::after {
            content: ""; position: absolute; top: 100%; left: 4px;
            border-width: 7px 7px 0 0; border-style: solid;
            border-color: #fffde7 transparent transparent transparent;
            filter: drop-shadow(1px 2px 1px rgba(0,0,0,0.1));
        }
        .shift-note-badge:hover .shift-note-bubble { visibility: visible; opacity: 1; }

        .annual-day-note-dot {
            position: absolute; top: -1px; right: 0px; z-index: 5;
            background: none; border: none; box-shadow: none; padding: 0;
            font-size: 0.55rem; line-height: 1; color: #c9960c; cursor: help;
        }
        .annual-note-bubble { bottom: 120%; top: auto; left: auto; right: -4px; width: 150px; }
        .annual-note-bubble::after { left: auto; right: 8px; }

        /* --- ������ ANIMATION ET EFFET D'ACCROCHE VISUELLE AU SURVOL --- */
        .drop-zone:hover { 
        background-color: rgba(52, 152, 219, 0.08) !important; /* Un voile bleu très léger et transparent */
        box-shadow: inset 0 0 0 2px rgba(52, 152, 219, 0.25); /* Crée un encadrement intérieur bleu net sans décaler le tableau */
        cursor: pointer; /* Le curseur devient une main pour "accrocher" le regard */
        }

/* --- STYLE POP-UP INDICATEUR DE SEMAINE GRIS ANTHRACITE (STYLE HOVER FLÈCHES) --- */
#week-indicator { 
    position: fixed; 
    top: 50%; 
    left: 50%; 
    transform: translate(-50%, -50%) scale(0.9); 
    background: #2c3e50; /* Gris anthracite/ardoise profond (ta variable --primary) */
    color: #ffffff; /* Le mot "Planning" passe en blanc pur pour un contraste maximal */
    padding: 6px 20px; 
    border-radius: 8px; 
    box-shadow: 0 8px 25px rgba(0, 0, 0, 0.35); /* Une belle ombre pour le faire flotter proprement */
    z-index: 99999; 
    border: 1px solid rgba(255, 255, 255, 0.1); /* Fine bordure claire pour détacher le bloc */
    font-family: 'Caveat', cursive; 
    font-size: 1.6rem; 
    font-weight: 700;
    pointer-events: none; 
    opacity: 0; 
    visibility: hidden;
    transition: all 0.2s ease-in-out; 
    display: flex;
    align-items: center;
    gap: 6px;
}

/* Version allumée */
#week-indicator.show {
    opacity: 1;
    visibility: visible;
    transform: translate(-50%, -50%) scale(1); 
}

/* Le numéro de semaine */
#week-indicator span { 
    font-family: 'Caveat', cursive; 
    font-size: 1.6rem; 
    color: var(--brand-green); /* Reste en vert vif, l'effet va être magnifique sur l'anthracite */
    font-weight: 700;
}

        .edge-nav { position: fixed; top: 100px; bottom: 0; width: 25px; opacity: 0; z-index: 4000; pointer-events: none; transition: 0.3s; }
        .edge-left { left: 0; border-right: 5px solid var(--brand-orange); background: linear-gradient(to right, rgba(243,146,0,0.1), transparent); }
        .edge-right { right: 0; border-left: 5px solid var(--brand-green); background: linear-gradient(to left, rgba(78,157,45,0.1), transparent); }
        .edge-nav.active { opacity: 1; width: 60px; }

        /* --- MODIFICATION DEMANDÉE : MODALE PLUS LARGE (800px) --- */
        .modal { display: none; position: fixed; z-index: 10000; left: 0; top: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.6); backdrop-filter: blur(4px); }
        .modal-content { background: white; margin: 5% auto; padding: 25px; border-radius: 12px; width: 800px; box-shadow: 0 10px 30px rgba(0,0,0,0.5); }
        .modal-header { font-family: 'Caveat', cursive; font-size: 2rem; border-bottom: 2px solid var(--brand-green); margin-bottom: 15px; display: flex; justify-content: space-between; align-items: center;}
        
        /* --- MODIFICATION DEMANDÉE : 3 COLONNES AU LIEU DE 2 --- */
        .m-grid { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 15px; }
        
        .m-field { display: flex; flex-direction: column; font-weight: 800; font-size: 0.7rem; color: #444; }
        .m-field input, .m-field select, .m-field textarea { padding: 8px; border: 1px solid #ccc; border-radius: 5px; margin-top: 4px; font-family: 'Segoe UI'; }
        .btn-save { background: var(--brand-green); color: white; border: none; padding: 12px; border-radius: 6px; cursor: pointer; font-weight: 900; margin-top: 20px; width: 100%; }
        .btn-save:disabled { background: #cbd5e1; cursor: not-allowed; }
        .btn-del { color: var(--danger); cursor: pointer; font-size: 1.2rem; transition: 0.2s; }
        .btn-del:hover { transform: scale(1.2); }

        /* Choix "Planifier les heures / Créer un BI" au clic sur une case (voir #choixCaseModal,
           ouvrirChoixCase) : deux gros boutons colorés plutôt qu'une liste, pour rester lisible au doigt
           sur tablette comme à la souris. Vert = planning (couleur d'action de cette page), orange =
           création de BI (même esprit que .ot-create-tile sur Saisie & Historique) — en teinte douce
           (fond pastel + texte coloré) plutôt qu'un dégradé plein, un premier essai vif s'étant révélé
           trop criard pour deux boutons pleine largeur côte à côte. */
        .choix-case-btn { display: flex; align-items: center; gap: 14px; width: 100%; box-sizing: border-box; padding: 16px 18px; border: none; border-radius: 10px; cursor: pointer; font-size: 0.95rem; font-weight: 700; text-align: left; font-family: inherit; transition: 0.2s; }
        .choix-case-btn i { font-size: 1.3rem; flex-shrink: 0; width: 24px; text-align: center; }
        .choix-case-btn:hover { transform: translateY(-2px); box-shadow: 0 6px 14px rgba(0,0,0,0.1); }
        .choix-case-btn-heures { background: #e8f8ef; color: #1e8449; }
        .choix-case-btn-bi { background: #fef3c7; color: #92400e; }
        .choix-case-btn-todo { background: #f3e8ff; color: #6b21a8; }

        /* --- TODO LIST PAR JOUR (voir #todoModal, ouvrirTodoModal) : une tâche non cochée se reporte
           automatiquement au jour suivant (voir todo_add/get_todo côté maintenance.php) plutôt que de
           rester bloquée sur un jour passé. --- */
        .todo-item { display: flex; align-items: center; gap: 10px; padding: 8px 10px; border-radius: 8px; background: #f8fafc; }
        .todo-item input[type="checkbox"] { width: 17px; height: 17px; flex-shrink: 0; cursor: pointer; accent-color: var(--brand-green); }
        .todo-item-texte { flex: 1; font-size: 0.88rem; color: var(--primary); word-break: break-word; }
        .todo-item.is-fait .todo-item-texte { text-decoration: line-through; color: #94a3b8; }
        .todo-item-del { color: #cbd5e1; cursor: pointer; transition: 0.2s; flex-shrink: 0; }
        .todo-item-del:hover { color: var(--danger); }
        .todo-empty-state { text-align: center; color: #94a3b8; font-size: 0.85rem; padding: 14px 0; }

        /* Pastille "todo list" sur une case du planning (voir todoBadgeHtml) : même gabarit que
           .shift-badge-compact/.shift-note-badge pour rester cohérent avec les autres petites étiquettes
           de case, en violet pour rester distinct des postes/astreintes/BI. */
        .todo-cell-badge { width: fit-content; background: #f3e8ff; border: 1px solid #d8b4fe; border-radius: 4px; padding: 1px 4px; box-shadow: 0 2px 4px rgba(0,0,0,0.15); color: #6b21a8; font-size: 0.54rem; font-weight: 800; cursor: pointer; }
        .todo-cell-badge.tout-fait { opacity: 0.55; }
        .mois-case .todo-cell-badge { font-size: 0.58rem; }

        .shift-section-label { font-size: 0.7rem; font-weight: 600; color: #888; text-transform: uppercase; letter-spacing: 0.03em; margin: 14px 0 8px; }
        .shift-modal-columns { display: grid; grid-template-columns: 1fr 1fr; gap: 24px; align-items: start; }
        .shift-modal-col { display: flex; flex-direction: column; min-width: 0; }
        .shift-chip-group { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; }
        .shift-chip { display: flex; align-items: center; justify-content: center; padding: 9px 8px; border-radius: 8px; border: 1.5px solid #e2e8f0; background: #f8fafc; color: #555; font-size: 0.8rem; font-weight: 500; cursor: pointer; transition: 0.15s; font-family: 'Segoe UI'; }
        .shift-chip:hover { border-color: #cbd5e1; }
        /* "Journée" scindé en deux moitiés cliquables indépendamment (Journée / Jour férié), dans le même
           encombrement qu'un chip normal plutôt que d'ajouter une 5e case à la grille. */
        .shift-hours-row { display: flex; align-items: center; gap: 8px; }
        .shift-hours-btn { width: 36px; height: 36px; border-radius: 8px; border: 1.5px solid #e2e8f0; background: #f8fafc; font-size: 1.1rem; font-weight: 500; color: #555; cursor: pointer; flex-shrink: 0; }
        .shift-hours-btn:hover { border-color: #cbd5e1; }
        #shift-heures { flex: 1; text-align: center; font-size: 0.95rem; font-weight: 700; padding: 8px; border: 1.5px solid #e2e8f0; border-radius: 8px; font-family: 'Segoe UI'; }

        /* --- Remplissage rapide (plusieurs jours d'un coup) --- */
        #bulk-heures { flex: 1; text-align: center; font-size: 0.95rem; font-weight: 700; padding: 8px; border: 1.5px solid #e2e8f0; border-radius: 8px; font-family: 'Segoe UI'; }
        .bulk-date-row { display: flex; align-items: center; gap: 8px; margin-bottom: 10px; }
        .bulk-date-row input[type="date"] { flex: 1; padding: 8px; border: 1.5px solid #e2e8f0; border-radius: 8px; font-family: 'Segoe UI'; font-size: 0.82rem; }
        .bulk-date-row span { color: #999; font-size: 0.8rem; }
        .bulk-weekend-toggle { display: flex; align-items: center; gap: 6px; font-size: 0.78rem; color: #555; margin-bottom: 16px; cursor: pointer; }
        .bulk-weekend-toggle input { width: 16px; height: 16px; margin: 0; }
        .bulk-preview { font-size: 0.76rem; color: #666; font-weight: 600; background: #f8fafc; border-radius: 8px; padding: 8px 10px; margin-top: 14px; }
        .shift-objectif-box { background: #f8fafc; border-radius: 10px; padding: 14px 16px; margin-top: 16px; }
        .shift-objectif-header { display: flex; justify-content: space-between; align-items: baseline; font-size: 0.72rem; color: #666; font-weight: 600; margin-bottom: 10px; }
        .shift-objectif-header strong { font-size: 1.25rem; color: var(--primary); font-weight: 800; }
        .shift-objectif-track { position: relative; height: 12px; background: #e2e8f0; border-radius: 6px; margin-top: 6px; }
        .shift-objectif-fill { height: 100%; border-radius: 6px; background: linear-gradient(90deg, #3498db, var(--brand-green)); transition: width 0.35s ease; max-width: 100%; }
        .shift-objectif-fill.over { background: linear-gradient(90deg, var(--brand-orange), var(--danger)); }
        /* Repère "où j'en serais à 35h/semaine (temps plein légal), congés déjà posés inclus" — un simple
           trait vertical positionné en % sur la barre, pour comparer visuellement au remplissage réel. */
        .objectif-marker-35h { position: absolute; top: -3px; bottom: -3px; width: 2px; background: rgba(44, 62, 80, 0.75); z-index: 3; pointer-events: none; }
        .atc-track .objectif-marker-35h { top: 0; bottom: 0; width: 1.5px; background: rgba(44, 62, 80, 0.85); }
        .shift-objectif-legend { display: flex; flex-direction: column; gap: 3px; font-size: 0.68rem; color: #888; margin-top: 9px; font-weight: 600; }
        .shift-objectif-detail-stack { display: flex; flex-direction: column; gap: 3px; }
        .shift-objectif-pace { font-weight: 700; }
        .shift-objectif-pace.avance { color: var(--brand-green); }
        .shift-objectif-pace.retard { color: var(--danger); }

        /* --- PLANNING ANNUEL (vue calendrier complète) --- */
        .annual-top-row { display: flex; align-items: center; justify-content: flex-start; gap: 40px; flex-wrap: wrap; margin-bottom: 18px; padding-bottom: 14px; border-bottom: 1px solid #eee; }
        /* La légende ne doit occuper que sa largeur de contenu (confinée), pas s'étirer pour combler
           l'espace : c'est le bloc de droite (objectif/fractionnement/barre) qui doit respirer. */
        .annual-legend { display: flex; flex-direction: column; gap: 6px; flex: 0 0 auto; }
        /* Grille à 5 colonnes : les 9 postes/événements se répartissent toujours sur 2 lignes pile (5 puis 4),
           quelle que soit la largeur du modal — contrairement à un flex-wrap dont le nombre de lignes varie
           selon l'espace disponible. Les astreintes (2 items) vivent sur leur propre ligne en dessous. */
        .annual-legend-grid { display: grid; grid-template-columns: repeat(5, auto); gap: 6px 14px; }
        .annual-legend-row { display: flex; flex-wrap: wrap; gap: 6px 14px; }
        /* Une ligne par préoccupation (objectif / fractionnement / barre) plutôt qu'un seul flex-wrap : évite
           que l'apparition d'un contrôle (ex. fractionnement) ne fasse sauter tout le reste en vrac. Grandit
           pour occuper l'espace libéré par la légende désormais confinée à sa gauche.  */
        .annual-total-compact { display: flex; flex-direction: column; align-items: stretch; gap: 8px; flex: 1 1 320px; font-size: 0.68rem; color: #666; font-weight: 600; }
        .atc-row { display: flex; align-items: center; flex-wrap: wrap; gap: 8px; white-space: nowrap; }
        .atc-track { position: relative; flex: 1 1 200px; max-width: 360px; height: 8px; background: #e2e8f0; border-radius: 4px; overflow: hidden; }
        .atc-pct { font-size: 0.85rem; font-weight: 800; color: var(--primary); }
        /* "Fait" et "restant" sur la même ligne, dans deux petits encadrés distincts plutôt qu'empilés. */
        .atc-detail-chip { display: inline-flex; align-items: center; background: #eef1f5; border-radius: 6px; padding: 4px 9px; font-size: 0.66rem; color: #666; font-weight: 600; white-space: nowrap; }
        .atc-pace { font-weight: 700; }
        .atc-pace.avance { color: var(--brand-green); }
        .atc-pace.retard { color: var(--danger); }
        .atc-info-btn { background: none; border: none; color: #aaa; cursor: pointer; font-size: 0.85rem; padding: 0 2px; line-height: 1; }
        .atc-info-btn:hover { color: var(--primary); }

        /* --- Modale de détail "comprendre mon avance/retard" --- */
        .ecart-detail-summary { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 10px; margin-bottom: 18px; }
        .ecart-detail-tile { background: #f8fafc; border-radius: 10px; padding: 12px 14px; text-align: center; }
        .ecart-detail-tile .edt-label { font-size: 0.66rem; color: #888; font-weight: 700; text-transform: uppercase; letter-spacing: 0.03em; margin-bottom: 4px; }
        .ecart-detail-tile .edt-value { font-size: 1.15rem; font-weight: 800; color: var(--primary); }
        .ecart-detail-tile.avance .edt-value { color: var(--brand-green); }
        .ecart-detail-tile.retard .edt-value { color: var(--danger); }
        .ecart-detail-explain { background: #fff8e6; border-left: 4px solid var(--brand-orange); border-radius: 0 8px 8px 0; padding: 10px 14px; font-size: 0.8rem; color: #6b5200; line-height: 1.5; margin-bottom: 18px; }
        .ecart-detail-ajust { background: #eef1f5; border-left: 4px solid var(--primary); border-radius: 0 8px 8px 0; padding: 8px 14px; font-size: 0.76rem; color: #444; line-height: 1.5; margin-top: -10px; margin-bottom: 18px; }
        .ecart-mois-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(110px, 1fr)); gap: 8px; margin-bottom: 18px; }
        .ecart-mois-tile { background: #f8fafc; border-radius: 8px; padding: 8px 10px; text-align: center; }
        .ecart-mois-tile .emt-mois { font-size: 0.62rem; color: #888; font-weight: 700; text-transform: uppercase; margin-bottom: 3px; }
        .ecart-mois-tile .emt-val { font-size: 0.88rem; font-weight: 800; }
        .ecart-mois-tile.avance .emt-val { color: var(--brand-green); }
        .ecart-mois-tile.retard .emt-val { color: var(--danger); }
        .ecart-mois-tile.neutre .emt-val { color: #999; }
        .ecart-vides-list { max-height: 180px; overflow-y: auto; border: 1px solid #eee; border-radius: 8px; }
        .ecart-vides-row { display: flex; justify-content: space-between; padding: 7px 12px; font-size: 0.78rem; border-bottom: 1px solid #f2f2f2; }
        .ecart-vides-row:last-child { border-bottom: none; }
        .ecart-vides-row .evr-date { color: #444; font-weight: 600; }
        .ecart-vides-row .evr-du { color: var(--danger); font-weight: 700; }
        .ecart-vides-empty { text-align: center; color: #94a3b8; font-size: 0.82rem; font-style: italic; padding: 16px; }
        .edt-cumul-table-wrap { overflow-x: auto; border: 1px solid #eee; border-radius: 8px; margin-bottom: 18px; }
        .edt-cumul-table { width: 100%; table-layout: fixed; border-collapse: collapse; font-size: 0.78rem; }
        .edt-cumul-table th { text-align: right; background: #f8fafc; color: #888; font-weight: 700; text-transform: uppercase; font-size: 0.62rem; padding: 7px 6px; border-bottom: 1px solid #eee; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .edt-cumul-table th:first-child, .edt-cumul-table td:first-child { text-align: left; white-space: normal; }
        .edt-cumul-table td { text-align: right; padding: 7px 6px; border-bottom: 1px solid #f2f2f2; font-weight: 600; color: #444; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .edt-cumul-table tr:last-child td { border-bottom: none; }
        .edt-cumul-table td.avance { color: var(--brand-green); font-weight: 800; }
        .edt-cumul-table td.retard { color: var(--danger); font-weight: 800; }
        .edt-cumul-init-row td { background: #fbfbfb; font-style: italic; font-weight: 500; color: #888; white-space: normal; }
        .edt-cumul-init-row td:first-child { font-size: 0.72rem; }
        .edt-cumul-init-row td.avance { font-style: normal; }
        @media (max-width: 480px) {
            .edt-cumul-table { font-size: 0.66rem; }
            .edt-cumul-table th { font-size: 0.54rem; padding: 6px 3px; }
            .edt-cumul-table td { padding: 6px 3px; }
        }
        .atc-objectif-edit { display: inline-flex; align-items: center; gap: 4px; }
        .atc-objectif-edit input { width: 56px; padding: 3px 5px; border: 1.5px solid #e2e8f0; border-radius: 6px; font-size: 0.75rem; font-family: 'Segoe UI'; }
        .atc-objectif-edit button { background: none; border: 1.5px solid #e2e8f0; border-radius: 6px; width: 22px; height: 22px; cursor: pointer; font-size: 0.68rem; color: #555; flex-shrink: 0; }
        .atc-objectif-edit button:hover { border-color: #cbd5e1; }
        .atc-adjust-note { font-size: 0.62rem; color: #999; font-weight: 500; }

        /* Bloc "Total annuel" / "Fractionnement" : grille 2 colonnes pour que les deux champs s'alignent
           sur la même verticalité (colonne libellé, puis colonne contrôle), plutôt que deux lignes libres
           dont les contrôles tombent à des endroits différents. Une ligne entière disparaît proprement si
           ses deux cellules (label + contrôle, regroupées via display:contents) passent à display:none. */
        .atc-form-card { background: #f8fafc; border-radius: 8px; padding: 8px 10px; }
        /* 3 colonnes : période (fusionnée sur les 2 lignes, centrée verticalement) / libellé / contrôle. */
        .atc-form { display: grid; grid-template-columns: auto auto auto; grid-template-rows: auto auto; align-items: center; gap: 6px 10px; }
        .atc-form-periode { grid-row: 1 / span 2; grid-column: 1; align-self: center; font-size: 0.68rem; color: #999; font-weight: 600; white-space: nowrap; padding-right: 8px; border-right: 1px solid #e2e8f0; }
        .atc-form-row-group { display: contents; }
        .atc-form-label { font-size: 0.68rem; color: #666; font-weight: 600; white-space: nowrap; }
        .atc-form select { padding: 3px 4px; border: 1.5px solid #e2e8f0; border-radius: 6px; font-size: 0.72rem; font-family: 'Segoe UI'; color: #555; justify-self: start; }

        /* Badge d'alerte (plafond CP, etc.) avec bulle d'aide au survol — même mécanique que les bulles
           de note (shift-note-badge/bubble) mais en rouge pour signaler un dépassement conventionnel. */
        .rt-label-row { display: flex; align-items: center; gap: 4px; }
        .annual-alert-badge {
            display: inline-flex; align-items: center; justify-content: center;
            width: 15px; height: 15px; border-radius: 50%; flex-shrink: 0;
            background: #fdecea; border: 1.5px solid var(--danger);
            color: var(--danger); font-size: 0.6rem; font-weight: 800; cursor: help;
            position: relative;
        }
        .annual-alert-bubble {
            visibility: hidden; opacity: 0; position: absolute; z-index: 500;
            top: 135%; left: 0;
            background: #fff5f4; color: #7a1f16;
            text-align: left; padding: 8px 10px; border-radius: 6px 6px 6px 2px;
            width: 220px; box-sizing: border-box;
            white-space: normal; overflow-wrap: break-word; word-break: break-word;
            font-family: 'Segoe UI', sans-serif; font-weight: 500; font-size: 0.7rem; line-height: 1.35;
            border: 1px solid var(--danger);
            box-shadow: 3px 4px 8px rgba(0,0,0,0.22);
            transition: opacity 0.15s ease-in-out;
            pointer-events: none;
        }
        .annual-alert-bubble::after {
            content: ""; position: absolute; bottom: 100%; left: 4px;
            border-width: 0 7px 7px 0; border-style: solid;
            border-color: transparent #fff5f4 transparent transparent;
            filter: drop-shadow(-1px -2px 1px rgba(0,0,0,0.1));
        }
        .annual-alert-badge:hover .annual-alert-bubble { visibility: visible; opacity: 1; }
        .annual-legend-item { display: flex; align-items: center; gap: 6px; font-size: 0.7rem; color: #555; font-weight: 600; }
        .annual-legend-swatch { width: 12px; height: 12px; border-radius: 3px; flex-shrink: 0; }
        .annual-legend-swatch.astreinte { border-radius: 50%; }
        .annual-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(165px, 1fr)); gap: 14px; }
        /* --- ZOOM DU CALENDRIER ANNUEL ---
           Le pinch-zoom tactile est désactivé partout dans l'appli (voir viewport meta, user-scalable=no)
           pour éviter le bug de rotation/zoom cassé sur mobile — mais ce calendrier est dense (dates,
           postes, écarts en 0.52-0.68rem) et illisible sans zoom sur petit écran. On réintroduit un zoom
           ciblé ici via transform:scale sur #annual-grid ; #annual-grid-wrapper reçoit ensuite la taille
           réelle du contenu zoomé (voir zoomAnnuel, JS) pour rester défilable — transform ne modifie pas
           la taille de mise en page d'un élément, seul son rendu visuel, donc sans ce recalcul le
           conteneur ne saurait jamais qu'il doit proposer une barre de défilement plus grande. */
        .annual-calendar-label { display: flex; align-items: center; justify-content: space-between; gap: 10px; }
        .annual-zoom-controls { display: flex; align-items: center; gap: 6px; text-transform: none; letter-spacing: normal; }
        .annual-zoom-controls button { width: 26px; height: 26px; border: 1px solid #dcdfe3; background: #fff; color: var(--primary); border-radius: 6px; cursor: pointer; display: flex; align-items: center; justify-content: center; font-size: 0.7rem; }
        .annual-zoom-controls button:hover { background: var(--brand-green); border-color: var(--brand-green); color: #fff; }
        .annual-zoom-controls span { font-size: 0.68rem; font-weight: 700; color: #64748b; min-width: 34px; text-align: center; }
        .annual-grid-wrapper { overflow: auto; max-width: 100%; }
        #annual-grid { transform-origin: top left; transition: transform 0.15s ease; }

        /* --- VUE MENSUELLE MOBILE/TABLETTE DU PLANNING ANNUEL ---
           Sur petit écran (≤1024px, même seuil que ecranEtroit() ailleurs dans ce fichier), la grille
           dense de 12 mini-mois reste illisible même avec le zoom manuel ci-dessus. Par défaut on
           n'affiche donc plus qu'un seul mois à la fois, en très grand, avec navigation ← → ; un bouton
           bascule permet de repasser à la vue d'ensemble (grille identique au rendu desktop) si besoin.
           Sur desktop, rien ne change : cette barre reste masquée et la grille s'affiche comme avant. */
        .annual-mobile-nav { display: none; }
        @media screen and (max-width: 1024px) {
            /* Correctif : .modal-content (partagé par toutes les modales) reste en box-sizing:content-box
               avec 20px de padding de chaque côté — sa largeur déclarée ici (width: min(1200px, 95vw))
               ne les inclut donc pas, et la boîte réellement affichée déborde de ~40px hors de l'écran.
               On repasse en border-box uniquement pour cette modale plutôt que de toucher la règle
               globale .modal-content (qui sert à toutes les autres modales de l'appli). */
            #annualModal .modal-content { box-sizing: border-box; }

            /* Correctif : sur petit écran, .annual-legend a flex:0 0 auto (ne rétrécit jamais) et sa
               grille à 5 colonnes fixes (repeat(5, auto)) ne s'enroule donc contre aucune largeur
               réelle — ça forçait toute la modale plus large que l'écran, ce qui repoussait aussi le
               bouton bascule ci-dessous hors champ. On empile légende / total sur toute la largeur
               pour que la grille ait enfin une largeur contre laquelle s'enrouler. */
            .annual-top-row { flex-direction: column; align-items: stretch; gap: 14px; }
            .annual-legend { flex: 1 1 auto; width: 100%; }
            .annual-legend-grid { display: flex; flex-wrap: wrap; }

            .annual-mobile-nav {
                display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 8px;
                margin: 10px 0; padding: 8px 10px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px;
            }
            .annual-mobile-nav-months { display: flex; align-items: center; gap: 8px; min-width: 0; }
            .annual-mobile-nav.mode-global .annual-mobile-nav-months { display: none; }
            .annual-mobile-nav button { width: 34px; height: 34px; border: 1px solid #dcdfe3; background: #fff; color: var(--primary); border-radius: 8px; cursor: pointer; display: flex; align-items: center; justify-content: center; font-size: 0.9rem; flex-shrink: 0; }
            .annual-mobile-nav button:hover { background: var(--brand-green); border-color: var(--brand-green); color: #fff; }
            .annual-mobile-nav button:disabled { opacity: 0.35; cursor: default; }
            .annual-mobile-nav button:disabled:hover { background: #fff; border-color: #dcdfe3; color: var(--primary); }
            #annual-mnav-label { font-family: 'Caveat', cursive; font-size: 1.3rem; color: var(--primary); font-weight: 700; text-transform: capitalize; min-width: 0; text-align: center; white-space: nowrap; }
            .annual-mnav-toggle { width: auto !important; padding: 0 12px; gap: 6px; font-size: 0.72rem; font-weight: 700; flex-shrink: 0; }

            /* Vue mensuelle (par défaut sur mobile) : un seul mois affiché, en très grand */
            #annual-grid.mode-mensuel { display: block; }
            #annual-grid.mode-mensuel .annual-month-card { display: none; }
            #annual-grid.mode-mensuel .annual-month-card.is-active-month { display: flex; }
            #annual-grid.mode-mensuel .annual-month-header { font-size: 1.6rem; padding: 10px 0; }
            #annual-grid.mode-mensuel .annual-day-row { grid-template-columns: 1.1fr 0.8fr 0.7fr 1.3fr; gap: 6px; padding: 9px 12px; font-size: 1rem; margin-bottom: 4px; }
            #annual-grid.mode-mensuel .annual-day-abbr, #annual-grid.mode-mensuel .annual-day-abbr-extra { font-size: 0.78rem; }
            #annual-grid.mode-mensuel .annual-day-mini-badge { font-size: 0.76rem; padding: 2px 6px; }
            #annual-grid.mode-mensuel .annual-day-ecart { font-size: 0.7rem; padding: 2px 6px; }
            #annual-grid.mode-mensuel .annual-month-total,
            #annual-grid.mode-mensuel .annual-month-ecart,
            #annual-grid.mode-mensuel .annual-month-ecart-cumul { font-size: 0.85rem; padding: 10px 12px; }
        }
        .annual-month-card { display: flex; flex-direction: column; border: 1px solid #eee; border-radius: 10px; overflow: hidden; background: #fff; }
        .annual-month-header { background: var(--primary); color: #fff; font-family: 'Caveat', cursive; font-size: 1.2rem; text-align: center; padding: 4px 0; text-transform: capitalize; }
        .annual-month-body { padding: 4px; flex: 1 1 auto; }
        /* Colonne dédiée au badge de poste (Matin/AM/Nuit/Journée), séparée de celle des badges secondaires
           (Jour férié/demi-congé) : chacune a sa propre largeur fixe, donc le badge de poste reste toujours
           centré à la même position d'un jour à l'autre, que le badge secondaire soit présent ou non — sinon
           un badge en plus décale tout bloc unique centré et désaligne "Nuit" entre deux jours. */
        .annual-day-row { display: grid; grid-template-columns: 0.8fr 0.62fr 0.48fr 1.3fr; align-items: center; gap: 2px; padding: 2px 6px; border-radius: 4px; font-size: 0.68rem; margin-bottom: 1px; position: relative; cursor: pointer; }
        .annual-day-row:hover { outline: 2px solid var(--accent); outline-offset: -1px; }
        .annual-day-date { font-weight: 600; color: #444; text-align: left; }
        .annual-day-abbr, .annual-day-abbr-extra { font-weight: 500; font-size: 0.6rem; letter-spacing: 0.02em; opacity: 0.75; text-align: center; white-space: nowrap; }
        /* white-space:nowrap + le heures/écart doivent tenir sur une seule ligne, quitte à rétrécir
           légèrement le texte plutôt que de retomber à la ligne (voir .annual-day-ecart ci-dessous). */
        .annual-day-heures { display: flex; align-items: center; justify-content: flex-end; gap: 3px; font-weight: 700; color: var(--primary); text-align: right; white-space: nowrap; }
        .annual-day-astreinte-dot { position: absolute; left: -3px; top: 50%; transform: translateY(-50%); width: 6px; height: 6px; border-radius: 50%; }
        .annual-day-mini-badge { display: inline-block; padding: 0 3px; border-radius: 3px; color: #fff; font-size: 0.58rem; font-weight: 700; line-height: 1.4; vertical-align: middle; white-space: nowrap; }
        /* Jour férié détecté automatiquement (aucune ligne enregistrée) : même badge, mais en pointillé et
           semi-transparent pour rester visuellement distinct d'un jour férié réellement saisi. */
        .annual-day-mini-badge-auto { opacity: 0.6; outline: 1px dashed rgba(255,255,255,0.7); outline-offset: -1px; }
        .annual-month-total { display: flex; justify-content: space-between; padding: 6px 8px; background: #f8fafc; font-size: 0.68rem; font-weight: 700; color: var(--primary); border-top: 1px solid #eee; }
        /* Écart du jour (heures faites − heures dues) et récap mensuel : vert = avance, rouge = retard.
           Fond plein + texte blanc plutôt que texte teinté sur fond pâle : beaucoup plus lisible en petit. */
        .annual-day-heures-txt { flex-shrink: 0; }
        .annual-day-ecart { flex-shrink: 0; display: inline-block; padding: 0 3px; border-radius: 3px; font-size: 0.52rem; font-weight: 600; line-height: 1.4; white-space: nowrap; }
        .annual-day-ecart.avance { color: #fff; background: #219150; }
        .annual-day-ecart.retard { color: #fff; background: var(--danger); }
        .annual-month-ecart { display: flex; justify-content: space-between; padding: 5px 8px; background: #fff; font-size: 0.62rem; font-weight: 700; border-top: 1px dashed #e2e8f0; }
        .annual-month-ecart.avance { color: #219150; }
        .annual-month-ecart.retard { color: var(--danger); }
        .annual-month-ecart-cumul { display: flex; justify-content: space-between; align-items: center; padding: 5px 8px; background: #f8fafc; font-size: 0.62rem; font-weight: 700; border-top: 1px solid #e2e8f0; }
        .annual-month-ecart-cumul.avance { color: #219150; }
        .annual-month-ecart-cumul.retard { color: var(--danger); }
        .annual-cumul-info-btn { margin-left: 4px; color: #94a3b8; cursor: pointer; font-size: 0.72rem; }
        .annual-cumul-info-btn:hover { color: var(--primary); }
        .annual-recap-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(115px, 1fr)); gap: 7px; margin-top: 6px; }
        .annual-recap-tile { background: #f8fafc; border-radius: 8px; padding: 6px 9px; border-left: 3px solid var(--c, #ccc); }
        .annual-recap-tile .rt-label { font-size: 0.58rem; font-weight: 700; color: #666; text-transform: uppercase; letter-spacing: 0.02em; margin-bottom: 3px; }
        .annual-recap-tile .rt-value { font-size: 0.92rem; font-weight: 800; color: var(--primary); }
        .annual-recap-tile .rt-value small { font-size: 0.62rem; font-weight: 600; color: #999; }
        .annual-recap-tile .rt-bar-track { height: 4px; background: #e2e8f0; border-radius: 2px; margin-top: 4px; overflow: hidden; }
        .annual-recap-tile .rt-bar-fill { height: 100%; border-radius: 2px; background: var(--c, #ccc); }
        .annual-recap-tile .rt-pct { font-size: 0.58rem; font-weight: 700; color: var(--ctext, var(--c, #888)); margin-top: 2px; }
        .annual-recap-tile .rt-note { font-size: 0.56rem; color: #888; font-weight: 500; margin-top: 3px; line-height: 1.3; }
        .btn-shift-clear { flex: 1; background: none; border: 1.5px solid #e2e8f0; color: var(--danger); border-radius: 6px; cursor: pointer; font-weight: 500; font-size: 0.8rem; }
        .btn-shift-clear:hover { background: #fdf1f1; border-color: var(--danger); }
        #shiftModal .btn-save { font-weight: 500; }
        /* Le bouton Enregistrer/Effacer est en toute fin de .modal-content (qui défile) : sur téléphone,
           la barre de gestes/navigation du système peut recouvrir cette fin de contenu, surtout que
           100vh ne tient pas toujours compte de cette barre. env(safe-area-inset-bottom) (voir aussi
           viewport-fit=cover dans la balise viewport) ajoute la marge réelle laissée par le système ; le
           max(20px, ...) garantit un minimum même sur les navigateurs qui ignorent cette variable. */
        #shiftModal .modal-content { padding-bottom: max(20px, env(safe-area-inset-bottom, 20px)); }

        .shift-bi-row { display: flex; align-items: center; gap: 10px; background: #f8fafc; border: 1px solid #e2e8f0; border-left: 4px solid var(--accent); border-radius: 8px; padding: 8px 10px; cursor: pointer; transition: 0.15s; text-align: left; font-family: 'Segoe UI'; }
        .shift-bi-row:hover { background: #f1f5f9; transform: translateX(2px); }
        .shift-bi-num { font-weight: 600; font-size: 0.7rem; color: #d35400; background: #fef5e7; border: 1px solid #f9e79f; border-radius: 4px; padding: 2px 6px; flex-shrink: 0; }
        .shift-bi-info { flex: 1; min-width: 0; }
        .shift-bi-equip { font-weight: 600; font-size: 0.82rem; color: var(--primary); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .shift-bi-desc { font-size: 0.72rem; color: #64748b; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; font-style: italic; }
        .shift-bi-statut { font-size: 0.6rem; font-weight: 600; text-transform: uppercase; color: white; padding: 3px 8px; border-radius: 10px; flex-shrink: 0; }
        .shift-bi-empty { text-align: center; color: #94a3b8; font-size: 0.78rem; font-style: italic; padding: 10px 0 16px; }

        /* --- SELECTEUR DE VUE : Jour / Semaine / Mois ---
           Meme habillage "verre depoli" que .user-badge/.btn-accueil dans le header : sur fond blanc plein
           (v1), le selecteur se voyait mal sur la photo en arriere-plan du bandeau. */
        .mode-vue-toggle { display: flex; background: rgba(255,255,255,0.16); backdrop-filter: blur(14px) brightness(1.15); -webkit-backdrop-filter: blur(14px) brightness(1.15); border: 1px solid rgba(255,255,255,0.45); box-shadow: 0 6px 16px rgba(0,0,0,0.2); border-radius: 18px; padding: 4px; gap: 2px; }
        .mode-vue-btn { border: none; background: none; padding: 7px 16px; border-radius: 14px; font-family: 'Segoe UI', sans-serif; font-size: 0.8rem; font-weight: 700; color: #fff; text-shadow: 0 1px 3px rgba(0,0,0,0.4); cursor: pointer; transition: 0.15s; }
        .mode-vue-btn:hover { background: rgba(255,255,255,0.18); }
        .mode-vue-btn.active { background: #fff; color: var(--primary); text-shadow: none; box-shadow: 0 2px 6px rgba(0,0,0,0.2); }
        @media screen and (max-width: 1024px) { .mode-vue-toggle { display: none; } }

        /* Vue "Jour" explicite (bouton du selecteur, pas seulement le mode mobile automatique) : la regle
           .mode-jour existante ne s'appliquait qu'en dessous de 1024px (voir plus haut) ; on la rend aussi
           active en plein ecran quand ce mode a ete choisi volontairement. */
        @media screen and (min-width: 1025px) {
            .planning-container.mode-jour { grid-template-columns: 200px 1fr; }
            /* La case du jour prend toute la largeur restante : sans ceci, les bons d'intervention
               (.task-badge-compact, width:100% par defaut car pense pour une colonne etroite en vue
               Semaine) s'etirent demesurement sur toute cette largeur. */
            .planning-container.mode-jour .task-badge-compact { width: fit-content; max-width: 150px; }
            /* #daySwitcher (pastilles Lun..Dim) s'affiche AU-DESSUS de #planningTable, dans le flux normal —
               sa hauteur (~46px) n'etait pas deduite de celle, fixe, du tableau (calc(100vh - 102px) : voir
               .planning-container plus haut), qui debordait donc d'autant sous le bas de l'ecran. Body ayant
               overflow:hidden, ce debordement etait invisible : la derniere ligne technicien de la liste
               semblait coupee. */
            #daySwitcher.show ~ #planningTable { height: calc(100vh - 102px - 47px); }
        }

        /* Le bandeau "Semaine XX / DU ... AU ..." (.week-nav-center) etait centre par justify-content:
           space-between entre le bouton accueil (etroit) et le groupe de droite (langue + selecteur de vue +
           badge utilisateur) — desormais plus large avec le selecteur Jour/Semaine/Mois, ce qui le decalait
           visiblement du vrai centre. On le centre donc par rapport a la fenetre plutot que par rapport a ses
           voisins. */
        @media screen and (min-width: 1025px) {
            .header-top { position: relative; }
            .week-nav-center { position: absolute; left: 50%; top: 50%; transform: translate(-50%, -50%); }
        }

        /* --- VUE MOIS --- */
        .mois-container { display: none; margin: 0 4px 4px 4px; background: rgba(255, 255, 255, 0.85); border-radius: 8px; border: 1px solid rgba(0,0,0,0.1); backdrop-filter: blur(3px); height: calc(100vh - 102px); overflow-y: auto; flex-direction: column; }
        .mois-container.show { display: flex; }
        .mois-toolbar { display: flex; align-items: center; justify-content: space-between; padding: 12px 16px; border-bottom: 1px solid #eef0f2; flex-wrap: wrap; gap: 10px; }
        /* Menu deroulant "technicien" maison (le <select> natif ne se stylait pas assez pour un rendu soigne :
           pas moyen d'afficher un avatar par ligne dans sa liste). */
        .tech-dropdown { position: relative; }
        .tech-dropdown-trigger { display: flex; align-items: center; gap: 10px; background: #fff; border: 1.5px solid #e2e8f0; border-radius: 12px; padding: 6px 14px 6px 6px; cursor: pointer; box-shadow: 0 2px 6px rgba(0,0,0,0.06); transition: 0.15s; font-family: 'Segoe UI'; min-width: 230px; text-align: left; }
        .tech-dropdown-trigger:hover { border-color: var(--brand-green); box-shadow: 0 4px 12px rgba(0,0,0,0.12); }
        .tech-dropdown.open .tech-dropdown-trigger { border-color: var(--brand-green); box-shadow: 0 0 0 3px rgba(46,204,113,0.15); }
        .tech-dropdown-avatar { width: 36px; height: 36px; border-radius: 50%; overflow: hidden; flex-shrink: 0; background: #dfe6ec; box-shadow: 0 0 0 1px #e2e8f0; }
        .tech-dropdown-avatar img { width: 100%; height: 100%; object-fit: cover; display: block; }
        .tech-dropdown-info { display: flex; flex-direction: column; flex: 1; min-width: 0; }
        .tech-dropdown-eyebrow { font-size: 0.6rem; font-weight: 800; color: #94a3b8; text-transform: uppercase; letter-spacing: 0.05em; }
        .tech-dropdown-name { font-size: 0.92rem; font-weight: 700; color: var(--primary); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .tech-dropdown-chevron { color: #94a3b8; font-size: 0.75rem; transition: transform 0.2s; flex-shrink: 0; margin-right: 2px; }
        .tech-dropdown.open .tech-dropdown-chevron { transform: rotate(180deg); color: var(--brand-green); }

        .tech-dropdown-panel { position: absolute; top: calc(100% + 8px); left: 0; min-width: 260px; background: #fff; border-radius: 12px; box-shadow: 0 14px 34px rgba(0,0,0,0.2); border: 1px solid rgba(0,0,0,0.06); padding: 6px; z-index: 500; opacity: 0; visibility: hidden; transform: translateY(-8px); transition: 0.16s ease; max-height: 320px; overflow-y: auto; }
        .tech-dropdown.open .tech-dropdown-panel { opacity: 1; visibility: visible; transform: translateY(0); }
        .tech-dropdown-item { display: flex; align-items: center; gap: 10px; padding: 8px 10px; border-radius: 8px; cursor: pointer; transition: 0.12s; }
        .tech-dropdown-item:hover { background: #f1f5f9; }
        .tech-dropdown-item.selected { background: rgba(46, 204, 113, 0.1); }
        .tech-dropdown-item-avatar { width: 30px; height: 30px; border-radius: 50%; overflow: hidden; flex-shrink: 0; background: #dfe6ec; }
        .tech-dropdown-item-avatar img { width: 100%; height: 100%; object-fit: cover; display: block; }
        .tech-dropdown-item-name { flex: 1; font-size: 0.84rem; font-weight: 600; color: var(--primary); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .tech-dropdown-item.selected .tech-dropdown-item-name { color: #196f3d; font-weight: 700; }
        .tech-dropdown-item-check { color: var(--brand-green); font-size: 0.8rem; opacity: 0; flex-shrink: 0; }
        .tech-dropdown-item.selected .tech-dropdown-item-check { opacity: 1; }
        .mois-grid { display: grid; grid-template-columns: repeat(7, minmax(0, 1fr)); gap: 4px; padding: 12px 16px 20px; }
        .mois-jour-nom { text-align: center; font-size: 0.7rem; font-weight: 700; color: #8a94a0; padding-bottom: 4px; }
        .mois-case { min-height: 86px; border-radius: 8px; background: #f8fafc; border: 1px solid #eef0f2; padding: 5px 6px; cursor: pointer; transition: 0.15s; display: flex; flex-direction: column; gap: 4px; }
        .mois-case:hover { border-color: #cbd5e1; background: #f1f5f9; }
        .mois-case.hors-mois { background: transparent; border-color: transparent; cursor: default; }
        .mois-case.aujourdhui { border-color: var(--accent); border-width: 2px; }
        .mois-case.drag-over { background: rgba(46, 204, 113, 0.2) !important; border: 2px dashed var(--brand-green); }
        /* Bons d'intervention du jour (memes badges/couleurs que la vue Semaine, voir .task-badge-compact) :
           en colonne etroite ici, donc pas question qu'ils s'etirent a 100% comme dans une case de semaine. */
        .mois-case-tasks { display: flex; flex-wrap: wrap; gap: 2px; }
        .mois-case .task-badge-compact { width: fit-content; max-width: 100%; font-size: 0.56rem; padding: 1px 4px; }
        .mois-case-num { font-size: 0.72rem; font-weight: 700; color: var(--primary); }
        .mois-case.hors-mois .mois-case-num { color: #d0d5db; }
        /* Meme badge "contour colore / fond blanc" que la vue Semaine (.shift-badge-compact, reutilise tel
           quel ci-dessous) plutot qu'un pave de couleur pleine : juste une taille un peu plus lisible et une
           troncature pour les libelles longs, vu que la case du mois est plus etroite qu'une case semaine. */
        .mois-case .shift-badge-compact { align-self: flex-start; max-width: 100%; font-size: 0.6rem; padding: 2px 5px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; display: block; }
        @media screen and (max-width: 1024px) { .mois-container { display: none !important; } }
    </style>
<?php include 'pwa_head.php'; ?>
</head>
<body onclick="checkCloseSidebar(event)">

<div class="side-nav-btn side-btn-left" onclick="changeWeek(-1)"><i class="fa-solid fa-chevron-left"></i></div>
<div class="side-nav-btn side-btn-right" onclick="changeWeek(1)"><i class="fa-solid fa-chevron-right"></i></div>

<div id="moisSideLeft" class="side-nav-btn side-nav-btn-mois side-btn-left" style="display:none;" onclick="changerMois(-1)"><i class="fa-solid fa-chevron-left"></i></div>
<div id="moisSideRight" class="side-nav-btn side-nav-btn-mois side-btn-right" style="display:none;" onclick="changerMois(1)"><i class="fa-solid fa-chevron-right"></i></div>

<div id="edge-left" class="edge-nav edge-left"></div>
<div id="edge-right" class="edge-nav edge-right"></div>

<div id="week-indicator"><?php echo htmlspecialchars(t('planning.planning_word')); ?> <span><?php echo htmlspecialchars(t('planning.semaine')); ?></span></div>

<?php include 'navbar_planning.php'; ?>

<div id="techSwitcher" class="tech-switcher">
    <div class="tech-switcher-icon"><i class="fa-solid fa-user"></i></div>
    <select id="techSwitcherSelect" onchange="selectionnerTech(this.value)"></select>
</div>
<div id="daySwitcher" class="day-switcher">
    <button type="button" class="day-switcher-arrow" onclick="jourAdjacent(-1)" title="<?php echo htmlspecialchars(t('planning.jour_precedent')); ?>" aria-label="<?php echo htmlspecialchars(t('planning.jour_precedent')); ?>"><i class="fa-solid fa-chevron-left"></i></button>
    <div id="daySwitcherPills" class="day-switcher-pills"></div>
    <button type="button" class="day-switcher-arrow" onclick="jourAdjacent(1)" title="<?php echo htmlspecialchars(t('planning.jour_suivant')); ?>" aria-label="<?php echo htmlspecialchars(t('planning.jour_suivant')); ?>"><i class="fa-solid fa-chevron-right"></i></button>
</div>
<div id="planningTable" class="planning-container"></div>
<div id="planningDayCards" class="planning-day-cards"></div>

<div id="moisContainer" class="mois-container">
    <div class="mois-toolbar">
        <div class="tech-dropdown" id="techDropdown">
            <button type="button" class="tech-dropdown-trigger" onclick="toggleTechDropdown()">
                <div class="tech-dropdown-avatar"><img id="moisTechAvatar" src="img/user.png" alt=""></div>
                <div class="tech-dropdown-info">
                    <span class="tech-dropdown-eyebrow"><?php echo htmlspecialchars(t('planning.role_technicien')); ?></span>
                    <span class="tech-dropdown-name" id="moisTechName">--</span>
                </div>
                <i class="fa-solid fa-chevron-down tech-dropdown-chevron"></i>
            </button>
            <div class="tech-dropdown-panel" id="techDropdownPanel"></div>
        </div>
    </div>
    <div class="mois-grid" id="moisGrid"></div>
</div>

<div id="editModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <span><?php echo htmlspecialchars(t('planning.modal_saisir_intervention')); ?></span>
            <?php if($is_admin): ?>
            <i class="fa-solid fa-trash-can btn-del" onclick="deleteTask()" title="<?php echo htmlspecialchars(t('planning.tooltip_supprimer')); ?>"></i>
            <?php endif; ?>
        </div>
        <input type="hidden" id="m-id">
        <div class="m-grid">
            <div class="m-field" style="grid-column: 1 / -1; background: #f8fafc; padding: 10px; border-radius: 6px; border: 1px dashed #cbd5e1; display: flex; flex-direction: row; align-items: center; gap: 10px;">
                <input type="checkbox" id="m-is-st" onchange="toggleST()" style="width: 18px; height: 18px; margin: 0;">
                <label for="m-is-st" style="color: var(--primary); font-size: 0.85rem; cursor: pointer; font-weight: bold;"><?php echo htmlspecialchars(t('planning.label_sous_traitee')); ?></label>
            </div>

            <div class="m-field" id="box-ee" style="display: none; grid-column: 1 / -1; background: #fff5e6; padding: 10px; border-radius: 6px; border-left: 4px solid var(--brand-orange); box-sizing: border-box;">
                <span style="color: var(--brand-orange); font-weight: 800; font-size: 0.7rem; margin-bottom: 4px;"><?php echo htmlspecialchars(t('planning.label_entreprise_ext')); ?></span>
                <select id="m-entreprise" style="padding: 8px; border: 1px solid #ccc; border-radius: 5px; font-family: 'Segoe UI'; width: 100%; background: white;"></select>
            </div>

            <div class="m-field">
                <span><?php echo htmlspecialchars(t('planning.label_demandeur')); ?></span>
                <select id="m-demandeur"></select>
            </div>

            <div class="m-field" id="box-tech">
                <span><?php echo htmlspecialchars(t('planning.label_technicien_interne')); ?></span>
                <select id="m-tech"></select>
            </div>

            <div class="m-field" id="container-usine">
                <span><?php echo htmlspecialchars(t('planning.label_lieu_usine')); ?></span>
                <select id="m-usine" onchange="updateSecteurs()"><option value=""><?php echo htmlspecialchars(t('planning.select_defaut')); ?></option></select>
            </div>
            <div class="m-field" id="container-secteur">
                <span><?php echo htmlspecialchars(t('planning.label_secteur')); ?></span>
                <select id="m-secteur" onchange="updateZones()" disabled><option value=""><?php echo htmlspecialchars(t('planning.select_en_attente')); ?></option></select>
            </div>
            <div class="m-field" id="container-zone">
                <span><?php echo htmlspecialchars(t('planning.label_zone')); ?></span>
                <select id="m-zone" onchange="updateMachines()" disabled><option value=""><?php echo htmlspecialchars(t('planning.select_en_attente')); ?></option></select>
            </div>
            <div class="m-field" id="container-equip">
                <span><?php echo htmlspecialchars(t('planning.label_machine')); ?></span>
                <select id="m-equip" disabled><option value=""><?php echo htmlspecialchars(t('planning.select_en_attente')); ?></option></select>
            </div>

            <div class="m-field"><?php echo htmlspecialchars(t('planning.label_date')); ?> <input type="date" id="m-date"></div>
            <div class="m-field"><?php echo htmlspecialchars(t('planning.label_temps_prevu')); ?> <input type="number" step="0.25" id="m-hours" placeholder="<?php echo htmlspecialchars(t('planning.placeholder_temps')); ?>"></div>
            <div class="m-field"><?php echo htmlspecialchars(t('planning.label_priorite')); ?> <select id="m-prio"><option value="Normal"><?php echo htmlspecialchars(t('maint.lib_normal')); ?></option><option value="Urgent"><?php echo htmlspecialchars(t('maint.lib_urgent')); ?></option></select></div>
            <div class="m-field"><?php echo htmlspecialchars(t('planning.label_statut')); ?> <select id="m-statut"><option value="À faire"><?php echo htmlspecialchars(t('maint.lib_afaire')); ?></option><option value="En cours"><?php echo htmlspecialchars(t('maint.lib_encours')); ?></option><option value="Terminée"><?php echo htmlspecialchars(t('maint.lib_termine')); ?></option></select></div>
            <div class="m-field"><?php echo htmlspecialchars(t('planning.label_type')); ?> <select id="m-type"><option value="Curatif"><?php echo htmlspecialchars(t('maint.type_curatif')); ?></option><option value="Préventif"><?php echo htmlspecialchars(t('maint.type_preventif')); ?></option><option value="Chantier"><?php echo htmlspecialchars(t('maint.type_chantier')); ?></option></select></div>

            <div class="m-field" style="justify-content: center; align-items: center; flex-direction: row; gap: 8px;">
                <input type="checkbox" id="m-casse" style="width: 18px; height: 18px; margin: 0;">
                <label for="m-casse" style="color: var(--danger); font-size: 0.65rem; cursor: pointer; font-weight: 900;"><?php echo htmlspecialchars(t('planning.label_casse')); ?></label>
            </div>
        </div>
        <div class="m-field" style="margin-top:10px;"><?php echo htmlspecialchars(t('planning.label_description')); ?> <textarea id="m-desc" rows="3"></textarea></div>
        <button class="btn-save" onclick="saveTask()"><?php echo htmlspecialchars(t('planning.btn_enregistrer')); ?></button>
        <button onclick="closeModal()" style="width:100%; background:none; border:none; color:#888; cursor:pointer; margin-top:10px; font-weight:700;"><?php echo htmlspecialchars(t('planning.btn_annuler')); ?></button>
    </div>
</div>

<div id="shiftModal" class="modal">
    <div class="modal-content" style="width: min(820px, 95vw); max-height: calc(100vh - 40px); max-height: calc(100dvh - 40px); margin: 20px auto; overflow-y: auto; box-sizing: border-box;">
        <div class="modal-header">
            <span id="shift-modal-title"><?php echo htmlspecialchars(t('planning.modal_planifier_default')); ?></span>
            <i class="fa-solid fa-xmark btn-del" onclick="closeShiftModal()" title="<?php echo htmlspecialchars(t('planning.tooltip_fermer')); ?>"></i>
        </div>
        <p id="shift-modal-date" style="margin:-10px 0 15px; color:#777; font-size:0.85rem; font-weight:400;"></p>

        <div class="shift-modal-columns">
            <div class="shift-modal-col">
                <div id="shift-bi-section">
                    <div class="shift-section-label"><?php echo htmlspecialchars(t('planning.label_bi_du_jour')); ?></div>
                    <div id="shift-bi-list" style="display:flex; flex-direction:column; gap:8px; margin-bottom:16px; max-height:180px; overflow-y:auto;"></div>
                </div>

                <div class="shift-section-label"><?php echo htmlspecialchars(t('planning.label_poste')); ?></div>
                <div id="shift-poste-group" class="shift-chip-group"></div>

                <div class="shift-section-label"><?php echo htmlspecialchars(t('planning.label_evenement')); ?></div>
                <div id="shift-evenement-group" class="shift-chip-group"></div>

                <div class="shift-section-label"><?php echo htmlspecialchars(t('planning.label_astreinte')); ?></div>
                <div id="shift-astreinte-group" class="shift-chip-group"></div>
            </div>

            <div class="shift-modal-col">
                <div class="shift-section-label"><?php echo htmlspecialchars(t('planning.label_heures_effectuees')); ?></div>
                <div class="shift-hours-row">
                    <button type="button" class="shift-hours-btn" onclick="adjustShiftHours(-0.5)">−</button>
                    <input type="number" id="shift-heures" step="0.5" min="0" max="24" placeholder="0">
                    <button type="button" class="shift-hours-btn" onclick="adjustShiftHours(0.5)">+</button>
                </div>

                <div id="shift-note-section">
                    <div class="shift-section-label"><?php echo htmlspecialchars(t('planning.label_note')); ?> <span style="text-transform:none; font-weight:400; color:#999;"><?php echo htmlspecialchars(t('planning.hint_note')); ?></span></div>
                    <textarea id="shift-note" rows="2" maxlength="255" placeholder="<?php echo htmlspecialchars(t('planning.placeholder_note')); ?>" style="width:100%; box-sizing:border-box; padding:8px; border:1.5px solid #e2e8f0; border-radius:8px; font-family:'Segoe UI'; font-size:0.82rem; resize:vertical;"></textarea>
                </div>
                <p id="shift-note-hidden-msg" style="display:none; margin:0 0 10px; font-size:0.78rem; color:#999; font-style:italic;"><i class="fa-solid fa-lock"></i> <?php echo htmlspecialchars(t('planning.note_masquee')); ?></p>

                <div class="shift-objectif-box">
                    <div class="shift-objectif-header">
                        <span><?php echo htmlspecialchars(t('planning.objectif_annualise')); ?> <span id="shift-total-periode"></span></span>
                        <strong id="shift-objectif-pct">0%</strong>
                    </div>
                    <div class="shift-objectif-track">
                        <div class="shift-objectif-fill" id="shift-objectif-fill"></div>
                        <div class="objectif-marker-35h" id="shift-objectif-marker-35h"></div>
                    </div>
                    <div class="shift-objectif-legend">
                        <div class="shift-objectif-detail-stack">
                            <span id="shift-objectif-fait"><?php echo htmlspecialchars(str_replace(['{fait}', '{objectif}'], ['0', '0'], t('planning.h_faites_sur'))); ?></span>
                            <span id="shift-objectif-detail"><?php echo htmlspecialchars(str_replace(['{n}', '{objectif}'], ['0', '0'], t('planning.h_restantes_sur'))); ?></span>
                        </div>
                        <span id="shift-objectif-pace"></span>
                    </div>
                </div>
            </div>
        </div>

        <button type="button" id="btn-voir-planning-annuel" onclick="openAnnualModal(shiftTech)" style="width:100%; margin-top:10px; background:none; border:1.5px dashed #cbd5e1; color:var(--primary); border-radius:8px; padding:9px; font-weight:600; font-size:0.78rem; cursor:pointer; font-family:inherit;"><i class="fa-solid fa-calendar-days"></i> <?php echo htmlspecialchars(t('planning.btn_voir_planning_annuel')); ?></button>
        <button type="button" onclick="openBulkModal()" style="width:100%; margin-top:8px; background:none; border:1.5px dashed #cbd5e1; color:var(--primary); border-radius:8px; padding:9px; font-weight:600; font-size:0.78rem; cursor:pointer; font-family:inherit;"><i class="fa-solid fa-layer-group"></i> <?php echo htmlspecialchars(t('planning.btn_remplir_plusieurs_jours')); ?></button>

        <div style="display:flex; gap:8px; margin-top:16px;">
            <button type="button" class="btn-shift-clear" onclick="clearShift()"><i class="fa-solid fa-eraser"></i> <?php echo htmlspecialchars(t('planning.btn_effacer')); ?></button>
            <button type="button" class="btn-save" style="margin-top:0;" onclick="saveShift()"><?php echo htmlspecialchars(t('planning.btn_enregistrer_min')); ?></button>
        </div>
    </div>
</div>

<div id="choixCaseModal" class="modal">
    <div class="modal-content" style="width: min(420px, 92vw); margin: 12vh auto; box-sizing: border-box;">
        <div class="modal-header">
            <span><?php echo htmlspecialchars(t('planning.choix_titre')); ?></span>
            <i class="fa-solid fa-xmark btn-del" onclick="closeChoixCase()" title="<?php echo htmlspecialchars(t('planning.tooltip_fermer')); ?>"></i>
        </div>
        <p id="choix-case-sous-titre" style="margin:-10px 0 15px; color:#777; font-size:0.85rem; font-weight:400;"></p>
        <div style="display:flex; flex-direction:column; gap:12px;">
            <button type="button" class="choix-case-btn choix-case-btn-heures" onclick="choisirPlanifierHeures()">
                <i class="fa-solid fa-clock"></i>
                <span><?php echo htmlspecialchars(t('planning.choix_planifier_heures')); ?></span>
            </button>
            <button type="button" class="choix-case-btn choix-case-btn-bi" onclick="choisirCreerBI()">
                <i class="fa-solid fa-file-circle-plus"></i>
                <span><?php echo htmlspecialchars(t('planning.choix_creer_bi')); ?></span>
            </button>
            <button type="button" class="choix-case-btn choix-case-btn-todo" onclick="choisirTodoList()">
                <i class="fa-solid fa-list-check"></i>
                <span><?php echo htmlspecialchars(t('planning.choix_todo_list')); ?></span>
            </button>
        </div>
    </div>
</div>

<div id="todoModal" class="modal">
    <div class="modal-content" style="width: min(440px, 92vw); max-height: calc(90vh - 40px); margin: 5vh auto; overflow-y: auto; box-sizing: border-box;">
        <div class="modal-header">
            <span id="todo-modal-titre"><?php echo htmlspecialchars(t('planning.choix_todo_list')); ?></span>
            <i class="fa-solid fa-xmark btn-del" onclick="closeTodoModal()" title="<?php echo htmlspecialchars(t('planning.tooltip_fermer')); ?>"></i>
        </div>
        <p id="todo-modal-date" style="margin:-10px 0 15px; color:#777; font-size:0.85rem; font-weight:400;"></p>
        <div id="todo-items-list" style="display:flex; flex-direction:column; gap:8px; margin-bottom:14px;"></div>
        <div style="display:flex; gap:8px;">
            <input type="text" id="todo-new-texte" maxlength="255" placeholder="<?php echo htmlspecialchars(t('planning.todo_placeholder')); ?>" style="flex:1; box-sizing:border-box; padding:9px 10px; border:1.5px solid #e2e8f0; border-radius:8px; font-family:'Segoe UI'; font-size:0.85rem;" onkeydown="if(event.key==='Enter'){ajouterTodoItem();}">
            <button type="button" class="choix-case-btn choix-case-btn-todo" style="width:auto; padding:9px 16px;" onclick="ajouterTodoItem()"><i class="fa-solid fa-plus"></i></button>
        </div>
    </div>
</div>

<div id="bulkModal" class="modal" style="z-index: 10600;">
    <div class="modal-content" style="width: min(480px, 92vw); max-height: calc(96vh - 40px); margin: 3vh auto; overflow-y: auto; box-sizing: border-box;">
        <div class="modal-header">
            <span id="bulk-modal-title"><?php echo htmlspecialchars(t('planning.modal_remplissage_rapide')); ?></span>
            <i class="fa-solid fa-xmark btn-del" onclick="closeBulkModal()" title="<?php echo htmlspecialchars(t('planning.tooltip_fermer')); ?>"></i>
        </div>

        <div class="shift-section-label"><?php echo htmlspecialchars(t('planning.label_periode')); ?></div>
        <div class="bulk-date-row">
            <input type="date" id="bulk-date-debut">
            <span><?php echo htmlspecialchars(t('planning.au_connector')); ?></span>
            <input type="date" id="bulk-date-fin">
        </div>
        <label class="bulk-weekend-toggle">
            <input type="checkbox" id="bulk-exclure-weekend" checked>
            <?php echo htmlspecialchars(t('planning.label_exclure_weekend')); ?>
        </label>

        <div class="shift-section-label"><?php echo htmlspecialchars(t('planning.label_poste')); ?></div>
        <div id="bulk-poste-group" class="shift-chip-group"></div>

        <div class="shift-section-label"><?php echo htmlspecialchars(t('planning.label_evenement')); ?></div>
        <div id="bulk-evenement-group" class="shift-chip-group"></div>

        <div class="shift-section-label"><?php echo htmlspecialchars(t('planning.label_astreinte')); ?></div>
        <div id="bulk-astreinte-group" class="shift-chip-group"></div>

        <div class="shift-section-label"><?php echo htmlspecialchars(t('planning.label_heures')); ?> <span style="text-transform:none; font-weight:400; color:#999;"><?php echo htmlspecialchars(t('planning.hint_heures_si_travaille')); ?></span></div>
        <div class="shift-hours-row">
            <button type="button" class="shift-hours-btn" onclick="adjustBulkHours(-0.5)">−</button>
            <input type="number" id="bulk-heures" step="0.5" min="0" max="24" placeholder="—">
            <button type="button" class="shift-hours-btn" onclick="adjustBulkHours(0.5)">+</button>
        </div>

        <div id="bulk-preview" class="bulk-preview"></div>

        <div style="display:flex; gap:8px; margin-top:16px;">
            <button type="button" class="btn-shift-clear" onclick="closeBulkModal()"><?php echo htmlspecialchars(t('planning.btn_annuler')); ?></button>
            <button type="button" id="bulk-apply-btn" class="btn-save" style="margin-top:0;" onclick="applyBulkFill()" disabled><?php echo htmlspecialchars(t('planning.btn_appliquer')); ?></button>
        </div>
    </div>
</div>

<div id="annualModal" class="modal" style="z-index: 10500;">
    <div class="modal-content" style="width: min(1200px, 95vw); max-height: calc(96vh - 50px); margin: 1.5vh auto; overflow-y: auto;">
        <div class="modal-header">
            <span id="annual-modal-title"><?php echo htmlspecialchars(t('planning.modal_planning_annuel')); ?></span>
            <i class="fa-solid fa-xmark btn-del" onclick="closeAnnualModal()" title="<?php echo htmlspecialchars(t('planning.tooltip_fermer')); ?>"></i>
        </div>
        <div class="annual-top-row">
            <div id="annual-legend" class="annual-legend">
                <div id="annual-legend-postes" class="annual-legend-grid"></div>
                <div id="annual-legend-astreintes" class="annual-legend-row"></div>
            </div>
            <div class="annual-total-compact">
                <div class="atc-form-card">
                    <div class="atc-form">
                        <span id="annual-total-periode" class="atc-form-periode"></span>
                        <span id="annual-objectif-row" class="atc-form-row-group">
                            <span class="atc-form-label"><?php echo htmlspecialchars(t('planning.label_total_annuel')); ?></span>
                            <span class="atc-objectif-edit">
                                <input type="number" id="annual-objectif-input" step="1" min="1" max="9999">
                                <button type="button" onclick="saveObjectifAnnuel()" title="<?php echo htmlspecialchars(t('planning.tooltip_enregistrer')); ?>"><i class="fa-solid fa-check"></i></button>
                                <button type="button" onclick="resetObjectifInput()" title="<?php echo htmlspecialchars(t('planning.tooltip_annuler_modif')); ?>"><i class="fa-solid fa-xmark"></i></button>
                            </span>
                        </span>
                        <span id="annual-fractionnement-row" class="atc-form-row-group">
                            <label for="annual-fractionnement-select" class="atc-form-label" title="<?php echo htmlspecialchars(t('planning.tooltip_fractionnement')); ?>"><?php echo htmlspecialchars(t('planning.label_fractionnement')); ?></label>
                            <select id="annual-fractionnement-select" onchange="saveFractionnement(this.value)">
                                <option value="0"><?php echo htmlspecialchars(t('planning.opt_0j')); ?></option>
                                <option value="1"><?php echo htmlspecialchars(t('planning.opt_1j')); ?></option>
                                <option value="2"><?php echo htmlspecialchars(t('planning.opt_2j')); ?></option>
                            </select>
                        </span>
                    </div>
                </div>
                <div class="atc-row">
                    <div class="atc-track"><div class="shift-objectif-fill" id="annual-total-fill"></div><div class="objectif-marker-35h" id="annual-total-marker-35h"></div></div>
                    <strong id="annual-total-pct" class="atc-pct">0%</strong>
                    <span id="annual-total-pace" class="atc-pace"></span>
                    <button type="button" id="annual-ecart-detail-btn" class="atc-info-btn" onclick="openEcartDetailModal()" title="<?php echo htmlspecialchars(t('planning.tooltip_comprendre_ecart')); ?>"><i class="fa-solid fa-circle-info"></i></button>
                </div>
                <div class="atc-row">
                    <span id="annual-total-fait" class="atc-detail-chip"></span>
                    <span id="annual-total-detail" class="atc-detail-chip"></span>
                </div>
                <div id="annual-objectif-adjust-note" class="atc-adjust-note"></div>
            </div>
        </div>

        <div class="shift-section-label" style="margin-top:4px;"><?php echo htmlspecialchars(t('planning.label_recap_annee')); ?> <span style="text-transform:none; font-weight:400; color:#999;"><?php echo htmlspecialchars(t('planning.hint_recap_annee')); ?></span></div>
        <div id="annual-recap-grid" class="annual-recap-grid"></div>

        <div class="shift-section-label annual-calendar-label" style="margin-top:20px;">
            <?php echo htmlspecialchars(t('planning.label_calendrier')); ?>
            <div class="annual-zoom-controls">
                <button type="button" onclick="zoomAnnuel(-1)" title="<?php echo htmlspecialchars(t('planning.tooltip_reduire')); ?>"><i class="fa-solid fa-magnifying-glass-minus"></i></button>
                <span id="annual-zoom-label">100%</span>
                <button type="button" onclick="zoomAnnuel(1)" title="<?php echo htmlspecialchars(t('planning.tooltip_agrandir')); ?>"><i class="fa-solid fa-magnifying-glass-plus"></i></button>
            </div>
        </div>
        <div id="annual-mobile-nav" class="annual-mobile-nav">
            <div class="annual-mobile-nav-months">
                <button type="button" id="annual-mnav-prev" onclick="annualMonthNav(-1)" title="<?php echo htmlspecialchars(t('planning.tooltip_mois_precedent')); ?>"><i class="fa-solid fa-chevron-left"></i></button>
                <span id="annual-mnav-label">—</span>
                <button type="button" id="annual-mnav-next" onclick="annualMonthNav(1)" title="<?php echo htmlspecialchars(t('planning.tooltip_mois_suivant')); ?>"><i class="fa-solid fa-chevron-right"></i></button>
            </div>
            <button type="button" id="annual-mnav-toggle" class="annual-mnav-toggle" onclick="toggleAnnualViewMode()">
                <i class="fa-solid fa-table-cells"></i> <span id="annual-mnav-toggle-label"><?php echo htmlspecialchars(t('planning.vue_ensemble')); ?></span>
            </button>
        </div>
        <div id="annual-grid-wrapper" class="annual-grid-wrapper">
            <div id="annual-grid" class="annual-grid"></div>
        </div>
        <div id="annual-months-adjust-note" class="atc-adjust-note" style="margin-top:8px;"></div>
    </div>
</div>

<div id="ecartDetailModal" class="modal" style="z-index: 10700;">
    <div class="modal-content" style="width: min(680px, 94vw); max-height: calc(96vh - 40px); margin: 2vh auto; overflow-y: auto;">
        <div class="modal-header">
            <span id="ecart-detail-title"><?php echo htmlspecialchars(t('planning.modal_comprendre_ecart')); ?></span>
            <i class="fa-solid fa-xmark btn-del" onclick="closeEcartDetailModal()" title="<?php echo htmlspecialchars(t('planning.tooltip_fermer')); ?>"></i>
        </div>

        <div class="ecart-detail-summary">
            <div class="ecart-detail-tile">
                <div class="edt-label"><?php echo htmlspecialchars(t('planning.label_heures_dues')); ?></div>
                <div class="edt-value" id="edt-du">—</div>
            </div>
            <div class="ecart-detail-tile">
                <div class="edt-label"><?php echo htmlspecialchars(t('planning.label_heures_faites')); ?></div>
                <div class="edt-value" id="edt-fait">—</div>
            </div>
            <div class="ecart-detail-tile" id="edt-ecart-tile">
                <div class="edt-label"><?php echo htmlspecialchars(t('planning.label_ecart')); ?></div>
                <div class="edt-value" id="edt-ecart">—</div>
            </div>
        </div>

        <div class="ecart-detail-explain" id="edt-explain"></div>
        <div class="ecart-detail-ajust" id="edt-ajust-note" style="display:none;"></div>

        <div class="shift-section-label"><?php echo htmlspecialchars(t('planning.label_ecart_par_mois')); ?> <span style="text-transform:none; font-weight:400; color:#999;"><?php echo htmlspecialchars(t('planning.hint_ecart_par_mois')); ?></span></div>
        <div class="ecart-mois-grid" id="edt-mois-grid"></div>

        <div class="shift-section-label" style="margin-top:18px;"><?php echo htmlspecialchars(t('planning.label_detail_cumul')); ?> <span style="text-transform:none; font-weight:400; color:#999;"><?php echo htmlspecialchars(t('planning.hint_detail_cumul')); ?></span></div>
        <div class="edt-cumul-table-wrap">
            <table class="edt-cumul-table">
                <thead><tr><th><?php echo htmlspecialchars(t('planning.th_mois')); ?></th><th><?php echo htmlspecialchars(t('planning.th_faites')); ?></th><th><?php echo htmlspecialchars(t('planning.th_dues')); ?></th><th><?php echo htmlspecialchars(t('planning.label_ecart')); ?></th><th><?php echo htmlspecialchars(t('planning.th_cumul')); ?></th></tr></thead>
                <tbody id="edt-cumul-tbody"></tbody>
            </table>
        </div>

        <div class="shift-section-label" style="margin-top:18px;"><?php echo htmlspecialchars(t('planning.label_jours_sans_saisie')); ?> <span style="text-transform:none; font-weight:400; color:#999;"><?php echo htmlspecialchars(t('planning.hint_jours_sans_saisie')); ?></span></div>
        <div class="ecart-vides-list" id="edt-vides-list"></div>
    </div>
</div>

<script>
// --- VARIABLE JS POUR SAVOIR SI ON EST ADMIN ---
const isAdmin = <?php echo json_encode($is_admin); ?>;
const currentUser = <?php echo json_encode($_SESSION['user']); ?>;
const LIBELLES = <?php echo json_encode($LIBELLES_WORKFLOW); ?>;
// Locale utilisée uniquement pour les formats affichant des noms de jour/mois en toutes lettres
// (weekday:'long', month:'long'/'short') : les dates purement numériques (d/m/Y) restent volontairement
// en 'fr-FR' quelle que soit la langue, pour garder le même format de date dans toute l'appli.
const JS_LOCALE = <?php echo json_encode(langue_actuelle() === 'en' ? 'en-US' : (langue_actuelle() === 'nl' ? 'nl-NL' : 'fr-FR')); ?>;
const I18N_PLANNING = <?php echo json_encode([
    'planning_word' => t('planning.planning_word'),
    'semaine' => t('planning.semaine'),
    'du' => t('planning.du'),
    'au_connector' => t('planning.au_connector'),
    'role_technicien' => t('planning.role_technicien'),
    'role_responsable' => t('planning.role_responsable'),
    'role_adjoint' => t('planning.role_adjoint'),
    'role_chef_projet' => t('planning.role_chef_projet'),
    'voir_planning_annuel_de' => t('planning.voir_planning_annuel_de'),
    'non_renseigne' => t('rapport.non_renseigne'),
    'aucune_description' => t('planning.aucune_description'),
    'aucune_description_sans_point' => t('planning.aucune_description_sans_point'),
    'prev_fallback' => t('stats.prev_fallback'),
    'type_curatif' => t('maint.type_curatif'),
    'type_preventif' => t('maint.type_preventif'),
    'type_chantier' => t('maint.type_chantier'),
    'aujourdhui_suffix' => t('planning.aujourdhui_suffix'),
    'temps_non_saisi' => t('planning.temps_non_saisi'),
    'moi_prefix' => t('planning.moi_prefix'),
    'moi_suffix' => t('planning.moi_suffix'),
    'select_defaut' => t('planning.select_defaut'),
    'select_secteur' => t('st.select_secteur'),
    'select_zone' => t('st.select_zone'),
    'select_machine' => t('st.select_machine'),
    'poste_abbr_matin' => t('planning.poste_abbr_matin'), 'poste_abbr_apres_midi' => t('planning.poste_abbr_apres_midi'),
    'poste_abbr_nuit' => t('planning.poste_abbr_nuit'), 'poste_abbr_journee' => t('planning.poste_abbr_journee'),
    'poste_abbr_jour_ferie' => t('planning.poste_abbr_jour_ferie'), 'poste_abbr_cp' => t('planning.poste_abbr_cp'),
    'poste_abbr_demi_cp' => t('planning.poste_abbr_demi_cp'), 'poste_abbr_maladie' => t('planning.poste_abbr_maladie'),
    'poste_abbr_rtt' => t('planning.poste_abbr_rtt'), 'poste_abbr_repos' => t('planning.poste_abbr_repos'),
    'jour_lundi' => t('jour.lundi'), 'jour_mardi' => t('jour.mardi'), 'jour_mercredi' => t('jour.mercredi'),
    'jour_jeudi' => t('jour.jeudi'), 'jour_vendredi' => t('jour.vendredi'), 'jour_samedi' => t('jour.samedi'), 'jour_dimanche' => t('jour.dimanche'),
    'non_assigne' => t('stats.non_assigne'),
    'aucun_bi_ce_jour' => t('planning.aucun_bi_ce_jour'),
    'modal_remplissage_rapide' => t('planning.modal_remplissage_rapide'),
    'choisir_periode_valide' => t('planning.choisir_periode_valide'),
    'jour_concerne_un' => t('planning.jour_concerne_un'),
    'jour_concerne_plusieurs' => t('planning.jour_concerne_plusieurs'),
    'choisir_poste_evenement' => t('planning.choisir_poste_evenement'),
    'jour_seront_remplis_un' => t('planning.jour_seront_remplis_un'),
    'jour_seront_remplis_plusieurs' => t('planning.jour_seront_remplis_plusieurs'),
    'jours_ecrases' => t('planning.jours_ecrases'),
    'confirm_bulk_apply' => t('planning.confirm_bulk_apply'),
    'application_en_cours' => t('planning.application_en_cours'),
    'avance' => t('planning.avance'),
    'retard' => t('planning.retard'),
    'faites' => t('planning.faites'),
    'depasse' => t('planning.depasse'),
    'restant' => t('planning.restant'),
    'rien_de_prevu' => t('planning.rien_de_prevu'),
    'mois_complet' => [t('planning.moiscomplet.janvier'), t('planning.moiscomplet.fevrier'), t('planning.moiscomplet.mars'), t('planning.moiscomplet.avril'), t('planning.moiscomplet.mai'), t('planning.moiscomplet.juin'), t('planning.moiscomplet.juillet'), t('planning.moiscomplet.aout'), t('planning.moiscomplet.septembre'), t('planning.moiscomplet.octobre'), t('planning.moiscomplet.novembre'), t('planning.moiscomplet.decembre')],
    'jour_lettre' => [t('planning.jourlettre.dimanche'), t('planning.jourlettre.lundi'), t('planning.jourlettre.mardi'), t('planning.jourlettre.mercredi'), t('planning.jourlettre.jeudi'), t('planning.jourlettre.vendredi'), t('planning.jourlettre.samedi')],
    'recap_matin' => t('planning.recap_matin'),
    'recap_apres_midi' => t('planning.recap_apres_midi'),
    'recap_nuit' => t('planning.recap_nuit'),
    'recap_conges_payes' => t('planning.recap_conges_payes'),
    'recap_cp_samedi' => t('planning.recap_cp_samedi'),
    'recap_rtt' => t('planning.recap_rtt'),
    'recap_maladie' => t('planning.recap_maladie'),
    'recap_astreinte' => t('planning.recap_astreinte'),
    'recap_astreinte_froid' => t('planning.recap_astreinte_froid'),
    'note_rtt' => t('planning.note_rtt'),
    'jour_pose_un' => t('planning.jour_pose_un'),
    'jour_pose_plusieurs' => t('planning.jour_pose_plusieurs'),
    'pct_realise' => t('planning.pct_realise'),
    'alerte_cp_streak' => t('planning.alerte_cp_streak'),
    'tooltip_detail_calcul' => t('planning.tooltip_detail_calcul'),
    'comprendre_ecart_tech' => t('planning.comprendre_ecart_tech'),
    'deja_compte_ecart' => t('planning.deja_compte_ecart'),
    'explain_jours_remplis' => t('planning.explain_jours_remplis'),
    'explain_tout_rempli' => t('planning.explain_tout_rempli'),
    'jour_de_semaine_un' => t('planning.jour_de_semaine_un'),
    'jour_de_semaine_plusieurs' => t('planning.jour_de_semaine_plusieurs'),
    'ajustement_depart' => t('planning.ajustement_depart'),
    'aucun_jour_vide' => t('planning.aucun_jour_vide'),
    'pile_rythme' => t('planning.pile_rythme'),
    'avance_rythme' => t('planning.avance_rythme'),
    'retard_rythme' => t('planning.retard_rythme'),
    'a_ce_jour' => t('planning.a_ce_jour'),
    'au_date' => t('planning.au_date'),
    'tooltip_repere_35h' => t('planning.tooltip_repere_35h'),
    'h_faites_sur' => t('planning.h_faites_sur'),
    'objectif_depasse' => t('planning.objectif_depasse'),
    'h_restantes_sur' => t('planning.h_restantes_sur'),
    'objectif_ajuste' => t('planning.objectif_ajuste'),
    'somme_mois_ajustement' => t('planning.somme_mois_ajustement'),
    'fractionnement_h' => t('planning.fractionnement_h'),
    'semaines_48h' => t('planning.semaines_48h'),
    'fractionnement_h_court' => t('planning.fractionnement_h_court'),
    'semaines_48h_court' => t('planning.semaines_48h_court'),
    'fractionnement_reduction' => t('planning.fractionnement_reduction'),
    'semaines_48h_reduction' => t('planning.semaines_48h_reduction'),
    'planning_annuel_tech' => t('planning.planning_annuel_tech'),
    'confirm_suppr_definitive' => t('planning.confirm_suppr_definitive'),
    'confirmation_title' => t('planning.confirmation_title'),
    'choix_todo_list' => t('planning.choix_todo_list'),
    'todo_vide' => t('planning.todo_vide'),
    'todo_confirm_suppr_msg' => t('planning.todo_confirm_suppr_msg'),
    'err_suppr_serveur' => t('planning.err_suppr_serveur'),
    'saving' => t('maint.saving'),
    'success_title' => t('maint.success_title'),
    'err_title' => t('maint.err_title'),
    'err_network_title' => t('maint.err_network_title'),
    'rapport_intermediaire_maj' => t('planning.rapport_intermediaire_maj'),
    'err_server_refused' => t('maint.err_server_refused'),
    'err_network_unreachable' => t('maint.err_network_unreachable'),
    'avance_globale_cumulee' => t('planning.avance_globale_cumulee'),
    'total' => t('planning.total'),
    'ecart_35h' => t('planning.ecart_35h'),
    'total_annualise_du' => t('planning.total_annualise_du'),
    'h_an_suffix' => t('planning.h_an_suffix'),
    'saisie_libre' => t('planning.saisie_libre'),
    'label_machine' => t('planning.label_machine'),
    'tooltip_ajustement_objectif' => t('planning.tooltip_ajustement_objectif'),
    'detecte_auto' => t('planning.detecte_auto'),
    'tooltip_h_faites_dues' => t('planning.tooltip_h_faites_dues'),
    'planifier_tech' => t('planning.planifier_tech'),
]); ?>;

function openNav(e) { if(e) e.stopPropagation(); document.getElementById("mySidebar").style.width = "280px"; }
function closeNav() { document.getElementById("mySidebar").style.width = "0"; }
function checkCloseSidebar(event) { if (document.getElementById("mySidebar").style.width === "280px" && !document.getElementById("mySidebar").contains(event.target)) closeNav(); }

let tasks = [];
let pointages = [];
// Todo list par technicien/jour (voir #todoModal, ouvrirTodoModal) : un technicien ne reçoit ici que ses
// propres tâches, un admin les reçoit toutes (filtrage déjà fait côté serveur, voir get_todo).
let todoParCle = {};
let currentMonday = getMonday(new Date());
let lastSwitchTime = 0;
let draggedTaskId = null;

// --- VUE "1 JOUR" (tablette/mobile) ---
// null = vue semaine normale (desktop) ; 0-6 = index (Lundi=0) du jour affiché seul.
let vueJour = null;

function ecranEtroit() { return window.matchMedia('(max-width: 1024px)').matches; }

// Décide si on doit être en vue jour, appelé au chargement et au redimensionnement. On ne bascule vers la
// vue jour que si on ne l'était pas déjà (sinon un simple resize ferait sauter le jour affiché) ; on repasse
// systématiquement en vue semaine dès que l'écran redevient assez large.
function appliquerModeAffichage() {
    if (ecranEtroit()) {
        if (vueJour === null) {
            const todayObj = new Date();
            const diffJours = Math.round((todayObj - currentMonday) / 86400000);
            vueJour = (diffJours >= 0 && diffJours <= 6) ? diffJours : 0;
        }
    } else {
        vueJour = null;
    }
}

// --- VUE "1 TECHNICIEN" (tablette/mobile) ---
// null = toute l'équipe visible (desktop, comportement d'origine) ; un nom = une seule ligne technicien
// affichée. Sur petit écran, voir tout le monde en même temps n'a pas de sens pour un technicien (il ne
// peut de toute façon modifier que sa propre ligne — voir peutPlanifier plus bas) et surcharge l'écran :
// on limite donc par défaut à sa propre ligne. Un admin peut choisir n'importe quel technicien (ou
// lui-même) via le sélecteur #techSwitcher.
let vueTech = null;

function appliquerModeTech() {
    if (ecranEtroit()) {
        if (!vueTech) { vueTech = currentUser; }
    } else {
        vueTech = null;
    }
}

// body{padding-top} était une valeur fixe (98px) qui suppose une hauteur d'en-tête constante. Sur petit
// écran, "Semaine XX" peut repasser sur 2 lignes (police plus grande que prévu, ou simplement un mot plus
// long) et l'en-tête (position:fixed) grandit alors au-delà de ces 98px réservés — le contenu qui suit
// (#techSwitcher notamment) se retrouve alors visuellement sous l'en-tête. On mesure donc la vraie
// hauteur rendue et on ajuste le padding en conséquence, plutôt que de parier sur un chiffre figé.
function ajusterPaddingHeader() {
    const header = document.querySelector('header');
    if (!header) return;
    document.body.style.paddingTop = header.offsetHeight + 'px';
}
window.addEventListener('load', ajusterPaddingHeader);

function debounce(fn, delai) {
    let t;
    return function (...args) { clearTimeout(t); t = setTimeout(() => fn.apply(this, args), delai); };
}
// renderTable() rappelle déjà appliquerVisibiliteJours() en fin d'exécution (voir plus bas) : pas besoin
// de l'appeler séparément ici.
window.addEventListener('resize', debounce(() => {
    appliquerModeAffichage(); appliquerModeTech(); renderTable(); ajusterPaddingHeader();
    // Rotation d'écran / redimensionnement pendant que le planning annuel est ouvert : on rebascule au
    // besoin entre vue mensuelle et vue d'ensemble (voir appliquerVueAnnuelleMobile).
    if (document.getElementById('annualModal').style.display === 'block') { appliquerVueAnnuelleMobile(); }
}, 150));
if (document.fonts && document.fonts.ready) { document.fonts.ready.then(ajusterPaddingHeader); }

// L'équipe est maintenant chargée dynamiquement depuis MySQL
const team = <?php echo json_encode(array_column($equipe_db, 'username')); ?>;
const teamPhotos = <?php echo json_encode(array_column($equipe_db, 'photo', 'username')); ?>;

// Ordre personnalisé de l'équipe demandé
const ordreEquipe = ["Technicien 1", "Technicien 2", "Technicien 3", "Technicien 5", "Technicien 4", "Technicien 6", "Technicien 7"];
team.sort((a, b) => ordreEquipe.indexOf(a) - ordreEquipe.indexOf(b));

// --- Horaires d'équipe importés depuis Excel (Paramètres > Planning) ---
const planningPostes = <?php echo json_encode(array_column($planning_postes, null, 'cle')); ?>;
const planningAstreintes = <?php echo json_encode(array_column($planning_astreintes, null, 'cle')); ?>;
const planningShiftsBruts = <?php echo json_encode($planning_shifts); ?>;
const shiftsParCle = {};
planningShiftsBruts.forEach(s => { shiftsParCle[`${s.utilisateur}_${s.jour}`] = s; });
const days = [I18N_PLANNING.jour_lundi, I18N_PLANNING.jour_mardi, I18N_PLANNING.jour_mercredi, I18N_PLANNING.jour_jeudi, I18N_PLANNING.jour_vendredi, I18N_PLANNING.jour_samedi, I18N_PLANNING.jour_dimanche];

// --- ANNUALISATION (1er avril au 31 mars) ---
const totauxAnnualises = <?php echo json_encode($totaux_annualises); ?>;
const objectifsAnnuels = <?php echo json_encode($objectifs_annuels); ?>;
const fractionnementAnnuel = <?php echo json_encode($fractionnement_annuel); ?>;
const periodeAnnualisationLabel = "<?php echo (new DateTime($periode_annualisation_debut))->format('d/m/Y') . ' ' . t('planning.au_connector') . ' ' . (new DateTime($periode_annualisation_fin))->format('d/m/Y'); ?>";
const periodeAnnualisationDebut = "<?php echo $periode_annualisation_debut; ?>";
const periodeAnnualisationFin = "<?php echo $periode_annualisation_fin; ?>";

// --- GESTION DES SOUS-TRAITANTS ---
// On récupère la liste envoyée par PHP (tout en haut du fichier)
const entreprisesList = <?php echo json_encode($entreprises_ext); ?>;

// Fonction pour cacher la liste des techniciens et afficher la liste des entreprises
function toggleST() {
    const isST = document.getElementById('m-is-st').checked;
    // La boîte orange s'affiche en mode 'block' pour occuper tout l'espace de la ligne proprement
    document.getElementById('box-ee').style.display = isST ? 'block' : 'none';
}

// --- MOTEUR DES CASCADES POUR LA MODALE PLANNING ---
const dbMachines = <?php echo json_encode($machines_db); ?>;

function initCascade() {
    if (dbMachines.length === 0) {
        document.getElementById('container-equip').innerHTML = `<span>${I18N_PLANNING.label_machine}</span><input id="m-equip" placeholder="${I18N_PLANNING.saisie_libre}">`;
        return;
    }
    const usines = [...new Set(dbMachines.map(m => m.usine))].sort();
    document.getElementById('m-usine').innerHTML = `<option value="">${I18N_PLANNING.select_defaut}</option>` + usines.map(u => `<option value="${u}">${u}</option>`).join('');
}

function updateSecteurs() {
    const usine = document.getElementById('m-usine').value;
    const mSecteur = document.getElementById('m-secteur');
    if (!usine) { mSecteur.disabled = true; return; }
    const secteurs = [...new Set(dbMachines.filter(m => m.usine === usine).map(m => m.secteur))].sort();
    mSecteur.innerHTML = `<option value="">${I18N_PLANNING.select_secteur}</option>` + secteurs.map(s => `<option value="${s}">${s}</option>`).join('');
    mSecteur.disabled = false;
}

function updateZones() {
    const usine = document.getElementById('m-usine').value;
    const secteur = document.getElementById('m-secteur').value;
    const mZone = document.getElementById('m-zone');
    if (!secteur) { mZone.disabled = true; return; }
    const zones = [...new Set(dbMachines.filter(m => m.usine === usine && m.secteur === secteur).map(m => m.zone))].sort();
    mZone.innerHTML = `<option value="">${I18N_PLANNING.select_zone}</option>` + zones.map(z => `<option value="${z}">${z}</option>`).join('');
    mZone.disabled = false;
}

function updateMachines() {
    const usine = document.getElementById('m-usine').value;
    const secteur = document.getElementById('m-secteur').value;
    const zone = document.getElementById('m-zone').value;
    const mEquip = document.getElementById('m-equip');
    if (!zone) { mEquip.disabled = true; return; }
    const machines = dbMachines.filter(m => m.usine === usine && m.secteur === secteur && m.zone === zone).sort((a,b) => a.nom_machine.localeCompare(b.nom_machine));
    mEquip.innerHTML = `<option value="">${I18N_PLANNING.select_machine}</option>` + machines.map(m => `<option value="${m.nom_machine}">${m.nom_machine}</option>`).join('');
    mEquip.disabled = false;
}

function getMonday(d) { 
    d = new Date(d); 
    let day = d.getDay(), diff = d.getDate() - day + (day == 0 ? -6 : 1); 
    let mon = new Date(d.setDate(diff));
    mon.setHours(0,0,0,0);
    return mon;
}

function getWeekNumber(d) {
    d = new Date(Date.UTC(d.getFullYear(), d.getMonth(), d.getDate()));
    d.setUTCDate(d.getUTCDate() + 4 - (d.getUTCDay()||7));
    return Math.ceil((((d - new Date(Date.UTC(d.getUTCFullYear(),0,1))) / 86400000) + 1)/7);
}

const dataHeuresSQL = <?php echo json_encode($heures_sql); ?>;

function calculateWeeklyHours(techName) {
    let total = 0;
    const joursSemaine = [];
    for(let i=0; i<7; i++) {
        const d = new Date(currentMonday);
        d.setDate(d.getDate() + i);
        joursSemaine.push(d.toISOString().split('T')[0]);
    }

    pointages.forEach(p => {
        if (p.tech === techName && p.date) {
            let datePointage = p.date.split(' ')[0];
            if (joursSemaine.includes(datePointage)) {
                total += parseFloat(p.hours || 0);
            }
        }
    });
    return total.toFixed(1);
}

function updateIndicator(diff) {
    const indicator = document.getElementById('week-indicator');
    const targetDate = new Date(currentMonday);
    targetDate.setDate(targetDate.getDate() + diff);
    // On garde l'harmonie de l'affichage
    indicator.innerHTML = `${I18N_PLANNING.planning_word} <span>${I18N_PLANNING.semaine} ${getWeekNumber(targetDate)}</span>`;
    indicator.classList.add('show'); // On utilise la classe CSS animée !
}

window.addEventListener('dragover', (e) => {
    if (!draggedTaskId) return;
    const threshold = 70;
    const now = Date.now();
    if (e.clientX < threshold) {
        document.getElementById('edge-left').classList.add('active');
        updateIndicator(-7);
        if (now - lastSwitchTime > 1800) { moveWeek(-7); lastSwitchTime = now; }
    } else if (e.clientX > window.innerWidth - threshold) {
        document.getElementById('edge-right').classList.add('active');
        updateIndicator(7);
        if (now - lastSwitchTime > 1800) { moveWeek(7); lastSwitchTime = now; }
    } else { 
        document.getElementById('edge-left').classList.remove('active');
        document.getElementById('edge-right').classList.remove('active');
        // Correction du bug d'invisibilité : on enlève juste la classe show
        document.getElementById('week-indicator').classList.remove('show');
    }
});

async function moveWeek(diff) {
    currentMonday.setDate(currentMonday.getDate() + diff);
    renderTable();
}

async function loadData() {
    try {
        const resTasks = await fetch('api.php?update=' + Date.now());
        tasks = await resTasks.json();

        const resPt = await fetch('maintenance.php?get_pointages=1&t=' + Date.now());
        pointages = await resPt.json();

        const resTodo = await fetch('maintenance.php?get_todo=1&t=' + Date.now());
        indexerTodoItems(await resTodo.json());

        // La vue Mois a sa propre grille (moisGrid), pas la peine de reconstruire la grille Semaine
        // (planningTable, masquee) pendant qu'on la regarde.
        if (typeof modeVue !== 'undefined' && modeVue === 'mois') { renderMoisView(); } else { renderTable(); }
    } catch(e) { console.error("Erreur chargement:", e); }
}

// Reconstruit todoParCle (clé "tech_date") à partir de la liste plate renvoyée par get_todo.
function indexerTodoItems(items) {
    todoParCle = {};
    (items || []).forEach(item => {
        const key = `${item.utilisateur}_${item.jour}`;
        if (!todoParCle[key]) todoParCle[key] = [];
        todoParCle[key].push(item);
    });
}

// Ouvre le rapport d'un bon d'intervention (utilise par la vue Semaine ET la vue Mois, voir renderMoisView)
// avec le meme filet de securite : le composant de rapport peut mettre un instant a etre pret, donc on
// reessaie d'y injecter le demandeur pendant 2 secondes plutot que de risquer un champ vide.
function ouvrirDetailBI(tk) {
    showDetailBI(tk.id);
    let verifCompteur = 0;
    const forceDemandeur = setInterval(() => {
        const repDemandeur = document.getElementById('rep-demandeur') || document.getElementById('rapport-demandeur');
        if (repDemandeur) {
            repDemandeur.innerText = tk.demandeur || I18N_PLANNING.non_renseigne;
            clearInterval(forceDemandeur);
        }
        if (++verifCompteur > 20) clearInterval(forceDemandeur); // Stop après 2 secondes si non trouvé
    }, 100);
}

function ouvrirDetailBIParId(id) {
    const tk = tasks.find(t => t.id == id);
    if (tk) { ouvrirDetailBI(tk); } else { showDetailBI(id); }
}

function renderTable() {
    const container = document.getElementById('planningTable');
    container.innerHTML = "<div style='background: #f8f9fa;'></div>";
    // L'en-tête garde sa hauteur naturelle ("auto"), les lignes techniciens se partagent le reste à parts
    // égales ("1fr" × N) : la grille tient toujours exactement dans la hauteur du conteneur, quel que soit
    // le nombre de techniciens. Sur un écran bas (téléphone en paysage : large mais peu haut), ce partage
    // à parts égales peut descendre sous la taille lisible d'une ligne (avatar + nom + rôle) — on impose
    // alors un plancher de 46px par ligne et on laisse le conteneur défiler (overflow-y:auto déjà posé)
    // plutôt que d'écraser le contenu.
    // Vue "1 technicien" (mobile/tablette, voir appliquerModeTech) : on ne construit que la ligne du
    // technicien choisi plutôt que de générer toute l'équipe pour ensuite la masquer en CSS — ça évite le
    // vide/défilement inutile d'une grille à 7 lignes dont 6 seraient cachées.
    const teamAffiche = vueTech ? team.filter(t => t === vueTech) : team;
    // Avec une seule ligne, la répartition "1fr" l'étire pour occuper TOUTE la hauteur du conteneur (avatar
    // et nom flottant seuls dans un bandeau démesuré) : on passe alors à une hauteur "auto" (la ligne ne
    // prend que la place dont son contenu a besoin) + align-content:start (voir .un-seul-tech) pour que le
    // reste de la hauteur reste simplement vide en bas plutôt que d'étirer la ligne.
    const modeUnTech = teamAffiche.length === 1;
    const ligneMin = modeUnTech ? 'auto' : (window.innerHeight < 480 ? 'minmax(46px, 1fr)' : '1fr');
    container.style.gridTemplateRows = `auto repeat(${teamAffiche.length}, ${ligneMin})`;
    container.classList.toggle('un-seul-tech', modeUnTech);

    document.getElementById('weekLabel').innerText = `${I18N_PLANNING.semaine} ${getWeekNumber(currentMonday)}`;
    const lastDay = new Date(currentMonday); lastDay.setDate(lastDay.getDate() + 6);
    document.getElementById('currentDateRange').innerText = `${I18N_PLANNING.du} ${currentMonday.toLocaleDateString(JS_LOCALE, {day:'numeric', month:'short'})} ${I18N_PLANNING.au_connector} ${lastDay.toLocaleDateString(JS_LOCALE, {day:'numeric', month:'short', year:'numeric'})}`;

    const todayObj = new Date();
    const todayStr = `${todayObj.getFullYear()}-${String(todayObj.getMonth() + 1).padStart(2, '0')}-${String(todayObj.getDate()).padStart(2, '0')}`;

    days.forEach((dayName, idx) => {
        const d = new Date(currentMonday); d.setDate(d.getDate() + idx);
        const y = d.getFullYear();
        const m = String(d.getMonth() + 1).padStart(2, '0');
        const day = String(d.getDate()).padStart(2, '0');
        const dStr = `${y}-${m}-${day}`;
        const dateDisplay = d.toLocaleDateString('fr-FR', {day:'2-digit', month:'2-digit', year:'numeric'});
        
        const isToday = (dStr === todayStr);

        container.insertAdjacentHTML('beforeend', `
            <div class="day-header ${isToday ? 'today-active' : ''}" data-day-idx="${idx}">
                <div class="day-name">${dayName}</div>
                <div class="day-date">${dateDisplay}</div>
            </div>`);
    });

    teamAffiche.forEach((tech, techIdx) => {
        const totalH = calculateWeeklyHours(tech);
        // Dernière ligne du tableau : les tuiles d'info des bons d'intervention s'y ouvrent vers le haut
        // plutôt que vers le bas (voir .tech-row-last dans le CSS), sinon elles sortent de la fenêtre.
        const isLastRow = techIdx === teamAffiche.length - 1;

        // Détermination dynamique du rôle pour l'affichage
        let roleAffiche = I18N_PLANNING.role_technicien;
        if (tech === "Technicien 1") {
            roleAffiche = I18N_PLANNING.role_responsable;
        } else if (tech === "Technicien 2") {
            roleAffiche = I18N_PLANNING.role_adjoint;
        } else if (tech === "Technicien 3") {
            roleAffiche = I18N_PLANNING.role_chef_projet;
        }
        // Les autres (Technicien 4, 5, 6, 7) resteront automatiquement sur "Technicien"

        const peutVoirAnnuel = isAdmin || tech === currentUser;
        const sidebarEl = document.createElement('div');
        sidebarEl.className = "tech-sidebar" + (peutVoirAnnuel ? " tech-sidebar-clickable" : "") + (isLastRow ? " tech-row-last" : "");
        if (peutVoirAnnuel) {
            sidebarEl.title = I18N_PLANNING.voir_planning_annuel_de + " " + tech;
            sidebarEl.addEventListener('click', () => openAnnualModal(tech));
        }
        sidebarEl.innerHTML = `
                <div class="avatar-wrapper">
                    <img src="${teamPhotos[tech] || 'img/user.png'}" onerror="this.src='https://api.dicebear.com/7.x/initials/svg?seed=${tech}'">
                </div>
                <div>
                    <div class="tech-name" style="font-weight: 600;">${tech}</div>
                    <div class="tech-role" style="font-size: 0.65rem; color: #555; font-style: italic; margin-top: 1px;">${roleAffiche}</div>
                    <div style="margin-top: 3px;">
                        <div class="tech-hours-badge" style="display:inline-block; margin-top:0;">${totalH}h</div>
                        <div class="tech-annual-badge" title="${I18N_PLANNING.total_annualise_du} ${periodeAnnualisationLabel}"><i class="fa-solid fa-calendar-days"></i>${(totauxAnnualises[tech] || 0).toFixed(1).replace(/\.0$/, '')}${I18N_PLANNING.h_an_suffix}</div>
                    </div>
                </div>`;
        container.appendChild(sidebarEl);

        days.forEach((_, idx) => {
            const d = new Date(currentMonday); d.setDate(d.getDate() + idx);
            const dStr = `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;

            const cell = document.createElement('div');
            // edge-col-first/edge-col-last (voir .task-tooltip plus haut) : empeche la tuile d'info de
            // deborder du cadre du planning pour un bon d'intervention du premier ou dernier jour affiche.
            cell.className = "drop-zone" + (isLastRow ? " tech-row-last" : "") + (idx === 0 ? " edge-col-first" : "") + (idx === days.length - 1 ? " edge-col-last" : "");
            cell.dataset.dayIdx = idx;
            const peutPlanifier = isAdmin || tech === currentUser;
            if (!peutPlanifier) {cell.style.cursor = "default"; }

            const shift = shiftsParCle[`${tech}_${dStr}`];
            const todoItemsJour = todoParCle[`${tech}_${dStr}`] || [];
            const aUnShift = shift && (shift.poste || shift.astreinte || shift.note || shift.demi_conge || shift.jour_ferie);
            if (aUnShift || todoItemsJour.length > 0) {
                const ligneShift = document.createElement('div');
                ligneShift.className = "shift-badges-row";
                // Todo list du jour (voir #todoModal) : petite pastille avant les badges d'horaires, pour
                // signaler d'un coup d'œil qu'il y a des tâches sans avoir à ouvrir la case.
                if (todoItemsJour.length > 0) { ligneShift.insertAdjacentHTML('beforeend', todoBadgeHtml(tech, dStr, todoItemsJour)); }
                if (aUnShift) {
                    if (shift.poste && planningPostes[shift.poste]) {
                        const bp = document.createElement('span');
                        const heuresSaisies = shift.heures !== null && shift.heures !== undefined && shift.heures !== '';
                        bp.className = "shift-badge-compact" + (heuresSaisies ? "" : " shift-badge-nohours");
                        bp.style.setProperty('--c', planningPostes[shift.poste].couleur);
                        let libellePoste = planningPostes[shift.poste].label + (shift.demi_conge ? ' ½' : '');
                        const titreExtra = [];
                        // Poste + demi-congé et/ou jour férié cumulés le même jour : liserés de chaque côté du
                        // badge, en plus de la couleur du poste au centre, pour distinguer visuellement le tout.
                        if (shift.demi_conge && planningPostes.demi_cp) {
                            bp.style.borderRight = `4px solid ${planningPostes.demi_cp.couleur}`;
                            titreExtra.push(planningPostes.demi_cp.label);
                        }
                        if (shift.jour_ferie && planningPostes.jour_ferie) {
                            bp.style.borderLeft = `4px solid ${planningPostes.jour_ferie.couleur}`;
                            libellePoste += ' · ' + planningPostes.jour_ferie.label;
                            titreExtra.push(planningPostes.jour_ferie.label);
                        }
                        bp.textContent = heuresSaisies ? `${libellePoste} · ${parseFloat(shift.heures)}h` : libellePoste;
                        if (titreExtra.length) { bp.title = `${planningPostes[shift.poste].label} + ${titreExtra.join(' + ')}`; }
                        ligneShift.appendChild(bp);
                    } else if (shift.demi_conge && planningPostes.demi_cp) {
                        const bd = document.createElement('span');
                        bd.className = "shift-badge-compact";
                        bd.style.setProperty('--c', planningPostes.demi_cp.couleur);
                        bd.textContent = planningPostes.demi_cp.label;
                        ligneShift.appendChild(bd);
                    } else if (shift.jour_ferie && planningPostes.jour_ferie) {
                        const bf = document.createElement('span');
                        bf.className = "shift-badge-compact";
                        bf.style.setProperty('--c', planningPostes.jour_ferie.couleur);
                        bf.textContent = planningPostes.jour_ferie.label;
                        ligneShift.appendChild(bf);
                    }
                    if (shift.astreinte && planningAstreintes[shift.astreinte]) {
                        const ba = document.createElement('span');
                        ba.className = "shift-badge-compact";
                        ba.style.setProperty('--c', planningAstreintes[shift.astreinte].couleur);
                        ba.textContent = planningAstreintes[shift.astreinte].label;
                        ligneShift.appendChild(ba);
                    }
                    // Note personnelle : uniquement visible sur SA PROPRE case, même pour un admin — le
                    // planning journalier/hebdomadaire est une vue de groupe, contrairement au planning
                    // annuel (accessible techincien par technicien) où l'admin peut consulter les notes.
                    if (shift.note && tech === currentUser) {
                        const bn = document.createElement('span');
                        bn.className = "shift-note-badge";
                        bn.innerHTML = '<i class="fa-solid fa-note-sticky"></i>';
                        const bubble = document.createElement('span');
                        bubble.className = 'shift-note-bubble';
                        bubble.textContent = shift.note;
                        bn.appendChild(bubble);
                        ligneShift.appendChild(bn);
                    }
                }
                cell.appendChild(ligneShift);
            }

            // Un technicien ne peut déposer un bon d'intervention que sur ses propres cases, jamais sur
            // celles d'un collègue (réassignation réservée aux admins). Ne pas preventDefault() suffit à
            // faire refuser le drop par le navigateur (curseur "interdit"), donc le drop ne se déclenche
            // même pas dans ce cas.
            cell.addEventListener('dragover', (e) => { if (peutPlanifier) e.preventDefault(); });
            cell.addEventListener('dragenter', () => { if (peutPlanifier) cell.classList.add('drag-over'); });
            cell.addEventListener('dragleave', () => cell.classList.remove('drag-over'));
            cell.addEventListener('drop', (e) => {
                cell.classList.remove('drag-over');
                if (!peutPlanifier) return;
                handleDrop(e, tech, dStr);
            });

            cell.addEventListener('click', (e) => {
    // Un admin peut planifier tout le monde ; un technicien uniquement ses propres cases
    if(peutPlanifier && (e.target === cell || e.target.closest('.shift-badges-row'))) {
        ouvrirChoixCase(tech, dStr);
    }
});

            tasks.forEach(tk => {
    // On cherche si ce technicien a un pointage pour cette tâche ce jour-là
    const ptgDuJour = pointages.find(p => 
        p.task_id === tk.id && 
        p.tech === tech && // <-- CORRECTION SÉCURISÉE
        p.date.split(' ')[0] === dStr
    );

                // CORRECTION : On n'affiche le ticket QUE si le technicien a un pointage dessus.
                // (On a supprimé la condition isDeclarantAndDateMatch qui polluait le planning du déclarant)
                if (ptgDuJour) {
                    const card = document.createElement('div');
                    const s = (tk.statut || '').toLowerCase();
                    let statusClass = s.includes('termin') ? 'termine' : (s.includes('cours') ? 'encours' : 'afaire');
                    if(tk.prio === "Urgent" && !s.includes('termin')) statusClass = 'urgent';
                    
                    card.className = `task-badge-compact status-${statusClass}`;
                    // Le bon est figé sur le jour d'un technicien : lui-même ne peut ni le déplacer ni le
                    // "récupérer" chez un collègue, seul un admin peut déplacer les bons de n'importe qui.
                    card.draggable = peutPlanifier;
                    if (!peutPlanifier) { card.style.cursor = "default"; }
                    
                    if(ptgDuJour) card.setAttribute('data-pointage-id', ptgDuJour.id);
                    
                    let totalHeuresReelles = pointages.filter(p => p.task_id === tk.id)
                                                      .reduce((acc, p) => acc + parseFloat(p.hours || 0), 0);
                    
                    let affichageTemps = totalHeuresReelles > 0 
                                         ? `<span style="color:#64b5f6; font-weight:900;">${totalHeuresReelles.toFixed(1)}h (R)</span>` 
                                         : `<span style="color:#ffb74d; font-weight:900;">${parseFloat(tk.hours || 0).toFixed(1)}h (P)</span>`;

                    let numBadge = tk.num_bi ? tk.num_bi : I18N_PLANNING.prev_fallback;
                    let alertIcon = statusClass === 'urgent' ? '<i class="fa-solid fa-triangle-exclamation blink-icon" style="margin-left:3px;"></i>' : '';
                    
                    // --- NOUVEAUTÉ : Affichage des heures du jour sur l'étiquette ---
                    let heuresDuJour = parseFloat(ptgDuJour.hours || 0);
                    // On crée une petite pastille d'heures (uniquement si le temps saisi est supérieur à 0)
                    let badgeHeuresHtml = heuresDuJour > 0 
                        ? `<span style="background: rgba(0,0,0,0.15); padding: 1px 4px; border-radius: 4px; margin-left: 5px; font-size: 0.55rem; border: 1px solid rgba(0,0,0,0.1);">${heuresDuJour}h</span>` 
                        : '';

                    card.innerHTML = `
                        <span style="pointer-events: none; display: flex; align-items: center;">${numBadge}${alertIcon}${badgeHeuresHtml}</span>
                        
                        <div class="task-tooltip">
                            <div style="font-family:'Caveat', cursive; font-size:1.4rem; color:var(--brand-orange); margin-bottom:5px; line-height:1;">${tk.equip}</div>
                            <div style="font-size:0.75rem; color:#e2e8f0; margin-bottom:10px; line-height:1.3; overflow:hidden; display:-webkit-box; -webkit-line-clamp:3; -webkit-box-orient:vertical;">${tk.desc || I18N_PLANNING.aucune_description}</div>
                            <div style="display:flex; justify-content:space-between; border-top:1px solid rgba(255,255,255,0.1); padding-top:8px; font-size:0.7rem;">
                                <span>${affichageTemps}</span>
                                <span style="text-transform:uppercase; font-weight:900; color:${statusClass==='termine'?LIBELLES.termine.couleur:(statusClass==='encours'?LIBELLES.encours.couleur:LIBELLES.afaire.couleur)}">${tk.statut}</span>
                            </div>
                        </div>
                    `;
                    
                    card.onclick = (e) => { e.stopPropagation(); ouvrirDetailBI(tk); };
                    
                    card.ondragstart = (e) => {
                        draggedTaskId = tk.id;
                        e.dataTransfer.setData("taskId", tk.id);
                        if(ptgDuJour) e.dataTransfer.setData("pointageId", ptgDuJour.id);
                        setTimeout(() => card.style.opacity = "0.4", 0);
                    };
                    
                    card.ondragend = () => { 
                        draggedTaskId = null; 
                        card.style.opacity = "1"; 
                        // On éteint proprement la bulle avec le CSS
                        document.getElementById('week-indicator').classList.remove('show'); 
                        document.getElementById('edge-left').classList.remove('active');
                        document.getElementById('edge-right').classList.remove('active');
                    };
                    
                    cell.appendChild(card);
                }
            });
            
            container.appendChild(cell);
        });
    });

    appliquerVisibiliteJours();
    appliquerVisibiliteTech();
    renderPlanningDayCards();
}

// Agenda "cartes" (voir CSS .planning-day-cards) : remplace la grille pour un technicien + un jour sur
// mobile/tablette. Reconstruit à partir des mêmes données (shiftsParCle, tasks, pointages) que la grille
// desktop ci-dessus, plutôt que de réutiliser son DOM — la grille est pensée pour être compacte (infos au
// survol, inutilisable au tactile), l'agenda doit au contraire tout afficher en clair.
function renderPlanningDayCards() {
    const wrap = document.getElementById('planningDayCards');
    const grille = document.getElementById('planningTable');
    if (!wrap) return;
    const actif = (vueTech !== null && vueJour !== null);
    wrap.classList.toggle('show', actif);
    grille.style.display = actif ? 'none' : '';
    // body{overflow:hidden} (voir CSS) suppose que .planning-container gère lui-même son propre
    // défilement interne (overflow-y:auto) — l'agenda "cartes" n'a pas cette contrainte de hauteur fixe et
    // peut largement dépasser l'écran (surtout en paysage téléphone, haut très court) : sans autoriser le
    // défilement de la PAGE dans ce mode, le bas de la première tuile et tout le reste restent
    // inaccessibles, sans aucun moyen de les voir.
    document.body.style.overflowY = actif ? 'auto' : '';
    if (!actif) return;

    const tech = vueTech;
    const d = new Date(currentMonday); d.setDate(d.getDate() + vueJour);
    const dStr = `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
    const peutPlanifier = isAdmin || tech === currentUser;

    let html = '';
    const shift = shiftsParCle[`${tech}_${dStr}`];
    const aShift = shift && (shift.poste || shift.astreinte || shift.note || shift.demi_conge || shift.jour_ferie);
    // Calculé avant la carte horaire (et pas seulement pour les cartes de tâches plus bas) : sert aussi
    // aux statistiques "Aujourd'hui" ajoutées dans la carte horaire elle-même.
    const pointagesJour = pointages.filter(p => p.tech === tech && p.date.split(' ')[0] === dStr);

    if (aShift) {
        let libellePoste = '', couleurPoste = '#7f8c8d';
        if (shift.poste && planningPostes[shift.poste]) {
            libellePoste = planningPostes[shift.poste].label + (shift.demi_conge ? ' ½' : '');
            couleurPoste = planningPostes[shift.poste].couleur;
            if (shift.jour_ferie && planningPostes.jour_ferie) libellePoste += ' · ' + planningPostes.jour_ferie.label;
            if (shift.heures !== null && shift.heures !== undefined && shift.heures !== '') libellePoste += ` · ${parseFloat(shift.heures)}h`;
        } else if (shift.demi_conge && planningPostes.demi_cp) {
            libellePoste = planningPostes.demi_cp.label; couleurPoste = planningPostes.demi_cp.couleur;
        } else if (shift.jour_ferie && planningPostes.jour_ferie) {
            libellePoste = planningPostes.jour_ferie.label; couleurPoste = planningPostes.jour_ferie.couleur;
        }
        const astreinte = (shift.astreinte && planningAstreintes[shift.astreinte]) ? planningAstreintes[shift.astreinte] : null;

        // Objectif annualisé : on réutilise EXACTEMENT les fonctions déjà utilisées par la fenêtre
        // "Planning annuel" (getObjectifEffectif, calculerReferenceHeures35h) plutôt que de recalculer ces
        // valeurs différemment ici — même source de vérité, donc toujours cohérent avec le reste de
        // l'appli, fractionnement et semaines à 48h déjà pris en compte.
        const heuresFaitAnnee = totauxAnnualises[tech] || 0;
        const objectifInfo = getObjectifEffectif(tech);
        const objectif = objectifInfo.effectif;
        const referenceH = calculerReferenceHeures35h(tech);
        const ecartRythme = heuresFaitAnnee - referenceH;
        const pctObjectif = objectif > 0 ? Math.round((heuresFaitAnnee / objectif) * 100) : 0;
        const restantLabel = heuresFaitAnnee >= objectif
            ? `+${(heuresFaitAnnee - objectif).toFixed(0)}h`
            : `${(objectif - heuresFaitAnnee).toFixed(0)}h`;
        const ecartClasse = Math.abs(ecartRythme) < 1 ? '' : (ecartRythme > 0 ? 'avance' : 'retard');
        const ecartLabel = Math.abs(ecartRythme) < 1 ? '±0h' : `${ecartRythme > 0 ? '+' : '-'}${Math.abs(ecartRythme).toFixed(0)}h`;
        const pctBarre = Math.min(pctObjectif, 100);
        // Repère 35h/semaine sur la barre (congés déjà posés inclus dans referenceH) : où on devrait en
        // être aujourd'hui si on suivait ce rythme, à comparer visuellement au remplissage réel.
        const pctRepere = objectif > 0 ? Math.min((referenceH / objectif) * 100, 100) : 0;

        html += `
    <div class="pdc-card pdc-shift" id="pdc-shift-card">
        <div class="pdc-accent" style="background:${couleurPoste};"></div>
        ${libellePoste ? `<div class="pdc-shift-poste"><i class="fa-solid fa-clock"></i> ${libellePoste}</div>` : ''}
        ${astreinte ? `<div class="pdc-shift-astreinte" style="--c:${astreinte.couleur}"><i class="fa-solid fa-phone-volume"></i> ${astreinte.label}</div>` : ''}
        ${(shift.note && tech === currentUser) ? `<div class="pdc-shift-note"><i class="fa-solid fa-note-sticky"></i> ${shift.note}</div>` : ''}
        <div class="pdc-obj-row">
            <div class="pdc-obj-stat ${ecartClasse}"><span class="pdc-obj-val">${ecartLabel}</span><span class="pdc-obj-label">${ecartRythme >= 0 ? I18N_PLANNING.avance : I18N_PLANNING.retard}</span></div>
            <div class="pdc-obj-stat"><span class="pdc-obj-val">${heuresFaitAnnee.toFixed(1).replace(/\.0$/, '')}h/${objectif.toFixed(0)}h</span><span class="pdc-obj-label">${I18N_PLANNING.faites}</span></div>
            <div class="pdc-obj-stat"><span class="pdc-obj-val">${restantLabel}</span><span class="pdc-obj-label">${heuresFaitAnnee >= objectif ? I18N_PLANNING.depasse : I18N_PLANNING.restant}</span></div>
        </div>
        <div class="pdc-obj-track-row">
            <div class="pdc-obj-track" title="${I18N_PLANNING.tooltip_repere_35h.replace('{n}', referenceH.toFixed(0)).replace('{jalon}', I18N_PLANNING.a_ce_jour)}">
                <div class="pdc-obj-fill ${pctObjectif > 100 ? 'over' : ''}" style="width:${pctBarre}%;"></div>
                <div class="pdc-obj-marker" style="left:${pctRepere}%;"></div>
            </div>
            <span class="pdc-obj-track-label">${objectif.toFixed(0)}h</span>
        </div>
    </div>`;
    }

    if (!aShift && pointagesJour.length === 0) {
        html += `<div class="pdc-empty"><i class="fa-solid fa-mug-hot"></i> ${I18N_PLANNING.rien_de_prevu}</div>`;
    } else {
        pointagesJour.forEach(ptg => {
            // String(...) : task_id (pointages) et tasks[].id ne sont pas forcément du même type
            // (ID-xxxx généré vs identifiant numérique selon la source), voir aussi plus bas.
            const tk = tasks.find(t => String(t.id) === String(ptg.task_id));
            if (!tk) return;
            const s = (tk.statut || '').toLowerCase();
            let statusClass = s.includes('termin') ? 'termine' : (s.includes('cours') ? 'encours' : 'afaire');
            if (tk.prio === "Urgent" && !s.includes('termin')) statusClass = 'urgent';
            const couleurStatut = statusClass === 'termine' ? LIBELLES.termine.couleur
                : statusClass === 'encours' ? LIBELLES.encours.couleur
                : statusClass === 'urgent' ? (LIBELLES.urgent ? LIBELLES.urgent.couleur : '#e74c3c')
                : LIBELLES.afaire.couleur;
            const heuresJour = parseFloat(ptg.hours || 0);
            const locLigne = (tk.usine || tk.secteur || tk.ligne || tk.zone)
                ? `${tk.usine || '?'} > ${tk.secteur || '?'} > ${tk.ligne ? tk.ligne + ' > ' : ''}${tk.zone || '?'}` : '';
            const dateInterv = tk.date ? tk.date.split(' ')[0].split('-').reverse().join('/') : '';
            const typeInfo = tk.type === 'Préventif' ? { icone: 'fa-calendar-check', couleur: 'var(--accent)' }
                : tk.type === 'Chantier' ? { icone: 'fa-person-digging', couleur: '#f39c12' }
                : { icone: 'fa-screwdriver-wrench', couleur: '#e74c3c' };
            html += `
    <div class="pdc-card pdc-task" data-task-id="${tk.id}">
        <div class="pdc-accent" style="background:${couleurStatut};"></div>
        <div class="pdc-task-top">
            <span class="pdc-task-bi">${tk.num_bi || I18N_PLANNING.prev_fallback}</span>
            <span class="pdc-task-statut" style="color:${couleurStatut};">${tk.statut}${statusClass === 'urgent' ? ' <i class="fa-solid fa-triangle-exclamation"></i>' : ''}</span>
        </div>
        <div class="pdc-task-machine"><i class="fa-solid fa-microchip"></i>${tk.equip || '-'}</div>
        ${locLigne ? `<div class="pdc-task-loc"><i class="fa-solid fa-location-dot"></i> ${locLigne}</div>` : ''}
        <div class="pdc-task-desc">${tk.desc || I18N_PLANNING.aucune_description}</div>
        <div class="pdc-task-meta">
            <span style="color:${typeInfo.couleur};"><i class="fa-solid ${typeInfo.icone}"></i> ${tk.type || I18N_PLANNING.type_curatif}</span>
            ${dateInterv ? `<span><i class="fa-regular fa-calendar"></i> ${dateInterv}</span>` : ''}
        </div>
        <div class="pdc-task-bottom"><i class="fa-solid fa-hourglass-half"></i> ${heuresJour > 0 ? heuresJour + 'h ' + I18N_PLANNING.aujourdhui_suffix : I18N_PLANNING.temps_non_saisi}</div>
    </div>`;
        });
    }

    wrap.innerHTML = html;

    if (aShift && peutPlanifier) {
        document.getElementById('pdc-shift-card').addEventListener('click', () => ouvrirChoixCase(tech, dStr));
    }
    wrap.querySelectorAll('.pdc-task').forEach(cardEl => {
        // String(...) des deux côtés : tk.id passe par un attribut data-* (toujours une chaîne) pour
        // retrouver la tâche après coup, alors que tasks[].id peut être numérique selon la source JSON.
        const tk = tasks.find(t => String(t.id) === cardEl.dataset.taskId);
        if (!tk) return;
        cardEl.addEventListener('click', () => {
            showDetailBI(tk.id);
            let verifCompteur = 0;
            const forceDemandeur = setInterval(() => {
                const repDemandeur = document.getElementById('rep-demandeur') || document.getElementById('rapport-demandeur');
                if (repDemandeur) { repDemandeur.innerText = tk.demandeur || I18N_PLANNING.non_renseigne; clearInterval(forceDemandeur); }
                if (++verifCompteur > 20) clearInterval(forceDemandeur);
            }, 100);
        });
    });
}

// Affiche (ou masque) le sélecteur de technicien sous l'en-tête, et le peuple/synchronise avec vueTech.
// Un technicien non-admin voit son propre nom en lecture seule (le <select> est désactivé : sécurité
// d'affichage, mais surtout cohérence — il n'a de toute façon le droit de modifier que sa propre ligne,
// voir peutPlanifier plus haut) ; un admin peut choisir n'importe qui, y compris lui-même.
function appliquerVisibiliteTech() {
    const switcher = document.getElementById('techSwitcher');
    const select = document.getElementById('techSwitcherSelect');
    const actif = vueTech !== null;
    switcher.classList.toggle('show', actif);
    if (!actif) return;

    if (isAdmin) {
        select.disabled = false;
        select.innerHTML = team.map(t => `<option value="${t}" ${t === vueTech ? 'selected' : ''}>${t === currentUser ? I18N_PLANNING.moi_prefix + ' (' + t + ')' : t}</option>`).join('');
    } else {
        select.disabled = true;
        select.innerHTML = `<option>${currentUser} ${I18N_PLANNING.moi_suffix}</option>`;
    }
}

function selectionnerTech(nom) {
    if (!isAdmin) return; // le <select> est désactivé pour un non-admin, mais on se protège quand même ici
    vueTech = nom;
    renderTable();
}

// Applique (ou retire) la vue "1 jour" sur le tableau déjà rendu : masque les cellules des autres jours,
// réduit la grille à 2 colonnes (voir CSS .mode-jour) et met à jour le sélecteur de jour sous l'en-tête.
function appliquerVisibiliteJours() {
    const container = document.getElementById('planningTable');
    const switcher = document.getElementById('daySwitcher');
    const enModeJour = vueJour !== null;

    container.classList.toggle('mode-jour', enModeJour);
    switcher.classList.toggle('show', enModeJour);

    document.querySelectorAll('#planningTable [data-day-idx]').forEach(el => {
        el.style.display = (!enModeJour || Number(el.dataset.dayIdx) === vueJour) ? '' : 'none';
    });

    if (enModeJour) { renderDaySwitcherPills(); }
}

function renderDaySwitcherPills() {
    const pillsWrap = document.getElementById('daySwitcherPills');
    const todayObj = new Date();
    const todayStr = `${todayObj.getFullYear()}-${String(todayObj.getMonth() + 1).padStart(2, '0')}-${String(todayObj.getDate()).padStart(2, '0')}`;

    pillsWrap.innerHTML = days.map((dayName, idx) => {
        const d = new Date(currentMonday); d.setDate(d.getDate() + idx);
        const dStr = `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
        const classes = ['day-switcher-pill'];
        if (idx === vueJour) classes.push('active');
        if (dStr === todayStr) classes.push('today');
        return `<div class="${classes.join(' ')}" onclick="selectionnerJour(${idx})">
            <span class="dsp-name">${dayName.slice(0, 3)}</span>
            <span class="dsp-date">${String(d.getDate()).padStart(2, '0')}/${String(d.getMonth() + 1).padStart(2, '0')}</span>
        </div>`;
    }).join('');
}

function selectionnerJour(idx) {
    vueJour = idx;
    appliquerVisibiliteJours();
    renderPlanningDayCards();
}

// Jour précédent/suivant : passe à la semaine adjacente en franchissant le lundi/dimanche, comme un
// calendrier continu plutôt que de bloquer aux bornes de la semaine affichée.
function jourAdjacent(dir) {
    let idx = vueJour + dir;
    if (idx < 0) { vueJour = 6; moveWeek(-7); }
    else if (idx > 6) { vueJour = 0; moveWeek(7); }
    else { vueJour = idx; appliquerVisibiliteJours(); renderPlanningDayCards(); }
}

// --- SELECTEUR DE VUE : Jour / Semaine / Mois ---
// Contrairement a vueJour/vueTech (bascule automatique sur petit ecran, voir appliquerModeAffichage),
// modeVue est choisi explicitement par le bouton en haut de page, a n'importe quelle largeur d'ecran.
let modeVue = 'semaine';
let moisTech = null;
let moisCourant = null;

// La navigation "semaine" (fleches sur les bords de l'ecran + petit encadre "Semaine XX" en haut, voir
// .side-nav-btn/.week-nav-center) n'a aucun effet visible sur la vue Mois. La laisser visible pretait a
// confusion : jusqu'a 4 fleches sur l'ecran en meme temps, dont 2 qui ne faisaient rien de constatable
// pendant qu'on regardait un mois. En vue Mois, on bascule vers leur equivalent mois : #moisNavCenter
// (meme classe .week-nav-center, donc meme centrage/style que le bandeau semaine) pour l'encadre du haut,
// et #moisSideLeft/#moisSideRight (memes classes .side-nav-btn/.side-btn-left/.side-btn-right, marquees
// .side-nav-btn-mois pour echapper au toggle "semaine" ci-dessous) pour les fleches sur les bords.
function appliquerVisibiliteNavSemaine() {
    const affiche = (modeVue !== 'mois');
    document.querySelectorAll('.side-nav-btn:not(.side-nav-btn-mois)').forEach(b => b.style.display = affiche ? '' : 'none');
    document.getElementById('weekNavCenter').style.display = affiche ? '' : 'none';
    document.getElementById('moisNavCenter').style.display = affiche ? 'none' : '';
    document.querySelectorAll('.side-nav-btn-mois').forEach(b => b.style.display = affiche ? 'none' : '');
}

function definirModeVue(mode) {
    if (modeVue === mode) return;
    modeVue = mode;
    document.querySelectorAll('.mode-vue-btn').forEach(b => b.classList.toggle('active', b.dataset.mode === mode));
    appliquerVisibiliteNavSemaine();

    if (mode === 'mois') {
        document.getElementById('planningTable').style.display = 'none';
        document.getElementById('daySwitcher').classList.remove('show');
        document.getElementById('moisContainer').classList.add('show');
        if (!moisTech) { moisTech = currentUser; }
        if (!moisCourant) { moisCourant = new Date(currentMonday.getFullYear(), currentMonday.getMonth(), 1); }
        renderMoisTechSelect();
        renderMoisView();
        return;
    }

    document.getElementById('moisContainer').classList.remove('show');
    document.getElementById('planningTable').style.display = '';

    if (mode === 'jour') {
        if (vueJour === null) {
            const todayObj = new Date();
            const diffJours = Math.round((todayObj - currentMonday) / 86400000);
            vueJour = (diffJours >= 0 && diffJours <= 6) ? diffJours : 0;
        }
    } else {
        vueJour = null;
    }
    renderTable();
}

function libelleTech(t) {
    return t === currentUser ? `${I18N_PLANNING.moi_prefix} (${t})` : t;
}

function urlAvatarTech(t) {
    return teamPhotos[t] || 'img/user.png';
}

// Menu deroulant maison (avatar + nom par ligne, coche sur le technicien choisi) : reconstruit a chaque
// changement de technicien pour que la coche/le fond vert suivent la selection.
function renderMoisTechSelect() {
    const panel = document.getElementById('techDropdownPanel');
    panel.innerHTML = team.map(t => `
        <div class="tech-dropdown-item${t === moisTech ? ' selected' : ''}" onclick="selectionnerMoisTech('${t}')">
            <div class="tech-dropdown-item-avatar"><img src="${urlAvatarTech(t)}" onerror="this.onerror=null; this.src='https://api.dicebear.com/7.x/initials/svg?seed=${t}';"></div>
            <div class="tech-dropdown-item-name">${libelleTech(t)}</div>
            <i class="fa-solid fa-check tech-dropdown-item-check"></i>
        </div>`).join('');
    document.getElementById('moisTechName').textContent = libelleTech(moisTech);
    mettreAJourAvatarMoisTech();
}

// Meme logique que l'avatar de la ligne technicien en vue Semaine (teamPhotos + repli dicebear).
function mettreAJourAvatarMoisTech() {
    const img = document.getElementById('moisTechAvatar');
    if (!img) return;
    img.onerror = () => { img.onerror = null; img.src = `https://api.dicebear.com/7.x/initials/svg?seed=${moisTech}`; };
    img.src = urlAvatarTech(moisTech);
}

function toggleTechDropdown(forcerEtat) {
    const el = document.getElementById('techDropdown');
    const ouvrir = (forcerEtat !== undefined) ? forcerEtat : !el.classList.contains('open');
    el.classList.toggle('open', ouvrir);
}

// Clic en dehors du menu = fermeture, comme n'importe quel menu deroulant standard.
document.addEventListener('click', (e) => {
    const dd = document.getElementById('techDropdown');
    if (dd && dd.classList.contains('open') && !dd.contains(e.target)) { toggleTechDropdown(false); }
});

function selectionnerMoisTech(nom) {
    moisTech = nom;
    renderMoisTechSelect();
    toggleTechDropdown(false);
    renderMoisView();
}

function changerMois(dir) {
    moisCourant.setMonth(moisCourant.getMonth() + dir);
    renderMoisView();
}

// Grille de type calendrier (6 semaines fixes, lundi->dimanche) pour un seul technicien a la fois : lit
// les memes donnees deja chargees en page (shiftsParCle, planningPostes) que la vue Semaine, pas de
// nouvel appel reseau. Un clic sur une case ouvre la meme modale de saisie que les autres vues.
function renderMoisView() {
    const grid = document.getElementById('moisGrid');
    const label = document.getElementById('moisNavLabel');
    label.textContent = moisCourant.toLocaleDateString(JS_LOCALE, { month: 'long', year: 'numeric' });

    const todayObj = new Date();
    const todayStr = `${todayObj.getFullYear()}-${String(todayObj.getMonth() + 1).padStart(2, '0')}-${String(todayObj.getDate()).padStart(2, '0')}`;

    const premier = new Date(moisCourant.getFullYear(), moisCourant.getMonth(), 1);
    const dernier = new Date(moisCourant.getFullYear(), moisCourant.getMonth() + 1, 0);
    document.getElementById('moisDateRange').innerText = `${I18N_PLANNING.du} ${premier.toLocaleDateString(JS_LOCALE, {day:'numeric', month:'short'})} ${I18N_PLANNING.au_connector} ${dernier.toLocaleDateString(JS_LOCALE, {day:'numeric', month:'short', year:'numeric'})}`;
    const decalage = (premier.getDay() + 6) % 7; // 0 = lundi
    const debutGrille = new Date(premier);
    debutGrille.setDate(debutGrille.getDate() - decalage);

    let html = days.map(d => `<div class="mois-jour-nom">${d.slice(0, 3)}</div>`).join('');

    // Meme regle que la vue Semaine (voir peutPlanifier dans renderTable) : un admin peut planifier
    // n'importe qui, un technicien seulement sa propre ligne — ici, comme la vue Mois n'affiche qu'un seul
    // technicien a la fois, ca revient a n'autoriser le glisser-deposer que si c'est SON propre calendrier.
    const peutPlanifierMois = isAdmin || moisTech === currentUser;

    for (let i = 0; i < 42; i++) {
        const d = new Date(debutGrille);
        d.setDate(d.getDate() + i);
        const dStr = `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
        const horsMois = d.getMonth() !== moisCourant.getMonth();
        const classes = ['mois-case'];
        if (horsMois) classes.push('hors-mois');
        if (dStr === todayStr) classes.push('aujourdhui');
        // Derniere ligne de la grille (i>=35) : la tuile d'info (.task-tooltip) s'ouvre vers le haut,
        // meme mecanique de retournement que .tech-row-last en vue Semaine, sinon elle sort en bas de l'ecran.
        if (i >= 35) classes.push('tech-row-last');
        // Premiere/derniere colonne (lundi/dimanche) : voir .edge-col-first/.edge-col-last plus haut,
        // sinon la tuile de 220px centree deborde du cadre du calendrier sur les bords.
        const colonne = i % 7;
        if (colonne === 0) classes.push('edge-col-first');
        if (colonne === 6) classes.push('edge-col-last');

        let badge = '';
        if (!horsMois) {
            const shift = shiftsParCle[`${moisTech}_${dStr}`];
            if (shift) {
                let libelle = '', couleur = '';
                if (shift.poste && planningPostes[shift.poste]) {
                    libelle = planningPostes[shift.poste].label;
                    couleur = planningPostes[shift.poste].couleur;
                } else if (shift.demi_conge && planningPostes.demi_cp) {
                    libelle = planningPostes.demi_cp.label;
                    couleur = planningPostes.demi_cp.couleur;
                } else if (shift.jour_ferie && planningPostes.jour_ferie) {
                    libelle = planningPostes.jour_ferie.label;
                    couleur = planningPostes.jour_ferie.couleur;
                }
                if (libelle) { badge = `<div class="shift-badge-compact" style="--c:${couleur}">${libelle}</div>`; }
            }
            // Todo list du jour (voir #todoModal) : même pastille que la vue Semaine, juste à côté du
            // badge de poste plutôt que dans sa propre ligne (la case du mois est plus étroite).
            const todoItemsJourMois = todoParCle[`${moisTech}_${dStr}`] || [];
            if (todoItemsJourMois.length > 0) { badge += todoBadgeHtml(moisTech, dStr, todoItemsJourMois); }
        }

        // Bons d'intervention : meme filtre que la vue Semaine (un pointage du technicien ce jour-la sur
        // la tache), meme code couleur par statut (voir .status-* + task-tooltip plus haut). Glisser-deposer
        // vers un autre jour : meme mecanique que la vue Semaine (handleDrop), juste reconnectee a une case
        // du mois plutot qu'une case de la grille semaine.
        let tachesHtml = '';
        if (!horsMois) {
            const chips = tasks.filter(tk => pointages.some(p => p.task_id === tk.id && p.tech === moisTech && p.date.split(' ')[0] === dStr))
                .map(tk => {
                    const s = (tk.statut || '').toLowerCase();
                    let statusClass = s.includes('termin') ? 'termine' : (s.includes('cours') ? 'encours' : 'afaire');
                    if (tk.prio === 'Urgent' && !s.includes('termin')) statusClass = 'urgent';
                    const numBadge = tk.num_bi ? tk.num_bi : I18N_PLANNING.prev_fallback;
                    const ptgDuJour = pointages.find(p => p.task_id === tk.id && p.tech === moisTech && p.date.split(' ')[0] === dStr);
                    const dragAttrs = peutPlanifierMois
                        ? ` draggable="true" ondragstart="draggedTaskId='${tk.id}'; event.dataTransfer.setData('taskId','${tk.id}');${ptgDuJour ? ` event.dataTransfer.setData('pointageId','${ptgDuJour.id}');` : ''}"`
                        : '';
                    // Meme tuile d'info qu'en vue Semaine (voir .task-tooltip plus haut) : temps reel
                    // pointe (toutes dates confondues sur ce BI) si dispo, sinon temps prevu.
                    const totalHeuresReelles = pointages.filter(p => p.task_id === tk.id).reduce((acc, p) => acc + parseFloat(p.hours || 0), 0);
                    const affichageTemps = totalHeuresReelles > 0
                        ? `<span style="color:#64b5f6; font-weight:900;">${totalHeuresReelles.toFixed(1)}h (R)</span>`
                        : `<span style="color:#ffb74d; font-weight:900;">${parseFloat(tk.hours || 0).toFixed(1)}h (P)</span>`;
                    return `<div class="task-badge-compact status-${statusClass}"${dragAttrs} onclick="event.stopPropagation(); ouvrirDetailBIParId('${tk.id}')">
                        <span style="pointer-events:none;">${numBadge}</span>
                        <div class="task-tooltip">
                            <div style="font-family:'Caveat', cursive; font-size:1.4rem; color:var(--brand-orange); margin-bottom:5px; line-height:1;">${tk.equip}</div>
                            <div style="font-size:0.75rem; color:#e2e8f0; margin-bottom:10px; line-height:1.3; overflow:hidden; display:-webkit-box; -webkit-line-clamp:3; -webkit-box-orient:vertical;">${tk.desc || I18N_PLANNING.aucune_description}</div>
                            <div style="display:flex; justify-content:space-between; border-top:1px solid rgba(255,255,255,0.1); padding-top:8px; font-size:0.7rem;">
                                <span>${affichageTemps}</span>
                                <span style="text-transform:uppercase; font-weight:900; color:${statusClass==='termine'?LIBELLES.termine.couleur:(statusClass==='encours'?LIBELLES.encours.couleur:LIBELLES.afaire.couleur)}">${tk.statut}</span>
                            </div>
                        </div>
                    </div>`;
                }).join('');
            if (chips) { tachesHtml = `<div class="mois-case-tasks">${chips}</div>`; }
        }

        const peutPlanifier = !horsMois && peutPlanifierMois;
        const dropAttrs = peutPlanifier
            ? ` ondragover="event.preventDefault();" ondragenter="this.classList.add('drag-over');" ondragleave="this.classList.remove('drag-over');" ondrop="this.classList.remove('drag-over'); handleDrop(event, '${moisTech}', '${dStr}');"`
            : '';
        html += `<div class="${classes.join(' ')}"${peutPlanifier ? ` onclick="ouvrirChoixCase('${moisTech}', '${dStr}')"` : ''}${dropAttrs}>
            <div class="mois-case-num">${d.getDate()}</div>
            ${badge}
            ${tachesHtml}
        </div>`;
    }

    grid.innerHTML = html;
}

function openModal(tk) {
    document.getElementById('m-id').value = tk.id;
    document.getElementById('m-equip').value = tk.equip;
    document.getElementById('m-desc').value = tk.desc || "";
    document.getElementById('m-hours').value = tk.hours;
    document.getElementById('m-date').value = tk.date;
    document.getElementById('m-prio').value = tk.prio || "Normal";
    document.getElementById('m-statut').value = tk.statut || "À faire";
    document.getElementById('m-type').value = tk.type || "Curatif";
    document.getElementById('m-casse').checked = (tk.casse === true || tk.casse === "1" || tk.casse === "on");
    
    // Remplissage dynamique des deux listes (Demandeur et Technicien) depuis la table utilisateurs de ta BDD
    document.getElementById('m-demandeur').innerHTML = `<option value="">${I18N_PLANNING.select_defaut}</option>` + team.map(t => `<option value="${t}" ${t == tk.demandeur ? 'selected' : ''}>${t}</option>`).join('');
    document.getElementById('m-tech').innerHTML = team.map(t => `<option value="${t}" ${t == tk.tech ? 'selected' : ''}>${t}</option>`).join('');
    
    // --- Gestion de l'affichage Sous-traitant ---
    document.getElementById('m-is-st').checked = (tk.is_sous_traitant == 1 || tk.is_sous_traitant === true || tk.is_sous_traitant === "1");
    toggleST();
    document.getElementById('m-entreprise').innerHTML = entreprisesList.map(e => `<option value="${e.id}" ${e.id == tk.entreprise_ext_id ? 'selected' : ''}>${e.nom}</option>`).join('');
    
    document.getElementById('editModal').style.display = "block";
}

function closeModal() { document.getElementById('editModal').style.display = "none"; }

// --- FENÊTRE DE PLANIFICATION (POSTE / ASTREINTE / HEURES) ---
let shiftTech = null;
let shiftDate = null;
let shiftPoste = null;
let shiftAstreinte = null;
let shiftDemiConge = false;
let shiftJourFerie = false;
// Note personnelle : masquée dans la modale quand un admin ouvre la case d'un autre technicien
// depuis le planning journalier/hebdomadaire (elle ne concerne que son auteur). shiftNoteExistante
// garde la vraie valeur en mémoire pour que saveShift() ne l'écrase pas par erreur avec un champ
// vide non affiché ; shiftNoteVisible dit si le champ affiché à l'écran fait foi ou non.
let shiftNoteExistante = '';
let shiftNoteVisible = true;
let annualModalTech = null;

// --- ZOOM DU CALENDRIER ANNUEL (voir CSS .annual-grid-wrapper / #annual-grid) ---
const ANNUAL_ZOOM_PALIERS = [0.8, 0.9, 1, 1.15, 1.3, 1.5, 1.75, 2];
let annualZoom = 1;

function zoomAnnuel(dir) {
    const idx = ANNUAL_ZOOM_PALIERS.indexOf(annualZoom);
    const newIdx = Math.max(0, Math.min(ANNUAL_ZOOM_PALIERS.length - 1, (idx === -1 ? 2 : idx) + dir));
    annualZoom = ANNUAL_ZOOM_PALIERS[newIdx];
    appliquerZoomAnnuel();
}

function appliquerZoomAnnuel() {
    const grid = document.getElementById('annual-grid');
    const wrapper = document.getElementById('annual-grid-wrapper');
    if (!grid || !wrapper) return;
    grid.style.transform = `scale(${annualZoom})`;
    if (annualZoom === 1) {
        wrapper.style.width = '';
        wrapper.style.height = '';
    } else {
        // transform ne change pas scrollWidth/scrollHeight (mesurés à l'échelle 1) : on agrandit donc
        // explicitement le conteneur défilant à la taille visuelle réelle du contenu zoomé.
        wrapper.style.width = (grid.scrollWidth * annualZoom) + 'px';
        wrapper.style.height = (grid.scrollHeight * annualZoom) + 'px';
    }
    document.getElementById('annual-zoom-label').textContent = Math.round(annualZoom * 100) + '%';
}

// --- VUE MENSUELLE MOBILE/TABLETTE DU PLANNING ANNUEL ---
// Par défaut sur petit écran (voir ecranEtroit()), on n'affiche qu'un seul mois à la fois — beaucoup
// plus lisible que la grille dense pensée pour desktop — avec un bouton pour repasser à la vue
// d'ensemble. renderAnnualGrid() reconstruit #annual-grid depuis zéro (ouverture de la modale, mais
// aussi après modification de l'objectif/du fractionnement) : c'est pourquoi appliquerVueAnnuelleMobile()
// est rappelée à la fin de cette fonction plutôt qu'une seule fois à l'ouverture.
let annualViewMode = 'global'; // 'global' (grille des 12 mois, comportement historique) | 'mensuel'
let annualActiveMonthIndex = null; // 0-11, position du mois affiché dans la période avril→mars

function indexMoisCourantAnnuel() {
    const debut = new Date(periodeAnnualisationDebut + 'T00:00:00');
    const fin = new Date(periodeAnnualisationFin + 'T00:00:00');
    const today = new Date();
    if (today <= debut) return 0;
    if (today >= fin) return 11;
    return (today.getFullYear() - debut.getFullYear()) * 12 + (today.getMonth() - debut.getMonth());
}

function annualMonthNav(dir) {
    const cards = document.querySelectorAll('#annual-grid .annual-month-card');
    if (!cards.length) return;
    annualActiveMonthIndex = Math.max(0, Math.min(cards.length - 1, (annualActiveMonthIndex ?? 0) + dir));
    appliquerVueAnnuelleMobile();
}

function toggleAnnualViewMode() {
    annualViewMode = (annualViewMode === 'mensuel') ? 'global' : 'mensuel';
    if (annualViewMode === 'mensuel') { annualZoom = 1; appliquerZoomAnnuel(); }
    appliquerVueAnnuelleMobile();
}

function appliquerVueAnnuelleMobile() {
    const grid = document.getElementById('annual-grid');
    const nav = document.getElementById('annual-mobile-nav');
    if (!grid || !nav) return;

    const cards = grid.querySelectorAll('.annual-month-card');
    if (!cards.length) return;
    if (annualActiveMonthIndex === null) { annualActiveMonthIndex = indexMoisCourantAnnuel(); }
    annualActiveMonthIndex = Math.max(0, Math.min(cards.length - 1, annualActiveMonthIndex));

    const enMensuel = ecranEtroit() && annualViewMode === 'mensuel';
    grid.classList.toggle('mode-mensuel', enMensuel);
    cards.forEach((card, i) => card.classList.toggle('is-active-month', i === annualActiveMonthIndex));

    const zoomControls = document.querySelector('.annual-zoom-controls');
    if (zoomControls) zoomControls.style.display = enMensuel ? 'none' : '';

    nav.classList.toggle('mode-global', annualViewMode === 'global');
    const prevBtn = document.getElementById('annual-mnav-prev');
    const nextBtn = document.getElementById('annual-mnav-next');
    const label = document.getElementById('annual-mnav-label');
    if (prevBtn) prevBtn.disabled = (annualActiveMonthIndex === 0);
    if (nextBtn) nextBtn.disabled = (annualActiveMonthIndex === cards.length - 1);
    if (label) label.textContent = cards[annualActiveMonthIndex].querySelector('.annual-month-header').textContent;

    const toggleLabel = document.getElementById('annual-mnav-toggle-label');
    if (toggleLabel) toggleLabel.textContent = (annualViewMode === 'mensuel') ? I18N_PLANNING.vue_ensemble : I18N_PLANNING.vue_mensuelle;
    const toggleIcon = document.querySelector('#annual-mnav-toggle i');
    if (toggleIcon) toggleIcon.className = (annualViewMode === 'mensuel') ? 'fa-solid fa-table-cells' : 'fa-solid fa-calendar-day';
}

let derniereAnalyseEcart = null;
let bulkPoste = null;
let bulkAstreinte = null;
let bulkDemiConge = false;
let bulkJourFerie = false;
let topModalZ = 10000;
function bringModalToFront(id) {
    topModalZ += 10;
    document.getElementById(id).style.zIndex = topModalZ;
}

// --- CHOIX "PLANIFIER LES HEURES / CRÉER UN BI" AU CLIC SUR UNE CASE ---
// Point d'entrée commun aux vues Jour, Semaine et Mois (voir ouvrirChoixCase dans renderTable,
// renderPlanningDayCards et renderMoisView) : avant d'ouvrir la modale d'heures, on laisse choisir si le
// clic concerne la planification (openShiftModal, inchangé) ou la déclaration d'un nouveau BI sur cette
// case — qui n'existait pas depuis le planning, uniquement depuis Saisie & Historique.
let choixCaseTech = null;
let choixCaseDate = null;

function ouvrirChoixCase(tech, dateStr) {
    choixCaseTech = tech;
    choixCaseDate = dateStr;
    const dateLabel = new Date(dateStr + 'T00:00:00').toLocaleDateString(JS_LOCALE, { weekday: 'long', day: 'numeric', month: 'long' });
    document.getElementById('choix-case-sous-titre').textContent = `${libelleTech(tech)} — ${dateLabel}`;
    document.getElementById('choixCaseModal').style.display = 'block';
}

function closeChoixCase() {
    document.getElementById('choixCaseModal').style.display = 'none';
}

function choisirPlanifierHeures() {
    const tech = choixCaseTech, dateStr = choixCaseDate;
    closeChoixCase();
    openShiftModal(tech, dateStr);
}

// Renvoie vers Saisie & Historique, qui ouvre l'assistant de création pré-rempli (technicien + date de
// la case) — voir ouvrirWizardOTPourPlanning dans maintenance.php, même principe que
// ouvrirWizardOTPourMachine pour le Parc Machine.
function choisirCreerBI() {
    const tech = choixCaseTech, dateStr = choixCaseDate;
    closeChoixCase();
    window.location.href = 'maintenance.php?creer_bi_planning=1&tech=' + encodeURIComponent(tech) + '&date=' + encodeURIComponent(dateStr);
}

// --- TODO LIST PAR JOUR (voir #todoModal) : accessible depuis le choix de case comme les deux options
// ci-dessus. Une tâche non cochée à la date du jour se reporte automatiquement au lendemain (voir
// get_todo côté maintenance.php, qui fait ce report avant de répondre) — pas besoin d'y penser ici.
let todoModalTech = null;
let todoModalDate = null;

// Pastille "il y a une todo list ce jour-là" apposée sur une case du planning (vues Semaine et Mois,
// voir renderTable/renderMoisView) : le nombre de tâches restantes, ou une coche si tout est fait. Un
// clic ouvre directement la liste, sans repasser par le choix de case (stopPropagation pour ne pas
// aussi déclencher l'ouverture de cette dernière).
function todoBadgeHtml(tech, dateStr, items) {
    const nbRestant = items.filter(i => i.fait != 1).length;
    const contenu = nbRestant > 0 ? `<i class="fa-solid fa-list-check"></i> ${nbRestant}` : `<i class="fa-solid fa-circle-check"></i>`;
    const titre = items.map(i => `${i.fait == 1 ? '✓' : '•'} ${i.texte}`).join('\n').replace(/"/g, '&quot;');
    return `<span class="todo-cell-badge${nbRestant === 0 ? ' tout-fait' : ''}" title="${titre}" onclick="event.stopPropagation(); ouvrirTodoModal('${tech}', '${dateStr}')">${contenu}</span>`;
}

function choisirTodoList() {
    const tech = choixCaseTech, dateStr = choixCaseDate;
    closeChoixCase();
    ouvrirTodoModal(tech, dateStr);
}

function ouvrirTodoModal(tech, dateStr) {
    todoModalTech = tech;
    todoModalDate = dateStr;
    const dateLabel = new Date(dateStr + 'T00:00:00').toLocaleDateString(JS_LOCALE, { weekday: 'long', day: 'numeric', month: 'long' });
    document.getElementById('todo-modal-titre').textContent = `${I18N_PLANNING.choix_todo_list} — ${libelleTech(tech)}`;
    document.getElementById('todo-modal-date').textContent = dateLabel;
    document.getElementById('todo-new-texte').value = '';
    renderTodoItems();
    document.getElementById('todoModal').style.display = 'block';
}

function closeTodoModal() {
    document.getElementById('todoModal').style.display = 'none';
}

function renderTodoItems() {
    const key = `${todoModalTech}_${todoModalDate}`;
    const items = (todoParCle[key] || []).slice().sort((a, b) => a.id - b.id);
    const wrap = document.getElementById('todo-items-list');
    if (items.length === 0) {
        wrap.innerHTML = `<div class="todo-empty-state">${I18N_PLANNING.todo_vide}</div>`;
        return;
    }
    wrap.innerHTML = items.map(item => `
        <div class="todo-item ${(item.fait == 1) ? 'is-fait' : ''}">
            <input type="checkbox" ${(item.fait == 1) ? 'checked' : ''} onchange="toggleTodoItem(${item.id}, this.checked)">
            <span class="todo-item-texte">${(item.texte || '').toString().replace(/</g, '&lt;')}</span>
            <i class="fa-solid fa-trash todo-item-del" onclick="supprimerTodoItem(${item.id})"></i>
        </div>`).join('');
}

async function ajouterTodoItem() {
    const input = document.getElementById('todo-new-texte');
    const texte = input.value.trim();
    if (!texte) return;
    const fd = new FormData();
    fd.append('action', 'todo_add');
    fd.append('tech', todoModalTech);
    fd.append('date', todoModalDate);
    fd.append('texte', texte);
    const res = await fetch('maintenance.php', { method: 'POST', body: fd });
    const data = await res.json().catch(() => null);
    if (!data || !data.id) return;
    const key = `${todoModalTech}_${todoModalDate}`;
    if (!todoParCle[key]) todoParCle[key] = [];
    todoParCle[key].push({ id: data.id, utilisateur: todoModalTech, jour: todoModalDate, texte: texte, fait: 0 });
    input.value = '';
    renderTodoItems();
}

async function toggleTodoItem(id, coche) {
    const fd = new FormData();
    fd.append('action', 'todo_toggle');
    fd.append('id', id);
    fd.append('fait', coche ? '1' : '0');
    await fetch('maintenance.php', { method: 'POST', body: fd });
    const key = `${todoModalTech}_${todoModalDate}`;
    const item = (todoParCle[key] || []).find(i => i.id == id);
    if (item) item.fait = coche ? 1 : 0;
    renderTodoItems();
}

async function supprimerTodoItem(id) {
    const ok = await aspirineConfirm(I18N_PLANNING.confirmation_title, I18N_PLANNING.todo_confirm_suppr_msg);
    if (!ok) return;
    const fd = new FormData();
    fd.append('action', 'todo_delete');
    fd.append('id', id);
    await fetch('maintenance.php', { method: 'POST', body: fd });
    const key = `${todoModalTech}_${todoModalDate}`;
    todoParCle[key] = (todoParCle[key] || []).filter(i => i.id != id);
    renderTodoItems();
}

function openShiftModal(tech, dateStr, depuisAnnuel) {
    if (!isAdmin && tech !== currentUser) return;
    shiftTech = tech;
    shiftDate = dateStr;
    const existingRecord = shiftsParCle[`${tech}_${dateStr}`];
    const existing = existingRecord || {};
    shiftPoste = existing.poste || null;
    shiftAstreinte = existing.astreinte || null;
    shiftDemiConge = !!existing.demi_conge;
    // Jour férié français pré-coché automatiquement tant qu'aucune ligne significative n'a encore été
    // enregistrée pour ce jour (une ligne vide résiduelle compte comme "rien") ; dès qu'une vraie saisie
    // existe, sa valeur cochée/décochée fait foi, y compris si quelqu'un la décoche volontairement pour un
    // jour férié travaillé sans le signaler.
    shiftJourFerie = ligneVide(existingRecord) ? estJourFerieFrance(dateStr) : !!existing.jour_ferie;

    document.getElementById('shift-modal-title').textContent = I18N_PLANNING.planifier_tech.replace('{tech}', tech);
    const dObj = new Date(dateStr + 'T00:00:00');
    document.getElementById('shift-modal-date').textContent = dObj.toLocaleDateString(JS_LOCALE, { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' });
    document.getElementById('shift-heures').value = (existing.heures !== undefined && existing.heures !== null) ? existing.heures : '';
    // Note personnelle : un admin qui ouvre la case d'un AUTRE technicien depuis le planning
    // journalier/hebdomadaire ne doit ni la voir ni pouvoir l'écraser en enregistrant autre chose
    // sur cette case (poste, heures...) ; depuis le planning annuel (depuisAnnuel=true), il peut la
    // consulter normalement. shiftNoteExistante garde la vraie valeur pour saveShift().
    shiftNoteExistante = existing.note || '';
    shiftNoteVisible = (tech === currentUser) || !!depuisAnnuel;
    document.getElementById('shift-note-section').style.display = shiftNoteVisible ? '' : 'none';
    document.getElementById('shift-note-hidden-msg').style.display = shiftNoteVisible ? 'none' : '';
    document.getElementById('shift-note').value = shiftNoteVisible ? shiftNoteExistante : '';
    // Période affichée figée du début de l'annualisation jusqu'au jour cliqué (pas la période complète) :
    // l'encadré ci-dessous montre l'avancement tel qu'il était à cette date-là, pas l'état actuel.
    const debutLabel = new Date(periodeAnnualisationDebut + 'T00:00:00').toLocaleDateString('fr-FR');
    document.getElementById('shift-total-periode').textContent = `${debutLabel} au ${dObj.toLocaleDateString('fr-FR')}`;

    renderShiftBiList(tech, dateStr);
    renderShiftChips();
    updateShiftTotalPreview();
    bringModalToFront('shiftModal');
    document.getElementById('shiftModal').style.display = 'block';
}

function closeShiftModal() {
    document.getElementById('shiftModal').style.display = 'none';
}

function renderShiftBiList(tech, dateStr) {
    const container = document.getElementById('shift-bi-list');
    container.innerHTML = '';

    const biDuJour = tasks.filter(tk => pointages.some(p =>
        p.task_id === tk.id && p.tech === tech && p.date.split(' ')[0] === dateStr
    ));

    if (biDuJour.length === 0) {
        container.innerHTML = `<div class="shift-bi-empty">${I18N_PLANNING.aucun_bi_ce_jour}</div>`;
        return;
    }

    biDuJour.forEach(tk => {
        const s = (tk.statut || '').toLowerCase();
        let statusClass = s.includes('termin') ? 'termine' : (s.includes('cours') ? 'encours' : 'afaire');
        const couleur = LIBELLES[statusClass] ? LIBELLES[statusClass].couleur : '#95a5a6';

        const row = document.createElement('div');
        row.className = 'shift-bi-row';
        row.innerHTML = `
            <span class="shift-bi-num">#${tk.num_bi || I18N_PLANNING.prev_fallback}</span>
            <div class="shift-bi-info">
                <div class="shift-bi-equip">${tk.equip || ''}</div>
                <div class="shift-bi-desc">${tk.desc || I18N_PLANNING.aucune_description_sans_point}</div>
            </div>
            <span class="shift-bi-statut" style="background:${couleur};">${tk.statut}</span>
        `;
        row.addEventListener('click', () => { closeShiftModal(); showDetailBI(tk.id); });
        container.appendChild(row);
    });
}

function renderShiftChips() {
    const posteGroup = document.getElementById('shift-poste-group');
    posteGroup.innerHTML = '';
    Object.values(planningPostes).filter(p => p.categorie !== 'evenement' && p.cle !== 'jour_ferie').forEach(p => {
        posteGroup.appendChild(buildShiftChip(p, shiftPoste === p.cle, () => {
            shiftPoste = (shiftPoste === p.cle) ? null : p.cle;
            renderShiftChips();
            updateShiftTotalPreview();
        }));
    });
    const evenementGroup = document.getElementById('shift-evenement-group');
    evenementGroup.innerHTML = '';
    // "Demi-congé payé" et "Jour férié" sont à part : contrairement aux autres événements (pleine journée),
    // ils peuvent se cumuler avec un poste (matin/après-midi/nuit/journée) pour signaler qu'une partie de
    // la journée — ou sa totalité — a quand même été travaillée un jour férié — ce sont donc des cases à
    // cocher indépendantes, pas un choix exclusif avec les postes.
    // Ordre d'affichage volontairement fixé (grille 2 colonnes) : 1re ligne Demi-congé/Congé payé,
    // 2e ligne Jour férié/RTT, 3e ligne Maladie — plutôt que l'ordre brut de la table planning_postes.
    const addPosteEvenementChip = (cle) => {
        const p = planningPostes[cle];
        if (!p) return;
        evenementGroup.appendChild(buildShiftChip(p, shiftPoste === p.cle, () => {
            shiftPoste = (shiftPoste === p.cle) ? null : p.cle;
            // Choisir congé/RTT/maladie désactive un demi-congé ou un jour férié déjà coché.
            if (shiftPoste && ['cp', 'rtt', 'maladie', 'repos'].includes(shiftPoste)) { shiftDemiConge = false; shiftJourFerie = false; }
            renderShiftChips();
            updateShiftTotalPreview();
        }));
    };
    if (planningPostes.demi_cp) {
        evenementGroup.appendChild(buildShiftChip(planningPostes.demi_cp, shiftDemiConge, () => {
            shiftDemiConge = !shiftDemiConge;
            // Le demi-congé ne peut se cumuler qu'avec un poste réellement travaillé (matin/après-midi/
            // nuit/journée), pas avec un événement pleine journée (congé/RTT/maladie) — ça n'aurait pas
            // de sens de dire "moitié congé" sur un jour déjà entièrement absent.
            if (shiftDemiConge && ['cp', 'rtt', 'maladie', 'repos'].includes(shiftPoste)) { shiftPoste = null; }
            renderShiftChips();
            updateShiftTotalPreview();
        }));
    }
    addPosteEvenementChip('cp');
    if (planningPostes.jour_ferie) {
        evenementGroup.appendChild(buildShiftChip(planningPostes.jour_ferie, shiftJourFerie, () => {
            shiftJourFerie = !shiftJourFerie;
            // Même règle que le demi-congé : "jour férié" se cumule avec un poste réellement travaillé,
            // pas avec une absence déjà encodée en poste (congé/RTT/maladie), qui n'a pas besoin d'être
            // en plus marquée "férié" puisqu'elle compte déjà pour 0h dû.
            if (shiftJourFerie && ['cp', 'rtt', 'maladie', 'repos'].includes(shiftPoste)) { shiftPoste = null; }
            renderShiftChips();
            updateShiftTotalPreview();
        }));
    }
    addPosteEvenementChip('rtt');
    addPosteEvenementChip('maladie');
    addPosteEvenementChip('repos');
    const astGroup = document.getElementById('shift-astreinte-group');
    astGroup.innerHTML = '';
    Object.values(planningAstreintes).forEach(a => {
        astGroup.appendChild(buildShiftChip(a, shiftAstreinte === a.cle, () => {
            shiftAstreinte = (shiftAstreinte === a.cle) ? null : a.cle;
            renderShiftChips();
        }));
    });
}

function buildShiftChip(item, active, onClick) {
    const btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'shift-chip';
    btn.textContent = item.label;
    if (active) {
        btn.style.background = item.couleur;
        btn.style.borderColor = item.couleur;
        btn.style.color = texteContrasteSur(item.couleur);
    }
    btn.addEventListener('click', onClick);
    return btn;
}

// Même logique de chips que renderShiftChips(), mais branchée sur les variables bulk* (état séparé, un
// technicien/jour n'est écrasé qu'au moment d'Appliquer, pas pendant que l'admin compose son remplissage).
function renderBulkChips() {
    const posteGroup = document.getElementById('bulk-poste-group');
    posteGroup.innerHTML = '';
    Object.values(planningPostes).filter(p => p.categorie !== 'evenement' && p.cle !== 'jour_ferie').forEach(p => {
        posteGroup.appendChild(buildShiftChip(p, bulkPoste === p.cle, () => {
            bulkPoste = (bulkPoste === p.cle) ? null : p.cle;
            renderBulkChips();
        }));
    });
    const evenementGroup = document.getElementById('bulk-evenement-group');
    evenementGroup.innerHTML = '';
    if (planningPostes.demi_cp) {
        evenementGroup.appendChild(buildShiftChip(planningPostes.demi_cp, bulkDemiConge, () => {
            bulkDemiConge = !bulkDemiConge;
            if (bulkDemiConge && ['cp', 'rtt', 'maladie', 'repos'].includes(bulkPoste)) { bulkPoste = null; }
            renderBulkChips();
        }));
    }
    const addPosteEvenementChipBulk = (cle) => {
        const p = planningPostes[cle];
        if (!p) return;
        evenementGroup.appendChild(buildShiftChip(p, bulkPoste === p.cle, () => {
            bulkPoste = (bulkPoste === p.cle) ? null : p.cle;
            if (bulkPoste && ['cp', 'rtt', 'maladie', 'repos'].includes(bulkPoste)) { bulkDemiConge = false; bulkJourFerie = false; }
            renderBulkChips();
        }));
    };
    addPosteEvenementChipBulk('cp');
    if (planningPostes.jour_ferie) {
        evenementGroup.appendChild(buildShiftChip(planningPostes.jour_ferie, bulkJourFerie, () => {
            bulkJourFerie = !bulkJourFerie;
            if (bulkJourFerie && ['cp', 'rtt', 'maladie', 'repos'].includes(bulkPoste)) { bulkPoste = null; }
            renderBulkChips();
        }));
    }
    addPosteEvenementChipBulk('rtt');
    addPosteEvenementChipBulk('maladie');
    addPosteEvenementChipBulk('repos');

    const astGroup = document.getElementById('bulk-astreinte-group');
    astGroup.innerHTML = '';
    Object.values(planningAstreintes).forEach(a => {
        astGroup.appendChild(buildShiftChip(a, bulkAstreinte === a.cle, () => {
            bulkAstreinte = (bulkAstreinte === a.cle) ? null : a.cle;
            renderBulkChips();
        }));
    });
    updateBulkPreview();
}

function getBulkDates() {
    const debut = document.getElementById('bulk-date-debut').value;
    const fin = document.getElementById('bulk-date-fin').value;
    if (!debut || !fin || debut > fin) return [];
    const exclureWeekend = document.getElementById('bulk-exclure-weekend').checked;
    const dates = [];
    let cursor = new Date(debut + 'T00:00:00');
    const finDate = new Date(fin + 'T00:00:00');
    while (cursor <= finDate) {
        const dow = cursor.getDay();
        if (!exclureWeekend || (dow !== 0 && dow !== 6)) {
            dates.push(`${cursor.getFullYear()}-${String(cursor.getMonth() + 1).padStart(2, '0')}-${String(cursor.getDate()).padStart(2, '0')}`);
        }
        cursor.setDate(cursor.getDate() + 1);
    }
    return dates;
}

function updateBulkPreview() {
    const dates = getBulkDates();
    const preview = document.getElementById('bulk-preview');
    const rienChoisi = !bulkPoste && !bulkAstreinte && !bulkDemiConge && !bulkJourFerie;
    if (dates.length === 0) {
        preview.textContent = I18N_PLANNING.choisir_periode_valide;
    } else if (rienChoisi) {
        preview.textContent = `${dates.length} ${dates.length > 1 ? I18N_PLANNING.jour_concerne_plusieurs : I18N_PLANNING.jour_concerne_un} ${I18N_PLANNING.choisir_poste_evenement}`;
    } else {
        preview.textContent = `${dates.length} ${dates.length > 1 ? I18N_PLANNING.jour_seront_remplis_plusieurs : I18N_PLANNING.jour_seront_remplis_un} ${I18N_PLANNING.jours_ecrases}`;
    }
    document.getElementById('bulk-apply-btn').disabled = dates.length === 0 || rienChoisi;
}

function openBulkModal() {
    if (!shiftTech) return;
    document.getElementById('bulk-modal-title').textContent = `${I18N_PLANNING.modal_remplissage_rapide} — ${shiftTech}`;
    document.getElementById('bulk-date-debut').value = shiftDate;
    document.getElementById('bulk-date-fin').value = shiftDate;
    document.getElementById('bulk-exclure-weekend').checked = true;
    document.getElementById('bulk-heures').value = '';
    bulkPoste = null;
    bulkAstreinte = null;
    bulkDemiConge = false;
    bulkJourFerie = false;
    renderBulkChips();
    bringModalToFront('bulkModal');
    document.getElementById('bulkModal').style.display = 'block';
}

function closeBulkModal() {
    document.getElementById('bulkModal').style.display = 'none';
}

['bulk-date-debut', 'bulk-date-fin', 'bulk-exclure-weekend'].forEach(id => {
    document.getElementById(id).addEventListener('change', updateBulkPreview);
});

async function applyBulkFill() {
    const dates = getBulkDates();
    if (dates.length === 0) return;
    const heures = document.getElementById('bulk-heures').value;

    const nDaysLabel = `${dates.length} ${dates.length > 1 ? I18N_PLANNING.jour_concerne_plusieurs : I18N_PLANNING.jour_concerne_un}`;
    const confirme = await aspirineConfirm(
        I18N_PLANNING.modal_remplissage_rapide,
        I18N_PLANNING.confirm_bulk_apply.replace('{n}', nDaysLabel).replace('{tech}', shiftTech).replace('{d1}', dates[0]).replace('{d2}', dates[dates.length - 1])
    );
    if (!confirme) return;

    const btn = document.getElementById('bulk-apply-btn');
    btn.disabled = true;
    const libelleOrigine = btn.textContent;
    btn.textContent = I18N_PLANNING.application_en_cours;

    await Promise.all(dates.map(async (dateStr) => {
        const fd = new FormData();
        fd.append('action', 'save_shift');
        fd.append('tech', shiftTech);
        fd.append('date', dateStr);
        fd.append('poste', bulkPoste || '');
        fd.append('astreinte', bulkAstreinte || '');
        fd.append('heures', heures);
        fd.append('note', '');
        fd.append('demi_conge', bulkDemiConge ? '1' : '');
        fd.append('jour_ferie', bulkJourFerie ? '1' : '');
        await fetch('maintenance.php', { method: 'POST', body: fd });

        const key = `${shiftTech}_${dateStr}`;
        const existing = shiftsParCle[key] || {};
        const oldH = (parseFloat(existing.heures) || 0) + creditMaladie(existing.poste);
        const newH = (parseFloat(heures) || 0) + creditMaladie(bulkPoste);
        totauxAnnualises[shiftTech] = (totauxAnnualises[shiftTech] || 0) - oldH + newH;
        shiftsParCle[key] = { utilisateur: shiftTech, jour: dateStr, poste: bulkPoste, astreinte: bulkAstreinte, heures: heures !== '' ? parseFloat(heures) : null, note: null, demi_conge: bulkDemiConge ? 1 : 0, jour_ferie: bulkJourFerie ? 1 : 0 };
    }));

    btn.textContent = libelleOrigine;
    closeBulkModal();
    closeShiftModal();
    renderTable();
    if (document.getElementById('annualModal').style.display === 'block') { renderAnnualGrid(shiftTech); }
}

function adjustShiftHours(delta) {
    const input = document.getElementById('shift-heures');
    let v = (parseFloat(input.value) || 0) + delta;
    v = Math.min(24, Math.max(0, v));
    input.value = v;
    updateShiftTotalPreview();
}

// Calendrier des jours fériés français (dates fixes + Pâques/Ascension/Pentecôte calculées par l'algorithme
// de Meeus/Jones/Butcher). Sert uniquement de filet de sécurité pour le repère 35h/semaine ci-dessous : rien
// n'est censé remplacer la saisie explicite d'un "Jour férié" sur une ligne existante.
function datePaques(annee) {
    const a = annee % 19, b = Math.floor(annee / 100), c = annee % 100;
    const d = Math.floor(b / 4), e = b % 4, f = Math.floor((b + 8) / 25);
    const g = Math.floor((b - f + 1) / 3), h = (19 * a + b - d - g + 15) % 30;
    const i = Math.floor(c / 4), k = c % 4, l = (32 + 2 * e + 2 * i - h - k) % 7;
    const m = Math.floor((a + 11 * h + 22 * l) / 451);
    const mois = Math.floor((h + l - 7 * m + 114) / 31);
    const jour = ((h + l - 7 * m + 114) % 31) + 1;
    return new Date(annee, mois - 1, jour);
}
const JOURS_FERIES_CACHE = {};
function joursFeriesFrance(annee) {
    if (JOURS_FERIES_CACHE[annee]) return JOURS_FERIES_CACHE[annee];
    const fmt = (d) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
    const addDays = (date, n) => { const dd = new Date(date); dd.setDate(dd.getDate() + n); return dd; };
    const paques = datePaques(annee);
    const set = new Set([
        `${annee}-01-01`, `${annee}-05-01`, `${annee}-05-08`, `${annee}-07-14`,
        `${annee}-08-15`, `${annee}-11-01`, `${annee}-11-11`, `${annee}-12-25`,
        fmt(addDays(paques, 1)),  // Lundi de Pâques
        fmt(addDays(paques, 39)), // Ascension
        fmt(addDays(paques, 50)), // Lundi de Pentecôte
    ]);
    JOURS_FERIES_CACHE[annee] = set;
    return set;
}
function estJourFerieFrance(dStr) {
    return joursFeriesFrance(parseInt(dStr.slice(0, 4), 10)).has(dStr);
}

// Une ligne peut exister en base sans que personne n'ait jamais tranché "férié ou pas" pour ce jour-là :
// seuls poste/demi-congé/jour_ferie témoignent d'une vraie décision sur ce sujet précis. Une astreinte ou des
// heures isolées sont des informations indépendantes (ex. une astreinte froid posée sans que quiconque ait
// pensé à cocher "férié" au passage) — les ignorer ici évite qu'elles ne bloquent silencieusement le
// pré-cochage, le badge annuel et le repère 35h/semaine pour un jour férié pourtant jamais tranché.
function ligneVide(shift) {
    if (!shift) return true;
    return !shift.poste && !shift.demi_conge && !shift.jour_ferie;
}

// Repère "35h/semaine" : ce qu'on devrait avoir accumulé à ce jour en travaillant la moyenne légale de
// 35h/semaine (soit 7h par jour ouvré lundi-vendredi) depuis le début de la période d'annualisation.
// Reprend la même logique que le calcul officiel des 1607h (365j − week-ends − CP − jours fériés) : les CP,
// RTT et jours fériés déjà posés/passés ne sont pas dus, donc déduits du repère (0h, ou 3,5h pour un
// demi-congé) — sinon les poser ferait mécaniquement apparaître un faux "retard" par rapport à un total réel
// qui, lui, ne les compte plus pour 0h non plus. Un jour férié effectivement travaillé reste un bonus dans
// le total réel : ce repère ne l'attend pas, donc ça n'annule rien.
// Filet de sécurité : si AUCUNE ligne n'existe pour ce jour (personne n'a rien saisi, ce qui arrive souvent
// un jour férié où tout le monde est simplement absent sans avoir eu besoin d'ouvrir le planning) et que la
// date correspond à un jour férié français connu, on ne le compte pas comme dû non plus — sans ce filet, un
// 1er mai jamais "posé" par personne compte à tort comme un jour normal, créant un faux retard de 7h.
// Samedi/dimanche : contrairement au lundi-vendredi, rien n'est dû par défaut (jour de repos), sauf si le
// jour a été réellement posé/travaillé (poste + heures) — dans ce cas il est comparé à 7h exactement comme
// un jour de semaine. Sans ça, un technicien qui travaille un samedi voyait la totalité de ses heures
// partir en "avance" sans aucune comparaison (8h travaillées = +8h d'un coup, au lieu de +1h par rapport aux
// 7h attendues), ce qui gonflait artificiellement son compteur par rapport à un jour de semaine équivalent.
// Un jour férié n'est "non dû" (0h) que s'il n'a pas été travaillé. S'il a été réellement travaillé (poste
// matin/après-midi/nuit/journée avec des heures), il compte comme un jour normal comparé à 7h — la
// majoration de salaire pour un jour férié travaillé est un sujet de paie, distinct de l'annualisation :
// 8h travaillées un jour férié restent 8h classiques ici, pas un bonus intégral de 8h.
// RTT et Repos ne sont PAS traités pareil, malgré des noms proches : le RTT consomme un crédit d'heures
// déjà accumulé (on "pioche" dans son avance) — le jour reste donc dû normalement (7h) avec 0h faites, ce
// qui débite bien l'avance. Le Repos est la contrepartie légale du repos hebdomadaire d'une rotation
// variable (samedi/dimanche/n'importe quel jour selon le planning) : il n'a jamais été "gagné", donc 0h dû.
const POSTES_TRAVAILLES_REF = ['matin', 'apres_midi', 'nuit', 'journee'];
function estTravaille(shift) {
    return !!(shift && shift.poste && POSTES_TRAVAILLES_REF.includes(shift.poste));
}

function referenceDuJour(shift, dStr, isWeekend) {
    // Un poste (Matin/Après-midi/Nuit/Journée) est souvent programmé À L'AVANCE (import du planning
    // Excel), avant que le technicien n'ait eu l'occasion de saisir ses heures réellement faites pour ce
    // jour-là. Sans cette garde, le repère comptait ce jour comme "dû" (7h) dès que le poste apparaissait
    // dans le planning — donc AVANT toute saisie — puis les heures effectuées, une fois enfin rentrées,
    // s'ajoutaient en totalité à l'avance/retard (aucune 7h n'étant plus disponible à déduire, déjà
    // "consommée" silencieusement). Résultat observé : un technicien qui saisit 8h sur un jour prévu à
    // l'avance voyait les 8h partir intégralement en avance au lieu de seulement +1h (8h faites − 7h dues).
    // Tant que les heures ne sont pas saisies, ce jour ne compte donc pas encore comme dû, exactement comme
    // s'il n'était pas encore programmé — le repère ne "consomme" les 7h qu'au moment où le technicien
    // rapporte réellement son temps, pas au moment où le poste est planifié.
    // !(heures > 0) plutôt qu'un simple test de nullité : l'import Excel du planning pré-remplit souvent
    // "heures" à 0 (valeur par défaut) en même temps que le poste, avant toute saisie réelle du
    // technicien — un 0 issu de l'import doit être traité comme "pas encore saisi", pas comme "0h
    // travaillées et validées".
    const posteProgrammeNonSaisi = shift && shift.poste && POSTES_TRAVAILLES_REF.includes(shift.poste)
        && !(parseFloat(shift.heures) > 0);

    if (isWeekend) {
        if (ligneVide(shift)) return 0;
        if (['cp', 'repos'].includes(shift.poste)) return 0;
        if (shift.jour_ferie && !estTravaille(shift)) return 0;
        if (shift.demi_conge) return 3.5;
        if (posteProgrammeNonSaisi) return 0;
        return 7;
    }
    if (shift && ['cp', 'repos'].includes(shift.poste)) return 0;
    if (shift && shift.jour_ferie && !estTravaille(shift)) return 0;
    if (shift && shift.demi_conge) return 3.5;
    if (ligneVide(shift) && estJourFerieFrance(dStr)) return 0;
    if (posteProgrammeNonSaisi) return 0;
    return 7;
}

// dateGel (optionnelle, 'YYYY-MM-DD') fige le calcul à une date passée/future précise au lieu
// d'aujourd'hui (voir totalHeuresJusquA, utilisée ensemble pour figer l'encadré "Objectif annualisé"
// de la fiche jour sur la date cliquée) — dans ce mode gelé, on ignore volontairement les jours saisis
// au-delà de dateGel : un instantané au 19 mai ne doit rien montrer de ce qui a été rempli après.
function calculerReferenceHeures35h(tech, dateGel) {
    const debut = new Date(periodeAnnualisationDebut + 'T00:00:00');
    const fin = new Date(periodeAnnualisationFin + 'T00:00:00');
    const today = dateGel ? new Date(dateGel + 'T00:00:00') : new Date();
    today.setHours(0, 0, 0, 0);
    if (today < debut) return 0;
    let reference = 0;
    let cursor = new Date(debut);
    while (cursor <= fin) {
        const dow = cursor.getDay();
        const y = cursor.getFullYear(), m = cursor.getMonth(), d = cursor.getDate();
        const dStr = `${y}-${String(m + 1).padStart(2, '0')}-${String(d).padStart(2, '0')}`;
        const shift = shiftsParCle[`${tech}_${dStr}`];
        // Un jour futur ne compte comme "dû" que s'il a déjà été saisi (poste/CP/RTT programmé à l'avance) —
        // sinon des semaines entières pas encore arrivées compteraient comme du retard. Mais un jour futur
        // DÉJÀ rempli (ex. poste + heures saisis 2 jours à l'avance) doit comparer fait vs dû tout de suite,
        // sinon le remplir grossit l'avance de sa totalité (8h) au lieu de son seul écart (+1h sur 7h dus).
        // Cette extension ne s'applique qu'en mode "aujourd'hui" vivant : en mode gelé (dateGel), on s'arrête
        // strictement à cette date, saisi ou non après.
        if (cursor <= today || (!dateGel && !ligneVide(shift))) {
            reference += referenceDuJour(shift, dStr, dow === 0 || dow === 6);
        }
        cursor.setDate(cursor.getDate() + 1);
    }
    // Le fractionnement et les semaines à 48h réduisent l'objectif final (voir getObjectifEffectif) : ils
    // doivent réduire ce repère "au fil de l'eau" aussi, sinon l'avance affichée mi-année ignorerait des
    // heures déjà officiellement accordées (ex. 2j de fractionnement accordés = 14h d'avance immédiate,
    // pas seulement une cible finale plus basse en fin de période).
    const ajustements = getObjectifEffectif(tech);
    reference -= (ajustements.ajustFractionnement + ajustements.ajust48h);
    return reference;
}

// dateGel (optionnelle) : voir calculerReferenceHeures35h — fige le repère 35h/semaine et son libellé sur
// une date précise au lieu d'aujourd'hui (utilisé par l'encadré "Objectif annualisé" figé sur le jour cliqué).
// Somme des heures faites entre le début de la période et dateLimite incluse (au lieu du total non borné
// totauxAnnualises[tech], qui inclut tout ce qui a été saisi sur toute l'année) — sert à figer l'encadré
// "Objectif annualisé" de la fiche jour sur la date cliquée plutôt que sur le total live d'aujourd'hui.
function totalHeuresJusquA(tech, dateLimite) {
    const debut = new Date(periodeAnnualisationDebut + 'T00:00:00');
    const limite = new Date(dateLimite + 'T00:00:00');
    let total = 0;
    let cursor = new Date(debut);
    while (cursor <= limite) {
        const y = cursor.getFullYear(), m = cursor.getMonth(), d = cursor.getDate();
        const dStr = `${y}-${String(m + 1).padStart(2, '0')}-${String(d).padStart(2, '0')}`;
        const shift = shiftsParCle[`${tech}_${dStr}`];
        if (shift) {
            if (shift.heures !== null && shift.heures !== undefined && shift.heures !== '') {
                total += parseFloat(shift.heures);
            }
            total += creditMaladie(shift.poste);
        }
        cursor.setDate(cursor.getDate() + 1);
    }
    return total;
}

function renderProgressBar(prefix, heuresFait, objectif, tech, dateGel) {
    const pctFait = objectif > 0 ? (heuresFait / objectif) * 100 : 0;

    document.getElementById(`${prefix}-pct`).textContent = Math.round(pctFait) + '%';

    const faitEl = document.getElementById(`${prefix}-fait`);
    if (faitEl) { faitEl.textContent = I18N_PLANNING.h_faites_sur.replace('{fait}', heuresFait.toFixed(1).replace(/\.0$/, '')).replace('{objectif}', objectif.toFixed(0)); }

    // Nombre d'heures restantes jusqu'à l'objectif annuel (plutôt que le simple "fait / objectif", qui
    // affichait un écart énorme et peu utile la majeure partie de l'année).
    const detailEl = document.getElementById(`${prefix}-detail`);
    if (heuresFait >= objectif) {
        detailEl.textContent = I18N_PLANNING.objectif_depasse.replace('{n}', (heuresFait - objectif).toFixed(0));
    } else {
        detailEl.textContent = I18N_PLANNING.h_restantes_sur.replace('{n}', (objectif - heuresFait).toFixed(0)).replace('{objectif}', objectif.toFixed(0));
    }

    const fill = document.getElementById(`${prefix}-fill`);
    fill.style.width = Math.min(pctFait, 100) + '%';
    fill.classList.toggle('over', pctFait > 100);

    const referenceH = calculerReferenceHeures35h(tech, dateGel);
    const jalonTxt = dateGel ? I18N_PLANNING.au_date.replace('{date}', new Date(dateGel + 'T00:00:00').toLocaleDateString('fr-FR')) : I18N_PLANNING.a_ce_jour;
    const marker = document.getElementById(`${prefix}-marker-35h`);
    if (marker) {
        const pctRef = objectif > 0 ? Math.min((referenceH / objectif) * 100, 100) : 0;
        marker.style.left = pctRef + '%';
        marker.title = I18N_PLANNING.tooltip_repere_35h.replace('{n}', referenceH.toFixed(0)).replace('{jalon}', jalonTxt);
    }

    // Avance/retard : comparé au repère 35h/semaine (le rythme qu'on devrait avoir à ce jour), pas à
    // l'objectif annuel complet — c'est ça qui est réellement actionnable au fil de l'année.
    const paceEl = document.getElementById(`${prefix}-pace`);
    const ecartRythme = heuresFait - referenceH;
    paceEl.classList.remove('avance', 'retard');
    if (Math.abs(ecartRythme) < 1) {
        paceEl.textContent = I18N_PLANNING.pile_rythme.replace('{jalon}', jalonTxt);
    } else if (ecartRythme > 0) {
        paceEl.textContent = I18N_PLANNING.avance_rythme.replace('{n}', ecartRythme.toFixed(0)).replace('{jalon}', jalonTxt);
        paceEl.classList.add('avance');
    } else {
        paceEl.textContent = I18N_PLANNING.retard_rythme.replace('{n}', Math.abs(ecartRythme).toFixed(0)).replace('{jalon}', jalonTxt);
        paceEl.classList.add('retard');
    }
}

// Neutralisation "comme si" de la maladie (jurisprudence annualisation) : contrairement au CP/RTT/férié
// qui ne sont pas dus, un jour de maladie est crédité au compteur réel avec les heures planifiées ce
// jour-là (7h en moyenne lissée), pour ne pas pénaliser un salarié qui ne peut pas rattraper.
function creditMaladie(poste) {
    return poste === 'maladie' ? 7 : 0;
}

// Semaines à 48h (Art. 3 de l'accord, période de pointe) : "les salariés effectuant 48 heures pendant
// une semaine pourront bénéficier de 4 heures de repos déduits du contingent annuel" — détectable
// automatiquement à partir des heures saisies, contrairement au fractionnement qui est une décision RH.
function calculerSemaines48h(tech) {
    const debut = new Date(periodeAnnualisationDebut + 'T00:00:00');
    const fin = new Date(periodeAnnualisationFin + 'T00:00:00');
    const heuresParSemaine = {};
    let cursor = new Date(debut);
    while (cursor <= fin) {
        const y = cursor.getFullYear(), m = cursor.getMonth(), d = cursor.getDate();
        const dStr = `${y}-${String(m + 1).padStart(2, '0')}-${String(d).padStart(2, '0')}`;
        const shift = shiftsParCle[`${tech}_${dStr}`];
        if (shift && shift.heures !== null && shift.heures !== undefined && shift.heures !== '') {
            const monday = new Date(cursor);
            monday.setDate(monday.getDate() - ((monday.getDay() + 6) % 7));
            const weekKey = `${monday.getFullYear()}-${String(monday.getMonth() + 1).padStart(2, '0')}-${String(monday.getDate()).padStart(2, '0')}`;
            heuresParSemaine[weekKey] = (heuresParSemaine[weekKey] || 0) + parseFloat(shift.heures);
        }
        cursor.setDate(cursor.getDate() + 1);
    }
    return Object.keys(heuresParSemaine).filter(k => heuresParSemaine[k] >= 48);
}

// Objectif effectif = objectif de base ajusté des jours de fractionnement (Art. 6, saisie RH) et des
// semaines à 48h (Art. 3, détection automatique) — les deux réduisent le nombre d'heures dues sur l'année.
function getObjectifEffectif(tech) {
    const base = objectifsAnnuels[tech] || 1607;
    const joursFractionnement = fractionnementAnnuel[tech] || 0;
    const ajustFractionnement = joursFractionnement * 7;
    const semaines48h = calculerSemaines48h(tech);
    const ajust48h = semaines48h.length * 4;
    return {
        base, joursFractionnement, ajustFractionnement, semaines48hCount: semaines48h.length, ajust48h,
        effectif: base - ajustFractionnement - ajust48h,
    };
}

function updateShiftTotalPreview() {
    const key = `${shiftTech}_${shiftDate}`;
    const originalRecord = shiftsParCle[key];
    const existing = originalRecord || {};
    const heuresInput = document.getElementById('shift-heures').value;
    const oldH = (parseFloat(existing.heures) || 0) + creditMaladie(existing.poste);
    const newH = (parseFloat(heuresInput) || 0) + creditMaladie(shiftPoste);
    // Figé sur shiftDate (le jour cliqué) plutôt que sur le total live totauxAnnualises : l'encadré montre
    // l'avancement tel qu'il était à cette date précise, pas l'état actuel — voir totalHeuresJusquA.
    const base = totalHeuresJusquA(shiftTech, shiftDate);
    const preview = base - oldH + newH;
    const objectif = getObjectifEffectif(shiftTech).effectif;

    // calculerReferenceHeures35h (appelée par renderProgressBar) lit shiftsParCle, donc encore l'état
    // enregistré (ex. poste programmé à l'avance mais heures pas encore saisies -> jour pas encore compté
    // "dû", voir posteProgrammeNonSaisi) tant que la saisie en cours n'a pas été sauvegardée par saveShift.
    // Sans ce correctif temporaire, taper des heures sur un jour déjà programmé faisait partir la totalité
    // des heures tapées en avance (ex. +8h) au lieu du seul écart réel par rapport aux 7h dues (ex. +1h) :
    // le repère restait bloqué sur "pas dû" pendant la saisie, alors que le total "fait" comptait déjà tout.
    // On bascule donc shiftsParCle sur la saisie en cours le temps du calcul, puis on restaure l'état
    // d'origine juste après (rien n'est réellement sauvegardé tant que saveShift n'a pas été appelée).
    shiftsParCle[key] = { ...existing, poste: shiftPoste, astreinte: shiftAstreinte, heures: heuresInput !== '' ? parseFloat(heuresInput) : null, demi_conge: shiftDemiConge ? 1 : 0, jour_ferie: shiftJourFerie ? 1 : 0 };
    renderProgressBar('shift-objectif', preview, objectif, shiftTech, shiftDate);
    if (originalRecord === undefined) { delete shiftsParCle[key]; } else { shiftsParCle[key] = originalRecord; }
}
document.getElementById('shift-heures').addEventListener('input', function () {
    let v = parseFloat(this.value);
    if (!isNaN(v)) {
        if (v > 24) this.value = 24;
        if (v < 0) this.value = 0;
    }
    updateShiftTotalPreview();
});

// --- PLANNING ANNUEL (vue calendrier complète, ouverte depuis la fenêtre de planification) ---
const MOIS_LABELS_ANNUEL = I18N_PLANNING.mois_complet;
const JOURS_ABBR_ANNUEL = I18N_PLANNING.jour_lettre;
const POSTE_ABBR_ANNUEL = {
    matin: I18N_PLANNING.poste_abbr_matin, apres_midi: I18N_PLANNING.poste_abbr_apres_midi,
    nuit: I18N_PLANNING.poste_abbr_nuit, journee: I18N_PLANNING.poste_abbr_journee,
    jour_ferie: I18N_PLANNING.poste_abbr_jour_ferie, cp: I18N_PLANNING.poste_abbr_cp,
    demi_cp: I18N_PLANNING.poste_abbr_demi_cp, maladie: I18N_PLANNING.poste_abbr_maladie,
    rtt: I18N_PLANNING.poste_abbr_rtt, repos: I18N_PLANNING.poste_abbr_repos,
};

function closeAnnualModal() {
    document.getElementById('annualModal').style.display = 'none';
}

// Bulle d'aide "i" à côté du repère 35h/semaine : détaille au technicien, sans qu'il ait besoin de
// demander, exactement d'où vient son avance/retard — mois par mois et jour vide par jour vide.
function openEcartDetailModal() {
    if (!derniereAnalyseEcart) return;
    const {
        tech, referenceH, totalHeures, ecartTotal, monthEcartListe, joursVidesListe, cumulHistorique,
        ajustGlobal, ajustFractionnement, ajust48h, joursFractionnement, semaines48hCount,
    } = derniereAnalyseEcart;

    document.getElementById('ecart-detail-title').textContent = I18N_PLANNING.comprendre_ecart_tech.replace('{tech}', tech);
    document.getElementById('edt-du').textContent = `${referenceH.toFixed(0)}h`;
    document.getElementById('edt-fait').textContent = `${totalHeures.toFixed(1).replace(/\.0$/, '')}h`;

    const ecartTile = document.getElementById('edt-ecart-tile');
    const ecartEl = document.getElementById('edt-ecart');
    ecartTile.classList.remove('avance', 'retard');
    const sensTotal = ecartTotal >= 0 ? 'avance' : 'retard';
    ecartTile.classList.add(sensTotal);
    ecartEl.textContent = `${ecartTotal >= 0 ? '+' : ''}${ecartTotal.toFixed(1).replace(/\.0$/, '')}h`;

    // Le fractionnement/48h réduit l'objectif ET ce repère "au fil de l'eau" (voir calculerReferenceHeures35h),
    // mais ce n'est lié à aucun jour précis — donc invisible dans les cases "Écart par mois" ci-dessous (qui ne
    // suivent que les jours réellement saisis). Il réapparaît en revanche, à part, dans la ligne "Ajustement de
    // départ" du tableau "Détail du cumul" plus bas — on le précise ici pour éviter toute contradiction entre
    // les deux sections.
    const ajustNoteEl = document.getElementById('edt-ajust-note');
    if (ajustGlobal > 0) {
        const parts = [];
        if (ajustFractionnement > 0) parts.push(I18N_PLANNING.fractionnement_h.replace('{n}', ajustFractionnement).replace('{j}', joursFractionnement));
        if (ajust48h > 0) parts.push(I18N_PLANNING.semaines_48h.replace('{n}', ajust48h).replace('{s}', semaines48hCount));
        ajustNoteEl.style.display = '';
        ajustNoteEl.innerHTML = `<i class="fa-solid fa-circle-info"></i> ` + I18N_PLANNING.deja_compte_ecart.replace('{n}', ajustGlobal.toFixed(0)).replace('{parts}', parts.join(' + '));
    } else {
        ajustNoteEl.style.display = 'none';
    }

    const detteVides = joursVidesListe.reduce((s, j) => s + j.du, 0);
    // Écart "brut" sur les seuls jours remplis, sans l'ajustement global (fractionnement/48h) qui est
    // expliqué à part ci-dessus : ecartTotal inclut déjà +ajustGlobal, il faut le retirer pour retomber sur
    // la somme réelle des cases mois par mois (sinon les deux chiffres ne se recoupent plus).
    const ecartJoursRemplis = ecartTotal + detteVides - ajustGlobal;
    const explainEl = document.getElementById('edt-explain');
    if (joursVidesListe.length > 0) {
        const jourLabel = joursVidesListe.length > 1 ? I18N_PLANNING.jour_de_semaine_plusieurs : I18N_PLANNING.jour_de_semaine_un;
        explainEl.innerHTML = `<i class="fa-solid fa-lightbulb"></i> ` + I18N_PLANNING.explain_jours_remplis
            .replace('{tech}', tech)
            .replace('{ecart}', ecartJoursRemplis.toFixed(1).replace(/\.0$/, ''))
            .replace('{n}', joursVidesListe.length)
            .replace('{jourlabel}', jourLabel)
            .replace('{pluriel}', joursVidesListe.length > 1 ? 'nt' : '')
            .replace('{du}', detteVides.toFixed(0));
    } else {
        explainEl.innerHTML = `<i class="fa-solid fa-circle-check"></i> ` + I18N_PLANNING.explain_tout_rempli;
    }

    const moisGrid = document.getElementById('edt-mois-grid');
    moisGrid.innerHTML = '';
    monthEcartListe.forEach(({ label, ecart }) => {
        const sens = Math.abs(ecart) < 0.05 ? 'neutre' : (ecart > 0 ? 'avance' : 'retard');
        const signe = ecart > 0.05 ? '+' : '';
        moisGrid.insertAdjacentHTML('beforeend', `
            <div class="ecart-mois-tile ${sens}">
                <div class="emt-mois">${label}</div>
                <div class="emt-val">${signe}${ecart.toFixed(1).replace(/\.0$/, '')}h</div>
            </div>
        `);
    });

    // Table "Détail du cumul" : reprend ligne par ligne le calcul de la ligne "Avance globale cumulée"
    // affichée sous chaque mois, jours vides ET ajustement fractionnement/48h inclus cette fois (voir
    // monthDue/monthEcartVrai dans renderAnnualGrid) — contrairement à la grille "Écart par mois" ci-dessus,
    // le cumul de la dernière ligne retombe donc exactement sur l'écart affiché en haut.
    const cumulTbody = document.getElementById('edt-cumul-tbody');
    // Ligne de départ, avant le premier mois : explique pourquoi le cumul du 1er mois n'est jamais égal à
    // son seul écart (ex. avril à +4,4h d'écart mais +15,4h de cumul) — la différence, c'est cet ajustement
    // fractionnement/48h compté dès le départ (voir cumulEcartGlobal dans renderAnnualGrid).
    let ligneInitiale = '';
    if (ajustGlobal > 0) {
        const partsInit = [];
        if (ajustFractionnement > 0) partsInit.push(`fractionnement ${joursFractionnement}j = +${ajustFractionnement}h`);
        if (ajust48h > 0) partsInit.push(`semaines 48h ${semaines48hCount} sem. = +${ajust48h}h`);
        ligneInitiale = `<tr class="edt-cumul-init-row" title="${I18N_PLANNING.tooltip_ajustement_objectif.replace('{parts}', partsInit.join(', '))}">
            <td colspan="4">${I18N_PLANNING.ajustement_depart.replace('{parts}', partsInit.join(' + '))}</td>
            <td class="avance">+${ajustGlobal.toFixed(0)}h</td>
        </tr>`;
    }
    cumulTbody.innerHTML = ligneInitiale + cumulHistorique.map(({ label, heuresFaites, heuresDues, ecart, cumul }) => {
        const sens = Math.abs(ecart) < 0.05 ? '' : (ecart > 0 ? 'avance' : 'retard');
        const signe = ecart > 0.05 ? '+' : '';
        const sensCumul = cumul >= 0 ? 'avance' : 'retard';
        const signeCumul = cumul >= 0 ? '+' : '';
        return `<tr>
            <td>${label}</td>
            <td>${heuresFaites.toFixed(1).replace(/\.0$/, '')}h</td>
            <td>${heuresDues.toFixed(1).replace(/\.0$/, '')}h</td>
            <td class="${sens}">${signe}${ecart.toFixed(1).replace(/\.0$/, '')}h</td>
            <td class="${sensCumul}">${signeCumul}${cumul.toFixed(1).replace(/\.0$/, '')}h</td>
        </tr>`;
    }).join('');

    const videsList = document.getElementById('edt-vides-list');
    if (joursVidesListe.length === 0) {
        videsList.innerHTML = `<div class="ecart-vides-empty">${I18N_PLANNING.aucun_jour_vide}</div>`;
    } else {
        videsList.innerHTML = joursVidesListe.map(j => {
            const dObj = new Date(j.date + 'T00:00:00');
            const label = dObj.toLocaleDateString(JS_LOCALE, { weekday: 'long', day: 'numeric', month: 'long' });
            return `<div class="ecart-vides-row"><span class="evr-date">${label}</span><span class="evr-du">-${j.du.toFixed(1).replace(/\.0$/, '')}h</span></div>`;
        }).join('');
    }

    bringModalToFront('ecartDetailModal');
    document.getElementById('ecartDetailModal').style.display = 'block';
}

function closeEcartDetailModal() {
    document.getElementById('ecartDetailModal').style.display = 'none';
}

// Bascule un texte coloré vers du noir dès que la couleur d'origine est trop claire pour rester lisible
// sur un fond blanc/gris clair (ex. jaune citron pour "Matin") — luminance relative WCAG au-delà du seuil.
function luminanceRelative(hex) {
    if (!hex) return null;
    const h = hex.replace('#', '');
    const full = h.length === 3 ? h.split('').map(c => c + c).join('') : h;
    if (full.length !== 6) return null;
    const r = parseInt(full.substr(0, 2), 16), g = parseInt(full.substr(2, 2), 16), b = parseInt(full.substr(4, 2), 16);
    if ([r, g, b].some(isNaN)) return null;
    const lin = (c) => { c /= 255; return c <= 0.03928 ? c / 12.92 : Math.pow((c + 0.055) / 1.055, 2.4); };
    return 0.2126 * lin(r) + 0.7152 * lin(g) + 0.0722 * lin(b);
}

function couleurLisible(hex, seuil = 0.6) {
    const lum = luminanceRelative(hex);
    if (lum === null) return hex || '#888';
    return lum > seuil ? '#333' : hex;
}

// Texte à poser PAR-DESSUS un fond de cette couleur (chips actifs, badges) : blanc par défaut, mais gris
// foncé si la couleur est trop claire (jaune citron...), sinon le blanc devient illisible.
function texteContrasteSur(hex, seuil = 0.6) {
    const lum = luminanceRelative(hex);
    if (lum === null) return '#fff';
    return lum > seuil ? '#333' : '#fff';
}

function renderAnnualLegend() {
    const postesContainer = document.getElementById('annual-legend-postes');
    const astreintesContainer = document.getElementById('annual-legend-astreintes');
    postesContainer.innerHTML = '';
    astreintesContainer.innerHTML = '';
    Object.values(planningPostes).forEach(p => {
        postesContainer.insertAdjacentHTML('beforeend', `<div class="annual-legend-item"><span class="annual-legend-swatch" style="background:${p.couleur};"></span>${p.label}</div>`);
    });
    Object.values(planningAstreintes).forEach(a => {
        astreintesContainer.insertAdjacentHTML('beforeend', `<div class="annual-legend-item"><span class="annual-legend-swatch astreinte" style="background:${a.couleur};"></span>${a.label}</div>`);
    });
}

function renderAnnualGrid(tech) {
    const grid = document.getElementById('annual-grid');
    grid.innerHTML = '';

    const debut = new Date(periodeAnnualisationDebut + 'T00:00:00');
    const fin = new Date(periodeAnnualisationFin + 'T00:00:00');

    let totalHeures = 0;
    let cursor = new Date(debut);
    let monthCard = null, monthBody = null, monthKey = null, monthTotal = 0, monthEcart = 0, monthDue = 0, monthDejaCommence = false;
    // Cumul de l'avance/retard mois après mois, pour suivre l'évolution globale (pas juste l'écart du mois
    // en cours). Démarre avec l'ajustement global (fractionnement/48h, voir getObjectifEffectif) car il
    // s'applique dès le premier jour de la période, pas à un mois précis — sans ça, le cumul du dernier
    // mois affiché ne rejoindrait jamais le total "avance" en haut du modal.
    const objectifInfoGrid = getObjectifEffectif(tech);
    let cumulEcartGlobal = objectifInfoGrid.ajustFractionnement + objectifInfoGrid.ajust48h;
    // Historique mois par mois du cumul (alimente la nouvelle table "Détail du cumul" dans la bulle d'aide) :
    // heures faites/dues du mois avec monthDue = somme de TOUTES les heures dues du mois (jours vides inclus),
    // contrairement à monthEcart ci-dessous qui ignore volontairement les jours vides (voir plus bas).
    const cumulHistorique = [];
    // Alimentent la bulle d'aide "Comprendre mon avance/retard" (voir openEcartDetailModal) : écart réel par
    // mois et liste des jours de semaine jamais saisis, qui pèsent silencieusement sur le repère du haut.
    const monthEcartListe = [];
    const joursVidesListe = [];
    // Congés payés et CP samedi : deux quotas légaux FIXES et distincts (25 jours de CP + 5 samedis dédiés,
    // soit 30 jours au total — pas 5 des 25), pas un total qui grandirait au fil des saisies — contrairement
    // aux postes (matin/nuit/...) qui sont des rotations sans quota fixe, où "total" = ce qui a été planifié
    // jusqu'ici. Les demi-congés n'ont pas leur propre tuile : ils créditent une demi-journée directement
    // dans le quota concerné (cp ou cpSamedi selon le jour) pour rester dans le même compteur.
    const recap = {
        cp: { total: 25, done: 0 }, cpSamedi: { total: 5, done: 0 }, rtt: { total: 0, done: 0 }, maladie: { total: 0, done: 0 },
        matin: { total: 0, done: 0 }, apresMidi: { total: 0, done: 0 }, nuit: { total: 0, done: 0 },
        astreinte: { total: 0, done: 0 }, astreinteFroid: { total: 0, done: 0 },
    };
    const todayObjAnnuel = new Date();
    const todayStrAnnuel = `${todayObjAnnuel.getFullYear()}-${String(todayObjAnnuel.getMonth() + 1).padStart(2, '0')}-${String(todayObjAnnuel.getDate()).padStart(2, '0')}`;

    // Alerte plafond CP continu en période de forte activité (Art. 6 : 12 jours ouvrables max entre le
    // 15 juillet et le 30 septembre). Approximation : personne ne pose de CP un dimanche (déjà non
    // travaillé), donc compter les jours calendaires consécutifs revient à compter les jours ouvrables.
    const anneeRef = periodeAnnualisationDebut.split('-')[0];
    const fenetreCpDebut = `${anneeRef}-07-15`;
    const fenetreCpFin = `${anneeRef}-09-30`;
    let cpStreakStart = null, cpStreakLen = 0, cpStreakPrevStr = null, maxCpStreakDansFenetre = 0;
    const chevaucheFenetreCp = (debutStr, finStr) => debutStr <= fenetreCpFin && finStr >= fenetreCpDebut;
    const clotureStreakCp = () => {
        if (cpStreakLen > 0 && chevaucheFenetreCp(cpStreakStart, cpStreakPrevStr)) {
            maxCpStreakDansFenetre = Math.max(maxCpStreakDansFenetre, cpStreakLen);
        }
        cpStreakStart = null; cpStreakLen = 0;
    };

    function flushMonth() {
        if (monthCard) {
            monthCard.insertAdjacentHTML('beforeend', `<div class="annual-month-total"><span>${I18N_PLANNING.total}</span><span>${monthTotal.toFixed(1).replace(/\.0$/, '')}h</span></div>`);
            // Récap du mois : somme des écarts jour par jour (7h/jour attendu, sauf week-end non travaillé)
            // sur les seuls jours déjà passés — un mois entièrement à venir n'affiche rien à comparer.
            if (Math.abs(monthEcart) >= 0.05) {
                const sens = monthEcart > 0 ? 'avance' : 'retard';
                const signe = monthEcart > 0 ? '+' : '';
                monthCard.insertAdjacentHTML('beforeend', `<div class="annual-month-ecart ${sens}"><span>${I18N_PLANNING.ecart_35h}</span><span>${signe}${monthEcart.toFixed(1).replace(/\.0$/, '')}h</span></div>`);
            }
            if (monthDejaCommence) {
                // Cumul global : avance/retard total depuis le début de la période jusqu'à la fin de ce mois
                // (pas juste l'écart du mois seul ci-dessus) — permet de suivre l'évolution mois après mois.
                // Utilise monthTotal - monthDue (jours vides inclus), pas monthEcart (qui les ignore), pour
                // que ce cumul retombe exactement sur le repère du haut — voir calculerReferenceHeures35h.
                const monthEcartVrai = monthTotal - monthDue;
                cumulEcartGlobal += monthEcartVrai;
                const sensCumul = cumulEcartGlobal >= 0 ? 'avance' : 'retard';
                const signeCumul = cumulEcartGlobal >= 0 ? '+' : '';
                const moisLabel = monthCard.querySelector('.annual-month-header').textContent;
                monthCard.insertAdjacentHTML('beforeend', `<div class="annual-month-ecart-cumul ${sensCumul}"><span>${I18N_PLANNING.avance_globale_cumulee} <i class="fa-solid fa-circle-info annual-cumul-info-btn" onclick="event.stopPropagation(); openEcartDetailModal();" title="${I18N_PLANNING.tooltip_detail_calcul}"></i></span><span>${signeCumul}${cumulEcartGlobal.toFixed(1).replace(/\.0$/, '')}h</span></div>`);
                monthEcartListe.push({ label: moisLabel, ecart: monthEcart });
                cumulHistorique.push({ label: moisLabel, heuresFaites: monthTotal, heuresDues: monthDue, ecart: monthEcartVrai, cumul: cumulEcartGlobal });
            }
            grid.appendChild(monthCard);
        }
    }

    while (cursor <= fin) {
        const y = cursor.getFullYear(), m = cursor.getMonth(), d = cursor.getDate();
        const key = `${y}-${m}`;
        if (key !== monthKey) {
            flushMonth();
            monthKey = key;
            monthTotal = 0;
            monthEcart = 0;
            monthDue = 0;
            monthDejaCommence = false;
            monthCard = document.createElement('div');
            monthCard.className = 'annual-month-card';
            monthCard.innerHTML = `<div class="annual-month-header">${MOIS_LABELS_ANNUEL[m]} ${y}</div>`;
            monthBody = document.createElement('div');
            monthBody.className = 'annual-month-body';
            monthCard.appendChild(monthBody);
        }

        const dStr = `${y}-${String(m + 1).padStart(2, '0')}-${String(d).padStart(2, '0')}`;
        const shift = shiftsParCle[`${tech}_${dStr}`];
        const dow = cursor.getDay();
        const isWeekend = (dow === 0 || dow === 6);

        if (shift) {
            const isPastAnnuel = dStr <= todayStrAnnuel;
            // CP/CP samedi/RTT/Maladie comptent dès la saisie (posé), pas seulement une fois la date passée :
            // le technicien veut voir tout de suite les congés qu'il a réservés, même à venir. Les rotations
            // (matin/après-midi/nuit/astreinte) gardent la logique "déjà passé" (progression réelle du planning).
            const bumpRecap = (cle, quotaFixe, countAll) => { if (!quotaFixe) { recap[cle].total++; } if (countAll || isPastAnnuel) { recap[cle].done++; } };
            // Un CP posé un dimanche ne consomme aucun quota : le dimanche n'est déjà pas travaillé, poser
            // "congé" ce jour-là ne coûte rien de plus — contrairement au samedi (quota cpSamedi dédié) ou
            // à un jour de semaine (quota cp classique).
            if (shift.poste === 'cp') {
                if (dow === 6) { bumpRecap('cpSamedi', true, true); }
                else if (dow !== 0) { bumpRecap('cp', true, true); }
            }
            else if (shift.poste === 'rtt') { bumpRecap('rtt', false, true); }
            else if (shift.poste === 'maladie') { bumpRecap('maladie', false, true); }
            else if (shift.poste === 'matin') { bumpRecap('matin'); }
            else if (shift.poste === 'apres_midi') { bumpRecap('apresMidi'); }
            else if (shift.poste === 'nuit') { bumpRecap('nuit'); }
            if (shift.astreinte === 'classique') { bumpRecap('astreinte'); }
            else if (shift.astreinte === 'froid') { bumpRecap('astreinteFroid'); }
            if (shift.demi_conge && dow !== 0) {
                if (dow === 6) { recap.cpSamedi.done += 0.5; } else { recap.cp.done += 0.5; }
            }
        }

        if (shift && shift.poste === 'cp') {
            if (cpStreakLen === 0) { cpStreakStart = dStr; }
            cpStreakLen++;
            cpStreakPrevStr = dStr;
        } else if (dow !== 0) {
            // Un dimanche sans CP ne casse pas une série en cours (jour de repos hebdo, jamais travaillé
            // de toute façon) — seul un vrai jour de reprise (ouvrable) interrompt la continuité du congé.
            clotureStreakCp();
        }

        // Case neutre par défaut, plus de couleur pleine liée au poste : gris très clair du lundi au
        // vendredi, blanc le week-end — la couleur du poste vit désormais uniquement dans son badge.
        let bg = isWeekend ? '#ffffff' : '#f4f5f7';
        let heuresTxt = '';
        let abbrTxt = '';
        let extraTxt = '';
        let heuresJour = 0;
        if (shift && !ligneVide(shift)) {
            // Postes réellement travaillés : colorent toute la case, comme avant (c'est l'info principale
            // du jour). Matin/après-midi/nuit/journée sont maintenant de petits badges comme CP/RTT/maladie/
            // jour férié, plutôt que de remplir toute la case de la couleur du poste — la case reste neutre
            // (gris clair en semaine, blanc le week-end) quel que soit le poste posé. Le badge de poste vit
            // dans sa propre colonne (abbrTxt), séparée de celle des badges secondaires (extraTxt), pour
            // rester à la même position que le jour ait ou non un badge Jour férié/demi-congé en plus.
            if (shift.poste && planningPostes[shift.poste]) {
                const item = planningPostes[shift.poste];
                const abbr = POSTE_ABBR_ANNUEL[shift.poste] || shift.poste;
                abbrTxt = `<span class="annual-day-mini-badge" style="background:${item.couleur}; color:${texteContrasteSur(item.couleur)};" title="${item.label}">${abbr}</span>`;
            }
            const extraBadges = [];
            if (shift.demi_conge && planningPostes.demi_cp) {
                extraBadges.push([planningPostes.demi_cp, POSTE_ABBR_ANNUEL.demi_cp || '½']);
            }
            if (shift.jour_ferie && planningPostes.jour_ferie) {
                extraBadges.push([planningPostes.jour_ferie, 'JF']);
            }
            extraBadges.forEach(([item, abbr]) => {
                extraTxt += (extraTxt ? ' ' : '') + `<span class="annual-day-mini-badge" style="background:${item.couleur}; color:${texteContrasteSur(item.couleur)};" title="${item.label}">${abbr}</span>`;
            });
            if (shift.heures !== null && shift.heures !== undefined && shift.heures !== '') {
                const h = parseFloat(shift.heures);
                heuresTxt = h + 'h';
                heuresJour += h;
                monthTotal += h;
                totalHeures += h;
            }
            // Congé/RTT/férié comptent pour 0h : le seuil de 1607h les a déjà déduits en amont. La maladie
            // est neutralisée "comme si" (jurisprudence annualisation) : créditée à 7h même sans heures
            // saisies, pour ne pas pénaliser un salarié qui ne peut pas rattraper une absence subie.
            if (shift.poste === 'maladie') { heuresJour += 7; monthTotal += 7; totalHeures += 7; }
        } else if (ligneVide(shift) && estJourFerieFrance(dStr) && planningPostes.jour_ferie) {
            // Aucune ligne significative enregistrée ce jour-là (absente, ou résidu vide), mais l'outil sait
            // que c'est un jour férié français (même filet de sécurité que pour le repère 35h/semaine) : on
            // l'affiche quand même, avec un style "auto" distinct, plutôt que de laisser la case muette
            // comme s'il ne s'était rien passé.
            extraTxt = `<span class="annual-day-mini-badge annual-day-mini-badge-auto" style="background:${planningPostes.jour_ferie.couleur}; color:${texteContrasteSur(planningPostes.jour_ferie.couleur)};" title="${planningPostes.jour_ferie.label} ${I18N_PLANNING.detecte_auto}">JF</span>`;
        }
        // Écart du jour = heures faites − heures dues (7h, sauf week-end non travaillé ou CP/RTT/férié/
        // demi-congé) — seulement sur les jours déjà passés ET réellement remplis (heuresJour > 0). Un jour
        // vide (rien saisi) n'affiche rien : ce badge donne un retour sur ce qui a été saisi, ce n'est pas
        // une alerte "vous avez oublié de remplir" (déjà couverte par le repère 35h/semaine en haut).
        let ecartTxt = '';
        // Comme dans calculerReferenceHeures35h : un jour futur compte aussi dès qu'il a été saisi (poste/CP/
        // RTT programmé à l'avance), pas seulement une fois sa date passée — sinon remplir un jour en avance
        // grossit l'avance de sa totalité au lieu de son seul écart vs les heures dues ce jour-là.
        if (dStr <= todayStrAnnuel || !ligneVide(shift)) {
            monthDejaCommence = true;
            const duJour = referenceDuJour(shift, dStr, isWeekend);
            // Contrairement à monthEcart (qui ignore les jours vides pour rester "amical", voir plus bas),
            // monthDue cumule TOUTES les heures dues du mois — c'est ce qui permet au cumul global (et à sa
            // table de détail) de retomber exactement sur le même total que le repère du haut, jours vides
            // inclus (voir calculerReferenceHeures35h, qui fait la même somme sans distinction).
            monthDue += duJour;
            if (!ligneVide(shift)) {
                // Dès qu'il y a une vraie saisie (poste, CP, RTT, repos, férié...), même sans heures — un RTT
                // n'a normalement pas d'heures saisies, mais il reste dû à 7h (voir referenceDuJour) : sans ce
                // badge, le compteur du haut baisserait de 7h sans qu'aucune case n'explique pourquoi.
                const ecartJour = heuresJour - duJour;
                monthEcart += ecartJour;
                if (Math.abs(ecartJour) >= 0.05) {
                    const sens = ecartJour > 0 ? 'avance' : 'retard';
                    const signe = ecartJour > 0 ? '+' : '';
                    ecartTxt = `<span class="annual-day-ecart ${sens}" title="${I18N_PLANNING.tooltip_h_faites_dues.replace('{fait}', heuresJour).replace('{du}', duJour)}">${signe}${ecartJour.toFixed(1).replace(/\.0$/, '')}h</span>`;
                }
            } else if (duJour > 0) {
                // Jour de semaine sans aucune saisie : rien à afficher sur la case (voir plus haut), mais il
                // pèse quand même sur le repère du haut (calculerReferenceHeures35h) — répertorié ici pour la
                // bulle d'aide "Comprendre mon avance/retard", qui liste ces jours au technicien.
                joursVidesListe.push({ date: dStr, du: duJour });
            }
        }

        let dotHtml = '';
        if (shift && shift.astreinte && planningAstreintes[shift.astreinte]) {
            dotHtml = `<span class="annual-day-astreinte-dot" style="background:${planningAstreintes[shift.astreinte].couleur};" title="${planningAstreintes[shift.astreinte].label}"></span>`;
        }

        const row = document.createElement('div');
        row.className = 'annual-day-row' + (isWeekend ? ' weekend' : '');
        row.style.background = bg;
        row.innerHTML = `${dotHtml}<span class="annual-day-date">${JOURS_ABBR_ANNUEL[dow]} ${d}</span><span class="annual-day-abbr">${abbrTxt}</span><span class="annual-day-abbr-extra">${extraTxt}</span><span class="annual-day-heures"><span class="annual-day-heures-txt">${heuresTxt}</span>${ecartTxt}</span>`;
        if (shift && shift.note) {
            const noteDot = document.createElement('span');
            noteDot.className = 'shift-note-badge annual-day-note-dot';
            noteDot.innerHTML = '<i class="fa-solid fa-note-sticky"></i>';
            const bubble = document.createElement('span');
            bubble.className = 'shift-note-bubble annual-note-bubble';
            bubble.textContent = shift.note;
            noteDot.appendChild(bubble);
            row.appendChild(noteDot);
        }
        row.addEventListener('click', () => openShiftModal(tech, dStr, true));
        monthBody.appendChild(row);

        cursor.setDate(cursor.getDate() + 1);
    }
    clotureStreakCp();
    flushMonth();

    const recapConfig = [
        { cle: 'matin', label: I18N_PLANNING.recap_matin, couleur: (planningPostes.matin || {}).couleur },
        { cle: 'apresMidi', label: I18N_PLANNING.recap_apres_midi, couleur: (planningPostes.apres_midi || {}).couleur },
        { cle: 'nuit', label: I18N_PLANNING.recap_nuit, couleur: (planningPostes.nuit || {}).couleur },
        { cle: 'cp', label: I18N_PLANNING.recap_conges_payes, couleur: (planningPostes.cp || {}).couleur },
        { cle: 'cpSamedi', label: I18N_PLANNING.recap_cp_samedi, couleur: (planningPostes.cp || {}).couleur },
        { cle: 'rtt', label: I18N_PLANNING.recap_rtt, couleur: (planningPostes.rtt || {}).couleur, sansQuota: true, note: I18N_PLANNING.note_rtt },
        { cle: 'maladie', label: I18N_PLANNING.recap_maladie, couleur: (planningPostes.maladie || {}).couleur, sansQuota: true },
        { cle: 'astreinte', label: I18N_PLANNING.recap_astreinte, couleur: (planningAstreintes.classique || {}).couleur },
        { cle: 'astreinteFroid', label: I18N_PLANNING.recap_astreinte_froid, couleur: (planningAstreintes.froid || {}).couleur },
    ];
    const recapGrid = document.getElementById('annual-recap-grid');
    recapGrid.innerHTML = '';
    recapConfig.forEach(c => {
        const r = recap[c.cle];
        const couleur = c.couleur || '#999';
        // Le pourcentage est écrit dans la couleur du poste : si celle-ci est trop claire (jaune citron...),
        // le texte devient illisible sur le fond blanc de la tuile. On bascule alors sur du noir automatiquement.
        const couleurTexte = couleurLisible(couleur);
        const valeur = Number.isInteger(r.done) ? r.done : r.done.toFixed(1).replace('.', ',');
        // RTT/Maladie n'ont pas de quota fixe comme les 25 CP/5 CP samedi : on affiche juste le nombre de
        // jours posés, sans dénominateur ni barre/pourcentage qui n'aurait pas de sens à comparer à rien.
        if (c.sansQuota) {
            recapGrid.insertAdjacentHTML('beforeend', `
                <div class="annual-recap-tile" style="--c:${couleur};">
                    <div class="rt-label">${c.label}</div>
                    <div class="rt-value">${valeur} <small>${r.done > 1 ? I18N_PLANNING.jour_pose_plusieurs : I18N_PLANNING.jour_pose_un}</small></div>
                    ${c.note ? `<div class="rt-note">${c.note}</div>` : ''}
                </div>
            `);
            return;
        }
        const pct = r.total > 0 ? Math.round((r.done / r.total) * 100) : 0;
        // Plafond Art. 6 : 12 jours ouvrables de CP continu max entre le 15/07 et le 30/09. On ne signale
        // que sur la tuile CP (la même série peut inclure des samedis, déjà comptés dans le même poste).
        const alerteCp = (c.cle === 'cp' && maxCpStreakDansFenetre > 12) ? `
            <span class="annual-alert-badge">!
                <span class="annual-alert-bubble">${I18N_PLANNING.alerte_cp_streak.replace('{n}', maxCpStreakDansFenetre)}</span>
            </span>` : '';
        recapGrid.insertAdjacentHTML('beforeend', `
            <div class="annual-recap-tile" style="--c:${couleur}; --ctext:${couleurTexte};">
                <div class="rt-label-row"><div class="rt-label">${c.label}</div>${alerteCp}</div>
                <div class="rt-value">${valeur} <small>/ ${r.total}</small></div>
                <div class="rt-bar-track"><div class="rt-bar-fill" style="width:${pct}%;"></div></div>
                <div class="rt-pct">${pct}% ${I18N_PLANNING.pct_realise}</div>
            </div>
        `);
    });

    // Alimente la bulle d'aide "Comprendre mon avance/retard" (voir openEcartDetailModal), avec tout le
    // détail déjà calculé pendant le rendu de ce planning plutôt que de tout recalculer à l'ouverture.
    const objectifInfo = getObjectifEffectif(tech);
    const referenceHTech = calculerReferenceHeures35h(tech);
    derniereAnalyseEcart = {
        tech, referenceH: referenceHTech, totalHeures,
        ecartTotal: totalHeures - referenceHTech,
        monthEcartListe, joursVidesListe, cumulHistorique,
        ajustGlobal: objectifInfo.ajustFractionnement + objectifInfo.ajust48h,
        ajustFractionnement: objectifInfo.ajustFractionnement, ajust48h: objectifInfo.ajust48h,
        joursFractionnement: objectifInfo.joursFractionnement, semaines48hCount: objectifInfo.semaines48hCount,
    };

    document.getElementById('annual-total-periode').textContent = periodeAnnualisationLabel;
    renderProgressBar('annual-total', totalHeures, objectifInfo.effectif, tech);

    const noteEl = document.getElementById('annual-objectif-adjust-note');
    const parts = [];
    if (objectifInfo.ajustFractionnement > 0) { parts.push(I18N_PLANNING.fractionnement_reduction.replace('{n}', objectifInfo.ajustFractionnement).replace('{j}', objectifInfo.joursFractionnement)); }
    if (objectifInfo.ajust48h > 0) { parts.push(I18N_PLANNING.semaines_48h_reduction.replace('{n}', objectifInfo.ajust48h).replace('{s}', objectifInfo.semaines48hCount)); }
    noteEl.textContent = parts.length ? I18N_PLANNING.objectif_ajuste.replace('{base}', objectifInfo.base).replace('{parts}', parts.map(p => p.replace('-', '− ')).join(' ')).replace('{effectif}', objectifInfo.effectif) : '';

    // La somme des écarts mensuels ci-dessous ne peut pas inclure le fractionnement/48h (voir monthEcart
    // dans renderAnnualGrid : c'est un ajustement global, non rattachable à un jour précis) — sans cette
    // ligne, la somme des mois semble ne pas coller au total affiché en haut.
    const monthsNoteEl = document.getElementById('annual-months-adjust-note');
    const ajustGlobalMois = objectifInfo.ajustFractionnement + objectifInfo.ajust48h;
    if (ajustGlobalMois > 0) {
        const partsMois = [];
        if (objectifInfo.ajustFractionnement > 0) partsMois.push(I18N_PLANNING.fractionnement_h_court.replace('{n}', objectifInfo.ajustFractionnement).replace('{j}', objectifInfo.joursFractionnement));
        if (objectifInfo.ajust48h > 0) partsMois.push(I18N_PLANNING.semaines_48h_court.replace('{n}', objectifInfo.ajust48h).replace('{s}', objectifInfo.semaines48hCount));
        monthsNoteEl.innerHTML = `<i class="fa-solid fa-circle-info"></i> ` + I18N_PLANNING.somme_mois_ajustement.replace('{n}', ajustGlobalMois.toFixed(0)).replace('{parts}', partsMois.join(' + '));
    } else {
        monthsNoteEl.textContent = '';
    }

    const fracSelect = document.getElementById('annual-fractionnement-select');
    fracSelect.value = String(fractionnementAnnuel[tech] || 0);
    document.getElementById('annual-objectif-input').value = objectifsAnnuels[tech] || 1607;

    appliquerVueAnnuelleMobile();
}

function openAnnualModal(tech) {
    if (!tech) return;
    annualModalTech = tech;
    annualZoom = 1;
    appliquerZoomAnnuel();
    // Vue mensuelle par défaut sur mobile/tablette (voir appliquerVueAnnuelleMobile), vue d'ensemble
    // classique sur desktop — recalculé à chaque ouverture, pas mémorisé d'une fois sur l'autre.
    annualViewMode = ecranEtroit() ? 'mensuel' : 'global';
    annualActiveMonthIndex = null;
    document.getElementById('annual-modal-title').textContent = I18N_PLANNING.planning_annuel_tech.replace('{tech}', tech);
    // Un technicien ne peut modifier que son propre objectif et son propre fractionnement, l'admin peut
    // modifier ceux de n'importe qui (même règle que les backends maintenance.php?action=save_objectif et
    // save_fractionnement) — chacun gère ses propres heures. Champ toujours visible (plus de crayon à
    // cliquer) : chaque ligne (label + contrôle) se masque entièrement d'un coup via display:contents ->
    // none quand l'utilisateur n'a pas le droit.
    const peutGererSesHeures = (isAdmin || tech === currentUser) ? 'contents' : 'none';
    document.getElementById('annual-objectif-row').style.display = peutGererSesHeures;
    document.getElementById('annual-fractionnement-row').style.display = peutGererSesHeures;
    resetObjectifInput();
    renderAnnualLegend();
    renderAnnualGrid(tech);
    bringModalToFront('annualModal');
    document.getElementById('annualModal').style.display = 'block';
}

function resetObjectifInput() {
    document.getElementById('annual-objectif-input').value = objectifsAnnuels[annualModalTech] || 1607;
}

async function saveObjectifAnnuel() {
    const input = document.getElementById('annual-objectif-input');
    const val = parseFloat(String(input.value).replace(',', '.'));
    if (!val || val <= 0 || val > 9999) return;

    const fd = new FormData();
    fd.append('action', 'save_objectif');
    fd.append('tech', annualModalTech);
    fd.append('objectif', val);
    await fetch('maintenance.php', { method: 'POST', body: fd });

    objectifsAnnuels[annualModalTech] = val;
    renderAnnualGrid(annualModalTech);
}

async function saveFractionnement(val) {
    const jours = parseInt(val, 10) || 0;
    const fd = new FormData();
    fd.append('action', 'save_fractionnement');
    fd.append('tech', annualModalTech);
    fd.append('periode_debut', periodeAnnualisationDebut);
    fd.append('jours', jours);
    await fetch('maintenance.php', { method: 'POST', body: fd });

    fractionnementAnnuel[annualModalTech] = jours;
    renderAnnualGrid(annualModalTech);
}

async function saveShift() {
    const heures = document.getElementById('shift-heures').value;
    const note = shiftNoteVisible ? document.getElementById('shift-note').value.trim() : shiftNoteExistante;
    const fd = new FormData();
    fd.append('action', 'save_shift');
    fd.append('tech', shiftTech);
    fd.append('date', shiftDate);
    fd.append('poste', shiftPoste || '');
    fd.append('astreinte', shiftAstreinte || '');
    fd.append('heures', heures);
    fd.append('note', note);
    fd.append('demi_conge', shiftDemiConge ? '1' : '');
    fd.append('jour_ferie', shiftJourFerie ? '1' : '');
    await fetch('maintenance.php', { method: 'POST', body: fd });

    const key = `${shiftTech}_${shiftDate}`;
    const existing = shiftsParCle[key] || {};
    const oldH = (parseFloat(existing.heures) || 0) + creditMaladie(existing.poste);
    const newH = (parseFloat(heures) || 0) + creditMaladie(shiftPoste);
    totauxAnnualises[shiftTech] = (totauxAnnualises[shiftTech] || 0) - oldH + newH;
    shiftsParCle[key] = { utilisateur: shiftTech, jour: shiftDate, poste: shiftPoste, astreinte: shiftAstreinte, heures: heures !== '' ? parseFloat(heures) : null, note: note || null, demi_conge: shiftDemiConge ? 1 : 0, jour_ferie: shiftJourFerie ? 1 : 0 };

    closeShiftModal();
    renderTable();
    if (document.getElementById('annualModal').style.display === 'block') { renderAnnualGrid(shiftTech); }
}

async function clearShift() {
    const fd = new FormData();
    fd.append('action', 'clear_shift');
    fd.append('tech', shiftTech);
    fd.append('date', shiftDate);
    await fetch('maintenance.php', { method: 'POST', body: fd });

    const key = `${shiftTech}_${shiftDate}`;
    const existing = shiftsParCle[key] || {};
    const oldH = (parseFloat(existing.heures) || 0) + creditMaladie(existing.poste);
    totauxAnnualises[shiftTech] = (totauxAnnualises[shiftTech] || 0) - oldH;
    delete shiftsParCle[key];

    closeShiftModal();
    renderTable();
    if (document.getElementById('annualModal').style.display === 'block') { renderAnnualGrid(shiftTech); }
}

async function handleDrop(e, tech, dateStr) {
    e.preventDefault();
    const taskId = e.dataTransfer.getData("taskId");
    const pointageId = e.dataTransfer.getData("pointageId");

    const fd = new URLSearchParams();
    
    if (pointageId) {
        fd.append('action', 'update_pointage_planif');
        fd.append('id', pointageId);
        fd.append('tech', tech);
        fd.append('date', dateStr);
    } else {
        fd.append('action', 'save_pointage');
        fd.append('task_id', taskId);
        fd.append('tech', tech);
        fd.append('date', dateStr);
        fd.append('hours', '0');
    }
    
    await fetch('maintenance.php', { method: 'POST', body: fd });
    loadData();
}

async function saveTask() {
    const id = document.getElementById('m-id').value;
    const isUpdate = id !== "";
    
    let task = isUpdate ? tasks.find(t => t.id == id) : {};
    if(isUpdate && !task) return;
    
    let heureFixe = isUpdate && task.date.includes(' ') ? ' ' + task.date.split(' ')[1] : ' 08:00';
    
    const isST = document.getElementById('m-is-st').checked;
    const selectEE = document.getElementById('m-entreprise');
    
    const techFinal = document.getElementById('m-tech').value;
    const idEE = isST ? selectEE.value : null;

    const idTache = isUpdate ? id : ("ID-" + Date.now());

    Object.assign(task, {
        id: idTache,
        tech: techFinal,
        is_sous_traitant: isST ? 1 : 0,
        entreprise_ext_id: idEE,
        demandeur: document.getElementById('m-demandeur').value,
        
        // --- AJOUTS : Envoi des cascades à la base de données ---
        usine: document.getElementById('m-usine').value,
        secteur: document.getElementById('m-secteur').value,
        zone: document.getElementById('m-zone').value,
        equip: document.getElementById('m-equip').value, // La machine finale
        
        desc: document.getElementById('m-desc').value, 
        hours: document.getElementById('m-hours').value,
        date: document.getElementById('m-date').value + heureFixe, 
        prio: document.getElementById('m-prio').value,
        statut: document.getElementById('m-statut').value,
        type: document.getElementById('m-type').value,
        casse: document.getElementById('m-casse').checked ? 1 : 0,
        action_user: "<?php echo $_SESSION['user']; ?>"
    });
    
    if (!isUpdate) { task.num_bi = genererNumeroBI(); }
    if (!isUpdate) { 
        const maintenant = new Date();
        const dateLocale = maintenant.getFullYear() + '-' + String(maintenant.getMonth() + 1).padStart(2, '0') + '-' + String(maintenant.getDate()).padStart(2, '0');
        const heureLocale = String(maintenant.getHours()).padStart(2, '0') + ':' + String(maintenant.getMinutes()).padStart(2, '0');
        task.date_creation = dateLocale + ' ' + heureLocale; 
    }
    
    // 1. Sauvegarde de la tâche (Table taches)
    await fetch('api.php', { method: 'POST', body: JSON.stringify(task) });

    // 2. NOUVEAU : Création du pointage (Pour l'affichage sur le planning)
    if (!isUpdate) {
        const fd = new URLSearchParams();
        fd.append('action', 'save_pointage');
        fd.append('task_id', idTache);
        fd.append('tech', techFinal);
        fd.append('date', document.getElementById('m-date').value);
        fd.append('hours', '0'); 
        
        await fetch('maintenance.php', { method: 'POST', body: fd });
    }
    
    closeModal(); 
    loadData();
}

function deleteTask() {
    if (!isAdmin) return;
    
    const id = document.getElementById('m-id').value;
    if (!id) return;

    idToDelete = id;
    
    const modalDel = document.getElementById('modalConfirmDel');
    if (modalDel) {
        modalDel.style.zIndex = "200020"; // Passe devant la modale d'édition du planning
        modalDel.style.display = 'block';
    }
    
    document.getElementById('btnConfirmDeleteFinal').onclick = async function() {
        await executerSuppressionRéelle();
    };
}

// --- NOUVELLE FONCTION DE SUPPRESSION DEPUIS LE RAPPORT ---
async function deleteTaskFromReport(id) {
    if (await aspirineConfirm(I18N_PLANNING.confirmation_title, I18N_PLANNING.confirm_suppr_definitive)) {
        await fetch(`api.php?delete=${id}`);
        document.getElementById('modalDetailBI').style.display = 'none';
        loadData();
    }
}

// Variable pour stocker le chrono et éviter les bugs si on clique trop vite sur les flèches
let weekTimeout = null;

function changeWeek(dir) { 
    let newDate = new Date(currentMonday);
    newDate.setDate(newDate.getDate() + (dir * 7));
    currentMonday = newDate; 
    
    // 1. On va chercher notre indicateur du milieu de l'écran
    const indicator = document.getElementById('week-indicator');
    if (indicator) {
        // 2. On lui injecte le numéro de la nouvelle semaine avec l'écriture Caveat
        indicator.innerHTML = `${I18N_PLANNING.planning_word} <span>${I18N_PLANNING.semaine} ${getWeekNumber(currentMonday)}</span>`;
        
        // 3. On lui ajoute la classe CSS pour l'allumer instantanément
        indicator.classList.add('show');
        
        // 4. Sécurité : si un chrono était déjà en cours (clics rapides), on l'annule
        if (weekTimeout) clearTimeout(weekTimeout);
        
        // 5. On programme l'extinction automatique en fondu au bout de 1,5 seconde
        weekTimeout = setTimeout(() => {
            indicator.classList.remove('show');
        }, 1500);
    }

    // 6. On recharge les données du planning normalement
    loadData(); 
}

initCascade();
appliquerModeAffichage();
appliquerModeTech();
ajusterPaddingHeader();
loadData();

function genererNumeroBI() {
    const now = new Date();
    const annee = now.getFullYear().toString().slice(-2); 
    const prefixeAnnee = "BI" + annee + "-"; 
    const bonsAnnee = tasks.filter(t => t.num_bi && t.num_bi.startsWith(prefixeAnnee));
    if (bonsAnnee.length === 0) return prefixeAnnee + "001";
    const numeros = bonsAnnee.map(t => {
        const parties = t.num_bi.split('-');
        return parties[1] ? parseInt(parties[1]) : 0;
    });
    const max = Math.max(...numeros);
    return prefixeAnnee + (max + 1).toString().padStart(3, '0');
}
// Variable globale pour stocker l'élément en cours de suppression sur le planning
let idToDelete = null;

function closeConfirmDel() {
    document.getElementById('modalConfirmDel').style.display = 'none';
    idToDelete = null;
    // Réinitialise le texte par défaut
    const textZone = document.querySelector('#modalConfirmDel p');
    if (textZone) {
        textZone.innerHTML = `${I18N_PLANNING.confirm_suppr_intervention}<br><span style="font-weight: bold; color: var(--danger);">${I18N_PLANNING.action_irreversible}</span>`;
    }
}

async function executerSuppressionRéelle() {
    if (!idToDelete) return;

    try {
        const response = await fetch(`api.php?delete=${encodeURIComponent(idToDelete)}`);
        if (response.ok) {
            closeConfirmDel();
            // On masque la modale d'édition du planning classique au cas où elle était ouverte
            closeModal();
            // On recharge les données du planning de manière fluide
            loadData();
        } else {
            await aspirineAlert(I18N_PLANNING.err_title, I18N_PLANNING.err_suppr_serveur);
        }
    } catch (error) {
        console.error("Erreur technique lors de la suppression du planning:", error);
    }
}


// ============================================================================
// SAUVEGARDE DU RAPPORT INTERMÉDIAIRE ET MODALES
// ============================================================================
async function sauvegarderNoteIntermediaire(id) {
    const textareaNote = document.getElementById('note-intermediaire-' + id);
    if (!textareaNote) return;

    const note = textareaNote.value;
    const btn = document.getElementById('btn-save-note-' + id);
    
    const oldText = btn.innerHTML;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> ' + I18N_PLANNING.saving;
    btn.disabled = true;

    try {
        const response = await fetch('api.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id: id, rapport_intermediaire: note, action: 'update_note' })
        });

        if (response.ok) {
            await aspirineAlert(I18N_PLANNING.success_title, I18N_PLANNING.rapport_intermediaire_maj);
            await loadData();
        } else {
            await aspirineAlert(I18N_PLANNING.err_title, I18N_PLANNING.err_server_refused);
        }
    } catch(e) {
        console.error("Erreur de sauvegarde:", e);
        await aspirineAlert(I18N_PLANNING.err_network_title, I18N_PLANNING.err_network_unreachable);
    }

    btn.innerHTML = oldText;
    btn.disabled = false;
}

function aspirineConfirm(titre, message) {
    return new Promise((resolve) => {
        const modal = document.getElementById('customConfirm');
        if(!modal) return resolve(confirm(message)); // Sécurité
        document.getElementById('confirmTitle').innerText = titre;
        document.getElementById('confirmMessage').innerText = message;
        modal.style.display = 'block';
        document.getElementById('confirmOk').onclick = () => { modal.style.display = 'none'; resolve(true); };
        document.getElementById('confirmCancel').onclick = () => { modal.style.display = 'none'; resolve(false); };
    });
}

function aspirineAlert(titre, message) {
    return new Promise((resolve) => {
        const modal = document.getElementById('customAlert');
        if(!modal) { alert(message); return resolve(); } // Sécurité
        document.getElementById('alertTitle').innerText = titre;
        document.getElementById('alertMessage').innerText = message;
        modal.style.display = 'block';
        document.getElementById('alertOk').onclick = () => { modal.style.display = 'none'; resolve(); };
    });
}

</script>

<div id="modalConfirmDel" class="modal" onclick="if(event.target == this) closeConfirmDel()">
    <div class="modal-content" style="max-width: 400px !important; margin: 12% auto !important; border-top: 6px solid var(--danger); text-align: center; padding: 30px; border-radius: 15px;">
        <div style="color: var(--danger); font-size: 4rem; margin-bottom: 15px;">
            <i class="fa-solid fa-circle-exclamation"></i>
        </div>
        <h2 style="font-family: 'Caveat', cursive; font-size: 2.2rem; color: var(--primary); margin: 0 0 10px 0;"><?php echo htmlspecialchars(t('planning.modal_supprimer_titre')); ?></h2>
        <p style="color: #64748b; font-size: 0.95rem; line-height: 1.5; margin-bottom: 25px;">
            <?php echo htmlspecialchars(t('planning.confirm_suppr_intervention')); ?><br>
            <span style="font-weight: bold; color: var(--danger);"><?php echo htmlspecialchars(t('planning.action_irreversible')); ?></span>
        </p>

        <div style="display: flex; gap: 10px; justify-content: center;">
            <button onclick="closeConfirmDel()" style="flex: 1; background: #f1f5f9; border: none; color: #64748b; padding: 12px; border-radius: 8px; font-weight: 700; cursor: pointer; transition: 0.2s; font-family: inherit;">
                <?php echo htmlspecialchars(t('planning.btn_annuler')); ?>
            </button>
            <button id="btnConfirmDeleteFinal" style="flex: 1; background: var(--danger); border: none; color: white; padding: 12px; border-radius: 8px; font-weight: 700; cursor: pointer; transition: 0.2s; box-shadow: 0 4px 12px rgba(231, 76, 60, 0.2); font-family: inherit;">
                <?php echo htmlspecialchars(t('planning.btn_supprimer')); ?>
            </button>
        </div>
    </div>
</div>

<div id="customConfirm" style="display:none; position:fixed; z-index:99999; left:0; top:0; width:100%; height:100%; background:rgba(0,0,0,0.5); backdrop-filter: blur(3px);">
    <div style="background:white; width:350px; margin:15% auto; padding:20px; border-radius:12px; text-align:center; box-shadow: 0 10px 25px rgba(0,0,0,0.2); border-top: 5px solid var(--brand-orange);">
        <i class="fa-solid fa-circle-question" style="font-size:3rem; color:var(--brand-orange); margin-bottom:15px;"></i>
        <h3 id="confirmTitle" style="margin:10px 0; color:var(--dark-blue);"><?php echo htmlspecialchars(t('planning.confirmation_title')); ?></h3>
        <p id="confirmMessage" style="color:#666; font-size:0.9rem; margin-bottom:20px;"><?php echo htmlspecialchars(t('planning.confirmation_msg_default')); ?></p>
        <div style="display:flex; justify-content:center; gap:10px;">
            <button id="confirmCancel" style="padding:10px 20px; border:none; border-radius:6px; background:#eee; cursor:pointer; font-weight:bold; font-family: inherit;"><?php echo htmlspecialchars(t('maint.cancel')); ?></button>
            <button id="confirmOk" style="padding:10px 20px; border:none; border-radius:6px; background:var(--brand-orange); color:white; cursor:pointer; font-weight:bold; font-family: inherit;"><?php echo htmlspecialchars(t('planning.btn_confirmer')); ?></button>
        </div>
    </div>
</div>

<div id="customAlert" style="display:none; position:fixed; z-index:99999; left:0; top:0; width:100%; height:100%; background:rgba(0,0,0,0.5); backdrop-filter: blur(3px);">
    <div style="background:white; width:350px; margin:15% auto; padding:20px; border-radius:12px; text-align:center; box-shadow: 0 10px 25px rgba(0,0,0,0.2); border-top: 5px solid #27ae60;">
        <i class="fa-solid fa-circle-check" style="font-size:3rem; color:#27ae60; margin-bottom:15px;"></i>
        <h3 id="alertTitle" style="margin:10px 0; color:var(--dark-blue);"><?php echo htmlspecialchars(t('planning.succes_title')); ?></h3>
        <p id="alertMessage" style="color:#666; font-size:0.9rem; margin-bottom:20px;"><?php echo htmlspecialchars(t('planning.operation_reussie')); ?></p>
        <div style="display:flex; justify-content:center;">
            <button id="alertOk" style="padding:10px 30px; border:none; border-radius:6px; background:#27ae60; color:white; cursor:pointer; font-weight:bold; font-family: inherit;"><?php echo htmlspecialchars(t('planning.btn_ok')); ?></button>
        </div>
    </div>
</div>

<?php include 'composant_rapport.php'; ?>

</body>
</html>