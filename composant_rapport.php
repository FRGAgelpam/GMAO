<?php
// Dictionnaire autonome des sous-traitants pour le rapport
$liste_ee_json = "[]";
// Habillage des statuts (configurable depuis Paramètres > Statuts & priorités) — nom de variable
// dédié (LIBELLES_RAPPORT, pas LIBELLES) car ce composant est inclus dans des pages qui déclarent
// déjà leur propre `const LIBELLES` : le redéclarer ferait planter tout le JS de la page hôte.
$libelles_rapport_php = [
    'afaire'  => ['couleur' => '#f39c12', 'label' => t('maint.lib_afaire')],
    'encours' => ['couleur' => '#3498db', 'label' => t('maint.lib_encours')],
    'termine' => ['couleur' => '#27ae60', 'label' => t('maint.lib_termine')],
    'refuse'  => ['couleur' => '#e74c3c', 'label' => t('maint.lib_refuse')],
    'urgent'  => ['couleur' => '#e74c3c', 'label' => t('maint.lib_urgent')],
];
try {
    require_once 'db.php';
    if(isset($db)) {
        $reqEE = $db->query("SELECT id, nom FROM entreprises_ext");
        if($reqEE) { $liste_ee_json = json_encode($reqEE->fetchAll(PDO::FETCH_ASSOC)); }
        foreach ($db->query("SELECT bucket, couleur FROM libelles_workflow")->fetchAll(PDO::FETCH_ASSOC) as $l) {
            if (isset($libelles_rapport_php[$l['bucket']])) { $libelles_rapport_php[$l['bucket']]['couleur'] = $l['couleur']; }
        }
    }
} catch (Throwable $e) {}
?>

<script>
    // 1. On interroge la session PHP globale ouverte sur le serveur
    // 2. Si le rôle est 'admin', PHP écrit la valeur "true" en texte brut
    // 3. Le navigateur lit ce script et crée une vraie variable de sécurité JavaScript
    const rapportIsAdmin = <?php echo (isset($_SESSION['role']) && $_SESSION['role'] === 'admin') ? 'true' : 'false'; ?>;
    // Technicien + Admin peuvent ajouter/supprimer des photos depuis cette fiche détail — un compte
    // "portail demandeur" ne peut en ajouter qu'à la création de sa propre demande (voir bi_photos.php).
    const rapportIsStaff = <?php echo (isset($_SESSION['role']) && in_array($_SESSION['role'], ['admin', 'technicien'], true)) ? 'true' : 'false'; ?>;
    
    // On en profite pour récupérer proprement le nom d'utilisateur connecté pour l'historique des actions
    const rapportCurrentUser = "<?php echo isset($_SESSION['user']) ? $_SESSION['user'] : 'Utilisateur Inconnu'; ?>";
</script>

<div id="modalDetailBI" class="modal" style="display:none; position:fixed; z-index:9999; left:0; top:0; width:100%; height:100%; background-color:rgba(15, 23, 42, 0.4); backdrop-filter: blur(3px); overflow-y:auto;">
    
    <div class="modal-content" style="max-width: 850px !important; width: 95% !important; max-height: 94vh; border-top: 3px solid var(--gelpam-orange); padding: 0; background: #fff; margin: 3vh auto; border-radius: 12px; position: relative; box-shadow: 0 20px 25px -5px rgba(46, 204, 113, 0.25), 0 10px 10px -5px rgba(46, 204, 113, 0.15); display: flex; flex-direction: column; overflow: hidden;" onclick="event.stopPropagation()">

        <div id="detailBIContent" style="overflow-y: auto; flex: 1; min-height: 0;"></div>

        <div id="detailBIFooter" class="no-print" style="display: flex; justify-content: space-between; align-items: center; gap: 8px; flex-wrap: wrap; padding: 8px 16px; border-top: 1px solid #f1f5f9; background: #fff; flex-shrink: 0;"></div>

    </div>
</div>

<!-- Modales de confirmation/alerte propres à ce composant : ne dépendent pas de aspirineConfirm/aspirineAlert,
     qui n'existent que sur les pages qui les définissent elles-mêmes (maintenance.php...). Ce composant est
     inclus dans d'autres pages (ex. admin_machines.php via la fiche de vie) qui ne les ont pas — sans ça, les
     boutons Réouvrir/Supprimer/Basculer préventif ne faisaient rien (erreur JS silencieuse). -->
<div id="rapportCustomConfirm" style="display:none; position:fixed; z-index:100000; left:0; top:0; width:100%; height:100%; background:rgba(0,0,0,0.5); backdrop-filter: blur(3px);">
    <div style="background:white; width:350px; margin:15% auto; padding:20px; border-radius:12px; text-align:center; box-shadow: 0 10px 25px rgba(0,0,0,0.2); border-top: 5px solid var(--gelpam-orange, #f39c12);">
        <i class="fa-solid fa-circle-question" style="font-size:3rem; color:var(--gelpam-orange, #f39c12); margin-bottom:15px;"></i>
        <h3 id="rapportConfirmTitle" style="margin:10px 0; color:var(--dark-blue, #2c3e50);"><?php echo htmlspecialchars(t('maint.confirm_default_title')); ?></h3>
        <p id="rapportConfirmMessage" style="color:#666; font-size:0.9rem; margin-bottom:20px;"><?php echo htmlspecialchars(t('maint.confirm_default_msg')); ?></p>
        <div style="display:flex; justify-content:center; gap:10px;">
            <button id="rapportConfirmCancel" style="padding:10px 20px; border:none; border-radius:6px; background:#eee; cursor:pointer; font-weight:bold; font-family:inherit;"><?php echo htmlspecialchars(t('maint.cancel')); ?></button>
            <button id="rapportConfirmOk" style="padding:10px 20px; border:none; border-radius:6px; background:var(--gelpam-orange, #f39c12); color:white; cursor:pointer; font-weight:bold; font-family:inherit;"><?php echo htmlspecialchars(t('maint.confirm_btn')); ?></button>
        </div>
    </div>
</div>

