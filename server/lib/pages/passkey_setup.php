<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Create a passkey right after confirming the email (required before using the tool).
declare(strict_types=1);

$u = current_user();
if (!$u) redirect('login');
$has = passkey_count((int)$u['id']) > 0;

page('Create your passkey', '
<div class="box narrow">
  <h1>Create your <span>passkey</span></h1>
  <p>' . ($has ? 'Add a passkey for this device.' : 'Your email is confirmed. One more step: create a passkey. You will use it to sign in from now on, without a password.') . '</p>
  <form onsubmit="return false">
    <label for="pk-label">Name for this passkey (optional)<input id="pk-label" type="text" placeholder="for example: MacBook Chrome"></label>
    <button type="button" data-passkey="register" data-label-input="pk-label">Create passkey</button>
    <div id="pk-msg" role="status"></div>
  </form>
  <p>Your browser or password manager will ask you to confirm with Touch ID, Face ID, Windows Hello, your phone or a security key.</p>
  ' . ($has ? '<p><a href="' . h(url('account')) . '">Back to your account</a></p>' : '') . '
</div>', 'register');
