$path = 'c:/Users/tomdu/Desktop/saas_lenoir_fiches_techniques/api/machine_edit.php'
$content = Get-Content -Path $path -Raw -Encoding UTF8

$replacements = @{
    'Ã©' = 'é'
    'Ã ' = 'à'
    'Ã»' = 'û'
    'Ã´' = 'ô'
    'Ã‰' = 'É'
    'â€“' = '—'
    'âž¤' = '➤'
    'Ã˜' = 'Ø'
    'MATÃ‰RIEL' = 'MATÉRIEL'
    'Ã©tage' = 'étage'
    'EtanchÃ©itÃ©' = 'Étanchéité'
    'Ã‰TAT GLOBAL Ã‰TAGE' = 'ÉTAT GLOBAL ÉTAGE'
    'dÃ©faut' = 'défaut'
    'dÃ©fini' = 'défini'
    'dÃ©jÃ ' = 'déjà'
    'GranulomÃ©trie' = 'Granulométrie'
    'DÃ©bit' = 'Débit'
    'densitÃ©' = 'densité'
    'SchÃ©ma' = 'Schéma'
    'MagnÃ©tiques' = 'Magnétiques'
    'gÃ©nÃ©ral' = 'général'
    'ContrÃ´le' = 'Contrôle'
    'dâ€™induction' = 'd’induction'
}

foreach ($key in $replacements.Keys) {
    $content = $content.Replace($key, $replacements[$key])
}

# Remove duplicate line
$duplicateLine = '<?= renderAprfRow("Étanchéité du tiroir à la fermeture", "sgsa_e".$e."_etancheite", $donnees) ?>' + "`n" + '                                <?= renderAprfRow("Étanchéité du tiroir à la fermeture", "sgsa_e".$e."_etancheite", $donnees) ?>'
$content = $content.Replace($duplicateLine, '<?= renderAprfRow("Étanchéité du tiroir à la fermeture", "sgsa_e".$e."_etancheite", $donnees) ?>')

Set-Content -Path $path -Value $content -Encoding UTF8
Write-Host "Done"
