<?php
require_once __DIR__ . '/../includes/config.php';

$code = intval($_GET['code'] ?? 404);
$messages = [
    403 => "Accès refusé. Vous n'avez pas l'autorisation d'accéder à cette ressource.",
    404 => "Page introuvable. La ressource que vous cherchez a été déplacée ou n'existe plus.",
    500 => "Erreur interne du serveur. Notre équipe technique a été notifiée."
];

$title = $code === 403 ? "Accès Refusé" : ($code === 404 ? "Introuvable" : "Erreur Serveur");
$message = $messages[$code] ?? "Une erreur inattendue est survenue.";

http_response_code($code);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Erreur <?= $code ?> | LM Expert</title>
    <link rel="stylesheet" href="/assets/style.css">
    <link rel="apple-touch-icon" href="/assets/icon-192.png">
    <meta name="theme-color" content="#020617">
    <style>
        body {
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            text-align: center;
            padding: 2rem;
            background: #020617;
            background-image: 
                radial-gradient(circle at 15% 50%, rgba(255, 179, 0, 0.08), transparent 25%),
                radial-gradient(circle at 85% 30%, rgba(14, 165, 233, 0.08), transparent 25%);
        }
        .error-card {
            background: var(--glass-bg, rgba(255, 255, 255, 0.03));
            border: 1px solid var(--glass-border, rgba(255, 255, 255, 0.05));
            border-radius: 16px;
            padding: 3rem 2rem;
            max-width: 500px;
            width: 100%;
            backdrop-filter: blur(20px);
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.5);
            animation: slideUp 0.6s ease-out forwards;
        }
        .error-code {
            font-size: 6rem;
            font-weight: 900;
            background: linear-gradient(135deg, #ffb300, #f59e0b);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            margin: 0;
            line-height: 1;
            filter: drop-shadow(0 10px 15px rgba(255, 179, 0, 0.2));
        }
        .error-title {
            font-size: 1.5rem;
            font-weight: 700;
            color: #f8fafc;
            margin: 1rem 0;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        .error-desc {
            color: #94a3b8;
            font-size: 1rem;
            line-height: 1.6;
            margin-bottom: 2rem;
        }
        @keyframes slideUp {
            from { transform: translateY(30px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }
    </style>
</head>
<body>
    <div class="error-card">
        <img src="/assets/lenoir_logo_trans.svg" alt="Raoul Lenoir" style="height: 40px; margin-bottom: 2rem; opacity: 0.8;">
        
        <h1 class="error-code"><?= $code ?></h1>
        <h2 class="error-title"><?= htmlspecialchars($title) ?></h2>
        <p class="error-desc"><?= htmlspecialchars($message) ?></p>
        
        <button onclick="window.history.back()" class="btn btn-ghost" style="margin-right: 1rem; border: 1px solid rgba(255,255,255,0.1);">
            ← Retour
        </button>
        <a href="/" class="btn btn-primary" style="text-decoration: none; display: inline-flex;">
            Tableau de bord
        </a>
    </div>
</body>
</html>
