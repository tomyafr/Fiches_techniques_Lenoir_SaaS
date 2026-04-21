<?php
// Désactiver l'affichage des erreurs pour ne pas corrompre le flux binaire de l'image
ini_set('display_errors', 0);
error_reporting(0);

require_once __DIR__ . '/../includes/config.php';
requireAuth(['technicien', 'admin']);

$db = getDB();

$machineId = isset($_GET['machine_id']) ? intval($_GET['machine_id']) : null;
$photoId = isset($_GET['photo_id']) ? intval($_GET['photo_id']) : null;

if (!$photoId) {
    header('HTTP/1.0 400 Bad Request');
    die("Paramètre id manquant.");
}

// Nettoyage complet du tampon de sortie
while (ob_get_level()) {
    ob_end_clean();
}

$stmt = $db->prepare('SELECT data FROM machine_photos WHERE id = ?');
$stmt->execute([$photoId]);
$found = $stmt->fetchColumn();

if ($found) {
    if (preg_match('/^data:([^;]+);base64,(.*)$/', $found, $matches)) {
        $mimeType = $matches[1];
        $data = base64_decode($matches[2]);
        header('Content-Type: ' . $mimeType);
        header('Content-Length: ' . strlen($data));
        header('Cache-Control: public, max-age=86400');
        echo $data;
    } else {
        // Fallback: Tentative de décodage si c\'est du base64 pur
        $data = base64_decode($found, true);
        if ($data !== false) {
            // Détection basique de signature magique
            if (strpos($data, "\x89PNG") === 0) $mimeType = 'image/png';
            elseif (strpos($data, "\xFF\xD8") === 0) $mimeType = 'image/jpeg';
            elseif (strpos($data, "GIF8") === 0) $mimeType = 'image/gif';
            elseif (strpos($data, "RIFF") === 0 && strpos($data, "WEBP", 8) === 8) $mimeType = 'image/webp';
            else $mimeType = 'image/jpeg'; // Default
            
            header('Content-Type: ' . $mimeType);
            header('Content-Length: ' . strlen($data));
            header('Cache-Control: public, max-age=86400');
            echo $data;
        } else {
            header('HTTP/1.0 404 Not Found');
        }
    }
} else {
    header('HTTP/1.0 404 Not Found');
}
