<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

$_ENV['JWT_SECRET'] = bin2hex(random_bytes(32));
$_ENV['MASTER_KEY_HEX'] = bin2hex(random_bytes(32));
$_ENV['ACTIVE_KEY_ID'] = 'phpunit-' . bin2hex(random_bytes(8));
