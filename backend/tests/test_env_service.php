<?php
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../src/Services/Env.php';
use BienenPlan\Services\Env;
Env::load();
print_r($_ENV['DB_NAME'] );
print_r($_ENV);
