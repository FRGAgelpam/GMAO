<?php
require_once __DIR__ . '/session_init.php';

// --- SÉCURITÉ : Uniquement les Admins pour le Plan Préventif ---
if (!isset($_SESSION['user']) || $_SESSION['role'] !== 'admin') {
    header("Location: maintenance.php");
    exit();
}

$is_admin = true;
$hide_menu_button = true;

// --- CATÉGORIES (configurables depuis Paramètres > Catégories de préventif) ---
require_once 'db.php';
$CATEGORIES_META = [];
$CATEGORIES_LISTE = [];
if (isset($db)) {
    try {
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
            $CATEGORIES_META[$cRow['cle']] = ['label' => t('preventif.prefix_categorie') . ' ' . $cRow['label'], 'icon' => $cRow['icone'], 'couleur' => $cRow['couleur']];
            $CATEGORIES_LISTE[$cRow['cle']] = ['label' => $cRow['label'], 'icon' => $cRow['icone'], 'couleur' => $cRow['couleur']];
        }
    } catch (Exception $e) {}
}
$cat = $_GET['cat'] ?? '';
if (!isset($CATEGORIES_META[$cat])) { $cat = ''; }
$breadcrumb_parent_label = t('preventif.breadcrumb');
$breadcrumb_parent_href = 'preventif.php';
$breadcrumb_label = $cat ? $CATEGORIES_LISTE[$cat]['label'] : t('preventifliste.plan_preventif');

// ============================================================================
// 1. CONNEXION BDD POUR RENDRE LES LISTES DYNAMIQUES
// ============================================================================
$machines_db = [];
$team_maintenance = [];
$services_liste = [];
$entreprises_ext = [];

try {
    require_once 'db.php';
    if(isset($db)) {
        // --- Récupération des Machines (Avec filet de sécurité pour "ligne") ---
        try {
            // On essaie avec la colonne 'ligne'
            $stmt = $db->query("SELECT usine, secteur, ligne, zone, nom_machine FROM machines ORDER BY usine, secteur, ligne, zone, nom_machine");
            $machines_db = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            // Si la colonne 'ligne' n'existe pas encore dans MariaDB, on récupère le reste
            $stmt = $db->query("SELECT usine, secteur, zone, nom_machine FROM machines ORDER BY usine, secteur, zone, nom_machine");
            $machines_db = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }

        // --- Récupération des Techniciens / Déclarants ---
        $resEquipe = $db->query("SELECT username FROM utilisateurs WHERE role IN ('admin', 'technicien') ORDER BY username ASC");
        if($resEquipe) {
            while($row = $resEquipe->fetch(PDO::FETCH_ASSOC)) {
                $team_maintenance[] = $row['username'];
            }
        }

        // --- Services (configurables depuis Paramètres) : pour "Signalé par" dans la checklist —
        // une tâche peut être remontée par un service (Production, Qualité...) et pas seulement par
        // un technicien. ---
        try {
            $resServices = $db->query("SELECT label FROM services ORDER BY ordre ASC");
            if ($resServices) { while ($row = $resServices->fetch(PDO::FETCH_ASSOC)) { $services_liste[] = $row['label']; } }
        } catch (Exception $e) {}

        // --- Entreprises extérieures (table sous_traitants.php) : pour marquer une tâche de la
        // checklist saisonnière comme sous-traitée, même logique que le formulaire d'OT. ---
        try {
            $resEE = $db->query("SELECT id, nom FROM entreprises_ext ORDER BY nom");
            if ($resEE) { $entreprises_ext = $resEE->fetchAll(PDO::FETCH_ASSOC); }
        } catch (Exception $e) {}

        // --- Table des règles de maintenance préventive (remplace preventifs.json) ---
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

        // Catégorie de classement (Process / Qualité / Réglementaire / Quotidien) — ajoutée après coup, les règles
        // existantes n'ayant pas encore ce champ basculent par défaut sur "process".
        $db->exec("ALTER TABLE preventif_regles ADD COLUMN IF NOT EXISTS categorie VARCHAR(30) DEFAULT 'process'");
        $db->exec("UPDATE preventif_regles SET categorie = 'process' WHERE categorie IS NULL OR categorie = ''");
        // categorie doit accueillir la même longueur que preventif_categories.cle (VARCHAR(30)) :
        // une catégorie créée depuis Paramètres avec un libellé un peu long générait une clé tronquée
        // en silence à l'enregistrement d'une règle (ex. "maintenance_preventive" -> "maintenance_preventi"),
        // ce qui décrochait la règle de sa catégorie.
        $db->exec("ALTER TABLE preventif_regles MODIFY COLUMN categorie VARCHAR(30) DEFAULT 'process'");

        // Assignation auto au technicien du planning "Matin" du jour (au lieu de l'intervenant fixe
        // de la règle) — utile pour les tâches qui doivent toujours revenir à celui qui est présent
        // le matin, quel qu'il soit ce jour-là. Lu par cron_preventif.php et par l'action
        // "generer_demande" (génération manuelle depuis le tableau).
        $db->exec("ALTER TABLE preventif_regles ADD COLUMN IF NOT EXISTS auto_matin TINYINT(1) NOT NULL DEFAULT 0");

        // Lien de traçabilité règle -> ticket généré (redondant avec api.php, cron_preventif.php ne passe pas par api.php)
        $db->exec("ALTER TABLE taches ADD COLUMN IF NOT EXISTS rule_id VARCHAR(64) DEFAULT NULL");

        // --- Checklist saisonnière : tâches ponctuelles (une fois, pas de récurrence) groupées par
        // catégorie + saison — pour les demandes du SAS qui n'appellent pas une vraie règle récurrente
        // (voir transfererVersPreventif() dans maintenance.php). À ne pas confondre avec preventif_regles,
        // qui génère automatiquement des BI via cron_preventif.php.
        $db->exec("CREATE TABLE IF NOT EXISTS preventif_checklist (
            id VARCHAR(64) PRIMARY KEY,
            categorie VARCHAR(30) NOT NULL DEFAULT 'process',
            saison VARCHAR(20) NOT NULL DEFAULT '',
            usine VARCHAR(150) DEFAULT NULL, equip VARCHAR(255), descr TEXT, signale_par VARCHAR(100),
            intervenant VARCHAR(150) DEFAULT NULL, date_prevue DATE DEFAULT NULL, prio VARCHAR(4) DEFAULT '',
            statut VARCHAR(20) DEFAULT 'a_faire',
            date_ajout DATETIME DEFAULT CURRENT_TIMESTAMP,
            fait TINYINT(1) DEFAULT 0, fait_par VARCHAR(100) DEFAULT NULL, date_fait DATETIME DEFAULT NULL,
            ordre INT DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        // Ajoutés après coup (assignation d'un intervenant, date prévue, priorité, statut à 3 états) :
        // table déjà en place sur les installs existantes avant ces ajouts.
        $db->exec("ALTER TABLE preventif_checklist ADD COLUMN IF NOT EXISTS intervenant VARCHAR(150) DEFAULT NULL");
        $db->exec("ALTER TABLE preventif_checklist ADD COLUMN IF NOT EXISTS date_prevue DATE DEFAULT NULL");
        $db->exec("ALTER TABLE preventif_checklist ADD COLUMN IF NOT EXISTS prio VARCHAR(4) DEFAULT ''");
        $db->exec("ALTER TABLE preventif_checklist ADD COLUMN IF NOT EXISTS statut VARCHAR(20) DEFAULT 'a_faire'");
        // Colonne "Usine" séparée de l'équipement (ex. document Excel : colonne A = usine/zone,
        // colonne B = machine/lieu) — pour pouvoir filtrer par usine sans dépendre d'un préfixe répété
        // dans le texte de l'équipement.
        $db->exec("ALTER TABLE preventif_checklist ADD COLUMN IF NOT EXISTS usine VARCHAR(150) DEFAULT NULL");
        // Sous-traitance (même principe que taches.is_sous_traitant / entreprise_ext_id sur les OT) :
        // une tâche de checklist saisonnière peut être confiée à une entreprise extérieure plutôt qu'à
        // l'équipe interne.
        $db->exec("ALTER TABLE preventif_checklist ADD COLUMN IF NOT EXISTS is_sous_traitant TINYINT(1) NOT NULL DEFAULT 0");
        $db->exec("ALTER TABLE preventif_checklist ADD COLUMN IF NOT EXISTS entreprise_ext_id INT DEFAULT NULL");

        // Liste gérée des usines/zones (créer + renommer depuis le formulaire de tâche) — évite que
        // chacun tape ses propres variantes en saisie libre (ex. "Usine B" vs "USINE B").
        $db->exec("CREATE TABLE IF NOT EXISTS preventif_checklist_zones (
            id INT AUTO_INCREMENT PRIMARY KEY,
            label VARCHAR(150) UNIQUE,
            ordre INT DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        if ((int)$db->query("SELECT COUNT(*) FROM preventif_checklist_zones")->fetchColumn() === 0) {
            // Amorçage unique à partir des usines déjà utilisées, pour ne rien perdre à l'arrivée de
            // cette gestion (ex. import Excel déjà fait avant que cette table n'existe).
            $stmtSeedZ = $db->prepare("INSERT IGNORE INTO preventif_checklist_zones (label, ordre) VALUES (?, ?)");
            $existantes = $db->query("SELECT DISTINCT usine FROM preventif_checklist WHERE usine IS NOT NULL AND usine <> '' ORDER BY usine")->fetchAll(PDO::FETCH_COLUMN);
            foreach ($existantes as $i => $label) { $stmtSeedZ->execute([$label, $i]); }
        }
        // Migration ponctuelle : les lignes déjà cochées "fait" (ancien système binaire) passent
        // directement en "Terminé" plutôt que de repartir à zéro sur "À faire".
        $db->exec("UPDATE preventif_checklist SET statut = 'termine' WHERE fait = 1 AND (statut IS NULL OR statut = 'a_faire')");

        // Saison courante par défaut : une "saison" à cheval sur deux années civiles (ex. maintenance
        // hivernale) bascule au 1er août plutôt qu'au 1er janvier — sinon on se retrouverait avec deux
        // saisons différentes en plein hiver. Purement un libellé par défaut : reste modifiable via
        // "Dupliquer pour la saison suivante".
        $moisActuel = (int)date('n');
        $anneeRef = (int)date('Y');
        $saisonCourante = $moisActuel >= 8 ? ($anneeRef . '-' . ($anneeRef + 1)) : (($anneeRef - 1) . '-' . $anneeRef);

        // --- Migration ponctuelle : import de l'ancien preventifs.json si la table est vide ---
        try {
            $cntRegles = (int)$db->query("SELECT COUNT(*) FROM preventif_regles")->fetchColumn();
            if ($cntRegles === 0 && file_exists('preventifs.json')) {
                $legacy = json_decode(file_get_contents('preventifs.json'), true) ?: [];
                if ($legacy) {
                    $insLegacy = $db->prepare("INSERT IGNORE INTO preventif_regles
                        (id, equip, descr, hours, type_op, prio, mode_planif, freq, jours, echeance,
                         alerte_val, alerte_unit, usine, secteur, ligne, zone, impact, arret_h,
                         intervenant, declarant, cause, pieces, commentaires, status, categorie, last_gen, last_gen_date)
                        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
                    foreach ($legacy as $p) {
                        $insLegacy->execute([
                            $p['id'] ?? uniqid('PLAN-'), $p['equip'] ?? '', $p['desc'] ?? '', $p['hours'] ?? '',
                            $p['type_op'] ?? '', $p['prio'] ?? 'Normal', 'frequence', $p['freq'] ?? '', null,
                            !empty($p['echeance']) ? $p['echeance'] : null, $p['alerte_val'] ?? '', $p['alerte_unit'] ?? 'jours',
                            $p['usine'] ?? '', $p['secteur'] ?? '', $p['ligne'] ?? '', $p['zone'] ?? '',
                            $p['impact'] ?? '', $p['arret_h'] ?? '', $p['intervenant'] ?? '', $p['declarant'] ?? '',
                            $p['cause'] ?? '', $p['pieces'] ?? '', $p['commentaires'] ?? '', $p['status'] ?? 'actif',
                            $p['categorie'] ?? 'process',
                            !empty($p['last_gen']) ? $p['last_gen'] : null,
                            !empty($p['last_gen']) ? substr($p['last_gen'], 0, 10) : null
                        ]);
                    }
                }
            }
        } catch (Throwable $e) {}
    }
} catch (Throwable $e) {}

$json_machines = json_encode($machines_db ?: []);
$json_team = json_encode($team_maintenance ?: []);
$json_services = json_encode($services_liste ?: []);
$json_entreprises_ext = json_encode($entreprises_ext ?: []);


// ============================================================================
// 2. API DES RÈGLES DE MAINTENANCE PRÉVENTIVE (table preventif_regles)
// ============================================================================

// --- Liste des règles (remplace l'ancien fetch direct de preventifs.json) ---
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'list') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $rows = $db->query("SELECT id, equip, descr AS `desc`, hours, type_op, prio, mode_planif, freq, jours,
            DATE_FORMAT(echeance,'%Y-%m-%d') AS echeance, alerte_val, alerte_unit, usine, secteur, ligne, zone,
            impact, arret_h, intervenant, declarant, cause, pieces, commentaires, status, categorie, auto_matin, last_gen
            FROM preventif_regles ORDER BY equip, descr")->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode($rows, JSON_INVALID_UTF8_SUBSTITUTE);
    } catch (Exception $e) {
        echo json_encode([]);
    }
    exit();
}

// --- Historique des occurrences générées (globale ou filtrée sur une règle) ---
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'history') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $ruleId = $_GET['rule_id'] ?? null;
        $sql = "SELECT id, num_bi, equip, description AS `desc`, date, statut, tech, compte_rendu, rule_id
                FROM taches WHERE rule_id IS NOT NULL " . ($ruleId ? "AND rule_id = ?" : "") . " ORDER BY date DESC LIMIT 200";
        $stmt = $db->prepare($sql);
        $stmt->execute($ruleId ? [$ruleId] : []);
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC), JSON_INVALID_UTF8_SUBSTITUTE);
    } catch (Exception $e) {
        echo json_encode([]);
    }
    exit();
}

// --- Checklist saisonnière : liste des tâches d'une catégorie, saison la plus récente par défaut ---
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'checklist_list') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $categorieDemandee = $_GET['categorie'] ?? '';
        $saisonDemandee = $_GET['saison'] ?? '';
        if (!$saisonDemandee) {
            // Priorité à la saison "en cours" (calculée depuis la date du jour, voir $saisonCourante
            // plus haut) si elle a déjà des tâches — sinon on atterrissait systématiquement sur la
            // dernière saison créée en base (souvent juste quelques tâches reportées en avance),
            // pas celle qu'on veut voir au quotidien.
            $chkCourante = $db->prepare("SELECT COUNT(*) FROM preventif_checklist WHERE categorie = ? AND saison = ?");
            $chkCourante->execute([$categorieDemandee, $saisonCourante]);
            if ($chkCourante->fetchColumn() > 0) {
                $saisonDemandee = $saisonCourante;
            } else {
                $stmtSaison = $db->prepare("SELECT saison FROM preventif_checklist WHERE categorie = ? ORDER BY saison DESC LIMIT 1");
                $stmtSaison->execute([$categorieDemandee]);
                $saisonDemandee = $stmtSaison->fetchColumn() ?: $saisonCourante;
            }
        }
        $stmt = $db->prepare("SELECT c.id, c.categorie, c.saison, c.usine, c.equip, c.descr AS `desc`, c.signale_par,
            c.intervenant, DATE_FORMAT(c.date_prevue,'%Y-%m-%d') AS date_prevue, c.prio,
            COALESCE(NULLIF(c.statut,''),'a_faire') AS statut,
            DATE_FORMAT(c.date_ajout,'%Y-%m-%d') AS date_ajout, c.fait, c.fait_par,
            DATE_FORMAT(c.date_fait,'%Y-%m-%d') AS date_fait,
            c.is_sous_traitant, c.entreprise_ext_id, e.nom AS nom_entreprise
            FROM preventif_checklist c LEFT JOIN entreprises_ext e ON c.entreprise_ext_id = e.id
            WHERE c.categorie = ? AND c.saison = ? ORDER BY c.date_ajout ASC");
        $stmt->execute([$categorieDemandee, $saisonDemandee]);
        // Liste des saisons existantes pour cette catégorie, pour le sélecteur côté client
        $stmtToutesSaisons = $db->prepare("SELECT DISTINCT saison FROM preventif_checklist WHERE categorie = ? ORDER BY saison DESC");
        $stmtToutesSaisons->execute([$categorieDemandee]);
        echo json_encode([
            'saison' => $saisonDemandee,
            'saisons_disponibles' => $stmtToutesSaisons->fetchAll(PDO::FETCH_COLUMN),
            'items' => $stmt->fetchAll(PDO::FETCH_ASSOC)
        ], JSON_INVALID_UTF8_SUBSTITUTE);
    } catch (Exception $e) {
        echo json_encode(['saison' => $saisonCourante, 'saisons_disponibles' => [], 'items' => []]);
    }
    exit();
}

// --- Liste des zones/usines gérées (formulaire de tâche de la checklist) ---
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'checklist_zones_list') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        echo json_encode($db->query("SELECT id, label FROM preventif_checklist_zones ORDER BY ordre ASC, label ASC")->fetchAll(PDO::FETCH_ASSOC), JSON_INVALID_UTF8_SUBSTITUTE);
    } catch (Exception $e) {
        echo json_encode([]);
    }
    exit();
}

