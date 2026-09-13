<?php
require_once __DIR__ . '/session_init.php';
require_once 'db.php';

$msg = ""; $status = "";

// Logo + liens externes configurables depuis Paramètres > Général (vides/génériques par défaut)
$logo_path_login = "img/logo.png";
$url_portail_login = "";
$url_demo_login = "";
try {
    $general_login = $db->query("SELECT cle, valeur FROM parametres_general")->fetchAll(PDO::FETCH_KEY_PAIR);
    if (!empty($general_login['logo_path'])) { $logo_path_login = $general_login['logo_path']; }
    if (!empty($general_login['url_portail'])) { $url_portail_login = $general_login['url_portail']; }
    if (!empty($general_login['url_demo'])) { $url_demo_login = $general_login['url_demo']; }
} catch (Exception $e) {}

// Capture du message de succès après changement obligatoire du code
if (isset($_GET['msg']) && $_GET['msg'] === 'mdp_modifie') {
    $msg = t('login.msg_password_updated');
    $status = "success";
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    try {
        // SÉCURITÉ : anti-bruteforce — 8 échecs maximum par IP sur 15 minutes
        $db->exec("CREATE TABLE IF NOT EXISTS login_attempts (
            id INT AUTO_INCREMENT PRIMARY KEY,
            ip VARCHAR(45),
            date_tentative DATETIME DEFAULT CURRENT_TIMESTAMP
        )");
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'inconnu';
        $stmtCount = $db->prepare("SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND date_tentative > (NOW() - INTERVAL 15 MINUTE)");
        $stmtCount->execute([$ip]);
        $trop_de_tentatives = ((int)$stmtCount->fetchColumn()) >= 8;

        if ($trop_de_tentatives) {
            $msg = t('login.msg_too_many_attempts');
            $status = "error";
        } else {

        // On utilise trim() pour éviter les espaces invisibles et on récupère les données
        $nom = trim($_POST['nom']);
        $password_input = trim($_POST['password']);

        // On cherche l'utilisateur (MariaDB ignore souvent la casse sur le nom, c'est plus sûr)
        $stmt = $db->prepare("SELECT * FROM utilisateurs WHERE username = ?");
        $stmt->execute([$nom]);
        $user = $stmt->fetch();

        // Liste noire des mots de passe interdits
        $mdp_interdits = ['1234', '12345', '123456', '0000', '5656', '5566', '5555'];

        // Vérification du mot de passe : hash sécurisé (password_hash), avec
        // repli sur l'ancienne comparaison en clair pour les comptes pas encore
        // migrés — dans ce cas le mot de passe est ré-enregistré sous forme de
        // hash juste après, de façon totalement transparente pour l'utilisateur.
        $password_ok = false;
        $besoin_migration = false;
        if ($user) {
            if (password_verify($password_input, $user['password'])) {
                $password_ok = true;
            } elseif ($user['password'] !== '' && hash_equals((string)$user['password'], $password_input)) {
                $password_ok = true;
                $besoin_migration = true;
            }
        }

        if ($password_ok) {

            // 1. SÉCURITÉ : Le compte est-il désactivé ?
            if (isset($user['actif']) && $user['actif'] == 0) {
                $msg = t('login.msg_account_disabled');
                $status = "error";
            }
            // 2. LE SAS DE SÉCURITÉ : Blocage si le mot de passe fait partie de la liste noire
            elseif (in_array($password_input, $mdp_interdits)) {
                $_SESSION['temp_id'] = $user['id'];
                $_SESSION['temp_user'] = $user['username'];
                header("Location: force_mdp.php");
                exit();
            }
            // 3. TOUT EST BON : Ouverture de la session normale
            else {
                if ($besoin_migration) {
                    $stmtMigrate = $db->prepare("UPDATE utilisateurs SET password = ? WHERE id = ?");
                    $stmtMigrate->execute([password_hash($password_input, PASSWORD_DEFAULT), $user['id']]);
                }

                $_SESSION['user'] = $user['username'];
                
                // PROTECTION : On force le rôle en minuscules
                $role_format = strtolower($user['role']);
                $_SESSION['role'] = $role_format;

                // ---> AJOUT AU JOURNAL (Traçabilité de la connexion) <---
                if (function_exists('ajouterLog')) {
                    ajouterLog($db, $_SESSION['user'], "Connexion", "S'est connecté au système (Rôle : " . ucfirst($role_format) . ")");
                }

                // Redirection selon le rôle
                if ($role_format === 'admin' || $role_format === 'technicien') {
                    header("Location: index.php"); // Accès à la GMAO
                } else {
                    header("Location: accueil.php"); // Portail simplifié pour les services
                }
                exit();
            }
        } else {
            // Échec : on journalise la tentative pour le compteur anti-bruteforce
            $db->prepare("INSERT INTO login_attempts (ip) VALUES (?)")->execute([$ip]);
            $msg = t('login.msg_wrong_credentials');
            $status = "error";
        }
        }
    } catch (Exception $e) {
        error_log("login.php: " . $e->getMessage());
        $msg = t('login.msg_system_error');
        $status = "error";
    }
}

