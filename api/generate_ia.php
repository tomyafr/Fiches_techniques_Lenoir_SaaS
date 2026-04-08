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

// Protection IDOR: Vérification de l'appartenance de la machine à l'intervention du technicien
$stmt = $db->prepare('SELECT m.*, i.technicien_id FROM machines m JOIN interventions i ON m.intervention_id = i.id WHERE m.id = ?');
$stmt->execute([$machineId]);
$machine = $stmt->fetch();

if (!$machine) {
    echo json_encode(['success' => false, 'error' => 'Machine introuvable']);
    exit;
}
if ($_SESSION['role'] !== 'admin' && $machine['technicien_id'] !== $_SESSION['user_id']) {
    echo json_encode(['success' => false, 'error' => 'Accès non autorisé à cette machine']);
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
    $systemPromptE = "Tu es le rédacteur technique officiel des rapports d'expertise Lenoir-Mec (séparation magnétique et levage industriel, groupe Delachaux).

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

    $mesures = json_decode($machine['mesures'] ?? '{}', true);
    $poste = $mesures['poste'] ?? 'N/A';
    
    $listeNR = implode("\n", array_map(fn($i) => "- " . $i['designation'] . ($i['commentaire'] ? " (" . $i['commentaire'] . ")" : ""), $issues['nr']));
    $listeNC = implode("\n", array_map(fn($i) => "- " . $i['designation'] . ($i['commentaire'] ? " (" . $i['commentaire'] . ")" : ""), $issues['nc']));
    $listeAA = implode("\n", array_map(fn($i) => "- " . $i['designation'] . ($i['commentaire'] ? " (" . $i['commentaire'] . ")" : ""), $issues['aa']));

    $userPromptE = "MACHINE : " . $machine['designation'] . " — Poste " . $poste . "\n\n";
    $userPromptE .= "POINTS À REMPLACER (N.R) :\n" . ($listeNR ?: "Néant") . "\n\n";
    $userPromptE .= "POINTS NON CONFORMES (N.C) :\n" . ($listeNC ?: "Néant") . "\n\n";
    $userPromptE .= "POINTS À AMÉLIORER (A.A) :\n" . ($listeAA ?: "Néant") . "\n\n";
    $userPromptE .= "Rédige les constats de dysfonctionnement.";

    $resultE = callGroqIA($systemPromptE, $userPromptE, [
        'temperature' => 0.15,
        'max_tokens' => 300,
        'top_p' => 0.9,
        'frequency_penalty' => 0.1,
        'presence_penalty' => 0.0
    ]);
    
    // Check if it's an actual IA error string
    if ($resultE && strpos($resultE, 'Erreur IA') === 0) {
        echo json_encode(['success' => false, 'error' => $resultE]);
        exit;
    }

    // Fallback if IA fails (null or empty)
    if (!$resultE) {
        $allIssues = [];
        foreach (['nr', 'nc', 'aa'] as $cat) {
            foreach ($issues[$cat] as $i) {
                $allIssues[] = "• " . $i['designation'] . ($i['commentaire'] ? " : " . $i['commentaire'] : "");
            }
        }
        $resultE = !empty($allIssues) ? implode("\n", $allIssues) : "";
    }
    
    if ($type === 'E') {
        $response['content'] = $resultE;
    } else {
        $response['dysfonctionnements'] = $resultE;
    }
}

if ($type === 'F' || $type === 'ALL') {
    $systemPromptF = "Tu es le rédacteur technique officiel des rapports d'expertise Lenoir-Mec (séparation magnétique et levage industriel, groupe Delachaux).

CONTEXTE : Tu rédiges la section \"F) CONCLUSION\" d'une fiche d'inspection terrain. Cette conclusion apparaît dans un rapport PDF envoyé au client final. Elle synthétise le bilan technique de l'équipement inspecté.

TYPES D'ÉQUIPEMENTS LENOIR-MEC :
- OVAP / OV : Overband (séparateur magnétique à bande)
- APRF / APRM / RD : Aimant permanent rectangulaire fixe
- ED-X : Séparateur à courants de Foucault
- TAP / PAP : Tambour ou Poulie à Aimants Permanents
- Levage : Électroaimants de levage industriel

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
    
    // Pour count_ok, on peut l'estimer ou le laisser à 0 si non traqué précisément ici
    // Dans ia_helper, on n'extrait que les issues. On va mettre une valeur indicative si possible.
    $count_ok = 0; 
    
    $allIssuesList = array_merge(
        array_map(fn($i) => "- NR: " . $i['designation'], $issues['nr']),
        array_map(fn($i) => "- NC: " . $i['designation'], $issues['nc']),
        array_map(fn($i) => "- AA: " . $i['designation'], $issues['aa'])
    );
    $allIssuesString = implode("\n", $allIssuesList);

    $userPromptF = "MACHINE : " . $machine['designation'] . " — Poste " . ($machine['mesures']['poste'] ?? 'N/A') . "\n\n";
    $userPromptF .= "RÉSUMÉ DES ANOMALIES :\n";
    $userPromptF .= "- Points à remplacer (N.R) : $count_nr\n";
    $userPromptF .= "- Points non conformes (N.C) : $count_nc\n";
    $userPromptF .= "- Points à améliorer (A.A) : $count_aa\n";
    $userPromptF .= "- Points conformes (OK) : $count_ok\n\n";
    $userPromptF .= "DÉTAIL DES ANOMALIES :\n" . ($allIssuesString ?: "Aucun défaut majeur.") . "\n\n";
    $userPromptF .= "Rédige la conclusion technique.";

    $resultF = callGroqIA($systemPromptF, $userPromptF, [
        'temperature' => 0.15,
        'max_tokens' => 300,
        'top_p' => 0.9,
        'frequency_penalty' => 0.1,
        'presence_penalty' => 0.0
    ]);
    
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
            $resultF = "";
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
