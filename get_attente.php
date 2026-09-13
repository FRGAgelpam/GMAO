<?php
require_once __DIR__ . '/session_init.php';
header("Content-Type: application/json; charset=utf-8");
require_once 'db.php';

// On ne donne les infos que si l'utilisateur est connecté (VIP ou Tech)
if (!isset($_SESSION['user'])) { echo json_encode([]); exit; }

try {
    // On va chercher uniquement les bons qui attendent d'être validés
    $res = $db->query("SELECT * FROM taches WHERE statut = 'EN ATTENTE' ORDER BY date DESC");
    $demandes = $res->fetchAll(PDO::FETCH_ASSOC);
    
    // On adapte le nom pour le JS
    foreach($demandes as &$d) {
        $d['desc'] = $d['description'];
    }

    echo json_encode($demandes);
} catch (Exception $e) {
    echo json_encode([]);
}