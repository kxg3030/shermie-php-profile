<?php

namespace app\controller;

use app\BaseController;
use app\service\PerformanceService;
use MongoDB\Driver\Exception\Exception;
use think\facade\View;

class Index extends BaseController {

    /**
     * @return string
     * 首页
     */
    public function index(): string {
        return View::fetch("index/index", [
            "sort"  => $this->request->get("sort", ""),
            "query" => $this->request->get(),
        ]);
    }

    /**
     * @throws Exception
     * 火焰图
     */
    public function graph(): string {
        $params  = $this->request->get();
        $service = new PerformanceService($params);
        $data    = $service->profileListOne($params["id"]);
        $data    = $service->graphDataFormat($data);
        return View::fetch("index/graph", [
            "data" => $data
        ]);
    }

    /**
     * @throws Exception
     * 请求详情
     */
    public function view(): string {
        $params  = $this->request->get();
        $service = new PerformanceService($params);

        $data = $service->profileListOne($params["id"]);

        // 执行耗时图表数据
        $wtData = $service->profileSort($data, PerformanceService::SORT_BY_WT);
        // 内存耗时图表数据
        $muData      = $service->profileSort($data, PerformanceService::SORT_BY_MEM);
        $wtDataSlice = array_slice($wtData, 0, 10);
        $muDataSlice = array_slice($muData, 0, 10);
        // 调用函数
        $dataList = [];
        foreach ($data as $funcName => $profile) {
            unset($profile["parents"]);
            $dataList[] = ["func" => urlencode($funcName)] + $profile;
        }
        // 数据排序
        $dimension = PerformanceService::SORT_BY_WT;
        uasort($dataList, function ($first, $second) use ($dimension) {
            if ($first[$dimension] == $second[$dimension]) {
                return 0;
            }
            return $first[$dimension] > $second[$dimension] ? -1 : 1;
        });

        $dataList = array_values($dataList);
        return View::fetch("index/view", [
            "wtData"   => $wtDataSlice,
            "muData"   => $muDataSlice,
            "dataList" => $dataList,
            "meta"     => $service->meta,
            "params"   => $params
        ]);
    }


    /**
     * @throws Exception
     */
    public function stack(): string {
        $params  = $this->request->get();
        $service = new PerformanceService($params);
        $data    = $service->profileListOne($params["id"]);
        $profile = $service->stackDataFormat($data, $params["metric"] ?? "wt");
        return View::fetch("index/stack", [
            "data" => $profile,
            "meta" => $service->meta
        ]);
    }

    public function address(): string {
        $params  = $this->request->get();
        $service = new PerformanceService($params);
        $data    = $service->profileListAll();
        [$ctData, $ctTime, $muData, $tableData] = $data;
        return View::fetch("index/address", [
            "params"    => $params,
            "ctData"    => $ctData,
            "ctTime"    => $ctTime,
            "muData"    => $muData,
            "tableData" => $tableData,
        ]);
    }
}
