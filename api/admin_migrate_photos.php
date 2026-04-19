<?php
/**
 * Trigger de migration des photos via navigateur
 * À utiliser une seule fois pour convertir les anciennes données.
 */
require_once __DIR__ . '/../includes/config.php';
requireAuth(['admin']); // Sécurité : Seul un admin peut lancer la migration

echo "<!DOCTYPE html>
<html lang='fr'>
<head>
    <meta charset='UTF-8'>
    <title>Migration Photos - Lenoir-Mec</title>
    <style>
        body { font-family: sans-serif; padding: 20px; line-height: 1.6; background: #f4f7f6; color: #333; }
        .container { max-width: 800px; margin: 0 auto; background: white; padding: 30px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        h1 { color: #d35400; border-bottom: 2px solid #d35400; padding-bottom: 10px; }
        pre { background: #000; color: #0f0; padding: 15px; border-radius: 5px; overflow-x: auto; font-size: 13px; }
        .btn { display: inline-block; padding: 10px 20px; background: #d35400; color: white; text-decoration: none; border-radius: 5px; margin-top: 20px; }
        .btn:hover { background: #e67e22; }
    </style>
</head>
<body>
    <div class='container'>
        <h1>Migration de la base de données (Photos)</h1>
        <p>Ce script va extraire les photos stockées au format base64 dans la table <code>machines</code> pour les placer dans la nouvelle table <code>machine_photos</code>.</p>
        <p><strong>Note :</strong> Cette opération peut prendre quelques secondes si vous avez beaucoup de données.</p>
        
        <pre>";

// Exécution du script de migration
// On redirige la sortie standard vers php://output pour l'afficher en direct
require_once __DIR__ . '/../scripts/migrate_photos.php';

echo "        </pre>
        
        <p>Migration terminée. Vous pouvez maintenant retourner à l'accueil.</p>
        <a href='/api/technicien.php' class='btn'>Retour au Tableau de Bord</a>
    </div>
</body>
</html>";
