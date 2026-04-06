const fs = require('fs');

// 1. rapport_final.php : Force last page
try {
    let content1 = fs.readFileSync('api/rapport_final.php', 'utf8');
    const target = "endPage.style.position = 'relative';";
    const replace = "endPage.style.position = 'relative';\n            endPage.style.pageBreakBefore = 'always';";

    if (content1.includes(target)) {
        content1 = content1.replace(target, replace);
        fs.writeFileSync('api/rapport_final.php', content1);
        console.log('rapport_final SUCCESS');
    } else {
        console.log('rapport_final TARGET NOT FOUND');
    }
} catch (e) {
    console.log('rapport_final ERROR: ' + e.message);
}

// 2. machine_edit.php : Fix section E & F split
try {
    let content2 = fs.readFileSync('api/machine_edit.php', 'utf8');
    const targetSection = '<div class="section-wrapper-pdf" style="border: 1px solid #000; padding:10px; background: #fff;">';
    const replaceSection = '<div class="section-wrapper-pdf" style="border: 1px solid #000; padding:10px; background: #fff; page-break-inside: avoid; break-inside: avoid;">';

    if (content2.includes(targetSection)) {
        content2 = content2.split(targetSection).join(replaceSection);
        fs.writeFileSync('api/machine_edit.php', content2);
        console.log('machine_edit SUCCESS');
    } else {
        console.log('machine_edit TARGET NOT FOUND');
    }
} catch (e) {
    console.log('machine_edit ERROR: ' + e.message);
}
