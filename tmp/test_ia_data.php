<?php
require_once __DIR__ . '/../includes/ia_helper.php';

// Simulation de données reçues d'un formulaire ED-X avec des problèmes
$donnees = [
    'edx_etat_gen' => 'aa', // Orange: À améliorer
    'edx_verrous' => 'nc',   // Rouge: Non conforme
    'edx_B_verrous' => 'nr', // Rouge: À revoir
    'ov_bande' => 'r',       // Orange: À remplacer
    'paptap_aspect' => 'hs',  // Rouge: HS
    'une_nouvelle_cle' => 'nc', // Clé non listée dans labelsMap
    'edx_tension_bande' => 'aa',
    'edx_tension_bande_comment' => 'Bande trop lâche'
];

echo "--- ANALYSE DES ISSUES ---\n";
$issues = extractIssuesFromDonnees($donnees);

echo "Points À améliorer (AA) :\n";
print_r($issues['aa']);

echo "\nPoints Non conformes (NC) :\n";
print_r($issues['nc']);

echo "\nPoints À revoir (NR) :\n";
print_r($issues['nr']);

$allIssues = [];
foreach (['nr', 'nc', 'aa'] as $cat) {
    foreach ($issues[$cat] as $i) {
        $allIssues[] = "• " . $i['designation'] . ($i['commentaire'] ? " (" . $i['commentaire'] . ")" : "");
    }
}

echo "\n--- LISTE FINALE POUR L'IA ---\n";
if (empty($allIssues)) {
    echo "Néant -> L'IA dira 'Aucune anomalie'\n";
} else {
    echo implode("\n", $allIssues) . "\n";
}
?>
