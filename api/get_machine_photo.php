<?php
require_once __DIR__ . '/../includes/config.php';
requireAuth(['technicien', 'admin']);

$db = getDB();

// Safety: Ensure table exists
$db->exec("CREATE TABLE IF NOT EXISTS machine_photos (
    id SERIAL PRIMARY KEY,
    machine_id INT NOT NULL REFERENCES machines(id) ON DELETE CASCADE,
    field_key VARCHAR(100) NOT NULL,
    data TEXT NOT NULL,
    caption TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

$machineId = $_GET['machine_id'] ?? null;
$key = $_GET['key'] ?? null;
$photoId = $_GET['photo_id'] ?? null;

if (!$machineId || !$key || !$photoId) {
    die("Paramètres manquants.");
}

$stmt = $db->prepare('SELECT data FROM machine_photos WHERE id = ? AND machine_id = ?');
$stmt->execute([$photoId, $machineId]);
$found = $stmt->fetchColumn();

if ($found && preg_match('/^data:([^;]+);base64,(.*)$/', $found, $matches)) {
    $mimeType = $matches[1];
    $data = base64_decode($matches[2]);
    header('Content-Type: ' . $mimeType);
    echo $data;
} else {
    header('HTTP/1.0 404 Not Found');
}
