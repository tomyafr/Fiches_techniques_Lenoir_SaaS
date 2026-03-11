<?php
require_once __DIR__ . '/../includes/config.php';
requireAuth(['technicien', 'admin']);

$db = getDB();
$userId = $_SESSION['user_id'];
$id = $_GET['id'] ?? null;

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
$machines = $stmtM->fetchAll();

// Fetch technicien name
$stmtT = $db->prepare('SELECT prenom, nom FROM users WHERE id = ?');
$stmtT->execute([$intervention['technicien_id']]);
$tech = $stmtT->fetch();
$techName = ($tech['prenom'] ?? '') . ' ' . ($tech['nom'] ?? '');

// Handle form POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    verifyCsrfToken();

    if ($_POST['action'] === 'save_rapport') {
        try {
            $contactNom = trim($_POST['contact_nom'] ?? '');
            $contactFonction = trim($_POST['contact_fonction'] ?? '');
            $contactEmail = trim($_POST['contact_email'] ?? '');
            $contactTel = trim($_POST['contact_telephone'] ?? '');
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
            $nomSignataire = trim($_POST['nom_signataire'] ?? '');

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
                /* Make it white/bright on the dark theme screen */
                filter: brightness(0) invert(1) opacity(0.9);
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

                <!-- Données PHP exposées pour le JS -->
                <script>
                    window.LM_RAPPORT = {
                        interventionId: <?= (int) $id ?>,
                        clientEmail: <?= json_encode($intervention['contact_email'] ?? $intervention['c_email'] ?? '') ?>,
                        nomSociete: <?= json_encode($intervention['nom_societe'] ?? '') ?>,
                        dateInt: <?= json_encode(date('d/m/Y', strtotime($intervention['date_intervention'] ?? 'now'))) ?>,
                        csrfToken: <?= json_encode(getCsrfToken()) ?>,
                        pdfFilename: <?= json_encode('Rapport_Lenoir_Mec_' . preg_replace('/[^A-Za-z0-9_\-]/', '_', $intervention['numero_arc'] ?? 'rapport') . '_' . date('d-m-Y') . '.pdf') ?>, machinesIds: [<?= implode(',', array_column($machines, 'id')) ?>]
                    };
                </script>
            <?php endif; ?>

            <?php if (!empty($error)): ?>
                <div
                    style="background: rgba(244,63,94,0.15); border:1px solid rgba(244,63,94,0.4); color:#f43f5e; padding:1rem; border-radius:8px; margin-bottom:1.5rem; font-size:0.85rem;">
                    ⚠️ <?= htmlspecialchars($error) ?>
                </div>
            <?php endif; ?>

            <!-- EN-TÊTE -->
            <div class="rapport-header card glass">
                <img src="/assets/lenoir_logo_doc.png" alt="LENOIR-MEC" class="rapport-logo"
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
                    <span class="machine-tag">
                        ⚙️
                        <?= htmlspecialchars($m['designation']) ?>
                        <?php $mm = json_decode($m['mesures'] ?? '{}', true); ?>
                        <?php if (!empty($mm['repere'])): ?>
                            <small style="opacity:0.7">–
                                <?= htmlspecialchars($mm['repere']) ?>
                            </small>
                        <?php endif; ?>
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
                        <label class="label">Nom du contact</label>
                        <input type="text" name="contact_nom" class="input" placeholder="Nom et prénom..."
                            value="<?= htmlspecialchars($intervention['contact_nom'] ?? '') ?>">
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
                    <input type="checkbox" name="souhait_rapport_unique" value="1"
                        <?= ($intervention['souhait_rapport_unique'] ?? false) ? 'checked' : '' ?>>
                    <span>Ce rapport d'expertise uniquement</span>
                </label>
                <label class="checkbox-item">
                    <input type="checkbox" name="souhait_offre_pieces" value="1"
                        <?= ($intervention['souhait_offre_pieces'] ?? false) ? 'checked' : '' ?>>
                    <span>Une offre de pièces de rechange</span>
                </label>
                <label class="checkbox-item">
                    <input type="checkbox" name="souhait_pieces_intervention" value="1"
                        <?= ($intervention['souhait_pieces_intervention'] ?? false) ? 'checked' : '' ?>>
                    <span>Pièces de rechange + intervention mise en place</span>
                </label>
                <label class="checkbox-item">
                    <input type="checkbox" name="souhait_aucune_offre" value="1"
                        <?= ($intervention['souhait_aucune_offre'] ?? false) ? 'checked' : '' ?>>
                    <span>Aucune offre</span>
                </label>
            </div>

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
                            placeholder="Nom et prénom du signataire..."
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

    <!-- html2pdf.js pour génération PDF côté client -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/signature_pad@4.1.7/dist/signature_pad.umd.min.js"></script>
    <script>
        let padClient, padTech;

        function resizeCanvas(canvas) {
            const ratio = Math.max(window.devicePixelRatio || 1, 1);
            canvas.style.height = '200px';
            canvas.width = canvas.offsetWidth * ratio;
            canvas.height = 200 * ratio;
            canvas.getContext('2d').scale(ratio, ratio);
        }

        function initSignatures() {
            const canvasC = document.getElementById('canvasClient');
            const canvasT = document.getElementById('canvasTech');
            if (!canvasC || !canvasT || !window.SignaturePad) return;

            const dpr = Math.max(window.devicePixelRatio || 1, 1);

            resizeCanvas(canvasT);
            padTech = new SignaturePad(canvasT, { penColor: 'black' });
            <?php if (!empty($intervention['signature_technicien'])): ?>
                padTech.fromDataURL('<?= $intervention['signature_technicien'] ?>', {
                    ratio: dpr,
                    width: canvasT.width / dpr,
                    height: canvasT.height / dpr
                });
            <?php endif; ?>

            resizeCanvas(canvasC);
            padClient = new SignaturePad(canvasC, { penColor: 'blue' });
            <?php if (!empty($intervention['signature_client'])): ?>
                padClient.fromDataURL('<?= $intervention['signature_client'] ?>', {
                    ratio: dpr,
                    width: canvasC.width / dpr,
                    height: canvasC.height / dpr
                });
            <?php endif; ?>
        }

        function validateAndSubmit() {
            if (!padClient || !padTech) {
                alert('Erreur: les zones de signature ne sont pas initialisées.');
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
            // L'envoi email est déclenché manuellement par le technicien
            // via le bouton "📧 Envoyer PDF par email" — pas d'envoi automatique.
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

        async function buildFullPdfContainer() {
            // Conteneur détaché du DOM : il ne faut pas l'insérer dans body
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
                    min-height: 29.7cm;
                    background: white;
                    color: black;
                    padding: 10mm;
                    box-sizing: border-box;
                    margin: 0;
                    font-family: Arial, sans-serif;
                    font-size: 13px;
                }
                .html2pdf__page-break {
                    height: 0;
                    page-break-before: always !important;
                    break-before: page !important;
                }
                .pdf-table { width: 100%; border-collapse: collapse; margin-bottom: 20px; color: black; font-size: 11px; }
                .pdf-table th, .pdf-table td { border: 1px solid #000; padding: 4px; vertical-align: middle; }
                .pdf-table th { background-color: #f0f0f0; text-align: left; text-transform: uppercase; }
                
                /* PASTILLES: On masque celles non sélectionnées et on met en valeur celle cochée */
                .pastille-group { display: inline-flex; gap: 4px; align-items: center; }
                .pastille-group label {
                    display: flex; align-items: center; justify-content: center;
                    width: 24px; height: 24px; border-radius: 50%;
                    border: 1px solid #ddd !important; background: transparent !important; opacity: 0.15; font-size: 0; position: relative;
                }
                .pastille-group label.selected { opacity: 1; border-width: 2px !important; }
                .pastille-group label.selected.p-na { background: #bbb !important; border-color: #999 !important; }
                .pastille-group label.selected.p-ok { background: #28a745 !important; border-color: #1e7e34 !important; }
                .pastille-group label.selected.p-aa { background: #e67e22 !important; border-color: #d35400 !important; }
                .pastille-group label.selected.p-nc { background: #dc3545 !important; border-color: #bd2130 !important; }
                .pastille-group label.selected.p-nr { background: #8b0000 !important; border-color: #5a0000 !important; }
                .pastille-group label.selected::after {
                    content: '✓'; color: white; font-size: 14px; font-weight: bold; line-height: 1;
                }

                .pdf-input { border: none; border-bottom: 1px dashed #000; background: transparent; font-size: 13px; font-family: Arial; padding: 2px; width: 100%; color: black; outline:none; }
                
                /* Rendu des commentaires pour qu'ils soient lisibles sans ascenceur */
                .pdf-textarea-rendered { 
                    width: 100%; font-family: Arial; font-size: 11px; color: black; white-space: pre-wrap; word-wrap: break-word; padding:4px; 
                }
                
                /* Surcharges rapport final (Couverture et Signatures) */
                .rapport-page-cloned { padding: 1.5cm; }
                .rapport-page-cloned .card.glass {
                    border: 1px solid #000 !important;
                    background: white !important;
                    box-shadow: none !important;
                }
                .rapport-page-cloned .label { color: #000 !important; font-weight: bold; margin-bottom: 0.2rem; }
                .rapport-page-cloned .input { background: white !important; color: black !important; border-bottom: 1px solid #000 !important; border-top: none; border-left: none; border-right: none; border-radius: 0; padding-left: 0; }
                .rapport-page-cloned .rapport-header { border-bottom: 3px solid #000; padding-bottom: 10px; margin-bottom: 20px;}
                .rapport-page-cloned .rapport-header h1 { color: #000; font-size: 1.5rem; }
                .rapport-page-cloned .section-title { color: #000; border-bottom: 1px solid #000; font-weight: bold; margin-bottom:15px; margin-top:20px; font-size:1.1rem; }
                .rapport-page-cloned .machines-recap { display: flex; flex-direction: column; gap: 5px; margin-bottom: 1rem; }
                .rapport-page-cloned .machines-recap .machine-tag { background: none; border: 1px solid transparent; color: #000; display: block; padding: 5px 0; font-size: 1rem;}
                
                img { max-width: 100%; }
            `;
            container.appendChild(styleNode);

            // --- 1. PAGE RAPPORT FINAL (COUVERTURE + INFOS) ---
            const rapportCloneWrapper = document.createElement('div');
            rapportCloneWrapper.className = 'pdf-page rapport-page-cloned';

            const originalRapport = document.querySelector('.rapport-page');
            const clone = originalRapport.cloneNode(true);

            // Nettoyage absolu du clone (on supprime les parties non voulues sur la couverture)
            clone.querySelectorAll('.mobile-header, .btn-final, .sig-clear, #successBanner, #btnSendEmail, #btnDownloadPDF, a, button').forEach(el => el.remove());

            // Pour la couverture, on va séparer le rapport en deux : 
            // - Couverture = Logo, en-tête, listes machines, Infos Client
            // - Fin = Commentaires tech, souhaits, commentaires clients, signatures

            // Diviser le clone
            const cloneCouverture = clone.cloneNode(true);
            const cloneFin = clone.cloneNode(true);

            // Dans la couverture, on supprime tout à partir de "Commentaire technicien"
            const allSectionsCouv = cloneCouverture.querySelectorAll('.section-title');
            let foundComm = false;
            allSectionsCouv.forEach(sec => {
                if (sec.textContent.includes('Commentaire / Observations') || foundComm) {
                    foundComm = true;
                    // On supprime la section et son contenu suivant... c'est complexe de trouver le suivant
                }
            });
            // Méthode simple : supprimer par conteneurs explicites
            const elementsToRemoveFromCover = Array.from(cloneCouverture.children).slice(5);
            // On conserve: form csrf(0), header(1), recap machines title(2), recap(3), info client title(4), card info client(5).
            // Le index 6 et + = le reste.
            const enfantsCouv = Array.from(cloneCouverture.querySelector('form').children);
            let idxStartFin = -1;
            enfantsCouv.forEach((el, index) => {
                if (el.classList && el.classList.contains('section-title') && el.textContent.includes('Observations')) {
                    idxStartFin = index;
                }
            });

            if (idxStartFin > -1) {
                // Remove elements from Cover
                for (let i = idxStartFin; i < enfantsCouv.length; i++) {
                    enfantsCouv[i].remove();
                }
            }

            // Fixer les inputs texte de la couverture
            const couvInputs = cloneCouverture.querySelectorAll('input[type="text"], input[type="email"], input[type="tel"]');
            couvInputs.forEach(inp => { const val = inp.value; inp.setAttribute('value', val); inp.value = val; });

            rapportCloneWrapper.appendChild(cloneCouverture);
            container.appendChild(rapportCloneWrapper);


            // --- 2. FETCH & APPEND MACHINES ---
            if (window.LM_RAPPORT && window.LM_RAPPORT.machinesIds) {
                for (const mId of window.LM_RAPPORT.machinesIds) {
                    try {
                        const res = await fetch('machine_edit.php?id=' + mId);
                        const html = await res.text();
                        const parser = new DOMParser();
                        const doc = parser.parseFromString(html, 'text/html');

                        // Récupérer toutes les pdf-page de cette machine
                        const pages = doc.querySelectorAll('.pdf-page');
                        let isFirstMachinePage = true;
                        pages.forEach(p => {
                            // PAGE BREAK avant chaque page
                            const pbReq = document.createElement('div');
                            pbReq.className = 'html2pdf__page-break';
                            container.appendChild(pbReq);

                            // Afficher la désignation en gros sur la première page de chaque machine
                            if (isFirstMachinePage) {
                                const hHeader = document.createElement('div');
                                hHeader.style.padding = "10px";
                                hHeader.style.backgroundColor = "#eee";
                                hHeader.style.border = "2px solid #000";
                                hHeader.style.fontWeight = "bold";
                                hHeader.style.textAlign = "center";
                                hHeader.style.marginBottom = "20px";
                                hHeader.style.fontSize = "16px";
                                hHeader.innerText = "FICHE TECHNIQUE MACHINE - ID " + mId;
                                p.insertBefore(hHeader, p.firstChild);
                                isFirstMachinePage = false;
                            }

                            // Fixer la pastille active
                            p.querySelectorAll('input[type="radio"]:checked').forEach(r => {
                                const lbl = r.closest('label');
                                if (lbl) lbl.classList.add('selected');
                            });

                            // Fixer textes input
                            p.querySelectorAll('input[type="text"], input[type="time"]').forEach(inp => {
                                inp.setAttribute('value', inp.value);
                            });
                            // Extraire le texte des textarea -> Transformer en div lisible
                            p.querySelectorAll('textarea').forEach(ta => {
                                const div = document.createElement('div');
                                div.className = 'pdf-textarea-rendered';
                                div.style.minHeight = ta.style.height || '25px';
                                div.textContent = ta.innerHTML || ta.value;
                                ta.parentNode.insertBefore(div, ta);
                                ta.remove();
                            });

                            container.appendChild(p);
                        });
                    } catch (err) {
                        console.error('Erreur fetch machine ' + mId, err);
                    }
                }
            }


            // PAGE BREAK FINAL AVANT LA DERNIERE PAGE DE CONCLUSION
            const pbFin = document.createElement('div');
            pbFin.className = 'html2pdf__page-break';
            container.appendChild(pbFin);

            // --- 3. PAGE RAPPORT FINAL (CONCLUSION) ---
            const rapportCloneWrapperFin = document.createElement('div');
            rapportCloneWrapperFin.className = 'pdf-page rapport-page-cloned';

            const enfantsFin = Array.from(cloneFin.querySelector('form').children);
            if (idxStartFin > -1) {
                // Remove elements from Fin (everything before the start of the final section)
                for (let i = 0; i < idxStartFin; i++) {
                    if (!enfantsFin[i].name && enfantsFin[i].nodeName !== "INPUT") { // Garder potentiellement csrf, etc. 
                        enfantsFin[i].remove();
                    }
                }
            }

            // Remettre le logo en haut
            const headerFin = document.createElement('div');
            headerFin.className = 'rapport-header';
            headerFin.innerHTML = `<img src="/assets/lenoir_logo_doc.png" alt="LENOIR-MEC" style="height: 60px; object-fit: contain; margin: 0 auto 1rem auto; display: block;">`;
            cloneFin.querySelector('form').insertBefore(headerFin, cloneFin.querySelector('form').firstChild);

            // Remplacer Textareas de la conclusion par des div stylisées
            const origTexteareasFin = originalRapport.querySelectorAll('textarea'); // on utilise l'original pour la valeur
            const cloneTextareasFin = cloneFin.querySelectorAll('textarea');
            cloneTextareasFin.forEach((ta, i) => {
                const realTa = origTexteareasFin[i];
                if (realTa) {
                    const div = document.createElement('div');
                    div.className = 'pdf-textarea-rendered';
                    div.style.border = '1px solid #000';
                    div.style.padding = '10px';
                    div.style.minHeight = ta.classList.contains('large') ? '150px' : '80px';
                    div.style.fontSize = "13px";
                    div.textContent = realTa.value;
                    ta.parentNode.insertBefore(div, ta);
                }
                ta.remove();
            });

            // Remplacer checkbox de "Le client souhaite" par un affichage Unicode ✓ ou ☐
            const origCheckFin = originalRapport.querySelectorAll('.checkbox-group input[type="checkbox"]');
            const cloneCheckFin = cloneFin.querySelectorAll('.checkbox-group input[type="checkbox"]');
            cloneCheckFin.forEach((chk, i) => {
                const span = document.createElement('span');
                // ✅ ☑ ☐
                span.innerHTML = (origCheckFin[i] && origCheckFin[i].checked) ? '☑' : '☐';
                span.style.fontSize = '1.3rem';
                span.style.marginRight = '8px';
                span.style.lineHeight = '1';
                span.style.verticalAlign = 'middle';
                chk.parentNode.insertBefore(span, chk);
                // Mettre le texte en gras si coché
                if (origCheckFin[i] && origCheckFin[i].checked) {
                    chk.parentNode.style.fontWeight = "bold";
                }
                chk.remove();
            });

            const finInputs = cloneFin.querySelectorAll('input[type="text"], input[type="email"], input[type="tel"]');
            finInputs.forEach(inp => { const val = inp.value; inp.setAttribute('value', val); inp.value = val; });

            // Recopier les vraies signatures
            const origCanvasTech = document.getElementById('canvasTech');
            const origCanvasClient = document.getElementById('canvasClient');
            if (origCanvasTech) {
                const imgTech = document.createElement('img');
                imgTech.src = origCanvasTech.toDataURL();
                imgTech.style.width = '100%';
                imgTech.style.maxHeight = '150px';
                imgTech.style.objectFit = 'contain';
                const cCloneT = cloneFin.querySelector('#canvasTech');
                if (cCloneT) cCloneT.parentNode.replaceChild(imgTech, cCloneT);
            }
            if (origCanvasClient) {
                const imgClient = document.createElement('img');
                imgClient.src = origCanvasClient.toDataURL();
                imgClient.style.width = '100%';
                imgClient.style.maxHeight = '150px';
                imgClient.style.objectFit = 'contain';
                const cCloneC = cloneFin.querySelector('#canvasClient');
                if (cCloneC) cCloneC.parentNode.replaceChild(imgClient, cCloneC);
            }

            rapportCloneWrapperFin.appendChild(cloneFin);
            container.appendChild(rapportCloneWrapperFin);

            // PAGE BREAK FINAL
            const pbF = document.createElement('div');
            pbF.className = 'html2pdf__page-break';
            container.appendChild(pbF);

            // --- 4. PAGE DE FIN ---
            const endPage = document.createElement('div');
            endPage.className = 'pdf-page';
            endPage.style.display = 'flex';
            endPage.style.justifyContent = 'center';
            endPage.style.alignItems = 'center';
            endPage.style.padding = '0';
            endPage.innerHTML = `<img src="/assets/machines/99-page de fin_diagram.png" style="width:100%; height:100%; object-fit:contain;">`;
            container.appendChild(endPage);

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
                margin: 0,
                filename: window.LM_RAPPORT ? window.LM_RAPPORT.pdfFilename : 'rapport.pdf',
                image: { type: 'jpeg', quality: 0.98 },
                html2canvas: { scale: 2, useCORS: true, logging: false },
                jsPDF: { unit: 'mm', format: 'a4', orientation: 'portrait' }
            };

            const worker = html2pdf().set(opt).from(container);
            const pdfBlob = await worker.outputPdf('blob');

            return new Promise((resolve, reject) => {
                const reader = new FileReader();
                reader.onload = () => resolve(reader.result.split(',')[1]);
                reader.onerror = reject;
                reader.readAsDataURL(pdfBlob);
            });
        }

        // ══════════════════════════════════════════════════════════════════
        // TÉLÉCHARGEMENT PDF BOUTON DIRECT
        // ══════════════════════════════════════════════════════════════════
        async function telechargerPDF() {
            const btn = document.getElementById('btnDownloadPDF');
            if (btn) { btn.disabled = true; btn.textContent = '⏳ Génération du rapport complet...'; }
            try {
                const container = await buildFullPdfContainer();

                const opt = {
                    margin: 0,
                    filename: window.LM_RAPPORT ? window.LM_RAPPORT.pdfFilename : 'rapport.pdf',
                    image: { type: 'jpeg', quality: 0.98 },
                    html2canvas: { scale: 2, useCORS: true, logging: false },
                    jsPDF: { unit: 'mm', format: 'a4', orientation: 'portrait' }
                };

                await html2pdf().set(opt).from(container).save();
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
    </script>
</body>

</html>