<?php
// Données et bootstrap partagés pour le schéma interactif d'usine (schema_usine.php)
// et la fiche de vie machine (fiche_vie_machine.php).
//
// Architecture générique : les schémas et leurs zones cliquables vivent en base
// (tables schemas_usine / schema_zones), pas codés en dur dans la logique métier,
// pour permettre d'ajouter d'autres schémas plus tard (type='image' + éditeur de
// zones, à construire dans un second temps). Les 2 schémas actuels sont de
// type='code' : leur rendu visuel est un template PHP fait main (voir schema_usine.php),
// mais leurs zones/fiches sont seedées et gérées exactement comme n'importe quel
// futur schéma.

function schema_usine_bootstrap($db) {
    static $done = false;
    if ($done) return;
    $done = true;

    try {
        $db->exec("CREATE TABLE IF NOT EXISTS schemas_usine (
            id INT AUTO_INCREMENT PRIMARY KEY,
            nom VARCHAR(150),
            type ENUM('code','image') DEFAULT 'image',
            template_key VARCHAR(60) NULL,
            image_path VARCHAR(255) NULL,
            ordre INT DEFAULT 0
        )");
        $db->exec("CREATE TABLE IF NOT EXISTS schema_zones (
            id INT AUTO_INCREMENT PRIMARY KEY,
            schema_id INT,
            zone_key VARCHAR(60),
            label VARCHAR(150),
            pos_x DECIMAL(6,3) NULL,
            pos_y DECIMAL(6,3) NULL,
            pos_w DECIMAL(6,3) NULL,
            pos_h DECIMAL(6,3) NULL,
            rotation DECIMAL(6,2) DEFAULT 0,
            text_rotation DECIMAL(6,2) DEFAULT 0,
            font_size DECIMAL(4,2) NULL,
            text_color VARCHAR(20) NULL,
            shape_type VARCHAR(20) DEFAULT 'rect',
            fill_color VARCHAR(20) DEFAULT '#ffffff',
            fill_color2 VARCHAR(20) NULL,
            gradient TINYINT(1) DEFAULT 0,
            gradient_angle SMALLINT DEFAULT 135,
            border_color VARCHAR(20) NULL,
            effect VARCHAR(20) DEFAULT 'none',
            est_clickable TINYINT(1) DEFAULT 1,
            machine_id INT NULL,
            ordre INT DEFAULT 0,
            UNIQUE KEY uniq_zone (schema_id, zone_key)
        )");
        @$db->exec("ALTER TABLE schema_zones ADD COLUMN IF NOT EXISTS rotation DECIMAL(6,2) DEFAULT 0");
        @$db->exec("ALTER TABLE schema_zones ADD COLUMN IF NOT EXISTS text_rotation DECIMAL(6,2) DEFAULT 0");
        @$db->exec("ALTER TABLE schema_zones ADD COLUMN IF NOT EXISTS font_size DECIMAL(4,2) NULL");
        @$db->exec("ALTER TABLE schema_zones ADD COLUMN IF NOT EXISTS text_color VARCHAR(20) NULL");
        @$db->exec("ALTER TABLE schema_zones ADD COLUMN IF NOT EXISTS shape_type VARCHAR(20) DEFAULT 'rect'");
        // Élargi de 20 à 40 caractères : des clés de forme comme "convoyeur_rouleaux_arc" (22 car.)
        // dépassaient VARCHAR(20) et étaient tronquées en silence par MySQL à l'enregistrement — la
        // forme retombait alors sur un rectangle gris par défaut au rechargement de la page.
        @$db->exec("ALTER TABLE schema_zones MODIFY COLUMN shape_type VARCHAR(40) DEFAULT 'rect'");
        @$db->exec("ALTER TABLE schema_zones ADD COLUMN IF NOT EXISTS fill_color VARCHAR(20) DEFAULT '#ffffff'");
        @$db->exec("ALTER TABLE schema_zones ADD COLUMN IF NOT EXISTS border_color VARCHAR(20) NULL");
        @$db->exec("ALTER TABLE schema_zones ADD COLUMN IF NOT EXISTS effect VARCHAR(20) DEFAULT 'none'");
        @$db->exec("ALTER TABLE schema_zones ADD COLUMN IF NOT EXISTS fill_color2 VARCHAR(20) NULL");
        @$db->exec("ALTER TABLE schema_zones ADD COLUMN IF NOT EXISTS gradient TINYINT(1) DEFAULT 0");
        @$db->exec("ALTER TABLE schema_zones ADD COLUMN IF NOT EXISTS gradient_angle SMALLINT DEFAULT 135");
        @$db->exec("ALTER TABLE schema_zones ADD COLUMN IF NOT EXISTS est_clickable TINYINT(1) DEFAULT 1");
        @$db->exec("ALTER TABLE schema_zones ADD COLUMN IF NOT EXISTS text_offset_x DECIMAL(6,2) DEFAULT 0");
        @$db->exec("ALTER TABLE schema_zones ADD COLUMN IF NOT EXISTS text_offset_y DECIMAL(6,2) DEFAULT 0");
        @$db->exec("ALTER TABLE schema_zones ADD COLUMN IF NOT EXISTS categorie_visuelle_id INT NULL");
        $db->exec("CREATE TABLE IF NOT EXISTS schema_categories_visuelles (
            id INT AUTO_INCREMENT PRIMARY KEY,
            nom VARCHAR(100) UNIQUE,
            fill_color VARCHAR(20) DEFAULT '#3498db',
            fill_color2 VARCHAR(20) NULL,
            gradient TINYINT(1) DEFAULT 0,
            border_color VARCHAR(20) NULL,
            effect VARCHAR(20) DEFAULT 'none',
            ordre INT DEFAULT 0
        )");
        @$db->exec("ALTER TABLE schema_categories_visuelles ADD COLUMN IF NOT EXISTS effect VARCHAR(20) DEFAULT 'none'");
        // Valeurs de départ (forme, taille, rotation, texte, comportement) : appliquées UNIQUEMENT au
        // moment où on choisit la catégorie sur une forme (comme un tampon de départ) — jamais
        // réimposées ensuite, contrairement aux couleurs/effet ci-dessus. Ça garantit que les réglages
        // individuels faits forme par forme (taille, orientation...) survivent à un futur changement
        // global de la catégorie.
        @$db->exec("ALTER TABLE schema_categories_visuelles ADD COLUMN IF NOT EXISTS shape_type VARCHAR(20) DEFAULT 'rect'");
        @$db->exec("ALTER TABLE schema_categories_visuelles MODIFY COLUMN shape_type VARCHAR(40) DEFAULT 'rect'");
        @$db->exec("ALTER TABLE schema_categories_visuelles ADD COLUMN IF NOT EXISTS pos_w DECIMAL(6,3) NULL");
        @$db->exec("ALTER TABLE schema_categories_visuelles ADD COLUMN IF NOT EXISTS pos_h DECIMAL(6,3) NULL");
        @$db->exec("ALTER TABLE schema_categories_visuelles ADD COLUMN IF NOT EXISTS rotation DECIMAL(6,2) DEFAULT 0");
        @$db->exec("ALTER TABLE schema_categories_visuelles ADD COLUMN IF NOT EXISTS text_rotation DECIMAL(6,2) DEFAULT 0");
        @$db->exec("ALTER TABLE schema_categories_visuelles ADD COLUMN IF NOT EXISTS font_size DECIMAL(4,2) NULL");
        @$db->exec("ALTER TABLE schema_categories_visuelles ADD COLUMN IF NOT EXISTS text_color VARCHAR(20) NULL");
        @$db->exec("ALTER TABLE schema_categories_visuelles ADD COLUMN IF NOT EXISTS est_clickable TINYINT(1) DEFAULT 1");
        @$db->exec("ALTER TABLE schema_categories_visuelles ADD COLUMN IF NOT EXISTS gradient_angle SMALLINT DEFAULT 135");
        @$db->exec("ALTER TABLE schema_categories_visuelles ADD COLUMN IF NOT EXISTS label VARCHAR(150) NULL");
        @$db->exec("ALTER TABLE schemas_usine ADD COLUMN IF NOT EXISTS geometrie_migree TINYINT(1) DEFAULT 0");
        @$db->exec("ALTER TABLE schemas_usine ADD COLUMN IF NOT EXISTS canvas_w DECIMAL(10,2) DEFAULT 12192");
        @$db->exec("ALTER TABLE schemas_usine ADD COLUMN IF NOT EXISTS canvas_h DECIMAL(10,2) DEFAULT 6858");
        $db->exec("CREATE TABLE IF NOT EXISTS fiches_vie (
            id INT AUTO_INCREMENT PRIMARY KEY,
            zone_id INT UNIQUE,
            ref_moteur VARCHAR(150) DEFAULT '',
            numero_moteur VARCHAR(150) DEFAULT '',
            ref_reducteur VARCHAR(150) DEFAULT '',
            ref_courroie VARCHAR(150) DEFAULT '',
            numero_cable VARCHAR(150) DEFAULT '',
            ref_roulement_avant VARCHAR(150) DEFAULT '',
            ref_roulement_arriere VARCHAR(150) DEFAULT '',
            repere_automate VARCHAR(150) DEFAULT '',
            notes TEXT NULL,
            maj_par VARCHAR(100) NULL,
            maj_le DATETIME NULL
        )");
        @$db->exec("ALTER TABLE fiches_vie ADD COLUMN IF NOT EXISTS repere_automate VARCHAR(150) DEFAULT ''");
        @$db->exec("ALTER TABLE fiches_vie ADD COLUMN IF NOT EXISTS numero_moteur VARCHAR(150) DEFAULT ''");
        @$db->exec("ALTER TABLE fiches_vie ADD COLUMN IF NOT EXISTS numero_cable VARCHAR(150) DEFAULT ''");
        $db->exec("CREATE TABLE IF NOT EXISTS fiche_interventions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            zone_id INT,
            date_saisie DATETIME DEFAULT CURRENT_TIMESTAMP,
            auteur VARCHAR(100),
            texte TEXT
        )");
        @$db->exec("ALTER TABLE fiche_interventions ADD COLUMN IF NOT EXISTS date_intervention DATE NULL");
        @$db->exec("ALTER TABLE fiche_interventions ADD COLUMN IF NOT EXISTS technicien VARCHAR(150) DEFAULT ''");
        @$db->exec("ALTER TABLE fiche_interventions ADD COLUMN IF NOT EXISTS statut VARCHAR(20) DEFAULT ''");
        $db->exec("CREATE TABLE IF NOT EXISTS fiche_pannes_memo (
            id INT AUTO_INCREMENT PRIMARY KEY,
            zone_id INT,
            date_saisie DATETIME DEFAULT CURRENT_TIMESTAMP,
            auteur VARCHAR(100),
            constat TEXT,
            solution TEXT
        )");
        $db->exec("CREATE TABLE IF NOT EXISTS fiche_documents (
            id INT AUTO_INCREMENT PRIMARY KEY,
            zone_id INT,
            nom_original VARCHAR(255),
            chemin VARCHAR(255),
            taille INT,
            type_fichier VARCHAR(20),
            uploade_par VARCHAR(100),
            uploade_le DATETIME DEFAULT CURRENT_TIMESTAMP
        )");
        schema_usine_migrer_dossiers_documents($db);
        $db->exec("CREATE TABLE IF NOT EXISTS fiche_devis (
            id INT AUTO_INCREMENT PRIMARY KEY,
            zone_id INT,
            nom_original VARCHAR(255),
            chemin VARCHAR(255),
            taille INT,
            type_fichier VARCHAR(20),
            uploade_par VARCHAR(100),
            uploade_le DATETIME DEFAULT CURRENT_TIMESTAMP
        )");
        $db->exec("CREATE TABLE IF NOT EXISTS composant_types (
            id INT AUTO_INCREMENT PRIMARY KEY,
            nom VARCHAR(100) UNIQUE,
            ordre INT DEFAULT 0
        )");
        if ($db->query("SELECT COUNT(*) FROM composant_types")->fetchColumn() == 0) {
            $stmtSeedComposants = $db->prepare("INSERT INTO composant_types (nom, ordre) VALUES (?, ?)");
            foreach (['Référence moteur', 'N° moteur', 'Référence réducteur', 'Référence courroie', 'N° câble', 'Roulement avant', 'Roulement arrière'] as $i => $nomComposant) {
                $stmtSeedComposants->execute([$nomComposant, $i]);
            }
        }
        $db->exec("CREATE TABLE IF NOT EXISTS fiche_composants (
            id INT AUTO_INCREMENT PRIMARY KEY,
            zone_id INT,
            composant_type_id INT,
            valeur VARCHAR(255) DEFAULT '',
            UNIQUE KEY uniq_zone_composant (zone_id, composant_type_id)
        )");
        $db->exec("CREATE TABLE IF NOT EXISTS type_equipement_composants (
            type_equipement_id INT NOT NULL,
            composant_type_id INT NOT NULL,
            PRIMARY KEY (type_equipement_id, composant_type_id)
        )");
        schema_usine_migrer_composants($db);
    } catch (Exception $e) {
        error_log("schema_usine_data.php (CREATE TABLE) : " . $e->getMessage());
    }

    if (schema_usine_est_demo($db)) {
        schema_usine_seed_demo($db);
    } else {
        schema_usine_seed($db);
        schema_usine_import_geometry_if_needed($db, 'salle_tri');
        schema_usine_import_geometry_if_needed($db, 'conditionnement');
    }
}

