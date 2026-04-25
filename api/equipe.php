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
        u.avatar_base64,
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
            <img src="/assets/lenoir_logo_trans.svg" alt="Raoul Lenoir" class="mobile-header-logo">
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
                        src="/assets/lenoir_logo_trans.svg"></a>
                <h2 style="font-size: 1.15rem;"><span class="text-gradient">Raoul Lenoir</span></h2>
                <p style="font-size: 0.7rem; color: var(--text-dim); text-transform: uppercase;">Administrateur</p>
            </div>
            <nav style="display:flex;flex-direction:column;gap:0.4rem;margin-bottom:2rem;">
                <a href="admin.php" class="btn btn-ghost sidebar-link"
                    style="justify-content:flex-start;padding:0.7rem 1.1rem;font-size:0.8rem;">
                    <img src="/assets/icon_dashboard_white.svg" style="height: 16px; width: 16px; margin-right: 8px;"> Tableau de bord
                </a>
                <a href="admin.php?new=1#" class="btn btn-ghost sidebar-link"
                    style="justify-content: flex-start; padding: 0.7rem 1.1rem; font-size: 0.8rem;">
                    <img src="/assets/icon_add_white.svg" style="height: 16px; width: 16px; margin-right: 8px;"> Nouvelle Fiche
                </a>
                <a href="historique.php" class="btn btn-ghost sidebar-link"
                    style="justify-content:flex-start;padding:0.7rem 1.1rem;font-size:0.8rem;">
                    <img src="/assets/icon_history_white.svg" style="height: 16px; width: 16px; margin-right: 8px;"> Historique
                </a>
                <a href="equipe.php" class="btn btn-primary sidebar-link"
                    style="justify-content:flex-start;padding:0.7rem 1.1rem;font-size:0.8rem;">
                    <img src="/assets/icon_profile_blue.svg" style="height: 16px; width: 16px; margin-right: 8px;"> Équipe
                </a>
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
            <div style="display:grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 1.5rem;"
                class="animate-in">
                <?php foreach ($equipe as $membre): ?>
                    <div class="card glass" style="padding: 1.5rem; display: flex; flex-direction: column;">
                        <div style="display: flex; align-items: center; gap: 1rem; margin-bottom: 1.5rem;">
                            <?php if (!empty($membre['avatar_base64'])): ?>
                                <img src="<?= htmlspecialchars($membre['avatar_base64']) ?>" 
                                      style="width: 50px; height: 50px; border-radius: 12px; object-fit: cover; border: 2px solid var(--glass-border);">
                            <?php else: ?>
                                <?php 
                                    $gradients = [
                                        'linear-gradient(135deg, #0ea5e9, #6366f1)',
                                        'linear-gradient(135deg, #f59e0b, #ef4444)',
                                        'linear-gradient(135deg, #10b981, #059669)',
                                        'linear-gradient(135deg, #8b5cf6, #d946ef)'
                                    ];
                                    $grad = $gradients[$membre['id'] % count($gradients)];
                                ?>
                                <div style="width: 50px; height: 50px; border-radius: 12px; background: <?= $grad ?>; display: flex; align-items: center; justify-content: center; font-size: 1.25rem; font-weight: 800; color: #fff; box-shadow: 0 8px 16px rgba(0,0,0,0.2);">
                                    <?= strtoupper(substr($membre['prenom'], 0, 1) . substr($membre['nom'], 0, 1)) ?>
                                </div>
                            <?php endif; ?>
                            <div style="position: relative;">
                                <h3 style="font-size: 1.15rem; margin-bottom: 0.2rem;">
                                    <?= htmlspecialchars($membre['prenom'] . ' ' . $membre['nom']) ?>
                                </h3>
                                <div style="display: flex; align-items: center; gap: 0.5rem;">
                                    <span style="font-size: 0.6rem; padding: 0.2rem 0.5rem; border-radius: 4px; background: rgba(255, 179, 0, 0.2); color: var(--primary); font-weight: 700;">
                                        <?= htmlspecialchars(strtoupper($membre['role'])) ?>
                                    </span>
                                    <span style="display: flex; align-items: center; gap: 4px; font-size: 0.65rem; color: var(--success); font-weight: 600;">
                                        <span style="width: 6px; height: 6px; background: var(--success); border-radius: 50%; display: inline-block;"></span>
                                        Actif
                                    </span>
                                </div>
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

    <!-- Bottom nav mobile -->
    <nav class="mobile-bottom-nav">
        <div class="mobile-bottom-nav-inner">
            <a href="admin.php" class="mobile-nav-item">
                <img src="/assets/icon_dashboard_white.svg" style="height: 24px; width: 24px; margin-bottom: 4px; opacity: 0.7;">
                <span class="mobile-nav-label">Tableau</span>
            </a>
            <a href="historique.php" class="mobile-nav-item">
                <img src="/assets/icon_history_white.svg" style="height: 24px; width: 24px; margin-bottom: 4px; opacity: 0.7;">
                <span class="mobile-nav-label">Historique</span>
            </a>
            <a href="equipe.php" class="mobile-nav-item active">
                <img src="/assets/icon_profile_blue.svg" style="height: 24px; width: 24px; margin-bottom: 4px; opacity: 0.7;">
                <span class="mobile-nav-label">Équipe</span>
            </a>
            <a href="profile.php" class="mobile-nav-item">
                <img src="/assets/icon_profile_blue.svg" style="height: 24px; width: 24px; margin-bottom: 4px; opacity: 0.7;">
                <span class="mobile-nav-label">Profil</span>
            </a>
        </div>
    </nav>

    <script>
        function toggleSidebar() {
            document.getElementById('sidebar').classList.toggle('open');
            document.getElementById('sidebarOverlay').classList.toggle('open');
        }
    </script>
</body>

</html>