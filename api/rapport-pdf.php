<?php
/**
 * Rapport PDF - Téléchargement Public
 * 
 * Point d'accès public pour le téléchargement des rapports PDF.
 * Redirige proprement vers l'URL Supabase Storage sans exposer
 * l'infrastructure technique au client final.
 * 
 * Usage: /api/rapport-pdf.php?id=INTERVENTION_ID&doc=NOM_DU_FICHIER.pdf&t=TIMESTAMP
 */

// Pas de session/auth requise - c'est un lien public pour les clients
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');

// Paramètres requis
$interventionId = $_GET['id'] ?? '';
$document = $_GET['doc'] ?? '';
$timestamp = $_GET['t'] ?? '';

// Validation basique anti-injection
if (empty($interventionId) || empty($document) || empty($timestamp)) {
    http_response_code(400);
    die('<!DOCTYPE html><html lang="fr"><head><meta charset="UTF-8"><title>Lien invalide</title>
    <style>body{font-family:system-ui,sans-serif;display:flex;justify-content:center;align-items:center;min-height:100vh;background:#0a0e1b;color:#f8fafc;margin:0;}
    .card{text-align:center;padding:3rem;border:1px solid rgba(255,255,255,0.1);border-radius:16px;background:rgba(17,24,39,0.85);max-width:500px;}
    h1{color:#f43f5e;font-size:1.5rem;margin-bottom:1rem;}p{color:#94a3b8;line-height:1.6;}</style></head>
    <body><div class="card"><h1>Lien invalide</h1><p>Ce lien de téléchargement est incomplet ou incorrect.<br>Veuillez contacter votre interlocuteur Lenoir-Mec pour obtenir un nouveau lien.</p></div></body></html>');
}

// Sécurisation : uniquement des caractères sûrs
if (!preg_match('/^[0-9]+$/', $interventionId) || !preg_match('/^[0-9]+$/', $timestamp)) {
    http_response_code(400);
    die('Paramètres invalides.');
}

// Nettoyer le nom du document (même logique que le JS côté client)
$safeDocument = preg_replace('/[^a-zA-Z0-9_\-\.]/', '_', $document);

// Construction de l'URL Supabase Storage
$supabaseUrl = 'https://lrshutesrhmjdkblpoqc.supabase.co';
$bucketName = 'rapports-pdf';
$filePath = $interventionId . '/' . $timestamp . '_' . $safeDocument;

$downloadUrl = $supabaseUrl . '/storage/v1/object/public/' . $bucketName . '/' . $filePath . '?download=';

// Redirection 302 (temporaire) vers le fichier réel
header('Location: ' . $downloadUrl, true, 302);
header('Cache-Control: no-cache, no-store, must-revalidate');
exit;
