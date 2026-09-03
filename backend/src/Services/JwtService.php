<?php
namespace BienenPlan\Services;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

class JwtService {
    
   public function __construct(private string $secret = '' ) {
        $this->secret = $_ENV['JWT_SECRET'] ?? '';
    }

    public function generateToken(int $userId, string $email): string {
        $payload = [
            'iss' => 'bienenplan-api', # Issuer
            'sub' => $userId, # Subjekt (User-ID)
            'email' => $email, # Email des Benutzer
            'iat' => time(), # Ausstellungszeitpunkt
            'exp' => time() + (86400 * 7) # Ablaufzeitpunkt 7 Tage
        ];

        return JWT::encode($payload, $this->secret, 'HS256');
    }

    public function validateToken(string $token): ?object {
        try {
            return JWT::decode($token, new Key($this->secret, 'HS256'));
        } catch (\Exception $e) {
            throw new \Exception('Ungültiges oder abgelaufenes Token: ' . $e->getMessage());
            return null;
        }
    }
}