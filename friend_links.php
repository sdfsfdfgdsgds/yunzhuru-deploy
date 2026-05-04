<?php
header('Content-Type: application/json; charset=utf-8');

// 友情链接数据
$data = [
    [
        "title" => "阿里云(8折优惠)",
        "url"   => "https://www.aliyun.com/minisite/goods?userCode=3kqkxkm0",
        "msg"   => "确认前往阿里云官网？"
    ]
];

echo json_encode([
    "code" => 200,
    "message" => "success",
    "data" => $data
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
