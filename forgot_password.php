<?php
require 'config/db.php';
require 'config/auth.php';
require 'config/mailer.php';

// With Clerk enabled, email recovery is owned by Clerk's sign-in flow — the
// "Forgot password?" link becomes a sign-in code instead of a local token.
if (sportsync_clerk_enabled()) {
    header('Location: login.php?notice=' . urlencode('Password resets are handled by Clerk. Sign in with a one-time email code instead.'));
    exit;
}

$message='';
$devResetUrl='';

if($_SERVER['REQUEST_METHOD']==='POST'){
    verify_csrf();
    $email=strtolower(trim($_POST['email']??''));

    if(filter_var($email,FILTER_VALIDATE_EMAIL)){
        $q=$pdo->prepare('SELECT id,full_name,email FROM users WHERE email=? AND status="active" LIMIT 1');
        $q->execute([$email]);
        $user=$q->fetch();

        if($user){
            $token=bin2hex(random_bytes(32));
            $hash=hash('sha256',$token);
            $pdo->prepare('UPDATE users SET reset_token_hash=?,reset_expires_at=DATE_ADD(NOW(),INTERVAL 30 MINUTE) WHERE id=?')->execute([$hash,$user['id']]);

            $resetUrl=sportsync_url('reset_password.php?token='.urlencode($token));
            $subject='Reset your Sporty Ni Migo password';
            $html='<div style="font-family:Arial,sans-serif;max-width:600px;margin:auto">'
                .'<h2>Reset your Sporty Ni Migo password</h2>'
                .'<p>Hello '.e($user['full_name']).',</p>'
                .'<p>We received a request to reset your Sporty Ni Migo password. This link expires in 30 minutes.</p>'
                .'<p><a href="'.e($resetUrl).'" style="display:inline-block;padding:12px 18px;background:#2563eb;color:#fff;text-decoration:none;border-radius:8px">Reset password</a></p>'
                .'<p>If you did not request this, you can ignore this email.</p>'
                .'</div>';
            sportsync_send_mail($user['email'],$subject,$html);
            if(APP_DEBUG) $devResetUrl=$resetUrl;
        }
    }

    // Always return the same message to avoid revealing account existence.
    $message='If an active account matches that email, a password reset link has been sent.';
}
?>
<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Reset access · Sporty Ni Migo</title><link rel="icon" type="image/png" href="assets/img/logo-icon.png"><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"><link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" rel="stylesheet"><link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet"><link rel="stylesheet" href="assets/css/app.css?v=20260929i"></head><body class="auth-body"><main class="auth-simple"><section class="auth-card glass"><div class="auth-card-inner"><div class="auth-logo"><i class="fa-solid fa-key"></i></div><span class="eyebrow">ACCOUNT RECOVERY</span><h2>Forgot your password?</h2><p class="text-muted">Enter your account email and we will send a time-limited reset link.</p><?php if($message):?><div class="alert alert-info"><?=e($message)?></div><?php endif;?><?php if(APP_DEBUG && $devResetUrl):?><div class="dev-reset-box"><strong>Development reset link</strong><p class="small mb-2">Shown only because APP_DEBUG is enabled.</p><a href="<?=e($devResetUrl)?>">Reset my password</a></div><?php endif;?><form method="post"><input type="hidden" name="csrf" value="<?=csrf_token()?>"><div class="form-floating mb-3"><input type="email" class="form-control" name="email" id="forgot_email" required autocomplete="email" placeholder="Email"><label for="forgot_email">Email address</label></div><button class="btn btn-primary w-100 py-3">Send reset link</button></form><a href="login.php" class="btn btn-link w-100 mt-2">Back to sign in</a></div></section></main></body></html>
