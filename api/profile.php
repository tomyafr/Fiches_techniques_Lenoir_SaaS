<?php
require_once __DIR__ . '/../includes/config.php';
requireAuth(); // Force la connexion

$message = '';
$messageType = '';
$userId = $_SESSION['user_id'];
$db = getDB();

// Mettre à jour la BDD pour le statut if needed
try {
    $db->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS statut VARCHAR(20) DEFAULT 'actif'");
} catch (Exception $e) {
}

// Forcer le changement si flag actif
$forceChange = !empty($_GET['force']) || !empty($_SESSION['must_change_password']);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    // Vérification CSRF
    verifyCsrfToken();

    if ($_POST['action'] === 'change_password') {
        $oldPass = $_POST['old_password'] ?? '';
        $newPass = $_POST['new_password'] ?? '';
        $confirmPass = $_POST['confirm_password'] ?? '';

        $stmt = $db->prepare('SELECT password_hash FROM users WHERE id = ?');
        $stmt->execute([$userId]);
        $user = $stmt->fetch();

        if ($user && password_verify($oldPass, $user['password_hash'])) {
            // Vérifier la politique de mot de passe forte
            $policyErrors = validatePassword($newPass);
            if (!empty($policyErrors)) {
                $message = implode('<br>', $policyErrors);
                $messageType = "error";
            } elseif ($newPass !== $confirmPass) {
                $message = "Les nouveaux mots de passe ne correspondent pas.";
                $messageType = "error";
            } elseif ($newPass === $oldPass) {
                $message = "Le nouveau mot de passe doit être différent de l'ancien.";
                $messageType = "error";
            } else {
                $newHash = password_hash($newPass, PASSWORD_BCRYPT, ['cost' => 12]);
                $update = $db->prepare('UPDATE users SET password_hash = ?, must_change_password = FALSE WHERE id = ?');
                $update->execute([$newHash, $userId]);

                // Supprimer le flag de changement obligatoire
                $_SESSION['must_change_password'] = false;

                logAudit('PASSWORD_CHANGE', "User ID: $userId");
                $message = "✓ Mot de passe mis à jour avec succès.";
                $messageType = "success";
                $forceChange = false;
            }
        } else {
            $message = "L'ancien mot de passe est incorrect.";
            $messageType = "error";
        }
    } elseif ($_POST['action'] === 'update_avatar') {
        $base64 = $_POST['avatar_base64'] ?? '';
        if (!empty($base64)) {
            $stmt = $db->prepare('UPDATE users SET avatar_base64 = ? WHERE id = ?');
            $stmt->execute([$base64, $userId]);
            $_SESSION['avatar'] = $base64;
            $message = "✓ Photo de profil mise à jour avec succès.";
            $messageType = "success";
        }
    } elseif ($_POST['action'] === 'update_statut') {
        $newStatut = $_POST['statut'] ?? 'actif';
        if (in_array($newStatut, ['actif', 'pause', 'absent'])) {
            $stmt = $db->prepare('UPDATE users SET statut = ? WHERE id = ?');
            $stmt->execute([$newStatut, $userId]);
            $message = "✓ Statut mis à jour avec succès.";
            $messageType = "success";
        }
    } elseif ($_POST['action'] === 'update_signature') {
        $sigBase64 = $_POST['signature_base64'] ?? '';
        if (!empty($sigBase64)) {
            $stmt = $db->prepare('UPDATE users SET signature_base64 = ? WHERE id = ?');
            $stmt->execute([$sigBase64, $userId]);
            $message = "✓ Signature électronique enregistrée avec succès.";
            $messageType = "success";
        } else {
            $message = "Aucune signature fournie.";
            $messageType = "error";
        }
    } elseif ($_POST['action'] === 'delete_signature') {
        $stmt = $db->prepare('UPDATE users SET signature_base64 = NULL WHERE id = ?');
        $stmt->execute([$userId]);
        $message = "✓ Signature supprimée.";
        $messageType = "success";
    }
}

// Fetch current user
$stmtUser = $db->prepare('SELECT statut, signature_base64 FROM users WHERE id = ?');
$stmtUser->execute([$userId]);
$userCurrent = $stmtUser->fetch();
$currentStatut = $userCurrent ? $userCurrent['statut'] : 'actif';
$currentSignature = $userCurrent ? ($userCurrent['signature_base64'] ?? '') : '';
?>
<!DOCTYPE html>
<html lang="fr">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Mon Profil | LM Expert</title>
    <link rel="stylesheet" href="/assets/style.css">
    <style>
        .password-toggle {
            background: none;
            border: none;
            color: var(--text-dim);
            padding: 0.5rem;
            cursor: pointer;
            font-size: 1rem;
            transition: color 0.2s;
        }

        .password-toggle:hover {
            color: var(--primary);
        }

        .input-wrapper {
            position: relative;
            display: flex;
            align-items: center;
        }

        /* Indicateur de force du mot de passe */
        .strength-bar-container {
            margin-top: 0.5rem;
            height: 4px;
            background: rgba(255, 255, 255, 0.06);
            border-radius: 4px;
            overflow: hidden;
        }

        .strength-bar {
            height: 100%;
            border-radius: 4px;
            transition: width 0.3s ease, background 0.3s ease;
            width: 0%;
        }

        .strength-label {
            font-size: 0.65rem;
            margin-top: 0.3rem;
            font-weight: 600;
            letter-spacing: 0.05em;
            text-transform: uppercase;
        }

        .strength-0 {
            background: transparent;
        }

        .strength-1 {
            background: #ef4444;
            width: 25%;
        }

        .strength-2 {
            background: #f97316;
            width: 50%;
        }

        .strength-3 {
            background: #eab308;
            width: 75%;
        }

        .strength-4 {
            background: #22c55e;
            width: 100%;
        }

        .policy-list {
            margin: 0.75rem 0 0 0;
            padding: 0;
            list-style: none;
            display: flex;
            flex-direction: column;
            gap: 0.3rem;
        }

        .policy-list li {
            font-size: 0.7rem;
            color: var(--text-dim);
            display: flex;
            align-items: center;
            gap: 0.4rem;
            transition: color 0.2s;
        }

        .policy-list li .check {
            font-size: 0.75rem;
        }

        .policy-list li.ok {
            color: #22c55e;
        }

        .policy-list li.fail {
            color: var(--error);
        }

        .force-banner {
            margin: 0 0 2rem 0;
            padding: 1.25rem 1.5rem;
            background: rgba(239, 68, 68, 0.08);
            border: 1px solid rgba(239, 68, 68, 0.2);
            border-radius: var(--radius-md);
            color: #fca5a5;
            font-size: 0.9rem;
            display: flex;
            align-items: center;
            gap: 12px;
            backdrop-filter: blur(10px);
        }

        .force-banner strong {
            color: #ef4444;
        }
    </style>
    <script>
        if (localStorage.getItem('theme') === 'light') {
            document.documentElement.classList.add('light-mode');
        }
    </script>
