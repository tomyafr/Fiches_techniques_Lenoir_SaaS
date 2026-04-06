const fs = require('fs');
let content = fs.readFileSync('api/rapport_final.php', 'utf8');

// 1. Force last page to always start on a new page
const targetEndPage = `            // --- 4. PAGE DE FIN (STRUCTURE LENOIR-MEC + SIGNATURES + OBSERVATIONS) ---

            const endPage = document.createElement('div');
            endPage.className = 'pdf-page';
            endPage.style.padding = '0 15mm';
            endPage.style.position = 'relative';`;

const replaceEndPage = `            // --- 4. PAGE DE FIN (STRUCTURE LENOIR-MEC + SIGNATURES + OBSERVATIONS) ---
            const endPage = document.createElement('div');
            endPage.className = 'pdf-page';
            endPage.style.padding = '0 15mm';
            endPage.style.position = 'relative';
            endPage.style.pageBreakBefore = 'always';`;

if (content.indexOf(targetEndPage) !== -1) {
    content = content.replace(targetEndPage, replaceEndPage);
    console.log("Last page break fixed.");
} else {
    // Try without extra newline just in case
    const targetEndPage2 = `            // --- 4. PAGE DE FIN (STRUCTURE LENOIR-MEC + SIGNATURES + OBSERVATIONS) ---
            const endPage = document.createElement('div');
            endPage.className = 'pdf-page';
            endPage.style.padding = '0 15mm';
            endPage.style.position = 'relative';`;
    if (content.indexOf(targetEndPage2) !== -1) {
        content = content.replace(targetEndPage2, replaceEndPage);
        console.log("Last page break fixed (v2).");
    } else {
        console.log("Could not find endPage target.");
    }
}

fs.writeFileSync('api/rapport_final.php', content);

// 2. Fix CAUSE DE DYSFONCTIONNEMENT section splitting in machine_edit.php
let content2 = fs.readFileSync('api/machine_edit.php', 'utf8');
const targetDys = '<div class="section-wrapper-pdf" style="border: 1px solid #000; padding:10px; background: #fff;">\n                            <div class="pdf-section-title">E) CAUSE DE DYSFONCTIONNEMENT :</div>';
const replaceDys = '<div class="section-wrapper-pdf" style="border: 1px solid #000; padding:10px; background: #fff; page-break-inside: avoid; break-inside: avoid;">\n                            <div class="pdf-section-title">E) CAUSE DE DYSFONCTIONNEMENT :</div>';

if (content2.indexOf(targetDys) !== -1) {
    content2 = content2.replace(targetDys, replaceDys);
    console.log("Section E split fixed.");
} else {
    console.log("Could not find section E target.");
}

fs.writeFileSync('api/machine_edit.php', content2);
