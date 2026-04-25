$path = "api/machine_edit.php"
$content = Get-Content $path -Encoding UTF8
$content[1136] = '                            <span style="color:#999; font-size:11px;">→</span>'
$content[1150] = '                                style="background:#28a745; color:white; border:none; border-radius:4px; padding:3px 10px; font-size:11px; cursor:pointer; margin-left:8px; vertical-align:middle;">▶'
$content[3559] = '                if (grid) grid.innerHTML = ''<p style="color:#28a745; font-size:11px; margin:0;">⌛ Chargement des photos...</p>'';'
$content[3578] = '                if (grid) grid.innerHTML = ''<p style="color:#dc3545; font-size:11px; margin:0;">❌ Erreur de chargement des photos.</p>'';'
$content[3606] = '                    alert("⚠️ Attention : La fiche contient trop de photos (" + sizeMB + " Mo). Vercel limite l''enregistrement à 4.5 Mo.\nVeuillez supprimer une ou deux photos avant d''enregistrer.");'
$content[3629] = '                btn.innerHTML = ''⌛'';'
$content[3637] = '                alert("❌ Image rejetée : Le fichier est trop petit. Veuillez prendre une photo nette.");'
$content[3639] = '                    btn.innerHTML = ''📷'';'
$content[3750] = '                            <span>📸</span>'
$content[3752] = '                            <button type="button" class="photo-btn" onclick="capturePhoto(''desc_materiel'')">➕ AJOUTER PHOTO</button>'
$content[3848] = '            if (btn) { btn.innerHTML = ''⏳ Analyse expert...''; btn.disabled = true; }'
$content[3878] = '            if (btn) { btn.innerHTML = ''⏳ Analyse expert...''; btn.disabled = true; }'
$content | Set-Content $path -Encoding UTF8
Write-Host "Cleanup complete."