</head>

<body>
    <style>
        @media (max-width: 1024px) {
            .mobile-header { display: flex !important; }
            .sidebar { padding-top: calc(var(--mobile-header-height) + 1rem) !important; }
            .main-content { padding-top: calc(var(--mobile-header-height) + 1.5rem) !important; }
        }
        @media (min-width: 1025px) {
            .mobile-header { display: none !important; }
        }
    </style>
    <!-- ═══ HEADER MOBILE ═══ -->
    <header class="mobile-header" style="z-index: 1001;">
        <button class="mobile-logo-btn"
            onclick="window.location.href='<?= $_SESSION['role'] === 'admin' ? 'admin.php' : 'technicien.php' ?>'"
            aria-label="Retour"
            style="background: none; border: none; padding: 0.5rem; display: flex; align-items: center; justify-content: center; z-index: 10;">
            <img src="/assets/icon_back_blue.svg" style="height: 24px; width: 24px;">
        </button>
        <span class="mobile-header-title">Mon Profil</span>
        <span class="mobile-header-user" style="z-index: 10;">
            <?php if (!empty($_SESSION['avatar'])): ?>
                <img src="<?= htmlspecialchars($_SESSION['avatar']) ?>"
                    style="width: 32px; height: 32px; border-radius: 50%; object-fit: cover; border: 1.5px solid var(--primary); box-shadow: 0 0 10px var(--primary-glow);">
            <?php else: ?>
                <div style="width: 32px; height: 32px; border-radius: 50%; background: var(--primary); color: #000; display: flex; align-items: center; justify-content: center; font-weight: 800; font-size: 0.75rem;">
                    <?= strtoupper(substr($_SESSION['user_prenom'], 0, 1)) ?>
                </div>
            <?php endif; ?>
        </span>
    </header>
    <div class="sidebar-overlay" id="sidebarOverlay" onclick="toggleSidebar()"></div>

    <div class="dashboard-layout">
        <aside class="sidebar" id="sidebar">
            <div style="margin-bottom: 2.5rem;">
                <!-- Logo cliquable vers le dashboard -->
                <a href="<?= $_SESSION['role'] === 'admin' ? 'admin.php' : 'technicien.php' ?>" class="brand-icon"
                    style="display: block; width: 180px; height: auto; margin: 0 0 1rem 0;">
                    <img src="/assets/lenoir_logo_trans.svg" alt="Raoul Lenoir">
                </a>
                <h2 style="font-size: 1.15rem;"><span class="text-gradient">Raoul Lenoir</span></h2>
                <p style="font-size: 0.7rem; color: var(--text-dim); text-transform: uppercase;">Mon Profil</p>
            </div>

            <nav style="display: flex; flex-direction: column; gap: 0.4rem; margin-bottom: 2rem;">
                <?php if (!$forceChange): ?>
                    <a href="<?= $_SESSION['role'] === 'admin' ? 'admin.php' : 'technicien.php' ?>" class="btn btn-ghost sidebar-link"
                        style="justify-content: flex-start; padding: 0.7rem 1.1rem; font-size: 0.8rem;">
                        <img src="/assets/icon_dashboard_white.svg" style="height: 16px; width: 16px; margin-right: 8px;"> Tableau de bord
                    </a>
                    
                    <?php if ($_SESSION['role'] === 'admin'): ?>
                    <a href="admin.php?new=1#" class="btn btn-ghost sidebar-link"
                        style="justify-content: flex-start; padding: 0.7rem 1.1rem; font-size: 0.8rem;">
                        <img src="/assets/icon_add_white.svg" style="height: 16px; width: 16px; margin-right: 8px;"> Nouvelle Fiche
                    </a>
                    <?php else: ?>
                    <a href="technicien.php?new=1#" class="btn btn-ghost sidebar-link"
                        style="justify-content: flex-start; padding: 0.7rem 1.1rem; font-size: 0.8rem;">
                        <img src="/assets/icon_add_white.svg" style="height: 16px; width: 16px; margin-right: 8px;"> Nouvelle Fiche
                    </a>
                    <?php endif; ?>

                    <a href="historique.php" class="btn btn-ghost sidebar-link"
                        style="justify-content: flex-start; padding: 0.7rem 1.1rem; font-size: 0.8rem;">
                        <img src="/assets/icon_history_white.svg" style="height: 16px; width: 16px; margin-right: 8px;"> Historique
                    </a>
                    <?php if ($_SESSION['role'] === 'admin'): ?>
                    <a href="equipe.php" class="btn btn-ghost sidebar-link"
                        style="justify-content: flex-start; padding: 0.7rem 1.1rem; font-size: 0.8rem;">
                        <img src="/assets/icon_profile_blue.svg" style="height: 16px; width: 16px; margin-right: 8px;"> Équipe
                    </a>
                    <?php endif; ?>
                    <a href="assistant.php" class="btn btn-ghost sidebar-link"
                        style="justify-content: flex-start; padding: 0.7rem 1.1rem; font-size: 0.8rem;">
                        <img src="/assets/ai_expert.jpg" style="height: 20px; width: 20px; margin-right: 8px; border-radius: 4px;"> Expert IA
                    </a>
                <?php endif; ?>
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
                
                <a href="logout.php" class="btn btn-ghost" style="width: 100%; justify-content: flex-start; padding: 0.7rem 1.1rem; font-size: 0.8rem; color: var(--error); margin-bottom: 0.4rem;">
                    <img src="/assets/icon_logout_red.svg" style="height: 16px; width: 16px; margin-right: 8px;"> Se déconnecter
                </a>
                <a href="profile.php" class="btn btn-primary sidebar-link"
                    style="width: 100%; justify-content: flex-start; padding: 0.7rem 1.1rem; font-size: 0.8rem;">
                    <img src="/assets/icon_profile_blue.svg" style="height: 16px; width: 16px; margin-right: 8px;"> Mon Profil
                </a>
            </div>
        </aside>

        <main class="main-content">
            <?php if ($forceChange && $messageType !== 'success'): ?>
                <div class="force-banner">
                    <span style="font-size: 1.2rem; flex-shrink: 0;">⚠</span>
                    <div>
                        <strong style="display: block; margin-bottom: 2px;">Changement de mot de passe obligatoire</strong>
                        <span style="font-size: 0.8rem; opacity: 0.9;">Pour des raisons de sécurité, vous devez définir un nouveau mot de passe avant de continuer.</span>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($message): ?>
                <script>
                    document.addEventListener('DOMContentLoaded', () => {
                        showToast(<?= json_encode($message) ?>, <?= json_encode($messageType) ?>);
                    });
                </script>
            <?php endif; ?>

            <div class="card glass animate-in">
                <div style="margin-bottom: 2rem; padding-bottom: 1.5rem; border-bottom: 1px solid var(--glass-border);">
                    <h3 style="font-size: 1.3rem; margin-bottom: 0.5rem;">Informations personnelles</h3>
                    <p style="color: var(--text-dim); font-size: 0.85rem;">Gérez vos accès et vos paramètres de
                        sécurité.</p>
                </div>

                <div
                    style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1.5rem; margin-bottom: 2rem;">
                    <div>
                        <p style="font-size: 0.7rem; color: var(--text-dim); text-transform: uppercase;">Nom Complet</p>
                        <p style="font-size: 1.1rem; font-weight: 700; color: var(--text-main);">
                            <?= htmlspecialchars($_SESSION['user_prenom'] . ' ' . $_SESSION['user_nom']) ?>
                        </p>
                    </div>
                    <div>
                        <p style="font-size: 0.7rem; color: var(--text-dim); text-transform: uppercase;">Rôle</p>
                        <div style="margin-top: 0.4rem;">
                            <?php if ($_SESSION['role'] === 'admin'): ?>
                                <span class="premium-role-badge admin">
                                    <span class="badge-icon">👑</span> Administrateur
                                </span>
                            <?php else: ?>
                                <span class="premium-role-badge tech">
                                    <span class="badge-icon">🔧</span> Technicien
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <style>
                    .premium-role-badge {
                        display: inline-flex;
                        align-items: center;
                        gap: 8px;
                        padding: 0.4rem 1rem;
                        border-radius: 50px;
                        font-size: 0.75rem;
                        font-weight: 800;
                        text-transform: uppercase;
                        letter-spacing: 0.5px;
                        backdrop-filter: blur(10px);
                        border: 1px solid rgba(255,255,255,0.1);
                    }
                    .premium-role-badge.admin {
                        background: linear-gradient(135deg, rgba(255, 179, 0, 0.2), rgba(255, 215, 0, 0.1));
                        color: #ffb300;
                        border-color: rgba(255, 179, 0, 0.3);
                        box-shadow: 0 0 15px rgba(255, 179, 0, 0.1);
                    }
                    .premium-role-badge.tech {
                        background: linear-gradient(135deg, rgba(14, 165, 233, 0.2), rgba(99, 102, 241, 0.1));
                        color: #0ea5e9;
                        border-color: rgba(14, 165, 233, 0.3);
                        box-shadow: 0 0 15px rgba(14, 165, 233, 0.1);
                    }
                    .badge-icon { font-size: 0.9rem; }

                    /* Switch CSS */
                    .switch {
                        position: relative;
                        display: inline-block;
                        width: 44px;
                        height: 24px;
                    }
                    .switch input { opacity: 0; width: 0; height: 0; }
                    .slider {
                        position: absolute;
                        cursor: pointer;
                        top: 0; left: 0; right: 0; bottom: 0;
                        background-color: rgba(255,255,255,0.1);
                        transition: .4s;
                        border-radius: 34px;
                        border: 1px solid var(--glass-border);
                    }
                    .slider:before {
                        position: absolute;
                        content: "";
                        height: 16px;
                        width: 16px;
                        left: 3px;
                        bottom: 3px;
                        background-color: white;
                        transition: .4s;
                        border-radius: 50%;
                    }
                    input:checked + .slider { background-color: var(--primary); }
                    input:focus + .slider { box-shadow: 0 0 1px var(--primary); }
                    input:checked + .slider:before { transform: translateX(20px); }
                </style>

                <!-- PREFERENCES / STATUT -->
                <div
                    style="margin-bottom: 2rem; background: rgba(255,255,255,0.02); padding: 1.5rem; border-radius: var(--radius-md); border: 1px solid var(--glass-border);">
                    <h4 style="margin-bottom: 1.5rem; color: var(--primary); display:flex; align-items:center; gap:8px;">
                        <span>⚙️</span> Préférences & Statut
                    </h4>
 
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 2rem;">
                        <!-- Theme Toggle -->
                        <div>
                            <p
                                style="font-size: 0.7rem; font-weight: 600; color: var(--text-muted); margin-bottom: 1rem; text-transform: uppercase; letter-spacing: 0.05em;">
                                Thème d'affichage</p>
                            <div style="display: flex; align-items: center; gap: 1rem;">
                                <label class="switch">
                                    <input type="checkbox" id="themeToggle">
                                    <span class="slider"></span>
                                </label>
                                <span id="themeToggleLabel" style="font-weight: 700; font-size: 0.85rem; color: var(--text-main);">Mode Sombre 🌙</span>
                            </div>
                            <script>
                                const themeToggle = document.getElementById('themeToggle');
                                const themeLabel = document.getElementById('themeToggleLabel');
                                const isLight = (localStorage.getItem('theme') === 'light');
                                themeToggle.checked = !isLight;
                                themeLabel.innerHTML = isLight ? 'Mode Clair ☀️' : 'Mode Sombre 🌙';

                                themeToggle.addEventListener('change', (e) => {
                                    if (e.target.checked) {
                                        localStorage.setItem('theme', 'dark');
                                        document.documentElement.classList.remove('light-mode');
                                        themeLabel.innerHTML = 'Mode Sombre 🌙';
                                    } else {
                                        localStorage.setItem('theme', 'light');
                                        document.documentElement.classList.add('light-mode');
                                        themeLabel.innerHTML = 'Mode Clair ☀️';
                                    }
                                });
                            </script>
                        </div>

                        <form method="POST" id="statusForm">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="update_statut">
                            <p style="font-size: 0.7rem; font-weight: 600; color: var(--text-muted); margin-bottom: 0.6rem; text-transform: uppercase; letter-spacing: 0.05em;">Statut Actuel</p>
                            <div style="display: flex; gap: 0.5rem; flex-wrap: wrap;">
                                <label style="cursor:pointer; flex: 1; min-width: 90px;">
                                    <input type="radio" name="statut" value="actif" <?= $currentStatut === 'actif' ? 'checked' : '' ?> style="display:none;" onchange="this.form.submit()">
                                    <div style="padding: 0.5rem; border-radius: 8px; border: 1px solid <?= $currentStatut === 'actif' ? 'var(--success)' : 'var(--glass-border)' ?>; background: <?= $currentStatut === 'actif' ? 'rgba(16,185,129,0.1)' : 'rgba(255,255,255,0.02)' ?>; text-align:center; transition: 0.2s; display: flex; align-items: center; justify-content: center; gap: 6px;">
                                        <div style="width: 8px; height: 8px; border-radius: 50%; background: var(--success); box-shadow: 0 0 8px var(--success);"></div>
                                        <span style="font-weight: 700; font-size: 0.7rem; color: <?= $currentStatut === 'actif' ? 'var(--success)' : 'var(--text-dim)' ?>;">ACTIF</span>
                                    </div>
                                </label>
                                <label style="cursor:pointer; flex: 1; min-width: 90px;">
                                    <input type="radio" name="statut" value="pause" <?= $currentStatut === 'pause' ? 'checked' : '' ?> style="display:none;" onchange="this.form.submit()">
                                    <div style="padding: 0.5rem; border-radius: 8px; border: 1px solid <?= $currentStatut === 'pause' ? 'var(--primary)' : 'var(--glass-border)' ?>; background: <?= $currentStatut === 'pause' ? 'rgba(255,179,0,0.1)' : 'rgba(255,255,255,0.02)' ?>; text-align:center; transition: 0.2s; display: flex; align-items: center; justify-content: center; gap: 6px;">
                                        <div style="width: 8px; height: 8px; border-radius: 50%; background: var(--primary); box-shadow: 0 0 8px var(--primary);"></div>
                                        <span style="font-weight: 700; font-size: 0.7rem; color: <?= $currentStatut === 'pause' ? 'var(--primary)' : 'var(--text-dim)' ?>;">PAUSE</span>
                                    </div>
                                </label>
                                <label style="cursor:pointer; flex: 1; min-width: 90px;">
                                    <input type="radio" name="statut" value="absent" <?= $currentStatut === 'absent' ? 'checked' : '' ?> style="display:none;" onchange="this.form.submit()">
                                    <div style="padding: 0.5rem; border-radius: 8px; border: 1px solid <?= $currentStatut === 'absent' ? 'var(--error)' : 'var(--glass-border)' ?>; background: <?= $currentStatut === 'absent' ? 'rgba(239,68,68,0.1)' : 'rgba(255,255,255,0.02)' ?>; text-align:center; transition: 0.2s; display: flex; align-items: center; justify-content: center; gap: 6px;">
                                        <div style="width: 8px; height: 8px; border-radius: 50%; background: var(--error); box-shadow: 0 0 8px var(--error);"></div>
                                        <span style="font-weight: 700; font-size: 0.7rem; color: <?= $currentStatut === 'absent' ? 'var(--error)' : 'var(--text-dim)' ?>;">ABSENT</span>
                                    </div>
                                </label>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- FORM PHOTO DE PROFIL -->
                <form method="POST" id="avatarForm" autocomplete="off"
                    style="margin-bottom: 2rem; background: rgba(255,255,255,0.02); padding: 1.5rem; border-radius: var(--radius-md); border: 1px solid var(--glass-border);">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="update_avatar">
                    <input type="hidden" name="avatar_base64" id="avatarBase64Input">

                    <h4 style="margin-bottom: 0.5rem; color: var(--primary);">📷 Photo de Profil</h4>
                    <p style="font-size: 0.75rem; color: var(--text-dim); margin-bottom: 1.5rem;">Celle-ci apparaîtra
                        sur votre tableau de bord. Prenez une photo ou choisissez-en une dans la galerie.</p>

                    <div style="display: flex; align-items: center; gap: 1.5rem; flex-wrap: wrap;">
                        <label for="avatarInput"
                            style="cursor: pointer; flex-shrink: 0; position: relative; width: 80px; height: 80px; border-radius: 50%; border: 2px dashed rgba(14, 165, 233, 0.4); display: flex; align-items: center; justify-content: center; overflow: hidden; background: rgba(0,0,0,0.3); transition: border-color 0.2s;">
                            <?php if (!empty($_SESSION['avatar'])): ?>
                                <img id="avatarPreview" src="<?= htmlspecialchars($_SESSION['avatar']) ?>"
                                    style="width: 100%; height: 100%; object-fit: cover;">
                            <?php else: ?>
                                <img id="avatarPreview" src=""
                                    style="width: 100%; height: 100%; object-fit: cover; display: none;">
                                <span id="avatarPlaceholder"
                                    style="font-size: 2rem; color: var(--accent-cyan); font-weight: 300;">+</span>
                            <?php endif; ?>
                            <input type="file" id="avatarInput" accept="image/*" style="display: none;">
                        </label>
                        <div style="display: flex; flex-direction: column; gap: 0.5rem;">
                            <button type="button" onclick="document.getElementById('avatarInput').click()"
                                class="btn btn-ghost"
                                style="padding: 0.5rem 1rem; font-size: 0.8rem; border-color: var(--glass-border);">
                                📁 Choisir une image...
                            </button>
                            <button type="submit" id="saveAvatarBtn" class="btn btn-primary"
                                style="display: none; padding: 0.5rem 1rem; font-size: 0.8rem; background: var(--success); color: white;">
                                ↓ Sauvegarder la photo
                            </button>
                        </div>
                    </div>
                </form>

                <script>
                    document.getElementById('avatarInput').addEventListener('change', function (e) {
                        const file = e.target.files[0];
                        if (!file) return;

                        const reader = new FileReader();
                        reader.onload = function (event) {
                            const img = new Image();
                            img.onload = function () {
                                const canvas = document.createElement('canvas');
                                const ctx = canvas.getContext('2d');

                                const size = Math.min(img.width, img.height);
                                const sx = (img.width - size) / 2;
                                const sy = (img.height - size) / 2;

                                canvas.width = 300;
                                canvas.height = 300;
                                ctx.drawImage(img, sx, sy, size, size, 0, 0, 300, 300);

                                const base64 = canvas.toDataURL('image/jpeg', 0.8);
                                document.getElementById('avatarBase64Input').value = base64;

                                document.getElementById('avatarPreview').src = base64;
                                document.getElementById('avatarPreview').style.display = 'block';
                                const placeholder = document.getElementById('avatarPlaceholder');
                                if (placeholder) placeholder.style.display = 'none';

                                document.getElementById('saveAvatarBtn').style.display = 'inline-block';
                            };
                            img.src = event.target.result;
                        };
                        reader.readAsDataURL(file);
                    });
                </script>

                <!-- ═══════════════════════════════════════ -->
                <!-- SIGNATURE ÉLECTRONIQUE -->
                <!-- ═══════════════════════════════════════ -->
                <div style="margin-bottom: 2rem; background: rgba(255,255,255,0.02); padding: 1.5rem; border-radius: var(--radius-md); border: 1px solid var(--glass-border);">
                    <h4 style="margin-bottom: 0.5rem; color: var(--primary); display: flex; align-items: center; gap: 8px;">✍️ Signature Électronique</h4>
                    <p style="font-size: 0.75rem; color: var(--text-dim); margin-bottom: 1.5rem;">
                        Cette signature sera automatiquement ajoutée à chaque rapport que vous générez. Dessinez directement ou importez une image.
                    </p>

                    <!-- Signature existante -->
                    <?php if (!empty($currentSignature)): ?>
                    <div id="existingSignatureBlock" style="margin-bottom: 1.5rem; padding: 1rem; background: #fff; border-radius: 12px; text-align: center; position: relative;">
                        <p style="font-size: 0.65rem; color: #666; margin-bottom: 0.5rem; text-transform: uppercase; font-weight: 600; letter-spacing: 0.05em;">Signature actuelle</p>
                        <img src="<?= htmlspecialchars($currentSignature) ?>" id="currentSigPreview" style="max-height: 100px; max-width: 100%; object-fit: contain;">
                        <form method="POST" style="position: absolute; top: 8px; right: 8px;">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="delete_signature">
                            <button type="submit" onclick="return confirm('Supprimer votre signature ?')" style="background: rgba(239,68,68,0.1); border: 1px solid rgba(239,68,68,0.3); color: #ef4444; padding: 4px 8px; border-radius: 6px; font-size: 0.65rem; cursor: pointer; font-weight: 600;">✕ Supprimer</button>
                        </form>
                    </div>
                    <?php endif; ?>

                    <!-- Tabs: Dessiner / Importer -->
                    <div style="display: flex; gap: 0; margin-bottom: 1rem; border-radius: 8px; overflow: hidden; border: 1px solid var(--glass-border);">
                        <button type="button" id="tabDraw" onclick="switchSigTab('draw')" style="flex: 1; padding: 0.6rem; font-size: 0.8rem; font-weight: 700; border: none; cursor: pointer; background: var(--primary); color: #fff; transition: 0.2s;">✏️ Dessiner</button>
                        <button type="button" id="tabUpload" onclick="switchSigTab('upload')" style="flex: 1; padding: 0.6rem; font-size: 0.8rem; font-weight: 700; border: none; cursor: pointer; background: rgba(255,255,255,0.05); color: var(--text-dim); transition: 0.2s;">📁 Importer une image</button>
                    </div>

                    <!-- Zone Dessin -->
                    <div id="sigDrawZone">
                        <div style="position: relative; border-radius: 12px; overflow: hidden; border: 2px solid var(--glass-border); background: #fff;">
                            <canvas id="sigCanvas" width="500" height="180" style="width: 100%; cursor: crosshair; touch-action: none; display: block;"></canvas>
                            <button type="button" onclick="clearSignatureCanvas()" style="position: absolute; top: 8px; right: 8px; background: rgba(0,0,0,0.6); color: #fff; border: none; padding: 4px 10px; border-radius: 6px; font-size: 0.7rem; cursor: pointer; font-weight: 600;">Effacer</button>
                            <!-- Ligne de base pour guider la signature -->
                            <div style="position: absolute; bottom: 35px; left: 20px; right: 20px; border-bottom: 1px dashed rgba(0,0,0,0.15); pointer-events: none;"></div>
                        </div>
                        <p style="font-size: 0.65rem; color: var(--text-dim); margin-top: 0.4rem; text-align: center;">Dessinez votre signature avec la souris ou le doigt</p>
                    </div>

                    <!-- Zone Upload -->
                    <div id="sigUploadZone" style="display: none;">
                        <label for="sigFileInput" style="cursor: pointer; display: flex; flex-direction: column; align-items: center; justify-content: center; padding: 2rem; border: 2px dashed rgba(14, 165, 233, 0.4); border-radius: 12px; background: rgba(14, 165, 233, 0.03); transition: 0.2s; min-height: 120px;">
                            <span style="font-size: 2rem; margin-bottom: 0.5rem;">📄</span>
                            <span style="font-size: 0.85rem; font-weight: 600; color: var(--accent-cyan);">Cliquez ou déposez une image</span>
                            <span style="font-size: 0.7rem; color: var(--text-dim); margin-top: 0.3rem;">PNG, JPG — L'image sera rognée et optimisée</span>
                        </label>
                        <input type="file" id="sigFileInput" accept="image/*" style="display: none;">
                        <div id="sigUploadPreviewWrapper" style="display: none; margin-top: 1rem; text-align: center; background: #fff; padding: 1rem; border-radius: 12px;">
                            <p style="font-size: 0.65rem; color: #666; margin-bottom: 0.5rem; font-weight: 600; text-transform: uppercase;">Aperçu</p>
                            <img id="sigUploadPreview" style="max-height: 120px; max-width: 100%; object-fit: contain;">
                        </div>
                    </div>

                    <!-- Bouton Sauvegarder -->
                    <form method="POST" id="signatureForm">
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="update_signature">
                        <input type="hidden" name="signature_base64" id="signatureBase64Input">
                        <button type="submit" id="saveSigBtn" class="btn btn-primary" style="width: 100%; margin-top: 1rem; padding: 0.75rem; font-size: 0.9rem; background: linear-gradient(135deg, #0ea5e9, #6366f1); border: none; display: none;">
                            💾 Enregistrer ma signature
                        </button>
                    </form>
                </div>

                <script src="https://cdn.jsdelivr.net/npm/signature_pad@4.1.7/dist/signature_pad.umd.min.js"></script>
                <script>
                    // ── Signature Pad Setup ──
                    let sigPad;
                    const sigCanvas = document.getElementById('sigCanvas');

                    function initSigPad() {
                        const ratio = Math.max(window.devicePixelRatio || 1, 1);
                        const rect = sigCanvas.getBoundingClientRect();
                        sigCanvas.width = rect.width * ratio;
                        sigCanvas.height = rect.height * ratio;
                        sigCanvas.getContext('2d').scale(ratio, ratio);
                        sigPad = new SignaturePad(sigCanvas, {
                            penColor: '#1a1a2e',
                            minWidth: 1.5,
                            maxWidth: 3,
                            backgroundColor: 'rgba(255,255,255,0)'
                        });
                        sigPad.addEventListener('endStroke', onSigChange);
                    }

                    function clearSignatureCanvas() {
                        if (sigPad) sigPad.clear();
                        document.getElementById('signatureBase64Input').value = '';
                        document.getElementById('saveSigBtn').style.display = 'none';
                    }

                    function onSigChange() {
                        if (sigPad && !sigPad.isEmpty()) {
                            // Export as trimmed PNG
                            const dataUrl = sigPad.toDataURL('image/png');
                            document.getElementById('signatureBase64Input').value = dataUrl;
                            document.getElementById('saveSigBtn').style.display = 'block';
                        }
                    }

                    // ── Tab Switching ──
                    function switchSigTab(tab) {
                        const drawZone = document.getElementById('sigDrawZone');
                        const uploadZone = document.getElementById('sigUploadZone');
                        const tabDraw = document.getElementById('tabDraw');
                        const tabUpload = document.getElementById('tabUpload');

                        if (tab === 'draw') {
                            drawZone.style.display = 'block';
                            uploadZone.style.display = 'none';
                            tabDraw.style.background = 'var(--primary)';
                            tabDraw.style.color = '#fff';
                            tabUpload.style.background = 'rgba(255,255,255,0.05)';
                            tabUpload.style.color = 'var(--text-dim)';
                        } else {
                            drawZone.style.display = 'none';
                            uploadZone.style.display = 'block';
                            tabUpload.style.background = 'var(--primary)';
                            tabUpload.style.color = '#fff';
                            tabDraw.style.background = 'rgba(255,255,255,0.05)';
                            tabDraw.style.color = 'var(--text-dim)';
                        }
                    }

                    // ── Upload & Crop ──
                    document.getElementById('sigFileInput').addEventListener('change', function(e) {
                        const file = e.target.files[0];
                        if (!file) return;

                        const reader = new FileReader();
                        reader.onload = function(event) {
                            const img = new Image();
                            img.onload = function() {
                                // Auto-crop: find bounding box of non-white pixels
                                const tmpCanvas = document.createElement('canvas');
                                const tmpCtx = tmpCanvas.getContext('2d');
                                tmpCanvas.width = img.width;
                                tmpCanvas.height = img.height;
                                tmpCtx.drawImage(img, 0, 0);

                                const imageData = tmpCtx.getImageData(0, 0, img.width, img.height);
                                const data = imageData.data;
                                let top = img.height, bottom = 0, left = img.width, right = 0;

                                for (let y = 0; y < img.height; y++) {
                                    for (let x = 0; x < img.width; x++) {
                                        const idx = (y * img.width + x) * 4;
                                        const r = data[idx], g = data[idx+1], b = data[idx+2], a = data[idx+3];
                                        // Consider pixel as content if it's not white/transparent
                                        if (a > 20 && (r < 240 || g < 240 || b < 240)) {
                                            if (y < top) top = y;
                                            if (y > bottom) bottom = y;
                                            if (x < left) left = x;
                                            if (x > right) right = x;
                                        }
                                    }
                                }

                                // Add padding
                                const pad = 15;
                                top = Math.max(0, top - pad);
                                left = Math.max(0, left - pad);
                                bottom = Math.min(img.height, bottom + pad);
                                right = Math.min(img.width, right + pad);

                                const cropW = right - left;
                                const cropH = bottom - top;

                                if (cropW < 10 || cropH < 10) {
                                    alert('L\'image semble vide ou trop claire.');
                                    return;
                                }

                                // Resize to fit 500x180 proportionally
                                const targetW = 500;
                                const targetH = 180;
                                const scale = Math.min(targetW / cropW, targetH / cropH);
                                const finalW = Math.round(cropW * scale);
                                const finalH = Math.round(cropH * scale);

                                const outCanvas = document.createElement('canvas');
                                outCanvas.width = targetW;
                                outCanvas.height = targetH;
                                const outCtx = outCanvas.getContext('2d');
                                // Transparent background
                                outCtx.clearRect(0, 0, targetW, targetH);
                                // Center the signature
                                const offsetX = (targetW - finalW) / 2;
                                const offsetY = (targetH - finalH) / 2;
                                outCtx.drawImage(img, left, top, cropW, cropH, offsetX, offsetY, finalW, finalH);

                                const base64 = outCanvas.toDataURL('image/png');
                                document.getElementById('signatureBase64Input').value = base64;

                                // Show preview
                                document.getElementById('sigUploadPreview').src = base64;
                                document.getElementById('sigUploadPreviewWrapper').style.display = 'block';
                                document.getElementById('saveSigBtn').style.display = 'block';
                            };
                            img.src = event.target.result;
                        };
                        reader.readAsDataURL(file);
                    });

                    // ── Form Validation ──
                    document.getElementById('signatureForm').addEventListener('submit', function(e) {
                        const val = document.getElementById('signatureBase64Input').value;
                        if (!val) {
                            e.preventDefault();
                            alert('Veuillez dessiner ou importer votre signature avant de sauvegarder.');
                        }
                    });

                    // Init signature pad on load
                    document.addEventListener('DOMContentLoaded', initSigPad);
                </script>

                <!-- FORM MOT DE PASSE -->
                <form method="POST" class="glass" id="passwordForm" autocomplete="off"
                    style="padding: 2rem; border-radius: var(--radius-md); background: rgba(255,255,255,0.02);">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="change_password">

                    <h4 style="margin-bottom: 0.5rem; color: var(--primary);">🔒 Changer le mot de passe</h4>
                    <p style="font-size: 0.75rem; color: var(--text-dim); margin-bottom: 1.5rem;">
                        Le mot de passe doit comporter au moins 12 caractères, avec majuscule, minuscule, chiffre et
                        caractère spécial.
                    </p>

                    <div class="form-group" style="margin-bottom: 1.25rem;">
                        <label class="label">Ancien mot de passe</label>
                        <div class="input-wrapper">
                            <input type="password" name="old_password" class="input p-password" required
                                placeholder="Votre mot de passe actuel" style="flex: 1; text-align: left;"
                                maxlength="128">
                            <button type="button" class="password-toggle" onclick="togglePass(this)">👁</button>
                        </div>
                    </div>

                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin-bottom: 1.5rem;">
                        <div class="form-group">
                            <label class="label">Nouveau mot de passe</label>
                            <div class="input-wrapper">
                                <input type="password" name="new_password" id="newPassword" class="input p-password"
                                    required placeholder="Min. 12 car." style="flex: 1; text-align: left;"
                                    maxlength="128" oninput="updateStrength(this.value)">
                                <button type="button" class="password-toggle" onclick="togglePass(this)">👁</button>
                            </div>
                            <!-- Indicateur de force -->
                            <div class="strength-bar-container">
                                <div class="strength-bar" id="strengthBar"></div>
                            </div>
                            <p class="strength-label" id="strengthLabel" style="color: var(--text-dim);">—</p>
                            <!-- Liste des règles -->
                            <ul class="policy-list" id="policyList">
                                <li id="rule-len"><span class="check">○</span> 12 caractères minimum</li>
                                <li id="rule-upper"><span class="check">○</span> Une lettre majuscule</li>
                                <li id="rule-lower"><span class="check">○</span> Une lettre minuscule</li>
                                <li id="rule-num"><span class="check">○</span> Un chiffre</li>
                                <li id="rule-spec"><span class="check">○</span> Un caractère spécial</li>
                            </ul>
                        </div>
                        <div class="form-group">
                            <label class="label">Confirmation</label>
                            <div class="input-wrapper">
                                <input type="password" name="confirm_password" id="confirmPassword"
                                    class="input p-password" required placeholder="Répéter"
                                    style="flex: 1; text-align: left;" maxlength="128" oninput="checkMatch()">
                                <button type="button" class="password-toggle" onclick="togglePass(this)">👁</button>
                            </div>
                            <p id="matchLabel" style="font-size: 0.65rem; margin-top: 0.4rem; font-weight: 600;"></p>
                        </div>
                    </div>

                    <button type="submit" class="btn btn-primary" style="width: 100%;">
                        Mettre à jour mon mot de passe
                    </button>
                </form>
            </div>

            <div class="app-footer">
                Raoul Lenoir SAS · <a href="privacy.php" style="color: inherit; text-decoration: underline;">RGPD &amp;
                    Confidentialité</a>
            </div>
        </main>
    </div>

    <script>
        function toggleSidebar() {
            document.getElementById('sidebar').classList.toggle('open');
            document.getElementById('sidebarOverlay').classList.toggle('open');
            document.body.classList.toggle('sidebar-is-open');
            // Fermer sidebar automatiquement si clic lien
            document.querySelectorAll('.sidebar-link').forEach(link => {
                link.addEventListener('click', () => {
                    document.getElementById('sidebar').classList.remove('open');
                    document.getElementById('sidebarOverlay').classList.remove('open');
                    document.body.classList.remove('sidebar-is-open');
                });
            });
        }
        function togglePass(btn) {
            const input = btn.parentElement.querySelector('input');
            const type = input.getAttribute('type') === 'password' ? 'text' : 'password';
            input.setAttribute('type', type);
            btn.textContent = type === 'password' ? '👁' : '🔒';
        }

        // Indicateur de force du mot de passe
        function updateStrength(val) {
            const bar = document.getElementById('strengthBar');
            const label = document.getElementById('strengthLabel');
            let score = 0;
            const checks = {
                'rule-len': val.length >= 12,
                'rule-upper': /[A-Z]/.test(val),
                'rule-lower': /[a-z]/.test(val),
                'rule-num': /[0-9]/.test(val),
                'rule-spec': /[\W_]/.test(val),
            };
            for (const [id, ok] of Object.entries(checks)) {
                const li = document.getElementById(id);
                if (ok) {
                    li.classList.add('ok');
                    li.classList.remove('fail');
                    li.querySelector('.check').textContent = '✓';
                    score++;
                } else {
                    li.classList.remove('ok');
                    if (val.length > 0) {
                        li.classList.add('fail');
                        li.querySelector('.check').textContent = '✕';
                    } else {
                        li.classList.remove('fail');
                        li.querySelector('.check').textContent = '○';
                    }
                }
            }
            bar.className = 'strength-bar strength-' + score;
            const labels = ['', 'Très faible', 'Faible', 'Moyen', 'Fort'];
            const colors = ['var(--text-dim)', '#ef4444', '#f97316', '#eab308', '#22c55e'];
            label.textContent = val.length > 0 ? (labels[score] || 'Fort') : '—';
            label.style.color = val.length > 0 ? (colors[score] || '#22c55e') : 'var(--text-dim)';
            checkMatch();
        }

        function checkMatch() {
            const newPass = document.getElementById('newPassword').value;
            const confirm = document.getElementById('confirmPassword').value;
            const ml = document.getElementById('matchLabel');
            if (!confirm) { ml.textContent = ''; return; }
            if (newPass === confirm) {
                ml.textContent = '✓ Les mots de passe correspondent';
                ml.style.color = '#22c55e';
            } else {
                ml.textContent = '✕ Ne correspond pas';
                ml.style.color = '#ef4444';
            }
        }
    </script>

    <nav class="mobile-bottom-nav">
        <div class="mobile-bottom-nav-inner">
            <a href="<?= $_SESSION['role'] === 'admin' ? 'admin.php' : 'technicien.php' ?>" class="mobile-nav-item">
                <img src="/assets/icon_dashboard_white.svg" style="height: 24px; width: 24px; margin-bottom: 4px; opacity: 0.7;">
                <span class="mobile-nav-label">Tableau</span>
            </a>
            <a href="historique.php" class="mobile-nav-item">
                <img src="/assets/icon_history_white.svg" style="height: 24px; width: 24px; margin-bottom: 4px; opacity: 0.7;">
                <span class="mobile-nav-label">Historique</span>
            </a>
            <?php if ($_SESSION['role'] === 'admin'): ?>
            <a href="equipe.php" class="mobile-nav-item">
                <img src="/assets/icon_profile_blue.svg" style="height: 24px; width: 24px; margin-bottom: 4px; opacity: 0.7;">
                <span class="mobile-nav-label">Équipe</span>
            </a>
            <?php endif; ?>
            <a href="profile.php" class="mobile-nav-item active">
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
    <script src="/assets/toast.js"></script>
</body>

</html>