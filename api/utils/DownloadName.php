<?php

/**
 * APK 下载名称模板工具。
 *
 * 模板只负责生成客户端保存时看到的文件名，不改变 release 目录中的内部
 * 制品路径。这样应用重新上传或重新注入时，内部文件仍可按原有合同查找，
 * 而用户可以独立维护稳定的下载名称。
 */

/**
 * 确保旧库具备 APK 下载名称模板字段。
 *
 * 安装脚本会创建新字段；这里保留幂等补字段逻辑，兼容已经运行中的旧库。
 */
function ensureApkDownloadNameTemplateColumn(PDO $pdo): void
{
    static $checked = false;
    if ($checked) {
        return;
    }

    $stmt = $pdo->query("SHOW COLUMNS FROM `cainiao_apk` LIKE 'download_name_template'");
    if (!$stmt->fetch(PDO::FETCH_ASSOC)) {
        try {
            $pdo->exec("ALTER TABLE `cainiao_apk` ADD `download_name_template` VARCHAR(255) NOT NULL DEFAULT '' COMMENT 'APK 下载名称模板' AFTER `name`");
        } catch (Throwable $e) {
            // 并发请求可能同时补字段；重复字段错误可以安全忽略。
            if (stripos($e->getMessage(), 'Duplicate column') === false && strpos($e->getMessage(), '1060') === false) {
                throw $e;
            }
        }
    }

    $checked = true;
}

/** 返回当前支持的下载名称模板占位符。 */
function downloadNameTemplatePlaceholders(): array
{
    return [
        'name' => '应用名称',
        'date' => '日期（YYYYMMDD）',
        'version' => '版本号',
        'package' => '包名',
        'appid' => '应用 ID',
        'task_id' => '任务 ID',
    ];
}

/**
 * 返回用于下载文件名的应用名称。
 *
 * 上传流程为了便于后台识别，会把框架检测结果追加到展示名末尾；下载名
 * 只保留应用本身的名称，避免“应用名(flutter框架)”成为用户保存的文件名。
 */
function normalizeDownloadApplicationName($name): string
{
    $name = trim((string)$name);
    if ($name === '') {
        return '';
    }

    // 兼容半角/全角括号，并只清理末尾的框架、引擎或安卓壳标记。
    $cleanName = preg_replace('/\s*[（(][^（）()]*?(?:框架|引擎|安卓壳)[^（）()]*[）)]\s*$/u', '', $name);
    return trim($cleanName === null ? $name : $cleanName);
}

/**
 * 返回空模板使用的默认下载名称模板。
 *
 * 模板保存在数据库时允许为空，空值表示跟随平台默认规则；统一在展开阶段
 * 补齐日期和“云注入”后缀，避免不同下载端各自实现出不同的默认文件名。
 */
function defaultDownloadNameTemplate(): string
{
    return '{name}_{date}_云注入.apk';
}

/** 返回平台业务使用的北京时间日期，避免 PHP 容器默认 UTC 导致跨日偏差。 */
function yunzhuruBusinessDate(string $format = 'Y-m-d H:i:s', ?DateTimeImmutable $now = null): string
{
    $now = $now ?: new DateTimeImmutable('now', new DateTimeZone('UTC'));
    return $now->setTimezone(new DateTimeZone('Asia/Shanghai'))->format($format);
}

/**
 * 将数据库中按 UTC 保存的 DATETIME 转成后台页面使用的北京时间。
 * 解析失败或空值原样返回，兼容旧数据中的空字段。
 */
function formatStoredUtcDateTime($value): string
{
    $text = trim((string)$value);
    if ($text === '') {
        return $text;
    }

    try {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $text, new DateTimeZone('UTC'));
        $errors = DateTimeImmutable::getLastErrors();
        if (!$date || (is_array($errors) && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            $date = new DateTimeImmutable($text, new DateTimeZone('UTC'));
        }
        return $date->setTimezone(new DateTimeZone('Asia/Shanghai'))->format('Y-m-d H:i:s');
    } catch (Throwable $e) {
        return $text;
    }
}

