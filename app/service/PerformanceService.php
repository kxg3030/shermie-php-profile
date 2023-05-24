<?php

namespace app\service;


use app\common\ErrorCode;
use app\common\Mongo;
use app\exception\BusinessException;
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Driver\Exception\Exception;
use MongoDB\Model\BSONDocument;

class PerformanceService {
    private $params = [];
    private $index  = [];
    private $data   = [];
    public  $meta   = [];

    const SORT_BY_CPU = "ecpu";
    const SORT_BY_MEM = "emu";
    const SORT_BY_WT  = "ewt";
    const SORT_BY_CT  = "ect";
    const SORT_BY_PM  = "epmu";
    const PARENT_NO   = "parent-no";
    /**
     * @var array
     */
    private $visited = [];
    /**
     * @var array
     */
    private $nodes = [];
    /**
     * @var array
     */
    private $links = [];

    public function __construct(array $params = []) {
        $this->params = $params;
    }

    /**
     * @throws Exception
     * @throws BusinessException
     */
    public function profileList(): array {
        $page = (int)($this->params["page"] ?? 1);
        $size = (int)($this->params["limit"] ?? 18);
        if (!($this->params["platform"] ?? "")) {
            throw new BusinessException(ErrorCode::BUSINESS_HANDLE_ERROR, "请选择应用");
        }
        $platform = ($this->params["platform"] ?? "") ?: "";
        $filter   = [];
        if ($range = ($this->params["time"] ?? "")) {
            [$start, $end] = explode(" - ", $range);
            $filter["request_ts"] = [
                '$gte' => new UTCDateTime($start),
                '$lte' => new UTCDateTime($end)
            ];
        }
        if ($url = ($this->params["url"] ?? "")) {
            $filter["meta.url"] = $url;
        }
        if ($method = ($this->params["method"] ?? "")) {
            $filter["meta.SERVER.REQUEST_METHOD"] = $method;
        }
        $options = [
            'limit'      => $size,
            "skip"       => ($page - 1) * $size,
            'sort'       => ['meta.SERVER.REQUEST_TIME' => -1],
            'projection' => ['profile.main()' => 1, 'meta' => 1, '_id' => 1]
        ];
        if ($this->params["sort"] ?? null) {
            $options["sort"] = ["profile.main()." . $this->params["sort"] => -1];
        }
        $list  = Mongo::instance()->query($filter, $options, $platform);
        $count = Mongo::instance()->count($filter, [], $platform);
        $data  = [];

        foreach ($list as $span) {
            /**@var $time UTCDateTime */
            $time   = $span->meta->request_ts ?? null;
            $record = [
                "method"  => $span->meta->SERVER->REQUEST_METHOD ?? "",
                "id"      => ((array)$span->_id)["oid"],
                "address" => $span->meta->SERVER->REQUEST_URI ?? "",
                "ip"      => $span->meta->SERVER->REMOTE_ADDR ?? "",
                "port"    => $span->meta->SERVER->REMOTE_PORT ?? "",
                "time"    => $time->toDateTime()->add((new \DateInterval("PT8H")))->format("Y-m-d H:i:s"),
            ];
            $data[] = $record + $this->profileWrap($span, true);
        }
        return ["count" => $count, "data" => $data, "code" => 200];
    }

    /**
     * @throws Exception
     */
    public function profileListOne(string $id, array $option = []): array {
        $data       = Mongo::instance()->queryOne(["_id" => new ObjectId($id)], $option, $this->params["platform"] ?? "");
        $this->meta = $data->meta->getArrayCopy();
        $data       = $this->profileWrap($data);
        foreach ($data as &$item) {
            $item[self::SORT_BY_WT]  = $item['wt'];
            $item[self::SORT_BY_MEM] = $item['mu'];
            $item[self::SORT_BY_CPU] = $item['cpu'];
            $item[self::SORT_BY_CT]  = $item['ct'];
            $item[self::SORT_BY_PM]  = $item['pmu'];
        }
        unset($item);
        $this->data = $data;
        foreach ($this->data as $name => $list) {
            $children = $this->children($name);
            foreach ($children as $child) {
                $this->data [$name][self::SORT_BY_WT]  -= $child['wt'];
                $this->data [$name][self::SORT_BY_MEM] -= $child['mu'];
                $this->data [$name][self::SORT_BY_CPU] -= $child['cpu'];
                $this->data [$name][self::SORT_BY_CT]  -= $child['ct'];
                $this->data [$name][self::SORT_BY_PM]  -= $child['pmu'];
            }
        }
        return $this->data;
    }

