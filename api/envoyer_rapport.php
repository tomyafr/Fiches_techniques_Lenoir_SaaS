<?php
/**
 * envoyer_rapport.php
 * Endpoint AJAX pour envoyer le rapport PDF par email au client dès la signature.
 *
 * POST :
 *   - intervention_id  : int
 *   - pdf_data         : string  (PDF en base64, sans le préfixe data:...)
 *   - client_email     : string
 *
 * Retourne JSON { success, message, email }
 */
require_once __DIR__ . '/../includes/config.php';
requireAuth(['technicien', 'admin']);

header('Content-Type: application/json');

// ── Validation entrée ─────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Méthode non autorisée.']);
    exit;
}

verifyCsrfToken();

$interventionId = (int) ($_POST['intervention_id'] ?? 0);
$pdfData = $_POST['pdf_data'] ?? '';       // base64 PDF
$clientEmail = trim($_POST['client_email'] ?? '');

if (!$interventionId || !$pdfData || !$clientEmail) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Paramètres manquants.']);
    exit;
}

if (!filter_var($clientEmail, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Adresse email client invalide.']);
    exit;
}

// ── Récupération des données de l'intervention ────────────────────────────────
$db = getDB();

if ($_SESSION['role'] === 'admin') {
    $stmt = $db->prepare('
        SELECT i.*, c.nom_societe, c.ville,
               u.prenom AS tech_prenom, u.nom AS tech_nom,
               u.email  AS tech_email
        FROM interventions i
        JOIN clients c ON i.client_id = c.id
        JOIN users   u ON i.technicien_id = u.id
        WHERE i.id = ?
    ');
    $stmt->execute([$interventionId]);
} else {
    $stmt = $db->prepare('
        SELECT i.*, c.nom_societe, c.ville,
               u.prenom AS tech_prenom, u.nom AS tech_nom,
               u.email  AS tech_email
        FROM interventions i
        JOIN clients c ON i.client_id = c.id
        JOIN users   u ON i.technicien_id = u.id
        WHERE i.id = ? AND i.technicien_id = ?
    ');
    $stmt->execute([$interventionId, $_SESSION['user_id']]);
}

$intervention = $stmt->fetch();
if (!$intervention) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Intervention introuvable.']);
    exit;
}

// ── Préparation des données email ────────────────────────────────────────────
$nomSociete = $intervention['nom_societe'] ?? 'Client';
$ville = $intervention['ville'] ?? '';
$techPrenom = $intervention['tech_prenom'] ?? '';
$techNom = $intervention['tech_nom'] ?? '';
$techEmail = $intervention['tech_email'] ?? '';
$techFullName = trim("$techPrenom $techNom");
$dateSignature = !empty($intervention['date_signature'])
    ? (new DateTime($intervention['date_signature']))->format('d/m/Y')
    : date('d/m/Y');
$numeroARC = $intervention['numero_arc'] ?? '';

// Destinataires
$expediteur = getenv('MAIL_FROM') ?: 'rapports@lenoir-mec.com';
$expediteurNom = getenv('MAIL_FROM_NAME') ?: 'Lenoir-Mec';
$emailMarie = getenv('MAIL_SAV') ?: 'marie@lenoir-mec.com';  // Responsable SAV
$ccRecipients = [];

if ($techEmail && filter_var($techEmail, FILTER_VALIDATE_EMAIL)) {
    $ccRecipients[] = ['email' => $techEmail, 'name' => $techFullName];
}
$ccRecipients[] = ['email' => $emailMarie, 'name' => 'Marie – SAV Lenoir-Mec'];

// Objet
$subject = "Rapport d'expertise Lenoir-Mec - {$nomSociete} - {$dateSignature}";

// Corps texte
$bodyText = <<<TXT
Madame, Monsieur,

Veuillez trouver ci-joint le rapport d'expertise réalisé par notre technicien {$techFullName} le {$dateSignature} sur votre site de {$ville}.

N'hésitez pas à nous contacter pour toute question.

Cordialement,
L'équipe Lenoir-Mec

--
Lenoir-Mec | Service Après-Vente
Email : {$expediteur}
TXT;

// Corps HTML Premium
$bodyHtml = "
<!DOCTYPE html>
<html lang='fr'>
<head>
    <meta charset='UTF-8'>
    <meta name='viewport' content='width=device-width, initial-scale=1.0'>
    <title>Rapport d'expertise Lenoir-Mec</title>
</head>
<body style='margin: 0; padding: 0; font-family: -apple-system, BlinkMacSystemFont, \"Segoe UI\", Roboto, Helvetica, Arial, sans-serif; background-color: #f8fafc;'>
    <table border='0' cellpadding='0' cellspacing='0' width='100%'>
        <tr>
            <td align='center' style='padding: 40px 0;'>
                <table border='0' cellpadding='0' cellspacing='0' width='600' style='background-color: #ffffff; border-radius: 16px; overflow: hidden; box-shadow: 0 10px 25px rgba(0,0,0,0.05);'>
                    <!-- Header -->
                    <tr>
                        <td align='center' style='padding: 40px 40px 30px 40px; background-color: #020617;'>
                            <h1 style='color: #ffffff; margin: 0; font-size: 24px; font-weight: 800; letter-spacing: -0.5px;'>L<span style='color: #ffb300;'>M</span> EXPERT</h1>
                            <p style='color: rgba(255,255,255,0.6); margin: 5px 0 0 0; font-size: 14px;'>Rapport d'Expertise Technique</p>
                        </td>
                    </tr>
                    <!-- Content -->
                    <tr>
                        <td style='padding: 40px;'>
                            <h2 style='color: #0f172a; margin: 0 0 20px 0; font-size: 20px; font-weight: 700;'>Bonjour,</h2>
                            <p style='color: #475569; margin: 0 0 30px 0; font-size: 16px; line-height: 24px;'>
                                Nous avons le plaisir de vous adresser le rapport technique relatif à l'expertise réalisée sur vos équipements. Ce document détaille l'état de vos machines et nos recommandations de maintenance.
                            </p>
                            
                            <!-- Intervention Details Card -->
                            <div style='background-color: #f1f5f9; border-radius: 12px; padding: 25px; margin-bottom: 30px;'>
                                <table border='0' cellpadding='0' cellspacing='0' width='100%'>
                                    <tr>
                                        <td style='width: 50%; padding-bottom: 15px;'>
                                            <p style='margin: 0; color: #64748b; font-size: 12px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px;'>Technicien</p>
                                            <p style='margin: 4px 0 0 0; color: #0f172a; font-size: 15px; font-weight: 600;'>{$techFullName}</p>
                                        </td>
                                        <td style='width: 50%; padding-bottom: 15px;'>
                                            <p style='margin: 0; color: #64748b; font-size: 12px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px;'>Date</p>
                                            <p style='margin: 4px 0 0 0; color: #0f172a; font-size: 15px; font-weight: 600;'>{$dateSignature}</p>
                                        </td>
                                    </tr>
                                    <tr>
                                        <td colspan='2'>
                                            <p style='margin: 0; color: #64748b; font-size: 12px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px;'>Site d'intervention</p>
                                            <p style='margin: 4px 0 0 0; color: #0f172a; font-size: 15px; font-weight: 600;'>{$nomSociete} - {$ville}</p>
                                        </td>
                                    </tr>
                                </table>
                            </div>

                            <p style='color: #475569; margin: 0 0 30px 0; font-size: 15px; line-height: 22px;'>
                                Vous trouverez le rapport complet en pièce jointe de cet email (format PDF).
                            </p>
                            
                            <p style='color: #475569; margin: 0; font-size: 15px;'>
                                Nos équipes restent à votre entière disposition pour toute information complémentaire.
                                <br><br>
                                Cordialement,<br>
                                <strong>L'équipe SAV Lenoir-Mec</strong>
                            </p>
                        </td>
                    </tr>
                    <!-- Footer -->
                    <tr>
                        <td style='padding: 30px 40px; background-color: #f8fafc; border-top: 1px solid #e2e8f0;'>
                            <table border='0' cellpadding='0' cellspacing='0' width='100%'>
                                <tr>
                                    <td style='color: #94a3b8; font-size: 12px; line-height: 18px;'>
                                        <p style='margin: 0 0 10px 0;'>
                                            <strong>Lenoir-Mec | Maintenance Industrielle</strong><br>
                                            ZI du Béarn, 54400 Cosnes-et-Romain, France<br>
                                            <a href='mailto:{$expediteur}' style='color: #ffb300; text-decoration: none;'>{$expediteur}</a> | <a href='https://www.raoul-lenoir.com' style='color: #ffb300; text-decoration: none;'>www.raoul-lenoir.com</a>
                                        </p>
                                        <p style='margin: 0;'>Ce message automatique vous a été envoyé après signature du rapport d'expertise.</p>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                </table>
                <p style='color: #cbd5e1; font-size: 11px; margin-top: 20px;'>&copy; " . date('Y') . " Lenoir-Mec. Tous droits réservés.</p>
            </td>
        </tr>
    </table>
</body>
</html>
";

// Nom du fichier PDF joint
$pdfFilename = "Rapport_Lenoir_Mec_{$numeroARC}_{$dateSignature}.pdf";
$pdfFilename = preg_replace('/[^A-Za-z0-9_\-.]/', '_', $pdfFilename);

// ── Sélection du service d'envoi ─────────────────────────────────────────────
// Priorité : Simulation (si demandée) > MailerSend > SendGrid > PHPMailer/SMTP > mail() natif
$mailersendKey = getenv('MAILERSEND_API_KEY');
$sendgridKey = getenv('SENDGRID_API_KEY');
$simulationRequested = (isset($_POST['simulation']) && $_POST['simulation'] === '1');

if ($simulationRequested || (empty($mailersendKey) && empty($sendgridKey) && empty(getenv('SMTP_HOST')))) {
    // 🛡️ MODE SIMULATION / PREVIEW
    $result = saveToSimulation(
        $nomSociete,
        $subject,
        $bodyHtml,
        $pdfData,
        $pdfFilename
    );
} elseif (!empty($mailersendKey)) {
    $result = sendViaMailerSend(
        $mailersendKey,
        $expediteur,
        $expediteurNom,
        $clientEmail,
        $ccRecipients,
        $subject,
        $bodyText,
        $bodyHtml,
        $pdfData,
        $pdfFilename
    );
} elseif (!empty($sendgridKey)) {
    $result = sendViaSendGrid(
        $sendgridKey,
        $expediteur,
        $expediteurNom,
        $clientEmail,
        $ccRecipients,
        $subject,
        $bodyText,
        $bodyHtml,
        $pdfData,
        $pdfFilename
    );
} else {
    // Fallback SMTP (PHPMailer si installé, sinon mail() natif)
    $result = sendViaPHPMailerOrNative(
        $expediteur,
        $expediteurNom,
        $clientEmail,
        $ccRecipients,
        $subject,
        $bodyText,
        $bodyHtml,
        $pdfData,
        $pdfFilename
    );
}

// ── Mise à jour du statut ────────────────────────────────────────────────────
if ($result['success']) {
    try {
        $db->prepare("UPDATE interventions SET statut = 'Envoyée', email_envoye_le = NOW(), email_destinataire = ? WHERE id = ?")
            ->execute([$clientEmail, $interventionId]);
    } catch (Exception $e) {
        // La colonne peut ne pas exister encore – on ignore silencieusement
    }
    logAudit('EMAIL_RAPPORT_SENT', "ARC: {$numeroARC} → {$clientEmail}");
}

echo json_encode($result);
exit;


// ════════════════════════════════════════════════════════════════════════════
// FONCTIONS D'ENVOI
// ════════════════════════════════════════════════════════════════════════════

/**
 * Envoi via l'API MailerSend (HTTP cURL, aucune dépendance Composer).
 * Doc : https://developers.mailersend.com/api/v1/email.html
 */
function sendViaMailerSend(
    string $apiKey,
    string $from,
    string $fromName,
    string $toEmail,
    array $ccList,
    string $subject,
    string $textBody,
    string $htmlBody,
    string $pdfBase64,
    string $pdfFilename
): array {

    $cc = [];
    foreach ($ccList as $r) {
        if (!empty($r['email'])) {
            $cc[] = ['email' => $r['email'], 'name' => $r['name']];
        }
    }

    $payload = [
        'from' => ['email' => $from, 'name' => $fromName],
        'to' => [['email' => $toEmail]],
        'subject' => $subject,
        'text' => $textBody,
        'html' => $htmlBody,
        'attachments' => [
            [
                'content' => $pdfBase64,   // MailerSend attend du base64 pur
                'filename' => $pdfFilename,
                'disposition' => 'attachment',
            ]
        ],
    ];

    if (!empty($cc)) {
        $payload['cc'] = $cc;
    }

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
        CURLOPT_TIMEOUT => 30,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);

    if ($curlErr) {
        return ['success' => false, 'message' => "Erreur réseau : $curlErr", 'email' => $toEmail];
    }

    // MailerSend retourne 202 Accepted en cas de succès
    if ($httpCode === 202) {
        return [
            'success' => true,
            'message' => "Rapport envoyé avec succès à {$toEmail}",
            'email' => $toEmail,
        ];
    }

    $decoded = json_decode($response, true);
    $errMsg = $decoded['message'] ?? "Code HTTP $httpCode";
    // Récupérer le détail des erreurs de validation si disponible
    if (!empty($decoded['errors'])) {
        $errDetails = [];
        foreach ($decoded['errors'] as $field => $msgs) {
            $errDetails[] = "$field : " . (is_array($msgs) ? implode(', ', $msgs) : $msgs);
        }
        $errMsg .= ' — ' . implode(' | ', $errDetails);
    }
    return ['success' => false, 'message' => "Erreur MailerSend : $errMsg", 'email' => $toEmail];
}

/**
 * Envoi via l'API SendGrid (HTTP cURL, aucune dépendance Composer).
 */
function sendViaSendGrid(
    string $apiKey,
    string $from,
    string $fromName,
    string $toEmail,
    array $ccList,
    string $subject,
    string $textBody,
    string $htmlBody,
    string $pdfBase64,
    string $pdfFilename
): array {

    $cc = [];
    foreach ($ccList as $r) {
        $cc[] = ['email' => $r['email'], 'name' => $r['name']];
    }

    $payload = [
        'personalizations' => [
            [
                'to' => [['email' => $toEmail]],
                'cc' => $cc ?: null,
                'subject' => $subject,
            ]
        ],
        'from' => ['email' => $from, 'name' => $fromName],
        'reply_to' => ['email' => $from, 'name' => $fromName],
        'content' => [
            ['type' => 'text/plain', 'value' => $textBody],
            ['type' => 'text/html', 'value' => $htmlBody],
        ],
        'attachments' => [
            [
                'content' => $pdfBase64,
                'type' => 'application/pdf',
                'filename' => $pdfFilename,
                'disposition' => 'attachment',
            ]
        ],
    ];

    // Retirer les cc null si vide
    if (empty($cc)) {
        unset($payload['personalizations'][0]['cc']);
    }

    $ch = curl_init('https://api.sendgrid.com/v3/mail/send');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $apiKey,
            'Content-Type: application/json',
        ],
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_TIMEOUT => 30,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);

    if ($curlErr) {
        return ['success' => false, 'message' => "Erreur réseau : $curlErr", 'email' => $toEmail];
    }

    if ($httpCode >= 200 && $httpCode < 300) {
        return [
            'success' => true,
            'message' => "Rapport envoyé avec succès à {$toEmail}",
            'email' => $toEmail,
        ];
    }

    $decoded = json_decode($response, true);
    $errMsg = $decoded['errors'][0]['message'] ?? "Code HTTP $httpCode";
    return ['success' => false, 'message' => "Erreur SendGrid : $errMsg", 'email' => $toEmail];
}

