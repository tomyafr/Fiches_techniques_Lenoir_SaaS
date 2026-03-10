<?php
require_once __DIR__ . '/../includes/config.php';

// Traitement de la déconnexion
if (isset($_GET['logout'])) {
    if (session_status() === PHP_SESSION_NONE) {
        session_set_cookie_params([
            'lifetime' => SESSION_TIMEOUT,
            'path' => '/',
            'secure' => true,
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
        session_start();
    }
    $_SESSION = array();
    session_destroy();
    if (isset($_COOKIE[session_name()])) {
        setcookie(session_name(), '', time() - 42000, '/');
    }
    setcookie('APP_SESSION_BACKUP', '', time() - 3600, '/', '', true, true);
    header('Location: index.php');
    exit;
}

startSecureSession();

// Endpoint d'interrogation de la demande de MDP
if (isset($_GET['check_pwd'])) {
    $reqId = (int) $_GET['check_pwd'];
    header('Content-Type: application/json');
    if ($reqId > 0) {
        $db = getDB();
        $stmt = $db->prepare("SELECT status FROM password_requests WHERE id = ?");
        $stmt->execute([$reqId]);
        $row = $stmt->fetch();
        if ($row) {
            echo json_encode(['success' => true, 'status' => $row['status']]);
            exit;
        }
    }
    echo json_encode(['success' => false]);
    exit;
}

if (isset($_SESSION['user_id'])) {
    $target = ($_SESSION['role'] === 'admin' ? 'admin.php' : 'technicien.php');
    header('Location: ' . $target);
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Vérification CSRF
    verifyCsrfToken();

    $db = getDB();
    try {
        $db->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS avatar_base64 TEXT");
        $db->exec("
            CREATE TABLE IF NOT EXISTS password_requests (
                id SERIAL PRIMARY KEY,
                user_id INT REFERENCES users(id) ON DELETE CASCADE,
                new_password_hash VARCHAR(255) NOT NULL,
                status VARCHAR(20) DEFAULT 'pending',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )
        ");
    } catch (Exception $e) {
    }
    $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'];
    // Ne garder que la première IP si plusieurs (proxy)
    $ip = trim(explode(',', $ip)[0]);

    // Protection Brute Force
    $stmt = $db->prepare('SELECT attempts, last_attempt FROM login_attempts WHERE ip_address = ?');
    $stmt->execute([$ip]);
    $throttle = $stmt->fetch();

    if ($throttle && $throttle['attempts'] >= 5) {
        $last = strtotime($throttle['last_attempt']);
        if (time() - $last < 900) { // 15 minutes
            $minutesLeft = ceil((900 - (time() - $last)) / 60);
            $error = "Trop de tentatives échouées. Réessayez dans {$minutesLeft} minute(s).";
        } else {
            $db->prepare('DELETE FROM login_attempts WHERE ip_address = ?')->execute([$ip]);
            $throttle = null;
        }
    }

    if (!$error) {
        $nom = strtoupper(trim($_POST['nom'] ?? ''));
        $password = $_POST['password'] ?? '';

        if (empty($nom) || empty($password)) {
            $error = 'Veuillez remplir tous les champs.';
        } else {
            // Pas d'énumération : message identique que le compte existe ou non
            $stmt = $db->prepare('SELECT id, nom, prenom, password_hash, role, must_change_password, avatar_base64 FROM users WHERE nom = ? AND actif IS TRUE');
            $stmt->execute([$nom]);
            $user = $stmt->fetch();

            if ($user && password_verify($password, $user['password_hash'])) {
                // Connexion réussie : supprimer les tentatives et régénérer l'ID de session
                $db->prepare('DELETE FROM login_attempts WHERE ip_address = ?')->execute([$ip]);

                // Régénérer l'ID de session pour prévenir la fixation de session
                session_regenerate_id(true);

                $_SESSION['user_id'] = $user['id'];
                $_SESSION['user_nom'] = $user['nom'];
                $_SESSION['user_prenom'] = $user['prenom'];
                $_SESSION['role'] = $user['role'];
                $_SESSION['login_time'] = time();
                $_SESSION['must_change_password'] = !empty($user['must_change_password']);
                $_SESSION['avatar'] = $user['avatar_base64'] ?? '';
                setSessionBackup();

                logAudit('LOGIN_SUCCESS', "User: $nom, IP: $ip");
                session_write_close();

                // Redirection selon rôle (ou vers profil si changement de MDP obligatoire)
                if (!empty($user['must_change_password'])) {
                    header('Location: profile.php?force=1');
                } else {
                    header('Location: ' . ($user['role'] === 'admin' ? 'admin.php' : 'technicien.php'));
                }
                exit;
            } else {
                // Échec : incrémenter les tentatives
                if ($throttle) {
                    $db->prepare('UPDATE login_attempts SET attempts = attempts + 1, last_attempt = NOW() WHERE ip_address = ?')->execute([$ip]);
                } else {
                    $db->prepare('INSERT INTO login_attempts (ip_address) VALUES (?)')->execute([$ip]);
                }
                logAudit('LOGIN_FAILED', "IP: $ip, Identifiant: $nom");
                // Délai artificiel pour ralentir les attaques bruteforce (0.5s)
                usleep(500000);
                $error = "Accès refusé. Vérifiez vos identifiants.";
            }
        }
    } elseif ($_POST['action'] === 'forgot_password') {
        $nom = strtoupper(trim($_POST['nom_oubli'] ?? ''));
        $newPass = $_POST['new_pass_oubli'] ?? '';
        if (empty($nom) || empty($newPass)) {
            $error = 'Veuillez remplir tous les champs.';
        } else {
            // Find user
            $stmt = $db->prepare('SELECT id FROM users WHERE nom = ? AND actif IS TRUE');
            $stmt->execute([$nom]);
            $u = $stmt->fetch();
            if ($u) {
                // Delete previous pending requests for this user to avoid spam
                $db->prepare("DELETE FROM password_requests WHERE user_id = ? AND status = 'pending'")->execute([$u['id']]);

                $hash = password_hash($newPass, PASSWORD_BCRYPT, ['cost' => 12]);
                $db->prepare("INSERT INTO password_requests (user_id, new_password_hash) VALUES (?, ?)")->execute([$u['id'], $hash]);
                $pendingReqId = $db->lastInsertId();
            } else {
                $error = "Identifiant introuvable.";
                $showForgotModal = true;
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="fr">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Connexion | Raoul Lenoir</title>
    <!-- ── PWA & Icons ── -->
    <link rel="manifest" href="/manifest.json">
    <link rel="icon" type="image/png" sizes="192x192" href="/assets/icon-192.png">
    <link rel="apple-touch-icon" href="/assets/icon-192.png">
    <meta name="theme-color" content="#020617">

    <link rel="stylesheet" href="/assets/style.css">
    <script>
        if (localStorage.getItem('theme') === 'light') {
            document.documentElement.classList.add('light-mode');
        }
    </script>
</head>

<body class="bg-main">
    <!-- Vidéo Background Premium -->
    <div class="video-background">
        <div class="video-overlay"></div>
        <video autoplay muted loop playsinline id="bgVideo">
            <source src="/assets/video-magnet.mp4" type="video/mp4">
        </video>
    </div>

    <div class="login-page">
        <!-- Logo -->
        <div class="login-header animate-in">
            <div class="brand-icon" style="width: 280px; height: auto; margin: 0 auto 1.5rem auto;">
                <img src="/assets/logo-raoul-lenoir.svg" alt="Raoul Lenoir"
                    style="filter: brightness(0) saturate(100%) invert(73%) sepia(86%) saturate(1063%) hue-rotate(358deg) brightness(101%) contrast(106%);">
            </div>
            <h1 class="login-title" style="color: #ffb300;">Raoul Lenoir</h1>
            <p class="login-subtitle">Fiches Techniques SaaS</p>
        </div>

        <form method="POST" class="login-card glass animate-in" autocomplete="off" <?= isset($pendingReqId) ? 'style="display:none;"' : '' ?>>
            <?= csrfField() ?>

            <?php if ($error): ?>
                <div class="alert alert-error">
                    <span>⚠</span>
                    <span><?= htmlspecialchars($error) ?></span>
                </div>
            <?php endif; ?>
            <?php if (isset($successMsg)): ?>
                <div class="alert alert-success">
                    <span>✓</span>
                    <span><?= htmlspecialchars($successMsg) ?></span>
                </div>
            <?php endif; ?>

            <input type="hidden" name="action" value="login">

            <div class="form-group">
                <label for="nom" class="label">Identifiant</label>
                <div class="input-wrapper">
                    <span class="input-icon">👤</span>
                    <input type="text" name="nom" id="nom" class="input" placeholder="Votre identifiant" required
                        autocomplete="off" spellcheck="false" maxlength="100">
                    <button type="button" class="input-clear" id="resetNom">✕</button>
                </div>
            </div>

            <div class="form-group">
                <label for="password" class="label">Mot de passe</label>
                <div class="input-wrapper">
                    <span class="input-icon">🔒</span>
                    <input type="password" name="password" id="password" class="input" placeholder="••••••••" required
                        autocomplete="new-password" maxlength="128">
                    <button type="button" class="password-toggle" id="togglePassword">👁</button>
                </div>
            </div>

            <button type="submit" class="btn btn-primary login-btn" style="width: 100%;">
                Connexion Sécurisée →
            </button>

            <!-- Raccourcis de test rapides -->
            <div style="display:flex; gap:10px; margin-top:20px;">
                <button type="button" class="btn btn-ghost"
                    onclick="document.getElementById('nom').value='TG'; document.getElementById('password').value='password123'; document.querySelector('.login-card').submit();"
                    style="flex:1; font-size:11px; padding:0.5rem;">🚀 Admin (TG)</button>
                <button type="button" class="btn btn-ghost"
                    onclick="document.getElementById('nom').value='LOTITO'; document.getElementById('password').value='password123'; document.querySelector('.login-card').submit();"
                    style="flex:1; font-size:11px; padding:0.5rem;">🚀 Tech (Lotito)</button>
            </div>

            <button type="button" class="btn btn-ghost" style="width: 100%; margin-top: 1rem; font-size: 0.75rem;"
                onclick="document.getElementById('forgotModal').style.display='flex'">
                J'ai oublié mon mot de passe
            </button>
        </form>

        <!-- MODAL FORGOT PASSWORD -->
        <div id="forgotModal"
            style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; z-index:9999; background:rgba(0,0,0,0.8); align-items:center; justify-content:center; backdrop-filter:blur(5px);">
            <div class="card glass animate-in"
                style="width: 100%; max-width: 400px; padding: 2.5rem; position: relative;">
                <button onclick="document.getElementById('forgotModal').style.display='none'"
                    style="position:absolute; top:1rem; right:1.5rem; background:none; border:none; color:var(--text-dim); font-size:1.5rem; cursor:pointer;">&times;</button>
                <h3 style="margin-bottom: 0.5rem; font-size: 1.25rem;">Mot de passe oublié</h3>
                <p style="font-size: 0.8rem; color: var(--text-dim); margin-bottom: 1.5rem; line-height: 1.4;">
                    Veuillez inscrire le nouveau mot de passe souhaité. Votre administrateur devra accepter votre
                    demande dans sa boîte de réception.
                </p>

                <form method="POST">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="forgot_password">

                    <div class="form-group">
                        <label class="label">Votre Identifiant</label>
                        <div class="input-wrapper">
                            <span class="input-icon">👤</span>
                            <input type="text" name="nom_oubli" id="nom_oubli" class="input"
                                placeholder="Votre identifiant" required autocomplete="off" spellcheck="false"
                                maxlength="100" style="text-transform: uppercase;">
                            <button type="button" class="input-clear" id="resetNomOubli">✕</button>
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="label">Nouveau mot de passe souhaité</label>
                        <div class="input-wrapper">
                            <span class="input-icon">🔒</span>
                            <input type="password" name="new_pass_oubli" id="new_pass_oubli" class="input"
                                placeholder="••••••••" required maxlength="128">
                            <button type="button" class="password-toggle" id="togglePasswordOubli">👁</button>
                        </div>
                    </div>

                    <button type="submit" class="btn btn-primary" style="width: 100%;">Envoyer la demande</button>
                </form>
            </div>
        </div>

        <div class="login-footer animate-in-delay-2" <?= isset($pendingReqId) ? 'style="display:none;"' : '' ?>>
            RAOUL LENOIR SAS · <?= date('Y') ?>
        </div>

        <?php if (isset($pendingReqId)): ?>
            <!-- WAITING SCREEN -->
            <div id="waitingScreen" class="card glass animate-in"
                style="width: 100%; max-width: 440px; padding: 3rem 2.5rem; position: relative; text-align:center;">
                <button onclick="window.location.href='index.php'"
                    style="position:absolute; top:1rem; right:1.5rem; background:none; border:none; color:var(--text-dim); font-size:1.5rem; cursor:pointer;">&times;</button>
                <div id="statusIcon" style="font-size: 3rem; margin-bottom: 1rem;">
                    <div class="spinner"
                        style="border-top-color: var(--primary); width: 48px; height: 48px; border-width:4px; margin: 0 auto;">
                    </div>
                </div>
                <h3 id="statusTitle" style="margin-bottom: 1rem; color: var(--primary);">Demande envoyée</h3>
                <p id="statusDesc" style="font-size: 0.85rem; color: var(--text-dim); line-height: 1.5;">
                    Parfait ! Une demande de modification de votre mot de passe a bien été envoyée.<br><br>
                    En attente de validation par l'administrateur...
                </p>
                <button id="successBtn" onclick="window.location.href='index.php'" class="btn btn-primary"
                    style="display:none; width:100%; margin-top:1.5rem;">Retour à la connexion</button>
            </div>
            <script>
                let pollingInterval = setInterval(() => {
                    fetch('index.php?check_pwd=<?= $pendingReqId ?>')
                        .then(res => res.json())
                        .then(data => {
                            if (data.success && data.status === 'accepted') {
                                clearInterval(pollingInterval);
                                document.getElementById('statusIcon').innerHTML = '✅';
                                document.getElementById('statusTitle').innerHTML = 'Mot de passe validé !';
                                document.getElementById('statusTitle').style.color = 'var(--success)';
                                document.getElementById('statusDesc').innerHTML = 'Votre demande a été acceptée par l\'administrateur. Vous pouvez maintenant vous connecter avec votre nouveau mot de passe.';
                                document.getElementById('successBtn').style.display = 'block';
                            } else if (data.success && data.status === 'rejected') {
                                clearInterval(pollingInterval);
                                document.getElementById('statusIcon').innerHTML = '❌';
                                document.getElementById('statusTitle').innerHTML = 'Demande refusée';
                                document.getElementById('statusTitle').style.color = 'var(--error)';
                                document.getElementById('statusDesc').innerHTML = 'Votre demande a été refusée par l\'administrateur. Veuillez vous rapprocher de lui.';
                                document.getElementById('successBtn').style.display = 'block';
                            }
                        });
                }, 3000); // Check every 3 seconds
            </script>
        <?php endif; ?>
    </div>

    <script>
        // Toggle password visibility (Login Form)
        const togglePassword = document.querySelector('#togglePassword');
        const password = document.querySelector('#password');
        if (togglePassword && password) {
            togglePassword.addEventListener('click', function () {
                const type = password.getAttribute('type') === 'password' ? 'text' : 'password';
                password.setAttribute('type', type);
                this.textContent = type === 'password' ? '👁' : '🔒';
            });
        }

        // Reset username field
        const resetNom = document.getElementById('resetNom');
        const nomInput = document.getElementById('nom');
        if (resetNom && nomInput) {
            resetNom.addEventListener('click', () => {
                nomInput.value = '';
                nomInput.focus();
            });
            nomInput.addEventListener('input', function () {
                this.value = this.value.toUpperCase();
            });
        }

        // Modal: Toggle password visibility
        const togglePasswordOubli = document.querySelector('#togglePasswordOubli');
        const passwordOubli = document.querySelector('#new_pass_oubli');
        if (togglePasswordOubli && passwordOubli) {
            togglePasswordOubli.addEventListener('click', function () {
                const type = passwordOubli.getAttribute('type') === 'password' ? 'text' : 'password';
                passwordOubli.setAttribute('type', type);
                this.textContent = type === 'password' ? '👁' : '🔒';
            });
        }

        // Modal: Reset username field
        const resetNomOubli = document.getElementById('resetNomOubli');
        const nomOubliInput = document.getElementById('nom_oubli');
        if (resetNomOubli && nomOubliInput) {
            resetNomOubli.addEventListener('click', () => {
                nomOubliInput.value = '';
                nomOubliInput.focus();
            });
            nomOubliInput.addEventListener('input', function () {
                this.value = this.value.toUpperCase();
            });
        }

        <?php if (!empty($showForgotModal)): ?>
            document.getElementById('forgotModal').style.display = 'flex';
        <?php endif; ?>
    </script>
    <script src="/assets/notifications.js"></script>
</body>

</html>