const fs = require('fs');
const path = require('path');

const dirToScan = '.';

const corruptions = [
    [/⏳/g, '⏳'],
    [/☹️/g, '☹️'],
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
    [/✅/g, '✅'],
    [/ /g, ' '], // Non-breaking space corruption
    [/⏳/g, '⏳'], // Added from user screenshot
];

function processFile(fullPath) {
    let buffer = fs.readFileSync(fullPath);
    
    // 1. Remove BOM if present
    let hasBom = false;
    if (buffer[0] === 0xEF && buffer[1] === 0xBB && buffer[2] === 0xBF) {
        buffer = buffer.slice(3);
        hasBom = true;
    }
    
    let content = buffer.toString('utf8');
    let originalContent = content;
    
    // 2. Fix corruptions
    for (const [regex, replacement] of corruptions) {
        content = content.replace(regex, replacement);
    }
    
    if (content !== originalContent || hasBom) {
        console.log(`Fixed: ${fullPath} ${hasBom ? '(Removed BOM)' : ''}`);
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

console.log('--- GLOBAL CLEANUP START ---');
walk(dirToScan);
console.log('--- GLOBAL CLEANUP FINISHED ---');
