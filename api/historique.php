<?php
require_once __DIR__ . '/../includes/config.php';
requireAuth(['admin', 'technicien']); // Allowed for both, but techniciens see only theirs

$db = getDB();
$isAdmin = ($_SESSION['role'] === 'admin');
$userId = $_SESSION['user_id'];

// ── Filtres GET validés ────────────────────────────────────────────────────
$filterPeriod = $_GET['period'] ?? 'all';
$allowedPeriods = ['today', 'current', 'last', 'month', 'all'];
if (!in_array($filterPeriod, $allowedPeriods, true)) {
    $filterPeriod = 'all';
}

$filterStatut = strtolower($_GET['statut'] ?? 'all');
$allowedStatuts = ['all', 'brouillon', 'terminee', 'envoyee'];
if (!in_array($filterStatut, $allowedStatuts, true)) {
    $filterStatut = 'all';
}

$filterUser = intval($_GET['user'] ?? 0);
$filterArc = substr(preg_replace('/[^\w\s\-\/]/', '', trim($_GET['arc'] ?? '')), 0, 50);
$filterClient = substr(preg_replace('/[^\w\s\-\/]/', '', trim($_GET['client'] ?? '')), 0, 50);

// Calcul des dates selon la période
$week = [
    'monday' => date('Y-m-d', strtotime('monday this week')),
    'sunday' => date('Y-m-d', strtotime('sunday this week'))
];

if ($filterPeriod === 'last') {
    $dateDebut = date('Y-m-d', strtotime($week['monday'] . ' -7 days'));
    $dateFin = date('Y-m-d', strtotime($week['sunday'] . ' -7 days'));
    $labelPeriod = 'Semaine précédente';
} elseif ($filterPeriod === 'today') {
    $dateDebut = date('Y-m-d');
    $dateFin = date('Y-m-d');
    $labelPeriod = 'Aujourd\'hui';
} elseif ($filterPeriod === 'month') {
    $dateDebut = date('Y-m-01');
    $dateFin = date('Y-m-d');
    $moisNoms = ['', 'Janvier', 'Février', 'Mars', 'Avril', 'Mai', 'Juin', 'Juillet', 'Août', 'Septembre', 'Octobre', 'Novembre', 'Décembre'];
    $labelPeriod = 'Ce mois (' . $moisNoms[(int) date('n')] . ' ' . date('Y') . ')';
} elseif ($filterPeriod === 'current') {
    $dateDebut = $week['monday'];
    $dateFin = date('Y-m-d'); // Jusqu'à aujourd'hui ou fin de semaine
    $labelPeriod = 'Semaine en cours';
} else { // all
    $dateDebut = '2020-01-01';
    $dateFin = date('Y-m-d', strtotime('+1 year')); // Allow future dates if scheduled
    $labelPeriod = 'Tout l\'historique';
}

$message = '';
$messageType = '';

// ── Récupérer tous les utilisateurs (pour admin) ───────────────────────────
$allUsers = [];
if ($isAdmin) {
    $stmtUsers = $db->prepare("SELECT id, nom, prenom FROM users WHERE role = 'technicien' ORDER BY nom");
    $stmtUsers->execute();
    $allUsers = $stmtUsers->fetchAll();
}

// ── Requête principale : toutes les interventions de la période ────────────
$query = '
    SELECT i.*, c.nom_societe, u.nom as tech_nom, u.prenom as tech_prenom, u.avatar_base64
    FROM interventions i
    JOIN clients c ON i.client_id = c.id
    JOIN users u ON i.technicien_id = u.id
    WHERE i.date_intervention >= ? AND i.date_intervention <= ?
';
$params = [$dateDebut, $dateFin];

if (!$isAdmin) {
    $query .= ' AND i.technicien_id = ?';
    $params[] = $userId;
} elseif ($filterUser > 0) {
    $query .= ' AND i.technicien_id = ?';
    $params[] = $filterUser;
}

