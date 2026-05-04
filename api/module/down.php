<?php
function getTemplates(PDO $pdo, array $input) {
    if (empty($input['apk_id'])) throw new Exception('缺少应用ID');
    if (empty($input['templates_id'])) throw new Exception('缺少模板ID');
    if (empty($input['sign_id'])) throw new Exception('缺少证书ID');

    $user = Auth::check($pdo);
    $userId  = (int)$user['id'];
    $isAdmin = (($user['role'] ?? '') === 'admin');

    // 第一步：查询 cainiao_apk 表
    if ($isAdmin) {
        $stmt = $pdo->prepare('SELECT * FROM cainiao_apk WHERE id = :apk_id LIMIT 1');
        $stmt->execute([':apk_id' => $input['apk_id']]);
    } else {
        $stmt = $pdo->prepare('SELECT * FROM cainiao_apk WHERE id = :apk_id AND user_id = :user_id LIMIT 1');
        $stmt->execute([':apk_id' => $input['apk_id'], ':user_id' => $userId]);
    }
    $apk = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$apk) throw new Exception('应用不存在');

    // 第二步：查询 cainiao_sign 表
    if ($isAdmin) {
        $stmt = $pdo->prepare('SELECT * FROM cainiao_sign WHERE id = :sign_id LIMIT 1');
        $stmt->execute([':sign_id' => $input['sign_id']]);
    } else {
        $stmt = $pdo->prepare('SELECT * FROM cainiao_sign WHERE id = :sign_id AND user_id = :user_id LIMIT 1');
        $stmt->execute([':sign_id' => $input['sign_id'], ':user_id' => $userId]);
    }
    $sign = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$sign) throw new Exception('证书不存在');

    // 第三步：查询 cainiao_template 表
    if ($isAdmin) {
        $stmt = $pdo->prepare('SELECT * FROM cainiao_template WHERE id = :templates_id LIMIT 1');
        $stmt->execute([':templates_id' => $input['templates_id']]);
    } else {
        $stmt = $pdo->prepare('SELECT * FROM cainiao_template WHERE id = :templates_id AND enable = 1 LIMIT 1');
        $stmt->execute([':templates_id' => $input['templates_id']]);
    }
    $template = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$template) throw new Exception('模板不存在或未启用');

    // 开始构建信息
    $dir = __DIR__ . '/../../'; // 网站根目录
    $sign_dir = $dir . 'signfile/' . $sign['path'];
    if (!file_exists($sign_dir)) {
        throw new Exception('云端的证书文件不存在 ' . $sign_dir);
    }
    $template_dir = $dir . 'templates/' . $template['path'];
    if (!file_exists($template_dir)) {
        throw new Exception('云端的模板文件不存在');
    }
    //$sign有这些字段
    // 构建注入信息
    $inject = [];
    $inject['name'] = $apk['name'];
    $inject['package'] = $apk['package'];
    $inject['version'] = $apk['version'];
    $inject['appid'] = $apk['id'];
    $inject['appkey'] = $apk['user_id'];
    $inject['sign_name'] = $sign['name'];
    $inject['sign_alias'] = $sign['alias'];
    $inject['sign_password'] = $sign['password'];
    $inject['sign_cert_password'] = $sign['cert_password'];
    $inject['className'] = $template['className'];

    // 转换为 JSON 字符串
    $inject_json = json_encode($inject, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

    // 创建临时 zip 文件
    $tmp_zip = sys_get_temp_dir() . '/inject_' . uniqid() . '.zip';
    $zip = new ZipArchive();
    if ($zip->open($tmp_zip, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new Exception('无法创建临时压缩包');
    }

    // ====== 关键改动：把 JKS 转为 PKCS12 并加入压缩包 ======
    // 源 keystore（JKS）
    $srcKeystore = $sign_dir; // 原 sign.keystore 路径
    $srcStorePass = $sign['password'];     // -storepass
    $srcKeyPass = $sign['cert_password'] ?? $sign['password']; // -keypass（如果另有字段则用证书密码）
    $alias = $sign['alias'];

    // 生成临时的 p12 路径和 p12 密码（这里用临时随机密码）
    $tmpP12 = sys_get_temp_dir() . '/sign_' . uniqid() . '.p12';
    $p12Pass = bin2hex(random_bytes(8)); // 临时密码，足够随机

    // 使用 keytool 执行转换：JKS -> PKCS12
    // keytool -importkeystore -srckeystore src.jks -srcstorepass SRC_PASS -srcalias ALIAS \
    //         -destkeystore dest.p12 -deststoretype PKCS12 -deststorepass P12_PASS -destkeypass P12_PASS -noprompt
    $keytool = trim(shell_exec('which keytool 2>/dev/null') ?: 'keytool'); // 尝试查找 keytool
    $cmdParts = [
    $keytool,
        '-J-Dkeystore.pkcs12.legacy=true',
        '-J-Dkeystore.pkcs12.macAlgorithm=PKCS12-MAC',
        '-importkeystore',
        '-srckeystore', escapeshellarg($srcKeystore),
        '-srcstorepass', escapeshellarg($srcStorePass),
        '-srcalias', escapeshellarg($alias),
        '-srckeypass', escapeshellarg($srcKeyPass),
        '-destkeystore', escapeshellarg($tmpP12),
        '-deststoretype', 'PKCS12',
        '-deststorepass', escapeshellarg($p12Pass),
        '-destkeypass', escapeshellarg($p12Pass),
        '-destalias', escapeshellarg($alias),
        '-noprompt'
    ];
    $cmd = implode(' ', $cmdParts) . ' 2>&1';

     $output = shell_exec($cmd);

    // 把生成的 p12 文件加入压缩包（命名为 sign.p12）
    if (!file_exists($tmpP12)) {
        throw new Exception('转换后的 PKCS12 文件不存在');
    }
    $zip->addFile($tmpP12, 'sign.p12');

    // 可选：如果你仍然需要原始 keystore 一并提供，可同时加入（这里注释掉）
    // $zip->addFile($sign_dir, 'sign.keystore');

    // 添加模板 APK 和 info.json
    $zip->addFile($template_dir, 'templates.apk');
    $zip->addFromString('info.json', $inject_json);

    // 设置密码加密（如果支持）
    if (method_exists($zip, 'setPassword')) {
        $zip->setPassword((string)md5($userId));
        $zip->setEncryptionName('sign.p12', ZipArchive::EM_AES_256);
        $zip->setEncryptionName('templates.apk', ZipArchive::EM_AES_256);
        $zip->setEncryptionName('info.json', ZipArchive::EM_AES_256);
    }

    $zip->close();

    // ====== 后续和清理 ======
    // 删除临时 p12
    @unlink($tmpP12);

    // 计算 MD5 并复制到目标目录
    $output_dir = $dir . 'local_inject/';
    if (!is_dir($output_dir)) {
        mkdir($output_dir, 0755, true);
    }

    $zip_md5 = md5_file($tmp_zip);
    $final_name = $userId . '_' . $zip_md5 . '.zip';
    $final_path = $output_dir . $final_name;

    // 如果已存在则覆盖
    if (file_exists($final_path)) {
        unlink($final_path);
    }

    if (!copy($tmp_zip, $final_path)) {
        throw new Exception('复制压缩包到local_inject目录失败');
    }

    // 删除临时文件
    @unlink($tmp_zip);
    $files = glob($output_dir . $userId . '_*.zip');
    if (is_array($files)) {
        foreach ($files as $file) {
            // 只保留当前生成的 zip
            if (basename($file) !== $final_name) {
                @unlink($file);
            }
        }
    }
    // 自动识别协议与域名，补齐完整访问路径
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
    $domain = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $baseUrl = rtrim($protocol . $domain, '/');
    $fileurl = $baseUrl . '/local_inject/' . $final_name;

    return [
        'fileurl' => $fileurl
    ];
}
?>
