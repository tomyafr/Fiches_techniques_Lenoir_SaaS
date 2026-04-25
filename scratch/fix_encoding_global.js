const fs = require('fs');
const files = ['api/machine_edit.php', 'api/admin.php', 'api/rapport_final.php', 'api/intervention_edit.php'];

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
    'â ³': '⌛',
    'âš ï¸ ': '⚠️',
    'â Œ': '❌',
    'â ¹': '⏹',
    'â ±': '⏱',
    'ðŸ“·': '📸',
    'ðŸ“¸': '📸',
    'Ã€': 'À',
    'Ã ': 'à',
    'CHRONOMÃˆTRE': 'CHRONOMÈTRE',
    'prÃ©-remplie': 'pré-remplie',
    'dÃ©tectÃ©s': 'détectés',
    'Ã©diter': 'éditer',
    'â†’': '→',
    'â–▶': '▶',
    'â–¶': '▶',
    'Â ': ' ', // Non-breaking space
    'â— ': '●',
    'â—‹': '○'
};

files.forEach(file => {
    if (fs.existsSync(file)) {
        let content = fs.readFileSync(file, 'utf8');
        let changed = false;
        
        for (const [search, replace] of Object.entries(replacements)) {
            if (content.includes(search)) {
                content = content.split(search).join(replace);
                changed = true;
            }
        }

        // Remove BOM
        if (content.startsWith('\uFEFF')) {
            content = content.slice(1);
            changed = true;
        }

        if (changed) {
            fs.writeFileSync(file, content, 'utf8');
            console.log(`Cleanup complete for ${file}.`);
        } else {
            console.log(`No changes needed for ${file}.`);
        }
    } else {
        console.log(`File not found: ${file}`);
    }
});
