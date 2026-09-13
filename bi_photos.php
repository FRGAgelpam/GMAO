<?php
require_once __DIR__ . '/session_init.php';
require 'db.php';
require_once __DIR__ . '/csrf.php';

header("Content-Type: application/json; charset=utf-8");

if (!isset($_SESSION['user']) || !isset($_SESSION['role'])) {
    echo json_encode(['success' => false, 'error' => 'Accès refusé.']);
    exit();
}
$role = $_SESSION['role'];
$user = $_SESSION['user'];
$is_staff = in_array($role, ['admin', 'technicien'], true);

$db->exec("CREATE TABLE IF NOT EXISTS taches_photos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    task_id VARCHAR(255),
    nom_original VARCHAR(255),
    chemin VARCHAR(255),
    taille INT,
    type_fichier VARCHAR(20),
    uploade_par VARCHAR(100),
    uploade_le DATETIME DEFAULT CURRENT_TIMESTAMP
)");

// Convertit une valeur php.ini du style "20M"/"2G"/"512K" en octets, pour comparer à CONTENT_LENGTH.
function bip_ini_en_octets($valeur) {
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

// Compression automatique des photos, façon "Réduire la taille de l'image" d'Outlook : redimensionne
// (max 1920px de côté) et recompresse en place pour réduire la place prise sur le serveur. HEIC (photos
// iPhone par défaut) n'est pas géré par GD et reste donc tel quel, non compressé.
function bip_compresser_image($chemin, $ext) {
    $ext = strtolower($ext);
    if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true)) { return; }

    $tailleAvant = @filesize($chemin);
    if ($tailleAvant === false || $tailleAvant < 500 * 1024) { return; } // déjà léger, inutile d'y toucher

    try {
        $info = @getimagesize($chemin);
        if (!$info) { return; }
        $largeur = $info[0];
        $hauteur = $info[1];

        switch ($ext) {
            case 'jpg':
            case 'jpeg': $image = @imagecreatefromjpeg($chemin); break;
            case 'png':  $image = @imagecreatefrompng($chemin); break;
            case 'webp': $image = @imagecreatefromwebp($chemin); break;
            default:     $image = false;
        }
        if (!$image) { return; }

        // Remet à l'endroit une photo de téléphone dont la rotation n'est stockée que dans l'EXIF
        // (sinon la recompression perd cette info et l'image ressort de travers dans certains viewers).
        if (($ext === 'jpg' || $ext === 'jpeg') && function_exists('exif_read_data')) {
            $exif = @exif_read_data($chemin);
            if (!empty($exif['Orientation']) && in_array((int)$exif['Orientation'], [3, 6, 8], true)) {
                $angle = [3 => 180, 6 => -90, 8 => 90][(int)$exif['Orientation']];
                $tourne = imagerotate($image, $angle, 0);
                if ($tourne !== false) { imagedestroy($image); $image = $tourne; }
                $largeur = imagesx($image);
                $hauteur = imagesy($image);
            }
        }

        $maxCote = 1920;
        if ($largeur > $maxCote || $hauteur > $maxCote) {
            $ratio = min($maxCote / $largeur, $maxCote / $hauteur);
            $largeurCible = max(1, (int)round($largeur * $ratio));
            $hauteurCible = max(1, (int)round($hauteur * $ratio));
            $redim = imagecreatetruecolor($largeurCible, $hauteurCible);
            if ($ext === 'png') { imagealphablending($redim, false); imagesavealpha($redim, true); }
            imagecopyresampled($redim, $image, 0, 0, 0, 0, $largeurCible, $hauteurCible, $largeur, $hauteur);
            imagedestroy($image);
            $image = $redim;
        }

        $tmp = $chemin . '.tmp_compress';
        $ok = false;
        switch ($ext) {
            case 'jpg':
            case 'jpeg': $ok = imagejpeg($image, $tmp, 80); break;
            case 'png':  $ok = imagepng($image, $tmp, 6); break;
            case 'webp': $ok = imagewebp($image, $tmp, 80); break;
        }
        imagedestroy($image);

        if ($ok && is_file($tmp)) {
            $tailleApres = filesize($tmp);
            // On ne garde la version compressée que si elle apporte un vrai gain — jamais de version
            // "compressée" plus grosse que l'originale écrasant celle-ci pour rien.
            if ($tailleApres > 0 && $tailleApres < $tailleAvant) {
                rename($tmp, $chemin);
                @chmod($chemin, 0664);
            } else {
                @unlink($tmp);
            }
        }
    } catch (Throwable $e) {
        error_log("bi_photos.php bip_compresser_image: " . $e->getMessage());
        // Ne bloque jamais l'upload si la compression échoue : la photo d'origine reste utilisable.
    }
}

