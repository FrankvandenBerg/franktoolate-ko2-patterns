<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Document root entry point. The application lives outside the web root:
// on the server in ~/kostudio (next to the subdomain's document root), locally in server/.
$appDir = getenv('KO_APP_DIR') ?: (is_dir(dirname(__DIR__) . '/kostudio/lib') ? dirname(__DIR__) . '/kostudio' : dirname(__DIR__));
require $appDir . '/lib/router.php';
