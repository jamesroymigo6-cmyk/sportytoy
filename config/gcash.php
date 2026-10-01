<?php
/**
 * Sporty Ni Migo GCash payment gateway (merchandise checkout).
 *
 * Two operating modes, selected with GCASH_MODE in .env:
 *
 *   manual (default) — no third-party account needed. The customer sends the
 *     exact amount to the shop's GCash number, enters the reference number
 *     from the receipt at checkout, and the order is stored as "verifying"
 *     until management confirms the money arrived (shop → order list →
 *     "Mark paid"). Requires GCASH_NUMBER.
 *
 *   auto — PayMongo (https://paymongo.com) hosted checkout restricted to
 *     GCash. The customer is redirected to a GCash payment page and this app
 *     confirms the order automatically when the gateway reports the payment
 *     as paid. Requires PAYMONGO_SECRET_KEY (sk_test_... or sk_live_...).
 *     PayMongo requires HTTPS redirect URLs in live mode.
 *
 * Payment states used on orders.payment_status:
 *   pending   — Cash on delivery/pickup (unchanged classic behavior)
 *   verifying — GCash: reference recorded / checkout started, awaiting confirmation
 *   paid      — money confirmed (automatically in auto mode, by management in manual mode)
 *   failed    — GCash payment rejected by management
 */

if (!defined('GCASH_MODE')) define('GCASH_MODE', strtolower(trim((string)sportsync_env('GCASH_MODE', 'manual'))));
if (!defined('GCASH_NUMBER')) define('GCASH_NUMBER', preg_replace('/[\s-]+/', '', (string)sportsync_env('GCASH_NUMBER', '')));
if (!defined('GCASH_ACCOUNT_NAME')) define('GCASH_ACCOUNT_NAME', trim((string)sportsync_env('GCASH_ACCOUNT_NAME', '')));
if (!defined('PAYMONGO_SECRET_KEY')) define('PAYMONGO_SECRET_KEY', trim((string)sportsync_env('PAYMONGO_SECRET_KEY', '')));
if (!defined('PAYMONGO_API_BASE')) define('PAYMONGO_API_BASE', 'https://api.paymongo.com/v1');

/** 'auto' when PayMongo is configured, 'manual' when only a GCash number is set, '' when GCash is off. */
function sportsync_gcash_mode(): string {
    if (GCASH_MODE === 'auto' && PAYMONGO_SECRET_KEY !== '') return 'auto';
    if (GCASH_NUMBER !== '') return 'manual';
    return '';
}

function sportsync_gcash_enabled(): bool { return sportsync_gcash_mode() !== ''; }

function sportsync_gcash_paymongo_enabled(): bool { return GCASH_MODE === 'auto' && PAYMONGO_SECRET_KEY !== ''; }

function sportsync_gcash_payline(): string {
    $line = 'GCash ' . GCASH_NUMBER;
    return GCASH_ACCOUNT_NAME !== '' ? $line . ' (' . GCASH_ACCOUNT_NAME . ')' : $line;
}

function sportsync_gcash_curl(string $method, string $url, array $headers = [], ?string $body = null, int $timeout = 15): array {
    $ch = curl_init($url);
    if (!$ch) throw new RuntimeException('Unable to open a connection to the GCash gateway.');
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_CONNECTTIMEOUT => 8,
    ]);
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    $response = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    if ($response === false) throw new RuntimeException('GCash gateway connection failed: ' . ($error !== '' ? $error : 'network error'));
    return [$status, (string)$response];
}

/**
 * Create a PayMongo Checkout Session limited to GCash.
 * $lineItems: [['name'=>..,'price'=>float,'qty'=>int],...] (the order snapshot).
 * Returns [sessionId, checkoutUrl].
 */
function sportsync_gcash_create_checkout(float $total, string $description, array $lineItems): array {
    if (!sportsync_gcash_paymongo_enabled()) throw new RuntimeException('GCash online payments are not configured (PAYMONGO_SECRET_KEY missing).');
    if ($total < 1) throw new RuntimeException('GCash online payment requires an order total of at least ₱1.00.');
    $items = [];
    foreach ($lineItems as $li) {
        $items[] = [
            'currency' => 'PHP',
            'amount' => (int)round(((float)($li['price'] ?? 0)) * 100),
            'name' => mb_substr((string)($li['name'] ?? 'Item'), 0, 120),
            'quantity' => max(1, (int)($li['qty'] ?? 1)),
        ];
    }
    $payload = json_encode(['data' => ['attributes' => [
        'line_items' => $items,
        'payment_method_types' => ['gcash'],
        'description' => mb_substr($description, 0, 200),
        'success_url' => sportsync_url('gcash_return.php'),
        'cancel_url' => sportsync_url('index.php?page=shop'),
        'reference_number' => 'SportyNiMigo-' . strtoupper(bin2hex(random_bytes(4))),
        'send_email_receipt' => false,
        'show_line_items' => true,
        'show_description' => true,
    ]]], JSON_UNESCAPED_UNICODE);
    [$status, $body] = sportsync_gcash_curl('POST', PAYMONGO_API_BASE . '/checkout_sessions', [
        'Content-Type: application/json',
        'Authorization: Basic ' . base64_encode(PAYMONGO_SECRET_KEY . ':'),
    ], $payload);
    $data = json_decode($body, true);
    if ($status >= 400 || !is_array($data)) {
        $detail = is_array($data) && isset($data['errors'][0]['detail']) ? (string)$data['errors'][0]['detail'] : ('HTTP ' . $status);
        throw new RuntimeException('GCash checkout could not be created: ' . $detail);
    }
    $id = (string)($data['data']['id'] ?? '');
    $url = (string)($data['data']['attributes']['checkout_url'] ?? '');
    if ($id === '' || $url === '') throw new RuntimeException('GCash checkout returned an unexpected response.');
    return [$id, $url];
}

/**
 * Poll one PayMongo checkout session.
 * Returns [paid(bool), checkoutUrl(string)] — the URL lets the customer resume
 * an unfinished payment from the shop page.
 */
function sportsync_gcash_session_status(string $sessionId): array {
    [$status, $body] = sportsync_gcash_curl('GET', PAYMONGO_API_BASE . '/checkout_sessions/' . rawurlencode($sessionId), [
        'Authorization: Basic ' . base64_encode(PAYMONGO_SECRET_KEY . ':'),
    ]);
    if ($status >= 400) {
        $data = json_decode($body, true);
        $detail = is_array($data) && isset($data['errors'][0]['detail']) ? (string)$data['errors'][0]['detail'] : ('HTTP ' . $status);
        throw new RuntimeException($detail);
    }
    $data = json_decode($body, true);
    if (!is_array($data)) return [false, ''];
    $attr = $data['data']['attributes'] ?? [];
    if (($attr['status'] ?? '') === 'paid') return [true, ''];
    foreach (($attr['payments'] ?? []) as $p) {
        if (($p['attributes']['status'] ?? '') === 'paid') return [true, ''];
    }
    return [false, (string)($attr['checkout_url'] ?? '')];
}