// Coche automatiquement, sur toutes les fiches de vie des machines d'un type d'équipement
// donné, le matériel défini comme "par défaut" pour ce type dans Paramètres. N'ajoute que
// les cases manquantes (INSERT IGNORE sur la clé unique zone_id+composant_type_id) : ne touche
// jamais une case déjà cochée ni sa valeur déjà saisie, et ne décoche jamais rien — s'applique
// aussi bien à la liaison d'une nouvelle machine qu'aux fiches déjà existantes.
function appliquer_composants_defaut_type($db, $typeEquipementNom) {
    $typeEquipementNom = trim((string)$typeEquipementNom);
    if ($typeEquipementNom === '') return;
    try {
        $stmtType = $db->prepare("SELECT id FROM types_equipement WHERE nom = ?");
        $stmtType->execute([$typeEquipementNom]);
        $typeId = $stmtType->fetchColumn();
        if (!$typeId) return;

        $stmtDefauts = $db->prepare("SELECT composant_type_id FROM type_equipement_composants WHERE type_equipement_id = ?");
        $stmtDefauts->execute([$typeId]);
        $composantIds = array_map('intval', $stmtDefauts->fetchAll(PDO::FETCH_COLUMN));
        if (!$composantIds) return;

        $stmtZones = $db->prepare("SELECT z.id FROM schema_zones z JOIN machines m ON m.id = z.machine_id WHERE m.type_equipement = ?");
        $stmtZones->execute([$typeEquipementNom]);
        $zoneIds = array_map('intval', $stmtZones->fetchAll(PDO::FETCH_COLUMN));
        if (!$zoneIds) return;

        $insert = $db->prepare("INSERT IGNORE INTO fiche_composants (zone_id, composant_type_id, valeur) VALUES (?, ?, '')");
        foreach ($zoneIds as $zid) {
            foreach ($composantIds as $cid) {
                $insert->execute([$zid, $cid]);
            }
        }
    } catch (Exception $e) {
        error_log("appliquer_composants_defaut_type: " . $e->getMessage());
    }
}

