<?php

function createJiaguTask(PDO $pdo, array $input)
{
    // 登录校验
    $user = Auth::check($pdo);
    $userId = (int)$user['id'];
    //throw new Exception('此功能暂时禁用');
    // 基础参数
    $apkId  = isset($input['apk_id'])  ? (int)$input['apk_id']  : 0;
    $signId = isset($input['sign_id']) ? (int)$input['sign_id'] : 0;
    $type   = trim($input['type'] ?? '');
    $rules  = trim($input['rule'] ?? '');
    $signType = isset($input['sign_type']) ? (int)$input['sign_type'] : 0;
    $hash256  = isset($input['hash256']) ? trim($input['hash256']) : '';
    if (!$apkId || !$signId || !$type) {
        throw new Exception('参数不完整');
    }
    // ========== 签名校验方式校验 ==========
    if (!in_array($signType, [0, 1, 2], true)) {
        throw new Exception('非法的签名校验方式');
    }
    if(empty($rules)){
        throw new Exception('加固规则错误');
    }
    /**
     * 去除所有空白字符（防绕过）
     */
    $compactRules = preg_replace('/\s+/', '', $rules);
    
    /**
     * 禁止出现 class*{*;} 形式
     */
    if (preg_match('/class\*\{\*;\}/i', $compactRules)) {
        throw new Exception('禁止使用全通配 class 规则');
    }
    
    /**
     * ✅ 必须包含：
     * (class + extends) 
     * 或
     * (class + { + })
     */
    $hasClass   = stripos($compactRules, 'class') !== false;
    $hasExtends = stripos($compactRules, 'extends') !== false;
    $hasBraceL  = strpos($compactRules, '{') !== false;
    $hasBraceR  = strpos($compactRules, '}') !== false;
    
    if (!(
            ($hasClass && $hasExtends) ||
            ($hasClass && $hasBraceL && $hasBraceR)
         )) {
        throw new Exception('规则格式不合法');
    }
    
    // sign_type = 2 时，必须提供合法 hash256
    if ($signType === 2) {
        if ($hash256 === '' || !preg_match('/^[0-9a-z]{64}$/', $hash256)) {
            throw new Exception('hash256 格式错误，必须是 64 位小写字母和数字');
        }
    } else {
        // 非自定义 hash256，强制清空
        $hash256 = '';
    }
    $task_vmp  = Auth::getSetting($pdo,"task_vmp","0");
    if(!$task_vmp && $user['role']!=='admin'){
        throw new Exception('此功能暂时禁用');
    }

    // ========== 加固类型校验（可扩展） ==========
    $allowTypes = [
        'vmp', // 虚拟机保护
        //'dpt', // 函数抽取
        // 后续在这里新增
    ];

    if (!in_array($type, $allowTypes, true)) {
        throw new Exception('不支持的加固类型');
    }

    // ========== 校验资源是否属于当前用户 ==========
    $check = function ($table, $id) use ($pdo, $userId) {
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM `$table` WHERE id = :id AND user_id = :uid"
        );
        $stmt->execute([':id' => $id, ':uid' => $userId]);
        return $stmt->fetchColumn() > 0;
    };

    if (
        !$check('cainiao_apk', $apkId) ||
        !$check('cainiao_sign', $signId)
    ) {
        if ($user['role'] !== 'admin') {
            throw new Exception('检测到未授权资源');
        }
    }

    // ========== APK 是否存在实际文件 ==========
    $stmt = $pdo->prepare("SELECT path FROM cainiao_apk WHERE id = :id");
    $stmt->execute([':id' => $apkId]);
    $apkPath = $stmt->fetchColumn();

    if (empty($apkPath)) {
        throw new Exception('文件不存在,请重新上传 APK');
    }

    // ========== 重复任务限制（同 APK 只能一个） ==========
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM cainiao_jiagu_task 
         WHERE user_id = :uid AND apk_id = :apk"
    );
    $stmt->execute([':uid' => $userId, ':apk' => $apkId]);
    if ($stmt->fetchColumn() > 0) {
        throw new Exception('该应用已存在加固任务，不能重复提交');
    }

    // ========== 每日任务数限制 ==========
    $vmpjiagu  = Auth::getSetting($pdo,"vmpjiagu","0");
    if($vmpjiagu){//如果开启了会员加固
        if(!$user['isVip']){
            throw new Exception('APK加固功能仅会员可用');
        }
    }

    // ========== 插入任务 ==========
    $insert = $pdo->prepare(
        "INSERT INTO `cainiao_jiagu_task`
        (user_id, apk_id, sign_id, sign_type, hash256, rules, type, created_at, status_text, status_info)
        VALUES
        (:uid, :apk, :sign, :sign_type, :hash256, :rules, :type, NOW(), '等待处理', '请等待任务队列')"
    );

    $insert->execute([
        ':uid'   => $userId,
        ':apk'   => $apkId,
        ':sign'  => $signId,
        ':sign_type' => $signType,
        ':hash256'   => $hash256,
        ':rules' => $rules,
        ':type'  => $type,
    ]);

    return [
        'message' => '加固任务创建成功，请耐心等待处理'
    ];
}
