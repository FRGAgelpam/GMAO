<?php
require_once __DIR__ . '/session_init.php';
// On détruit toutes les variables de session
session_unset();
// On détruit la session elle-même
session_destroy();
// On redirige vers la page de connexion
header("Location: login.php");
exit();
?>