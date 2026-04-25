$path = "api/machine_edit.php"
$content = [System.IO.File]::ReadAllText($path, [System.Text.Encoding]::UTF8)

$replacements = @{
    "â†’" = "→"
    "â–¶" = "▶"
    "âŒ›" = "⌛"
    "â Œ" = "❌"
    "âš ï¸ " = "⚠️"
    "âž•" = "➕"
    "â ³" = "⏳"
    "ðŸ“¸" = "📸"
    "ðŸ“·" = "📷"
    "cÃ¢bles" = "câbles"
    "cÃ¢ble" = "câble"
}

foreach ($key in $replacements.Keys) {
    $content = $content.Replace($key, $replacements[$key])
}

[System.IO.File]::WriteAllText($path, $content, [System.Text.Encoding]::UTF8)
Write-Host "Cleanup complete."
