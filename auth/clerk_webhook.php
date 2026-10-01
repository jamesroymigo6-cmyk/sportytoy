<?php
/**
 * Clerk webhook receiver (Svix-signed).
 *
 * Point a Clerk webhook at https://YOUR-HOST/auth/clerk_webhook.php and set
 * CLERK_WEBHOOK_SECRET in .env. Handles user.created / user.updated /
 * user.deleted so local accounts stay in sync even when someone signs up
 * through a flow that never touches auth/clerk_sync.php.
 */

require_once dirname(__DIR__) . '/config/db.php';
require_once dirname(__DIR__) . '/config/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method not allowed.');
}

$payload = (string)file_get_contents('php://input');
$headers = [];
foreach ($_SERVER as $key => $value) {
    if (str_starts_with($key, 'HTTP_SVIX_')) {
        $name = strtolower(str_replace('_', '-', substr($key, 5)));
        $headers[$name] = (string)$value;
    }
}

if (!sportsync_clerk_verify_webhook($payload, $headers)) {
    http_response_code(401);
    exit('Invalid webhook signature.');
}

$event = json_decode($payload, true);
if (!is_array($event)) {
    http_response_code(400);
    exit('Invalid payload.');
}

$type = (string)($event['type'] ?? '');
$data = $event['data'] ?? [];
$clerkId = (string)($data['id'] ?? '');

if ($clerkId === '' || !str_starts_with($clerkId, 'user_')) {
    http_response_code(204);
    exit;
}

if ($type === 'user.deleted') {
    // Keep local history coherent: release the Clerk link instead of deleting
    // the row (events, orders and messages reference users.id).
    $pdo->prepare('UPDATE users SET clerk_id=NULL, status="inactive" WHERE clerk_id=?')->execute([$clerkId]);
    http_response_code(204);
    exit;
}

if (in_array($type, ['user.created', 'user.updated'], true)) {
    // Preferred role: honour metadata when present, otherwise the default.
    $preferredRole = (string)(($data['public_metadata']['sport-sync-role'] ?? ($data['unsafe_metadata']['sport-sync-role'] ?? '')));
    $row = sportsync_clerk_provision($pdo, $data, $preferredRole);
    if ($row && $type === 'user.created') {
        $pdo->prepare('INSERT INTO activity_logs(user_id,action,details) VALUES(?,?,?)')
            ->execute([(int)$row['id'], 'account_registered', 'Provisioned via Clerk webhook']);
    }
    http_response_code(204);
    exit;
}

http_response_code(204);
