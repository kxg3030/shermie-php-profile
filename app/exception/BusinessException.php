<?php

namespace app\exception;

use app\common\ErrorCode;
use Throwable;

class BusinessException extends \Exception {

    public function __construct($code = ErrorCode::BUSINESS_HANDLE_ERROR, $message = "", Throwable $previous = null) {
        if(!$message){
            $message = ErrorCode::error($code);
        }
        parent::__construct($message, $code, $previous);
    }
}