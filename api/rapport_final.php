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

            if (empty($contactPrenom)) { $error = "Le prÃ©nom du contact est obligatoire."; }
            elseif (empty($contactNom)) { $error = "Le nom du contact est obligatoire."; }
            elseif (strlen($contactPrenom) > 50) { $error = "Le prÃ©nom du contact ne doit pas dÃ©passer 50 caractÃ¨res."; }
            elseif (strlen($contactNom) > 50) { $error = "Le nom du contact ne doit pas dÃ©passer 50 caractÃ¨res."; }
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

$now = date('d/m/Y') . ' Ã  ' . date('H:i');

// --- CALCUL DES STATISTIQUES POUR LA SYNTHÃˆSE ---
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

    // DurÃ©e rÃ©alisÃ©e par machine
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

    // Ã‰tats de contrÃ´le
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
                elseif ($v === 'aa' || $v === 'r' || $v === 'A amÃ©liorer')
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
    $dureeSynth = "Non renseignÃ©";
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

        /* Premium Downloader Overlay */
        #pdfDownloadOverlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: #020617;
            z-index: 10000;
            display: none;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            color: #fff;
            font-family: 'Inter', sans-serif;
        }

        .loader-lenoir {
            width: 120px;
            height: 120px;
            position: relative;
            margin-bottom: 2rem;
        }

        .loader-lenoir::before, .loader-lenoir::after {
            content: '';
            position: absolute;
            border-radius: 50%;
            border: 4px solid transparent;
            border-top-color: var(--primary);
            width: 100%;
            height: 100%;
            animation: spin 1.5s linear infinite;
        }

        .loader-lenoir::after {
            width: 80%;
            height: 80%;
            top: 10%;
            left: 10%;
            border-top-color: var(--accent-cyan);
            animation-duration: 1s;
            animation-direction: reverse;
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
        }

        .download-status-text {
            font-size: 1.25rem;
            font-weight: 600;
            background: linear-gradient(90deg, #fff, var(--primary));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            margin-bottom: 0.5rem;
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
        <div class="loader-lenoir"></div>
        <div class="download-status-text" id="pdfProgressText">GÃ©nÃ©ration de votre rapport premium</div>
        
        <!-- Bar de progression globale -->
        <div style="width: 250px; height: 10px; background: rgba(255,255,255,0.1); border-radius: 5px; margin-top: 20px; position: relative; overflow: hidden; border: 1px solid rgba(255,255,255,0.2);">
            <div id="pdfProgressBar" style="width: 0%; height: 100%; background: var(--primary); transition: width 0.3s ease;"></div>
        </div>
        
        <div id="pdfProgressDetail" style="color: var(--text-dim); font-size: 0.8rem; margin-top: 10px;">PrÃ©paration des donnÃ©es...</div>
    </div>

    <!-- Interface IA globale -->
    <div id="ai-global-progress" style="display:none; position:fixed; top:20px; right:20px; width:300px; background:rgba(15,23,42,0.95); backdrop-filter:blur(10px); border:1px solid var(--primary); border-radius:12px; padding:1.5rem; z-index:10001; box-shadow:0 20px 50px rgba(0,0,0,0.5);">
        <div style="font-weight:bold; color:var(--primary); margin-bottom:10px; display:flex; align-items:center; gap:8px;">
            <img src="/assets/ai_expert.jpg" style="height:24px; width:24px; border-radius:50%;">
            <span>Analyse IA en cours...</span>
        </div>
        <div style="width:100%; height:6px; background:rgba(255,255,255,0.1); border-radius:3px; overflow:hidden; margin-bottom:10px;">
            <div id="ai-progress-bar" style="width:0%; height:100%; background:var(--primary); transition:width 0.3s;"></div>
        </div>
        <div style="display:flex; justify-content:space-between; font-size:0.75rem;">
            <span id="ai-progress-text" style="color:var(--text-dim);">DÃ©marrage...</span>
            <span id="ai-progress-percent" style="color:var(--primary); font-weight:bold;">0%</span>
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

            <!-- DonnÃ©es PHP exposÃ©es pour le JS -->
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
                            'repere' => $mes['repere'] ?? 'â€”',
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
                        <span>🚀</span> Lancer l'IA sur toutes les fiches
                    </button>
                </div>
                <p style="font-size: 0.85rem; color: var(--text-dim); margin-bottom: 1rem;">
                    Cette fonction analyse automatiquement chaque fiche pour générer les sections "Dysfonctionnements" et "Conclusions" en se basant sur vos relevés. 
                    <strong>Les fiches déjà modifiées manuellement ne seront pas écrasées sans confirmation.</strong>
                </p>
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
                            value="<?= htmlspecialchars($intervention['contact_fonction'] ?? $intervention['c_fonction'] ?? '') ?>">
                    </div>
                    <div class="form-group">
                        <label class="label">Email <span style="color:var(--error);">*</span></label>
                        <input type="email" name="contact_email" class="input" placeholder="client@societe.com"
                            value="<?= htmlspecialchars($intervention['contact_email'] ?? $intervention['c_email'] ?? '') ?>"
                            required>
                    </div>
                </div>

                <div class="field-row">
                    <div class="form-group">
                        <label class="label">Téléphone</label>
                        <input type="tel" name="contact_telephone" class="input" placeholder="06 12 34 56 78"
                            value="<?= htmlspecialchars($intervention['contact_telephone'] ?? $intervention['c_tel'] ?? '') ?>">
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
                    <input type="hidden" name="sigTech" id="sigTechInput">
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

    <!-- Libs PDF -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/pdf-lib@1.17.1/dist/pdf-lib.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/signature_pad@4.0.0/dist/signature_pad.umd.min.js"></script>
    <script>
        let padClient, padTech;

        // â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
        // 1. STYLE ADN VISUELL PDF (V4.2 STABLE)
        // â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
        const GLOBAL_PDF_STYLES = `
            .pdf-page { width: 21cm; min-height: 29.7cm; padding: 1.5cm 1cm 1.5cm 1cm; background: white; color: black; font-family: Arial, sans-serif; font-size: 13px; position: relative; box-sizing: border-box; margin: 0; overflow: visible !important; }
            .pdf-header { display: flex; justify-content: space-between; align-items: center; border-bottom: 2px solid #1e4e6d; padding-bottom: 15px; margin-bottom: 20px; }
            .lenoir-title { font-size: 24px; font-weight: 900; color: #1e4e6d; margin: 0; text-transform: uppercase; }
            .pdf-title-box { text-align: center; border: 2px solid #1e4e6d; padding: 10px; font-size: 20px; font-weight: bold; background: #f0f0f0; margin-bottom: 20px; color: #1e4e6d; }
            .pdf-section-title { font-weight: bold; font-size: 16px; color: #d35400 !important; margin-bottom: 10px; border-bottom: 2px solid #d35400; padding-bottom: 5px; text-transform: uppercase; }
            .pdf-table { width: 100%; border-collapse: collapse; margin-bottom: 20px; color: black; font-size: 11px; table-layout: fixed; }
            .pdf-table th, .pdf-table td { border: 1px solid #1e4e6d; padding: 4px 5px; vertical-align: middle; word-wrap: break-word; }
            .section-header-row { height: 30px; background: #5b9bd5 !important; color: white !important; font-weight: bold; -webkit-print-color-adjust: exact; }
            .pastille-group { display: flex; align-items: center; justify-content: space-around; width: 135px; margin: 0 auto; -webkit-print-color-adjust: exact; }
            .pastille-group label { display: inline-flex; align-items: center; justify-content: center; width: 22px; height: 22px; border-radius: 50%; border: 1px solid #777; background: #eee; position: relative; -webkit-print-color-adjust: exact; }
            .pastille-group label.selected.p-ok { background: #28a745 !important; }
            .pastille-group label.selected.p-aa { background: #e67e22 !important; }
            .pastille-group label.selected.p-nc { background: #dc3545 !important; }
            .pastille-group label.selected.p-nr { background: #8b0000 !important; }
            .pastille-group label.selected::after { content: "✓" !important; color: white !important; font-size: 14px; font-weight: bold; position: absolute; }
            .diagonal-header { height: 110px; vertical-align: bottom; padding: 0 !important; position: relative; background: #e0e0e0 !important; overflow: visible; -webkit-print-color-adjust: exact; border: 1px solid #1e4e6d !important; }
            .diagonal-wrapper { display: flex; width: 140px; height: 100%; margin: 0 auto; position: relative; }
            .diag-col { width: 28px; height: 100%; position: relative; border-right: 1px solid #1e4e6d; }
            .diag-col::before { content: ""; position: absolute; left: 100%; top: 0; bottom: 35px; width: 1px; background: #1e4e6d; transform: skewX(-35deg); transform-origin: bottom left; }
            .diag-text { position: absolute; bottom: 38px; left: 100%; transform: rotate(-55deg); transform-origin: bottom left; font-size: 8px; font-weight: bold; white-space: nowrap; width: 200px; color: #000; }
            .pdf-footer { position: absolute; bottom: 1cm; left: 1cm; right: 1cm; text-align: center; font-size: 9px; font-weight: bold; border-top: 1px solid #1e4e6d; padding-top: 5px; color: #555; }
            .no-print-pdf, .btn-ia-refresh, .top-bar, .photo-btn, .photo-thumbs, #btnChrono, .photo-del-overlay { display: none !important; }
        `;

        function initPads() {
            const canvasC = document.getElementById('canvasClient');
            const canvasT = document.getElementById('canvasTech');
            
            if (canvasC && canvasT && typeof SignaturePad !== 'undefined') {
                const ratio = Math.max(window.devicePixelRatio || 1, 1);
                
                // Tech Pad
                canvasT.width = canvasT.offsetWidth * ratio;
                canvasT.height = (canvasT.offsetHeight || 200) * ratio;
                canvasT.getContext("2d").scale(ratio, ratio);
                padTech = new SignaturePad(canvasT, { penColor: 'rgb(0,0,0)', minWidth: 1, maxWidth: 3 });

                // Client Pad
                canvasC.width = canvasC.offsetWidth * ratio;
                canvasC.height = (canvasC.offsetHeight || 200) * ratio;
                canvasC.getContext("2d").scale(ratio, ratio);
                padClient = new SignaturePad(canvasC, { penColor: 'rgb(0,0,255)', minWidth: 1, maxWidth: 3 });

                window.onresize = () => {
                    if(padClient && padTech) {
                        const dataC = padClient.toData();
                        const dataT = padTech.toData();
                        
                        canvasC.width = canvasC.offsetWidth * ratio;
                        canvasC.height = (canvasC.offsetHeight || 200) * ratio;
                        canvasC.getContext("2d").scale(ratio, ratio);
                        
                        canvasT.width = canvasT.offsetWidth * ratio;
                        canvasT.height = (canvasT.offsetHeight || 200) * ratio;
                        canvasT.getContext("2d").scale(ratio, ratio);
                        
                        padClient.fromData(dataC);
                        padTech.fromData(dataT);
                    }
                };
            }
        }

        window.onload = initPads;

        function validateAndSubmit() {
            if (!padTech || !padClient) {
                alert("Erreur: Les zones de signature ne sont pas chargées.");
                return false;
            }
            if (padTech.isEmpty() || padClient.isEmpty()) {
                alert('Signatures obligatoires (Technicien + Client).');
                return false;
            }
            const nomC = document.querySelector('[name="nom_signataire"]')?.value.trim();
            if (!nomC) {
                alert('Nom du signataire obligatoire.');
                return false;
            }
            document.getElementById('sigTechInput').value = padTech.toDataURL();
            document.getElementById('sigClientInput').value = padClient.toDataURL();
            return true;
        }

        // â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
        // 3. MOTEUR PDF CHUNKED (V4.2)
        // â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
        async function ensureImagesBase64(container) {
            const imgs = Array.from(container.querySelectorAll('img'));
            for (const img of imgs) {
                const src = img.getAttribute('src');
                if (!src || src.startsWith('data:')) continue;
                try {
                    const abs = src.startsWith('/') ? window.location.origin + src : src;
                    const b = await fetch(abs).then(r => r.blob());
                    img.src = await new Promise(res => { const r = new FileReader(); r.onload = () => res(r.result); r.readAsDataURL(b); });
                } catch (e) {}
            }
        }

        async function renderChunk(container) {
            await ensureImagesBase64(container);
            const style = document.createElement('style');
            style.textContent = GLOBAL_PDF_STYLES;
            container.appendChild(style);
            
            const opt = { 
                margin: 0, 
                image:{type:'jpeg',quality:0.98}, 
                html2canvas:{scale:2,useCORS:true}, 
                jsPDF:{unit:'mm',format:'a4',orientation:'portrait'} 
            };
            const pdfBlob = await html2pdf().set(opt).from(container).outputPdf('blob');
            return new Uint8Array(await pdfBlob.arrayBuffer());
        }

        function createPdfFooter() {
            const f = document.createElement('div');
            f.className = 'pdf-footer';
            const leg = window.LM_RAPPORT.legal;
            f.innerHTML = `${leg.address}<br>${leg.contact}<br>${leg.siret}`;
            return f;
        }

        async function genererPDFBlob() {
            const overlay = document.getElementById('pdfDownloadOverlay');
            const progressBar = document.getElementById('pdfProgressBar');
            const progressText = document.getElementById('pdfProgressDetail');
            
            overlay.style.display = 'flex';
            const pdfChunks = [];
            
            try {
                // CHUNK 1: COUVERTURE
                progressText.textContent = "Couverture...";
                progressBar.style.width = "10%";
                const coverPage = document.createElement('div');
                coverPage.style.width = '21cm';
                const i = window.LM_RAPPORT.info;
                coverPage.innerHTML = `
                    <div class="pdf-page">
                        <div class="pdf-header"><h1 class="lenoir-title">RAOUL LENOIR</h1><img src="/assets/lenoir_logo_doc.png" style="height:60px;"></div>
                        <div class="pdf-title-box">RAPPORT D'EXPERTISE TECHNIQUE</div>
                        <div class="section-wrapper-pdf">
                            <h2 class="pdf-section-title">CLIENT : ${i.client}</h2>
                            <p><strong>NÂ° ARC : ${i.numArc}</strong></p>
                            <p>Expertise Ã  : ${i.ville}</p>
                            <p>Date : ${window.LM_RAPPORT.dateInt}</p>
                        </div>
                        <div style="text-align:center; margin-top:50px;"><img src="/assets/tech_illustration.jpg" style="width:80%; border:2px solid #1e4e6d;"></div>
                    </div>
                `;
                coverPage.firstChild.appendChild(createPdfFooter());
                pdfChunks.push(await renderChunk(coverPage));

                // 4. LES FICHES (En morceaux)
                const ids = window.LM_RAPPORT.machinesIds || [];
                for (let idx=0; idx<ids.length; idx++) {
                    progressBar.style.width = (10 + Math.round((idx/ids.length)*80)) + "%";
                    progressText.textContent = `Machine ${idx+1}/${ids.length}...`;
                    
                    const res = await fetch(`machine_edit.php?id=${ids[idx]}&pdf=1`);
                    const doc = new DOMParser().parseFromString(await res.text(), 'text/html');
                    const mCont = document.createElement('div');
                    mCont.style.width = '21cm';
                    
                    doc.querySelectorAll('.pdf-page').forEach((p, pIdx) => {
                        p.className = 'pdf-page';
                        p.style.margin = '0';
                        p.querySelectorAll('.no-print-pdf, .top-bar, .photo-btn, .photo-thumbs, #btnChrono, .btn-ia-refresh, .photo-del-overlay').forEach(el => el.remove());
                        if (pIdx===0) {
                            const h = document.createElement('div');
                            h.style = "text-align:right; font-weight:bold; color:#1e4e6d; margin-bottom:10px;";
                            h.textContent = `FICHE ${idx + 1} / ${ids.length}`;
                            p.insertBefore(h, p.firstChild);
                        }
                        p.querySelectorAll('input[type="radio"]:checked').forEach(r => r.closest('label')?.classList.add('selected'));
                        p.appendChild(createPdfFooter());
                        mCont.appendChild(p);
                    });
                    pdfChunks.push(await renderChunk(mCont));
                }

                // CHUNK FINAL: SIGNATURES
                progressText.textContent = "Finalisation...";
                const endPage = document.createElement('div');
                endPage.style.width = '21cm';
                const sigT = padTech.toDataURL();
                const sigC = padClient.toDataURL();
                const nomC = document.querySelector('[name="nom_signataire"]')?.value || '_____';
                endPage.innerHTML = `
                    <div class="pdf-page">
                        <div class="pdf-section-title">CONCLUSIONS & SIGNATURES</div>
                        <table class="pdf-table" style="height:250px;">
                            <tr>
                                <td style="width:50%; vertical-align:top;"><strong>TECHNICIEN :</strong><br><img src="${sigT}" style="max-height:100px; display:block; margin:20px auto;"></td>
                                <td style="width:50%; vertical-align:top;"><strong>CLIENT (${nomC}) :</strong><br><img src="${sigC}" style="max-height:100px; display:block; margin:20px auto;"></td>
                            </tr>
                        </table>
                        <div style="text-align:center; margin-top:50px;"><img src="/assets/qr_lenoir.png" style="width:100px;"><br><strong>www.raoul-lenoir.com</strong></div>
                    </div>
                `;
                endPage.firstChild.appendChild(createPdfFooter());
                pdfChunks.push(await renderChunk(endPage));

                // ASSEMBLAGE
                progressText.textContent = "Fusion...";
                const merged = await PDFLib.PDFDocument.create();
                for (const bytes of pdfChunks) {
                    const doc = await PDFLib.PDFDocument.load(bytes);
                    const copied = await merged.copyPages(doc, doc.getPageIndices());
                    copied.forEach(p => merged.addPage(p));
                }
                return await merged.save();
                
            } finally { overlay.style.display = 'none'; }
        }

        async function telechargerPDF() {
            const btn = document.getElementById('btnDownloadPDF');
            if (btn) btn.disabled = true;
            try {
                const bytes = await genererPDFBlob();
                const link = document.createElement('a');
                link.href = URL.createObjectURL(new Blob([bytes], {type:'application/pdf'}));
                link.download = window.LM_RAPPORT.pdfFilename || 'rapport.pdf';
                link.click();
            } catch (e) { alert("Erreur : " + e.message); }
            finally { if (btn) btn.disabled = false; }
        }

        async function genererPDFBase64() {
            const bytes = await genererPDFBlob();
            return btoa(String.fromCharCode(...bytes));
        }

        async function lancerEnvoiEmail() {
            const btn = document.getElementById('btnSendEmail');
            if(btn) btn.disabled = true;
            try {
                const b64 = await genererPDFBase64();
                const fd = new FormData();
                fd.append('intervention_id', window.LM_RAPPORT.interventionId);
                fd.append('pdf_data', b64);
                fd.append('client_email', window.LM_RAPPORT.clientEmail);
                fd.append('csrf_token', window.LM_RAPPORT.csrfToken);
                const r = await fetch('/envoyer_rapport.php', { method:'POST', body:fd }).then(res => res.json());
                const toast = document.getElementById('emailToast');
                toast.textContent = r.success ? '✅ Envoyé !' : '❌ Erreur : ' + r.message;
                toast.style.background = r.success ? '#10b981' : '#f43f5e';
                toast.style.display = 'block';
                setTimeout(() => toast.style.display = 'none', 5000);
            } catch(e) { alert("Erreur Envoi : " + e.message); }
            finally { if(btn) btn.disabled = false; }
        }

        async function generateAllIA() {
            if(!confirm("Lancer l'IA sur toutes les fiches ?")) return;
            const progress = document.getElementById('ai-global-progress');
            progress.style.display = 'block';
            const m = window.LM_RAPPORT.machinesData;
            for(let i=0; i<m.length; i++) {
                document.getElementById('ai-progress-bar').style.width = Math.round((i/m.length)*100) + '%';
                try {
                    const r = await fetch(`generate_ia.php?machine_id=${m[i].id}&intervention_id=${window.LM_RAPPORT.interventionId}`).then(res => res.json());
                    if(r.success) {
                        await fetch('save_ia.php', {
                            method:'POST',
                            headers:{'Content-Type':'application/x-www-form-urlencoded'},
                            body:`machine_id=${m[i].id}&intervention_id=${window.LM_RAPPORT.interventionId}&dysfonctionnements=${encodeURIComponent(r.dysfonctionnements)}&conclusion=${encodeURIComponent(r.conclusion)}&csrf_token=${window.LM_RAPPORT.csrfToken}`
                        });
                    }
                } catch(e){}
            }
            alert("IA terminée !"); location.reload();
        }
    </script>
</body>
</html>
