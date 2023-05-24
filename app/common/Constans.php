<?php

namespace app\common;

use think\facade\Route;

class Constans {

    public static function menu(array $params): array {
        return [
            ["name" => "最近请求", "active" => !($params["sort"] ?? ""), "link" => Route::buildUrl("/index/index")->build(), "metric" => "rencent"],
            ["name" => "执行时间", "active" => (($params["sort"] ?? "") == "wt"), "link" => Route::buildUrl("/index/index", ["sort" => "wt"])->build(), "metric" => "wt"],
            ["name" => "CPU时间", "active" => (($params["sort"] ?? "") == "cpu"), "link" => Route::buildUrl("/index/index", ["sort" => "cpu"])->build(), "metric" => "cpu"],
            ["name" => "内存占用", "active" => (($params["sort"] ?? "") == "mu"), "link" => Route::buildUrl("/index/index", ["sort" => "mu"])->build(), "metric" => "mu"],
        ];
    }
}