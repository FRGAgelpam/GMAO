<?php

date_default_timezone_set('Europe/Paris');

// SÉCURITÉ : ce script n'est destiné qu'au cron interne du serveur
// (curl http://127.0.0.1/cron_preventif.php toutes les minutes) — on bloque
// tout appel qui ne vient pas de la machine elle-même.
$appelant = $_SERVER['REMOTE_ADDR'] ?? '';
if (!in_array($appelant, ['127.0.0.1', '::1'], true)) {
    http_response_code(403);
    exit('Accès refusé.');
}

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

header('Content-Type: text/html; charset=utf-8');
// CRON JOB : SCRIPT DE GÉNÉRATION AUTOMATIQUE DES BONS PRÉVENTIFS
require 'db.php';
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Sécurité : s'assure que la colonne de traçabilité existe même si aucune page n'a encore
// déclenché l'ALTER équivalent dans api.php / preventif.php (ce script ne les inclut pas).
$db->exec("ALTER TABLE taches ADD COLUMN IF NOT EXISTS rule_id VARCHAR(64) DEFAULT NULL");
$db->exec("ALTER TABLE preventif_regles ADD COLUMN IF NOT EXISTS auto_matin TINYINT(1) NOT NULL DEFAULT 0");

$maintenant = date('Y-m-d H:i:s');
$aujourdhui = date('Y-m-d');
$temps_actuel = time(); // Temps en secondes depuis 1970 (idéal pour les calculs)

$jourSemaineMap = [1 => 'lun', 2 => 'mar', 3 => 'mer', 4 => 'jeu', 5 => 'ven', 6 => 'sam', 0 => 'dim'];
$jourAuj = $jourSemaineMap[(int)date('w')];

// Les préventifs "réels" ne se génèrent qu'à 5h du matin (début du 1er poste),
// pour que le technicien découvre les nouveaux BI à sa prise de poste.
// Le mode "test_1m" (dev) échappe à cette règle : il doit rester instantané pour tester une règle.
$heureActuelle = (int)date('H');
$creneauCinqHeures = ($heureActuelle === 5);

echo "Démarrage de l'analyse des préventifs...<br>";

$plans = $db->query("SELECT * FROM preventif_regles WHERE status != 'pause'")->fetchAll(PDO::FETCH_ASSOC);

