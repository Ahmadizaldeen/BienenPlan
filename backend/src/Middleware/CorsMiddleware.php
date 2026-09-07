<?php

namespace BienenPlan\Middleware;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;
use Slim\Psr7\Response as SlimResponse;

class CorsMiddleware implements MiddlewareInterface { # Preflight prüfen und weitergeben
    public function process(Request $request, RequestHandler $handler): Response
    {
        // CORS Preflight-Anfrage
        if ($request->getMethod() === 'OPTIONS') {

            $response = new SlimResponse();

            return $this->addCorsHeaders($response);
        }
        // Normale Anfrage weiterleiten
        $response = $handler->handle($request);
       

        // CORS-Header zur Response hinzufügen
        return $this->addCorsHeaders($response);
    }

    private function addCorsHeaders(Response $response): Response
    {
        return $response
            ->withHeader('Access-Control-Allow-Origin', '*')
            ->withHeader(
                'Access-Control-Allow-Headers',
                'X-Requested-With, Content-Type, Accept, Origin, Authorization'
            )
            ->withHeader(
                'Access-Control-Allow-Methods',
                'GET, POST, PUT, DELETE, PATCH, OPTIONS'
            );
    }
}

