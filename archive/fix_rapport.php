<?php
$file = 'c:\\Users\\tomdu\\Desktop\\saas_lenoir_fiches_techniques\\api\\rapport_final.php';
$content = file_get_contents($file);

// Fix 1: ensureImagesBase64 - the block was already updated by the FIRST replace_file_content but let's be sure
$old_block1 = "                    // Prepend origin if relative
                    let absoluteUrl = src;
                    if (src.startsWith('/') && !src.startsWith('//')) {
                        absoluteUrl = window.location.origin + src;
                    }";

$new_block1 = "                    // Prepend origin if relative
                    let absoluteUrl = src;
                    if (!src.startsWith('http') && !src.startsWith('data:')) {
                        if (src.startsWith('/')) {
                            absoluteUrl = window.location.origin + src;
                        } else {
                            absoluteUrl = window.location.origin + '/' + src;
                        }
                    }";

// Fix 2: machine image loop (which is currently empty or broken)
$broken_img_loop = "                            p.querySelectorAll('img').forEach(img => {
                                }
                            });";

$fixed_img_loop = "                            p.querySelectorAll('img').forEach(img => {
                                const src = img.getAttribute('src');
                                if (!src || src.startsWith('data:')) return;
                                let absoluteUrl = src;
                                if (!src.startsWith('http')) {
                                    if (src.startsWith('/')) {
                                        absoluteUrl = window.location.origin + src;
                                    } else {
                                        absoluteUrl = window.location.origin + '/' + src;
                                    }
                                }
                                img.src = absoluteUrl;
                            });";

// Fix 3: Restore the deleted textarea loop header
$deleted_textarea_header = "                                const specialKeys = ['aprf_attraction_comment'";
$restored_textarea_header = "                            p.querySelectorAll('textarea').forEach(ta => {
                                let val = ta.value || ta.innerHTML;
                                const specialKeys = ['aprf_attraction_comment'";

// Apply replacements
if (strpos($content, $old_block1) !== false) {
    $content = str_replace($old_block1, $new_block1, $content);
}

if (strpos($content, $broken_img_loop) !== false) {
    $content = str_replace($broken_img_loop, $fixed_img_loop, $content);
}

if (strpos($content, $deleted_textarea_header) !== false && strpos($content, 'p.querySelectorAll(\'textarea\')') === false) {
    $content = str_replace($deleted_textarea_header, $restored_textarea_header, $content);
}

file_put_contents($file, $content);
echo "Fix applied successfully via PHP\n";
?>
