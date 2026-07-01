<?php
/**
 * LRG Dashboard — Magic Link Auth System
 * Forked from VALN dashboard. Uses MySQL (PDO) for sessions, wp_mail() for sending links.
 */

require_once __DIR__ . '/data/auth-config.php';

function lrg_get_pdo(): PDO {
    static $pdo = null;
    if ($pdo !== null) return $pdo;
    $dsn = 'mysql:host=' . LRG_DB_HOST . ';port=' . LRG_DB_PORT . ';dbname=' . LRG_DB_NAME . ';charset=utf8';
    $pdo = new PDO($dsn, LRG_DB_USER, LRG_DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    return $pdo;
}

function lrg_auth_log(string $message): void {
    $ts = date('Y-m-d H:i:s');
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    @file_put_contents(LRG_AUTH_LOG_FILE, "[$ts] [$ip] $message\n", FILE_APPEND | LOCK_EX);
}

function lrg_check_rate_limit(string $email): bool {
    $pdo = lrg_get_pdo();
    $table = LRG_DB_PREFIX . 'lrg_dashboard_sessions';
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM $table WHERE email = ? AND purpose = 'login' AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)");
    $stmt->execute([$email]);
    return (int)$stmt->fetchColumn() < LRG_RATE_LIMIT_MAX;
}

function lrg_generate_token(): string {
    return bin2hex(random_bytes(32));
}

function lrg_send_magic_link(string $email, string $base_path): array {
    $email = strtolower(trim($email));
    $allowed = LRG_DASHBOARD_ALLOWED_EMAILS;

    if (!lrg_check_rate_limit($email)) {
        lrg_auth_log("RATE_LIMITED email=$email");
        return ['success' => false, 'rate_limited' => true];
    }

    if (!isset($allowed[$email])) {
        lrg_auth_log("DENIED_UNKNOWN_EMAIL email=$email");
        return ['success' => false, 'unknown' => true];
    }

    $token = lrg_generate_token();
    $pdo = lrg_get_pdo();
    $table = LRG_DB_PREFIX . 'lrg_dashboard_sessions';

    $stmt = $pdo->prepare("INSERT INTO $table (email, token, purpose, expires_at, ip_address, user_agent) VALUES (?, ?, 'login', DATE_ADD(NOW(), INTERVAL " . LRG_LOGIN_TOKEN_EXPIRY . " SECOND), ?, ?)");
    $stmt->execute([$email, $token, $_SERVER['REMOTE_ADDR'] ?? null, substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500)]);

    $link = 'https://lrgrealty.com/dashboard/?auth=' . $token;

    if (!function_exists('wp_mail')) {
        define('SHORTINIT', false);
        require_once LRG_WP_LOAD_PATH;
    }

    $subject = 'Your LRG Dashboard login link';
    $body = "Click the link below to log into the LRG Dashboard.\nThis link expires in 15 minutes and can only be used once.\n\n$link\n\nIf you didn't request this, ignore this email.";
    $sent = wp_mail($email, $subject, $body);

    lrg_auth_log($sent ? "MAGIC_LINK_SENT email=$email" : "MAGIC_LINK_FAILED email=$email");
    return ['success' => $sent];
}

function lrg_handle_magic_link(string $token, string $base_path): ?array {
    $pdo = lrg_get_pdo();
    $table = LRG_DB_PREFIX . 'lrg_dashboard_sessions';

    $stmt = $pdo->prepare("SELECT * FROM $table WHERE token = ? AND purpose = 'login' AND used_at IS NULL AND expires_at > NOW() LIMIT 1");
    $stmt->execute([$token]);
    $row = $stmt->fetch();

    if (!$row) {
        lrg_auth_log("MAGIC_LINK_INVALID token=" . substr($token, 0, 8) . "...");
        return null;
    }

    $email = $row['email'];
    $allowed = LRG_DASHBOARD_ALLOWED_EMAILS;
    if (!isset($allowed[$email])) {
        lrg_auth_log("MAGIC_LINK_EMAIL_NOT_ALLOWED email=$email");
        return null;
    }

    // Mark login token as used
    $stmt = $pdo->prepare("UPDATE $table SET used_at = NOW() WHERE id = ?");
    $stmt->execute([$row['id']]);

    // Create session
    $session_token = lrg_generate_token();
    $stmt = $pdo->prepare("INSERT INTO $table (email, token, purpose, expires_at, ip_address, user_agent) VALUES (?, ?, 'session', DATE_ADD(NOW(), INTERVAL " . LRG_SESSION_TOKEN_EXPIRY . " SECOND), ?, ?)");
    $stmt->execute([$email, $session_token, $_SERVER['REMOTE_ADDR'] ?? null, substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500)]);

    setcookie('LRG_DASH_SESSION', $session_token, [
        'expires' => time() + LRG_SESSION_TOKEN_EXPIRY,
        'path' => '/',
        'httponly' => true,
        'secure' => true,
        'samesite' => 'Lax',
    ]);
    setcookie('wordpress_lrg_dash', '1', [
        'expires' => time() + LRG_SESSION_TOKEN_EXPIRY,
        'path' => '/',
        'secure' => true,
        'samesite' => 'Lax',
    ]);

    $user = $allowed[$email];
    lrg_auth_log("LOGIN_SUCCESS email=$email name={$user['name']}");

    return [
        'email' => $email,
        'name' => $user['name'],
        'role' => $user['role'],
        'default_page' => $user['default_page'],
    ];
}

function lrg_get_current_user(): ?array {
    $token = $_COOKIE['LRG_DASH_SESSION'] ?? '';
    if (empty($token) || strlen($token) !== 64) return null;

    $pdo = lrg_get_pdo();
    $table = LRG_DB_PREFIX . 'lrg_dashboard_sessions';

    $stmt = $pdo->prepare("SELECT * FROM $table WHERE token = ? AND purpose = 'session' AND used_at IS NULL AND expires_at > NOW() LIMIT 1");
    $stmt->execute([$token]);
    $row = $stmt->fetch();

    if (!$row) return null;

    $email = $row['email'];
    $allowed = LRG_DASHBOARD_ALLOWED_EMAILS;
    if (!isset($allowed[$email])) return null;

    $user = $allowed[$email];
    return [
        'email' => $email,
        'name' => $user['name'],
        'role' => $user['role'],
        'default_page' => $user['default_page'],
    ];
}

function lrg_handle_logout(): void {
    $token = $_COOKIE['LRG_DASH_SESSION'] ?? '';
    if (!empty($token)) {
        $pdo = lrg_get_pdo();
        $table = LRG_DB_PREFIX . 'lrg_dashboard_sessions';
        $stmt = $pdo->prepare("UPDATE $table SET used_at = NOW() WHERE token = ? AND purpose = 'session'");
        $stmt->execute([$token]);
    }
    setcookie('LRG_DASH_SESSION', '', ['path' => '/', 'expires' => 1]);
    setcookie('wordpress_lrg_dash', '', ['path' => '/', 'expires' => 1]);
    lrg_auth_log("LOGOUT token=" . substr($token, 0, 8) . "...");
}
