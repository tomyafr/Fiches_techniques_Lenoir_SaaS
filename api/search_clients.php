<?php
require_once __DIR__ . '/../includes/config.php';
requireAuth(['technicien', 'admin']);

$q = trim($_GET['q'] ?? '');

if (strlen($q) < 2) {
    echo json_encode([]);
    exit;
}

$db = getDB();
$stmt = $db->prepare('SELECT id, nom_societe FROM clients WHERE nom_societe ILIKE ? ORDER BY nom_societe LIMIT 10');
$stmt->execute(['%' . $q . '%']);
$clients = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo json_encode($clients);
