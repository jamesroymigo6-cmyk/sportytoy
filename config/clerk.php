<?php
/**
 * SportSync × Clerk authentication bridge.
 *
 * Adds Clerk email authentication on top of the classic SportSync accounts:
 * - ClerkJS is loaded on the auth pages (sign-in / sign-up components).
 * - This library verifies Clerk session JWTs against the instance JWKS
 *   (RS256 via OpenSSL, no Composer packages required) and talks to the
 *   Clerk Backend REST API with cURL.
 * - Local users are provisioned/linked automatically, keeping every existing
 *   SportSync feature (roles, capabilities, SMS, messaging) working unchanged.
 *
 * Required .env values (all optional — when absent the classic auth is used):
 *   CLERK_PUBLISHABLE_KEY=pk_...
 *   CLERK_SECRET_KEY=sk_...
 *   CLERK_FAPI_URL=https://clerk-app.clerk.accounts.dev  (Clerk Frontend API URL)
 *   CLERK_WEBHOOK_SECRET=whsec_...                       (Svix signing secret)
 */

require_once __DIR__ . '/app.php';

if (!defined('CLERK_PUBLISHABLE_KEY')) define('CLERK_PUBLISHABLE_KEY', (string)sportsync_env('CLERK_PUBLISHABLE_KEY', ''));
if (!defined('CLERK_SECRET_KEY'))      define('CLERK_SECRET_KEY', (string)sportsync_env('CLERK_SECRET_KEY', ''));
if (!defined('CLERK_FAPI_URL'))        define('CLERK_FAPI_URL', rtrim((string)sportsync_env('CLERK_FAPI_URL', ''), '/'));
if (!defined('CLERK_WEBHOOK_SECRET'))  define('CLERK_WEBHOOK_SECRET', (string)sportsync_env('CLERK_WEBHOOK_SECRET', ''));

const CLERK_API_BASE = 'https://api.clerk.com/v1';

/**
 * Order-safe Clerk configuration check.
 *
 * This file may be loaded before OR after app.php (app.php requires it for the
 * CSP headers, but standalone entry points may require it first). When loaded
 * first, the CLERK_* constants are not yet defined while app.php's header phase
 * runs, so fall back to the environment directly instead of relying on them.
 */
function sportsync_clerk_enabled(): bool {
    $secret = defined('CLERK_SECRET_KEY') ? CLERK_SECRET_KEY : (string)sportsync_env('CLERK_SECRET_KEY', '');
    $public = defined('CLERK_PUBLISHABLE_KEY') ? CLERK_PUBLISHABLE_KEY : (string)sportsync_env('CLERK_PUBLISHABLE_KEY', '');
    return $secret !== '' && $public !== '';
}

/* ------------------------------------------------------------------ */
/* Clerk Backend REST API (cURL)                                       */
/* ------------------------------------------------------------------ */
/**
 * Perform a Clerk Backend API call.
 * Returns ['ok'=>bool,'status'=>int,'data'=>array].
 */
function sportsync_clerk_api(string $method, string $path, array $params = []): array {
    if (!sportsync_clerk_enabled()) return ['ok' => false, 'status' => 0, 'data' => []];
    $method = strtoupper($method);
    $url = CLERK_API_BASE . $path;
    $ch = curl_init($url);
    $headers = [
        'Authorization: Bearer ' . CLERK_SECRET_KEY,
        'Accept: application/json',
        'Clerk-API-Version: 2025-04-10',
    ];
    $body = null;
    if ($params !== []) {
        if (in_array($method, ['GET', 'DELETE'], true)) {
            $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query($params);
            curl_setopt($ch, CURLOPT_URL, $url);
        } else {
            $headers[] = 'Content-Type: application/json';
            $body = json_encode($params, JSON_UNESCAPED_UNICODE);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
    }
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 12,
        CURLOPT_CONNECTTIMEOUT => 6,
    ]);
    $res = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($res === false || $status === 0) {
        sportsync_log('Clerk API transport failure [' . $method . ' ' . $path . ']: ' . ($err ?: 'no response'));
        return ['ok' => false, 'status' => 0, 'data' => []];
    }
    $data = json_decode((string)$res, true);
    if (!is_array($data)) $data = [];
    if ($status >= 400) {
        $detail = $data['errors'][0]['long_message'] ?? ($data['errors'][0]['message'] ?? 'unknown');
        sportsync_log('Clerk API error [' . $method . ' ' . $path . '] HTTP ' . $status . ': ' . $detail);
    }
    return ['ok' => $status >= 200 && $status < 300, 'status' => $status, 'data' => $data];
}

