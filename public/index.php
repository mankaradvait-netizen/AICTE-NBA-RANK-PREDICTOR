<?php

declare(strict_types=1);

// Built-in CLI web server static file pass-through
if (php_sapi_name() === 'cli-server') {
    $rawPath = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
    if ($rawPath !== null && $rawPath !== false && $rawPath !== '/' && file_exists(__DIR__ . $rawPath)) {
        return false;
    }
}

use App\App;
use App\Config\Environment;

require __DIR__ . '/../vendor/autoload.php';

// Load environment configuration
Environment::load(__DIR__ . '/..');

// Bootstrap Slim 4 application
$app = App::create();

// Run application
$app->run();