// Message clair selon le code d'erreur PHP d'upload (UPLOAD_ERR_*).
function bip_message_erreur_upload($code) {
    switch ($code) {
        case UPLOAD_ERR_INI_SIZE:
        case UPLOAD_ERR_FORM_SIZE:
            return 'Photo trop volumineuse : la limite du serveur est de ' . ini_get('upload_max_filesize') . '.';
        case UPLOAD_ERR_PARTIAL:
            return 'La photo a été envoyée partiellement (connexion interrompue) — réessaie.';
        case UPLOAD_ERR_NO_FILE:
            return 'Aucune photo reçue.';
        default:
            return 'Échec de l\'envoi de la photo (code ' . $code . ').';
    }
}

// --- LECTURE ---
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $action = $_GET['action'] ?? '';
    if ($action === 'list') {
        $task_id = trim($_GET['task_id'] ?? '');
        if ($task_id === '') { echo json_encode(['success' => false, 'error' => 'task_id manquant.']); exit(); }
        $stmt = $db->prepare("SELECT id, nom_original, chemin, taille, uploade_par, uploade_le FROM taches_photos WHERE task_id = ? ORDER BY uploade_le ASC");
        $stmt->execute([$task_id]);
        echo json_encode([
            'success' => true,
            'photos' => $stmt->fetchAll(PDO::FETCH_ASSOC),
            'csrf_token' => csrf_token(),
            'is_staff' => $is_staff,
        ]);
        exit();
    }
    echo json_encode(['success' => true, 'csrf_token' => csrf_token()]);
    exit();
}

