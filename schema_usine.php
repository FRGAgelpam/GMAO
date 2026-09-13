<?php
require_once __DIR__ . '/session_init.php';
require 'db.php';
require_once __DIR__ . '/schema_usine_data.php';

if (!isset($_SESSION['user']) || !isset($_SESSION['role']) || !in_array($_SESSION['role'], ['admin', 'technicien'])) {
    http_response_code(403);
    echo t('pm.err_acces_refuse');
    exit();
}
$is_admin = ($_SESSION['role'] === 'admin');

schema_usine_bootstrap($db);

$vue = $_GET['vue'] ?? 'liste';

function su_h($s) { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

function su_get_schema_et_zones($db, $template_key) {
    $stmt = $db->prepare("SELECT * FROM schemas_usine WHERE template_key = ?");
    $stmt->execute([$template_key]);
    $schema = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$schema) return [null, []];

    $stmtZ = $db->prepare("SELECT * FROM schema_zones WHERE schema_id = ? ORDER BY ordre ASC");
    $stmtZ->execute([$schema['id']]);
    return [$schema, $stmtZ->fetchAll(PDO::FETCH_ASSOC)];
}

// Couleur de texte claire/sombre selon la luminance du fond, pour rester lisible quelle que
// soit la couleur choisie depuis l'éditeur (formule standard 0.299R+0.587G+0.114B).
function su_text_color($hex) {
    if (!$hex || strlen($hex) < 6) return '#2c3e50';
    $hex = ltrim($hex, '#');
    if (strlen($hex) === 3) $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
    $r = hexdec(substr($hex, 0, 2)); $g = hexdec(substr($hex, 2, 2)); $b = hexdec(substr($hex, 4, 2));
    $lum = 0.299 * $r + 0.587 * $g + 0.114 * $b;
    return $lum < 150 ? '#ffffff' : '#2c3e50';
}

