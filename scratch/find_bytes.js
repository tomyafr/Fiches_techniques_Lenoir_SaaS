const fs = require('fs');
const buffer = fs.readFileSync('api/machine_edit.php');
const target = Buffer.from('Stop');
let pos = -1;
while ((pos = buffer.indexOf(target, pos + 1)) !== -1) {
    const context = buffer.slice(pos - 10, pos + 10);
    console.log(`Found Stop at ${pos}: ${context.toString('hex')} (${context.toString('ascii')})`);
}
