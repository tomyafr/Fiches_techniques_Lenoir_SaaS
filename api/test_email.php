<?php
/**
 * test_email.php — Script de test d'envoi email MailerSend
 * Accès réservé aux admins uniquement.
 * URL : https://ton-app.vercel.app/test_email.php
 *
 * ⚠️ SUPPRIMER CE FICHIER après validation en production !
 */
require_once __DIR__ . '/../includes/config.php';
requireAuth(['admin', 'technicien']);

$result = null;
$testMode = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_test'])) {
    verifyCsrfToken();
    $testMode = true;
    $toEmail = trim($_POST['to_email'] ?? '');
    $apiKey = getenv('MAILERSEND_API_KEY');
    $fromEmail = getenv('MAIL_FROM') ?: 'non configuré';
    $fromName = getenv('MAIL_FROM_NAME') ?: 'MVP Lenoir-Mec';

    if (!$apiKey) {
        $result = ['ok' => false, 'msg' => '❌ MAILERSEND_API_KEY non trouvée dans les variables d\'environnement.'];
    } elseif (!filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
        $result = ['ok' => false, 'msg' => '❌ Adresse email destinataire invalide.'];
    } else {
        // Appel API MailerSend (email simple, sans pièce jointe)
        $payload = [
            'from' => ['email' => $fromEmail, 'name' => $fromName],
            'to' => [['email' => $toEmail]],
            'subject' => '✅ Test envoi email — MVP Lenoir-Mec',
            'text' => "Bonjour,\n\nCeci est un email de test envoyé depuis le MVP Lenoir-Mec.\n\nSi vous recevez cet email, la configuration MailerSend fonctionne correctement.\n\nExpéditeur configuré : {$fromEmail}\nDate du test : " . date('d/m/Y à H:i:s') . "\n\nCordialement,\nL'équipe Lenoir-Mec",
            'html' => "
                <div style='font-family:Arial,sans-serif;max-width:600px;margin:0 auto;padding:24px;'>
                  <div style='background:#020617;padding:20px;border-radius:12px;text-align:center;margin-bottom:24px;'>
                    <h1 style='color:#ffb300;margin:0;font-size:1.2rem;'>LENOIR-MEC</h1>
                    <p style='color:#94a3b8;font-size:0.8rem;margin:4px 0 0;'>MVP — Test de configuration email</p>
                  </div>
                  <h2 style='color:#10b981;'>✅ Email de test reçu avec succès !</h2>
                  <p>Bonjour,</p>
                  <p>Ceci est un email de test envoyé depuis le MVP Lenoir-Mec.<br>
                  La configuration MailerSend fonctionne correctement.</p>
                  <table style='width:100%;border-collapse:collapse;margin:16px 0;'>
                    <tr style='background:#f1f5f9;'><td style='padding:8px 12px;font-weight:bold;'>Expéditeur</td><td style='padding:8px 12px;'>{$fromEmail}</td></tr>
                    <tr><td style='padding:8px 12px;font-weight:bold;'>Destinataire</td><td style='padding:8px 12px;'>{$toEmail}</td></tr>
                    <tr style='background:#f1f5f9;'><td style='padding:8px 12px;font-weight:bold;'>Date du test</td><td style='padding:8px 12px;'>" . date('d/m/Y à H:i:s') . "</td></tr>
                  </table>
                  <p style='color:#64748b;font-size:0.85rem;'>Les vrais rapports PDF seront envoyés de la même manière, avec le PDF en pièce jointe.</p>
                  <hr style='border:none;border-top:1px solid #e2e8f0;margin:24px 0;'>
                  <p style='color:#94a3b8;font-size:0.75rem;text-align:center;'>Lenoir-Mec — MVP en cours de développement</p>
                </div>
            ",
        ];

        $ch = curl_init('https://api.mailersend.com/v1/email');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $apiKey,
                'Content-Type: application/json',
                'X-Requested-With: XMLHttpRequest',
            ],
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_TIMEOUT => 15,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);
        curl_close($ch);

        if ($curlErr) {
            $result = ['ok' => false, 'msg' => "❌ Erreur réseau cURL : $curlErr"];
        } elseif ($httpCode === 202) {
            $result = ['ok' => true, 'msg' => "✅ Email envoyé avec succès à <strong>{$toEmail}</strong> ! Vérifie ta boîte mail (et les spams)."];
            logAudit('EMAIL_TEST_SENT', "Test email → $toEmail");
        } else {
            $decoded = json_decode($response, true);
            $errMsg = $decoded['message'] ?? "Code HTTP $httpCode";
            
            // Check if it returned a simulation HTML (handled by our new backend logic)
            if (isset($decoded['simulation_html'])) {
                $jsHtml = json_encode($decoded['simulation_html']);
                $result = [
                    'ok' => true, 
                    'msg' => "🧪 <strong>SIMULATION :</strong> " . $decoded['message'] . " <br><br><button onclick='showSim()' class='btn' style='display:inline-block; margin-top:10px; background:#ffb300; color:#020617; border:none; cursor:pointer;'>👁️ Voir la simulation</button><script>function showSim(){ const sw=window.open(\"\",\"_blank\"); sw.document.write($jsHtml); sw.document.close(); }</script>",
                    'sim_html' => $decoded['simulation_html']
                ];
            } else {
                if (!empty($decoded['errors'])) {
                    $details = [];
                    foreach ($decoded['errors'] as $field => $msgs) {
                        $details[] = "$field : " . (is_array($msgs) ? implode(', ', $msgs) : $msgs);
                    }
                    $errMsg .= '<br>Détails : ' . implode('<br>', $details);
                }
                $result = ['ok' => false, 'msg' => "❌ Erreur MailerSend (HTTP $httpCode) : $errMsg"];
            }
        }
    }
}