/** 返回数据库 UTC 时间对应的北京时间日期，供对象路径回溯使用。 */
function yunzhuruBusinessDateFromStoredUtc($value): ?string
{
    $display = formatStoredUtcDateTime($value);
    if ($display === '') {
        return null;
    }
    try {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $display, new DateTimeZone('Asia/Shanghai'));
        return $date ? $date->format('Ymd') : null;
    } catch (Throwable $e) {
        return null;
    }
}

/** 批量转换 API 行中的业务时间字段，保持数据库查询和清理逻辑仍使用 UTC。 */
function formatStoredUtcDateTimeFields(array &$row, array $fields): void
{
    foreach ($fields as $field) {
        if (array_key_exists($field, $row) && $row[$field] !== null && $row[$field] !== '') {
            $row[$field] = formatStoredUtcDateTime($row[$field]);
        }
    }
}

/**
 * 校验并规范化下载名称模板。
 *
 * 空字符串代表清除自定义模板。未知占位符直接拒绝，避免保存后只能得到
 * 原样花括号；普通文件名字符由下载响应阶段统一做跨平台清理。
 */
function normalizeDownloadNameTemplate($template): string
{
    $template = trim((string)$template);
    if ($template === '') {
        return '';
    }
    if (function_exists('mb_strlen') ? mb_strlen($template, 'UTF-8') > 120 : strlen($template) > 120) {
        throw new Exception('下载名称模板不能超过 120 个字符');
    }
    if (preg_match('~[\\\\/<>:"|?*]~u', $template)) {
        throw new Exception('下载名称模板不能包含路径分隔符或文件名特殊字符');
    }
    if (preg_match('/[\x00-\x1F\x7F]/u', $template)) {
        throw new Exception('下载名称模板不能包含控制字符');
    }

    $allowed = downloadNameTemplatePlaceholders();
    preg_match_all('/\{([^{}]*)\}/u', $template, $matches);
    foreach ($matches[1] as $placeholder) {
        if (!array_key_exists($placeholder, $allowed)) {
            throw new Exception('不支持的下载名称占位符：{' . $placeholder . '}');
        }
    }
    $withoutPlaceholders = preg_replace('/\{[^{}]*\}/u', '', $template);
    if (preg_match('/[{}]/u', $withoutPlaceholders)) {
        throw new Exception('下载名称模板中的占位符格式不正确');
    }

    return $template;
}

/**
 * 将模板替换为实际文件名。
 *
 * 日期使用 YYYYMMDD，适合文件名排序；最终扩展名和危险字符仍由 down.php
 * 的 normalizeDownloadName() 统一处理。
 */
function renderDownloadNameTemplate(string $template, array $context): string
{
    $template = trim($template);
    if ($template === '') {
        $template = defaultDownloadNameTemplate();
    }
    $template = normalizeDownloadNameTemplate($template);

    $values = [
        'name' => normalizeDownloadApplicationName($context['name'] ?? $context['app_name'] ?? '未命名应用') ?: '未命名应用',
        'date' => trim((string)($context['date'] ?? yunzhuruBusinessDate('Ymd'))),
        'version' => trim((string)($context['version'] ?? $context['app_version'] ?? '')),
        'package' => trim((string)($context['package'] ?? $context['app_package'] ?? '')),
        'appid' => trim((string)($context['appid'] ?? $context['app_id'] ?? '')),
        'task_id' => trim((string)($context['task_id'] ?? '')),
    ];
    foreach ($values as $key => $value) {
        $template = str_replace('{' . $key . '}', $value, $template);
    }

    return trim($template);
}

/**
 * 生成固定桶中的制品对象名，与上传和恢复路径共用同一规则。
 */
function buildReleaseDownloadObjectFileName(string $internalFileName, string $downloadName, string $defaultName): string
{
    $baseName = basename($internalFileName);
    if ($downloadName === '' || $downloadName === $defaultName) {
        return $baseName;
    }

    return pathinfo($baseName, PATHINFO_FILENAME) . '-' . substr(md5($downloadName), 0, 12) . '.apk';
}
