<?php
$file = 'c:/Users/tomdu/Desktop/saas_lenoir_fiches_techniques/api/machine_edit.php';
$content = file_get_contents($file);

$replacements = [
    'Ã©' => 'é',
    'Ã ' => 'à',
    'Ã»' => 'û',
    'Ã´' => 'ô',
    'Ã‰' => 'É',
    'â€“' => '—',
    'âž¤' => '➤',
    'Ã˜' => 'Ø',
    'MATÃ‰RIEL' => 'MATÉRIEL',
    'Ã©tage' => 'étage',
    'EtanchÃ©itÃ©' => 'Étanchéité',
    'Ã‰TAT GLOBAL Ã‰TAGE' => 'ÉTAT GLOBAL ÉTAGE',
    'dÃ©faut' => 'défaut',
    'dÃ©fini' => 'défini',
    'dÃ©jÃ ' => 'déjà',
    'GranulomÃ©trie' => 'Granulométrie',
    'DÃ©bit' => 'Débit',
    'densitÃ©' => 'densité',
    'SchÃ©ma' => 'Schéma',
    'MagnÃ©tiques' => 'Magnétiques',
    'gÃ©nÃ©ral' => 'général',
    'ContrÃ´le' => 'Contrôle',
    'dâ€™induction' => 'd’induction',
];

$content = str_replace(array_keys($replacements), array_values($replacements), $content);

// Remove duplicate line in SGSA section
$content = str_replace(
    '<?= renderAprfRow("Étanchéité du tiroir à la fermeture", "sgsa_e".$e."_etancheite", $donnees) ?>' . "\n" . '                                <?= renderAprfRow("Étanchéité du tiroir à la fermeture", "sgsa_e".$e."_etancheite", $donnees) ?>',
    '<?= renderAprfRow("Étanchéité du tiroir à la fermeture", "sgsa_e".$e."_etancheite", $donnees) ?>',
    $content
);

file_put_contents($file, $content);
echo "Done\n";
?>
