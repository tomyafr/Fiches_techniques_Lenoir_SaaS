$path = "api/machine_edit.php"
$utf8NoBom = New-Object System.Text.UTF8Encoding($false)
$content = [System.IO.File]::ReadAllText($path)
[System.IO.File]::WriteAllText($path, $content, $utf8NoBom)
Write-Host "BOM removed from $path"
