<?php
// Configuration de la TODO list du planning : catégories de tâches et durées estimées proposées, modifiables
// dans Paramètres > TODO list (voir parametres.php). Partagé par planning.php (formulaire), maintenance.php
// (validation à l'enregistrement d'une tâche) et parametres.php (édition), qui incluent tous ce fichier.

// Crée la table des tâches si besoin, puis ajoute les colonnes de "détails" d'une tâche sur une table créée
// avant leur existence — vérifié par SHOW COLUMNS plutôt que ADD COLUMN IF NOT EXISTS, qui n'existe pas sur
// MySQL (seulement MariaDB). jour_origine = jour d'origine d'une tâche reportée automatiquement ;
// date_fait = moment où elle a été cochée.
function todo_assurer_table($db) {
    $db->exec("CREATE TABLE IF NOT EXISTS planning_todo (
        id INT AUTO_INCREMENT PRIMARY KEY,
        utilisateur VARCHAR(100) NOT NULL,
        jour DATE NOT NULL,
        texte VARCHAR(255) NOT NULL,
        fait TINYINT(1) NOT NULL DEFAULT 0,
        date_creation DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    )");
    $colonnes = [
        'priorite'     => "VARCHAR(10) NOT NULL DEFAULT 'normale'",
        'heure'        => "TIME NULL",
        'machine'      => "VARCHAR(100) NULL",
        'detail'       => "VARCHAR(255) NULL",
        'categorie'    => "VARCHAR(30) NULL",
        'duree_min'    => "SMALLINT UNSIGNED NULL",
        'jour_origine' => "DATE NULL",
        'date_fait'    => "DATETIME NULL",
    ];
    foreach ($colonnes as $nom => $definition) {
        if ($db->query("SHOW COLUMNS FROM planning_todo LIKE '$nom'")->fetch() === false) {
            $db->exec("ALTER TABLE planning_todo ADD COLUMN $nom $definition");
        }
    }
}

// Catégories d'origine (clé => libellé français, icône, couleur). Le libellé français stocké en base pour
// ces clés-là est remplacé à l'affichage par la traduction courante tant que personne ne l'a personnalisé
// (même principe que libelles_workflow dans parametres.php).
function todo_categories_defauts() {
    return [
        'depannage'     => ['Dépannage',      'fa-wrench',          '#e74c3c'],
        'preventif'     => ['Préventif',      'fa-calendar-check',  '#3498db'],
        'controle'      => ['Contrôle',       'fa-magnifying-glass', '#8e44ad'],
        'nettoyage'     => ['Nettoyage',      'fa-broom',           '#16a085'],
        'administratif' => ['Administratif',  'fa-folder-open',     '#f39c12'],
        'autre'         => ['Autre',          'fa-ellipsis',        '#7f8c8d'],
    ];
}

// Icônes proposées pour une catégorie de tâche.
function todo_icones_disponibles() {
    return ['fa-wrench', 'fa-screwdriver-wrench', 'fa-hammer', 'fa-toolbox', 'fa-gear', 'fa-calendar-check',
        'fa-magnifying-glass', 'fa-clipboard-check', 'fa-broom', 'fa-folder-open', 'fa-envelope', 'fa-phone',
        'fa-cart-shopping', 'fa-truck', 'fa-boxes-stacked', 'fa-graduation-cap', 'fa-bolt', 'fa-snowflake',
        'fa-oil-can', 'fa-flask', 'fa-fire-extinguisher', 'fa-shield-halved', 'fa-triangle-exclamation', 'fa-ellipsis'];
}

const TODO_DUREES_DEFAUT = [15, 30, 45, 60, 90, 120, 180, 240, 480];

function todo_config_assurer($db) {
    $db->exec("CREATE TABLE IF NOT EXISTS todo_categories (
        id INT AUTO_INCREMENT PRIMARY KEY,
        cle VARCHAR(30) UNIQUE,
        label VARCHAR(100),
        icone VARCHAR(60) DEFAULT 'fa-ellipsis',
        couleur VARCHAR(20) DEFAULT '#7f8c8d',
        ordre INT DEFAULT 0
    )");
    $db->exec("CREATE TABLE IF NOT EXISTS parametres_general (cle VARCHAR(50) PRIMARY KEY, valeur TEXT)");
    if ($db->query("SELECT COUNT(*) FROM todo_categories")->fetchColumn() == 0) {
        $stmt = $db->prepare("INSERT INTO todo_categories (cle, label, icone, couleur, ordre) VALUES (?, ?, ?, ?, ?)");
        $i = 0;
        foreach (todo_categories_defauts() as $cle => $d) { $stmt->execute([$cle, $d[0], $d[1], $d[2], $i++]); }
    }
    $stmt = $db->prepare("INSERT IGNORE INTO parametres_general (cle, valeur) VALUES ('todo_durees', ?)");
    $stmt->execute([implode(',', TODO_DUREES_DEFAUT)]);
}

// Libellé affiché d'une catégorie : la traduction courante tant que c'est encore le libellé français d'origine.
function todo_categorie_label($cat) {
    $defauts = todo_categories_defauts();
    if (isset($defauts[$cat['cle']]) && $cat['label'] === $defauts[$cat['cle']][0]) {
        return t('planning.todo_cat_' . $cat['cle']);
    }
    return $cat['label'];
}

function todo_categories_charger($db) {
    $rows = $db->query("SELECT id, cle, label, icone, couleur FROM todo_categories ORDER BY ordre ASC, id ASC")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) { $r['label_affiche'] = todo_categorie_label($r); }
    unset($r);
    return $rows;
}

function todo_durees_charger($db) {
    $stmt = $db->prepare("SELECT valeur FROM parametres_general WHERE cle = 'todo_durees'");
    $stmt->execute();
    $brut = $stmt->fetchColumn();
    if ($brut === false) { return TODO_DUREES_DEFAUT; }
    $liste = array_filter(array_map('intval', explode(',', (string)$brut)), function ($m) { return $m >= 1 && $m <= 1440; });
    $liste = array_values(array_unique($liste));
    sort($liste);
    return $liste;
}

// "90" -> "1 h 30", "45" -> "45 min"
function todo_format_duree($min) {
    $min = (int)$min;
    if ($min < 60) { return $min . ' min'; }
    $h = intdiv($min, 60);
    $m = $min % 60;
    return $m ? sprintf('%d h %02d', $h, $m) : $h . ' h';
}
