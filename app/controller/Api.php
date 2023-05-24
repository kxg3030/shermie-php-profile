<?php

namespace app\controller;

use app\BaseController;
use app\exception\BusinessException;
use app\service\PerformanceService;
use MongoDB\Driver\Exception\Exception;
use think\Response;

class Api extends BaseController {

    /**
     * @throws Exception
     * @throws BusinessException
     */
    public function list(): Response {
        $params = $this->request->get();
        $data   = (new PerformanceService($params))->profileList();
        return $this->success($data);
    }
}