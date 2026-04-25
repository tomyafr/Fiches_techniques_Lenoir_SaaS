$path = "api/machine_edit.php"
$content = Get-Content $path -Encoding UTF8

$arrow = [char]0x2192
$play = [char]0x25B6
$hourglass = [char]0x231B
$cross = [char]0x274C
$warning = [char]0x26A0 + [char]0xFE0F
$plus = [char]0x2795
$timer = [char]0x23F3
$camera1 = [char]0xD83D + [char]0xDCF8
$camera2 = [char]0xD83D + [char]0xDCF7

$content[1136] = $content[1136] -replace "â†’", $arrow
$content[1150] = $content[1150] -replace "â–¶", $play
$content[3559] = $content[3559] -replace "âŒ›", $hourglass
$content[3578] = $content[3578] -replace "â Œ", $cross
$content[3606] = $content[3606] -replace "âš ï¸ ", $warning
$content[3629] = $content[3629] -replace "âŒ›", $hourglass
$content[3637] = $content[3637] -replace "â Œ", $cross
$content[3639] = $content[3639] -replace "ðŸ“·", $camera2
$content[3750] = $content[3750] -replace "ðŸ“¸", $camera1
$content[3752] = $content[3752] -replace "âž•", $plus
$content[3848] = $content[3848] -replace "â ³", $timer
$content[3878] = $content[3878] -replace "â ³", $timer

$content | Set-Content $path -Encoding UTF8
Write-Host "Cleanup complete."
