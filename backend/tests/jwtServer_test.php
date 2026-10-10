<?php

use BienenPlan\Services\JwtService;
require_once __DIR__ .'/../bootstrap.php';

require_once __DIR__ . "/../src/Services/JwtService.php";
$jwtService = new JwtService($_ENV['JWT_SECRET']);
$userId = 10;
$email = "jwt@server.test";
$token= $jwtService->generateToken($userId,$email);
#dd($jwtService);
$decoded = $jwtService->validateToken($token);
$Expierd_token = "eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJiaWVuZW5wbGFuLWFwaSIsInN1YiI6MTAsImVtYWlsIjoiand0QHNlcnZlci50ZXN0IiwiaWF0IjoxNzg4NzE5MzgxLCJleHAiOjE3ODg3MTkzODh9.F5pBonzTdKMT_RDeJ8lwzLmSDccmUTCHZsOoJniCh0E";
$invalid_token ="xxxxxxxxxxxxx";
try{
    dd($jwtService->validateToken($invalid_token));
}
catch(Exception $e){
    echo $e->getMessage(); # Wrong number of segments, Expired token
}

#dd($decoded);
dd($token);

