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
    <script src="https://unpkg.com/pdf-lib@1.17.1/dist/pdf-lib.min.js"></script>

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
        <div class="download-status-text">Génération de votre rapport premium</div>
        <div style="color: var(--text-dim); font-size: 0.9rem;">Veuillez patienter quelques instants...</div>
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
                        <span>🚀</span> Lancer l'IA sur toutes les fiches
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
                    $statusColor = '#94a3b8'; // dÃ©faut
                    if (stripos($mStatus, 'conforme') !== false && stripos($mStatus, 'non') === false) $statusColor = '#10b981';
                    if (stripos($mStatus, 'améloriation') !== false) $statusColor = '#f59e0b';
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
                    <span>Une offre de piÃ¨ces de rechange</span>
                </label>
                <label class="checkbox-item">
                    <input type="checkbox" name="souhait_pieces_intervention" value="1" class="chk-souhait"
                        <?= ($intervention['souhait_pieces_intervention'] ?? false) ? 'checked' : '' ?>>
                    <span>PiÃ¨ces de rechange + intervention mise en place</span>
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
                placeholder="Remarques ou demandes spÃ©cifiques du client..."><?= htmlspecialchars($intervention['commentaire_client'] ?? '') ?></textarea>

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

    <!-- Libs PDF : jsPDF + html2canvas exposes comme globals -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
    <!-- html2pdf.js pour gÃ©nÃ©ration PDF cÃ´tÃ© client -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
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

            if (pad) {
                pad.clear(); // RÃ©initialise pour Ã©viter les distorsions si on redimensionne
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
            if (window.LM_RAPPORT && window.LM_RAPPORT.sigTech) {
                try {
                    padTech.fromDataURL(window.LM_RAPPORT.sigTech, { ratio: dpr, width: canvasT.width / dpr, height: canvasT.height / dpr });
                } catch (e) { console.error("Error loading tech sig:", e); }
            }

            // Client Pad
            resizeCanvas(canvasC);
            canvasWidthC = canvasC.offsetWidth;
            padClient = new SignaturePad(canvasC, {
                penColor: 'blue',
                throttle: 16,
                minWidth: 1.5,
                maxWidth: 4.5
            });
            if (window.LM_RAPPORT && window.LM_RAPPORT.sigClient) {
                try {
                    padClient.fromDataURL(window.LM_RAPPORT.sigClient, { ratio: dpr, width: canvasC.width / dpr, height: canvasC.height / dpr });
                } catch (e) { console.error("Error loading client sig:", e); }
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
            initSignatures();

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

        // ══════════════════════════════════════════════════════════════════
        // ENGINE V4.1 : STYLES ET HELPERS MODULAIRES (100+ PAGES)
        // ══════════════════════════════════════════════════════════════════

        const PDF_STYLES = `
            .pdf-page { width: 21cm; min-height: 100px; background: white; color: black; padding: 0 15mm; box-sizing: border-box; margin: 0; font-family: Arial, sans-serif; font-size: 13px; position: relative; }
            .pdf-section-title { text-align: left !important; color: #d35400 !important; font-size: 16px !important; margin-bottom: 20px !important; border-bottom: 2px solid #d35400 !important; padding-bottom: 5px !important; text-transform: uppercase !important; font-weight: bold !important; page-break-after: avoid !important; }
            .section-wrapper-pdf { page-break-inside: avoid !important; break-inside: avoid !important; margin-top: 25px !important; margin-bottom: 25px !important; display: block !important; width: 100% !important; }
            .pdf-table-container, .card, .sig-zone, .photo-annexe-item { page-break-inside: avoid !important; }
            .pdf-table { width: 100%; border-collapse: collapse; margin-bottom: 20px; color: black; font-size: 11px; table-layout: fixed; }
            .pdf-table th, .pdf-table td { border: 1px solid #000; padding: 4px; vertical-align: middle; word-wrap: break-word; word-break: break-word; }
            .pdf-table th { background-color: #f0f0f0; text-align: left; text-transform: uppercase; }
            .pastille-group { display: flex; gap: 4px; align-items: center; justify-content: center; width: 100%; height: 100%; }
            .pastille-group input[type="radio"] { display: none !important; }
            .pastille-group label { display: inline-block; width: 18px; height: 18px; border-radius: 50%; border: 1px solid #777 !important; background: #eee !important; position: relative; }
            .pastille-group label.selected.p-ok { background-color: #28a745 !important; border-color: #1e7e34 !important; }
            .pastille-group label.selected.p-aa { background-color: #f39c12 !important; border-color: #d35400 !important; }
            .pastille-group label.selected.p-nc { background-color: #dc3545 !important; border-color: #bd2130 !important; }
            .pastille-group label.selected.p-nr { background-color: #8b0000 !important; border-color: #5a0000 !important; }
            .pastille-group label.selected.p-na { background-color: #95a5a6 !important; border-color: #7f8c8d !important; }
            .pastille-group label.selected::after { content: ""; position: absolute; top: 50%; left: 50%; width: 6px; height: 6px; background: white; border-radius: 50%; transform: translate(-50%, -50%); }
            .diagonal-header { height: 120px; vertical-align: bottom; padding: 0 !important; position: relative; background: #e0e0e0 !important; border: 1px solid #000 !important; }
            .diagonal-wrapper { display: flex; width: 140px; height: 100%; position: relative; margin: 0 auto; }
            .diag-col { width: 28px; height: 100%; position: relative; flex-shrink: 0; }
            .diag-col::before { content: ""; position: absolute; left: 100%; top: 0; bottom: 30px; width: 1px; background: #000; transform: skewX(-35deg); transform-origin: bottom left; }
            .diag-text { position: absolute; bottom: 35px; left: 100%; padding-left: 5px; transform: rotate(-55deg); transform-origin: bottom left; text-align: left; white-space: nowrap; font-size: 8px; font-weight: bold; width: 200px; }
            .pdf-textarea-rendered { width: 100%; font-family: Arial; font-size: 9pt; color: black; white-space: pre-wrap; padding: 4px; }
            .no-print-pdf, .btn-ia-refresh, .top-bar, .photo-btn, .photo-thumbs, #btnChrono { display: none !important; }
            img { max-width: 100%; }
        `;

        async function waitForImages(element) {
            const images = element.querySelectorAll('img');
            const promises = Array.from(images).map(img => {
                if (img.complete) return Promise.resolve();
                return new Promise(resolve => { img.onload = resolve; img.onerror = resolve; });
            });
            await Promise.all(promises);
            return new Promise(r => setTimeout(r, 200));
        }

        function createPdfFooter() {
            const f = document.createElement('div');
            const leg = window.LM_RAPPORT.legal;
            f.style.cssText = 'margin-top:20px; width:100%; text-align:center; font-size:9px; font-weight:bold; border-top:2px solid #000; padding:5px 0; page-break-inside:avoid;';
            f.innerHTML = `${leg.address}<br>${leg.contact}<br>${leg.siret}`;
            return f;
        }

        async function getPdfBytes(element) {
            const opt = { margin:0, filename:'temp.pdf', image:{type:'jpeg',quality:0.98}, html2canvas:{scale:2, useCORS:true}, jsPDF:{unit:'mm', format:'a4', orientation:'portrait'} };
            const pdfBuf = await html2pdf().set(opt).from(element).outputPdf('arraybuffer');
            return new Uint8Array(pdfBuf);
        }

        async function buildMachinePageContainer(mId, mIdx, totalMachines) {
            const container = document.createElement('div');
            const styleNode = document.createElement('style');
            styleNode.textContent = PDF_STYLES;
            container.appendChild(styleNode);
            try {
                const res = await fetch(`machine_edit.php?id=${mId}&pdf=1`);
                const html = await res.text();
                const parser = new DOMParser();
                const doc = parser.parseFromString(html, 'text/html');
                const pages = Array.from(doc.querySelectorAll('.pdf-page'));
                pages.forEach((p, pIdx) => {
                    p.style.margin = '0'; p.style.boxShadow = 'none';
                    p.querySelectorAll('.photo-btn, .btn-ia-refresh, .top-bar, .photo-thumbs, #btnChrono, .no-print-pdf, .photo-del-overlay').forEach(el => el.remove());
                    if (pIdx === 0) {
                        p.style.pageBreakBefore = 'always';
                        const hDiv = document.createElement('div');
                        hDiv.style.cssText = 'text-align:right; font-size:12px; font-weight:bold; color:#1B4F72; margin-bottom:5px;';
                        hDiv.innerHTML = `FICHE ${mIdx + 1} / ${totalMachines}`;
                        p.insertBefore(hDiv, p.firstChild);
                    }
                    p.querySelectorAll('input:not([type="radio"]):not([type="hidden"])').forEach(inp => {
                        let val = (inp.value || '').trim();
                        if (inp.name === 'mesures[poste]') val = val || (mIdx + 1);
                        inp.outerHTML = `<span style="border-bottom:1px dashed black; display:inline-block; min-width:30px; padding:0 3px; font-weight:bold;">${val}</span>`;
                    });
                    p.querySelectorAll('textarea').forEach(ta => {
                        const val = ta.value || ta.innerHTML;
                        if (val.trim()) {
                            const div = document.createElement('div');
                            div.className = 'pdf-textarea-rendered';
                            div.textContent = val;
                            ta.parentNode.insertBefore(div, ta);
                        }
                        ta.remove();
                    });
                    p.querySelectorAll('img').forEach(img => {
                        if (img.src.startsWith('/') && !img.src.startsWith('//')) {
                            img.src = window.location.origin + img.src;
                        }
                    });
                    p.appendChild(createPdfFooter());
                    container.appendChild(p);
                });
            } catch (err) { console.error('Error machine ' + mId, err); }
            return container;
        }

        async function buildHeaderPagesContainer() {
            const container = document.createElement('div');
            const styleNode = document.createElement('style');
            styleNode.textContent = PDF_STYLES;
            container.appendChild(styleNode);
            const d = window.LM_RAPPORT;
            const techNameLabel = "<?= htmlspecialchars($techName) ?>";
            const sigTechData = d.sigTech || document.getElementById('canvasTech')?.toDataURL() || '';

            const p1 = document.createElement('div');
            p1.className = 'pdf-page';
            p1.innerHTML = `
                <table style="width:100%; border:none; margin-bottom:15px; border-bottom: 2px solid #1e4e6d;">
                    <tr><td style="width: 40%; vertical-align: bottom; padding-bottom: 10px;"><img src="/assets/lenoir_logo_doc.png" style="height:60px;"></td><td style="width: 60%; vertical-align: bottom; text-align: right; padding-bottom: 5px;"><div style="font-size: 11px; color:#1e4e6d; font-style:italic;">Le spécialiste des applications magnétiques pour la séparation et le levage industriel</div></td></tr>
                </table>
                <div style="border:3px solid #1e4e6d; padding:15px; margin-bottom:30px;">
                    <h1 style="text-align:center; color:#1e4e6d; font-size:26px; margin: 10px 0 20px 0;">RAPPORT DE L'EXPERTISE</h1>
                    <div style="text-align:right; font-weight:bold; font-size:14px; margin-bottom:15px;">N°ARC : ${d.arc}</div>
                    <table style="width:100%; border-collapse:collapse; border:2px solid #1e4e6d; margin-bottom:20px; font-size:12px;">
                        <tr><td colspan="4" style="background:#5b9bd5; color:white; text-align:center; font-weight:bold; padding:6px; border:1px solid #000;">COORDONNEES DU CLIENT</td></tr>
                        <tr><td style="font-weight:bold; padding:6px; border:1px solid #000; width:20%;">Société</td><td style="padding:6px; border:1px solid #000; width:30%;">${d.nomSociete}</td><td style="font-weight:bold; padding:6px; border:1px solid #000; width:20%;">Date</td><td style="padding:6px; border:1px solid #000; width:30%;">${d.dateInt}</td></tr>
                    </table>
                </div>
                <table style="width:100%; border-collapse:collapse; border:2px solid #1e4e6d; font-size:13px;">
                    <tr><td style="font-weight:bold; padding:15px; border:1px solid #1e4e6d; width:25%;">Technicien:</td><td style="padding:15px; border:1px solid #1e4e6d; width:30%;">${techNameLabel}</td><td style="padding:5px; border:1px solid #1e4e6d; width:45%; text-align:center;"><img src="${sigTechData}" style="max-height:80px;"></td></tr>
                </table>
            `;
            p1.appendChild(createPdfFooter());
            container.appendChild(p1);

            const p2 = document.createElement('div');
            p2.className = 'pdf-page';
            p2.style.pageBreakBefore = 'always';
            const s = d.synth;
            p2.innerHTML = `<div style="padding-top:20px;"><h2 style="font-weight:bold; font-size:18px; color:#1e4e6d; margin:0 0 25px 0; border-bottom:2px solid #1e4e6d; text-transform:uppercase;">SYNTHÈSE DE L'INTERVENTION</h2><div style="margin-bottom:15px; font-size:13px;"><div><strong>Technicien :</strong> ${s.tech}</div><div><strong>Date :</strong> ${s.date}</div><div><strong>Équipements :</strong> ${s.nbMachines}</div></div></div>`;
            p2.appendChild(createPdfFooter());
            container.appendChild(p2);
            return container;
        }

        async function buildFooterPageContainer() {
            const container = document.createElement('div');
            const styleNode = document.createElement('style');
            styleNode.textContent = PDF_STYLES;
            container.appendChild(styleNode);
            const d = window.LM_RAPPORT;
            const endPage = document.createElement('div');
            endPage.className = 'pdf-page';
            endPage.style.pageBreakBefore = 'always';
            const sigClientData = d.sigClient || document.getElementById('canvasClient')?.toDataURL() || '';
            const nomSignataire = document.querySelector('[name="nom_signataire"]')?.value || '_____';
            endPage.innerHTML = `<div style="padding-top:20px;"><div style="margin-top:40px;"><h2 style="color:#1e4e6d; font-size:16px; border-bottom:2px solid #1e4e6d; padding-bottom:5px; text-transform:uppercase;">SIGNATURES</h2><table style="width:100%; border:1px solid #1e4e6d;"><tr><td style="width:50%; padding:20px; text-align:center; border-right:1px solid #1e4e6d;"><strong>Technicien LENOIR</strong><br><br><img src="${d.sigTech || ''}" style="max-height:100px;"></td><td style="width:50%; padding:20px; text-align:center;"><strong>Client (${nomSignataire})</strong><br><br><img src="${sigClientData}" style="max-height:100px;"></td></tr></table></div></div>`;
            endPage.appendChild(createPdfFooter());
            container.appendChild(endPage);
            return container;
        }

        async function genererPDFBlob(onProgress) {
            const { PDFDocument, rgb } = PDFLib;
            const finalPdf = await PDFDocument.create();
            const ids = (window.LM_RAPPORT && window.LM_RAPPORT.machinesIds) ? window.LM_RAPPORT.machinesIds : [];
            const total = 2 + ids.length;
            let step = 0;
            const addLayer = async (cnt, lbl) => {
                step++; if (onProgress) onProgress(Math.round((step / total) * 100), lbl);
                const bytes = await getPdfBytes(cnt);
                const doc = await PDFDocument.load(bytes);
                const pages = await finalPdf.copyPages(doc, doc.getPageIndices());
                pages.forEach(p => finalPdf.addPage(p));
            };
            await addLayer(await buildHeaderPagesContainer(), "Préparation de la couverture...");
            for (let i = 0; i < ids.length; i++) {
                await addLayer(await buildMachinePageContainer(ids[i], i, ids.length), `Fiche ${i + 1}/${ids.length}...`);
            }
            await addLayer(await buildFooterPageContainer(), "Finalisation...");
            const pages = finalPdf.getPages();
            if (pages.length > 0) {
                const { width, height } = pages[0].getSize();
                for (let i = 0; i < pages.length; i++) {
                    pages[i].drawText(`Page ${i + 1} / ${pages.length}`, { x: width / 2 - 20, y: 15, size: 9, color: rgb(0.3, 0.3, 0.3) });
                }
            }
            return await finalPdf.save();
        }

        async function telechargerPDF() {
            const btn = document.getElementById('btnDownloadPDF');
            const progressZone = document.getElementById('ai-global-progress');
            const progressBar = document.getElementById('ai-progress-bar');
            const progressText = document.getElementById('ai-progress-text');
            const progressPercent = document.getElementById('ai-progress-percent');
            if (btn) btn.disabled = true;
            if (progressZone) progressZone.style.display = 'block';
            try {
                const bytes = await genererPDFBlob((pct, msg) => {
                    if (progressBar) progressBar.style.width = pct + '%';
                    if (progressPercent) progressPercent.textContent = pct + '%';
                    if (progressText) progressText.textContent = msg;
                });
                const blob = new Blob([bytes], { type: 'application/pdf' });
                const link = document.createElement('a');
                link.href = URL.createObjectURL(blob);
                link.download = `Rapport_Expertise_${(window.LM_RAPPORT && window.LM_RAPPORT.arc) || 'Final'}.pdf`;
                link.click();
            } catch (e) { alert("Erreur génération PDF: " + e.message); }
            finally { if (btn) btn.disabled = false; setTimeout(() => { if (progressZone) progressZone.style.display = 'none'; }, 2000); }
        }

        async function genererPDFBase64() {
             const bytes = await genererPDFBlob();
             return btoa(String.fromCharCode(...new Uint8Array(bytes)));
        }

        function afficherToast(message, type = 'success') {
            const toast = document.getElementById('emailToast');
            if (!toast) return;
            toast.textContent = message;
            toast.style.cssText = 'display:block; background:rgba(0,0,0,0.8); color:white; padding:10px; border-radius:5px; position:fixed; bottom:20px; left:50%; transform:translateX(-50%); z-index:9999;';
            setTimeout(() => { toast.style.display = 'none'; }, 3000);
        }

        async function envoyerParAPI(interventionId, pdfBase64, clientEmail, csrfToken) {
            const formData = new FormData();
            formData.append('intervention_id', interventionId);
            formData.append('pdf_data', pdfBase64);
            formData.append('client_email', clientEmail);
            formData.append('csrf_token', csrfToken);
            const resp = await fetch('/envoyer_rapport.php', { method: 'POST', body: formData, credentials: 'same-origin' });
            return resp.json();
        }

        async function lancerEnvoiEmail() {
            if (!window.LM_RAPPORT) return;
            try {
                const b64 = await genererPDFBase64();
                const res = await envoyerParAPI(window.LM_RAPPORT.interventionId, b64, window.LM_RAPPORT.clientEmail, window.LM_RAPPORT.csrfToken);
                if (res.success) afficherToast('Email envoyé !');
                else alert('Erreur : ' + res.message);
            } catch (e) { alert('Erreur : ' + e.message); }
        }

        async function generateAllIA() {
            const progressZone = document.getElementById('ai-global-progress');
            if (progressZone) progressZone.style.display = 'block';
            location.reload(); 
        }

        function containerSuccessBanner() { return document.getElementById('successBanner') !== null; }
    </script>
</body>
</html>