// --- ÉCRITURE ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Même piège que fiche_vie_machine.php : si le fichier dépasse post_max_size, PHP vide $_POST
    // et $_FILES sans erreur exploitable — on le détecte via CONTENT_LENGTH avant le CSRF.
    $postMaxOctets = bip_ini_en_octets(ini_get('post_max_size'));
    if ($postMaxOctets > 0 && empty($_POST) && empty($_FILES) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > $postMaxOctets) {
        echo json_encode(['success' => false, 'error' => 'Photo trop volumineuse : la limite du serveur est de ' . ini_get('post_max_size') . '. Réduis la taille de la photo ou contacte l\'administrateur.']);
        exit();
    }

    $action = $_POST['action'] ?? '';
    if (!csrf_verifie($_POST['csrf_token'] ?? '')) {
        echo json_encode(['success' => false, 'error' => 'Session expirée, merci de recharger la page.']);
        exit();
    }

    $task_id = trim($_POST['task_id'] ?? '');
    if ($task_id === '') { echo json_encode(['success' => false, 'error' => 'task_id manquant.']); exit(); }

    if ($action === 'upload') {
        $stmtT = $db->prepare("SELECT demandeur, tech, statut FROM taches WHERE id = ?");
        $stmtT->execute([$task_id]);
        $tache = $stmtT->fetch(PDO::FETCH_ASSOC);
        if (!$tache) { echo json_encode(['success' => false, 'error' => "Bon d'intervention introuvable."]); exit(); }

        // Le personnel (admin/technicien) peut toujours ajouter une photo. Un compte "portail
        // demandeur" ne peut en ajouter qu'à la création de SA PROPRE demande, tant qu'elle n'a
        // pas encore été prise en charge par la maintenance (pas de fenêtre de temps arbitraire :
        // on se base sur l'état réel du BI, qui reflète cette prise en charge).
        $peutAjouter = $is_staff || ($tache['demandeur'] === $user && $tache['tech'] === 'À ATTRIBUER' && $tache['statut'] === 'EN ATTENTE');
        if (!$peutAjouter) {
            echo json_encode(['success' => false, 'error' => 'Action réservée à la maintenance une fois la demande prise en charge.']);
            exit();
        }

        if (!isset($_FILES['photo']) || $_FILES['photo']['error'] !== UPLOAD_ERR_OK) {
            echo json_encode(['success' => false, 'error' => bip_message_erreur_upload($_FILES['photo']['error'] ?? UPLOAD_ERR_NO_FILE)]);
            exit();
        }
        $extAutorisees = ['jpg', 'jpeg', 'png', 'webp', 'heic'];
        $ext = strtolower(pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, $extAutorisees)) {
            echo json_encode(['success' => false, 'error' => 'Type de fichier non autorisé (jpg, png, webp, heic uniquement).']);
            exit();
        }
        try {
            $dossierSur = preg_replace('/[^A-Za-z0-9_-]/', '_', $task_id);
            $uploadDir = __DIR__ . '/uploads/photos_bi/' . $dossierSur . '/';
            if (!is_dir($uploadDir)) { mkdir($uploadDir, 0775, true); }
            @chmod($uploadDir, 0775);
            $fileName = 'photo_' . time() . '_' . rand(100, 999) . '.' . $ext;
            $cheminDisque = $uploadDir . $fileName;
            $cheminRelatif = 'uploads/photos_bi/' . $dossierSur . '/' . $fileName;

            if (move_uploaded_file($_FILES['photo']['tmp_name'], $cheminDisque)) {
                @chmod($cheminDisque, 0664);
                bip_compresser_image($cheminDisque, $ext);
                $tailleFinale = @filesize($cheminDisque) ?: $_FILES['photo']['size'];
                $stmt = $db->prepare("INSERT INTO taches_photos (task_id, nom_original, chemin, taille, type_fichier, uploade_par) VALUES (?, ?, ?, ?, ?, ?)");
                $stmt->execute([$task_id, $_FILES['photo']['name'], $cheminRelatif, $tailleFinale, $ext, $user]);
                ajouterLog($db, $user, "Bon d'intervention", "Photo ajoutée sur le BI " . $task_id);
                echo json_encode(['success' => true, 'id' => $db->lastInsertId(), 'chemin' => $cheminRelatif]);
            } else {
                echo json_encode(['success' => false, 'error' => "Échec de l'enregistrement de la photo."]);
            }
        } catch (Exception $e) {
            error_log("bi_photos.php upload: " . $e->getMessage());
            echo json_encode(['success' => false, 'error' => 'Erreur serveur.']);
        }
        exit();
    }

    if ($action === 'delete') {
        if (!$is_staff) {
            echo json_encode(['success' => false, 'error' => 'Action réservée à la maintenance.']);
            exit();
        }
        $photo_id = (int)($_POST['photo_id'] ?? 0);
        try {
            $stmt = $db->prepare("SELECT * FROM taches_photos WHERE id = ? AND task_id = ?");
            $stmt->execute([$photo_id, $task_id]);
            $photo = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($photo) {
                $chemin = __DIR__ . '/' . $photo['chemin'];
                if (is_file($chemin)) { @unlink($chemin); }
                $db->prepare("DELETE FROM taches_photos WHERE id = ?")->execute([$photo_id]);
                ajouterLog($db, $user, "Bon d'intervention", "Photo supprimée sur le BI " . $task_id);
            }
            echo json_encode(['success' => true]);
        } catch (Exception $e) {
            error_log("bi_photos.php delete: " . $e->getMessage());
            echo json_encode(['success' => false, 'error' => 'Erreur serveur.']);
        }
        exit();
    }

    echo json_encode(['success' => false, 'error' => 'Action inconnue.']);
    exit();
}

echo json_encode(['success' => false, 'error' => 'Méthode non autorisée.']);
