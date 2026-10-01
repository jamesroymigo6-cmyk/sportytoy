<?php
/**
 * GCash return page (PayMongo "auto" mode).
 *
 * PayMongo redirects the customer here after they finish (or abandon) the
 * GCash payment. We poll the checkout session server-side; if the payment
 * settled, the order is confirmed immediately, otherwise the customer gets a
 * clear status and a resume link. The shop page re-checks too, so this page
 * is a convenience, not the only confirmation path.
 */
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/gcash.php';
require_login();
$u = current_user();

$ref = trim((string)($_GET['session'] ?? ''));
if ($ref === '' || !preg_match('/^[A-Za-z0-9_\-]{6,80}$/', $ref)) {
    header('Location: index.php?page=shop');exit;
}
$ok = false;$resume = '';
try {
    [$paid, $resume] = sportsync_gcash_session_status($ref);
    if ($paid) {
        $pdo->beginTransaction();
        $q = $pdo->prepare('SELECT gp.id, gp.order_id FROM gcash_payouts gp JOIN orders o ON o.id=gp.order_id WHERE gp.checkout_session_id=? AND gp.status="verifying" AND o.user_id=? AND o.payment_method="GCash" FOR UPDATE');
        $q->execute([$ref, $u['id']]);
        $payout = $q->fetch();
        if ($payout) {
            $pdo->prepare('UPDATE gcash_payouts SET status="paid", verified_at=NOW() WHERE id=?')->execute([(int)$payout['id']]);
            $pdo->prepare('UPDATE orders SET payment_status="paid", payment_verified_at=NOW() WHERE id=?')->execute([(int)$payout['order_id']]);
            best_effort_notification($pdo, (int)$u['id'], 'GCash payment confirmed', 'Your GCash payment for order #' . (int)$payout['order_id'] . ' was received. The shop will prepare your items.');
            $pdo->commit();
            header('Location: index.php?page=shop&ok=' . urlencode('GCash payment received. Order #' . (int)$payout['order_id'] . ' is confirmed.'));exit;
        }
        $pdo->rollBack();
        $ok = true; // already settled by the shop page poller
    }
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    sportsync_log($e);
    $resume = '';
}
$q = $pdo->prepare('SELECT o.id FROM orders o JOIN gcash_payouts gp ON gp.order_id=o.id WHERE gp.checkout_session_id=? AND o.user_id=?');
$q->execute([$ref, $u['id']]);
$orderId = (int)$q->fetchColumn();
if ($orderId) {
    header('Location: index.php?page=shop');exit;
}
header('Location: index.php?page=shop&err=' . urlencode('We could not confirm your GCash payment yet. If you already paid, the shop will verify it shortly.'));exit;
