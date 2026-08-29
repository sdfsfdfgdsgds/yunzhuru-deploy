<?php
return [
    'host'     => getenv('DB_HOST') ?: '127.0.0.1',
    'port'     => intval(getenv('DB_PORT') ?: 3306),
    'dbname'   => getenv('DB_NAME') ?: 'yunzhuru',
    'username' => getenv('DB_USER') ?: 'root',
    // 密码只从 Railway 环境变量读取，仓库不保留可用回退值。
    'password' => getenv('DB_PASS') ?: '',
    'charset'  => 'utf8mb4',
];