if ($filterStatut === 'brouillon') {
    $query .= " AND i.statut ILIKE 'Brouillon%'";
} elseif ($filterStatut === 'terminee') {
    $query .= " AND i.statut ILIKE 'Termin%'";
} elseif ($filterStatut === 'envoyee') {
    $query .= " AND i.statut ILIKE 'Envoy%'";
}

if (!empty($filterArc)) {
    $query .= ' AND i.numero_arc ILIKE ?';
    $params[] = '%' . $filterArc . '%';
}

if (!empty($filterClient)) {
    $query .= ' AND c.nom_societe ILIKE ?';
    $params[] = '%' . $filterClient . '%';
}

// ── Pagination ─────────────────────────────────────────────────────────────
$itemsPerPage = 10;
$currentPage = max(1, intval($_GET['page'] ?? 1));
$offset = ($currentPage - 1) * $itemsPerPage;

// IMPORTANT: Ajouter l'ordre AVANT de compter et de limiter
$query .= ' ORDER BY i.date_intervention DESC, i.created_at DESC';

// 1. Requête pour le total total (sans LIMIT)
$countQuery = 'SELECT COUNT(*) FROM (' . $query . ') AS total_items';
$countStmt = $db->prepare($countQuery);
$countStmt->execute($params);
$totalItems = $countStmt->fetchColumn();
$totalPages = ceil($totalItems / $itemsPerPage);

// 2. Requête finale avec LIMIT et OFFSET
$paginatedQuery = $query . " LIMIT $itemsPerPage OFFSET $offset";
$stmt = $db->prepare($paginatedQuery);
$stmt->execute($params);
$interventions = $stmt->fetchAll();

// Stats sur le lot filtré complet (pas seulement la page)
$allFilteredQuery = 'SELECT statut FROM (' . $query . ') AS filtered_lot';
$allFilteredStmt = $db->prepare($allFilteredQuery);
$allFilteredStmt->execute($params);
$allFiltered = $allFilteredStmt->fetchAll();

$nbInterventions = count($allFiltered);
$terminees = count(array_filter($allFiltered, fn($i) => in_array(strtolower($i['statut']), ['terminee', 'terminée', 'envoyee', 'envoyée'])));
$brouillons = $nbInterventions - $terminees;

$clientsSet = [];
foreach ($interventions as $i) {
    $clientsSet[$i['nom_societe']] = true;
}
$nbClients = count($clientsSet);

