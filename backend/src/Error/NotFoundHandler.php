<?php

namespace BienenPlan\Error;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Response as SlimResponse;
use Throwable;

class NotFoundHandler
{
    public function __invoke(
        ServerRequestInterface $request,
        Throwable $exception,
        bool $displayErrorDetails
    ): Response {

        $response = new SlimResponse();

        $data = [
            'error' => 'Not Found',
            'message' => 'The requested endpoint does not exist.',
            'path' => $request->getUri()->getPath(),
            'status' => 404
        ];

        $response->getBody()->write(
            json_encode($data)
        );

        return $response
            ->withStatus(404)
            ->withHeader('Content-Type', 'application/json');
    }
}