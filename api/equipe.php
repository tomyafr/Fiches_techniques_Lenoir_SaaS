<?php
require_once __DIR__ . '/../includes/config.php';
requireAuth('admin');
$db = getDB();

$viewId = isset($_GET['id']) ? (int)$_GET['id'] : null;
$viewUser = null;
$viewReports = [];

if ($viewId) {
    $stmtUser = $db->prepare('SELECT id, nom, prenom, role, actif, avatar_base64 FROM users WHERE id = ?');
    $stmtUser->execute([$viewId]);
    $viewUser = $stmtUser->fetch();
    
    if ($viewUser) {
        $stmtReports = $db->prepare("
            SELECT i.id, i.numero_arc, c.nom_societe, i.date_intervention, i.statut,
            (SELECT json_agg(m.mesures->>'temps_realise') FROM machines m WHERE m.intervention_id = i.id) as temps_array
            FROM interventions i
            JOIN clients c ON i.client_id = c.id
            WHERE i.technicien_id = ?
            ORDER BY i.date_intervention DESC
        ");
        $stmtReports->execute([$viewId]);
        $viewReports = $stmtReports->fetchAll();
    }
}

$equipe = [];
if (!$viewUser) {
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
}
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
                <a href="admin.php?tab=nouvelle" class="btn btn-ghost sidebar-link"
                    style="justify-content: flex-start; padding: 0.7rem 1.1rem; font-size: 0.8rem; text-decoration: none; color: inherit;">
                    <img src="/assets/icon_add_white.svg" style="height: 16px; width: 16px; margin-right: 8px;"> Nouveau Rapport
                </a>
                <a href="historique.php" class="btn btn-ghost sidebar-link"
                    style="justify-content:flex-start;padding:0.7rem 1.1rem;font-size:0.8rem;">
                    <img src="/assets/icon_history_white.svg" style="height: 16px; width: 16px; margin-right: 8px;"> Historique
                </a>
                <a href="equipe.php" class="btn btn-primary sidebar-link"
                    style="justify-content:flex-start;padding:0.7rem 1.1rem;font-size:0.8rem;">
                    <img src="/assets/icon_team_blue.svg" style="height: 16px; width: 16px; margin-right: 8px;"> Équipe
                </a>
                <a href="assistant.php" class="btn btn-ghost sidebar-link"
                    style="justify-content:flex-start;padding:0.7rem 1.1rem;font-size:0.8rem;">
                    <img src="/assets/ai_expert.jpg" style="height: 18px; width: 18px; margin-right: 8px; margin-left: -2px; mix-blend-mode: screen;"> Expert IA
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
            <?php if ($viewUser): ?>
                <?php
                // Calculer les totaux pour le résumé
                $totalTimeMinutesAll = 0;
                $totalReports = count($viewReports);
                foreach ($viewReports as $r) {
                    $arr = json_decode($r['temps_array'] ?? '[]', true);
                    if (is_array($arr)) {
                        foreach ($arr as $t) {
                            $t = trim($t ?? '');
                            if (empty($t)) continue;
                            if (strpos($t, 'h') !== false) {
                                $parts = explode('h', $t);
                                $totalTimeMinutesAll += ((int)$parts[0] * 60) + (int)($parts[1] ?? 0);
                            } else {
                                $totalTimeMinutesAll += round((float)$t * 60);
                            }
                        }
                    }
                }
                $th = floor($totalTimeMinutesAll / 60);
                $tm = $totalTimeMinutesAll % 60;
                $totalTimeString = $th > 0 || $tm > 0 ? $th . 'h' . str_pad((string)$tm, 2, '0', STR_PAD_LEFT) : '0h00';
                ?>
                <div class="animate-in">
                    <a href="equipe.php" class="btn btn-ghost" style="margin-bottom: 1.5rem; color: var(--text-dim); display: inline-flex; padding: 0.5rem 1rem;">
                        ← Retour à l'équipe
                    </a>
                    
                    <div class="card glass" style="padding: 2rem; margin-bottom: 2.5rem; border-top: 4px solid var(--primary);">
                        <div style="display: flex; align-items: center; gap: 1.5rem; margin-bottom: 2rem;">
                            <?php if (!empty($viewUser['avatar_base64'])): ?>
                                <img src="<?= htmlspecialchars($viewUser['avatar_base64']) ?>" style="width: 80px; height: 80px; border-radius: 16px; object-fit: cover; border: 2px solid var(--glass-border);">
                            <?php else: ?>
                                <div style="width: 80px; height: 80px; border-radius: 16px; background: linear-gradient(135deg, #0ea5e9, #6366f1); display: flex; align-items: center; justify-content: center; font-size: 2rem; font-weight: 800; color: #fff; box-shadow: 0 8px 16px rgba(0,0,0,0.2);">
                                    <?= strtoupper(substr($viewUser['prenom'], 0, 1) . substr($viewUser['nom'], 0, 1)) ?>
                                </div>
                            <?php endif; ?>
                            <div>
                                <h2 style="font-size: 1.8rem; margin-bottom: 0.3rem;"><?= htmlspecialchars($viewUser['prenom'] . ' ' . $viewUser['nom']) ?></h2>
                                <span style="font-size: 0.8rem; padding: 0.3rem 0.8rem; border-radius: 6px; background: rgba(255, 179, 0, 0.2); color: var(--primary); font-weight: 700;">
                                    <?= htmlspecialchars(strtoupper($viewUser['role'])) ?>
                                </span>
                            </div>
                        </div>

                        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1.5rem;">
                            <div style="background: rgba(0,0,0,0.3); padding: 1.5rem; border-radius: 12px; text-align: center; border: 1px solid var(--glass-border);">
                                <div style="color: var(--text-dim); font-size: 0.9rem; margin-bottom: 0.5rem;">Total des Rapports</div>
                                <div style="font-size: 2.5rem; font-weight: bold; color: var(--primary); text-shadow: 0 0 15px rgba(244,130,32,0.3);"><?= $totalReports ?></div>
                            </div>
                            <div style="background: rgba(0,0,0,0.3); padding: 1.5rem; border-radius: 12px; text-align: center; border: 1px solid var(--glass-border);">
                                <div style="color: var(--text-dim); font-size: 0.9rem; margin-bottom: 0.5rem;">Temps Total Cumulé</div>
                                <div style="font-size: 2.5rem; font-weight: bold; color: #10b981; text-shadow: 0 0 15px rgba(16,185,129,0.3);"><?= $totalTimeString ?></div>
                            </div>
                        </div>
                    </div>

                    <h3 style="margin-bottom: 1.5rem; color: var(--text-dim); font-size: 0.9rem; text-transform: uppercase; letter-spacing: 1px;">Historique de ses rapports</h3>
                    <?php if (empty($viewReports)): ?>
                        <div class="card glass" style="padding: 3rem; text-align: center; color: var(--text-dim); border-radius: 12px;">Aucun rapport trouvé pour ce technicien.</div>
                    <?php else: ?>
                        <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(320px, 1fr)); gap: 1.5rem;">
                            <?php foreach ($viewReports as $i): ?>
                                <?php
                                $tMins = 0;
                                $tArr = json_decode($i['temps_array'] ?? '[]', true);
                                if (is_array($tArr)) {
                                    foreach ($tArr as $t) {
                                        $t = trim($t ?? '');
                                        if (empty($t)) continue;
                                        if (strpos($t, 'h') !== false) {
                                            $parts = explode('h', $t);
                                            $tMins += ((int)$parts[0] * 60) + (int)($parts[1] ?? 0);
                                        } else {
                                            $tMins += round((float)$t * 60);
                                        }
                                    }
                                }
                                $displayTime = '--';
                                if ($tMins > 0) {
                                    $h = floor($tMins / 60);
                                    $m = $tMins % 60;
                                    $displayTime = $h . 'h' . str_pad((string)$m, 2, '0', STR_PAD_LEFT);
                                }
                                $isTerminee = in_array(strtolower($i['statut']), ['terminee', 'terminée', 'envoyee', 'envoyée']);
                                ?>
                                <a href="intervention_edit.php?id=<?= $i['id'] ?>" class="card glass" style="padding: 1.5rem; text-decoration: none; color: inherit; display: flex; flex-direction: column; gap: 1rem; border-left: 4px solid <?= $isTerminee ? 'var(--success)' : 'var(--warning)' ?>; transition: transform 0.2s, box-shadow 0.2s;" onmouseover="this.style.transform='translateY(-4px)'; this.style.boxShadow='0 12px 24px rgba(0,0,0,0.4)';" onmouseout="this.style.transform='none'; this.style.boxShadow='var(--glass-shadow)';">
                                    
                                    <div style="display: flex; justify-content: space-between; align-items: flex-start;">
                                        <span class="tag-arc" style="font-size: 0.75rem; padding: 0.25rem 0.6rem; border-radius: 4px; background: rgba(255,255,255,0.1); color: white; font-weight: 600; letter-spacing: 0.5px;">
                                            <?= htmlspecialchars($i['numero_arc']) ?>
                                        </span>
                                        <span class="status-badge <?= $isTerminee ? 'status-terminee' : 'status-brouillon' ?>" style="font-size: 0.7rem;">
                                            <?= htmlspecialchars($i['statut']) ?>
                                        </span>
                                    </div>
                                    
                                    <div>
                                        <h4 style="font-size: 1.15rem; color: white; margin: 0 0 0.4rem 0; font-weight: 600; line-height: 1.3; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; text-overflow: ellipsis;">
                                            <?= htmlspecialchars($i['nom_societe']) ?>
                                        </h4>
                                    </div>
                                    
                                    <div style="margin-top: auto; padding-top: 1rem; border-top: 1px solid rgba(255,255,255,0.06); display: flex; justify-content: space-between; align-items: center;">
                                        <div style="display: flex; align-items: center; gap: 0.5rem; color: var(--text-dim); font-size: 0.85rem; font-family: var(--font-mono);">
                                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line></svg>
                                            <?= date('d M Y', strtotime($i['date_intervention'])) ?>
                                        </div>
                                        <div style="display: flex; align-items: center; gap: 0.5rem; color: #10b981; font-weight: 600; font-size: 0.9rem; background: rgba(16, 185, 129, 0.1); padding: 0.2rem 0.6rem; border-radius: 4px;">
                                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>
                                            <?= $displayTime ?>
                                        </div>
                                    </div>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            <?php else: ?>
            <div style="display:grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 1.5rem;"
                class="animate-in">
                <?php foreach ($equipe as $membre): ?>
                    <a href="equipe.php?id=<?= $membre['id'] ?>" class="card glass" style="padding: 1.5rem; display: flex; flex-direction: column; text-decoration: none; color: inherit; transition: transform 0.2s, border-color 0.2s; cursor: pointer;" onmouseover="this.style.transform='translateY(-5px)'; this.style.borderColor='var(--primary)'" onmouseout="this.style.transform='none'; this.style.borderColor='var(--glass-border)'">
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
                    </a>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </main>
    </div>

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
                <img src="/assets/icon_team_blue.svg" style="height: 24px; width: 24px; margin-bottom: 4px; opacity: 0.7;">
                <span class="mobile-nav-label">Équipe</span>
            </a>
            <a href="profile.php" class="mobile-nav-item">
                <img src="/assets/icon_profile_blue.svg" style="height: 24px; width: 24px; margin-bottom: 4px; opacity: 0.7;">
                <span class="mobile-nav-label">Profil</span>
            </a>
            <a href="assistant.php" class="mobile-nav-item">
                <div style="width: 24px; height: 24px; display: flex; align-items: center; justify-content: center; margin-bottom: 4px;">
                    <img src="/assets/ai_expert.jpg" style="height: 22px; width: 22px; object-fit: cover; mix-blend-mode: screen;">
                </div>
                <span class="mobile-nav-label">Expert IA</span>
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