<div id="rapportCustomAlert" style="display:none; position:fixed; z-index:100000; left:0; top:0; width:100%; height:100%; background:rgba(0,0,0,0.5); backdrop-filter: blur(3px);">
    <div style="background:white; width:350px; margin:15% auto; padding:20px; border-radius:12px; text-align:center; box-shadow: 0 10px 25px rgba(0,0,0,0.2); border-top: 5px solid #27ae60;">
        <i class="fa-solid fa-circle-check" style="font-size:3rem; color:#27ae60; margin-bottom:15px;"></i>
        <h3 id="rapportAlertTitle" style="margin:10px 0; color:var(--dark-blue, #2c3e50);"><?php echo htmlspecialchars(t('maint.alert_default_title')); ?></h3>
        <p id="rapportAlertMessage" style="color:#666; font-size:0.9rem; margin-bottom:20px;"><?php echo htmlspecialchars(t('maint.alert_default_msg')); ?></p>
        <div style="display:flex; justify-content:center;">
            <button id="rapportAlertOk" style="padding:10px 30px; border:none; border-radius:6px; background:#27ae60; color:white; cursor:pointer; font-weight:bold; font-family:inherit;"><?php echo htmlspecialchars(t('maint.ok')); ?></button>
        </div>
    </div>
</div>

<script>
function rapportConfirm(titre, message) {
    return new Promise((resolve) => {
        const modal = document.getElementById('rapportCustomConfirm');
        document.getElementById('rapportConfirmTitle').innerText = titre;
        document.getElementById('rapportConfirmMessage').innerText = message;
        modal.style.display = 'block';
        document.getElementById('rapportConfirmOk').onclick = () => { modal.style.display = 'none'; resolve(true); };
        document.getElementById('rapportConfirmCancel').onclick = () => { modal.style.display = 'none'; resolve(false); };
    });
}
function rapportAlert(titre, message) {
    return new Promise((resolve) => {
        const modal = document.getElementById('rapportCustomAlert');
        document.getElementById('rapportAlertTitle').innerText = titre;
        document.getElementById('rapportAlertMessage').innerText = message;
        modal.style.display = 'block';
        document.getElementById('rapportAlertOk').onclick = () => { modal.style.display = 'none'; resolve(); };
    });
}
</script>

