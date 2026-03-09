const fs = require('fs');
const path = require('path');
const { execSync } = require('child_process');

const inputDir = path.join(__dirname, '../Fiche_technique_LENOIR/Format WORD (orinignaux ne pas utiliser)');
const outputDir = path.join(__dirname, '../assets/machines');

if (!fs.existsSync(outputDir)) {
    fs.mkdirSync(outputDir, { recursive: true });
}

// Ensure unique extraction for docx
const files = fs.readdirSync(inputDir).filter(f => f.endsWith('.docx'));

for (const file of files) {
    const filePath = path.join(inputDir, file);
    const machineRawName = file.replace('2-', '').replace('.docx', '').toLowerCase();

    console.log(`Extracting from ${file}...`);

    // Copy to temporary zip inside outputDir
    const tempZip = path.join(outputDir, `temp_${machineRawName}.zip`);
    fs.copyFileSync(filePath, tempZip);

    const extractionPath = path.join(outputDir, `temp_${machineRawName}_extracted`);

    try {
        // Expand-Archive powershell
        execSync(`powershell.exe -Command "Expand-Archive -Path '${tempZip}' -DestinationPath '${extractionPath}' -Force"`);

        const mediaDir = path.join(extractionPath, 'word', 'media');
        if (fs.existsSync(mediaDir)) {
            const mediaFiles = fs.readdirSync(mediaDir).filter(f => f.endsWith('.png') || f.endsWith('.jpg') || f.endsWith('.jpeg'));
            if (mediaFiles.length > 0) {
                // Heuristic: the largest file is usually the diagram, OR the second one if the first is the logo.
                let largestFile = mediaFiles[0];
                let maxSize = 0;

                // Let's filter out the logo by size if possible. The logo is ~18KB. 
                // Alternatively, just iterate and copy them all, but the user expects the "schema" (diagram).
                for (const m of mediaFiles) {
                    const stat = fs.statSync(path.join(mediaDir, m));
                    if (stat.size > maxSize) {
                        maxSize = stat.size;
                        largestFile = m;
                    }
                }

                const diagramOutput = path.join(outputDir, `${machineRawName}_diagram${path.extname(largestFile)}`);
                fs.copyFileSync(path.join(mediaDir, largestFile), diagramOutput);
                console.log(`-> Saved diagram to ${diagramOutput} (Size: ${maxSize})`);
            } else {
                console.log(`-> No images found in ${file}`);
            }
        }
    } catch (e) {
        console.error(`Error extracting ${file}: ${e.message}`);
    } finally {
        // Cleanup
        if (fs.existsSync(tempZip)) fs.unlinkSync(tempZip);
        if (fs.existsSync(extractionPath)) fs.rmSync(extractionPath, { recursive: true, force: true });
    }
}
console.log('Done.');
