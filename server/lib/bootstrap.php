<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Shared setup: config, database, session, helpers.
// Accounts are passwordless: people verify their email, then sign in with a passkey.
// An emailed one-time link is the fallback (new device, lost passkey).

declare(strict_types=1);

define('KO_HOME', dirname(__DIR__));

$configFile = KO_HOME . '/config.local.php';
if (!is_file($configFile)) {
    http_response_code(500);
    exit('Missing config.local.php (copy config.example.php and fill it in).');
}
$CONFIG = require $configFile;

require KO_HOME . '/lib/mail.php';
require KO_HOME . '/lib/webauthn.php';

function config(string $key, $default = null) {
    global $CONFIG;
    return $CONFIG[$key] ?? $default;
}

function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new PDO(config('db_dsn'), config('db_user'), config('db_pass'), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        migrate($pdo);
    }
    return $pdo;
}

function migrate(PDO $pdo): void {
    $isMysql = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
    $id = $isMysql ? 'INT AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
    $pdo->exec("CREATE TABLE IF NOT EXISTS ko_users (
        id $id,
        email VARCHAR(190) NOT NULL UNIQUE,
        name VARCHAR(190) NOT NULL DEFAULT '',
        handle VARCHAR(64) NOT NULL,
        is_admin TINYINT NOT NULL DEFAULT 0,
        active TINYINT NOT NULL DEFAULT 1,
        email_verified_at DATETIME NULL,
        created_at DATETIME NOT NULL,
        last_login_at DATETIME NULL
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS ko_passkeys (
        id $id,
        user_id INT NOT NULL,
        credential_id VARCHAR(512) NOT NULL UNIQUE,
        public_key TEXT NOT NULL,
        alg INT NOT NULL,
        sign_count BIGINT NOT NULL DEFAULT 0,
        label VARCHAR(190) NOT NULL DEFAULT '',
        created_at DATETIME NOT NULL,
        last_used_at DATETIME NULL
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS ko_tokens (
        id $id,
        user_id INT NOT NULL,
        purpose VARCHAR(20) NOT NULL,
        token_hash CHAR(64) NOT NULL UNIQUE,
        expires_at DATETIME NOT NULL,
        used_at DATETIME NULL
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS ko_attempts (
        id $id,
        ip VARCHAR(64) NOT NULL,
        kind VARCHAR(20) NOT NULL,
        attempted_at DATETIME NOT NULL
    )");
    // Activity log shown to admins
    $pdo->exec("CREATE TABLE IF NOT EXISTS ko_events (
        id $id,
        user_id INT NULL,
        type VARCHAR(40) NOT NULL,
        detail VARCHAR(255) NOT NULL DEFAULT '',
        ip VARCHAR(64) NOT NULL DEFAULT '',
        created_at DATETIME NOT NULL
    )");
    // Per user and device: cached list of the sounds on the K.O. II, so it does not have to be read over MIDI every time
    $text = $isMysql ? 'MEDIUMTEXT' : 'TEXT';
    $pdo->exec("CREATE TABLE IF NOT EXISTS ko_sound_cache (
        user_id INT NOT NULL,
        device VARCHAR(64) NOT NULL,
        data $text NOT NULL,
        updated_at DATETIME NOT NULL,
        PRIMARY KEY (user_id, device)
    )");
    // Project backups made before every write, stored per user (files live outside the web root)
    $pdo->exec("CREATE TABLE IF NOT EXISTS ko_backups (
        id $id,
        user_id INT NOT NULL,
        device VARCHAR(64) NOT NULL,
        project VARCHAR(8) NOT NULL,
        size INT NOT NULL,
        sha256 CHAR(64) NOT NULL,
        note VARCHAR(190) NOT NULL DEFAULT '',
        created_at DATETIME NOT NULL
    )");
}

/** Record something a user did, for the admin activity log. */
function log_event(?int $uid, string $type, string $detail = ''): void {
    try {
        db()->prepare('INSERT INTO ko_events (user_id, type, detail, ip, created_at) VALUES (?, ?, ?, ?, ?)')
            ->execute([$uid, $type, mb_substr($detail, 0, 255), client_ip(), now()]);
        if (random_int(1, 200) === 1) db()->prepare('DELETE FROM ko_events WHERE created_at < ?')->execute([ago(180 * 86400)]);
    } catch (Throwable $e) { error_log('kostudio log_event: ' . $e->getMessage()); }
}


function now(): string { return gmdate('Y-m-d H:i:s'); }
function ago(int $seconds): string { return gmdate('Y-m-d H:i:s', time() - $seconds); }

function base_path(): string {
    $b = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
    return $b === '' ? '' : $b;
}

function url(string $route = ''): string { return base_path() . '/' . ltrim($route, '/'); }
function abs_url(string $route = ''): string { return rtrim((string)config('base_url'), '/') . '/' . ltrim($route, '/'); }

function start_session(): void {
    if (session_status() === PHP_SESSION_ACTIVE) return;
    session_name('kostudio');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => base_path() . '/',
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

function h(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function csrf_token(): string {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf'];
}

function csrf_field(): string { return '<input type="hidden" name="csrf" value="' . h(csrf_token()) . '">'; }

function check_csrf(): void {
    $sent = (string)($_POST['csrf'] ?? $_SERVER['HTTP_X_CSRF'] ?? '');
    if (!hash_equals($_SESSION['csrf'] ?? '', $sent)) {
        http_response_code(400);
        exit('Invalid form token. Go back, reload the page and try again.');
    }
}

function current_user(): ?array {
    if (empty($_SESSION['uid'])) return null;
    $st = db()->prepare('SELECT * FROM ko_users WHERE id = ? AND active = 1');
    $st->execute([$_SESSION['uid']]);
    return $st->fetch() ?: null;
}

function passkey_count(int $uid): int {
    $st = db()->prepare('SELECT COUNT(*) FROM ko_passkeys WHERE user_id = ?');
    $st->execute([$uid]);
    return (int)$st->fetchColumn();
}

function log_in(array $u): void {
    session_regenerate_id(true);
    $_SESSION['uid'] = (int)$u['id'];
    db()->prepare('UPDATE ko_users SET last_login_at = ? WHERE id = ?')->execute([now(), $u['id']]);
}

function redirect(string $route): never {
    header('Location: ' . url($route));
    exit;
}

function client_ip(): string { return substr($_SERVER['REMOTE_ADDR'] ?? 'unknown', 0, 64); }

/** Simple per-IP rate limit: at most $max events of $kind in $window seconds. Records the event. */
function rate_limited(string $kind, int $max, int $window): bool {
    $st = db()->prepare('SELECT COUNT(*) FROM ko_attempts WHERE ip = ? AND kind = ? AND attempted_at > ?');
    $st->execute([client_ip(), $kind, ago($window)]);
    if ((int)$st->fetchColumn() >= $max) return true;
    db()->prepare('INSERT INTO ko_attempts (ip, kind, attempted_at) VALUES (?, ?, ?)')->execute([client_ip(), $kind, now()]);
    db()->prepare('DELETE FROM ko_attempts WHERE attempted_at < ?')->execute([ago(86400)]);
    return false;
}

function user_count(): int { return (int)db()->query('SELECT COUNT(*) FROM ko_users')->fetchColumn(); }

function find_user_by_email(string $email): ?array {
    $st = db()->prepare('SELECT * FROM ko_users WHERE email = ?');
    $st->execute([strtolower(trim($email))]);
    return $st->fetch() ?: null;
}

function create_user(string $email, string $name, bool $admin, bool $verified): array {
    $st = db()->prepare('INSERT INTO ko_users (email, name, handle, is_admin, active, email_verified_at, created_at) VALUES (?, ?, ?, ?, 1, ?, ?)');
    $st->execute([strtolower(trim($email)), trim($name), b64u_enc(random_bytes(32)), $admin ? 1 : 0, $verified ? now() : null, now()]);
    return find_user_by_email($email);
}

/** Create a one-time token; returns the raw token for the link. */
function make_token(int $uid, string $purpose, int $ttl): string {
    $raw = b64u_enc(random_bytes(32));
    db()->prepare('INSERT INTO ko_tokens (user_id, purpose, token_hash, expires_at) VALUES (?, ?, ?, ?)')
        ->execute([$uid, $purpose, hash('sha256', $raw), gmdate('Y-m-d H:i:s', time() + $ttl)]);
    return $raw;
}

/** Consume a token; returns the user or null. */
function use_token(string $raw): ?array {
    $st = db()->prepare('SELECT * FROM ko_tokens WHERE token_hash = ? AND used_at IS NULL AND expires_at > ?');
    $st->execute([hash('sha256', $raw), now()]);
    $t = $st->fetch();
    if (!$t) return null;
    db()->prepare('UPDATE ko_tokens SET used_at = ? WHERE id = ?')->execute([now(), $t['id']]);
    $st = db()->prepare('SELECT * FROM ko_users WHERE id = ? AND active = 1');
    $st->execute([$t['user_id']]);
    $u = $st->fetch() ?: null;
    if ($u && !$u['email_verified_at']) {
        db()->prepare('UPDATE ko_users SET email_verified_at = ? WHERE id = ?')->execute([now(), $u['id']]);
        log_event((int)$u['id'], 'email_confirmed');
    }
    if ($u) log_event((int)$u['id'], 'signed_in', 'email link');
    return $u;
}

/** Email a sign-in link (also used to confirm a new address). */
function send_sign_in_link(array $u, bool $welcome): void {
    $link = abs_url('link?t=' . make_token((int)$u['id'], 'link', 3600));
    $name = $u['name'] ?: 'there';
    $text = $welcome
        ? "Hi $name,\n\nWelcome to K.O. II Patterns. Confirm your email address and create your passkey with this link:\n\n$link\n\nThe link works once and expires in one hour. If you did not sign up, you can ignore this email.\n"
        : "Hi $name,\n\nUse this link to sign in to K.O. II Patterns. Once signed in you can add a passkey for this device:\n\n$link\n\nThe link works once and expires in one hour. If you did not ask for it, you can ignore this email.\n";
    send_mail($u['email'], $welcome ? 'Confirm your email for K.O. II Patterns' : 'Your sign-in link for K.O. II Patterns', $text);
}

function json_out(array $data, int $code = 200): never {
    http_response_code($code);
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    echo json_encode($data);
    exit;
}

function json_in(): array {
    $d = json_decode((string)file_get_contents('php://input'), true);
    return is_array($d) ? $d : [];
}

function page(string $title, string $body, string $js = ''): void {
    header('Content-Type: text/html; charset=utf-8');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: same-origin');
    header('Cache-Control: no-store');
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
    echo '<meta name="csrf" content="' . h(csrf_token()) . '">';
    echo '<title>' . h($title) . ' · K.O. II Patterns</title>';
    echo '<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Archivo:wght@400;600;800&family=IBM+Plex+Mono:wght@400;500&display=swap">';
    echo '<style>' . file_get_contents(KO_HOME . '/lib/page.css') . '</style></head><body><main class="box">';
    echo $body;
    echo '</main>';
    // Only the script a page needs ('login' or 'register'), comments and indentation stripped.
    if ($js === 'login' || $js === 'register') {
        $code = file_get_contents(KO_HOME . "/lib/passkey-$js.js");
        $code = preg_replace('~^\s*//.*$~m', '', $code);
        $code = preg_replace('~\n\s*~', "\n", trim($code));
        echo '<script>window.KO_BASE=' . json_encode(base_path()) . ";\n" . $code . '</script>';
    }
    echo '</body></html>';
}
