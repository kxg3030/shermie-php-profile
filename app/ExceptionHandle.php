<?php

namespace app;

use app\exception\BusinessException;
use Exception;
use think\exception\Handle;
use think\exception\HttpException;
use think\exception\ValidateException;
use think\Request;
use think\Response;
use Throwable;

/**
 * 应用异常处理类
 */
class ExceptionHandle extends Handle {
    /**
     * 不需要记录信息（日志）的异常类列表
     * @var array
     */
    protected $ignoreReport = [
//        HttpException::class,
//        HttpResponseException::class,
//        ModelNotFoundException::class,
//        DataNotFoundException::class,
        ValidateException::class,
    ];

    /**
     * Render an exception into an HTTP response.
     *
     * @access public
     * @param Request $request
     * @param Throwable $e
     * @return Response
     */
    public function render($request, Throwable $e): Response {
        // 验证异常
        if ($e instanceof ValidateException) {
            return Response::create([
                "data" => [],
                "code" => 1000,
                "msg"  => $e->getMessage()
            ], "json", 401);
        }
        // 业务异常
        if ($e instanceof BusinessException) {
            return Response::create([
                "msg"  => $e->getMessage(),
                "code" => $e->getCode(),
                "data" => []
            ], 'json');
        }
        // 请求异常
        if (($e instanceof HttpException)) {
            parent::report($e);
            return Response::create([
                "data" => [],
                "code" => 10001,
                "msg"  => "服务不可用：" . $e->getStatusCode()
            ], "json", $e->getStatusCode());
        }
        if ($request->isJson() || $request->isAjax()) {
            parent::report($e);
            return Response::create([
                "data" => [],
                "code" => 10002,
                "msg"  => "服务不可用"
            ], "json");
        }

        // 其他异常
        return parent::render($request, $e);
    }

    /**
     * Report or log an exception.
     *
     * @access public
     * @param Throwable $exception
     * @return void
     */
    public function report(Throwable $exception): void {
        if (!$this->isIgnoreReport($exception)) {
            $data = [
                'file'    => $exception->getFile(),
                'line'    => $exception->getLine(),
                'message' => $this->getMessage($exception),
                'code'    => $this->getCode($exception),
            ];
            $log  = "[{$data['code']}]{$data['message']}[{$data['file']}:{$data['line']}]";

            if ($this->app->config->get('log.record_trace')) {
                $log .= PHP_EOL . $exception->getTraceAsString();
            }

            try {
                $this->app->log->record($log, 'error');
            } catch (Exception $e) {
            }
        }
    }
}
