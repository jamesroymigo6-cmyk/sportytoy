<?php
/**
 * Sporty Ni Migo online application bootstrap.
 * Loads environment variables, applies production-safe headers, and exposes helpers.
 */

if (!function_exists('sportsync_env')) {
    function sportsync_env(string $key, ?string $default = null): ?string {
        $value = getenv($key);
        if ($value !== false) return $value;
        if (isset($_ENV[$key])) return (string)$_ENV[$key];
        if (isset($_SERVER[$key])) return (string)$_SERVER[$key];
        return $default;
    }
}

if (!defined('SPORTSYNC_ROOT')) define('SPORTSYNC_ROOT', dirname(__DIR__));

// Lightweight .env loader for hosting panels that do not expose environment variables.
$envFile = SPORTSYNC_ROOT . '/.env';
if (is_file($envFile) && is_readable($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) continue;
        [$key, $value] = array_map('trim', explode('=', $line, 2));
        if ($key === '') continue;
        if ((str_starts_with($value, '"') && str_ends_with($value, '"')) || (str_starts_with($value, "'") && str_ends_with($value, "'"))) {
            $value = substr($value, 1, -1);
        }
        if (getenv($key) === false) {
            putenv($key . '=' . $value);
            $_ENV[$key] = $value;
        }
    }
}

if (!defined('APP_ENV')) define('APP_ENV', sportsync_env('APP_ENV', 'production'));
if (!defined('APP_DEBUG')) define('APP_DEBUG', filter_var(sportsync_env('APP_DEBUG', '0'), FILTER_VALIDATE_BOOL));
if (!defined('APP_URL')) define('APP_URL', rtrim((string)sportsync_env('APP_URL', ''), '/'));

// PHP and the MySQL session MUST agree on the time zone. The connection sets
// its session to this zone too (see config/db.php), so date(), NOW() and every
// stored timestamp line up. Without this, PHP falls back to the server's zone
// while MySQL uses another, and lockouts, reminders and "today" lookups drift
// by the difference.
if (!defined('APP_TIMEZONE')) define('APP_TIMEZONE', (string)sportsync_env('APP_TIMEZONE', 'Asia/Manila'));
date_default_timezone_set(APP_TIMEZONE);

function sportsync_is_https(): bool {
    if (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') return true;
    if ((int)($_SERVER['SERVER_PORT'] ?? 0) === 443) return true;
    // Common reverse-proxy headers used by Cloudflare, cPanel, Render, Railway, etc.
    if (strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https') return true;
    if (strtolower((string)($_SERVER['HTTP_CF_VISITOR'] ?? '')) && str_contains((string)$_SERVER['HTTP_CF_VISITOR'], 'https')) return true;
    return false;
}

function sportsync_url(string $path = ''): string {
    $path = ltrim($path, '/');
    if (APP_URL !== '') return APP_URL . ($path !== '' ? '/' . $path : '');
    $scheme = sportsync_is_https() ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $base = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/.');
    return $scheme . '://' . $host . ($base ? $base : '') . ($path !== '' ? '/' . $path : '');
}

function sportsync_security_headers(): void {
    if (headers_sent()) return;
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: camera=(), geolocation=(self), microphone=(self)');
    header("Cross-Origin-Opener-Policy: same-origin-allow-popups");
    // The app loads Bootstrap, Font Awesome, Google Fonts, Leaflet, OSM tiles,
    // Open-Meteo, and — when Clerk authentication is enabled — the ClerkJS SDK
    // with its Frontend API, accounts portal, image CDN and telemetry endpoints.
    $clerk = sportsync_clerk_enabled();
    $clerkScript = $clerk ? ' https://*.clerk.accounts.dev https://clerk.dev https://*.clerk.dev' : '';
    $clerkConnect = $clerk ? ' https://api.clerk.com https://*.clerk.accounts.dev https://clerk.dev https://*.clerk.dev https://clerk.telemetry.clerk.com' : '';
    $clerkFrame = $clerk ? ' https://*.clerk.accounts.dev https://clerk.dev https://*.clerk.dev' : '';
    $clerkImg = $clerk ? ' https://img.clerk.com https://*.clerk.accounts.dev' : '';
    header("Content-Security-Policy: default-src 'self'; base-uri 'self'; form-action 'self'" . ($clerk ? ' https://*.clerk.accounts.dev' : '') . "; frame-ancestors 'self'; object-src 'none'; img-src 'self' data: blob: https://*.tile.openstreetmap.org https://unpkg.com{$clerkImg}; media-src 'self' blob:; style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com https://fonts.googleapis.com https://unpkg.com; font-src 'self' data: https://fonts.gstatic.com https://cdnjs.cloudflare.com; script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://unpkg.com{$clerkScript}; connect-src 'self' https://api.open-meteo.com https://*.tile.openstreetmap.org https://unpkg.com{$clerkConnect}; frame-src {$clerkFrame}");
    if (sportsync_is_https()) {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
}

function sportsync_log(Throwable|string $error): void {
    $message = $error instanceof Throwable ? ($error::class . ': ' . $error->getMessage() . "\n" . $error->getTraceAsString()) : (string)$error;
    $dir = SPORTSYNC_ROOT . '/storage/logs';
    // Serverless hosts (Vercel) have a read-only application directory, so
    // fall back to the system temp folder when the project log is unwritable.
    if (!is_dir($dir) || !is_writable($dir)) $dir = sys_get_temp_dir();
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    @error_log('[' . date('c') . '] ' . $message . PHP_EOL, 3, $dir . '/app.log');
}

function sportsync_public_error(Throwable $e): string {
    sportsync_log($e);
    if (APP_DEBUG) return $e->getMessage();
    if ($e instanceof Exception && !($e instanceof PDOException)) return $e->getMessage();
    if ($e instanceof PDOException) {
        $state = (string)$e->getCode();
        $msg = $e->getMessage();
        if (str_contains($msg, 'foreign key constraint') || $state === '23000') {
            return 'The selected record or your login session no longer matches the database. Please refresh the page; if this database was re-imported, sign out and sign in again.';
        }
        if (str_contains($msg, 'Unknown column') || str_contains($msg, "doesn't exist") || $state === '42S22' || $state === '42S02') {
            return 'The Sporty Ni Migo database schema is out of date or incomplete. Open the application once with an administrator database account to let it self-upgrade, or import database/sports_events_full.sql for a fresh install.';
        }
        if (str_contains($msg, 'Duplicate entry')) return 'That record already exists. Please use different details or refresh the page.';
    }
    $code='SS-'.strtoupper(substr(hash('sha256',$e->getMessage().$e->getFile().$e->getLine()),0,8));
    return 'Sporty Ni Migo could not save this change. Error reference: '.$code.'. Please refresh and try again. An administrator can check storage/logs/app.log using this time/error reference.';
}

// Clerk is loaded here so every entry point (and the security headers below)
// can rely on its helpers. Its require_once back to app.php is a safe no-op.
require_once __DIR__ . '/clerk.php';

sportsync_security_headers();

if (APP_DEBUG) {
    ini_set('display_errors', '1');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '0');
    error_reporting(E_ALL);
}
