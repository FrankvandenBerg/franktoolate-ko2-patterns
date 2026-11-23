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


// Project backups: kept per user, device and project; the newest 25 per project are kept.
if ($route === 'api/backups' || preg_match('~^api/backups/(\d+)$~', $route, $bm)) {
    $dir = KO_HOME . '/data/backups/' . (int)$u['id'];
    $id = isset($bm[1]) ? (int)$bm[1] : 0;
    if ($id) {
        $st = db()->prepare('SELECT * FROM ko_backups WHERE id = ? AND user_id = ?');
        $st->execute([$id, $u['id']]);
        $b = $st->fetch();
        if (!$b || !is_file("$dir/$id.tar")) json_out(['error' => 'Backup not found.'], 404);
        if ($method === 'DELETE') { check_csrf(); @unlink("$dir/$id.tar"); db()->prepare('DELETE FROM ko_backups WHERE id = ?')->execute([$id]); json_out(['ok' => true]); }
        header('Content-Type: application/x-tar');
        header('Content-Disposition: attachment; filename="KO2-P' . preg_replace('~\D~', '', $b['project']) . '-' . str_replace([' ', ':'], ['-', ''], $b['created_at']) . '.tar"');
        header('Cache-Control: no-store');
        readfile("$dir/$id.tar");
        exit;
    }
    $device = substr(preg_replace('~[^A-Za-z0-9_-]~', '', (string)($_GET['device'] ?? '')), 0, 64);
    $project = substr(preg_replace('~[^0-9]~', '', (string)($_GET['project'] ?? '')), 0, 8);
    if ($method === 'GET') {
        $sql = 'SELECT id, device, project, size, note, created_at FROM ko_backups WHERE user_id = ?';
        $args = [$u['id']];
        if ($device !== '') { $sql .= ' AND device = ?'; $args[] = $device; }
        if ($project !== '') { $sql .= ' AND project = ?'; $args[] = $project; }
        $st = db()->prepare($sql . ' ORDER BY id DESC LIMIT 200');
        $st->execute($args);
        json_out(['backups' => $st->fetchAll()]);
    }
    if ($method === 'POST') {
        check_csrf();
        if ($device === '' || $project === '') json_out(['error' => 'Missing device or project.'], 400);
        $data = (string)file_get_contents('php://input');
        if (strlen($data) < 512 || strlen($data) > 16 * 1024 * 1024) json_out(['error' => 'That does not look like a project archive.'], 400);
        if (!is_dir($dir) && !mkdir($dir, 0700, true)) json_out(['error' => 'Could not create the backup folder.'], 500);
        $note = mb_substr((string)($_GET['note'] ?? ''), 0, 190);
        db()->prepare('INSERT INTO ko_backups (user_id, device, project, size, sha256, note, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)')
            ->execute([$u['id'], $device, $project, strlen($data), hash('sha256', $data), $note, now()]);
        $newId = (int)db()->lastInsertId();
        if (file_put_contents("$dir/$newId.tar", $data) === false) json_out(['error' => 'Could not store the backup.'], 500);
        // retention: newest 25 per user, device and project
        $st = db()->prepare('SELECT id FROM ko_backups WHERE user_id = ? AND device = ? AND project = ? ORDER BY id DESC');
        $st->execute([$u['id'], $device, $project]);
        foreach (array_slice($st->fetchAll(PDO::FETCH_COLUMN), 25) as $old) {
            @unlink("$dir/$old.tar");
            db()->prepare('DELETE FROM ko_backups WHERE id = ?')->execute([$old]);
        }
        json_out(['ok' => true, 'id' => $newId]);
    }
    json_out(['error' => 'Unsupported method.'], 405);
}

