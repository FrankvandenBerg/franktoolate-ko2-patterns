<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Front controller: every request goes through here.

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

start_session();

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$route = trim(substr($path, strlen(base_path())), '/');
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if (str_starts_with($route, 'passkey/')) {
    require KO_HOME . '/lib/pages/passkey_api.php';
    exit;
}
if ($route === 'admin' || str_starts_with($route, 'admin/')) {
    require KO_HOME . '/lib/pages/admin.php';
    exit;
}

switch ($route) {
    case 'login':    require KO_HOME . '/lib/pages/login.php'; exit;
    case 'register': require KO_HOME . '/lib/pages/register.php'; exit;
    case 'link':     require KO_HOME . '/lib/pages/link.php'; exit;
    case 'passkey':  require KO_HOME . '/lib/pages/passkey_setup.php'; exit;
    case 'setup':    require KO_HOME . '/lib/pages/setup.php'; exit;
    case 'account':  require KO_HOME . '/lib/pages/account.php'; exit;
    case 'logout':
        if ($method === 'POST') {
            check_csrf();
            if (!empty($_SESSION['uid'])) log_event((int)$_SESSION['uid'], 'signed_out');
            $_SESSION = []; session_destroy();
        }
        redirect('login');
}

// Everything below needs a signed-in user with a passkey.
if (user_count() === 0) redirect('setup');
$u = current_user();
if (!$u) redirect('login');
if (passkey_count((int)$u['id']) === 0) redirect('passkey');

$tools = tools_for($u);

if ($route === '') {
    // Launcher: go straight to the only tool, otherwise list them.
    $live = array_values(array_filter($tools, fn($t) => $t['status'] === 'live'));
    if (count($live) === 1 && !$u['is_admin']) redirect($live[0]['slug'] . '/');
    require KO_HOME . '/lib/pages/home.php';
    exit;
}

// Old address of the K.O. II tool
if ($route === 'index.html' || $route === 'ko2lib.js') redirect('ko2/');

[$slug, $file] = array_pad(explode('/', $route, 2), 2, '');
$tool = null;
foreach ($tools as $t) if ($t['slug'] === $slug) $tool = $t;
if (!$tool || !preg_match('~^[a-z0-9-]+$~', $slug)) {
    http_response_code(404);
    page('Not found', '<h1>Not found</h1><p><a href="' . h(url()) . '">Back to your tools</a></p>');
    exit;
}
// Relative asset paths need the trailing slash.
if ($file === '' && !str_ends_with($path, '/')) redirect($slug . '/');
serve_tool($u, $tool, $file === '' ? 'index.html' : $file);

function serve_tool(array $u, array $tool, string $file): void {
    if (!preg_match('~^[A-Za-z0-9._-]+$~', $file) || str_starts_with($file, '.')) { http_response_code(404); exit('Not found'); }
    $full = KO_HOME . '/app/' . $tool['slug'] . '/' . $file;
    if (!is_file($full)) { http_response_code(404); exit('Not found'); }
    header('Cache-Control: no-store');
    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    if ($ext !== 'html') {
        $types = ['js' => 'text/javascript', 'css' => 'text/css', 'json' => 'application/json', 'svg' => 'image/svg+xml', 'png' => 'image/png', 'wasm' => 'application/wasm'];
        header('Content-Type: ' . ($types[$ext] ?? 'application/octet-stream'));
        readfile($full);
        return;
    }
    // Log a tool visit at most once per half hour per tool.
    $k = 'opened_' . $tool['slug'];
    if (empty($_SESSION[$k]) || $_SESSION[$k] < time() - 1800) {
        $_SESSION[$k] = time();
        log_event((int)$u['id'], 'tool_opened', $tool['slug']);
    }
    $bar = '<div class="userbar" style="display:flex;flex-wrap:wrap;gap:12px;align-items:center;justify-content:flex-end;font:12px \'IBM Plex Mono\',monospace;color:var(--muted)">'
        . '<a href="' . h(url()) . '" style="color:inherit">Tools</a>'
        . '<span>' . h($u['name'] ?: $u['email']) . '</span>'
        . ($u['is_admin'] ? '<a href="' . h(url('admin')) . '" style="color:inherit">Admin</a>' : '')
        . '<a href="' . h(url('account')) . '" style="color:inherit">Account</a>'
        . '<a href="' . h((string)config('source_url', 'https://github.com/franktoolate/kostudio')) . '" style="color:inherit" target="_blank" rel="noopener">Source code (AGPL-3.0)</a>'
        . '<form method="post" action="' . h(url('logout')) . '" style="display:inline">' . csrf_field()
        . '<button style="font:inherit;background:none;border:0;color:inherit;text-decoration:underline;cursor:pointer;padding:0">Sign out</button></form></div>';
    $html = file_get_contents($full);
    $html = str_replace('<div class="wrap">', '<div class="wrap">' . $bar, $html);
    header('Content-Type: text/html; charset=utf-8');
    echo $html;
}