// Récupère les variables configurées pour les afficher
$apiKey = getenv('MAILERSEND_API_KEY') ?: null;
$fromEmail = getenv('MAIL_FROM') ?: null;
$fromName = getenv('MAIL_FROM_NAME') ?: null;
$mailSav = getenv('MAIL_SAV') ?: null;
?>
<!DOCTYPE html>
<html lang="fr">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Test Email — MVP Lenoir-Mec</title>
    <link rel="stylesheet" href="/assets/style.css">
    <style>
        .test-page {
            max-width: 640px;
            margin: 0 auto;
            padding: 2rem 1.5rem 4rem;
            padding-top: 5rem;
        }

        .check-row {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.75rem 1rem;
            border-radius: 8px;
            margin-bottom: 0.5rem;
            background: rgba(15, 23, 42, 0.3);
            border: 1px solid var(--glass-border);
            font-size: 0.85rem;
        }

        .check-ok {
            border-color: rgba(16, 185, 129, 0.4);
        }

        .check-fail {
            border-color: rgba(244, 63, 94, 0.4);
        }

        .badge-ok {
            color: #10b981;
            font-size: 1.1rem;
        }

        .badge-fail {
            color: #f43f5e;
            font-size: 1.1rem;
        }

        .badge-warn {
            color: #f59e0b;
            font-size: 1.1rem;
        }

        .result-box {
            padding: 1.25rem 1.5rem;
            border-radius: 10px;
            margin: 1.5rem 0;
            font-size: 0.9rem;
            line-height: 1.6;
        }

        .result-ok {
            background: rgba(16, 185, 129, 0.1);
            border: 1px solid rgba(16, 185, 129, 0.4);
            color: #10b981;
        }

        .result-fail {
            background: rgba(244, 63, 94, 0.1);
            border: 1px solid rgba(244, 63, 94, 0.4);
            color: #f43f5e;
        }

        .warn-box {
            background: rgba(245, 158, 11, 0.1);
            border: 1px solid rgba(245, 158, 11, 0.3);
            color: #f59e0b;
            padding: 1rem;
            border-radius: 8px;
            font-size: 0.8rem;
            margin-bottom: 1.5rem;
        }
    </style>
</head>

