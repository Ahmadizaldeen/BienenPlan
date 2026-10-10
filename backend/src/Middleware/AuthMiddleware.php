<?php
namespace BienenPlan\Middleware;

use Psr\Http\Message\ServerRequestInterface as Request; # Eingehende HTTP-Anfrage
use Slim\Psr7\Response as SlimResponse; # Response erzeugen, bearbeiten und zurückgeben
use BienenPlan\Services\JwtService; # JWT prüfen/erzeugen
use BienenPlan\Models\User;
use Psr\Http\Message\ResponseInterface as Response; # Rückgabetyp einer HTTP-Antwort
use Psr\Http\Server\RequestHandlerInterface as RequestHandler; # Server-Request-Handler

class AuthMiddleware {

public function __construct(private JwtService $jwt_service, private User $users)
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

        } catch (\Exception $e) {
            $response = new SlimResponse();
            $response->getBody()->write(json_encode(['error' => 'Ungültiges oder abgelaufenes Token'])); # Stream in den Body schreiben 
            return $response->withHeader('Content-Type', 'application/json')->withStatus(401);
        }

        // Reload account state: an existing JWT must not preserve revoked admin rights.
        $userId = filter_var($decoded->sub ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $user = $userId === false ? false : $this->users->findById($userId);
        if (!$user) {
            $response = new SlimResponse();
            $response->getBody()->write(json_encode(['error' => 'Benutzer nicht mehr aktiv']));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(401);
        }
        $request = $request->withAttribute('user_id', (int) $user['id'])
            ->withAttribute('is_admin', (bool) $user['is_admin']);

        return $handler->handle($request); # Response an Controller weiterleiten ween token gültig ist
    }
}