// Éclaircit (percent > 0) ou assombrit (percent < 0) une couleur hex — miroir PHP de suShadeColor()
// (admin_machines.php), pour générer les dégradés "3D" des formes industrielles côté rendu statique.
function su_shade_color($hex, $percent) {
    $hex = ltrim($hex ?: '#3498db', '#');
    if (strlen($hex) === 3) $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
    $r = hexdec(substr($hex, 0, 2)); $g = hexdec(substr($hex, 2, 2)); $b = hexdec(substr($hex, 4, 2));
    $adj = function($c) use ($percent) {
        $v = $c + ($percent / 100) * ($percent > 0 ? (255 - $c) : $c);
        return max(0, min(255, (int)round($v)));
    };
    return sprintf('#%02x%02x%02x', $adj($r), $adj($g), $adj($b));
}
const SU_SVG_SHAPES = ['convoyeur_rouleaux', 'convoyeur_rouleaux_arc', 'vis_sans_fin', 'tapis_chevron', 'robot_bras'];
// Nombre de rouleaux tel que l'écart réel entre deux rouleaux reste constant quelle que soit la
// largeur (%) de la forme — miroir PHP de suRollerCount() (admin_machines.php).
function su_roller_count($posW) {
    $usable = ($posW ?: 10) * 0.88;
    $pitch = 0.62;
    $gaps = max(2, (int)round($usable / $pitch));
    return $gaps + 1;
}
function su_roller_width($posW) {
    $targetPercentOfCanvas = 0.303;
    return max(0.3, min(20, ($targetPercentOfCanvas / ($posW ?: 10)) * 100));
}
// Miroir PHP exact de suIndustrialSvg() (admin_machines.php) — doit rester synchronisé pour que le
// rendu statique (ici) et le rendu live de l'éditeur produisent la même illustration.
function su_industrial_svg($shape, $fill, $id, $posW = 10, $posH = 10, $border = null) {
    $light = su_shade_color($fill, 40); $dark = su_shade_color($fill, -35);
    $hasChassis = !empty($border) && $border !== 'none';
    $steelLight = $hasChassis ? su_shade_color($border, 35) : '#c7ccd1';
    $steelMid = $hasChassis ? $border : '#9aa1a8';
    $steelDark = $hasChassis ? su_shade_color($border, -35) : '#5b6167';
    $g1 = 'su-g1-' . $id; $g2 = 'su-g2-' . $id; $g3 = 'su-g3-' . $id;
    $svgOpen = '<svg viewBox="0 0 100 100" preserveAspectRatio="' . ($shape === 'convoyeur_rouleaux_arc' ? 'xMidYMid meet' : 'none') . '" style="width:100%;height:100%;display:block;">';
    if ($shape === 'convoyeur_rouleaux') {
        $rollers = '';
        $n = su_roller_count($posW);
        $rw = su_roller_width($posW);
        $strokeW = max(0.03, $rw * 0.0625);
        for ($i = 0; $i < $n; $i++) {
            $cx = 6 + $i * (88 / ($n - 1));
            $rollers .= '<rect x="' . ($cx - $rw / 2) . '" y="20" width="' . $rw . '" height="60" rx="' . ($rw / 2) . '" fill="url(#' . $g2 . ')" stroke="' . $dark . '" stroke-width="' . $strokeW . '"/>';
        }
        $railStart = 6 - $rw / 2; $railEnd = 94 + $rw / 2; $railW = $railEnd - $railStart;
        return $svgOpen . '<defs>'
            . '<linearGradient id="' . $g1 . '" x1="0" y1="0" x2="0" y2="1"><stop offset="0%" stop-color="' . $steelLight . '"/><stop offset="45%" stop-color="' . $steelMid . '"/><stop offset="100%" stop-color="' . $steelDark . '"/></linearGradient>'
            . '<linearGradient id="' . $g2 . '" x1="0" y1="0" x2="1" y2="0"><stop offset="0%" stop-color="' . $dark . '"/><stop offset="42%" stop-color="' . $light . '"/><stop offset="58%" stop-color="' . $light . '"/><stop offset="100%" stop-color="' . $dark . '"/></linearGradient>'
            . '</defs>'
            . '<rect x="' . $railStart . '" y="12" width="' . $railW . '" height="9" rx="2" fill="url(#' . $g1 . ')"/>'
            . '<rect x="' . $railStart . '" y="79" width="' . $railW . '" height="9" rx="2" fill="url(#' . $g1 . ')"/>'
            . $rollers . '</svg>';
    }
    if ($shape === 'convoyeur_rouleaux_arc') {
        $rollers = '';
        $sizeAvg = (($posW ?: 10) + ($posH ?: 10)) / 2;
        $n = su_roller_count($sizeAvg);
        $rw = su_roller_width($sizeAvg);
        $rOut = 95; $rIn = 65; $rMid = ($rOut + $rIn) / 2; $rollerLen = $rOut - $rIn;
        $strokeW = max(0.03, $rw * 0.0625);
        for ($i = 0; $i < $n; $i++) {
            $theta = $i * (90 / ($n - 1));
            $rad = $theta * M_PI / 180;
            $cx = sin($rad) * $rMid;
            $cy = 100 - cos($rad) * $rMid;
            $rollers .= '<rect x="' . ($cx - $rw / 2) . '" y="' . ($cy - $rollerLen / 2) . '" width="' . $rw . '" height="' . $rollerLen . '" rx="' . ($rw / 2) . '" fill="url(#' . $g2 . ')" stroke="' . $dark . '" stroke-width="' . $strokeW . '" transform="rotate(' . $theta . ' ' . $cx . ' ' . $cy . ')"/>';
        }
        return $svgOpen . '<defs>'
            . '<linearGradient id="' . $g1 . '" x1="0" y1="0" x2="1" y2="1"><stop offset="0%" stop-color="' . $steelLight . '"/><stop offset="50%" stop-color="' . $steelMid . '"/><stop offset="100%" stop-color="' . $steelDark . '"/></linearGradient>'
            . '<linearGradient id="' . $g2 . '" x1="0" y1="0" x2="1" y2="0"><stop offset="0%" stop-color="' . $dark . '"/><stop offset="42%" stop-color="' . $light . '"/><stop offset="58%" stop-color="' . $light . '"/><stop offset="100%" stop-color="' . $dark . '"/></linearGradient>'
            . '</defs>'
            . '<path d="M 0 5 A 95 95 0 0 1 95 100" fill="none" stroke="url(#' . $g1 . ')" stroke-width="9" stroke-linecap="round"/>'
            . '<path d="M 0 35 A 65 65 0 0 1 65 100" fill="none" stroke="url(#' . $g1 . ')" stroke-width="9" stroke-linecap="round"/>'
            . $rollers . '</svg>';
    }
    if ($shape === 'vis_sans_fin') {
        $threads = '';
        $n = 10;
        for ($i = 0; $i < $n; $i++) {
            $cx = 6 + $i * (88 / ($n - 1));
            $threads .= '<path d="M ' . ($cx - 7) . ' 10 L ' . ($cx + 7) . ' 90" stroke="' . $dark . '" stroke-width="6.5" stroke-linecap="round"/>';
            $threads .= '<path d="M ' . ($cx - 5.3) . ' 10 L ' . ($cx + 8.7) . ' 90" stroke="' . $light . '" stroke-width="2.3" stroke-linecap="round"/>';
        }
        return $svgOpen . '<defs>'
            . '<linearGradient id="' . $g1 . '" x1="0" y1="0" x2="0" y2="1"><stop offset="0%" stop-color="' . $steelLight . '"/><stop offset="50%" stop-color="' . $steelMid . '"/><stop offset="100%" stop-color="' . $steelDark . '"/></linearGradient>'
            . '</defs>'
            . '<rect x="0" y="34" width="100" height="32" rx="16" fill="url(#' . $g1 . ')"/>'
            . $threads . '</svg>';
    }
    if ($shape === 'tapis_chevron') {
        $chevrons = '';
        $n = 5;
        for ($i = 0; $i < $n; $i++) {
            $cx = 20 + $i * 15;
            $chevrons .= '<path d="M ' . ($cx - 6) . ' 42 L ' . $cx . ' 30 L ' . ($cx + 6) . ' 42" fill="none" stroke="' . $dark . '" stroke-width="2.2" stroke-linecap="round" opacity="0.55"/>';
        }
        return $svgOpen . '<defs>'
            . '<linearGradient id="' . $g1 . '" x1="0" y1="0" x2="0" y2="1"><stop offset="0%" stop-color="' . $light . '"/><stop offset="55%" stop-color="' . $fill . '"/><stop offset="100%" stop-color="' . $dark . '"/></linearGradient>'
            . '<radialGradient id="' . $g3 . '" cx="35%" cy="35%" r="70%"><stop offset="0%" stop-color="' . $steelLight . '"/><stop offset="100%" stop-color="' . $steelDark . '"/></radialGradient>'
            . '</defs>'
            . '<circle cx="12" cy="50" r="16" fill="url(#' . $g3 . ')"/>'
            . '<circle cx="88" cy="50" r="16" fill="url(#' . $g3 . ')"/>'
            . '<rect x="12" y="30" width="76" height="40" fill="url(#' . $g1 . ')"/>'
            . $chevrons . '</svg>';
    }
    if ($shape === 'robot_bras') {
        return $svgOpen . '<defs>'
            . '<linearGradient id="' . $g1 . '" x1="0" y1="0" x2="0" y2="1"><stop offset="0%" stop-color="' . $steelLight . '"/><stop offset="50%" stop-color="' . $steelMid . '"/><stop offset="100%" stop-color="' . $steelDark . '"/></linearGradient>'
            . '<linearGradient id="' . $g2 . '" x1="0" y1="0" x2="1" y2="1"><stop offset="0%" stop-color="' . $light . '"/><stop offset="50%" stop-color="' . $fill . '"/><stop offset="100%" stop-color="' . $dark . '"/></linearGradient>'
            . '<radialGradient id="' . $g3 . '" cx="35%" cy="35%" r="70%"><stop offset="0%" stop-color="' . $light . '"/><stop offset="100%" stop-color="' . $dark . '"/></radialGradient>'
            . '</defs>'
            . '<rect x="25" y="82" width="50" height="14" rx="4" fill="url(#' . $g1 . ')"/>'
            . '<rect x="42" y="40" width="16" height="46" rx="8" fill="url(#' . $g1 . ')"/>'
            . '<circle cx="50" cy="40" r="11" fill="url(#' . $g3 . ')"/>'
            . '<rect x="46" y="18" width="42" height="13" rx="6.5" fill="url(#' . $g2 . ')" transform="rotate(-18 50 24)"/>'
            . '<circle cx="82" cy="16" r="7" fill="url(#' . $g3 . ')"/>'
            . '</svg>';
    }
    return '';
}

