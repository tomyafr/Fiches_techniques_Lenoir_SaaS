<?php
require_once __DIR__ . '/../includes/config.php';
requireAuth('technicien');

$db = getDB();
$userId = $_SESSION['user_id'];
$id = $_GET['id'] ?? null;

if (!$id) {
    header('Location: technicien.php');
    exit;
}

// Fetch Machine and associated Intervention
$stmt = $db->prepare('
    SELECT m.*, i.numero_arc, i.technicien_id, c.nom_societe 
    FROM machines m 
    JOIN interventions i ON m.intervention_id = i.id 
    JOIN clients c ON i.client_id = c.id
    WHERE m.id = ? AND i.technicien_id = ?
');
$stmt->execute([$id, $userId]);
$machine = $stmt->fetch();

if (!$machine) {
    die("Machine introuvable ou accès refusé.");
}

$donnees = json_decode($machine['donnees_controle'] ?? '{}', true);
$mesures = json_decode($machine['mesures'] ?? '{}', true);

// Save form
$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    verifyCsrfToken();

    if ($_POST['action'] === 'save_machine') {
        $of = trim($_POST['numero_of'] ?? '');
        $commentaire = trim($_POST['commentaires'] ?? '');

        $postDonnees = $_POST['donnees'] ?? [];
        $postMesures = $_POST['mesures'] ?? [];

        $db->prepare('UPDATE machines SET numero_of = ?, commentaires = ?, donnees_controle = ?, mesures = ? WHERE id = ?')
            ->execute([$of, $commentaire, json_encode($postDonnees), json_encode($postMesures), $id]);

        // Retour à l'intervention
        header('Location: intervention_edit.php?id=' . $machine['intervention_id'] . '&msg=saved');
        exit;
    }
}

// Define the schema simply mapping to the docs
$schemaGlobal = [
    "Examen d'ensemble" => ["Fixation de l'appareil", "Appareil sale", "Usure importante", "Racleur d'évacuation ok (si présent)"],
    "Transmission / Motorisation" => ["Tension courroies ou chaînes", "Alignement pignons / poulies", "Graissage chaîne", "Niveau d'huile réducteur", "Echauffement palier/moteur", "Bruit suspect"],
    "Bande" => ["Tension de la bande", "Déport de la bande", "Etat surface inférieure", "Etat surface supérieure", "Bandes collées sur tassaux", "Usure des tasseaux"],
    "Armoire / Coffret Electrique" => ["Etat général du coffret", "Test déclenchement défauts", "Bouton d'arrêt d'urgence", "Température interne"],
    "Séparateurs de Métaux Magnétiques" => ["Tension d'alimentation", "Tension d'excitation", "Valeur d'isolement par rapport à la masse", "Valeur inductive (Gauss)"]
];
?>
<!DOCTYPE html>
<html lang="fr">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0">
    <title>Edition Machine
        <?= htmlspecialchars($machine['designation']) ?>
    </title>
    <link rel="stylesheet" href="/assets/style.css">
</head>

