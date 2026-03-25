<?php
require_once __DIR__ . '/../includes/config.php';
requireAuth('admin');
$db = getDB();

$stmtUsers = $db->prepare('
    SELECT 
        u.id, 
        u.nom, 
        u.prenom, 
        u.role,
        u.actif,
        u.created_at,
        (SELECT COUNT(*) FROM interventions i WHERE i.technicien_id = u.id) as total_interventions,
        (SELECT date_intervention FROM interventions i WHERE i.technicien_id = u.id ORDER BY date_intervention DESC LIMIT 1) as derniere_intervention
    FROM users u
    WHERE u.actif = TRUE
    ORDER BY u.role DESC, u.nom ASC
');
$stmtUsers->execute();
$equipe = $stmtUsers->fetchAll();
?>
<!DOCTYPE html>
<html lang="fr">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0">
    <title>Mon Équipe | LM Expert</title>
    <link rel="stylesheet" href="/assets/style.css">
    <link rel="manifest" href="/manifest.json">
    <link rel="apple-touch-icon" href="/assets/icon-192.png">
    <meta name="theme-color" content="#020617">
</head>

<body>
    <header class="mobile-header">
        <button class="mobile-logo-btn" onclick="toggleSidebar()" aria-label="Menu">
            <img src="/assets/logo-raoul-lenoir.svg" alt="Raoul Lenoir" class="mobile-header-logo"
                style="filter:brightness(0) saturate(100%) invert(73%) sepia(86%) saturate(1063%) hue-rotate(358deg) brightness(101%) contrast(106%);">
        </button>
        <span class="mobile-header-title">Équipe Techniciens</span>
        <span class="mobile-header-user"><?= htmlspecialchars($_SESSION['user_prenom']) ?></span>
    </header>
    <div class="sidebar-overlay" id="sidebarOverlay" onclick="toggleSidebar()"></div>

    <div class="dashboard-layout">
        <aside class="sidebar" id="sidebar">
            <button class="sidebar-close-btn" onclick="toggleSidebar()">&times;</button>
            <div style="margin-bottom: 2.5rem;">
                <a href="admin.php" class="brand-icon"
                    style="display: block; width: 180px; height: auto; margin: 0 0 1rem 0;"><img
                        src="/assets/logo-raoul-lenoir.svg"></a>
                <h2 style="font-size: 1.15rem;"><span class="text-gradient">Raoul Lenoir</span></h2>
                <p style="font-size: 0.7rem; color: var(--text-dim); text-transform: uppercase;">Administrateur</p>
            </div>
            <nav style="display:flex;flex-direction:column;gap:0.4rem;margin-bottom:2rem;">
                <a href="admin.php" class="btn btn-ghost sidebar-link"
                    style="justify-content:flex-start;padding:0.7rem 1.1rem;font-size:0.8rem;">
                    <img src="/assets/icons/dashboard.png" class="premium-icon"> Tableau de bord
                </a>
                <a href="admin.php?new=1#" class="btn btn-ghost sidebar-link"
                    style="justify-content: flex-start; padding: 0.7rem 1.1rem; font-size: 0.8rem;">
                    <img src="/assets/icons/add.png" class="premium-icon"> Nouvelle Fiche
                </a>
                <a href="historique.php" class="btn btn-ghost sidebar-link"
                    style="justify-content:flex-start;padding:0.7rem 1.1rem;font-size:0.8rem;">
                    <img src="/assets/icons/history.png" class="premium-icon"> Historique
                </a>
                <a href="equipe.php" class="btn btn-primary sidebar-link"
                    style="justify-content:flex-start;padding:0.7rem 1.1rem;font-size:0.8rem;">
                    <img src="/assets/icons/team.png" class="premium-icon"> Équipe
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
                    <img src="/assets/icons/error.png" class="premium-icon"> Se déconnecter
                </a>
                <a href="profile.php" class="btn btn-ghost sidebar-link"
                    style="width: 100%; justify-content: flex-start; padding: 0.7rem 1.1rem; font-size: 0.8rem;">
                    <img src="/assets/icons/profile.png" class="premium-icon"> Mon Profil
                </a>
            </div>
        </aside>

        <main class="main-content">
            <div style="display:grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 1.5rem;"
                class="animate-in">
                <?php foreach ($equipe as $membre): ?>
                    <div class="card glass" style="padding: 1.5rem; display: flex; flex-direction: column;">
                        <div style="display: flex; align-items: center; gap: 1rem; margin-bottom: 1.5rem;">
                            <div
                                style="width: 50px; height: 50px; border-radius: 50%; border: 1px solid var(--primary); display: flex; align-items: center; justify-content: center; font-size: 1.25rem; font-weight: 800; color: var(--primary);">
                                <?= strtoupper(substr($membre['prenom'], 0, 1) . substr($membre['nom'], 0, 1)) ?>
                            </div>
                            <div>
                                <h3 style="font-size: 1.15rem; margin-bottom: 0.2rem;">
                                    <?= htmlspecialchars($membre['prenom'] . ' ' . $membre['nom']) ?>
                                </h3>
                                <span
                                    style="font-size: 0.6rem; padding: 0.2rem 0.5rem; border-radius: 4px; background: rgba(255, 179, 0, 0.2); color: var(--primary);">
                                    <?= htmlspecialchars(strtoupper($membre['role'])) ?>
                                </span>
                            </div>
                        </div>

                        <div
                            style="background: rgba(0, 0, 0, 0.2); padding: 1rem; border-radius: 8px; margin-bottom: 1rem;">
                            <div style="display:flex; justify-content:space-between; margin-bottom: 0.5rem;">
                                <span style="color:var(--text-dim); font-size:0.75rem;">Fiches créées :</span>
                                <span
                                    style="font-weight:bold; color:var(--primary);"><?= $membre['total_interventions'] ?></span>
                            </div>
                            <div style="display:flex; justify-content:space-between;">
                                <span style="color:var(--text-dim); font-size:0.75rem;">Dernière intervention :</span>
                                <span
                                    style="font-size:0.8rem;"><?= $membre['derniere_intervention'] ? date('d/m/Y', strtotime($membre['derniere_intervention'])) : 'Aucune' ?></span>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </main>
    </div>

    <script>
        function toggleSidebar() {
            document.getElementById('sidebar').classList.toggle('open');
            document.getElementById('sidebarOverlay').classList.toggle('open');
        }
    </script>
</body>

</html>