// Formes disponibles : rect/roundRect/ellipse via border-radius, le reste via clip-path.
function su_shape_css($shapeType) {
    $polygons = [
        'triangle' => 'polygon(50% 0%, 0% 100%, 100% 100%)',
        'diamond' => 'polygon(50% 0%, 100% 50%, 50% 100%, 0% 50%)',
        'pentagon' => 'polygon(50% 0%, 100% 38%, 82% 100%, 18% 100%, 0% 38%)',
        'hexagon' => 'polygon(25% 0%, 75% 0%, 100% 50%, 75% 100%, 25% 100%, 0% 50%)',
        'star' => 'polygon(50% 0%,61% 35%,98% 35%,68% 57%,79% 91%,50% 70%,21% 91%,32% 57%,2% 35%,39% 35%)',
        'arrow_right' => 'polygon(0% 20%,60% 20%,60% 0%,100% 50%,60% 100%,60% 80%,0% 80%)',
        'arrow_left' => 'polygon(100% 20%,40% 20%,40% 0%,0% 50%,40% 100%,40% 80%,100% 80%)',
        'arrow_up' => 'polygon(20% 100%,20% 40%,0% 40%,50% 0%,100% 40%,80% 40%,80% 100%)',
        'arrow_down' => 'polygon(20% 0%,20% 60%,0% 60%,50% 100%,100% 60%,80% 60%,80% 0%)',
        'parallelogram' => 'polygon(20% 0%,100% 0%,80% 100%,0% 100%)',
        'octagon' => 'polygon(30% 0%,70% 0%,100% 30%,100% 70%,70% 100%,30% 100%,0% 70%,0% 30%)',
        'trapezoid' => 'polygon(20% 0%,80% 0%,100% 100%,0% 100%)',
        'trapezoid_inv' => 'polygon(0% 0%,100% 0%,80% 100%,20% 100%)',
        'cross' => 'polygon(35% 0%,65% 0%,65% 35%,100% 35%,100% 65%,65% 65%,65% 100%,35% 100%,35% 65%,0% 65%,0% 35%,35% 35%)',
        'chevron_right' => 'polygon(0% 0%,60% 0%,100% 50%,60% 100%,0% 100%,40% 50%)',
        'chevron_left' => 'polygon(100% 0%,40% 0%,0% 50%,40% 100%,100% 100%,60% 50%)',
        'right_triangle' => 'polygon(0% 0%,0% 100%,100% 100%)',
        'semicircle' => 'polygon(0% 50%,3.8% 30.9%,14.6% 14.6%,30.9% 3.8%,50% 0%,69.1% 3.8%,85.4% 14.6%,96.2% 30.9%,100% 50%,100% 100%,0% 100%)',
        'double_arrow_h' => 'polygon(10% 50%,30% 20%,30% 40%,70% 40%,70% 20%,90% 50%,70% 80%,70% 60%,30% 60%,30% 80%)',
        'double_arrow_v' => 'polygon(50% 10%,20% 30%,40% 30%,40% 70%,20% 70%,50% 90%,80% 70%,60% 70%,60% 30%,80% 30%)',
        'bevel_rect' => 'polygon(12% 0%,88% 0%,100% 12%,100% 88%,88% 100%,12% 100%,0% 88%,0% 12%)',
        'plate_left' => 'polygon(30% 0%,100% 0%,100% 100%,30% 100%,0% 50%)',
        'plate_right' => 'polygon(0% 0%,70% 0%,100% 50%,70% 100%,0% 100%)',
        'triangle_down' => 'polygon(0% 0%,100% 0%,50% 100%)',
        'triangle_left' => 'polygon(100% 0%,100% 100%,0% 50%)',
        'triangle_right' => 'polygon(0% 0%,0% 100%,100% 50%)',
        'quarter_circle' => 'polygon(0% 100%,100% 100%,92.4% 61.7%,70.7% 29.3%,38.3% 7.6%,0% 0%)',
        'hexagon_v' => 'polygon(50% 0%,100% 25%,100% 75%,50% 100%,0% 75%,0% 25%)',
        'l_shape' => 'polygon(0% 0%,40% 0%,40% 60%,100% 60%,100% 100%,0% 100%)',
        't_shape' => 'polygon(0% 0%,100% 0%,100% 30%,65% 30%,65% 100%,35% 100%,35% 30%,0% 30%)',
        'rt_tl' => 'polygon(0% 0%,100% 0%,0% 100%)',
        'rt_tr' => 'polygon(0% 0%,100% 0%,100% 100%)',
        'rt_br' => 'polygon(100% 0%,100% 100%,0% 100%)',
        'shield' => 'polygon(0% 0%,100% 0%,100% 60%,50% 100%,0% 60%)',
        'ribbon_right' => 'polygon(0% 0%,85% 0%,100% 50%,85% 100%,0% 100%,15% 50%)',
        'ribbon_left' => 'polygon(100% 0%,15% 0%,0% 50%,15% 100%,100% 100%,85% 50%)',
        'rect_snip_1' => 'polygon(0% 0%,85% 0%,100% 15%,100% 100%,0% 100%)',
        'rect_snip_2_same' => 'polygon(15% 0%,85% 0%,100% 15%,100% 100%,0% 100%,0% 15%)',
        'rect_snip_diag' => 'polygon(15% 0%,100% 0%,100% 85%,85% 100%,0% 100%,0% 15%)',
        'rect_round_1' => 'polygon(0% 0%,75% 0%,88% 2%,96% 9%,100% 22%,100% 100%,0% 100%)',
        'heptagon' => 'polygon(50% 0%,89.1% 18.8%,98.7% 61.1%,71.7% 95%,28.3% 95%,1.3% 61.1%,10.9% 18.8%)',
        'decagon' => 'polygon(50% 0%,79.4% 19.1%,97.6% 34.5%,97.6% 65.5%,79.4% 80.9%,50% 100%,20.6% 80.9%,2.4% 65.5%,2.4% 34.5%,20.6% 19.1%)',
        'dodecagon' => 'polygon(50% 0%,75% 13.4%,93.3% 25%,100% 50%,93.3% 75%,75% 86.6%,50% 100%,25% 86.6%,6.7% 75%,0% 50%,6.7% 25%,25% 13.4%)',
        'arrow_cross' => 'polygon(50% 0%,65% 20%,55% 20%,55% 45%,80% 45%,80% 35%,100% 50%,80% 65%,80% 55%,55% 55%,55% 80%,65% 80%,50% 100%,35% 80%,45% 80%,45% 55%,20% 55%,20% 65%,0% 50%,20% 35%,20% 45%,45% 45%,45% 20%,35% 20%)',
        'minus_sign' => 'polygon(10% 42%,90% 42%,90% 58%,10% 58%)',
        'multiply_x' => 'polygon(8% 0%,50% 34%,92% 0%,100% 8%,64% 50%,100% 92%,92% 100%,50% 66%,8% 100%,0% 92%,36% 50%,0% 8%)',
        'document' => 'polygon(0% 0%,100% 0%,100% 78%,83% 92%,66% 78%,50% 92%,33% 78%,17% 92%,0% 78%)',
        'manual_input' => 'polygon(0% 20%,100% 0%,100% 100%,0% 100%)',
        'off_page_connector' => 'polygon(0% 0%,100% 0%,100% 70%,50% 100%,0% 70%)',
        'bowtie' => 'polygon(0% 0%,100% 0%,0% 100%,100% 100%)',
        'delay' => 'polygon(0% 0%,70% 0%,85% 3%,96% 12%,100% 25%,100% 75%,96% 88%,85% 97%,70% 100%,0% 100%)',
        'punched_tape' => 'polygon(0% 15%,16.5% 0%,33% 15%,50% 0%,66% 15%,83% 0%,100% 15%,100% 85%,83% 100%,66% 85%,50% 100%,33% 85%,16.5% 100%,0% 85%)',
        'elbow' => 'polygon(0% 100%, 1.23% 84.36%, 4.89% 69.1%, 10.9% 54.6%, 19.1% 41.22%, 29.29% 29.29%, 41.22% 19.1%, 54.6% 10.9%, 69.1% 4.89%, 84.36% 1.23%, 100% 0%, 100% 55%, 92.96% 55.55%, 86.09% 57.2%, 79.57% 59.9%, 73.55% 63.59%, 68.18% 68.18%, 63.59% 73.55%, 59.9% 79.57%, 57.2% 86.09%, 55.55% 92.96%, 55% 100%)',
        'convoyeur_rouleaux' => 'polygon(4.33% 0%,12.33% 0%,12.33% 65%,21% 65%,21% 0%,29% 0%,29% 65%,37.67% 65%,37.67% 0%,45.67% 0%,45.67% 65%,54.33% 65%,54.33% 0%,62.33% 0%,62.33% 65%,71% 65%,71% 0%,79% 0%,79% 65%,87.67% 65%,87.67% 0%,95.67% 0%,95.67% 65%,100% 65%,100% 100%,0% 100%,0% 65%,4.33% 65%)',
        'vis_sans_fin' => 'polygon(0% 30%,8.33% 10%,16.67% 30%,25% 10%,33.33% 30%,41.67% 10%,50% 30%,58.33% 10%,66.67% 30%,75% 10%,83.33% 30%,91.67% 10%,100% 30%,100% 70%,91.67% 90%,83.33% 70%,75% 90%,66.67% 70%,58.33% 90%,50% 70%,41.67% 90%,33.33% 70%,25% 90%,16.67% 70%,8.33% 90%,0% 70%)',
        'tapis_chevron' => 'polygon(0% 45%,12.5% 15%,25% 45%,37.5% 15%,50% 45%,62.5% 15%,75% 45%,87.5% 15%,100% 45%,100% 100%,0% 100%)',
        'robot_bras' => 'polygon(20% 100%,20% 88%,42% 88%,42% 35%,75% 35%,75% 22%,85% 15%,78% 28%,85% 40%,75% 45%,58% 45%,58% 88%,80% 88%,80% 100%)',
    ];
    if ($shapeType === 'text_only') return ['radius' => '0', 'clip' => 'none'];
    if ($shapeType === 'stadium') return ['radius' => '999px', 'clip' => 'none'];
    if (isset($polygons[$shapeType])) return ['radius' => '0', 'clip' => $polygons[$shapeType]];
    if ($shapeType === 'ellipse') return ['radius' => '50%', 'clip' => 'none'];
    if ($shapeType === 'roundRect') return ['radius' => '16%', 'clip' => 'none'];
    return ['radius' => '3px', 'clip' => 'none'];
}

