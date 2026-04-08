<?php
require_once __DIR__ . '/../includes/config.php';
requireAuth('admin');

$db = getDB();
$userId = $_SESSION['user_id'];
$message = '';
$messageType = '';

// Password Requests
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
                }

                // Create intervention (assigned to the current user who creates it)
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
    } elseif ($_POST['action'] === 'accept_pwd') {
        $reqId = (int) ($_POST['req_id'] ?? 0);
        $stmt = $db->prepare("SELECT * FROM password_requests WHERE id = ? AND status = 'pending'");
        $stmt->execute([$reqId]);
        $req = $stmt->fetch();
        if ($req) {
            $db->prepare("UPDATE users SET password_hash = ?, must_change_password = FALSE WHERE id = ?")->execute([$req['new_password_hash'], $req['user_id']]);
            $db->prepare("UPDATE password_requests SET status = 'accepted' WHERE id = ?")->execute([$reqId]);
            $message = "Demande de mot de passe acceptée.";
            $messageType = "success";
        }
    } elseif ($_POST['action'] === 'reject_pwd') {
        $reqId = (int) ($_POST['req_id'] ?? 0);
        $db->prepare("UPDATE password_requests SET status = 'rejected' WHERE id = ?")->execute([$reqId]);
        $message = "Demande refusée.";
        $messageType = "info";
    }
}

$stmtPwd = $db->query("SELECT pr.id, pr.created_at, u.nom, u.prenom FROM password_requests pr JOIN users u ON pr.user_id = u.id WHERE pr.status = 'pending' ORDER BY pr.created_at ASC");
$pendingPwdRequests = $stmtPwd->fetchAll();


// Fetch All Interventions
$filterArc = trim($_GET['arc'] ?? '');
$query = '
    SELECT i.*, c.nom_societe, u.nom as tech_nom, u.prenom as tech_prenom,
    (SELECT COUNT(*) FROM machines m WHERE m.intervention_id = i.id) as nb_machines
    FROM interventions i 
    JOIN clients c ON i.client_id = c.id 
    JOIN users u ON i.technicien_id = u.id
    WHERE 1=1
';
$params = [];
if (!empty($filterArc)) {
    $query .= ' AND i.numero_arc ILIKE ?';
    $params[] = '%' . $filterArc . '%';
}
$query .= ' ORDER BY i.date_intervention DESC, i.id DESC LIMIT 100';

$stmt = $db->prepare($query);
$stmt->execute($params);
$interventions = $stmt->fetchAll();

$encours = array_filter($interventions, fn($i) => $i['statut'] === 'Brouillon');
$terminees = array_filter($interventions, fn($i) => $i['statut'] === 'Terminee');
$envoyees = array_filter($interventions, fn($i) => $i['statut'] === 'Envoyee');

