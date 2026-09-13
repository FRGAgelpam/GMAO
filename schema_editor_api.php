<?php
require_once __DIR__ . '/session_init.php';
require 'db.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/schema_usine_data.php';

header("Content-Type: application/json; charset=utf-8");

if (!isset($_SESSION['user']) || !isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    echo json_encode(['success' => false, 'error' => 'Action réservée aux administrateurs.']);
    exit();
}
$user = $_SESSION['user'];

schema_usine_bootstrap($db);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Méthode non supportée.']);
    exit();
}

$action = $_POST['action'] ?? '';
if (!csrf_verifie($_POST['csrf_token'] ?? '')) {
    echo json_encode(['success' => false, 'error' => 'Session expirée, merci de recharger la page.']);
    exit();
}

$SHAPES_AUTORISEES = ['rect', 'roundRect', 'ellipse', 'triangle', 'diamond', 'pentagon', 'hexagon', 'star', 'arrow_right', 'arrow_left', 'arrow_up', 'arrow_down', 'parallelogram', 'octagon', 'trapezoid', 'trapezoid_inv', 'cross', 'chevron_right', 'chevron_left', 'right_triangle', 'semicircle', 'double_arrow_h', 'double_arrow_v', 'bevel_rect', 'plate_left', 'plate_right', 'triangle_down', 'triangle_left', 'triangle_right', 'quarter_circle', 'hexagon_v', 'l_shape', 't_shape', 'rt_tl', 'rt_tr', 'rt_br', 'shield', 'ribbon_right', 'ribbon_left', 'rect_snip_1', 'rect_snip_2_same', 'rect_snip_diag', 'rect_round_1', 'heptagon', 'decagon', 'dodecagon', 'arrow_cross', 'minus_sign', 'multiply_x', 'document', 'manual_input', 'off_page_connector', 'bowtie', 'delay', 'punched_tape', 'stadium', 'elbow', 'text_only', 'convoyeur_rouleaux', 'convoyeur_rouleaux_arc', 'vis_sans_fin', 'tapis_chevron', 'robot_bras'];
$EFFETS_AUTORISES = ['none', 'shadow', 'bevel', 'inset', 'glow'];
$GRADIENT_ANGLES_AUTORISES = [0, 90, 135, 180, 270];

function seapi_clamp($v, $min, $max) { return max($min, min($max, (float)$v)); }

