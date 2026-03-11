<?php
require_once __DIR__ . '/../includes/config.php';
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
        $commentaire = trim($_POST['commentaires'] ?? '');
        $postDonnees = $_POST['donnees'] ?? [];
        $postMesures = $_POST['mesures'] ?? [];
        $postPhotos = $_POST['photos_json'] ?? '{}';

        $db->prepare('UPDATE machines SET numero_of = ?, commentaires = ?, donnees_controle = ?, mesures = ?, photos = ? WHERE id = ?')
            ->execute([$of, $commentaire, json_encode($postDonnees), json_encode($postMesures), $postPhotos, $id]);

        header('Location: intervention_edit.php?id=' . $machine['intervention_id'] . '&msg=saved');
        exit;
    }
}

$designation = strtoupper(trim($machine['designation']));
$isAPRF = strpos($designation, 'APRF') !== false || strpos($designation, 'APRM') !== false;
$isEDX = strpos($designation, 'ED-X') !== false || strpos($designation, 'EDX') !== false || strpos($designation, 'FOUCAULT') !== false;
$isOV = strpos($designation, 'OV') !== false && strpos($designation, 'ROUE') === false;
$isLevage = strpos($designation, 'LEVAGE') !== false || strpos($designation, 'AIMANT') !== false;
$isPAP = strpos($designation, 'PAP') !== false || strpos($designation, 'TAP') !== false;

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
            display: inline-flex;
            gap: 4px;
            align-items: center;
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
                page-break-after: always;
                padding: 0;
                border-radius: 0;
            }

            .pdf-page:last-child {
                page-break-after: auto;
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
    </style>
</head>

<body>

    <form method="POST" id="machineForm">
        <input type="hidden" name="action" value="save_machine">
        <?= csrfField() ?>

        <div class="top-bar">
            <button type="button" class="btn btn-ghost"
                onclick="window.location.href='intervention_edit.php?id=<?= $machine['intervention_id'] ?>'"
                style="color:white; border-color:white;">← REVENIR</button>
            <div style="display:flex; gap:10px;">
                <button type="button" class="btn btn-ghost" onclick="window.print()"
                    style="background:#2b2d31; color:white; border:1px solid #444;">🖨️ IMPRIMER / PDF</button>
                <button type="submit" class="btn btn-primary" style="background:#e6b12a; color:#000;">ENREGISTRER LA
                    FICHE</button>
            </div>
        </div>

        <div class="mobile-wrapper">
            <?php
            ob_start();
            ?>
            <!-- Header exact LENOIR -->
            <table style="width:100%; border-collapse:collapse; border:1px solid #000; margin-bottom:15px; color:#000;">
                <tr>
                    <td style="width:40%; border-right:1px solid #000; padding:15px; vertical-align:bottom;">
                        <img src="/assets/lenoir_logo_doc.png" alt="LENOIR-MEC"
                            style="max-width:220px; display:block; margin-bottom:30px;">
                        <div style="text-align:right; font-weight:bold; color:#0070c0; font-size:16px;">
                            Poste<input type="text" name="mesures[poste]"
                                value="<?= htmlspecialchars($mesures['poste'] ?? '') ?>"
                                style="width:100px; border:none; border-bottom:1px solid #0070c0; outline:none; color:#0070c0; background:transparent; font-weight:bold;">
                        </div>
                    </td>
                    <td style="width:60%; text-align:center; vertical-align:middle;">
                        <span style="font-size:26px; font-weight:bold; color:#000;">
                            <?= $isAPRF ? 'Aimant permanent rectangulaire fixe APRF' : ($isEDX ? 'Séparateur à courants de foucault ED-X' : ($isOV ? 'Overband Electromagnétique OV' : ($isLevage ? 'Electroaimants de Levage' : htmlspecialchars($machine['designation'])))) ?>
                        </span>
                    </td>
                </tr>
            </table>

            <table
                style="width:100%; border-collapse:collapse; border:1px solid #000; margin-bottom:20px; font-size:13px; color:#000;">
                <tr>
                    <td style="width:15%; font-weight:bold; border:1px solid #000; padding:6px; background:#d9d9d9;">N°
                        A.R.C.</td>
                    <td style="width:35%; border:1px solid #000; padding:6px; font-family:Courier, monospace;">
                        <?= htmlspecialchars($machine['numero_arc']) ?>
                    </td>
                    <td style="width:15%; font-weight:bold; border:1px solid #000; padding:6px; background:#d9d9d9;">
                        Repère</td>
                    <td style="width:35%; border:1px solid #000; padding:6px;">
                        <input type="text" name="mesures[repere]"
                            value="<?= htmlspecialchars($mesures['repere'] ?? '') ?>" class="pdf-input">
                    </td>
                </tr>
                <tr>
                    <td style="font-weight:bold; border:1px solid #000; padding:6px; background:#d9d9d9;">N° O.F.</td>
                    <td style="border:1px solid #000; padding:6px;">
                        <input type="text" name="numero_of" value="<?= htmlspecialchars($machine['numero_of']) ?>"
                            class="pdf-input">
                    </td>
                    <td style="font-weight:bold; border:1px solid #000; padding:6px; background:#d9d9d9;">Désignation
                    </td>
                    <td style="border:1px solid #000; padding:6px; font-weight:bold;">
                        <?= htmlspecialchars($machine['designation']) ?>
                    </td>
                </tr>
                <tr>
                    <td style="font-weight:bold; border:1px solid #000; padding:6px; background:#d9d9d9;">Date</td>
                    <td style="border:1px solid #000; padding:6px;">
                        <input type="text" name="mesures[date_intervention]"
                            value="<?= htmlspecialchars($mesures['date_intervention'] ?? $dateIntervention) ?>"
                            class="pdf-input" placeholder="DD/MM/YYYY" style="width:85px;">
                    </td>
                    <td style="font-weight:bold; border:1px solid #000; padding:6px; background:#d9d9d9;">T. prévu</td>
                    <td style="border:1px solid #000; padding:6px; font-weight:bold; color:#0070c0;">
                        <?= $tempsPrev ?>
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
                        <span id="tempsCalc" style="font-weight:bold; color:#0070c0; font-size:14px;"></span>
                        <button type="button" id="btnChrono" onclick="toggleChrono()"
                            style="background:#28a745; color:white; border:none; border-radius:4px; padding:3px 10px; font-size:11px; cursor:pointer; margin-left:8px; vertical-align:middle;">▶
                            Chrono</button>
                    </td>
                </tr>
            </table>
            <?php
            $pdfHeader = ob_get_clean();
            function newPdfPage()
            {
                return '</div><div class="pdf-page" style="padding-top:2cm;">';
            }
            ?>
            <div class="pdf-page">
                <?= $pdfHeader ?>

                <?php
                // === PASTILLE HELPERS ===
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
                function photoCamBtn($key)
                {
                    return '<div style="display:flex; align-items:center; gap:4px; margin-top:2px;">
                        <button type="button" class="photo-btn" onclick="capturePhoto(\'' . $key . '\')">📷</button>
                        <span class="photo-thumbs" id="thumbs_' . $key . '"></span>
                    </div>';
                }
                function renderCheckRow($label, $key, $donnees)
                {
                    return '<tr>
                        <td style="font-weight:bold; font-size:12px;">' . htmlspecialchars($label) . '</td>
                        <td class="col-etat" style="text-align:center;">' . renderEtatRadios($key . "_radio", $donnees) . '</td>
                        <td class="col-comment"><textarea name="donnees[' . $key . '_comment]" class="pdf-textarea" placeholder="Détails..." oninput="autoGrow(this)">' . htmlspecialchars($donnees[$key . "_comment"] ?? '') . '</textarea>' . photoCamBtn($key) . '</td>
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
                        <td style="padding:0;"><textarea name="donnees[' . $key . '_comment]" class="pdf-textarea" style="border:none; width:100%; padding:4px;" oninput="autoGrow(this)">' . htmlspecialchars($donnees[$key . "_comment"] ?? '') . '</textarea>' . photoCamBtn($key) . '</td>
                    </tr>';
                }
                ?>

                <!-- DYNAMIC CONTENT DEPENDING ON MACHINE TYPE -->
                <?php if ($isAPRF): ?>

                    <div style="display:flex; justify-content:space-between; margin-bottom:15px; padding:10px;">
                        <div><strong>Temps prévisionnel :</strong> 1h</div>
                        <div><strong>Temps réalisé :</strong> <input type="text" class="pdf-input"
                                style="width:80px; text-align:center; border-bottom:1px solid #000;"
                                name="mesures[temps_realise]"
                                value="<?= htmlspecialchars($mesures['temps_realise'] ?? '') ?>"> h</div>
                    </div>



                    <table class="pdf-table" style="font-size:11px;">
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
                                            A remplacer<br>sous :</td>
                                        <td style="width:33%; border:none; padding:2px; font-weight:bold;">H.S.</td>
                                    </tr>
                                </table>
                            </th>
                        </tr>

                        <tr>
                            <th colspan="3" style="background:#5b9bd5; color:white;">Aimants permanent fixe de triage type
                                APRF</th>
                        </tr>
                        <?= renderAprfRow("Satisfaction de fonctionnement", "aprf_satisfaction", $donnees) ?>
                        <?= renderAprfRow("État et type de la bande", "aprf_bande", $donnees) ?>
                        <?= renderAprfRow("État des réglettes", "aprf_reglettes", $donnees) ?>
                        <?= renderAprfRow("État des boutons étoile :", "aprf_boutons", $donnees) ?>
                        <?= renderAprfRow("Options (à préciser)", "aprf_options", $donnees) ?>

                        <tr>
                            <th colspan="3" style="background:#5b9bd5; color:white;">AIMANT PERMANENT</th>
                        </tr>
                        <?= renderAprfRow("Caisson Inox", "aprf_inox", $donnees) ?>

                        <tr>
                        <tr>
                            <td style="vertical-align:top; font-size:11px;">
                                Contrôle de l'attraction sur échantillon<br><br>
                                Bille diamètre 20<br>
                                Écrou M4<br>
                                Rond diamètre 6 Lg 50<br>
                                Rond diamètre 6 Lg 100
                            </td>
                            <td style="padding:0; vertical-align:middle;">
                                <?= renderAprfEtatRadios("aprf_attraction", $donnees) ?>
                            </td>
                            <td style="padding:0;"><textarea name="donnees[aprf_attraction_comment]" class="pdf-textarea"
                                    style="height:120px; border:none; width:100%; box-sizing:border-box; padding:4px;"><?= htmlspecialchars($donnees["aprf_attraction_comment"] ?? '') ?></textarea>
                            </td>
                        </tr>

                        <tr>
                            <th colspan="3" style="background:#5b9bd5; color:white;">APPLICATION CLIENT</th>
                        </tr>
                        <tr>
                            <td style="font-weight:bold; padding:8px;">Type de produit : <input type="text"
                                    class="pdf-input" style="width:auto;" name="mesures[produit]"
                                    value="<?= htmlspecialchars($mesures['produit'] ?? '') ?>"></td>
                            <td style="padding:0; vertical-align:middle;"><?= renderAprfEtatRadios("aprf_prod", $donnees) ?>
                            </td>
                            <td style="padding:0;"><textarea name="donnees[aprf_prod_comment]" class="pdf-textarea"
                                    style="height:30px; border:none; width:100%; box-sizing:border-box; padding:4px;"><?= htmlspecialchars($donnees["aprf_prod_comment"] ?? '') ?></textarea>
                            </td>
                        </tr>
                        <tr>
                            <td style="font-weight:bold; padding:8px;">Granulométrie : <input type="text" class="pdf-input"
                                    style="width:40px; text-align:center;" name="mesures[granulometrie]"
                                    value="<?= htmlspecialchars($mesures['granulometrie'] ?? '') ?>"> mm</td>
                            <td style="padding:0; vertical-align:middle;">
                                <?= renderAprfEtatRadios("aprf_granu", $donnees) ?>
                            </td>
                            <td style="padding:0;"><textarea name="donnees[aprf_granu_comment]" class="pdf-textarea"
                                    style="height:30px; border:none; width:100%; box-sizing:border-box; padding:4px;"><?= htmlspecialchars($donnees["aprf_granu_comment"] ?? '') ?></textarea>
                            </td>
                        </tr>
                        <tr>
                            <td style="font-weight:bold; padding:8px;">Distance aimants / bande : <input type="text"
                                    class="pdf-input" style="width:40px; text-align:center;" name="mesures[distance]"
                                    value="<?= htmlspecialchars($mesures['distance'] ?? '') ?>"> mm</td>
                            <td style="padding:0; vertical-align:middle;"><?= renderAprfEtatRadios("aprf_dist", $donnees) ?>
                            </td>
                            <td style="padding:0;"><textarea name="donnees[aprf_dist_comment]" class="pdf-textarea"
                                    style="height:30px; border:none; width:100%; box-sizing:border-box; padding:4px;"><?= htmlspecialchars($donnees["aprf_dist_comment"] ?? '') ?></textarea>
                            </td>
                        </tr>
                        <tr>
                            <td style="font-weight:bold; padding:8px;">Hauteur de la couche : <input type="text"
                                    class="pdf-input" style="width:40px; text-align:center;" name="mesures[hauteur]"
                                    value="<?= htmlspecialchars($mesures['hauteur'] ?? '') ?>"> mm</td>
                            <td style="padding:0; vertical-align:middle;"><?= renderAprfEtatRadios("aprf_haut", $donnees) ?>
                            </td>
                            <td style="padding:0;"><textarea name="donnees[aprf_haut_comment]" class="pdf-textarea"
                                    style="height:30px; border:none; width:100%; box-sizing:border-box; padding:4px;"><?= htmlspecialchars($donnees["aprf_haut_comment"] ?? '') ?></textarea>
                            </td>
                        </tr>
                        <tr>
                            <td style="font-weight:bold; padding:8px;">Débit : <input type="text" class="pdf-input"
                                    style="width:50px; text-align:center;" name="mesures[debit]"
                                    value="<?= htmlspecialchars($mesures['debit'] ?? '') ?>"> t/h</td>
                            <td style="padding:0; vertical-align:middle;">
                                <?= renderAprfEtatRadios("aprf_debit", $donnees) ?>
                            </td>
                            <td style="padding:4px;">Avec densité de <input type="text" class="pdf-input"
                                    style="width:50px; text-align:center;" name="mesures[densite]"
                                    value="<?= htmlspecialchars($mesures['densite'] ?? '') ?>"></td>
                        </tr>
                    </table>

                    <div style="margin-top:20px;"></div>
                    <div
                        style="background:#5b9bd5; color:white; font-weight:bold; font-size:12px; padding:5px; margin-top:0; border:1px solid #000;">
                        PHOTOS ANNEXES :</div>

                    <img src="/assets/machines/aprf_diagram.png"
                        style="max-width:100%; height:auto; display:block; margin:20px auto;" alt="Schéma APRF"
                        onerror="this.style.display=\'none\'">

                    <!-- EDX SCHEMA -->
                <?php elseif ($isEDX): ?>

                    <div style="display:flex; justify-content:space-between; margin-bottom:15px; padding:10px;">
                        <div><strong>Temps prévisionnel :</strong> 3,5h</div>
                        <div><strong>Temps réalisé :</strong> <input type="text" class="pdf-input"
                                style="width:80px; text-align:center; border-bottom:1px solid #000;"
                                name="mesures[temps_realise]"
                                value="<?= htmlspecialchars($mesures['temps_realise'] ?? '') ?>"> h</div>
                    </div>

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
                            <td style="padding:0;"><textarea name="donnees[' . $key . '_comment]" class="pdf-textarea" style="border:none; width:100%; padding:4px;" oninput="autoGrow(this)">' . htmlspecialchars($donnees[$key . "_comment"] ?? '') . '</textarea>' . photoCamBtn($key) . '</td>
                        </tr>';
                    }
                    ?>

                    <table class="pdf-table" style="font-size:11px;">
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
                    <table class="pdf-table" style="font-size:11px;">
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

                    <div style="margin-top:20px; font-weight:bold; font-size:11px;">Commentaire général :</div>
                    <textarea name="commentaires" class="pdf-textarea"
                        style="height:100px; padding:5px; margin-top:5px; border:1px solid #000; width:100%; box-sizing:border-box;"><?= htmlspecialchars($machine['commentaires']) ?></textarea>

                    <table class="pdf-table" style="font-size:11px; margin-top:20px;">
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
                                <td style="padding:0;"><textarea name="donnees[' . $key . '_comment]" class="pdf-textarea" style="height:30px; border:none; width:100%; box-sizing:border-box; padding:4px;"></textarea></td>
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

                    <div style="margin-top:20px;"></div>
                    <div
                        style="background:#5b9bd5; color:white; font-weight:bold; font-size:12px; padding:5px; margin-top:0px; border:1px solid #000;">
                        PHOTOS ANNEXES :</div>

                    <img src="/assets/machines/edx_diagram.png"
                        style="max-width:100%; height:auto; display:block; margin:20px auto;" alt="Schéma ED-X"
                        onerror="this.style.display='none'">

                    <img src="/assets/machines/edx_diagram_2.png"
                        style="max-width:100%; height:auto; display:block; margin:20px auto;" alt="Schéma ED-X (Suite)"
                        onerror="this.style.display='none'">

                <?php elseif ($isOV): ?>
                    <div style="display:flex; justify-content:space-between; margin-bottom:15px; padding:10px;">
                        <div><strong>Temps prévisionnel :</strong> 1,5h</div>
                        <div><strong>Temps réalisé :</strong> <input type="text" class="pdf-input"
                                style="width:80px; text-align:center; border-bottom:1px solid #000;"
                                name="mesures[temps_realise]"
                                value="<?= htmlspecialchars($mesures['temps_realise'] ?? '') ?>"> h</div>
                    </div>

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
                            <td style="padding:0;"><textarea name="donnees[' . $key . '_comment]" class="pdf-textarea" style="border:none; width:100%; padding:4px;" oninput="autoGrow(this)">' . htmlspecialchars($donnees[$key . "_comment"] ?? '') . '</textarea>' . photoCamBtn($key) . '</td>
                        </tr>';
                    }
                    ?>

                    <table class="pdf-table" style="font-size:11px;">
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

                        <tr>
                            <th colspan="3" style="background:#5b9bd5; color:white;">Partie B - Les performances</th>
                        </tr>
                        <tr>
                            <td colspan="2"
                                style="font-weight:bold; font-size:11px; vertical-align:middle; padding-left:10px;">Bille
                                diamètre 20</td>
                            <td style="padding:0;"><textarea name="donnees[ov_perf_bille]" class="pdf-textarea"
                                    style="height:30px; border:none; width:100%; box-sizing:border-box; padding:4px;"></textarea>
                            </td>
                        </tr>
                        <tr>
                            <td colspan="2"
                                style="font-weight:bold; font-size:11px; vertical-align:middle; padding-left:10px;">Ecrou M4
                            </td>
                            <td style="padding:0;"><textarea name="donnees[ov_perf_ecrou]" class="pdf-textarea"
                                    style="height:30px; border:none; width:100%; box-sizing:border-box; padding:4px;"></textarea>
                            </td>
                        </tr>
                        <tr>
                            <td colspan="2"
                                style="font-weight:bold; font-size:11px; vertical-align:middle; padding-left:10px;">Rond
                                diamètre 6 longueur 50</td>
                            <td style="padding:0;"><textarea name="donnees[ov_perf_rond50]" class="pdf-textarea"
                                    style="height:30px; border:none; width:100%; box-sizing:border-box; padding:4px;"></textarea>
                            </td>
                        </tr>
                        <tr>
                            <td colspan="2"
                                style="font-weight:bold; font-size:11px; vertical-align:middle; padding-left:10px;">Rond
                                diamètre 6 longueur 100</td>
                            <td style="padding:0;"><textarea name="donnees[ov_perf_rond100]" class="pdf-textarea"
                                    style="height:30px; border:none; width:100%; box-sizing:border-box; padding:4px;"></textarea>
                            </td>
                        </tr>
                    </table>

                    <table class="pdf-table" style="font-size:11px;">
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
                                <td style="padding:0;"><textarea name="donnees[' . $key . '_comment]" class="pdf-textarea" style="height:30px; border:none; width:100%; box-sizing:border-box; padding:4px;"></textarea></td>
                            </tr>';
                        }
                        ?>
                        <?= renderFreqRow("Contrôle visuel de la bande", "ov_freq_bande", $donnees) ?>
                        <?= renderFreqRow("Contrôle visuel des fixations", "ov_freq_fix", $donnees) ?>
                        <?= renderFreqRow("Contrôle visuel des tambours", "ov_freq_tamb", $donnees) ?>
                        <?= renderFreqRow("Graissage des paliers", "ov_freq_graiss", $donnees) ?>
                    </table>

                    <div style="margin-top:20px;"></div>

                    <div style="border:1px solid #f29b43; padding:10px; margin-top:0px; text-align:center;">
                        <img src="/assets/machines/ov_diagram.png"
                            style="max-width:100%; height:auto; display:block; margin:0 auto 15px auto;" alt="Schéma OV">

                        <!-- Tableau de Légende Gris -->
                        <div style="display:inline-block; background:white; border:1px solid #000; width:350px;">
                            <table
                                style="width:100%; border-collapse:collapse; font-size:10px; text-align:center; color:#000;">
                                <tr>
                                    <th
                                        style="border:1px solid #000; background:#d9d9d9; padding:4px; width:20%; font-weight:bold;">
                                        Rep.</th>
                                    <th style="border:1px solid #000; background:#d9d9d9; padding:4px; font-weight:bold;">
                                        DESIGNATION</th>
                                </tr>
                                <tr>
                                    <td style="border:1px solid #000; padding:2px;">1</td>
                                    <td style="border:1px solid #000; padding:2px;">Motoreducteur Rossi</td>
                                </tr>
                                <tr>
                                    <td style="border:1px solid #000; padding:2px;">2</td>
                                    <td style="border:1px solid #000; padding:2px;">Motoreducteur Leroy Somer</td>
                                </tr>
                                <tr>
                                    <td style="border:1px solid #000; padding:2px;">3</td>
                                    <td style="border:1px solid #000; padding:2px;">Motoreducteur SEW</td>
                                </tr>
                                <tr>
                                    <td style="border:1px solid #000; padding:2px;">4</td>
                                    <td style="border:1px solid #000; padding:2px;">Paliers fixes du tambour moteur</td>
                                </tr>
                                <tr>
                                    <td style="border:1px solid #000; padding:2px;">5</td>
                                    <td style="border:1px solid #000; padding:2px;">Paliers tendeurs du tambour mené</td>
                                </tr>
                                <tr>
                                    <td style="border:1px solid #000; padding:2px;">6</td>
                                    <td style="border:1px solid #000; padding:2px;">Contrôleur de rotation</td>
                                </tr>
                                <tr>
                                    <td style="border:1px solid #000; padding:2px;">7</td>
                                    <td style="border:1px solid #000; padding:2px;">Tambour mené</td>
                                </tr>
                                <tr>
                                    <td style="border:1px solid #000; padding:2px;">8</td>
                                    <td style="border:1px solid #000; padding:2px;">Tambour moteur</td>
                                </tr>
                                <tr>
                                    <td style="border:1px solid #000; padding:2px;">9</td>
                                    <td style="border:1px solid #000; padding:2px;">(Galets)</td>
                                </tr>
                                <tr>
                                    <td style="border:1px solid #000; padding:2px;">10</td>
                                    <td style="border:1px solid #000; padding:2px;">(Contrôleurs de déport de bande)</td>
                                </tr>
                                <tr>
                                    <td style="border:1px solid #000; padding:2px;">11</td>
                                    <td style="border:1px solid #000; padding:2px;">Bande</td>
                                </tr>
                            </table>
                        </div>
                    </div>

                    <div style="margin-top:20px;"></div>
                    <div
                        style="background:#5b9bd5; color:white; font-weight:bold; font-size:12px; padding:5px; margin-top:0; border:1px solid #000;">
                        PHOTOS ANNEXES</div>

                <?php elseif ($isLevage): ?>

                    <div
                        style="display:flex; justify-content:space-between; margin-bottom:15px; padding:10px; border:1px solid #000; background:#f9f9f9; color:black;">
                        <div style="font-size:11px;"><strong>Temps prévisionnel :</strong> 25min/aimant + 25min/palonnier +
                            30min/armoire</div>
                        <div style="font-size:11px;"><strong>Temps réalisé :</strong> <input type="text" class="pdf-input"
                                style="width:80px; text-align:center; border-bottom:1px solid #000;"
                                name="mesures[temps_realise]"
                                value="<?= htmlspecialchars($mesures['temps_realise'] ?? '') ?>"> h</div>
                    </div>

                    <table class="pdf-table" style="font-size:11px; color:black;">
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

                        <!-- Section Main -->
                        <tr>
                            <th style="background:#4472c4; color:white; font-weight:bold; padding:4px;" colspan="3">Produit
                                de Levage type : <input type="text" name="mesures[levage_type]"
                                    value="<?= htmlspecialchars($mesures['levage_type'] ?? '') ?>"
                                    style="background:transparent; border:none; border-bottom:1px solid white; color:white; outline:none; font-weight:bold; width:200px;">
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
                                    class="pdf-textarea"
                                    style="height:30px; border:none; width:100%; box-sizing:border-box; padding:4px;"><?= htmlspecialchars($donnees["levage_facteur_service_comment"] ?? '') ?></textarea>
                            </td>
                        </tr>
                    </table>

                    <div style="margin-top:20px;"></div>

                    <div style="margin-top:0px; border:1px solid #000; padding:10px; color:black;">
                        <!-- Main schema enlarged without absolute positioning inside -->
                        <div style="display:flex; justify-content:center; align-items: flex-end; margin-bottom:10px;">
                            <img src="/assets/machines/levage_diagram.png"
                                style="width:100%; max-width:600px; height:auto; display:block;" alt="Schéma Levage">
                        </div>

                        <!-- Inputs below the image, standard style "comme les autres" -->
                        <div style="display:flex; gap:10px;">
                            <div style="flex:1; border:1px solid #000; padding:5px; font-size:11px;">
                                <strong>Diamètre du pôle :</strong> <input type="text" name="mesures[levage_diam_pole]"
                                    value="<?= htmlspecialchars($mesures['levage_diam_pole'] ?? '') ?>" class="pdf-input"
                                    style="width:60px; border-bottom: 1px solid #000;"> mm<br>
                                <strong>Diamètre du noyau :</strong> <input type="text" name="mesures[levage_diam_noyau]"
                                    value="<?= htmlspecialchars($mesures['levage_diam_noyau'] ?? '') ?>" class="pdf-input"
                                    style="width:60px; border-bottom: 1px solid #000;"> mm
                            </div>
                            <div style="flex:1; border:1px solid #000; padding:5px; font-size:11px;">
                                <strong>Epaisseur du pôle :</strong> <input type="text"
                                    name="mesures[levage_epaisseur_pole]"
                                    value="<?= htmlspecialchars($mesures['levage_epaisseur_pole'] ?? '') ?>"
                                    class="pdf-input" style="width:60px; border-bottom: 1px solid #000;"> mm
                            </div>
                        </div>
                        <div
                            style="background:#5b9bd5; color:white; font-weight:bold; font-size:12px; padding:5px; margin-top:20px; border:1px solid #000;">
                            PHOTOS ANNEXES</div>

                    <?php else: ?>

                        <!-- GENERIC SCHEMA -->
                        <div
                            style="background:#fff3cd; color:#856404; padding:15px; margin-bottom:20px; font-weight:bold; border:1px solid #ffeeba; text-align:center;">
                            Cette machine (<?= htmlspecialchars($machine['designation']) ?>) n'a pas encore de modèle PDF
                            numérisé sur mesure (comme APRF ou ED-X). Voici la grille générique.
                        </div>

                        <table class="pdf-table">
                            <tr>
                                <th>Point de Contrôle / Désignation</th>
                                <th style="text-align:center">État</th>
                                <th>Commentaires / Valeurs Mesurées</th>
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
                            <div style="border:1px solid #000; padding:10px; text-align:center; margin-top:20px;">
                                <div style="font-weight:bold; margin-bottom:10px;">Schéma de Référence (Extrait Word) :</div>
                                <img src="<?= htmlspecialchars($foundSchema) ?>"
                                    style="max-width:100%; height:auto; display:block; margin:0 auto;" alt="Schéma machine">
                            </div>
                        <?php endif; ?>

                    <?php endif; ?>

                    <?php if (!$isEDX): ?>
                        <div style="margin-top:20px; border: 1px solid #000; padding:10px;">
                            <div style="font-weight:bold; font-size:14px; margin-bottom:5px;">Commentaire général :</div>
                            <textarea name="commentaires" class="pdf-textarea" style="min-height:80px; font-size:13px;"
                                placeholder="En présence du client / Pièces à proposer..."><?= htmlspecialchars($machine['commentaires'] ?? '') ?></textarea>
                        </div>
                    <?php endif; ?>

                    <!-- PHOTOS ANNEXES -->
                    <div
                        style="background:#5b9bd5; color:white; font-weight:bold; font-size:12px; padding:5px; margin-top:20px; border:1px solid #000;">
                        PHOTOS ANNEXES</div>
                    <div id="photosAnnexesGrid"
                        style="border:1px solid #000; border-top:none; padding:10px; min-height:60px; display:flex; flex-wrap:wrap; gap:10px;">
                        <p style="color:#999; font-size:11px; margin:0;" id="noPhotosMsg">Aucune photo. Utilisez les
                            boutons 📷 sur chaque ligne de contrôle.</p>
                    </div>

                    <input type="hidden" name="photos_json" id="photosJsonInput" value="">
                    <script>var _photosFromDB = <?= json_encode($photosData ?: new stdClass(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES) ?>;</script>

                </div> <!-- fin .pdf-page -->
            </div> <!-- fin .mobile-wrapper -->
    </form>

    <!-- Hidden file input for camera capture -->
    <input type="file" id="cameraInput" accept="image/*" capture="environment" style="display:none">

    <script>
        // ========== PHOTO SYSTEM ==========
        var allPhotos = {};
        var currentPhotoKey = '';

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

        document.getElementById('cameraInput').addEventListener('change', function (e) {
            var file = e.target.files[0];
            if (!file) return;
            compressImage(file, 1200, 0.8, function (base64) {
                if (!allPhotos[currentPhotoKey]) allPhotos[currentPhotoKey] = [];
                var caption = prompt('Commentaire photo (optionnel) :', '') || '';
                allPhotos[currentPhotoKey].push({ data: base64, caption: caption });
                syncPhotos();
                renderThumbsForKey(currentPhotoKey);
                renderAnnexes();
            });
            // Reset so same file can be re-selected
            e.target.value = '';
        });

        function compressImage(file, maxWidth, quality, callback) {
            var reader = new FileReader();
            reader.onload = function (ev) {
                var img = new Image();
                img.onload = function () {
                    var w = img.width, h = img.height;
                    if (w > maxWidth) {
                        h = Math.round(h * maxWidth / w);
                        w = maxWidth;
                    }
                    var canvas = document.createElement('canvas');
                    canvas.width = w;
                    canvas.height = h;
                    canvas.getContext('2d').drawImage(img, 0, 0, w, h);
                    callback(canvas.toDataURL('image/jpeg', quality));
                };
                img.src = ev.target.result;
            };
            reader.readAsDataURL(file);
        }

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

        function renderAnnexes() {
            var grid = document.getElementById('photosAnnexesGrid');
            var msg = document.getElementById('noPhotosMsg');
            grid.innerHTML = '';
            var hasPhotos = false;
            var keys = Object.keys(allPhotos);
            keys.forEach(function (key) {
                allPhotos[key].forEach(function (p) {
                    hasPhotos = true;
                    var item = document.createElement('div');
                    item.className = 'photo-annexe-item';
                    var label = key.replace(/_/g, ' ').replace(/edx |ov |aprf |levage /gi, '').replace(/comment|radio/g, '').trim();
                    label = label.charAt(0).toUpperCase() + label.slice(1);
                    item.innerHTML = '<img src="' + p.data + '">' +
                        '<p><strong>' + label + '</strong>' + (p.caption ? '<br><em>' + p.caption + '</em>' : '') + '</p>';
                    grid.appendChild(item);
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

        // ========== PASTILLE CLICK MANAGEMENT ==========
        document.addEventListener('click', function (e) {
            var label = e.target.closest('.pastille-group label');
            if (!label) return;
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
            if (d && f) {
                var dp = d.split(':'), fp = f.split(':');
                var mins = (parseInt(fp[0]) * 60 + parseInt(fp[1])) - (parseInt(dp[0]) * 60 + parseInt(dp[1]));
                if (mins < 0) mins += 1440;
                var h = Math.floor(mins / 60), m = mins % 60;
                span.textContent = '= ' + h + 'h' + pad2(m);
            } else if (d && chronoRunning) {
                // Live display
                var now = new Date();
                var dp2 = d.split(':');
                var mins2 = (now.getHours() * 60 + now.getMinutes()) - (parseInt(dp2[0]) * 60 + parseInt(dp2[1]));
                if (mins2 < 0) mins2 += 1440;
                var h2 = Math.floor(mins2 / 60), m2 = mins2 % 60;
                span.textContent = '⏱ ' + h2 + 'h' + pad2(m2);
            } else {
                span.textContent = '';
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
        });
    </script>
</body>

</html>