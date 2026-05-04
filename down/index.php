<?php
// 获取 GET 参数中的文件 ID
$fileId = $_GET['id'] ?? '';
$fileId = trim($fileId);
$action = $_GET['action'];
// 参数校验
if ($fileId === '') {
    echo json_encode(['code' => 400, 'message' => '缺少文件ID'], JSON_UNESCAPED_UNICODE);
    exit;
}

// 拼接蓝奏云链接
$url = 'https://wwuz.lanzn.com/' . $fileId;

// 调用解析
$lanzou = new LanzouYun();
$result = $lanzou->getSignedUrl($url);

// 成功则跳转，失败则返回JSON
if ($result['code'] === 200 && !empty($result['url'])) {
    if($action == 'json'){
        print_r(json_encode($result,320));
        header('info: ok');
        exit;
    }
    header('Location: ' . $result['url']);
    header('info: ok');
    exit;
} else {
    header('Content-Type: application/json');
    echo json_encode($result, JSON_UNESCAPED_UNICODE);
    exit;
}


class LanzouYun
{
    private static $UserAgent = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/72.0.3626.121 Safari/537.36';
    
    //主方法，调用这个方法，传入蓝奏链接即可
    public static function getSignedUrl($url)
    {
        try {
            // 判断传入链接参数是否为空
            if (empty($url)) {
                return [
                    'code' => 400,
                    'url' => '',
                    'message' => '请输入URL'
                ];
            }

            // 一个简单的链接处理
            $url = 'https://www.lanzoup.com/' . explode('.com/', $url)[1];
            $url = 'https://wwuz.lanzn.com/' . explode('.com/', $url)[1];//20250928修复地址
            $softInfo = self::MloocCurlGet($url);
            
            // 判断文件链接是否失效
            if (strstr($softInfo, "文件取消分享了") !== false) {
                return [
                    'code' => 400,
                    'url' => '',
                    'message' => '文件取消分享了'
                ];
            }

            // 取文件名称、大小
            preg_match('~style="font-size: 30px;text-align: center;padding: 56px 0px 20px 0px;">(.*?)</div>~', $softInfo, $softName);
            if (!isset($softName[1])) {
                preg_match('~<div class="n_box_3fn".*?>(.*?)</div>~', $softInfo, $softName);
            }
            preg_match('~<div class="n_filesize".*?>大小：(.*?)</div>~', $softInfo, $softFilesize);
            if (!isset($softFilesize[1])) {
                preg_match('~<span class="p7">文件大小：</span>(.*?)<br>~', $softInfo, $softFilesize);
            }
            if (!isset($softName[1])) {
                preg_match('~var filename = \'(.*?)\';~', $softInfo, $softName);
            }
            if (!isset($softName[1])) {
                preg_match('~div class="b"><span>(.*?)</span></div>~', $softInfo, $softName);
            }
            //exit($softInfo);
            // 带密码的链接的处理
            if (strstr($softInfo, "function down_p(){") !== false) {
                return [
                    'code' => 400,
                    'url' => '',
                    'message' => '请输入分享密码'
                ];
            } else {
                // 不带密码的链接处理
                preg_match("~\n<iframe.*?name=\"[\s\S]*?\"\ssrc=\"\/(.*?)\"~", $softInfo, $link);
                // 蓝奏云新版页面正则规则
                if (empty($link[1])) {
                    preg_match("~<iframe.*?name=\"[\s\S]*?\"\ssrc=\"\/(.*?)\"~", $softInfo, $link);
                }
                $ifurl = "https://wwuz.lanzn.com/" . $link[1];
                //exit($ifurl);
                $softInfo = self::MloocCurlGet($ifurl);
                //preg_match_all("~'sign':'(.*?)'~", $softInfo, $segment);//旧版
                preg_match_all("~var\s+wp_sign\s*=\s*'(.*?)'~", $softInfo, $segment1);//20250520修复
                preg_match_all("~var\s+ajaxdata\s*=\s*'(.*?)'~", $softInfo, $segment2);//20250520修复
                preg_match_all("~url\s*:\s*'/ajaxm\.php\?file=(\d+)'~", $softInfo, $segment3);
                $post_data = [
                    "websignkey" => $segment2[1][0],
                    "websign" => '',
                    "kd" => 1,
                    "ves" => 1,
                    "action" => 'downprocess',
                    "signs" => $segment2[1][0],
                    "sign" => $segment1[1][0],
                ];
                $softInfo = self::MloocCurlPost($post_data, "https://www.lanzoup.com/ajaxm.php?file={$segment3[1][1]}", $ifurl);
            }
               /* return [
                    'code' => 400,
                    'url' => '',
                    'message' => $segment3[1][1]
                    
                ];*/
            // 其他情况下的信息输出
            $softInfo = json_decode($softInfo, true);
            if ($softInfo['zt'] != 1) {
                return [
                    'code' => 400,
                    'url' => '',
                    'message' => $softInfo['inf']
                    
                ];
            }

            // 拼接链接
            $downUrl1 = $softInfo['dom'] . '/file/' . $softInfo['url'];
            // 解析最终直链地址
            $downUrl2 = self::MloocCurlHead($downUrl1, "https://developer.lanzoug.com", self::$UserAgent, "down_ip=1; expires=Sat, 16-Nov-2019 11:42:54 GMT; path=/; domain=.baidupan.com");
            // 判断最终链接是否获取成功，如未成功则使用原链接
            $downUrl = $downUrl2 == "" ? $downUrl1 : $downUrl2;

            return [
                'code' => 200,
                'url' => $downUrl,
                'message' => '解析成功'
            ];
        } catch (Exception $e) {
            return [
                'code' => 400,
                'url' => '',
                'message' => $e->getMessage()
            ];
        }
    }