if ($action === 'update_geometry') {
    $zone_id = (int)($_POST['zone_id'] ?? 0);
    $shape_type = in_array($_POST['shape_type'] ?? '', $SHAPES_AUTORISEES) ? $_POST['shape_type'] : 'rect';
    $fill = preg_match('/^#[0-9a-fA-F]{6}$/', $_POST['fill_color'] ?? '') ? $_POST['fill_color'] : '#ffffff';
    $borderRaw = trim($_POST['border_color'] ?? '');
    $border = ($borderRaw !== '' && preg_match('/^#[0-9a-fA-F]{6}$/', $borderRaw)) ? $borderRaw : null;
    $gradient = !empty($_POST['gradient']) ? 1 : 0;
    $fill2Raw = trim($_POST['fill_color2'] ?? '');
    $fill2 = ($fill2Raw !== '' && preg_match('/^#[0-9a-fA-F]{6}$/', $fill2Raw)) ? $fill2Raw : null;
    $gradientAngle = in_array((int)($_POST['gradient_angle'] ?? 135), $GRADIENT_ANGLES_AUTORISES) ? (int)$_POST['gradient_angle'] : 135;
    $label = trim($_POST['label'] ?? '');
    $est_clickable = !empty($_POST['est_clickable']) ? 1 : 0;

    $fontSizeRaw = trim($_POST['font_size'] ?? '');
    $fontSize = ($fontSizeRaw !== '' && is_numeric($fontSizeRaw)) ? seapi_clamp($fontSizeRaw, 0.3, 3) : null;
    $textColorRaw = trim($_POST['text_color'] ?? '');
    $textColor = preg_match('/^#[0-9a-fA-F]{6}$/', $textColorRaw) ? $textColorRaw : null;
    $effect = in_array($_POST['effect'] ?? '', $EFFETS_AUTORISES) ? $_POST['effect'] : 'none';

    $catRaw = trim($_POST['categorie_visuelle_id'] ?? '');
    $categorieVisuelleId = null;
    if ($catRaw !== '') {
        $chkCat = $db->prepare("SELECT id FROM schema_categories_visuelles WHERE id=?");
        $chkCat->execute([(int)$catRaw]);
        $categorieVisuelleId = $chkCat->fetchColumn() ? (int)$catRaw : null;
    }

    try {
        $stmt = $db->prepare("UPDATE schema_zones SET pos_x=?, pos_y=?, pos_w=?, pos_h=?, rotation=?, text_rotation=?, font_size=?, text_color=?, text_offset_x=?, text_offset_y=?, shape_type=?, fill_color=?, fill_color2=?, gradient=?, gradient_angle=?, border_color=?, effect=?, label=?, est_clickable=?, categorie_visuelle_id=? WHERE id=?");
        $stmt->execute([
            seapi_clamp($_POST['pos_x'] ?? 0, -20, 120),
            seapi_clamp($_POST['pos_y'] ?? 0, -20, 120),
            seapi_clamp($_POST['pos_w'] ?? 1, 0.3, 100),
            seapi_clamp($_POST['pos_h'] ?? 1, 0.3, 100),
            seapi_clamp($_POST['rotation'] ?? 0, -360, 360),
            seapi_clamp($_POST['text_rotation'] ?? 0, -360, 360),
            $fontSize,
            $textColor,
            seapi_clamp($_POST['text_offset_x'] ?? 0, -200, 200),
            seapi_clamp($_POST['text_offset_y'] ?? 0, -200, 200),
            $shape_type, $fill, $fill2, $gradient, $gradientAngle, $border, $effect, $label, $est_clickable, $categorieVisuelleId, $zone_id,
        ]);
        ajouterLog($db, $user, "Éditeur schéma", "Zone #$zone_id modifiée (« $label »)");
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        error_log("schema_editor_api.php update_geometry: " . $e->getMessage());
        echo json_encode(['success' => false, 'error' => 'Erreur serveur.']);
    }
    exit();
}

if ($action === 'reorder') {
    $zone_id = (int)($_POST['zone_id'] ?? 0);
    $direction = ($_POST['direction'] ?? 'front') === 'back' ? 'back' : 'front';
    try {
        $sid = $db->prepare("SELECT schema_id FROM schema_zones WHERE id = ?");
        $sid->execute([$zone_id]);
        $schema_id = $sid->fetchColumn();
        if (!$schema_id) { echo json_encode(['success' => false, 'error' => 'Forme introuvable.']); exit(); }

        if ($direction === 'front') {
            $m = $db->prepare("SELECT COALESCE(MAX(ordre), 0) FROM schema_zones WHERE schema_id = ?");
            $m->execute([$schema_id]);
            $newOrdre = (int)$m->fetchColumn() + 1;
        } else {
            $m = $db->prepare("SELECT COALESCE(MIN(ordre), 0) FROM schema_zones WHERE schema_id = ?");
            $m->execute([$schema_id]);
            $newOrdre = (int)$m->fetchColumn() - 1;
        }
        $db->prepare("UPDATE schema_zones SET ordre = ? WHERE id = ?")->execute([$newOrdre, $zone_id]);
        echo json_encode(['success' => true, 'ordre' => $newOrdre]);
    } catch (Exception $e) {
        error_log("schema_editor_api.php reorder: " . $e->getMessage());
        echo json_encode(['success' => false, 'error' => 'Erreur serveur.']);
    }
    exit();
}

