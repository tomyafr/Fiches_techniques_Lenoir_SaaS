const fs = require('fs');
const path = require('path');

const dirToScan = '.';

const corruptions = [
    [/⏳/g, '⏳'],
    [/☐/g, '☐'],
    [/☐/g, '☐'],
    [/☐‘/g, '☑️'],
    [/➤/g, '➤'],
    [/⚙️/g, '⚙️'],
    [/⚠️/g, '⚠️'],
    [/❌/g, '❌'],
    [/📧/g, '📧'],
    [/📤/g, '📤'],
    [/📶/g, '📶'],
    [/🔄/g, '🔄'],
    [/…/g, '…'],
    [/È/g, 'È'],
    [/É/g, 'É'],
    [/é/g, 'é'],
    [/è/g, 'è'],
    [/à/g, 'à'],
    [/â/g, 'â'],
    [/ê/g, 'ê'],
    [/ë/g, 'ë'],
    [/î/g, 'î'],
    [/ï/g, 'ï'],
    [/ô/g, 'ô'],
    [/û/g, 'û'],
    [/ù/g, 'ù'],
    [/ç/g, 'ç'],
    [/Ç/g, 'Ç'],
    [/É/g, 'É'],
    [/È/g, 'È'],
    [/°/g, '°'],
    [/–/g, '–'],
    [/—/g, '—'],
    [/═/g, '═'],
    [/✅/g, '✅'],
    [/✓/g, '✓'], // Re-fixed for checkmark
    [/✓/g, '✓'], // Re-fixed for checkmark (variant)
    [/←/g, '←'], // Left arrow
    [/←/g, '←'], // Left arrow variant seen in user screenshot for Retour
    [/ /g, ' '],
    [/⏳/g, '⏳'],
];

function processFile(fullPath) {
    let buffer = fs.readFileSync(fullPath);
    
    // Remove BOM
    if (buffer[0] === 0xEF && buffer[1] === 0xBB && buffer[2] === 0xBF) {
        buffer = buffer.slice(3);
    }
    
    let content = buffer.toString('utf8');
    let original = content;
    
    for (const [regex, replacement] of corruptions) {
        content = content.replace(regex, replacement);
    }
    
    // Additional direct replacements for the specific button and link
    content = content.replace(/✓/g, '✓');
    content = content.replace(/←/g, '←');
    content = content.replace(/←/g, '←');
    content = content.replace(/✓/g, '✓');

    if (content !== original) {
        console.log(`Re-Fixed: ${fullPath}`);
        fs.writeFileSync(fullPath, content, 'utf8');
    }
}

function walk(dir) {
    const files = fs.readdirSync(dir);
    for (const file of files) {
        const fullPath = path.join(dir, file);
        const stats = fs.statSync(fullPath);
        if (stats.isDirectory()) {
            if (['node_modules', '.git', '.gemini', 'vendor'].includes(file)) continue;
            walk(fullPath);
        } else if (/\.(php|js|css|html|json)$/.test(file)) {
            processFile(fullPath);
        }
    }
}

console.log('--- GLOBAL CLEANUP V3 START ---');
walk(dirToScan);
console.log('--- GLOBAL CLEANUP V3 FINISHED ---');
