$path = "api/machine_edit.php"
$bytes = [System.IO.File]::ReadAllBytes($path)

# Find sequence 0xE2 0x80 0xA0 0xE2 0x80 0x99 (â†’) and replace with 0xE2 0x86 0x92 (→)
# This is tricky in byte array.

# Let's use PowerShell's -replace with regex and hex escapes
$content = [System.IO.File]::ReadAllText($path, [System.Text.Encoding]::UTF8)

# â†’ is often E2 80 A0 E2 80 99
# Let's try to match by line index and replace everything between tags
$lines = Get-Content $path -Encoding UTF8
$lines[1136] = $lines[1136] -replace 'font-size:11px;">.*</span>', ('font-size:11px;">' + [char]0x2192 + '</span>')
$lines[1150] = $lines[1150] -replace 'middle;">.*', ('middle;">' + [char]0x25B6)
$lines[3752] = $lines[3752] -replace 'desc_materiel''\)">.* AJOUTER', ('desc_materiel'')">' + [char]0x2795 + ' AJOUTER')

$lines | Set-Content $path -Encoding UTF8
Write-Host "Cleanup complete."
