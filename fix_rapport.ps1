$source = "c:\Users\tomdu\Desktop\saas_lenoir_fiches_techniques\api\rapport_final.php"
$target = "c:\Users\tomdu\Desktop\saas_lenoir_fiches_techniques\api\rapport_final_fixed.php"

$content = Get-Content -Path $source
$stablePart = $content[0..1083]

$newScript = @"
        });

        // ══════════════════════════════════════════════════════════════════
        // ENGINE V4.1 : STYLES ET HELPERS MODULAIRES (100+ PAGES)
        // ══════════════════════════════════════════════════════════════════

        const PDF_STYLES = ``
            .pdf-page { width: 21cm; min-height: 100px; background: white; color: black; padding: 0 15mm; box-sizing: border-box; margin: 0; font-family: Arial, sans-serif; font-size: 13px; position: relative; }
            .pdf-section-title { text-align: left !important; color: #d35400 !important; font-size: 16px !important; margin-bottom: 20px !important; border-bottom: 2px solid #d35400 !important; padding-bottom: 5px !important; text-transform: uppercase !important; font-weight: bold !important; page-break-after: avoid !important; }
            .section-wrapper-pdf { page-break-inside: avoid !important; break-inside: avoid !important; margin-top: 25px !important; margin-bottom: 25px !important; display: block !important; width: 100% !important; }
            .pdf-table-container, .card, .sig-zone, .photo-annexe-item { page-break-inside: avoid !important; }
            .pdf-table { width: 100%; border-collapse: collapse; margin-bottom: 20px; color: black; font-size: 11px; table-layout: fixed; }
            .pdf-table th, .pdf-table td { border: 1px solid #000; padding: 4px; vertical-align: middle; word-wrap: break-word; word-break: break-word; }
            .pdf-table th { background-color: #f0f0f0; text-align: left; text-transform: uppercase; }
            .pastille-group { display: flex; gap: 4px; align-items: center; justify-content: center; width: 100%; height: 100%; }
            .pastille-group input[type="radio"] { display: none !important; }
            .pastille-group label { display: inline-block; width: 18px; height: 18px; border-radius: 50%; border: 1px solid #777 !important; background: #eee !important; position: relative; }
            .pastille-group label.selected.p-ok { background-color: #28a745 !important; border-color: #1e7e34 !important; }
            .pastille-group label.selected.p-aa { background-color: #f39c12 !important; border-color: #d35400 !important; }
            .pastille-group label.selected.p-nc { background-color: #dc3545 !important; border-color: #bd2130 !important; }
            .pastille-group label.selected.p-nr { background-color: #8b0000 !important; border-color: #5a0000 !important; }
            .pastille-group label.selected.p-na { background-color: #95a5a6 !important; border-color: #7f8c8d !important; }
            .pastille-group label.selected::after { content: ""; position: absolute; top: 50%; left: 50%; width: 6px; height: 6px; background: white; border-radius: 50%; transform: translate(-50%, -50%); }
            .diagonal-header { height: 120px; vertical-align: bottom; padding: 0 !important; position: relative; background: #e0e0e0 !important; border: 1px solid #000 !important; }
            .diagonal-wrapper { display: flex; width: 140px; height: 100%; position: relative; margin: 0 auto; }
            .diag-col { width: 28px; height: 100%; position: relative; flex-shrink: 0; }
            .diag-col::before { content: ""; position: absolute; left: 100%; top: 0; bottom: 30px; width: 1px; background: #000; transform: skewX(-35deg); transform-origin: bottom left; }
            .diag-text { position: absolute; bottom: 35px; left: 100%; padding-left: 5px; transform: rotate(-55deg); transform-origin: bottom left; text-align: left; white-space: nowrap; font-size: 8px; font-weight: bold; width: 200px; }
            .pdf-textarea-rendered { width: 100%; font-family: Arial; font-size: 9pt; color: black; white-space: pre-wrap; padding: 4px; }
            .no-print-pdf, .btn-ia-refresh, .top-bar, .photo-btn, .photo-thumbs, #btnChrono { display: none !important; }
            img { max-width: 100%; }
        ``;

        async function waitForImages(element) {
            const images = element.querySelectorAll('img');
            const promises = Array.from(images).map(img => {
                if (img.complete) return Promise.resolve();
                return new Promise(resolve => { img.onload = resolve; img.onerror = resolve; });
            });
            await Promise.all(promises);
            return new Promise(r => setTimeout(r, 200));
        }

        function createPdfFooter() {
            const f = document.createElement('div');
            const leg = window.LM_RAPPORT.legal;
            f.style.cssText = 'margin-top:20px; width:100%; text-align:center; font-size:9px; font-weight:bold; border-top:2px solid #000; padding:5px 0; page-break-inside:avoid;';
            f.innerHTML = `${leg.address}<br>${leg.contact}<br>${leg.siret}`;
            return f;
        }

        async function getPdfBytes(element) {
            const opt = { margin:0, filename:'temp.pdf', image:{type:'jpeg',quality:0.98}, html2canvas:{scale:2, useCORS:true}, jsPDF:{unit:'mm', format:'a4', orientation:'portrait'} };
            const pdfBuf = await html2pdf().set(opt).from(element).outputPdf('arraybuffer');
            return new Uint8Array(pdfBuf);
        }

        async function buildMachinePageContainer(mId, mIdx, totalMachines) {
            const container = document.createElement('div');
            const styleNode = document.createElement('style');
            styleNode.textContent = PDF_STYLES;
            container.appendChild(styleNode);
            try {
                const res = await fetch(`machine_edit.php?id=${mId}&pdf=1`);
                const html = await res.text();
                const parser = new DOMParser();
                const doc = parser.parseFromString(html, 'text/html');
                const pages = Array.from(doc.querySelectorAll('.pdf-page'));
                pages.forEach((p, pIdx) => {
                    p.style.margin = '0'; p.style.boxShadow = 'none';
                    p.querySelectorAll('.photo-btn, .btn-ia-refresh, .top-bar, .photo-thumbs, #btnChrono, .no-print-pdf, .photo-del-overlay').forEach(el => el.remove());
                    if (pIdx === 0) {
                        p.style.pageBreakBefore = 'always';
                        const hDiv = document.createElement('div');
                        hDiv.style.cssText = 'text-align:right; font-size:12px; font-weight:bold; color:#1B4F72; margin-bottom:5px;';
                        hDiv.innerHTML = `FICHE ${mIdx + 1} / ${totalMachines}`;
                        p.insertBefore(hDiv, p.firstChild);
                    }
                    p.querySelectorAll('input:not([type="radio"]):not([type="hidden"])').forEach(inp => {
                        let val = (inp.value || '').trim();
                        if (inp.name === 'mesures[poste]') val = val || (mIdx + 1);
                        inp.outerHTML = `<span style="border-bottom:1px dashed black; display:inline-block; min-width:30px; padding:0 3px; font-weight:bold;">${val}</span>`;
                    });
                    p.querySelectorAll('textarea').forEach(ta => {
                        const val = ta.value || ta.innerHTML;
                        if (val.trim()) {
                            const div = document.createElement('div');
                            div.className = 'pdf-textarea-rendered';
                            div.textContent = val;
                            ta.parentNode.insertBefore(div, ta);
                        }
                        ta.remove();
                    });
                    p.querySelectorAll('img').forEach(img => {
                        if (img.src.startsWith('/') && !img.src.startsWith('//')) {
                            img.src = window.location.origin + img.src;
                        }
                    });
                    p.appendChild(createPdfFooter());
                    container.appendChild(p);
                });
            } catch (err) { console.error('Error machine ' + mId, err); }
            return container;
        }

        async function buildHeaderPagesContainer() {
            const container = document.createElement('div');
            const styleNode = document.createElement('style');
            styleNode.textContent = PDF_STYLES;
            container.appendChild(styleNode);
            const d = window.LM_RAPPORT;
            const techNameLabel = "<?= htmlspecialchars($techName) ?>";
            const sigTechData = d.sigTech || document.getElementById('canvasTech')?.toDataURL() || '';

            const p1 = document.createElement('div');
            p1.className = 'pdf-page';
            p1.innerHTML = ``
                <table style="width:100%; border:none; margin-bottom:15px; border-bottom: 2px solid #1e4e6d;">
                    <tr><td style="width: 40%; vertical-align: bottom; padding-bottom: 10px;"><img src="/assets/lenoir_logo_doc.png" style="height:60px;"></td><td style="width: 60%; vertical-align: bottom; text-align: right; padding-bottom: 5px;"><div style="font-size: 11px; color:#1e4e6d; font-style:italic;">Le spécialiste des applications magnétiques pour la séparation et le levage industriel</div></td></tr>
                </table>
                <div style="border:3px solid #1e4e6d; padding:15px; margin-bottom:30px;">
                    <h1 style="text-align:center; color:#1e4e6d; font-size:26px; margin: 10px 0 20px 0;">RAPPORT DE L'EXPERTISE</h1>
                    <div style="text-align:right; font-weight:bold; font-size:14px; margin-bottom:15px;">N°ARC : ${d.arc}</div>
                    <table style="width:100%; border-collapse:collapse; border:2px solid #1e4e6d; margin-bottom:20px; font-size:12px;">
                        <tr><td colspan="4" style="background:#5b9bd5; color:white; text-align:center; font-weight:bold; padding:6px; border:1px solid #000;">COORDONNEES DU CLIENT</td></tr>
                        <tr><td style="font-weight:bold; padding:6px; border:1px solid #000; width:20%;">Société</td><td style="padding:6px; border:1px solid #000; width:30%;">${d.nomSociete}</td><td style="font-weight:bold; padding:6px; border:1px solid #000; width:20%;">Date</td><td style="padding:6px; border:1px solid #000; width:30%;">${d.dateInt}</td></tr>
                    </table>
                </div>
                <table style="width:100%; border-collapse:collapse; border:2px solid #1e4e6d; font-size:13px;">
                    <tr><td style="font-weight:bold; padding:15px; border:1px solid #1e4e6d; width:25%;">Technicien:</td><td style="padding:15px; border:1px solid #1e4e6d; width:30%;">${techNameLabel}</td><td style="padding:5px; border:1px solid #1e4e6d; width:45%; text-align:center;"><img src="${sigTechData}" style="max-height:80px;"></td></tr>
                </table>
            ``;
            p1.appendChild(createPdfFooter());
            container.appendChild(p1);

            const p2 = document.createElement('div');
            p2.className = 'pdf-page';
            p2.style.pageBreakBefore = 'always';
            const s = d.synth;
            p2.innerHTML = ``<div style="padding-top:20px;"><h2 style="font-weight:bold; font-size:18px; color:#1e4e6d; margin:0 0 25px 0; border-bottom:2px solid #1e4e6d; text-transform:uppercase;">SYNTHÈSE DE L'INTERVENTION</h2><div style="margin-bottom:15px; font-size:13px;"><div><strong>Technicien :</strong> ${s.tech}</div><div><strong>Date :</strong> ${s.date}</div><div><strong>Équipements :</strong> ${s.nbMachines}</div></div></div>``;
            p2.appendChild(createPdfFooter());
            container.appendChild(p2);
            return container;
        }

        async function buildFooterPageContainer() {
            const container = document.createElement('div');
            const styleNode = document.createElement('style');
            styleNode.textContent = PDF_STYLES;
            container.appendChild(styleNode);
            const d = window.LM_RAPPORT;
            const endPage = document.createElement('div');
            endPage.className = 'pdf-page';
            endPage.style.pageBreakBefore = 'always';
            const sigClientData = d.sigClient || document.getElementById('canvasClient')?.toDataURL() || '';
            const nomSignataire = document.querySelector('[name="nom_signataire"]')?.value || '_____';
            endPage.innerHTML = ``<div style="padding-top:20px;"><div style="margin-top:40px;"><h2 style="color:#1e4e6d; font-size:16px; border-bottom:2px solid #1e4e6d; padding-bottom:5px; text-transform:uppercase;">SIGNATURES</h2><table style="width:100%; border:1px solid #1e4e6d;"><tr><td style="width:50%; padding:20px; text-align:center; border-right:1px solid #1e4e6d;"><strong>Technicien LENOIR</strong><br><br><img src="${d.sigTech || ''}" style="max-height:100px;"></td><td style="width:50%; padding:20px; text-align:center;"><strong>Client (${nomSignataire})</strong><br><br><img src="${sigClientData}" style="max-height:100px;"></td></tr></table></div></div>``;
            endPage.appendChild(createPdfFooter());
            container.appendChild(endPage);
            return container;
        }

        async function genererPDFBlob(onProgress) {
            const { PDFDocument, rgb } = PDFLib;
            const finalPdf = await PDFDocument.create();
            const ids = (window.LM_RAPPORT && window.LM_RAPPORT.machinesIds) ? window.LM_RAPPORT.machinesIds : [];
            const total = 2 + ids.length;
            let step = 0;
            const addLayer = async (cnt, lbl) => {
                step++; if (onProgress) onProgress(Math.round((step / total) * 100), lbl);
                const bytes = await getPdfBytes(cnt);
                const doc = await PDFDocument.load(bytes);
                const pages = await finalPdf.copyPages(doc, doc.getPageIndices());
                pages.forEach(p => finalPdf.addPage(p));
            };
            await addLayer(await buildHeaderPagesContainer(), "Préparation de la couverture...");
            for (let i = 0; i < ids.length; i++) {
                await addLayer(await buildMachinePageContainer(ids[i], i, ids.length), `Fiche ${i + 1}/${ids.length}...`);
            }
            await addLayer(await buildFooterPageContainer(), "Finalisation...");
            const pages = finalPdf.getPages();
            if (pages.length > 0) {
                const { width, height } = pages[0].getSize();
                for (let i = 0; i < pages.length; i++) {
                    pages[i].drawText(`Page ${i + 1} / ${pages.length}`, { x: width / 2 - 20, y: 15, size: 9, color: rgb(0.3, 0.3, 0.3) });
                }
            }
            return await finalPdf.save();
        }

        async function telechargerPDF() {
            const btn = document.getElementById('btnDownloadPdf');
            const progressZone = document.getElementById('ai-global-progress');
            const progressBar = document.getElementById('ai-progress-bar');
            const progressText = document.getElementById('ai-progress-text');
            const progressPercent = document.getElementById('ai-progress-percent');
            if (btn) btn.disabled = true;
            if (progressZone) progressZone.style.display = 'block';
            try {
                const bytes = await genererPDFBlob((pct, msg) => {
                    if (progressBar) progressBar.style.width = pct + '%';
                    if (progressPercent) progressPercent.textContent = pct + '%';
                    if (progressText) progressText.textContent = msg;
                });
                const blob = new Blob([bytes], { type: 'application/pdf' });
                const link = document.createElement('a');
                link.href = URL.createObjectURL(blob);
                link.download = `Rapport_Expertise_${(window.LM_RAPPORT && window.LM_RAPPORT.arc) || 'Final'}.pdf`;
                link.click();
            } catch (e) { alert("Erreur génération PDF: " + e.message); }
            finally { if (btn) btn.disabled = false; setTimeout(() => { if (progressZone) progressZone.style.display = 'none'; }, 2000); }
        }

        async function genererPDFBase64() {
             const bytes = await genererPDFBlob();
             return btoa(String.fromCharCode(...new Uint8Array(bytes)));
        }

        function afficherToast(message, type = 'success') {
            const toast = document.getElementById('emailToast');
            if (!toast) return;
            toast.textContent = message;
            toast.style.cssText = 'display:block; background:rgba(0,0,0,0.8); color:white; padding:10px; border-radius:5px; position:fixed; bottom:20px; left:50%; transform:translateX(-50%); z-index:9999;';
            setTimeout(() => { toast.style.display = 'none'; }, 3000);
        }

        async function envoyerParAPI(interventionId, pdfBase64, clientEmail, csrfToken) {
            const formData = new FormData();
            formData.append('intervention_id', interventionId);
            formData.append('pdf_data', pdfBase64);
            formData.append('client_email', clientEmail);
            formData.append('csrf_token', csrfToken);
            const resp = await fetch('/envoyer_rapport.php', { method: 'POST', body: formData, credentials: 'same-origin' });
            return resp.json();
        }

        async function lancerEnvoiEmail() {
            if (!window.LM_RAPPORT) return;
            try {
                const b64 = await genererPDFBase64();
                const res = await envoyerParAPI(window.LM_RAPPORT.interventionId, b64, window.LM_RAPPORT.clientEmail, window.LM_RAPPORT.csrfToken);
                if (res.success) afficherToast('Email envoyé !');
                else alert('Erreur : ' + res.message);
            } catch (e) { alert('Erreur : ' + e.message); }
        }

        async function generateAllIA() {
            const progressZone = document.getElementById('ai-global-progress');
            if (progressZone) progressZone.style.display = 'block';
            location.reload(); 
        }

        function containerSuccessBanner() { return document.getElementById('successBanner') !== null; }
    </script>
</body>
</html>
"@

$finalContent = ($stablePart -join "`n") + "`n" + $newScript
$finalContent | Out-File -FilePath $target -Encoding utf8
