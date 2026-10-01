<?php
/**
 * ============================================================================
 * Vercel front controller
 * ============================================================================
 * On Vercel this single serverless function receives every dynamic request
 * (see vercel.json) and dispatches it to the real page in the project root.
 * Static /assets and /uploads files are served directly by Vercel's CDN.
 *
 * Query strings are preserved because vercel.json routes to this file without
 * a destination query, so REQUEST_URI still holds the original path (?page=…,
 * ?ok=…, ?contact=… all keep working).
 *
 * Relative requires inside the entry files ("require 'config/db.php'") resolve
 * against the current working directory, so the handler chdir()s to the
 * target file's folder first — exactly like Apache would.
 */

$docroot = realpath(dirname(__DIR__));
if ($docroot === false) {
    http_response_code(500);
    exit('Application root could not be resolved.');
}

$uriPath = urldecode((string)(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/'));
$path = ltrim($uriPath, '/');
if ($path === '' || $path === '/') {
    $path = 'index.php';
}
// Pretty URLs: /login and /register resolve to their .php entry points.
if (!str_ends_with($path, '.php')) {
    $path .= '.php';
}

$target = realpath($docroot . '/' . $path);

if (
    !$target
    || !str_starts_with($target, $docroot . DIRECTORY_SEPARATOR)
    || !is_file($target)
    || !str_ends_with($target, '.php')
    || realpath(__FILE__) === $target // never recurse into this controller
) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Not found.');
}

chdir(dirname($target));
require $target;
