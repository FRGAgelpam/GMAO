<?php
// Petit sélecteur de langue (drapeaux) à inclure sur login.php et dans navbar.php.
// Conserve la query string courante et ajoute/écrase juste ?lang=xx.
$__gmao_lang_cur = function_exists('langue_actuelle') ? langue_actuelle() : 'fr';
$__gmao_lang_labels = ['fr' => 'FR', 'en' => 'EN', 'nl' => 'NL'];
$__gmao_lang_qs = $_GET;
?>
<div class="lang-switcher">
    <?php foreach ($__gmao_lang_labels as $__gmao_lc => $__gmao_ll):
        $__gmao_lang_qs['lang'] = $__gmao_lc;
        $__gmao_lang_href = htmlspecialchars('?' . http_build_query($__gmao_lang_qs), ENT_QUOTES);
    ?>
    <a href="<?php echo $__gmao_lang_href; ?>" class="lang-switcher-item<?php echo $__gmao_lc === $__gmao_lang_cur ? ' active' : ''; ?>"><?php echo $__gmao_ll; ?></a>
    <?php endforeach; ?>
</div>
<style>
    .lang-switcher { display: flex; gap: 4px; }
    .lang-switcher-item {
        font-size: 0.68rem; font-weight: 700; letter-spacing: 0.02em;
        padding: 3px 7px; border-radius: 6px; text-decoration: none;
        color: rgba(255,255,255,0.6); border: 1px solid rgba(255,255,255,0.25);
        transition: 0.2s;
    }
    .lang-switcher-item:hover { color: #fff; border-color: rgba(255,255,255,0.5); }
    .lang-switcher-item.active { color: #fff; background: rgba(255,255,255,0.22); border-color: rgba(255,255,255,0.5); }
    /* Variante sombre (fond clair, ex. carte de connexion) */
    .lang-switcher.lang-switcher-dark .lang-switcher-item { color: #7f8c8d; border-color: #ddd; }
    .lang-switcher.lang-switcher-dark .lang-switcher-item:hover { color: #2c3e50; border-color: #b0b8bd; }
    .lang-switcher.lang-switcher-dark .lang-switcher-item.active { color: #fff; background: #3498db; border-color: #3498db; }
</style>