/**
 * Fallback : PHPMailer si disponible, sinon mail() natif.
 */
function sendViaPHPMailerOrNative(
    string $from,
    string $fromName,
    string $toEmail,
    array $ccList,
    string $subject,
    string $textBody,
    string $htmlBody,
    string $pdfBase64,
    string $pdfFilename
): array {

    // ── Tente PHPMailer (si installé via Composer) ──────────────────────────
    $phpmailerPath = __DIR__ . '/../vendor/autoload.php';
    if (file_exists($phpmailerPath)) {
        require_once $phpmailerPath;
        try {
            $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
            $mail->isSMTP();
            $mail->Host = getenv('SMTP_HOST') ?: 'smtp.gmail.com';
            $mail->SMTPAuth = true;
            $mail->Username = getenv('SMTP_USER') ?: $from;
            $mail->Password = getenv('SMTP_PASS') ?: '';
            $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port = (int) (getenv('SMTP_PORT') ?: 587);
            $mail->CharSet = 'UTF-8';

            $mail->setFrom($from, $fromName);
            $mail->addAddress($toEmail);
            foreach ($ccList as $cc) {
                $mail->addCC($cc['email'], $cc['name']);
            }

            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body = $htmlBody;
            $mail->AltBody = $textBody;

            // Pièce jointe PDF depuis base64
            $pdfBinary = base64_decode($pdfBase64);
            $mail->addStringAttachment($pdfBinary, $pdfFilename, \PHPMailer\PHPMailer\PHPMailer::ENCODING_BASE64, 'application/pdf');

            $mail->send();
            return ['success' => true, 'message' => "Rapport envoyé avec succès à {$toEmail}", 'email' => $toEmail];
        } catch (\PHPMailer\PHPMailer\Exception $e) {
            return ['success' => false, 'message' => 'Erreur PHPMailer : ' . $e->getMessage(), 'email' => $toEmail];
        }
    }

    // ── Fallback : mail() natif avec pièce jointe base64 ───────────────────
    $boundary = '----=_Part_' . md5(uniqid('', true));
    $pdfBinary = base64_decode($pdfBase64);
    $pdfEncoded = chunk_split(base64_encode($pdfBinary));

    $ccHeader = '';
    foreach ($ccList as $cc) {
        $ccHeader .= "Cc: {$cc['name']} <{$cc['email']}>\r\n";
    }

    $headers = "From: {$fromName} <{$from}>\r\n";
    $headers .= $ccHeader;
    $headers .= "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: multipart/mixed; boundary=\"{$boundary}\"\r\n";

    $body = "--{$boundary}\r\n";
    $body .= "Content-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n";
    $body .= $textBody . "\r\n";
    $body .= "--{$boundary}\r\n";
    $body .= "Content-Type: application/pdf; name=\"{$pdfFilename}\"\r\n";
    $body .= "Content-Transfer-Encoding: base64\r\n";
    $body .= "Content-Disposition: attachment; filename=\"{$pdfFilename}\"\r\n\r\n";
    $body .= $pdfEncoded . "\r\n";
    $body .= "--{$boundary}--";

    $sent = mail($toEmail, $subject, $body, $headers);
    if ($sent) {
        return ['success' => true, 'message' => "Rapport envoyé avec succès à {$toEmail}", 'email' => $toEmail];
    }
    return ['success' => false, 'message' => 'Échec envoi email (mail()). Vérifiez la config SMTP serveur.', 'email' => $toEmail];
}

