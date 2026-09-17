<?php
require_once __DIR__ . '/session_init.php';
require_once 'db.php';

if (!isset($_SESSION['user'])) { header("Location: login.php"); exit(); }

try {
    $db->exec("CREATE TABLE IF NOT EXISTS idees_amelioration (
        id INT AUTO_INCREMENT PRIMARY KEY,
        service VARCHAR(255),
        demandeur VARCHAR(255),
        titre VARCHAR(255),
        description TEXT,
        categorie VARCHAR(50),
        statut VARCHAR(30) DEFAULT 'Nouvelle',
        date_creation DATETIME DEFAULT CURRENT_TIMESTAMP,
        reponse_admin TEXT NULL,
        date_reponse DATETIME NULL
    )");
    $db->exec("CREATE TABLE IF NOT EXISTS idees_messages (
        id INT AUTO_INCREMENT PRIMARY KEY,
        idee_id INT NOT NULL,
        expediteur VARCHAR(255),
        message TEXT,
        is_admin TINYINT(1) DEFAULT 0,
        lu TINYINT(1) DEFAULT 0,
        date_envoi DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
} catch (Exception $e) {}

// --- AJAX : ENVOI D'UNE IDÉE ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'submit_idee') {
    header('Content-Type: application/json');
    $nom = trim($_POST['nom'] ?? '');
    $titre = trim($_POST['titre'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $categorie = trim($_POST['categorie'] ?? 'Autre');

    if ($nom === '' || $titre === '' || $description === '') {
        echo json_encode(['success' => false, 'error' => 'champs_manquants']);
        exit();
    }

    try {
        $stmt = $db->prepare("INSERT INTO idees_amelioration (service, demandeur, titre, description, categorie, statut) VALUES (?, ?, ?, ?, ?, 'Nouvelle')");
        $stmt->execute([$_SESSION['user'], $nom, $titre, $description, $categorie]);

        if (function_exists('ajouterLog')) {
            ajouterLog($db, $_SESSION['user'], "Idée GMAO", "Nouvelle idée proposée : " . $titre);
        }

        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => 'db_error']);
    }
    exit();
}

// --- AJAX : LE SERVICE RÉPOND À L'ADMINISTRATEUR SUR UNE IDÉE ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'repondre_service') {
    header('Content-Type: application/json');
    $idee_id = (int)($_POST['idee_id'] ?? 0);
    $message = trim($_POST['message'] ?? '');

    if (!$idee_id || $message === '') {
        echo json_encode(['success' => false, 'error' => 'champs_manquants']);
        exit();
    }

    try {
        // Sécurité : l'idée doit appartenir au service actuellement connecté
        $chk = $db->prepare("SELECT id FROM idees_amelioration WHERE id = ? AND service = ?");
        $chk->execute([$idee_id, trim($_SESSION['user'])]);
        if (!$chk->fetch()) {
            echo json_encode(['success' => false, 'error' => 'non_autorise']);
            exit();
        }

        $stmt = $db->prepare("INSERT INTO idees_messages (idee_id, expediteur, message, is_admin, lu) VALUES (?, ?, ?, 0, 0)");
        $stmt->execute([$idee_id, trim($_SESSION['user']), $message]);

        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => 'db_error']);
    }
    exit();
}

$user_session = trim($_SESSION['user']);

// --- MES IDÉES DÉJÀ ENVOYÉES (par service), AVEC LEUR FIL DE MESSAGES ---
$mes_idees = [];
$messagesParIdee = [];
try {
    $stmt = $db->prepare("SELECT * FROM idees_amelioration WHERE service = ? ORDER BY date_creation DESC");
    $stmt->execute([$user_session]);
    $mes_idees = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (!empty($mes_idees)) {
        $ids = array_column($mes_idees, 'id');
        $placeholders = implode(',', array_fill(0, count($ids), '?'));

        $stmtMsg = $db->prepare("SELECT * FROM idees_messages WHERE idee_id IN ($placeholders) ORDER BY date_envoi ASC");
        $stmtMsg->execute($ids);
        foreach ($stmtMsg->fetchAll(PDO::FETCH_ASSOC) as $m) {
            $messagesParIdee[$m['idee_id']][] = $m;
        }

        // On marque les messages admin comme lus APRÈS avoir capturé leur état ci-dessus,
        // pour que le badge "NOUVEAU" de cette page reflète encore l'état avant la visite.
        $db->prepare("UPDATE idees_messages SET lu = 1 WHERE idee_id IN ($placeholders) AND is_admin = 1 AND lu = 0")->execute($ids);
    }
} catch (Exception $e) {}

