<?php
/**
 * API pour charger les photos d'une machine séparément
 * Évite l'erreur Vercel "FUNCTION_RESPONSE_PAYLOAD_TOO_LARGE" (limite 4.5MB)
 */
require_once __DIR__ . '/../includes/config.php';
requireAuth(['technicien', 'admin']);

$id = $_GET['id'] ?? null;
if (!$id) {
    http_response_code(400);
    echo json_encode(['error' => 'ID manquant']);
    exit;
}

$db = getDB();
$stmt = $db->prepare('SELECT photos FROM machines WHERE id = ?');
$stmt->execute([$id]);
$row = $stmt->fetch();

header('Content-Type: application/json');
if (!$row || empty($row['photos'])) {
    echo '{}';
} else {
    echo $row['photos'];
}
