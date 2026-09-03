<?php
/**
 * 一次性私有制品下载入口。
 *
 * 入口只接受 API 签发的短期令牌，不接受真实文件路径。
 * 令牌在首次 GET 时原子消费，从而同时覆盖 Android 和桌面端的下载链路。
 */

header('Cache-Control: no-store, private');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    http_response_code(405);
    header('Allow: GET');
    exit;
}

require_once __DIR__ . '/config/redis.php';
require_once __DIR__ . '/api/utils/PrivateDownload.php';

$token = (string)($_GET['token'] ?? '');
try {
    $metadata = consumePrivateDownloadToken($token);
    if ($metadata === null || empty($metadata['path'])) {
        http_response_code(404);
        exit;
    }

    $file = resolvePrivateDownloadFile((string)$metadata['path']);
    $downloadName = basename((string)($metadata['name'] ?? $file['name']));
    if ($downloadName === '' || $downloadName === '.' || $downloadName === '..') {
        $downloadName = $file['name'];
    }

    header('Content-Type: application/octet-stream');
    header('Content-Length: ' . filesize($file['full_path']));
    header("Content-Disposition: attachment; filename*=UTF-8''" . rawurlencode($downloadName));
    readfile($file['full_path']);
} catch (Throwable $error) {
    http_response_code(404);
}
