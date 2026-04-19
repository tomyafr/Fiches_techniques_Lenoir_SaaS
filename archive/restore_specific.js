const fs = require('fs');

const input = 'api/rapport_final.php.tmp';
const output = 'api/rapport_final.php';

const buffer = fs.readFileSync(input);
// Identify UTF-16LE (FF FE) or UTF-8 BOM (EF BB BF)
let content;
if (buffer[0] === 0xFF && buffer[1] === 0xFE) {
    content = buffer.slice(2).toString('utf16le');
} else if (buffer[0] === 0xEF && buffer[1] === 0xBB && buffer[2] === 0xBF) {
    content = buffer.slice(3).toString('utf8');
} else {
    content = buffer.toString('utf8');
}

fs.writeFileSync(output, content, 'utf8');
console.log('Successfully restored commit e0c4bf3 and converted it to clean UTF-8.');
