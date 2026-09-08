<?php
/**
 * 配置小对象 PUT 的有界瞬态重试策略。
 *
 * 只重试同一份已经生成的密文，不重新构造业务配置，不持有或变更任务状态。
 * 每次真实上传前由调用方重新验证应用/复用状态；状态失效立即交回既有
 * 快照重建或删除回滚逻辑。大文件、桶探测、对象删除和列举不使用此策略。
 */

if (!function_exists('configUploadRetryIsTransient')) {
    /** 仅允许明确的临时传输错误和临时服务错误，不依据可变的错误文本判断。 */
    function configUploadRetryIsTransient(array $result): bool
    {
        $errno = (int)($result['curl_errno'] ?? 0);
        $httpCode = (int)($result['http_code'] ?? 0);
        if ((int)($result['code'] ?? 500) === 200 && $errno === 0) return false;
        // libcurl 稳定错误码：代理/DNS 解析、连接、传输截断、超时、空响应、
        // 发送/接收失败及 HTTP/2 流错误。证书、URL 和鉴权错误均不在白名单。
        $networkErrors = [5, 6, 7, 18, 28, 52, 55, 56, 92];
        if ($errno !== 0 && !in_array($errno, $networkErrors, true)) return false;
        if (in_array($httpCode, [408, 429, 500, 502, 503, 504], true)) return true;
        // 400/401/403/404 等已明确拒绝请求的响应不重复发送；收到 2xx 头后
        // 发生传输截断仍可重试同一密文，让最后写入内容保持幂等。
        if ($httpCode !== 0 && ($httpCode < 200 || $httpCode >= 300)) return false;
        return in_array($errno, $networkErrors, true);
    }
}

if (!function_exists('configUploadRetryPut')) {
    /**
     * 最多尝试三次配置 PUT，两次重试分别等待 0.5 秒和 1 秒。
     *
     * put 必须复用相同 key、密文和对象头；validate 每次尝试前返回 null
     * 表示继续，或返回 code=409/410 的状态结果表示停止旧快照。sleep 接收
     * 微秒数，可由隔离测试替换。attempts 是实际请求次数，elapsed_ms 只累计
     * 请求耗时而不包含退避；curl_errno 始终保留最后一次真实请求的错误码。
     */
    function configUploadRetryPut(callable $put, callable $validate, ?callable $sleep = null): array
    {
        $sleep = $sleep ?: static function (int $microseconds): void { usleep($microseconds); };
        $attempts = 0;
        $elapsedMs = 0;
        $lastErrno = 0;
        $result = ['code' => 500, 'message' => '配置对象尚未上传'];
        while ($attempts < 3) {
            // 在退避之后、下一次 PUT 之前复查，而不是只在最初生成密文时检查。
            $invalidState = $validate();
            if (is_array($invalidState)) {
                $invalidState['state_changed'] = true;
                $invalidState['attempts'] = $attempts;
                $invalidState['curl_errno'] = $lastErrno;
                $invalidState['elapsed_ms'] = $elapsedMs;
                return $invalidState;
            }
            $result = $put();
            $attempts++;
            $elapsedMs += max(0, (int)($result['elapsed_ms'] ?? 0));
            $lastErrno = (int)($result['curl_errno'] ?? 0);
            if ($attempts >= 3 || !configUploadRetryIsTransient($result)) break;
            $sleep($attempts === 1 ? 500000 : 1000000);
        }
        $result['attempts'] = $attempts;
        $result['curl_errno'] = $lastErrno;
        $result['elapsed_ms'] = $elapsedMs;
        return $result;
    }
}