// Small JSON API for the app (signed-in users only)
if ($route === 'api/sounds') {
    $device = substr(preg_replace('~[^A-Za-z0-9_-]~', '', (string)($_GET['device'] ?? '')), 0, 64);
    if ($device === '') json_out(['error' => 'Missing device.'], 400);
    if ($method === 'GET') {
        $st = db()->prepare('SELECT data, updated_at FROM ko_sound_cache WHERE user_id = ? AND device = ?');
        $st->execute([$u['id'], $device]);
        $r = $st->fetch();
        json_out($r ? ['sounds' => json_decode($r['data'], true), 'updated' => $r['updated_at']] : ['sounds' => null]);
    }
    if ($method === 'POST') {
        check_csrf();
        $raw = (string)file_get_contents('php://input');
        if (strlen($raw) > 2_000_000) json_out(['error' => 'Too large.'], 413);
        $in = json_decode($raw, true);
        if (!is_array($in) || !is_array($in['sounds'] ?? null)) json_out(['error' => 'Bad data.'], 400);
        $clean = [];
        foreach (array_slice($in['sounds'], 0, 2000) as $s) {
            if (!is_array($s)) continue;
            $clean[] = [
                'slot' => (int)($s['slot'] ?? 0), 'node' => (int)($s['node'] ?? 0), 'size' => (int)($s['size'] ?? 0),
                'name' => mb_substr((string)($s['name'] ?? ''), 0, 120), 'seconds' => round((float)($s['seconds'] ?? 0), 2),
                'channels' => (int)($s['channels'] ?? 1), 'mode' => mb_substr((string)($s['mode'] ?? ''), 0, 20),
            ];
        }
        $json = json_encode($clean);
        $isMysql = db()->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
        $sql = $isMysql
            ? 'INSERT INTO ko_sound_cache (user_id, device, data, updated_at) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE data = VALUES(data), updated_at = VALUES(updated_at)'
            : 'INSERT OR REPLACE INTO ko_sound_cache (user_id, device, data, updated_at) VALUES (?, ?, ?, ?)';
        db()->prepare($sql)->execute([$u['id'], $device, $json, now()]);
        json_out(['ok' => true, 'count' => count($clean)]);
    }
    json_out(['error' => 'Use GET or POST.'], 405);
}

// The app itself lives at the root. Old addresses (/ko2/…, /index.html) still work.
if (preg_match('~^ko2(/(.*))?$~', $route, $km)) { $rest = $km[2] ?? ''; redirect($rest === '' || $rest === 'index.html' ? '' : $rest); }
if ($route === '' || $route === 'index.html') { serve_app_file($u, 'index.html'); exit; }
if (preg_match('~^[A-Za-z0-9_-]+\.(js|css|json|svg|png|wasm)$~', $route)) { serve_app_file($u, $route); exit; }
http_response_code(404);
page('Not found', '<h1>Not found</h1><p><a href="' . h(url()) . '">Back to the tool</a></p>');
exit;

function serve_app_file(array $u, string $file): void {
    if (!preg_match('~^[A-Za-z0-9._-]+$~', $file) || str_starts_with($file, '.')) { http_response_code(404); exit('Not found'); }
    $full = KO_HOME . '/app/ko2/' . $file;
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
    // Log an app visit at most once per half hour.
    if (empty($_SESSION['opened']) || $_SESSION['opened'] < time() - 1800) {
        $_SESSION['opened'] = time();
        log_event((int)$u['id'], 'tool_opened', 'ko2');
    }
    // User menu and (for admins) the Admin tab go into placeholders in the app header.
    $menu = '<details class="um"><summary>' . h($u['name'] ?: $u['email']) . '</summary><div class="um-pop">'
        . '<span class="um-mail">' . h($u['email']) . '</span>'
        . '<a href="' . h(url('account')) . '">Account &amp; passkeys</a>'
        . '<a href="' . h((string)config('source_url', 'https://github.com/franktoolate/ko2-patterns')) . '" target="_blank" rel="noopener">Source code (AGPL-3.0)</a>'
        . '<form method="post" action="' . h(url('logout')) . '">' . csrf_field() . '<button>Sign out</button></form></div></details>';
    $html = file_get_contents($full);
    $html = str_replace('<!--user-menu-->', $menu, $html);
    if ($u['is_admin']) $html = str_replace('<!--admin-tab-->', '<a href="' . h(url('admin')) . '">Admin</a>', $html);
    $html = str_replace('</head>', '<meta name="csrf" content="' . h(csrf_token()) . '"><meta name="ko-base" content="' . h(base_path()) . '"></head>', $html);
    header('Content-Type: text/html; charset=utf-8');
    echo $html;
}
