const fs = require('fs');

const files = [
    'api/machine_edit.php',
    'admin.php',
    'rapport_final.php',
    'intervention_edit.php',
    'index.php',
    'api/get_machine_photos.php'
];

const byteReplacements = [
    { search: Buffer.from([0xC3, 0xA2, 0xC2, 0x8F, 0xC2, 0xB3]), replace: Buffer.from([0xE2, 0x8F, 0xB3]) }, // â ³ -> ⌛
    { search: Buffer.from([0xC3, 0xA2, 0xC2, 0x8F, 0xC2, 0xB9]), replace: Buffer.from([0xE2, 0x8F, 0xB9]) }, // â ¹ -> ⏹
    { search: Buffer.from([0xC3, 0xA2, 0xC2, 0x8F, 0xC2, 0xB1]), replace: Buffer.from([0xE2, 0x8F, 0xB1]) }, // â ± -> ⏱
    { search: Buffer.from([0xC3, 0xA2, 0xC2, 0x9A, 0xC2, 0xA0, 0xC3, 0xAF, 0xC2, 0xB8, 0xC2, 0x8F]), replace: Buffer.from([0xE2, 0x9A, 0xA0, 0xEF, 0xB8, 0x8F]) }, // âš ï¸  -> ⚠️
    { search: Buffer.from([0xC3, 0xA2, 0xC2, 0x9C, 0xC2, 0x96]), replace: Buffer.from([0xE2, 0x9C, 0x96]) }, // â Œ -> ❌
    { search: Buffer.from([0xC3, 0xB0, 0xC5, 0xB8, 0xE2, 0x80, 0x9C, 0xC2, 0xB7]), replace: Buffer.from([0xF0, 0x9F, 0x93, 0xB7]) }, // ðŸ“· -> 📸
    { search: Buffer.from([0xC3, 0xB0, 0xC5, 0xB8, 0xE2, 0x80, 0x9C, 0xC2, 0xB8]), replace: Buffer.from([0xF0, 0x9F, 0x93, 0xB8]) }, // ðŸ“¸ -> 📸
    { search: Buffer.from([0xC3, 0xA2, 0xE2, 0x82, 0xAC, 0xE2, 0x80, 0x9D]), replace: Buffer.from([0xE2, 0x80, 0x93]) }, // â€“ -> –
    { search: Buffer.from([0xC3, 0xA2, 0xE2, 0x82, 0xAC, 0xE2, 0x80, 0x94]), replace: Buffer.from([0xE2, 0x80, 0x94]) }, // â€” -> —
    { search: Buffer.from([0xC3, 0xA2, 0xE2, 0x82, 0xAC, 0xC2, 0x9C]), replace: Buffer.from([0xE2, 0x80, 0x9C]) }, // â€œ -> “
    { search: Buffer.from([0xC3, 0x83, 0xC2, 0x97]), replace: Buffer.from([0xC3, 0x97]) }, // Ã— -> ×
    { search: Buffer.from([0xC3, 0x83, 0xC2, 0x80]), replace: Buffer.from([0xC3, 0x80]) }, // Ã€ -> À
    { search: Buffer.from([0xC3, 0x83, 0xC2, 0xA9]), replace: Buffer.from([0xC3, 0xA9]) }, // Ã© -> é
    { search: Buffer.from([0xC3, 0x83, 0xC2, 0xA0]), replace: Buffer.from([0xC3, 0xA0]) }, // Ã  -> à
    { search: Buffer.from([0xC3, 0x83, 0xC2, 0xA8]), replace: Buffer.from([0xC3, 0xA8]) }, // Ã¨ -> è
    { search: Buffer.from([0xC3, 0x83, 0xC2, 0xB4]), replace: Buffer.from([0xC3, 0xB4]) }, // Ã´ -> ô
    { search: Buffer.from([0xC3, 0x83, 0xC2, 0xAA]), replace: Buffer.from([0xC3, 0xAA]) }, // Ãª -> ê
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
