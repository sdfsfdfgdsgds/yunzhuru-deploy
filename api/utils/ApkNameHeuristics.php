<?php

/**
 * 判断 APK 归档条目名称是否疑似乱码。
 *
 * 该启发式同时用于上传接口和注入 worker，集中在工具模块中，避免两处
 * 阈值处理漂移。$ascii 表示非 ASCII 字符比例阈值，最终范围固定在 0.4
 * 至 1 之间，以保持历史检测合同。
 */
function isGarbledApkName(string $name, float $ascii = 0.4): bool
{
    $ascii = max(0.4, min(1.0, $ascii));
    $totalLength = mb_strlen($name, 'UTF-8');
    if ($totalLength === false || $totalLength === 0) {
        return false;
    }

    $asciiCount = preg_match_all('/[\x20-\x7E]/', $name);
    $nonAsciiRatio = 1 - ($asciiCount / $totalLength);
    return $nonAsciiRatio > $ascii;
}
