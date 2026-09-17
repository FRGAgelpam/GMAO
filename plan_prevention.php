<?php
require_once __DIR__ . '/session_init.php';
// SÉCURITÉ : page accessible uniquement depuis sous_traitants.php, réservée au personnel
// maintenance — elle n'avait auparavant aucun contrôle de session.
if (!isset($_SESSION['user']) || !isset($_SESSION['role']) || !in_array(strtolower($_SESSION['role']), ['admin', 'technicien'])) {
    header("Location: login.php");
    exit();
}
require 'db.php';

// Identité affichée sur le document, configurable depuis Paramètres > Général
$nom_entreprise_pdp = "GMAO";
$logo_path_pdp = "img/logo.png";
try {
    $general_pdp = $db->query("SELECT cle, valeur FROM parametres_general")->fetchAll(PDO::FETCH_KEY_PAIR);
    if (!empty($general_pdp['nom_entreprise'])) { $nom_entreprise_pdp = $general_pdp['nom_entreprise']; }
    if (!empty($general_pdp['logo_path'])) { $logo_path_pdp = $general_pdp['logo_path']; }
} catch (Exception $e) {}

// --- 1. AJAX : GÉNÉRER LE NUMÉRO EN DIRECT ---
if (isset($_GET['action']) && $_GET['action'] === 'get_num' && isset($_GET['nom'])) {
    $nom_clean = strtoupper(preg_replace('/[^a-zA-Z0-9]/', '', $_GET['nom']));
    if (empty($nom_clean)) { echo "PDP-BROUILLON"; exit; }
    $annee = date('y');
    $prefixe = "PDP-" . $nom_clean . "-" . $annee . "-";
    // Compte le nombre de fichiers existants pour cette entreprise cette année
    $fichiers = glob("uploads/pdp/" . $prefixe . "*.pdf");
    $count = ($fichiers !== false) ? count($fichiers) + 1 : 1;
    echo $prefixe . str_pad($count, 3, '0', STR_PAD_LEFT);
    exit;
}

// --- 2. SAUVEGARDE DU PDF ENVOYÉ PAR LE JAVASCRIPT (Méthode Hybride) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['pdf_file']) && isset($_POST['nom_ee'])) {
    $nom_ee = trim($_POST['nom_ee']);
    $num_pdp = preg_replace('/[^a-zA-Z0-9\-]/', '', $_POST['num_pdp']);
    $id_ee = !empty($_POST['id_ee']) ? $_POST['id_ee'] : null;

    // Vérification d'erreur de transfert
    if ($_FILES['pdf_file']['error'] !== UPLOAD_ERR_OK) {
        $code = $_FILES['pdf_file']['error'];
        echo json_encode(['status' => 'error', 'message' => "Erreur d'envoi du fichier PDF (Code $code)."]);
        exit;
    }

    $uploadDir = 'uploads/pdp/';
    if (!is_dir($uploadDir)) { mkdir($uploadDir, 0755, true); }
    $uploadFile = $uploadDir . $num_pdp . '.pdf';

    // On déplace le PDF parfait généré par le navigateur
    if (move_uploaded_file($_FILES['pdf_file']['tmp_name'], $uploadFile)) {
        
        // Si l'entreprise n'existait pas (Porte d'urgence), on la crée en mode "Ponctuel"
        if (!$id_ee) {
            $stmtCheck = $db->prepare("SELECT id FROM entreprises_ext WHERE nom = ?");
            $stmtCheck->execute([$nom_ee]);
            $existing = $stmtCheck->fetch(PDO::FETCH_ASSOC);

            if ($existing) {
                $id_ee = $existing['id'];
            } else {
                $stmt = $db->prepare("INSERT INTO entreprises_ext (nom, type_pdp, fichier_pdp) VALUES (?, 'Ponctuel', ?)");
                $stmt->execute([$nom_ee, $uploadFile]);
                $id_ee = $db->lastInsertId();
            }
        }
        
        // Enregistrement du lien PDF sur la fiche de l'entreprise
        if ($id_ee) {
            $stmt = $db->prepare("UPDATE entreprises_ext SET fichier_pdp = ? WHERE id = ?");
            $stmt->execute([$uploadFile, $id_ee]);
        }
        
        echo json_encode(['status' => 'success']);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Erreur d\'écriture du PDF sur le serveur Debian.']);
    }
    exit;
}

// --- 3. LECTURE DES DONNÉES AU CHARGEMENT ---
$id_ee_prefill = $_GET['id_ee'] ?? '';
$nom_ee_prefill = $_GET['nom_ee'] ?? '';
$usine_prefill = $_GET['usine'] ?? '';
$equip_prefill = $_GET['equip'] ?? '';
$num_pdp_prefill = "PDP-BROUILLON";