foreach ($plans as $p) {
    $mode = $p['mode_planif'] ?: 'frequence';
    $generer = false;

    if ($mode === 'jours_semaine') {
        // Mode jours de la semaine : on génère si le jour actuel fait partie des jours cochés,
        // et qu'on n'a pas déjà généré aujourd'hui (protège contre un cron lancé plusieurs fois/jour)
        $joursListe = array_filter(explode(',', $p['jours'] ?? ''));
        $dejaGenereAujourdhui = ($p['last_gen_date'] === $aujourdhui);
        if (in_array($jourAuj, $joursListe, true) && !$dejaGenereAujourdhui && $creneauCinqHeures) {
            $generer = true;
        }
    } else {
        // Mode fréquence (comportement historique, inchangé) : décompte de jours écoulés
        $freq_val = $p['freq'];
        $last_gen = !empty($p['last_gen']) ? $p['last_gen'] : '2000-01-01 00:00:00';
        $temps_last_gen = strtotime($last_gen);

        if ($freq_val === 'test_1m') {
            // Mode test : on vérifie si 60 secondes d'écart se sont écoulées
            if (($temps_actuel - $temps_last_gen) >= 60) {
                $generer = true;
            }
        } else {
            $freq_jours = (int)$freq_val;
            $diff_jours = ($temps_actuel - $temps_last_gen) / (60 * 60 * 24);
            if ($freq_jours > 0 && $diff_jours >= $freq_jours && $creneauCinqHeures) {
                $generer = true;
            }
        }
    }

    if ($generer) {
        // Création du titre spécifique
        $desc_complete = "[PRÉVENTIF] " . $p['descr'];

        try {
            // Calcul du nouvel ID de ticket (Pour éviter l'erreur 1364)
            $stmtMax = $db->query("SELECT MAX(CAST(id AS UNSIGNED)) as max_id FROM taches");
            $resMax = $stmtMax->fetch(PDO::FETCH_ASSOC);
            $nouvel_id = ($resMax['max_id'] !== null) ? $resMax['max_id'] + 1 : 1;

            $tech = !empty($p['intervenant']) ? $p['intervenant'] : null;

            // Assignation auto au technicien du poste "Matin" du jour (planning), à la place de
            // l'intervenant fixe de la règle — voir preventif_liste.php (case "auto_matin").
            // Si personne n'est au matin ce jour-là (0 ou 2+ candidats), on ne retombe PAS sur
            // l'intervenant fixe : la demande arrive sans intervenant dans le SAS de validation.
            if (!empty($p['auto_matin'])) {
                $stmtMatin = $db->prepare("SELECT utilisateur FROM planning_shifts WHERE jour = ? AND poste = 'matin' ORDER BY utilisateur ASC LIMIT 1");
                $stmtMatin->execute([$aujourdhui]);
                $techMatin = $stmtMatin->fetchColumn();
                $tech = $techMatin !== false ? $techMatin : null;
            }

            $stmt = $db->prepare("INSERT INTO taches
                (id, date, equip, description, statut, type, demandeur, usine, secteur, ligne, zone, prio, rule_id)
                VALUES (?, ?, ?, ?, 'À faire', 'Préventif', 'GMAO', ?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                $nouvel_id,
                $maintenant, // Date d'aujourd'hui AVEC L'HEURE EXACTE
                $p['equip'],
                $desc_complete,
                $p['usine'], $p['secteur'], $p['ligne'], $p['zone'],
                $p['prio'] ?: 'Normal',
                $p['id']
            ]);

            // Si un intervenant est assigné à la règle, on crée aussi son pointage
            // pour que la tâche apparaisse immédiatement sur le planning (colonne du technicien, jour du jour).
            // Sans intervenant, il n'y a pas de colonne technicien à occuper : le ticket reste
            // visible dans le SAS de validation mais n'apparaît pas encore sur le planning.
            if ($tech) {
                $stmtPtg = $db->prepare("INSERT INTO pointages (task_id, tech, date, hours) VALUES (?, ?, ?, 0)");
                $stmtPtg->execute([$nouvel_id, $tech, $maintenant]);
            }

            $upd = $db->prepare("UPDATE preventif_regles SET last_gen = ?, last_gen_date = ? WHERE id = ?");
            $upd->execute([$maintenant, $aujourdhui, $p['id']]);

            // L'échéance affichée dans le tableau (statut À jour/Bientôt/En retard) est un champ figé,
            // saisi une seule fois à la création — elle ne bouge jamais toute seule. Sans ce recalcul,
            // une règle en mode "fréquence" finit systématiquement par s'afficher "En retard" au bout
            // d'un ou deux cycles, même quand chaque génération est bien suivie d'un BI clôturé dans les
            // temps : l'échéance n'a jamais avancé pour refléter la prochaine occurrence. Non applicable
            // au mode "jours de la semaine", qui n'utilise pas ce champ (voir getPlanStatus côté client).
            if ($mode !== 'jours_semaine' && $p['freq'] !== 'test_1m') {
                $freq_jours_maj = (int)$p['freq'];
                if ($freq_jours_maj > 0) {
                    $nouvelle_echeance = date('Y-m-d', strtotime($aujourdhui . " +{$freq_jours_maj} days"));
                    $db->prepare("UPDATE preventif_regles SET echeance = ? WHERE id = ?")
                       ->execute([$nouvelle_echeance, $p['id']]);
                }
            }

            echo "<span style='color:green;'>[+] Généré : " . $p['equip'] . " - " . $p['descr'] . " (règle " . $p['id'] . ")</span><br>";

        } catch (Exception $e) {
            echo "<span style='color:red;'>[!] Erreur SQL : " . $e->getMessage() . "</span><br>";
        }
    }
}

echo "<b>Analyse terminée.</b>";
?>