// Nom de dossier lisible pour un identifiant technique (ex. libellé de zone) : minuscules,
// sans accents, sans caractères spéciaux — pour que les dossiers d'upload restent identifiables
// à l'oeil quand on parcourt le disque en dehors de la GMAO (SFTP, etc.).
function schema_usine_slug($texte) {
    $texte = (string)$texte;
    $translitere = @iconv('UTF-8', 'ASCII//TRANSLIT', $texte);
    if ($translitere !== false) { $texte = $translitere; }
    $texte = strtolower($texte);
    $texte = preg_replace('/[^a-z0-9]+/', '-', $texte);
    $texte = trim($texte, '-');
    return $texte !== '' ? substr($texte, 0, 40) : 'zone';
}

// Migration ponctuelle (idempotente, marqueur sur disque) : les dossiers de documentation créés
// avant l'ajout du nom lisible s'appelaient juste "{zone_id}/" — illisible en parcourant le
// serveur par SFTP. On les renomme en "{zone_id}_{nom-lisible}/" et on répare au passage les
// permissions (0755 par défaut sur les dossiers créés par mkdir() empêchait le groupe www-data,
// donc l'utilisateur gelpam, d'y écrire/supprimer — on force 0775/0664).
function schema_usine_migrer_dossiers_documents($db) {
    $baseDir = __DIR__ . '/uploads/documentation_machines/';
    $marqueur = $baseDir . '.migre_noms_dossiers';
    if (!is_dir($baseDir) || is_file($marqueur)) { return; }
    try {
        $zoneIds = $db->query("SELECT DISTINCT zone_id FROM fiche_documents")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($zoneIds as $zid) {
            $ancienDir = $baseDir . $zid . '/';
            if (!is_dir($ancienDir)) { continue; }
            $stmtZ = $db->prepare("SELECT label FROM schema_zones WHERE id = ?");
            $stmtZ->execute([$zid]);
            $label = $stmtZ->fetchColumn();
            $nouveauNom = $zid . '_' . schema_usine_slug($label ?: ('zone-' . $zid));
            $nouveauDir = $baseDir . $nouveauNom . '/';
            if ($ancienDir !== $nouveauDir) {
                if (!is_dir($nouveauDir)) { @rename($ancienDir, $nouveauDir); }
                if (is_dir($nouveauDir)) {
                    $ancienPrefixe = 'uploads/documentation_machines/' . $zid . '/';
                    $nouveauPrefixe = 'uploads/documentation_machines/' . $nouveauNom . '/';
                    $db->prepare("UPDATE fiche_documents SET chemin = REPLACE(chemin, ?, ?) WHERE zone_id = ?")
                        ->execute([$ancienPrefixe, $nouveauPrefixe, $zid]);
                }
            }
            $dirActuel = is_dir($nouveauDir) ? $nouveauDir : $ancienDir;
            @chmod($dirActuel, 0775);
            foreach (glob($dirActuel . '*') as $fichier) {
                if (is_file($fichier)) { @chmod($fichier, 0664); }
            }
        }
        @file_put_contents($marqueur, date('c'));
    } catch (Exception $e) {
        error_log("schema_usine_data.php migration dossiers documents : " . $e->getMessage());
    }
}

