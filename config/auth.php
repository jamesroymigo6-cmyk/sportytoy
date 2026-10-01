<?php
require_once __DIR__ . '/app.php';

if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_samesite', 'Lax');
    ini_set('session.cookie_secure', sportsync_is_https() ? '1' : '0');
    ini_set('session.gc_maxlifetime', (string)(60 * 60 * 4));

    // Database-backed sessions so logins survive serverless hosting (Vercel),
    // where the filesystem is ephemeral and file sessions cannot be shared.
    require_once __DIR__ . '/session.php';
    if (class_exists('SportyNiMigoDbSessionHandler')) {
        try {
            session_set_save_handler(new SportyNiMigoDbSessionHandler(), true);
        } catch (Throwable $sessionHandlerError) {
            sportsync_log($sessionHandlerError); // fall back to file sessions
        }
    }

    session_name('SPORTSYNCSESSID');
    session_start([
        'cookie_httponly' => true,
        'cookie_secure' => sportsync_is_https(),
        'cookie_samesite' => 'Lax',
    ]);
}

// Idle-session expiry for online shared devices.
$idleSeconds = max(900, (int)sportsync_env('SESSION_IDLE_SECONDS', '7200'));
if (!empty($_SESSION['last_activity']) && (time() - (int)$_SESSION['last_activity']) > $idleSeconds) {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires' => time() - 42000,
            'path' => $params['path'] ?: '/',
            'domain' => $params['domain'] ?: '',
            'secure' => (bool)$params['secure'],
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }
    session_destroy();
    session_start();
}
$_SESSION['last_activity'] = time();

function current_user(){ return $_SESSION['user'] ?? null; }
function require_login(){
    if(!current_user()){
        $_SESSION['after_login'] = $_SERVER['REQUEST_URI'] ?? 'index.php';
        header('Location: landing.php');
        exit;
    }
}
function require_api_login(): void {
    if (!current_user()) {
        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok'=>false,'error'=>'Authentication required.']);
        exit;
    }
}
function has_role($roles){ $u=current_user(); return $u && in_array($u['role_name'], (array)$roles, true); }

function role_capabilities(?string $roleName = null): array {
    $roleName = $roleName ?? (current_user()['role_name'] ?? '');
    $map = [
        'Administrator' => [
            'manage_events','manage_venues','manage_equipment','manage_inventory','manage_orders',
            'manage_participants','manage_announcements','manage_users','view_reports','communicate','community','view_weather'
        ],
        'Staff/Coordinator' => [
            'manage_events','manage_venues','manage_equipment','manage_inventory','manage_orders',
            'manage_participants','manage_announcements','view_reports','communicate','community','view_weather'
        ],
        'Event Organizer' => [
            'plan_event','manage_own_event','borrow_equipment','order_merchandise','communicate','community','view_weather','travel'
        ],
        'Participant/Athlete' => [
            'plan_event','manage_own_event','borrow_equipment','order_merchandise','register_event','communicate','community','view_weather','travel'
        ],
        'Spectator/Community Member' => [
            'plan_event','manage_own_event','borrow_equipment','order_merchandise','register_event','communicate','community','view_weather','travel'
        ],
    ];
    return $map[$roleName] ?? [];
}
function can_role(string $capability): bool { return in_array($capability, role_capabilities(), true); }
function require_capability(string $capability): void {
    if (!can_role($capability)) { http_response_code(403); exit('Forbidden: your role is not allowed to perform this action.'); }
}
function require_role($roles){ if(!has_role($roles)){ http_response_code(403); exit('Forbidden'); } }
function e($v){ return htmlspecialchars((string)$v, ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8'); }
function csrf_token(){ if(empty($_SESSION['csrf'])) $_SESSION['csrf']=bin2hex(random_bytes(32)); return $_SESSION['csrf']; }
function verify_csrf(){
    if($_SERVER['REQUEST_METHOD']==='POST'){
        $provided=$_POST['csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
        if(!hash_equals($_SESSION['csrf'] ?? '', (string)$provided)){
            // 403 instead of the Laravel-style 419: some Apache builds (this
            // XAMPP's httpd.conf included) turn unknown statuses into a 500,
            // which breaks client handling and pollutes error logs.
            http_response_code(403);
            exit('Invalid or expired security token. Refresh the page and try again.');
        }
    }
}
function password_is_strong($password){
    return strlen($password)>=10 && preg_match('/[A-Z]/',$password) && preg_match('/[a-z]/',$password) && preg_match('/\d/',$password);
}
function login_user(array $row){
    session_regenerate_id(true);
    $_SESSION['user']=[
        'id'=>(int)$row['id'],
        'name'=>$row['full_name'],
        'email'=>$row['email'],
        'role_name'=>$row['role_name'],
        'phone'=>$row['phone']??'',
        'address'=>$row['address']??''
    ];
    $_SESSION['csrf']=bin2hex(random_bytes(32));
    $_SESSION['last_activity']=time();
}
function refresh_session_user(PDO $pdo){
    if(!current_user()) return;
    $s=$pdo->prepare('SELECT u.id,u.full_name,u.email,u.phone,u.address,r.name role_name FROM users u JOIN roles r ON r.id=u.role_id WHERE u.id=? AND u.status="active"');
    $s->execute([current_user()['id']]);
    if($row=$s->fetch()) login_user($row); else { $_SESSION=[]; session_destroy(); }
}
function validate_session_user(PDO $pdo, bool $api = false): void {
    $sessionUser = current_user();
    if (!$sessionUser) return;
    try {
        $stmt = $pdo->prepare('SELECT u.id,u.full_name,u.email,u.phone,u.address,u.status,r.name role_name FROM users u JOIN roles r ON r.id=u.role_id WHERE u.id=? LIMIT 1');
        $stmt->execute([(int)$sessionUser['id']]);
        $row = $stmt->fetch();
    } catch (Throwable $e) {
        sportsync_log($e);
        return;
    }
    if (!$row || $row['status'] !== 'active') {
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) session_destroy();
        if ($api) {
            http_response_code(401);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok'=>false,'error'=>'Your login session is no longer valid. Please sign in again.']);
            exit;
        }
        header('Location: landing.php?notice='.urlencode('Your previous session no longer matches the current database. Please sign in again.'));
        exit;
    }
    // Keep the session identity synchronized without regenerating the CSRF token on every request.
    $_SESSION['user'] = [
        'id'=>(int)$row['id'],
        'name'=>$row['full_name'],
        'email'=>$row['email'],
        'role_name'=>$row['role_name'],
        'phone'=>$row['phone']??'',
        'address'=>$row['address']??''
    ];
}

function safe_local_redirect(string $target, string $fallback='index.php'): string {
    $target = trim($target);
    if ($target === '' || str_contains($target, '://') || str_starts_with($target, '//') || str_contains($target, "\r") || str_contains($target, "\n")) return $fallback;
    return $target;
}
