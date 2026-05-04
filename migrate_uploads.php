<?php
/**
 * 从 CTG 下载 uploads 备份并解压到持久卷
 * 一次性使用，用完删除
 */
set_time_limit(0);
ignore_user_abort(true);
header('Content-Type: text/plain; charset=utf-8');

$url = 'http://143.92.40.177/tmp-dl/uploads_backup.tar.gz';
$dest = '/tmp/uploads_backup.tar.gz';
$extractTo = '/var/www/html/uploads/';

echo "开始下载: $url\n";
ob_flush(); flush();

// 用 curl 命令下载（支持大文件）
$cmd = "curl -s -o " . escapeshellarg($dest) . " " . escapeshellarg($url) . " 2>&1";
$output = shell_exec($cmd);
echo "curl 输出: $output\n";

if (!file_exists($dest)) {
    echo "下载失败！\n";
    exit;
}

$size = filesize($dest);
echo "下载完成: " . round($size / 1024 / 1024 / 1024, 2) . " GB\n";
ob_flush(); flush();

if ($size < 1000000) {
    echo "文件太小，可能下载失败。内容:\n";
    echo file_get_contents($dest);
    exit;
}

echo "开始解压到 $extractTo ...\n";
ob_flush(); flush();

$cmd2 = "tar xzf " . escapeshellarg($dest) . " -C " . escapeshellarg($extractTo) . " 2>&1";
$output2 = shell_exec($cmd2);
echo "解压输出: $output2\n";

// 统计
$count = trim(shell_exec("ls " . escapeshellarg($extractTo) . " | wc -l"));
echo "解压完成！文件数: $count\n";

// 清理
unlink($dest);
echo "临时文件已清理\n";
