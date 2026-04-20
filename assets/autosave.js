/**
 * Autosave.js - Professional Offline Drafts for Lenoir-Mec
 * 
 * Ce script sauvegarde localement la progression toutes les 15 secondes.
 * En cas de rafraîchissement ou de perte de réseau, il propose de restaurer le brouillon.
 */

document.addEventListener('DOMContentLoaded', () => {
    const form = document.querySelector('form.autosave-form');
    if (!form) return;

    // Clé unique par page (chemin + query params comme ?id=...)
    const pageId = window.location.pathname + window.location.search;
    const storageKey = 'autosave_' + btoa(pageId);
    
    const indicator = document.getElementById('autosave-indicator');

    // 1. Détection d'un brouillon existant au chargement
    const savedDataStr = localStorage.getItem(storageKey);
    if (savedDataStr) {
        try {
            const draft = JSON.parse(savedDataStr);
            const draftTime = new Date(draft._ts).toLocaleString('fr-FR');
            
            // Création d'une bannière de restauration
            const banner = document.createElement('div');
            banner.style.cssText = "position:sticky; top:10px; z-index:10001; background:var(--primary); color:#000; padding:12px; border-radius:8px; margin-bottom:20px; font-weight:bold; display:flex; justify-content:space-between; align-items:center; box-shadow:0 4px 15px rgba(0,0,0,0.4); border: 2px solid #000;";
            banner.innerHTML = `
                <div>
                    <span>📝 Brouillon trouvé (du ${draftTime})</span>
                </div>
                <div style="display:flex; gap:10px;">
                    <button type="button" id="btnRestoreDraft" style="background:#020617; color:#fff; border:none; padding:6px 12px; border-radius:4px; cursor:pointer; font-weight:800;">RESTAURER</button>
                    <button type="button" id="btnIgnoreDraft" style="background:transparent; color:#000; border:1px solid #000; padding:6px 12px; border-radius:4px; cursor:pointer;">IGNORER</button>
                </div>
            `;
            
            form.parentNode.insertBefore(banner, form);

            document.getElementById('btnRestoreDraft').addEventListener('click', () => {
                restoreFromObj(draft.data);
                banner.remove();
                if (typeof showToast === 'function') showToast("Progression restaurée !", "success");
            });

            document.getElementById('btnIgnoreDraft').addEventListener('click', () => {
                if (confirm("Voulez-vous supprimer ce brouillon ?")) {
                    localStorage.removeItem(storageKey);
                    banner.remove();
                }
            });

        } catch (e) {
            console.error('Autosave Error:', e);
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
            // On ignore les photos binaires (trop lourdes pour localStorage) et les tokens
            if (key !== 'csrf_token' && key !== 'action' && !key.includes('photos_json')) {
                dataObj.data[key] = val;
            }
        }

        try {
            localStorage.setItem(storageKey, JSON.stringify(dataObj));
            
            if (indicator) {
                indicator.style.opacity = '1';
                indicator.innerHTML = '<img src="/assets/icon_check_white.svg" style="height:12px; vertical-align:middle; margin-right:5px;"> Brouillon auto-sauvegardé';
                setTimeout(() => { indicator.style.opacity = '0'; }, 3000);
            }
        } catch (e) {
            console.warn('LocalStorage plein ou désactivé');
        }
    }

    // Intervalle de 15 secondes pour la sauvegarde automatique
    setInterval(saveDraft, 15000);

    // Aussi sauvegarder si on quitte la page (visibilitychange est plus fiable que beforeunload)
    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'hidden') saveDraft();
    });
});

/**
 * Nettoyage global (appelé par le serveur après une sauvegarde réussie)
 */
function clearAutosaveDraft() {
    const pageId = window.location.pathname + window.location.search;
    const storageKey = 'autosave_' + btoa(pageId);
    localStorage.removeItem(storageKey);
}
