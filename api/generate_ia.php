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
    // On récupère toutes les anomalies dans l'ordre où elles ont été extraites (ordre de labelsMap)
    $allIssues = [];
    foreach (['nr', 'nc', 'aa'] as $cat) {
        foreach ($issues[$cat] as $i) {
            $allIssues[] = "• " . $i['designation'] . ($i['commentaire'] ? " (" . $i['commentaire'] . ")" : "");
        }
    }
    
    // Note: extractIssuesFromDonnees remplit maintenant les catégories dans l'ordre de labelsMap, 
    // mais le groupage final par catégorie (NR puis NC puis AA) pourrait encore un peu bouger l'ordre global.
    // Cependant, comme extractIssuesFromDonnees itère sur labelsMap, les points d'une même catégorie sont dans l'ordre.
    // Pour un ordre 100% chronologique TOUTES catégories confondues, il faudrait revoir extractIssuesFromDonnees.
    
    // Attends, si je veux l'ordre de la fiche ABSOLU, je dois modifier extractIssuesFromDonnees pour qu'elle renvoie une liste plate.
    // Mais le prompt IA aime bien avoir les catégories. 
    // Restons sur ce compromis qui respecte l'ordre à l'intérieur de chaque catégorie de gravité.

    $systemPrompt = "Tu es l'Expert Senior LENOIR-MEC. Rédige l'analyse technique.
RÈGLES CRITIQUES :
- NE REPRENDS PAS LE TITRE 'E) CAUSE DE DYSFONCTIONNEMENT' ou 'E)'.
- NE LISTE QUE LES ANOMALIES RÉELLES (Points Orange ou Rouge).
- RESPECTE L'ORDRE de la liste fournie ci-dessous (ordre du formulaire).
- Sois très concis (maximum 3-5 mots par point).
- Si et seulement si TOUTE la liste fournie est 'Néant', réponds UNIQUEMENT: 'Aucune anomalie détectée lors de l'inspection.'
- NE LISTE PAS les points qui sont en bon état.";

    $userPrompt = "LISTE DES DÉFAUTS À TRAITER :\n" . (empty($allIssues) ? "Néant" : implode("\n", $allIssues));

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

    $systemPrompt = "Tu es l'Expert LENOIR-MEC. Rédige l'analyse de conclusion.
Instructions :
- NE REPRENDS PAS LE TITRE 'F) CONCLUSION' ou 'F)'.
- Synthétise le bilan technique en 2 phrases très courtes.
- Mentionne le niveau de priorité global (Urgent, Moyen, Faible).
- Style : Industriel, factuel, sans fioritures.";

    $userPrompt = "BILAN DÉTAILLÉ DES ANOMALIES :\n" . ($allIssuesString ?: "Aucun défaut majeur.") . "\n\n" .
                  "Rédige la conclusion finale.";

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
