<?php
$file = 'api/machine_edit.php';
$content = file_get_contents($file);

$replacements = [
    'â†’' => '→',
    'â–¶' => '▶',
    'âŒ›' => '⌛',
    'â Œ' => '❌',
    'âš ï¸ ' => '⚠️',
    'âž•' => '➕',
    'â ³' => '⏳',
    'ðŸ“¸' => '📸',
    'ðŸ“·' => '📷',
    'cÃ¢bles' => 'câbles',
    'cÃ¢ble' => 'câble'
];

foreach ($replacements as $search => $replace) {
    $content = str_replace($search, $replace, $content);
}

file_put_contents($file, $content);
echo "Cleanup complete.\n";
?>
