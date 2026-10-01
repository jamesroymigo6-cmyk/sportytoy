<?php
require 'config/db.php';
require 'config/auth.php';
require 'config/sms.php';

$clerkOn = sportsync_clerk_enabled();

if (current_user()) {
    header('Location: index.php');
    exit;
}

// Brute-force protection. Keep the numbers in one place so the message below can
// never advertise a different window than the code actually applies.
const LOGIN_LOCKOUT_ATTEMPTS = 5;
const LOGIN_LOCKOUT_SECONDS  = 900;

$error = '';
$notice = trim($_GET['notice'] ?? '');
$email = strtolower(trim($_POST['email'] ?? ($_GET['email'] ?? ($_COOKIE['sportsync_email'] ?? ''))));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $password = $_POST['password'] ?? '';

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Enter a valid email address.';
    } elseif ($password === '') {
        $error = 'Enter your password.';
    } else {
        $stmt = $pdo->prepare('SELECT u.*, r.name role_name FROM users u JOIN roles r ON r.id=u.role_id WHERE u.email=? LIMIT 1');
        $stmt->execute([$email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        // A lockout that has already expired must also reset the counter.
        // Otherwise failed_login_attempts stays at the lock threshold and a
        // single later typo re-locks the account for another 15 minutes.
        if ($user && !empty($user['locked_until']) && strtotime($user['locked_until']) <= time()) {
            $pdo->prepare('UPDATE users SET failed_login_attempts=0, locked_until=NULL WHERE id=?')->execute([$user['id']]);
            $user['failed_login_attempts'] = 0;
            $user['locked_until'] = null;
        }

        if ($user && $user['status'] !== 'active') {
            $error = 'This account is currently unavailable. Contact an administrator for assistance.';
        } elseif ($user && !empty($user['locked_until']) && strtotime($user['locked_until']) > time()) {
            $mins = max(1, (int)ceil((strtotime($user['locked_until']) - time()) / 60));
            $error = 'Too many failed sign-in attempts. Try again in '.$mins.' minute'.($mins === 1 ? '' : 's').'.';
        } elseif ($user && password_verify($password, $user['password_hash'])) {
            $pdo->prepare('UPDATE users SET failed_login_attempts=0, locked_until=NULL, last_login_at=NOW() WHERE id=?')->execute([$user['id']]);
            login_user($user);

            $cookieOptions = [
                'expires' => isset($_POST['remember_email']) ? time() + 60 * 60 * 24 * 30 : time() - 3600,
                'path' => '/',
                'secure' => sportsync_is_https(),
                'httponly' => true,
                'samesite' => 'Lax',
            ];
            setcookie('sportsync_email', isset($_POST['remember_email']) ? $email : '', $cookieOptions);

            $pdo->prepare('INSERT INTO activity_logs(user_id,action,details) VALUES(?,?,?)')
                ->execute([$user['id'], 'login', 'Successful sign in']);

            $target = safe_local_redirect($_SESSION['after_login'] ?? 'index.php');
            unset($_SESSION['after_login']);
            header('Location: '.$target);
            exit;
        } else {
            if ($user) {
                $attempts = (int)$user['failed_login_attempts'] + 1;
                $locked = $attempts >= LOGIN_LOCKOUT_ATTEMPTS ? date('Y-m-d H:i:s', time() + LOGIN_LOCKOUT_SECONDS) : null;
                $pdo->prepare('UPDATE users SET failed_login_attempts=?, locked_until=? WHERE id=?')
                    ->execute([$attempts, $locked, $user['id']]);
                // The attempt that trips the lock tells the user immediately
                // instead of letting the next attempt be the first to mention it.
                if ($locked !== null) {
                    $error = 'Too many failed sign-in attempts. Try again in ' . (int)(LOGIN_LOCKOUT_SECONDS / 60) . ' minutes.';
                }
            }
            if ($error === '') {
                $error = 'Invalid email or password.';
            }
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="description" content="Sign in to SportSync sports event operations platform.">
<title>Sign in · SportSync</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Poppins:wght@500;600;700;800&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" rel="stylesheet">
<link rel="stylesheet" href="assets/css/app.css?v=20260929i">
<style>
.clerk-mount{min-height:420px;display:flex;align-items:flex-start;justify-content:center}
.clerk-mount .cl-root-box,.clerk-mount>div{width:100%}
.clerk-skeleton{display:flex;flex-direction:column;gap:14px;width:100%;max-width:400px;margin:8px auto 0}
.clerk-skeleton span{display:block;height:46px;border-radius:12px;background:linear-gradient(100deg,rgba(148,163,184,.18) 30%,rgba(148,163,184,.34) 50%,rgba(148,163,184,.18) 70%);background-size:200% 100%;animation:clerkShimmer 1.3s infinite}
.clerk-skeleton span:nth-child(3){height:18px;margin-top:6px}
@keyframes clerkShimmer{to{background-position:-200% 0}}
.clerk-divider{display:flex;align-items:center;gap:12px;color:var(--muted,#94a3b8);font-size:.8rem;margin:18px 0 4px}
.clerk-divider::before,.clerk-divider::after{content:"";flex:1;height:1px;background:rgba(148,163,184,.35)}
</style>
</head>
<body class="auth-body auth-sports">
<main class="auth-shell auth-shell-pro">
    <section class="auth-hero auth-hero-pro d-none d-lg-flex">
        <div class="auth-hero-pro-inner">
            <div class="auth-brand-pro">
                <span class="auth-brand-mark"><i class="fa-solid fa-trophy"></i></span>
                <div><strong>SportSync</strong><small>Sports Event Operations</small></div>
            </div>

            <div class="auth-hero-copy-pro">
                <span class="auth-kicker"><i class="fa-solid fa-bolt me-2"></i>ONE CONNECTED SPORTS WORKSPACE</span>
                <h1>Plan smarter.<br>Play better.</h1>
                <p>Coordinate events, venues, equipment, communication, weather awareness, community updates, and operations from one secure online platform.</p>

                <div class="auth-feature-grid">
                    <div class="auth-feature"><i class="fa-solid fa-calendar-check"></i><div><strong>Smart scheduling</strong><span>Conflict-aware events and venues</span></div></div>
                    <div class="auth-feature"><i class="fa-solid fa-location-dot"></i><div><strong>Venue mapping</strong><span>Tupi, South Cotabato discovery</span></div></div>
                    <div class="auth-feature"><i class="fa-solid fa-box-open"></i><div><strong>Equipment access</strong><span>Reserve or purchase what you need</span></div></div>
                    <div class="auth-feature"><i class="fa-solid fa-cloud-sun-rain"></i><div><strong>Live awareness</strong><span>Weather and urgent notifications</span></div></div>
                </div>
            </div>

            <div class="auth-hero-footer-pro">
                <span><i class="fa-solid fa-shield-halved"></i> Role-secured access</span>
                <span><i class="fa-solid fa-envelope-circle-check"></i> Verified email sign-in</span>
                <span><i class="fa-solid fa-mobile-screen-button"></i> Mobile ready</span>
            </div>
        </div>
    </section>

    <section class="auth-panel-pro">
        <div class="auth-card auth-card-pro">
            <div class="auth-card-inner auth-card-inner-pro">
                <div class="auth-mobile-brand d-lg-none">
                    <span class="auth-brand-mark"><i class="fa-solid fa-trophy"></i></span>
                    <div><strong>SportSync</strong><small>Sports Event Operations</small></div>
                </div>

                <div class="auth-heading-pro">
                    <span class="eyebrow">WELCOME BACK</span>
                    <h2>Sign in to your workspace</h2>
                    <p>Continue managing your events, reservations, messages, and community activity.</p>
                </div>

                <?php if ($notice): ?>
                <div class="auth-alert auth-alert-success" role="status"><i class="fa-solid fa-circle-check"></i><span><?= e($notice) ?></span></div>
                <?php endif; ?>
                <?php if ($error): ?>
                <div class="auth-alert auth-alert-danger" role="alert"><i class="fa-solid fa-circle-exclamation"></i><span><?= e($error) ?></span></div>
                <?php endif; ?>

                <?php if ($clerkOn): ?>
                    <div class="clerk-divider">or continue with email &amp; password</div>
                    <div class="clerk-mount" id="clerk-auth"></div>
                    <div id="clerk-fallback" class="d-none">
                        <form method="post" id="loginForm" novalidate>
                            <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                            <div class="auth-field">
                                <label for="email">Email address</label>
                                <div class="auth-input-shell"><i class="fa-regular fa-envelope"></i>
                                    <input class="form-control" id="email" name="email" type="email" required autocomplete="email" inputmode="email" placeholder="name@example.com" value="<?= e($email) ?>">
                                </div>
                                <div class="invalid-feedback">Enter a valid email address.</div>
                            </div>
                            <div class="auth-field">
                                <label for="password">Password</label>
                                <div class="auth-input-shell"><i class="fa-solid fa-lock"></i>
                                    <input class="form-control" id="password" name="password" type="password" required autocomplete="current-password" placeholder="Your password">
                                </div>
                                <div class="invalid-feedback">Enter your password.</div>
                            </div>
                            <button class="btn auth-primary-btn mt-2" type="submit"><span>Sign in</span><i class="fa-solid fa-arrow-right ms-2"></i></button>
                        </form>
                    </div>
                <?php else: ?>
                    <form method="post" id="loginForm" novalidate>
                        <input type="hidden" name="csrf" value="<?= csrf_token() ?>">

                        <div class="auth-field">
                            <label for="email">Email address</label>
                            <div class="auth-input-shell">
                                <i class="fa-regular fa-envelope"></i>
                                <input class="form-control" id="email" name="email" type="email" required autocomplete="email" inputmode="email" placeholder="name@example.com" value="<?= e($email) ?>">
                            </div>
                            <div class="invalid-feedback">Enter a valid email address.</div>
                        </div>

                        <div class="auth-field">
                            <div class="d-flex justify-content-between align-items-center gap-3">
                                <label for="password">Password</label>
                                <a class="auth-inline-link" href="forgot_password.php">Forgot password?</a>
                            </div>
                            <div class="auth-input-shell">
                                <i class="fa-solid fa-lock"></i>
                                <input class="form-control" id="password" name="password" type="password" required autocomplete="current-password" placeholder="Enter your password">
                                <span id="capsLockNotice" class="d-none text-warning small ms-2"><i class="fa-solid fa-triangle-exclamation"></i> Caps Lock is on</span>
                            </div>
                            <div class="invalid-feedback">Enter your password.</div>
                        </div>

                        <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
                            <label class="auth-check"><input type="checkbox" name="remember_email" <?= isset($_COOKIE['sportsync_email']) ? 'checked' : '' ?>><span>Remember my email</span></label>
                            <span class="auth-security-inline"><i class="fa-solid fa-shield-halved"></i> Secure login</span>
                        </div>

                        <button class="btn auth-primary-btn" id="loginSubmit" type="submit">
                            <span class="submit-label">Sign in</span>
                            <span class="submit-loading d-none"><span class="spinner-border spinner-border-sm" aria-hidden="true"></span> Signing in...</span>
                            <i class="fa-solid fa-arrow-right submit-icon"></i>
                        </button>
                    </form>
                <?php endif; ?>

                <div class="auth-divider"><span>New to SportSync?</span></div>
                <a class="btn auth-secondary-btn" href="register.php"><i class="fa-solid fa-user-plus"></i> Create an account</a>

                <div class="auth-trust-row">
                    <span><i class="fa-solid fa-check"></i> Responsive</span>
                    <span><i class="fa-solid fa-check"></i> Online</span>
                    <span><i class="fa-solid fa-check"></i> Role-based</span>
                </div>

                <?php if (APP_DEBUG): ?>
                <div class="demo-box mt-4"><div class="small fw-semibold mb-1"><i class="fa-solid fa-flask me-2"></i>Development demo administrator</div><div class="small text-muted">admin@sports.local · Admin@123</div></div>
                <?php endif; ?>
            </div>
        </div>
    </section>
</main>

<?php if ($clerkOn): ?>
    <?= sportsync_clerk_js('SignIn', ['after' => 'auth/clerk_sync.php']) ?>
    <?= sportsync_clerk_sync_script() ?>
    <script>
    (function () {
        var done = false;
        document.addEventListener('clerk-ready', function () {
            done = true;
            var sk = document.getElementById('clerk-skeleton'); if (sk) sk.classList.add('d-none');
            var mount = document.getElementById('clerk-auth');
            // If the component produced no UI (e.g. an unsupported browser),
            // reveal the classic email form as a safety net.
            if (mount && !mount.childElementCount) document.getElementById('clerk-fallback')?.classList.remove('d-none');
        });
        setTimeout(function () {
            if (done) return;
            var fb = document.getElementById('clerk-fallback');
            if (fb) fb.classList.remove('d-none');
            var sk = document.getElementById('clerk-skeleton'); if (sk) sk.classList.add('d-none');
        }, 8000);
        // Show the form immediately if the CDN is unreachable.
        window.addEventListener('error', function (ev) {
            if (done || !(ev.target && ev.target.src && ev.target.src.indexOf('clerk') !== -1)) return;
            var fb = document.getElementById('clerk-fallback'); if (fb) fb.classList.remove('d-none');
            var sk = document.getElementById('clerk-skeleton'); if (sk) sk.classList.add('d-none');
        }, true);
    })();
    </script>
<?php else: ?>
    <script src="assets/js/app.js"></script>
    <script>
    (() => {
        const form = document.getElementById('loginForm');
        const password = document.getElementById('password');
        const caps = document.getElementById('capsLockNotice');
        const submit = document.getElementById('loginSubmit');

        password?.addEventListener('keyup', (e) => caps?.classList.toggle('d-none', !e.getModifierState?.('CapsLock')));
        password?.addEventListener('blur', () => caps?.classList.add('d-none'));

        form?.addEventListener('submit', (e) => {
            if (!form.checkValidity()) {
                e.preventDefault();
                e.stopPropagation();
                form.classList.add('was-validated');
                form.querySelector(':invalid')?.focus();
                return;
            }
            submit.disabled = true;
            submit.querySelector('.submit-label')?.classList.add('d-none');
            submit.querySelector('.submit-icon')?.classList.add('d-none');
            submit.querySelector('.submit-loading')?.classList.remove('d-none');
        });
    })();
    </script>
<?php endif; ?>
</body>
</html>
