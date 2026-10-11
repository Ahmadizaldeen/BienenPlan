<?php

namespace BienenPlan\Controllers;

use BienenPlan\Services\ChangeFeed;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class ChangeController {
    public function __construct(private ChangeFeed $feed) {}

    public function poll(Request $request, Response $response): Response {
        $query = $request->getQueryParams();
        $after = $query['after'] ?? null;
        $status = 200;
        try {
            if (array_key_exists('after', $query) && (!is_string($after) || $after === '')) {
                throw new \InvalidArgumentException('Ungueltiger Aenderungs-Cursor');
            }
            $data = $this->feed->poll((int) $request->getAttribute('user_id'), $after);
        } catch (\InvalidArgumentException $exception) {
            $data = ['error' => $exception->getMessage()];
            $status = 400;
        } catch (\DomainException $exception) {
            $data = ['error' => $exception->getMessage()];
            $status = 401;
        }
        $response->getBody()->write(json_encode($data, JSON_THROW_ON_ERROR));
        return $response->withHeader('Content-Type', 'application/json')
            ->withHeader('Cache-Control', 'no-store')->withStatus($status);
    }
}
