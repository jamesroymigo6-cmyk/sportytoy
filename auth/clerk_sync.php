<?php
/**
 * Clerk sign-in landing / token exchange.
 *
 * ClerkJS completes the authentication flow on the auth pages and then
 * redirects here with the session token (POST when possible, GET otherwise).
 * This endpoint verifies the JWT against Clerk's JWKS, provisions or links the
 * matching local SportSync account, and opens the PHP session the app uses.
 */

require_once dirname(__DIR__) . '/config/db.php';
require_once dirname(__DIR__) . '/config/auth.php';
require_once dirname(__DIR__) . '/config/sms.php';

if (current_user()) {
    header('Location: index.php');
    exit;
}

if (!sportsync_clerk_enabled()) {
    header('Location: login.php?notice=' . urlencode('Clerk authentication is not configured on this server.'));
    exit;
}

$token = sportsync_clerk_request_token();
if ($token === '') {
    header('Location: login.php?notice=' . urlencode('Sign-in could not be completed. Please try again.'));
    exit;
}

$clerkUser = sportsync_clerk_authenticate($token);
if (!$clerkUser) {
    sportsync_log('Clerk token verification failed during sign-in sync.');
    header('Location: login.php?notice=' . urlencode('We could not verify your sign-in. Please try again.'));
    exit;
}

$row = sportsync_clerk_provision($pdo, $clerkUser);
if (!$row || ($row['status'] ?? '') !== 'active') {
    header('Location: login.php?notice=' . urlencode('This account is not active. Contact an administrator for assistance.'));
    exit;
}

login_user($row);
$pdo->prepare('UPDATE users SET last_login_at=NOW(), failed_login_attempts=0, locked_until=NULL WHERE id=?')
    ->execute([(int)$row['id']]);
$pdo->prepare('INSERT INTO activity_logs(user_id,action,details) VALUES(?,?,?)')
    ->execute([(int)$row['id'], 'login', 'Signed in with Clerk (email verification)']);

// Fetch the requested page (or its redirect key) before the session write so
// the header includes the current CSRF token.
$target = safe_local_redirect($_SESSION['after_login'] ?? 'index.php');
unset($_SESSION['after_login']);
header('Location: ' . $target);
exit;
