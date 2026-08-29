#!/bin/bash
# 云注入 Railway 启动脚本

set -e

DB_HOST="${DB_HOST:-127.0.0.1}"
DB_PORT="${DB_PORT:-3306}"
DB_NAME="${DB_NAME:-yunzhuru}"
DB_USER="${DB_USER:-root}"
PORT="${PORT:-8080}"
export DB_HOST DB_PORT DB_NAME DB_USER DB_PASS PORT
# 生产数据库密码必须由 Railway 注入；不再提供仓库内的默认密码。
if [ -z "${DB_PASS:-}" ]; then
    echo "[entrypoint] 缺少 DB_PASS，停止启动" >&2
    exit 1
fi

# ========== 等待 MySQL 就绪 ==========
echo "[entrypoint] 等待 MySQL 就绪..."
db_ready=0
for i in $(seq 1 30); do
    if php -r 'try { $dsn = "mysql:host=" . getenv("DB_HOST") . ";port=" . getenv("DB_PORT") . ";dbname=" . getenv("DB_NAME"); new PDO($dsn, getenv("DB_USER"), getenv("DB_PASS")); echo "ok"; } catch (Throwable $e) { exit(1); }' 2>/dev/null; then
        db_ready=1
        echo "[entrypoint] MySQL 已就绪"
        break
    fi
    echo "[entrypoint] 等待中... ($i/30)"
    sleep 2
done
if [ "$db_ready" -ne 1 ]; then
    echo "[entrypoint] MySQL 在等待窗口内未就绪，触发容器重启" >&2
    exit 1
fi

# ========== 生成数据库配置文件 ==========
echo "[entrypoint] 生成 config/db.php..."
cat > /var/www/html/config/db.php <<'EOPHP'
<?php
// 运行时从环境变量读取连接信息，避免把密码展开到文件内容或日志。
$host = getenv('DB_HOST') ?: '127.0.0.1';
$port = intval(getenv('DB_PORT') ?: 3306);
$dbName = getenv('DB_NAME') ?: 'yunzhuru';
$dsn = "mysql:host={$host};port={$port};dbname={$dbName};charset=utf8mb4";
$username = getenv('DB_USER') ?: 'root';
$password = getenv('DB_PASS') ?: '';
try {
    $pdo = new PDO($dsn, $username, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    echo json_encode(['code' => 500, 'message' => 'DB error: ' . $e->getMessage()]);
    exit;
}
EOPHP

# ========== 确保目录权限 ==========
mkdir -p /var/www/html/uploads /var/www/html/temp /var/log/supervisor
chmod -R 777 /var/www/html/temp

# ========== 持久化目录（软链接到 uploads 卷）==========
for dir in icon release signfile templates; do
    mkdir -p /var/www/html/uploads/$dir
    if [ -d "/var/www/html/$dir" ] && [ ! -L "/var/www/html/$dir" ]; then
        if [ "$dir" = "templates" ]; then
            # 内置壳模板属于代码发布产物，同名文件必须随新镜像覆盖到持久化卷。
            cp -rf /var/www/html/$dir/* /var/www/html/uploads/$dir/ 2>/dev/null || true
            echo "[entrypoint] 内置 templates 已同步到持久化目录"
        else
            # 用户上传和产物目录只补齐缺失文件，避免部署覆盖用户数据。
            cp -rn /var/www/html/$dir/* /var/www/html/uploads/$dir/ 2>/dev/null || true
        fi
        rm -rf /var/www/html/$dir
    fi
    if [ ! -L "/var/www/html/$dir" ]; then
        ln -sf /var/www/html/uploads/$dir /var/www/html/$dir
    fi
done
echo "[entrypoint] 持久化目录软链接完成"

# ========== 启动 supervisord ==========
echo "[entrypoint] 启动 supervisord..."
exec /usr/bin/supervisord -c /etc/supervisor/conf.d/supervisord.conf
