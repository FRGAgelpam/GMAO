<?php
// SÉCURITÉ : isole les sessions par environnement (prod / démo / local) pour qu'une
// session ouverte sur un environnement (ex. la démo publique) ne soit jamais valide
// sur un autre partageant le même serveur (ex. la vraie prod) — les deux sites tournent
// sur la même VM et partageaient par défaut le même nom de cookie PHPSESSID.
require_once __DIR__ . '/../db_credentials.php';
session_name('GMAOSESS_' . substr(md5($dbname), 0, 12));
session_start();

// I18N : langue courante + fonction t(), disponibles partout où session_init.php est inclus.
require_once __DIR__ . '/i18n.php';