<script>
const dictionnaireEE = <?php echo $liste_ee_json; ?>;
const LIBELLES_RAPPORT = <?php echo json_encode($libelles_rapport_php); ?>;
const I18N_RAPPORT = <?php echo json_encode([
    'intervention_introuvable' => t('rapport.intervention_introuvable'),
    'aucun_temps' => t('rapport.aucun_temps'),
    'entreprise_ext_fallback' => t('rapport.entreprise_ext_fallback'),
    'sous_traitant_label' => t('rapport.sous_traitant_label'),
    'type_preventif' => t('maint.type_preventif'),
    'type_chantier' => t('maint.type_chantier'),
    'type_curatif' => t('maint.type_curatif'),
    'casse_accidentelle' => t('rapport.casse_accidentelle'),
    'visserie_controlee' => t('rapport.visserie_controlee'),
    'origine_demande' => t('rapport.origine_demande'),
    'signale_le' => t('rapport.signale_le'),
    'date_at' => t('idee.date_at'),
    'prevu_le' => t('rapport.prevu_le'),
    'emetteur' => t('rapport.emetteur'),
    'non_renseigne' => t('rapport.non_renseigne'),
    'priorite_label' => t('rapport.priorite_label'),
    'lib_urgent' => t('maint.lib_urgent'),
    'lib_normal' => t('maint.lib_normal'),
    'localisation_precise' => t('rapport.localisation_precise'),
    'usine_label' => t('rapport.usine_label'),
    'secteur_label' => t('rapport.secteur_label'),
    'ligne_label' => t('rapport.ligne_label'),
    'zone_label' => t('rapport.zone_label'),
    'machine_label' => t('rapport.machine_label'),
    'panne_demande' => t('rapport.panne_demande'),
    'historique_avancement' => t('rapport.historique_avancement'),
    'carnet_de_bord' => t('rapport.carnet_de_bord'),
    'carnet_placeholder' => t('rapport.carnet_placeholder'),
    'enregistrer_suivi' => t('rapport.enregistrer_suivi'),
    'rapport_final' => t('rapport.rapport_final'),
    'en_attente_cloture' => t('rapport.en_attente_cloture'),
    'detail_temps' => t('rapport.detail_temps'),
    'saisies_count' => t('rapport.saisies_count'),
    'th_date' => t('maint.col_date'),
    'th_intervenant' => t('rapport.th_intervenant'),
    'th_temps' => t('rapport.th_temps'),
    'total_cumule' => t('rapport.total_cumule'),
    'photos_title' => t('rapport.photos_title'),
    'loading' => t('suivi.chargement'),
    'title' => t('rapport.title'),
    'other_party_fallback' => t('chat.other_party_fallback'),
    'btn_messagerie' => t('rapport.btn_messagerie'),
    'btn_export_pdf' => t('rapport.btn_export_pdf'),
    'btn_fermer' => t('rapport.btn_fermer'),
    'btn_generer_pdp' => t('rapport.btn_generer_pdp'),
    'btn_reouvrir' => t('rapport.btn_reouvrir'),
    'btn_supprimer' => t('rapport.btn_supprimer'),
    'btn_bascule_hivernal' => t('rapport.btn_bascule_hivernal'),
    'reserve_admins' => t('rapport.reserve_admins'),
    'photo_add' => t('rapport.photo_add'),
    'aucune_photo' => t('rapport.aucune_photo'),
    'photo_count_one' => t('rapport.photo_count_one'),
    'photo_count_many' => t('rapport.photo_count_many'),
    'supprimer_photo_tooltip' => t('rapport.supprimer_photo_tooltip'),
    'photo_non_ajoutee' => t('rapport.photo_non_ajoutee'),
    'erreur_inconnue' => t('rapport.erreur_inconnue'),
    'confirm_del_photo_title' => t('rapport.confirm_del_photo_title'),
    'confirm_del_photo_msg' => t('rapport.confirm_del_photo_msg'),
    'confirm_reopen_title' => t('rapport.confirm_reopen_title'),
    'confirm_reopen_msg' => t('rapport.confirm_reopen_msg'),
    'ticket_introuvable' => t('rapport.ticket_introuvable'),
    'success_title' => t('maint.success_title'),
    'reopen_success' => t('rapport.reopen_success'),
    'err_title' => t('maint.err_title'),
    'err_reopen_server' => t('rapport.err_reopen_server'),
    'err_network_title' => t('maint.err_network_title'),
    'confirm_bascule_hivernal_title' => t('rapport.confirm_bascule_hivernal_title'),
    'confirm_bascule_avec_heures' => t('rapport.confirm_bascule_avec_heures'),
    'confirm_bascule_sans_heures' => t('rapport.confirm_bascule_sans_heures'),
    'transfer_err_add' => t('maint.transfer_err_add'),
    'compte_rendu_bascule' => t('rapport.compte_rendu_bascule'),
    'transfer_success_title' => t('maint.transfer_success_title'),
    'bascule_success_avec_heures' => t('rapport.bascule_success_avec_heures'),
    'bascule_success_sans_heures' => t('rapport.bascule_success_sans_heures'),
    'confirm_del_title' => t('rapport.confirm_del_title'),
    'confirm_del_msg_simple' => t('rapport.confirm_del_msg_simple'),
    'confirm_del_msg_host' => t('rapport.confirm_del_msg_host'),
    'del_absolute_irreversible' => t('rapport.del_absolute_irreversible'),
    'saving' => t('maint.saving'),
    'carnet_success' => t('rapport.carnet_success'),
    'err_server_refused' => t('maint.err_server_refused'),
    'err_network_unreachable' => t('maint.err_network_unreachable'),
]); ?>;
// LA FONCTION UNIQUE REUTILISABLE PARTOUT
async function showDetailBI(id) {
    try {
        // Sécurité : Si les variables globales n'existent pas sur la page hôte, on va chercher les données en direct
        if (typeof tasks === 'undefined' || tasks.length === 0) {
            const resTasks = await fetch('api.php?t=' + Date.now());
            window.tasks = await resTasks.json();
        }
        if (typeof pointages === 'undefined' || pointages.length === 0) {
            const resPt = await fetch('maintenance.php?get_pointages=1&t=' + Date.now());
            window.pointages = await resPt.json();
        }

        const t = tasks.find(x => x.id === id);
        if (!t) return await rapportAlert(I18N_RAPPORT.err_title, I18N_RAPPORT.intervention_introuvable);

        const content = document.getElementById('detailBIContent');
        const ptgs = pointages.filter(p => p.task_id === id);
        let totalHeures = 0;
        let lignesPointages = "";

        if (ptgs.length === 0) {
            lignesPointages = `<tr><td colspan="3" style="padding: 8px; text-align: center; color: #94a3b8; font-style: italic; font-size: 0.8rem;">${I18N_RAPPORT.aucun_temps}</td></tr>`;
        } else {
            ptgs.forEach(p => {
                const h = parseFloat(p.hours);
                if (!isNaN(h)) {
                    totalHeures += h;
                    let dateP = p.date.split(' ')[0].split('-').reverse().join('/');
                    lignesPointages += `
                        <tr style="border-bottom: 1px solid #f1f5f9;">
                            <td style="padding: 8px; color: #64748b; font-size: 0.8rem; text-align: left;">${dateP}</td>
                            <td style="padding: 8px; color: #334155; font-size: 0.8rem; text-align: left;">${p.tech}</td>
                            <td style="padding: 8px; text-align: right; color: var(--dark-blue); font-weight:600; font-size: 0.8rem;">${h.toFixed(2)} h</td>
                        </tr>`;
                }
            });
        }

        // --- GESTION DE L'AFFICHAGE DU SOUS-TRAITANT ---
        let ligneST = "";
        if (t.is_sous_traitant == 1 || t.is_sous_traitant === "1" || t.is_sous_traitant === true) {
            let stName = I18N_RAPPORT.entreprise_ext_fallback;
            
            // On cherche le nom dans notre dictionnaire autonome
            if (t.entreprise_ext_id && typeof dictionnaireEE !== 'undefined') {
                const foundEE = dictionnaireEE.find(e => e.id == t.entreprise_ext_id);
                if (foundEE) stName = foundEE.nom;
            }
            
            ligneST = `<span style="color: #64748b;">${I18N_RAPPORT.sous_traitant_label}</span> <span style="color: var(--gelpam-orange); font-weight: bold; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">${stName}</span>`;
        }

        let s = (t.statut || "À faire");
        
        let slug = s.toLowerCase().normalize("NFD").replace(/[\u0300-\u036f]/g, "");
        let colorStatus = LIBELLES_RAPPORT.afaire.couleur;
        let texteStatus = LIBELLES_RAPPORT.afaire.label;
        if (slug.includes("cours")) { colorStatus = LIBELLES_RAPPORT.encours.couleur; texteStatus = LIBELLES_RAPPORT.encours.label; }
        else if (slug.includes("refus")) { colorStatus = LIBELLES_RAPPORT.refuse.couleur; texteStatus = LIBELLES_RAPPORT.refuse.label; }
        else if (slug.includes("termin")) { colorStatus = LIBELLES_RAPPORT.termine.couleur; texteStatus = LIBELLES_RAPPORT.termine.label; }

        let badgeType = t.type === 'Préventif' ? `<span style="background:#3498db; color:white; padding:3px 8px; border-radius:4px; font-size:0.65rem; font-weight:500; text-transform:uppercase;">${I18N_RAPPORT.type_preventif}</span>` :
                        (t.type === 'Chantier' ? `<span style="background:#9b59b6; color:white; padding:3px 8px; border-radius:4px; font-size:0.65rem; font-weight:500; text-transform:uppercase;">${I18N_RAPPORT.type_chantier}</span>` :
                        `<span style="background:#e74c3c; color:white; padding:3px 8px; border-radius:4px; font-size:0.65rem; font-weight:500; text-transform:uppercase;">${I18N_RAPPORT.type_curatif}</span>`);
        
        let badgeCasse = (t.casse == 1 || t.casse === 'true' || t.casse === true) ? `<span style="background:var(--danger); color:white; padding:3px 8px; border-radius:4px; font-size:0.65rem; font-weight:500; text-transform:uppercase; margin-left:5px;"><i class="fa-solid fa-bolt"></i> ${I18N_RAPPORT.casse_accidentelle}</span>` : '';
        
        let badgeVisserie = (t.verif_vis == 1 || t.verif_vis === 'true' || t.verif_vis === true || t.verif_vis === "1") ? `<span style="background:#8e44ad; color:white; padding:3px 8px; border-radius:4px; font-size:0.65rem; font-weight:500; text-transform:uppercase; margin-left:5px;"><i class="fa-solid fa-screwdriver"></i> ${I18N_RAPPORT.visserie_controlee}</span>` : '';

        // --- GESTION DES DATES ET HEURES ---
        let dateReelleStr = t.date_creation || t.date; 
        let dateOrigine = '--/--/----'; let heureOrigine = '--:--';
        if (dateReelleStr && dateReelleStr.includes('-')) {
            let parties = dateReelleStr.split(' ');
            dateOrigine = parties[0].split('-').reverse().join('/');
            if (parties[1]) {
                heureOrigine = parties[1].substring(0, 5);
                // Si l'heure est celle par défaut (08:00), on tente de récupérer la vraie heure de création
                if (heureOrigine === "08:00" && t.date_creation && t.date_creation.includes(' ')) {
                    heureOrigine = t.date_creation.split(' ')[1].substring(0, 5);
                }
            }
        }
        let datePlanif = t.date.split(' ')[0].split('-').reverse().join('/');

        // --- PRÉPARATION DU BLOC NOTE INTERMÉDIAIRE ---
        let rapportIntermediaireHtml = '';
        let contenuIntermediaire = t.rapport_intermediaire || '';

        if (slug.includes("termin")) {
            // Si le bon est terminé, on affiche la note en lecture seule (si elle existe)
            if (contenuIntermediaire.trim() !== '') {
                rapportIntermediaireHtml = `
                    <div style="background: white; border-radius: 5px; padding: 8px; border: 1px solid #f1f5f9; border-left: 4px solid #3498db;">
                        <label style="display: block; font-size: 0.58rem; font-weight: 600; color: #3498db; text-transform: uppercase; margin-bottom: 3px;"><i class="fa-solid fa-clipboard"></i> ${I18N_RAPPORT.historique_avancement}</label>
                        <div style="font-size: 0.75rem; color: #334155; white-space: pre-wrap; line-height: 1.25;">${contenuIntermediaire}</div>
                    </div>
                `;
            }
        } else {
            // Si le bon est "En cours" ou "À faire", on affiche la zone de saisie
            rapportIntermediaireHtml = `
                <div class="no-print" style="background: #f8fafc; border-radius: 5px; padding: 8px; border: 1px solid #e2e8f0; border-left: 4px solid #3498db;">
                    <label style="display: block; font-size: 0.58rem; font-weight: 600; color: #3498db; text-transform: uppercase; margin-bottom: 4px;"><i class="fa-solid fa-pen-to-square"></i> ${I18N_RAPPORT.carnet_de_bord}</label>
                    <textarea id="note-intermediaire-${t.id}" rows="2" placeholder="${I18N_RAPPORT.carnet_placeholder}" style="width: 100%; border: 1px solid #cbd5e1; border-radius: 5px; padding: 6px; font-size: 0.78rem; font-family: inherit; color: #334155; resize: vertical; box-sizing: border-box; margin-bottom: 6px; outline:none;" onfocus="this.style.border='1px solid #3498db'" onblur="this.style.border='1px solid #cbd5e1'">${contenuIntermediaire}</textarea>
                    <div style="text-align: right;">
                        <button id="btn-save-note-${t.id}" onclick="sauvegarderNoteIntermediaire('${t.id}')" style="background: #3498db; color: white; border: none; padding: 5px 10px; border-radius: 4px; font-size: 0.68rem; font-weight: bold; cursor: pointer; transition: 0.2s;"><i class="fa-solid fa-floppy-disk"></i> ${I18N_RAPPORT.enregistrer_suivi}</button>
                    </div>
                </div>
            `;
        }

        // --- GESTION DU BOUTON CHAT ---
        // Le chat n'a de sens que pour une demande réellement venue du portail Services : un BI
        // que le technicien a créé lui-même n'a personne en face à qui écrire. On détecte l'origine
        // portail via le marqueur "DEMANDE DE : ..." que demande.php injecte dans la description
        // (même convention déjà utilisée côté suivi.php / composant_messagerie.php pour retrouver
        // le vrai demandeur), plutôt que via l'id ou le champ demandeur qui ne sont pas fiables.
        let btnChatHtml = "";
        if (/DEMANDE DE\s*:/i.test(t.desc || t.description || '')) {
            const nbNonLus = (typeof notifTicketsNonLus !== 'undefined' && notifTicketsNonLus[String(t.id)]) || 0;
            const badgeNonLusHtml = nbNonLus > 0
                ? `<span style="background:var(--danger); color:white; border-radius:10px; min-width:16px; height:16px; padding:0 4px; font-size:0.62rem; font-weight:700; display:inline-flex; align-items:center; justify-content:center;">${nbNonLus}</span>`
                : '';
            btnChatHtml = `
                <button onclick="ouvrirChatTicket('${t.id}', '${t.demandeur ? t.demandeur.replace(/'/g, "\\'") : I18N_RAPPORT.other_party_fallback}')" style="background: #3498db; border: none; color: white; padding: 5px 10px; border-radius: 5px; font-size: 0.68rem; font-weight: bold; cursor: pointer; display: flex; align-items: center; gap: 4px; box-shadow: 0 2px 4px rgba(52,152,219,0.3); transition: 0.2s;" onmouseover="this.style.background='#2980b9';" onmouseout="this.style.background='#3498db';">
                    <i class="fa-solid fa-comments"></i> ${I18N_RAPPORT.btn_messagerie} ${badgeNonLusHtml}
                </button>
            `;
        }

        // --- GÉNÉRATION DE LA MODALE ---
        content.innerHTML = `
            <div id="zoneAImprimer" style="font-family: 'Segoe UI', sans-serif; color: #334155; padding: 14px 18px; background: white; width: 100%; box-sizing: border-box; max-width: 800px; margin: 0 auto;">

                <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 8px; padding-bottom: 6px; border-bottom: 2px solid #f1f5f9;">
                    <div style="display: flex; align-items: center; gap: 10px;">
                        <div style="background: #fff5e6; color: #f39c12; width: 34px; height: 34px; border-radius: 8px; display: flex; align-items: center; justify-content: center; font-size: 1.15rem;">
                            <i class="fa-solid fa-clipboard-check"></i>
                        </div>
                        <div>
                            <h2 style="margin: 0; font-family: 'Caveat', cursive; font-size: 1.7rem; color: #2c3e50; line-height: 1;">${I18N_RAPPORT.title}</h2>
                            <div style="font-size: 0.85rem; font-weight: 600; color: #64748b; margin-top: 1px;"><span style="color: #f39c12;">#</span>${t.num_bi || '---'}</div>
                        </div>
                    </div>
                    <div style="text-align: right; display: flex; gap: 5px; flex-wrap: wrap; justify-content: flex-end; align-items: center;">
                        <span style="background:${colorStatus}; color:white; padding:2px 8px; border-radius:20px; font-size:0.65rem; font-weight:bold; text-transform:uppercase;">${texteStatus}</span>
                        ${badgeType} ${badgeCasse} ${badgeVisserie}
                    </div>
                </div>

                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 8px; margin-bottom: 8px;">

                    <div style="background: #f8fafc; padding: 8px; border-radius: 6px; border: 1px solid #e2e8f0;">
                        <h4 style="margin: 0 0 4px 0; font-size: 0.62rem; color: #94a3b8; text-transform: uppercase; border-bottom: 1px solid #e2e8f0; padding-bottom: 3px; font-weight: 600;"><i class="fa-solid fa-info-circle"></i> ${I18N_RAPPORT.origine_demande}</h4>
                        <div style="display: grid; grid-template-columns: 90px 1fr; gap: 3px; font-size: 0.72rem;">
                            <span style="color: #64748b;">${I18N_RAPPORT.signale_le}</span> <span style="color: #2c3e50; font-weight: 600;">${dateOrigine} ${I18N_RAPPORT.date_at} ${heureOrigine}</span>
                            <span style="color: #64748b;">${I18N_RAPPORT.prevu_le}</span> <span style="color: #3498db; font-weight: bold;">${datePlanif}</span>
                            <span style="color: #64748b;">${I18N_RAPPORT.emetteur}</span> <span id="rapport-demandeur" style="color: #2c3e50; font-weight: 600;">${t.demandeur || I18N_RAPPORT.non_renseigne}</span>
                            <span style="color: #64748b;">${I18N_RAPPORT.priorite_label}</span> <span style="color: ${t.prio === 'Urgent' ? LIBELLES_RAPPORT.urgent.couleur : '#2c3e50'}; font-weight: bold;">${t.prio === 'Urgent' ? I18N_RAPPORT.lib_urgent : I18N_RAPPORT.lib_normal}</span>
                            ${ligneST}
                        </div>
                    </div>

                    <div style="background: #f0f7ff; padding: 8px; border-radius: 6px; border: 1px solid #bae6fd;">
                        <h4 style="margin: 0 0 4px 0; font-size: 0.62rem; color: #38bdf8; text-transform: uppercase; border-bottom: 1px solid #bae6fd; padding-bottom: 3px; font-weight: 600;"><i class="fa-solid fa-location-dot"></i> ${I18N_RAPPORT.localisation_precise}</h4>
                        <div style="display: grid; grid-template-columns: 65px 1fr; gap: 3px; font-size: 0.72rem;">
                            <span style="color: #0284c7;">${I18N_RAPPORT.usine_label}</span> <span style="color: #2c3e50;">${t.usine || '---'}</span>
                            <span style="color: #0284c7;">${I18N_RAPPORT.secteur_label}</span> <span style="color: #2c3e50;">${t.secteur || '---'}</span>
                            ${t.ligne ? `<span style="color: #0284c7;">${I18N_RAPPORT.ligne_label}</span> <span style="color: #2c3e50;">${t.ligne}</span>` : ''}
                            <span style="color: #0284c7;">${I18N_RAPPORT.zone_label}</span> <span style="color: #2c3e50;">${t.zone || '---'}</span>
                            <span style="color: #0284c7;">${I18N_RAPPORT.machine_label}</span> <span style="font-weight: bold; color: #2c3e50;">${t.equip || '---'}</span>
                        </div>
                    </div>
                </div>

                <div style="display: flex; flex-direction: column; gap: 8px; margin-bottom: 8px;">
                    <div style="background: white; border-radius: 5px; padding: 8px; border: 1px solid #f1f5f9; border-left: 4px solid #f39c12;">
                        <label style="display: block; font-size: 0.58rem; font-weight: 600; color: #f39c12; text-transform: uppercase; margin-bottom: 3px;"><i class="fa-solid fa-triangle-exclamation"></i> ${I18N_RAPPORT.panne_demande}</label>
                        <div style="font-size: 0.75rem; color: #475569; font-style: italic; line-height: 1.25;">"${t.desc || ''}"</div>
                    </div>

                    ${rapportIntermediaireHtml}

                    <div style="background: white; border-radius: 5px; padding: 8px; border: 1px solid #f1f5f9; border-left: 4px solid #2ecc71;">
                        <label style="display: block; font-size: 0.58rem; font-weight: 600; color: #2ecc71; text-transform: uppercase; margin-bottom: 3px;"><i class="fa-solid fa-check-double"></i> ${I18N_RAPPORT.rapport_final}</label>
                        <div style="font-size: 0.75rem; color: #2e7d32; white-space: pre-wrap; line-height: 1.25;">${t.compte_rendu || t.comm_tech || I18N_RAPPORT.en_attente_cloture}</div>
                    </div>
                </div>

                <div style="background: #fff; border: 1px solid #e2e8f0; border-radius: 6px; overflow: hidden;">
                    <div style="background: #f8fafc; padding: 5px 10px; border-bottom: 1px solid #e2e8f0; display:flex; justify-content:space-between; align-items:center;">
                        <h4 style="margin: 0; font-size: 0.65rem; color: #2c3e50; text-transform: uppercase; font-weight: 600;"><i class="fa-solid fa-clock-rotate-left"></i> ${I18N_RAPPORT.detail_temps}</h4>
                        <span style="font-size: 0.58rem; color: #64748b;">${I18N_RAPPORT.saisies_count.replace('{n}', ptgs.length)}</span>
                    </div>
                    <table style="width: 100%; border-collapse: collapse; font-size: 0.72rem;">
                        <thead style="background: #f1f5f9; font-size: 0.58rem; color: #64748b; text-transform: uppercase;">
                            <tr>
                                <th style="padding: 4px 6px; text-align: left; font-weight: 600;">${I18N_RAPPORT.th_date}</th>
                                <th style="padding: 4px 6px; text-align: left; font-weight: 600;">${I18N_RAPPORT.th_intervenant}</th>
                                <th style="padding: 4px 6px; text-align: right; font-weight: 600;">${I18N_RAPPORT.th_temps}</th>
                            </tr>
                        </thead>
                        <tbody>${lignesPointages}</tbody>
                        <tfoot style="background: #fff5e6; border-top: 1px solid #fed7aa; color: #d35400;">
                            <tr>
                                <td colspan="2" style="padding: 5px 6px; text-align: right; text-transform: uppercase; font-size: 0.65rem; font-weight: 600;">${I18N_RAPPORT.total_cumule}</td>
                                <td style="padding: 5px 6px; text-align: right; font-size: 0.82rem; font-weight: 600;">${totalHeures.toFixed(2)} h</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>

                <div style="background: #fff; border: 1px solid #e2e8f0; border-radius: 6px; overflow: hidden; margin-top: 8px;">
                    <div style="background: #f8fafc; padding: 5px 10px; border-bottom: 1px solid #e2e8f0; display:flex; justify-content:space-between; align-items:center;">
                        <h4 style="margin: 0; font-size: 0.65rem; color: #2c3e50; text-transform: uppercase; font-weight: 600;"><i class="fa-solid fa-camera"></i> ${I18N_RAPPORT.photos_title}</h4>
                        <span id="rapport-photos-count" style="font-size: 0.58rem; color: #64748b;"></span>
                    </div>
                    <div id="rapport-photos-body" style="padding: 10px; display:flex; flex-wrap:wrap; gap:8px;"></div>
                </div>
            </div>`;

        renderRapportPhotos(t.id);

        // Barre de boutons : hors de la zone scrollable/imprimable, épinglée en bas de la fenêtre
        // (voir #detailBIFooter dans le HTML) pour rester accessible sans avoir à scroller tout le rapport.
        document.getElementById('detailBIFooter').innerHTML = `
            <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
                <div id="zoneBoutonSupprimer" style="display:flex; gap:10px;"></div>
                <button onclick="exporterPDF_Centralise('${t.num_bi || t.id}')" style="background: #f1f5f9; border: none; color: #475569; padding: 5px 10px; border-radius: 5px; font-size: 0.68rem; font-weight: 500; cursor: pointer; display: flex; align-items: center; gap: 4px;">
                    <i class="fa-solid fa-file-pdf" style="color: #e74c3c;"></i> ${I18N_RAPPORT.btn_export_pdf}
                </button>
                ${btnChatHtml}
            </div>
            <div style="display: flex; align-items: center; gap: 10px;">
                <div id="zoneBoutonsSecurises" style="display:flex; align-items:center; gap:10px;"></div>
                <button type="button" class="no-print" onclick="document.getElementById('modalDetailBI').style.display='none'" style="background:#f1f5f9; border:1px solid #e2e8f0; color:#64748b; padding: 5px 10px; border-radius: 5px; font-size: 0.68rem; font-weight: 700; cursor: pointer; display: flex; align-items: center; gap: 4px; font-family: inherit; transition:0.15s;" onmouseover="this.style.background='#e2e8f0'; this.style.color='#334155';" onmouseout="this.style.background='#f1f5f9'; this.style.color='#64748b';">
                    <i class="fa-solid fa-xmark"></i> ${I18N_RAPPORT.btn_fermer}
                </button>
            </div>
        `;

        // --- MÉCANIQUE DES BOUTONS DE SÉCURITÉ ---
        let boutonsActionHtml = "";
        // Bouton Supprimer isolé du reste : rendu à gauche (à côté d'Exporter PDF), pas dans la zone
        // de droite avec PDP/Réouvrir, à la demande de David.
        let boutonSupprimerHtml = "";

        // 1. Bouton PDP (Visible uniquement si une entreprise est liée au ticket)
        if (t.entreprise_ext_id) {
            boutonsActionHtml += `
                <a href="generateur_pdp.php?id_ee=${t.entreprise_ext_id}&id_ticket=${t.id}" target="_blank"
                   style="background-color: #8e44ad; color: white; border: none; padding: 5px 10px; border-radius: 5px; font-weight: bold; cursor: pointer; display: flex; align-items: center; gap: 4px; font-size: 0.68rem; text-decoration:none;">
                   <i class="fa-solid fa-file-shield"></i> ${I18N_RAPPORT.btn_generer_pdp}
                </a>`;
        }

        // 2. Boutons d'admin
        if (typeof rapportIsAdmin !== 'undefined' && rapportIsAdmin === true) {
            if (slug.includes("termin")) {
                boutonsActionHtml += `
                    <button onclick="reouvrirTicketDepuisRapport('${t.id}')"
                        style="background-color: #2ecc71; color: white; border: none; padding: 5px 10px; border-radius: 5px; font-weight: bold; cursor: pointer; display: flex; align-items: center; gap: 4px; font-size: 0.68rem; font-family: inherit;">
                        <i class="fa-solid fa-folder-open"></i> ${I18N_RAPPORT.btn_reouvrir}
                    </button>`;
            } else {
                boutonSupprimerHtml = `
                    <button onclick="supprimerTicketDepuisRapport('${t.id}')"
                        style="background-color: #e74c3c; color: white; border: none; padding: 5px 10px; border-radius: 5px; font-weight: bold; cursor: pointer; display: flex; align-items: center; gap: 4px; font-size: 0.68rem; font-family: inherit;">
                        <i class="fa-solid fa-trash-can"></i> ${I18N_RAPPORT.btn_supprimer}
                    </button>`;
                boutonsActionHtml += `
                    <button onclick="transfererBIVersPreventifHivernal('${t.id}')"
                        style="background-color: #0ea5e9; color: white; border: none; padding: 5px 10px; border-radius: 5px; font-weight: bold; cursor: pointer; display: flex; align-items: center; gap: 4px; font-size: 0.68rem; font-family: inherit;">
                        <i class="fa-solid fa-snowflake"></i> ${I18N_RAPPORT.btn_bascule_hivernal}
                    </button>`;
            }
        } else {
            boutonsActionHtml += `<span style="color: #94a3b8; font-size: 0.75rem; font-style: italic;"><i class="fa-solid fa-lock"></i> ${I18N_RAPPORT.reserve_admins}</span>`;
        }

        const conteneurSupprimer = document.getElementById('zoneBoutonSupprimer');
        if (conteneurSupprimer) {
            conteneurSupprimer.innerHTML = boutonSupprimerHtml;
        }

        const conteneurBoutons = document.getElementById('zoneBoutonsSecurises');
        if (conteneurBoutons) {
            conteneurBoutons.innerHTML = boutonsActionHtml;
        }

        const modalDetailBIEl = document.getElementById('modalDetailBI');
        let maxZExistant = 9999;
        document.querySelectorAll('.modal').forEach(m => {
            if (m !== modalDetailBIEl && getComputedStyle(m).display !== 'none') {
                const z = parseInt(getComputedStyle(m).zIndex) || 0;
                if (z > maxZExistant) { maxZExistant = z; }
            }
        });
        modalDetailBIEl.style.zIndex = maxZExistant + 10;
        modalDetailBIEl.style.display = 'block';
    } catch (e) {
        console.error("Erreur dans showDetailBI :", e);
    }
}

