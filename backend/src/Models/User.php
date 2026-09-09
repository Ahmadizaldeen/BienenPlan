<?php
namespace BienenPlan\Models;

use PDO;

class User {

    public function __construct(private PDO $db) {
        $this->db = $db;
    }

    public function create(string $name, string $email, string $password): array {
        $this->db->beginTransaction(); # insert user, personal group, membership (1 User:1 Group, owner)
        try {
            $userStatement = $this->db->prepare(
                "INSERT INTO users (name, email, password_hash) VALUES (:name, :email, :pass)"
            );
            $userStatement->execute([
                'name' => $name,
                'email' => $email,
                'pass' => password_hash($password, PASSWORD_DEFAULT)
            ]);

            $userId = (int) $this->db->lastInsertId();
            $groupStatement = $this->db->prepare(
                "INSERT INTO groups (name, personal_user_id) VALUES (:name, :personal_user_id)"
            );
            $groupStatement->execute([
                'name' => 'Personal user ' . $userId,
                'personal_user_id' => $userId
            ]);

            $personalGroupId = (int) $this->db->lastInsertId();
            $membershipStatement = $this->db->prepare(
                "INSERT INTO users_groups (user_id, groups_id, role) VALUES (:user_id, :groups_id, 'owner')"
            );
            $membershipStatement->execute([
                'user_id' => $userId,
                'groups_id' => $personalGroupId
            ]);

            $this->db->commit();

            return [
                'user_id' => $userId,
                'personal_group_id' => $personalGroupId
            ];
        } catch (\Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $exception;
        }
    }

    public function findByEmail(string $email): array|false {
        $stmt = $this->db->prepare("SELECT * FROM users WHERE email = :email AND deleted_at IS NULL");
        $stmt->execute(['email' => $email]);
        return $stmt->fetch();
    }

    public function set_picture(int $user_id, string $url) {

    }
}