?>
<!DOCTYPE html>
<html lang="fr">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0">
    <title>Historique des Interventions | LM Expert</title>
    <link rel="stylesheet" href="/assets/style.css">
    <link rel="manifest" href="/manifest.json">
    <meta name="theme-color" content="#020617">
    <?php renderSentryJS(); ?>
    <script>
        if (localStorage.getItem('theme') === 'light') document.documentElement.classList.add('light-mode');
    </script>
    <style>
        .hist-table {
            width: 100%;
            border-collapse: collapse;
        }

        .hist-table th {
            padding: 0.85rem 1rem;
            text-align: left;
            border-bottom: 2px solid var(--glass-border);
            color: var(--text-dim);
            font-size: 0.65rem;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            white-space: nowrap;
        }

        .hist-table td {
            padding: 0.9rem 1rem;
            border-bottom: 1px solid rgba(255, 255, 255, 0.03);
            font-size: 0.82rem;
            vertical-align: middle;
        }

        .hist-table tr:hover td {
            background: var(--primary-subtle);
        }

        .avatar {
            width: 32px;
            height: 32px;
            border-radius: 10px;
            background: linear-gradient(135deg, #0ea5e9, #6366f1);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 0.7rem;
            color: #fff;
            flex-shrink: 0;
            box-shadow: 0 4px 10px rgba(99, 102, 241, 0.3);
        }

        .filter-bar {
            display: flex;
            gap: 0.5rem;
            flex-wrap: nowrap;
            margin-bottom: 1.5rem;
            align-items: center;
        }

        .filter-bar select, .filter-bar input {
            padding: 0.5rem 0.6rem;
            font-size: 0.75rem;
            min-width: 0;
            background: rgba(255, 255, 255, 0.03);
            border: 1px solid var(--glass-border);
            border-radius: var(--radius-md);
            color: var(--text-main);
            outline: none;
            transition: var(--transition-fast);
            flex: 1; /* Force equal width and single line */
        }

        .filter-bar select:focus, .filter-bar input:focus {
            border-color: var(--primary);
            background: rgba(255, 255, 255, 0.08);
            box-shadow: 0 0 10px var(--primary-glow);
        }

        .filter-bar select option {
            background: #0f172a;
            color: white;
        }

        @media (max-width: 1024px) {
            .filter-bar {
                flex-wrap: wrap;
            }
            .filter-bar > * {
                flex: 1 1 calc(33% - 0.5rem);
            }
        }
        @media (max-width: 600px) {
            .filter-bar > * {
                flex: 1 1 100%;
            }
        }
        }

        .filter-bar select,
        .filter-bar input {
            background: rgba(15, 23, 42, 0.6);
            border: 1px solid var(--glass-border);
            border-radius: var(--radius-sm);
            color: var(--text-main);
            padding: 0.6rem 1rem;
            font-family: var(--font-main);
            font-size: 0.82rem;
            min-height: 44px;
            cursor: pointer;
        }

        .filter-bar select:focus,
        .filter-bar input:focus {
            outline: none;
            border-color: var(--primary);
        }

        .tag-arc {
            display: inline-block;
            padding: 0.15rem 0.55rem;
            background: rgba(14, 165, 233, 0.08);
            color: var(--accent-cyan);
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 700;
            font-family: var(--font-mono);
        }

        .status-badge {
            display: inline-block;
            padding: 0.2rem 0.6rem;
            border-radius: 20px;
            font-size: 0.65rem;
            font-weight: bold;
            text-transform: uppercase;
        }

        .status-terminee {
            background: rgba(34, 197, 94, 0.1);
            color: #22c55e;
            border: 1px solid rgba(34, 197, 94, 0.3);
        }

        .status-brouillon {
            background: rgba(245, 158, 11, 0.1);
            color: #f59e0b;
            border: 1px solid rgba(245, 158, 11, 0.3);
        }

        @media (max-width: 768px) {
            .filter-bar {
                flex-direction: column;
            }

            .filter-bar select,
            .filter-bar input {
                width: 100%;
            }

            .col-tech {
                display: none;
            }
        }
        .pagination {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 1.5rem;
            margin-top: 2rem;
            padding-top: 1.5rem;
            border-top: 1px solid var(--glass-border);
        }

        .pagination-link {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 0.6rem 1rem;
            background: rgba(255, 255, 255, 0.03);
            border: 1px solid var(--glass-border);
            border-radius: var(--radius-md);
            color: var(--text-main);
            text-decoration: none;
            font-size: 0.8rem;
            font-weight: 600;
            transition: all 0.2s;
        }

        .pagination-link:hover:not(.disabled) {
            background: var(--primary-subtle);
            border-color: var(--primary);
            color: var(--primary);
            transform: translateY(-2px);
        }

        .pagination-link.disabled {
            opacity: 0.3;
            cursor: not-allowed;
            pointer-events: none;
        }

        .pagination-info {
            font-size: 0.8rem;
            color: var(--text-dim);
            font-weight: 500;
        }
    </style>
</head>

