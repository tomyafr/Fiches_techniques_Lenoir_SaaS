    <!-- html2pdf.js pour génération PDF côté client -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/signature_pad/4.1.7/signature_pad.umd.min.js"></script>
    
    <!-- BLOC SCRIPT 1: Signatures et Validation (Ultra-Robuste) -->
    <script>
        var padClient, padTech;
        var canvasWidthT = 0;
        var canvasWidthC = 0;

        function initSignatures() {
            var canvasT = document.getElementById('canvasTech');
            var canvasC = document.getElementById('canvasClient');
            if (!canvasT || !canvasC || !window.SignaturePad) return;

            var ratio = Math.max(window.devicePixelRatio || 1, 1);
            
            if (!padTech) {
                var wT = canvasT.offsetWidth || 600;
                canvasT.width = wT * ratio;
                canvasT.height = 200 * ratio;
                canvasT.getContext('2d').scale(ratio, ratio);
                padTech = new SignaturePad(canvasT, { penColor: 'black', minWidth: 1.5, maxWidth: 4.5 });
                if (window.LM_RAPPORT && window.LM_RAPPORT.sigTech && window.LM_RAPPORT.sigTech.length > 50) {
                    padTech.fromDataURL(window.LM_RAPPORT.sigTech, { ratio: ratio, width: wT, height: 200 });
                }
                canvasWidthT = wT;
            }

            if (!padClient) {
                var wC = canvasC.offsetWidth || 600;
                canvasC.width = wC * ratio;
                canvasC.height = 200 * ratio;
                canvasC.getContext('2d').scale(ratio, ratio);
                padClient = new SignaturePad(canvasC, { penColor: 'blue', minWidth: 1.5, maxWidth: 4.5 });
                if (window.LM_RAPPORT && window.LM_RAPPORT.sigClient && window.LM_RAPPORT.sigClient.length > 50) {
                    padClient.fromDataURL(window.LM_RAPPORT.sigClient, { ratio: ratio, width: wC, height: 200 });
                }
                canvasWidthC = wC;
            }
        }

        document.addEventListener('DOMContentLoaded', function() {
            var cp = document.getElementById('contact_prenom');
            var cn = document.getElementById('contact_nom');
            var sn = document.getElementById('nom_signataire');
      
            if (cp && cn && sn) {
                var updateSign = function() {
                    sn.value = (cp.value.trim() + ' ' + cn.value.trim()).trim();
                };
                cp.addEventListener('input', updateSign);
                cn.addEventListener('input', updateSign);
            }
            initSignatures();
            setTimeout(initSignatures, 500);
            setTimeout(initSignatures, 1500);
        });

        window.onload = function() {
            initSignatures();
            setTimeout(initSignatures, 1000);
        };

        window.addEventListener('resize', function() {
            var ratio = Math.max(window.devicePixelRatio || 1, 1);
            if (padTech && document.getElementById('canvasTech')) {
                var c = document.getElementById('canvasTech');
                if (c.offsetWidth !== canvasWidthT && c.offsetWidth > 10) {
                    var d = padTech.toDataURL();
                    canvasWidthT = c.offsetWidth;
                    c.width = canvasWidthT * ratio;
                    c.height = 200 * ratio;
                    c.getContext('2d').scale(ratio, ratio);
                    padTech.clear();
                    padTech.fromDataURL(d, { ratio: ratio, width: canvasWidthT, height: 200 });
                }
            }
            if (padClient && document.getElementById('canvasClient')) {
                var c = document.getElementById('canvasClient');
                if (c.offsetWidth !== canvasWidthC && c.offsetWidth > 10) {
                    var d = padClient.toDataURL();
                    canvasWidthC = c.offsetWidth;
                    c.width = canvasWidthC * ratio;
                    c.height = 200 * ratio;
                    c.getContext('2d').scale(ratio, ratio);
                    padClient.clear();
                    padClient.fromDataURL(d, { ratio: ratio, width: canvasWidthC, height: 200 });
                }
            }
        });

        function clearSig(type) {
            if (type === 'Tech' && padTech) padTech.clear();
            if (type === 'Client' && padClient) padClient.clear();
        }

        function savePads() {
            if (padTech) document.getElementById('sigTechInput').value = padTech.toDataURL();
            if (padClient) document.getElementById('sigClientInput').value = padClient.toDataURL();
        }

        function validateAndSubmit() {
            if (!padClient || !padTech) {
                alert('Erreur: les zones de signature ne sont pas prêtes.');
                return false;
            }

            var fieldsToCheck = [
                { name: 'commentaire_technicien', label: 'Observations du technicien' },
                { name: 'commentaire_client', label: 'Commentaire du client' },
                { name: 'nom_signataire', label: 'Nom du signataire' }
            ];
            var testPatterns = [/test/i, /lorem/i];

            for (var i = 0; i < fieldsToCheck.length; i++) {
                var f = fieldsToCheck[i];
                var el = document.querySelector('[name="' + f.name + '"]');
                var val = el ? el.value : '';
                
                var foundMatch = false;
                for (var j = 0; j < testPatterns.length; j++) {
                    if (testPatterns[j].test(val)) { foundMatch = true; break; }
                }

                if (foundMatch) {
                    if (!confirm("Attention: Le champ '" + f.label + "' contient des données semblant être du test. Continuer ?")) {
                        return false;
                    }
                }
            }

            var elSignataire = document.querySelector('[name="nom_signataire"]');
            var nomSignataire = elSignataire ? elSignataire.value.trim() : '';
            if (!nomSignataire) { alert('Le nom du signataire est obligatoire.'); return false; }

            savePads();
            return true;
        }
    </script>
    
    <!-- BLOC SCRIPT 2: Fonctions de Rapport et IA (Async, etc.) -->
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const chkUnique = document.querySelector('[name="souhait_rapport_unique"]');
            const otherChks = document.querySelectorAll('[name="souhait_offre_pieces"], [name="souhait_pieces_intervention"], [name="souhait_aucune_offre"]');
            if (chkUnique) {
                chkUnique.addEventListener('change', function() { if (this.checked) otherChks.forEach(c => c.checked = false); });
                otherChks.forEach(c => { c.addEventListener('change', function() { if (this.checked) chkUnique.checked = false; }); });
            }
        });

        async function waitForImages(element) {
            const images = element.querySelectorAll('img');
            const promises = Array.from(images).map(img => {
                if (img.complete) return Promise.resolve();
                return new Promise(resolve => { img.onload = resolve; img.onerror = resolve; setTimeout(resolve, 5000); });
            });
            await Promise.race([Promise.all(promises), new Promise(resolve => setTimeout(resolve, 10000))]);
            return new Promise(r => setTimeout(r, 200));
        }

        async function buildFullPdfContainer() {
            const container = document.createElement('div');
            container.id = 'pdf-full-wrapper';
            container.style.width = '210mm';
            container.style.backgroundColor = 'white';
            container.style.color = 'black';

            const styleNode = document.createElement('style');
            styleNode.textContent = `
                .pdf-page { width: 21cm; min-height: 100px; background: white; color: black; padding: 0 15mm; box-sizing: border-box; margin: 0 !important; font-family: Arial, sans-serif; font-size: 13px; position: relative; }
                .pdf-table { width: 100%; border-collapse: collapse; margin-bottom: 20px; color: black; font-size: 11px; table-layout: fixed; }
                .pdf-table th, .pdf-table td { border: 1px solid #000; padding: 4px; vertical-align: middle; word-wrap: break-word; }
                .pdf-table th { background-color: #f0f0f0; }
                .html2pdf__page-break { page-break-before: always; break-before: page; display: block; clear: both; }
            `;
            container.appendChild(styleNode);

            // Fetch data from form
            var nomSociete = document.querySelector('[name="nom_societe_display"]')?.value || window.LM_RAPPORT.nomSociete;
            var numArc = window.LM_RAPPORT.arc;
            var techName = "<?= htmlspecialchars($techName) ?>";
            var dateExp = window.LM_RAPPORT.dateInt;
            
            // Layout simplified here for brevity, keeping main structure
            const rapportCloneWrapper = document.createElement('div');
            rapportCloneWrapper.className = 'pdf-page';
            rapportCloneWrapper.innerHTML = `
                <div style="text-align: center; border: 3px solid #d35400; padding: 15px;">
                    <h1 style="color: #d35400;">RAPPORT D'EXPERTISE SUR SITE</h1>
                    <p><b>Client :</b> ${nomSociete}</p>
                    <p><b>ARC :</b> ${numArc}</p>
                    <p><b>Technicien :</b> ${techName}</p>
                    <p><b>Date :</b> ${dateExp}</p>
                </div>
            `;
            container.appendChild(rapportCloneWrapper);

            // Append machines
            const machineIds = window.LM_RAPPORT.machinesIds || [];
            for (let i = 0; i < machineIds.length; i++) {
                const id = machineIds[i];
                const page = document.createElement('div');
                page.className = 'pdf-page';
                page.style.pageBreakBefore = 'always';
                try {
                    const response = await fetch(`generate_rapport_fiche.php?id=${id}&rapport_id=${window.LM_RAPPORT.rapport_id}`);
                    const html = await response.text();
                    page.innerHTML = html;
                    container.appendChild(page);
                } catch (e) {
                    console.error("Error machine", id, e);
                }
            }
            return container;
        }

        async function telechargerPDF() {
            const btn = document.getElementById('btnDownloadPDF');
            if (btn) btn.disabled = true;
            try {
                const container = await buildFullPdfContainer();
                document.body.appendChild(container); // Needed for dimension calculation
                const opt = {
                    margin: 0,
                    filename: 'Rapport_Expertise_' + window.LM_RAPPORT.arc + '.pdf',
                    image: { type: 'jpeg', quality: 0.98 },
                    html2canvas: { scale: 2, useCORS: true, logging: false },
                    jsPDF: { unit: 'mm', format: 'a4', orientation: 'portrait' }
                };
                await html2pdf().set(opt).from(container).save();
                document.body.removeChild(container);
            } catch (err) {
                console.error("PDF Error", err);
                alert("Erreur lors de la génération du PDF.");
            }
            if (btn) btn.disabled = false;
        }

        async function generateAllIA() {
            const btn = document.getElementById('btnGenerateAllAI');
            if (btn) btn.disabled = true;
            const progress = document.getElementById('iaBatchProgress');
            const bar = document.getElementById('iaBatchProgressBar');
            const status = document.getElementById('iaBatchStatus');
            if (progress) progress.style.display = 'block';

            const ids = window.LM_RAPPORT.machinesIds || [];
            for (let i = 0; i < ids.length; i++) {
                const id = ids[i];
                if (status) status.textContent = `IA : Analyse machine ${i + 1}/${ids.length}...`;
                if (bar) bar.style.width = ((i + 1) / ids.length * 100) + '%';
                try {
                    await fetch(`generate_ia.php?type=E&id=${id}`);
                    await fetch(`generate_ia.php?type=F&id=${id}`);
                } catch (e) {}
            }
            if (status) status.textContent = "Analyse terminée !";
            setTimeout(() => { location.reload(); }, 1500);
        }
    </script>
</body>
</html>
