<?php
// Copy to config.local.php on the server (next to lib/ and app/) and fill it in. Never commit config.local.php.
return [
    // Public address of the tool. Passkeys are bound to this host name.
    'base_url' => 'https://kostudio.example.com',

    // MySQL (on cPanel, names get your account prefix, e.g. account_kostudio)
    'db_dsn'  => 'mysql:host=localhost;dbname=account_kostudio;charset=utf8mb4',
    'db_user' => 'account_kostudio',
    'db_pass' => 'change-me',

    // Outgoing mail (sign-up confirmations and sign-in links)
    'smtp_host' => 'mail.example.com',
    'smtp_port' => 465,
    'smtp_user' => 'noreply@example.com',
    'smtp_pass' => 'change-me',
    'mail_from' => 'noreply@example.com',
    'mail_from_name' => 'K.O. II Patterns',

    // Where users can get the source code (AGPL)
    'source_url' => 'https://github.com/franktoolate/ko2-patterns',

    // Needed once, to create the first admin account at /setup. Use a long random string.
    'setup_token' => 'change-me-to-a-long-random-string',
];
