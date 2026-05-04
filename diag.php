<?php
header('Content-Type: application/json');
$checks = [];
$templatesDir = __DIR__ . '/templates/';
$checks['templates_dir'] = is_dir($templatesDir);
$checks['templates_files'] = is_dir($templatesDir) ? array_values(array_diff(scandir($templatesDir), ['.','..'])) : [];
$shellFile = $templatesDir . '1_8db4f7afda84737b12058fef9ae13322.apk';
$checks['shell_exists'] = file_exists($shellFile);
$checks['shell_size'] = file_exists($shellFile) ? filesize($shellFile) : 0;
$checks['shell_realpath'] = realpath($shellFile) ?: 'false';
$apktoolJar = __DIR__ . '/bin/apktool_2.11.1.jar';
$checks['apktool_exists'] = file_exists($apktoolJar);
$checks['apktool_size'] = file_exists($apktoolJar) ? filesize($apktoolJar) : 0;
$checks['java'] = trim(shell_exec('java -version 2>&1 | head -1') ?? 'not found');
$checks['ionice'] = trim(shell_exec('which ionice 2>&1') ?? 'not found');
$checks['nice'] = trim(shell_exec('which nice 2>&1') ?? 'not found');
$config = require __DIR__ . '/config/config.php';
$checks['db_port'] = $config['port'];
$checks['temp_writable'] = is_writable(__DIR__ . '/temp/');
$checks['worker_count'] = trim(shell_exec('ps aux | grep worker.php | grep -v grep | wc -l') ?? '0');
$checks['supervisor'] = trim(shell_exec('supervisorctl status 2>&1') ?? 'unknown');
// 试运行 apktool
$testCmd = sprintf('java -jar %s --version 2>&1', escapeshellarg($apktoolJar));
$checks['apktool_version'] = trim(shell_exec($testCmd) ?? 'failed');
echo json_encode($checks, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
