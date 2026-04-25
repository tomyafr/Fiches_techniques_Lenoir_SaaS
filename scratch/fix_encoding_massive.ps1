$path = "api/machine_edit.php"
$content = Get-Content $path -Raw -Encoding UTF8

# Specific replacements for things found in Select-String
$replacements = @{
    "Ã©" = "é"
    "Ã " = "à"
    "Ã¨" = "è"
    "Ã´" = "ô"
    "Ãª" = "ê"
    "Ã«" = "ë"
    "Ã®" = "î"
    "Ã¯" = "ï"
    "Ã¹" = "ù"
    "Ã»" = "û"
    "Ã§" = "ç"
    "Ã‰" = "É"
    "Ãˆ" = "È"
    "Ã€" = "À"
    "Ã‚" = "Â"
    "ÃŽ" = "Î"
    "Ã" = "Ï"
    "Ã”" = "Ô"
    "Ã›" = "Û"
    "Ã™" = "Ù"
    "Ã‡" = "Ç"
    "Ã¦" = "æ"
    "Ã¸" = "ø"
    "Ã˜" = "Ø"
    "Â°C" = "°C"
    "Ã—" = "×"
    "â€“" = "–"
    "â€”" = "—"
    "â€" = "—"
    "Ã€" = "à" # Sometimes it's double encoded
}

foreach ($key in $replacements.Keys) {
    $content = $content -replace [regex]::Escape($key), $replacements[$key]
}

# Fix specific lines found
$content = $content -replace "CHRONOMÃˆTRE", "CHRONOMÈTRE"
$content = $content -replace "prÃ©-remplie", "pré-remplie"
$content = $content -replace "dÃ©tectÃ©s", "détectés"
$content = $content -replace "Ã©diter", "éditer"

[System.IO.File]::WriteAllText($path, $content, (New-Object System.Text.UTF8Encoding($false)))
Write-Host "Cleanup complete."
