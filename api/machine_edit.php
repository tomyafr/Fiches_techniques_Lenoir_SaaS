<?php
header('Content-Type: text/html; charset=utf-8');
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

// BUG-FIX: Vercel Payload Too Large (4.5MB limit)
// On ne charge les photos en PHP que pour la génération PDF. 
// Pour le web, on les charge en AJAX plus bas.
if (isset($_GET['pdf'])) {
    $photosData = json_decode($machine['photos'] ?? '{}', true) ?: [];
} else {
    $photosData = []; 
}

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


$designation = str_to_upper_fr(trim($machine['designation'] ?? ''));
$isAPRF = strpos($designation, 'APRF') !== false || strpos($designation, 'RD') !== false;
$isEDX = strpos($designation, 'ED-X') !== false || strpos($designation, 'FOUCAULT') !== false;
$isOV = strpos($designation, 'OV') !== false && strpos($designation, 'ROUE') === false;
$isOVAP = $isOV && strpos($designation, 'OVAP') !== false;
$isPAP = strpos($designation, 'À AIMANTS PERMANENTS') !== false || strpos($designation, 'TAP/PAP') !== false || strpos($designation, 'PAP') !== false || strpos($designation, 'TAP') !== false;
$isLevage = (strpos($designation, 'LEVAGE') !== false || strpos($designation, 'AIMANT') !== false) && !$isAPRF && !$isPAP;
$isSPM = strpos($designation, 'SPM') !== false;
$isPM = strpos($designation, 'PM') !== false && strpos($designation, 'PLAQUE') !== false && !$isSPM;
$isSGCP = strpos($designation, 'SGCP') !== false || strpos($designation, 'SGCM') !== false;
$isSGA = (strpos($designation, 'SGA') !== false || strpos($designation, 'GRILLE') !== false) && !$isSGCP;
$isSGSA = strpos($designation, 'SGSA') !== false;
$isSLT = strpos($designation, 'SLT') !== false;
$isSRM = strpos($designation, 'SRM') !== false || strpos($designation, 'CÔNE') !== false || strpos($designation, 'CONE') !== false;

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
elseif ($isPM)
    $tempsPrev = '0h20';
elseif ($isSPM)
    $tempsPrev = '0h20';
elseif ($isSGCP)
    $tempsPrev = '3h00';
elseif ($isSGA)
    $tempsPrev = '3h30';
elseif ($isSGSA)
    $tempsPrev = '3h00';
elseif ($isSLT)
    $tempsPrev = '2h00';
elseif ($isSRM)
    $tempsPrev = '0h20';
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
        $listeNR = implode("\n", array_map(fn($i) => "- " . $i['designation'] . ($i['commentaire'] ? " (" . $i['commentaire'] . ")" : ""), $issues['nr']));
        $listeNC = implode("\n", array_map(fn($i) => "- " . $i['designation'] . ($i['commentaire'] ? " (" . $i['commentaire'] . ")" : ""), $issues['nc']));
        $listeAA = implode("\n", array_map(fn($i) => "- " . $i['designation'] . ($i['commentaire'] ? " (" . $i['commentaire'] . ")" : ""), $issues['aa']));

        $systemPrompt = "Tu es le rédacteur technique officiel des rapports d'expertise Lenoir-Mec (séparation magnétique et levage industriel, groupe Delachaux).

CONTEXTE : Tu rédiges la section \"E) CAUSE DE DYSFONCTIONNEMENT\" d'une fiche d'inspection terrain. Cette section apparaît dans un rapport PDF envoyé au client final. Le technicien a inspecté un équipement et relevé des anomalies.

ÉTATS D'ÉVALUATION :
- N/A = Non applicable (le point ne concerne pas cette machine)
- OK = Conforme
- A.A = À améliorer (point orange — dégradation constatée, pas critique)
- N.C = Non conforme (point rouge — défaut avéré nécessitant intervention)
- N.R = Nécessite remplacement (point rouge foncé — pièce HS ou dangereuse)

