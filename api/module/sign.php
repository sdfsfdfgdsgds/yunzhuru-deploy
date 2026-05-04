<?php
/*function DownSigns(PDO $pdo, array $input)
{
    $user = Auth::check($pdo);
    $userId = (int)$user['id'];

    // 前端传入的证书 id
    $signId = isset($input['id']) ? (int)$input['id'] : 0;
    if ($signId <= 0) {
        throw new Exception('缺少证书id');
    }

    $signTable = 'cainiao_sign';

    $sql = "SELECT id, name, alias, password, cert_password, upload_time, path
            FROM `$signTable`
            WHERE id = :id AND user_id = :user_id
            LIMIT 1";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':id' => $signId,
        ':user_id' => $userId
    ]);

    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row || empty($row['path'])) {
        throw new Exception('未找到对应id的证书');
    }

    // 证书文件目录
    $baseDir  = $_SERVER['DOCUMENT_ROOT'] . '/signfile/';
    $basePath = '/signfile/';

    // 防目录穿越
    $fileName = basename($row['path']);
    $fullFile = $baseDir . $fileName;

    // 校验文件是否真实存在
    if (!is_file($fullFile)) {
        throw new Exception("证书文件不存在");
    }

    // 拼接相对路径
    $row['path'] = $basePath . $fileName;

    // 返回统一数组结构，方便前端处理
    return [$row];
}*/



/**
 * 通过文件头判断 keystore 类型
 * - JKS: FE ED FE ED ...
 * - PKCS12: 30 82 ...
 * 返回：'JKS' / 'PKCS12' / 'UNKNOWN'
 */
function detectKeystoreTypeByHeader($file)
{
    $fp = fopen($file, 'rb');
    if (!$fp) return 'UNKNOWN';

    $head = fread($fp, 8);
    fclose($fp);

    if ($head === false || strlen($head) < 4) return 'UNKNOWN';

    $b = array_values(unpack('C*', substr($head, 0, 4)));

    // JKS magic: FE ED FE ED
    if ($b[0] === 0xFE && $b[1] === 0xED && $b[2] === 0xFE && $b[3] === 0xED) {
        return 'JKS';
    }

    // PKCS12 常见 ASN.1 开头：30 82 ...
    if ($b[0] === 0x30 && $b[1] === 0x82) {
        return 'PKCS12';
    }

    return 'UNKNOWN';
}

/**
 * 不是 JKS 则转换为 JKS，输出到 /jks/原文件名称.jks（允许覆盖）
 * 返回：相对路径（以 / 开头）
 */
function convertToJksIfNeeded($srcFullFile, $srcFileName, $storePassword)
{
    $docRoot = rtrim($_SERVER['DOCUMENT_ROOT'], '/');
    $jksDir  = $docRoot . '/jks/';
    if (!is_dir($jksDir)) {
        if (!mkdir($jksDir, 0755, true) && !is_dir($jksDir)) {
            throw new Exception('创建 jks 目录失败');
        }
    }

    $baseName = pathinfo($srcFileName, PATHINFO_FILENAME);
    $destName = $baseName . '.jks';
    $destFull = $jksDir . $destName;

    $type = detectKeystoreTypeByHeader($srcFullFile);

    // 已经是 JKS：直接返回原始 signfile 路径
    if ($type === 'JKS') {
        return '/signfile/' . $srcFileName;
    }

    // 非 JKS：转换成 JKS（允许覆盖）
    // 如果未知类型，也尝试转换；失败再抛出明确错误
    $srcPass = $storePassword;

    // 为了避免 shell 注入，所有参数必须 escapeshellarg
    $cmd = sprintf(
        'keytool -importkeystore -noprompt ' .
        '-srckeystore %s -srcstorepass %s ' .
        '-destkeystore %s -deststorepass %s -deststoretype JKS',
        escapeshellarg($srcFullFile),
        escapeshellarg($srcPass),
        escapeshellarg($destFull),
        escapeshellarg($srcPass)
    );

    $output = shell_exec($cmd . ' 2>&1');

    if (!is_file($destFull) || filesize($destFull) <= 0) {
        // 额外提示一下检测到的类型，方便定位
        throw new Exception('证书转换为 JKS 失败，类型=' . $type . '，输出=' . $output);
    }

    // 返回转换后的 JKS 相对路径
    return '/jks/' . $destName;
}

function DownSigns(PDO $pdo, array $input)
{
    $user = Auth::check($pdo);
    $userId = (int)$user['id'];

    // 前端传入的证书 id
    $signId = isset($input['id']) ? (int)$input['id'] : 0;
    if ($signId <= 0) {
        throw new Exception('缺少证书id');
    }

    $signTable = 'cainiao_sign';

    $sql = "SELECT id, name, alias, password, cert_password, upload_time, path
            FROM `$signTable`
            WHERE id = :id AND user_id = :user_id
            LIMIT 1";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':id' => $signId,
        ':user_id' => $userId
    ]);

    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row || empty($row['path'])) {
        throw new Exception('未找到对应id的证书');
    }

    // 证书文件目录
    $baseDir = rtrim($_SERVER['DOCUMENT_ROOT'], '/') . '/signfile/';

    // 防目录穿越
    $fileName = basename($row['path']);
    $fullFile = $baseDir . $fileName;

    // 校验文件是否真实存在
    if (!is_file($fullFile)) {
        throw new Exception('证书文件不存在');
    }

    // password 作为 keystore 密码（用于转换）
    $storePassword = (string)($row['password'] ?? '');
    if ($storePassword === '') {
        throw new Exception('证书密码为空，无法转换');
    }

    // ===== 格式检测 + 必要时转换 =====
    $finalPath = convertToJksIfNeeded($fullFile, $fileName, $storePassword);

    // 返回给前端的 path：如果转换了就是 /jks/xxx.jks，否则 /signfile/xxx
    $row['path'] = $finalPath;

    return [$row];
}