/** Fetch a Clerk user object by Clerk user id (e.g. user_2abc...). */
function sportsync_clerk_get_user(string $clerkId): ?array {
    $r = sportsync_clerk_api('GET', '/users/' . rawurlencode($clerkId));
    return $r['ok'] ? $r['data'] : null;
}

/** Find a Clerk user by (primary or any) email address. */
function sportsync_clerk_user_by_email(string $email): ?array {
    $r = sportsync_clerk_api('GET', '/users', ['email_address' => $email, 'limit' => 5, 'order_by' => '-created_at']);
    if (!$r['ok']) return null;
    foreach ((array)($r['data'] ?? []) as $u) {
        if (($u['email_addresses'][0]['email_address'] ?? '') === strtolower($email)) return $u;
    }
    return null;
}

/**
 * Create a Clerk user. Password is optional; when omitted the account signs in
 * with email verification codes. A verification email is prepared either way.
 */
function sportsync_clerk_create_user(string $email, string $fullName, ?string $phone = null, ?string $password = null): array {
    $parts = preg_split('/\s+/', trim($fullName), 2) ?: ['SportSync'];
    $params = [
        'email_address' => strtolower($email),
        'first_name'    => $parts[0] ?: 'SportSync',
        'last_name'     => $parts[1] ?? '',
    ];
    if ($phone !== null && $phone !== '') $params['phone_number'] = $phone;
    if ($password !== null && $password !== '') {
        $params['password'] = $password;
        $params['skip_password_checks'] = 'true';
    }
    $r = sportsync_clerk_api('POST', '/users', $params);
    if (!$r['ok']) {
        return ['ok' => false, 'id' => null, 'error' => $r['data']['errors'][0]['long_message'] ?? 'Clerk rejected the account.'];
    }
    $user = $r['data'];
    // Ensure the verification email is sent even when the instance has
    // "auto-verify on creation" disabled.
    $primary = $user['primary_email_address_id'] ?? ($user['email_addresses'][0]['id'] ?? null);
    if ($primary && ($user['email_addresses'][0]['verification']['status'] ?? null) !== 'verified') {
        sportsync_clerk_api('POST', '/email_addresses/' . rawurlencode((string)$primary) . '/prepare_verification');
    }
    return ['ok' => true, 'id' => $user['id'] ?? null, 'error' => null];
}

function sportsync_clerk_delete_user(string $clerkId): bool {
    return sportsync_clerk_api('DELETE', '/users/' . rawurlencode($clerkId))['ok'];
}

/* ------------------------------------------------------------------ */
/* Session JWT verification against the instance JWKS                  */
/* ------------------------------------------------------------------ */
function sportsync_clerk_cache_dir(): string {
    return SPORTSYNC_ROOT . '/storage/cache';
}

function sportsync_clerk_jwks(): array {
    $dir = sportsync_clerk_cache_dir();
    $file = $dir . '/clerk_jwks.json';
    if (is_file($file)) {
        $cached = json_decode((string)@file_get_contents($file), true);
        if (is_array($cached) && !empty($cached['keys']) && (time() - (int)($cached['fetched_at'] ?? 0)) < 600) {
            return $cached;
        }
    }
    $url = CLERK_FAPI_URL !== '' ? CLERK_FAPI_URL . '/.well-known/jwks.json' : '';
    if ($url === '') return ['keys' => [], 'fetched_at' => 0];
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8, CURLOPT_CONNECTTIMEOUT => 4]);
    $res = curl_exec($ch);
    curl_close($ch);
    $jwks = json_decode((string)$res, true);
    if (!is_array($jwks) || empty($jwks['keys'])) {
        sportsync_log('Clerk JWKS fetch failed; using stale cache if available.');
        return is_array($cached ?? null) ? $cached : ['keys' => [], 'fetched_at' => 0];
    }
    $jwks['fetched_at'] = time();
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    @file_put_contents($file, json_encode($jwks), LOCK_EX);
    return $jwks;
}

