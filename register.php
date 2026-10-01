<?php
require 'config/db.php';
require 'config/auth.php';
require 'config/sms.php';

$clerkOn = sportsync_clerk_enabled();

if (current_user()) {
    header('Location: index.php');
    exit;
}

/**
 * The classic registration form, shared by the standalone page and the
 * Clerk-fallback branch further down.
 */
function sportysync_register_form(): string {
    global $values;
    ob_start();
    ?>
    <form method="post" id="registerForm" novalidate>
        <input type="hidden" name="csrf" value="<?= csrf_token() ?>">

        <div class="row g-3">
            <div class="col-12 auth-field">
                <label for="full_name">Full name</label>
                <div class="auth-input-shell"><i class="fa-regular fa-user"></i><input class="form-control" name="full_name" id="full_name" required minlength="3" autocomplete="name" placeholder="Enter your complete name" value="<?= e($values['full_name']) ?>"></div>
                <div class="invalid-feedback">Enter your complete name.</div>
            </div>

            <div class="col-md-6 auth-field">
                <label for="reg_email">Email address</label>
                <div class="auth-input-shell"><i class="fa-regular fa-envelope"></i><input class="form-control" type="email" name="email" id="reg_email" required autocomplete="email" inputmode="email" placeholder="name@example.com" value="<?= e($values['email']) ?>"></div>
                <div class="invalid-feedback">Enter a valid email address.</div>
            </div>

            <div class="col-md-6 auth-field">
                <label for="phone">Mobile number</label>
                <div class="auth-input-shell"><i class="fa-solid fa-mobile-screen-button"></i><input class="form-control" name="phone" id="phone" required autocomplete="tel" inputmode="tel" placeholder="09171234567" value="<?= e($values['phone']) ?>"></div>
                <div class="auth-field-note"><i class="fa-solid fa-message"></i> Used for urgent SMS reminders</div>
                <div class="invalid-feedback">Enter a valid mobile number.</div>
            </div>

            <div class="col-12 auth-field">
                <label for="address">Home address <span class="text-muted small">(barangay / street, Tupi or nearby)</span></label>
                <div class="auth-input-shell"><i class="fa-solid fa-location-dot"></i><input class="form-control" name="address" id="address" maxlength="255" autocomplete="street-address" placeholder="e.g. Purok Malinis, Brgy. Poblacion, Tupi, South Cotabato" value="<?= e($values['address'] ?? '') ?>"></div>
                <div class="auth-field-note"><i class="fa-solid fa-route"></i> Powers your personal travel-time estimate in the event planner</div>
            </div>

            <div class="col-12 auth-field">
                <label for="role">Account type</label>
                <div class="auth-input-shell"><i class="fa-solid fa-users"></i><select class="form-select" name="role" id="role"><option <?= $values['role']==='Participant/Athlete'?'selected':'' ?>>Participant/Athlete</option><option <?= $values['role']==='Spectator/Community Member'?'selected':'' ?>>Spectator/Community Member</option></select></div>
                <div id="roleDescription" class="auth-field-note">Join events, plan permitted activities, access venues, shop, communication, and community features.</div>
            </div>

            <div class="col-md-6 auth-field">
                <label for="reg_password">Password</label>
                <div class="auth-input-shell"><i class="fa-solid fa-lock"></i><input class="form-control password-strength-input" type="password" name="password" id="reg_password" required minlength="10" autocomplete="new-password" placeholder="New password"><button class="auth-password-btn" type="button" data-password-toggle="#reg_password" aria-label="Show password"><i class="fa-regular fa-eye"></i></button></div>
                <div class="invalid-feedback">Use at least 10 characters with uppercase, lowercase, and a number.</div>
            </div>

            <div class="col-md-6 auth-field">
                <label for="confirm_password">Confirm password</label>
                <div class="auth-input-shell"><i class="fa-solid fa-shield-halved"></i><input class="form-control" type="password" name="password_confirm" id="confirm_password" required minlength="10" autocomplete="new-password" placeholder="Repeat password"><button class="auth-password-btn" type="button" data-password-toggle="#confirm_password" aria-label="Show password"><i class="fa-regular fa-eye"></i></button></div>
                <div class="invalid-feedback" id="confirmFeedback">Passwords must match.</div>
            </div>
        </div>

        <div class="password-meter mt-3" id="registerMeter"><span></span></div>
        <div class="registration-help" id="passwordRules" aria-live="polite">
            <span data-rule="length"><i class="fa-regular fa-circle"></i>10+ characters</span>
            <span data-rule="upper"><i class="fa-regular fa-circle"></i>Uppercase</span>
            <span data-rule="lower"><i class="fa-regular fa-circle"></i>Lowercase</span>
            <span data-rule="number"><i class="fa-regular fa-circle"></i>Number</span>
        </div>

        <label class="auth-check auth-terms-check mt-3 mb-4"><input type="checkbox" name="terms" id="terms" required <?= isset($_POST['terms']) ? 'checked' : '' ?>><span>I agree to use Sporty Ni Migo responsibly and keep my account credentials secure.</span></label>

        <button class="btn auth-primary-btn" id="registerSubmit" type="submit">
            <span class="submit-label">Create account</span>
            <span class="submit-loading d-none"><span class="spinner-border spinner-border-sm" aria-hidden="true"></span> Creating account...</span>
            <i class="fa-solid fa-user-check submit-icon"></i>
        </button>
    </form>
    <?php
    return (string)ob_get_clean();
}

