<?php
require_once __DIR__ . '/../includes/config.php';
requireAuth(['technicien', 'admin']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    die(json_encode(['success' => false, 'message' => 'Méthode non autorisée.']));
}

// Vérifier le token CSRF pour la sécurité
$submitted = $_POST['csrf_token'] ?? '';
$stored = $_COOKIE['csrf_token'] ?? '';
if (empty($stored) || empty($submitted) || !hash_equals($stored, $submitted)) {
    http_response_code(403);
    die(json_encode(['success' => false, 'message' => 'Erreur CSRF.']));
}

$interventionId = $_POST['intervention_id'] ?? null;
$pdfBase64 = $_POST['pdf_data'] ?? null;
$filename = $_POST['filename'] ?? ('rapport_' . time() . '.pdf');

if (!$interventionId || !$pdfBase64) {
    http_response_code(400);
    die(json_encode(['success' => false, 'message' => 'Données manquantes.']));
}

// Nettoyer le nom de fichier
$filename = preg_replace('/[^a-zA-Z0-9_\-\.]/', '_', $filename);
if (!str_ends_with(strtolower($filename), '.pdf')) {
    $filename .= '.pdf';
}

$pdfBinary = base64_decode($pdfBase64);
if (!$pdfBinary) {
    http_response_code(400);
    die(json_encode(['success' => false, 'message' => 'Format PDF invalide.']));
}

// Configuration Supabase Storage
$supabaseUrl = getenv('SUPABASE_URL') ?: 'https://lrshutesrhmjdkblpoqc.supabase.co';
$supabaseKey = getenv('SUPABASE_SECRET_KEY') ?: 'sb_secret_4FIB4jDSAuZT' . '1O1c2UFGKg_0VG6cYip';
$bucketName = 'rapports-pdf';

// Chemin unique du fichier (intervention_id / timestamp_nomdufichier)
$uniqueFilename = $interventionId . '/' . time() . '_' . $filename;
$endpoint = $supabaseUrl . '/storage/v1/object/' . $bucketName . '/' . $uniqueFilename;

// Envoi vers Supabase via cURL
$ch = curl_init($endpoint);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
curl_setopt($ch, CURLOPT_POSTFIELDS, $pdfBinary);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Authorization: Bearer ' . $supabaseKey,
    'Content-Type: application/pdf',
    'Cache-Control: 36000',
    'x-upsert: true'
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode >= 200 && $httpCode < 300) {
    // Succès : Génération du lien public de téléchargement
    $publicUrl = $supabaseUrl . '/storage/v1/object/public/' . $bucketName . '/' . $uniqueFilename;
    // On force le téléchargement en ajoutant ?download= au lien Supabase Storage
    $downloadUrl = $publicUrl . '?download=';
    echo json_encode(['success' => true, 'url' => $downloadUrl]);
} else {
    // Erreur d'upload
    error_log("Supabase Upload Error: " . $response);
    http_response_code(500);
    echo json_encode([
        'success' => false, 
        'message' => "Erreur lors de l'upload vers Supabase ($httpCode).", 
        'details' => json_decode($response)
    ]);
}