// --- RÉCUPÉRATION DYNAMIQUE DES UTILISATEURS POUR LA LISTE DÉROULANTE ---
// Démo publique : un seul compte ("demo") proposé au choix, jamais la vraie liste de l'équipe
// (noms internes, organisation par service) — voir GMAO_EST_DEMO dans db.php.
$maint_users = [];
$other_users = [];
try {
    if (GMAO_EST_DEMO) {
        $other_users[] = 'demo';
    } else {
        // MODIFICATION : On exclut les opérateurs simples en vérifiant qu'ils ont bien un mot de passe de connexion
        $stmt_users = $db->query("SELECT username, role FROM utilisateurs WHERE password IS NOT NULL AND password != '' ORDER BY username ASC");
        while ($row = $stmt_users->fetch(PDO::FETCH_ASSOC)) {
            $role_lower = strtolower($row['role']);
            // Groupe Maintenance = admin ou technicien
            if ($role_lower === 'admin' || $role_lower === 'technicien') {
                $maint_users[] = $row['username'];
            } else {
                $other_users[] = $row['username'];
            }
        }
    }
} catch (Exception $e) {
    // Si la table n'existe pas ou erreur, les tableaux resteront vides
}
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(langue_actuelle()); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title><?php echo htmlspecialchars(t('login.title')); ?></title>
    <link rel="icon" type="image/png" href="img/logo.png">
    <link href="https://fonts.googleapis.com/css2?family=Caveat:wght@600&family=Segoe+UI:wght@400;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { 
    margin: 0; 
    font-family: 'Segoe UI', sans-serif; 
    /* "center 50px" signifie : centré horizontalement, et décalé de 50px vers le bas verticalement */
    background: linear-gradient(rgba(0,0,0,0.4), rgba(0,0,0,0.4)), url('img/fond.jpg') no-repeat center 0px fixed; 
    background-size: cover; 
    display: flex; 
    align-items: center; 
    justify-content: center; 
    height: 100vh; 
}
        .login-card {
            background: rgba(255, 255, 255, 0.95);
            padding: 40px;
            border-radius: 15px;
            box-shadow: 0 10px 25px rgba(0,0,0,0.3);
            width: 350px;
            text-align: center;
            backdrop-filter: blur(10px);
            transition: box-shadow 0.3s;
        }
        .login-card:hover {
            box-shadow:
                0 -10px 26px -10px rgba(46, 204, 113, 0.5),
                0 22px 38px -8px rgba(46, 204, 113, 0.6),
                0 10px 25px rgba(0,0,0,0.3);
        }
        .login-card img { display: block; margin: 0 auto -15px auto; }
        h1 { font-family: 'Caveat', cursive; color: #2c3e50; font-size: 1.9rem; margin-top: -30px; margin-bottom: 20px; }
        select, input { width: 100%; padding: 12px; margin: 10px 0; border: 1px solid #ddd; border-radius: 8px; font-size: 1rem; box-sizing: border-box; }
        button { width: 100%; padding: 12px; background: #3498db; border: none; color: white; font-weight: bold; border-radius: 8px; cursor: pointer; font-size: 1.1rem; transition: 0.3s; margin-top: 10px; }
        button:hover { background: #2980b9; transform: translateY(-2px); }
        .msg { padding: 10px; margin-bottom: 10px; border-radius: 8px; font-size: 0.9rem; font-weight: bold; }
        .error { background: #fadbd8; color: #e74c3c; border: 1px solid #e74c3c; }
        .success { background: #d5f5e3; color: #2ecc71; border: 1px solid #2ecc71; }
        .back-link { display: block; margin-top: 20px; font-size: 0.85rem; color: #7f8c8d; text-decoration: none; font-weight: 600; }
        .back-link:hover { color: #2c3e50; text-decoration: underline; }
        .portal-link {
            display: block;
            margin-top: 12px;
            padding: 10px;
            background: #27ae60;
            color: white;
            text-decoration: none;
            border-radius: 8px;
            font-weight: bold;
            text-align: center;
            transition: 0.3s;
        }
        .portal-link:hover {
            background: #1e8449;
            transform: translateY(-2px);
        }
        .demo-hint { margin: -8px 0 12px 0; padding: 8px 10px; background: #fff5e6; border: 1px dashed #f39c12; border-radius: 8px; color: #b9770e; font-size: 0.82rem; font-weight: 700; animation: demo-hint-blink 1.4s ease-in-out infinite; }
        @keyframes demo-hint-blink { 0%, 100% { opacity: 1; } 50% { opacity: 0.35; } }
    </style>
<?php include 'pwa_head.php'; ?>
</head>
<body>

<div style="position:fixed; top:16px; right:16px; z-index:10;">
    <?php include 'lang_switcher.php'; ?>
</div>

<div class="login-card">
    <img src="<?php echo htmlspecialchars($logo_path_login); ?>" height="150" alt="Logo">
    <h1><?php echo htmlspecialchars(t('login.heading')); ?></h1>

    <?php if (GMAO_EST_DEMO): ?>
    <div class="demo-hint"><i class="fa-solid fa-circle-info"></i> <?php echo t('login.demo_hint'); ?></div>
    <?php endif; ?>

    <?php if($msg): ?>
        <div class="msg <?php echo $status; ?>">
            <i class="fa-solid fa-circle-exclamation"></i> 
            <?php echo $msg; ?>
        </div>
    <?php endif; ?>

    <form method="POST">
        <select name="nom" required>
            <option value="" disabled selected><?php echo htmlspecialchars(t('login.who_are_you')); ?></option>
            
            <?php if (GMAO_EST_DEMO): ?>
                <?php foreach($other_users as $username): ?>
                    <option value="<?php echo htmlspecialchars($username); ?>"><?php echo htmlspecialchars($username); ?></option>
                <?php endforeach; ?>
            <?php else: ?>

            <?php if(count($maint_users) > 0): ?>
            <optgroup label="<?php echo htmlspecialchars(t('login.group_maintenance')); ?>">
                <?php foreach($maint_users as $username): ?>
                    <option value="<?php echo htmlspecialchars($username); ?>"><?php echo htmlspecialchars($username); ?></option>
                <?php endforeach; ?>
            </optgroup>
            <?php endif; ?>

            <?php if(count($other_users) > 0): ?>
            <optgroup label="<?php echo htmlspecialchars(t('login.group_other_services')); ?>">
                <?php foreach($other_users as $username): ?>
                    <option value="<?php echo htmlspecialchars($username); ?>"><?php echo htmlspecialchars($username); ?></option>
                <?php endforeach; ?>
            </optgroup>
            <?php endif; ?>

            <?php endif; ?>

        </select>
        
        <input type="password" name="password" placeholder="<?php echo htmlspecialchars(t('login.password_placeholder')); ?>" required>
        <button type="submit"><?php echo htmlspecialchars(t('login.submit')); ?></button>
    </form>

    <?php if (!GMAO_EST_DEMO && $url_portail_login !== ''): ?>
    <a href="<?php echo htmlspecialchars($url_portail_login); ?>" class="portal-link">
    <i class="fa-solid fa-house"></i> <?php echo htmlspecialchars(t('login.back_to_portal')); ?>
    </a>
    <?php endif; ?>

    <a href="password_change.php" class="back-link">
        <?php echo htmlspecialchars(t('login.change_password')); ?> <i class="fa-solid fa-arrow-right"></i>
    </a>

    <a href="aide_connexion.php" class="back-link">
        <?php echo htmlspecialchars(t('login.need_help')); ?> <i class="fa-solid fa-arrow-right"></i>
    </a>

    <?php if (!GMAO_EST_DEMO && $url_demo_login !== ''): ?>
    <a href="<?php echo htmlspecialchars($url_demo_login); ?>" target="_blank" rel="noopener" class="back-link">
        <?php echo htmlspecialchars(t('login.try_demo')); ?> <i class="fa-solid fa-arrow-right"></i>
    </a>
    <?php endif; ?>

</div>

</body>
</html>