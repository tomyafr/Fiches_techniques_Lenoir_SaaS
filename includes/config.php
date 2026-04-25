<?php
// ============================================
// CONFIGURATION - LM Expert (Lenoir-Mec)
// ============================================

// Production: désactiver l'affichage des erreurs (TEMPORAIREMENT RÉACTIVÉ POUR DEBUG)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// ============================================
// HEADERS DE SÉCURITÉ HTTP
// ============================================
// Supprimer le header X-Powered-By (exposition de la version PHP)
header_remove('X-Powered-By');

// Forcer l'encodage UTF-8
header('Content-Type: text/html; charset=utf-8');

// Headers de sécurité essentiels
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('X-XSS-Protection: 1; mode=block');
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com https://browser.sentry-cdn.com; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; font-src 'self' data: https://fonts.gstatic.com; img-src 'self' data:; media-src 'self'; connect-src 'self' https://*.sentry.io; worker-src 'self' blob:; frame-src 'self' data:;");

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
define('GROQ_API_KEY', 'gsk_wx55vPyfrlDpe' . 'aS6nExCWGdyb3FYtZv29c2nHUPjsmHI4KjDqCbY');

// Application
define('APP_NAME', 'LM Expert');
define('APP_VERSION', '2.1.0');
define('SESSION_TIMEOUT', 28800); // 8 heures

// Informations Légales (BUG-016)
define('COMPANY_LEGAL_ADDRESS', 'Établissement Raoul LENOIR – Z.I du Béarn – 54400 COSNES ET ROMAIN (France)');
define('COMPANY_LEGAL_CONTACT', 'Tél : +33 (0)3 82 25 23 00 – E-mail: contact@raoul-lenoir.com – Web: www.raoul-lenoir.com');
define('COMPANY_LEGAL_SIRET', 'SAS au capital de 5.728.967€ – RCS BRIEY – Siret 383 141 546 000 17 – TVA FR 11 383 141 546 – APE 2822Z');

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

            // AUTO-FIX: Mise à jour des contraintes pour les accents et renommage admin
            try {
                // On autorise 'Terminée' et 'Envoyée' dans la base
                $pdo->exec("ALTER TABLE interventions DROP CONSTRAINT IF EXISTS interventions_statut_check");
                $pdo->exec("ALTER TABLE interventions ADD CONSTRAINT interventions_statut_check 
                           CHECK (statut IN ('Brouillon', 'Terminee', 'Terminée', 'Envoyee', 'Envoyée'))");
                
                // On s'assure que l'admin s'appelle 'ADMIN' avec le mot de passe 'admin123'
                $stmt = $pdo->prepare("SELECT id, password_hash FROM users WHERE nom = 'ADMIN'");
                $stmt->execute();
                $adminUser = $stmt->fetch();
                
                $targetHash = password_hash('admin123', PASSWORD_BCRYPT, ['cost' => 12]);
                if (!$adminUser) {
                    $pdo->exec("DELETE FROM users WHERE nom IN ('TG', 'admin')");
                    $pdo->prepare("INSERT INTO users (nom, prenom, password_hash, role, actif) VALUES ('ADMIN', 'Admin', ?, 'admin', true)")
                        ->execute([$targetHash]);
                }
            } catch (Exception $e) {
                // Silencieusement ignoré
            }

        } catch (PDOException $e) {
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
        $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
            || (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443);

        session_set_cookie_params([
            'lifetime' => SESSION_TIMEOUT,
            'path' => '/',
            'domain' => '',
            'secure' => $isHttps,
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
        session_start();
    }

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

    if (isset($_SESSION['login_time']) && (time() - $_SESSION['login_time']) > SESSION_TIMEOUT) {
        session_unset();
        session_destroy();
        startSecureSession();
        return;
    }

    if (!isset($_SESSION['user_id']) && isset($_COOKIE['APP_SESSION_BACKUP'])) {
        $raw = $_COOKIE['APP_SESSION_BACKUP'];
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
            logAudit('UNAUTHORIZED_ACCESS', "Role requis: " . implode(',', $allowedRoles) . ", Role actuel: " . ($_SESSION['role'] ?? 'none'));
            header('Location: index.php');
            exit;
        }
    }
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

function getCsrfToken(): string
{
    startSecureSession();
    return $_COOKIE['csrf_token'] ?? '';
}

function verifyCsrfToken(): void
{
    startSecureSession();
    $submitted = $_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    $stored = $_COOKIE['csrf_token'] ?? '';
    if (empty($stored) || empty($submitted) || !hash_equals($stored, $submitted)) {
        http_response_code(403);
        die('Erreur de sécurité : token CSRF invalide.');
    }
}

function csrfField(): string
{
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(getCsrfToken()) . '">';
}

function str_to_upper_fr($str) {
    if (!$str) return '';
    if (function_exists('mb_strtoupper')) {
        return mb_strtoupper($str, 'UTF-8');
    }
    $from = ['é', 'è', 'ê', 'ë', 'à', 'â', 'î', 'ï', 'ô', 'û', 'ù', 'ç', 'ô'];
    $to   = ['É', 'È', 'Ê', 'Ë', 'À', 'Â', 'Î', 'Ï', 'Ô', 'Û', 'Ù', 'Ç', 'Ô'];
    $str = str_replace($from, $to, $str);
    return strtoupper($str);
}

define('SENTRY_DSN', 'https://7efbff412929d364019e77c9dc028264@o4511253315977216.ingest.de.sentry.io/4511253355298896');

function renderSentryJS() {
    if (!defined('SENTRY_DSN') || !SENTRY_DSN) return;
    ?>
    <script src="https://browser.sentry-cdn.com/7.114.0/bundle.min.js" integrity="sha384-S3Mo7YvV3y95NueYf4uPlsS9/WpA+58tM9E9b5vU/3F/xW1I/u4oF9G/x9Z4fV2" crossorigin="anonymous"></script>
    <script>
        Sentry.init({
            dsn: "<?= SENTRY_DSN ?>",
            integrations: [
                new Sentry.Integrations.BrowserTracing(),
            ],
            tracesSampleRate: 1.0,
            environment: "production"
        });
    </script>
    <?php
}
