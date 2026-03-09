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

// Actions Make/Edit Machine
$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    verifyCsrfToken();

    if ($_POST['action'] === 'ajouter_machine') {
        $of = trim($_POST['numero_of'] ?? '');
        $designation = trim($_POST['designation'] ?? '');
        $annee = trim($_POST['annee_fabrication'] ?? '');

        $stmtIns = $db->prepare('INSERT INTO machines (intervention_id, numero_of, designation, annee_fabrication, commentaires, mesures, donnees_controle) VALUES (?, ?, ?, ?, ?, ?, ?)');
        $stmtIns->execute([$id, $of, $designation, $annee, '', '{}', '{}']);
        $message = "Machine ajoutée avec succès.";
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
    }
}

// Fetch Machines
$stmtMach = $db->prepare('SELECT * FROM machines WHERE intervention_id = ? ORDER BY id ASC');
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
    <header class="mobile-header">
        <button class="btn btn-ghost"
            onclick="window.location.href='<?= $_SESSION['role'] === 'admin' ? 'admin.php' : 'technicien.php' ?>'"
            style="padding: 0.5rem;">
            ← Retour
        </button>
        <span class="mobile-header-title">Fiche ARC</span>
        <span class="mobile-header-user"></span>
    </header>

    <main class="main-content" style="padding-top: 5rem; padding-bottom: 6rem;">
        <?php if ($message): ?>
            <div class="alert alert-success animate-in">
                <span>✓</span>
                <span>
                    <?= htmlspecialchars($message) ?>
                </span>
            </div>
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
                <p style="font-size: 2rem; margin-bottom: 1rem;">⚙️</p>
                <p style="color: var(--text-dim); margin-bottom: 1.5rem;">Aucune machine n'a encore été ajoutée à cette
                    fiche.</p>
                <button onclick="document.getElementById('modalNewMachine').style.display='flex'"
                    class="btn btn-primary">Ajouter la première machine</button>
            </div>
        <?php else: ?>
            <div id="machinesList">
                <?php foreach ($machines as $m): ?>
                    <a href="machine_edit.php?id=<?= $m['id'] ?>" class="machine-card glass"
                        style="display: block; text-decoration: none; color: inherit;">
                        <div style="display:flex; justify-content:space-between; align-items:center;">
                            <div>
                                <h4 style="margin: 0 0 0.3rem 0; font-size: 1.05rem;">
                                    <?= htmlspecialchars($m['designation']) ?>
                                </h4>
                                <p style="margin: 0; font-size: 0.8rem; color: var(--text-dim);">OF:
                                    <?= htmlspecialchars($m['numero_of'] ?: 'N/A') ?> &nbsp;|&nbsp; Année:
                                    <?= htmlspecialchars($m['annee_fabrication'] ?: 'N/A') ?>
                                </p>
                            </div>
                            <div style="color: var(--primary); font-weight: bold;">
                                Éditer la fiche →
                            </div>
                        </div>
                    </a>
                <?php endforeach; ?>
            </div>

            <!-- Bouton pour Finaliser -->
            <button onclick="document.getElementById('modalSignature').style.display='flex'" class="btn btn-primary"
                style="width:100%; margin-top: 2rem; padding: 1rem; font-size: 1rem; background: var(--accent-cyan); color: #fff; border:none;">
                Terminer et Signer l'intervention ✓
            </button>
        <?php endif; ?>

    </main>

    <!-- MODAL NEW MACHINE -->
    <div id="modalNewMachine"
        style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; z-index:9999; background:rgba(0,0,0,0.8); align-items:center; justify-content:center; backdrop-filter:blur(5px);">
        <div class="card glass animate-in" style="width: 100%; max-width: 400px; padding: 2rem; position: relative;">
            <button onclick="document.getElementById('modalNewMachine').style.display='none'"
                style="position:absolute; top:1rem; right:1.5rem; background:none; border:none; color:var(--text-dim); font-size:1.5rem; cursor:pointer;">&times;</button>
            <h3 style="margin-bottom: 1.5rem;">Nouvelle Machine</h3>
            <form method="POST">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="ajouter_machine">

                <div class="form-group">
                    <label class="label">Type de Fiche / Désignation</label>
                    <select name="designation" class="input" required style="background: rgba(15, 23, 42, 0.6);">
                        <option value="SÉPARATEUR OV - SÉRIE 30">SÉPARATEUR OV - SÉRIE 30</option>
                        <option value="SÉPARATEUR OVERBAND OV (AUTRES SÉRIES)">OVERBAND OV (AUTRES SÉRIES)</option>
                        <option value="SÉPARATEURS APRF-APRM">SÉPARATEURS APRF-APRM</option>
                        <option value="SGA - GA - EXTRA - ÉTROIT SGA">SGA - GA - EXTRA</option>
                        <option value="SGSA">SGSA</option>
                        <option value="ED-X">SÉPARATEUR ED-X</option>
                        <option value="TAMBOUR ROTATIF">TAMBOUR ROTATIF</option>
                        <option value="TUBULAIRES">TUBULAIRES</option>
                    </select>
                </div>

                <div class="form-group">
                    <label class="label">Numéro OF (Optionnel)</label>
                    <input type="text" name="numero_of" class="input" placeholder="ex: OF-1234">
                </div>

                <div class="form-group">
                    <label class="label">Année de fabrication</label>
                    <input type="number" name="annee_fabrication" class="input" placeholder="2024" min="1950"
                        max="<?= date('Y') ?>">
                </div>

                <button type="submit" class="btn btn-primary" style="width: 100%;">Ajouter cette machine</button>
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

            <form method="POST">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="save_signatures">

                <div class="form-group">
                    <label class="label">Nom du Signataire (Client)</label>
                    <input type="text" name="nomClient" class="input" placeholder="Nom et prénom..." required>
                </div>

                <div class="form-group">
                    <label class="label" style="display:flex; justify-content:space-between;">
                        <span>Signature Client</span>
                        <span onclick="padClient.clear()"
                            style="cursor:pointer; color:var(--accent-cyan); font-size:0.75rem;">Effacer</span>
                    </label>
                    <canvas id="canvasClient" width="400" height="200"
                        style="background:#fff; border-radius:8px; width:100%; cursor:crosshair;"></canvas>
                    <input type="hidden" name="sigClient" id="sigClientInput" required>
                </div>

                <div class="form-group">
                    <label class="label" style="display:flex; justify-content:space-between;">
                        <span>Votre Signature (Technicien)</span>
                        <span onclick="padTech.clear()"
                            style="cursor:pointer; color:var(--accent-cyan); font-size:0.75rem;">Effacer</span>
                    </label>
                    <canvas id="canvasTech" width="400" height="200"
                        style="background:#fff; border-radius:8px; width:100%; cursor:crosshair;"></canvas>
                    <input type="hidden" name="sigTech" id="sigTechInput" required>
                </div>

                <button type="submit" onclick="savePads()" class="btn btn-primary"
                    style="width: 100%; margin-top:1rem;">Valider Définitivement</button>
            </form>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/signature_pad@4.1.7/dist/signature_pad.umd.min.js"></script>
    <script>
        let padClient, padTech;
        setTimeout(() => {
            const canvasC = document.getElementById('canvasClient');
            const canvasT = document.getElementById('canvasTech');
            if (canvasC && canvasT && window.SignaturePad) {
                function resizeCanvas(canvas) {
                    const ratio = Math.max(window.devicePixelRatio || 1, 1);
                    canvas.width = canvas.offsetWidth * ratio;
                    canvas.height = canvas.offsetHeight * ratio;
                    canvas.getContext("2d").scale(ratio, ratio);
                }
                resizeCanvas(canvasC); resizeCanvas(canvasT);
                padClient = new SignaturePad(canvasC, { penColor: "blue" });
                padTech = new SignaturePad(canvasT, { penColor: "black" });
            }
        }, 500);

        function savePads() {
            if (padClient.isEmpty() || padTech.isEmpty()) {
                alert("Veuillez remplir les deux signatures.");
                event.preventDefault();
                return;
            }
            document.getElementById('sigClientInput').value = padClient.toDataURL();
            document.getElementById('sigTechInput').value = padTech.toDataURL();
        }
    </script>
</body>

</html>