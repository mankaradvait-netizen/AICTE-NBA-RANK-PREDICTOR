<?php

declare(strict_types=1);

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class HealthController
{
    public function check(Request $request, Response $response): Response
    {
        $response->getBody()->write((string) json_encode([
            'status' => 'ok',
            'timestamp' => date('c'),
            'service' => 'NIRF Deterministic Analysis Platform',
        ]));
        return $response->withHeader('Content-Type', 'application/json');
    }
}
