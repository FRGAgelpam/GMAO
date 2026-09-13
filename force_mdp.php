<?php
require_once __DIR__ . '/session_init.php';
require 'db.php';
require 'csrf.php';

// Si l'utilisateur n'est pas passé par le sas, on le jette
if (!isset($_SESSION['temp_id'])) {
    header("Location: login.php");
    exit();
}

$message = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['nouveau_mdp'])) {
    $nouveau_mdp = trim($_POST['nouveau_mdp']);
    $confirm_mdp = trim($_POST['confirm_mdp']);

    // LA MÊME LISTE NOIRE ICI
    $mdp_interdits = ['1234', '12345', '123456', '0000', '5656', '5566', '5555'];

    if (!csrf_verifie($_POST['csrf_token'] ?? '')) {
        $message = "<div class='alert danger'>Session expirée, merci de recharger la page et réessayer.</div>";
    } elseif (empty($nouveau_mdp) || in_array($nouveau_mdp, $mdp_interdits)) {
        $message = "<div class='alert danger'>Veuillez choisir un code sécurisé (les codes par défaut ou trop simples sont interdits).</div>";
    } elseif ($nouveau_mdp !== $confirm_mdp) {
        $message = "<div class='alert danger'>Les mots de passe ne correspondent pas.</div>";
    } else {
        // Mise à jour dans la base de données (toujours sous forme de hash sécurisé)
        $stmt = $db->prepare("UPDATE utilisateurs SET password = ? WHERE id = ?");
        $stmt->execute([password_hash($nouveau_mdp, PASSWORD_DEFAULT), $_SESSION['temp_id']]);

        // On détruit la session temporaire pour l'obliger à se reconnecter avec le nouveau code
        session_destroy();
        
        // Redirection vers le login avec un message de succès
        header("Location: login.php?msg=mdp_modifie");
        exit();
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Sécurité GMAO - Mise à jour requise</title>
    <link href="https://fonts.googleapis.com/css2?family=Segoe+UI:wght@400;600;800&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Segoe UI', sans-serif; background: #1a252f; display: flex; justify-content: center; align-items: center; height: 100vh; margin: 0; }
        .box { background: white; padding: 40px; border-radius: 12px; box-shadow: 0 10px 30px rgba(0,0,0,0.5); width: 100%; max-width: 400px; text-align: center; border-top: 5px solid #e74c3c; }
        input { width: 90%; padding: 12px; margin: 10px 0; border: 1px solid #ccc; border-radius: 6px; font-size: 1rem; box-sizing: border-box; }
        button { background: #e74c3c; color: white; border: none; padding: 15px; width: 100%; border-radius: 6px; font-weight: bold; cursor: pointer; font-size: 1.1rem; margin-top: 15px; transition: 0.3s; }
        button:hover { background: #c0392b; transform: translateY(-2px); }
        .alert.danger { color: #c0392b; background: #fadbd8; padding: 10px; border-radius: 6px; margin-bottom: 15px; font-weight: bold;}
    </style>
<?php include 'pwa_head.php'; ?>
</head>
<body>
    <div class="box">
        <h2 style="color: #e74c3c; margin-top: 0;">Mise à jour de sécurité</h2>
        <p style="color: #666; font-size: 0.9rem;">Bonjour <strong><?php echo htmlspecialchars($_SESSION['temp_user']); ?></strong>.<br><br>Pour des raisons de sécurité, le code d'accès par défaut n'est plus autorisé. Veuillez créer votre code personnel.</p>
        
        <?php echo $message; ?>
        
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>">
            <input type="password" name="nouveau_mdp" placeholder="Nouveau code secret" required>
            <input type="password" name="confirm_mdp" placeholder="Confirmer le code secret" required>
            <button type="submit">VALIDER MON NOUVEAU CODE</button>
        </form>
    </div>
</body>
</html>