<body>
    <header class="mobile-header">
        <button class="btn btn-ghost"
            onclick="window.location.href='intervention_edit.php?id=<?= $machine['intervention_id'] ?>'"
            style="padding: 0.5rem; font-size: 1.2rem;">
            ←
        </button>
        <span class="mobile-header-title" style="font-size: 0.9rem;">
            <?= htmlspecialchars($machine['designation']) ?>
        </span>
    </header>

    <main class="main-content" style="padding-top: 5rem; padding-bottom: 6rem;">
        <div class="card glass" style="margin-bottom: 2rem;">
            <div style="display:flex; justify-content:space-between; align-items:flex-start;">
                <div>
                    <h2 style="color: var(--primary); margin-bottom: 0.5rem; font-size:1.1rem;">
                        <?= htmlspecialchars($machine['nom_societe']) ?>
                    </h2>
                    <p style="color: var(--text-dim); font-size: 0.85rem; margin-bottom: 0.2rem;">
                        <strong>ARC:</strong>
                        <?= htmlspecialchars($machine['numero_arc']) ?>
                    </p>
                </div>
            </div>
        </div>

        <form method="POST">
            <input type="hidden" name="action" value="save_machine">
            <?= csrfField() ?>

            <div class="card glass animate-in" style="margin-bottom: 2rem;">
                <h3 style="margin-bottom: 1rem; color: var(--accent-cyan); font-size:1rem;">Informations Générales</h3>
                <div class="form-group">
                    <label class="label">Désignation (Type)</label>
                    <input type="text" disabled class="input" value="<?= htmlspecialchars($machine['designation']) ?>"
                        style="background: rgba(255,255,255,0.05); color:var(--text-dim) !important;">
                </div>
                <div class="form-group">
                    <label class="label">Numéro OF (Ordre de Fabrication)</label>
                    <input type="text" name="numero_of" class="input"
                        value="<?= htmlspecialchars($machine['numero_of']) ?>" placeholder="Ex: OF-1234">
                </div>
            </div>

            <!-- Dynamically generate checklists based on document parts -->
            <?php foreach ($schemaGlobal as $section => $points): ?>
                <div class="card glass animate-in" style="margin-bottom: 1.5rem;">
                    <h3
                        style="margin-bottom: 1rem; color: var(--primary); font-size: 0.95rem; text-transform:uppercase; border-bottom: 1px solid var(--glass-border); padding-bottom: 0.5rem;">
                        <?= htmlspecialchars($section) ?>
                    </h3>

                    <div style="display: flex; flex-direction: column; gap: 0.75rem;">
                        <!-- Headers for radio buttons -->
                        <div
                            style="display:flex; justify-content:space-between; font-size: 0.7rem; color: var(--text-dim); text-transform: uppercase;">
                            <span style="flex:1;">Point de contrôle</span>
                            <div style="display:flex; gap:1rem; width: 120px; text-align:center;">
                                <span style="flex:1;">C</span>
                                <span style="flex:1;">NC</span>
                                <span style="flex:1;">N/A</span>
                            </div>
                        </div>

                        <?php foreach ($points as $idx => $pointName):
                            $key = "sec_" . md5($section) . "_pt_" . $idx;
                            $val = $donnees[$key] ?? 'na';
                            ?>
                            <div
                                style="display:flex; justify-content:space-between; align-items:center;  border-bottom: 1px dashed rgba(255,255,255,0.05); padding-bottom: 0.4rem;">
                                <span style="flex:1; font-size: 0.85rem;">
                                    <?= htmlspecialchars($pointName) ?>
                                </span>
                                <div style="display:flex; gap:0.5rem; width: 120px; justify-content:space-between;">
                                    <!-- Correct => C -->
                                    <label style="display:flex; align-items:center; cursor:pointer;" title="Correct">
                                        <input type="radio" name="donnees[<?= $key ?>]" value="c" <?= $val === 'c' ? 'checked' : '' ?>
                                        style="width:1.2rem; height:1.2rem; margin:auto;">
                                    </label>
                                    <!-- Non Correct => NC -->
                                    <label style="display:flex; align-items:center; cursor:pointer;" title="Non Correct">
                                        <input type="radio" name="donnees[<?= $key ?>]" value="nc" <?= $val === 'nc' ? 'checked' : '' ?> style="width:1.2rem; height:1.2rem; margin:auto;">
                                    </label>
                                    <!-- Non Applicable => N/A -->
                                    <label style="display:flex; align-items:center; cursor:pointer;" title="Non Applicable">
                                        <input type="radio" name="donnees[<?= $key ?>]" value="na" <?= $val === 'na' ? 'checked' : '' ?> style="width:1.2rem; height:1.2rem; margin:auto;">
                                    </label>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endforeach; ?>

            <div class="card glass animate-in" style="margin-bottom: 1.5rem;">
                <h3 style="margin-bottom: 1rem; color: var(--accent-cyan); font-size:1rem;">Mesures Importantes</h3>
                <div class="form-group" style="margin-bottom: 1rem;">
                    <label class="label">Valeur Inductive Mesurée (Gauss)</label>
                    <input type="text" name="mesures[gauss]" class="input"
                        value="<?= htmlspecialchars($mesures['gauss'] ?? '') ?>" placeholder="Ex: 50 M">
                </div>
                <div class="form-group" style="margin-bottom: 1rem;">
                    <label class="label">Valeur d'isolement</label>
                    <input type="text" name="mesures[isolement]" class="input"
                        value="<?= htmlspecialchars($mesures['isolement'] ?? '') ?>" placeholder="Ex: >500 Mohms">
                </div>
            </div>

            <div class="card glass animate-in" style="margin-bottom: 2rem;">
                <h3 style="margin-bottom: 1rem; color: var(--accent-cyan); font-size:1rem;">Commentaires / Observations
                </h3>
                <div class="form-group" style="margin-bottom: 0;">
                    <textarea name="commentaires" class="input" style="height: 120px; resize:vertical; padding: 1rem;"
                        placeholder="Notes techniques, suggestions, pièces de rechange à proposer..."><?= htmlspecialchars($machine['commentaires'] ?? '') ?></textarea>
                </div>
            </div>

            <div
                style="position: sticky; bottom: 1rem; background: var(--main-bg); padding: 1rem; border-radius: 12px; border: 1px solid var(--primary-subtle); box-shadow: 0 -4px 20px rgba(0,0,0,0.5); z-index:100;">
                <button type="submit" class="btn btn-primary"
                    style="width: 100%; height: 3.5rem; font-size: 1.1rem; box-shadow: 0 4px 15px rgba(255, 179, 0, 0.4);">
                    Sauvegarder la Fiche ✓
                </button>
            </div>
        </form>

    </main>

    <div class="app-footer">
        Raoul Lenoir SAS ·
        <?= date('Y') ?>
    </div>
</body>

</html>