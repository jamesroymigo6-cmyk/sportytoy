<?php
require_once __DIR__ . '/app.php';

// ---------------------------------------------------------------------------
// SMSGate transport (SMS Gateway for Android, https://sms-gate.app)
// Sends urgent SMS through the SMSGate cloud API. The messages themselves are
// dispatched by the user's own registered Android device, so there is no
// per-message carrier fee — only the gateway account is required.
// Configure in .env:
//   SMS_PROVIDER=smsgate
//   SMSGATE_API_URL=https://api.sms-gate.app
//   SMSGATE_USERNAME=...   (SMSGate Web Dashboard account username)
//   SMSGATE_PASSWORD=...   (SMSGate Web Dashboard account password)
//   SMS_SENDER=SportSync   (labelled in the admin UI; the device SIM's sender
//                           identity is what recipients actually see)
// ---------------------------------------------------------------------------

function sportsync_sms_enabled(): bool {
    return strtolower((string)sportsync_env('SMS_PROVIDER', 'disabled')) === 'smsgate'
        && trim((string)sportsync_env('SMSGATE_API_URL', '')) !== ''
        && trim((string)sportsync_env('SMSGATE_USERNAME', '')) !== ''
        && trim((string)sportsync_env('SMSGATE_PASSWORD', '')) !== ''
        && trim((string)sportsync_env('SMS_SENDER', '')) !== '';
}

function sportsync_normalize_phone(string $phone): ?string {
    $phone = preg_replace('/[^0-9+]/', '', trim($phone));
    if ($phone === '') return null;
    if (str_starts_with($phone, '+')) $phone = substr($phone, 1);
    if (preg_match('/^09\d{9}$/', $phone)) return '63' . substr($phone, 1);
    if (preg_match('/^639\d{9}$/', $phone)) return $phone;
    if (preg_match('/^[1-9]\d{7,14}$/', $phone)) return $phone;
    return null;
}

function sportsync_send_sms(string $phone, string $message): array {
    $to = sportsync_normalize_phone($phone);
    if (!$to) return ['ok'=>false,'status'=>'invalid_phone','message_id'=>null,'error'=>'Invalid phone number'];
    if (!sportsync_sms_enabled()) return ['ok'=>false,'status'=>'not_configured','message_id'=>null,'error'=>'SMS provider is not configured'];
    if (!function_exists('curl_init')) return ['ok'=>false,'status'=>'curl_missing','message_id'=>null,'error'=>'PHP cURL extension is unavailable'];

    $base = rtrim((string)sportsync_env('SMSGATE_API_URL', ''), '/');
    $username = (string)sportsync_env('SMSGATE_USERNAME', '');
    $password = (string)sportsync_env('SMSGATE_PASSWORD', '');
    $message = trim($message);
    $len = function_exists('mb_strlen') ? mb_strlen($message) : strlen($message);
    if ($len > 640) $message = (function_exists('mb_substr') ? mb_substr($message, 0, 637) : substr($message, 0, 637)) . '...';

    // SMSGate 3rd-party API v1 (POST {base}/3rdparty/v1/messages).
    // Basic auth with the Web Dashboard account credentials. The cloud API
    // relays the message to the registered Android device for dispatch.
    // Payload: {"phoneNumbers":["63..."],"textMessage":{"text":"..."}}
    // Success: HTTP 202 Accepted with {"id":"...","state":"pending",...}
    $payload = [
        'phoneNumbers' => ['+' . $to],
        'textMessage'  => ['text' => $message],
    ];

    $ch = curl_init($base . '/3rdparty/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_HTTPHEADER => [
            'Authorization: Basic ' . base64_encode($username . ':' . $password),
            'Content-Type: application/json',
            'Accept: application/json',
        ],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ]);
    $raw = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($raw === false || $curlError !== '') {
        sportsync_log('SMSGate cURL error: ' . $curlError);
        return ['ok'=>false,'status'=>'transport_error','message_id'=>null,'error'=>'SMS transport failed'];
    }
    $data = json_decode((string)$raw, true);
    $messageId = $data['id'] ?? ($data['messageId'] ?? null);
    // 202 Accepted = queued for dispatch; 200/201 also treated as success.
    $ok = $httpCode >= 200 && $httpCode < 300 && (bool)$messageId;
    if (!$ok) sportsync_log('SMSGate response HTTP ' . $httpCode . ': ' . substr((string)$raw, 0, 1000));
    return ['ok'=>$ok,'status'=>$ok?'ACCEPTED':'FAILED','message_id'=>$messageId,'error'=>$ok?null:'SMSGate rejected the message (' . $httpCode . ')'];
}

