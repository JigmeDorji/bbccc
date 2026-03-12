<?php
/**
 * router.php — PHP built-in server URL router
 * Allows clean URLs without .php extension.
 * Usage: php -S localhost:8080 router.php
 */

$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// Serve real static files (css, js, images, fonts, etc.) as-is
if ($uri !== '/' && file_exists(__DIR__ . $uri)) {
    return false;
}

// Strip leading slash and trailing slash
$path = trim($uri, '/');

// If empty, serve index.php
if ($path === '') {
    require __DIR__ . '/index.php';
    exit;
}

// If exact .php file exists, serve it
if (file_exists(__DIR__ . '/' . $path . '.php')) {
    require __DIR__ . '/' . $path . '.php';
    exit;
}

// If the path already ends in .php and the file exists, serve it
if (substr($path, -4) === '.php' && file_exists(__DIR__ . '/' . $path)) {
    require __DIR__ . '/' . $path;
    exit;
}

// 404 fallback
http_response_code(404);
echo "<h1>404 Not Found</h1><p>The page <strong>/$path</strong> could not be found.</p>";
