<?php
function fix_encoding($filepath) {
    $content = file_get_contents($filepath);
    $original_content = $content;

    // 1. Remove UTF-8 BOM
    if (strpos($content, "\xEF\xBB\xBF") === 0) {
        $content = substr($content, 3);
        echo "Removed BOM from $filepath\n";
    }

    // 2. Fix double-encoded UTF-8 characters (common in French)
    $replacements = [
        "\xC3\x83\xC2\xA9" => "\xC3\xA9", // é
        "\xC3\x83\xC2\xA8" => "\xC3\xA8", // è
        "\xC3\x83\xC2\xAA" => "\xC3\xAA", // ê
        "\xC3\x83\xC2\xAB" => "\xC3\xAB", // ë
        "\xC3\x83\xC2\xA0" => "\xC3\xA0", // à
        "\xC3\x83\xc2\xa2" => "\xc3\xa2", // â
        "\xC3\x83\xC2\xAE" => "\xC3\xae", // î
        "\xC3\x83\xC2\xAF" => "\xC3\xaf", // ï
        "\xC3\x83\xC2\xB4" => "\xC3\xb4", // ô
        "\xC3\x83\xC2\xBB" => "\xC3\xbb", // û
        "\xC3\x83\xC2\xB9" => "\xC3\xb9", // ù
        "\xC3\x83\xC2\xA7" => "\xC3\xa7", // ç
        "\xC3\x83\xC2\x89" => "\xC3\x89", // É
        "\xC3\x83\xC2\x80" => "\xC3\x80", // À
        "\xC3\x82\xc2\xb0" => "\xc2\xb0", // °
    ];

    foreach ($replacements as $old => $new) {
        $content = str_replace($old, $new, $content);
    }

    // 3. Remove closing PHP tag at end of file if it's the last thing
    $trimmed = rtrim($content);
    if (substr($trimmed, -2) === '?>') {
        $content = substr($trimmed, 0, -2);
        echo "Removed closing PHP tag from $filepath\n";
    }

    if ($content !== $original_content) {
        file_put_contents($filepath, $content);
        echo "Fixed $filepath\n";
    }
}

function scan_dir($dir) {
    $files = scandir($dir);
    foreach ($files as $file) {
        if ($file === '.' || $file === '..' || $file === '.git' || $file === '.vercel') continue;
        $path = $dir . DIRECTORY_SEPARATOR . $file;
        if (is_dir($path)) {
            scan_dir($path);
        } elseif (substr($file, -4) === '.php') {
            fix_encoding($path);
        }
    }
}

scan_dir('c:\Users\tomdu\Desktop\saas_lenoir_fiches_techniques');
