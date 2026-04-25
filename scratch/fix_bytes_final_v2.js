const fs = require('fs');

const files = [
    'api/machine_edit.php',
    'api/admin.php',
    'api/rapport_final.php',
    'api/intervention_edit.php',
    'api/index.php',
    'api/get_machine_photos.php'
];

const byteReplacements = [
    { search: Buffer.from([0xC3, 0xA2, 0xCB, 0x9C, 0xE2, 0x80, 0x98]), replace: Buffer.from('☑', 'utf8') }, // â˜‘ -> ☑
    { search: Buffer.from([0xC3, 0xA2, 0xCB, 0x9C, 0xC2, 0x90]), replace: Buffer.from('☐', 'utf8') },     // â˜  -> ☐
    { search: Buffer.from([0xC3, 0xA2, 0xC5, 0xA1, 0xC2, 0xA0, 0xC3, 0xAF, 0xC2, 0xB8, 0xC2, 0x8F]), replace: Buffer.from('⚠️', 'utf8') }, // âš ï¸  -> ⚠️
    { search: Buffer.from([0xC3, 0xA2, 0xC2, 0x9C, 0xC2, 0x96]), replace: Buffer.from('❌', 'utf8') }, // â Œ -> ❌
    { search: Buffer.from([0xC3, 0xA2, 0xC2, 0x8F, 0xC2, 0xB3]), replace: Buffer.from('⌛', 'utf8') }, // â ³ -> ⌛
    { search: Buffer.from([0xC3, 0xA2, 0xC2, 0x8F, 0xC2, 0xB9]), replace: Buffer.from('⏹', 'utf8') }, // â ¹ -> ⏹
    { search: Buffer.from([0xC3, 0xA2, 0xC2, 0x8F, 0xC2, 0xB1]), replace: Buffer.from('⏱', 'utf8') }, // â ± -> ⏱
    { search: Buffer.from([0xC3, 0xB0, 0xC5, 0xB8, 0xE2, 0x80, 0x9C, 0xC2, 0xB7]), replace: Buffer.from('📸', 'utf8') }, // ðŸ“· -> 📸
    { search: Buffer.from([0xC3, 0xB0, 0xC5, 0xB8, 0xE2, 0x80, 0x9C, 0xC2, 0xB8]), replace: Buffer.from('📸', 'utf8') }, // ðŸ“¸ -> 📸
    { search: Buffer.from([0xC3, 0x83, 0xC2, 0xA9]), replace: Buffer.from('é', 'utf8') },
    { search: Buffer.from([0xC3, 0x83, 0xC2, 0xA0]), replace: Buffer.from('à', 'utf8') },
    { search: Buffer.from([0xC3, 0x83, 0xC2, 0xA8]), replace: Buffer.from('è', 'utf8') },
    { search: Buffer.from([0xC3, 0x83, 0xC2, 0xB4]), replace: Buffer.from('ô', 'utf8') },
    { search: Buffer.from([0xC3, 0x83, 0xC2, 0xAA]), replace: Buffer.from('ê', 'utf8') },
    { search: Buffer.from([0xC3, 0x83, 0xC2, 0x80]), replace: Buffer.from('À', 'utf8') },
    { search: Buffer.from([0xC3, 0x83, 0xC2, 0x97]), replace: Buffer.from('×', 'utf8') },
    { search: Buffer.from([0xC3, 0xA2, 0xE2, 0x82, 0xAC, 0xE2, 0x80, 0x9D]), replace: Buffer.from('–', 'utf8') },
    { search: Buffer.from([0xC3, 0xA2, 0xE2, 0x82, 0xAC, 0xE2, 0x80, 0x94]), replace: Buffer.from('—', 'utf8') },
];

files.forEach(file => {
    if (fs.existsSync(file)) {
        let buffer = fs.readFileSync(file);
        let changed = false;

        for (const { search, replace } of byteReplacements) {
            let pos = 0;
            while ((pos = buffer.indexOf(search, pos)) !== -1) {
                const newBuffer = Buffer.concat([
                    buffer.slice(0, pos),
                    replace,
                    buffer.slice(pos + search.length)
                ]);
                buffer = newBuffer;
                changed = true;
                pos += replace.length;
            }
        }

        if (changed) {
            fs.writeFileSync(file, buffer);
            console.log(`Byte-level cleanup complete for ${file}.`);
        } else {
            console.log(`No corrupted byte sequences found in ${file}.`);
        }
    }
});
