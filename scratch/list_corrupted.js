const fs = require('fs');
const content = fs.readFileSync('api/machine_edit.php', 'utf8');
const lines = content.split('\n');
lines.forEach((line, i) => {
    if (line.includes('â') || line.includes('ð') || line.includes('Ã')) {
        console.log(`${i+1}: ${line.trim()}`);
    }
});
