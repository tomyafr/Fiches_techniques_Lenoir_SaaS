<?php
// ============================================
// CONFIGURATION - Pointage Atelier SaaS
// ============================================

// Production: désactiver l'affichage des erreurs
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(0);

// ============================================
// HEADERS DE SÉCURITÉ HTTP
// ============================================
// Supprimer le header X-Powered-By (exposition de la version PHP)
header_remove('X-Powered-By');

// Headers de sécurité essentiels
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('X-XSS-Protection: 1; mode=block');
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; font-src 'self' data: https://fonts.gstatic.com; img-src 'self' data:; media-src 'self'; connect-src 'self';");

// ============================================
// BASE DE DONNÉES
// ============================================
define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
define('DB_NAME', getenv('DB_NAME') ?: 'pointage_saas');
define('DB_USER', getenv('DB_USER') ?: 'root');
define('DB_PASS', getenv('DB_PASS') ?: '');

// Microsoft Business Central - Configuration API
define('BC_TENANT_ID', getenv('BC_TENANT_ID') ?: 'votre-tenant-id');
define('BC_CLIENT_ID', getenv('BC_CLIENT_ID') ?: 'votre-client-id');
define('BC_CLIENT_SECRET', getenv('BC_CLIENT_SECRET') ?: 'votre-client-secret');
define('BC_COMPANY_ID', getenv('BC_COMPANY_ID') ?: 'votre-company-id');
define('BC_ENV', getenv('BC_ENV') ?: 'production');

define('BC_BASE_URL', "https://api.businesscentral.dynamics.com/v2.0/" . BC_TENANT_ID . "/" . BC_ENV . "/api/v2.0");
define('BC_TOKEN_URL', 'https://login.microsoftonline.com/' . BC_TENANT_ID . '/oauth2/v2.0/token');
define('BC_SCOPE', 'https://api.businesscentral.dynamics.com/.default');

// Application
define('APP_NAME', 'Pointage Atelier');
define('APP_VERSION', '2.1.0');
define('SESSION_TIMEOUT', 28800); // 8 heures

// Politique de mot de passe
define('PASSWORD_MIN_LENGTH', 12);
define('PASSWORD_REQUIRE_UPPERCASE', true);
define('PASSWORD_REQUIRE_LOWERCASE', true);
define('PASSWORD_REQUIRE_NUMBER', true);
define('PASSWORD_REQUIRE_SPECIAL', true);

// Timezone
date_default_timezone_set('Europe/Paris');

