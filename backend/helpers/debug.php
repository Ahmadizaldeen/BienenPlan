<?php
function dd(... $data)
{
    if (($_ENV['APP_ENV'] ?? '') === 'local') {
    echo "<pre><br>";
    foreach ($data as $item) {
        var_dump($item);
        echo "\n-----------------\n";
    }

    exit;
    }
}