const fs = require('fs');
let content = fs.readFileSync('api/machine_edit.php', 'utf8');

const targetStyle = 'style="border: 1px solid #000; padding:10px; background: #fff; page-break-inside: avoid; break-inside: avoid;"';
const replacementStyle = 'style="padding:0; background: transparent; page-break-inside: avoid; break-inside: avoid;"';

if (content.includes(targetStyle)) {
    content = content.split(targetStyle).join(replacementStyle);
    fs.writeFileSync('api/machine_edit.php', content);
    console.log('SUCCESS: Style updated.');
} else {
    console.log('FAIL: targetStyle not found.');
}
