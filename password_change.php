<?php
require_once __DIR__ . '/session_init.php';
require_once 'db.php'; // Connexion MariaDB officielle
require_once 'csrf.php';

$msg = ""; $status = "";

// Logo configurable depuis Paramètres > Général (repli sur le logo par défaut)
$logo_path_pwd = "img/logo.png";
try {
    $general_pwd = $db->query("SELECT cle, valeur FROM parametres_general")->fetchAll(PDO::FETCH_KEY_PAIR);
    if (!empty($general_pwd['logo_path'])) { $logo_path_pwd = $general_pwd['logo_path']; }
} catch (Exception $e) {}

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    try {
        if (!csrf_verifie($_POST['csrf_token'] ?? '')) {
            throw new Exception("Session expirée, merci de recharger la page et réessayer.");
        }
        $nom = $_POST['nom'];
        $old = $_POST['old_password'];
        $new = $_POST['new_password'];

        // On cherche l'utilisateur dans MariaDB (colonne username)
        $stmt = $db->prepare("SELECT * FROM utilisateurs WHERE username = ?");
        $stmt->execute([$nom]);
        $user = $stmt->fetch();

        // Vérification sécurisée : hash (password_hash), avec repli sur l'ancienne
        // comparaison en clair pour les comptes pas encore migrés.
        $password_ok = false;
        if ($user) {
            if (password_verify($old, $user['password'])) {
                $password_ok = true;
            } elseif ($user['password'] !== '' && hash_equals((string)$user['password'], $old)) {
                $password_ok = true;
            }
        }

        $mdp_interdits = ['1234', '12345', '123456', '0000', '5656', '5566', '5555'];

        if ($password_ok && in_array($new, $mdp_interdits)) {
            $msg = "Veuillez choisir un code sécurisé (les codes par défaut ou trop simples sont interdits).";
            $status = "error";
        } elseif ($password_ok) {

            // Mise à jour du code, toujours enregistré sous forme de hash sécurisé
            $update = $db->prepare("UPDATE utilisateurs SET password = ? WHERE username = ?");
            $update->execute([password_hash($new, PASSWORD_DEFAULT), $nom]);

            // Log dans l'historique
            $log = $db->prepare("INSERT INTO historique (utilisateur, action_type, details) VALUES (?, ?, ?)");
            $log->execute([$nom, "Sécurité", "A modifié son mot de passe"]);

            $msg = "Mot de passe mis à jour !";
            $status = "success";
        } else {
            $msg = "Ancien code incorrect.";
            $status = "error";
        }
    } catch (Exception $e) {
        error_log("password_change.php: " . $e->getMessage());
        $msg = "Erreur système, merci de réessayer.";
        $status = "error";
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Changer mon code - GMAO</title>
    <link rel="icon" type="image/png" href="img/logo.png">
    <link href="https://fonts.googleapis.com/css2?family=Caveat:wght@600&family=Segoe+UI:wght@400;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body {
            margin: 0; font-family: 'Segoe UI', sans-serif;
            background: linear-gradient(rgba(0,0,0,0.4), rgba(0,0,0,0.4)), url('img/fond.jpg') no-repeat center center fixed;
            background-size: cover;
            display: flex; align-items: center; justify-content: center; height: 100vh;
        }
        .login-card {
            background: rgba(255, 255, 255, 0.95);
            padding: 40px; border-radius: 15px;
            box-shadow: 0 10px 25px rgba(0,0,0,0.3);
            width: 350px; text-align: center;
            backdrop-filter: blur(10px);
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
    </style>
<?php include 'pwa_head.php'; ?>
</head>
<body>

<div class="login-card">
    <img src="<?php echo htmlspecialchars($logo_path_pwd); ?>" height="150" alt="Logo">
    <h1>Sécurité Compte</h1>

    <?php if($msg): ?>
        <div class="msg <?php echo $status; ?>">
            <i class="fa-solid <?php echo $status === 'success' ? 'fa-circle-check' : 'fa-circle-exclamation'; ?>"></i> 
            <?php echo $msg; ?>
        </div>
    <?php endif; ?>

    <form method="POST">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>">
        <select name="nom" required>
            <option value="" disabled selected>Qui êtes-vous ?</option>
            
            <optgroup label="SERVICE MAINTENANCE">
                <option value="Technicien 1">Technicien 1</option>
                <option value="Technicien 2">Technicien 2</option>
                <option value="Technicien 3">Technicien 3</option>
                <option value="Technicien 5">Technicien 5</option>
                <option value="Technicien 4">Technicien 4</option>
                <option value="Technicien 6">Technicien 6</option>
                <option value="Technicien 7">Technicien 7</option>
            </optgroup>

            <optgroup label="AUTRES SERVICES">
                <option value="Direction">Direction / Chef de service</option>
                <option value="S. Sécurité">Service Sécurité</option>
                <option value="S. Qualité">Service Qualité</option>
                <option value="S. Production">Service Production</option>
                <option value="S. Logistique">Service Logistique</option>
                <option value="S. Agronomie">Service Agronomie</option>
            </optgroup>
        </select>
        
        <input type="password" name="old_password" placeholder="Ancien code secret" required>
        <input type="password" name="new_password" placeholder="Nouveau code secret" required>
        
        <button type="submit">VALIDER LE CHANGEMENT</button>
    </form>

    <a href="login.php" class="back-link">
        <i class="fa-solid fa-arrow-left"></i> Retour à la connexion
    </a>
</div>

</body>
</html>