    public function profileSort(array $data, $dimension): array {
        $result = [];
        uasort($data, function ($first, $second) use ($dimension) {
            if ($first[$dimension] == $second[$dimension]) {
                return 0;
            }
            return $first[$dimension] > $second[$dimension] ? -1 : 1;
        });
        $index = 0;
        foreach ($data as $funcName => $dataList) {
            $index++;
            $result[] = [urlencode($funcName), (static function () use ($dataList, $dimension) {
                switch ($dimension) {
                    case self::SORT_BY_MEM:
                        return sprintf("%.2f", $dataList[$dimension] / 1024);
                    case self::SORT_BY_WT:
                        return $dataList[$dimension] / 1000;
                }
                return "";
            })(), $index];
        }
        return $result;
    }

    public function profileWrap($span, bool $removeFunc = false): array {
        $profileFields = ["wt", "ct", "cpu", "mu", "pmu"];
        $record        = [];
        foreach ($span->profile ?? [] as $key => $item) {
            /**@var $item BSONDocument */
            $splitMap = explode("==>", $key);
            $parent   = $function = null;
            if (count($splitMap) >= 2) {
                [$parent, $function] = $splitMap;
            } else {
                $function = $splitMap[0];
            }
            if (!($record[$function] ?? null)) {
                $record[$function]            = $item->getArrayCopy();
                $record[$function]["parents"] = [$parent];
            } else {
                $record[$function]["parents"][] = $parent;
                foreach ($profileFields as $profileField) {
                    if (array_key_exists($profileField, $record[$function])) {
                        $record[$function][$profileField] += $item->{$profileField} ?? 0;
                    } else {
                        $record[$function][$profileField] = 0;
                    }
                }
            }
            if ($parent === null) {
                $parent = self::PARENT_NO;
            }
            if (!isset($this->index[$parent])) {
                $this->index[$parent] = [];
            }
            $this->index[$parent][$function] = $item->getArrayCopy();
            if ($removeFunc) {
                foreach ($profileFields as $profileField) {
                    $record[$profileField] = $record[$function][$profileField] ?? 0;
                }
                unset($record[$function]);
            }
        }
        return $record;
    }

    /**
     * @param $symbol
     * @param null $metric
     * @param int $threshold
     * @return array
     */
    private function children($symbol, $metric = null, $threshold = 0): array {
        $children = [];
        if (!isset($this->index[$symbol])) {
            return $children;
        }
        $total = 0;
        if (isset($metric)) {
            $top      = $this->index["parent-no"];
            $mainFunc = current($top);
            $total    = $mainFunc[$metric];
        }
        foreach ($this->index[$symbol] as $name => $data) {
            if ($metric && $total > 0 && $threshold > 0 && ($this->data[$name][$metric] / $total) < $threshold) {
                continue;
            }
            $children[] = $data + ['function' => $name];
        }
        return $children;
    }

    public function graphDataFormat(array $data, $metric = 'wt', $threshold = 0.01): array {
        $exclusiveKeys = ['ewt', 'ecpu', 'emu', 'epmu'];
        if (in_array($metric, $exclusiveKeys)) {
            $main = array_reduce($data, function ($result, $item) use ($metric) {
                if ($item[$metric] > $result) {
                    return $item[$metric];
                }
                return $result;
            }, 0);
        } else {
            $main = $data['main()'][$metric];
        }
        $this->visited = $this->nodes = $this->links = [];
        $flamegraph    = $this->flameGraphData(self::PARENT_NO, $main, $metric, $threshold);
        return ['data' => array_shift($flamegraph), 'sort' => $this->visited];
    }

    public function stackDataFormat(array $data, $metric = 'wt', $threshold = 0.01): array {
        $exclusiveKeys = ['ewt', 'ecpu', 'emu', 'epmu'];
        if (in_array($metric, $exclusiveKeys)) {
            $main = array_reduce($data, function ($result, $item) use ($metric) {
                if ($item[$metric] > $result) {
                    return $item[$metric];
                }
                return $result;
            }, 0);
        } else {
            $main = $data['main()'][$metric];
        }
        $this->visited = $this->nodes = $this->links = [];
        $this->stackGraphData(self::PARENT_NO, $main, $metric, $threshold);
        $profile = [
            'metric' => $metric,
            'total'  => $main,
            'nodes'  => $this->nodes,
            'links'  => $this->links
        ];
        unset($this->visited, $this->nodes, $this->links);
        return $profile;
    }