// Effets "à la PowerPoint" appliqués sur la forme. Ombre/Lueur passent par filter:drop-shadow
// (respecte la découpe clip-path des formes non rectangulaires, contrairement à box-shadow qui
// serait rogné par le clip-path). Relief/Ombre interne passent par box-shadow inset (rendu correct
// uniquement sur les formes basées sur border-radius : rect/roundRect/ellipse/stadium — approximation
// acceptable pour les polygones, cas rare sur ce plan).
function su_effect_css($effect, $fillColor, $borderColor) {
    switch ($effect) {
        case 'shadow':
            return " filter: drop-shadow(3px 4px 6px rgba(0,0,0,.4));";
        case 'bevel':
            return " box-shadow: inset -3px -3px 6px rgba(0,0,0,.3), inset 3px 3px 6px rgba(255,255,255,.6);";
        case 'inset':
            return " box-shadow: inset 0 0 10px rgba(0,0,0,.5), inset 0 0 3px rgba(0,0,0,.55);";
        case 'glow':
            $glow = su_h(($borderColor ?: $fillColor) ?: '#3b82f6');
            return " filter: drop-shadow(0 0 5px {$glow}) drop-shadow(0 0 10px {$glow});";
        default:
            return '';
    }
}

function su_render_zone($z, $is_admin) {
    $isTextOnly = $z['shape_type'] === 'text_only';
    $isSvgShape = in_array($z['shape_type'], SU_SVG_SHAPES, true);
    $style = "left:{$z['pos_x']}%; top:{$z['pos_y']}%; width:{$z['pos_w']}%; height:{$z['pos_h']}%;";
    $svg = '';
    if ($isSvgShape) {
        // Illustration SVG en dégradés (effet 3D) plutôt qu'un aplat + clip-path — voir su_industrial_svg().
        $style .= " background:transparent; border-color:transparent; border-radius:0; clip-path:none;";
        $svg = su_industrial_svg($z['shape_type'], $z['fill_color'] ?: '#3498db', (int)$z['id'], (float)$z['pos_w'], (float)$z['pos_h'], $z['border_color'] ?? null);
    } elseif ($isTextOnly) {
        // Pas de géométrie visible : juste une ancre invisible pour porter le texte.
        $style .= " background:transparent; border-color:transparent;";
    } else {
        if (!empty($z['gradient']) && !empty($z['fill_color2'])) {
            $angle = (int)($z['gradient_angle'] ?? 135);
            $style .= " background:linear-gradient({$angle}deg, " . su_h($z['fill_color']) . ", " . su_h($z['fill_color2']) . ");";
        } else {
            $style .= " background:" . su_h($z['fill_color']) . ";";
        }
        $style .= $z['border_color'] ? " border-color:" . su_h($z['border_color']) . ";" : " border-color:transparent;";
        $style .= su_effect_css($z['effect'] ?? 'none', $z['fill_color'], $z['border_color']);
    }
    if (!$isSvgShape) {
        $css = su_shape_css($z['shape_type']);
        $style .= " border-radius:{$css['radius']}; clip-path:{$css['clip']};";
    }
    if ((float)$z['rotation'] != 0.0) $style .= " transform: rotate({$z['rotation']}deg);";

    // La rotation du texte est indépendante de celle de la forme : on annule d'abord la
    // rotation de la forme, puis on applique la rotation de texte demandée (angle absolu à l'écran).
    $textRot = (float)$z['text_rotation'] - (float)$z['rotation'];
    // Le texte est positionné par décalage libre (text_offset_x/y, en % de la largeur/hauteur de
    // la forme) autour de son centre, indépendamment de la géométrie — pour rester lisible même
    // quand une autre forme vient se poser au centre (ex: une longue vis avec une machine dessus).
    // position:absolute + translate(-50%,-50%) : le span est un enfant de la forme, il hérite donc
    // automatiquement de la rotation de celle-ci (cohérent avec le décalage exprimé dans son repère local).
    $offX = (float)($z['text_offset_x'] ?? 0);
    $offY = (float)($z['text_offset_y'] ?? 0);
    // white-space:nowrap : le texte ne doit jamais passer à la ligne, même pivoté dans une forme
    // étroite — il peut déborder visuellement de sa forme le long de l'axe de rotation, ce qui
    // est le comportement voulu (ex: texte vertical sur un tuyau étroit).
    $textColor = !empty($z['text_color']) ? $z['text_color'] : su_text_color($isTextOnly ? '#ffffff' : $z['fill_color']);
    $spanStyle = "position:absolute; left:calc(50% + {$offX}%); top:calc(50% + {$offY}%); white-space:nowrap;";
    $spanTransform = 'translate(-50%,-50%)';
    if ($textRot != 0.0) $spanTransform .= " rotate({$textRot}deg)";
    $spanStyle .= " transform: {$spanTransform};";
    if ($z['label'] !== '') $spanStyle .= " color:" . su_h($textColor) . ";";
    if (!empty($z['font_size'])) $spanStyle .= " font-size:" . su_h($z['font_size']) . "rem;";

    $data = ' data-zone-id="' . (int)$z['id'] . '"'
        . ' data-fill="' . su_h($z['fill_color']) . '"'
        . ' data-border="' . su_h($z['border_color'] ?: 'none') . '"'
        . ' data-fill2="' . su_h($z['fill_color2'] ?: '') . '"'
        . ' data-gradient="' . (!empty($z['gradient']) ? '1' : '0') . '"'
        . ' data-gradient-angle="' . (int)($z['gradient_angle'] ?? 135) . '"'
        . ' data-effect="' . su_h($z['effect'] ?? 'none') . '"'
        . ' data-shape="' . su_h($z['shape_type']) . '"'
        . ' data-rotation="' . su_h($z['rotation']) . '"'
        . ' data-text-rotation="' . su_h($z['text_rotation']) . '"'
        . ' data-font-size="' . su_h($z['font_size'] ?? '') . '"'
        . ' data-text-color="' . su_h($z['text_color'] ?? '') . '"'
        . ' data-text-offset-x="' . su_h($offX) . '"'
        . ' data-text-offset-y="' . su_h($offY) . '"'
        . ' data-ordre="' . (int)$z['ordre'] . '"'
        . ' data-clickable="' . ($z['est_clickable'] ? '1' : '0') . '"'
        . ' data-categorie-visuelle-id="' . (!empty($z['categorie_visuelle_id']) ? (int)$z['categorie_visuelle_id'] : '') . '"';

    $svgLayer = $svg !== '' ? '<div class="su-zone-svg" style="position:absolute; inset:0; pointer-events:none;">' . $svg . '</div>' : '';
    $inner = $svgLayer . '<span class="su-zone-label" style="' . $spanStyle . '">' . su_h($z['label']) . '</span>';

    if ($z['est_clickable']) {
        return '<button type="button" class="su-zone su-zone-click"' . $data . ' style="' . $style . '" title="' . su_h($z['label']) . '">' . $inner . '</button>';
    }
    $cls = 'su-zone su-zone-decor' . ($is_admin ? ' su-zone-editable' : '');
    return '<div class="' . $cls . '"' . $data . ' style="' . $style . '">' . $inner . '</div>';
}

