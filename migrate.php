<?php
/**
 * 临时数据迁移脚本 — 从 CTG 服务器拉取数据库和文件
 * 用完后删除此文件
 * 访问：/migrate.php?action=db|uploads|status&key=YunZhuRu2026
 */

set_time_limit(0);
ini_set('memory_limit', '512M');

$SECRET_KEY = 'YunZhuRu2026';

// 支持 CLI 模式（后台进程调用）
if (php_sapi_name() === 'cli' && isset($argv[1])) {
    parse_str($argv[1], $_GET);
}

if (($_GET['key'] ?? '') !== $SECRET_KEY) {
    http_response_code(403);
    die('Forbidden');
}

$action = $_GET['action'] ?? 'status';
$CTG_HOST = $_GET['host'] ?? '143.92.40.180'; // 默认用 CloudFront 回源 IP

header('Content-Type: text/plain; charset=utf-8');

switch ($action) {
    case 'status':
        echo "=== 迁移状态 ===\n";
        echo "DB: " . (file_exists('/tmp/db_imported') ? '已导入' : '未导入') . "\n";
        echo "磁盘: " . shell_exec('df -h /var/www/html/uploads 2>&1') . "\n";
        echo "uploads 文件数: " . trim(shell_exec('find /var/www/html/uploads -type f 2>/dev/null | wc -l')) . "\n";
        echo "MariaDB 表数: ";
        try {
            require_once __DIR__ . '/config/db.php';
            $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
            echo count($tables) . "\n";
            // 显示几个关键表的行数
            foreach (['cainiao_apk', 'cainiao_user', 'cainiao_inject_task'] as $t) {
                if (in_array($t, $tables)) {
                    $count = $pdo->query("SELECT COUNT(*) FROM $t")->fetchColumn();
                    echo "  $t: $count 行\n";
                }
            }
        } catch (Exception $e) {
            echo "错误: " . $e->getMessage() . "\n";
        }
        break;

    case 'db':
        // 后台模式：fork 独立 PHP 进程执行，HTTP 立即返回
        $logFile = '/tmp/migrate_db.log';
        if (isset($_GET['bg'])) {
            $params = http_build_query(['key' => $SECRET_KEY, 'action' => 'db_exec', 'host' => $CTG_HOST, 'sql_file' => $_GET['sql_file'] ?? 'yunzhuru_backup_m1g.sql.gz']);
            shell_exec("nohup php /var/www/html/migrate.php '$params' > $logFile 2>&1 &");
            echo "=== 数据库导入已在后台启动 ===\n";
            echo "查看进度: migrate.php?key=...&action=db_log\n";
            break;
        }
        // fall through

    case 'db_log':
        if ($action === 'db_log') {
            if (file_exists('/tmp/migrate_db.log')) {
                echo file_get_contents('/tmp/migrate_db.log');
            } else {
                echo "无导入日志\n";
            }
            break;
        }

    case 'db_exec':
        echo "=== 开始导入数据库 ===\n";
        flush();

        // 1. 下载 SQL
        $sqlGzPath = '/tmp/yunzhuru_backup.sql.gz';
        $sqlPath = '/tmp/yunzhuru_backup.sql';
        $url = "http://{$CTG_HOST}/" . ($_GET['sql_file'] ?? 'yunzhuru_backup_m1g.sql.gz');

        echo "下载 SQL: $url\n";
        flush();

        $ch = curl_init($url);
        $fp = fopen($sqlGzPath, 'w');
        curl_setopt_array($ch, [
            CURLOPT_FILE => $fp,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 600,
        ]);
        $ok = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $size = curl_getinfo($ch, CURLINFO_SIZE_DOWNLOAD);
        curl_close($ch);
        fclose($fp);

        echo "下载完成: HTTP $httpCode, 大小 " . round($size / 1024 / 1024) . "MB\n";
        flush();

        if ($httpCode !== 200 || $size < 1000) {
            die("下载失败\n");
        }

        // 2. 解压
        echo "解压中...\n";
        flush();
        shell_exec("gunzip -f $sqlGzPath");

        if (!file_exists($sqlPath)) {
            die("解压失败\n");
        }
        echo "解压完成: " . round(filesize($sqlPath) / 1024 / 1024) . "MB\n";
        flush();

        // 3. 导入 MariaDB（用 mariadb 命令行客户端，流式导入不占内存）
        echo "导入 MariaDB...\n";
        flush();

        $dbHost = getenv('DB_HOST') ?: '127.0.0.1';
        $dbPort = getenv('DB_PORT') ?: '3306';
        $dbUser = getenv('DB_USER') ?: 'root';
        $dbPass = getenv('DB_PASS') ?: 'Yyf@Mysql2026!';
        $dbName = getenv('DB_NAME') ?: 'yunzhuru';

        $cmd = "mariadb -h $dbHost -P $dbPort -u $dbUser -p'$dbPass' $dbName < $sqlPath 2>&1";
        $output = shell_exec($cmd);
        echo "导入输出: " . ($output ?: '(无输出，导入成功)') . "\n";

        // 验证
        try {
            require_once __DIR__ . '/config/db.php';
            $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
            echo "导入完成，共 " . count($tables) . " 张表\n";
            $apkCount = $pdo->query("SELECT COUNT(*) FROM cainiao_apk")->fetchColumn();
            echo "cainiao_apk: $apkCount 行\n";
            file_put_contents('/tmp/db_imported', date('Y-m-d H:i:s'));
        } catch (Exception $e) {
            echo "验证失败: " . $e->getMessage() . "\n";
        }

        // 清理
        @unlink($sqlPath);
        echo "清理完成\n";
        break;

    case 'uploads':
        if (isset($_GET['bg'])) {
            $params = http_build_query(['key' => $SECRET_KEY, 'action' => 'uploads', 'host' => $CTG_HOST]);
            shell_exec("nohup php /var/www/html/migrate.php '$params' > /tmp/migrate_uploads.log 2>&1 &");
            echo "uploads 同步已在后台启动\n查看进度: migrate.php?key=...&action=uploads_log\n";
            break;
        }
        echo "=== 同步 uploads 目录 ===\n";
        flush();

        // 获取 CTG 上的文件列表
        require_once __DIR__ . '/config/db.php';

        // 从数据库获取所有 APK 的路径
        $stmt = $pdo->query("SELECT id, name, path FROM cainiao_apk WHERE path != '' ORDER BY id");
        $apks = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo "共 " . count($apks) . " 个应用需要同步\n";
        flush();

        $success = 0;
        $fail = 0;
        $skip = 0;

        foreach ($apks as $apk) {
            $localPath = '/var/www/html/uploads/' . ltrim($apk['path'], '/');
            $dir = dirname($localPath);

            // 已存在则跳过
            if (file_exists($localPath)) {
                $skip++;
                continue;
            }

            // 从 CTG 下载
            $url = "http://{$CTG_HOST}/uploads/" . ltrim($apk['path'], '/');

            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }

            $ch = curl_init($url);
            $fp = fopen($localPath, 'w');
            curl_setopt_array($ch, [
                CURLOPT_FILE => $fp,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_TIMEOUT => 300,
            ]);
            curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $size = curl_getinfo($ch, CURLINFO_SIZE_DOWNLOAD);
            curl_close($ch);
            fclose($fp);

            if ($httpCode === 200 && $size > 0) {
                $success++;
                echo "OK [{$apk['id']}] {$apk['name']} (" . round($size / 1024 / 1024, 1) . "MB)\n";
            } else {
                $fail++;
                @unlink($localPath);
                echo "FAIL [{$apk['id']}] {$apk['name']} (HTTP $httpCode)\n";
            }
            flush();
        }

        echo "\n完成: 成功=$success, 失败=$fail, 跳过=$skip\n";
        break;

    case 'release':
        if (isset($_GET['bg'])) {
            $params = http_build_query(['key' => $SECRET_KEY, 'action' => 'release', 'host' => $CTG_HOST]);
            shell_exec("nohup php /var/www/html/migrate.php '$params' > /tmp/migrate_release.log 2>&1 &");
            echo "release 同步已在后台启动\n查看进度: migrate.php?key=...&action=release_log\n";
            break;
        }
        echo "=== 同步 release 目录 ===\n";
        flush();

        require_once __DIR__ . '/config/db.php';

        // 从注入任务表获取已完成的 APK 路径
        $stmt = $pdo->query("SELECT DISTINCT injected_apk FROM cainiao_inject_task WHERE status_text = '编译成功' AND injected_apk IS NOT NULL AND injected_apk != ''");
        $paths = $stmt->fetchAll(PDO::FETCH_COLUMN);
        echo "共 " . count($paths) . " 个注入结果需要同步\n";
        flush();

        $success = 0;
        $fail = 0;
        $skip = 0;

        foreach ($paths as $path) {
            $localPath = '/var/www/html/release/' . ltrim($path, '/');
            $dir = dirname($localPath);

            if (file_exists($localPath)) {
                $skip++;
                continue;
            }

            $url = "http://{$CTG_HOST}/release/" . ltrim($path, '/');

            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }

            $ch = curl_init($url);
            $fp = fopen($localPath, 'w');
            curl_setopt_array($ch, [
                CURLOPT_FILE => $fp,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_TIMEOUT => 300,
            ]);
            curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $size = curl_getinfo($ch, CURLINFO_SIZE_DOWNLOAD);
            curl_close($ch);
            fclose($fp);

            if ($httpCode === 200 && $size > 0) {
                $success++;
                echo "OK " . basename($path) . " (" . round($size / 1024 / 1024, 1) . "MB)\n";
            } else {
                $fail++;
                @unlink($localPath);
                echo "FAIL " . basename($path) . " (HTTP $httpCode)\n";
            }
            flush();
        }

        echo "\n完成: 成功=$success, 失败=$fail, 跳过=$skip\n";
        break;

    default:
        if ($action === 'uploads_log') {
            echo file_exists('/tmp/migrate_uploads.log') ? file_get_contents('/tmp/migrate_uploads.log') : "无日志\n";
            break;
        }
        if ($action === 'release_log') {
            echo file_exists('/tmp/migrate_release.log') ? file_get_contents('/tmp/migrate_release.log') : "无日志\n";
            break;
        }
        if ($action === 'icons') {
            echo "=== 同步图标 ===\n";
            flush();
            require_once __DIR__ . '/config/db.php';
            $stmt = $pdo->query("SELECT DISTINCT icon FROM cainiao_apk WHERE icon != '' AND icon != 'android.png'");
            $icons = $stmt->fetchAll(PDO::FETCH_COLUMN);
            echo "共 " . count($icons) . " 个图标需要同步\n";
            $success = 0; $fail = 0; $skip = 0;
            $iconDir = __DIR__ . '/icon/';
            if (!is_dir($iconDir)) mkdir($iconDir, 0755, true);
            foreach ($icons as $icon) {
                $localPath = $iconDir . $icon;
                if (file_exists($localPath)) { $skip++; continue; }
                $url = "http://{$CTG_HOST}/icon/" . urlencode($icon);
                $ch = curl_init($url);
                $fp = fopen($localPath, 'w');
                curl_setopt_array($ch, [CURLOPT_FILE => $fp, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 30]);
                curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch); fclose($fp);
                if ($httpCode === 200 && filesize($localPath) > 0) {
                    $success++;
                    echo "OK $icon\n";
                } else {
                    $fail++;
                    @unlink($localPath);
                    echo "FAIL $icon (HTTP $httpCode)\n";
                }
                flush();
            }
            echo "\n完成: 成功=$success, 失败=$fail, 跳过=$skip\n";
            break;
        }
        echo "未知操作: $action\n";
        echo "可用: status, db, uploads, release\n";
}
