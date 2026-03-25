<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/ia_helper.php';

$db = getDB();
$stmt = $db->query('SELECT * FROM machines ORDER BY id DESC LIMIT 1');
$machine = $stmt->fetch();

if (!$machine) die("No machine found.");

$donnees = json_decode($machine['donnees_controle'] ?? '{}', true);
$issues = extractIssuesFromDonnees($donnees);

header('Content-Type: application/json');
echo json_encode([
    'machine' => $machine['designation'],
    'raw_donnees_count' => count($donnees),
    'extracted_issues' => $issues,
    'raw_sample' => array_slice($donnees, 0, 10, true)
], JSON_PRETTY_PRINT);
