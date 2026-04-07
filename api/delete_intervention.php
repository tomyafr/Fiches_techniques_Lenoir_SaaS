<?php
require_once __DIR__ . '/../includes/config.php';
requireAuth(['admin', 'technicien']);

$id = intval($_GET['id'] ?? 0);
if (!$id) {
    header('Location: historique.php?msg=Invalid+ID');
    exit;
}

$submittedToken = $_GET['csrf_token'] ?? '';
$storedToken = $_COOKIE['csrf_token'] ?? '';
if (empty($storedToken) || empty($submittedToken) || !hash_equals($storedToken, $submittedToken)) {
    die('Securite: Jeton CSRF invalide.');
}

$db = getDB();

// Vérifier les droits
$stmt = $db->prepare("SELECT technicien_id FROM interventions WHERE id = ?");
$stmt->execute([$id]);
$intervention = $stmt->fetch();

if (!$intervention) {
    header('Location: historique.php?msg=Introuvable');
    exit;
}

if ($_SESSION['role'] !== 'admin' && $intervention['technicien_id'] !== $_SESSION['user_id']) {
    header('Location: historique.php?msg=Non+Autorise');
    exit;
}

// Supprimer l'intervention (les machines seront supprimées via un LEFT JOIN ou doivent être supprimées manuellement)
$db->beginTransaction();
try {
    $db->prepare("DELETE FROM machines WHERE intervention_id = ?")->execute([$id]);
    $db->prepare("DELETE FROM interventions WHERE id = ?")->execute([$id]);

    logAudit('DELETE_INTERVENTION', "Intervention ID: $id deleted by user " . $_SESSION['user_id']);

    $db->commit();
    header('Location: ' . ($_SERVER['HTTP_REFERER'] ?? 'historique.php') . '?msg=Fiche+supprimee');
} catch (Exception $e) {
    $db->rollBack();
    header('Location: ' . ($_SERVER['HTTP_REFERER'] ?? 'historique.php') . '?msg=Erreur');
}
exit;
