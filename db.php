<?php
// SÉCURITÉ : identifiants déplacés hors de la racine web (voir db_credentials.php,
// un niveau au-dessus de html/) pour qu'ils ne soient jamais servis via une URL.
require __DIR__ . '/../db_credentials.php';

// Détecte l'environnement démo (base gmao_db_demo) : sert à masquer/genericiser le contenu
// confidentiel (process de production, branding réel, comptes internes) qui ne doit jamais
// apparaître sur la démo publique, sans jamais changer le comportement de la prod.
if (!defined('GMAO_EST_DEMO')) { define('GMAO_EST_DEMO', $dbname === 'gmao_db_demo'); }

try {
    // Connexion unique à MariaDB
    $db = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $user, $pass);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // On fait pointer $db_users sur la même base pour ne pas casser ton code actuel
    $db_users = $db;

} catch (Exception $e) {
    // SÉCURITÉ : le détail de l'erreur part dans le journal serveur, jamais à l'écran
    error_log("Erreur de connexion MariaDB : " . $e->getMessage());
    die("Le service est momentanément indisponible. Merci de réessayer dans quelques instants.");
}
// Fonction pour écrire dans le journal d'activité
function ajouterLog($db, $utilisateur, $action, $description) {
    try {
        $stmt = $db->prepare("INSERT INTO logs (utilisateur, action, description) VALUES (?, ?, ?)");
        $stmt->execute([$utilisateur, $action, $description]);
    } catch (Exception $e) {
        // On ignore l'erreur pour ne pas bloquer l'application
    }
}
?>