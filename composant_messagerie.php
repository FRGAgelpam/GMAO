<!--
    COMPOSANT PARTAGÉ : fenêtre de messagerie liée à un ticket/BI.
    Utilisée à la fois côté GMAO (composant_rapport.php, depuis le Rapport
    d'Intervention) et côté Portail Services (suivi.php, après vérification
    du code personnel) — une seule implémentation, pour ne plus avoir deux
    fenêtres à faire évoluer séparément.

    Chaque page hôte doit :
      1. Inclure ce fichier une fois.
      2. Appeler setMessagerieUser(nom) avec le nom de l'utilisateur courant
         (à mettre à jour à chaque fois que cet utilisateur change, par ex.
         après une vérification de code personnel côté portail).
      3. Ouvrir la fenêtre via ouvrirChatTicket(taskId, nomAutrePersonne, contexte).
         - Sur les pages GMAO qui ont déjà le tableau global `tasks` en mémoire,
           le paramètre `contexte` peut être omis : la localisation, la panne
           et la date de la demande sont retrouvées automatiquement dedans.
         - Sur les pages sans ce tableau (le portail), passer explicitement
           contexte = { numBi, localisation, panne, dateHeure }.
-->
<div id="modalChatTicket" class="modal-overlay" style="z-index: 200050; display:none; position:fixed; left:0; top:0; width:100%; height:100%; background:rgba(15,23,42,0.7); backdrop-filter:blur(4px); align-items:center; justify-content:center;">
    <div class="modal-content-detail" style="max-width:520px; width:95%; border-top: 5px solid #3498db; background:#f8fafc; padding:0; display:flex; flex-direction:column; height: 72vh; min-height: 460px; border-radius:10px; overflow:hidden; box-shadow:0 20px 25px -5px rgba(0,0,0,0.3);" onclick="event.stopPropagation()">

        <div style="padding: 16px 20px 14px; display:flex; justify-content:space-between; align-items:flex-start; background:white; border-bottom: 1px solid #e2e8f0;">
            <div>
                <h3 style="margin:0; font-size:1.1rem; line-height:1.2; color:var(--primary); font-weight:700; display:flex; align-items:center; gap:8px;"><i class="fa-solid fa-comments" style="color:#3498db;"></i> <?php echo htmlspecialchars(t('chat.title')); ?></h3>
                <div id="chat-ticket-id" style="font-size:0.72rem; color:#94a3b8; margin-top:6px; display:flex; gap:12px; flex-wrap:wrap;"></div>
            </div>
            <span style="cursor:pointer; font-size:26px; color:#cbd5e1; line-height:1;" onclick="document.getElementById('modalChatTicket').style.display='none'">&times;</span>
        </div>

        <div id="chat-ticket-context" style="padding: 12px 20px; background:#fff; border-bottom:1px solid #e2e8f0; display:flex; flex-direction:column; gap:7px; flex-shrink:0;"></div>

        <div style="flex:1; padding: 15px; overflow-y:auto; background:#f8fafc; min-height:0;">
            <div id="chat-ticket-messages" style="display:flex; flex-direction:column; gap:10px; background:#f8fafc; border:1px solid #eef2f5; border-radius:8px; padding:12px; min-height:100%; box-sizing:border-box;"></div>
        </div>

        <div style="padding: 15px; background: white; border-top: 1px solid #e2e8f0;">
            <label style="display:block; font-size:0.65rem; font-weight:700; color:#64748b; text-transform:uppercase; letter-spacing:0.4px; margin-bottom:6px;"><?php echo htmlspecialchars(t('chat.new_message')); ?></label>
            <div style="display:flex; gap:10px;">
                <input type="hidden" id="chat-ticket-task-id">
                <textarea id="chat-ticket-input" placeholder="<?php echo htmlspecialchars(t('chat.input_placeholder')); ?>" rows="1" style="flex:1; padding:9px 11px; border:1px solid #ddd; border-radius:6px; outline:none; font-family:inherit; font-size:0.85rem; background:#fff; resize:vertical; min-height:40px; max-height:110px;"></textarea>
                <button onclick="envoyerMessageTicket()" style="background:linear-gradient(135deg, #3ddc84, #2ecc71); color:white; border:none; border-radius:8px; padding:0 18px; cursor:pointer; transition:0.2s; font-weight:700; font-size:0.82rem; box-shadow:0 3px 8px rgba(46,204,113,0.35); flex-shrink:0; white-space:nowrap;"><i class="fa-solid fa-paper-plane"></i> <?php echo htmlspecialchars(t('chat.send')); ?></button>
            </div>
        </div>
    </div>
</div>

