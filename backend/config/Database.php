<?php

abstract class Database{
        private static ?PDO $instance = null;

    public static function getConnection(): PDO { // singleton pattern
        if (self::$instance === null) { 
            $host = $_ENV['DB_HOST'] ;
            $port = $_ENV['DB_PORT'] ;
            $db   = $_ENV['DB_NAME'] ;
            $user = $_ENV['DB_USER'] ;
            $pass = $_ENV['DB_PASS'] ;

            $dsn = "mysql:host=$host;port=$port;dbname=$db;";
            
            $options = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION, 
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC 
            ];

            try {
                self::$instance = new PDO($dsn, $user, $pass, $options);
            } catch (PDOException $e) {
                Response::json(['error' => 'Datenbankverbindung fehlgeschlagen: ' ], 500);
            }
        }
        return self::$instance;
    }
}
