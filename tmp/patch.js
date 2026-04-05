const fs = require('fs');
let content = fs.readFileSync('api/rapport_final.php', 'utf8');

const target1 = `        function createPdfFooter() {
            const f = document.createElement('div');
            const leg = window.LM_RAPPORT.legal;
            f.style.marginTop = '30px';
            f.style.width = '100%';
            f.style.textAlign = 'center';
            f.style.fontSize = '9px';
            f.style.fontWeight = 'bold';
            f.style.borderTop = '2px solid #000';
            f.style.paddingTop = '5px';
            f.style.paddingBottom = '5px';
            f.style.pageBreakInside = 'avoid';
            f.innerHTML = \`\${leg.address}<br>\${leg.contact}<br>\${leg.siret}\`;
            return f;
        }`;

const replacement1 = `        function createPdfFooter() {
            return document.createElement('div');
        }`;

const target2 = `            const opt = {
                margin: [10, 0, 15, 0], // Top, Left, Bottom, Right
                filename: window.LM_RAPPORT ? window.LM_RAPPORT.pdfFilename : 'rapport.pdf',
                image: { type: 'jpeg', quality: 0.98 },
                html2canvas: { scale: 2, useCORS: true, logging: false },
                jsPDF: { unit: 'mm', format: 'a4', orientation: 'portrait' },
                pagebreak: { mode: ['css', 'legacy'], avoid: ['tbody', 'img', '.photo-annexe-item', '.pdf-section', '.sig-zone', '.levage-diagram-container'] }
            };

            const worker = html2pdf().set(opt).from(container);

            await worker.toPdf().get('pdf').then(function (pdf) {
                const totalPages = pdf.internal.getNumberOfPages();
                for (let i = 1; i <= totalPages; i++) {
                    pdf.setPage(i);
                    pdf.setFont('helvetica', 'normal');
                    pdf.setFontSize(9);
                    pdf.setTextColor(50, 50, 50);
                    pdf.text('Page ' + i + ' / ' + totalPages, 105, 286, { align: 'center' });
                }
            });`;

const replacement2 = `            const opt = {
                margin: [10, 0, 25, 0], // Top, Left, Bottom, Right
                filename: window.LM_RAPPORT ? window.LM_RAPPORT.pdfFilename : 'rapport.pdf',
                image: { type: 'jpeg', quality: 0.98 },
                html2canvas: { scale: 2, useCORS: true, logging: false },
                jsPDF: { unit: 'mm', format: 'a4', orientation: 'portrait' },
                pagebreak: { mode: ['css', 'legacy'], avoid: ['tbody', 'img', '.photo-annexe-item', '.pdf-section', '.sig-zone', '.levage-diagram-container'] }
            };

            const worker = html2pdf().set(opt).from(container);

            await worker.toPdf().get('pdf').then(function (pdf) {
                const totalPages = pdf.internal.getNumberOfPages();
                const leg = window.LM_RAPPORT.legal;
                
                const extractText = (htmlStr) => {
                    const tmp = document.createElement('div');
                    tmp.innerHTML = htmlStr;
                    return tmp.textContent || tmp.innerText || '';
                };
                const lAddress = extractText(leg.address || '');
                const lContact = extractText(leg.contact || '');
                const lSiret = extractText(leg.siret || '');

                for (let i = 1; i <= totalPages; i++) {
                    pdf.setPage(i);
                    
                    pdf.setDrawColor(0);
                    pdf.setLineWidth(0.5);
                    pdf.line(10, 276, 200, 276);
                    
                    pdf.setFont('helvetica', 'bold');
                    pdf.setFontSize(8);
                    pdf.setTextColor(0, 0, 0);
                    pdf.text(lAddress, 105, 280, { align: 'center' });
                    pdf.text(lContact, 105, 284, { align: 'center' });
                    pdf.text(lSiret, 105, 288, { align: 'center' });
                    
                    pdf.setFont('helvetica', 'normal');
                    pdf.setFontSize(9);
                    pdf.setTextColor(50, 50, 50);
                    pdf.text('Page ' + i + ' / ' + totalPages, 105, 294, { align: 'center' });
                }
            });`;

content = content.replace(target1, replacement1);
content = content.replace(target2, replacement2);
fs.writeFileSync('api/rapport_final.php', content);
