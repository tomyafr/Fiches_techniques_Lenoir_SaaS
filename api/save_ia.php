<?php
require_once __DIR__ . '/../includes/config.php';
requireAuth(['technicien', 'admin']);

$db = getDB();

// Handle multiple input formats
$input = file_get_contents('php://input');
$data = json_decode($input, true);

if (!$data) {
    // Fallback to standard $_POST
    $data = $_POST;
}

$id = $data['id'] ?? $data['machine_id'] ?? null;
$type = $data['type'] ?? ''; // 'dysfonctionnements' or 'conclusion'
$content = $data['content'] ?? '';

// Bulk save support (used by generateAllIA)
$dysfonctionnements = $data['dysfonctionnements'] ?? null;
$conclusion = $data['conclusion'] ?? null;

if (!$id) {
    echo json_encode(['success' => false, 'error' => 'Machine ID manquant']);
    exit;
}

try {
    if ($dysfonctionnements !== null && $conclusion !== null) {
        $stmt = $db->prepare("UPDATE machines SET dysfonctionnements = ?, conclusion = ? WHERE id = ?");
        $stmt->execute([$dysfonctionnements, $conclusion, $id]);
    } elseif (in_array($type, ['dysfonctionnements', 'conclusion'])) {
        $stmt = $db->prepare("UPDATE machines SET $type = ? WHERE id = ?");
        $stmt->execute([$content, $id]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Paramètres invalides']);
        exit;
    }
    echo json_encode(['success' => true]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