/**
 * SIMULATION : Retourne l'email dans la réponse pour prévisualisation.
 */
function saveToSimulation($clientName, $subject, $html, $pdfBase64, $pdfFilename): array {
    // On crée une petite page pour voir à la fois le mail et un lien vers le PDF
    // On l'envoie directement en string car le FS Vercel est read-only
    $previewHtml = "
    <!DOCTYPE html>
    <html>
    <head>
        <title>LM Expert - Simulation Envoi Email</title>
        <meta name='viewport' content='width=device-width, initial-scale=1.0'>
        <style>
            body { font-family: -apple-system, sans-serif; background: #020617; color: white; padding: 20px; margin: 0; }
            .meta-box { background: rgba(255,179,0,0.1); border: 1px solid rgba(255,179,0,0.3); padding: 1.5rem; border-radius: 12px; margin-bottom: 2rem; max-width: 800px; margin-left: auto; margin-right: auto; }
            .email-container { display: flex; justify-content: center; padding-bottom: 50px; }
            .email-frame { background: white; border: none; width: 100%; max-width: 700px; height: 1000px; border-radius: 12px; box-shadow: 0 30px 60px rgba(0,0,0,0.5); }
            .btn-pdf { display: inline-flex; align-items: center; gap: 8px; background: #ffb300; color: #020617; text-decoration: none; padding: 12px 24px; border-radius: 8px; font-weight: bold; margin-top: 15px; font-size: 14px; transition: 0.3s; }
            .btn-pdf:hover { background: #ffc433; transform: translateY(-2px); }
        </style>
    </head>
    <body>
        <div class='meta-box'>
            <h2 style='margin-top:0; color: #ffb300; display: flex; align-items: center; gap: 10px;'>
                <span>🧪</span> Mode Simulation / Preview
            </h2>
            <p style='margin: 10px 0; font-size: 14px; color: #94a3b8;'>
                Cet écran simule l'email qui sera envoyé au client. Utilisez ceci pour valider le design premium et la pièce jointe.
            </p>
            <hr style='border: none; border-top: 1px solid rgba(255,255,255,0.1); margin: 1.5rem 0;'>
            <p style='margin: 5px 0;'><strong>Sujet :</strong> " . htmlspecialchars($subject) . "</p>
            <p style='margin: 5px 0;'><strong>Destinataire :</strong> Simulation pour {$clientName}</p>
            <a href='data:application/pdf;base64,{$pdfBase64}' download='{$pdfFilename}' class='btn-pdf'>
                📥 Télécharger le Rapport PDF (Attachement)
            </a>
        </div>
        <div class='email-container'>
            <iframe class='email-frame' srcdoc=\"" . htmlspecialchars($html, ENT_QUOTES) . "\"></iframe>
        </div>
    </body>
    </html>
    ";

    return [
        'success' => true, 
        'message' => '🧪 Mode Simulation : Le mail n\'a pas été envoyé réellement (aucun service configuré), mais vous pouvez voir le rendu ci-dessous.',
        'simulation_html' => $previewHtml,
        'email' => 'Simulation @ ' . ($clientName ?: 'Client')
    ];
}
