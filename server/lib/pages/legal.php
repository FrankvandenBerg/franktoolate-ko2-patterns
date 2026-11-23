<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Public legal pages: /privacy, /terms, /about. No sign-in needed, no JavaScript.
declare(strict_types=1);

$contact = '<a href="mailto:ciao@franktoolate.com">ciao@franktoolate.com</a>';
$source = h((string)config('source_url', 'https://github.com/FrankvandenBerg/franktoolate-ko2-patterns'));
$sourceLink = '<a href="' . $source . '">' . preg_replace('~^https?://~', '', $source) . '</a>';
$effective = '23 November 2026';
$back = current_user()
    ? '<nav class="top"><a href="' . h(url()) . '">← Back to the tool</a></nav>'
    : '<nav class="top"><a href="' . h(url('login')) . '">← Sign in</a><a href="' . h(url('register')) . '">Create an account</a></nav>';
$nav = '<nav class="top legalnav"><a href="' . h(url('privacy')) . '"' . ($route === 'privacy' ? ' aria-current="page"' : '') . '>Privacy</a>'
    . '<a href="' . h(url('terms')) . '"' . ($route === 'terms' ? ' aria-current="page"' : '') . '>Terms</a>'
    . '<a href="' . h(url('about')) . '"' . ($route === 'about' ? ' aria-current="page"' : '') . '>About</a></nav>';