// Migration ponctuelle (idempotente, marqueur en base) : la fiche technique avait 7 champs fixes
// (référence/n° moteur, réducteur, courroie, câble, roulements avant/arrière). On bascule vers une
// liste de matériel configurable (table composant_types, gérée dans Paramètres) : chaque ancien
// champ déjà rempli devient un composant coché avec sa valeur conservée. Les anciennes colonnes de
// fiches_vie restent en base (non lues/écrites par l'appli) au cas où — pas de suppression.
function schema_usine_migrer_composants($db) {
    $db->exec("CREATE TABLE IF NOT EXISTS schema_migrations (
        cle VARCHAR(60) PRIMARY KEY,
        fait_le DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    $stmt = $db->prepare("SELECT COUNT(*) FROM schema_migrations WHERE cle = ?");
    $stmt->execute(['composants_v1']);
    if ($stmt->fetchColumn() > 0) { return; }
    try {
        $correspondance = [
            'ref_moteur' => 'Référence moteur',
            'numero_moteur' => 'N° moteur',
            'ref_reducteur' => 'Référence réducteur',
            'ref_courroie' => 'Référence courroie',
            'numero_cable' => 'N° câble',
            'ref_roulement_avant' => 'Roulement avant',
            'ref_roulement_arriere' => 'Roulement arrière',
        ];
        $typeIds = [];
        $stmtT = $db->prepare("SELECT id FROM composant_types WHERE nom = ?");
        foreach ($correspondance as $colonne => $nomType) {
            $stmtT->execute([$nomType]);
            $tid = $stmtT->fetchColumn();
            if ($tid) { $typeIds[$colonne] = $tid; }
        }
        $fiches = $db->query("SELECT * FROM fiches_vie")->fetchAll(PDO::FETCH_ASSOC);
        $insert = $db->prepare("INSERT IGNORE INTO fiche_composants (zone_id, composant_type_id, valeur) VALUES (?, ?, ?)");
        foreach ($fiches as $f) {
            foreach ($correspondance as $colonne => $nomType) {
                $valeur = trim($f[$colonne] ?? '');
                if ($valeur !== '' && isset($typeIds[$colonne])) {
                    $insert->execute([$f['zone_id'], $typeIds[$colonne], $valeur]);
                }
            }
        }
        $db->prepare("INSERT INTO schema_migrations (cle) VALUES (?)")->execute(['composants_v1']);
    } catch (Exception $e) {
        error_log("schema_usine_data.php migration composants : " . $e->getMessage());
    }
}

// Détecte l'environnement démo (base gmao_db_demo) : le process de production réel (noms de
// machines, références, disposition de l'atelier) est confidentiel et ne doit jamais apparaître
// sur la démo publique. Testé par nom de base plutôt qu'un flag séparé pour ne dépendre d'aucune
// config supplémentaire à tenir à jour.
function schema_usine_est_demo($db) {
    static $cache = null;
    if ($cache === null) {
        try { $cache = ($db->query("SELECT DATABASE()")->fetchColumn() === 'gmao_db_demo'); }
        catch (Throwable $e) { $cache = false; }
    }
    return $cache;
}

// Plan factice pour l'environnement démo : mêmes template_key que la prod (pour que la liste des
// schémas et les liens existants continuent de fonctionner tels quels), mais des zones et une
// disposition entièrement fictives, sans aucun rapport avec le vrai process de production —
// cliquables comme les vraies, donc la fonctionnalité (plan -> fiche de vie) reste démontrable.
function schema_usine_seed_demo($db) {
    $definitions = [
        'salle_tri' => [
            'nom' => 'Lignes de production',
            'zones' => [
                ['zone_key' => 'demo_reception', 'label' => 'Réception matière', 'x' => 5, 'y' => 8, 'w' => 19, 'h' => 25, 'color' => '#93c5fd'],
                ['zone_key' => 'demo_tremie_1', 'label' => 'Trémie 1', 'x' => 26, 'y' => 8, 'w' => 19, 'h' => 25, 'color' => '#a5b4fc'],
                ['zone_key' => 'demo_tremie_2', 'label' => 'Trémie 2', 'x' => 47, 'y' => 8, 'w' => 19, 'h' => 25, 'color' => '#a5b4fc'],
                ['zone_key' => 'demo_convoyeur_1', 'label' => 'Convoyeur A', 'x' => 68, 'y' => 8, 'w' => 19, 'h' => 25, 'color' => '#7dd3fc'],
                ['zone_key' => 'demo_trieur', 'label' => 'Trieur optique', 'x' => 5, 'y' => 36, 'w' => 19, 'h' => 25, 'color' => '#fbbf24'],
                ['zone_key' => 'demo_poste_tri_1', 'label' => 'Poste de tri 1', 'x' => 26, 'y' => 36, 'w' => 19, 'h' => 25, 'color' => '#fcd34d'],
                ['zone_key' => 'demo_poste_tri_2', 'label' => 'Poste de tri 2', 'x' => 47, 'y' => 36, 'w' => 19, 'h' => 25, 'color' => '#fcd34d'],
                ['zone_key' => 'demo_broyeur', 'label' => 'Broyeur', 'x' => 68, 'y' => 36, 'w' => 19, 'h' => 25, 'color' => '#fca5a5'],
                ['zone_key' => 'demo_silo', 'label' => 'Silo tampon', 'x' => 5, 'y' => 64, 'w' => 19, 'h' => 25, 'color' => '#86efac'],
                ['zone_key' => 'demo_convoyeur_2', 'label' => 'Convoyeur B', 'x' => 26, 'y' => 64, 'w' => 19, 'h' => 25, 'color' => '#7dd3fc'],
                ['zone_key' => 'demo_controle_q', 'label' => 'Contrôle qualité', 'x' => 47, 'y' => 64, 'w' => 19, 'h' => 25, 'color' => '#c4b5fd'],
                ['zone_key' => 'demo_local_tech_1', 'label' => 'Local technique', 'x' => 68, 'y' => 64, 'w' => 19, 'h' => 25, 'color' => '#d1d5db'],
            ],
        ],
        'conditionnement' => [
            'nom' => 'Conditionnement / Palettisation',
            'zones' => [
                ['zone_key' => 'demo_doseuse', 'label' => 'Doseuse', 'x' => 5, 'y' => 10, 'w' => 28, 'h' => 27, 'color' => '#a5b4fc'],
                ['zone_key' => 'demo_ensacheuse', 'label' => 'Ensacheuse', 'x' => 36, 'y' => 10, 'w' => 28, 'h' => 27, 'color' => '#93c5fd'],
                ['zone_key' => 'demo_etiqueteuse', 'label' => 'Étiqueteuse', 'x' => 67, 'y' => 10, 'w' => 28, 'h' => 27, 'color' => '#fcd34d'],
                ['zone_key' => 'demo_detecteur', 'label' => 'Détecteur métaux', 'x' => 5, 'y' => 40, 'w' => 28, 'h' => 27, 'color' => '#fca5a5'],
                ['zone_key' => 'demo_table_reprise', 'label' => 'Table de reprise', 'x' => 36, 'y' => 40, 'w' => 28, 'h' => 27, 'color' => '#d1d5db'],
                ['zone_key' => 'demo_robot', 'label' => 'Robot palettiseur', 'x' => 67, 'y' => 40, 'w' => 28, 'h' => 27, 'color' => '#fdba74'],
                ['zone_key' => 'demo_filmeuse', 'label' => 'Filmeuse', 'x' => 5, 'y' => 70, 'w' => 28, 'h' => 22, 'color' => '#7dd3fc'],
                ['zone_key' => 'demo_stock_palettes', 'label' => 'Stock palettes', 'x' => 36, 'y' => 70, 'w' => 28, 'h' => 22, 'color' => '#86efac'],
                ['zone_key' => 'demo_quai', 'label' => "Quai d'expédition", 'x' => 67, 'y' => 70, 'w' => 28, 'h' => 22, 'color' => '#c4b5fd'],
            ],
        ],
    ];

    $ordreSchema = 0;
    foreach ($definitions as $template_key => $def) {
        $stmt = $db->prepare("SELECT id FROM schemas_usine WHERE template_key = ?");
        $stmt->execute([$template_key]);
        $schema_id = $stmt->fetchColumn();
        if (!$schema_id) {
            $ins = $db->prepare("INSERT INTO schemas_usine (nom, type, template_key, ordre, geometrie_migree) VALUES (?, 'code', ?, ?, 1)");
            $ins->execute([$def['nom'], $template_key, $ordreSchema]);
            $schema_id = $db->lastInsertId();
        }
        $ordreSchema++;

        $countStmt = $db->prepare("SELECT COUNT(*) FROM schema_zones WHERE schema_id = ?");
        $countStmt->execute([$schema_id]);
        if ((int)$countStmt->fetchColumn() >= count($def['zones'])) {
            continue;
        }

        $zOrdre = 0;
        foreach ($def['zones'] as $z) {
            $chk = $db->prepare("SELECT id FROM schema_zones WHERE schema_id = ? AND zone_key = ?");
            $chk->execute([$schema_id, $z['zone_key']]);
            $zone_id = $chk->fetchColumn();
            if (!$zone_id) {
                $insZ = $db->prepare("INSERT INTO schema_zones
                    (schema_id, zone_key, label, pos_x, pos_y, pos_w, pos_h, shape_type, fill_color, border_color, effect, est_clickable, ordre)
                    VALUES (?, ?, ?, ?, ?, ?, ?, 'roundRect', ?, ?, 'shadow', 1, ?)");
                $insZ->execute([$schema_id, $z['zone_key'], $z['label'], $z['x'], $z['y'], $z['w'], $z['h'], $z['color'], $z['color'], $zOrdre]);
                $zone_id = $db->lastInsertId();
                $db->prepare("INSERT INTO fiches_vie (zone_id) VALUES (?)")->execute([$zone_id]);
            }
            $zOrdre++;
        }
    }
}

// Libellés des 36 vis "Salle de tri", repris tels quels de la légende fournie.
function schema_usine_vis_labels() {
    return [
        1 => "Vis acheminement 1",
        2 => "Vis acheminement 2",
        3 => "Vis répartition batteurs",
        4 => "Vis sortie batteur 1",
        5 => "Vis sortie batteur 2",
        6 => "Vis transfert 1 aéro 1",
        7 => "Vis transfert 2 aéro 2",
        8 => "Vis extraction 1 aéro 1",
        9 => "Vis extraction 2 aéro 1",
        10 => "Vis déchets aéro",
        11 => "Vis transfert 1 aéro 2",
        12 => "Vis transfert 2 aéro 2",
        13 => "Vis extraction 1 aéro 2",
        14 => "Vis extraction 2 aéro 2",
        15 => "Vis récupération poudre",
        16 => "Vis bon produit aéro 1+2",
        17 => "Vis alimentation vibrants haut",
        18 => "Vis couloir vibrants haut",
        19 => "Vis récupération écart vibrants haut",
        20 => "Vis déchets aéro 2",
        21 => "Vis coproduit alimentation Urshel",
        22 => "Vis sous Urshel",
        23 => "Vis alimentation élévateur",
        24 => "Vis sortie élévateur",
        25 => "Vis alimentation doseur",
        26 => "Vis déchets SINEX",
        27 => "Vis tube déchets poudre aéro",
        28 => "Vis déchets sortie batteurs",
        29 => "Vis déchets 1",
        30 => "Vis déchets coproduit",
        31 => "Vis déchets élévateur",
        32 => "Vis déchets 2",
        33 => "Vis déchets sortie salle de tri",
        34 => "Vis déchets horizontale",
        35 => "Grande vis déchets",
        36 => "Vis pivot",
    ];
}

function schema_usine_zones_definition() {
    $vis = schema_usine_vis_labels();
    $vis_zones = [];
    foreach ($vis as $n => $label) {
        // Libellé court affiché sur le plan (la forme est parfois trop étroite pour du texte long) ;
        // l'intitulé complet reste dans la fiche de vie via schema_usine_vis_labels().
        $vis_zones[] = ['zone_key' => 'vis_' . $n, 'label' => "Vis $n", 'groupe' => ($n <= 25 ? 'produit' : 'dechets')];
    }

    $tapis_zones = [];
    for ($n = 1; $n <= 19; $n++) {
        $tapis_zones[] = ['zone_key' => 'tapis_' . $n, 'label' => "Tapis T$n", 'groupe' => 'tapis'];
    }

    return [
        'salle_tri' => [
            'nom' => 'Salle de tri',
            'zones' => array_merge([
                ['zone_key' => 'herse', 'label' => 'Herse', 'groupe' => 'machine'],
                ['zone_key' => 'batteur_1', 'label' => 'Batteur 1', 'groupe' => 'machine'],
                ['zone_key' => 'batteur_2', 'label' => 'Batteur 2', 'groupe' => 'machine'],
                ['zone_key' => 'sinex', 'label' => 'Sinex', 'groupe' => 'machine'],
                ['zone_key' => 'aero_1', 'label' => 'Aéro 1', 'groupe' => 'machine'],
                ['zone_key' => 'aero_2', 'label' => 'Aéro 2', 'groupe' => 'machine'],
                ['zone_key' => 'vibrants_haut_1', 'label' => 'Vibrants H1', 'groupe' => 'machine'],
                ['zone_key' => 'vibrants_haut_2', 'label' => 'Vibrants H2', 'groupe' => 'machine'],
                ['zone_key' => 'vibrants_haut_3', 'label' => 'Vibrants H3', 'groupe' => 'machine'],
                ['zone_key' => 'vibrants_haut_4', 'label' => 'Vibrants H4', 'groupe' => 'machine'],
                ['zone_key' => 'vibrants_aero1', 'label' => 'Vibrants A1', 'groupe' => 'machine'],
                ['zone_key' => 'vibrants_aero2', 'label' => 'Vibrants A2', 'groupe' => 'machine'],
                ['zone_key' => 'vibrant_elevateur', 'label' => 'Vibrant élévateur', 'groupe' => 'machine'],
                ['zone_key' => 'broyeur', 'label' => 'Broyeur', 'groupe' => 'machine'],
            ], $vis_zones),
        ],
        'conditionnement' => [
            'nom' => 'Conditionnement / Palettisation',
            'zones' => array_merge([
                ['zone_key' => 'formeuse', 'label' => 'Formeuse CE-31', 'groupe' => 'machine'],
                ['zone_key' => 'ensacheuse', 'label' => 'Ensacheuse ZIM-31', 'groupe' => 'machine'],
                ['zone_key' => 'doseur', 'label' => 'Doseur', 'groupe' => 'machine'],
                ['zone_key' => 'vibrant_caisses', 'label' => 'Vibrant de caisses', 'groupe' => 'machine'],
                ['zone_key' => 'fermeuse_sachet', 'label' => 'Fermeuse sachet DF-31', 'groupe' => 'machine'],
                ['zone_key' => 'fermeuse_boites', 'label' => 'Fermeuse de boîtes CC-31', 'groupe' => 'machine'],
                ['zone_key' => 'detecteur_metaux', 'label' => 'Détecteur à métaux', 'groupe' => 'machine'],
                ['zone_key' => 'balance', 'label' => 'Balance', 'groupe' => 'machine'],
                ['zone_key' => 'etiqueteuse', 'label' => 'Etiqueteuse', 'groupe' => 'machine'],
                ['zone_key' => 'table_prehension', 'label' => 'Table de préhension', 'groupe' => 'machine'],
                ['zone_key' => 'robot', 'label' => 'Robot', 'groupe' => 'machine'],
                ['zone_key' => 'pile_palettes', 'label' => 'Pile Palettes', 'groupe' => 'machine'],
                ['zone_key' => 'depose', 'label' => 'Dépose', 'groupe' => 'machine'],
                ['zone_key' => 'filmeuse', 'label' => 'Filmeuse', 'groupe' => 'machine'],
                ['zone_key' => 'porte', 'label' => 'Porte', 'groupe' => 'machine'],
            ], $tapis_zones),
        ],
    ];
}

function schema_usine_seed($db) {
    $definitions = schema_usine_zones_definition();
    $ordreSchema = 0;
    foreach ($definitions as $template_key => $def) {
        $stmt = $db->prepare("SELECT id FROM schemas_usine WHERE template_key = ?");
        $stmt->execute([$template_key]);
        $schema_id = $stmt->fetchColumn();
        if (!$schema_id) {
            $ins = $db->prepare("INSERT INTO schemas_usine (nom, type, template_key, ordre) VALUES (?, 'code', ?, ?)");
            $ins->execute([$def['nom'], $template_key, $ordreSchema]);
            $schema_id = $db->lastInsertId();
        }
        $ordreSchema++;

        $countStmt = $db->prepare("SELECT COUNT(*) FROM schema_zones WHERE schema_id = ?");
        $countStmt->execute([$schema_id]);
        if ((int)$countStmt->fetchColumn() >= count($def['zones'])) {
            continue; // déjà seedé, on évite de refaire N requêtes à chaque appel
        }

        $zOrdre = 0;
        foreach ($def['zones'] as $z) {
            $chk = $db->prepare("SELECT id FROM schema_zones WHERE schema_id = ? AND zone_key = ?");
            $chk->execute([$schema_id, $z['zone_key']]);
            $zone_id = $chk->fetchColumn();
            if (!$zone_id) {
                $insZ = $db->prepare("INSERT INTO schema_zones (schema_id, zone_key, label, ordre) VALUES (?, ?, ?, ?)");
                $insZ->execute([$schema_id, $z['zone_key'], $z['label'], $zOrdre]);
                $zone_id = $db->lastInsertId();
                $db->prepare("INSERT INTO fiches_vie (zone_id) VALUES (?)")->execute([$zone_id]);
            }
            $zOrdre++;
        }
    }
}

// --- Couleurs de thème Office (extraites de theme1.xml des deux PowerPoint sources de David) ---
const SU_SCHEME_COLORS = [
    'bg1' => '#FFFFFF', 'tx1' => '#000000',
    'accent1' => '#4472C4', 'accent2' => '#ED7D31', 'accent3' => '#A5A5A5',
    'accent4' => '#FFC000', 'accent5' => '#5B9BD5', 'accent6' => '#70AD47',
];

function su_resolve_color($fill) {
    if (!$fill) return null;
    if (strpos($fill, 'scheme:') === 0) {
        $k = substr($fill, 7);
        return SU_SCHEME_COLORS[$k] ?? '#cccccc';
    }
    return $fill;
}

function su_border_radius_pct($shapeType) {
    if (in_array($shapeType, ['ellipse', 'flowChartConnector', 'donut', 'blockArc'])) return 'ellipse';
    if ($shapeType === 'roundRect') return 'roundRect';
    return 'rect';
}

// Associe chaque étiquette de texte du PowerPoint (numéro de vis, nom de machine...) à un
// zone_key connu, par proximité de position (les libellés sources ont pu bouger de quelques
// centièmes de % entre l'extraction et l'édition — la correspondance se fait sur la position
// la plus proche, pas sur un id fixe).
function su_match_labels($labels, $map) {
    $used = array_fill(0, count($map), false);
    $out = [];
    foreach ($labels as $lbl) {
        $best = -1;
        $bestDist = 999999;
        foreach ($map as $i => $m) {
            if ($used[$i]) continue;
            $d = pow($lbl['x'] - $m[1], 2) + pow($lbl['y'] - $m[2], 2);
            if ($d < $bestDist) { $bestDist = $d; $best = $i; }
        }
        if ($best >= 0 && $bestDist < 4.0) {
            $used[$best] = true;
            $out[] = ['label' => $lbl, 'zone_key' => $map[$best][3]];
        }
    }
    return $out;
}

function su_salle_tri_label_map() {
    return [
        ['1', 9.23, 87.28, 'vis_1'], ['2', 9.18, 70.16, 'vis_2'], ['3', 9.19, 59.90, 'vis_3'],
        ['4', 4.97, 47.03, 'vis_4'], ['5', 13.48, 46.73, 'vis_5'], ['6', 10.37, 28.87, 'vis_6'],
        ['7', 21.89, 47.87, 'vis_7'], ['8', 27.02, 32.95, 'vis_8'], ['9', 32.03, 33.22, 'vis_9'],
        ['10', 19.44, 60.51, 'vis_10'], ['11', 19.63, 69.45, 'vis_11'], ['12', 28.88, 69.40, 'vis_12'],
        ['13', 43.41, 33.15, 'vis_13'], ['14', 48.64, 33.38, 'vis_14'], ['15', 16.41, 31.08, 'vis_15'],
        ['16', 36.35, 31.10, 'vis_16'], ['17', 55.64, 31.17, 'vis_17'], ['18', 66.74, 36.18, 'vis_18'],
        ['19', 61.84, 60.71, 'vis_19'], ['20', 52.62, 62.11, 'vis_20'], ['20b', 39.64, 62.26, 'vis_20'],
        ['21', 56.04, 64.65, 'vis_21'], ['22', 58.99, 63.57, 'vis_22'], ['23', 59.74, 59.25, 'vis_23'],
        ['24', 71.98, 68.18, 'vis_24'], ['25', 73.77, 46.63, 'vis_25'], ['26', 8.70, 46.05, 'vis_26'],
        ['27', 16.20, 46.39, 'vis_27'], ['28', 16.87, 70.81, 'vis_28'], ['29', 33.75, 70.97, 'vis_29'],
        ['30', 45.17, 70.31, 'vis_30'], ['31', 56.30, 73.32, 'vis_31'], ['32', 37.61, 51.73, 'vis_32'],
        ['33', 37.62, 34.79, 'vis_33'], ['34', 37.68, 24.80, 'vis_34'], ['35', 37.70, 11.66, 'vis_35'],
        ['36', 42.11, 1.65, 'vis_36'],
        ['Aéro 1', 27.85, 47.94, 'aero_1'], ['Aéro 2', 43.81, 48.38, 'aero_2'],
        ['Vibrant élévateur', 67.21, 61.12, 'vibrant_elevateur'],
        ['Herse', 12.12, 94.99, 'herse'],
        ['Batteur1', 3.36, 64.28, 'batteur_1'], ['Batteur2', 12.19, 64.15, 'batteur_2'],
        ['Sinex', 7.94, 38.83, 'sinex'],
        ['VibrantsA1', 26.23, 62.36, 'vibrants_aero1'], ['VibrantsA2', 42.00, 62.88, 'vibrants_aero2'],
        ['VibrantsH1', 59.58, 38.28, 'vibrants_haut_1'], ['VibrantsH2', 59.58, 43.44, 'vibrants_haut_2'],
        ['VibrantsH3', 59.65, 48.60, 'vibrants_haut_3'], ['VibrantsH4', 59.58, 53.19, 'vibrants_haut_4'],
        ['Broyeur', 63.92, 69.69, 'broyeur'],
        // Cluster conditionnement en aperçu (non cliquable ici — détail dans l'autre schéma)
        ['Doseur', 80.66, 47.58, null], ['Ensacheuse', 80.52, 32.09, null], ['Fermeture sachets', 80.63, 62.41, null],
        ['Scotcheuse', 87.56, 62.53, null], ['LOMA', 91.99, 56.02, null], ['BalanceGhost', 95.42, 62.65, null],
        ['EtiqueteuseGhost', 91.39, 53.28, null], ['RobotGhost', 92.31, 44.25, null], ['FilmeuseGhost', 90.91, 31.07, null],
        ['Cartonneuse', 79.83, 12.82, null],
    ];
}

function su_condi_label_map() {
    return [
        ['Formeuse CE-31', 75.96, 37.16, 'formeuse'],
        ['Ensacheuse ZIM-31', 51.71, 36.70, 'ensacheuse'],
        ['Vibrant de caisses', 11.29, 26.76, 'vibrant_caisses'],
        ['Fermeuse sachet DF-31', 12.46, 40.23, 'fermeuse_sachet'],
        ['Fermeuse de boîtes CC-31', 12.46, 50.22, 'fermeuse_boites'],
        ['Détecteur à métaux', 12.41, 64.32, 'detecteur_metaux'],
        ['Balance', 11.57, 80.88, 'balance'],
        ['Etiqueteuse', 19.80, 84.60, 'etiqueteuse'],
        ['Robot', 40.66, 70.79, 'robot'],
        ['Table de préhension', 24.34, 71.86, 'table_prehension'],
        ['Dépose', 43.70, 85.47, 'depose'],
        ['Pile Palettes', 49.44, 70.36, 'pile_palettes'],
        ['Doseur', 24.44, 5.49, 'doseur'],
        ['Filmeuse', 64.78, 84.84, 'filmeuse'],
        ['Porte', 73.55, 86.65, 'porte'],
        ['T1', 67.48, 29.80, 'tapis_1'], ['T2', 58.85, 30.02, 'tapis_2'], ['T3', 48.32, 30.02, 'tapis_3'],
        ['T4', 47.62, 23.54, 'tapis_4'], ['T5', 47.34, 16.37, 'tapis_5'], ['T6', 28.66, 16.25, 'tapis_6'],
        ['T7', 8.40, 19.21, 'tapis_7'], ['T8', 7.98, 35.39, 'tapis_8'], ['T9', 8.05, 60.14, 'tapis_9'],
        ['T10', 7.18, 74.65, 'tapis_10'], ['T11', 8.32, 90.20, 'tapis_11'], ['T12', 20.99, 93.38, 'tapis_12'],
        ['T13', 34.11, 89.15, 'tapis_13'], ['T14', 34.96, 76.39, 'tapis_14'], ['T15', 48.03, 88.99, 'tapis_15'],
        ['T16', 60.40, 87.33, 'tapis_16'], ['T17', 65.93, 88.90, 'tapis_17'], ['T18', 71.66, 87.17, 'tapis_18'],
        ['T19', 81.13, 87.67, 'tapis_19'],
    ];
}

// Ne lance l'import de géométrie qu'une seule fois par schéma (protège les modifications
// manuelles faites depuis l'éditeur visuel — l'import ne doit jamais écraser du travail humain).
function schema_usine_import_geometry_if_needed($db, $template_key) {
    $stmt = $db->prepare("SELECT id, geometrie_migree FROM schemas_usine WHERE template_key = ?");
    $stmt->execute([$template_key]);
    $schema = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$schema || $schema['geometrie_migree']) return;
    schema_usine_import_geometry($db, $schema['id'], $template_key);
}

// Agrandit (mode='grow') ou réduit (mode='shrink', inverse mathématique exact) le canevas d'un
// côté (haut/bas/gauche/droite) : réétire toutes les zones existantes pour libérer (ou reprendre)
// de la place vierge sur ce côté, sans les déplacer visuellement les unes par rapport aux autres
// (simple mise à l'échelle proportionnelle de tout le schéma, réversible sans aucune perte de données).
function schema_usine_resize_canvas($db, $schema_id, $direction, $pct, $mode = 'grow') {
    $pct = max(1, min(80, (float)$pct));
    $factor = (100 + $pct) / 100; // ex: +20% => facteur 1.2

    // Garde-fou : on ne réduit jamais en dessous de la taille d'origine (celle du PowerPoint
    // source), pour ne jamais risquer de compresser le contenu réel.
    if ($mode === 'shrink') {
        $cur = $db->prepare("SELECT canvas_w, canvas_h FROM schemas_usine WHERE id = ?");
        $cur->execute([$schema_id]);
        $row = $cur->fetch(PDO::FETCH_ASSOC);
        if (!$row) return false;
        if (in_array($direction, ['top', 'bottom']) && (float)$row['canvas_h'] / $factor < 6858 - 1) return false;
        if (in_array($direction, ['left', 'right']) && (float)$row['canvas_w'] / $factor < 12192 - 1) return false;
    }

    if ($direction === 'top') {
        if ($mode === 'grow') {
            $db->prepare("UPDATE schema_zones SET pos_y = (pos_y + ?) / ?, pos_h = pos_h / ? WHERE schema_id = ?")
                ->execute([$pct, $factor, $factor, $schema_id]);
        } else {
            $db->prepare("UPDATE schema_zones SET pos_y = pos_y * ? - ?, pos_h = pos_h * ? WHERE schema_id = ?")
                ->execute([$factor, $pct, $factor, $schema_id]);
        }
        $db->prepare("UPDATE schemas_usine SET canvas_h = canvas_h " . ($mode === 'grow' ? '*' : '/') . " ? WHERE id = ?")->execute([$factor, $schema_id]);
    } elseif ($direction === 'bottom') {
        if ($mode === 'grow') {
            $db->prepare("UPDATE schema_zones SET pos_y = pos_y / ?, pos_h = pos_h / ? WHERE schema_id = ?")
                ->execute([$factor, $factor, $schema_id]);
        } else {
            $db->prepare("UPDATE schema_zones SET pos_y = pos_y * ?, pos_h = pos_h * ? WHERE schema_id = ?")
                ->execute([$factor, $factor, $schema_id]);
        }
        $db->prepare("UPDATE schemas_usine SET canvas_h = canvas_h " . ($mode === 'grow' ? '*' : '/') . " ? WHERE id = ?")->execute([$factor, $schema_id]);
    } elseif ($direction === 'left') {
        if ($mode === 'grow') {
            $db->prepare("UPDATE schema_zones SET pos_x = (pos_x + ?) / ?, pos_w = pos_w / ? WHERE schema_id = ?")
                ->execute([$pct, $factor, $factor, $schema_id]);
        } else {
            $db->prepare("UPDATE schema_zones SET pos_x = pos_x * ? - ?, pos_w = pos_w * ? WHERE schema_id = ?")
                ->execute([$factor, $pct, $factor, $schema_id]);
        }
        $db->prepare("UPDATE schemas_usine SET canvas_w = canvas_w " . ($mode === 'grow' ? '*' : '/') . " ? WHERE id = ?")->execute([$factor, $schema_id]);
    } elseif ($direction === 'right') {
        if ($mode === 'grow') {
            $db->prepare("UPDATE schema_zones SET pos_x = pos_x / ?, pos_w = pos_w / ? WHERE schema_id = ?")
                ->execute([$factor, $factor, $schema_id]);
        } else {
            $db->prepare("UPDATE schema_zones SET pos_x = pos_x * ?, pos_w = pos_w * ? WHERE schema_id = ?")
                ->execute([$factor, $factor, $schema_id]);
        }
        $db->prepare("UPDATE schemas_usine SET canvas_w = canvas_w " . ($mode === 'grow' ? '*' : '/') . " ? WHERE id = ?")->execute([$factor, $schema_id]);
    } else {
        return false;
    }
    return true;
}

function schema_usine_import_geometry($db, $schema_id, $template_key) {
    require __DIR__ . '/schema_usine_geometry.php';
    // NOTE : require exécuté dans cette fonction => $SALLE_TRI_SHAPES etc. sont des variables
    // locales à cette portée (PHP ne les pose PAS dans $GLOBALS), on les lit donc directement.

    if ($template_key === 'salle_tri') {
        $shapes = $SALLE_TRI_SHAPES;
        $labels = $SALLE_TRI_LABELS;
        $map = su_salle_tri_label_map();
    } elseif ($template_key === 'conditionnement') {
        $shapes = $CONDI_SHAPES;
        $labels = $CONDI_LABELS;
        $map = su_condi_label_map();
    } else {
        return;
    }

    $claimed = array_fill(0, count($shapes), false);

    // Phase A — on détermine d'abord QUI réclame quelle forme décorative, sans encore rien
    // écrire en base (nécessaire pour connaître à l'avance le nombre de formes décoratives
    // restantes et leur donner un ordre d'empilement inférieur — voir Phase B/C ci-dessous).
    $matches = [];
    foreach (su_match_labels($labels, $map) as $match) {
        $lbl = $match['label'];
        $bestI = -1; $bestDist = 999999;
        foreach ($shapes as $i => $s) {
            if ($claimed[$i]) continue;
            $sc_x = $s['x'] + $s['w'] / 2; $sc_y = $s['y'] + $s['h'] / 2;
            $lc_x = $lbl['x'] + $lbl['w'] / 2; $lc_y = $lbl['y'] + $lbl['h'] / 2;
            $d = pow($sc_x - $lc_x, 2) + pow($sc_y - $lc_y, 2);
            if ($d < $bestDist) { $bestDist = $d; $bestI = $i; }
        }

        if ($bestI >= 0 && $bestDist < 9.0) {
            $claimed[$bestI] = true;
            $s = $shapes[$bestI];
            $geo = ['x' => $s['x'], 'y' => $s['y'], 'w' => $s['w'], 'h' => $s['h'], 'rot' => $s['rot'],
                'fill' => su_resolve_color($s['fill']) ?? '#cccccc', 'border' => su_resolve_color($s['line']), 'shape' => su_border_radius_pct($s['shape'])];
        } else {
            $geo = ['x' => $lbl['x'], 'y' => $lbl['y'], 'w' => max($lbl['w'], 3), 'h' => max($lbl['h'], 3), 'rot' => 0,
                'fill' => '#e2e6ea', 'border' => '#adb5bd', 'shape' => 'rect'];
        }
        $matches[] = ['zoneKey' => $match['zone_key'], 'text' => $lbl['text'], 'geo' => $geo];
    }

    // Phase B — formes décoratives restantes (tuyaux, cadres, panneaux) en premier, avec un
    // ordre d'empilement bas : elles doivent rester DERRIÈRE les machines/vis, jamais devant.
    // Une forme libre ("freeform") qui couvre presque tout le canevas est presque toujours un
    // simple contour/cadre dans le PowerPoint d'origine (pas un aplat de couleur) : on l'ignore
    // plutôt que de la reproduire en gros pavé de couleur qui masquerait tout le reste.
    $ordre = 0;
    foreach ($shapes as $i => $s) {
        if ($claimed[$i]) continue;
        if (in_array($s['shape'], ['line', 'straightConnector1'])) continue;
        if ($s['shape'] === '?' && $s['w'] * $s['h'] > 4000) continue; // forme libre couvrant >~40% du canevas
        $bg = su_resolve_color($s['fill']);
        if (!$bg) continue;
        $insD = $db->prepare("INSERT INTO schema_zones (schema_id, zone_key, label, pos_x, pos_y, pos_w, pos_h, rotation, fill_color, border_color, shape_type, est_clickable, ordre) VALUES (?, ?, '', ?, ?, ?, ?, ?, ?, ?, ?, 0, ?)");
        $insD->execute([$schema_id, 'deco_' . $ordre, $s['x'], $s['y'], $s['w'], $s['h'], $s['rot'], $bg, su_resolve_color($s['line']), su_border_radius_pct($s['shape']), $ordre]);
        $ordre++;
    }

    // Phase C — zones labellisées (vis, tapis, machines, aperçu) par-dessus, ordre plus élevé.
    foreach ($matches as $m) {
        $geo = $m['geo'];
        if ($m['zoneKey'] === null) {
            $insZ = $db->prepare("INSERT INTO schema_zones (schema_id, zone_key, label, pos_x, pos_y, pos_w, pos_h, rotation, fill_color, border_color, shape_type, est_clickable, ordre) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?)");
            $insZ->execute([$schema_id, 'ghost_' . $ordre, $m['text'], $geo['x'], $geo['y'], $geo['w'], $geo['h'], $geo['rot'], $geo['fill'], $geo['border'], $geo['shape'], $ordre]);
        } else {
            $upd = $db->prepare("UPDATE schema_zones SET pos_x=?, pos_y=?, pos_w=?, pos_h=?, rotation=?, fill_color=?, border_color=?, shape_type=?, ordre=? WHERE schema_id=? AND zone_key=?");
            $upd->execute([$geo['x'], $geo['y'], $geo['w'], $geo['h'], $geo['rot'], $geo['fill'], $geo['border'], $geo['shape'], $ordre, $schema_id, $m['zoneKey']]);
        }
        $ordre++;
    }

    $db->prepare("UPDATE schemas_usine SET geometrie_migree = 1 WHERE id = ?")->execute([$schema_id]);
}
