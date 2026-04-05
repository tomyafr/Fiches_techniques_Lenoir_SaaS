const fs = require('fs');
let content = fs.readFileSync('api/rapport_final.php', 'utf8');

const target1 = "        function createPdfFooter() {\n" +
"            const f = document.createElement('div');\n" +
"            const leg = window.LM_RAPPORT.legal;\n" +
"            f.style.marginTop = '30px';\n" +
"            f.style.width = '100%';\n" +
"            f.style.textAlign = 'center';\n" +
"            f.style.fontSize = '9px';\n" +
"            f.style.fontWeight = 'bold';\n" +
"            f.style.borderTop = '2px solid #000';\n" +
"            f.style.paddingTop = '5px';\n" +
"            f.style.paddingBottom = '5px';\n" +
"            f.style.pageBreakInside = 'avoid';\n" +
"            f.innerHTML = `${leg.address}<br>${leg.contact}<br>${leg.siret}`;\n" +
"            return f;\n" +
"        }";

const replacement1 = `        function createPdfFooter() {
            return document.createElement('div');
        }`;

const target2 = "            const opt = {\n" +
"                margin: [10, 0, 15, 0], // Top, Left, Bottom, Right\n" +
"                filename: window.LM_RAPPORT ? window.LM_RAPPORT.pdfFilename : 'rapport.pdf',\n" +
"                image: { type: 'jpeg', quality: 0.98 },\n" +
"                html2canvas: { scale: 2, useCORS: true, logging: false },\n" +
"                jsPDF: { unit: 'mm', format: 'a4', orientation: 'portrait' },\n" +
"                pagebreak: { mode: ['css', 'legacy'], avoid: ['tbody', 'img', '.photo-annexe-item', '.pdf-section', '.sig-zone', '.levage-diagram-container'] }\n" +
"            };\n" +
"\n" +
"            const worker = html2pdf().set(opt).from(container);\n" +
"\n" +
"            await worker.toPdf().get('pdf').then(function (pdf) {\n" +
"                const totalPages = pdf.internal.getNumberOfPages();\n" +
"                for (let i = 1; i <= totalPages; i++) {\n" +
"                    pdf.setPage(i);\n" +
"                    pdf.setFont('helvetica', 'normal');\n" +
"                    pdf.setFontSize(9);\n" +
"                    pdf.setTextColor(50, 50, 50);\n" +
"                    pdf.text('Page ' + i + ' / ' + totalPages, 105, 286, { align: 'center' });\n" +
"                }\n" +
"            });";

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