<script>
const I18N_CHAT = <?php echo json_encode([
    'other_party_fallback' => t('chat.other_party_fallback'),
    'production_fallback' => t('chat.production_fallback'),
    'no_info' => t('chat.no_info'),
    'reported_on' => t('chat.reported_on'),
    'no_exchange' => t('chat.no_exchange'),
    'you' => t('chat.you'),
    'date_at' => t('idee.date_at'),
    'relance_message' => t('chat.relance_message'),
    'relance_message_bi' => t('chat.relance_message_bi'),
]); ?>;

let messagerieCurrentUser = null;
function setMessagerieUser(nom) { messagerieCurrentUser = nom; }

async function ouvrirChatTicket(taskId, nomAutrePersonne, contexteExplicite) {
    let vraiInterlocuteur = nomAutrePersonne || I18N_CHAT.other_party_fallback;
    let numBiAffiche = '';
    let panneTexte = '';
    let localisationTxt = '';
    let dateHeureDemande = '';

    if (contexteExplicite) {
        numBiAffiche = contexteExplicite.numBi || '';
        panneTexte = contexteExplicite.panne || '';
        localisationTxt = contexteExplicite.localisation || '';
        dateHeureDemande = contexteExplicite.dateHeure || '';
    } else if (typeof tasks !== 'undefined') {
        // Pages GMAO (maintenance.php et consorts) : tout est déjà en mémoire dans `tasks`.
        const t = tasks.find(x => x.id == taskId);
        if (t) {
            let descBrute = t.desc || "";
            vraiInterlocuteur = nomAutrePersonne || t.action_user || I18N_CHAT.production_fallback;
            numBiAffiche = t.num_bi || '';
            panneTexte = descBrute;

            if (descBrute.toUpperCase().includes("DEMANDE DE :")) {
                let parts = descBrute.split(' - ');
                let match = descBrute.match(/DEMANDE DE\s*:\s*([^-\[\n]+)/i);
                if (match && match[1]) {
                    vraiInterlocuteur = match[1].trim();
                    if (vraiInterlocuteur.includes('(')) {
                        vraiInterlocuteur = vraiInterlocuteur.substring(0, vraiInterlocuteur.indexOf('(')).trim();
                    }
                }
                // Le premier segment est "DEMANDE DE : Nom (Fonction)" : le reste est la vraie panne.
                panneTexte = parts.slice(1).join(' - ').trim() || descBrute;
            }

            localisationTxt = [t.usine, t.secteur, t.ligne, t.zone].filter(v => v && v !== 'N/A').join(' > ');
            if (t.equip) { localisationTxt = localisationTxt ? (localisationTxt + ' — ' + t.equip) : t.equip; }

            const dateBrute = t.date_creation || t.date;
            if (dateBrute && dateBrute.includes('-')) {
                const jourMois = dateBrute.split(' ')[0].split('-').reverse().join('/');
                let heure = (dateBrute.split(' ')[1] || '').substring(0, 5);
                // 08:00 est une heure par défaut, pas la vraie heure de signalement.
                if ((heure === '08:00' || !heure) && t.date_creation && t.date_creation.includes(' ')) {
                    heure = t.date_creation.split(' ')[1].substring(0, 5);
                }
                dateHeureDemande = jourMois + (heure ? ' ' + I18N_CHAT.date_at + ' ' + heure : '');
            }
        }
    }

    // Ligne meta au même format que celle des cartes d'Idées GMAO (icône + texte, par item).
    const metaSpan = 'style="display:flex; align-items:center; gap:4px;"';
    document.getElementById('chat-ticket-id').innerHTML =
        `<span ${metaSpan}><i class="fa-solid fa-user"></i> ${vraiInterlocuteur}</span>` +
        (numBiAffiche ? `<span ${metaSpan}><i class="fa-solid fa-clipboard-list"></i> ${numBiAffiche}</span>` : '');

    // Encart de contexte : localisation, nature de la panne, jour/heure de la demande —
    // pour ne pas avoir à rouvrir le rapport / le détail du ticket pendant qu'on discute.
    const ctxRow = 'style="display:flex; gap:9px; align-items:flex-start; font-size:0.78rem; color:#475569; line-height:1.35;"';
    let ctxHtml = '';
    if (localisationTxt) {
        ctxHtml += `<div ${ctxRow}><i class="fa-solid fa-location-dot" style="color:#3498db; width:14px; margin-top:2px; flex-shrink:0;"></i><div><b>${localisationTxt}</b></div></div>`;
    }
    ctxHtml += `<div ${ctxRow}><i class="fa-solid fa-triangle-exclamation" style="color:#f39c12; width:14px; margin-top:2px; flex-shrink:0;"></i><div style="font-style:italic;">"${(panneTexte || I18N_CHAT.no_info).replace(/</g, '&lt;')}"</div></div>`;
    if (dateHeureDemande) {
        ctxHtml += `<div ${ctxRow}><i class="fa-solid fa-clock" style="color:#94a3b8; width:14px; flex-shrink:0;"></i><div style="color:#94a3b8; font-size:0.74rem;">${I18N_CHAT.reported_on.replace('{date}', dateHeureDemande)}</div></div>`;
    }
    document.getElementById('chat-ticket-context').innerHTML = ctxHtml;

    document.getElementById('chat-ticket-task-id').value = taskId;
    document.getElementById('modalChatTicket').style.display = 'flex';
    document.getElementById('chat-ticket-input').value = '';

    await chargerMessagesChat(taskId);

    if (window.chatInterval) clearInterval(window.chatInterval);
    window.chatInterval = setInterval(() => {
        if (document.getElementById('modalChatTicket').style.display === 'flex') {
            chargerMessagesChat(taskId);
        } else {
            clearInterval(window.chatInterval);
        }
    }, 5000);
}

async function chargerMessagesChat(taskId) {
    const conteneur = document.getElementById('chat-ticket-messages');

    try {
        await fetch('api.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'marquer_lus', task_id: taskId, expediteur: messagerieCurrentUser })
        });

        if (typeof verifierNotifications === 'function') verifierNotifications();

        const res = await fetch(`api.php?action=get_chat_messages&task_id=${taskId}&t=${Date.now()}`);
        const messages = await res.json();

        conteneur.innerHTML = '';
        if (messages.length === 0) {
            conteneur.innerHTML = '<div style="text-align:center; color:#94a3b8; font-size:0.85rem; margin-top:20px; font-style:italic;">' + I18N_CHAT.no_exchange + '</div>';
            return;
        }

        messages.forEach(msg => {
            const isMoi = (msg.expediteur === messagerieCurrentUser);
            const align = isMoi ? 'flex-end' : 'flex-start';
            // "vous" en dégradé bleu, l'autre partie en dégradé orange.
            const bg = isMoi ? 'linear-gradient(135deg, #4f8ef7, #3d6fe0)' : 'linear-gradient(135deg, #ffb75e, #f5942b)';
            const radius = isMoi ? '12px 12px 4px 12px' : '12px 12px 12px 4px';
            const dateObj = new Date(msg.date_envoi);
            const dateHeure = dateObj.toLocaleDateString('fr-FR', {day:'2-digit', month:'2-digit', year:'numeric'}) + ' ' + I18N_CHAT.date_at + ' ' + dateObj.toLocaleTimeString('fr-FR', {hour: '2-digit', minute:'2-digit'});
            const texte = (msg.message || '').replace(/</g, '&lt;');

            conteneur.innerHTML += `
                <div style="align-self: ${align}; max-width: 80%;">
                    <div style="background: ${bg}; color: #fff; padding: 8px 11px; border-radius: ${radius}; font-size: 0.8rem; line-height: 1.4; box-shadow: 0 1px 3px rgba(15,23,42,0.08); word-wrap: break-word;">
                        <div style="font-size:0.6rem; font-weight:700; text-transform:uppercase; color:rgba(255,255,255,0.75); margin-bottom:2px;">${isMoi ? I18N_CHAT.you : msg.expediteur}</div>
                        <div style="white-space:pre-line;">${texte}</div>
                        <div style="font-size:0.58rem; color:rgba(255,255,255,0.7); margin-top:4px; text-align:right;">${dateHeure}</div>
                    </div>
                </div>
            `;
        });
        conteneur.scrollTop = conteneur.scrollHeight;
    } catch(e) {}
}

async function envoyerMessageTicket() {
    const input = document.getElementById('chat-ticket-input');
    const message = input.value.trim();
    const taskId = document.getElementById('chat-ticket-task-id').value;

    if (!message) return;
    input.disabled = true;

    try {
        const response = await fetch('api.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action: 'send_chat_message',
                task_id: taskId,
                expediteur: messagerieCurrentUser,
                message: message
            })
        });

        if (response.ok) {
            input.value = '';
            await chargerMessagesChat(taskId);
        }
    } catch (e) {}

    input.disabled = false;
    input.focus();
}

document.addEventListener('keydown', function(e) {
    if (e.key === 'Enter' && !e.shiftKey && document.activeElement.id === 'chat-ticket-input') {
        e.preventDefault(); // Entrée envoie, Maj+Entrée fait un saut de ligne (le champ est multi-ligne)
        envoyerMessageTicket();
    }
});
</script>
