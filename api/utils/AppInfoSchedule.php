<?php
/**
 * 应用信息延迟生效计划。
 *
 * 每次保存独立记录待生效字段和基线。计划按字段冲突裁剪，保证等待期间的其它保存
 * 不会被旧快照覆盖；到期后只应用基线仍未变化的字段。
 */

require_once __DIR__ . '/Auth.php';
require_once __DIR__ . '/DownloadName.php';

if (!defined('APP_INFO_SCHEDULE_MAX_DELAY_SECONDS')) {
    define('APP_INFO_SCHEDULE_MAX_DELAY_SECONDS', 7 * 24 * 3600);
}

/** 确保旧库拥有多批次应用信息计划表。 */
function ensureAppInfoScheduleSchema(PDO $pdo): void
{
    static $checked = false;
    if ($checked) return;
    $pdo->exec("CREATE TABLE IF NOT EXISTS `cainiao_apk_info_schedule` (
        `schedule_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY COMMENT '计划 ID',
        `app_id` INT NOT NULL COMMENT '应用 ID',
        `user_id` INT NOT NULL DEFAULT 0 COMMENT '应用所属用户 ID',
        `payload_json` LONGTEXT NOT NULL COMMENT '字段差异和基线',
        `apply_at` DATETIME NOT NULL COMMENT 'UTC 生效时间',
        `status` VARCHAR(20) NOT NULL DEFAULT 'pending' COMMENT 'pending/applied/cancelled/failed',
        `created_at` DATETIME NOT NULL COMMENT '创建时间（UTC）',
        `updated_at` DATETIME NOT NULL COMMENT '更新时间（UTC）',
        `applied_at` DATETIME NULL COMMENT '实际应用时间（UTC）',
        `error_message` VARCHAR(500) NOT NULL DEFAULT '' COMMENT '失败原因',
        KEY `idx_app_info_schedule_app_status` (`app_id`, `status`, `apply_at`),
        KEY `idx_app_info_schedule_due` (`status`, `apply_at`),
        KEY `idx_app_info_schedule_user` (`user_id`, `status`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='应用信息延迟生效计划'");
    $checked = true;
}

/** 将 UTC DATETIME 转为前端可用的计划快照。 */
function appInfoScheduleSnapshot(array $row, ?DateTimeImmutable $now = null): array
{
    $now = $now ?: new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $applyAt = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', trim((string)($row['apply_at'] ?? '')), new DateTimeZone('UTC'));
    if (!$applyAt) return ['status' => (string)($row['status'] ?? 'pending'), 'apply_at' => '', 'remaining_seconds' => 0];
    return [
        'status' => (string)($row['status'] ?? 'pending'),
        'apply_at' => $applyAt->setTimezone(new DateTimeZone('Asia/Shanghai'))->format(DateTimeInterface::ATOM),
        'remaining_seconds' => max(0, $applyAt->getTimestamp() - $now->getTimestamp()),
    ];
}

/** 生成用户可读的延迟时间。 */
function appInfoScheduleFormatDelay(int $seconds): string
{
    $seconds = max(0, $seconds);
    if ($seconds >= 3600) return intdiv($seconds, 3600) . '小时' . (($seconds % 3600) >= 60 ? intdiv($seconds % 3600, 60) . '分钟' : '');
    if ($seconds >= 60) return intdiv($seconds, 60) . '分钟';
    return $seconds . '秒';
}

/** 统一应用信息值，避免数据库字符串和前端数字导致误判。 */
function appInfoScheduleNormalizeValues(array $values): array
{
    $out = [
        'name' => trim((string)($values['name'] ?? '')),
        'download_name_template' => normalizeDownloadNameTemplate($values['download_name_template'] ?? ''),
        'app_key' => trim((string)($values['app_key'] ?? '')),
        'is_reusable' => (int)($values['is_reusable'] ?? 0),
        'config_mode' => (int)($values['config_mode'] ?? 0),
        'reuse_apk_id' => ($values['reuse_apk_id'] ?? null) === null || ($values['reuse_apk_id'] ?? '') === '' ? null : (int)$values['reuse_apk_id'],
        'domain_mode' => (int)($values['domain_mode'] ?? 0),
        'custom_domains' => ($values['custom_domains'] ?? null) === null ? null : (string)$values['custom_domains'],
        'reuse_options' => $values['reuse_options'] ?? [],
    ];
    if (!is_array($out['reuse_options'])) $out['reuse_options'] = [];
    return $out;
}

/** 根据当前基线和本次请求生成只包含真实变化的字段。 */
function appInfoScheduleBuildChanges(array $before, array $requested): array
{
    $before = appInfoScheduleNormalizeValues($before);
    $requested = appInfoScheduleNormalizeValues(array_merge($before, $requested));
    $changes = [];
    foreach ($requested as $field => $after) {
        if (!array_key_exists($field, $before) || $before[$field] === $after) continue;
        $changes[$field] = ['before' => $before[$field], 'after' => $after];
    }
    $guards = [];
    if (array_key_exists('config_mode', $changes) || array_key_exists('reuse_apk_id', $changes) || array_key_exists('reuse_options', $changes)) {
        foreach (['config_mode', 'reuse_apk_id', 'reuse_options'] as $field) $guards[$field] = $before[$field];
    }
    if (array_key_exists('domain_mode', $changes) || array_key_exists('custom_domains', $changes)) {
        foreach (['domain_mode', 'custom_domains'] as $field) $guards[$field] = $before[$field];
    }
    return ['changes' => $changes, 'guards' => $guards];
}

/** 新增一条计划；同一应用的不同字段计划可以同时存在。 */
function appInfoScheduleCreate(PDO $pdo, int $appId, int $userId, array $payload, int $delaySeconds): array
{
    if ($appId <= 0 || $userId <= 0 || $delaySeconds <= 0 || $delaySeconds > APP_INFO_SCHEDULE_MAX_DELAY_SECONDS) {
        throw new InvalidArgumentException('应用计划参数错误');
    }
    ensureAppInfoScheduleSchema($pdo);
    $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($encoded === false) throw new RuntimeException('应用信息计划编码失败');
    $applyAt = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify('+' . $delaySeconds . ' seconds')->format('Y-m-d H:i:s');
    $stmt = $pdo->prepare("INSERT INTO cainiao_apk_info_schedule
        (app_id, user_id, payload_json, apply_at, status, created_at, updated_at, applied_at, error_message)
        VALUES (:app_id, :user_id, :payload_json, :apply_at, 'pending', UTC_TIMESTAMP(), UTC_TIMESTAMP(), NULL, '')");
    $stmt->execute([':app_id' => $appId, ':user_id' => $userId, ':payload_json' => $encoded, ':apply_at' => $applyAt]);
    return appInfoScheduleSnapshot(['status' => 'pending', 'apply_at' => $applyAt]);
}

/** 兼容旧调用名，但不再按 app_id 覆盖其它计划。 */
function appInfoScheduleUpsert(PDO $pdo, int $appId, int $userId, array $payload, int $delaySeconds): array
{
    return appInfoScheduleCreate($pdo, $appId, $userId, $payload, $delaySeconds);
}

/** 按本次实际修改字段裁剪旧计划，未冲突字段及其原生效时间保持不变。 */
function appInfoSchedulePruneConflicts(PDO $pdo, int $appId, array $changedFields): void
{
    ensureAppInfoScheduleSchema($pdo);
    $changed = array_fill_keys(array_keys($changedFields), true);
    $groups = [
        ['config_mode', 'reuse_apk_id', 'reuse_options'],
        ['domain_mode', 'custom_domains'],
    ];
    foreach ($groups as $group) {
        $hit = false;
        foreach ($group as $field) if (isset($changed[$field])) $hit = true;
        if ($hit) foreach ($group as $field) $changed[$field] = true;
    }
    if (!$changed) return;
    $stmt = $pdo->prepare("SELECT schedule_id, payload_json FROM cainiao_apk_info_schedule
        WHERE app_id=:app_id AND status='pending' FOR UPDATE");
    $stmt->execute([':app_id' => $appId]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $payload = json_decode((string)$row['payload_json'], true);
        if (!is_array($payload) || !is_array($payload['changes'] ?? null)) continue;
        foreach (array_keys($changed) as $field) unset($payload['changes'][$field], $payload['guards'][$field]);
        if (!$payload['changes']) {
            $done = $pdo->prepare("UPDATE cainiao_apk_info_schedule SET status='cancelled', updated_at=UTC_TIMESTAMP(), error_message='' WHERE schedule_id=:id AND status='pending'");
            $done->execute([':id' => (int)$row['schedule_id']]);
        } else {
            $update = $pdo->prepare("UPDATE cainiao_apk_info_schedule SET payload_json=:payload, updated_at=UTC_TIMESTAMP() WHERE schedule_id=:id AND status='pending'");
            $update->execute([':payload' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ':id' => (int)$row['schedule_id']]);
        }
    }
}

/** 兼容立即保存取消调用；传字段时只取消冲突字段。 */
function appInfoScheduleCancel(PDO $pdo, int $appId, ?array $changedFields = null): void
{
    if ($changedFields === null) {
        $stmt = $pdo->prepare("UPDATE cainiao_apk_info_schedule SET status='cancelled', updated_at=UTC_TIMESTAMP(), error_message='' WHERE app_id=:app_id AND status='pending'");
        $stmt->execute([':app_id' => $appId]);
        return;
    }
    appInfoSchedulePruneConflicts($pdo, $appId, $changedFields);
}

/** 给列表附加最早计划和计划数量，前端据此恢复倒计时。 */
function appInfoScheduleAttachRows(PDO $pdo, array &$rows): void
{
    ensureAppInfoScheduleSchema($pdo);
    foreach ($rows as &$row) {
        $row['pending_update_status'] = '';
        $row['pending_update_apply_at'] = '';
        $row['pending_update_remaining_seconds'] = 0;
        $row['pending_update_count'] = 0;
    }
    unset($row);
    if (!$rows) return;
    $ids = array_values(array_unique(array_filter(array_map(static fn($row): int => (int)($row['id'] ?? 0), $rows), static fn(int $id): bool => $id > 0)));
    if (!$ids) return;
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("SELECT app_id, status, apply_at, COUNT(*) OVER (PARTITION BY app_id) AS plan_count
        FROM cainiao_apk_info_schedule WHERE status='pending' AND app_id IN ({$placeholders})
        ORDER BY apply_at ASC");
    $stmt->execute($ids);
    $scheduled = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $item) {
        $id = (int)$item['app_id'];
        if (!isset($scheduled[$id])) $scheduled[$id] = appInfoScheduleSnapshot($item) + ['count' => (int)$item['plan_count']];
    }
    foreach ($rows as &$row) {
        $id = (int)($row['id'] ?? 0);
        if (isset($scheduled[$id])) {
            $row['pending_update_status'] = $scheduled[$id]['status'];
            $row['pending_update_apply_at'] = $scheduled[$id]['apply_at'];
            $row['pending_update_remaining_seconds'] = $scheduled[$id]['remaining_seconds'];
            $row['pending_update_count'] = $scheduled[$id]['count'];
        }
    }
    unset($row);
}

/** 到期时按基线合并字段，期间被改过的字段会被跳过。 */
function appInfoScheduleApplyPayload(PDO $pdo, array $schedule, array $app): bool
{
    $payload = json_decode((string)($schedule['payload_json'] ?? ''), true);
    if (!is_array($payload) || !is_array($payload['changes'] ?? null)) throw new RuntimeException('待生效应用信息快照损坏');
    $current = appInfoScheduleNormalizeValues($app);
    $changes = $payload['changes'];
    foreach (($payload['guards'] ?? []) as $field => $before) {
        if (array_key_exists($field, $current) && $current[$field] !== $before) {
            foreach (['config_mode', 'reuse_apk_id', 'reuse_options'] as $f) if ($field === 'config_mode' || $field === 'reuse_apk_id' || $field === 'reuse_options') unset($changes[$f]);
            foreach (['domain_mode', 'custom_domains'] as $f) if ($field === 'domain_mode' || $field === 'custom_domains') unset($changes[$f]);
            break;
        }
    }
    foreach ($changes as $field => $change) {
        if (!is_array($change) || !array_key_exists($field, $current) || (($change['before'] ?? null) !== $current[$field])) unset($changes[$field]);
    }
    $fields = [];
    $params = [':id' => (int)$app['id'], ':user_id' => (int)$app['user_id']];
    $nextConfig = $current['config_mode'];
    $nextReuse = $current['reuse_apk_id'];
    foreach ($changes as $field => $change) {
        if (!is_array($change) || !array_key_exists('after', $change)) continue;
        $after = $change['after'];
        if ($field === 'name' || $field === 'app_key') { $fields[] = "{$field}=:{$field}"; $params[":{$field}"] = trim((string)$after); }
        elseif ($field === 'download_name_template') { $fields[] = 'download_name_template=:download_name_template'; $params[':download_name_template'] = normalizeDownloadNameTemplate($after); }
        elseif ($field === 'is_reusable') { $value = (int)$after; if (!in_array($value, [0,1], true)) throw new RuntimeException('待生效复用标记非法'); $fields[]='is_reusable=:is_reusable'; $params[':is_reusable']=$value; }
        elseif ($field === 'config_mode') { $nextConfig=(int)$after; if (!in_array($nextConfig,[0,1],true)) throw new RuntimeException('待生效配置方式非法'); $fields[]='config_mode=:config_mode'; $params[':config_mode']=$nextConfig; }
        elseif ($field === 'reuse_apk_id') { $nextReuse=$after===null?null:(int)$after; if ($nextConfig===1 && (!$nextReuse || $nextReuse===(int)$app['id'])) throw new RuntimeException('待生效复用目标非法'); $fields[]=$nextConfig===1?'reuse_apk_id=:reuse_apk_id':'reuse_apk_id=NULL'; if($nextConfig===1)$params[':reuse_apk_id']=$nextReuse; }
        elseif ($field === 'domain_mode') { $mode=(int)$after; if(!in_array($mode,[0,1],true))throw new RuntimeException('待生效域名方式非法'); $fields[]='domain_mode=:domain_mode';$params[':domain_mode']=$mode; }
        elseif ($field === 'custom_domains') { if($after===null||$after==='')$fields[]='custom_domains=NULL'; else {$valid=[];foreach(explode("\n",(string)$after) as $domain){$domain=trim($domain);if($domain==='')continue;if(!preg_match('/^https?:\/\/[^\s\/$.?#].[^\s]*$/i',$domain))throw new RuntimeException('待生效自定义域名格式错误');$valid[]=$domain;}if(!$valid)throw new RuntimeException('待生效自定义域名为空');$fields[]='custom_domains=:custom_domains';$params[':custom_domains']=implode("\n",$valid);} }
        elseif ($field === 'reuse_options') { if(!is_array($after))throw new RuntimeException('待生效复用选项非法');$fields[]='reuse_options=:reuse_options';$params[':reuse_options']=json_encode($after,JSON_UNESCAPED_UNICODE); }
    }
    if (!$fields) return false;
    if ($nextConfig===1 && (array_key_exists('config_mode',$changes)||array_key_exists('reuse_apk_id',$changes))) {
        $target=$pdo->prepare("SELECT a.id FROM cainiao_apk a LEFT JOIN cainiao_apk_deleted d ON d.apk_id=a.id WHERE a.id=:id AND a.user_id=:user_id AND a.is_reusable=1 AND d.apk_id IS NULL FOR SHARE");
        $target->execute([':id'=>$nextReuse,':user_id'=>(int)$app['user_id']]); if(!$target->fetchColumn())throw new RuntimeException('待生效复用目标已不存在或未开启复用');
    }
    $update=$pdo->prepare("UPDATE cainiao_apk SET ".implode(', ',$fields)." WHERE id=:id AND user_id=:user_id AND NOT EXISTS (SELECT 1 FROM cainiao_apk_deleted d WHERE d.apk_id=cainiao_apk.id)");$update->execute($params);
    return array_key_exists('config_mode',$changes)||array_key_exists('reuse_apk_id',$changes)||array_key_exists('reuse_options',$changes);
}

/** 处理到期计划，应用锁和计划锁按固定顺序取得。 */
function appInfoScheduleProcessDue(PDO $pdo, int $limit = 5): int
{
    ensureAppInfoScheduleSchema($pdo); $processed=0;
    for($i=0;$i<max(1,$limit);$i++){
        $candidate=$pdo->query("SELECT schedule_id, app_id, user_id FROM cainiao_apk_info_schedule WHERE status='pending' AND apply_at<=UTC_TIMESTAMP() ORDER BY apply_at ASC, schedule_id ASC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        if(!$candidate)break;
        $scheduleId=(int)$candidate['schedule_id'];$appId=(int)$candidate['app_id'];$configChanged=false;
        try{
            $pdo->beginTransaction();
            $appStmt=$pdo->prepare("SELECT a.id,a.user_id,a.name,a.download_name_template,a.app_key,a.is_reusable,a.config_mode,a.reuse_apk_id,a.domain_mode,a.custom_domains,a.reuse_options FROM cainiao_apk a LEFT JOIN cainiao_apk_deleted d ON d.apk_id=a.id WHERE a.id=:id AND a.user_id=:user_id AND d.apk_id IS NULL LIMIT 1 FOR UPDATE");
            $appStmt->execute([':id'=>$appId,':user_id'=>(int)$candidate['user_id']]);$app=$appStmt->fetch(PDO::FETCH_ASSOC);
            $lock=$pdo->prepare("SELECT * FROM cainiao_apk_info_schedule WHERE schedule_id=:sid AND status='pending' AND apply_at<=UTC_TIMESTAMP() FOR UPDATE");$lock->execute([':sid'=>$scheduleId]);$schedule=$lock->fetch(PDO::FETCH_ASSOC);
            if(!$schedule){$pdo->rollBack();continue;}
            if(!$app){$cancel=$pdo->prepare("UPDATE cainiao_apk_info_schedule SET status='cancelled',updated_at=UTC_TIMESTAMP(),error_message='应用已删除或无权访问' WHERE schedule_id=:sid AND status='pending'");$cancel->execute([':sid'=>$scheduleId]);$pdo->commit();$processed++;continue;}
            $configChanged=appInfoScheduleApplyPayload($pdo,$schedule,$app);
            $done=$pdo->prepare("UPDATE cainiao_apk_info_schedule SET status='applied',applied_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP(),error_message='' WHERE schedule_id=:sid AND status='pending'");$done->execute([':sid'=>$scheduleId]);$pdo->commit();$processed++;
        }catch(Throwable $error){if($pdo->inTransaction())$pdo->rollBack();$msg=function_exists('mb_substr')?mb_substr($error->getMessage(),0,480):substr($error->getMessage(),0,480);$fail=$pdo->prepare("UPDATE cainiao_apk_info_schedule SET status='failed',updated_at=UTC_TIMESTAMP(),error_message=:msg WHERE schedule_id=:sid AND status='pending'");$fail->execute([':msg'=>$msg,':sid'=>$scheduleId]);error_log('[AppInfoSchedule] 到期应用失败：'.$msg);$processed++;continue;}
        try{if($configChanged)Auth::afterConfigChange($pdo,$appId,'应用信息延迟生效');else Auth::reset_redis($appId);}catch(Throwable $error){error_log('[AppInfoSchedule] 到期传播失败 appId='.$appId.'：'.$error->getMessage());}
    }
    return $processed;
}
