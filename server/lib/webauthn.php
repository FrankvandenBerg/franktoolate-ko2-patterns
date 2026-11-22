<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Minimal WebAuthn (passkey) server side: CBOR decoding, COSE key → PEM, and verification with OpenSSL.
// Supports ES256 (-7) and RS256 (-257) with attestation "none".
declare(strict_types=1);

function b64u_enc(string $bin): string { return rtrim(strtr(base64_encode($bin), '+/', '-_'), '='); }
function b64u_dec(string $s): string {
    $r = base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4), true);
    if ($r === false) throw new RuntimeException('Invalid base64url data');
    return $r;
}

// ---- CBOR ----
final class Cbor {
    private int $pos = 0;
    public function __construct(private string $data) {}
    public static function decode(string $data, ?int &$consumed = null) {
        $c = new self($data);
        $v = $c->item();
        $consumed = $c->pos;
        return $v;
    }
    private function byte(): int {
        if ($this->pos >= strlen($this->data)) throw new RuntimeException('CBOR: unexpected end');
        return ord($this->data[$this->pos++]);
    }
    private function bytes(int $n): string {
        if ($this->pos + $n > strlen($this->data)) throw new RuntimeException('CBOR: unexpected end');
        $s = substr($this->data, $this->pos, $n); $this->pos += $n; return $s;
    }
    private function length(int $info): int {
        if ($info < 24) return $info;
        if ($info === 24) return $this->byte();
        if ($info === 25) return unpack('n', $this->bytes(2))[1];
        if ($info === 26) return unpack('N', $this->bytes(4))[1];
        if ($info === 27) return unpack('J', $this->bytes(8))[1];
        throw new RuntimeException('CBOR: indefinite lengths are not supported');
    }
    private function item() {
        $b = $this->byte();
        $major = $b >> 5; $info = $b & 31;
        switch ($major) {
            case 0: return $this->length($info);
            case 1: return -1 - $this->length($info);
            case 2: return ['_bytes' => $this->bytes($this->length($info))];
            case 3: return $this->bytes($this->length($info));
            case 4: $n = $this->length($info); $a = []; for ($i = 0; $i < $n; $i++) $a[] = $this->item(); return $a;
            case 5: $n = $this->length($info); $m = [];
                for ($i = 0; $i < $n; $i++) { $k = $this->item(); $m[is_array($k) ? json_encode($k) : $k] = $this->item(); }
                return $m;
            case 6: $this->length($info); return $this->item(); // tag: ignore
            case 7:
                if ($info === 20) return false;
                if ($info === 21) return true;
                if ($info === 22 || $info === 23) return null;
                throw new RuntimeException('CBOR: unsupported simple value');
        }
        throw new RuntimeException('CBOR: bad major type');
    }
}

function cbor_bytes($v): string {
    if (!is_array($v) || !isset($v['_bytes'])) throw new RuntimeException('CBOR: expected a byte string');
    return $v['_bytes'];
}

// ---- DER helpers ----
function der_len(int $n): string {
    if ($n < 128) return chr($n);
    $s = ltrim(pack('N', $n), "\0");
    return chr(0x80 | strlen($s)) . $s;
}
function der(int $tag, string $content): string { return chr($tag) . der_len(strlen($content)) . $content; }
function der_uint(string $bin): string {
    $bin = ltrim($bin, "\0");
    if ($bin === '' || (ord($bin[0]) & 0x80)) $bin = "\0" . $bin;
    return der(0x02, $bin);
}

