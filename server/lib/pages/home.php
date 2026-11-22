<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Launcher: the tools this user may open.
declare(strict_types=1);

$cards = '';
foreach ($tools as $t) {
    $badge = $t['status'] !== 'live' ? ' <span class="pill">' . h($t['status']) . '</span>' : '';
    $cards .= '<a class="tool" href="' . h(url($t['slug'] . '/')) . '"><strong>' . h($t['name']) . $badge . '</strong><span>' . h($t['description']) . '</span></a>';
}

page('Your tools', '
<nav class="top"><span class="mono">' . h($u['name'] ?: $u['email']) . '</span>' . ($u['is_admin'] ? '<a href="' . h(url('admin')) . '">Admin</a>' : '') . '<a href="' . h(url('account')) . '">Account</a>
<form method="post" action="' . h(url('logout')) . '" style="display:inline;background:none;border:0;padding:0">' . csrf_field() . '<button class="link">Sign out</button></form></nav>
<h1>Your <span>tools</span></h1>
' . ($cards ? '<div class="tools">' . $cards . '</div>' : '<p>No tools are available for your account yet.</p>'));
