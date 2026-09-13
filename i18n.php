<?php
// I18N : détection/mémorisation de la langue courante + fonction de traduction t().
// Inclus depuis session_init.php (donc après session_start()) pour que toutes les
// pages qui incluent déjà session_init.php bénéficient de t() sans rien changer.
//
// Priorité de détection : ?lang= dans l'URL (mémorisé pour la suite) > session > cookie > 'fr' par défaut.
// Le contenu venant de la base (noms de machines, catégories créées dans Paramètres, comptes-rendus...)
// n'est PAS traduit : seule l'interface statique (menus, boutons, titres, messages) passe par t().

define('GMAO_LANGUES_DISPONIBLES', ['fr', 'en', 'nl']);

if (isset($_GET['lang']) && in_array($_GET['lang'], GMAO_LANGUES_DISPONIBLES, true)) {
    $_SESSION['lang'] = $_GET['lang'];
    if (!headers_sent()) {
        setcookie('gmao_lang', $_GET['lang'], time() + 60 * 60 * 24 * 365, '/');
    }
}

$__gmao_lang = $_SESSION['lang'] ?? ($_COOKIE['gmao_lang'] ?? 'fr');
if (!in_array($__gmao_lang, GMAO_LANGUES_DISPONIBLES, true)) {
    $__gmao_lang = 'fr';
}
$_SESSION['lang'] = $__gmao_lang;

$GLOBALS['__gmao_i18n'] = require __DIR__ . '/lang/' . $__gmao_lang . '.php';
// Repli sur le français pour toute clé pas encore traduite dans la langue courante.
if ($__gmao_lang !== 'fr') {
    $GLOBALS['__gmao_i18n'] += require __DIR__ . '/lang/fr.php';
}

/**
 * Traduit une clé vers la langue courante (repli : français, puis la clé elle-même).
 * $params permet une substitution simple à la strtr(), ex. t('bonjour', ['{nom}' => $nom]).
 */
function t(string $key, array $params = []): string
{
    $str = $GLOBALS['__gmao_i18n'][$key] ?? $key;
    return $params ? strtr($str, $params) : $str;
}

function langue_actuelle(): string
{
    return $GLOBALS['__gmao_lang'] ?? ($_SESSION['lang'] ?? 'fr');
}
