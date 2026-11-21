<?php

declare(strict_types=1);

// Built-in CLI web server static file pass-through
if (php_sapi_name() === 'cli-server') {
    $rawPath = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
    if ($rawPath !== null && $rawPath !== false && $rawPath !== '/' && file_exists(__DIR__ . $rawPath)) {
        return false;
    }
}

// Suppress inline HTML warnings leaking into API responses while logging errors
ini_set('display_errors', '0');
ini_set('memory_limit', '512M');
error_reporting(E_ALL);

// Register fatal shutdown handler to guarantee JSON response even on fatal crash
register_shutdown_function(function () {
    $error = error_get_last();
    if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
            http_response_code(500);
        }
        echo json_encode([
            'success' => false,
            'error' => 'Server Fatal Error: ' . $error['message'] . ' in ' . basename($error['file']) . ':' . $error['line'],
        ]);
    }
});

use App\App;
use App\Config\Environment;

require __DIR__ . '/../vendor/autoload.php';

// Load environment configuration
Environment::load(__DIR__ . '/..');

// Bootstrap Slim 4 application
$app = App::create();

// Run application
$app->run();