<body>
    <header class="mobile-header">
        <button class="mobile-logo-btn" onclick="toggleSidebar()" aria-label="Menu">
            <img src="/assets/lenoir_logo_trans.svg" alt="Raoul Lenoir" class="mobile-header-logo">
        </button>
        <span class="mobile-header-title">Historique</span>
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
            <button class="sidebar-close-btn" onclick="toggleSidebar()">&times;</button>
            <div style="margin-bottom: 2.5rem;">
                <a href="<?= $isAdmin ? 'admin.php' : 'technicien.php' ?>" class="brand-icon"
                    style="display:block;width:180px;height:auto;margin:0 0 1rem 0;">
                    <img src="/assets/lenoir_logo_trans.svg">
                </a>
                <h2 style="font-size:1.15rem;"><span class="text-gradient">Raoul Lenoir</span></h2>
                <p style="font-size:0.7rem;color:var(--text-dim);text-transform:uppercase;">
                    <?= $isAdmin ? 'Administrateur' : 'Technicien' ?>
                </p>
            </div>

            <nav style="display:flex;flex-direction:column;gap:0.4rem;margin-bottom:2rem;">
                <a href="<?= $isAdmin ? 'admin.php' : 'technicien.php' ?>" class="btn btn-ghost sidebar-link"
                    style="justify-content:flex-start;padding:0.7rem 1.1rem;font-size:0.8rem;">
                    <img src="/assets/icon_dashboard_white.svg" style="height: 16px; width: 16px; margin-right: 8px;"> Tableau de bord
                </a>
                <?php if ($isAdmin): ?>
                    <a href="admin.php?new=1#" class="btn btn-ghost sidebar-link"
                        style="justify-content: flex-start; padding: 0.7rem 1.1rem; font-size: 0.8rem;">
                        <img src="/assets/icon_add_white.svg" style="height: 16px; width: 16px; margin-right: 8px;"> Nouveau Rapport
                    </a>
                <?php else: ?>
                    <a href="technicien.php?new=1#" class="btn btn-ghost sidebar-link"
                        style="justify-content: flex-start; padding: 0.7rem 1.1rem; font-size: 0.8rem;">
                        <img src="/assets/icon_add_white.svg" style="height: 16px; width: 16px; margin-right: 8px;"> Nouveau Rapport
                    </a>
                <?php endif; ?>
                <a href="historique.php" class="btn btn-primary sidebar-link"
                    style="justify-content:flex-start;padding:0.7rem 1.1rem;font-size:0.8rem;">
                    <img src="/assets/icon_history_white.svg" style="height: 16px; width: 16px; margin-right: 8px;"> Historique
                </a>
                <?php if ($isAdmin): ?>
                    <a href="equipe.php" class="btn btn-ghost sidebar-link"
                        style="justify-content:flex-start;padding:0.7rem 1.1rem;font-size:0.8rem;">
                        <img src="/assets/icon_profile_blue.svg" style="height: 16px; width: 16px; margin-right: 8px;"> Équipe
                    </a>
                <?php endif; ?>
                <a href="assistant.php" class="btn btn-ghost sidebar-link"
                    style="justify-content:flex-start;padding:0.7rem 1.1rem;font-size:0.8rem;">
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
            <div style="display:flex;align-items:center;gap:1rem;margin-bottom:1.5rem;" class="animate-in">
                <h1
                    style="font-size:1.4rem;background:linear-gradient(135deg,var(--primary),var(--primary-light));-webkit-background-clip:text;background-clip:text;-webkit-text-fill-color:transparent;">
                    Historique des Interventions
                </h1>
            </div>

            <!-- Stats rapides -->
            <div class="stats-grid animate-in" style="margin-bottom:1.5rem;">
                <div class="stat-item glass">
                    <span class="stat-label">Total Fiches</span>
                    <span class="stat-value">
                        <?= $nbInterventions ?>
                    </span>
                    <span style="font-size:0.65rem;color:var(--text-dim);margin-top:0.4rem;">
                        <?= $labelPeriod ?>
                    </span>
                </div>
                <div class="stat-item glass">
                    <span class="stat-label">Terminées</span>
                    <span class="stat-value" style="color:var(--success);">
                        <?= $terminees ?>
                    </span>
                </div>
                <div class="stat-item glass">
                    <span class="stat-label">Brouillons</span>
                    <span class="stat-value" style="color:var(--warning);">
                        <?= $brouillons ?>
                    </span>
                </div>
                <div class="stat-item glass">
                    <span class="stat-label">Clients Affectés</span>
                    <span class="stat-value">
                        <?= $nbClients ?>
                    </span>
                </div>
            </div>

            <!-- Filtres -->
            <div class="card glass animate-in-delay-1" style="padding:1.5rem;margin-bottom:1.5rem;">
                <form method="GET" class="filter-bar" id="filterForm" autocomplete="off">
                    <select name="period" onchange="this.form.submit()">
                        <option value="all" <?= $filterPeriod === 'all' ? 'selected' : '' ?>>Tout l'historique</option>
                        <option value="today" <?= $filterPeriod === 'today' ? 'selected' : '' ?>>Aujourd'hui</option>
                        <option value="current" <?= $filterPeriod === 'current' ? 'selected' : '' ?>>Semaine en cours
                        </option>
                        <option value="last" <?= $filterPeriod === 'last' ? 'selected' : '' ?>>Semaine précédente</option>
                        <option value="month" <?= $filterPeriod === 'month' ? 'selected' : '' ?>>Ce mois</option>
                    </select>

                    <?php if ($isAdmin): ?>
                        <select name="user" onchange="this.form.submit()">
                            <option value="0">Tous les techniciens</option>
                            <?php foreach ($allUsers as $u): ?>
                                <option value="<?= $u['id'] ?>" <?= $filterUser == $u['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($u['prenom'] . ' ' . $u['nom']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    <?php endif; ?>

                    <select name="statut" onchange="this.form.submit()">
                        <option value="all" <?= $filterStatut === 'all' ? 'selected' : '' ?>>Tous les états</option>
                        <option value="brouillon" <?= $filterStatut === 'brouillon' ? 'selected' : '' ?>>Brouillon</option>
                        <option value="terminee" <?= $filterStatut === 'terminee' ? 'selected' : '' ?>>Terminée</option>
                        <option value="envoyee" <?= $filterStatut === 'envoyee' ? 'selected' : '' ?>>Envoyée</option>
                    </select>

                    <input type="text" name="arc" placeholder="Filtrer par ARC..."
                        value="<?= htmlspecialchars($filterArc) ?>" maxlength="50"
                        style="flex:1;min-width:150px;">

                    <input type="text" name="client" placeholder="Filtrer par Client..."
                        value="<?= htmlspecialchars($filterClient) ?>" maxlength="50"
                        style="flex:1;min-width:150px;">

                    <button type="submit" class="btn btn-primary" style="padding:0.6rem 1.25rem;font-size:0.8rem;">Filtrer</button>
                    
                    <?php if ($filterUser || $filterArc || $filterClient || $filterStatut !== 'all' || $filterPeriod !== 'all'): ?>
                        <a href="historique.php" class="btn btn-ghost" style="padding:0.6rem 1rem;font-size:0.8rem;">Effacer</a>
                    <?php endif; ?>
                </form>
            </div>

            <div class="card glass animate-in-delay-2" style="padding:0;overflow:hidden;">
                <div
                    style="padding:1.25rem 1.5rem;border-bottom:1px solid var(--glass-border);display:flex;justify-content:space-between;align-items:center;">
                    <h3 style="font-size:1rem;">Détail des documents <span
                            style="font-size:0.75rem;color:var(--text-dim);font-weight:400;margin-left:0.5rem;">
                            <?= $totalItems ?> interventions
                        </span></h3>
                </div>

                <?php if (empty($interventions)): ?>
                    <div style="padding:3rem;text-align:center;color:var(--text-dim);">
                        <div style="font-size: 60px; margin-bottom: 1rem; opacity: 0.3; text-align: center;"><img src="/assets/icon_gear_orange.svg" style="height: 60px; width: 60px;"></div>
                        <p>Aucune intervention pour cette période.</p>
                    </div>
                <?php else: ?>
                    <div class="table-scroll-wrapper" style="overflow-x:auto;">
                        <table class="hist-table">
                            <thead>
                                <tr>
                                    <?php if ($isAdmin): ?>
                                        <th class="col-tech">Technicien</th>
                                    <?php endif; ?>
                                    <th>Date</th>
                                    <th>N° ARC</th>
                                    <th>Client</th>
                                    <th style="text-align:center;">Statut</th>
                                    <th style="text-align:center;">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($interventions as $i): ?>
                                    <tr ondblclick="window.location.href='intervention_edit.php?id=<?= $i['id'] ?>';"
                                        style="cursor: pointer;" title="Double-clic pour ouvrir">
                                        <?php if ($isAdmin): ?>
                                            <td class="col-tech">
                                                    <?php 
                                                        $techInitiales = strtoupper(substr($i['tech_prenom'] ?? '', 0, 1) . substr($i['tech_nom'] ?? '', 0, 1));
                                                        $gradients = [
                                                            'linear-gradient(135deg, #0ea5e9, #6366f1)',
                                                            'linear-gradient(135deg, #f59e0b, #ef4444)',
                                                            'linear-gradient(135deg, #10b981, #059669)',
                                                            'linear-gradient(135deg, #8b5cf6, #d946ef)'
                                                        ];
                                                        $gradIndex = (isset($i['technicien_id']) ? $i['technicien_id'] : 0) % count($gradients);
                                                        $grad = $gradients[$gradIndex];
                                                    ?>
                                                    <?php if (!empty($i['avatar_base64'])): ?>
                                                        <img src="<?= htmlspecialchars($i['avatar_base64']) ?>" class="avatar"
                                                            style="object-fit: cover; width: 32px; height: 32px; border-radius: 8px;">
                                                    <?php else: ?>
                                                        <div class="avatar" style="width: 32px; height: 32px; border-radius: 8px; display: flex; align-items: center; justify-content: center; background: <?= $grad ?>; color: white; font-size: 0.7rem; font-weight: bold; box-shadow: 0 4px 8px rgba(0,0,0,0.2);">
                                                            <?= $techInitiales ?: '??' ?>
                                                        </div>
                                                    <?php endif; ?>
                                                    <span style="font-weight:600; font-size: 0.8rem;">
                                                        <?= htmlspecialchars(($i['tech_prenom'] ?? '') . ' ' . ($i['tech_nom'] ?? '')) ?>
                                                    </span>
                                                </div>
                                            </td>
                                        <?php endif; ?>
                                        <td style="font-family:var(--font-mono);font-size:0.78rem;color:var(--text-muted);">
                                            <?= date('d/m/Y', strtotime($i['date_intervention'])) ?>
                                        </td>
                                        <td><span class="tag-arc">
                                                <?= htmlspecialchars($i['numero_arc']) ?>
                                            </span></td>
                                        <td style="font-weight:600;">
                                            <?= htmlspecialchars($i['nom_societe']) ?>
                                        </td>
                                        <td style="text-align:center;">
                                            <span
                                                class="status-badge <?= in_array(strtolower($i['statut']), ['terminee', 'terminée', 'envoyee', 'envoyée']) ? 'status-terminee' : 'status-brouillon' ?>">
                                                <?= in_array(strtolower($i['statut']), ['terminee', 'terminée', 'envoyee', 'envoyée']) ? 'Signée' : 'En Saisie' ?>
                                            </span>
                                        </td>
                                        <td style="white-space:nowrap;">
                                            <div style="display: grid; grid-template-columns: 36px 36px 36px; gap: 8px; justify-content: center; margin: 0 auto; width: max-content;">
                                                <div>
                                                    <?php if (in_array(strtolower($i['statut']), ['terminee', 'terminée', 'envoyee', 'envoyée'])): ?>
                                                        <a href="rapport_final.php?id=<?= $i['id'] ?>&msg=ok" class="btn btn-ghost"
                                                            style="width:36px; height:36px; padding:0; display:flex; align-items:center; justify-content:center; color: var(--success);"
                                                            title="Voir le rapport"><img src="/assets/icon_document_blue.svg" style="height: 18px; width: 18px;"></a>
                                                    <?php endif; ?>
                                                </div>
                                                <div>
                                                    <a href="intervention_edit.php?id=<?= $i['id'] ?>" class="btn btn-ghost"
                                                        style="width:36px; height:36px; padding:0; display:flex; align-items:center; justify-content:center;"
                                                        title="Modifier l'intervention"><img src="/assets/icon_edit_orange.svg" style="height: 18px; width: 18px;"></a>
                                                </div>
                                                <div>
                                                    <a href="#"
                                                        onclick="if(confirm('🚨 ATTENTION : Supprimer cette fiche ARC <?= htmlspecialchars($i['numero_arc']) ?> définitivement ainsi que toutes ses fiches machines ? Cette action est irréversible.')) window.location.href='delete_intervention.php?id=<?= $i['id'] ?>&csrf_token=<?= getCsrfToken() ?>';"
                                                        class="btn btn-ghost"
                                                        style="width:36px; height:36px; padding:0; display:flex; align-items:center; justify-content:center; color: var(--error);"
                                                        title="Supprimer"><img src="/assets/icon_delete_red.svg" style="height: 18px; width: 18px;"></a>
                                                </div>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <?php if ($totalPages > 1): ?>
                        <?php
                        $queryParams = $_GET;
                        unset($queryParams['page']);
                        $baseQuery = http_build_query($queryParams);
                        if ($baseQuery) $baseQuery .= '&';
                        ?>
                        <div class="pagination" style="padding: 1.5rem; display: flex; align-items: center; justify-content: center; gap: 2rem; border-top: 1px solid var(--glass-border);">
                            <?php if ($currentPage > 1): ?>
                                <a href="historique.php?<?= $baseQuery ?>page=<?= $currentPage - 1 ?>" class="pagination-link">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="m15 18-6-6 6-6"/></svg>
                                    Précédent
                                </a>
                            <?php else: ?>
                                <span class="pagination-link disabled" style="opacity: 0.3; cursor: not-allowed;">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="m15 18-6-6 6-6"/></svg>
                                    Précédent
                                </span>
                            <?php endif; ?>

                            <div style="font-size: 0.85rem; font-weight: 700; color: var(--text-dim);">
                                Page <span style="color: var(--primary);"><?= $currentPage ?></span> sur <?= $totalPages ?>
                            </div>

                            <?php if ($currentPage < $totalPages): ?>
                                <a href="historique.php?<?= $baseQuery ?>page=<?= $currentPage + 1 ?>" class="pagination-link">
                                    Suivant
                                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="m9 18 6-6-6-6"/></svg>
                                </a>
                            <?php else: ?>
                                <span class="pagination-link disabled" style="opacity: 0.3; cursor: not-allowed;">
                                    Suivant
                                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="m9 18 6-6-6-6"/></svg>
                                </span>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
            <div class="app-footer">
                Raoul Lenoir SAS &middot; <a href="privacy.php" style="color:inherit;text-decoration:underline;">RGPD
                    &amp; Confidentialité</a>
            </div>
        </main>
    </div>

    <nav class="mobile-bottom-nav">
        <div class="mobile-bottom-nav-inner">
            <a href="<?= $isAdmin ? 'admin.php' : 'technicien.php' ?>" class="mobile-nav-item">
                <img src="/assets/icon_dashboard_white.svg" style="height: 24px; width: 24px; margin-bottom: 4px; opacity: 0.7;">
                <span class="mobile-nav-label">Tableau</span>
            </a>
            <a href="historique.php" class="mobile-nav-item active">
                <img src="/assets/icon_history_white.svg" style="height: 24px; width: 24px; margin-bottom: 4px; opacity: 0.7;">
                <span class="mobile-nav-label">Historique</span>
            </a>
            <a href="profile.php" class="mobile-nav-item">
                <img src="/assets/icon_profile_blue.svg" style="height: 24px; width: 24px; margin-bottom: 4px; opacity: 0.7;">
                <span class="mobile-nav-label">Profil</span>
            </a>
            <a href="assistant.php" class="mobile-nav-item">
                <div style="width: 24px; height: 24px; display: flex; align-items: center; justify-content: center; margin-bottom: 4px;">
                    <img src="/assets/ai_expert.jpg" style="height: 22px; width: 22px; border-radius: 4px; object-fit: cover;">
                </div>
                <span class="mobile-nav-label">Expert IA</span>
            </a>
        </div>
    </nav>
    <script>
        function toggleSidebar() {
            document.getElementById('sidebar').classList.toggle('open');
            document.getElementById('sidebarOverlay').classList.toggle('open');
            document.body.classList.toggle('sidebar-is-open');
        }
    </script>
</body>

</html>