// --- Photos du BI (voir bi_photos.php) : galerie affichée dans la fiche détail, avec ajout/
// suppression réservés à Technicien+Admin (rapportIsStaff) — un compte "portail demandeur" ne
// peut ajouter une photo qu'au moment de la création de sa propre demande, pas depuis cette fiche.
// `prefix` permet de réutiliser cette même galerie ailleurs sur la page (ex. modalClotureBI dans
// maintenance.php, qui a ses propres ids "cloture-photos-*") sans dupliquer toute cette logique.
async function renderRapportPhotos(taskId, prefix = 'rapport') {
    const body = document.getElementById(prefix + '-photos-body');
    const countEl = document.getElementById(prefix + '-photos-count');
    if (!body) return;
    body.innerHTML = `<span style="font-size:0.7rem; color:#94a3b8;"><i class="fa-solid fa-spinner fa-spin"></i> ${I18N_RAPPORT.loading}</span>`;

    let photos = [];
    let csrfToken = '';
    try {
        const res = await fetch('bi_photos.php?action=list&task_id=' + encodeURIComponent(taskId));
        const data = await res.json();
        if (data.success) { photos = data.photos; csrfToken = data.csrf_token; }
    } catch (e) { console.error("Erreur chargement photos :", e); }

    if (countEl) countEl.textContent = photos.length > 0 ? (photos.length > 1 ? I18N_RAPPORT.photo_count_many.replace('{n}', photos.length) : I18N_RAPPORT.photo_count_one.replace('{n}', photos.length)) : '';

    const addBtnHtml = rapportIsStaff ? `
        <label style="display:flex; flex-direction:column; align-items:center; justify-content:center; gap:4px; width:76px; height:76px; border:2px dashed #cbd5e1; border-radius:8px; color:#3498db; font-size:0.58rem; font-weight:700; text-align:center; cursor:pointer; flex-shrink:0;">
            <i class="fa-solid fa-camera" style="font-size:1.1rem;"></i>${I18N_RAPPORT.photo_add}
            <input type="file" accept="image/*" multiple style="display:none;" onchange="uploadRapportPhotos('${taskId}', this.files, '${csrfToken}', '${prefix}')">
        </label>` : '';

    if (photos.length === 0 && !rapportIsStaff) {
        body.innerHTML = `<span style="font-size:0.72rem; color:#94a3b8; font-style:italic;">${I18N_RAPPORT.aucune_photo}</span>`;
        return;
    }

    const escAttr = (s) => (s || '').replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;');

    body.innerHTML = addBtnHtml + photos.map(p => `
        <div style="position:relative; width:76px; height:76px; border-radius:8px; overflow:hidden; border:1px solid #e2e8f0; flex-shrink:0;">
            <img src="${p.chemin}" alt="${escAttr(p.nom_original)}" style="width:100%; height:100%; object-fit:cover; display:block; cursor:pointer;" onclick="openRapportPhotoLightbox('${p.chemin}')">
            ${rapportIsStaff ? `<button type="button" onclick="deleteRapportPhoto(${p.id}, '${taskId}', '${csrfToken}', '${prefix}')" title="${I18N_RAPPORT.supprimer_photo_tooltip}" style="position:absolute; top:2px; right:2px; width:18px; height:18px; border-radius:50%; background:rgba(15,23,42,0.65); color:#fff; border:none; display:flex; align-items:center; justify-content:center; cursor:pointer; font-size:0.6rem; padding:0;"><i class="fa-solid fa-xmark"></i></button>` : ''}
        </div>`).join('');
}

