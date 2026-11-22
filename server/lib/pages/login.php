<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Sign in with a passkey, or ask for an email link.
declare(strict_types=1);

if (user_count() === 0) redirect('setup');
if (current_user()) redirect('');

$sent = false; $error = '';
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    check_csrf();
    $email = strtolower(trim((string)($_POST['email'] ?? '')));
    if (rate_limited('mail', 5, 900)) {
        $error = 'Too many emails requested from here. Wait 15 minutes and try again.';
    } else {
        $u = filter_var($email, FILTER_VALIDATE_EMAIL) ? find_user_by_email($email) : null;
        // Same answer whether or not the account exists.
        if ($u && $u['active']) {
            log_event((int)$u['id'], 'link_requested');
            try { send_sign_in_link($u, false); } catch (Throwable $e) { error_log('kostudio mail: ' . $e->getMessage()); }
        }
        $sent = true;
    }
}

page('Sign in', '
<div class="box narrow">
  <h1>K.O. II <span>patterns</span></h1>
  <p>Browse, play and rearrange the patterns on your EP-133 K.O. II.</p>
  <form onsubmit="return false">
    <h2>Sign in</h2>
    <button type="button" data-passkey="login">Sign in with a passkey</button>
    <div id="pk-msg" role="status"></div>
  </form>
  <details class="fallback"' . ($sent || $error ? ' open' : '') . '>
  <summary>No passkey on this device?</summary>
  <form method="post" action="' . h(url('login')) . '">' . csrf_field() . '
    <p>We email you a one-time sign-in link. After signing in you can add a passkey for this device.</p>
    <label for="email">Email<input id="email" type="email" name="email" autocomplete="email" required></label>
    ' . ($sent ? '<div class="ok" role="status">If that address has an account, a sign-in link is on its way. It expires in one hour.</div>' : '') . '
    ' . ($error ? '<div class="err" role="alert">' . h($error) . '</div>' : '') . '
    <button type="submit" class="sec">Email me a sign-in link</button>
  </form>
  </details>
  <p>New here? <a href="' . h(url('register')) . '">Create an account</a></p>
</div>', 'login');
