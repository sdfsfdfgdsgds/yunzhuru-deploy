<?php

/**
 * 读取服务日志末尾内容，统一控制读取上限并过滤不可见控制字符。
 *
 * @param string $logFile 日志文件路径
 * @param array $input API 输入参数，可选 length 字段
 * @return array{size:int,content:string}
 */
function readServiceLogTail(string $logFile, array $input): array
{
    if (!file_exists($logFile)) {
        throw new Exception('日志文件不存在');
    }

    $maxRead = 100 * 1024;
    $defaultRead = 20 * 1024;
    $readSize = isset($input['length']) ? (int)$input['length'] : $defaultRead;
    $readSize = max(1024, min($readSize, $maxRead));

    $fp = fopen($logFile, 'rb');
    if (!$fp) {
        throw new Exception('无法打开日志文件');
    }

    $bufferSize = 4096;
    $pos = -1;
    $buffer = '';
    $totalSize = 0;
    $lines = [];

    fseek($fp, 0, SEEK_END);
    $fileSize = ftell($fp);

    while ($fileSize + $pos > 0 && $totalSize < $readSize) {
        $seek = max(0, $fileSize + $pos - $bufferSize + 1);
        $readLen = $fileSize + $pos - $seek + 1;

        fseek($fp, $seek);
        $chunk = fread($fp, $readLen);
        $buffer = $chunk . $buffer;

        $pos -= $bufferSize;

        $parts = explode("\n", $buffer);
        $buffer = array_shift($parts);

        foreach (array_reverse($parts) as $line) {
            // 日志可能包含二进制残留，停止读取以避免破坏 JSON 响应。
            if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $line)) {
                fclose($fp);
                return [
                    'size' => $fileSize,
                    'content' => implode("\n", $lines),
                ];
            }

            $lineSize = strlen($line) + 1;
            if ($totalSize + $lineSize > $readSize) {
                break 2;
            }

            array_unshift($lines, $line);
            $totalSize += $lineSize;
        }
    }

    if ($buffer !== '' && $totalSize < $readSize
        && !preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $buffer)) {
        array_unshift($lines, $buffer);
    }

    fclose($fp);

    return [
        'size' => $fileSize,
        'content' => implode("\n", $lines),
    ];
}

/** 清空服务日志文件，并保留原有错误语义。 */
function clearServiceLog(string $logFile): array
{
    if (!file_exists($logFile)) {
        throw new Exception('日志文件不存在');
    }

    if (!is_writable($logFile)) {
        throw new Exception('日志文件不可写，无法清空');
    }

    $fp = fopen($logFile, 'c');
    if (!$fp) {
        throw new Exception('无法打开日志文件');
    }

    ftruncate($fp, 0);
    fclose($fp);

    return ['message' => '日志已清空'];
}