function sportsync_clerk_jwk_to_pem(array $jwk): ?string {
    $n = $jwk['n'] ?? null; $e = $jwk['e'] ?? null;
    if (!$n || !$e) return null;
    $decode = fn(string $v): string => str_pad(ltrim(base64_decode(strtr($v, '-_', '+/'), true) ?: '', "\0"), 1, "\0");
    $mod = $decode($n); $exp = $decode($e);

    // DER length octets: short form up to 127 bytes, long form (0x80 | byte
    // count + big-endian length) beyond that.
    $encodeLen = function (string $bytes): string {
        $len = strlen($bytes);
        if ($len <= 127) return chr($len);
        $lenBytes = ltrim(pack('N', $len), "\0");
        return chr(0x80 | strlen($lenBytes)) . $lenBytes;
    };
    // INTEGER: the leading zero byte (needed when the high bit is set) must be
    // added BEFORE the length is computed.
    $int = function (string $bytes) use ($encodeLen): string {
        if (ord($bytes[0]) > 0x7f) $bytes = "\x00" . $bytes;
        return "\x02" . $encodeLen($bytes) . $bytes;
    };

    $rsaSeq = "\x30" . $encodeLen($int($mod) . $int($exp)) . $int($mod) . $int($exp);
    $algId  = "\x30\x0d\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01\x05\x00";
    // BIT STRING: one leading octet for unused bits (0) + the wrapped key DER.
    $bitStr = "\x03" . $encodeLen("\x00" . $rsaSeq) . "\x00" . $rsaSeq;
    $outer  = "\x30" . $encodeLen($algId . $bitStr) . $algId . $bitStr;

    return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($outer), 64) . "-----END PUBLIC KEY-----\n";
}

/**
 * Verify a Clerk session JWT (RS256). Returns the token claims array or null.
 * The caller must treat a null result as "not signed in".
 */
function sportsync_clerk_verify_token(string $jwt): ?array {
    if ($jwt === '' || !sportsync_clerk_enabled()) return null;
    $parts = explode('.', $jwt);
    if (count($parts) !== 3) return null;
    $decode = function (string $s): ?array {
        $json = base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4), true);
        $arr = $json === false ? null : json_decode($json, true);
        return is_array($arr) ? $arr : null;
    };
    $header = $decode($parts[0]); $payload = $decode($parts[1]); $sig = base64_decode(strtr($parts[2], '-_', '+/') . str_repeat('=', (4 - strlen($parts[2]) % 4) % 4), true);
    if (!$header || !$payload || $sig === false) return null;
    if (($header['alg'] ?? '') !== 'RS256') return null;

    $jwks = sportsync_clerk_jwks();
    $kid = $header['kid'] ?? '';
    $jwk = null;
    foreach ((array)($jwks['keys'] ?? []) as $k) if (($k['kid'] ?? '') === $kid) { $jwk = $k; break; }
    if (!$jwk && (time() - (int)($jwks['fetched_at'] ?? 0)) > 0) {
        @unlink(sportsync_clerk_cache_dir() . '/clerk_jwks.json');
        $jwks = sportsync_clerk_jwks();
        foreach ((array)($jwks['keys'] ?? []) as $k) if (($k['kid'] ?? '') === $kid) { $jwk = $k; break; }
    }
    if (!$jwk) return null;
    $pem = sportsync_clerk_jwk_to_pem($jwk);
    if (!$pem) return null;
    $ok = openssl_verify($parts[0] . '.' . $parts[1], $sig, $pem, OPENSSL_ALGO_SHA256) === 1;
    if (!$ok) return null;

    $now = time();
    if (($payload['exp'] ?? 0) < $now || ($payload['nbf'] ?? $now) > $now + 5) return null;
    // Reject tokens minted for another Clerk application on the same instance.
    if (isset($payload['v']) && $payload['v'] !== 2) return null;
    return $payload;
}

/** Read the current Clerk session token from cookie, POST or GET. */
function sportsync_clerk_request_token(): string {
    $t = (string)($_POST['clerk_token'] ?? $_GET['clerk_token'] ?? '');
    if ($t !== '') return $t;
    return (string)($_COOKIE['__session'] ?? '');
}

/**
 * Verify the token and return the full Clerk user object (REST refresh) or null.
 */
function sportsync_clerk_authenticate(string $token): ?array {
    $claims = sportsync_clerk_verify_token($token);
    if (!$claims || empty($claims['sub'])) return null;
    return sportsync_clerk_get_user((string)$claims['sub']);
}

/* ------------------------------------------------------------------ */
/* Local provisioning / linking                                        */
/* ------------------------------------------------------------------ */
/** Pick the local role id for a provisioned account. */
function sportsync_clerk_role_id(PDO $pdo, string $preferred = ''): ?int {
    $order = array_values(array_filter(array_unique(array_merge(
        $preferred !== '' ? [$preferred] : [],
        ['Participant/Athlete', 'Spectator/Community Member']
    ))));
    $q = $pdo->prepare('SELECT id FROM roles WHERE name=? LIMIT 1');
    foreach ($order as $name) { $q->execute([$name]); if ($id = $q->fetchColumn()) return (int)$id; }
    return $pdo->query('SELECT id FROM roles ORDER BY id ASC LIMIT 1')->fetchColumn() ? (int)$pdo->query('SELECT id FROM roles ORDER BY id ASC LIMIT 1')->fetchColumn() : null;
}

