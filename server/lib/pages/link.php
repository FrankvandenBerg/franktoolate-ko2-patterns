<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// One-time email link: confirms the address and signs in. Shown as a button so that
// mail scanners that prefetch links do not use up the token.
declare(strict_types=1);

$raw = (string)($_GET['t'] ?? $_POST['t'] ?? '');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    check_csrf();
    $u = $raw !== '' ? use_token($raw) : null;
    if ($u) {
        log_in($u);
        redirect(passkey_count((int)$u['id']) === 0 ? 'passkey' : '');
    }
    page('Link expired', '
<div class="box narrow">
  <h1>Link <span>expired</span></h1>
  <p>This link was already used or has expired. Request a new one on the sign-in page.</p>
  <p><a href="' . h(url('login')) . '">Go to sign in</a></p>
</div>');
    exit;
}

page('Continue', '
<div class="box narrow">
  <h1>K.O. II <span>patterns</span></h1>
  <form method="post" action="' . h(url('link')) . '">' . csrf_field() . '
    <input type="hidden" name="t" value="' . h($raw) . '">
    <p>Confirm that it is you to continue.</p>
    <button type="submit">Continue</button>
  </form>
</div>');
