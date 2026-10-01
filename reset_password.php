<?php
require 'config/db.php'; require 'config/auth.php';

// Local reset links are meaningless under Clerk-owned recovery.
if (sportsync_clerk_enabled()) {
    header('Location: login.php?notice=' . urlencode('Password resets are handled by Clerk. Sign in with a one-time email code instead.'));
    exit;
}

$token=$_GET['token']??$_POST['token']??'';$error='';
$hash=hash('sha256',$token);$q=$pdo->prepare('SELECT u.id user_id,u.email,u.reset_expires_at expires_at FROM users u WHERE u.reset_token_hash=? AND u.reset_expires_at>NOW() LIMIT 1');$q->execute([$hash]);$reset=$q->fetch();
if(!$reset) $error='This reset link is invalid or has expired.';
if($_SERVER['REQUEST_METHOD']==='POST' && $reset){verify_csrf();$pass=$_POST['password']??'';$confirm=$_POST['password_confirm']??'';if(!password_is_strong($pass))$error='Use at least 10 characters with uppercase, lowercase, and a number.';elseif($pass!==$confirm)$error='Passwords do not match.';else{$pdo->beginTransaction();$pdo->prepare('UPDATE users SET password_hash=?,failed_login_attempts=0,locked_until=NULL,reset_token_hash=NULL,reset_expires_at=NULL WHERE id=?')->execute([password_hash($pass,PASSWORD_DEFAULT),$reset['user_id']]);$pdo->commit();header('Location: login.php?notice='.urlencode('Password updated successfully. Please sign in.'));exit;}}
?>
<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>New password · SportSync</title><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"><link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet"><link rel="stylesheet" href="assets/css/app.css?v=20260929i"></head><body class="auth-body"><main class="auth-simple"><section class="auth-card glass"><div class="auth-card-inner"><span class="eyebrow">SECURE RESET</span><h2>Choose a new password</h2><?php if($error):?><div class="alert alert-danger"><?=e($error)?></div><?php endif;?><?php if($reset):?><form method="post"><input type="hidden" name="csrf" value="<?=csrf_token()?>"><input type="hidden" name="token" value="<?=e($token)?>"><div class="form-floating mb-3"><input type="password" class="form-control password-strength-input" name="password" id="newpass" required placeholder="Password"><label for="newpass">New password</label></div><div class="form-floating mb-3"><input type="password" class="form-control" name="password_confirm" id="newpass2" required placeholder="Confirm"><label for="newpass2">Confirm password</label></div><div class="password-meter mb-3"><span></span></div><button class="btn btn-primary w-100 py-3">Update password</button></form><?php else:?><a class="btn btn-primary w-100" href="forgot_password.php">Request a new link</a><?php endif;?></div></section></main><script src="assets/js/app.js"></script></body></html>