if ($action === 'add_zone') {
    $schema_id = (int)($_POST['schema_id'] ?? 0);
    try {
        $ord = $db->prepare("SELECT COALESCE(MAX(ordre), 0) + 1 FROM schema_zones WHERE schema_id = ?");
        $ord->execute([$schema_id]);
        $ordre = $ord->fetchColumn();

        $zoneKey = 'zone_' . uniqid();
        $ins = $db->prepare("INSERT INTO schema_zones (schema_id, zone_key, label, pos_x, pos_y, pos_w, pos_h, rotation, fill_color, border_color, shape_type, est_clickable, ordre) VALUES (?, ?, 'Nouvelle forme', 40, 40, 10, 8, 0, '#d6dee3', '#95a5a6', 'rect', 1, ?)");
        $ins->execute([$schema_id, $zoneKey, $ordre]);
        $zone_id = $db->lastInsertId();
        $db->prepare("INSERT INTO fiches_vie (zone_id) VALUES (?)")->execute([$zone_id]);

        ajouterLog($db, $user, "Éditeur schéma", "Nouvelle forme ajoutée (zone #$zone_id)");
        echo json_encode(['success' => true, 'zone_id' => (int)$zone_id]);
    } catch (Exception $e) {
        error_log("schema_editor_api.php add_zone: " . $e->getMessage());
        echo json_encode(['success' => false, 'error' => 'Erreur serveur.']);
    }
    exit();
}

if ($action === 'delete_zone') {
    $zone_id = (int)($_POST['zone_id'] ?? 0);
    try {
        $docs = $db->prepare("SELECT chemin FROM fiche_documents WHERE zone_id = ?");
        $docs->execute([$zone_id]);
        foreach ($docs->fetchAll(PDO::FETCH_COLUMN) as $chemin) {
            $full = __DIR__ . '/' . $chemin;
            if (is_file($full)) { @unlink($full); }
        }
        $db->prepare("DELETE FROM fiche_documents WHERE zone_id = ?")->execute([$zone_id]);
        $db->prepare("DELETE FROM fiche_interventions WHERE zone_id = ?")->execute([$zone_id]);
        $db->prepare("DELETE FROM fiche_pannes_memo WHERE zone_id = ?")->execute([$zone_id]);
        $db->prepare("DELETE FROM fiche_composants WHERE zone_id = ?")->execute([$zone_id]);
        $db->prepare("DELETE FROM fiches_vie WHERE zone_id = ?")->execute([$zone_id]);
        $db->prepare("DELETE FROM schema_zones WHERE id = ?")->execute([$zone_id]);

        ajouterLog($db, $user, "Éditeur schéma", "Forme #$zone_id supprimée");
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        error_log("schema_editor_api.php delete_zone: " . $e->getMessage());
        echo json_encode(['success' => false, 'error' => 'Erreur serveur.']);
    }
    exit();
}

if ($action === 'resize_canvas') {
    $schema_id = (int)($_POST['schema_id'] ?? 0);
    $direction = $_POST['direction'] ?? '';
    $mode = ($_POST['mode'] ?? 'grow') === 'shrink' ? 'shrink' : 'grow';
    if (!in_array($direction, ['top', 'bottom', 'left', 'right'])) {
        echo json_encode(['success' => false, 'error' => 'Direction inconnue.']);
        exit();
    }
    try {
        $ok = schema_usine_resize_canvas($db, $schema_id, $direction, 20, $mode);
        if (!$ok) {
            $msg = $mode === 'shrink' ? "Impossible de réduire en dessous de la taille d'origine." : 'Schéma introuvable.';
            echo json_encode(['success' => false, 'error' => $msg]);
            exit();
        }
        $verbe = $mode === 'shrink' ? 'réduit' : 'agrandi';
        ajouterLog($db, $user, "Éditeur schéma", "Canevas $verbe côté « $direction » (schéma #$schema_id)");
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        error_log("schema_editor_api.php resize_canvas: " . $e->getMessage());
        echo json_encode(['success' => false, 'error' => 'Erreur serveur.']);
    }
    exit();
}

echo json_encode(['success' => false, 'error' => 'Action inconnue.']);
