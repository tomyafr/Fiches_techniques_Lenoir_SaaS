<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/ia_helper.php';
requireAuth(['technicien', 'admin']);

$db = getDB();
$userId = $_SESSION['user_id'];
$id = $_GET['id'] ?? null;

if (!$id) {
    header('Location: technicien.php');
    exit;
}

if ($_SESSION['role'] === 'admin') {
    $stmt = $db->prepare('
        SELECT m.*, i.numero_arc, i.technicien_id, i.date_intervention, c.nom_societe 
        FROM machines m 
        JOIN interventions i ON m.intervention_id = i.id 
        JOIN clients c ON i.client_id = c.id
        WHERE m.id = ?
    ');
    $stmt->execute([$id]);
} else {
    $stmt = $db->prepare('
        SELECT m.*, i.numero_arc, i.technicien_id, i.date_intervention, c.nom_societe 
        FROM machines m 
        JOIN interventions i ON m.intervention_id = i.id 
        JOIN clients c ON i.client_id = c.id
        WHERE m.id = ? AND i.technicien_id = ?
    ');
    $stmt->execute([$id, $userId]);
}
$machine = $stmt->fetch();

if (!$machine) {
    die("Machine introuvable ou accès refusé.");
}

$donnees = json_decode($machine['donnees_controle'] ?? '{}', true);
$mesures = json_decode($machine['mesures'] ?? '{}', true);
$photosData = json_decode($machine['photos'] ?? '{}', true) ?: [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    verifyCsrfToken();
    if ($_POST['action'] === 'save_machine') {
        $of = trim($_POST['numero_of'] ?? '');
        $annee = trim($_POST['annee_fabrication'] ?? '');
        $commentaire = trim($_POST['commentaires'] ?? '');
        $dysfonctionnements = trim($_POST['dysfonctionnements'] ?? '');
        $conclusion = trim($_POST['conclusion'] ?? '');
        $postDonnees = $_POST['donnees'] ?? [];
        $postMesures = $_POST['mesures'] ?? [];
        $postPhotos = $_POST['photos_json'] ?? '{}';

        $db->prepare('UPDATE machines SET numero_of = ?, annee_fabrication = ?, commentaires = ?, dysfonctionnements = ?, conclusion = ?, donnees_controle = ?, mesures = ?, photos = ? WHERE id = ?')
            ->execute([$of, $annee, $commentaire, $dysfonctionnements, $conclusion, json_encode($postDonnees), json_encode($postMesures), $postPhotos, $id]);

        header('Location: intervention_edit.php?id=' . $machine['intervention_id'] . '&msg=saved');
        exit;
    }
}

$designation = strtoupper(trim($machine['designation']));
$isAPRF = strpos($designation, 'APRF') !== false || strpos($designation, 'RD') !== false;
$isEDX = strpos($designation, 'ED-X') !== false || strpos($designation, 'FOUCAULT') !== false;
$isOV = strpos($designation, 'OV') !== false && strpos($designation, 'ROUE') === false;
$isOVAP = $isOV && strpos($designation, 'OVAP') !== false;
$isLevage = (strpos($designation, 'LEVAGE') !== false || strpos($designation, 'AIMANT') !== false) && !$isAPRF && !$isPAP;
$isPAP = strpos($designation, 'À AIMANTS PERMANENTS') !== false || strpos($designation, 'TAP/PAP') !== false || strpos($designation, 'PAP') !== false || strpos($designation, 'TAP') !== false;

// Temps prévisionnel par type
if ($isEDX)
    $tempsPrev = '3h30';
elseif ($isOV)
    $tempsPrev = '1h30';
elseif ($isAPRF)
    $tempsPrev = '1h00';
elseif ($isPAP)
    $tempsPrev = '1h00';
elseif ($isLevage)
    $tempsPrev = '1h30';
else
    $tempsPrev = '1h00';

$dateIntervention = date('d/m/Y', strtotime($machine['date_intervention']));
$tempsRealise = $mesures['temps_realise'] ?? '';
$heureDebut = $mesures['heure_debut'] ?? '';
$heureFin = $mesures['heure_fin'] ?? '';

/**
 * Génère un résumé des dysfonctionnements via IA ou fallback local.
 */
function generateDysfunctionsAI($machine, $type = 'E') {
    $donnees = json_decode($machine['donnees_controle'] ?? '{}', true);
    $issues = extractIssuesFromDonnees($donnees);
    $typeMachine = $machine['designation'];
    $poste = json_decode($machine['mesures'] ?? '{}', true)['poste'] ?? 'N/A';

    if ($type === 'E') {
        $formattedAA = array_map(fn($i) => "• " . $i['designation'] . ($i['commentaire'] ? " (" . $i['commentaire'] . ")" : ""), $issues['aa']);
        $formattedNC = array_map(fn($i) => "• " . $i['designation'] . ($i['commentaire'] ? " (" . $i['commentaire'] . ")" : ""), $issues['nc']);
        $formattedNR = array_map(fn($i) => "• " . $i['designation'] . ($i['commentaire'] ? " (" . $i['commentaire'] . ")" : ""), $issues['nr']);

        $systemPrompt = "Tu es un expert technique Lenoir-Mec spécialiste des séparateurs magnétiques industriels. Rédige la section E) CAUSE DE DYSFONCTIONNEMENT en français. Format : une liste à puces courte (1 ligne par problème). Style : professionnel, technique, concis.";
        $userPrompt = "Type: $typeMachine, Poste: $poste\nAA: " . implode(", ", $formattedAA) . "\nNC: " . implode(", ", $formattedNC) . "\nNR/HS: " . implode(", ", $formattedNR);

        $result = callGroqIA($systemPrompt, $userPrompt);
        if (!$result) {
            $all = array_merge($formattedNR, $formattedNC, $formattedAA);
            return !empty($all) ? implode("\n", $all) : "Aucun dysfonctionnement majeur signalé.";
        }
        return $result;
    } else {
        $systemPrompt = "En te basant sur les dysfonctionnements listés, rédige la section F) CONCLUSION. Format : 1-2 phrases max. Style rapport d'expertise Lenoir-Mec.";
        $userPrompt = "Machine: $typeMachine\nDysfonctionnements: " . json_encode($issues);
        return callGroqIA($systemPrompt, $userPrompt) ?: "Votre équipement est conforme à nos standards technologiques.";
    }
}

// --- BUG-020: Fréquences recommandées Lenoir-Mec ---
$recoFreq = [];
if ($isEDX) {
    $recoFreq = [
        'edx_freq_bande' => 'h',
        'edx_freq_virole' => 'h',
        'edx_freq_tamb' => 'h',
        'edx_freq_pal' => 'q',
        'edx_freq_graiss' => 'm',
        'edx_freq_net_conv' => 'h',
        'edx_freq_net_cais' => 'h'
    ];
} elseif ($isOV) {
    $recoFreq = [
        'ov_freq_bande' => 'h',
        'ov_freq_fix' => 'h',
        'ov_freq_tamb' => 'q',
        'ov_freq_graiss' => 'h'
    ];
}

// Pre-remplissage des fréquences si vides (BUG-020)
foreach ($recoFreq as $rfk => $rfv) {
    if (!isset($donnees[$rfk]) || $donnees[$rfk] === '') {
        $donnees[$rfk] = $rfv;
    }
}
?>
<!DOCTYPE html>
<html lang="fr">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Fiche <?= htmlspecialchars($machine['designation']) ?></title>
    <!-- On charge quand même le style de base, mais on le surcharge pour la page A4 -->
    <link rel="stylesheet" href="/assets/style.css">
    <style>
        body {
            background: #1a1b1e;
            color: #fff;
            padding: 20px;
            font-family: Arial, sans-serif;
        }

        /* A4 Page Style to mimic PDF */
        .pdf-page {
            width: 21cm;
            min-height: 29.7cm;
            margin: 60px auto 20px auto;
            background: white;
            color: black;
            box-shadow: 0 0 20px rgba(0, 0, 0, 0.8);
            position: relative;
            padding: 1cm;
            box-sizing: border-box;
            border-radius: 4px;
            overflow: hidden;
            font-size: 13px;
        }

        .pdf-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 2px solid #000;
            padding-bottom: 15px;
            margin-bottom: 20px;
        }

        .lenoir-title {
            font-size: 24px;
            font-weight: 900;
            color: #000;
            margin: 0;
            text-transform: uppercase;
        }

        .pdf-meta {
            text-align: right;
            font-size: 14px;
            line-height: 1.5;
        }

        .pdf-title-box {
            text-align: center;
            border: 2px solid #000;
            padding: 10px;
            font-size: 20px;
            font-weight: bold;
            background: #f0f0f0;
            margin-bottom: 20px;
        }

        .pdf-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
            color: black;
        }

        .pdf-table th,
        .pdf-table td {
            border: 1px solid #000;
            padding: 4px 5px;
            vertical-align: middle;
        }

        .pdf-table th {
            background-color: #f0f0f0;
            text-align: left;
            font-size: 12px;
            text-transform: uppercase;
        }

        .pdf-table td.col-etat {
            text-align: center;
            width: 90px;
            white-space: nowrap;
        }

        .pdf-table td.col-comment {
            width: 45%;
        }

        /* === PASTILLE SYSTEM === */
        .pastille-group {
            display: flex;
            gap: 2px;
            align-items: center;
            justify-content: space-around;
            width: 140px;
            margin: 0 auto;
            flex-shrink: 0;
        }

        .pastille-group label {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 28px;
            height: 28px;
            border-radius: 50%;
            cursor: pointer;
            border: 2px solid #ccc;
            transition: all 0.15s ease;
            position: relative;
            font-size: 0;
            -webkit-tap-highlight-color: transparent;
            touch-action: manipulation;
        }

        .pastille-group label:active {
            transform: scale(0.9);
        }

        .pastille-group input[type="radio"] {
            position: absolute;
            opacity: 0;
            width: 0;
            height: 0;
            pointer-events: none;
        }

        /* Couleurs pastilles */
        .pastille-group label.p-na {
            background: #bbb;
            border-color: #999;
        }

        .pastille-group label.p-ok {
            background: #28a745;
            border-color: #1e7e34;
        }

        .pastille-group label.p-aa {
            background: #e67e22;
            border-color: #d35400;
        }

        .pastille-group label.p-nc {
            background: #dc3545;
            border-color: #bd2130;
        }

        .pastille-group label.p-nr {
            background: #8b0000;
            border-color: #5a0000;
        }

        /* Etat sélectionné */
        .pastille-group label.selected {
            transform: scale(1.15);
            box-shadow: 0 0 0 3px rgba(0, 0, 0, 0.25);
        }

        .pastille-group label.selected::after {
            content: '\2713';
            color: white;
            font-size: 14px;
            font-weight: bold;
            text-shadow: 0 1px 2px rgba(0, 0, 0, 0.4);
        }

        @media screen and (max-width: 768px) {
            .pastille-group label {
                width: 36px;
                height: 36px;
            }

            .pastille-group label.selected::after {
                font-size: 18px;
            }
        }

        /* === PHOTO SYSTEM === */
        .photo-btn {
            display: inline-flex;
            align-items: center;
            gap: 2px;
            background: #5b9bd5;
            color: white;
            border: none;
            border-radius: 4px;
            padding: 2px 6px;
            font-size: 10px;
            cursor: pointer;
            vertical-align: middle;
            -webkit-tap-highlight-color: transparent;
            touch-action: manipulation;
        }

        .photo-btn:active {
            transform: scale(0.95);
        }

        .photo-thumbs {
            display: inline-flex;
            gap: 4px;
            vertical-align: middle;
            flex-wrap: wrap;
        }

        .photo-thumb-wrap {
            position: relative;
            display: inline-block;
        }

        .photo-thumb-wrap img {
            width: 50px;
            height: 50px;
            object-fit: cover;
            border-radius: 3px;
            border: 1px solid #ccc;
            cursor: pointer;
        }

        .photo-thumb-wrap .photo-del {
            position: absolute;
            top: -4px;
            right: -4px;
            background: #dc3545;
            color: white;
            border: none;
            border-radius: 50%;
            width: 16px;
            height: 16px;
            font-size: 10px;
            line-height: 16px;
            text-align: center;
            cursor: pointer;
            padding: 0;
        }

        .photo-annexe-item {
            text-align: center;
            max-width: 200px;
        }

        .photo-annexe-item img {
            width: 180px;
            height: 135px;
            object-fit: cover;
            border: 1px solid #000;
        }

        .photo-annexe-item p {
            font-size: 10px;
            margin: 3px 0 0 0;
            color: #333;
        }

        /* PHOTO GRID SYSTEM */
        .photo-grid-1 { grid-template-columns: 1fr; }
        .photo-grid-2 { grid-template-columns: 1fr 1fr; }
        .photo-grid-3 { 
            grid-template-columns: 1fr 1fr;
            grid-template-areas: "p1 p2" "p3 p3";
        }
        .photo-grid-3 > div:nth-child(3) { grid-area: p3; }
        .photo-grid-4 { grid-template-columns: 1fr 1fr; grid-template-rows: auto auto; }

        .pdf-input {
            border: none;
            border-bottom: 1px dashed #000;
            background: transparent;
            font-size: 13px;
            font-family: Arial;
            padding: 2px;
            width: 100%;
            outline: none;
            color: black;
            border-radius: 0;
        }

        /* --- SECTION B : MONTAGE PHOTO --- */
        .photo-montage-grid {
            display: grid;
            gap: 10px;
            margin: 15px auto 10px auto;
            width: 100%;
            max-width: 550px;
            min-height: 100px;
            background: #fdfdfd;
            border: 1px solid #eee;
            padding: 10px;
            box-sizing: border-box;
            page-break-inside: avoid;
        }
        .photo-montage-grid.empty {
            display: flex;
            align-items: center;
            justify-content: center;
            border: 2px dashed #ccc;
            background: #fafafa;
        }
        .photo-placeholder {
            text-align: center;
            color: #999;
        }
        .photo-placeholder span { font-size: 40px; display: block; margin-bottom: 10px; }
        .photo-placeholder p { font-size: 13px; margin: 0 0 10px 0; font-style: italic; }

        .montage-item {
            position: relative;
            width: 100%;
            height: 250px;
            overflow: hidden;
            border: 1px solid #000;
            background: #eee;
        }
        .montage-item img {
            width: 100%;
            height: 100%;
            object-fit: contain;
            background: #f8f8f8;
            display: block;
        }
        .photo-comment-overlay {
            position: absolute;
            bottom: 0;
            left: 0;
            right: 0;
            background: rgba(0, 0, 0, 0.6);
            color: white;
            font-size: 11px;
            padding: 4px 8px;
            text-align: center;
        }
        .montage-item .photo-del-overlay {
            position: absolute;
            top: 8px;
            right: 8px;
            background: #dc3545;
            color: white;
            border: 2px solid white;
            border-radius: 50%;
            width: 28px;
            height: 28px;
            cursor: pointer;
            display: flex !important; /* Force visibility */
            align-items: center;
            justify-content: center;
            font-size: 16px;
            font-weight: bold;
            z-index: 100;
            box-shadow: 0 2px 4px rgba(0,0,0,0.3);
        }
        
        /* Grid variants - Refined for perfect alignment */
        .grid-1 { grid-template-columns: 1fr; }
        .grid-1 .montage-item { height: 280px; }
        
        .grid-2 { grid-template-columns: 1fr 1fr; }
        
        .grid-3 { 
            grid-template-columns: 1.2fr 0.8fr; 
            grid-template-rows: 250px 250px; 
            align-items: stretch;
        }
        /* Left image spans 2 rows */
        .grid-3 .montage-item:first-child { 
            grid-row: span 2; 
            height: 100%; 
        }
        /* Right images (2nd and 3rd) must fill their row height */
        .grid-3 .montage-item:nth-child(2),
        .grid-3 .montage-item:nth-child(3) {
            height: 100%;
        }
        
        .grid-4 { grid-template-columns: 1fr 1fr; grid-template-rows: 200px 200px; }
        .grid-4 .montage-item { height: 100%; }

        @media print {
            .photo-montage-grid.empty, .photo-del-overlay, .no-print-pdf { display: none !important; }
        }

        .pdf-textarea {
            width: 100%;
            min-height: 30px;
            border: 1px solid transparent;
            background: transparent;
            resize: none;
            overflow: hidden;
            font-family: Arial;
            font-size: 12px;
            outline: none;
            color: black;
            box-sizing: border-box;
        }

        .pdf-textarea:hover,
        .pdf-textarea:focus {
            background: rgba(0, 0, 0, 0.05);
            border: 1px dashed #999;
        }

        .pdf-section-title {
            font-weight: bold;
            font-size: 14px;
            background: #e0e0e0;
            padding: 5px;
            border: 1px solid #000;
            margin-bottom: -1px;
            color: black;
            text-transform: uppercase;
        }

        /* Top Save bar */
        .top-bar {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            background: rgba(10, 10, 15, 0.95);
            padding: 10px 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            z-index: 100;
            backdrop-filter: blur(10px);
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        }

        .diagonal-header {
            height: 90px;
            vertical-align: bottom;
            padding: 0;
            position: relative;
            background: #e0e0e0 !important;
            overflow: hidden;
        }
        .diagonal-wrapper {
            display: flex;
            width: 100%;
            height: 100%;
            align-items: flex-end;
            justify-content: space-around;
            padding-bottom: 5px;
        }
        .diag-label {
            transform: rotate(-55deg);
            transform-origin: bottom left;
            white-space: nowrap;
            font-size: 8px;
            font-weight: bold;
            width: 0;
            margin-left: 10px;
            color: #000;
        }
        .diag-line {
            position: absolute;
            bottom: 0;
            width: 1px;
            height: 15px;
            background: #999;
        }

        @media print {
            @page {
                size: A4 portrait;
                margin: 0.5cm;
            }

            .top-bar {
                display: none !important;
            }

            body {
                background: white;
                padding: 0;
                margin: 0;
            }

            .mobile-wrapper {
                padding: 0;
                margin: 0;
            }

            tr {
                page-break-inside: avoid;
            }

            .pdf-page {
                margin: 0;
                box-shadow: none;
                padding: 0;
                border-radius: 0;
            }
        }

        @media (max-width: 768px) {
            body {
                padding: 0;
                background: #111;
            }

            .mobile-wrapper {
                overflow-x: auto;
                -webkit-overflow-scrolling: touch;
                padding: 10px;
                padding-top: 70px;
            }

            .pdf-page {
                margin: 0 0 20px 0;
                transform-origin: top left;
            }
        }
        .btn-ia-refresh {
            background: rgba(230, 126, 34, 0.1);
            color: #d35400;
            border: 1px solid rgba(230, 126, 34, 0.4);
            padding: 3px 8px;
            border-radius: 4px;
            font-size: 11px;
            font-weight: bold;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 5px;
            transition: all 0.2s;
        }
        .btn-ia-refresh:hover {
            background: rgba(230, 126, 34, 0.2);
            border-color: #d35400;
        }
        .btn-ia-refresh img {
            filter: sepia(1) saturate(5) hue-rotate(-30deg);
        }
    </style>
