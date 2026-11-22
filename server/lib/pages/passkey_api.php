<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// JSON endpoints for passkey registration and sign-in.
declare(strict_types=1);

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') json_out(['error' => 'Use POST.'], 405);
check_csrf();
$action = substr($route, strlen('passkey/'));
$in = json_in();

try {
    switch ($action) {
        case 'register-options': {
            $u = current_user();
            if (!$u) json_out(['error' => 'Sign in first.'], 401);
            $challenge = random_bytes(32);
            $_SESSION['reg_challenge'] = $challenge;
            $st = db()->prepare('SELECT credential_id FROM ko_passkeys WHERE user_id = ?');
            $st->execute([$u['id']]);
            json_out([
                'challenge' => b64u_enc($challenge),
                'rp' => ['id' => rp_id(), 'name' => 'K.O. II Patterns'],
                'user' => ['id' => $u['handle'], 'name' => $u['email'], 'displayName' => $u['name'] ?: $u['email']],
                'pubKeyCredParams' => [['type' => 'public-key', 'alg' => -7], ['type' => 'public-key', 'alg' => -257]],
                'authenticatorSelection' => ['residentKey' => 'required', 'requireResidentKey' => true, 'userVerification' => 'preferred'],
                'attestation' => 'none',
                'timeout' => 120000,
                'excludeCredentials' => array_map(fn($r) => ['type' => 'public-key', 'id' => $r['credential_id']], $st->fetchAll()),
            ]);
        }
        case 'register': {
            $u = current_user();
            if (!$u) json_out(['error' => 'Sign in first.'], 401);
            $challenge = $_SESSION['reg_challenge'] ?? null;
            unset($_SESSION['reg_challenge']);
            if (!$challenge) json_out(['error' => 'The request expired. Try again.'], 400);
            [$credId, $pem, $alg, $count] = webauthn_verify_registration($in, $challenge);
            $label = trim(substr((string)($in['label'] ?? ''), 0, 100));
            if ($label === '') $label = device_label();
            db()->prepare('INSERT INTO ko_passkeys (user_id, credential_id, public_key, alg, sign_count, label, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)')
                ->execute([$u['id'], b64u_enc($credId), $pem, $alg, $count, $label, now()]);
            json_out(['ok' => true, 'redirect' => url('')]);
        }
        case 'login-options': {
            if (rate_limited('passkey', 30, 900)) json_out(['error' => 'Too many attempts. Wait 15 minutes and try again.'], 429);
            $challenge = random_bytes(32);
            $_SESSION['login_challenge'] = $challenge;
            json_out(['challenge' => b64u_enc($challenge), 'rpId' => rp_id(), 'userVerification' => 'preferred', 'timeout' => 120000]);
        }
        case 'login': {
            $challenge = $_SESSION['login_challenge'] ?? null;
            unset($_SESSION['login_challenge']);
            if (!$challenge) json_out(['error' => 'The request expired. Try again.'], 400);
            $st = db()->prepare('SELECT p.*, u.active FROM ko_passkeys p JOIN ko_users u ON u.id = p.user_id WHERE p.credential_id = ?');
            $st->execute([(string)($in['id'] ?? '')]);
            $pk = $st->fetch();
            if (!$pk || !$pk['active']) json_out(['error' => 'This passkey is not linked to an active account.'], 401);
            $count = webauthn_verify_assertion($in, $challenge, $pk['public_key'], (int)$pk['alg'], (int)$pk['sign_count']);
            db()->prepare('UPDATE ko_passkeys SET sign_count = ?, last_used_at = ? WHERE id = ?')->execute([$count, now(), $pk['id']]);
            $st = db()->prepare('SELECT * FROM ko_users WHERE id = ?');
            $st->execute([$pk['user_id']]);
            log_in($st->fetch());
            json_out(['ok' => true, 'redirect' => url('')]);
        }
        default:
            json_out(['error' => 'Unknown action.'], 404);
    }
} catch (RuntimeException $e) {
    json_out(['error' => 'Passkey check failed: ' . $e->getMessage()], 400);
}

function device_label(): string {
    $ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
    $os = str_contains($ua, 'iPhone') ? 'iPhone' : (str_contains($ua, 'iPad') ? 'iPad' : (str_contains($ua, 'Android') ? 'Android'
        : (str_contains($ua, 'Mac OS') ? 'Mac' : (str_contains($ua, 'Windows') ? 'Windows' : (str_contains($ua, 'Linux') ? 'Linux' : 'Device')))));
    $br = str_contains($ua, 'Edg/') ? 'Edge' : (str_contains($ua, 'Chrome/') ? 'Chrome' : (str_contains($ua, 'Firefox/') ? 'Firefox' : (str_contains($ua, 'Safari/') ? 'Safari' : '')));
    return trim("$os $br");
}
