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

$now = date('d/m/Y') . ' ├á ' . date('H:i');

// --- CALCUL DES STATISTIQUES POUR LA SYNTH├êSE ---
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

    // Dur├®e r├®alis├®e par machine
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

    // ├ëtats de contr├┤le
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
                elseif ($v === 'aa' || $v === 'r' || $v === 'A am├®liorer')
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
    $dureeSynth = "Non renseign├®";
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
        <div class="download-status-text">G├®n├®ration de votre rapport premium</div>
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
                    <h3 style="margin:0 0 0.5rem 0; color:#10b981;">Rapport finalis├® avec succ├¿s !</h3>
                    <p style="font-size:0.85rem; color:var(--text-dim); margin-bottom:1rem;">L'intervention ARC
                        <?= htmlspecialchars($intervention['numero_arc']) ?> a ├®t├® cl├┤tur├®e.
                    </p>

                    <!-- Toast email (inject├® par JS) -->
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
                        <!-- Bouton T├®l├®charger PDF -->
                        <button type="button" id="btnDownloadPDF" onclick="telechargerPDF()"
                            style="padding:0.7rem 1.5rem; background:var(--primary); color:#000; border:none; border-radius:8px; font-weight:700; cursor:pointer; font-size:0.9rem; display:flex; align-items:center; gap:0.5rem;">
                            <span id="btnDownloadPDFIcon"><img src="/assets/icon_download.svg" style="height: 18px; width: 18px; vertical-align: middle; filter: brightness(0);"></span> 
                            <span id="btnDownloadPDFLabel">T├®l├®charger le PDF</span>
                        </button>
                        <a href="<?= $_SESSION['role'] === 'admin' ? 'admin.php' : 'technicien.php' ?>"
                            style="padding:0.7rem 1.5rem; background:rgba(255,255,255,0.1); color:var(--text); border:1px solid var(--glass-border); border-radius:8px; font-weight:600; text-decoration:none; font-size:0.9rem; display:flex; align-items:center; gap:8px;">
                            <span><img src="/assets/icon_back_green.svg" style="height: 18px; width: 18px; vertical-align: middle;"></span>
                            <span style="color:#27AE60;">Retour au tableau de bord</span>
                        </a>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Donn├®es PHP expos├®es pour le JS -->
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
                            'repere' => $mes['repere'] ?? 'ÔÇö',
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

            <!-- ANALYSE IA G├ëN├ëRALE (Rectangle Orange) -->
            <div id="ai-synthesis-block" class="card" style="background: rgba(255, 179, 0, 0.1); border: 2px solid var(--primary); padding: 1.5rem; margin-bottom: 2rem; border-radius: 12px; position: relative;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem;">
                    <div style="display: flex; align-items: center; gap: 10px;">
                        <img src="/assets/ai_expert.jpg" style="height: 48px; width: 48px; object-fit: cover; border-radius: 8px; border: 2px solid rgba(255,255,255,0.2);">
                        <h2 style="color: var(--primary); margin: 0; font-size: 1.1rem; text-transform: uppercase; letter-spacing: 1px;">Analyse Intelligente Globale</h2>
                    </div>
                    <button type="button" onclick="generateAllIA()" id="btnGenerateAllIa" class="btn btn-primary" style="background: var(--primary); color: #000; font-weight: 700; font-size: 0.8rem; padding: 0.5rem 1rem; border-radius: 8px; display: flex; align-items: center; gap: 8px;">
                        <span>­ƒÜÇ</span> Lancer l'IA sur toutes les fiches
                    </button>
                </div>
                <p style="font-size: 0.85rem; color: var(--text-dim); margin-bottom: 1rem;">
                    Cette fonction analyse automatiquement chaque fiche pour g├®n├®rer les sections "Dysfonctionnements" et "Conclusions" en se basant sur vos relev├®s. 
                    <strong>Les fiches d├®j├á modifi├®es manuellement ne seront pas ├®cras├®es sans confirmation.</strong>
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

            <!-- EN-T├èTE -->
            <div class="rapport-header">
                <img src="/assets/lenoir_logo_trans.svg" alt="LENOIR-MEC" 
                    style="height: 60px; width: auto; object-fit: contain; display: block; margin: 0 auto 1.5rem auto;">
                <h1>Rapport d'expertise sur site</h1>
                <div class="arc-badge">
                    ARC <?= htmlspecialchars($intervention['numero_arc']) ?>
                </div>
            </div>

            <!-- R├ëCAP MACHINES -->
            <div class="section-title">├ëquipements contr├┤l├®s (<?= count($machines) ?>)</div>
            <div class="machines-recap">
                <?php foreach ($machines as $m): 
                    $mStatus = $m['conclusion'] ?? '';
                    $statusColor = '#94a3b8'; // d├®faut
                    if (stripos($mStatus, 'conforme') !== false && stripos($mStatus, 'non') === false) $statusColor = '#10b981';
                    if (stripos($mStatus, 'am├®lioration') !== false) $statusColor = '#f59e0b';
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
                            <small style="opacity:0.6; color:#94a3b8;">ÔÇô <?= htmlspecialchars($mm['repere']) ?></small>
                        <?php endif; ?>
                        <small style="margin-left: 4px; color: <?= $statusColor ?>; font-weight:bold;">(<?= (int)$m['points_count'] ?> pts)</small>
                    </span>
                <?php endforeach; ?>
            </div>

            <!-- INFORMATIONS CLIENT -->
            <div class="section-title">Informations client</div>
            <div class="card glass" style="padding: 1.5rem;">
                <div class="form-group">
                    <label class="label">Soci├®t├® <span style="color:var(--error);">*</span></label>
                    <input type="text" name="nom_societe_display" class="input"
                        value="<?= htmlspecialchars($intervention['nom_societe']) ?>" disabled style="opacity:0.7;">
                </div>

                <div class="field-row">
                    <div class="form-group">
                        <label class="label">Pr├®nom du contact <span style="color:var(--error);">*</span></label>
                        <input type="text" name="contact_prenom" id="contact_prenom" class="input" placeholder="Pr├®nom..."
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
                        <label class="label">Fonction / R├┤le</label>
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
                        <label class="label">T├®l├®phone</label>
                        <input type="tel" name="contact_telephone" class="input" placeholder="06 12 34 56 78"
                            value="<?= htmlspecialchars($intervention['contact_telephone'] ?? $intervention['c_tel'] ?? '') ?>">
                    </div>
                    <div class="form-group">
                        <label class="label">Adresse</label>
                        <input type="text" name="adresse" class="input" placeholder="Rue, num├®ro..."
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
                placeholder="Saisissez vos observations g├®n├®rales sur l'├®tat des ├®quipements, les anomalies relev├®es, les recommandations..."><?= htmlspecialchars($intervention['commentaire_technicien'] ?? '') ?></textarea>

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
                    <span>Une offre de pi├¿ces de rechange</span>
                </label>
                <label class="checkbox-item">
                    <input type="checkbox" name="souhait_pieces_intervention" value="1" class="chk-souhait"
                        <?= ($intervention['souhait_pieces_intervention'] ?? false) ? 'checked' : '' ?>>
                    <span>Pi├¿ces de rechange + intervention mise en place</span>
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
                placeholder="Remarques ou demandes sp├®cifiques du client..."><?= htmlspecialchars($intervention['commentaire_client'] ?? '') ?></textarea>

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
                            placeholder="NOM Pr├®nom du signataire (ex: DUPONT Jean)"
                            value="<?= htmlspecialchars($intervention['nom_signataire_client'] ?? '') ?>" required>
                    </div>
                    <canvas id="canvasClient" width="600" height="200"></canvas>
                    <input type="hidden" name="sigClient" id="sigClientInput">
                </div>
            </div>

            <!-- BOUTON FINAL -->
            <button type="submit" class="btn-final" onclick="return validateAndSubmit()">
                Ô£ô Finaliser le rapport et terminer l'intervention
            </button>

            <a href="intervention_edit.php?id=<?= $id ?>"
                style="display:block; text-align:center; margin-top:1rem; color:var(--text-dim); font-size:0.85rem; text-decoration:none;">
                ÔåÉ Retour aux fiches
            </a>
        </form>
    </div>

    <!-- Libs PDF : jsPDF + html2canvas exposes comme globals -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
    <!-- html2pdf.js pour g├®n├®ration PDF c├┤t├® client -->
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
                pad.clear(); // R├®initialise pour ├®viter les distorsions si on redimensionne
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
                alert('Erreur: les zones de signature ne sont pas pr├¬tes. Veuillez rafra├«chir la page.');
                return false;
            }

            // --- BUG-005, BUG-014, BUG-015: Contr├┤le de qualit├® des textes ---
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
                    if (!confirm("ÔÜá´©Å Le champ '" + f.label + "' contient des donn├®es semblant ├¬tre du test ou non-professionnelles (\"" + val.substring(0, 20) + "...\"). Voulez-vous vraiment continuer ?")) {
                        return false;
                    }
                }
            }

            const nomSignataire = document.querySelector('[name="nom_signataire"]')?.value.trim() || '';
            if (!nomSignataire) {
                alert('Le nom du signataire est obligatoire.');
                return false;
            }

            // --- BUG-008: Contr├┤le de compl├®tude des fiches ---
            const machinesData = window.LM_RAPPORT.machinesData;
            for (let m of machinesData) {
                if (m.points_count === 0) {
                    alert('ÔØî Compl├®tude insuffisante : La fiche machine "' + m.designation + '" est enti├¿rement vide (0 point de contr├┤le rempli). Veuillez la compl├®ter avant de finaliser.');
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
            // --- BUG-018: Exclusivit├® des checkboxes "Le client souhaite" ---
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


        // ÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉ
        // CR├ëATION DU CONTENEUR COMPLET POUR LE PDF (ASYNCHRONE)
        // ÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉ

        // Helper : Attendre que toutes les images soient charg├®es
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

        async function buildFullPdfContainer() {
            const container = document.createElement('div');
            container.id = 'pdf-full-wrapper';
            container.style.width = '210mm';
            container.style.backgroundColor = 'white';
            container.style.color = 'black';

            // --- 0. STYLES SP├ëCIFIQUES ---
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
                .pdf-table, table.controles { 
                    page-break-inside: auto !important; 
                }
                .pdf-table tr, table.controles tr { 
                    page-break-inside: avoid !important; 
                    page-break-after: auto !important;
                }
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

                /* ├ëtat s├®lectionn├® : On retrouve les vraies couleurs du SaaS */
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
                /* La barre verticale courte ├á droite de la zone grise */
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
                    <td style="padding:6px; border-right:1px solid #000; text-align:center;">${m.arc || 'ÔÇö'}</td>
                    <td style="padding:6px; border-right:1px solid #000; text-align:center;">${m.of || 'ÔÇö'}</td>
                    <td style="padding:6px; border-right:1px solid #000;">${m.designation || 'ÔÇö'}</td>
                    <td style="padding:6px; text-align:center;">${m.annee || 'ÔÇö'}</td>
                </tr>
            `).join('');

            // --- 1. PAGE RAPPORT FINAL (COUVERTURE + INFOS) ---
            const rapportCloneWrapper = document.createElement('div');
            rapportCloneWrapper.className = 'pdf-page';
            // Layout exact as requested
            rapportCloneWrapper.innerHTML = `
                <!-- HEADER (Logo, Slogan) -->
                <table style="width:100%; border:none; margin-bottom:15px; border-bottom: 2px solid #1e4e6d;">
                    <tr>
                        <td style="width: 40%; vertical-align: bottom; padding-bottom: 10px;">
                            <img src="/assets/lenoir_logo_doc.png" style="height:60px;">
                        </td>
                        <td style="width: 60%; vertical-align: bottom; text-align: right; padding-bottom: 5px;">
                            <div style="font-size: 11px; font-weight: normal; color: #1e4e6d; font-style: italic;">
                                Le sp├®cialiste des applications magn├®tiques pour la s├®paration et le levage industriel
                            </div>
                        </td>
                    </tr>
                </table>
                <div style="text-align: right; color: #555; font-weight: bold; font-size: 11px; margin-top: 5px; margin-bottom: 15px;">RAPPORT D'EXPERTISE</div>

                <!-- GRAND CADRE BLEU -->
                <div style="border: 3px solid #1e4e6d; padding: 15px; margin-bottom: 30px;">
                    <h1 style="text-align: center; color: #1e4e6d; font-size: 26px; font-weight: bold; margin: 10px 0 20px 0;">RAPPORT DE L'EXPERTISE</h1>
                    <div style="text-align: right; font-weight: bold; font-size: 14px; color: black; margin-bottom: 15px;">N┬░ARC : ${numArc}</div>

                    <!-- TABLEAU CLIENT -->
                    <table style="width:100%; border-collapse:collapse; border: 2px solid #1e4e6d; margin-bottom:20px; font-size:12px; font-family: Arial, sans-serif;">
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
                            <td style="font-weight: bold; padding: 6px; border: 1px solid #000;">Pr├®nom</td>
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
                            <td style="font-weight: bold; padding: 6px; border: 1px solid #000;">T├®l├®phone</td>
                            <td style="padding: 6px; border: 1px solid #000;">${contactTel || '_____'}</td>
                        </tr>
                        <tr>
                            <td style="font-weight: bold; padding: 6px; border: 1px solid #000;">Pays</td>
                            <td style="padding: 6px; border: 1px solid #000;">${pays || 'France'}</td>
                            <td style="font-weight: bold; padding: 6px; border: 1px solid #000;">Courriel</td>
                            <td style="padding: 6px; border: 1px solid #000; color: blue; text-decoration: underline;">${contactEmail || '_____'}</td>
                        </tr>
                    </table>

                    <!-- TABLEAU PARC MACHINE -->
                    <table style="width:100%; border-collapse:collapse; border: 2px solid #1e4e6d; font-size:12px; font-family: Arial, sans-serif;">
                        <tr>
                            <td colspan="5" style="background-color: #5b9bd5; color: white; text-align: center; font-weight: bold; text-transform: uppercase; padding: 6px; border: 1px solid #000;">
                                PARC MACHINE
                            </td>
                        </tr>
                        <tr>
                            <td style="background-color: #f2f2f2; text-align: center; font-weight: bold; padding: 6px; border: 1px solid #000; width: 8%;">Poste</td>
                            <td style="background-color: #f2f2f2; text-align: center; font-weight: bold; padding: 6px; border: 1px solid #000; width: 25%;">N┬░ A.R.C (N┬░ de s├®rie)</td>
                            <td style="background-color: #f2f2f2; text-align: center; font-weight: bold; padding: 6px; border: 1px solid #000; width: 45%;">D├®signation du Produit</td>
                            <td style="background-color: #f2f2f2; text-align: center; font-weight: bold; padding: 6px; border: 1px solid #000; width: 12%;">Rep├¿re</td>
                            <td style="background-color: #f2f2f2; text-align: center; font-weight: bold; padding: 6px; border: 1px solid #000; width: 10%;">Ann├®e</td>
                        </tr>
                        ${window.LM_RAPPORT.machinesData.map((m, idx) => `
                            <tr>
                                <td style="text-align: center; font-weight: bold; padding: 6px; border: 1px solid #000;">${m.poste || (idx + 1)}</td>
                                <td style="text-align: center; padding: 6px; border: 1px solid #000;">${m.arc || 'ÔÇö'} ${m.of ? ' - ' + m.of : ''}</td>
                                <td style="padding: 6px; border: 1px solid #000;">${m.designation || 'ÔÇö'}</td>
                                <td style="text-align: center; padding: 6px; border: 1px solid #000;">${m.repere || 'ÔÇö'}</td>
                                <td style="text-align: center; padding: 6px; border: 1px solid #000;">${m.annee || 'ÔÇö'}</td>
                            </tr>
                        `).join('')}
                    </table>
                </div>

                <!-- SIGNATURES (Hors du cadre orange) -->
                <table style="width:100%; border-collapse:collapse; border: 2px solid #1e4e6d; font-size:13px; font-family: Arial, sans-serif;">
                    <tr>
                        <td style="font-weight: bold; padding: 15px 10px; border: 1px solid #1e4e6d; width: 25%;">Technicien sur Site :</td>
                        <td style="padding: 15px 10px; border: 1px solid #1e4e6d; width: 30%;">${techName}</td>
                        <td rowspan="2" style="padding: 5px; border: 1px solid #1e4e6d; width: 45%; text-align: center; vertical-align: middle;">
                            <img src="${sigTechData}" style="max-height:80px; max-width:100%;">
                        </td>
                    </tr>
                    <tr>
                        <td style="font-weight: bold; padding: 15px 10px; border: 1px solid #1e4e6d;">Date d'expertise :</td>
                        <td style="padding: 15px 10px; border: 1px solid #1e4e6d;">${dateExp}</td>
                    </tr>
                </table>
            `;
            rapportCloneWrapper.appendChild(createPdfFooter());
            container.appendChild(rapportCloneWrapper);

            // --- 1.2 PAGE SYNTH├êSE + PR├ëAMBULE (FUSIONN├ëS POUR ├ëCONOMISER DES PAGES) ---
            const synthPreambulePage = document.createElement('div');
            synthPreambulePage.className = 'pdf-page';
            synthPreambulePage.style.margin = '0';
            synthPreambulePage.style.boxShadow = 'none';
            synthPreambulePage.style.pageBreakBefore = 'always';
            synthPreambulePage.style.paddingTop = '15mm';
            const s = window.LM_RAPPORT.synth;

            // Calcul mois prochain pour le pr├®ambule
            let moisProchainText = "";
            let villePreambule = ville || "[VILLE DU CLIENT]";
            if (dateExp && dateExp.includes('/')) {
                const parts = dateExp.split('/');
                if (parts.length === 3) {
                    const mIndex = parseInt(parts[1], 10) - 1;
                    const y = parseInt(parts[2], 10) + 1;
                    const moisNoms = ['janvier', 'f├®vrier', 'mars', 'avril', 'mai', 'juin', 'juillet', 'ao├╗t', 'septembre', 'octobre', 'novembre', 'd├®cembre'];
                    if (mIndex >= 0 && mIndex < 12) {
                        moisProchainText = moisNoms[mIndex] + ' ' + y;
                    }
                }
            }
            if (!moisProchainText) moisProchainText = "[MOIS PROCHAIN]";

            synthPreambulePage.innerHTML = `
                <div style="padding-top: 10px;">
                    <div style="padding: 20px; color: #000; background: #fff; margin-bottom: 30px; page-break-inside: avoid;">
                        <h2 style="font-weight: bold; font-size: 18px; color: #1e4e6d; margin: 0 0 25px 0; padding-bottom: 5px; border-bottom: 2px solid #1e4e6d; text-transform: uppercase; width: 100%;">SYNTH├êSE DE L'INTERVENTION</h2>
                        
                        <div style="margin-bottom: 15px; font-size: 13px; line-height: 1.6;">
                            <div><strong>Technicien :</strong> ${s.tech}</div>
                            <div><strong>Date :</strong> ${s.date}</div>
                            <div><strong>Dur├®e totale :</strong> ${s.duree}</div>
                            <div><strong>├ëquipements contr├┤l├®s :</strong> ${s.nbMachines}</div>
                        </div>

                        <div style="margin: 20px 0; font-size: 13px;">
                            <div style="display: flex; align-items: center; margin-bottom: 8px;">
                                <span style="display: inline-block; width: 12px; height: 12px; background: #28a745; margin-right: 10px;"></span>
                                <strong>${s.ok} points conformes</strong>
                            </div>
                            <div style="display: flex; align-items: center; margin-bottom: 8px;">
                                <span style="display: inline-block; width: 12px; height: 12px; background: #1e4e6d; margin-right: 10px;"></span>
                                <strong>${s.aa} points ├á am├®liorer</strong>
                            </div>
                            <div style="display: flex; align-items: center; margin-bottom: 8px;">
                                <span style="display: inline-block; width: 12px; height: 12px; background: #dc3545; margin-right: 10px;"></span>
                                <strong>${s.nc} point${s.nc > 1 ? 's' : ''} non conforme${s.nc > 1 ? 's' : ''}</strong>
                            </div>
                            <div style="display: flex; align-items: center; margin-bottom: 8px;">
                                <span style="display: inline-block; width: 12px; height: 12px; background: #8b0000; margin-right: 10px;"></span>
                                <strong>${s.nr} remplacement${s.nr > 1 ? 's' : ''} n├®cessaire${s.nr > 1 ? 's' : ''}</strong>
                            </div>
                        </div>

                        <div style="margin-top: 25px; text-align: center;">
                            <div style="font-weight: bold; font-size: 14px; margin-bottom: 5px; text-transform: uppercase;">SCORE DE CONFORMIT├ë : ${s.score}%</div>
                            ${s.nbMachinesEmpty > 0 ? `<div style="font-size: 11px; color: #dc3545; font-weight: bold; margin-bottom: 8px;">ÔÜá´©Å ${s.nbMachinesEmpty} fiche(s) non remplie(s) ÔÇö score calcul├® sur ${s.nbMachinesFilled}/${s.nbMachinesFilled + s.nbMachinesEmpty} fiches uniquement</div>` : ''}
                            <div style="width: 100%; height: 20px; background: #e2e8f0; border: 1px solid #000; position: relative; overflow: hidden; border-radius: 4px;">
                                <div style="width: ${s.score}%; height: 100%; background: ${s.score < 33 ? '#dc3545' : (s.score < 66 ? '#f59e0b' : '#22c55e')}; transition: width 0.5s;"></div>
                            </div>
                        </div>
                    </div>

                    <div style="font-size: 13px; line-height: 1.5; color: black; font-family: Arial, sans-serif; page-break-inside: avoid;">
                        <h2 style="color: #1e4e6d; font-size: 16px; text-transform: uppercase; margin-bottom: 10px; border-bottom: 2px solid #1e4e6d; padding-bottom: 5px;">PR├ëAMBULE :</h2>
                        
                        <p style="margin-bottom: 12px;">
                            Ce rapport est ├®tabli suite ├á une expertise effectu├®e le ${dateExp} sur votre site de ${villePreambule}.
                        </p>
                        
                        <p style="margin-bottom: 12px;">
                            Nos expertises permettent de vous accompagner dans votre d├®marche ISO 22000 :2005 et HACCP. Notre analyse est suivie de conclusions ou recommandations que nous vous invitons ├á suivre pour la p├®rennit├® et la qualit├® de votre production.
                        </p>
                        
                        <p style="margin-bottom: 12px;">
                            Dans le cadre de notre prestation annuelle, la prochaine expertise aura lieu en ${moisProchainText}. Nous vous contacterons pour ├®tablir une date appropri├®e ├á vos imp├®ratifs de production.
                        </p>
                    </div>
                </div>
            `;
            synthPreambulePage.appendChild(createPdfFooter());
            container.appendChild(synthPreambulePage);

            // --- 2. FETCH & APPEND MACHINES ---
            if (window.LM_RAPPORT && window.LM_RAPPORT.machinesIds) {
                let reportMachineIds = [...window.LM_RAPPORT.machinesIds];
                const emptyOption = 'include';
                const emptyIds = window.LM_RAPPORT.emptyMachinesIds || [];

                // Si option = 'exclude', on retire carr├®ment les machines vides de la boucle !
                if (emptyOption === 'exclude' && emptyIds.length > 0) {
                    reportMachineIds = reportMachineIds.filter(id => !emptyIds.includes(parseInt(id, 10)) && !emptyIds.includes(String(id)));
                }

                const totalMachines = reportMachineIds.length;
                for (let mIdx = 0; mIdx < totalMachines; mIdx++) {
                    const mId = reportMachineIds[mIdx];

                    // Si on a gard├® la machine mais qu'elle est vide et qu'on voulait 'condensed'
                    if (emptyOption === 'condensed' && (emptyIds.includes(parseInt(mId, 10)) || emptyIds.includes(String(mId)))) {
                        const mData = window.LM_RAPPORT.machinesData.find(m => parseInt(m.id, 10) === parseInt(mId, 10)) || {};
                        const mDesignation = mData.designation || '├ëquipement';
                        const mArc = mData.arc || numArc;

                        const p = document.createElement('div');
                        p.className = 'pdf-page';
                        p.innerHTML = `
                            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; border-bottom: 3px solid #5B9BD5; padding-bottom: 5px;">
                                <div style="font-size: 14px; font-weight: bold; color: #1B4F72;">FICHE ${mIdx + 1} / ${totalMachines}</div>
                                <img src="/assets/lenoir_logo_doc.png" style="height: 45px;">
                            </div>
                            
                            <table style="width:100%; border-collapse:collapse; border:1px solid #000; margin-bottom:20px; font-size:13px; color:#000;">
                                <tr>
                                    <td style="width:15%; font-weight:bold; border:1px solid #000; padding:6px; background:#d9d9d9;">N┬░ A.R.C.</td>
                                    <td style="width:35%; border:1px solid #000; padding:6px; font-weight:bold;">${mArc}</td>
                                    <td style="width:15%; font-weight:bold; border:1px solid #000; padding:6px; background:#d9d9d9;">D├®signation</td>
                                    <td style="width:35%; border:1px solid #000; padding:6px;"><b>${mDesignation}</b></td>
                                </tr>
                            </table>

                            <div style="margin-top: 150px; text-align: center;">
                                <div style="font-size: 24px; font-weight: bold; color: #dc3545; text-transform: uppercase; border: 4px solid #dc3545; display: inline-block; padding: 20px 40px; transform: rotate(-5deg);">
                                    ├ëQUIPEMENT NON CONTR├öL├ë
                                </div>
                                <div style="margin-top: 30px; color: #555; font-size: 14px;">
                                    Aucune donn├®e n'a ├®t├® saisie pour ce mat├®riel lors de l'intervention.
                                </div>
                            </div>
                        `;

                        p.style.pageBreakBefore = 'always';
                        container.appendChild(p);

                        continue; // Passe directement ├á la machine suivante sans fetch html !
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

                            // --- NETTOYAGE RADICAL DES ├ëL├ëMENTS D'INTERFACE ---
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
                                p.style.pageBreakBefore = 'always';
                                p.style.marginTop = '0';
                                p.style.paddingTop = '10mm'; 
                                
                                // On ajoute discretement le num├®ro de fiche AU DESSUS du header officiel LENOIR
                                const hDiv = document.createElement('div');
                                hDiv.style.textAlign = 'right';
                                hDiv.style.fontSize = '12px';
                                hDiv.style.fontWeight = 'bold';
                                hDiv.style.color = '#1B4F72';
                                hDiv.style.marginBottom = '5px';
                                hDiv.innerHTML = `FICHE ${mIdx + 1} / ${totalMachines}`;
                                p.insertBefore(hDiv, p.firstChild);
                            }

                            p.querySelectorAll('input[type="radio"]:checked').forEach(r => {
                                const lbl = r.closest('label');
                                if (lbl) lbl.classList.add('selected');
                            });

                            p.querySelectorAll('input:not([type="radio"]):not([type="checkbox"]):not([type="hidden"]):not([type="file"])').forEach(inp => {
                                let val = (inp.value || '').trim();
                                if (inp.name === 'mesures[poste]') {
                                    val = val ? val : (mIdx + 1);
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
                                    if (!val.trim()) val = "Non r├®alis├®";
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

                            // BUGFIX PDF: S├®parateur de table (tbody)
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

                                    currentTbody.appendChild(row); // D├®place le tr dans le nouveau tbody

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
                            p.style.minHeight = 'auto'; // Help chaining
                            p.appendChild(createPdfFooter());
                            container.appendChild(p);
                        });
                    } catch (err) {
                        console.error('Erreur fetch machine ' + mId, err);
                    }
                }
            }

            // Page de fin : On ne force plus syst├®matiquement le saut de page
            // si le contenu pr├®c├®dent est court. On laisse html2pdf g├®rer ou on met un petit espacement.
            const pbFin = document.createElement('div');
            pbFin.style.height = '20px';
            container.appendChild(pbFin);

            // --- 4. PAGE DE FIN (STRUCTURE LENOIR-MEC + SIGNATURES + OBSERVATIONS) ---

            const endPage = document.createElement('div');
            endPage.className = 'pdf-page';
            endPage.style.padding = '0 15mm';
            endPage.style.position = 'relative';
            endPage.style.pageBreakBefore = 'always';

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
                    
                    <!-- OBSERVATIONS G├ëN├ëRALES -->
                    <div style="font-weight: bold; font-size: 14px; color: #1e4e6d; margin-bottom: 10px; border-bottom: 2px solid #1e4e6d; padding-bottom: 5px; text-transform: uppercase;">OBSERVATIONS DU TECHNICIEN</div>
                    <div style="padding: 8px 0; min-height: 40px; font-size: 12px; white-space: pre-wrap; margin-bottom: 20px;">${commentaryTech}</div>

                    <div style="font-weight: bold; font-size: 14px; color: #1e4e6d; margin-bottom: 10px; border-bottom: 2px solid #1e4e6d; padding-bottom: 5px; text-transform: uppercase;">COMMENTAIRE DU CLIENT</div>
                    <div style="padding: 8px 0; font-size: 12px; white-space: pre-wrap; margin-bottom: 20px;">${commentaryClient}</div>

                    <!-- LE CLIENT SOUHAITE -->
                    <div style="font-weight: bold; font-size: 14px; color: #1e4e6d; margin-bottom: 10px; border-bottom: 2px solid #1e4e6d; padding-bottom: 5px; text-transform: uppercase;">LE CLIENT SOUHAITE</div>
                    <div style="padding: 8px 0; margin-bottom: 20px;">
                        <div style="margin-bottom: 3px; font-size: 11px;">${souhaitRapport ? 'Ôÿæ' : 'ÔÿÉ'} Ce Rapport d\'expertise uniquement</div>
                        <div style="margin-bottom: 3px; font-size: 11px;">${souhaitPieces ? 'Ôÿæ' : 'ÔÿÉ'} Une offre de Pi├¿ces de Rechange</div>
                        <div style="margin-bottom: 3px; font-size: 11px;">${souhaitIntervention ? 'Ôÿæ' : 'ÔÿÉ'} Une offre de PR + intervention mise en place</div>
                        <div style="font-size: 11px;">${souhaitAucune ? 'Ôÿæ' : 'ÔÿÉ'} Aucune offre</div>
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
                                <div style="font-weight: bold; text-decoration: underline; margin-bottom: 5px;">Contr├┤leur (NOM Pr├®nom) :</div>
                                <div style="margin-bottom: 10px;"><strong>${techNameLabel}</strong></div>
                                <div style="text-align: center;">
                                    <img src="${sigTechImg}" style="max-height: 80px; max-width: 90%; object-fit: contain; background: white;">
                                </div>
                            </td>
                            <td style="border: 1px solid #000; padding: 8px; vertical-align: top; width: 50%;">
                                <div style="font-weight: bold; text-decoration: underline; margin-bottom: 5px;">Client (NOM Pr├®nom) :</div>
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
                            <div style="font-size: 12px;">Ô×ñ <strong>Soufyane SALAH</strong> &nbsp;&nbsp;&nbsp; <span style="font-style: italic;">Charg├® d'Affaires</span></div>
                        </div>
                        
                        <div style="background-color: #E67E22; color: white; padding: 4px 15px; font-weight: bold; border-bottom: 2px solid #000; font-size: 10px;">POUR LA PLANIFICATION D'UNE V├ëRIFICATION P├ëRIODIQUE</div>
                        <div style="background-color: #fff; padding: 6px;">
                            <div style="font-size: 12px;">Ô×ñ <strong>Sophie NIAY</strong> &nbsp;&nbsp;&nbsp; <span style="font-style: italic;">Responsable Service Clients</span></div>
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

            await waitForImages(container);
            return container;
        }

        // ÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉ
        // G├ëN├ëRATION PDF (html2pdf.js)
        // ÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉ
        async function genererPDFBase64() {
            if (!window.html2pdf) throw new Error('html2pdf.js non disponible');

            const container = await buildFullPdfContainer();
            await ensureImagesBase64(container);

            const opt = {
                margin: [10, 0, 15, 0], // Top, Left, Bottom, Right
                filename: window.LM_RAPPORT ? window.LM_RAPPORT.pdfFilename : 'rapport.pdf',
                image: { type: 'jpeg', quality: 0.98 },
                html2canvas: { scale: 1.5, useCORS: true, logging: false },
                jsPDF: { unit: 'mm', format: 'a4', orientation: 'portrait' },
                pagebreak: { mode: ['css', 'legacy'], avoid: ['tbody', 'img', '.photo-annexe-item', '.pdf-section', '.sig-zone', '.levage-diagram-container'] }
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

            const pdfBlob = await worker.outputPdf('blob');

            return new Promise((resolve, reject) => {
                const reader = new FileReader();
                reader.onload = () => resolve(reader.result.split(',')[1]);
                reader.onerror = reject;
                reader.readAsDataURL(pdfBlob);
            });
        }

        // ÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉ
        // T├ëL├ëCHARGEMENT PDF BOUTON DIRECT
        // ÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉ
        async function telechargerPDF() {
            const btn = document.getElementById('btnDownloadPDF');
            const icon = document.getElementById('btnDownloadPDFIcon');
            const label = document.getElementById('btnDownloadPDFLabel');

            if (btn) { 
                btn.disabled = true; 
                if (label) label.textContent = 'G├®n├®ration...';
                if (icon) icon.textContent = 'ÔÅ│';
            }
            try {
                const container = await buildFullPdfContainer();
                await ensureImagesBase64(container);

                const opt = {
                    margin: [10, 0, 15, 0], // Top, Left, Bottom, Right
                    filename: window.LM_RAPPORT ? window.LM_RAPPORT.pdfFilename : 'rapport.pdf',
                    image: { type: 'jpeg', quality: 0.98 },
                    html2canvas: { scale: 1.5, useCORS: true, logging: false },
                    jsPDF: { unit: 'mm', format: 'a4', orientation: 'portrait' },
                    pagebreak: { mode: ['css', 'legacy'], avoid: ['tbody', 'img', '.photo-annexe-item', '.pdf-section', '.sig-zone', '.levage-diagram-container'] }
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

                await worker.save();
            } catch (e) {
                alert('Erreur g├®n├®ration PDF : ' + e.message);
            } finally {
                if (btn) { 
                    btn.disabled = false; 
                    if (label) label.textContent = 'T├®l├®charger le PDF';
                    if (icon) icon.innerHTML = '<img src="/assets/icon_download.svg" style="height: 18px; width: 18px; vertical-align: middle; filter: brightness(0);">';
                }
            }
        }

        // ÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉ
        // TOAST UI
        // ÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉ
        function afficherToast(message, type = 'success') {
            const toast = document.getElementById('emailToast');
            if (!toast) return;
            toast.textContent = message;
            if (type === 'success') {
                toast.style.background = 'rgba(16,185,129,0.2)';
                toast.style.border = '1px solid rgba(16,185,129,0.5)';
                toast.style.color = '#10b981';
            } else if (type === 'warning') {
                toast.style.background = 'rgba(245,158,11,0.2)';
                toast.style.border = '1px solid rgba(245,158,11,0.5)';
                toast.style.color = '#f59e0b';
            } else {
                toast.style.background = 'rgba(244,63,94,0.2)';
                toast.style.border = '1px solid rgba(244,63,94,0.5)';
                toast.style.color = '#f43f5e';
            }
            toast.style.display = 'block';
            // Scroll vers le toast
            toast.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }

        // ÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉ
        // FILE D'ATTENTE HORS-LIGNE (IndexedDB)
        // ÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉ
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
                            console.log('[LM] Email rejou├® avec succ├¿s :', item.client_email);
                        }
                    } catch (e) {
                        console.warn('[LM] Rejouer ├®chou├® :', e);
                    }
                }
            };
        }

        // ├ëcouter la reconnexion r├®seau
        window.addEventListener('online', () => {
            console.log('[LM] Connexion r├®tablie ÔÇô rejouer la file d\'attente email');
            rejouerFileDAttente();
        });

        // ÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉ
        // APPEL API ENVOI EMAIL
        // ÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉ
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

        // ÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉ
        // FONCTION PRINCIPALE : LANCER L'ENVOI EMAIL
        // ÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉ
        async function lancerEnvoiEmail(auto = false) {
            if (!window.LM_RAPPORT) return;

            const { interventionId, clientEmail, csrfToken, nomSociete } = window.LM_RAPPORT;

            if (!clientEmail) {
                afficherToast('ÔÜá´©Å Aucun email client renseign├®. Veuillez reprendre le formulaire.', 'error');
                return;
            }

            const btn = document.getElementById('btnSendEmail');
            const icon = document.getElementById('btnSendEmailIcon');
            const label = document.getElementById('btnSendEmailLabel');

            if (btn) btn.disabled = true;
            if (icon) icon.textContent = 'ÔÅ│';
            if (label) label.textContent = 'G├®n├®ration du PDFÔÇª';

            let pdfBase64;
            try {
                pdfBase64 = await genererPDFBase64();
            } catch (e) {
                if (btn) btn.disabled = false;
                if (icon) icon.textContent = '­ƒôº';
                if (label) label.textContent = 'Envoyer PDF par email';
                afficherToast('ÔØî Erreur g├®n├®ration PDF : ' + e.message, 'error');
                return;
            }

            if (icon) icon.textContent = '­ƒôñ';
            if (label) label.textContent = 'Envoi en coursÔÇª';

            // Hors-ligne : mettre en file d'attente
            if (!navigator.onLine) {
                try {
                    await sauvegarderEnFile({
                        intervention_id: interventionId,
                        pdf_data: pdfBase64,
                        client_email: clientEmail,
                        csrf_token: csrfToken,
                    });
                    afficherToast('­ƒô Hors-ligne ÔÇô email mis en file d\'attente. Il sera envoy├® automatiquement ├á la reconnexion.', 'warning');
                } catch (e) {
                    afficherToast('ÔØî Impossible de mettre l\'email en file d\'attente.', 'error');
                }
                if (btn) btn.disabled = false;
                if (icon) icon.textContent = '­ƒôº';
                if (label) label.textContent = 'Envoyer PDF par email';
                return;
            }

            // En ligne : envoi direct
            try {
                const result = await envoyerParAPI(interventionId, pdfBase64, clientEmail, csrfToken);
                if (result.success) {
                    afficherToast('Ô£à Rapport envoy├® avec succ├¿s ├á ' + result.email, 'success');
                    if (btn) btn.style.background = 'linear-gradient(135deg,#10b981,#059669)';
                    if (icon) icon.textContent = 'Ô£à';
                    if (label) label.textContent = 'Email envoy├® !';
                    btn.disabled = true; // Ne pas renvoyer
                } else {
                    afficherToast('ÔØî ' + (result.message || 'Erreur envoi email'), 'error');
                    if (btn) btn.disabled = false;
                    if (icon) icon.textContent = '­ƒöä';
                    if (label) label.textContent = 'R├®essayer l\'envoi';
                }
            } catch (e) {
                // R├®seau coup├® pendant l'envoi
                try {
                    await sauvegarderEnFile({
                        intervention_id: interventionId,
                        pdf_data: pdfBase64,
                        client_email: clientEmail,
                        csrf_token: csrfToken,
                    });
                    afficherToast('­ƒô Connexion perdue ÔÇô email mis en file d\'attente. Il sera envoy├® ├á la reconnexion.', 'warning');
                } catch (qe) {
                    afficherToast('ÔØî Erreur r├®seau et impossible de mettre en file : ' + e.message, 'error');
                }
                if (btn) btn.disabled = false;
                if (icon) icon.textContent = '­ƒöä';
                if (label) label.textContent = 'R├®essayer l\'envoi';
            }
        }

        // ÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉ
        // G├ëN├ëRATION MASSIVE IA (Toutes les fiches)
        // ÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉ
        async function generateAllIA() {
            const btn = document.getElementById('btnGenerateAllIa');
            const progressZone = document.getElementById('ai-global-progress');
            const progressBar = document.getElementById('ai-progress-bar');
            const progressText = document.getElementById('ai-progress-text');
            const progressPercent = document.getElementById('ai-progress-percent');
            
            if (!containerSuccessBanner()) {
                if (!confirm("Cette op├®ration va analyser toutes les machines de l'intervention. Les fiches d├®j├á modifi├®es manuellement seront ignor├®es. Continuer ?")) return;
            }

            btn.disabled = true;
            btn.innerHTML = '<span>ÔÜÖ´©Å</span> Analyse en cours...';
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
                    // On consid├¿re qu'une fiche est d├®j├á remplie si Dysfonctionnements OU Conclusion
                    // contiennent du texte autre que les valeurs par d├®faut.
                    const dys = (m.dysfonctionnements || "").trim();
                    const conc = (m.conclusion || "").trim();
                    const isManual = (dys.length > 5 && !dys.includes("Aucun dysfonctionnement majeur")) 
                                  || (conc.length > 5 && !conc.includes("conforme ├á nos standards"));

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
            progressText.textContent = 'Analyse termin├®e !';
            
            setTimeout(() => {
                alert(`Analyse termin├®e !\n- ${successCount} fiches g├®n├®r├®es\n- ${skipCount} fiches ignor├®es (d├®j├á remplies)\n\nLe rapport va ├¬tre actualis├®.`);
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
