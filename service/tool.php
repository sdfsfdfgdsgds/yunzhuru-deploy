<?php


function merge_activities_to_application_only($source_dir, $target_dir) {
    $src_manifest = rtrim($source_dir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'AndroidManifest.xml';
    $dst_manifest = rtrim($target_dir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'AndroidManifest.xml';

    if (!file_exists($src_manifest) || !file_exists($dst_manifest)) {
        echo "Manifest 文件不存在\n";
        return false;
    }

    // 加载源和目标 manifest
    $src_doc = new DOMDocument();
    $src_doc->preserveWhiteSpace = false;
    $src_doc->formatOutput = true;
    $src_doc->load($src_manifest);

    $dst_doc = new DOMDocument();
    $dst_doc->preserveWhiteSpace = false;
    $dst_doc->formatOutput = true;
    $dst_doc->load($dst_manifest);

    // 获取 <manifest> 根节点
    $dst_manifest_node = $dst_doc->getElementsByTagName("manifest")->item(0);
    $src_manifest_node = $src_doc->getElementsByTagName("manifest")->item(0);

    if (!$dst_manifest_node || !$src_manifest_node) {
        echo "未找到 <manifest> 根节点\n";
        return false;
    }

    // 合并 <uses-permission>（去重）
    $dst_perms = [];
    foreach ($dst_doc->getElementsByTagName("uses-permission") as $perm) {
        $name = $perm->getAttribute("android:name");
        if ($name) {
            $dst_perms[$name] = true;
        }
    }

    foreach ($src_doc->getElementsByTagName("uses-permission") as $perm) {
        $name = $perm->getAttribute("android:name");
        if ($name && !isset($dst_perms[$name])) {
            echo "插入 uses-permission: $name\n";
            $comment = $dst_doc->createComment(" 此 uses-permission 来自合并插入 ");
            $dst_manifest_node->appendChild($comment);
            $dst_manifest_node->appendChild($dst_doc->importNode($perm, true));
            $dst_perms[$name] = true;
        }
    }

    // 合并 <permission>（去重）
    $dst_defined_perms = [];
    foreach ($dst_doc->getElementsByTagName("permission") as $perm) {
        $name = $perm->getAttribute("android:name");
        if ($name) {
            $dst_defined_perms[$name] = true;
        }
    }

    /* foreach ($src_doc->getElementsByTagName("permission") as $perm) {
        $name = $perm->getAttribute("android:name");
        if ($name && !isset($dst_defined_perms[$name])) {
            $comment = $dst_doc->createComment(" 此 permission 来自合并插入 ");
            $dst_manifest_node->appendChild($comment);
            $dst_manifest_node->appendChild($dst_doc->importNode($perm, true));
            $dst_defined_perms[$name] = true;
        }
    } */

    // 获取 <application> 节点
    $dst_app = $dst_doc->getElementsByTagName("application")->item(0);
    $src_app = $src_doc->getElementsByTagName("application")->item(0);

    if (!$dst_app || !$src_app) {
        echo "未找到 <application> 标签\n";
        return false;
    }

    // 合并 <activity> 和 <activity-alias>（去掉 intent-filter）
    foreach (['activity', 'activity-alias'] as $tag) {
        foreach ($src_app->getElementsByTagName($tag) as $node) {
            $imported = $dst_doc->importNode($node, true);

            // 移除 intent-filter
            $filters = $imported->getElementsByTagName("intent-filter");
            while ($filters->length > 0) {
                $imported->removeChild($filters->item(0));
            }
            echo "插入 $tag: $name\n";
            $comment = $dst_doc->createComment(" 此 $tag 来自合并插入 ");
            $dst_app->appendChild($comment);
            $dst_app->appendChild($imported);
        }
    }

    // 保存结果
    $dst_doc->save($dst_manifest);
    echo "合并完成：uses-permission、permission、activity 均已插入并带注释\n";
    return true;
}












function delete_dir($dir) {
    if (!is_dir($dir)) return;

    $items = scandir($dir);
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') continue;
        $path = $dir . DIRECTORY_SEPARATOR . $item;
        if (is_dir($path)) {
            delete_dir($path);
        } else {
            unlink($path);
        }
    }
    rmdir($dir);
}

function copy_res_files($src_dir, $dst_dir, $file_list) {
    $copied_files = [];
    $count = 0;

    foreach ($file_list as $relative_path) {
        // 标准化路径，去掉开头斜杠
        $relative_path = ltrim($relative_path, '/\\');

        $src_file = rtrim($src_dir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $relative_path;
        $dst_file = rtrim($dst_dir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $relative_path;

        if (!is_file($src_file)) {
            continue; // 源文件不存在，跳过
        }

        if (file_exists($dst_file)) {
            continue; // 目标已存在，跳过
        }

        // 创建目标文件夹
        $dst_folder = dirname($dst_file);
        if (!is_dir($dst_folder)) {
            mkdir($dst_folder, 0777, true);
        }

        if (copy($src_file, $dst_file)) {
            $copied_files[] = str_replace('\\', '/', $relative_path);
            $count++;
        }
    }

    return [
        'count' => $count,
        'files' => $copied_files
    ];
}




function ensure_application_name($apk_dir, $class_name) {
    $manifest_path = rtrim($apk_dir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'AndroidManifest.xml';

    if (!file_exists($manifest_path)) {
        return [false, "Manifest 文件不存在：$manifest_path"];
    }

    // 加载并解析 XML
    libxml_use_internal_errors(true); // 屏蔽格式警告
    $xml = new DOMDocument();
    $xml->preserveWhiteSpace = false;
    $xml->formatOutput = true;
    $xml->load($manifest_path);

    $xpath = new DOMXPath($xml);
    $xpath->registerNamespace("android", "http://schemas.android.com/apk/res/android");

    // 获取 <application> 标签
    $applications = $xml->getElementsByTagName("application");
    if ($applications->length === 0) {
        return [false, "未找到 <application> 标签"];
    }

    $application = $applications->item(0);

    // 查找 android:name 属性
    $name_attr = null;
    foreach ($application->attributes as $attr) {
        if ($attr->nodeName === "android:name" || $attr->name === "android:name") {
            $name_attr = $attr;
            break;
        }
    }

    // 是否需要修改
    $need_update = false;

    if ($name_attr === null) {
        // 属性不存在，直接添加
        $application->setAttribute("android:name", $class_name);
        $need_update = true;
        echo "添加android:name属性\n";
    } else {
        $current = trim($name_attr->value);
        echo "android:name属性:{$current}\n";
        if ($current === '' || $current === 'android.app.Application') {
            // 属性为空或是默认类名，设置新值
            echo "设置android:name属性:{$class_name}\n";
            $name_attr->value = $class_name;
            $need_update = true;
        }
    }

    // 保存文件
    if ($need_update) {
        $xml->save($manifest_path);
        return [true, "已设置 android:name 为：$class_name"];
    } else {
        return [true, "已有有效 android:name，无需修改"];
    }
}


//Application基类替换
function replace_application_super($target_file_path, $new_super_class_name, $apk_root_dir = null, $file = true) {
    if (!is_file($target_file_path) || pathinfo($target_file_path, PATHINFO_EXTENSION) !== 'smali') {
        return [false, "不是有效的 smali 文件", null, null];
    }
    
    // 将 Java 类名（com.xxx.HookApplication;）转为 smali 路径
    $class_name = rtrim($new_super_class_name, ';');
    $class_path = str_replace('.', '/', $class_name) . '.smali';
if($file){
    // 获取 apk 根目录（根据 smali 文件路径推算或传入）
    if (!$apk_root_dir) {
        $apk_root_dir = explode(DIRECTORY_SEPARATOR, $target_file_path);
        while (count($apk_root_dir)) {
            $path = implode(DIRECTORY_SEPARATOR, $apk_root_dir);
            if (is_dir($path) && preg_match('/smali(_classes\d+)?$/', basename($path))) {
                array_pop($apk_root_dir); // 去掉 smali_classesX
                break;
            }
            array_pop($apk_root_dir);
        }
        $apk_root_dir = implode(DIRECTORY_SEPARATOR, $apk_root_dir);
    }

    // 遍历 smali 目录查找新的父类文件
    $smali_dirs = [];
    foreach (scandir($apk_root_dir) as $entry) {
        if (preg_match('/^smali(_classes\d+)?$/', $entry) && is_dir($apk_root_dir . DIRECTORY_SEPARATOR . $entry)) {
            $smali_dirs[] = $apk_root_dir . DIRECTORY_SEPARATOR . $entry;
        }
    }

    $hook_file_found = false;
    $hook_file_path = null;
    $hook_is_app = false;

    foreach ($smali_dirs as $dir) {
        $full_path = $dir . DIRECTORY_SEPARATOR . $class_path;
        if (file_exists($full_path)) {
            $hook_file_found = true;
            $hook_file_path = $full_path;
            $lines = file($full_path);
            foreach ($lines as $line) {
                if (preg_match('/^\.super\s+(L[^;]+;)/', trim($line), $match)) {
                    if ($match[1] === 'Landroid/app/Application;') {
                        $hook_is_app = true;
                    }
                    break;
                }
            }
            break;
        }
    }

    if (!$hook_file_found) {
        return [false, "指定的新父类类文件不存在", null, null];
    }

    if (!$hook_is_app) {
        return [false, "新父类不是 Application 子类，不允许替换", null, null];
    }
}
    // 开始替换目标文件中的 .super
    $lines = file($target_file_path);
    $modified = false;
    $original_super = null;
    $new_super_smali = 'L' . str_replace('.', '/', $class_name) . ';';

    foreach ($lines as $index => $line) {
        if (preg_match('/^\.super\s+(L[^;]+;)/', trim($line), $match)) {
            $original_super = $match[1];
            if ($original_super !== $new_super_smali) {
                $lines[$index] = ".super $new_super_smali\n";
                $modified = true;
            }
            break;
        }
    }

    if ($modified) {
        file_put_contents($target_file_path, implode('', $lines));
        return [true, "替换成功", $original_super, $new_super_smali];
    } else {
        return [true, "无需修改，.super 已是目标类", $original_super, $new_super_smali];
    }
}



//基类读取
function get_application_inheritance_chain($apk_dir, $super = 'Landroid/app/Application;', $storey = true) {
    $manifest_path = rtrim($apk_dir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'AndroidManifest.xml';
    if (!$super) {
        $super = 'Landroid/app/Application;';
    }
    if (!file_exists($manifest_path)) {
        return ['state'=>1,'error' => "Manifest 文件不存在：$manifest_path"];
    }

    // 读取 AndroidManifest 内容
    $content = file_get_contents($manifest_path);

    // 提取 application 的 android:name
    if (!preg_match('/<application[^>]*android:name="([^"]+)"/', $content, $match)) {
        return ['state'=>2,'error' => "未找到 application 的 android:name 属性"];
    }

    $class_name = $match[1];

    // 处理相对类名（.MyApp）拼接包名
    if (substr($class_name, 0, 1) === '.') {
        if (preg_match('/<manifest[^>]*package="([^"]+)"/', $content, $pkg_match)) {
            $class_name = $pkg_match[1] . $class_name;
        }
    }

    // 构造链路
    $chain = build_class_chain($apk_dir, $class_name, $super, $storey);

    // 获取最终基类名称
    $final = get_last_node($chain);
    $depth = get_chain_depth($chain);
    return [
        'final_super' => $final['super'],
        'class'       => $final['class'] ?? $class_name,
        'file'        => $final['file'] ?? null,
        'depth'       => $depth,
        'chain'       => $chain
    ];
}
//基类链路查找
function build_class_chain($apk_dir, $class_name, $stop_super = 'Landroid/app/Application;', $storey = true) {
    

    $class_path = str_replace('.', '/', ltrim($class_name, '.')) . '.smali';

    $smali_dirs = [];
    foreach (scandir($apk_dir) as $entry) {
        if (preg_match('/^smali(_classes\d+)?$/', $entry) && is_dir($apk_dir . DIRECTORY_SEPARATOR . $entry)) {
            $smali_dirs[] = $apk_dir . DIRECTORY_SEPARATOR . $entry;
        }
    }

    foreach ($smali_dirs as $smali_dir) {
        $full_path = $smali_dir . DIRECTORY_SEPARATOR . $class_path;
        if (file_exists($full_path)) {
            $lines = file($full_path);
            foreach ($lines as $line) {
                if (preg_match('/^\.super\s+(L[^;]+;)/', trim($line), $super_match)) {
                    $super_smali = $super_match[1];

                    $result = [
                        'class' => $class_name,
                        'super' => $super_smali,
                        'file'  => $full_path,
                        'extends' => null
                    ];

                    // 仅当 storey 为 true 且 super 不等于 stop_super 时才递归
                    if ($storey && $super_smali !== $stop_super) {
                        $super_java = str_replace('/', '.', substr($super_smali, 1, -1));
                        $result['extends'] = build_class_chain($apk_dir, $super_java, $stop_super, $storey);
                    }

                    return $result;
                }
            }

            return [
                'class' => $class_name,
                'super' => null,
                'file'  => $full_path,
                'extends' => null,
                'error' => '.super 未找到'
            ];
        }
    }

    return [
        'class' => $class_name,
        'super' => null,
        'file'  => null,
        'extends' => null,
        'error' => '类文件未找到'
    ];
}

function get_last_node($chain) {
    while ($chain && isset($chain['extends']) && $chain['extends']) {
        $chain = $chain['extends'];
    }
    return $chain;
}
function get_chain_depth($chain) {
    $depth = 0;
    while ($chain) {
        $depth++;
        $chain = $chain['extends'] ?? null;
    }
    return $depth;
}








//smali文件融合
function replace_smali_string($dir, $search, $replace, $case_sensitive = true) {
    $result = [];

    if (!is_dir($dir)) {
        echo "无效的目录：$dir\n";
        return $result;
    }

    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir));
    foreach ($iterator as $file) {
        if (!$file->isFile() || pathinfo($file, PATHINFO_EXTENSION) !== 'smali') {
            continue;
        }

        $file_path = $file->getPathname();
        $lines = file($file_path); // 逐行读取
        $modified = false;
        $new_lines = [];

        foreach ($lines as $index => $line) {
            $original_line = $line;

            if ($case_sensitive) {
                if (strpos($line, $search) !== false) {
                    $new_line = str_replace($search, $replace, $line);
                    if ($new_line !== $line) {
                        $result[] = [
                            'file' => $file_path,
                            'line' => $index + 1,
                            'original' => rtrim($line),
                            'modified' => rtrim($new_line)
                        ];
                        $line = $new_line;
                        $modified = true;
                    }
                }
            } else {
                if (preg_match('/' . preg_quote($search, '/') . '/i', $line)) {
                    $new_line = preg_replace('/' . preg_quote($search, '/') . '/i', $replace, $line);
                    if ($new_line !== $line) {
                        $result[] = [
                            'file' => $file_path,
                            'line' => $index + 1,
                            'original' => rtrim($line),
                            'modified' => rtrim($new_line)
                        ];
                        $line = $new_line;
                        $modified = true;
                    }
                }
            }

            $new_lines[] = $line;
        }

        if ($modified) {
            file_put_contents($file_path, implode('', $new_lines));
        }
    }

    return $result;
}


//签名APK，旧方法，存在shell注入风险
/*function sign_apk($keystore, $alias, $storepass, $keypass, $unsigned_apk, $signed_apk = null, $output_folder = null, $apksigner_path = 'apksigner') {
    if (!file_exists($unsigned_apk)) {
        $msg = "未找到待签名 APK 文件：$unsigned_apk";
        echo "$msg\n";
        return [false, $msg, null, null];
    }

    if (!file_exists($keystore)) {
        $msg = "签名文件不存在：$keystore";
        echo "$msg\n";
        return [false, $msg, null, null];
    }

    // 自动生成签名后 APK 名称
    if (empty($signed_apk)) {
        $signed_apk = preg_replace('/\.apk$/', '.signed.apk', $unsigned_apk);
    }

    // 构造签名命令
    $cmd = "java -jar \"$apksigner_path\" sign " .
       "--min-sdk-version 11 " .
       "--ks \"$keystore\" " .
       "--ks-key-alias $alias " .
       "--ks-pass pass:$storepass " .
       "--key-pass pass:$keypass " .
       "--v1-signing-enabled true " .
       "--v2-signing-enabled true " .
       "--v3-signing-enabled true " .
       "--out \"$signed_apk\" \"$unsigned_apk\"";
    if($apksigner_path == 'apksigner'){
        $cmd = "\"$apksigner_path\" sign " .
       "--ks \"$keystore\" " .
       "--ks-key-alias $alias " .
       "--ks-pass pass:$storepass " .
       "--key-pass pass:$keypass " .
       "--v1-signing-enabled true " .
       "--v2-signing-enabled true " .
       "--v3-signing-enabled true " .
       "--out \"$signed_apk\" \"$unsigned_apk\"";
    }
    
    echo "执行签名命令：$cmd\n";

    // 执行命令
    $output = shell_exec($cmd);
    //echo "签名输出：\n$output\n";

    // 判断签名结果
    if (file_exists($signed_apk)) {
        $msg = "签名完成：$signed_apk";
        //echo "$msg\n";

        // 删除未签名 APK
        unlink($unsigned_apk);
        echo "已删除未签名文件：$unsigned_apk\n";

        // 提示删除反编译目录（不自动执行）
        if (!empty($output_folder)) {
            delete_dir($output_folder);
            //echo "请手动清理反编译目录：$output_folder\n";
        }

        return [true, $msg, $signed_apk, $output];
    } else {
        $msg = "❌ 签名失败，未生成文件。";
        echo "$msg\n";
        return [false, $msg, null, $output];
    }
}*/

function sign_apk($keystore, $alias, $storepass, $keypass, $unsigned_apk, $signed_apk = null, $output_folder = null, $apksigner_path = 'apksigner') {

    // 检查文件
    if (!file_exists($unsigned_apk)) {
        $msg = "未找到待签名 APK 文件：$unsigned_apk";
        return [false, $msg, null, null];
    }

    if (!file_exists($keystore)) {
        $msg = "签名文件不存在：$keystore";
        return [false, $msg, null, null];
    }

    // 自动生成签名后 APK 名称
    if (empty($signed_apk)) {
        $signed_apk = preg_replace('/\.apk$/', '.signed.apk', $unsigned_apk);
    }

    // -----------------------------
    // 🚨 核心修复点：所有参数严格转义
    // -----------------------------
    $keystore_arg     = escapeshellarg($keystore);
    $alias_arg        = escapeshellarg($alias);
    $storepass_arg    = escapeshellarg("pass:$storepass");
    $keypass_arg      = escapeshellarg("pass:$keypass");
    $unsigned_arg     = escapeshellarg($unsigned_apk);
    $signed_arg       = escapeshellarg($signed_apk);
    $apksigner_arg    = escapeshellarg($apksigner_path); // jar 文件或命令名

    // 构造签名命令（安全版）
    if ($apksigner_path === 'apksigner') {
        // 系统内置 apksigner 版本
        $cmd = "$apksigner_arg sign "
             . "--ks $keystore_arg "
             . "--ks-key-alias $alias_arg "
             . "--ks-pass $storepass_arg "
             . "--key-pass $keypass_arg "
             . "--v1-signing-enabled true "
             . "--v2-signing-enabled true "
             . "--v3-signing-enabled true "
             . "--out $signed_arg $unsigned_arg";
    } else {
        // 自定义 jar 包版本
        $cmd = "java -jar $apksigner_arg sign "
             . "--min-sdk-version 11 "
             . "--ks $keystore_arg "
             . "--ks-key-alias $alias_arg "
             . "--ks-pass $storepass_arg "
             . "--key-pass $keypass_arg "
             . "--v1-signing-enabled true "
             . "--v2-signing-enabled true "
             . "--v3-signing-enabled true "
             . "--out $signed_arg $unsigned_arg";
    }

    echo "执行签名命令：$cmd\n";

    // 执行命令，捕获 stdout + stderr
    $output = shell_exec($cmd . ' 2>&1');

    // 判断签名是否成功
    if (!file_exists($signed_apk)) {
        $msg = "签名失败，未生成文件。输出：" . $output;
        return [false, $msg, null, $output];
    }

    // 验证签名后 APK 完整性（防止 ZIP 结构损坏的 APK 被误判为成功）
    if ($apksigner_path !== 'apksigner') {
        $verify_cmd = "java -jar " . escapeshellarg($apksigner_path) . " verify " . escapeshellarg($signed_apk) . " 2>&1";
    } else {
        $verify_cmd = escapeshellarg($apksigner_path) . " verify " . escapeshellarg($signed_apk) . " 2>&1";
    }
    $verify_output = shell_exec($verify_cmd);
    $verify_exit = 0;
    exec($verify_cmd, $dummy, $verify_exit);
    if ($verify_exit !== 0) {
        $msg = "签名后验证失败，APK 可能损坏。验证输出：" . $verify_output;
        @unlink($signed_apk); // 删除损坏的 APK
        return [false, $msg, null, $verify_output];
    }

    $msg = "签名完成：$signed_apk";

    // 删除未签名文件
    @unlink($unsigned_apk);

    // 删除反编译目录
    if (!empty($output_folder)) {
        delete_dir($output_folder);
    }

    return [true, $msg, $signed_apk, $output];
}

/**
 * 验证签名后的 APK 是否完整可安装
 * 检测项：aapt dump badging 解析 Manifest + DEX 文件存在性
 * @param string $apkPath 签名后的 APK 路径
 * @return array [bool 是否通过, string 信息, array 详情]
 */
function verify_apk_installable($apkPath) {
    $errors = [];

    // 1. 文件存在性和大小检查
    if (!file_exists($apkPath)) {
        return [false, "APK 文件不存在：{$apkPath}", ['file_missing' => true]];
    }
    $fileSize = filesize($apkPath);
    if ($fileSize < 10240) { // 小于 10KB 基本不可能是有效 APK
        $errors[] = "APK 文件异常小（{$fileSize} 字节），可能损坏";
    }

    // 2. aapt dump badging 验证 Manifest 可解析
    $aaptCmd = "aapt dump badging " . escapeshellarg($apkPath) . " 2>&1";
    $aaptOutput = shell_exec($aaptCmd);
    $packageName = null;
    $versionName = null;

    if (empty($aaptOutput) || stripos($aaptOutput, 'error:') !== false || stripos($aaptOutput, 'ERROR') !== false) {
        // aapt 失败，尝试 aapt2
        $aapt2Cmd = "aapt2 dump badging " . escapeshellarg($apkPath) . " 2>&1";
        $aaptOutput = shell_exec($aapt2Cmd);
        if (empty($aaptOutput) || stripos($aaptOutput, 'error:') !== false || stripos($aaptOutput, 'ERROR') !== false) {
            $errors[] = "aapt/aapt2 无法解析 APK Manifest，输出：" . substr($aaptOutput ?? '', 0, 500);
        }
    }

    // 提取包名和版本信息
    if ($aaptOutput && preg_match("/package: name='([^']+)'.*?versionCode='([^']*)'.*?versionName='([^']*)'/", $aaptOutput, $m)) {
        $packageName = $m[1];
        $versionName = $m[3];
        echo "APK 验证：包名={$packageName}，版本={$versionName}\n";
    } else if (empty($errors)) {
        $errors[] = "无法从 APK 中提取包名/版本信息";
    }

    // 3. 检查 DEX 文件存在性（用 python3 zipfile 模块，容器内可能没有 unzip/jar）
    $dexCmd = "python3 -c \"import zipfile,sys;z=zipfile.ZipFile(sys.argv[1]);print(sum(1 for n in z.namelist() if n.endswith('.dex')))\" " . escapeshellarg($apkPath) . " 2>&1";
    $dexCount = intval(trim(shell_exec($dexCmd)));
    if ($dexCount === 0) {
        $errors[] = "APK 中未找到任何 DEX 文件";
    } else {
        echo "APK 验证：包含 {$dexCount} 个 DEX 文件\n";
    }

    if (!empty($errors)) {
        $msg = "APK 完整性检测失败：" . implode('；', $errors);
        echo $msg . "\n";
        return [false, $msg, ['errors' => $errors, 'package' => $packageName, 'size' => $fileSize]];
    }

    $msg = "APK 完整性检测通过（{$packageName} v{$versionName}，{$dexCount}个DEX，" . round($fileSize / 1024 / 1024, 2) . "MB）";
    echo $msg . "\n";
    return [true, $msg, ['package' => $packageName, 'version' => $versionName, 'dex_count' => $dexCount, 'size' => $fileSize]];
}

//回编译
/*function rebuild_apk($apktool_path, $decode_folder, $output_apk = null, $xmx = "512M") {
    if (!file_exists($apktool_path)) {
        $msg = "找不到 apktool 工具：$apktool_path";
        echo "$msg\n";
        return [false, $msg, null, null];
    }

    if (!is_dir($decode_folder)) {
        $msg = "反编译目录不存在：$decode_folder";
        echo "$msg\n";
        return [false, $msg, null, null];
    }

    // 如果未指定输出 APK 路径，则自动生成
    if (empty($output_apk)) {
        $parent_dir = dirname($decode_folder);
        $folder_name = basename($decode_folder);
        $output_apk = $parent_dir . DIRECTORY_SEPARATOR . $folder_name . '.build.apk';
    }

    // 构造打包命令
    $cmd = "nice -n 19 java -Xmx{$xmx} -jar \"$apktool_path\" b \"$decode_folder\" -o \"$output_apk\" 2>&1";
    echo "开始回编译打包\n";

    // 执行命令
    $output = shell_exec($cmd);
    echo "rebuild_apk回编译输出：\n$output\n";
    $errorText = parseApktoolWarningsAsText($output);
    // 检查输出文件
    if (file_exists($output_apk)) {
        $msg = "APK 回编译成功，输出文件：$output_apk";
        echo "$msg\n";
        return [true, $msg, $output_apk, $output];
    } else {
        $msg = "回编译失败，未生成 APK 文件。";
        echo "$msg\n";
        return [false, $msg, $output_apk, $output, $errorText];
    }
}*/
//20260301修复RCE漏洞
function rebuild_apk($apktool_path, $decode_folder, $output_apk = null, $xmx = "512M") {

    if (!file_exists($apktool_path)) {
        $msg = "找不到 apktool 工具：$apktool_path";
        echo "$msg\n";
        return [false, $msg, null, null];
    }

    if (!is_dir($decode_folder)) {
        $msg = "反编译目录不存在：$decode_folder";
        echo "$msg\n";
        return [false, $msg, null, null];
    }

    if (empty($output_apk)) {
        $parent_dir = dirname($decode_folder);
        $folder_name = basename($decode_folder);
        $output_apk = $parent_dir . DIRECTORY_SEPARATOR . $folder_name . '.build.apk';
    }

    // 限制 xmx 只能是数字+M
    if (!preg_match('/^\d+M$/', $xmx)) {
        return [false, "非法的Xmx参数", null, null];
    }

    $javaCmd = 'java';
    $niceCmd = 'nice';

    $cmd = sprintf(
        '%s -n 19 %s -Xmx%s -jar %s b %s -o %s 2>&1',
        escapeshellcmd($niceCmd),
        escapeshellcmd($javaCmd),
        $xmx, // 已经过正则验证
        escapeshellarg($apktool_path),
        escapeshellarg($decode_folder),
        escapeshellarg($output_apk)
    );

    echo "开始回编译打包\n";

    $output = shell_exec($cmd);
    echo "rebuild_apk回编译输出：\n$output\n";

    $errorText = parseApktoolWarningsAsText($output);

    if (file_exists($output_apk)) {
        $msg = "APK 回编译成功，输出文件：$output_apk";
        echo "$msg\n";
        return [true, $msg, $output_apk, $output];
    } else {
        $msg = "回编译失败，未生成 APK 文件。";
        echo "$msg\n";
        return [false, $msg, $output_apk, $output, $errorText];
    }
}

//20250618修复路径匹配规则错误的问题
function parseApktoolWarningsAsText(string $log): string {
    $lines = explode("\n", $log);
    $result = '';

    foreach ($lines as $line) {
        $line = trim($line);
        if (strpos($line, 'W:') !== 0) continue;

        // 修改后的正则，更宽松匹配 res 路径
        if (preg_match('#W:\s+.+?(\/res\/[^:]+):(\d+):(.+)$#', $line, $matches)) {
            $filePath = $matches[1];
            $lineNum  = $matches[2];
            $message  = trim($matches[3]);

            $result .= $filePath . ':' . $lineNum . ': ' . $message . "\n";
        }
    }

    return $result;
}


function optimizeApkWithAapt2($apkPath) {
    if (!is_file($apkPath)) {
        return [false, 'APK文件不存在'];
    }

    $aapt2Path = 'aapt2'; // 假设 aapt2 已加入系统环境变量
    $outputPath = preg_replace('/\.apk$/', '.opt.apk', $apkPath);

    // 构建命令
    $cmd = "$aapt2Path optimize --collapse-resource-names -o " . escapeshellarg($outputPath) . ' ' . escapeshellarg($apkPath) . " 2>&1";

    exec($cmd, $output, $code);

    if ($code === 0 && file_exists($outputPath)) {
        return [true, $outputPath];
    } else {
        return [false, implode("\n", $output)];
    }
}



function merge_smali_directories($source_dir, $target_dir) {
    // 1. 获取源目录中所有 smali 和 smali_* 目录
    $smali_dirs = [];
    foreach (scandir($source_dir) as $entry) {
        if (preg_match('/^smali(_classes\d+)?$/', $entry) && is_dir($source_dir . DIRECTORY_SEPARATOR . $entry)) {
            $smali_dirs[] = $entry;
        }
    }

    // 2. 获取目标目录已有的最大 smali_classesN 序号
    $existing = [];
    foreach (scandir($target_dir) as $entry) {
        if (preg_match('/^smali(_classes(\d+))?$/', $entry, $m) && is_dir($target_dir . DIRECTORY_SEPARATOR . $entry)) {
            $existing[] = isset($m[2]) ? intval($m[2]) : 1; // smali 视为 classes1
        }
    }
    $max_index = empty($existing) ? 0 : max($existing);

    // 3. 依次复制，每个递增命名
    foreach ($smali_dirs as $dir_name) {
        $src_path = $source_dir . DIRECTORY_SEPARATOR . $dir_name;
        $new_index = ++$max_index;
        $dst_name = $new_index === 1 ? 'smali' : 'smali_classes' . $new_index;
        $dst_path = $target_dir . DIRECTORY_SEPARATOR . $dst_name;

        // 递归复制目录
        recursive_copy($src_path, $dst_path);
        echo "已复制 $dir_name 到 $dst_name\n";
    }

    echo "smali 融合完成。\n";
    return true;
}

// 工具函数：递归复制目录内容
function recursive_copy($src, $dst) {
    if (!is_dir($src)) return;
    if (!file_exists($dst)) mkdir($dst, 0777, true);

    $items = scandir($src);
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') continue;
        $src_item = $src . DIRECTORY_SEPARATOR . $item;
        $dst_item = $dst . DIRECTORY_SEPARATOR . $item;

        if (is_dir($src_item)) {
            recursive_copy($src_item, $dst_item);
        } else {
            copy($src_item, $dst_item);
        }
    }
}




//AndroidManifest融合
function merge_android_manifests($source_dir, $target_dir, $intent = false) {
    $src_manifest = rtrim($source_dir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'AndroidManifest.xml';
    $dst_manifest = rtrim($target_dir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'AndroidManifest.xml';

    if (!file_exists($src_manifest) || !file_exists($dst_manifest)) {
        echo "Manifest 文件不存在\n";
        return false;
    }

    $src_xml = file_get_contents($src_manifest);
    $dst_xml = file_get_contents($dst_manifest);

    // 1. 提取 <uses-permission>
    preg_match_all('/<uses-permission[^>]+\/>/', $src_xml, $src_permissions);
    preg_match_all('/<uses-permission[^>]+\/>/', $dst_xml, $dst_permissions);
    $src_permissions = array_unique($src_permissions[0]);
    $dst_permissions_text = implode("\n", $dst_permissions[0]);

    // 2. 提取 <permission>
    preg_match_all('/<permission[^>]+\/>/', $src_xml, $src_custom_permissions);
    preg_match_all('/<permission[^>]+\/>/', $dst_xml, $dst_custom_permissions);
    $src_custom_permissions = array_unique($src_custom_permissions[0]);
    $dst_custom_permissions_text = implode("\n", $dst_custom_permissions[0]);

    // 3. 提取 <activity> 和 <activity-alias>
    preg_match_all('/<activity\b[^>]*>.*?<\/activity>/is', $src_xml, $src_activities);
    preg_match_all('/<activity-alias\b[^>]*>.*?<\/activity-alias>/is', $src_xml, $src_aliases);
    $src_activities = $src_activities[0];
    $src_aliases = $src_aliases[0];
    $all_activities = array_merge($src_activities, $src_aliases);

    // 4. 处理 Activity，仅保留一个入口
    $entry_found = false;
    $processed_activities = [];

    foreach ($all_activities as $block) {
        if (!$entry_found && preg_match('/<intent-filter>.*?MAIN.*?LAUNCHER.*?<\/intent-filter>/is', $block)) {
            $processed_activities[] = "<!-- 此 activity 来自插入 -->\n" . $block;
            $entry_found = true;
        } else {
            $block_no_entry = preg_replace('/<intent-filter>.*?<\/intent-filter>/is', '', $block);
            $processed_activities[] = "<!-- 此 activity 来自插入 -->\n" . $block_no_entry;
        }
    }

    // 5. 注释原 manifest 中的 intent-filter 启动项
    if($intent){
        $dst_xml = preg_replace_callback(
            '/(<(activity|activity-alias)\b[^>]*>)(.*?<intent-filter>.*?<\/intent-filter>)(.*?<\/\2>)/is',
            function ($matches) {
                if (preg_match('/android.intent.action.MAIN/', $matches[3]) &&
                    preg_match('/android.intent.category.LAUNCHER/', $matches[3])) {
                    $commented = "<!-- 此处为原启动入口，intent-filter 已被注释 -->\n";
                    $commented .= preg_replace('/(<intent-filter>.*?<\/intent-filter>)/is', '<!-- $1 -->', $matches[3]);
                    return $matches[1] . "\n" . $commented . "\n" . $matches[4];
                }
                return $matches[0];
            },
            $dst_xml
        );
    }
    // 6. 插入 <permission>（自定义权限），避免重复
    foreach ($src_custom_permissions as $perm_def) {
        if (strpos($dst_custom_permissions_text, $perm_def) === false) {
            $insert = "    <!-- 此自定义权限来自插入 -->\n    $perm_def";
            //$dst_xml = preg_replace('/(<manifest[^>]*>)/', "$1\n$insert", $dst_xml);//插入后会导致无法安装
        }
    }

    // 7. 插入 <uses-permission>（跳过重复）
    foreach ($src_permissions as $perm) {
        if (strpos($dst_permissions_text, $perm) === false) {
            $insert = "    <!-- 此权限来自插入 -->\n    $perm";
            $dst_xml = preg_replace('/(<manifest[^>]*>)/', "$1\n$insert", $dst_xml);
        }
    }

    // 8. 插入 <activity> 和 <activity-alias> 到 <application> 中
    $insert_block = implode("\n    ", $processed_activities);
    $dst_xml = preg_replace_callback(
        '/<application[^>]*>/',
        function ($matches) use ($insert_block) {
            return $matches[0] . "\n    " . $insert_block;
        },
        $dst_xml,
        1
    );

    // 9. 保存结果
    file_put_contents($dst_manifest, $dst_xml);
    echo "合并完成：权限、自定义权限、Activity 合并，并保留注释信息：$dst_manifest\n";
    return true;
}





//找启动入口类名
function parse_apk_manifests($dirs) {
    $results = [];

    foreach ($dirs as $dir) {
        $manifest_path = rtrim($dir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'AndroidManifest.xml';

        if (!file_exists($manifest_path)) {
            $results[] = [false, null, null, "Manifest 文件不存在：$manifest_path"];
            continue;
        }

        // 加载 XML
        $xml = new DOMDocument();
        libxml_use_internal_errors(true); // 忽略格式警告
        $xml->load($manifest_path);

        $xpath = new DOMXPath($xml);
        $launcher_activity = null;
        $source = null;

        // 查找所有 <activity>
        $activities = $xpath->query('//activity');
        foreach ($activities as $activity) {
            $intent_filters = $activity->getElementsByTagName('intent-filter');
            foreach ($intent_filters as $filter) {
                $has_main = false;
                $has_launcher = false;

                foreach ($filter->getElementsByTagName('action') as $action) {
                    if ($action->getAttribute('android:name') === 'android.intent.action.MAIN') {
                        $has_main = true;
                    }
                }

                foreach ($filter->getElementsByTagName('category') as $category) {
                    if ($category->getAttribute('android:name') === 'android.intent.category.LAUNCHER') {
                        $has_launcher = true;
                    }
                }

                if ($has_main && $has_launcher) {
                    $launcher_activity = $activity->getAttribute('android:name');
                    $source = 'activity';
                    break 2;
                }
            }
        }

        // 如果未找到，再查找 <activity-alias>
        if (!$launcher_activity) {
            $aliases = $xpath->query('//activity-alias');
            foreach ($aliases as $alias) {
                $intent_filters = $alias->getElementsByTagName('intent-filter');
                foreach ($intent_filters as $filter) {
                    $has_main = false;
                    $has_launcher = false;

                    foreach ($filter->getElementsByTagName('action') as $action) {
                        if ($action->getAttribute('android:name') === 'android.intent.action.MAIN') {
                            $has_main = true;
                        }
                    }

                    foreach ($filter->getElementsByTagName('category') as $category) {
                        if ($category->getAttribute('android:name') === 'android.intent.category.LAUNCHER') {
                            $has_launcher = true;
                        }
                    }

                    if ($has_main && $has_launcher) {
                        $launcher_activity = $alias->getAttribute('android:targetActivity');
                        $source = 'activity-alias';
                        break 2;
                    }
                }
            }
        }

        if ($launcher_activity !== null) {
            $results[] = [true, $launcher_activity, $source, null];
        } else {
            $results[] = [false, null, null, "未找到启动 Activity：$manifest_path"];
        }
    }

    return $results;
}



/*function zipalign_apk($apk_path) {
    if (!file_exists($apk_path)) {
        return [false, 'APK 文件不存在'];
    }

    // 获取输出路径（在原文件同目录，添加 .aligned.apk 后缀）
    $dir = dirname($apk_path);
    $filename = basename($apk_path, '.apk');
    $aligned_apk = $dir . DIRECTORY_SEPARATOR . $filename . '.aligned.apk';

    // 执行 zipalign 命令
    $cmd = "nice -n 19 zipalign -f 4 \"$apk_path\" \"$aligned_apk\" 2>&1";
    $output = shell_exec($cmd);

    // 检查对齐文件是否创建成功
    if (file_exists($aligned_apk)) {
        return [true, $aligned_apk];
    } else {
        return [false, "zipalign 执行失败：" . $output];
    }
}*/
//20260301修复RCE漏洞
function zipalign_apk($apk_path) {

    if (!file_exists($apk_path)) {
        return [false, 'APK 文件不存在'];
    }

    // 规范路径，防止奇怪输入
    $apk_path = realpath($apk_path);
    if ($apk_path === false) {
        return [false, '非法APK路径'];
    }

    $dir = dirname($apk_path);
    $filename = pathinfo($apk_path, PATHINFO_FILENAME);
    $aligned_apk = $dir . DIRECTORY_SEPARATOR . $filename . '.aligned.apk';

    $cmd = sprintf(
        '%s -n 19 %s -f 4 %s %s 2>&1',
        escapeshellcmd('nice'),
        escapeshellcmd('zipalign'),
        escapeshellarg($apk_path),
        escapeshellarg($aligned_apk)
    );

    $output = shell_exec($cmd);

    if (file_exists($aligned_apk)) {
        return [true, $aligned_apk];
    } else {
        return [false, "zipalign 执行失败：" . $output];
    }
}


// 方法：反编译 APK
/*function decompile_apks($apktool_jar, $apk_files, $output_base_dir = null) {
    $results = [];

    // 检查所有 APK 文件是否存在
    foreach ($apk_files as $apk_file) {
        if (!file_exists($apk_file)) {
            return [[false, "APK 文件不存在：$apk_file", null, null]];
        }
    }

    // 遍历每个 APK 执行反编译
    foreach ($apk_files as $apk_file) {
        // 生成默认输出目录
        $apk_dir = dirname($apk_file);
        $apk_name = pathinfo($apk_file, PATHINFO_FILENAME);
        $output_dir = ($output_base_dir ?? $apk_dir) . DIRECTORY_SEPARATOR . $apk_name;

        // 构造反编译命令
        //$cmd = "nice -n 19 ionice -c2 -n7 java -jar \"$apktool_jar\" d -r \"$apk_file\" -o \"$output_dir\" -f 2>&1";//不反编译资源
        $cmd = "nice -n 19 java -jar \"$apktool_jar\" d --no-res --no-src \"$apk_file\" -o \"$output_dir\" -f 2>&1";//不反编译资源和dex

        //$cmd = "nice -n 19 ionice -c2 -n7 java -jar \"$apktool_jar\" d \"$apk_file\" -o \"$output_dir\" -f --no-res 2>&1";



        // 执行命令
        $output = shell_exec($cmd);
        echo "反编译执行结果：".$output."\n";
        // 判断是否成功（通过输出目录是否存在判断）
        if (is_dir($output_dir)) {
            $results[] = [true, "反编译成功", $output_dir, $output];
        } else {
            $results[] = [false, "反编译失败", $output_dir, $output];
        }
    }

    return $results;
}*/
//20260301修复RCE漏洞
function decompile_apks($apktool_jar, $apk_files, $output_base_dir = null) {
    $results = [];

    $apktool_jar = realpath($apktool_jar);
    if ($apktool_jar === false || !file_exists($apktool_jar)) {
        return [[false, "apktool 工具不存在", null, null]];
    }

    foreach ($apk_files as $apk_file) {
        $apk_file_real = realpath($apk_file);
        if ($apk_file_real === false || !file_exists($apk_file_real)) {
            return [[false, "APK 文件不存在：$apk_file", null, null]];
        }
    }

    foreach ($apk_files as $apk_file) {
        $apk_file_real = realpath($apk_file);

        $apk_dir = dirname($apk_file_real);
        $apk_name = pathinfo($apk_file_real, PATHINFO_FILENAME);

        $base_dir = $output_base_dir ? realpath($output_base_dir) : $apk_dir;
        if ($base_dir === false) {
            return [[false, "输出目录非法", null, null]];
        }

        $output_dir = $base_dir . DIRECTORY_SEPARATOR . $apk_name;

        $cmd = sprintf(
            '%s -n 19 %s -jar %s d --no-res --no-src %s -o %s -f 2>&1',
            escapeshellcmd('nice'),
            escapeshellcmd('java'),
            escapeshellarg($apktool_jar),
            escapeshellarg($apk_file_real),
            escapeshellarg($output_dir)
        );

        $output = shell_exec($cmd);
        echo "反编译执行结果：" . $output . "\n";

        if (is_dir($output_dir)) {
            $results[] = [true, "反编译成功", $output_dir, $output];
        } else {
            $results[] = [false, "反编译失败", $output_dir, $output];
        }
    }

    return $results;
}


/**
 * 生成随机签名 keystore
 * @param string $outputDir 输出目录
 * @return array|false ['keystore' => 路径, 'alias' => 别名, 'storepass' => 密码, 'keypass' => 密码]
 */
function generateRandomKeystore($outputDir) {
    $alias = 'key' . rand(1000, 9999);
    $password = bin2hex(random_bytes(8));
    $filename = 'random_' . md5(uniqid(mt_rand(), true)) . '.jks';
    $keystorePath = rtrim($outputDir, '/') . '/' . $filename;

    if (!is_dir($outputDir)) {
        mkdir($outputDir, 0755, true);
    }

    $names = ['App', 'Dev', 'Mobile', 'Cloud', 'Tech', 'Soft', 'Net', 'Pro'];
    $cn = $names[array_rand($names)] . rand(100, 999);
    $dname = "CN={$cn},OU=Dev,O={$cn},L=SZ,ST=GD,C=CN";

    $cmd = sprintf(
        'keytool -genkeypair -v -keystore %s -keyalg RSA -keysize 2048 -validity 9125 -alias %s -storepass %s -keypass %s -dname %s 2>&1',
        escapeshellarg($keystorePath),
        escapeshellarg($alias),
        escapeshellarg($password),
        escapeshellarg($password),
        escapeshellarg($dname)
    );

    $output = shell_exec($cmd);

    if (!file_exists($keystorePath)) {
        echo "生成随机签名失败: {$output}\n";
        return false;
    }

    echo "随机签名生成成功: {$keystorePath}\n";
    return [
        'keystore'  => $keystorePath,
        'alias'     => $alias,
        'storepass' => $password,
        'keypass'   => $password,
    ];
}
