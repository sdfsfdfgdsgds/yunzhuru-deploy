<?php

class Lanzou
{
    /**
     * 登录蓝奏云账号
     * @param string $username 蓝奏云账号
     * @param string $password 密码
     * @return array 登录结果，包括 cookie 和用户 ID，失败返回 error 字段
     */
    public static function login($username, $password)
    {
        $url = 'http://up.woozooo.com/mlogin.php';
        $post = [
            'task' => 3,
            'uid' => $username,
            'pwd' => $password,
            'setSessionId' => '',
            'setSig' => '',
            'setScene' => '',
            'setToken' => '',
            'formhash' => ''
        ];

        $res = self::request($url, $post);

        $json = json_decode($res['body'], true);
        if (!$json || !isset($json['zt'])) {
            return ['error' => '接口返回异常'];
        }

        if ($json['zt'] != 1) {
            return ['error' => $json['info']];
        }

        preg_match_all('/Set-Cookie:\s*([^\r\n]+)/i', $res['header'], $matches);
        $cookies = implode('; ', array_map(function ($cookie) {
            return explode(';', $cookie)[0];
        }, $matches[1]));

        return [
            'success' => true,
            'user_id' => $json['id'],
            'cookies' => $cookies
        ];
    }

    /**
     * 检查蓝奏云是否已登录
     * @param string $cookie 登录后的 Cookie
     * @return bool
     */
    public static function checkLogin($cookie)
    {
        $url = 'https://pc.woozooo.com/mydisk.php';
        $res = self::request($url, null, $cookie);
        return strpos($res['body'], 'mydisk.php?item=files&action=index&u=') !== false;
    }

    /**
     * 上传文件到蓝奏云
     * @param string $filePath 本地文件路径
     * @param string $fileName 上传后的文件名称
     * @param string $cookie 登录后的 Cookie
     * @param string $userId 用户ID
     * @param string $folderId 上传目录ID，默认-1
     * @return array 上传结果
     */
    public static function upload($filePath, $fileName, $cookie, $userId, $folderId = '-1')
    {
        if (!file_exists($filePath)) {
            return ['code' => 0, 'msg' => '文件不存在'];
        }

        $url = 'https://pc.woozooo.com/html5up.php';
        $referer = 'https://pc.woozooo.com/mydisk.php?item=files&action=index&u=' . $userId;
        $mimeType = 'application/vnd.android.package-archive';

        $postFields = [
            'task' => '1',
            'vie' => '2',
            've' => '2',
            'id' => 'WU_FILE_1',
            'name' => $fileName,
            'type' => $mimeType,
            'lastModifiedDate' => gmdate('D M d Y H:i:s') . ' GMT+0800 (中国标准时间)',
            'size' => filesize($filePath),
            'folder_id_bb_n' => $folderId,
            'upload_file' => new CURLFile($filePath, $mimeType, $fileName)
        ];

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $postFields,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => false,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_HTTPHEADER => [
                'User-Agent: Mozilla/5.0 (Windows NT 10.0; WOW64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/69.0.3497.100 Safari/537.36',
                'Referer: ' . $referer
            ],
            CURLOPT_COOKIE => $cookie
        ]);

        $response = curl_exec($ch);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            return ['code' => 0, 'msg' => '上传失败：' . $error];
        }

        $json = json_decode($response, true);
        if (isset($json['zt']) && $json['zt'] == 1) {
            return ['code' => 1, 'msg' => '上传成功', 'data' => $json];
        } else {
            return ['code' => 0, 'msg' => '上传失败：' . ($json['info'] ?? '未知错误'), 'data' => $json];
        }
    }

    /**
     * 获取文件夹下的内容
     * @param string $cookie 登录后的 Cookie
     * @param string $userId 用户ID
     * @param string|int $folderId 文件夹 ID，默认 -1
     * @return array 响应内容
     */
    public static function getFolder($cookie, $userId, $folderId = -1)
    {
        $url = "https://up.woozooo.com/doupload.php?uid=" . $userId;
        $postData = [
            'task' => 47,
            'folder_id' => $folderId,
            'vei' => ''
        ];
        $headers = [
            'Origin: https://up.woozooo.com',
            'Content-Type: application/x-www-form-urlencoded; charset=UTF-8'
        ];
        $data = self::request($url, $postData, $cookie, $headers);
        return json_decode($data['body'],true);
    }

    /**
     * 私有请求方法
     * @param string $url 请求地址
     * @param array|string|null $postData POST数据，为null时为GET请求
     * @param string $cookie Cookie字符串
     * @param array $customHeaders 额外自定义请求头
     * @return array 返回数组 ['header' => string, 'body' => string]
     */
    private static function request($url, $postData = null, $cookie = '', $customHeaders = [])
    {
        $ch = curl_init();

        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HEADER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

        $headers = [
            'User-Agent: Mozilla/5.0 (Windows NT 10.0; WOW64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/69.0.3497.100 Safari/537.36'
        ];

        if (!empty($customHeaders) && is_array($customHeaders)) {
            $headers = array_merge($headers, $customHeaders);
        }

        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        if ($cookie) {
            curl_setopt($ch, CURLOPT_COOKIE, $cookie);
        }

        if ($postData !== null) {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, is_array($postData) ? http_build_query($postData) : $postData);
        }

        $response = curl_exec($ch);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $header = substr($response, 0, $headerSize);
        $body = substr($response, $headerSize);
        curl_close($ch);

        return [
            'header' => $header,
            'body' => $body
        ];
    }
}
