<?php
$file = 'api/machine_edit.php';
$content = file_get_contents($file);

$replacements = [
    'Ã©' => 'é',
    'Ã ' => 'à',
    'Ã¨' => 'è',
    'Ã´' => 'ô',
    'Ãª' => 'ê',
    'Ã«' => 'ë',
    'Ã®' => 'î',
    'Ã¯' => 'ï',
    'Ã¹' => 'ù',
    'Ã»' => 'û',
    'Ã§' => 'ç',
    'Ã‰' => 'É',
    'Ãˆ' => 'È',
    'Ã€' => 'À',
    'Ã‚' => 'Â',
    'ÃŽ' => 'Î',
    'Ã ' => 'Ï',
    'Ã”' => 'Ô',
    'Ã›' => 'Û',
    'Ã™' => 'Ù',
    'Ã‡' => 'Ç',
    'Ã¦' => 'æ',
    'Ã¸' => 'ø',
    'Ã˜' => 'Ø',
    'Â°C' => '°C',
    'Ã—' => '×',
    'â€“' => '–',
    'â€”' => '—',
    'â€' => '—',
    'Ã€' => 'à',
    'CHRONOMÃˆTRE' => 'CHRONOMÈTRE',
    'prÃ©-remplie' => 'pré-remplie',
    'dÃ©tectÃ©s' => 'détectés',
    'Ã©diter' => 'éditer',
    'â ³' => '⏳',
    'â Œ' => '❌',
    'âŒ›' => '⌛',
    'â†’' => '→',
    'â–▶' => '▶',
    'â–¶' => '▶'
];

foreach ($replacements as $search => $replace) {
    $content = str_replace($search, $replace, $content);
}

// Remove BOM if any
if (substr($content, 0, 3) === "\xEF\xBB\xBF") {
    $content = substr($content, 3);
}

file_put_contents($file, $content);
echo "Massive cleanup complete.\n";
?>
