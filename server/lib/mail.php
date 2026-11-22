<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Minimal SMTP client (implicit TLS on port 465, AUTH LOGIN). No external libraries needed.
declare(strict_types=1);

function send_mail(string $to, string $subject, string $text): void {
    $host = (string)config('smtp_host', 'localhost');
    $port = (int)config('smtp_port', 465);
    $user = (string)config('smtp_user');
    $pass = (string)config('smtp_pass');
    $from = (string)config('mail_from', $user);
    $fromName = (string)config('mail_from_name', 'K.O. II Patterns');

    $fp = stream_socket_client("ssl://$host:$port", $errno, $errstr, 20, STREAM_CLIENT_CONNECT,
        stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]));
    if (!$fp) throw new RuntimeException("SMTP connect failed: $errstr");
    stream_set_timeout($fp, 20);

    $read = function () use ($fp): string {
        $out = '';
        while (($line = fgets($fp, 1024)) !== false) {
            $out .= $line;
            if (strlen($line) < 4 || $line[3] === ' ') break;
        }
        return $out;
    };
    $cmd = function (?string $c, string $expect) use ($fp, $read): void {
        if ($c !== null) fwrite($fp, $c . "\r\n");
        $r = $read();
        if (strncmp($r, $expect, strlen($expect)) !== 0) throw new RuntimeException('SMTP error: ' . trim($r));
    };

    $cmd(null, '220');
    $cmd('EHLO ' . (parse_url((string)config('base_url', 'https://localhost'), PHP_URL_HOST) ?: 'localhost'), '250');
    $cmd('AUTH LOGIN', '334');
    $cmd(base64_encode($user), '334');
    $cmd(base64_encode($pass), '235');
    $cmd('MAIL FROM:<' . $from . '>', '250');
    $cmd('RCPT TO:<' . $to . '>', '25');
    $cmd('DATA', '354');

    $headers = [
        'From: ' . mb_encode_mimeheader($fromName) . ' <' . $from . '>',
        'To: <' . $to . '>',
        'Subject: ' . mb_encode_mimeheader($subject),
        'Date: ' . date(DATE_RFC2822),
        'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . substr(strrchr($from, '@') ?: '@localhost', 1) . '>',
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: 8bit',
    ];
    $body = str_replace(["\r\n", "\r"], "\n", $text);
    $body = preg_replace('/^\./m', '..', $body);            // dot-stuffing
    $body = str_replace("\n", "\r\n", $body);
    fwrite($fp, implode("\r\n", $headers) . "\r\n\r\n" . $body . "\r\n.\r\n");
    $cmd(null, '250');
    fwrite($fp, "QUIT\r\n");
    fclose($fp);
}
