/**
 * Autosave.js - Professional Offline Drafts for Lenoir-Mec
 * 
 * Sauvegarde localement la progression en silence.
 * Restaure le brouillon de manière non-intrusive.
 */

document.addEventListener('DOMContentLoaded', () => {
    const form = document.querySelector('form.autosave-form');
    if (!form) return;

    // Clé unique par page basée uniquement sur le path et l'ID (ignore les msgs de succès)
    const urlParams = new URLSearchParams(window.location.search);
    const id = urlParams.get('id') || 'new';
    const pageId = window.location.pathname + '?id=' + id;
    const storageKey = 'autosave_' + btoa(pageId);
    
    const indicator = document.getElementById('autosave-indicator');

    // 1. Détection d'un brouillon existant au chargement
    const savedDataStr = localStorage.getItem(storageKey);
    if (savedDataStr) {
        try {
            const draft = JSON.parse(savedDataStr);
            const draftTime = new Date(draft._ts).toLocaleString('fr-FR');
            
            // On vérifie si le brouillon est récent (moins de 24h)
            if (Date.now() - draft._ts < 24 * 3600 * 1000) {
                // Création d'une bannière de restauration non-intrusive (très fine, intégrée en haut du form)
                const banner = document.createElement('div');
                banner.style.cssText = "background:#ffb300; color:#000; padding:8px 15px; border-radius:6px; margin-bottom:15px; font-size:13px; font-weight:600; display:flex; justify-content:space-between; align-items:center; border: 1px solid #cc8f00;";
                banner.innerHTML = `
                    <div style="display:flex; align-items:center; gap:8px;">
                        <span style="font-size:16px;">⏱️</span>
                        <span>Un brouillon non sauvegardé du ${draftTime} est disponible.</span>
                    </div>
                    <div style="display:flex; gap:8px;">
                        <button type="button" id="btnRestoreDraft" style="background:#020617; color:#fff; border:none; padding:4px 10px; border-radius:4px; cursor:pointer; font-weight:bold; font-size:12px; transition:0.2s;">Restaurer</button>
                        <button type="button" id="btnIgnoreDraft" style="background:transparent; color:#000; border:1px solid #000; padding:4px 10px; border-radius:4px; cursor:pointer; font-size:12px; transition:0.2s;">Ignorer</button>
                    </div>
                `;
                
                form.parentNode.insertBefore(banner, form);

                document.getElementById('btnRestoreDraft').addEventListener('click', () => {
                    restoreFromObj(draft.data);
                    banner.remove();
                    if (typeof showToast === 'function') showToast("Progression restaurée !", "success");
                });

                document.getElementById('btnIgnoreDraft').addEventListener('click', () => {
                    localStorage.removeItem(storageKey);
                    banner.remove();
                });
            } else {
                // Brouillon trop vieux (>24h), on le nettoie en silence
                localStorage.removeItem(storageKey);
            }
        } catch (e) {
            console.error('Autosave Error:', e);
            localStorage.removeItem(storageKey);
        }
    }

    /**
     * Restaure les valeurs du formulaire à partir d'un objet
     */
    function restoreFromObj(data) {
        for (const [name, value] of Object.entries(data)) {
            const inputs = form.querySelectorAll(`[name="${name}"]`);
            if (!inputs.length) continue;

            const first = inputs[0];
            if (first.type === 'radio' || first.type === 'checkbox') {
                inputs.forEach(r => {
                    if (r.value === value) {
                        r.checked = true;
                        r.dispatchEvent(new Event('change', { bubbles: true }));
                    }
                });
            } else {
                first.value = value;
                first.dispatchEvent(new Event('input', { bubbles: true }));
            }
        }
    }

    /**
     * Sauvegarde l'état actuel du formulaire
     */
    function saveDraft() {
        const formData = new FormData(form);
        const dataObj = {
            _ts: Date.now(),
            data: {}
        };

        for (let [key, val] of formData.entries()) {
            // On ignore les photos binaires et les champs de sécurité
            if (key !== 'csrf_token' && key !== 'action' && !key.includes('photos_json') && !key.includes('signature')) {
                dataObj.data[key] = val;
            }
        }

        try {
            localStorage.setItem(storageKey, JSON.stringify(dataObj));
            
            if (indicator) {
                indicator.style.opacity = '1';
                indicator.innerHTML = '💾 Brouillon auto-sauvegardé';
                setTimeout(() => { indicator.style.opacity = '0'; }, 2000);
            }
        } catch (e) {
            // LocalStorage peut être plein, on échoue silencieusement
        }
    }

    // Intervalle de 10 secondes pour la sauvegarde automatique
    setInterval(saveDraft, 10000);

    // Sauvegarder aussi à la perte de focus (changement d'onglet ou fermeture)
    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'hidden') saveDraft();
    });
});

/**
 * Nettoyage global (appelé par le serveur via script inline ou onsubmit)
 */
function clearAutosaveDraft() {
    try {
        const urlParams = new URLSearchParams(window.location.search);
        const id = urlParams.get('id') || 'new';
        const pageId = window.location.pathname + '?id=' + id;
        const storageKey = 'autosave_' + btoa(pageId);
        localStorage.removeItem(storageKey);
    } catch(e) {}
}