async function uploadRapportPhotos(taskId, fileList, csrfToken, prefix = 'rapport') {
    const files = [...fileList].filter(f => f.type.startsWith('image/'));
    for (const file of files) {
        const fd = new FormData();
        fd.append('action', 'upload');
        fd.append('task_id', taskId);
        fd.append('csrf_token', csrfToken);
        fd.append('photo', file);
        try {
            const res = await fetch('bi_photos.php', { method: 'POST', body: fd });
            const data = await res.json();
            if (!data.success) { await rapportAlert(I18N_RAPPORT.photo_non_ajoutee, data.error || I18N_RAPPORT.erreur_inconnue); }
        } catch (e) {
            console.error("Erreur upload photo :", e);
        }
    }
    renderRapportPhotos(taskId, prefix);
}

async function deleteRapportPhoto(photoId, taskId, csrfToken, prefix = 'rapport') {
    const ok = await rapportConfirm(I18N_RAPPORT.confirm_del_photo_title, I18N_RAPPORT.confirm_del_photo_msg);
    if (!ok) return;
    try {
        const fd = new FormData();
        fd.append('action', 'delete');
        fd.append('photo_id', photoId);
        fd.append('task_id', taskId);
        fd.append('csrf_token', csrfToken);
        await fetch('bi_photos.php', { method: 'POST', body: fd });
    } catch (e) {
        console.error("Erreur suppression photo :", e);
    }
    renderRapportPhotos(taskId, prefix);
}

