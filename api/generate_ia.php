<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/ia_helper.php';
requireAuth(['technicien', 'admin']);

$db = getDB();
$machineId = $_GET['id'] ?? null;
$type = $_GET['type'] ?? 'E'; // E = Dysfonctionnements, F = Conclusion

if (!$machineId) {
    echo json_encode(['error' => 'Machine ID manquant']);
    exit;
}

$stmt = $db->prepare('SELECT * FROM machines WHERE id = ?');
$stmt->execute([$machineId]);
$machine = $stmt->fetch();

if (!$machine) {
    echo json_encode(['error' => 'Machine introuvable']);
    exit;
}

// Données : Priorité au POST (formulaire en cours) sinon lecture DB
$donnees = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['donnees'])) {
    $donnees = $_POST['donnees'];
} else {
    $donnees = json_decode($machine['donnees_controle'] ?? '{}', true);
}
$issues = extractIssuesFromDonnees($donnees);

$typeMachine = $machine['designation'];
$poste = json_decode($machine['mesures'] ?? '{}', true)['poste'] ?? 'N/A';

if ($type === 'E') {
    // Génération Section E
    $formattedAA = array_map(fn($i) => "• " . $i['designation'] . ($i['commentaire'] ? " (" . $i['commentaire'] . ")" : ""), $issues['aa']);
    $formattedNC = array_map(fn($i) => "• " . $i['designation'] . ($i['commentaire'] ? " (" . $i['commentaire'] . ")" : ""), $issues['nc']);
    $formattedNR = array_map(fn($i) => "• " . $i['designation'] . ($i['commentaire'] ? " (" . $i['commentaire'] . ")" : ""), $issues['nr']);

    $systemPrompt = "Tu es un expert technique senior chez LENOIR-MEC, spécialiste mondial de la séparation magnétique. Ta mission est de rédiger la section 'E) CAUSE DE DYSFONCTIONNEMENT' d'un rapport d'expertise.
Instructions :
- Sois très précis, technique et professionnel.
- Utilise le vocabulaire métier (ex: virole, tambour, induction, moteur, bande).
- Structure la réponse sous forme de liste à puces claire.
- Si des pièces sont à remplacer (NR/HS), insiste sur l'urgence technique de manière factuelle.
- Langue : Français de France.
- NE PAS inventer de faits non listés.";

    $userPrompt = "CONTEXTE LENOIR-MEC\n" .
                  "Équipement : $typeMachine\n" .
                  "Localisation/Poste : $poste\n\n" .
                  "CONSTATS DE TERRAIN :\n" .
                  "- Points non conformes (NC) / Hors Service (HS) :\n" . (empty($formattedNC) ? "Aucun" : implode("\n", $formattedNC)) . "\n" .
                  "- Remplacements requis (NR/R) :\n" . (empty($formattedNR) ? "Aucun" : implode("\n", $formattedNR)) . "\n" .
                  "- Points à surveiller (AA) :\n" . (empty($formattedAA) ? "Aucun" : implode("\n", $formattedAA)) . "\n\n" .
                  "Rédige maintenant la section E) de manière structurée.";

    $result = callGroqIA($systemPrompt, $userPrompt);
    
    // Détection d'une erreur (si le résultat commence par "Erreur IA")
    if ($result && strpos($result, 'Erreur IA') === 0) {
        echo json_encode(['error' => $result]);
        exit;
    }

    // Fallback si IA échoue complètement ou pas de clé
    if (!$result) {
        $all = array_merge($formattedNR, $formattedNC, $formattedAA);
        $result = !empty($all) ? implode("\n", $all) : "Aucun dysfonctionnement majeur signalé.";
    }

    echo json_encode(['content' => $result]);
} else {
    // Génération Section F (Conclusion)
    $allIssuesString = "";
    foreach(['nr','nc','aa'] as $cat) {
        foreach($issues[$cat] as $i) $allIssuesString .= "• " . $i['designation'] . "\n";
    }

    $systemPrompt = "Tu es l'Expert LENOIR-MEC. Rédige la section 'F) CONCLUSION' finale du rapport.
Instructions :
- Rédige 2 à 3 phrases maximum.
- Sois direct, rassurant ou alertant selon la gravité des défauts.
- Parle de l'impact sur la production ou la sécurité (ex: perte d'efficacité de tri, risque mécanique).
- Termine par une recommandation claire.
- Style : Expert industriel senior.";

    $userPrompt = "BILAN D'EXPERTISE :\n" .
                  "Machine : $typeMachine\n" .
                  "Détails des anomalies relevées :\n" . ($allIssuesString ?: "Aucun défaut, équipement en parfait état de fonctionnement.") . "\n\n" .
                  "Rédige la conclusion finale professionnelle.";

    $result = callGroqIA($systemPrompt, $userPrompt);
    
    if ($result && strpos($result, 'Erreur IA') === 0) {
        echo json_encode(['error' => $result]);
        exit;
    }

    if (!$result) {
        $countNR = count($issues['nr']);
        $countNC = count($issues['nc']);
        $countAA = count($issues['aa']);
        
        if ($countNR + $countNC + $countAA === 0) {
            $result = "Votre équipement est conforme à nos standards officiels. L'équipement est pleinement opérationnel.";
        } else {
            $reco = "une révision technique";
            if ($countNR > 0) $reco = "le remplacement immédiat des pièces critiques (voir Section E)";
            else if ($countNC > 0) $reco = "une remise en conformité rapide";
            
            $result = "L'expertise a révélé des anomalies" . ($countNR > 0 ? " majeures" : "") . ". Nous préconisons $reco pour garantir la sécurité et l'efficacité de votre séparation magnétique.";
        }
    }

    echo json_encode(['content' => $result]);
}
