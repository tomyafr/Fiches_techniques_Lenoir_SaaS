<?php
require_once __DIR__ . '/../includes/config.php';
requireAuth(['technicien', 'admin']);

$db = getDB();
$userId = $_SESSION['user_id'];
$id = $_GET['id'] ?? null;

// Self-healing DB migration to add signature_base64
try {
    $db->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS signature_base64 TEXT");
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

$machines = array_filter($allMachines, function($m) {
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
            $contactNom = trim($_POST['contact_nom'] ?? '');
            $contactFonction = trim($_POST['contact_fonction'] ?? '');
            $contactEmail = trim($_POST['contact_email'] ?? '');
            $contactTel = trim($_POST['contact_telephone'] ?? '');
            $nomSignataire = trim($_POST['nom_signataire'] ?? '');

            if (empty($contactNom)) { $error = "Le nom du contact est obligatoire."; }
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
                contact_nom = ?, commentaire_technicien = ?, commentaire_client = ?,
                souhait_rapport_unique = ?, souhait_offre_pieces = ?,
                souhait_pieces_intervention = ?, souhait_aucune_offre = ?,
                signature_client = ?, signature_technicien = ?,
                nom_signataire_client = ?, date_signature = NOW(),
                statut = ? WHERE id = ?')
                ->execute([
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
        $totalMinutes += (int)$mt[1] * 60 + (int)($mt[2] ?: 0);
    } elseif (is_numeric($t)) {
        $totalMinutes += (int)($t * 60);
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
        if (strpos($k, '_radio') !== false || strpos($k, '_stat') !== false || 
            preg_match('/^(aprf|edx|ov|levage|pap)_(?!.*comment).*/', $k)) {
            
            if (!empty($v) && $v !== 'pc') {
                $pointsCount++;
                if ($v === 'c' || $v === 'bon' || $v === 'OK') $totalOk++;
                elseif ($v === 'aa' || $v === 'r' || $v === 'A améliorer') $totalAmeliorer++;
                elseif ($v === 'nc' || $v === 'hs' || $v === 'Non conforme') $totalNonConforme++;
                elseif ($v === 'nr' || $v === 'A remplacer') $totalRemplacer++;
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
            padding: 1.5rem;
            border-bottom: 3px solid var(--primary);
        }

        .rapport-header h1 {
            font-size: 1.2rem;
            color: var(--primary);
            text-transform: uppercase;
            letter-spacing: 1px;
            margin: 0 0 0.5rem 0;
        }

        .rapport-header .arc-badge {
            display: inline-block;
            background: rgba(255, 179, 0, 0.15);
            color: var(--primary);
            padding: 0.4rem 1.2rem;
            border-radius: 20px;
            font-weight: 700;
            font-family: monospace;
            font-size: 1rem;
        }

        .section-title {
            font-size: 0.75rem;
            text-transform: uppercase;
            color: var(--text-dim);
            letter-spacing: 1px;
            margin: 2rem 0 1rem 0;
            padding-bottom: 0.5rem;
            border-bottom: 1px solid var(--glass-border);
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
            box-shadow: inset 0 2px 4px rgba(0,0,0,0.1);
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
            transition: all 0.3s;
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

        @media print {

            .mobile-header,
            .btn-final,
            .sig-clear {
                display: none !important;
            }

            .rapport-logo {
                filter: none !important;
                /* Keep original color in PDF/Print */
            }
        }

        @media screen {
            .rapport-logo {
                /* Keep original colors even on screen */
                filter: none;
                opacity: 1;
            }
        }
    </style>
</head>

<body>
    <header class="mobile-header">
        <a href="intervention_edit.php?id=<?= $id ?>" class="btn btn-ghost"
            style="padding: 0.5rem; color: var(--accent-cyan); text-decoration: none;">
            ← Retour
        </a>
        <span class="mobile-header-title">Rapport Final</span>
        <span class="mobile-header-user"></span>
    </header>

    <div class="rapport-page">
        <form method="POST" id="rapportForm">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="save_rapport">

            <?php if (isset($_GET['msg']) && $_GET['msg'] === 'ok'): ?>
                <div id="successBanner"
                    style="background: rgba(16,185,129,0.15); border:1px solid rgba(16,185,129,0.4); color:#10b981; padding:1.5rem; border-radius:12px; margin-bottom:1.5rem; text-align:center;">
                    <div style="font-size:2.5rem; margin-bottom:0.5rem;">✅</div>
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
                            <span id="btnSendEmailIcon">📧</span>
                            <span id="btnSendEmailLabel">Envoyer PDF par email</span>
                        </button>
                        <!-- Bouton Télécharger PDF -->
                        <button type="button" id="btnDownloadPDF" onclick="telechargerPDF()"
                            style="padding:0.7rem 1.5rem; background:var(--primary); color:#000; border:none; border-radius:8px; font-weight:700; cursor:pointer; font-size:0.9rem;">
                            ⬇️ Télécharger le PDF
                        </button>
                        <a href="<?= $_SESSION['role'] === 'admin' ? 'admin.php' : 'technicien.php' ?>"
                            style="padding:0.7rem 1.5rem; background:rgba(255,255,255,0.1); color:var(--text); border:1px solid var(--glass-border); border-radius:8px; font-weight:600; text-decoration:none; font-size:0.9rem;">
                            ← Retour au tableau de bord
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
                        pdfFilename: <?= json_encode('Rapport_Lenoir_Mec_' . preg_replace('/[^A-Za-z0-9_\-]/', '_', $intervention['numero_arc'] ?? 'rapport') . '_' . date('d-m-Y') . '.pdf') ?>, 
                        emptyFichesOption: 'include',
                        emptyMachinesIds: <?= json_encode($emptyMachinesIds) ?>,
                        machinesIds: [<?= implode(',', array_column($machines, 'id')) ?>],
                        machinesData: <?= json_encode(array_values(array_map(function($m) use ($intervention) {
                            return [
                                'id' => $m['id'],
                                'arc' => $intervention['numero_arc'],
                                'of' => $m['numero_of'] ?? '',
                                'designation' => $m['designation'] ?? '',
                                'annee' => $m['annee_fabrication'] ?? '',
                                'points_count' => $m['points_count'] ?? 0
                            ];
                        }, $machines))) ?>
                    };
                </script>

            <?php if (!empty($error)): ?>
                <div
                    style="background: rgba(244,63,94,0.15); border:1px solid rgba(244,63,94,0.4); color:#f43f5e; padding:1rem; border-radius:8px; margin-bottom:1.5rem; font-size:0.85rem;">
                    ⚠️ <?= htmlspecialchars($error) ?>
                </div>
            <?php endif; ?>

            <!-- EN-TÊTE -->
            <div class="rapport-header card glass">
                <img src="/assets/logo_transparent.png" alt="LENOIR-MEC" class="rapport-logo"
                    style="height: 60px; width: auto; object-fit: contain; margin: 0 auto 1rem auto; display: block; max-width: 100%;">
                <h1>Rapport d'expertise sur site</h1>
                <div class="arc-badge">ARC
                    <?= htmlspecialchars($intervention['numero_arc']) ?>
                </div>
            </div>

            <!-- RÉCAP MACHINES -->
            <div class="section-title">Équipements contrôlés (
                <?= count($machines) ?>)
            </div>
            <div class="machines-recap">
                <?php foreach ($machines as $m): ?>
                    <?php 
                        $pct = ($m['points_count'] > 0) ? 'filled' : 'empty';
                        $statusColor = ($m['points_count'] > 5) ? 'var(--accent-cyan)' : 'var(--error)';
                    ?>
                    <span class="machine-tag" style="border-left: 4px solid <?= $statusColor ?>;">
                        ⚙️
                        <?= htmlspecialchars($m['designation']) ?>
                        <?php $mm = json_decode($m['mesures'] ?? '{}', true); ?>
                        <?php if (!empty($mm['repere'])): ?>
                            <small style="opacity:0.7">– <?= htmlspecialchars($mm['repere']) ?></small>
                        <?php endif; ?>
                        <small style="margin-left: 8px; color: <?= $statusColor ?>;">(<?= $m['points_count'] ?> pts)</small>
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
                        <label class="label">Nom et Prénom du contact <span style="color:var(--error);">*</span></label>
                        <input type="text" name="contact_nom" id="contact_nom" class="input" placeholder="Nom et prénom..."
                            value="<?= htmlspecialchars($intervention['contact_nom'] ?? '') ?>" required maxlength="50">
                        <small id="contact_nom_warning" style="color: var(--error); display: none; font-size: 0.75rem; margin-top: 0.25rem;">
                            ⚠️ Attention: Caractères répétés détectés.
                        </small>
                    </div>
                    <div class="form-group">
                        <label class="label">Fonction / Rôle</label>
                        <input type="text" name="contact_fonction" class="input" placeholder="Resp. maintenance..."
                            value="<?= htmlspecialchars($intervention['contact_fonction'] ?? $intervention['c_fonction'] ?? '') ?>">
                    </div>
                </div>

                <div class="field-row">
                    <div class="form-group">
                        <label class="label">Email <span style="color:var(--error);">*</span></label>
                        <input type="email" name="contact_email" class="input" placeholder="client@societe.com"
                            value="<?= htmlspecialchars($intervention['contact_email'] ?? $intervention['c_email'] ?? '') ?>"
                            required>
                    </div>
                    <div class="form-group">
                        <label class="label">Téléphone</label>
                        <input type="tel" name="contact_telephone" class="input" placeholder="06 12 34 56 78"
                            value="<?= htmlspecialchars($intervention['contact_telephone'] ?? $intervention['c_tel'] ?? '') ?>">
                    </div>
                </div>

                <div class="form-group">
                    <label class="label">Adresse</label>
                    <input type="text" name="adresse" class="input" placeholder="Rue, numéro..."
                        value="<?= htmlspecialchars($intervention['adresse'] ?? '') ?>">
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
                    chk.addEventListener('change', function() {
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
                📅
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
                            value="<?= htmlspecialchars($intervention['nom_signataire_client'] ?: ($intervention['contact_nom'] ?? '')) ?>" required>
                    </div>
                    <canvas id="canvasClient" width="600" height="200"></canvas>
                    <input type="hidden" name="sigClient" id="sigClientInput">
                </div>
            </div>

            <!-- BLOC IA GLOBAL (BATCH) -->
            <div class="card glass" style="margin-top:2.5rem; padding:1.5rem; border:1px solid #d35400; background:rgba(211, 84, 0, 0.05);">
                <div style="display:flex; align-items:center; gap:12px; margin-bottom:12px;">
                    <div style="line-height:0;"><img src="/assets/ai_expert.jpg" style="height:3rem; width:3rem; border-radius:50%; object-fit:cover; border: 2px solid #d35400;"></div>
                    <div>
                        <h4 style="margin:0; color:#d35400; font-size:1.1rem;">Génération automatique intelligente</h4>
                        <p style="margin:4px 0 0 0; font-size:0.8rem; color:var(--text-dim);">L'Expert IA va parcourir toutes vos machines pour rédiger les conclusions et dysfonctionnements (Section E & F) en fonction de vos contrôles.</p>
                    </div>
                </div>
                
                <div id="iaBatchProgress" style="display:none; margin-bottom:15px; background:rgba(0,0,0,0.2); border-radius:10px; height:12px; overflow:hidden; border:1px solid var(--glass-border);">
                    <div id="iaBatchProgressBar" style="width:0%; height:100%; background:linear-gradient(90deg, #e67e22, #d35400); transition:width 0.3s;"></div>
                </div>
                <div id="iaBatchStatus" style="font-size:0.85rem; color:var(--text); text-align:center; margin-bottom:15px; font-weight:600; min-height:1.2em;"></div>

                <button type="button" id="btnGenerateAllAI" onclick="generateAllIA()" class="btn-final" style="margin-top:0; background:linear-gradient(135deg, #e67e22, #d35400); box-shadow:0 4px 15px rgba(230, 126, 34, 0.3);">
                    <img src="/assets/ai_expert.jpg" style="height:20px; width:20px; border-radius:50%; vertical-align:middle; margin-right:8px;"> Analyser toutes les machines par l'Expert IA
                </button>
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

    <!-- html2pdf.js pour génération PDF côté client -->
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
            if (window.LM_RAPPORT && window.LM_RAPPORT.sigTech) {
                try {
                    padTech.fromDataURL(window.LM_RAPPORT.sigTech, { ratio: dpr, width: canvasT.width / dpr, height: canvasT.height / dpr });
                } catch(e) { console.error("Error loading tech sig:", e); }
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
                } catch(e) { console.error("Error loading client sig:", e); }
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

            // --- BUG-008: Contrôle de complétude des fiches (Désactivé suite à la demande du client) ---
            // Les machines vides ("Non contrôlées") sont désormais autorisées lors de la finalisation.

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

        document.addEventListener('DOMContentLoaded', function() {
            // --- BUG-018: Exclusivité des checkboxes "Le client souhaite" ---
            const chkUnique = document.querySelector('[name="souhait_rapport_unique"]');
            const otherChks = document.querySelectorAll('[name="souhait_offre_pieces"], [name="souhait_pieces_intervention"], [name="souhait_aucune_offre"]');
            
            if (chkUnique) {
                chkUnique.addEventListener('change', function() {
                    if (this.checked) {
                        otherChks.forEach(c => c.checked = false);
                    }
                });
                otherChks.forEach(c => {
                    c.addEventListener('change', function() {
                        if (this.checked) {
                            chkUnique.checked = false;
                        }
                    });
                });
            }

            const contactNomInput = document.getElementById('contact_nom');
            const warningEl = document.getElementById('contact_nom_warning');
            
            if (contactNomInput) {
                contactNomInput.addEventListener('input', function() {
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

        // Footer is handled natively by jsPDF pdf.text() — see genererPDFBase64 and telechargerPDF.

        async function buildFullPdfContainer() {
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
                    padding: 0 15mm; /* Margins are now handled by html2pdf natively! */
                    box-sizing: border-box;
                    margin: 0;
                    font-family: Arial, sans-serif;
                    font-size: 13px;
                    position: relative; 
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
                .pdf-table th { background-color: #f0f0f0; text-align: left; text-transform: uppercase; }
                
                .pdf-table .col-comment, .pdf-table td:last-child {
                    max-width: 65mm;
                    overflow: hidden;
                    text-overflow: ellipsis;
                }
                
                .pastille-group { display: flex; gap: 6px; align-items: center; justify-content: center; width: 100%; }
                .pastille-group label {
                    display: flex; align-items: center; justify-content: center;
                    width: 18px; height: 18px; border-radius: 50%;
                    border: 1px solid #ccc !important; background: transparent !important; font-size: 0; position: relative;
                }
                /* Only the selected label gets a background color */
                .pastille-group label.selected.p-na { background: #bbb !important; border-color: #999 !important; opacity: 1; }
                .pastille-group label.selected.p-ok { background: #28a745 !important; border-color: #1e7e34 !important; opacity: 1; }
                .pastille-group label.selected.p-aa { background: #e67e22 !important; border-color: #d35400 !important; opacity: 1; }
                .pastille-group label.selected.p-nc { background: #dc3545 !important; border-color: #bd2130 !important; opacity: 1; }
                .pastille-group label.selected.p-nr { background: #8b0000 !important; border-color: #5a0000 !important; opacity: 1; }
                /* Non selected labels are subtle empty circles */
                .pastille-group label:not(.selected) { opacity: 0.8; border: 1px solid #777 !important; background: transparent !important;}

                .pastille-group label.selected::after {
                    content: '';
                    display: block;
                    width: 8px;
                    height: 8px;
                    background: #000;
                    border-radius: 50%;
                    position: absolute;
                    top: 50%;
                    left: 50%;
                    transform: translate(-50%, -50%);
                }

                /* Styling raw radio buttons for html2canvas compatibility (Frequency table) */
                input[type="radio"] {
                    -webkit-appearance: none;
                    appearance: none;
                    width: 16px;
                    height: 16px;
                    border: 2px solid #999;
                    border-radius: 50%;
                    background: #eee;
                    position: relative;
                    display: inline-block;
                    vertical-align: middle;
                }
                input[type="radio"]:checked {
                    border-color: #444;
                    background: #fff;
                }
                input[type="radio"]:checked::after {
                    content: '';
                    display: block;
                    width: 8px;
                    height: 8px;
                    background: #000;
                    border-radius: 50%;
                    position: absolute;
                    top: 50%;
                    left: 50%;
                    transform: translate(-50%, -50%);
                }
                
                .pdf-input { border: none; border-bottom: 1px dashed #000; background: transparent; font-size: 13px; font-family: Arial; padding: 2px; width: 100%; color: black; outline:none; }
                .pdf-textarea-rendered { 
                    width: 100%; font-family: Arial; font-size: 9pt; color: black; white-space: pre-wrap; word-wrap: break-word; padding:4px; box-sizing: border-box;
                }
                .no-print-pdf { display: none !important; }
                
                .photo-annexe-item { text-align: center; max-width: 200px; margin-bottom: 10px; }
                .photo-annexe-item img { width: 180px; height: 135px; object-fit: cover; border: 1px solid #000; }
                .photo-annexe-item p { font-size: 8pt; margin: 3px 0 0 0; color: #000; line-height: 1.2; }

                img { max-width: 100%; }
            `;
            container.appendChild(styleNode);

            // Fetch data from form for Page 1
            const numArc = window.LM_RAPPORT.arc;
            const nomSociete = document.querySelector('[name="nom_societe_display"]')?.value || window.LM_RAPPORT.nomSociete;
            const adresse = document.querySelector('[name="adresse"]')?.value || '';
            const cp = document.querySelector('[name="code_postal"]')?.value || '';
            const ville = document.querySelector('[name="ville"]')?.value || '';
            const pays = document.querySelector('[name="pays"]')?.value || '';
            
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
            
            const stampHTML = '<div style="color: #2b4c80; font-family: Arial, sans-serif; font-size: 9px; line-height: 1.2; font-weight: bold; margin-bottom: 5px;">' +
                (window.LM_RAPPORT.legal.address || '') + '<br>' +
                (window.LM_RAPPORT.legal.contact || '') + '<br>' +
                (window.LM_RAPPORT.legal.siret || '') +
                '</div>';

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
            const rapportCloneWrapper = document.createElement('div');
            rapportCloneWrapper.className = 'pdf-page';
            // Layout exact as requested
            rapportCloneWrapper.innerHTML = `
                <!-- HEADER (Logo, Slogan) -->
                <table style="width:100%; border:none; margin-bottom:15px; border-bottom: 2px solid #e67e22;">
                    <tr>
                        <td style="width: 40%; vertical-align: bottom; padding-bottom: 10px;">
                            <img src="/assets/lenoir_logo_doc.png" style="height:60px;">
                        </td>
                        <td style="width: 60%; vertical-align: bottom; text-align: right; padding-bottom: 5px;">
                            <div style="font-size: 11px; font-weight: normal; color: #e67e22; font-style: italic;">
                                Le spécialiste des applications magnétiques pour la séparation et le levage industriel
                            </div>
                        </td>
                    </tr>
                </table>
                <div style="text-align: right; color: #555; font-weight: bold; font-size: 11px; margin-top: 5px; margin-bottom: 15px;">RAPPORT D'EXPERTISE</div>

                <!-- GRAND CADRE ORANGE -->
                <div style="border: 3px solid #d35400; padding: 15px; margin-bottom: 30px;">
                    <h1 style="text-align: center; color: #d35400; font-size: 26px; font-weight: bold; margin: 10px 0 20px 0;">RAPPORT D'EXPERTISE SUR SITE</h1>
                    <div style="text-align: right; font-weight: bold; font-size: 14px; color: black; margin-bottom: 15px;">N°ARC : ${numArc}</div>

                    <!-- TABLEAU CLIENT -->
                    <table style="width:100%; border-collapse:collapse; border: 2px solid #d35400; margin-bottom:20px; font-size:12px; font-family: Arial, sans-serif;">
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
                            <td style="padding: 6px; border: 1px solid #000;">_____</td> <!-- Backend n'a pas séparé Nom/Prénom historiquement, on met un placeholder ou on laisse vide -->
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

                    <!-- TABLEAU PARC MACHINE -->
                    <table style="width:100%; border-collapse:collapse; border: 2px solid #d35400; font-size:12px; font-family: Arial, sans-serif;">
                        <tr>
                            <td colspan="3" style="background-color: #5b9bd5; color: white; text-align: center; font-weight: bold; text-transform: uppercase; padding: 6px; border: 1px solid #000;">
                                PARC MACHINE
                            </td>
                        </tr>
                        <tr>
                            <td style="background-color: #f2f2f2; text-align: center; font-weight: bold; padding: 6px; border: 1px solid #000; width: 10%;">Poste</td>
                            <td style="background-color: #f2f2f2; text-align: center; font-weight: bold; padding: 6px; border: 1px solid #000; width: 30%;">N° A.R.C (N° de série)</td>
                            <td style="background-color: #f2f2f2; text-align: center; font-weight: bold; padding: 6px; border: 1px solid #000; width: 60%;">Désignation du Produit</td>
                        </tr>
                        ${window.LM_RAPPORT.machinesData.map((m, idx) => `
                            <tr>
                                <td style="text-align: center; font-weight: bold; padding: 6px; border: 1px solid #000;">${m.poste || (idx + 1)}</td>
                                <td style="text-align: center; padding: 6px; border: 1px solid #000;">${m.arc || '—'} ${m.of ? ' - ' + m.of : ''}</td>
                                <td style="padding: 6px; border: 1px solid #000;">${m.designation || '—'}</td>
                            </tr>
                        `).join('')}
                    </table>
                </div>

                <!-- SIGNATURES (Hors du cadre orange) -->
                <table style="width:100%; border-collapse:collapse; border: 3px solid #d35400; font-size:13px; font-family: Arial, sans-serif;">
                    <tr>
                        <td style="font-weight: bold; padding: 15px 10px; border: 1px solid #000; width: 25%;">Technicien sur Site :</td>
                        <td style="padding: 15px 10px; border: 1px solid #000; width: 30%; font-weight: bold;">${techName}</td>
                        <td rowspan="2" style="padding: 15px; border: 1px solid #000; width: 45%; text-align: center; vertical-align: middle;">
                            ${stampHTML}
                            <img src="${sigTechData}" style="max-height:90px; max-width:100%; object-fit: contain;">
                            <div style="margin-top:5px; font-size: 11px; color:#2b4c80; font-style:italic;">${techName}</div>
                        </td>
                    </tr>
                    <tr>
                        <td style="font-weight: bold; padding: 15px 10px; border: 1px solid #000;">Date d'expertise :</td>
                        <td style="padding: 15px 10px; border: 1px solid #000; font-weight: bold;">${dateExp}</td>
                    </tr>
                </table>
            `;
            container.appendChild(rapportCloneWrapper);

            // --- 1.2 PAGE SYNTHÈSE + PRÉAMBULE (FUSIONNÉS POUR ÉCONOMISER DES PAGES) ---
            const synthPreambulePage = document.createElement('div');
            synthPreambulePage.className = 'pdf-page';
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
                    <div style="border: 2px solid #000; padding: 20px; color: #000; background: #fff; margin-bottom: 30px; page-break-inside: avoid;">
                        <h2 style="text-align: center; margin-top: 0; margin-bottom: 20px; text-decoration: none; font-size: 16px; text-transform: uppercase; color: #000; font-weight: 900;"><span style="border-bottom: 2px solid #000; padding-bottom: 2px;">SYNTHÈSE DE L'INTERVENTION</span></h2>
                        
                        <div style="margin-bottom: 15px; font-size: 13px; line-height: 1.6; color: #000;">
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
                                <span style="display: inline-block; width: 12px; height: 12px; background: #e67e22; margin-right: 10px;"></span>
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
                        <h2 style="color: #f97316; text-decoration: underline; font-size: 16px; text-transform: uppercase; margin-bottom: 15px;">PRÉAMBULE :</h2>
                        
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
            container.appendChild(synthPreambulePage);

            // --- 2. FETCH & APPEND MACHINES ---
            if (window.LM_RAPPORT && window.LM_RAPPORT.machinesIds) {
                let reportMachineIds = [...window.LM_RAPPORT.machinesIds];
                const emptyOption = window.LM_RAPPORT.emptyFichesOption || 'include';
                const emptyIds = window.LM_RAPPORT.emptyMachinesIds || [];

                // Si option = 'exclude', on retire les machines vides de la boucle
                if (emptyOption === 'exclude' && emptyIds.length > 0) {
                    reportMachineIds = reportMachineIds.filter(id => !emptyIds.includes(parseInt(id, 10)) && !emptyIds.includes(String(id)));
                }

                const totalMachines = reportMachineIds.length;
                console.log(`[PDF] Début du traitement de ${totalMachines} machines.`);
                
                for (let mIdx = 0; mIdx < totalMachines; mIdx++) {
                    const mId = reportMachineIds[mIdx];
                    
                    // Indicateur de progression sur le bouton si possible
                    const labelBtn = document.getElementById('btnDownloadPDFLabel') || document.getElementById('btnDownloadPDF');
                    if (labelBtn) labelBtn.textContent = `⏳ Récupération machine ${mIdx + 1}/${totalMachines}...`;
                    
                    // Si on a gardé la machine mais qu'elle est vide et qu'on voulait 'condensed'
                    if (emptyOption === 'condensed' && (emptyIds.includes(parseInt(mId, 10)) || emptyIds.includes(String(mId)))) {
                        const mData = window.LM_RAPPORT.machinesData.find(m => parseInt(m.id, 10) === parseInt(mId, 10)) || {};
                        const mDesignation = mData.designation || 'Équipement';
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
                                    <td style="width:15%; font-weight:bold; border:1px solid #000; padding:6px; background:#d9d9d9;">N° A.R.C.</td>
                                    <td style="width:35%; border:1px solid #000; padding:6px; font-weight:bold;">${mArc}</td>
                                    <td style="width:15%; font-weight:bold; border:1px solid #000; padding:6px; background:#d9d9d9;">Désignation</td>
                                    <td style="width:35%; border:1px solid #000; padding:6px;"><b>${mDesignation}</b></td>
                                </tr>
                            </table>

                            <div style="margin-top: 150px; text-align: center;">
                                <div style="font-size: 24px; font-weight: bold; color: #dc3545; text-transform: uppercase; border: 4px solid #dc3545; display: inline-block; padding: 20px 40px; transform: rotate(-5deg);">
                                    ÉQUIPEMENT NON CONTRÔLÉ
                                </div>
                                <div style="margin-top: 30px; color: #555; font-size: 14px;">
                                    Aucune donnée n'a été saisie pour ce matériel lors de l'intervention.
                                </div>
                            </div>
                        `;
                        
                        p.style.pageBreakBefore = 'always';
                        container.appendChild(p);

                        continue; // Passe directement à la machine suivante sans fetch html !
                    }

                    try {
                        const res = await fetch('machine_edit.php?id=' + mId + '&pdf=1');
                        const html = await res.text();
                        const parser = new DOMParser();
                        const doc = parser.parseFromString(html, 'text/html');

                        const pages = doc.querySelectorAll('.pdf-page');

                        pages.forEach((p, pIdx) => {
                            // Bug 5 & New Fix: Remove empty photos section
                            // Bug 5 & New Fix: Remove empty photos section
                            const hasPhotos = p.querySelectorAll('.photo-annexe-item img').length > 0;
                            p.querySelectorAll('.photos-annexes-wrapper').forEach(wrapper => {
                                if (!wrapper.querySelector('.photo-annexe-item')) {
                                    wrapper.remove();
                                }
                            });

                            p.querySelectorAll('.photo-btn, .photo-thumbs, #btnChrono').forEach(el => el.remove());
                            p.querySelectorAll('img.no-print-pdf').forEach(el => el.remove());
                            p.querySelectorAll('div.no-print-pdf').forEach(el => el.classList.remove('no-print-pdf'));
                            
                            // If it's a diagram/photo page and it's empty after cleanup, skip it
                            const contentText = p.textContent.trim();
                            if ((pIdx > 0) && contentText.length < 50 && !hasPhotos && !p.querySelector('img')) {
                                return; // Skip empty pages
                            }

                            // Chaque machine commence obligatoirement sur une nouvelle page
                            if (pIdx === 0) {
                                p.style.pageBreakBefore = 'always';
                                p.style.marginTop = '0';
                                p.style.paddingTop = '0';
                                const hDiv = document.createElement('div');
                                hDiv.style.display = 'flex';
                                hDiv.style.justifyContent = 'space-between';
                                hDiv.style.alignItems = 'center';
                                hDiv.style.marginBottom = '20px';
                                hDiv.style.borderBottom = '3px solid #d35400';
                                hDiv.style.paddingBottom = '5px';
                                hDiv.innerHTML = `
                                    <div style="font-size: 14px; font-weight: bold; color: #1B4F72;">FICHE ${mIdx + 1} / ${totalMachines}</div>
                                    <img src="/assets/lenoir_logo_doc.png" style="height: 45px;">
                                `;
                                p.insertBefore(hDiv, p.firstChild);
                            }

                            p.querySelectorAll('input[type="radio"]').forEach(r => {
                                const lbl = r.closest('label');
                                if (lbl) {
                                    if (r.checked) lbl.classList.add('selected');
                                } else {
                                    // For plain naked radio inputs (like in RAPPEL DES FRÉQUENCES blue table)
                                    // that html2canvas refuses to paint correctly: Replace them inline!
                                    const isChecked = r.checked;
                                    r.outerHTML = `<div style="display:inline-block; width:14px; height:14px; border-radius:50%; border:1px solid #777; position:relative; vertical-align:middle; background:transparent;">
                                        ${isChecked ? '<div style="position:absolute; top:50%; left:50%; transform:translate(-50%, -50%); width:6px; height:6px; background:#000; border-radius:50%;"></div>' : ''}
                                    </div>`;
                                }
                            });

                            p.querySelectorAll('input:not([type="radio"]):not([type="checkbox"]):not([type="hidden"]):not([type="file"])').forEach(inp => {
                                let val = (inp.value || '').trim();
                                // Bug 4: Handle "Poste"
                                if (inp.name === 'mesures[poste]') {
                                    val = val ? val : (mIdx + 1);
                                } else if (!val) {
                                    val = '_____';
                                }
                                
                                inp.outerHTML = `<span style="border-bottom:1px dashed black; display:inline-block; min-width:30px; padding:0 3px; font-weight:bold;">${val}</span>`;
                            });

                            p.querySelectorAll('select').forEach(sel => {
                                let valText = sel.options[sel.selectedIndex]?.text || '';
                                if (!sel.value) {
                                    valText = '_____';
                                }
                                sel.outerHTML = `<span style="border-bottom:1px dashed black; display:inline-block; min-width:30px; padding:0 3px; font-weight:bold; color:black;">${valText}</span>`;
                            });

                            p.querySelectorAll('textarea').forEach(ta => {
                                let val = ta.value || ta.innerHTML;
                                
                                // NEW FIX FOR PERFORMANCE / NON REALISE Bug:
                                const specialKeys = ['aprf_attraction_comment', 'ov_perf_bille', 'ov_perf_ecrou', 'ov_perf_rond50', 'ov_perf_rond100', 'levage_charge_maxi_comment', 'levage_temp_maxi_comment'];
                                if (specialKeys.some(k => ta.name && ta.name.includes(k))) {
                                    if (!val.trim()) val = "Non réalisé";
                                }

                                if (ta.name === 'commentaires' && (!val || !val.trim())) {
                                    const parentWrapper = ta.closest('div[style*="margin-top"]');
                                    if (parentWrapper && parentWrapper.innerText.includes('Commentaire')) {
                                        parentWrapper.style.display = 'none';
                                    }
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
                            
                            // L'ancienne logique `tbody` complexe a été retirée car elle cassait les bordures 
                            // et gelait la génération de html2canvas sur iOS/Safari.
                            
                            p.style.minHeight = 'auto';
                            container.appendChild(document.importNode(p, true));
                        });
                    } catch (err) {
                        console.error('Erreur fetch machine ' + mId, err);
                        const errorPage = document.createElement('div');
                        errorPage.className = 'pdf-page';
                        errorPage.innerHTML = `<div style="padding:20px; color:red; border:2px solid red;">⚠️ Erreur lors de la récupération de la machine ${mId} : ${err.message}</div>`;
                        container.appendChild(errorPage);
                    }
                }
            } else {
                console.warn("[PDF] Aucune machine trouvée dans window.LM_RAPPORT.machinesIds");
            }

            // Page de fin : On force SYSTEMATIQUEMENT le saut de page
            // pour satisfaire le besoin "LA DERNIERE PAGE FIXE ET SUR UNE SEUL ET MEME PAGE"

            // --- 4. PAGE DE FIN (STRUCTURE LENOIR-MEC + SIGNATURES + OBSERVATIONS) ---

            const endPage = document.createElement('div');
            endPage.style.width = '21cm';
            endPage.style.padding = '10px 15mm';
            endPage.style.boxSizing = 'border-box';
            endPage.style.background = 'white';
            endPage.style.position = 'static';
            endPage.style.overflow = 'visible';
            endPage.style.pageBreakBefore = 'always';

            const originalRapport = document.getElementById('rapportForm');
            const souhaitRapport = originalRapport.querySelector('[name="souhait_rapport_unique"]').checked;
            const souhaitPieces = originalRapport.querySelector('[name="souhait_offre_pieces"]').checked;
            const souhaitIntervention = originalRapport.querySelector('[name="souhait_pieces_intervention"]').checked;
            const souhaitAucune = originalRapport.querySelector('[name="souhait_aucune_offre"]').checked;
            const contactNomFin = originalRapport.querySelector('[name="contact_nom"]').value || '_____';
            const nomSignataireFin = originalRapport.querySelector('[name="nom_signataire"]').value || contactNomFin;
            const techNameLabel = "<?= htmlspecialchars($techName) ?>";
            const dateStr = window.LM_RAPPORT.dateInt;

            const commentaryTechRaw = originalRapport.querySelector('[name="commentaire_technicien"]')?.value || '';
            const commentaryClientRaw = originalRapport.querySelector('[name="commentaire_client"]')?.value || '';
            
            const commentaryTech = commentaryTechRaw.trim() ? `<div style="border: 2px solid #000; padding: 4px; min-height: 40px; font-size: 11px; white-space: pre-wrap; margin-bottom: 5px;">${commentaryTechRaw}</div>` : '';
            const commentaryClient = commentaryClientRaw.trim() ? `<div style="border: 2px solid #000; padding: 4px; font-size: 11px; white-space: pre-wrap; margin-bottom: 5px;">${commentaryClientRaw}</div>` : '';
            const titleTech = commentaryTechRaw.trim() ? `<div style="background-color: #1B4F72; color: white; border: 2px solid #000; border-bottom: none; padding: 3px 15px; font-weight: bold; font-size: 11px; text-transform: uppercase;">OBSERVATIONS DU TECHNICIEN</div>` : '';
            const titleClient = commentaryClientRaw.trim() ? `<div style="background-color: #1B4F72; color: white; border: 2px solid #000; border-bottom: none; padding: 3px 15px; font-weight: bold; font-size: 11px; text-transform: uppercase;">COMMENTAIRE DU CLIENT</div>` : '';


            const sigTechImg = window.LM_RAPPORT.sigTech || document.getElementById('canvasTech')?.toDataURL() || '';
            const sigClientImg = window.LM_RAPPORT.sigClient || document.getElementById('canvasClient')?.toDataURL() || '';

            endPage.innerHTML = `
                <div style="font-family: Arial, sans-serif; font-size: 11px; color: #000;">
                    
                    <!-- OBSERVATIONS GÉNÉRALES -->
                    <div style="page-break-inside: avoid;">
                        ${titleTech}
                        ${commentaryTech}
                    </div>

                    <div style="page-break-inside: avoid;">
                        ${titleClient}
                        ${commentaryClient}
                    </div>

                    <!-- LE CLIENT SOUHAITE -->
                    <div style="page-break-inside: avoid;">
                        <div style="background-color: #1B4F72; color: white; border: 2px solid #000; border-bottom: none; padding: 3px 15px; font-weight: bold; font-size: 11px; text-transform: uppercase;">LE CLIENT SOUHAITE</div>
                        <div style="border: 2px solid #000; padding: 4px; margin-bottom: 5px;">
                            <div style="margin-bottom: 2px; font-size: 11px;">${souhaitRapport ? '☑' : '☐'} Ce Rapport d\\'expertise uniquement</div>
                            <div style="margin-bottom: 2px; font-size: 11px;">${souhaitPieces ? '☑' : '☐'} Une offre de Pièces de Rechange</div>
                            <div style="margin-bottom: 2px; font-size: 11px;">${souhaitIntervention ? '☑' : '☐'} Une offre de PR + intervention mise en place</div>
                            <div style="font-size: 11px;">${souhaitAucune ? '☑' : '☐'} Aucune offre</div>
                        </div>
                    </div>

                    <!-- DATE ET HEURE -->
                    <div style="page-break-inside: avoid;">
                        <div style="background-color: #1B4F72; color: white; border: 2px solid #000; border-bottom: none; padding: 3px 15px; font-weight: bold; font-size: 11px; text-transform: uppercase;">DATE ET HEURE</div>
                        <div style="border: 2px solid #000; padding: 4px; margin-bottom: 5px; font-size: 12px; font-weight: bold; text-align: center;">
                            Fait le ${dateStr}
                        </div>
                    </div>

                    <!-- SIGNATURES -->
                    <div style="page-break-inside: avoid;">
                        <div style="background-color: #1B4F72; color: white; border: 2px solid #000; border-bottom: none; padding: 3px 15px; font-weight: bold; font-size: 11px; text-transform: uppercase;">SIGNATURES</div>
                        <table style="width: 100%; border-collapse: collapse; table-layout: fixed; border: 2px solid #000; margin-bottom: 5px;">
                            <tr style="height: 80px;">
                                <td style="border: 1px solid #000; padding: 4px; vertical-align: top; width: 50%;">
                                    <div style="font-weight: bold; text-decoration: underline; margin-bottom: 3px;">Contrôleur (NOM Prénom) :</div>
                                    <div style="margin-bottom: 5px;"><strong>${techNameLabel}</strong></div>
                                    <div style="text-align: center;">
                                        <img src="${sigTechImg}" style="max-height: 55px; max-width: 90%; object-fit: contain; background: white;">
                                    </div>
                                </td>
                                <td style="border: 1px solid #000; padding: 4px; vertical-align: top; width: 50%;">
                                    <div style="font-weight: bold; text-decoration: underline; margin-bottom: 3px;">Client (NOM Prénom) :</div>
                                    <div style="margin-bottom: 5px;"><strong>${nomSignataireFin}</strong></div>
                                    <div style="text-align: center;">
                                        <img src="${sigClientImg}" style="max-height: 55px; max-width: 90%; object-fit: contain; background: white;">
                                    </div>
                                </td>
                            </tr>
                        </table>
                    </div>

                    <!-- CONTACTS ORANGE -->
                    <div style="border: 2px solid #000; padding: 0; text-align: center; margin-bottom: 5px;">
                        <div style="background-color: #E67E22; color: white; padding: 3px 15px; font-weight: bold; border-bottom: 2px solid #000; font-size: 10px;">POUR TOUTE INFORMATION TECHNIQUE SUR CE RAPPORT</div>
                        <div style="background-color: #fff; padding: 4px; border-bottom: 2px solid #000;">
                            <div style="font-size: 11px;">➤ <strong>Soufyane SALAH</strong> &nbsp;&nbsp;&nbsp; <span style="font-style: italic;">Chargé d'Affaires</span></div>
                        </div>
                        
                        <div style="background-color: #E67E22; color: white; padding: 3px 15px; font-weight: bold; border-bottom: 2px solid #000; font-size: 10px;">POUR LA PLANIFICATION D'UNE VÉRIFICATION PÉRIODIQUE</div>
                        <div style="background-color: #fff; padding: 4px;">
                            <div style="font-size: 11px;">➤ <strong>Sophie NIAY</strong> &nbsp;&nbsp;&nbsp; <span style="font-style: italic;">Responsable Service Clients</span></div>
                        </div>
                    </div>

                    <!-- FOOTER SECTION WITH QR CODE -->
                    <div class="qr-block" style="text-align: center; color: #1B4F72; margin-top: 5px; page-break-inside: avoid; padding-bottom: 5px;">
                        <div style="font-weight: bold; margin-bottom: 4px;">UNE SEULE ADRESSE COMMUNE : contact@raoul-lenoir.com</div>
                        
                        <div style="margin-top: 4px;">
                            <div style="font-size: 10px; font-weight: bold; margin-bottom: 2px;">Visitez notre site !</div>
                            <img src="/assets/qr_lenoir.png" style="width: 80px; height: 80px; display: block; margin: 0 auto;">
                            <div style="font-weight: bold; font-size: 10px; margin-top: 2px;">www.raoul-lenoir.com</div>
                        </div>
                    </div>
                </div>
            `;
            container.appendChild(endPage);

            // CHANGEMENT MAJEUR CONTRE LA COUPURE DE CANVAS : border-collapse empêche html2pdf de calculer la hauteur des TR
            // On le force en "separate" pour donner à html2pdf des hauteurs de TR nettes et mesurables sans overlap.
            container.querySelectorAll('table.pdf-table, table.controles').forEach(tbl => {
                tbl.style.borderCollapse = 'separate';
                tbl.style.borderSpacing = '0';
            });

            // Blindage ultime anti-coupure de tableaux
            // 1) Chaque ligne <tr> ne peut pas être coupée
            container.querySelectorAll('tr').forEach(tr => {
                tr.style.pageBreakInside = 'avoid';
                tr.classList.add('avoid-break');
            });
            // 2) Les sections pdf-section ne sont pas coupées
            container.querySelectorAll('.pdf-section').forEach(sec => {
                sec.style.pageBreakInside = 'avoid';
            });
            // 3) Les petits tableaux (< 15 lignes) ne peuvent pas être coupés du tout
            container.querySelectorAll('table').forEach(tbl => {
                if (tbl.querySelectorAll('tr').length <= 15) {
                    tbl.style.pageBreakInside = 'avoid';
                    tbl.classList.add('avoid-break');
                }
            });

            await waitForImages(container);
            return container;
        }

        // ══════════════════════════════════════════════════════════════════
        // GÉNÉRATION PDF (html2pdf.js)
        // ══════════════════════════════════════════════════════════════════
        async function genererPDFBase64() {
            if (!window.html2pdf) throw new Error('html2pdf.js non disponible');

            const container = await buildFullPdfContainer();

            const opt = {
                margin: [10, 0, 15, 0], // Top, Left, Bottom, Right
                filename: window.LM_RAPPORT ? window.LM_RAPPORT.pdfFilename : 'rapport.pdf',
                image: { type: 'jpeg', quality: 0.98 },
                html2canvas: { scale: 2, useCORS: true, logging: false },
                jsPDF: { unit: 'mm', format: 'a4', orientation: 'portrait' },
                pagebreak: { mode: ['css', 'legacy'], avoid: ['tr', 'tbody', 'img', '.photo-annexe-item', '.pdf-section', '.sig-zone', '.qr-block', '.avoid-break'] }
            };

            return new Promise(async (resolve, reject) => {
                try {
                    const worker = html2pdf().set(opt).from(container);
                    await worker.toPdf().get('pdf').then(function (pdf) {
                        const totalPages = pdf.internal.getNumberOfPages();
                        for (let i = 1; i <= totalPages; i++) {
                            pdf.setPage(i);
                            // Page number
                            pdf.setFont('helvetica', 'normal');
                            pdf.setFontSize(9);
                            pdf.setTextColor(50, 50, 50);
                            pdf.text('Page ' + i + ' / ' + totalPages, 105, 282, { align: 'center' });
                            // Legal footer
                            pdf.setFontSize(6);
                            pdf.setTextColor(0, 0, 0);
                            pdf.setFont('helvetica', 'bold');
                            const leg = window.LM_RAPPORT.legal;
                            pdf.text(leg.address, 105, 286, { align: 'center' });
                            pdf.text(leg.contact, 105, 289, { align: 'center' });
                            pdf.text(leg.siret, 105, 292, { align: 'center' });
                        }
                    });
                    const pdfBlob = await worker.outputPdf('blob');
                    const reader = new FileReader();
                    reader.onload = () => resolve(reader.result.split(',')[1]);
                    reader.onerror = reject;
                    reader.readAsDataURL(pdfBlob);
                } catch (e) {
                    reject(e);
                }
            });
        }

        // ══════════════════════════════════════════════════════════════════
        // TÉLÉCHARGEMENT PDF BOUTON DIRECT
        // ══════════════════════════════════════════════════════════════════
        async function telechargerPDF() {
            const btn = document.getElementById('btnDownloadPDF');
            const originalContent = btn ? btn.innerHTML : '';
            if (btn) { btn.disabled = true; btn.innerHTML = '⏳ Préparation du rapport...'; }
            
            try {
                const container = await buildFullPdfContainer();
                const nbPagesAssemblees = container.querySelectorAll('.pdf-page').length;
                
                if (nbPagesAssemblees <= 2 && window.LM_RAPPORT.machinesIds.length > 0) {
                    if (!confirm("Attention : Le rapport semble ne contenir aucune fiche machine (3 pages seulement). Voulez-vous quand même continuer ?")) {
                        throw new Error("Génération annulée par l'utilisateur car le rapport était incomplet.");
                    }
                }

                const opt = {
                    margin: [10, 0, 15, 0], // Top, Left, Bottom, Right
                    filename: window.LM_RAPPORT ? window.LM_RAPPORT.pdfFilename : 'rapport.pdf',
                    image: { type: 'jpeg', quality: 0.98 },
                    html2canvas: { scale: 2, useCORS: true, logging: false },
                    jsPDF: { unit: 'mm', format: 'a4', orientation: 'portrait' },
                    pagebreak: { mode: ['css', 'legacy'], avoid: ['tr', 'tbody', 'img', '.photo-annexe-item', '.pdf-section', '.sig-zone', '.qr-block', '.avoid-break'] }
                };

                const worker = html2pdf().set(opt).from(container);
                await worker.toPdf().get('pdf').then(function (pdf) {
                    const totalPages = pdf.internal.getNumberOfPages();
                    for (let i = 1; i <= totalPages; i++) {
                        pdf.setPage(i);
                        // Page number
                        pdf.setFont('helvetica', 'normal');
                        pdf.setFontSize(9);
                        pdf.setTextColor(50, 50, 50);
                        pdf.text('Page ' + i + ' / ' + totalPages, 105, 282, { align: 'center' });
                        // Legal footer
                        pdf.setFontSize(6);
                        pdf.setTextColor(0, 0, 0);
                        pdf.setFont('helvetica', 'bold');
                        const leg = window.LM_RAPPORT.legal;
                        pdf.text(leg.address, 105, 286, { align: 'center' });
                        pdf.text(leg.contact, 105, 289, { align: 'center' });
                        pdf.text(leg.siret, 105, 292, { align: 'center' });
                    }
                });
                await worker.save();
            } catch (e) {
                alert('Erreur génération PDF : ' + e.message);
            } finally {
                if (btn) { btn.disabled = false; btn.textContent = '⬇️ Télécharger le PDF'; }
            }
        }

        // ══════════════════════════════════════════════════════════════════
        // TOAST UI
        // ══════════════════════════════════════════════════════════════════
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
            if (label) label.textContent = 'Génération du PDF…';

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
                    afficherToast('📶 Hors-ligne – email mis en file d\'attente. Il sera envoyé automatiquement à la reconnexion.', 'warning');
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
                    afficherToast('✅ Rapport envoyé avec succès à ' + result.email, 'success');
                    if (btn) btn.style.background = 'linear-gradient(135deg,#10b981,#059669)';
                    if (icon) icon.textContent = '✅';
                    if (label) label.textContent = 'Email envoyé !';
                    btn.disabled = true; // Ne pas renvoyer
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
                    afficherToast('📶 Connexion perdue – email mis en file d\'attente. Il sera envoyé à la reconnexion.', 'warning');
                } catch (qe) {
                    afficherToast('❌ Erreur réseau et impossible de mettre en file : ' + e.message, 'error');
                }
                if (btn) btn.disabled = false;
                if (icon) icon.textContent = '🔄';
                if (label) label.textContent = 'Réessayer l\'envoi';
            }
        }

        // ══════════════════════════════════════════════════════════════════
        // GÉNÉRATION IA PAR LOT (BATCH)
        // ══════════════════════════════════════════════════════════════════
        async function generateAllIA() {
            if (!confirm("L'Expert IA va parcourir toutes les machines du rapport.\n\nNOTE : Les conclusions et causes déjà saisies seront PRÉSERVÉES. Seules les fiches vides seront complétées.\n\nContinuer ?")) return;

            const btn = document.getElementById('btnGenerateAllAI');
            const progress = document.getElementById('iaBatchProgress');
            const bar = document.getElementById('iaBatchProgressBar');
            const status = document.getElementById('iaBatchStatus');
            const mIds = window.LM_RAPPORT.machinesIds;

            btn.disabled = true;
            btn.innerHTML = '⌛ Analyse par l\'Expert IA...';
            progress.style.display = 'block';
            
            let current = 0;
            const total = mIds.length;

            for (const id of mIds) {
                current++;
                const mData = window.LM_RAPPORT.machinesData.find(m => m.id == id) || { designation: 'Machine', dysfonctionnements: '', conclusion: '' };
                
                // Sécurité : Ne pas écraser si déjà rempli
                if (mData.dysfonctionnements && mData.conclusion && mData.dysfonctionnements.trim() !== '' && mData.conclusion.trim() !== '') {
                    status.innerText = `Saut de: ${mData.designation} (Déjà rempli)`;
                    bar.style.width = (current / total * 100) + '%';
                    continue;
                }

                status.innerText = `Analyse par l'Expert IA: ${mData.designation} (${current}/${total})`;
                bar.style.width = ((current - 0.5) / total * 100) + '%';

                try {
                    // 1. Dysfonctionnements (Seulement si vide)
                    if (!mData.dysfonctionnements || mData.dysfonctionnements.trim() === '') {
                        let resE = await fetch(`generate_ia.php?type=E&id=${id}`);
                        let dataE = await resE.json();
                        if (dataE.content) {
                            await fetch('save_ia.php', {
                                method: 'POST',
                                headers: { 'Content-Type': 'application/json' },
                                body: JSON.stringify({ id: id, type: 'dysfonctionnements', content: dataE.content })
                            });
                        }
                    }

                    // 2. Conclusion (Seulement si vide)
                    if (!mData.conclusion || mData.conclusion.trim() === '') {
                        let resF = await fetch(`generate_ia.php?type=F&id=${id}`);
                        let dataF = await resF.json();
                        if (dataF.content) {
                            await fetch('save_ia.php', {
                                method: 'POST',
                                headers: { 'Content-Type': 'application/json' },
                                body: JSON.stringify({ id: id, type: 'conclusion', content: dataF.content })
                            });
                        }
                    }
                } catch (e) {
                    console.error(`Error processing machine ${id}:`, e);
                }
                
                bar.style.width = (current / total * 100) + '%';
            }

            status.innerText = "✅ Analyse terminée !";
            btn.innerHTML = '<img src="/assets/ai_expert.jpg" style="height:16px; width:16px; border-radius:50%; vertical-align:middle; margin-right:6px;"> Relancer l\'Expert IA';
            btn.disabled = false;
            
            // Recharger les données PHP pour que le PDF utilise les nouvelles valeurs DB
            setTimeout(() => {
                location.reload(); 
            }, 1000);
        }

    </script>
</body>
</html>