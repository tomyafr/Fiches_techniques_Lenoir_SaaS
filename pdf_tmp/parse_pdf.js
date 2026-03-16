const fs = require('fs');
const pdf = require('pdf-parse');
console.log(typeof pdf);
let path = 'c:/Users/tomdu/Desktop/saas_lenoir_fiches_techniques/Fiche_technique_LENOIR/Rapport_officiel_exemple/Rapport - K+S France - 2500046.pdf';
let dataBuffer = fs.readFileSync(path);
pdf(dataBuffer).then(function(data) {
    fs.writeFileSync('output.txt', data.text);
}).catch(e => console.error(e));
