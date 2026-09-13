<?php
// Modèle d'identifiants de connexion à la base MariaDB de la GMAO.
//
// 1. Copie ce fichier UN NIVEAU AU-DESSUS de ce dossier (hors de la racine web,
//    donc hors de "html/"), et renomme la copie en "db_credentials.php".
// 2. Remplace les valeurs ci-dessous par celles de ta propre base de données
//    (créée en important install/schema.sql).
// 3. Ne commite jamais ce fichier une fois rempli avec de vrais identifiants —
//    il doit rester hors du dépôt Git (voir install/README.md).
$host = 'localhost';
$dbname = 'gmao_db';
$user = 'gmao_user';
$pass = 'change_moi';
