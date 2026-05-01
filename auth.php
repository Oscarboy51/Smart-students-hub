<?php
// ============================================================
// auth.php — /htdocs/ssh/api/auth.php
// Handles: register, login, logout, reset-password, verify-token
// ============================================================
require_once __DIR__ . '/config.php';

$action = $_GET['action'] ?? '';

match ($action) {
    'register'       => register(),
    'login'          => login(),
    'logout'         => logoutUser(),
    'reset-password' => resetPassword(),
    'check-user'     => checkUser(),
    'verify-token'   => verifyToken(),
    default          => respond(['ok' => false, 'msg' => 'Unknown action'], 404),
};

// ---- REGISTER ----
function register(): void {
    $b = body();
    $studentId = trim($b['studentId'] ?? '');
    $username  = trim($b['username'] ?? '');
    $email     = strtolower(trim($b['email'] ?? ''));
    $password  = $b['password'] ?? '';
    $role      = in_array($b['role'] ?? '', ['student', 'seller']) ? $b['role'] : 'student';
    $uid       = trim($b['uid'] ?? '');  // Firebase UID

    if (!$studentId || !$username || !$email || !$password || !$uid)
        respond(['ok' => false, 'msg' => 'All fields required']);

    if (!filter_var($email, FILTER_VALIDATE_EMAIL))
        respond(['ok' => false, 'field' => 'email', 'msg' => 'Invalid email']);

    if (strlen($password) < 8)
        respond(['ok' => false, 'field' => 'password', 'msg' => 'Password too short']);

    $pdo = db();

    if ($pdo->prepare('SELECT 1 FROM users WHERE student_id=?')->execute([$studentId]) &&
        $pdo->query('SELECT FOUND_ROWS()')->fetchColumn())
    {}
    $chk = $pdo->prepare('SELECT student_id, username, email FROM users WHERE student_id=? OR username=? OR email=?');
    $chk->execute([$studentId, $username, $email]);
    $existing = $chk->fetch();
    if ($existing) {
        if ($existing['student_id'] === $studentId) respond(['ok' => false, 'field' => 'id',    'msg' => 'Student ID already registered']);
        if ($existing['username']   === $username)  respond(['ok' => false, 'field' => 'user',  'msg' => 'Username already taken']);
        if ($existing['email']      === $email)     respond(['ok' => false, 'field' => 'email', 'msg' => 'Email already in use']);
    }

    $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
    $stmt = $pdo->prepare(
        'INSERT INTO users (uid, student_id, username, email, password, role) VALUES (?,?,?,?,?,?)'
    );
    $stmt->execute([$uid, $studentId, $username, $email, $hash, $role]);
    $newId = $pdo->lastInsertId();

    $token = jwtEncode(['sub' => $newId, 'uid' => $uid, 'username' => $username, 'role' => $role]);
    respond(['ok' => true, 'token' => $token, 'user' => safeUser($newId)]);
}

// ---- LOGIN ----
function login(): void {
    $b        = body();
    $email    = strtolower(trim($b['email'] ?? ''));
    $password = $b['password'] ?? '';
    $uid      = trim($b['uid'] ?? '');  // Firebase UID passed after Firebase Auth succeeds

    if (!$email || !$uid) respond(['ok' => false, 'msg' => 'Email and Firebase UID required']);

    $pdo  = db();
    $stmt = $pdo->prepare('SELECT * FROM users WHERE email=?');
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if (!$user) respond(['ok' => false, 'msg' => 'No account found for this email']);
    if ($user['banned']) respond(['ok' => false, 'msg' => 'Account suspended. Contact administrator.']);

    // Sync Firebase UID if changed
    if ($user['uid'] !== $uid) {
        $pdo->prepare('UPDATE users SET uid=? WHERE id=?')->execute([$uid, $user['id']]);
    }

    $token = jwtEncode(['sub' => $user['id'], 'uid' => $uid, 'username' => $user['username'], 'role' => $user['role']]);
    respond(['ok' => true, 'token' => $token, 'user' => safeUser($user['id'])]);
}

// ---- LOGOUT (blacklist JWT) ----
function logoutUser(): void {
    $payload = requireAuth();
    $pdo = db();
    $pdo->prepare('INSERT IGNORE INTO revoked_tokens (jti, expires_at) VALUES (?, FROM_UNIXTIME(?))')
        ->execute([$payload['jti'], $payload['exp']]);
    // Clean old tokens
    $pdo->exec('DELETE FROM revoked_tokens WHERE expires_at < NOW()');
    respond(['ok' => true]);
}

// ---- CHECK USER (for password reset flow) ----
function checkUser(): void {
    $b    = body();
    $email = strtolower(trim($b['email'] ?? ''));
    if (!$email) respond(['ok' => false]);
    $stmt = db()->prepare('SELECT username FROM users WHERE email=?');
    $stmt->execute([$email]);
    $u = $stmt->fetch();
    $u ? respond(['ok' => true, 'username' => $u['username']]) : respond(['ok' => false]);
}

// ---- RESET PASSWORD (called after Firebase Auth password reset) ----
function resetPassword(): void {
    // Firebase handles the actual reset email; this syncs the new password hash
    $b       = body();
    $uid     = trim($b['uid'] ?? '');
    $newHash = password_hash($b['password'] ?? '', PASSWORD_BCRYPT, ['cost' => 12]);
    if (!$uid) respond(['ok' => false, 'msg' => 'Firebase UID required']);
    $stmt = db()->prepare('UPDATE users SET password=? WHERE uid=?');
    $stmt->execute([$newHash, $uid]);
    respond(['ok' => true]);
}

// ---- VERIFY TOKEN ----
function verifyToken(): void {
    $payload = requireAuth();
    respond(['ok' => true, 'user' => safeUser($payload['sub'])]);
}

// ---- Helper: return safe user object ----
function safeUser(int $id): array {
    $stmt = db()->prepare(
        'SELECT id, uid, student_id, username, email, role, points, monthly_points,
                discount_tokens, correct_answer_tokens_granted, quiz_streak, last_quiz_date,
                banned, created_at
         FROM users WHERE id=?'
    );
    $stmt->execute([$id]);
    return $stmt->fetch() ?: [];
}