// ============================================
// CONNEXION PDO (PostgreSQL pour Vercel)
// ============================================
function getDB()
{
    static $pdo = null;
    if ($pdo === null) {
        try {
            $host = getenv('POSTGRES_HOST') ?: getenv('PGHOST');
            $db = getenv('POSTGRES_DATABASE') ?: getenv('PGDATABASE');
            $user = getenv('POSTGRES_USER') ?: getenv('PGUSER');
            $pass = getenv('POSTGRES_PASSWORD') ?: getenv('PGPASSWORD');

            if ($host) {
                $dsn = "pgsql:host=$host;port=5432;dbname=$db;sslmode=require";
            } else {
                $dbUrl = getenv('DATABASE_URL') ?: getenv('POSTGRES_URL');
                if ($dbUrl) {
                    $parts = parse_url($dbUrl);
                    $host = $parts['host'];
                    $db = ltrim($parts['path'], '/');
                    $user = $parts['user'];
                    $pass = $parts['pass'];
                    $port = isset($parts['port']) ? $parts['port'] : '5432';
                    $dsn = "pgsql:host=$host;port=$port;dbname=$db;sslmode=require";
                } else {
                    die('Erreur : Variables de base de données non trouvées.');
                }
            }

            $pdo = new PDO($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
        } catch (PDOException $e) {
            // Afficher l'erreur (temporaire)
            die('Erreur de connexion à la base de données : ' . $e->getMessage());
        }
    }
    return $pdo;
}

// ============================================
// SESSION SÉCURISÉE
// ============================================
function startSecureSession()
{
    if (session_status() === PHP_SESSION_NONE) {
        // Configuration sécurisée de la session
        $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
            || (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443);

        session_set_cookie_params([
            'lifetime' => SESSION_TIMEOUT,
            'path' => '/',
            'domain' => '',
            'secure' => $isHttps,   // Secure uniquement en HTTPS
            'httponly' => true,        // HttpOnly: inaccessible via JS (anti-XSS)
            'samesite' => 'Strict',   // SameSite: protection CSRF
        ]);
        session_start();
    }

    // Set CSRF token cookie early if not present (Double Submit Cookie pattern for Serverless)
    if (empty($_COOKIE['csrf_token'])) {
        $token = bin2hex(random_bytes(32));
        $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
            || (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443);
        setcookie('csrf_token', $token, [
            'expires' => time() + SESSION_TIMEOUT,
            'path' => '/',
            'domain' => '',
            'secure' => $isHttps,
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
        $_COOKIE['csrf_token'] = $token;
    }

    // Vérification du timeout de session
    if (isset($_SESSION['login_time']) && (time() - $_SESSION['login_time']) > SESSION_TIMEOUT) {
        session_unset();
        session_destroy();
        startSecureSession();
        return;
    }

    // Restaurer depuis le cookie de secours si session PHP vide (important Vercel)
    if (!isset($_SESSION['user_id']) && isset($_COOKIE['APP_SESSION_BACKUP'])) {
        $raw = $_COOKIE['APP_SESSION_BACKUP'];
        // Vérification signature HMAC avant de restaurer
        $secret = getenv('SESSION_SECRET') ?: 'default-secret-change-in-prod';
        $parts = explode('.', $raw, 2);
        if (count($parts) === 2) {
            [$payload, $sig] = $parts;
            $expected = hash_hmac('sha256', $payload, $secret);
            if (hash_equals($expected, $sig)) {
                $data = json_decode(base64_decode($payload), true);
                if (
                    $data && isset($data['user_id']) && isset($data['ts'])
                    && (time() - $data['ts']) < SESSION_TIMEOUT
                ) {
                    $_SESSION['user_id'] = $data['user_id'];
                    $_SESSION['user_nom'] = $data['user_nom'];
                    $_SESSION['user_prenom'] = $data['user_prenom'];
                    $_SESSION['role'] = $data['role'];
                    $_SESSION['login_time'] = $data['ts'];
                }
            }
        }
    }
}

// Enregistrer les infos en cookie de secours (signé HMAC)
function setSessionBackup()
{
    $secret = getenv('SESSION_SECRET') ?: 'default-secret-change-in-prod';
    $payload = base64_encode(json_encode([
        'user_id' => $_SESSION['user_id'] ?? '',
        'user_nom' => $_SESSION['user_nom'] ?? '',
        'user_prenom' => $_SESSION['user_prenom'] ?? '',
        'role' => $_SESSION['role'] ?? '',
        'ts' => time(),
    ]));
    $sig = hash_hmac('sha256', $payload, $secret);
    $data = $payload . '.' . $sig;

    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');

    setcookie('APP_SESSION_BACKUP', $data, [
        'expires' => time() + SESSION_TIMEOUT,
        'path' => '/',
        'domain' => '',
        'secure' => $isHttps,
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
}

// ============================================
// AUTHENTIFICATION
// ============================================
function requireAuth($role = null)
{
    startSecureSession();
    if (!isset($_SESSION['user_id'])) {
        header('Location: index.php');
        exit;
    }
    if ($role) {
        $allowedRoles = is_array($role) ? $role : [$role];
        if (!in_array($_SESSION['role'], $allowedRoles)) {
            // Journaliser la tentative d'accès non autorisée
            logAudit('UNAUTHORIZED_ACCESS', "Role requis: " . implode(',', $allowedRoles) . ", Role actuel: " . ($_SESSION['role'] ?? 'none'));
            header('Location: index.php');
            exit;
        }
    }
    // Vérifier si le changement de mot de passe est obligatoire
    if (isset($_SESSION['must_change_password']) && $_SESSION['must_change_password']) {
        $currentPage = basename($_SERVER['PHP_SELF']);
        if ($currentPage !== 'profile.php' && $currentPage !== 'logout.php') {
            header('Location: profile.php?force=1');
            exit;
        }
    }
}

// ============================================
// TOKEN CSRF
// ============================================
/**
 * Génère (ou récupère) le token CSRF de la session courante
 */
function getCsrfToken(): string
{
    startSecureSession();
    return $_COOKIE['csrf_token'] ?? '';
}

/**
 * Vérifie le token CSRF soumis dans un formulaire POST
 * Termine le script avec une erreur 403 si invalide
 */
function verifyCsrfToken(): void
{
    startSecureSession();
    $submitted = $_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    $stored = $_COOKIE['csrf_token'] ?? '';
    if (empty($stored) || empty($submitted) || !hash_equals($stored, $submitted)) {
        http_response_code(403);
        die('Erreur de sécurité : token CSRF invalide. Veuillez recharger la page.');
    }
}

/**
 * Retourne un champ HTML caché avec le token CSRF
 */
function csrfField(): string
{
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(getCsrfToken()) . '">';
}

// ============================================
// POLITIQUE DE MOT DE PASSE
// ============================================
/**
 * Valide un mot de passe selon la politique de sécurité
 * Retourne un tableau d'erreurs (vide si valide)
 */
function validatePassword(string $password): array
{
    $errors = [];
    if (strlen($password) < PASSWORD_MIN_LENGTH) {
        $errors[] = "Le mot de passe doit contenir au moins " . PASSWORD_MIN_LENGTH . " caractères.";
    }
    if (PASSWORD_REQUIRE_UPPERCASE && !preg_match('/[A-Z]/', $password)) {
        $errors[] = "Le mot de passe doit contenir au moins une lettre majuscule.";
    }
    if (PASSWORD_REQUIRE_LOWERCASE && !preg_match('/[a-z]/', $password)) {
        $errors[] = "Le mot de passe doit contenir au moins une lettre minuscule.";
    }
    if (PASSWORD_REQUIRE_NUMBER && !preg_match('/[0-9]/', $password)) {
        $errors[] = "Le mot de passe doit contenir au moins un chiffre.";
    }
    if (PASSWORD_REQUIRE_SPECIAL && !preg_match('/[\W_]/', $password)) {
        $errors[] = "Le mot de passe doit contenir au moins un caractère spécial (!@#\$%^&*...).";
    }
    // Vérification listes noires communes
    $blacklist = ['password123', 'Password123', 'password', '123456789', 'azerty123'];
    if (in_array(strtolower($password), array_map('strtolower', $blacklist))) {
        $errors[] = "Ce mot de passe est trop commun et n'est pas autorisé.";
    }
    return $errors;
}

/**
 * Calcule le score de force d'un mot de passe (0-4)
 */
function getPasswordStrength(string $password): int
{
    $score = 0;
    if (strlen($password) >= 12)
        $score++;
    if (preg_match('/[A-Z]/', $password))
        $score++;
    if (preg_match('/[0-9]/', $password))
        $score++;
    if (preg_match('/[\W_]/', $password))
        $score++;
    return $score;
}

// ============================================
// UTILITAIRES DATE / SEMAINE
// ============================================
function getCurrentWeekDates()
{
    $today = new DateTime();
    $dayOfWeek = (int) $today->format('N');
    $monday = clone $today;
    $monday->modify('-' . ($dayOfWeek - 1) . ' days');
    $sunday = clone $monday;
    $sunday->modify('+6 days');

    $dates = [];
    $current = clone $monday;
    while ($current <= $sunday) {
        $dates[] = $current->format('Y-m-d');
        $current->modify('+1 day');
    }

    return [
        'monday' => $monday->format('Y-m-d'),
        'sunday' => $sunday->format('Y-m-d'),
        'dates' => $dates,
        'labels' => ['Lun', 'Mar', 'Mer', 'Jeu', 'Ven', 'Sam', 'Dim'],
    ];
}

// ============================================
// JOURNAL D'AUDIT
// ============================================
/**
 * Enregistrer une action dans le log d'audit
 */
function logAudit($action, $details = '')
{
    try {
        $db = getDB();
        $stmt = $db->prepare('INSERT INTO audit_logs (user_id, action, details, ip_address) VALUES (?, ?, ?, ?)');
        $stmt->execute([
            $_SESSION['user_id'] ?? null,
            $action,
            $details,
            $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'],
        ]);
    } catch (Exception $e) {
        // On ne bloque pas l'app si le log échoue
    }
}
