<?php
require_once __DIR__ . '/session_init.php';
require 'db.php';
require 'csrf.php';

// --- SÉCURITÉ ---
if (!isset($_SESSION['user']) || strtolower($_SESSION['role']) !== 'admin') {
    header("Location: maintenance.php");
    exit();
}

$is_admin = true;
$message = "";

// --- AUTO-MIGRATION ---
try {
    @$db->exec("ALTER TABLE utilisateurs ADD COLUMN IF NOT EXISTS prenom VARCHAR(100) AFTER username");
    @$db->exec("ALTER TABLE utilisateurs ADD COLUMN IF NOT EXISTS fonction VARCHAR(100) AFTER prenom");
    @$db->exec("ALTER TABLE utilisateurs ADD COLUMN IF NOT EXISTS email VARCHAR(150)");
    @$db->exec("ALTER TABLE utilisateurs ADD COLUMN IF NOT EXISTS telephone VARCHAR(20)");
    @$db->exec("ALTER TABLE utilisateurs ADD COLUMN IF NOT EXISTS code_personnel VARCHAR(50)");
    @$db->exec("ALTER TABLE utilisateurs ADD COLUMN IF NOT EXISTS photo VARCHAR(255)");
} catch (Exception $e) {}

// --- PHOTO DE PROFIL (upload) ---
// Stockée dans uploads/ (donnée, pas du code — voir .gitignore) : chaque environnement
// (prod, démo, un futur clone GitHub) garde ainsi ses propres photos, jamais versionnées.
function adminreset_ini_en_octets($valeur) {
    $valeur = trim((string)$valeur);
    if ($valeur === '') { return 0; }
    $unite = strtoupper(substr($valeur, -1));
    $nombre = (int)$valeur;
    switch ($unite) {
        case 'G': return $nombre * 1024 * 1024 * 1024;
        case 'M': return $nombre * 1024 * 1024;
        case 'K': return $nombre * 1024;
        default: return (int)$valeur;
    }
}
function adminreset_enregistrer_avatar($fichier, $userId, $db) {
    $extAutorisees = ['jpg', 'jpeg', 'png', 'webp'];
    $ext = strtolower(pathinfo($fichier['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $extAutorisees, true)) { return null; }
    $uploadDir = __DIR__ . '/uploads/avatars/';
    if (!is_dir($uploadDir)) { mkdir($uploadDir, 0755, true); }
    // Supprime l'ancienne photo pour ne pas accumuler de fichiers orphelins
    $stmt = $db->prepare("SELECT photo FROM utilisateurs WHERE id = ?");
    $stmt->execute([$userId]);
    $ancienChemin = $stmt->fetchColumn();
    if (!empty($ancienChemin) && is_file(__DIR__ . '/' . $ancienChemin)) {
        @unlink(__DIR__ . '/' . $ancienChemin);
    }
    $fileName = 'user_' . $userId . '_' . time() . '.' . $ext;
    $cheminRelatif = 'uploads/avatars/' . $fileName;
    if (move_uploaded_file($fichier['tmp_name'], $uploadDir . $fileName)) {
        @chmod($uploadDir . $fileName, 0664);
        return $cheminRelatif;
    }
    return null;
}

// --- SERVICES (configurables depuis Paramètres > Services) ---
try {
    $db->exec("CREATE TABLE IF NOT EXISTS services (
        id INT AUTO_INCREMENT PRIMARY KEY,
        cle VARCHAR(60) UNIQUE,
        label VARCHAR(100),
        ordre INT DEFAULT 0
    )");
    if ($db->query("SELECT COUNT(*) FROM services")->fetchColumn() == 0) {
        $defautsServices = [
            ['production', 'Production'],
            ['rh', 'Ressources Humaines'],
            ['direction', 'Direction'],
            ['comptabilite', 'Comptabilité'],
            ['qualite', 'Qualité'],
            ['securite', 'Sécurité'],
            ['qhse', 'QHSE (Qualité & Sécurité)'],
            ['logistique', 'Logistique'],
            ['agronomie', 'Agronomie'],
        ];
        $stmtSeed = $db->prepare("INSERT INTO services (cle, label, ordre) VALUES (?, ?, ?)");
        foreach ($defautsServices as $i => $d) { $stmtSeed->execute([$d[0], $d[1], $i]); }
    }
} catch (Exception $e) {}
$services_dispo = $db->query("SELECT cle, label FROM services ORDER BY ordre ASC")->fetchAll(PDO::FETCH_ASSOC);

// --- GESTION DES ACTIONS (AJOUT/MODIF/SUPPRESSION) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Piège classique PHP : si la photo dépasse post_max_size, PHP vide $_POST et $_FILES
    // sans erreur exploitable — la requête ressemble alors à une session expirée (le
    // csrf_token, vidé lui aussi, ne correspond plus à rien). On le détecte via CONTENT_LENGTH
    // avant même la vérification CSRF (même piège que bi_photos.php / fiche_vie_machine.php).
    $postMaxOctets = adminreset_ini_en_octets(ini_get('post_max_size'));
    if ($postMaxOctets > 0 && empty($_POST) && empty($_FILES) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > $postMaxOctets) {
        $message = "<div class='alert danger'>" . htmlspecialchars(t('adminreset.photo_trop_volumineuse', ['{limite}' => ini_get('post_max_size')])) . "</div>";
    }
    // SÉCURITÉ CSRF : ces actions créent/modifient/suppriment des comptes,
    // on vérifie que la requête vient bien d'un formulaire de cette page.
    elseif (!csrf_verifie($_POST['csrf_token'] ?? '')) {
        $message = "<div class='alert danger'>" . htmlspecialchars(t('ideesadmin.session_expiree')) . "</div>";
    } elseif (isset($_POST['action']) && $_POST['action'] === 'create_user') {
        $n_user = trim($_POST['new_username']);
        $n_prenom = isset($_POST['new_prenom']) ? trim($_POST['new_prenom']) : '';
        $n_fonction = isset($_POST['new_fonction']) ? trim($_POST['new_fonction']) : '';
        $n_role = trim($_POST['new_role']);
        $n_email = trim($_POST['new_email']);
        $n_tel = trim($_POST['new_telephone']);
        $n_pass = isset($_POST['new_password']) ? trim($_POST['new_password']) : '';
        $n_code = isset($_POST['new_code_personnel']) ? trim($_POST['new_code_personnel']) : '';
        $n_actif = 1; // Actif par défaut
        
        try {
            // SÉCURITÉ : le mot de passe est toujours stocké sous forme de hash sécurisé
            // (une chaîne vide reste une chaîne vide : compte "opérateur" sans connexion).
            $n_pass_stocke = ($n_pass !== '') ? password_hash($n_pass, PASSWORD_DEFAULT) : '';
            $stmt = $db->prepare("INSERT INTO utilisateurs (username, prenom, fonction, role, email, telephone, code_personnel, password, actif) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$n_user, $n_prenom, $n_fonction, $n_role, $n_email, $n_tel, $n_code, $n_pass_stocke, $n_actif]);
            $nouvel_id = $db->lastInsertId();
            if (isset($_FILES['avatar']) && $_FILES['avatar']['error'] === UPLOAD_ERR_OK) {
                $chemin = adminreset_enregistrer_avatar($_FILES['avatar'], $nouvel_id, $db);
                if ($chemin) {
                    $db->prepare("UPDATE utilisateurs SET photo=? WHERE id=?")->execute([$chemin, $nouvel_id]);
                }
            }
            $message = "<div class='alert success'>" . str_replace('{user}', htmlspecialchars($n_user), t('adminreset.compte_cree')) . "</div>";
        } catch (Exception $e) {
            $message = "<div class='alert danger'>" . htmlspecialchars(t('adminreset.err_identifiant_existe')) . "</div>";
        }
    } elseif (isset($_POST['action']) && $_POST['action'] === 'delete_user') {
        $db->prepare("DELETE FROM utilisateurs WHERE id = ?")->execute([$_POST['delete_id']]);
        $message = "<div class='alert success'>" . htmlspecialchars(t('adminreset.utilisateur_supprime')) . "</div>";
    } elseif (isset($_POST['user_id'])) {
        $id = $_POST['user_id'];
        $prenom = isset($_POST['prenom']) ? trim($_POST['prenom']) : '';
        $fonction = isset($_POST['fonction']) ? trim($_POST['fonction']) : '';
        $email = trim($_POST['email']);
        $tel = trim($_POST['telephone']);
        $code = isset($_POST['code_personnel']) ? trim($_POST['code_personnel']) : '';
        $role = trim($_POST['role']);
        $actif = intval($_POST['actif']);

        try {
            $db->prepare("UPDATE utilisateurs SET prenom=?, fonction=?, email=?, telephone=?, code_personnel=?, role=?, actif=? WHERE id=?")->execute([$prenom, $fonction, $email, $tel, $code, $role, $actif, $id]);

            if (!empty($_POST['new_password'])) {
                // SÉCURITÉ : mot de passe toujours stocké sous forme de hash sécurisé
                $db->prepare("UPDATE utilisateurs SET password=? WHERE id=?")->execute([password_hash(trim($_POST['new_password']), PASSWORD_DEFAULT), $id]);
            }

            if (!empty($_POST['remove_avatar'])) {
                $stmtOld = $db->prepare("SELECT photo FROM utilisateurs WHERE id = ?");
                $stmtOld->execute([$id]);
                $ancienChemin = $stmtOld->fetchColumn();
                if (!empty($ancienChemin) && is_file(__DIR__ . '/' . $ancienChemin)) {
                    @unlink(__DIR__ . '/' . $ancienChemin);
                }
                $db->prepare("UPDATE utilisateurs SET photo=NULL WHERE id=?")->execute([$id]);
            } elseif (isset($_FILES['avatar']) && $_FILES['avatar']['error'] === UPLOAD_ERR_OK) {
                $chemin = adminreset_enregistrer_avatar($_FILES['avatar'], $id, $db);
                if ($chemin) {
                    $db->prepare("UPDATE utilisateurs SET photo=? WHERE id=?")->execute([$chemin, $id]);
                }
            }
            $message = "<div class='alert success'>" . htmlspecialchars(t('adminreset.compte_maj')) . "</div>";
        } catch (Exception $e) {
            $message = "<div class='alert danger'>" . htmlspecialchars(t('adminreset.err_sql')) . "</div>";
        }
    }
}

// --- TRI INTELLIGENT DES UTILISATEURS ---
$users = $db->query("SELECT * FROM utilisateurs ORDER BY role ASC, fonction ASC, username ASC")->fetchAll(PDO::FETCH_ASSOC);

$maintenance_users = [];
$portal_users = [];
$personnel_by_service = [];

foreach ($users as &$u) {
    $role_low = strtolower($u['role']);
    $has_password = !empty($u['password']);
    
    // Drapeau invisible pour le Javascript (Portail vs Employé)
    $u['is_portal'] = (in_array($role_low, ['admin', 'technicien']) || $has_password) ? true : false;

    if (in_array($role_low, ['admin', 'technicien'])) {
        $maintenance_users[] = $u;
    } elseif ($has_password) {
        $portal_users[] = $u;
    } else {
        if (!isset($personnel_by_service[$role_low])) $personnel_by_service[$role_low] = [];
        $personnel_by_service[$role_low][] = $u;
    }
}
unset($u);
$default_avatar = "img/user.png";
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(langue_actuelle()); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title><?php echo htmlspecialchars(t('adminreset.page_title_tag')); ?></title>
    <link rel="icon" type="image/png" href="img/logo.png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Caveat:wght@600&family=Segoe+UI:wght@300;400;600;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #2c3e50; --accent: #3498db; --success: #2ecc71; 
            --danger: #e74c3c; --gelpam-green: #2ecc71; --gelpam-orange: #f39c12;
        }

        body { 
    margin: 0; 
    font-family: 'Segoe UI', sans-serif; 
    /* On change 'center center' par 'center 110px' pour décaler l'image vers le bas */
    background: linear-gradient(rgba(0, 0, 0, 0.2), rgba(0, 0, 0, 0.2)), url('img/fond.jpg') no-repeat center 0px fixed; 
    background-size: cover; 
    min-height: 100vh;
    padding-top: 98px;
}

        /* --- STYLES NAVBAR ORIGINAUX --- */
        header { position: fixed; top: 0; width: 100%; z-index: 1000; overflow: hidden; box-shadow: 0 4px 20px rgba(0,0,0,0.25); }
        header::before { content: ""; position: absolute; top: -12px; left: -12px; right: -12px; bottom: -12px; background: linear-gradient(rgba(0, 0, 0, 0.2), rgba(0, 0, 0, 0.2)), url('img/fond.jpg') no-repeat center 0px fixed; background-size: cover; filter: blur(4px); z-index: -1; }
        .crumb-bar { display: flex; align-items: center; gap: 8px; font-size: 0.94rem; font-weight: 600; color: rgba(255,255,255,0.9); text-shadow: 0 1px 3px rgba(0,0,0,0.4); max-width: 99%; margin: 0 auto; padding: 0 10px 10px; }
        .crumb-bar .crumb-home { color: inherit; text-decoration: none; display: inline-flex; align-items: center; gap: 5px; }
        .crumb-bar .crumb-home:hover { text-decoration: underline; }
        .crumb-sep { color: rgba(255,255,255,0.45); font-weight: 400; }
        .crumb-current { color: rgba(255,255,255,0.75); font-weight: 400; }
        .header-top { display: flex; justify-content: space-between; align-items: center; padding: 12px 20px; }
        .header-title { font-family: 'Caveat', cursive; font-size: 1.5rem; color: white; background: rgba(255,255,255,0.12); backdrop-filter: blur(14px) brightness(1.15); -webkit-backdrop-filter: blur(14px) brightness(1.15); border: 1px solid rgba(255,255,255,0.4); border-radius: 20px; padding: 6px 18px; text-shadow: 0 2px 6px rgba(0,0,0,0.4); box-shadow: 0 6px 16px rgba(0,0,0,0.2); }
        .nav-tabs { display: flex; background: #fff; padding: 0 10px; gap: 2px; }
        .tab-item { padding: 10px 18px; text-decoration: none; color: #7f8c8d; font-weight: 600; font-size: 0.8rem; border-bottom: 3px solid transparent; transition: 0.3s; display: flex; align-items: center; gap: 8px; }
        .tab-item.active { color: var(--accent); border-bottom: 3px solid var(--accent); background: rgba(52, 152, 219, 0.05); }
        /* --- STYLE DU BADGE UTILISATEUR ET CLIGNOTANT VERT --- */
        .user-badge { background: rgba(255,255,255,0.12); backdrop-filter: blur(14px) brightness(1.15); -webkit-backdrop-filter: blur(14px) brightness(1.15); padding: 6px 14px; border-radius: 20px; font-size: 0.75rem; font-weight: 600; display: flex; align-items: center; gap: 8px; color: white; border: 1px solid rgba(255,255,255,0.4); text-shadow: 0 1px 3px rgba(0,0,0,0.4); box-shadow: 0 6px 16px rgba(0,0,0,0.2); }
        .status-pulse { width: 8px; height: 8px; border-radius: 50%; position: relative; background: #2ecc71; display: inline-block; }
        .status-pulse::after { content: ""; position: absolute; top: 0; left: 0; width: 100%; height: 100%; border-radius: 50%; background: #2ecc71; animation: pulse-dot 2s infinite; opacity: 0.6; }
        @keyframes pulse-dot { 0% { transform: scale(1); opacity: 0.6; } 100% { transform: scale(2.5); opacity: 0; } }

        .sidebar { height: 100%; width: 0; position: fixed; z-index: 3000; top: 0; left: 0; background-color: #1a252f; overflow-x: hidden; transition: 0.4s; padding-top: 60px; }
        .sidebar a { padding: 12px 25px; text-decoration: none; font-size: 1.05rem; color: #ecf0f1; display: block; transition: 0.3s; border-left: 4px solid transparent; }
        .sidebar a:hover { background: #2c3e50; border-left: 4px solid var(--accent); }
        .sidebar a.active-side { background: #2c3e50; border-left: 4px solid var(--accent); }
        .sidebar .closebtn { position: absolute; top: 10px; right: 25px; font-size: 36px; color: white; border: none; background: none; cursor: pointer; }
        .openbtn { font-size: 22px; cursor: pointer; background: none; border: none; color: var(--primary); padding: 5px 10px; }
        .btn-accueil { font-size: 18px; color: white; text-decoration: none; display: flex; align-items: center; justify-content: center; width: 38px; height: 38px; border-radius: 50%; background: rgba(255,255,255,0.12); backdrop-filter: blur(14px) brightness(1.15); -webkit-backdrop-filter: blur(14px) brightness(1.15); border: 1px solid rgba(255,255,255,0.4); box-shadow: 0 6px 16px rgba(0,0,0,0.2); transition: transform 0.2s, background 0.2s; }
        .btn-accueil:hover { background: rgba(255,255,255,0.28); transform: translateY(-2px); }
        .btn-accueil-green:hover { background: rgba(46, 204, 113, 0.3); border-color: rgba(46, 204, 113, 0.6); }

        /* --- STYLES DE LA PAGE ET CARTES --- */
        .container { max-width: 1200px; margin: 0 auto; padding: 10px 20px; }
        
        .btn-create-main { background: var(--gelpam-orange); color: white; border: none; padding: 15px 30px; font-size: 1.1rem; font-weight: 900; border-radius: 8px; cursor: pointer; box-shadow: 0 4px 15px rgba(243, 156, 18, 0.4); transition: 0.3s; margin-bottom: 20px; display: inline-block; }
        .btn-create-main:hover { transform: translateY(-3px); box-shadow: 0 8px 20px rgba(243, 156, 18, 0.6); }

        .section-title { font-family: 'Caveat', cursive; font-size: 1.8rem; color: white; margin-top: 10px; margin-bottom: 15px; border-bottom: 2px solid rgba(255,255,255,0.3); padding-bottom: 5px; text-shadow: 1px 1px 3px rgba(0,0,0,0.5); }
        
        .users-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(160px, 1fr)); gap: 12px; margin-bottom: 30px; }
        .user-card { background: rgba(255, 255, 255, 0.95); backdrop-filter: blur(5px); border-radius: 8px; padding: 10px; text-align: center; box-shadow: 0 4px 10px rgba(0,0,0,0.1); border-top: 3px solid var(--accent); cursor: pointer; transition: 0.2s; position: relative; }
        .user-card:hover { transform: translateY(-3px); box-shadow: 0 6px 15px rgba(0,0,0,0.2); }
        .user-card.role-admin { border-top-color: var(--gelpam-orange); }
        .user-card.role-technicien { border-top-color: var(--gelpam-green); }
        .user-card.role-portal { border-top-color: #8e44ad; }

        .avatar-container { width: 45px; height: 45px; border-radius: 50%; margin: 0 auto 5px auto; overflow: hidden; border: 2px solid white; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        .avatar-container img { width: 100%; height: 100%; object-fit: cover; }
        .u-name { font-weight: 800; font-size: 0.9rem; color: var(--primary); margin: 0; }
        .u-role { font-size: 0.65rem; color: #7f8c8d; text-transform: uppercase; font-weight: 700; display: block; margin-bottom: 3px;}
        .contact-mini { font-size: 0.65rem; color: #666; margin-top: 5px; border-top: 1px solid #eee; padding-top: 5px; line-height: 1.4; }
        .pin-badge { font-weight: bold; color: var(--danger); background: rgba(231,76,60,0.1); padding: 2px 5px; border-radius: 3px; display: inline-block; margin-bottom: 3px; font-size: 0.8rem;}

        /* --- MINI-CARTES (Personnel) --- */
        .personnel-panel { background: rgba(255, 255, 255, 0.95); border-radius: 12px; padding: 20px; box-shadow: 0 10px 20px rgba(0,0,0,0.15); margin-bottom: 30px; }
        .service-title { font-size: 1.1rem; color: var(--primary); border-bottom: 2px solid #eee; padding-bottom: 5px; margin-bottom: 10px; font-weight: 800; text-transform: uppercase; }
        .mini-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(130px, 1fr)); gap: 8px; margin-bottom: 20px;}
        .mini-grid .user-card { padding: 8px; border-top-width: 2px; border-top-color: var(--accent); }

        /* --- MODALES --- */
        .modal { display: none; position: fixed; z-index: 4000; left: 0; top: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.7); backdrop-filter: blur(5px); align-items: center; justify-content: center; overflow-y: auto;}
        .modal-content { background: white; width: 450px; border-radius: 15px; padding: 30px; position: relative; box-shadow: 0 20px 50px rgba(0,0,0,0.3); margin: 50px auto;}
        .modal-header { font-family: 'Caveat', cursive; font-size: 2rem; color: var(--primary); margin-bottom: 20px; border-bottom: 3px solid var(--accent); padding-bottom: 5px; }
        
        .form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 15px; }
        .form-group { display: flex; flex-direction: column; gap: 5px; margin-bottom: 5px;}
        .form-group.full { grid-column: 1 / -1; }
        .form-group label { font-weight: 800; font-size: 0.7rem; color: #666; text-transform: uppercase; }
        .form-group input, .form-group select { padding: 10px; border: 1px solid #ddd; border-radius: 6px; font-size: 0.9rem; font-family: inherit; }
        
        .btn-save { background: var(--accent); color: white; border: none; padding: 12px; border-radius: 6px; cursor: pointer; font-weight: 900; width: 100%; margin-top: 15px; transition: 0.2s;}
        .alert { padding: 10px; border-radius: 8px; margin-bottom: 15px; font-weight: bold; text-align: center; }
        .success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .danger { background: #fadbd8; color: #c0392b; border: 1px solid #e74c3c; }

        /* --- SELECTEUR INTELLIGENT --- */
        .type-selector { background: #f8f9fa; padding: 10px; border-radius: 8px; border: 1px solid #ddd; text-align: center; margin-bottom: 15px; grid-column: 1 / -1;}
        .type-selector label { display: inline-block; cursor: pointer; margin: 0 10px; font-size: 0.9rem; text-transform: none;}
        .hidden-field { display: none !important; }
    </style>
<?php include 'pwa_head.php'; ?>
</head>
<body onclick="checkCloseSidebar(event)">

<?php $breadcrumb_label = t('adminreset.breadcrumb'); include 'navbar.php'; ?>

<div class="container">
    <?php echo $message; ?>

    <div style="text-align: center;">
        <button class="btn-create-main" onclick="openAddModal()">
            <i class="fa-solid fa-user-plus"></i> <?php echo htmlspecialchars(t('adminreset.btn_new_user')); ?>
        </button>
    </div>

    <h2 class="section-title"><i class="fa-solid fa-wrench" style="color: var(--gelpam-green);"></i> <?php echo htmlspecialchars(t('adminreset.section_maintenance')); ?></h2>
    <div class="users-grid">
        <?php foreach($maintenance_users as $u):
            $role_class = ($u['role'] == 'admin') ? 'role-admin' : 'role-technicien';
            $role_display = ($u['role'] == 'admin') ? t('adminreset.role_admin') : t('adminreset.role_technicien');
            $avatar_url = !empty($u['photo']) ? $u['photo'] : $default_avatar;
        ?>
        <div class="user-card <?php echo $role_class; ?>" onclick='openEditModal(<?php echo htmlspecialchars(json_encode($u), ENT_QUOTES, 'UTF-8'); ?>)'>
            <div class="avatar-container"><img src="<?php echo $avatar_url; ?>" onerror="this.src='<?php echo $default_avatar; ?>'"></div>
            <p class="u-name"><?php echo htmlspecialchars($u['username']); ?></p>
            <span class="u-role"><?php echo htmlspecialchars($role_display); ?> <?php if(!empty($u['fonction'])) echo "<br>(".htmlspecialchars($u['fonction']).")"; ?></span>
            <div class="contact-mini">
                <div style="white-space: nowrap; overflow: hidden; text-overflow: ellipsis;"><i class="fa-solid fa-envelope"></i> <?php echo !empty($u['email']) ? htmlspecialchars($u['email']) : "-"; ?></div>
                <div><i class="fa-solid fa-phone"></i> <?php echo !empty($u['telephone']) ? htmlspecialchars($u['telephone']) : "-"; ?></div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <h2 class="section-title"><i class="fa-solid fa-door-open" style="color: var(--gelpam-orange);"></i> <?php echo htmlspecialchars(t('adminreset.section_portails')); ?></h2>
    <div class="users-grid">
        <?php foreach($portal_users as $u): $avatar_url = !empty($u['photo']) ? $u['photo'] : $default_avatar; ?>
        <div class="user-card role-portal" onclick='openEditModal(<?php echo htmlspecialchars(json_encode($u), ENT_QUOTES, 'UTF-8'); ?>)'>
            <div class="avatar-container"><img src="<?php echo htmlspecialchars($avatar_url); ?>" onerror="this.src='<?php echo $default_avatar; ?>'"></div>
            <p class="u-name"><?php echo htmlspecialchars($u['username']); ?></p>
            <span class="u-role"><?php echo htmlspecialchars(t('adminreset.compte_prefix')); ?> <?php echo htmlspecialchars($u['role']); ?></span>
            <div class="contact-mini">
                <div style="color: #8e44ad; font-weight: bold;"><i class="fa-solid fa-asterisk"></i> <?php echo htmlspecialchars(t('adminreset.code_secret_defini')); ?></div>
                <div style="white-space: nowrap; overflow: hidden; text-overflow: ellipsis;"><i class="fa-solid fa-envelope"></i> <?php echo !empty($u['email']) ? htmlspecialchars($u['email']) : "-"; ?></div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <h2 class="section-title"><i class="fa-solid fa-users" style="color: var(--accent);"></i> <?php echo htmlspecialchars(t('adminreset.section_personnel')); ?></h2>
    <div class="personnel-panel">
        <?php if(empty($personnel_by_service)): ?>
            <p style="text-align:center; color:#777; font-style:italic;"><?php echo htmlspecialchars(t('adminreset.no_personnel')); ?></p>
        <?php else: ?>
            <?php foreach($personnel_by_service as $service => $employes):
                // Formatage élégant pour le titre QHSE
                $service_name = ($service === 'qhse') ? t('adminreset.qhse_label') : ucfirst($service);
            ?>
                <div class="service-title"><i class="fa-solid fa-tag" style="color: var(--accent);"></i> <?php echo htmlspecialchars(t('adminreset.service_prefix')); ?> <?php echo htmlspecialchars($service_name); ?></div>
                <div class="mini-grid">
                    <?php foreach($employes as $u): $avatar_url = !empty($u['photo']) ? $u['photo'] : $default_avatar; ?>
                    <div class="user-card" onclick='openEditModal(<?php echo htmlspecialchars(json_encode($u), ENT_QUOTES, 'UTF-8'); ?>)'>
                        <div class="avatar-container" style="width:35px;height:35px;"><img src="<?php echo htmlspecialchars($avatar_url); ?>" onerror="this.src='<?php echo $default_avatar; ?>'"></div>
                        <p class="u-name" style="font-size:0.85rem;"><?php echo htmlspecialchars($u['prenom']." ".$u['username']); ?></p>
                        <span class="u-role" style="font-size:0.6rem;"><?php echo htmlspecialchars($u['fonction']); ?></span>
                        <?php if(!empty($u['code_personnel'])): ?>
                            <div class="pin-badge" style="font-size:0.75rem;"><i class="fa-solid fa-key"></i> <?php echo htmlspecialchars(t('adminreset.pin_prefix')); ?> <?php echo htmlspecialchars($u['code_personnel']); ?></div>
                        <?php else: ?>
                            <span class="u-role" style="color:#e67e22; font-size:0.6rem;"><?php echo htmlspecialchars(t('adminreset.attente_code')); ?></span>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<div id="modalAddUser" class="modal">
    <div class="modal-content">
        <h2 class="modal-header" style="color: var(--gelpam-orange);"><?php echo htmlspecialchars(t('adminreset.modal_new_title')); ?></h2>
        <form method="POST" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>">
            <input type="hidden" name="action" value="create_user">

            <div class="form-grid">
                <div class="form-group full" style="align-items:center;">
                    <label><?php echo htmlspecialchars(t('adminreset.label_photo')); ?></label>
                    <div style="display:flex; align-items:center; gap:12px;">
                        <div class="avatar-container" style="width:50px;height:50px;margin:0;"><img id="add_avatar_preview" src="img/user.png"></div>
                        <input type="file" name="avatar" accept="image/png,image/jpeg,image/webp" onchange="previewAvatar(this, 'add_avatar_preview')" style="flex:1;">
                    </div>
                </div>

                <div class="type-selector">
                    <label><input type="radio" name="acc_type" value="portail" onchange="toggleAddFields(this.value)" checked> <i class="fa-solid fa-door-open"></i> <?php echo htmlspecialchars(t('adminreset.radio_portail')); ?></label>
                    <label><input type="radio" name="acc_type" value="employe" onchange="toggleAddFields(this.value)"> <i class="fa-solid fa-user"></i> <?php echo htmlspecialchars(t('adminreset.radio_employe')); ?></label>
                </div>

                <div class="form-group full">
                    <label><?php echo htmlspecialchars(t('adminreset.label_identifiant')); ?></label>
                    <input type="text" name="new_username" required>
                </div>

                <div class="form-group full">
                    <label><?php echo htmlspecialchars(t('adminreset.label_service_affectation')); ?></label>
                    <select name="new_role" required>
                        <?php foreach ($services_dispo as $sv): ?>
                        <option value="<?php echo htmlspecialchars($sv['cle']); ?>"><?php echo htmlspecialchars($sv['label']); ?></option>
                        <?php endforeach; ?>
                        <option value="technicien"><?php echo htmlspecialchars(t('adminreset.opt_technicien_maintenance')); ?></option>
                        <option value="admin"><?php echo htmlspecialchars(t('adminreset.opt_admin_maintenance')); ?></option>
                    </select>
                </div>

                <div class="form-group full" id="add_grp_password">
                    <label><?php echo htmlspecialchars(t('adminreset.label_password_portail')); ?></label>
                    <input type="text" name="new_password" id="add_input_password" placeholder="<?php echo htmlspecialchars(t('adminreset.placeholder_password_obligatoire')); ?>" required>
                </div>

                <div class="form-group hidden-field" id="add_grp_prenom">
                    <label><?php echo htmlspecialchars(t('adminreset.label_prenom')); ?></label><input type="text" name="new_prenom">
                </div>
                <div class="form-group" id="add_grp_fonction">
                    <label><?php echo htmlspecialchars(t('adminreset.label_fonction')); ?></label><input type="text" name="new_fonction">
                </div>
                <div class="form-group full hidden-field" id="add_grp_pin">
                    <label style="color: var(--danger);"><i class="fa-solid fa-key"></i> <?php echo htmlspecialchars(t('adminreset.label_pin_2fa')); ?></label>
                    <input type="text" name="new_code_personnel" placeholder="<?php echo htmlspecialchars(t('adminreset.placeholder_pin_auto')); ?>" style="border: 1px solid var(--danger); text-align: center;">
                </div>

                <div class="form-group"><label><?php echo htmlspecialchars(t('adminreset.label_email')); ?></label><input type="email" name="new_email"></div>
                <div class="form-group"><label><?php echo htmlspecialchars(t('adminreset.label_telephone')); ?></label><input type="tel" name="new_telephone"></div>
            </div>

            <div class="form-group full">
                <button type="submit" class="btn-save" style="background: var(--gelpam-orange);"><?php echo htmlspecialchars(t('adminreset.btn_creer')); ?></button>
                <button type="button" onclick="closeAddModal()" style="width:100%; background:none; border:none; color:#888; cursor:pointer; margin-top:10px; font-weight:700;"><?php echo htmlspecialchars(t('adminreset.btn_annuler')); ?></button>
            </div>
        </form>
    </div>
</div>

<div id="modalEditUser" class="modal">
    <div class="modal-content">
        <h2 class="modal-header" id="modalUserName"><?php echo htmlspecialchars(t('adminreset.modal_edit_default_title')); ?></h2>
        <form method="POST" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>">
            <input type="hidden" name="user_id" id="form_id">
            <input type="hidden" name="username_hidden" id="form_username">
            <input type="hidden" name="remove_avatar" id="form_remove_avatar" value="0">

            <div class="form-grid">
                <div class="form-group full" style="align-items:center;">
                    <label><?php echo htmlspecialchars(t('adminreset.label_photo_actuelle')); ?></label>
                    <div style="display:flex; align-items:center; gap:12px;">
                        <div class="avatar-container" style="width:50px;height:50px;margin:0;"><img id="edit_avatar_preview" src="img/user.png" onerror="this.src='img/user.png'"></div>
                        <label style="font-weight:400; text-transform:none; font-size:0.8rem; flex:1;">
                            <?php echo htmlspecialchars(t('adminreset.label_remplacer_photo')); ?>
                            <input type="file" name="avatar" accept="image/png,image/jpeg,image/webp" onchange="document.getElementById('form_remove_avatar').value='0'; previewAvatar(this, 'edit_avatar_preview');">
                        </label>
                        <button type="button" id="btn_retirer_photo" onclick="retirerPhoto()" style="background:none; border:1px solid var(--danger); color:var(--danger); border-radius:6px; padding:6px 10px; font-size:0.75rem; font-weight:700; cursor:pointer; white-space:nowrap;"><?php echo htmlspecialchars(t('adminreset.btn_retirer_photo')); ?></button>
                    </div>
                </div>

                <div class="form-group full">
                    <label><?php echo htmlspecialchars(t('adminreset.label_changer_role')); ?></label>
                    <select name="role" id="form_role" required>
                        <?php foreach ($services_dispo as $sv): ?>
                        <option value="<?php echo htmlspecialchars($sv['cle']); ?>"><?php echo htmlspecialchars($sv['label']); ?></option>
                        <?php endforeach; ?>
                        <option value="technicien"><?php echo htmlspecialchars(t('adminreset.opt_technicien_maintenance')); ?></option>
                        <option value="admin"><?php echo htmlspecialchars(t('adminreset.opt_admin_maintenance')); ?></option>
                    </select>
                </div>

                <div class="form-group full" id="edit_grp_password">
                    <label><?php echo htmlspecialchars(t('adminreset.label_new_password_portail')); ?></label>
                    <input type="text" name="new_password" placeholder="<?php echo htmlspecialchars(t('adminreset.placeholder_password_unchanged')); ?>">
                </div>

                <div class="form-group hidden-field" id="edit_grp_prenom"><label><?php echo htmlspecialchars(t('adminreset.label_prenom')); ?></label><input type="text" name="prenom" id="form_prenom"></div>
                <div class="form-group" id="edit_grp_fonction"><label><?php echo htmlspecialchars(t('adminreset.label_fonction')); ?></label><input type="text" name="fonction" id="form_fonction"></div>

                <div class="form-group full hidden-field" id="edit_grp_pin">
                    <label style="color: var(--danger);"><i class="fa-solid fa-key"></i> <?php echo htmlspecialchars(t('adminreset.label_pin_personnel')); ?></label>
                    <input type="text" name="code_personnel" id="form_code_personnel" style="border: 2px solid var(--danger); text-align: center; font-weight: bold; letter-spacing: 2px; font-size: 1.2rem; color: var(--danger);">
                </div>

                <div class="form-group"><label><?php echo htmlspecialchars(t('adminreset.label_email')); ?></label><input type="email" name="email" id="form_email"></div>
                <div class="form-group"><label><?php echo htmlspecialchars(t('adminreset.label_telephone')); ?></label><input type="tel" name="telephone" id="form_telephone"></div>

                <div class="form-group full" style="margin-top: 10px; background: #f8f9fa; padding: 10px; border-radius: 6px;">
                    <label><?php echo htmlspecialchars(t('adminreset.label_etat_compte')); ?></label>
                    <select name="actif" id="form_actif" required style="border: 2px solid var(--primary); font-weight: bold;">
                        <option value="1"><?php echo htmlspecialchars(t('adminreset.opt_actif')); ?></option>
                        <option value="0"><?php echo htmlspecialchars(t('adminreset.opt_inactif')); ?></option>
                    </select>
                </div>
            </div>

            <div style="display: flex; gap: 10px; margin-top: 20px;">
                <button type="submit" class="btn-save" style="flex: 2; margin-top:0;"><?php echo htmlspecialchars(t('adminreset.btn_mettre_a_jour')); ?></button>
                <button type="button" onclick="supprimerUtilisateur()" class="btn-save" style="flex: 1; margin-top:0; background: var(--danger);" title="<?php echo htmlspecialchars(t('adminreset.tooltip_supprimer')); ?>">
                    <i class="fa-solid fa-trash"></i>
                </button>
            </div>
            <button type="button" onclick="closeEditModal()" style="width:100%; background:none; border:none; color:#888; cursor:pointer; margin-top:15px; font-weight:700;"><?php echo htmlspecialchars(t('adminreset.btn_fermer')); ?></button>
        </form>
    </div>
</div>

<div id="customConfirm" style="display:none; position:fixed; z-index:99999; left:0; top:0; width:100%; height:100%; background:rgba(0,0,0,0.5); backdrop-filter: blur(3px);">
    <div style="background:white; width:350px; margin:15% auto; padding:20px; border-radius:12px; text-align:center; box-shadow: 0 10px 25px rgba(0,0,0,0.2); border-top: 5px solid var(--danger);">
        <i class="fa-solid fa-triangle-exclamation" style="font-size:3rem; color:var(--danger); margin-bottom:15px;"></i>
        <h3 id="confirmTitle" style="margin:10px 0; color:var(--primary);"><?php echo htmlspecialchars(t('planning.confirmation_title')); ?></h3>
        <p id="confirmMessage" style="color:#666; font-size:0.9rem; margin-bottom:20px;"></p>
        <div style="display:flex; justify-content:center; gap:10px;">
            <button id="confirmCancel" style="padding:10px 20px; border:none; border-radius:6px; background:#eee; cursor:pointer; font-weight:bold; font-family: inherit;"><?php echo htmlspecialchars(t('maint.cancel')); ?></button>
            <button id="confirmOk" style="padding:10px 20px; border:none; border-radius:6px; background:var(--danger); color:white; cursor:pointer; font-weight:bold; font-family: inherit;"><?php echo htmlspecialchars(t('planning.btn_confirmer')); ?></button>
        </div>
    </div>
</div>

<script>
const I18N_ADMINRESET = <?php echo json_encode([
    'profil_prefix' => t('adminreset.profil_prefix'),
    'confirm_delete_user' => t('adminreset.confirm_delete_user'),
    'confirmation_title' => t('planning.confirmation_title'),
]); ?>;

function aspirineConfirm(titre, message) {
    return new Promise((resolve) => {
        const modal = document.getElementById('customConfirm');
        if (!modal) return resolve(confirm(message)); // Sécurité
        document.getElementById('confirmTitle').innerText = titre;
        document.getElementById('confirmMessage').innerText = message;
        modal.style.display = 'block';
        document.getElementById('confirmOk').onclick = () => { modal.style.display = 'none'; resolve(true); };
        document.getElementById('confirmCancel').onclick = () => { modal.style.display = 'none'; resolve(false); };
    });
}

function openNav(e) { e.stopPropagation(); document.getElementById("mySidebar").style.width = "280px"; }
function closeNav() { document.getElementById("mySidebar").style.width = "0"; }
function checkCloseSidebar(event) { if (document.getElementById("mySidebar").style.width === "280px" && !document.getElementById("mySidebar").contains(event.target)) closeNav(); }

function toggleAddFields(type) {
    const isEmploye = (type === 'employe');
    document.getElementById('add_grp_password').classList.toggle('hidden-field', isEmploye);
    document.getElementById('add_input_password').required = !isEmploye; 
    
    document.getElementById('add_grp_prenom').classList.toggle('hidden-field', !isEmploye);
    document.getElementById('add_grp_pin').classList.toggle('hidden-field', !isEmploye);
}
function previewAvatar(input, imgId) {
    if (input.files && input.files[0]) {
        const reader = new FileReader();
        reader.onload = function(e) { document.getElementById(imgId).src = e.target.result; };
        reader.readAsDataURL(input.files[0]);
    }
}

function openAddModal() {
    document.getElementById('modalAddUser').style.display = "flex";
    document.querySelector('input[name="acc_type"][value="portail"]').checked = true;
    toggleAddFields('portail');
    document.getElementById('add_avatar_preview').src = "img/user.png";
    document.querySelector('#modalAddUser input[name="avatar"]').value = "";
}
function closeAddModal() { document.getElementById('modalAddUser').style.display = "none"; }

function openEditModal(user) {
    document.getElementById('modalUserName').innerText = I18N_ADMINRESET.profil_prefix + " " + user.username;
    document.getElementById('form_id').value = user.id;
    document.getElementById('form_username').value = user.username;
    document.getElementById('form_role').value = user.role.toLowerCase();
    document.getElementById('form_prenom').value = user.prenom || "";
    document.getElementById('form_fonction').value = user.fonction || "";
    document.getElementById('form_email').value = user.email || "";
    document.getElementById('form_telephone').value = user.telephone || "";
    document.getElementById('form_code_personnel').value = user.code_personnel || "";
    document.getElementById('form_actif').value = user.actif !== undefined ? user.actif : 1;
    document.getElementById('form_remove_avatar').value = "0";
    document.getElementById('edit_avatar_preview').src = user.photo || "img/user.png";
    document.querySelector('#modalEditUser input[name="avatar"]').value = "";

    const isPortal = user.is_portal;
    
    document.getElementById('edit_grp_password').classList.toggle('hidden-field', !isPortal);
    document.getElementById('edit_grp_prenom').classList.toggle('hidden-field', isPortal);
    document.getElementById('edit_grp_pin').classList.toggle('hidden-field', isPortal);
    // Fonction / Poste toujours visible : utile aussi pour les comptes admin/technicien
    // (affiché comme intitulé de poste sur les cartes techniciens de maintenance.php).

    document.getElementById('modalEditUser').style.display = "flex";
}
function closeEditModal() { document.getElementById('modalEditUser').style.display = "none"; }

function retirerPhoto() {
    document.getElementById('form_remove_avatar').value = "1";
    document.querySelector('#modalEditUser input[name="avatar"]').value = "";
    document.getElementById('edit_avatar_preview').src = "img/user.png";
}

async function supprimerUtilisateur() {
    if (await aspirineConfirm(I18N_ADMINRESET.confirmation_title, I18N_ADMINRESET.confirm_delete_user)) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = `<input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES); ?>"><input type="hidden" name="action" value="delete_user"><input type="hidden" name="delete_id" value="${document.getElementById('form_id').value}">`;
        document.body.appendChild(form);
        form.submit();
    }
}

window.onclick = function(event) {
    if (event.target == document.getElementById('modalAddUser')) closeAddModal();
    if (event.target == document.getElementById('modalEditUser')) closeEditModal();
}
</script>
</body>
</html>