    /**
     * @param $parentName
     * @param $main
     * @param $metric
     * @param $threshold
     * @param null $parentIndex
     * @return array
     */
    private function flameGraphData($parentName, $main, $metric, $threshold, $parentIndex = null): array {
        $result = [];
        if (!isset($this->index[$parentName])) {
            return $result;
        }
        $children = $this->index[$parentName];
        foreach ($children as $childName => $metrics) {
            $encodeName = str_replace("\\", "\\\\", $childName);
            $metrics    = $this->data[$childName];
            if ($metrics[$metric] / $main <= $threshold) {
                continue;
            }
            $current = [
                'name'  => $encodeName,
                'value' => $metrics[$metric]
            ];
            $revisit = false;
            if (!isset($this->visited[$encodeName])) {
                $index                      = count($this->nodes);
                $this->visited[$encodeName] = $index;
                $this->nodes[]              = $current;
            } else {
                $revisit = true;
                $index   = $this->visited[$encodeName];
            }
            if (isset($this->index[$childName]) && !$revisit) {
                $grandChildren = $this->flameGraphData($childName, $main, $metric, $threshold, $index);
                if (!empty($grandChildren)) {
                    $current['children'] = $grandChildren;
                }
            }
            $result[] = $current;
        }
        return $result;
    }

    private function stackGraphData($parentName, $main, $metric, $threshold, $parentIndex = null) {
        if (!isset($this->index[$parentName])) {
            return;
        }
        $children = $this->index[$parentName];
        foreach ($children as $childName => $metrics) {
            $metrics    = $this->data[$childName];
            $encodeName = str_replace("\\", "\\\\", $childName);
            if ($metrics[$metric] / $main <= $threshold) {
                continue;
            }
            $revisit = false;
            if (!isset($this->visited[$encodeName])) {
                $index                      = count($this->nodes);
                $this->visited[$encodeName] = $index;

                $this->nodes[] = [
                    'name'      => $encodeName,
                    'callCount' => $metrics['ct'],
                    'value'     => $metrics[$metric],
                ];
            } else {
                $revisit = true;
                $index   = $this->visited[$encodeName];
            }
            if ($parentIndex !== null) {
                $encodeParentName = str_replace("\\", "\\\\", $parentName);
                $this->links[]    = [
                    'source'    => $encodeParentName,
                    'target'    => $encodeName,
                    'callCount' => $metrics['ct'],
                ];
            }
            if (isset($this->index[$childName]) && !$revisit) {
                $this->stackGraphData($childName, $main, $metric, $threshold, $index);
            }
        }
    }

    public function profileListAll(): array {

        $option   = [
            'limit'      => 200,
            'sort'       => ['meta.SERVER.REQUEST_TIME' => -1],
            'projection' => [
                'profile.main()'             => 1,
                'id'                         => 1,
                'meta.url'                   => 1,
                "meta.request_ts"            => 1,
                "meta.SERVER.REQUEST_METHOD" => 1,
            ]
        ];
        $dataList = Mongo::instance()->query([
            "meta.url"                   => $this->params["url"],
            "meta.SERVER.REQUEST_METHOD" => [
                '$ne' => "OPTIONS"
            ]
        ], $option, $this->params["platform"] ?? "");

        $dealTime  = [];
        $memoryUse = [];
        $datetime  = [];
        $tableData = [];
        foreach ($dataList as $span) {
            $url         = $span->meta->url ?? "";
            $time        = $span->meta->request_ts ?? null;
            $wrapSpan    = $this->profileWrap($span);
            $dealTime[]  = sprintf("%.2f", ($wrapSpan["main()"]["wt"] ?? 0) / 1e3);
            $memoryUse[] = sprintf("%.2f", ($wrapSpan["main()"]["mu"] ?? 0) / 1024);
            $datetime[]  = $time->toDateTime()->format("Y-m-d H:i:s");
            $tableData[] = array_merge($wrapSpan["main()"],
                [
                    "url"    => $url,
                    "time"   => $time->toDateTime()->format("Y-m-d H:i:s"),
                    "method" => $span->meta->SERVER->REQUEST_METHOD ?? ""
                ]
            );
        }
        return [$dealTime, $datetime, $memoryUse, $tableData];
    }
}