/**
 * Read-only SMSGate connection check: lists registered Android devices.
 * Returns ['ok'=>bool,'http'=>int,'devices'=>[[name,online,last_seen]],'error'=>?string].
 * Requires no scopes beyond the account itself (Basic auth on /devices).
 */
function sportsync_smsgate_devices(): array {
    if (!function_exists('curl_init')) return ['ok'=>false,'http'=>0,'devices'=>[],'error'=>'PHP cURL extension is unavailable'];
    $base = rtrim((string)sportsync_env('SMSGATE_API_URL', ''), '/');
    $username = (string)sportsync_env('SMSGATE_USERNAME', '');
    $password = (string)sportsync_env('SMSGATE_PASSWORD', '');
    if ($base === '' || $username === '' || $password === '') {
        return ['ok'=>false,'http'=>0,'devices'=>[],'error'=>'SMSGate is not fully configured (need API URL, username, and password)'];
    }
    $ch = curl_init($base . '/3rdparty/v1/devices');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_HTTPHEADER => [
            'Authorization: Basic ' . base64_encode($username . ':' . $password),
            'Accept: application/json',
        ],
    ]);
    $raw = curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($raw === false || $err !== '') {
        return ['ok'=>false,'http'=>0,'devices'=>[],'error'=>'Could not reach the SMSGate API: ' . $err];
    }
    if ($http === 401) {
        return ['ok'=>false,'http'=>$http,'devices'=>[],'error'=>'SMSGate rejected the credentials (401). Check the username and password.'];
    }
    if ($http < 200 || $http >= 300) {
        return ['ok'=>false,'http'=>$http,'devices'=>[],'error'=>'SMSGate returned HTTP ' . $http];
    }
    $data = json_decode((string)$raw, true);
    $items = is_array($data) ? ($data['items'] ?? $data) : [];
    $devices = [];
    foreach ((array)$items as $d) {
        if (!is_array($d)) continue;
        $devices[] = [
            'name'      => (string)($d['device_name'] ?? ($d['id'] ?? 'Unknown device')),
            'online'    => (bool)($d['is_online'] ?? false),
            'last_seen' => isset($d['last_seen']) ? (string)$d['last_seen'] : null,
        ];
    }
    return ['ok'=>true,'http'=>$http,'devices'=>$devices,'error'=>null];
}

/**
 * Resolve the recipient list for a custom bulk SMS from admin filters.
 * $f keys: audience, event_id, registration_status, custom_only, search.
 * Returns rows: user_id, full_name, phone, role_name, event_title, reg_status.
 */
