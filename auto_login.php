<?php
require_once __DIR__ . '/includes/config.php';
$db = getDB();
$user = $db->query("SELECT * FROM users WHERE role = 'admin' LIMIT 1")->fetch();
if ($user) {
    $_SESSION['user_id'] = $user['id'];
    $_SESSION['role'] = $user['role'];
    
    // Find a random intervention
    $int = $db->query("SELECT id FROM interventions LIMIT 1")->fetch();
    if ($int) {
        header('Location: api/rapport_final.php?id=' . $int['id']);
        exit;
    }
}
echo "No data";
