<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../includes/config.php';

try {
    $db = getDB();
    
    // On met à jour Pierre LOTITO en Soufyane SALAH pour garder l'historique
    $stmt = $db->prepare("UPDATE users SET nom = 'SALAH', prenom = 'Soufyane' WHERE (nom = 'LOTITO' AND prenom = 'Pierre') OR nom = 'LOTITO'");
    $stmt->execute();
    
    $affected = $stmt->rowCount();
    
    echo "<h1 style='color: green;'>✅ Succès !</h1>";
    echo "<p>Le compte utilisateur a bien été renommé en <strong>Soufyane SALAH</strong>.</p>";
    echo "<p>Historique conservé intact. ($affected ligne(s) modifiée(s)).</p>";
    echo "<a href='/api/technicien.php'>Retourner au tableau de bord</a>";
    
} catch (Exception $e) {
    die("Erreur : " . $e->getMessage());
}
?>
