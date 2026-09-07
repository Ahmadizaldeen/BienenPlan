<?php
namespace BienenPlan\Models;

use PDO;

class User {

    public function __construct(private PDO $db) {
        $this->db = $db;
    }

    public function create(string $name, string $email, string $password): bool {
        $stmt = $this->db->prepare("INSERT INTO users (name, email, password_hash) VALUES (:name, :email, :pass)");
        return $stmt->execute([
            'name' => $name,
            'email' => $email,
            'pass' => password_hash($password, PASSWORD_DEFAULT)
        ]);
    }

    public function findByEmail(string $email): array|false {
        $stmt = $this->db->prepare("SELECT * FROM users WHERE email = :email AND deleted_at IS NULL");
        $stmt->execute(['email' => $email]);
        return $stmt->fetch();
    }

    public function set_picture(int $user_id, string $url) {

    }
}