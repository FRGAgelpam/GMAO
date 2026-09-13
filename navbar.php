<?php
$page_actuelle = basename($_SERVER['PHP_SELF']);

// --- DÉTECTION DYNAMIQUE DU RÔLE ---
// Si l'utilisateur a le rôle "admin" dans la base de données, il voit tout.
$is_admin = (isset($_SESSION['role']) && $_SESSION['role'] === 'admin');

// --- IDENTITÉ DE L'APP (configurable depuis Paramètres > Général) ---
// Repli sur les valeurs par défaut si $db n'est pas dispo ou si la table n'existe pas encore.
$nom_entreprise_nav = "GMAO";
$logo_path_nav = "img/logo.png";
if (isset($db)) {
    try {
        $general_nav = $db->query("SELECT cle, valeur FROM parametres_general")->fetchAll(PDO::FETCH_KEY_PAIR);
        if (!empty($general_nav['nom_entreprise'])) { $nom_entreprise_nav = $general_nav['nom_entreprise']; }
        if (!empty($general_nav['logo_path'])) { $logo_path_nav = $general_nav['logo_path']; }
    } catch (Exception $e) {}
}
?>

<div id="mySidebar" class="sidebar"></div>

<?php if (empty($hide_header)): ?>
<header>
    <div class="header-top">
        <div style="display:flex; align-items:center; gap:12px;">
            <?php if (!empty($header_logout)): ?>
            <a href="logout.php" class="btn-accueil btn-logout-header" title="<?php echo htmlspecialchars(t('nav.logout')); ?>" aria-label="<?php echo htmlspecialchars(t('nav.logout')); ?>"><i class="fa-solid fa-right-from-bracket"></i></a>
            <?php endif; ?>
            <?php if (empty($hide_accueil_btn)): ?>
            <a href="index.php" class="btn-accueil btn-accueil-green" title="<?php echo htmlspecialchars(t('nav.home')); ?>" aria-label="<?php echo htmlspecialchars(t('nav.home')); ?>"><i class="fa-solid fa-house"></i></a>
            <?php endif; ?>
        </div>
        <div style="display:flex; align-items:center; gap:12px;">
            <?php include 'lang_switcher.php'; ?>
            <div class="user-badge">
                <div class="status-pulse"></div>
                <i class="fa-solid fa-user-gear"></i> <span><?php echo htmlspecialchars($_SESSION['user']); ?></span>
            </div>
        </div>
    </div>
    <?php if (!empty($breadcrumb_label)): ?>
    <div class="crumb-bar">
        <a href="index.php" class="crumb-home"><i class="fa-solid fa-house"></i> <?php echo htmlspecialchars(t('nav.home')); ?></a>
        <?php if (!empty($breadcrumb_parent_label)): ?>
        <span class="crumb-sep">/</span>
        <a href="<?php echo htmlspecialchars($breadcrumb_parent_href); ?>" class="crumb-parent"><?php echo htmlspecialchars($breadcrumb_parent_label); ?></a>
        <?php endif; ?>
        <span class="crumb-sep">/</span>
        <span class="crumb-current"><?php echo htmlspecialchars($breadcrumb_label); ?></span>
    </div>
    <?php endif; ?>
</header>
<style>
    /* En paysage téléphone (écran bas, voir max-height ci-dessous), l'en-tête occupe une part trop
       importante des ~390-430px disponibles. On le masque au défilement vers le bas (on gagne la place)
       et on le réaffiche dès qu'on remonte — comportement standard des barres de navigation mobiles.
       Sans effet sur les pages "tableau de bord" (index.php, preventif.php, planning.php) qui ne
       défilent pas la fenêtre elle-même (leur grille défile en interne) : le scroll y reste à 0, la
       classe n'est donc jamais ajoutée, ce qui est le comportement voulu (rien à gagner à les masquer). */
    header { transition: transform 0.25s ease; }
    @media screen and (max-height: 480px) {
        header.header-auto-hidden { transform: translateY(-100%); }
    }
</style>
<script>
(function () {
    var header = document.querySelector('header');
    if (!header) return;
    // Chaque page fixe elle-même son body{padding-top} (variable d'une page à l'autre) pour réserver la
    // place de l'en-tête fixe. On lit cette valeur une fois au chargement pour pouvoir la restaurer :
    // masquer l'en-tête sans réduire aussi ce padding laisserait un vide vide à sa place, sans gain réel
    // d'espace utile.
    var padOriginal = getComputedStyle(document.body).paddingTop;
    var enPaysageBas = function () { return window.matchMedia('(max-height: 480px)').matches; };
    var dernierY = window.scrollY;
    window.addEventListener('scroll', function () {
        var y = window.scrollY;
        if (!enPaysageBas()) {
            header.classList.remove('header-auto-hidden');
            document.body.style.paddingTop = '';
            dernierY = y;
            return;
        }
        if (y > dernierY && y > 60) {
            header.classList.add('header-auto-hidden');
            document.body.style.paddingTop = '0px';
        } else {
            header.classList.remove('header-auto-hidden');
            document.body.style.paddingTop = padOriginal;
        }
        dernierY = y;
    }, { passive: true });
})();
</script>
<?php endif; ?>
