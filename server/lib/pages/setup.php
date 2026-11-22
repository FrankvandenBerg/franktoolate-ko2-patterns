<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// One-time creation of the first admin account, protected by the setup token from config.local.php.
declare(strict_types=1);

if (user_count() > 0) redirect('login');

$error = '';
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    check_csrf();
    $token = (string)config('setup_token', '');
    $email = trim((string)($_POST['email'] ?? ''));
    $name = trim((string)($_POST['name'] ?? ''));
    if (rate_limited('setup', 10, 900)) {
        $error = 'Too many attempts. Wait 15 minutes and try again.';
    } elseif ($token === '' || !hash_equals($token, (string)($_POST['token'] ?? ''))) {
        $error = 'The setup token is not correct.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Enter a valid email address.';
    } else {
        $u = create_user($email, $name, true, true);
        log_in($u);
        log_event((int)$u['id'], 'signed_up', 'first admin via setup');
        redirect('passkey');
    }
}

page('Set up', '
<div class="box narrow">
  <h1>Set up <span>K.O. II patterns</span></h1>
  <p>Create the first admin account. You need the setup token from <span class="mono">config.local.php</span> on the server. Next you create your passkey.</p>
  <form method="post" action="' . h(url('setup')) . '">' . csrf_field() . '
    <label for="token">Setup token<input id="token" type="password" name="token" required></label>
    <label for="name">Name<input id="name" type="text" name="name" value="' . h($_POST['name'] ?? '') . '"></label>
    <label for="email">Email<input id="email" type="email" name="email" value="' . h($_POST['email'] ?? '') . '" required></label>
    ' . ($error ? '<div class="err" role="alert">' . h($error) . '</div>' : '') . '
    <button type="submit">Create admin account</button>
  </form>
</div>');