function cose_to_pem(array $cose): array {
    $kty = $cose[1] ?? null; $alg = $cose[3] ?? null;
    if ($kty === 2 && $alg === -7) {
        if (($cose[-1] ?? null) !== 1) throw new RuntimeException('Only the P-256 curve is supported');
        $x = cbor_bytes($cose[-2]); $y = cbor_bytes($cose[-3]);
        if (strlen($x) !== 32 || strlen($y) !== 32) throw new RuntimeException('Bad EC key');
        $der = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200') . "\x04" . $x . $y;
    } elseif ($kty === 3 && $alg === -257) {
        $n = cbor_bytes($cose[-1]); $e = cbor_bytes($cose[-2]);
        $rsa = der(0x30, der_uint($n) . der_uint($e));
        $algId = der(0x30, der(0x06, hex2bin('2a864886f70d010101')) . "\x05\x00");
        $der = der(0x30, $algId . der(0x03, "\x00" . $rsa));
    } else {
        throw new RuntimeException('Unsupported passkey algorithm');
    }
    $pem = "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
    if (!openssl_pkey_get_public($pem)) throw new RuntimeException('Could not read the passkey public key');
    return [$pem, $alg];
}

function rp_id(): string { return (string)parse_url((string)config('base_url'), PHP_URL_HOST); }
function rp_origin(): string { return rtrim((string)config('base_url'), '/'); }

function check_client_data(string $clientDataJSON, string $type, string $challenge): void {
    $cd = json_decode($clientDataJSON, true);
    if (!is_array($cd)) throw new RuntimeException('Bad client data');
    if (($cd['type'] ?? '') !== $type) throw new RuntimeException('Wrong ceremony type');
    if (!hash_equals($challenge, b64u_dec((string)($cd['challenge'] ?? '')))) throw new RuntimeException('Challenge mismatch');
    if (($cd['origin'] ?? '') !== rp_origin()) throw new RuntimeException('Origin mismatch');
}

function parse_auth_data(string $ad): array {
    if (strlen($ad) < 37) throw new RuntimeException('Authenticator data too short');
    $out = [
        'rpIdHash' => substr($ad, 0, 32),
        'flags' => ord($ad[32]),
        'signCount' => unpack('N', substr($ad, 33, 4))[1],
    ];
    if (!hash_equals(hash('sha256', rp_id(), true), $out['rpIdHash'])) throw new RuntimeException('RP ID mismatch');
    if (!($out['flags'] & 0x01)) throw new RuntimeException('User presence missing');
    if ($out['flags'] & 0x40) {
        $credLen = unpack('n', substr($ad, 53, 2))[1];
        $out['credId'] = substr($ad, 55, $credLen);
        $out['cose'] = Cbor::decode(substr($ad, 55 + $credLen));
    }
    return $out;
}

/** Verify navigator.credentials.create() output; returns [credentialId, pem, alg, signCount]. */
function webauthn_verify_registration(array $resp, string $challenge): array {
    $clientDataJSON = b64u_dec((string)($resp['clientDataJSON'] ?? ''));
    check_client_data($clientDataJSON, 'webauthn.create', $challenge);
    $att = Cbor::decode(b64u_dec((string)($resp['attestationObject'] ?? '')));
    $ad = parse_auth_data(cbor_bytes($att['authData'] ?? null));
    if (empty($ad['credId']) || !is_array($ad['cose'] ?? null)) throw new RuntimeException('No credential in response');
    [$pem, $alg] = cose_to_pem($ad['cose']);
    return [$ad['credId'], $pem, $alg, $ad['signCount']];
}

/** Verify navigator.credentials.get() output against a stored key; returns the new sign count. */
function webauthn_verify_assertion(array $resp, string $challenge, string $pem, int $alg, int $storedCount): int {
    $clientDataJSON = b64u_dec((string)($resp['clientDataJSON'] ?? ''));
    check_client_data($clientDataJSON, 'webauthn.get', $challenge);
    $authData = b64u_dec((string)($resp['authenticatorData'] ?? ''));
    $ad = parse_auth_data($authData);
    $sig = b64u_dec((string)($resp['signature'] ?? ''));
    $ok = openssl_verify($authData . hash('sha256', $clientDataJSON, true), $sig, $pem, OPENSSL_ALGO_SHA256);
    if ($ok !== 1) throw new RuntimeException('Signature check failed');
    if ($storedCount > 0 && $ad['signCount'] > 0 && $ad['signCount'] <= $storedCount) throw new RuntimeException('Passkey counter went backwards');
    return $ad['signCount'];
}
