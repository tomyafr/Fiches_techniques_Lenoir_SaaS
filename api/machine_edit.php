<?php
require_once __DIR__ . '/../includes/config.php';
requireAuth('technicien');

$db = getDB();
$userId = $_SESSION['user_id'];
$id = $_GET['id'] ?? null;

if (!$id) {
    header('Location: technicien.php');
    exit;
}

$stmt = $db->prepare('
    SELECT m.*, i.numero_arc, i.technicien_id, i.date_intervention, c.nom_societe 
    FROM machines m 
    JOIN interventions i ON m.intervention_id = i.id 
    JOIN clients c ON i.client_id = c.id
    WHERE m.id = ? AND i.technicien_id = ?
');
$stmt->execute([$id, $userId]);
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
$isAPRF = strpos($designation, 'APRF') !== false;
$isEDX = strpos($designation, 'ED-X') !== false || strpos($designation, 'EDX') !== false;
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
                <!-- Header LENOIR -->
                <div class="pdf-header">
                    <div style="display:flex; align-items:center;">
                        <!-- Mockup logo since the exact lenoir.png is not sure to be black on white readable -->
                        <h1 style="color:#000; font-size:32px; margin:0; font-style:italic; font-weight:900;">LENOIR-MEC
                        </h1>
                    </div>
                    <div class="pdf-meta">
                        <div><strong>N° A.R.C. :</strong> <?= htmlspecialchars($machine['numero_arc']) ?></div>
                        <div><strong>Client :</strong> <?= htmlspecialchars($machine['nom_societe']) ?></div>
                        <div><strong>Date :</strong> <?= date('d/m/Y', strtotime($machine['date_intervention'])) ?>
                        </div>
                    </div>
                </div>

                <!-- Title Box -->
                <div class="pdf-title-box">
                    <div style="font-size:14px; font-weight:normal; text-align:left;">Poste : <input type="text"
                            class="pdf-input" style="width:300px" name="mesures[poste]"
                            value="<?= htmlspecialchars($mesures['poste'] ?? '') ?>"></div>
                    <div style="margin-top:15px; color:#000; font-size:20px;">
                        <?= $isAPRF ? 'Aimant permanent rectangulaire fixe' : ($isEDX ? 'Séparateur à courants de foucault' : 'Fiche de Contrôle :') ?>
                        <br />
                        <span
                            style="font-size:26px; font-weight:900; color:#c00;"><?= htmlspecialchars($machine['designation']) ?></span>
                    </div>
                    <div style="font-size:14px; font-weight:bold; margin-top:15px; text-align:right;">N° O.F. : <input
                            type="text" class="pdf-input" style="width:150px; font-weight:bold;" name="numero_of"
                            value="<?= htmlspecialchars($machine['numero_of']) ?>"></div>
                </div>

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

                    <table class="pdf-table">
                        <tr>
                            <th>Désignations</th>
                            <th style="text-align:center">Etat<br /><span
                                    style="font-size:9px;font-weight:normal">C=Correct, NC=Non Correct</span></th>
                            <th>Commentaires</th>
                        </tr>
                        <?= renderCheckRow("Aimants permanent fixe de triage type APRF : Satisfaction de fonctionnement", "aprf_satisfaction", $donnees) ?>
                        <?= renderCheckRow("État et type de la bande", "aprf_bande", $donnees) ?>
                        <?= renderCheckRow("État des réglettes", "aprf_reglettes", $donnees) ?>
                        <?= renderCheckRow("État des boutons étoile", "aprf_boutons", $donnees) ?>

                        <tr>
                            <th colspan="3" style="background:#ddd;">Options (à préciser)</th>
                        </tr>
                        <?= renderCheckRow("AIMANT PERMANENT Caisson Inox", "aprf_inox", $donnees) ?>

                        <tr>
                            <th colspan="3" style="background:#ddd;">Contrôle de l'attraction sur échantillon</th>
                        </tr>
                        <?= renderCheckRow("Bille diamètre 20", "aprf_bille20", $donnees) ?>
                        <?= renderCheckRow("Écrou M4", "aprf_ecroum4", $donnees) ?>
                        <?= renderCheckRow("Rond diamètre 6 Lg 50", "aprf_rond50", $donnees) ?>
                        <?= renderCheckRow("Rond diamètre 6 Lg 100", "aprf_rond100", $donnees) ?>
                    </table>

                    <div class="pdf-section-title">APPLICATION CLIENT</div>
                    <table class="pdf-table">
                        <tr>
                            <td style="width:40%; font-weight:bold;">Type de produit :</td>
                            <td><input type="text" class="pdf-input" name="mesures[produit]"
                                    value="<?= htmlspecialchars($mesures['produit'] ?? '') ?>"></td>
                        </tr>
                        <tr>
                            <td style="font-weight:bold;">Granulométrie (mm) :</td>
                            <td><input type="text" class="pdf-input" name="mesures[granulometrie]"
                                    value="<?= htmlspecialchars($mesures['granulometrie'] ?? '') ?>"></td>
                        </tr>
                        <tr>
                            <td style="font-weight:bold;">Distance aimants / bande (mm) :</td>
                            <td><input type="text" class="pdf-input" name="mesures[distance]"
                                    value="<?= htmlspecialchars($mesures['distance'] ?? '') ?>"></td>
                        </tr>
                        <tr>
                            <td style="font-weight:bold;">Hauteur de la couche (mm) :</td>
                            <td><input type="text" class="pdf-input" name="mesures[hauteur]"
                                    value="<?= htmlspecialchars($mesures['hauteur'] ?? '') ?>"></td>
                        </tr>
                        <tr>
                            <td style="font-weight:bold;">Débit (t/h) :</td>
                            <td><input type="text" class="pdf-input" name="mesures[debit]"
                                    value="<?= htmlspecialchars($mesures['debit'] ?? '') ?>"></td>
                        </tr>
                        <tr>
                            <td style="font-weight:bold;">Avec densité de :</td>
                            <td><input type="text" class="pdf-input" name="mesures[densite]"
                                    value="<?= htmlspecialchars($mesures['densite'] ?? '') ?>"></td>
                        </tr>
                    </table>

                    <!-- EDX SCHEMA -->
                <?php elseif ($isEDX): ?>

                    <div style="display:flex; justify-content:space-between; margin-bottom:15px; padding:10px;">
                        <div><strong>Temps prévisionnel :</strong> 3,5h</div>
                        <div><strong>Temps réalisé :</strong> <input type="text" class="pdf-input"
                                style="width:80px; text-align:center; border-bottom:1px solid #000;"
                                name="mesures[temps_realise]"
                                value="<?= htmlspecialchars($mesures['temps_realise'] ?? '') ?>"> h</div>
                    </div>

                    <table class="pdf-table" style="font-size:11px;">
                        <tr>
                            <th>Désignations</th>
                            <th style="text-align:center">État<br /><span
                                    style="font-size:9px;font-weight:normal">C=Correct, NC=Non Correct</span></th>
                            <th>Commentaires</th>
                        </tr>
                        <tr>
                            <th colspan="3" style="background:#ddd;">Environnement / Aspect général</th>
                        </tr>
                        <?= renderCheckRow("Accès au séparateur", "edx_acces", $donnees) ?>
                        <?= renderCheckRow("Etat général du séparateur", "edx_etat_gen", $donnees) ?>

                        <tr>
                            <th colspan="3" style="background:#ddd;">PARTIE A - Convoyeur</th>
                        </tr>
                        <?= renderCheckRow("Etat général des verrous", "edx_verrous", $donnees) ?>
                        <?= renderCheckRow("Etat général des grenouillères", "edx_grenouilles", $donnees) ?>
                        <?= renderCheckRow("Etat général des poignées de portes", "edx_poignees", $donnees) ?>
                        <?= renderCheckRow("Etat général des carters de protection/ des portes", "edx_carters", $donnees) ?>
                        <?= renderCheckRow("Aspect général intérieur séparateur", "edx_int_sep", $donnees) ?>
                        <?= renderCheckRow("Contrôle visuel des étanchéités latérales", "edx_etanch", $donnees) ?>
                        <?= renderCheckRow("Contrôle visuel état extérieur de la bande", "edx_bande_ext", $donnees) ?>
                        <?= renderCheckRow("Contrôle visuel état intérieur de la bande", "edx_bande_int", $donnees) ?>
                        <?= renderCheckRow("Contrôle de la tension de bande", "edx_tension_bande", $donnees) ?>
                        <?= renderCheckRow("Contrôle état des rouleaux anti-déport de bande", "edx_rlx_anti", $donnees) ?>
                        <?= renderCheckRow("Contrôle état des détecteurs de déport de bande", "edx_detecteurs", $donnees) ?>
                        <?= renderCheckRow("Contrôle état des guides TEFLON / tôle INOX déport de bande", "edx_guides", $donnees) ?>
                        <?= renderCheckRow("Contrôle état et réglage du racleur de bande", "edx_racleur", $donnees) ?>
                        <?= renderCheckRow("Contrôle réglage des paliers PHUSE-TENDEURS", "edx_paliers_phuse", $donnees) ?>
                        <?= renderCheckRow("Contrôle état du tambour moteur", "edx_tambour", $donnees) ?>
                        <?= renderCheckRow("Contrôle visuel virole fibre roue polaire", "edx_virole", $donnees) ?>
                        <?= renderCheckRow("Contrôle visuel déflecteur carbone roue polaire", "edx_deflecteur", $donnees) ?>
                        <?= renderCheckRow("Contrôle visuel état caisson roue polaire", "edx_caisson_roue", $donnees) ?>
                        <?= renderCheckRow("Contrôle état général des vis de fixation virole fibre", "edx_vis_virole", $donnees) ?>
                        <?= renderCheckRow("Contrôle état du contrôleur de rotation", "edx_ctrl_rot", $donnees) ?>
                        <?= renderCheckRow("Contrôle et repère du réglage du 3ème rouleau", "edx_3e_rouleau", $donnees) ?>

                        <tr>
                            <th colspan="3" style="background:#ddd;">PARTIE B - Caisson de séparation</th>
                        </tr>
                        <?= renderCheckRow("Etat général (verrous, grenouillères, portes, plexis)", "edx_cais_etat", $donnees) ?>
                        <?= renderCheckRow("Aspect général intérieur du caisson de séparation", "edx_cais_int", $donnees) ?>
                        <?= renderCheckRow("Contrôle état et mécanisme du volet", "edx_cais_volet", $donnees) ?>
                        <?= renderCheckRow("Nettoyage complet de l'intérieur du caisson", "edx_cais_net", $donnees) ?>

                        <tr>
                            <th colspan="3" style="background:#ddd;">PARTIE C - Armoire électrique</th>
                        </tr>
                        <?= renderCheckRow("Aspect général armoire & boutonnerie façade", "edx_arm_aspect", $donnees) ?>
                        <?= renderCheckRow("Etat général Arrêt d'Urgence", "edx_arm_au", $donnees) ?>
                        <?= renderCheckRow("Vitesse bande relevée / conforme", "edx_arm_vit_b", $donnees) ?>
                        <?= renderCheckRow("Vitesse roue polaire relevée / conforme", "edx_arm_vit_r", $donnees) ?>
                        <?= renderCheckRow("Contrôle freinage roue polaire / Temps constaté", "edx_arm_frein", $donnees) ?>
                        <?= renderCheckRow("Vérification des serrages câbles", "edx_arm_cable", $donnees) ?>
                    </table>

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