    // 获取下载链接函数
    private static function MloocCurlGetDownUrl($url)
    {
        $header = get_headers($url, 1);
        return isset($header['Location']) ? $header['Location'] : "";
    }

    // CURL函数
    private static function MloocCurlGet($url = '', $UserAgent = '')
    {
        $headers = [
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,image/apng,*/*;q=0.8',
            'Accept-Encoding: gzip, deflate',
            'Accept-Language: zh-CN,zh;q=0.9',
            'Cache-Control: no-cache',
            'Connection: keep-alive',
            'Pragma: no-cache',
            'Upgrade-Insecure-Requests: 1',
            'User-Agent: ' . $UserAgent
        ];
        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, $url);
        curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($curl, CURLOPT_REFERER, $guise);
        curl_setopt($curl, CURLOPT_COOKIE, $cookie);
        curl_setopt($curl, CURLOPT_USERAGENT, $UserAgent);
        curl_setopt($curl, CURLOPT_ENCODING, "");//让curl自动完成解压
        curl_setopt($curl, CURLOPT_NOBODY, 0); // 改成0，才能获取正文
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($curl, CURLINFO_HEADER_OUT, true);
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($curl, CURLOPT_TIMEOUT, 10); // 超时10秒
        $data = curl_exec($curl); // 获取正文
        curl_close($curl);
        //echo $data;
        if (preg_match("/var\s+arg1\s*=\s*'([a-fA-F0-9]+)';/", $data, $matches)) {
            $arg1 = $matches[1];
        } else {
            //未获取到arg1的值
            $curl = curl_init();
            curl_setopt($curl, CURLOPT_URL, $url);
            curl_setopt($curl, CURLOPT_FOLLOWLOCATION, 1);
            if ($UserAgent != "") {
                curl_setopt($curl, CURLOPT_USERAGENT, $UserAgent);
            }
            curl_setopt($curl, CURLOPT_HTTPHEADER, ['X-FORWARDED-FOR:' . self::Rand_IP(), 'CLIENT-IP:' . self::Rand_IP()]);
            // 关闭SSL
            curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, false);
            curl_setopt($curl, CURLOPT_COOKIE, $cookie);
            // 返回数据不直接显示
            curl_setopt($curl, CURLOPT_ENCODING, "");//让curl自动完成解压
            curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
            $response = curl_exec($curl);
            curl_close($curl);
            return $response;
        }
        $acw_sc__v2 =  self::reorganizeAndEncrypt($arg1);
        //echo $acw_sc__v2;
        $cookie =  "acw_sc__v2={$acw_sc__v2};" . $cookie;//将计算后的cookie值进行拼接
        
        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, $url);
        curl_setopt($curl, CURLOPT_FOLLOWLOCATION, 1);
        if ($UserAgent != "") {
            curl_setopt($curl, CURLOPT_USERAGENT, $UserAgent);
        }
        curl_setopt($curl, CURLOPT_HTTPHEADER, ['X-FORWARDED-FOR:' . self::Rand_IP(), 'CLIENT-IP:' . self::Rand_IP()]);
        // 关闭SSL
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($curl, CURLOPT_COOKIE, $cookie);
        // 返回数据不直接显示
        curl_setopt($curl, CURLOPT_ENCODING, "");//让curl自动完成解压
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
        $response = curl_exec($curl);
        curl_close($curl);
        return $response;
    }

    // POST函数
    private static function MloocCurlPost($post_data = '', $url = '', $ifurl = '', $UserAgent = '')
    {
        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, $url);
        curl_setopt($curl, CURLOPT_USERAGENT, $UserAgent);
        if ($ifurl != '') {
            curl_setopt($curl, CURLOPT_REFERER, $ifurl);
        }
        curl_setopt($curl, CURLOPT_HTTPHEADER, ['X-FORWARDED-FOR:' . self::Rand_IP(), 'CLIENT-IP:' . self::Rand_IP()]);
        // 关闭SSL
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, false);
        // 返回数据不直接显示
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($curl, CURLOPT_POST, 1);
        curl_setopt($curl, CURLOPT_POSTFIELDS, $post_data);
        $response = curl_exec($curl);
        curl_close($curl);
        return $response;
    }

    private static function MloocCurlBody($url, $guise, $UserAgent, $cookie)
    {
        $headers = [
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,image/apng,*/*;q=0.8',
            'Accept-Encoding: gzip, deflate',
            'Accept-Language: zh-CN,zh;q=0.9',
            'Cache-Control: no-cache',
            'Connection: keep-alive',
            'Pragma: no-cache',
            'Upgrade-Insecure-Requests: 1',
            'User-Agent: ' . $UserAgent
        ];
    
        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, $url);
        curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($curl, CURLOPT_REFERER, $guise);
        curl_setopt($curl, CURLOPT_COOKIE, $cookie);
        curl_setopt($curl, CURLOPT_USERAGENT, $UserAgent);
        curl_setopt($curl, CURLOPT_NOBODY, 0); // 改成0，才能获取正文
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($curl, CURLINFO_HEADER_OUT, true);
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($curl, CURLOPT_TIMEOUT, 10); // 超时10秒
    