function openRapportPhotoLightbox(url) {
    let overlay = document.getElementById('rapportPhotoLightbox');
    if (!overlay) {
        overlay = document.createElement('div');
        overlay.id = 'rapportPhotoLightbox';
        overlay.style.cssText = 'display:flex; position:fixed; z-index:100001; left:0; top:0; width:100%; height:100%; background:rgba(0,0,0,0.85); align-items:center; justify-content:center; cursor:zoom-out; padding:20px; box-sizing:border-box;';
        overlay.onclick = () => { overlay.style.display = 'none'; };
        overlay.innerHTML = '<img id="rapportPhotoLightboxImg" style="max-width:100%; max-height:100%; border-radius:6px; box-shadow:0 10px 40px rgba(0,0,0,0.5);">';
        document.body.appendChild(overlay);
    }
    document.getElementById('rapportPhotoLightboxImg').src = url;
    overlay.style.display = 'flex';
}

async function reouvrirTicketDepuisRapport(id) {
    const confirmation = await rapportConfirm(I18N_RAPPORT.confirm_reopen_title, I18N_RAPPORT.confirm_reopen_msg);
    if (!confirmation) return;

    try {
        const res = await fetch('api.php?t=' + Date.now());
        const tasksJson = await res.json();
        const t = tasksJson.find(x => x.id == id);

        if (!t) {
            await rapportAlert(I18N_RAPPORT.err_title, I18N_RAPPORT.ticket_introuvable);
            return;
        }

        t.statut = "En cours";
        t.action_user = rapportCurrentUser;

        const response = await fetch('api.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(t)
        });

        if (response.ok) {
            await rapportAlert(I18N_RAPPORT.success_title, I18N_RAPPORT.reopen_success);
            location.reload();
        } else {
            await rapportAlert(I18N_RAPPORT.err_title, I18N_RAPPORT.err_reopen_server);
        }
    } catch (e) {
        await rapportAlert(I18N_RAPPORT.err_network_title, e.message);
    }
}