RÈGLES DE RÉDACTION STRICTES :
1. NE JAMAIS écrire le titre \"E) CAUSE DE DYSFONCTIONNEMENT\" — il est déjà imprimé sur le rapport.
2. NE JAMAIS ajouter de phrase d'introduction, de salutation, ou de conclusion.
3. NE JAMAIS inventer de constats non fournis dans les données.
4. Chaque anomalie = 1 tiret, 1 ligne, maximum 15 mots.
5. Commencer chaque tiret par le composant concerné, suivi du constat.
6. Si un commentaire technicien est fourni entre parenthèses, l'intégrer au constat.
7. Classer par gravité : d'abord N.R (remplacement), puis N.C (non conforme), puis A.A (à améliorer).
8. Si AUCUNE anomalie n'est fournie → répondre EXACTEMENT : \"Aucune anomalie détectée lors de l'inspection.\"
9. Style : industriel, factuel, impersonnel. Pas de \"nous\", pas de \"il faudrait\".";

        $userPrompt = "MACHINE : $typeMachine — Poste $poste\n\n";
        $userPrompt .= "POINTS À REMPLACER (N.R) :\n" . ($listeNR ?: "Néant") . "\n\n";
        $userPrompt .= "POINTS NON CONFORMES (N.C) :\n" . ($listeNC ?: "Néant") . "\n\n";
        $userPrompt .= "POINTS À AMÉLIORER (A.A) :\n" . ($listeAA ?: "Néant") . "\n\n";
        $userPrompt .= "Rédige les constats de dysfonctionnement.";

        return callGroqIA($systemPrompt, $userPrompt, [
            'temperature' => 0.15,
            'max_tokens' => 300,
            'top_p' => 0.9,
            'frequency_penalty' => 0.1,
            'presence_penalty' => 0.0
        ]) ?: "";
    } else {
        $systemPrompt = "Tu es le rédacteur technique officiel des rapports d'expertise Lenoir-Mec (séparation magnétique et levage industriel, groupe Delachaux).

CONTEXTE : Tu rédiges la section \"F) CONCLUSION\" d'une fiche d'inspection terrain. Cette conclusion apparaît dans un rapport PDF envoyé au client final. Elle synthétise le bilan technique de l'équipement inspecté.

TYPES D'ÉQUIPEMENTS LENOIR-MEC :
- OVAP / OV : Overband (séparateur magnétique à bande)
- APRF / APRM / RD : Aimant permanent rectangulaire fixe
- ED-X : Séparateur à courants de Foucault
- TAP / PAP : Tambour ou Poulie à Aimants Permanents
- Levage : Électroaimants de levage industriel
- SLT : Séparateur à Haute Intensité
- PM / PML / PMN / PMNL : Plaques Magnétiques (séparation manuelle)
- SPM : Séparateur à Plaques Magnétiques

RÈGLE DE PRIORITÉ :
- Si N.R > 0 → Priorité : URGENT (remplacement de pièce nécessaire)
- Si N.C > 0 et N.R = 0 → Priorité : MOYEN (défauts à corriger mais pas de danger immédiat)
- Si seulement A.A → Priorité : FAIBLE (dégradations mineures à surveiller)
- Si aucun défaut → Priorité : FAIBLE (fonctionnement nominal)

RÈGLES DE RÉDACTION STRICTES :
1. NE JAMAIS écrire le titre \"F) CONCLUSION\" — il est déjà imprimé sur le rapport.
2. NE JAMAIS ajouter de salutation, de remerciement, ou de formule de politesse.
3. Exactement 2 phrases. Pas 1, pas 3. Deux.
4. Phrase 1 : Bilan technique général (ex: \"Le bilan technique révèle...\" ou \"Le bilan technique est globalement satisfaisant...\").
5. Phrase 2 : Niveau de priorité (ex: \"Le niveau de priorité global est évalué comme [urgent/moyen/faible].\").
6. Si aucun dysfonctionnement → \"Le bilan technique est globalement satisfaisant avec aucun défaut majeur détecté. Le niveau de priorité global est évalué comme faible.\"
7. Style : impersonnel, factuel, professionnel. Pas de recommandations. Pas de \"nous conseillons\".";

        $count_nr = count($issues['nr']);
        $count_nc = count($issues['nc']);
        $count_aa = count($issues['aa']);
        $count_ok = 0;

        $allIssuesList = array_merge(
            array_map(fn($i) => "- NR: " . $i['designation'], $issues['nr']),
            array_map(fn($i) => "- NC: " . $i['designation'], $issues['nc']),
            array_map(fn($i) => "- AA: " . $i['designation'], $issues['aa'])
        );
        $allIssuesString = implode("\n", $allIssuesList);

        $userPrompt = "MACHINE : $typeMachine — Poste $poste\n\n";
        $userPrompt .= "RÉSUMÉ DES ANOMALIES :\n";
        $userPrompt .= "- Points à remplacer (N.R) : $count_nr\n";
        $userPrompt .= "- Points non conformes (N.C) : $count_nc\n";
        $userPrompt .= "- Points à améliorer (A.A) : $count_aa\n";
        $userPrompt .= "- Points conformes (OK) : $count_ok\n\n";
        $userPrompt .= "DÉTAIL DES ANOMALIES :\n" . ($allIssuesString ?: "Aucun défaut majeur.") . "\n\n";
        $userPrompt .= "Rédige la conclusion technique.";

        return callGroqIA($systemPrompt, $userPrompt, [
            'temperature' => 0.15,
            'max_tokens' => 300,
            'top_p' => 0.9,
            'frequency_penalty' => 0.1,
            'presence_penalty' => 0.0
        ]) ?: "";
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
            padding: 1.5cm 1cm 1cm 1cm; /* Augmentation du padding top pour éviter les textes coupés */
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

        .pdf-table thead {
            page-break-inside: avoid;
            page-break-after: avoid;
        }

        .pdf-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
            color: black;
            page-break-inside: auto;
        }

        .pdf-table tr {
            page-break-inside: avoid;
            break-inside: avoid;
        }

        .pdf-table th,
        .pdf-table td {
            border: 1px solid #000;
            padding: 4px 5px;
            vertical-align: middle;
        }

        /* Wrappers pour sections B, C, D, E, F */
        .section-wrapper-pdf {
            page-break-inside: avoid;
            break-inside: avoid;
            margin-top: 25px;
            margin-bottom: 25px;
            display: block;
            width: 100%;
        }

        .pdf-section-title {
            font-weight: bold;
            font-size: 16px;
            color: #d35400;
            margin-bottom: 10px;
            border-bottom: 2px solid #d35400;
            padding-bottom: 5px;
            page-break-after: avoid;
            break-after: avoid;
            text-transform: uppercase;
        }

        /* === PASTILLE SYSTEM === */
        .pastille-group {
            display: flex;
            align-items: center;
            justify-content: space-around;
            width: 140px;
            margin: 0 auto;
            flex-shrink: 0;
        }

        .pastille-group input[type="radio"] {
            display: none !important;
        }

        .pastille-group input[type="radio"] {
            display: none !important;
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
            background: #fdfdfd;
            box-sizing: border-box;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }

        /* Couleurs d'origine conservées */
        .pastille-group label.p-na { background: #bbb !important; border-color: #999 !important; }
        .pastille-group label.p-ok { background: #28a745 !important; border-color: #1e7e34 !important; }
        .pastille-group label.p-aa { background: #e67e22 !important; border-color: #d35400 !important; }
        .pastille-group label.p-nc { background: #dc3545 !important; border-color: #bd2130 !important; }
        .pastille-group label.p-nr { background: #8b0000 !important; border-color: #5a0000 !important; }

        .pastille-group label.selected {
            transform: scale(1.15);
            box-shadow: 0 0 0 3px rgba(0, 0, 0, 0.25);
            z-index: 10;
        }

        .pastille-group label.selected::after {
            content: '\2713';
            color: white !important;
            font-size: 14px;
            font-weight: bold;
            text-shadow: 0 1px 2px rgba(0, 0, 0, 0.4);
        }

        /* --- ZOOM / LIGHTBOX SYSTEM --- */
        #lightbox-modal {
            display: none;
            position: fixed;
            z-index: 10000;
            padding-top: 50px;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            overflow: auto;
            background-color: rgba(0,0,0,0.9);
            cursor: zoom-out;
        }
        #lightbox-modal img {
            margin: auto;
            display: block;
            max-width: 90%;
            max-height: 80%;
            border-radius: 5px;
            box-shadow: 0 0 20px rgba(0,0,0,0.5);
            animation-name: zoom;
            animation-duration: 0.3s;
        }
        @keyframes zoom {
            from {transform:scale(0)}
            to {transform:scale(1)}
        }
        #lightbox-caption {
            margin: auto;
            display: block;
            width: 80%;
            max-width: 700px;
            text-align: center;
            color: #ccc;
            padding: 20px 0;
            font-size: 18px;
            font-weight: bold;
        }
        .lightbox-close {
            position: absolute;
            top: 15px;
            right: 35px;
            color: #f1f1f1;
            font-size: 40px;
            font-weight: bold;
            transition: 0.3s;
            cursor: pointer;
        }
        .lightbox-close:hover {
            color: #bbb;
        }
        /* Curseur loupe pour les photos cliquables */
        .photo-thumb-wrap img, .montage-item img, .photo-annexe-item img {
            cursor: zoom-in;
            transition: opacity 0.2s;
        }
        .photo-thumb-wrap img:hover, .montage-item img:hover, .photo-annexe-item img:hover {
            opacity: 0.8;
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
            page-break-inside: avoid; /* Empêche la coupure au milieu des photos B */
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
            height: 110px;
            vertical-align: bottom;
            padding: 0 !important;
            position: relative;
            background: #e0e0e0 !important;
            overflow: visible;
            border-right: none !important;
            page-break-after: avoid;
            break-after: avoid;
        }
        .diagonal-header + th {
            border-left: none !important;
        }
        .diagonal-wrapper {
            display: flex;
            width: 140px;
            height: 100%;
            align-items: stretch;
            margin: 0 auto; /* Centrage pour aligner avec les pastilles */
            box-sizing: border-box;
        }
        .diag-col {
            width: 28px;
            height: 100%;
            position: relative;
            flex-shrink: 0;
        }
        .diag-col.col-3 {
            flex: 1; /* Aligne parfaitement sur 1/3 de 140px */
        }
        /* Ligne verticale basse (zone grise) */
        .diag-col::after {
            content: "";
            position: absolute;
            left: 100%; /* BORD DROIT de la gommette */
            bottom: 0;
            width: 1px;
            height: 35px;
            background: #000;
        }
        /* Ligne diagonale haute (zone grise) */
        .diag-col::before {
            content: "";
            position: absolute;
            left: 100%;
            top: 0;
            bottom: 35px;
            width: 1px;
            background: #000;
            transform: skewX(-35deg); /* Inclinaison vers la DROITE */
            transform-origin: bottom left;
        }
        .diag-text {
            position: absolute;
            bottom: 38px;
            left: 100%;
            padding-left: 4px; /* Décalage pour être à droite du trait */
            transform: rotate(-55deg);
            transform-origin: bottom left;
            text-align: left;
            white-space: nowrap;
            font-size: 8px;
            font-weight: bold;
            color: #000;
            width: 200px;
            line-height: 1.1;
        }
        .diag-text.col-3 {
            /* Suit le left: 100% de sa colonne parente */
        }

        /* --- SECTION BLUE BAR WITH LINES --- */
        .section-header-row {
            height: 30px;
            background: #5b9bd5 !important;
            padding: 0 !important;
        }
        .section-header-wrapper {
            display: flex;
            height: 100%;
            width: 100%;
            color: white;
            align-items: stretch;
        }
        .section-header-title {
            width: 35%;
            padding: 4px 10px;
            display: flex;
            align-items: center;
            font-size: 11px;
            font-weight: bold;
        }
        .section-header-cols {
            display: flex;
            width: 140px;
            height: 100%;
            margin: 0 auto; /* Centrage pour aligner avec les pastilles */
        }
        .section-header-col {
            width: 28px;
            flex-shrink: 0;
            height: 100%;
            position: relative;
            border-right: 1px solid #000;
            box-sizing: border-box;
        }
        .section-header-col.col-3 {
            width: 46.6px;
        }
        .section-header-comment {
            flex: 1;
            padding: 4px 10px;
            display: flex;
            align-items: center;
            font-size: 11px;
            font-weight: bold;
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

    <form method="POST" id="machineForm" autocomplete="off" class="autosave-form">
        <input type="hidden" name="action" value="save_machine">
        <?= csrfField() ?>

        <div class="top-bar">
            <button type="button" class="btn btn-ghost"
                onclick="window.location.href='intervention_edit.php?id=<?= $machine['intervention_id'] ?>'"
                style="padding: 0.4rem 0.8rem; color: var(--error); display:flex; align-items:center; gap:6px; font-weight: 700; font-size: 0.85rem; letter-spacing: 0.5px; border-radius: var(--radius-sm); border: 1px solid rgba(244, 63, 94, 0.2); background: rgba(244, 63, 94, 0.05);">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="m15 18-6-6 6-6"/></svg>
                RETOUR
            </button>
            <span class="mobile-header-title" style="color: var(--primary); font-size: 0.9rem; font-weight: 700; position: absolute; left: 50%; transform: translateX(-50%); white-space: nowrap;">
                <?= htmlspecialchars(str_to_upper_fr(str_replace('*', '', $machine['designation'] ?? ''))) ?>
            </span>
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
            <!-- Saut de page forcé en début de chaque machine pour éviter les coupures d'en-tête -->
            <div class="pdf-page" style="page-break-before: always;">
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
                                        ELECTROAIMANT DE TRIAGE FIXE RD
                                    <?php else: ?>
                                        AIMANT PERMANENT RECTANGULAIRE FIXE APRF
                                    <?php endif; ?>
                                <?php elseif ($isPAP): ?>
                                    TAMBOUR OU POULIE À AIMANTS PERMANENTS TAP/PAP
                                <?php elseif ($isLevage): ?>
                                    ELECTROAIMANTS DE LEVAGE
                                <?php elseif ($isSPM): ?>
                                    SÉPARATEUR À PLAQUES MAGNÉTIQUES SPM
                                <?php elseif ($isSLT): ?>
                                    SÉPARATEUR HAUTE INTENSITÉ SLT
                                <?php else: ?>
                                    <?= str_to_upper_fr(str_replace(['RDE', '*'], ['RD', ''], $machine['designation'] ?? '')) ?>
                                <?php endif; ?>
                            </span>
                        </td>
                    </tr>
                </table>

                <div class="section-wrapper-pdf">
                    <div class="pdf-section-title">A) FICHE DE CONTRÔLE :</div>
                
                <div style="font-weight:bold; color:#1B4F72; margin-bottom:5px; font-size:14px; page-break-after: avoid;">
                    Poste : <input type="text" name="mesures[poste]" value="<?= htmlspecialchars($mesures['poste'] ?? '') ?>" style="border:none; border-bottom:1px dashed #000; font-weight:bold; width:30px; background:transparent;" autocomplete="off">
                </div>

                <table
                    style="width:100%; border-collapse:collapse; border:1px solid #000; margin-bottom:20px; font-size:13px; color:#000; page-break-after: avoid; break-after: avoid;">
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
                            <span style="font-weight:bold; color:#1B4F72; font-size:14px;"><?= htmlspecialchars($tempsPrev) ?></span>
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
                </div>

                <?php
                // === HELPERS ===
                function newPdfPage() {
                    // MF-007 Fix: on ne casse plus les pages artificiellement entre sections
                    // html2pdf gère les sauts via le système de tbody splitting
                    return ''; 
                }
                function renderSectionB($photosData)
                {
                    $photos = $photosData['desc_materiel'] ?? [];
                    // Filtrer les entrées vides
                    $photos = array_values(array_filter($photos, function($p) { return !empty($p['data']); }));
                    $count = count($photos);
                    $isPdf = isset($_GET['pdf']);
                    
                    $html = '
                    <div class="section-wrapper-pdf">
                        <div class="pdf-section-title">B) DESCRIPTION DU MATÉRIEL :</div>
                        <div id="description_materiel_montage">';
                    
                    // Si aucune photo et mode édition, on affiche le placeholder avec bouton
                    // Si aucune photo et mode PDF, on affiche seulement le rectangle avec la croix
                    if ($count == 0) {
                        $html .= '
                        <div style="position:relative; width:100%; height:80px; border:1px solid #ddd; background:#fdfdfd; display:flex; align-items:center; justify-content:center; overflow:hidden; margin-bottom:10px;">
                            <svg style="position:absolute; top:0; left:0; width:100%; height:100%; pointer-events:none;" preserveAspectRatio="none">
                                <line x1="0" y1="0" x2="100%" y2="100%" style="stroke:#e0e0e0; stroke-width:1;" />
                                <line x1="0" y1="100%" x2="100%" y2="0" style="stroke:#e0e0e0; stroke-width:1;" />
                            </svg>
                            <div style="position:relative; z-index:1; font-size:13px; color:#999; font-weight:bold; text-align:center;">
                                Aucune photo prise pour la partie B
                                ' . (!$isPdf ? '<br><button type="button" class="btn btn-primary" onclick="capturePhoto(\'desc_materiel\')" style="margin-top:10px; height:32px; font-size:11px;">📷 Ajouter une photo</button>' : '') . '
                            </div>
                        </div>';
                    } else {
                        $gridClass = 'grid-' . ($count > 4 ? 4 : $count);
                        $html .= '<div class="photo-montage-grid ' . $gridClass . '">';
                        foreach (array_slice($photos, 0, 4) as $i => $p) {
                            $html .= '
                            <div class="montage-item">
                                <img src="' . htmlspecialchars($p['data']) . '" alt="Photo Matériel ' . ($i+1) . '" onclick="openLightbox(this.src, \'Photo Matériel ' . ($i+1) . '\')">
                                <button type="button" class="photo-del-overlay no-print-pdf" onclick="deletePhoto(\'desc_materiel\', ' . $i . ')">×</button>
                                ' . (!empty($p['comment']) ? '<div class="photo-comment-overlay">' . htmlspecialchars($p['comment']) . '</div>' : '') . '
                            </div>';
                        }
                        $html .= '</div>';
                    }
                    
                    if ($count < 4 && !$isPdf) {
                        $html .= '<div style="text-align:center; margin-top:10px;" class="no-print-pdf">
                            <button type="button" class="photo-btn no-print-pdf" onclick="capturePhoto(\'desc_materiel\')" style="padding:6px 12px; font-size:12px;">
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
                    return '<label class="' . $cssClass . $sel . '" title="' . $title . '" style="margin:0;">
                                <input type="radio" name="donnees[' . $name . ']" value="' . $value . '" ' . ($currentVal == $value ? 'checked' : '') . '>
                            </label>';
                }
                function renderEtatRadios($key, $donnees, $nbCols = 5)
                {
                    $val = $donnees[$key] ?? '';
                    $w = ($nbCols == 3) ? '33.33%' : '28px';
                    
                    if ($nbCols == 5) {
                        $items = [
                            ['pc', 'p-na', 'Pas concerné / NA'],
                            ['c', 'p-ok', 'Correct'],
                            ['aa', 'p-aa', 'À améliorer'],
                            ['nc', 'p-nc', 'Non correct'],
                            ['nr', 'p-nr', 'Non réparé / À revoir']
                        ];
                    } else {
                        $items = [
                            ['bon', 'p-ok', 'Bon'],
                            ['r', 'p-aa', 'À remplacer'],
                            ['hs', 'p-nc', 'HS']
                        ];
                    }

                    $html = '<div class="pastille-group">';
                    foreach ($items as $item) {
                        $p_w = ($nbCols == 3) ? '46.6px' : '28px';
                        $style = 'width:'.$p_w.'; display:flex; justify-content:center; align-items:center;';
                        $html .= '<div style="'.$style.'">'
                               . pastille($key, $item[0], $item[1], $item[2], $val)
                               . '</div>';
                    }
                    $html .= '</div>';
                    return $html;
                }
                function photoCamBtn($key, $label = '')
                {
                    global $photoLabelsMap, $photosData, $id;
                    if (!isset($photoLabelsMap)) $photoLabelsMap = [];
                    if ($label) $photoLabelsMap[$key] = $label;
                    
                    $thumbsHtml = '';
                    if (isset($_GET['pdf']) && !empty($photosData[$key])) {
                        foreach ($photosData[$key] as $p) {
                            $imgS = (strlen($p['data']) > 50000) 
                                ? "get_machine_photo.php?machine_id=$id&key=$key&photo_id=".$p['id'] 
                                : $p['data'];
                            $thumbsHtml .= '<span class="photo-thumb-wrap" style="margin-right:5px;">
                                <img src="' . htmlspecialchars($imgS) . '" style="width:40px; height:40px; object-fit:cover; border:1px solid #ccc; vertical-align:middle;">
                            </span>';
                        }
                    }

                    return '<div style="display:flex; align-items:center; gap:4px; margin-top:2px;">
                        <button type="button" class="photo-btn no-print-pdf" onclick="capturePhoto(\'' . $key . '\')">📷</button>
                        <span class="photo-thumbs" id="thumbs_' . $key . '">' . $thumbsHtml . '</span>
                    </div>';
                }

                function renderSectionC($isEDX, $isOV) {
                    ?>
                    <div class="section-wrapper-pdf" style="page-break-before: always; page-break-inside: avoid !important; break-inside: avoid !important;">
                        <table style="width: 100%; border-collapse: collapse; page-break-inside: avoid !important;">
                            <tr>
                                <td style="padding: 0; border: none; page-break-inside: avoid !important;">
                                    <div class="pdf-section-title"><?= str_to_upper_fr("C) RAPPEL DES FRÉQUENCES DE NETTOYAGE ET DES DIFFÉRENTS POINTS DE CONTRÔLE :") ?></div>
                                    <img src="/assets/machines/frequences_tableau.png" style="width:100%; height:auto; border:2px solid #ed7d31; display: block; page-break-inside: avoid !important;">
                                </td>
                            </tr>
                        </table>
                    </div>
                    <?php
                }
                function renderSectionD($isEDX, $mesures) {
                    if (!$isEDX) return;
                    $mini = $mesures['edx_releve_mini'] ?? '....';
                    $maxi = $mesures['edx_releve_maxi'] ?? '....';
                    ?>
                    <div class="section-wrapper-pdf">
                        <div class="pdf-section-title"><?= str_to_upper_fr("D) RELEVÉS D’INDUCTION MAGNÉTIQUE :") ?></div>
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
                        <td style="font-weight:bold; font-size:12px; width:35%;">' . htmlspecialchars($label) . '</td>
                        <td class="col-etat" style="text-align:center;">' . renderEtatRadios($key . "_radio", $donnees) . '</td>
                        <td class="col-comment"><textarea name="donnees[' . $key . '_comment]" class="pdf-textarea" placeholder="Détails..." oninput="autoGrow(this)">' . htmlspecialchars($donnees[$key . "_comment"] ?? '') . '</textarea>' . photoCamBtn($key, $label) . '</td>
                    </tr>';
                }
                function renderSectionHeader($title, $nbCols = 5) {
                    $w = ($nbCols == 3) ? '46.6px' : '28px';
                    $tds = '';
                    for ($i = 0; $i < $nbCols; $i++) {
                        $tds .= '<div style="width:'.$w.'; border-right:1px solid #000; height:100%; box-sizing:border-box;"></div>';
                    }
                    return '<tr class="section-header-row" style="background:#5b9bd5 !important; page-break-before: avoid; break-before: avoid;">
                        <td style="width:35%; font-weight:bold; color:white; padding:4px 10px; font-size:11px; page-break-before: avoid; break-before: avoid; border-top:1px solid #000;">' . str_to_upper_fr(htmlspecialchars($title)) . '</td>
                        <td style="width:140px; padding:0; vertical-align:middle; height:30px; border-top:1px solid #000;">
                            <div style="display:flex; width:140px; height:100%; margin: 0 auto; border:none; border-left: 1px solid #000;">' . $tds . '</div>
                        </td>
                        <td style="width:35%; background:#5b9bd5 !important; border-left:none !important; border-top:1px solid #000;"></td>
                    </tr>';
                }
                function renderEdxRow($label, $key, $donnees)
                {
                    return '<tr>
                        <td style="font-weight:bold; font-size:11px; width:35%;">' . htmlspecialchars($label) . '</td>
                        <td style="padding:0; vertical-align:middle; text-align:center; width:140px;">' . renderEtatRadios($key, $donnees, 5) . '</td>
                        <td style="padding:0; width:35%;"><textarea name="donnees[' . $key . '_comment]" class="pdf-textarea" style="border:none; width:100%; padding:4px;" oninput="autoGrow(this)">' . htmlspecialchars($donnees[$key . "_comment"] ?? '') . '</textarea>' . photoCamBtn($key, $label) . '</td>
                    </tr>';
                }
                function renderOvRow($label, $key, $donnees)
                {
                    return '<tr>
                        <td style="font-weight:bold; font-size:11px; width:35%;">' . htmlspecialchars($label) . '</td>
                        <td style="padding:0; vertical-align:middle; text-align:center; width:140px;">' . renderEtatRadios($key, $donnees, 5) . '</td>
                        <td style="padding:0; width:35%;"><textarea name="donnees[' . $key . '_comment]" class="pdf-textarea" style="border:none; width:100%; padding:4px;" oninput="autoGrow(this)">' . htmlspecialchars($donnees[$key . "_comment"] ?? '') . '</textarea>' . photoCamBtn($key, $label) . '</td>
                    </tr>';
                }
                function renderAprfRow($label, $key, $donnees)
                {
                    return '<tr>
                        <td style="font-weight:bold; font-size:11px; width:35%;">' . htmlspecialchars($label) . '</td>
                        <td style="padding:0; vertical-align:middle; text-align:center; width:140px;">' . renderEtatRadios($key, $donnees, 3) . '</td>
                        <td style="padding:0; width:35%;"><textarea name="donnees[' . $key . '_comment]" class="pdf-textarea" style="border:none; width:100%; padding:4px;" oninput="autoGrow(this)">' . htmlspecialchars($donnees[$key . "_comment"] ?? '') . '</textarea>' . photoCamBtn($key, $label) . '</td>
                    </tr>';
                }
                function renderFreqRowEdx($label, $key, $donnees)
                {
                    $v = $donnees[$key] ?? '';
                    return '<tr>
                        <td style="font-weight:normal; font-size:10px; width:40%;">' . htmlspecialchars($label) . '</td>
                        <td style="text-align:center;"><input type="radio" name="donnees[' . $key . ']" value="q" ' . ($v == 'q' ? 'checked' : '') . '></td>
                        <td style="text-align:center;"><input type="radio" name="donnees[' . $key . ']" value="h" ' . ($v == 'h' ? 'checked' : '') . '></td>
                        <td style="text-align:center;"><input type="radio" name="donnees[' . $key . ']" value="m" ' . ($v == 'm' ? 'checked' : '') . '></td>
                        <td style="text-align:center;"><input type="radio" name="donnees[' . $key . ']" value="a" ' . ($v == 'a' ? 'checked' : '') . '></td>
                        <td style="width:25%;"><textarea name="donnees[' . $key . '_comment]" class="pdf-textarea" style="height:30px; border:none; width:100%; box-sizing:border-box; padding:4px;">' . htmlspecialchars($donnees[$key . "_comment"] ?? '') . '</textarea></td>
                    </tr>';
                }
                function renderFreqRow($label, $key, $donnees)
                {
                    $v = $donnees[$key] ?? '';
                    return '<tr>
                        <td style="font-weight:bold; font-size:11px; width:35%;">' . htmlspecialchars($label) . '</td>
                        <td style="text-align:center;"><input type="radio" name="donnees[' . $key . ']" value="q" ' . ($v == 'q' ? 'checked' : '') . '></td>
                        <td style="text-align:center;"><input type="radio" name="donnees[' . $key . ']" value="h" ' . ($v == 'h' ? 'checked' : '') . '></td>
                        <td style="text-align:center;"><input type="radio" name="donnees[' . $key . ']" value="m" ' . ($v == 'm' ? 'checked' : '') . '></td>
                        <td style="text-align:center;"><input type="radio" name="donnees[' . $key . ']" value="a" ' . ($v == 'a' ? 'checked' : '') . '></td>
                        <td style="padding:0; width:35%;"><textarea name="donnees[' . $key . '_comment]" class="pdf-textarea" style="height:30px; border:none; width:100%; box-sizing:border-box; padding:4px;">' . htmlspecialchars($donnees[$key . "_comment"] ?? '') . '</textarea></td>
                    </tr>';
                }
                function renderDiagonalHeader($nbCols = 5) {
                    $labels = ($nbCols == 5) 
                        ? ['Pas concerné', 'Correct', 'A améliorer', 'Pas correct', 'Nécessite<br>remplacement']
                        : ['Bon', 'A remp.<br>sous :', 'H.S.'];
                    
                    $colsHtml = '';
                    $colClass = ($nbCols == 3) ? ' col-3' : '';
                    foreach ($labels as $lbl) {
                        $colsHtml .= '<div class="diag-col' . $colClass . '"><div class="diag-text' . $colClass . '">' . $lbl . '</div></div>';
                    }
                    
                    $commentTitle = ($nbCols == 5) ? 'COMMENTAIRES / VALEURS' : 'COMMENTAIRES';
                    
                    return '<tr style="page-break-after: avoid; break-after: avoid;">
                        <th style="width:35%; text-align:center; vertical-align:middle; background:#e0e0e0; font-size:11px; page-break-after: avoid; break-after: avoid; border-top: 1px solid #000; border-right: 1px solid #000;">DESIGNATIONS</th>
                        <th class="diagonal-header" style="width:140px; page-break-after: avoid; break-after: avoid;">
                            <div class="diagonal-wrapper" style="border-left:1px solid #000;">' . $colsHtml . '</div>
                        </th>
                        <th style="width:35%; text-align:center; vertical-align:middle; background:#e0e0e0; font-size:11px; border-top: 1px solid #000;">' . $commentTitle . '</th>
                    </tr>';
                }
                function renderAprfEtatRadios($key, $donnees)
                {
                    return renderEtatRadios($key, $donnees, 3);
                }
                ?>

                <!-- DYNAMIC CONTENT DEPENDING ON MACHINE TYPE -->
                <?php if ($isAPRF): ?>

                    <?= newPdfPage() ?>
                    <table class="pdf-table controles" style="font-size:11px; margin-top:0;">
                        <thead>
                            <?= renderDiagonalHeader(3) ?>
                        </thead>
                        <tbody>

                        <?= renderSectionHeader("Aimants permanent fixe de triage type APRF", 3) ?>
                        <?= renderAprfRow("Satisfaction de fonctionnement", "aprf_satisfaction", $donnees) ?>
                        <?= renderAprfRow("État et type de la bande", "aprf_bande", $donnees) ?>
                        <?= renderAprfRow("État des réglettes", "aprf_reglettes", $donnees) ?>
                        <?= renderAprfRow("État des boutons étoile :", "aprf_boutons", $donnees) ?>
                        <?= renderAprfRow("Options (à préciser)", "aprf_options", $donnees) ?>

                        <?= renderSectionHeader("AIMANT PERMANENT", 3) ?>
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
                            <td style="padding:4px; padding-left:25px; font-size:11px; width:35%;"><?= $alabel ?></td>
                            <td style="border:1px solid #000; text-align:center; padding:0; vertical-align:middle; width:140px;">
                                <?= renderEtatRadios("aprf_attr_$akey", $donnees, 3) ?>
                            </td>
                            <td style="padding:0; width:35%;">
                                <textarea name="donnees[aprf_attr_<?= $akey ?>_comment]" class="pdf-textarea" style="height:25px; border:none; width:100%; box-sizing:border-box; padding:4px;" placeholder="Commentaires..."><?= htmlspecialchars($donnees["aprf_attr_$akey" . "_comment"] ?? '') ?></textarea>
                            </td>
                        </tr>
                        <?php endforeach; ?>

                        <?= renderSectionHeader("APPLICATION CLIENT", 3) ?>
                        <tr>
                            <td style="padding:4px; font-weight:bold; width:35%;">
                                Type de produit : 
                                <input type="text" name="mesures[aprf_produit]" value="<?= htmlspecialchars($mesures['aprf_produit'] ?? '') ?>" class="pdf-input" style="width:150px;">
                            </td>
                            <td style="border:1px solid #000; text-align:center; padding:0; vertical-align:middle; width:140px;">
                                <?= renderEtatRadios("aprf_produit_stat", $donnees, 3) ?>
                            </td>
                            <td style="padding:0; width:35%;"><textarea name="donnees[aprf_produit_comment]" class="pdf-textarea" style="height:30px; border:none; width:100%; box-sizing:border-box; padding:4px;"><?= htmlspecialchars($donnees["aprf_produit_comment"] ?? '') ?></textarea></td>
                        </tr>
                        <tr>
                            <td style="padding:4px; font-weight:bold;">
                                Granulométrie : 
                                <input type="text" name="mesures[aprf_granu]" value="<?= htmlspecialchars($mesures['aprf_granu'] ?? '') ?>" class="pdf-input" style="width:60px; text-align:center;"> mm
                            </td>
                            <td style="border:1px solid #000; text-align:center; padding:0; vertical-align:middle; width:70px;">
                                <?= renderAprfEtatRadios("aprf_granu_stat", $donnees) ?>
                            </td>
                            <td style="padding:0;"><textarea name="donnees[aprf_granu_comment]" class="pdf-textarea" style="height:30px; border:none; width:100%; box-sizing:border-box; padding:4px;"><?= htmlspecialchars($donnees["aprf_granu_comment"] ?? '') ?></textarea></td>
                        </tr>
                        <tr>
                            <td style="padding:4px; font-weight:bold;">
                                Distance aimants / bande : 
                                <input type="text" name="mesures[aprf_dist_min]" value="<?= htmlspecialchars($mesures['aprf_dist_min'] ?? '') ?>" class="pdf-input" style="width:40px; text-align:center;"> à 
                                <input type="text" name="mesures[aprf_dist_max]" value="<?= htmlspecialchars($mesures['aprf_dist_max'] ?? '') ?>" class="pdf-input" style="width:40px; text-align:center;"> mm
                            </td>
                            <td style="border:1px solid #000; text-align:center; padding:0; vertical-align:middle; width:70px;">
                                <?= renderAprfEtatRadios("aprf_dist_stat", $donnees) ?>
                            </td>
                            <td style="padding:0;"><textarea name="donnees[aprf_dist_comment]" class="pdf-textarea" style="height:30px; border:none; width:100%; box-sizing:border-box; padding:4px;"><?= htmlspecialchars($donnees["aprf_dist_comment"] ?? '') ?></textarea></td>
                        </tr>
                        <tr>
                            <td style="padding:4px; font-weight:bold;">
                                Hauteur de la couche : 
                                <input type="text" name="mesures[aprf_H_couche]" value="<?= htmlspecialchars($mesures['aprf_H_couche'] ?? '') ?>" class="pdf-input" style="width:80px; text-align:center;"> mm
                            </td>
                            <td style="border:1px solid #000; text-align:center; padding:0; vertical-align:middle; width:70px;">
                                <?= renderAprfEtatRadios("aprf_H_stat", $donnees) ?>
                            </td>
                            <td style="padding:0;"><textarea name="donnees[aprf_H_comment]" class="pdf-textarea" style="height:30px; border:none; width:100%; box-sizing:border-box; padding:4px;"><?= htmlspecialchars($donnees["aprf_H_comment"] ?? '') ?></textarea></td>
                        </tr>
                        <tr>
                            <td style="padding:4px; font-weight:bold;">
                                Débit : 
                                <input type="text" name="mesures[aprf_debit]" value="<?= htmlspecialchars($mesures['aprf_debit'] ?? '') ?>" class="pdf-input" style="width:60px; text-align:center;"> t/h
                            </td>
                            <td style="border:1px solid #000; text-align:center; padding:0; vertical-align:middle; width:70px;">
                                <?= renderAprfEtatRadios("aprf_debit_stat", $donnees) ?>
                            </td>
                            <td style="padding:4px;">
                                Avec densité de <input type="text" name="mesures[aprf_densite]" value="<?= htmlspecialchars($mesures['aprf_densite'] ?? '') ?>" class="pdf-input" style="width:60px; text-align:center;">
                            </td>
                        </tr>
                        </tbody>
                    </table>


                    <div class="pdf-section" style="margin-top:20px;">
                        <img src="/assets/machines/aprf_diagram.png"
                            style="max-width:100%; height:auto; display:block; margin:20px auto;" alt="Schéma APRF"
                            onerror="this.style.display='none'">
                    </div>

                    <!-- EDX SCHEMA -->
                <?php elseif ($isPM): ?>

                    <?= newPdfPage() ?>
                    <table class="pdf-table controles" style="font-size:11px; margin-top:0;">
                        <thead>
                            <?= renderDiagonalHeader(3) ?>
                        </thead>
                        <tbody>
                            <?= renderSectionHeader("Plaques Magnétiques type PM/PML/PMN/PMNL", 3) ?>
                            <?= renderAprfRow("Satisfaction de fonctionnement", "pm_satisfaction", $donnees) ?>
                            <?= renderAprfRow("Aspect général", "pm_aspect", $donnees) ?>

                            <tr>
                                <th colspan="3" style="background:#e0e0e0; font-weight:bold; font-size:11px; border-top: 1px solid #000; border-bottom: 1px solid #000; padding:4px;">Contrôle d’induction :</th>
                            </tr>
                            <?php 
                            $inductions = [
                                'p1' => ['label' => 'P1 =', 'suffix' => 'Gauss (contre)'],
                                'p2' => ['label' => 'P2 =', 'suffix' => 'Gauss (bord largeur)'],
                                'p3' => ['label' => 'P3 =', 'suffix' => 'Gauss (bord longueur)']
                            ];
                            foreach($inductions as $ikey => $idata): ?>
                            <tr>
                                <td style="padding:4px; padding-left:25px; font-size:11px; width:35%;">
                                    <span style="font-weight:bold; margin-right:5px;">➤</span> <?= $idata['label'] ?> 
                                    <input type="text" name="mesures[pm_induction_<?= $ikey ?>]" value="<?= htmlspecialchars($mesures['pm_induction_' . $ikey] ?? '') ?>" style="width:50px; border:none; border-bottom:1px dashed #000; text-align:center; font-weight:bold; font-size:11px;" autocomplete="off"> 
                                    <?= $idata['suffix'] ?>
                                </td>
                                <td style="border:1px solid #000; text-align:center; padding:0; vertical-align:middle; width:140px;">
                                    <?= renderEtatRadios("pm_induction_" . $ikey . "_stat", $donnees, 3) ?>
                                </td>
                                <td style="padding:0; width:35%;"><textarea name="donnees[pm_induction_<?= $ikey ?>_stat_comment]" class="pdf-textarea" style="height:25px; border:none; width:100%; box-sizing:border-box; padding:4px;" placeholder="Commentaires..."><?= htmlspecialchars($donnees["pm_induction_" . $ikey . "_stat_comment"] ?? '') ?></textarea></td>
                            </tr>
                            <?php endforeach; ?>

                            <tr>
                                <th colspan="3" style="background:#e0e0e0; font-weight:bold; font-size:11px; border-top: 1px solid #000; border-bottom: 1px solid #000; padding:4px;">Distance d'attraction :</th>
                            </tr>
                            <?php 
                            $distances = [
                                'ecrou'   => 'Ecrou M4 =',
                                'rond50'  => 'Rond Ø6 Lg50 =',
                                'rond100' => 'Rond Ø6 Lg100 =',
                                'bille'   => 'Bille Ø20 ='
                            ];
                            foreach($distances as $dkey => $dlabel): ?>
                            <tr>
                                <td style="padding:4px; padding-left:25px; font-size:11px; width:35%;">
                                    <span style="font-weight:bold; margin-right:5px;">➤</span> <?= $dlabel ?> 
                                    <input type="text" name="mesures[pm_dist_<?= $dkey ?>]" value="<?= htmlspecialchars($mesures['pm_dist_' . $dkey] ?? '') ?>" style="width:40px; border:none; border-bottom:1px dashed #000; text-align:center; font-weight:bold; font-size:11px;" autocomplete="off"> mm
                                </td>
                                <td style="border:1px solid #000; text-align:center; padding:0; vertical-align:middle; width:140px;">
                                    <?= renderEtatRadios("pm_dist_" . $dkey . "_stat", $donnees, 3) ?>
                                </td>
                                <td style="padding:0; width:35%;"><textarea name="donnees[pm_dist_<?= $dkey ?>_stat_comment]" class="pdf-textarea" style="height:25px; border:none; width:100%; box-sizing:border-box; padding:4px;" placeholder="Commentaires..."><?= htmlspecialchars($donnees["pm_dist_" . $dkey . "_stat_comment"] ?? '') ?></textarea></td>
                            </tr>
                            <?php endforeach; ?>

                            <tr>
                                <td style="padding:4px; font-weight:bold; font-size:11px; width:35%;">Type d’aimants : Ferrite / Néodyme</td>
                                <td style="border:1px solid #000; text-align:center; padding:0; vertical-align:middle; width:140px;">
                                    <?= renderEtatRadios("pm_aimants", $donnees, 3) ?>
                                </td>
                                <td style="padding:0; width:35%;"><textarea name="donnees[pm_aimants_comment]" class="pdf-textarea" style="height:25px; border:none; width:100%; box-sizing:border-box; padding:4px;"><?= htmlspecialchars($donnees["pm_aimants_comment"] ?? '') ?></textarea></td>
                            </tr>
                            <tr>
                                <td style="padding:4px; font-weight:bold; font-size:11px; width:35%;">
                                    Température d’utilisation : Ambiante / Haute : 
                                    <input type="text" name="mesures[pm_temp_valeur]" value="<?= htmlspecialchars($mesures['pm_temp_valeur'] ?? '') ?>" style="width:35px; border:none; border-bottom:1px dashed #000; text-align:center; font-weight:bold; font-size:11px;" autocomplete="off"> °C
                                </td>
                                <td style="border:1px solid #000; text-align:center; padding:0; vertical-align:middle; width:140px;">
                                    <?= renderEtatRadios("pm_temperature", $donnees, 3) ?>
                                </td>
                                <td style="padding:0; width:35%;"><textarea name="donnees[pm_temperature_comment]" class="pdf-textarea" style="height:25px; border:none; width:100%; box-sizing:border-box; padding:4px;"><?= htmlspecialchars($donnees["pm_temperature_comment"] ?? '') ?></textarea></td>
                            </tr>

                            <?= renderAprfRow("Etat du volet (option)", "pm_volet", $donnees) ?>
                            <?= renderAprfRow("Etat des châssis (option)", "pm_chassis", $donnees) ?>
                            <?= renderAprfRow("Etat des ressauts (option)", "pm_ressauts", $donnees) ?>

                            <?= renderSectionHeader("APPLICATION CLIENT", 3) ?>
                            <tr>
                                <td style="padding:4px; font-weight:bold; width:35%;">
                                    Type de produit : 
                                    <input type="text" name="mesures[pm_produit]" value="<?= htmlspecialchars($mesures['pm_produit'] ?? '') ?>" class="pdf-input" style="width:150px;" autocomplete="off">
                                </td>
                                <td style="border:1px solid #000; text-align:center; padding:0; vertical-align:middle; width:140px;">
                                    <?= renderEtatRadios("pm_produit_stat", $donnees, 3) ?>
                                </td>
                                <td style="padding:4px; font-size:11px; color:#555;">
                                    Aciers de <input type="text" name="mesures[pm_prod_min]" value="<?= htmlspecialchars($mesures['pm_prod_min'] ?? '') ?>" style="width:25px; border:none; border-bottom:1px dashed #000; text-align:center;" autocomplete="off"> à <input type="text" name="mesures[pm_prod_max]" value="<?= htmlspecialchars($mesures['pm_prod_max'] ?? '') ?>" style="width:25px; border:none; border-bottom:1px dashed #000; text-align:center;" autocomplete="off"> mm
                                </td>
                            </tr>
                            <tr>
                                <td style="padding:4px; font-weight:bold;">
                                    Granulométrie : 
                                    <input type="text" name="mesures[pm_granulo]" value="<?= htmlspecialchars($mesures['pm_granulo'] ?? '') ?>" class="pdf-input" style="width:60px; text-align:center;" autocomplete="off"> mm
                                </td>
                                <td style="border:1px solid #000; text-align:center; padding:0; vertical-align:middle; width:70px;">
                                    <?= renderEtatRadios("pm_granulo_stat", $donnees, 3) ?>
                                </td>
                                <td style="padding:0;"><textarea name="donnees[pm_granulo_comment]" class="pdf-textarea" style="height:30px; border:none; width:100%; box-sizing:border-box; padding:4px;" autocomplete="off"><?= htmlspecialchars($donnees["pm_granulo_comment"] ?? '') ?></textarea></td>
                            </tr>
                            <tr>
                                <td style="padding:4px; font-weight:bold;">
                                    Débit : 
                                    <input type="text" name="mesures[pm_debit]" value="<?= htmlspecialchars($mesures['pm_debit'] ?? '') ?>" class="pdf-input" style="width:60px; text-align:center;" autocomplete="off"> t/h
                                </td>
                                <td style="border:1px solid #000; text-align:center; padding:0; vertical-align:middle; width:70px;">
                                    <?= renderEtatRadios("pm_debit_stat", $donnees, 3) ?>
                                </td>
                                <td style="padding:4px; font-size:11px; color:#555;">
                                    Avec densité de <input type="text" name="mesures[pm_densite]" value="<?= htmlspecialchars($mesures['pm_densite'] ?? '') ?>" style="width:50px; border:none; border-bottom:1px dashed #000; text-align:center;" autocomplete="off">
                                </td>
                            </tr>
                            <tr>
                                <td style="padding:4px; font-weight:bold;">
                                    Environnement Atex ?
                                </td>
                                <td style="border:1px solid #000; text-align:center; padding:0; vertical-align:middle; width:70px;">
                                    <div style="display:flex; justify-content:center; gap:10px; font-size:10px;">
                                        <label><input type="radio" name="mesures[pm_atex]" value="non" <?= ($mesures['pm_atex'] ?? '') === 'non' ? 'checked' : '' ?>> Non</label>
                                        <label><input type="radio" name="mesures[pm_atex]" value="oui" <?= ($mesures['pm_atex'] ?? '') === 'oui' ? 'checked' : '' ?>> Oui</label>
                                    </div>
                                </td>
                                <td style="padding:4px;"><input type="text" name="mesures[pm_atex_precision]" value="<?= htmlspecialchars($mesures['pm_atex_precision'] ?? '') ?>" placeholder="Préciser..." style="width:100%; border:none; border-bottom:1px dashed #000; font-size:11px;" autocomplete="off"></td>
                            </tr>

                        </tbody>
                    </table>

                    <div style="text-align:center; margin-top:20px;">
                        <img src="/assets/machines/pm_diagram.png" 
                             style="max-width:100%; height:auto; display:block; margin:0 auto;" 
                             alt="Schémas Plaques Magnétiques PM"
                             onerror="this.style.display='none'">
                    </div>

                <?php elseif ($isSGCP): ?>

                    <table class="pdf-table controles" style="font-size:11px; margin-top:0;">
                        <thead>
                            <?= renderDiagonalHeader(3) ?>
                        </thead>
                        <tbody>
                            <?= renderSectionHeader("Séparateur à grilles à commande pneumatique ou manuelle", 3) ?>
                            <?= renderAprfRow("Satisfaction de fonctionnement", "sgcp_satisfaction", $donnees) ?>
                            <?= renderAprfRow("Aspect général", "sgcp_aspect", $donnees) ?>
                            <?= renderAprfRow("Fréquence de nettoyage", "sgcp_f", $donnees) ?>

                            <?= renderSectionHeader("APPLICATION CLIENT", 3) ?>
                            <tr>
                                <td style="padding:8px; font-weight:bold;">
                                    Type de produit : 
                                    <input type="text" name="mesures[sgcp_produit]" value="<?= htmlspecialchars($mesures['sgcp_produit'] ?? '') ?>" class="pdf-input" style="width:140px; margin-left:5px;">
                                </td>
                                <td style="border:1px solid #000; text-align:center; padding:0; vertical-align:middle; width:140px;">
                                    <?= renderEtatRadios("sgcp_produit_stat", $donnees, 3) ?>
                                </td>
                                <td style="padding:8px; font-size:10px; color:#555;">
                                    <textarea name="donnees[sgcp_produit_comment]" class="pdf-textarea" style="height:35px; border:none; width:100%; box-sizing:border-box; padding:4px;"><?= htmlspecialchars($donnees['sgcp_produit_comment'] ?? '') ?></textarea>
                                </td>
                            </tr>
                            <tr>
                                <td style="padding:8px; font-weight:bold;">
                                    Granulométrie : 
                                    <input type="text" name="mesures[sgcp_granulo_min]" value="<?= htmlspecialchars($mesures['sgcp_granulo_min'] ?? '') ?>" style="width:40px; border:none; border-bottom:1px solid #000; text-align:center;"> à <input type="text" name="mesures[sgcp_granulo_max]" value="<?= htmlspecialchars($mesures['sgcp_granulo_max'] ?? '') ?>" style="width:40px; border:none; border-bottom:1px solid #000; text-align:center;"> mm
                                </td>
                                <td style="border:1px solid #000; text-align:center; padding:0; vertical-align:middle; width:140px;">
                                    <?= renderEtatRadios("sgcp_granulo_stat", $donnees, 3) ?>
                                </td>
                                <td style="padding:8px; font-size:10px; color:#555;">
                                    Aciers de <input type="text" name="mesures[sgcp_acier_min]" value="<?= htmlspecialchars($mesures['sgcp_acier_min'] ?? '') ?>" style="width:30px; border:none; border-bottom:1px dashed #000; text-align:center;"> à <input type="text" name="mesures[sgcp_acier_max]" value="<?= htmlspecialchars($mesures['sgcp_acier_max'] ?? '') ?>" style="width:30px; border:none; border-bottom:1px dashed #000; text-align:center;"> mm
                                </td>
                            </tr>
                            <tr>
                                <td style="padding:8px; font-weight:bold;">
                                    Débit : 
                                    <input type="text" name="mesures[sgcp_debit]" value="<?= htmlspecialchars($mesures['sgcp_debit'] ?? '') ?>" style="width:60px; border:none; border-bottom:1px solid #000; text-align:center; margin-left:5px;"> t/h
                                </td>
                                <td style="border:1px solid #000; text-align:center; padding:0; vertical-align:middle; width:140px;">
                                    <?= renderEtatRadios("sgcp_debit_stat", $donnees, 3) ?>
                                </td>
                                <td style="padding:8px; font-size:10px; color:#555;">
                                    Avec densité de <input type="text" name="mesures[sgcp_densite]" value="<?= htmlspecialchars($mesures['sgcp_densite'] ?? '') ?>" style="width:60px; border:none; border-bottom:1px dashed #000; text-align:center;">
                                </td>
                            </tr>
                            <tr>
                                <td style="padding:8px; font-weight:bold;">
                                    Environnement Atex ? Non – Oui :
                                    <input type="text" name="mesures[sgcp_atex]" value="<?= htmlspecialchars($mesures['sgcp_atex'] ?? '') ?>" style="width:100px; border:none; border-bottom:1px solid #000; text-align:center; margin-left:5px;">
                                </td>
                                <td style="border:1px solid #000; text-align:center; padding:0; vertical-align:middle; width:140px;">
                                    <?= renderEtatRadios("sgcp_atex_stat", $donnees, 3) ?>
                                </td>
                                <td style="padding:0;"><textarea name="donnees[sgcp_atex_comment]" class="pdf-textarea" style="height:35px; border:none; width:100%; box-sizing:border-box; padding:4px;"><?= htmlspecialchars($donnees['sgcp_atex_comment'] ?? '') ?></textarea></td>
                            </tr>

                            <?php for($e=1; $e<=2; $e++): ?>
                                <?= renderSectionHeader("ETAGE ".($e==1 ? '1 (Supérieur)' : $e), 3) ?>
                                <tr>
                                    <td style="padding:8px; vertical-align:top;">
                                        <div style="margin-bottom:8px; font-weight:bold;">
                                            Nombre de barreaux : 
                                            <input type="text" name="mesures[sgcp_e<?= $e ?>_nb]" value="<?= htmlspecialchars($mesures['sgcp_e'.$e.'_nb'] ?? '') ?>" style="width:40px; border:none; border-bottom:1px solid #000; text-align:center; font-weight:bold;" autocomplete="off">
                                        </div>
                                        
                                        <div style="background:#fcfcfc; border:1px solid #eee; padding:5px;">
                                            <div style="font-size:9px; color:#888; margin-bottom:5px; font-weight:bold;">Barreaux (Gauss Max) :</div>
                                            <table style="width:100%; border-collapse:collapse; border:none; font-size:10px;">
                                                <tr>
                                                    <td style="width:50%; border:none; padding-right:5px; vertical-align:top;">
                                                        <?php for($b=1; $b<=6; $b++): ?>
                                                            <div style="display:flex; justify-content:space-between; margin-bottom:3px; border-bottom:1px solid #f0f0f0;">
                                                                <span style="font-weight:600;"><?= $b ?> :</span>
                                                                <span><input type="text" name="mesures[sgcp_e<?= $e ?>_b<?= $b ?>]" value="<?= htmlspecialchars($mesures['sgcp_e'.$e.'_b'.$b] ?? '') ?>" style="width:35px; border:none; text-align:center; font-size:10px;"> <span style="font-size:8px; color:#aaa;">G</span></span>
                                                            </div>
                                                        <?php endfor; ?>
                                                    </td>
                                                    <td style="width:50%; border:none; padding-left:5px; vertical-align:top; border-left:1px solid #eee;">
                                                        <?php for($b=7; $b<=11; $b++): ?>
                                                            <div style="display:flex; justify-content:space-between; margin-bottom:3px; border-bottom:1px solid #f0f0f0;">
                                                                <span style="font-weight:600;"><?= $b ?> :</span>
                                                                <span><input type="text" name="mesures[sgcp_e<?= $e ?>_b<?= $b ?>]" value="<?= htmlspecialchars($mesures['sgcp_e'.$e.'_b'.$b] ?? '') ?>" style="width:35px; border:none; text-align:center; font-size:10px;"> <span style="font-size:8px; color:#aaa;">G</span></span>
                                                            </div>
                                                        <?php endfor; ?>
                                                    </td>
                                                </tr>
                                            </table>
                                        </div>
                                    </td>
                                    <td style="border:1px solid #000; text-align:center; padding:0; vertical-align:middle; width:140px;">
                                        <div style="margin-bottom:8px; font-size:9px; color:#888; font-weight:bold; letter-spacing:0.5px;">ÉTAT GLOBAL ÉTAGE</div>
                                        <?= renderEtatRadios("sgcp_e".$e."_stat", $donnees, 3) ?>
                                    </td>
                                    <td style="padding:0; vertical-align:top; width:30%;">
                                        <textarea name="donnees[sgcp_e<?= $e ?>_comment]" class="pdf-textarea" style="height:100%; min-height:140px; border:none; width:100%; box-sizing:border-box; padding:8px;" placeholder="Observation étage <?= $e ?>..."><?= htmlspecialchars($donnees["sgcp_e".$e."_comment"] ?? '') ?></textarea>
                                    </td>
                                </tr>
                                <?= renderAprfRow("Coulissement des barreaux", "sgcp_e".$e."_coulissement", $donnees) ?>
                                <?= renderAprfRow("Etanchéité du tiroir à la fermeture", "sgcp_e".$e."_etancheite", $donnees) ?>
                            <?php endfor; ?>

                        </tbody>
                    </table>

                    <div style="display:flex; flex-direction:column; align-items:center; gap:20px; margin-top:30px;">
                        <div style="text-align:center; width:100%;">
                            <p style="font-weight:bold; margin-bottom:5px; color:#d35400; text-align:left; border-bottom:1px solid #ed7d31; padding-bottom:5px;">SGCP :</p>
                            <img src="/assets/machines/sgcp_diagram.png" 
                                 style="max-width:100%; height:auto; display:block; margin:0 auto; border:1px solid #eee; border-radius:8px;" 
                                 alt="Schéma SGCP"
                                 onerror="this.style.display='none'">
                        </div>
                        <div style="text-align:center; width:100%;">
                            <p style="font-weight:bold; margin-bottom:5px; color:#d35400; text-align:left; border-bottom:1px solid #ed7d31; padding-bottom:5px;">SGCM :</p>
                            <img src="/assets/machines/sgcm_diagram.png" 
                                 style="max-width:100%; height:auto; display:block; margin:0 auto; border:1px solid #eee; border-radius:8px;" 
                                 alt="Schéma SGCM"
                                 onerror="this.style.display='none'">
                        </div>
                    </div>

                <?php elseif ($isSGSA): ?>

                    <table class="pdf-table controles" style="font-size:11px; margin-top:0;">
                        <thead>
                            <?= renderDiagonalHeader(3) ?>
                        </thead>
                        <tbody>
                            <?= renderSectionHeader("Séparateur à grilles magnétiques à nettoyage semi-automatique type SGSA", 3) ?>
                            <?= renderAprfRow("Satisfaction de fonctionnement", "sgsa_satisfaction", $donnees) ?>
                            <?= renderAprfRow("Aspect général", "sgsa_aspect", $donnees) ?>
                            <?= renderAprfRow("Fréquence de nettoyage", "sgsa_freq_nettoyage", $donnees) ?>

                            <?= renderSectionHeader("APPLICATION CLIENT", 3) ?>
                            <tr>
                                <td style="padding:8px; font-weight:bold;">
                                    Type de produit : 
                                    <input type="text" name="mesures[sgsa_produit]" value="<?= htmlspecialchars($mesures['sgsa_produit'] ?? '') ?>" class="pdf-input" style="width:140px; margin-left:5px;">
                                </td>
                                <td style="border:1px solid #000; text-align:center; padding:0; vertical-align:middle; width:140px;">
                                    <?= renderEtatRadios("sgsa_produit_stat", $donnees, 3) ?>
                                </td>
                                <td style="padding:8px; font-size:10px; color:#555;">
                                    <textarea name="donnees[sgsa_produit_comment]" class="pdf-textarea" style="height:35px; border:none; width:100%; box-sizing:border-box; padding:4px;"><?= htmlspecialchars($donnees['sgsa_produit_comment'] ?? '') ?></textarea>
                                </td>
                            </tr>
                            <tr>
                                <td style="padding:8px; font-weight:bold;">
                                    Granulométrie : 
                                    <input type="text" name="mesures[sgsa_granulo_min]" value="<?= htmlspecialchars($mesures['sgsa_granulo_min'] ?? '') ?>" style="width:40px; border:none; border-bottom:1px solid #000; text-align:center;"> à <input type="text" name="mesures[sgsa_granulo_max]" value="<?= htmlspecialchars($mesures['sgsa_granulo_max'] ?? '') ?>" style="width:40px; border:none; border-bottom:1px solid #000; text-align:center;"> mm
                                </td>
                                <td style="border:1px solid #000; text-align:center; padding:0; vertical-align:middle; width:140px;">
                                    <?= renderEtatRadios("sgsa_granulo_stat", $donnees, 3) ?>
                                </td>
                                <td style="padding:8px; font-size:10px; color:#555;">
                                    Aciers de <input type="text" name="mesures[sgsa_acier_min]" value="<?= htmlspecialchars($mesures['sgsa_acier_min'] ?? '') ?>" style="width:30px; border:none; border-bottom:1px dashed #000; text-align:center;"> à <input type="text" name="mesures[sgsa_acier_max]" value="<?= htmlspecialchars($mesures['sgsa_acier_max'] ?? '') ?>" style="width:30px; border:none; border-bottom:1px dashed #000; text-align:center;"> mm
                                </td>
                            </tr>
                            <tr>
                                <td style="padding:8px; font-weight:bold;">
                                    Débit : 
                                    <input type="text" name="mesures[sgsa_debit]" value="<?= htmlspecialchars($mesures['sgsa_debit'] ?? '') ?>" style="width:60px; border:none; border-bottom:1px solid #000; text-align:center; margin-left:5px;"> t/h
                                </td>
                                <td style="border:1px solid #000; text-align:center; padding:0; vertical-align:middle; width:140px;">
                                    <?= renderEtatRadios("sgsa_debit_stat", $donnees, 3) ?>
                                </td>
                                <td style="padding:8px; font-size:10px; color:#555;">
                                    Avec densité de <input type="text" name="mesures[sgsa_densite]" value="<?= htmlspecialchars($mesures['sgsa_densite'] ?? '') ?>" style="width:60px; border:none; border-bottom:1px dashed #000; text-align:center;">
                                </td>
                            </tr>
                            <tr>
                                <td style="padding:8px; font-weight:bold;">
                                    Environnement Atex ? Non – Oui :
                                    <input type="text" name="mesures[sgsa_atex]" value="<?= htmlspecialchars($mesures['sgsa_atex'] ?? '') ?>" style="width:100px; border:none; border-bottom:1px solid #000; text-align:center; margin-left:5px;">
                                </td>
                                <td style="border:1px solid #000; text-align:center; padding:0; vertical-align:middle; width:140px;">
                                    <?= renderEtatRadios("sgsa_atex_stat", $donnees, 3) ?>
                                </td>
                                <td style="padding:0;"><textarea name="donnees[sgsa_atex_comment]" class="pdf-textarea" style="height:35px; border:none; width:100%; box-sizing:border-box; padding:4px;"><?= htmlspecialchars($donnees['sgsa_atex_comment'] ?? '') ?></textarea></td>
                            </tr>

                            <?php for($e=1; $e<=3; $e++): ?>
                                <?= renderSectionHeader("ETAGE ".($e==1 ? '1 (Supérieur)' : $e), 3) ?>
                                <tr>
                                    <td style="padding:8px; vertical-align:top;">
                                        <div style="margin-bottom:8px; font-weight:bold;">
                                            Nombre de barreaux : 
                                            <input type="text" name="mesures[sgsa_e<?= $e ?>_nb]" value="<?= htmlspecialchars($mesures['sgsa_e'.$e.'_nb'] ?? '') ?>" style="width:40px; border:none; border-bottom:1px solid #000; text-align:center; font-weight:bold;" autocomplete="off">
                                        </div>
                                        
                                        <div style="background:#fcfcfc; border:1px solid #eee; padding:5px;">
                                            <div style="font-size:9px; color:#888; margin-bottom:5px; font-weight:bold;">Barreaux (Gauss Max) :</div>
                                            <table style="width:100%; border-collapse:collapse; border:none; font-size:10px;">
                                                <tr>
                                                    <td style="width:50%; border:none; padding-right:5px; vertical-align:top;">
                                                        <?php for($b=1; $b<=6; $b++): ?>
                                                            <div style="display:flex; justify-content:space-between; margin-bottom:3px; border-bottom:1px solid #f0f0f0;">
                                                                <span style="font-weight:600;"><?= $b ?> :</span>
                                                                <span><input type="text" name="mesures[sgsa_e<?= $e ?>_b<?= $b ?>]" value="<?= htmlspecialchars($mesures['sgsa_e'.$e.'_b'.$b] ?? '') ?>" style="width:35px; border:none; text-align:center; font-size:10px;"> <span style="font-size:8px; color:#aaa;">G</span></span>
                                                            </div>
                                                        <?php endfor; ?>
                                                    </td>
                                                    <td style="width:50%; border:none; padding-left:5px; vertical-align:top; border-left:1px solid #eee;">
                                                        <?php for($b=7; $b<=11; $b++): ?>
                                                            <div style="display:flex; justify-content:space-between; margin-bottom:3px; border-bottom:1px solid #f0f0f0;">
                                                                <span style="font-weight:600;"><?= $b ?> :</span>
                                                                <span><input type="text" name="mesures[sgsa_e<?= $e ?>_b<?= $b ?>]" value="<?= htmlspecialchars($mesures['sgsa_e'.$e.'_b'.$b] ?? '') ?>" style="width:35px; border:none; text-align:center; font-size:10px;"> <span style="font-size:8px; color:#aaa;">G</span></span>
                                                            </div>
                                                        <?php endfor; ?>
                                                    </td>
                                                </tr>
                                            </table>
                                        </div>
                                    </td>
                                    <td style="border:1px solid #000; text-align:center; padding:0; vertical-align:middle; width:140px;">
                                        <div style="margin-bottom:8px; font-size:9px; color:#888; font-weight:bold; letter-spacing:0.5px;">ÉTAT GLOBAL ÉTAGE</div>
                                        <?= renderEtatRadios("sgsa_e".$e."_stat", $donnees, 3) ?>
                                    </td>
                                    <td style="padding:0; vertical-align:top; width:30%;">
                                        <textarea name="donnees[sgsa_e<?= $e ?>_comment]" class="pdf-textarea" style="height:100%; min-height:140px; border:none; width:100%; box-sizing:border-box; padding:8px;" placeholder="Observation étage <?= $e ?>..."><?= htmlspecialchars($donnees["sgsa_e".$e."_comment"] ?? '') ?></textarea>
                                    </td>
                                </tr>
                                <?= renderAprfRow("Coulissement des tiroirs", "sgsa_e".$e."_coulissement", $donnees) ?>
                                <?= renderAprfRow("Etanchéité du tiroir à la fermeture", "sgsa_e".$e."_etancheite", $donnees) ?>
                            <?php endfor; ?>
                        </tbody>
                    </table>
                    
                    <div style="text-align:center; margin-top:20px;">
                        <img src="/assets/machines/sgsa_diagram.png" 
                             style="max-width:100%; height:auto; display:block; margin:0 auto;" 
                             alt="Schéma Séparateur à Grilles SGSA"
                             onerror="this.style.display='none'">
                    </div>

                <?php elseif ($isSGA): ?>
    

                    <table class="pdf-table controles" style="font-size:11px; margin-top:0;">
                        <thead>
                            <?= renderDiagonalHeader(3) ?>
                        </thead>
                        <tbody>
                            <?= renderSectionHeader("Séparateur à grille magnétique à nettoyage automatique type SGA", 3) ?>
                            <?= renderAprfRow("Satisfaction de fonctionnement", "sga_satisfaction", $donnees) ?>
                            <?= renderAprfRow("Aspect général", "sga_aspect", $donnees) ?>

                            <?= renderSectionHeader("CONTROLE D'ETANCHEITE", 3) ?>
                            <?= renderAprfRow("Portes, brides, nourrices, vérins, volets", "sga_etancheite", $donnees) ?>

                            <?= renderSectionHeader("FONCTIONS", 3) ?>
                            <?= renderAprfRow("Coulissement de chaque barreau", "sga_coulissement", $donnees) ?>
                            
                            <tr>
                                <td style="padding:10px; font-weight:bold; width:35%;">Détecteurs de position du circuit magnétique</td>
                                <td style="border:1px solid #000; text-align:center; padding:0; vertical-align:middle; width:140px;">
                                    <?= renderEtatRadios("sga_detect_circuit_stat", $donnees, 3) ?>
                                </td>
                                <td style="padding:0; width:30%;"><textarea name="donnees[sga_detect_circuit_comment]" class="pdf-textarea" style="height:35px; border:none; width:100%; box-sizing:border-box; padding:4px;" placeholder="(Anciens modèles)"><?= htmlspecialchars($donnees['sga_detect_circuit_comment'] ?? '') ?></textarea></td>
                            </tr>
                            <tr>
                                <td style="padding:10px; font-weight:bold; width:35%;">Détecteurs de position des vérins</td>
                                <td style="border:1px solid #000; text-align:center; padding:0; vertical-align:middle; width:140px;">
                                    <?= renderEtatRadios("sga_detect_verins_stat", $donnees, 3) ?>
                                </td>
                                <td style="padding:0; width:30%;"><textarea name="donnees[sga_detect_verins_comment]" class="pdf-textarea" style="height:35px; border:none; width:100%; box-sizing:border-box; padding:4px;" placeholder="(Anciens modèles)"><?= htmlspecialchars($donnees['sga_detect_verins_comment'] ?? '') ?></textarea></td>
                            </tr>

                            <?= renderAprfRow("Vérins (Ouverture et fermeture des volets)", "sga_verins", $donnees) ?>
                            <?= renderAprfRow("Présence de limiteurs de débit à chaque barreaux", "sga_limiteurs", $donnees) ?>
                            <?= renderAprfRow("Présence de bagues coulissantes", "sga_bagues", $donnees) ?>

                            <?php for($e=1; $e<=3; $e++): ?>
                                <?= renderSectionHeader("ETAGE ".($e==1 ? '1 (Supérieur)' : $e), 3) ?>
                                <tr>
                                    <td style="padding:8px; vertical-align:top;">
                                        <div style="margin-bottom:8px;">
                                            Nombre de barreaux : 
                                            <input type="text" name="mesures[sga_e<?= $e ?>_nb]" value="<?= htmlspecialchars($mesures['sga_e'.$e.'_nb'] ?? '') ?>" style="width:40px; border:none; border-bottom:1px solid #000; text-align:center; font-weight:bold;" autocomplete="off">
                                        </div>
                                        
                                        <div style="background:#fcfcfc; border:1px solid #eee; padding:5px;">
                                            <div style="font-size:9px; color:#888; margin-bottom:5px; font-weight:bold;">Barreaux (Gauss Max) :</div>
                                            <table style="width:100%; border-collapse:collapse; border:none; font-size:10px;">
                                                <tr>
                                                    <td style="width:50%; border:none; padding-right:5px; vertical-align:top;">
                                                        <?php for($b=1; $b<=6; $b++): ?>
                                                            <div style="display:flex; justify-content:space-between; margin-bottom:3px; border-bottom:1px solid #f0f0f0;">
                                                                <span style="font-weight:600;"><?= $b ?> :</span>
                                                                <span><input type="text" name="mesures[sga_e<?= $e ?>_b<?= $b ?>]" value="<?= htmlspecialchars($mesures['sga_e'.$e.'_b'.$b] ?? '') ?>" style="width:35px; border:none; text-align:center; font-size:10px;"> <span style="font-size:8px; color:#aaa;">G</span></span>
                                                            </div>
                                                        <?php endfor; ?>
                                                    </td>
                                                    <td style="width:50%; border:none; padding-left:5px; vertical-align:top; border-left:1px solid #eee;">
                                                        <?php for($b=7; $b<=11; $b++): ?>
                                                            <div style="display:flex; justify-content:space-between; margin-bottom:3px; border-bottom:1px solid #f0f0f0;">
                                                                <span style="font-weight:600;"><?= $b ?> :</span>
                                                                <span><input type="text" name="mesures[sga_e<?= $e ?>_b<?= $b ?>]" value="<?= htmlspecialchars($mesures['sga_e'.$e.'_b'.$b] ?? '') ?>" style="width:35px; border:none; text-align:center; font-size:10px;"> <span style="font-size:8px; color:#aaa;">G</span></span>
                                                            </div>
                                                        <?php endfor; ?>
                                                        <div style="height:15px;"></div>
                                                    </td>
                                                </tr>
                                            </table>
                                        </div>
                                    </td>
                                    <td style="border:1px solid #000; text-align:center; padding:0; vertical-align:middle; width:140px;">
                                        <div style="margin-bottom:8px; font-size:9px; color:#888; font-weight:bold; letter-spacing:0.5px;">ÉTAT GLOBAL ÉTAGE</div>
                                        <?= renderEtatRadios("sga_e".$e."_stat", $donnees, 3) ?>
                                    </td>
                                    <td style="padding:0; vertical-align:top; width:30%;">
                                        <textarea name="donnees[sga_e<?= $e ?>_comment]" class="pdf-textarea" style="height:100%; min-height:140px; border:none; width:100%; box-sizing:border-box; padding:8px;" placeholder="Observation étage <?= $e ?>..."><?= htmlspecialchars($donnees["sga_e".$e."_comment"] ?? '') ?></textarea>
                                    </td>
                                </tr>
                            <?php endfor; ?>

                            <?= renderSectionHeader("COFFRET D'ALIMENTATION ET COMMANDE", 3) ?>
                            <?= renderAprfRow("Intervalle entre deux cycles de nettoyage", "sga_intervalle", $donnees) ?>
                            <?= renderAprfRow("Etanchéité sous coffret", "sga_etanch_coffret", $donnees) ?>
                            <?= renderAprfRow("Présence d'un filtre à air et d'un régulateur de pression", "sga_filtre_regulateur", $donnees) ?>

                            <?= renderSectionHeader("APPLICATION CLIENT", 3) ?>
                            <tr>
                                <td style="padding:8px; font-weight:bold;">
                                    Type de produit : 
                                    <input type="text" name="mesures[sga_produit]" value="<?= htmlspecialchars($mesures['sga_produit'] ?? '') ?>" class="pdf-input" style="width:140px; margin-left:5px;">
                                </td>
                                <td style="border:1px solid #000; text-align:center; padding:0; vertical-align:middle; width:140px;">
                                    <?= renderEtatRadios("sga_produit_stat", $donnees, 3) ?>
                                </td>
                                <td style="padding:8px; font-size:10px; color:#555;">
                                    Aciers de <input type="text" name="mesures[sga_acier_min]" value="<?= htmlspecialchars($mesures['sga_acier_min'] ?? '') ?>" style="width:30px; border:none; border-bottom:1px dashed #000; text-align:center;"> à <input type="text" name="mesures[sga_acier_max]" value="<?= htmlspecialchars($mesures['sga_acier_max'] ?? '') ?>" style="width:30px; border:none; border-bottom:1px dashed #000; text-align:center;"> mm
                                </td>
                            </tr>
                            <tr>
                                <td style="padding:8px; font-weight:bold;">
                                    Granulométrie : 
                                    <input type="text" name="mesures[sga_granulo]" value="<?= htmlspecialchars($mesures['sga_granulo'] ?? '') ?>" style="width:60px; border:none; border-bottom:1px solid #000; text-align:center; margin-left:5px;"> mm
                                </td>
                                <td style="border:1px solid #000; text-align:center; padding:0; vertical-align:middle; width:140px;">
                                    <?= renderEtatRadios("sga_granulo_stat", $donnees, 3) ?>
                                </td>
                                <td style="padding:0;"><textarea name="donnees[sga_granulo_comment]" class="pdf-textarea" style="height:35px; border:none; width:100%; box-sizing:border-box; padding:4px;"><?= htmlspecialchars($donnees['sga_granulo_comment'] ?? '') ?></textarea></td>
                            </tr>
                            <tr>
                                <td style="padding:8px; font-weight:bold;">
                                    Débit : 
                                    <input type="text" name="mesures[sga_debit]" value="<?= htmlspecialchars($mesures['sga_debit'] ?? '') ?>" style="width:60px; border:none; border-bottom:1px solid #000; text-align:center; margin-left:5px;"> t/h
                                </td>
                                <td style="border:1px solid #000; text-align:center; padding:0; vertical-align:middle; width:140px;">
                                    <?= renderEtatRadios("sga_debit_stat", $donnees, 3) ?>
                                </td>
                                <td style="padding:8px; font-size:10px; color:#555;">
                                    Avec densité de <input type="text" name="mesures[sga_densite]" value="<?= htmlspecialchars($mesures['sga_densite'] ?? '') ?>" style="width:60px; border:none; border-bottom:1px dashed #000; text-align:center;">
                                </td>
                            </tr>
                            <tr>
                                <td style="padding:8px; font-weight:bold;">
                                    Pression de service : 
                                    <input type="text" name="mesures[sga_pression]" value="<?= htmlspecialchars($mesures['sga_pression'] ?? '') ?>" style="width:60px; border:none; border-bottom:1px solid #000; text-align:center; margin-left:5px;"> Bar
                                </td>
                                <td style="border:1px solid #000; text-align:center; padding:0; vertical-align:middle; width:140px;">
                                    <?= renderEtatRadios("sga_pression_stat", $donnees, 3) ?>
                                </td>
                                <td style="padding:0;"><textarea name="donnees[sga_pression_comment]" class="pdf-textarea" style="height:35px; border:none; width:100%; box-sizing:border-box; padding:4px;"><?= htmlspecialchars($donnees['sga_pression_comment'] ?? '') ?></textarea></td>
                            </tr>
                        </tbody>
                    </table>

                    <div style="text-align:center; margin-top:20px;">
                        <img src="/assets/machines/sga_diagram.png" 
                             style="max-width:100%; height:auto; display:block; margin:0 auto;" 
                             alt="Schéma Séparateur à Grilles SGA"
                             onerror="this.style.display='none'">
                    </div>

                <?php elseif ($isSLT): ?>

                    <table class="pdf-table controles" style="font-size:11px; margin-top:0;">
                        <thead>
                            <?= renderDiagonalHeader(3) ?>
                        </thead>
                        <tbody>
                            <?= renderSectionHeader("Séparateur haute intensité type SLT", 3) ?>
                            <?= renderAprfRow("Satisfaction de fonctionnement", "slt_satisfaction", $donnees) ?>
                            <?= renderAprfRow("Aspect général", "slt_aspect", $donnees) ?>

                            <tr>
                                <td style="padding:8px; font-weight:bold;">
                                    Goulotte alimentation (Ouverture + Usure) : 
                                    <input type="text" name="mesures[slt_goulotte_mm]" value="<?= htmlspecialchars($mesures['slt_goulotte_mm'] ?? '') ?>" style="width:50px; border:none; border-bottom:1px solid #000; text-align:center;"> mm
                                </td>
                                <td style="border:1px solid #000; text-align:center; padding:0; vertical-align:middle; width:140px;">
                                    <?= renderEtatRadios("slt_goulotte", $donnees, 3) ?>
                                </td>
                                <td style="padding:0;"><textarea name="donnees[slt_goulotte_comment]" class="pdf-textarea" style="height:35px; border:none; width:100%; box-sizing:border-box; padding:4px;"><?= htmlspecialchars($donnees['slt_goulotte_comment'] ?? '') ?></textarea></td>
                            </tr>

                            <tr>
                                <td style="padding:8px; font-weight:bold;">
                                    Saupoudreur 1 (Silent Bloc) Amplitude : 
                                    <input type="text" name="mesures[slt_saupoudreur1_mm]" value="<?= htmlspecialchars($mesures['slt_saupoudreur1_mm'] ?? '') ?>" style="width:50px; border:none; border-bottom:1px solid #000; text-align:center;"> mm
                                </td>
                                <td style="border:1px solid #000; text-align:center; padding:0; vertical-align:middle; width:140px;">
                                    <?= renderEtatRadios("slt_saupoudreur1", $donnees, 3) ?>
                                </td>
                                <td style="padding:0;"><textarea name="donnees[slt_saupoudreur1_comment]" class="pdf-textarea" style="height:35px; border:none; width:100%; box-sizing:border-box; padding:4px;"><?= htmlspecialchars($donnees['slt_saupoudreur1_comment'] ?? '') ?></textarea></td>
                            </tr>

                            <tr>
                                <td style="padding:8px; font-weight:bold;">
                                    Saupoudreur 2 (Silent Bloc) Amplitude : 
                                    <input type="text" name="mesures[slt_saupoudreur2_mm]" value="<?= htmlspecialchars($mesures['slt_saupoudreur2_mm'] ?? '') ?>" style="width:50px; border:none; border-bottom:1px solid #000; text-align:center;"> mm
                                </td>
                                <td style="border:1px solid #000; text-align:center; padding:0; vertical-align:middle; width:140px;">
                                    <?= renderEtatRadios("slt_saupoudreur2", $donnees, 3) ?>
                                </td>
                                <td style="padding:0;"><textarea name="donnees[slt_saupoudreur2_comment]" class="pdf-textarea" style="height:35px; border:none; width:100%; box-sizing:border-box; padding:4px;"><?= htmlspecialchars($donnees['slt_saupoudreur2_comment'] ?? '') ?></textarea></td>
                            </tr>

                            <?= renderAprfRow("Niveau d'huile du Motoréducteur TAPN", "slt_huile_tapn", $donnees) ?>
                            <?= renderAprfRow("Niveau d'huile du Motoréducteur SLT", "slt_huile_slt", $donnees) ?>
                            <?= renderAprfRow("Courroie Tête SLT (Etat et Tension)", "slt_courroie", $donnees) ?>
                            <?= renderAprfRow("Poulies", "slt_poulies", $donnees) ?>
                            <?= renderAprfRow("Palier TAPN", "slt_palier_tapn", $donnees) ?>
                            <?= renderAprfRow("Position du circuit magnétique du TAPN", "slt_circuit_tapn", $donnees) ?>
                            <?= renderAprfRow("Position du circuit magnétique de la Tête SLT", "slt_circuit_slt", $donnees) ?>
                            <?= renderAprfRow("Virole INOX", "slt_virole_inox", $donnees) ?>
                            <?= renderAprfRow("Virole Fibre", "slt_virole_fibre", $donnees) ?>
                            <?= renderAprfRow("Volet de séparation", "slt_volet", $donnees) ?>

                            <tr>
                                <td style="padding:8px; font-weight:bold;">
                                    Relever induction TAP : 
                                    <input type="text" name="mesures[slt_induction_tap_val]" value="<?= htmlspecialchars($mesures['slt_induction_tap_val'] ?? '') ?>" style="width:70px; border:none; border-bottom:1px solid #000; text-align:center;"> gauss
                                </td>
                                <td style="border:1px solid #000; text-align:center; padding:0; vertical-align:middle; width:140px;">
                                    <?= renderEtatRadios("slt_induction_tap", $donnees, 3) ?>
                                </td>
                                <td style="padding:0;"><textarea name="donnees[slt_induction_tap_comment]" class="pdf-textarea" style="height:35px; border:none; width:100%; box-sizing:border-box; padding:4px;"><?= htmlspecialchars($donnees['slt_induction_tap_comment'] ?? '') ?></textarea></td>
                            </tr>

                            <tr>
                                <td style="padding:8px; font-weight:bold;">
                                    Relever induction SLT : 
                                    <input type="text" name="mesures[slt_induction_slt_val]" value="<?= htmlspecialchars($mesures['slt_induction_slt_val'] ?? '') ?>" style="width:70px; border:none; border-bottom:1px solid #000; text-align:center;"> gauss
                                </td>
                                <td style="border:1px solid #000; text-align:center; padding:0; vertical-align:middle; width:140px;">
                                    <?= renderEtatRadios("slt_induction_slt", $donnees, 3) ?>
                                </td>
                                <td style="padding:0;"><textarea name="donnees[slt_induction_slt_comment]" class="pdf-textarea" style="height:35px; border:none; width:100%; box-sizing:border-box; padding:4px;"><?= htmlspecialchars($donnees['slt_induction_slt_comment'] ?? '') ?></textarea></td>
                            </tr>

                            <tr>
                                <td style="padding:8px; font-weight:bold;">
                                    Relever réglage fréquence variateur : 
                                    <input type="text" name="mesures[slt_variateur_hz]" value="<?= htmlspecialchars($mesures['slt_variateur_hz'] ?? '') ?>" style="width:40px; border:none; border-bottom:1px solid #000; text-align:center;"> Hz, 
                                    <input type="text" name="mesures[slt_variateur_a]" value="<?= htmlspecialchars($mesures['slt_variateur_a'] ?? '') ?>" style="width:40px; border:none; border-bottom:1px solid #000; text-align:center;"> A
                                </td>
                                <td style="border:1px solid #000; text-align:center; padding:0; vertical-align:middle; width:140px;">
                                    <?= renderEtatRadios("slt_variateur", $donnees, 3) ?>
                                </td>
                                <td style="padding:0;"><textarea name="donnees[slt_variateur_comment]" class="pdf-textarea" style="height:35px; border:none; width:100%; box-sizing:border-box; padding:4px;"><?= htmlspecialchars($donnees['slt_variateur_comment'] ?? '') ?></textarea></td>
                            </tr>

                            <tr>
                                <td style="padding:8px; font-weight:bold;">
                                    Relever Ampérage moteur TAPN : 
                                    <input type="text" name="mesures[slt_amperage_tapn_val]" value="<?= htmlspecialchars($mesures['slt_amperage_tapn_val'] ?? '') ?>" style="width:70px; border:none; border-bottom:1px solid #000; text-align:center;">
                                </td>
                                <td style="border:1px solid #000; text-align:center; padding:0; vertical-align:middle; width:140px;">
                                    <?= renderEtatRadios("slt_amperage_tapn", $donnees, 3) ?>
                                </td>
                                <td style="padding:0;"><textarea name="donnees[slt_amperage_tapn_comment]" class="pdf-textarea" style="height:35px; border:none; width:100%; box-sizing:border-box; padding:4px;"><?= htmlspecialchars($donnees['slt_amperage_tapn_comment'] ?? '') ?></textarea></td>
                            </tr>

                            <?= renderAprfRow("Position du commutateur vibrant 1", "slt_commutateur1", $donnees) ?>
                            <?= renderAprfRow("Position du commutateur vibrant 2", "slt_commutateur2", $donnees) ?>
                            <?= renderAprfRow("Relever réglage volet 1. Gauche", "slt_volet1_gauche", $donnees) ?>
                            <?= renderAprfRow("Relever réglage volet 3. Droite", "slt_volet3_droite", $donnees) ?>

                            <?= renderSectionHeader("APPLICATION CLIENT", 3) ?>
                            <tr>
                                <td style="padding:8px; font-weight:bold;">Type de produit :</td>
                                <td style="border:1px solid #000; text-align:center; padding:0; vertical-align:middle; width:140px;">
                                    <?= renderEtatRadios("slt_produit_stat", $donnees, 3) ?>
                                </td>
                                <td style="padding:0;"><textarea name="mesures[slt_produit]" class="pdf-textarea" style="height:35px; border:none; width:100%; box-sizing:border-box; padding:4px; font-weight:bold;"><?= htmlspecialchars($mesures['slt_produit'] ?? '') ?></textarea></td>
                            </tr>
                            <tr>
                                <td style="padding:8px; font-weight:bold;">
                                    Granulométrie : 
                                    <input type="text" name="mesures[slt_granulo]" value="<?= htmlspecialchars($mesures['slt_granulo'] ?? '') ?>" style="width:100px; border:none; border-bottom:1px solid #000; text-align:center;"> mm
                                </td>
                                <td style="border:1px solid #000; text-align:center; padding:0; vertical-align:middle; width:140px;">
                                    <?= renderEtatRadios("slt_granulo_stat", $donnees, 3) ?>
                                </td>
                                <td style="padding:0;"><textarea name="donnees[slt_granulo_comment]" class="pdf-textarea" style="height:35px; border:none; width:100%; box-sizing:border-box; padding:4px;"><?= htmlspecialchars($donnees['slt_granulo_comment'] ?? '') ?></textarea></td>
                            </tr>
                            <tr>
                                <td style="padding:8px; font-weight:bold;">
                                    Débit : 
                                    <input type="text" name="mesures[slt_debit]" value="<?= htmlspecialchars($mesures['slt_debit'] ?? '') ?>" style="width:70px; border:none; border-bottom:1px solid #000; text-align:center;"> t/h
                                </td>
                                <td style="border:1px solid #000; text-align:center; padding:0; vertical-align:middle; width:140px;">
                                    <?= renderEtatRadios("slt_debit_stat", $donnees, 3) ?>
                                </td>
                                <td style="padding:8px; font-size:10px; color:#555;">
                                    Avec densité de <input type="text" name="mesures[slt_densite]" value="<?= htmlspecialchars($mesures['slt_densite'] ?? '') ?>" style="width:60px; border:none; border-bottom:1px dashed #000; text-align:center;">
                                </td>
                            </tr>
                        </tbody>
                    </table>

                    <div style="text-align:center; margin-top:30px;">
                        <div style="margin-bottom:25px;">
                            <img src="/assets/machines/slt_photo.png" style="max-width:90%; height:auto; border:1px solid #ccc; display:block; margin:0 auto;" alt="Photo SLT" onerror="this.style.display='none'">
                        </div>
                        <div>
                            <img src="/assets/machines/slt_diagram.png" style="max-width:100%; height:auto; display:block; margin:0 auto;" alt="Schéma SLT" onerror="this.style.display='none'">
                        </div>
                        
                        <!-- TABLEAU LEGENDE SLT -->
                        <div style="margin: 20px auto; width: 60%;">
                            <table style="width:100%; border-collapse:collapse; font-size:10px; border:1px solid #000; text-align:center;">
                                <tr style="background:#d9d9d9;">
                                    <th style="border:1px solid #000; padding:4px; width:30%;">Rep.</th>
                                    <th style="border:1px solid #000; padding:4px;">DESIGNATION</th>
                                </tr>
                                <tr><td style="border:1px solid #000; padding:2px;">A</td><td style="border:1px solid #000; padding:2px; text-align:left; padding-left:10px;">Tambour TAPN</td></tr>
                                <tr><td style="border:1px solid #000; padding:2px;">B</td><td style="border:1px solid #000; padding:2px; text-align:left; padding-left:10px;">Tambour SLT</td></tr>
                                <tr><td style="border:1px solid #000; padding:2px;">1</td><td style="border:1px solid #000; padding:2px; text-align:left; padding-left:10px;">Motoreducteur tambour TAPN</td></tr>
                                <tr><td style="border:1px solid #000; padding:2px;">2</td><td style="border:1px solid #000; padding:2px; text-align:left; padding-left:10px;">Motoreducteur tambour SLT</td></tr>
                                <tr><td style="border:1px solid #000; padding:2px;">3</td><td style="border:1px solid #000; padding:2px; text-align:left; padding-left:10px;">Poulie motoreducteur</td></tr>
                                <tr><td style="border:1px solid #000; padding:2px;">4</td><td style="border:1px solid #000; padding:2px; text-align:left; padding-left:10px;">Courroie</td></tr>
                                <tr><td style="border:1px solid #000; padding:2px;">5</td><td style="border:1px solid #000; padding:2px; text-align:left; padding-left:10px;">Contrôleur de rotation</td></tr>
                                <tr><td style="border:1px solid #000; padding:2px;">6</td><td style="border:1px solid #000; padding:2px; text-align:left; padding-left:10px;">Palier axe volet</td></tr>
                                <tr><td style="border:1px solid #000; padding:2px;">7</td><td style="border:1px solid #000; padding:2px; text-align:left; padding-left:10px;">Grand volet</td></tr>
                                <tr><td style="border:1px solid #000; padding:2px;">8</td><td style="border:1px solid #000; padding:2px; text-align:left; padding-left:10px;">Petit volet</td></tr>
                                <tr><td style="border:1px solid #000; padding:2px;">9</td><td style="border:1px solid #000; padding:2px; text-align:left; padding-left:10px;">Saupoudreur</td></tr>
                            </table>
                        </div>
                    </div>

                <?php elseif ($isSPM): ?>

                    <table class="pdf-table controles" style="font-size:11px; margin-top:0;">
                        <thead>
                            <?= renderDiagonalHeader(3) ?>
                        </thead>
                        <tbody>
                            <?= renderSectionHeader("Plaques Magnétiques type PM/PML/PMN/PMNL", 3) ?>
                            <?= renderAprfRow("Satisfaction de fonctionnement", "spm_satisfaction", $donnees) ?>
                            <?= renderAprfRow("Aspect général", "spm_aspect", $donnees) ?>
                            <?= renderAprfRow("Etanchéité des plaques", "spm_etanch_plaques", $donnees) ?>
                            <?= renderAprfRow("Etanchéité des brides", "spm_etanch_brides", $donnees) ?>
                            <?= renderAprfRow("Etat des charnières et du verrouillage", "spm_charnieres", $donnees) ?>
                            <?= renderAprfRow("Nettoyage manuel ou avec vérin", "spm_nettoyage", $donnees) ?>

                            <tr>
                                <th colspan="3" style="background:#e0e0e0; font-weight:bold; font-size:11px; border-top: 1px solid #000; border-bottom: 1px solid #000; padding:4px;">Contrôle d’induction :</th>
                            </tr>
                            <?php 
                            $inductions = [
                                'p1' => ['label' => 'P1 =', 'suffix' => 'Gauss (centre)'],
                                'p2' => ['label' => 'P2 =', 'suffix' => 'Gauss (bord largeur)'],
                                'p3' => ['label' => 'P3 =', 'suffix' => 'Gauss (bord longueur)']
                            ];
                            foreach($inductions as $ikey => $idata): ?>
                            <tr>
                                <td style="padding:4px; padding-left:25px; font-size:11px; width:35%;">
                                    <span style="font-weight:bold; margin-right:5px;">➤</span> <?= $idata['label'] ?> 
                                    <input type="text" name="mesures[spm_induction_<?= $ikey ?>]" value="<?= htmlspecialchars($mesures['spm_induction_' . $ikey] ?? '') ?>" style="width:50px; border:none; border-bottom:1px dashed #000; text-align:center; font-weight:bold; font-size:11px;" autocomplete="off"> 
                                    <?= $idata['suffix'] ?>
                                </td>
                                <td style="border:1px solid #000; text-align:center; padding:0; vertical-align:middle; width:140px;">
                                    <?= renderEtatRadios("spm_induction_" . $ikey . "_stat", $donnees, 3) ?>
                                </td>
                                <td style="padding:0; width:35%;"><textarea name="donnees[spm_induction_<?= $ikey ?>_stat_comment]" class="pdf-textarea" style="height:25px; border:none; width:100%; box-sizing:border-box; padding:4px;" placeholder="Commentaires..."><?= htmlspecialchars($donnees["spm_induction_" . $ikey . "_stat_comment"] ?? '') ?></textarea></td>
                            </tr>
                            <?php endforeach; ?>

                            <tr>
                                <th colspan="3" style="background:#e0e0e0; font-weight:bold; font-size:11px; border-top: 1px solid #000; border-bottom: 1px solid #000; padding:4px;">Distance d'attraction :</th>
                            </tr>
                            <?php 
                            $distances = [
                                'ecrou' => 'Ecrou M4 =',
                                'rond50' => 'Rond Ø6 Lg50 =',
                                'rond100' => 'Rond Ø6 Lg100 =',
                                'bille' => 'Bille Ø20 ='
                            ];
                            foreach($distances as $dkey => $dlabel): ?>
                            <tr>
                                <td style="padding:4px; padding-left:25px; font-size:11px;">
                                    <span style="font-weight:bold; margin-right:5px;">➤</span> <?= $dlabel ?> 
                                    <input type="text" name="mesures[spm_dist_<?= $dkey ?>]" value="<?= htmlspecialchars($mesures['spm_dist_' . $dkey] ?? '') ?>" style="width:50px; border:none; border-bottom:1px dashed #000; text-align:center; font-weight:bold; font-size:11px;" autocomplete="off"> mm
                                </td>
                                <td style="border:1px solid #000; text-align:center; padding:0; vertical-align:middle;">
                                    <?= renderEtatRadios("spm_dist_" . $dkey . "_stat", $donnees, 3) ?>
                                </td>
                                <td style="padding:0;"><textarea name="donnees[spm_dist_<?= $dkey ?>_stat_comment]" class="pdf-textarea" style="height:25px; border:none; width:100%; box-sizing:border-box; padding:4px;" placeholder="Commentaires..."><?= htmlspecialchars($donnees["spm_dist_" . $dkey . "_stat_comment"] ?? '') ?></textarea></td>
                            </tr>
                            <?php endforeach; ?>

                            <tr>
                                <td style="padding:8px; font-weight:bold;">Type d'aimants : Ferrite / Néodyme</td>
                                <td style="border:1px solid #000; text-align:center; padding:0; vertical-align:middle;">
                                    <?= renderEtatRadios("spm_aimants", $donnees, 3) ?>
                                </td>
                                <td style="padding:0;"><textarea name="donnees[spm_aimants_comment]" class="pdf-textarea" style="height:35px; border:none; width:100%; box-sizing:border-box; padding:4px;"><?= htmlspecialchars($donnees['spm_aimants_comment'] ?? '') ?></textarea></td>
                            </tr>
                            <tr>
                                <td style="padding:8px; font-weight:bold;">
                                    Température d'utilisation : Ambiante / Haute : 
                                    <input type="text" name="mesures[spm_temp_valeur]" value="<?= htmlspecialchars($mesures['spm_temp_valeur'] ?? '') ?>" style="width:50px; border:none; border-bottom:1px solid #000; text-align:center;"> °C
                                </td>
                                <td style="border:1px solid #000; text-align:center; padding:0; vertical-align:middle;">
                                    <?= renderEtatRadios("spm_temperature", $donnees, 3) ?>
                                </td>
                                <td style="padding:0;"><textarea name="donnees[spm_temperature_comment]" class="pdf-textarea" style="height:35px; border:none; width:100%; box-sizing:border-box; padding:4px;"><?= htmlspecialchars($donnees['spm_temperature_comment'] ?? '') ?></textarea></td>
                            </tr>
                            <?= renderAprfRow("Etat du V de séparation", "spm_v_separation", $donnees) ?>
                            <?= renderAprfRow("Etat des ressauts (option)", "spm_ressauts", $donnees) ?>

                            <?= renderSectionHeader("APPLICATION CLIENT", 3) ?>
                            <tr>
                                <td style="padding:8px; font-weight:bold; font-size:11px;">
                                    Type de produit : 
                                    <input type="text" name="mesures[spm_produit]" value="<?= htmlspecialchars($mesures['spm_produit'] ?? '') ?>" class="pdf-input" style="width:120px; font-weight:bold;">
                                </td>
                                <td style="border:1px solid #000; text-align:center; padding:0; vertical-align:middle; width:140px;">
                                    <?= renderEtatRadios("spm_produit_stat", $donnees, 3) ?>
                                </td>
                                <td style="padding:8px; font-size:10px; color:#555;">
                                    Aciers de <input type="text" name="mesures[spm_acier_min]" value="<?= htmlspecialchars($mesures['spm_acier_min'] ?? '') ?>" style="width:30px; border:none; border-bottom:1px dashed #333; text-align:center;"> à <input type="text" name="mesures[spm_acier_max]" value="<?= htmlspecialchars($mesures['spm_acier_max'] ?? '') ?>" style="width:30px; border:none; border-bottom:1px dashed #333; text-align:center;"> mm
                                </td>
                            </tr>
                            <tr>
                                <td style="padding:8px; font-weight:bold;">
                                    Granulométrie : 
                                    <input type="text" name="mesures[spm_granulo]" value="<?= htmlspecialchars($mesures['spm_granulo'] ?? '') ?>" style="width:70px; border:none; border-bottom:1px solid #000; text-align:center;"> mm
                                </td>
                                <td style="border:1px solid #000; text-align:center; padding:0; vertical-align:middle; width:140px;">
                                    <?= renderEtatRadios("spm_granulo_stat", $donnees, 3) ?>
                                </td>
                                <td style="padding:0;"><textarea name="donnees[spm_granulo_comment]" class="pdf-textarea" style="height:35px; border:none; width:100%; box-sizing:border-box; padding:4px;"><?= htmlspecialchars($donnees['spm_granulo_comment'] ?? '') ?></textarea></td>
                            </tr>
                            <tr>
                                <td style="padding:8px; font-weight:bold;">
                                    Débit : 
                                    <input type="text" name="mesures[spm_debit]" value="<?= htmlspecialchars($mesures['spm_debit'] ?? '') ?>" style="width:70px; border:none; border-bottom:1px solid #000; text-align:center;"> t/h
                                </td>
                                <td style="border:1px solid #000; text-align:center; padding:0; vertical-align:middle; width:140px;">
                                    <?= renderEtatRadios("spm_debit_stat", $donnees, 3) ?>
                                </td>
                                <td style="padding:8px; font-size:10px; color:#555;">
                                    Avec densité de <input type="text" name="mesures[spm_densite]" value="<?= htmlspecialchars($mesures['spm_densite'] ?? '') ?>" style="width:60px; border:none; border-bottom:1px dashed #000; text-align:center;">
                                </td>
                            </tr>
                            <tr>
                                <td style="padding:8px; font-weight:bold;">Environnement Atex ?</td>
                                <td style="border:1px solid #000; text-align:center; padding:0; vertical-align:middle; width:140px;">
                                    <?= renderEtatRadios("spm_atex_stat", $donnees, 3) ?>
                                </td>
                                <td style="padding:0;"><textarea name="mesures[spm_atex]" class="pdf-textarea" style="height:35px; border:none; width:100%; box-sizing:border-box; padding:4px;" placeholder="Non - Oui (Préciser)"><?= htmlspecialchars($mesures['spm_atex'] ?? '') ?></textarea></td>
                            </tr>
                        </tbody>
                    </table>

                    <div style="text-align:center; margin-top:30px;">
                        <h3 style="font-size:18px; color:#000; font-weight:bold; border-bottom:3px solid #ff6600; display:inline-block; padding-bottom:10px; margin-bottom:20px; text-transform:uppercase;">SÉPARATEUR À PLAQUE MAGNÉTIQUE SPM</h3>
                        
                        <div class="machine-orange-box" style="margin-bottom:25px; border:2px solid #ff6600; padding:15px; display:inline-block; background-color:#fff; box-shadow:0 4px 8px rgba(0,0,0,0.1);">
                            <img src="/assets/machines/spm_diagram_composite.png" style="max-width:100%; height:auto; display:block; margin:0 auto;" alt="Schéma Composite SPM">
                        </div>
                    </div>

                <?php elseif ($isSRM): ?>
                    <table class="pdf-table controles" style="font-size:11px; margin-top:0;">
                        <thead>
                            <?= renderDiagonalHeader(3) ?>
                        </thead>
                        <tbody>
                            <?= renderSectionHeader("Cône magnétique", 3) ?>
                            <?= renderAprfRow("Satisfaction de fonctionnement", "srm_satisfaction", $donnees) ?>
                            <?= renderAprfRow("Aspect général", "srm_aspect", $donnees) ?>
                            <?= renderAprfRow("Étanchéité de la porte", "srm_etanch_porte", $donnees) ?>
                            <tr>
                                <td style="padding:4px; font-weight:bold;">Présence d'un détecteur d'ouverture OUI / NON</td>
                                <td style="border:1px solid #000; text-align:center; padding:0; vertical-align:middle; width:70px;">
                                    <div style="display:flex; justify-content:center; gap:10px; font-size:10px;">
                                        <label><input type="radio" name="mesures[srm_detecteur]" value="non" <?= ($mesures['srm_detecteur'] ?? '') === 'non' ? 'checked' : '' ?>> Non</label>
                                        <label><input type="radio" name="mesures[srm_detecteur]" value="oui" <?= ($mesures['srm_detecteur'] ?? '') === 'oui' ? 'checked' : '' ?>> Oui</label>
                                    </div>
                                </td>
                                <td style="padding:0;"><textarea name="donnees[srm_detecteur_comment]" class="pdf-textarea" style="height:30px; border:none; width:100%; box-sizing:border-box; padding:4px;" autocomplete="off"><?= htmlspecialchars($donnees["srm_detecteur_comment"] ?? '') ?></textarea></td>
                            </tr>
                            <?= renderAprfRow("Étanchéité des brides Sup + Inf", "srm_etanch_brides", $donnees) ?>
                            <?= renderAprfRow("Etat des charnières et du verrouillage", "srm_charnieres", $donnees) ?>

                            <?= renderSectionHeader("Contrôle d'induction :", 3) ?>
                            <tr>
                                <td style="padding:4px; font-weight:bold;">➤ P1 = <input type="text" name="mesures[srm_induction_p1]" value="<?= htmlspecialchars($mesures['srm_induction_p1'] ?? '') ?>" class="pdf-input" style="width:100px; text-align:center;" autocomplete="off"> Gauss</td>
                                <td style="border:1px solid #000; text-align:center; padding:0; vertical-align:middle; width:70px;">
                                    <?= renderEtatRadios("srm_induction_p1_stat", $donnees, 3) ?>
                                </td>
                                <td style="padding:0;"><textarea name="donnees[srm_induction_p1_stat_comment]" class="pdf-textarea" style="height:30px; border:none; width:100%; box-sizing:border-box; padding:4px;" autocomplete="off"><?= htmlspecialchars($donnees["srm_induction_p1_stat_comment"] ?? '') ?></textarea></td>
                            </tr>
                            <tr>
                                <td style="padding:4px; font-weight:bold;">➤ P2 = <input type="text" name="mesures[srm_induction_p2]" value="<?= htmlspecialchars($mesures['srm_induction_p2'] ?? '') ?>" class="pdf-input" style="width:100px; text-align:center;" autocomplete="off"> Gauss</td>
                                <td style="border:1px solid #000; text-align:center; padding:0; vertical-align:middle; width:70px;">
                                    <?= renderEtatRadios("srm_induction_p2_stat", $donnees, 3) ?>
                                </td>
                                <td style="padding:0;"><textarea name="donnees[srm_induction_p2_stat_comment]" class="pdf-textarea" style="height:30px; border:none; width:100%; box-sizing:border-box; padding:4px;" autocomplete="off"><?= htmlspecialchars($donnees["srm_induction_p2_stat_comment"] ?? '') ?></textarea></td>
                            </tr>
                            <tr>
                                <td style="padding:4px; font-weight:bold;">➤ P3 = <input type="text" name="mesures[srm_induction_p3]" value="<?= htmlspecialchars($mesures['srm_induction_p3'] ?? '') ?>" class="pdf-input" style="width:100px; text-align:center;" autocomplete="off"> Gauss</td>
                                <td style="border:1px solid #000; text-align:center; padding:0; vertical-align:middle; width:70px;">
                                    <?= renderEtatRadios("srm_induction_p3_stat", $donnees, 3) ?>
                                </td>
                                <td style="padding:0;"><textarea name="donnees[srm_induction_p3_stat_comment]" class="pdf-textarea" style="height:30px; border:none; width:100%; box-sizing:border-box; padding:4px;" autocomplete="off"><?= htmlspecialchars($donnees["srm_induction_p3_stat_comment"] ?? '') ?></textarea></td>
                            </tr>

                            <tr>
                                <td style="padding:4px; font-weight:bold;">Type d'aimants : Ferrite / Néodyme</td>
                                <td style="border:1px solid #000; text-align:center; padding:0; vertical-align:middle; width:70px;">
                                    <div style="display:flex; justify-content:center; gap:10px; font-size:10px;">
                                        <label><input type="radio" name="mesures[srm_aimants]" value="ferrite" <?= ($mesures['srm_aimants'] ?? '') === 'ferrite' ? 'checked' : '' ?>> Ferrite</label>
                                        <label><input type="radio" name="mesures[srm_aimants]" value="neodyme" <?= ($mesures['srm_aimants'] ?? '') === 'neodyme' ? 'checked' : '' ?>> Néodyme</label>
                                    </div>
                                </td>
                                <td style="padding:0;"><textarea name="donnees[srm_aimants_comment]" class="pdf-textarea" style="height:30px; border:none; width:100%; box-sizing:border-box; padding:4px;" autocomplete="off"><?= htmlspecialchars($donnees["srm_aimants_comment"] ?? '') ?></textarea></td>
                            </tr>

                            <tr>
                                <td style="padding:4px; font-weight:bold;">Température d'utilisation : Ambiante / Haute : <input type="text" name="mesures[srm_temp_valeur]" value="<?= htmlspecialchars($mesures['srm_temp_valeur'] ?? '') ?>" style="width:30px; border:none; border-bottom:1px dashed #000; text-align:center;" autocomplete="off"> °C</td>
                                <td style="border:1px solid #000; text-align:center; padding:0; vertical-align:middle; width:70px;">
                                    <div style="display:flex; justify-content:center; gap:10px; font-size:10px;">
                                        <label><input type="radio" name="mesures[srm_temperature]" value="ambiante" <?= ($mesures['srm_temperature'] ?? '') === 'ambiante' ? 'checked' : '' ?>> Amb.</label>
                                        <label><input type="radio" name="mesures[srm_temperature]" value="haute" <?= ($mesures['srm_temperature'] ?? '') === 'haute' ? 'checked' : '' ?>> Haute</label>
                                    </div>
                                </td>
                                <td style="padding:0;"><textarea name="donnees[srm_temperature_comment]" class="pdf-textarea" style="height:30px; border:none; width:100%; box-sizing:border-box; padding:4px;" autocomplete="off"><?= htmlspecialchars($donnees["srm_temperature_comment"] ?? '') ?></textarea></td>
                            </tr>

                            <?= renderAprfRow("Etat du cône de séparation", "srm_cone_separation", $donnees) ?>

                            <?= renderSectionHeader("APPLICATION CLIENT", 3) ?>
                            <tr>
                                <td style="padding:4px; font-weight:bold; width:35%;">
                                    Type de produit : 
                                    <input type="text" name="mesures[srm_produit]" value="<?= htmlspecialchars($mesures['srm_produit'] ?? '') ?>" class="pdf-input" style="width:150px;" autocomplete="off">
                                </td>
                                <td style="border:1px solid #000; text-align:center; padding:0; vertical-align:middle; width:140px;">
                                    <?= renderEtatRadios("srm_produit_stat", $donnees, 3) ?>
                                </td>
                                <td style="padding:4px; font-size:11px; color:#555;">
                                    Aciers de <input type="text" name="mesures[srm_prod_min]" value="<?= htmlspecialchars($mesures['srm_prod_min'] ?? '') ?>" style="width:25px; border:none; border-bottom:1px dashed #000; text-align:center;" autocomplete="off"> à <input type="text" name="mesures[srm_prod_max]" value="<?= htmlspecialchars($mesures['srm_prod_max'] ?? '') ?>" style="width:25px; border:none; border-bottom:1px dashed #000; text-align:center;" autocomplete="off"> mm
                                </td>
                            </tr>
                            <tr>
                                <td style="padding:4px; font-weight:bold;">
                                    Granulométrie : 
                                    <input type="text" name="mesures[srm_granulo]" value="<?= htmlspecialchars($mesures['srm_granulo'] ?? '') ?>" class="pdf-input" style="width:60px; text-align:center;" autocomplete="off"> mm
                                </td>
                                <td style="border:1px solid #000; text-align:center; padding:0; vertical-align:middle; width:70px;">
                                    <?= renderEtatRadios("srm_granulo_stat", $donnees, 3) ?>
                                </td>
                                <td style="padding:0;"><textarea name="donnees[srm_granulo_comment]" class="pdf-textarea" style="height:30px; border:none; width:100%; box-sizing:border-box; padding:4px;" autocomplete="off"><?= htmlspecialchars($donnees["srm_granulo_comment"] ?? '') ?></textarea></td>
                            </tr>
                            <tr>
                                <td style="padding:4px; font-weight:bold;">
                                    Débit : 
                                    <input type="text" name="mesures[srm_debit]" value="<?= htmlspecialchars($mesures['srm_debit'] ?? '') ?>" class="pdf-input" style="width:60px; text-align:center;" autocomplete="off"> t/h
                                </td>
                                <td style="border:1px solid #000; text-align:center; padding:0; vertical-align:middle; width:70px;">
                                    <?= renderEtatRadios("srm_debit_stat", $donnees, 3) ?>
                                </td>
                                <td style="padding:4px; font-size:11px; color:#555;">
                                    Avec densité de <input type="text" name="mesures[srm_densite]" value="<?= htmlspecialchars($mesures['srm_densite'] ?? '') ?>" style="width:50px; border:none; border-bottom:1px dashed #000; text-align:center;" autocomplete="off">
                                </td>
                            </tr>
                            <tr>
                                <td style="padding:4px; font-weight:bold;">
                                    Environnement Atex ?
                                </td>
                                <td style="border:1px solid #000; text-align:center; padding:0; vertical-align:middle; width:70px;">
                                    <div style="display:flex; justify-content:center; gap:10px; font-size:10px;">
                                        <label><input type="radio" name="mesures[srm_atex]" value="non" <?= ($mesures['srm_atex'] ?? '') === 'non' ? 'checked' : '' ?>> Non</label>
                                        <label><input type="radio" name="mesures[srm_atex]" value="oui" <?= ($mesures['srm_atex'] ?? '') === 'oui' ? 'checked' : '' ?>> Oui</label>
                                    </div>
                                </td>
                                <td style="padding:4px;"><input type="text" name="mesures[srm_atex_precision]" value="<?= htmlspecialchars($mesures['srm_atex_precision'] ?? '') ?>" placeholder="Préciser..." style="width:100%; border:none; border-bottom:1px dashed #000; font-size:11px;" autocomplete="off"></td>
                            </tr>

                            <tr>
                                <td style="padding:4px; font-weight:bold;">Commentaire général :</td>
                                <td colspan="2" style="padding:0;">
                                    <textarea name="mesures[srm_commentaire_general]" class="pdf-textarea" style="height:60px; border:none; width:100%; box-sizing:border-box; padding:4px;" autocomplete="off"><?= htmlspecialchars($mesures["srm_commentaire_general"] ?? '') ?></textarea>
                                </td>
                            </tr>

                        </tbody>
                    </table>

                    <div style="text-align:center; margin-top:30px;">
                        <h3 style="font-size:18px; color:#000; font-weight:bold; border-bottom:3px solid #ff6600; display:inline-block; padding-bottom:10px; margin-bottom:20px; text-transform:uppercase;">SÉPARATEUR À CÔNE MAGNÉTIQUE SRM</h3>
                        
                        <div class="machine-orange-box" style="margin-top:20px; border:2px solid #ff6600; padding:15px; display:inline-block; background-color:#fff; box-shadow:0 4px 8px rgba(0,0,0,0.1);">
                            <img src="/assets/machines/srm_diagram.png" style="max-width:100%; height:auto; display:block; margin:0 auto;" alt="Support SRM">
                        </div>
                    </div>

                <?php elseif ($isEDX): ?>


                    <?= newPdfPage() ?>
                    <table class="pdf-table controles" style="font-size:11px; margin-top:0;">
                        <thead>
                            <?= renderDiagonalHeader(5) ?>
                        </thead>
                        <tbody>
                        <?= renderSectionHeader("Environnement / Aspect général", 5) ?>
                         <?= renderEdxRow("Accès au séparateur", "edx_acces", $donnees) ?>
                        <?= renderEdxRow("Etat général du séparateur", "edx_etat_gen", $donnees) ?>

                        <?= renderSectionHeader("Partie A - Convoyeur", 5) ?>
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
                        </tbody>
                    </table>

                    <?= newPdfPage() ?>
                    <table class="pdf-table controles" style="font-size:11px; margin-top:0;">
                        <thead>
                            <?= renderDiagonalHeader(5) ?>
                        </thead>
                        <tbody>
                        <?= renderSectionHeader("Partie B - Caisson de séparation", 5) ?>
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
                        </tbody>
                    </table>

                    <?= newPdfPage() ?>
                    <table class="pdf-table controles" style="font-size:11px; margin-top:0;">
                        <thead>
                            <?= renderDiagonalHeader(5) ?>
                        </thead>
                        <tbody>
                        <?= renderSectionHeader("Partie C - Armoire électrique", 5) ?>
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
                        </tbody>
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


                    <?= newPdfPage() ?>
                    <table class="pdf-table controles" style="font-size:11px; margin-top:0;">
                        <thead>
                            <?= renderDiagonalHeader(5) ?>
                        </thead>
                        <tbody>
                        <?= renderSectionHeader("Environnement / Aspect général", 5) ?>
                        <?= renderOvRow("Accès au séparateur", "ov_acces", $donnees) ?>
                        <?= renderOvRow("Etat général du séparateur", "ov_etat_gen", $donnees) ?>

                        <?= renderSectionHeader("Partie A - Le séparateur", 5) ?>
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
                    <table class="pdf-table controles" style="font-size:11px; margin-top:0;">
                        <thead>
                            <?= renderDiagonalHeader(3) ?>
                        </thead>
                        <tbody>
                        <?= renderSectionHeader("Partie B - Les performances", 3) ?>
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
                                <?= renderEtatRadios($key . "_stat", $donnees, 3) ?>
                            </td>
                            <td style="padding:0;">
                                <textarea name="donnees[<?= $key ?>]" class="pdf-textarea" style="height:30px; border:none; width:100%; box-sizing:border-box; padding:4px;"><?= htmlspecialchars($donnees[$key] ?? "") ?></textarea>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>

                    <table class="pdf-table controles" style="font-size:11px; margin-top:10px;">
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
                        <?= renderFreqRow("Contrôle visuel de la bande", "ov_freq_bande", $donnees) ?>
                        <?= renderFreqRow("Contrôle visuel des fixations", "ov_freq_fix", $donnees) ?>
                        <?= renderFreqRow("Contrôle visuel des tambours", "ov_freq_tamb", $donnees) ?>
                        <?= renderFreqRow("Graissage des paliers", "ov_freq_graiss", $donnees) ?>
                    </table>

                        <img src="/assets/machines/ovap_diagram.png"
                            style="max-width:100%; height:auto; display:block; margin:20px auto;" alt="Schéma Overband">

                    <!-- Photo section removed here, handled globally at bottom -->

                <?php elseif ($isPAP): ?>

                    <?= newPdfPage() ?>
                    <table class="pdf-table controles" style="font-size:11px; margin-top:0;">
                        <thead>
                            <?= renderDiagonalHeader(3) ?>
                        </thead>
                        <tbody>
                        <?= renderSectionHeader("PAP/TAP", 3) ?>
                        <?= renderAprfRow("Satisfaction de fonctionnement", "paptap_satisfaction", $donnees) ?>
                        <?= renderAprfRow("Aspect général", "paptap_aspect", $donnees) ?>

                        <?= renderSectionHeader("PRODUIT", 3) ?>
                        <tr>
                            <td style="padding:4px;">
                                Type de produit : 
                                <input type="text" name="mesures[paptap_produit]" value="<?= htmlspecialchars($mesures['paptap_produit'] ?? '') ?>" class="pdf-input" style="width:100px;">
                            </td>
                            <td style="border:1px solid #000; text-align:center; padding:0; vertical-align:middle; width:70px;">
                                <?= renderAprfEtatRadios("paptap_produit_stat", $donnees) ?>
                            </td>
                            <td style="padding:4px;">
                                Aciers de <input type="text" name="mesures[paptap_acier_min]" value="<?= htmlspecialchars($mesures['paptap_acier_min'] ?? '') ?>" class="pdf-input" style="width:30px; text-align:center;">
                                à <input type="text" name="mesures[paptap_acier_max]" value="<?= htmlspecialchars($mesures['paptap_acier_max'] ?? '') ?>" class="pdf-input" style="width:30px; text-align:center;"> mm
                            </td>
                        </tr>
                        <tr>
                            <td style="padding:4px;">
                                Granulométrie : 
                                <input type="text" name="mesures[paptap_granu_min]" value="<?= htmlspecialchars($mesures['paptap_granu_min'] ?? '') ?>" class="pdf-input" style="width:40px; text-align:center;"> à 
                                <input type="text" name="mesures[paptap_granu_max]" value="<?= htmlspecialchars($mesures['paptap_granu_max'] ?? '') ?>" class="pdf-input" style="width:40px; text-align:center;"> mm
                            </td>
                            <td style="border:1px solid #000; text-align:center; padding:0; vertical-align:middle;">
                                <?= renderAprfEtatRadios("paptap_granu_stat", $donnees) ?>
                            </td>
                            <td style="padding:0;"><textarea name="donnees[paptap_granu_comment]" class="pdf-textarea" style="border:none; width:100%; padding:4px;"><?= htmlspecialchars($donnees['paptap_granu_comment'] ?? '') ?></textarea></td>
                        </tr>
                        <tr>
                            <td style="padding:4px;">
                                Débit : 
                                <input type="text" name="mesures[paptap_debit]" value="<?= htmlspecialchars($mesures['paptap_debit'] ?? '') ?>" class="pdf-input" style="width:60px; text-align:center;"> t/h
                            </td>
                            <td style="border:1px solid #000; text-align:center; padding:0; vertical-align:middle;">
                                <?= renderAprfEtatRadios("paptap_debit_stat", $donnees) ?>
                            </td>
                            <td style="padding:4px;">
                                Avec densité de <input type="text" name="mesures[paptap_densite]" value="<?= htmlspecialchars($mesures['paptap_densite'] ?? '') ?>" class="pdf-input" style="width:50px; text-align:center;">
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
                        <?= renderSectionHeader("MECANIQUE", 3) ?>
                        <?= renderAprfRow("Etat d’usure de la virole inox", "paptap_virole", $donnees) ?>
                        <?= renderAprfRow("Revêtement caoutchouc lisse ou losange", "paptap_revetement", $donnees) ?>
                        <?= renderAprfRow("Nombre et taille des tasseaux", "paptap_tasseaux", $donnees) ?>
                        <?= renderAprfRow("Etat de l’arbre d’entrainement", "paptap_arbre", $donnees) ?>
                        <?= renderAprfRow("Etat des paliers et graissage", "paptap_paliers", $donnees) ?>
                        <?= renderAprfRow("Rotation sans difficulté", "paptap_rotation", $donnees) ?>

                        <!-- Section MAGNETIQUE -->
                        <?= renderSectionHeader("MAGNETIQUE", 3) ?>
                        <tr>
                            <td style="padding:4px;">Position correcte du circuit (pour TAP)</td>
                            <td style="border:1px solid #000; text-align:center; padding:0; vertical-align:middle;">
                                <?= renderAprfEtatRadios("paptap_pos_circuit_stat", $donnees) ?>
                            </td>
                            <td style="padding:4px;">
                                Réglage : <input type="text" name="mesures[paptap_reglage]" value="<?= htmlspecialchars($mesures['paptap_reglage'] ?? '') ?>" class="pdf-input" style="width:80px; text-align:center;"> °
                            </td>
                        </tr>
                        <?= renderAprfRow("Type de circuit : Agitateur / Linéaire / Croisé", "paptap_type_circuit", $donnees) ?>
                        <?= renderAprfRow("Bon maintien du palier fixe", "paptap_palier_fixe", $donnees) ?>
                        <tr>
                            <td style="padding:4px;">
                                Induction sur la virole : <input type="text" name="mesures[paptap_induction]" value="<?= htmlspecialchars($mesures['paptap_induction'] ?? '') ?>" class="pdf-input" style="width:60px; text-align:center;"> Gauss
                            </td>
                            <td style="border:1px solid #000; text-align:center; padding:0; vertical-align:middle;">
                                <?= renderAprfEtatRadios("paptap_induction_stat", $donnees) ?>
                            </td>
                            <td style="padding:0;"><textarea name="donnees[paptap_induction_comment]" class="pdf-textarea" style="border:none; width:100%; padding:4px;"><?= htmlspecialchars($donnees['paptap_induction_comment'] ?? '') ?></textarea></td>
                        </tr>
                        <?= renderAprfRow("Aimants : Ferrite ou Néodyme", "paptap_aimants", $donnees) ?>
                        <?= renderAprfRow("Présence et position correcte d’un volet de séparation", "paptap_volet", $donnees) ?>
                        </tbody>
                    </table>

                    <div style="text-align:center; margin-top:20px;">
                        <img src="/assets/machines/Image_TAP-PAP_Lenoir.png" style="max-width:100%; height:auto;" alt="Schémas PAP/TAP">
                    </div>

                <?php elseif ($isLevage): ?>

                    <?= newPdfPage() ?>
                    <table class="pdf-table controles" style="font-size:11px; margin-top:0;">
                        <thead>
                            <?= renderDiagonalHeader(3) ?>
                        </thead>
                        <tbody>

                        <?= renderSectionHeader("CONTROLES", 3) ?>

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
                        <?= renderSectionHeader("MECANIQUE", 3) ?>
                        <?= renderAprfRow("Planéité des pôles et du noyau", "levage_planeite", $donnees) ?>
                        <?= renderAprfRow("Jeu entre bouclier et pôles", "levage_jeu_bouclier", $donnees) ?>
                        <?= renderAprfRow("Etanchéité de la boite de connexion (Joint/PE)", "levage_etanch_boite", $donnees) ?>
                        <?= renderAprfRow("Maintien du câble par le PE et le collier STAUFF", "levage_maintien_cable", $donnees) ?>
                        <?= renderAprfRow("Etat des vis tenant le couvercle", "levage_etat_vis", $donnees) ?>
                        <?= renderAprfRow("Etat des axes de Levage", "levage_axes", $donnees) ?>
                        <?= renderAprfRow("Etat des chaines", "levage_chaines", $donnees) ?>

                        <!-- Section ELECTRIQUE HORS TENSION -->
                        <?= renderSectionHeader("ELECTRIQUE HORS TENSION", 3) ?>
                        <tr>
                            <td style="padding:4px; font-weight:bold; width:35%;">
                                Isolement sous 1000 Vcc :
                                <input type="text" name="mesures[levage_isolement]"
                                    value="<?= htmlspecialchars($mesures['levage_isolement'] ?? '') ?>" class="pdf-input"
                                    style="width:80px; text-align:center; margin-left:5px;">
                                M.ohms
                            </td>
                            <td
                                style="border:1px solid #000; text-align:center; padding:0; vertical-align:middle; width:140px;">
                                <?= renderEtatRadios('levage_isolement_stat', $donnees, 3) ?>
                            </td>
                            <td style="padding:0; width:35%;"><textarea name="donnees[levage_isolement_comment]" class="pdf-textarea"
                                    style="height:30px; border:none; width:100%; box-sizing:border-box; padding:4px;"><?= htmlspecialchars($donnees["levage_isolement_comment"] ?? '') ?></textarea>
                            </td>
                        </tr>
                        <tr>
                            <td style="padding:4px; font-weight:bold; width:35%;">
                                Résistance à froid :
                                <input type="text" name="mesures[levage_resistance]"
                                    value="<?= htmlspecialchars($mesures['levage_resistance'] ?? '') ?>" class="pdf-input"
                                    style="width:80px; text-align:center; margin-left:5px;">
                                ohms
                            </td>
                            <td
                                style="border:1px solid #000; text-align:center; padding:0; vertical-align:middle; width:140px;">
                                <?= renderEtatRadios('levage_resistance_stat', $donnees, 3) ?>
                            </td>
                            <td style="padding:0; width:35%;"><textarea name="donnees[levage_resistance_comment]" class="pdf-textarea"
                                    style="height:30px; border:none; width:100%; box-sizing:border-box; padding:4px;"><?= htmlspecialchars($donnees["levage_resistance_comment"] ?? '') ?></textarea>
                            </td>
                        </tr>
                        <tr>
                            <td style="padding:4px; font-weight:bold; width:35%;">
                                Température de la carcasse :
                                <input type="text" name="mesures[levage_temp_carcasse]"
                                    value="<?= htmlspecialchars($mesures['levage_temp_carcasse'] ?? '') ?>"
                                    class="pdf-input"
                                    style="width:80px; text-align:center; margin-left:5px;">
                                °C
                            </td>
                            <td
                                style="border:1px solid #000; text-align:center; padding:0; vertical-align:middle; width:140px;">
                                <?= renderEtatRadios('levage_temp_carcasse_stat', $donnees, 3) ?>
                            </td>
                            <td style="padding:0; width:35%;"><textarea name="donnees[levage_temp_carcasse_comment]"
                                    class="pdf-textarea"
                                    style="height:30px; border:none; width:100%; box-sizing:border-box; padding:4px;"><?= htmlspecialchars($donnees["levage_temp_carcasse_comment"] ?? '') ?></textarea>
                            </td>
                        </tr>
                        <tr>
                            <td style="padding:4px; font-weight:bold; width:35%;">
                                Température ambiante :
                                <input type="text" name="mesures[levage_temp_ambiante]"
                                    value="<?= htmlspecialchars($mesures['levage_temp_ambiante'] ?? '') ?>"
                                    class="pdf-input"
                                    style="width:80px; text-align:center; margin-left:5px;">
                                °C
                            </td>
                            <td
                                style="border:1px solid #000; text-align:center; padding:0; vertical-align:middle; width:140px;">
                                <?= renderEtatRadios('levage_temp_ambiante_stat', $donnees, 3) ?>
                            </td>
                            <td style="padding:0; width:35%;"><textarea name="donnees[levage_temp_ambiante_comment]"
                                    class="pdf-textarea"
                                    style="height:30px; border:none; width:100%; box-sizing:border-box; padding:4px;"><?= htmlspecialchars($donnees["levage_temp_ambiante_comment"] ?? '') ?></textarea>
                            </td>
                        </tr>
                        <tr>
                            <td style="padding:4px; font-weight:bold; width:35%;">
                                Electroaimant arrêté depuis
                                <input type="text" name="mesures[levage_arrete_depuis]"
                                    value="<?= htmlspecialchars($mesures['levage_arrete_depuis'] ?? '') ?>"
                                    class="pdf-input"
                                    style="width:60px; text-align:center; margin-left:5px;"> h
                            </td>
                            <td
                                style="border:1px solid #000; text-align:center; padding:0; vertical-align:middle; width:140px;">
                                <?= renderEtatRadios('levage_arrete_depuis_stat', $donnees, 3) ?>
                            </td>
                            <td style="padding:0; width:35%;"><textarea name="donnees[levage_arrete_depuis_comment]"
                                    class="pdf-textarea"
                                    style="height:30px; border:none; width:100%; box-sizing:border-box; padding:4px;"><?= htmlspecialchars($donnees["levage_arrete_depuis_comment"] ?? '') ?></textarea>
                            </td>
                        </tr>
                        <?= renderAprfRow("Serrage correcte des bornes", "levage_serrage_bornes", $donnees) ?>

                        <?= renderSectionHeader("ELECTRIQUE SOUS TENSION", 3) ?>
                        <tr>
                            <td style="padding:4px; font-weight:bold;">
                                Tension :
                                <input type="text" name="mesures[levage_tension]"
                                    value="<?= htmlspecialchars($mesures['levage_tension'] ?? '') ?>" class="pdf-input"
                                    style="width:80px; text-align:center; margin-left:5px;">
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
                                    style="width:80px; text-align:center; margin-left:5px;"> A
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
                                    style="width:80px; text-align:center; margin-left:5px;">
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
                                    style="width:80px; text-align:center; margin-left:5px;">
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

                        <?= renderSectionHeader("APPLICATION DU CLIENT", 3) ?>
                        <tr>
                            <td style="padding:4px; font-weight:bold;">
                                Type de produit manipulé : Brame / Tôle / Paquets / Coils / Profilés
                            </td>
                            <td style="border:1px solid #000; text-align:center;"></td>
                            <td style="padding:4px; font-weight:bold; text-align:left;">
                                Dimensions :
                                <input type="text" name="mesures[levage_dimensions]"
                                    value="<?= htmlspecialchars($mesures['levage_dimensions'] ?? '') ?>" class="pdf-input"
                                    style="width:120px; margin-left:5px;">
                            </td>
                        </tr>
                        <tr>
                            <td style="padding:4px; font-weight:bold;">
                                Charge Maxi par aimant :
                                <input type="text" name="mesures[levage_charge_maxi]"
                                    value="<?= htmlspecialchars($mesures['levage_charge_maxi'] ?? '') ?>" class="pdf-input"
                                    style="width:80px; text-align:center; margin-left:5px;">
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
                                    style="width:80px; text-align:center; margin-left:5px;">
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
                                    style="width:80px; text-align:center; margin-left:5px;"> %
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
                                    style="width:80px; text-align:center; margin-left:5px;">
                                h/jour
                            </td>
                            <td style="border:1px solid #000; text-align:center;"></td>
                            <td style="padding:0;"><textarea name="donnees[levage_facteur_service_comment]"
                                    style="height:30px; border:none; width:100%; box-sizing:border-box; padding:4px;"><?= htmlspecialchars($donnees["levage_facteur_service_comment"] ?? '') ?></textarea>
                            </td>
                        </tr>
                    </table>

                        <div class="levage-diagram-container" style="position:relative; width:100%; max-width:650px; min-height:450px; margin:20px auto 10px auto; page-break-inside:avoid;">
                            <!-- Diagram includes Rep section and boxes -->
                            <img src="/assets/machines/levage_diagram.png" 
                                 style="width:100%; height:auto;" 
                                 alt="Circulaire">
                            
                            <!-- Diamètre pôle (83.6% / 23.0%) - Adjusted without transform -->
                            <div style="position:absolute; left:79%; top:22%; font-size:9px;">
                                <input type="text" name="mesures[levage_diam_pole]" value="<?= htmlspecialchars($mesures['levage_diam_pole'] ?? '') ?>" class="pdf-input" style="width:55px; border:none; background:transparent; text-align:center; font-size:9px;" autocomplete="off">
                            </div>

                            <!-- Diamètre noyau (85.5% / 27.5%) -->
                            <div style="position:absolute; left:81%; top:26.5%; font-size:9px;">
                                <input type="text" name="mesures[levage_diam_noyau]" value="<?= htmlspecialchars($mesures['levage_diam_noyau'] ?? '') ?>" class="pdf-input" style="width:55px; border:none; background:transparent; text-align:center; font-size:9px;" autocomplete="off">
                            </div>

                            <!-- Epaisseur pôle (85.6% / 39.5%) -->
                            <div style="position:absolute; left:81%; top:38.5%; font-size:9px;">
                                <input type="text" name="mesures[levage_ep_pole]" value="<?= htmlspecialchars($mesures['levage_ep_pole'] ?? '') ?>" class="pdf-input" style="width:60px; border:none; background:transparent; text-align:center; font-size:9px;" autocomplete="off">
                            </div>

                            <!-- Ø ext/int (5.3% / 45.4%) -->
                            <div style="position:absolute; left:5.3%; top:44%; font-size:9px; font-weight:bold; color:#000; line-height:1.2;">
                                Ø ext 2 : <input type="text" name="mesures[levage_ext2]" value="<?= htmlspecialchars($mesures['levage_ext2'] ?? '') ?>" class="pdf-input" style="width:40px; background:transparent; font-size:9px;" autocomplete="off"><br>
                                Ø ext 1 : <input type="text" name="mesures[levage_ext1]" value="<?= htmlspecialchars($mesures['levage_ext1'] ?? '') ?>" class="pdf-input" style="width:40px; background:transparent; font-size:9px;" autocomplete="off"><br>
                                Ø int 2 : <input type="text" name="mesures[levage_int2]" value="<?= htmlspecialchars($mesures['levage_int2'] ?? '') ?>" class="pdf-input" style="width:40px; background:transparent; font-size:9px;" autocomplete="off"><br>
                                Ø int 1 : <input type="text" name="mesures[levage_int1]" value="<?= htmlspecialchars($mesures['levage_int1'] ?? '') ?>" class="pdf-input" style="width:40px; background:transparent; font-size:9px;" autocomplete="off">
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
                            <thead>
                                <?= renderDiagonalHeader(5) ?>
                            </thead>
                            <tbody>
                            <?= renderSectionHeader("AUTRES CONTROLES", 5) ?>
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
                            </tbody>
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
                        echo '<div class="section-wrapper-pdf">' . renderSectionB($photosData) . '</div>';

                        // SECTION C & D (uniquement PDF)
                        if (isset($_GET['pdf'])):
                            renderSectionC($isEDX, $isOV);
                            renderSectionD($isEDX, $mesures);
                        endif;
                        ?>

                        <div class="section-wrapper-pdf" style="padding:0; background: transparent; page-break-inside: avoid; break-inside: avoid;">
                            <div class="pdf-section-title">E) CAUSE DE DYSFONCTIONNEMENT :</div>
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
                                        $dysText = "";
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
                                                $criticalPhotos[] = $p['data'];
                                            }
                                        }
                                    }
                                }
                                if (!empty($criticalPhotos)): ?>
                                    <div style="display:flex; flex-wrap:wrap; gap:10px; margin-top:10px;">
                                        <?php foreach (array_unique($criticalPhotos) as $photo): ?>
                                            <img src="<?= htmlspecialchars($photo) ?>" style="width:140px; height:100px; object-fit:cover; border:1px solid #ccc; border-radius:4px;">
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>

                        <div class="section-wrapper-pdf" style="padding:0; background: transparent; page-break-inside: avoid; break-inside: avoid;">
                            <div class="pdf-section-title">F) CONCLUSION :</div>
                            <?php if (!isset($_GET['pdf'])): ?>
                                <p style="font-size:11px; color:#666; margin-bottom:5px;">Cette conclusion peut être générée par l'IA en fonction des résultats du contrôle.</p>
                                <textarea name="conclusion" id="conclusionText" class="pdf-textarea" style="min-height:80px; font-size:13px; border: 1px solid #ccc; background:#fff; padding:5px;"><?= htmlspecialchars($machine['conclusion'] ?? '') ?></textarea>
                                <button type="button" onclick="generateConclusion(this)" style="margin-top:5px; background:#2980b9; color:white; border:none; padding:5px 10px; border-radius:4px; cursor:pointer; font-size:11px;"><img src="/assets/ai_expert.jpg" style="height:14px; width:14px; vertical-align:middle; border-radius:3px; margin-right:4px;"> Générer par l'Expert IA</button>
                            <?php else: ?>
                                <?php 
                                    $concText = trim($machine['conclusion'] ?? '');
                                    if (empty($concText)) {
                                        $concText = "";
                                    }
                                ?>
                                <div style="font-size:13px; white-space: pre-wrap; margin-bottom:10px;"><?= htmlspecialchars($concText) ?></div>
                            <?php endif; ?>
                        </div>

                        <?php if (isset($_GET['pdf'])): ?>
                            <div class="section-wrapper-pdf" style="margin-top:15px; border-top: 1px solid #ddd; padding-top:10px;">
                                <table style="width:100%; border-collapse:collapse;">
                                    <tr>
                                        <td colspan="2" style="font-weight:bold; font-size:14px; color:#d35400; padding-bottom:15px;">RAPPEL : Le nettoyage de votre <?= $isEDX ? 'EDX' : ($isOV ? 'OV' : 'équipement') ?> doit être régulier et complet (intérieur et extérieur)</td>
                                    </tr>
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

                    <div class="section-wrapper-pdf photos-annexes-wrapper" style="margin-top:20px;">
                        <div class="pdf-section-title">PHOTOS ANNEXES</div>
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
                                            <?php $imgS = (isset($_GET['pdf']) && strlen($p['data']) > 50000) ? "get_machine_photo.php?machine_id=$id&key=$key&photo_id=".$p['id'] : $p['data']; ?><img src="<?= htmlspecialchars($imgS) ?>" onclick="openLightbox(this.src, '<?= addslashes(htmlspecialchars($p['caption'] ?? '')) ?>')">
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
        // ========== ZOOM / LIGHTBOX ==========
        function openLightbox(src, caption) {
            const modal = document.getElementById('lightbox-modal');
            const img = document.getElementById('lightbox-img');
            const cap = document.getElementById('lightbox-caption');
            if(!modal || !img) return;
            img.src = src;
            cap.innerHTML = caption || '';
            modal.style.display = "block";
        }

        async function loadPhotosAsync() {
            try {
                // Afficher un petit indicateur de chargement
                const grid = document.getElementById('photosAnnexesGrid');
                if (grid) grid.innerHTML = '<p style="color:#28a745; font-size:11px; margin:0;">⌛ Chargement des photos...</p>';
                
                const response = await fetch('get_machine_photos.php?id=<?= $id ?>');
                if (!response.ok) throw new Error('Erreur lors du chargement des photos');
                
                const data = await response.json();
                allPhotos = data;
                
                // On met à jour l'affichage
                Object.keys(allPhotos).forEach(function (key) {
                    renderThumbsForKey(key);
                });
                renderDescriptionMontage();
                renderAnnexes();
                syncPhotos();
                console.log("Photos chargées via AJAX.");
            } catch (err) {
                console.error("Erreur AJAX photos:", err);
                const grid = document.getElementById('photosAnnexesGrid');
                if (grid) grid.innerHTML = '<p style="color:#dc3545; font-size:11px; margin:0;">❌ Erreur de chargement des photos.</p>';
            }
        }

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
            if (f) f.addEventListener('submit', function (e) { 
                syncPhotos(); 
                
                // --- Vercel Payload Limit Safety Check ---
                const serialized = document.getElementById('photosJsonInput').value;
                const sizeMB = (serialized.length / (1024 * 1024)).toFixed(2);
                if (serialized.length > 4.5 * 1024 * 1024) {
                    alert("⚠️ Attention : La fiche contient trop de photos (" + sizeMB + " Mo). Vercel limite l'enregistrement à 4.5 Mo.\nVeuillez supprimer une ou deux photos avant d'enregistrer.");
                    e.preventDefault();
                    return false;
                }
            });
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
                    quality: 0.5,
                    maxWidth: 750,
                    mimeType: 'image/jpeg', 
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
                wrap.innerHTML = '<img src="' + p.data + '" title="' + (p.caption || '') + '" onclick="openLightbox(this.src, \'' + (p.caption || '').replace(/'/g, "\\'") + '\')">' +
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
                        <img src="${p.data}" alt="Photo Matériel ${i+1}" onclick="openLightbox(this.src, 'Photo Matériel ${i+1}')">
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
                    
                    item.innerHTML = '<img src="' + p.data + '" onclick="openLightbox(this.src, \'' + (p.caption || '').replace(/'/g, "\\'") + '\')">' +
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
        // ========== SECTION E & F GENERATION IA =============================================
        async function typeWriterEffect(element, text, speed = 8) {
            element.value = '';
            for (let i = 0; i < text.length; i++) {
                element.value += text.charAt(i);
                // Optimisation: autoGrow moins souvent pour ne pas saturer le main thread
                if (i % 10 === 0) autoGrow(element);
                await new Promise(r => setTimeout(r, speed));
            }
            autoGrow(element);
            // Déclenche l'événement input pour forcer la sauvegarde (autosave.js)
            element.dispatchEvent(new Event('input', { bubbles: true }));
        }

        async function generateConclusion(btn) {
            btn = btn || (window.event ? window.event.currentTarget : null);
            const originalText = btn ? btn.innerHTML : '';
            if (btn) { btn.innerHTML = '⏳ Analyse expert...'; btn.disabled = true; }

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
                    await typeWriterEffect(textNode, data.content, 12);
                } else {
                    alert('Erreur IA : ' + (data.error || 'Indisponible'));
                }
            } catch (e) {
                alert('Erreur de connexion à l\'API IA.');
            } finally {
                if (btn) { btn.innerHTML = originalText; btn.disabled = false; }
            }
        }

        async function generateDysfunctions(btn) {
            btn = btn || (window.event ? window.event.currentTarget : null);
            const textarea = document.getElementById('dysfonctionnementsText');
            
            if (textarea.value && textarea.value !== "Aucun dysfonctionnement majeur signalé." && !confirm("Voulez-vous écraser le contenu actuel par l'analyse IA ?")) return;
            
            const originalText = btn ? btn.innerHTML : '';
            if (btn) { btn.innerHTML = '⏳ Analyse expert...'; btn.disabled = true; }

            try {
                const form = document.getElementById('machineForm');
                const formData = new FormData(form);
                const res = await fetch(`generate_ia.php?type=E&id=<?= $id ?>`, {
                    method: 'POST',
                    body: formData
                });
                const data = await res.json();
                if (data.content) {
                    await typeWriterEffect(textarea, data.content, 8);
                } else {
                    alert('Erreur IA : ' + (data.error || 'Indisponible'));
                }
            } catch (e) {
                alert('Erreur de connexion à l\'API IA.');
            } finally {
                if (btn) { btn.innerHTML = originalText; btn.disabled = false; }
            }
        }

        async function refreshIA(type) {
            const btn = event.currentTarget;
            if (type === 'E') {
                await generateDysfunctions(btn);
            } else if (type === 'F') {
                await generateConclusion(btn);
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
            if (radio) {
                radio.checked = true;
                radio.dispatchEvent(new Event('change', { bubbles: true }));
                radio.dispatchEvent(new Event('input', { bubbles: true }));
            }
        });
        
        // Listener global pour mettre à jour les classes visuelles (utilisé par autosave.js et les clics manuels)
        document.addEventListener('change', function(e) {
            if (e.target.matches('.pastille-group input[type="radio"]')) {
                const radio = e.target;
                const label = radio.closest('label');
                const group = radio.closest('.pastille-group');
                if (label && group) {
                    group.querySelectorAll('label').forEach(l => l.classList.remove('selected'));
                    if (radio.checked) label.classList.add('selected');
                }
            }
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
            // Photos (Async Fix for Vercel Payload Limit)
            <?php if (!isset($_GET['pdf'])): ?>
                loadPhotosAsync();
            <?php else: ?>
                // Mode PDF : on utilise les données PHP déjà injectées
                Object.keys(allPhotos).forEach(function (key) {
                    renderThumbsForKey(key);
                });
                renderDescriptionMontage();
                renderAnnexes();
                syncPhotos();
            <?php endif; ?>

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
    <div id="autosave-indicator" style="position:fixed; bottom:20px; left:20px; background:rgba(0,0,0,0.8); border-left: 3px solid var(--accent-cyan); color:white; padding:8px 12px; border-radius:4px; opacity:0; transition:opacity 0.3s; z-index:9999; font-size:0.75rem; box-shadow: 0 4px 10px rgba(0,0,0,0.5);"></div>
    <script src="/assets/autosave.js"></script>

    <!-- LIGHTBOX MODAL -->
    <div id="lightbox-modal" onclick="this.style.display='none'">
        <span class="lightbox-close">&times;</span>
        <img id="lightbox-img">
        <div id="lightbox-caption"></div>
    </div>
</body>

</html>