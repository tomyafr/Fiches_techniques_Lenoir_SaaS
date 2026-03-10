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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    verifyCsrfToken();
    if ($_POST['action'] === 'save_machine') {
        $of = trim($_POST['numero_of'] ?? '');
        $commentaire = trim($_POST['commentaires'] ?? '');
        $postDonnees = $_POST['donnees'] ?? [];
        $postMesures = $_POST['mesures'] ?? [];

        $db->prepare('UPDATE machines SET numero_of = ?, commentaires = ?, donnees_controle = ?, mesures = ? WHERE id = ?')
            ->execute([$of, $commentaire, json_encode($postDonnees), json_encode($postMesures), $id]);

        header('Location: intervention_edit.php?id=' . $machine['intervention_id'] . '&msg=saved');
        exit;
    }
}

$designation = strtoupper(trim($machine['designation']));
$isAPRF = strpos($designation, 'APRF') !== false || strpos($designation, 'APRM') !== false;
$isEDX = strpos($designation, 'ED-X') !== false || strpos($designation, 'EDX') !== false || strpos($designation, 'FOUCAULT') !== false;
$isOV = strpos($designation, 'OV') !== false && strpos($designation, 'ROUE') === false;
$isLevage = strpos($designation, 'LEVAGE') !== false || strpos($designation, 'AIMANT') !== false;
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
            padding: 2cm 1.5cm;
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
            padding: 8px 5px;
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
            width: 35%;
        }

        .radio-box {
            display: inline-flex;
            flex-direction: column;
            align-items: center;
            font-size: 9px;
            margin: 0 4px;
            cursor: pointer;
            color: black;
            font-weight: bold;
        }

        .radio-box input {
            cursor: pointer;
            width: 16px;
            height: 16px;
            margin-top: 2px;
            accent-color: #c00;
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
            height: 40px;
            border: 1px solid transparent;
            background: transparent;
            resize: vertical;
            font-family: Arial;
            font-size: 12px;
            outline: none;
            color: black;
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
                margin: 0;
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
                style="color:white; border-color:white;">← REVENIR À L'INTERVENTION</button>
            <button type="submit" class="btn btn-primary" style="background:#e6b12a; color:#000;">ENREGISTRER LA
                FICHE</button>
        </div>

        <div class="mobile-wrapper">
            <div class="pdf-page">
                <!-- Header exact LENOIR -->
                <table
                    style="width:100%; border-collapse:collapse; border:1px solid #000; margin-bottom:15px; color:#000;">
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
                        <td
                            style="width:15%; font-weight:bold; border:1px solid #000; padding:6px; background:#d9d9d9;">
                            N° A.R.C.</td>
                        <td style="width:35%; border:1px solid #000; padding:6px; font-family:Courier, monospace;">
                            <?= htmlspecialchars($machine['numero_arc']) ?>
                        </td>
                        <td
                            style="width:15%; font-weight:bold; border:1px solid #000; padding:6px; background:#d9d9d9;">
                            Repère</td>
                        <td style="width:35%; border:1px solid #000; padding:6px;">
                            <input type="text" name="mesures[repere]"
                                value="<?= htmlspecialchars($mesures['repere'] ?? '') ?>" class="pdf-input">
                        </td>
                    </tr>
                    <tr>
                        <td
                            style="width:15%; font-weight:bold; border:1px solid #000; padding:6px; background:#d9d9d9;">
                            N° O.F.</td>
                        <td style="width:35%; border:1px solid #000; padding:6px;">
                            <input type="text" name="numero_of" value="<?= htmlspecialchars($machine['numero_of']) ?>"
                                class="pdf-input">
                        </td>
                        <td
                            style="width:15%; font-weight:bold; border:1px solid #000; padding:6px; background:#d9d9d9;">
                            Désignation</td>
                        <td style="width:35%; border:1px solid #000; padding:6px; font-weight:bold;">
                            <?= htmlspecialchars($machine['designation']) ?>
                        </td>
                    </tr>
                </table>

                <?php
                // HELPER FONCTION : Radio Button pour État (C/A/NC)
                function renderEtatRadios($key, $donnees)
                {
                    $val = $donnees[$key] ?? '';
                    return '
                    <div style="display:flex; justify-content:center; gap:8px;">
                        <label class="radio-box" title="Correct">C<br><input type="radio" name="donnees[' . $key . ']" value="c" ' . ($val == 'c' ? 'checked' : '') . '></label>
                        <label class="radio-box" title="Non Correct">NC<br><input type="radio" name="donnees[' . $key . ']" value="nc" ' . ($val == 'nc' ? 'checked' : '') . '></label>
                        <label class="radio-box" title="Non Applicable" style="color:#666">N/A<br><input type="radio" name="donnees[' . $key . ']" value="na" ' . ($val == 'na' ? 'checked' : '') . '></label>
                    </div>';
                }
                function renderCheckRow($label, $key, $donnees)
                {
                    return '<tr>
                        <td style="font-weight:bold; font-size:12px;">' . htmlspecialchars($label) . '</td>
                        <td class="col-etat">' . renderEtatRadios($key . "_radio", $donnees) . '</td>
                        <td class="col-comment"><textarea name="donnees[' . $key . '_comment]" class="pdf-textarea" placeholder="Détails...">' . htmlspecialchars($donnees[$key . "_comment"] ?? '') . '</textarea></td>
                    </tr>';
                }
                function renderAprfEtatRadios($key, $donnees)
                {
                    $val = $donnees[$key] ?? '';
                    return '
                    <table style="width:100%; border-collapse:collapse; text-align:center; height:100%;">
                        <tr>
                            <td style="width:33%; border:none; border-right:1px solid #000;"><input type="radio" name="donnees[' . $key . ']" value="bon" ' . ($val == 'bon' ? 'checked' : '') . '></td>
                            <td style="width:34%; border:none; border-right:1px solid #000;"><input type="radio" name="donnees[' . $key . ']" value="r" ' . ($val == 'r' ? 'checked' : '') . '></td>
                            <td style="width:33%; border:none;"><input type="radio" name="donnees[' . $key . ']" value="hs" ' . ($val == 'hs' ? 'checked' : '') . '></td>
                        </tr>
                    </table>';
                }
                function renderAprfRow($label, $key, $donnees)
                {
                    return '<tr>
                        <td style="font-weight:normal; font-size:11px;">' . htmlspecialchars($label) . '</td>
                        <td style="padding:0; vertical-align:middle;">' . renderAprfEtatRadios($key, $donnees) . '</td>
                        <td style="padding:0;"><textarea name="donnees[' . $key . '_comment]" class="pdf-textarea" style="height:30px; border:none; width:100%; box-sizing:border-box; padding:4px;">' . htmlspecialchars($donnees[$key . "_comment"] ?? '') . '</textarea></td>
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

                    <div
                        style="background:#5b9bd5; color:white; font-weight:bold; font-size:12px; padding:5px; margin-top:0; border:1px solid #000; border-top:none;">
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
                        return '
                        <table style="width:100%; height:100%; border-collapse:collapse; text-align:center; line-height:1; min-height:30px;">
                            <tr>
                                <td style="width:20%; border:none; border-right:1px solid #000;"><input type="radio" name="donnees[' . $key . ']" value="pc" ' . ($val == 'pc' ? 'checked' : '') . '></td>
                                <td style="width:20%; border:none; border-right:1px solid #000;"><input type="radio" name="donnees[' . $key . ']" value="c" ' . ($val == 'c' ? 'checked' : '') . '></td>
                                <td style="width:20%; border:none; border-right:1px solid #000;"><input type="radio" name="donnees[' . $key . ']" value="aa" ' . ($val == 'aa' ? 'checked' : '') . '></td>
                                <td style="width:20%; border:none; border-right:1px solid #000;"><input type="radio" name="donnees[' . $key . ']" value="nc" ' . ($val == 'nc' ? 'checked' : '') . '></td>
                                <td style="width:20%; border:none;"><input type="radio" name="donnees[' . $key . ']" value="nr" ' . ($val == 'nr' ? 'checked' : '') . '></td>
                            </tr>
                        </table>';
                    }
                    function renderEdxRow($label, $key, $donnees)
                    {
                        return '<tr>
                            <td style="font-weight:normal; font-size:11px;">' . htmlspecialchars($label) . '</td>
                            <td style="padding:0; vertical-align:middle;">' . renderEdxEtatRadios($key, $donnees) . '</td>
                            <td style="padding:0;"><textarea name="donnees[' . $key . '_comment]" class="pdf-textarea" style="height:30px; border:none; border-bottom:1px solid transparent; width:100%; padding:4px; box-sizing:border-box;">' . htmlspecialchars($donnees[$key . "_comment"] ?? '') . '</textarea></td>
                        </tr>';
                    }
                    ?>

                    <table class="pdf-table" style="font-size:11px;">
                        <tr>
                            <th rowspan="2"
                                style="width:35%; text-align:center; vertical-align:middle; background:#e0e0e0;">
                                DESIGNATIONS</th>
                            <th style="text-align:center; padding:0; background:#e0e0e0;">ETAT</th>
                            <th rowspan="2"
                                style="width:25%; text-align:center; vertical-align:middle; background:#e0e0e0;">
                                COMMENTAIRES</th>
                        </tr>
                        <tr>
                            <th style="padding:0; background:#e0e0e0;">
                                <table style="width:100%; border-collapse:collapse; text-align:center; font-size:9px;">
                                    <tr>
                                        <td style="width:20%; border:none; border-right:1px solid #000; padding:2px;">
                                            Pas<br>concerné</td>
                                        <td style="width:20%; border:none; border-right:1px solid #000; padding:2px;">
                                            Correct</td>
                                        <td style="width:20%; border:none; border-right:1px solid #000; padding:2px;">
                                            A<br>améliorer</td>
                                        <td style="width:20%; border:none; border-right:1px solid #000; padding:2px;">
                                            Pas<br>correct</td>
                                        <td style="width:20%; border:none; padding:2px;">Nécessite<br>remplacement</td>
                                    </tr>
                                </table>
                            </th>
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

                    <div
                        style="background:#5b9bd5; color:white; font-weight:bold; font-size:12px; padding:5px; margin-top:20px; border:1px solid #000; border-top:none;">
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
                        return '
                        <table style="width:100%; height:100%; border-collapse:collapse; text-align:center; line-height:1; min-height:30px;">
                            <tr>
                                <td style="width:20%; border:none; border-right:1px solid #000;"><input type="radio" name="donnees[' . $key . ']" value="pc" ' . ($val == 'pc' ? 'checked' : '') . '></td>
                                <td style="width:20%; border:none; border-right:1px solid #000;"><input type="radio" name="donnees[' . $key . ']" value="c" ' . ($val == 'c' ? 'checked' : '') . '></td>
                                <td style="width:20%; border:none; border-right:1px solid #000;"><input type="radio" name="donnees[' . $key . ']" value="aa" ' . ($val == 'aa' ? 'checked' : '') . '></td>
                                <td style="width:20%; border:none; border-right:1px solid #000;"><input type="radio" name="donnees[' . $key . ']" value="nc" ' . ($val == 'nc' ? 'checked' : '') . '></td>
                                <td style="width:20%; border:none;"><input type="radio" name="donnees[' . $key . ']" value="nr" ' . ($val == 'nr' ? 'checked' : '') . '></td>
                            </tr>
                        </table>';
                    }
                    function renderOvRow($label, $key, $donnees)
                    {
                        return '<tr>
                            <td style="font-weight:bold; font-size:11px;">' . htmlspecialchars($label) . '</td>
                            <td style="padding:0; vertical-align:middle;">' . renderOvEtatRadios($key, $donnees) . '</td>
                            <td style="padding:0;"><textarea name="donnees[' . $key . '_comment]" class="pdf-textarea" style="height:30px; border:none; border-bottom:1px solid transparent; width:100%; padding:4px; box-sizing:border-box;">' . htmlspecialchars($donnees[$key . "_comment"] ?? '') . '</textarea></td>
                        </tr>';
                    }
                    ?>

                    <table class="pdf-table" style="font-size:11px;">
                        <tr>
                            <th rowspan="2"
                                style="width:35%; text-align:center; vertical-align:middle; background:#e0e0e0;">
                                DESIGNATIONS</th>
                            <th style="text-align:center; padding:0; background:#e0e0e0;">ETAT</th>
                            <th rowspan="2"
                                style="width:25%; text-align:center; vertical-align:middle; background:#e0e0e0;">
                                COMMENTAIRES</th>
                        </tr>
                        <tr>
                            <th style="padding:0; background:#e0e0e0;">
                                <table style="width:100%; border-collapse:collapse; text-align:center; font-size:9px;">
                                    <tr>
                                        <td style="width:20%; border:none; border-right:1px solid #000; padding:2px;">
                                            Pas<br>concerné</td>
                                        <td style="width:20%; border:none; border-right:1px solid #000; padding:2px;">
                                            Correct</td>
                                        <td style="width:20%; border:none; border-right:1px solid #000; padding:2px;">
                                            A<br>améliorer</td>
                                        <td style="width:20%; border:none; border-right:1px solid #000; padding:2px;">
                                            Pas<br>correct</td>
                                        <td style="width:20%; border:none; padding:2px;">Nécessite<br>remplacement</td>
                                    </tr>
                                </table>
                            </th>
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

                    <div style="border:1px solid #f29b43; padding:10px; margin-top:20px; text-align:center;">
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
                    <div
                        style="background:#5b9bd5; color:white; font-weight:bold; font-size:12px; padding:5px; margin-top:0; border:1px solid #000; border-top:none;">
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
                            <td colspan="1" style="padding:4px; font-weight:bold;">Isolement sous 1000 Vcc :</td>
                            <td style="border:1px solid #000; text-align:center;">
                                <input type="text" name="mesures[levage_isolement]"
                                    value="<?= htmlspecialchars($mesures['levage_isolement'] ?? '') ?>" class="pdf-input"
                                    style="width:60px;"> M.Ohms
                            </td>
                            <td style="padding:0;"><textarea name="donnees[levage_isolement_comment]" class="pdf-textarea"
                                    style="height:30px; border:none; width:100%; box-sizing:border-box; padding:4px;"><?= htmlspecialchars($donnees["levage_isolement_comment"] ?? '') ?></textarea>
                            </td>
                        </tr>
                        <tr>
                            <td colspan="1" style="padding:4px; font-weight:bold;">Résistance à froid :</td>
                            <td style="border:1px solid #000; border-top:none; text-align:center;">
                                <input type="text" name="mesures[levage_resistance]"
                                    value="<?= htmlspecialchars($mesures['levage_resistance'] ?? '') ?>" class="pdf-input"
                                    style="width:60px;"> ohms
                            </td>
                            <td style="padding:0;"><textarea name="donnees[levage_resistance_comment]" class="pdf-textarea"
                                    style="height:30px; border:none; width:100%; box-sizing:border-box; padding:4px;"><?= htmlspecialchars($donnees["levage_resistance_comment"] ?? '') ?></textarea>
                            </td>
                        </tr>
                        <tr>
                            <td colspan="1" style="padding:4px; font-weight:bold;">Température de la carcasse :</td>
                            <td style="border:1px solid #000; border-top:none; text-align:center;">
                                <input type="text" name="mesures[levage_temp_carcasse]"
                                    value="<?= htmlspecialchars($mesures['levage_temp_carcasse'] ?? '') ?>"
                                    class="pdf-input" style="width:60px;"> °C
                            </td>
                            <td style="padding:0;"><textarea name="donnees[levage_temp_carcasse_comment]"
                                    class="pdf-textarea"
                                    style="height:30px; border:none; width:100%; box-sizing:border-box; padding:4px;"><?= htmlspecialchars($donnees["levage_temp_carcasse_comment"] ?? '') ?></textarea>
                            </td>
                        </tr>
                        <tr>
                            <td colspan="1" style="padding:4px; font-weight:bold;">Température ambiante :</td>
                            <td style="border:1px solid #000; border-top:none; text-align:center;">
                                <input type="text" name="mesures[levage_temp_ambiante]"
                                    value="<?= htmlspecialchars($mesures['levage_temp_ambiante'] ?? '') ?>"
                                    class="pdf-input" style="width:60px;"> °C
                            </td>
                            <td style="padding:0;"><textarea name="donnees[levage_temp_ambiante_comment]"
                                    class="pdf-textarea"
                                    style="height:30px; border:none; width:100%; box-sizing:border-box; padding:4px;"><?= htmlspecialchars($donnees["levage_temp_ambiante_comment"] ?? '') ?></textarea>
                            </td>
                        </tr>
                        <tr>
                            <td colspan="1" style="padding:4px; font-weight:bold;">Electroaimant arrêté depuis :</td>
                            <td style="border:1px solid #000; border-top:none; text-align:center;">
                                <input type="text" name="mesures[levage_arrete_depuis]"
                                    value="<?= htmlspecialchars($mesures['levage_arrete_depuis'] ?? '') ?>"
                                    class="pdf-input" style="width:60px;"> h
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
                            <td style="padding:4px; font-weight:bold;">Tension :</td>
                            <td style="padding:0; vertical-align:middle; border:1px solid #000;">
                                <div style="display:flex; flex-direction:column; align-items:center;">
                                    <div style="display:flex; align-items:center;">
                                        <input type="text" name="mesures[levage_tension]"
                                            value="<?= htmlspecialchars($mesures['levage_tension'] ?? '') ?>"
                                            class="pdf-input" style="width:60px; text-align:center;"> <span
                                            style="font-size:10px; margin-left:3px;">Vcc</span>
                                    </div>
                                    <?= renderAprfEtatRadios("levage_tension_stat", $donnees) ?>
                                </div>
                            </td>
                            <td style="padding:0;"><textarea name="donnees[levage_tension_comment]" class="pdf-textarea"
                                    style="height:60px; border:none; width:100%; box-sizing:border-box; padding:4px;"><?= htmlspecialchars($donnees["levage_tension_comment"] ?? '') ?></textarea>
                            </td>
                        </tr>
                        <tr>
                            <td style="padding:4px; font-weight:bold;">Intensité :</td>
                            <td style="padding:0; vertical-align:middle; border:1px solid #000;">
                                <div style="display:flex; flex-direction:column; align-items:center;">
                                    <div style="display:flex; align-items:center;">
                                        <input type="text" name="mesures[levage_intensite]"
                                            value="<?= htmlspecialchars($mesures['levage_intensite'] ?? '') ?>"
                                            class="pdf-input" style="width:60px; text-align:center;"> <span
                                            style="font-size:10px; margin-left:3px;">A</span>
                                    </div>
                                    <?= renderAprfEtatRadios("levage_intensite_stat", $donnees) ?>
                                </div>
                            </td>
                            <td style="padding:0;"><textarea name="donnees[levage_intensite_comment]" class="pdf-textarea"
                                    style="height:60px; border:none; width:100%; box-sizing:border-box; padding:4px;"><?= htmlspecialchars($donnees["levage_intensite_comment"] ?? '') ?></textarea>
                            </td>
                        </tr>
                        <tr>
                            <td style="padding:4px; font-weight:bold;">Champ magnétique (centre noyau) :</td>
                            <td style="padding:0; vertical-align:middle; border:1px solid #000;">
                                <div style="display:flex; flex-direction:column; align-items:center;">
                                    <div style="display:flex; align-items:center;">
                                        <input type="text" name="mesures[levage_champ_centre]"
                                            value="<?= htmlspecialchars($mesures['levage_champ_centre'] ?? '') ?>"
                                            class="pdf-input" style="width:60px; text-align:center;"> <span
                                            style="font-size:10px; margin-left:3px;">Gauss</span>
                                    </div>
                                    <?= renderAprfEtatRadios("levage_champ_centre_stat", $donnees) ?>
                                </div>
                            </td>
                            <td style="padding:0;"><textarea name="donnees[levage_champ_centre_comment]"
                                    class="pdf-textarea"
                                    style="height:60px; border:none; width:100%; box-sizing:border-box; padding:4px;"><?= htmlspecialchars($donnees["levage_champ_centre_comment"] ?? '') ?></textarea>
                            </td>
                        </tr>
                        <tr>
                            <td style="padding:4px; font-weight:bold;">Champ magnétique (milieu pôle) :</td>
                            <td style="padding:0; vertical-align:middle; border:1px solid #000;">
                                <div style="display:flex; flex-direction:column; align-items:center;">
                                    <div style="display:flex; align-items:center;">
                                        <input type="text" name="mesures[levage_champ_pole]"
                                            value="<?= htmlspecialchars($mesures['levage_champ_pole'] ?? '') ?>"
                                            class="pdf-input" style="width:60px; text-align:center;"> <span
                                            style="font-size:10px; margin-left:3px;">Gauss</span>
                                    </div>
                                    <?= renderAprfEtatRadios("levage_champ_pole_stat", $donnees) ?>
                                </div>
                            </td>
                            <td style="padding:0;"><textarea name="donnees[levage_champ_pole_comment]" class="pdf-textarea"
                                    style="height:60px; border:none; width:100%; box-sizing:border-box; padding:4px;"><?= htmlspecialchars($donnees["levage_champ_pole_comment"] ?? '') ?></textarea>
                            </td>
                        </tr>

                        <!-- Section APPLICATION DU CLIENT -->
                        <tr>
                            <th colspan="3" style="background:#5b9bd5; color:white; text-align:left; padding:4px;">
                                APPLICATION DU CLIENT</th>
                        </tr>
                        <tr>
                            <td colspan="3" style="padding:5px;">
                                <strong>Produit manipulé :</strong> Brame / Tôle / Paquets / Coils / Profilés |
                                Dim. : <input type="text" name="mesures[levage_dimensions]"
                                    value="<?= htmlspecialchars($mesures['levage_dimensions'] ?? '') ?>" class="pdf-input"
                                    style="width:200px;">
                            </td>
                        </tr>
                        <tr>
                            <td colspan="2" style="padding:5px;"><strong>Charge Maxi par aimant :</strong></td>
                            <td style="padding:5px;"><input type="text" name="mesures[levage_charge_maxi]"
                                    value="<?= htmlspecialchars($mesures['levage_charge_maxi'] ?? '') ?>" class="pdf-input"
                                    style="width:100px;"> kg</td>
                        </tr>
                        <tr>
                            <td colspan="2" style="padding:5px;"><strong>Température Maxi des produits :</strong></td>
                            <td style="padding:5px;"><input type="text" name="mesures[levage_temp_maxi]"
                                    value="<?= htmlspecialchars($mesures['levage_temp_maxi'] ?? '') ?>" class="pdf-input"
                                    style="width:100px;"> °C</td>
                        </tr>
                        <tr>
                            <td colspan="2" style="padding:5px;"><strong>Facteur de marche estimé :</strong></td>
                            <td style="padding:5px;"><input type="text" name="mesures[levage_facteur_marche]"
                                    value="<?= htmlspecialchars($mesures['levage_facteur_marche'] ?? '') ?>"
                                    class="pdf-input" style="width:100px;"> %</td>
                        </tr>
                        <tr>
                            <td colspan="2" style="padding:5px;"><strong>Facteur de service h/jour :</strong></td>
                            <td style="padding:5px;"><input type="text" name="mesures[levage_facteur_service]"
                                    value="<?= htmlspecialchars($mesures['levage_facteur_service'] ?? '') ?>"
                                    class="pdf-input" style="width:100px;"> h/j</td>
                        </tr>
                    </table>

                    <div style="margin-top:20px; border:1px solid #000; padding:10px; color:black;">
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
                    </div>

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

                <div style="margin-top:20px; border: 1px solid #000; padding:10px;">
                    <div style="font-weight:bold; font-size:14px; margin-bottom:5px;">Commentaire général :</div>
                    <textarea name="commentaires" class="pdf-textarea" style="height:80px; font-size:13px;"
                        placeholder="En présence du client / Pièces à proposer..."><?= htmlspecialchars($machine['commentaires'] ?? '') ?></textarea>
                </div>

            </div> <!-- fin .pdf-page -->
        </div> <!-- fin .mobile-wrapper -->
    </form>
</body>

</html>