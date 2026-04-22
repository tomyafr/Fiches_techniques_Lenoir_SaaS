<?php
require_once __DIR__ . '/../includes/config.php';
$db = getDB();

try {
    // 1. Supprimer les anciens comptes admin potentiels pour repartir de zéro
    $db->prepare("DELETE FROM users WHERE nom IN ('TG', 'ADMIN', 'admin')")->execute();
    
    // 2. Créer le nouveau compte admin propre
    $pass = 'admin123';
    $hash = password_hash($pass, PASSWORD_BCRYPT, ['cost' => 12]);
    
    $stmt = $db->prepare("INSERT INTO users (nom, prenom, password_hash, role, actif, must_change_password) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->execute(['ADMIN', 'Admin', $hash, 'admin', true, false]);
    
    echo "RESET COMPLET REUSSI :\n";
    echo "Identifiant : ADMIN\n";
    echo "Mot de passe : admin123\n";
    echo "Anciens comptes TG/ADMIN/admin supprimés.\n";
} catch (Exception $e) {
    echo "ERREUR LORS DU RESET : " . $e->getMessage();
}
