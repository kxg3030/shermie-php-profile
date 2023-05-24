<?php

use MongoDB\BSON\UTCDateTime;
use think\Config;
use think\Env;

define("ROOT_DIR", dirname(dirname(__FILE__)));
require ROOT_DIR . '/vendor/autoload.php';
// 加载配置
$config = new Config();
$config = $config->load(ROOT_DIR . "/config/profile.php");

try {
    // 检测扩展
    if (!extension_loaded('xhprof') && !extension_loaded('uprofiler') && !extension_loaded('tideways') && !extension_loaded('tideways_xhprof')) {
        error_log('xhprof扩展未加载');
        return;
    }

    if (!extension_loaded('mongodb')) {
        error_log('mongodb扩展未加载');
        return;
    }
    $collectionName = $config["project"][$_SERVER["DOCUMENT_ROOT"]]["id"] ?? "";
    if (!$collectionName) {
        error_log('mongodb未配置项目对应的集合名');
        return;
    }

// 获取过滤项目
    $filter = $config["filter"] ?? [];
    if (is_array($filter) && in_array($_SERVER['DOCUMENT_ROOT'], $filter)) {
        return;
    }
    // 采样率
    $rate      = $config["project"][$_SERVER["DOCUMENT_ROOT"]]["rate"] ?? 0.1;
    $shouldRun = $config["enable"]($rate);
    if (!$shouldRun) {
        return;
    }

    if (!isset($_SERVER['REQUEST_TIME_FLOAT'])) {
        $_SERVER['REQUEST_TIME_FLOAT'] = microtime(true);
    }

    $extension = $config["extension"] ?? null;
    switch ($extension) {
        case "uprofiler":
            uprofiler_enable(UPROFILER_FLAGS_CPU | UPROFILER_FLAGS_MEMORY);
            break;
        case "tideways_xhprof":
            tideways_xhprof_enable(TIDEWAYS_XHPROF_FLAGS_MEMORY | TIDEWAYS_XHPROF_FLAGS_MEMORY_MU | TIDEWAYS_XHPROF_FLAGS_MEMORY_PMU | TIDEWAYS_XHPROF_FLAGS_CPU | TIDEWAYS_XHPROF_FLAGS_NO_BUILTINS);
            break;
        case "tideways":
            tideways_enable(TIDEWAYS_FLAGS_CPU | TIDEWAYS_FLAGS_MEMORY);
            tideways_span_create('sql');
            break;
        case "xhprof":
            if (PHP_MAJOR_VERSION == 5 && PHP_MINOR_VERSION > 4) {
                xhprof_enable(XHPROF_FLAGS_CPU | XHPROF_FLAGS_MEMORY | XHPROF_FLAGS_NO_BUILTINS);
            } else {
                xhprof_enable(XHPROF_FLAGS_CPU | XHPROF_FLAGS_MEMORY);
            }
            break;
        default:
            error_log('加载性能捕获扩展错误');
            return;
    }
} catch (Throwable $throwable) {
    error_log('未知错误：' . $throwable->getMessage());
    return;
}

register_shutdown_function(function () use ($config) {
    // 加载环境变量
    $env = new Env();
    $env->load(ROOT_DIR . "/.env");
    try {
        $extension = $config["extension"] ?: "";
        if (!$extension) {
            error_log('Profile扩展加载错误');
            return;
        }
        $collectionName = $config["project"][$_SERVER["DOCUMENT_ROOT"]]["id"] ?? "";
        if (!$collectionName) {
            error_log('Profile项目对应集合不存在');
            return;
        }
        if ($extension == 'uprofiler' && extension_loaded('uprofiler')) {
            $data['profile'] = uprofiler_disable();
        } else if ($extension == 'tideways_xhprof' && extension_loaded('tideways_xhprof')) {
            $data['profile'] = tideways_xhprof_disable();
        } else {
            $data['profile'] = xhprof_disable();
        }
        ignore_user_abort(true);
        flush();
        $uri = array_key_exists('REQUEST_URI', $_SERVER) ? $_SERVER['REQUEST_URI'] : null;
        if (empty($uri) && isset($_SERVER['argv'])) {
            $cmd = basename($_SERVER['argv'][0]);
            $uri = $cmd . ' ' . implode(' ', array_slice($_SERVER['argv'], 1));
        }
        $time             = array_key_exists('REQUEST_TIME', $_SERVER) ? $_SERVER['REQUEST_TIME'] : time();
        $requestTimeFloat = explode('.', $_SERVER['REQUEST_TIME_FLOAT'] * 1000)[0];
        $requestTs        = new UTCDateTime($time * 1000);
        $requestTsMicro   = new UTCDateTime($requestTimeFloat);
        $data['meta']     = [
            'url'              => $uri,
            'SERVER'           => $_SERVER,
            'get'              => $_GET,
            'env'              => $_ENV,
            'simple_url'       => $config["handle"]($uri),
            'request_ts'       => $requestTs,
            'request_ts_micro' => $requestTsMicro,
            'request_date'     => date('Y-m-d', $time),
        ];
        \app\common\Mongo::instance([
            "username" => $env->get("MONGO_USERNAME"),
            "password" => $env->get("MONGO_PASSWORD"),
            "hostname" => $env->get("MONGO_HOSTNAME"),
            "hostport" => $env->get("MONGO_HOSTPORT"),
            "database" => $env->get("MONGO_DATABASE")
        ])->insert($data, $collectionName);
        error_log('性能数据捕获成功');
    } catch (Exception $e) {
        error_log('性能数据捕获异常：\n' . $e->getTraceAsString());
    }
});
