<?php
require_once __DIR__ . '/app.php';

$host = sportsync_env('DB_HOST', '127.0.0.1');
$port = sportsync_env('DB_PORT', '3306');
$db   = sportsync_env('DB_DATABASE', 'sports_event_system');
$user = sportsync_env('DB_USERNAME', 'root');
$pass = sportsync_env('DB_PASSWORD', '');

$dsn = "mysql:host={$host};port={$port};dbname={$db};charset=utf8mb4";

// Cloud MySQL hosts (Aiven, TiDB Serverless, etc.) usually require TLS.
// Set DB_SSL=1 (and optionally DB_SSL_CA to a CA bundle path) to enable it.
$pdoOptions = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
    PDO::ATTR_PERSISTENT => false,
    PDO::ATTR_TIMEOUT => 8,
];
if (sportsync_env('DB_SSL', '') === '1') {
    if (defined('PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT')) $pdoOptions[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = false;
    if ((string)sportsync_env('DB_SSL_CA', '') !== '' && defined('PDO::MYSQL_ATTR_SSL_CA')) $pdoOptions[PDO::MYSQL_ATTR_SSL_CA] = sportsync_env('DB_SSL_CA', '');
}

try {
    $pdo = new PDO($dsn, $user, $pass, $pdoOptions);
    // Derive the MySQL session offset from APP_TIMEZONE so the database can
    // never drift away from what PHP's date() and date_default_timezone_set()
    // produce (shared hosting often has MySQL on a different zone than PHP).
    try {
        $offset = (new DateTimeZone(APP_TIMEZONE))->getOffset(new DateTime('now', new DateTimeZone(APP_TIMEZONE)));
        $pdo->exec("SET time_zone = '" . sprintf('%s%02d:%02d', $offset < 0 ? '-' : '+', intdiv(abs($offset), 3600), intdiv(abs($offset) % 3600, 60)) . "'");
    } catch (Throwable $e) {
        sportsync_log('Could not align the database time zone with ' . APP_TIMEZONE . ': ' . $e->getMessage());
    }
    require_once __DIR__ . '/schema.php';
    sportsync_ensure_schema($pdo);
} catch (PDOException $e) {
    sportsync_log($e);
    http_response_code(503);
    if (APP_DEBUG) {
        die('Database connection failed: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'));
    }
    die('Sporty Ni Migo is temporarily unable to connect to its database. Please try again later.');
}
