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
        $contactPrenom = trim($_POST['contact_prenom'] ?? '');
        $contactNom = trim($_POST['contact_nom'] ?? '');
        $dateInt = $_POST['date_intervention'] ?? date('Y-m-d');

        if (empty($arc) || empty($clientNom)) {
            $message = 'Le numéro ARC et le client sont obligatoires.';
            $messageType = 'error';
        } else {
            try {
                $db->beginTransaction();

                // Create or find client
                $stmtCheck = $db->prepare('SELECT id FROM clients WHERE nom_societe = ?');
                $stmtCheck->execute([$clientNom]);
                $clientId = $stmtCheck->fetchColumn();

                if (!$clientId) {
                    $stmtClient = $db->prepare('INSERT INTO clients (nom_societe) VALUES (?) RETURNING id');
                    $stmtClient->execute([$clientNom]);
                    $clientId = $stmtClient->fetchColumn();
                    $lastInt = null;
                    $clientData = null;
                } else {
                    // Fetch data from last intervention for this client
                    $stmtLast = $db->prepare('SELECT contact_fonction, contact_email, contact_telephone FROM interventions WHERE client_id = ? AND (contact_fonction != \'\' OR contact_email != \'\' OR contact_telephone != \'\') ORDER BY date_intervention DESC, id DESC LIMIT 1');
                    $stmtLast->execute([$clientId]);
                    $lastInt = $stmtLast->fetch(PDO::FETCH_ASSOC);

                    // Fetch base client data as fallback
                    $stmtClientData = $db->prepare('SELECT contact_fonction, contact_email, contact_telephone FROM clients WHERE id = ?');
                    $stmtClientData->execute([$clientId]);
                    $clientData = $stmtClientData->fetch(PDO::FETCH_ASSOC);
                }

                // Create intervention with inherited data (Prioritize last intervention, then client master data)
                $cFonction = !empty($lastInt['contact_fonction']) ? $lastInt['contact_fonction'] : ($clientData['contact_fonction'] ?? '');
                $cEmail = !empty($lastInt['contact_email']) ? $lastInt['contact_email'] : ($clientData['contact_email'] ?? '');
                $cTel = !empty($lastInt['contact_telephone']) ? $lastInt['contact_telephone'] : ($clientData['contact_telephone'] ?? '');

                $stmtInt = $db->prepare('INSERT INTO interventions (numero_arc, client_id, technicien_id, contact_prenom, contact_nom, contact_fonction, contact_email, contact_telephone, date_intervention) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
                $stmtInt->execute([$arc, $clientId, $userId, $contactPrenom, $contactNom, $cFonction, $cEmail, $cTel, $dateInt]);
                $newId = $db->lastInsertId();

                $db->commit();
                logAudit('INTERVENTION_CREATED', "ARC: $arc");
                header('Location: intervention_edit.php?id=' . $newId);
                exit;
            } catch (PDOException $e) {
                $db->rollBack();
                if ($e->getCode() == 23505) {
                    $message = 'Ce numéro ARC existe déjà.';
                } else {
                    $message = 'Erreur lors de la création: ' . $e->getMessage(); // Added error message for debugging
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

$encours = array_filter($interventions, fn($i) => strtolower($i['statut']) === 'brouillon');
$terminees = array_slice(array_filter($interventions, fn($i) => in_array(strtolower($i['statut']), ['terminee', 'terminée', 'envoyee', 'envoyée'])), 0, 5);

$showNewTab = isset($_GET['new']) && $_GET['new'] == '1';
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
    <?php renderSentryJS(); ?>
    <script>
        if (localStorage.getItem('theme') === 'light') { document.documentElement.classList.add('light-mode'); }
    </script>
</head>

<body onload="document.body.classList.add('loaded')">
    <header class="mobile-header">
        <button class="mobile-logo-btn" onclick="toggleSidebar()" aria-label="Menu">
            <img src="/assets/lenoir_logo_trans.svg" alt="Raoul Lenoir" class="mobile-header-logo">
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
                    <img src="/assets/lenoir_logo_trans.svg" alt="Raoul Lenoir">
                </a>
                <h2 style="font-size: 1.15rem;"><span class="text-gradient">Raoul Lenoir</span></h2>
                <p style="font-size: 0.7rem; color: var(--text-dim); text-transform: uppercase;">Espace Technicien</p>
            </div>

            <nav style="display: flex; flex-direction: column; gap: 0.4rem; margin-bottom: 2rem;">
                <button class="btn <?= $showNewTab ? 'btn-ghost' : 'btn-primary' ?> sidebar-link" onclick="switchTab('dashboard')" id="nav-dashboard"
                    style="justify-content: flex-start; padding: 0.7rem 1.1rem; font-size: 0.8rem;">
                    <img src="/assets/icon_dashboard_white.svg" style="height: 16px; width: 16px; margin-right: 8px;"> Tableau de bord
                </button>
                <button class="btn <?= $showNewTab ? 'btn-primary' : 'btn-ghost' ?> sidebar-link" onclick="switchTab('nouvelle')" id="nav-nouvelle"
                    style="justify-content: flex-start; padding: 0.7rem 1.1rem; font-size: 0.8rem;">
                    <img src="/assets/icon_add_white.svg" style="height: 16px; width: 16px; margin-right: 8px;"> Nouveau Rapport
                </button>
                <a href="historique.php" class="btn btn-ghost sidebar-link"
                    style="justify-content: flex-start; padding: 0.7rem 1.1rem; font-size: 0.8rem; text-decoration: none; color: inherit;">
                    <img src="/assets/icon_history_white.svg" style="height: 16px; width: 16px; margin-right: 8px;"> Historique
                </a>
                <a href="assistant.php" class="btn btn-ghost sidebar-link"
                    style="justify-content: flex-start; padding: 0.7rem 1.1rem; font-size: 0.8rem; text-decoration: none; color: inherit;">
                    <img src="/assets/ai_expert.jpg" style="height: 20px; width: 20px; margin-right: 8px; border-radius: 4px;"> Expert IA
                </a>
            </nav>

            <div style="margin-top: auto; padding-top: 1.5rem; border-top: 1px solid var(--glass-border);">
                <p style="font-size: 0.65rem; color: var(--text-dim); text-transform: uppercase;">Connecté</p>
                <div style="display: flex; align-items: center; gap: 0.75rem; margin-bottom: 1rem;">
                    <?php if (!empty($_SESSION['avatar'])): ?>
                        <div style="width: 42px; height: 42px; border-radius: 50%; border: 1.5px solid rgba(255, 255, 255, 0.8); overflow: hidden; flex-shrink: 0; box-shadow: 0 0 10px rgba(0,0,0,0.2);">
                            <img src="<?= htmlspecialchars($_SESSION['avatar']) ?>" style="width: 100%; height: 100%; object-fit: cover;">
                        </div>
                    <?php endif; ?>
                    <p
                        style="font-weight: 600; font-size: 0.85rem; margin: 0; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 180px;">
                        <?= htmlspecialchars($_SESSION['user_prenom'] . ' ' . $_SESSION['user_nom']) ?>
                    </p>
                </div>

                <a href="logout.php" class="btn btn-ghost"
                    style="width: 100%; justify-content: flex-start; padding: 0.7rem 1.1rem; font-size: 0.8rem; color: var(--error); margin-bottom: 0.4rem;">
                    <img src="/assets/icon_logout_red.svg" style="height: 16px; width: 16px; margin-right: 8px;"> Se déconnecter
                </a>
                <a href="profile.php" class="btn btn-ghost sidebar-link"
                    style="width: 100%; justify-content: flex-start; padding: 0.7rem 1.1rem; font-size: 0.8rem;">
                    <img src="/assets/icon_profile_blue.svg" style="height: 16px; width: 16px; margin-right: 8px;"> Mon Profil
                </a>
            </div>
        </aside>

        <main class="main-content">
            <?php if ($message): ?>
                <script>
                    document.addEventListener('DOMContentLoaded', () => {
                        showToast(<?= json_encode($message) ?>, <?= json_encode($messageType) ?>);
                    });
                </script>
            <?php endif; ?>

            <div class="stats-grid animate-in" style="grid-template-columns: repeat(2, 1fr); gap: 1rem; margin-bottom: 2rem;">
                <div class="stat-item glass premium-glow" style="border-left: 3px solid var(--accent-cyan); padding: 1.25rem;">
                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:0.5rem;">
                        <span class="stat-label" style="margin:0;">En cours</span>
                        <img src="/assets/icon_gear_orange.svg" style="height:16px; opacity:0.5; filter: hue-rotate(180deg);">
                    </div>
                    <span class="stat-value" style="color: var(--accent-cyan); font-size: 2rem;"><?= count($encours) ?></span>
                </div>
                <div class="stat-item glass premium-glow" style="border-left: 3px solid var(--primary); padding: 1.25rem;">
                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:0.5rem;">
                        <span class="stat-label" style="margin:0;">Historique</span>
                        <img src="/assets/icon_history_white.svg" style="height:16px; opacity:0.5;">
                    </div>
                    <span class="stat-value" style="font-size: 2rem;"><?= count($terminees) ?></span>
                </div>
            </div>

            <!-- Tab Dashboard -->
            <div id="tab-dashboard" class="animate-in" <?= $showNewTab ? 'style="display: none;"' : '' ?>>
                <h3
                    style="margin-bottom: 1.25rem; color: var(--text-dim); font-size: 0.8rem; text-transform: uppercase;">
                    Fiches Techniques en cours
                </h3>
                <?php if (empty($encours)): ?>
                    <div class="card glass" style="text-align: center; padding: 4rem 2rem;">
                        <div style="margin-bottom: 1rem; opacity: 0.1; text-align: center;"><img src="/assets/icon_gear_orange.svg" style="height: 80px; width: 80px;"></div>
                        <p style="color: var(--text-dim); font-size: 0.9rem;">Aucune intervention en cours</p>
                    </div>
                <?php else: ?>
                    <div style="display: flex; flex-direction: column; gap: 0.75rem;">
                        <?php foreach ($encours as $i): ?>
                            <?php 
                                $clientInitial = strtoupper(substr($i['nom_societe'], 0, 1));
                                $gradients = [
                                    'linear-gradient(135deg, #0ea5e9, #6366f1)',
                                    'linear-gradient(135deg, #f59e0b, #ef4444)',
                                    'linear-gradient(135deg, #10b981, #059669)',
                                    'linear-gradient(135deg, #8b5cf6, #d946ef)'
                                ];
                                $grad = $gradients[ord($clientInitial) % count($gradients)];
                            ?>
                            <a href="intervention_edit.php?id=<?= $i['id'] ?>" class="card glass of-card"
                                style="padding: 1rem 1.25rem; display: flex; align-items: center; gap: 1rem; text-decoration: none; color: inherit; transition: var(--transition-smooth);">
                                <div style="width: 44px; height: 44px; border-radius: 12px; background: <?= $grad ?>; display: flex; align-items: center; justify-content: center; color: white; font-weight: 800; font-size: 1.2rem; flex-shrink: 0; box-shadow: 0 4px 12px rgba(0,0,0,0.2);">
                                    <?= $clientInitial ?>
                                </div>
                                <div style="flex: 1;">
                                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom: 0.1rem;">
                                        <p style="font-weight: 700; font-size: 0.95rem; margin:0;"><?= htmlspecialchars($i['nom_societe']) ?></p>
                                        <span style="font-size: 0.65rem; padding: 0.2rem 0.5rem; border-radius: 4px; background: rgba(14, 165, 233, 0.1); color: var(--accent-cyan); font-weight: 700;">BROUILLON</span>
                                    </div>
                                    <p style="font-size: 0.75rem; color: var(--text-dim); margin:0;">
                                        ARC: <?= htmlspecialchars($i['numero_arc']) ?> · <?= date('d/m/Y', strtotime($i['date_intervention'])) ?>
                                    </p>
                                </div>
                                <div style="color: var(--primary); font-size: 1.2rem; opacity: 0.5;">›</div>
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
                                    <?php if (in_array(strtolower($i['statut']), ['terminee', 'terminée'])): ?>
                                        <span class="status-badge" style="background:rgba(16,185,129,0.1); color:#10b981; border:1px solid rgba(16,185,129,0.2);">Terminée</span>
                                    <?php else: ?>
                                        <span class="status-badge" style="background:rgba(59,130,246,0.1); color:#3b82f6; border:1px solid rgba(59,130,246,0.2);">Envoyée</span>
                                    <?php endif; ?>
                                    <?php if (in_array(strtolower($i['statut']), ['terminee', 'terminée'])): ?>
                                        <a href="rapport_final.php?id=<?= $i['id'] ?>&msg=ok"
                                            style="font-size:0.75rem; padding:0.3rem 0.6rem; background:rgba(16,185,129,0.1); color:var(--success); border-radius:4px; text-decoration:none; white-space:nowrap;">
                                            <img src="/assets/icon_document_blue.svg" style="height: 14px; width: 14px; vertical-align: middle; margin-right: 4px;"> Rapport
                                        </a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Tab Nouveau Rapport -->
            <div id="tab-nouvelle" style="<?= $showNewTab ? 'display: block;' : 'display: none;' ?>" class="animate-in">
                <form method="POST" class="card glass" style="margin-bottom: 1.5rem;" autocomplete="off">
                    <input type="hidden" name="action" value="nouvelle_intervention">
                    <?= csrfField() ?>
                    <h3 style="margin-bottom: 1.5rem; display: flex; align-items: center; gap: 0.75rem;">
                        <img src="/assets/icon_add_white.svg" style="height: 20px; width: 20px;"> Nouvelle Intervention
                    </h3>

                    <div class="form-group">
                        <label class="label" style="font-size: 1rem; color: var(--primary);">N° A.R.C. <span
                                style="color: var(--error);">*</span></label>
                        <p style="font-size: 0.7rem; color: var(--text-dim); margin: 0 0 0.5rem 0;">Référence centrale
                            de l'intervention – reportée sur toutes les fiches</p>
                        <input type="text" name="numero_arc" class="input" placeholder="ex: 2600182" 
                            style="text-transform: uppercase; font-size: 1.1rem; font-weight: bold; border: 2px solid var(--primary); padding: 1rem;"
                            required autofocus maxlength="50">
                    </div>

                    <div class="form-group" style="position:relative;">
                        <label class="label">Client (Société) <span style="color: var(--error);">*</span></label>
                        <input type="text" name="nom_societe" id="client_search" class="input" placeholder="Commencez à taper..."
                            required autocomplete="off">
                        <div id="autocomplete_results" class="autocomplete-dropdown" style="display:none;"></div>
                    </div>

                    <div style="display:grid; grid-template-columns: 1fr 1fr; gap:1rem; margin-bottom: 1.5rem;">
                        <div class="form-group" style="margin-bottom:0;">
                            <label class="label">Prénom du contact</label>
                            <input type="text" name="contact_prenom" class="input" placeholder="Prénom...">
                        </div>
                        <div class="form-group" style="margin-bottom:0;">
                            <label class="label">Nom du contact</label>
                            <input type="text" name="contact_nom" class="input" placeholder="Nom...">
                        </div>
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

    <nav class="mobile-bottom-nav">
        <div class="mobile-bottom-nav-inner">
            <button class="mobile-nav-item <?= $showNewTab ? '' : 'active' ?>" onclick="switchTab('dashboard'); setActiveNav(this)" id="nav-mob-dashboard">
                <img src="/assets/icon_dashboard_white.svg" style="height: 24px; width: 24px; margin-bottom: 4px; opacity: 0.7;">
                <span class="mobile-nav-label">Dashboard</span>
            </button>
            <button class="mobile-nav-item <?= $showNewTab ? 'active' : '' ?>" onclick="switchTab('nouvelle'); setActiveNav(this)" id="nav-mob-nouvelle">
                <img src="/assets/icon_add_white.svg" style="height: 24px; width: 24px; margin-bottom: 4px; opacity: 0.7;">
                <span class="mobile-nav-label">+ Fiche</span>
            </button>
            <a href="historique.php" class="mobile-nav-item" style="color: inherit; text-decoration:none;">
                <img src="/assets/icon_history_white.svg" style="height: 24px; width: 24px; margin-bottom: 4px; opacity: 0.7;">
                <span class="mobile-nav-label">Historique</span>
            </a>
            <a href="profile.php" class="mobile-nav-item" style="color: inherit; text-decoration:none;">
                <img src="/assets/icon_profile_blue.svg" style="height: 24px; width: 24px; margin-bottom: 4px; opacity: 0.7;">
                <span class="mobile-nav-label">Profil</span>
            </a>
            <a href="assistant.php" class="mobile-nav-item" style="color: inherit; text-decoration:none;">
                <div style="width: 24px; height: 24px; display: flex; align-items: center; justify-content: center; margin-bottom: 4px;">
                    <img src="/assets/ai_expert.jpg" style="height: 22px; width: 22px; border-radius: 4px; object-fit: cover;">
                </div>
                <span class="mobile-nav-label">Expert IA</span>
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

        // Client Autocomplete Logic
        const clientInput = document.getElementById('client_search');
        const resultsDiv = document.getElementById('autocomplete_results');
        let debounceTimer;

        clientInput.addEventListener('input', function() {
            clearTimeout(debounceTimer);
            const q = this.value.trim();
            if (q.length < 2) {
                resultsDiv.style.display = 'none';
                return;
            }

            debounceTimer = setTimeout(() => {
                fetch(`search_clients.php?q=${encodeURIComponent(q)}`)
                    .then(r => r.json())
                    .then(data => {
                        if (data.length > 0) {
                            resultsDiv.innerHTML = data.map(c => `
                                <div class="autocomplete-item" onclick="selectClient('${c.nom_societe.replace(/'/g, "\\'")}')">
                                    ${c.nom_societe}
                                </div>
                            `).join('');
                            resultsDiv.style.display = 'block';
                        } else {
                            resultsDiv.style.display = 'none';
                        }
                    });
            }, 300);
        });

        function selectClient(name) {
            clientInput.value = name;
            resultsDiv.style.display = 'none';
        }

        document.addEventListener('click', (e) => {
            if (!clientInput.contains(e.target) && !resultsDiv.contains(e.target)) {
                resultsDiv.style.display = 'none';
            }
        });

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
        // Smooth navigation
        document.querySelectorAll('.sidebar-link').forEach(link => {
            link.addEventListener('click', function(e) {
                if (this.getAttribute('href') && !this.getAttribute('onclick')) {
                    document.body.style.opacity = '0';
                }
            });
        });
    </script>
    <script src="/assets/toast.js"></script>
</body>

</html>