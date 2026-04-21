<?php
require_once __DIR__ . '/../includes/config.php';
$db = getDB();
$stmt = $db->query("SELECT id, nom, prenom, role, actif FROM users");
$rows = $stmt->fetchAll();
header('Content-Type: application/json');
echo json_encode($rows, JSON_PRETTY_PRINT);