if (!empty($id_ee_prefill)) {
    try {
        $stmt = $db->prepare("SELECT nom FROM entreprises_ext WHERE id = ?");
        $stmt->execute([$id_ee_prefill]);
        $entreprise = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($entreprise) {
            $nom_ee_prefill = $entreprise['nom'];
            $nom_clean = strtoupper(preg_replace('/[^a-zA-Z0-9]/', '', $nom_ee_prefill));
            $annee = date('y');
            $prefixe = "PDP-" . $nom_clean . "-" . $annee . "-";
            $fichiers = glob("uploads/pdp/" . $prefixe . "*.pdf");
            $count = ($fichiers !== false) ? count($fichiers) + 1 : 1;
            $num_pdp_prefill = $prefixe . str_pad($count, 3, '0', STR_PAD_LEFT);
        }
    } catch(Exception $e) {}
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Plan de Prévention - <?php echo htmlspecialchars($nom_entreprise_pdp); ?></title>
    <link rel="icon" type="image/png" href="img/logo.png?v=2">
    
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <style>
        /* --- STYLE GENERAL ET IMPRESSION --- */
        body { font-family: 'Calibri', Arial, sans-serif; background: #e2e8f0; color: #000000; padding: 20px; font-size: 13px; line-height: 1.4; }
        
        body, table, th, td, p, span, div, strong, h1, h2, h3 { color: #000000 !important; }
        
        .a4-page { background: #fff; max-width: 900px; margin: 0 auto; padding: 40px; box-shadow: 0 10px 25px rgba(0,0,0,0.2); }
        
        /* Barre d'action flottante */
        .action-bar { position: fixed; top: 20px; right: 20px; display: flex; gap: 10px; z-index: 1000; }
        .btn-action { padding: 10px 15px; border: none; border-radius: 6px; font-weight: bold; cursor: pointer; color: white !important; font-size: 14px; box-shadow: 0 4px 6px rgba(0,0,0,0.1); transition: 0.2s; }
        .btn-pdf { background-color: #e74c3c; }
        .btn-pdf:hover { background-color: #c0392b; transform: translateY(-2px); }
        .btn-print { background-color: #34495e; }
        .btn-print:hover { background-color: #2c3e50; transform: translateY(-2px); }

        /* Tableaux */
        table { width: 100%; border-collapse: collapse; margin-bottom: 15px; }
        th, td { border: 1px solid #000; padding: 8px; vertical-align: top; }
        th { background: #e7e6e6; text-align: left; font-weight: 900; }
        
        .header-table td { border: none; padding: 0; vertical-align: middle; }
        .title-doc { font-size: 24px; font-weight: 900; text-align: center; text-transform: uppercase; }
        
        /* Couleurs de charte */
        .alert-text { color: #ff0000 !important; font-weight: bold; }
        .text-black { font-weight: 900; }
        
        /* --- NOUVELLES COULEURS VERTES --- */
        .green-title {
            background-color: #a8e67a !important; 
            padding: 8px 12px;
            border: 2px solid #548235; 
            border-radius: 4px;
            margin-top: 30px;
            font-weight: 900;
            text-transform: uppercase;
            font-size: 15px;
        }

        .main-risk-title {
            background-color: #70AD47 !important; 
            color: #ffffff !important;
            padding: 10px;
            border: 2px solid #385723;
            margin-top: 30px;
            text-align: center;
            font-weight: 900;
            font-size: 16px;
        }

        .risk-content th {
            background-color: #e2f0d9 !important; 
        }

        /* Menu déroulant */
        .select-db {
            border: 1px solid #ccc; padding: 5px; border-radius: 4px;
            font-family: inherit; font-weight: bold; font-size: 13px;
            width: 100%; margin-bottom: 5px; background: #fff;
        }

        /* Cases des tableaux de signatures (entreprise extérieure/sous-traitante/utilisatrice) :
           la cellule du tableau porte déjà la bordure, le champ ne doit donc pas en rajouter une. */
        .cell-input {
            width: 100%; border: none; outline: none; background: transparent;
            font-family: inherit; font-size: inherit; color: inherit;
            padding: 0; box-sizing: border-box;
        }

        /* Lignes resserrées pour les tableaux "Entreprise extérieure" / "Entreprises sous-traitantes" :
           padding réduit par rapport au th/td général (8px), pour tenir 5 lignes sans trop grandir. */
        .signature-table th, .signature-table td { padding: 3px 6px; }

        /* Images intégrées */
        .img-inline { vertical-align: middle; margin-right: 10px; }
        .img-float-left { float: left; margin-right: 15px; margin-bottom: 10px; }
        .img-center { display: block; margin: 15px auto; max-width: 100%; }
        
        /* --- MECANIQUE D'AFFICHAGE DYNAMIQUE --- */
        .risk-section { margin-bottom: 15px; border: 1px solid #000; background: #fff; page-break-inside: avoid !important; break-inside: avoid !important;}
        .risk-header { display: flex; justify-content: space-between; align-items: center; background: #e7e6e6; padding: 8px 15px; font-weight: 900; border-bottom: 1px solid #000; }
        
        /* Correction de l'alignement des boutons OUI/NON */
        .risk-toggles { display: flex; gap: 10px; }
        .risk-toggles label { 
            display: inline-flex; 
            align-items: center;  
            cursor: pointer; 
            font-size: 14px; 
            background: #fff; 
            padding: 4px 10px; 
            border-radius: 4px; 
            border: 1px solid #000; 
            font-weight: bold;
        }
        .risk-toggles input[type="radio"] { 
            margin: 0 6px 0 0; 
        }
        
        .risk-content { display: block; padding: 10px; background: #fff; }
        .risk-content table { margin-bottom: 0; }
        .hidden-block { display: none !important; }

        /* Mise en forme de la nouvelle checklist intégrée */
        .checklist-item {
            display: flex; 
            align-items: flex-start; 
            gap: 10px; 
            margin-bottom: 6px; 
            padding-bottom: 4px;
            border-bottom: 1px dashed #e2e8f0;
        }
        .checklist-item:last-child {
            border-bottom: none;
            margin-bottom: 0;
            padding-bottom: 0;
        }
        .checklist-boxes {
            white-space: nowrap; 
            flex-shrink: 0; 
            display: flex; 
            gap: 8px; 
            background: #f1f5f9; 
            padding: 2px 6px; 
            border-radius: 4px;
            border: 1px solid #cbd5e1;
        }
        .checklist-text {
            padding-top: 2px;
        }

        /* --- GESTION DES COUPURES DE PAGES (ANTI-COUPE) --- */
        .anti-coupe, tr {
            page-break-inside: avoid !important;
            break-inside: avoid !important;
        }

        /* --- STYLE D'IMPRESSION STRICT --- */
        @media print {
            @page { margin: 14mm 12mm; }
            body, html { margin: 0 !important; padding: 0 !important; background: #fff !important; font-size: 11px; }
            .a4-page { box-shadow: none !important; margin: 0 auto !important; }
            .action-bar { display: none !important; }

            /* Sécurité contre les blocs fantômes */
            .risk-section { border: 1px solid #000 !important; margin-bottom: 5px !important; }
            .risk-header { background: #e7e6e6 !important; border-bottom: 1px solid #000 !important; padding: 5px !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            .risk-content { padding: 5px !important; }

            /* Forcer la disparition réelle des blocs fermés */
            .hidden-block { display: none !important; height: 0 !important; padding: 0 !important; margin: 0 !important; overflow: hidden !important; visibility: hidden !important; }

            .select-db { -webkit-appearance: none; -moz-appearance: none; appearance: none; border: none; background: transparent; padding: 0; }
            .cell-input { -webkit-appearance: none; -moz-appearance: none; appearance: none; }
            .checklist-boxes { background: transparent !important; border: none !important; padding: 0 !important; }

            /* Couleurs garanties à l'impression */
            .green-title, .main-risk-title, .risk-content th { -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; }

            /* Saut de page réel : ces marqueurs ne servaient qu'au PDF (html2pdf.js),
               ils n'avaient aucun effet sur l'impression navigateur (window.print()) */
            .html2pdf__page-break {
                display: block !important;
                height: 0 !important;
                page-break-before: always !important;
                break-before: page !important;
            }

            /* Un bloc de risque OUVERT (OUI coché) ne doit jamais être coupé au
               milieu à l'impression : s'il ne tient pas dans l'espace restant
               sur la page en cours, on le bascule entièrement sur la page
               suivante plutôt que de laisser les cases se chevaucher sur deux
               pages. Chaque item, ligne et tableau à l'intérieur hérite de la
               même contrainte pour qu'aucune coupure ne puisse se produire
               n'importe où dans le bloc. */
            .risk-section,
            .risk-content,
            .risk-content table,
            .risk-content tr,
            .checklist-item {
                page-break-inside: avoid !important;
                break-inside: avoid !important;
            }
        }
    </style>
<?php include 'pwa_head.php'; ?>
</head>
<body>

<div class="action-bar">
    <button class="btn-action btn-print" onclick="window.print()"><i class="fa-solid fa-print"></i> 1. Imprimer / Enregistrer en PDF</button>
    <button class="btn-action btn-pdf" id="btn-upload-pdp" onclick="declencherChoixFichierPDF()"><i class="fa-solid fa-cloud-arrow-up"></i> 2. Envoyer le PDF au serveur</button>
    <input type="file" id="input-pdf-upload" accept="application/pdf,.pdf" style="display:none" onchange="envoyerPDFChoisi(this)">
</div>

<div class="a4-page" id="document-pdp">
    <input type="hidden" id="hidden-id-ee" value="<?php echo htmlspecialchars($id_ee_prefill); ?>">
    
    <table class="header-table anti-coupe" style="border-bottom: 3px solid #000; margin-bottom: 20px;">
        <tr>
            <td width="25%">
                <img src="<?php echo htmlspecialchars($logo_path_pdp); ?>" alt="Logo" style="height: 100px;">
            </td>
            <td width="45%" class="title-doc">
                PLAN DE PRÉVENTION
                <div id="affichage-num-pdp" style="color:#e74c3c !important; font-size:18px; margin-top:5px; letter-spacing: 1px; font-weight:900;"><?php echo htmlspecialchars($num_pdp_prefill); ?></div>
            </td>
            <td width="30%" style="text-align: right; padding-left: 10px;">
                <strong style="font-size: 11px; margin-top:5px; display:inline-block;">Entreprise Utilisatrice (EU) :</strong><br>
                <span class="text-black" style="font-size:13px;"><?php echo htmlspecialchars(strtoupper($nom_entreprise_pdp)); ?></span><br>
                <span id="date-auto" class="text-black"></span>
            </td>
        </tr>
    </table>

    <table class="anti-coupe">
        <tr>
            <td width="50%">
                <strong class="text-black" style="font-size: 16px;">[NOM DE VOTRE ENTREPRISE]</strong><br>
                [Adresse]<br>
                [Code postal] [Ville]<br>
                [Téléphone]
            </td>
            <td width="50%">
                <strong class="text-black">Représentée par :</strong><br>
                <span class="text-black">[Nom du Directeur]</span> (Directeur)<br>
                <span class="text-black">[Nom du Responsable Technique]</span> (Responsable Technique)<br>
                <span class="text-black">[Nom du Représentant Sécurité]</span> (Représentant Sécurité)<br>
                <span class="text-black">[Nom de l'Animatrice Sécurité]</span> (Animatrice sécurité)
            </td>
        </tr>
    </table>

    <div class="green-title">NATURE DE L’OPÉRATION</div>
    <table style="margin-top: 10px;">
        <tr>
            <th width="20%">Opération R 4511-4</th>
            <td width="25%"><input type="checkbox"> Opération ponctuelle < à 24h</td>
            <td width="35%"><input type="checkbox"> Annuelle : Opérations identiques et répétitives sur le même site</td>
            <td width="20%"><input type="checkbox"> Programmée</td>
        </tr>
        <tr>
            <th>Durée</th>
            <td colspan="3"><input type="checkbox"> Moins de 400 heures &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp; <input type="checkbox"> Plus de 400 heures</td>
        </tr>
        <tr>
            <th>Dangerosité</th>
            <td colspan="3"><input type="checkbox"> Travaux dangereux (*) &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp; <input type="checkbox"> Travaux non dangereux (*)</td>
        </tr>
    </table>
    
    <p class="alert-text">Le plan de prévention a pour but de réduire les risques liés à la coactivité et aux interférences résultant des activités de l’entreprise utilisatrice et de la/des entreprise(s) extérieure(s). Les mesures de prévention/protection décrites dans le mode opératoire ont pour but de supprimer la coactivité et les interférences.</p>

    <table class="anti-coupe">
        <tr><td colspan="2"><strong>Nom de l’entreprise extérieure =</strong> <input type="text" id="input-nom-ee" value="<?php echo htmlspecialchars($nom_ee_prefill); ?>" style="width:70%; border:none; border-bottom:1px dotted #000; font-family: inherit; font-weight:bold;"></td></tr>
        <tr>
            <td width="50%"><strong>Représenté par =</strong> <input type="text" style="width:60%; border:none; border-bottom:1px dotted #000; font-family: inherit; font-weight:bold;"></td>
            <td width="50%"><strong>En qualité de =</strong> <input type="text" style="width:60%; border:none; border-bottom:1px dotted #000; font-family: inherit; font-weight:bold;"></td>
        </tr>
    </table>

    <table class="anti-coupe">
        <tr><td colspan="2"><strong>Localisation de l’opération sur [votre entreprise] =</strong> <input type="text" value="<?php echo htmlspecialchars($usine_prefill); ?>" style="width:60%; border:none; border-bottom:1px dotted #000; font-family: inherit; font-weight:bold;"></td></tr>
        <tr><td colspan="2"><strong>Désignation de l’opération :</strong> <input type="text" value="<?php echo htmlspecialchars($equip_prefill); ?>" style="width:80%; border:none; border-bottom:1px dotted #000; font-family: inherit; font-weight:bold;"></td></tr>
        <tr>
            <td width="50%"><strong>Sous-traitant :</strong> Oui <input type="checkbox"> Non <input type="checkbox"></td>
            <td width="50%"><strong>Nombre d’entreprises extérieures :</strong> <input type="text" size="5" style="border:none; border-bottom:1px dotted #000; font-weight:bold;"></td>
        </tr>
        <tr>
            <td><strong>Effectif global prévu :</strong> <input type="text" size="10" style="border:none; border-bottom:1px dotted #000; font-weight:bold;"></td>
            <td><strong>Date prévue de début des travaux :</strong> <input type="text" size="10" placeholder="__/__/____" style="border:none; border-bottom:1px dotted #000; font-weight:bold; text-align:center;"> &nbsp;&nbsp; <strong>Durée prévisionnelle :</strong> Jusqu’au <input type="text" size="10" placeholder="__/__/____" style="border:none; border-bottom:1px dotted #000; font-weight:bold; text-align:center;"></td>
        </tr>
        <tr><td colspan="2"><strong>Horaires de travail :</strong> Du lundi au vendredi de 8h à 18h</td></tr>
    </table>

    <div class="anti-coupe" style="border: 2px solid #ff0000; padding: 15px; text-align: center; margin-bottom: 20px; background-color: #fff9f9;">
        <p class="alert-text" style="margin: 0;">
            Merci de vous inscrire dès votre arrivée et tous les jours dans le registre d’accueil situé dans le hall d’entrée administration ou logistique.<br>
            Veuillez penser à également noter votre heure de départ lorsque vous aurez terminé vos travaux.
        </p>
    </div>

    <div class="html2pdf__page-break"></div>

    <div class="green-title">Habilitations / autorisations nécessaires <span style="font-size:13px; font-weight:normal; text-transform:none;">(à mettre en annexe du PDP)</span></div>
    <table style="margin-top: 10px;">
        <tr><th width="40%"></th><th width="10%">EU</th><th width="10%">EE</th><th width="40%">Autorisation d’accès aux locaux</th></tr>
        <tr><td>CACES = _______</td><td><input type="checkbox"></td><td><input type="checkbox"></td><td><input type="checkbox"> WC</td></tr>
        <tr><td>Formation risque Légionelle</td><td><input type="checkbox"></td><td><input type="checkbox"></td><td><input type="checkbox"> Bureau</td></tr>
        <tr><td>Habilitation travail en hauteur</td><td><input type="checkbox"></td><td><input type="checkbox"></td><td><input type="checkbox"> Douche</td></tr>
        <tr><td>Habilitation électrique = _______</td><td><input type="checkbox"></td><td><input type="checkbox"></td><td><input type="checkbox"> Vestiaires</td></tr>
        <tr><td>Formation NH3</td><td><input type="checkbox"></td><td><input type="checkbox"></td><td><input type="checkbox"> Salle de pause</td></tr>
        <tr><td>Formation chaudière</td><td><input type="checkbox"></td><td><input type="checkbox"></td><td></td></tr>
        <tr><td>Autre : Soudeur, ESP, Echafaudage…</td><td><input type="checkbox"></td><td><input type="checkbox"></td><td></td></tr>
        <tr><td>VGP des appareils/accessoires de levage ( grue, élingues, palonnier, sangles, chariot, nacelle… )</td><td><input type="checkbox"></td><td><input type="checkbox"></td><td></td></tr>
        <tr><td>VGP des EPI (antichute, ARI, …)</td><td><input type="checkbox"></td><td><input type="checkbox"></td><td></td></tr>
        <tr><td>Fiche de données de sécurité</td><td><input type="checkbox"></td><td><input type="checkbox"></td><td></td></tr>
        <tr><td>Autres : _______</td><td><input type="checkbox"></td><td><input type="checkbox"></td><td></td></tr>
    </table>

    <div class="green-title">INSTRUCTIONS À DONNER PAR L’EU AU PERSONNEL DES EE</div>
    <table style="margin-top: 10px;">
        <tr>
            <td width="50%">
                <input type="checkbox"> Plan d’accès au site<br>
                <input type="checkbox"> Plan de circulation interne<br>
                <input type="checkbox"> Protocole de chargement/déchargement<br>
                <input type="checkbox"> Plan de localisation des dangers à restriction d’accès<br>
                <input type="checkbox"> Consigne incendie / NH3<br>
                <input type="checkbox"> Protocole en cas d’accidents<br>
                <input type="checkbox"> Protocole en cas de Légionelles TAR
            </td>
            <td width="50%">
                <input type="checkbox"> Prêt de matériel<br>
                <input type="checkbox"> Prêt de main d’œuvre entre EE autorisé (manutention)<br>
                <input type="checkbox"> Permis de feu<br>
                <input type="checkbox"> Consignes de sécurité spécifiques : <input type="text" style="width:40%; border:none; border-bottom:1px dotted #000; font-weight:bold;"><br>
                <input type="checkbox"> Autre : <input type="text" style="width:60%; border:none; border-bottom:1px dotted #000; font-weight:bold;">
            </td>
        </tr>
    </table>

    <div class="html2pdf__page-break"></div>
    <div class="main-risk-title">MODE OPÉRATOIRE R 4512-5 / ANALYSE DES RISQUES R 4512-6</div>
    <p class="alert-text anti-coupe" style="text-align:center; font-style:italic; margin-top:5px;">Les éléments marqués d’un * nécessitent une autorisation/habilitation</p>

    <div class="risk-section anti-coupe" id="section-circulation">
        <div class="risk-header">
            <span>Risques liés à la circulation interne</span>
            <span class="risk-toggles">
                <label><input type="radio" name="rad-circulation" value="oui" onclick="toggleRisk('content-circulation', true)"> OUI</label>
                <label><input type="radio" name="rad-circulation" value="non" onclick="toggleRisk('content-circulation', false)" checked> NON</label>
            </span>
        </div>
        <div class="risk-content hidden-block" id="content-circulation">
            <table>
                <tr><th width="20%">Phase(s) de travail dangereuse(s)</th><th width="25%">Risque(s)</th><th width="55%">Mesures de protection et de prévention</th></tr>
                <tr>
                    <td>Circulation dans l’entreprise</td>
                    <td>Collision véhicule légère, lourds et engins de manutention<br>Collision de piétons</td>
                    <td>
                        <div class="checklist-item">
                            <div class="checklist-boxes"><label><input type="checkbox"> <b>ARDO</b></label> <label><input type="checkbox"> <b style="color:#e74c3c;">EE</b></label></div>
                            <div class="checklist-text">Vitesse limitée à 20 km/h sur le site pour VL, PL, remorques…</div>
                        </div>
                        <div class="checklist-item">
                            <div class="checklist-boxes"><label><input type="checkbox"> <b>ARDO</b></label> <label><input type="checkbox"> <b style="color:#e74c3c;">EE</b></label></div>
                            <div class="checklist-text">Ceinture de sécurité obligatoire (y compris pour les engins de manutention)</div>
                        </div>
                        <div class="checklist-item">
                            <div class="checklist-boxes"><label><input type="checkbox"> <b>ARDO</b></label> <label><input type="checkbox"> <b style="color:#e74c3c;">EE</b></label></div>
                            <div class="checklist-text">Utiliser les voies de circulation piétonnes</div>
                        </div>
                        <div class="checklist-item">
                            <div class="checklist-boxes"><label><input type="checkbox"> <b>ARDO</b></label> <label><input type="checkbox"> <b style="color:#e74c3c;">EE</b></label></div>
                            <div class="checklist-text">Informer le personnel des risques interférents</div>
                        </div>
                        <div class="checklist-item">
                            <div class="checklist-boxes"><label><input type="checkbox"> <b>ARDO</b></label> <label><input type="checkbox"> <b style="color:#e74c3c;">EE</b></label></div>
                            <div class="checklist-text">Respect du plan de circulation</div>
                        </div>
                        <div class="checklist-item">
                            <div class="checklist-boxes"><label><input type="checkbox"> <b>ARDO</b></label> <label><input type="checkbox"> <b style="color:#e74c3c;">EE</b></label></div>
                            <div class="checklist-text">Stationnement des véhicules défini et respecté (se garer en marche arrière)</div>
                        </div>
                        <div class="checklist-item">
                            <div class="checklist-boxes"><label><input type="checkbox"> <b>ARDO</b></label> <label><input type="checkbox"> <b style="color:#e74c3c;">EE</b></label></div>
                            <div class="checklist-text">Port des EPI pour circuler : casque, chaussures de sécurité, gilet haute visibilité</div>
                        </div>
                    </td>
                </tr>
                <tr>
                    <td>Circulation dans l’entreprise</td>
                    <td>Trébuchement des personnes, chute de plain-pied</td>
                    <td>
                        <div class="checklist-item">
                            <div class="checklist-boxes"><label><input type="checkbox"> <b>ARDO</b></label> <label><input type="checkbox"> <b style="color:#e74c3c;">EE</b></label></div>
                            <div class="checklist-text">Ranger le matériel</div>
                        </div>
                        <div class="checklist-item">
                            <div class="checklist-boxes"><label><input type="checkbox"> <b>ARDO</b></label> <label><input type="checkbox"> <b style="color:#e74c3c;">EE</b></label></div>
                            <div class="checklist-text">Délimiter la zone de travail</div>
                        </div>
                        <div class="checklist-item">
                            <div class="checklist-boxes"><label><input type="checkbox"> <b>ARDO</b></label> <label><input type="checkbox"> <b style="color:#e74c3c;">EE</b></label></div>
                            <div class="checklist-text">Ne pas encombrer les zones de circulation</div>
                        </div>
                        <div class="checklist-item">
                            <div class="checklist-boxes"><label><input type="checkbox"> <b>ARDO</b></label> <label><input type="checkbox"> <b style="color:#e74c3c;">EE</b></label></div>
                            <div class="checklist-text">Tenir la rampe dans les escaliers</div>
                        </div>
                        <div class="checklist-item">
                            <div class="checklist-boxes"><label><input type="checkbox"> <b>ARDO</b></label> <label><input type="checkbox"> <b style="color:#e74c3c;">EE</b></label></div>
                            <div class="checklist-text">Rangement en fin de journée et en clôture de chantier</div>
                        </div>
                    </td>
                </tr>
            </table>
        </div>
    </div>

    <div class="risk-section anti-coupe" id="section-environnement">
        <div class="risk-header">
            <span>Risques liés à l’environnement de travail</span>
            <span class="risk-toggles">
                <label><input type="radio" name="rad-environnement" value="oui" onclick="toggleRisk('content-environnement', true)"> OUI</label>
                <label><input type="radio" name="rad-environnement" value="non" onclick="toggleRisk('content-environnement', false)" checked> NON</label>
            </span>
        </div>
        <div class="risk-content hidden-block" id="content-environnement">
            <table>
                <tr><th width="20%">Phase(s) de travail dangereuse(s)</th><th width="25%">Risque(s)</th><th width="55%">Mesures de protection et de prévention</th></tr>
                <tr>
                    <td>Condition de travail</td>
                    <td>Fatigue<br>Exposition au bruit<br>Travailleur Isolé<br>Températures négatives (-18 °C)<br>Non détection de dangers<br>Inhalation de poussière</td>
                    <td>
                        <div class="checklist-item">
                            <div class="checklist-boxes"><label><input type="checkbox"> <b>ARDO</b></label> <label><input type="checkbox"> <b style="color:#e74c3c;">EE</b></label></div>
                            <div class="checklist-text">Faire des pauses régulières (toutes les 2h)</div>
                        </div>
                        <div class="checklist-item">
                            <div class="checklist-boxes"><label><input type="checkbox"> <b>ARDO</b></label> <label><input type="checkbox"> <b style="color:#e74c3c;">EE</b></label></div>
                            <div class="checklist-text">Limiter le travail isolé au maximum (ex=combles) ou être accompagné par une personne de [votre entreprise]</div>
                        </div>
                        <div class="checklist-item">
                            <div class="checklist-boxes"><label><input type="checkbox"> <b>ARDO</b></label> <label><input type="checkbox"> <b style="color:#e74c3c;">EE</b></label></div>
                            <div class="checklist-text">Protection auditive en cas de travaux bruyants (+80Db)</div>
                        </div>
                        <div class="checklist-item">
                            <div class="checklist-boxes"><label><input type="checkbox"> <b>ARDO</b></label> <label><input type="checkbox"> <b style="color:#e74c3c;">EE</b></label></div>
                            <div class="checklist-text">Port de masques adaptés</div>
                        </div>
                        <div class="checklist-item">
                            <div class="checklist-boxes"><label><input type="checkbox"> <b>ARDO</b></label> <label><input type="checkbox"> <b style="color:#e74c3c;">EE</b></label></div>
                            <div class="checklist-text">Accès à l’atelier pour utiliser la hotte aspirante</div>
                        </div>
                        <div class="checklist-item">
                            <div class="checklist-boxes"><label><input type="checkbox"> <b>ARDO</b></label> <label><input type="checkbox"> <b style="color:#e74c3c;">EE</b></label></div>
                            <div class="checklist-text">Travail en extérieur</div>
                        </div>
                        <div class="checklist-item">
                            <div class="checklist-boxes"><label><input type="checkbox"> <b>ARDO</b></label> <label><input type="checkbox"> <b style="color:#e74c3c;">EE</b></label></div>
                            <div class="checklist-text">Vêtements de froid (cagoule, veste, gants, chaussures, …)</div>
                        </div>
                    </td>
                </tr>
            </table>
        </div>
    </div>

    <div class="risk-section anti-coupe" id="section-hauteur">
        <div class="risk-header">
            <span>Risques liés au travail en hauteur</span>
            <span class="risk-toggles">
                <label><input type="radio" name="rad-hauteur" value="oui" onclick="toggleRisk('content-hauteur', true)"> OUI</label>
                <label><input type="radio" name="rad-hauteur" value="non" onclick="toggleRisk('content-hauteur', false)" checked> NON</label>
            </span>
        </div>
        <div class="risk-content hidden-block" id="content-hauteur">
            <table>
                <tr><th width="20%">Phase(s) de travail dangereuse(s)</th><th width="25%">Risque(s)</th><th width="55%">Mesures de protection et de prévention</th></tr>
                <tr>
                    <td>Travaux en hauteur</td>
                    <td>Chute de hauteur<br>Chute d’objets</td>
                    <td>
                        <div class="checklist-item">
                            <div class="checklist-boxes"><label><input type="checkbox"> <b>ARDO</b></label> <label><input type="checkbox"> <b style="color:#e74c3c;">EE</b></label></div>
                            <div class="checklist-text">* Echafaudage</div>
                        </div>
                        <div class="checklist-item">
                            <div class="checklist-boxes"><label><input type="checkbox"> <b>ARDO</b></label> <label><input type="checkbox"> <b style="color:#e74c3c;">EE</b></label></div>
                            <div class="checklist-text">* Nacelle = port du harnais et de la longe de maintien (absorbeurs interdits) + casque avec jugulaire</div>
                        </div>
                        <div class="checklist-item">
                            <div class="checklist-boxes"><label><input type="checkbox"> <b>ARDO</b></label> <label><input type="checkbox"> <b style="color:#e74c3c;">EE</b></label></div>
                            <div class="checklist-text">Plateforme individuelle roulante (PIR)</div>
                        </div>
                        <div class="checklist-item">
                            <div class="checklist-boxes"><label><input type="checkbox"> <b>ARDO</b></label> <label><input type="checkbox"> <b style="color:#e74c3c;">EE</b></label></div>
                            <div class="checklist-text">* Ligne de vie, port du harnais et de la longe dans la nacelle</div>
                        </div>
                        <div class="checklist-item">
                            <div class="checklist-boxes"><label><input type="checkbox"> <b>ARDO</b></label> <label><input type="checkbox"> <b style="color:#e74c3c;">EE</b></label></div>
                            <div class="checklist-text">Pose de garde-corps</div>
                        </div>
                        <div class="checklist-item">
                            <div class="checklist-boxes"><label><input type="checkbox"> <b>ARDO</b></label> <label><input type="checkbox"> <b style="color:#e74c3c;">EE</b></label></div>
                            <div class="checklist-text">* Pose de filets</div>
                        </div>
                        <div class="checklist-item">
                            <div class="checklist-boxes"><label><input type="checkbox"> <b>ARDO</b></label> <label><input type="checkbox"> <b style="color:#e74c3c;">EE</b></label></div>
                            <div class="checklist-text">Echelles uniquement en cas d’impossibilité d’accès, échelle attachée en haut, port du harnais * et de la longe de maintien</div>
                        </div>
                        <div class="checklist-item">
                            <div class="checklist-boxes"><label><input type="checkbox"> <b>ARDO</b></label> <label><input type="checkbox"> <b style="color:#e74c3c;">EE</b></label></div>
                            <div class="checklist-text">Pour les travaux dans les combles : 90 kg maximum par panneau sandwich</div>
                        </div>
                        <div class="checklist-item">
                            <div class="checklist-boxes"><label><input type="checkbox"> <b>ARDO</b></label> <label><input type="checkbox"> <b style="color:#e74c3c;">EE</b></label></div>
                            <div class="checklist-text">Prévoir des passerelles adaptées</div>
                        </div>
                        <div class="checklist-item">
                            <div class="checklist-boxes"><label><input type="checkbox"> <b>ARDO</b></label> <label><input type="checkbox"> <b style="color:#e74c3c;">EE</b></label></div>
                            <div class="checklist-text">Balisage au sol et derrière les portes si travail à proximité d’une voie d’accès</div>
                        </div>
                        <div class="checklist-item">
                            <div class="checklist-boxes"><label><input type="checkbox"> <b>ARDO</b></label> <label><input type="checkbox"> <b style="color:#e74c3c;">EE</b></label></div>
                            <div class="checklist-text">Interdire les travaux superposés</div>
                        </div>
                        <div class="checklist-item">
                            <div class="checklist-boxes"><label><input type="checkbox"> <b>ARDO</b></label> <label><input type="checkbox"> <b style="color:#e74c3c;">EE</b></label></div>
                            <div class="checklist-text">Vigie au sol</div>
                        </div>
                    </td>
                </tr>
            </table>
        </div>
    </div>

    <div class="risk-section anti-coupe" id="section-machines">
        <div class="risk-header">
            <span>Risques liés aux machines/équipements</span>
            <span class="risk-toggles">
                <label><input type="radio" name="rad-machines" value="oui" onclick="toggleRisk('content-machines', true)"> OUI</label>
                <label><input type="radio" name="rad-machines" value="non" onclick="toggleRisk('content-machines', false)" checked> NON</label>
            </span>
        </div>
        <div class="risk-content hidden-block" id="content-machines">
            <table>
                <tr><th width="20%">Phase(s) de travail dangereuse(s)</th><th width="25%">Risque(s)</th><th width="55%">Mesures de protection et de prévention</th></tr>
                <tr>
                    <td>Travaux par points chaud</td>
                    <td>Projections<br>Objets tranchants<br>Objets brûlants, froids<br>Dégradation de la vision</td>
                    <td>
                        <div class="checklist-item">
                            <div class="checklist-boxes"><label><input type="checkbox"> <b>ARDO</b></label> <label><input type="checkbox"> <b style="color:#e74c3c;">EE</b></label></div>
                            <div class="checklist-text">Lunettes, écran facial</div>
                        </div>
                        <div class="checklist-item">
                            <div class="checklist-boxes"><label><input type="checkbox"> <b>ARDO</b></label> <label><input type="checkbox"> <b style="color:#e74c3c;">EE</b></label></div>
                            <div class="checklist-text">Masque de soudeur</div>
                        </div>
                        <div class="checklist-item">
                            <div class="checklist-boxes"><label><input type="checkbox"> <b>ARDO</b></label> <label><input type="checkbox"> <b style="color:#e74c3c;">EE</b></label></div>
                            <div class="checklist-text">Balisage de la zone</div>
                        </div>
                        <div class="checklist-item">
                            <div class="checklist-boxes"><label><input type="checkbox"> <b>ARDO</b></label> <label><input type="checkbox"> <b style="color:#e74c3c;">EE</b></label></div>
                            <div class="checklist-text">Vêtements ignifugés</div>
                        </div>
                        <div class="checklist-item">
                            <div class="checklist-boxes"><label><input type="checkbox"> <b>ARDO</b></label> <label><input type="checkbox"> <b style="color:#e74c3c;">EE</b></label></div>
                            <div class="checklist-text">Bâche ignifugée</div>
                        </div>
                        <div class="checklist-item">
                            <div class="checklist-boxes"><label><input type="checkbox"> <b>ARDO</b></label> <label><input type="checkbox"> <b style="color:#e74c3c;">EE</b></label></div>
                            <div class="checklist-text">Gants anti-coupure</div>
                        </div>
                        <div class="checklist-item">
                            <div class="checklist-boxes"><label><input type="checkbox"> <b>ARDO</b></label> <label><input type="checkbox"> <b style="color:#e74c3c;">EE</b></label></div>
                            <div class="checklist-text">Gants anti-chaleur</div>
                        </div>
                        <div class="checklist-item">
                            <div class="checklist-boxes"><label><input type="checkbox"> <b>ARDO</b></label> <label><input type="checkbox"> <b style="color:#e74c3c;">EE</b></label></div>
                            <div class="checklist-text">Gants froid</div>
                        </div>
                    </td>
                </tr>
                <tr>
                    <td>Exposition à des éléments en mouvement</td>
                    <td>Heurt<br>Écrasement</td>
                    <td>
                        <div class="checklist-item">
                            <div class="checklist-boxes"><label><input type="checkbox"> <b>ARDO</b></label> <label><input type="checkbox"> <b style="color:#e74c3c;">EE</b></label></div>
                            <div class="checklist-text">Interdiction d’intervenir sur des organes en mouvement</div>
                        </div>
                        <div class="checklist-item">
                            <div class="checklist-boxes"><label><input type="checkbox"> <b>ARDO</b></label> <label><input type="checkbox"> <b style="color:#e74c3c;">EE</b></label></div>
                            <div class="checklist-text">Présence d’arrêts d’urgence : A repérer</div>
                        </div>
                        <div class="checklist-item">
                            <div class="checklist-boxes"><label><input type="checkbox"> <b>ARDO</b></label> <label><input type="checkbox"> <b style="color:#e74c3c;">EE</b></label></div>
                            <div class="checklist-text">Carters de protection en place</div>
                        </div>
                        <div class="checklist-item">
                            <div class="checklist-boxes"><label><input type="checkbox"> <b>ARDO</b></label> <label><input type="checkbox"> <b style="color:#e74c3c;">EE</b></label></div>
                            <div class="checklist-text">Balisage et interdiction de passage dans les zones dangereuses</div>
                        </div>
                    </td>
                </tr>
            </table>
        </div>
    </div>

    <div class="risk-section anti-coupe" id="section-incendie">
        <div class="risk-header">
            <span>Risques incendie/explosion</span>
            <span class="risk-toggles">
                <label><input type="radio" name="rad-incendie" value="oui" onclick="toggleRisk('content-incendie', true)"> OUI</label>
                <label><input type="radio" name="rad-incendie" value="non" onclick="toggleRisk('content-incendie', false)" checked> NON</label>
            </span>
        </div>
        <div class="risk-content hidden-block" id="content-incendie">
            <table>
                <tr><th width="20%">Phase(s) de travail dangereuse(s)</th><th width="25%">Risque(s)</th><th width="55%">Mesures de protection et de prévention</th></tr>
                <tr>
                    <td>Travaux par points chaud</td>
                    <td>Départ de feu/ explosion</td>
                    <td>
                        <div class="checklist-item">
                            <div class="checklist-boxes"><label><input type="checkbox"> <b>ARDO</b></label> <label><input type="checkbox"> <b style="color:#e74c3c;">EE</b></label></div>
                            <div class="checklist-text">* Permis feu HEBDOMADAIRE</div>
                        </div>
                        <div class="checklist-item">
                            <div class="checklist-boxes"><label><input type="checkbox"> <b>ARDO</b></label> <label><input type="checkbox"> <b style="color:#e74c3c;">EE</b></label></div>
                            <div class="checklist-text">Privilégier la préparation des pièces en atelier ou en extérieur</div>
                        </div>
                        <div class="checklist-item">
                            <div class="checklist-boxes"><label><input type="checkbox"> <b>ARDO</b></label> <label><input type="checkbox"> <b style="color:#e74c3c;">EE</b></label></div>
                            <div class="checklist-text">S'assurer que les conditions de travail sont adaptées à la soudure</div>
                        </div>
                        <div class="checklist-item">
                            <div class="checklist-boxes"><label><input type="checkbox"> <b>ARDO</b></label> <label><input type="checkbox"> <b style="color:#e74c3c;">EE</b></label></div>
                            <div class="checklist-text">Mettre à disposition des dispositifs de lutte contre le feu</div>
                        </div>
                        <div class="checklist-item">
                            <div class="checklist-boxes"><label><input type="checkbox"> <b>ARDO</b></label> <label><input type="checkbox"> <b style="color:#e74c3c;">EE</b></label></div>
                            <div class="checklist-text">Arrêts des travaux 1 heure avant la fin des travaux (16h30 maximum)</div>
                        </div>
                        <div class="checklist-item">
                            <div class="checklist-boxes"><label><input type="checkbox"> <b>ARDO</b></label> <label><input type="checkbox"> <b style="color:#e74c3c;">EE</b></label></div>
                            <div class="checklist-text">Surveillances</div>
                        </div>
                        <div class="checklist-item">
                            <div class="checklist-boxes"><label><input type="checkbox"> <b>ARDO</b></label> <label><input type="checkbox"> <b style="color:#e74c3c;">EE</b></label></div>
                            <div class="checklist-text">Mise Hors Service en durée limitée de la zone de détection incendie</div>
                        </div>
                    </td>
                </tr>
            </table>
        </div>
    </div>

    <div class="risk-section anti-coupe" id="section-levage">
        <div class="risk-header">
            <span>Risques liés à manutention et levage</span>
            <span class="risk-toggles">
                <label><input type="radio" name="rad-levage" value="oui" onclick="toggleRisk('content-levage', true)"> OUI</label>
                <label><input type="radio" name="rad-levage" value="non" onclick="toggleRisk('content-levage', false)" checked> NON</label>
            </span>
        </div>
        <div class="risk-content hidden-block" id="content-levage">
            <table>
                <tr><th width="20%">Phase(s) de travail dangereuse(s)</th><th width="25%">Risque(s)</th><th width="55%">Mesures de protection et de prévention</th></tr>
                <tr>
                    <td>Conduite d’engin de manutention</td>
                    <td>Collision<br>Renversement engin de manutention<br>Chute d’objets</td>
                    <td>
                        <div class="checklist-item">
                            <div class="checklist-boxes"><label><input type="checkbox"> <b>ARDO</b></label> <label><input type="checkbox"> <b style="color:#e74c3c;">EE</b></label></div>
                            <div class="checklist-text">Balisage de la zone d’intervention</div>
                        </div>
                        <div class="checklist-item">
                            <div class="checklist-boxes"><label><input type="checkbox"> <b>ARDO</b></label> <label><input type="checkbox"> <b style="color:#e74c3c;">EE</b></label></div>
                            <div class="checklist-text">Signaler la topographie du terrain + risques de la zone</div>
                        </div>
                        <div class="checklist-item">
                            <div class="checklist-boxes"><label><input type="checkbox"> <b>ARDO</b></label> <label><input type="checkbox"> <b style="color:#e74c3c;">EE</b></label></div>
                            <div class="checklist-text">S’assurer de la conformité des appareils (VGP, carnet de maintenance)</div>
                        </div>
                        <div class="checklist-item">
                            <div class="checklist-boxes"><label><input type="checkbox"> <b>ARDO</b></label> <label><input type="checkbox"> <b style="color:#e74c3c;">EE</b></label></div>
                            <div class="checklist-text">Respect des règles de bonne conduite</div>
                        </div>
                        <div class="checklist-item">
                            <div class="checklist-boxes"><label><input type="checkbox"> <b>ARDO</b></label> <label><input type="checkbox"> <b style="color:#e74c3c;">EE</b></label></div>
                            <div class="checklist-text">*S’assurer que les conducteurs ont reçu une formation adaptée</div>
                        </div>
                        <div class="checklist-item">
                            <div class="checklist-boxes"><label><input type="checkbox"> <b>ARDO</b></label> <label><input type="checkbox"> <b style="color:#e74c3c;">EE</b></label></div>
                            <div class="checklist-text">Respect des consignes d’utilisation et consignes du constructeur</div>
                        </div>
                        <div class="checklist-item">
                            <div class="checklist-boxes"><label><input type="checkbox"> <b>ARDO</b></label> <label><input type="checkbox"> <b style="color:#e74c3c;">EE</b></label></div>
                            <div class="checklist-text">Veiller aux opérations de manutention</div>
                        </div>

                        <div style="margin-top: 15px; margin-bottom: 5px; font-weight: bold;">Matériel de manutention conforme (VGP à jour, NC levées) :</div>

                        <div class="checklist-item">
                            <div class="checklist-boxes"><label><input type="checkbox"> <b>ARDO</b></label> <label><input type="checkbox"> <b style="color:#e74c3c;">EE</b></label></div>
                            <div class="checklist-text">* Chariots élévateurs (6 mois)</div>
                        </div>
                        <div class="checklist-item">
                            <div class="checklist-boxes"><label><input type="checkbox"> <b>ARDO</b></label> <label><input type="checkbox"> <b style="color:#e74c3c;">EE</b></label></div>
                            <div class="checklist-text">* Grues (6 ou 12 mois selon type)</div>
                        </div>
                        <div class="checklist-item">
                            <div class="checklist-boxes"><label><input type="checkbox"> <b>ARDO</b></label> <label><input type="checkbox"> <b style="color:#e74c3c;">EE</b></label></div>
                            <div class="checklist-text">* Élingues (12 mois)</div>
                        </div>
                        <div class="checklist-item">
                            <div class="checklist-boxes"><label><input type="checkbox"> <b>ARDO</b></label> <label><input type="checkbox"> <b style="color:#e74c3c;">EE</b></label></div>
                            <div class="checklist-text">* Pont roulant (12 mois)</div>
                        </div>
                        <div class="checklist-item">
                            <div class="checklist-boxes"><label><input type="checkbox"> <b>ARDO</b></label> <label><input type="checkbox"> <b style="color:#e74c3c;">EE</b></label></div>
                            <div class="checklist-text">* Palan sur monorail, à bras (12 mois/6 mois)</div>
                        </div>
                        <div class="checklist-item">
                            <div class="checklist-boxes"><label><input type="checkbox"> <b>ARDO</b></label> <label><input type="checkbox"> <b style="color:#e74c3c;">EE</b></label></div>
                            <div class="checklist-text">Cric hydraulique, mécanique (12 mois)</div>
                        </div>

                        <div style="margin-top: 15px;">
                            ENGIN CONCERNE = <input type="text" style="width:50%; border:none; border-bottom:1px dotted #000; font-weight:bold;">
                        </div>
                    </td>
                </tr>
                <tr>
                    <td>Prêt d’engin de manutention</td>
                    <td></td>
                    <td>
                        <div class="checklist-item">
                            <div class="checklist-boxes"><label><input type="checkbox"> <b>ARDO</b></label> <label><input type="checkbox"> <b style="color:#e74c3c;">EE</b></label></div>
                            <div class="checklist-text">S'assurer de la conformité des appareils (VGP, carnet de maintenance)</div>
                        </div>
                        <div class="checklist-item">
                            <div class="checklist-boxes"><label><input type="checkbox"> <b>ARDO</b></label> <label><input type="checkbox"> <b style="color:#e74c3c;">EE</b></label></div>
                            <div class="checklist-text">S'assurer que les conducteurs ont reçu une formation adaptée</div>
                        </div>
                        <div class="checklist-item">
                            <div class="checklist-boxes"><label><input type="checkbox"> <b>ARDO</b></label> <label><input type="checkbox"> <b style="color:#e74c3c;">EE</b></label></div>
                            <div class="checklist-text">Respect des consignes d'utilisation et consignes du constructeur</div>
                        </div>
                        <div style="margin-top: 15px;">
                            ENGIN EMPRUNTE = <input type="text" style="width:50%; border:none; border-bottom:1px dotted #000; font-weight:bold;">
                        </div>
                    </td>
                </tr>
            </table>
        </div>
    </div>

    <div class="risk-section anti-coupe" id="section-manuelle">
        <div class="risk-header">
            <span>Risques liés à manutention manuelle</span>
            <span class="risk-toggles">
                <label><input type="radio" name="rad-manuelle" value="oui" onclick="toggleRisk('content-manuelle', true)"> OUI</label>
                <label><input type="radio" name="rad-manuelle" value="non" onclick="toggleRisk('content-manuelle', false)" checked> NON</label>
            </span>
        </div>
        <div class="risk-content hidden-block" id="content-manuelle">
            <table>
                <tr><th width="20%">Phase(s) de travail dangereuse(s)</th><th width="25%">Risque(s)</th><th width="55%">Mesures de protection et de prévention</th></tr>
                <tr>
                    <td>Déplacement de charges</td>
                    <td>Lésions dorsales<br>Lésions corporelles<br>Coupure</td>
                    <td>
                        <div class="checklist-item">
                            <div class="checklist-boxes"><label><input type="checkbox"> <b>ARDO</b></label> <label><input type="checkbox"> <b style="color:#e74c3c;">EE</b></label></div>
                            <div class="checklist-text">Port de charge limité à 25 kg (+ si reconnu apte)</div>
                        </div>
                        <div class="checklist-item">
                            <div class="checklist-boxes"><label><input type="checkbox"> <b>ARDO</b></label> <label><input type="checkbox"> <b style="color:#e74c3c;">EE</b></label></div>
                            <div class="checklist-text">Formation gestes et postures (conseillé)</div>
                        </div>
                        <div class="checklist-item">
                            <div class="checklist-boxes"><label><input type="checkbox"> <b>ARDO</b></label> <label><input type="checkbox"> <b style="color:#e74c3c;">EE</b></label></div>
                            <div class="checklist-text">Formations TMS</div>
                        </div>
                        <div class="checklist-item">
                            <div class="checklist-boxes"><label><input type="checkbox"> <b>ARDO</b></label> <label><input type="checkbox"> <b style="color:#e74c3c;">EE</b></label></div>
                            <div class="checklist-text">Port d’EPI adaptés à la manutention (gants anti-coupure, chaussures de sécurité)</div>
                        </div>
                        <div class="checklist-item">
                            <div class="checklist-boxes"><label><input type="checkbox"> <b>ARDO</b></label> <label><input type="checkbox"> <b style="color:#e74c3c;">EE</b></label></div>
                            <div class="checklist-text">Prêt de transpalettes manuels</div>
                        </div>
                        <div class="checklist-item">
                            <div class="checklist-boxes"><label><input type="checkbox"> <b>ARDO</b></label> <label><input type="checkbox"> <b style="color:#e74c3c;">EE</b></label></div>
                            <div class="checklist-text">Étirement avant le début des travaux</div>
                        </div>
                        <div class="checklist-item">
                            <div class="checklist-boxes"><label><input type="checkbox"> <b>ARDO</b></label> <label><input type="checkbox"> <b style="color:#e74c3c;">EE</b></label></div>
                            <div class="checklist-text">Fournir de l’aide d’un cariste</div>
                        </div>
                    </td>
                </tr>
            </table>
        </div>
    </div>

    <div class="risk-section anti-coupe" id="section-electrique">
        <div class="risk-header">
            <span>Risques électriques</span>
            <span class="risk-toggles">
                <label><input type="radio" name="rad-electrique" value="oui" onclick="toggleRisk('content-electrique', true)"> OUI</label>
                <label><input type="radio" name="rad-electrique" value="non" onclick="toggleRisk('content-electrique', false)" checked> NON</label>
            </span>
        </div>
        <div class="risk-content hidden-block" id="content-electrique">
            <table>
                <tr><th width="20%">Phase(s) de travail dangereuse(s)</th><th width="25%">Risque(s)</th><th width="55%">Mesures de protection et de prévention</th></tr>
                <tr>
                    <td>Appareil sous tension<br>PNST</td>
                    <td>Électrocution<br>Explosion<br>Incendie</td>
                    <td>
                        <div class="checklist-item">
                            <div class="checklist-boxes"><label><input type="checkbox"> <b>ARDO</b></label> <label><input type="checkbox"> <b style="color:#e74c3c;">EE</b></label></div>
                            <div class="checklist-text">S’assurer que le personnel a reçu des formations adaptées</div>
                        </div>
                        <div class="checklist-item">
                            <div class="checklist-boxes"><label><input type="checkbox"> <b>ARDO</b></label> <label><input type="checkbox"> <b style="color:#e74c3c;">EE</b></label></div>
                            <div class="checklist-text">Consignation électrique</div>
                        </div>
                        <div class="checklist-item">
                            <div class="checklist-boxes"><label><input type="checkbox"> <b>ARDO</b></label> <label><input type="checkbox"> <b style="color:#e74c3c;">EE</b></label></div>
                            <div class="checklist-text">Bon de consignation</div>
                        </div>
                        <div class="checklist-item">
                            <div class="checklist-boxes"><label><input type="checkbox"> <b>ARDO</b></label> <label><input type="checkbox"> <b style="color:#e74c3c;">EE</b></label></div>
                            <div class="checklist-text">Travaux hors tension obligatoire</div>
                        </div>
                        <div class="checklist-item">
                            <div class="checklist-boxes"><label><input type="checkbox"> <b>ARDO</b></label> <label><input type="checkbox"> <b style="color:#e74c3c;">EE</b></label></div>
                            <div class="checklist-text">VAT</div>
                        </div>
                        <div class="checklist-item">
                            <div class="checklist-boxes"><label><input type="checkbox"> <b>ARDO</b></label> <label><input type="checkbox"> <b style="color:#e74c3c;">EE</b></label></div>
                            <div class="checklist-text">S’assurer de la conformité des installations</div>
                        </div>
                        <div class="checklist-item">
                            <div class="checklist-boxes"><label><input type="checkbox"> <b>ARDO</b></label> <label><input type="checkbox"> <b style="color:#e74c3c;">EE</b></label></div>
                            <div class="checklist-text">Informer des PNST</div>
                        </div>
                        <div class="checklist-item">
                            <div class="checklist-boxes"><label><input type="checkbox"> <b>ARDO</b></label> <label><input type="checkbox"> <b style="color:#e74c3c;">EE</b></label></div>
                            <div class="checklist-text">Utilisations EPI adaptées : gants isolants, visière, tapis isolant</div>
                        </div>
                        <div class="checklist-item">
                            <div class="checklist-boxes"><label><input type="checkbox"> <b>ARDO</b></label> <label><input type="checkbox"> <b style="color:#e74c3c;">EE</b></label></div>
                            <div class="checklist-text">Balisage</div>
                        </div>
                        <div class="checklist-item">
                            <div class="checklist-boxes"><label><input type="checkbox"> <b>ARDO</b></label> <label><input type="checkbox"> <b style="color:#e74c3c;">EE</b></label></div>
                            <div class="checklist-text">Travaux à proximité de réseaux souterrains (eau, gaz, électricité…)</div>
                        </div>
                    </td>
                </tr>
            </table>
        </div>
    </div>

    <div class="risk-section anti-coupe" id="section-chimique">
        <div class="risk-header">
            <span>Risques Chimiques</span>
            <span class="risk-toggles">
                <label><input type="radio" name="rad-chimique" value="oui" onclick="toggleRisk('content-chimique', true)"> OUI</label>
                <label><input type="radio" name="rad-chimique" value="non" onclick="toggleRisk('content-chimique', false)" checked> NON</label>
            </span>
        </div>
        <div class="risk-content hidden-block" id="content-chimique">
            <table>
                <tr><th width="20%">Phase(s) de travail dangereuse(s)</th><th width="25%">Risque(s)</th><th width="55%">Mesures de protection et de prévention</th></tr>
                <tr>
                    <td>Manipulation et utilisation de substances dangereuses<br>Accès SDM</td>
                    <td>Brûlure chimique, irritation<br>Incendie<br>CMR<br>Projection<br>Mélange incompatible<br>Fuite NH3 / CO2</td>
                    <td>
                        <div class="checklist-item">
                            <div class="checklist-boxes"><label><input type="checkbox"> <b>ARDO</b></label> <label><input type="checkbox"> <b style="color:#e74c3c;">EE</b></label></div>
                            <div class="checklist-text">Port des EPI en adéquation avec les produits utilisés</div>
                        </div>
                        <div class="checklist-item">
                            <div class="checklist-boxes"><label><input type="checkbox"> <b>ARDO</b></label> <label><input type="checkbox"> <b style="color:#e74c3c;">EE</b></label></div>
                            <div class="checklist-text">Balisage de la zone</div>
                        </div>
                        <div class="checklist-item">
                            <div class="checklist-boxes"><label><input type="checkbox"> <b>ARDO</b></label> <label><input type="checkbox"> <b style="color:#e74c3c;">EE</b></label></div>
                            <div class="checklist-text">FDS / FT</div>
                        </div>
                        <div class="checklist-item">
                            <div class="checklist-boxes"><label><input type="checkbox"> <b>ARDO</b></label> <label><input type="checkbox"> <b style="color:#e74c3c;">EE</b></label></div>
                            <div class="checklist-text">Respecter la compatibilité au stockage (interdire acide-base)</div>
                        </div>
                        <div class="checklist-item">
                            <div class="checklist-boxes"><label><input type="checkbox"> <b>ARDO</b></label> <label><input type="checkbox"> <b style="color:#e74c3c;">EE</b></label></div>
                            <div class="checklist-text">Les produits chimiques identifiés et stockés sur bacs de rétention</div>
                        </div>
                        <div class="checklist-item">
                            <div class="checklist-boxes"><label><input type="checkbox"> <b>ARDO</b></label> <label><input type="checkbox"> <b style="color:#e74c3c;">EE</b></label></div>
                            <div class="checklist-text">Stockage/transport de bidon fermés (seaux interdits)</div>
                        </div>
                        <div class="checklist-item">
                            <div class="checklist-boxes"><label><input type="checkbox"> <b>ARDO</b></label> <label><input type="checkbox"> <b style="color:#e74c3c;">EE</b></label></div>
                            <div class="checklist-text">Absorbants minéraux</div>
                        </div>
                        <div class="checklist-item">
                            <div class="checklist-boxes"><label><input type="checkbox"> <b>ARDO</b></label> <label><input type="checkbox"> <b style="color:#e74c3c;">EE</b></label></div>
                            <div class="checklist-text">*Formation NH3 / CO2</div>
                        </div>
                        <div class="checklist-item">
                            <div class="checklist-boxes"><label><input type="checkbox"> <b>ARDO</b></label> <label><input type="checkbox"> <b style="color:#e74c3c;">EE</b></label></div>
                            <div class="checklist-text">Procédure en cas d'alerte NH3/CO2 + lever le doute</div>
                        </div>
                        <div class="checklist-item">
                            <div class="checklist-boxes"><label><input type="checkbox"> <b>ARDO</b></label> <label><input type="checkbox"> <b style="color:#e74c3c;">EE</b></label></div>
                            <div class="checklist-text">Présence de douche de sécurité + lave œil</div>
                        </div>
                    </td>
                </tr>
            </table>
        </div>
    </div>

    <div class="risk-section anti-coupe" id="section-biologique">
        <div class="risk-header">
            <span>Risques Biologiques</span>
            <span class="risk-toggles">
                <label><input type="radio" name="rad-biologique" value="oui" onclick="toggleRisk('content-biologique', true)"> OUI</label>
                <label><input type="radio" name="rad-biologique" value="non" onclick="toggleRisk('content-biologique', false)" checked> NON</label>
            </span>
        </div>
        <div class="risk-content hidden-block" id="content-biologique">
            <table>
                <tr><th width="20%">Phase(s) de travail dangereuse(s)</th><th width="25%">Risque(s)</th><th width="55%">Mesures de protection et de prévention</th></tr>
                <tr>
                    <td>TAR<br>Légionelle</td>
                    <td>Inhalation de bactéries</td>
                    <td>
                        <div class="checklist-item">
                            <div class="checklist-boxes"><label><input type="checkbox"> <b>ARDO</b></label> <label><input type="checkbox"> <b style="color:#e74c3c;">EE</b></label></div>
                            <div class="checklist-text">Accès limité et signalé</div>
                        </div>
                        <div class="checklist-item">
                            <div class="checklist-boxes"><label><input type="checkbox"> <b>ARDO</b></label> <label><input type="checkbox"> <b style="color:#e74c3c;">EE</b></label></div>
                            <div class="checklist-text">Port de masques adaptés FFP3</div>
                        </div>
                        <div class="checklist-item">
                            <div class="checklist-boxes"><label><input type="checkbox"> <b>ARDO</b></label> <label><input type="checkbox"> <b style="color:#e74c3c;">EE</b></label></div>
                            <div class="checklist-text">*Formation adaptée et suivi médical</div>
                        </div>
                    </td>
                </tr>
            </table>
        </div>
    </div>


    <div class="html2pdf__page-break"></div>
    <div class="green-title" style="margin-top:20px;">CONSIGNES EN CAS D’ACCIDENT</div>
    <div class="anti-coupe" style="margin-top: 15px;">
        <img src="img/logo_sst.png" alt="Logo SST" class="img-float-left" style="height: 80px;">
        <p><span class="alert-text">En cas d’accident grave ou bénin, vous devez immédiatement le signaler auprès de votre responsable ainsi qu’au responsable travaux de [votre entreprise] ([Nom] [Téléphone]).</span><br>
        Sur le chantier, vous devez obligatoirement posséder un Sauveteur Secouriste du Travail (SST) ainsi qu’une trousse de pharmacie complète. Chaque opérateur doit avoir connaissance de l’identité du sauveteur ainsi que la localisation de la trousse de secours.<br>
        [votre entreprise] met à disposition des douches de secours et rince œil (retrouvable à l’entrée de chaque salle des machines) et un défibrillateur (à côté de la pointeuse du personnel de [votre entreprise]). Nous possédons également des sauveteurs en cas de force majeure (voir liste au-dessus de la badgeuse et aux entrées).<br>
        [votre entreprise] ne possède pas d’infirmeries.</p>
    </div>
    
    <table class="anti-coupe" style="width:100%; border: 2px solid #000; margin-top: 20px;">
        <tr>
            <th style="background:#e7e6e6;">SAMU</th><td style="font-weight:900; font-size:16px;">15</td>
            <th style="background:#e7e6e6;">Police</th><td style="font-weight:900; font-size:16px;">17</td>
            <th style="background:#e7e6e6;">Pompiers</th><td style="font-weight:900; font-size:16px;">18</td>
        </tr>
        <tr>
            <th style="background:#e7e6e6;">Appel d’urgence</th><td style="font-weight:900;">112</td>
            <th style="background:#e7e6e6;">Centre Antipoison</th><td style="font-weight:900;">04.75.53.40.00</td>
            <th style="background:#e7e6e6;">Urgence électricité</th><td style="font-weight:900;">09.69.32.15.15</td>
        </tr>
        <tr>
            <th style="background:#e7e6e6;">Sourds et malentendants</th><td style="font-weight:900;">114</td>
            <th style="background:#e7e6e6;">SOS mains et doigts</th><td style="font-weight:900;">04.75.00.35.00</td>
            <th style="background:#e7e6e6;">Médecine du travail</th><td style="font-weight:900;">04.75.92.21.88</td>
        </tr>
        <tr>
            <th style="background:#e7e6e6;">Défenseur des droits</th><td style="font-weight:900;">09.69.39.00.00</td>
            <th style="background:#e7e6e6;">Inspection du travail</th><td style="font-weight:900;" colspan="3">04.26.52.68.00</td>
        </tr>
    </table>

    <div class="green-title anti-coupe">CONSIGNES INCENDIE</div>
    <div class="anti-coupe">
        <p class="alert-text">Avant-propos = merci de vous référencer dans le livret d’accueil et de vérifier que votre permis feu est à jour avant de commencer les travaux. Interdiction de fumer sur le site sauf aux abris fumeur.</p>
        <p><img src="img/logo_extincteur.png" class="img-inline" style="height:40px;"><img src="img/logo_alarme.png" class="img-inline" style="height:40px;"> <strong>En cas de départ incendie :</strong> Si vous êtes témoin d’un départ de feu, donnez l’alerte en prévenant une personne de [votre entreprise] et déclenchez l’alarme incendie grâce à un déclencheur manuel. Dans un second temps, combattez-le par des moyens appropriés (RIA, extincteur). Si vous parvenez à éteindre le feu, surveillez le foyer. Une personne de [votre entreprise] doit appeler les pompiers pour leur donner l'alerte et les guider sur le site.</p>
        <p><img src="img/logo_issue.png" class="img-inline" style="height:40px;"> <strong class="alert-text">L’évacuation : Dès que l’alarme retentit, toutes les personnes doivent quitter immédiatement leur poste de travail</strong> en écoutant les conseils des guide-files de [votre entreprise]. Vous devez vous diriger vers les issues de secours les plus proches et les plus accessibles. Ne faites pas de demi-tours ou n’allez pas récupérer vos affaires. Dirigez-vous vers le point de rassemblement le plus proche (VOIR ANNEXE 2).</p>
        <p>Les issues de secours sont localisées par le panneau suivant : Si vous n’arrivez pas à franchir le feu ou les fumées, n’insistez pas et baissez-vous pour trouver de l’air frais et prenez une autre issue. Les portes verrouillées par badge magnétique seront ouvertes. En attendant les secours : Une fois aux points de rassemblement, signalez votre présence à la personne responsable de l’appel et informez de toutes absences anormales.</p>
    </div>

    <div class="html2pdf__page-break"></div>
    <div class="green-title anti-coupe">CONSIGNES NH3/CO2</div>
    <div class="anti-coupe">
        <p>AMMONIAC (NH3) : gaz frigorifique incolore d’odeur caractéristique piquante et irritante. En cas de projection liquide ou gazeuse il peut être dangereux pour l’Homme.<br>
        <strong class="alert-text">Ne pas pénétrer dans les zones contenant de l’Ammoniac sans y être habilité et autorisé. Si vous êtes habilité, merci de transmettre vos habilitations au responsable de travaux.</strong></p>
        
        <p><strong>En cas de fuite de gaz :</strong> En cas de fuite d’ammoniac, une sirène retentit (ou alors en cas d’odeur irritante). Toutes les personnes doivent quitter immédiatement leur poste de travail : écoutez les directives des guide-files de [votre entreprise], ils sont formés à l’évacuation en cas de fuite NH3 ou CO2. Seul le personnel formé à l’intervention en sécurité ammoniac est autorisé à intervenir où se situe la fuite (les salles des machines). Pendant l’intervention, la zone sera consignée. Ne pas passer le balisage. Sortez des bâtiments sans précipitation en empruntant les issues de secours les plus proches.</p>
        
        <p class="alert-text" style="text-align:center; font-weight:bold; font-size: 16px; border:2px dashed #ff0000; padding:5px;">Ne jamais retourner en arrière</p>
        
        <p><img src="img/manche_a_air.png" class="img-inline" style="height:50px;"> Vous devez vous diriger au point de rassemblement le plus proche tout en prenant en compte le sens du vent (VOIR ANNEXE 2). Le sens du vent se vérifie grâce aux manches à air sur les toitures. Une fois aux points de rassemblement, signalez votre présence aux équipes d’intervention.</p>
    </div>

    <div class="html2pdf__page-break"></div>
    <div class="green-title anti-coupe">CONSIGNES LÉGIONELLE</div>
    <div class="anti-coupe">
        <p>La légionelle peut se retrouver au niveau des Tours AéroRéfrigérantes (TAR). L’accès est restreint et signalé via un panneau. <strong class="alert-text">Vous ne devez pas y accéder sans autorisations ni habilitations.</strong> Si vous êtes habilité, merci de transmettre vos habilitations au responsable de travaux.</p>
        <img src="img/legionellose.png" class="img-center" style="width: 90%; max-height: 700px; object-fit: contain; margin-top: 30px;" alt="Infographie Légionellose">
    </div>

    <div class="html2pdf__page-break"></div>
    <div class="green-title anti-coupe" style="margin-top: 0;">RESPONSABILITÉS ET SIGNATURES DES ENTREPRISES</div>
    
    <div class="anti-coupe">
        <p style="font-size:12px; margin-top:10px; text-align: justify;">Chaque responsable d’Entreprise Extérieure participant à l'élaboration du Plan de Prévention final, s'engage à transmettre toutes les informations de ce Plan de Prévention à chacun de ses salariés appelé à participer au chantier en objet et demeure responsable de l'application des mesures de prévention nécessaires à la protection de son personnel. Les parties réactualisent ce Plan de Prévention en cas de modifications entraînant des répercussions significatives en termes de sécurité. L’entreprise utilisatrice se réserve le droit de différer l’accès en cas de risque de coactivité ou d’interférence avec une autre entreprise. En cas de problème d’exploitation, L’entreprise utilisatrice reste prioritaire sur ses installations, se réserve le droit de différer l’accès, et pourra interrompre le chantier pour procéder à des travaux urgents. L’entreprise utilisatrice se réserve le droit d’interrompre les travaux si les règles définies dans le présent plan de prévention ne sont pas respectées.</p>
    </div>

    <div class="anti-coupe">
        <h3 style="text-align:center; margin-top:10px; font-size:15px;">ENTREPRISE EXTERIEURE</h3>
        <table class="signature-table" style="margin-bottom: 8px;">
            <tr><th width="20%">Entreprise</th><th width="25%">Nom / Prénom</th><th width="20%">Fonction</th><th width="15%">Téléphone</th><th width="20%">Date & Signature</th></tr>
            <tr><td height="14"><input type="text" class="cell-input"></td><td><input type="text" class="cell-input"></td><td><input type="text" class="cell-input"></td><td><input type="text" class="cell-input"></td><td><input type="text" class="cell-input"></td></tr>
            <tr><td height="14"><input type="text" class="cell-input"></td><td><input type="text" class="cell-input"></td><td><input type="text" class="cell-input"></td><td><input type="text" class="cell-input"></td><td><input type="text" class="cell-input"></td></tr>
            <tr><td height="14"><input type="text" class="cell-input"></td><td><input type="text" class="cell-input"></td><td><input type="text" class="cell-input"></td><td><input type="text" class="cell-input"></td><td><input type="text" class="cell-input"></td></tr>
            <tr><td height="14"><input type="text" class="cell-input"></td><td><input type="text" class="cell-input"></td><td><input type="text" class="cell-input"></td><td><input type="text" class="cell-input"></td><td><input type="text" class="cell-input"></td></tr>
            <tr><td height="14"><input type="text" class="cell-input"></td><td><input type="text" class="cell-input"></td><td><input type="text" class="cell-input"></td><td><input type="text" class="cell-input"></td><td><input type="text" class="cell-input"></td></tr>
        </table>
    </div>

    <div class="anti-coupe">
        <h3 style="text-align:center; margin-top:10px; font-size:15px;">ENTREPRISES SOUS TRAITANTES</h3>
        <table class="signature-table" style="margin-bottom: 8px;">
            <tr><th width="20%">Entreprise</th><th width="20%">Nom / Prénom</th><th width="25%">Nature des travaux</th><th width="15%">Téléphone</th><th width="20%">Adresse & Signature</th></tr>
            <tr><td height="14"><input type="text" class="cell-input"></td><td><input type="text" class="cell-input"></td><td><input type="text" class="cell-input"></td><td><input type="text" class="cell-input"></td><td><input type="text" class="cell-input"></td></tr>
            <tr><td height="14"><input type="text" class="cell-input"></td><td><input type="text" class="cell-input"></td><td><input type="text" class="cell-input"></td><td><input type="text" class="cell-input"></td><td><input type="text" class="cell-input"></td></tr>
            <tr><td height="14"><input type="text" class="cell-input"></td><td><input type="text" class="cell-input"></td><td><input type="text" class="cell-input"></td><td><input type="text" class="cell-input"></td><td><input type="text" class="cell-input"></td></tr>
            <tr><td height="14"><input type="text" class="cell-input"></td><td><input type="text" class="cell-input"></td><td><input type="text" class="cell-input"></td><td><input type="text" class="cell-input"></td><td><input type="text" class="cell-input"></td></tr>
            <tr><td height="14"><input type="text" class="cell-input"></td><td><input type="text" class="cell-input"></td><td><input type="text" class="cell-input"></td><td><input type="text" class="cell-input"></td><td><input type="text" class="cell-input"></td></tr>
        </table>
    </div>

    <div class="anti-coupe">
        <h3 style="text-align:center; margin-top:10px; font-size:15px;">ENTREPRISE UTILISATRICE</h3>
        <table class="signature-table" style="margin-bottom: 10px;">
            <tr><th width="25%">Nom / Prénom</th><th width="25%">Fonction</th><th width="15%">Téléphone</th><th width="15%">Date</th><th width="20%">Signature</th></tr>
            <tr><td height="14"><input type="text" class="cell-input" style="font-weight:bold;" value=""></td><td><input type="text" class="cell-input" value="Représentant légal"></td><td><input type="text" class="cell-input" value=""></td><td><input type="text" class="cell-input"></td><td><input type="text" class="cell-input"></td></tr>
            <tr><td height="14"><input type="text" class="cell-input" style="font-weight:bold;" value=""></td><td><input type="text" class="cell-input" value="Responsable des travaux"></td><td><input type="text" class="cell-input" value=""></td><td><input type="text" class="cell-input"></td><td><input type="text" class="cell-input"></td></tr>
            <tr><td height="14"><input type="text" class="cell-input" style="font-weight:bold;" value=""></td><td><input type="text" class="cell-input" value="Référent sécurité"></td><td><input type="text" class="cell-input" value=""></td><td><input type="text" class="cell-input"></td><td><input type="text" class="cell-input"></td></tr>
            <tr><td height="14"><input type="text" class="cell-input" style="font-weight:bold;" value=""></td><td><input type="text" class="cell-input" value="Animatrice Sécurité"></td><td><input type="text" class="cell-input" value=""></td><td><input type="text" class="cell-input"></td><td><input type="text" class="cell-input"></td></tr>
            <tr><td height="14"><input type="text" class="cell-input"></td><td><input type="text" class="cell-input"></td><td><input type="text" class="cell-input"></td><td><input type="text" class="cell-input"></td><td><input type="text" class="cell-input"></td></tr>
            <tr><td height="14"><input type="text" class="cell-input"></td><td><input type="text" class="cell-input"></td><td><input type="text" class="cell-input"></td><td><input type="text" class="cell-input"></td><td><input type="text" class="cell-input"></td></tr>
        </table>
    </div>
    <div class="html2pdf__page-break"></div>
    <div class="green-title anti-coupe">ANNEXE 1 : Plan de circulation [votre entreprise]</div>
    <p style="text-align:center; font-style:italic; color:#666; margin-top:20px;">[Insérer ici le plan d'accès et de circulation de votre site]</p>

    <div class="html2pdf__page-break"></div>
    <div class="green-title anti-coupe">ANNEXE 2 : Points de rassemblements</div>
    <p style="text-align:center; font-style:italic; color:#666; margin-top:20px;">[Insérer ici le plan de votre site avec les points de rassemblement]</p>

</div>

<script>
    // 1. Initialisation : Remplissage de la date ET sécurité pour forcer le masquage des risques
    document.addEventListener("DOMContentLoaded", function() {
        // Date
        const today = new Date();
        const dateString = today.toLocaleDateString('fr-FR', { day: '2-digit', month: '2-digit', year: 'numeric' });
        document.getElementById('date-auto').innerText = dateString;

        // Sécurité : On s'assure que tous les contenus sont bien cachés au chargement
        const allRiskContents = document.querySelectorAll('.risk-content');
        allRiskContents.forEach(content => {
            content.classList.add('hidden-block');
        });
    });

    // 2. Récupération du Numéro PdP magique quand le technicien tape le nom de l'entreprise
    const inputNom = document.getElementById('input-nom-ee');
    const idExistante = document.getElementById('hidden-id-ee').value;
    
    // Si on vient de la porte organisée, on verrouille le champ nom
    if (idExistante !== "") {
        inputNom.readOnly = true;
        inputNom.style.background = "#f1f5f9";
    }

    inputNom.addEventListener('blur', async function() {
        // On génère le numéro uniquement si c'est une nouvelle saisie
        if (idExistante === "" && this.value.trim() !== "") {
            try {
                const response = await fetch('plan_prevention.php?action=get_num&nom=' + encodeURIComponent(this.value.trim()));
                const num = await response.text();
                document.getElementById('affichage-num-pdp').innerText = num;
            } catch (e) {
                console.error("Erreur génération numéro", e);
            }
        }
    });

    // 3. Masquage des zones de risque (Impression propre)
    function toggleRisk(contentId, isVisible) {
        const contentDiv = document.getElementById(contentId);
        if (isVisible) {
            contentDiv.classList.remove('hidden-block');
        } else {
            contentDiv.classList.add('hidden-block');
        }
    }

    // 4. GÉNÉRATION + ENVOI DU PDF, EN DEUX ÉTAPES
    //
    // CORRECTIF : les méthodes précédentes tentaient de reconstruire le rendu de la page en JS
    // (html2canvas/html2pdf, avec ou sans cadre isolé) pour générer le PDF et l'envoyer
    // automatiquement en un seul clic. Sur certaines machines, cette reconstruction produisait
    // un rendu décalé, coupé ou vide — de façon différente à chaque tentative, ce qui rendait
    // le problème impossible à fiabiliser à distance.
    // Nouvelle méthode : on utilise l'impression native du navigateur (bouton "Imprimer",
    // fiable à 100% car c'est le moteur du navigateur lui-même qui dessine le PDF) pour générer
    // le fichier, puis on le renvoie sur le serveur via un simple choix de fichier.
    function declencherChoixFichierPDF() {
        document.getElementById('input-pdf-upload').click();
    }

    async function envoyerPDFChoisi(fileInput) {
        const nomEntreprise = inputNom.value.trim();
        const id_ee = document.getElementById('hidden-id-ee').value;
        const numPdp = document.getElementById('affichage-num-pdp').innerText;

        if (!nomEntreprise) {
            aspirineAlert("Attention", "Veuillez taper le nom de l'entreprise extérieure avant d'envoyer.");
            fileInput.value = '';
            return;
        }

        const file = fileInput.files[0];
        if (!file) return;

        const btn = document.getElementById('btn-upload-pdp');
        const oldText = btn.innerHTML;
        btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Envoi...';
        btn.disabled = true;

        try {
            const fd = new FormData();
            fd.append('pdf_file', file, numPdp + '.pdf');
            fd.append('nom_ee', nomEntreprise);
            fd.append('num_pdp', numPdp);
            if (id_ee) fd.append('id_ee', id_ee);

            const response = await fetch('plan_prevention.php', { method: 'POST', body: fd });
            const result = await response.json();

            if (result.status === 'success') {
                await aspirineAlert("Succès", "Le Plan de Prévention a été enregistré avec succès !");
                window.close();
            } else {
                await aspirineAlert("Erreur", result.message || "Erreur lors de l'écriture sur le serveur.");
            }
        } catch (error) {
            await aspirineAlert("Erreur technique", "Erreur lors de l'envoi : " + error);
        }

        fileInput.value = '';
        btn.innerHTML = oldText;
        btn.disabled = false;
    }

    // --- MOTEUR DES MODALES GMAO ---
    function aspirineAlert(titre, message) {
        return new Promise((resolve) => {
            const modal = document.getElementById('customAlert');
            document.getElementById('alertTitle').innerText = titre;
            document.getElementById('alertMessage').innerText = message;
            
            // Changement de style dynamique en fonction du titre
            const icon = modal.querySelector('i');
            const topBar = modal.querySelector('div>div');
            if (titre === "Erreur" || titre.includes("Attention")) {
                icon.className = "fa-solid fa-triangle-exclamation";
                icon.style.color = "#e74c3c";
                modal.children[0].style.borderTop = "5px solid #e74c3c";
            } else {
                icon.className = "fa-solid fa-circle-check";
                icon.style.color = "#27ae60";
                modal.children[0].style.borderTop = "5px solid #27ae60";
            }

            modal.style.display = 'block';

            document.getElementById('alertOk').onclick = () => {
                modal.style.display = 'none';
                resolve();
            };
        });
    }
</script>

<div id="customAlert" style="display:none; position:fixed; z-index:99999; left:0; top:0; width:100%; height:100%; background:rgba(0,0,0,0.5); backdrop-filter: blur(3px);">
    <div style="background:white; width:350px; margin:15% auto; padding:20px; border-radius:12px; text-align:center; box-shadow: 0 10px 25px rgba(0,0,0,0.2); border-top: 5px solid #27ae60;">
        <i class="fa-solid fa-circle-check" style="font-size:3rem; color:#27ae60; margin-bottom:15px;"></i>
        <h3 id="alertTitle" style="margin:10px 0; color:#2c3e50;">Succès</h3>
        <p id="alertMessage" style="color:#666; font-size:0.9rem; margin-bottom:20px;">Opération réussie !</p>
        <div style="display:flex; justify-content:center;">
            <button id="alertOk" style="padding:10px 30px; border:none; border-radius:6px; background:#2c3e50; color:white; cursor:pointer; font-weight:bold;">OK</button>
        </div>
    </div>
</div>

</body>
</html>