// ============================================================================
// BASCULE D'UN BI DÉJÀ CRÉÉ VERS LA CHECKLIST « TRAVAUX HIVER » (préventif hivernal) : à la
// différence de transfererVersPreventif() (maintenance.php, pour une simple demande SAS pas
// encore actionnée, toujours supprimée), un BI peut déjà porter des heures pointées et un
// compte-rendu — on ne le supprime donc que s'il n'a strictement aucun pointage. S'il y en a, on
// le clôture normalement (heures/compte-rendu conservés dans l'historique) avec un compte-rendu
// explicite, plutôt que de perdre ce temps déjà enregistré. Décision de David (27/08/2026).
// ============================================================================
async function transfererBIVersPreventifHivernal(id) {
    const t = tasks.find(x => x.id == id);
    if (!t) { await rapportAlert(I18N_RAPPORT.err_title, I18N_RAPPORT.ticket_introuvable); return; }

    const ptgs = pointages.filter(p => p.task_id === id);
    const aDesHeures = ptgs.some(p => (parseFloat(p.hours) || 0) > 0);

    const messageConfirm = aDesHeures
        ? I18N_RAPPORT.confirm_bascule_avec_heures
        : I18N_RAPPORT.confirm_bascule_sans_heures;
    const ok = await rapportConfirm(I18N_RAPPORT.confirm_bascule_hivernal_title, messageConfirm);
    if (!ok) return;

    try {
        const resAdd = await fetch('preventif_liste.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action: 'checklist_add',
                categorie: 'maintenance_preventive',
                usine: t.usine || '',
                equip: t.equip || '',
                desc: t.desc || t.description || '',
                signale_par: t.demandeur || rapportCurrentUser
            })
        });
        if (!resAdd.ok) { await rapportAlert(I18N_RAPPORT.err_title, I18N_RAPPORT.transfer_err_add); return; }

        if (aDesHeures) {
            t.statut = "Terminée";
            t.compte_rendu = I18N_RAPPORT.compte_rendu_bascule;
            t.action_user = rapportCurrentUser;
            await fetch('api.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(t) });
        } else {
            await fetch('api.php?delete=' + encodeURIComponent(id));
        }

        document.getElementById('modalDetailBI').style.display = 'none';
        await rapportAlert(I18N_RAPPORT.transfer_success_title, aDesHeures
            ? I18N_RAPPORT.bascule_success_avec_heures
            : I18N_RAPPORT.bascule_success_sans_heures);
        location.reload();
    } catch (e) {
        await rapportAlert(I18N_RAPPORT.err_network_title, e.message);
    }
}

