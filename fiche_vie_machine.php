<?php
require_once __DIR__ . '/session_init.php';
require 'db.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/schema_usine_data.php';

header("Content-Type: application/json; charset=utf-8");

if (!isset($_SESSION['user']) || !isset($_SESSION['role']) || !in_array($_SESSION['role'], ['admin', 'technicien'])) {
    echo json_encode(['success' => false, 'error' => 'Accès refusé.']);
    exit();
}
$is_admin = ($_SESSION['role'] === 'admin');
$user = $_SESSION['user'];

schema_usine_bootstrap($db);

// Convertit une valeur php.ini du style "20M"/"2G"/"512K" en octets, pour comparer à CONTENT_LENGTH.
function fv_ini_en_octets($valeur) {
    $valeur = trim((string)$valeur);
    if ($valeur === '') { return 0; }
    $unite = strtoupper(substr($valeur, -1));
    $nombre = (int)$valeur;
    switch ($unite) {
        case 'G': return $nombre * 1024 * 1024 * 1024;
        case 'M': return $nombre * 1024 * 1024;
        case 'K': return $nombre * 1024;
        default: return (int)$valeur;
    }
}

// Message clair selon le code d'erreur PHP d'upload (UPLOAD_ERR_*), au lieu d'un "Aucun fichier
// reçu" générique qui ne dit pas à l'utilisateur que c'est un problème de taille.
function fv_message_erreur_upload($code) {
    switch ($code) {
        case UPLOAD_ERR_INI_SIZE:
        case UPLOAD_ERR_FORM_SIZE:
            return 'Fichier trop volumineux : la limite du serveur est de ' . ini_get('upload_max_filesize') . '.';
        case UPLOAD_ERR_PARTIAL:
            return 'Le fichier a été envoyé partiellement (connexion interrompue) — réessaie.';
        case UPLOAD_ERR_NO_FILE:
            return 'Aucun fichier reçu.';
        default:
            return 'Échec de l\'envoi du fichier (code ' . $code . ').';
    }
}

function fv_refuser_si_pas_admin($is_admin) {
    if (!$is_admin) {
        echo json_encode(['success' => false, 'error' => 'Action réservée aux administrateurs.']);
        exit();
    }
}

