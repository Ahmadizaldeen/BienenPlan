<?php
class User {
    private ?int $id = null;
    private string $name;
    private string $email;
    private string $password_hash;
    private ?string $picture;
    private ?PDO $db = null;


    public function __construct(string $name, string $email, string $password_hash, ?string $picture = null) {
        $this->db = Database::getConnection();
        $this->name = $name;
        $this->email = $email;
        $this->password_hash = $password_hash;
        $this->picture = $picture;
    }

    public function create(): bool {
        $stmt = $this->db->prepare('INSERT INTO users (name,email, password_hash,picture) VALUES (?,?,?,?)');
        $stmt->execute([$this->name, $this->email, $this->password_hash, $this->picture]);
        $this->id = $this->db->lastInsertID();
        return $this->id !== null;
    }

    public static function findById(int $id): ?User  {
        $db = Database::getConnection();
        $stmt = $db->prepare('SELECT * FROM users WHERE id = ? AND deleted_at IS NULL');
        $stmt->execute([$id]);

        $result = $stmt->fetch();
        $user = new User($result['name'], $result['email'], $result['password_hash'], $result['picture']);
        return $user ?: null;
    }
}