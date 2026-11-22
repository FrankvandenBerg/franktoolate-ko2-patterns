<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// User management for admins.
declare(strict_types=1);

$me = current_user();
if (!$me) redirect('login');
if (!$me['is_admin']) { http_response_code(403); page('No access', '<h1>No access</h1><p>Only admins can manage users.</p>'); exit; }

$msg = ''; $err = '';
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    check_csrf();
    $action = (string)($_POST['action'] ?? '');
    $id = (int)($_POST['id'] ?? 0);
    $st = db()->prepare('SELECT * FROM ko_users WHERE id = ?');
    $st->execute([$id]);
    $target = $st->fetch() ?: null;
    try {
        if ($action === 'invite') {
            $email = trim((string)($_POST['email'] ?? ''));
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Enter a valid email address.');
            if (find_user_by_email($email)) throw new RuntimeException('An account with that email already exists.');
            $u = create_user($email, (string)($_POST['name'] ?? ''), !empty($_POST['is_admin']), false);
            send_sign_in_link($u, true);
            $msg = 'Invitation sent to ' . $email . '.';
        } elseif (!$target) {
            throw new RuntimeException('That account no longer exists.');
        } elseif ($id === (int)$me['id'] && in_array($action, ['toggle_active', 'toggle_admin', 'delete'], true)) {
            throw new RuntimeException('You cannot disable, demote or delete your own account.');
        } elseif ($action === 'toggle_active') {
            db()->prepare('UPDATE ko_users SET active = 1 - active WHERE id = ?')->execute([$id]);
            $msg = 'Account ' . ($target['active'] ? 'disabled' : 'enabled') . '.';
        } elseif ($action === 'toggle_admin') {
            db()->prepare('UPDATE ko_users SET is_admin = 1 - is_admin WHERE id = ?')->execute([$id]);
            $msg = 'Role changed.';
        } elseif ($action === 'link') {
            send_sign_in_link($target, !$target['email_verified_at']);
            $msg = 'Sign-in link sent to ' . $target['email'] . '.';
        } elseif ($action === 'delete') {
            if (empty($_POST['confirm'])) throw new RuntimeException('Tick "sure" to delete an account.');
            db()->prepare('DELETE FROM ko_passkeys WHERE user_id = ?')->execute([$id]);
            db()->prepare('DELETE FROM ko_tokens WHERE user_id = ?')->execute([$id]);
            db()->prepare('DELETE FROM ko_users WHERE id = ?')->execute([$id]);
            $msg = 'Account deleted.';
        }
    } catch (RuntimeException $e) {
        $err = $e->getMessage();
    } catch (Throwable $e) {
        error_log('kostudio admin: ' . $e->getMessage());
        $err = 'Something went wrong (' . get_class($e) . '). Check the error log.';
    }
}

$users = db()->query('SELECT u.*, (SELECT COUNT(*) FROM ko_passkeys p WHERE p.user_id = u.id) AS passkeys FROM ko_users u ORDER BY u.created_at')->fetchAll();
$rows = '';
foreach ($users as $u) {
    $self = (int)$u['id'] === (int)$me['id'];
    $btn = fn(string $a, string $label, string $extra = '') =>
        '<form method="post" action="' . h(url('admin')) . '">' . csrf_field()
        . '<input type="hidden" name="action" value="' . $a . '"><input type="hidden" name="id" value="' . (int)$u['id'] . '">'
        . $extra . '<button class="sec">' . h($label) . '</button></form>';
    $status = !$u['active'] ? '<span class="err">disabled</span>' : (!$u['email_verified_at'] ? 'email not confirmed' : ((int)$u['passkeys'] === 0 ? 'no passkey yet' : 'active'));
    $rows .= '<tr><td>' . h($u['name']) . '<br><span class="mono">' . h($u['email']) . '</span></td>'
        . '<td>' . ($u['is_admin'] ? 'admin' : 'user') . '</td>'
        . '<td>' . $status . '</td>'
        . '<td class="mono">' . (int)$u['passkeys'] . '</td>'
        . '<td class="mono">' . h($u['last_login_at'] ? substr($u['last_login_at'], 0, 16) . ' UTC' : '–') . '</td>'
        . '<td>' . ($self ? '<span class="mono">you</span>' :
            $btn('toggle_active', $u['active'] ? 'Disable' : 'Enable') . ' '
            . $btn('toggle_admin', $u['is_admin'] ? 'Make user' : 'Make admin') . ' '
            . $btn('link', 'Email sign-in link') . ' '
            . $btn('delete', 'Delete', '<label class="check" style="display:inline-flex;font-size:11px"><input type="checkbox" name="confirm" value="1">sure</label> ')) . '</td></tr>';
}

page('Users', '
<nav class="top"><a href="' . h(url()) . '">← Back to the tool</a><a href="' . h(url('account')) . '">My account</a></nav>
<h1>Users</h1>
' . ($msg ? '<div class="ok" role="status">' . h($msg) . '</div>' : '') . ($err ? '<div class="err" role="alert">' . h($err) . '</div>' : '') . '
<div class="tbl"><table><thead><tr><th>Name / email</th><th>Role</th><th>Status</th><th>Passkeys</th><th>Last sign-in</th><th></th></tr></thead><tbody>' . $rows . '</tbody></table></div>
<form method="post" action="' . h(url('admin')) . '">' . csrf_field() . '
  <h2>Invite someone</h2>
  <p>They get an email to confirm their address and create a passkey. People can also sign up themselves.</p>
  <input type="hidden" name="action" value="invite">
  <label for="name">Name<input id="name" type="text" name="name"></label>
  <label for="email">Email<input id="email" type="email" name="email" required></label>
  <label class="check"><input type="checkbox" name="is_admin" value="1"> Admin (can manage users)</label>
  <button type="submit">Send invitation</button>
</form>');
