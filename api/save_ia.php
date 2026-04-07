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

// Protection CSRF depuis Fetch
$submittedToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($data['csrf_token'] ?? '');
$storedToken = $_COOKIE['csrf_token'] ?? '';
if (empty($storedToken) || empty($submittedToken) || !hash_equals($storedToken, $submittedToken)) {
    echo json_encode(['success' => false, 'error' => 'Token CSRF invalide.']);
    exit;
}

if (!$id) {
    echo json_encode(['success' => false, 'error' => 'Machine ID manquant']);
    exit;
}

// Protection IDOR: Vérification de l'appartenance de la machine à l'intervention du technicien
$stmtAuth = $db->prepare('SELECT i.technicien_id FROM machines m JOIN interventions i ON m.intervention_id = i.id WHERE m.id = ?');
$stmtAuth->execute([$id]);
$machineAuth = $stmtAuth->fetch();

if (!$machineAuth) {
    echo json_encode(['success' => false, 'error' => 'Machine introuvable']);
    exit;
}
if ($_SESSION['role'] !== 'admin' && $machineAuth['technicien_id'] !== $_SESSION['user_id']) {
    echo json_encode(['success' => false, 'error' => 'Accès non autorisé à cette machine']);
    exit;
}

// Bulk save support (used by generateAllIA)
$dysfonctionnements = $data['dysfonctionnements'] ?? null;
$conclusion = $data['conclusion'] ?? null;

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
