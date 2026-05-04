<?php
/**
 * Redis 统一连接配置
 * Docker环境使用容器名 'redis'，本地开发环境可通过环境变量 REDIS_HOST 覆盖为 '127.0.0.1'
 */
if (!defined('REDIS_HOST')) {
    define('REDIS_HOST', getenv('REDIS_HOST') ?: '127.0.0.1');
}
if (!defined('REDIS_PORT')) {
    define('REDIS_PORT', 6379);
}
if (!defined('REDIS_TIMEOUT')) {
    define('REDIS_TIMEOUT', 1.0);
}

/**
 * 获取Redis连接
 * @param int $db 数据库编号，默认0
 * @return Redis
 */
function getRedisConnection($db = 0) {
    $redis = new Redis();
    $redis->connect(REDIS_HOST, REDIS_PORT, REDIS_TIMEOUT);
    if ($db > 0) {
        $redis->select($db);
    }
    return $redis;
}
