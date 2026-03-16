
        let padClient, padTech;

        function resizeCanvas(canvas, pad = null) {
            const ratio = Math.max(window.devicePixelRatio || 1, 1);
            const containerWidth = canvas.offsetWidth || canvas.parentElement.offsetWidth || 600;
            
            canvas.width = containerWidth * ratio;
            canvas.height = 200 * ratio;
            canvas.style.height = '200px';
            
            const context = canvas.getContext('2d');
            context.scale(ratio, ratio);
            
            if (pad) {
                pad.clear(); // Réinitialise pour éviter les distorsions si on redimensionne
            }
        }

        let canvasWidthT = 0;
        let canvasWidthC = 0;

        function initSignatures() {
            const canvasC = document.getElementById('canvasClient');
            const canvasT = document.getElementById('canvasTech');
            
            if (!window.SignaturePad) {
                console.error("SignaturePad library not loaded");
                return;
            }
            if (!canvasC || !canvasT) return;

            const dpr = Math.max(window.devicePixelRatio || 1, 1);

            // Tech Pad
            resizeCanvas(canvasT);
            canvasWidthT = canvasT.offsetWidth;
            padTech = new SignaturePad(canvasT, { 
                penColor: 'black',
                throttle: 16,
                minWidth: 1.5,
                maxWidth: 4.5
            });
            if (window.LM_RAPPORT && window.LM_RAPPORT.sigTech) {
                try {
                    padTech.fromDataURL(window.LM_RAPPORT.sigTech, { ratio: dpr, width: canvasT.width / dpr, height: canvasT.height / dpr });
                } catch(e) { console.error("Error loading tech sig:", e); }
            }

            // Client Pad
            resizeCanvas(canvasC);
            canvasWidthC = canvasC.offsetWidth;
            padClient = new SignaturePad(canvasC, { 
                penColor: 'blue',
                throttle: 16,
                minWidth: 1.5,
                maxWidth: 4.5
            });
            if (window.LM_RAPPORT && window.LM_RAPPORT.sigClient) {
                try {
                    padClient.fromDataURL(window.LM_RAPPORT.sigClient, { ratio: dpr, width: canvasC.width / dpr, height: canvasC.height / dpr });
                } catch(e) { console.error("Error loading client sig:", e); }
            }
        }



        window.addEventListener('resize', () => {
            const cT = document.getElementById('canvasTech');
            const cC = document.getElementById('canvasClient');
            
            // Only resize and clear if the actual container width changed (to avoid scroll-resize issues on mobile)
            if (cT && padTech && cT.offsetWidth && cT.offsetWidth !== canvasWidthT) {
                canvasWidthT = cT.offsetWidth;
                resizeCanvas(cT, padTech);
            }
            if (cC && padClient && cC.offsetWidth && cC.offsetWidth !== canvasWidthC) {
                canvasWidthC = cC.offsetWidth;
                resizeCanvas(cC, padClient);
            }
        });

        function validateAndSubmit() {
            if (!padClient || !padTech) {
                alert('Erreur: les zones de signature ne sont pas prêtes. Veuillez rafraîchir la page.');
                return false;
            }

            // --- BUG-005, BUG-014, BUG-015: Contrôle de qualité des textes ---
            const fieldsToCheck = [
                { name: 'commentaire_technicien', label: 'Observations du technicien' },
                { name: 'commentaire_client', label: 'Commentaire du client' },
                { name: 'nom_signataire', label: 'Nom du signataire' }
            ];
            const testPatterns = [/test/i, /lorem/i, /(.)\1{4,}/];
            const forbiddenWords = ['nul', 'rien', 'sans', 'na', 'n/a'];

            for (let f of fieldsToCheck) {
                const val = document.querySelector('[name="' + f.name + '"]')?.value || '';
                if (val.length < 2 && val.length > 0) continue; // Skip very shorts handled elsewhere
                
                let foundMatch = false;
                for (let p of testPatterns) {
                    if (p.test(val)) {
                        foundMatch = true;
                        break;
                    }
                }
                
                if (!foundMatch && forbiddenWords.includes(val.toLowerCase().trim())) {
                    foundMatch = true;
                }

                if (foundMatch) {
                    if (!confirm("⚠️ Le champ '" + f.label + "' contient des données semblant être du test ou non-professionnelles (\"" + val.substring(0, 20) + "...\"). Voulez-vous vraiment continuer ?")) {
                        return false;
                    }
                }
            }

            const nomSignataire = document.querySelector('[name="nom_signataire"]')?.value.trim() || '';
            if (!nomSignataire) {
                alert('Le nom du signataire est obligatoire.');
                return false;
            }

            // --- BUG-008: Contrôle de complétude des fiches ---
            const machinesData = window.LM_RAPPORT.machinesData;
            for (let m of machinesData) {
                if (m.points_count === 0) {
                    alert('❌ Complétude insuffisante : La fiche machine "' + m.designation + '" est entièrement vide (0 point de contrôle rempli). Veuillez la compléter avant de finaliser.');
                    return false;
                }
            }

            const contactNom = document.getElementById('contact_nom')?.value.trim() || '';
            if (!contactNom) {
                alert('Le nom du contact est obligatoire.');
                return false;
            }

            if (padTech.isEmpty()) {
                alert('Veuillez signer en tant que technicien.');
                return false;
            }
            if (padClient.isEmpty()) {
                alert('Veuillez faire signer le client.');
                return false;
            }
            
            document.getElementById('sigTechInput').value = padTech.toDataURL();
            document.getElementById('sigClientInput').value = padClient.toDataURL();
            return true;
        }

        document.addEventListener('DOMContentLoaded', function() {
            // --- BUG-018: Exclusivité des checkboxes "Le client souhaite" ---
            const chkUnique = document.querySelector('[name="souhait_rapport_unique"]');
            const otherChks = document.querySelectorAll('[name="souhait_offre_pieces"], [name="souhait_pieces_intervention"], [name="souhait_aucune_offre"]');
            
            if (chkUnique) {
                chkUnique.addEventListener('change', function() {
                    if (this.checked) {
                        otherChks.forEach(c => c.checked = false);
                    }
                });
                otherChks.forEach(c => {
                    c.addEventListener('change', function() {
                        if (this.checked) {
                            chkUnique.checked = false;
                        }
                    });
                });
            }

            const contactNomInput = document.getElementById('contact_nom');
            const warningEl = document.getElementById('contact_nom_warning');
            
            if (contactNomInput) {
                contactNomInput.addEventListener('input', function() {
                    const val = this.value;
                    // Detect more than 3 consecutive identical characters
                    if (/(.)\1{3,}/.test(val)) {
                        warningEl.style.display = 'block';
                    } else {
                        warningEl.style.display = 'none';
                    }
                });
            }
        });

        // Initialize signatures when layout is completely established to avoid zero-width bugs
        window.addEventListener('load', () => {
            setTimeout(initSignatures, 100);
        });


        // ══════════════════════════════════════════════════════════════════
        // CRÉATION DU CONTENEUR COMPLET POUR LE PDF (ASYNCHRONE)
        // ══════════════════════════════════════════════════════════════════

        // Helper : Attendre que toutes les images soient chargées
        async function waitForImages(element) {
            const images = element.querySelectorAll('img');
            const promises = Array.from(images).map(img => {
                if (img.complete) return Promise.resolve();
                return new Promise(resolve => {
                    img.onload = resolve;
                    img.onerror = resolve;
                });
            });
            await Promise.all(promises);
            return new Promise(r => setTimeout(r, 200));
        }

        // helper: create footer
        function createPdfFooter() {
            const f = document.createElement('div');
            const leg = window.LM_RAPPORT.legal;
            f.style.marginTop = '30px';
            f.style.width = '100%';
            f.style.textAlign = 'center';
            f.style.fontSize = '9px';
            f.style.fontWeight = 'bold';
            f.style.borderTop = '2px solid #000';
            f.style.paddingTop = '5px';
            f.innerHTML = `${leg.address}<br>${leg.contact}<br>${leg.siret}`;
            return f;
        }

        async function buildFullPdfContainer() {
            const container = document.createElement('div');
            container.id = 'pdf-full-wrapper';
            container.style.width = '210mm';
            container.style.backgroundColor = 'white';
            container.style.color = 'black';

            // --- 0. STYLES SPÉCIFIQUES ---
            const styleNode = document.createElement('style');
            styleNode.textContent = `
                .pdf-page {
                    width: 21cm;
                    min-height: 100px; 
                    background: white;
                    color: black;
                    padding: 10mm 15mm 25mm 15mm;
                    box-sizing: border-box;
                    margin: 0;
                    font-family: Arial, sans-serif;
                    font-size: 13px;
                    position: relative; 
                }
                .html2pdf__page-break {
                    height: 1px;
                    width: 100%;
                    overflow: hidden;
                    page-break-before: always !important;
                    break-before: page !important;
                    display: block;
                    clear: both;
                }
                .pdf-table-container, .card, .sig-zone, .photo-annexe-item {
                    page-break-inside: avoid !important;
                }
                .pdf-table, table.controles { 
                    page-break-inside: auto !important; 
                }
                .pdf-table tr, table.controles tr { 
                    page-break-inside: avoid !important; 
                    page-break-after: auto !important;
                }
                .pdf-section {
                    margin-bottom: 15px;
                }
                .pdf-table { width: 100%; border-collapse: collapse; margin-bottom: 20px; color: black; font-size: 11px; }
                .pdf-table th, .pdf-table td { border: 1px solid #000; padding: 4px; vertical-align: middle; }
                .pdf-table th { background-color: #f0f0f0; text-align: left; text-transform: uppercase; }
                
                .pastille-group { display: inline-flex; gap: 4px; align-items: center; }
                .pastille-group label {
                    display: flex; align-items: center; justify-content: center;
                    width: 18px; height: 18px; border-radius: 50%;
                    border: 1px solid #ccc !important; background: transparent !important; font-size: 0; position: relative;
                }
                /* Only the selected label gets a background color */
                .pastille-group label.selected.p-na { background: #bbb !important; border-color: #999 !important; opacity: 1; }
                .pastille-group label.selected.p-ok { background: #28a745 !important; border-color: #1e7e34 !important; opacity: 1; }
                .pastille-group label.selected.p-aa { background: #e67e22 !important; border-color: #d35400 !important; opacity: 1; }
                .pastille-group label.selected.p-nc { background: #dc3545 !important; border-color: #bd2130 !important; opacity: 1; }
                .pastille-group label.selected.p-nr { background: #8b0000 !important; border-color: #5a0000 !important; opacity: 1; }
                /* Non selected labels are subtle empty circles */
                .pastille-group label:not(.selected) { opacity: 0.3; border: 1px solid #ccc !important; }

                .pdf-input { border: none; border-bottom: 1px dashed #000; background: transparent; font-size: 13px; font-family: Arial; padding: 2px; width: 100%; color: black; outline:none; }
                .pdf-textarea-rendered { 
                    width: 100%; font-family: Arial; font-size: 9pt; color: black; white-space: pre-wrap; word-wrap: break-word; padding:4px; box-sizing: border-box;
                }
                .no-print-pdf { display: none !important; }
                
                .photo-annexe-item { text-align: center; max-width: 200px; margin-bottom: 10px; }
                .photo-annexe-item img { width: 180px; height: 135px; object-fit: cover; border: 1px solid #000; }
                .photo-annexe-item p { font-size: 8pt; margin: 3px 0 0 0; color: #000; line-height: 1.2; }

                img { max-width: 100%; }
            `;
            container.appendChild(styleNode);

            // Fetch data from form for Page 1
            const numArc = window.LM_RAPPORT.arc;
            const nomSociete = document.querySelector('[name="nom_societe_display"]')?.value || window.LM_RAPPORT.nomSociete;
            const adresse = document.querySelector('[name="adresse"]')?.value || '';
            const cp = document.querySelector('[name="code_postal"]')?.value || '';
            const ville = document.querySelector('[name="ville"]')?.value || '';
            const pays = document.querySelector('[name="pays"]')?.value || '';
            
            const contactNom = document.querySelector('[name="contact_nom"]')?.value || '';
            const contactFonction = document.querySelector('[name="contact_fonction"]')?.value || '';
            const contactTel = document.querySelector('[name="contact_telephone"]')?.value || '';
            const contactEmail = document.querySelector('[name="contact_email"]')?.value || '';
            
            const nomSignataire = document.querySelector('[name="nom_signataire"]')?.value || '_____';
            
            const commentaire = document.querySelector('[name="commentaire_technicien"]')?.value || '';
            
            const techName = "<?= htmlspecialchars($techName) ?>";
            const dateExp = window.LM_RAPPORT.dateInt;
            const sigTechData = window.LM_RAPPORT.sigTech || document.getElementById('canvasTech')?.toDataURL() || '';
            const sigClientData = window.LM_RAPPORT.sigClient || document.getElementById('canvasClient')?.toDataURL() || '';

            // Generate HTML lines for machines
            const machinesTrs = window.LM_RAPPORT.machinesData.map(m => `
                <tr style="border-bottom:1px solid #000;">
                    <td style="padding:6px; border-right:1px solid #000; text-align:center;">${m.arc || '—'}</td>
                    <td style="padding:6px; border-right:1px solid #000; text-align:center;">${m.of || '—'}</td>
                    <td style="padding:6px; border-right:1px solid #000;">${m.designation || '—'}</td>
                    <td style="padding:6px; text-align:center;">${m.annee || '—'}</td>
                </tr>
            `).join('');

            // --- 1. PAGE RAPPORT FINAL (COUVERTURE + INFOS) ---
            const rapportCloneWrapper = document.createElement('div');
            rapportCloneWrapper.className = 'pdf-page';
            // Layout exact as requested
            rapportCloneWrapper.innerHTML = `
                <!-- HEADER -->
                <div style="border: 2px solid #000; margin-bottom: 20px;">
                    <div style="display:flex; padding:15px; align-items:center;">
                        <div style="flex:1;">
                            <img src="/assets/lenoir_logo_doc.png" style="height:60px;">
                            <div style="font-weight:bold; font-size:16px; margin-top:5px; color:#1B4F72;">MAGNETIC SYSTEMS</div>
                        </div>
                        <div style="flex:2; text-align:right; color:#1B4F72; font-weight:bold; font-size:13px; line-height:1.4;">
                            Le spécialiste des applications<br>
                            magnétiques pour la séparation<br>
                            et le levage industriel<br>
                            <br>
                            <span style="font-size:16px; text-decoration:underline;">LISTING DES EXPERTISES</span>
                        </div>
                    </div>
                    <div style="background-color:#5B9BD5; color:white; text-align:center; padding:15px; border-top:2px solid #000;">
                        <h1 style="margin:0; font-size:20px; text-transform:uppercase; letter-spacing:1px;">RAPPORT D'EXPERTISE SUR SITE</h1>
                        <div style="font-size:16px; font-weight:bold; margin-top:8px;">N° ARC : ${numArc}</div>
                    </div>
                </div>

                <!-- COORDONNEES DU CLIENT -->
                <div style="background-color:#1B4F72; color:white; padding:6px 15px; font-weight:bold; border:2px solid #000; border-bottom:none; text-transform:uppercase; font-size:12px;">
                    COORDONNÉES DU CLIENT
                </div>
                <table style="width:100%; border-collapse:collapse; border:2px solid #000; margin-bottom:15px; font-size:12px;">
                    <tr>
                        <td style="width:50%; padding:10px; border-right:1px solid #000; vertical-align:top;">
                            <div style="margin-bottom:8px; font-weight:bold; font-size:13px; text-decoration:underline;">SOCIÉTÉ</div>
                            <table style="width:100%">
                                <tr><td style="width:80px; padding-bottom:3px;">Nom:</td><td style="font-weight:bold; padding-bottom:3px;">${nomSociete || '_____'}</td></tr>
                                <tr><td style="padding-bottom:3px;">Adresse:</td><td style="padding-bottom:3px;">${adresse || '_____'}</td></tr>
                                <tr><td style="padding-bottom:3px;">CP:</td><td style="padding-bottom:3px;">${cp || '_____'}</td></tr>
                                <tr><td style="padding-bottom:3px;">Ville:</td><td style="padding-bottom:3px;">${ville || '_____'}</td></tr>
                                <tr><td style="padding-bottom:3px;">Pays:</td><td style="padding-bottom:3px;">${pays || 'France'}</td></tr>
                            </table>
                        </td>
                        <td style="width:50%; padding:10px; vertical-align:top;">
                            <div style="margin-bottom:8px; font-weight:bold; font-size:13px; text-decoration:underline;">CONTACT SUR SITE</div>
                            <table style="width:100%">
                                <tr><td style="width:90px; padding-bottom:3px;">Nom complet:</td><td style="font-weight:bold; padding-bottom:3px;">${contactNom || '_____'}</td></tr>
                                <tr><td style="padding-bottom:3px;">Fonction:</td><td style="padding-bottom:3px;">${contactFonction || '_____'}</td></tr>
                                <tr><td style="padding-bottom:3px;">Téléphone:</td><td style="padding-bottom:3px;">${contactTel || '_____'}</td></tr>
                                <tr><td style="padding-bottom:3px;">Courriel:</td><td style="padding-bottom:3px;">${contactEmail || '_____'}</td></tr>
                            </table>
                        </td>
                    </tr>
                </table>

                <!-- PARC MACHINE -->
                <div style="background-color:#1B4F72; color:white; padding:6px 15px; font-weight:bold; border:2px solid #000; border-bottom:none; text-transform:uppercase; font-size:12px;">
                    PARC MACHINE
                </div>
                <table style="width:100%; border-collapse:collapse; border:2px solid #000; margin-bottom:15px; font-size:11px; text-align:left;">
                    <thead>
                        <tr style="border-bottom:2px solid #000; background-color:#f8fafc;">
                            <th style="padding:6px; border-right:1px solid #000; width:15%; text-align:center;">N° A.R.C</th>
                            <th style="padding:6px; border-right:1px solid #000; width:15%; text-align:center;">N° O.F.</th>
                            <th style="padding:6px; border-right:1px solid #000; width:55%;">Désignation du matériel</th>
                            <th style="padding:6px; width:15%; text-align:center;">Année</th>
                        </tr>
                    </thead>
                    <tbody>
                        ${machinesTrs}
                    </tbody>
                </table>

                <!-- COMMENTAIRE TECHNICIEN -->
                ${commentaire ? `
                <div style="border:2px solid #000; margin-bottom:15px;">
                    <div style="background-color:#f8fafc; border-bottom:1px solid #000; padding:6px 15px; font-weight:bold; font-size:12px;">
                        Commentaire :
                    </div>
                    <div style="padding:10px; min-height:80px; font-size:12px; white-space:pre-wrap;">${commentaire}</div>
                </div>` : ''}

                <!-- SIGNATURES -->
                <table style="width:100%; border-collapse:collapse; border:2px solid #000; font-size:12px; margin-bottom: 20px;">
                    <tr>
                        <td style="width:50%; padding:10px; border-right:1px solid #000; vertical-align:top;">
                            <div style="margin-bottom:8px;"><strong>Technicien sur site :</strong> ${techName}</div>
                            <div><strong>Date d'expertise :</strong> ${dateExp}</div>
                            <div style="text-align: center; margin-top: 10px;">
                                <img src="${sigTechData}" style="max-height:60px; max-width:90%; border:1px dashed #ccc; padding:3px; background:white;">
                            </div>
                        </td>
                        <td style="width:50%; padding:10px; text-align:center; vertical-align:middle;">
                            <div style="margin-bottom:8px;"><strong>Client :</strong> ${nomSignataire}</div>
                            <img src="${sigClientData}" style="max-height:60px; max-width:90%; border:1px dashed #ccc; padding:3px; background:white;">
                        </td>
                    </tr>
                </table>
            `;
            rapportCloneWrapper.appendChild(createPdfFooter());
            container.appendChild(rapportCloneWrapper);

            // --- 1.2 PAGE SYNTHÈSE + PRÉAMBULE (FUSIONNÉS POUR ÉCONOMISER DES PAGES) ---
            const synthPreambulePage = document.createElement('div');
            synthPreambulePage.className = 'pdf-page';
            const s = window.LM_RAPPORT.synth;
            
            // Calcul mois prochain pour le préambule
            let moisProchainText = "";
            let villePreambule = ville || "[VILLE DU CLIENT]";
            if (dateExp && dateExp.includes('/')) {
                const parts = dateExp.split('/');
                if (parts.length === 3) {
                    const mIndex = parseInt(parts[1], 10) - 1;
                    const y = parseInt(parts[2], 10) + 1;
                    const moisNoms = ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];
                    if (mIndex >= 0 && mIndex < 12) {
                        moisProchainText = moisNoms[mIndex] + ' ' + y;
                    }
                }
            }
            if (!moisProchainText) moisProchainText = "[MOIS PROCHAIN]";

            synthPreambulePage.innerHTML = `
                <div style="padding-top: 10px;">
                    <div style="border: 2px solid #000; padding: 20px; color: #000; background: #fff; margin-bottom: 30px; page-break-inside: avoid;">
                        <h2 style="text-align: center; margin-top: 0; margin-bottom: 20px; text-decoration: underline; font-size: 16px; text-transform: uppercase;">SYNTHÈSE DE L'INTERVENTION</h2>
                        
                        <div style="margin-bottom: 15px; font-size: 13px; line-height: 1.6;">
                            <div><strong>Technicien :</strong> ${s.tech}</div>
                            <div><strong>Date :</strong> ${s.date}</div>
                            <div><strong>Durée totale :</strong> ${s.duree}</div>
                            <div><strong>Équipements contrôlés :</strong> ${s.nbMachines}</div>
                        </div>

                        <div style="margin: 20px 0; font-size: 13px;">
                            <div style="display: flex; align-items: center; margin-bottom: 8px;">
                                <span style="display: inline-block; width: 12px; height: 12px; background: #28a745; margin-right: 10px;"></span>
                                <strong>${s.ok} points conformes</strong>
                            </div>
                            <div style="display: flex; align-items: center; margin-bottom: 8px;">
                                <span style="display: inline-block; width: 12px; height: 12px; background: #e67e22; margin-right: 10px;"></span>
                                <strong>${s.aa} points à améliorer</strong>
                            </div>
                            <div style="display: flex; align-items: center; margin-bottom: 8px;">
                                <span style="display: inline-block; width: 12px; height: 12px; background: #dc3545; margin-right: 10px;"></span>
                                <strong>${s.nc} point${s.nc > 1 ? 's' : ''} non conforme${s.nc > 1 ? 's' : ''}</strong>
                            </div>
                            <div style="display: flex; align-items: center; margin-bottom: 8px;">
                                <span style="display: inline-block; width: 12px; height: 12px; background: #8b0000; margin-right: 10px;"></span>
                                <strong>${s.nr} remplacement${s.nr > 1 ? 's' : ''} nécessaire${s.nr > 1 ? 's' : ''}</strong>
                            </div>
                        </div>

                        <div style="margin-top: 25px; text-align: center;">
                            <div style="font-weight: bold; font-size: 14px; margin-bottom: 5px; text-transform: uppercase;">SCORE DE CONFORMITÉ : ${s.score}%</div>
                            ${s.nbMachinesEmpty > 0 ? `<div style="font-size: 11px; color: #dc3545; font-weight: bold; margin-bottom: 8px;">⚠️ ${s.nbMachinesEmpty} fiche(s) non remplie(s) — score calculé sur ${s.nbMachinesFilled}/${s.nbMachinesFilled + s.nbMachinesEmpty} fiches uniquement</div>` : ''}
                            <div style="width: 100%; height: 20px; background: #e2e8f0; border: 1px solid #000; position: relative; overflow: hidden; border-radius: 4px;">
                                <div style="width: ${s.score}%; height: 100%; background: ${s.score < 33 ? '#dc3545' : (s.score < 66 ? '#f59e0b' : '#22c55e')}; transition: width 0.5s;"></div>
                            </div>
                        </div>
                    </div>

                    <div style="font-size: 13px; line-height: 1.5; color: black; font-family: Arial, sans-serif; page-break-inside: avoid;">
                        <h2 style="color: #f97316; text-decoration: underline; font-size: 16px; text-transform: uppercase; margin-bottom: 15px;">PRÉAMBULE :</h2>
                        
                        <p style="margin-bottom: 12px;">
                            Ce rapport est établi suite à une expertise effectuée le ${dateExp} sur votre site de ${villePreambule}.
                        </p>
                        
                        <p style="margin-bottom: 12px;">
                            Nos expertises permettent de vous accompagner dans votre démarche ISO 22000 :2005 et HACCP. Notre analyse est suivie de conclusions ou recommandations que nous vous invitons à suivre pour la pérennité et la qualité de votre production.
                        </p>
                        
                        <p style="margin-bottom: 12px;">
                            Dans le cadre de notre prestation annuelle, la prochaine expertise aura lieu en ${moisProchainText}. Nous vous contacterons pour établir une date appropriée à vos impératifs de production.
                        </p>
                    </div>
                </div>
            `;
            synthPreambulePage.appendChild(createPdfFooter());
            container.appendChild(synthPreambulePage);

            // --- 2. FETCH & APPEND MACHINES ---
            if (window.LM_RAPPORT && window.LM_RAPPORT.machinesIds) {
                const totalMachines = window.LM_RAPPORT.machinesIds.length;
                for (let mIdx = 0; mIdx < totalMachines; mIdx++) {
                    const mId = window.LM_RAPPORT.machinesIds[mIdx];
                    try {
                        const res = await fetch('machine_edit.php?id=' + mId);
                        const html = await res.text();
                        const parser = new DOMParser();
                        const doc = parser.parseFromString(html, 'text/html');

                        const pages = doc.querySelectorAll('.pdf-page');
                        pages.forEach((p, pIdx) => {
                            // Bug 5 & New Fix: Remove empty photos section
                            const hasPhotos = p.querySelectorAll('.photo-annexe-item img').length > 0;
                            p.querySelectorAll('.photos-annexes-wrapper').forEach(wrapper => {
                                if (!wrapper.querySelector('.photo-annexe-item')) {
                                    wrapper.remove();
                                }
                            });

                            // Bug 1 & 2 & 3: Clean up machine fiche
                            p.querySelectorAll('.photo-btn, .photo-thumbs, #btnChrono, .no-print-pdf').forEach(el => el.remove());
                            
                            // If it's a diagram/photo page and it's empty after cleanup, skip it
                            const contentText = p.textContent.trim();
                            if ((pIdx > 0) && contentText.length < 50 && !hasPhotos && !p.querySelector('img')) {
                                return; // Skip empty pages
                            }

                            // Chaque machine commence sur une nouvelle page
                            if (pIdx === 0) {
                                const forcedBreak = document.createElement('div');
                                forcedBreak.className = 'html2pdf__page-break';
                                container.appendChild(forcedBreak);
                                p.style.marginTop = '0';
                            }

                            if (pIdx === 0) {
                                p.style.paddingTop = '0';
                                const hDiv = document.createElement('div');
                                hDiv.style.display = 'flex';
                                hDiv.style.justifyContent = 'space-between';
                                hDiv.style.alignItems = 'center';
                                hDiv.style.marginBottom = '20px';
                                hDiv.style.borderBottom = '3px solid #5B9BD5';
                                hDiv.style.paddingBottom = '5px';
                                hDiv.innerHTML = `
                                    <div style="font-size: 14px; font-weight: bold; color: #1B4F72;">FICHE ${mIdx + 1} / ${totalMachines}</div>
                                    <img src="/assets/lenoir_logo_doc.png" style="height: 45px;">
                                `;
                                p.insertBefore(hDiv, p.firstChild);
                            }

                            p.querySelectorAll('input[type="radio"]:checked').forEach(r => {
                                const lbl = r.closest('label');
                                if (lbl) lbl.classList.add('selected');
                            });

                            p.querySelectorAll('input[type="text"], input[type="time"]').forEach(inp => {
                                let val = inp.value || '';
                                // Bug 4: Handle "Poste"
                                if (inp.name === 'mesures[poste]') {
                                    val = val ? val : 'N/A';
                                } else if (!val) {
                                    val = '_____';
                                }
                                
                                inp.outerHTML = `<span style="border-bottom:1px dashed black; display:inline-block; min-width:30px; padding:0 3px; font-weight:bold;">${val}</span>`;
                            });

                            p.querySelectorAll('textarea').forEach(ta => {
                                let val = ta.value || ta.innerHTML;
                                
                                // NEW FIX FOR PERFORMANCE / NON REALISE Bug:
                                const specialKeys = ['aprf_attraction_comment', 'ov_perf_bille', 'ov_perf_ecrou', 'ov_perf_rond50', 'ov_perf_rond100', 'levage_charge_maxi_comment', 'levage_temp_maxi_comment'];
                                if (specialKeys.some(k => ta.name && ta.name.includes(k))) {
                                    if (!val.trim()) val = "Non réalisé";
                                }

                                if (val.trim()) {
                                    const div = document.createElement('div');
                                    div.className = 'pdf-textarea-rendered';
                                    div.style.minHeight = '15px';
                                    div.textContent = val;
                                    ta.parentNode.insertBefore(div, ta);
                                }
                                ta.remove();
                            });
                            
                            p.style.position = 'relative';
                            p.style.paddingBottom = '30mm';
                            p.style.minHeight = 'auto'; // Help chaining
                            p.appendChild(createPdfFooter());
                            container.appendChild(p);
                        });
                    } catch (err) {
                        console.error('Erreur fetch machine ' + mId, err);
                    }
                }
            }

            // Page de fin : On ne force plus systématiquement le saut de page
            // si le contenu précédent est court. On laisse html2pdf gérer ou on met un petit espacement.
            const pbFin = document.createElement('div');
            pbFin.style.height = '20px';
            container.appendChild(pbFin);

            // --- 4. PAGE DE FIN (STRUCTURE LENOIR-MEC + SIGNATURES + OBSERVATIONS) ---

            const endPage = document.createElement('div');
            endPage.className = 'pdf-page';
            endPage.style.padding = '10mm 15mm 15mm 15mm';
            endPage.style.position = 'relative';

            const originalRapport = document.getElementById('rapportForm');
            const souhaitRapport = originalRapport.querySelector('[name="souhait_rapport_unique"]').checked;
            const souhaitPieces = originalRapport.querySelector('[name="souhait_offre_pieces"]').checked;
            const souhaitIntervention = originalRapport.querySelector('[name="souhait_pieces_intervention"]').checked;
            const souhaitAucune = originalRapport.querySelector('[name="souhait_aucune_offre"]').checked;
            const nomSignataire = originalRapport.querySelector('[name="nom_signataire"]').value || '_____';
            const techNameLabel = "<?= htmlspecialchars($techName) ?>";
            const dateStr = window.LM_RAPPORT.dateInt;

            const commentaryTech = originalRapport.querySelector('[name="commentaire_technicien"]')?.value || '';
            const commentaryClient = originalRapport.querySelector('[name="commentaire_client"]')?.value || '';

            const sigTechImg = window.LM_RAPPORT.sigTech || document.getElementById('canvasTech')?.toDataURL() || '';
            const sigClientImg = window.LM_RAPPORT.sigClient || document.getElementById('canvasClient')?.toDataURL() || '';

            endPage.innerHTML = `
                <div style="font-family: Arial, sans-serif; font-size: 11px; color: #000;">
                    
                    <!-- OBSERVATIONS GÉNÉRALES -->
                    <div style="background-color: #1B4F72; color: white; border: 2px solid #000; border-bottom: none; padding: 4px 15px; font-weight: bold; font-size: 11px; text-transform: uppercase;">OBSERVATIONS DU TECHNICIEN</div>
                    <div style="border: 2px solid #000; padding: 8px; min-height: 60px; font-size: 11px; white-space: pre-wrap; margin-bottom: 10px;">${commentaryTech}</div>

                    <div style="background-color: #1B4F72; color: white; border: 2px solid #000; border-bottom: none; padding: 4px 15px; font-weight: bold; font-size: 11px; text-transform: uppercase;">COMMENTAIRE DU CLIENT</div>
                    <div style="border: 2px solid #000; padding: 8px; font-size: 11px; white-space: pre-wrap; margin-bottom: 10px;">${commentaryClient}</div>

                    <!-- LE CLIENT SOUHAITE -->
                    <div style="background-color: #1B4F72; color: white; border: 2px solid #000; border-bottom: none; padding: 4px 15px; font-weight: bold; font-size: 11px; text-transform: uppercase;">LE CLIENT SOUHAITE</div>
                    <div style="border: 2px solid #000; padding: 8px; margin-bottom: 10px;">
                        <div style="margin-bottom: 3px; font-size: 11px;">${souhaitRapport ? '☑' : '☐'} Ce Rapport d\'expertise uniquement</div>
                        <div style="margin-bottom: 3px; font-size: 11px;">${souhaitPieces ? '☑' : '☐'} Une offre de Pièces de Rechange</div>
                        <div style="margin-bottom: 3px; font-size: 11px;">${souhaitIntervention ? '☑' : '☐'} Une offre de PR + intervention mise en place</div>
                        <div style="font-size: 11px;">${souhaitAucune ? '☑' : '☐'} Aucune offre</div>
                    </div>

                    <!-- DATE ET HEURE -->
                    <div style="background-color: #1B4F72; color: white; border: 2px solid #000; border-bottom: none; padding: 4px 15px; font-weight: bold; font-size: 11px; text-transform: uppercase;">DATE ET HEURE</div>
                    <div style="border: 2px solid #000; padding: 8px; margin-bottom: 10px; font-size: 13px; font-weight: bold; text-align: center;">
                        Fait le ${dateStr}
                    </div>

                    <!-- SIGNATURES -->
                    <div style="background-color: #1B4F72; color: white; border: 2px solid #000; border-bottom: none; padding: 4px 15px; font-weight: bold; font-size: 11px; text-transform: uppercase;">SIGNATURES</div>
                    <table style="width: 100%; border-collapse: collapse; table-layout: fixed; border: 2px solid #000; margin-bottom: 15px;">
                        <tr style="height: 120px;">
                            <td style="border: 1px solid #000; padding: 8px; vertical-align: top; width: 50%;">
                                <div style="font-weight: bold; text-decoration: underline; margin-bottom: 5px;">Contrôleur (NOM Prénom) :</div>
                                <div style="margin-bottom: 10px;"><strong>${techNameLabel}</strong></div>
                                <div style="text-align: center;">
                                    <img src="${sigTechImg}" style="max-height: 80px; max-width: 90%; object-fit: contain; background: white;">
                                </div>
                            </td>
                            <td style="border: 1px solid #000; padding: 8px; vertical-align: top; width: 50%;">
                                <div style="font-weight: bold; text-decoration: underline; margin-bottom: 5px;">Client (NOM Prénom) :</div>
                                <div style="margin-bottom: 10px;"><strong>${nomSignataire}</strong></div>
                                <div style="text-align: center;">
                                    <img src="${sigClientImg}" style="max-height: 80px; max-width: 90%; object-fit: contain; background: white;">
                                </div>
                            </td>
                        </tr>
                    </table>

                    <!-- CONTACTS ORANGE -->
                    <div style="border: 2px solid #000; padding: 0; text-align: center; margin-bottom: 15px;">
                        <div style="background-color: #E67E22; color: white; padding: 4px 15px; font-weight: bold; border-bottom: 2px solid #000; font-size: 10px;">POUR TOUTE INFORMATION TECHNIQUE SUR CE RAPPORT</div>
                        <div style="background-color: #fff; padding: 6px; border-bottom: 2px solid #000;">
                            <div style="font-size: 12px;">➤ <strong>Soufyane SALAH</strong> &nbsp;&nbsp;&nbsp; <span style="font-style: italic;">Chargé d'Affaires</span></div>
                        </div>
                        
                        <div style="background-color: #E67E22; color: white; padding: 4px 15px; font-weight: bold; border-bottom: 2px solid #000; font-size: 10px;">POUR LA PLANIFICATION D'UNE VÉRIFICATION PÉRIODIQUE</div>
                        <div style="background-color: #fff; padding: 6px;">
                            <div style="font-size: 12px;">➤ <strong>Sophie NIAY</strong> &nbsp;&nbsp;&nbsp; <span style="font-style: italic;">Responsable Service Clients</span></div>
                        </div>
                    </div>

                    <!-- FOOTER SECTION WITH QR CODE -->
                    <div style="text-align: center; color: #1B4F72; margin-top: 5px;">
                        <div style="font-weight: bold; margin-bottom: 8px;">UNE SEULE ADRESSE COMMUNE : contact@raoul-lenoir.com</div>
                        
                        <div style="margin-top: 5px;">
                            <div style="font-size: 10px; font-weight: bold; margin-bottom: 3px;">Visitez notre site !</div>
                            <img src="https://api.qrserver.com/v1/create-qr-code/?size=150x150&data=https://www.lenoir-mec.com" crossorigin="anonymous" style="width: 100px; height: 100px; display: block; margin: 0 auto;">
                            <div style="font-weight: bold; font-size: 10px; margin-top: 5px;">www.raoul-lenoir.com</div>
                        </div>
                    </div>
                </div>
            `;
            endPage.appendChild(createPdfFooter());
            container.appendChild(endPage);

            await waitForImages(container);
            return container;
        }

        // ══════════════════════════════════════════════════════════════════
        // GÉNÉRATION PDF (html2pdf.js)
        // ══════════════════════════════════════════════════════════════════
        async function genererPDFBase64() {
            if (!window.html2pdf) throw new Error('html2pdf.js non disponible');

            const container = await buildFullPdfContainer();

            const opt = {
                margin: 0,
                filename: window.LM_RAPPORT ? window.LM_RAPPORT.pdfFilename : 'rapport.pdf',
                image: { type: 'jpeg', quality: 0.98 },
                html2canvas: { scale: 2, useCORS: true, logging: false },
                jsPDF: { unit: 'mm', format: 'a4', orientation: 'portrait' }
            };

            const worker = html2pdf().set(opt).from(container);
            const pdfBlob = await worker.outputPdf('blob');

            return new Promise((resolve, reject) => {
                const reader = new FileReader();
                reader.onload = () => resolve(reader.result.split(',')[1]);
                reader.onerror = reject;
                reader.readAsDataURL(pdfBlob);
            });
        }

        // ══════════════════════════════════════════════════════════════════
        // TÉLÉCHARGEMENT PDF BOUTON DIRECT
        // ══════════════════════════════════════════════════════════════════
        async function telechargerPDF() {
            const btn = document.getElementById('btnDownloadPDF');
            if (btn) { btn.disabled = true; btn.textContent = '⏳ Génération du rapport complet...'; }
            try {
                const container = await buildFullPdfContainer();

                const opt = {
                    margin: 0,
                    filename: window.LM_RAPPORT ? window.LM_RAPPORT.pdfFilename : 'rapport.pdf',
                    image: { type: 'jpeg', quality: 0.98 },
                    html2canvas: { scale: 2, useCORS: true, logging: false },
                    jsPDF: { unit: 'mm', format: 'a4', orientation: 'portrait' }
                };

                await html2pdf().set(opt).from(container).save();
            } catch (e) {
                alert('Erreur génération PDF : ' + e.message);
            } finally {
                if (btn) { btn.disabled = false; btn.textContent = '⬇️ Télécharger le PDF'; }
            }
        }

        // ══════════════════════════════════════════════════════════════════
        // TOAST UI
        // ══════════════════════════════════════════════════════════════════
        function afficherToast(message, type = 'success') {
            const toast = document.getElementById('emailToast');
            if (!toast) return;
            toast.textContent = message;
            if (type === 'success') {
                toast.style.background = 'rgba(16,185,129,0.2)';
                toast.style.border = '1px solid rgba(16,185,129,0.5)';
                toast.style.color = '#10b981';
            } else if (type === 'warning') {
                toast.style.background = 'rgba(245,158,11,0.2)';
                toast.style.border = '1px solid rgba(245,158,11,0.5)';
                toast.style.color = '#f59e0b';
            } else {
                toast.style.background = 'rgba(244,63,94,0.2)';
                toast.style.border = '1px solid rgba(244,63,94,0.5)';
                toast.style.color = '#f43f5e';
            }
            toast.style.display = 'block';
            // Scroll vers le toast
            toast.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }

        // ══════════════════════════════════════════════════════════════════
        // FILE D'ATTENTE HORS-LIGNE (IndexedDB)
        // ══════════════════════════════════════════════════════════════════
        const DB_NAME = 'LMEmailQueue';
        const DB_VERSION = 1;
        const STORE_NAME = 'pendingEmails';

        function ouvrirIDB() {
            return new Promise((resolve, reject) => {
                const req = indexedDB.open(DB_NAME, DB_VERSION);
                req.onupgradeneeded = e => {
                    e.target.result.createObjectStore(STORE_NAME, { keyPath: 'id', autoIncrement: true });
                };
                req.onsuccess = e => resolve(e.target.result);
                req.onerror = e => reject(e.target.error);
            });
        }

        async function sauvegarderEnFile(payload) {
            const db = await ouvrirIDB();
            const tx = db.transaction(STORE_NAME, 'readwrite');
            const store = tx.objectStore(STORE_NAME);
            store.add({ ...payload, queued_at: Date.now() });
            return new Promise((res, rej) => {
                tx.oncomplete = res;
                tx.onerror = rej;
            });
        }

        async function rejouerFileDAttente() {
            const db = await ouvrirIDB();
            const tx = db.transaction(STORE_NAME, 'readwrite');
            const store = tx.objectStore(STORE_NAME);
            const req = store.getAll();
            req.onsuccess = async () => {
                const items = req.result;
                for (const item of items) {
                    try {
                        const res = await envoyerParAPI(item.intervention_id, item.pdf_data, item.client_email, item.csrf_token);
                        if (res.success) {
                            // Supprimer de la file
                            db.transaction(STORE_NAME, 'readwrite').objectStore(STORE_NAME).delete(item.id);
                            console.log('[LM] Email rejoué avec succès :', item.client_email);
                        }
                    } catch (e) {
                        console.warn('[LM] Rejouer échoué :', e);
                    }
                }
            };
        }

        // Écouter la reconnexion réseau
        window.addEventListener('online', () => {
            console.log('[LM] Connexion rétablie – rejouer la file d\'attente email');
            rejouerFileDAttente();
        });

        // ══════════════════════════════════════════════════════════════════
        // APPEL API ENVOI EMAIL
        // ══════════════════════════════════════════════════════════════════
        async function envoyerParAPI(interventionId, pdfBase64, clientEmail, csrfToken) {
            const formData = new FormData();
            formData.append('intervention_id', interventionId);
            formData.append('pdf_data', pdfBase64);
            formData.append('client_email', clientEmail);
            formData.append('csrf_token', csrfToken);

            const resp = await fetch('/envoyer_rapport.php', {
                method: 'POST',
                body: formData,
                credentials: 'same-origin',
            });
            if (!resp.ok) throw new Error('HTTP ' + resp.status);
            return resp.json();
        }

        // ══════════════════════════════════════════════════════════════════
        // FONCTION PRINCIPALE : LANCER L'ENVOI EMAIL
        // ══════════════════════════════════════════════════════════════════
        async function lancerEnvoiEmail(auto = false) {
            if (!window.LM_RAPPORT) return;

            const { interventionId, clientEmail, csrfToken, nomSociete } = window.LM_RAPPORT;

            if (!clientEmail) {
                afficherToast('⚠️ Aucun email client renseigné. Veuillez reprendre le formulaire.', 'error');
                return;
            }

            const btn = document.getElementById('btnSendEmail');
            const icon = document.getElementById('btnSendEmailIcon');
            const label = document.getElementById('btnSendEmailLabel');

            if (btn) btn.disabled = true;
            if (icon) icon.textContent = '⏳';
            if (label) label.textContent = 'Génération du PDF…';

            let pdfBase64;
            try {
                pdfBase64 = await genererPDFBase64();
            } catch (e) {
                if (btn) btn.disabled = false;
                if (icon) icon.textContent = '📧';
                if (label) label.textContent = 'Envoyer PDF par email';
                afficherToast('❌ Erreur génération PDF : ' + e.message, 'error');
                return;
            }

            if (icon) icon.textContent = '📤';
            if (label) label.textContent = 'Envoi en cours…';

            // Hors-ligne : mettre en file d'attente
            if (!navigator.onLine) {
                try {
                    await sauvegarderEnFile({
                        intervention_id: interventionId,
                        pdf_data: pdfBase64,
                        client_email: clientEmail,
                        csrf_token: csrfToken,
                    });
                    afficherToast('📶 Hors-ligne – email mis en file d\'attente. Il sera envoyé automatiquement à la reconnexion.', 'warning');
                } catch (e) {
                    afficherToast('❌ Impossible de mettre l\'email en file d\'attente.', 'error');
                }
                if (btn) btn.disabled = false;
                if (icon) icon.textContent = '📧';
                if (label) label.textContent = 'Envoyer PDF par email';
                return;
            }

            // En ligne : envoi direct
            try {
                const result = await envoyerParAPI(interventionId, pdfBase64, clientEmail, csrfToken);
                if (result.success) {
                    afficherToast('✅ Rapport envoyé avec succès à ' + result.email, 'success');
                    if (btn) btn.style.background = 'linear-gradient(135deg,#10b981,#059669)';
                    if (icon) icon.textContent = '✅';
                    if (label) label.textContent = 'Email envoyé !';
                    btn.disabled = true; // Ne pas renvoyer
                } else {
                    afficherToast('❌ ' + (result.message || 'Erreur envoi email'), 'error');
                    if (btn) btn.disabled = false;
                    if (icon) icon.textContent = '🔄';
                    if (label) label.textContent = 'Réessayer l\'envoi';
                }
            } catch (e) {
                // Réseau coupé pendant l'envoi
                try {
                    await sauvegarderEnFile({
                        intervention_id: interventionId,
                        pdf_data: pdfBase64,
                        client_email: clientEmail,
                        csrf_token: csrfToken,
                    });
                    afficherToast('📶 Connexion perdue – email mis en file d\'attente. Il sera envoyé à la reconnexion.', 'warning');
                } catch (qe) {
                    afficherToast('❌ Erreur réseau et impossible de mettre en file : ' + e.message, 'error');
                }
                if (btn) btn.disabled = false;
                if (icon) icon.textContent = '🔄';
                if (label) label.textContent = 'Réessayer l\'envoi';
            }
        }
    