if ($vue === 'liste') {
    // Démo : titre et description génériques (le vrai process de production ne doit jamais
    // apparaître ici, même dans un simple texte d'accroche) — voir GMAO_EST_DEMO dans db.php.
    $titreSalleTri = GMAO_EST_DEMO ? t('pm.schema_lignes_prod') : t('pm.schema_salle_tri');
    $descSalleTri = GMAO_EST_DEMO ? t('pm.schema_desc_lignes_prod_demo') : t('pm.schema_desc_salle_tri');
    $descConditionnement = GMAO_EST_DEMO ? t('pm.schema_desc_conditionnement_demo') : t('pm.schema_desc_conditionnement');
    ?>
    <div class="su-liste">
        <button type="button" class="su-carte" data-vue="salle_tri">
            <i class="fa-solid fa-filter"></i>
            <div><h4><?php echo su_h($titreSalleTri); ?></h4><p><?php echo su_h($descSalleTri); ?></p></div>
        </button>
        <button type="button" class="su-carte" data-vue="conditionnement">
            <i class="fa-solid fa-box-open"></i>
            <div><h4><?php echo t('pm.schema_titre_conditionnement'); ?></h4><p><?php echo su_h($descConditionnement); ?></p></div>
        </button>
    </div>
    <?php
    exit();
}

