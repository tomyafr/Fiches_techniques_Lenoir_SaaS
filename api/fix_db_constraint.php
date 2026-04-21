<?php
/**
 * Script de mise à jour de la contrainte CHECK sur la table interventions.
 * Permet d'accepter les statuts avec accents 'Terminée' et 'Envoyée'.
 * SE SUPPRIME AUTOMATIQUEMENT APRÈS EXÉCUTION.
 */
require_once __DIR__ . '/../includes/config.php';

$db = getDB();

try {
    // 1. On identifie le nom de la contrainte (souvent interventions_statut_check)
    // 2. On la supprime
    // 3. On la recrée avec les nouvelles valeurs autorisées
    
    // PostgreSQL : DROP CONSTRAINT IF EXISTS puis ADD CONSTRAINT
    $db->exec("ALTER TABLE interventions DROP CONSTRAINT IF EXISTS interventions_statut_check");
    
    $db->exec("ALTER TABLE interventions ADD CONSTRAINT interventions_statut_check 
               CHECK (statut IN ('Brouillon', 'Terminee', 'Terminée', 'Envoyee', 'Envoyée'))");

    echo "<div style='font-family:sans-serif; padding:40px; text-align:center;'>
            <h1 style='color:#10b981;'>Base de données mise à jour ! ✅</h1>
            <p>La contrainte de statut accepte désormais les accents.</p>
            <p style='color:#666;'>Ce script s'est auto-supprimé.</p>
            <a href='index.php' style='display:inline-block; margin-top:20px; padding:10px 20px; background:#020617; color:white; text-decoration:none; border-radius:8px;'>Retour</a>
          </div>";

    unlink(__FILE__);
} catch (Exception $e) {
    echo "<div style='font-family:sans-serif; padding:40px; color:#ef4444;'>
            <h1>Erreur technique</h1>
            <p>" . htmlspecialchars($e->getMessage()) . "</p>
          </div>";
    unlink(__FILE__);
}