/**
 * Find or create the local SportSync user for a verified Clerk user, and
 * always keep clerk_id linked. Returns the same row shape login_user() needs.
 */
function sportsync_clerk_provision(PDO $pdo, array $clerkUser, string $preferredRole = '', string $preferredPhone = ''): ?array {
    $clerkId = (string)($clerkUser['id'] ?? '');
    if ($clerkId === '') return null;

    $email = '';
    $primaryId = (string)($clerkUser['primary_email_address_id'] ?? '');
    foreach ((array)($clerkUser['email_addresses'] ?? []) as $ea) {
        if (($ea['verification']['status'] ?? '') === 'verified' && ($ea['email_address'] ?? '') !== '') {
            if ($email === '' || ($primaryId !== '' && ($ea['id'] ?? '') === $primaryId)) $email = strtolower((string)$ea['email_address']);
        }
    }
    if ($email === '') return null; // require at least one verified email

    $name = trim(($clerkUser['first_name'] ?? '') . ' ' . ($clerkUser['last_name'] ?? ''));
    if ($name === '') $name = ucfirst(strtok($email, '@') ?: 'SportSync member');
    $phone = $preferredPhone;
    if ($phone === '') {
        $primaryPhoneId = (string)($clerkUser['primary_phone_number_id'] ?? '');
        foreach ((array)($clerkUser['phone_numbers'] ?? []) as $pn) {
            if (($primaryPhoneId !== '' && ($pn['id'] ?? '') === $primaryPhoneId) || $phone === '') {
                $phone = (string)($pn['phone_number'] ?? '');
                if ($primaryPhoneId !== '' && ($pn['id'] ?? '') === $primaryPhoneId) break;
            }
        }
    }

    $pdo->beginTransaction();
    try {
        $q = $pdo->prepare('SELECT u.*, r.name role_name FROM users u JOIN roles r ON r.id=u.role_id WHERE u.clerk_id=? LIMIT 1');
        $q->execute([$clerkId]);
        $row = $q->fetch();

        if (!$row) {
            $q2 = $pdo->prepare('SELECT u.*, r.name role_name FROM users u JOIN roles r ON r.id=u.role_id WHERE u.email=? LIMIT 1');
            $q2->execute([$email]);
            $row = $q2->fetch();
            if ($row) {
                // Existing classic account: link it to the Clerk identity.
                $pdo->prepare('UPDATE users SET clerk_id=?, email_verified_at=COALESCE(email_verified_at,NOW()) WHERE id=?')->execute([$clerkId, $row['id']]);
            }
        }

        if (!$row) {
            $roleId = sportsync_clerk_role_id($pdo, $preferredRole);
            if (!$roleId) throw new RuntimeException('No roles configured.');
            // Unusable random password hash: Clerk owns authentication.
            $randHash = password_hash(bin2hex(random_bytes(24)), PASSWORD_DEFAULT);
            $pdo->prepare('INSERT INTO users(role_id,full_name,email,password_hash,phone,status,email_verified_at,clerk_id)
                           VALUES(?,?,?,?,?,"active",NOW(),?)')
                ->execute([$roleId, $name, $email, $randHash, $phone !== '' ? $phone : null, $clerkId]);
            $newId = (int)$pdo->lastInsertId();
            $pdo->prepare('INSERT INTO messages(sender_id,receiver_id,message_type,subject,message) VALUES(NULL,?,"text",?,?)')
                ->execute([$newId, 'Welcome to SportSync', 'Your account is ready. Explore events, venues, communication, announcements, and community updates.']);
            $pdo->prepare('INSERT INTO activity_logs(user_id,action,details) VALUES(?,?,?)')
                ->execute([$newId, 'account_registered', 'Provisioned via Clerk sign-in']);
            $q3 = $pdo->prepare('SELECT u.*, r.name role_name FROM users u JOIN roles r ON r.id=u.role_id WHERE u.id=?');
            $q3->execute([$newId]);
            $row = $q3->fetch() ?: null;
        } else {
            // Keep the profile fresh from Clerk (name changes, new phone).
            $pdo->prepare('UPDATE users SET full_name=?, email_verified_at=COALESCE(email_verified_at,NOW()) WHERE id=? AND status="active"')
                ->execute([$name, $row['id']]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        sportsync_log($e);
        return null;
    }
    return is_array($row) ? $row : null;
}

/* ------------------------------------------------------------------ */
/* ClerkJS page helpers                                                */
/* ------------------------------------------------------------------ */
/**
 * Render the ClerkJS loader + component mount script.
 * $component: 'SignIn' | 'SignUp' | null (null = load Clerk only).
 */
function sportsync_clerk_js(string $component = null, array $opts = []): string {
    if (!sportsync_clerk_enabled()) return '';
    $pk = json_encode(CLERK_PUBLISHABLE_KEY);
    $mountId = e($opts['mount'] ?? 'clerk-auth');
    $comp = $component ? json_encode($component) : 'null';
    $afterUrl = trim((string)($opts['after'] ?? ''));
    // afterSignOutUrl is always present; the component-specific redirect URL is
    // appended only when the caller supplied one.
    $redirectOpt = $afterUrl !== ''
        ? ', ' . ($component === 'SignUp' ? 'afterSignUpUrl' : 'afterSignInUrl') . '=' . json_encode($afterUrl)
        : '';
    return <<<HTML
<script>window.ClerkDebug=false;</script>
<script src="https://cdn.jsdelivr.net/npm/@clerk/clerk-js@5/dist/clerk.browser.js" data-clerk-publishable-key="{$pk}" crossorigin="anonymous" async></script>
<script>
(function(){
  function start(){
    if(!window.Clerk){ setTimeout(start,60); return; }
    Clerk.load({ publishableKey: {$pk} }).then(function(){
      var el=document.getElementById('{$mountId}');
      if(el && {$comp}){ Clerk.mount{$component}(el,{ afterSignOutUrl:'landing.php'{$redirectOpt} }); }
      document.dispatchEvent(new CustomEvent('clerk-ready'));
    }).catch(function(err){ console.error('Clerk load failed',err);
      var fb=document.getElementById('clerk-fallback'); if(fb) fb.classList.remove('d-none');
      var sk=document.getElementById('clerk-skeleton'); if(sk) sk.classList.add('d-none');
    });
  }
  if(document.readyState==='loading'){ document.addEventListener('DOMContentLoaded',start); } else { start(); }
})();
</script>
HTML;
}

/**
 * Belt-and-suspenders redirect used after Clerk component flows: if the browser
 * did not send the HttpOnly __session cookie to the PHP side, re-dispatch the
 * sign-in sync with the token read from the ClerkJS session instead. Gives the
 * component's own redirect a head start, then polls briefly for a session.
 */
function sportsync_clerk_sync_script(): string {
    if (!sportsync_clerk_enabled()) return '';
    return <<<'HTML'
<script>
(function(){
  var tries=0;
  function go(){
    if(tries++>25) return; // ~12s of polling, then give up quietly
    if(!window.Clerk||!Clerk.sessionToken){ setTimeout(go,480); return; }
    Promise.resolve(Clerk.sessionToken).then(function(t){
      if(!t) return;
      var f=document.createElement('form');
      f.method='POST'; f.action='auth/clerk_sync.php';
      var i=document.createElement('input'); i.type='hidden'; i.name='clerk_token'; i.value=t;
      f.appendChild(i); document.body.appendChild(f); f.submit();
    }).catch(function(){});
  }
  function arm(){ setTimeout(go,2500); }
  if(document.readyState==='loading'){ document.addEventListener('DOMContentLoaded',arm); } else { arm(); }
})();
</script>
HTML;
}

/* ------------------------------------------------------------------ */
/* Svix webhook signature verification (pure PHP)                      */
/* ------------------------------------------------------------------ */
function sportsync_clerk_verify_webhook(string $payload, array $headers): bool {
    if (CLERK_WEBHOOK_SECRET === '') return false;
    $id    = $headers['svix-id'] ?? '';
    $ts    = $headers['svix-timestamp'] ?? '';
    $sigs  = $headers['svix-signature'] ?? '';
    if ($id === '' || $ts === '' || $sigs === '') return false;
    if (abs(time() - (int)$ts) > 300) return false; // replay window
    $secret = base64_decode(substr(CLERK_WEBHOOK_SECRET, strlen('whsec_')), true);
    if ($secret === false) return false;
    $expected = base64_encode(hash_hmac('sha256', $id . '.' . $ts . '.' . $payload, $secret, true));
    foreach (explode(' ', $sigs) as $pair) {
        if (str_starts_with($pair, 'v1,') && hash_equals($expected, substr($pair, 3))) return true;
    }
    return false;
}