if ($route === 'privacy') {
    page('Privacy policy', $back . '
<article class="legaltext">
<h1>Privacy <span>policy</span></h1>
<p class="mono">Effective date: ' . $effective . '</p>
<p>K.O. II Patterns is a free, non-commercial tool run by Frank van den Berg, a private individual in the Netherlands. I am the controller for the personal data described here. Questions or requests: ' . $contact . '.</p>

<h2>What I store and why</h2>
<div class="tbl"><table>
<thead><tr><th>Data</th><th>Why</th><th>Legal basis</th><th>How long</th></tr></thead>
<tbody>
<tr><td>Email address and the name you enter</td><td>To create and run your account, and to send confirmation and sign-in emails</td><td>Contract (GDPR art. 6(1)(b))</td><td>Until you delete your account</td></tr>
<tr><td>Passkey public key, its label (e.g. "MacBook"), creation and last-used date</td><td>To let you sign in without a password. Your private key never leaves your device.</td><td>Contract</td><td>Until you remove the passkey or delete your account</td></tr>
<tr><td>Account dates: created, email confirmed, last sign-in</td><td>To run your account</td><td>Contract</td><td>Until you delete your account</td></tr>
<tr><td>Activity log: sign-ins, sign-outs, failed sign-ins, passkeys added or removed, sign-in links requested, sounds shared or unshared, visits to the tool (at most one per 30 minutes), with date, time and IP address</td><td>Security, abuse prevention and seeing how the tool is used</td><td>Legitimate interest (art. 6(1)(f))</td><td>180 days, or until you delete your account</td></tr>
<tr><td>IP address of sign-in and sign-up attempts</td><td>Rate limiting against abuse</td><td>Legitimate interest</td><td>24 hours</td></tr>
<tr><td>One-time sign-in links (stored only as a hash)</td><td>Email sign-in and address confirmation</td><td>Contract</td><td>Valid for 1 hour, deleted within 1 day</td></tr>
<tr><td>Project backups: a copy of a K.O. II project, the device\'s serial number, project number, size and date</td><td>Made automatically before the tool writes to your device, so you can restore it</td><td>Contract</td><td>The newest 25 per project and device. You can delete them yourself; all are deleted with your account.</td></tr>
<tr><td>Sound list: names, sizes and settings of the sounds on your K.O. II, with the device\'s serial number</td><td>So the tool does not have to read the list over MIDI every time</td><td>Contract</td><td>Until replaced or your account is deleted</td></tr>
</tbody></table></div>
<p><b>Shared sounds.</b> If you share a sound, the sound file, its name and settings, the date and the name on your account are stored and shown to every signed-in user, together with how often it was downloaded. Legal basis: contract (you choose to share). You can stop sharing a sound at any time; shared sounds are also deleted with your account. Copies other users already downloaded to their own device stay with them.</p>
<p>The tool talks to your K.O. II directly from your browser over Web MIDI. Patterns, playback and other device data stay in your browser, except the backups, sound list and shared sounds above.</p>
<p>I do not use analytics, advertising or tracking, and I do not sell your data. Fonts are served from this site, not from Google or another third party.</p>

<h2>Cookies and local storage</h2>
<p>The site sets one cookie, <span class="mono">kostudio</span>, which keeps you signed in and expires when you close your browser. The tool also keeps some settings and your sound list in your browser\'s local storage. Both are strictly necessary for the tool to work, so no consent banner is needed.</p>

<h2>Who processes the data</h2>
<p>The site, database, backups, shared sounds and outgoing email are hosted by a hosting provider in the Netherlands, which processes the data only on my behalf. Data is not transferred outside the European Economic Area. The hosting provider may keep standard web server logs (including IP addresses) for a limited time.</p>

<h2>Your rights</h2>
<p>You can ask for access to, correction of, or deletion of your data, ask me to restrict processing, object to processing based on legitimate interest, and ask for a copy of your data in a portable format. You can delete your account yourself under <a href="' . h(url('account')) . '">Account</a>; this deletes your account, passkeys, backups, sound list, shared sounds and your activity log at once. For anything else, email ' . $contact . '. I will answer within one month.</p>
<p>If you think I handle your data wrongly, you can complain to the Dutch Data Protection Authority (<a href="https://autoriteitpersoonsgegevens.nl">Autoriteit Persoonsgegevens</a>).</p>

<h2>Security</h2>
<p>The site uses HTTPS only. There are no passwords: you sign in with a passkey or a one-time email link. Backups are stored outside the public web folder and are only available to your own account.</p>

<h2>Age</h2>
<p>The tool is meant for people aged 16 and over.</p>

<h2>Changes</h2>
<p>If this policy changes in a way that matters, I will tell you by email or in the tool. The date at the top shows the latest version.</p>
</article>' . $nav);
    exit;
}

if ($route === 'terms') {
    page('Terms of use', $back . '
<article class="legaltext">
<h1>Terms of <span>use</span></h1>
<p class="mono">Effective date: ' . $effective . '</p>
<p>By creating an account you agree to these terms.</p>
<p><b>1. The service.</b> K.O. II Patterns is a free, non-commercial hobby project by Frank van den Berg. It lets you read, play and rearrange patterns on a Teenage Engineering EP-133 K.O. II from your browser.</p>
<p><b>2. Not affiliated with Teenage Engineering.</b> This tool is an independent project. It is not affiliated with, endorsed by or supported by Teenage Engineering AB. "Teenage Engineering", "EP-133" and "K.O. II" are trademarks of Teenage Engineering AB and are used only to describe which device the tool works with. Do not contact Teenage Engineering support about this tool.</p>
<p><b>3. Use at your own risk.</b> The K.O. II\'s MIDI and file protocol is not officially documented; the tool is based on reverse-engineered behaviour. Writing to your device can corrupt or lose projects, and firmware updates can break the tool. The tool makes a backup before every write, but keep your own backups too (for example with Teenage Engineering\'s own tool). The service is provided "as is", without any warranty, and may change, stop working or be shut down at any time.</p>
<p><b>4. Liability.</b> To the extent the law allows, I am not liable for any damage or data loss resulting from using the tool, including loss of projects, samples or device settings. Nothing in these terms limits liability that cannot be limited under Dutch law, such as for intent or gross negligence.</p>
<p><b>5. Your account.</b> Give a real email address and keep your passkeys safe. One account per person. You may delete your account at any time. I may suspend or delete accounts that misuse the service, for example by trying to break in, overload the server or access other people\'s data.</p>
<p><b>6. Your content.</b> Your projects and sounds remain yours. Backups are stored only so you can restore them; I do not use them for anything else.</p>
<p><b>7. Sharing sounds.</b> You can share sounds with other users. Only share sounds you made yourself or have the right to share: no samples from other people\'s tracks, commercial sample packs or Teenage Engineering\'s factory sounds. By sharing a sound you give every user of the tool a free, worldwide, non-exclusive and permanent licence to use it in their own music, including music they release or sell. They may not sell or redistribute the sound itself as a sample, alone or in a sample pack. The name on your account is shown with every sound you share. You can stop sharing at any time, but copies already downloaded stay with the people who downloaded them. I may remove shared sounds that break these terms. To report a sound that infringes your rights, email ' . $contact . '.</p>
<p><b>8. Open source.</b> The tool\'s source code is available under the GNU Affero General Public License v3.0 at ' . $sourceLink . '. These terms cover the use of this hosted service; the licence covers the code.</p>
<p><b>9. Changes and law.</b> I may update these terms; important changes will be announced by email or in the tool. Dutch law applies.</p>
<p>Contact: ' . $contact . '.</p>
</article>' . $nav);
    exit;
}

// about
page('About', $back . '
<article class="legaltext">
<h1>About K.O. II <span>Patterns</span></h1>
<p>K.O. II Patterns is a free web tool for the Teenage Engineering EP-133 K.O. II. Connect your K.O. II over USB, and the tool reads your projects so you can browse, play and rearrange patterns, scenes and songs in Chrome or Edge. It is a hobby project by Frank van den Berg.</p>
<p><b>Not affiliated with Teenage Engineering.</b> This is an independent project, not affiliated with, endorsed by or supported by Teenage Engineering AB. "Teenage Engineering", "EP-133" and "K.O. II" are their trademarks and are used only to describe compatibility. The tool does not include or distribute any Teenage Engineering firmware, sounds or software.</p>
<p><b>Open source.</b> K.O. II Patterns is free software, licensed under the GNU Affero General Public License v3.0 or later (AGPL-3.0-or-later). You can read, change and share the code under that licence. The full source code of the version running here is at ' . $sourceLink . '. If you run a modified version for others over a network, the AGPL requires you to offer them your source code as well.</p>
<p><b>Credits.</b> Reading projects from the device builds on <a href="https://github.com/phones24/ep133-export-to-daw">ep133-export-to-daw</a> by phones24. Fonts: Archivo and IBM Plex Mono, under the SIL Open Font License.</p>
<p><b>No warranty.</b> The device protocol is not officially documented. Use at your own risk and keep backups. See the <a href="' . h(url('terms')) . '">Terms</a> and the <a href="' . h(url('privacy')) . '">Privacy policy</a>.</p>
<p>Contact: ' . $contact . '</p>
</article>' . $nav);
exit;
