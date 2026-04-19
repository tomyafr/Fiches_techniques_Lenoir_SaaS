const fs = require('fs');

const input = 'api/rapport_final_STABLE.php';
const output = 'api/rapport_final.php';

// Read as UTF-16LE
const buffer = fs.readFileSync(input);
// Remove first 2 bytes (FF FE)
const content = buffer.slice(2).toString('utf16le');

// Write as UTF-8 (no BOM)
fs.writeFileSync(output, content, 'utf8');

console.log('Successfully converted STABLE version from UTF-16LE to UTF-8 and restored it.');
