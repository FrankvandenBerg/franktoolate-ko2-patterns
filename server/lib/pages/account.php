<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Your account: name and passkeys.
declare(strict_types=1);

$me = current_user();
if (!$me) redirect('login');

$msg = ''; $err = '';
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    check_csrf();
    $action = (string)($_POST['action'] ?? '');
    if ($action === 'name') {
        $name = trim((string)($_POST['name'] ?? ''));
        if (mb_strlen($name) > 100) $err = 'Use a shorter name.';
        else { db()->prepare('UPDATE ko_users SET name = ? WHERE id = ?')->execute([$name, $me['id']]); $msg = 'Name saved.'; $me['name'] = $name; }
    } elseif ($action === 'remove') {
        if (passkey_count((int)$me['id']) <= 1) $err = 'You cannot remove your last passkey. Add another one first.';
        else {
            db()->prepare('DELETE FROM ko_passkeys WHERE id = ? AND user_id = ?')->execute([(int)($_POST['id'] ?? 0), $me['id']]);
            log_event((int)$me['id'], 'passkey_removed');
            $msg = 'Passkey removed.';
        }
    }
}

$st = db()->prepare('SELECT * FROM ko_passkeys WHERE user_id = ? ORDER BY created_at');
$st->execute([$me['id']]);
$rows = '';
foreach ($st->fetchAll() as $p) {
    $rows .= '<tr><td>' . h($p['label']) . '</td><td class="mono">' . h(substr($p['created_at'], 0, 10)) . '</td>'
        . '<td class="mono">' . h($p['last_used_at'] ? substr($p['last_used_at'], 0, 16) . ' UTC' : '–') . '</td>'
        . '<td><form method="post" action="' . h(url('account')) . '">' . csrf_field()
        . '<input type="hidden" name="action" value="remove"><input type="hidden" name="id" value="' . (int)$p['id'] . '"><button class="sec">Remove</button></form></td></tr>';
}

page('Account', '
<nav class="top"><a href="' . h(url()) . '">← Back to the tool</a></nav>
<h1>Account</h1>
<p class="mono">' . h($me['email']) . '</p>
' . ($msg ? '<div class="ok" role="status">' . h($msg) . '</div>' : '') . ($err ? '<div class="err" role="alert">' . h($err) . '</div>' : '') . '
<form method="post" action="' . h(url('account')) . '">' . csrf_field() . '
  <input type="hidden" name="action" value="name">
  <label for="name">Name<input id="name" type="text" name="name" value="' . h($me['name']) . '"></label>
  <button type="submit" class="sec">Save name</button>
</form>
<h2>Passkeys</h2>
<div class="tbl"><table><thead><tr><th>Name</th><th>Created</th><th>Last used</th><th></th></tr></thead><tbody>' . $rows . '</tbody></table></div>
<p><a class="btn" href="' . h(url('passkey')) . '">Add a passkey for this device</a></p>');
