<?php $page_actuelle = basename($_SERVER['PHP_SELF']); ?>

<div id="mySidebar" class="sidebar"></div>

<header>
    <div class="header-top">
        <a href="index.php" class="btn-accueil btn-accueil-green" title="<?php echo htmlspecialchars(t('nav.home')); ?>" aria-label="<?php echo htmlspecialchars(t('nav.home')); ?>"><i class="fa-solid fa-house"></i></a>

        <div class="week-nav-center" id="weekNavCenter">
            <div class="nav-controls">
                <button onclick="changeWeek(-1)" class="nav-btn"><i class="fa-solid fa-chevron-left"></i></button>
                <div class="week-label" id="weekLabel"><?php echo htmlspecialchars(t('planning.semaine')); ?> --</div>
                <button onclick="changeWeek(1)" class="nav-btn"><i class="fa-solid fa-chevron-right"></i></button>
            </div>
            <div class="date-range-sub" id="currentDateRange"><?php echo htmlspecialchars(t('planning.du')); ?> ... <?php echo htmlspecialchars(t('planning.au_connector')); ?> ... 2026</div>
        </div>

        <div class="week-nav-center" id="moisNavCenter" style="display:none;">
            <div class="nav-controls">
                <button type="button" onclick="changerMois(-1)" class="nav-btn"><i class="fa-solid fa-chevron-left"></i></button>
                <div class="week-label" id="moisNavLabel">--</div>
                <button type="button" onclick="changerMois(1)" class="nav-btn"><i class="fa-solid fa-chevron-right"></i></button>
            </div>
            <div class="date-range-sub" id="moisDateRange">...</div>
        </div>

        <div style="display:flex; align-items:center; gap:12px;">
            <div class="mode-vue-toggle" id="modeVueToggle">
                <button type="button" class="mode-vue-btn" data-mode="jour" onclick="definirModeVue('jour')"><?php echo htmlspecialchars(t('planning.jour')); ?></button>
                <button type="button" class="mode-vue-btn active" data-mode="semaine" onclick="definirModeVue('semaine')"><?php echo htmlspecialchars(t('planning.semaine')); ?></button>
                <button type="button" class="mode-vue-btn" data-mode="mois" onclick="definirModeVue('mois')"><?php echo htmlspecialchars(t('planning.mois')); ?></button>
            </div>
            <?php include 'lang_switcher.php'; ?>
            <div class="user-badge">
                <div class="status-pulse"></div>
                <i class="fa-solid fa-user-gear"></i> <span><?php echo htmlspecialchars($_SESSION['user']); ?></span>
            </div>
        </div>
    </div>
    <div class="crumb-bar">
        <a href="index.php" class="crumb-home"><i class="fa-solid fa-house"></i> <?php echo htmlspecialchars(t('nav.home')); ?></a>
        <span class="crumb-sep">/</span>
        <span class="crumb-current"><?php echo htmlspecialchars(t('planning.planning_word')); ?></span>
    </div>
</header>
