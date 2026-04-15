<?php
header('Content-Type: text/html; charset=utf-8');
require_once __DIR__ . '/../includes/config.php';
requireAuth(['technicien', 'admin']);

$db = getDB();
$userId = $_SESSION['user_id'];
$id = $_GET['id'] ?? null;

try {
    $db->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS signature_base64 TEXT");
    $db->exec("ALTER TABLE interventions ADD COLUMN IF NOT EXISTS contact_prenom VARCHAR(100)");
    $db->exec("ALTER TABLE interventions ADD COLUMN IF NOT EXISTS contact_nom VARCHAR(100)");
} catch (Exception $e) { }

if (!$id) {
    header('Location: technicien.php');
    exit;
}

// Fetch Intervention + Client
if ($_SESSION['role'] === 'admin') {
    $stmt = $db->prepare('
        SELECT i.*, c.nom_societe, c.adresse, c.code_postal, c.ville, c.pays,
               c.contact_email AS c_email, c.contact_telephone AS c_tel, c.contact_fonction AS c_fonction
        FROM interventions i
        JOIN clients c ON i.client_id = c.id
        WHERE i.id = ?
    ');
    $stmt->execute([$id]);
} else {
    $stmt = $db->prepare('
        SELECT i.*, c.nom_societe, c.adresse, c.code_postal, c.ville, c.pays,
               c.contact_email AS c_email, c.contact_telephone AS c_tel, c.contact_fonction AS c_fonction
        FROM interventions i
        JOIN clients c ON i.client_id = c.id
        WHERE i.id = ? AND i.technicien_id = ?
    ');
    $stmt->execute([$id, $userId]);
}
$intervention = $stmt->fetch();

if (!$intervention) {
    die("Intervention introuvable.");
}

// Fetch machines
$stmtM = $db->prepare('SELECT * FROM machines WHERE intervention_id = ? ORDER BY id');
$stmtM->execute([$id]);
$allMachines = $stmtM->fetchAll();

$machines = array_filter($allMachines, function ($m) {
    $mes = json_decode($m['mesures'] ?? '{}', true);
    return !($mes['excluded'] ?? false);
});
$machines = array_values($machines); // Re-index

// Fetch technicien name and signature
$stmtT = $db->prepare('SELECT prenom, nom, signature_base64 FROM users WHERE id = ?');
$stmtT->execute([$intervention['technicien_id']]);
$tech = $stmtT->fetch();
$techName = ($tech['prenom'] ?? '') . ' ' . ($tech['nom'] ?? '');
$techSignatureBase64 = $tech['signature_base64'] ?? '';

// Handle form POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    verifyCsrfToken();

    if ($_POST['action'] === 'save_rapport') {
        try {
            $contactPrenom = trim($_POST['contact_prenom'] ?? '');
            $contactNom = trim($_POST['contact_nom'] ?? '');
            $contactFonction = trim($_POST['contact_fonction'] ?? '');
            $contactEmail = trim($_POST['contact_email'] ?? '');
            $contactTel = trim($_POST['contact_telephone'] ?? '');
            $nomSignataire = trim($_POST['nom_signataire'] ?? '');

            if (empty($contactPrenom)) { $error = "Le prénom du contact est obligatoire."; }
            elseif (empty($contactNom)) { $error = "Le nom du contact est obligatoire."; }
            elseif (strlen($contactPrenom) > 50) { $error = "Le prénom du contact ne doit pas dépasser 50 caractères."; }
            elseif (strlen($contactNom) > 50) { $error = "Le nom du contact ne doit pas dépasser 50 caractères."; }
            elseif (empty($nomSignataire)) { $error = "Le nom du signataire est obligatoire."; }

            if (isset($error)) { throw new Exception($error); }
            $adresse = trim($_POST['adresse'] ?? '');
            $cp = trim($_POST['code_postal'] ?? '');
            $ville = trim($_POST['ville'] ?? '');
            $pays = trim($_POST['pays'] ?? '');
            $commentaireTech = trim($_POST['commentaire_technicien'] ?? '');
            $commentaireClient = trim($_POST['commentaire_client'] ?? '');

            // PostgreSQL needs 't'/'f' for boolean columns
            $souhaitRapport = isset($_POST['souhait_rapport_unique']) ? 't' : 'f';
            $souhaitPieces = isset($_POST['souhait_offre_pieces']) ? 't' : 'f';
            $souhaitIntervention = isset($_POST['souhait_pieces_intervention']) ? 't' : 'f';
            $souhaitAucune = isset($_POST['souhait_aucune_offre']) ? 't' : 'f';

            $sigClient = $_POST['sigClient'] ?? null;
            $sigTech = $_POST['sigTech'] ?? null;

            // Save new technician signature permanently if present
            if (!empty($sigTech)) {
                try {
                    $db->prepare('UPDATE users SET signature_base64 = ? WHERE id = ?')
                       ->execute([$sigTech, $userId]);
                } catch (Exception $e) {}
            }

            // Update client info
            $db->prepare('UPDATE clients SET adresse = ?, code_postal = ?, ville = ?, pays = ?,
                contact_email = ?, contact_telephone = ?, contact_fonction = ? WHERE id = ?')
                ->execute([$adresse, $cp, $ville, $pays, $contactEmail, $contactTel, $contactFonction, $intervention['client_id']]);

            // Update intervention
            $db->prepare('UPDATE interventions SET
                contact_prenom = ?, contact_nom = ?, commentaire_technicien = ?, commentaire_client = ?,
                souhait_rapport_unique = ?, souhait_offre_pieces = ?,
                souhait_pieces_intervention = ?, souhait_aucune_offre = ?,
                signature_client = ?, signature_technicien = ?,
                nom_signataire_client = ?, date_signature = NOW(),
                statut = ? WHERE id = ?')
                ->execute([
                    $contactPrenom,
                    $contactNom,
                    $commentaireTech,
                    $commentaireClient,
                    $souhaitRapport,
                    $souhaitPieces,
                    $souhaitIntervention,
                    $souhaitAucune,
                    $sigClient,
                    $sigTech,
                    $nomSignataire,
                    'Terminee',
                    $id
                ]);

            logAudit('RAPPORT_FINALIZED', "ARC: " . $intervention['numero_arc']);

            header("Location: rapport_final.php?id=" . $id . "&msg=ok");
            exit;
        } catch (Exception $e) {
            $error = "Erreur lors de la sauvegarde : " . $e->getMessage();
        }
    }
}

$now = date('d/m/Y') . ' à ' . date('H:i');

// --- CALCUL DES STATISTIQUES POUR LA SYNTHÈSE ---
$totalOk = 0;
$totalAmeliorer = 0;
$totalNonConforme = 0;
$totalRemplacer = 0;
$totalNA = 0;
$totalMinutes = 0;
$nbMachinesFilled = 0;
$nbMachinesEmpty = 0;
$emptyMachinesIds = [];

foreach ($machines as &$m) {
    $donnees = json_decode($m['donnees_controle'] ?? '{}', true);
    $mesures = json_decode($m['mesures'] ?? '{}', true);

    // Durée réalisée par machine
    $t = $mesures['temps_realise'] ?? '';
    if (preg_match('/(\d+)\s*h\s*(\d*)/i', $t, $mt)) {
        $totalMinutes += (int) $mt[1] * 60 + (int) ($mt[2] ?: 0);
    } elseif (is_numeric($t)) {
        $totalMinutes += (int) ($t * 60);
    } else {
        // Fallback to hours diff
        $h_deb = $mesures['heure_debut'] ?? '';
        $h_fin = $mesures['heure_fin'] ?? '';
        if (!empty($h_deb) && !empty($h_fin)) {
            $start = strtotime($h_deb);
            $end = strtotime($h_fin);
            if ($end > $start) {
                $totalMinutes += ($end - $start) / 60;
            }
        }
    }

    // États de contrôle
    $pointsCount = 0;
    foreach ($donnees as $k => $v) {
        if (
            strpos($k, '_radio') !== false || strpos($k, '_stat') !== false ||
            preg_match('/^([a-z0-9]{2,10})_(?!.*[Cc]omment).*/', $k)
        ) {

            if (!empty($v) && $v !== 'pc') {
                $pointsCount++;
                if ($v === 'c' || $v === 'bon' || $v === 'OK')
                    $totalOk++;
                elseif ($v === 'aa' || $v === 'r' || $v === 'A améliorer')
                    $totalAmeliorer++;
                elseif ($v === 'nc' || $v === 'hs' || $v === 'Non conforme')
                    $totalNonConforme++;
                elseif ($v === 'nr' || $v === 'A remplacer')
                    $totalRemplacer++;
            }
        }
    }
    $m['points_count'] = $pointsCount;
    if ($pointsCount > 0) {
        $nbMachinesFilled++;
    } else {
        $nbMachinesEmpty++;
        $emptyMachinesIds[] = $m['id'];
    }
}
unset($m);

$h_synth = floor($totalMinutes / 60);
$m_synth = $totalMinutes % 60;

if ($totalMinutes == 0) {
    $dureeSynth = "Non renseigné";
} else {
    $dureeSynth = "";
    if ($h_synth > 0) {
        $dureeSynth .= $h_synth . "h ";
    }
    $dureeSynth .= str_pad($m_synth, 2, '0', STR_PAD_LEFT) . "min";
}

$denom = $totalOk + $totalAmeliorer + $totalNonConforme + $totalRemplacer;
$scoreConformite = $denom > 0 ? round(($totalOk / $denom) * 100) : 0;
?>
<!DOCTYPE html>
<html lang="fr">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0">
    <title>Rapport Final | ARC
        <?= htmlspecialchars($intervention['numero_arc']) ?>
    </title>
    <link rel="stylesheet" href="/assets/style.css">
    <link rel="manifest" href="/manifest.json">
    <meta name="theme-color" content="#020617">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/pdf-lib@1.17.1/dist/pdf-lib.min.js"></script>
    <script>
        const PDF_CHUNK_SIZE = 5;
    </script>
    <style>
        .rapport-page {
            max-width: 720px;
            margin: 0 auto;
            padding: 1.5rem;
            padding-top: 5rem;
            padding-bottom: 6rem;
        }

        .rapport-header {
            text-align: center;
            margin-bottom: 2rem;
            padding: 2.5rem 1.5rem;
            background: rgba(15, 23, 42, 0.4);
            border: 1px solid var(--glass-border);
            border-radius: 16px;
        }

        .rapport-header h1 {
            font-size: 1.5rem;
            color: var(--primary);
            text-transform: uppercase;
            letter-spacing: 1px;
            margin: 0 0 1rem 0;
        }

        .rapport-header .arc-badge {
            display: inline-block;
            background: rgba(255, 179, 0, 0.15);
            color: var(--primary);
            padding: 0.5rem 1.5rem;
            border-radius: 12px;
            font-weight: 800;
            font-family: monospace;
            font-size: 1.2rem;
            border: 1px solid rgba(255, 179, 0, 0.3);
        }

        .section-title {
            font-size: 0.9rem;
            font-weight: 800;
            text-transform: uppercase;
            color: var(--primary);
            letter-spacing: 1px;
            margin: 3rem 0 1.5rem 0;
            padding: 0.75rem 1.25rem;
            background: rgba(255, 179, 0, 0.05);
            border-left: 4px solid var(--primary);
            border-radius: 4px;
        }

        .field-row {
            display: flex;
            gap: 1rem;
            margin-bottom: 0.75rem;
        }

        .field-row .form-group {
            flex: 1;
            margin-bottom: 0;
        }

        .form-group .label {
            font-size: 0.72rem;
            margin-bottom: 0.3rem;
        }

        .form-group .input,
        .form-group textarea {
            font-size: 0.9rem;
        }

        .rapport-textarea {
            width: 100%;
            border: 1px solid var(--glass-border);
            border-radius: 8px;
            background: rgba(15, 23, 42, 0.4);
            color: var(--text);
            padding: 1rem;
            font-family: inherit;
            font-size: 0.9rem;
            resize: vertical;
            outline: none;
            transition: border 0.2s;
        }

        .rapport-textarea:focus {
            border-color: var(--primary);
        }

        .rapport-textarea.large {
            min-height: 250px;
        }

        @media (min-width: 768px) {
            .rapport-textarea.large {
                min-height: 300px;
            }
        }

        .rapport-textarea.small {
            min-height: 150px;
        }

        .checkbox-group {
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
        }

        .checkbox-item {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.75rem 1rem;
            border-radius: 8px;
            background: rgba(15, 23, 42, 0.3);
            border: 1px solid var(--glass-border);
            cursor: pointer;
            transition: all 0.2s;
        }

        .checkbox-item:hover {
            border-color: var(--primary);
            background: rgba(255, 179, 0, 0.05);
        }

        .checkbox-item input[type="checkbox"] {
            width: 20px;
            height: 20px;
            accent-color: var(--primary);
            cursor: pointer;
        }

        .checkbox-item span {
            font-size: 0.9rem;
        }

        .datetime-display {
            text-align: center;
            padding: 1rem;
            background: rgba(15, 23, 42, 0.3);
            border-radius: 8px;
            border: 1px solid var(--glass-border);
            font-family: monospace;
            font-size: 1.1rem;
            color: var(--text);
        }

        .sig-zone {
            margin-bottom: 1.5rem;
        }

        .sig-zone .sig-label {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 0.5rem;
        }

        .sig-zone .sig-label span:first-child {
            font-weight: 600;
            font-size: 0.85rem;
        }

        .sig-zone .sig-clear {
            font-size: 0.75rem;
            color: var(--accent-cyan);
            cursor: pointer;
        }

        .sig-zone canvas {
            background: #fff;
            border-radius: 8px;
            width: 100%;
            cursor: crosshair;
            display: block;
            touch-action: none;
            box-shadow: inset 0 2px 4px rgba(0, 0, 0, 0.1);
        }

        .btn-final {
            width: 100%;
            padding: 1.2rem;
            font-size: 1.05rem;
            font-weight: 700;
            background: linear-gradient(135deg, #10b981, #059669);
            color: #fff;
            border: none;
            border-radius: 12px;
            cursor: pointer;
            transition: all 0.2s;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-top: 1.5rem;
        }

        .btn-final:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(16, 185, 129, 0.35);
        }

        .btn-final:active {
            transform: translateY(0);
        }

        .machines-recap {
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem;
            margin-bottom: 1rem;
        }

        .machines-recap .machine-tag {
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
            padding: 0.3rem 0.7rem;
            border-radius: 6px;
            background: rgba(255, 179, 0, 0.1);
            border: 1px solid rgba(255, 179, 0, 0.2);
            font-size: 0.75rem;
            color: var(--primary);
        }

        /* Ultra-Premium Downloader Overlay */
        #pdfDownloadOverlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(2, 6, 23, 0.85);
            backdrop-filter: blur(20px);
            z-index: 10000;
            display: none;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            color: #fff;
            font-family: 'Outfit', 'Inter', sans-serif;
        }

        .premium-loader-card {
            position: relative;
            width: 90%;
            max-width: 480px;
            padding: 3rem 2.5rem;
            background: rgba(255, 255, 255, 0.03);
            border-radius: 32px;
            border: 1px solid rgba(255, 255, 255, 0.1);
            box-shadow: 0 40px 100px -20px rgba(0, 0, 0, 0.7);
            overflow: hidden;
            text-align: center;
        }

        /* Animated Glowing Border */
        .premium-loader-card::before {
            content: '';
            position: absolute;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: conic-gradient(from 0deg, transparent, rgba(255, 179, 0, 0.2), transparent 40%, #ffb300, transparent 60%);
            animation: rotateBorder 4s linear infinite;
            z-index: -1;
        }

        .premium-loader-card::after {
            content: '';
            position: absolute;
            inset: 2px;
            background: #020617;
            border-radius: 30px;
            z-index: -1;
        }

        @keyframes rotateBorder {
            100% { transform: rotate(360deg); }
        }

        /* Abstract compilation visual */
        .loader-visual {
            position: relative;
            width: 100px;
            height: 100px;
            margin: 0 auto 2rem;
        }

        .visual-circle {
            position: absolute;
            inset: 0;
            border: 2px solid rgba(255, 255, 255, 0.05);
            border-radius: 50%;
        }

        .visual-core {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            width: 40px;
            height: 40px;
            background: radial-gradient(circle, var(--primary), #e67e22);
            border-radius: 50%;
            box-shadow: 0 0 30px var(--primary);
            animation: pulse-core 2s ease-in-out infinite;
        }

        .visual-orbit {
            position: absolute;
            top: 50%;
            left: 50%;
            width: 100%;
            height: 100%;
            margin-top: -50%;
            margin-left: -50%;
            border: 2px solid transparent;
            border-top-color: var(--accent-cyan);
            border-radius: 50%;
            animation: rotate-orbit 3s linear infinite;
        }

        @keyframes pulse-core {
            0%, 100% { transform: translate(-50%, -50%) scale(1); opacity: 0.8; }
            50% { transform: translate(-50%, -50%) scale(1.2); opacity: 1; }
        }

        @keyframes rotate-orbit {
            100% { transform: rotate(360deg); }
        }

        .download-status-text {
            font-size: 1.5rem;
            font-weight: 800;
            margin-bottom: 0.5rem;
            letter-spacing: -0.02em;
            background: linear-gradient(to right, #fff, #fbd38d);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .loader-subtext {
            color: rgba(255, 255, 255, 0.4);
            font-size: 0.95rem;
            margin-bottom: 2rem;
        }

        /* Modern Progress Bar */
        .progress-box {
            position: relative;
            width: 100%;
            height: 8px;
            background: rgba(255, 255, 255, 0.05);
            border-radius: 100px;
            margin-bottom: 1.5rem;
            overflow: hidden;
        }

        .progress-fill {
            position: absolute;
            left: 0;
            top: 0;
            height: 100%;
            width: 0%;
            background: linear-gradient(90deg, #ffb300, #fbd38d);
            border-radius: 100px;
            box-shadow: 0 0 15px rgba(255, 179, 0, 0.5);
            transition: width 0.6s cubic-bezier(0.2, 0.8, 0.2, 1);
        }

        .progress-info {
            display: flex;
            justify-content: space-between;
            font-size: 0.85rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .percent-label { color: var(--primary); }
        .stage-label { color: rgba(255, 255, 255, 0.5); }

        /* Task Indicators */
        .premium-tasks {
            display: flex;
            justify-content: center;
            gap: 20px;
            margin-top: 2.5rem;
        }

        .task-step {
            width: 10px;
            height: 10px;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.1);
            transition: all 0.4s;
        }

        .task-step.active {
            background: var(--primary);
            box-shadow: 0 0 10px var(--primary);
            transform: scale(1.3);
        }

        .task-step.completed {
            background: #10b981;
        }

        @media print {
            .mobile-header, .btn-final, .sig-clear { display: none !important; }
            .rapport-logo { filter: none !important; }
        }

        @media screen {
            .rapport-logo { filter: none; opacity: 1; }
        }
    </style>
</head>

<body>
    <div id="pdfDownloadOverlay">
        <div class="premium-loader-card">
            <div class="loader-visual">
                <div class="visual-circle"></div>
                <div class="visual-orbit"></div>
                <div class="visual-core"></div>
            </div>
            
            <div class="download-status-text">Production du Rapport</div>
            <div class="loader-subtext" id="pdfLoaderSubtext">Optimisation et rendu...</div>

            <div class="progress-box">
                <div class="progress-fill" id="pdfProgressBarFill"></div>
            </div>

            <div class="progress-info">
                <div class="stage-label" id="pdfStageLabel">Phase d'initialisation</div>
                <div class="percent-label" id="pdfPercentLabel">0%</div>
            </div>

            <div class="premium-tasks">
                <div class="task-step" id="step-1"></div>
                <div class="task-step" id="step-2"></div>
                <div class="task-step" id="step-3"></div>
            </div>
        </div>
    </div>
    <style>
        .mobile-header { display: flex !important; }
        .rapport-page { margin-top: calc(var(--mobile-header-height) + 1rem); }
    </style>
    <header class="mobile-header">
        <a href="intervention_edit.php?id=<?= $id ?>" class="btn btn-ghost"
            style="padding: 0.4rem 0.8rem; color: var(--error); text-decoration: none; display:flex; align-items:center; gap:6px; font-weight: 700; font-size: 0.85rem; letter-spacing: 0.5px; border-radius: var(--radius-sm); border: 1px solid rgba(244, 63, 94, 0.2); background: rgba(244, 63, 94, 0.05);">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="m15 18-6-6 6-6"/></svg>
            RETOUR
        </a>
        <span class="mobile-header-title">Rapport Final</span>
        <span class="mobile-header-user"></span>
    </header>

    <div class="rapport-page">
        <form method="POST" id="rapportForm" autocomplete="off">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="save_rapport">

            <?php if (isset($_GET['msg']) && $_GET['msg'] === 'ok'): ?>
                <div id="successBanner"
                    style="background: rgba(16,185,129,0.15); border:1px solid rgba(16,185,129,0.4); color:#10b981; padding:1.5rem; border-radius:12px; margin-bottom:1.5rem; text-align:center;">
                    <div style="margin-bottom:1rem; text-align:center;">
                        <img src="/assets/check_success.svg" style="height: 80px; width: 80px;">
                    </div>
                    <h3 style="margin:0 0 0.5rem 0; color:#10b981;">Rapport finalisé avec succès !</h3>
                    <p style="font-size:0.85rem; color:var(--text-dim); margin-bottom:1rem;">L'intervention ARC
                        <?= htmlspecialchars($intervention['numero_arc']) ?> a été clôturée.
                    </p>

                    <!-- Toast email (injecté par JS) -->
                    <div id="emailToast"
                        style="display:none; margin-bottom:1rem; padding:0.75rem 1rem; border-radius:8px; font-size:0.85rem; font-weight:600;">
                    </div>

                    <div style="display:flex; gap:0.75rem; justify-content:center; flex-wrap:wrap;">
                        <!-- Bouton Envoyer PDF par email -->
                        <button type="button" id="btnSendEmail" onclick="lancerEnvoiEmail()"
                            style="padding:0.7rem 1.5rem; background:linear-gradient(135deg,#3b82f6,#1d4ed8); color:#fff; border:none; border-radius:8px; font-weight:700; cursor:pointer; font-size:0.9rem; display:flex; align-items:center; gap:0.5rem;">
                            <span id="btnSendEmailIcon"><img src="/assets/icon_email_send.svg" style="height: 18px; width: 18px; vertical-align: middle;"></span>
                            <span id="btnSendEmailLabel">Envoyer PDF par email</span>
                        </button>
                        <!-- Bouton Télécharger PDF -->
                        <button type="button" id="btnDownloadPDF" onclick="telechargerPDF()"
                            style="padding:0.7rem 1.5rem; background:var(--primary); color:#000; border:none; border-radius:8px; font-weight:700; cursor:pointer; font-size:0.9rem; display:flex; align-items:center; gap:0.5rem;">
                            <span id="btnDownloadPDFIcon"><img src="/assets/icon_download.svg" style="height: 18px; width: 18px; vertical-align: middle; filter: brightness(0);"></span> 
                            <span id="btnDownloadPDFLabel">Télécharger le PDF</span>
                        </button>
                        <a href="<?= $_SESSION['role'] === 'admin' ? 'admin.php' : 'technicien.php' ?>"
                            style="padding:0.7rem 1.5rem; background:rgba(255,255,255,0.1); color:var(--text); border:1px solid var(--glass-border); border-radius:8px; font-weight:600; text-decoration:none; font-size:0.9rem; display:flex; align-items:center; gap:8px;">
                            <span><img src="/assets/icon_back_green.svg" style="height: 18px; width: 18px; vertical-align: middle;"></span>
                            <span style="color:#27AE60;">Retour au tableau de bord</span>
                        </a>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Données PHP exposées pour le JS -->
            <script>
                window.LM_RAPPORT = {
                    interventionId: <?= (int) $id ?>,
                    clientEmail: <?= json_encode($intervention['contact_email'] ?? $intervention['c_email'] ?? '') ?>,
                    nomSociete: <?= json_encode($intervention['nom_societe'] ?? '') ?>,
                    legal: {
                        address: <?= json_encode(COMPANY_LEGAL_ADDRESS) ?>,
                        contact: <?= json_encode(COMPANY_LEGAL_CONTACT) ?>,
                        siret: <?= json_encode(COMPANY_LEGAL_SIRET) ?>
                    },
                    dateInt: <?= json_encode(date('d/m/Y', strtotime($intervention['date_intervention'] ?? 'now'))) ?>,
                    csrfToken: <?= json_encode(getCsrfToken()) ?>,
                    techName: <?= json_encode($techName) ?>,
                    arc: <?= json_encode($intervention['numero_arc'] ?? '') ?>,
                    synth: {
                        tech: <?= json_encode($techName) ?>,
                        date: <?= json_encode(date('d/m/Y', strtotime($intervention['date_intervention'] ?? 'now'))) ?>,
                        duree: <?= json_encode($dureeSynth) ?>,
                        nbMachines: <?= count($machines) ?>,
                        ok: <?= $totalOk ?>,
                        aa: <?= $totalAmeliorer ?>,
                        nc: <?= $totalNonConforme ?>,
                        nr: <?= $totalRemplacer ?>,
                        na: <?= $totalNA ?>,
                        score: <?= $scoreConformite ?>,
                        nbMachinesFilled: <?= $nbMachinesFilled ?>,
                        nbMachinesEmpty: <?= $nbMachinesEmpty ?>
                    },
                    sigTech: <?= json_encode($intervention['signature_technicien'] ?: $techSignatureBase64) ?>,
                    sigClient: <?= json_encode($intervention['signature_client'] ?? '') ?>,
                    pdfFilename: <?= json_encode('Rapport_Lenoir_Mec_' . preg_replace('/[^A-Za-z0-9_\-]/', '_', $intervention['nom_societe'] ?? 'Client') . '_' . date('d-m-Y') . '.pdf') ?>,
                    emptyFichesOption: 'include',
                    emptyMachinesIds: <?= json_encode($emptyMachinesIds) ?>,
                    machinesIds: [<?= implode(',', array_column($machines, 'id')) ?>],
                    machinesData: <?= json_encode(array_values(array_map(function ($m) use ($intervention) {
                        $mes = json_decode($m['mesures'] ?? '{}', true);
                        return [
                            'id' => $m['id'],
                            'arc' => $intervention['numero_arc'],
                            'of' => $m['numero_of'] ?? '',
                            'designation' => $m['designation'] ?? '',
                            'repere' => $mes['repere'] ?? '—',
                            'annee' => $m['annee_fabrication'] ?? '',
                            'points_count' => $m['points_count'] ?? 0,
                            'dysfonctionnements' => $m['dysfonctionnements'] ?? '',
                            'conclusion' => $m['conclusion'] ?? ''
                        ];
                    }, $machines))) ?>
                };
            </script>

            <?php if (!empty($error)): ?>
                <div
                    style="background: rgba(244,63,94,0.15); border:1px solid rgba(244,63,94,0.4); color:#f43f5e; padding:1rem; border-radius:8px; margin-bottom:1.5rem; font-size:0.85rem; display:flex; align-items:center; gap:10px;">
                    <img src="/assets/icons/warning.png" style="height: 18px; width: 18px;"> <?= htmlspecialchars($error) ?>
                </div>
            <?php endif; ?>

            <!-- ANALYSE IA GÉNÉRALE (Rectangle Orange) -->
            <div id="ai-synthesis-block" class="card" style="background: rgba(255, 179, 0, 0.1); border: 2px solid var(--primary); padding: 1.5rem; margin-bottom: 2rem; border-radius: 12px; position: relative;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem;">
                    <div style="display: flex; align-items: center; gap: 10px;">
                        <img src="/assets/ai_expert.jpg" style="height: 48px; width: 48px; object-fit: cover; border-radius: 8px; border: 2px solid rgba(255,255,255,0.2);">
                        <h2 style="color: var(--primary); margin: 0; font-size: 1.1rem; text-transform: uppercase; letter-spacing: 1px;">Analyse Intelligente Globale</h2>
                    </div>
                    <button type="button" onclick="generateAllIA()" id="btnGenerateAllIa" class="btn btn-primary" style="background: var(--primary); color: #000; font-weight: 700; font-size: 0.8rem; padding: 0.5rem 1rem; border-radius: 8px; display: flex; align-items: center; gap: 8px;">
                        <span>⚙️</span> Lancer l'IA sur toutes les fiches
                    </button>
                </div>
                <p style="font-size: 0.85rem; color: var(--text-dim); margin-bottom: 1rem;">
                    Cette fonction analyse automatiquement chaque fiche pour générer les sections "Dysfonctionnements" et "Conclusions" en se basant sur vos relevés. 
                    <strong>Les fiches déjà modifiées manuellement ne seront pas écrasées sans confirmation.</strong>
                </p>
                <div id="ai-global-progress" style="display: none; margin-top: 1rem;">
                    <div style="display: flex; justify-content: space-between; font-size: 0.75rem; margin-bottom: 5px; color: var(--accent-cyan);">
                        <span id="ai-progress-text">Traitement en cours...</span>
                        <span id="ai-progress-percent">0%</span>
                    </div>
                    <div style="width: 100%; height: 6px; background: rgba(255,255,255,0.1); border-radius: 3px; overflow: hidden;">
                        <div id="ai-progress-bar" style="width: 0%; height: 100%; background: var(--accent-cyan); transition: width 0.3s ease;"></div>
                    </div>
                </div>
            </div>

            <!-- EN-TÊTE -->
            <div class="rapport-header">
                <img src="/assets/lenoir_logo_trans.svg" alt="LENOIR-MEC" 
                    style="height: 60px; width: auto; object-fit: contain; display: block; margin: 0 auto 1.5rem auto;">
                <h1>Rapport d'expertise sur site</h1>
                <div class="arc-badge">
                    ARC <?= htmlspecialchars($intervention['numero_arc']) ?>
                </div>
            </div>

            <!-- RÉCAP MACHINES -->
            <div class="section-title">Équipements contrôlés (<?= count($machines) ?>)</div>
            <div class="machines-recap">
                <?php foreach ($machines as $m): 
                    $mStatus = $m['conclusion'] ?? '';
                    $statusColor = '#94a3b8'; // défaut
                    if (stripos($mStatus, 'conforme') !== false && stripos($mStatus, 'non') === false) $statusColor = '#10b981';
                    if (stripos($mStatus, 'amélioration') !== false) $statusColor = '#f59e0b';
                    if (stripos($mStatus, 'non conforme') !== false) $statusColor = '#f43f5e';
                    if (stripos($mStatus, 'remplacer') !== false) $statusColor = '#8b0000';
                    
                    $mm = json_decode($m['mesures'] ?? '{}', true);
                ?>
                    <span class="machine-tag" style="background: rgba(255,255,255,0.05); border: 1px solid rgba(255,255,255,0.1); padding: 0.4rem 0.8rem; border-radius: 8px; display: inline-flex; align-items:center; gap:8px;">
                        <div style="width:20px; height:20px; background:transparent; border-radius:5px; display:flex; align-items:center; justify-content:center; flex-shrink:0;">
                            <img src="/assets/icon_gear_custom.svg" style="height:16px; width:16px; opacity:0.85;">
                        </div>
                        <strong style="color:#fff; font-size:0.85rem;"><?= htmlspecialchars($m['designation']) ?></strong>
                        <?php if (!empty($mm['repere'])): ?>
                            <small style="opacity:0.6; color:#94a3b8;">– <?= htmlspecialchars($mm['repere']) ?></small>
                        <?php endif; ?>
                        <small style="margin-left: 4px; color: <?= $statusColor ?>; font-weight:bold;">(<?= (int)$m['points_count'] ?> pts)</small>
                    </span>
                <?php endforeach; ?>
            </div>

            <!-- INFORMATIONS CLIENT -->
            <div class="section-title">Informations client</div>
            <div class="card glass" style="padding: 1.5rem;">
                <div class="form-group">
                    <label class="label">Société <span style="color:var(--error);">*</span></label>
                    <input type="text" name="nom_societe_display" class="input"
                        value="<?= htmlspecialchars($intervention['nom_societe']) ?>" disabled style="opacity:0.7;">
                </div>

                <div class="field-row">
                    <div class="form-group">
                        <label class="label">Prénom du contact <span style="color:var(--error);">*</span></label>
                        <input type="text" name="contact_prenom" id="contact_prenom" class="input" placeholder="Prénom..."
                            value="<?= htmlspecialchars($intervention['contact_prenom'] ?? '') ?>" required maxlength="50">
                    </div>
                    <div class="form-group">
                        <label class="label">Nom du contact <span style="color:var(--error);">*</span></label>
                        <input type="text" name="contact_nom" id="contact_nom" class="input" placeholder="Nom..."
                            value="<?= htmlspecialchars($intervention['contact_nom'] ?? '') ?>" required maxlength="50">
                    </div>
                </div>

                <div class="field-row">
                    <div class="form-group">
                        <label class="label">Fonction / Rôle</label>
                        <input type="text" name="contact_fonction" class="input" placeholder="Resp. maintenance..."
                            value="<?= htmlspecialchars(!empty($intervention['contact_fonction']) ? $intervention['contact_fonction'] : ($intervention['c_fonction'] ?? '')) ?>">
                    </div>
                    <div class="form-group">
                        <label class="label">Email <span style="color:var(--error);">*</span></label>
                        <input type="email" name="contact_email" class="input" placeholder="client@societe.com"
                            value="<?= htmlspecialchars(!empty($intervention['contact_email']) ? $intervention['contact_email'] : ($intervention['c_email'] ?? '')) ?>"
                            required>
                    </div>
                </div>

                <div class="field-row">
                    <div class="form-group">
                        <label class="label">Téléphone</label>
                        <input type="tel" name="contact_telephone" class="input" placeholder="06 12 34 56 78"
                            value="<?= htmlspecialchars(!empty($intervention['contact_telephone']) ? $intervention['contact_telephone'] : ($intervention['c_tel'] ?? '')) ?>">
                    </div>
                    <div class="form-group">
                        <label class="label">Adresse</label>
                        <input type="text" name="adresse" class="input" placeholder="Rue, numéro..."
                            value="<?= htmlspecialchars($intervention['adresse'] ?? '') ?>">
                    </div>
                </div>

                <div class="field-row">
                    <div class="form-group">
                        <label class="label">Code postal</label>
                        <input type="text" name="code_postal" class="input" placeholder="75000"
                            value="<?= htmlspecialchars($intervention['code_postal'] ?? '') ?>">
                    </div>
                    <div class="form-group">
                        <label class="label">Ville</label>
                        <input type="text" name="ville" class="input" placeholder="Paris"
                            value="<?= htmlspecialchars($intervention['ville'] ?? '') ?>">
                    </div>
                    <div class="form-group">
                        <label class="label">Pays</label>
                        <input type="text" name="pays" class="input" placeholder="France"
                            value="<?= htmlspecialchars($intervention['pays'] ?? 'France') ?>">
                    </div>
                </div>
            </div>

            <!-- COMMENTAIRE TECHNICIEN -->
            <div class="section-title">Commentaire / Observations du technicien</div>
            <textarea name="commentaire_technicien" class="rapport-textarea large"
                placeholder="Saisissez vos observations générales sur l'état des équipements, les anomalies relevées, les recommandations..."><?= htmlspecialchars($intervention['commentaire_technicien'] ?? '') ?></textarea>

            <!-- LE CLIENT SOUHAITE -->
            <div class="section-title">Le client souhaite</div>
            <div class="checkbox-group">
                <label class="checkbox-item">
                    <input type="checkbox" name="souhait_rapport_unique" value="1" class="chk-souhait"
                        <?= ($intervention['souhait_rapport_unique'] ?? false) ? 'checked' : '' ?>>
                    <span>Ce rapport d'expertise uniquement</span>
                </label>
                <label class="checkbox-item">
                    <input type="checkbox" name="souhait_offre_pieces" value="1" class="chk-souhait"
                        <?= ($intervention['souhait_offre_pieces'] ?? false) ? 'checked' : '' ?>>
                    <span>Une offre de pièces de rechange</span>
                </label>
                <label class="checkbox-item">
                    <input type="checkbox" name="souhait_pieces_intervention" value="1" class="chk-souhait"
                        <?= ($intervention['souhait_pieces_intervention'] ?? false) ? 'checked' : '' ?>>
                    <span>Pièces de rechange + intervention mise en place</span>
                </label>
                <label class="checkbox-item">
                    <input type="checkbox" name="souhait_aucune_offre" value="1" class="chk-souhait"
                        <?= ($intervention['souhait_aucune_offre'] ?? false) ? 'checked' : '' ?>>
                    <span>Aucune offre</span>
                </label>
            </div>

            <script>
                // MF-010: Mutual exclusivity for "Le client souhaite" options (radio-group behavior)
                document.querySelectorAll('.chk-souhait').forEach(chk => {
                    chk.addEventListener('change', function () {
                        if (this.checked) {
                            document.querySelectorAll('.chk-souhait').forEach(other => {
                                if (other !== this) other.checked = false;
                            });
                        }
                    });
                });
            </script>

            <!-- COMMENTAIRE CLIENT -->
            <div class="section-title">Commentaire du client</div>
            <textarea name="commentaire_client" class="rapport-textarea small"
                placeholder="Remarques ou demandes spécifiques du client..."><?= htmlspecialchars($intervention['commentaire_client'] ?? '') ?></textarea>

            <!-- DATE & HEURE -->
            <div class="section-title">Date et heure</div>
            <div class="datetime-display">
                <img src="/assets/icon_calendar_blue.svg" style="height: 24px; width: 24px; vertical-align: middle; margin-right: 8px;">
                <?= $now ?>
            </div>

            <!-- SIGNATURES -->
            <div class="section-title">Signatures</div>
            <div class="card glass" style="padding: 1.5rem;">
                <!-- Signature Technicien -->
                <div class="sig-zone">
                    <div class="sig-label">
                        <span>Signature Technicien</span>
                        <span class="sig-clear" onclick="padTech.clear()">Effacer</span>
                    </div>
                    <div class="form-group" style="margin-bottom: 0.5rem;">
                        <input type="text" class="input" value="<?= htmlspecialchars($techName) ?>" disabled
                            style="opacity:0.7; font-size:0.85rem;">
                    </div>
                    <canvas id="canvasTech" width="600" height="200"></canvas>
                    <input type="hidden" name="sigTech" id="sigTechInput" value="<?= htmlspecialchars($intervention['signature_technicien'] ?: $techSignatureBase64) ?>">
                </div>

                <!-- Signature Client -->
                <div class="sig-zone">
                    <div class="sig-label">
                        <span>Signature Client</span>
                        <span class="sig-clear" onclick="padClient.clear()">Effacer</span>
                    </div>
                    <div class="form-group" style="margin-bottom: 0.5rem;">
                        <input type="text" name="nom_signataire" class="input"
                            placeholder="NOM Prénom du signataire (ex: DUPONT Jean)"
                            value="<?= htmlspecialchars($intervention['nom_signataire_client'] ?? '') ?>" required>
                    </div>
                    <canvas id="canvasClient" width="600" height="200"></canvas>
                    <input type="hidden" name="sigClient" id="sigClientInput">
                </div>
            </div>

            <!-- BOUTON FINAL -->
            <button type="submit" class="btn-final" onclick="return validateAndSubmit()">
                ✓ Finaliser le rapport et terminer l'intervention
            </button>

            <a href="intervention_edit.php?id=<?= $id ?>"
                style="display:block; text-align:center; margin-top:1rem; color:var(--text-dim); font-size:0.85rem; text-decoration:none;">
                ← Retour aux fiches
            </a>
        </form>
    </div>

    <!-- Libs PDF : jsPDF + html2canvas exposes comme globals -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
    <!-- html2pdf.js pour génération PDF côté client -->
    <!-- html2pdf moved to head -->
    <script src="https://cdn.jsdelivr.net/npm/signature_pad@4.1.7/dist/signature_pad.umd.min.js"></script>
    <script>
        let padClient, padTech;

        function resizeCanvas(canvas, pad = null) {
            const ratio = Math.max(window.devicePixelRatio || 1, 1);
            const containerWidth = canvas.offsetWidth || canvas.parentElement.offsetWidth || 600;

            canvas.width = containerWidth * ratio;
            canvas.height = 200 * ratio;
            canvas.style.height = '200px';

            const context = canvas.getContext('2d');
            context.scale(ratio, ratio);

            if (pad && pad.isEmpty()) {
                pad.clear(); // Réinitialise pour éviter les distorsions si on redimensionne
            }
        }

        let canvasWidthT = 0;
        let canvasWidthC = 0;

        function initSignatures() {
            const canvasC = document.getElementById('canvasClient');
            const canvasT = document.getElementById('canvasTech');

            if (!window.SignaturePad) {
                console.error("SignaturePad library not loaded");
                return;
            }
            if (!canvasC || !canvasT) return;

            const dpr = Math.max(window.devicePixelRatio || 1, 1);

            // Tech Pad
            resizeCanvas(canvasT);
            canvasWidthT = canvasT.offsetWidth;
            padTech = new SignaturePad(canvasT, {
                penColor: 'black',
                throttle: 16,
                minWidth: 1.5,
                maxWidth: 4.5
            });

            // Client Pad
            resizeCanvas(canvasC);
            canvasWidthC = canvasC.offsetWidth;
            padClient = new SignaturePad(canvasC, {
                penColor: 'blue',
                throttle: 16,
                minWidth: 1.5,
                maxWidth: 4.5
            });

            // Load initial signatures after pads are created
            if (window.LM_RAPPORT) {
                if (window.LM_RAPPORT.sigTech) {
                    try {
                        padTech.fromDataURL(window.LM_RAPPORT.sigTech, { ratio: dpr, width: canvasT.width / dpr, height: canvasT.height / dpr });
                    } catch (e) { console.error("Error loading tech sig:", e); }
                }
                if (window.LM_RAPPORT.sigClient) {
                    try {
                        padClient.fromDataURL(window.LM_RAPPORT.sigClient, { ratio: dpr, width: canvasC.width / dpr, height: canvasC.height / dpr });
                    } catch (e) { console.error("Error loading client sig:", e); }
                }
            }
        }



        window.addEventListener('resize', () => {
            const cT = document.getElementById('canvasTech');
            const cC = document.getElementById('canvasClient');

            // Only resize and clear if the actual container width changed (to avoid scroll-resize issues on mobile)
            if (cT && padTech && cT.offsetWidth && cT.offsetWidth !== canvasWidthT) {
                canvasWidthT = cT.offsetWidth;
                resizeCanvas(cT, padTech);
            }
            if (cC && padClient && cC.offsetWidth && cC.offsetWidth !== canvasWidthC) {
                canvasWidthC = cC.offsetWidth;
                resizeCanvas(cC, padClient);
            }
        });

        function validateAndSubmit() {
            if (!padClient || !padTech) {
                alert('Erreur: les zones de signature ne sont pas prêtes. Veuillez rafraîchir la page.');
                return false;
            }

            // --- BUG-005, BUG-014, BUG-015: Contrôle de qualité des textes ---
            const fieldsToCheck = [
                { name: 'commentaire_technicien', label: 'Observations du technicien' },
                { name: 'commentaire_client', label: 'Commentaire du client' },
                { name: 'nom_signataire', label: 'Nom du signataire' }
            ];
            const testPatterns = [/test/i, /lorem/i, /(.)\1{4,}/];
            const forbiddenWords = ['nul', 'rien', 'sans', 'na', 'n/a'];

            for (let f of fieldsToCheck) {
                const val = document.querySelector('[name="' + f.name + '"]')?.value || '';
                if (val.length < 2 && val.length > 0) continue; // Skip very shorts handled elsewhere

                let foundMatch = false;
                for (let p of testPatterns) {
                    if (p.test(val)) {
                        foundMatch = true;
                        break;
                    }
                }

                if (!foundMatch && forbiddenWords.includes(val.toLowerCase().trim())) {
                    foundMatch = true;
                }

                if (foundMatch) {
                    if (!confirm("⚠️ Le champ '" + f.label + "' contient des données semblant être du test ou non-professionnelles (\"" + val.substring(0, 20) + "...\"). Voulez-vous vraiment continuer ?")) {
                        return false;
                    }
                }
            }

            const nomSignataire = document.querySelector('[name="nom_signataire"]')?.value.trim() || '';
            if (!nomSignataire) {
                alert('Le nom du signataire est obligatoire.');
                return false;
            }

            // --- BUG-008: Contrôle de complétude des fiches ---
            const machinesData = window.LM_RAPPORT.machinesData;
            for (let m of machinesData) {
                if (m.points_count === 0) {
                    alert('❌ Complétude insuffisante : La fiche machine "' + m.designation + '" est entièrement vide (0 point de contrôle rempli). Veuillez la compléter avant de finaliser.');
                    return false;
                }
            }

            const contactNom = document.getElementById('contact_nom')?.value.trim() || '';
            if (!contactNom) {
                alert('Le nom du contact est obligatoire.');
                return false;
            }

            if (padTech.isEmpty()) {
                alert('Veuillez signer en tant que technicien.');
                return false;
            }
            if (padClient.isEmpty()) {
                alert('Veuillez faire signer le client.');
                return false;
            }

            document.getElementById('sigTechInput').value = padTech.toDataURL();
            document.getElementById('sigClientInput').value = padClient.toDataURL();
            return true;
        }

        document.addEventListener('DOMContentLoaded', function () {
            // --- BUG-018: Exclusivité des checkboxes "Le client souhaite" ---
            const chkUnique = document.querySelector('[name="souhait_rapport_unique"]');
            const otherChks = document.querySelectorAll('[name="souhait_offre_pieces"], [name="souhait_pieces_intervention"], [name="souhait_aucune_offre"]');

            if (chkUnique) {
                chkUnique.addEventListener('change', function () {
                    if (this.checked) {
                        otherChks.forEach(c => c.checked = false);
                    }
                });
                otherChks.forEach(c => {
                    c.addEventListener('change', function () {
                        if (this.checked) {
                            chkUnique.checked = false;
                        }
                    });
                });
            }

            const contactNomInput = document.getElementById('contact_nom');
            const warningEl = document.getElementById('contact_nom_warning');

            if (contactNomInput) {
                contactNomInput.addEventListener('input', function () {
                    const val = this.value;
                    // Detect more than 3 consecutive identical characters
                    if (/(.)\1{3,}/.test(val)) {
                        warningEl.style.display = 'block';
                    } else {
                        warningEl.style.display = 'none';
                    }
                });
            }
        });

        // Initialize signatures when layout is completely established to avoid zero-width bugs
        window.addEventListener('load', () => {
            setTimeout(initSignatures, 100);
        });


        // ══════════════════════════════════════════════════════════════════
        // CRÉATION DU CONTENEUR COMPLET POUR LE PDF (ASYNCHRONE)
        // ══════════════════════════════════════════════════════════════════

        // Helper : Attendre que toutes les images soient chargées
        async function waitForImages(element) {
            const images = element.querySelectorAll('img');
            const promises = Array.from(images).map(img => {
                if (img.complete) return Promise.resolve();
                return new Promise(resolve => {
                    img.onload = resolve;
                    img.onerror = resolve;
                });
            });
            await Promise.all(promises);
            return new Promise(r => setTimeout(r, 200));
        }

        // helper: create footer
        function createPdfFooter() {
            const f = document.createElement('div');
            const leg = window.LM_RAPPORT.legal;
            f.style.marginTop = '30px';
            f.style.width = '100%';
            f.style.textAlign = 'center';
            f.style.fontSize = '9px';
            f.style.fontWeight = 'bold';
            f.style.borderTop = '2px solid #000';
            f.style.paddingTop = '5px';
            f.style.paddingBottom = '5px';
            f.style.pageBreakInside = 'avoid';
            f.innerHTML = `${leg.address}<br>${leg.contact}<br>${leg.siret}`;
            return f;
        }

        async function ensureImagesBase64(container) {
            const imgs = Array.from(container.querySelectorAll('img'));
            const promises = imgs.map(async img => {
                const src = img.src || img.getAttribute('src');
                if (!src || src.startsWith('data:')) return;
                
                try {
                    // Prepend origin if relative
                    let absoluteUrl = src;
                    if (src.startsWith('/') && !src.startsWith('//')) {
                        absoluteUrl = window.location.origin + src;
                    }
                    
                    const resp = await fetch(absoluteUrl);
                    if (!resp.ok) throw new Error('Fetch failed');
                    const blob = await resp.blob();
                    
                    return new Promise((resolve) => {
                        const reader = new FileReader();
                        reader.onload = () => {
                            img.src = reader.result;
                            resolve();
                        };
                        reader.onerror = resolve;
                        reader.readAsDataURL(blob);
                    });
                } catch (e) {
                    console.error('Failed to b64 image: ' + src, e);
                }
            });
            await Promise.all(promises);
        }

        async function buildFullPdfContainer(opts = {}) {
            const includeIntro = opts.includeIntro !== false;
            const includeMachines = opts.includeMachines !== false;
            const includeEnd = opts.includeEnd !== false;
            const targetMachineIds = opts.targetMachineIds || null;
            const startIndex = opts.startIndex || 0;

            const container = document.createElement('div');
            container.id = 'pdf-full-wrapper';
            container.style.width = '210mm';
            container.style.backgroundColor = 'white';
            container.style.color = 'black';

            // --- 0. STYLES SPÉCIFIQUES ---
            const styleNode = document.createElement('style');
            styleNode.textContent = `
                .pdf-page {
                    width: 21cm;
                    min-height: 100px;
                    background: white;
                    color: black;
                    padding: 0 15mm;
                    box-sizing: border-box;
                    margin: 0;
                    font-family: Arial, sans-serif;
                    font-size: 13px;
                    position: relative;
                }
                .pdf-section-title {
                    text-align: left !important;
                    color: #d35400 !important;
                    font-size: 16px !important;
                    margin-bottom: 20px !important;
                    border-bottom: 2px solid #d35400 !important;
                    padding-bottom: 5px !important;
                    text-transform: uppercase !important;
                    font-weight: bold !important;
                    page-break-after: avoid !important;
                }
                .section-wrapper-pdf {
                    page-break-inside: avoid !important;
                    break-inside: avoid !important;
                    margin-top: 25px !important;
                    margin-bottom: 25px !important;
                    display: block !important;
                    width: 100% !important;
                }
                .html2pdf__page-break {
                    height: 1px;
                    width: 100%;
                    overflow: hidden;
                    page-break-before: always !important;
                    break-before: page !important;
                    display: block;
                    clear: both;
                }
                .pdf-table-container, .card, .sig-zone, .photo-annexe-item {
                    page-break-inside: avoid !important;
                }
                tr { 
                    page-break-inside: avoid !important; 
                    page-break-after: auto !important;
                }
                .pdf-table, table.controles, .can-split { 
                    page-break-inside: auto !important; 
                }
                .can-split {
                    page-break-inside: auto !important;
                    border: none !important;
                }
                .can-split tr td { border: 1px solid #000; }
                /* Orange borders only on the very edges of the whole table */
                .can-split tr:first-child td { border-top: 2px solid #F48220 !important; }
                .can-split tr:last-child td { border-bottom: 2px solid #F48220 !important; }
                .can-split tr td:first-child { border-left: 2px solid #F48220 !important; }
                .can-split tr td:last-child { border-right: 2px solid #F48220 !important; }
                .pdf-section {
                    margin-bottom: 15px;
                }
                .pdf-table { width: 100%; border-collapse: collapse; margin-bottom: 20px; color: black; font-size: 11px; table-layout: fixed; }
                .pdf-table th, .pdf-table td { border: 1px solid #000; padding: 4px; vertical-align: middle; word-wrap: break-word; word-break: break-word; }
                .pdf-table thead th { border-top: 1px solid #000 !important; }
                .pdf-table th { background-color: #f0f0f0; text-align: left; text-transform: uppercase; }
                
                .pdf-table .col-comment, .pdf-table td:last-child {
                    max-width: 65mm;
                    overflow: hidden;
                    text-overflow: ellipsis;
                }
                
                .pastille-group { display: flex; gap: 4px; align-items: center; justify-content: center; width: 100%; height: 100%; box-sizing: border-box; }
                .pastille-group input[type="radio"] { display: none !important; }
                .pastille-group label {
                    display: inline-block;
                    width: 18px; height: 18px; border-radius: 50%;
                    border: 1px solid #777 !important; background: #eee !important;
                    position: relative; cursor: default;
                    box-sizing: border-box;
                }
                /* Gommettes couleurs pleines */
                .pastille-group label.p-ok { background-color: #d1d1d1 !important; }
                .pastille-group label.p-aa { background-color: #d1d1d1 !important; }
                .pastille-group label.p-nc { background-color: #d1d1d1 !important; }
                .pastille-group label.p-nr { background-color: #d1d1d1 !important; }
                .pastille-group label.p-na { background-color: #d1d1d1 !important; }

                /* État sélectionné : On retrouve les vraies couleurs du SaaS */
                .pastille-group label.selected.p-ok { background-color: #28a745 !important; border-color: #1e7e34 !important; }
                .pastille-group label.selected.p-aa { background-color: #f39c12 !important; border-color: #d35400 !important; }
                .pastille-group label.selected.p-nc { background-color: #dc3545 !important; border-color: #bd2130 !important; }
                .pastille-group label.selected.p-nr { background-color: #8b0000 !important; border-color: #5a0000 !important; }
                .pastille-group label.selected.p-na { background-color: #95a5a6 !important; border-color: #7f8c8d !important; }

                /* Le point blanc central pour simuler le bouton radio premium */
                .pastille-group label.selected::after {
                    content: "";
                    position: absolute;
                    top: 50%; left: 50%;
                    width: 6px; height: 6px;
                    background: white;
                    border-radius: 50%;
                    transform: translate(-50%, -50%);
                }

                /* === DIAGONAL HEADERS CSS === */
                .diagonal-header {
                    height: 120px;
                    vertical-align: bottom;
                    padding: 0 !important;
                    position: relative;
                    background: #e0e0e0 !important;
                    overflow: visible;
                    border: 1px solid #000 !important;
                    border-right: none !important;
                }
                .diagonal-header + th {
                    border-left: none !important;
                }
                .diagonal-wrapper {
                    display: flex;
                    width: 140px;
                    height: 100%;
                    align-items: stretch;
                    margin: 0 auto;
                    border: none !important;
                    position: relative;
                }
                /* La barre verticale courte à droite de la zone grise */
                .diagonal-wrapper::after {
                    content: "";
                    position: absolute;
                    right: 0;
                    bottom: 0;
                    width: 1px;
                    height: 30px;
                    background: #000;
                    z-index: 5;
                }
                .machine-icon {
                    width: 40px;
                    height: 40px;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    background: transparent;
                    border-radius: 8px;
                    flex-shrink: 0;
                }
                .diag-col {
                    width: 28px;
                    height: 100%;
                    position: relative;
                    flex-shrink: 0;
                }
                .diag-col.col-3 { width: 46.6px; }
                .diag-col::after {
                    content: "";
                    position: absolute;
                    left: 100%;
                    bottom: 0;
                    width: 1px;
                    height: 30px;
                    background: #000;
                    z-index: 2;
                }
                .diag-col::before {
                    content: "";
                    position: absolute;
                    left: 100%;
                    top: 0;
                    bottom: 30px;
                    width: 1px;
                    background: #000;
                    transform: skewX(-35deg);
                    transform-origin: bottom left;
                }
                .diag-text {
                    position: absolute;
                    bottom: 35px;
                    left: 100%;
                    padding-left: 5px;
                    transform: rotate(-55deg);
                    transform-origin: bottom left;
                    text-align: left;
                    white-space: nowrap;
                    font-size: 8px;
                    font-weight: bold;
                    color: #000;
                    width: 200px;
                    line-height: 1.1;
                }

                .pdf-input { border: none; border-bottom: 1px dashed #000; background: transparent; font-size: 13px; font-family: Arial; padding: 2px; width: 100%; color: black; outline:none; }
                .pdf-textarea-rendered { 
                    width: 100%; font-family: Arial; font-size: 9pt; color: black; white-space: pre-wrap; word-wrap: break-word; padding:4px; box-sizing: border-box;
                }
                .no-print-pdf, .btn-ia-refresh, .top-bar, .photo-btn, .photo-thumbs, #btnChrono { display: none !important; }
                
                .photo-annexe-item { text-align: center; max-width: 200px; margin-bottom: 10px; }
                .photo-annexe-item img { width: 180px; height: 135px; object-fit: cover; border: 1px solid #000; }
                .photo-annexe-item p { font-size: 8pt; margin: 3px 0 0 0; color: #000; line-height: 1.2; }

                img { max-width: 100%; }
                .levage-diagram-container { page-break-inside: avoid !important; break-inside: avoid !important; }
            `;
            container.appendChild(styleNode);

            // Fetch data from form for Page 1
            const numArc = window.LM_RAPPORT.arc;
            const nomSociete = document.querySelector('[name="nom_societe_display"]')?.value || window.LM_RAPPORT.nomSociete;
            const adresse = document.querySelector('[name="adresse"]')?.value || '';
            const cp = document.querySelector('[name="code_postal"]')?.value || '';
            const ville = document.querySelector('[name="ville"]')?.value || '';
            const pays = document.querySelector('[name="pays"]')?.value || '';

            const contactPrenom = document.querySelector('[name="contact_prenom"]')?.value || '';
            const contactNom = document.querySelector('[name="contact_nom"]')?.value || '';
            const contactFonction = document.querySelector('[name="contact_fonction"]')?.value || '';
            const contactTel = document.querySelector('[name="contact_telephone"]')?.value || '';
            const contactEmail = document.querySelector('[name="contact_email"]')?.value || '';

            const nomSignataire = document.querySelector('[name="nom_signataire"]')?.value || '_____';

            const commentaire = document.querySelector('[name="commentaire_technicien"]')?.value || '';

            const techName = "<?= htmlspecialchars($techName) ?>";
            const dateExp = window.LM_RAPPORT.dateInt;
            const sigTechData = window.LM_RAPPORT.sigTech || document.getElementById('canvasTech')?.toDataURL() || '';
            const sigClientData = window.LM_RAPPORT.sigClient || document.getElementById('canvasClient')?.toDataURL() || '';

            // Generate HTML lines for machines
            const machinesTrs = window.LM_RAPPORT.machinesData.map(m => `
                <tr style="border-bottom:1px solid #000;">
                    <td style="padding:6px; border-right:1px solid #000; text-align:center;">${m.arc || '—'}</td>
                    <td style="padding:6px; border-right:1px solid #000; text-align:center;">${m.of || '—'}</td>
                    <td style="padding:6px; border-right:1px solid #000;">${m.designation || '—'}</td>
                    <td style="padding:6px; text-align:center;">${m.annee || '—'}</td>
                </tr>
            `).join('');

            // --- 1. PAGE RAPPORT FINAL (COUVERTURE + INFOS) ---
            if (includeIntro) {
            const rapportCloneWrapper = document.createElement('div');
            rapportCloneWrapper.className = 'pdf-page';
            // Layout exact as requested
            rapportCloneWrapper.innerHTML = `
                <!-- HEADER (Logo, Slogan) -->
                <table style="width:100%; border:none; margin-bottom:15px; border-bottom: 2px solid #F48220;">
                    <tr>
                        <td style="width: 40%; vertical-align: bottom; padding-bottom: 10px;">
                            <img src="/assets/lenoir_logo_doc.png" style="height:60px;">
                        </td>
                        <td style="width: 60%; vertical-align: bottom; text-align: right; padding-bottom: 5px;">
                            <div style="font-size: 11px; font-weight: normal; color: #F48220; font-style: italic;">
                                Le spécialiste des applications magnétiques pour la séparation et le levage industriel
                            </div>
                        </td>
                    </tr>
                </table>
                <div style="text-align: right; color: #555; font-weight: bold; font-size: 11px; margin-top: 5px; margin-bottom: 15px;">RAPPORT D'EXPERTISE</div>

                <!-- GRAND CADRE ORANGE -->
                <div style="border: 3px solid #F48220; padding: 15px; margin-bottom: 30px;">
                    <h1 style="text-align: center; color: #F48220; font-size: 26px; font-weight: bold; margin: 10px 0 20px 0;">RAPPORT DE L'EXPERTISE</h1>
                    <div style="text-align: right; font-weight: bold; font-size: 14px; color: black; margin-bottom: 15px;">N&deg;ARC : ${numArc}</div>

                    <!-- TABLEAU CLIENT -->
                    <table style="width:100%; border-collapse:collapse; border: 2px solid #F48220; margin-bottom:20px; font-size:12px; font-family: Arial, sans-serif;">
                        <tr>
                            <td colspan="4" style="background-color: #5b9bd5; color: white; text-align: center; font-weight: bold; text-transform: uppercase; padding: 6px; border: 1px solid #000;">
                                COORDONNEES DU CLIENT
                            </td>
                        </tr>
                        <tr>
                            <td colspan="2" style="background-color: #f2f2f2; text-align: center; font-weight: bold; padding: 6px; border: 1px solid #000; width: 50%;">SOCIETE</td>
                            <td colspan="2" style="background-color: #f2f2f2; text-align: center; font-weight: bold; padding: 6px; border: 1px solid #000; width: 50%;">CONTACT SUR SITE</td>
                        </tr>
                        <tr>
                            <td style="font-weight: bold; padding: 6px; border: 1px solid #000; width: 15%;">Nom</td>
                            <td style="padding: 6px; border: 1px solid #000; width: 35%;">${nomSociete || '_____'}</td>
                            <td style="font-weight: bold; padding: 6px; border: 1px solid #000; width: 15%;">Nom</td>
                            <td style="padding: 6px; border: 1px solid #000; width: 35%;">${contactNom || '_____'}</td>
                        </tr>
                        <tr>
                            <td style="font-weight: bold; padding: 6px; border: 1px solid #000;">Adresse</td>
                            <td style="padding: 6px; border: 1px solid #000;">${adresse || '_____'}</td>
                            <td style="font-weight: bold; padding: 6px; border: 1px solid #000;">Prénom</td>
                            <td style="padding: 6px; border: 1px solid #000;">${contactPrenom || '_____'}</td>
                        </tr>
                        <tr>
                            <td style="font-weight: bold; padding: 6px; border: 1px solid #000;">CP</td>
                            <td style="padding: 6px; border: 1px solid #000;">${cp || '_____'}</td>
                            <td style="font-weight: bold; padding: 6px; border: 1px solid #000;">Fonction</td>
                            <td style="padding: 6px; border: 1px solid #000;">${contactFonction || '_____'}</td>
                        </tr>
                        <tr>
                            <td style="font-weight: bold; padding: 6px; border: 1px solid #000;">Ville</td>
                            <td style="padding: 6px; border: 1px solid #000;">${ville || '_____'}</td>
                            <td style="font-weight: bold; padding: 6px; border: 1px solid #000;">Téléphone</td>
                            <td style="padding: 6px; border: 1px solid #000;">${contactTel || '_____'}</td>
                        </tr>
                        <tr>
                            <td style="font-weight: bold; padding: 6px; border: 1px solid #000;">Pays</td>
                            <td style="padding: 6px; border: 1px solid #000;">${pays || 'France'}</td>
                            <td style="font-weight: bold; padding: 6px; border: 1px solid #000;">Courriel</td>
                            <td style="padding: 6px; border: 1px solid #000; color: blue; text-decoration: underline;">${contactEmail || '_____'}</td>
                        </tr>
                    </table>
                </div>

                <!-- TABLEAU PARC MACHINE -->
                <div style="margin-top: 10px;">
                    <table class="can-split" style="width:100%; border-collapse:collapse; font-size:12px; font-family: Arial, sans-serif;">
                        <tr style="page-break-inside: avoid; page-break-after: avoid;">
                            <td colspan="5" style="background-color: #5b9bd5; color: white; text-align: center; font-weight: bold; text-transform: uppercase; padding: 6px; border: 1px solid #000;">
                                PARC MACHINE
                            </td>
                        </tr>
                        <tr style="page-break-inside: avoid;">
                            <td style="background-color: #f2f2f2; text-align: center; font-weight: bold; padding: 6px; border: 1px solid #000; width: 8%;">Poste</td>
                            <td style="background-color: #f2f2f2; text-align: center; font-weight: bold; padding: 6px; border: 1px solid #000; width: 25%;">N&deg; A.R.C (N&deg; de série)</td>
                            <td style="background-color: #f2f2f2; text-align: center; font-weight: bold; padding: 6px; border: 1px solid #000; width: 45%;">Désignation du Produit</td>
                            <td style="background-color: #f2f2f2; text-align: center; font-weight: bold; padding: 6px; border: 1px solid #000; width: 12%;">Repère</td>
                            <td style="background-color: #f2f2f2; text-align: center; font-weight: bold; padding: 6px; border: 1px solid #000; width: 10%;">Année</td>
                        </tr>
                        ${window.LM_RAPPORT.machinesData.map((m, idx) => `
                            <tr style="page-break-inside: avoid; break-inside: avoid;">
                                <td style="text-align: center; font-weight: bold; padding: 6px; border: 1px solid #000;">${m.poste || (idx + 1)}</td>
                                <td style="text-align: center; padding: 6px; border: 1px solid #000;">${m.arc || '—'} ${m.of ? ' - ' + m.of : ''}</td>
                                <td style="padding: 6px; border: 1px solid #000;">${m.designation || '—'}</td>
                                <td style="text-align: center; padding: 6px; border: 1px solid #000;">${m.repere || '—'}</td>
                                <td style="text-align: center; padding: 6px; border: 1px solid #000;">${m.annee || '—'}</td>
                            </tr>
                        `).join('')}
                    </table>
                </div>

                <!-- SIGNATURES (Hors du cadre orange) -->
                <table style="width:100%; border-collapse:collapse; border: 2px solid #F48220; font-size:13px; font-family: Arial, sans-serif;">
                    <tr>
                        <td style="font-weight: bold; padding: 15px 10px; border: 1px solid #F48220; width: 25%;">Technicien sur Site :</td>
                        <td style="padding: 15px 10px; border: 1px solid #F48220; width: 30%;">${techName}</td>
                        <td rowspan="2" style="padding: 5px; border: 1px solid #F48220; width: 45%; text-align: center; vertical-align: middle;">
                            <img src="${sigTechData}" style="max-height:80px; max-width:100%;">
                        </td>
                    </tr>
                    <tr>
                        <td style="font-weight: bold; padding: 15px 10px; border: 1px solid #F48220;">Date d'expertise :</td>
                        <td style="padding: 15px 10px; border: 1px solid #F48220;">${dateExp}</td>
                    </tr>
                </table>
            `;
            rapportCloneWrapper.appendChild(createPdfFooter());
            container.appendChild(rapportCloneWrapper);

            // --- 1.2 PAGE SYNTHÈSE + PRÉAMBULE (FUSIONNÉS POUR ÉCONOMISER DES PAGES) ---
            const synthPreambulePage = document.createElement('div');
            synthPreambulePage.className = 'pdf-page';
            synthPreambulePage.style.margin = '0';
            synthPreambulePage.style.boxShadow = 'none';
            if (container.children.length > 0) synthPreambulePage.style.pageBreakBefore = 'always';
            synthPreambulePage.style.paddingTop = '15mm';
            const s = window.LM_RAPPORT.synth;

            // Calcul mois prochain pour le préambule
            let moisProchainText = "";
            let villePreambule = ville || "[VILLE DU CLIENT]";
            if (dateExp && dateExp.includes('/')) {
                const parts = dateExp.split('/');
                if (parts.length === 3) {
                    const mIndex = parseInt(parts[1], 10) - 1;
                    const y = parseInt(parts[2], 10) + 1;
                    const moisNoms = ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];
                    if (mIndex >= 0 && mIndex < 12) {
                        moisProchainText = moisNoms[mIndex] + ' ' + y;
                    }
                }
            }
            if (!moisProchainText) moisProchainText = "[MOIS PROCHAIN]";

            synthPreambulePage.innerHTML = `
                <div style="padding-top: 10px;">
                    <div style="padding: 20px; color: #000; background: #fff; margin-bottom: 30px; page-break-inside: avoid;">
                        <h2 style="font-weight: bold; font-size: 18px; color: #1e4e6d; margin: 0 0 25px 0; padding-bottom: 5px; border-bottom: 2px solid #1e4e6d; text-transform: uppercase; width: 100%;">SYNTHÈSE DE L'INTERVENTION</h2>
                        
                        <div style="margin-bottom: 15px; font-size: 13px; line-height: 1.6;">
                            <div><strong>Technicien :</strong> ${s.tech}</div>
                            <div><strong>Date :</strong> ${s.date}</div>
                            <div><strong>Durée totale :</strong> ${s.duree}</div>
                            <div><strong>Équipements contrôlés :</strong> ${s.nbMachines}</div>
                        </div>

                        <div style="margin: 20px 0; font-size: 13px;">
                            <div style="display: flex; align-items: center; margin-bottom: 8px;">
                                <span style="display: inline-block; width: 12px; height: 12px; background: #28a745; margin-right: 10px;"></span>
                                <strong>${s.ok} points conformes</strong>
                            </div>
                            <div style="display: flex; align-items: center; margin-bottom: 8px;">
                                <span style="display: inline-block; width: 12px; height: 12px; background: #1e4e6d; margin-right: 10px;"></span>
                                <strong>${s.aa} points à améliorer</strong>
                            </div>
                            <div style="display: flex; align-items: center; margin-bottom: 8px;">
                                <span style="display: inline-block; width: 12px; height: 12px; background: #dc3545; margin-right: 10px;"></span>
                                <strong>${s.nc} point${s.nc > 1 ? 's' : ''} non conforme${s.nc > 1 ? 's' : ''}</strong>
                            </div>
                            <div style="display: flex; align-items: center; margin-bottom: 8px;">
                                <span style="display: inline-block; width: 12px; height: 12px; background: #8b0000; margin-right: 10px;"></span>
                                <strong>${s.nr} remplacement${s.nr > 1 ? 's' : ''} nécessaire${s.nr > 1 ? 's' : ''}</strong>
                            </div>
                        </div>

                        <div style="margin-top: 25px; text-align: center;">
                            <div style="font-weight: bold; font-size: 14px; margin-bottom: 5px; text-transform: uppercase;">SCORE DE CONFORMITÉ : ${s.score}%</div>
                            ${s.nbMachinesEmpty > 0 ? `<div style="font-size: 11px; color: #dc3545; font-weight: bold; margin-bottom: 8px;">⚠️ ${s.nbMachinesEmpty} fiche(s) non remplie(s) — score calculé sur ${s.nbMachinesFilled}/${s.nbMachinesFilled + s.nbMachinesEmpty} fiches uniquement</div>` : ''}
                            <div style="width: 100%; height: 20px; background: #e2e8f0; border: 1px solid #000; position: relative; overflow: hidden; border-radius: 4px;">
                                <div style="width: ${s.score}%; height: 100%; background: ${s.score < 33 ? '#dc3545' : (s.score < 66 ? '#f59e0b' : '#22c55e')}; transition: width 0.5s;"></div>
                            </div>
                        </div>
                    </div>

                    <div style="font-size: 13px; line-height: 1.5; color: black; font-family: Arial, sans-serif; page-break-inside: avoid;">
                        <h2 style="color: #1e4e6d; font-size: 16px; text-transform: uppercase; margin-bottom: 10px; border-bottom: 2px solid #1e4e6d; padding-bottom: 5px;">PRÉAMBULE :</h2>
                        
                        <p style="margin-bottom: 12px;">
                            Ce rapport est établi suite à une expertise effectuée le ${dateExp} sur votre site de ${villePreambule}.
                        </p>
                        
                        <p style="margin-bottom: 12px;">
                            Nos expertises permettent de vous accompagner dans votre démarche ISO 22000 :2005 et HACCP. Notre analyse est suivie de conclusions ou recommandations que nous vous invitons à suivre pour la pérennité et la qualité de votre production.
                        </p>
                        
                        <p style="margin-bottom: 12px;">
                            Dans le cadre de notre prestation annuelle, la prochaine expertise aura lieu en ${moisProchainText}. Nous vous contacterons pour établir une date appropriée à vos impératifs de production.
                        </p>
                    </div>
                </div>
            `;
            synthPreambulePage.appendChild(createPdfFooter());
            container.appendChild(synthPreambulePage);
            }

            // --- 2. FETCH & APPEND MACHINES ---
            if (includeMachines && window.LM_RAPPORT && window.LM_RAPPORT.machinesIds) {
                let reportMachineIds = targetMachineIds ? targetMachineIds : [...window.LM_RAPPORT.machinesIds];
                const emptyOption = 'include';
                const emptyIds = window.LM_RAPPORT.emptyMachinesIds || [];

                // Si option = 'exclude', on retire carrément les machines vides de la boucle !
                if (!targetMachineIds && emptyOption === 'exclude' && emptyIds.length > 0) {
                    reportMachineIds = reportMachineIds.filter(id => !emptyIds.includes(parseInt(id, 10)) && !emptyIds.includes(String(id)));
                }

                const totalMachinesReal = window.LM_RAPPORT.machinesIds.length;
                for (let localIdx = 0; localIdx < reportMachineIds.length; localIdx++) {
                    const mId = reportMachineIds[localIdx];
                    const globalIdx = startIndex + localIdx;

                    // Si on a gardé la machine mais qu'elle est vide et qu'on voulait 'condensed'
                    if (emptyOption === 'condensed' && (emptyIds.includes(parseInt(mId, 10)) || emptyIds.includes(String(mId)))) {
                        const mData = window.LM_RAPPORT.machinesData.find(m => parseInt(m.id, 10) === parseInt(mId, 10)) || {};
                        const mDesignation = mData.designation || 'Équipement';
                        const mArc = mData.arc || numArc;

                        const p = document.createElement('div');
                        p.className = 'pdf-page';
                        p.innerHTML = `
                            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; border-bottom: 3px solid #5B9BD5; padding-bottom: 5px;">
                                <div style="font-size: 14px; font-weight: bold; color: #1B4F72;">FICHE ${globalIdx + 1} / ${totalMachinesReal}</div>
                                <img src="/assets/lenoir_logo_doc.png" style="height: 45px;">
                            </div>
                            
                            <table style="width:100%; border-collapse:collapse; border:1px solid #000; margin-bottom:20px; font-size:13px; color:#000;">
                                <tr>
                                    <td style="width:15%; font-weight:bold; border:1px solid #000; padding:6px; background:#d9d9d9;">N┬░ A.R.C.</td>
                                    <td style="width:35%; border:1px solid #000; padding:6px; font-weight:bold;">${mArc}</td>
                                    <td style="width:15%; font-weight:bold; border:1px solid #000; padding:6px; background:#d9d9d9;">Année</td>
                                    <td style="width:35%; border:1px solid #000; padding:6px;"><b>${mData.annee || '—'}</b></td>
                                </tr>
                            </table>

                            <div style="margin-top: 150px; text-align: center;">
                                <div style="font-size: 24px; font-weight: bold; color: #dc3545; text-transform: uppercase; border: 4px solid #dc3545; display: inline-block; padding: 20px 40px; transform: rotate(-5deg);">
                                    ÉQUIPEMENT NON CONTRöLÉ
                                </div>
                                <div style="margin-top: 30px; color: #555; font-size: 14px;">
                                    Aucune donnée n'a été saisie pour ce matériel lors de l'intervention.
                                </div>
                            </div>
                        `;

                        p.style.minHeight = '100px';
                        if (container.children.length > 0) p.style.pageBreakBefore = 'always';
                        container.appendChild(p);

                        continue; // Passe directement à la machine suivante sans fetch html !
                    }

                    try {
                        const res = await fetch('machine_edit.php?id=' + mId + '&pdf=1');
                        const html = await res.text();
                        const parser = new DOMParser();
                        const doc = parser.parseFromString(html, 'text/html');

                        
                        // --- V3.3 STABILITY PATCH ---
                        await new Promise(r => setTimeout(r, 100));

                        const pages = doc.querySelectorAll('.pdf-page');
                        pages.forEach((p, pIdx) => {
                            // Bug 5 & New Fix: Remove empty photos section
                            const hasPhotos = p.querySelectorAll('.photo-annexe-item img').length > 0;
                            p.querySelectorAll('.photos-annexes-wrapper').forEach(wrapper => {
                                if (!wrapper.querySelector('.photo-annexe-item')) {
                                    wrapper.remove();
                                }
                            });

                            // --- NETTOYAGE RADICAL DES ÉLÉMENTS D'INTERFACE ---
                            p.querySelectorAll('.photo-btn, .btn-ia-refresh, .top-bar, .photo-thumbs, #btnChrono, .no-print-pdf, .photo-del-overlay').forEach(el => el.remove());

                            // FIX: Blank pages. Strip default screen margins and shadows so they don't push into invisible overflowing pages
                            p.style.margin = '0';
                            p.style.boxShadow = 'none';

                            // If it's a diagram/photo page and it's empty after cleanup, skip it
                            const contentText = p.textContent.trim();
                            if ((pIdx > 0) && contentText.length < 50 && !hasPhotos && !p.querySelector('img')) {
                                return; // Skip empty pages
                            }

                            // Chaque machine commence obligatoirement sur une nouvelle page
                            if (pIdx === 0) {
                                if (container.children.length > 0) {
                                    p.style.pageBreakBefore = 'always';
                                }
                                p.style.marginTop = '0';
                                p.style.paddingTop = '10mm'; 
                                
                                // On ajoute discretement le numéro de fiche AU DESSUS du header officiel LENOIR
                                const hDiv = document.createElement('div');
                                hDiv.style.textAlign = 'right';
                                hDiv.style.fontSize = '12px';
                                hDiv.style.fontWeight = 'bold';
                                hDiv.style.color = '#1B4F72';
                                hDiv.style.marginBottom = '5px';
                                hDiv.innerHTML = `FICHE ${globalIdx + 1} / ${totalMachinesReal}`;
                                p.insertBefore(hDiv, p.firstChild);
                            }

                            p.querySelectorAll('input[type="radio"]:checked').forEach(r => {
                                const lbl = r.closest('label');
                                if (lbl) lbl.classList.add('selected');
                            });

                            p.querySelectorAll('input:not([type="radio"]):not([type="checkbox"]):not([type="hidden"]):not([type="file"])').forEach(inp => {
                                let val = (inp.value || '').trim();
                                if (inp.name === 'mesures[poste]') {
                                    val = val ? val : (globalIdx + 1);
                                } else if (!val) {
                                    val = '';
                                }

                                let style = inp.getAttribute('style') || '';
                                let newStyle = style + "; border-bottom:1px dashed black; display:inline-block; min-width:30px; padding:0 3px; font-weight:bold;";
                                inp.outerHTML = `<span style="${newStyle}">${val}</span>`;
                            });

                            p.querySelectorAll('select').forEach(sel => {
                                let valText = sel.options[sel.selectedIndex]?.text || '';
                                if (!sel.value) valText = '';
                                sel.outerHTML = `<span style="border-bottom:1px dashed black; display:inline-block; min-width:30px; padding:0 3px; font-weight:bold; color:black;">${valText}</span>`;
                            });

                            p.querySelectorAll('img').forEach(img => {
                                let src = img.getAttribute('src') || '';
                                if (src.startsWith('/') && !src.startsWith('//')) {
                                    img.src = window.location.origin + src;
                                }
                            });

                            p.querySelectorAll('textarea').forEach(ta => {
                                let val = ta.value || ta.innerHTML;

                                // NEW FIX FOR PERFORMANCE / NON REALISE Bug:
                                const specialKeys = ['aprf_attraction_comment', 'ov_perf_bille', 'ov_perf_ecrou', 'ov_perf_rond50', 'ov_perf_rond100', 'levage_charge_maxi_comment', 'levage_temp_maxi_comment'];
                                if (specialKeys.some(k => ta.name && ta.name.includes(k))) {
                                    if (!val.trim()) val = "Non réalisé";
                                }

                                if (val.trim()) {
                                    const div = document.createElement('div');
                                    div.className = 'pdf-textarea-rendered';
                                    div.style.minHeight = '15px';
                                    div.textContent = val;
                                    ta.parentNode.insertBefore(div, ta);
                                }
                                ta.remove();
                            });

                            // BUGFIX PDF: Séparateur de table (tbody)
                            // html2pdf coupe en deux les <tr> sauvagement. 
                            // La seule vraie solution est de wrapper chaque ligne logique dans son propre <tbody> !
                            p.querySelectorAll('table.pdf-table').forEach(table => {
                                const rows = Array.from(table.querySelectorAll('tr'));
                                if (!rows.length) return;

                                let currentTbody = document.createElement('tbody');
                                currentTbody.style.pageBreakInside = 'avoid';
                                table.appendChild(currentTbody);

                                let pendingSpans = 0;
                                rows.forEach(row => {
                                    let maxSpan = 1;
                                    row.querySelectorAll('th, td').forEach(c => {
                                        if (c.rowSpan > maxSpan) maxSpan = c.rowSpan;
                                    });
                                    if (maxSpan - 1 > pendingSpans) pendingSpans = maxSpan - 1;

                                    currentTbody.appendChild(row); // Déplace le tr dans le nouveau tbody

                                    if (pendingSpans > 0) {
                                        pendingSpans--;
                                    } else {
                                        currentTbody = document.createElement('tbody');
                                        currentTbody.style.pageBreakInside = 'avoid';
                                        table.appendChild(currentTbody);
                                    }
                                });

                                // Supprimer les tbody/thead originaux devenus vides
                                Array.from(table.children).forEach(child => {
                                    if ((child.tagName === 'TBODY' || child.tagName === 'THEAD') && child.children.length === 0) {
                                        child.remove();
                                    }
                                });
                            });

                            p.style.position = 'relative';
                            p.style.paddingBottom = '5mm'; 
                            p.querySelectorAll('.section-wrapper-pdf').forEach(w => w.style.marginBottom = '0');
                            p.style.minHeight = '100px'; 
                            p.appendChild(createPdfFooter());
                            container.appendChild(p);
                        });
                    } catch (err) {
                        console.error('Erreur fetch machine ' + mId, err);
                    }
                }
            }

            if (includeEnd) {
                // --- 4. PAGE DE FIN (STRUCTURE LENOIR-MEC + SIGNATURES + OBSERVATIONS) ---

            const endPage = document.createElement('div');
            endPage.className = 'pdf-page';
            endPage.style.padding = '0 15mm';
            endPage.style.position = 'relative';
            if (container.children.length > 0) endPage.style.pageBreakBefore = 'always';

            const originalRapport = document.getElementById('rapportForm');
            const souhaitRapport = originalRapport.querySelector('[name="souhait_rapport_unique"]').checked;
            const souhaitPieces = originalRapport.querySelector('[name="souhait_offre_pieces"]').checked;
            const souhaitIntervention = originalRapport.querySelector('[name="souhait_pieces_intervention"]').checked;
            const souhaitAucune = originalRapport.querySelector('[name="souhait_aucune_offre"]').checked;
            const nomSignataireFin = originalRapport.querySelector('[name="nom_signataire"]').value || '_____';
            const techNameLabel = "<?= htmlspecialchars($techName) ?>";
            const dateStr = window.LM_RAPPORT.dateInt;

            const commentaryTech = originalRapport.querySelector('[name="commentaire_technicien"]')?.value || '';
            const commentaryClient = originalRapport.querySelector('[name="commentaire_client"]')?.value || '';

            const sigTechImg = window.LM_RAPPORT.sigTech || document.getElementById('canvasTech')?.toDataURL() || '';
            const sigClientImg = window.LM_RAPPORT.sigClient || document.getElementById('canvasClient')?.toDataURL() || '';

            endPage.innerHTML = `
                <div style="font-family: Arial, sans-serif; font-size: 11px; color: #000;">
                    
                    <!-- OBSERVATIONS GÉNÉRALES -->
                    <div style="font-weight: bold; font-size: 14px; color: #1e4e6d; margin-bottom: 10px; border-bottom: 2px solid #1e4e6d; padding-bottom: 5px; text-transform: uppercase;">OBSERVATIONS DU TECHNICIEN</div>
                    <div style="padding: 8px 0; min-height: 40px; font-size: 12px; white-space: pre-wrap; margin-bottom: 20px;">${commentaryTech}</div>

                    <div style="font-weight: bold; font-size: 14px; color: #1e4e6d; margin-bottom: 10px; border-bottom: 2px solid #1e4e6d; padding-bottom: 5px; text-transform: uppercase;">COMMENTAIRE DU CLIENT</div>
                    <div style="padding: 8px 0; font-size: 12px; white-space: pre-wrap; margin-bottom: 20px;">${commentaryClient}</div>

                    <!-- LE CLIENT SOUHAITE -->
                    <div style="font-weight: bold; font-size: 14px; color: #1e4e6d; margin-bottom: 10px; border-bottom: 2px solid #1e4e6d; padding-bottom: 5px; text-transform: uppercase;">LE CLIENT SOUHAITE</div>
                    <div style="padding: 8px 0; margin-bottom: 20px;">
                        <div style="margin-bottom: 3px; font-size: 11px;">${souhaitRapport ? '☑' : '☐'} Ce Rapport d\'expertise uniquement</div>
                        <div style="margin-bottom: 3px; font-size: 11px;">${souhaitPieces ? '☑' : '☐'} Une offre de Pièces de Rechange</div>
                        <div style="margin-bottom: 3px; font-size: 11px;">${souhaitIntervention ? '☑' : '☐'} Une offre de PR + intervention mise en place</div>
                        <div style="font-size: 11px;">${souhaitAucune ? '☑' : '☐'} Aucune offre</div>
                    </div>

                    <!-- DATE ET HEURE -->
                    <div style="font-weight: bold; font-size: 14px; color: #1e4e6d; margin-bottom: 10px; border-bottom: 2px solid #1e4e6d; padding-bottom: 5px; text-transform: uppercase;">DATE ET LIEU</div>
                    <div style="padding: 8px 0; margin-bottom: 20px; font-size: 13px; font-weight: bold;">
                        Fait le ${dateStr}
                    </div>

                    <!-- SIGNATURES -->
                    <div style="font-weight: bold; font-size: 14px; color: #1e4e6d; margin-bottom: 10px; border-bottom: 2px solid #1e4e6d; padding-bottom: 5px; text-transform: uppercase;">SIGNATURES</div>
                    <table style="width: 100%; border-collapse: collapse; table-layout: fixed; margin-bottom: 15px;">
                        <tr style="height: 120px;">
                            <td style="border: 1px solid #000; padding: 8px; vertical-align: top; width: 50%;">
                                <div style="font-weight: bold; text-decoration: underline; margin-bottom: 5px;">Contrôleur (NOM Prénom) :</div>
                                <div style="margin-bottom: 10px;"><strong>${techNameLabel}</strong></div>
                                <div style="text-align: center;">
                                    <img src="${sigTechImg}" style="max-height: 80px; max-width: 90%; object-fit: contain; background: white;">
                                </div>
                            </td>
                            <td style="border: 1px solid #000; padding: 8px; vertical-align: top; width: 50%;">
                                <div style="font-weight: bold; text-decoration: underline; margin-bottom: 5px;">Client (NOM Prénom) :</div>
                                <div style="margin-bottom: 10px;"><strong>${nomSignataireFin}</strong></div>
                                <div style="text-align: center;">
                                    <img src="${sigClientImg}" style="max-height: 80px; max-width: 90%; object-fit: contain; background: white;">
                                </div>
                            </td>
                        </tr>
                    </table>

                    <!-- CONTACTS ORANGE -->
                    <div style="border: 2px solid #000; padding: 0; text-align: center; margin-bottom: 15px;">
                        <div style="background-color: #E67E22; color: white; padding: 4px 15px; font-weight: bold; border-bottom: 2px solid #000; font-size: 10px;">POUR TOUTE INFORMATION TECHNIQUE SUR CE RAPPORT</div>
                        <div style="background-color: #fff; padding: 6px; border-bottom: 2px solid #000;">
                            <div style="font-size: 12px;">📞 <strong>Soufyane SALAH</strong> &nbsp;&nbsp;&nbsp; <span style="font-style: italic;">Chargé d'Affaires</span></div>
                        </div>
                        
                        <div style="background-color: #E67E22; color: white; padding: 4px 15px; font-weight: bold; border-bottom: 2px solid #000; font-size: 10px;">POUR LA PLANIFICATION D'UNE VÉRIFICATION PÉRIODIQUE</div>
                        <div style="background-color: #fff; padding: 6px;">
                            <div style="font-size: 12px;">📞 <strong>Sophie NIAY</strong> &nbsp;&nbsp;&nbsp; <span style="font-style: italic;">Responsable Service Clients</span></div>
                        </div>
                    </div>

                    <!-- FOOTER SECTION WITH QR CODE -->
                    <div style="text-align: center; color: #1B4F72; margin-top: 5px;">
                        <div style="font-weight: bold; margin-bottom: 8px;">UNE SEULE ADRESSE COMMUNE : contact@raoul-lenoir.com</div>
                        
                        <div style="margin-top: 5px;">
                            <div style="font-size: 10px; font-weight: bold; margin-bottom: 3px;">Visitez notre site !</div>
                            <img src="/assets/qr_lenoir.png" style="width: 100px; height: 100px; display: block; margin: 0 auto;">
                            <div style="font-weight: bold; font-size: 10px; margin-top: 5px;">www.raoul-lenoir.com</div>
                        </div>
                    </div>
                </div>
            `;
            endPage.appendChild(createPdfFooter());
            container.appendChild(endPage);
            }

            await waitForImages(container);
            return container;
        }

        // ══════════════════════════════════════════════════════════════════
        // UI PROGRESS HELPER (Advanced)
        // ══════════════════════════════════════════════════════════════════
        function updatePdfProgress(percent, stepIndex, subtext = null) {
            const fill = document.getElementById('pdfProgressBarFill');
            const perc = document.getElementById('pdfPercentLabel');
            const stage = document.getElementById('pdfStageLabel');
            const sub = document.getElementById('pdfLoaderSubtext');

            if(fill) fill.style.width = percent + '%';
            if(perc) perc.textContent = Math.round(percent) + '%';
            
            if(subtext && sub) sub.textContent = subtext;

            // Manage steps
            document.querySelectorAll('.task-step').forEach((s, idx) => {
                s.classList.remove('active');
                if((idx + 1) < stepIndex) s.classList.add('completed');
                if((idx + 1) === stepIndex) s.classList.add('active');
            });

            if(stepIndex === 1 && stage) stage.textContent = "Initialisation";
            if(stepIndex === 2 && stage) stage.textContent = "Expertise Machines";
            if(stepIndex === 3 && stage) stage.textContent = "Finalisation";
        }

        // ══════════════════════════════════════════════════════════════════
        // GÉNÉRAL ROUTER (Monolithique vs Chunked)
        // ══════════════════════════════════════════════════════════════════
        async function generateUltimatePDF(action = 'download') {
            const machineIds = window.LM_RAPPORT.machinesIds || [];
            if (machineIds.length <= 5) {
                return await generateUltimatePDF_Monolithic(action);
            } else {
                return await generateUltimatePDF_Chunked(action);
            }
        }

        async function generateUltimatePDF_Monolithic(action = 'download') {
            if (!window.html2pdf) throw new Error('html2pdf.js non disponible');

            const overlay = document.getElementById('pdfDownloadOverlay');
            if (overlay) overlay.style.display = 'flex';
            
            // reset UI
            document.querySelectorAll('.task-step').forEach(s => s.classList.remove('active', 'completed'));
            updatePdfProgress(0, 1, "Construction du document...");

            try {
                updatePdfProgress(20, 1);
                const container = await buildFullPdfContainer();
                updatePdfProgress(50, 2, "Préparation des visuels...");
                await ensureImagesBase64(container);
                updatePdfProgress(80, 3, "Numérotation des pages...");

                const opt = {
                    margin: [10, 0, 15, 0], 
                    filename: window.LM_RAPPORT ? window.LM_RAPPORT.pdfFilename : 'rapport.pdf',
                    image: { type: 'jpeg', quality: 0.98 },
                    html2canvas: { scale: 1.5, useCORS: true, logging: false },
                    jsPDF: { unit: 'mm', format: 'a4', orientation: 'portrait' },
                    pagebreak: { mode: 'css', avoid: ['img', '.photo-annexe-item', '.pdf-section', '.sig-zone', '.levage-diagram-container'] }
                };

                const worker = html2pdf().set(opt).from(container);

                await worker.toPdf().get('pdf').then(function (pdf) {
                    const totalPages = pdf.internal.getNumberOfPages();
                    for (let i = 1; i <= totalPages; i++) {
                        pdf.setPage(i);
                        pdf.setFont('helvetica', 'normal');
                        pdf.setFontSize(9);
                        pdf.setTextColor(50, 50, 50);
                        pdf.text('Page ' + i + ' / ' + totalPages, 105, 286, { align: 'center' });
                    }
                });

                if (action === 'download') {
                    updatePdfProgress(100, 3, "Téléchargement...");
                    await worker.save();
                } else {
                    const pdfBlob = await worker.outputPdf('blob');
                    return await blobToBase64(pdfBlob);
                }
            } catch (e) {
                console.error("Génération PDF (Monolithique) échouée:", e);
                alert("Erreur génération PDF : " + e.message);
            } finally {
                if (overlay) overlay.style.display = 'none';
            }
        }

        async function generateUltimatePDF_Chunked(action = 'download') {
            if (!window.html2pdf) throw new Error('html2pdf.js non disponible');
            if (!window.PDFLib) throw new Error('PDF-Lib non chargé (vérifiez votre connexion)');

            const PDFLib = window.PDFLib;
            const overlay = document.getElementById('pdfDownloadOverlay');
            if (overlay) overlay.style.display = 'flex';
            
            // reset UI
            document.querySelectorAll('.task-step').forEach(s => s.classList.remove('active', 'completed'));
            updatePdfProgress(0, 1, "Démarrage du découpage...");

            try {
                const machineIds = window.LM_RAPPORT.machinesIds || [];
                const totalSteps = 2 + Math.ceil(machineIds.length / PDF_CHUNK_SIZE) + 1;
                let currentProg = 0;
                const chunks = [];
                const pdfOptions = {
                    margin: [10, 0, 15, 0],
                    image: { type: 'jpeg', quality: 0.98 },
                    html2canvas: { scale: 1.5, useCORS: true, logging: false },
                    jsPDF: { unit: 'mm', format: 'a4', orientation: 'portrait' },
                    pagebreak: { mode: 'css', avoid: ['img', '.photo-annexe-item', '.pdf-section', '.sig-zone', '.levage-diagram-container'] }
                };

                // 1. CHUNK INTRO
                updatePdfProgress(10, 1, "Initialisation...");
                const introContainer = await buildFullPdfContainer({ includeIntro: true, includeMachines: false, includeEnd: false });
                await ensureImagesBase64(introContainer);
                await new Promise(r => setTimeout(r, 100)); // Safety
                chunks.push(await html2pdf().set(pdfOptions).from(introContainer).outputPdf('arraybuffer'));

                // 2. CHUNKS MACHINES
                for (let i = 0; i < machineIds.length; i += PDF_CHUNK_SIZE) {
                    const group = machineIds.slice(i, i + PDF_CHUNK_SIZE);
                    const machineText = `Machines ${i + 1} à ${Math.min(i + PDF_CHUNK_SIZE, machineIds.length)}`;
                    let prog = 10 + ((i + PDF_CHUNK_SIZE) / machineIds.length) * 70;
                    updatePdfProgress(Math.min(prog, 80), 2, machineText);

                    const mContainer = await buildFullPdfContainer({ 
                        includeIntro: false, 
                        includeMachines: true, 
                        includeEnd: false, 
                        targetMachineIds: group,
                        startIndex: i
                    });
                    await ensureImagesBase64(mContainer);
                    await new Promise(r => setTimeout(r, 100)); // Safety for渲染
                    chunks.push(await html2pdf().set(pdfOptions).from(mContainer).outputPdf('arraybuffer'));
                }

                // 3. CHUNK END
                updatePdfProgress(85, 3, "Finalisation des signatures...");
                const endContainer = await buildFullPdfContainer({ includeIntro: false, includeMachines: false, includeEnd: true });
                await ensureImagesBase64(endContainer);
                await new Promise(r => setTimeout(r, 100)); // Safety
                chunks.push(await html2pdf().set(pdfOptions).from(endContainer).outputPdf('arraybuffer'));

                // 4. MERGE & PAGINATION
                updatePdfProgress(95, 3, "Assemblage et pagination...");
                const mergedBytes = await mergePdfChunks(chunks);
                
                const { PDFDocument, rgb, StandardFonts } = window.PDFLib;
                const pdfDoc = await PDFDocument.load(mergedBytes);
                const pages = pdfDoc.getPages();
                const font = await pdfDoc.embedFont(StandardFonts.Helvetica);
                
                for (let i = 0; i < pages.length; i++) {
                    const { width } = pages[i].getSize();
                    const text = `Page ${i + 1} / ${pages.length}`;
                    pages[i].drawText(text, {
                        x: width / 2 - font.widthOfTextAtSize(text, 9) / 2,
                        y: 31, // exact 286mm position as in 91ca0be
                        size: 9,
                        font: font,
                        color: rgb(0.2, 0.2, 0.2),
                    });
                }
                const finalPdfBytes = await pdfDoc.save();
                updatePdfProgress(100, 3, "Terminé !");

                if (action === 'download') {
                    const blob = new Blob([finalPdfBytes], { type: 'application/pdf' });
                    const url = URL.createObjectURL(blob);
                    const a = document.createElement('a');
                    a.href = url;
                    a.download = window.LM_RAPPORT.pdfFilename || 'rapport.pdf';
                    document.body.appendChild(a);
                    a.click();
                    document.body.removeChild(a);
                    URL.revokeObjectURL(url);
                } else {
                    return uint8ArrayToBase64(finalPdfBytes);
                }
            } catch (e) {
                console.error("Génération PDF échouée:", e);
                alert("Erreur génération PDF : " + e.message);
            } finally {
                if (overlay) overlay.style.display = 'none';
            }
        }

        async function mergePdfChunks(chunks) {
            const { PDFDocument } = window.PDFLib;
            const mergedPdf = await PDFDocument.create();
            for (const chunkBuffer of chunks) {
                const chunkPdf = await PDFDocument.load(chunkBuffer);
                const copiedPages = await mergedPdf.copyPages(chunkPdf, chunkPdf.getPageIndices());
                copiedPages.forEach((page) => mergedPdf.addPage(page));
            }
            return await mergedPdf.save();
        }

        // Utilitaires robustes pour Base64 (évite Maximum call stack size exceeded)
        function uint8ArrayToBase64(uint8) {
            let binary = '';
            const len = uint8.byteLength;
            const chunk = 8192; // Traiter par blocs de 8Ko
            for (let i = 0; i < len; i += chunk) {
                binary += String.fromCharCode.apply(null, uint8.subarray(i, i + chunk));
            }
            return btoa(binary);
        }

        function blobToBase64(blob) {
            return new Promise((resolve, reject) => {
                const reader = new FileReader();
                reader.onload = () => resolve(reader.result.split(',')[1]);
                reader.onerror = reject;
                reader.readAsDataURL(blob);
            });
        }

        async function genererPDFBase64() {
            return await generateUltimatePDF('base64');
        }

        async function telechargerPDF() {
            const btn = document.getElementById('btnDownloadPDF');
            if (btn) btn.disabled = true;
            try {
                await generateUltimatePDF('download');
            } finally {
                if (btn) btn.disabled = false;
            }
        }

        // ══════════════════════════════════════════════════════════════════
        // TOAST UI
        // ══════════════════════════════════════════════════════════════════
        function afficherToast(message, type = 'success') {
            const toast = document.getElementById('emailToast');
            if (!toast) return;
            
            let icon = '🔔';
            let bg = 'rgba(16,185,129,0.1)';
            let border = 'rgba(16,185,129,0.3)';
            let color = '#10b981';

            if (type === 'success') {
                icon = '✅';
                bg = 'linear-gradient(135deg, rgba(16, 185, 129, 0.15), rgba(5, 150, 105, 0.15))';
                border = 'rgba(16, 185, 129, 0.4)';
                color = '#10b981';
            } else if (type === 'warning') {
                icon = '📶';
                bg = 'linear-gradient(135deg, rgba(245, 158, 11, 0.15), rgba(217, 119, 6, 0.15))';
                border = 'rgba(245, 158, 11, 0.4)';
                color = '#f59e0b';
            } else {
                icon = '❌';
                bg = 'linear-gradient(135deg, rgba(244, 63, 94, 0.15), rgba(225, 29, 72, 0.15))';
                border = 'rgba(244, 63, 94, 0.4)';
                color = '#f43f5e';
            }

            toast.innerHTML = `<div style="display:flex; align-items:center; gap:12px; text-align:left;">
                <span style="font-size:1.4rem;">${icon}</span>
                <span style="flex:1;">${message}</span>
            </div>`;
            
            toast.style.display = 'block';
            toast.style.background = bg;
            toast.style.border = `1px solid ${border}`;
            toast.style.color = color;
            toast.style.padding = '1.25rem';
            toast.style.backdropFilter = 'blur(10px)';
            toast.style.boxShadow = '0 10px 30px rgba(0,0,0,0.1)';
            
            toast.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }

        // ══════════════════════════════════════════════════════════════════
        // FILE D'ATTENTE HORS-LIGNE (IndexedDB)
        // ══════════════════════════════════════════════════════════════════
        const DB_NAME = 'LMEmailQueue';
        const DB_VERSION = 1;
        const STORE_NAME = 'pendingEmails';

        function ouvrirIDB() {
            return new Promise((resolve, reject) => {
                const req = indexedDB.open(DB_NAME, DB_VERSION);
                req.onupgradeneeded = e => {
                    e.target.result.createObjectStore(STORE_NAME, { keyPath: 'id', autoIncrement: true });
                };
                req.onsuccess = e => resolve(e.target.result);
                req.onerror = e => reject(e.target.error);
            });
        }

        async function sauvegarderEnFile(payload) {
            const db = await ouvrirIDB();
            const tx = db.transaction(STORE_NAME, 'readwrite');
            const store = tx.objectStore(STORE_NAME);
            store.add({ ...payload, queued_at: Date.now() });
            return new Promise((res, rej) => {
                tx.oncomplete = res;
                tx.onerror = rej;
            });
        }

        async function rejouerFileDAttente() {
            const db = await ouvrirIDB();
            const tx = db.transaction(STORE_NAME, 'readwrite');
            const store = tx.objectStore(STORE_NAME);
            const req = store.getAll();
            req.onsuccess = async () => {
                const items = req.result;
                for (const item of items) {
                    try {
                        const res = await envoyerParAPI(item.intervention_id, item.pdf_data, item.client_email, item.csrf_token);
                        if (res.success) {
                            // Supprimer de la file
                            db.transaction(STORE_NAME, 'readwrite').objectStore(STORE_NAME).delete(item.id);
                            console.log('[LM] Email rejoué avec succès :', item.client_email);
                        }
                    } catch (e) {
                        console.warn('[LM] Rejouer échoué :', e);
                    }
                }
            };
        }

        // Écouter la reconnexion réseau
        window.addEventListener('online', () => {
            console.log('[LM] Connexion rétablie – rejouer la file d\'attente email');
            rejouerFileDAttente();
        });

        // ══════════════════════════════════════════════════════════════════
        // APPEL API ENVOI EMAIL
        // ══════════════════════════════════════════════════════════════════
        async function envoyerParAPI(interventionId, pdfBase64, clientEmail, csrfToken) {
            const formData = new FormData();
            formData.append('intervention_id', interventionId);
            formData.append('pdf_data', pdfBase64);
            formData.append('client_email', clientEmail);
            formData.append('csrf_token', csrfToken);

            const resp = await fetch('/envoyer_rapport.php', {
                method: 'POST',
                body: formData,
                credentials: 'same-origin',
            });
            if (!resp.ok) throw new Error('HTTP ' + resp.status);
            return resp.json();
        }

        // ══════════════════════════════════════════════════════════════════
        // FONCTION PRINCIPALE : LANCER L'ENVOI EMAIL
        // ══════════════════════════════════════════════════════════════════
        async function lancerEnvoiEmail(auto = false) {
            if (!window.LM_RAPPORT) return;

            const { interventionId, clientEmail, csrfToken, nomSociete } = window.LM_RAPPORT;

            if (!clientEmail) {
                afficherToast('⚠️ Aucun email client renseigné. Veuillez reprendre le formulaire.', 'error');
                return;
            }

            const btn = document.getElementById('btnSendEmail');
            const icon = document.getElementById('btnSendEmailIcon');
            const label = document.getElementById('btnSendEmailLabel');

            if (btn) btn.disabled = true;
            if (icon) icon.textContent = '⏳';
            if (label) label.textContent = 'génération du PDF…';

            let pdfBase64;
            try {
                pdfBase64 = await genererPDFBase64();
            } catch (e) {
                if (btn) btn.disabled = false;
                if (icon) icon.textContent = '📧';
                if (label) label.textContent = 'Envoyer PDF par email';
                afficherToast('❌ Erreur génération PDF : ' + e.message, 'error');
                return;
            }

            if (icon) icon.textContent = '📤';
            if (label) label.textContent = 'Envoi en cours…';

            // Hors-ligne : mettre en file d'attente
            if (!navigator.onLine) {
                try {
                    await sauvegarderEnFile({
                        intervention_id: interventionId,
                        pdf_data: pdfBase64,
                        client_email: clientEmail,
                        csrf_token: csrfToken,
                    });
                    afficherToast('Connexion hors-ligne – email mis en file d\'attente. Il sera envoyé automatiquement à la reconnexion.', 'warning');
                } catch (e) {
                    afficherToast('❌ Impossible de mettre l\'email en file d\'attente.', 'error');
                }
                if (btn) btn.disabled = false;
                if (icon) icon.textContent = '📧';
                if (label) label.textContent = 'Envoyer PDF par email';
                return;
            }

            // En ligne : envoi direct
            try {
                const result = await envoyerParAPI(interventionId, pdfBase64, clientEmail, csrfToken);
                if (result.success) {
                    if (result.simulation_html) {
                        afficherToast('🧪 ' + result.message, 'warning');
                        if (btn) {
                            btn.style.background = 'linear-gradient(135deg, #f59e0b, #d97706)';
                            btn.innerHTML = '<span>👁️</span> Voir la Simulation';
                            btn.onclick = () => {
                                const sw = window.open('', '_blank');
                                sw.document.write(result.simulation_html);
                                sw.document.close();
                            };
                            btn.disabled = false;
                        }
                    } else {
                        afficherToast('✅ Rapport envoyé avec succès à ' + result.email, 'success');
                        if (btn) btn.style.background = 'linear-gradient(135deg,#10b981,#059669)';
                        if (icon) icon.textContent = '✅';
                        if (label) label.textContent = 'Email envoyé !';
                        btn.disabled = true; // Ne pas renvoyer
                    }
                } else {
                    afficherToast('❌ ' + (result.message || 'Erreur envoi email'), 'error');
                    if (btn) btn.disabled = false;
                    if (icon) icon.textContent = '🔄';
                    if (label) label.textContent = 'Réessayer l\'envoi';
                }
            } catch (e) {
                // Réseau coupé pendant l'envoi
                try {
                    await sauvegarderEnFile({
                        intervention_id: interventionId,
                        pdf_data: pdfBase64,
                        client_email: clientEmail,
                        csrf_token: csrfToken,
                    });
                    afficherToast('Connexion affaiblie – email mis en file d\'attente. Il sera envoyé automatiquement dès que possible.', 'warning');
                } catch (qe) {
                    afficherToast('❌ Erreur réseau et impossible de mettre en file : ' + e.message, 'error');
                }
                if (btn) btn.disabled = false;
                if (icon) icon.textContent = '🔄';
                if (label) label.textContent = 'Réessayer l\'envoi';
            }
        }

        // ══════════════════════════════════════════════════════════════════
        // génération MASSIVE IA (Toutes les fiches)
        // ══════════════════════════════════════════════════════════════════
        async function generateAllIA() {
            const btn = document.getElementById('btnGenerateAllIa');
            const progressZone = document.getElementById('ai-global-progress');
            const progressBar = document.getElementById('ai-progress-bar');
            const progressText = document.getElementById('ai-progress-text');
            const progressPercent = document.getElementById('ai-progress-percent');
            
            if (!containerSuccessBanner()) {
                if (!confirm("Cette opération va analyser toutes les machines de l'intervention. Les fiches déjà modifiées manuellement seront ignorées. Continuer ?")) return;
            }

            btn.disabled = true;
            btn.innerHTML = '<span>⚙️</span> Analyse en cours...';
            progressZone.style.display = 'block';

            const machines = window.LM_RAPPORT.machinesData;
            const total = machines.length;
            let successCount = 0;
            let skipCount = 0;

            for (let i = 0; i < total; i++) {
                const m = machines[i];
                const pct = Math.round((i / total) * 100);
                progressBar.style.width = pct + '%';
                progressPercent.textContent = pct + '%';
                progressText.textContent = `Analyse de : ${m.designation}...`;

                try {
                    // On considère qu'une fiche est déjà remplie si Dysfonctionnements OU Conclusion
                    // contiennent du texte autre que les valeurs par défaut.
                    const dys = (m.dysfonctionnements || "").trim();
                    const conc = (m.conclusion || "").trim();
                    const isManual = (dys.length > 5 && !dys.includes("Aucun dysfonctionnement majeur")) 
                                  || (conc.length > 5 && !conc.includes("conforme à nos standards"));

                    if (isManual) {
                        skipCount++;
                        continue;
                    }

                    const resIa = await fetch(`generate_ia.php?machine_id=${m.id}&intervention_id=${window.LM_RAPPORT.interventionId}`);
                    const dataIa = await resIa.json();

                    if (dataIa.success) {
                        const saveResp = await fetch('save_ia.php', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                            body: `machine_id=${m.id}&intervention_id=${window.LM_RAPPORT.interventionId}&dysfonctionnements=${encodeURIComponent(dataIa.dysfonctionnements)}&conclusion=${encodeURIComponent(dataIa.conclusion)}&csrf_token=${window.LM_RAPPORT.csrfToken}`
                        });
                        const saveResult = await saveResp.json();
                        if (saveResult.success) {
                            successCount++;
                            m.dysfonctionnements = dataIa.dysfonctionnements;
                            m.conclusion = dataIa.conclusion;
                        }
                    }
                } catch (e) {
                    console.error("IA Error for machine " + m.id, e);
                }
            }

            progressBar.style.width = '100%';
            progressPercent.textContent = '100%';
            progressText.textContent = 'Analyse terminée !';
            
            setTimeout(() => {
                alert(`Analyse terminée !\n- ${successCount} fiches générées\n- ${skipCount} fiches ignorées (déjà remplies)\n\nLe rapport va être actualisé.`);
                location.reload();
            }, 500);
        }

        function containerSuccessBanner() {
            return document.getElementById('successBanner') !== null;
        }
    </script>
</body>

</html>  
 
   
 
 