<body>
    <header class="mobile-header">
        <a href="technicien.php" class="btn btn-ghost"
            style="padding:0.5rem;color:var(--accent-cyan);text-decoration:none;">← Retour</a>
        <span class="mobile-header-title">Test Email</span>
        <span></span>
    </header>

    <div class="test-page">
        <div class="card glass" style="padding:1.5rem; margin-bottom:1.5rem;">
            <h1 style="font-size:1.1rem; color:var(--primary); margin:0 0 0.25rem;">🧪 Test d'envoi email</h1>
            <p style="color:var(--text-dim); font-size:0.8rem; margin:0;">Vérifie la configuration MailerSend avant de
                déployer en prod.</p>
        </div>

        <!-- Avertissement fichier temporaire -->
        <div class="warn-box">
            ⚠️ <strong>Fichier temporaire.</strong> Supprime <code>api/test_email.php</code> une fois les tests
            terminés.
        </div>

        <!-- Diagnostic des variables d'env -->
        <div class="section-title"
            style="font-size:0.72rem;text-transform:uppercase;letter-spacing:1px;color:var(--text-dim);margin-bottom:0.75rem;">
            Diagnostic configuration</div>

        <div class="check-row <?= $apiKey ? 'check-ok' : 'check-fail' ?>">
            <span class="<?= $apiKey ? 'badge-ok' : 'badge-fail' ?>">
                <?= $apiKey ? '✅' : '❌' ?>
            </span>
            <div>
                <strong>MAILERSEND_API_KEY</strong><br>
                <span style="color:var(--text-dim);">
                    <?= $apiKey ? 'mlsn.' . substr($apiKey, 5, 6) . '...' . substr($apiKey, -4) : 'Non trouvée !' ?>
                </span>
            </div>
        </div>

        <div class="check-row <?= $fromEmail ? 'check-ok' : 'check-fail' ?>">
            <span class="<?= $fromEmail ? 'badge-ok' : 'badge-fail' ?>">
                <?= $fromEmail ? '✅' : '❌' ?>
            </span>
            <div>
                <strong>MAIL_FROM</strong> (expéditeur)<br>
                <span style="color:var(--text-dim);">
                    <?= htmlspecialchars($fromEmail ?? 'Non configuré !') ?>
                </span>
            </div>
        </div>

        <div class="check-row <?= $fromName ? 'check-ok' : 'check-fail' ?>">
            <span class="<?= $fromName ? 'badge-ok' : 'badge-fail' ?>">
                <?= $fromName ? '✅' : '❌' ?>
            </span>
            <div>
                <strong>MAIL_FROM_NAME</strong><br>
                <span style="color:var(--text-dim);">
                    <?= htmlspecialchars($fromName ?? 'Non configuré !') ?>
                </span>
            </div>
        </div>

        <div class="check-row <?= $mailSav ? 'check-ok' : 'check-fail' ?>">
            <span class="<?= $mailSav ? 'badge-ok' : 'badge-fail' ?>">
                <?= $mailSav ? '✅' : '❌' ?>
            </span>
            <div>
                <strong>MAIL_SAV</strong> (CC fixe)<br>
                <span style="color:var(--text-dim);">
                    <?= htmlspecialchars($mailSav ?? 'Non configuré !') ?>
                </span>
            </div>
        </div>

        <!-- Résultat de l'envoi -->
        <?php if ($result): ?>
            <div class="result-box <?= $result['ok'] ? 'result-ok' : 'result-fail' ?>">
                <?= $result['msg'] ?>
            </div>
        <?php endif; ?>

        <!-- Formulaire de test -->
        <div style="margin-top:1.5rem;">
            <div class="section-title"
                style="font-size:0.72rem;text-transform:uppercase;letter-spacing:1px;color:var(--text-dim);margin-bottom:0.75rem;">
                Envoyer un email de test</div>
            <form method="POST">
                <?= csrfField() ?>
                <div class="form-group" style="margin-bottom:1rem;">
                    <label class="label">Adresse de destination (pour le test)</label>
                    <input type="email" name="to_email" class="input" placeholder="ton.email@gmail.com"
                        value="<?= htmlspecialchars($_POST['to_email'] ?? $mailSav ?? '') ?>" required>
                    <p style="font-size:0.75rem; color:var(--text-dim); margin-top:0.4rem;">
                        💡 Mets n'importe quelle adresse — tu seras aussi en CC automatiquement.
                    </p>
                </div>
                <button type="submit" name="send_test" value="1"
                    style="width:100%;padding:1rem;background:linear-gradient(135deg,#3b82f6,#1d4ed8);color:#fff;border:none;border-radius:10px;font-weight:700;font-size:0.95rem;cursor:pointer;">
                    📧 Envoyer l'email de test
                </button>
            </form>
        </div>

        <div style="text-align:center; margin-top:2rem;">
            <a href="technicien.php" style="color:var(--text-dim);font-size:0.8rem;text-decoration:none;">← Retour au
                tableau de bord</a>
        </div>
    </div>
</body>

</html>