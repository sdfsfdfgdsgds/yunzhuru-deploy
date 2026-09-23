<?php

/**
 * 生成一个弹窗最近两天的链接小时统计。
 *
 * 统计只纳入“打开链接”（click_type=1）且目标文本非空的点击事件；
 * 小时按北京时间 0-23 点补齐，返回结构供图片弹窗和文字弹窗共用。
 * 可传入固定时刻验证跨日边界；正式调用默认读取当前时间。
 */
function popupClickHourlyStats(PDO $pdo, int $popupId, string $module, ?DateTimeImmutable $now = null): array
{
    $timezone = new DateTimeZone('Asia/Shanghai');
    $now = ($now ?? new DateTimeImmutable('now', $timezone))->setTimezone($timezone);
    $today = $now->setTime(0, 0, 0);
    $yesterday = $today->modify('-1 day');
    $tomorrow = $today->modify('+1 day');

    $start = $yesterday->getTimestamp();
    $end = $tomorrow->getTimestamp();

    // 日志由 MySQL NOW() 写入，先按相同数据库会话时区还原时刻，再转成北京时间。
    // 边界转回数据库时间，保留 created_at 范围索引；链接按字节区分路径大小写。
    $stmt = $pdo->prepare(<<<'SQL'
        SELECT
            click_text,
            DATE(click_time) AS stat_date,
            HOUR(click_time) AS stat_hour,
            COUNT(*) AS click_count
        FROM (
            SELECT CAST(click_text AS BINARY) AS click_text,
                   TIMESTAMPADD(SECOND, UNIX_TIMESTAMP(created_at), '1970-01-01 08:00:00') AS click_time
            FROM cainiao_popup_stat_log
            WHERE popup_id = ?
              AND module = ?
              AND type = 'click'
              AND click_type = 1
              AND click_text <> ''
              AND created_at >= FROM_UNIXTIME(?)
              AND created_at < FROM_UNIXTIME(?)
        ) AS link_events
        GROUP BY click_text, DATE(click_time), HOUR(click_time)
        ORDER BY click_text, stat_date, stat_hour
    SQL);
    $stmt->execute([$popupId, $module, $start, $end]);

    $links = [];
    $rows = [];
    for ($hour = 0; $hour < 24; $hour++) {
        $rows[$hour] = [
            'hour' => $hour,
            'label' => sprintf('%02d:00', $hour),
            'yesterday' => [],
            'today' => [],
        ];
    }

    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $clickText = (string)$row['click_text'];
        // 同一弹窗内相同链接可能配置在多个按钮，按链接目标合并统计。
        $linkKey = hash('sha256', $clickText);
        if (!isset($links[$linkKey])) {
            $links[$linkKey] = [
                'key' => $linkKey,
                'click_text' => $clickText,
                'yesterday_total' => 0,
                'today_total' => 0,
            ];
        }

        $date = (string)$row['stat_date'];
        $dayKey = $date === $today->format('Y-m-d') ? 'today' : 'yesterday';
        $hour = max(0, min(23, (int)$row['stat_hour']));
        $count = (int)$row['click_count'];
        $rows[$hour][$dayKey][$linkKey] = $count;
        $links[$linkKey][$dayKey . '_total'] += $count;
    }

    $currentHour = (int)$now->format('G');
    foreach ($rows as &$row) {
        $row['today_available'] = $row['hour'] <= $currentHour;
        $row['today_current'] = $row['hour'] === $currentHour;
    }
    unset($row);

    return [
        'timezone' => 'Asia/Shanghai',
        'today' => $today->format('Y-m-d'),
        'yesterday' => $yesterday->format('Y-m-d'),
        'current_hour' => $currentHour,
        'links' => array_values($links),
        'rows' => array_values($rows),
    ];
}
