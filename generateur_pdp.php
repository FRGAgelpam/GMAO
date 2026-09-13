<?php
require_once __DIR__ . '/session_init.php';
// SÉCURITÉ : page accessible uniquement depuis sous_traitants.php, réservée au personnel
// maintenance — elle n'avait auparavant aucun contrôle de session (IDOR via id_ee/id_ticket).
if (!isset($_SESSION['user']) || !isset($_SESSION['role']) || !in_array(strtolower($_SESSION['role']), ['admin', 'technicien'])) {
    header("Location: login.php");
    exit();
}
require_once 'db.php';

$id_ee = $_GET['id_ee'] ?? null;
$id_ticket = $_GET['id_ticket'] ?? null;

$params = [];

// 1. R�cup�rer les infos de l'entreprise si elle existe
if ($id_ee) {
    $stmt = $db->prepare("SELECT * FROM entreprises_ext WHERE id = ?");
    $stmt->execute([$id_ee]);
    $ee = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($ee) {
        $params['nom_ee'] = $ee['nom'];
        $params['contact_ee'] = $ee['contact_nom'];
        $params['tel_ee'] = $ee['telephone'];
    }
}

// 2. R�cup�rer les infos du ticket si pr�sent
if ($id_ticket) {
    $stmt = $db->prepare("SELECT * FROM taches WHERE id = ?");
    $stmt->execute([$id_ticket]);
    $ticket = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($ticket) {
        $params['usine'] = $ticket['usine'];
        $params['equip'] = $ticket['equip'];
        $params['desc'] = $ticket['description'];
    }
}

// 3. Rediriger vers ton Plan de Pr�vention en passant les donn�es via URL
$query_string = http_build_query($params);
header("Location: plan_prevention.php?" . $query_string);
exit();
?>