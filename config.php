<?php
// ============================================================
// config.php — Place in /htdocs/ssh/api/config.php on XAMPP
// ============================================================

define('DB_HOST', 'localhost');
define('DB_NAME', 'smart_student_hub');
define('DB_USER', 'root');         // Change for production
define('DB_PASS', '');             // Change for production
define('DB_CHARSET', 'utf8mb4');

define('JWT_SECRET', 'SSH_JWT_SECRET_CHANGE_THIS_2024!@#');
define('JWT_EXPIRE', 86400);       // 24 hours in seconds

// Paystack keys
define('PAYSTACK_SECRET_KEY', 'sk_test_REPLACE_WITH_YOUR_KEY');
define('PAYSTACK_PUBLIC_KEY', 'pk_test_REPLACE_WITH_YOUR_KEY');

// Firebase Admin SDK (server-side)
define('FIREBASE_PROJECT_ID', 'smart-student-hub-XXXXX');
define('FIREBASE_SERVICE_ACCOUNT_PATH', __DIR__ . '/firebase-service-account.json');

// CORS — restrict to your domain in production
define('ALLOWED_ORIGIN', '*');

// Error reporting — disable in production
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// ---- Bootstrap ----
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: ' . ALLOWED_ORIGIN);
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Firebase-Token');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

function db(): PDO {
    static $pdo = null;
    if ($pdo) return $pdo;
    try {
        $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'msg' => 'Database connection failed']);
        exit;
    }
    return $pdo;
}

function respond(array $data, int $code = 200): void {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function body(): array {
    $raw = file_get_contents('php://input');
    return json_decode($raw, true) ?? [];
}

// ---- Minimal JWT (no external lib needed) ----
function jwtEncode(array $payload): string {
    $header  = base64url(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
    $payload['iat'] = time();
    $payload['exp'] = time() + JWT_EXPIRE;
    $payload['jti'] = bin2hex(random_bytes(8));
    $body    = base64url(json_encode($payload));
    $sig     = base64url(hash_hmac('sha256', "$header.$body", JWT_SECRET, true));
    return "$header.$body.$sig";
}

function jwtDecode(string $token): ?array {
    $parts = explode('.', $token);
    if (count($parts) !== 3) return null;
    [$header, $body, $sig] = $parts;
    $expected = base64url(hash_hmac('sha256', "$header.$body", JWT_SECRET, true));
    if (!hash_equals($expected, $sig)) return null;
    $payload = json_decode(base64_decode(strtr($body, '-_', '+/')), true);
    if (!$payload || $payload['exp'] < time()) return null;
    // Check blacklist
    $stmt = db()->prepare('SELECT 1 FROM revoked_tokens WHERE jti=?');
    $stmt->execute([$payload['jti']]);
    if ($stmt->fetch()) return null;
    return $payload;
}

function base64url($data): string {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function requireAuth(): array {
    $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (!str_starts_with($auth, 'Bearer ')) respond(['ok' => false, 'msg' => 'Unauthorized'], 401);
    $payload = jwtDecode(substr($auth, 7));
    if (!$payload) respond(['ok' => false, 'msg' => 'Token invalid or expired'], 401);
    return $payload;
}

function requireAdmin(): array {
    $user = requireAuth();
    if ($user['role'] !== 'admin') respond(['ok' => false, 'msg' => 'Admin only'], 403);
    return $user;
}