$errors = [];
$existingAccount = false;
$values = [
    'full_name' => '',
    'email' => '',
    'phone' => '',
    'address' => '',
    'role' => 'Participant/Athlete',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    foreach ($values as $key => $default) {
        $values[$key] = trim($_POST[$key] ?? $default);
    }

    $values['email'] = strtolower($values['email']);
    $password = $_POST['password'] ?? '';
    $confirm = $_POST['password_confirm'] ?? '';

    if (filter_var($values['email'], FILTER_VALIDATE_EMAIL)) {
        $check = $pdo->prepare('SELECT id,status FROM users WHERE email=? LIMIT 1');
        $check->execute([$values['email']]);
        if ($check->fetch(PDO::FETCH_ASSOC)) {
            $existingAccount = true;
            $errors[] = 'This email is already registered. Sign in or reset the password for the existing account.';
        }
    }

    if (!$existingAccount) {
        if (mb_strlen($values['full_name']) < 3) {
            $errors[] = 'Enter your complete name.';
        }
        if (!filter_var($values['email'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Enter a valid email address.';
        }
        if ($values['phone'] === '') {
            $errors[] = 'Mobile number is required for urgent Sporty Ni Migo SMS reminders.';
        } else {
            $normalizedPhone = sportsync_normalize_phone($values['phone']);
            if (!$normalizedPhone) {
                $errors[] = 'Enter a valid Philippine mobile number, such as 09171234567 or +639171234567.';
            } else {
                $values['phone'] = $normalizedPhone;
            }
        }
        if (!in_array($values['role'], ['Participant/Athlete', 'Spectator/Community Member'], true)) {
            $errors[] = 'Invalid account type.';
        }
        if (!password_is_strong($password)) {
            $errors[] = 'Password must be at least 10 characters and include uppercase, lowercase, and a number.';
        }
        if ($password !== $confirm) {
            $errors[] = 'Passwords do not match.';
        }
        if (!isset($_POST['terms'])) {
            $errors[] = 'Accept the account security terms to continue.';
        }
    }

    if (!$errors) {
        $role = $pdo->prepare('SELECT id FROM roles WHERE name=? LIMIT 1');
        $role->execute([$values['role']]);
        $roleId = $role->fetchColumn();

        if (!$roleId) {
            $errors[] = 'The selected account type is currently unavailable.';
        } else {
            try {
                $pdo->beginTransaction();
                $pdo->prepare(
                    'INSERT INTO users(role_id,full_name,email,password_hash,phone,address,status,email_verified_at)
                     VALUES(?,?,?,?,?,?,"active",NOW())'
                )->execute([
                    $roleId,
                    $values['full_name'],
                    $values['email'],
                    password_hash($password, PASSWORD_DEFAULT),
                    $values['phone'],
                    $values['address'] !== '' ? $values['address'] : null,
                ]);

                $uid = $pdo->lastInsertId();

                $pdo->prepare('INSERT INTO messages(sender_id,receiver_id,message_type,subject,message) VALUES(NULL,?,"text",?,?)')
                    ->execute([$uid, 'Welcome to Sporty Ni Migo', 'Your account is ready. Explore events, venues, communication, announcements, and community updates.']);

                $pdo->prepare('INSERT INTO activity_logs(user_id,action,details) VALUES(?,?,?)')
                    ->execute([$uid, 'account_registered', 'Self-service registration']);

                $pdo->commit();

                // Mirror the account into Clerk so the member can also use the
                // verified-email sign-in flow when Clerk is configured.
                if ($clerkOn) {
                    $sync = sportsync_clerk_create_user($values['email'], $values['full_name'], $values['phone'], $password);
                    if ($sync['ok'] && $sync['id']) {
                        $pdo->prepare('UPDATE users SET clerk_id=? WHERE id=?')->execute([$sync['id'], $uid]);
                    } elseif ($sync['error']) {
                        sportsync_log('Clerk mirror for ' . $values['email'] . ' failed: ' . $sync['error']);
                    }
                }

                header('Location: login.php?notice='.urlencode('Account created successfully. You can now sign in.').'&email='.urlencode($values['email']));
                exit;
            } catch (PDOException $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                if (($e->errorInfo[1] ?? null) === 1062) {
                    $existingAccount = true;
                    $errors[] = 'This email is already registered. Sign in or reset your password.';
                } else {
                    error_log('Sporty Ni Migo registration error: '.$e->getMessage());
                    $errors[] = 'Registration could not be completed. Please try again.';
                }
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                error_log('Sporty Ni Migo registration error: '.$e->getMessage());
                $errors[] = 'Registration could not be completed. Please try again.';
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
<meta name="description" content="Create a Sporty Ni Migo account for online sports events, venue access, communication, and community participation.">
<title>Create account · Sporty Ni Migo</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Poppins:wght@500;600;700;800&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" rel="stylesheet">
<link rel="icon" type="image/png" href="assets/img/logo-icon.png"><link rel="stylesheet" href="assets/css/app.css?v=20261002a">
<style>
.clerk-mount{min-height:480px;display:flex;align-items:flex-start;justify-content:center}
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
<main class="auth-shell auth-shell-pro auth-shell-register-pro">
    <section class="auth-hero auth-hero-pro d-none d-lg-flex">
        <div class="auth-hero-pro-inner">
            <div class="auth-brand-pro">
                <span class="auth-brand-mark"><img src="assets/img/logo-icon.png" alt="Sporty Ni Migo logo"></span>
                <div><strong>Sporty Ni Migo</strong><small>Sports Event Operations</small></div>
            </div>

            <div class="auth-hero-copy-pro">
                <span class="auth-kicker"><i class="fa-solid fa-people-group me-2"></i>JOIN THE SPORTSYNC COMMUNITY</span>
                <h1>Your sports journey,<br>connected.</h1>
                <p>Create one account to plan or join events, discover venues, receive urgent updates, communicate with the community, and stay connected wherever you are.</p>

                <div class="auth-feature-grid">
                    <div class="auth-feature"><i class="fa-solid fa-calendar-plus"></i><div><strong>Plan & join events</strong><span>Simple online event workflows</span></div></div>
                    <div class="auth-feature"><i class="fa-solid fa-bell"></i><div><strong>Stay informed</strong><span>Announcements and urgent SMS</span></div></div>
                    <div class="auth-feature"><i class="fa-solid fa-comments"></i><div><strong>Communicate</strong><span>Messages and voice in one place</span></div></div>
                    <div class="auth-feature"><i class="fa-solid fa-map-location-dot"></i><div><strong>Explore Tupi venues</strong><span>Locations, capacity, and directions</span></div></div>
                </div>
            </div>

            <div class="auth-hero-footer-pro">
                <span><i class="fa-solid fa-user-shield"></i> Safe account setup</span>
                <span><i class="fa-solid fa-mobile-screen"></i> Works on any device</span>
                <span><i class="fa-solid fa-cloud"></i> Online access</span>
            </div>
        </div>
    </section>

    <section class="auth-panel-pro">
        <div class="auth-card auth-card-pro auth-register-card-pro">
            <div class="auth-card-inner auth-card-inner-pro">
                <a href="login.php" class="auth-back-link"><i class="fa-solid fa-arrow-left"></i> Back to sign in</a>

                <div class="auth-mobile-brand d-lg-none">
                    <span class="auth-brand-mark"><img src="assets/img/logo-icon.png" alt="Sporty Ni Migo logo"></span>
                    <div><strong>Sporty Ni Migo</strong><small>Sports Event Operations</small></div>
                </div>

                <div class="auth-heading-pro">
                    <span class="eyebrow">CREATE ACCOUNT</span>
                    <h2>Get started with Sporty Ni Migo</h2>
                    <p>Participant and community accounts can register online. Administrative roles are created by authorized management.</p>
                </div>

                <?php if ($errors): ?>
                <div class="auth-alert auth-alert-danger" role="alert">
                    <i class="fa-solid fa-circle-exclamation"></i>
                    <div><strong>Please review</strong><ul class="mb-0 mt-1 ps-3"><?php foreach ($errors as $x): ?><li><?= e($x) ?></li><?php endforeach; ?></ul>
                    <?php if ($existingAccount): ?><div class="account-exists-actions mt-3"><a class="btn btn-sm btn-light" href="login.php?email=<?= urlencode($values['email']) ?>"><i class="fa-solid fa-right-to-bracket me-1"></i>Sign in</a><a class="btn btn-sm btn-outline-light" href="forgot_password.php"><i class="fa-solid fa-key me-1"></i>Reset password</a></div><?php endif; ?></div>
                </div>
                <?php endif; ?>

                <?php if ($clerkOn): ?>
                    <div class="clerk-divider">or register with the classic form</div>
                    <div class="clerk-mount" id="clerk-auth"></div>
                    <div id="clerk-fallback" class="d-none">
                        <?= sportysync_register_form() ?>
                    </div>
                <?php else: ?>
                    <?= sportysync_register_form() ?>
                <?php endif; ?>

                <div class="auth-divider"><span>Already registered?</span></div>
                <a class="btn auth-secondary-btn" href="login.php"><i class="fa-solid fa-right-to-bracket"></i> Sign in instead</a>
            </div>
        </div>
    </section>
</main>

<?php if ($clerkOn): ?>
    <?= sportsync_clerk_js('SignUp', ['after' => 'auth/clerk_sync.php']) ?>
    <?= sportsync_clerk_sync_script() ?>
    <script>
    (function () {
        var done = false;
        document.addEventListener('clerk-ready', function () {
            done = true;
            var sk = document.getElementById('clerk-skeleton'); if (sk) sk.classList.add('d-none');
            var mount = document.getElementById('clerk-auth');
            if (mount && !mount.childElementCount) document.getElementById('clerk-fallback')?.classList.remove('d-none');
        });
        setTimeout(function () {
            if (done) return;
            var fb = document.getElementById('clerk-fallback');
            if (fb) fb.classList.remove('d-none');
            var sk = document.getElementById('clerk-skeleton'); if (sk) sk.classList.add('d-none');
        }, 8000);
        window.addEventListener('error', function (ev) {
            if (done || !(ev.target && ev.target.src && ev.target.src.indexOf('clerk') !== -1)) return;
            var fb = document.getElementById('clerk-fallback'); if (fb) fb.classList.remove('d-none');
            var sk = document.getElementById('clerk-skeleton'); if (sk) sk.classList.add('d-none');
        }, true);
    })();
    </script>
<?php endif; ?>
<script src="assets/js/app.js"></script>
<script>
(() => {
    const form = document.getElementById('registerForm');
    const password = document.getElementById('reg_password');
    const confirm = document.getElementById('confirm_password');
    const meter = document.getElementById('registerMeter');
    const submit = document.getElementById('registerSubmit');
    const role = document.getElementById('role');
    const roleDescription = document.getElementById('roleDescription');
    const rules = {
        length: v => v.length >= 10,
        upper: v => /[A-Z]/.test(v),
        lower: v => /[a-z]/.test(v),
        number: v => /\d/.test(v)
    };

    function updateRule(name, ok) {
        const el = document.querySelector('[data-rule="'+name+'"]');
        if (!el) return;
        el.classList.toggle('valid', ok);
        el.classList.toggle('invalid', !ok && password.value.length > 0);
        const icon = el.querySelector('i');
        if (icon) icon.className = ok ? 'fa-solid fa-circle-check' : 'fa-regular fa-circle';
    }

    function updatePassword() {
        const v = password.value;
        let score = 0;
        Object.entries(rules).forEach(([name, fn]) => { const ok = fn(v); if (ok) score++; updateRule(name, ok); });
        meter.className = 'password-meter mt-3 '+(score <= 1 ? 'weak' : score <= 3 ? 'medium' : 'strong');
        if (confirm.value) confirm.setCustomValidity(v === confirm.value ? '' : 'Passwords do not match.');
    }

    password?.addEventListener('input', updatePassword);
    confirm?.addEventListener('input', () => confirm.setCustomValidity(password.value === confirm.value ? '' : 'Passwords do not match.'));
    role?.addEventListener('change', () => {
        roleDescription.textContent = role.value === 'Participant/Athlete'
            ? 'Join events, plan permitted activities, access venues, shop, communication, and community features.'
            : 'Follow events, shop, communicate, receive updates, and participate in the Sporty Ni Migo community.';
    });

    form?.addEventListener('submit', (event) => {
        confirm.setCustomValidity(password.value === confirm.value ? '' : 'Passwords do not match.');
        if (!form.checkValidity()) {
            event.preventDefault();
            event.stopPropagation();
            form.classList.add('was-validated');
            form.querySelector(':invalid')?.scrollIntoView({behavior:'smooth', block:'center'});
            return;
        }
        submit.disabled = true;
        submit.querySelector('.submit-label')?.classList.add('d-none');
        submit.querySelector('.submit-icon')?.classList.add('d-none');
        submit.querySelector('.submit-loading')?.classList.remove('d-none');
    });
})();
</script>
</body>
</html>