// --- Création / modification / suppression d'une règle ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);
    if ($data) {
        if (isset($data['action']) && $data['action'] === 'delete') {
            $stmt = $db->prepare("DELETE FROM preventif_regles WHERE id = ?");
            $stmt->execute([$data['id']]);
        } elseif (isset($data['action']) && $data['action'] === 'generer_demande') {
            // Génération manuelle d'une demande à partir d'une règle, hors cycle du cron — reproduit
            // exactement le même INSERT que cron_preventif.php (num_bi jamais renseigné : la tâche
            // atterrit dans le SAS de validation, ce n'est PAS un bon d'intervention direct).
            header('Content-Type: application/json; charset=utf-8');
            $stmtRegle = $db->prepare("SELECT * FROM preventif_regles WHERE id = ?");
            $stmtRegle->execute([$data['id'] ?? '']);
            $regle = $stmtRegle->fetch(PDO::FETCH_ASSOC);
            if (!$regle) {
                echo json_encode(["status" => "error", "message" => t('preventifliste.err_regle_introuvable')]);
                exit();
            }

            $maintenant = date('Y-m-d H:i:s');
            $aujourdhui = date('Y-m-d');
            $autoMatinSansTech = false;
            $tech = !empty($regle['intervenant']) ? $regle['intervenant'] : null;
            if (!empty($regle['auto_matin'])) {
                $stmtMatin = $db->prepare("SELECT utilisateur FROM planning_shifts WHERE jour = ? AND poste = 'matin' ORDER BY utilisateur ASC LIMIT 1");
                $stmtMatin->execute([$aujourdhui]);
                $techMatin = $stmtMatin->fetchColumn();
                $tech = $techMatin !== false ? $techMatin : null;
                $autoMatinSansTech = ($techMatin === false);
            }

            $stmtMax = $db->query("SELECT MAX(CAST(id AS UNSIGNED)) as max_id FROM taches");
            $nouvel_id = ((int)($stmtMax->fetch(PDO::FETCH_ASSOC)['max_id'] ?? 0)) + 1;

            $stmtIns = $db->prepare("INSERT INTO taches
                (id, date, equip, description, statut, type, demandeur, usine, secteur, ligne, zone, prio, rule_id)
                VALUES (?, ?, ?, ?, 'À faire', 'Préventif', 'GMAO', ?, ?, ?, ?, ?, ?)");
            $stmtIns->execute([
                $nouvel_id, $maintenant, $regle['equip'], "[PRÉVENTIF] " . $regle['descr'],
                $regle['usine'], $regle['secteur'], $regle['ligne'], $regle['zone'],
                $regle['prio'] ?: 'Normal', $regle['id']
            ]);

            if ($tech) {
                $db->prepare("INSERT INTO pointages (task_id, tech, date, hours) VALUES (?, ?, ?, 0)")
                   ->execute([$nouvel_id, $tech, $maintenant]);
            }

            // Même traçabilité que le cron, pour ne pas générer une 2e fois le même jour à 5h.
            $db->prepare("UPDATE preventif_regles SET last_gen = ?, last_gen_date = ? WHERE id = ?")
               ->execute([$maintenant, $aujourdhui, $regle['id']]);

            // Même recalcul d'échéance que cron_preventif.php (voir son commentaire) : sans ça une
            // génération manuelle laisse l'échéance figée dans le passé et la règle repasse "En retard"
            // au prochain chargement, malgré le cycle qui vient d'être relancé.
            if (($regle['mode_planif'] ?: 'frequence') !== 'jours_semaine' && $regle['freq'] !== 'test_1m') {
                $freqJoursMaj = (int)$regle['freq'];
                if ($freqJoursMaj > 0) {
                    $nouvelleEcheance = date('Y-m-d', strtotime($aujourdhui . " +{$freqJoursMaj} days"));
                    $db->prepare("UPDATE preventif_regles SET echeance = ? WHERE id = ?")
                       ->execute([$nouvelleEcheance, $regle['id']]);
                }
            }

            echo json_encode(["status" => "ok", "tech" => $tech, "auto_matin_sans_tech" => $autoMatinSansTech]);
            exit();
        } elseif (isset($data['action']) && $data['action'] === 'checklist_add') {
            // Sert à la fois pour l'ajout (pas d'id fourni, ou id inconnu) et la modification (id
            // d'un item existant, ex. clic sur une ligne de la checklist pour affecter un intervenant/
            // une date après coup) — d'où l'UPSERT plutôt qu'un simple INSERT.
            $saison = $data['saison'] ?: $saisonCourante;
            $maxOrdre = $db->prepare("SELECT COALESCE(MAX(ordre), -1) FROM preventif_checklist WHERE categorie = ? AND saison = ?");
            $maxOrdre->execute([$data['categorie'] ?? 'process', $saison]);
            $stmt = $db->prepare("INSERT INTO preventif_checklist
                (id, categorie, saison, usine, equip, descr, signale_par, intervenant, date_prevue, prio, ordre, is_sous_traitant, entreprise_ext_id)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)
                ON DUPLICATE KEY UPDATE
                 usine=VALUES(usine), equip=VALUES(equip), descr=VALUES(descr), signale_par=VALUES(signale_par),
                 intervenant=VALUES(intervenant), date_prevue=VALUES(date_prevue), prio=VALUES(prio),
                 is_sous_traitant=VALUES(is_sous_traitant), entreprise_ext_id=VALUES(entreprise_ext_id)");
            $estSousTraite = !empty($data['is_sous_traitant']);
            $stmt->execute([
                $data['id'] ?: ('CKL-' . round(microtime(true) * 1000)),
                $data['categorie'] ?? 'process', $saison,
                ($data['usine'] ?? '') !== '' ? $data['usine'] : null,
                $data['equip'] ?? '', $data['desc'] ?? '', $data['signale_par'] ?? '',
                ($data['intervenant'] ?? '') !== '' ? $data['intervenant'] : null,
                !empty($data['date_prevue']) ? $data['date_prevue'] : null,
                $data['prio'] ?? '',
                (int)$maxOrdre->fetchColumn() + 1,
                $estSousTraite ? 1 : 0,
                $estSousTraite && !empty($data['entreprise_ext_id']) ? $data['entreprise_ext_id'] : null
            ]);
        } elseif (isset($data['action']) && $data['action'] === 'checklist_zone_add') {
            $label = trim($data['label'] ?? '');
            if ($label !== '') {
                $maxOrdreZ = (int)$db->query("SELECT COALESCE(MAX(ordre), -1) FROM preventif_checklist_zones")->fetchColumn();
                try {
                    $db->prepare("INSERT INTO preventif_checklist_zones (label, ordre) VALUES (?, ?)")->execute([$label, $maxOrdreZ + 1]);
                } catch (Exception $e) { /* doublon (contrainte UNIQUE) : on ignore silencieusement */ }
            }
        } elseif (isset($data['action']) && $data['action'] === 'checklist_zone_rename') {
            $id = (int)($data['id'] ?? 0);
            $label = trim($data['label'] ?? '');
            if ($id && $label !== '') {
                $ancien = $db->prepare("SELECT label FROM preventif_checklist_zones WHERE id = ?");
                $ancien->execute([$id]);
                $ancienLabel = $ancien->fetchColumn();
                if ($ancienLabel !== false && $ancienLabel !== $label) {
                    $db->prepare("UPDATE preventif_checklist_zones SET label = ? WHERE id = ?")->execute([$label, $id]);
                    // Répercute le renommage sur les tâches déjà classées sous l'ancien nom, sinon elles
                    // se retrouvent avec une usine "orpheline" qui n'existe plus dans la liste gérée.
                    $db->prepare("UPDATE preventif_checklist SET usine = ? WHERE usine = ?")->execute([$label, $ancienLabel]);
                }
            }
        } elseif (isset($data['action']) && $data['action'] === 'checklist_zone_delete') {
            $id = (int)($data['id'] ?? 0);
            $lbl = $db->prepare("SELECT label FROM preventif_checklist_zones WHERE id = ?");
            $lbl->execute([$id]);
            $label = $lbl->fetchColumn();
            if ($label !== false) {
                $enUsage = $db->prepare("SELECT COUNT(*) FROM preventif_checklist WHERE usine = ?");
                $enUsage->execute([$label]);
                if ((int)$enUsage->fetchColumn() === 0) {
                    $db->prepare("DELETE FROM preventif_checklist_zones WHERE id = ?")->execute([$id]);
                } else {
                    echo json_encode(["status" => "error", "message" => t('preventifliste.err_zone_utilisee')]);
                    exit();
                }
            }
        } elseif (isset($data['action']) && $data['action'] === 'checklist_set_statut') {
            // Remplace l'ancien checklist_toggle (fait binaire) par un statut à 3 états. fait/fait_par/
            // date_fait restent alimentés en synchro (Terminé <=> fait=1) pour l'affichage "fait le X
            // par Y" et pour ne pas casser les anciens exports qui liraient encore la colonne fait.
            $statut = in_array($data['statut'] ?? '', ['a_faire', 'en_cours', 'termine'], true) ? $data['statut'] : 'a_faire';
            $estTermine = ($statut === 'termine');
            $stmt = $db->prepare("UPDATE preventif_checklist SET statut = ?, fait = ?, fait_par = ?, date_fait = ? WHERE id = ?");
            $stmt->execute([$statut, $estTermine ? 1 : 0, $estTermine ? ($data['fait_par'] ?? '') : null, $estTermine ? date('Y-m-d H:i:s') : null, $data['id']]);
        } elseif (isset($data['action']) && $data['action'] === 'checklist_delete') {
            $stmt = $db->prepare("DELETE FROM preventif_checklist WHERE id = ?");
            $stmt->execute([$data['id']]);
        } elseif (isset($data['action']) && $data['action'] === 'checklist_dupliquer') {
            // Reporte uniquement les tâches cochées par l'utilisateur (case de gauche du tableau,
            // détournée de son ancien rôle "fait" — voir checklist_set_statut) — pas toute la saison :
            // sert typiquement en fin de saison pour reporter ce qui n'a pas pu être fait à temps.
            $categorie = $data['categorie'] ?? 'process';
            $saisonCible = trim($data['saison_cible'] ?? '');
            $ids = is_array($data['ids'] ?? null) ? array_values(array_filter($data['ids'])) : [];
            if ($saisonCible !== '' && count($ids) > 0) {
                // La date prévue ne repart PAS d'une saison à l'autre (celle de l'an dernier n'a plus de
                // sens), le statut repart à "à faire" ; intervenant habituel et priorité sont conservés.
                $placeholders = implode(',', array_fill(0, count($ids), '?'));
                $stmtSrc = $db->prepare("SELECT usine, equip, descr, signale_par, intervenant, prio, ordre FROM preventif_checklist WHERE id IN ($placeholders) ORDER BY ordre ASC");
                $stmtSrc->execute($ids);
                $stmtIns = $db->prepare("INSERT INTO preventif_checklist (id, categorie, saison, usine, equip, descr, signale_par, intervenant, prio, statut, ordre) VALUES (?,?,?,?,?,?,?,?,?,'a_faire',?)");
                $i = 0;
                foreach ($stmtSrc->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    $stmtIns->execute([
                        'CKL-' . round(microtime(true) * 1000) . '-' . $i,
                        $categorie, $saisonCible, $row['usine'], $row['equip'], $row['descr'], $row['signale_par'],
                        $row['intervenant'], $row['prio'], $row['ordre']
                    ]);
                    $i++;
                }
            }
        } else {
            $sql = "INSERT INTO preventif_regles
                (id, equip, descr, hours, type_op, prio, mode_planif, freq, jours, echeance,
                 alerte_val, alerte_unit, usine, secteur, ligne, zone, impact, arret_h,
                 intervenant, declarant, cause, pieces, commentaires, status, categorie, auto_matin, last_gen, last_gen_date)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
                ON DUPLICATE KEY UPDATE
                 equip=VALUES(equip), descr=VALUES(descr), hours=VALUES(hours), type_op=VALUES(type_op),
                 prio=VALUES(prio), mode_planif=VALUES(mode_planif), freq=VALUES(freq), jours=VALUES(jours),
                 echeance=VALUES(echeance), alerte_val=VALUES(alerte_val), alerte_unit=VALUES(alerte_unit),
                 usine=VALUES(usine), secteur=VALUES(secteur), ligne=VALUES(ligne), zone=VALUES(zone),
                 impact=VALUES(impact), arret_h=VALUES(arret_h), intervenant=VALUES(intervenant),
                 declarant=VALUES(declarant), cause=VALUES(cause), pieces=VALUES(pieces),
                 commentaires=VALUES(commentaires), status=VALUES(status), categorie=VALUES(categorie),
                 auto_matin=VALUES(auto_matin)";
                 // NB : last_gen / last_gen_date ne sont volontairement jamais écrasés depuis le client,
                 // seul cron_preventif.php (et l'action generer_demande) sont autorisés à faire avancer
                 // ces valeurs.
            $stmt = $db->prepare($sql);
            $stmt->execute([
                $data['id'] ?: ('PLAN-' . round(microtime(true) * 1000)),
                $data['equip'] ?? '', $data['desc'] ?? '', $data['hours'] ?? '', $data['type_op'] ?? '',
                $data['prio'] ?? 'Normal', $data['mode_planif'] ?? 'frequence', $data['freq'] ?? '',
                $data['jours'] ?? '', !empty($data['echeance']) ? $data['echeance'] : null,
                $data['alerte_val'] ?? '', $data['alerte_unit'] ?? 'jours', $data['usine'] ?? '',
                $data['secteur'] ?? '', $data['ligne'] ?? '', $data['zone'] ?? '', $data['impact'] ?? '',
                $data['arret_h'] ?? '', $data['intervenant'] ?? '', $data['declarant'] ?? '',
                $data['cause'] ?? '', $data['pieces'] ?? '', $data['commentaires'] ?? '',
                $data['status'] ?? 'actif', $data['categorie'] ?? 'process',
                !empty($data['auto_matin']) ? 1 : 0, null, null
            ]);
        }
        echo json_encode(["status" => "ok"]);
        exit();
    }
}
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(langue_actuelle()); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title><?php echo $cat ? htmlspecialchars($CATEGORIES_META[$cat]['label']) : htmlspecialchars(t('preventifliste.plan_preventif')); ?> - GMAO</title>
    <link rel="icon" type="image/png" href="img/logo.png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Caveat:wght@600&family=Segoe+UI:wght@300;400;600;800&display=swap" rel="stylesheet">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
    <style>
        :root {
            --primary: #2c3e50; --accent: #3498db; --success: #2ecc71;
            --danger: #e74c3c; --gelpam-green: #2ecc71; --gelpam-orange: #f39c12;
            --ardo-blue: #005696;
            --soft-blue: #ebf5fb; --soft-green: #e8f8f5;
        }

        html, body { height: 100%; }
        body {
    margin: 0;
    box-sizing: border-box;
    font-family: 'Segoe UI', sans-serif;
    background: linear-gradient(rgba(0, 0, 0, 0.2), rgba(0, 0, 0, 0.2)), url('img/fond.jpg') no-repeat center center fixed;
    background-color: #1a2733;
    background-size: cover;
    height: 100vh;
    overflow: hidden;
    padding-top: 84px;
}
        /* La page est normalement bornée à la fenêtre (overflow:hidden ci-dessus) — la vue Travaux a
           besoin de dépasser et de faire défiler la page réelle (voir switchTab). Activé/désactivé en
           JS uniquement pendant que cet onglet est affiché, pour ne rien changer ailleurs. */
        body.ckl-scroll-page { height: auto; min-height: 100vh; overflow-y: auto; }
        body.ckl-scroll-page .container { height: auto; min-height: calc(100vh - 84px); }

        @keyframes pulse-dot { 0% { transform: scale(1); opacity: 0.6; } 100% { transform: scale(2.5); opacity: 0; } }
        /* Même principe de pulsation que .status-pulse (ronds de connexion), transposé en anneau
           lumineux pour un bouton entier plutôt qu'un petit rond. */
        @keyframes pulse-ring-btn { 0% { box-shadow: 0 0 0 0 rgba(243,156,18,0.65); } 70% { box-shadow: 0 0 0 10px rgba(243,156,18,0); } 100% { box-shadow: 0 0 0 0 rgba(243,156,18,0); } }
        .btn-pulse { animation: pulse-ring-btn 1.8s infinite; }

        /* --- HEADER & NAV --- */
        header { position: fixed; top: 0; width: 100%; z-index: 1000; overflow: hidden; box-shadow: 0 4px 20px rgba(0,0,0,0.25); }
        header::before { content: ""; position: absolute; top: -12px; left: -12px; right: -12px; bottom: -12px; background: linear-gradient(rgba(0, 0, 0, 0.2), rgba(0, 0, 0, 0.2)), url('img/fond.jpg') no-repeat center center fixed; background-size: cover; filter: blur(4px); z-index: -1; }
        .header-top { display: flex; justify-content: space-between; align-items: center; padding: 8px 20px; }
        .btn-accueil { font-size: 18px; color: white; text-decoration: none; display: flex; align-items: center; justify-content: center; width: 38px; height: 38px; border-radius: 50%; background: rgba(255,255,255,0.12); backdrop-filter: blur(14px) brightness(1.15); -webkit-backdrop-filter: blur(14px) brightness(1.15); border: 1px solid rgba(255,255,255,0.4); box-shadow: 0 6px 16px rgba(0,0,0,0.2); transition: transform 0.2s, background 0.2s; }
        .btn-accueil:hover { background: rgba(255,255,255,0.28); transform: translateY(-2px); }
        .btn-accueil-green:hover { background: rgba(46, 204, 113, 0.3); border-color: rgba(46, 204, 113, 0.6); }
        .crumb-bar { display: flex; align-items: center; gap: 8px; font-size: 0.94rem; font-weight: 600; color: rgba(255,255,255,0.9); text-shadow: 0 1px 3px rgba(0,0,0,0.4); max-width: 99%; margin: 0 auto; padding: 0 10px 10px; box-sizing: border-box; }
        .crumb-bar .crumb-home { color: inherit; text-decoration: none; display: inline-flex; align-items: center; gap: 5px; }
        .crumb-bar .crumb-home:hover { text-decoration: underline; }
        .crumb-bar .crumb-parent { color: inherit; text-decoration: none; }
        .crumb-bar .crumb-parent:hover { text-decoration: underline; }
        .crumb-sep { color: rgba(255,255,255,0.45); font-weight: 400; }
        .crumb-current { color: rgba(255,255,255,0.75); font-weight: 400; }
        .nav-tabs { display: flex; background: #fff; padding: 0 10px; gap: 2px; }
        .tab-item { padding: 10px 18px; text-decoration: none; color: #7f8c8d; font-weight: 600; font-size: 0.8rem; border-bottom: 3px solid transparent; transition: 0.3s; display: flex; align-items: center; gap: 8px; }
        .tab-item:hover { background: rgba(0,0,0,0.02); }
        .tab-item.active { color: var(--accent); border-bottom: 3px solid var(--accent); background: rgba(52, 152, 219, 0.05); }

        .user-badge { background: rgba(255,255,255,0.12); backdrop-filter: blur(14px) brightness(1.15); -webkit-backdrop-filter: blur(14px) brightness(1.15); padding: 5px 12px; border-radius: 20px; font-size: 0.75rem; font-weight: 600; display: flex; align-items: center; gap: 8px; color: white; border: 1px solid rgba(255,255,255,0.4); text-shadow: 0 1px 3px rgba(0,0,0,0.4); box-shadow: 0 6px 16px rgba(0,0,0,0.2); }
        .status-pulse { width: 8px; height: 8px; border-radius: 50%; position: relative; background: var(--success); }
        .status-pulse::after { content: ""; position: absolute; width: 100%; height: 100%; border-radius: 50%; background: inherit; animation: pulse-dot 2s infinite; opacity: 0.6; }

        /* --- SIDEBAR --- */
        .sidebar { height: 100%; width: 0; position: fixed; z-index: 3000; top: 0; left: 0; background-color: #1a252f; overflow-x: hidden; overflow-y: auto; transition: 0.4s; padding-top: 60px; padding-bottom: 20px; }
        .sidebar a { padding: 12px 25px; text-decoration: none; font-size: 1.05rem; color: #ecf0f1; display: block; transition: 0.3s; border-left: 4px solid transparent; }
        .sidebar a:hover { background: #2c3e50; border-left: 4px solid var(--accent); }
        .sidebar a.active-side { background: #2c3e50; border-left: 4px solid var(--accent); color: var(--accent); font-weight: bold; }
        .sidebar .closebtn { position: absolute; top: 10px; right: 25px; font-size: 36px; color: white; border: none; background: none; cursor: pointer; }
        .openbtn { font-size: 22px; cursor: pointer; background: none; border: none; color: var(--primary); padding: 5px 10px; }

        /* --- CONTENU --- */
        /* Mise en page fixe : seul le tableau de règles défile, les KPI et l'en-tête restent visibles */
        .container { max-width: 1300px; margin: 0 auto; height: 100%; padding: 0 15px 15px; box-sizing: border-box; display: flex; flex-direction: column; min-height: 0; }
        .kpi-row { flex-shrink: 0; }
        .card { background: rgba(255, 255, 255, 0.95); padding: 16px 20px; border-radius: 12px; box-shadow: 0 5px 15px rgba(0,0,0,0.1); border-left: 5px solid var(--accent); flex: 1 1 auto; min-height: 0; display: flex; flex-direction: column; overflow: hidden; }
        .page-header { flex-shrink: 0; display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px; border-bottom: 2px solid #eee; padding-bottom: 10px; flex-wrap: wrap; gap: 10px; }
        .page-title { font-family: 'Caveat', cursive; font-size: 1.7rem; color: var(--primary); margin: 0; display: flex; align-items: center; gap: 10px; }
        .page-subtitle { font-size: 0.76rem; color: #64748b; margin: 2px 0 0 0; font-weight: 400; }

        .header-actions { display: flex; gap: 10px; }

        .btn-add { background: var(--accent); color: white; border: none; padding: 11px 22px; border-radius: 9px; font-weight: 700; cursor: pointer; transition: 0.25s; font-size: 0.85rem; letter-spacing: 0.2px; display: flex; align-items: center; gap: 9px; box-shadow: 0 4px 14px -4px rgba(52,152,219,0.5); }
        .btn-add i { font-size: 0.8rem; }
        .btn-add:hover { background: #2980b9; transform: translateY(-2px); box-shadow: 0 8px 20px -6px rgba(52,152,219,0.6); }

        .btn-print { background: #fff; color: var(--primary); border: 1px solid #cbd5e1; padding: 10px 18px; border-radius: 8px; font-weight: 700; cursor: pointer; transition: 0.3s; font-size: 0.9rem; display: flex; align-items: center; gap: 8px; }
        .btn-print:hover { background: #f1f5f9; border-color: var(--accent); color: var(--accent); }

        /* --- KPI DASHBOARD --- */
        .kpi-row { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 8px; margin-bottom: 10px; }
        /* 4 colonnes figées, aucune adaptation mobile jusqu'ici : sur téléphone chaque carte n'avait que
           ~90px de large, chiffre et libellé se chevauchaient sur l'icône. */
        @media screen and (max-width: 600px) { .kpi-row { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
        .kpi-card { background: rgba(255,255,255,0.95); border-radius: 10px; padding: 10px 14px; box-shadow: 0 5px 15px rgba(0,0,0,0.08); display: flex; align-items: center; gap: 10px; border-top: 3px solid var(--accent); transition: transform 0.2s; }
        .kpi-card:hover { transform: translateY(-3px); }
        .kpi-filterable { cursor: pointer; }
        .kpi-filterable.active { box-shadow: 0 0 0 2px var(--primary), 0 5px 15px rgba(0,0,0,0.08); }
        .kpi-card.k-danger { border-top-color: var(--danger); }
        .kpi-card.k-warning { border-top-color: var(--gelpam-orange); }
        .kpi-card.k-success { border-top-color: var(--success); }
        .kpi-icon { width: 34px; height: 34px; min-width: 34px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 0.95rem; background: rgba(52,152,219,0.1); color: var(--accent); }
        .kpi-card.k-danger .kpi-icon { background: rgba(231,76,60,0.1); color: var(--danger); }
        .kpi-card.k-warning .kpi-icon { background: rgba(243,156,18,0.12); color: var(--gelpam-orange); }
        .kpi-card.k-success .kpi-icon { background: rgba(46,204,113,0.12); color: var(--success); }
        .kpi-value { font-size: 1.25rem; font-weight: 800; color: var(--primary); line-height: 1.1; }
        .kpi-label { font-size: 0.62rem; color: #64748b; text-transform: uppercase; letter-spacing: 0.5px; font-weight: 700; margin-top: 2px; }

        /* --- FILTRES --- */
        .toolbar { flex-shrink: 0; display: flex; flex-wrap: wrap; gap: 10px; align-items: center; margin-bottom: 8px; }
        .search-bar { flex: 1; min-width: 220px; padding: 10px 15px; border: 1px solid #ddd; border-radius: 8px; font-size: 0.95rem; outline: none; transition: 0.3s; box-sizing: border-box; background: #f8f9fa url('data:image/svg+xml;utf8,<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="%23999" class="bi bi-search" viewBox="0 0 16 16"><path d="M11.742 10.344a6.5 6.5 0 1 0-1.397 1.398h-.001c.03.04.062.078.098.115l3.85 3.85a1 1 0 0 0 1.415-1.414l-3.85-3.85a1.007 1.007 0 0 0-.115-.1zM12 6.5a5.5 5.5 0 1 1-11 0 5.5 5.5 0 0 1 11 0z"/></svg>') no-repeat 15px center; padding-left: 40px; }
        .search-bar:focus { border-color: var(--accent); box-shadow: 0 0 0 3px rgba(52, 152, 219, 0.2); background-color: #fff; }
        /* Pour les <select> de filtre : mêmes proportions que .search-bar mais sans l'icône loupe
           (pensée pour un champ de saisie libre, elle se retrouvait collée sur le texte choisi). */
        .filter-select { flex: 0 0 auto; min-width: 160px; padding: 10px 15px; border: 1px solid #ddd; border-radius: 8px; font-size: 0.88rem; outline: none; background: #f8f9fa; box-sizing: border-box; }
        .filter-select:focus { border-color: var(--accent); box-shadow: 0 0 0 3px rgba(52, 152, 219, 0.2); background-color: #fff; }

        /* #vue-regles / #vue-checklist doivent transmettre le flex-chain jusqu'à .card/.table-scroll
           (voir .container/.card plus haut) — sans ça .card ne reçoit plus la hauteur bornée dont il
           a besoin pour que .table-scroll défile, et la table déborde silencieusement (visible
           seulement si on a plus de lignes que la fenêtre n'en affiche). */
        #vue-regles, #vue-checklist { display: flex; flex-direction: column; flex: 1 1 auto; min-height: 0; }
        /* Contrairement à la vue Règles (bornée à la fenêtre, défilement interne au tableau), la vue
           Travaux peut dépasser la fenêtre et fait défiler la PAGE entière — plus de place réelle
           pour lire les tâches, quitte à scroller un peu plus loin pour les voir toutes. */
        #vue-checklist { flex: 0 0 auto; min-height: auto; }
        #vue-checklist .card { flex: 0 0 auto; overflow: visible; }
        /* overflow-x:auto en filet de sécurité : si les colonnes redimensionnées à la main dépassent
           la largeur de la carte, le tableau défile horizontalement au lieu de déborder par-dessus
           le reste de la page. */
        #vue-checklist .table-scroll { flex: 0 0 auto; overflow-y: visible; overflow-x: auto; }
        /* position:relative (pas static) : le tableau ne défile plus en interne (voir table-scroll
           ci-dessus) donc pas besoin de sticky, mais .ckl-col-resizer a besoin d'un ancrage pour se
           positionner sur le bord droit de sa colonne. */
        #vue-checklist th { position: relative; }
        /* box-sizing:border-box impératif ici : sans lui, le padding de th/td (10px 15px, voir plus
           haut) s'ajoute PAR-DESSUS chaque largeur en %, et la somme dépasse largement les 100% visés
           — c'est ce qui écrasait la colonne Tâche à quelques dizaines de pixels et poussait Actions
           hors du cadre. */
        #checklistTable { table-layout: fixed; box-sizing: border-box; }
        #checklistTable th, #checklistTable td { box-sizing: border-box; }
        .ckl-col-resizer { position: absolute; top: 0; right: -3px; width: 6px; height: 100%; cursor: col-resize; z-index: 2; user-select: none; }
        .ckl-col-resizer:hover, .ckl-col-resizer.is-resizing { background: rgba(52,152,219,0.45); }
        .preventif-tabs { display: flex; gap: 6px; margin-bottom: 16px; flex-shrink: 0; }
        .preventif-tab { border: 1px solid #cbd5e1; background: rgba(255,255,255,0.7); color: #475569; padding: 10px 18px; border-radius: 10px 10px 0 0; font-size: 0.85rem; font-weight: 700; cursor: pointer; transition: 0.2s; display: flex; align-items: center; gap: 8px; border-bottom: none; }
        .preventif-tab:hover { background: #fff; }
        .preventif-tab.active { background: rgba(255, 255, 255, 0.95); color: var(--primary); box-shadow: 0 -2px 8px rgba(0,0,0,0.06); }
        .preventif-tab .chip-count { background: #eef1f5; color: #475569; padding: 1px 7px; border-radius: 10px; font-size: 0.7rem; }
        .preventif-tab.active .chip-count { background: var(--accent); color: #fff; }
        .checklist-check { width: 16px; height: 16px; cursor: pointer; accent-color: var(--gelpam-green); }
        .btn-icon-del { background: none; border: 1px solid #fecaca; color: var(--danger); width: 30px; height: 30px; border-radius: 7px; cursor: pointer; }
        .btn-icon-del:hover { background: #fef2f2; }
        .checklist-row.is-fait .ckl-desc-text { text-decoration: line-through; color: var(--gelpam-green); }
        .ckl-th-tri { cursor: pointer; user-select: none; }
        .ckl-th-tri:hover { color: var(--accent); }
        .ckl-th-tri i { font-size: 0.7rem; margin-left: 4px; opacity: 0.5; }
        /* Mêmes badges de statut que la page Saisie & Historique (maintenance.php) : là-bas, .status-btn
           n'a en fait aucun style de taille dédié (juste le dégradé de couleur .st-xxx par-dessus un
           bouton par défaut du navigateur) — on reproduit donc ce même gabarit compact plutôt qu'un
           badge arrondi décoratif. Cliquer dessus fait avancer le statut. */
        /* Liste déroulante plutôt qu'un badge à un seul clic (qui faisait "disparaître" la tâche en la
           renvoyant tout en bas) : même code couleur que les statuts de Saisie & Historique, mais on
           choisit l'état voulu sans que la ligne bouge dans le tableau. */
        .ckl-status-select { padding: 3px 6px; border-radius: 4px; font-size: 0.68rem; font-weight: 700; color: #fff; border: none; cursor: pointer; white-space: nowrap; font-family: inherit; }
        .ckl-status-select option { color: #334155; background: #fff; font-weight: 600; }
        .ckl-st-a_faire { background: linear-gradient(135deg, #f39c12, #d35400); }
        .ckl-st-en_cours { background: linear-gradient(135deg, #3498db, #2980b9); }
        .ckl-st-termine { background: linear-gradient(135deg, #2ecc71, #27ae60); }
        /* Description sur une seule ligne (tronquée) : c'est elle qui doit primer sur la largeur des
           lignes, pas les colonnes annexes — voir répartition des largeurs du tableau checklist. */
        .ckl-desc-text { white-space: nowrap; overflow: hidden; text-overflow: ellipsis; display: block; max-width: 100%; cursor: help; }
        #checklistBody td.ckl-td-tronque { white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 0; cursor: help; }
        .filter-chips { display: flex; gap: 8px; flex-wrap: wrap; }
        .chip { border: 1px solid #cbd5e1; background: #fff; color: #475569; padding: 8px 14px; border-radius: 20px; font-size: 0.78rem; font-weight: 700; cursor: pointer; transition: 0.2s; display: flex; align-items: center; gap: 6px; white-space: nowrap; }
        .chip:hover { border-color: var(--accent); color: var(--accent); }
        .chip.active { background: var(--primary); border-color: var(--primary); color: #fff; }
        .chip .chip-count { background: rgba(0,0,0,0.12); padding: 1px 7px; border-radius: 10px; font-size: 0.7rem; }
        .chip.active .chip-count { background: rgba(255,255,255,0.25); }

        /* --- TABLEAU --- */
        .table-scroll { flex: 1 1 auto; min-height: 0; overflow-y: auto; }
        table { width: 100%; border-collapse: collapse; table-layout: fixed; }
        th { position: sticky; top: 0; z-index: 1; background: #f1f5f9; color: #64748b; padding: 10px 15px; text-align: left; font-size: 0.75rem; text-transform: uppercase; letter-spacing: 1px; border-bottom: 2px solid #e2e8f0; cursor: default; }
        td { padding: 10px 15px; border-bottom: 1px solid #f1f5f9; font-size: 0.9rem; color: #334155; vertical-align: middle; }
        tr:hover { background: #f8fafc; }

        .empty-state { text-align: center; padding: 50px 20px; color: #94a3b8; }
        .empty-state i { font-size: 2.5rem; margin-bottom: 10px; opacity: 0.5; }

        .badge-f { padding: 4px 8px; border-radius: 20px; font-size: 0.7rem; font-weight: 700; display: inline-flex; align-items: center; gap: 5px; }
        .f-7 { background: #dcfce7; color: #166534; }
        .f-15 { background: #f3e8ff; color: #6b21a8; }
        .f-30 { background: #e0f2fe; color: #0369a1; }
        .f-90 { background: #fef3c7; color: #92400e; }
        .f-365 { background: #fee2e2; color: #991b1b; }

        .badge-bi { padding: 4px 9px; border-radius: 20px; font-size: 0.7rem; font-weight: 700; display: inline-flex; align-items: center; gap: 5px; background: #dbeafe; color: #1d4ed8; cursor: pointer; white-space: nowrap; }
        .badge-bi:hover { background: #bfdbfe; }
        .badge-bi-pending { background: #fef3c7; color: #92400e; }
        .badge-bi-pending:hover { background: #fde68a; }

        /* --- BADGES INLINE (PRIORITÉ / IMPACT / INTERVENANT) --- */
        .badge-urgent { padding: 2px 8px; border-radius: 20px; font-size: 0.65rem; font-weight: 800; display: inline-flex; align-items: center; gap: 4px; background: #fee2e2; color: #991b1b; text-transform: uppercase; letter-spacing: 0.3px; }
        .badge-impact { padding: 2px 8px; border-radius: 20px; font-size: 0.65rem; font-weight: 700; display: inline-flex; align-items: center; gap: 4px; background: #fef3c7; color: #92400e; }
        .row-badges { display: flex; flex-wrap: wrap; gap: 5px; margin-top: 5px; }
        .cell-intervenant { display: flex; align-items: center; gap: 7px; font-size: 0.82rem; color: #334155; }
        .cell-intervenant .iv-avatar { width: 24px; height: 24px; min-width: 24px; border-radius: 50%; background: #eef2f7; color: var(--accent); display: flex; align-items: center; justify-content: center; font-size: 0.65rem; font-weight: 800; }
        .cell-intervenant.iv-empty { color: #cbd5e1; font-style: italic; }
        .equip-title {
            display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical;
            overflow: hidden; cursor: default;
        }
        .loc-path {
            font-size: 0.7rem; color: #94a3b8; font-weight: normal; margin-top: 2px;
            white-space: nowrap; overflow: hidden; text-overflow: ellipsis; cursor: default;
        }

        /* --- BADGES DE CONFORMITÉ (ÉCHÉANCE) --- */
        .status-badge { padding: 5px 10px; border-radius: 20px; font-size: 0.72rem; font-weight: 700; display: inline-flex; align-items: center; gap: 6px; white-space: nowrap; }
        .status-ok { background: #dcfce7; color: #166534; }
        .status-soon { background: #fef3c7; color: #92400e; }
        .status-overdue { background: #fee2e2; color: #991b1b; }
        .status-paused { background: #e2e8f0; color: #64748b; }
        .status-unknown { background: #f1f5f9; color: #94a3b8; }
        .status-dot { width: 7px; height: 7px; border-radius: 50%; position: relative; background: currentColor; flex: none; }
        .status-overdue .status-dot::after { content: ""; position: absolute; width: 100%; height: 100%; border-radius: 50%; background: currentColor; animation: pulse-dot 1.4s infinite; }
        .echeance-date { font-size: 0.7rem; color: #94a3b8; margin-top: 3px; }

        /* --- VUE "CARTES" TABLETTE/TÉLÉPHONE (règles automatiques + Travaux Hiver) : sous 1024px,
           le tableau à 8-9 colonnes devient illisible (colonnes réduites à 1-2 lettres). On bascule
           alors sur une carte empilée par ligne, réutilisant les mêmes classes de badges que le
           tableau (.status-badge, .badge-f, .badge-bi, .cell-intervenant...) pour rester cohérent
           visuellement. Les deux vues sont toujours générées ensemble par renderTable() /
           renderChecklistTable() ; seul le CSS decide laquelle s'affiche. */
        .prev-cards-grid { display: none; }
        @media screen and (max-width: 1024px) {
            .table-scroll { display: none; }
            .prev-cards-grid { display: flex; flex-direction: column; gap: 10px; }
            /* La mise en page desktop borne toute la page à la fenêtre (body en overflow:hidden,
               voir plus haut) et ne laisse défiler QUE .table-scroll en interne — un choix fait pour
               garder les KPI/en-tête visibles pendant qu'on parcourt le tableau. Cacher .table-scroll
               ci-dessus supprime donc la seule zone qui défilait, sans rien pour la remplacer : la
               page semblait "bloquée" (on y accède, mais impossible de descendre). On repasse ici sur
               un défilement de la page entière, comme le fait déjà .ckl-scroll-page (activé en JS
               uniquement pour l'onglet Travaux Hiver) — mais pour les deux onglets, en permanence,
               dès qu'on est en dessous de 1024px. */
            body { height: auto !important; min-height: 100vh; overflow-y: auto !important; }
            .container { height: auto !important; min-height: calc(100vh - 84px); }
            .card, #vue-regles, #vue-checklist { flex: 0 0 auto !important; min-height: auto !important; overflow: visible !important; }

            /* Fenêtres modales (édition de tâche, nouvelle règle, détail, historique...) : toutes
               utilisaient des grilles à 2-5 colonnes fixes et, pour l'assistant de création, une
               sidebar de 210px à côté du contenu — illisible/inutilisable sur un écran de téléphone.
               On empile tout en 1 colonne et on réduit les marges internes. */
            .form-grid-2, .form-grid-3, .form-grid-4, .form-grid-5,
            .history-row, .ckl-modal-grid {
                grid-template-columns: 1fr !important;
            }
            /* Les tuiles d'info de la fenêtre de détail (Rythme, Échéance, Alerte...) et les cartes de
               sélection visuelle (Catégorie de préventif, Fréquence du cycle...) restent en petites
               cases côte à côte (2 par ligne) plutôt que de s'empiler une par une en pleine largeur —
               demande explicite de David, qui les trouvait trop grandes/larges en 1 colonne. Seuls les
               vrais champs de formulaire (form-grid-*, saisie/sélection classique) restent empilés :
               eux ont besoin de toute la largeur pour rester saisissables au doigt. */
            .info-tile-grid, .choice-grid.cols-3, .choice-grid.cols-4 { grid-template-columns: repeat(2, minmax(0, 1fr)) !important; }
            .wizard-shell { flex-direction: column; }
            .wizard-sidebar { width: 100%; flex-direction: row; flex-wrap: wrap; gap: 4px 12px; padding: 8px; }
            .wizard-sidebar-connector { display: none; }
            .modal-content-wizard, .modal-content-detail, .modal-content-large { padding: 14px !important; }
            #modalChecklistItem > .modal-content { max-width: 94% !important; padding: 16px !important; }
            /* 3 boutons ("Fermer" / "Générer une demande" / "Modifier cette règle") côte à côte se
               compressaient au point de faire retomber leur texte sur plusieurs lignes. Empilés, chacun
               pleine largeur, plus lisible et plus facile à toucher au doigt. */
            .btn-row-detail { flex-direction: column; }
            .btn-row-detail .btn-action-modal { width: 100% !important; justify-content: center; box-sizing: border-box; }

            /* Débordement horizontal de toute la page, forçant à scroller latéralement : les 3
               boutons "Historique / Registre imprimable / Nouvelle règle" ne retombaient pas à la
               ligne, et le texte de localisation dans les cartes ne se tronquait pas vraiment — un
               classique de flexbox : un enfant flex garde par défaut min-width:auto (sa largeur de
               contenu "naturelle"), donc son white-space:nowrap/ellipsis n'a aucun effet tant qu'on ne
               force pas explicitement min-width:0 pour l'autoriser à rétrécir sous cette largeur. */
            .header-actions { flex-wrap: wrap; }
            .prc-head > div:first-child { min-width: 0; flex: 1 1 auto; }
        }
        .prev-rule-card { position: relative; background: #fff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 14px 16px; cursor: pointer; }
        .prev-rule-card .prc-head { display: flex; align-items: flex-start; justify-content: space-between; gap: 10px; margin-bottom: 6px; }
        .prev-rule-card .prc-desc { font-size: 0.85rem; color: #475569; font-weight: 600; margin: 8px 0; }
        .prev-rule-card .prc-row { display: flex; flex-wrap: wrap; align-items: center; gap: 10px; margin-bottom: 8px; }
        .prev-rule-card .prc-bottom { display: flex; align-items: center; justify-content: space-between; gap: 10px; padding-top: 10px; border-top: 1px dashed #eef2f5; }
        .prev-rule-card .prc-actions { display: flex; align-items: center; gap: 8px; }

        .prev-ckl-card { background: #fff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 14px 16px; }
        .prev-ckl-card.is-fait { opacity: 0.65; }
        .prev-ckl-card .pcc-top { display: flex; align-items: flex-start; gap: 10px; margin-bottom: 10px; }
        .prev-ckl-card .pcc-top input[type="checkbox"] { margin-top: 3px; flex: none; width: 17px; height: 17px; }
        .prev-ckl-card .pcc-title { flex: 1; font-size: 0.85rem; font-weight: 600; color: #334155; min-width: 0; }
        .prev-ckl-card .pcc-sub { font-size: 0.68rem; color: #94a3b8; margin-top: 2px; font-weight: normal; }
        .prev-ckl-card .pcc-meta { display: flex; flex-wrap: wrap; gap: 6px 14px; font-size: 0.78rem; color: #64748b; margin-bottom: 10px; }
        .prev-ckl-card .pcc-meta b { color: #334155; font-weight: 700; }
        .prev-ckl-card .pcc-bottom { display: flex; align-items: center; justify-content: space-between; gap: 10px; padding-top: 10px; border-top: 1px dashed #eef2f5; }

        /* --- LA SUPER MODALE OPTIMISÉE (SANS SCROLL) --- */
        .modal { display: none; position: fixed; z-index: 4000; left: 0; top: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.6); backdrop-filter: blur(4px); align-items: center; justify-content: center; }
        /* Requis par composant_rapport.php (#modalDetailBI / #modalChatTicket) */
        .modal-content { background:white; margin:2% auto; padding:20px; border-radius:15px; width:95%; max-height:90vh; overflow-y:auto; box-shadow: 0 10px 30px rgba(0,0,0,0.3); }

        .modal-content-large {
            background: #f8fafc;
            width: 1200px; /* Plus large pour tasser le contenu */
            max-width: 98%;
            padding: 15px 25px; /* Marges réduites */
            border-radius: 12px;
            position: relative;
            box-shadow: 0 20px 50px rgba(0,0,0,0.3);
            border-top: 5px solid var(--accent);
            max-height: 98vh; /* Prend presque tout l'écran */
            overflow-y: auto;
        }

        .form-section-title {
            font-size: 0.8rem;
            color: var(--primary);
            border-bottom: 2px solid #e2e8f0;
            padding-bottom: 4px;
            margin: 12px 0 8px 0; /* Marges réduites */
            text-transform: uppercase;
            font-weight: 800;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        /* Grilles ajustées pour maximiser la largeur */
        .form-grid-3 { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 10px; }
        .form-grid-4 { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 10px; }
        .form-grid-5 { display: grid; grid-template-columns: repeat(5, minmax(0, 1fr)); gap: 10px; }

        .form-group { display: flex; flex-direction: column; gap: 3px; }
        .form-group label { font-weight: 700; font-size: 0.7rem; color: #64748b; }
        .form-hint { font-size: 0.68rem; color: #94a3b8; font-weight: 400; margin-top: 2px; }

        /* Tassage des champs de saisie */
        .form-group input, .form-group select, .form-group textarea {
            padding: 4px 8px;
            height: 32px; /* Plus fin */
            border: 1px solid #cbd5e1;
            border-radius: 6px;
            font-size: 0.85rem;
            font-family: inherit;
            transition: 0.2s;
            outline: none;
            background: #fff;
            box-sizing: border-box;
            width: 100%;
        }
        .form-group input:focus, .form-group select:focus, .form-group textarea:focus { border-color: var(--accent); box-shadow: 0 0 0 3px rgba(52, 152, 219, 0.1); }
        .form-group select { cursor: pointer; }

        /* Textareas ajustés */
        .form-group textarea { resize: vertical; min-height: 45px; height: 45px; padding: 6px 8px; }

        .btn-action-modal { border: none; font-weight: bold; border-radius: 6px; cursor: pointer; transition: 0.2s; font-size: 0.95rem; }
        .btn-save { background: var(--success); color: white; }
        .btn-save:hover { background: #27ae60; }
        .btn-cancel { background: #e2e8f0; color: #475569; }
        .btn-cancel:hover { background: #cbd5e1; color: #334155; }

        /* --- WIZARD DE CRÉATION (MODALE) --- */
        .modal-content-wizard { width: 980px; max-width: 96%; padding: 14px 28px 8px 28px; }

        .wizard-shell { display: flex; gap: 20px; margin-top: 8px; align-items: stretch; }

        /* --- Sidebar verticale des étapes (inspirée des wizards SaaS premium) --- */
        .wizard-sidebar { width: 210px; flex: none; background: linear-gradient(165deg, #f8fafc, #eef2f7); border-radius: 14px; padding: 12px 12px; display: flex; flex-direction: column; }
        .wizard-sidebar-step { display: flex; align-items: flex-start; gap: 10px; padding: 6px 8px; border-radius: 10px; transition: 0.2s; }
        .wizard-sidebar-step.clickable { cursor: pointer; }
        .wizard-sidebar-step.clickable:hover { background: rgba(52,152,219,0.08); }
        .ss-icon { width: 28px; height: 28px; min-width: 28px; border-radius: 50%; background: #e2e8f0; color: #94a3b8; display: flex; align-items: center; justify-content: center; font-size: 0.8rem; transition: 0.3s; }
        .wizard-sidebar-step.active .ss-icon { background: var(--accent); color: #fff; box-shadow: 0 0 0 4px rgba(52,152,219,0.15); }
        .wizard-sidebar-step.done .ss-icon { background: var(--success); color: #fff; }
        .wizard-sidebar-step.done.step-incomplete .ss-icon { background: var(--gelpam-orange); }
        .ss-text strong { display: block; font-size: 0.76rem; color: #94a3b8; font-weight: 700; line-height: 1.25; }
        .ss-text span { font-size: 0.63rem; color: #cbd5e1; }
        .wizard-sidebar-step.active .ss-text strong { color: var(--primary); }
        .wizard-sidebar-step.done .ss-text strong { color: var(--success); }
        .wizard-sidebar-step.done.step-incomplete .ss-text strong { color: var(--gelpam-orange); }
        .wizard-sidebar-connector { width: 2px; height: 10px; background: #e2e8f0; margin-left: 22px; transition: 0.3s; }
        .wizard-sidebar-connector.done { background: var(--success); }

        /* --- Bloc d'aide illustré, en haut de chaque étape (même présentation que l'assistant OT) --- */
        .wizard-step-help { display: flex; gap: 10px; align-items: center; background: #f7f9fb; border: 1px solid #e6e9ec; border-radius: 10px; padding: 6px 10px; margin-bottom: 6px; }
        .wizard-step-help img { width: 68px; height: 40px; object-fit: cover; object-position: top; border-radius: 7px; border: 1px solid #e2e8f0; flex-shrink: 0; background: #eef2f5; }
        .wizard-step-help-icon-fallback { width: 68px; height: 40px; border-radius: 7px; background: var(--soft-blue, #eaf4fc); color: var(--accent); display: flex; align-items: center; justify-content: center; font-size: 1.2rem; flex-shrink: 0; }
        .wizard-step-help-text { font-size: 0.71rem; font-weight: 400; color: #5a6b7a; line-height: 1.3; }
        .wizard-step-help-text b { color: var(--primary); }

        .wizard-sidebar-progress-wrap { margin-top: auto; padding-top: 10px; }
        .wizard-mini-progress-bar { height: 5px; background: #e2e8f0; border-radius: 3px; overflow: hidden; }
        .wizard-mini-progress-fill { height: 100%; background: linear-gradient(90deg, var(--accent), var(--success)); transition: width 0.35s ease; width: 0%; }
        .wizard-mini-progress-label { font-size: 0.62rem; color: #94a3b8; font-weight: 600; margin-top: 5px; text-align: center; }

        .wizard-main { flex: 1; min-width: 0; display: flex; flex-direction: column; }
        .wizard-content { min-height: 0; }
        .wizard-panel { display: none; animation: fadeInStep 0.25s ease; }
        .wizard-panel.active { display: block; }
        @keyframes fadeInStep { from { opacity: 0; transform: translateY(6px); } to { opacity: 1; transform: translateY(0); } }

        .wizard-panel-title { font-size: 0.95rem; font-weight: 700; color: var(--primary); display: flex; align-items: center; gap: 10px; margin-bottom: 2px; }
        .wizard-panel-desc { font-size: 0.73rem; font-weight: 400; color: #94a3b8; margin-bottom: 6px; }

        .form-grid-2 { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 8px; margin-bottom: 8px; }
        .form-grid-2 .span-2 { grid-column: 1 / -1; }

        .wizard-panel .form-group { margin-bottom: 6px !important; }
        .wizard-panel .form-group label { font-size: 0.7rem; font-weight: 600; display: flex; align-items: center; gap: 5px; }
        .wizard-panel .form-group label .req { color: var(--danger); }
        .wizard-panel .form-group input, .wizard-panel .form-group select, .wizard-panel .form-group textarea { height: 30px; font-size: 0.83rem; padding: 4px 9px; border-radius: 7px; }
        .wizard-panel .form-group textarea { height: 42px; min-height: 42px; padding: 5px 9px; }

        .field-error { border-color: var(--danger) !important; box-shadow: 0 0 0 3px rgba(231,76,60,0.12) !important; }

        .location-preview { background: #f1f5f9; border: 1px dashed #cbd5e1; border-radius: 8px; padding: 7px 12px; font-size: 0.76rem; font-weight: 400; color: #64748b; display: flex; align-items: center; gap: 8px; margin-top: 4px; flex-wrap: wrap; }
        .location-preview i { color: var(--accent); }
        .location-preview .lp-empty { color: #cbd5e1; font-style: italic; }

        /* ============================================================
           LOCALISATION MACHINE — recherche + navigation par tuiles
           (même pattern que la création de bon d'intervention, maintenance.php)
        ============================================================ */
        .loc-search-field { grid-column: 1 / -1; }
        .loc-search-wrap { position: relative; width: 100%; }
        .loc-search-wrap .loc-search-icon { position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: #94a3b8; font-size: 0.9rem; pointer-events: none; }
        #loc-search-input { width: 100%; height: 36px; padding: 0 12px 0 34px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 0.83rem; font-family: inherit; box-sizing: border-box; transition: border-color 0.2s; }
        #loc-search-input:focus { outline: none; border-color: var(--accent); }
        .loc-search-results { position: absolute; z-index: 50; top: calc(100% + 4px); left: 0; right: 0; max-height: 300px; overflow-y: auto; background: #fff; border: 1px solid #e2e8f0; border-radius: 10px; box-shadow: 0 12px 28px rgba(15,23,42,0.15); display: none; }
        .loc-search-item { padding: 9px 14px; cursor: pointer; border-bottom: 1px solid #f1f5f9; }
        .loc-search-item:last-child { border-bottom: none; }
        .loc-search-item:hover, .loc-search-item.is-active { background: var(--soft-blue); }
        .loc-search-item-name { font-weight: 700; font-size: 0.85rem; color: var(--primary); }
        .loc-search-item-path { font-size: 0.72rem; color: #94a3b8; margin-top: 2px; }
        .loc-search-empty { padding: 14px; text-align: center; color: #94a3b8; font-size: 0.82rem; }

        .loc-breadcrumb { display: flex; flex-wrap: wrap; align-items: center; gap: 6px; margin: 4px 0; grid-column: 1 / -1; }
        .loc-crumb { display: inline-flex; align-items: center; gap: 6px; padding: 5px 11px; border-radius: 20px; background: #f1f5f9; color: #64748b; font-size: 0.75rem; font-weight: 700; cursor: pointer; border: none; font-family: inherit; transition: 0.15s; }
        .loc-crumb:hover { background: #e2e8f0; }
        .loc-crumb.is-current { background: var(--soft-blue); color: var(--accent); cursor: default; }
        .loc-crumb.is-empty { background: none; color: #cbd5e1; font-weight: 600; cursor: default; }
        .loc-crumb-sep { color: #cbd5e1; font-size: 0.68rem; }

        .loc-tiles { display: grid; grid-template-columns: repeat(auto-fill, minmax(130px, 1fr)); gap: 8px; grid-column: 1 / -1; }
        .loc-tile { display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 6px; text-align: center; background: #fff; border: 2px solid #e2e8f0; border-radius: 10px; padding: 12px 8px; cursor: pointer; font-family: inherit; transition: 0.15s; }
        .loc-tile:hover { border-color: var(--accent); background: var(--soft-blue); transform: translateY(-2px); }
        .loc-tile-icon { width: 32px; height: 32px; border-radius: 9px; background: var(--soft-blue); color: var(--accent); display: flex; align-items: center; justify-content: center; font-size: 1rem; }
        .loc-tile-label { font-size: 0.76rem; font-weight: 700; color: var(--primary); line-height: 1.25; word-break: break-word; }
        .loc-empty-msg { grid-column: 1 / -1; padding: 16px; text-align: center; color: #94a3b8; font-size: 0.82rem; background: #f8fafc; border-radius: 10px; }

        .loc-done-card { grid-column: 1 / -1; display: flex; align-items: center; justify-content: space-between; gap: 12px; background: var(--soft-green); border: 2px solid #b8ecd9; border-radius: 10px; padding: 12px 14px; flex-wrap: wrap; }
        .loc-done-info { display: flex; align-items: center; gap: 10px; }
        .loc-done-icon { width: 36px; height: 36px; border-radius: 9px; background: var(--gelpam-green); color: #fff; display: flex; align-items: center; justify-content: center; font-size: 1.05rem; flex-shrink: 0; }
        .loc-done-name { font-weight: 800; color: var(--primary); font-size: 0.88rem; }
        .loc-done-path { font-size: 0.72rem; color: #5a8a76; margin-top: 2px; }
        .loc-done-change { background: #fff; border: 1px solid #cbd5e1; color: var(--primary); font-weight: 700; font-size: 0.74rem; padding: 6px 12px; border-radius: 8px; cursor: pointer; font-family: inherit; }
        .loc-done-change:hover { border-color: var(--accent); color: var(--accent); }

        .review-card { background: #fff; border: 1px solid #e2e8f0; border-radius: 10px; padding: 10px 14px; margin-top: 6px; }
        .review-row { display: flex; justify-content: space-between; align-items: center; padding: 5px 0; border-bottom: 1px solid #f1f5f9; font-size: 0.8rem; }
        .review-row:last-child { border-bottom: none; }
        .review-row .rr-label { color: #94a3b8; font-weight: 600; display: flex; align-items: center; gap: 8px; }
        .review-row .rr-value { color: var(--primary); font-weight: 600; text-align: right; }

        .wizard-nav { display: flex; justify-content: space-between; align-items: center; margin-top: 12px; border-top: 1px solid #e2e8f0; padding-top: 10px; }
        .btn-wizard { border: none; font-weight: 600; border-radius: 8px; cursor: pointer; transition: 0.2s; font-size: 0.82rem; padding: 8px 18px; display: flex; align-items: center; gap: 8px; }
        .btn-wizard-prev { background: #f1f5f9; color: #64748b; }
        .btn-wizard-prev:hover { background: #e2e8f0; }
        .btn-wizard-next { background: var(--accent); color: #fff; }
        .btn-wizard-next:hover { background: #2980b9; }
        .btn-wizard-save { background: var(--success); color: #fff; }
        .btn-wizard-save:hover { background: #27ae60; }
        .btn-wizard-cancel { background: none; color: #94a3b8; font-weight: 500; }
        .btn-wizard-cancel:hover { color: var(--danger); }

        /* --- SÉLECTEURS VISUELS PAR CARTES (Priorité / Fréquence / Impact) --- */
        .choice-grid { display: grid; gap: 6px; }
        .choice-grid.cols-2 { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        .choice-grid.cols-3 { grid-template-columns: repeat(3, minmax(0, 1fr)); }
        .choice-grid.cols-4 { grid-template-columns: repeat(4, minmax(0, 1fr)); }
        .choice-card { border: 2px solid #e2e8f0; background: #fff; border-radius: 9px; padding: 5px 6px; text-align: center; cursor: pointer; transition: 0.15s; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 3px; min-height: 36px; }
        .choice-card i { font-size: 0.86rem; color: #94a3b8; transition: 0.15s; }
        .choice-card span { font-size: 0.6rem; font-weight: 600; color: #64748b; line-height: 1.1; }
        .choice-card:hover { border-color: #cbd5e1; transform: translateY(-2px); }
        .choice-card.selected { border-color: var(--accent); background: rgba(52,152,219,0.06); box-shadow: 0 4px 10px rgba(52,152,219,0.15); }
        .choice-card.selected i, .choice-card.selected span { color: var(--accent); }
        .choice-card.c-urgent.selected { border-color: var(--danger); background: rgba(231,76,60,0.06); }
        .choice-card.c-urgent.selected i, .choice-card.c-urgent.selected span { color: var(--danger); }
        .choice-card.c-danger.selected { border-color: var(--danger); background: rgba(231,76,60,0.06); }
        .choice-card.c-danger.selected i, .choice-card.c-danger.selected span { color: var(--danger); }
        .choice-card.c-warning.selected { border-color: var(--gelpam-orange); background: rgba(243,156,18,0.08); }
        .choice-card.c-warning.selected i, .choice-card.c-warning.selected span { color: var(--gelpam-orange); }

        .jour-chip { border: 2px solid #e2e8f0; background: #fff; border-radius: 20px; padding: 6px 12px; font-size: 0.75rem; font-weight: 600; color: #64748b; cursor: pointer; transition: 0.15s; display: flex; align-items: center; gap: 6px; }
        .jour-chip:hover { border-color: #cbd5e1; }
        .jour-chip:has(input:checked) { border-color: var(--accent); background: rgba(52,152,219,0.06); color: var(--accent); }
        .jour-chip input { accent-color: var(--accent); }
        #joursModeBlock { border: 2px solid transparent; border-radius: 10px; padding: 8px; margin: -8px; }

        /* --- BULLES D'AIDE --- */
        .help-tip { display: inline-flex; align-items: center; justify-content: center; width: 15px; height: 15px; border-radius: 50%; background: #e2e8f0; color: #64748b; font-size: 0.62rem; font-weight: 800; cursor: help; position: relative; }
        .help-tip:hover .help-bubble, .help-tip:focus .help-bubble { opacity: 1; visibility: visible; transform: translateX(-50%) translateY(0); }
        .help-bubble { position: absolute; bottom: 135%; left: 50%; transform: translateX(-50%) translateY(4px); background: #1e293b; color: #fff; font-size: 0.72rem; font-weight: 500; padding: 8px 12px; border-radius: 8px; width: 210px; line-height: 1.4; opacity: 0; visibility: hidden; transition: 0.2s; z-index: 20; box-shadow: 0 8px 20px rgba(0,0,0,0.25); text-align: left; }
        .help-bubble::after { content: ""; position: absolute; top: 100%; left: 50%; transform: translateX(-50%); border: 6px solid transparent; border-top-color: #1e293b; }

        /* --- MODALE DE DÉTAIL (consultation d'une règle) --- */
        .modal-content-detail { width: 720px; max-width: 95%; padding: 20px 28px; }
        .detail-header { display: flex; justify-content: space-between; align-items: flex-start; gap: 15px; margin-bottom: 6px; }
        .detail-title { font-family: 'Caveat', cursive; font-size: 1.75rem; color: var(--primary); margin: 0; display: flex; align-items: center; gap: 10px; }
        .detail-subtitle { font-size: 0.8rem; color: #94a3b8; margin-top: 2px; font-weight: 400; }
        .detail-badges-row { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 8px; }
        .detail-pill { padding: 4px 11px; border-radius: 20px; font-size: 0.68rem; font-weight: 600; display: inline-flex; align-items: center; gap: 6px; white-space: nowrap; }
        .info-tile-grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 8px; }
        .info-tile { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 9px; padding: 7px 10px; }
        .info-tile .it-label { font-size: 0.6rem; color: #94a3b8; text-transform: uppercase; font-weight: 600; letter-spacing: 0.3px; margin-bottom: 3px; display: flex; align-items: center; gap: 5px; }
        .info-tile .it-value { font-size: 0.83rem; color: var(--primary); font-weight: 500; word-break: break-word; }
        .detail-section { margin-top: 12px; }
        .detail-section:first-child { margin-top: 4px; }
        .detail-section-title { font-size: 0.68rem; text-transform: uppercase; color: #64748b; font-weight: 600; margin: 0 0 6px 0; display: flex; align-items: center; gap: 6px; letter-spacing: 0.3px; border-bottom: 1px solid #e2e8f0; padding-bottom: 4px; }
        .detail-section-title i { color: var(--accent); }
        .detail-block { margin-top: 8px; }
        .detail-block h4 { font-size: 0.68rem; text-transform: uppercase; color: #64748b; font-weight: 600; margin: 0 0 4px 0; display: flex; align-items: center; gap: 6px; letter-spacing: 0.2px; }
        .detail-block p { font-size: 0.83rem; font-weight: 400; color: #334155; margin: 0; line-height: 1.42; background: #f8fafc; border-radius: 8px; padding: 8px 10px; border: 1px solid #f1f5f9; white-space: pre-wrap; }
        .history-row { display: grid; grid-template-columns: 1fr 1.4fr 1fr 1fr; gap: 10px; align-items: center; padding: 6px 10px; font-size: 0.76rem; font-weight: 400; color: #334155; background: #f8fafc; border: 1px solid #f1f5f9; border-radius: 8px; margin-bottom: 5px; cursor: pointer; transition: 0.15s; }
        .history-row:hover { background: #eff6ff; border-color: #bfdbfe; }
        #detailHistoryList { max-height: 110px; overflow-y: auto; }
        .btn-row-detail { display: flex; justify-content: flex-end; gap: 10px; margin-top: 12px; border-top: 1px solid #e2e8f0; padding-top: 10px; }
        #tableBody tr { cursor: pointer; }

        /* --- IMPRESSION (REGISTRE AUDIT) --- */
        .print-header { display: none; }
        @media print {
            body { background: #fff !important; padding-top: 0 !important; height: auto !important; overflow: visible !important; }
            .container, .card, .table-scroll { height: auto !important; overflow: visible !important; }
            header, .sidebar, .btn-floating-nav, .btn-add, .btn-print, .search-bar, .filter-chips, .kpi-row, th:last-child, td:last-child, .btn-edit, .btn-del, .btn-status { display: none !important; }
            .card { box-shadow: none; border-left: none; padding: 0; }
            .print-header { display: block; margin-bottom: 20px; }
            .print-header h1 { font-family: 'Segoe UI', sans-serif; font-size: 1.4rem; color: #000; margin: 0 0 4px 0; }
            .print-header p { font-size: 0.8rem; color: #444; margin: 0; }
            table { font-size: 0.75rem; }
            th { position: static !important; }
        }
    </style>
<?php include 'pwa_head.php'; ?>
</head>
<body onclick="checkCloseSidebar(event)">

<?php include 'navbar.php'; ?>

<div class="container">

    <div class="print-header">
        <h1><?php echo t('preventifliste.print_h1'); ?></h1>
        <p><?php echo t('preventifliste.print_edite_le'); ?> <span id="printDate"></span> — <?php echo t('preventifliste.print_doc_suivi'); ?></p>
    </div>

    <div class="preventif-tabs" id="preventifTabs">
        <button type="button" class="preventif-tab active" data-tab="regles" onclick="switchTab('regles')"><i class="fa-solid fa-rotate"></i> <?php echo t('preventifliste.tab_regles'); ?></button>
        <button type="button" class="preventif-tab" data-tab="checklist" onclick="switchTab('checklist')"><i class="fa-solid fa-square-check"></i> <?php echo t('preventifliste.tab_checklist'); ?> <span class="chip-count" id="cnt-checklist-todo">0</span></button>
    </div>

    <div id="vue-regles">
    <div class="kpi-row">
        <div class="kpi-card kpi-filterable active" data-filter="tous" onclick="setFilter('tous')" title="<?php echo htmlspecialchars(t('preventifliste.tooltip_voir_toutes')); ?>">
            <div class="kpi-icon"><i class="fa-solid fa-list-check"></i></div>
            <div>
                <div class="kpi-value" id="kpiTotal">0</div>
                <div class="kpi-label"><?php echo t('preventifliste.kpi_regles_actives'); ?></div>
            </div>
        </div>
        <div class="kpi-card k-danger kpi-filterable" data-filter="overdue" onclick="setFilter('overdue')" title="<?php echo htmlspecialchars(t('preventifliste.tooltip_filtrer_retard')); ?>">
            <div class="kpi-icon"><i class="fa-solid fa-triangle-exclamation"></i></div>
            <div>
                <div class="kpi-value" id="kpiOverdue">0</div>
                <div class="kpi-label"><?php echo t('preventifliste.kpi_en_retard'); ?></div>
            </div>
        </div>
        <div class="kpi-card k-warning kpi-filterable" data-filter="soon" onclick="setFilter('soon')" title="<?php echo htmlspecialchars(t('preventifliste.tooltip_filtrer_bientot')); ?>">
            <div class="kpi-icon"><i class="fa-solid fa-hourglass-half"></i></div>
            <div>
                <div class="kpi-value" id="kpiSoon">0</div>
                <div class="kpi-label"><?php echo t('preventifliste.kpi_echeance_7j'); ?></div>
            </div>
        </div>
        <div class="kpi-card k-success kpi-filterable" data-filter="compliant" onclick="setFilter('compliant')" title="<?php echo htmlspecialchars(t('preventifliste.tooltip_filtrer_conformes')); ?>">
            <div class="kpi-icon"><i class="fa-solid fa-shield-halved"></i></div>
            <div>
                <div class="kpi-value" id="kpiCompliance">100%</div>
                <div class="kpi-label"><?php echo t('preventifliste.kpi_taux_conformite'); ?></div>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="page-header">
            <div>
                <h1 class="page-title">
                    <i class="fa-solid <?php echo $cat ? htmlspecialchars($CATEGORIES_META[$cat]['icon']) : 'fa-calendar-check'; ?>" style="color: <?php echo $cat ? htmlspecialchars($CATEGORIES_META[$cat]['couleur']) : 'var(--accent)'; ?>;"></i>
                    <?php echo $cat ? htmlspecialchars($CATEGORIES_META[$cat]['label']) : t('preventifliste.plan_maintenance_preventive'); ?>
                </h1>
                <p class="page-subtitle">
                    <?php echo $cat ? t('preventifliste.vue_filtree_prefix').' ' : ''; ?><?php echo t('preventifliste.page_subtitle'); ?>
                </p>
            </div>
            <div class="header-actions">
                <button class="btn-print" onclick="openHistorique()"><i class="fa-solid fa-clock-rotate-left"></i> <?php echo t('preventifliste.btn_historique'); ?></button>
                <button class="btn-print" onclick="window.print()"><i class="fa-solid fa-print"></i> <?php echo t('preventifliste.btn_registre_imprimable'); ?></button>
                <button class="btn-add" onclick="openModal()"><i class="fa-solid fa-plus"></i> <?php echo t('preventifliste.btn_nouvelle_regle'); ?></button>
            </div>
        </div>

        <div class="toolbar">
            <input type="text" id="searchInput" class="search-bar" placeholder="<?php echo htmlspecialchars(t('preventifliste.search_regles_placeholder')); ?>" onkeyup="renderTable()">
            <div class="filter-chips" id="filterChips">
                <button class="chip active" data-filter="tous" onclick="setFilter('tous')"><i class="fa-solid fa-layer-group"></i> <?php echo t('preventifliste.chip_tous'); ?> <span class="chip-count" id="cnt-tous">0</span></button>
                <button class="chip" data-filter="overdue" onclick="setFilter('overdue')"><i class="fa-solid fa-triangle-exclamation"></i> <?php echo t('preventifliste.chip_en_retard'); ?> <span class="chip-count" id="cnt-overdue">0</span></button>
                <button class="chip" data-filter="soon" onclick="setFilter('soon')"><i class="fa-solid fa-hourglass-half"></i> <?php echo t('preventifliste.chip_bientot'); ?> <span class="chip-count" id="cnt-soon">0</span></button>
                <button class="chip" data-filter="ok" onclick="setFilter('ok')"><i class="fa-solid fa-circle-check"></i> <?php echo t('preventifliste.chip_a_jour'); ?> <span class="chip-count" id="cnt-ok">0</span></button>
                <button class="chip" data-filter="paused" onclick="setFilter('paused')"><i class="fa-solid fa-snowflake"></i> <?php echo t('preventifliste.chip_en_pause'); ?> <span class="chip-count" id="cnt-paused">0</span></button>
            </div>
        </div>

        <div class="table-scroll">
            <table>
                <thead>
                    <tr>
                        <th style="width: 20%;"><?php echo t('preventifliste.th_equipement'); ?></th>
                        <th style="width: 19%;"><?php echo t('preventifliste.th_operation'); ?></th>
                        <th style="width: 8%;"><?php echo t('preventifliste.th_frequence'); ?></th>
                        <th style="width: 12%;"><?php echo t('preventifliste.th_echeance'); ?></th>
                        <th style="width: 10%;"><?php echo t('preventifliste.th_intervenant'); ?></th>
                        <th style="width: 9%; text-align:center;"><?php echo t('preventifliste.th_duree'); ?></th>
                        <th style="width: 10%;"><?php echo t('preventifliste.th_dernier_bi'); ?></th>
                        <th style="width: 9%; text-align:center;"><?php echo t('preventifliste.th_actions'); ?></th>
                    </tr>
                </thead>
                <tbody id="tableBody"></tbody>
            </table>
        </div>
        <div id="rulesCards" class="prev-cards-grid"></div>
        <div class="empty-state" id="emptyState" style="display:none;">
            <i class="fa-solid fa-inbox"></i>
            <div><?php echo t('preventifliste.empty_regles'); ?></div>
        </div>
    </div>
    </div>

    <div id="vue-checklist" style="display:none;">
        <div class="card">
            <div class="page-header">
                <div>
                    <h1 class="page-title">
                        <i class="fa-solid fa-square-check" style="color: <?php echo $cat ? htmlspecialchars($CATEGORIES_META[$cat]['couleur']) : 'var(--accent)'; ?>;"></i>
                        <?php echo t('preventifliste.travaux_hiver_saison'); ?> <span id="checklist-saison-label">—</span>
                    </h1>
                    <p class="page-subtitle"><?php echo t('preventifliste.checklist_subtitle'); ?></p>
                </div>
                <div class="header-actions">
                    <select id="checklistSaisonSelect" class="filter-select" style="min-width:auto;" onchange="changerSaisonChecklist(this.value)"></select>
                    <button id="btn-reporter-selection" class="btn-print btn-pulse" style="display:none;" onclick="dupliquerChecklist()" title="<?php echo htmlspecialchars(t('preventifliste.tooltip_reporter_selection')); ?>"><i class="fa-solid fa-copy"></i> <?php echo t('preventifliste.btn_reporter_selection'); ?></button>
                    <button class="btn-add" onclick="ouvrirAjoutChecklistItem()"><i class="fa-solid fa-plus"></i> <?php echo t('preventifliste.btn_ajouter_tache'); ?></button>
                </div>
            </div>

            <div class="toolbar">
                <input type="text" id="checklistSearch" class="search-bar" placeholder="<?php echo htmlspecialchars(t('preventifliste.search_checklist_placeholder')); ?>" oninput="appliquerFiltresChecklist()">
                <select id="checklistFilterUsine" class="filter-select" onchange="appliquerFiltresChecklist()"><option value=""><?php echo t('preventifliste.opt_toutes_usines'); ?></option></select>
                <select id="checklistFilterIntervenant" class="filter-select" onchange="appliquerFiltresChecklist()"><option value=""><?php echo t('preventifliste.opt_tous_intervenants'); ?></option></select>
                <select id="checklistFilterPrio" class="filter-select" onchange="appliquerFiltresChecklist()">
                    <option value=""><?php echo t('preventifliste.opt_toutes_priorites'); ?></option>
                    <option value="1"><?php echo str_replace('{n}', '1', t('preventifliste.opt_priorite_n')); ?></option>
                    <option value="2"><?php echo str_replace('{n}', '2', t('preventifliste.opt_priorite_n')); ?></option>
                    <option value="3"><?php echo str_replace('{n}', '3', t('preventifliste.opt_priorite_n')); ?></option>
                    <option value="4"><?php echo str_replace('{n}', '4', t('preventifliste.opt_priorite_n')); ?></option>
                </select>
                <div class="filter-chips" id="checklistStatutChips">
                    <button class="chip active" data-filter="tous" onclick="setChecklistFilter('tous')"><i class="fa-solid fa-layer-group"></i> <?php echo t('preventifliste.chip_toutes'); ?> <span class="chip-count" id="cnt-ckl-tous">0</span></button>
                    <button class="chip" data-filter="a_faire" onclick="setChecklistFilter('a_faire')"><i class="fa-solid fa-hourglass-half"></i> <?php echo t('preventifliste.chip_a_faire'); ?> <span class="chip-count" id="cnt-ckl-afaire">0</span></button>
                    <button class="chip" data-filter="en_cours" onclick="setChecklistFilter('en_cours')"><i class="fa-solid fa-spinner"></i> <?php echo t('preventifliste.chip_en_cours'); ?> <span class="chip-count" id="cnt-ckl-encours">0</span></button>
                    <button class="chip" data-filter="termine" onclick="setChecklistFilter('termine')"><i class="fa-solid fa-circle-check"></i> <?php echo t('preventifliste.chip_terminees'); ?> <span class="chip-count" id="cnt-ckl-termine">0</span></button>
                </div>
            </div>

            <div class="table-scroll">
                <table id="checklistTable">
                    <thead>
                        <tr>
                            <th style="width: 2%;" title="<?php echo htmlspecialchars(t('preventifliste.tooltip_selection_report')); ?>"><i class="fa-solid fa-arrow-right-arrow-left" style="font-size:0.65rem;"></i><span class="ckl-col-resizer" onmousedown="event.stopPropagation()"></span></th>
                            <th style="width: 9%;" class="ckl-th-tri" onclick="trierChecklist('usine')"><?php echo t('preventifliste.th_usine'); ?> <i class="fa-solid fa-sort"></i><span class="ckl-col-resizer" onmousedown="event.stopPropagation()"></span></th>
                            <th style="width: 10%;" class="ckl-th-tri" onclick="trierChecklist('equip')"><?php echo t('preventifliste.th_equipement'); ?> <i class="fa-solid fa-sort"></i><span class="ckl-col-resizer" onmousedown="event.stopPropagation()"></span></th>
                            <th><?php echo t('preventifliste.th_tache'); ?><span class="ckl-col-resizer" onmousedown="event.stopPropagation()"></span></th>
                            <th style="width: 9%;" class="ckl-th-tri" onclick="trierChecklist('intervenant')"><?php echo t('preventifliste.th_intervenant'); ?> <i class="fa-solid fa-sort"></i><span class="ckl-col-resizer" onmousedown="event.stopPropagation()"></span></th>
                            <th style="width: 7%;" class="ckl-th-tri" onclick="trierChecklist('date_prevue')"><?php echo t('preventifliste.th_date'); ?> <i class="fa-solid fa-sort"></i><span class="ckl-col-resizer" onmousedown="event.stopPropagation()"></span></th>
                            <th style="width: 4%;" class="ckl-th-tri" onclick="trierChecklist('prio')"><?php echo t('preventifliste.th_prio'); ?> <i class="fa-solid fa-sort"></i><span class="ckl-col-resizer" onmousedown="event.stopPropagation()"></span></th>
                            <th style="width: 10%;"><?php echo t('preventifliste.th_statut'); ?><span class="ckl-col-resizer" onmousedown="event.stopPropagation()"></span></th>
                            <th style="width: 12%; text-align:center;"><?php echo t('preventifliste.th_actions'); ?></th>
                        </tr>
                    </thead>
                    <tbody id="checklistBody"></tbody>
                </table>
            </div>
            <div id="checklistCards" class="prev-cards-grid"></div>
            <div class="empty-state" id="checklistEmptyState" style="display:none;">
                <i class="fa-solid fa-inbox"></i>
                <div><?php echo t('preventifliste.empty_checklist'); ?></div>
            </div>
        </div>
    </div>
</div>

<div id="modalChecklistItem" class="modal">
    <div class="modal-content" style="max-width:820px; padding:26px 34px;">
        <div style="display:flex; justify-content:space-between; align-items:center; border-bottom:3px solid var(--primary); padding-bottom:14px;">
            <h2 id="ckl-modal-title" style="font-family:'Caveat', cursive; margin:0; color:var(--primary); display:flex; align-items:center; gap:10px;">
                <i class="fa-solid fa-square-check" style="color:var(--accent);"></i> <?php echo t('preventifliste.ckl_modal_ajouter'); ?>
            </h2>
            <span style="cursor:pointer; font-size:30px; color:#94a3b8;" onclick="fermerAjoutChecklistItem()">&times;</span>
        </div>
        <div style="margin-top:24px;">
            <input type="hidden" id="ckl-item-id">

            <div class="ckl-modal-grid" style="display:grid; grid-template-columns:1fr 1fr 1fr; gap:20px; margin-bottom:18px;">
                <div>
                    <label style="display:flex; justify-content:space-between; align-items:center; font-size:0.7rem; font-weight:700; color:#555; text-transform:uppercase; margin-bottom:6px;">
                        <?php echo t('preventifliste.label_usine'); ?>
                        <span onclick="ouvrirGererZones()" style="cursor:pointer; color:var(--gelpam-orange); font-weight:600; text-transform:none; font-size:0.72rem;"><i class="fa-solid fa-gear"></i> <?php echo t('preventifliste.gerer_link'); ?></span>
                    </label>
                    <select id="ckl-usine" style="width:100%; padding:9px 10px; border:1px solid #ddd; border-radius:8px; font-family:inherit; font-size:0.88rem; box-sizing:border-box;">
                        <option value="">—</option>
                    </select>
                </div>
                <div>
                    <label style="display:block; font-size:0.7rem; font-weight:700; color:#555; text-transform:uppercase; margin-bottom:6px;"><?php echo t('preventifliste.label_equipement'); ?></label>
                    <input type="text" id="ckl-equip" style="width:100%; padding:9px 10px; border:1px solid #ddd; border-radius:8px; font-family:inherit; font-size:0.88rem; box-sizing:border-box;">
                </div>
                <div>
                    <label style="display:block; font-size:0.7rem; font-weight:700; color:#555; text-transform:uppercase; margin-bottom:6px;"><?php echo t('preventifliste.label_signale_par'); ?></label>
                    <select id="ckl-signale-par" style="width:100%; padding:9px 10px; border:1px solid #ddd; border-radius:8px; font-family:inherit; font-size:0.88rem; box-sizing:border-box;"></select>
                </div>
            </div>

            <label style="display:block; font-size:0.7rem; font-weight:700; color:#555; text-transform:uppercase; margin-bottom:6px;"><?php echo t('preventifliste.label_tache'); ?> <span style="color:var(--danger);">*</span></label>
            <textarea id="ckl-desc" rows="2" style="width:100%; padding:9px 10px; border:1px solid #ddd; border-radius:8px; font-family:inherit; font-size:0.88rem; box-sizing:border-box; margin-bottom:18px; resize:vertical;"></textarea>

            <label style="display:block; font-size:0.7rem; font-weight:700; color:#555; text-transform:uppercase; margin-bottom:6px;"><?php echo t('preventifliste.label_intervenants'); ?> <span style="font-weight:400; text-transform:none; color:var(--accent);"><?php echo t('preventifliste.hint_plusieurs_personnes'); ?></span></label>
            <div id="ckl-intervenant-checkboxes" style="max-height:100px; overflow-y:auto; border:1px solid #ddd; border-radius:8px; padding:8px 10px; margin-bottom:10px; display:flex; flex-direction:column; gap:4px;"></div>

            <div style="display:flex; align-items:center; gap:10px; background:#f8fafc; padding:10px; border-radius:6px; border:1px dashed #cbd5e1; margin-bottom:10px;">
                <input type="checkbox" id="ckl-is-st" onchange="toggleSTChecklist()" style="width:18px; height:18px; margin:0;">
                <label for="ckl-is-st" style="color:var(--primary); font-size:0.85rem; cursor:pointer; font-weight:bold;"><?php echo t('preventifliste.check_sous_traite'); ?></label>
            </div>
            <div class="field" id="ckl-container-ee" style="display:none; background:#fff5e6; padding:10px; border-radius:6px; border-left:4px solid var(--gelpam-orange); margin-bottom:18px;">
                <label style="display:block; font-size:0.7rem; font-weight:700; color:var(--gelpam-orange); text-transform:uppercase; margin-bottom:6px;"><?php echo t('preventifliste.label_entreprise_ext'); ?></label>
                <select id="ckl-entreprise" style="width:100%; padding:9px 10px; border:1px solid #ddd; border-radius:8px; font-family:inherit; font-size:0.88rem; box-sizing:border-box;">
                    <option value=""><?php echo t('preventifliste.opt_choisir_entreprise'); ?></option>
                    <?php foreach ($entreprises_ext as $ee): ?>
                        <option value="<?php echo htmlspecialchars($ee['id']); ?>"><?php echo htmlspecialchars($ee['nom']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="ckl-modal-grid" style="display:grid; grid-template-columns:1.6fr 1fr 1fr; gap:20px;">
                <div>
                    <label style="display:block; font-size:0.7rem; font-weight:700; color:#555; text-transform:uppercase; margin-bottom:6px;"><?php echo t('preventifliste.label_autre_intervenant'); ?> <span style="font-weight:400; text-transform:none; color:var(--accent);"><?php echo t('preventifliste.hint_renfort_saisonnier'); ?></span></label>
                    <input type="text" id="ckl-intervenant-libre" placeholder="<?php echo htmlspecialchars(t('preventifliste.placeholder_un_nom')); ?>" style="width:100%; padding:9px 10px; border:1px solid #ddd; border-radius:8px; font-family:inherit; font-size:0.88rem; box-sizing:border-box;">
                </div>
                <div>
                    <label style="display:block; font-size:0.7rem; font-weight:700; color:#555; text-transform:uppercase; margin-bottom:6px;"><?php echo t('preventifliste.label_date_prevue'); ?></label>
                    <input type="date" id="ckl-date-prevue" style="width:100%; padding:9px 10px; border:1px solid #ddd; border-radius:8px; font-family:inherit; font-size:0.88rem; box-sizing:border-box;">
                </div>
                <div>
                    <label style="display:block; font-size:0.7rem; font-weight:700; color:#555; text-transform:uppercase; margin-bottom:6px;"><?php echo t('preventifliste.label_priorite'); ?></label>
                    <select id="ckl-prio" style="width:100%; padding:9px 10px; border:1px solid #ddd; border-radius:8px; font-family:inherit; font-size:0.88rem; box-sizing:border-box;">
                        <option value="">—</option>
                        <option value="1">1</option>
                        <option value="2">2</option>
                        <option value="3">3</option>
                        <option value="4">4</option>
                    </select>
                </div>
            </div>
            <div id="ckl-error" style="font-size:0.72rem; color:var(--danger); font-weight:600; min-height:16px; margin-top:8px;"></div>
        </div>
        <div style="display:flex; gap:10px; margin-top:16px;">
            <button onclick="fermerAjoutChecklistItem()" style="flex:1; padding:11px; border:none; border-radius:8px; background:#f1f5f9; color:#64748b; cursor:pointer; font-weight:700;"><?php echo t('preventifliste.btn_annuler'); ?></button>
            <button id="ckl-modal-submit" onclick="confirmerAjoutChecklistItem()" style="flex:1; padding:11px; border:none; border-radius:8px; background:var(--success); color:white; cursor:pointer; font-weight:700;"><i class="fa-solid fa-plus"></i> <?php echo t('preventifliste.btn_ajouter'); ?></button>
        </div>
    </div>
</div>

<div id="modalGererZones" class="modal">
    <div class="modal-content" style="max-width:420px;">
        <div style="display:flex; justify-content:space-between; align-items:center; border-bottom:3px solid var(--accent); padding-bottom:10px;">
            <h2 style="font-family:'Caveat', cursive; margin:0; color:var(--primary); display:flex; align-items:center; gap:10px;">
                <i class="fa-solid fa-gear" style="color:var(--accent);"></i> <?php echo t('preventifliste.gerer_zones_title'); ?>
            </h2>
            <span style="cursor:pointer; font-size:30px; color:#94a3b8;" onclick="fermerGererZones()">&times;</span>
        </div>
        <div style="margin-top:18px;">
            <div id="ckl-zones-liste-gestion" style="max-height:260px; overflow-y:auto; display:flex; flex-direction:column; gap:6px; margin-bottom:14px;"></div>
            <div id="ckl-zone-erreur" style="font-size:0.72rem; color:var(--danger); font-weight:600; min-height:16px; margin-bottom:6px;"></div>
            <div style="display:flex; gap:8px;">
                <input type="text" id="ckl-nouvelle-zone" placeholder="<?php echo htmlspecialchars(t('preventifliste.placeholder_nouvelle_zone')); ?>" style="flex:1; padding:9px 10px; border:1px solid #ddd; border-radius:8px; font-family:inherit; font-size:0.88rem; box-sizing:border-box;">
                <button onclick="ajouterZone()" style="padding:9px 16px; border:none; border-radius:8px; background:var(--accent); color:white; cursor:pointer; font-weight:700;"><i class="fa-solid fa-plus"></i></button>
            </div>
        </div>
        <div style="display:flex; margin-top:14px;">
            <button onclick="fermerGererZones()" style="flex:1; padding:11px; border:none; border-radius:8px; background:#f1f5f9; color:#64748b; cursor:pointer; font-weight:700;"><?php echo t('preventifliste.btn_fermer'); ?></button>
        </div>
    </div>
</div>

<div id="modalDupliquerChecklist" class="modal">
    <div class="modal-content" style="max-width:420px;">
        <div style="display:flex; justify-content:space-between; align-items:center; border-bottom:3px solid var(--accent); padding-bottom:10px;">
            <h2 style="font-family:'Caveat', cursive; margin:0; color:var(--primary); display:flex; align-items:center; gap:10px;">
                <i class="fa-solid fa-copy" style="color:var(--accent);"></i> <?php echo t('preventifliste.reporter_taches_title'); ?>
            </h2>
            <span style="cursor:pointer; font-size:30px; color:#94a3b8;" onclick="fermerDupliquerChecklist()">&times;</span>
        </div>
        <div style="margin-top:18px;">
            <p style="font-size:0.82rem; color:#5a6b7a; margin:0 0 14px;"><?php echo t('preventifliste.reporter_taches_desc'); ?></p>
            <label style="display:block; font-size:0.7rem; font-weight:700; color:#555; text-transform:uppercase; margin-bottom:6px;"><?php echo t('preventifliste.label_nouvelle_saison'); ?></label>
            <input type="text" id="ckl-nouvelle-saison" list="ckl-saisons-liste" placeholder="<?php echo htmlspecialchars(t('preventifliste.placeholder_saison')); ?>" style="width:100%; padding:9px 10px; border:1px solid #ddd; border-radius:8px; font-family:inherit; font-size:0.88rem; box-sizing:border-box;">
            <datalist id="ckl-saisons-liste"></datalist>
            <p style="font-size:0.72rem; color:#94a3b8; margin:6px 0 0;"><?php echo t('preventifliste.hint_saison'); ?></p>
            <div id="ckl-dup-error" style="font-size:0.72rem; color:var(--danger); font-weight:600; min-height:16px; margin-top:8px;"></div>
        </div>
        <div style="display:flex; gap:10px; margin-top:10px;">
            <button onclick="fermerDupliquerChecklist()" style="flex:1; padding:11px; border:none; border-radius:8px; background:#f1f5f9; color:#64748b; cursor:pointer; font-weight:700;"><?php echo t('preventifliste.btn_annuler'); ?></button>
            <button onclick="confirmerDupliquerChecklist()" style="flex:1; padding:11px; border:none; border-radius:8px; background:var(--accent); color:white; cursor:pointer; font-weight:700;"><i class="fa-solid fa-copy"></i> <?php echo t('preventifliste.btn_reporter'); ?></button>
        </div>
    </div>
</div>

<div id="modalPreventif" class="modal">
    <div class="modal-content-large modal-content-wizard">

        <div style="display: flex; justify-content: space-between; align-items: center;">
            <h2 id="wizardTitle" style="font-family:'Caveat', cursive; font-size:1.7rem; color:var(--primary); margin:0; display:flex; align-items:center; gap:10px;">
                <i class="fa-solid fa-sliders" style="color:var(--accent);"></i> <?php echo t('preventifliste.wizard_title_new'); ?>
            </h2>
            <button onclick="closeModal()" style="background:none; border:none; font-size:2rem; cursor:pointer; color:#94a3b8;">&times;</button>
        </div>

        <input type="hidden" id="f-id">

        <div class="wizard-shell">

            <!-- SIDEBAR VERTICALE DES ÉTAPES -->
            <div class="wizard-sidebar">
                <div class="wizard-sidebar-step" data-step="1" onclick="tryGoToStep(1)">
                    <div class="ss-icon"><i class="fa-solid fa-clipboard-list"></i></div>
                    <div class="ss-text"><strong><?php echo t('preventifliste.step1_title'); ?></strong><span><?php echo t('preventifliste.step1_sub'); ?></span></div>
                </div>
                <div class="wizard-sidebar-connector" data-connector="1"></div>
                <div class="wizard-sidebar-step" data-step="2" onclick="tryGoToStep(2)">
                    <div class="ss-icon"><i class="fa-solid fa-location-dot"></i></div>
                    <div class="ss-text"><strong><?php echo t('preventifliste.step2_title'); ?></strong><span><?php echo t('preventifliste.step2_sub'); ?></span></div>
                </div>
                <div class="wizard-sidebar-connector" data-connector="2"></div>
                <div class="wizard-sidebar-step" data-step="3" onclick="tryGoToStep(3)">
                    <div class="ss-icon"><i class="fa-solid fa-industry"></i></div>
                    <div class="ss-text"><strong><?php echo t('preventifliste.step3_title'); ?></strong><span><?php echo t('preventifliste.step3_sub'); ?></span></div>
                </div>
                <div class="wizard-sidebar-connector" data-connector="3"></div>
                <div class="wizard-sidebar-step" data-step="4" onclick="tryGoToStep(4)">
                    <div class="ss-icon"><i class="fa-solid fa-file-signature"></i></div>
                    <div class="ss-text"><strong><?php echo t('preventifliste.step4_title'); ?></strong><span><?php echo t('preventifliste.step4_sub'); ?></span></div>
                </div>

                <div class="wizard-sidebar-progress-wrap">
                    <div class="wizard-mini-progress-bar"><div class="wizard-mini-progress-fill" id="wizardProgressFill"></div></div>
                    <div class="wizard-mini-progress-label" id="wizardProgressLabel"><?php echo str_replace(['{n}','{total}'], ['1','4'], t('preventifliste.etape_progress')); ?></div>
                </div>
            </div>

            <div class="wizard-main">
                <div class="wizard-content">

                    <!-- ÉTAPE 1 : QUOI & QUAND -->
                    <div class="wizard-panel" data-panel="1">
                        <div class="wizard-panel-title"><i class="fa-solid fa-clipboard-list" style="color:var(--accent);"></i> <?php echo t('preventifliste.p1_title'); ?></div>
                        <div class="wizard-panel-desc"><?php echo t('preventifliste.p1_desc'); ?></div>

                        <div class="wizard-step-help">
                            <img src="img/aide/preventif_wizard_etape1.png" alt="<?php echo htmlspecialchars(t('preventifliste.help_alt_1')); ?>" onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                            <div class="wizard-step-help-icon-fallback" style="display:none;"><i class="fa-solid fa-clipboard-list"></i></div>
                            <div class="wizard-step-help-text"><?php echo t('preventifliste.help_text_1'); ?></div>
                        </div>

                        <div class="form-group" style="margin-bottom:16px;">
                            <label><?php echo t('preventifliste.label_type_operation'); ?> <span class="req">*</span>
                                <span class="help-tip" tabindex="0">?<span class="help-bubble"><?php echo t('preventifliste.help_type_operation'); ?></span></span>
                            </label>
                            <select id="f-type-op">
                                <option value=""><?php echo t('preventifliste.opt_selectionner_type'); ?></option>
                                <option value="Préventif électrique"><?php echo t('preventifliste.type_preventif_electrique'); ?></option>
                                <option value="Mécanique"><?php echo t('preventifliste.type_mecanique'); ?></option>
                                <option value="Hydraulique"><?php echo t('preventifliste.type_hydraulique'); ?></option>
                                <option value="Pneumatique"><?php echo t('preventifliste.type_pneumatique'); ?></option>
                                <option value="Maintenance préventive périodique"><?php echo t('preventifliste.type_maintenance_preventive_periodique'); ?></option>
                                <option value="Maintenance corrective"><?php echo t('preventifliste.type_maintenance_corrective'); ?></option>
                                <option value="Remplacement de pièces"><?php echo t('preventifliste.type_remplacement_pieces'); ?></option>
                                <option value="Amélioration process"><?php echo t('preventifliste.type_amelioration_process'); ?></option>
                                <option value="Demande de pièces urgente"><?php echo t('preventifliste.type_demande_pieces_urgente'); ?></option>
                                <option value="Inspection réglementaire"><?php echo t('preventifliste.type_inspection_reglementaire'); ?></option>
                                <option value="Autre"><?php echo t('preventifliste.type_autre'); ?></option>
                            </select>
                        </div>

                        <div class="form-group" style="margin-bottom:16px;">
                            <label><?php echo t('preventifliste.label_categorie_preventif'); ?> <span class="req">*</span>
                                <span class="help-tip" tabindex="0">?<span class="help-bubble"><?php echo t('preventifliste.help_categorie_preventif'); ?></span></span>
                            </label>
                            <?php $premiereCategorie = array_key_first($CATEGORIES_LISTE) ?: 'process'; ?>
                            <input type="hidden" id="f-categorie" value="<?php echo htmlspecialchars($premiereCategorie); ?>">
                            <div class="choice-grid cols-<?php echo max(2, min(count($CATEGORIES_LISTE), 4)); ?>">
                                <?php foreach ($CATEGORIES_LISTE as $cle => $meta): ?>
                                <div class="choice-card card-categorie <?php echo $cle === $premiereCategorie ? 'selected' : ''; ?>" data-value="<?php echo htmlspecialchars($cle); ?>" onclick="chooseCard(this,'f-categorie','.card-categorie')"><i class="fa-solid <?php echo htmlspecialchars($meta['icon']); ?>"></i><span><?php echo htmlspecialchars($meta['label']); ?></span></div>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <div class="form-group" style="margin-bottom:16px;">
                            <label><?php echo t('preventifliste.label_priorite_req'); ?> <span class="req">*</span></label>
                            <select id="f-prio" style="display:none;">
                                <option value="Normal">Normal</option>
                                <option value="Urgent">Urgent</option>
                            </select>
                            <div class="choice-grid cols-2">
                                <div class="choice-card card-prio selected" data-value="Normal" onclick="chooseCard(this,'f-prio','.card-prio')"><i class="fa-solid fa-circle-check"></i><span><?php echo t('maint.lib_normal'); ?></span></div>
                                <div class="choice-card card-prio c-urgent" data-value="Urgent" onclick="chooseCard(this,'f-prio','.card-prio')"><i class="fa-solid fa-fire"></i><span><?php echo t('maint.lib_urgent'); ?></span></div>
                            </div>
                        </div>

                        <div class="form-group" style="margin-bottom:16px;">
                            <label><?php echo t('preventifliste.label_mode_planif'); ?> <span class="req">*</span>
                                <span class="help-tip" tabindex="0">?<span class="help-bubble"><?php echo t('preventifliste.help_mode_planif'); ?></span></span>
                            </label>
                            <input type="hidden" id="f-mode-planif" value="frequence">
                            <div class="choice-grid cols-2">
                                <div class="choice-card card-mode selected" data-value="frequence" onclick="chooseCard(this,'f-mode-planif','.card-mode'); toggleModePlanif();"><i class="fa-solid fa-rotate"></i><span><?php echo t('preventifliste.card_frequence_jours'); ?></span></div>
                                <div class="choice-card card-mode" data-value="jours_semaine" onclick="chooseCard(this,'f-mode-planif','.card-mode'); toggleModePlanif();"><i class="fa-solid fa-calendar-week"></i><span><?php echo t('preventifliste.card_jours_semaine'); ?></span></div>
                            </div>
                        </div>

                        <div class="form-group" style="margin-bottom:16px;" id="freqModeBlock">
                            <label><?php echo t('preventifliste.label_frequence_cycle'); ?> <span class="req">*</span>
                                <span class="help-tip" tabindex="0">?<span class="help-bubble"><?php echo t('preventifliste.help_frequence_cycle'); ?></span></span>
                            </label>
                            <select id="f-freq" style="display:none;" onchange="suggestEcheance()">
                                <option value="test_1m"><?php echo t('preventifliste.opt_test_1m'); ?></option>
                                <option value="1"><?php echo t('preventifliste.opt_1j_quotidien'); ?></option>
                                <option value="7"><?php echo t('preventifliste.opt_7j_hebdo'); ?></option>
                                <option value="15"><?php echo t('preventifliste.opt_15j_quinzaine'); ?></option>
                                <option value="30"><?php echo t('preventifliste.opt_30j_mensuel'); ?></option>
                                <option value="90"><?php echo t('preventifliste.opt_90j_trimestriel'); ?></option>
                                <option value="180"><?php echo t('preventifliste.opt_180j_semestriel'); ?></option>
                                <option value="365"><?php echo t('preventifliste.opt_365j_annuel'); ?></option>
                            </select>
                            <div class="choice-grid cols-4">
                                <div class="choice-card card-freq selected" data-value="1" onclick="chooseCard(this,'f-freq','.card-freq')"><i class="fa-solid fa-sun"></i><span><?php echo t('preventifliste.card_quotidien'); ?></span></div>
                                <div class="choice-card card-freq" data-value="7" onclick="chooseCard(this,'f-freq','.card-freq')"><i class="fa-solid fa-calendar-week"></i><span><?php echo t('preventifliste.card_hebdo'); ?></span></div>
                                <div class="choice-card card-freq" data-value="15" onclick="chooseCard(this,'f-freq','.card-freq')"><i class="fa-solid fa-calendar-days"></i><span><?php echo t('preventifliste.card_15jours'); ?></span></div>
                                <div class="choice-card card-freq" data-value="30" onclick="chooseCard(this,'f-freq','.card-freq')"><i class="fa-solid fa-calendar-check"></i><span><?php echo t('preventifliste.card_mensuel'); ?></span></div>
                                <div class="choice-card card-freq" data-value="90" onclick="chooseCard(this,'f-freq','.card-freq')"><i class="fa-solid fa-rotate"></i><span><?php echo t('preventifliste.card_trimestriel'); ?></span></div>
                                <div class="choice-card card-freq" data-value="180" onclick="chooseCard(this,'f-freq','.card-freq')"><i class="fa-solid fa-arrows-rotate"></i><span><?php echo t('preventifliste.card_semestriel'); ?></span></div>
                                <div class="choice-card card-freq" data-value="365" onclick="chooseCard(this,'f-freq','.card-freq')"><i class="fa-solid fa-calendar-star"></i><span><?php echo t('preventifliste.card_annuel'); ?></span></div>
                                <div class="choice-card card-freq c-warning" data-value="test_1m" onclick="chooseCard(this,'f-freq','.card-freq')"><i class="fa-solid fa-bug"></i><span><?php echo t('preventifliste.card_test_1min'); ?></span></div>
                            </div>
                        </div>

                        <div class="form-group" style="margin-bottom:16px; display:none;" id="joursModeBlock">
                            <label><?php echo t('preventifliste.label_jours_semaine'); ?> <span class="req">*</span>
                                <span class="help-tip" tabindex="0">?<span class="help-bubble"><?php echo t('preventifliste.help_jours_semaine'); ?></span></span>
                            </label>
                            <div style="display:flex; gap:8px; flex-wrap:wrap;">
                                <label class="jour-chip"><input type="checkbox" class="f-jour" value="lun"> <?php echo t('preventifliste.jour_lun'); ?></label>
                                <label class="jour-chip"><input type="checkbox" class="f-jour" value="mar"> <?php echo t('preventifliste.jour_mar'); ?></label>
                                <label class="jour-chip"><input type="checkbox" class="f-jour" value="mer"> <?php echo t('preventifliste.jour_mer'); ?></label>
                                <label class="jour-chip"><input type="checkbox" class="f-jour" value="jeu"> <?php echo t('preventifliste.jour_jeu'); ?></label>
                                <label class="jour-chip"><input type="checkbox" class="f-jour" value="ven"> <?php echo t('preventifliste.jour_ven'); ?></label>
                            </div>
                        </div>

                        <div class="form-grid-2">
                            <div class="form-group">
                                <label><?php echo t('preventifliste.label_duree_estimee'); ?> <span class="req">*</span></label>
                                <input type="number" id="f-hours" step="0.25" value="0.25">
                            </div>
                            <div class="form-group">
                                <label><i class="fa-regular fa-calendar-days"></i> <?php echo t('preventifliste.label_date_echeance'); ?>
                                    <span class="help-tip" tabindex="0">?<span class="help-bubble"><?php echo t('preventifliste.help_date_echeance'); ?></span></span>
                                </label>
                                <input type="date" id="f-echeance">
                                <div class="form-hint" id="echeanceHint"><?php echo t('preventifliste.hint_echeance_auto'); ?></div>
                            </div>
                            <div class="form-group span-2">
                                <label><i class="fa-regular fa-bell"></i> <?php echo t('preventifliste.label_alerte'); ?>
                                    <span class="help-tip" tabindex="0">?<span class="help-bubble"><?php echo t('preventifliste.help_alerte'); ?></span></span>
                                </label>
                                <div style="display:flex; gap:8px;">
                                    <input type="number" id="f-alerte-val" placeholder="<?php echo htmlspecialchars(t('preventifliste.placeholder_ex2')); ?>" style="width: 30%;">
                                    <select id="f-alerte-unit" style="width: 70%;">
                                        <option value="jours"><?php echo t('preventifliste.opt_jours_avant'); ?></option>
                                        <option value="mois"><?php echo t('preventifliste.opt_mois_avant'); ?></option>
                                        <option value="ans"><?php echo t('preventifliste.opt_ans_avant'); ?></option>
                                    </select>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- ÉTAPE 2 : LOCALISATION -->
                    <div class="wizard-panel" data-panel="2">
                        <div class="wizard-panel-title"><i class="fa-solid fa-location-dot" style="color:var(--accent);"></i> <?php echo t('preventifliste.p2_title'); ?></div>
                        <div class="wizard-panel-desc"><?php echo t('preventifliste.p2_desc'); ?></div>

                        <div class="wizard-step-help">
                            <img src="img/aide/preventif_wizard_etape2.png" alt="<?php echo htmlspecialchars(t('preventifliste.help_alt_2')); ?>" onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                            <div class="wizard-step-help-icon-fallback" style="display:none;"><i class="fa-solid fa-location-dot"></i></div>
                            <div class="wizard-step-help-text"><?php echo t('preventifliste.help_text_2'); ?></div>
                        </div>

                        <div class="form-grid-2">
                            <div class="form-group span-2 loc-search-field" id="loc-search-field">
                                <label><?php echo t('preventifliste.label_recherche_rapide'); ?> <span class="help-tip" tabindex="0">?<span class="help-bubble"><?php echo t('preventifliste.help_recherche_rapide'); ?></span></span></label>
                                <div class="loc-search-wrap">
                                    <i class="fa-solid fa-magnifying-glass loc-search-icon"></i>
                                    <input type="text" id="loc-search-input" placeholder="<?php echo htmlspecialchars(t('preventifliste.placeholder_recherche_machine')); ?>" autocomplete="off" oninput="rechercheMachinePrev(this.value)" onfocus="rechercheMachinePrev(this.value)">
                                    <div id="loc-search-results" class="loc-search-results"></div>
                                </div>
                            </div>

                            <div id="loc-breadcrumb" class="loc-breadcrumb"></div>
                            <div id="loc-tiles" class="loc-tiles"></div>

                            <!-- Champs réels du formulaire : pilotés par le picker visuel ci-dessus -->
                            <div style="display:none;">
                                <select id="f-usine" onchange="updateCascade('secteur'); updateLocationPreview(); renderLocPicker();"><option value="">Sélectionner...</option></select>
                                <select id="f-secteur" onchange="updateCascade('ligne'); updateLocationPreview(); renderLocPicker();" disabled><option value="">-</option></select>
                                <select id="f-ligne" onchange="updateCascade('zone'); updateLocationPreview(); renderLocPicker();" disabled><option value="">-</option></select>
                                <select id="f-zone" onchange="updateCascade('machine'); updateLocationPreview(); renderLocPicker();" disabled><option value="">-</option></select>
                                <select id="f-equip" onchange="updateLocationPreview(); renderLocPicker();" disabled><option value="">-</option></select>
                            </div>
                        </div>

                        <div class="location-preview" id="locationPreview">
                            <i class="fa-solid fa-signs-post"></i> <span class="lp-empty"><?php echo t('preventifliste.lp_empty'); ?></span>
                        </div>
                    </div>

                    <!-- ÉTAPE 3 : IMPACT & ÉQUIPE -->
                    <div class="wizard-panel" data-panel="3">
                        <div class="wizard-panel-title"><i class="fa-solid fa-industry" style="color:var(--accent);"></i> <?php echo t('preventifliste.p3_title'); ?></div>
                        <div class="wizard-panel-desc"><?php echo t('preventifliste.p3_desc'); ?></div>

                        <div class="wizard-step-help">
                            <img src="img/aide/preventif_wizard_etape3.png" alt="<?php echo htmlspecialchars(t('preventifliste.help_alt_3')); ?>" onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                            <div class="wizard-step-help-icon-fallback" style="display:none;"><i class="fa-solid fa-user-gear"></i></div>
                            <div class="wizard-step-help-text"><?php echo t('preventifliste.help_text_3'); ?></div>
                        </div>

                        <div class="form-group" style="margin-bottom:16px;">
                            <label><?php echo t('preventifliste.label_impact_production'); ?>
                                <span class="help-tip" tabindex="0">?<span class="help-bubble"><?php echo t('preventifliste.help_impact_production'); ?></span></span>
                            </label>
                            <select id="f-impact" style="display:none;">
                                <option value="Aucun impact"><?php echo t('preventifliste.impact_aucun'); ?></option>
                                <option value="Arrêt total"><?php echo t('preventifliste.impact_arret_total'); ?></option>
                                <option value="Réduction cadence 50 %"><?php echo t('preventifliste.impact_reduction_50'); ?></option>
                                <option value="Réduction cadence 25 %"><?php echo t('preventifliste.impact_reduction_25'); ?></option>
                                <option value="Impact qualité"><?php echo t('preventifliste.impact_qualite'); ?></option>
                                <option value="Risque sécurité"><?php echo t('preventifliste.impact_securite'); ?></option>
                            </select>
                            <div class="choice-grid cols-3">
                                <div class="choice-card card-impact selected" data-value="Aucun impact" onclick="chooseCard(this,'f-impact','.card-impact')"><i class="fa-solid fa-circle-check"></i><span><?php echo t('preventifliste.impact_aucun'); ?></span></div>
                                <div class="choice-card card-impact c-danger" data-value="Arrêt total" onclick="chooseCard(this,'f-impact','.card-impact')"><i class="fa-solid fa-hand"></i><span><?php echo t('preventifliste.impact_arret_total'); ?></span></div>
                                <div class="choice-card card-impact c-warning" data-value="Réduction cadence 50 %" onclick="chooseCard(this,'f-impact','.card-impact')"><i class="fa-solid fa-gauge-high"></i><span><?php echo t('preventifliste.impact_cadence_moins50'); ?></span></div>
                                <div class="choice-card card-impact c-warning" data-value="Réduction cadence 25 %" onclick="chooseCard(this,'f-impact','.card-impact')"><i class="fa-solid fa-gauge"></i><span><?php echo t('preventifliste.impact_cadence_moins25'); ?></span></div>
                                <div class="choice-card card-impact c-warning" data-value="Impact qualité" onclick="chooseCard(this,'f-impact','.card-impact')"><i class="fa-solid fa-award"></i><span><?php echo t('preventifliste.impact_qualite'); ?></span></div>
                                <div class="choice-card card-impact c-danger" data-value="Risque sécurité" onclick="chooseCard(this,'f-impact','.card-impact')"><i class="fa-solid fa-triangle-exclamation"></i><span><?php echo t('preventifliste.impact_securite'); ?></span></div>
                            </div>
                        </div>

                        <div class="form-grid-2">
                            <div class="form-group">
                                <label><?php echo t('preventifliste.label_duree_arret'); ?></label>
                                <input type="number" id="f-arret-h" step="0.5" placeholder="0.0">
                            </div>
                            <div class="form-group"></div>
                            <div class="form-group">
                                <label><i class="fa-solid fa-user-pen"></i> <?php echo t('preventifliste.label_declarant'); ?></label>
                                <select id="f-declarant"><option value=""><?php echo t('maint.opt_selectionner'); ?></option></select>
                            </div>
                            <div class="form-group">
                                <label><i class="fa-solid fa-user-gear"></i> <?php echo t('preventifliste.label_technicien_intervenant'); ?></label>
                                <select id="f-intervenant"><option value=""><?php echo t('maint.opt_selectionner'); ?></option></select>
                            </div>
                            <div class="form-group span-2" style="flex-direction:row; align-items:center; gap:8px; margin-top:2px;">
                                <input type="checkbox" id="f-auto-matin" style="width:16px; height:16px; cursor:pointer; margin:0;" onchange="toggleAutoMatin()">
                                <label for="f-auto-matin" style="font-weight:600; font-size:0.72rem; color:#475569; cursor:pointer; display:flex; align-items:center; gap:5px;">
                                    <i class="fa-solid fa-sun" style="color:var(--gelpam-orange);"></i> <?php echo t('preventifliste.label_auto_matin'); ?>
                                </label>
                            </div>
                        </div>
                    </div>

                    <!-- ÉTAPE 4 : DÉTAILS & VALIDATION -->
                    <div class="wizard-panel" data-panel="4">
                        <div class="wizard-panel-title"><i class="fa-solid fa-file-signature" style="color:var(--accent);"></i> <?php echo t('preventifliste.p4_title'); ?></div>
                        <div class="wizard-panel-desc"><?php echo t('preventifliste.p4_desc'); ?></div>

                        <div class="wizard-step-help">
                            <img src="img/aide/preventif_wizard_etape4.png" alt="<?php echo htmlspecialchars(t('preventifliste.help_alt_4')); ?>" onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                            <div class="wizard-step-help-icon-fallback" style="display:none;"><i class="fa-solid fa-file-signature"></i></div>
                            <div class="wizard-step-help-text"><?php echo t('preventifliste.help_text_4'); ?></div>
                        </div>

                        <div class="form-group" style="margin-bottom: 14px;">
                            <label><?php echo t('preventifliste.label_description_precise'); ?> <span class="req">*</span>
                                <span class="help-tip" tabindex="0">?<span class="help-bubble"><?php echo t('preventifliste.help_description_precise'); ?></span></span>
                            </label>
                            <input type="text" id="f-desc" placeholder="<?php echo htmlspecialchars(t('preventifliste.placeholder_description')); ?>">
                        </div>

                        <div class="form-grid-2">
                            <div class="form-group">
                                <label><?php echo t('preventifliste.label_cause'); ?></label>
                                <textarea id="f-cause" placeholder="<?php echo htmlspecialchars(t('preventifliste.placeholder_cause')); ?>"></textarea>
                            </div>
                            <div class="form-group">
                                <label><?php echo t('preventifliste.label_pieces'); ?></label>
                                <textarea id="f-pieces" placeholder="<?php echo htmlspecialchars(t('preventifliste.placeholder_pieces')); ?>"></textarea>
                            </div>
                            <div class="form-group span-2">
                                <label><?php echo t('preventifliste.label_commentaires'); ?></label>
                                <textarea id="f-commentaires" placeholder="<?php echo htmlspecialchars(t('preventifliste.placeholder_commentaires')); ?>"></textarea>
                            </div>
                        </div>

                        <div class="review-card" id="reviewCard"></div>
                    </div>

                </div>

                <div class="wizard-nav">
                    <button class="btn-wizard btn-wizard-cancel" onclick="closeModal()"><?php echo t('preventifliste.btn_annuler'); ?></button>
                    <div style="display:flex; gap:10px;">
                        <button class="btn-wizard btn-wizard-prev" id="btnWizardPrev" onclick="prevStep()" style="display:none;"><i class="fa-solid fa-arrow-left"></i> <?php echo t('preventifliste.btn_precedent'); ?></button>
                        <button class="btn-wizard btn-wizard-next" id="btnWizardNext" onclick="nextStep()"><?php echo t('preventifliste.btn_suivant'); ?> <i class="fa-solid fa-arrow-right"></i></button>
                        <button class="btn-wizard btn-wizard-save" id="btnWizardSave" onclick="savePlan()" style="display:none;"><i class="fa-solid fa-floppy-disk"></i> <?php echo t('preventifliste.btn_enregistrer_regle'); ?></button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<div id="modalDetail" class="modal">
    <div class="modal-content-large modal-content-detail">
        <div class="detail-header">
            <div>
                <h2 class="detail-title" id="detailTitle"><i class="fa-solid fa-microchip" style="color:var(--accent);"></i> —</h2>
                <div class="detail-subtitle" id="detailSubtitle"></div>
                <div class="detail-badges-row" id="detailBadges"></div>
            </div>
            <button onclick="closeDetail()" style="background:none; border:none; font-size:2rem; cursor:pointer; color:#94a3b8;">&times;</button>
        </div>
        <div id="detailBody"></div>
        <div class="detail-block">
            <h4><i class="fa-solid fa-clock-rotate-left"></i> <?php echo t('preventifliste.historique_generations'); ?></h4>
            <div id="detailHistoryList"></div>
        </div>
        <div class="btn-row-detail">
            <button class="btn-action-modal btn-cancel" style="width: auto; padding: 8px 22px; margin: 0;" onclick="closeDetail()"><?php echo t('preventifliste.btn_fermer'); ?></button>
            <button class="btn-action-modal" style="width: auto; padding: 8px 22px; margin: 0; background:#dbeafe; color:#1d4ed8; display:flex; align-items:center; gap:8px;" onclick="genererDemandeDepuisDetail()"><i class="fa-solid fa-bolt"></i> <?php echo t('preventifliste.btn_generer_demande'); ?></button>
            <button class="btn-action-modal btn-save" style="width: auto; padding: 8px 26px; margin: 0; display:flex; align-items:center; gap:8px;" onclick="editFromDetail()"><i class="fa-solid fa-pen"></i> <?php echo t('preventifliste.btn_modifier_regle'); ?></button>
        </div>
    </div>
</div>

<div id="customAlert" style="display:none; position:fixed; z-index:99999; left:0; top:0; width:100%; height:100%; background:rgba(0,0,0,0.5); backdrop-filter: blur(3px);">
    <div style="background:white; width:350px; margin:15% auto; padding:20px; border-radius:12px; text-align:center; box-shadow: 0 10px 25px rgba(0,0,0,0.2); border-top: 5px solid #27ae60;">
        <i class="fa-solid fa-circle-check" style="font-size:3rem; color:#27ae60; margin-bottom:15px;"></i>
        <h3 id="alertTitle" style="margin:10px 0; color:var(--primary);"><?php echo t('preventifliste.succes_title'); ?></h3>
        <p id="alertMessage" style="color:#666; font-size:0.9rem; margin-bottom:20px;"><?php echo t('preventifliste.operation_reussie'); ?></p>
        <div style="display:flex; justify-content:center;">
            <button id="alertOk" style="padding:10px 30px; border:none; border-radius:6px; background:#27ae60; color:white; cursor:pointer; font-weight:bold;"><?php echo t('preventifliste.btn_ok'); ?></button>
        </div>
    </div>
</div>

<?php include 'composant_rapport.php'; ?>

<div id="modalHistorique" class="modal">
    <div class="modal-content-large">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:10px;">
            <h2 style="font-family:'Caveat', cursive; font-size:2rem; color:var(--primary); margin:0; display:flex; align-items:center; gap:10px;">
                <i class="fa-solid fa-clock-rotate-left" style="color:var(--accent);"></i> <?php echo t('preventifliste.historique_preventif_title'); ?>
            </h2>
            <button onclick="closeHistorique()" style="background:none; border:none; font-size:2rem; cursor:pointer; color:#94a3b8;">&times;</button>
        </div>
        <table>
            <thead>
                <tr>
                    <th><?php echo t('preventifliste.th_equipement'); ?></th>
                    <th><?php echo t('preventifliste.th_operation_simple'); ?></th>
                    <th><?php echo t('preventifliste.th_date'); ?></th>
                    <th><?php echo t('preventifliste.th_num_bi'); ?></th>
                    <th><?php echo t('preventifliste.th_statut'); ?></th>
                    <th><?php echo t('preventifliste.th_technicien'); ?></th>
                </tr>
            </thead>
            <tbody id="historiqueTableBody"></tbody>
        </table>
        <div class="empty-state" id="historiqueEmptyState" style="display:none;">
            <i class="fa-solid fa-inbox"></i>
            <div><?php echo t('preventifliste.empty_historique'); ?></div>
        </div>
    </div>
</div>

<div id="customConfirm" style="display:none; position:fixed; z-index:99999; left:0; top:0; width:100%; height:100%; background:rgba(0,0,0,0.5); backdrop-filter: blur(3px);">
    <div id="confirmBox" style="background:white; width:350px; margin:15% auto; padding:20px; border-radius:12px; text-align:center; box-shadow: 0 10px 25px rgba(0,0,0,0.2); border-top: 5px solid var(--danger);">
        <i id="confirmIcon" class="fa-solid fa-circle-exclamation" style="font-size:3rem; color:var(--danger); margin-bottom:15px;"></i>
        <h3 id="confirmTitle" style="margin:10px 0; color:var(--primary); font-family: 'Segoe UI', sans-serif;"><?php echo t('preventifliste.confirmation_title'); ?></h3>
        <p id="confirmMessage" style="color:#666; font-size:0.9rem; margin-bottom:20px;"><?php echo t('preventifliste.confirmation_msg_default'); ?></p>
        <div style="display:flex; justify-content:center; gap:10px;">
            <button id="confirmCancel" style="padding:10px 20px; border:none; border-radius:6px; background:#eee; color:#333; cursor:pointer; font-weight:bold; transition:0.2s;"><?php echo t('preventifliste.btn_annuler'); ?></button>
            <button id="confirmOk" style="padding:10px 20px; border:none; border-radius:6px; background:var(--danger); color:white; cursor:pointer; font-weight:bold; transition:0.2s;"><?php echo t('preventifliste.btn_supprimer_default'); ?></button>
        </div>
    </div>
</div>

<script>
    // Chargés à la demande par showDetailBI() (composant_rapport.php) au premier clic sur un bon.
    // IMPORTANT : var (pas let) — le fallback de showDetailBI fait `window.tasks = ...`,
    // ce qui ne réassigne PAS une variable déclarée en `let`.
    var tasks = [];
    var pointages = [];

    function aspirineAlert(titre, message) {
        return new Promise((resolve) => {
            const modal = document.getElementById('customAlert');
            document.getElementById('alertTitle').innerText = titre;
            document.getElementById('alertMessage').innerText = message;
            modal.style.display = 'block';
            document.getElementById('alertOk').onclick = () => {
                modal.style.display = 'none';
                resolve();
            };
        });
    }

    let plans = [];
    let currentFilter = 'tous';
    let wizardStep = 1;
    let wizardUnlocked = 1;
    const WIZARD_STEPS = 4;
    let detailPlan = null;
    // Rempli quand l'assistant est ouvert depuis une demande en attente du SAS de validation
    // (maintenance.php) : voir ouvrirDepuisDemande() et le hook dans savePlan().
    let demandeIdTransfert = null;

    // --- VARIABLES DYNAMIQUES PHP VERS JS ---
    const dbMachines = <?php echo $json_machines; ?>;
    const team = <?php echo $json_team; ?>;
    const servicesListe = <?php echo $json_services; ?>;
    const entreprisesExt = <?php echo $json_entreprises_ext; ?>;
    const urlCat = <?php echo json_encode($cat); ?>;
    const currentUser = <?php echo json_encode($_SESSION['user']); ?>;
    const CATEGORIES = <?php echo json_encode($CATEGORIES_LISTE); ?>;
    const JS_LOCALE_PREV = <?php echo json_encode(langue_actuelle() === 'en' ? 'en-US' : (langue_actuelle() === 'nl' ? 'nl-NL' : 'fr-FR')); ?>;
    const I18N_PREVLISTE = <?php echo json_encode([
        'opt_selectionner' => t('maint.opt_selectionner'),
        'aucune_option_niveau' => t('preventifliste.aucune_option_niveau'),
        'aucune_machine_trouvee' => t('preventifliste.aucune_machine_trouvee'),
        'st_non_planifie' => t('preventifliste.st_non_planifie'),
        'st_en_pause' => t('preventifliste.st_en_pause'),
        'st_en_retard_de' => t('preventifliste.st_en_retard_de'),
        'st_aujourdhui' => t('preventifliste.st_aujourdhui'),
        'st_dans_n_j' => t('preventifliste.st_dans_n_j'),
        'categorie_defaut' => t('preventifliste.categorie_defaut'),
        'technicien_matin_auto' => t('preventifliste.technicien_matin_auto'),
        'tooltip_technicien_matin' => t('preventifliste.tooltip_technicien_matin'),
        'non_assigne' => t('preventifliste.non_assigne'),
        'tooltip_cliquer_detail' => t('preventifliste.tooltip_cliquer_detail'),
        'tooltip_generer_maintenant' => t('preventifliste.tooltip_generer_maintenant'),
        'th_usine' => t('preventifliste.th_usine'),
        'th_equipement' => t('preventifliste.th_equipement'),
        'th_intervenant' => t('preventifliste.th_intervenant'),
        'th_date' => t('preventifliste.th_date'),
        'entreprise_ext_fallback' => t('rapport.entreprise_ext_fallback'),
        'tooltip_activer' => t('preventifliste.tooltip_activer'),
        'tooltip_pause_hiver' => t('preventifliste.tooltip_pause_hiver'),
        'tooltip_voir_bi' => t('preventifliste.tooltip_voir_bi'),
        'tooltip_attente_validation' => t('preventifliste.tooltip_attente_validation'),
        'en_attente' => t('preventifliste.en_attente'),
        'aucune_bi' => t('preventifliste.aucune_bi'),
        'aucun_jour' => t('preventifliste.aucun_jour'),
        'lib_urgent' => t('maint.lib_urgent'),
        'lib_normal' => t('maint.lib_normal'),
        'lib_afaire' => t('maint.lib_afaire'),
        'lib_encours' => t('maint.lib_encours'),
        'lib_termine' => t('maint.lib_termine'),
        'lib_refuse' => t('maint.lib_refuse'),
        'lib_attente' => t('maint.lib_attente'),
        'suggestion_date' => t('preventifliste.suggestion_date'),
        'etape_progress' => t('preventifliste.etape_progress'),
        'lp_empty' => t('preventifliste.lp_empty'),
        'calculee_enregistrement' => t('preventifliste.calculee_enregistrement'),
        'aucun_jour_selectionne' => t('preventifliste.aucun_jour_selectionne'),
        'review_equipement' => t('preventifliste.review_equipement'),
        'review_localisation' => t('preventifliste.review_localisation'),
        'review_rythme' => t('preventifliste.review_rythme'),
        'review_echeance' => t('preventifliste.review_echeance'),
        'review_duree_estimee' => t('preventifliste.review_duree_estimee'),
        'review_intervenant' => t('preventifliste.review_intervenant'),
        'technicien_matin_auto_planning' => t('preventifliste.technicien_matin_auto_planning'),
        'freqlabel_test' => t('preventifliste.freqlabel_test'),
        'freqlabel_quotidien' => t('preventifliste.freqlabel_quotidien'),
        'freqlabel_hebdo' => t('preventifliste.freqlabel_hebdo'),
        'freqlabel_15j' => t('preventifliste.freqlabel_15j'),
        'freqlabel_mensuel' => t('preventifliste.freqlabel_mensuel'),
        'freqlabel_trimestriel' => t('preventifliste.freqlabel_trimestriel'),
        'freqlabel_semestriel' => t('preventifliste.freqlabel_semestriel'),
        'freqlabel_annuel' => t('preventifliste.freqlabel_annuel'),
        'freqlabel_n_jours' => t('preventifliste.freqlabel_n_jours'),
        'freq_test1m' => t('preventifliste.freq_test1m'),
        'freq_quotidien' => t('preventifliste.freq_quotidien'),
        'freq_hebdo' => t('preventifliste.freq_hebdo'),
        'freq_15jours' => t('preventifliste.freq_15jours'),
        'freq_mensuel' => t('preventifliste.freq_mensuel'),
        'freq_trimestriel' => t('preventifliste.freq_trimestriel'),
        'freq_semestriel' => t('preventifliste.freq_semestriel'),
        'freq_annuel' => t('preventifliste.freq_annuel'),
        'jour_lun' => t('preventifliste.jour_lun'), 'jour_mar' => t('preventifliste.jour_mar'), 'jour_mer' => t('preventifliste.jour_mer'),
        'jour_jeu' => t('preventifliste.jour_jeu'), 'jour_ven' => t('preventifliste.jour_ven'),
        'unite_jours' => t('preventifliste.unite_jours'),
        'unite_mois' => t('preventifliste.unite_mois'),
        'unite_ans' => t('preventifliste.unite_ans'),
        'alerte_non_definie' => t('preventifliste.alerte_non_definie'),
        'alerte_avant_echeance' => t('preventifliste.alerte_avant_echeance'),
        'detail_equipement_fallback' => t('preventifliste.detail_equipement_fallback'),
        'detail_aucune_description' => t('preventifliste.detail_aucune_description'),
        'detail_section_planification' => t('preventifliste.detail_section_planification'),
        'detail_section_impact' => t('preventifliste.detail_section_impact'),
        'detail_dernier_num_bi' => t('preventifliste.detail_dernier_num_bi'),
        'detail_section_commentaires' => t('preventifliste.detail_section_commentaires'),
        'label_impact_production' => t('preventifliste.label_impact_production'),
        'impact_aucun' => t('preventifliste.impact_aucun'),
        'detail_non_planifiee' => t('preventifliste.detail_non_planifiee'),
        'detail_jamais_generee' => t('preventifliste.detail_jamais_generee'),
        'detail_attente_validation' => t('preventifliste.detail_attente_validation'),
        'detail_loc_non_renseignee' => t('preventifliste.detail_loc_non_renseignee'),
        'detail_echeance' => t('preventifliste.detail_echeance'),
        'detail_alerte' => t('preventifliste.detail_alerte'),
        'detail_duree_estimee' => t('preventifliste.detail_duree_estimee'),
        'detail_priorite' => t('preventifliste.detail_priorite'),
        'detail_impact' => t('preventifliste.detail_impact'),
        'detail_duree_arret' => t('preventifliste.detail_duree_arret'),
        'detail_non_renseignee' => t('preventifliste.detail_non_renseignee'),
        'detail_section_equipe' => t('preventifliste.detail_section_equipe'),
        'detail_declarant' => t('preventifliste.detail_declarant'),
        'detail_section_tracabilite' => t('preventifliste.detail_section_tracabilite'),
        'detail_derniere_generation' => t('preventifliste.detail_derniere_generation'),
        'detail_statut_regle' => t('preventifliste.detail_statut_regle'),
        'detail_en_pause' => t('preventifliste.detail_en_pause'),
        'detail_active' => t('preventifliste.detail_active'),
        'detail_section_description' => t('preventifliste.detail_section_description'),
        'detail_section_cause' => t('preventifliste.detail_section_cause'),
        'detail_section_pieces' => t('preventifliste.detail_section_pieces'),
        'detail_aucune_generation' => t('preventifliste.detail_aucune_generation'),
        'wizard_title_new' => t('preventifliste.wizard_title_new'),
        'hors_liste_suffix' => t('preventifliste.hors_liste_suffix'),
        'hint_echeance_auto' => t('preventifliste.hint_echeance_auto'),
        'wizard_title_edit' => t('preventifliste.wizard_title_edit'),
        'wizard_title_depuis_demande' => t('preventifliste.wizard_title_depuis_demande'),
        'err_champs_obligatoires' => t('preventifliste.err_champs_obligatoires'),
        'err_equip_desc_obligatoires' => t('preventifliste.err_equip_desc_obligatoires'),
        'transfere_title' => t('preventifliste.transfere_title'),
        'transfere_msg' => t('preventifliste.transfere_msg'),
        'confirm_generer_titre' => t('preventifliste.confirm_generer_titre'),
        'confirm_generer_msg' => t('preventifliste.confirm_generer_msg'),
        'btn_generer' => t('preventifliste.btn_generer'),
        'demande_creee_avec_tech' => t('preventifliste.demande_creee_avec_tech'),
        'demande_creee_sans_tech_matin' => t('preventifliste.demande_creee_sans_tech_matin'),
        'demande_creee_sans_tech' => t('preventifliste.demande_creee_sans_tech'),
        'demande_generee_title' => t('preventifliste.demande_generee_title'),
        'err_title' => t('preventifliste.err_title'),
        'err_generation_echoue' => t('preventifliste.err_generation_echoue'),
        'confirm_suppr_regle_titre' => t('preventifliste.confirm_suppr_regle_titre'),
        'confirm_suppr_regle_msg' => t('preventifliste.confirm_suppr_regle_msg'),
        'btn_supprimer_default' => t('preventifliste.btn_supprimer_default'),
        'ckl_st_a_faire' => t('preventifliste.ckl_st_a_faire'),
        'ckl_st_en_cours' => t('preventifliste.ckl_st_en_cours'),
        'ckl_st_termine' => t('preventifliste.ckl_st_termine'),
        'optgroup_equipe_maintenance' => t('preventifliste.optgroup_equipe_maintenance'),
        'opt_tous_intervenants' => t('preventifliste.opt_tous_intervenants'),
        'opt_toutes_usines' => t('preventifliste.opt_toutes_usines'),
        'optgroup_services' => t('preventifliste.optgroup_services'),
        'aucune_zone' => t('preventifliste.aucune_zone'),
        'tooltip_enregistrer_nom' => t('preventifliste.tooltip_enregistrer_nom'),
        'tooltip_supprimer' => t('preventifliste.tooltip_supprimer'),
        'tooltip_modifier' => t('preventifliste.tooltip_modifier'),
        'btn_enregistrer' => t('preventifliste.btn_enregistrer'),
        'btn_ajouter' => t('preventifliste.btn_ajouter'),
        'confirm_suppr_zone_titre' => t('preventifliste.confirm_suppr_zone_titre'),
        'confirm_suppr_zone_msg' => t('preventifliste.confirm_suppr_zone_msg'),
        'fait_le' => t('preventifliste.fait_le'),
        'par' => t('preventifliste.par'),
        'signale_par' => t('preventifliste.signale_par'),
        'tooltip_selection_report_ckl' => t('preventifliste.tooltip_selection_report_ckl'),
        'confirm_suppr_tache_titre' => t('preventifliste.confirm_suppr_tache_titre'),
        'confirm_suppr_tache_msg' => t('preventifliste.confirm_suppr_tache_msg'),
        'ckl_modal_modifier' => t('preventifliste.ckl_modal_modifier'),
        'ckl_modal_ajouter' => t('preventifliste.ckl_modal_ajouter'),
        'err_desc_tache_obligatoire' => t('preventifliste.err_desc_tache_obligatoire'),
        'aucune_tache_selectionnee_title' => t('preventifliste.aucune_tache_selectionnee_title'),
        'aucune_tache_selectionnee_msg' => t('preventifliste.aucune_tache_selectionnee_msg'),
        'err_indiquer_saison' => t('preventifliste.err_indiquer_saison'),
        'err_saison_deja_affichee' => t('preventifliste.err_saison_deja_affichee'),
    ]); ?>;

    function openNav(e) { e.stopPropagation(); document.getElementById("mySidebar").style.width = "280px"; }
    function closeNav() { document.getElementById("mySidebar").style.width = "0"; }
    function checkCloseSidebar(event) { if (document.getElementById("mySidebar").style.width === "280px" && !document.getElementById("mySidebar").contains(event.target)) closeNav(); }

    // --- INITIALISATION DES LISTES DYNAMIQUES ---
    function initDynamicSelects() {
        // Remplir Techniciens et Déclarants
        const teamOptions = `<option value="">${I18N_PREVLISTE.opt_selectionner}</option>` + team.map(t => `<option value="${t}">${t}</option>`).join('');
        document.getElementById('f-intervenant').innerHTML = teamOptions;
        document.getElementById('f-declarant').innerHTML = teamOptions;

        // Remplir la première liste (Usine)
        const usines = [...new Set(dbMachines.map(m => m.usine).filter(Boolean))].sort();
        document.getElementById('f-usine').innerHTML = '<option value="">Sélectionner...</option>' + usines.map(u => `<option value="${u}">${u}</option>`).join('');

        document.getElementById('printDate').textContent = new Date().toLocaleDateString(JS_LOCALE_PREV, { year: 'numeric', month: 'long', day: 'numeric' });
        renderLocPicker();
    }

    // --- LE MOTEUR DE CASCADE POUR LA LOCALISATION ---
    function updateCascade(step) {
        const u = document.getElementById('f-usine').value;
        const s = document.getElementById('f-secteur');
        const l = document.getElementById('f-ligne');
        const z = document.getElementById('f-zone');
        const m = document.getElementById('f-equip');

        if (step === 'secteur') {
            const secteurs = [...new Set(dbMachines.filter(x => x.usine === u).map(x => x.secteur).filter(Boolean))].sort();
            s.innerHTML = '<option value="">Choisir...</option>' + secteurs.map(x => `<option value="${x}">${x}</option>`).join('');
            s.disabled = false; l.disabled = true; z.disabled = true; m.disabled = true;
            l.innerHTML = '<option value="">-</option>'; z.innerHTML = '<option value="">-</option>'; m.innerHTML = '<option value="">-</option>';
        }
        else if (step === 'ligne') {
            // Check si la DB contient des lignes, sinon on saute cette étape
            const lignes = [...new Set(dbMachines.filter(x => x.usine === u && x.secteur === s.value).map(x => x.ligne).filter(Boolean))].sort();
            if(lignes.length > 0) {
                l.innerHTML = '<option value="">Choisir...</option>' + lignes.map(x => `<option value="${x}">${x}</option>`).join('');
                l.disabled = false;
            } else {
                l.innerHTML = '<option value="N/A">Non applicable</option>';
                l.disabled = true;
                updateCascade('zone'); // Saute directement à la zone
            }
            z.disabled = true; m.disabled = true;
        }
        else if (step === 'zone') {
            const l_val = l.value === 'N/A' ? undefined : l.value;
            let filtered = dbMachines.filter(x => x.usine === u && x.secteur === s.value);
            if(l_val) filtered = filtered.filter(x => x.ligne === l_val);

            const zones = [...new Set(filtered.map(x => x.zone).filter(Boolean))].sort();
            z.innerHTML = '<option value="">Choisir...</option>' + zones.map(x => `<option value="${x}">${x}</option>`).join('');
            z.disabled = false; m.disabled = true;
        }
        else if (step === 'machine') {
            const l_val = l.value === 'N/A' ? undefined : l.value;
            let filtered = dbMachines.filter(x => x.usine === u && x.secteur === s.value && x.zone === z.value);
            if(l_val) filtered = filtered.filter(x => x.ligne === l_val);

            const machines = filtered.sort((a,b) => (a.nom_machine||"").localeCompare(b.nom_machine||""));
            m.innerHTML = '<option value="">Choisir...</option>' + machines.map(x => `<option value="${x.nom_machine}">${x.nom_machine}</option>`).join('');
            m.disabled = false;
        }
    }

    // ============================================================================
    // PICKER VISUEL DE LOCALISATION (recherche + navigation par tuiles)
    // Pilote les <select> f-usine/f-secteur/f-ligne/f-zone/f-equip existants
    // (gardés cachés en compatibilité avec updateCascade, la revue et l'édition).
    // Même pattern que le picker de création de bon d'intervention (maintenance.php).
    // ============================================================================

    function locHasLigneChoicesPrev() {
        const fLigne = document.getElementById('f-ligne');
        return [...fLigne.options].some(o => o.value && o.value !== 'N/A');
    }

    function locPickUsinePrev(val) { document.getElementById('f-usine').value = val; updateCascade('secteur'); updateLocationPreview(); renderLocPicker(); }
    function locPickSecteurPrev(val) { document.getElementById('f-secteur').value = val; updateCascade('ligne'); updateLocationPreview(); renderLocPicker(); }
    function locPickLignePrev(val) { document.getElementById('f-ligne').value = val; updateCascade('zone'); updateLocationPreview(); renderLocPicker(); }
    function locPickZonePrev(val) { document.getElementById('f-zone').value = val; updateCascade('machine'); updateLocationPreview(); renderLocPicker(); }
    function locPickEquipPrev(val) { document.getElementById('f-equip').value = val; updateLocationPreview(); renderLocPicker(); markFieldError('f-equip', false); }

    function locGoBackToPrev(level) {
        const fUsine = document.getElementById('f-usine');
        const fSecteur = document.getElementById('f-secteur');
        const fLigne = document.getElementById('f-ligne');
        const fZone = document.getElementById('f-zone');
        const fEquip = document.getElementById('f-equip');

        if (level === 'usine') {
            fUsine.value = '';
            fSecteur.innerHTML = '<option value="">-</option>'; fSecteur.disabled = true;
            fLigne.innerHTML = '<option value="">-</option>'; fLigne.disabled = true;
            fZone.innerHTML = '<option value="">-</option>'; fZone.disabled = true;
            fEquip.innerHTML = '<option value="">-</option>'; fEquip.disabled = true;
        } else if (level === 'secteur') {
            fSecteur.value = '';
            fLigne.innerHTML = '<option value="">-</option>'; fLigne.disabled = true;
            fZone.innerHTML = '<option value="">-</option>'; fZone.disabled = true;
            fEquip.innerHTML = '<option value="">-</option>'; fEquip.disabled = true;
            updateCascade('secteur');
        } else if (level === 'ligne') {
            fLigne.value = '';
            fZone.innerHTML = '<option value="">-</option>'; fZone.disabled = true;
            fEquip.innerHTML = '<option value="">-</option>'; fEquip.disabled = true;
            updateCascade('ligne');
        } else if (level === 'zone') {
            fZone.value = '';
            fEquip.innerHTML = '<option value="">-</option>'; fEquip.disabled = true;
            updateCascade('zone');
        } else if (level === 'equip') {
            fEquip.value = '';
        }
        updateLocationPreview();
        renderLocPicker();
    }

    function locTileIconPrev(level) {
        return { usine: 'fa-industry', secteur: 'fa-diagram-project', ligne: 'fa-arrows-left-right-to-line', zone: 'fa-map-pin', equip: 'fa-microchip' }[level];
    }

    function locOptionsForPrev(level) {
        const map = { usine: 'f-usine', secteur: 'f-secteur', ligne: 'f-ligne', zone: 'f-zone', equip: 'f-equip' };
        const sel = document.getElementById(map[level]);
        return [...sel.options].filter(o => o.value && o.value !== 'N/A');
    }

    function renderLocPicker() {
        const breadcrumbEl = document.getElementById('loc-breadcrumb');
        const tilesEl = document.getElementById('loc-tiles');
        if (!breadcrumbEl || !tilesEl) return;

        const fUsine = document.getElementById('f-usine');
        const fSecteur = document.getElementById('f-secteur');
        const fLigne = document.getElementById('f-ligne');
        const fZone = document.getElementById('f-zone');
        const fEquip = document.getElementById('f-equip');

        const usine = fUsine.value;
        const secteur = fSecteur.value;
        const ligneHasChoices = locHasLigneChoicesPrev();
        const ligne = (ligneHasChoices && fLigne.value && fLigne.value !== 'N/A') ? fLigne.value : '';
        const zone = fZone.value;
        const equip = fEquip.value;

        // --- Fil d'Ariane ---
        breadcrumbEl.innerHTML = '';
        const addCrumb = (label, current, level, isLast) => {
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'loc-crumb' + (current === '' ? ' is-current' : '');
            btn.textContent = current || label;
            btn.addEventListener('click', () => locGoBackToPrev(level));
            breadcrumbEl.appendChild(btn);
            if (!isLast) {
                const sep = document.createElement('i');
                sep.className = 'fa-solid fa-chevron-right loc-crumb-sep';
                breadcrumbEl.appendChild(sep);
            }
        };
        addCrumb('Usine', usine, 'usine', !usine);
        if (usine) addCrumb('Secteur', secteur, 'secteur', !secteur);
        if (secteur && ligneHasChoices) addCrumb('Ligne', ligne, 'ligne', !ligne);
        if (secteur && (!ligneHasChoices || ligne)) addCrumb('Zone', zone, 'zone', !(zone && equip));
        if (zone && equip) addCrumb('Machine', equip, 'equip', true);

        // --- Détermination du niveau à afficher en tuiles ---
        let level;
        if (!usine) level = 'usine';
        else if (!secteur) level = 'secteur';
        else if (ligneHasChoices && !ligne) level = 'ligne';
        else if (!zone) level = 'zone';
        else if (!equip) level = 'equip';
        else level = 'done';

        tilesEl.innerHTML = '';

        if (level === 'done') {
            const card = document.createElement('div');
            card.className = 'loc-done-card';
            const path = [usine, secteur, ligne, zone].filter(Boolean).join(' > ');
            card.innerHTML = `
                <div class="loc-done-info">
                    <div class="loc-done-icon"><i class="fa-solid fa-circle-check"></i></div>
                    <div>
                        <div class="loc-done-name"></div>
                        <div class="loc-done-path"></div>
                    </div>
                </div>
                <button type="button" class="loc-done-change"><i class="fa-solid fa-rotate"></i> Changer de machine</button>
            `;
            card.querySelector('.loc-done-name').textContent = equip;
            card.querySelector('.loc-done-path').textContent = path;
            card.querySelector('.loc-done-change').addEventListener('click', () => locGoBackToPrev('zone'));
            tilesEl.appendChild(card);
            return;
        }

        const options = locOptionsForPrev(level);
        if (options.length === 0) {
            const msg = document.createElement('div');
            msg.className = 'loc-empty-msg';
            msg.textContent = I18N_PREVLISTE.aucune_option_niveau;
            tilesEl.appendChild(msg);
            return;
        }

        const pickFns = { usine: locPickUsinePrev, secteur: locPickSecteurPrev, ligne: locPickLignePrev, zone: locPickZonePrev, equip: locPickEquipPrev };
        options.forEach(o => {
            const tile = document.createElement('button');
            tile.type = 'button';
            tile.className = 'loc-tile';
            const icon = document.createElement('div');
            icon.className = 'loc-tile-icon';
            icon.innerHTML = `<i class="fa-solid ${locTileIconPrev(level)}"></i>`;
            const label = document.createElement('div');
            label.className = 'loc-tile-label';
            label.textContent = o.textContent;
            tile.appendChild(icon);
            tile.appendChild(label);
            tile.addEventListener('click', () => pickFns[level](o.value));
            tilesEl.appendChild(tile);
        });
    }

    function rechercheMachinePrev(query) {
        const resultsEl = document.getElementById('loc-search-results');
        if (!resultsEl) return;
        query = (query || '').trim().toLowerCase();
        if (!query) { resultsEl.style.display = 'none'; resultsEl.innerHTML = ''; return; }

        const tokens = query.split(/\s+/).filter(Boolean);
        const matches = dbMachines.filter(m => {
            const hay = [m.usine, m.secteur, m.ligne, m.zone, m.nom_machine].filter(Boolean).join(' ').toLowerCase();
            return tokens.every(t => hay.includes(t));
        }).slice(0, 30);

        resultsEl.innerHTML = '';
        if (matches.length === 0) {
            const empty = document.createElement('div');
            empty.className = 'loc-search-empty';
            empty.textContent = I18N_PREVLISTE.aucune_machine_trouvee;
            resultsEl.appendChild(empty);
            resultsEl.style.display = 'block';
            return;
        }
        matches.forEach(m => {
            const item = document.createElement('div');
            item.className = 'loc-search-item';
            const nameEl = document.createElement('div');
            nameEl.className = 'loc-search-item-name';
            nameEl.textContent = m.nom_machine || '(sans nom)';
            const pathEl = document.createElement('div');
            pathEl.className = 'loc-search-item-path';
            pathEl.textContent = [m.usine, m.secteur, m.ligne, m.zone].filter(Boolean).join(' > ');
            item.appendChild(nameEl);
            item.appendChild(pathEl);
            item.addEventListener('click', () => selectMachineFromSearchPrev(m));
            resultsEl.appendChild(item);
        });
        resultsEl.style.display = 'block';
    }

    function selectMachineFromSearchPrev(m) {
        document.getElementById('f-usine').value = m.usine || '';
        updateCascade('secteur');
        document.getElementById('f-secteur').value = m.secteur || '';
        updateCascade('ligne');
        if (m.ligne) { document.getElementById('f-ligne').value = m.ligne; }
        updateCascade('zone');
        document.getElementById('f-zone').value = m.zone || '';
        updateCascade('machine');
        document.getElementById('f-equip').value = m.nom_machine || '';

        const searchInput = document.getElementById('loc-search-input');
        if (searchInput) searchInput.value = '';
        const resultsEl = document.getElementById('loc-search-results');
        if (resultsEl) { resultsEl.style.display = 'none'; resultsEl.innerHTML = ''; }

        updateLocationPreview();
        renderLocPicker();
        markFieldError('f-equip', false);
    }

    document.addEventListener('click', (e) => {
        const wrap = document.getElementById('loc-search-field');
        const resultsEl = document.getElementById('loc-search-results');
        if (wrap && resultsEl && !wrap.contains(e.target)) {
            resultsEl.style.display = 'none';
        }
    });

    // --- CALCUL DE CONFORMITÉ (statut d'échéance d'une règle) ---
    function freqToDays(freq) {
        if (freq === 'test_1m') return 1/1440; // ~1 minute, pour le mode test
        return parseInt(freq, 10) || 0;
    }

    function alertToDays(val, unit) {
        const v = parseFloat(val);
        if (!v) return 7; // valeur par défaut si aucune alerte définie
        if (unit === 'mois') return v * 30;
        if (unit === 'ans') return v * 365;
        return v;
    }

    function getPlanStatus(p) {
        if (p.status === 'pause') return { key: 'paused', label: I18N_PREVLISTE.st_en_pause, diffDays: null };
        if (!p.echeance) return { key: 'unknown', label: I18N_PREVLISTE.st_non_planifie, diffDays: null };

        const today = new Date(); today.setHours(0,0,0,0);
        const echeance = new Date(p.echeance + 'T00:00:00');
        const diffDays = Math.round((echeance - today) / 86400000);
        const alertWindow = alertToDays(p.alerte_val, p.alerte_unit);

        if (diffDays < 0) return { key: 'overdue', label: I18N_PREVLISTE.st_en_retard_de.replace('{n}', Math.abs(diffDays)), diffDays };
        if (diffDays <= alertWindow) return { key: 'soon', label: diffDays === 0 ? I18N_PREVLISTE.st_aujourdhui : I18N_PREVLISTE.st_dans_n_j.replace('{n}', diffDays), diffDays };
        return { key: 'ok', label: I18N_PREVLISTE.st_dans_n_j.replace('{n}', diffDays), diffDays };
    }

    const STATUS_META = {
        overdue: { cls: 'status-overdue', icon: 'fa-triangle-exclamation' },
        soon: { cls: 'status-soon', icon: 'fa-hourglass-half' },
        ok: { cls: 'status-ok', icon: 'fa-circle-check' },
        paused: { cls: 'status-paused', icon: 'fa-snowflake' },
        unknown: { cls: 'status-unknown', icon: 'fa-circle-question' }
    };

    function setFilter(f) {
        currentFilter = f;
        document.querySelectorAll('.chip').forEach(c => c.classList.toggle('active', c.dataset.filter === f));
        document.querySelectorAll('.kpi-filterable').forEach(c => c.classList.toggle('active', c.dataset.filter === f));
        renderTable();
    }

    let history = [];
    let historyByRule = {};

    async function loadHistory() {
        try {
            const res = await fetch('preventif_liste.php?action=history&t=' + Date.now());
            history = await res.json();
        } catch (e) { history = []; }
        historyByRule = {};
        history.forEach(h => {
            if (!historyByRule[h.rule_id]) historyByRule[h.rule_id] = [];
            historyByRule[h.rule_id].push(h);
        });
    }

    function lastOccurrence(ruleId) {
        const occs = historyByRule[ruleId];
        return occs && occs.length ? occs[0] : null; // déjà trié par date DESC côté serveur
    }

    async function loadPlans() {
        const res = await fetch('preventif_liste.php?action=list&t=' + Date.now());
        plans = await res.json();
        await loadHistory();
        renderTable();
    }

    function renderKPIs(withStatus) {
        const actifs = withStatus.filter(x => x.st.key !== 'paused');
        const overdue = withStatus.filter(x => x.st.key === 'overdue').length;
        const soon = withStatus.filter(x => x.st.key === 'soon').length;
        const ok = withStatus.filter(x => x.st.key === 'ok').length;
        const paused = withStatus.filter(x => x.st.key === 'paused').length;

        document.getElementById('kpiTotal').textContent = actifs.length;
        document.getElementById('kpiOverdue').textContent = overdue;
        document.getElementById('kpiSoon').textContent = soon;

        const compliance = actifs.length ? Math.round(((actifs.length - overdue) / actifs.length) * 100) : 100;
        document.getElementById('kpiCompliance').textContent = compliance + '%';

        document.getElementById('cnt-tous').textContent = withStatus.length;
        document.getElementById('cnt-overdue').textContent = overdue;
        document.getElementById('cnt-soon').textContent = soon;
        document.getElementById('cnt-ok').textContent = ok;
        document.getElementById('cnt-paused').textContent = paused;
    }

    function renderTable() {
        const search = document.getElementById('searchInput').value.toLowerCase();
        const tbody = document.getElementById('tableBody');
        const emptyState = document.getElementById('emptyState');
        tbody.innerHTML = "";

        // Filtre par catégorie (venant du hub, via l'URL ?cat=...) — appliqué avant tout le reste
        let scopedPlans = urlCat ? plans.filter(p => (p.categorie || 'process') === urlCat) : plans;

        // On calcule le statut de conformité de chaque règle une seule fois
        let withStatus = scopedPlans.map(p => ({ p, st: getPlanStatus(p) }));

        renderKPIs(withStatus);

        // Filtre par statut (chips + vignette "Taux de conformité", qui regroupe "à jour" + "bientôt",
        // exactement comme le calcul de compliance dans renderKPIs — tout ce qui n'est pas en retard)
        if (currentFilter === 'compliant') {
            withStatus = withStatus.filter(x => x.st.key !== 'overdue' && x.st.key !== 'paused');
        } else if (currentFilter !== 'tous') {
            withStatus = withStatus.filter(x => x.st.key === currentFilter);
        }

        // Filtre texte libre
        if (search) {
            withStatus = withStatus.filter(x => {
                const blob = [x.p.equip, x.p.desc, x.p.type_op, x.p.usine, x.p.zone, x.p.secteur, x.p.intervenant, x.p.impact, (CATEGORIES[x.p.categorie]||{}).label].join(' ').toLowerCase();
                return blob.includes(search);
            });
        }

        // Tri : en retard d'abord (plus ancien en premier), puis bientôt, puis à jour, puis pause/non planifié
        const order = { overdue: 0, soon: 1, ok: 2, unknown: 3, paused: 4 };
        withStatus.sort((a, b) => {
            const diff = order[a.st.key] - order[b.st.key];
            if (diff !== 0) return diff;
            if (a.st.diffDays !== null && b.st.diffDays !== null) return a.st.diffDays - b.st.diffDays;
            return 0;
        });

        emptyState.style.display = withStatus.length === 0 ? 'block' : 'none';

        // Vue "cartes" (tablette/téléphone, voir .prev-cards-grid) : générée en parallèle du tableau
        // avec les mêmes variables déjà calculées pour chaque ligne, pour ne pas dupliquer la logique.
        let cardsHtml = [];

        withStatus.forEach(({ p, st }) => {
            let tr = document.createElement('tr');

            let isPaused = p.status === 'pause';
            let rowOpacity = isPaused ? '0.6' : '1';
            let statusBtn = isPaused
                ? `<button class="btn-status" title="${I18N_PREVLISTE.tooltip_activer}" onclick="event.stopPropagation(); togglePause('${p.id}')" style="color:#fff; background:#94a3b8; border:none; border-radius:4px; padding:6px 10px; cursor:pointer; margin-right:5px;"><i class="fa-solid fa-play"></i></button>`
                : `<button class="btn-status" title="${I18N_PREVLISTE.tooltip_pause_hiver}" onclick="event.stopPropagation(); togglePause('${p.id}')" style="color:#fff; background:var(--accent); border:none; border-radius:4px; padding:6px 10px; cursor:pointer; margin-right:5px;"><i class="fa-solid fa-pause"></i></button>`;

            let fClass = 'f-' + p.freq;
            let icon = 'fa-calendar';
            let fText = I18N_PREVLISTE.freqlabel_n_jours.replace('{n}', p.freq);
            if(p.freq == 'test_1m') { fText = I18N_PREVLISTE.freq_test1m; icon = 'fa-bug'; fClass = 'f-7'; }
            if(p.freq == 1) { fText = I18N_PREVLISTE.freq_quotidien; icon = 'fa-sun'; fClass = 'f-7'; }
            if(p.freq == 7) { fText = I18N_PREVLISTE.freq_hebdo; icon = 'fa-calendar-week'; }
            if(p.freq == 15) { fText = I18N_PREVLISTE.freq_15jours; icon = 'fa-calendar-days'; }
            if(p.freq == 30) { fText = I18N_PREVLISTE.freq_mensuel; icon = 'fa-calendar-check'; }
            if(p.freq == 90) { fText = I18N_PREVLISTE.freq_trimestriel; icon = 'fa-rotate'; }
            if(p.freq == 180) { fText = I18N_PREVLISTE.freq_semestriel; icon = 'fa-arrows-rotate'; }
            if(p.freq == 365) { fText = I18N_PREVLISTE.freq_annuel; icon = 'fa-arrows-rotate'; }
            if(p.mode_planif === 'jours_semaine') {
                fClass = 'f-7'; icon = 'fa-calendar-week';
                fText = (p.jours || '').split(',').filter(Boolean).map(j => JOURS_LABELS[j] ? JOURS_LABELS[j].slice(0,3) : j).join(' ') || I18N_PREVLISTE.aucun_jour;
            }

            let typeBadge = p.type_op ? `<div style="font-size:0.7rem; color:#94a3b8; margin-top:3px;">${p.type_op}</div>` : '';
            const catMeta = CATEGORIES[p.categorie] || Object.values(CATEGORIES)[0] || { label: I18N_PREVLISTE.categorie_defaut, icon: 'fa-gear', couleur: '#3498db' };
            let catBadge = !urlCat ? `<div style="font-size:0.65rem; font-weight:700; margin-top:3px; color:${catMeta.couleur};"><i class="fa-solid ${catMeta.icon}"></i> ${catMeta.label}</div>` : '';

            const sm = STATUS_META[st.key];
            const echeanceDateTxt = p.echeance ? new Date(p.echeance + 'T00:00:00').toLocaleDateString('fr-FR') : '';

            const lastOcc = lastOccurrence(p.id);
            let derniereBiHtml = `<span style="color:#cbd5e1; font-size:0.8rem;">${I18N_PREVLISTE.aucune_bi}</span>`;
            if (lastOcc) {
                derniereBiHtml = lastOcc.num_bi
                    ? `<span class="badge-bi" onclick="event.stopPropagation(); openOccurrence('${lastOcc.id}')" title="${I18N_PREVLISTE.tooltip_voir_bi}"><i class="fa-solid fa-file-invoice"></i> ${lastOcc.num_bi}</span>`
                    : `<span class="badge-bi badge-bi-pending" onclick="event.stopPropagation(); openOccurrence('${lastOcc.id}')" title="${I18N_PREVLISTE.tooltip_attente_validation}"><i class="fa-solid fa-hourglass-half"></i> ${I18N_PREVLISTE.en_attente}</span>`;
            }

            // Localisation complète (usine > secteur > ligne > zone), pour repérer la machine sans ouvrir le détail.
            // Sur sa propre ligne, sous le titre : titre limité à 2 lignes (.equip-title), localisation
            // sur 1 ligne avec "..." si trop longue (.loc-path) — 3 lignes max au total pour la cellule.
            const locParts = [p.usine, p.secteur, p.ligne, p.zone].filter(v => v && v !== 'N/A');
            const locFull = locParts.join(' > ');
            const locHtml = locFull ? `<div class="loc-path" title="${locFull.replace(/"/g, '&quot;')}"><i class="fa-solid fa-location-dot" style="font-size:0.6rem;"></i> ${locFull}</div>` : '';

            // Badges rapides : priorité urgente et impact production, visibles sans ouvrir le détail
            let rowBadges = '';
            if (p.prio === 'Urgent') rowBadges += `<span class="badge-urgent"><i class="fa-solid fa-fire"></i> ${I18N_PREVLISTE.lib_urgent}</span>`;
            if (p.impact && p.impact !== 'Aucun impact') rowBadges += `<span class="badge-impact"><i class="fa-solid fa-industry"></i> ${p.impact}</span>`;
            const rowBadgesHtml = rowBadges ? `<div class="row-badges">${rowBadges}</div>` : '';

            // Intervenant assigné (celui qui recevra le bon de travail) — si "auto_matin" est coché,
            // ce n'est pas une personne fixe : ce sera celui au poste "Matin" du planning le jour J.
            const isAutoMatin = (p.auto_matin == 1 || p.auto_matin === '1');
            const intervenantHtml = isAutoMatin
                ? `<div class="cell-intervenant" title="${I18N_PREVLISTE.tooltip_technicien_matin}"><span class="iv-avatar" style="background:#fef3c7; color:#92400e;"><i class="fa-solid fa-sun"></i></span> ${I18N_PREVLISTE.technicien_matin_auto}</div>`
                : (p.intervenant
                    ? `<div class="cell-intervenant"><span class="iv-avatar">${p.intervenant.slice(0,2).toUpperCase()}</span> ${p.intervenant}</div>`
                    : `<div class="cell-intervenant iv-empty"><i class="fa-solid fa-user-slash"></i> ${I18N_PREVLISTE.non_assigne}</div>`);

            tr.style.opacity = rowOpacity;
            tr.title = I18N_PREVLISTE.tooltip_cliquer_detail;
            tr.onclick = () => openDetail(p);
            tr.innerHTML = `
                <td style="font-weight:700; color:var(--primary);">
                    <div class="equip-title" title="${String(p.equip).replace(/"/g, '&quot;')}"><i class="fa-solid fa-microchip" style="font-size:0.7rem; opacity:0.4; margin-right:5px;"></i>${p.equip}</div>
                    ${locHtml}
                    ${catBadge}
                </td>
                <td style="color:#475569; font-weight:600;">
                    ${p.desc}
                    ${typeBadge}
                    ${rowBadgesHtml}
                </td>
                <td><span class="badge-f ${fClass}"><i class="fa-solid ${icon}"></i> ${fText}</span></td>
                <td>
                    <span class="status-badge ${sm.cls}"><span class="status-dot"></span> ${st.label}</span>
                    ${echeanceDateTxt ? `<div class="echeance-date">${echeanceDateTxt}</div>` : ''}
                </td>
                <td>${intervenantHtml}</td>
                <td style="font-weight:700; color:var(--accent); text-align:center; white-space:nowrap;"><i class="fa-regular fa-clock" style="font-size:0.8rem;"></i> ${p.hours} h</td>
                <td>${derniereBiHtml}</td>
                <td style="text-align:center;">
                    <div style="display:flex; justify-content:center; align-items:center; gap:6px;">
                        ${statusBtn}
                        <button class="btn-gen" onclick="event.stopPropagation(); genererDemandeManuelle('${p.id}', '${String(p.equip).replace(/'/g, "\\'")}')" style="color:var(--accent); background:#dbeafe; border:none; border-radius:4px; padding:6px 10px; cursor:pointer; transition:0.2s;" title="${I18N_PREVLISTE.tooltip_generer_maintenant}"><i class="fa-solid fa-bolt"></i></button>
                        <button class="btn-del" onclick="event.stopPropagation(); deletePlan('${p.id}')" style="color:var(--danger); background:#fee2e2; border:none; border-radius:4px; padding:6px 10px; cursor:pointer; transition:0.2s;"><i class="fa-solid fa-trash"></i></button>
                    </div>
                </td>
            `;
            tbody.appendChild(tr);

            cardsHtml.push(`
                <div class="prev-rule-card" style="opacity:${rowOpacity};" onclick="openDetail(plans.find(pp => pp.id === '${p.id}'))">
                    <div class="prc-head">
                        <div>
                            <div class="equip-title" title="${String(p.equip).replace(/"/g, '&quot;')}"><i class="fa-solid fa-microchip" style="font-size:0.7rem; opacity:0.4; margin-right:5px;"></i>${p.equip}</div>
                            ${locHtml}
                            ${catBadge}
                        </div>
                        <span class="status-badge ${sm.cls}" style="flex:none;"><span class="status-dot"></span> ${st.label}</span>
                    </div>
                    ${echeanceDateTxt ? `<div class="echeance-date">${echeanceDateTxt}</div>` : ''}
                    <div class="prc-desc">${p.desc}${typeBadge}${rowBadgesHtml}</div>
                    <div class="prc-row">
                        <span class="badge-f ${fClass}"><i class="fa-solid ${icon}"></i> ${fText}</span>
                        ${derniereBiHtml}
                    </div>
                    <div class="prc-row">${intervenantHtml}</div>
                    <div class="prc-bottom">
                        <span style="font-weight:700; color:var(--accent); white-space:nowrap;"><i class="fa-regular fa-clock" style="font-size:0.8rem;"></i> ${p.hours} h</span>
                        <div class="prc-actions" onclick="event.stopPropagation();">
                            ${statusBtn}
                            <button class="btn-gen" onclick="genererDemandeManuelle('${p.id}', '${String(p.equip).replace(/'/g, "\\'")}')" style="color:var(--accent); background:#dbeafe; border:none; border-radius:4px; padding:6px 10px; cursor:pointer;" title="${I18N_PREVLISTE.tooltip_generer_maintenant}"><i class="fa-solid fa-bolt"></i></button>
                            <button class="btn-del" onclick="deletePlan('${p.id}')" style="color:var(--danger); background:#fee2e2; border:none; border-radius:4px; padding:6px 10px; cursor:pointer;"><i class="fa-solid fa-trash"></i></button>
                        </div>
                    </div>
                </div>
            `);
        });

        document.getElementById('rulesCards').innerHTML = cardsHtml.join('');
    }

    async function togglePause(id) {
        let p = plans.find(plan => plan.id === id);
        if (p) {
            p.status = (p.status === 'pause') ? 'actif' : 'pause';
            await fetch('preventif_liste.php', { method: 'POST', body: JSON.stringify(p) });
            loadPlans();
        }
    }

    function toggleModePlanif() {
        const mode = document.getElementById('f-mode-planif').value;
        document.getElementById('freqModeBlock').style.display = mode === 'frequence' ? '' : 'none';
        document.getElementById('joursModeBlock').style.display = mode === 'jours_semaine' ? '' : 'none';
        markFieldError('joursModeBlock', false);
    }

    function suggestEcheance() {
        const echInput = document.getElementById('f-echeance');
        if (echInput.value) return; // On ne touche pas à une date déjà saisie
        const freq = document.getElementById('f-freq').value;
        const days = freq === 'test_1m' ? 0 : (parseInt(freq, 10) || 0);
        if (!days) return;
        const d = new Date();
        d.setDate(d.getDate() + days);
        document.getElementById('echeanceHint').textContent = I18N_PREVLISTE.suggestion_date.replace('{date}', d.toLocaleDateString('fr-FR'));
    }

    // ============================================================================
    // WIZARD : NAVIGATION PAR ÉTAPES DE LA MODALE DE CRÉATION/ÉDITION
    // ============================================================================
    function markFieldError(id, hasError) {
        document.getElementById(id).classList.toggle('field-error', hasError);
    }

    function validateStep(step) {
        if (step === 1) {
            const ok = document.getElementById('f-type-op').value !== '';
            markFieldError('f-type-op', !ok);
            if (document.getElementById('f-mode-planif').value === 'jours_semaine') {
                const joursOk = document.querySelectorAll('.f-jour:checked').length > 0;
                markFieldError('joursModeBlock', !joursOk);
                return ok && joursOk;
            }
            return ok;
        }
        if (step === 2) {
            const ok = document.getElementById('f-equip').value !== '';
            markFieldError('f-equip', !ok);
            return ok;
        }
        if (step === 4) {
            const ok = document.getElementById('f-desc').value.trim() !== '';
            markFieldError('f-desc', !ok);
            return ok;
        }
        return true; // Étape 3 : aucun champ obligatoire
    }

    function stepIsFilled(n) {
        if (n === 1) {
            if (document.getElementById('f-type-op').value === '') return false;
            if (document.getElementById('f-mode-planif').value === 'jours_semaine') return document.querySelectorAll('.f-jour:checked').length > 0;
            return true;
        }
        if (n === 2) return !!document.getElementById('f-equip').value;
        if (n === 4) return !!document.getElementById('f-desc').value.trim();
        return true;
    }

    function updateWizardUI() {
        document.querySelectorAll('.wizard-panel').forEach(p => p.classList.toggle('active', parseInt(p.dataset.panel, 10) === wizardStep));
        document.querySelectorAll('.wizard-sidebar-step').forEach(item => {
            const n = parseInt(item.dataset.step, 10);
            const done = n < wizardStep;
            item.classList.toggle('active', n === wizardStep);
            item.classList.toggle('done', done);
            item.classList.toggle('step-incomplete', done && !stepIsFilled(n));
            item.classList.toggle('clickable', n <= wizardUnlocked);
        });
        document.querySelectorAll('.wizard-sidebar-connector').forEach(c => {
            const n = parseInt(c.dataset.connector, 10);
            c.classList.toggle('done', n < wizardStep);
        });
        document.getElementById('wizardProgressFill').style.width = ((wizardStep - 1) / (WIZARD_STEPS - 1) * 100) + '%';
        document.getElementById('wizardProgressLabel').textContent = I18N_PREVLISTE.etape_progress.replace('{n}', wizardStep).replace('{total}', WIZARD_STEPS);

        document.getElementById('btnWizardPrev').style.display = wizardStep === 1 ? 'none' : 'flex';
        document.getElementById('btnWizardNext').style.display = wizardStep === WIZARD_STEPS ? 'none' : 'flex';
        document.getElementById('btnWizardSave').style.display = wizardStep === WIZARD_STEPS ? 'flex' : 'none';

        if (wizardStep === 4) renderReviewCard();
    }

    // --- SÉLECTEURS VISUELS PAR CARTES (Priorité / Fréquence / Impact) ---
    function chooseCard(el, hiddenId, groupSelector) {
        document.getElementById(hiddenId).value = el.dataset.value;
        document.querySelectorAll(groupSelector).forEach(c => c.classList.remove('selected'));
        el.classList.add('selected');
        if (hiddenId === 'f-freq') suggestEcheance();
        markFieldError(hiddenId, false);
    }

    function syncCardSelection(hiddenId, groupSelector) {
        const val = document.getElementById(hiddenId).value;
        document.querySelectorAll(groupSelector).forEach(c => c.classList.toggle('selected', c.dataset.value === val));
    }

    function goToStep(n) {
        wizardStep = n;
        wizardUnlocked = Math.max(wizardUnlocked, n);
        updateWizardUI();
    }

    function tryGoToStep(n) {
        if (n <= wizardUnlocked) goToStep(n);
    }

    function nextStep() {
        if (!validateStep(wizardStep)) return;
        if (wizardStep < WIZARD_STEPS) goToStep(wizardStep + 1);
    }

    function prevStep() {
        if (wizardStep > 1) goToStep(wizardStep - 1);
    }

    function updateLocationPreview() {
        const parts = ['f-usine', 'f-secteur', 'f-ligne', 'f-zone', 'f-equip']
            .map(id => document.getElementById(id).value)
            .filter(v => v && v !== 'N/A');
        const el = document.getElementById('locationPreview');
        if (parts.length === 0) {
            el.innerHTML = `<i class="fa-solid fa-signs-post"></i> <span class="lp-empty">${I18N_PREVLISTE.lp_empty}</span>`;
        } else {
            el.innerHTML = '<i class="fa-solid fa-signs-post"></i> ' + parts.join(' <i class="fa-solid fa-chevron-right" style="font-size:0.65rem;color:#cbd5e1;"></i> ');
        }
    }

    function freqLabel(freq) {
        const map = { test_1m: I18N_PREVLISTE.freqlabel_test, '1': I18N_PREVLISTE.freqlabel_quotidien, '7': I18N_PREVLISTE.freqlabel_hebdo, '15': I18N_PREVLISTE.freqlabel_15j, '30': I18N_PREVLISTE.freqlabel_mensuel, '90': I18N_PREVLISTE.freqlabel_trimestriel, '180': I18N_PREVLISTE.freqlabel_semestriel, '365': I18N_PREVLISTE.freqlabel_annuel };
        return map[freq] || (freq ? I18N_PREVLISTE.freqlabel_n_jours.replace('{n}', freq) : '-');
    }

    const JOURS_LABELS = { lun: I18N_PREVLISTE.jour_lun, mar: I18N_PREVLISTE.jour_mar, mer: I18N_PREVLISTE.jour_mer, jeu: I18N_PREVLISTE.jour_jeu, ven: I18N_PREVLISTE.jour_ven };

    function biStatutLabel(s) {
        const map = { 'À faire': I18N_PREVLISTE.lib_afaire, 'En cours': I18N_PREVLISTE.lib_encours, 'Terminée': I18N_PREVLISTE.lib_termine, 'Refusée': I18N_PREVLISTE.lib_refuse, 'En attente': I18N_PREVLISTE.lib_attente };
        return map[s] || s;
    }

    function renderReviewCard() {
        const g = id => document.getElementById(id).value;
        const equip = g('f-equip') || '—';
        const locParts = ['f-usine', 'f-secteur', 'f-ligne', 'f-zone'].map(id => g(id)).filter(v => v && v !== 'N/A');
        const echeance = g('f-echeance') ? new Date(g('f-echeance') + 'T00:00:00').toLocaleDateString('fr-FR') : I18N_PREVLISTE.calculee_enregistrement;

        const modePlanif = g('f-mode-planif');
        const rythmeLabel = modePlanif === 'jours_semaine'
            ? ([...document.querySelectorAll('.f-jour:checked')].map(c => JOURS_LABELS[c.value]).join(', ') || I18N_PREVLISTE.aucun_jour_selectionne)
            : freqLabel(g('f-freq'));

        document.getElementById('reviewCard').innerHTML = `
            <div class="review-row"><span class="rr-label"><i class="fa-solid fa-microchip"></i> ${I18N_PREVLISTE.review_equipement}</span><span class="rr-value">${equip}</span></div>
            <div class="review-row"><span class="rr-label"><i class="fa-solid fa-location-dot"></i> ${I18N_PREVLISTE.review_localisation}</span><span class="rr-value">${locParts.join(' > ') || '—'}</span></div>
            <div class="review-row"><span class="rr-label"><i class="fa-solid fa-rotate"></i> ${I18N_PREVLISTE.review_rythme}</span><span class="rr-value">${rythmeLabel}</span></div>
            ${modePlanif === 'frequence' ? `<div class="review-row"><span class="rr-label"><i class="fa-regular fa-calendar-days"></i> ${I18N_PREVLISTE.review_echeance}</span><span class="rr-value">${echeance}</span></div>` : ''}
            <div class="review-row"><span class="rr-label"><i class="fa-regular fa-clock"></i> ${I18N_PREVLISTE.review_duree_estimee}</span><span class="rr-value">${g('f-hours') || '0'} h</span></div>
            <div class="review-row"><span class="rr-label"><i class="fa-solid fa-user-gear"></i> ${I18N_PREVLISTE.review_intervenant}</span><span class="rr-value">${document.getElementById('f-auto-matin').checked ? I18N_PREVLISTE.technicien_matin_auto_planning : (g('f-intervenant') || I18N_PREVLISTE.non_assigne)}</span></div>
        `;
    }

    // ============================================================================
    // FENÊTRE DE DÉTAIL (consultation en lecture seule d'une règle existante)
    // ============================================================================
    function detailTile(icon, label, value, color) {
        return `<div class="info-tile"><div class="it-label"><i class="fa-solid ${icon}"></i> ${label}</div><div class="it-value"${color ? ` style="color:${color};"` : ''}>${value}</div></div>`;
    }
    function detailSection(icon, title, tilesHtml) {
        return `<div class="detail-section"><div class="detail-section-title"><i class="fa-solid ${icon}"></i> ${title}</div><div class="info-tile-grid">${tilesHtml}</div></div>`;
    }

    function alerteLabel(p) {
        if (!p.alerte_val) return I18N_PREVLISTE.alerte_non_definie;
        const unitLabel = { jours: I18N_PREVLISTE.unite_jours, mois: I18N_PREVLISTE.unite_mois, ans: I18N_PREVLISTE.unite_ans }[p.alerte_unit] || I18N_PREVLISTE.unite_jours;
        return I18N_PREVLISTE.alerte_avant_echeance.replace('{val}', p.alerte_val).replace('{unit}', unitLabel);
    }

    function openDetail(p) {
        detailPlan = p;
        const st = getPlanStatus(p);
        const sm = STATUS_META[st.key];
        const catMetaDetail = CATEGORIES[p.categorie] || Object.values(CATEGORIES)[0] || { label: I18N_PREVLISTE.categorie_defaut, icon: 'fa-gear', couleur: '#3498db' };

        document.getElementById('detailTitle').innerHTML = `<i class="fa-solid fa-microchip" style="color:var(--accent);"></i> ${p.equip || I18N_PREVLISTE.detail_equipement_fallback}`;
        document.getElementById('detailSubtitle').textContent = p.desc || I18N_PREVLISTE.detail_aucune_description;

        let badges = `<span class="detail-pill" style="background:${catMetaDetail.couleur}1f; color:${catMetaDetail.couleur};"><i class="fa-solid ${catMetaDetail.icon}"></i> ${catMetaDetail.label}</span>`;
        badges += `<span class="status-badge ${sm.cls} detail-pill"><span class="status-dot"></span> ${st.label}</span>`;
        if (p.prio === 'Urgent') badges += `<span class="detail-pill badge-urgent"><i class="fa-solid fa-fire"></i> ${I18N_PREVLISTE.lib_urgent}</span>`;
        if (p.type_op) badges += `<span class="detail-pill" style="background:#eef2f7; color:#64748b;"><i class="fa-solid fa-tag"></i> ${p.type_op}</span>`;
        document.getElementById('detailBadges').innerHTML = badges;

        const echeanceTxt = p.echeance ? new Date(p.echeance + 'T00:00:00').toLocaleDateString('fr-FR') : I18N_PREVLISTE.detail_non_planifiee;
        const lastGenTxt = p.last_gen ? new Date(p.last_gen.replace(' ', 'T')).toLocaleDateString('fr-FR') : I18N_PREVLISTE.detail_jamais_generee;
        const rythmeTxt = p.mode_planif === 'jours_semaine'
            ? ((p.jours || '').split(',').filter(Boolean).map(j => JOURS_LABELS[j] || j).join(', ') || I18N_PREVLISTE.aucun_jour)
            : freqLabel(p.freq);

        const lastOcc = lastOccurrence(p.id);
        const dernierBiTxt = lastOcc ? (lastOcc.num_bi || I18N_PREVLISTE.detail_attente_validation) : I18N_PREVLISTE.detail_jamais_generee;

        const locParts = [p.usine, p.secteur, p.ligne, p.zone].filter(v => v && v !== 'N/A');
        const locHtml = locParts.length
            ? `<i class="fa-solid fa-signs-post"></i> ${locParts.join(' <i class="fa-solid fa-chevron-right" style="font-size:0.65rem;color:#cbd5e1;"></i> ')}`
            : `<i class="fa-solid fa-signs-post"></i> <span class="lp-empty">${I18N_PREVLISTE.detail_loc_non_renseignee}</span>`;

        let bodyHtml = '';
        bodyHtml += `<div class="detail-section"><div class="detail-section-title"><i class="fa-solid fa-location-dot"></i> ${I18N_PREVLISTE.review_localisation}</div><div class="location-preview" style="margin-top:0;">${locHtml}</div></div>`;
        bodyHtml += detailSection('fa-calendar-day', I18N_PREVLISTE.detail_section_planification, [
            detailTile('fa-rotate', I18N_PREVLISTE.review_rythme, rythmeTxt),
            detailTile('fa-regular fa-calendar-days', I18N_PREVLISTE.detail_echeance, echeanceTxt),
            detailTile('fa-regular fa-bell', I18N_PREVLISTE.detail_alerte, alerteLabel(p)),
            detailTile('fa-regular fa-clock', I18N_PREVLISTE.detail_duree_estimee, `${p.hours || 0} h`),
            detailTile('fa-flag', I18N_PREVLISTE.detail_priorite, p.prio || I18N_PREVLISTE.lib_normal),
        ].join(''));
        bodyHtml += detailSection('fa-industry', I18N_PREVLISTE.detail_section_impact, [
            detailTile('fa-industry', I18N_PREVLISTE.detail_impact, p.impact || I18N_PREVLISTE.impact_aucun),
            detailTile('fa-hourglass-half', I18N_PREVLISTE.detail_duree_arret, p.arret_h ? `${p.arret_h} h` : I18N_PREVLISTE.detail_non_renseignee),
        ].join(''));
        const intervenantDetailTxt = (p.auto_matin == 1 || p.auto_matin === '1')
            ? I18N_PREVLISTE.technicien_matin_auto_planning
            : (p.intervenant || I18N_PREVLISTE.non_assigne);
        bodyHtml += detailSection('fa-users', I18N_PREVLISTE.detail_section_equipe, [
            detailTile('fa-user-gear', I18N_PREVLISTE.review_intervenant, intervenantDetailTxt),
            detailTile('fa-user-pen', I18N_PREVLISTE.detail_declarant, p.declarant || '-'),
        ].join(''));
        bodyHtml += detailSection('fa-clock-rotate-left', I18N_PREVLISTE.detail_section_tracabilite, [
            detailTile('fa-history', I18N_PREVLISTE.detail_derniere_generation, lastGenTxt),
            detailTile('fa-file-invoice', I18N_PREVLISTE.detail_dernier_num_bi, dernierBiTxt),
            detailTile('fa-toggle-on', I18N_PREVLISTE.detail_statut_regle, p.status === 'pause' ? I18N_PREVLISTE.detail_en_pause : I18N_PREVLISTE.detail_active),
        ].join(''));

        bodyHtml += `<div class="detail-block"><h4><i class="fa-solid fa-file-signature"></i> ${I18N_PREVLISTE.detail_section_description}</h4><p>${p.desc || '—'}</p></div>`;
        if (p.cause) bodyHtml += `<div class="detail-block"><h4><i class="fa-solid fa-magnifying-glass"></i> ${I18N_PREVLISTE.detail_section_cause}</h4><p>${p.cause}</p></div>`;
        if (p.pieces) bodyHtml += `<div class="detail-block"><h4><i class="fa-solid fa-gears"></i> ${I18N_PREVLISTE.detail_section_pieces}</h4><p>${p.pieces}</p></div>`;
        if (p.commentaires) bodyHtml += `<div class="detail-block"><h4><i class="fa-solid fa-comment"></i> ${I18N_PREVLISTE.detail_section_commentaires}</h4><p>${p.commentaires}</p></div>`;

        document.getElementById('detailBody').innerHTML = bodyHtml;

        const occs = historyByRule[p.id] || [];
        const historyList = document.getElementById('detailHistoryList');
        if (occs.length === 0) {
            historyList.innerHTML = `<p style="color:#94a3b8; font-size:0.85rem;">${I18N_PREVLISTE.detail_aucune_generation}</p>`;
        } else {
            historyList.innerHTML = occs.map(occ => `
                <div class="history-row" onclick="openOccurrence('${occ.id}')">
                    <span>${occ.date ? new Date(occ.date.replace(' ', 'T')).toLocaleDateString('fr-FR') : '-'}</span>
                    <span>${occ.num_bi ? `<span class="badge-bi"><i class="fa-solid fa-file-invoice"></i> ${occ.num_bi}</span>` : `<span class="badge-bi badge-bi-pending"><i class="fa-solid fa-hourglass-half"></i> ${I18N_PREVLISTE.en_attente}</span>`}</span>
                    <span>${occ.statut ? biStatutLabel(occ.statut) : '-'}</span>
                    <span>${occ.tech || I18N_PREVLISTE.non_assigne}</span>
                </div>
            `).join('');
        }

        document.getElementById('modalDetail').style.display = 'flex';
    }

    function closeDetail() { document.getElementById('modalDetail').style.display = 'none'; }

    function editFromDetail() {
        closeDetail();
        if (detailPlan) editPlan(detailPlan);
    }

    // ============================================================================
    // HISTORIQUE : clic sur une occurrence -> ouvre le vrai bon d'intervention
    // (modale #modalDetailBI fournie par composant_rapport.php, la même que sur
    // Saisie & Historique / Planning)
    // ============================================================================
    function openOccurrence(id) {
        showDetailBI(id);
    }

    function openHistorique() {
        const tbody = document.getElementById('historiqueTableBody');
        const emptyState = document.getElementById('historiqueEmptyState');
        tbody.innerHTML = '';
        emptyState.style.display = history.length === 0 ? 'block' : 'none';

        history.forEach(occ => {
            const tr = document.createElement('tr');
            tr.style.cursor = 'pointer';
            tr.onclick = () => openOccurrence(occ.id);
            const dateTxt = occ.date ? new Date(occ.date.replace(' ', 'T')).toLocaleDateString('fr-FR') : '-';
            const biHtml = occ.num_bi
                ? `<span class="badge-bi"><i class="fa-solid fa-file-invoice"></i> ${occ.num_bi}</span>`
                : `<span class="badge-bi badge-bi-pending"><i class="fa-solid fa-hourglass-half"></i> ${I18N_PREVLISTE.en_attente}</span>`;
            tr.innerHTML = `
                <td style="font-weight:700; color:var(--primary);">${occ.equip || '-'}</td>
                <td style="color:#475569;">${(occ.desc || '').replace('[PRÉVENTIF] ', '')}</td>
                <td>${dateTxt}</td>
                <td>${biHtml}</td>
                <td>${occ.statut ? biStatutLabel(occ.statut) : '-'}</td>
                <td>${occ.tech || I18N_PREVLISTE.non_assigne}</td>
            `;
            tbody.appendChild(tr);
        });

        document.getElementById('modalHistorique').style.display = 'flex';
    }

    function closeHistorique() { document.getElementById('modalHistorique').style.display = 'none'; }

    function openModal() {
        document.getElementById('wizardTitle').innerHTML = `<i class="fa-solid fa-sliders" style="color:var(--accent);"></i> ${I18N_PREVLISTE.wizard_title_new}`;
        document.getElementById('f-id').value = "";
        document.getElementById('f-desc').value = "";
        document.getElementById('f-hours').value = "0.25";

        document.getElementById('f-type-op').value = "";
        document.getElementById('f-categorie').value = urlCat || "process";
        document.getElementById('f-prio').value = "Normal";
        document.getElementById('f-mode-planif').value = "frequence";
        document.getElementById('f-freq').value = "1";
        document.querySelectorAll('.f-jour').forEach(c => c.checked = false);
        toggleModePlanif();
        document.getElementById('f-echeance').value = "";
        document.getElementById('f-alerte-val').value = "";
        document.getElementById('f-alerte-unit').value = "jours";
        document.getElementById('echeanceHint').textContent = I18N_PREVLISTE.hint_echeance_auto;

        // Relance propre de la cascade
        document.getElementById('f-usine').value = "";
        updateCascade('secteur');

        document.getElementById('f-impact').value = "Aucun impact";
        document.getElementById('f-arret-h').value = "";
        document.getElementById('f-intervenant').value = "";
        document.getElementById('f-declarant').value = "";
        document.getElementById('f-auto-matin').checked = false;
        toggleAutoMatin();

        document.getElementById('f-cause').value = "";
        document.getElementById('f-pieces').value = "";
        document.getElementById('f-commentaires').value = "";

        syncCardSelection('f-categorie', '.card-categorie');
        syncCardSelection('f-prio', '.card-prio');
        syncCardSelection('f-mode-planif', '.card-mode');
        syncCardSelection('f-freq', '.card-freq');
        syncCardSelection('f-impact', '.card-impact');

        ['f-type-op','f-equip','f-desc'].forEach(id => markFieldError(id, false));
        markFieldError('joursModeBlock', false);
        wizardUnlocked = 1;
        goToStep(1);
        updateLocationPreview();
        renderLocPicker();

        document.getElementById('modalPreventif').style.display = "flex";
    }

    function editPlan(p) {
        document.getElementById('wizardTitle').innerHTML = `<i class="fa-solid fa-pen" style="color:var(--accent);"></i> ${I18N_PREVLISTE.wizard_title_edit}`;
        document.getElementById('f-id').value = p.id;
        document.getElementById('f-desc').value = p.desc;
        document.getElementById('f-hours').value = p.hours;

        document.getElementById('f-type-op').value = p.type_op || "";
        document.getElementById('f-categorie').value = p.categorie || "process";
        document.getElementById('f-prio').value = p.prio || "Normal";
        document.getElementById('f-mode-planif').value = p.mode_planif || "frequence";
        document.getElementById('f-freq').value = p.freq || "1";
        document.querySelectorAll('.f-jour').forEach(c => c.checked = (p.jours || '').split(',').includes(c.value));
        toggleModePlanif();
        document.getElementById('f-echeance').value = p.echeance || "";
        document.getElementById('f-alerte-val').value = p.alerte_val || "";
        document.getElementById('f-alerte-unit').value = p.alerte_unit || "jours";
        document.getElementById('echeanceHint').textContent = I18N_PREVLISTE.hint_echeance_auto;

        // --- Reconstruction de la cascade pour l'édition ---
        document.getElementById('f-usine').value = p.usine || "";
        updateCascade('secteur');

        document.getElementById('f-secteur').value = p.secteur || "";
        updateCascade('ligne');

        document.getElementById('f-ligne').value = p.ligne || "";
        if(document.getElementById('f-ligne').disabled === false) updateCascade('zone');

        document.getElementById('f-zone').value = p.zone || "";
        updateCascade('machine');

        // Sécurité si l'équipement a été supprimé de la BDD entre temps
        const fEquip = document.getElementById('f-equip');
        if (p.equip && ![...fEquip.options].some(o => o.value === p.equip)) {
            const opt = document.createElement('option');
            opt.value = p.equip;
            opt.innerText = p.equip + " " + I18N_PREVLISTE.hors_liste_suffix;
            fEquip.appendChild(opt);
            fEquip.disabled = false;
        }
        fEquip.value = p.equip || "";
        // ---------------------------------------------------

        document.getElementById('f-impact').value = p.impact || "Aucun impact";
        document.getElementById('f-arret-h').value = p.arret_h || "";
        document.getElementById('f-intervenant').value = p.intervenant || "";
        document.getElementById('f-declarant').value = p.declarant || "";
        document.getElementById('f-auto-matin').checked = !!(p.auto_matin && p.auto_matin != '0');
        toggleAutoMatin();

        document.getElementById('f-cause').value = p.cause || "";
        document.getElementById('f-pieces').value = p.pieces || "";
        document.getElementById('f-commentaires').value = p.commentaires || "";

        syncCardSelection('f-categorie', '.card-categorie');
        syncCardSelection('f-prio', '.card-prio');
        syncCardSelection('f-mode-planif', '.card-mode');
        syncCardSelection('f-freq', '.card-freq');
        syncCardSelection('f-impact', '.card-impact');

        ['f-type-op','f-equip','f-desc'].forEach(id => markFieldError(id, false));
        markFieldError('joursModeBlock', false);
        wizardUnlocked = WIZARD_STEPS; // Édition : on autorise à naviguer librement, les données sont déjà valides
        goToStep(1);
        updateLocationPreview();
        renderLocPicker();

        document.getElementById('modalPreventif').style.display = "flex";
    }

    // Ouvre l'assistant de règle pré-rempli depuis une demande en attente transférée depuis le SAS
    // de validation (maintenance.php) — voir transfererVersPreventif() côté maintenance.php. On
    // réutilise editPlan() tel quel (même remplissage des champs, même cascade de localisation)
    // avec un id vide : à l'enregistrement, savePlan() génère alors un nouvel id (nouvelle règle),
    // exactement comme depuis le bouton "+ Nouvelle opération".
    function ouvrirDepuisDemande(payload) {
        editPlan({
            id: '', equip: payload.equip || '', desc: payload.desc || '', hours: '0.25',
            type_op: '', categorie: urlCat || 'process', prio: payload.prio || 'Normal',
            mode_planif: 'frequence', freq: '365', jours: '', echeance: '',
            alerte_val: '', alerte_unit: 'jours',
            usine: payload.usine || '', secteur: payload.secteur || '', ligne: payload.ligne || '', zone: payload.zone || '',
            impact: 'Aucun impact', arret_h: '', intervenant: '', declarant: '',
            cause: '', pieces: '', commentaires: ''
        });
        document.getElementById('wizardTitle').innerHTML = `<i class="fa-solid fa-recycle" style="color:var(--gelpam-green);"></i> ${I18N_PREVLISTE.wizard_title_depuis_demande}`;
        demandeIdTransfert = payload.demandeId || null;
    }

    async function savePlan() {
        if (!validateStep(1) || !validateStep(2) || !validateStep(4)) {
            await aspirineAlert(I18N_PREVLISTE.err_title, I18N_PREVLISTE.err_champs_obligatoires);
            return;
        }
        const id = document.getElementById('f-id').value;
        let existingPlan = plans.find(plan => plan.id === id) || {};

        const modePlanif = document.getElementById('f-mode-planif').value;

        let echeance = document.getElementById('f-echeance').value;
        if (!echeance && modePlanif === 'frequence') {
            // Proposition automatique si l'utilisateur n'a rien saisi (uniquement en mode fréquence)
            const freq = document.getElementById('f-freq').value;
            const days = freq === 'test_1m' ? 0 : (parseInt(freq, 10) || 0);
            if (days) {
                const d = new Date();
                d.setDate(d.getDate() + days);
                echeance = d.toISOString().slice(0, 10);
            }
        }

        const p = {
            id: id || "PLAN-" + Date.now(),
            equip: document.getElementById('f-equip').value,
            desc: document.getElementById('f-desc').value,
            hours: document.getElementById('f-hours').value,

            type_op: document.getElementById('f-type-op').value,
            categorie: document.getElementById('f-categorie').value,
            prio: document.getElementById('f-prio').value,
            mode_planif: modePlanif,
            freq: document.getElementById('f-freq').value,
            jours: modePlanif === 'jours_semaine' ? [...document.querySelectorAll('.f-jour:checked')].map(c => c.value).join(',') : '',
            echeance: echeance,
            alerte_val: document.getElementById('f-alerte-val').value,
            alerte_unit: document.getElementById('f-alerte-unit').value,

            usine: document.getElementById('f-usine').value,
            secteur: document.getElementById('f-secteur').value,
            ligne: document.getElementById('f-ligne').value === 'N/A' ? "" : document.getElementById('f-ligne').value,
            zone: document.getElementById('f-zone').value,

            impact: document.getElementById('f-impact').value,
            arret_h: document.getElementById('f-arret-h').value,
            intervenant: document.getElementById('f-intervenant').value,
            declarant: document.getElementById('f-declarant').value,
            auto_matin: document.getElementById('f-auto-matin').checked ? 1 : 0,

            cause: document.getElementById('f-cause').value,
            pieces: document.getElementById('f-pieces').value,
            commentaires: document.getElementById('f-commentaires').value,

            status: existingPlan.status || 'actif',
            last_gen: existingPlan.last_gen || ''
        };

        if(!p.equip || !p.desc) return await aspirineAlert(I18N_PREVLISTE.err_title, I18N_PREVLISTE.err_equip_desc_obligatoires);

        await fetch('preventif_liste.php', { method: 'POST', body: JSON.stringify(p) });
        closeModal();
        loadPlans();

        // Cette règle vient du transfert d'une demande en attente (voir ouvrirDepuisDemande) :
        // son contenu vit désormais dans la règle, la demande d'origine n'a plus lieu d'exister.
        if (demandeIdTransfert) {
            const idASupprimer = demandeIdTransfert;
            demandeIdTransfert = null;
            await fetch('api.php?delete=' + encodeURIComponent(idASupprimer));
            await aspirineAlert(I18N_PREVLISTE.transfere_title, I18N_PREVLISTE.transfere_msg);
        }
    }

    function closeModal() { document.getElementById('modalPreventif').style.display = "none"; }

    // Quand l'assignation auto au technicien du matin est cochée, le champ "Intervenant" fixe
    // n'a plus de sens (il sera ignoré à la génération) : on le désactive visuellement pour éviter
    // toute confusion, sans effacer sa valeur (repris si la case est décochée ensuite).
    function toggleAutoMatin() {
        const auto = document.getElementById('f-auto-matin').checked;
        document.getElementById('f-intervenant').disabled = auto;
        document.getElementById('f-intervenant').style.opacity = auto ? '0.5' : '1';
    }

    // Génération manuelle d'une demande à partir d'une règle (hors cycle du cron) : crée une nouvelle
    // tâche dans le SAS de validation (pas un bon d'intervention direct — voir action "generer_demande"
    // côté serveur, qui ne renseigne jamais num_bi).
    async function genererDemandeManuelle(id, equipLabel) {
        const ok = await aspirineConfirm(
            I18N_PREVLISTE.confirm_generer_titre,
            I18N_PREVLISTE.confirm_generer_msg.replace('{equip}', equipLabel),
            I18N_PREVLISTE.btn_generer,
            "var(--accent)"
        );
        if (!ok) return;
        try {
            const res = await fetch('preventif_liste.php', { method: 'POST', body: JSON.stringify({ action: 'generer_demande', id: id }) });
            const data = await res.json();
            if (data.status === 'ok') {
                let msg;
                if (data.tech) {
                    msg = I18N_PREVLISTE.demande_creee_avec_tech.replace('{tech}', data.tech);
                } else if (data.auto_matin_sans_tech) {
                    msg = I18N_PREVLISTE.demande_creee_sans_tech_matin;
                } else {
                    msg = I18N_PREVLISTE.demande_creee_sans_tech;
                }
                await aspirineAlert(I18N_PREVLISTE.demande_generee_title, msg);
                loadPlans();
            } else {
                await aspirineAlert(I18N_PREVLISTE.err_title, data.message || I18N_PREVLISTE.err_generation_echoue);
            }
        } catch (e) {
            await aspirineAlert(I18N_PREVLISTE.err_title, I18N_PREVLISTE.err_generation_echoue);
        }
    }

    function genererDemandeDepuisDetail() {
        if (!detailPlan) return;
        genererDemandeManuelle(detailPlan.id, detailPlan.equip);
    }

    // okLabel/okColor optionnels : par défaut "Supprimer" en rouge (usage historique, suppressions),
    // mais réutilisable pour une confirmation non destructive (ex. génération d'une demande) avec un
    // libellé et une couleur neutres.
    function aspirineConfirm(titre, message, okLabel, okColor) {
        return new Promise((resolve) => {
            const modal = document.getElementById('customConfirm');
            const couleur = okColor || 'var(--danger)';
            document.getElementById('confirmTitle').innerText = titre;
            document.getElementById('confirmMessage').innerText = message;
            document.getElementById('confirmOk').innerText = okLabel || I18N_PREVLISTE.btn_supprimer_default;
            document.getElementById('confirmOk').style.background = couleur;
            document.getElementById('confirmIcon').style.color = couleur;
            document.getElementById('confirmBox').style.borderTopColor = couleur;
            modal.style.display = 'block';

            document.getElementById('confirmOk').onclick = () => { modal.style.display = 'none'; resolve(true); };
            document.getElementById('confirmCancel').onclick = () => { modal.style.display = 'none'; resolve(false); };
        });
    }

    // ============================================================================
    // CHECKLIST SAISONNIÈRE : tâches ponctuelles (une fois, pas de récurrence) — voir
    // preventif_checklist côté serveur. Scopée à la catégorie de cette page (urlCat), comme les
    // règles automatiques juste au-dessus.
    // ============================================================================
    const checklistCategorie = urlCat || 'process';
    let checklistSaisonActuelle = null;
    let checklistSaisonsDisponibles = [];
    // Sélection pour report vers la saison suivante (case de gauche du tableau) : purement côté
    // client, ne survit pas à un changement de saison délibéré — voir changerSaisonChecklist().
    let checklistSelectionReport = new Set();
    const CKL_STATUTS = { a_faire: I18N_PREVLISTE.ckl_st_a_faire, en_cours: I18N_PREVLISTE.ckl_st_en_cours, termine: I18N_PREVLISTE.ckl_st_termine };
    // Réaffectée par initChecklistColResize() plus bas — appelée à chaque affichage de l'onglet
    // (voir switchTab) car le tableau démarre en display:none : des largeurs en px posées pendant
    // qu'il est caché ne se répercutent pas correctement sur le rendu table-layout:fixed tant qu'il
    // n'est pas redevenu visible, ce qui faisait déborder la colonne Actions jusqu'au premier
    // redimensionnement manuel.
    let appliquerLargeursChecklistSauvegardees = () => {};

    function switchTab(tab) {
        document.querySelectorAll('.preventif-tab').forEach(b => b.classList.toggle('active', b.dataset.tab === tab));
        document.getElementById('vue-regles').style.display = (tab === 'regles') ? '' : 'none';
        document.getElementById('vue-checklist').style.display = (tab === 'checklist') ? '' : 'none';
        document.body.classList.toggle('ckl-scroll-page', tab === 'checklist');
        if (tab === 'checklist') {
            loadChecklist(checklistSaisonActuelle);
            appliquerLargeursChecklistSauvegardees();
        }
    }

    let checklistItemsBruts = [];
    let checklistFiltreStatut = 'tous'; // 'tous' | 'a_faire' | 'en_cours' | 'termine'
    let checklistTri = { colonne: null, sens: 1 };

    async function loadChecklist(saison) {
        try {
            const url = 'preventif_liste.php?action=checklist_list&categorie=' + encodeURIComponent(checklistCategorie) + (saison ? '&saison=' + encodeURIComponent(saison) : '') + '&t=' + Date.now();
            const res = await fetch(url);
            const data = await res.json();
            checklistSaisonActuelle = data.saison;
            checklistSaisonsDisponibles = data.saisons_disponibles && data.saisons_disponibles.length ? data.saisons_disponibles : [data.saison];

            document.getElementById('checklist-saison-label').textContent = checklistSaisonActuelle;
            const select = document.getElementById('checklistSaisonSelect');
            select.innerHTML = checklistSaisonsDisponibles.map(s => `<option value="${s}" ${s === checklistSaisonActuelle ? 'selected' : ''}>${s}</option>`).join('');

            checklistItemsBruts = data.items || [];
            document.getElementById('cnt-checklist-todo').textContent = checklistItemsBruts.filter(i => i.statut !== 'termine').length;

            // Liste déroulante "Intervenant" du filtre : seulement les intervenants réellement présents
            // dans cette saison (pas toute l'équipe, voir cases à cocher du formulaire d'ajout).
            const filtreIntervenant = document.getElementById('checklistFilterIntervenant');
            const intervenantSelectionne = filtreIntervenant.value;
            const intervenantsPresents = [...new Set(checklistItemsBruts.flatMap(i => (i.intervenant || '').split(',').map(n => n.trim()).filter(Boolean)))].sort();
            filtreIntervenant.innerHTML = `<option value="">${I18N_PREVLISTE.opt_tous_intervenants}</option>` + intervenantsPresents.map(n => `<option value="${n}">${n}</option>`).join('');
            filtreIntervenant.value = intervenantsPresents.includes(intervenantSelectionne) ? intervenantSelectionne : '';

            // Même principe pour "Usine" : filtre + suggestions du formulaire, à partir des usines déjà
            // utilisées dans cette saison.
            const filtreUsine = document.getElementById('checklistFilterUsine');
            const usineSelectionnee = filtreUsine.value;
            const usinesPresentes = [...new Set(checklistItemsBruts.map(i => i.usine).filter(Boolean))].sort();
            filtreUsine.innerHTML = `<option value="">${I18N_PREVLISTE.opt_toutes_usines}</option>` + usinesPresentes.map(u => `<option value="${u}">${u}</option>`).join('');
            filtreUsine.value = usinesPresentes.includes(usineSelectionnee) ? usineSelectionnee : '';

            appliquerFiltresChecklist();
        } catch (e) {}
    }

    function changerSaisonChecklist(saison) {
        checklistSelectionReport.clear();
        loadChecklist(saison);
    }

    function toggleSTChecklist() {
        document.getElementById('ckl-container-ee').style.display = document.getElementById('ckl-is-st').checked ? 'block' : 'none';
    }

    function setChecklistFilter(statut) {
        checklistFiltreStatut = statut;
        document.querySelectorAll('#checklistStatutChips .chip').forEach(b => b.classList.toggle('active', b.dataset.filter === statut));
        appliquerFiltresChecklist();
    }

    function trierChecklist(colonne) {
        if (checklistTri.colonne === colonne) { checklistTri.sens *= -1; }
        else { checklistTri = { colonne, sens: 1 }; }
        appliquerFiltresChecklist();
    }

    function appliquerFiltresChecklist() {
        const recherche = (document.getElementById('checklistSearch').value || '').trim().toLowerCase();
        // Le filtre "Intervenant" doit matcher une personne DANS la liste (plusieurs intervenants
        // possibles par tâche, stockés séparés par virgule), pas une égalité stricte de la chaîne.
        const intervenantFiltre = document.getElementById('checklistFilterIntervenant').value;
        const prioFiltre = document.getElementById('checklistFilterPrio').value;
        const usineFiltre = document.getElementById('checklistFilterUsine').value;

        let items = checklistItemsBruts.filter(i => {
            if (checklistFiltreStatut !== 'tous' && i.statut !== checklistFiltreStatut) return false;
            if (intervenantFiltre && !(i.intervenant || '').split(',').map(n => n.trim()).includes(intervenantFiltre)) return false;
            if (prioFiltre && String(i.prio || '') !== prioFiltre) return false;
            if (usineFiltre && i.usine !== usineFiltre) return false;
            if (recherche) {
                const hay = [i.usine, i.equip, i.desc, i.intervenant, i.signale_par].filter(Boolean).join(' ').toLowerCase();
                if (!hay.includes(recherche)) return false;
            }
            return true;
        });

        // Par défaut (pas de colonne cliquée), on garde l'ordre d'ajout tel que renvoyé par le
        // serveur (voir ORDER BY date_ajout ASC) : changer le statut d'une tâche ne doit JAMAIS la
        // faire bouger dans la liste, sinon on la perd de vue juste après l'avoir cliquée.
        if (checklistTri.colonne) {
            const col = checklistTri.colonne, sens = checklistTri.sens;
            items = [...items].sort((a, b) => {
                const va = (a[col] || '').toString(), vb = (b[col] || '').toString();
                return va.localeCompare(vb, 'fr', { numeric: true }) * sens;
            });
        }

        document.getElementById('cnt-ckl-tous').textContent = checklistItemsBruts.length;
        document.getElementById('cnt-ckl-afaire').textContent = checklistItemsBruts.filter(i => i.statut === 'a_faire').length;
        document.getElementById('cnt-ckl-encours').textContent = checklistItemsBruts.filter(i => i.statut === 'en_cours').length;
        document.getElementById('cnt-ckl-termine').textContent = checklistItemsBruts.filter(i => i.statut === 'termine').length;

        renderChecklistTable(items);
    }

    // "Signalé par" : liste déroulante équipe maintenance + services (une tâche peut être remontée
    // par un service comme par un technicien). "Intervenant(s)" : cases à cocher de l'équipe
    // (plusieurs personnes possibles sur une même tâche) + une ligne de saisie libre pour les
    // renforts saisonniers de la production, qui n'ont pas de compte GMAO.
    document.getElementById('ckl-signale-par').innerHTML = '<option value="">—</option>'
        + `<optgroup label="${I18N_PREVLISTE.optgroup_equipe_maintenance}">${team.map(t => `<option value="${t}">${t}</option>`).join('')}</optgroup>`
        + (servicesListe.length ? `<optgroup label="${I18N_PREVLISTE.optgroup_services}">${servicesListe.map(s => `<option value="${s}">${s}</option>`).join('')}</optgroup>` : '');
    document.getElementById('ckl-intervenant-checkboxes').innerHTML = team.map(t => `
        <label style="display:flex; align-items:center; gap:8px; font-size:0.85rem; cursor:pointer; font-weight:400; text-transform:none;">
            <input type="checkbox" class="ckl-intervenant-cb" value="${t}" style="width:16px; height:16px;"> ${t}
        </label>
    `).join('');

    // ============================================================================
    // ZONES / USINES GÉRÉES (liste déroulante "Usine" du formulaire de tâche, avec
    // création/renommage — voir preventif_checklist_zones côté serveur).
    // ============================================================================
    let checklistZones = [];

    async function loadChecklistZones() {
        try {
            const res = await fetch('preventif_liste.php?action=checklist_zones_list&t=' + Date.now());
            checklistZones = await res.json();
        } catch (e) { checklistZones = []; }
        const select = document.getElementById('ckl-usine');
        const valeurActuelle = select.value;
        select.innerHTML = '<option value="">—</option>' + checklistZones.map(z => `<option value="${z.label}">${z.label}</option>`).join('');
        select.value = valeurActuelle;
    }

    function ouvrirGererZones() {
        document.getElementById('ckl-nouvelle-zone').value = '';
        document.getElementById('ckl-zone-erreur').textContent = '';
        renderZonesGestion();
        document.getElementById('modalGererZones').style.display = 'block';
    }
    function fermerGererZones() { document.getElementById('modalGererZones').style.display = 'none'; }

    function renderZonesGestion() {
        const conteneur = document.getElementById('ckl-zones-liste-gestion');
        conteneur.innerHTML = checklistZones.map(z => `
            <div style="display:flex; gap:6px; align-items:center;">
                <input type="text" value="${z.label.replace(/"/g, '&quot;')}" data-id="${z.id}" class="ckl-zone-input" style="flex:1; padding:7px 9px; border:1px solid #ddd; border-radius:6px; font-family:inherit; font-size:0.85rem; box-sizing:border-box;" onkeydown="if(event.key==='Enter'){renommerZone(${z.id}, this.value)}">
                <button onclick="renommerZone(${z.id}, document.querySelector('.ckl-zone-input[data-id=\\'${z.id}\\']').value)" title="${I18N_PREVLISTE.tooltip_enregistrer_nom}" style="background:none; border:1px solid #bfe0f7; color:var(--accent); width:30px; height:30px; border-radius:6px; cursor:pointer;"><i class="fa-solid fa-check"></i></button>
                <button onclick="supprimerZone(${z.id})" title="${I18N_PREVLISTE.tooltip_supprimer}" style="background:none; border:1px solid #fecaca; color:var(--danger); width:30px; height:30px; border-radius:6px; cursor:pointer;"><i class="fa-solid fa-trash"></i></button>
            </div>
        `).join('') || `<p style="font-size:0.82rem; color:#94a3b8; text-align:center;">${I18N_PREVLISTE.aucune_zone}</p>`;
    }

    async function ajouterZone() {
        const label = document.getElementById('ckl-nouvelle-zone').value.trim();
        if (!label) return;
        await fetch('preventif_liste.php', { method: 'POST', body: JSON.stringify({ action: 'checklist_zone_add', label: label }) });
        document.getElementById('ckl-nouvelle-zone').value = '';
        await loadChecklistZones();
        renderZonesGestion();
    }

    async function renommerZone(id, label) {
        label = (label || '').trim();
        if (!label) return;
        await fetch('preventif_liste.php', { method: 'POST', body: JSON.stringify({ action: 'checklist_zone_rename', id: id, label: label }) });
        await loadChecklistZones();
        renderZonesGestion();
        loadChecklist(checklistSaisonActuelle); // les tâches migrent vers le nouveau nom
    }

    async function supprimerZone(id) {
        const ok = await aspirineConfirm(I18N_PREVLISTE.confirm_suppr_zone_titre, I18N_PREVLISTE.confirm_suppr_zone_msg);
        if (!ok) return;
        const res = await fetch('preventif_liste.php', { method: 'POST', body: JSON.stringify({ action: 'checklist_zone_delete', id: id }) });
        const data = await res.json().catch(() => null);
        if (data && data.status === 'error') {
            document.getElementById('ckl-zone-erreur').textContent = '⚠️ ' + data.message;
            return;
        }
        await loadChecklistZones();
        renderZonesGestion();
    }

    // Colonne "Intervenant" : nom de l'entreprise sous-traitante (avec pictogramme) si la tâche lui
    // est confiée, sinon les intervenants internes cochés/saisis à la main.
    function texteIntervenantChecklist(i) {
        const estSousTraite = i.is_sous_traitant == 1 || i.is_sous_traitant === true || i.is_sous_traitant === "1";
        if (estSousTraite) {
            const nom = (i.nom_entreprise || I18N_PREVLISTE.entreprise_ext_fallback).toString().replace(/</g, '&lt;');
            return `<i class="fa-solid fa-building" style="color:var(--gelpam-orange); margin-right:4px;"></i>${nom}`;
        }
        return (i.intervenant || '—').toString().replace(/</g, '&lt;');
    }

    let checklistItemsCourants = [];

    function renderChecklistTable(items) {
        checklistItemsCourants = items;
        const tbody = document.getElementById('checklistBody');
        const cardsWrap = document.getElementById('checklistCards');
        const empty = document.getElementById('checklistEmptyState');
        if (items.length === 0) {
            tbody.innerHTML = '';
            cardsWrap.innerHTML = '';
            empty.style.display = 'block';
            majBoutonReporter();
            return;
        }
        empty.style.display = 'none';
        majBoutonReporter();
        // Vue "cartes" (tablette/téléphone, voir .prev-cards-grid) : mêmes items, mêmes actions,
        // juste réarrangés verticalement au lieu des colonnes du tableau.
        cardsWrap.innerHTML = items.map(i => {
            const prioColor = i.prio === '1' ? 'var(--danger)' : i.prio === '2' ? 'var(--gelpam-orange)' : 'var(--primary)';
            const subLabel = i.statut === 'termine'
                ? I18N_PREVLISTE.fait_le.replace('{date}', i.date_fait || '?') + (i.fait_par ? ' ' + I18N_PREVLISTE.par + ' ' + i.fait_par : '')
                : (i.signale_par ? I18N_PREVLISTE.signale_par.replace('{n}', i.signale_par.toString().replace(/</g, '&lt;')) : '');
            const intervenantAffiche = texteIntervenantChecklist(i);
            return `
                <div class="prev-ckl-card ${i.statut === 'termine' ? 'is-fait' : ''}">
                    <div class="pcc-top">
                        <input type="checkbox" class="checklist-check" ${checklistSelectionReport.has(i.id) ? 'checked' : ''} onchange="toggleSelectionReport('${i.id}', this.checked)" title="${I18N_PREVLISTE.tooltip_selection_report_ckl}">
                        <div class="pcc-title">${(i.desc || '').toString().replace(/</g, '&lt;')}${subLabel ? `<div class="pcc-sub">${subLabel}</div>` : ''}</div>
                        ${i.prio ? `<span style="font-weight:700; color:${prioColor}; flex:none;">P${i.prio}</span>` : ''}
                    </div>
                    <div class="pcc-meta">
                        <span><b>${I18N_PREVLISTE.th_usine}:</b> ${(i.usine || '—').toString().replace(/</g, '&lt;')}</span>
                        <span><b>${I18N_PREVLISTE.th_equipement}:</b> ${(i.equip || '—').toString().replace(/</g, '&lt;')}</span>
                        <span><b>${I18N_PREVLISTE.th_intervenant}:</b> ${intervenantAffiche}</span>
                        <span><b>${I18N_PREVLISTE.th_date}:</b> ${i.date_prevue ? i.date_prevue.split('-').reverse().join('/') : '—'}</span>
                    </div>
                    <div class="pcc-bottom">
                        <select class="ckl-status-select ckl-st-${i.statut}" onchange="changerStatutChecklistItem('${i.id}', this.value)">
                            ${Object.entries(CKL_STATUTS).map(([v, l]) => `<option value="${v}" ${i.statut === v ? 'selected' : ''}>${l}</option>`).join('')}
                        </select>
                        <div>
                            <button class="btn-icon-del" style="border-color:#bfe0f7; color:var(--accent); margin-right:4px;" onclick="ouvrirAjoutChecklistItem('${i.id}')" title="${I18N_PREVLISTE.tooltip_modifier}"><i class="fa-solid fa-pen"></i></button>
                            <button class="btn-icon-del" onclick="deleteChecklistItem('${i.id}')" title="${I18N_PREVLISTE.tooltip_supprimer}"><i class="fa-solid fa-trash"></i></button>
                        </div>
                    </div>
                </div>
            `;
        }).join('');
        tbody.innerHTML = items.map(i => `
            <tr class="checklist-row ${i.statut === 'termine' ? 'is-fait' : ''}">
                <td style="text-align:center;"><input type="checkbox" class="checklist-check" ${checklistSelectionReport.has(i.id) ? 'checked' : ''} onchange="toggleSelectionReport('${i.id}', this.checked)" title="${I18N_PREVLISTE.tooltip_selection_report_ckl}"></td>
                <td class="ckl-td-tronque" title="${(i.usine || '').toString().replace(/</g, '&lt;').replace(/"/g, '&quot;')}">${(i.usine || '—').toString().replace(/</g, '&lt;')}</td>
                <td class="ckl-td-tronque" title="${(i.equip || '').toString().replace(/</g, '&lt;').replace(/"/g, '&quot;')}">${(i.equip || '—').toString().replace(/</g, '&lt;')}</td>
                <td><span class="ckl-desc-text" title="${(i.desc || '').toString().replace(/</g, '&lt;').replace(/"/g, '&quot;')}">${(i.desc || '').toString().replace(/</g, '&lt;')}</span><div style="font-size:0.68rem; color:#94a3b8; margin-top:2px;">${i.statut === 'termine' ? I18N_PREVLISTE.fait_le.replace('{date}', i.date_fait || '?') + (i.fait_par ? ' ' + I18N_PREVLISTE.par + ' ' + i.fait_par : '') : (i.signale_par ? I18N_PREVLISTE.signale_par.replace('{n}', i.signale_par.toString().replace(/</g, '&lt;')) : '')}</div></td>
                <td class="ckl-td-tronque" title="${(i.intervenant || '').toString().replace(/</g, '&lt;').replace(/"/g, '&quot;')}">${texteIntervenantChecklist(i)}</td>
                <td>${i.date_prevue ? i.date_prevue.split('-').reverse().join('/') : '—'}</td>
                <td>${i.prio ? `<span style="font-weight:700; color:${i.prio === '1' ? 'var(--danger)' : i.prio === '2' ? 'var(--gelpam-orange)' : 'var(--primary)'};">${i.prio}</span>` : '—'}</td>
                <td>
                    <select class="ckl-status-select ckl-st-${i.statut}" onchange="changerStatutChecklistItem('${i.id}', this.value)">
                        ${Object.entries(CKL_STATUTS).map(([v, l]) => `<option value="${v}" ${i.statut === v ? 'selected' : ''}>${l}</option>`).join('')}
                    </select>
                </td>
                <td style="text-align:center; white-space:nowrap;">
                    <button class="btn-icon-del" style="border-color:#bfe0f7; color:var(--accent); margin-right:4px;" onclick="ouvrirAjoutChecklistItem('${i.id}')" title="${I18N_PREVLISTE.tooltip_modifier}"><i class="fa-solid fa-pen"></i></button>
                    <button class="btn-icon-del" onclick="deleteChecklistItem('${i.id}')" title="${I18N_PREVLISTE.tooltip_supprimer}"><i class="fa-solid fa-trash"></i></button>
                </td>
            </tr>
        `).join('');
    }

    function toggleSelectionReport(id, checked) {
        if (checked) { checklistSelectionReport.add(id); } else { checklistSelectionReport.delete(id); }
        majBoutonReporter();
    }

    // Le bouton ne sert à rien tant qu'aucune tâche n'est cochée — autant ne pas l'afficher.
    function majBoutonReporter() {
        document.getElementById('btn-reporter-selection').style.display = checklistSelectionReport.size > 0 ? 'flex' : 'none';
    }

    async function changerStatutChecklistItem(id, statut) {
        await fetch('preventif_liste.php', {
            method: 'POST',
            body: JSON.stringify({ action: 'checklist_set_statut', id: id, statut: statut, fait_par: currentUser })
        });
        loadChecklist(checklistSaisonActuelle);
    }

    async function deleteChecklistItem(id) {
        const ok = await aspirineConfirm(I18N_PREVLISTE.confirm_suppr_tache_titre, I18N_PREVLISTE.confirm_suppr_tache_msg);
        if (!ok) return;
        await fetch('preventif_liste.php', { method: 'POST', body: JSON.stringify({ action: 'checklist_delete', id: id }) });
        loadChecklist(checklistSaisonActuelle);
    }

    // Sans argument : ouverture pour ajout. Avec un id (clic sur le crayon d'une ligne) : édition,
    // pré-remplie — utile pour affecter l'intervenant/la date après coup sur les tâches importées.
    function ouvrirAjoutChecklistItem(id) {
        const item = id ? checklistItemsCourants.find(i => i.id === id) : null;
        const intervenantsActuels = item ? (item.intervenant || '').split(',').map(n => n.trim()).filter(Boolean) : [];

        document.getElementById('ckl-item-id').value = item ? item.id : '';
        document.getElementById('ckl-usine').value = item ? (item.usine || '') : '';
        document.getElementById('ckl-equip').value = item ? (item.equip || '') : '';
        document.getElementById('ckl-desc').value = item ? (item.desc || '') : '';
        document.getElementById('ckl-signale-par').value = item ? (item.signale_par || '') : currentUser;
        document.getElementById('ckl-date-prevue').value = item ? (item.date_prevue || '') : '';
        document.getElementById('ckl-prio').value = item ? (item.prio || '') : '';

        // Coche les cases correspondant à l'équipe, et met le reste (renforts saisonniers, noms hors
        // équipe) dans le champ libre.
        const nomsEquipe = new Set(team);
        document.querySelectorAll('.ckl-intervenant-cb').forEach(cb => { cb.checked = intervenantsActuels.includes(cb.value); });
        document.getElementById('ckl-intervenant-libre').value = intervenantsActuels.filter(n => !nomsEquipe.has(n)).join(', ');

        const estSousTraite = item ? (item.is_sous_traitant == 1 || item.is_sous_traitant === true || item.is_sous_traitant === "1") : false;
        document.getElementById('ckl-is-st').checked = estSousTraite;
        document.getElementById('ckl-entreprise').value = item && item.entreprise_ext_id ? item.entreprise_ext_id : '';
        toggleSTChecklist();

        document.getElementById('ckl-error').textContent = '';
        document.getElementById('ckl-modal-title').innerHTML = item
            ? `<i class="fa-solid fa-pen" style="color:var(--accent);"></i> ${I18N_PREVLISTE.ckl_modal_modifier}`
            : `<i class="fa-solid fa-square-check" style="color:var(--primary);"></i> ${I18N_PREVLISTE.ckl_modal_ajouter}`;
        document.getElementById('ckl-modal-submit').innerHTML = item
            ? `<i class="fa-solid fa-check"></i> ${I18N_PREVLISTE.btn_enregistrer}`
            : `<i class="fa-solid fa-plus"></i> ${I18N_PREVLISTE.btn_ajouter}`;
        document.getElementById('modalChecklistItem').style.display = 'block';
    }
    function fermerAjoutChecklistItem() { document.getElementById('modalChecklistItem').style.display = 'none'; }

    async function confirmerAjoutChecklistItem() {
        const desc = document.getElementById('ckl-desc').value.trim();
        if (!desc) { document.getElementById('ckl-error').textContent = I18N_PREVLISTE.err_desc_tache_obligatoire; return; }

        const intervenantsCoches = [...document.querySelectorAll('.ckl-intervenant-cb:checked')].map(cb => cb.value);
        const intervenantsLibres = document.getElementById('ckl-intervenant-libre').value.split(',').map(n => n.trim()).filter(Boolean);
        const intervenant = [...intervenantsCoches, ...intervenantsLibres].join(', ');
        const estSousTraite = document.getElementById('ckl-is-st').checked;

        await fetch('preventif_liste.php', {
            method: 'POST',
            body: JSON.stringify({
                action: 'checklist_add', id: document.getElementById('ckl-item-id').value || undefined,
                categorie: checklistCategorie, saison: checklistSaisonActuelle,
                usine: document.getElementById('ckl-usine').value.trim(),
                equip: document.getElementById('ckl-equip').value.trim(), desc: desc,
                signale_par: document.getElementById('ckl-signale-par').value.trim(),
                intervenant: intervenant,
                date_prevue: document.getElementById('ckl-date-prevue').value,
                prio: document.getElementById('ckl-prio').value,
                is_sous_traitant: estSousTraite ? 1 : 0,
                entreprise_ext_id: estSousTraite ? document.getElementById('ckl-entreprise').value : null
            })
        });
        fermerAjoutChecklistItem();
        loadChecklist(checklistSaisonActuelle);
    }

    function suggererSaisonSuivante(saison) {
        const m = /^(\d{4})-(\d{4})$/.exec(saison || '');
        if (!m) return '';
        return (parseInt(m[1], 10) + 1) + '-' + (parseInt(m[2], 10) + 1);
    }

    async function dupliquerChecklist() {
        if (checklistSelectionReport.size === 0) {
            await aspirineAlert(I18N_PREVLISTE.aucune_tache_selectionnee_title, I18N_PREVLISTE.aucune_tache_selectionnee_msg);
            return;
        }
        document.getElementById('ckl-nouvelle-saison').value = suggererSaisonSuivante(checklistSaisonActuelle);
        // Suggère les saisons déjà existantes (avant ou après celle affichée) — reporter en arrière
        // (ex. une tâche finalement pas faite à temps qu'on rattache à l'année en cours) doit être
        // aussi simple qu'avancer d'une saison.
        document.getElementById('ckl-saisons-liste').innerHTML = checklistSaisonsDisponibles
            .filter(s => s !== checklistSaisonActuelle)
            .map(s => `<option value="${s}">`).join('');
        document.getElementById('ckl-dup-error').textContent = '';
        document.getElementById('modalDupliquerChecklist').style.display = 'block';
    }
    function fermerDupliquerChecklist() { document.getElementById('modalDupliquerChecklist').style.display = 'none'; }

    async function confirmerDupliquerChecklist() {
        const cible = document.getElementById('ckl-nouvelle-saison').value.trim();
        const errEl = document.getElementById('ckl-dup-error');
        if (!cible) { errEl.textContent = I18N_PREVLISTE.err_indiquer_saison; return; }
        if (cible === checklistSaisonActuelle) { errEl.textContent = I18N_PREVLISTE.err_saison_deja_affichee; return; }
        await fetch('preventif_liste.php', {
            method: 'POST',
            body: JSON.stringify({ action: 'checklist_dupliquer', categorie: checklistCategorie, saison_cible: cible, ids: [...checklistSelectionReport] })
        });
        checklistSelectionReport.clear();
        fermerDupliquerChecklist();
        loadChecklist(cible);
    }

    async function deletePlan(id) {
        const isConfirmed = await aspirineConfirm(I18N_PREVLISTE.confirm_suppr_regle_titre, I18N_PREVLISTE.confirm_suppr_regle_msg);
        if(isConfirmed) {
            await fetch('preventif_liste.php', { method: 'POST', body: JSON.stringify({action: 'delete', id: id}) });
            loadPlans();
        }
    }

    // Lancement au chargement de la page
    initDynamicSelects();
    loadPlans();
    // Chargé aussi au démarrage (pas seulement à l'ouverture de l'onglet) pour que le badge du
    // nombre de tâches restantes soit à jour dès l'arrivée sur la page, sans avoir à cliquer.
    loadChecklist(null);
    loadChecklistZones();

    // ============================================================================
    // COLONNES REDIMENSIONNABLES À LA SOURIS (tableau Travaux Hiver) — largeurs mémorisées
    // par navigateur (localStorage), par catégorie, pour ne pas repartir de zéro à chaque visite.
    // ============================================================================
    (function initChecklistColResize() {
        const table = document.getElementById('checklistTable');
        if (!table) return;
        const cleStockage = 'gmao_ckl_largeurs_' + checklistCategorie;
        const ths = () => [...table.querySelectorAll('thead th')];

        function appliquerLargeursSauvegardees() {
            try {
                const largeurs = JSON.parse(localStorage.getItem(cleStockage) || 'null');
                if (!Array.isArray(largeurs)) return;
                const cols = ths();
                // Un tableau sauvegardé avec un nombre de colonnes différent de celui d'aujourd'hui (ex.
                // ajout/suppression d'une colonne dans une mise à jour depuis la dernière visite) ne
                // correspond plus colonne à colonne : l'appliquer tel quel décale "Intervenant/Date/
                // Priorité/Statut/Actions" sur les mauvaises largeurs. On l'ignore plutôt que de désaligner
                // le tableau — un prochain redimensionnement le réenregistrera à la bonne taille.
                if (largeurs.length !== cols.length) { localStorage.removeItem(cleStockage); return; }
                largeurs.forEach((px, i) => { if (cols[i] && px) cols[i].style.width = px + 'px'; });
            } catch (e) {}
        }

        function sauvegarderLargeurs() {
            localStorage.setItem(cleStockage, JSON.stringify(ths().map(th => th.offsetWidth)));
        }

        table.querySelectorAll('thead th .ckl-col-resizer').forEach(handle => {
            handle.addEventListener('mousedown', (e) => {
                e.preventDefault();
                e.stopPropagation();
                const th = handle.parentElement;
                // Fige d'abord TOUTES les colonnes sur leur largeur actuelle en px avant d'appliquer le
                // glissement, pour repartir d'un état cohérent (sinon mélanger colonnes en % et
                // colonnes déjà passées en px peut faire déborder le tableau de sa carte). Le tableau
                // peut désormais dépasser sa carte sans casser la page : .table-scroll défile alors
                // horizontalement (voir overflow-x plus haut), comme dans un vrai tableur.
                ths().forEach(c => { c.style.width = c.offsetWidth + 'px'; });
                const startX = e.pageX;
                const startWidth = th.offsetWidth;
                handle.classList.add('is-resizing');
                function onMove(ev) {
                    th.style.width = Math.max(28, startWidth + (ev.pageX - startX)) + 'px';
                }
                function onUp() {
                    handle.classList.remove('is-resizing');
                    document.removeEventListener('mousemove', onMove);
                    document.removeEventListener('mouseup', onUp);
                    sauvegarderLargeurs();
                }
                document.addEventListener('mousemove', onMove);
                document.addEventListener('mouseup', onUp);
            });
        });

        appliquerLargeursSauvegardees();
    })();

    // Arrivée depuis le SAS de validation (maintenance.php) : ouvre directement l'assistant
    // pré-rempli avec la demande transférée — voir transfererVersPreventif() côté maintenance.php.
    (function ouvrirTransfertSiPresent() {
        if (new URLSearchParams(window.location.search).get('depuis_demande') !== '1') return;
        const raw = sessionStorage.getItem('gmao_transfert_preventif');
        sessionStorage.removeItem('gmao_transfert_preventif'); // usage unique : un refresh ne rouvre pas l'assistant
        if (!raw) return;
        try { ouvrirDepuisDemande(JSON.parse(raw)); } catch (e) {}
    })();
</script>
</body>
</html>
