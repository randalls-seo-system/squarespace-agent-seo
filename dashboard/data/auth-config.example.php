<?php
/**
 * Auth Configuration — COPY to auth-config.php and fill in real values.
 * auth-config.php is gitignored. This file shows what's needed.
 */

define('LRG_DASHBOARD_ALLOWED_EMAILS', [
    'admin@example.com' => [
        'name' => 'Admin',
        'role' => 'operator',           // operator = full dashboard access
        'default_page' => 'command-center'
    ],
    // Add more users as needed. Roles: operator (full), agent_reviewer (scoped to one article).
]);

// MySQL credentials (from wp-config.php on the WP Engine install)
define('LRG_DB_HOST', '127.0.0.1');
define('LRG_DB_PORT', '3306');
define('LRG_DB_NAME', 'wp_INSTALLNAME');        // ← your WPE install DB name
define('LRG_DB_USER', 'INSTALLNAME');            // ← your WPE install DB user
define('LRG_DB_PASS', 'YOUR_DB_PASSWORD_HERE');  // ← from wp-config.php
define('LRG_DB_PREFIX', 'wp_');

// Auth settings
define('LRG_LOGIN_TOKEN_EXPIRY', 900);        // 15 minutes for magic links
define('LRG_SESSION_TOKEN_EXPIRY', 31536000); // 365 days for sessions
define('LRG_RATE_LIMIT_MAX', 5);              // max login requests per email per hour
define('LRG_AUTH_LOG_FILE', __DIR__ . '/dashboard-auth.log');
define('LRG_WP_LOAD_PATH', '/nas/content/live/INSTALLNAME/wp-load.php');  // ← WPE path

// Permanent login keys (optional — bypass email login)
// Generate with: openssl rand -hex 32
define('LRG_PERMANENT_LOGIN_KEYS', [
    // '64-char-hex-token' => 'user@email.com',
]);
