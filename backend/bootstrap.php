<?php
# === load helper functions ===#
require_once __DIR__ . '/helpers/Response.php';

# === load debug functions ===#
require_once __DIR__ . '/helpers/debug.php';

#=== load environment variables ===#
require_once __DIR__ . '/vendor/autoload.php';

use Dotenv\Dotenv; # vlucas/phpdotenv package
use Dotenv\Exception\ValidationException;

$dotenv = Dotenv::createImmutable(__DIR__ . '/'); # load .env file
try{
    $dotenv->Load();
    $dotenv->required([ 'DB_HOST', 'DB_NAME', 'DB_USER', 'DB_PORT' ])->notEmpty();
}

catch(ValidationException $e){ // dotenv validation exception
    Response::json(['error' => 'Fehler beim Laden der Umgebungsvariablen: Ungültiger Wert'], 500);
}
catch(Exception $e){
    Response::json(['error' => 'Fehler beim Laden der Umgebungsvariablen'], 500);
}



