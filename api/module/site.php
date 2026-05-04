<?php
function getAndroidUrl(PDO $pdo, array $input){
    // 查询最新且启用的版本
    $sql = "SELECT 
                download,
                versionname,
                versioncode,
                newnotice,
                update_time
            FROM cainiao_version
            WHERE enabled = 1
            ORDER BY update_time DESC, versioncode DESC
            LIMIT 1";

    $stmt = $pdo->query($sql);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    // 如果没有查到记录
    if (!$row || empty($row['download'])) {
        throw new Exception("暂无可下载的版本");
    }

    return $row;
}