function sportsync_sms_bulk_recipients(PDO $pdo, array $f): array {
    $sql = 'SELECT u.id user_id,u.full_name,u.phone,r.name role_name FROM users u JOIN roles r ON r.id=u.role_id WHERE u.status="active" AND u.phone IS NOT NULL AND TRIM(u.phone)<>""';
    $args = [];
    $audience = (string)($f['audience'] ?? 'all');
    $roles = [];
    if ($audience === 'participants') $roles = ['Participant/Athlete','Spectator/Community Member'];
    elseif ($audience === 'organizers') $roles = ['Event Organizer'];
    elseif ($audience === 'staff') $roles = ['Staff/Coordinator'];
    elseif ($audience === 'management') $roles = ['Administrator','Staff/Coordinator'];
    if ($roles) {
        $sql .= ' AND r.name IN (' . implode(',', array_fill(0, count($roles), '?')) . ')';
        array_push($args, ...$roles);
    }
    if (!empty($f['custom_only'])) $sql .= ' AND u.clerk_id IS NULL';
    $eventId = (int)($f['event_id'] ?? 0);
    if ($eventId > 0) {
        $sql .= ' AND u.id IN (SELECT user_id FROM event_registrations WHERE event_id=?';
        $args[] = $eventId;
        $regStatus = (string)($f['registration_status'] ?? '');
        if ($regStatus === 'approved' || $regStatus === 'pending' || $regStatus === 'rejected') {
            $sql .= ' AND status=?';
            $args[] = $regStatus;
        }
        $sql .= ')';
    }
    $search = trim((string)($f['search'] ?? ''));
    if ($search !== '') {
        $sql .= ' AND (u.full_name LIKE ? OR u.phone LIKE ?)';
        $like = '%' . $search . '%';
        array_push($args, $like, $like);
    }
    $sql .= ' ORDER BY u.full_name';
    $q = $pdo->prepare($sql);
    $q->execute($args);
    return $q->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Send a custom bulk SMS to an explicit recipient list (admin composer).
 * Logs one activity_logs row per recipient under action sms_custom.
 */
function sportsync_send_bulk_sms(PDO $pdo, array $recipients, string $message, string $context = 'custom broadcast'): array {
    $text = trim((string)sportsync_env('SMS_SENDER', 'SportSync')) . ': ' . trim($message);
    $sent = 0; $failed = 0; $skipped = 0;
    $log = $pdo->prepare('INSERT INTO activity_logs(user_id,action,details) VALUES(?,?,?)');
    foreach ($recipients as $user) {
        $normalized = sportsync_normalize_phone((string)$user['phone']);
        if (!$normalized) { $skipped++; continue; }
        $result = sportsync_send_sms($normalized, $text);
        if ($result['ok']) $sent++; else $failed++;
        $log->execute([
            $user['user_id'] ?? null,
            'sms_custom',
            $context . ' | to ' . $normalized . ' | status=' . $result['status'] . ' | "' . mb_substr($message, 0, 80) . '"',
        ]);
    }
    return ['total'=>count($recipients),'sent'=>$sent,'failed'=>$failed,'skipped'=>$skipped];
}

function sportsync_sms_audience_users(PDO $pdo, string $audience): array {
    $roles = [];
    switch ($audience) {
        case 'participants': $roles = ['Participant/Athlete','Spectator/Community Member']; break;
        case 'staff': $roles = ['Staff/Coordinator']; break;
        case 'organizers': $roles = ['Event Organizer']; break;
        case 'management': $roles = ['Administrator','Staff/Coordinator']; break;
        default: $roles = [];
    }
    if (!$roles) {
        return $pdo->query('SELECT u.id,u.full_name,u.phone FROM users u WHERE u.status="active" AND u.phone IS NOT NULL AND TRIM(u.phone)<>""')->fetchAll(PDO::FETCH_ASSOC);
    }
    $ph = implode(',', array_fill(0, count($roles), '?'));
    $q = $pdo->prepare('SELECT u.id,u.full_name,u.phone FROM users u JOIN roles r ON r.id=u.role_id WHERE u.status="active" AND u.phone IS NOT NULL AND TRIM(u.phone)<>"" AND r.name IN (' . $ph . ')');
    $q->execute($roles);
    return $q->fetchAll(PDO::FETCH_ASSOC);
}

function sportsync_send_announcement_sms(PDO $pdo, int $announcementId, string $audience, string $title, string $body): array {
    $users = sportsync_sms_audience_users($pdo, $audience);
    $sent = 0; $failed = 0; $skipped = 0;
    $log = $pdo->prepare('INSERT INTO activity_logs(user_id,action,details) VALUES(?,?,?)');
    $text = 'SportSync URGENT: ' . trim($title) . ' - ' . trim(preg_replace('/\s+/', ' ', $body));
    foreach ($users as $user) {
        $normalized = sportsync_normalize_phone((string)$user['phone']);
        if (!$normalized) {
            $skipped++;
            $log->execute([$user['id'] ?? null,'sms_invalid_phone','announcement #'.$announcementId.' phone='.(string)$user['phone']]);
            continue;
        }
        $result = sportsync_send_sms($normalized, $text);
        if ($result['ok']) $sent++; else $failed++;
        $log->execute([$user['id'] ?? null,'sms_announcement','announcement #'.$announcementId.' status='.$result['status'].' to '.$normalized]);
    }
    return ['total'=>count($users),'sent'=>$sent,'failed'=>$failed,'skipped'=>$skipped];
}
