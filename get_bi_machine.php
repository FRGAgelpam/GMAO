<?php
require_once __DIR__ . '/session_init.php';
header("Content-Type: application/json; charset=utf-8");
require_once 'db.php';

// On ne donne les infos que si l'utilisateur est connecté
if (!isset($_SESSION['user'])) { echo json_encode([]); exit; }

$equip = trim($_GET['equip'] ?? '');
$usine = trim($_GET['usine'] ?? '');
$secteur = trim($_GET['secteur'] ?? '');
$ligne = trim($_GET['ligne'] ?? '');
$zone = trim($_GET['zone'] ?? '');

if ($equip === '') { echo json_encode([]); exit; }

try {
    // On filtre sur la localisation complète (pas juste le nom de la machine) car plusieurs
    // usines peuvent avoir des lignes/machines homonymes.
    $stmt = $db->prepare("SELECT id, num_bi, date, statut, description, tech, prio
        FROM taches
        WHERE equip = ? AND usine = ? AND secteur = ? AND IFNULL(ligne, '') = ? AND IFNULL(zone, '') = ?
        ORDER BY date DESC");
    $stmt->execute([$equip, $usine, $secteur, $ligne, $zone]);
    $bons = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode($bons);
} catch (Exception $e) {
    echo json_encode([]);
}
