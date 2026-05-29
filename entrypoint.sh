#!/bin/bash
# 云注入 Railway 启动脚本

set -e

DB_HOST="${DB_HOST:-127.0.0.1}"
DB_PORT="${DB_PORT:-3306}"
DB_NAME="${DB_NAME:-yunzhuru}"
DB_USER="${DB_USER:-root}"
DB_PASS="${DB_PASS:-Yyf@Mysql2026!}"

# ========== 等待 MySQL 就绪 ==========
echo "[entrypoint] 等待 MySQL 就绪..."
for i in $(seq 1 30); do
    if php -r "try { new PDO('mysql:host=${DB_HOST};port=${DB_PORT}', '${DB_USER}', '${DB_PASS}'); echo 'ok'; } catch(Exception \$e) { exit(1); }" 2>/dev/null; then
        echo "[entrypoint] MySQL 已就绪"
        break
    fi
    echo "[entrypoint] 等待中... ($i/30)"
    sleep 2
done

# ========== 生成数据库配置文件 ==========
echo "[entrypoint] 生成 config/db.php..."
cat > /var/www/html/config/db.php <<EOPHP
<?php
\$port = ${DB_PORT};
\$dsn = "mysql:host=${DB_HOST};port=\${port};dbname=${DB_NAME};charset=utf8mb4";
\$username = '${DB_USER}';
\$password = '${DB_PASS}';
try {
    \$pdo = new PDO(\$dsn, \$username, \$password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException \$e) {
    echo json_encode(['code' => 500, 'message' => 'DB error: ' . \$e->getMessage()]);
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
