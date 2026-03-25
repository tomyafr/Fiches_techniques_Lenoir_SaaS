<?php
require_once __DIR__ . '/../includes/config.php';
requireAuth(['technicien', 'admin']);

$db = getDB();
$data = json_decode(file_get_contents('php://input'), true);

$id = $data['id'] ?? null;
$type = $data['type'] ?? ''; // 'dysfonctionnements' or 'conclusion'
$content = $data['content'] ?? '';

if (!$id || !in_array($type, ['dysfonctionnements', 'conclusion'])) {
    echo json_encode(['error' => 'Paramètres invalides']);
    exit;
}

try {
    $stmt = $db->prepare("UPDATE machines SET $type = ? WHERE id = ?");
    $stmt->execute([$content, $id]);
    echo json_encode(['success' => true]);
} catch (Exception $e) {
    echo json_encode(['error' => $e->getMessage()]);
}
