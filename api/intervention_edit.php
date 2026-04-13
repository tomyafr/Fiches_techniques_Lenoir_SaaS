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

// Fetch Intervention
if ($_SESSION['role'] === 'admin') {
    $stmt = $db->prepare('
        SELECT i.*, c.nom_societe 
        FROM interventions i 
        JOIN clients c ON i.client_id = c.id 
        WHERE i.id = ?
    ');
    $stmt->execute([$id]);
} else {
    $stmt = $db->prepare('
        SELECT i.*, c.nom_societe 
        FROM interventions i 
        JOIN clients c ON i.client_id = c.id 
        WHERE i.id = ? AND i.technicien_id = ?
    ');
    $stmt->execute([$id, $userId]);
}
$intervention = $stmt->fetch();

if (!$intervention) {
    die("Intervention introuvable ou vous n'avez pas les droits.");
}

// Fetch Tech Sign for Fallback
$stmtT = $db->prepare('SELECT signature_base64 FROM users WHERE id = ?');
$stmtT->execute([$intervention['technicien_id']]);
$techSignatureBase64 = $stmtT->fetchColumn() ?: '';

// Actions Make/Edit Machine
$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    verifyCsrfToken();

    if ($_POST['action'] === 'ajouter_machine') {
        $of = trim($_POST['numero_of'] ?? '');
        $designation = trim($_POST['designation'] ?? '');
        $annee = trim($_POST['annee_fabrication'] ?? '');
        $repere = trim($_POST['repere'] ?? '');

        if (empty($of) || empty($designation) || empty($annee)) {
            $message = "Le N° O.F., la désignation et l'année sont obligatoires.";
        } else {
            $initMesures = json_encode(['repere' => $repere]);
            $stmtIns = $db->prepare('INSERT INTO machines (intervention_id, numero_of, designation, annee_fabrication, commentaires, mesures, donnees_controle, dysfonctionnements, conclusion) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
            $stmtIns->execute([$id, $of, $designation, $annee, '', $initMesures, '{}', '', '']);
            $message = "Machine ajoutée avec succès.";
        }
    } elseif ($_POST['action'] === 'save_signatures') {
        $sigClient = $_POST['sigClient'] ?? null;
        $sigTech = $_POST['sigTech'] ?? null;
        $nomClient = $_POST['nomClient'] ?? null;

        $db->prepare('UPDATE interventions SET signature_client = ?, signature_technicien = ?, nom_signataire_client = ?, statut = ? WHERE id = ?')
            ->execute([$sigClient, $sigTech, $nomClient, 'Terminee', $id]);

        // redirect based on role
        if ($_SESSION['role'] === 'admin') {
            header("Location: admin.php?msg=saved");
        } else {
            header("Location: technicien.php?msg=saved");
        }
        exit;
    } elseif ($_POST['action'] === 'import_machines') {
        $lastIntId = $_POST['last_intervention_id'] ?? null;
        if ($lastIntId) {
            $stmtPrev = $db->prepare('SELECT designation, numero_of, annee_fabrication, mesures FROM machines WHERE intervention_id = ?');
            $stmtPrev->execute([$lastIntId]);
            $prevMachines = $stmtPrev->fetchAll();

            foreach ($prevMachines as $pm) {
                $mes = json_decode($pm['mesures'] ?? '{}', true);
                $initMesures = json_encode(['repere' => $mes['repere'] ?? '']);
                $stmtIns = $db->prepare('INSERT INTO machines (intervention_id, numero_of, designation, annee_fabrication, commentaires, mesures, donnees_controle, dysfonctionnements, conclusion) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
                $stmtIns->execute([$id, $pm['numero_of'], $pm['designation'], $pm['annee_fabrication'], '', $initMesures, '{}', '', '']);
            }
            $message = count($prevMachines) . " machines importées.";
            header("Location: intervention_edit.php?id=$id&msg=imported");
            exit;
        }
    }
}

// Fetch Pre-existing machines for this client (for suggestions)
$stmtLastInt = $db->prepare('SELECT id, date_intervention FROM interventions WHERE client_id = ? AND id != ? ORDER BY date_intervention DESC, id DESC LIMIT 1');
$stmtLastInt->execute([$intervention['client_id'], $id]);
$lastIntervention = $stmtLastInt->fetch();
$lastMachines = [];
if ($lastIntervention) {
    $stmtLM = $db->prepare('SELECT designation, numero_of FROM machines WHERE intervention_id = ?');
    $stmtLM->execute([$lastIntervention['id']]);
    $lastMachines = $stmtLM->fetchAll();
}

// Fetch Machines
$stmtMach = $db->prepare('SELECT * FROM machines WHERE intervention_id = ? ORDER BY id DESC');
$stmtMach->execute([$id]);
$machines = $stmtMach->fetchAll();

?>
<!DOCTYPE html>
<html lang="fr">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0">
    <title>Edition Intervention
        <?= htmlspecialchars($intervention['numero_arc']) ?>
    </title>
    <link rel="stylesheet" href="/assets/style.css">
    <style>
        .machine-card {
            border: 1px solid var(--glass-border);
            border-left: 4px solid var(--primary);
            padding: 1rem;
            margin-bottom: 1rem;
            border-radius: var(--radius-md);
            background: rgba(255, 255, 255, 0.02);
            transition: 0.3s;
        }

        .machine-card:hover {
            border-left-color: var(--accent-cyan);
            background: var(--primary-subtle);
        }
    </style>
</head>

<body>
    <style>
        .mobile-header { display: flex !important; }
        .main-content { padding-top: calc(var(--mobile-header-height) + 1.5rem) !important; }
    </style>
    <header class="mobile-header">
        <button class="btn btn-ghost" onclick="document.getElementById('modalQuit').style.display='flex'"
            style="padding: 0.4rem 0.8rem; color: var(--error); display:flex; align-items:center; gap:6px; font-weight: 700; font-size: 0.85rem; letter-spacing: 0.5px; border-radius: var(--radius-sm); border: 1px solid rgba(244, 63, 94, 0.2); background: rgba(244, 63, 94, 0.05);">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="m15 18-6-6 6-6"/></svg>
            RETOUR
        </button>
        <span class="mobile-header-title">Fiche ARC</span>
        <span class="mobile-header-user"></span>
    </header>

    <main class="main-content" style="padding-bottom: 6rem;">
        <?php if ($message): ?>
            <script>
                document.addEventListener('DOMContentLoaded', () => {
                    showToast(<?= json_encode($message) ?>, 'success');
                });
            </script>
        <?php endif; ?>

        <!-- INTRO CLIENT -->
        <div class="card glass animate-in" style="margin-bottom: 2rem;">
            <div style="display:flex; justify-content:space-between; align-items:flex-start;">
                <div>
                    <h2 style="color: var(--primary); margin-bottom: 0.5rem;">
                        <?= htmlspecialchars($intervention['nom_societe']) ?>
                    </h2>
                    <p style="color: var(--text-dim); font-size: 0.85rem; margin-bottom: 0.2rem;">
                        <strong>ARC:</strong>
                        <?= htmlspecialchars($intervention['numero_arc']) ?> &nbsp;|&nbsp;
                        <strong>Date:</strong>
                        <?= date('d/m/Y', strtotime($intervention['date_intervention'])) ?>
                    </p>
                    <p style="color: var(--text-dim); font-size: 0.85rem;">
                        <strong>Contact:</strong>
                        <?= htmlspecialchars($intervention['contact_nom']) ?: 'N/A' ?>
                    </p>
                </div>
                <div>
                    <span class="badge"
                        style="background: rgba(255, 179, 0, 0.1); color: var(--primary); padding: 0.4rem 0.8rem; border-radius: 20px; font-weight: bold; font-size: 0.75rem;">
                        <?= htmlspecialchars($intervention['statut']) ?>
                    </span>
                </div>
            </div>
        </div>

        <h3 style="margin-bottom: 1rem; display: flex; align-items: center; justify-content: space-between;">
            Machines Contrôlées
            <?php if (count($machines) > 0): ?>
                <button onclick="document.getElementById('modalNewMachine').style.display='flex'" class="btn btn-ghost"
                    style="font-size: 0.8rem; padding: 0.4rem 0.8rem;">+ Ajouter</button>
            <?php endif; ?>
        </h3>

        <?php if (empty($machines)): ?>
            <div class="card glass"
                style="text-align: center; padding: 2.5rem 1rem; border: 1px dashed var(--glass-border); background: transparent;">
                <div style="margin-bottom: 1.5rem; text-align:center; opacity:0.1;"><img src="/assets/icon_gear_orange.svg" style="height: 80px; width: 80px;"></div>
                <p style="color: var(--text-dim); margin-bottom: 1.5rem;">Aucune machine n'a encore été ajoutée à cette fiche.</p>
                
                <div style="display:flex; flex-direction:column; gap:1rem; max-width:320px; margin:0 auto;">
                    <button onclick="document.getElementById('modalNewMachine').style.display='flex'" class="btn btn-primary">Ajouter manuellement</button>
                    
                    <?php if (!empty($lastMachines)): ?>
                        <div style="margin-top:1rem; padding-top:1rem; border-top: 1px solid var(--glass-border);">
                            <p style="font-size:0.75rem; color:var(--primary); margin-bottom:0.75rem; font-weight:600;">
                                <img src="/assets/icon_history_white.svg" style="height:14px; vertical-align:middle; margin-right:4px;"> 
                                Suggestion : <?= count($lastMachines) ?> machines trouvées le <?= date('d/m/Y', strtotime($lastIntervention['date_intervention'])) ?>
                            </p>
                            <form method="POST">
                                <?= csrfField() ?>
                                <input type="hidden" name="action" value="import_machines">
                                <input type="hidden" name="last_intervention_id" value="<?= $lastIntervention['id'] ?>">
                                <button type="submit" class="btn btn-ghost" style="width:100%; font-size:0.8rem; border-color:var(--primary-glow);">
                                    Ré-importer le parc machine
                                </button>
                            </form>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php else: ?>
            <!-- PARC MACHINE TABLE -->
            <div class="card glass" style="padding: 0; overflow-x: auto;" id="machinesList">
                <table style="width:100%; border-collapse:collapse; font-size:0.85rem; min-width:600px;">
                    <thead>
                        <tr style="background: rgba(255,179,0,0.1); text-align:left;">
                            <th
                                style="padding:0.7rem 0.8rem; font-size:0.7rem; text-transform:uppercase; color:var(--primary); font-weight:700; white-space:nowrap;">
                                N° ARC</th>
                            <th
                                style="padding:0.7rem 0.8rem; font-size:0.7rem; text-transform:uppercase; color:var(--primary); font-weight:700;">
                                N° OF</th>
                            <th
                                style="padding:0.7rem 0.8rem; font-size:0.7rem; text-transform:uppercase; color:var(--primary); font-weight:700;">
                                Désignation</th>
                            <th
                                style="padding:0.7rem 0.8rem; font-size:0.7rem; text-transform:uppercase; color:var(--primary); font-weight:700;">
                                Repère</th>
                            <th
                                style="padding:0.7rem 0.8rem; font-size:0.7rem; text-transform:uppercase; color:var(--primary); font-weight:700; text-align:center;">
                                Année</th>
                            <th
                                style="padding:0.7rem 0.8rem; font-size:0.7rem; text-transform:uppercase; color:var(--primary); font-weight:700; text-align:center;">
                                Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($machines as $m):
                            $mMesures = json_decode($m['mesures'] ?? '{}', true);
                            ?>
                            <tr id="machine-card-<?= $m['id'] ?>"
                                style="border-top: 1px solid var(--glass-border); cursor:pointer; transition: all 0.3s ease;"
                                onclick="window.location.href='machine_edit.php?id=<?= $m['id'] ?>';"
                                onmouseover="this.style.background='rgba(255,179,0,0.05)'"
                                onmouseout="this.style.background=''">
                                <td
                                    style="padding:0.6rem 0.8rem; color:var(--text-dim); font-family:monospace; white-space:nowrap;">
                                    <?= htmlspecialchars($intervention['numero_arc']) ?></td>
                                <td style="padding:0.6rem 0.8rem; white-space:nowrap;">
                                    <?= htmlspecialchars($m['numero_of'] ?: '—') ?></td>
                                <td style="padding:0.6rem 0.8rem; font-weight:600; <?php 
                                    $cleanDesig = strtoupper(str_replace('*', '', $m['designation']));
                                    echo (stripos($cleanDesig, 'FIXE RD') !== false) ? 'color: #ffb300;' : 'color: #fff;'; 
                                ?>">
                                    <?= htmlspecialchars($cleanDesig) ?>
                                </td>
                                <td style="padding:0.6rem 0.8rem;"><?= htmlspecialchars($mMesures['repere'] ?? '—') ?></td>
                                <td style="padding:0.6rem 0.8rem; text-align:center;">
                                    <?= htmlspecialchars($m['annee_fabrication'] ?: '—') ?></td>
                                <td style="padding:0.6rem 0.8rem; white-space:nowrap;" onclick="event.stopPropagation();">
                                    <div style="display: grid; grid-template-columns: 36px 36px 36px; gap: 8px; justify-content: center; margin: 0 auto; width: max-content;">
                                        <div>
                                            <!-- Réservé pour le "Voir" potentiel du Dashboard pour garder l'alignement parfait -->
                                        </div>
                                        <div>
                                            <a href="machine_edit.php?id=<?= $m['id'] ?>" class="btn btn-ghost"
                                                style="width:36px; height:36px; padding:0; display:flex; align-items:center; justify-content:center;" title="Éditer">
                                                <img src="/assets/icon_edit_orange.svg" style="height: 18px; width: 18px;">
                                            </a>
                                        </div>
                                        <div>
                                            <button onclick="deleteMachine(<?= $m['id'] ?>, this);" class="btn btn-ghost"
                                                style="width:36px; height:36px; padding:0; display:flex; align-items:center; justify-content:center; color: var(--error);"
                                                title="Supprimer">
                                                <img src="/assets/icon_delete_red.svg" style="height: 18px; width: 18px;">
                                            </button>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- Bouton pour Finaliser -->
            <a href="rapport_final.php?id=<?= $id ?>" class="btn btn-primary"
                style="display:flex; align-items:center; justify-content:center; gap:8px; width:100%; margin-top: 2rem; padding: 1rem; font-size: 1rem; background: linear-gradient(135deg, #10b981, #059669); color: #fff; font-weight: bold; border:none; text-align:center; text-decoration:none; border-radius:12px;">
                <img src="/assets/icon_check_white.svg" style="height: 20px; width: 20px;"> Finaliser le Rapport
            </a>
            <button onclick="document.getElementById('modalQuit').style.display='flex'" class="btn btn-ghost"
                style="width:100%; margin-top: 1rem; padding: 1rem; font-size: 1rem; color: var(--error); border: 1px solid rgba(244, 63, 94, 0.3); display:flex; align-items:center; justify-content:center; gap:6px;">
                <img src="/assets/icon_close_red.svg" style="height: 18px; width: 18px;"> Quitter sans finaliser
            </button>
        <?php endif; ?>

    </main>

    <!-- MODAL NEW MACHINE -->
    <div id="modalNewMachine"
        style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; z-index:9999; background:rgba(0,0,0,0.8); align-items:center; justify-content:center; backdrop-filter:blur(5px);">
        <div class="card glass animate-in" style="width: 100%; max-width: 420px; padding: 2rem; position: relative;">
            <button onclick="document.getElementById('modalNewMachine').style.display='none'"
                style="position:absolute; top:1rem; right:1.5rem; background:none; border:none; color:var(--text-dim); font-size:1.5rem; cursor:pointer;">&times;</button>
            <h3 style="margin-bottom: 1.5rem;">Ajouter une Machine</h3>
            <form method="POST" autocomplete="off">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="ajouter_machine">

                <div style="display:flex; gap:0.75rem; margin-bottom:1rem;">
                    <div class="form-group" style="flex:1; margin-bottom:0;">
                        <label class="label" style="font-size:0.7rem;">N° A.R.C.</label>
                        <input type="text" class="input" value="<?= htmlspecialchars($intervention['numero_arc']) ?>"
                            disabled style="font-family:monospace; opacity:0.7; font-size:0.9rem;">
                    </div>
                    <div class="form-group" style="flex:1; margin-bottom:0;">
                        <label class="label" style="font-size:0.7rem;">N° OF <span style="color:var(--error);">*</span></label>
                        <input type="text" name="numero_of" class="input" placeholder="OF-1234" required>
                    </div>
                </div>

                <div class="form-group">
                    <label class="label">Type de Fiche / Désignation <span style="color:var(--error);">*</span></label>
                    <select name="designation" class="input" required style="background: rgba(15, 23, 42, 0.6);">
                        <option value="">— Choisir le type —</option>
                        <option value="OVERBAND OVAP (PERMANENT)">OVERBAND OVAP (PERMANENT)</option>
                        <option value="OVERBAND OV (ELECTROMAGNÉTIQUE)">OVERBAND OV (ELECTROMAGNÉTIQUE)</option>
                        <option value="AIMANT FIXE APRF (PERMANENT)">AIMANT FIXE APRF (PERMANENT)</option>
                        <option value="SÉPARATEUR À COURANTS DE FOUCAULT ED-X">SÉPARATEUR À COURANTS DE FOUCAULT ED-X</option>
                        <option value="ELECTROAIMANT FIXE RD">ELECTROAIMANT FIXE RD</option>
                        <option value="TAMBOUR OU POULIE (TAP/PAP)">TAMBOUR OU POULIE (TAP/PAP)</option>
                        <option value="ÉLECTROAIMANTS DE LEVAGE">ÉLECTROAIMANTS DE LEVAGE</option>
                        <option value="SÉPARATEUR À GRILLES AUTOMATIQUE SGA">SÉPARATEUR À GRILLES AUTOMATIQUE SGA</option>
                        <option value="SÉPARATEUR À GRILLE SGCP/SGCM">SÉPARATEUR À GRILLE SGCP/SGCM</option>
                        <option value="SÉPARATEUR À GRILLE SEMI-AUTOMATIQUE SGSA">SÉPARATEUR À GRILLE SEMI-AUTOMATIQUE SGSA</option>
                        <option value="PLAQUES MAGNÉTIQUES PM">PLAQUES MAGNÉTIQUES PM</option>
                    </select>
                </div>

                <div style="display:flex; gap:0.75rem; margin-bottom:1rem;">
                    <div class="form-group" style="flex:1; margin-bottom:0;">
                        <label class="label" style="font-size:0.7rem;">Repère</label>
                        <input type="text" name="repere" class="input" placeholder="ex: SEP-01">
                    </div>
                    <div class="form-group" style="flex:1; margin-bottom:0;">
                        <label class="label" style="font-size:0.7rem;">Année de mise en service <span style="color:var(--error);">*</span></label>
                        <input type="number" name="annee_fabrication" class="input" placeholder="<?= date('Y') ?>"
                            min="1900" max="<?= date('Y') + 1 ?>" required pattern="[0-9]{4}">
                    </div>
                </div>

                <button type="submit" class="btn btn-primary" style="width: 100%; margin-top:0.5rem;">Ajouter cette
                    machine</button>
            </form>
        </div>
    </div>

    <!-- MODAL SIGNATURE (PWA Style) -->
    <div id="modalSignature"
        style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; z-index:9999; background:rgba(0,0,0,0.8); align-items:center; justify-content:center; backdrop-filter:blur(5px); overflow-y: auto;">
        <div class="card glass animate-in"
            style="width: 100%; max-width: 500px; padding: 2rem; position: relative; margin: auto;">
            <button onclick="document.getElementById('modalSignature').style.display='none'"
                style="position:absolute; top:1rem; right:1.5rem; background:none; border:none; color:var(--text-dim); font-size:1.5rem; cursor:pointer;">&times;</button>
            <h3 style="margin-bottom: 0.5rem; color: var(--accent-cyan);">Validation</h3>
            <p style="font-size: 0.8rem; color: var(--text-dim); margin-bottom: 1.5rem;">Faites signer le client pour
                valider l'intervention.</p>

            <form method="POST" autocomplete="off">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="save_signatures">

                <div class="form-group">
                    <label class="label">Nom du Signataire (Client)</label>
                    <input type="text" name="nomClient" class="input" placeholder="Nom et prénom..."
                        value="<?= htmlspecialchars($intervention['nom_signataire_client'] ?? '') ?>" required>
                </div>

                <div class="form-group">
                    <label class="label" style="display:flex; justify-content:space-between;">
                        <span>Signature Client</span>
                        <span onclick="padClient.clear()"
                            style="cursor:pointer; color:var(--accent-cyan); font-size:0.75rem;">Effacer</span>
                    </label>
                    <canvas id="canvasClient" width="400" height="200"
                        style="background:#fff; border-radius:8px; width:100%; cursor:crosshair; touch-action:none;"></canvas>
                    <input type="hidden" name="sigClient" id="sigClientInput" required>
                </div>

                <div class="form-group">
                    <label class="label" style="display:flex; justify-content:space-between;">
                        <span>Votre Signature (Technicien)</span>
                        <span onclick="padTech.clear()"
                            style="cursor:pointer; color:var(--accent-cyan); font-size:0.75rem;">Effacer</span>
                    </label>
                    <canvas id="canvasTech" width="400" height="200"
                        style="background:#fff; border-radius:8px; width:100%; cursor:crosshair; touch-action:none;"></canvas>
                    <input type="hidden" name="sigTech" id="sigTechInput" value="<?= htmlspecialchars($intervention['signature_technicien'] ?: $techSignatureBase64) ?>" required>
                </div>

                <button type="submit" onclick="savePads()" class="btn btn-primary"
                    style="width: 100%; margin-top:1rem;">Valider Définitivement</button>
            </form>
        </div>
    </div>

    <!-- MODAL QUIT -->
    <div id="modalQuit"
        style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; z-index:9999; background:rgba(0,0,0,0.8); align-items:center; justify-content:center; backdrop-filter:blur(5px);">
        <div class="card glass animate-in"
            style="width: 100%; max-width: 400px; padding: 2rem; position: relative; border-color: rgba(244,63,94,0.3);">
            <button onclick="document.getElementById('modalQuit').style.display='none'"
                style="position:absolute; top:1rem; right:1.5rem; background:none; border:none; color:var(--text-dim); font-size:1.5rem; cursor:pointer;">&times;</button>
            <h3 style="margin-bottom: 1rem; color: var(--error);">Quitter la fiche ?</h3>
            <p style="color: var(--text-dim); margin-bottom: 2rem; font-size: 0.9rem;">Êtes-vous sûr de vouloir quitter
                cette fiche d'expertise ?<br><br>Les machines déjà renseignées seront sauvegardées, mais l'intervention
                n'est pas encore terminée (signature manquante).</p>
            <div style="display:flex; gap:1rem;">
                <button type="button" class="btn btn-ghost" style="flex:1;"
                    onclick="document.getElementById('modalQuit').style.display='none'">Annuler</button>
                <button type="button" class="btn"
                    style="flex:1; background: var(--error); color: #fff; border: none; font-weight: bold;"
                    onclick="window.location.href='<?= $_SESSION['role'] === 'admin' ? 'admin.php' : 'technicien.php' ?>'">Oui,
                    Quitter</button>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/signature_pad@4.1.7/dist/signature_pad.umd.min.js"></script>
    <script>
        let padClient, padTech;

        function resizeCanvas(canvas) {
            const ratio = Math.max(window.devicePixelRatio || 1, 1);
            // On fixe une hauteur de 200px (la même partout)
            canvas.style.height = "200px";
            canvas.width = canvas.offsetWidth * ratio;
            canvas.height = 200 * ratio; // Fix height as offsetHeight might be flawed
            canvas.getContext("2d").scale(ratio, ratio);
        }

        function openSignatureModal() {
            document.getElementById('modalSignature').style.display = 'flex';

            // On initialise les canvas MAINTENANT que la modale est visible
            setTimeout(() => {
                const canvasC = document.getElementById('canvasClient');
                const canvasT = document.getElementById('canvasTech');

                if (canvasC && canvasT && window.SignaturePad) {
                    const dpr = Math.max(window.devicePixelRatio || 1, 1);
                    // Ne redimensionne et n'initialise que si ce n'est pas déjà fait
                    if (!padClient) {
                        resizeCanvas(canvasC);
                        padClient = new SignaturePad(canvasC, { penColor: "blue" });
                        <?php if (!empty($intervention['signature_client'])): ?>
                            padClient.fromDataURL('<?= $intervention['signature_client'] ?>', { ratio: dpr, width: canvasC.width / dpr, height: canvasC.height / dpr });
                        <?php endif; ?>
                    }
                    if (!padTech) {
                        resizeCanvas(canvasT);
                        padTech = new SignaturePad(canvasT, { penColor: "black" });
                        const sigTechVal = document.getElementById('sigTechInput').value;
                        if (sigTechVal) {
                            padTech.fromDataURL(sigTechVal, { ratio: dpr, width: canvasT.width / dpr, height: canvasT.height / dpr });
                        }
                    }
                }
            }, 50); // Petit délai pour laisser le navigateur dessiner la modale
        }

        function savePads() {
            if (!padClient || !padTech || padClient.isEmpty() || padTech.isEmpty()) {
                alert("Veuillez remplir les deux signatures.");
                event.preventDefault();
                return;
            }
            document.getElementById('sigClientInput').value = padClient.toDataURL();
            document.getElementById('sigTechInput').value = padTech.toDataURL();
        }

        function deleteMachine(machineId, btn) {
            if (!confirm('Supprimer cet équipement ?')) return;

            // CSRF protection included in URL
            const csrfToken = document.querySelector('input[name="csrf_token"]').value;
            fetch('delete_machine.php?id=' + machineId + '&csrf_token=' + encodeURIComponent(csrfToken), {
                method: 'GET',
                credentials: 'same-origin'
            }).then(function () {
                var row = document.getElementById('machine-card-' + machineId);
                if (row) {
                    row.style.opacity = '0';
                    row.style.transition = 'opacity 0.3s ease';
                    setTimeout(function () {
                        row.remove();
                        // If no machines remain in tbody, reload to show empty state
                        var tbody = document.querySelector('#machinesList tbody');
                        if (tbody && tbody.children.length === 0) {
                            location.reload();
                        }
                    }, 350);
                }
            }).catch(function () {
                alert('Erreur lors de la suppression.');
            });
        }
    </script>
    <script src="/assets/toast.js"></script>
</body>

</html>