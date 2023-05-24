<?php
return [
    // 开启捕获
    "enable"    => function (float $rate) {
        if ($rate < 0.0 || $rate > 1.0) {
            return false;
        }
        $random = random_int(0, PHP_INT_MAX);
        if ($rate < 0.5) {
            $boundary = (int)($rate * PHP_INT_MAX);
        } else {
            $boundary = PHP_INT_MAX - (int)((1 - $rate) * PHP_INT_MAX);
        }
        return $random < $boundary;
    },
    // 扩展配置
    "extension" => "tideways_xhprof",
    // 过滤项目
    "filter"    => [],
    // 缓存配置
    "mongo"     => [
        "dns"      => "mongodb://user:password@host:port/database",
        "database" => "xhprof"
    ],
    // url处理
    "handle"    => function ($url) {
        return preg_replace('/\=\d+/', '', $url);
    },
    // 项目映射
    "project"   => [
        // 项目地址 => [项目名称,唯一标识,采样率]
        "/var/www/html/test" => ["title" => "测试项目", "id" => "test", "rate" => 0.8],
    ],
    "platform"  => [
        // 项目名称和唯一标识,必须和上面的project保持一致
        ["title" => "测试项目", "id" => "results"],
    ]
];