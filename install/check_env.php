<?php
header('Content-Type: application/json');

// 配置锁路径
$lockFile = __DIR__ . '/../config/config.lock';


// 如果已安装，返回 404
if (file_exists($lockFile)) {
    //http_response_code(404);
    echo json_encode(['code' => 404, 'message' => '系统已安装，禁止访问该接口']);
    exit;
}

$result = [
    'code' => 200,
    'message' => '检测完成',
    'data' => []
];

// 系统平台检测
$systemName = PHP_OS_FAMILY ?: PHP_OS;
if (stripos($systemName, 'Linux') !== false) {
    $result['data']['system_platform'] = [
        'status' => true,
        'value' => $systemName
    ];
} else {
    $result['data']['system_platform'] = [
        'status' => false,
        'value' => $systemName,
        'error' => '当前操作系统不受支持，仅支持部署在 Linux 系统上',
        'install' => '请切换到 Linux 系统环境部署，如 Ubuntu/Debian/CentOS 等'
    ];
}

// 检测 PHP 版本
$phpVersion = PHP_VERSION;
if (version_compare($phpVersion, '7.1.0', '>=')) {
    $result['data']['php_version'] = ['status' => true, 'value' => $phpVersion];
} else {
    $result['data']['php_version'] = ['status' => false, 'value' => $phpVersion, 'error' => 'PHP 版本必须 ≥ 7.1'];
}

// exif 扩展检测
if (extension_loaded('exif')) {
    $result['data']['ext_exif'] = [
        'status' => true,
        'value' => '已启用'
    ];
} else {
    $result['data']['ext_exif'] = [
        'status' => false,
        'value' => '未启用',
        'error' => '未检测到 exif 扩展，某些图片功能将无法使用',
        'install' => '请安装 exif 扩展，并重启 php 服务'
    ];
}
// redis 扩展检测
if (extension_loaded('redis')) {
    $redisVersion = phpversion('redis');
    $result['data']['Redis'] = [
        'status' => true,
        'value'  => '已启用' . ($redisVersion ? "（版本 {$redisVersion}）" : '')
    ];
} else {
    $result['data']['Redis'] = [
        'status' => false,
        'value'  => '未启用',
        'error'  => '未检测到 redis 扩展',
        'install'=> '请在宝塔面板为PHP安装redis扩展以及安装redis环境'
    ];
}

// 检测 shell_exec 是否可用
if (function_exists('shell_exec')) {
    $result['data']['shell_exec'] = ['status' => true];
} else {
    $result['data']['shell_exec'] = ['status' => false, 'error' => 'shell_exec 函数被禁用，解禁后重启 php 服务'];
}

// 检测 putenv 是否可用
if (function_exists('putenv')) {
    $result['data']['putenv'] = ['status' => true];
} else {
    $result['data']['putenv'] = ['status' => false, 'error' => 'putenv 函数被禁用，解禁后重启 php 服务'];
}

// 检测 pcntl_signal 是否可用
if (function_exists('pcntl_signal')) {
    $result['data']['pcntl_signal'] = ['status' => true];
} else {
    $result['data']['pcntl_signal'] = ['status' => false, 'error' => 'pcntl_signal 函数被禁用，解禁后重启 php 服务'];
}

// 检测 java 是否安装
$javaOutput = @shell_exec('java -version 2>&1');

if (preg_match('/version "(.*?)"/', $javaOutput, $match)) {
    $javaVersion = $match[1];
    $result['data']['java'] = [
        'status' => true,
        'value' => $javaVersion
    ];
} else {
    $result['data']['java'] = [
        'status' => false,
        'error' => '未检测到 Java 环境',
        'install' => '可执行以下命令安装：sudo apt install default-jre -y'
    ];
}

/*
//项目内置了签名jar文件了
$apkOutput = @shell_exec('apksigner --version 2>&1');

if (
    $apkOutput &&
    stripos($apkOutput, 'command not found') === false &&
    preg_match('/\d+(\.\d+){1,2}/', $apkOutput, $match)
) {
    $apkVersion = $match[0];
    $result['data']['apksigner'] = [
        'status' => true,
        'value' => $apkVersion
    ];
} else {
    $result['data']['apksigner'] = [
        'status' => false,
        'error' => '未检测到 apksigner 命令',
        'install' => '可执行以下命令安装：sudo apt install apksigner -y'
    ];
}

*/

// 检测 zipalign 是否安装
$zipalignOutput = @shell_exec('zipalign -v 2>&1');

if (
    $zipalignOutput &&
    stripos($zipalignOutput, 'Usage') !== false
) {
    $result['data']['zipalign'] = [
        'status' => true,
        'value' => '已检测到 zipalign 命令'
    ];
} else {
    $result['data']['zipalign'] = [
        'status' => false,
        'error' => '未检测到 zipalign 命令',
        'install' => '可执行以下命令安装：sudo apt update && sudo apt install -y zipalign'
    ];
}

// 检测 aapt 是否安装
$aaptOutput = @shell_exec('aapt v 2>&1');

if (
    $aaptOutput &&
    stripos($aaptOutput, 'Android Asset Packaging Tool') !== false
) {
    $result['data']['aapt'] = [
        'status' => true,
        'value' => '已检测到 aapt 命令'
    ];
} else {
    $result['data']['aapt'] = [
        'status' => false,
        'error' => '未检测到 aapt 命令',
        'install' => '可执行以下命令安装：sudo apt install -y aapt'
    ];
}

// 检测 go 是否安装
$aaptOutput = @shell_exec('go version 2>&1');

if (
    $aaptOutput &&
    stripos($aaptOutput, 'go version') !== false
) {
    $result['data']['go'] = [
        'status' => true,
        'value' => $aaptOutput
    ];
} else {
    $result['data']['go'] = [
        'status' => false,
        'error' => '未检测到 go 命令(非必装环境)',
        'install' => '可执行以下命令安装：sudo apt install -y golang-go'
    ];
}

// 检测 cmake 是否安装
$cmakeOutput = @shell_exec('cmake --version 2>&1');

if (
    $cmakeOutput &&
    preg_match('/cmake version\s+([\d\.]+)/i', $cmakeOutput, $match)
) {
    $result['data']['cmake'] = [
        'status' => true,
        'value'  => $match[1]
    ];
} else {
    $result['data']['cmake'] = [
        'status'  => false,
        'error'   => '未检测到 cmake 环境',
        'install' => '请通过 Android SDK 安装：sdkmanager "cmake;3.22.1"'
    ];
}
// 检测 Android NDK（绕过 open_basedir）
$ndkCmd = @shell_exec('ls /usr/lib/android-sdk/ndk 2>/dev/null');

if ($ndkCmd && stripos($ndkCmd, '26.1.10909125') !== false) {
    $result['data']['ndk'] = [
        'status' => true,
        'value'  => '26.1.10909125'
    ];
} else {
    $result['data']['ndk'] = [
        'status'  => false,
        'error'   => '未检测到 Android NDK (26.1.10909125)',
        'install' => '请执行：sdkmanager "ndk;26.1.10909125"'
    ];
}





echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);exit;