async function supprimerTicketDepuisRapport(id) {
    const modalHote = document.getElementById('modalConfirmDel') || 
                      document.getElementById('modalDeleteTask') || 
                      document.getElementById('modalSupprimerEvt'); 
    
    if (modalHote) {
        if (typeof idToDelete !== 'undefined') idToDelete = id;
        else window.idToDelete = id;

        const zoneTexte = modalHote.querySelector('p');
        if (zoneTexte) {
            zoneTexte.innerHTML = `${I18N_RAPPORT.confirm_del_msg_host}<br><span style="font-weight: bold; color: var(--danger);">${I18N_RAPPORT.del_absolute_irreversible}</span>`;
        }

        const btnFinal = document.getElementById('btnConfirmDeleteFinal') || 
                         document.getElementById('btnValidDelete') ||
                         modalHote.querySelector('.btn-danger');
        
        if (btnFinal) {
            btnFinal.onclick = async function() {
                const modalRapport = document.getElementById('modalDetailBI');
                if (modalRapport) modalRapport.style.display = 'none';
                
                if (typeof executerSuppressionRéelle === 'function') {
                    await executerSuppressionRéelle();
                } else if (typeof supprimerTachePlanning === 'function') {
                    await supprimerTachePlanning();
                } else if (typeof confirmDelete === 'function') {
                    await confirmDelete();
                } else {
                    await fetch(`api.php?delete=${encodeURIComponent(id)}`);
                    location.reload();
                }
            };
        }

        modalHote.style.zIndex = "200010"; 
        modalHote.style.display = 'block';

    } else {
        const confirmation = await rapportConfirm(I18N_RAPPORT.confirm_del_title, I18N_RAPPORT.confirm_del_msg_simple);
        if (confirmation) {
            fetch(`api.php?delete=${encodeURIComponent(id)}`).then(() => location.reload());
        }
    }
}

function exporterPDF_Centralise(biName) {
    const element = document.getElementById('zoneAImprimer');
    const actions = element.querySelector('.no-print');
    if(actions) actions.style.visibility = 'hidden';

    const opt = {
        margin: 10,
        filename: 'RI_' + biName + '.pdf',
        image: { type: 'jpeg', quality: 0.98 },
        html2canvas: { 
            scale: 2, 
            useCORS: true, 
            logging: false,
            letterRendering: true,
            scrollY: 0,
            scrollX: 0
        },
        jsPDF: { unit: 'mm', format: 'a4', orientation: 'portrait' },
        pagebreak: { mode: ['avoid', 'css'] } 
    };

    element.style.width = "170mm"; 
    
    html2pdf().set(opt).from(element).save().then(() => {
        element.style.width = "100%";
        if(actions) actions.style.visibility = 'visible';
    }).catch(err => {
        element.style.width = "100%";
        if(actions) actions.style.visibility = 'visible';
    });
}

// --- SAUVEGARDE DU RAPPORT INTERMÉDIAIRE ---
async function sauvegarderNoteIntermediaire(id) {
    const textareaNote = document.getElementById('note-intermediaire-' + id);
    if (!textareaNote) return;

    const note = textareaNote.value;
    const btn = document.getElementById('btn-save-note-' + id);
    
    const oldText = btn.innerHTML;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> ' + I18N_RAPPORT.saving;
    btn.disabled = true;

    try {
        const response = await fetch('api.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ 
                id: id, 
                rapport_intermediaire: note, 
                action: 'update_note' 
            })
        });

        if (response.ok) {
            // showDetailBI() ne refait un fetch que si `tasks` est vide : sur les pages où
            // elle est déjà chargée, il fallait recharger la page pour voir la note enregistrée.
            // On met donc à jour le cache local directement, en plus de l'appel serveur.
            if (typeof tasks !== 'undefined') {
                const tLocal = tasks.find(x => x.id === id);
                if (tLocal) tLocal.rapport_intermediaire = note;
            }
            await rapportAlert(I18N_RAPPORT.success_title, I18N_RAPPORT.carnet_success);
            await showDetailBI(id);
        } else {
            await rapportAlert(I18N_RAPPORT.err_title, I18N_RAPPORT.err_server_refused);
        }
    } catch(e) {
        console.error("Erreur de sauvegarde:", e);
        await rapportAlert(I18N_RAPPORT.err_network_title, I18N_RAPPORT.err_network_unreachable);
    }

    btn.innerHTML = oldText;
    btn.disabled = false;
}
</script>

<?php include 'composant_messagerie.php'; ?>

<script>
setMessagerieUser(rapportCurrentUser);
</script>
