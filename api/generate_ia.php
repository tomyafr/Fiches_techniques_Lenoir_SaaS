<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/ia_helper.php';
requireAuth(['technicien', 'admin']);

$db = getDB();
// Support for both 'id' and 'machine_id'
$machineId = $_GET['id'] ?? $_GET['machineId'] ?? $_GET['machine_id'] ?? null;
$type = $_GET['type'] ?? 'ALL'; // E = Dysfonctionnements, F = Conclusion, ALL = Both

if (!$machineId) {
    echo json_encode(['success' => false, 'error' => 'Machine ID manquant']);
    exit;
}

$stmt = $db->prepare('SELECT * FROM machines WHERE id = ?');
$stmt->execute([$machineId]);
$machine = $stmt->fetch();

if (!$machine) {
    echo json_encode(['success' => false, 'error' => 'Machine introuvable']);
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

$response = ['success' => true];

if ($type === 'E' || $type === 'ALL') {
    // Génération Section E (Dysfonctionnements)
    $allIssues = [];
    foreach (['nr', 'nc', 'aa'] as $cat) {
        foreach ($issues[$cat] as $i) {
            $allIssues[] = "• " . $i['designation'] . ($i['commentaire'] ? " (" . $i['commentaire'] . ")" : "");
        }
    }

    $systemPromptE = "Tu es l'Expert Senior LENOIR-MEC. Rédige l'analyse technique globale.
RÈGLES CRITIQUES :
- NE REPRENDS PAS LE TITRE 'E) CAUSE DE DYSFONCTIONNEMENT' ou 'E)'.
- LISTE TOUTES LES ANOMALIES (Points Orange/À améliorer OU Points Rouges/Non conformes).
- RESPECTE L'ORDRE de la liste fournie.
- Sois très concis (maximum 3-5 mots par point).
- Si et seulement si TOUTE la liste fournie est 'Néant', réponds UNIQUEMENT: 'Aucune anomalie détectée lors de l'inspection.'
- NE LISTE PAS les points qui sont en bon état.";
    $userPromptE = "LISTE DES DÉFAUTS À TRAITER :\n" . (empty($allIssues) ? "Néant" : implode("\n", $allIssues));

    $resultE = callGroqIA($systemPromptE, $userPromptE);
    
    // Check if it's an actual IA error string
    if ($resultE && strpos($resultE, 'Erreur IA') === 0) {
        echo json_encode(['success' => false, 'error' => $resultE]);
        exit;
    }

    // Fallback if IA fails (null or empty)
    if (!$resultE) {
        $resultE = !empty($allIssues) ? implode("\n", $allIssues) : "Aucun dysfonctionnement majeur signalé.";
    }
    
    if ($type === 'E') {
        $response['content'] = $resultE;
    } else {
        $response['dysfonctionnements'] = $resultE;
    }
}

if ($type === 'F' || $type === 'ALL') {
    // Génération Section F (Conclusion)
    $allIssuesString = "";
    foreach(['nr','nc','aa'] as $cat) {
        foreach($issues[$cat] as $i) $allIssuesString .= "• " . $i['designation'] . "\n";
    }

    $systemPromptF = "Tu es l'Expert LENOIR-MEC. Rédige l'analyse de conclusion.
Instructions :
- NE REPRENDS PAS LE TITRE 'F) CONCLUSION' ou 'F)'.
- Synthétise le bilan technique en 2 phrases très courtes.
- Mentionne le niveau de priorité global (Urgent, Moyen, Faible).
- Style : Industriel, factuel, sans fioritures.";
    $userPromptF = "BILAN DÉTAILLÉ DES ANOMALIES :\n" . ($allIssuesString ?: "Aucun défaut majeur.") . "\n\n" .
                  "Rédige la conclusion finale.";

    $resultF = callGroqIA($systemPromptF, $userPromptF);
    
    // Check if it's an actual IA error string
    if ($resultF && strpos($resultF, 'Erreur IA') === 0) {
        echo json_encode(['success' => false, 'error' => $resultF]);
        exit;
    }

    // Fallback if IA fails (null or empty)
    if (!$resultF) {
        $countNR = count($issues['nr']);
        $countNC = count($issues['nc']);
        $countAA = count($issues['aa']);
        
        if ($countNR + $countNC + $countAA === 0) {
            $resultF = "Votre équipement est conforme à nos standards officiels. L'équipement est pleinement opérationnel.";
        } else {
            $reco = "une révision technique";
            if ($countNR > 0) $reco = "le remplacement immédiat des pièces critiques (voir Section E)";
            else if ($countNC > 0) $reco = "une remise en conformité rapide";
            
            $resultF = "L'expertise a révélé des anomalies" . ($countNR > 0 ? " majeures" : "") . ". Nous préconisons $reco pour garantir la sécurité et l'efficacité de votre séparation magnétique.";
        }
    }

    if ($type === 'F') {
        $response['content'] = $resultF;
    } else {
        $response['conclusion'] = $resultF;
    }
}

echo json_encode($response);
