<?php
require_once __DIR__ . '/../includes/config.php';
requireAuth(['admin', 'technicien']);

$id = intval($_GET['id'] ?? 0);
if (!$id) {
    header('Location: intervention_edit.php?msg=Invalid+Machine+ID');
    exit;
}

$db = getDB();

// Fetch the machine to know its intervention_id
$stmt = $db->prepare('
    SELECT m.intervention_id, i.technicien_id, i.statut 
    FROM machines m 
    JOIN interventions i ON m.intervention_id = i.id 
    WHERE m.id = ?
');
$stmt->execute([$id]);
$info = $stmt->fetch();

if (!$info) {
    header('Location: intervention_edit.php?msg=Introuvable');
    exit;
}

if ($_SESSION['role'] !== 'admin' && $info['technicien_id'] !== $_SESSION['user_id']) {
    header('Location: intervention_edit.php?id=' . $info['intervention_id'] . '&msg=Non+Autorise');
    exit;
}

// Ensure intervention is not already "Terminee" (optional, but good practice)
if ($info['statut'] === 'Terminee' && $_SESSION['role'] !== 'admin') {
    header('Location: intervention_edit.php?id=' . $info['intervention_id'] . '&msg=Deja+Signee');
    exit;
}

// Delete the machine
$db->prepare("DELETE FROM machines WHERE id = ?")->execute([$id]);
logAudit('DELETE_MACHINE', "Machine ID: $id deleted from Intervention: {$info['intervention_id']} by user " . $_SESSION['user_id']);

header('Location: intervention_edit.php?id=' . $info['intervention_id'] . '&msg=Machine+Supprimee');
exit;
