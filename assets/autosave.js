/**
 * Autosave.js - Lightweight Offline Drafts
 * 
 * Sauvegarde localement les données saisies par le technicien
 * pour éviter la perte d'informations dans les zones sans réseau (sous-sols, etc.)
 */

document.addEventListener('DOMContentLoaded', () => {
    // Ne cibler que les formulaires qui ont la classe 'autosave-form'
    const form = document.querySelector('form.autosave-form');
    if (!form) return;

    // Utiliser l'URL de base comme clé de stockage unique
    const pageId = window.location.pathname + window.location.search;
    const storageKey = 'autosave_' + btoa(pageId);

    // 1. Restaurer les données au chargement si un brouillon existe
    const savedData = localStorage.getItem(storageKey);
    if (savedData) {
        try {
            const parsedData = JSON.parse(savedData);
            let restoredCount = 0;

            for (const [name, value] of Object.entries(parsedData)) {
                // Trouver l'input correspondant (par name, car c'est ce qui est envoyé en PHP)
                const inputs = form.querySelectorAll(`[name="${name}"]`);
                if (!inputs.length) continue;

                const input = inputs[0];
                
                // Restaurer les radios et checkboxes (toujours prioritaire sur le serveur car c'est un brouillon local)
                if (input.type === 'radio' || input.type === 'checkbox') {
                    inputs.forEach(r => {
                        if (r.value === value) {
                            r.checked = true;
                            // Déclencher les événements pour que les autres scripts (pastilles, etc.) se mettent à jour visuellement
                            r.dispatchEvent(new Event('change', { bubbles: true }));
                            r.dispatchEvent(new Event('input', { bubbles: true }));
                            restoredCount++;
                        }
                    });
                } else if (input.tagName === 'SELECT' || input.tagName === 'TEXTAREA' || input.type === 'text' || input.type === 'number') {
                    // Pour les textes, on ne restaure que si le champ est vide (brouillon non encore envoyé au serveur)
                    if (!input.defaultValue && !input.getAttribute('data-server-loaded') && !input.value) {
                        input.value = value;
                        input.dispatchEvent(new Event('input', { bubbles: true }));
                        restoredCount++;
                    }
                }
            }

            if (restoredCount > 0 && typeof showToast === 'function') {
                showToast("Brouillon hors-ligne restauré (Réseau faible)", "info");
            }

        } catch (e) {
            console.error('Erreur lors de la lecture du autosave:', e);
        }
    }

    // 2. Sauvegarder à chaque modification
    const saveToLocal = () => {
        const formData = new FormData(form);
        const dataObj = {};
        for (let [key, val] of formData.entries()) {
            // Ne pas sauvegarder le token CSRF ni les champs cachés techniques
            if (key !== 'csrf_token' && key !== 'action') {
                dataObj[key] = val;
            }
        }
        localStorage.setItem(storageKey, JSON.stringify(dataObj));
        
        // Petit indicateur visuel discret
        const indicator = document.getElementById('autosave-indicator');
        if (indicator) {
            indicator.style.opacity = '1';
            indicator.textContent = 'Brouillon sauvegardé localement ✓';
            setTimeout(() => { indicator.style.opacity = '0'; }, 2000);
        }
    };

    // Déclencher la sauvegarde sur input/change
    form.addEventListener('input', saveToLocal);
    form.addEventListener('change', saveToLocal);

    // 3. Vider le brouillon quand le formulaire est validé (soumission réussie au serveur)
    form.addEventListener('submit', () => {
        // Optionnel: on pourrait le vider seulement APRES validation serveur
        // Mais pour l'instant, si le form part, on garde le localStorage intact 
        // au cas où le serveur plante (Timeout hors ligne). 
        // Le vidage se fera si la page PHP est rechargée sans POST (voir step 4)
    });
});

// 4. Fonction pour purger le cache manuellement (appelée en PHP si succès)
function clearAutosaveDraft() {
    const pageId = window.location.pathname + window.location.search;
    const storageKey = 'autosave_' + btoa(pageId);
    localStorage.removeItem(storageKey);
}
