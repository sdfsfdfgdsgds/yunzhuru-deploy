<?php
return [
    'host'     => getenv('DB_HOST') ?: '127.0.0.1',
    'port'     => intval(getenv('DB_PORT') ?: 3306),
    'dbname'   => 'yunzhuru',
    'username' => getenv('DB_USER') ?: 'root',
    'password' => getenv('DB_PASS') ?: 'Yyf@Mysql2026!',
    'charset'  => 'utf8mb4',
];
