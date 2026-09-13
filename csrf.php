<?php
// Protection CSRF minimale : un jeton unique par session, à poser dans les
// formulaires sensibles (création/suppression de compte, changement de mot
// de passe) et à vérifier avant d'exécuter l'action correspondante.
// À inclure après session_start().

function csrf_token() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_verifie($jeton_recu) {
    return isset($_SESSION['csrf_token']) && is_string($jeton_recu) && hash_equals($_SESSION['csrf_token'], $jeton_recu);
}
