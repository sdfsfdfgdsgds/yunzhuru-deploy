<?php
/**
 * 从 CTG 下载 uploads 备份并解压到持久卷
 * 后台执行，立即返回
 */
header('Content-Type: application/json');

$logFile = '/tmp/migrate_uploads.log';
$lockFile = '/tmp/migrate_uploads.lock';

// 检查进度
if (isset($_GET['status'])) {
    $result = ['running' => file_exists($lockFile)];
    if (file_exists($logFile)) {
        $result['log'] = file_get_contents($logFile);
    }
    $count = trim(shell_exec("ls /var/www/html/uploads/ 2>/dev/null | wc -l"));
    $result['file_count'] = (int)$count;
    echo json_encode($result, JSON_UNESCAPED_UNICODE);
    exit;
}

// 防止重复执行
if (file_exists($lockFile)) {
    echo json_encode(['message' => '迁移正在进行中，访问 ?status 查看进度']);
    exit;
}

// 后台执行下载+解压
$script = <<<'BASH'
#!/bin/bash
LOG=/tmp/migrate_uploads.log
LOCK=/tmp/migrate_uploads.lock
touch $LOCK
echo "[$(date)] 开始下载..." > $LOG
curl -s -o /tmp/uploads_backup.tar.gz "http://143.92.40.177/tmp-dl/uploads_backup.tar.gz" 2>&1
SIZE=$(stat -c%s /tmp/uploads_backup.tar.gz 2>/dev/null || echo 0)
echo "[$(date)] 下载完成: ${SIZE} 字节" >> $LOG
if [ "$SIZE" -lt 1000000 ]; then
    echo "[$(date)] 文件太小，下载失败" >> $LOG
    rm -f $LOCK
    exit 1
fi
echo "[$(date)] 开始解压..." >> $LOG
tar xzf /tmp/uploads_backup.tar.gz -C /var/www/html/uploads/ 2>&1 >> $LOG
COUNT=$(ls /var/www/html/uploads/ | wc -l)
echo "[$(date)] 解压完成！文件数: $COUNT" >> $LOG
rm -f /tmp/uploads_backup.tar.gz
echo "[$(date)] 临时文件已清理，迁移完成" >> $LOG
rm -f $LOCK
BASH;

file_put_contents('/tmp/migrate.sh', $script);
chmod('/tmp/migrate.sh', 0755);
exec('bash /tmp/migrate.sh > /dev/null 2>&1 &');

echo json_encode(['message' => '迁移已在后台启动，访问 ?status 查看进度'], JSON_UNESCAPED_UNICODE);