        $data = curl_exec($curl); // 获取正文
        curl_close($curl);
        return $data; // 返回正文
    }

    // 直链解析函数
    private static function MloocCurlHead($url, $guise, $UserAgent, $cookie)
    {
        $headers = [
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,image/apng,*/*;q=0.8',
            //'Accept-Encoding: gzip, deflate',
            'Accept-Language: zh-CN,zh;q=0.9',
            'Cache-Control: no-cache',
            'Connection: keep-alive',
            'Pragma: no-cache',
            'Upgrade-Insecure-Requests: 1',
            'User-Agent: ' . $UserAgent
        ];
        /*
        //第一步,先拿到arg1，20250928改造新增第一次访问
        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, $url);
        curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($curl, CURLOPT_REFERER, $guise);
        curl_setopt($curl, CURLOPT_COOKIE, $cookie);
        curl_setopt($curl, CURLOPT_USERAGENT, $UserAgent);
        curl_setopt($curl, CURLOPT_ENCODING, "");//让curl自动完成解压
        curl_setopt($curl, CURLOPT_NOBODY, 0); // 改成0，才能获取正文
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($curl, CURLINFO_HEADER_OUT, true);
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($curl, CURLOPT_TIMEOUT, 10); // 超时10秒
        $data = curl_exec($curl); // 获取正文
        curl_close($curl);
        //echo $data;
        if (preg_match("/var\s+arg1\s*=\s*'([a-fA-F0-9]+)';/", $data, $matches)) {
            $arg1 = $matches[1];
        } else {
            return null;//未获取到arg1的值
        }
        $acw_sc__v2 =  self::reorganizeAndEncrypt($arg1);
        //echo $acw_sc__v2;
        $cookie =  "acw_sc__v2={$acw_sc__v2};" . $cookie;//将计算后的cookie值进行拼接
        */
        
        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, $url);
        curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($curl, CURLOPT_REFERER, $guise);
        curl_setopt($curl, CURLOPT_COOKIE, $cookie);
        curl_setopt($curl, CURLOPT_USERAGENT, $UserAgent);
        curl_setopt($curl, CURLOPT_NOBODY, 1);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($curl, CURLINFO_HEADER_OUT, TRUE);
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
        // 超时设置，默认为10秒
        curl_setopt($curl, CURLOPT_TIMEOUT, 10);
        $data = curl_exec($curl);
        $url = curl_getinfo($curl);
        curl_close($curl);
        return $url["redirect_url"];
    }

    // 随机IP函数
    private static function Rand_IP()
    {
        $ip2id = round(rand(600000, 2550000) / 10000);
        $ip3id = round(rand(600000, 2550000) / 10000);
        $ip4id = round(rand(600000, 2550000) / 10000);
        $arr_1 = ["218", "218", "66", "66", "218", "218", "60", "60", "202", "204", "66", "66", "66", "59", "61", "60", "222", "221", "66", "59", "60", "60", "66", "218", "218", "62", "63", "64", "66", "66", "122", "211"];
        $randarr = mt_rand(0, count($arr_1) - 1);
        $ip1id = $arr_1[$randarr];
        return $ip1id . "." . $ip2id . "." . $ip3id . "." . $ip4id;
    }
    
    //20250928新增算法
    private static function reorganizeAndEncrypt($arg1) {
        // 定义位置映射数组，去除空格
        $mask = base64_decode("MzAwMDE3NjAwMDg1NjAwNjA2MTUwMTUzMzAwMzY5MDAyNzgwMDM3NQ==");// 这个值是蓝奏页面源码中可以找到的
    
        // 这一段值是页面源码中posList的值，从16进制转换为10进制的结果
        $posList = array_map('trim', explode(',', "15, 35, 29, 24, 33, 16, 1, 38, 10, 9, 19, 31, 40, 27, 22, 23, 25, 13, 6, 11, 39, 18, 20, 8, 14, 21, 32, 26, 2, 30, 7, 4, 17, 5, 3, 28, 34, 37, 12, 36"));
        // 构建位置到索引的映射，减少循环次数
        $map = [];
        foreach ($posList as $idx => $pos) {
            $map[(int)$pos] = $idx;
        }
        // 初始化输出数组
        $output = array_fill(0, count($posList), '');
        $len = mb_strlen($arg1, 'UTF-8');
        // 遍历输入字符，重新排序
        for ($i = 1; $i <= $len; $i++) { // 索引从1开始
            if (isset($map[$i])) {
                $targetIndex = $map[$i];
                if ($targetIndex < count($posList)) {
                    $output[$targetIndex] = mb_substr($arg1, $i - 1, 1, 'UTF-8');
                }
            }
        }
        // 生成重排后的字符串
        $rearranged = implode('', $output);
        // 执行异或加密
        $result = '';
        $i = 0;
        $dataLength = strlen($rearranged);
        $maskLength = strlen($mask);
        // 对每对字符进行异或加密
        while ($i < $dataLength && $i < $maskLength) {
            $dataChunkText = substr($rearranged, $i, 2);
            $maskChunkText = substr($mask, $i, 2);
            if ($dataChunkText !== '' && $maskChunkText !== '') {
                $dataChunk = hexdec($dataChunkText);
                $maskChunk = hexdec($maskChunkText);
                $xorResult = $dataChunk ^ $maskChunk;
                // 转换为十六进制并确保两位长度
                $hexResult = dechex($xorResult);
                if (strlen($hexResult) < 2) {
                    $hexResult = '0' . $hexResult;
                }
                $result .= $hexResult;
                $i += 2;
            }
        }
        return strtolower($result);
    }
}

