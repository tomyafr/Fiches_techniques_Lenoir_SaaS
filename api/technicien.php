<?php
require_once __DIR__ . '/../includes/config.php';
requireAuth(['technicien', 'admin']);

$db = getDB();
$userId = $_SESSION['user_id'];
$message = '';
$messageType = '';

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    verifyCsrfToken();

    if ($_POST['action'] === 'nouvelle_intervention') {
        $arc = strtoupper(trim($_POST['numero_arc'] ?? ''));
        $clientNom = trim($_POST['nom_societe'] ?? '');
        $contactNom = trim($_POST['contact_nom'] ?? '');
        $dateInt = $_POST['date_intervention'] ?? date('Y-m-d');

        if (empty($arc) || empty($clientNom)) {
            $message = 'Le numéro ARC et le client sont obligatoires.';
            $messageType = 'error';
        } elseif (strlen($arc) > 15) {
            $message = 'Le numéro ARC ne doit pas dépasser 15 caractères.';
            $messageType = 'error';
        } elseif (!preg_match('/^ARC-[A-Z0-9\-_]+$/i', $arc)) {
            $message = 'Le format du numéro ARC est invalide (doit commencer par ARC-).';
            $messageType = 'error';
        } else {
            try {
                $db->beginTransaction();

                // Create or find client
                $stmtClient = $db->prepare('INSERT INTO clients (nom_societe) VALUES (?) RETURNING id');
                $stmtClient->execute([$clientNom]);
                $clientId = $stmtClient->fetchColumn();

                // Create intervention
                $stmtInt = $db->prepare('INSERT INTO interventions (numero_arc, client_id, technicien_id, contact_nom, date_intervention) VALUES (?, ?, ?, ?, ?) RETURNING id');
                $stmtInt->execute([$arc, $clientId, $userId, $contactNom, $dateInt]);
                $newId = $stmtInt->fetchColumn();

                $db->commit();
                logAudit('INTERVENTION_CREATED', "ARC: $arc");
                header('Location: intervention_edit.php?id=' . $newId);
                exit;
            } catch (PDOException $e) {
                $db->rollBack();
                if ($e->getCode() == 23505) {
                    $message = 'Ce numéro ARC existe déjà.';
                } else {
                    $message = 'Erreur lors de la création.';
                }
                $messageType = 'error';
            }
        }
    }
}

