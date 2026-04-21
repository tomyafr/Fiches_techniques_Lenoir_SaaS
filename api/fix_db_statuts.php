<?php
/**
 * Script de maintenance d'urgence pour corriger les accents.
 * SE SUPPRIME AUTOMATIQUEMENT APRÈS EXÉCUTION.
 */
require_once __DIR__ . '/../includes/config.php';

$db = getDB();

try {
    $db->beginTransaction();

    // Fix Terminée
    $stmt1 = $db->prepare("UPDATE interventions SET statut = 'Terminée' WHERE statut = 'Terminee'");
    $stmt1->execute();
    $count1 = $stmt1->rowCount();

    // Fix Envoyée
    $stmt2 = $db->prepare("UPDATE interventions SET statut = 'Envoyée' WHERE statut = 'Envoyee'");
    $stmt2->execute();
    $count2 = $stmt2->rowCount();

    $db->commit();

    echo "<div style='font-family:sans-serif; padding:40px; text-align:center;'>
            <h1 style='color:#10b981;'>Maintenance Terminée ! ✅</h1>
            <p style='font-size:1.2rem; color:#444;'>
                - <b>$count1</b> fiches ont été corrigées en 'Terminée'.<br>
                - <b>$count2</b> fiches ont été corrigées en 'Envoyée'.
            </p>
            <p style='color:#666;'>Ce script s'est auto-supprimé pour des raisons de sécurité.</p>
            <a href='index.php' style='display:inline-block; margin-top:20px; padding:10px 20px; background:#020617; color:white; text-decoration:none; border-radius:8px;'>Retour à l'accueil</a>
          </div>";

    // Auto-suppression
    unlink(__FILE__);

} catch (Exception $e) {
    if ($db->inTransaction()) $db->rollBack();
    echo "<h1 style='color:#ef4444;'>Erreur lors de la maintenance : " . $e->getMessage() . "</h1>";
}
