$path = 'c:/Users/tomdu/Desktop/saas_lenoir_fiches_techniques/api/machine_edit.php'
$bytes = [System.IO.File]::ReadAllBytes($path)
$content = [System.Text.Encoding]::UTF8.GetString($bytes)

# List of common double-encoded UTF-8 sequences
$replacements = @{
    'Ã©' = 'é'
    'Ã ' = 'à'
    'Ã»' = 'û'
    'Ã´' = 'ô'
    'Ã‰' = 'É'
    'Ãˆ' = 'È'
    'Ã‹' = 'Ë'
    'Ã§' = 'ç'
    'Ã ' = 'à'
    'â€“' = '—'
    'âž¤' = '➤'
    'Ã˜' = 'Ø'
    'â†’' = '→'
    'â–¶' = '▶'
    'Ã—' = '×'
    'Ãª' = 'ê'
}

foreach ($key in $replacements.Keys) {
    $content = $content.Replace($key, $replacements[$key])
}

# Fix specific technical terms
$content = $content.Replace('GranulomÃ©trie', 'Granulométrie')
$content = $content.Replace('DÃ©bit', 'Débit')
$content = $content.Replace('densitÃ©', 'densité')
$content = $content.Replace('MATÃ‰RIEL', 'MATÉRIEL')
$content = $content.Replace('EtanchÃ©itÃ©', 'Étanchéité')
$content = $content.Replace('Ã‰TAT GLOBAL', 'ÉTAT GLOBAL')

$newBytes = [System.Text.Encoding]::UTF8.GetBytes($content)
[System.IO.File]::WriteAllBytes($path, $newBytes)
Write-Host "Cleanup completed."
