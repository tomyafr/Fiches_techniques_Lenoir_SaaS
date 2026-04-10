<?php
require_once __DIR__ . '/../includes/config.php';
requireAuth(['technicien', 'admin']);

$db = getDB();
$machineId = $_GET['machine_id'] ?? null;
$key = $_GET['key'] ?? null;
$photoId = $_GET['photo_id'] ?? null;

if (!$machineId || !$key || !$photoId) {
    die("Paramètres manquants.");
}

$stmt = $db->prepare('SELECT photos FROM machines WHERE id = ?');
$stmt->execute([$machineId]);
$m = $stmt->fetch();

if (!$m) die("Machine introuvable.");

$photosData = json_decode($m['photos'] ?? '{}', true);
$found = null;

if (isset($photosData[$key])) {
    foreach ($photosData[$key] as $p) {
        if ($p['id'] == $photoId) {
            $found = $p['data'];
            break;
        }
    }
}

if ($found && preg_match('/^data:([^;]+);base64,(.*)$/', $found, $matches)) {
    $mimeType = $matches[1];
    $data = base64_decode($matches[2]);
    header('Content-Type: ' . $mimeType);
    echo $data;
} else {
    header('HTTP/1.0 404 Not Found');
}
