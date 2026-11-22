<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Front controller: every request to the tool goes through here.

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

switch ($route) {
    case 'login':    require KO_HOME . '/lib/pages/login.php'; break;
    case 'register': require KO_HOME . '/lib/pages/register.php'; break;
    case 'link':     require KO_HOME . '/lib/pages/link.php'; break;
    case 'passkey':  require KO_HOME . '/lib/pages/passkey_setup.php'; break;
    case 'setup':    require KO_HOME . '/lib/pages/setup.php'; break;
    case 'admin':    require KO_HOME . '/lib/pages/admin.php'; break;
    case 'account':  require KO_HOME . '/lib/pages/account.php'; break;
    case 'logout':
        if ($method === 'POST') { check_csrf(); $_SESSION = []; session_destroy(); }
        redirect('login');
    case '':
    case 'index.html':
    case 'ko2lib.js':
        if (user_count() === 0) redirect('setup');
        $u = current_user();
        if (!$u) redirect('login');
        if (passkey_count((int)$u['id']) === 0) redirect('passkey');
        serve_app($u, $route === 'ko2lib.js' ? 'ko2lib.js' : 'index.html');
        exit;
    default:
        http_response_code(404);
        page('Not found', '<h1>Not found</h1><p><a href="' . h(url()) . '">Back to the tool</a></p>');
}

function serve_app(array $u, string $file): void {
    $full = KO_HOME . '/app/' . $file;
    header('Cache-Control: no-store');
    header('X-Frame-Options: DENY');
    if ($file === 'ko2lib.js') {
        header('Content-Type: text/javascript; charset=utf-8');
        readfile($full);
        return;
    }
    $bar = '<div class="userbar" style="display:flex;flex-wrap:wrap;gap:12px;align-items:center;justify-content:flex-end;font:12px \'IBM Plex Mono\',monospace;color:var(--muted)">'
        . '<span>' . h($u['name'] ?: $u['email']) . '</span>'
        . ($u['is_admin'] ? '<a href="' . h(url('admin')) . '" style="color:inherit">Users</a>' : '')
        . '<a href="' . h(url('account')) . '" style="color:inherit">Account</a>'
        . '<a href="' . h((string)config('source_url', 'https://github.com/franktoolate/kostudio')) . '" style="color:inherit" target="_blank" rel="noopener">Source code (AGPL-3.0)</a>'
        . '<form method="post" action="' . h(url('logout')) . '" style="display:inline">' . csrf_field()
        . '<button style="font:inherit;background:none;border:0;color:inherit;text-decoration:underline;cursor:pointer;padding:0">Sign out</button></form></div>';
    $html = file_get_contents($full);
    $html = str_replace('<div class="wrap">', '<div class="wrap">' . $bar, $html);
    header('Content-Type: text/html; charset=utf-8');
    echo $html;
}