</head>

<body>

    <form method="POST" id="machineForm" autocomplete="off">
        <input type="hidden" name="action" value="save_machine">
        <?= csrfField() ?>

        <div class="top-bar">
            <button type="button" class="btn btn-ghost"
                onclick="window.location.href='intervention_edit.php?id=<?= $machine['intervention_id'] ?>'"
                style="color:white; border-color:white; display:flex; align-items:center; gap:6px;">
                <img src="/assets/icon_back_white.svg" style="height: 18px; width: 18px;"> REVENIR</button>
            <div style="display:flex; gap:10px; align-items:center;">
                <label style="color:white; font-size:0.8rem; display:flex; align-items:center; gap:5px; cursor:pointer; background:rgba(255,255,255,0.1); padding:5px 10px; border-radius:5px;">
                    <input type="checkbox" name="mesures[excluded]" value="1" <?= ($mesures['excluded'] ?? false) ? 'checked' : '' ?>>
                    Exclure du rapport
                </label>
                <button type="button" class="btn btn-ghost" onclick="window.print()"
                    style="background:#2b2d31; color:white; border:1px solid #444; display:flex; align-items:center; gap:6px;">
                    <img src="/assets/icon_document_white.svg" style="height: 16px; width: 16px; margin-right: 8px; vertical-align: middle;"> IMPRIMER
                </button>
                <button type="submit" class="btn btn-primary" style="background:#e6b12a; color:#000;">ENREGISTRER</button>
            </div>
        </div>

        <div class="mobile-wrapper">
            <div class="pdf-page">
                <!-- Header exact LENOIR (Always show for consistency) -->
                <table style="width:100%; border-collapse:collapse; border:1px solid #000; margin-bottom:15px; color:#000;">
                    <tr>
                        <td style="width:40%; border-right:1px solid #000; padding:15px; vertical-align:middle; background:#fff; text-align:center;">
                            <img src="/assets/lenoir_logo_doc.png" alt="LENOIR-MEC" 
                                style="max-width:220px; width:100%; height:auto; display:block; margin:0 auto;">
                        </td>
                        <td style="width:60%; text-align:center; vertical-align:middle;">
                            <span style="font-size:26px; font-weight:bold; color:#000;">
                                <?php if ($isAPRF): ?>
                                    <?php if (strpos($designation, 'RD') !== false): ?>
                                        Electroaimant de triage fixe RD
                                    <?php else: ?>
                                        Aimant permanent rectangulaire fixe<br>APRF
                                    <?php endif; ?>
                                <?php elseif ($isPAP): ?>
                                    Tambour ou Poulie à Aimants Permanents<br>TAP/PAP
                                <?php elseif ($isLevage): ?>
                                    Electroaimants de Levage
                                <?php else: ?>
                                    <?= htmlspecialchars(str_replace('RDE', 'RD', $machine['designation'])) ?>
                                <?php endif; ?>
                            </span>
                        </td>
                    </tr>
                </table>

                <div style="font-weight:bold; font-size:16px; color:#d35400; margin-bottom:10px; border-bottom: 2px solid #d35400; padding-bottom:5px;">A) FICHE DE CONTRÔLE :</div>
                
                <div style="font-weight:bold; color:#1B4F72; margin-bottom:10px; font-size:14px;">
                    Poste : <input type="text" name="mesures[poste]" value="<?= htmlspecialchars($mesures['poste'] ?? '') ?>" style="border:none; border-bottom:1px dashed #000; font-weight:bold; width:30px; background:transparent;" autocomplete="off">
                </div>

                <table
                    style="width:100%; border-collapse:collapse; border:1px solid #000; margin-bottom:20px; font-size:13px; color:#000;">
                    <tr>
                        <td style="width:15%; font-weight:bold; border:1px solid #000; padding:6px; background:#d9d9d9;">N°
                            A.R.C.</td>
                        <td style="width:35%; border:1px solid #000; padding:6px; font-weight:bold;">
                            <?= htmlspecialchars($machine['numero_arc']) ?>
                        </td>
                        <td style="width:15%; font-weight:bold; border:1px solid #000; padding:6px; background:#d9d9d9;">
                            Repère</td>
                        <td style="width:35%; border:1px solid #000; padding:6px;">
                            <input type="text" name="mesures[repere]"
                                value="<?= htmlspecialchars($mesures['repere'] ?? '') ?>" class="pdf-input" autocomplete="off">
                        </td>
                    </tr>
                    <tr>
                        <td style="font-weight:bold; border:1px solid #000; padding:6px; background:#d9d9d9;">N° O.F. <span style="color:var(--error);">*</span></td>
                        <td style="border:1px solid #000; padding:6px;">
                            <input type="text" name="numero_of" value="<?= htmlspecialchars($machine['numero_of']) ?>"
                                class="pdf-input" required placeholder="ex: 123456" autocomplete="off">
                        </td>
                        <td style="font-weight:bold; border:1px solid #000; padding:6px; background:#d9d9d9;">Année <span style="color:var(--error);">*</span></td>
                        <td style="border:1px solid #000; padding:6px;">
                            <input type="number" name="annee_fabrication" value="<?= htmlspecialchars($machine['annee_fabrication']) ?>"
                                class="pdf-input" required min="1900" max="<?= date('Y') + 1 ?>" placeholder="AAAA" autocomplete="off">
                        </td>
                    </tr>
                    <tr>
                        <td style="font-weight:bold; border:1px solid #000; padding:6px; background:#d9d9d9;">Date</td>
                        <td style="border:1px solid #000; padding:6px;">
                            <input type="text" name="mesures[date_intervention]"
                                value="<?= htmlspecialchars($mesures['date_intervention'] ?? $dateIntervention) ?>"
                                class="pdf-input" placeholder="DD/MM/YYYY" style="width:85px;" autocomplete="off">
                        </td>
                        <td style="font-weight:bold; border:1px solid #000; padding:6px; background:#d9d9d9;">T. prévu</td>
                        <td style="border:1px solid #000; padding:6px;">
                            <span style="font-weight:bold; color:#1B4F72; font-size:14px;"><?= htmlspecialchars($tempsPrev) ?> h</span>
                        </td>
                    </tr>
                    <tr>
                        <td style="font-weight:bold; border:1px solid #000; padding:6px; background:#e8f4e8;">Horaires</td>
                        <td style="border:1px solid #000; padding:6px;">
                            <input type="time" name="mesures[heure_debut]" id="heureDebut"
                                value="<?= htmlspecialchars($heureDebut) ?>"
                                style="border:none; outline:none; font-size:13px; background:transparent; width:70px;">
                            <span style="color:#999; font-size:11px;">→</span>
                            <input type="time" name="mesures[heure_fin]" id="heureFin"
                                value="<?= htmlspecialchars($heureFin) ?>"
                                style="border:none; outline:none; font-size:13px; background:transparent; width:70px;">
                        </td>
                        <td style="font-weight:bold; border:1px solid #000; padding:6px; background:#e8f4e8;">T. réalisé
                        </td>
                        <td style="border:1px solid #000; padding:6px;">
                            <input type="text" class="pdf-input"
                                style="width:40px; text-align:center; font-weight:bold; color:#1B4F72;"
                                name="mesures[temps_realise]" id="inputTempsRealise"
                                value="<?= htmlspecialchars($mesures['temps_realise'] ?? '') ?>"> h
                            <span id="tempsCalc" style="font-weight:bold; color:#1B4F72; font-size:14px; margin-left:5px;"></span>
                            <button type="button" id="btnChrono" onclick="toggleChrono()" class="no-print-pdf"
                                style="background:#28a745; color:white; border:none; border-radius:4px; padding:3px 10px; font-size:11px; cursor:pointer; margin-left:8px; vertical-align:middle;">▶
                                Chrono</button>
                        </td>
                    </tr>
                </table>

                <?php
                // === HELPERS ===
                function newPdfPage() {
                    return '</div><div class="pdf-page bg-white p-4">'; 
                }
                function renderSectionB($photosData) {
                    $photos = $photosData['desc_materiel'] ?? [];
                    $count = count($photos);
                    
                    $html = '
                    <div style="margin-top:20px; page-break-inside: avoid;">
                        <div style="font-weight:bold; font-size:14px; color:#d35400; margin-bottom:10px; border-bottom: 2px solid #d35400; padding-bottom:5px;">B) DESCRIPTION DU MATERIEL :</div>
                        <div id="description_materiel_montage">';
                        
                    if ($count > 0) {
                        $gridClass = 'grid-' . ($count > 4 ? 4 : $count);
                        $html .= '<div class="photo-montage-grid ' . $gridClass . '">';
                        foreach (array_slice($photos, 0, 4) as $i => $p) {
                            $html .= '
                            <div class="montage-item">
                                <img src="' . htmlspecialchars($p['data']) . '" alt="Photo Matériel ' . ($i+1) . '">
                                <button type="button" class="photo-del-overlay no-print-pdf" onclick="deletePhoto(\'desc_materiel\', ' . $i . ')">×</button>
                                ' . (!empty($p['comment']) ? '<div class="photo-comment-overlay">' . htmlspecialchars($p['comment']) . '</div>' : '') . '
                            </div>';
                        }
                        $html .= '</div>';
                    } else {
                        $html .= '
                            <div class="photo-montage-grid empty no-print-pdf">
                                <div id="photo_placeholder" style="flex:1; display:flex; align-items:center; justify-content:center; background:rgba(255,255,255,0.03); border:1px dashed var(--glass-border); border-radius:8px; height:100px; color:var(--text-dim);">
                                    <svg viewBox="0 0 24 24" width="32" height="32" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>
                                </div>
                                <button type="button" class="btn btn-primary" onclick="triggerPhoto()" style="height:38px; padding:0 1rem; display:flex; align-items:center; gap:8px;">
                                    <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/></svg> Photo
                                </button>
                            </div>';
                    }
                    
                    // Bouton d'ajout visible uniquement hors PDF
                    if ($count < 4 && !isset($_GET['pdf'])) {
                        $html .= '<div style="text-align:center; margin-top:10px;" class="no-print-pdf">
                            <button type="button" class="photo-btn" onclick="capturePhoto(\'desc_materiel\')" style="padding:6px 12px; font-size:12px;">
                                <span>📷</span> Ajouter une photo (' . $count . '/4)
                            </button>
                        </div>';
                    }

                    $html .= '
                        </div>
                    </div>';
                    
                    return $html;
                }
                function pastille($name, $value, $cssClass, $title, $currentVal)
                {
                    $sel = ($currentVal == $value) ? ' selected' : '';
                    return '<label class="' . $cssClass . $sel . '" title="' . $title . '"><input type="radio" name="donnees[' . $name . ']" value="' . $value . '" ' . ($currentVal == $value ? 'checked' : '') . '></label>';
                }
                function renderEtatRadios($key, $donnees)
                {
                    $val = $donnees[$key] ?? '';
                    return '<div class="pastille-group">'
                        . pastille($key, 'pc', 'p-na', 'Pas concerné / NA', $val)
                        . pastille($key, 'c', 'p-ok', 'Correct', $val)
                        . pastille($key, 'aa', 'p-aa', 'À améliorer', $val)
                        . pastille($key, 'nc', 'p-nc', 'Non correct', $val)
                        . pastille($key, 'nr', 'p-nr', 'Non réparé / À revoir', $val)
                        . '</div>';
                }
                function photoCamBtn($key, $label = '')
                {
                    global $photoLabelsMap;
                    if (!isset($photoLabelsMap)) $photoLabelsMap = [];
                    if ($label) $photoLabelsMap[$key] = $label;
                    
                    return '<div style="display:flex; align-items:center; gap:4px; margin-top:2px;">
                        <button type="button" class="photo-btn" onclick="capturePhoto(\'' . $key . '\')">📷</button>
                        <span class="photo-thumbs" id="thumbs_' . $key . '"></span>
                    </div>';
                }

                function renderSectionC($isEDX, $isOV) {
                    ?>
                    <div style="margin-top:20px; page-break-inside: avoid;">
                        <div style="font-weight:bold; font-size:14px; color:#d35400; margin-bottom:10px;">C) RAPPEL DES FRÉQUENCES DE NETTOYAGE ET DES DIFFÉRENTS POINTS DE CONTRÔLE :</div>
                        <img src="/assets/machines/frequences_tableau.png" style="width:100%; height:auto; border:2px solid #ed7d31;">
                    </div>
                    <?php
                }

                function renderSectionD($isEDX, $mesures) {
                    if (!$isEDX) return;
                    $mini = $mesures['edx_releve_mini'] ?? '....';
                    $maxi = $mesures['edx_releve_maxi'] ?? '....';
                    ?>
                    <div style="margin-top:20px; page-break-inside: avoid;">
                        <div style="font-weight:bold; font-size:14px; color:#d35400; margin-bottom:5px;">D) RELEVES D’INDUCTION MAGNETIQUE :</div>
                        <div style="border:1px solid #ed7d31; padding:10px; font-size:13px; background:#fff;">
                            <p style="margin:5px 0;"><strong>Roue polaire :</strong></p>
                            <p style="margin:5px 0 5px 20px;">• Relevé mini : <strong><?= htmlspecialchars($mini) ?></strong> Gauss</p>
                            <p style="margin:5px 0 5px 20px;">• Relevé maxi : <strong><?= htmlspecialchars($maxi) ?></strong> Gauss</p>
                            <p style="margin:10px 0 5px 0; font-style:italic; font-size:11px; color:#555;">(Matériel neuf = 2000 Gauss en standard +/- 10%)</p>
                        </div>
                    </div>
                    <?php
                }
                function renderCheckRow($label, $key, $donnees)
                {
                    return '<tr>
                        <td style="font-weight:bold; font-size:12px;">' . htmlspecialchars($label) . '</td>
                        <td class="col-etat" style="text-align:center;">' . renderEtatRadios($key . "_radio", $donnees) . '</td>
                        <td class="col-comment"><textarea name="donnees[' . $key . '_comment]" class="pdf-textarea" placeholder="Détails..." oninput="autoGrow(this)">' . htmlspecialchars($donnees[$key . "_comment"] ?? '') . '</textarea>' . photoCamBtn($key, $label) . '</td>
                    </tr>';
                }
                function renderAprfEtatRadios($key, $donnees)
                {
                    $val = $donnees[$key] ?? '';
                    return '<div class="pastille-group">'
                        . pastille($key, 'bon', 'p-ok', 'Bon', $val)
                        . pastille($key, 'r', 'p-aa', 'À remplacer', $val)
                        . pastille($key, 'hs', 'p-nc', 'HS', $val)
                        . '</div>';
                }
                function renderAprfRow($label, $key, $donnees)
                {
                    return '<tr>
                        <td style="font-weight:normal; font-size:11px;">' . htmlspecialchars($label) . '</td>
                        <td style="padding:2px 4px; vertical-align:middle; text-align:center;">' . renderAprfEtatRadios($key, $donnees) . '</td>
                        <td style="padding:0;"><textarea name="donnees[' . $key . '_comment]" class="pdf-textarea" style="border:none; width:100%; padding:4px;" oninput="autoGrow(this)">' . htmlspecialchars($donnees[$key . "_comment"] ?? '') . '</textarea>' . photoCamBtn($key, $label) . '</td>
                    </tr>';
                }
                ?>

                <!-- DYNAMIC CONTENT DEPENDING ON MACHINE TYPE -->
                <?php if ($isAPRF): ?>

                    <table class="pdf-table controles" style="font-size:11px;">
                        <tr>
                            <th colspan="3" style="background:#e0e0e0; padding:6px; font-size:12px; font-weight:bold; text-align:left;">
                                <div style="display:flex; justify-content:space-between; width:100%;">
                                    <span>TEMPS PRÉVISIONNEL : 25min/aimant + 25min/palonnier + 30min/armoire</span>
                                    <span>TEMPS RÉALISÉ : <?= htmlspecialchars($tempsRealise ?: '—') ?> h</span>
                                </div>
                            </th>
                        </tr>
                        <tr>
                            <th rowspan="2" style="width:40%; text-align:center; background:#e0e0e0; padding:0;">
                                <div style="border-bottom:1px solid #000; padding:4px;">DESIGNATIONS</div>
                            </th>
                            <th style="text-align:center; padding:0; background:#e0e0e0;">ETAT</th>
                            <th rowspan="2" style="width:30%; text-align:center; background:#e0e0e0;">COMMENTAIRES</th>
                        </tr>
                        <tr>
                            <th style="padding:0; background:#e0e0e0;">
                                <table style="width:100%; border-collapse:collapse; text-align:center; font-size:10px;">
                                    <tr>
                                        <td
                                            style="width:33%; border:none; border-right:1px solid #000; padding:2px; font-weight:bold;">
                                            Bon</td>
                                        <td
                                            style="width:34%; border:none; border-right:1px solid #000; padding:2px; font-weight:bold;">
                                            A remplacer<br>sous :</td>
                                        <td style="width:33%; border:none; padding:2px; font-weight:bold;">H.S.</td>
                                    </tr>
                                </table>
                            </th>
                        </tr>

                        <tr>
                            <th colspan="3" style="background:#5b9bd5; color:white; text-align:left; padding:4px;">Aimants permanent fixe de triage type APRF</th>
                        </tr>
                        <?= renderAprfRow("Satisfaction de fonctionnement", "aprf_satisfaction", $donnees) ?>
                        <?= renderAprfRow("État et type de la bande", "aprf_bande", $donnees) ?>
                        <?= renderAprfRow("État des réglettes", "aprf_reglettes", $donnees) ?>
                        <?= renderAprfRow("État des boutons étoile :", "aprf_boutons", $donnees) ?>
                        <?= renderAprfRow("Options (à préciser)", "aprf_options", $donnees) ?>

                        <tr>
                            <th colspan="3" style="background:#5b9bd5; color:white; text-align:left; padding:4px;">AIMANT PERMANENT</th>
                        </tr>
                        <?= renderAprfRow("Caisson Inox", "aprf_inox", $donnees) ?>
                        
                        <?= renderAprfRow("Contrôle de l’attraction sur échantillon", "aprf_attraction_main", $donnees) ?>
                        <?php 
                        $attractions = [
                            'bille'   => 'Bille diamètre 20 mm',
                            'ecrou'   => 'Écrou M4',
                            'rond50'  => 'Rond diamètre 6 Lg 50 mm',
                            'rond100' => 'Rond diamètre 6 Lg 100 mm'
                        ];
                        foreach($attractions as $akey => $alabel): ?>
                        <tr>
                            <td style="padding:4px; padding-left:25px; font-size:11px;"><?= $alabel ?></td>
                            <td style="border:1px solid #000; text-align:center; padding:0; vertical-align:middle; width:70px;">
                                <?= renderAprfEtatRadios("aprf_attr_$akey", $donnees) ?>
                            </td>
                            <td style="padding:0;">
                                <textarea name="donnees[aprf_attr_<?= $akey ?>_comment]" class="pdf-textarea" style="height:25px; border:none; width:100%; box-sizing:border-box; padding:4px;" placeholder="Commentaires..."><?= htmlspecialchars($donnees["aprf_attr_$akey" . "_comment"] ?? '') ?></textarea>
                            </td>
                        </tr>
                        <?php endforeach; ?>

                        <tr>
                            <th colspan="3" style="background:#5b9bd5; color:white; text-align:left; padding:4px;">APPLICATION CLIENT</th>
                        </tr>
                        <tr>
                            <td style="padding:4px; font-weight:bold;">
                                Type de produit : 
                                <input type="text" name="mesures[aprf_produit]" value="<?= htmlspecialchars($mesures['aprf_produit'] ?? '') ?>" class="pdf-input" style="width:150px; border-bottom:1px solid #000;">
                            </td>
                            <td style="border:1px solid #000; text-align:center; padding:0; vertical-align:middle; width:70px;">
                                <?= renderAprfEtatRadios("aprf_produit_stat", $donnees) ?>
                            </td>
                            <td style="padding:0;"><textarea name="donnees[aprf_produit_comment]" class="pdf-textarea" style="height:30px; border:none; width:100%; box-sizing:border-box; padding:4px;"><?= htmlspecialchars($donnees["aprf_produit_comment"] ?? '') ?></textarea></td>
                        </tr>
                        <tr>
                            <td style="padding:4px; font-weight:bold;">
                                Granulométrie : 
                                <input type="text" name="mesures[aprf_granu]" value="<?= htmlspecialchars($mesures['aprf_granu'] ?? '') ?>" class="pdf-input" style="width:60px; border-bottom:1px solid #000; text-align:center;"> mm
                            </td>
                            <td style="border:1px solid #000; text-align:center; padding:0; vertical-align:middle; width:70px;">
                                <?= renderAprfEtatRadios("aprf_granu_stat", $donnees) ?>
                            </td>
                            <td style="padding:0;"><textarea name="donnees[aprf_granu_comment]" class="pdf-textarea" style="height:30px; border:none; width:100%; box-sizing:border-box; padding:4px;"><?= htmlspecialchars($donnees["aprf_granu_comment"] ?? '') ?></textarea></td>
                        </tr>
                        <tr>
                            <td style="padding:4px; font-weight:bold;">
                                Distance aimants / bande : 
                                <input type="text" name="mesures[aprf_dist_min]" value="<?= htmlspecialchars($mesures['aprf_dist_min'] ?? '') ?>" class="pdf-input" style="width:40px; border-bottom:1px solid #000; text-align:center;"> à 
                                <input type="text" name="mesures[aprf_dist_max]" value="<?= htmlspecialchars($mesures['aprf_dist_max'] ?? '') ?>" class="pdf-input" style="width:40px; border-bottom:1px solid #000; text-align:center;"> mm
                            </td>
                            <td style="border:1px solid #000; text-align:center; padding:0; vertical-align:middle; width:70px;">
                                <?= renderAprfEtatRadios("aprf_dist_stat", $donnees) ?>
                            </td>
                            <td style="padding:0;"><textarea name="donnees[aprf_dist_comment]" class="pdf-textarea" style="height:30px; border:none; width:100%; box-sizing:border-box; padding:4px;"><?= htmlspecialchars($donnees["aprf_dist_comment"] ?? '') ?></textarea></td>
                        </tr>
                        <tr>
                            <td style="padding:4px; font-weight:bold;">
                                Hauteur de la couche : 
                                <input type="text" name="mesures[aprf_H_couche]" value="<?= htmlspecialchars($mesures['aprf_H_couche'] ?? '') ?>" class="pdf-input" style="width:80px; border-bottom:1px solid #000; text-align:center;"> mm
                            </td>
                            <td style="border:1px solid #000; text-align:center; padding:0; vertical-align:middle; width:70px;">
                                <?= renderAprfEtatRadios("aprf_H_stat", $donnees) ?>
                            </td>
                            <td style="padding:0;"><textarea name="donnees[aprf_H_comment]" class="pdf-textarea" style="height:30px; border:none; width:100%; box-sizing:border-box; padding:4px;"><?= htmlspecialchars($donnees["aprf_H_comment"] ?? '') ?></textarea></td>
                        </tr>
                        <tr>
                            <td style="padding:4px; font-weight:bold;">
                                Débit : 
                                <input type="text" name="mesures[aprf_debit]" value="<?= htmlspecialchars($mesures['aprf_debit'] ?? '') ?>" class="pdf-input" style="width:60px; border-bottom:1px solid #000; text-align:center;"> t/h
                            </td>
                            <td style="border:1px solid #000; text-align:center; padding:0; vertical-align:middle; width:70px;">
                                <?= renderAprfEtatRadios("aprf_debit_stat", $donnees) ?>
                            </td>
                            <td style="padding:4px;">
                                Avec densité de <input type="text" name="mesures[aprf_densite]" value="<?= htmlspecialchars($mesures['aprf_densite'] ?? '') ?>" class="pdf-input" style="width:60px; border-bottom:1px solid #000; text-align:center;">
                            </td>
                        </tr>
                    </table>


                    <div class="pdf-section" style="margin-top:20px;">
                        <img src="/assets/machines/aprf_diagram.png"
                            style="max-width:100%; height:auto; display:block; margin:20px auto;" alt="Schéma APRF"
                            onerror="this.style.display='none'">
                    </div>

                    <!-- EDX SCHEMA -->
                <?php elseif ($isEDX): ?>


                    <?php
                    function renderEdxEtatRadios($key, $donnees)
                    {
                        $val = $donnees[$key] ?? '';
                        return '<div class="pastille-group">'
                            . pastille($key, 'pc', 'p-na', 'Pas concerné', $val)
                            . pastille($key, 'c', 'p-ok', 'Correct', $val)
                            . pastille($key, 'aa', 'p-aa', 'À améliorer', $val)
                            . pastille($key, 'nc', 'p-nc', 'Pas correct', $val)
                            . pastille($key, 'nr', 'p-nr', 'Nécessite remplacement', $val)
                            . '</div>';
                    }
                    function renderEdxRow($label, $key, $donnees)
                    {
                        return '<tr>
                            <td style="font-weight:normal; font-size:11px;">' . htmlspecialchars($label) . '</td>
                            <td style="padding:2px 4px; vertical-align:middle; text-align:center;">' . renderEdxEtatRadios($key, $donnees) . '</td>
                            <td style="padding:0;"><textarea name="donnees[' . $key . '_comment]" class="pdf-textarea" style="border:none; width:100%; padding:4px;" oninput="autoGrow(this)">' . htmlspecialchars($donnees[$key . "_comment"] ?? '') . '</textarea>' . photoCamBtn($key, $label) . '</td>
                        </tr>';
                    }
                    ?>

                    <table class="pdf-table controles" style="font-size:11px;">
                        <tr>
                            <th style="width:35%; text-align:center; vertical-align:middle; background:#e0e0e0;">
                                DESIGNATIONS</th>
                            <th class="diagonal-header" style="width:140px;">
                                <div class="diagonal-wrapper">
                                    <div class="diag-label">Pas concerné</div>
                                    <div class="diag-label">Correct</div>
                                    <div class="diag-label">A améliorer</div>
                                    <div class="diag-label">Pas correct</div>
                                    <div class="diag-label">Nécessite remplacement</div>
                                </div>
                            </th>
                            <th style="width:35%; text-align:center; vertical-align:middle; background:#e0e0e0;">
                                COMMENTAIRES</th>
                        </tr>
                        <tr>
                            <th colspan="3" style="background:#5b9bd5; color:white;">Environnement / Aspect général</th>
                        </tr>
                        <?= renderEdxRow("Accès au séparateur", "edx_acces", $donnees) ?>
                        <?= renderEdxRow("Etat général du séparateur", "edx_etat_gen", $donnees) ?>

                        <tr>
                            <th colspan="3" style="background:#5b9bd5; color:white;">Partie A - Convoyeur</th>
                        </tr>
                        <?= renderEdxRow("Etat général des verrous", "edx_verrous", $donnees) ?>
                        <?= renderEdxRow("Etat général des grenouillères", "edx_grenouilles", $donnees) ?>
                        <?= renderEdxRow("Etat général des poignées de portes", "edx_poignees", $donnees) ?>
                        <?= renderEdxRow("Etat général des carters de protection/ des portes", "edx_carters", $donnees) ?>
                        <?= renderEdxRow("Aspect général intérieur séparateur", "edx_int_sep", $donnees) ?>
                        <?= renderEdxRow("Contrôle visuel des étanchéités latérales", "edx_etanch", $donnees) ?>
                        <?= renderEdxRow("Contrôle visuel état extérieur de la bande", "edx_bande_ext", $donnees) ?>
                        <?= renderEdxRow("Contrôle visuel état intérieur de la bande", "edx_bande_int", $donnees) ?>
                        <?= renderEdxRow("Contrôle de la tension de bande", "edx_tension_bande", $donnees) ?>
                        <?= renderEdxRow("Contrôle état des rouleaux anti-déport de bande", "edx_rlx_anti", $donnees) ?>
                        <?= renderEdxRow("Contrôle état des détecteurs de déport de bande", "edx_detecteurs", $donnees) ?>
                        <?= renderEdxRow("Contrôle état des guides TEFLON / tôle INOX déport de bande", "edx_guides", $donnees) ?>
                        <?= renderEdxRow("Contrôle état du racleur de bande", "edx_racleur", $donnees) ?>
                        <?= renderEdxRow("Contrôle réglage du racleur de bande", "edx_racleur_regl", $donnees) ?>
                        <?= renderEdxRow("Contrôle réglage des paliers PHUSE-TENDEURS", "edx_paliers_phuse", $donnees) ?>
                        <?= renderEdxRow("Contrôle état du tambour moteur", "edx_tambour", $donnees) ?>
                        <?= renderEdxRow("Contrôle visuel fibre virole roue polaire", "edx_virole", $donnees) ?>
                        <?= renderEdxRow("Contrôle visuel état déflecteur carbone roue polaire", "edx_deflecteur", $donnees) ?>
                        <?= renderEdxRow("Contrôle visuel état caisson roue polaire", "edx_caisson_roue", $donnees) ?>
                        <?= renderEdxRow("Contrôle état général des vis de fixation virole fibre", "edx_vis_virole", $donnees) ?>
                        <?= renderEdxRow("Contrôle état du contrôleur de rotation", "edx_ctrl_rot", $donnees) ?>
                        <?= renderEdxRow("Contrôle et repère du réglage du 3ème rouleau, ajustement bande", "edx_3e_rouleau", $donnees) ?>
                        <?= renderEdxRow("Contrôle état des rouleaux \"mines\"", "edx_rlx_mines", $donnees) ?>
                        <?= renderEdxRow("Contrôle état du motoréducteur entraînement bande", "edx_motor", $donnees) ?>
                        <?= renderEdxRow("Démontage carter de protection (courroie/accouplement) moteur entraînement roue polaire", "edx_dem_carter", $donnees) ?>
                        <?= renderEdxRow("Contrôle état des courroies", "edx_courroies", $donnees) ?>
                        <?= renderEdxRow("Contrôle tension des courroies", "edx_tens_courroies", $donnees) ?>
                        <?= renderEdxRow("Contrôle état accouplement", "edx_accoupl", $donnees) ?>
                        <?= renderEdxRow("Contrôle alignement moteur", "edx_align_mot", $donnees) ?>
                        <?= renderEdxRow("Contrôle état des paliers/roulements de la virole fibre", "edx_pal_fibre", $donnees) ?>
                        <?= renderEdxRow("Contrôle graissage des paliers/roulements de la virole fibre", "edx_graiss_fibre", $donnees) ?>
                        <?= renderEdxRow("Contrôle état des paliers/roulements de la roue polaire", "edx_pal_roue", $donnees) ?>
                        <?= renderEdxRow("Contrôle graissage des paliers/roulements de la roue polaire", "edx_graiss_roue", $donnees) ?>
                        <?= renderEdxRow("Contrôle induction roue polaire", "edx_induc_roue", $donnees) ?>
                        <?= renderEdxRow("Etat général des câbles d'alimentation, boîtiers de raccordement, connexion", "edx_cables", $donnees) ?>
                        <?= renderEdxRow("Nettoyage complet de l'intérieur du séparateur", "edx_nettoyage", $donnees) ?>
                        <?= renderEdxRow("Remontage des carters de protection/portes", "edx_remontage", $donnees) ?>
                    </table>

                    <?= newPdfPage() ?>
                    <table class="pdf-table controles" style="font-size:11px;">
                        <tr>
                            <th colspan="3" style="background:#5b9bd5; color:white;">Partie B - Caisson de séparation</th>
                        </tr>
                        <?= renderEdxRow("Etat général des verrous", "edx_B_verrous", $donnees) ?>
                        <?= renderEdxRow("Etat général des grenouillères", "edx_B_grenouilles", $donnees) ?>
                        <?= renderEdxRow("Etat général des poignées de portes", "edx_B_poignees", $donnees) ?>
                        <?= renderEdxRow("Etat général des carters de protection/des portes", "edx_B_portes", $donnees) ?>
                        <?= renderEdxRow("Etat général des plexis", "edx_B_plex", $donnees) ?>
                        <?= renderEdxRow("Démontage des carters de protection/des portes", "edx_B_dem", $donnees) ?>
                        <?= renderEdxRow("Aspect général intérieur du caisson de séparation", "edx_B_asp", $donnees) ?>
                        <?= renderEdxRow("Contrôle état du volet", "edx_B_volet", $donnees) ?>
                        <?= renderEdxRow("Contrôle état mécanisme réglage volet", "edx_B_meca", $donnees) ?>
                        <?= renderEdxRow("Contrôles des réglages du volet (archivages des réglages)", "edx_B_reglages", $donnees) ?>
                        <?= renderEdxRow("Nettoyage complet de l'intérieur du caisson de séparation", "edx_B_net", $donnees) ?>
                        <?= renderEdxRow("Remontage des carters de protection/portes", "edx_B_rem", $donnees) ?>
                    </table>

                    <?= newPdfPage() ?>
                    <table class="pdf-table controles" style="font-size:11px;">
                        <tr>
                            <th colspan="3" style="background:#5b9bd5; color:white;">Partie C - Armoire électrique</th>
                        </tr>
                        <tr>
                            <th colspan="3" style="background:#e0e0e0; font-weight:normal;">Hors Tension</th>
                        </tr>
                        <?= renderEdxRow("Aspect général armoire électrique", "edx_C_arm", $donnees) ?>
                        <?= renderEdxRow("Aspect général boutonnerie façade armoire", "edx_C_bout", $donnees) ?>
                        <?= renderEdxRow("Etat général AU séparateur", "edx_C_au", $donnees) ?>
                        <?= renderEdxRow("Ouverture armoire électrique", "edx_C_ouvert", $donnees) ?>
                        <?= renderEdxRow("Etat général intérieur armoire électrique", "edx_C_int", $donnees) ?>

                        <tr>
                            <th colspan="3" style="background:#e0e0e0; font-weight:normal;">Sous Tension</th>
                        </tr>
                        <?= renderEdxRow("Vitesse bande relevée", "edx_C_vit_b", $donnees) ?>
                        <?= renderEdxRow("Vitesse bande conforme process", "edx_C_vit_b_conf", $donnees) ?>
                        <?= renderEdxRow("Nouveaux réglages réalisés", "edx_C_regl1", $donnees) ?>
                        <?= renderEdxRow("Vitesse roue polaire relevée", "edx_C_vit_r", $donnees) ?>
                        <?= renderEdxRow("Vitesse roue polaire conforme aux process", "edx_C_vit_r_conf", $donnees) ?>
                        <?= renderEdxRow("Nouveaux réglages réalisés", "edx_C_regl2", $donnees) ?>
                        <?= renderEdxRow("Nouveaux réglages volet de séparation", "edx_C_regl3", $donnees) ?>
                        <?= renderEdxRow("Contrôle freinage roue polaire", "edx_C_frein", $donnees) ?>
                        <?= renderEdxRow("Temps de freinage constaté", "edx_C_temps", $donnees) ?>
                        <?= renderEdxRow("Vérification des serrages câbles de l'armoire", "edx_C_cables", $donnees) ?>
                        <?= renderEdxRow("Fermeture de l'armoire électrique", "edx_C_ferm", $donnees) ?>
                    </table>

                    <?= newPdfPage() ?>
                    <div style="margin-top:20px; font-weight:bold; font-size:11px;">Commentaire général :</div>
                    <textarea name="commentaires" class="pdf-textarea"
                        style="height:100px; padding:5px; margin-top:5px; border:1px solid #000; width:100%; box-sizing:border-box;"><?= htmlspecialchars($machine['commentaires']) ?></textarea>

                    <table class="pdf-table controles" style="font-size:11px; margin-top:20px;">
                        <tr>
                            <th colspan="6" style="background:#5b9bd5; color:white; font-size:10px;">En présence du client /
                                Rappel des fréquences de nettoyage et des différents points de contrôle</th>
                        </tr>
                        <tr>
                            <th style="width:40%;">Contrôle</th>
                            <th style="text-align:center;">Quotidien</th>
                            <th style="text-align:center;">Hebdomadaire</th>
                            <th style="text-align:center;">Mensuel</th>
                            <th style="text-align:center;">Annuel</th>
                            <th style="width:25%;">Commentaires</th>
                        </tr>
                        <?php
                        function renderFreqRowEdx($label, $key, $donnees)
                        {
                            $v = $donnees[$key] ?? '';
                            return '<tr>
                                <td style="font-weight:normal; font-size:10px;">' . htmlspecialchars($label) . '</td>
                                <td style="text-align:center;"><input type="radio" name="donnees[' . $key . ']" value="q" ' . ($v == 'q' ? 'checked' : '') . '></td>
                                <td style="text-align:center;"><input type="radio" name="donnees[' . $key . ']" value="h" ' . ($v == 'h' ? 'checked' : '') . '></td>
                                <td style="text-align:center;"><input type="radio" name="donnees[' . $key . ']" value="m" ' . ($v == 'm' ? 'checked' : '') . '></td>
                                <td style="text-align:center;"><input type="radio" name="donnees[' . $key . ']" value="a" ' . ($v == 'a' ? 'checked' : '') . '></td>
                                <td style="padding:0;"><textarea name="donnees[' . $key . '_comment]" class="pdf-textarea" style="height:30px; border:none; width:100%; box-sizing:border-box; padding:4px;">' . htmlspecialchars($donnees[$key . "_comment"] ?? '') . '</textarea></td>
                            </tr>';
                        }
                        ?>
                        <?= renderFreqRowEdx("Contrôle visuel de la bande", "edx_freq_bande", $donnees) ?>
                        <?= renderFreqRowEdx("Contrôle visuel de la virole en fibre époxy", "edx_freq_virole", $donnees) ?>
                        <?= renderFreqRowEdx("Contrôle visuel du tambour moteur", "edx_freq_tamb", $donnees) ?>
                        <?= renderFreqRowEdx("Contrôle échauffement des paliers", "edx_freq_pal", $donnees) ?>
                        <?= renderFreqRowEdx("Graissage des paliers", "edx_freq_graiss", $donnees) ?>
                        <?= renderFreqRowEdx("Nettoyage de l'intérieur du séparateur - partie convoyage", "edx_freq_net_conv", $donnees) ?>
                        <?= renderFreqRowEdx("Nettoyage de l'intérieur du séparateur - partie caisson de séparation", "edx_freq_net_cais", $donnees) ?>
                    </table>

                    <div class="pdf-section" style="margin-top:20px;">
                        <img src="/assets/machines/edx_diagram.png"
                            style="max-width:100%; height:auto; display:block; margin:20px auto;" alt="Schéma ED-X"
                            onerror="this.style.display='none'">

                        <img src="/assets/machines/edx_diagram_2.png"
                            style="max-width:100%; height:auto; display:block; margin:20px auto;" alt="Schéma ED-X (Suite)"
                            onerror="this.style.display='none'">
                    </div>

                <?php elseif ($isOV): ?>

                    <?php
                    function renderOvEtatRadios($key, $donnees)
                    {
                        $val = $donnees[$key] ?? '';
                        return '<div class="pastille-group">'
                            . pastille($key, 'pc', 'p-na', 'Pas concerné', $val)
                            . pastille($key, 'c', 'p-ok', 'Correct', $val)
                            . pastille($key, 'aa', 'p-aa', 'À améliorer', $val)
                            . pastille($key, 'nc', 'p-nc', 'Pas correct', $val)
                            . pastille($key, 'nr', 'p-nr', 'Nécessite remplacement', $val)
                            . '</div>';
                    }
                    function renderOvRow($label, $key, $donnees)
                    {
                        return '<tr>
                            <td style="font-weight:bold; font-size:11px;">' . htmlspecialchars($label) . '</td>
                            <td style="padding:2px 4px; vertical-align:middle; text-align:center;">' . renderOvEtatRadios($key, $donnees) . '</td>
                            <td style="padding:0;"><textarea name="donnees[' . $key . '_comment]" class="pdf-textarea" style="border:none; width:100%; padding:4px;" oninput="autoGrow(this)">' . htmlspecialchars($donnees[$key . "_comment"] ?? '') . '</textarea>' . photoCamBtn($key, $label) . '</td>
                        </tr>';
                    }
                    ?>

                    <table class="pdf-table controles" style="font-size:11px;">
                        <tr>
                            <th style="width:35%; text-align:center; vertical-align:middle; background:#e0e0e0;">
                                DESIGNATIONS</th>
                            <th style="text-align:center; background:#e0e0e0; font-size:8px; line-height:1.3; padding:4px;">
                                <span style="color:#bbb;">●</span>N/A
                                <span style="color:#28a745;">●</span>OK
                                <span style="color:#e67e22;">●</span>A.A
                                <span style="color:#dc3545;">●</span>N.C
                                <span style="color:#8b0000;">●</span>N.R
                            </th>
                            <th style="width:35%; text-align:center; vertical-align:middle; background:#e0e0e0;">
                                COMMENTAIRES</th>
                        </tr>
                        <tr>
                            <th colspan="3" style="background:#5b9bd5; color:white;">Environnement / Aspect général</th>
                        </tr>
                        <?= renderOvRow("Accès au séparateur", "ov_acces", $donnees) ?>
                        <?= renderOvRow("Etat général du séparateur", "ov_etat_gen", $donnees) ?>

                        <tr>
                            <th colspan="3" style="background:#5b9bd5; color:white;">Partie A - Le séparateur</th>
                        </tr>
                        <?= renderOvRow("Etat de la bande", "ov_bande", $donnees) ?>
                        <?= renderOvRow("Présence des protections latérales", "ov_pres_prot", $donnees) ?>
                        <?= renderOvRow("Etat des protections latérales", "ov_etat_prot", $donnees) ?>
                        <?= renderOvRow("Présences des déflecteurs", "ov_pres_def", $donnees) ?>
                        <?= renderOvRow("Etat des déflecteurs", "ov_etat_def", $donnees) ?>
                        <?= renderOvRow("Etat de la boulonnerie", "ov_boulon", $donnees) ?>
                        <?= renderOvRow("Etat des longerons", "ov_longeron", $donnees) ?>
                        <?= renderOvRow("Etat des câbles et presse-étoupes", "ov_cables", $donnees) ?>
                        <?= renderOvRow("Modèle et état du moteur", "ov_moteur", $donnees) ?>
                        <?= renderOvRow("Etat du bras de couple", "ov_couple", $donnees) ?>
                        <?= renderOvRow("Etat du contrôleur de rotation", "ov_ctrl", $donnees) ?>
                        <?= renderOvRow("Etat des galets anti-déport de bande", "ov_galets", $donnees) ?>
                        <?= renderOvRow("Etat des détecteurs anti-déport de bande", "ov_detect", $donnees) ?>
                        <?= renderOvRow("Etat des paliers PHUSE tendeurs", "ov_pal_phuse", $donnees) ?>
                        <?= renderOvRow("Etat des paliers du tambour motorisé", "ov_pal_mot", $donnees) ?>
                        <?= renderOvRow("Etat du caisson en acier inoxydable", "ov_caisson", $donnees) ?>
                        <?= renderOvRow("Contrôle des connexions dans la boîte à bornes", "ov_conn", $donnees) ?>
                        <?= renderOvRow("Mesure de résistance", "ov_resist", $donnees) ?>
                        <?= renderOvRow("Mesure de l'isolement sous 1000 volts CC", "ov_isol", $donnees) ?>
                        <?= renderOvRow("Option 1 :", "ov_opt1", $donnees) ?>
                        <?= renderOvRow("Option 2 :", "ov_opt2", $donnees) ?>
                    </table>

                    <?= newPdfPage() ?>
                    <table class="pdf-table controles" style="font-size:11px;">
                        <tr>
                            <th colspan="3" style="background:#5b9bd5; color:white;">Partie B - Les performances</th>
                        </tr>
                        <tr>
                            <th style="width:40%; text-align:center; background:#e0e0e0;">DESIGNATIONS</th>
                            <th style="text-align:center; background:#e0e0e0;">ETAT</th>
                            <th style="width:30%; text-align:center; background:#e0e0e0;">COMMENTAIRES</th>
                        </tr>
                        <?php
                        $ovPerfs = [
                            'ov_perf_bille'   => 'Bille diamètre 20',
                            'ov_perf_ecrou'   => 'Ecrou M4',
                            'ov_perf_rond50'  => 'Rond diamètre 6 longueur 50',
                            'ov_perf_rond100' => 'Rond diamètre 6 longueur 100'
                        ];
                        foreach($ovPerfs as $key => $label): ?>
                        <tr>
                            <td style="font-weight:bold; font-size:11px; padding-left:10px;"><?= $label ?></td>
                            <td style="padding:0; vertical-align:middle; text-align:center;">
                                <?= renderAprfEtatRadios($key . "_stat", $donnees) ?>
                            </td>
                            <td style="padding:0;">
                                <textarea name="donnees[<?= $key ?>]" class="pdf-textarea" style="height:30px; border:none; width:100%; box-sizing:border-box; padding:4px;"><?= htmlspecialchars($donnees[$key] ?? "") ?></textarea>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </table>

                    <?= newPdfPage() ?>
                    <table class="pdf-table controles" style="font-size:11px;">
                        <tr>
                            <th colspan="6" style="background:#5b9bd5; color:white;">En présence du client / Rappel des
                                fréquences de nettoyage et des différents points de contrôle</th>
                        </tr>
                        <tr>
                            <th style="width:30%; text-align:center; background:#fff;">Contrôle</th>
                            <th style="text-align:center; background:#fff;">Quotidien</th>
                            <th style="text-align:center; background:#fff;">Hebdomadaire</th>
                            <th style="text-align:center; background:#fff;">Mensuel</th>
                            <th style="text-align:center; background:#fff;">Annuel</th>
                            <th style="text-align:center; width:25%; background:#fff;">Commentaires</th>
                        </tr>
                        <?php
                        function renderFreqRow($label, $key, $donnees)
                        {
                            $v = $donnees[$key] ?? '';
                            return '<tr>
                                <td style="font-weight:bold; font-size:11px;">' . htmlspecialchars($label) . '</td>
                                <td style="text-align:center;"><input type="radio" name="donnees[' . $key . ']" value="q" ' . ($v == 'q' ? 'checked' : '') . '></td>
                                <td style="text-align:center;"><input type="radio" name="donnees[' . $key . ']" value="h" ' . ($v == 'h' ? 'checked' : '') . '></td>
                                <td style="text-align:center;"><input type="radio" name="donnees[' . $key . ']" value="m" ' . ($v == 'm' ? 'checked' : '') . '></td>
                                <td style="text-align:center;"><input type="radio" name="donnees[' . $key . ']" value="a" ' . ($v == 'a' ? 'checked' : '') . '></td>
                                <td style="padding:0;"><textarea name="donnees[' . $key . '_comment]" class="pdf-textarea" style="height:30px; border:none; width:100%; box-sizing:border-box; padding:4px;">' . htmlspecialchars($donnees[$key . "_comment"] ?? '') . '</textarea></td>
                            </tr>';
                        }
                        ?>
                        <?= renderFreqRow("Contrôle visuel de la bande", "ov_freq_bande", $donnees) ?>
                        <?= renderFreqRow("Contrôle visuel des fixations", "ov_freq_fix", $donnees) ?>
                        <?= renderFreqRow("Contrôle visuel des tambours", "ov_freq_tamb", $donnees) ?>
                        <?= renderFreqRow("Graissage des paliers", "ov_freq_graiss", $donnees) ?>
                    </table>

                        <img src="/assets/machines/ovap_diagram.png"
                            style="max-width:100%; height:auto; display:block; margin:20px auto;" alt="Schéma Overband">

                    <!-- Photo section removed here, handled globally at bottom -->

                <?php elseif ($isPAP): ?>

                    <table class="pdf-table controles" style="font-size:11px;">

                        <!-- Section PAP/TAP -->
                        <tr>
                            <th colspan="3" style="background:#5b9bd5; color:white; text-align:left; padding:4px;">PAP/TAP</th>
                        </tr>
                        <?= renderAprfRow("Satisfaction de fonctionnement", "paptap_satisfaction", $donnees) ?>
                        <?= renderAprfRow("Aspect général", "paptap_aspect", $donnees) ?>

                        <!-- Section PRODUIT -->
                        <tr>
                            <th colspan="3" style="background:#5b9bd5; color:white; text-align:left; padding:4px;">PRODUIT</th>
                        </tr>
                        <tr>
                            <td style="padding:4px;">
                                Type de produit : 
                                <input type="text" name="mesures[paptap_produit]" value="<?= htmlspecialchars($mesures['paptap_produit'] ?? '') ?>" class="pdf-input" style="width:100px; border-bottom:1px solid #000;">
                            </td>
                            <td style="border:1px solid #000; text-align:center; padding:0; vertical-align:middle; width:70px;">
                                <?= renderAprfEtatRadios("paptap_produit_stat", $donnees) ?>
                            </td>
                            <td style="padding:4px;">
                                Aciers de <input type="text" name="mesures[paptap_acier_min]" value="<?= htmlspecialchars($mesures['paptap_acier_min'] ?? '') ?>" style="width:30px; border:none; border-bottom:1px solid #000; text-align:center;">
                                à <input type="text" name="mesures[paptap_acier_max]" value="<?= htmlspecialchars($mesures['paptap_acier_max'] ?? '') ?>" style="width:30px; border:none; border-bottom:1px solid #000; text-align:center;"> mm
                            </td>
                        </tr>
                        <tr>
                            <td style="padding:4px;">
                                Granulométrie : 
                                <input type="text" name="mesures[paptap_granu_min]" value="<?= htmlspecialchars($mesures['paptap_granu_min'] ?? '') ?>" style="width:40px; border:none; border-bottom:1px solid #000; text-align:center;"> à 
                                <input type="text" name="mesures[paptap_granu_max]" value="<?= htmlspecialchars($mesures['paptap_granu_max'] ?? '') ?>" style="width:40px; border:none; border-bottom:1px solid #000; text-align:center;"> mm
                            </td>
                            <td style="border:1px solid #000; text-align:center; padding:0; vertical-align:middle;">
                                <?= renderAprfEtatRadios("paptap_granu_stat", $donnees) ?>
                            </td>
                            <td style="padding:0;"><textarea name="donnees[paptap_granu_comment]" class="pdf-textarea" style="border:none; width:100%; padding:4px;"><?= htmlspecialchars($donnees['paptap_granu_comment'] ?? '') ?></textarea></td>
                        </tr>
                        <tr>
                            <td style="padding:4px;">
                                Débit : 
                                <input type="text" name="mesures[paptap_debit]" value="<?= htmlspecialchars($mesures['paptap_debit'] ?? '') ?>" style="width:60px; border:none; border-bottom:1px solid #000; text-align:center;"> t/h
                            </td>
                            <td style="border:1px solid #000; text-align:center; padding:0; vertical-align:middle;">
                                <?= renderAprfEtatRadios("paptap_debit_stat", $donnees) ?>
                            </td>
                            <td style="padding:4px;">
                                Avec densité de <input type="text" name="mesures[paptap_densite]" value="<?= htmlspecialchars($mesures['paptap_densite'] ?? '') ?>" style="width:50px; border:none; border-bottom:1px solid #000; text-align:center;">
                            </td>
                        </tr>
                        <tr>
                            <td style="padding:4px;">
                                Montage sur : Convoyeur / Trémie / Autre :
                            </td>
                            <td style="border:1px solid #000; text-align:center; padding:0; vertical-align:middle;">
                                <?= renderAprfEtatRadios("paptap_montage_stat", $donnees) ?>
                            </td>
                            <td style="padding:0;"><textarea name="donnees[paptap_montage_comment]" class="pdf-textarea" style="border:none; width:100%; padding:4px;" placeholder="Précisez..."><?= htmlspecialchars($donnees['paptap_montage_comment'] ?? '') ?></textarea></td>
                        </tr>

                        <!-- Section MECANIQUE -->
                        <tr>
                            <th colspan="3" style="background:#5b9bd5; color:white; text-align:left; padding:4px;">MECANIQUE</th>
                        </tr>
                        <?= renderAprfRow("Etat d’usure de la virole inox", "paptap_virole", $donnees) ?>
                        <?= renderAprfRow("Revêtement caoutchouc lisse ou losange", "paptap_revetement", $donnees) ?>
                        <?= renderAprfRow("Nombre et taille des tasseaux", "paptap_tasseaux", $donnees) ?>
                        <?= renderAprfRow("Etat de l’arbre d’entrainement", "paptap_arbre", $donnees) ?>
                        <?= renderAprfRow("Etat des paliers et graissage", "paptap_paliers", $donnees) ?>
                        <?= renderAprfRow("Rotation sans difficulté", "paptap_rotation", $donnees) ?>

                        <!-- Section MAGNETIQUE -->
                        <tr>
                            <th colspan="3" style="background:#5b9bd5; color:white; text-align:left; padding:4px;">MAGNETIQUE</th>
                        </tr>
                        <tr>
                            <td style="padding:4px;">Position correcte du circuit (pour TAP)</td>
                            <td style="border:1px solid #000; text-align:center; padding:0; vertical-align:middle;">
                                <?= renderAprfEtatRadios("paptap_pos_circuit_stat", $donnees) ?>
                            </td>
                            <td style="padding:4px;">
                                Réglage : <input type="text" name="mesures[paptap_reglage]" value="<?= htmlspecialchars($mesures['paptap_reglage'] ?? '') ?>" style="width:80px; border:none; border-bottom:1px solid #000; text-align:center;"> °
                            </td>
                        </tr>
                        <?= renderAprfRow("Type de circuit : Agitateur / Linéaire / Croisé", "paptap_type_circuit", $donnees) ?>
                        <?= renderAprfRow("Bon maintien du palier fixe", "paptap_palier_fixe", $donnees) ?>
                        <tr>
                            <td style="padding:4px;">
                                Induction sur la virole : <input type="text" name="mesures[paptap_induction]" value="<?= htmlspecialchars($mesures['paptap_induction'] ?? '') ?>" style="width:60px; border:none; border-bottom:1px solid #000; text-align:center;"> Gauss
                            </td>
                            <td style="border:1px solid #000; text-align:center; padding:0; vertical-align:middle;">
                                <?= renderAprfEtatRadios("paptap_induction_stat", $donnees) ?>
                            </td>
                            <td style="padding:0;"><textarea name="donnees[paptap_induction_comment]" class="pdf-textarea" style="border:none; width:100%; padding:4px;"><?= htmlspecialchars($donnees['paptap_induction_comment'] ?? '') ?></textarea></td>
                        </tr>
                        <?= renderAprfRow("Aimants : Ferrite ou Néodyme", "paptap_aimants", $donnees) ?>
                        <?= renderAprfRow("Présence et position correcte d’un volet de séparation", "paptap_volet", $donnees) ?>
                    </table>

                    <div style="text-align:center; margin-top:20px;">
                        <img src="/assets/machines/Image_TAP-PAP_Lenoir.png" style="max-width:100%; height:auto;" alt="Schémas PAP/TAP">
                    </div>

                <?php elseif ($isLevage): ?>


                    <table class="pdf-table controles" style="font-size:11px; color:black;">
                        <tr>
                            <th rowspan="2" style="width:40%; text-align:center; background:#e0e0e0;">DESIGNATIONS</th>
                            <th style="text-align:center; padding:0; background:#e0e0e0;">ETAT</th>
                            <th rowspan="2" style="width:30%; text-align:center; background:#e0e0e0;">COMMENTAIRES</th>
                        </tr>
                        <tr>
                            <th style="padding:0; background:#e0e0e0;">
                                <table style="width:100%; border-collapse:collapse; text-align:center; font-size:10px;">
                                    <tr>
                                        <td
                                            style="width:33%; border:none; border-right:1px solid #000; padding:2px; font-weight:bold;">
                                            Bon</td>
                                        <td
                                            style="width:34%; border:none; border-right:1px solid #000; padding:2px; font-weight:bold;">
                                            A remp.<br>sous :</td>
                                        <td style="width:33%; border:none; padding:2px; font-weight:bold;">H.S.</td>
                                    </tr>
                                </table>
                            </th>
                        </tr>

                        <tr>
                            <th style="background:#4472c4; color:white; font-weight:bold; padding:4px;" colspan="3">Produit
                                de Levage type : 
                                <select name="mesures[levage_type]" required 
                                    style="background:white; border:1px solid #ccc; color:black; outline:none; font-weight:bold; width:220px; font-size:11px; padding:2px;">
                                    <option value="">-- Choisir le type --</option>
                                    <option value="Electroaimant Circulaire" <?= ($mesures['levage_type'] ?? '') == 'Electroaimant Circulaire' ? 'selected' : '' ?>>Electroaimant Circulaire</option>
                                    <option value="Electroaimant Rectangulaire" <?= ($mesures['levage_type'] ?? '') == 'Electroaimant Rectangulaire' ? 'selected' : '' ?>>Electroaimant Rectangulaire</option>
                                    <option value="Palonnier Fixe" <?= ($mesures['levage_type'] ?? '') == 'Palonnier Fixe' ? 'selected' : '' ?>>Palonnier Fixe</option>
                                    <option value="Palonnier Telescopique" <?= ($mesures['levage_type'] ?? '') == 'Palonnier Telescopique' ? 'selected' : '' ?>>Palonnier Telescopique</option>
                                    <option value="Armoire de Commande" <?= ($mesures['levage_type'] ?? '') == 'Armoire de Commande' ? 'selected' : '' ?>>Armoire de Commande</option>
                                    <option value="Autre / Accessoire" <?= ($mesures['levage_type'] ?? '') == 'Autre / Accessoire' ? 'selected' : '' ?>>Autre / Accessoire</option>
                                </select>
                            </th>
                         </tr>
                        <?= renderAprfRow("Satisfaction de fonctionnement", "levage_satisfaction", $donnees) ?>
                        <?= renderAprfRow("Aspect général", "levage_aspect", $donnees) ?>

                        <!-- Section MECANIQUE -->
                        <tr>
                            <th colspan="3" style="background:#5b9bd5; color:white; text-align:left; padding:4px;">MECANIQUE
                            </th>
                        </tr>
                        <?= renderAprfRow("Planéité des pôles et du noyau", "levage_planeite", $donnees) ?>
                        <?= renderAprfRow("Jeu entre bouclier et pôles", "levage_jeu_bouclier", $donnees) ?>
                        <?= renderAprfRow("Etanchéité de la boite de connexion (Joint/PE)", "levage_etanch_boite", $donnees) ?>
                        <?= renderAprfRow("Maintien du câble par le PE et le collier STAUFF", "levage_maintien_cable", $donnees) ?>
                        <?= renderAprfRow("Etat des vis tenant le couvercle", "levage_etat_vis", $donnees) ?>
                        <?= renderAprfRow("Etat des axes de Levage", "levage_axes", $donnees) ?>
                        <?= renderAprfRow("Etat des chaines", "levage_chaines", $donnees) ?>

                        <!-- Section ELECTRIQUE HORS TENSION -->
                        <tr>
                            <th colspan="3" style="background:#5b9bd5; color:white; text-align:left; padding:4px;">
                                ELECTRIQUE HORS TENSION</th>
                        </tr>
                        <tr>
                            <td style="padding:4px; font-weight:bold;">
                                Isolement sous 1000 Vcc :
                                <input type="text" name="mesures[levage_isolement]"
                                    value="<?= htmlspecialchars($mesures['levage_isolement'] ?? '') ?>" class="pdf-input"
                                    style="width:80px; border-bottom:1px solid #000; text-align:center; margin-left:5px;">
                                M.ohms
                            </td>
                            <td
                                style="border:1px solid #000; text-align:center; padding:0; vertical-align:middle; width:70px;">
                                <?= renderAprfEtatRadios('levage_isolement_stat', $donnees) ?>
                            </td>
                            <td style="padding:0;"><textarea name="donnees[levage_isolement_comment]" class="pdf-textarea"
                                    style="height:30px; border:none; width:100%; box-sizing:border-box; padding:4px;"><?= htmlspecialchars($donnees["levage_isolement_comment"] ?? '') ?></textarea>
                            </td>
                        </tr>
                        <tr>
                            <td style="padding:4px; font-weight:bold;">
                                Résistance à froid :
                                <input type="text" name="mesures[levage_resistance]"
                                    value="<?= htmlspecialchars($mesures['levage_resistance'] ?? '') ?>" class="pdf-input"
                                    style="width:80px; border-bottom:1px solid #000; text-align:center; margin-left:5px;">
                                ohms
                            </td>
                            <td
                                style="border:1px solid #000; text-align:center; padding:0; vertical-align:middle; width:70px;">
                                <?= renderAprfEtatRadios('levage_resistance_stat', $donnees) ?>
                            </td>
                            <td style="padding:0;"><textarea name="donnees[levage_resistance_comment]" class="pdf-textarea"
                                    style="height:30px; border:none; width:100%; box-sizing:border-box; padding:4px;"><?= htmlspecialchars($donnees["levage_resistance_comment"] ?? '') ?></textarea>
                            </td>
                        </tr>
                        <tr>
                            <td style="padding:4px; font-weight:bold;">
                                Température de la carcasse :
                                <input type="text" name="mesures[levage_temp_carcasse]"
                                    value="<?= htmlspecialchars($mesures['levage_temp_carcasse'] ?? '') ?>"
                                    class="pdf-input"
                                    style="width:80px; border-bottom:1px solid #000; text-align:center; margin-left:5px;">
                                °C
                            </td>
                            <td
                                style="border:1px solid #000; text-align:center; padding:0; vertical-align:middle; width:70px;">
                                <?= renderAprfEtatRadios('levage_temp_carcasse_stat', $donnees) ?>
                            </td>
                            <td style="padding:0;"><textarea name="donnees[levage_temp_carcasse_comment]"
                                    class="pdf-textarea"
                                    style="height:30px; border:none; width:100%; box-sizing:border-box; padding:4px;"><?= htmlspecialchars($donnees["levage_temp_carcasse_comment"] ?? '') ?></textarea>
                            </td>
                        </tr>
                        <tr>
                            <td style="padding:4px; font-weight:bold;">
                                Température ambiante :
                                <input type="text" name="mesures[levage_temp_ambiante]"
                                    value="<?= htmlspecialchars($mesures['levage_temp_ambiante'] ?? '') ?>"
                                    class="pdf-input"
                                    style="width:80px; border-bottom:1px solid #000; text-align:center; margin-left:5px;">
                                °C
                            </td>
                            <td
                                style="border:1px solid #000; text-align:center; padding:0; vertical-align:middle; width:70px;">
                                <?= renderAprfEtatRadios('levage_temp_ambiante_stat', $donnees) ?>
                            </td>
                            <td style="padding:0;"><textarea name="donnees[levage_temp_ambiante_comment]"
                                    class="pdf-textarea"
                                    style="height:30px; border:none; width:100%; box-sizing:border-box; padding:4px;"><?= htmlspecialchars($donnees["levage_temp_ambiante_comment"] ?? '') ?></textarea>
                            </td>
                        </tr>
                        <tr>
                            <td style="padding:4px; font-weight:bold;">
                                Electroaimant arrêté depuis
                                <input type="text" name="mesures[levage_arrete_depuis]"
                                    value="<?= htmlspecialchars($mesures['levage_arrete_depuis'] ?? '') ?>"
                                    class="pdf-input"
                                    style="width:60px; border-bottom:1px solid #000; text-align:center; margin-left:5px;"> h
                            </td>
                            <td
                                style="border:1px solid #000; text-align:center; padding:0; vertical-align:middle; width:70px;">
                                <?= renderAprfEtatRadios('levage_arrete_depuis_stat', $donnees) ?>
                            </td>
                            <td style="padding:0;"><textarea name="donnees[levage_arrete_depuis_comment]"
                                    class="pdf-textarea"
                                    style="height:30px; border:none; width:100%; box-sizing:border-box; padding:4px;"><?= htmlspecialchars($donnees["levage_arrete_depuis_comment"] ?? '') ?></textarea>
                            </td>
                        </tr>
                        <?= renderAprfRow("Serrage correcte des bornes", "levage_serrage_bornes", $donnees) ?>

                        <!-- Section ELECTRIQUE SOUS TENSION -->
                        <tr>
                            <th colspan="3" style="background:#5b9bd5; color:white; text-align:left; padding:4px;">
                                ELECTRIQUE SOUS TENSION</th>
                        </tr>
                        <tr>
                            <td style="padding:4px; font-weight:bold;">
                                Tension :
                                <input type="text" name="mesures[levage_tension]"
                                    value="<?= htmlspecialchars($mesures['levage_tension'] ?? '') ?>" class="pdf-input"
                                    style="width:80px; border-bottom:1px solid #000; text-align:center; margin-left:5px;">
                                Vcc
                            </td>
                            <td
                                style="border:1px solid #000; text-align:center; padding:0; vertical-align:middle; width:70px;">
                                <?= renderAprfEtatRadios('levage_tension_stat', $donnees) ?>
                            </td>
                            <td style="padding:0;"><textarea name="donnees[levage_tension_comment]" class="pdf-textarea"
                                    style="height:30px; border:none; width:100%; box-sizing:border-box; padding:4px;"><?= htmlspecialchars($donnees["levage_tension_comment"] ?? '') ?></textarea>
                            </td>
                        </tr>
                        <tr>
                            <td style="padding:4px; font-weight:bold;">
                                Intensité :
                                <input type="text" name="mesures[levage_intensite]"
                                    value="<?= htmlspecialchars($mesures['levage_intensite'] ?? '') ?>" class="pdf-input"
                                    style="width:80px; border-bottom:1px solid #000; text-align:center; margin-left:5px;"> A
                            </td>
                            <td
                                style="border:1px solid #000; text-align:center; padding:0; vertical-align:middle; width:70px;">
                                <?= renderAprfEtatRadios('levage_intensite_stat', $donnees) ?>
                            </td>
                            <td style="padding:0;"><textarea name="donnees[levage_intensite_comment]" class="pdf-textarea"
                                    style="height:30px; border:none; width:100%; box-sizing:border-box; padding:4px;"><?= htmlspecialchars($donnees["levage_intensite_comment"] ?? '') ?></textarea>
                            </td>
                        </tr>
                        <tr>
                            <td style="padding:4px; font-weight:bold;">
                                Champ magnétique au centre du noyau :
                                <input type="text" name="mesures[levage_champ_centre]"
                                    value="<?= htmlspecialchars($mesures['levage_champ_centre'] ?? '') ?>" class="pdf-input"
                                    style="width:80px; border-bottom:1px solid #000; text-align:center; margin-left:5px;">
                                Gauss
                            </td>
                            <td
                                style="border:1px solid #000; text-align:center; padding:0; vertical-align:middle; width:70px;">
                                <?= renderAprfEtatRadios('levage_champ_centre_stat', $donnees) ?>
                            </td>
                            <td style="padding:0;"><textarea name="donnees[levage_champ_centre_comment]"
                                    class="pdf-textarea"
                                    style="height:30px; border:none; width:100%; box-sizing:border-box; padding:4px;"><?= htmlspecialchars($donnees["levage_champ_centre_comment"] ?? '') ?></textarea>
                            </td>
                        </tr>
                        <tr>
                            <td style="padding:4px; font-weight:bold;">
                                Champ magnétique au milieu du pôle :
                                <input type="text" name="mesures[levage_champ_pole]"
                                    value="<?= htmlspecialchars($mesures['levage_champ_pole'] ?? '') ?>" class="pdf-input"
                                    style="width:80px; border-bottom:1px solid #000; text-align:center; margin-left:5px;">
                                Gauss
                            </td>
                            <td
                                style="border:1px solid #000; text-align:center; padding:0; vertical-align:middle; width:70px;">
                                <?= renderAprfEtatRadios('levage_champ_pole_stat', $donnees) ?>
                            </td>
                            <td style="padding:0;"><textarea name="donnees[levage_champ_pole_comment]" class="pdf-textarea"
                                    style="height:30px; border:none; width:100%; box-sizing:border-box; padding:4px;"><?= htmlspecialchars($donnees["levage_champ_pole_comment"] ?? '') ?></textarea>
                            </td>
                        </tr>

                        <!-- Section APPLICATION DU CLIENT -->
                        <tr>
                            <th colspan="3" style="background:#5b9bd5; color:white; text-align:left; padding:4px;">
                                APPLICATION DU CLIENT</th>
                        </tr>
                        <tr>
                            <td style="padding:4px; font-weight:bold;">
                                Type de produit manipulé : Brame / Tôle / Paquets / Coils / Profilés
                            </td>
                            <td style="border:1px solid #000; text-align:center;"></td>
                            <td style="padding:4px; font-weight:bold; text-align:left;">
                                Dimensions :
                                <input type="text" name="mesures[levage_dimensions]"
                                    value="<?= htmlspecialchars($mesures['levage_dimensions'] ?? '') ?>" class="pdf-input"
                                    style="width:120px; border-bottom:1px solid #000; margin-left:5px;">
                            </td>
                        </tr>
                        <tr>
                            <td style="padding:4px; font-weight:bold;">
                                Charge Maxi par aimant :
                                <input type="text" name="mesures[levage_charge_maxi]"
                                    value="<?= htmlspecialchars($mesures['levage_charge_maxi'] ?? '') ?>" class="pdf-input"
                                    style="width:80px; border-bottom:1px solid #000; text-align:center; margin-left:5px;">
                                kg
                            </td>
                            <td style="border:1px solid #000; text-align:center;"></td>
                            <td style="padding:0;"><textarea name="donnees[levage_charge_maxi_comment]" class="pdf-textarea"
                                    style="height:30px; border:none; width:100%; box-sizing:border-box; padding:4px;"><?= htmlspecialchars($donnees["levage_charge_maxi_comment"] ?? '') ?></textarea>
                            </td>
                        </tr>
                        <tr>
                            <td style="padding:4px; font-weight:bold;">
                                Température Maxi des produits :
                                <input type="text" name="mesures[levage_temp_maxi]"
                                    value="<?= htmlspecialchars($mesures['levage_temp_maxi'] ?? '') ?>" class="pdf-input"
                                    style="width:80px; border-bottom:1px solid #000; text-align:center; margin-left:5px;">
                                °C
                            </td>
                            <td style="border:1px solid #000; text-align:center;"></td>
                            <td style="padding:0;"><textarea name="donnees[levage_temp_maxi_comment]" class="pdf-textarea"
                                    style="height:30px; border:none; width:100%; box-sizing:border-box; padding:4px;"><?= htmlspecialchars($donnees["levage_temp_maxi_comment"] ?? '') ?></textarea>
                            </td>
                        </tr>
                        <tr>
                            <td style="padding:4px; font-weight:bold;">
                                Estimation du facteur de marche :
                                <input type="text" name="mesures[levage_facteur_marche]"
                                    value="<?= htmlspecialchars($mesures['levage_facteur_marche'] ?? '') ?>"
                                    class="pdf-input"
                                    style="width:80px; border-bottom:1px solid #000; text-align:center; margin-left:5px;"> %
                            </td>
                            <td style="border:1px solid #000; text-align:center;"></td>
                            <td style="padding:0;"><textarea name="donnees[levage_facteur_marche_comment]"
                                    class="pdf-textarea"
                                    style="height:30px; border:none; width:100%; box-sizing:border-box; padding:4px;"><?= htmlspecialchars($donnees["levage_facteur_marche_comment"] ?? '') ?></textarea>
                            </td>
                        </tr>
                        <tr>
                            <td style="padding:4px; font-weight:bold;">
                                Facteur de service :
                                <input type="text" name="mesures[levage_facteur_service]"
                                    value="<?= htmlspecialchars($mesures['levage_facteur_service'] ?? '') ?>"
                                    class="pdf-input"
                                    style="width:80px; border-bottom:1px solid #000; text-align:center; margin-left:5px;">
                                h/jour
                            </td>
                            <td style="border:1px solid #000; text-align:center;"></td>
                            <td style="padding:0;"><textarea name="donnees[levage_facteur_service_comment]"
                                    style="height:30px; border:none; width:100%; box-sizing:border-box; padding:4px;"><?= htmlspecialchars($donnees["levage_facteur_service_comment"] ?? '') ?></textarea>
                            </td>
                        </tr>
                    </table>

                        <div style="position:relative; width:100%; max-width:650px; margin:20px auto 10px auto;">
                            <!-- Diagram includes Rep section and boxes -->
                            <img src="/assets/machines/levage_diagram.png" 
                                 style="width:100%; height:auto;" 
                                 alt="Circulaire">
                            
                            <!-- Diamètre pôle (83.6% / 23.0%) - Raised slightly to sit just above the line -->
                            <div style="position:absolute; left:83.6%; top:23.0%; transform:translate(-50%, -50%); font-size:9px;">
                                <input type="text" name="mesures[levage_diam_pole]" value="<?= htmlspecialchars($mesures['levage_diam_pole'] ?? '') ?>" class="pdf-input" style="width:55px; border:none; background:transparent; text-align:center; font-size:9px;" autocomplete="off">
                            </div>

                            <!-- Diamètre noyau (85.5% / 27.5%) - Raised slightly more to stop touching the line -->
                            <div style="position:absolute; left:85.5%; top:27.5%; transform:translate(-50%, -50%); font-size:9px;">
                                <input type="text" name="mesures[levage_diam_noyau]" value="<?= htmlspecialchars($mesures['levage_diam_noyau'] ?? '') ?>" class="pdf-input" style="width:55px; border:none; background:transparent; text-align:center; font-size:9px;" autocomplete="off">
                            </div>

                            <!-- Epaisseur pôle (85.6% / 39.5%) - Raised slightly more -->
                            <div style="position:absolute; left:85.6%; top:39.5%; transform:translate(-50%, -50%); font-size:9px;">
                                <input type="text" name="mesures[levage_ep_pole]" value="<?= htmlspecialchars($mesures['levage_ep_pole'] ?? '') ?>" class="pdf-input" style="width:60px; border:none; background:transparent; text-align:center; font-size:9px;" autocomplete="off">
                            </div>

                            <!-- Ø ext/int (5.3% / 45.4%) - Restored border-bottom for these HTML-only fields -->
                            <div style="position:absolute; left:5.3%; top:45.4%; transform:translate(0, -50%); font-size:9px; font-weight:bold; color:#000; line-height:1.2;">
                                Ø ext 2 : <input type="text" name="mesures[levage_ext2]" value="<?= htmlspecialchars($mesures['levage_ext2'] ?? '') ?>" class="pdf-input" style="width:40px; border:none; border-bottom:1px solid #000; background:transparent; font-size:9px;" autocomplete="off"><br>
                                Ø ext 1 : <input type="text" name="mesures[levage_ext1]" value="<?= htmlspecialchars($mesures['levage_ext1'] ?? '') ?>" class="pdf-input" style="width:40px; border:none; border-bottom:1px solid #000; background:transparent; font-size:9px;" autocomplete="off"><br>
                                Ø int 2 : <input type="text" name="mesures[levage_int2]" value="<?= htmlspecialchars($mesures['levage_int2'] ?? '') ?>" class="pdf-input" style="width:40px; border:none; border-bottom:1px solid #000; background:transparent; font-size:9px;" autocomplete="off"><br>
                                Ø int 1 : <input type="text" name="mesures[levage_int1]" value="<?= htmlspecialchars($mesures['levage_int1'] ?? '') ?>" class="pdf-input" style="width:40px; border:none; border-bottom:1px solid #000; background:transparent; font-size:9px;" autocomplete="off">
                            </div>
                        </div>
                        <!-- Handled globally -->

                    <?php else: ?>

                        <!-- GENERIC SCHEMA -->
                        <div
                            style="background:#fff3cd; color:#856404; padding:15px; margin-bottom:20px; font-weight:bold; border:1px solid #ffeeba; text-align:center;">
                            Cette machine (<?= htmlspecialchars($machine['designation']) ?>) n'a pas encore de modèle PDF
                            numérisé sur mesure (comme APRF ou ED-X). Voici la grille générique.
                        </div>

                        <table class="pdf-table controles">
                            <tr>
                                <th style="width:35%; text-align:center; vertical-align:middle; background:#e0e0e0; font-size:11px;">DESIGNATIONS</th>
                                <th class="diagonal-header" style="width:140px;">
                                    <div class="diagonal-wrapper">
                                        <div class="diag-label">Pas concerné</div>
                                        <div class="diag-label">Correct</div>
                                        <div class="diag-label">A améliorer</div>
                                        <div class="diag-label">Pas correct</div>
                                        <div class="diag-label">Nécessite remplacement</div>
                                    </div>
                                </th>
                                <th style="width:35%; text-align:center; vertical-align:middle; background:#e0e0e0; font-size:11px;">COMMENTAIRES / VALEURS</th>
                            </tr>
                            <tr>
                                <th colspan="3" style="background:#ddd;">Examen de l'appareil</th>
                            </tr>
                            <?= renderCheckRow("Fixation de l'appareil", "gen_fixation", $donnees) ?>
                            <?= renderCheckRow("Appareil sale / Nettoyage", "gen_sale", $donnees) ?>
                            <?= renderCheckRow("Usure importante des pièces", "gen_usure", $donnees) ?>

                            <tr>
                                <th colspan="3" style="background:#ddd;">Transmission & Motorisation</th>
                            </tr>
                            <?= renderCheckRow("Tension courroies ou chaînes", "gen_tension", $donnees) ?>
                            <?= renderCheckRow("Alignement pignons / poulies", "gen_align", $donnees) ?>
                            <?= renderCheckRow("Graissage chaîne / Niveau d'huile", "gen_huile", $donnees) ?>
                            <?= renderCheckRow("Échauffement ou Bruit suspect", "gen_bruit", $donnees) ?>

                            <tr>
                                <th colspan="3" style="background:#ddd;">Contrôles électriques & Divers</th>
                            </tr>
                            <?= renderCheckRow("Test déclenchement défauts", "gen_defauts", $donnees) ?>
                            <?= renderCheckRow("Tester bouton Arrêt d'Urgence", "gen_au", $donnees) ?>
                            <?= renderCheckRow("Mesure isolation & Induction", "gen_mesures", $donnees) ?>
                        </table>

                        <?php
                        // Automatiquement charger le schéma s'il existe (pour dépanner)
                        $cleanName = strtolower(preg_replace('/[^a-zA-Z0-9-]/', '', $machine['designation']));
                        // Essayer qq mots clés extraits
                        $possiblePrefixes = explode(' ', strtolower($machine['designation']));
                        $foundSchema = null;
                        $exts = ['png', 'jpg', 'jpeg'];

                        // on fait une petite passe pour trouver l'image
                        foreach (['sga', 'sgcp', 'sgsa', 'slt', 'spm', 'srm', 'levage', 'pap-tap', 'pm', 'ovap'] as $mkey) {
                            if (strpos(strtolower($machine['designation']), $mkey) !== false) {
                                foreach ($exts as $ext) {
                                    $checkPath = __DIR__ . '/../assets/machines/' . $mkey . '_diagram.' . $ext;
                                    if (file_exists($checkPath)) {
                                        $foundSchema = '/assets/machines/' . $mkey . '_diagram.' . $ext;
                                        break 2;
                                    }
                                }
                            }
                        }

                        if ($foundSchema):
                            ?>
                            <div class="pdf-section" style="border:1px solid #000; padding:10px; text-align:center; margin-top:20px;">
                                <div style="font-weight:bold; margin-bottom:10px;">Schéma de Référence (Extrait Word) :</div>
                                <img src="<?= htmlspecialchars($foundSchema) ?>"
                                    style="max-width:100%; height:auto; display:block; margin:0 auto;" alt="Schéma machine">
                            </div>
                        <?php endif; ?>

                    <?php endif; ?>


                        <?php 
                        // --- SECTION B : DESCRIPTION MATERIEL (GLOBAL) ---
                        echo renderSectionB($photosData);

                        // SECTION C & D (uniquement PDF)
                        if (isset($_GET['pdf'])):
                            renderSectionC($isEDX, $isOV);
                            renderSectionD($isEDX, $mesures);
                        endif;
                        ?>

                        <div style="margin-top:20px; border: 1px solid #000; padding:10px; background: #fff; page-break-inside: avoid;">
                            <div style="font-weight:bold; font-size:14px; margin-bottom:5px; color:#d35400;">E) CAUSE DE DYSFONCTIONNEMENT :</div>
                            <?php if (!isset($_GET['pdf'])): ?>
                                <p style="font-size:11px; color:#666; margin-bottom:5px;">Cette zone est pré-remplie avec les points "Non conformes" ou "À améliorer" détectés. Vous pouvez éditer le texte ci-dessous.</p>
                                <textarea name="dysfonctionnements" id="dysfonctionnementsText" class="pdf-textarea" style="min-height:100px; font-size:13px; border: 1px solid #ccc; background:#fff; padding:5px;"><?= htmlspecialchars($machine['dysfonctionnements'] ?? '') ?></textarea>
                                <button type="button" class="btn-ia-action" onclick="generateDysfunctions(this)" style="margin-top:5px; background:#2980b9; color:white; border:none; padding:5px 10px; border-radius:4px; cursor:pointer; font-size:11px;">
                                    <img src="/assets/ai_expert.jpg" style="height: 14px; width: 14px; vertical-align: middle; border-radius:3px; margin-right: 4px;"> Actualiser
                                </button>
                            <?php else: ?>
                                <?php 
                                    $dysText = trim($machine['dysfonctionnements'] ?? '');
                                    if (empty($dysText)) {
                                        $dysText = "Aucun dysfonctionnement majeur signalé.";
                                    }
                                ?>
                                <div style="font-size:13px; white-space: pre-wrap; margin-bottom:10px;"><?= htmlspecialchars($dysText) ?></div>
                                <?php
                                // Affichage des photos liées aux points critiques
                                $criticalPhotos = [];
                                foreach ($donnees as $k => $v) {
                                    if (strpos($k, '_radio') !== false && in_array($v, ['nc', 'nr', 'aa'])) {
                                        $baseKey = str_replace('_radio', '', $k);
                                        if (!empty($photosData[$baseKey])) {
                                            foreach ($photosData[$baseKey] as $p) {
                                                $criticalPhotos[] = $p;
                                            }
                                        }
                                    }
                                }
                                if (!empty($criticalPhotos)): ?>
                                    <div style="display:flex; flex-wrap:wrap; gap:10px; margin-top:10px;">
                                        <?php foreach ($criticalPhotos as $p): ?>
                                            <div style="border:1px solid #000; padding:2px; text-align:center;">
                                                <img src="<?= htmlspecialchars($p['data']) ?>" style="max-height:150px; width:auto;">
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>

                        <div style="margin-top:20px; border: 1px solid #000; padding:10px; background: #fff; page-break-inside: avoid;">
                            <div style="font-weight:bold; font-size:14px; margin-bottom:5px; color:#d35400;">F) CONCLUSION :</div>
                            <?php if (!isset($_GET['pdf'])): ?>
                                <p style="font-size:11px; color:#666; margin-bottom:5px;">Cette conclusion peut être générée par l'IA en fonction des résultats du contrôle.</p>
                                <textarea name="conclusion" id="conclusionText" class="pdf-textarea" style="min-height:80px; font-size:13px; border: 1px solid #ccc; background:#fff; padding:5px;"><?= htmlspecialchars($machine['conclusion'] ?? '') ?></textarea>
                                <button type="button" onclick="generateConclusion(this)" style="margin-top:5px; background:#2980b9; color:white; border:none; padding:5px 10px; border-radius:4px; cursor:pointer; font-size:11px;"><img src="/assets/ai_expert.jpg" style="height:14px; width:14px; vertical-align:middle; border-radius:3px; margin-right:4px;"> Générer par l'Expert IA</button>
                            <?php else: ?>
                                <?php 
                                    $concText = trim($machine['conclusion'] ?? '');
                                    if (empty($concText)) {
                                        $concText = "Votre équipement est conforme à nos standards officiels.";
                                    }
                                ?>
                                <div style="font-size:13px; white-space: pre-wrap; margin-bottom:10px;"><?= htmlspecialchars($concText) ?></div>
                            <?php endif; ?>
                        </div>

                        <?php if (isset($_GET['pdf'])): ?>
                            <div style="margin-top:40px; border-top: 1px solid #ddd; padding-top:15px; page-break-inside: avoid;">
                                <div style="font-weight:bold; font-size:14px; color:#d35400; margin-bottom:15px;">RAPPEL : Le nettoyage de votre <?= $isEDX ? 'EDX' : ($isOV ? 'OV' : 'équipement') ?> doit être régulier et complet (intérieur et extérieur)</div>
                                <table style="width:100%; border-collapse:collapse;">
                                    <tr>
                                        <td style="width:60px; vertical-align:middle; padding-bottom:15px;">
                                            <img src="/assets/hazard/magnet.png" style="height:45px;">
                                        </td>
                                        <td style="font-size:14px; vertical-align:middle; padding-bottom:15px; font-weight:bold;">Attention ! Champ magnétique !</td>
                                    </tr>
                                    <tr>
                                        <td style="width:60px; vertical-align:middle; padding-bottom:15px;">
                                            <img src="/assets/hazard/no_water.png" style="height:45px;">
                                        </td>
                                        <td style="font-size:14px; vertical-align:middle; padding-bottom:15px; font-weight:bold;">Ne pas arroser !</td>
                                    </tr>
                                    <tr>
                                        <td style="width:60px; vertical-align:middle; padding-bottom:15px;">
                                            <img src="/assets/hazard/no_implants.png" style="height:45px;">
                                        </td>
                                        <td style="font-size:14px; vertical-align:middle; padding-bottom:15px; font-weight:bold;">Accès interdit aux porteurs d’implants actifs !</td>
                                    </tr>
                                </table>
                            </div>
                        <?php endif; ?>

                    <div class="pdf-section photos-annexes-wrapper" style="margin-top:20px; page-break-inside: avoid;">
                        <div
                            style="background:#d35400; color:white; font-weight:bold; font-size:12px; padding:5px; border:1px solid #000;">
                            PHOTOS ANNEXES</div>
                        <div id="photosAnnexesGrid"
                            style="border:1px solid #000; border-top:none; padding:10px; min-height:60px; display:flex; flex-wrap:wrap; gap:10px;">
                            <?php if (empty($photosData)): ?>
                                <p style="color:#999; font-size:11px; margin:0;" id="noPhotosMsg" class="no-print-pdf">Aucune photo. Utilisez les boutons 📷 sur chaque ligne de contrôle.</p>
                            <?php else: ?>
                                <?php $photoIndex = 1; ?>
                                <?php foreach ($photosData as $key => $photos): ?>
                                    <?php if ($key === 'desc_materiel') continue; // Déjà affiché en Section B ?>
                                    <?php foreach ($photos as $p): ?>
                                        <div class="photo-annexe-item">
                                            <img src="<?= htmlspecialchars($p['data']) ?>">
                                            <?php 
                                            global $photoLabelsMap;
                                            $label = $photoLabelsMap[$key] ?? '';
                                            if (!$label) {
                                                $label = str_replace('_', ' ', $key);
                                                $label = preg_replace('/edx |ov |aprf |levage /i', '', $label);
                                                $label = str_replace(['comment', 'radio'], '', $label);
                                                $label = ucfirst(trim($label));
                                            }
                                            ?>
                                            <p><strong>Photo <?= $photoIndex++ ?> : <?= htmlspecialchars($label) ?></strong>
                                            <?php if (!empty($p['caption'])): ?>
                                                <br><em><?= htmlspecialchars($p['caption']) ?></em>
                                            <?php endif; ?>
                                            </p>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>

                    <input type="hidden" name="photos_json" id="photosJsonInput" value="">
                    <script>
                        var _photosFromDB = <?= json_encode($photosData ?: new stdClass(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES) ?>;
                        var _photoLabelsMap = <?= json_encode($photoLabelsMap ?? new stdClass(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES) ?>;
                    </script>

                </div> <!-- fin .pdf-page -->
            </div> <!-- fin .mobile-wrapper -->
    </form>

    <!-- Hidden file input for camera capture -->
    <input type="file" id="cameraInput" accept="image/*" capture="environment" style="display:none">

    <script>
        // ========== PHOTO SYSTEM ==========
        var allPhotos = {};
        var currentPhotoKey = '';
        var isUploading = false;

        // Load from safely injected PHP variable
        if (typeof _photosFromDB === 'object' && _photosFromDB !== null && !Array.isArray(_photosFromDB)) {
            allPhotos = _photosFromDB;
        }

        function syncPhotos() {
            document.getElementById('photosJsonInput').value = JSON.stringify(allPhotos);
        }

        // Sync photos to hidden input BEFORE form submit
        (function () {
            var f = document.querySelector('form');
            if (f) f.addEventListener('submit', function () { syncPhotos(); });
        })();

        function capturePhoto(key) {
            currentPhotoKey = key;
            document.getElementById('cameraInput').click();
        }

        // Implémentation de Compressor.js (remplace l'ancienne compression Canvas très lente et buggée sur iOS)
        document.getElementById('cameraInput').addEventListener('change', function (e) {
            if (isUploading) return;
            var file = e.target.files[0];
            if (!file) return;

            isUploading = true;

            // Visual feedback
            const btn = document.querySelector(`.photo-btn[onclick*="${currentPhotoKey}"]`);
            if (btn) {
                btn.innerHTML = '⌛';
                btn.disabled = true;
                btn.style.opacity = '0.5';
                btn.style.pointerEvents = 'none';
            }

            // --- BUG-013: Validation de la taille de l'image ---
            if (file.size < 50 * 1024) { // lowered to 50KB to be safe
                alert("❌ Image rejetée : Le fichier est trop petit. Veuillez prendre une photo nette.");
                if (btn) {
                    btn.innerHTML = '📷';
                    btn.disabled = false;
                    btn.style.opacity = '1';
                    btn.style.pointerEvents = 'auto';
                }
                e.target.value = '';
                isUploading = false;
                return;
            }

            const handleResult = (result) => {
                var reader = new FileReader();
                reader.onloadend = function () {
                    var base64 = reader.result;
                    if (!allPhotos[currentPhotoKey]) allPhotos[currentPhotoKey] = [];
                    
                    var caption = prompt('Commentaire photo (optionnel) :', '') || '';
                    const forbiddenWords = ['nul', 'rien', 'sans', 'na', 'test', 'lorem'];
                    if (forbiddenWords.includes(caption.toLowerCase().trim())) {
                        caption = '';
                    }

                    allPhotos[currentPhotoKey].push({ data: base64, caption: caption });
                    syncPhotos();
                    renderThumbsForKey(currentPhotoKey);
                    renderAnnexes();
                    if (btn) {
                        btn.innerHTML = '📷';
                        btn.disabled = false;
                        btn.style.opacity = '1';
                        btn.style.pointerEvents = 'auto';
                    }
                    isUploading = false;
                };
                reader.readAsDataURL(result);
            };

            if (typeof Compressor === 'undefined') {
                console.warn("Compressor.js non chargé, utilisation du fallback direct.");
                handleResult(file);
            } else {
                new Compressor(file, {
                    quality: 0.7,
                    maxWidth: 1280,
                    checkOrientation: true,
                    success(result) {
                        handleResult(result);
                    },
                    error(err) {
                        console.error("Erreur Compressor :", err.message);
                        handleResult(file); // Fallback even on error
                    }
                });
            }
            e.target.value = '';
            // Safety timeout if something hangs
            setTimeout(() => { if(isUploading) isUploading = false; }, 10000);
        });

        function deletePhoto(key, index) {
            if (!confirm('Supprimer cette photo ?')) return;
            allPhotos[key].splice(index, 1);
            if (allPhotos[key].length === 0) delete allPhotos[key];
            syncPhotos();
            renderThumbsForKey(key);
            renderAnnexes();
        }

        // syncPhotos is defined above

        function renderThumbsForKey(key) {
            var container = document.getElementById('thumbs_' + key);
            if (key === 'desc_materiel') {
                renderDescriptionMontage();
            }
            if (!container) return;
            container.innerHTML = '';
            var photos = allPhotos[key] || [];
            photos.forEach(function (p, i) {
                var wrap = document.createElement('span');
                wrap.className = 'photo-thumb-wrap';
                wrap.innerHTML = '<img src="' + p.data + '" title="' + (p.caption || '') + '">' +
                    '<button type="button" class="photo-del" onclick="deletePhoto(\'' + key + '\',' + i + ')">×</button>';
                container.appendChild(wrap);
            });
        }

        function renderDescriptionMontage() {
            var container = document.getElementById('description_materiel_montage');
            if (!container) return;
            
            var photos = allPhotos['desc_materiel'] || [];
            var count = photos.length;
            
            if (count === 0) {
                container.innerHTML = `
                    <div class="photo-montage-grid empty">
                        <div class="photo-placeholder">
                            <span>📸</span>
                            <p>En attente de photo du matériel (max 4)...</p>
                            <button type="button" class="photo-btn" onclick="capturePhoto('desc_materiel')">➕ AJOUTER PHOTO</button>
                        </div>
                    </div>`;
                return;
            }
            
            var gridClass = 'grid-' + (count > 4 ? 4 : count);
            var html = `<div class="photo-montage-grid ${gridClass}">`;
            
            photos.slice(0, 4).forEach(function(p, i) {
                html += `
                    <div class="montage-item">
                        <img src="${p.data}" alt="Photo Matériel ${i+1}">
                        <button type="button" class="photo-del-overlay no-print-pdf" onclick="deletePhoto('desc_materiel', ${i})">×</button>
                    </div>`;
            });
            
            html += '</div>';
            
            // Si moins de 4, on affiche quand même le bouton d'ajout en dessous
            if (count < 4) {
                html += `<div style="text-align:center; margin-top:10px;">
                    <button type="button" class="photo-btn" onclick="capturePhoto('desc_materiel')" style="padding:6px 12px; font-size:12px;">
                        <span>📷</span> Ajouter une photo (${count}/4)
                    </button>
                </div>`;
            }
            
            container.innerHTML = html;
        }

        function renderAnnexes() {
            var grid = document.getElementById('photosAnnexesGrid');
            var msg = document.getElementById('noPhotosMsg');
            grid.innerHTML = '';
            var hasPhotos = false;
            var keys = Object.keys(allPhotos);
            var photoIndex = 1;
            keys.forEach(function (key) {
                if (key === 'desc_materiel') return; // Skip in annexes since it has its own section
                allPhotos[key].forEach(function (p) {
                    hasPhotos = true;
                    var item = document.createElement('div');
                    item.className = 'photo-annexe-item';
                    
                    var label = _photoLabelsMap[key] || '';
                    if (!label) {
                        label = key.replace(/_/g, ' ').replace(/edx |ov |aprf |levage /gi, '').replace(/comment|radio/g, '').trim();
                        label = label.charAt(0).toUpperCase() + label.slice(1);
                    }
                    
                    item.innerHTML = '<img src="' + p.data + '">' +
                        '<p><strong>Photo ' + photoIndex + ' : ' + label + '</strong>' + (p.caption ? '<br><em>' + p.caption + '</em>' : '') + '</p>';
                    grid.appendChild(item);
                    photoIndex++;
                });
            });
            if (!hasPhotos) {
                grid.innerHTML = '<p style="color:#999; font-size:11px; margin:0;" id="noPhotosMsg">Aucune photo.</p>';
            }
        }

        // ========== TEXTAREA AUTO-GROW ==========
        function autoGrow(el) {
            el.style.height = 'auto';
            el.style.height = el.scrollHeight + 'px';
        }
        // ========== SECTION E & F GENERATION IA ==========
        async function generateConclusion(btn) {
            const originalText = btn.innerHTML;
            btn.innerHTML = '⏳ Analyse expert...';
            btn.disabled = true;

            try {
                const form = document.getElementById('machineForm');
                const formData = new FormData(form);
                const res = await fetch(`generate_ia.php?type=F&id=<?= $id ?>`, {
                    method: 'POST',
                    body: formData
                });
                const data = await res.json();
                if (data.content) {
                    const textNode = document.getElementById('conclusionText');
                    textNode.value = data.content;
                    autoGrow(textNode);
                } else {
                    alert('Erreur IA : ' + (data.error || 'Indisponible'));
                }
            } catch (e) {
                alert('Erreur de connexion à l\'API IA.');
            } finally {
                btn.innerHTML = originalText;
                btn.disabled = false;
            }
        }

        async function generateDysfunctions(btn) {
            const originalText = btn.innerHTML;
            const textarea = document.getElementById('dysfonctionnementsText');
            
            if (textarea.value && textarea.value !== "Aucun dysfonctionnement majeur signalé." && !confirm("Voulez-vous écraser le contenu actuel par l'analyse IA ?")) return;
            
            btn.innerHTML = '⏳ Analyse expert...';
            btn.disabled = true;

            try {
                const form = document.getElementById('machineForm');
                const formData = new FormData(form);
                const res = await fetch(`generate_ia.php?type=E&id=<?= $id ?>`, {
                    method: 'POST',
                    body: formData
                });
                const data = await res.json();
                if (data.content) {
                    textarea.value = data.content;
                    autoGrow(textarea);
                } else {
                    alert('Erreur IA : ' + (data.error || 'Indisponible'));
                }
            } catch (e) {
                alert('Erreur de connexion à l\'API IA.');
            } finally {
                btn.innerHTML = originalText;
                btn.disabled = false;
            }
        }

        async function refreshIA(type) {
            const btn = event.currentTarget;
            const originalHTML = btn.innerHTML;
            btn.disabled = true;
            btn.innerHTML = '⏳...';

            try {
                if (type === 'E') {
                    await generateDysfunctions();
                } else if (type === 'F') {
                    await generateConclusion();
                }
            } catch (e) {
                console.error("Erreur refreshIA:", e);
            } finally {
                btn.disabled = false;
                btn.innerHTML = originalHTML;
            }
        }
        // ========== GOMMETTES (PASTILLES) & APPUI LONG POUR EFFACER ==========
        let pastillePressTimer;
        function startPastillePress(e) {
            const label = e.target.closest('.pastille-group label');
            if (!label) return;
            clearTimeout(pastillePressTimer);
            pastillePressTimer = setTimeout(() => {
                const group = label.closest('.pastille-group');
                group.querySelectorAll('label').forEach(l => l.classList.remove('selected'));
                group.querySelectorAll('input[type="radio"]').forEach(r => {
                    r.checked = false;
                    // Trigger change if needed for frequency alerts
                    r.dispatchEvent(new Event('change', { bubbles: true }));
                });
                // Feedback visuel
                label.style.transition = 'transform 0.2s';
                label.style.transform = 'scale(1.5) rotate(15deg)';
                setTimeout(() => { label.style.transform = ''; }, 300);
                if (navigator.vibrate) navigator.vibrate([50, 30, 50]);
                label.dataset.longPressed = 'true';
                setTimeout(() => { delete label.dataset.longPressed; }, 500);
            }, 2000); // 2 secondes
        }
        function cancelPastillePress() { clearTimeout(pastillePressTimer); }

        document.addEventListener('mousedown', startPastillePress);
        document.addEventListener('touchstart', startPastillePress, { passive: true });
        document.addEventListener('mouseup', cancelPastillePress);
        document.addEventListener('touchend', cancelPastillePress);
        document.addEventListener('touchmove', cancelPastillePress);

        document.addEventListener('click', function (e) {
            var label = e.target.closest('.pastille-group label');
            if (!label || label.dataset.longPressed === 'true') return;
            var group = label.closest('.pastille-group');
            group.querySelectorAll('label').forEach(function (l) { l.classList.remove('selected'); });
            label.classList.add('selected');
            var radio = label.querySelector('input[type="radio"]');
            if (radio) radio.checked = true;
        });

        // ========== CHRONOMÈTRE & TEMPS ==========
        var chronoRunning = false;

        function toggleChrono() {
            var btn = document.getElementById('btnChrono');
            var debutInput = document.getElementById('heureDebut');
            var finInput = document.getElementById('heureFin');
            if (!chronoRunning) {
                // Start: set heure_debut to now
                var now = new Date();
                debutInput.value = pad2(now.getHours()) + ':' + pad2(now.getMinutes());
                finInput.value = '';
                btn.textContent = '⏹ Stop';
                btn.style.background = '#dc3545';
                chronoRunning = true;
                calcTemps();
            } else {
                // Stop: set heure_fin to now
                var now2 = new Date();
                finInput.value = pad2(now2.getHours()) + ':' + pad2(now2.getMinutes());
                btn.textContent = '▶ Chrono';
                btn.style.background = '#28a745';
                chronoRunning = false;
                calcTemps();
            }
        }

        function pad2(n) { return n < 10 ? '0' + n : '' + n; }

        function calcTemps() {
            var d = document.getElementById('heureDebut').value;
            var f = document.getElementById('heureFin').value;
            var span = document.getElementById('tempsCalc');
            var realInput = document.getElementById('inputTempsRealise');
            
            if (d && f) {
                var dp = d.split(':'), fp = f.split(':');
                var mins = (parseInt(fp[0]) * 60 + parseInt(fp[1])) - (parseInt(dp[0]) * 60 + parseInt(dp[1]));
                if (mins <= 0) {
                    if (mins === 0 && d !== '') {
                        // Just silence if empty
                    } else if (mins < 0) {
                        mins += 1440; // Over Midnight
                    }
                }
                
                var h = Math.floor(mins / 60), m = mins % 60;
                var formatted = h + (m > 0 ? '.' + Math.round((m/60)*100)/100 : ''); // Decimal format as requested by user often in these SaaS
                // But wait, the previous format was hHmm. Let's stick to a readable format or what they had.
                // The user said "Met bien les valeurs de chaque fiche genre les valeur du temps prévisionnel dans le tableau du T.Prévu"
                // Temps prévisionnel is "1h", "3h30", etc.
                var display = h + 'h' + pad2(m);
                span.textContent = '= ' + display;
                
                if (realInput && !chronoRunning) {
                    realInput.value = (h + (m/60)).toFixed(1).replace('.0', '');
                    // Update: The user might prefer decimal for the input but hHmm for display.
                }
            } else if (d && chronoRunning) {
                // Live display
                var now = new Date();
                var dp2 = d.split(':');
                var mins2 = (now.getHours() * 60 + now.getMinutes()) - (parseInt(dp2[0]) * 60 + parseInt(dp2[1]));
                if (mins2 < 0) mins2 += 1440;
                var h2 = Math.floor(mins2 / 60), m2 = mins2 % 60;
                span.textContent = '⏱ ' + h2 + 'h' + pad2(m2);
                if (realInput) realInput.value = (h2 + (m2/60)).toFixed(1).replace('.0', '');
            } else {
                span.textContent = '';
                // Don't clear realInput if manually filled
            }
        }

        // ========== INIT ON PAGE LOAD ==========
        document.addEventListener('DOMContentLoaded', function () {
            // Pastilles
            document.querySelectorAll('.pastille-group input[type="radio"]:checked').forEach(function (r) {
                r.closest('label').classList.add('selected');
            });
            // Auto-grow
            document.querySelectorAll('.pdf-textarea').forEach(function (ta) {
                if (ta.value) autoGrow(ta);
                ta.addEventListener('input', function () { autoGrow(this); });
            });
            // Photos
            Object.keys(allPhotos).forEach(function (key) {
                renderThumbsForKey(key);
            });
            renderDescriptionMontage();
            renderAnnexes();
            syncPhotos();

            // Auto-set heure_debut to now if empty
            var debutInput = document.getElementById('heureDebut');
            if (debutInput && !debutInput.value) {
                var now = new Date();
                debutInput.value = pad2(now.getHours()) + ':' + pad2(now.getMinutes());
            }

            // Calc temps on change
            var hd = document.getElementById('heureDebut');
            var hf = document.getElementById('heureFin');
            if (hd) hd.addEventListener('change', calcTemps);
            if (hf) hf.addEventListener('change', calcTemps);
            calcTemps();

            // Live chrono update every 30s
            setInterval(function () { if (chronoRunning) calcTemps(); }, 30000);

            // --- BUG-020: Alerte si divergence des fréquences recommandées ---
            const RECO_FREQ = <?= json_encode($recoFreq) ?>;
            document.querySelectorAll('input[type="radio"]').forEach(function(radio) {
                radio.addEventListener('change', function() {
                    const nameAttr = this.getAttribute('name');
                    const match = nameAttr.match(/donnees\[(.*?)\]/);
                    if (match && RECO_FREQ[match[1]]) {
                        const recoVal = RECO_FREQ[match[1]];
                        if (this.value !== recoVal) {
                            const labels = { q: 'Quotidien', h: 'Hebdomadaire', m: 'Mensuel', a: 'Annuel' };
                            alert("⚠️ Recommandation Lenoir-Mec :\nLa fréquence préconisée pour ce contrôle est : " + labels[recoVal] + ".\nVous avez sélectionné : " + labels[this.value] + ".");
                        }
                    }
                });
            });
        });
    </script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/compressorjs/1.2.1/compressor.min.js"></script>
</body>

</html>