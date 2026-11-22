<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Self sign-up: name + email → confirmation email → passkey.
declare(strict_types=1);

if (current_user()) redirect('');

$done = false; $error = '';
$name = ''; $email = '';
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    check_csrf();
    $name = trim((string)($_POST['name'] ?? ''));
    $email = strtolower(trim((string)($_POST['email'] ?? '')));
    if (($_POST['website'] ?? '') !== '') {
        $done = true; // honeypot filled in: pretend it worked
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Enter a valid email address.';
    } elseif (mb_strlen($name) > 100) {
        $error = 'Use a shorter name.';
    } elseif (rate_limited('mail', 5, 900)) {
        $error = 'Too many sign-ups from here. Wait 15 minutes and try again.';
    } else {
        try {
            $u = find_user_by_email($email);
            if ($u) {
                // Do not reveal that the address exists: send a sign-in link instead.
                if ($u['active']) send_sign_in_link($u, false);
                log_event((int)$u['id'], 'sign_up_existing', 'sent a sign-in link instead');
            } else {
                $u = create_user($email, $name, false, false);
                log_event((int)$u['id'], 'signed_up');
                send_sign_in_link($u, true);
            }
            $done = true;
        } catch (Throwable $e) {
            error_log('kostudio register: ' . $e->getMessage());
            $error = 'We could not send the confirmation email. Try again in a moment.';
        }
    }
}

page('Create account', $done ? '
<div class="box narrow">
  <h1>Check your <span>email</span></h1>
  <p>We sent a link to ' . h($email) . '. Open it on this device to confirm your address and create your passkey. The link expires in one hour.</p>
  <p><a href="' . h(url('login')) . '">Back to sign in</a></p>
</div>' : '
<div class="box narrow">
  <h1>Create an <span>account</span></h1>
  <p>No password needed: you confirm your email, then create a passkey on your device (Touch ID, Face ID, Windows Hello or a security key).</p>
  <form method="post" action="' . h(url('register')) . '">' . csrf_field() . '
    <label for="name">Name<input id="name" type="text" name="name" value="' . h($name) . '" autocomplete="name"></label>
    <label for="email">Email<input id="email" type="email" name="email" value="' . h($email) . '" autocomplete="email" required></label>
    <div style="position:absolute;left:-9999px" aria-hidden="true"><label for="website">Website<input id="website" type="text" name="website" tabindex="-1" autocomplete="off"></label></div>
    ' . ($error ? '<div class="err" role="alert">' . h($error) . '</div>' : '') . '
    <button type="submit">Send confirmation email</button>
  </form>
  <p>Already have an account? <a href="' . h(url('login')) . '">Sign in</a></p>
</div>');
