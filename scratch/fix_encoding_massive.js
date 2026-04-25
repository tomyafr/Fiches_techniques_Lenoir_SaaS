const fs = require('fs');
const path = 'api/machine_edit.php';
let content = fs.readFileSync(path, 'utf8');

const replacements = {
    'Ã©': 'é',
    'Ã ': 'à',
    'Ã¨': 'è',
    'Ã´': 'ô',
    'Ãª': 'ê',
    'Ã«': 'ë',
    'Ã®': 'î',
    'Ã¯': 'ï',
    'Ã¹': 'ù',
    'Ã»': 'û',
    'Ã§': 'ç',
    'Ã‰': 'É',
    'Ãˆ': 'È',
    'Ã€': 'À',
    'Ã‚': 'Â',
    'ÃŽ': 'Î',
    'Ã ': 'Ï',
    'Ã”': 'Ô',
    'Ã›': 'Û',
    'Ã™': 'Ù',
    'Ã‡': 'Ç',
    'Ã¦': 'æ',
    'Ã¸': 'ø',
    'Ã˜': 'Ø',
    'Â°C': '°C',
    'Ã—': '×',
    'â€“': '–',
    'â€”': '—',
    'â€': '—',
    'Ã€': 'à',
    'CHRONOMÃˆTRE': 'CHRONOMÈTRE',
    'prÃ©-remplie': 'pré-remplie',
    'dÃ©tectÃ©s': 'détectés',
    'Ã©diter': 'éditer',
    'â ³': '⏳',
    'â Œ': '❌',
    'âŒ›': '⌛',
    'â†’': '→',
    'â–▶': '▶',
    'â–¶': '▶'
};

for (const [search, replace] of Object.entries(replacements)) {
    content = content.split(search).join(replace);
}

// Remove BOM
if (content.startsWith('\uFEFF')) {
    content = content.slice(1);
}

fs.writeFileSync(path, content, 'utf8');
console.log("Massive cleanup complete with Node.js.");
