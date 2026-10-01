<?php
require 'config/auth.php';

if (!current_user()) {
    header('Location: landing.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
    header('Location: login.php?notice='.urlencode('You have been signed out securely.'));
    exit;
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Sign out · SportSync</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" rel="stylesheet">
<link rel="stylesheet" href="assets/css/app.css">
</head>
<body class="auth-body auth-sports">
<main class="logout-page-shell">
  <section class="logout-page-card">
    <div class="logout-modal-icon"><i class="fa-solid fa-right-from-bracket"></i></div>
    <span class="eyebrow d-block mt-3">ACCOUNT SESSION</span>
    <h1>Sign out of SportSync?</h1>
    <p>Confirm that you want to end this session. Your account data and saved activity will remain available the next time you sign in.</p>
    <form method="post" class="d-grid gap-2 mt-4">
      <input type="hidden" name="csrf" value="<?=csrf_token()?>">
      <button class="btn btn-danger btn-lg" type="submit" data-clerk-sign-out><i class="fa-solid fa-right-from-bracket me-2"></i>Yes, sign out</button>
      <a class="btn btn-light btn-lg" href="landing.php"><i class="fa-solid fa-arrow-left me-2"></i>Back to home</a>
    </form>
  </section>
</main>
<?php if (sportsync_clerk_enabled()): ?>
    <?= sportsync_clerk_js() ?>
    <script>
    (function () {
        document.addEventListener('clerk-ready', function () {
            if (!window.Clerk || !Clerk.user || !Clerk.user()) return;
            var btn = document.querySelector('[data-clerk-sign-out]');
            if (!btn) return;
            btn.addEventListener('click', function (ev) {
                // The PHP session is already destroyed by the form POST above;
                // also end the Clerk session so the browser forgets the identity.
                Clerk.signOut().catch(function () {});
            }, { once: true });
        });
    })();
    </script>
<?php endif; ?>
</body>
</html>
