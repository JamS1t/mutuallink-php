<?php
declare(strict_types=1);

/**
 * Router for PHP's built-in server (php -S).
 * Replicates the protection in .htaccess, which only works under Apache:
 * - 403 for internal folders
 * - 403 for non-public file extensions
 */

$uri = urldecode(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/');

$blocked = preg_match('#^/(config|lib|database|tests|docs|include|views)(/|$)#i', $uri);
if ($blocked || preg_match('/\.(sql|bat|md|log|ini)$/i', $uri)) {
    http_response_code(403);
    exit('Forbidden');
}

// Serve real files (css/js/img/pdf) directly; route everything else through the entry points.
$file = __DIR__ . $uri;
if ($uri !== '/' && is_file($file)) {
    return false;
}

// Directory index: /documentation/ -> /documentation/index.html (Apache's DirectoryIndex equivalent)
if ($uri !== '/' && is_dir($file)) {
    $dirUri = rtrim($uri, '/');
    if (is_file(__DIR__ . $dirUri . '/index.html')) {
        header('Content-Type: text/html; charset=utf-8');
        readfile(__DIR__ . $dirUri . '/index.html');
        return true;
    }
    if (is_file(__DIR__ . $dirUri . '/index.php')) {
        require __DIR__ . $dirUri . '/index.php';
        return true;
    }
}
if ($uri === '/' || $uri === '') {
    require __DIR__ . '/index.php';
    return true;
}
// Pretty paths without .php fall back to file.php if it exists, otherwise the login page.
if (is_file(__DIR__ . $uri . '.php')) {
    require __DIR__ . $uri . '.php';
    return true;
}
require __DIR__ . '/index.php';