?>
<!DOCTYPE html>
<html lang="fr">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0">
    <title>Espace Admin | LM Expert</title>
    <link rel="stylesheet" href="/assets/style.css">
    <link rel="manifest" href="/manifest.json">
    <link rel="apple-touch-icon" href="/assets/icon-192.png">
    <meta name="theme-color" content="#020617">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script>if (localStorage.getItem('theme') === 'light') document.documentElement.classList.add('light-mode');</script>
    <style>
        .dashboard-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2.5rem;
        }
        .stat-card {
            background: var(--glass-bg);
            border: 1px solid var(--glass-border);
            border-radius: var(--radius-lg);
            padding: 1.5rem;
            backdrop-filter: blur(10px);
            transition: transform 0.3s ease, box-shadow 0.3s ease;
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }
        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 30px rgba(0,0,0,0.3);
            border-color: var(--lenoir-orange);
        }
        .stat-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .stat-title {
            font-size: 0.85rem;
            font-weight: 600;
            color: var(--text-dim);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .stat-value {
            font-size: 1.75rem;
            font-weight: 800;
            color: var(--primary);
        }
        .chart-container {
            position: relative;
            height: 200px;
            width: 100%;
        }
        /* Page Transition */
        body { opacity: 0; transition: opacity 0.5s ease; }
        body.loaded { opacity: 1; }
        .animate-up {
            animation: slideUp 0.6s ease-out forwards;
            opacity: 0;
        }
        @keyframes slideUp {
            from { transform: translateY(20px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }
    </style>
</head>
<body onload="document.body.classList.add('loaded')">
    <header class="mobile-header">
        <button class="mobile-logo-btn" onclick="toggleSidebar()" aria-label="Menu">
            <img src="/assets/lenoir_logo_trans.svg" alt="Raoul Lenoir" class="mobile-header-logo">
        </button>
        <span class="mobile-header-title">Admin</span>
        <span class="mobile-header-user"><?= htmlspecialchars($_SESSION['user_prenom']) ?></span>
    </header>
    <div class="sidebar-overlay" id="sidebarOverlay" onclick="toggleSidebar()"></div>

    <div class="dashboard-layout">
        <aside class="sidebar" id="sidebar">
            <button class="sidebar-close-btn" onclick="toggleSidebar()">&times;</button>
            <div style="margin-bottom: 2.5rem;">
                <a href="admin.php" class="brand-icon"
                    style="display: block; width: 180px; height: auto; margin: 0 0 1rem 0;"><img
                        src="/assets/lenoir_logo_trans.svg"></a>
                <h2 style="font-size: 1.15rem;"><span class="text-gradient">Raoul Lenoir</span></h2>
                <p style="font-size: 0.7rem; color: var(--text-dim); text-transform: uppercase;">Administrateur</p>
            </div>

            <nav style="display: flex; flex-direction: column; gap: 0.4rem; margin-bottom: 2rem;">
                <a href="admin.php" class="btn btn-primary sidebar-link"
                    style="justify-content: flex-start; padding: 0.7rem 1.1rem; font-size: 0.8rem;">
                    <img src="/assets/icon_dashboard_white.svg" style="height: 16px; width: 16px; margin-right: 8px;"> Tableau de bord
                </a>
                <button onclick="document.getElementById('newInterventionModal').style.display='flex'"
                    class="btn btn-ghost sidebar-link"
                    style="justify-content: flex-start; padding: 0.7rem 1.1rem; font-size: 0.8rem;">
                    <img src="/assets/icon_add_white.svg" style="height: 16px; width: 16px; margin-right: 8px;"> Nouvelle Fiche
                </button>
                <a href="historique.php" class="btn btn-ghost sidebar-link"
                    style="justify-content: flex-start; padding: 0.7rem 1.1rem; font-size: 0.8rem;">
                    <img src="/assets/icon_history_white.svg" style="height: 16px; width: 16px; margin-right: 8px;"> Historique
                </a>
                <a href="equipe.php" class="btn btn-ghost sidebar-link"
                    style="justify-content: flex-start; padding: 0.7rem 1.1rem; font-size: 0.8rem;">
                    <img src="/assets/icon_profile_blue.svg" style="height: 16px; width: 16px; margin-right: 8px;"> Équipe
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

            <div style="display:flex; justify-content:space-between; margin-bottom: 2rem; align-items: center;">
                <button class="btn btn-primary"
                    onclick="document.getElementById('newInterventionModal').style.display='flex'">
                    <img src="/assets/icon_add_white.svg" style="height: 18px; width: 18px; margin-right: 8px; vertical-align: middle;"> NOUVELLE FICHE TECHNIQUE
                </button>
                <button onclick="document.getElementById('pwdInboxModal').style.display='flex'" class="btn btn-ghost"
                    style="padding:0.6rem 1rem;">
                    <span><img src="/assets/icons/notification.png" class="premium-icon" style="height: 18px; width: 18px; vertical-align: middle; margin-right: 4px;"></span> Demandes MDP
                    <?php if (count($pendingPwdRequests) > 0): ?>
                        <span
                            style="background:var(--error); color:white; border-radius:50%; width:20px; height:20px; display:inline-flex; align-items:center; justify-content:center; font-size:0.7rem; font-weight:bold; margin-left:0.5rem;">
                            <?= count($pendingPwdRequests) ?>
                        </span>
                    <?php endif; ?>
                </button>
            </div>

            <!-- DASHBOARD SECTION -->
            <div class="dashboard-grid animate-up" style="animation-delay: 0.1s;">
                <div class="stat-card premium-glow">
                    <div class="stat-header">
                        <span class="stat-title">Conformité Globale</span>
                        <span class="badge" style="background: rgba(16, 185, 129, 0.1); color: #10b981;">Score IA</span>
                    </div>
                    <div style="display: flex; align-items: baseline; gap: 8px;">
                        <span class="stat-value" id="complianceValue">--</span>
                        <span style="color: var(--text-dim); font-size: 1rem;">%</span>
                    </div>
                    <div class="chart-container">
                        <canvas id="complianceChart"></canvas>
                    </div>
                </div>

                <div class="stat-card premium-glow">
                    <div class="stat-header">
                        <span class="stat-title">Volume d'Expertises</span>
                        <span class="badge" style="background: rgba(59, 130, 246, 0.1); color: #3b82f6;">Mensuel</span>
                    </div>
                    <div class="stat-value" id="monthlyTotal">--</div>
                    <div class="chart-container">
                        <canvas id="monthlyChart"></canvas>
                    </div>
                </div>

                <div class="stat-card premium-glow">
                    <div class="stat-header">
                        <span class="stat-title">Répartition par Statut</span>
                    </div>
                    <div class="chart-container" style="height: 180px;">
                        <canvas id="statusChart"></canvas>
                    </div>
                </div>
            </div>

            <!-- Modal Nouvelle Intervention -->
            <div id="newInterventionModal"
                style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; z-index:9999; background:rgba(0,0,0,0.8); align-items:center; justify-content:center; backdrop-filter:blur(5px);">
                <div class="card glass animate-in"
                    style="width:100%; max-width:500px; padding:2rem; position:relative;">
                    <button type="button" onclick="document.getElementById('newInterventionModal').style.display='none'"
                        style="position:absolute; top:1rem; right:1.5rem; background:none; border:none; color:var(--text-dim); font-size:1.5rem; cursor:pointer;">&times;</button>
                    <h3 style="margin-bottom: 1.5rem;">Nouvelle Fiche Technique</h3>
                    <form method="POST" autocomplete="off">
                        <input type="hidden" name="action" value="nouvelle_intervention">
                        <?= csrfField() ?>
                        <div class="form-group">
                            <label class="label">Numéro ARC *</label>
                            <input type="text" name="numero_arc" class="input" required placeholder="Ex: 2026-001"
                                maxlength="50" style="text-transform:uppercase;">
                        </div>
                        <div class="form-group">
                            <label class="label">Client (Nom de la société) *</label>
                            <input type="text" name="nom_societe" class="input" required
                                placeholder="Nom de l'entreprise">
                        </div>
                        <div class="form-group">
                            <label class="label">Nom du contact sur place</label>
                            <input type="text" name="contact_nom" class="input" placeholder="Optionnel">
                        </div>
                        <div class="form-group">
                            <label class="label">Date d'intervention</label>
                            <input type="date" name="date_intervention" class="input" value="<?= date('Y-m-d') ?>"
                                required>
                        </div>
                        <button type="submit" class="btn btn-primary" style="width: 100%; margin-top: 1rem;">
                            Créer et Démarrer la Saisie
                        </button>
                    </form>
                </div>
            </div>


            <!-- Modal MDP (idem que précédent) -->
            <div id="pwdInboxModal"
                style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; z-index:9999; background:rgba(0,0,0,0.8); align-items:center; justify-content:center; backdrop-filter:blur(5px);">
                <div class="card glass animate-in"
                    style="width:100%; max-width:500px; padding:2rem; position:relative;">
                    <button onclick="document.getElementById('pwdInboxModal').style.display='none'"
                        style="position:absolute; top:1rem; right:1.5rem; background:none; border:none; color:var(--text-dim); font-size:1.5rem; cursor:pointer;">&times;</button>
                    <h3 style="display:flex; align-items:center; gap:8px;"><img src="/assets/icons/email.png" class="premium-icon" style="height: 24px; width: 24px;"> Demandes de mot de passe</h3>
                    <?php if (count($pendingPwdRequests) === 0): ?>
                        <p style="color:var(--text-dim); font-size:0.85rem; padding: 1rem 0;">Aucune demande en attente.</p>
                    <?php else: ?>
                        <?php foreach ($pendingPwdRequests as $req): ?>
                            <div
                                style="background:rgba(255,255,255,0.05); padding:1rem; border-radius:var(--radius-sm); margin-bottom:1rem;">
                                <p><?= htmlspecialchars($req['prenom'] . ' ' . $req['nom']) ?></p>
                                <div style="display:flex; gap:0.5rem; margin-top:0.5rem;">
                                    <form method="POST" style="margin:0;"><?= csrfField() ?><input type="hidden" name="action"
                                            value="accept_pwd"><input type="hidden" name="req_id"
                                            value="<?= $req['id'] ?>"><button class="btn btn-primary"
                                            style="padding:0.4rem; font-size:0.75rem;">Accepter</button></form>
                                    <form method="POST" style="margin:0;"><?= csrfField() ?><input type="hidden" name="action"
                                            value="reject_pwd"><input type="hidden" name="req_id"
                                            value="<?= $req['id'] ?>"><button class="btn btn-ghost"
                                            style="padding:0.4rem; font-size:0.75rem; color:var(--error);">Refuser</button>
                                    </form>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <div class="stats-grid animate-in" style="margin-bottom:2rem;">
                <div class="stat-item glass">
                    <span class="stat-label">Brouillons</span>
                    <span class="stat-value" style="color:var(--text-main);"><?= count($encours) ?></span>
                </div>
                <div class="stat-item glass">
                    <span class="stat-label">Terminées</span>
                    <span class="stat-value" style="color:var(--primary);"><?= count($terminees) ?></span>
                </div>
                <div class="stat-item glass">
                    <span class="stat-label">Envoyées</span>
                    <span class="stat-value" style="color:var(--success);"><?= count($envoyees) ?></span>
                </div>
            </div>

            <div class="card glass animate-in">
                <div
                    style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 2rem; flex-wrap: wrap; gap: 1rem;">
                    <h3 style="font-size: 1.3rem;">Toutes les Fiches Techniques</h3>
                    <form method="GET" style="display: flex; gap: 0.5rem;" autocomplete="off">
                        <input type="text" name="arc" class="input" style="width: 160px; padding: 0.5rem;"
                            placeholder="Recherche ARC..." value="<?= htmlspecialchars($filterArc) ?>">
                        <button type="submit" class="btn btn-ghost" style="padding: 0.5rem;">Filtrer</button>
                    </form>
                </div>

                <div style="overflow-x: auto;">
                    <table style="width: 100%; border-collapse: collapse; min-width: 600px; text-align:left;">
                        <thead>
                            <tr
                                style="border-bottom: 2px solid var(--glass-border); color: var(--text-dim); font-size: 0.75rem; text-transform: uppercase;">
                                <th style="padding: 1rem;">ARC</th>
                                <th style="padding: 1rem;">Date</th>
                                <th style="padding: 1rem;">Client</th>
                                <th style="padding: 1rem;">Machines</th>
                                <th style="padding: 1rem;">Technicien</th>
                                <th style="padding: 1rem;">Statut</th>
                                <th style="padding: 1rem; text-align:right;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($interventions)): ?>
                                <tr>
                                    <td colspan="7" style="padding: 2rem; text-align: center; color: var(--text-dim);">
                                        Aucune intervention trouvée.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($interventions as $i): ?>
                                    <tr style="border-bottom: 1px solid rgba(255,255,255,0.03);">
                                        <td style="padding: 1rem; font-weight:bold; color:var(--primary);">
                                            <?= htmlspecialchars($i['numero_arc']) ?>
                                        </td>
                                        <td style="padding: 1rem; font-size:0.85rem;">
                                            <?= date('d/m/Y', strtotime($i['date_intervention'])) ?>
                                        </td>
                                        <td style="padding: 1rem; font-size:0.9rem;"><?= htmlspecialchars($i['nom_societe']) ?>
                                        </td>
                                        <td style="padding: 1rem; font-size:0.9rem;"><?= $i['nb_machines'] ?></td>
                                        <td style="padding: 1rem; font-size:0.85rem; color:var(--text-dim);">
                                            <?= htmlspecialchars(substr($i['tech_prenom'], 0, 1) . '. ' . $i['tech_nom']) ?>
                                        </td>
                                        <td style="padding: 1rem;">
                                            <span
                                                style="font-size:0.7rem; padding:0.2rem 0.6rem; border-radius:20px; font-weight:bold; 
                                                <?= $i['statut'] === 'Terminee' ? 'background:rgba(16,185,129,0.1);color:var(--success);' : ($i['statut'] === 'Envoyee' ? 'background:var(--primary);color:#000;' : 'background:rgba(255,255,255,0.1);color:var(--text-dim);') ?>">
                                                <?= htmlspecialchars($i['statut']) ?>
                                            </span>
                                        </td>
                                        <td style="padding: 1rem; text-align:right;">
                                            <?php if ($i['statut'] !== 'Brouillon'): ?>
                                                <a href="rapport_final.php?id=<?= $i['id'] ?>&download=1" target="_blank" class="btn btn-ghost"
                                                    style="padding:0.4rem 0.6rem; font-size:0.8rem; text-decoration:none;">PDF</a>
                                            <?php else: ?>
                                                <a href="intervention_edit.php?id=<?= $i['id'] ?>" class="btn btn-ghost"
                                                    style="padding:0.4rem 0.6rem; font-size:0.8rem; text-decoration:none;">Continuer
                                                    la saisie →</a>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </main>
    </div>

    <!-- Mobile Bottom Nav -->
    <nav class="mobile-bottom-nav">
        <div class="mobile-bottom-nav-inner">
            <a href="admin.php" class="mobile-nav-item active" style="color: inherit; text-decoration:none;">
                <img src="/assets/icon_dashboard_white.svg" style="height: 24px; width: 24px; margin-bottom: 4px; opacity: 0.7;">
                <span class="mobile-nav-label">Tableau</span>
            </a>
            <a href="historique.php" class="mobile-nav-item" style="color: inherit; text-decoration:none;">
                <img src="/assets/icon_history_white.svg" style="height: 24px; width: 24px; margin-bottom: 4px; opacity: 0.7;">
                <span class="mobile-nav-label">Historique</span>
            </a>
            <a href="equipe.php" class="mobile-nav-item" style="color: inherit; text-decoration:none;">
                <img src="/assets/icon_profile_blue.svg" style="height: 24px; width: 24px; margin-bottom: 4px; opacity: 0.7;">
                <span class="mobile-nav-label">Équipe</span>
            </a>
            <a href="profile.php" class="mobile-nav-item" style="color: inherit; text-decoration:none;">
                <img src="/assets/icon_profile_blue.svg" style="height: 24px; width: 24px; margin-bottom: 4px; opacity: 0.7;">
                <span class="mobile-nav-label">Profil</span>
            </a>
        </div>
    </nav>

    <script>
        document.addEventListener("DOMContentLoaded", function () {
            if (window.location.search.includes('new=1')) {
                document.getElementById('newInterventionModal').style.display = 'flex';
            }
            initDashboard();
        });

        function toggleSidebar() {
            document.getElementById('sidebar').classList.toggle('active');
            document.getElementById('sidebarOverlay').classList.toggle('active');
        }

        // Initialize Charts
        async function initDashboard() {
            try {
                const response = await fetch('admin_stats.php');
                const result = await response.json();
                if (!result.success || !result.data) return;

                const data = result.data;
                const ctxComp = document.getElementById('complianceChart').getContext('2d');
                const ctxMonthly = document.getElementById('monthlyChart').getContext('2d');
                const ctxStatus = document.getElementById('statusChart').getContext('2d');

                // 1. Compliance (Gradient Orange Gauge)
                const gradOrange = ctxComp.createLinearGradient(0, 0, 200, 0);
                gradOrange.addColorStop(0, '#ffb300');
                gradOrange.addColorStop(1, '#ff8f00');

                document.getElementById('complianceValue').innerText = data.compliance;
                new Chart(ctxComp, {
                    type: 'doughnut',
                    data: {
                        datasets: [{
                            data: [data.compliance, 100 - data.compliance],
                            backgroundColor: [gradOrange, 'rgba(255, 255, 255, 0.03)'],
                            borderWidth: 0,
                            circumference: 180,
                            rotation: 270,
                        }]
                    },
                    options: {
                        cutout: '82%',
                        plugins: { legend: { display: false } },
                        responsive: true,
                        maintainAspectRatio: false,
                        animation: { duration: 2000, easing: 'easeOutQuart' }
                    }
                });

                // 2. Monthly Trends (Deep Blue Area Gradient)
                const gradBlue = ctxMonthly.createLinearGradient(0, 0, 0, 200);
                gradBlue.addColorStop(0, 'rgba(0, 74, 153, 0.4)');
                gradBlue.addColorStop(1, 'rgba(0, 74, 153, 0)');

                const monthlyLabels = (data.monthly || []).map(m => {
                    const months = ['Jan', 'Fév', 'Mar', 'Avr', 'Mai', 'Juin', 'Juil', 'Août', 'Sep', 'Oct', 'Nov', 'Déc'];
                    const parts = (m.mois || "").split('-');
                    const monthIdx = parts.length > 1 ? parseInt(parts[1]) - 1 : 0;
                    return months[monthIdx] || "N/A";
                });
                document.getElementById('monthlyTotal').innerText = (data.monthly || []).reduce((a, b) => a + parseInt(b.count), 0);
                
                new Chart(ctxMonthly, {
                    type: 'line',
                    data: {
                        labels: monthlyLabels,
                        datasets: [{
                            label: 'Interventions',
                            data: (data.monthly || []).map(m => m.count),
                            borderColor: '#004a99',
                            borderWidth: 3,
                            backgroundColor: gradBlue,
                            fill: true,
                            tension: 0.4,
                            pointRadius: 4,
                            pointBackgroundColor: '#004a99',
                            pointHoverRadius: 6,
                        }]
                    },
                    options: {
                        scales: {
                            y: { display: false, beginAtZero: true },
                            x: { grid: { display: false }, ticks: { color: '#64748b', font: { size: 10, weight: '600' } } }
                        },
                        plugins: { 
                            legend: { display: false },
                            tooltip: {
                                backgroundColor: '#0f172a',
                                titleColor: '#fff',
                                bodyColor: '#cbd5e1',
                                padding: 12,
                                cornerRadius: 8,
                                displayColors: false
                            }
                        },
                        responsive: true,
                        maintainAspectRatio: false
                    }
                });

                // 3. Status Distribution (Brand Mix)
                new Chart(ctxStatus, {
                    type: 'doughnut',
                    data: {
                        labels: (data.status || []).map(s => s.statut || "N/A"),
                        datasets: [{
                            data: (data.status || []).map(s => s.count || 0),
                            backgroundColor: ['#004a99', '#ffb300', '#64748b', '#10b981'],
                            hoverOffset: 15,
                            borderWidth: 2,
                            borderColor: '#020617'
                        }]
                    },
                    options: {
                        plugins: {
                            legend: {
                                position: 'right',
                                labels: { color: '#94a3b8', boxWidth: 10, font: { size: 11, weight: '500' }, padding: 15 }
                            }
                        },
                        responsive: true,
                        maintainAspectRatio: false,
                        animation: { animateRotate: true, animateScale: true }
                    }
                });

            } catch (e) {
                console.error('Stats error:', e);
            }
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