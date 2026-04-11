const fs = require('fs');
const path = require('path');

function removeBom(dir) {
    const files = fs.readdirSync(dir);
    
    files.forEach(file => {
        const fullPath = path.join(dir, file);
        const stats = fs.statSync(fullPath);
        
        if (stats.isDirectory()) {
            if (file !== 'node_modules' && file !== '.git' && file !== '.gemini') {
                removeBom(fullPath);
            }
        } else if (file.endsWith('.php') || file.endsWith('.js') || file.endsWith('.css') || file.endsWith('.html')) {
            let buffer = fs.readFileSync(fullPath);
            if (buffer[0] === 0xEF && buffer[1] === 0xBB && buffer[2] === 0xBF) {
                console.log(`BOM removed from: ${fullPath}`);
                fs.writeFileSync(fullPath, buffer.slice(3));
            }
        }
    });
}

// Fixed corrupted sequences for symbols
const corruptReplacements = {
    '✅': '✅',
    '✅': '✅',
    '⏳': '⏳', // Example for spinner/wait icon
    '⬅️': '⬅️',
    '🚀': '🚀',
    'é': 'é',
    'è': 'è',
    'à': 'à',
    'â': 'â',
    'ê': 'ê',
    'ë': 'ë',
    'î': 'î',
    'ï': 'ï',
    'ô': 'ô',
    'û': 'û',
    'ù': 'ù',
    'ç': 'ç',
    'É': 'É',
    '°': '°',
    '–': '–',
    '—': '—'
};

function fixCorruption(dir) {
    const files = fs.readdirSync(dir);
    
    files.forEach(file => {
        const fullPath = path.join(dir, file);
        const stats = fs.statSync(fullPath);
        
        if (stats.isDirectory()) {
            if (file !== 'node_modules' && file !== '.git' && file !== '.gemini') {
                fixCorruption(fullPath);
            }
        } else if (file.endsWith('.php') || file.endsWith('.js')) {
            let content = fs.readFileSync(fullPath, 'utf8');
            let changed = false;
            for (const [corrupt, fixed] of Object.entries(corruptReplacements)) {
                if (content.includes(corrupt)) {
                    content = content.split(corrupt).join(fixed);
                    changed = true;
                }
            }
            if (changed) {
                console.log(`Corrupted characters fixed in: ${fullPath}`);
                fs.writeFileSync(fullPath, content, 'utf8');
            }
        }
    });
}

console.log('--- STARTING GLOBAL BOM CLEANUP ---');
removeBom('.');
console.log('--- STARTING GLOBAL CORRUPTION FIX ---');
fixCorruption('.');
console.log('--- ALL DONE ---');
