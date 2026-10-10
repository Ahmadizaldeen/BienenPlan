<?php require_once __DIR__.'/../bootstrap.php';
require_once __DIR__ .'/../config/Database.php';
$db = Database::getConnection(); # exit were 

echo "Datenbankverbindung erfolgreich!";

$db = Database::getConnection();
$stmt = $db->prepare(
            'Show tables '
        );
        $stmt->execute();
        $result = $stmt->fetchAll();

dd($result);