if (in_array($vue, ['salle_tri', 'conditionnement'])) {
    [$schema, $zones] = su_get_schema_et_zones($db, $vue);
    if (!$schema) { http_response_code(404); exit(); }
    ?>
    <div class="su-schema" data-schema-id="<?php echo (int)$schema['id']; ?>" data-template-key="<?php echo su_h($vue); ?>">
        <p class="su-note"><i class="fa-solid fa-circle-info"></i> <?php echo t('pm.schema_note_clic'); ?><?php echo $is_admin ? t('pm.schema_note_admin_extra') : ''; ?></p>
        <?php
        $canvasW = $schema['canvas_w'] ?: 12192;
        $canvasH = $schema['canvas_h'] ?: 6858;
        // Un agrandissement horizontal (gauche/droite) élargit réellement la boîte (au-delà de 100%,
        // avec défilement latéral dans .schema-body) ; un agrandissement vertical (haut/bas) l'allonge
        // via aspect-ratio, la largeur restant celle du conteneur — sinon les deux se feraient au
        // détriment l'un de l'autre (élargir écraserait la hauteur, et inversement).
        $widthPct = 100 * $canvasW / 12192;
        ?>
        <div class="su-canvas" id="suCanvas" style="width:<?php echo su_h($widthPct); ?>%; aspect-ratio:<?php echo su_h($canvasW); ?>/<?php echo su_h($canvasH); ?>;">
            <?php foreach ($zones as $z) echo su_render_zone($z, $is_admin); ?>
        </div>
        <?php if ($vue === 'salle_tri' && !GMAO_EST_DEMO): ?>
        <div class="su-hint"><i class="fa-solid fa-arrow-up-right-from-square"></i> <?php echo t('pm.schema_hint_cluster'); ?>
            <button type="button" class="su-btn-lien" data-vue="conditionnement"><?php echo t('pm.schema_y_aller'); ?> <i class="fa-solid fa-arrow-right"></i></button>
        </div>
        <?php endif; ?>
    </div>
    <?php
    exit();
}

http_response_code(404);