?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(langue_actuelle()); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title><?php echo htmlspecialchars(t('idee.page_title')); ?></title>
    <link rel="icon" type="image/png" href="img/logo.png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Caveat:wght@600;700&family=Segoe+UI:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root { --primary: #2c3e50; --accent: #3498db; --brand-orange: #f39c12; --brand-green: #2ecc71; --danger: #e74c3c; --violet: #8e44ad; }
        * { box-sizing: border-box; }
        body {
            margin: 0; font-family: 'Segoe UI', sans-serif;
            background: linear-gradient(rgba(0,0,0,0.5), rgba(0,0,0,0.5)), url('img/fond.jpg') no-repeat center center fixed;
            background-size: cover; display: flex; justify-content: center; align-items: flex-start; min-height: 100vh; padding: 58px 20px 20px; box-sizing: border-box;
        }

        .page-wrap { width: 100%; max-width: 1180px; display:flex; flex-direction:column; gap: 16px; }

        .cards-row { display: flex; gap: 16px; align-items: flex-start; }
        .cards-row .card { flex: 1 1 0; min-width: 0; }
        .cards-row .card:last-child { max-height: 82vh; overflow-y: auto; }

        @media (max-width: 860px) {
            .cards-row { flex-direction: column; }
            .cards-row .card:last-child { max-height: none; overflow-y: visible; }
        }

        .topbar {
            background: rgba(255,255,255,0.97); border-radius: 14px; box-shadow: 0 10px 25px rgba(0,0,0,0.4);
            padding: 10px 18px; display:flex; align-items:center; justify-content:space-between;
        }
        .topbar-links { display:flex; align-items:center; gap: 18px; }
        .topbar-links a { color:#94a3b8; text-decoration:none; font-size: 0.78rem; display:flex; align-items:center; gap:6px; font-weight:600; }
        .topbar-links a:hover { color: var(--brand-orange); }
        .topbar-links a.logout:hover { color: var(--danger); }
        .who-badge { background:#f1f5f9; padding: 5px 12px; border-radius: 20px; font-size: 0.78rem; font-weight: 600; color: var(--primary); }

        .card { background: rgba(255, 255, 255, 0.97); border-radius: 16px; box-shadow: 0 10px 30px rgba(0,0,0,0.5); padding: 24px 26px; }

        .card-head { display:flex; align-items:center; gap: 12px; margin-bottom: 4px; }
        .card-head i { font-size: 1.6rem; color: var(--brand-orange); }
        .card-head h2 { font-family: 'Caveat', cursive; color: var(--primary); font-size: 1.7rem; margin: 0; }
        .card-sub { color:#94a3b8; font-size: 0.78rem; margin: 0 0 18px; }

        .field { margin-bottom: 14px; }
        label { display: block; font-size: 0.7rem; font-weight: 600; color: #555; margin-bottom: 4px; text-transform: uppercase; }
        input, select, textarea { width: 100%; padding: 10px 12px; border: 1px solid #ddd; border-radius: 8px; box-sizing: border-box; font-size: 0.9rem; font-family: inherit; background: #fff; }
        input:focus, select:focus, textarea:focus { outline: none; border-color: var(--brand-orange); box-shadow: 0 0 0 3px rgba(243,156,18,0.15); }
        textarea { resize: vertical; min-height: 90px; }

        .btn-send { width: 100%; padding: 13px; border: none; border-radius: 8px; background: linear-gradient(135deg, #3ddc84, var(--brand-green)); color: #fff; font-weight: 700; font-size: 0.9rem; cursor: pointer; transition: 0.2s; display:flex; align-items:center; justify-content:center; gap: 8px; box-shadow: 0 3px 8px rgba(46,204,113,0.35); }
        .btn-send:hover:not(:disabled) { background: #27ae60; transform: translateY(-1px); box-shadow: 0 5px 12px rgba(46,204,113,0.45); }
        .btn-send:disabled { background: #cbd5e1; cursor: not-allowed; }

        .form-msg { font-size: 0.72rem; font-weight: 600; text-align:center; margin-top: 10px; min-height: 14px; }
        .form-msg.error { color: var(--danger); }
        .form-msg.success { color: var(--brand-green); }

        .idees-title { font-family: 'Caveat', cursive; color: var(--primary); font-size: 1.5rem; margin: 0 0 12px; display:flex; align-items:center; gap:10px; }
        .idees-title i { color: var(--brand-orange); }

        .idee-item { background: #fff; border: 1px solid #e2e8f0; border-radius: 10px; padding: 12px 14px; margin-bottom: 8px; cursor: pointer; transition: box-shadow 0.15s, border-color 0.15s, transform 0.15s; }
        .idee-item:hover { box-shadow: 0 3px 10px rgba(15,23,42,0.08); transform: translateY(-1px); }
        .idee-item-nouveau { border-color: #e84393; box-shadow: 0 0 0 1px rgba(232,67,147,0.25); }
        .idee-item-top { display:flex; justify-content:space-between; align-items:flex-start; gap: 10px; }
        .idee-titre { font-weight: 700; color: var(--primary); font-size: 0.9rem; display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
        .idee-meta { font-size: 0.65rem; color: #94a3b8; margin-top: 3px; }

        .badge-nouveau { font-size: 0.58rem; font-weight: 800; letter-spacing: 0.4px; color: #fff; background: #e84393; padding: 2px 7px; border-radius: 10px; animation: pulse-nouveau 1.6s ease-in-out infinite; }
        @keyframes pulse-nouveau { 0%, 100% { opacity: 1; } 50% { opacity: 0.55; } }

        .idee-chevron { color: #cbd5e1; font-size: 0.85rem; transition: transform 0.2s ease; }
        .idee-item.is-open .idee-chevron { transform: rotate(180deg); }
        .idee-item-body { display: none; margin-top: 10px; }
        .idee-item.is-open .idee-item-body { display: block; }

        .statut-pill { font-size: 0.6rem; font-weight: 700; padding: 3px 9px; border-radius: 20px; color: #fff; white-space: nowrap; text-transform: uppercase; }
        .statut-nouvelle { background: var(--accent); }
        .statut-etude { background: #16a085; }
        .statut-acceptee { background: var(--brand-green); }
        .statut-realisee { background: var(--violet); }
        .statut-rejetee { background: var(--danger); }

        .empty-state { text-align:center; padding: 18px; color:#94a3b8; font-style:italic; font-size: 0.85rem; }

        .btn-floating-nav {
            position: fixed; top: 20px; left: 20px; z-index: 1000;
            width: 46px; height: 46px; border-radius: 50%;
            background: rgba(255, 255, 255, 0.12);
            backdrop-filter: blur(14px) brightness(1.15);
            -webkit-backdrop-filter: blur(14px) brightness(1.15);
            border: 1px solid rgba(255,255,255,0.4);
            color: white; font-size: 1.15rem; display: flex; align-items: center; justify-content: center;
            text-decoration: none; box-shadow: 0 8px 20px rgba(0,0,0,0.25);
            transition: transform 0.3s, box-shadow 0.3s, background 0.3s, border-color 0.3s;
        }
        .btn-floating-nav:hover { transform: translateY(-3px); }
        .btn-floating-nav.home:hover { background: rgba(46,204,113,0.3); border-color: rgba(46,204,113,0.6); box-shadow: 0 14px 26px -8px rgba(46,204,113,0.5), 0 8px 18px rgba(0,0,0,0.2); }
        .crumb-bar { position: fixed; top: 33px; left: 76px; z-index: 1000; display: flex; align-items: center; gap: 8px; font-size: 0.94rem; font-weight: 600; color: rgba(255,255,255,0.9); text-shadow: 0 1px 3px rgba(0,0,0,0.4); }
        .crumb-bar .crumb-home { color: inherit; text-decoration: none; display: inline-flex; align-items: center; gap: 5px; }
        .crumb-bar .crumb-home:hover { text-decoration: underline; }
        .crumb-sep { color: rgba(255,255,255,0.55); font-weight: 400; }
        .crumb-current { color: rgba(255,255,255,0.75); font-weight: 400; }
        @media (max-width: 600px) { .crumb-bar { display: none; } }

        /* --- FIL DE DISCUSSION D'UNE IDÉE (dans le corps déplié de la carte) --- */
        .idee-desc-body { font-size: 0.8rem; color: #64748b; line-height: 1.5; white-space: pre-line; background: #f8fafc; padding: 10px 12px; border-radius: 8px; border: 1px solid #eef2f5; }

        .idee-thread { padding: 12px 2px; display: flex; flex-direction: column; gap: 10px; max-height: 260px; overflow-y: auto; }
        .idee-thread-vide { text-align: center; color: #94a3b8; font-size: 0.78rem; font-style: italic; padding: 12px 0; }

        .msg-bulle { max-width: 82%; padding: 9px 12px; border-radius: 14px; font-size: 0.82rem; line-height: 1.4; word-wrap: break-word; box-shadow: 0 1px 3px rgba(15,23,42,0.08); }
        .msg-admin { align-self: flex-start; background: linear-gradient(135deg, #ffb75e, #f5942b); color: #fff; border-bottom-left-radius: 4px; }
        .msg-service { align-self: flex-end; background: linear-gradient(135deg, #4f8ef7, #3d6fe0); color: #fff; border-bottom-right-radius: 4px; }
        .msg-auteur { font-size: 0.6rem; font-weight: 700; text-transform: uppercase; opacity: 0.65; margin-bottom: 2px; }
        .msg-date { font-size: 0.58rem; opacity: 0.6; margin-top: 4px; text-align: right; }

        .idee-reply-err { font-size: 0.7rem; color: var(--danger); font-weight: 600; min-height: 14px; margin-top: 4px; }

        .idee-reply-form { display: flex; gap: 8px; margin-top: 6px; }
        .idee-reply-form textarea { flex: 1; min-height: 44px; max-height: 100px; resize: vertical; }
        .idee-reply-form button { background: linear-gradient(135deg, #3ddc84, var(--brand-green)); color: #fff; border: none; border-radius: 10px; padding: 0 18px; font-weight: 700; cursor: pointer; flex-shrink: 0; transition: 0.2s; box-shadow: 0 3px 8px rgba(46,204,113,0.35); }
        .idee-reply-form button:hover:not(:disabled) { background: #27ae60; transform: translateY(-1px); box-shadow: 0 5px 12px rgba(46,204,113,0.45); }
        .idee-reply-form button:disabled { background: #cbd5e1; cursor: not-allowed; }
    </style>
<?php include 'pwa_head.php'; ?>
</head>
<body>

<a href="accueil.php" class="btn-floating-nav home" title="<?php echo htmlspecialchars(t('idee.home_tooltip')); ?>" aria-label="<?php echo htmlspecialchars(t('idee.home_tooltip')); ?>"><i class="fa-solid fa-house"></i></a>
<div style="position:fixed; top:20px; right:20px; z-index:1000;">
    <?php include 'lang_switcher.php'; ?>
</div>
<div class="crumb-bar">
    <a href="accueil.php" class="crumb-home"><?php echo htmlspecialchars(t('idee.crumb_portal')); ?></a>
    <span class="crumb-sep">/</span>
    <span class="crumb-current"><?php echo htmlspecialchars(t('idee.crumb_current')); ?></span>
</div>

<div class="page-wrap">
    <div class="topbar">
        <span class="who-badge"><i class="fa-solid fa-building"></i> <?php echo htmlspecialchars($_SESSION['user']); ?></span>
    </div>

    <div class="cards-row">
    <div class="card">
        <div class="card-head">
            <i class="fa-solid fa-lightbulb"></i>
            <h2><?php echo htmlspecialchars(t('idee.form_title')); ?></h2>
        </div>
        <p class="card-sub"><?php echo htmlspecialchars(t('idee.form_subtitle')); ?></p>

        <form id="form-idee">
            <div class="field">
                <label><?php echo htmlspecialchars(t('idee.label_nom')); ?></label>
                <input type="text" id="i-nom" placeholder="<?php echo htmlspecialchars(t('idee.nom_placeholder')); ?>" required>
            </div>

            <div class="field">
                <label><?php echo htmlspecialchars(t('idee.label_categorie')); ?></label>
                <select id="i-categorie">
                    <option value="Fonctionnalité"><?php echo htmlspecialchars(t('idee.cat_fonctionnalite')); ?></option>
                    <option value="Ergonomie"><?php echo htmlspecialchars(t('idee.cat_ergonomie')); ?></option>
                    <option value="Bug"><?php echo htmlspecialchars(t('idee.cat_bug')); ?></option>
                    <option value="Autre"><?php echo htmlspecialchars(t('idee.cat_autre')); ?></option>
                </select>
            </div>

            <div class="field">
                <label><?php echo htmlspecialchars(t('idee.label_titre')); ?></label>
                <input type="text" id="i-titre" placeholder="<?php echo htmlspecialchars(t('idee.titre_placeholder')); ?>" required>
            </div>

            <div class="field">
                <label><?php echo htmlspecialchars(t('idee.label_description')); ?></label>
                <textarea id="i-description" placeholder="<?php echo htmlspecialchars(t('idee.description_placeholder')); ?>" required></textarea>
            </div>

            <button type="submit" class="btn-send" id="btn-send-idee"><i class="fa-solid fa-paper-plane"></i> <?php echo htmlspecialchars(t('idee.btn_send')); ?></button>
            <div class="form-msg" id="form-idee-msg"></div>
        </form>
    </div>

    <div class="card">
        <h3 class="idees-title"><i class="fa-solid fa-list-check"></i> <?php echo htmlspecialchars(t('idee.list_title')); ?></h3>
        <div id="liste-idees">
            <?php if (empty($mes_idees)): ?>
                <div class="empty-state">
                    <i class="fa-solid fa-comment-dots" style="font-size: 1.8rem; margin-bottom: 8px; display:block; color:#cbd5e1;"></i>
                    <?php echo htmlspecialchars(t('idee.empty_list')); ?>
                </div>
            <?php else: ?>
                <?php foreach ($mes_idees as $idee):
                    $statutClass = 'statut-nouvelle';
                    $statutLabel = t('idee.statut_nouvelle');
                    switch ($idee['statut']) {
                        case 'À l\'étude': $statutClass = 'statut-etude'; $statutLabel = t('idee.statut_etude'); break;
                        case 'Acceptée': $statutClass = 'statut-acceptee'; $statutLabel = t('idee.statut_acceptee'); break;
                        case 'Réalisée': $statutClass = 'statut-realisee'; $statutLabel = t('idee.statut_realisee'); break;
                        case 'Rejetée': $statutClass = 'statut-rejetee'; $statutLabel = t('idee.statut_rejetee'); break;
                    }
                    $dateStr = date('d/m/Y', strtotime($idee['date_creation'])) . ' ' . t('idee.date_at') . ' ' . date('H:i', strtotime($idee['date_creation']));

                    $msgsIdee = $messagesParIdee[$idee['id']] ?? [];
                    $estNouveau = false;
                    foreach ($msgsIdee as $m) {
                        if ((int)$m['is_admin'] === 1 && (int)$m['lu'] === 0) { $estNouveau = true; break; }
                    }
                    // Rétro-compatibilité : les idées répondues avant l'ajout du fil de discussion
                    // n'ont qu'un reponse_admin isolé. On l'affiche comme premier message du fil.
                    if (empty($msgsIdee) && !empty($idee['reponse_admin'])) {
                        $msgsIdee[] = [
                            'expediteur' => t('idee.auteur_admin'),
                            'message' => $idee['reponse_admin'],
                            'is_admin' => 1,
                            'date_envoi' => $idee['date_reponse'] ?? $idee['date_creation'],
                        ];
                    }
                ?>
                <div class="idee-item <?php echo $estNouveau ? 'idee-item-nouveau' : ''; ?>">
                    <div class="idee-item-top" onclick="toggleIdeeItem(this)">
                        <div>
                            <div class="idee-titre">
                                <?php echo htmlspecialchars($idee['titre']); ?>
                                <?php if ($estNouveau): ?><span class="badge-nouveau"><?php echo htmlspecialchars(t('idee.badge_nouveau')); ?></span><?php endif; ?>
                            </div>
                            <div class="idee-meta"><?php echo htmlspecialchars($idee['demandeur']); ?> · <?php echo $dateStr; ?> · <?php echo htmlspecialchars($idee['categorie']); ?></div>
                        </div>
                        <div style="display:flex; align-items:center; gap:8px; flex-shrink:0;">
                            <span class="statut-pill <?php echo $statutClass; ?>"><?php echo $statutLabel; ?></span>
                            <i class="fa-solid fa-chevron-down idee-chevron"></i>
                        </div>
                    </div>

                    <div class="idee-item-body">
                        <div class="idee-desc-body"><?php echo nl2br(htmlspecialchars($idee['description'])); ?></div>

                        <?php if (!empty($msgsIdee)): ?>
                            <div class="idee-thread">
                                <?php foreach ($msgsIdee as $m): ?>
                                    <div class="msg-bulle <?php echo $m['is_admin'] ? 'msg-admin' : 'msg-service'; ?>">
                                        <div class="msg-auteur"><?php echo $m['is_admin'] ? htmlspecialchars(t('idee.auteur_admin')) : htmlspecialchars(t('idee.auteur_vous')); ?></div>
                                        <div class="msg-texte"><?php echo nl2br(htmlspecialchars($m['message'])); ?></div>
                                        <div class="msg-date"><?php echo date('d/m/Y', strtotime($m['date_envoi'])) . ' ' . htmlspecialchars(t('idee.date_at')) . ' ' . date('H:i', strtotime($m['date_envoi'])); ?></div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div class="idee-thread-vide"><?php echo htmlspecialchars(t('idee.no_response_yet')); ?></div>
                        <?php endif; ?>

                        <form class="idee-reply-form" onsubmit="return envoyerReponseService(event, <?php echo (int)$idee['id']; ?>, this);">
                            <textarea placeholder="<?php echo htmlspecialchars(t('idee.reply_placeholder')); ?>" required></textarea>
                            <button type="submit"><i class="fa-solid fa-paper-plane"></i></button>
                        </form>
                        <div class="idee-reply-err"></div>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
    </div>
</div>

<script>
const I18N_IDEE = <?php echo json_encode([
    'msg_missing_fields' => t('idee.msg_missing_fields'),
    'btn_sending' => t('idee.btn_sending'),
    'msg_success' => t('idee.msg_success'),
    'msg_error' => t('idee.msg_error'),
    'msg_network_error' => t('idee.msg_network_error'),
    'btn_send' => t('idee.btn_send'),
    'reply_error' => t('idee.reply_error'),
    'reply_network_error' => t('idee.reply_network_error'),
]); ?>;

document.getElementById('form-idee').addEventListener('submit', async function(e) {
    e.preventDefault();

    const nom = document.getElementById('i-nom').value.trim();
    const categorie = document.getElementById('i-categorie').value;
    const titre = document.getElementById('i-titre').value.trim();
    const description = document.getElementById('i-description').value.trim();
    const msg = document.getElementById('form-idee-msg');
    const btn = document.getElementById('btn-send-idee');

    if (!nom || !titre || !description) {
        msg.className = 'form-msg error';
        msg.innerText = I18N_IDEE.msg_missing_fields;
        return;
    }

    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> ' + I18N_IDEE.btn_sending;
    msg.innerText = '';

    const formData = new FormData();
    formData.append('action', 'submit_idee');
    formData.append('nom', nom);
    formData.append('categorie', categorie);
    formData.append('titre', titre);
    formData.append('description', description);

    try {
        const res = await fetch('idee.php', { method: 'POST', body: formData });
        const data = await res.json();

        if (data.success) {
            msg.className = 'form-msg success';
            msg.innerText = I18N_IDEE.msg_success;
            setTimeout(() => location.reload(), 900);
        } else {
            msg.className = 'form-msg error';
            msg.innerText = I18N_IDEE.msg_error;
            btn.disabled = false;
            btn.innerHTML = '<i class="fa-solid fa-paper-plane"></i> ' + I18N_IDEE.btn_send;
        }
    } catch (err) {
        msg.className = 'form-msg error';
        msg.innerText = I18N_IDEE.msg_network_error;
        btn.disabled = false;
        btn.innerHTML = '<i class="fa-solid fa-paper-plane"></i> ' + I18N_IDEE.btn_send;
    }
});

function toggleIdeeItem(topEl) {
    topEl.closest('.idee-item').classList.toggle('is-open');
}

async function envoyerReponseService(e, ideeId, formEl) {
    e.preventDefault();
    const textarea = formEl.querySelector('textarea');
    const message = textarea.value.trim();
    const err = formEl.nextElementSibling;
    const btn = formEl.querySelector('button');
    err.innerText = '';

    if (!message) { return false; }

    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i>';

    const fd = new FormData();
    fd.append('action', 'repondre_service');
    fd.append('idee_id', ideeId);
    fd.append('message', message);

    try {
        const res = await fetch('idee.php', { method: 'POST', body: fd });
        const data = await res.json();
        if (data.success) {
            location.reload();
        } else {
            err.innerText = I18N_IDEE.reply_error;
            btn.disabled = false;
            btn.innerHTML = '<i class="fa-solid fa-paper-plane"></i>';
        }
    } catch (e2) {
        err.innerText = I18N_IDEE.reply_network_error;
        btn.disabled = false;
        btn.innerHTML = '<i class="fa-solid fa-paper-plane"></i>';
    }
    return false;
}
</script>

</body>
</html>
