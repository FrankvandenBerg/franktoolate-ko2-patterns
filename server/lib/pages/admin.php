<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Admin area: overview, users (with detail and activity), tools and access.
declare(strict_types=1);

$me = current_user();
if (!$me) redirect('login');
if (!$me['is_admin']) { http_response_code(403); page('No access', '<h1>No access</h1><p>Only admins can open this page.</p>'); exit; }

$sub = trim(substr($route, strlen('admin')), '/');   // '', 'users', 'user', 'tools', 'activity'
$msg = ''; $err = '';

const EVENT_LABELS = [
    'signed_up' => 'Signed up', 'sign_up_existing' => 'Tried to sign up again', 'email_confirmed' => 'Confirmed email',
    'signed_in' => 'Signed in', 'sign_in_failed' => 'Sign-in failed', 'signed_out' => 'Signed out', 'link_requested' => 'Asked for a sign-in link',
    'passkey_added' => 'Added a passkey', 'passkey_removed' => 'Removed a passkey', 'tool_opened' => 'Opened a tool',
    'admin_change' => 'Changed by an admin',
];

function fmt_time(?string $t): string { return $t ? h(substr($t, 0, 16)) . ' UTC' : '–'; }
function user_by_id(int $id): ?array {
    $st = db()->prepare('SELECT * FROM ko_users WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}
function user_status(array $u, int $passkeys): string {
    if (!$u['active']) return '<span class="pill bad">blocked</span>';
    if (!$u['email_verified_at']) return '<span class="pill">email not confirmed</span>';
    if ($passkeys === 0) return '<span class="pill">no passkey yet</span>';
    return '<span class="pill good">active</span>';
}
function events_table(array $rows, bool $withUser): string {
    if (!$rows) return '<p>No activity yet.</p>';
    $h = '<div class="tbl"><table><thead><tr><th>When</th>' . ($withUser ? '<th>Who</th>' : '') . '<th>What</th><th>Detail</th><th>IP</th></tr></thead><tbody>';
    foreach ($rows as $e) {
        $who = $e['user_id'] ? '<a href="' . h(url('admin/user?id=' . (int)$e['user_id'])) . '">' . h($e['name'] ?: ($e['email'] ?? '#' . $e['user_id'])) . '</a>' : '–';
        $h .= '<tr><td class="mono">' . fmt_time($e['created_at']) . '</td>' . ($withUser ? '<td>' . $who . '</td>' : '')
            . '<td>' . h(EVENT_LABELS[$e['type']] ?? $e['type']) . '</td><td>' . h($e['detail']) . '</td><td class="mono">' . h($e['ip']) . '</td></tr>';
    }
    return $h . '</tbody></table></div>';
}
function admin_nav(string $sub): string {
    $items = ['' => 'Overview', 'users' => 'Users', 'tools' => 'Tools', 'activity' => 'Activity'];
    $h = '<nav class="tabs" aria-label="Admin">';
    foreach ($items as $k => $label) {
        $active = $sub === $k || ($sub === 'user' && $k === 'users');
        $h .= '<a href="' . h(url('admin' . ($k ? '/' . $k : ''))) . '"' . ($active ? ' aria-current="page"' : '') . '>' . $label . '</a>';
    }
    return '<nav class="top"><a href="' . h(url()) . '">← Tools</a><a href="' . h(url('account')) . '">My account</a></nav><h1>Admin</h1>' . $h . '</nav>';
}
function post_form(string $action, array $fields, string $label, string $cls = 'sec', string $extra = ''): string {
    $h = '<form method="post" action="' . h($action) . '" class="inline">' . csrf_field();
    foreach ($fields as $k => $v) $h .= '<input type="hidden" name="' . h($k) . '" value="' . h((string)$v) . '">';
    return $h . $extra . '<button class="' . $cls . '">' . h($label) . '</button></form>';
}

// ---------- actions ----------
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    check_csrf();
    $action = (string)($_POST['action'] ?? '');
    $id = (int)($_POST['id'] ?? 0);
    try {
        if ($action === 'invite') {
            $email = trim((string)($_POST['email'] ?? ''));
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Enter a valid email address.');
            if (find_user_by_email($email)) throw new RuntimeException('An account with that email already exists.');
            $nu = create_user($email, (string)($_POST['name'] ?? ''), !empty($_POST['is_admin']), false);
            log_event((int)$nu['id'], 'admin_change', 'invited by ' . $me['email']);
            send_sign_in_link($nu, true);
            $msg = 'Invitation sent to ' . $email . '.';
        } elseif (in_array($action, ['toggle_active', 'toggle_admin', 'link', 'delete', 'access'], true)) {
            $t = user_by_id($id);
            if (!$t) throw new RuntimeException('That account no longer exists.');
            if ($id === (int)$me['id'] && $action !== 'link' && $action !== 'access') throw new RuntimeException('You cannot block, demote or delete your own account.');
            if ($action === 'toggle_active') {
                db()->prepare('UPDATE ko_users SET active = 1 - active WHERE id = ?')->execute([$id]);
                log_event($id, 'admin_change', ($t['active'] ? 'blocked' : 'unblocked') . ' by ' . $me['email']);
                $msg = 'Account ' . ($t['active'] ? 'blocked' : 'unblocked') . '.';
            } elseif ($action === 'toggle_admin') {
                db()->prepare('UPDATE ko_users SET is_admin = 1 - is_admin WHERE id = ?')->execute([$id]);
                log_event($id, 'admin_change', ($t['is_admin'] ? 'made user' : 'made admin') . ' by ' . $me['email']);
                $msg = 'Role changed.';
            } elseif ($action === 'link') {
                send_sign_in_link($t, !$t['email_verified_at']);
                log_event($id, 'admin_change', 'sign-in link sent by ' . $me['email']);
                $msg = 'Sign-in link sent to ' . $t['email'] . '.';
            } elseif ($action === 'access') {
                $want = array_map('intval', (array)($_POST['tools'] ?? []));
                db()->prepare('DELETE FROM ko_tool_access WHERE user_id = ?')->execute([$id]);
                $ins = db()->prepare('INSERT INTO ko_tool_access (user_id, tool_id, granted_at) VALUES (?, ?, ?)');
                foreach (array_unique($want) as $tid) $ins->execute([$id, $tid, now()]);
                log_event($id, 'admin_change', 'tool access updated by ' . $me['email']);
                $msg = 'Tool access saved.';
            } elseif ($action === 'delete') {
                if (empty($_POST['confirm'])) throw new RuntimeException('Tick "sure" to delete an account.');
                foreach (['ko_passkeys', 'ko_tokens', 'ko_tool_access', 'ko_sound_cache', 'ko_backups'] as $tbl) db()->prepare("DELETE FROM $tbl WHERE user_id = ?")->execute([$id]);
                foreach (glob(KO_HOME . '/data/backups/' . $id . '/*.tar') ?: [] as $f) @unlink($f);
                @rmdir(KO_HOME . '/data/backups/' . $id);
                db()->prepare('DELETE FROM ko_users WHERE id = ?')->execute([$id]);
                log_event(null, 'admin_change', 'deleted ' . $t['email'] . ' by ' . $me['email']);
                $msg = 'Account deleted.';
                if ($sub === 'user') { $_SESSION['flash'] = $msg; redirect('admin/users'); }
            }
        } elseif ($action === 'tool_save' || $action === 'tool_add') {
            $slug = strtolower(trim((string)($_POST['slug'] ?? '')));
            $name = trim((string)($_POST['name'] ?? ''));
            $desc = trim((string)($_POST['description'] ?? ''));
            $access = in_array($_POST['access'] ?? '', ['everyone', 'selected', 'admins'], true) ? $_POST['access'] : 'everyone';
            $status = in_array($_POST['status'] ?? '', ['live', 'hidden'], true) ? $_POST['status'] : 'hidden';
            $sort = (int)($_POST['sort'] ?? 0);
            if ($name === '') throw new RuntimeException('Give the tool a name.');
            if ($action === 'tool_add') {
                if (!preg_match('~^[a-z0-9-]{2,40}$~', $slug) || in_array($slug, ['admin', 'login', 'logout', 'register', 'link', 'passkey', 'setup', 'account'], true))
                    throw new RuntimeException('Use a short address of lowercase letters, digits and dashes (not a reserved word).');
                db()->prepare('INSERT INTO ko_tools (slug, name, description, access, status, sort, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)')
                    ->execute([$slug, $name, $desc, $access, $status, $sort, now()]);
                $msg = 'Tool added. Upload its files to app/' . $slug . '/ on the server.';
            } else {
                db()->prepare('UPDATE ko_tools SET name = ?, description = ?, access = ?, status = ?, sort = ? WHERE id = ?')
                    ->execute([$name, $desc, $access, $status, $sort, $id]);
                $msg = 'Tool saved.';
            }
        }
    } catch (PDOException $e) {
        $err = str_contains($e->getMessage(), 'Duplicate') || str_contains($e->getMessage(), 'UNIQUE') ? 'That address is already in use.' : 'Database error.';
        error_log('kostudio admin: ' . $e->getMessage());
    } catch (RuntimeException $e) {
        $err = $e->getMessage();
    } catch (Throwable $e) {
        error_log('kostudio admin: ' . $e->getMessage());
        $err = 'Something went wrong. Check the error log.';
    }
}
if (!empty($_SESSION['flash'])) { $msg = $_SESSION['flash']; unset($_SESSION['flash']); }
$notice = ($msg ? '<div class="ok" role="status">' . h($msg) . '</div>' : '') . ($err ? '<div class="err" role="alert">' . h($err) . '</div>' : '');

$db = db();
$allTools = $db->query('SELECT * FROM ko_tools ORDER BY sort, name')->fetchAll();

// ---------- pages ----------
if ($sub === '') {
    $count = function (string $sql, array $p = []) use ($db): int { $s = $db->prepare($sql); $s->execute($p); return (int)$s->fetchColumn(); };
    $tiles = [
        ['Users', $count('SELECT COUNT(*) FROM ko_users')],
        ['Signed in, last 7 days', $count('SELECT COUNT(*) FROM ko_users WHERE last_login_at > ?', [ago(7 * 86400)])],
        ['New, last 30 days', $count('SELECT COUNT(*) FROM ko_users WHERE created_at > ?', [ago(30 * 86400)])],
        ['Waiting to finish sign-up', $count('SELECT COUNT(*) FROM ko_users u WHERE active = 1 AND (email_verified_at IS NULL OR NOT EXISTS (SELECT 1 FROM ko_passkeys p WHERE p.user_id = u.id))')],
        ['Blocked', $count('SELECT COUNT(*) FROM ko_users WHERE active = 0')],
        ['Tool visits, last 7 days', $count("SELECT COUNT(*) FROM ko_events WHERE type = 'tool_opened' AND created_at > ?", [ago(7 * 86400)])],
    ];
    $th = '<div class="tiles">';
    foreach ($tiles as [$l, $v]) $th .= '<div class="tile"><b>' . $v . '</b><span>' . h($l) . '</span></div>';
    $th .= '</div>';
    $recentUsers = $db->query('SELECT * FROM ko_users ORDER BY created_at DESC LIMIT 5')->fetchAll();
    $ru = '<ul class="list">';
    foreach ($recentUsers as $r) $ru .= '<li><a href="' . h(url('admin/user?id=' . (int)$r['id'])) . '">' . h($r['name'] ?: $r['email']) . '</a> <span class="mono">' . fmt_time($r['created_at']) . '</span></li>';
    $ru .= '</ul>';
    $ev = $db->query('SELECT e.*, u.name, u.email FROM ko_events e LEFT JOIN ko_users u ON u.id = e.user_id ORDER BY e.id DESC LIMIT 15')->fetchAll();
    page('Admin', '<div class="box wide">' . admin_nav($sub) . $notice . $th
        . '<h2>Newest accounts</h2>' . $ru
        . '<h2>Latest activity</h2>' . events_table($ev, true) . '<p><a href="' . h(url('admin/activity')) . '">All activity</a></p></div>');
    exit;
}

if ($sub === 'users') {
    $q = trim((string)($_GET['q'] ?? ''));
    $filter = (string)($_GET['f'] ?? '');
    $sql = 'SELECT u.*, (SELECT COUNT(*) FROM ko_passkeys p WHERE p.user_id = u.id) AS passkeys,
            (SELECT COUNT(*) FROM ko_events e WHERE e.user_id = u.id AND e.type = \'tool_opened\') AS visits
            FROM ko_users u WHERE (u.email LIKE ? OR u.name LIKE ?)';
    if ($filter === 'blocked') $sql .= ' AND u.active = 0';
    if ($filter === 'admins') $sql .= ' AND u.is_admin = 1';
    if ($filter === 'pending') $sql .= ' AND u.active = 1 AND (u.email_verified_at IS NULL OR NOT EXISTS (SELECT 1 FROM ko_passkeys p WHERE p.user_id = u.id))';
    $st = $db->prepare($sql . ' ORDER BY u.created_at DESC');
    $st->execute(['%' . $q . '%', '%' . $q . '%']);
    $rows = '';
    foreach ($st->fetchAll() as $r) {
        $rows .= '<tr><td><a href="' . h(url('admin/user?id=' . (int)$r['id'])) . '">' . h($r['name'] ?: '(no name)') . '</a><br><span class="mono">' . h($r['email']) . '</span></td>'
            . '<td>' . ($r['is_admin'] ? 'admin' : 'user') . '</td><td>' . user_status($r, (int)$r['passkeys']) . '</td>'
            . '<td class="mono">' . fmt_time($r['created_at']) . '</td><td class="mono">' . fmt_time($r['last_login_at']) . '</td><td class="mono">' . (int)$r['visits'] . '</td></tr>';
    }
    $opt = fn($v, $l) => '<option value="' . $v . '"' . ($filter === $v ? ' selected' : '') . '>' . $l . '</option>';
    page('Users', '<div class="box wide">' . admin_nav($sub) . $notice . '
<form method="get" action="' . h(url('admin/users')) . '" class="row">
  <label for="q">Search<input id="q" type="text" name="q" value="' . h($q) . '" placeholder="name or email"></label>
  <label for="f">Show<select id="f" name="f">' . $opt('', 'Everyone') . $opt('pending', 'Waiting to finish sign-up') . $opt('admins', 'Admins') . $opt('blocked', 'Blocked') . '</select></label>
  <button class="sec">Filter</button>
</form>
<div class="tbl"><table><thead><tr><th>Name / email</th><th>Role</th><th>Status</th><th>Signed up</th><th>Last sign-in</th><th>Tool visits</th></tr></thead><tbody>' . ($rows ?: '<tr><td colspan="6">No accounts match.</td></tr>') . '</tbody></table></div>
<form method="post" action="' . h(url('admin/users')) . '">' . csrf_field() . '
  <h2>Invite someone</h2>
  <p>They get an email to confirm their address and create a passkey. People can also sign up themselves.</p>
  <input type="hidden" name="action" value="invite">
  <label for="name">Name<input id="name" type="text" name="name"></label>
  <label for="email">Email<input id="email" type="email" name="email" required></label>
  <label class="check"><input type="checkbox" name="is_admin" value="1"> Admin (can open this admin area)</label>
  <button type="submit">Send invitation</button>
</form></div>');
    exit;
}

if ($sub === 'user') {
    $id = (int)($_GET['id'] ?? 0);
    $t = user_by_id($id);
    if (!$t) { http_response_code(404); page('Not found', '<div class="box wide">' . admin_nav('users') . '<p>That account does not exist.</p></div>'); exit; }
    $self = $id === (int)$me['id'];
    $st = $db->prepare('SELECT * FROM ko_passkeys WHERE user_id = ? ORDER BY created_at');
    $st->execute([$id]);
    $pks = $st->fetchAll();
    $pkRows = '';
    foreach ($pks as $p) $pkRows .= '<tr><td>' . h($p['label']) . '</td><td class="mono">' . fmt_time($p['created_at']) . '</td><td class="mono">' . fmt_time($p['last_used_at']) . '</td></tr>';
    $st = $db->prepare('SELECT tool_id FROM ko_tool_access WHERE user_id = ?');
    $st->execute([$id]);
    $granted = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    $toolRows = '';
    foreach ($allTools as $tl) {
        $note = $tl['access'] === 'everyone' ? 'open to everyone' : ($tl['access'] === 'admins' ? 'admins only' : 'only selected people');
        $dis = $tl['access'] !== 'selected' ? ' disabled' : '';
        $chk = ($tl['access'] === 'everyone' || in_array((int)$tl['id'], $granted, true) || ($tl['access'] === 'admins' && $t['is_admin'])) ? ' checked' : '';
        $toolRows .= '<label class="check"><input type="checkbox" name="tools[]" value="' . (int)$tl['id'] . '"' . $chk . $dis . '> ' . h($tl['name']) . ' <span class="mono">(' . $note . ($tl['status'] !== 'live' ? ', hidden' : '') . ')</span></label>';
    }
    $st = $db->prepare('SELECT e.*, u.name, u.email FROM ko_events e LEFT JOIN ko_users u ON u.id = e.user_id WHERE e.user_id = ? ORDER BY e.id DESC LIMIT 100');
    $st->execute([$id]);
    $act = url('admin/user?id=' . $id);
    $actions = $self ? '<p>This is your own account.</p>' :
        '<div class="row">' . post_form($act, ['action' => 'toggle_active', 'id' => $id], $t['active'] ? 'Block' : 'Unblock')
        . post_form($act, ['action' => 'toggle_admin', 'id' => $id], $t['is_admin'] ? 'Make user' : 'Make admin')
        . post_form($act, ['action' => 'link', 'id' => $id], 'Email sign-in link')
        . post_form($act, ['action' => 'delete', 'id' => $id], 'Delete', 'sec', '<label class="check"><input type="checkbox" name="confirm" value="1"> sure</label> ') . '</div>';
    page($t['email'], '<div class="box wide">' . admin_nav($sub) . $notice . '
<h2>' . h($t['name'] ?: '(no name)') . ' ' . user_status($t, count($pks)) . '</h2>
<dl class="facts">
  <dt>Email</dt><dd class="mono">' . h($t['email']) . '</dd>
  <dt>Role</dt><dd>' . ($t['is_admin'] ? 'admin' : 'user') . '</dd>
  <dt>Signed up</dt><dd class="mono">' . fmt_time($t['created_at']) . '</dd>
  <dt>Email confirmed</dt><dd class="mono">' . fmt_time($t['email_verified_at']) . '</dd>
  <dt>Last sign-in</dt><dd class="mono">' . fmt_time($t['last_login_at']) . '</dd>
</dl>
' . $actions . '
<h2>Passkeys</h2>
' . ($pkRows ? '<div class="tbl"><table><thead><tr><th>Name</th><th>Created</th><th>Last used</th></tr></thead><tbody>' . $pkRows . '</tbody></table></div>' : '<p>No passkeys yet.</p>') . '
<form method="post" action="' . h($act) . '">' . csrf_field() . '
  <h2>Tool access</h2>
  <p>Tools open to everyone are always available. Tick the tools set to "only selected people" that this person may use.</p>
  <input type="hidden" name="action" value="access"><input type="hidden" name="id" value="' . $id . '">
  ' . ($toolRows ?: '<p>No tools yet.</p>') . '
  <button type="submit" class="sec">Save tool access</button>
</form>
<h2>Activity</h2>' . events_table($st->fetchAll(), false) . '</div>');
    exit;
}

if ($sub === 'tools') {
    $cards = '';
    $sel = fn(string $name, array $opts, string $cur) => '<select name="' . $name . '">' . implode('', array_map(fn($k, $l) => '<option value="' . $k . '"' . ($cur === $k ? ' selected' : '') . '>' . $l . '</option>', array_keys($opts), $opts)) . '</select>';
    $accessOpts = ['everyone' => 'Everyone with an account', 'selected' => 'Only selected people', 'admins' => 'Admins only'];
    $statusOpts = ['live' => 'Live', 'hidden' => 'Hidden (admins only)'];
    foreach ($allTools as $tl) {
        $st = $db->prepare("SELECT COUNT(*) FROM ko_events WHERE type = 'tool_opened' AND detail = ? AND created_at > ?");
        $st->execute([$tl['slug'], ago(30 * 86400)]);
        $visits = (int)$st->fetchColumn();
        $st = $db->prepare('SELECT COUNT(*) FROM ko_tool_access WHERE tool_id = ?');
        $st->execute([$tl['id']]);
        $grants = (int)$st->fetchColumn();
        $installed = is_file(KO_HOME . '/app/' . $tl['slug'] . '/index.html');
        $cards .= '<form method="post" action="' . h(url('admin/tools')) . '">' . csrf_field() . '
  <input type="hidden" name="action" value="tool_save"><input type="hidden" name="id" value="' . (int)$tl['id'] . '">
  <h2><a href="' . h(url($tl['slug'] . '/')) . '">/' . h($tl['slug']) . '/</a> ' . ($installed ? '' : '<span class="pill bad">files missing</span>') . '</h2>
  <p class="mono">' . $visits . ' visits in the last 30 days · ' . $grants . ' people given access</p>
  <label>Name<input type="text" name="name" value="' . h($tl['name']) . '" required></label>
  <label>Description<input type="text" name="description" value="' . h($tl['description']) . '"></label>
  <div class="row"><label>Who can use it' . $sel('access', $accessOpts, $tl['access']) . '</label>
  <label>Status' . $sel('status', $statusOpts, $tl['status']) . '</label>
  <label>Order<input type="text" name="sort" value="' . (int)$tl['sort'] . '" inputmode="numeric" style="width:5em"></label></div>
  <button type="submit" class="sec">Save</button>
</form>';
    }
    page('Tools', '<div class="box wide">' . admin_nav($sub) . $notice . $cards . '
<form method="post" action="' . h(url('admin/tools')) . '">' . csrf_field() . '
  <h2>Add a tool</h2>
  <p>Register a new tool here, then upload its files (at least <span class="mono">index.html</span>) to <span class="mono">app/&lt;address&gt;/</span> on the server. New tools start hidden, so only admins see them until you set them live.</p>
  <input type="hidden" name="action" value="tool_add">
  <label>Address (lowercase, e.g. op-1-tool)<input type="text" name="slug" required pattern="[a-z0-9-]{2,40}"></label>
  <label>Name<input type="text" name="name" required></label>
  <label>Description<input type="text" name="description"></label>
  <div class="row"><label>Who can use it' . $sel('access', $accessOpts, 'selected') . '</label>
  <label>Status' . $sel('status', $statusOpts, 'hidden') . '</label>
  <label>Order<input type="text" name="sort" value="20" inputmode="numeric" style="width:5em"></label></div>
  <button type="submit">Add tool</button>
</form></div>');
    exit;
}

if ($sub === 'activity') {
    $type = (string)($_GET['type'] ?? '');
    $sql = 'SELECT e.*, u.name, u.email FROM ko_events e LEFT JOIN ko_users u ON u.id = e.user_id';
    $p = [];
    if ($type !== '' && isset(EVENT_LABELS[$type])) { $sql .= ' WHERE e.type = ?'; $p[] = $type; }
    $st = $db->prepare($sql . ' ORDER BY e.id DESC LIMIT 300');
    $st->execute($p);
    $opts = '<option value="">Everything</option>';
    foreach (EVENT_LABELS as $k => $l) $opts .= '<option value="' . $k . '"' . ($type === $k ? ' selected' : '') . '>' . h($l) . '</option>';
    page('Activity', '<div class="box wide">' . admin_nav($sub) . $notice . '
<form method="get" action="' . h(url('admin/activity')) . '" class="row"><label for="type">Show<select id="type" name="type">' . $opts . '</select></label><button class="sec">Filter</button></form>
' . events_table($st->fetchAll(), true) . '<p>The latest 300 entries. Activity older than 180 days is removed automatically.</p></div>');
    exit;
}

http_response_code(404);
page('Not found', '<div class="box wide">' . admin_nav('') . '<p>That admin page does not exist.</p></div>');