// Fetch interventions
$stmt = $db->prepare('
    SELECT i.*, c.nom_societe 
    FROM interventions i 
    JOIN clients c ON i.client_id = c.id 
    WHERE i.technicien_id = ? 
    ORDER BY i.date_intervention DESC LIMIT 50
');
$stmt->execute([$userId]);
$interventions = $stmt->fetchAll();

$today = date('Y-m-d');
$encours = array_filter($interventions, fn($i) => $i['statut'] === 'Brouillon');
$terminees = array_filter($interventions, fn($i) => in_array($i['statut'], ['Terminee', 'Envoyee']));
?>
<!DOCTYPE html>
<html lang="fr">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0">
    <title>Mes Interventions | LM Expert</title>
    <link rel="stylesheet" href="/assets/style.css">
    <link rel="manifest" href="/manifest.json">
    <link rel="apple-touch-icon" href="/assets/icon-192.png">
    <meta name="theme-color" content="#020617">
    <script>
        if (localStorage.getItem('theme') === 'light') { document.documentElement.classList.add('light-mode'); }
    </script>
</head>

<body>
    <header class="mobile-header">
        <button class="mobile-logo-btn" onclick="toggleSidebar()" aria-label="Menu">
            <img src="/assets/logo-raoul-lenoir.svg" alt="Raoul Lenoir" class="mobile-header-logo"
                style="filter:brightness(0) saturate(100%) invert(73%) sepia(86%) saturate(1063%) hue-rotate(358deg) brightness(101%) contrast(106%);">
        </button>
        <span class="mobile-header-title">Tableau de Bord</span>
        <span class="mobile-header-user">
            <?php if (!empty($_SESSION['avatar'])): ?>
                <img src="<?= htmlspecialchars($_SESSION['avatar']) ?>"
                    style="width: 28px; height: 28px; border-radius: 50%; object-fit: cover; border: 1px solid var(--glass-border);">
            <?php else: ?>
                <?= htmlspecialchars($_SESSION['user_prenom']) ?>
            <?php endif; ?>
        </span>
    </header>
    <div class="sidebar-overlay" id="sidebarOverlay" onclick="toggleSidebar()"></div>

    <div class="dashboard-layout">
        <aside class="sidebar" id="sidebar">
            <button class="sidebar-close-btn" onclick="toggleSidebar()" aria-label="Fermer">&times;</button>
            <div style="margin-bottom: 2.5rem;">
                <a href="technicien.php" class="brand-icon"
                    style="display: block; width: 180px; height: auto; margin: 0 0 1rem 0;">
                    <img src="/assets/logo-raoul-lenoir.svg" alt="Raoul Lenoir">
                </a>
                <h2 style="font-size: 1.15rem;"><span class="text-gradient">Raoul Lenoir</span></h2>
                <p style="font-size: 0.7rem; color: var(--text-dim); text-transform: uppercase;">Espace Technicien</p>
            </div>

            <nav style="display: flex; flex-direction: column; gap: 0.4rem; margin-bottom: 2rem;">
                <button class="btn btn-primary sidebar-link" onclick="switchTab('dashboard')" id="nav-dashboard"
                    style="justify-content: flex-start; padding: 0.7rem 1.1rem; font-size: 0.8rem;">
                    <img src="/assets/icons/dashboard.png" style="height:14px; width:auto; margin-right:8px;"> Mes Interventions
                </button>
                <button class="btn btn-ghost sidebar-link" onclick="switchTab('nouvelle')" id="nav-nouvelle"
                    style="justify-content: flex-start; padding: 0.7rem 1.1rem; font-size: 0.8rem;">
                    <img src="/assets/icons/add.png" style="height:14px; width:auto; margin-right:8px;"> Nouvelle Fiche
                </button>
                <a href="historique.php" class="btn btn-ghost sidebar-link"
                    style="justify-content: flex-start; padding: 0.7rem 1.1rem; font-size: 0.8rem; text-decoration: none; color: inherit;">
                    <img src="/assets/icons/history.png" style="height:14px; width:auto; margin-right:8px;"> Historique
                </a>
            </nav>

            <div style="margin-top: auto; padding-top: 1.5rem; border-top: 1px solid var(--glass-border);">
                <p style="font-size: 0.65rem; color: var(--text-dim); text-transform: uppercase;">Connecté</p>
                <div style="display: flex; align-items: center; gap: 0.75rem; margin-bottom: 1rem;">
                    <p
                        style="font-weight: 600; font-size: 0.85rem; margin: 0; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 180px;">
                        <?= htmlspecialchars($_SESSION['user_prenom'] . ' ' . $_SESSION['user_nom']) ?>
                    </p>
                </div>

                <a href="logout.php" class="btn btn-ghost"
                    style="width: 100%; justify-content: flex-start; padding: 0.7rem 1.1rem; font-size: 0.8rem; color: var(--error); margin-bottom: 0.4rem;">
                    <span>🚪</span> Se déconnecter
                </a>
                <a href="profile.php" class="btn btn-ghost sidebar-link"
                    style="width: 100%; justify-content: flex-start; padding: 0.7rem 1.1rem; font-size: 0.8rem;">
                    <img src="/assets/icons/profile.png" style="height:14px; width:auto; margin-right:8px;"> Mon Profil
                </a>
            </div>
        </aside>

        <main class="main-content">
            <?php if ($message): ?>
                <div class="alert alert-<?= $messageType ?> animate-in">
                    <span><?= $messageType === 'success' ? '✓' : '⚠' ?></span>
                    <span><?= htmlspecialchars($message) ?></span>
                </div>
            <?php endif; ?>

            <div class="stats-grid animate-in">
                <div class="stat-item glass">
                    <span class="stat-label">En cours</span>
                    <span class="stat-value" style="color: var(--accent-cyan);"><?= count($encours) ?></span>
                </div>
                <div class="stat-item glass">
                    <span class="stat-label">Historique</span>
                    <span class="stat-value"><?= count($terminees) ?></span>
                </div>
            </div>

            <!-- Tab Dashboard -->
            <div id="tab-dashboard" class="animate-in">
                <h3
                    style="margin-bottom: 1.25rem; color: var(--text-dim); font-size: 0.8rem; text-transform: uppercase;">
                    Fiches Techniques en cours
                </h3>
                <?php if (empty($encours)): ?>
                    <div class="card glass" style="text-align: center; padding: 4rem 2rem;">
                        <img src="/assets/icons/machine.png" style="height:60px; width:auto; display:block; margin:0 auto 1rem auto; opacity:0.3;">
                        <p style="color: var(--text-dim); font-size: 0.9rem;">Aucune intervention en cours</p>
                    </div>
                <?php else: ?>
                    <div style="display: flex; flex-direction: column; gap: 0.75rem;">
                        <?php foreach ($encours as $i): ?>
                            <a href="intervention_edit.php?id=<?= $i['id'] ?>" class="card glass of-card"
                                style="padding: 1.25rem 1.5rem; display: flex; align-items: center; justify-content: space-between; text-decoration: none; color: inherit;">
                                <div>
                                    <span class="date-badge"><?= date('d/m/Y', strtotime($i['date_intervention'])) ?></span>
                                    <p style="font-weight: 700; font-size: 1rem;"><?= htmlspecialchars($i['nom_societe']) ?></p>
                                    <p style="font-size: 0.8rem; color: var(--text-dim);">ARC:
                                        <?= htmlspecialchars($i['numero_arc']) ?>
                                    </p>
                                </div>
                                <span style="color: var(--primary);">Éditer →</span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <h3
                    style="margin: 2rem 0 1.25rem 0; color: var(--text-dim); font-size: 0.8rem; text-transform: uppercase;">
                    Historique récent
                </h3>
                <?php if (empty($terminees)): ?>
                    <div class="card glass" style="text-align: center; padding: 2rem;">
                        <p style="color: var(--text-dim); font-size: 0.9rem;">Historique vide</p>
                    </div>
                <?php else: ?>
                    <div style="display: flex; flex-direction: column; gap: 0.75rem;">
                        <?php foreach ($terminees as $i): ?>
                            <div class="card glass"
                                style="padding: 1rem 1.5rem; display: flex; justify-content: space-between; align-items:center;">
                                <div>
                                    <p style="font-weight: 700; font-size: 0.95rem; opacity:0.8;">
                                        <?= htmlspecialchars($i['nom_societe']) ?>
                                    </p>
                                    <p style="font-size: 0.7rem; color: var(--text-dim);">ARC:
                                        <?= htmlspecialchars($i['numero_arc']) ?>
                                    </p>
                                </div>
                                <div style="display:flex; align-items:center; gap:0.5rem;">
                                    <?php if ($i['statut'] === 'Terminee'): ?>
                                        <a href="rapport_final.php?id=<?= $i['id'] ?>&msg=ok"
                                            style="font-size:0.75rem; padding:0.3rem 0.6rem; background:rgba(16,185,129,0.1); color:var(--success); border-radius:4px; text-decoration:none; white-space:nowrap;">
                                            📄 Rapport
                                        </a>
                                    <?php endif; ?>
                                    <span
                                        style="font-size: 0.8rem; padding: 0.2rem 0.5rem; background: rgba(16, 185, 129, 0.1); color: var(--success); border-radius: 4px;">
                                        <?= htmlspecialchars($i['statut']) ?>
                                    </span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Tab Nouvelle Fiche -->
            <div id="tab-nouvelle" style="display: none;" class="animate-in">
                <form method="POST" class="card glass" style="margin-bottom: 1.5rem;">
                    <input type="hidden" name="action" value="nouvelle_intervention">
                    <?= csrfField() ?>
                    <h3 style="margin-bottom: 1.5rem; display: flex; align-items: center; gap: 0.75rem;">
                        <img src="/assets/icons/add.png" style="height:20px; width:auto;"> Nouvelle Intervention
                    </h3>

                    <div class="form-group">
                        <label class="label" style="font-size: 1rem; color: var(--primary);">N° A.R.C. <span
                                style="color: var(--error);">*</span></label>
                        <p style="font-size: 0.7rem; color: var(--text-dim); margin: 0 0 0.5rem 0;">Référence centrale
                            de l'intervention – reportée sur toutes les fiches</p>
                        <input type="text" name="numero_arc" class="input" placeholder="ex: ARC-2026-001"
                            style="text-transform: uppercase; font-size: 1.1rem; font-weight: bold; border: 2px solid var(--primary); padding: 1rem;"
                            required autofocus maxlength="15" pattern="ARC-[a-zA-Z0-9\-_]{1,11}" title="Format attendu: ARC-XXXXXXXXXX (max 15 caractères)">
                    </div>

                    <div class="form-group">
                        <label class="label">Client (Société) <span style="color: var(--error);">*</span></label>
                        <input type="text" name="nom_societe" class="input" placeholder="Nom de l'entreprise..."
                            required>
                    </div>

                    <div class="form-group">
                        <label class="label">Contact sur place</label>
                        <input type="text" name="contact_nom" class="input" placeholder="Nom du responsable...">
                    </div>

                    <div class="form-group">
                        <label class="label">Date de l'intervention</label>
                        <input type="date" name="date_intervention" class="input" value="<?= $today ?>" required>
                    </div>

                    <button type="submit" class="btn btn-primary"
                        style="width: 100%; height: 3.5rem; font-size: 0.95rem;">
                        Créer l'Intervention & Ajouter les Machines →
                    </button>
                </form>
            </div>

            <div class="app-footer">
                Raoul Lenoir SAS · <?= date('Y') ?>
            </div>
        </main>
    </div>

    <!-- Mobile Bottom Nav -->
    <nav class="mobile-bottom-nav">
        <div class="mobile-bottom-nav-inner">
            <button class="mobile-nav-item active" onclick="switchTab('dashboard'); setActiveNav(this)"
                id="nav-mob-dashboard">
                <img src="/assets/icons/dashboard.png" style="height:20px; width:auto; margin-bottom:4px; display:block; margin-left:auto; margin-right:auto;">
                <span class="mobile-nav-label">Dashboard</span>
            </button>
            <button class="mobile-nav-item" onclick="switchTab('nouvelle'); setActiveNav(this)" id="nav-mob-nouvelle">
                <img src="/assets/icons/add.png" style="height:20px; width:auto; margin-bottom:4px; display:block; margin-left:auto; margin-right:auto;">
                <span class="mobile-nav-label">+ Fiche</span>
            </button>
            <a href="historique.php" class="mobile-nav-item" style="color: inherit; text-decoration:none;">
                <img src="/assets/icons/history.png" style="height:20px; width:auto; margin-bottom:4px; display:block; margin-left:auto; margin-right:auto;">
                <span class="mobile-nav-label">Historique</span>
            </a>
            <a href="profile.php" class="mobile-nav-item" style="color: inherit; text-decoration:none;">
                <img src="/assets/icons/profile.png" style="height:20px; width:auto; margin-bottom:4px; display:block; margin-left:auto; margin-right:auto;">
                <span class="mobile-nav-label">Profil</span>
            </a>
        </div>
    </nav>

    <script>
        function switchTab(name) {
            document.getElementById('tab-dashboard').style.display = name === 'dashboard' ? 'block' : 'none';
            document.getElementById('tab-nouvelle').style.display = name === 'nouvelle' ? 'block' : 'none';
            document.getElementById('nav-dashboard').className = name === 'dashboard' ? 'btn btn-primary sidebar-link' : 'btn btn-ghost sidebar-link';
            document.getElementById('nav-nouvelle').className = name === 'nouvelle' ? 'btn btn-primary sidebar-link' : 'btn btn-ghost sidebar-link';

            closeSidebar();

            document.querySelectorAll('.mobile-nav-item').forEach(i => i.classList.remove('active'));
            if (name === 'dashboard' && document.getElementById('nav-mob-dashboard')) {
                document.getElementById('nav-mob-dashboard').classList.add('active');
            }
            if (name === 'nouvelle' && document.getElementById('nav-mob-nouvelle')) {
                document.getElementById('nav-mob-nouvelle').classList.add('active');
            }
            window.scrollTo({ top: 0, behavior: 'smooth' });
        }
        function setActiveNav(el) {
            document.querySelectorAll('.mobile-nav-item').forEach(i => i.classList.remove('active'));
            el.classList.add('active');
        }
        function toggleSidebar() {
            document.getElementById('sidebar').classList.toggle('open');
            document.getElementById('sidebarOverlay').classList.toggle('open');
            document.body.classList.toggle('sidebar-is-open');
        }
        function closeSidebar() {
            document.getElementById('sidebar').classList.remove('open');
            document.getElementById('sidebarOverlay').classList.remove('open');
        }
        document.addEventListener("DOMContentLoaded", function () {
            if (window.location.search.includes('new=1')) {
                switchTab('nouvelle');
            }
        });
    </script>
</body>

</html>