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

$donnees = json_decode($machine['donnees_controle'] ?? '{}', true);
$issues = extractIssuesFromDonnees($donnees);

$typeMachine = $machine['designation'];
$poste = json_decode($machine['mesures'] ?? '{}', true)['poste'] ?? 'N/A';

if ($type === 'E') {
    // Génération Section E
    $formattedAA = array_map(fn($i) => "• " . $i['designation'] . ($i['commentaire'] ? " (" . $i['commentaire'] . ")" : ""), $issues['aa']);
    $formattedNC = array_map(fn($i) => "• " . $i['designation'] . ($i['commentaire'] ? " (" . $i['commentaire'] . ")" : ""), $issues['nc']);
    $formattedNR = array_map(fn($i) => "• " . $i['designation'] . ($i['commentaire'] ? " (" . $i['commentaire'] . ")" : ""), $issues['nr']);

    $systemPrompt = "Tu es un expert technique Lenoir-Mec spécialiste des séparateurs magnétiques industriels. Rédige la section E) CAUSE DE DYSFONCTIONNEMENT en français. Format : une liste à puces courte (1 ligne par problème). Style : professionnel, technique, concis. NE PAS inventer de problèmes.";
    $userPrompt = "Type de machine : $typeMachine\nPoste : $poste\n\nPoints à améliorer (AA) :\n" . (empty($formattedAA) ? "Aucun" : implode("\n", $formattedAA)) . "\n\nPoints non conformes (NC) :\n" . (empty($formattedNC) ? "Aucun" : implode("\n", $formattedNC)) . "\n\nRemplacements nécessaires (NR/HS) :\n" . (empty($formattedNR) ? "Aucun" : implode("\n", $formattedNR));

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

    $systemPrompt = "En te basant sur les dysfonctionnements listés, rédige la section F) CONCLUSION. Format : 1-2 phrases max. Style rapport d'expertise Lenoir-Mec.";
    $userPrompt = "Machine : $typeMachine\nDysfonctionnements :\n" . ($allIssuesString ?: "Aucun défaut majeur.");

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
