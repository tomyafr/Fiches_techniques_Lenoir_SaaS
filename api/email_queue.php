<?php
/**
 * email_queue.php
 * Endpoint pour la file d'attente email hors-ligne (Background Sync PWA).
 *
 * Quand le Service Worker détecte la reconnexion, il rejoue les emails
 * en attente stockés dans IndexedDB côté client et les poste ici.
 *
 * POST JSON :
 *   { intervention_id, pdf_data, client_email, csrf_token }
 *
 * Retourne JSON { success, message, email }
 */
require_once __DIR__ . '/../includes/config.php';
requireAuth(['technicien', 'admin']);

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Méthode non autorisée.']);
    exit;
}

// Accepte JSON ou FormData
$contentType = $_SERVER['CONTENT_TYPE'] ?? '';
if (strpos($contentType, 'application/json') !== false) {
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true) ?? [];
    $_POST = $data;
    // Simuler le cookie CSRF via le champ JSON
    if (isset($data['csrf_token']) && !isset($_COOKIE['csrf_token'])) {
        $_COOKIE['csrf_token'] = $data['csrf_token'];
    }
}

verifyCsrfToken();

// Déléguer à envoyer_rapport.php en incluant le fichier
// On re-route les paramètres correctement
$_POST['intervention_id'] = $_POST['intervention_id'] ?? 0;
$_POST['pdf_data'] = $_POST['pdf_data'] ?? '';
$_POST['client_email'] = $_POST['client_email'] ?? '';

include __DIR__ . '/envoyer_rapport.php';
