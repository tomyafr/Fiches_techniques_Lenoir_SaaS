<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/ia_helper.php';

$mockDonnees = [
    'edx_C_au' => 'nc',
    'edx_B_asp' => 'aa',
    'edx_B_dem' => 'nr',
    'aprf_satisfaction' => 'r'
];

$issues = extractIssuesFromDonnees($mockDonnees);

echo "NR Issues: " . count($issues['nr']) . "\n";
foreach ($issues['nr'] as $i) echo "- " . $i['designation'] . "\n";

echo "NC Issues: " . count($issues['nc']) . "\n";
foreach ($issues['nc'] as $i) echo "- " . $i['designation'] . "\n";

echo "AA Issues: " . count($issues['aa']) . "\n";
foreach ($issues['aa'] as $i) echo "- " . $i['designation'] . "\n";
?>
