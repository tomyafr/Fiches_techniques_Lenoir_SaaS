const fs = require('fs');
const path = require('path');

const dirToScan = '.';

const corruptions = [
    // Box drawing / Separators
    [/═/g, '═'],
    
    // Emojis / Symbols
    [/✅/g, '✅'],
    [/❌/g, '❌'],
    [/⚠️/g, '⚠️'],
    [/⏳/g, '⏳'],
    [/📧/g, '📧'],
    [/📤/g, '📤'],
    [/📶/g, '📶'],
    [/🔄/g, '🔄'],
    [/✓/g, '✓'],
    [/←/g, '←'],
    [/…/g, '…'],
    [/⚙️/g, '⚙️'],

    // French accents (Latin-1/MacRoman mess)
    [/é/g, 'é'],
    [/à/g, 'à'],
    [/è/g, 'è'],
    [/ê/g, 'ê'],
    [/ë/g, 'ë'],
    [/î/g, 'î'],
    [/ï/g, 'ï'],
    [/ô/g, 'ô'],
    [/û/g, 'û'],
    [/ù/g, 'ù'],
    [/ç/g, 'ç'],
    [/À/g, 'À'],
    [/É/g, 'É'],
    [/È/g, 'È'],
    [/À/g, 'À'],
    [/Ç/g, 'Ç'],
    [/ö/g, 'ö'],
    [/ä/g, 'ä'],
    
    // Special patterns from view_file
    [/génération/gi, 'génération'],
    [/renseigné/g, 'renseigné'],
    [/envoyé/g, 'envoyé'],
    [/succès/g, 'succès'],
    [/Réessayer/g, 'Réessayer'],
    [/–/g, '–'],
    [/–/g, '—'], // Just in case, usually it's en-dash
    [/—/g, '—'],
    [/'/g, "'"],
    [/coupé/g, 'coupé'],
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
    
    if (content !== original) {
        console.log(`Deep Cleaned: ${fullPath}`);
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

console.log('--- DEEP CLEANUP START ---');
walk(dirToScan);
console.log('--- DEEP CLEANUP FINISHED ---');
