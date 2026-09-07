<?php
namespace BienenPlan\Middleware;

use Psr\Http\Message\ServerRequestInterface as Request; # Eingehende HTTP-Anfrage
use Slim\Psr7\Response as SlimResponse; # Response erzeugen, bearbeiten und zurückgeben
use BienenPlan\Services\JwtService; # JWT prüfen/erzeugen
use Psr\Http\Message\ResponseInterface as Response; # Rückgabetyp einer HTTP-Antwort
use Psr\Http\Server\RequestHandlerInterface as RequestHandler; # Server-Request-Handler

class AuthMiddleware {

public function __construct(private JwtService $jwt_service)
{
    $this->jwt_service = $jwt_service;
}

    public function __invoke(Request $request, RequestHandler $handler): Response {
        $authHeader = $request->getHeaderLine('Authorization'); # 
        
        if (!$authHeader || !preg_match('/Bearer\s(\S+)/', $authHeader, $matches)) { # regex prüft, ob der Header im Format "Bearer <token>" vorliegt
            $response = new SlimResponse();
            $response->getBody()->write(json_encode(['error' => 'Nicht autorisiert']));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(401);
        }

        $jwt = $matches[1]; # token aus dem Header extrahieren

        try {
            $decoded = $this->jwt_service->validateToken($jwt);
            // User-ID aus dem Token im Request speichern
            $request = $request->withAttribute('user_id', $decoded->sub);

        } catch (\Exception $e) {
            $response = new SlimResponse();
            $response->getBody()->write(json_encode(['error' => 'Ungültiges oder abgelaufenes Token'])); # Stream in den Body schreiben 
            return $response->withHeader('Content-Type', 'application/json')->withStatus(401);
        }

        return $handler->handle($request); # Response an Controller weiterleiten ween token gültig ist
    }
}