$path = "api/rapport_final.php"
$content = [System.IO.File]::ReadAllText($path)
$bad = '                            p.querySelectorAll(\'input[type="radio"]:checked\').forEach(r => {
                                const lbl = r.closest(\'label\');
                                if (lbl) lbl.classList.add(\'selected\');
                            });

                                let src = img.getAttribute(\'src\') || \'\';
                                if (src.startsWith(\'/\') && !src.startsWith(\'//\')) {
                                    img.src = window.location.origin + src;
                                }
                            });'
$good = '                            p.querySelectorAll(\'input[type="radio"]:checked\').forEach(r => {
                                const lbl = r.closest(\'label\');
                                if (lbl) lbl.classList.add(\'selected\');
                            });

                            p.querySelectorAll(\'input:not([type="radio"]):not([type="checkbox"]):not([type="hidden"]):not([type="file"])\').forEach(inp => {
                                let val = (inp.value || \'\').trim();
                                if (inp.name === \'mesures[poste]\') {
                                    val = val ? val : (mIdx + 1);
                                } else if (!val) {
                                    val = \'\';
                                }
                                let style = inp.getAttribute(\'style\') || \'\';
                                let newStyle = style + "; border-bottom:1px dashed black; display:inline-block; min-width:30px; padding:0 3px; font-weight:bold;";
                                inp.outerHTML = `<span style="${newStyle}">${val}</span>`;
                            });

                            p.querySelectorAll(\'select\').forEach(sel => {
                                let valText = sel.options[sel.selectedIndex]?.text || \'\';
                                if (!sel.value) valText = \'\';
                                sel.outerHTML = `<span style="border-bottom:1px dashed black; display:inline-block; min-width:30px; padding:0 3px; font-weight:bold; color:black;">${valText}</span>`;
                            });

                            p.querySelectorAll(\'img\').forEach(img => {
                                let src = img.getAttribute(\'src\') || \'\';
                                if (src.startsWith(\'/\') && !src.startsWith(\'//\')) {
                                    img.src = window.location.origin + src;
                                }
                            });'
if ($content.Contains($bad)) {
    $content = $content.Replace($bad, $good)
    [System.IO.File]::WriteAllText($path, $content)
    Write-Host "SUCCESS"
} else {
    Write-Host "ERROR: Not found"
}
