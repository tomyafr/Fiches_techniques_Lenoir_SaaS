<?php
/**
 * Script de renommage de l'utilisateur Admin.
 * SE SUPPRIME AUTOMATIQUEMENT APRÈS EXÉCUTION.
 */
require_once __DIR__ . '/../includes/config.php';

$db = getDB();

try {
    // On met à jour l'utilisateur dont l'identifiant (nom) est TG
    // On change son prénom en 'Admin' et son nom en vide
    $stmt = $db->prepare("UPDATE users SET prenom = 'Admin', nom_long = 'Administrateur' WHERE nom = 'TG'");
    $stmt->execute();
    
    // Si la colonne 'nom_long' n'existe pas, on tente juste prenom et nom
    if ($stmt->rowCount() === 0) {
        $db->prepare("UPDATE users SET prenom = 'Admin', nom = '' WHERE nom = 'TG'")->execute();
    }

    echo "<div style='font-family:sans-serif; padding:40px; text-align:center;'>
            <h1 style='color:#10b981;'>Utilisateur Renommé ! ✅</h1>
            <p>L'utilisateur est maintenant affiché comme 'Admin'.</p>
            <p style='color:#666;'>Ce script s'est auto-supprimé.</p>
            <a href='index.php' style='display:inline-block; margin-top:20px; padding:10px 20px; background:#020617; color:white; text-decoration:none; border-radius:8px;'>Retour</a>
          </div>";

    unlink(__FILE__);
} catch (Exception $e) {
    echo "Info: " . $e->getMessage();
    unlink(__FILE__);
}
