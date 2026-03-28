# Guide de Dépannage : Signature et PDF (Rapport Final)

Ce document répertorie les causes fréquentes de pannes sur la page `rapport_final.php` et les solutions appliquées pour les résoudre. À consulter en cas de "page blanche" ou de boutons inactifs.

## 1. La "Page Blanche" (Erreur de Syntaxe PHP)
**Symptôme** : Le serveur renvoie une page vide ou une erreur 500.
**Cause fréquente** : Une opération automatisée de "nettoyage" a remplacé les flèches de tableaux PHP `=>` par le mot-clé `function`.
**Correction** :
- Vérifier les tableaux associatifs PHP (ex: `window.LM_RAPPORT`).
- Remplacer `'id' function $m['id']` par `'id' => $m['id']`.

## 2. Boutons inactifs (IA ou PDF ne répondent pas)
**Symptôme** : Cliquer sur "Expert IA" ou "Télécharger" ne fait rien (pas même un message d'erreur visible sans console).
**Causes possibles** :
### A. Problème de Portée (Scope)
- **Erreur** : Les fonctions (`generateAllIA`, `telechargerPDF`) sont définies à l'intérieur d'un `document.addEventListener('DOMContentLoaded', ...)` et ne sont pas visibles pour les attributs HTML `onclick`.
- **Solution** : Exposer explicitement les fonctions : `window.generateAllIA = generateAllIA;`.

### B. Erreur de Syntaxe JS (Parsing)
- **Erreur** : Une corruption dans le code (ex: `forEach(sel function {`) empêche le navigateur de lire TOUT le bloc de script.
- **Vérification** : Taper `typeof generateAllIA` dans la console. Si `undefined`, le script n'a pas été chargé.
- **Réparation** : S'assurer que les boucles utilisent la syntaxe ES5 classique `forEach(function(el) { ... })` pour la compatibilité tablette.

## 3. Crash pendant le téléchargement (Génération PDF)
**Symptôme** : Le bouton s'active, l'overlay s'affiche, mais le téléchargement ne finit jamais ou une erreur apparaît en console.
**Cause fréquente** : Variable non définie dans les template literals (backticks).
- **Exemple** : Utilisation de `${numArc}` sans que `const numArc = ...` ne soit déclaré en haut du script JS.
**Correction** : Extraire les variables nécessaires depuis `window.LM_RAPPORT` au tout début du bloc `<script>`.

## 4. Problèmes de Signature (Non-affichage)
**Symptôme** : Les zones de dessin ne s'affichent pas ou le tracé est invisible.
**Architecture de protection** :
- **Isolation** : Toujours laisser le code des signatures dans le **PREMIER bloc `<script>`** du fichier.
- **Compatibilité** : Ne jamais utiliser de fonctions fléchées (`=>`), de `const` ou de `let` dans ce premier bloc. Utiliser `var` et `function()` uniquement pour garantir le fonctionnement sur les tablettes très anciennes.
- **Initialisation** : Appeler `initSignatures()` au `DOMContentLoaded` ET au `window.onload`.

---
*Note : Si cet incident se reproduit, vérifier en priorité les modifications récentes faites via des outils de remplacement global qui ne distinguent pas le PHP du JavaScript.*