function fv_get_zone($db, $zone_id) {
    $stmt = $db->prepare("SELECT z.*, s.nom AS schema_nom FROM schema_zones z JOIN schemas_usine s ON s.id = z.schema_id WHERE z.id = ?");
    $stmt->execute([$zone_id]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

// --- LECTURE COMPLÈTE D'UNE FICHE ---
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $zone_id = (int)($_GET['zone_id'] ?? 0);
    $zone = fv_get_zone($db, $zone_id);
    if (!$zone) { echo json_encode(['success' => false, 'error' => 'Zone introuvable.']); exit(); }

    $stmtF = $db->prepare("SELECT * FROM fiches_vie WHERE zone_id = ?");
    $stmtF->execute([$zone_id]);
    $fiche = $stmtF->fetch(PDO::FETCH_ASSOC);
    if (!$fiche) {
        $db->prepare("INSERT INTO fiches_vie (zone_id) VALUES (?)")->execute([$zone_id]);
        $stmtF->execute([$zone_id]);
        $fiche = $stmtF->fetch(PDO::FETCH_ASSOC);
    }

    $historique = null;
    if (!empty($zone['machine_id'])) {
        $stmtM = $db->prepare("SELECT * FROM machines WHERE id = ?");
        $stmtM->execute([$zone['machine_id']]);
        $m = $stmtM->fetch(PDO::FETCH_ASSOC);
        if ($m) {
            $stmtT = $db->prepare("SELECT id, num_bi, date, statut, description, tech, prio, compte_rendu, type, casse, is_sous_traitant
                FROM taches
                WHERE equip = ? AND usine = ? AND secteur = ? AND IFNULL(ligne, '') = ? AND IFNULL(zone, '') = ?
                ORDER BY date DESC");
            $stmtT->execute([$m['nom_machine'], $m['usine'], $m['secteur'], $m['ligne'] ?? '', $m['zone'] ?? '']);
            $historique = ['machine' => $m, 'bons' => $stmtT->fetchAll(PDO::FETCH_ASSOC)];
        }
    }

    $stmtI = $db->prepare("SELECT * FROM fiche_interventions WHERE zone_id = ? ORDER BY date_saisie DESC");
    $stmtI->execute([$zone_id]);
    $interventions = $stmtI->fetchAll(PDO::FETCH_ASSOC);

    $stmtD = $db->prepare("SELECT * FROM fiche_documents WHERE zone_id = ? ORDER BY uploade_le DESC");
    $stmtD->execute([$zone_id]);
    $documents = $stmtD->fetchAll(PDO::FETCH_ASSOC);

    $stmtV = $db->prepare("SELECT * FROM fiche_devis WHERE zone_id = ? ORDER BY uploade_le DESC");
    $stmtV->execute([$zone_id]);
    $devis = $stmtV->fetchAll(PDO::FETCH_ASSOC);

    $stmtP = $db->prepare("SELECT * FROM fiche_pannes_memo WHERE zone_id = ? ORDER BY date_saisie DESC");
    $stmtP->execute([$zone_id]);
    $pannes_memo = $stmtP->fetchAll(PDO::FETCH_ASSOC);

    $machines_dispo = null;
    if ($is_admin) {
        $machines_dispo = $db->query("SELECT id, nom_machine, usine, secteur, ligne, zone FROM machines ORDER BY nom_machine ASC")->fetchAll(PDO::FETCH_ASSOC);
    }

    $techniciens_dispo = $db->query("SELECT username FROM utilisateurs WHERE role IN ('admin', 'technicien') ORDER BY username ASC")->fetchAll(PDO::FETCH_COLUMN);

    $composant_types = $db->query("SELECT * FROM composant_types ORDER BY ordre ASC")->fetchAll(PDO::FETCH_ASSOC);
    $stmtC = $db->prepare("SELECT composant_type_id, valeur FROM fiche_composants WHERE zone_id = ?");
    $stmtC->execute([$zone_id]);
    $composants = $stmtC->fetchAll(PDO::FETCH_ASSOC);

    $types_equipement = $db->query("SELECT id, nom FROM types_equipement ORDER BY ordre ASC")->fetchAll(PDO::FETCH_ASSOC);
    $type_equipement_composants = [];
    foreach ($db->query("SELECT type_equipement_id, composant_type_id FROM type_equipement_composants") as $r) {
        $type_equipement_composants[(int)$r['type_equipement_id']][] = (int)$r['composant_type_id'];
    }

    echo json_encode([
        'success' => true,
        'zone' => $zone,
        'fiche' => $fiche,
        'historique' => $historique,
        'interventions' => $interventions,
        'documents' => $documents,
        'devis' => $devis,
        'pannes_memo' => $pannes_memo,
        'machines_dispo' => $machines_dispo,
        'techniciens_dispo' => $techniciens_dispo,
        'composant_types' => $composant_types,
        'composants' => $composants,
        'types_equipement' => $types_equipement,
        'type_equipement_composants' => $type_equipement_composants,
        'is_admin' => $is_admin,
        'csrf_token' => csrf_token(),
    ]);
    exit();
}

// --- ACTIONS D'ÉCRITURE ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Piège classique PHP : si le fichier envoyé dépasse post_max_size, PHP vide $_POST et $_FILES
    // SANS remonter d'erreur exploitable — la requête ressemblait alors à une session expirée (le
    // csrf_token, vidé lui aussi, ne correspondait plus à rien), ce qui masquait la vraie cause à
    // l'utilisateur. On détecte ce cas via CONTENT_LENGTH avant même la vérification CSRF.
    $postMaxOctets = fv_ini_en_octets(ini_get('post_max_size'));
    if ($postMaxOctets > 0 && empty($_POST) && empty($_FILES) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > $postMaxOctets) {
        echo json_encode(['success' => false, 'error' => 'Fichier trop volumineux : la limite du serveur est de ' . ini_get('post_max_size') . '. Réduis la taille du fichier ou contacte l\'administrateur.']);
        exit();
    }

    $action = $_POST['action'] ?? '';
    if (!csrf_verifie($_POST['csrf_token'] ?? '')) {
        echo json_encode(['success' => false, 'error' => 'Session expirée, merci de recharger la page.']);
        exit();
    }
    $zone_id = (int)($_POST['zone_id'] ?? 0);
    $zone = fv_get_zone($db, $zone_id);
    if (!$zone) { echo json_encode(['success' => false, 'error' => 'Zone introuvable.']); exit(); }

    if ($action === 'update_refs') {
        try {
            $stmt = $db->prepare("UPDATE fiches_vie SET repere_automate=?, notes=?, maj_par=?, maj_le=NOW() WHERE zone_id=?");
            $stmt->execute([
                trim($_POST['repere_automate'] ?? ''),
                trim($_POST['notes'] ?? ''),
                $user,
                $zone_id,
            ]);
            $composantsPost = $_POST['composants'] ?? [];
            if (is_array($composantsPost)) {
                $updComposant = $db->prepare("UPDATE fiche_composants SET valeur = ? WHERE zone_id = ? AND composant_type_id = ?");
                foreach ($composantsPost as $typeId => $valeur) {
                    $updComposant->execute([trim($valeur), $zone_id, (int)$typeId]);
                }
            }
            ajouterLog($db, $user, "Fiche de vie machine", "Références techniques mises à jour pour « " . $zone['label'] . " »");
            echo json_encode(['success' => true]);
        } catch (Exception $e) {
            error_log("fiche_vie_machine.php update_refs: " . $e->getMessage());
            echo json_encode(['success' => false, 'error' => 'Erreur serveur.']);
        }
        exit();
    }

    if ($action === 'update_composants_selection') {
        $selectionIds = array_map('intval', $_POST['composant_type_ids'] ?? []);
        try {
            $existants = $db->prepare("SELECT composant_type_id FROM fiche_composants WHERE zone_id = ?");
            $existants->execute([$zone_id]);
            $existantsIds = array_map('intval', $existants->fetchAll(PDO::FETCH_COLUMN));
            $aAjouter = array_diff($selectionIds, $existantsIds);
            $aRetirer = array_diff($existantsIds, $selectionIds);
            $insert = $db->prepare("INSERT INTO fiche_composants (zone_id, composant_type_id, valeur) VALUES (?, ?, '')");
            foreach ($aAjouter as $tid) { $insert->execute([$zone_id, $tid]); }
            if ($aRetirer) {
                $in = implode(',', array_fill(0, count($aRetirer), '?'));
                $db->prepare("DELETE FROM fiche_composants WHERE zone_id = ? AND composant_type_id IN ($in)")
                    ->execute(array_merge([$zone_id], array_values($aRetirer)));
            }
            ajouterLog($db, $user, "Fiche de vie machine", "Liste de matériel mise à jour pour « " . $zone['label'] . " »");
            echo json_encode(['success' => true]);
        } catch (Exception $e) {
            error_log("fiche_vie_machine.php update_composants_selection: " . $e->getMessage());
            echo json_encode(['success' => false, 'error' => 'Erreur serveur.']);
        }
        exit();
    }

    if ($action === 'add_intervention') {
        $texte = trim($_POST['texte'] ?? '');
        $technicien = trim($_POST['technicien'] ?? '');
        $dateIntervention = trim($_POST['date_intervention'] ?? '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateIntervention)) { $dateIntervention = date('Y-m-d'); }
        $statut = $_POST['statut'] ?? '';
        if (!in_array($statut, ['', 'ok', 'pas_ok'], true)) { $statut = ''; }
        if ($texte === '') { echo json_encode(['success' => false, 'error' => 'La description ne peut pas être vide.']); exit(); }
        try {
            $stmt = $db->prepare("INSERT INTO fiche_interventions (zone_id, auteur, texte, technicien, date_intervention, statut) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([$zone_id, $user, $texte, $technicien, $dateIntervention, $statut]);
            ajouterLog($db, $user, "Fiche de vie machine", "Intervention ajoutée sur « " . $zone['label'] . " »");
            echo json_encode(['success' => true]);
        } catch (Exception $e) {
            error_log("fiche_vie_machine.php add_intervention: " . $e->getMessage());
            echo json_encode(['success' => false, 'error' => 'Erreur serveur.']);
        }
        exit();
    }

    if ($action === 'delete_intervention') {
        $interv_id = (int)($_POST['interv_id'] ?? 0);
        try {
            $stmt = $db->prepare("SELECT * FROM fiche_interventions WHERE id = ? AND zone_id = ?");
            $stmt->execute([$interv_id, $zone_id]);
            $interv = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($interv) {
                $db->prepare("DELETE FROM fiche_interventions WHERE id = ?")->execute([$interv_id]);
                ajouterLog($db, $user, "Fiche de vie machine", "Intervention supprimée sur « " . $zone['label'] . " »");
            }
            echo json_encode(['success' => true]);
        } catch (Exception $e) {
            error_log("fiche_vie_machine.php delete_intervention: " . $e->getMessage());
            echo json_encode(['success' => false, 'error' => 'Erreur serveur.']);
        }
        exit();
    }

    if ($action === 'add_memo') {
        $constat = trim($_POST['constat'] ?? '');
        $solution = trim($_POST['solution'] ?? '');
        if ($constat === '') { echo json_encode(['success' => false, 'error' => 'La panne constatée ne peut pas être vide.']); exit(); }
        try {
            $stmt = $db->prepare("INSERT INTO fiche_pannes_memo (zone_id, auteur, constat, solution) VALUES (?, ?, ?, ?)");
            $stmt->execute([$zone_id, $user, $constat, $solution]);
            ajouterLog($db, $user, "Fiche de vie machine", "Mémo panne ajouté sur « " . $zone['label'] . " »");
            echo json_encode(['success' => true]);
        } catch (Exception $e) {
            error_log("fiche_vie_machine.php add_memo: " . $e->getMessage());
            echo json_encode(['success' => false, 'error' => 'Erreur serveur.']);
        }
        exit();
    }

    if ($action === 'update_memo') {
        $memo_id = (int)($_POST['memo_id'] ?? 0);
        $constat = trim($_POST['constat'] ?? '');
        $solution = trim($_POST['solution'] ?? '');
        if ($constat === '') { echo json_encode(['success' => false, 'error' => 'La panne constatée ne peut pas être vide.']); exit(); }
        try {
            $stmt = $db->prepare("UPDATE fiche_pannes_memo SET constat = ?, solution = ? WHERE id = ? AND zone_id = ?");
            $stmt->execute([$constat, $solution, $memo_id, $zone_id]);
            ajouterLog($db, $user, "Fiche de vie machine", "Mémo panne modifié sur « " . $zone['label'] . " »");
            echo json_encode(['success' => true]);
        } catch (Exception $e) {
            error_log("fiche_vie_machine.php update_memo: " . $e->getMessage());
            echo json_encode(['success' => false, 'error' => 'Erreur serveur.']);
        }
        exit();
    }

    if ($action === 'delete_memo') {
        fv_refuser_si_pas_admin($is_admin);
        $memo_id = (int)($_POST['memo_id'] ?? 0);
        try {
            $stmt = $db->prepare("SELECT * FROM fiche_pannes_memo WHERE id = ? AND zone_id = ?");
            $stmt->execute([$memo_id, $zone_id]);
            $memo = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($memo) {
                $db->prepare("DELETE FROM fiche_pannes_memo WHERE id = ?")->execute([$memo_id]);
                ajouterLog($db, $user, "Fiche de vie machine", "Mémo panne supprimé sur « " . $zone['label'] . " »");
            }
            echo json_encode(['success' => true]);
        } catch (Exception $e) {
            error_log("fiche_vie_machine.php delete_memo: " . $e->getMessage());
            echo json_encode(['success' => false, 'error' => 'Erreur serveur.']);
        }
        exit();
    }

    if ($action === 'upload_document') {
        fv_refuser_si_pas_admin($is_admin);
        if (!isset($_FILES['document']) || $_FILES['document']['error'] !== UPLOAD_ERR_OK) {
            echo json_encode(['success' => false, 'error' => fv_message_erreur_upload($_FILES['document']['error'] ?? UPLOAD_ERR_NO_FILE)]);
            exit();
        }
        $extAutorisees = ['pdf', 'jpg', 'jpeg', 'png', 'doc', 'docx', 'xls', 'xlsx'];
        $ext = strtolower(pathinfo($_FILES['document']['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, $extAutorisees)) {
            echo json_encode(['success' => false, 'error' => 'Type de fichier non autorisé (pdf, jpg, png, doc, xls uniquement).']);
            exit();
        }
        try {
            $nomDossier = $zone_id . '_' . schema_usine_slug($zone['label']);
            $uploadDir = __DIR__ . '/uploads/documentation_machines/' . $nomDossier . '/';
            if (!is_dir($uploadDir)) { mkdir($uploadDir, 0775, true); }
            @chmod($uploadDir, 0775);
            $fileName = 'doc_' . time() . '_' . rand(100, 999) . '.' . $ext;
            $cheminDisque = $uploadDir . $fileName;
            $cheminRelatif = 'uploads/documentation_machines/' . $nomDossier . '/' . $fileName;

            if (move_uploaded_file($_FILES['document']['tmp_name'], $cheminDisque)) {
                @chmod($cheminDisque, 0664);
                $stmt = $db->prepare("INSERT INTO fiche_documents (zone_id, nom_original, chemin, taille, type_fichier, uploade_par) VALUES (?, ?, ?, ?, ?, ?)");
                $stmt->execute([$zone_id, $_FILES['document']['name'], $cheminRelatif, $_FILES['document']['size'], $ext, $user]);
                ajouterLog($db, $user, "Fiche de vie machine", "Document ajouté sur « " . $zone['label'] . " » : " . $_FILES['document']['name']);
                echo json_encode(['success' => true]);
            } else {
                echo json_encode(['success' => false, 'error' => "Échec de l'enregistrement du fichier."]);
            }
        } catch (Exception $e) {
            error_log("fiche_vie_machine.php upload_document: " . $e->getMessage());
            echo json_encode(['success' => false, 'error' => 'Erreur serveur.']);
        }
        exit();
    }

    if ($action === 'delete_document') {
        fv_refuser_si_pas_admin($is_admin);
        $doc_id = (int)($_POST['doc_id'] ?? 0);
        try {
            $stmt = $db->prepare("SELECT * FROM fiche_documents WHERE id = ? AND zone_id = ?");
            $stmt->execute([$doc_id, $zone_id]);
            $doc = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($doc) {
                $chemin = __DIR__ . '/' . $doc['chemin'];
                if (is_file($chemin)) { @unlink($chemin); }
                $db->prepare("DELETE FROM fiche_documents WHERE id = ?")->execute([$doc_id]);
                ajouterLog($db, $user, "Fiche de vie machine", "Document supprimé sur « " . $zone['label'] . " » : " . $doc['nom_original']);
            }
            echo json_encode(['success' => true]);
        } catch (Exception $e) {
            error_log("fiche_vie_machine.php delete_document: " . $e->getMessage());
            echo json_encode(['success' => false, 'error' => 'Erreur serveur.']);
        }
        exit();
    }

    if ($action === 'upload_devis') {
        fv_refuser_si_pas_admin($is_admin);
        if (!isset($_FILES['document']) || $_FILES['document']['error'] !== UPLOAD_ERR_OK) {
            echo json_encode(['success' => false, 'error' => fv_message_erreur_upload($_FILES['document']['error'] ?? UPLOAD_ERR_NO_FILE)]);
            exit();
        }
        $extAutorisees = ['pdf', 'jpg', 'jpeg', 'png', 'doc', 'docx', 'xls', 'xlsx'];
        $ext = strtolower(pathinfo($_FILES['document']['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, $extAutorisees)) {
            echo json_encode(['success' => false, 'error' => 'Type de fichier non autorisé (pdf, jpg, png, doc, xls uniquement).']);
            exit();
        }
        try {
            $baseDir = __DIR__ . '/uploads/devis_machines/';
            if (!is_dir($baseDir)) { mkdir($baseDir, 0775, true); }
            @chmod($baseDir, 0775);
            $nomDossier = $zone_id . '_' . schema_usine_slug($zone['label']);
            $uploadDir = $baseDir . $nomDossier . '/';
            if (!is_dir($uploadDir)) { mkdir($uploadDir, 0775, true); }
            @chmod($uploadDir, 0775);
            $fileName = 'devis_' . time() . '_' . rand(100, 999) . '.' . $ext;
            $cheminDisque = $uploadDir . $fileName;
            $cheminRelatif = 'uploads/devis_machines/' . $nomDossier . '/' . $fileName;

            if (move_uploaded_file($_FILES['document']['tmp_name'], $cheminDisque)) {
                @chmod($cheminDisque, 0664);
                $stmt = $db->prepare("INSERT INTO fiche_devis (zone_id, nom_original, chemin, taille, type_fichier, uploade_par) VALUES (?, ?, ?, ?, ?, ?)");
                $stmt->execute([$zone_id, $_FILES['document']['name'], $cheminRelatif, $_FILES['document']['size'], $ext, $user]);
                ajouterLog($db, $user, "Fiche de vie machine", "Devis ajouté sur « " . $zone['label'] . " » : " . $_FILES['document']['name']);
                echo json_encode(['success' => true]);
            } else {
                echo json_encode(['success' => false, 'error' => "Échec de l'enregistrement du fichier."]);
            }
        } catch (Exception $e) {
            error_log("fiche_vie_machine.php upload_devis: " . $e->getMessage());
            echo json_encode(['success' => false, 'error' => 'Erreur serveur.']);
        }
        exit();
    }

    if ($action === 'delete_devis') {
        fv_refuser_si_pas_admin($is_admin);
        $doc_id = (int)($_POST['doc_id'] ?? 0);
        try {
            $stmt = $db->prepare("SELECT * FROM fiche_devis WHERE id = ? AND zone_id = ?");
            $stmt->execute([$doc_id, $zone_id]);
            $doc = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($doc) {
                $chemin = __DIR__ . '/' . $doc['chemin'];
                if (is_file($chemin)) { @unlink($chemin); }
                $db->prepare("DELETE FROM fiche_devis WHERE id = ?")->execute([$doc_id]);
                ajouterLog($db, $user, "Fiche de vie machine", "Devis supprimé sur « " . $zone['label'] . " » : " . $doc['nom_original']);
            }
            echo json_encode(['success' => true]);
        } catch (Exception $e) {
            error_log("fiche_vie_machine.php delete_devis: " . $e->getMessage());
            echo json_encode(['success' => false, 'error' => 'Erreur serveur.']);
        }
        exit();
    }

    if ($action === 'update_type_machine') {
        fv_refuser_si_pas_admin($is_admin);
        if (empty($zone['machine_id'])) {
            echo json_encode(['success' => false, 'error' => "Aucune machine du Parc Machine rattachée à cette fiche."]);
            exit();
        }
        $type_equipement = trim($_POST['type_equipement'] ?? '');
        // Remplace la liste de matériel par le jeu par défaut de la nouvelle catégorie (calculé et
        // confirmé côté client si ça efface des références déjà saisies) — contrairement à
        // appliquer_composants_defaut_type() qui est additive pour ne jamais rien effacer quand on
        // modifie les défauts d'un type depuis Paramètres (ça toucherait toutes les machines du type).
        // Ici on cible une seule machine sur un changement de catégorie explicite : un remplacement net
        // est ce que l'utilisateur attend.
        $selectionIds = array_map('intval', $_POST['composant_type_ids'] ?? []);
        try {
            $db->prepare("UPDATE machines SET type_equipement = ? WHERE id = ?")->execute([$type_equipement, $zone['machine_id']]);

            $existants = $db->prepare("SELECT composant_type_id FROM fiche_composants WHERE zone_id = ?");
            $existants->execute([$zone_id]);
            $existantsIds = array_map('intval', $existants->fetchAll(PDO::FETCH_COLUMN));
            $aAjouter = array_diff($selectionIds, $existantsIds);
            $aRetirer = array_diff($existantsIds, $selectionIds);
            $insert = $db->prepare("INSERT INTO fiche_composants (zone_id, composant_type_id, valeur) VALUES (?, ?, '')");
            foreach ($aAjouter as $tid) { $insert->execute([$zone_id, $tid]); }
            if ($aRetirer) {
                $in = implode(',', array_fill(0, count($aRetirer), '?'));
                $db->prepare("DELETE FROM fiche_composants WHERE zone_id = ? AND composant_type_id IN ($in)")
                    ->execute(array_merge([$zone_id], array_values($aRetirer)));
            }

            ajouterLog($db, $user, "Fiche de vie machine", "Catégorie changée pour « " . $zone['label'] . " » : " . ($type_equipement !== '' ? $type_equipement : 'Non renseigné'));
            echo json_encode(['success' => true]);
        } catch (Exception $e) {
            error_log("fiche_vie_machine.php update_type_machine: " . $e->getMessage());
            echo json_encode(['success' => false, 'error' => 'Erreur serveur.']);
        }
        exit();
    }

    if ($action === 'link_machine') {
        fv_refuser_si_pas_admin($is_admin);
        $machine_id = trim($_POST['machine_id'] ?? '');
        $machine_id = ($machine_id === '') ? null : (int)$machine_id;
        try {
            $stmt = $db->prepare("UPDATE schema_zones SET machine_id = ? WHERE id = ?");
            $stmt->execute([$machine_id, $zone_id]);
            if ($machine_id !== null) {
                $stmtType = $db->prepare("SELECT type_equipement FROM machines WHERE id = ?");
                $stmtType->execute([$machine_id]);
                $typeMachine = $stmtType->fetchColumn();
                if ($typeMachine) { appliquer_composants_defaut_type($db, $typeMachine); }
            } else {
                // Détachement : la liste de matériel cochée n'a plus de sens sans machine/catégorie
                // pour la justifier (elle avait été posée par appliquer_composants_defaut_type au
                // rattachement) — on la vide pour éviter qu'un ancien matériel "orphelin" traîne.
                $db->prepare("DELETE FROM fiche_composants WHERE zone_id = ?")->execute([$zone_id]);
            }
            ajouterLog($db, $user, "Fiche de vie machine", "Rattachement machine du parc modifié pour « " . $zone['label'] . " »");
            echo json_encode(['success' => true]);
        } catch (Exception $e) {
            error_log("fiche_vie_machine.php link_machine: " . $e->getMessage());
            echo json_encode(['success' => false, 'error' => 'Erreur serveur.']);
        }
        exit();
    }

    // Créé une machine directement depuis le picker de localisation quand elle n'existe pas
    // encore dans le Parc Machine, puis la rattache aussitôt à cette fiche de vie — mêmes
    // règles d'insertion que l'ajout de machine dans admin_machines.php (ordre = max+1 dans la zone).
    if ($action === 'create_and_link_machine') {
        fv_refuser_si_pas_admin($is_admin);
        $usine = trim($_POST['usine'] ?? '');
        $secteur = trim($_POST['secteur'] ?? '');
        $ligne = trim($_POST['ligne'] ?? '');
        $zoneLoc = trim($_POST['zone'] ?? '');
        $nom = trim($_POST['nom_machine'] ?? '');
        if ($usine === '' || $secteur === '' || $ligne === '' || $zoneLoc === '' || $nom === '') {
            echo json_encode(['success' => false, 'error' => "Emplacement incomplet ou nom manquant."]);
            exit();
        }
        try {
            $stmtMax = $db->prepare("SELECT MAX(ordre) AS max_ordre FROM machines WHERE zone = ?");
            $stmtMax->execute([$zoneLoc]);
            $resMax = $stmtMax->fetch(PDO::FETCH_ASSOC);
            $nouvel_ordre = ($resMax['max_ordre'] !== null) ? $resMax['max_ordre'] + 1 : 0;

            $insM = $db->prepare("INSERT INTO machines (usine, secteur, ligne, zone, nom_machine, ordre) VALUES (?, ?, ?, ?, ?, ?)");
            $insM->execute([$usine, $secteur, $ligne, $zoneLoc, $nom, $nouvel_ordre]);
            $machine_id = $db->lastInsertId();

            $db->prepare("UPDATE schema_zones SET machine_id = ? WHERE id = ?")->execute([$machine_id, $zone_id]);

            ajouterLog($db, $user, "Ajout Machine", "A ajouté la machine « $nom » depuis la fiche de vie « " . $zone['label'] . " »");
            echo json_encode(['success' => true, 'machine_id' => (int)$machine_id]);
        } catch (Exception $e) {
            error_log("fiche_vie_machine.php create_and_link_machine: " . $e->getMessage());
            echo json_encode(['success' => false, 'error' => 'Erreur serveur.']);
        }
        exit();
    }

    if ($action === 'rename_zone') {
        fv_refuser_si_pas_admin($is_admin);
        $new_label = trim($_POST['new_label'] ?? '');
        if ($new_label === '') { echo json_encode(['success' => false, 'error' => 'Nom invalide.']); exit(); }
        try {
            $stmt = $db->prepare("UPDATE schema_zones SET label = ? WHERE id = ?");
            $stmt->execute([$new_label, $zone_id]);
            ajouterLog($db, $user, "Fiche de vie machine", "Zone renommée : « " . $zone['label'] . " » → « $new_label »");
            echo json_encode(['success' => true]);
        } catch (Exception $e) {
            error_log("fiche_vie_machine.php rename_zone: " . $e->getMessage());
            echo json_encode(['success' => false, 'error' => 'Erreur serveur.']);
        }
        exit();
    }

    echo json_encode(['success' => false, 'error' => 'Action inconnue.']);
    exit();
}

echo json_encode(['success' => false, 'error' => 'Méthode non supportée.']);
